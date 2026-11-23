<div>
    <x-erp.page-header title="Sales & invoices" subtitle="Posted invoices only. Each row is immutable; due collections are separate receipts.">
        <x-slot:actions>
            @can('pos.sell')
                <a href="{{ route('pos.index') }}" class="rounded-lg bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700">Open POS</a>
            @endcan
        </x-slot:actions>
    </x-erp.page-header>

    <div class="mb-4 grid gap-2 rounded-xl border border-slate-200 bg-white p-3 shadow-sm md:grid-cols-2 xl:grid-cols-5">
        <input type="search" wire:model.live.debounce.400ms="search" placeholder="Invoice, customer, phone…" class="rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500 xl:col-span-2" aria-label="Search invoices">
        <select wire:model.live="unitFilter" class="rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500" aria-label="Unit">
            <option value="all">All my units</option>
            @foreach ($units as $unit)
                <option value="{{ $unit->id }}">{{ $unit->name }}</option>
            @endforeach
        </select>
        <input type="date" wire:model.live="from" class="rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500" aria-label="From date">
        <input type="date" wire:model.live="to" class="rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500" aria-label="To date">
    </div>

    <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-left text-[11px] uppercase tracking-wider text-slate-500">
                    <tr>
                        <th class="px-4 py-2.5 font-semibold">Invoice</th>
                        <th class="px-4 py-2.5 font-semibold">Date</th>
                        <th class="px-4 py-2.5 font-semibold">Customer</th>
                        <th class="px-4 py-2.5 font-semibold">Unit</th>
                        <th class="px-4 py-2.5 text-right font-semibold">Items</th>
                        <th class="px-4 py-2.5 text-right font-semibold">Total</th>
                        <th class="px-4 py-2.5 text-right font-semibold">Paid</th>
                        <th class="px-4 py-2.5 text-right font-semibold">Due</th>
                        <th class="px-4 py-2.5"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($sales as $sale)
                        <tr wire:key="sale-{{ $sale->id }}" class="hover:bg-slate-50/70">
                            <td class="px-4 py-2.5 font-mono text-xs font-medium text-slate-800">{{ $sale->invoice_no }}</td>
                            <td class="px-4 py-2.5 text-xs text-slate-600">{{ $sale->posted_at?->timezone(config('erp.timezone'))->format('d M Y, H:i') }}</td>
                            <td class="px-4 py-2.5 text-slate-700">{{ $sale->customer?->displayName() ?? 'Walk-in' }}</td>
                            <td class="px-4 py-2.5 text-slate-700">{{ $sale->businessUnit?->name }}</td>
                            <td class="px-4 py-2.5 text-right tabular-nums text-slate-700">{{ $sale->items_count }}</td>
                            <td class="px-4 py-2.5 text-right tabular-nums font-medium text-slate-800">{{ $currency }}{{ number_format((float) $sale->grand_total, 2) }}</td>
                            <td class="px-4 py-2.5 text-right tabular-nums text-slate-700">{{ $currency }}{{ number_format((float) $sale->paid_total, 2) }}</td>
                            <td class="px-4 py-2.5 text-right tabular-nums {{ (float) $sale->due_total > 0 ? 'font-semibold text-rose-700' : 'text-slate-500' }}">{{ $currency }}{{ number_format((float) $sale->due_total, 2) }}</td>
                            <td class="px-4 py-2.5 text-right">
                                <a href="{{ route('invoices.show', $sale) }}" class="rounded border border-slate-300 px-2 py-1 text-xs font-medium text-slate-700 hover:bg-slate-100">Invoice</a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="px-4 py-12 text-center text-sm text-slate-500">No invoices yet. Open the POS to post the first sale.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($sales->hasPages())
            <div class="border-t border-slate-200 px-4 py-3">{{ $sales->links() }}</div>
        @endif
    </div>
</div>
