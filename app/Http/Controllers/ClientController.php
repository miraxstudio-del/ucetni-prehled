<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class ClientController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Client::class);

        // Jméno je v DB zašifrované → filtrovat i řadit lze až po dešifrování
        // v PHP. Klientů jsou řádově desítky, takže se načtou všichni a
        // stránkuje se ručně.
        $term = $request->query('q');
        $page = max(1, $request->integer('page') ?: 1);
        $perPage = 25;

        $all = Client::query()
            ->withCount('invoices')
            ->withMax('invoices', 'issue_date')
            ->get()
            ->filter(fn (Client $client) => $client->matchesSearch($term))
            ->sortBy(fn (Client $client) => mb_strtolower((string) $client->name), SORT_NATURAL)
            ->values();

        // withMax vrací syrový řetězec, ne Carbon instanci — dopočítá se ručně,
        // ať to jde použít stejně v kartách i v tabulce (sloupec Poslední aktivita).
        $all->each(function (Client $client) {
            $client->invoices_max_issue_date = $client->invoices_max_issue_date
                ? Carbon::parse($client->invoices_max_issue_date)
                : null;
        });

        $clients = new LengthAwarePaginator(
            $all->forPage($page, $perPage),
            $all->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );

        // "Aktivní" = má fakturu za posledních 12 měsíců. "Firemní" podle
        // vyplněného IČO — u OSVČ bez IČO jde o soukromou osobu.
        $activeSince = now()->subMonths(12);
        $stats = [
            'total' => $all->count(),
            'active' => $all->filter(fn (Client $c) => $c->invoices_max_issue_date && $c->invoices_max_issue_date->greaterThanOrEqualTo($activeSince))->count(),
            'company' => $all->filter(fn (Client $c) => filled($c->ico))->count(),
            'individual' => $all->filter(fn (Client $c) => blank($c->ico))->count(),
        ];

        return view('clients.index', [
            'clients' => $clients,
            'search' => $term,
            'stats' => $stats,
        ]);
    }

    public function create(): View
    {
        $this->authorize('create', Client::class);

        return view('clients.form', ['client' => new Client]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Client::class);

        $client = Client::create($this->validated($request));

        $this->audit->log('client.created', entity: $client);

        return redirect()->route('clients.show', $client)
            ->with('status', 'Klient byl vytvořen.');
    }

    public function show(Client $client): View
    {
        $this->authorize('view', $client);

        $invoices = $client->invoices()
            ->orderByDesc('issue_date')
            ->limit(50)
            ->get();

        return view('clients.show', [
            'client' => $client,
            'invoices' => $invoices,
            'totalRevenue' => $client->totalRevenue(),
        ]);
    }

    public function edit(Client $client): View
    {
        $this->authorize('update', $client);

        return view('clients.form', ['client' => $client]);
    }

    public function update(Request $request, Client $client): RedirectResponse
    {
        $this->authorize('update', $client);

        $client->update($this->validated($request));

        $this->audit->log('client.updated', entity: $client);

        return redirect()->route('clients.show', $client)
            ->with('status', 'Klient byl upraven.');
    }

    public function destroy(Client $client): RedirectResponse
    {
        $this->authorize('delete', $client);

        $client->delete();

        $this->audit->log('client.deleted', entity: $client);

        return redirect()->route('clients.index')
            ->with('status', 'Klient byl smazán.');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'ico' => ['nullable', 'digits:8'],
            'dic' => ['nullable', 'string', 'max:12', 'regex:/^[A-Z]{2}[0-9A-Z]+$/'],
            'street' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'zip' => ['nullable', 'string', 'max:10'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'note' => ['nullable', 'string', 'max:2000'],
            'due_days' => ['nullable', 'integer', 'min:1', 'max:365'],
        ], [
            'ico.digits' => 'IČO musí mít přesně 8 číslic.',
            'dic.regex' => 'DIČ zadejte ve formátu CZ12345678.',
        ], [
            'name' => 'název',
            'ico' => 'IČO',
            'dic' => 'DIČ',
            'email' => 'e-mail',
        ]);
    }
}
