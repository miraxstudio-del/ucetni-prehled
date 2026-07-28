@props(['title' => null])

@php
    $user = auth()->user();
    $context = app(\App\Support\OrganizationContext::class);
    $currentOrganization = $context->current();

    $navigation = [
        ['label' => 'Přehled', 'route' => 'dashboard', 'icon' => 'lucide-layout-dashboard'],
        ['label' => 'Faktury', 'route' => 'invoices.index', 'icon' => 'lucide-file-text'],
        ['label' => 'Klienti', 'route' => 'clients.index', 'icon' => 'lucide-users'],
        ['label' => 'Banka', 'route' => 'bank.index', 'icon' => 'lucide-landmark'],
        ['label' => 'Účetnictví', 'route' => 'accounting.index', 'icon' => 'lucide-calculator'],
        ['label' => 'Daně', 'route' => 'tax.index', 'icon' => 'lucide-percent'],
        ['label' => 'Nastavení', 'route' => 'settings.invoicing', 'icon' => 'lucide-settings'],
    ];
@endphp

<!DOCTYPE html>
<html lang="cs" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ? $title.' · ' : '' }}{{ config('app.name') }}</title>
    {{-- Externí soubor se src= (CSP inline skripty blokuje) — motiv se musí
         nastavit ještě před prvním vykreslením, aby nenaskočil blik špatné barvy. --}}
    <script src="{{ asset('theme-init.js') }}"></script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-full bg-slate-50 font-sans text-slate-900 antialiased dark:bg-slate-950 dark:text-slate-100"
      x-data="{ sidebarOpen: false }">

