@php
    $money = fn ($v) => number_format((float) $v, 2, ',', ' ');
    $canManage = auth()->user()->can('manage', App\Models\BankTransaction::class);
@endphp

<x-app-layout title="Banka">
    <div class="mb-6 flex flex-wrap items-center justify-between gap-4">
        <div class="flex items-center gap-3">
            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-primary-50 text-primary-600 dark:bg-primary-950 dark:text-primary-400">
                <x-lucide-landmark class="h-5 w-5" />
            </span>
            <div>
                <div class="flex flex-wrap items-center gap-3">
                    <h1 class="text-2xl font-bold tracking-tight">Banka</h1>
                    <x-year-badge :year="$year" />
                </div>
                <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                    příjmy <strong class="text-emerald-600 tabular-nums">{{ $money($income) }} Kč</strong>
                    · výdaje <strong class="text-red-600 tabular-nums">{{ $money(abs((float) $expense)) }} Kč</strong>
                </p>
            </div>
        </div>
        @if ($canManage)
            <div class="flex items-center gap-2">
                <form method="POST" action="{{ route('bank.verify-payments') }}">
                    @csrf
                    <input type="hidden" name="rok" value="{{ $year }}">
                    <x-button variant="secondary"><x-lucide-search-check class="h-4 w-4" /> Ověřit platby</x-button>
                </form>
                <form method="POST" action="{{ route('bank.sync') }}">
                    @csrf
                    <x-button><x-lucide-refresh-cw class="h-4 w-4" /> Synchronizovat teď</x-button>
                </form>
            </div>
        @endif
    </div>

    @if ($errors->has('sync'))
        <x-alert type="error" class="mb-6">{{ $errors->first('sync') }}</x-alert>
    @endif

    {{-- Napojení účtů + import/export --}}
    <div class="mb-6 grid gap-4 lg:grid-cols-3">
        <section class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
            <div class="flex items-center justify-between">
                <h2 class="flex items-center gap-2 text-sm font-semibold">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-primary-50 text-primary-600 dark:bg-primary-950 dark:text-primary-400">
                        <x-lucide-lock class="h-5 w-5" />
                    </span>
                    Napojení (Fio)
                    @if ($connections->contains(fn ($c) => $c->status === 'active'))
                        <span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300">
                            <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span> Připojeno
                        </span>
                    @endif
                </h2>
                <x-lucide-shield-check class="h-5 w-5 text-slate-300 dark:text-slate-600" />
            </div>
            <ul class="mt-3 space-y-2 text-sm">
                @forelse ($connections as $connection)
                    <li class="flex items-center gap-2">
                        <span @class([
                            'h-2 w-2 shrink-0 rounded-full',
                            'bg-emerald-500' => $connection->status === 'active',
                            'bg-red-500' => $connection->status !== 'active',
                        ])></span>
                        <span class="min-w-0 flex-1 truncate">
                            {{ $connection->bankAccount?->displayNumber() }}
                            <span class="block text-xs text-slate-400">
                                {{ $connection->last_sync_at ? 'Sync: '.$connection->last_sync_at->format('j. n. H:i') : 'Zatím nesynchronizováno' }}
                                @if ($connection->last_error) · {{ Str::limit($connection->last_error, 60) }} @endif
                            </span>
                        </span>
                        @if ($canManage)
                            <form method="POST" action="{{ route('bank.connections.destroy', $connection) }}"
                                  data-confirm="Odebrat napojení účtu?">
                                @csrf @method('DELETE')
                                <button class="rounded p-1.5 text-slate-400 hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-950">
                                    <x-lucide-trash-2 class="h-4 w-4" />
                                </button>
                            </form>
                        @endif
                    </li>
                @empty
                    <li class="text-slate-500 dark:text-slate-400">Žádný účet není napojen.</li>
                @endforelse
            </ul>

            @if ($canManage)
                <form method="POST" action="{{ route('bank.connections.store') }}" class="mt-4 space-y-3 border-t border-slate-100 pt-3 dark:border-slate-800">
                    @csrf
                    <div>
                        <x-input-label for="fio_account" value="Bankovní účet" class="text-xs" />
                        <select id="fio_account" name="bank_account_id" required
                                class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-2 py-2 text-sm shadow-sm dark:border-slate-700 dark:bg-slate-800">
                            @foreach ($accounts as $account)
                                <option value="{{ $account->id }}">{{ $account->displayNumber() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <x-input-label for="api_token" value="Fio API token (jen pro čtení)" class="text-xs" />
                        <x-password-input id="api_token" name="api_token" required autocomplete="off" />
                        <x-input-error :messages="$errors->get('api_token')" />
                    </div>
                    <x-button class="w-full">Uložit napojení</x-button>
                    <p class="text-xs text-slate-400">Token vytvoříte v internetbankingu Fio (Nastavení → API). Ukládá se šifrovaně.</p>
                </form>
            @endif
        </section>

        @if ($canManage)
            <section class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                <h2 class="flex items-center gap-2 text-sm font-semibold">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-sky-50 text-sky-600 dark:bg-sky-950 dark:text-sky-400">
                        <x-lucide-cloud-upload class="h-5 w-5" />
                    </span>
                    Import výpisu (GPC/ABO)
                </h2>
                <form method="POST" action="{{ route('bank.import') }}" enctype="multipart/form-data" class="mt-3 space-y-3">
                    @csrf
                    <div>
                        <x-input-label for="import_account" value="Bankovní účet" class="text-xs" />
                        <select id="import_account" name="bank_account_id" required
                                class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-2 py-2 text-sm shadow-sm dark:border-slate-700 dark:bg-slate-800">
                            @foreach ($accounts as $account)
                                <option value="{{ $account->id }}">{{ $account->displayNumber() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div x-data="{ fileName: null }">
                        <x-input-label value="Soubor výpisu" class="text-xs" />
                        <label class="mt-1 flex cursor-pointer items-center gap-2 rounded-lg border border-slate-300 bg-white px-2 py-2 text-sm shadow-sm dark:border-slate-700 dark:bg-slate-800">
                            <span class="inline-flex shrink-0 items-center gap-1.5 rounded-md bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-700 dark:bg-slate-700 dark:text-slate-200">
                                <x-lucide-file-up class="h-3.5 w-3.5" /> Vybrat soubor
                            </span>
                            <span class="min-w-0 flex-1 truncate text-slate-400" x-text="fileName ?? 'Soubor nevybrán'"></span>
                            <input type="file" name="file" accept=".gpc,.abo,.txt" required class="sr-only"
                                   @change="fileName = $event.target.files[0]?.name ?? null">
                        </label>
                    </div>
                    <x-input-error :messages="$errors->get('file')" />
                    <x-button class="w-full"><x-lucide-upload class="h-4 w-4" /> Importovat</x-button>
                    <p class="text-xs text-slate-400">Funguje s výpisem z libovolné české banky. Duplicity se přeskakují.</p>
                </form>
            </section>
        @endif

        <section class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
            <h2 class="flex items-center gap-2 text-sm font-semibold">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-primary-50 text-primary-600 dark:bg-primary-950 dark:text-primary-400">
                    <x-lucide-download class="h-5 w-5" />
                </span>
                Export do GPC
            </h2>
            <form method="GET" action="{{ route('bank.export') }}" class="mt-3 space-y-3">
                <div>
                    <x-input-label for="export_account" value="Bankovní účet" class="text-xs" />
                    <select id="export_account" name="bank_account_id" required
                            class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-2 py-2 text-sm shadow-sm dark:border-slate-700 dark:bg-slate-800">
                        @foreach ($accounts as $account)
                            <option value="{{ $account->id }}">{{ $account->displayNumber() }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <x-input-label value="Období" class="text-xs" />
                    <div class="mt-1 flex gap-2">
                        <input type="date" name="od" value="{{ $year }}-01-01" required
                               class="block w-full rounded-lg border border-slate-300 bg-white px-2 py-2 text-sm shadow-sm dark:border-slate-700 dark:bg-slate-800">
                        <input type="date" name="do" value="{{ $year }}-12-31" required
                               class="block w-full rounded-lg border border-slate-300 bg-white px-2 py-2 text-sm shadow-sm dark:border-slate-700 dark:bg-slate-800">
                    </div>
                </div>
                <x-button class="w-full"><x-lucide-download class="h-4 w-4" /> Stáhnout .gpc</x-button>
                <p class="text-xs text-slate-400">Pro účetní — např. celý rok jedním souborem.</p>
            </form>
        </section>
    </div>

    {{-- Filtry --}}
    <form method="GET" class="mb-4 flex flex-wrap items-end gap-3">
        <div class="relative">
            <x-lucide-search class="pointer-events-none absolute left-3 top-2.5 h-4 w-4 text-slate-400" />
            <input type="search" name="q" value="{{ $search }}" placeholder="Protistrana, VS, zpráva…"
                   class="w-64 rounded-lg border border-slate-300 bg-white py-2 pl-9 pr-3 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/30 dark:border-slate-700 dark:bg-slate-800">
        </div>
        <select name="rok" class="rounded-lg border border-slate-300 bg-white py-2 pl-3 pr-8 text-sm shadow-sm dark:border-slate-700 dark:bg-slate-800">
            @foreach (range(now()->year, now()->year - 5) as $y)
                <option value="{{ $y }}" @selected($year === $y)>{{ $y }}</option>
            @endforeach
        </select>
        <select name="typ" class="rounded-lg border border-slate-300 bg-white py-2 pl-3 pr-8 text-sm shadow-sm dark:border-slate-700 dark:bg-slate-800">
            <option value="">Příjmy i výdaje</option>
            <option value="prijmy" @selected($type === 'prijmy')>Jen příjmy</option>
            <option value="vydaje" @selected($type === 'vydaje')>Jen výdaje</option>
        </select>
        <x-button><x-lucide-list-filter class="h-4 w-4" /> Filtrovat</x-button>
    </form>

    {{-- Transakce --}}
    <div class="overflow-x-auto rounded-xl bg-white shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-slate-200 text-left text-xs uppercase tracking-wide text-slate-500 dark:border-slate-800 dark:text-slate-400">
                    <th class="px-4 py-3">Datum</th>
                    <th class="px-4 py-3">Protistrana</th>
                    <th class="px-4 py-3">VS</th>
                    <th class="px-4 py-3">Zpráva</th>
                    <th class="px-4 py-3">Párování</th>
                    <th class="px-4 py-3 text-right">Částka</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                @forelse ($transactions as $transaction)
                    <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/50">
                        <td class="px-4 py-3 text-slate-500 dark:text-slate-400 whitespace-nowrap">
                            <span class="inline-flex items-center gap-1.5">
                                <x-lucide-calendar class="h-3.5 w-3.5 text-slate-300 dark:text-slate-600" />
                                {{ $transaction->booked_on->format('j. n. Y') }}
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            <span class="block max-w-[14rem] truncate">{{ $transaction->counterparty_name ?: '—' }}</span>
                            <span class="text-xs text-slate-400 tabular-nums">{{ $transaction->counterparty_account }}</span>
                        </td>
                        <td class="px-4 py-3 tabular-nums">{{ $transaction->variable_symbol }}</td>
                        <td class="px-4 py-3"><span class="block max-w-[12rem] truncate text-slate-500 dark:text-slate-400">{{ $transaction->message }}</span></td>
                        <td class="px-4 py-3">
                            @if ($transaction->matches->isNotEmpty())
                                @foreach ($transaction->matches as $match)
                                    <span class="inline-flex items-center gap-1">
                                        <a href="{{ $match->invoice ? route('invoices.show', $match->invoice) : '#' }}"
                                           class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700 hover:bg-emerald-100 dark:bg-emerald-950 dark:text-emerald-300">
                                            <x-lucide-link class="h-3 w-3" /> {{ $match->invoice?->number }}
                                        </a>
                                        @if ($canManage)
                                            <form method="POST" action="{{ route('bank.unmatch', $match) }}"
                                                  data-confirm="Zrušit spárování s fakturou {{ $match->invoice?->number }}?">
                                                @csrf @method('DELETE')
                                                <button class="rounded p-0.5 text-slate-400 hover:text-red-600" title="Zrušit spárování">
                                                    <x-lucide-x class="h-3 w-3" />
                                                </button>
                                            </form>
                                        @endif
                                    </span>
                                @endforeach
                            @elseif ($transaction->isCredit() && $canManage && $openInvoices->isNotEmpty())
                                <form method="POST" action="{{ route('bank.match', $transaction) }}" class="flex items-center gap-1">
                                    @csrf
                                    <select name="invoice_id" required
                                            class="rounded-lg border border-slate-300 bg-white py-1 pl-2 pr-7 text-xs shadow-sm dark:border-slate-700 dark:bg-slate-800">
                                        <option value="">Spárovat s…</option>
                                        @foreach ($openInvoices as $invoice)
                                            <option value="{{ $invoice->id }}">
                                                {{ $invoice->number }} ({{ $money($invoice->total) }} Kč)
                                            </option>
                                        @endforeach
                                    </select>
                                    <button class="rounded-lg bg-primary-600 p-1.5 text-white hover:bg-primary-700" title="Spárovat">
                                        <x-lucide-check class="h-3.5 w-3.5" />
                                    </button>
                                </form>
                            @else
                                <span class="text-xs text-slate-400">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right font-medium tabular-nums whitespace-nowrap {{ $transaction->isCredit() ? 'text-emerald-600' : 'text-red-600' }}">
                            {{ $transaction->isCredit() ? '+' : '' }}{{ $money($transaction->amount) }} Kč
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-12 text-center">
                            <x-lucide-landmark class="mx-auto h-10 w-10 text-slate-300 dark:text-slate-600" />
                            <p class="mt-3 text-sm text-slate-500 dark:text-slate-400">
                                Žádné transakce za rok {{ $year }}. Napojte účet přes Fio API, nebo importujte výpis GPC.
                            </p>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $transactions->links() }}</div>
</x-app-layout>
