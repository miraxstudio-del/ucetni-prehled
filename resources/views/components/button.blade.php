@props(['variant' => 'primary', 'type' => 'submit'])

@php
    $classes = match ($variant) {
        'primary' => 'bg-primary-600 text-white shadow-sm hover:bg-primary-700 focus-visible:outline-primary-600',
        'secondary' => 'bg-white text-slate-700 shadow-sm ring-1 ring-inset ring-slate-300 hover:bg-slate-50
                        dark:bg-slate-800 dark:text-slate-200 dark:ring-slate-700 dark:hover:bg-slate-700',
        'danger' => 'bg-red-600 text-white shadow-sm hover:bg-red-700 focus-visible:outline-red-600',
    };
@endphp

<button type="{{ $type }}" {{ $attributes->merge(['class' =>
    'inline-flex items-center justify-center gap-2 rounded-lg px-4 py-2 text-sm font-semibold transition
     focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 '.$classes
]) }}>
    {{ $slot }}
</button>
