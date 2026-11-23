<div>
    <x-erp.page-header title="Control Center" subtitle="Live inventory truth across your authorised units. Every figure below is queried from posted records.">
        <x-slot:actions>
            @can('inventory.create')
                <a href="{{ route('inventory.create') }}" class="inline-flex items-center gap-1.5 rounded-lg bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                    Add stock
                </a>
            @endcan
            @can('purchase.create')
                <a href="{{ route('purchases.create') }}" class="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50">New purchase</a>
            @endcan
            @can('stock.transfer.create')
                <a href="{{ route('transfers.create') }}" class="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50">Unit transfer</a>
            @endcan
        </x-slot:actions>
    </x-erp.page-header>

    <div class="mb-4 flex flex-wrap items-center gap-3">
        <label for="dash-unit" class="text-xs font-semibold uppercase tracking-wider text-slate-500">Scope</label>
        <select id="dash-unit" wire:model.live="unitFilter" class="rounded-lg border-slate-300 py-1.5 pl-3 pr-8 text-sm focus:border-indigo-500 focus:ring-indigo-500">
            <option value="all">All my units</option>
            @foreach ($units as $unit)
                <option value="{{ $unit->id }}">{{ $unit->name }}</option>
            @endforeach
        </select>
        <span class="text-xs text-slate-500">Business day: {{ \App\Support\BusinessClock::today()->format('D, d M Y') }} ({{ config('erp.timezone') }})</span>
    </div>

    @can('invoice.view')
        <livewire:payment-reminders :unit-id="$unitFilter" :key="'dashboard-reminders-'.$unitFilter" />
    @endcan

    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <x-erp.stat label="Today's sales" :value="$currency.number_format((float) $salesSummary['sales_total']->amount, 2)" :hint="$salesSummary['sales_count'].' posted invoice(s)'" emphasis />
        <x-erp.stat label="Collected today" :value="$currency.number_format((float) $salesSummary['collected_total']->amount, 2)" :hint="'Cash '.$currency.number_format((float) $salesSummary['collected_cash']->amount, 0).' · Bank '.$currency.number_format((float) $salesSummary['collected_bank']->amount, 0).' · MFS '.$currency.number_format((float) $salesSummary['collected_mfs']->amount, 0)" />
        <x-erp.stat label="Outstanding due" :value="$currency.number_format((float) $salesSummary['outstanding_due']->amount, 2)" hint="Customer/shop receivables" />
        @can('reports.cost_profit')
            <x-erp.stat label="Gross profit today" :value="$currency.number_format((float) $salesSummary['gross_profit']->amount, 2)" :hint="'COGS '.$currency.number_format((float) $salesSummary['cogs_total']->amount, 2)" />
        @endcan
    </div>

    <div class="mt-3 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <x-erp.stat label="Available phones" :value="number_format($summary['counts']['sellable'])" hint="In unit · available to sell" emphasis />
        <x-erp.stat label="In transit" :value="number_format($summary['counts']['IN_TRANSIT'])" hint="Not sellable until received" />
        <x-erp.stat label="With shops / persons" :value="number_format($summary['counts']['AT_EXTERNAL_PARTY'])" hint="Temporary deliveries · still owned" />
        <x-erp.stat label="At repair vendor" :value="number_format($summary['counts']['AT_REPAIR_VENDOR'])" hint="Owned, not sellable" />
        <x-erp.stat label="Pending transfers" :value="number_format($summary['pending_transfers'])" hint="Requested or dispatched" />

        @if ($canSeeCost)
            <x-erp.stat label="Phone stock value" :value="$currency.number_format((float) $summary['stock_value']->amount, 2)" hint="Sum of active acquisition cost bases in units" />
            <x-erp.stat label="Accessory stock value" :value="$currency.number_format((float) $summary['accessory_value']->amount, 2)" hint="On hand × weighted average cost" />
            <x-erp.stat label="Value in transit" :value="$currency.number_format((float) $summary['in_transit_value']->amount, 2)" hint="Cost basis currently moving" />
            <x-erp.stat label="Value at repair" :value="$currency.number_format((float) $summary['repair_value']->amount, 2)" hint="Cost basis at vendors" />
        @endif

        <x-erp.stat label="Today's received purchases" :value="number_format($summary['today_purchase_count'])" :hint="$currency.number_format((float) $summary['today_purchases']->amount, 2).' at cost'" />
        <x-erp.stat label="Low-stock accessories" :value="number_format($summary['low_stock_count'])" hint="At or below reorder point" />
        <x-erp.stat label="Quarantined" :value="number_format($summary['counts']['RETURN_PENDING_INSPECTION'])" hint="Pending inspection" />
    </div>

    <div class="mt-5 grid gap-5 xl:grid-cols-3">
        <x-erp.card title="Stock by unit" description="Physically available handsets per branch." class="xl:col-span-1">
            <ul class="divide-y divide-slate-100">
                @foreach ($summary['units'] as $unitRow)
                    <li class="flex items-center justify-between py-2.5">
                        <div>
                            <p class="text-sm font-medium text-slate-800">{{ $unitRow['name'] }}</p>
                            <p class="text-xs text-slate-500">{{ $summary['counts']['sellable'] > 0 ? 'Authorised' : 'No availability' }}</p>
                        </div>
                        <div class="text-right">
                            <p class="text-lg font-semibold tabular-nums text-slate-900">{{ number_format($unitRow['available']) }}</p>
                            <a href="{{ route('inventory.index', ['unit' => $unitRow['id']]) }}" class="text-xs font-medium text-indigo-600 hover:text-indigo-700">Open grid →</a>
                        </div>
                    </li>
                @endforeach
            </ul>
        </x-erp.card>

        <x-erp.card title="Recent inventory activity" description="Latest immutable IMEI events in scope." class="xl:col-span-2" flush>
            @if ($recent->isEmpty())
                <p class="px-4 py-8 text-center text-sm text-slate-500">No inventory events yet. Add a phone or receive a purchase to begin the audit trail.</p>
            @else
                <ul class="divide-y divide-slate-100">
                    @foreach ($recent as $event)
                        <li class="flex flex-wrap items-center gap-3 px-4 py-2.5">
                            <x-erp.badge tone="{{ $event->to_custody === \App\Enums\Custody::WithCustomer ? 'indigo' : ($event->event_type === \App\Enums\InventoryEventType::TransferDispatched ? 'amber' : 'green') }}">{{ $event->event_type->label() }}</x-erp.badge>
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-medium text-slate-800">
                                    {{ $event->unit?->displayName() }}
                                    <span class="font-mono text-xs text-slate-500">{{ $event->unit?->primary_imei }}</span>
                                </p>
                                <p class="text-xs text-slate-500">
                                    {{ $event->fromUnit?->name ?? 'External' }} → {{ $event->toUnit?->name ?? $event->to_custody?->label() }}
                                    · {{ $event->actor?->name ?? 'System' }}
                                </p>
                            </div>
                            <time class="text-xs text-slate-500">{{ $event->happened_at?->timezone(config('erp.timezone'))->format('d M, H:i') }}</time>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-erp.card>
    </div>

    <div class="mt-5 grid gap-5 xl:grid-cols-2">
        <x-erp.card title="Accessories at or below reorder point" description="Order before you run out.">
            @if ($lowStock->isEmpty())
                <p class="py-6 text-center text-sm text-slate-500">No accessory is below its reorder point.</p>
            @else
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs uppercase tracking-wider text-slate-500">
                            <th class="pb-2 font-semibold">Item</th>
                            <th class="pb-2 text-right font-semibold">Reorder at</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($lowStock as $variant)
                            <tr>
                                <td class="py-2 text-slate-800">{{ $variant->displayName() }}</td>
                                <td class="py-2 text-right tabular-nums text-slate-600">{{ $variant->reorder_point }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </x-erp.card>

        <div class="rounded-xl border border-dashed border-slate-300 bg-white p-4">
            <h2 class="text-sm font-semibold text-slate-800">Modules not enabled yet</h2>
            <p class="mt-1 text-xs text-slate-600">The following are specified but intentionally absent from this build. No placeholder buttons or fabricated numbers are shown.</p>
            <ul class="mt-3 grid gap-1.5 text-xs text-slate-600 sm:grid-cols-2">
                <li>• Installment schedules (Phase 3)</li>
                <li>• Repairs &amp; exchange (Phase 4)</li>
                <li>• Expenses &amp; payroll (Phase 5)</li>
                <li>• Full reports &amp; exports (Phase 6)</li>
            </ul>
            <p class="mt-3 text-xs text-slate-500">Progress is tracked in <span class="font-mono">IMPLEMENTATION_STATUS.md</span>.</p>
        </div>
    </div>
</div>
