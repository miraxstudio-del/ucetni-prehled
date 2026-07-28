@php
    $initialPreview = [
        'name' => $organization->name,
        'ico' => $organization->ico,
        'dic' => $organization->dic,
        'vatPayer' => $organization->vat_payer,
        'street' => $organization->street,
        'city' => $organization->city,
        'zip' => $organization->zip,
        'email' => $organization->email,
        'registrationNote' => $organization->registration_note,
        'invoiceFooter' => $organization->invoice_footer,
        'template' => $organization->invoice_template?->value ?? 'klasik',
    ];
@endphp

<x-app-layout title="Nastavení fakturace">
    <div class="mb-6">
        <h1 class="text-2xl font-bold tracking-tight">Nastavení</h1>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Fakturační údaje, vzhled dokladů, účty a číselné řady</p>
    </div>

    <x-settings-tabs />

    @if ($errors->any())
        <x-alert type="error" class="mb-6 max-w-6xl">
            @foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach
        </x-alert>
    @endif

    @if ($isOwner)
        <div class="grid gap-6 lg:grid-cols-2"
             x-data="organizationPreview(@js($initialPreview), @js(route('settings.invoicing.preview')))"
             @input.debounce.500ms="refresh()" @change.debounce.500ms="refresh()">

            <div class="space-y-6">
                {{-- Fakturační údaje + vzhled faktury (jeden formulář, jedno uložení) --}}
                <form method="POST" action="{{ route('settings.organization.update') }}"
                      class="space-y-5 rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                    @csrf
                    @method('PUT')

                    <h2 class="font-semibold">Fakturační údaje</h2>

                    <div>
                        <x-input-label for="name" value="Název firmy / jméno podnikatele *" />
                        <x-text-input id="name" name="name" x-model="name" :value="old('name', $organization->name)" required />
                        <x-input-error :messages="$errors->get('name')" />
                    </div>

                    <div class="grid gap-4 sm:grid-cols-3">
                        <div>
                            <x-input-label for="ico" value="IČO" />
                            <x-text-input id="ico" name="ico" x-model="ico" maxlength="8" :value="old('ico', $organization->ico)" />
                            <x-input-error :messages="$errors->get('ico')" />
                        </div>
                        <div>
                            <x-input-label for="dic" value="DIČ" />
                            <x-text-input id="dic" name="dic" x-model="dic" placeholder="CZ12345678" :value="old('dic', $organization->dic)" />
                            <x-input-error :messages="$errors->get('dic')" />
                        </div>
                        <div class="flex items-end pb-2.5">
                            <label class="flex items-center gap-2 text-sm text-slate-600 dark:text-slate-400">
                                <input type="checkbox" name="vat_payer" value="1" x-model="vatPayer" @checked(old('vat_payer', $organization->vat_payer))
                                       class="h-4 w-4 rounded border-slate-300 text-primary-600 focus:ring-primary-500 dark:border-slate-600 dark:bg-slate-800">
                                Plátce DPH
                            </label>
                        </div>
                    </div>

                    <div>
                        <x-input-label for="street" value="Ulice a č. p." />
                        <x-text-input id="street" name="street" x-model="street" :value="old('street', $organization->street)" />
                    </div>

                    <div class="grid gap-4 sm:grid-cols-3">
                        <div class="sm:col-span-2">
                            <x-input-label for="city" value="Město" />
                            <x-text-input id="city" name="city" x-model="city" :value="old('city', $organization->city)" />
                        </div>
                        <div>
                            <x-input-label for="zip" value="PSČ" />
                            <x-text-input id="zip" name="zip" x-model="zip" maxlength="10" :value="old('zip', $organization->zip)" />
                        </div>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-3">
                        <div>
                            <x-input-label for="email" value="E-mail" />
                            <x-text-input id="email" name="email" type="email" x-model="email" :value="old('email', $organization->email)" />
                            <x-input-error :messages="$errors->get('email')" />
                        </div>
                        <div>
                            <x-input-label for="phone" value="Telefon" />
                            <x-text-input id="phone" name="phone" :value="old('phone', $organization->phone)" />
                        </div>
                        <div>
                            <x-input-label for="default_due_days" value="Výchozí splatnost (dny)" />
                            <x-text-input id="default_due_days" name="default_due_days" type="number" min="1" max="365"
                                          :value="old('default_due_days', $organization->default_due_days)" required />
                        </div>
                    </div>

                    <div>
                        <x-input-label for="registration_note" value="Spisová značka / evidence" />
                        <x-text-input id="registration_note" name="registration_note" x-model="registrationNote"
                                      placeholder="např. Fyzická osoba zapsaná v živnostenském rejstříku"
                                      :value="old('registration_note', $organization->registration_note)" />
                        <p class="mt-1 text-xs text-slate-400">Tiskne se na faktury pod údaje dodavatele.</p>
                    </div>

                    <div>
                        <x-input-label for="invoice_footer" value="Text v patičce faktur" />
                        <textarea id="invoice_footer" name="invoice_footer" rows="2" x-model="invoiceFooter"
                                  class="mt-1.5 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm shadow-sm dark:border-slate-700 dark:bg-slate-800">{{ old('invoice_footer', $organization->invoice_footer) }}</textarea>
                    </div>

                    <div class="border-t border-slate-100 pt-5 dark:border-slate-800">
                        <h2 class="font-semibold">Vzhled faktury</h2>
                        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Vyberte, jak budou vypadat vystavené PDF doklady.</p>

                        <div class="mt-4 grid grid-cols-2 gap-3 xl:grid-cols-4">
                            @foreach ($templates as $tpl)
                                <label class="cursor-pointer rounded-xl border-2 p-3 transition"
                                       :class="template === '{{ $tpl->value }}' ? 'border-primary-500 bg-primary-50/50 dark:bg-primary-950/20' : 'border-slate-200 dark:border-slate-700'">
                                    <input type="radio" name="invoice_template" value="{{ $tpl->value }}" x-model="template" class="sr-only">

                                    @if ($tpl->value === 'klasik')
                                        <div class="rounded-lg border border-slate-200 bg-white p-2 dark:border-slate-700 dark:bg-slate-800">
                                            <div class="h-1.5 w-8 rounded bg-primary-600"></div>
                                            <div class="mt-2 flex gap-2">
                                                <div class="h-6 flex-1 space-y-1"><div class="h-1 w-full rounded bg-slate-200 dark:bg-slate-600"></div><div class="h-1 w-2/3 rounded bg-slate-200 dark:bg-slate-600"></div></div>
                                                <div class="h-6 flex-1 space-y-1"><div class="h-1 w-full rounded bg-slate-200 dark:bg-slate-600"></div><div class="h-1 w-2/3 rounded bg-slate-200 dark:bg-slate-600"></div></div>
                                            </div>
                                            <div class="mt-2 h-px w-full bg-slate-200 dark:bg-slate-600"></div>
                                            <div class="mt-1 h-1 w-full rounded bg-slate-100 dark:bg-slate-700"></div>
                                        </div>
                                    @elseif ($tpl->value === 'moderni')
                                        <div class="rounded-lg border border-slate-200 bg-white p-2 dark:border-slate-700 dark:bg-slate-800">
                                            <div class="h-2 w-full rounded bg-primary-600"></div>
                                            <div class="mt-2 flex gap-1.5">
                                                <div class="h-6 flex-1 rounded bg-slate-100 dark:bg-slate-700"></div>
                                                <div class="h-6 flex-1 rounded bg-slate-100 dark:bg-slate-700"></div>
                                            </div>
                                            <div class="mt-1.5 h-1.5 w-full rounded bg-primary-500"></div>
                                            <div class="mt-1 flex justify-end"><div class="h-2 w-1/3 rounded bg-primary-700"></div></div>
                                        </div>
                                    @elseif ($tpl->value === 'minimal')
                                        <div class="rounded-lg border border-slate-200 bg-white p-2 dark:border-slate-700 dark:bg-slate-800">
                                            <div class="h-px w-full bg-slate-900 dark:bg-slate-300"></div>
                                            <div class="mt-2 flex gap-2">
                                                <div class="h-6 flex-1 space-y-1"><div class="h-0.5 w-full rounded bg-slate-200 dark:bg-slate-600"></div><div class="h-0.5 w-2/3 rounded bg-slate-200 dark:bg-slate-600"></div></div>
                                                <div class="h-6 flex-1 space-y-1"><div class="h-0.5 w-full rounded bg-slate-200 dark:bg-slate-600"></div><div class="h-0.5 w-2/3 rounded bg-slate-200 dark:bg-slate-600"></div></div>
                                            </div>
                                            <div class="mt-2 h-px w-full bg-slate-200 dark:bg-slate-600"></div>
                                        </div>
                                    @else
                                        {{-- Tradiční: tučný titulek s číslem, dva sloupce, tabulka a Celkem k platbě --}}
                                        <div class="rounded-lg border border-slate-200 bg-white p-2 dark:border-slate-700 dark:bg-slate-800">
                                            <div class="flex items-center justify-between">
                                                <div class="h-1.5 w-10 rounded bg-slate-800 dark:bg-slate-300"></div>
                                                <div class="h-1.5 w-5 rounded bg-slate-800 dark:bg-slate-300"></div>
                                            </div>
                                            <div class="mt-1 h-px w-full bg-slate-900 dark:bg-slate-300"></div>
                                            <div class="mt-1.5 flex gap-2">
                                                <div class="h-5 flex-1 space-y-1"><div class="h-0.5 w-full rounded bg-slate-200 dark:bg-slate-600"></div><div class="h-0.5 w-3/4 rounded bg-slate-200 dark:bg-slate-600"></div><div class="h-0.5 w-2/3 rounded bg-slate-200 dark:bg-slate-600"></div></div>
                                                <div class="h-5 flex-1 space-y-1"><div class="h-0.5 w-full rounded bg-slate-200 dark:bg-slate-600"></div><div class="h-0.5 w-3/4 rounded bg-slate-200 dark:bg-slate-600"></div><div class="h-0.5 w-2/3 rounded bg-slate-200 dark:bg-slate-600"></div></div>
                                            </div>
                                            <div class="mt-1.5 border-y border-slate-400 py-0.5 dark:border-slate-500"><div class="h-0.5 w-full rounded bg-slate-200 dark:bg-slate-600"></div></div>
                                            <div class="mt-1 flex justify-end"><div class="h-1 w-2/5 rounded bg-slate-800 dark:bg-slate-300"></div></div>
                                        </div>
                                    @endif

                                    <p class="mt-2 text-sm font-medium">{{ $tpl->label() }}</p>
                                    <p class="text-xs text-slate-500 dark:text-slate-400">{{ $tpl->description() }}</p>
                                </label>
                            @endforeach
                        </div>
                        <x-input-error :messages="$errors->get('invoice_template')" class="mt-2" />

                        <x-button name="save_invoice_template" value="1" class="mt-4">
                            <x-lucide-check class="h-4 w-4" />
                            Použít tento vzhled faktury
                        </x-button>
                    </div>

                    <x-button variant="secondary">Uložit ostatní údaje</x-button>
                </form>

                {{-- Logo a razítko --}}
                <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                    <h2 class="font-semibold">Logo a razítko na faktury</h2>
                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">PNG nebo JPG, max 1 MB. Zobrazí se na PDF fakturách.</p>

                    <div class="mt-4 grid gap-6 sm:grid-cols-2">
                        @foreach ([['logo', 'Logo', $organization->logo_path], ['razitko', 'Razítko / podpis', $organization->stamp_path]] as [$type, $label, $path])
                            <div>
                                <p class="text-sm font-medium">{{ $label }}</p>
                                <div class="mt-2 flex h-24 items-center justify-center rounded-lg border border-dashed border-slate-300 bg-slate-50 p-2 dark:border-slate-700 dark:bg-slate-800/50">
                                    @if ($path)
                                        <img src="{{ route('settings.organization.image', $type) }}" alt="{{ $label }}" class="max-h-20 max-w-full object-contain">
                                    @else
                                        <span class="text-xs text-slate-400">Zatím nenahráno</span>
                                    @endif
                                </div>
                                <form method="POST" action="{{ route('settings.organization.image.upload', $type) }}"
                                      enctype="multipart/form-data" class="mt-2 flex gap-2">
                                    @csrf
                                    <input type="file" name="image" accept=".png,.jpg,.jpeg" required
                                           class="block w-full text-xs text-slate-500 file:mr-2 file:rounded-lg file:border-0 file:bg-primary-50 file:px-2.5 file:py-1.5 file:text-xs file:font-medium file:text-primary-700 dark:file:bg-primary-950 dark:file:text-primary-300">
                                    <x-button variant="secondary" class="shrink-0 !px-3 !py-1.5 text-xs">Nahrát</x-button>
                                </form>
                                @if ($path)
                                    <form method="POST" action="{{ route('settings.organization.image.delete', $type) }}" class="mt-1">
                                        @csrf @method('DELETE')
                                        <button class="text-xs text-red-600 hover:underline">Odebrat</button>
                                    </form>
                                @endif
                            </div>
                        @endforeach
                    </div>
                    <x-input-error :messages="$errors->get('image')" class="mt-2" />
                </section>

                @include('settings.partials.bank-accounts')
                @include('settings.partials.number-series')
            </div>

            {{-- Náhled — stejná Blade šablona jako skutečné PDF, jen v prohlížeči.
                 Iframe se vykresluje v přirozené velikosti A4 a zmenšuje transformem
                 na šířku sloupce (viz setupPdfPreviewScaling v app.js) — proto bez
                 posuvníků, vždy celá stránka. --}}
            <aside>
                <div class="sticky top-20 space-y-3">
                    <div class="flex items-center justify-between">
                        <h2 class="text-sm font-semibold text-slate-700 dark:text-slate-300">Náhled faktury</h2>
                        <span class="text-xs text-slate-400">aktualizuje se za chodu</span>
                    </div>
                    <div x-ref="previewWrapper" class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                        <iframe x-ref="previewFrame" title="Náhled faktury" scrolling="no" style="border: 0;"></iframe>
                    </div>
                </div>
            </aside>
        </div>
    @else
        <div class="max-w-3xl space-y-6">
            @include('settings.partials.bank-accounts')
            @include('settings.partials.number-series')
        </div>
    @endif
</x-app-layout>
