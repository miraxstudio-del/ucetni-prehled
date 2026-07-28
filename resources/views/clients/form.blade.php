<x-app-layout :title="$client->exists ? 'Upravit klienta' : 'Nový klient'">
    <div class="mb-6">
        <h1 class="text-2xl font-bold tracking-tight">{{ $client->exists ? 'Upravit klienta' : 'Nový klient' }}</h1>
    </div>

    <form method="POST"
          action="{{ $client->exists ? route('clients.update', $client) : route('clients.store') }}"
          class="max-w-2xl space-y-4 rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800"
          x-data="aresForm(@js(old('ico', $client->ico)), @js(url('/ares')))">
        @csrf
        @if ($client->exists) @method('PUT') @endif

        <div class="grid gap-4 sm:grid-cols-3">
            <div>
                <x-input-label for="ico" value="IČO" />
                <div class="flex gap-2">
                    <x-text-input id="ico" name="ico" x-model="ico" :value="old('ico', $client->ico)" maxlength="8" inputmode="numeric" />
                    <button type="button" @click="lookup()" :disabled="loading"
                            class="mt-1.5 shrink-0 rounded-lg bg-slate-100 px-3 text-sm font-medium text-slate-700 ring-1 ring-inset ring-slate-300 hover:bg-slate-200 disabled:opacity-50 dark:bg-slate-800 dark:text-slate-200 dark:ring-slate-700">
                        <span x-show="!loading">Načíst z ARES</span>
                        <span x-show="loading" x-cloak>Načítám…</span>
                    </button>
                </div>
                <p class="mt-1.5 text-xs text-red-600" x-text="error" x-show="error" x-cloak></p>
                <x-input-error :messages="$errors->get('ico')" />
            </div>
            <div class="sm:col-span-2">
                <x-input-label for="name" value="Název / jméno *" />
                <x-text-input id="name" name="name" x-ref="name" :value="old('name', $client->name)" required />
                <x-input-error :messages="$errors->get('name')" />
            </div>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <x-input-label for="dic" value="DIČ" />
                <x-text-input id="dic" name="dic" x-ref="dic" :value="old('dic', $client->dic)" placeholder="CZ12345678" />
                <x-input-error :messages="$errors->get('dic')" />
            </div>
            <div>
                <x-input-label for="street" value="Ulice a č. p." />
                <x-text-input id="street" name="street" x-ref="street" :value="old('street', $client->street)" />
            </div>
        </div>

        <div class="grid gap-4 sm:grid-cols-3">
            <div class="sm:col-span-2">
                <x-input-label for="city" value="Město" />
                <x-text-input id="city" name="city" x-ref="city" :value="old('city', $client->city)" />
            </div>
            <div>
                <x-input-label for="zip" value="PSČ" />
                <x-text-input id="zip" name="zip" x-ref="zip" :value="old('zip', $client->zip)" maxlength="10" />
            </div>
        </div>

        <div class="grid gap-4 sm:grid-cols-3">
            <div>
                <x-input-label for="email" value="E-mail" />
                <x-text-input id="email" name="email" type="email" :value="old('email', $client->email)" />
                <x-input-error :messages="$errors->get('email')" />
            </div>
            <div>
                <x-input-label for="phone" value="Telefon" />
                <x-text-input id="phone" name="phone" :value="old('phone', $client->phone)" />
            </div>
            <div>
                <x-input-label for="due_days" value="Splatnost (dny)" />
                <x-text-input id="due_days" name="due_days" type="number" min="1" max="365"
                              :value="old('due_days', $client->due_days)" placeholder="výchozí organizace" />
            </div>
        </div>

        <div>
            <x-input-label for="note" value="Poznámka" />
            <textarea id="note" name="note" rows="3"
                      class="mt-1.5 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/30 dark:border-slate-700 dark:bg-slate-800">{{ old('note', $client->note) }}</textarea>
        </div>

        <div class="flex items-center gap-3 pt-2">
            <x-button>{{ $client->exists ? 'Uložit změny' : 'Vytvořit klienta' }}</x-button>
            <a href="{{ $client->exists ? route('clients.show', $client) : route('clients.index') }}"
               class="text-sm font-medium text-slate-500 hover:text-slate-700 dark:text-slate-400">Zrušit</a>
        </div>
    </form>
</x-app-layout>
