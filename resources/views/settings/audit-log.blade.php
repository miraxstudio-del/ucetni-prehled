<x-app-layout title="Audit log">
    <div class="mb-6">
        <h1 class="text-2xl font-bold tracking-tight">Nastavení</h1>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">Audit log — kdo, kdy a co v organizaci udělal</p>
    </div>

    <x-settings-tabs />

    <form method="GET" class="mb-4 flex items-end gap-3">
        <div>
            <x-input-label for="akce" value="Kategorie" />
            <select id="akce" name="akce"
                    class="mt-1.5 rounded-lg border border-slate-300 bg-white py-2 pl-3 pr-8 text-sm shadow-sm dark:border-slate-700 dark:bg-slate-800">
                @foreach ($actionGroups as $value => $label)
                    <option value="{{ $value }}" @selected($action === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <x-button variant="secondary">Filtrovat</x-button>
    </form>

    <div class="overflow-x-auto rounded-xl bg-white shadow-sm ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-slate-200 text-left text-xs uppercase tracking-wide text-slate-500 dark:border-slate-800 dark:text-slate-400">
                    <th class="px-4 py-3">Kdy</th>
                    <th class="px-4 py-3">Kdo</th>
                    <th class="px-4 py-3">Akce</th>
                    <th class="px-4 py-3">Detail</th>
                    <th class="px-4 py-3">IP adresa</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                @forelse ($logs as $log)
                    <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/50">
                        <td class="whitespace-nowrap px-4 py-2.5 text-slate-500 dark:text-slate-400">
                            {{ $log->created_at->format('j. n. Y H:i:s') }}
                        </td>
                        <td class="px-4 py-2.5">{{ $log->user?->name ?? '—' }}</td>
                        <td class="px-4 py-2.5"><code class="rounded bg-slate-100 px-1.5 py-0.5 text-xs dark:bg-slate-800">{{ $log->action }}</code></td>
                        <td class="max-w-[18rem] truncate px-4 py-2.5 text-xs text-slate-500 dark:text-slate-400">
                            @if ($log->entity_type){{ class_basename($log->entity_type) }} #{{ $log->entity_id }}@endif
                            @if ($log->meta) {{ json_encode($log->meta, JSON_UNESCAPED_UNICODE) }} @endif
                        </td>
                        <td class="whitespace-nowrap px-4 py-2.5 tabular-nums text-slate-500 dark:text-slate-400">{{ $log->ip }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-10 text-center text-sm text-slate-500 dark:text-slate-400">Žádné záznamy.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $logs->links() }}</div>
</x-app-layout>
