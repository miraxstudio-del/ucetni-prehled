@php
    $tabs = [
        ['label' => 'Fakturace a firma', 'route' => 'settings.invoicing'],
    ];
@endphp

<div class="mb-6 flex gap-1 rounded-lg bg-slate-100 p-1 dark:bg-slate-800 w-fit">
    @foreach ($tabs as $tab)
        <a href="{{ route($tab['route']) }}"
           @class([
               'rounded-md px-4 py-1.5 text-sm font-medium transition',
               'bg-white text-slate-900 shadow-sm dark:bg-slate-700 dark:text-white' => request()->routeIs($tab['route']),
               'text-slate-600 hover:text-slate-900 dark:text-slate-400' => ! request()->routeIs($tab['route']),
           ])>{{ $tab['label'] }}</a>
    @endforeach
</div>
