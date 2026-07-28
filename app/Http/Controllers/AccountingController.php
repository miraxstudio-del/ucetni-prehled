<?php

namespace App\Http\Controllers;

use App\Models\BankTransaction;
use App\Models\Client;
use App\Models\Invoice;
use App\Services\Audit\AuditLogger;
use App\Services\Exports\AccountingPackageExporter;
use App\Services\Exports\CsvExporter;
use App\Services\Exports\MoneyS3Exporter;
use App\Services\Exports\PohodaXmlExporter;
use App\Services\Exports\XlsxSummaryExporter;
use App\Services\Imports\ClientCsvImporter;
use App\Services\Imports\InvoiceCsvImporter;
use App\Services\Imports\IsdocImporter;
use App\Support\OrganizationContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class AccountingController extends Controller
{
    public function __construct(
        private readonly OrganizationContext $context,
        private readonly AuditLogger $audit,
    ) {}

    public function show(): View
    {
        $this->authorize('viewAny', Invoice::class);

        return view('accounting.index', [
            'defaultFrom' => now()->startOfYear()->toDateString(),
            'defaultTo' => now()->toDateString(),
        ]);
    }

    /** Kompletní ZIP balíček pro účetní za období. */
    public function exportZip(Request $request, AccountingPackageExporter $exporter): Response
    {
        $this->authorize('viewAny', Invoice::class);

        [$from, $to] = $this->period($request);

        $path = $exporter->build($from, $to);

        $this->audit->log('export.zip', meta: ['from' => $from, 'to' => $to]);

        return response()
            ->download($path, $exporter->filename($from, $to))
            ->deleteFileAfterSend();
    }

    public function exportInvoicesCsv(Request $request, CsvExporter $exporter): Response
    {
        $this->authorize('viewAny', Invoice::class);

        [$from, $to] = $this->period($request);

        $invoices = Invoice::with('client')
            ->whereBetween('issue_date', [$from, $to])
            ->orderBy('issue_date')
            ->get();

        $this->audit->log('export.invoices_csv', meta: ['from' => $from, 'to' => $to]);

        return $this->csvResponse($exporter->invoices($invoices), "faktury-{$from}-{$to}.csv");
    }

    public function exportTransactionsCsv(Request $request, CsvExporter $exporter): Response
    {
        $this->authorize('viewAny', Invoice::class);

        [$from, $to] = $this->period($request);

        $transactions = BankTransaction::whereBetween('booked_on', [$from, $to])
            ->orderBy('booked_on')
            ->get();

        $this->audit->log('export.transactions_csv', meta: ['from' => $from, 'to' => $to]);

        return $this->csvResponse($exporter->transactions($transactions), "transakce-{$from}-{$to}.csv");
    }

    public function exportXlsx(Request $request, XlsxSummaryExporter $exporter): Response
    {
        $this->authorize('viewAny', Invoice::class);

        [$from, $to] = $this->period($request);

        $invoices = Invoice::with('client')->whereBetween('issue_date', [$from, $to])->get();
        $transactions = BankTransaction::whereBetween('booked_on', [$from, $to])->get();

        $this->audit->log('export.xlsx', meta: ['from' => $from, 'to' => $to]);

        return response($exporter->export($invoices, $transactions, $from, $to), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="prehled-'.$from.'-'.$to.'.xlsx"',
        ]);
    }

    public function exportPohoda(Request $request, PohodaXmlExporter $exporter): Response
    {
        $this->authorize('viewAny', Invoice::class);

        [$from, $to] = $this->period($request);

        $invoices = Invoice::with(['client', 'items'])
            ->whereNotNull('number')
            ->whereBetween('issue_date', [$from, $to])
            ->get();

        $this->audit->log('export.pohoda', meta: ['from' => $from, 'to' => $to]);

        return response($exporter->export($this->context->current(), $invoices), 200, [
            'Content-Type' => 'application/xml; charset=windows-1250',
            'Content-Disposition' => 'attachment; filename="'.$exporter->filename().'"',
        ]);
    }

    public function exportMoney(Request $request, MoneyS3Exporter $exporter): Response
    {
        $this->authorize('viewAny', Invoice::class);

        [$from, $to] = $this->period($request);

        $invoices = Invoice::with('client')
            ->whereNotNull('number')
            ->whereBetween('issue_date', [$from, $to])
            ->get();

        $this->audit->log('export.money_s3', meta: ['from' => $from, 'to' => $to]);

        return response($exporter->export($this->context->current(), $invoices), 200, [
            'Content-Type' => 'application/xml; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.$exporter->filename().'"',
        ]);
    }

    public function templateClients(): Response
    {
        return $this->csvResponse("\xEF\xBB\xBF".ClientCsvImporter::TEMPLATE, 'sablona-klienti.csv');
    }

    public function templateInvoices(): Response
    {
        return $this->csvResponse("\xEF\xBB\xBF".InvoiceCsvImporter::TEMPLATE, 'sablona-faktury.csv');
    }

    public function importClients(Request $request, ClientCsvImporter $importer): RedirectResponse
    {
        $this->authorize('create', Client::class);

        $request->validate(['file' => ['required', 'file', 'max:5120']], [], ['file' => 'soubor']);

        $result = $importer->import($request->file('file')->get());

        return $this->importResponse('Klienti', $result);
    }

    public function importInvoices(Request $request, InvoiceCsvImporter $importer): RedirectResponse
    {
        $this->authorize('create', Invoice::class);

        $request->validate(['file' => ['required', 'file', 'max:5120']], [], ['file' => 'soubor']);

        $result = $importer->import($request->file('file')->get());

        return $this->importResponse('Faktury', $result);
    }

    public function importIsdoc(Request $request, IsdocImporter $importer): RedirectResponse
    {
        $this->authorize('create', Invoice::class);

        $request->validate(['file' => ['required', 'file', 'max:5120']], [], ['file' => 'soubor']);

        $invoice = $importer->import($request->file('file')->get());

        return redirect()->route('invoices.show', $invoice)
            ->with('status', 'Přijatá faktura '.$invoice->number.' byla naimportována z ISDOC.');
    }

    /** @return array{0: string, 1: string} */
    private function period(Request $request): array
    {
        $validated = $request->validate([
            'od' => ['required', 'date'],
            'do' => ['required', 'date', 'after_or_equal:od'],
        ], [], ['od' => 'od', 'do' => 'do']);

        return [$validated['od'], $validated['do']];
    }

    private function csvResponse(string $content, string $filename): Response
    {
        return response($content, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    private function importResponse(string $label, array $result): RedirectResponse
    {
        $message = "{$label}: naimportováno {$result['imported']}, přeskočeno {$result['skipped']}.";

        $redirect = back()->with('status', $message);

        if ($result['errors'] !== []) {
            $redirect->with('importErrors', array_slice($result['errors'], 0, 20));
        }

        return $redirect;
    }
}
