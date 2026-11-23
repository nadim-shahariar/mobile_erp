<div class="mx-auto max-w-6xl">
    <x-erp.page-header title="New purchase" subtitle="Draft first, then receive. Receiving registers each IMEI, opens its acquisition cycle and books accessory stock in one transaction.">
        <x-slot:actions>
            <a href="{{ route('purchases.index') }}" class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Back</a>
        </x-slot:actions>
    </x-erp.page-header>

    @error('form')
        <div class="mb-3 rounded-lg border border-rose-200 bg-rose-50 px-4 py-2 text-sm text-rose-800">{{ $message }}</div>
    @enderror

    <form wire:submit="save" class="space-y-4">
        <x-erp.card title="Document">
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <div>
                    <label for="purchase-unit" class="text-xs font-semibold uppercase tracking-wide text-slate-600">Receiving unit</label>
                    <select id="purchase-unit" wire:model="unitId" class="mt-1 w-full rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="">Select unit</option>
                        @foreach ($units as $unit)
                            <option value="{{ $unit->id }}">{{ $unit->name }}</option>
                        @endforeach
                    </select>
                    @error('unitId') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="purchase-date" class="text-xs font-semibold uppercase tracking-wide text-slate-600">Purchase date</label>
                    <input id="purchase-date" type="date" wire:model="purchaseDate" class="mt-1 w-full rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                    @error('purchaseDate') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="purchase-supplier" class="text-xs font-semibold uppercase tracking-wide text-slate-600">Supplier</label>
                    <select id="purchase-supplier" wire:model="supplierId" class="mt-1 w-full rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="">Individual seller / no supplier record</option>
                        @foreach ($suppliers as $supplier)
                            <option value="{{ $supplier->id }}">{{ $supplier->name }}</option>
                        @endforeach
                    </select>
                    @error('supplierId') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    <a href="{{ route('suppliers.index') }}" class="mt-1 inline-block text-[11px] font-medium text-indigo-600 hover:text-indigo-700">Manage suppliers →</a>
                </div>
                <div>
                    <label for="purchase-buying-party" class="text-xs font-semibold uppercase tracking-wide text-slate-600">Buying party (sheet)</label>
                    <input id="purchase-buying-party" type="text" wire:model="buyingParty" maxlength="180" class="mt-1 w-full rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500" placeholder="Overrides the supplier name on handset cycles">
                    @error('buyingParty') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="purchase-reference" class="text-xs font-semibold uppercase tracking-wide text-slate-600">Supplier reference / memo</label>
                    <input id="purchase-reference" type="text" wire:model="reference" maxlength="120" class="mt-1 w-full rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                </div>
                <div>
                    <label for="purchase-method" class="text-xs font-semibold uppercase tracking-wide text-slate-600">Payment method</label>
                    <select id="purchase-method" wire:model="paymentMethod" class="mt-1 w-full rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="CASH">Cash</option>
                        <option value="BANK_TRANSFER">Bank transfer</option>
                        <option value="CARD">Card</option>
                        <option value="BKASH">bKash</option>
                        <option value="NAGAD">Nagad</option>
                        <option value="OTHER">Other</option>
                    </select>
                </div>
                <div class="sm:col-span-2 lg:col-span-3">
                    <label for="purchase-notes" class="text-xs font-semibold uppercase tracking-wide text-slate-600">Notes</label>
                    <textarea id="purchase-notes" wire:model="notes" rows="2" class="mt-1 w-full rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500"></textarea>
                </div>
            </div>
        </x-erp.card>

        <x-erp.card title="Lines" description="One line per physical handset. Accessories are quantity lines.">
            <x-slot:actions>
                <button type="button" wire:click="addPhoneLine" class="rounded-lg bg-indigo-50 px-2.5 py-1.5 text-xs font-semibold text-indigo-700 hover:bg-indigo-100">+ Phone</button>
                <button type="button" wire:click="addAccessoryLine" class="rounded-lg bg-slate-100 px-2.5 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-200">+ Accessory</button>
            </x-slot:actions>

            @error('lines') <p class="mb-2 text-xs text-rose-600">{{ $message }}</p> @enderror

            <div class="space-y-3">
                @foreach ($lines as $index => $line)
                    <div class="rounded-lg border border-slate-200 p-3" wire:key="line-{{ $index }}-{{ $line['line_type'] }}">
                        <div class="mb-2 flex items-center justify-between">
                            <span class="inline-flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide {{ $line['line_type'] === 'PHONE' ? 'text-indigo-700' : 'text-slate-600' }}">
                                {{ $line['line_type'] === 'PHONE' ? 'Serialized phone' : 'Accessory' }}
                            </span>
                            <button type="button" wire:click="removeLine({{ $index }})" class="text-xs font-medium text-rose-600 hover:text-rose-700">Remove</button>
                        </div>

                        <div class="grid gap-3 md:grid-cols-6">
                            <div class="{{ $line['line_type'] === 'PHONE' ? 'md:col-span-4' : 'md:col-span-3' }}">
                                <label class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Item</label>
                                <select wire:model="lines.{{ $index }}.product_variant_id" class="mt-1 w-full rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                                    <option value="">Select…</option>
                                    @foreach ($line['line_type'] === 'PHONE' ? $phoneVariants : $accessoryVariants as $variant)
                                        <option value="{{ $variant->id }}">{{ $variant->displayName() }} — {{ $variant->sku }}</option>
                                    @endforeach
                                </select>
                                @error("lines.{$index}.product_variant_id") <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Qty</label>
                                <input type="number" min="1" wire:model="lines.{{ $index }}.quantity" @readonly($line['line_type'] === 'PHONE') class="mt-1 w-full rounded-lg border-slate-300 text-sm read-only:bg-slate-100 focus:border-indigo-500 focus:ring-indigo-500">
                                @error("lines.{$index}.quantity") <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Unit cost ({{ $currency }})</label>
                                <input type="text" inputmode="decimal" wire:model="lines.{{ $index }}.unit_cost" class="mt-1 w-full rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                                @error("lines.{$index}.unit_cost") <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                            </div>
                        </div>

                        @if ($line['line_type'] === 'PHONE')
                            <div class="mt-3 grid gap-3 border-t border-slate-100 pt-3 md:grid-cols-6">
                                <div class="md:col-span-2">
                                    <label class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">IMEI 1</label>
                                    <input type="text" inputmode="numeric" wire:model="lines.{{ $index }}.imei_1" class="mt-1 w-full rounded-lg border-slate-300 font-mono text-sm focus:border-indigo-500 focus:ring-indigo-500" placeholder="15 digits">
                                    @error("lines.{$index}.imei_1") <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                                </div>
                                <div class="md:col-span-2">
                                    <label class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">IMEI 2</label>
                                    <input type="text" inputmode="numeric" wire:model="lines.{{ $index }}.imei_2" class="mt-1 w-full rounded-lg border-slate-300 font-mono text-sm focus:border-indigo-500 focus:ring-indigo-500">
                                    @error("lines.{$index}.imei_2") <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Battery %</label>
                                    <input type="number" min="0" max="100" wire:model="lines.{{ $index }}.battery_health" class="mt-1 w-full rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                                    @error("lines.{$index}.battery_health") <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Cycles</label>
                                    <input type="number" min="0" wire:model="lines.{{ $index }}.cycle_count" class="mt-1 w-full rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                                    @error("lines.{$index}.cycle_count") <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Grade</label>
                                    <input type="text" maxlength="20" wire:model="lines.{{ $index }}.condition_grade" class="mt-1 w-full rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                                </div>
                                <div class="md:col-span-2">
                                    <label class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Box / kit</label>
                                    <input type="text" maxlength="60" wire:model="lines.{{ $index }}.box_kit" class="mt-1 w-full rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                                </div>
                                <div class="md:col-span-2">
                                    <label class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Expected selling price ({{ $currency }})</label>
                                    <input type="text" inputmode="decimal" wire:model="lines.{{ $index }}.expected_selling_price" class="mt-1 w-full rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                                    @error("lines.{$index}.expected_selling_price") <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                                </div>
                                <div class="md:col-span-4">
                                    <label class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Due / status reason</label>
                                    <input type="text" maxlength="255" wire:model="lines.{{ $index }}.status_note" class="mt-1 w-full rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                                    @error("lines.{$index}.status_note") <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                                </div>
                                <div class="md:col-span-2">
                                    <label class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Adapter name</label>
                                    <input type="text" maxlength="120" wire:model="lines.{{ $index }}.adapter_name" class="mt-1 w-full rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                                    @error("lines.{$index}.adapter_name") <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                                </div>
                                <div class="md:col-span-1">
                                    <label class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Adapter {{ $currency }}</label>
                                    <input type="text" inputmode="decimal" wire:model="lines.{{ $index }}.adapter_price" class="mt-1 w-full rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                                    @error("lines.{$index}.adapter_price") <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                                </div>
                                <div class="md:col-span-2">
                                    <label class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Cable name</label>
                                    <input type="text" maxlength="120" wire:model="lines.{{ $index }}.cable_name" class="mt-1 w-full rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                                    @error("lines.{$index}.cable_name") <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                                </div>
                                <div class="md:col-span-1">
                                    <label class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Cable {{ $currency }}</label>
                                    <input type="text" inputmode="decimal" wire:model="lines.{{ $index }}.cable_price" class="mt-1 w-full rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                                    @error("lines.{$index}.cable_price") <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                                </div>
                                <div class="md:col-span-6">
                                    <label class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Line note</label>
                                    <input type="text" maxlength="500" wire:model="lines.{{ $index }}.notes" class="mt-1 w-full rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                                </div>
                            </div>

                            <div class="mt-3 flex flex-wrap items-center gap-4 rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-600">
                                @can('inventory.imei.override')
                                    <label class="flex items-center gap-1.5">
                                        <input type="checkbox" wire:model.live="lines.{{ $index }}.allow_legacy" class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                                        Legacy / unverified IMEI
                                    </label>
                                @endcan
                                <label class="flex items-center gap-1.5">
                                    <input type="checkbox" wire:model.live="lines.{{ $index }}.generate_internal_serial" class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                                    No IMEI — generate internal serial
                                </label>
                                @if ($line['allow_legacy'] || $line['generate_internal_serial'])
                                    <input type="text" wire:model="lines.{{ $index }}.flag_reason" placeholder="Reason (required)" class="min-w-[220px] flex-1 rounded border-slate-300 py-1 text-xs focus:border-indigo-500 focus:ring-indigo-500">
                                    @error("lines.{$index}.flag_reason") <span class="text-rose-600">{{ $message }}</span> @enderror
                                @endif
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        </x-erp.card>

        <div class="grid gap-4 lg:grid-cols-3">
            <x-erp.card title="Invoice totals" class="lg:col-span-2">
                <div class="grid gap-4 sm:grid-cols-3">
                    <div>
                        <label for="purchase-discount" class="text-xs font-semibold uppercase tracking-wide text-slate-600">Discount</label>
                        <input id="purchase-discount" type="text" inputmode="decimal" wire:model.live="discountTotal" class="mt-1 w-full rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                        @error('discountTotal') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="purchase-charges" class="text-xs font-semibold uppercase tracking-wide text-slate-600">Other charges</label>
                        <input id="purchase-charges" type="text" inputmode="decimal" wire:model.live="otherCharges" class="mt-1 w-full rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                        @error('otherCharges') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="purchase-paid" class="text-xs font-semibold uppercase tracking-wide text-slate-600">Paid now</label>
                        <input id="purchase-paid" type="text" inputmode="decimal" wire:model.live="paidAmount" class="mt-1 w-full rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                        @error('paidAmount') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
                </div>
                <dl class="mt-4 space-y-1.5 text-sm">
                    <div class="flex justify-between text-slate-600"><dt>Subtotal</dt><dd class="tabular-nums">{{ $currency }}{{ number_format((float) $subtotal->amount, 2) }}</dd></div>
                    <div class="flex justify-between text-slate-600"><dt>Total</dt><dd class="tabular-nums">{{ $currency }}{{ number_format((float) $total->amount, 2) }}</dd></div>
                    <div class="flex justify-between font-semibold {{ $balance->isPositive() ? 'text-rose-700' : 'text-slate-800' }}"><dt>Balance due</dt><dd class="tabular-nums">{{ $currency }}{{ number_format((float) $balance->amount, 2) }}</dd></div>
                </dl>
                <p class="mt-3 text-[11px] leading-snug text-slate-500">Purchase payments settle supplier payable; they are never recorded as operating expense. Ledger posting arrives with the accounting module (Phase 2).</p>
            </x-erp.card>

            <x-erp.card title="Commit">
                @if ($canReceive)
                    <label class="flex items-start gap-2 text-sm text-slate-700">
                        <input type="checkbox" wire:model="receiveNow" class="mt-0.5 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                        <span>Receive into stock immediately</span>
                    </label>
                    <p class="mt-1 text-[11px] text-slate-500">Uncheck to save a draft that does not change stock.</p>
                @else
                    <p class="text-xs text-slate-600">You can save drafts. A manager with <span class="font-mono">purchase.approve</span> must receive stock.</p>
                @endif
                <button type="submit" class="mt-4 w-full rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700" wire:loading.attr="disabled">
                    <span wire:loading.remove>Save purchase</span>
                    <span wire:loading>Saving…</span>
                </button>
                <a href="{{ route('purchases.index') }}" class="mt-2 block w-full rounded-lg border border-slate-300 px-4 py-2 text-center text-sm font-medium text-slate-700 hover:bg-slate-50">Cancel</a>
            </x-erp.card>
        </div>
    </form>
</div>
