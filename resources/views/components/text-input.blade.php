@props(['disabled' => false])

<input @disabled($disabled) {{ $attributes->merge(['class' =>
    'mt-1.5 block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm
     placeholder:text-slate-400 focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/30
     disabled:cursor-not-allowed disabled:bg-slate-50 disabled:text-slate-500
     dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 dark:placeholder:text-slate-500'
]) }}>
