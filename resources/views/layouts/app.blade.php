@php
    $currentUser = auth()->user();
    $unitContext = app(\App\Support\UnitContext::class);
    $activeUnit = $currentUser ? $unitContext->current($currentUser) : null;
    $availableUnits = $currentUser ? $unitContext->availableUnits($currentUser) : collect();
    $companyName = config('erp.company.name');
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full bg-slate-100">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Control Center' }} · {{ $companyName }}</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet" />
    <style>
        :root { --bn-font: 'Noto Sans Bengali', 'SolaimanLipi', 'Kalpurush', sans-serif; }
        html[lang="bn"] body { font-family: var(--bn-font); }
    </style>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="h-full font-sans antialiased">
<div x-data="{ sidebarOpen: false }" class="min-h-full">
    <div x-cloak x-show="sidebarOpen" x-transition.opacity class="fixed inset-0 z-30 bg-slate-900/60 lg:hidden" @click="sidebarOpen = false"></div>

    <aside
        class="fixed inset-y-0 left-0 z-40 flex w-64 -translate-x-full flex-col bg-slate-900 transition-transform duration-200 lg:translate-x-0"
        :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full lg:translate-x-0'"
    >
        <div class="flex h-16 shrink-0 items-center gap-2 border-b border-slate-800 px-4">
            <x-erp.shop-mark />
            <div class="min-w-0">
                <p class="truncate text-sm font-semibold text-white">{{ $companyName }}</p>
                <p class="text-[11px] uppercase tracking-wider text-slate-400">Mobile Shop ERP</p>
            </div>
        </div>

        <nav class="flex-1 space-y-6 overflow-y-auto px-3 py-4">
            <div>
                <p class="px-3 pb-1.5 text-[10px] font-semibold uppercase tracking-widest text-slate-500">{{ __('Workspace') }}</p>
                <div class="space-y-1">
                    <x-erp.nav-item :href="route('dashboard')" :active="request()->routeIs('dashboard')" icon="home">{{ __('Control Center') }}</x-erp.nav-item>
                    @can('pos.sell')
                        <x-erp.nav-item :href="route('pos.index')" :active="request()->routeIs('pos.*')" icon="receipt">POS terminal</x-erp.nav-item>
                    @endcan
                    @can('invoice.view')
                        <x-erp.nav-item :href="route('sales.index')" :active="request()->routeIs('sales.*')" icon="receipt">Sales &amp; invoices</x-erp.nav-item>
                    @endcan
                    @can('customer.view')
                        @can('invoice.view')
                        <x-erp.nav-item :href="route('payment-reminders')" :active="request()->routeIs('payment-reminders')" icon="clock">Payment reminders</x-erp.nav-item>
                    @endcan
                    <x-erp.nav-item :href="route('customers.index')" :active="request()->routeIs('customers.*')" icon="users">Customers</x-erp.nav-item>
                    @endcan
                    @can('inventory.view')
                        <x-erp.nav-item :href="route('inventory.index')" :active="request()->routeIs('inventory.index')" icon="cube">{{ __('Inventory') }}</x-erp.nav-item>
                        <x-erp.nav-item :href="route('imei.history')" :active="request()->routeIs('imei.history')" icon="clock">{{ __('IMEI History') }}</x-erp.nav-item>
                    @endcan
                    @can('purchase.view')
                        <x-erp.nav-item :href="route('purchases.index')" :active="request()->routeIs('purchases.*')" icon="receipt">{{ __('Purchases') }}</x-erp.nav-item>
                        @if(\App\Models\Setting::get('features.purchase_lots', true))<x-erp.nav-item :href="route('purchase-lots')" :active="request()->routeIs('purchase-lots')" icon="receipt">Lot orders</x-erp.nav-item>@endif
                    @endcan
                    @can('stock.transfer.view')
                        <x-erp.nav-item :href="route('transfers.index')" :active="request()->routeIs('transfers.*')" icon="arrows">{{ __('Unit Transfers') }}</x-erp.nav-item>
                    @endcan
                </div>
            </div>

            <div>
                <p class="px-3 pb-1.5 text-[10px] font-semibold uppercase tracking-widest text-slate-500">{{ __('Reference data') }}</p>
                <div class="space-y-1">
                    @can('catalog.view')
                        <x-erp.nav-item :href="route('catalog.index')" :active="request()->routeIs('catalog.*')" icon="tag">{{ __('Catalog') }}</x-erp.nav-item>
                    @endcan
                    @can('supplier.view')
                        <x-erp.nav-item :href="route('suppliers.index')" :active="request()->routeIs('suppliers.*')" icon="truck">{{ __('Suppliers') }}</x-erp.nav-item>
                    @endcan
                    @can('inventory.import')
                        <x-erp.nav-item :href="route('imports.phones')" :active="request()->routeIs('imports.*')" icon="upload">{{ __('Excel Import') }}</x-erp.nav-item>
                    @endcan
                </div>
            </div>

            <div>
                <p class="px-3 pb-1.5 text-[10px] font-semibold uppercase tracking-widest text-slate-500">{{ __('Administration') }}</p>
                <div class="space-y-1">
                    @can('staff.view')
                        @can('payroll.run')<x-erp.nav-item :href="route('staff.salary')" :active="request()->routeIs('staff.salary')" icon="users">Staff salary</x-erp.nav-item>@endcan
                        <x-erp.nav-item :href="route('staff.index')" :active="request()->routeIs('staff.*')" icon="users">{{ __('Staff & Access') }}</x-erp.nav-item>
                    @endcan
                    @can('settings.manage')
                        <x-erp.nav-item :href="route('settings.index')" :active="request()->routeIs('settings.*')" icon="cog">{{ __('Settings') }}</x-erp.nav-item>
                    @endcan
                </div>
            </div>
        </nav>

        <div class="border-t border-slate-800 p-3">
            <p class="px-2 text-[10px] uppercase tracking-widest text-slate-500">POS · Inventory · Ledger</p>
            <p class="mt-1 px-2 text-[11px] leading-snug text-slate-400">Inventory, sales, exchanges, expenses and customer payment schedules.</p>
        </div>
    </aside>

    <div class="lg:pl-64">
        <header class="sticky top-0 z-20 border-b border-slate-200 bg-white/95 backdrop-blur">
            <div class="flex h-16 items-center gap-3 px-4 lg:px-6">
                <button type="button" class="rounded-lg p-2 text-slate-600 hover:bg-slate-100 lg:hidden" @click="sidebarOpen = true" aria-label="Open navigation">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" /></svg>
                </button>

                <form method="GET" action="{{ route('inventory.index') }}" class="hidden min-w-0 flex-1 md:block">
                    <label for="global-search" class="sr-only">Search IMEI, model, invoice</label>
                    <div class="relative max-w-xl">
                        <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" fill="none" viewBox="0 0 24 24" stroke-width="1.7" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-4.35-4.35M17 10.5a6.5 6.5 0 1 1-13 0 6.5 6.5 0 0 1 13 0Z" /></svg>
                        <input id="global-search" type="search" name="q" value="{{ request('q') }}" placeholder="{{ __('Search IMEI 1 / IMEI 2, model, SKU…') }}" class="w-full rounded-lg border-slate-300 bg-slate-50 pl-9 text-sm focus:border-indigo-500 focus:bg-white focus:ring-indigo-500">
                    </div>
                </form>

                <div class="ml-auto flex items-center gap-2">
                    @if ($availableUnits->count() > 1)
                        <form method="POST" action="{{ route('unit.switch') }}">
                            @csrf
                            <label for="unit-selector" class="sr-only">Active unit</label>
                            <select id="unit-selector" name="unit_id" onchange="this.form.submit()" class="rounded-lg border-slate-300 py-1.5 pl-3 pr-8 text-sm font-medium text-slate-700 focus:border-indigo-500 focus:ring-indigo-500">
                                @foreach ($availableUnits as $unit)
                                    <option value="{{ $unit->id }}" @selected($activeUnit?->id === $unit->id)>{{ $unit->name }}</option>
                                @endforeach
                            </select>
                        </form>
                    @elseif ($activeUnit)
                        <span class="hidden rounded-lg bg-slate-100 px-3 py-1.5 text-sm font-medium text-slate-700 sm:inline">{{ $activeUnit->name }}</span>
                    @endif

                    <form method="POST" action="{{ route('locale.switch') }}" class="hidden sm:block">
                        @csrf
                        <select name="locale" onchange="this.form.submit()" aria-label="Language" class="rounded-lg border-slate-300 py-1.5 pl-2 pr-7 text-xs font-medium text-slate-600 focus:border-indigo-500 focus:ring-indigo-500">
                            @foreach (config('erp.locales') as $code => $label)
                                <option value="{{ $code }}" @selected(app()->getLocale() === $code)>{{ strtoupper($code) }}</option>
                            @endforeach
                        </select>
                    </form>

                    <details class="relative">
                        <summary class="flex cursor-pointer list-none items-center gap-2 rounded-lg px-2 py-1.5 hover:bg-slate-100">
                            <span class="flex h-8 w-8 items-center justify-center rounded-full bg-indigo-100 text-xs font-semibold text-indigo-700">{{ strtoupper(substr($currentUser?->name ?? '?', 0, 1)) }}</span>
                            <span class="hidden text-left sm:block">
                                <span class="block text-xs font-semibold text-slate-800">{{ $currentUser?->name }}</span>
                                <span class="block text-[11px] text-slate-500">{{ $currentUser?->getRoleNames()->first() }}</span>
                            </span>
                        </summary>
                        <div class="absolute right-0 mt-2 w-52 rounded-lg border border-slate-200 bg-white p-1 shadow-lg">
                            <a href="{{ route('profile.edit') }}" class="block rounded-md px-3 py-2 text-sm text-slate-700 hover:bg-slate-50">{{ __('My profile') }}</a>
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <button type="submit" class="block w-full rounded-md px-3 py-2 text-left text-sm text-rose-600 hover:bg-rose-50">{{ __('Sign out') }}</button>
                            </form>
                        </div>
                    </details>
                </div>
            </div>
        </header>

        <main class="px-4 py-6 lg:px-6">
            @if (session('success'))
                <div x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 6000)" class="mb-4 flex items-start gap-2 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                    <span class="font-medium">Done.</span><span>{{ session('success') }}</span>
                </div>
            @endif

            @if (session('error'))
                <div x-data="{ show: true }" x-show="show" class="mb-4 flex items-start gap-2 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
                    <span class="font-medium">Blocked.</span><span>{{ session('error') }}</span>
                </div>
            @endif

            @if ($errors->any())
                <div class="mb-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">
                    <p class="font-medium">Please fix the following:</p>
                    <ul class="mt-1 list-inside list-disc">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            {{ $slot }}
        </main>
    </div>
</div>
@livewireScripts
</body>
</html>
