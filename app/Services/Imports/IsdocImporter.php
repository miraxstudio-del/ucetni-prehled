<?php

namespace App\Services\Imports;

use App\Enums\InvoiceStatus;
use App\Models\Client;
use App\Models\Invoice;
use App\Services\Audit\AuditLogger;
use App\Support\OrganizationContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use SimpleXMLElement;

/**
 * Import přijaté faktury (nákladu) ze souboru ISDOC.
 * Dodavatel z dokumentu se založí/napáruje jako klient.
 */
class IsdocImporter
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly AuditLogger $audit,
    ) {}

    public function import(string $content): Invoice
    {
        $previous = libxml_use_internal_errors(true);

        try {
            $xml = simplexml_load_string($content);
        } finally {
            libxml_use_internal_errors($previous);
        }

        if ($xml === false || $xml->getName() !== 'Invoice') {
            throw ValidationException::withMessages([
                'file' => 'Soubor není platný ISDOC dokument.',
            ]);
        }

        $number = trim((string) $xml->ID);

        if ($number === '') {
            throw ValidationException::withMessages(['file' => 'ISDOC neobsahuje číslo dokladu.']);
        }

        if (Invoice::query()->where('number', $number)->exists()) {
            throw ValidationException::withMessages([
                'file' => "Doklad {$number} už je v evidenci.",
            ]);
        }

        $supplier = $xml->AccountingSupplierParty->Party ?? null;

        $client = $this->resolveSupplier($supplier);

        $issueDate = (string) $xml->IssueDate ?: now()->toDateString();
        $dueDate = (string) ($xml->PaymentMeans->Payment->Details->PaymentDueDate ?? '') ?: $issueDate;

        return DB::transaction(function () use ($xml, $number, $client, $issueDate, $dueDate) {
            $invoice = Invoice::create([
                'direction' => 'received',
                'type' => 'invoice',
                'status' => InvoiceStatus::Issued,
                'client_id' => $client->id,
                'number' => $number,
                'variable_symbol' => (string) ($xml->PaymentMeans->Payment->Details->VariableSymbol ?? '') ?: null,
                'issue_date' => $issueDate,
                'duzp' => (string) $xml->TaxPointDate ?: $issueDate,
                'due_date' => $dueDate,
                'payment_method' => 'bank_transfer',
                'currency' => (string) $xml->LocalCurrencyCode ?: 'CZK',
                'subtotal' => $this->decimal((string) ($xml->LegalMonetaryTotal->TaxExclusiveAmount ?? '0')),
                'vat_total' => $this->decimal((string) ($xml->TaxTotal->TaxAmount ?? '0')),
                'total' => $this->decimal((string) ($xml->LegalMonetaryTotal->TaxInclusiveAmount ?? '0')),
            ]);

            $position = 0;

            foreach ($xml->InvoiceLines->InvoiceLine ?? [] as $line) {
                $invoice->items()->create([
                    'position' => $position++,
                    'description' => (string) ($line->Item->Description ?? 'Položka') ?: 'Položka',
                    'quantity' => $this->decimal((string) ($line->InvoicedQuantity ?? '1'), 3),
                    'unit' => (string) ($line->InvoicedQuantity['unitCode'] ?? '') ?: null,
                    'unit_price' => $this->decimal((string) ($line->UnitPrice ?? '0')),
                    'vat_rate' => $this->decimal((string) ($line->ClassifiedTaxCategory->Percent ?? '0')),
                    'line_subtotal' => $this->decimal((string) ($line->LineExtensionAmount ?? '0')),
                    'line_vat' => $this->decimal((string) ($line->LineExtensionTaxAmount ?? '0')),
                    'line_total' => $this->decimal((string) ($line->LineExtensionAmountTaxInclusive ?? '0')),
                ]);
            }

            $this->audit->log('import.isdoc', entity: $invoice, meta: ['number' => $invoice->number]);

            return $invoice;
        });
    }

    private function resolveSupplier(?SimpleXMLElement $party): Client
    {
        $ico = $party !== null ? preg_replace('/\D/', '', (string) ($party->PartyIdentification->ID ?? '')) : '';
        $name = $party !== null ? trim((string) ($party->PartyName->Name ?? '')) : '';

        if ($ico !== '') {
            // IČO je zašifrované → přes slepý index
            $client = Client::whereIco($ico)->first();

            if ($client) {
                return $client;
            }
        }

        return Client::create([
            'name' => $name !== '' ? $name : 'Dodavatel z ISDOC',
            'ico' => $ico ?: null,
            'dic' => $party !== null ? ((string) ($party->PartyTaxScheme->CompanyID ?? '') ?: null) : null,
            'street' => $party !== null ? ((string) ($party->PostalAddress->StreetName ?? '') ?: null) : null,
            'city' => $party !== null ? ((string) ($party->PostalAddress->CityName ?? '') ?: null) : null,
            'zip' => $party !== null ? ((string) ($party->PostalAddress->PostalZone ?? '') ?: null) : null,
        ]);
    }

    private function decimal(string $value, int $scale = 2): string
    {
        return bcadd(is_numeric($value) ? $value : '0', '0', $scale);
    }
}
