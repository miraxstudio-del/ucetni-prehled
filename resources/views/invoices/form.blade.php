@php
    $isVatPayer = $organization->vat_payer || old('direction', $invoice->direction?->value) === 'received';
    $initialItems = old('items') ?? ($invoice->items->count()
        ? $invoice->items->map(fn ($i) => [
            'description' => $i->description,
            'quantity' => rtrim(rtrim((string) $i->quantity, '0'), '.'),
            'unit' => $i->unit,
            'unit_price' => (string) $i->unit_price,
            'vat_rate' => number_format((float) $i->vat_rate, 0),
        ])->values()->all()
        : [['description' => '', 'quantity' => '1', 'unit' => '', 'unit_price' => '', 'vat_rate' => '21']]);

    $previewMeta = [
        'clientId' => old('client_id', $invoice->client_id),
        'bankAccountId' => old('bank_account_id', $invoice->bank_account_id ?? $bankAccounts->firstWhere('is_default', true)?->id),
        'issueDate' => old('issue_date', $invoice->issue_date?->format('Y-m-d')),
        'dueDate' => old('due_date', $invoice->due_date?->format('Y-m-d')),
        'duzp' => old('duzp', $invoice->duzp?->format('Y-m-d')),
        'paymentMethod' => old('payment_method', $invoice->payment_method?->value ?? 'bank_transfer'),
        'variableSymbol' => old('variable_symbol', $invoice->variable_symbol),
        'number' => $invoice->number,
        'note' => old('note', $invoice->note),
        'type' => old('type', $invoice->type?->value ?? 'invoice'),
        'direction' => old('direction', $invoice->direction?->value ?? 'issued'),
    ];
@endphp

