<div class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_380px]">
    @if($unitTransferOpen)
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 p-4"><form wire:submit="transferSelectedPhone" class="w-full max-w-lg space-y-4 rounded-xl bg-white p-5 shadow-xl"><h2 class="font-semibold">Transfer selected phone</h2><p class="text-sm">{{ \App\Models\SerializedUnit::find($transferPhoneId)?->displayName() }} · {{ \App\Models\SerializedUnit::find($transferPhoneId)?->primaryIdentifier() }}</p><label class="block text-sm">Destination unit<select wire:model="transferDestination" class="mt-1 w-full rounded border-slate-300"><option value="">Select unit</option>@foreach($units->where('id','!=',(int)$unitId) as $destinationUnit)<option value="{{ $destinationUnit->id }}">{{ $destinationUnit->name }}</option>@endforeach</select></label><p class="text-xs text-slate-500">Moves the phone from the current unit into the selected unit and removes it from this sale. A transfer memo will be available to print.</p>@error('unitTransfer')<p class="text-sm text-rose-600">{{ $message }}</p>@enderror @error('transferDestination')<p class="text-sm text-rose-600">{{ $message }}</p>@enderror<div class="flex gap-2"><button type="button" wire:click="$set('unitTransferOpen',false)" class="rounded border px-3 py-2">Close</button><button wire:loading.attr="disabled" class="rounded bg-indigo-600 px-3 py-2 text-white">Transfer now</button></div></form></div>
    @endif

    <div class="min-w-0 space-y-3">
        @if($unit)<livewire:pos.repair-desk :unit-id="$unitId" :key="'repair-desk-'.$unitId" />@endif

    @if($lastUnitTransferId)<div class="mb-3 rounded-lg bg-green-50 p-3 text-sm text-green-800">Unit transfer completed. <a href="{{ route('transfers.memo',$lastUnitTransferId) }}" target="_blank" rel="noopener" class="font-semibold underline">Print transfer cash memo</a></div>@endif

        @can('invoice.view')
            <livewire:payment-reminders :unit-id="$unitId" :key="'pos-reminders-'.$unitId" />
        @endcan
        @can('invoice.view')<livewire:pos.stock-handoff :unit-id="$unitId" :key="'stock-handoff-'.$unitId" />@endcan
        @can('expense.create')
            <livewire:pos.expense-entry :unit-id="$unitId" :key="'pos-expense-'.$unitId" />
        @endcan
        <div class="rounded-xl border border-slate-200 bg-white p-3 shadow-sm">
            <div class="flex flex-wrap items-end gap-3">
                <div class="min-w-[160px]">
                    <label for="pos-unit" class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Unit</label>
                    <select id="pos-unit" wire:model.live="unitId" class="mt-1 w-full rounded-lg border-slate-300 py-1.5 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                        @foreach ($units as $unitOption)
                            <option value="{{ $unitOption->id }}">{{ $unitOption->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="min-w-[260px] flex-1">
                    <label for="pos-customer" class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Customer</label>
                    <div class="mt-1 flex items-center gap-2">
                    <select id="pos-customer" wire:change="selectCustomer($event.target.value)" class="min-w-0 flex-1 rounded-lg border-slate-300 py-1.5 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="">Walk-in customer</option>
                        @foreach ($customers as $customer)
                            <option value="{{ $customer->id }}" @selected($customerId === $customer->id)>{{ $customer->displayName() }}@if ($customer->phone) · {{ $customer->phone }}@endif</option>
                        @endforeach
                    </select>
                    @can('customer.edit')
                        <button type="button" wire:click="openCustomerModal" aria-label="Add new customer" title="Add new customer" class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-indigo-600 text-xl font-semibold text-white hover:bg-indigo-700">+</button>
                    @endcan
                    </div>
                    @error('customerId') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
                @can('inventory.create')
                    <button type="button" wire:click="openStockEntry('phone')" class="rounded-lg border border-green-300 px-3 py-2 text-sm font-semibold text-green-800">+ New phone</button>
                    <button type="button" wire:click="openStockEntry('product')" class="rounded-lg border border-green-300 px-3 py-2 text-sm font-semibold text-green-800">+ New accessory</button>
                @endcan
                @can('exchange.create')
                    <button type="button" wire:click="openExchange" class="rounded-lg bg-indigo-50 px-3 py-2 text-sm font-semibold text-indigo-700">Exchange product</button>
                @endcan
                @if ($selectedCustomer)
                    <span class="rounded-full bg-indigo-50 px-2.5 py-1 text-xs font-medium text-indigo-700">
                        Due {{ $currency }}{{ number_format((float) $selectedCustomer->outstandingBalance()->amount, 2) }}
                        @if ($selectedCustomer->credit_limit > 0)
                            · limit {{ $currency }}{{ number_format((float) $selectedCustomer->credit_limit, 2) }}
                        @endif
                    </span>
                @endif
            </div>
        </div>

        @if ($exchangeEnabled)
            <div class="rounded-xl border border-indigo-200 bg-indigo-50 p-4 text-sm">
                <div class="flex flex-wrap items-start justify-between gap-2"><div><p class="font-semibold text-indigo-900">Incoming exchange product</p><p>{{ $incoming['kind'] === 'PHONE' ? $incoming['brandName'].' '.$incoming['modelName'].' · '.$incoming['primaryImei'] : $incoming['productName'].' · '.$incoming['productSku'] }}</p><p>Buying value: {{ $currency }}{{ number_format((float) $incoming['buyingPrice'], 2) }} · will be in stock</p></div><div class="flex gap-2"><button type="button" wire:click="openExchange" class="font-semibold text-indigo-700 underline">Edit incoming product</button><button type="button" wire:click="removeExchange" class="font-semibold text-rose-700 underline">Remove exchange</button></div></div>
            </div>
        @endif
        <div class="grid gap-3 md:grid-cols-2">
            <div class="relative rounded-xl border border-slate-200 bg-white p-3 shadow-sm">
                <label for="pos-phone-search" class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Add handset by IMEI / model</label>
                <input id="pos-phone-search" type="search" wire:model.live.debounce.300ms="phoneSearch" placeholder="Scan or type IMEI, model, brand…" class="mt-1 w-full rounded-lg border-slate-300 font-mono text-sm focus:border-indigo-500 focus:ring-indigo-500">
                @error('phoneSearch') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                @if ($phoneResults->isNotEmpty())
                    <ul class="absolute z-20 mt-1 w-[calc(100%-1.5rem)] divide-y divide-slate-100 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-lg">
                        @foreach ($phoneResults as $result)
                            <li>
                                <button type="button" wire:click="addPhone({{ $result->id }})" class="flex w-full items-center justify-between gap-2 px-3 py-2 text-left hover:bg-indigo-50">
                                    <span>
                                        <span class="block text-sm font-medium text-slate-800">{{ $result->displayName() }}</span>
                                        <span class="block font-mono text-[11px] text-slate-500">{{ $result->primary_imei }}</span>
                                    </span>
                                    <span class="text-xs font-semibold tabular-nums text-slate-700">{{ $currency }}{{ number_format((float) ($result->expected_selling_price ?? $result->variant?->default_selling_price ?? 0), 2) }}</span>
                                </button>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            <div class="relative rounded-xl border border-slate-200 bg-white p-3 shadow-sm">
                <label for="pos-accessory-search" class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Add accessory</label>
                <input id="pos-accessory-search" type="search" wire:model.live.debounce.300ms="accessorySearch" placeholder="Charger, cable, case, glass…" class="mt-1 w-full rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                @error('accessorySearch') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                @if ($accessoryResults->isNotEmpty())
                    <ul class="absolute z-20 mt-1 w-[calc(100%-1.5rem)] divide-y divide-slate-100 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-lg">
                        @foreach ($accessoryResults as $balance)
                            <li>
                                <button type="button" wire:click="addAccessory({{ $balance->product_variant_id }})" class="flex w-full items-center justify-between gap-2 px-3 py-2 text-left hover:bg-indigo-50">
                                    <span>
                                        <span class="block text-sm font-medium text-slate-800">{{ $balance->variant?->displayName() }}</span>
                                        <span class="block text-[11px] text-slate-500">{{ $balance->qty_on_hand - $balance->qty_reserved }} available</span>
                                    </span>
                                    <span class="text-xs font-semibold tabular-nums text-slate-700">{{ $currency }}{{ number_format((float) $balance->variant?->default_selling_price, 2) }}</span>
                                </button>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>

        <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
            <div class="flex items-center justify-between border-b border-slate-100 px-4 py-2.5">
                <h2 class="text-sm font-semibold text-slate-800">Sale items</h2>
                <span class="text-xs text-slate-500">{{ count($lines) }} line(s)</span>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50 text-left text-[10px] uppercase tracking-wider text-slate-500">
                        <tr>
                            <th class="px-3 py-2 font-semibold">Item</th>
                            <th class="px-3 py-2 font-semibold">IMEI / SKU</th>
                            <th class="px-2 py-2 text-right font-semibold">Qty</th>
                            <th class="px-2 py-2 text-right font-semibold">Price</th>
                            <th class="px-2 py-2 text-right font-semibold">Discount</th>
                            <th class="px-2 py-2 text-right font-semibold">Total</th>
                            <th class="px-2 py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($lines as $index => $line)
                            @php
                                $price = is_numeric($line['unit_price']) ? (float) $line['unit_price'] : 0;
                                $discount = is_numeric($line['discount']) ? (float) $line['discount'] : 0;
                                $lineTotal = ($price * (int) $line['quantity']) - $discount;
                            @endphp
                            <tr wire:key="pos-line-{{ $line['line_type'] }}-{{ $line['reference_id'] }}" class="align-top">
                                <td class="px-3 py-2">
                                    <p class="font-medium text-slate-800">{{ $line['description'] }}</p>
                                    <p class="text-[10px] uppercase tracking-wide text-slate-400">{{ $line['line_type'] }}</p>
                                </td>
                                <td class="px-3 py-2 font-mono text-xs text-slate-600">{{ $line['identifier'] ?? '—' }}</td>
                                <td class="px-2 py-2 text-right">
                                    @if ($line['line_type'] === 'PHONE')
                                        <span class="tabular-nums">1</span>
                                    @else
                                        <input type="number" min="1" max="{{ $line['stock'] }}" wire:model.live="lines.{{ $index }}.quantity" class="w-16 rounded border-slate-300 px-1 py-0.5 text-right text-xs" aria-label="Quantity">
                                    @endif
                                    @error("lines.{$index}.quantity") <p class="text-[10px] text-rose-600">{{ $message }}</p> @enderror
                                </td>
                                <td class="px-2 py-2 text-right">
                                    <input type="text" inputmode="decimal" wire:model.live="lines.{{ $index }}.unit_price" class="w-24 rounded border-slate-300 px-1 py-0.5 text-right text-xs tabular-nums" aria-label="Unit price">
                                    @error("lines.{$index}.unit_price") <p class="text-[10px] text-rose-600">{{ $message }}</p> @enderror
                                </td>
                                <td class="px-2 py-2 text-right">
                                    <input type="text" inputmode="decimal" wire:model.live="lines.{{ $index }}.discount" class="w-20 rounded border-slate-300 px-1 py-0.5 text-right text-xs tabular-nums" aria-label="Line discount">
                                    @error("lines.{$index}.discount") <p class="text-[10px] text-rose-600">{{ $message }}</p> @enderror
                                </td>
                                <td class="px-2 py-2 text-right font-semibold tabular-nums text-slate-800">{{ $currency }}{{ number_format($lineTotal, 2) }}</td>
                                <td class="px-2 py-2 text-right">
                                    @if($line['line_type'] === 'PHONE')@can('stock.transfer.approve')@can('stock.transfer.create')<button type="button" wire:click="openUnitTransfer({{ $line['reference_id'] }})" class="rounded border border-indigo-200 px-2 py-1 text-xs font-semibold text-indigo-700">Unit transfer</button>@endcan
 @endcan
 @endif
                                    @if($line['line_type'] === 'PHONE')
                                        @can('repair.create')
                                            <button type="button" wire:click="$dispatch('open-phone-repair', { phoneId: {{ $line['reference_id'] }} })" class="rounded border border-amber-300 px-2 py-1 text-xs font-semibold text-amber-700">Send to repair</button>
                                        @endcan
                                    @endif
                                    <button type="button" wire:click="removeLine({{ $index }})" class="rounded px-2 py-1 text-xs font-semibold text-rose-600 hover:bg-rose-50">✕</button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-4 py-12 text-center">
                                    <p class="text-sm font-medium text-slate-700">The invoice is empty.</p>
                                    <p class="mt-1 text-xs text-slate-500">Scan an IMEI or search a model/accessory above to add the first line.</p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>


    </div>

    <aside class="space-y-3">
        <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
            <div class="flex items-center justify-between">
                <h2 class="text-sm font-semibold text-slate-800">Invoice summary</h2>
                <span class="text-xs text-slate-500">{{ count($lines) }} items</span>
            </div>

            <dl class="mt-3 space-y-1.5 text-sm">
                <div class="flex justify-between text-slate-600"><dt>Subtotal after line discounts</dt><dd class="tabular-nums">{{ $currency }}{{ number_format((float) $totals['subtotal']->amount, 2) }}</dd></div>
                <div class="flex justify-between text-slate-600"><dt>Line discounts</dt><dd class="tabular-nums">{{ $currency }}{{ number_format((float) $totals['line_discount']->amount, 2) }}</dd></div>
                <div>
                    <label for="pos-invoice-discount" class="text-xs font-medium text-slate-500">Invoice discount ({{ $currency }})</label>
                    <input id="pos-invoice-discount" type="text" inputmode="decimal" wire:model.live="invoiceDiscount" class="mt-1 w-full rounded-lg border-slate-300 py-1 text-right text-sm tabular-nums focus:border-indigo-500 focus:ring-indigo-500">
                    @error('invoiceDiscount') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
                <div class="flex justify-between border-t border-slate-100 pt-2 text-base font-semibold text-slate-900"><dt>Total</dt><dd class="tabular-nums">{{ $currency }}{{ number_format((float) $totals['total']->amount, 2) }}</dd></div>
                @unless ($exchangeEnabled)
                <div class="flex justify-between text-slate-600"><dt>Paid now</dt><dd class="tabular-nums">{{ $currency }}{{ number_format((float) $totals['paid']->amount, 2) }}</dd></div>
                <div class="flex justify-between font-semibold {{ $totals['due']->isPositive() ? 'text-rose-700' : 'text-slate-700' }}"><dt>Due</dt><dd class="tabular-nums">{{ $currency }}{{ number_format((float) $totals['due']->amount, 2) }}</dd></div>
                @endunless
            </dl>

            @unless ($exchangeEnabled)
            <div class="mt-3 flex gap-1.5" role="group" aria-label="Exact payment selection">
                @foreach(['CASH'=>'Exact cash','BANK_TRANSFER'=>'Exact bank','BKASH'=>'Exact bKash'] as $exactMethod=>$exactLabel)
                    <button type="button" wire:click="setExactPayment('{{ $exactMethod }}')" wire:loading.attr="disabled" aria-pressed="{{ $activeExactMethod === $exactMethod ? 'true' : 'false' }}" @class(['flex-1 rounded-lg border px-2 py-1.5 text-[11px] font-semibold', 'border-indigo-600 bg-indigo-600 text-white' => $activeExactMethod === $exactMethod, 'border-slate-300 bg-white text-slate-700 hover:bg-slate-50' => $activeExactMethod !== $exactMethod])>{{ $exactLabel }}</button>
                @endforeach
            </div>

            <div class="mt-3 space-y-2">
                @foreach ($payments as $index => $payment)
                    <div class="rounded-lg border border-slate-200 p-2" wire:key="payment-{{ $paymentVersion }}-{{ $index }}">
                        <div class="grid grid-cols-[1fr_90px_auto] gap-1.5">
                            <select wire:model.live="payments.{{ $index }}.method" class="rounded border-slate-300 py-1 text-xs focus:border-indigo-500 focus:ring-indigo-500" aria-label="Payment method">
                                @foreach ($paymentMethods as $method)
                                    <option value="{{ $method->value }}">{{ $method->label() }}</option>
                                @endforeach
                            </select>
                            <input type="text" inputmode="decimal" wire:model.live="payments.{{ $index }}.amount" placeholder="0.00" class="rounded border-slate-300 px-1.5 py-1 text-right text-xs tabular-nums focus:border-indigo-500 focus:ring-indigo-500" aria-label="Payment amount">
                            <button type="button" wire:click="removePayment({{ $index }})" class="rounded px-1 text-xs text-rose-600 hover:bg-rose-50">✕</button>
                        </div>
                        <input type="text" wire:model="payments.{{ $index }}.reference" placeholder="{{ ($payment['method'] ?? 'CASH') === 'BKASH' ? 'bKash transaction ID (optional)' : (($payment['method'] ?? 'CASH') === 'BANK_TRANSFER' ? 'Bank reference (optional)' : 'Reference (optional)') }}" class="mt-1 w-full rounded border-slate-300 px-1.5 py-1 text-[11px] focus:border-indigo-500 focus:ring-indigo-500">
                        @error("payments.{$index}.amount") <p class="mt-1 text-[10px] text-rose-600">{{ $message }}</p> @enderror
                    </div>
                @endforeach
                <button type="button" wire:click="addPayment" class="w-full rounded-lg border border-dashed border-slate-300 py-1.5 text-[11px] font-semibold text-slate-600 hover:bg-slate-50">+ Split payment</button>
            </div>

            @endunless
            @if ($exchangeEnabled)
                <div class="mt-3 space-y-3 rounded-lg border border-indigo-200 bg-indigo-50 p-3 text-sm">
                    <p class="font-semibold text-indigo-900">Exchange settlement</p>
                    <p>Incoming buying value: {{ $currency }}{{ number_format((float) $incoming['buyingPrice'], 2) }}</p>
                    <p class="font-semibold">{{ $exchangeDifference->isPositive() ? 'Customer adds' : ($exchangeDifference->isNegative() ? 'From counter · pay customer' : 'Even exchange') }}: {{ $currency }}{{ number_format(abs((float) $exchangeDifference->amount), 2) }}</p>
                    <div><label for="exchange-direction" class="text-xs font-semibold">Money direction · automatic</label><input id="exchange-direction" type="text" readonly value="{{ $exchangeDirection === 'COUNTER' ? 'Shop pays from counter' : ($exchangeDirection === 'CUSTOMER' ? 'Customer pays' : 'Even exchange · no money') }}" class="mt-1 w-full rounded-lg border-slate-300 bg-slate-50 text-sm"></div>
                    @if ($exchangeDirection !== 'EVEN')
                        <p class="text-xs text-indigo-800">Split the payment across Cash, Bank, bKash or another method.</p>
                        @foreach ($exchangePayments as $index => $payment)
                            <div wire:key="exchange-payment-{{ $payment['key'] ?? $index }}" class="space-y-2 rounded-lg border border-indigo-200 bg-white p-2">
                                <div class="grid grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] items-center gap-2">
                                    <select wire:model.live="exchangePayments.{{ $index }}.method" aria-label="Exchange payment method {{ $index + 1 }}" class="min-w-0 rounded-lg border-slate-300 text-xs">@foreach ($paymentMethods as $method)<option value="{{ $method->value }}">{{ $method->label() }}</option>@endforeach</select>
                                    <input wire:model.live.debounce.300ms="exchangePayments.{{ $index }}.amount" inputmode="decimal" placeholder="0.00" aria-label="Exchange payment amount {{ $index + 1 }}" class="min-w-0 rounded-lg border-slate-300 text-right text-xs">
                                    <button type="button" wire:click="removeExchangePayment({{ $index }})" aria-label="Remove exchange payment {{ $index + 1 }}" class="rounded px-1 py-2 text-rose-700">×</button>
                                </div>
                                <input wire:model="exchangePayments.{{ $index }}.reference" maxlength="120" placeholder="Reference / transaction ID (optional)" aria-label="Exchange payment reference {{ $index + 1 }}" class="w-full rounded-lg border-slate-300 text-xs">
                                @foreach (['method', 'amount', 'reference'] as $paymentField) @error('exchangePayments.'.$index.'.'.$paymentField)<p class="text-xs text-rose-600">{{ $message }}</p>@enderror @endforeach
                            </div>
                        @endforeach
                        <button type="button" wire:click="addExchangePayment" class="w-full rounded-lg border border-dashed border-indigo-300 px-3 py-2 text-xs font-semibold text-indigo-700">+ Add payment method</button>
                    @endif
                    @error('exchangePayments')<p class="text-xs text-rose-600">{{ $message }}</p>@enderror
                    <div class="flex justify-between text-xs font-semibold"><span>Total {{ $exchangeDirection === 'COUNTER' ? 'paid from counter' : 'payment' }}</span><span>{{ $currency }}{{ $exchangePaid->formatted() }}</span></div>
                    <div class="flex justify-between text-xs font-semibold {{ $exchangeRemaining->isZero() ? 'text-green-700' : 'text-rose-700' }}"><span>{{ $exchangeRemaining->isNegative() ? 'Over by' : 'Remaining to enter' }}</span><span>{{ $currency }}{{ $exchangeRemaining->isNegative() ? \App\Support\Money::zero()->sub($exchangeRemaining)->formatted() : $exchangeRemaining->formatted() }}</span></div>
                    <p class="text-xs text-indigo-800">Walk-in or registered customers can exchange. Payments must match the difference; who pays is calculated automatically.</p>
                    @error('exchange')<p class="text-xs text-rose-700">{{ $message }}</p>@enderror
                </div>
            @endif
            @include('livewire.pos.repayment-plan')

            @error('sale')
                <p class="mt-3 rounded-lg bg-rose-50 px-3 py-2 text-xs text-rose-700">{{ $message }}</p>
            @enderror

            @if (! $exchangeEnabled && $totals['due']->isPositive() && ! $selectedCustomer)
                <p class="mt-2 rounded-lg bg-amber-50 px-3 py-2 text-[11px] text-amber-800">A registered customer is required for the remaining due.</p>
            @endif
            @if (! $exchangeEnabled && $totals['due']->isNegative())
                <p class="mt-2 rounded-lg bg-rose-50 px-3 py-2 text-[11px] text-rose-700">Payments exceed the total. Reduce a tender before posting.</p>
            @endif

            <button type="button" wire:click="complete" wire:loading.attr="disabled" @disabled($lines === []) class="mt-3 w-full rounded-xl bg-indigo-600 px-4 py-3 text-sm font-bold text-white shadow-sm hover:bg-indigo-700 disabled:cursor-not-allowed disabled:bg-slate-300">
                <span wire:loading.remove wire:target="complete">{{ $exchangeEnabled ? 'Post exchange' : 'Post sale' }} · {{ $currency }}{{ number_format((float) $totals['total']->amount, 2) }}</span>
                <span wire:loading wire:target="complete">Posting…</span>
            </button>
            <p class="mt-2 text-[10px] leading-snug text-slate-500">The invoice, IMEI event, cycle close, accessory stock and double-entry journal are posted atomically. Posted invoices never change.</p>
        </div>

        @include('livewire.pos.recent-transactions')

        <div class="rounded-xl border border-slate-200 bg-white p-3 shadow-sm">
            <label for="pos-notes" class="text-[11px] font-semibold uppercase tracking-wide text-slate-500">Invoice note</label>
            <textarea id="pos-notes" wire:model="notes" rows="2" class="mt-1 w-full rounded-lg border-slate-300 text-xs focus:border-indigo-500 focus:ring-indigo-500" placeholder="Warranty, accessories included, etc."></textarea>
        </div>
    </aside>

    @if ($stockEntryOpen)
        <div wire:key="pos-stock-entry-{{ $stockEntryVersion }}" class="fixed inset-0 z-50 overflow-y-auto bg-slate-900/60 p-2 sm:p-5" role="dialog" aria-modal="true" aria-labelledby="quick-stock-title" x-data x-on:keydown.escape.window="$wire.closeStockEntry()">
            <section class="mx-auto max-w-[1600px] rounded-xl bg-white shadow-xl">
                <header class="sticky top-0 z-10 flex items-start justify-between gap-3 rounded-t-xl border-b border-slate-200 bg-white px-4 py-3">
                    <div><h2 id="quick-stock-title" class="text-base font-semibold text-slate-900">Quick stock entry</h2><p class="mt-1 text-xs text-slate-500">Add phones or accessories without leaving POS. Your sale cart and payments stay in place.</p></div>
                    <button type="button" wire:click="closeStockEntry" aria-label="Close quick stock entry" class="shrink-0 rounded-lg border border-slate-300 px-3 py-2 text-sm font-semibold text-slate-700">Close · Back to POS</button>
                </header>
                <div class="min-w-0 p-3 sm:p-5">
                    <livewire:inventory.quick-stock-entry :entry-mode="$stockEntryMode" :receiving-unit="$unitId" :key="'quick-stock-'.$unitId.'-'.$stockEntryVersion" />
                </div>
            </section>
        </div>
    @endif

    @include('livewire.pos.exchange-modal')

    @if ($customerModalOpen)
        <div class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-900/60 p-4 pt-20">
            <form wire:submit="saveCustomer" class="w-full max-w-md rounded-xl bg-white p-5 shadow-xl">
                <h2 class="text-base font-semibold text-slate-900">New customer</h2>
                <div class="mt-3 grid gap-3 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <label class="text-xs font-semibold uppercase tracking-wide text-slate-600">Name</label>
                        <input type="text" wire:model="customerName" class="mt-1 w-full rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                        @error('customerName') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="text-xs font-semibold uppercase tracking-wide text-slate-600">Phone</label>
                        <input type="text" wire:model="customerPhone" class="mt-1 w-full rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                        @error('customerPhone')<p class="text-xs text-rose-600">{{ $message }}</p>@enderror
                    </div>
                    <details class="sm:col-span-2"><summary class="cursor-pointer text-xs font-semibold text-slate-500">Optional: shop type, credit limit and address</summary><div class="mt-3 grid gap-3 sm:grid-cols-2">
                    <div>
                        <label class="text-xs font-semibold uppercase tracking-wide text-slate-600">Type</label>
                        <select wire:model.live="customerType" class="mt-1 w-full rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                            <option value="PERSON">Person</option>
                            <option value="SHOP">Shop / wholesale</option>
                        </select>
                    </div>

                    <div>
                        <label class="text-xs font-semibold uppercase tracking-wide text-slate-600">Credit limit ({{ $currency }})</label>
                        <input type="text" inputmode="decimal" wire:model="customerCreditLimit" class="mt-1 w-full rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                        @error('customerCreditLimit') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <div class="sm:col-span-2">
                        <label class="text-xs font-semibold uppercase tracking-wide text-slate-600">Address</label>
                        <input type="text" wire:model="customerAddress" class="mt-1 w-full rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                    </div>
                    </div></details>
                </div>
                <div class="mt-4 flex justify-end gap-2">
                    <button type="button" wire:click="$set('customerModalOpen', false)" class="rounded-lg border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">Cancel</button>
                    <button type="submit" class="rounded-lg bg-indigo-600 px-3 py-2 text-sm font-semibold text-white hover:bg-indigo-700">Create customer</button>
                </div>
            </form>
        </div>
    @endif
</div>
