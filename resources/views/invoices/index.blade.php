@php
    $money = fn ($v) => number_format((float) $v, 2, ',', ' ');
    // Trvalé smazání i vystavené/zaplacené faktury smí jen vlastník — obchází
    // storno a dělá díru v číselné řadě.
    $canForceDelete = app(\App\Support\OrganizationContext::class)->role() === \App\Enums\Role::Owner;
@endphp

<x-app-layout title="Faktury">
    <div class="mb-6 flex flex-wrap items-center justify-between gap-4">
        <div>
            <div class="flex flex-wrap items-center gap-3">
                <h1 class="text-2xl font-bold tracking-tight">Faktury</h1>
                @if ($year)
                    <x-year-badge :year="$year" />
                @endif
            </div>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                Součet za výběr: <strong class="tabular-nums">{{ $money($sumTotal) }} Kč</strong>
            </p>
        </div>
        @can('create', App\Models\Invoice::class)
            <a href="{{ route('invoices.create', ['smer' => $direction->value]) }}"
               class="inline-flex items-center gap-2 rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-primary-700">
                <x-lucide-plus class="h-4 w-4" /> {{ $direction === App\Enums\InvoiceDirection::Received ? 'Nová přijatá faktura' : 'Nová faktura' }}
            </a>
        @endcan
    </div>

    {{-- Statistiky — rozpad podle stavu, nezávislý na filtru Stav --}}
    <div class="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
            <div class="flex items-center gap-3">
                <span class="flex h-10 w-10 items-center justify-center rounded-full bg-primary-50 text-primary-600 dark:bg-primary-950 dark:text-primary-400">
                    <x-lucide-file-text class="h-5 w-5" />
                </span>
                <span class="text-sm font-medium text-slate-500 dark:text-slate-400">Celkem faktur</span>
            </div>
            <p class="mt-3 text-2xl font-bold tabular-nums">{{ $money($stats['total']->sum) }} Kč</p>
            <p class="mt-1 text-xs text-slate-400">{{ $stats['total']->count }} faktur</p>
        </div>
        <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
            <div class="flex items-center gap-3">
                <span class="flex h-10 w-10 items-center justify-center rounded-full bg-red-50 text-red-600 dark:bg-red-950 dark:text-red-400">
                    <x-lucide-triangle-alert class="h-5 w-5" />
                </span>
                <span class="text-sm font-medium text-slate-500 dark:text-slate-400">Po splatnosti</span>
            </div>
            <p class="mt-3 text-2xl font-bold tabular-nums">{{ $money($stats['overdue']->sum) }} Kč</p>
            <p class="mt-1 text-xs text-slate-400">{{ $stats['overdue']->count }} faktur</p>
        </div>
        <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
            <div class="flex items-center gap-3">
                <span class="flex h-10 w-10 items-center justify-center rounded-full bg-amber-50 text-amber-600 dark:bg-amber-950 dark:text-amber-400">
                    <x-lucide-clock class="h-5 w-5" />
                </span>
                <span class="text-sm font-medium text-slate-500 dark:text-slate-400">Neuhrazené</span>
            </div>
            <p class="mt-3 text-2xl font-bold tabular-nums">{{ $money($stats['unpaid']->sum) }} Kč</p>
            <p class="mt-1 text-xs text-slate-400">{{ $stats['unpaid']->count }} faktur</p>
        </div>
        <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
            <div class="flex items-center gap-3">
                <span class="flex h-10 w-10 items-center justify-center rounded-full bg-emerald-50 text-emerald-600 dark:bg-emerald-950 dark:text-emerald-400">
                    <x-lucide-circle-check class="h-5 w-5" />
                </span>
                <span class="text-sm font-medium text-slate-500 dark:text-slate-400">Zaplaceno</span>
            </div>
            <p class="mt-3 text-2xl font-bold tabular-nums">{{ $money($stats['paid']->sum) }} Kč</p>
            <p class="mt-1 text-xs text-slate-400">{{ $stats['paid']->count }} faktur</p>
        </div>
    </div>

    {{-- Přepínač vydané / přijaté --}}
    <div class="mb-4 flex gap-1 rounded-lg bg-slate-100 p-1 dark:bg-slate-800 w-fit">
        @foreach (App\Enums\InvoiceDirection::cases() as $dir)
            <a href="{{ route('invoices.index', ['smer' => $dir->value]) }}"
               @class([
                   'rounded-md px-4 py-1.5 text-sm font-medium transition',
                   'bg-white text-slate-900 shadow-sm dark:bg-slate-700 dark:text-white' => $direction === $dir,
                   'text-slate-600 hover:text-slate-900 dark:text-slate-400' => $direction !== $dir,
               ])>{{ $dir->label() }}</a>
        @endforeach
    </div>

    {{-- Filtry --}}
    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
        <form method="GET" class="flex flex-wrap items-end gap-3">
            <input type="hidden" name="smer" value="{{ $direction->value }}">
            <div class="relative">
                <x-lucide-search class="pointer-events-none absolute left-3 top-2.5 h-4 w-4 text-slate-400" />
                <input type="search" name="q" value="{{ $search }}" placeholder="Číslo, VS, klient…"
                       class="w-64 rounded-lg border border-slate-300 bg-white py-2 pl-9 pr-3 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/30 dark:border-slate-700 dark:bg-slate-800">
            </div>
            <select name="stav" class="rounded-lg border border-slate-300 bg-white py-2 pl-3 pr-8 text-sm shadow-sm dark:border-slate-700 dark:bg-slate-800">
                <option value="">Všechny stavy</option>
                @foreach (App\Enums\InvoiceStatus::cases() as $s)
                    <option value="{{ $s->value }}" @selected($status === $s)>{{ $s->label() }}</option>
                @endforeach
            </select>
            <select name="rok" class="rounded-lg border border-slate-300 bg-white py-2 pl-3 pr-8 text-sm shadow-sm dark:border-slate-700 dark:bg-slate-800">
                <option value="">Všechny roky</option>
                @foreach (range(now()->year, now()->year - 5) as $y)
                    <option value="{{ $y }}" @selected($year === $y)>{{ $y }}</option>
                @endforeach
            </select>
            <x-button variant="secondary">Filtrovat</x-button>
            @if ($search || $status || $year)
                <a href="{{ route('invoices.index', ['smer' => $direction->value]) }}"
                   class="inline-flex items-center gap-1.5 rounded-lg px-3 py-2 text-sm font-medium text-slate-500 hover:bg-slate-100 dark:text-slate-400 dark:hover:bg-slate-800">
                    <x-lucide-rotate-ccw class="h-4 w-4" /> Resetovat
                </a>
            @endif
        </form>

        <div class="flex items-center gap-2">
            @can('manage', App\Models\BankTransaction::class)
                <form method="POST" action="{{ route('bank.verify-payments') }}">
                    @csrf
                    @if ($year) <input type="hidden" name="rok" value="{{ $year }}"> @endif
                    <button type="submit"
                            class="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 shadow-sm hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200 dark:hover:bg-slate-700"
                            title="Zkontrolovat neuhrazené faktury proti bankovním transakcím">
                        <x-lucide-search-check class="h-4 w-4" /> Ověřit platby
                    </button>
                </form>
            @endcan
            <a href="{{ route('accounting.index') }}"
               class="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 shadow-sm hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200 dark:hover:bg-slate-700"
               title="Export faktur pro účetní">
                <x-lucide-download class="h-4 w-4" /> Export
            </a>
        </div>
    </div>

    <div x-data="{
            selected: [],
            rows: @js($invoices->getCollection()->map(fn ($i) => ['id' => $i->id, 'total' => (float) $i->total])->values()),
            get sum() { return this.rows.filter(r => this.selected.includes(r.id)).reduce((s, r) => s + r.total, 0); },
            toggleAll(checked) { this.selected = checked ? this.rows.map(r => r.id) : []; },
         }">
        @if (count($invoices))
            <div x-show="selected.length > 0" x-cloak
                 class="mb-3 flex items-center gap-3 rounded-lg bg-primary-50 px-4 py-2 text-sm text-primary-800 dark:bg-primary-950/50 dark:text-primary-200">
                <span><strong x-text="selected.length"></strong> vybráno</span>
                <span class="text-primary-400">·</span>
                <span>součet <strong x-text="sum.toLocaleString('cs-CZ', {minimumFractionDigits: 2, maximumFractionDigits: 2})"></strong> Kč</span>
            </div>
        @endif

        <div class="overflow-x-auto rounded-xl bg-white shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-200 text-left text-xs uppercase tracking-wide text-slate-500 dark:border-slate-800 dark:text-slate-400">
                        <th class="w-10 px-4 py-3">
                            <input type="checkbox" @change="toggleAll($event.target.checked)"
                                   class="h-4 w-4 rounded border-slate-300 text-primary-600 focus:ring-primary-500 dark:border-slate-600 dark:bg-slate-800">
                        </th>
                        <th class="px-4 py-3">Číslo</th>
                        <th class="px-4 py-3">{{ $direction === App\Enums\InvoiceDirection::Received ? 'Dodavatel' : 'Odběratel' }}</th>
                        <th class="px-4 py-3">Vystaveno</th>
                        <th class="px-4 py-3">Splatnost</th>
                        <th class="px-4 py-3">Stav</th>
                        <th class="px-4 py-3 text-right">Částka</th>
                        <th class="px-4 py-3"><span class="sr-only">Akce</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    @forelse ($invoices as $invoice)
                        <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/50">
                            <td class="px-4 py-3">
                                <input type="checkbox" value="{{ $invoice->id }}" x-model.number="selected"
                                       class="h-4 w-4 rounded border-slate-300 text-primary-600 focus:ring-primary-500 dark:border-slate-600 dark:bg-slate-800">
                            </td>
                            <td class="px-4 py-3">
                                <a href="{{ route('invoices.show', $invoice) }}" class="font-medium text-primary-700 hover:underline dark:text-primary-400">
                                    {{ $invoice->number ?? 'Koncept' }}
                                </a>
                                @if ($invoice->type !== App\Enums\DocumentType::Invoice)
                                    <span class="ml-1 text-xs text-slate-400">{{ $invoice->type->label() }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-3">{{ $invoice->client?->name }}</td>
                            <td class="px-4 py-3 text-slate-500 dark:text-slate-400">{{ $invoice->issue_date->format('j. n. Y') }}</td>
                            <td class="px-4 py-3 text-slate-500 dark:text-slate-400">{{ $invoice->due_date->format('j. n. Y') }}</td>
                            <td class="px-4 py-3">
                                <span class="rounded-full px-2.5 py-1 text-xs font-medium {{ $invoice->isOverdue() ? 'bg-amber-50 text-amber-700 dark:bg-amber-950 dark:text-amber-300' : $invoice->status->badgeClasses() }}">
                                    {{ $invoice->isOverdue() ? 'Po splatnosti' : $invoice->status->label() }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-right font-medium tabular-nums">{{ $money($invoice->total) }} Kč</td>
                            <td class="px-4 py-3">
                                <div class="flex items-center justify-end gap-1">
                                    @can('markPaid', $invoice)
                                        <form method="POST" action="{{ route('invoices.paid', $invoice) }}">
                                            @csrf
                                            <button type="submit" title="Označit jako zaplaceno"
                                                    class="rounded-lg p-1.5 text-slate-400 hover:bg-emerald-50 hover:text-emerald-600 dark:hover:bg-emerald-950">
                                                <x-lucide-circle-check class="h-4 w-4" />
                                            </button>
                                        </form>
                                    @endcan
                                    @if ($canForceDelete)
                                        <form method="POST" action="{{ route('invoices.force-destroy', $invoice) }}"
                                              data-confirm="Trvale smazat fakturu {{ $invoice->number ?? 'koncept' }} ({{ $money($invoice->total) }} Kč)? Vytvoří to díru v číselné řadě a nejde to vzít zpět.">
                                            @csrf @method('DELETE')
                                            {{-- server ověří číslo znovu; z tabulky se posílá bez opisování, ať jde smazat rychle --}}
                                            <input type="hidden" name="confirm_number" value="{{ $invoice->number ?? 'KONCEPT' }}">
                                            <button type="submit" title="Smazat úplně"
                                                    class="rounded-lg p-1.5 text-slate-400 hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-950">
                                                <x-lucide-trash-2 class="h-4 w-4" />
                                            </button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-4 py-12 text-center">
                                <x-lucide-file-text class="mx-auto h-10 w-10 text-slate-300 dark:text-slate-600" />
                                <p class="mt-3 text-sm text-slate-500 dark:text-slate-400">Žádné faktury neodpovídají výběru.</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
        {{-- Bez inline onchange — CSP nepovoluje 'unsafe-inline', odesílá se přes Alpine @change. --}}
        <form method="GET" x-data class="flex items-center gap-2 text-sm text-slate-500 dark:text-slate-400">
            <input type="hidden" name="smer" value="{{ $direction->value }}">
            @if ($search) <input type="hidden" name="q" value="{{ $search }}"> @endif
            @if ($status) <input type="hidden" name="stav" value="{{ $status->value }}"> @endif
            @if ($year) <input type="hidden" name="rok" value="{{ $year }}"> @endif
            <select name="na_stranku" @change="$el.form.submit()"
                    class="rounded-lg border border-slate-300 bg-white py-1.5 pl-2 pr-7 text-sm shadow-sm dark:border-slate-700 dark:bg-slate-800">
                @foreach ([10, 25, 50, 100] as $size)
                    <option value="{{ $size }}" @selected($perPage === $size)>{{ $size }}</option>
                @endforeach
            </select>
            <span>z {{ $invoices->total() }} faktur</span>
        </form>

        {{ $invoices->links() }}
    </div>
</x-app-layout>
