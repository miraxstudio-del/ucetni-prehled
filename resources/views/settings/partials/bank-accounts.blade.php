{{-- Bankovní účty --}}
<section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
    <h2 class="font-semibold">Bankovní účty</h2>
    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Účet se tiskne na faktury a používá se pro QR platbu.</p>

    <ul class="mt-4 divide-y divide-slate-100 dark:divide-slate-800">
        @forelse ($accounts as $account)
            <li class="flex items-center gap-4 py-3">
                <x-lucide-landmark class="h-5 w-5 shrink-0 text-slate-400" />
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-medium">{{ $account->name }}
                        @if ($account->is_default)
                            <span class="ml-2 rounded-full bg-primary-50 px-2 py-0.5 text-xs font-medium text-primary-700 dark:bg-primary-950 dark:text-primary-300">Výchozí</span>
                        @endif
                    </p>
                    <p class="text-xs text-slate-500 dark:text-slate-400 tabular-nums">{{ $account->displayNumber() }} · {{ $account->iban }}</p>
                </div>
                @unless ($account->is_default)
                    <form method="POST" action="{{ route('settings.accounts.default', $account) }}">
                        @csrf
                        <button type="submit" class="text-xs font-medium text-primary-600 hover:underline">Nastavit výchozí</button>
                    </form>
                @endunless
                <form method="POST" action="{{ route('settings.accounts.destroy', $account) }}"
                      data-confirm="Odebrat účet {{ $account->displayNumber() }}?">
                    @csrf @method('DELETE')
                    <button type="submit" class="rounded-lg p-2 text-slate-400 hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-950">
                        <x-lucide-trash-2 class="h-4 w-4" />
                    </button>
                </form>
            </li>
        @empty
            <li class="py-3 text-sm text-slate-500 dark:text-slate-400">Zatím žádný účet.</li>
        @endforelse
    </ul>

    <form method="POST" action="{{ route('settings.accounts.store') }}" class="mt-4 flex flex-wrap items-end gap-3 border-t border-slate-100 pt-4 dark:border-slate-800">
        @csrf
        <div>
            <x-input-label for="account_name" value="Název účtu" />
            <x-text-input id="account_name" name="name" placeholder="Běžný účet" required />
            <x-input-error :messages="$errors->get('name')" />
        </div>
        <div>
            <x-input-label for="account" value="Číslo účtu" />
            <x-text-input id="account" name="account" placeholder="123-1234567890/0100" required />
            <x-input-error :messages="$errors->get('account')" />
        </div>
        <x-button variant="secondary" class="mb-px"><x-lucide-plus class="h-4 w-4" /> Přidat účet</x-button>
    </form>
</section>
