@php
    $colorClasses = [
        'primary' => 'bg-primary-50 text-primary-600 dark:bg-primary-950 dark:text-primary-400',
        'emerald' => 'bg-emerald-50 text-emerald-600 dark:bg-emerald-950 dark:text-emerald-400',
        'amber' => 'bg-amber-50 text-amber-600 dark:bg-amber-950 dark:text-amber-400',
        'red' => 'bg-red-50 text-red-600 dark:bg-red-950 dark:text-red-400',
    ];
@endphp

<x-app-layout title="Přehled">
    <div class="mb-8">
        <h1 class="text-2xl font-bold tracking-tight">Přehled</h1>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ $organization->name }}</p>
    </div>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ($cards as $card)
            <div class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                <div class="flex items-center justify-between">
                    <span class="flex h-10 w-10 items-center justify-center rounded-full {{ $colorClasses[$card['color']] }}">
                        <x-dynamic-component :component="$card['icon']" class="h-5 w-5" />
                    </span>
                </div>
                <p class="mt-3 text-sm font-medium text-slate-500 dark:text-slate-400">{{ $card['label'] }}</p>
                <p class="mt-1 text-2xl font-bold tabular-nums">{{ number_format((float) $card['value'], 0, ',', ' ') }}&nbsp;Kč</p>
                <p class="mt-2 text-xs">
                    @if ($card['trend'])
                        <span @class([
                            'inline-flex items-center gap-1 font-medium',
                            'text-emerald-600 dark:text-emerald-400' => $card['trend']['percent'] >= 0,
                            'text-red-600 dark:text-red-400' => $card['trend']['percent'] < 0,
                        ])>
                            @if ($card['trend']['percent'] >= 0)
                                <x-lucide-trending-up class="h-3.5 w-3.5" />
                            @else
                                <x-lucide-trending-down class="h-3.5 w-3.5" />
                            @endif
                            {{ abs($card['trend']['percent']) }}&nbsp;%
                        </span>
                        <span class="text-slate-400">{{ $card['trend']['label'] }}</span>
                    @else
                        <span class="text-slate-400">— beze změny</span>
                    @endif
                </p>
            </div>
        @endforeach
    </div>

    <div class="mt-6 grid gap-6 lg:grid-cols-2">
        <div class="space-y-6">
            {{-- Příjmy po měsících --}}
            <div class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                <div class="flex items-center justify-between">
                    <h2 class="font-semibold">Příjmy po měsících <span class="text-sm font-normal text-slate-400">(zaplacené faktury)</span></h2>
                    {{-- Bez inline onchange — CSP nepovoluje 'unsafe-inline', odesílá se přes Alpine @change. --}}
                    <form method="GET" x-data>
                        <select name="rok" @change="$el.form.submit()"
                                class="rounded-lg border border-slate-300 bg-white py-1.5 pl-2 pr-7 text-sm shadow-sm dark:border-slate-700 dark:bg-slate-800">
                            @foreach (range(now()->year, now()->year - 4) as $y)
                                <option value="{{ $y }}" @selected($chartYear === $y)>{{ $y === now()->year ? 'Tento rok' : $y }}</option>
                            @endforeach
                        </select>
                    </form>
                </div>

                @php $maxIncome = max(1, ...array_column($monthlyIncome, 'value')); @endphp
                @if (array_sum(array_column($monthlyIncome, 'value')) == 0)
                    <div class="mt-6 flex h-48 flex-col items-center justify-center text-center">
                        <x-lucide-chart-column class="h-10 w-10 text-slate-300 dark:text-slate-600" />
                        <p class="mt-3 text-sm text-slate-500 dark:text-slate-400">Graf se vykreslí po první zaplacené faktuře.</p>
                    </div>
                @else
                    <div class="mt-8 flex h-48 items-end gap-1.5">
                        @foreach ($monthlyIncome as $month)
                            <div class="group flex h-full flex-1 flex-col items-center justify-end gap-1"
                                 title="{{ $month['label'] }}: {{ number_format($month['value'], 0, ',', ' ') }} Kč">
                                <span class="text-[10px] tabular-nums text-slate-500 dark:text-slate-400">
                                    @if ($month['value'] > 0){{ number_format($month['value'] / 1000, 0, ',', ' ') }}k @endif
                                </span>
                                <div class="w-full rounded-t bg-primary-500/80 transition group-hover:bg-primary-600 dark:bg-primary-600/70"
                                     style="height: {{ max(2, round($month['value'] / $maxIncome * 100)) }}%"></div>
                                <span class="text-[10px] text-slate-400">{{ $month['label'] }}</span>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

            {{-- Poslední transakce --}}
            <div class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                <div class="flex items-center justify-between">
                    <h2 class="font-semibold">Poslední transakce</h2>
                    <a href="{{ route('bank.index') }}" class="text-sm font-medium text-primary-600 hover:underline dark:text-primary-400">Zobrazit všechny →</a>
                </div>
                <ul class="mt-3 divide-y divide-slate-100 dark:divide-slate-800">
                    @forelse ($recentTransactions as $transaction)
                        <li class="flex items-center gap-3 py-2.5 text-sm">
                            <span @class([
                                'flex h-7 w-7 shrink-0 items-center justify-center rounded-full',
                                'bg-emerald-50 text-emerald-600 dark:bg-emerald-950 dark:text-emerald-400' => $transaction->isCredit(),
                                'bg-red-50 text-red-600 dark:bg-red-950 dark:text-red-400' => ! $transaction->isCredit(),
                            ])>
                                <x-dynamic-component :component="$transaction->isCredit() ? 'lucide-arrow-down' : 'lucide-arrow-up'" class="h-3.5 w-3.5" />
                            </span>
                            <span class="min-w-0 flex-1 truncate">{{ $transaction->counterparty_name ?: $transaction->message ?: 'Transakce' }}</span>
                            <span class="text-xs text-slate-400 whitespace-nowrap">{{ $transaction->booked_on->format('j. n.') }}</span>
                            <span class="font-medium tabular-nums whitespace-nowrap {{ $transaction->isCredit() ? 'text-emerald-600' : 'text-red-600' }}">
                                {{ $transaction->isCredit() ? '+' : '' }}{{ number_format((float) $transaction->amount, 0, ',', ' ') }} Kč
                            </span>
                        </li>
                    @empty
                        <li class="py-2 text-sm text-slate-500 dark:text-slate-400">Zatím žádné transakce — napojte banku.</li>
                    @endforelse
                </ul>
            </div>
        </div>

        <div class="space-y-6">
            {{-- Poslední faktury --}}
            <div class="rounded-xl bg-white shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                <div class="flex items-center justify-between px-6 pt-5">
                    <h2 class="font-semibold">Poslední faktury</h2>
                    <a href="{{ route('invoices.index') }}" class="text-sm font-medium text-primary-600 hover:underline dark:text-primary-400">Zobrazit všechny →</a>
                </div>
                @if ($recentInvoices->isEmpty())
                    <div class="flex h-48 flex-col items-center justify-center text-center">
                        <x-lucide-file-plus class="h-10 w-10 text-slate-300 dark:text-slate-600" />
                        <p class="mt-3 text-sm text-slate-500 dark:text-slate-400">Zatím žádné faktury.</p>
                        @can('create', App\Models\Invoice::class)
                            <a href="{{ route('invoices.create') }}" class="mt-3 text-sm font-medium text-primary-600 hover:underline">+ Vystavit první fakturu</a>
                        @endcan
                    </div>
                @else
                    <ul class="mt-3 divide-y divide-slate-100 px-6 pb-4 dark:divide-slate-800">
                        @foreach ($recentInvoices as $invoice)
                            <li class="flex items-center gap-3 py-2.5">
                                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary-50 text-primary-600 dark:bg-primary-950 dark:text-primary-400">
                                    <x-lucide-file-text class="h-4 w-4" />
                                </span>
                                <div class="min-w-0 flex-1">
                                    <a href="{{ route('invoices.show', $invoice) }}" class="text-sm font-medium text-primary-700 hover:underline dark:text-primary-400">
                                        {{ $invoice->number ?? 'Koncept' }}
                                    </a>
                                    <p class="truncate text-xs text-slate-500 dark:text-slate-400">{{ $invoice->client?->name }}</p>
                                </div>
                                <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $invoice->isOverdue() ? 'bg-amber-50 text-amber-700 dark:bg-amber-950 dark:text-amber-300' : $invoice->status->badgeClasses() }}">
                                    {{ $invoice->isOverdue() ? 'Po splatnosti' : $invoice->status->label() }}
                                </span>
                                <span class="text-sm font-medium tabular-nums">{{ number_format((float) $invoice->total, 0, ',', ' ') }} Kč</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            {{-- Rychlé akce --}}
            <div class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                <h2 class="font-semibold">Rychlé akce</h2>
                <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
                    @can('create', App\Models\Invoice::class)
                        <a href="{{ route('invoices.create') }}"
                           class="flex flex-col items-center gap-2 rounded-lg border border-slate-200 p-3 text-center text-xs font-medium text-slate-600 transition hover:border-primary-300 hover:bg-primary-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-primary-950/40">
                            <x-lucide-file-plus class="h-5 w-5 text-primary-600 dark:text-primary-400" />
                            Nová faktura
                        </a>
                    @endcan
                    @can('create', App\Models\Client::class)
                        <a href="{{ route('clients.create') }}"
                           class="flex flex-col items-center gap-2 rounded-lg border border-slate-200 p-3 text-center text-xs font-medium text-slate-600 transition hover:border-primary-300 hover:bg-primary-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-primary-950/40">
                            <x-lucide-user-plus class="h-5 w-5 text-primary-600 dark:text-primary-400" />
                            Nový klient
                        </a>
                    @endcan
                    <a href="{{ route('bank.index') }}"
                       class="flex flex-col items-center gap-2 rounded-lg border border-slate-200 p-3 text-center text-xs font-medium text-slate-600 transition hover:border-primary-300 hover:bg-primary-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-primary-950/40">
                        <x-lucide-arrow-down-to-line class="h-5 w-5 text-primary-600 dark:text-primary-400" />
                        Přijatá platba
                    </a>
                    @can('create', App\Models\Invoice::class)
                        <a href="{{ route('invoices.create', ['smer' => 'received']) }}"
                           class="flex flex-col items-center gap-2 rounded-lg border border-slate-200 p-3 text-center text-xs font-medium text-slate-600 transition hover:border-primary-300 hover:bg-primary-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-primary-950/40">
                            <x-lucide-arrow-up-from-line class="h-5 w-5 text-primary-600 dark:text-primary-400" />
                            Výdaj
                        </a>
                    @endcan
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
