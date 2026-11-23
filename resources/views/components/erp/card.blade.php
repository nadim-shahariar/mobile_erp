@props(['title' => null, 'description' => null, 'flush' => false])
<section {{ $attributes->merge(['class' => 'rounded-xl border border-slate-200 bg-white shadow-sm']) }}>
    @if ($title || $description)
        <header class="flex flex-wrap items-start justify-between gap-2 border-b border-slate-100 px-4 py-3">
            <div>
                <h2 class="text-sm font-semibold text-slate-900">{{ $title }}</h2>
                @if ($description)
                    <p class="mt-0.5 text-xs text-slate-500">{{ $description }}</p>
                @endif
            </div>
            @isset($actions)
                <div class="flex items-center gap-2">{{ $actions }}</div>
            @endisset
        </header>
    @endif
    <div class="{{ $flush ? '' : 'p-4' }}">{{ $slot }}</div>
</section>
