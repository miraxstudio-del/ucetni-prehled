<?php

namespace App\Services\Exports;

use App\Enums\InvoiceStatus;
use App\Models\BankTransaction;
use App\Models\Invoice;
use Illuminate\Support\Collection;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

/**
 * prehled.xlsx — rekapitulace pro účetní: souhrn, faktury a transakce.
 */
class XlsxSummaryExporter
{
    /**
     * @param  Collection<int, Invoice>  $invoices
     * @param  Collection<int, BankTransaction>  $transactions
     */
    public function export(Collection $invoices, Collection $transactions, string $from, string $to): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ucetni-prehled-xlsx-');

        $writer = new Writer;
        $writer->openToFile($path);

        // List 1: Přehled
        $writer->getCurrentSheet()->setName('Přehled');

        $issued = $invoices->where('direction.value', 'issued');
        $notCancelled = $issued->where('status', '!=', InvoiceStatus::Cancelled);

        $writer->addRow(Row::fromValues(['Účetní přehled — rekapitulace za období', $from.' až '.$to]));
        $writer->addRow(Row::fromValues([]));
        $writer->addRow(Row::fromValues(['Fakturováno (vydané, bez storna)', (float) $notCancelled->sum(fn ($i) => (float) $i->total)]));
        $writer->addRow(Row::fromValues(['— z toho zaplaceno', (float) $notCancelled->where('status', InvoiceStatus::Paid)->sum(fn ($i) => (float) $i->total)]));
        $writer->addRow(Row::fromValues(['— čeká na úhradu', (float) $notCancelled->whereIn('status', [InvoiceStatus::Issued, InvoiceStatus::Sent])->sum(fn ($i) => (float) $i->total)]));
        $writer->addRow(Row::fromValues(['DPH celkem (vydané)', (float) $notCancelled->sum(fn ($i) => (float) $i->vat_total)]));
        $writer->addRow(Row::fromValues(['Přijaté faktury (náklady)', (float) $invoices->where('direction.value', 'received')->sum(fn ($i) => (float) $i->total)]));
        $writer->addRow(Row::fromValues(['Příjmy na účtech', (float) $transactions->filter(fn ($t) => (float) $t->amount > 0)->sum(fn ($t) => (float) $t->amount)]));
        $writer->addRow(Row::fromValues(['Výdaje na účtech', (float) $transactions->filter(fn ($t) => (float) $t->amount < 0)->sum(fn ($t) => (float) $t->amount)]));

        // List 2: Faktury
        $sheet = $writer->addNewSheetAndMakeItCurrent();
        $sheet->setName('Faktury');
        $writer->addRow(Row::fromValues([
            'Číslo', 'Typ', 'Směr', 'Stav', 'Klient', 'IČO', 'Vystaveno', 'DUZP',
            'Splatnost', 'Zaplaceno', 'VS', 'Základ', 'DPH', 'Celkem', 'Měna',
        ]));

        foreach ($invoices as $invoice) {
            $writer->addRow(Row::fromValues([
                $invoice->number ?? 'koncept',
                $invoice->type->label(),
                $invoice->direction->label(),
                $invoice->status->label(),
                $invoice->client?->name,
                $invoice->client?->ico,
                $invoice->issue_date->format('d.m.Y'),
                $invoice->duzp?->format('d.m.Y'),
                $invoice->due_date->format('d.m.Y'),
                $invoice->paid_at?->format('d.m.Y'),
                $invoice->variable_symbol,
                (float) $invoice->subtotal,
                (float) $invoice->vat_total,
                (float) $invoice->total,
                $invoice->currency,
            ]));
        }

        // List 3: Transakce
        $sheet = $writer->addNewSheetAndMakeItCurrent();
        $sheet->setName('Transakce');
        $writer->addRow(Row::fromValues([
            'Datum', 'Částka', 'Měna', 'Protistrana', 'Účet', 'VS', 'Zpráva', 'Zdroj',
        ]));

        foreach ($transactions as $transaction) {
            $writer->addRow(Row::fromValues([
                $transaction->booked_on->format('d.m.Y'),
                (float) $transaction->amount,
                $transaction->currency,
                $transaction->counterparty_name,
                $transaction->counterparty_account,
                $transaction->variable_symbol,
                $transaction->message,
                $transaction->import_source,
            ]));
        }

        $writer->close();

        $content = (string) file_get_contents($path);
        @unlink($path);

        return $content;
    }
}
