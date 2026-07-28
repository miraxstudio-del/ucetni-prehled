<?php

namespace App\Http\Controllers;

use App\Enums\DocumentType;
use App\Enums\InvoiceDirection;
use App\Enums\PaymentMethod;
use App\Models\BankAccount;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Organization;
use App\Services\Invoicing\InvoiceCalculator;
use App\Services\Pdf\InvoicePdf;
use App\Support\OrganizationContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Živý webový náhled faktury — v obou případech se skládá jen v paměti,
 * nic se neukládá. Vykresluje se úplně stejnou Blade šablonou jako reálné
 * PDF (viz InvoicePdf), takže náhled nemůže vypadat jinak než výsledek.
 */
class InvoicePreviewController extends Controller
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly InvoiceCalculator $calculator,
        private readonly InvoicePdf $pdf,
    ) {}

    /** Náhled pro nastavení fakturace — organizace ještě není uložená. */
    public function organization(Request $request): Response
    {
        $this->authorize('create', Client::class);

        $current = $this->context->current();

        $organization = new Organization([
            'name' => $request->input('name') ?: $current->name,
            'ico' => $request->input('ico'),
            'dic' => $request->input('dic'),
            'vat_payer' => $request->boolean('vat_payer'),
            'street' => $request->input('street'),
            'city' => $request->input('city'),
            'zip' => $request->input('zip'),
            'email' => $request->input('email'),
            'registration_note' => $request->input('registration_note'),
            'invoice_footer' => $request->input('invoice_footer'),
            'invoice_template' => $request->input('invoice_template', $current->invoice_template?->value ?? 'klasik'),
            'default_due_days' => $current->default_due_days,
        ]);
        $organization->logo_path = $current->logo_path;
        $organization->stamp_path = $current->stamp_path;

        $bankAccount = BankAccount::orderByDesc('is_default')->first();
        $invoice = $this->sampleInvoice($organization, $bankAccount);

        return $this->htmlResponse($invoice);
    }

    /** Náhled rozpracované faktury (formulář Nová/Upravit faktura). */
    public function invoice(Request $request): Response
    {
        $this->authorize('create', Invoice::class);

        $organization = $this->context->current();

        $calculated = $this->calculator->calculate(
            $request->input('items', []),
            $organization->vat_payer,
        );

        $client = $request->filled('client_id')
            ? Client::find($request->integer('client_id'))
            : null;

        $bankAccount = $request->filled('bank_account_id')
            ? BankAccount::find($request->integer('bank_account_id'))
            : BankAccount::orderByDesc('is_default')->first();

        $issueDate = $request->input('issue_date') ?: now()->toDateString();

        $invoice = new Invoice([
            'type' => $request->input('type', DocumentType::Invoice->value),
            'direction' => $request->input('direction', InvoiceDirection::Issued->value),
            'currency' => 'CZK',
            'issue_date' => $issueDate,
            'due_date' => $request->input('due_date') ?: $issueDate,
            'duzp' => $request->input('duzp') ?: $issueDate,
            'payment_method' => $request->input('payment_method', PaymentMethod::BankTransfer->value),
            'variable_symbol' => $request->input('variable_symbol'),
            'number' => $request->input('number') ?: 'NÁHLED',
            'note' => $request->input('note'),
            'subtotal' => $calculated['subtotal'],
            'vat_total' => $calculated['vat_total'],
            'total' => $calculated['total'],
        ]);
        $invoice->setRelation('organization', $organization);
        $invoice->setRelation('client', $client);
        $invoice->setRelation('bankAccount', $bankAccount);
        $invoice->setRelation('items', collect($calculated['items'])->map(fn ($item) => new InvoiceItem($item)));

        return $this->htmlResponse($invoice);
    }

    private function sampleInvoice(Organization $organization, ?BankAccount $bankAccount): Invoice
    {
        $calculated = $this->calculator->calculate([
            ['description' => 'Konzultace', 'quantity' => '10', 'unit' => 'h', 'unit_price' => '1200', 'vat_rate' => '21'],
            ['description' => 'Vývoj — sprint', 'quantity' => '1', 'unit' => 'ks', 'unit_price' => '25000', 'vat_rate' => '21'],
        ], $organization->vat_payer);

        $invoice = new Invoice([
            'type' => DocumentType::Invoice->value,
            'direction' => InvoiceDirection::Issued->value,
            'currency' => 'CZK',
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays($organization->default_due_days ?: 14)->toDateString(),
            'duzp' => now()->toDateString(),
            'payment_method' => PaymentMethod::BankTransfer->value,
            'variable_symbol' => '2026001',
            'number' => '2026001',
            'subtotal' => $calculated['subtotal'],
            'vat_total' => $calculated['vat_total'],
            'total' => $calculated['total'],
        ]);
        $invoice->setRelation('organization', $organization);
        $invoice->setRelation('bankAccount', $bankAccount);
        $invoice->setRelation('client', new Client([
            'name' => 'Ukázkový klient s.r.o.',
            'street' => 'Vzorová 123',
            'city' => 'Praha',
            'zip' => '110 00',
            'ico' => '12345678',
            'dic' => $organization->vat_payer ? 'CZ12345678' : null,
        ]));
        $invoice->setRelation('items', collect($calculated['items'])->map(fn ($item) => new InvoiceItem($item)));

        return $invoice;
    }

    /**
     * Frontend to vloží přes `iframe.srcdoc`, ne přes URL — takže na to
     * nedosáhne X-Frame-Options a inline <style> v šabloně povoluje už
     * globální CSP (style-src má 'unsafe-inline').
     */
    private function htmlResponse(Invoice $invoice): Response
    {
        return response($this->pdf->renderHtml($invoice))
            ->header('Content-Type', 'text/html; charset=UTF-8');
    }
}
