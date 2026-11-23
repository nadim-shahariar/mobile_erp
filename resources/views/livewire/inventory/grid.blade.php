<div>
    <x-erp.page-header title="Inventory" subtitle="IMEI-level handset stock with live custody, sellability and cost basis. Edits are audited and never overwrite IMEI history.">
        <x-slot:actions>
            @can('inventory.create')
                <a href="{{ route('inventory.create') }}" class="rounded-lg bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700">Add stock</a>
            @endcan
            @can('inventory.import')
                <a href="{{ route('inventory.bulk') }}" class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Bulk entry</a>
                <a href="{{ route('imports.phones') }}" class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Import Excel/CSV</a>
            @endcan
        </x-slot:actions>
    </x-erp.page-header>

    @error('grid')
        <div class="mb-3 rounded-lg border border-rose-200 bg-rose-50 px-4 py-2 text-sm text-rose-800">{{ $message }}</div>
    @enderror

    <div class="mb-4 rounded-xl border border-slate-200 bg-white p-3 shadow-sm">
        <div class="grid gap-2 md:grid-cols-2 xl:grid-cols-6">
            <div class="xl:col-span-2">
                <label for="grid-search" class="sr-only">Search</label>
                <input id="grid-search" type="search" wire:model.live.debounce.400ms="search" placeholder="IMEI, model, SKU, brand…" class="w-full rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
            </div>
            <select wire:model.live="unitFilter" aria-label="Unit" class="rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                <option value="all">All my units</option>
                @foreach ($businessUnits as $businessUnit)
                    <option value="{{ $businessUnit->id }}">{{ $businessUnit->name }}</option>
                @endforeach
            </select>
            <select wire:model.live="custody" aria-label="Custody" class="rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                <option value="all">Any custody</option>
                @foreach ($custodyCases as $case)
                    <option value="{{ $case->value }}">{{ $case->label() }}</option>
                @endforeach
            </select>
            <select wire:model.live="sellability" aria-label="Sellability" class="rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                <option value="all">Any sellability</option>
                @foreach ($sellabilityCases as $case)
                    <option value="{{ $case->value }}">{{ $case->label() }}</option>
                @endforeach
            </select>
            <select wire:model.live="brand" aria-label="Brand" class="rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                <option value="all">Any brand</option>
                @foreach ($brands as $brandOption)
                    <option value="{{ $brandOption->id }}">{{ $brandOption->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="mt-2 flex flex-wrap items-center gap-2 text-xs text-slate-500">
            <select wire:model.live="condition" aria-label="Condition" class="rounded-lg border-slate-300 py-1 text-xs focus:border-indigo-500 focus:ring-indigo-500">
                <option value="all">Any grade</option>
                @foreach ($conditions as $grade)
                    <option value="{{ $grade }}">{{ $grade }}</option>
                @endforeach
            </select>
            <select wire:model.live="sort" aria-label="Sort" class="rounded-lg border-slate-300 py-1 text-xs focus:border-indigo-500 focus:ring-indigo-500">
                <option value="recent">Last activity</option>
                <option value="imei">IMEI</option>
                <option value="condition">Condition</option>
                <option value="battery">Battery</option>
            </select>
            <select wire:model.live="direction" aria-label="Direction" class="rounded-lg border-slate-300 py-1 text-xs focus:border-indigo-500 focus:ring-indigo-500">
                <option value="desc">Descending</option>
                <option value="asc">Ascending</option>
            </select>
            <select wire:model.live="perPage" aria-label="Rows per page" class="rounded-lg border-slate-300 py-1 text-xs focus:border-indigo-500 focus:ring-indigo-500">
                <option value="10">10 rows</option>
                <option value="25">25 rows</option>
                <option value="50">50 rows</option>
                <option value="100">100 rows</option>
            </select>
            <button type="button" wire:click="clearFilters" class="rounded-lg border border-slate-300 bg-white px-2 py-1 font-medium text-slate-700 hover:bg-slate-50">Clear filters</button>
            <span wire:loading class="font-medium text-indigo-600">Querying…</span>
            <span class="ml-auto">Showing {{ $units->total() }} handset(s)</span>
        </div>
    </div>

    <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
        <div class="overflow-x-auto">
            <table class="min-w-[1750px] divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50">
                    <tr class="text-left text-[11px] uppercase tracking-wider text-slate-500">
                        <th class="px-2 py-2.5 font-semibold">#</th>
                        <th class="px-3 py-2.5 font-semibold">Device</th>
                        <th class="px-3 py-2.5 font-semibold">IMEI 1</th>
                        <th class="px-3 py-2.5 font-semibold">IMEI 2</th>
                        <th class="px-2 py-2.5 text-right font-semibold">Battery</th>
                        <th class="px-2 py-2.5 text-right font-semibold">Cycles</th>
                        <th class="px-2 py-2.5 font-semibold">Grade</th>
                        <th class="px-3 py-2.5 font-semibold">Box / kit</th>
                        <th class="px-3 py-2.5 font-semibold">Unit</th>
                        <th class="px-3 py-2.5 font-semibold">Buying party</th>
                        <th class="px-3 py-2.5 font-semibold">State</th>
                        @if ($canSeeCost)
                            <th class="px-3 py-2.5 text-right font-semibold">Cost basis</th>
                            <th class="px-3 py-2.5 font-semibold">Received</th>
                        @endif
                        <th class="px-3 py-2.5 text-right font-semibold">Selling price</th>
                        @if ($canSeeCost)
                            <th class="px-3 py-2.5 text-right font-semibold">Projected margin</th>
                        @endif
                        <th class="px-3 py-2.5 font-semibold">Adapter / cable</th>
                        <th class="px-3 py-2.5 font-semibold">Last activity</th>
                        <th class="px-3 py-2.5 text-right font-semibold">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($units as $index => $unit)
                        @php
                            $cycle = $unit->activeCycle;
                            $sourceLine = $cycle?->source;
                            $purchase = $sourceLine instanceof \App\Models\PurchaseLine ? $sourceLine->purchase : null;
                            $stateTone = match (true) {
                                $unit->current_custody === \App\Enums\Custody::InTransit => 'amber',
                                $unit->current_custody === \App\Enums\Custody::AtRepairVendor => 'blue',
                                $unit->current_custody === \App\Enums\Custody::WithCustomer => 'indigo',
                                $unit->sellability_status === \App\Enums\Sellability::Available && $unit->current_custody === \App\Enums\Custody::InUnit => 'green',
                                default => 'slate',
                            };
                        @endphp
                        <tr wire:key="handset-{{ $unit->id }}" class="align-middle hover:bg-slate-50/70">
                            <td class="px-2 py-2 text-xs tabular-nums text-slate-500">{{ $units->firstItem() + $index }}</td>
                            <td class="px-3 py-2">
                                <p class="font-medium text-slate-900">{{ $unit->variant?->product?->brand?->name }} {{ $unit->variant?->product?->name }}</p>
                                <p class="text-xs text-slate-500">
                                    {{ collect([$unit->variant?->storage, $unit->variant?->ram, $unit->variant?->color])->filter()->implode(' · ') ?: 'No variant attributes' }}
                                </p>
                            </td>
                            <td class="px-3 py-2 font-mono text-xs text-slate-800">
                                {{ $unit->primary_imei ?? '—' }}
                                @if ($unit->imei_status !== \App\Enums\ImeiStatus::Valid)
                                    <span class="mt-0.5 block text-[10px] font-semibold uppercase text-amber-600">{{ $unit->imei_status->label() }}</span>
                                @endif
                            </td>
                            <td class="px-3 py-2 font-mono text-xs text-slate-600">{{ $unit->secondary_imei ?? '—' }}</td>
                            <td class="px-2 py-2 text-right tabular-nums text-slate-700">
                                @if ($editingId === $unit->id)
                                    <input type="number" min="0" max="100" wire:model="edit.battery_health" class="w-16 rounded border-slate-300 px-1 py-0.5 text-xs" aria-label="Battery health">
                                @else
                                    {{ $unit->battery_health === null ? 'Unknown' : $unit->battery_health.'%' }}
                                @endif
                            </td>
                            <td class="px-2 py-2 text-right tabular-nums text-slate-700">
                                @if ($editingId === $unit->id)
                                    <input type="number" min="0" wire:model="edit.cycle_count" class="w-16 rounded border-slate-300 px-1 py-0.5 text-xs" aria-label="Cycle count">
                                @else
                                    {{ $unit->cycle_count === null ? 'Unknown' : $unit->cycle_count }}
                                @endif
                            </td>
                            <td class="px-2 py-2 text-slate-700">
                                @if ($editingId === $unit->id)
                                    <input type="text" wire:model="edit.condition_grade" maxlength="20" class="w-16 rounded border-slate-300 px-1 py-0.5 text-xs" aria-label="Condition grade">
                                @else
                                    {{ $unit->condition_grade ?? '—' }}
                                @endif
                            </td>
                            <td class="px-3 py-2 text-slate-700">
                                @if ($editingId === $unit->id)
                                    <input type="text" wire:model="edit.box_kit" maxlength="60" class="w-32 rounded border-slate-300 px-1 py-0.5 text-xs" aria-label="Box and kit">
                                @else
                                    {{ $unit->box_kit ?? '—' }}
                                @endif
                            </td>
                            <td class="px-3 py-2 text-slate-700">{{ $unit->currentUnit?->name ?? '—' }}</td>
                            <td class="px-3 py-2 text-xs text-slate-700">
                                {{ $cycle?->buying_party_name ?? $purchase?->supplier?->name ?? '—' }}
                                <span class="mt-0.5 block text-[10px] uppercase tracking-wide text-slate-400">{{ $cycle?->source_type->label() ?? 'No cycle' }}</span>
                            </td>
                            <td class="px-3 py-2">
                                <x-erp.badge :tone="$stateTone">{{ $unit->current_custody->label() }}</x-erp.badge>
                                <span class="mt-0.5 block text-[10px] uppercase tracking-wide text-slate-500">{{ $unit->sellability_status->label() }}</span>
                                @if ($editingId === $unit->id)
                                    <input type="text" wire:model="edit.status_note" maxlength="255" placeholder="Due / reason" class="mt-1 w-36 rounded border-slate-300 px-1 py-0.5 text-[11px]">
                                @elseif ($unit->status_note)
                                    <span class="mt-1 block max-w-36 truncate text-[11px] italic text-slate-500" title="{{ $unit->status_note }}">{{ $unit->status_note }}</span>
                                @endif
                            </td>
                            @if ($canSeeCost)
                                <td class="px-3 py-2 text-right tabular-nums text-slate-800">
                                    @if ($cycle)
                                        {{ config('erp.currency.symbol') }}{{ number_format((float) $cycle->effective_cost_basis, 2) }}
                                        <span class="block text-[10px] uppercase tracking-wide text-slate-400">cycle {{ $cycle->cycle_no }} · {{ $cycle->source_type->label() }}</span>
                                    @else
                                        <span class="text-xs text-slate-400">No open cycle</span>
                                    @endif
                                </td>
                                <td class="px-3 py-2 text-xs text-slate-600">
                                    <p>{{ $cycle?->acquired_at?->timezone(config('erp.timezone'))->format('d M Y') ?? '—' }}</p>
                                    <p class="text-slate-400">{{ $purchase?->document_no ?? 'Manual entry' }}</p>
                                </td>
                            @endif
                            <td class="px-3 py-2 text-right tabular-nums text-slate-700">
                                @if ($editingId === $unit->id)
                                    <input type="text" inputmode="decimal" wire:model="edit.expected_selling_price" class="w-24 rounded border-slate-300 px-1 py-0.5 text-xs" aria-label="Expected selling price">
                                @elseif ($unit->expected_selling_price !== null)
                                    {{ config('erp.currency.symbol') }}{{ number_format((float) $unit->expected_selling_price, 2) }}
                                @else
                                    <span class="text-xs text-slate-400">Not set</span>
                                @endif
                            </td>
                            @if ($canSeeCost)
                                <td class="px-3 py-2 text-right tabular-nums">
                                    @if ($cycle && $unit->expected_selling_price !== null)
                                        @php($margin = (float) $unit->expected_selling_price - (float) $cycle->effective_cost_basis)
                                        <span class="{{ $margin >= 0 ? 'text-emerald-700' : 'text-rose-700' }}">{{ $margin >= 0 ? '+' : '' }}{{ config('erp.currency.symbol') }}{{ number_format($margin, 2) }}</span>
                                        <span class="block text-[10px] uppercase tracking-wide text-slate-400">projected</span>
                                    @else
                                        <span class="text-xs text-slate-400">—</span>
                                    @endif
                                </td>
                            @endif
                            <td class="px-3 py-2">
                                @if ($unit->accessories->isEmpty())
                                    <span class="text-xs text-slate-400">None</span>
                                @else
                                    <ul class="space-y-0.5 text-xs text-slate-700">
                                        @foreach ($unit->accessories as $accessory)
                                            <li class="whitespace-nowrap">{{ \Illuminate\Support\Str::title(strtolower($accessory->accessory_type)) }}: {{ $accessory->name }}@if ((float) $accessory->price > 0) <span class="text-slate-500">· {{ config('erp.currency.symbol') }}{{ number_format((float) $accessory->price, 2) }}</span>@endif</li>
                                        @endforeach
                                    </ul>
                                @endif
                                @if ($canUpdate && $unit->current_custody !== \App\Enums\Custody::WithCustomer)
                                    <button type="button" wire:click="openExtras({{ $unit->id }})" class="mt-1 text-[11px] font-semibold text-indigo-600 hover:text-indigo-700">Edit bundle</button>
                                @endif
                            </td>
                            <td class="px-3 py-2 text-xs text-slate-600">
                                @if ($unit->lastEvent)
                                    <p>{{ $unit->lastEvent->event_type->label() }}</p>
                                    <p class="text-slate-400">{{ $unit->lastEvent->happened_at?->timezone(config('erp.timezone'))->format('d M, H:i') }}</p>
                                @else
                                    <span class="text-slate-400">No event</span>
                                @endif
                            </td>
                            <td class="px-3 py-2">
                                <div class="flex flex-wrap items-center justify-end gap-1">
                                    @if ($editingId === $unit->id)
                                        <button type="button" wire:click="saveEdit" class="rounded bg-emerald-600 px-2 py-1 text-xs font-semibold text-white hover:bg-emerald-700">Save</button>
                                        <button type="button" wire:click="cancelEdit" class="rounded border border-slate-300 px-2 py-1 text-xs font-medium text-slate-600 hover:bg-slate-100">Cancel</button>
                                    @else
                                        <a href="{{ route('imei.history', $unit->primary_imei ?? $unit->id) }}" class="rounded border border-slate-300 px-2 py-1 text-xs font-medium text-slate-700 hover:bg-slate-100">History</a>
                                        @if ($canUpdate && $unit->current_custody !== \App\Enums\Custody::WithCustomer)
                                            <button type="button" wire:click="startEdit({{ $unit->id }})" class="rounded border border-slate-300 px-2 py-1 text-xs font-medium text-slate-700 hover:bg-slate-100">Edit</button>
                                            <button type="button" wire:click="openIdentifiers({{ $unit->id }})" class="rounded border border-slate-300 px-2 py-1 text-xs font-medium text-slate-700 hover:bg-slate-100">IMEI</button>
                                        @endif
                                        @can('stock.transfer.create')
                                            @if ($unit->current_custody === \App\Enums\Custody::InUnit && $unit->sellability_status === \App\Enums\Sellability::Available)
                                                <a href="{{ route('transfers.create', ['phone' => $unit->id]) }}" class="rounded border border-slate-300 px-2 py-1 text-xs font-medium text-slate-700 hover:bg-slate-100">Transfer</a>
                                            @endif
                                        @endcan
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ $canSeeCost ? 18 : 15 }}" class="px-4 py-12 text-center">
                                <p class="text-sm font-medium text-slate-700">No handsets match these filters.</p>
                                <p class="mt-1 text-xs text-slate-500">Adjust the filters, or add a phone / receive a purchase to create real stock.</p>
                                @can('inventory.create')
                                    <a href="{{ route('inventory.create') }}" class="mt-3 inline-block rounded-lg bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-indigo-700">Add stock</a>
                                @endcan
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($units->hasPages())
            <div class="border-t border-slate-200 px-4 py-3">{{ $units->links() }}</div>
        @endif
    </div>

    @if ($identifierUnitId !== null)
        <div class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-900/60 p-4 pt-16" wire:keydown.escape="closeIdentifiers">
            <div class="w-full max-w-lg rounded-xl bg-white p-5 shadow-xl">
                <h2 class="text-base font-semibold text-slate-900">Correct IMEI identifiers</h2>
                <p class="mt-1 text-xs text-slate-500">Uniqueness is enforced across both slots. The old values stay in the audit log; a handset with a customer cannot be changed.</p>

                @error('identifierForm')
                    <p class="mt-2 rounded-lg bg-rose-50 px-3 py-2 text-xs text-rose-700">{{ $message }}</p>
                @enderror

                <form wire:submit="saveIdentifiers" class="mt-4 space-y-3">
                    <div>
                        <label for="imei-primary" class="text-xs font-semibold uppercase tracking-wide text-slate-600">IMEI 1</label>
                        <input id="imei-primary" type="text" wire:model="identifierForm.primary_imei" class="mt-1 w-full rounded-lg border-slate-300 font-mono text-sm focus:border-indigo-500 focus:ring-indigo-500">
                        @error('identifierForm.primary_imei') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="imei-secondary" class="text-xs font-semibold uppercase tracking-wide text-slate-600">IMEI 2</label>
                        <input id="imei-secondary" type="text" wire:model="identifierForm.secondary_imei" class="mt-1 w-full rounded-lg border-slate-300 font-mono text-sm focus:border-indigo-500 focus:ring-indigo-500">
                        @error('identifierForm.secondary_imei') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <label class="flex items-start gap-2 text-xs text-slate-600">
                        <input type="checkbox" wire:model="identifierForm.allow_legacy" class="mt-0.5 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                        <span>Keep as legacy / unverified IMEI (requires the IMEI override permission and a reason).</span>
                    </label>
                    <div>
                        <label for="imei-reason" class="text-xs font-semibold uppercase tracking-wide text-slate-600">Reason</label>
                        <input id="imei-reason" type="text" wire:model="identifierForm.flag_reason" class="mt-1 w-full rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                        @error('identifierForm.flag_reason') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <div class="flex justify-end gap-2 pt-1">
                        <button type="button" wire:click="closeIdentifiers" class="rounded-lg border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">Cancel</button>
                        <button type="submit" class="rounded-lg bg-indigo-600 px-3 py-2 text-sm font-semibold text-white hover:bg-indigo-700">Save identifiers</button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    @if ($extrasUnitId !== null)
        <div class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-900/60 p-4 pt-16" wire:keydown.escape="closeExtras">
            <div class="w-full max-w-lg rounded-xl bg-white p-5 shadow-xl">
                <h2 class="text-base font-semibold text-slate-900">Adapter / cable bundle</h2>
                <p class="mt-1 text-xs text-slate-500">Recorded on the handset row. Prices do not change the acquisition cost basis and every change is audited.</p>

                @error('extrasForm')
                    <p class="mt-2 rounded-lg bg-rose-50 px-3 py-2 text-xs text-rose-700">{{ $message }}</p>
                @enderror

                <form wire:submit="saveExtras" class="mt-4 grid gap-3 sm:grid-cols-2">
                    <div>
                        <label for="extras-adapter" class="text-xs font-semibold uppercase tracking-wide text-slate-600">Adapter name</label>
                        <input id="extras-adapter" type="text" wire:model="extrasForm.adapter_name" maxlength="120" class="mt-1 w-full rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                        @error('extrasForm.adapter_name') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="extras-adapter-price" class="text-xs font-semibold uppercase tracking-wide text-slate-600">Adapter price</label>
                        <input id="extras-adapter-price" type="text" inputmode="decimal" wire:model="extrasForm.adapter_price" class="mt-1 w-full rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                        @error('extrasForm.adapter_price') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="extras-cable" class="text-xs font-semibold uppercase tracking-wide text-slate-600">Cable name</label>
                        <input id="extras-cable" type="text" wire:model="extrasForm.cable_name" maxlength="120" class="mt-1 w-full rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                        @error('extrasForm.cable_name') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="extras-cable-price" class="text-xs font-semibold uppercase tracking-wide text-slate-600">Cable price</label>
                        <input id="extras-cable-price" type="text" inputmode="decimal" wire:model="extrasForm.cable_price" class="mt-1 w-full rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                        @error('extrasForm.cable_price') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <div class="flex justify-end gap-2 pt-1 sm:col-span-2">
                        <button type="button" wire:click="closeExtras" class="rounded-lg border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">Cancel</button>
                        <button type="submit" class="rounded-lg bg-indigo-600 px-3 py-2 text-sm font-semibold text-white hover:bg-indigo-700">Save bundle</button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
