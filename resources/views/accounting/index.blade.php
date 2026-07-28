<x-app-layout title="Účetnictví">
    <div class="mb-6">
        <h1 class="text-2xl font-bold tracking-tight">Účetnictví</h1>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Exporty a importy pro účetní</p>
    </div>

    @if (session('importErrors'))
        <x-alert type="warning" class="mb-6 max-w-4xl">
            <p class="font-semibold">Některé řádky se nepodařilo naimportovat:</p>
            <ul class="mt-1 list-inside list-disc">
                @foreach (session('importErrors') as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </x-alert>
    @endif

    @if ($errors->any())
        <x-alert type="error" class="mb-6 max-w-4xl">
            @foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach
        </x-alert>
    @endif

    <div class="grid max-w-5xl gap-6 lg:grid-cols-2">
        {{-- ZIP balíček --}}
        <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800 lg:col-span-2">
            <div class="flex items-start gap-4">
                <div class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-primary-50 dark:bg-primary-950">
                    <x-lucide-folder-archive class="h-6 w-6 text-primary-600 dark:text-primary-400" />
                </div>
                <div class="flex-1">
                    <h2 class="font-semibold">Kompletní balíček pro účetní (ZIP)</h2>
                    <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
                        PDF všech dokladů (složky <code class="rounded bg-slate-100 px-1 dark:bg-slate-800">vydane/</code>
                        a <code class="rounded bg-slate-100 px-1 dark:bg-slate-800">prijate/</code>),
                        faktury.csv, transakce.csv, výpisy .gpc, ISDOC soubory a prehled.xlsx s rekapitulací.
                    </p>
                    <form method="GET" action="{{ route('accounting.export.zip') }}" class="mt-4 flex flex-wrap items-end gap-3">
                        <div>
                            <x-input-label for="zip_od" value="Od" />
                            <x-text-input id="zip_od" name="od" type="date" :value="$defaultFrom" required />
                        </div>
                        <div>
                            <x-input-label for="zip_do" value="Do" />
                            <x-text-input id="zip_do" name="do" type="date" :value="$defaultTo" required />
                        </div>
                        <x-button class="mb-px"><x-lucide-download class="h-4 w-4" /> Stáhnout ZIP</x-button>
                    </form>
                </div>
            </div>
        </section>

        {{-- Jednotlivé exporty --}}
        <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
            <h2 class="font-semibold">Jednotlivé exporty</h2>
            <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Vyberte období a formát.</p>

            <form method="GET" class="mt-4 space-y-3" x-data="{ action: '{{ route('accounting.export.invoices-csv') }}' }"
                  x-bind:action="action">
                <div class="flex gap-3">
                    <div class="flex-1">
                        <x-input-label for="exp_od" value="Od" />
                        <x-text-input id="exp_od" name="od" type="date" :value="$defaultFrom" required />
                    </div>
                    <div class="flex-1">
                        <x-input-label for="exp_do" value="Do" />
                        <x-text-input id="exp_do" name="do" type="date" :value="$defaultTo" required />
                    </div>
                </div>
                <div>
                    <x-input-label for="exp_format" value="Formát" />
                    <select id="exp_format" x-model="action"
                            class="mt-1.5 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm shadow-sm dark:border-slate-700 dark:bg-slate-800">
                        <option value="{{ route('accounting.export.invoices-csv') }}">Faktury — CSV</option>
                        <option value="{{ route('accounting.export.transactions-csv') }}">Transakce — CSV</option>
                        <option value="{{ route('accounting.export.xlsx') }}">Přehled — XLSX</option>
                        <option value="{{ route('accounting.export.pohoda') }}">Pohoda XML</option>
                        <option value="{{ route('accounting.export.money') }}">Money S3 XML</option>
                    </select>
                </div>
                <x-button variant="secondary"><x-lucide-download class="h-4 w-4" /> Exportovat</x-button>
                <p class="text-xs text-slate-400">GPC výpisy najdete v sekci Banka, ISDOC na detailu faktury.</p>
            </form>
        </section>

        {{-- Importy --}}
        <section class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
            <h2 class="font-semibold">Importy (migrace z Excelu)</h2>

            @can('create', App\Models\Client::class)
                <div class="mt-4 space-y-5">
                    <form method="POST" action="{{ route('accounting.import.clients') }}" enctype="multipart/form-data" class="space-y-2">
                        @csrf
                        <div class="flex items-center justify-between">
                            <x-input-label value="Klienti z CSV" />
                            <a href="{{ route('accounting.template.clients') }}" class="text-xs font-medium text-primary-600 hover:underline">
                                Stáhnout šablonu
                            </a>
                        </div>
                        <div class="flex gap-2">
                            <input type="file" name="file" accept=".csv,.txt" required
                                   class="block w-full text-sm text-slate-500 file:mr-3 file:rounded-lg file:border-0 file:bg-primary-50 file:px-3 file:py-2 file:text-sm file:font-medium file:text-primary-700 dark:file:bg-primary-950 dark:file:text-primary-300">
                            <x-button variant="secondary" class="shrink-0">Importovat</x-button>
                        </div>
                    </form>

                    <form method="POST" action="{{ route('accounting.import.invoices') }}" enctype="multipart/form-data" class="space-y-2 border-t border-slate-100 pt-4 dark:border-slate-800">
                        @csrf
                        <div class="flex items-center justify-between">
                            <x-input-label value="Faktury z CSV" />
                            <a href="{{ route('accounting.template.invoices') }}" class="text-xs font-medium text-primary-600 hover:underline">
                                Stáhnout šablonu
                            </a>
                        </div>
                        <div class="flex gap-2">
                            <input type="file" name="file" accept=".csv,.txt" required
                                   class="block w-full text-sm text-slate-500 file:mr-3 file:rounded-lg file:border-0 file:bg-primary-50 file:px-3 file:py-2 file:text-sm file:font-medium file:text-primary-700 dark:file:bg-primary-950 dark:file:text-primary-300">
                            <x-button variant="secondary" class="shrink-0">Importovat</x-button>
                        </div>
                    </form>

                    <form method="POST" action="{{ route('accounting.import.isdoc') }}" enctype="multipart/form-data" class="space-y-2 border-t border-slate-100 pt-4 dark:border-slate-800">
                        @csrf
                        <x-input-label value="Přijatá faktura z ISDOC" />
                        <div class="flex gap-2">
                            <input type="file" name="file" accept=".isdoc,.xml" required
                                   class="block w-full text-sm text-slate-500 file:mr-3 file:rounded-lg file:border-0 file:bg-primary-50 file:px-3 file:py-2 file:text-sm file:font-medium file:text-primary-700 dark:file:bg-primary-950 dark:file:text-primary-300">
                            <x-button variant="secondary" class="shrink-0">Importovat</x-button>
                        </div>
                        <x-input-error :messages="$errors->get('file')" />
                    </form>
                </div>
            @else
                <p class="mt-3 text-sm text-slate-500 dark:text-slate-400">
                    Importy jsou dostupné jen rolím s právem zápisu — role účetní má čtení a exporty.
                </p>
            @endcan
        </section>
    </div>
</x-app-layout>
