<?php

namespace App\Services\Exports;

use App\Models\BankTransaction;
use App\Models\Invoice;
use Illuminate\Support\Collection;

/**
 * CSV exporty faktur a transakcí — středník jako oddělovač a UTF-8 BOM,
 * aby soubor správně otevřel český Excel.
 */
class CsvExporter
{
    private const BOM = "\xEF\xBB\xBF";

    /** @param Collection<int, Invoice> $invoices */
    public function invoices(Collection $invoices): string
    {
        $rows = [[
            'Číslo', 'Typ', 'Směr', 'Stav', 'Klient', 'IČO klienta',
            'Vystaveno', 'DUZP', 'Splatnost', 'Zaplaceno',
            'VS', 'Základ', 'DPH', 'Celkem', 'Měna',
        ]];

        foreach ($invoices as $invoice) {
            $rows[] = [
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
                $this->decimal($invoice->subtotal),
                $this->decimal($invoice->vat_total),
                $this->decimal($invoice->total),
                $invoice->currency,
            ];
        }

        return $this->build($rows);
    }

    /** @param Collection<int, BankTransaction> $transactions */
    public function transactions(Collection $transactions): string
    {
        $rows = [[
            'Datum', 'Částka', 'Měna', 'Protistrana', 'Účet protistrany',
            'VS', 'KS', 'SS', 'Zpráva', 'Zdroj',
        ]];

        foreach ($transactions as $transaction) {
            $rows[] = [
                $transaction->booked_on->format('d.m.Y'),
                $this->decimal($transaction->amount),
                $transaction->currency,
                $transaction->counterparty_name,
                $transaction->counterparty_account,
                $transaction->variable_symbol,
                $transaction->constant_symbol,
                $transaction->specific_symbol,
                $transaction->message,
                $transaction->import_source,
            ];
        }

        return $this->build($rows);
    }

    private function build(array $rows): string
    {
        $handle = fopen('php://temp', 'r+');

        foreach ($rows as $row) {
            fputcsv($handle, array_map(fn ($v) => $v ?? '', $row), ';', '"', '\\');
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return self::BOM.$csv;
    }

    /** Desetinná čárka pro Excel. */
    private function decimal(mixed $value): string
    {
        return str_replace('.', ',', (string) $value);
    }
}
