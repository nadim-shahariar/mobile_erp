@php
    $currentUser = auth()->user();
    $companyName = config('erp.company.name');
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full bg-slate-100">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Point of Sale · {{ $companyName }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="h-full font-sans antialiased">
<div class="min-h-full">
    <header class="bg-slate-900 text-white">
        <div class="mx-auto flex h-16 max-w-[1700px] items-center gap-4 px-4">
            <a href="{{ route('pos.index') }}" class="flex items-center gap-2">
                <x-erp.shop-mark />
                <span class="hidden sm:block">
                    <span class="block text-sm font-semibold tracking-wide">{{ $companyName }}</span>
                    <span class="block text-[10px] uppercase tracking-widest text-slate-400">Mobile Shop POS</span>
                </span>
            </a>

            <div class="ml-4 hidden items-center gap-2 text-xs md:flex">
                <span class="text-slate-400">Counter</span>
                <span class="font-semibold">{{ $currentUser?->name }}</span>
                <span class="rounded-full bg-emerald-500/15 px-2 py-0.5 font-medium text-emerald-300">POS</span>
            </div>

            <div class="ml-auto flex items-center gap-2">
                <a href="{{ route('dashboard') }}" class="rounded-lg border border-slate-700 px-3 py-1.5 text-xs font-semibold text-slate-200 hover:bg-slate-800">Back office</a>
                @can('payroll.run')<a href="{{ route('staff.salary') }}" class="rounded-lg border border-slate-700 px-3 py-1.5 text-xs font-semibold text-slate-200">Staff salary</a>@endcan
                @can('invoice.view')
                    <a href="{{ route('payment-reminders') }}" class="rounded-lg border border-amber-600 px-3 py-1.5 text-xs font-semibold text-amber-200">Reminders</a>
                    <a href="{{ route('sales.index') }}" class="rounded-lg border border-slate-700 px-3 py-1.5 text-xs font-semibold text-slate-200 hover:bg-slate-800">Invoices</a>
                @endcan
                <span class="hidden text-right text-[11px] leading-tight text-slate-400 sm:block">
                    <span class="block text-sm font-semibold text-white" data-clock>{{ \App\Support\BusinessClock::now()->format('H:i') }}</span>
                    {{ \App\Support\BusinessClock::now()->format('D, d M Y') }}
                </span>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="rounded-lg bg-slate-800 px-3 py-1.5 text-xs font-semibold text-slate-200 hover:bg-slate-700">Logout</button>
                </form>
            </div>
        </div>
    </header>

    <main class="mx-auto max-w-[1700px] px-4 py-4">
        @if (session('success'))
            <div class="mb-3 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-2 text-sm text-emerald-800">{{ session('success') }}</div>
        @endif
        @if ($errors->any() && !$errors->has('sale'))
            <div class="mb-3 rounded-lg border border-rose-200 bg-rose-50 px-4 py-2 text-sm text-rose-800">
                <ul class="list-inside list-disc">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif
        {{ $slot }}
    </main>
</div>
@livewireScripts
</body>
</html>
