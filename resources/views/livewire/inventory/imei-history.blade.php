<div>
    <x-erp.page-header title="IMEI lifetime history" subtitle="One physical handset, one identity: every acquisition cycle, transfer, repair, sale and return is appended here and never rewritten." />

    <form wire:submit="search" class="mb-5 flex flex-wrap items-end gap-2 rounded-xl border border-slate-200 bg-white p-3 shadow-sm">
        <div class="min-w-[260px] flex-1">
            <label for="imei-search" class="text-xs font-semibold uppercase tracking-wide text-slate-600">Exact IMEI (either slot) or internal serial</label>
            <input id="imei-search" type="search" wire:model="imei" class="mt-1 w-full rounded-lg border-slate-300 font-mono text-sm focus:border-indigo-500 focus:ring-indigo-500" placeholder="e.g. 356789011000001">
        </div>
        <button type="submit" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">Trace</button>
        @error('imei') <p class="text-xs text-rose-600">{{ $message }}</p> @enderror
    </form>

    @if ($notFound)
        <div class="rounded-xl border border-amber-200 bg-amber-50 p-5 text-sm text-amber-900">
            <p class="font-semibold">No handset found for “{{ $imei }}”.</p>
            <p class="mt-1 text-xs">Check the digits, or register the handset through Add phone / a purchase receipt. Unregistered IMEIs are never invented.</p>
        </div>
    @elseif ($unit)
        @php
            $cycle = $unit->activeCycle ?? $unit->cycles->firstWhere('closed_at', null);
            $stateTone = match (true) {
                $unit->current_custody === \App\Enums\Custody::InUnit && $unit->sellability_status === \App\Enums\Sellability::Available => 'green',
                $unit->current_custody === \App\Enums\Custody::WithCustomer => 'indigo',
                $unit->current_custody === \App\Enums\Custody::InTransit => 'amber',
                default => 'slate',
            };
        @endphp

        <div class="grid gap-4 lg:grid-cols-3">
            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm lg:col-span-2">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-widest text-slate-500">Handset</p>
                        <h2 class="mt-1 text-xl font-semibold text-slate-900">{{ $unit->displayName() }}</h2>
                        <p class="mt-1 font-mono text-sm text-slate-700">{{ $unit->primary_imei ?? '—' }}
                            @if ($unit->secondary_imei)
                                <span class="text-slate-400"> / </span>{{ $unit->secondary_imei }}
                            @endif
                        </p>
                    </div>
                    <x-erp.badge :tone="$stateTone">{{ $unit->current_custody->label() }}</x-erp.badge>
                </div>

                <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-3">
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-slate-500">Current unit</dt>
                        <dd class="font-medium text-slate-800">{{ $unit->currentUnit?->name ?? 'Not in a unit' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-slate-500">Sellability</dt>
                        <dd class="font-medium text-slate-800">{{ $unit->sellability_status->label() }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-slate-500">IMEI status</dt>
                        <dd class="font-medium text-slate-800">{{ $unit->imei_status->label() }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-slate-500">Battery / cycles</dt>
                        <dd class="font-medium text-slate-800">{{ $unit->batteryHealthLabel() }} · {{ $unit->cycleCountLabel() }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-slate-500">Condition</dt>
                        <dd class="font-medium text-slate-800">{{ $unit->condition_grade ?? 'Not graded' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-slate-500">Owner since</dt>
                        <dd class="font-medium text-slate-800">{{ $cycle?->acquired_at?->timezone(config('erp.timezone'))->format('d M Y') ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-slate-500">Buying party</dt>
                        <dd class="font-medium text-slate-800">{{ $cycle?->buying_party_name ?? 'Not recorded' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-slate-500">Expected selling price</dt>
                        <dd class="font-medium tabular-nums text-slate-800">{{ $unit->expected_selling_price !== null ? $currency.number_format((float) $unit->expected_selling_price, 2) : 'Not set' }}</dd>
                    </div>
                    @if ($unit->status_note)
                        <div>
                            <dt class="text-xs uppercase tracking-wide text-slate-500">Due / status reason</dt>
                            <dd class="font-medium text-slate-800">{{ $unit->status_note }}</dd>
                        </div>
                    @endif
                    @if ($canSeeCost)
                        <div>
                            <dt class="text-xs uppercase tracking-wide text-slate-500">Current cycle cost</dt>
                            <dd class="font-medium tabular-nums text-slate-800">{{ $cycle ? $currency.number_format((float) $cycle->effective_cost_basis, 2) : 'None' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs uppercase tracking-wide text-slate-500">Lifetime cycles</dt>
                            <dd class="font-medium text-slate-800">{{ $unit->cycles->count() }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs uppercase tracking-wide text-slate-500">Lifetime repair cost</dt>
                            <dd class="font-medium text-slate-800">Repair module not enabled (Phase 4)</dd>
                        </div>
                    @endif
                </dl>

                @if ($unit->imei_flag_reason)
                    <p class="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800">Flagged identity: {{ $unit->imei_flag_reason }}</p>
                @endif
            </div>

            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
                <h3 class="text-sm font-semibold text-slate-900">Identifiers</h3>
                <ul class="mt-2 space-y-1 text-xs">
                    @foreach ($unit->identifiers as $identifier)
                        <li class="flex items-center justify-between gap-2">
                            <span class="font-semibold uppercase tracking-wide text-slate-500">{{ $identifier->identifier_type }}</span>
                            <span class="font-mono text-slate-800">{{ $identifier->normalized_value }}</span>
                        </li>
                    @endforeach
                </ul>
                <p class="mt-3 text-[11px] leading-snug text-slate-500">Uniqueness is enforced across both IMEI slots by the identity table.</p>

                <h4 class="mt-4 text-xs font-semibold uppercase tracking-wide text-slate-600">Adapter / cable</h4>
                @if ($unit->accessories->isEmpty())
                    <p class="mt-1 text-xs text-slate-500">None recorded.</p>
                @else
                    <ul class="mt-1 space-y-1 text-xs">
                        @foreach ($unit->accessories as $accessory)
                            <li class="flex items-center justify-between gap-2">
                                <span class="text-slate-700">{{ \Illuminate\Support\Str::title(strtolower($accessory->accessory_type)) }}: {{ $accessory->name }}</span>
                                <span class="tabular-nums text-slate-600">{{ $accessory->price !== null ? $currency.number_format((float) $accessory->price, 2) : '—' }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
                <a href="{{ route('inventory.index', ['q' => $unit->primary_imei]) }}" class="mt-4 inline-block text-xs font-semibold text-indigo-600 hover:text-indigo-700">Open in inventory grid →</a>
            </div>
        </div>

        <div class="mt-5 grid gap-5 lg:grid-cols-3">
            <x-erp.card title="Acquisition cycles" description="Each return/trade-in opens a new cost basis." flush class="lg:col-span-1">
                <ul class="divide-y divide-slate-100">
                    @foreach ($unit->cycles->sortByDesc('cycle_no') as $cycleRow)
                        <li class="px-4 py-3 text-sm">
                            <div class="flex items-center justify-between">
                                <p class="font-semibold text-slate-800">Cycle {{ $cycleRow->cycle_no }}</p>
                                <x-erp.badge :tone="$cycleRow->closed_at ? 'slate' : 'green'">{{ $cycleRow->closed_at ? 'Closed' : 'Active' }}</x-erp.badge>
                            </div>
                            <p class="mt-1 text-xs text-slate-500">
                                {{ $cycleRow->source_type->label() }}
                                @if ($cycleRow->source instanceof \App\Models\PurchaseLine)
                                    · {{ $cycleRow->source->purchase?->document_no }}
                                @endif
                                @if ($cycleRow->buying_party_name)
                                    · {{ $cycleRow->buying_party_name }}
                                @endif
                            </p>
                            <p class="mt-1 text-xs text-slate-500">
                                {{ $cycleRow->acquired_at?->timezone(config('erp.timezone'))->format('d M Y') }}
                                → {{ $cycleRow->closed_at?->timezone(config('erp.timezone'))->format('d M Y') ?? 'present' }}
                            </p>
                            @if ($canSeeCost)
                                <p class="mt-1 text-xs text-slate-700">Acquired {{ $currency.number_format((float) $cycleRow->acquisition_value, 2) }} · allocated {{ $currency.number_format((float) $cycleRow->allocated_direct_cost, 2) }} · basis {{ $currency.number_format((float) $cycleRow->effective_cost_basis, 2) }}</p>
                            @endif
                            @if ($cycleRow->closing_type)
                                <p class="mt-0.5 text-[11px] text-slate-500">Closed by {{ $cycleRow->closing_type->label() }}</p>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </x-erp.card>

            <x-erp.card title="Event ledger" description="Append-only. Every row has an actor and a business timestamp." flush class="lg:col-span-2">
                <ol class="divide-y divide-slate-100">
                    @foreach ($unit->events as $event)
                        <li class="flex gap-3 px-4 py-3">
                            <div class="mt-1 h-2 w-2 shrink-0 rounded-full bg-indigo-500"></div>
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <p class="text-sm font-semibold text-slate-800">{{ $event->event_type->label() }}</p>
                                    <span class="text-[11px] text-slate-500">{{ $event->happened_at?->timezone(config('erp.timezone'))->format('d M Y, H:i') }}</span>
                                </div>
                                <p class="text-xs text-slate-600">
                                    {{ $event->from_custody?->label() ?? 'New' }} @if ($event->fromUnit) ({{ $event->fromUnit->name }}) @endif
                                    → {{ $event->to_custody?->label() ?? '—' }} @if ($event->toUnit) ({{ $event->toUnit->name }}) @endif
                                </p>
                                <p class="text-[11px] text-slate-500">
                                    By {{ $event->actor?->name ?? 'System' }}
                                    @if ($event->reference)
                                        @php
                                            $referenceLabel = match (true) {
                                                $event->reference instanceof \App\Models\PurchaseLine => $event->reference->purchase?->document_no,
                                                default => $event->reference->document_no ?? $event->reference->transfer_no ?? class_basename($event->reference),
                                            };
                                        @endphp
                                        · Ref {{ $referenceLabel }}
                                    @endif
                                </p>
                                @if (isset($event->metadata['restock_condition']))
                                    @php($condition = $event->metadata['restock_condition'])
                                    <p class="mt-1 text-xs text-amber-800">Restock inspection · battery {{ $condition['before']['battery_health'] ?? 'Unknown' }} → {{ $condition['after']['battery_health'] ?? 'Unknown' }} · grade {{ $condition['before']['condition_grade'] ?? 'Unknown' }} → {{ $condition['after']['condition_grade'] ?? 'Unknown' }}</p>
                                @endif
                                @if ($event->note)
                                    <p class="mt-0.5 text-[11px] italic text-slate-500">{{ $event->note }}</p>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ol>
            </x-erp.card>
        </div>
    @else
        <div class="rounded-xl border border-dashed border-slate-300 bg-white p-8 text-center">
            <p class="text-sm font-medium text-slate-700">Search an IMEI to open its full lifetime.</p>
            <p class="mt-1 text-xs text-slate-500">Transfers, repairs, sales, exchanges and re-acquisitions all appear on the same handset identity.</p>
        </div>
    @endif
</div>
