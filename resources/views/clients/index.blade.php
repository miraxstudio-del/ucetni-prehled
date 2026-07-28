@php
    $avatarPalette = [
        'bg-blue-100 text-blue-700 dark:bg-blue-950 dark:text-blue-300',
        'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300',
        'bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300',
        'bg-rose-100 text-rose-700 dark:bg-rose-950 dark:text-rose-300',
        'bg-violet-100 text-violet-700 dark:bg-violet-950 dark:text-violet-300',
        'bg-cyan-100 text-cyan-700 dark:bg-cyan-950 dark:text-cyan-300',
        'bg-orange-100 text-orange-700 dark:bg-orange-950 dark:text-orange-300',
        'bg-pink-100 text-pink-700 dark:bg-pink-950 dark:text-pink-300',
    ];
    $initials = function (string $name) {
        $words = array_values(array_filter(explode(' ', trim($name))));
        $chars = array_map(fn ($w) => mb_substr($w, 0, 1), array_slice($words, 0, 2));

        return mb_strtoupper(implode('', $chars)) ?: '?';
    };
@endphp

<x-app-layout title="Klienti">
    <div class="mb-6 flex flex-wrap items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold tracking-tight">Klienti</h1>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ $clients->total() }} klientů</p>
        </div>
        @can('create', App\Models\Client::class)
            <a href="{{ route('clients.create') }}"
               class="inline-flex items-center gap-2 rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-primary-700">
                <x-lucide-plus class="h-4 w-4" /> Nový klient
            </a>
        @endcan
    </div>

    {{-- Statistiky --}}
    <div class="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
            <div class="flex items-center gap-3">
                <span class="flex h-10 w-10 items-center justify-center rounded-full bg-primary-50 text-primary-600 dark:bg-primary-950 dark:text-primary-400">
                    <x-lucide-users class="h-5 w-5" />
                </span>
                <span class="text-sm font-medium text-slate-500 dark:text-slate-400">Celkem klientů</span>
            </div>
            <p class="mt-3 text-2xl font-bold tabular-nums">{{ $stats['total'] }}</p>
        </div>
        <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
            <div class="flex items-center gap-3">
                <span class="flex h-10 w-10 items-center justify-center rounded-full bg-emerald-50 text-emerald-600 dark:bg-emerald-950 dark:text-emerald-400">
                    <x-lucide-circle-check class="h-5 w-5" />
                </span>
                <span class="text-sm font-medium text-slate-500 dark:text-slate-400">Aktivní klienti</span>
            </div>
            <p class="mt-3 text-2xl font-bold tabular-nums">{{ $stats['active'] }}</p>
            <p class="mt-1 text-xs text-slate-400">fakturováno za posledních 12 měsíců</p>
        </div>
        <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
            <div class="flex items-center gap-3">
                <span class="flex h-10 w-10 items-center justify-center rounded-full bg-sky-50 text-sky-600 dark:bg-sky-950 dark:text-sky-400">
                    <x-lucide-building-2 class="h-5 w-5" />
                </span>
                <span class="text-sm font-medium text-slate-500 dark:text-slate-400">Firemní klienti</span>
            </div>
            <p class="mt-3 text-2xl font-bold tabular-nums">{{ $stats['company'] }}</p>
            <p class="mt-1 text-xs text-slate-400">{{ $stats['total'] ? round($stats['company'] / $stats['total'] * 100) : 0 }} % z celkového počtu</p>
        </div>
        <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
            <div class="flex items-center gap-3">
                <span class="flex h-10 w-10 items-center justify-center rounded-full bg-orange-50 text-orange-600 dark:bg-orange-950 dark:text-orange-400">
                    <x-lucide-user class="h-5 w-5" />
                </span>
                <span class="text-sm font-medium text-slate-500 dark:text-slate-400">Soukromé osoby</span>
            </div>
            <p class="mt-3 text-2xl font-bold tabular-nums">{{ $stats['individual'] }}</p>
            <p class="mt-1 text-xs text-slate-400">{{ $stats['total'] ? round($stats['individual'] / $stats['total'] * 100) : 0 }} % z celkového počtu</p>
        </div>
    </div>

    <div class="mb-4 flex flex-wrap items-center gap-3">
        <form method="GET" class="max-w-sm flex-1">
            <div class="relative">
                <x-lucide-search class="pointer-events-none absolute left-3 top-2.5 h-4 w-4 text-slate-400" />
                <input type="search" name="q" value="{{ $search }}" placeholder="Hledat podle názvu, IČO, e-mailu…"
                       class="w-full rounded-lg border border-slate-300 bg-white py-2 pl-9 pr-3 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/30 dark:border-slate-700 dark:bg-slate-800">
            </div>
        </form>
        <button type="button" disabled title="Připravujeme"
                class="inline-flex cursor-not-allowed items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-medium text-slate-400 dark:border-slate-800 dark:bg-slate-900">
            <x-lucide-list-filter class="h-4 w-4" /> Filtry (již brzy)
        </button>
        <button type="button" disabled title="Připravujeme"
                class="inline-flex cursor-not-allowed items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-medium text-slate-400 dark:border-slate-800 dark:bg-slate-900">
            <x-lucide-tag class="h-4 w-4" /> Štítky (již brzy)
        </button>
    </div>

    <div class="overflow-x-auto rounded-xl bg-white shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-slate-200 text-left text-xs uppercase tracking-wide text-slate-500 dark:border-slate-800 dark:text-slate-400">
                    <th class="px-4 py-3">Název</th>
                    <th class="px-4 py-3">IČO</th>
                    <th class="px-4 py-3">E-mail</th>
                    <th class="px-4 py-3">Město</th>
                    <th class="px-4 py-3 text-right">Faktur</th>
                    <th class="px-4 py-3">Poslední aktivita</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                @forelse ($clients as $client)
                    <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/50">
                        <td class="px-4 py-3">
                            <a href="{{ route('clients.show', $client) }}" class="flex items-center gap-3 font-medium text-primary-700 hover:underline dark:text-primary-400">
                                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full text-xs font-semibold {{ $avatarPalette[$client->id % count($avatarPalette)] }}">
                                    {{ $initials($client->name) }}
                                </span>
                                {{ $client->name }}
                            </a>
                        </td>
                        <td class="px-4 py-3 tabular-nums text-slate-500 dark:text-slate-400">{{ $client->ico ?: '—' }}</td>
                        <td class="px-4 py-3">{{ $client->email }}</td>
                        <td class="px-4 py-3">{{ $client->city }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ $client->invoices_count }}</td>
                        <td class="px-4 py-3 text-slate-500 dark:text-slate-400">
                            {{ $client->invoices_max_issue_date?->format('j. n. Y') ?? '—' }}
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-12 text-center">
                            <x-lucide-users class="mx-auto h-10 w-10 text-slate-300 dark:text-slate-600" />
                            <p class="mt-3 text-sm text-slate-500 dark:text-slate-400">
                                {{ $search ? 'Hledání nic nenašlo.' : 'Zatím žádní klienti. Přidejte prvního.' }}
                            </p>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $clients->links() }}</div>
</x-app-layout>
