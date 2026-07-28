<x-app-layout :title="$client->name">
    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div>
            <a href="{{ route('clients.index') }}" class="text-sm text-slate-500 hover:text-slate-700 dark:text-slate-400">← Klienti</a>
            <h1 class="mt-1 text-2xl font-bold tracking-tight">{{ $client->name }}</h1>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                @if ($client->ico) IČO: {{ $client->ico }} @endif
                @if ($client->dic) · DIČ: {{ $client->dic }} @endif
            </p>
        </div>
        <div class="flex gap-2">
            @can('create', App\Models\Invoice::class)
                <a href="{{ route('invoices.create') }}"
                   class="inline-flex items-center gap-2 rounded-lg bg-primary-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-primary-700">
                    <x-lucide-plus class="h-4 w-4" /> Faktura
                </a>
            @endcan
            @can('update', $client)
                <a href="{{ route('clients.edit', $client) }}"
                   class="inline-flex items-center gap-2 rounded-lg bg-white px-3 py-2 text-sm font-semibold text-slate-700 shadow-sm ring-1 ring-inset ring-slate-300 hover:bg-slate-50 dark:bg-slate-800 dark:text-slate-200 dark:ring-slate-700">
                    <x-lucide-pencil class="h-4 w-4" /> Upravit
                </a>
            @endcan
            @can('delete', $client)
                <form method="POST" action="{{ route('clients.destroy', $client) }}"
                      data-confirm="Opravdu smazat klienta {{ $client->name }}?">
                    @csrf @method('DELETE')
                    <button type="submit"
                            class="inline-flex items-center gap-2 rounded-lg bg-white px-3 py-2 text-sm font-semibold text-red-600 shadow-sm ring-1 ring-inset ring-slate-300 hover:bg-red-50 dark:bg-slate-800 dark:ring-slate-700">
                        <x-lucide-trash-2 class="h-4 w-4" /> Smazat
                    </button>
                </form>
            @endcan
        </div>
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6">
            <section class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Fakturační údaje</h2>
                <dl class="mt-3 space-y-2 text-sm">
                    @if ($client->fullAddress())
                        <div><dt class="text-slate-500 dark:text-slate-400">Adresa</dt><dd>{{ $client->fullAddress() }}</dd></div>
                    @endif
                    @if ($client->email)
                        <div><dt class="text-slate-500 dark:text-slate-400">E-mail</dt><dd>{{ $client->email }}</dd></div>
                    @endif
                    @if ($client->phone)
                        <div><dt class="text-slate-500 dark:text-slate-400">Telefon</dt><dd>{{ $client->phone }}</dd></div>
                    @endif
                    <div>
                        <dt class="text-slate-500 dark:text-slate-400">Splatnost</dt>
                        <dd>{{ $client->due_days ? $client->due_days.' dní' : 'výchozí ('.$client->organization->default_due_days.' dní)' }}</dd>
                    </div>
                </dl>
                @if ($client->note)
                    <p class="mt-3 border-t border-slate-100 pt-3 text-sm text-slate-600 dark:border-slate-800 dark:text-slate-300">{{ $client->note }}</p>
                @endif
            </section>

            <section class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Celkový obrat</h2>
                <p class="mt-2 text-2xl font-bold tabular-nums">{{ number_format((float) $totalRevenue, 2, ',', ' ') }} Kč</p>
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Součet vydaných nestornovaných faktur. Platební morálka přibude s modulem Banka (Fáze 3).</p>
            </section>
        </div>

        <section class="lg:col-span-2 rounded-xl bg-white shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
            <h2 class="border-b border-slate-100 px-5 py-4 font-semibold dark:border-slate-800">Historie faktur</h2>
            <table class="w-full text-sm">
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    @forelse ($invoices as $invoice)
                        <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/50">
                            <td class="px-5 py-3">
                                <a href="{{ route('invoices.show', $invoice) }}" class="font-medium text-primary-700 hover:underline dark:text-primary-400">
                                    {{ $invoice->number ?? 'Koncept' }}
                                </a>
                            </td>
                            <td class="px-3 py-3 text-slate-500 dark:text-slate-400">{{ $invoice->issue_date->format('j. n. Y') }}</td>
                            <td class="px-3 py-3">
                                <span class="rounded-full px-2.5 py-1 text-xs font-medium {{ $invoice->isOverdue() ? 'bg-amber-50 text-amber-700 dark:bg-amber-950 dark:text-amber-300' : $invoice->status->badgeClasses() }}">
                                    {{ $invoice->isOverdue() ? 'Po splatnosti' : $invoice->status->label() }}
                                </span>
                            </td>
                            <td class="px-5 py-3 text-right font-medium tabular-nums">{{ number_format((float) $invoice->total, 2, ',', ' ') }} Kč</td>
                        </tr>
                    @empty
                        <tr><td class="px-5 py-10 text-center text-sm text-slate-500 dark:text-slate-400">Zatím žádné faktury.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </section>
    </div>
</x-app-layout>
