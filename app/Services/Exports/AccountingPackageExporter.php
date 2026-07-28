<?php

namespace App\Services\Exports;

use App\Enums\InvoiceDirection;
use App\Enums\InvoiceStatus;
use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Models\Invoice;
use App\Services\Bank\GpcExporter;
use App\Services\Pdf\InvoicePdf;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use ZipArchive;

/**
 * Export ZIP za období pro účetní: PDF všech faktur (vydane/, prijate/),
 * faktury.csv, transakce.csv, transakce.gpc, ISDOC soubory a prehled.xlsx.
 */
class AccountingPackageExporter
{
    public function __construct(
        private readonly CsvExporter $csv,
        private readonly XlsxSummaryExporter $xlsx,
        private readonly IsdocExporter $isdoc,
        private readonly GpcExporter $gpc,
        private readonly InvoicePdf $pdf,
    ) {}

    /** Vytvoří ZIP a vrátí cestu k dočasnému souboru. */
    public function build(string $from, string $to): string
    {
        $invoices = Invoice::query()
            ->with(['items', 'client', 'bankAccount', 'organization'])
            ->whereBetween('issue_date', [$from, $to])
            ->orderBy('issue_date')
            ->get();

        $transactions = BankTransaction::query()
            ->whereBetween('booked_on', [$from, $to])
            ->orderBy('booked_on')
            ->get();

        $path = tempnam(sys_get_temp_dir(), 'ucetni-prehled-zip-');

        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Nepodařilo se vytvořit ZIP archiv.');
        }

        $zip->addFromString('faktury.csv', $this->csv->invoices($invoices));
        $zip->addFromString('transakce.csv', $this->csv->transactions($transactions));
        $zip->addFromString('prehled.xlsx', $this->xlsx->export($invoices, $transactions, $from, $to));

        // GPC výpis za každý účet s transakcemi
        foreach (BankAccount::withTrashed()->get() as $account) {
            $accountTransactions = $transactions->where('bank_account_id', $account->id);

            if ($accountTransactions->isEmpty()) {
                continue;
            }

            $zip->addFromString(
                'transakce-'.$account->account_number.'.gpc',
                $this->gpc->export($account, $accountTransactions, $from, $to),
            );
        }

        // PDF + ISDOC jednotlivých dokladů
        foreach ($invoices as $invoice) {
            if ($invoice->number === null) {
                continue; // koncepty do balíčku nepatří
            }

            $folder = $invoice->direction === InvoiceDirection::Issued ? 'vydane' : 'prijate';

            try {
                $zip->addFromString(
                    $folder.'/'.$this->pdf->filename($invoice),
                    $this->pdf->render($invoice)->output(),
                );
            } catch (\Throwable $e) {
                Log::warning('PDF do ZIP exportu selhalo: '.$e->getMessage(), ['invoice_id' => $invoice->id]);
            }

            if ($invoice->direction === InvoiceDirection::Issued
                && $invoice->status !== InvoiceStatus::Cancelled) {
                $zip->addFromString('isdoc/'.$this->isdoc->filename($invoice), $this->isdoc->export($invoice));
            }
        }

        $zip->close();

        return $path;
    }

    public function filename(string $from, string $to): string
    {
        return sprintf('ucetni-prehled-export-%s-%s.zip', $from, $to);
    }
}
