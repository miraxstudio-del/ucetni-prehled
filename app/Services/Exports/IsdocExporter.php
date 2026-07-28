<?php

namespace App\Services\Exports;

use App\Enums\DocumentType;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use DOMDocument;
use Illuminate\Support\Str;

/**
 * Export faktury do formátu ISDOC 6.0.1 (český standard e-fakturace).
 */
class IsdocExporter
{
    public function export(Invoice $invoice): string
    {
        $invoice->loadMissing(['items', 'client', 'organization', 'bankAccount']);

        // ISDOC je dokument stejně jako PDF — skládá se ze zmrazených údajů
        // z okamžiku vystavení, ne ze živých dat klienta/organizace.
        $invoice->freezeForDocument();

        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;

        $root = $doc->createElementNS('http://isdoc.cz/namespace/2013', 'Invoice');
        $root->setAttribute('version', '6.0.1');
        $doc->appendChild($root);

        $organization = $invoice->organization;
        $vatPayer = $organization->vat_payer;

        $append = function ($parent, string $name, ?string $value = null) use ($doc) {
            $element = $value === null
                ? $doc->createElement($name)
                : $doc->createElement($name, htmlspecialchars($value, ENT_XML1));
            $parent->appendChild($element);

            return $element;
        };

        // 1 = faktura, 2 = ODD, 4 = proforma (dle číselníku ISDOC)
        $append($root, 'DocumentType', match ($invoice->type) {
            DocumentType::Invoice => '1',
            DocumentType::CreditNote => '2',
            DocumentType::Proforma => '4',
        });
        $append($root, 'ID', (string) $invoice->number);
        $append($root, 'UUID', (string) Str::uuid());
        $append($root, 'IssueDate', $invoice->issue_date->format('Y-m-d'));
        if ($invoice->duzp) {
            $append($root, 'TaxPointDate', $invoice->duzp->format('Y-m-d'));
        }
        $append($root, 'VATApplicable', $vatPayer ? 'true' : 'false');
        $append($root, 'ElectronicPossibilityAgreementReference', '');
        $append($root, 'LocalCurrencyCode', $invoice->currency);
        $append($root, 'CurrRate', '1');
        $append($root, 'RefCurrRate', '1');

        // Dodavatel
        $supplier = $append($append($root, 'AccountingSupplierParty'), 'Party');
        $identification = $append($supplier, 'PartyIdentification');
        $append($identification, 'ID', (string) $organization->ico);
        $append($append($supplier, 'PartyName'), 'Name', $organization->name);
        $address = $append($supplier, 'PostalAddress');
        $append($address, 'StreetName', (string) $organization->street);
        $append($address, 'BuildingNumber', '');
        $append($address, 'CityName', (string) $organization->city);
        $append($address, 'PostalZone', (string) $organization->zip);
        $country = $append($address, 'Country');
        $append($country, 'IdentificationCode', $organization->country ?: 'CZ');
        $append($country, 'Name', 'Česká republika');
        if ($organization->dic) {
            $scheme = $append($supplier, 'PartyTaxScheme');
            $append($scheme, 'CompanyID', $organization->dic);
            $append($scheme, 'TaxScheme', 'VAT');
        }

        // Odběratel
        $customer = $append($append($root, 'AccountingCustomerParty'), 'Party');
        $identification = $append($customer, 'PartyIdentification');
        $append($identification, 'ID', (string) $invoice->client?->ico);
        $append($append($customer, 'PartyName'), 'Name', (string) $invoice->client?->name);
        $address = $append($customer, 'PostalAddress');
        $append($address, 'StreetName', (string) $invoice->client?->street);
        $append($address, 'BuildingNumber', '');
        $append($address, 'CityName', (string) $invoice->client?->city);
        $append($address, 'PostalZone', (string) $invoice->client?->zip);
        $country = $append($address, 'Country');
        $append($country, 'IdentificationCode', $invoice->client?->country ?: 'CZ');
        $append($country, 'Name', 'Česká republika');
        if ($invoice->client?->dic) {
            $scheme = $append($customer, 'PartyTaxScheme');
            $append($scheme, 'CompanyID', $invoice->client->dic);
            $append($scheme, 'TaxScheme', 'VAT');
        }

        // Položky
        $lines = $append($root, 'InvoiceLines');

        foreach ($invoice->items as $index => $item) {
            $line = $append($lines, 'InvoiceLine');
            $append($line, 'ID', (string) ($index + 1));
            $append($line, 'InvoicedQuantity', (string) $item->quantity)
                ->setAttribute('unitCode', (string) $item->unit);
            $append($line, 'LineExtensionAmount', (string) $item->line_subtotal);
            $append($line, 'LineExtensionAmountTaxInclusive', (string) $item->line_total);
            $append($line, 'LineExtensionTaxAmount', (string) $item->line_vat);
            $append($line, 'UnitPrice', (string) $item->unit_price);
            $append($line, 'UnitPriceTaxInclusive', (string) $this->unitPriceWithVat($item));
            $classified = $append($line, 'ClassifiedTaxCategory');
            $append($classified, 'Percent', (string) $item->vat_rate);
            $append($classified, 'VATCalculationMethod', '0');
            $append($append($line, 'Item'), 'Description', $item->description);
        }

        // Rekapitulace DPH
        $taxTotal = $append($root, 'TaxTotal');

        foreach ($invoice->vatBreakdown() as $rate => $amounts) {
            $subTotal = $append($taxTotal, 'TaxSubTotal');
            $append($subTotal, 'TaxableAmount', $amounts['base']);
            $append($subTotal, 'TaxAmount', $amounts['vat']);
            $append($subTotal, 'TaxInclusiveAmount', bcadd($amounts['base'], $amounts['vat'], 2));
            $append($subTotal, 'AlreadyClaimedTaxableAmount', '0');
            $append($subTotal, 'AlreadyClaimedTaxAmount', '0');
            $append($subTotal, 'AlreadyClaimedTaxInclusiveAmount', '0');
            $append($subTotal, 'DifferenceTaxableAmount', $amounts['base']);
            $append($subTotal, 'DifferenceTaxAmount', $amounts['vat']);
            $append($subTotal, 'DifferenceTaxInclusiveAmount', bcadd($amounts['base'], $amounts['vat'], 2));
            $category = $append($subTotal, 'TaxCategory');
            $append($category, 'Percent', (string) $rate);
        }

        $append($taxTotal, 'TaxAmount', (string) $invoice->vat_total);

        // Součty
        $monetary = $append($root, 'LegalMonetaryTotal');
        $append($monetary, 'TaxExclusiveAmount', (string) $invoice->subtotal);
        $append($monetary, 'TaxInclusiveAmount', (string) $invoice->total);
        $append($monetary, 'AlreadyClaimedTaxExclusiveAmount', '0');
        $append($monetary, 'AlreadyClaimedTaxInclusiveAmount', '0');
        $append($monetary, 'DifferenceTaxExclusiveAmount', (string) $invoice->subtotal);
        $append($monetary, 'DifferenceTaxInclusiveAmount', (string) $invoice->total);
        $append($monetary, 'PayableRoundingAmount', '0');
        $append($monetary, 'PaidDepositsAmount', '0');
        $append($monetary, 'PayableAmount', (string) $invoice->total);

        // Platební údaje
        if ($invoice->bankAccount) {
            $means = $append($root, 'PaymentMeans');
            $payment = $append($means, 'Payment');
            $append($payment, 'PaidAmount', (string) $invoice->total);
            $append($payment, 'PaymentMeansCode', '42'); // převodem
            $details = $append($payment, 'Details');
            $append($details, 'PaymentDueDate', $invoice->due_date->format('Y-m-d'));
            $append($details, 'ID', $invoice->bankAccount->account_number);
            $append($details, 'BankCode', $invoice->bankAccount->bank_code);
            $append($details, 'Name', (string) $invoice->bankAccount->name);
            $append($details, 'IBAN', (string) $invoice->bankAccount->iban);
            $append($details, 'VariableSymbol', (string) $invoice->variable_symbol);
        }

        return $doc->saveXML();
    }

    public function filename(Invoice $invoice): string
    {
        return str_replace(['/', '\\', ' '], '-', (string) $invoice->number).'.isdoc';
    }

    private function unitPriceWithVat(InvoiceItem $item): string
    {
        $rate = bcdiv((string) $item->vat_rate, '100', 6);

        return bcadd((string) $item->unit_price, bcmul((string) $item->unit_price, $rate, 6), 2);
    }
}