<x-app-layout :title="$invoice->exists ? 'Upravit doklad' : 'Nový doklad'">
    <div class="mb-6">
        <h1 class="text-2xl font-bold tracking-tight">{{ $invoice->exists ? 'Upravit doklad' : 'Nový doklad' }}</h1>
        @if ($invoice->exists && $invoice->number)
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ $invoice->number }}</p>
        @endif
    </div>

    @if ($errors->any())
        <x-alert type="error" class="mb-6 max-w-4xl">Formulář obsahuje chyby — zkontrolujte zvýrazněná pole.</x-alert>
    @endif

    <div class="grid gap-6 lg:grid-cols-2"
         x-data="invoiceForm(@js($initialItems), @js($isVatPayer), @js($previewMeta), @js(route('invoices.preview')))"
         @input.debounce.500ms="refresh()" @change.debounce.500ms="refresh()">
    <form method="POST"
          action="{{ $invoice->exists ? route('invoices.update', $invoice) : route('invoices.store') }}"
          class="space-y-6">
        @csrf
        @if ($invoice->exists) @method('PUT') @endif

        <div class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
            <div class="grid gap-4 sm:grid-cols-3">
                <div>
                    <x-input-label for="direction" value="Směr dokladu" />
                    <select id="direction" name="direction" x-model="direction"
                            class="mt-1.5 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm shadow-sm dark:border-slate-700 dark:bg-slate-800"
                            @if ($invoice->exists) disabled @endif>
                        @foreach (App\Enums\InvoiceDirection::cases() as $dir)
                            <option value="{{ $dir->value }}" @selected(old('direction', $invoice->direction?->value ?? 'issued') === $dir->value)>{{ $dir->label() }}</option>
                        @endforeach
                    </select>
                    @if ($invoice->exists)
                        <input type="hidden" name="direction" value="{{ $invoice->direction->value }}">
                    @endif
                </div>
                <div>
                    <x-input-label for="type" value="Typ dokladu" />
                    <select id="type" name="type" x-model="type"
                            class="mt-1.5 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm shadow-sm dark:border-slate-700 dark:bg-slate-800">
                        @foreach (App\Enums\DocumentType::cases() as $type)
                            <option value="{{ $type->value }}" @selected(old('type', $invoice->type?->value ?? 'invoice') === $type->value)>{{ $type->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <x-input-label for="client_id" value="Klient *" />
                    <select id="client_id" name="client_id" x-model="clientId" required
                            class="mt-1.5 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm shadow-sm dark:border-slate-700 dark:bg-slate-800">
                        <option value="">— vyberte —</option>
                        @foreach ($clients as $client)
                            <option value="{{ $client->id }}" @selected((int) old('client_id', $invoice->client_id) === $client->id)>{{ $client->name }}</option>
                        @endforeach
                    </select>
                    <x-input-error :messages="$errors->get('client_id')" />
                    <p class="mt-1 text-xs text-slate-400">Chybí klient? <a href="{{ route('clients.create') }}" class="text-primary-600 hover:underline">Přidat nového</a></p>
                </div>
            </div>

            <div class="mt-4 grid gap-4 sm:grid-cols-4">
                <div>
                    <x-input-label for="issue_date" value="Datum vystavení *" />
                    <x-text-input id="issue_date" name="issue_date" type="date" x-model="issueDate" :value="old('issue_date', $invoice->issue_date?->format('Y-m-d'))" required />
                    <x-input-error :messages="$errors->get('issue_date')" />
                </div>
                <div>
                    <x-input-label for="duzp" value="DUZP" />
                    <x-text-input id="duzp" name="duzp" type="date" x-model="duzp" :value="old('duzp', $invoice->duzp?->format('Y-m-d'))" />
                    <p class="mt-1 text-xs text-slate-400">Nevyplněné = datum vystavení</p>
                </div>
                <div>
                    <x-input-label for="due_date" value="Splatnost *" />
                    <x-text-input id="due_date" name="due_date" type="date" x-model="dueDate" :value="old('due_date', $invoice->due_date?->format('Y-m-d'))" required />
                    <x-input-error :messages="$errors->get('due_date')" />
                </div>
                <div>
                    <x-input-label for="payment_method" value="Způsob úhrady" />
                    <select id="payment_method" name="payment_method" x-model="paymentMethod"
                            class="mt-1.5 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm shadow-sm dark:border-slate-700 dark:bg-slate-800">
                        @foreach (App\Enums\PaymentMethod::cases() as $method)
                            <option value="{{ $method->value }}" @selected(old('payment_method', $invoice->payment_method?->value ?? 'bank_transfer') === $method->value)>{{ $method->label() }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="mt-4 grid gap-4 sm:grid-cols-3">
                <div>
                    <x-input-label for="bank_account_id" value="Bankovní účet" />
                    <select id="bank_account_id" name="bank_account_id" x-model="bankAccountId"
                            class="mt-1.5 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm shadow-sm dark:border-slate-700 dark:bg-slate-800">
                        <option value="">— žádný —</option>
                        @foreach ($bankAccounts as $account)
                            <option value="{{ $account->id }}" @selected((int) old('bank_account_id', $invoice->bank_account_id ?? $bankAccounts->firstWhere('is_default', true)?->id) === $account->id)>
                                {{ $account->name }} ({{ $account->displayNumber() }})
                            </option>
                        @endforeach
                    </select>
                    @if ($bankAccounts->isEmpty())
                        <p class="mt-1 text-xs text-amber-600">Bez účtu nebude na PDF QR platba — <a href="{{ route('settings.invoicing') }}" class="underline">přidat účet</a></p>
                    @endif
                </div>
                <div x-data="{ received: document.getElementById('direction')?.value === 'received' }"
                     x-init="document.getElementById('direction')?.addEventListener('change', e => received = e.target.value === 'received')">
                    <template x-if="received">
                        <div>
                            <x-input-label for="number" value="Číslo dokladu dodavatele" />
                            <x-text-input id="number" name="number" x-model="number" :value="old('number', $invoice->number)" />
                            <x-input-error :messages="$errors->get('number')" />
                        </div>
                    </template>
                </div>
                <div>
                    <x-input-label for="variable_symbol" value="Variabilní symbol" />
                    <x-text-input id="variable_symbol" name="variable_symbol" x-model="variableSymbol" :value="old('variable_symbol', $invoice->variable_symbol)" maxlength="10" inputmode="numeric" />
                    <p class="mt-1 text-xs text-slate-400">U vydaných se doplní z čísla dokladu</p>
                    <x-input-error :messages="$errors->get('variable_symbol')" />
                </div>
            </div>
        </div>

        {{-- Položky --}}
        <div class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
            <h2 class="mb-4 font-semibold">Položky</h2>
            <x-input-error :messages="$errors->get('items')" class="mb-3" />

            <div class="space-y-3">
                <template x-for="(item, index) in items" :key="index">
                    <div class="grid gap-2 rounded-lg border border-slate-200 p-3 dark:border-slate-700 sm:grid-cols-12 sm:items-end">
                        <div class="sm:col-span-5">
                            <label class="text-xs font-medium text-slate-500 dark:text-slate-400">Popis *</label>
                            <input type="text" x-model="item.description" :name="`items[${index}][description]`" required maxlength="500"
                                   class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm shadow-sm dark:border-slate-700 dark:bg-slate-800">
                        </div>
                        <div class="sm:col-span-1">
                            <label class="text-xs font-medium text-slate-500 dark:text-slate-400">Množ.</label>
                            <input type="text" x-model="item.quantity" :name="`items[${index}][quantity]`" inputmode="decimal"
                                   class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-2 py-2 text-sm shadow-sm dark:border-slate-700 dark:bg-slate-800">
                        </div>
                        <div class="sm:col-span-1">
                            <label class="text-xs font-medium text-slate-500 dark:text-slate-400">MJ</label>
                            <input type="text" x-model="item.unit" :name="`items[${index}][unit]`" maxlength="20" placeholder="ks"
                                   class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-2 py-2 text-sm shadow-sm dark:border-slate-700 dark:bg-slate-800">
                        </div>
                        <div class="sm:col-span-2">
                            <label class="text-xs font-medium text-slate-500 dark:text-slate-400">Cena/MJ {{ $isVatPayer ? 'bez DPH' : '' }} *</label>
                            <input type="text" x-model="item.unit_price" :name="`items[${index}][unit_price]`" required inputmode="decimal"
                                   class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-2 py-2 text-right text-sm shadow-sm dark:border-slate-700 dark:bg-slate-800">
                        </div>
                        <div class="sm:col-span-1" x-show="vatPayer">
                            <label class="text-xs font-medium text-slate-500 dark:text-slate-400">DPH</label>
                            <select x-model="item.vat_rate" :name="`items[${index}][vat_rate]`"
                                    class="mt-1 block w-full rounded-lg border border-slate-300 bg-white px-2 py-2 text-sm shadow-sm dark:border-slate-700 dark:bg-slate-800">
                                <option value="21">21 %</option>
                                <option value="12">12 %</option>
                                <option value="0">0 %</option>
                            </select>
                        </div>
                        <template x-if="!vatPayer">
                            <input type="hidden" :name="`items[${index}][vat_rate]`" value="0">
                        </template>
                        <div class="text-right sm:col-span-1">
                            <label class="text-xs font-medium text-slate-500 dark:text-slate-400">Celkem</label>
                            <div class="mt-1 py-2 text-sm font-medium tabular-nums" x-text="formatMoney(lineTotal(item))"></div>
                        </div>
                        <div class="sm:col-span-1 text-right">
                            <button type="button" @click="removeItem(index)" x-show="items.length > 1"
                                    class="rounded-lg p-2 text-slate-400 hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-950">
                                <x-lucide-trash-2 class="h-4 w-4" />
                            </button>
                        </div>
                    </div>
                </template>
            </div>

            <button type="button" @click="addItem()"
                    class="mt-3 inline-flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium text-primary-600 hover:bg-primary-50 dark:hover:bg-primary-950">
                <x-lucide-plus class="h-4 w-4" /> Přidat položku
            </button>

            <div class="mt-4 flex justify-end border-t border-slate-100 pt-4 dark:border-slate-800">
                <dl class="w-64 space-y-1 text-sm">
                    <template x-if="vatPayer">
                        <div>
                            <div class="flex justify-between"><dt class="text-slate-500">Základ</dt><dd class="tabular-nums" x-text="formatMoney(subtotal())"></dd></div>
                            <div class="flex justify-between"><dt class="text-slate-500">DPH</dt><dd class="tabular-nums" x-text="formatMoney(vatTotal())"></dd></div>
                        </div>
                    </template>
                    <div class="flex justify-between text-base font-bold"><dt>Celkem</dt><dd class="tabular-nums" x-text="formatMoney(grandTotal())"></dd></div>
                </dl>
            </div>
        </div>

        <div class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
            <x-input-label for="note" value="Text na doklad (poznámka)" />
            <textarea id="note" name="note" rows="2" x-model="note"
                      class="mt-1.5 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm shadow-sm dark:border-slate-700 dark:bg-slate-800">{{ old('note', $invoice->note) }}</textarea>
        </div>

        <div class="flex items-center gap-3">
            <x-button name="action" value="issue">Vystavit doklad</x-button>
            <x-button name="action" value="draft" variant="secondary">Uložit jako koncept</x-button>
            <a href="{{ $invoice->exists ? route('invoices.show', $invoice) : route('invoices.index') }}"
               class="text-sm font-medium text-slate-500 hover:text-slate-700 dark:text-slate-400">Zrušit</a>
        </div>
    </form>

        {{-- Náhled — stejná Blade šablona jako skutečné PDF, jen v prohlížeči.
             Iframe se vykresluje v přirozené velikosti A4 a zmenšuje transformem
             na šířku sloupce (viz setupPdfPreviewScaling v app.js) — proto bez
             posuvníků, vždy celá stránka. --}}
        <aside>
            <div class="sticky top-20 space-y-3">
                <div class="flex items-center justify-between">
                    <h2 class="text-sm font-semibold text-slate-700 dark:text-slate-300">Náhled dokladu</h2>
                    <span class="text-xs text-slate-400">aktualizuje se za chodu</span>
                </div>
                <div x-ref="previewWrapper" class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                    <iframe x-ref="previewFrame" title="Náhled dokladu" scrolling="no" style="border: 0;"></iframe>
                </div>
            </div>
        </aside>
    </div>
</x-app-layout>
