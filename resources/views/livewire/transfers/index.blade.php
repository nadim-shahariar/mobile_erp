<div>
    <x-erp.page-header title="Unit transfers" subtitle="Request → dispatch → receive. In-transit handsets are never sellable and no stock can exist in two units at once.">
        <x-slot:actions>
            @if ($canCreate && $unitCount > 1)
                <a href="{{ route('transfers.create') }}" class="rounded-lg bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700">New transfer</a>
            @elseif ($canCreate)
                <span class="rounded-lg bg-slate-100 px-3 py-2 text-xs font-medium text-slate-600">Transfers need at least two authorised units.</span>
            @endif
        </x-slot:actions>
    </x-erp.page-header>

    <div class="mb-4 grid gap-2 rounded-xl border border-slate-200 bg-white p-3 shadow-sm md:grid-cols-3">
        <input type="search" wire:model.live.debounce.400ms="search" placeholder="Transfer no or IMEI…" class="rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500 md:col-span-2" aria-label="Search transfers">
        <select wire:model.live="status" class="rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500" aria-label="Status">
            <option value="all">Any status</option>
            <option value="REQUESTED">Requested</option>
            <option value="DISPATCHED">Dispatched</option>
            <option value="RECEIVED">Received</option>
            <option value="CANCELLED">Cancelled</option>
        </select>
    </div>

    <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-left text-[11px] uppercase tracking-wider text-slate-500">
                    <tr>
                        <th class="px-4 py-2.5 font-semibold">Transfer</th>
                        <th class="px-4 py-2.5 font-semibold">Route</th>
                        <th class="px-4 py-2.5 font-semibold">Requested</th>
                        <th class="px-4 py-2.5 text-right font-semibold">Lines</th>
                        <th class="px-4 py-2.5 font-semibold">Status</th>
                        <th class="px-4 py-2.5"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($transfers as $transfer)
                        <tr wire:key="transfer-{{ $transfer->id }}" class="hover:bg-slate-50/70">
                            <td class="px-4 py-2.5 font-mono text-xs font-medium text-slate-800">{{ $transfer->transfer_no }}</td>
                            <td class="px-4 py-2.5 text-slate-700">{{ $transfer->fromUnit?->name }} → {{ $transfer->toUnit?->name }}</td>
                            <td class="px-4 py-2.5 text-xs text-slate-600">
                                {{ $transfer->requester?->name }}
                                <span class="block text-slate-400">{{ $transfer->requested_at?->timezone(config('erp.timezone'))->format('d M Y, H:i') }}</span>
                            </td>
                            <td class="px-4 py-2.5 text-right tabular-nums text-slate-700">{{ $transfer->lines->count() }}</td>
                            <td class="px-4 py-2.5">
                                <x-erp.badge :tone="match($transfer->status) { \App\Enums\StockTransferStatus::Received => 'green', \App\Enums\StockTransferStatus::Dispatched => 'amber', \App\Enums\StockTransferStatus::Cancelled, \App\Enums\StockTransferStatus::Rejected => 'slate', default => 'blue' }">{{ $transfer->status->label() }}</x-erp.badge>
                            </td>
                            <td class="px-4 py-2.5 text-right">
                                <a href="{{ route('transfers.show', $transfer) }}" class="rounded border border-slate-300 px-2 py-1 text-xs font-medium text-slate-700 hover:bg-slate-100">Open</a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-4 py-12 text-center">
                                <p class="text-sm font-medium text-slate-700">No transfers yet.</p>
                                <p class="mt-1 text-xs text-slate-500">Create a transfer to move stock between units with a full audit trail.</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($transfers->hasPages())
            <div class="border-t border-slate-200 px-4 py-3">{{ $transfers->links() }}</div>
        @endif
    </div>
</div>
