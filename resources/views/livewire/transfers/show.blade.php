<div class="mx-auto max-w-4xl">
    <x-erp.page-header :title="'Transfer '.$transfer->transfer_no" subtitle="Each step is actor-stamped and appended to every handset's IMEI timeline.">
        <x-slot:actions>
            <a href="{{ route('transfers.memo',$transfer) }}" target="_blank" rel="noopener" class="rounded-lg bg-indigo-600 px-3 py-2 text-sm font-semibold text-white">Print transfer memo</a>
            <a href="{{ route('transfers.index') }}" class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">All transfers</a>
        </x-slot:actions>
    </x-erp.page-header>

    @error('transfer')
        <div class="mb-3 rounded-lg border border-rose-200 bg-rose-50 px-4 py-2 text-sm text-rose-800">{{ $message }}</div>
    @enderror

    <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <p class="font-mono text-lg font-semibold text-slate-900">{{ $transfer->transfer_no }}</p>
                <p class="mt-1 text-sm text-slate-700">{{ $transfer->fromUnit?->name }} → {{ $transfer->toUnit?->name }}</p>
                <p class="text-xs text-slate-500">Requested {{ $transfer->requested_at?->timezone(config('erp.timezone'))->format('d M Y, H:i') }} by {{ $transfer->requester?->name }}</p>
            </div>
            <x-erp.badge :tone="match($transfer->status) { \App\Enums\StockTransferStatus::Received => 'green', \App\Enums\StockTransferStatus::Dispatched => 'amber', default => 'blue' }">{{ $transfer->status->label() }}</x-erp.badge>
        </div>

        <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-2">
            <div>
                <dt class="text-xs uppercase tracking-wide text-slate-500">Dispatched</dt>
                <dd class="text-slate-800">{{ $transfer->dispatcher?->name ?? 'Not yet' }} @if ($transfer->dispatched_at) · {{ $transfer->dispatched_at->timezone(config('erp.timezone'))->format('d M, H:i') }} @endif</dd>
            </div>
            <div>
                <dt class="text-xs uppercase tracking-wide text-slate-500">Received</dt>
                <dd class="text-slate-800">{{ $transfer->receiver?->name ?? 'Not yet' }} @if ($transfer->received_at) · {{ $transfer->received_at->timezone(config('erp.timezone'))->format('d M, H:i') }} @endif</dd>
            </div>
            @if ($transfer->notes)
                <div class="sm:col-span-2">
                    <dt class="text-xs uppercase tracking-wide text-slate-500">Note</dt>
                    <dd class="text-slate-800">{{ $transfer->notes }}</dd>
                </div>
            @endif
            @if ($transfer->cancellation_reason)
                <div class="sm:col-span-2">
                    <dt class="text-xs uppercase tracking-wide text-slate-500">Cancellation reason</dt>
                    <dd class="text-slate-800">{{ $transfer->cancellation_reason }}</dd>
                </div>
            @endif
        </dl>

        <div class="mt-5 flex flex-wrap gap-2 border-t border-slate-100 pt-4">
            @if ($canDispatch)
                <button type="button" wire:click="dispatchTransfer" wire:confirm="Dispatch this transfer? Handsets move to in-transit and cannot be sold until received." class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">Dispatch</button>
            @endif
            @if ($canReceive)
                <button type="button" wire:click="receiveTransfer" wire:confirm="Confirm the IMEIs are physically present and receive this transfer?" class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700">Receive into {{ $transfer->toUnit?->name }}</button>
            @endif
            @if ($canCancel)
                <button type="button" wire:click="cancelTransfer" wire:confirm="Cancel this requested transfer and release reservations?" class="rounded-lg border border-rose-300 px-4 py-2 text-sm font-semibold text-rose-700 hover:bg-rose-50">Cancel request</button>
            @endif
            @if (! $canDispatch && ! $canReceive && ! $canCancel)
                <p class="text-xs text-slate-500">No action is available to you for this transfer in its current state.</p>
            @endif
        </div>
    </div>

    <x-erp.card class="mt-4" title="Lines" flush>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-left text-[11px] uppercase tracking-wider text-slate-500">
                    <tr>
                        <th class="px-4 py-2.5 font-semibold">Item</th>
                        <th class="px-4 py-2.5 font-semibold">IMEI</th>
                        <th class="px-4 py-2.5 text-right font-semibold">Qty</th>
                        <th class="px-4 py-2.5 font-semibold">Current custody</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($transfer->lines as $line)
                        <tr wire:key="tline-{{ $line->id }}">
                            <td class="px-4 py-2.5">
                                <p class="font-medium text-slate-800">{{ $line->serializedUnit?->displayName() ?? $line->variant?->displayName() }}</p>
                                <p class="text-[11px] uppercase tracking-wide text-slate-500">{{ $line->line_type->value }}</p>
                            </td>
                            <td class="px-4 py-2.5 font-mono text-xs text-slate-700">
                                @if ($line->serializedUnit)
                                    <a href="{{ route('imei.history', $line->serializedUnit->primary_imei) }}" class="text-indigo-600 hover:text-indigo-700">{{ $line->serializedUnit->primary_imei }}</a>
                                @else
                                    —
                                @endif
                            </td>
                            <td class="px-4 py-2.5 text-right tabular-nums text-slate-700">{{ $line->quantity ?? 1 }}</td>
                            <td class="px-4 py-2.5 text-xs text-slate-700">
                                @if ($line->serializedUnit)
                                    {{ $line->serializedUnit->current_custody->label() }}
                                    <span class="block text-slate-400">{{ $line->serializedUnit->currentUnit?->name ?? 'No unit' }}</span>
                                @else
                                    <span class="text-slate-500">Accessory quantity</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-erp.card>
</div>
