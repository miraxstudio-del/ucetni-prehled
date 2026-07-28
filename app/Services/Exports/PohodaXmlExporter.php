<?php

namespace App\Services\Exports;

use App\Enums\InvoiceDirection;
use App\Models\Invoice;
use App\Models\Organization;
use DOMDocument;
use DOMElement;
use Illuminate\Support\Collection;

/**
 * Export faktur do Pohoda XML (dataPack, schéma STORMWARE).
 * Generuje typ faktury issuedInvoice / receivableInvoice dle směru.
 */
class PohodaXmlExporter
{
    private const NS_DAT = 'http://www.stormware.cz/schema/version_2/data.xsd';

    private const NS_INV = 'http://www.stormware.cz/schema/version_2/invoice.xsd';

    private const NS_TYP = 'http://www.stormware.cz/schema/version_2/type.xsd';

    /** @param Collection<int, Invoice> $invoices */
    public function export(Organization $organization, Collection $invoices): string
    {
        $doc = new DOMDocument('1.0', 'Windows-1250');
        $doc->formatOutput = true;

        $pack = $doc->createElementNS(self::NS_DAT, 'dat:dataPack');
        $pack->setAttribute('version', '2.0');
        $pack->setAttribute('id', 'Účetní přehled-'.now()->format('YmdHis'));
        $pack->setAttribute('ico', (string) $organization->ico);
        $pack->setAttribute('application', 'Účetní přehled');
        $pack->setAttribute('note', 'Export z Účetní přehled (www.miraxstudio.cz)');
        $pack->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:inv', self::NS_INV);
        $pack->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:typ', self::NS_TYP);
        $doc->appendChild($pack);

        foreach ($invoices as $index => $invoice) {
            $item = $doc->createElementNS(self::NS_DAT, 'dat:dataPackItem');
            $item->setAttribute('version', '2.0');
            $item->setAttribute('id', 'FA-'.($index + 1));
            $pack->appendChild($item);

            $item->appendChild($this->invoiceElement($doc, $invoice));
        }

        return $doc->saveXML();
    }

    public function filename(): string
    {
        return 'pohoda-faktury-'.now()->format('Y-m-d').'.xml';
    }

    private function invoiceElement(DOMDocument $doc, Invoice $invoice): DOMElement
    {
        $element = $doc->createElementNS(self::NS_INV, 'inv:invoice');
        $element->setAttribute('version', '2.0');

        $header = $doc->createElementNS(self::NS_INV, 'inv:invoiceHeader');
        $element->appendChild($header);

        $add = function (DOMElement $parent, string $ns, string $name, ?string $value = null) use ($doc) {
            $node = $value === null
                ? $doc->createElementNS($ns, $name)
                : $doc->createElementNS($ns, $name, htmlspecialchars($value, ENT_XML1));
            $parent->appendChild($node);

            return $node;
        };

        $add($header, self::NS_INV, 'inv:invoiceType',
            $invoice->direction === InvoiceDirection::Issued ? 'issuedInvoice' : 'receivedInvoice');
        $number = $add($header, self::NS_INV, 'inv:number');
        $add($number, self::NS_TYP, 'typ:numberRequested', (string) $invoice->number);
        $add($header, self::NS_INV, 'inv:symVar', (string) $invoice->variable_symbol);
        $add($header, self::NS_INV, 'inv:date', $invoice->issue_date->format('Y-m-d'));
        if ($invoice->duzp) {
            $add($header, self::NS_INV, 'inv:dateTax', $invoice->duzp->format('Y-m-d'));
        }
        $add($header, self::NS_INV, 'inv:dateDue', $invoice->due_date->format('Y-m-d'));
        $add($header, self::NS_INV, 'inv:text', 'Faktura '.$invoice->number);

        $partner = $add($header, self::NS_INV, 'inv:partnerIdentity');
        $address = $add($partner, self::NS_TYP, 'typ:address');
        $add($address, self::NS_TYP, 'typ:company', (string) $invoice->client?->name);
        $add($address, self::NS_TYP, 'typ:street', (string) $invoice->client?->street);
        $add($address, self::NS_TYP, 'typ:city', (string) $invoice->client?->city);
        $add($address, self::NS_TYP, 'typ:zip', (string) $invoice->client?->zip);
        if ($invoice->client?->ico) {
            $add($address, self::NS_TYP, 'typ:ico', $invoice->client->ico);
        }
        if ($invoice->client?->dic) {
            $add($address, self::NS_TYP, 'typ:dic', $invoice->client->dic);
        }

        // Položky
        $detail = $doc->createElementNS(self::NS_INV, 'inv:invoiceDetail');
        $element->appendChild($detail);

        foreach ($invoice->items as $item) {
            $line = $add($detail, self::NS_INV, 'inv:invoiceItem');
            $add($line, self::NS_INV, 'inv:text', mb_substr($item->description, 0, 90));
            $add($line, self::NS_INV, 'inv:quantity', (string) $item->quantity);
            $add($line, self::NS_INV, 'inv:unit', (string) $item->unit);
            $add($line, self::NS_INV, 'inv:rateVAT', match ((int) $item->vat_rate) {
                21 => 'high',
                12 => 'low',
                default => 'none',
            });
            $currency = $add($line, self::NS_INV, 'inv:homeCurrency');
            $add($currency, self::NS_TYP, 'typ:unitPrice', (string) $item->unit_price);
            $add($currency, self::NS_TYP, 'typ:price', (string) $item->line_subtotal);
            $add($currency, self::NS_TYP, 'typ:priceVAT', (string) $item->line_vat);
            $add($currency, self::NS_TYP, 'typ:priceSum', (string) $item->line_total);
        }

        // Souhrn
        $summary = $doc->createElementNS(self::NS_INV, 'inv:invoiceSummary');
        $element->appendChild($summary);
        $home = $add($summary, self::NS_INV, 'inv:homeCurrency');
        $add($home, self::NS_TYP, 'typ:priceNone', (string) $invoice->subtotal);
        $add($home, self::NS_TYP, 'typ:priceLow', '0');
        $add($home, self::NS_TYP, 'typ:priceHigh', '0');
        $round = $add($home, self::NS_TYP, 'typ:round');
        $add($round, self::NS_TYP, 'typ:priceRound', '0');

        return $element;
    }
}
