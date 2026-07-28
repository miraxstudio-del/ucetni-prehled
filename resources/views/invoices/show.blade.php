@php
    $money = fn ($v) => number_format((float) $v, 2, ',', ' ');
    $vatPayer = $invoice->organization->vat_payer;
@endphp

<x-app-layout :title="$invoice->number ?? 'Koncept'">
    @php $forceDeleteExpected = $invoice->number ?? 'KONCEPT'; @endphp
    <div x-data="forceDeleteInvoice(@js($forceDeleteExpected), @js($errors->has('confirm_number')))">
    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div>
            <a href="{{ route('invoices.index', ['smer' => $invoice->direction->value]) }}"
               class="text-sm text-slate-500 hover:text-slate-700 dark:text-slate-400">← Faktury</a>
            <div class="mt-1 flex items-center gap-3">
                <h1 class="text-2xl font-bold tracking-tight">{{ $invoice->type->label() }} {{ $invoice->number ?? '(koncept)' }}</h1>
                <span class="rounded-full px-2.5 py-1 text-xs font-medium {{ $invoice->isOverdue() ? 'bg-amber-50 text-amber-700 dark:bg-amber-950 dark:text-amber-300' : $invoice->status->badgeClasses() }}">
                    {{ $invoice->isOverdue() ? 'Po splatnosti' : $invoice->status->label() }}
                </span>
            </div>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                {{ $invoice->direction->label() }} · {{ $invoice->client?->name }}
            </p>
        </div>

        <div class="flex flex-wrap gap-2">
            <a href="{{ route('invoices.pdf', $invoice) }}" target="_blank"
               class="inline-flex items-center gap-2 rounded-lg bg-white px-3 py-2 text-sm font-semibold text-slate-700 shadow-sm ring-1 ring-inset ring-slate-300 hover:bg-slate-50 dark:bg-slate-800 dark:text-slate-200 dark:ring-slate-700">
                <x-lucide-file-down class="h-4 w-4" /> PDF
            </a>
            @if ($invoice->number)
                <a href="{{ route('invoices.isdoc', $invoice) }}"
                   class="inline-flex items-center gap-2 rounded-lg bg-white px-3 py-2 text-sm font-semibold text-slate-700 shadow-sm ring-1 ring-inset ring-slate-300 hover:bg-slate-50 dark:bg-slate-800 dark:text-slate-200 dark:ring-slate-700">
                    <x-lucide-file-code class="h-4 w-4" /> ISDOC
                </a>
            @endif
            @can('update', $invoice)
                <a href="{{ route('invoices.edit', $invoice) }}"
                   class="inline-flex items-center gap-2 rounded-lg bg-white px-3 py-2 text-sm font-semibold text-slate-700 shadow-sm ring-1 ring-inset ring-slate-300 hover:bg-slate-50 dark:bg-slate-800 dark:text-slate-200 dark:ring-slate-700">
                    <x-lucide-pencil class="h-4 w-4" /> Upravit
                </a>
            @endcan
            @can('issue', $invoice)
                <form method="POST" action="{{ route('invoices.issue', $invoice) }}">
                    @csrf
                    <x-button><x-lucide-badge-check class="h-4 w-4" /> Vystavit</x-button>
                </form>
            @endcan
            @can('markPaid', $invoice)
                <form method="POST" action="{{ route('invoices.paid', $invoice) }}">
                    @csrf
                    <x-button variant="secondary"><x-lucide-circle-check class="h-4 w-4" /> Zaplaceno</x-button>
                </form>
            @endcan
            @can('duplicate', $invoice)
                <form method="POST" action="{{ route('invoices.duplicate', $invoice) }}">
                    @csrf
                    <x-button variant="secondary"><x-lucide-copy class="h-4 w-4" /> Duplikovat</x-button>
                </form>
            @endcan
            @can('delete', $invoice)
                <form method="POST" action="{{ route('invoices.destroy', $invoice) }}"
                      data-confirm="Opravdu smazat tento koncept?">
                    @csrf @method('DELETE')
                    <x-button variant="danger"><x-lucide-trash-2 class="h-4 w-4" /> Smazat</x-button>
                </form>
            @elsecan('cancel', $invoice)
                <form method="POST" action="{{ route('invoices.cancel', $invoice) }}"
                      data-confirm="Opravdu stornovat doklad {{ $invoice->number }}? Storno nelze vzít zpět.">
                    @csrf
                    <x-button variant="danger"><x-lucide-ban class="h-4 w-4" /> Storno</x-button>
                </form>
            @endcan
            @can('forceDelete', $invoice)
                <button type="button" @click="open = ! open"
                        class="inline-flex items-center gap-2 rounded-lg bg-white px-3 py-2 text-sm font-semibold text-red-600 shadow-sm ring-1 ring-inset ring-red-200 hover:bg-red-50 dark:bg-slate-800 dark:ring-red-900 dark:hover:bg-red-950/40">
                    <x-lucide-trash-2 class="h-4 w-4" /> Smazat úplně
                </button>
            @endcan
        </div>
    </div>

    @can('forceDelete', $invoice)
        <div x-show="open" x-cloak x-transition
             class="mb-6 max-w-4xl rounded-xl border border-red-200 bg-red-50 p-4 dark:border-red-900 dark:bg-red-950/30">
            <p class="flex items-start gap-2 text-sm text-red-800 dark:text-red-200">
                <x-lucide-triangle-alert class="mt-0.5 h-4 w-4 shrink-0" />
                <span>
                    Tohle fakturu nevratně smaže z databáze — nejde vzít zpět a
                    @if ($invoice->number)
                        v číselné řadě vznikne díra po dokladu <strong>{{ $invoice->number }}</strong>.
                        @if ($invoice->status !== \App\Enums\InvoiceStatus::Cancelled)
                            Pro doklad, který zůstává platný, zvaž místo toho storno.
                        @endif
                    @else
                        koncept zmizí i s položkami.
                    @endif
                    Pro potvrzení opiš přesně <strong>{{ $forceDeleteExpected }}</strong>.
                </span>
            </p>
            <form method="POST" action="{{ route('invoices.force-destroy', $invoice) }}" class="mt-3 flex flex-wrap gap-2">
                @csrf @method('DELETE')
                <input type="text" name="confirm_number" x-model="value" autocomplete="off"
                       placeholder="{{ $forceDeleteExpected }}"
                       class="flex-1 rounded-lg border border-red-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-red-500 focus:outline-none focus:ring-2 focus:ring-red-500/30 dark:border-red-800 dark:bg-slate-900">
                <button type="submit" :disabled="! matches"
                        :class="matches ? 'bg-red-600 hover:bg-red-700 cursor-pointer' : 'bg-red-300 cursor-not-allowed dark:bg-red-900'"
                        class="rounded-lg px-4 py-2 text-sm font-semibold text-white transition">
                    Smazat trvale
                </button>
            </form>
            <x-input-error :messages="$errors->get('confirm_number')" class="mt-2" />
        </div>
    @endcan

    @if ($errors->any() && ! $errors->has('confirm_number'))
        <x-alert type="error" class="mb-6 max-w-4xl">
            @foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach
        </x-alert>
    @endif

    <div class="grid max-w-5xl gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            {{-- Položky --}}
            <section class="overflow-x-auto rounded-xl bg-white shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-200 text-left text-xs uppercase tracking-wide text-slate-500 dark:border-slate-800 dark:text-slate-400">
                            <th class="px-4 py-3">Popis</th>
                            <th class="px-4 py-3 text-right">Množství</th>
                            <th class="px-4 py-3 text-right">Cena/MJ</th>
                            @if ($vatPayer)<th class="px-4 py-3 text-right">DPH</th>@endif
                            <th class="px-4 py-3 text-right">Celkem</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        @foreach ($invoice->items as $item)
                            <tr>
                                <td class="px-4 py-3">{{ $item->description }}</td>
                                <td class="px-4 py-3 text-right tabular-nums">{{ rtrim(rtrim(number_format((float) $item->quantity, 3, ',', ' '), '0'), ',') }} {{ $item->unit }}</td>
                                <td class="px-4 py-3 text-right tabular-nums">{{ $money($item->unit_price) }}</td>
                                @if ($vatPayer)<td class="px-4 py-3 text-right tabular-nums">{{ number_format((float) $item->vat_rate, 0) }} %</td>@endif
                                <td class="px-4 py-3 text-right font-medium tabular-nums">{{ $money($item->line_total) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        @if ($vatPayer)
                            <tr class="border-t border-slate-200 dark:border-slate-800">
                                <td colspan="{{ $vatPayer ? 4 : 3 }}" class="px-4 py-2 text-right text-slate-500">Základ daně</td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ $money($invoice->subtotal) }} Kč</td>
                            </tr>
                            @foreach ($invoice->vatBreakdown() as $rate => $amounts)
                                <tr>
                                    <td colspan="4" class="px-4 py-2 text-right text-slate-500">DPH {{ number_format((float) $rate, 0) }} %</td>
                                    <td class="px-4 py-2 text-right tabular-nums">{{ $money($amounts['vat']) }} Kč</td>
                                </tr>
                            @endforeach
                        @endif
                        <tr class="border-t-2 border-slate-900 dark:border-slate-100">
                            <td colspan="{{ $vatPayer ? 4 : 3 }}" class="px-4 py-3 text-right text-base font-bold">Celkem</td>
                            <td class="px-4 py-3 text-right text-base font-bold tabular-nums">{{ $money($invoice->total) }} Kč</td>
                        </tr>
                    </tfoot>
                </table>
            </section>

            {{-- Odeslání e-mailem --}}
            @can('send', $invoice)
                <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                    <h2 class="font-semibold">Odeslat e-mailem</h2>
                    <form method="POST" action="{{ route('invoices.send', $invoice) }}" class="mt-4 space-y-3">
                        @csrf
                        <div class="grid gap-3 sm:grid-cols-2">
                            <div>
                                <x-input-label for="to" value="E-mail příjemce" />
                                <x-text-input id="to" name="to" type="email" :value="old('to', $invoice->client?->email)" required />
                                <x-input-error :messages="$errors->get('to')" />
                            </div>
                        </div>
                        <div>
                            <x-input-label for="message" value="Zpráva (nepovinné — jinak výchozí text)" />
                            <textarea id="message" name="message" rows="2"
                                      class="mt-1.5 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm shadow-sm dark:border-slate-700 dark:bg-slate-800">{{ old('message') }}</textarea>
                        </div>
                        <x-button><x-lucide-send class="h-4 w-4" /> Odeslat s PDF přílohou</x-button>
                    </form>

                    @if ($invoice->emailLogs->isNotEmpty())
                        <ul class="mt-4 space-y-1 border-t border-slate-100 pt-3 text-xs text-slate-500 dark:border-slate-800 dark:text-slate-400">
                            @foreach ($invoice->emailLogs as $log)
                                <li>
                                    {{ $log->created_at->format('j. n. Y H:i') }} → {{ $log->to }}
                                    <span class="{{ $log->status === 'sent' ? 'text-emerald-600' : 'text-red-600' }}">
                                        {{ $log->status === 'sent' ? 'odesláno' : 'chyba' }}
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>
            @endcan
        </div>

        {{-- Metadata --}}
        <section class="h-fit rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Údaje dokladu</h2>
            <dl class="mt-3 space-y-2.5 text-sm">
                <div class="flex justify-between"><dt class="text-slate-500 dark:text-slate-400">Vystaveno</dt><dd>{{ $invoice->issue_date->format('j. n. Y') }}</dd></div>
                @if ($vatPayer && $invoice->duzp)
                    <div class="flex justify-between"><dt class="text-slate-500 dark:text-slate-400">DUZP</dt><dd>{{ $invoice->duzp->format('j. n. Y') }}</dd></div>
                @endif
                <div class="flex justify-between"><dt class="text-slate-500 dark:text-slate-400">Splatnost</dt><dd>{{ $invoice->due_date->format('j. n. Y') }}</dd></div>
                @if ($invoice->variable_symbol)
                    <div class="flex justify-between"><dt class="text-slate-500 dark:text-slate-400">VS</dt><dd class="tabular-nums">{{ $invoice->variable_symbol }}</dd></div>
                @endif
                <div class="flex justify-between"><dt class="text-slate-500 dark:text-slate-400">Úhrada</dt><dd>{{ $invoice->payment_method->label() }}</dd></div>
                @if ($invoice->bankAccount)
                    <div class="flex justify-between"><dt class="text-slate-500 dark:text-slate-400">Účet</dt><dd class="tabular-nums">{{ $invoice->bankAccount->displayNumber() }}</dd></div>
                @endif
                @if ($invoice->paid_at)
                    <div class="flex justify-between"><dt class="text-slate-500 dark:text-slate-400">Zaplaceno</dt><dd>{{ $invoice->paid_at->format('j. n. Y') }}</dd></div>
                @endif
                @if ($invoice->cancelled_at)
                    <div class="flex justify-between"><dt class="text-slate-500 dark:text-slate-400">Stornováno</dt><dd>{{ $invoice->cancelled_at->format('j. n. Y H:i') }}</dd></div>
                @endif
            </dl>
            @if ($invoice->note)
                <p class="mt-3 border-t border-slate-100 pt-3 text-sm text-slate-600 dark:border-slate-800 dark:text-slate-300">{{ $invoice->note }}</p>
            @endif
        </section>
    </div>
    </div>
</x-app-layout>
