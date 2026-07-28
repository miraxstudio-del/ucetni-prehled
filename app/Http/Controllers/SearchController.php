<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Invoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    /**
     * Globální hledání v horní liště — faktury podle čísla/VS (nešifrované
     * sloupce, hledá se v SQL) a klienti podle názvu/IČO/e-mailu/města
     * (šifrované sloupce, srovnává se po dešifrování v PHP — viz
     * Client::matchesSearch).
     */
    public function search(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Invoice::class);
        $this->authorize('viewAny', Client::class);

        $term = trim((string) $request->query('q', ''));

        if (mb_strlen($term) < 2) {
            return response()->json(['invoices' => [], 'clients' => []]);
        }

        $invoices = Invoice::query()
            ->with('client')
            ->where(function ($query) use ($term) {
                $query->where('number', 'like', '%'.$term.'%')
                    ->orWhere('variable_symbol', 'like', '%'.$term.'%');
            })
            ->orderByDesc('issue_date')
            ->limit(6)
            ->get()
            ->map(fn (Invoice $invoice) => [
                'label' => $invoice->number ?? 'Koncept',
                'sub' => $invoice->client?->name,
                'total' => (float) $invoice->total,
                'url' => route('invoices.show', $invoice),
            ])
            ->values();

        $clients = Client::query()
            ->get()
            ->filter(fn (Client $client) => $client->matchesSearch($term))
            ->take(6)
            ->map(fn (Client $client) => [
                'label' => $client->name,
                'sub' => $client->ico ? "IČO: {$client->ico}" : $client->email,
                'url' => route('clients.show', $client),
            ])
            ->values();

        return response()->json(['invoices' => $invoices, 'clients' => $clients]);
    }
}
