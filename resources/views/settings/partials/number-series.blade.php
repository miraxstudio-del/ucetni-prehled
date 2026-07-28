{{-- Číselné řady --}}
<section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
    <h2 class="font-semibold">Číselné řady</h2>
    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
        Formát s tokeny <code class="rounded bg-slate-100 px-1 dark:bg-slate-800">{YYYY}</code>,
        <code class="rounded bg-slate-100 px-1 dark:bg-slate-800">{YY}</code> a
        <code class="rounded bg-slate-100 px-1 dark:bg-slate-800">{NNNN}</code> (počet N = počet míst).
        Pokud řada chybí, založí se při prvním vystavení automaticky.
    </p>
    <x-input-error :messages="$errors->get('series')" class="mt-2" />

    <table class="mt-4 w-full text-sm">
        <thead>
            <tr class="border-b border-slate-200 text-left text-xs uppercase tracking-wide text-slate-500 dark:border-slate-800 dark:text-slate-400">
                <th class="py-2 pr-3">Typ</th>
                <th class="py-2 pr-3">Název</th>
                <th class="py-2 pr-3">Formát</th>
                <th class="py-2 pr-3">Rok</th>
                <th class="py-2 pr-3">Další číslo</th>
                <th class="py-2"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
            @forelse ($series as $item)
                <tr>
                    <td class="py-2.5 pr-3">{{ $item->document_type->label() }}</td>
                    <td class="py-2.5 pr-3">{{ $item->name }}</td>
                    <td class="py-2.5 pr-3 font-mono text-xs">{{ $item->format }}</td>
                    <td class="py-2.5 pr-3 tabular-nums">{{ $item->year }}</td>
                    <td class="py-2.5 pr-3 tabular-nums">{{ $item->preview() }}</td>
                    <td class="py-2.5 text-right">
                        <form method="POST" action="{{ route('settings.series.destroy', $item) }}"
                              data-confirm="Smazat řadu {{ $item->name }}?">
                            @csrf @method('DELETE')
                            <button type="submit" class="rounded-lg p-1.5 text-slate-400 hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-950">
                                <x-lucide-trash-2 class="h-4 w-4" />
                            </button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="py-4 text-sm text-slate-500 dark:text-slate-400">Zatím žádná řada — vytvoří se automaticky, nebo přidejte vlastní.</td></tr>
            @endforelse
        </tbody>
    </table>

    <form method="POST" action="{{ route('settings.series.store') }}" class="mt-4 grid gap-3 border-t border-slate-100 pt-4 dark:border-slate-800 sm:grid-cols-5 sm:items-end">
        @csrf
        <div>
            <x-input-label for="document_type" value="Typ" />
            <select id="document_type" name="document_type"
                    class="mt-1.5 block w-full rounded-lg border border-slate-300 bg-white px-2 py-2 text-sm shadow-sm dark:border-slate-700 dark:bg-slate-800">
                @foreach (App\Enums\DocumentType::cases() as $type)
                    <option value="{{ $type->value }}">{{ $type->label() }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <x-input-label for="series_name" value="Název" />
            <x-text-input id="series_name" name="name" placeholder="Hlavní" required />
        </div>
        <div>
            <x-input-label for="format" value="Formát" />
            <x-text-input id="format" name="format" value="{YYYY}-{NNNN}" required />
            <x-input-error :messages="$errors->get('format')" />
        </div>
        <div>
            <x-input-label for="year" value="Rok" />
            <x-text-input id="year" name="year" type="number" :value="now()->year" required />
        </div>
        <div class="flex items-end gap-2">
            <div class="flex-1">
                <x-input-label for="next_number" value="Od čísla" />
                <x-text-input id="next_number" name="next_number" type="number" value="1" min="1" required />
            </div>
            <x-button variant="secondary" class="mb-px shrink-0 !px-3" title="Přidat číselnou řadu" aria-label="Přidat číselnou řadu">
                <x-lucide-plus class="h-4 w-4" />
                <span class="hidden lg:inline">Přidat</span>
            </x-button>
        </div>
    </form>
</section>
