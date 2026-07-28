<?php

namespace App\Services\Bank;

use App\Enums\InvoiceDirection;
use App\Enums\InvoiceStatus;
use App\Models\BankTransaction;
use App\Models\Invoice;
use App\Support\Text;
use Illuminate\Database\Eloquent\Collection;

/**
 * Ověření stavů „zaplaceno“ proti bance pro jednu organizaci a rok —
 * jádro sdílené příkazem `ucetni-prehled:verify-payments` i ručním spuštěním z UI
 * (viz BankController::verifyPayments). Pracuje vždy nad organizací
 * nastavenou v aktuálním OrganizationContext.
 */
class PaymentVerifier
{
    public function __construct(private readonly PaymentMatcher $matcher) {}

    /**
     * @return array{paired: list<string>, downgraded: list<string>, unpaid: list<string>, closed_year: bool}
     */
    public function verify(int $year, bool $dryRun = false): array
    {
        $closedYear = $year < now()->year;

        $invoices = Invoice::query()
            ->with(['paymentMatches'])
            ->where('direction', InvoiceDirection::Issued)
            ->whereYear('issue_date', $year)
            ->whereNotIn('status', [InvoiceStatus::Draft, InvoiceStatus::Cancelled])
            ->orderBy('number')
            ->get();

        // Platby od 1. 1. daného roku (bez horní hranice — prosincová
        // faktura bývá zaplacená v lednu dalšího roku).
        $transactions = BankTransaction::query()
            ->with('matches')
            ->whereDate('booked_on', '>=', sprintf('%04d-01-01', $year))
            ->where('amount', '>', 0)
            ->orderBy('booked_on')
            ->get();

        $paired = [];
        $downgraded = [];
        $unpaid = [];
        // v dry-run se zápisy nedějí — stejná platba se nesmí "použít" dvakrát
        $usedTransactionIds = [];

        foreach ($invoices as $invoice) {
            if ($invoice->paymentMatches->isNotEmpty()) {
                continue; // platba už doložená
            }

            $importMarked = $invoice->status === InvoiceStatus::Paid
                && $invoice->paid_at === null
                && str_starts_with((string) $invoice->note, 'Importováno z');

            // Ručně označené jako zaplacené (má datum úhrady) nechat být —
            // to je vědomé rozhodnutí uživatele, ne domněnka importu.
            if ($invoice->status === InvoiceStatus::Paid && ! $importMarked) {
                continue;
            }

            $transaction = $this->findPayment($invoice, $transactions, $usedTransactionIds);

            if ($transaction !== null) {
                $usedTransactionIds[] = $transaction->id;

                if (! $dryRun) {
                    if ($importMarked) {
                        // nejdřív zpět na vystaveno, ať markPaid doplní datum úhrady
                        $invoice->status = InvoiceStatus::Issued;
                        $invoice->save();
                    }

                    $this->matcher->verifiedMatch($invoice, $transaction);
                    $transaction->load('matches');
                }

                $paired[] = $invoice->number;

                continue;
            }

            if ($importMarked && ! $closedYear) {
                if (! $dryRun) {
                    $invoice->status = InvoiceStatus::Issued;
                    $invoice->save();
                }

                $downgraded[] = $invoice->number;
                $unpaid[] = $invoice->number;
            } elseif (! $importMarked) {
                $unpaid[] = $invoice->number;
            }
        }

        return [
            'paired' => $paired,
            'downgraded' => $downgraded,
            'unpaid' => $unpaid,
            'closed_year' => $closedYear,
        ];
    }

    /**
     * Najde příchozí platbu k faktuře:
     *  1. shoda VS (číslo faktury nebo VS, i bez levostranných nul) + přesná
     *     částka + měna;
     *  2. Aukro: jméno kupujícího z názvu původního souboru („AUKRO - user“)
     *     se hledá ve zprávě platby „…od kupujícího user“ — částka je netto;
     *  3. platba BEZ VS: přesná částka + stejné jméno protistrany jako klient
     *     na faktuře (porovnání množiny slov — „GOLÍKOVÁ DENISA“ ==
     *     „Denisa Golíková“). Jen bez VS, aby se neukradla platba označená
     *     symbolem jiné faktury.
     *
     * @param  Collection<int, BankTransaction>  $transactions
     * @param  list<int>  $usedTransactionIds
     */
    private function findPayment(Invoice $invoice, Collection $transactions, array $usedTransactionIds): ?BankTransaction
    {
        $symbols = array_filter(array_unique([
            $invoice->variable_symbol,
            $invoice->number,
            ltrim((string) $invoice->variable_symbol, '0'),
        ]));

        $available = $transactions->filter(
            fn (BankTransaction $t) => $t->matches->isEmpty()
                && ! in_array($t->id, $usedTransactionIds, true)
                && $t->currency === $invoice->currency
        );

        foreach ($available as $transaction) {
            if (in_array($transaction->variable_symbol, $symbols, true)
                && (float) $transaction->amount === (float) $invoice->total) {
                return $transaction;
            }
        }

        // Aukro netto — jen když známe jméno kupujícího z názvu souboru
        if (preg_match('/AUKRO - (.+?)\.pdf/iu', (string) $invoice->note, $m)) {
            $buyer = mb_strtolower(trim($m[1]));

            if ($buyer !== '') {
                foreach ($available as $transaction) {
                    if (str_contains(mb_strtolower((string) $transaction->message), 'od kupujícího '.$buyer)) {
                        return $transaction;
                    }
                }
            }
        }

        // Bez VS: jméno protistrany + přesná částka
        $clientWords = $this->nameWords((string) $invoice->client?->name);

        if ($clientWords !== []) {
            foreach ($available as $transaction) {
                if (blank($transaction->variable_symbol)
                    && (float) $transaction->amount === (float) $invoice->total
                    && $this->nameWords((string) $transaction->counterparty_name) === $clientWords) {
                    return $transaction;
                }
            }
        }

        return null;
    }

    /**
     * Jméno jako seřazená množina slov bez diakritiky — pořadí ani velikost
     * písmen nerozhodují („GOLÍKOVÁ DENISA“ == „Denisa Golíková“).
     *
     * @return list<string>
     */
    private function nameWords(string $name): array
    {
        $words = preg_split('/[\s,.]+/u', mb_strtolower(Text::ascii($name))) ?: [];
        $words = array_values(array_filter($words));
        sort($words);

        return $words;
    }
}
