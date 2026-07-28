<?php

namespace App\Services\Bank;

use App\Enums\InvoiceDirection;
use App\Enums\InvoiceStatus;
use App\Models\BankTransaction;
use App\Models\Invoice;
use App\Models\PaymentMatch;
use App\Services\Audit\AuditLogger;
use App\Services\Invoicing\InvoiceService;
use Illuminate\Support\Facades\DB;

/**
 * Párování plateb s fakturami.
 * Automaticky: příchozí platba se spáruje s vydanou fakturou při shodě
 * variabilního symbolu, částky a měny. Jinak zůstává na ruční spárování.
 */
class PaymentMatcher
{
    public function __construct(
        private readonly InvoiceService $invoices,
        private readonly AuditLogger $audit,
    ) {}

    /** Zkusí automaticky spárovat transakci; vrací true při úspěchu. */
    public function autoMatch(BankTransaction $transaction): bool
    {
        if (! $transaction->isCredit()
            || blank($transaction->variable_symbol)
            || $transaction->matches()->exists()) {
            return false;
        }

        $normalizedVs = ltrim($transaction->variable_symbol, '0');

        $invoice = Invoice::query()
            ->where('direction', InvoiceDirection::Issued)
            ->whereIn('status', [InvoiceStatus::Issued, InvoiceStatus::Sent])
            ->whereIn('variable_symbol', array_unique([$transaction->variable_symbol, $normalizedVs]))
            ->where('currency', $transaction->currency)
            ->where('total', $transaction->amount)
            ->whereDoesntHave('paymentMatches')
            ->first();

        if ($invoice === null) {
            return false;
        }

        $this->match($invoice, $transaction, matchedBy: 'auto');

        return true;
    }

    /** Ruční spárování vybrané faktury s transakcí. */
    public function manualMatch(Invoice $invoice, BankTransaction $transaction, int $userId): void
    {
        $this->match($invoice, $transaction, matchedBy: 'manual', userId: $userId);
    }

    /**
     * Spárování se známou vazbou (zpětné ověření plateb u importu) — na
     * rozdíl od autoMatch smí částka nesedět: Aukro posílá výplaty už po
     * odečtení své provize, takže na účet dorazí méně, než zní faktura.
     */
    public function verifiedMatch(Invoice $invoice, BankTransaction $transaction): void
    {
        $this->match($invoice, $transaction, matchedBy: 'auto');
    }

    public function unmatch(PaymentMatch $match, int $userId): void
    {
        DB::transaction(function () use ($match, $userId) {
            $invoice = $match->invoice;
            $match->delete();

            if ($invoice && $invoice->status === InvoiceStatus::Paid) {
                $invoice->status = InvoiceStatus::Issued;
                $invoice->forceFill(['paid_at' => null])->save();
            }

            $this->audit->log('payment.unmatched', entity: $invoice, meta: ['user_id' => $userId]);
        });
    }

    private function match(Invoice $invoice, BankTransaction $transaction, string $matchedBy, ?int $userId = null): void
    {
        DB::transaction(function () use ($invoice, $transaction, $matchedBy, $userId) {
            PaymentMatch::create([
                'organization_id' => $transaction->organization_id,
                'invoice_id' => $invoice->id,
                'bank_transaction_id' => $transaction->id,
                'amount' => (string) $transaction->amount,
                'matched_by' => $matchedBy,
                'matched_by_user_id' => $userId,
            ]);

            if ($invoice->status !== InvoiceStatus::Paid) {
                $this->invoices->markPaid($invoice, $transaction->booked_on->toDateString());
            }

            $this->audit->log('payment.matched', entity: $invoice, meta: [
                'transaction_id' => $transaction->id,
                'matched_by' => $matchedBy,
            ]);
        });
    }
}
