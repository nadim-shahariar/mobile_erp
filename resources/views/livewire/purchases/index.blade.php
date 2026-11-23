<div>
    <x-erp.page-header title="Purchases" subtitle="Inventory acquisition documents. Receiving a purchase creates IMEI identities, opens cost-basis cycles and updates accessory stock atomically.">
        <x-slot:actions>
            @if ($canCreate)
                <a href="{{ route('purchases.create') }}" class="rounded-lg bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700">New purchase</a>
            @endif
        </x-slot:actions>
    </x-erp.page-header>

    <div class="mb-4 grid gap-2 rounded-xl border border-slate-200 bg-white p-3 shadow-sm md:grid-cols-2 xl:grid-cols-5">
        <input type="search" wire:model.live.debounce.400ms="search" placeholder="Document no, supplier…" class="rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500" aria-label="Search purchases">
        <select wire:model.live="unitFilter" class="rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500" aria-label="Unit">
            <option value="all">All my units</option>
            @foreach ($units as $unit)
                <option value="{{ $unit->id }}">{{ $unit->name }}</option>
            @endforeach
        </select>
        <select wire:model.live="status" class="rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500" aria-label="Status">
            <option value="all">Any status</option>
            <option value="DRAFT">Draft</option>
            <option value="RECEIVED">Received</option>
            <option value="CANCELLED">Cancelled</option>
        </select>
        <input type="date" wire:model.live="from" class="rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500" aria-label="From date">
        <input type="date" wire:model.live="to" class="rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500" aria-label="To date">
    </div>

    <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-left text-[11px] uppercase tracking-wider text-slate-500">
                    <tr>
                        <th class="px-4 py-2.5 font-semibold">Document</th>
                        <th class="px-4 py-2.5 font-semibold">Date</th>
                        <th class="px-4 py-2.5 font-semibold">Supplier</th>
                        <th class="px-4 py-2.5 font-semibold">Unit</th>
                        <th class="px-4 py-2.5 text-right font-semibold">Phones</th>
                        <th class="px-4 py-2.5 text-right font-semibold">Total</th>
                        <th class="px-4 py-2.5 text-right font-semibold">Paid</th>
                        <th class="px-4 py-2.5 text-right font-semibold">Due</th>
                        <th class="px-4 py-2.5 font-semibold">Status</th>
                        <th class="px-4 py-2.5"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($purchases as $purchase)
                        <tr class="hover:bg-slate-50/70" wire:key="purchase-{{ $purchase->id }}">
                            <td class="px-4 py-2.5 font-mono text-xs font-medium text-slate-800">{{ $purchase->document_no }}</td>
                            <td class="px-4 py-2.5 text-slate-700">{{ $purchase->purchase_date->format('d M Y') }}</td>
                            <td class="px-4 py-2.5 text-slate-700">{{ $purchase->supplier?->name ?? 'Individual seller' }}</td>
                            <td class="px-4 py-2.5 text-slate-700">{{ $purchase->businessUnit?->name }}</td>
                            <td class="px-4 py-2.5 text-right tabular-nums text-slate-700">{{ $purchase->lines->where('line_type', \App\Enums\LineType::Phone)->count() }}</td>
                            <td class="px-4 py-2.5 text-right tabular-nums text-slate-800">{{ $currency }}{{ number_format((float) $purchase->total, 2) }}</td>
                            <td class="px-4 py-2.5 text-right tabular-nums text-slate-700">{{ $currency }}{{ number_format((float) $purchase->paid_amount, 2) }}</td>
                            <td class="px-4 py-2.5 text-right tabular-nums {{ (float) $purchase->due_amount > 0 ? 'font-medium text-rose-700' : 'text-slate-500' }}">{{ $currency }}{{ number_format((float) $purchase->due_amount, 2) }}</td>
                            <td class="px-4 py-2.5">
                                <x-erp.badge :tone="match($purchase->status) { \App\Enums\PurchaseStatus::Received => 'green', \App\Enums\PurchaseStatus::Draft => 'amber', default => 'slate' }">{{ $purchase->status->label() }}</x-erp.badge>
                            </td>
                            <td class="px-4 py-2.5 text-right">
                                <a href="{{ route('purchases.show', $purchase) }}" class="rounded border border-slate-300 px-2 py-1 text-xs font-medium text-slate-700 hover:bg-slate-100">Open</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="10" class="px-4 py-12 text-center">
                                <p class="text-sm font-medium text-slate-700">No purchase documents yet.</p>
                                <p class="mt-1 text-xs text-slate-500">Create a purchase to book real inventory. Drafts do not affect stock until received.</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($purchases->hasPages())
            <div class="border-t border-slate-200 px-4 py-3">{{ $purchases->links() }}</div>
        @endif
    </div>
</div>
