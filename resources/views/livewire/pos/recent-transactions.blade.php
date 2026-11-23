<details wire:key="recent-transactions-{{ $unitId }}" wire:ignore.self class="rounded-xl border border-slate-200 bg-white shadow-sm">
    <summary class="cursor-pointer px-4 py-3 text-sm font-semibold text-slate-800">Recent transactions · Today ({{ $recentSales->count() }})</summary>
    <div class="max-h-96 space-y-2 overflow-y-auto border-t border-slate-100 p-3">
        @forelse ($recentSales as $recent)
            <details wire:key="recent-transaction-{{ $recent->id }}" wire:ignore.self class="rounded-lg border border-slate-200 text-xs">
                <summary class="cursor-pointer p-3">
                    <span class="font-mono font-semibold text-indigo-700">{{ $recent->invoice_no }}</span>
                    <span class="ml-1 font-semibold">{{ $currency }}{{ number_format((float) $recent->grand_total, 2) }}</span>
                    <span class="mt-1 block text-slate-500">{{ $recent->customer?->displayName() ?? 'Walk-in customer' }} · {{ $recent->posted_at?->timezone(config('erp.timezone'))->format('H:i') }} · {{ $recent->exchange ? 'Exchange' : 'Sale' }}</span>
                </summary>
                <div class="space-y-2 border-t border-slate-100 p-3 text-slate-600">
                    @foreach ($recent->items as $item)<p>{{ $item->description }} @if ($item->identifier)<span class="block font-mono text-slate-500">{{ $item->identifier }}</span>@endif</p>@endforeach
                    <p>Settled: {{ $currency }}{{ number_format((float) $recent->paid_total, 2) }} · Due: <span class="font-semibold {{ (float) $recent->due_total > 0 ? 'text-rose-700' : '' }}">{{ $currency }}{{ number_format((float) $recent->due_total, 2) }}</span></p>
                    @foreach ($recent->payments as $payment)<p>{{ $payment->method->label() }}: {{ $currency }}{{ $payment->amount }}</p>@endforeach
                    @if ($recent->exchange)<p>Trade-in credit: {{ $currency }}{{ $recent->trade_in_credit }}</p>@endif
                    <a target="_blank" rel="noopener" href="{{ route('invoices.show', $recent) }}" class="inline-block font-semibold text-indigo-700 underline">View / print invoice</a>
                </div>
            </details>
        @empty
            <p class="text-xs text-slate-500">No transactions in this branch today.</p>
        @endforelse
        @if ($recentSales->count() === 10)<p class="text-xs text-slate-500">Showing the latest 10 transactions. <a href="{{ route('sales.index') }}" target="_blank" rel="noopener" class="text-indigo-700 underline">View all invoices</a></p>@endif
    </div>
</details>
