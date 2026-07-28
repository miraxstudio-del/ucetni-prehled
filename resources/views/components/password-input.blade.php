@props(['disabled' => false])

<div class="relative" x-data="{ show: false }">
    <input @disabled($disabled)
           x-bind:type="show ? 'text' : 'password'"
           {{ $attributes->merge(['class' =>
                'mt-1.5 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 pr-10 text-sm text-slate-900 shadow-sm
                 placeholder:text-slate-400 focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/30
                 disabled:cursor-not-allowed disabled:bg-slate-50 disabled:text-slate-500
                 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 dark:placeholder:text-slate-500'
           ]) }}>
    <button type="button" @click="show = !show" tabindex="-1"
            class="absolute inset-y-0 right-0 top-1.5 flex items-center pr-3 text-slate-400 hover:text-slate-600 dark:hover:text-slate-300"
            x-bind:aria-label="show ? 'Skrýt heslo' : 'Zobrazit heslo'">
        <x-lucide-eye x-show="!show" class="h-4 w-4" />
        <x-lucide-eye-off x-show="show" x-cloak class="h-4 w-4" />
    </button>
</div>