<div class="min-h-screen">
    {{-- Overlay pro mobilní drawer --}}
    <div x-cloak x-show="sidebarOpen" x-transition.opacity
         class="fixed inset-0 z-40 bg-slate-900/50 lg:hidden"
         @click="sidebarOpen = false"></div>

    {{-- Sidebar — výchozí stav je ve statických třídách (skrytý na mobilu,
         viditelný na desktopu), takže se vykreslí správně ještě před Alpine
         a po prokliku nikam „neposkakuje". Alpine jen přepíná drawer. --}}
    <aside class="fixed inset-y-0 left-0 z-50 flex w-64 -translate-x-full flex-col border-r border-slate-200 bg-white transition-transform duration-200 dark:border-slate-800 dark:bg-slate-900 lg:translate-x-0"
           :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full lg:translate-x-0'">

        <div class="flex h-16 items-center gap-2 px-6">
            <a href="{{ route('dashboard') }}" class="text-2xl font-extrabold tracking-tight text-primary-600">Účetní přehled</a>
        </div>

        <nav class="flex-1 space-y-1 overflow-y-auto px-3 py-4" aria-label="Hlavní navigace">
            @foreach ($navigation as $item)
                @php $active = request()->routeIs($item['route']) || ($item['route'] === 'settings.invoicing' && request()->routeIs('settings.*')); @endphp
                <a href="{{ route($item['route']) }}"
                   @class([
                       'flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition',
                       'bg-primary-50 text-primary-700 dark:bg-primary-950 dark:text-primary-300' => $active,
                       'text-slate-600 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-slate-100' => ! $active,
                   ])
                   @if($active) aria-current="page" @endif>
                    <x-dynamic-component :component="$item['icon']" class="h-5 w-5 shrink-0" />
                    {{ $item['label'] }}
                </a>
            @endforeach
        </nav>

        {{-- Lokální profil --}}
        <div class="border-t border-slate-200 p-4 dark:border-slate-800">
            <div class="flex items-center gap-3">
                <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-primary-100 text-sm font-semibold text-primary-700 dark:bg-primary-900 dark:text-primary-200">
                    {{ mb_strtoupper(mb_substr($user->name, 0, 1)) }}
                </div>
                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-medium">{{ $user->name }}</p>
                    <p class="truncate text-xs text-slate-500 dark:text-slate-400">Pouze na tomto počítači</p>
                </div>
            </div>
        </div>
    </aside>

    {{-- Obsah --}}
    <div class="flex min-h-screen flex-col lg:pl-64">
        {{-- Horní lišta --}}
        <header class="sticky top-0 z-30 flex h-16 items-center gap-3 border-b border-slate-200 bg-white/80 px-4 backdrop-blur dark:border-slate-800 dark:bg-slate-900/80 lg:px-8">
            <button type="button" class="rounded-lg p-2 text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800 lg:hidden"
                    @click="sidebarOpen = true">
                <x-lucide-menu class="h-5 w-5" />
                <span class="sr-only">Otevřít menu</span>
            </button>

            {{-- Globální vyhledávání — faktury (číslo/VS) a klienti (název/IČO/e-mail/město) --}}
            <div class="relative hidden max-w-md flex-1 md:block" x-data="globalSearch(@js(route('search')))"
                 @keydown.escape.window="close()">
                <x-lucide-search class="pointer-events-none absolute left-3 top-2.5 h-4 w-4 text-slate-400" />
                <input type="search" x-model="query" @input="onInput()" autocomplete="off"
                       @focus="query.trim().length >= 2 && (open = true)"
                       placeholder="Hledat fakturu nebo klienta…"
                       class="w-full rounded-lg border border-slate-200 bg-slate-50 py-2 pl-9 pr-3 text-sm focus:border-primary-500 focus:bg-white focus:outline-none focus:ring-2 focus:ring-primary-500/30 dark:border-slate-800 dark:bg-slate-800/50 dark:focus:bg-slate-900">

                <div x-cloak x-show="open" @click.outside="close()" x-transition
                     class="absolute left-0 right-0 z-50 mt-2 max-h-96 overflow-y-auto rounded-xl bg-white p-2 shadow-lg ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-700">
                    <template x-if="loading">
                        <div class="px-3 py-2 text-sm text-slate-400">Hledám…</div>
                    </template>
                    <template x-if="!loading && !hasResults">
                        <div class="px-3 py-2 text-sm text-slate-400">Nic nenalezeno.</div>
                    </template>
                    <template x-if="!loading && results.invoices.length > 0">
                        <div>
                            <div class="px-3 py-1 text-xs font-semibold uppercase tracking-wide text-slate-400">Faktury</div>
                            <template x-for="item in results.invoices" :key="item.url">
                                <a :href="item.url" class="flex items-center justify-between gap-3 rounded-lg px-3 py-2 text-sm hover:bg-slate-100 dark:hover:bg-slate-800">
                                    <span class="min-w-0 truncate">
                                        <span class="font-medium" x-text="item.label"></span>
                                        <span class="text-slate-400" x-text="item.sub ? ' · ' + item.sub : ''"></span>
                                    </span>
                                    <span class="shrink-0 tabular-nums text-slate-500" x-text="item.total.toLocaleString('cs-CZ') + ' Kč'"></span>
                                </a>
                            </template>
                        </div>
                    </template>
                    <template x-if="!loading && results.clients.length > 0">
                        <div>
                            <div class="px-3 py-1 text-xs font-semibold uppercase tracking-wide text-slate-400">Klienti</div>
                            <template x-for="item in results.clients" :key="item.url">
                                <a :href="item.url" class="flex items-center justify-between gap-3 rounded-lg px-3 py-2 text-sm hover:bg-slate-100 dark:hover:bg-slate-800">
                                    <span class="min-w-0 truncate font-medium" x-text="item.label"></span>
                                    <span class="shrink-0 text-slate-400" x-text="item.sub"></span>
                                </a>
                            </template>
                        </div>
                    </template>
                </div>
            </div>

            <div class="ml-auto flex items-center gap-2">
                <a href="{{ route('clients.create') }}"
                   class="inline-flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-semibold text-slate-700 ring-1 ring-inset ring-slate-200 transition hover:bg-slate-50 dark:text-slate-200 dark:ring-slate-700 dark:hover:bg-slate-800">
                    <x-lucide-user-plus class="h-4 w-4" />
                    <span class="hidden sm:inline">Nový klient</span>
                </a>

                <a href="{{ route('invoices.create') }}"
                   class="inline-flex items-center gap-2 rounded-lg bg-primary-600 px-3 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-primary-700">
                    <x-lucide-plus class="h-4 w-4" />
                    <span class="hidden sm:inline">Nová faktura</span>
                </a>

                <button type="button" x-data="themeToggle" @click="toggle()" title="Přepnout světlý/tmavý režim"
                        class="rounded-lg p-2 text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800">
                    <x-lucide-sun class="h-5 w-5" x-show="!dark" x-cloak />
                    <x-lucide-moon class="h-5 w-5" x-show="dark" x-cloak />
                    <span class="sr-only">Přepnout motiv</span>
                </button>

                <button type="button" title="Notifikace (již brzy)"
                        class="rounded-lg p-2 text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800">
                    <x-lucide-bell class="h-5 w-5" />
                    <span class="sr-only">Notifikace</span>
                </button>

                <a href="{{ route('settings.invoicing') }}"
                   class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium text-slate-700 ring-1 ring-inset ring-slate-200 transition hover:bg-slate-50 dark:text-slate-200 dark:ring-slate-700 dark:hover:bg-slate-800">
                    <x-lucide-building-2 class="h-4 w-4 text-slate-400" />
                    <span class="max-w-[10rem] truncate">{{ $currentOrganization?->name ?? 'Moje firma' }}</span>
                </a>
            </div>
        </header>

        <main class="flex-1 p-4 lg:p-8">
            @if (session('status') && str_contains((string) session('status'), ' '))
                <x-alert type="success" class="mb-6">{{ session('status') }}</x-alert>
            @endif

            {{ $slot }}
        </main>

        <footer class="border-t border-slate-200 px-4 py-4 text-center text-xs text-slate-500 dark:border-slate-800 dark:text-slate-400 lg:px-8">
            Účetní přehled vytvořilo
            <a href="https://www.miraxstudio.cz" target="_blank" rel="noopener noreferrer" class="font-medium text-primary-600 hover:underline dark:text-primary-400">Mirax Studio®</a>
            · zdarma pod licencí MIT · data zůstávají na tomto počítači
        </footer>
    </div>
</div>
</body>
</html>
