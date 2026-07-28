<?php

namespace App\Http\Controllers;

use App\Enums\InvoiceDirection;
use App\Enums\InvoiceStatus;
use App\Models\BankAccount;
use App\Models\BankConnection;
use App\Models\BankTransaction;
use App\Models\Invoice;
use App\Models\PaymentMatch;
use App\Services\Audit\AuditLogger;
use App\Services\Bank\BankSyncService;
use App\Services\Bank\GpcExporter;
use App\Services\Bank\GpcParser;
use App\Services\Bank\PaymentMatcher;
use App\Services\Bank\PaymentVerifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class BankController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', BankTransaction::class);

        $year = $request->integer('rok') ?: now()->year;
        $type = $request->query('typ'); // prijmy | vydaje | null

        $search = $request->query('q');

        $query = BankTransaction::query()
            ->with(['bankAccount', 'matches.invoice'])
            ->whereYear('booked_on', $year)
            ->when($type === 'prijmy', fn ($q) => $q->where('amount', '>', 0))
            ->when($type === 'vydaje', fn ($q) => $q->where('amount', '<', 0))
            ->orderByDesc('booked_on')
            ->orderByDesc('id');

        $sums = BankTransaction::query()
            ->whereYear('booked_on', $year)
            ->selectRaw('SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END) as income,
                         SUM(CASE WHEN amount < 0 THEN amount ELSE 0 END) as expense')
            ->first();

        // faktury nabízené k ručnímu spárování
        $openInvoices = Invoice::query()
            ->where('direction', InvoiceDirection::Issued)
            ->whereIn('status', [InvoiceStatus::Issued, InvoiceStatus::Sent])
            ->orderByDesc('issue_date')
            ->limit(100)
            ->get(['id', 'number', 'total', 'variable_symbol']);

        // Protistrana a zpráva jsou zašifrované → při hledání se musí načíst
        // a porovnat v PHP. Bez hledání zůstává stránkování čistě v SQL.
        if (filled($search)) {
            $page = max(1, $request->integer('page') ?: 1);
            $matched = $query->get()
                ->filter(fn (BankTransaction $t) => $t->matchesSearch($search))
                ->values();

            $transactions = new LengthAwarePaginator(
                $matched->forPage($page, 30),
                $matched->count(),
                30,
                $page,
                ['path' => $request->url(), 'query' => $request->query()],
            );
        } else {
            $transactions = $query->paginate(30)->withQueryString();
        }

        return view('bank.index', [
            'transactions' => $transactions,
            'accounts' => BankAccount::orderByDesc('is_default')->get(),
            'connections' => BankConnection::with('bankAccount')->get(),
            'openInvoices' => $openInvoices,
            'year' => $year,
            'type' => $type,
            'search' => $request->query('q'),
            'income' => $sums->income ?? 0,
            'expense' => $sums->expense ?? 0,
        ]);
    }

    public function sync(BankSyncService $service): RedirectResponse
    {
        $this->authorize('manage', BankTransaction::class);

        $connections = BankConnection::all();

        if ($connections->isEmpty()) {
            return back()->with('status', 'Nejdříve napojte bankovní účet (API token).');
        }

        $imported = 0;
        $matched = 0;

        foreach ($connections as $connection) {
            try {
                $result = $service->sync($connection);
                $imported += $result['imported'];
                $matched += $result['matched'];
            } catch (\Throwable $e) {
                return back()->withErrors(['sync' => 'Synchronizace selhala: '.$e->getMessage()]);
            }
        }

        return back()->with('status', "Synchronizace dokončena — nových transakcí: {$imported}, spárováno plateb: {$matched}.");
    }

    /**
     * Ruční spuštění z UI — projde neuhrazené a importem-domnělé-zaplacené
     * faktury běžícího výběru let a zkusí je spárovat s bankou (stejná logika
     * jako `ucetni-prehled:verify-payments`, viz PaymentVerifier).
     */
    public function verifyPayments(Request $request, PaymentVerifier $verifier): RedirectResponse
    {
        $this->authorize('manage', BankTransaction::class);

        $year = $request->integer('rok') ?: now()->year;

        $result = $verifier->verify($year);

        $paired = count($result['paired']);
        $downgraded = count($result['downgraded']);

        $message = match (true) {
            $paired === 0 && $downgraded === 0 => "Ověřeno za rok {$year} — žádné nové platby k dohledání v bance.",
            $downgraded === 0 => "Ověřeno za rok {$year} — spárováno s bankou: ".implode(', ', $result['paired']).'.',
            default => "Ověřeno za rok {$year} — spárováno s bankou: ".implode(', ', $result['paired'])
                .'. Bez dokladu o platbě vráceno na „vystaveno“: '.implode(', ', $result['downgraded']).'.',
        };

        return back()->with('status', $message);
    }

    public function storeConnection(Request $request, AuditLogger $audit): RedirectResponse
    {
        $this->authorize('manage', BankTransaction::class);

        $validated = $request->validate([
            'bank_account_id' => ['required', 'integer'],
            // alfanumericky: token se vkládá do cesty URL Fio API — omezením
            // formátu vyloučíme pokus o manipulaci s cestou (SSRF/traversal)
            'api_token' => ['required', 'string', 'alpha_num', 'min:32', 'max:128'],
        ], [
            'api_token.alpha_num' => 'Fio token smí obsahovat jen písmena a číslice.',
        ], ['bank_account_id' => 'účet', 'api_token' => 'API token']);

        $account = BankAccount::findOrFail($validated['bank_account_id']);

        BankConnection::updateOrCreate(
            ['bank_account_id' => $account->id],
            ['provider' => 'fio', 'api_token' => $validated['api_token'], 'status' => 'active', 'last_error' => null],
        );

        $audit->log('bank.connection_saved', meta: ['bank_account_id' => $account->id, 'provider' => 'fio']);

        return back()->with('status', 'Napojení na Fio banku bylo uloženo. Token je v databázi šifrovaný.');
    }

    public function destroyConnection(BankConnection $connection, AuditLogger $audit): RedirectResponse
    {
        $this->authorize('manage', BankTransaction::class);

        $connection->delete();

        $audit->log('bank.connection_removed', meta: ['bank_account_id' => $connection->bank_account_id]);

        return back()->with('status', 'Napojení bylo odebráno.');
    }

    public function import(Request $request, GpcParser $parser, BankSyncService $service, PaymentMatcher $matcher, AuditLogger $audit): RedirectResponse
    {
        $this->authorize('manage', BankTransaction::class);

        $validated = $request->validate([
            'bank_account_id' => ['required', 'integer'],
            'file' => ['required', 'file', 'max:5120'],
        ], [], ['bank_account_id' => 'účet', 'file' => 'soubor']);

        $account = BankAccount::findOrFail($validated['bank_account_id']);

        $parsed = $parser->parse($request->file('file')->get());

        if ($parsed['transactions'] === []) {
            throw ValidationException::withMessages([
                'file' => 'V souboru nebyly nalezeny žádné položky (věty 075). Je to výpis ve formátu GPC/ABO?',
            ]);
        }

        $imported = 0;
        $matched = 0;

        foreach ($parsed['transactions'] as $data) {
            $transaction = $service->store($account->id, $data, source: 'gpc');

            if ($transaction === null) {
                continue;
            }

            $imported++;

            if ($matcher->autoMatch($transaction)) {
                $matched++;
            }
        }

        $skipped = count($parsed['transactions']) - $imported;

        $audit->log('bank.gpc_imported', meta: [
            'bank_account_id' => $account->id,
            'imported' => $imported,
            'skipped_duplicates' => $skipped,
        ]);

        return back()->with('status',
            "Import dokončen — nových transakcí: {$imported}, přeskočených duplicit: {$skipped}, spárováno plateb: {$matched}.");
    }

    public function export(Request $request, GpcExporter $exporter, AuditLogger $audit): Response
    {
        $this->authorize('export', BankTransaction::class);

        $validated = $request->validate([
            'bank_account_id' => ['required', 'integer'],
            'od' => ['required', 'date'],
            'do' => ['required', 'date', 'after_or_equal:od'],
        ], [], ['bank_account_id' => 'účet', 'od' => 'od', 'do' => 'do']);

        $account = BankAccount::findOrFail($validated['bank_account_id']);

        $transactions = BankTransaction::query()
            ->where('bank_account_id', $account->id)
            ->whereBetween('booked_on', [$validated['od'], $validated['do']])
            ->get();

        $content = $exporter->export($account, $transactions, $validated['od'], $validated['do']);

        $audit->log('bank.gpc_exported', meta: [
            'bank_account_id' => $account->id,
            'from' => $validated['od'],
            'to' => $validated['do'],
            'count' => $transactions->count(),
        ]);

        return response($content, 200, [
            'Content-Type' => 'application/octet-stream; charset=windows-1250',
            'Content-Disposition' => 'attachment; filename="'.$exporter->filename($validated['od'], $validated['do']).'"',
        ]);
    }

    public function match(Request $request, BankTransaction $transaction, PaymentMatcher $matcher): RedirectResponse
    {
        $this->authorize('manage', BankTransaction::class);

        $validated = $request->validate([
            'invoice_id' => ['required', 'integer'],
        ], [], ['invoice_id' => 'faktura']);

        $invoice = Invoice::findOrFail($validated['invoice_id']);

        if ($transaction->matches()->where('invoice_id', $invoice->id)->exists()) {
            return back();
        }

        $matcher->manualMatch($invoice, $transaction, $request->user()->id);

        return back()->with('status', 'Platba byla spárována s fakturou '.$invoice->number.'.');
    }

    public function unmatch(PaymentMatch $match, Request $request, PaymentMatcher $matcher): RedirectResponse
    {
        $this->authorize('manage', BankTransaction::class);

        $matcher->unmatch($match, $request->user()->id);

        return back()->with('status', 'Spárování bylo zrušeno.');
    }
}
