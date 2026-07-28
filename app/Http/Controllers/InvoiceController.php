<?php

namespace App\Http\Controllers;

use App\Enums\DocumentType;
use App\Enums\InvoiceDirection;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Models\BankAccount;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\NumberSeries;
use App\Services\Exports\IsdocExporter;
use App\Services\Invoicing\InvoiceService;
use App\Services\Pdf\InvoicePdf;
use App\Support\OrganizationContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class InvoiceController extends Controller
{
    public function __construct(
        private readonly InvoiceService $service,
        private readonly OrganizationContext $context,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Invoice::class);

        $direction = InvoiceDirection::tryFrom((string) $request->query('smer')) ?? InvoiceDirection::Issued;
        $status = InvoiceStatus::tryFrom((string) $request->query('stav'));
        $year = $request->integer('rok') ?: null;
        $perPage = in_array($request->integer('na_stranku'), [10, 25, 50, 100], true)
            ? $request->integer('na_stranku')
            : 25;

        $query = Invoice::query()
            ->with('client')
            ->where('direction', $direction)
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($year, fn ($q) => $q->whereYear('issue_date', $year))
            ->search($request->query('q'))
            ->orderByDesc('issue_date')
            ->orderByDesc('id');

        $sumTotal = (clone $query)->where('status', '!=', InvoiceStatus::Cancelled)->sum('total');

        // Rozpad podle stavu je nezávislý na výběru ve filtru „Stav" — jinak
        // by po vyfiltrování na jeden stav zbylé tři karty ztratily smysl.
        $baseline = Invoice::query()
            ->where('direction', $direction)
            ->when($year, fn ($q) => $q->whereYear('issue_date', $year))
            ->search($request->query('q'));

        $openStatuses = [InvoiceStatus::Issued, InvoiceStatus::Sent];

        $stats = [
            'total' => (clone $baseline)->where('status', '!=', InvoiceStatus::Cancelled)
                ->selectRaw('COUNT(*) as count, COALESCE(SUM(total), 0) as sum')->first(),
            'overdue' => (clone $baseline)->whereIn('status', $openStatuses)->whereDate('due_date', '<', today())
                ->selectRaw('COUNT(*) as count, COALESCE(SUM(total), 0) as sum')->first(),
            'unpaid' => (clone $baseline)->whereIn('status', $openStatuses)->whereDate('due_date', '>=', today())
                ->selectRaw('COUNT(*) as count, COALESCE(SUM(total), 0) as sum')->first(),
            'paid' => (clone $baseline)->where('status', InvoiceStatus::Paid)
                ->selectRaw('COUNT(*) as count, COALESCE(SUM(total), 0) as sum')->first(),
        ];

        return view('invoices.index', [
            'invoices' => $query->paginate($perPage)->withQueryString(),
            'direction' => $direction,
            'status' => $status,
            'year' => $year,
            'perPage' => $perPage,
            'search' => $request->query('q'),
            'sumTotal' => $sumTotal,
            'stats' => $stats,
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorize('create', Invoice::class);

        $invoice = new Invoice([
            'direction' => InvoiceDirection::tryFrom((string) $request->query('smer')) ?? InvoiceDirection::Issued,
            'type' => DocumentType::Invoice,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays($this->context->current()->default_due_days)->toDateString(),
            'payment_method' => PaymentMethod::BankTransfer,
        ]);

        return view('invoices.form', $this->formData($invoice));
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Invoice::class);

        [$data, $items] = $this->validated($request);

        $invoice = $this->service->saveDraft($data, $items);

        if ($request->input('action') === 'issue') {
            $this->authorize('issue', $invoice);
            $this->service->issue($invoice);

            return redirect()->route('invoices.show', $invoice)
                ->with('status', 'Doklad '.$invoice->number.' byl vystaven.');
        }

        return redirect()->route('invoices.show', $invoice)
            ->with('status', 'Koncept byl uložen.');
    }

    public function show(Invoice $invoice): View
    {
        $this->authorize('view', $invoice);

        $invoice->load(['items', 'client', 'bankAccount', 'emailLogs' => fn ($q) => $q->latest()]);

        return view('invoices.show', ['invoice' => $invoice]);
    }

    public function edit(Invoice $invoice): View
    {
        $this->authorize('update', $invoice);

        $invoice->load('items');

        return view('invoices.form', $this->formData($invoice));
    }

    public function update(Request $request, Invoice $invoice): RedirectResponse
    {
        $this->authorize('update', $invoice);

        [$data, $items] = $this->validated($request, $invoice);

        $invoice = $this->service->saveDraft($data, $items, $invoice);

        if ($request->input('action') === 'issue') {
            $this->authorize('issue', $invoice);
            $this->service->issue($invoice);

            return redirect()->route('invoices.show', $invoice)
                ->with('status', 'Doklad '.$invoice->number.' byl vystaven.');
        }

        return redirect()->route('invoices.show', $invoice)
            ->with('status', 'Koncept byl uložen.');
    }

    public function destroy(Invoice $invoice): RedirectResponse
    {
        $this->authorize('delete', $invoice);

        $invoice->delete();

        return redirect()->route('invoices.index')
            ->with('status', 'Koncept byl smazán.');
    }

    /**
     * Trvalé smazání i vystavené/zaplacené faktury (jen vlastník) — obchází
     * storno a vytvoří díru v číselné řadě, proto vyžaduje opsání čísla
     * dokladu jako potvrzení.
     */
    public function forceDestroy(Request $request, Invoice $invoice): RedirectResponse
    {
        $this->authorize('forceDelete', $invoice);

        $expected = $invoice->number ?? 'KONCEPT';

        $request->validate([
            'confirm_number' => ['required', 'string', Rule::in([$expected])],
        ], [
            'confirm_number.in' => 'Zadané číslo neodpovídá číslu faktury.',
        ], ['confirm_number' => 'potvrzovací číslo']);

        $this->service->forceDelete($invoice);

        return redirect()->route('invoices.index')
            ->with('status', "Faktura {$expected} byla trvale smazána.");
    }

    public function issue(Invoice $invoice): RedirectResponse
    {
        $this->authorize('issue', $invoice);

        if ($invoice->direction === InvoiceDirection::Received && $invoice->number === null) {
            throw ValidationException::withMessages([
                'number' => 'U přijaté faktury doplňte číslo dokladu dodavatele.',
            ]);
        }

        $this->service->issue($invoice);

        return back()->with('status', 'Doklad '.$invoice->number.' byl vystaven.');
    }

    public function markPaid(Request $request, Invoice $invoice): RedirectResponse
    {
        $this->authorize('markPaid', $invoice);

        $validated = $request->validate(['paid_at' => ['nullable', 'date']]);

        $this->service->markPaid($invoice, $validated['paid_at'] ?? null);

        return back()->with('status', 'Doklad byl označen jako zaplacený.');
    }

    public function cancel(Invoice $invoice): RedirectResponse
    {
        $this->authorize('cancel', $invoice);

        $this->service->cancel($invoice);

        return back()->with('status', 'Doklad byl stornován.');
    }

    public function duplicate(Invoice $invoice): RedirectResponse
    {
        $this->authorize('duplicate', $invoice);

        $copy = $this->service->duplicate($invoice);

        return redirect()->route('invoices.edit', $copy)
            ->with('status', 'Kopie dokladu byla vytvořena jako koncept.');
    }

    public function pdf(Invoice $invoice, InvoicePdf $pdf)
    {
        $this->authorize('view', $invoice);

        return $pdf->render($invoice)->stream($pdf->filename($invoice));
    }

    public function isdoc(Invoice $invoice, IsdocExporter $exporter)
    {
        $this->authorize('view', $invoice);

        abort_if($invoice->number === null, 404, 'Koncept nelze exportovat do ISDOC.');

        return response($exporter->export($invoice), 200, [
            'Content-Type' => 'application/xml; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.$exporter->filename($invoice).'"',
        ]);
    }

    public function send(Request $request, Invoice $invoice, InvoicePdf $pdf): RedirectResponse
    {
        $this->authorize('send', $invoice);

        $validated = $request->validate([
            'to' => ['required', 'email'],
            'message' => ['nullable', 'string', 'max:2000'],
        ], [], ['to' => 'e-mail příjemce', 'message' => 'zpráva']);

        // Selhání SMTP nesmí shodit stránku — pokus je zaznamenaný v email logu.
        try {
            $this->service->send($invoice, $validated['to'], $validated['message'] ?? null, $pdf);
        } catch (\Throwable $e) {
            Log::error('Odeslání faktury selhalo: '.$e->getMessage(), [
                'invoice_id' => $invoice->id,
            ]);

            throw ValidationException::withMessages([
                'to' => 'Fakturu se nepodařilo odeslat — e-mailový server neodpovídá. '
                    .'Zkuste to prosím znovu později; PDF si můžete stáhnout a poslat ručně.',
            ]);
        }

        return back()->with('status', 'Faktura byla odeslána na '.$validated['to'].'.');
    }

    /** @return array{0: array, 1: array} */
    private function validated(Request $request, ?Invoice $invoice = null): array
    {
        $organizationId = $this->context->id();

        $validated = $request->validate([
            'direction' => ['required', Rule::enum(InvoiceDirection::class)],
            'type' => ['required', Rule::enum(DocumentType::class)],
            'client_id' => ['required', 'integer'],
            'number' => [
                'nullable', 'string', 'max:30',
                Rule::unique('invoices', 'number')
                    ->where('organization_id', $organizationId)
                    ->whereNull('deleted_at')
                    ->ignore($invoice?->id),
            ],
            'variable_symbol' => ['nullable', 'digits_between:1,10'],
            'issue_date' => ['required', 'date'],
            'duzp' => ['nullable', 'date'],
            'due_date' => ['required', 'date', 'after_or_equal:issue_date'],
            'payment_method' => ['required', Rule::enum(PaymentMethod::class)],
            'bank_account_id' => ['nullable', 'integer'],
            'note' => ['nullable', 'string', 'max:2000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.description' => ['required', 'string', 'max:500'],
            'items.*.quantity' => ['required', 'string', 'max:15'],
            'items.*.unit' => ['nullable', 'string', 'max:20'],
            'items.*.unit_price' => ['required', 'string', 'max:20'],
            'items.*.vat_rate' => ['required', Rule::in(['21', '12', '0'])],
        ], [
            'items.required' => 'Doklad musí obsahovat alespoň jednu položku.',
            'due_date.after_or_equal' => 'Splatnost nemůže předcházet datu vystavení.',
        ], [
            'client_id' => 'klient',
            'issue_date' => 'datum vystavení',
            'due_date' => 'splatnost',
            'items.*.description' => 'popis položky',
            'items.*.unit_price' => 'cena',
        ]);

        // tenant-safe ověření vazeb: globální scope vrátí 404 pro cizí záznamy
        Client::findOrFail($validated['client_id']);

        if (! empty($validated['bank_account_id'])) {
            BankAccount::findOrFail($validated['bank_account_id']);
        }

        // vydané doklady dostávají číslo výhradně z číselné řady
        if ($validated['direction'] === InvoiceDirection::Issued->value) {
            $validated['number'] = $invoice?->number;
        }

        $items = $validated['items'];
        unset($validated['items']);

        return [$validated, $items];
    }

    private function formData(Invoice $invoice): array
    {
        return [
            'invoice' => $invoice,
            'clients' => Client::orderBy('name')->get(['id', 'name', 'email', 'due_days']),
            'bankAccounts' => BankAccount::orderByDesc('is_default')->get(),
            'numberSeries' => NumberSeries::orderBy('document_type')->get(),
            'organization' => $this->context->current(),
        ];
    }
}
