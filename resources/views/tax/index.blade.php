@php
    $money = fn ($v) => number_format((float) $v, 0, ',', ' ').' Kč';

    $scenarioJson = collect($scenarios)
        ->map(fn ($s) => ['label' => $s['label'], 'result' => $s['result']->toArray()])
        ->all();

    $initial = [
        'children' => (bool) $profile->claim_children,
        'spouse' => (bool) $profile->claim_spouse_credit,
    ];
@endphp

<x-app-layout title="Daně">
    <div class="mb-6 flex flex-wrap items-start justify-between gap-4">
        <div>
            <div class="flex flex-wrap items-center gap-3">
                <h1 class="text-2xl font-bold tracking-tight">Daně</h1>
                <x-year-badge :year="$year" />
            </div>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                Průběžný výpočet daně z příjmů, sociálního a zdravotního pojištění
            </p>
        </div>

        <form method="GET" action="{{ route('tax.index') }}"
              class="flex items-end gap-2 rounded-xl bg-white p-2 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
            <div>
                <x-input-label for="rok" value="Přepnout rok" />
                {{-- Bez inline onchange — CSP nepovoluje 'unsafe-inline', odesílá se tlačítkem. --}}
                <div class="relative mt-1">
                    <x-lucide-calendar-days class="pointer-events-none absolute left-2.5 top-2.5 h-4 w-4 text-slate-400" />
                    <select id="rok" name="rok"
                            class="rounded-lg border-slate-300 py-2 pl-8 pr-8 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-slate-700 dark:bg-slate-900">
                        @foreach ($years as $y)
                            <option value="{{ $y }}" @selected($y === $year)>{{ $y }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <x-button type="submit" variant="secondary">Zobrazit</x-button>
        </form>
    </div>

    @if (session('status'))
        <x-alert type="success" class="mb-6 max-w-5xl">{{ session('status') }}</x-alert>
    @endif

    @if ($errors->any())
        <x-alert type="error" class="mb-6 max-w-5xl">
            @foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach
        </x-alert>
    @endif

    {{-- Uzavřený rok: závazné je podané přiznání, ne součet faktur --}}
    @if ($usesOverride)
        <x-alert type="warning" class="mb-6 max-w-5xl">
            <p class="font-semibold">Rok {{ $year }} je uzavřený — počítá se z podaného přiznání.</p>
            <p class="mt-1 text-sm">
                Příjmy dle přiznání: <strong>{{ $money($income) }}</strong>.
                Součet faktur v evidenci: <strong>{{ $money($invoiceIncome) }}</strong>.
                @if ($discrepancy !== 0)
                    Rozdíl <strong>{{ $money(abs($discrepancy)) }}</strong> — neobjasněný, závazné je přiznání.
                @endif
            </p>
            @if (! empty($closed['note']))
                <p class="mt-1 text-sm">{{ $closed['note'] }}</p>
            @endif
        </x-alert>
    @endif

    <div x-data="taxScenarios(@js($scenarioJson), @js($initial))" class="max-w-5xl space-y-6">

        {{-- ——— Tři průběžně přepočítávané kolonky ——— --}}
        <div class="grid gap-4 sm:grid-cols-3">
            {{-- Daň z příjmů --}}
            <section class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                <div class="flex items-center gap-2 text-sm font-medium text-slate-500 dark:text-slate-400">
                    <x-lucide-landmark class="h-4 w-4" /> Daň z příjmů
                </div>
                <p class="mt-2 text-2xl font-bold tabular-nums"
                   :class="current.bonus > 0 ? 'text-emerald-600 dark:text-emerald-400' : ''"
                   x-text="current.bonus > 0 ? '+ ' + money(current.bonus) : money(current.taxDue)"></p>
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400"
                   x-text="current.bonus > 0 ? 'daňový bonus — vrátí finanční úřad' : 'k zaplacení finančnímu úřadu'"></p>
            </section>

            {{-- Sociální --}}
            <section class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                <div class="flex items-center gap-2 text-sm font-medium text-slate-500 dark:text-slate-400">
                    <x-lucide-shield class="h-4 w-4" /> Sociální pojištění
                </div>
                <p class="mt-2 text-2xl font-bold tabular-nums" x-text="money(current.social)"></p>
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                    doplatek <span class="font-medium" x-text="money(current.socialDue)"></span>,
                    nová záloha <span class="font-medium" x-text="money(current.socialNextAdvance)"></span>/měs.
                </p>
            </section>

            {{-- Zdravotní --}}
            <section class="rounded-xl bg-white p-5 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                <div class="flex items-center gap-2 text-sm font-medium text-slate-500 dark:text-slate-400">
                    <x-lucide-heart-pulse class="h-4 w-4" /> Zdravotní pojištění
                </div>
                <p class="mt-2 text-2xl font-bold tabular-nums" x-text="money(current.health)"></p>
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                    doplatek <span class="font-medium" x-text="money(current.healthDue)"></span>,
                    nová záloha <span class="font-medium" x-text="money(current.healthNextAdvance)"></span>/měs.
                </p>
            </section>
        </div>

        {{-- ——— Výhled běžícího roku ——— --}}
        @if ($projection !== null)
            @php
                $monthName = fn (?int $m) => $m === null ? null : ['', 'ledna', 'února', 'března', 'dubna', 'května', 'června', 'července', 'srpna', 'září', 'října', 'listopadu', 'prosince'][$m];
            @endphp
            <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <h2 class="font-semibold">Výhled roku {{ $year }}</h2>
                    <span class="text-xs text-slate-400">
                        orientačně z dosavadního tempa — fakturováno {{ $money($projection['ytdIncome']) }},
                        projekce do konce roku {{ $money($projection['projectedIncome']) }}
                    </span>
                </div>

                <ul class="mt-4 space-y-3 text-sm">
                    {{-- Daňový bonus --}}
                    @if ($projection['bonus'] !== null)
                        @php $b = $projection['bonus']; @endphp
                        <li class="flex gap-3">
                            @if ($b['reached'])
                                <span class="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-emerald-50 text-emerald-600 dark:bg-emerald-950 dark:text-emerald-400"><x-lucide-check class="h-3.5 w-3.5" /></span>
                                <span><strong>Nárok na daňový bonus je zajištěn</strong> — příjmy {{ $money($b['ytd']) }} už překročily hranici {{ $money($b['threshold']) }}.</span>
                            @elseif ($b['onTrack'])
                                <span class="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-amber-50 text-amber-600 dark:bg-amber-950 dark:text-amber-400"><x-lucide-clock class="h-3.5 w-3.5" /></span>
                                <span>
                                    <strong>Daňový bonus: na dobré cestě, ale těsně.</strong>
                                    K hranici {{ $money($b['threshold']) }} chybí {{ $money($b['missing']) }}
                                    — při současném tempu ji překročíte během {{ $monthName($b['estimatedMonth']) ?? 'roku' }}.
                                    Hranice je tvrdá: o korunu míň a bonus {{ $money($result->childBenefit) }} propadá celý.
                                </span>
                            @else
                                <span class="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-red-50 text-red-600 dark:bg-red-950 dark:text-red-400"><x-lucide-triangle-alert class="h-3.5 w-3.5" /></span>
                                <span>
                                    <strong>Daňový bonus je ohrožen!</strong>
                                    K hranici {{ $money($b['threshold']) }} chybí {{ $money($b['missing']) }}
                                    a současné tempo na ni nestačí. Do konce roku je potřeba fakturovat
                                    ještě ~{{ $money($b['neededPerMonth']) }} měsíčně, jinak celý bonus
                                    {{ $money($result->childBenefit) }} propadá.
                                </span>
                            @endif
                        </li>
                    @endif

                    {{-- Rozhodná částka — sociální --}}
                    @if ($projection['social'] !== null)
                        @php $s = $projection['social']; @endphp
                        <li class="flex gap-3">
                            @if (! $s['willCross'])
                                <span class="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-emerald-50 text-emerald-600 dark:bg-emerald-950 dark:text-emerald-400"><x-lucide-check class="h-3.5 w-3.5" /></span>
                                <span>
                                    <strong>Sociální pojištění se letos platit nebude</strong> — projekce zisku
                                    {{ $money($s['projectedProfit']) }} zůstává pod rozhodnou částkou {{ $money($s['threshold']) }}.
                                </span>
                            @else
                                <span class="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-amber-50 text-amber-600 dark:bg-amber-950 dark:text-amber-400"><x-lucide-clock class="h-3.5 w-3.5" /></span>
                                <span>
                                    <strong>Zisk letos překročí rozhodnou částku</strong> {{ $money($s['threshold']) }}
                                    ({{ $s['estimatedMonth'] !== null ? 'zhruba během '.$monthName($s['estimatedMonth']) : 'už překročena' }})
                                    — vznikne účast na důchodovém pojištění a sociální se doplatí za celý rok.
                                </span>
                            @endif
                        </li>
                    @endif

                    {{-- Limit DPH --}}
                    @php $v = $projection['vat']; @endphp
                    <li class="flex gap-3">
                        @if (! $v['willCross'])
                            <span class="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-emerald-50 text-emerald-600 dark:bg-emerald-950 dark:text-emerald-400"><x-lucide-check class="h-3.5 w-3.5" /></span>
                            <span>
                                <strong>Limit DPH bez rizika</strong> — projekce obratu {{ $money($v['projected']) }}
                                je hluboko pod hranicí {{ $money($v['limit']) }}.
                            </span>
                        @else
                            <span class="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-red-50 text-red-600 dark:bg-red-950 dark:text-red-400"><x-lucide-triangle-alert class="h-3.5 w-3.5" /></span>
                            <span>
                                <strong>Obrat letos překročí {{ $money($v['limit']) }}</strong>
                                ({{ $v['estimatedMonth'] !== null ? 'zhruba během '.$monthName($v['estimatedMonth']) : 'už překročen' }})
                                — od 1. 1. dalšího roku vznikne povinná registrace k DPH.
                                @if ($v['willCrossImmediate'])
                                    Projekce míří i nad {{ $money($v['immediateLimit']) }} — plátcem byste se stali okamžitě dnem překročení.
                                @endif
                                @if ($v['deferAdvice'])
                                    K překročení dojde až na konci roku — posunutím prosincové fakturace do ledna lze zůstat pod limitem.
                                @endif
                            </span>
                        @endif
                    </li>
                </ul>

                <p class="mt-4 text-xs text-slate-400">
                    Lineární odhad z faktur vystavených do {{ \Illuminate\Support\Carbon::parse($projection['asOf'])->format('j. n. Y') }} —
                    sezónní výkyvy neumí předvídat.
                </p>
            </section>
        @endif

        {{-- ——— Shrnutí roku ——— --}}
        <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
            <h2 class="font-semibold">Jak se k číslům došlo</h2>

            <dl class="mt-4 grid gap-x-8 gap-y-2 text-sm sm:grid-cols-2">
                <div class="flex justify-between border-b border-slate-100 py-1 dark:border-slate-800">
                    <dt class="text-slate-500 dark:text-slate-400">Příjmy</dt>
                    <dd class="font-medium tabular-nums" x-text="money(current.income)"></dd>
                </div>
                <div class="flex justify-between border-b border-slate-100 py-1 dark:border-slate-800">
                    <dt class="text-slate-500 dark:text-slate-400">
                        Výdaje @if ($profile->pausal_percent)(paušál {{ $profile->pausal_percent }} %)@else(skutečné)@endif
                    </dt>
                    <dd class="font-medium tabular-nums" x-text="'− ' + money(current.expenses)"></dd>
                </div>
                <div class="flex justify-between border-b border-slate-100 py-1 dark:border-slate-800">
                    <dt class="text-slate-500 dark:text-slate-400">Základ daně</dt>
                    <dd class="font-medium tabular-nums" x-text="money(current.taxBase)"></dd>
                </div>
                <div class="flex justify-between border-b border-slate-100 py-1 dark:border-slate-800">
                    <dt class="text-slate-500 dark:text-slate-400">Zaokrouhlený základ (stovky dolů)</dt>
                    <dd class="font-medium tabular-nums" x-text="money(current.taxBaseRounded)"></dd>
                </div>
                <div class="flex justify-between border-b border-slate-100 py-1 dark:border-slate-800">
                    <dt class="text-slate-500 dark:text-slate-400">Daň {{ $parameters['rate'] }} %</dt>
                    <dd class="font-medium tabular-nums" x-text="money(current.taxBeforeCredits)"></dd>
                </div>
                <div class="flex justify-between border-b border-slate-100 py-1 dark:border-slate-800">
                    <dt class="text-slate-500 dark:text-slate-400">Sleva na poplatníka</dt>
                    <dd class="font-medium tabular-nums" x-text="'− ' + money(current.taxpayerCredit)"></dd>
                </div>
                <div class="flex justify-between border-b border-slate-100 py-1 dark:border-slate-800">
                    <dt class="text-slate-500 dark:text-slate-400">Sleva na manžela/manželku</dt>
                    <dd class="font-medium tabular-nums" x-text="'− ' + money(current.spouseCredit)"></dd>
                </div>
                <div class="flex justify-between border-b border-slate-100 py-1 dark:border-slate-800">
                    <dt class="text-slate-500 dark:text-slate-400">Zvýhodnění na děti</dt>
                    <dd class="font-medium tabular-nums" x-text="'− ' + money(current.childBenefit)"></dd>
                </div>
                <div class="flex justify-between border-b border-slate-100 py-1 dark:border-slate-800">
                    <dt class="text-slate-500 dark:text-slate-400">Vyměřovací základ sociální ({{ $parameters['social_base_share'] }} %)</dt>
                    <dd class="font-medium tabular-nums" x-text="money(current.socialBase)"></dd>
                </div>
                <div class="flex justify-between border-b border-slate-100 py-1 dark:border-slate-800">
                    <dt class="text-slate-500 dark:text-slate-400">Vyměřovací základ zdravotní ({{ $parameters['health_base_share'] }} %)</dt>
                    <dd class="font-medium tabular-nums" x-text="money(current.healthBase)"></dd>
                </div>
                <div class="flex justify-between border-t-2 border-slate-200 py-2 font-semibold dark:border-slate-700 sm:col-span-2">
                    <dt>Vypořádání roku (daň + pojistné − bonus)</dt>
                    <dd class="tabular-nums"
                        :class="current.netSettlement < 0 ? 'text-emerald-600 dark:text-emerald-400' : ''"
                        x-text="current.netSettlement < 0 ? '+ ' + money(-current.netSettlement) + ' vám přijde' : money(current.netSettlement) + ' zaplatíte'"></dd>
                </div>
            </dl>

            <template x-if="current.notes.length">
                <ul class="mt-4 space-y-1 rounded-lg bg-amber-50 p-3 text-sm text-amber-900 dark:bg-amber-950/40 dark:text-amber-200">
                    <template x-for="note in current.notes" :key="note">
                        <li class="flex gap-2"><span>•</span><span x-text="note"></span></li>
                    </template>
                </ul>
            </template>
        </section>

        {{-- ——— Scénáře slev ——— --}}
        <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
            <h2 class="font-semibold">Scénáře slev</h2>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                Přepínače mění jen tento náhled — nic se neukládá. Uložit se to dá v nastavení níže.
            </p>

            <div class="mt-4 flex flex-wrap gap-6">
                <label class="flex cursor-pointer items-center gap-2 text-sm">
                    <input type="checkbox" x-model="children"
                           class="rounded border-slate-300 text-primary-600 focus:ring-primary-500 dark:border-slate-600 dark:bg-slate-800">
                    Uplatnit zvýhodnění na děti ({{ $profile->children }})
                </label>
                <label class="flex cursor-pointer items-center gap-2 text-sm">
                    <input type="checkbox" x-model="spouse"
                           class="rounded border-slate-300 text-primary-600 focus:ring-primary-500 dark:border-slate-600 dark:bg-slate-800">
                    Uplatnit slevu na manžela/manželku
                </label>
            </div>

            <div class="mt-5 overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-200 text-left text-xs uppercase tracking-wide text-slate-500 dark:border-slate-700 dark:text-slate-400">
                            <th class="py-2 pr-4 font-medium">Kombinace</th>
                            <th class="py-2 pr-4 text-right font-medium">Daň / bonus</th>
                            <th class="py-2 pr-4 text-right font-medium">Sociální</th>
                            <th class="py-2 pr-4 text-right font-medium">Zdravotní</th>
                            <th class="py-2 text-right font-medium">Zbyde vám</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                        @foreach ($scenarios as $key => $s)
                            @php $r = $s['result']; @endphp
                            <tr :class="key === '{{ $key }}' ? 'bg-primary-50/60 dark:bg-primary-950/30' : ''">
                                <td class="py-2 pr-4">
                                    {{ $s['label'] }}
                                    <span x-show="key === '{{ $key }}'"
                                          class="ml-1 rounded bg-primary-100 px-1.5 py-0.5 text-xs font-medium text-primary-700 dark:bg-primary-900 dark:text-primary-300">zvoleno</span>
                                </td>
                                <td class="py-2 pr-4 text-right tabular-nums {{ $r->bonus > 0 ? 'text-emerald-600 dark:text-emerald-400' : '' }}">
                                    {{ $r->bonus > 0 ? '+ '.$money($r->bonus) : $money($r->taxDue) }}
                                </td>
                                <td class="py-2 pr-4 text-right tabular-nums">{{ $money($r->social) }}</td>
                                <td class="py-2 pr-4 text-right tabular-nums">{{ $money($r->health) }}</td>
                                <td class="py-2 text-right font-medium tabular-nums">{{ $money($r->netIncome()) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <p class="mt-4 rounded-lg bg-slate-50 p-3 text-xs text-slate-600 dark:bg-slate-800/60 dark:text-slate-400">
                <strong>Pozor na slevu na manžela/manželku:</strong> od roku 2024 na ni vzniká nárok jen tehdy,
                pokud manžel/ka pečuje o dítě do 3 let věku a jeho/její vlastní příjmy za rok nepřesáhly 68 000 Kč.
                Scénář ji spočítá, ale nárok si ověřte — jinak hrozí doměrek.
            </p>
        </section>

        {{-- ——— Srovnání s paušální daní ——— --}}
        <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
            <h2 class="font-semibold">Vyplatila by se paušální daň?</h2>

            @if (! $flatTax['eligible'])
                <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">{{ $flatTax['reason'] }}</p>
            @else
                @php $better = $flatTax['difference'] > 0; @endphp

                <p class="mt-2 text-sm text-slate-500 dark:text-slate-400">
                    Při příjmu {{ $money($income) }} spadáte do {{ $flatTax['band'] }}. pásma —
                    {{ $money($flatTax['monthly']) }} měsíčně, tedy {{ $money($flatTax['annual']) }} za rok.
                </p>

                <div class="mt-4 overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-slate-200 text-left text-xs uppercase tracking-wide text-slate-500 dark:border-slate-700 dark:text-slate-400">
                                <th class="py-2 pr-4 font-medium">Režim</th>
                                <th class="py-2 pr-4 text-right font-medium">Odvedete za rok</th>
                                <th class="py-2 pr-4 text-right font-medium">Bonus na děti</th>
                                <th class="py-2 text-right font-medium">Zbyde vám</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                            <tr class="{{ $better ? '' : 'bg-emerald-50/60 dark:bg-emerald-950/20' }}">
                                <td class="py-2 pr-4">
                                    Dnešní režim (paušální výdaje)
                                    @unless ($better)
                                        <span class="ml-1 rounded bg-emerald-100 px-1.5 py-0.5 text-xs font-medium text-emerald-700 dark:bg-emerald-900 dark:text-emerald-300">výhodnější</span>
                                    @endunless
                                </td>
                                <td class="py-2 pr-4 text-right tabular-nums">{{ $money($result->taxDue + $result->social + $result->health) }}</td>
                                <td class="py-2 pr-4 text-right tabular-nums text-emerald-600 dark:text-emerald-400">
                                    {{ $result->bonus > 0 ? '+ '.$money($result->bonus) : '—' }}
                                </td>
                                <td class="py-2 text-right font-medium tabular-nums">{{ $money($flatTax['currentNetIncome']) }}</td>
                            </tr>
                            <tr class="{{ $better ? 'bg-emerald-50/60 dark:bg-emerald-950/20' : '' }}">
                                <td class="py-2 pr-4">
                                    Paušální daň ({{ $flatTax['band'] }}. pásmo)
                                    @if ($better)
                                        <span class="ml-1 rounded bg-emerald-100 px-1.5 py-0.5 text-xs font-medium text-emerald-700 dark:bg-emerald-900 dark:text-emerald-300">výhodnější</span>
                                    @endif
                                </td>
                                <td class="py-2 pr-4 text-right tabular-nums">{{ $money($flatTax['annual']) }}</td>
                                <td class="py-2 pr-4 text-right tabular-nums text-red-600 dark:text-red-400">nelze uplatnit</td>
                                <td class="py-2 text-right font-medium tabular-nums">{{ $money($flatTax['netIncome']) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <p class="mt-4 rounded-lg p-3 text-sm {{ $better
                    ? 'bg-emerald-50 text-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-200'
                    : 'bg-red-50 text-red-900 dark:bg-red-950/40 dark:text-red-200' }}">
                    @if ($better)
                        <strong>Paušální daň by byla o {{ $money(abs($flatTax['difference'])) }} za rok výhodnější.</strong>
                        Než přejdete, ověřte podmínky vstupu — režim nejde kombinovat s dalšími příjmy nad limit.
                    @else
                        <strong>Paušální daň by vás stála o {{ $money(abs($flatTax['difference'])) }} za rok víc.</strong>
                        @if ($flatTax['lostBonus'] > 0)
                            Hlavní důvod: v paušálním režimu <strong>nelze uplatnit slevy ani zvýhodnění na děti</strong>,
                            takže byste přišli o daňový bonus {{ $money($flatTax['lostBonus']) }}.
                            Navíc se platí minimální pojistné, i když u vedlejší činnosti dnes platíte míň.
                        @endif
                    @endif
                </p>
            @endif
        </section>

        {{-- ——— Příjmy po měsících ——— --}}
        <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
            <h2 class="font-semibold">Příjmy po měsících {{ $year }}</h2>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                Z vydaných faktur v evidenci (podle data vystavení, bez proforem a storen).
            </p>

            @php
                $maxMonth = max(1, max(array_column($monthly, 'income')));
                $names = ['', 'Leden', 'Únor', 'Březen', 'Duben', 'Květen', 'Červen', 'Červenec', 'Srpen', 'Září', 'Říjen', 'Listopad', 'Prosinec'];
            @endphp

            <div class="mt-4 space-y-1.5">
                @foreach ($monthly as $m => $data)
                    <div class="flex items-center gap-3 text-sm">
                        <span class="w-20 shrink-0 text-slate-500 dark:text-slate-400">{{ $names[$m] }}</span>
                        <div class="h-2 flex-1 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
                            <div class="h-full rounded-full bg-primary-500"
                                 style="width: {{ $data['income'] > 0 ? round($data['income'] / $maxMonth * 100, 2) : 0 }}%"></div>
                        </div>
                        <span class="w-28 shrink-0 text-right tabular-nums">{{ $money($data['income']) }}</span>
                        <span class="hidden w-32 shrink-0 text-right tabular-nums text-slate-400 sm:block">{{ $money($data['running']) }}</span>
                    </div>
                @endforeach
            </div>

            <div class="mt-4 flex justify-between border-t border-slate-200 pt-3 text-sm font-semibold dark:border-slate-700">
                <span>Celkem z faktur</span>
                <span class="tabular-nums">{{ $money($invoiceIncome) }}</span>
            </div>
        </section>

        {{-- ——— Nastavení ——— --}}
        <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
            <h2 class="font-semibold">Nastavení roku {{ $year }}</h2>

            <form method="POST" action="{{ route('tax.update', $year) }}" class="mt-4 space-y-5"
                  x-data="{ mode: '{{ $profile->pausal_percent ? 'pausal' : 'actual' }}' }">
                @csrf
                @method('PUT')

                <div class="grid gap-5 sm:grid-cols-2">
                    <div>
                        <x-input-label for="expense_mode" value="Výdaje" />
                        <select id="expense_mode" name="expense_mode" x-model="mode"
                                class="mt-1 w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-slate-700 dark:bg-slate-900">
                            <option value="pausal">Výdajový paušál</option>
                            <option value="actual">Skutečné výdaje</option>
                        </select>
                    </div>

                    <div x-show="mode === 'pausal'">
                        <x-input-label for="pausal_percent" value="Sazba paušálu" />
                        <select id="pausal_percent" name="pausal_percent"
                                class="mt-1 w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-slate-700 dark:bg-slate-900">
                            @foreach ($parameters['pausal_caps'] as $percent => $cap)
                                <option value="{{ $percent }}" @selected($profile->pausal_percent === $percent)>
                                    {{ $percent }} % (strop {{ $money($cap) }})
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div x-show="mode === 'actual'" x-cloak>
                        <x-input-label for="actual_expenses" value="Skutečné výdaje (Kč)" />
                        <x-text-input id="actual_expenses" name="actual_expenses" type="number" step="0.01" min="0"
                                      class="w-full" :value="old('actual_expenses', $profile->actual_expenses)" />
                    </div>

                    <div>
                        <x-input-label for="children" value="Počet vyživovaných dětí" />
                        <x-text-input id="children" name="children" type="number" min="0" max="20"
                                      class="w-full" :value="old('children', $profile->children)" />
                        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                            Zvýhodnění: {{ implode(' / ', array_map($money, $parameters['child_credits'])) }} na 1./2./3.+ dítě
                        </p>
                    </div>

                    <div>
                        <x-input-label for="social_advances_paid" value="Zaplacené zálohy — sociální (Kč)" />
                        <x-text-input id="social_advances_paid" name="social_advances_paid" type="number" step="0.01" min="0"
                                      class="w-full" :value="old('social_advances_paid', $profile->social_advances_paid)" />
                    </div>

                    <div>
                        <x-input-label for="health_advances_paid" value="Zaplacené zálohy — zdravotní (Kč)" />
                        <x-text-input id="health_advances_paid" name="health_advances_paid" type="number" step="0.01" min="0"
                                      class="w-full" :value="old('health_advances_paid', $profile->health_advances_paid)" />
                    </div>

                    <div class="sm:col-span-2">
                        <x-input-label for="income_override" value="Příjmy dle podaného přiznání (Kč) — nepovinné" />
                        <x-text-input id="income_override" name="income_override" type="number" step="0.01" min="0"
                                      class="w-full" :value="old('income_override', $profile->income_override)" />
                        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                            Vyplňte jen u uzavřeného roku. Má přednost před součtem faktur.
                        </p>
                    </div>
                </div>

                <div class="space-y-3 border-t border-slate-200 pt-4 dark:border-slate-700">
                    <label class="flex items-start gap-3 text-sm">
                        <input type="checkbox" name="secondary_activity" value="1" @checked($profile->secondary_activity)
                               class="mt-0.5 rounded border-slate-300 text-primary-600 focus:ring-primary-500 dark:border-slate-600 dark:bg-slate-800">
                        <span>
                            <span class="font-medium">Vedlejší činnost</span>
                            <span class="block text-xs text-slate-500 dark:text-slate-400">
                                Rodičovská, zaměstnání, studium do 26 let, důchod nebo péče o dítě do 4 let
                                (§ 9 zákona č. 155/1995 Sb.). Sociální se neplatí, pokud zisk nepřesáhne
                                rozhodnou částku {{ $money($parameters['social_secondary_threshold']) }}.
                            </span>
                        </span>
                    </label>

                    <div class="ml-7">
                        <x-input-label for="secondary_activity_until" value="Datum přechodu na hlavní činnost — nepovinné" />
                        <x-text-input id="secondary_activity_until" name="secondary_activity_until" type="date"
                                      class="w-full sm:w-56"
                                      :value="old('secondary_activity_until', optional($profile->secondary_activity_until)->format('Y-m-d'))" />
                        <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                            Vyplňte, jen když vedlejší činnost v roce {{ $year }} skončila (např. konec rodičovské) a
                            zbytek roku je hlavní. Roční rozhodná částka se pak poměrně sníží za měsíce po přechodu —
                            přechod nahlaste ČSSZ do 15 dnů.
                        </p>
                    </div>

                    <label class="flex items-start gap-3 text-sm">
                        <input type="checkbox" name="state_health_payer" value="1" @checked($profile->state_health_payer)
                               class="mt-0.5 rounded border-slate-300 text-primary-600 focus:ring-primary-500 dark:border-slate-600 dark:bg-slate-800">
                        <span>
                            <span class="font-medium">Stát je plátcem zdravotního pojistného</span>
                            <span class="block text-xs text-slate-500 dark:text-slate-400">
                                Celodenní osobní péče alespoň o jedno dítě do 7 let (kategorie L, § 7 zákona
                                č. 48/1997 Sb.) — nezávisí na hlavní/vedlejší činnosti. Od roku 2026 platí i při
                                souběhu s podnikáním jako hlavní činnost. Nahlaste zdravotní pojišťovně, platí ode
                                dne po doručení oznámení.
                            </span>
                        </span>
                    </label>

                    <label class="flex items-start gap-3 text-sm">
                        <input type="checkbox" name="claim_taxpayer_credit" value="1" @checked($profile->claim_taxpayer_credit)
                               class="mt-0.5 rounded border-slate-300 text-primary-600 focus:ring-primary-500 dark:border-slate-600 dark:bg-slate-800">
                        <span class="font-medium">Uplatnit slevu na poplatníka ({{ $money($parameters['taxpayer_credit']) }})</span>
                    </label>

                    <label class="flex items-start gap-3 text-sm">
                        <input type="checkbox" name="claim_children" value="1" @checked($profile->claim_children)
                               class="mt-0.5 rounded border-slate-300 text-primary-600 focus:ring-primary-500 dark:border-slate-600 dark:bg-slate-800">
                        <span class="font-medium">Uplatnit zvýhodnění na děti</span>
                    </label>

                    <label class="flex items-start gap-3 text-sm">
                        <input type="checkbox" name="claim_spouse_credit" value="1" @checked($profile->claim_spouse_credit)
                               class="mt-0.5 rounded border-slate-300 text-primary-600 focus:ring-primary-500 dark:border-slate-600 dark:bg-slate-800">
                        <span class="font-medium">Uplatnit slevu na manžela/manželku ({{ $money($parameters['spouse_credit']) }})</span>
                    </label>
                </div>

                <div>
                    <x-input-label for="note" value="Poznámka" />
                    <textarea id="note" name="note" rows="2"
                              class="mt-1 w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-slate-700 dark:bg-slate-900">{{ old('note', $profile->note) }}</textarea>
                </div>

                <x-button type="submit">Uložit nastavení</x-button>
            </form>
        </section>

        {{-- ——— Zákonné parametry ——— --}}
        <section class="rounded-xl bg-slate-50 p-6 ring-1 ring-slate-200 dark:bg-slate-900/50 dark:ring-slate-800">
            <h2 class="font-semibold">Zákonné parametry {{ $year }}</h2>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                Podle těchto čísel se počítá. Jsou ověřená u zdrojů níže — nic se neodhaduje.
            </p>

            <dl class="mt-4 grid gap-x-8 gap-y-1 text-sm sm:grid-cols-2">
                @foreach ([
                    'Sleva na poplatníka' => $money($parameters['taxpayer_credit']),
                    'Sleva na manžela/manželku' => $money($parameters['spouse_credit']),
                    'Sazba daně' => $parameters['rate'].' % / '.$parameters['rate_high'].' % nad '.$money($parameters['high_rate_threshold']),
                    'Průměrná mzda' => $money($parameters['average_wage']),
                    'Minimální mzda' => $money($parameters['min_wage']),
                    'Min. příjem pro daňový bonus' => $money($parameters['bonus_min_income']),
                    'Sociální — sazba / základ' => $parameters['social_rate'].' % z '.$parameters['social_base_share'].' % zisku',
                    'Rozhodná částka (vedlejší)' => $money($parameters['social_secondary_threshold']),
                    'Zdravotní — sazba / základ' => $parameters['health_rate'].' % z '.$parameters['health_base_share'].' % zisku',
                    'Zaokrouhlení' => 'základ na stovky dolů, pojistné na koruny nahoru',
                ] as $label => $value)
                    <div class="flex justify-between gap-4 border-b border-slate-200 py-1 dark:border-slate-800">
                        <dt class="text-slate-500 dark:text-slate-400">{{ $label }}</dt>
                        <dd class="text-right font-medium">{{ $value }}</dd>
                    </div>
                @endforeach
            </dl>

            <div class="mt-4 text-xs text-slate-500 dark:text-slate-400">
                <span class="font-medium">Zdroje:</span>
                @foreach ($parameters['sources'] as $source)
                    <a href="{{ $source }}" target="_blank" rel="noopener noreferrer"
                       class="ml-1 underline hover:text-primary-600">{{ parse_url($source, PHP_URL_HOST) }}</a>
                @endforeach
            </div>

            <p class="mt-4 rounded-lg bg-amber-50 p-3 text-xs text-amber-900 dark:bg-amber-950/40 dark:text-amber-200">
                Výpočet je orientační pomůcka, ne závazné daňové poradenství. Před podáním přiznání
                a přehledů si čísla ověřte u účetní.
            </p>
        </section>
    </div>
</x-app-layout>
