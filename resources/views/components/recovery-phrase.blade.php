@props(['words', 'title' => 'Záložní fráze — zapište si ji', 'intro' => null])

{{-- Zobrazuje se jen jednou; znovu ji nelze vypsat, jen vygenerovat novou. --}}
<div {{ $attributes->merge(['class' => 'rounded-xl border-2 border-amber-300 bg-amber-50 p-5 dark:border-amber-800 dark:bg-amber-950/40']) }}>
    <div class="flex items-start gap-3">
        <x-lucide-key-round class="mt-0.5 h-5 w-5 shrink-0 text-amber-600 dark:text-amber-400" />
        <div class="min-w-0 flex-1">
            <p class="font-bold text-amber-900 dark:text-amber-200">{{ $title }}</p>
            <p class="mt-1 text-sm text-amber-800 dark:text-amber-300">
                {{ $intro ?? 'Těchto '.count($words).' slov je jediná cesta zpět do účtu, pokud ztratíte heslo i přístup k e-mailu. Zobrazí se pouze teď.' }}
            </p>

            <ol class="mt-4 grid grid-cols-2 gap-1.5 sm:grid-cols-3">
                @foreach ($words as $index => $word)
                    <li class="flex items-baseline gap-2 rounded-lg bg-white/70 px-2.5 py-1.5 dark:bg-black/20">
                        <span class="w-4 shrink-0 text-right text-xs tabular-nums text-amber-600/70 dark:text-amber-500/70">{{ $index + 1 }}</span>
                        <code class="font-mono text-sm font-semibold text-amber-900 dark:text-amber-100">{{ $word }}</code>
                    </li>
                @endforeach
            </ol>

            <div class="mt-4 flex flex-wrap items-center gap-3" x-data="recoveryPhrase(@js(implode(' ', $words)))">
                <button type="button" @click="copy()"
                        class="inline-flex items-center gap-2 rounded-lg bg-amber-600 px-3 py-2 text-sm font-semibold text-white hover:bg-amber-700">
                    <x-lucide-copy class="h-4 w-4" />
                    <span x-show="!copied">Zkopírovat</span>
                    <span x-show="copied" x-cloak>Zkopírováno!</span>
                </button>
                {{-- onclick by CSP zablokovala (inline handler) → přes Alpine --}}
                <button type="button" @click="print()"
                        class="inline-flex items-center gap-2 rounded-lg bg-white px-3 py-2 text-sm font-semibold text-amber-800 ring-1 ring-inset ring-amber-300 hover:bg-amber-50 dark:bg-transparent dark:text-amber-200 dark:ring-amber-700">
                    <x-lucide-printer class="h-4 w-4" /> Vytisknout
                </button>
            </div>

            <p class="mt-4 border-t border-amber-200 pt-3 text-xs text-amber-800/80 dark:border-amber-800 dark:text-amber-400/80">
                <strong>Uložte ji mimo počítač</strong> (papír, trezor, správce hesel). Kdo frázi zná, může převzít účet —
                nikomu ji neposílejte. Novou si můžete kdykoli vygenerovat v Nastavení → Zabezpečení.
            </p>
        </div>
    </div>
</div>
