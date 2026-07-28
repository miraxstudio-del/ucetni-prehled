@props(['year'])

<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-2 rounded-full bg-primary-600 px-3.5 py-1.5 text-sm font-bold text-white shadow-sm']) }}>
    <x-lucide-calendar-days class="h-4 w-4" />
    Rok {{ $year }}
</span>
