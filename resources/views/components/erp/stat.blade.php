@props(['label', 'value', 'hint' => null, 'emphasis' => false])
<div {{ $attributes->merge(['class' => 'rounded-xl border border-slate-200 bg-white p-4 shadow-sm']) }}>
    <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-500">{{ $label }}</p>
    <p class="mt-1.5 text-2xl font-semibold tabular-nums {{ $emphasis ? 'text-indigo-700' : 'text-slate-900' }}">{{ $value }}</p>
    @if ($hint)
        <p class="mt-1 text-xs text-slate-500">{{ $hint }}</p>
    @endif
</div>
