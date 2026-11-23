<div class="mx-auto max-w-5xl">
    <x-erp.page-header :title="'Purchase '.$purchase->document_no" subtitle="A received purchase is immutable. Corrections use a return document in a later phase.">
        <x-slot:actions>
            <a href="{{ route('purchases.index') }}" class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">All purchases</a>
        </x-slot:actions>
    </x-erp.page-header>

    @error('purchase')
        <div class="mb-3 rounded-lg border border-rose-200 bg-rose-50 px-4 py-2 text-sm text-rose-800">{{ $message }}</div>
    @enderror

    <div class="grid gap-4 lg:grid-cols-3">
        <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm lg:col-span-2">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p class="font-mono text-lg font-semibold text-slate-900">{{ $purchase->document_no }}</p>
                    <p class="mt-1 text-sm text-slate-600">{{ $purchase->supplier?->name ?? 'Individual seller / no supplier record' }}</p>
                    <p class="text-xs text-slate-500">{{ $purchase->purchase_date->format('d M Y') }} · {{ $purchase->businessUnit?->name }}</p>
                </div>
                <x-erp.badge :tone="match($purchase->status) { \App\Enums\PurchaseStatus::Received => 'green', \App\Enums\PurchaseStatus::Draft => 'amber', default => 'slate' }">{{ $purchase->status->label() }}</x-erp.badge>
            </div>

            <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-2">
                <div>
                    <dt class="text-xs uppercase tracking-wide text-slate-500">Created by</dt>
                    <dd class="text-slate-800">{{ $purchase->creator?->name ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-wide text-slate-500">Received by</dt>
                    <dd class="text-slate-800">{{ $purchase->receiver?->name ?? 'Not received' }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-wide text-slate-500">Reference</dt>
                    <dd class="text-slate-800">{{ $purchase->reference ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-wide text-slate-500">Buying party (sheet)</dt>
                    <dd class="text-slate-800">{{ $purchase->buying_party_name ?? 'Uses supplier name' }}</dd>
                </div>
                <div>
                    <dt class="text-xs uppercase tracking-wide text-slate-500">Payment method</dt>
                    <dd class="text-slate-800">{{ $purchase->payment_method ?? '—' }}</dd>
                </div>
                @if ($purchase->notes)
                    <div class="sm:col-span-2">
                        <dt class="text-xs uppercase tracking-wide text-slate-500">Notes</dt>
                        <dd class="text-slate-800">{{ $purchase->notes }}</dd>
                    </div>
                @endif
            </dl>

            @if ($canReceive)
                <div class="mt-5 flex flex-wrap gap-2 border-t border-slate-100 pt-4">
                    <button type="button" wire:click="receive" wire:confirm="Receive this purchase into stock? This registers every IMEI and cannot be undone." class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-700">Receive into stock</button>
                    <button type="button" wire:click="cancel" wire:confirm="Cancel this draft purchase?" class="rounded-lg border border-rose-300 px-4 py-2 text-sm font-semibold text-rose-700 hover:bg-rose-50">Cancel draft</button>
                </div>
            @elseif ($purchase->status === \App\Enums\PurchaseStatus::Received)
                <p class="mt-5 rounded-lg bg-emerald-50 px-3 py-2 text-xs text-emerald-800">Received {{ $purchase->received_at?->timezone(config('erp.timezone'))->format('d M Y, H:i') }}. Stock and IMEI cycles are live.</p>
            @endif
        </div>

        <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <h3 class="text-sm font-semibold text-slate-900">Totals</h3>
            <dl class="mt-2 space-y-1.5 text-sm">
                <div class="flex justify-between text-slate-600"><dt>Subtotal</dt><dd class="tabular-nums">{{ $currency }}{{ number_format((float) $purchase->subtotal, 2) }}</dd></div>
                <div class="flex justify-between text-slate-600"><dt>Discount</dt><dd class="tabular-nums">{{ $currency }}{{ number_format((float) $purchase->discount_total, 2) }}</dd></div>
                <div class="flex justify-between text-slate-600"><dt>Other charges</dt><dd class="tabular-nums">{{ $currency }}{{ number_format((float) $purchase->other_charges, 2) }}</dd></div>
                <div class="flex justify-between border-t border-slate-100 pt-1.5 font-semibold text-slate-900"><dt>Total</dt><dd class="tabular-nums">{{ $currency }}{{ number_format((float) $purchase->total, 2) }}</dd></div>
                <div class="flex justify-between text-slate-600"><dt>Paid</dt><dd class="tabular-nums">{{ $currency }}{{ number_format((float) $purchase->paid_amount, 2) }}</dd></div>
                <div class="flex justify-between font-semibold {{ (float) $purchase->due_amount > 0 ? 'text-rose-700' : 'text-slate-800' }}"><dt>Due</dt><dd class="tabular-nums">{{ $currency }}{{ number_format((float) $purchase->due_amount, 2) }}</dd></div>
            </dl>
        </div>
    </div>

    <x-erp.card class="mt-4" title="Lines and received units" flush>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-left text-[11px] uppercase tracking-wider text-slate-500">
                    <tr>
                        <th class="px-4 py-2.5 font-semibold">Item</th>
                        <th class="px-4 py-2.5 font-semibold">IMEI / serial</th>
                        <th class="px-4 py-2.5 text-right font-semibold">Qty</th>
                        <th class="px-4 py-2.5 text-right font-semibold">Unit cost</th>
                        <th class="px-4 py-2.5 text-right font-semibold">Line total</th>
                        <th class="px-4 py-2.5 font-semibold">Stock result</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($purchase->lines as $line)
                        @php($serial = $receivedUnits->get($line->id))
                        <tr wire:key="pline-{{ $line->id }}">
                            <td class="px-4 py-2.5">
                                <p class="font-medium text-slate-800">{{ $line->variant?->displayName() }}</p>
                                <p class="text-[11px] uppercase tracking-wide text-slate-500">{{ $line->line_type->value }}</p>
                                @if ($line->expected_selling_price !== null)
                                    <p class="mt-0.5 text-[11px] text-slate-600">Selling price {{ $currency }}{{ number_format((float) $line->expected_selling_price, 2) }}</p>
                                @endif
                                @php($payload = $line->serial_payload ?? [])
                                @if (! empty($payload['adapter_name']) || ! empty($payload['cable_name']))
                                    <p class="mt-0.5 text-[11px] text-slate-500">
                                        @if (! empty($payload['adapter_name']))
                                            Adapter: {{ $payload['adapter_name'] }}@if (($payload['adapter_price'] ?? null) !== null) ({{ $currency }}{{ number_format((float) $payload['adapter_price'], 2) }})@endif
                                        @endif
                                        @if (! empty($payload['cable_name']))
                                            · Cable: {{ $payload['cable_name'] }}@if (($payload['cable_price'] ?? null) !== null) ({{ $currency }}{{ number_format((float) $payload['cable_price'], 2) }})@endif
                                        @endif
                                    </p>
                                @endif
                                @if ($line->status_note)
                                    <p class="mt-0.5 text-[11px] italic text-slate-500">{{ $line->status_note }}</p>
                                @endif
                            </td>
                            <td class="px-4 py-2.5 font-mono text-xs text-slate-700">
                                @if ($line->line_type === \App\Enums\LineType::Phone)
                                    {{ $line->imei_1 ?? 'Internal serial' }}
                                    @if ($line->imei_2)
                                        <span class="block text-slate-400">{{ $line->imei_2 }}</span>
                                    @endif
                                @else
                                    <span class="text-slate-400">—</span>
                                @endif
                            </td>
                            <td class="px-4 py-2.5 text-right tabular-nums text-slate-700">{{ $line->quantity }}</td>
                            <td class="px-4 py-2.5 text-right tabular-nums text-slate-700">{{ $currency }}{{ number_format((float) $line->unit_cost, 2) }}</td>
                            <td class="px-4 py-2.5 text-right tabular-nums text-slate-800">{{ $currency }}{{ number_format((float) $line->line_total, 2) }}</td>
                            <td class="px-4 py-2.5 text-xs">
                                @if ($line->line_type === \App\Enums\LineType::Accessory)
                                    @if ($purchase->status === \App\Enums\PurchaseStatus::Received)
                                        <span class="font-medium text-emerald-700">Stock received</span>
                                    @else
                                        <span class="text-slate-500">Pending receipt</span>
                                    @endif
                                @elseif ($serial?->unit)
                                    <a href="{{ route('imei.history', $serial->unit->primary_imei) }}" class="font-medium text-indigo-600 hover:text-indigo-700">Cycle {{ $serial->cycle_no }} · {{ $serial->unit->currentUnit?->name }}</a>
                                @else
                                    <span class="text-slate-500">Pending receipt</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-erp.card>
</div>
