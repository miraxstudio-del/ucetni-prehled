<?php

namespace App\Services\Exports;

use App\Enums\InvoiceDirection;
use App\Models\Invoice;
use App\Models\Organization;
use DOMDocument;
use Illuminate\Support\Collection;

/**
 * Export faktur do Money S3 XML (MoneyData — seznam vydaných/přijatých faktur).
 */
class MoneyS3Exporter
{
    /** @param Collection<int, Invoice> $invoices */
    public function export(Organization $organization, Collection $invoices): string
    {
        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;

        $root = $doc->createElement('MoneyData');
        $root->setAttribute('ICAgendy', (string) $organization->ico);
        $root->setAttribute('description', 'Export z Účetní přehled (www.miraxstudio.cz)');
        $doc->appendChild($root);

        $issued = $doc->createElement('SeznamFaktVyd');
        $received = $doc->createElement('SeznamFaktPrij');
        $root->appendChild($issued);
        $root->appendChild($received);

        foreach ($invoices as $invoice) {
            $isIssued = $invoice->direction === InvoiceDirection::Issued;
            $parent = $isIssued ? $issued : $received;
            $element = $doc->createElement($isIssued ? 'FaktVyd' : 'FaktPrij');
            $parent->appendChild($element);

            $add = function (string $name, ?string $value) use ($doc, $element) {
                $element->appendChild($doc->createElement($name, htmlspecialchars((string) $value, ENT_XML1)));
            };

            $add('Doklad', (string) $invoice->number);
            $add('Vystaveno', $invoice->issue_date->format('Y-m-d'));
            $add('DatUcPr', ($invoice->duzp ?? $invoice->issue_date)->format('Y-m-d'));
            $add('Splatno', $invoice->due_date->format('Y-m-d'));
            $add('VarSymbol', (string) $invoice->variable_symbol);
            $add('Celkem', (string) $invoice->total);

            $partner = $doc->createElement($isIssued ? 'DodOdb' : 'DodOdb');
            $element->appendChild($partner);
            $partner->appendChild($doc->createElement('ObchNazev', htmlspecialchars((string) $invoice->client?->name, ENT_XML1)));
            if ($invoice->client?->ico) {
                $partner->appendChild($doc->createElement('ICO', $invoice->client->ico));
            }
        }

        return $doc->saveXML();
    }

    public function filename(): string
    {
        return 'money-s3-faktury-'.now()->format('Y-m-d').'.xml';
    }
}
