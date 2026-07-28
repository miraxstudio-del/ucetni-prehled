@props(['type' => 'success'])

@php
    $classes = match ($type) {
        'success' => 'bg-emerald-50 text-emerald-800 ring-emerald-200 dark:bg-emerald-950 dark:text-emerald-200 dark:ring-emerald-900',
        'warning' => 'bg-amber-50 text-amber-800 ring-amber-200 dark:bg-amber-950 dark:text-amber-200 dark:ring-amber-900',
        'error' => 'bg-red-50 text-red-800 ring-red-200 dark:bg-red-950 dark:text-red-200 dark:ring-red-900',
        'info' => 'bg-primary-50 text-primary-800 ring-primary-200 dark:bg-primary-950 dark:text-primary-200 dark:ring-primary-900',
    };
@endphp

<div {{ $attributes->merge(['class' => 'rounded-lg p-4 text-sm ring-1 '.$classes]) }}>
    {{ $slot }}
</div>
