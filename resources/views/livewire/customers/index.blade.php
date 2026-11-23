<div>
    <x-erp.page-header title="Customers & shops" subtitle="Retail customers and wholesale shops with credit limits and outstanding invoices. Collections recover receivables; they are never new revenue.">
        <x-slot:actions>
            @if ($canEdit)
                <button type="button" wire:click="openModal" class="rounded-lg bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700">New customer</button>
            @endif
        </x-slot:actions>
    </x-erp.page-header>

    @error('collection') <div class="mb-3 rounded-lg border border-rose-200 bg-rose-50 px-4 py-2 text-sm text-rose-800">{{ $message }}</div> @enderror

    <div class="mb-4">
        <input type="search" wire:model.live.debounce.400ms="search" placeholder="Name, business, phone, email…" class="w-full max-w-md rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500" aria-label="Search customers">
    </div>

    <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-left text-[11px] uppercase tracking-wider text-slate-500">
                    <tr>
                        <th class="px-4 py-2.5 font-semibold">Customer</th>
                        <th class="px-4 py-2.5 font-semibold">Type</th>
                        <th class="px-4 py-2.5 font-semibold">Phone</th>
                        <th class="px-4 py-2.5 text-right font-semibold">Invoices</th>
                        <th class="px-4 py-2.5 text-right font-semibold">Credit limit</th>
                        <th class="px-4 py-2.5 text-right font-semibold">Outstanding</th>
                        <th class="px-4 py-2.5">Status</th>
                        <th class="px-4 py-2.5"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($customers as $customer)
                        <tr wire:key="customer-{{ $customer->id }}" class="hover:bg-slate-50/70">
                            <td class="px-4 py-2.5">
                                <p class="font-medium text-slate-800">{{ $customer->displayName() }}</p>
                                @if ($customer->business_name)
                                    <p class="text-xs text-slate-500">{{ $customer->name }}</p>
                                @endif
                            </td>
                            <td class="px-4 py-2.5"><x-erp.badge :tone="$customer->type->value === 'SHOP' ? 'indigo' : 'slate'">{{ $customer->type->label() }}</x-erp.badge></td>
                            <td class="px-4 py-2.5 text-slate-600">{{ $customer->phone ?? '—' }}</td>
                            <td class="px-4 py-2.5 text-right tabular-nums text-slate-700">{{ $customer->sales_count }}</td>
                            <td class="px-4 py-2.5 text-right tabular-nums text-slate-700">{{ (float) $customer->credit_limit > 0 ? $currency.number_format((float) $customer->credit_limit, 2) : '—' }}</td>
                            <td class="px-4 py-2.5 text-right tabular-nums font-medium {{ (float) ($customer->due_sum ?? 0) > 0 ? 'text-rose-700' : 'text-slate-500' }}">{{ $currency }}{{ number_format((float) ($customer->due_sum ?? 0), 2) }}</td>
                            <td class="px-4 py-2.5"><x-erp.badge :tone="$customer->is_active ? 'green' : 'slate'">{{ $customer->is_active ? 'Active' : 'Inactive' }}</x-erp.badge></td>
                            <td class="px-4 py-2.5 text-right">
                                @if ($canCollect && (float) ($customer->due_sum ?? 0) > 0)
                                    <button type="button" wire:click="openCollect({{ $customer->id }})" class="rounded border border-emerald-300 px-2 py-1 text-xs font-semibold text-emerald-700 hover:bg-emerald-50">Collect</button>
                                @endif
                                @if ($canEdit)
                                    <button type="button" wire:click="openModal({{ $customer->id }})" class="rounded border border-slate-300 px-2 py-1 text-xs font-medium text-slate-700 hover:bg-slate-100">Edit</button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="px-4 py-12 text-center text-sm text-slate-500">No customers yet. Create one here or from the POS.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($customers->hasPages())
            <div class="border-t border-slate-200 px-4 py-3">{{ $customers->links() }}</div>
        @endif
    </div>

    @if ($modalOpen)
        <div class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-900/60 p-4 pt-16">
            <form wire:submit="save" class="w-full max-w-xl rounded-xl bg-white p-5 shadow-xl">
                <h2 class="text-base font-semibold text-slate-900">{{ $editingId ? 'Edit customer' : 'New customer' }}</h2>
                <div class="mt-3 grid gap-3 sm:grid-cols-2">
                    <div>
                        <label class="text-xs font-semibold uppercase tracking-wide text-slate-600">Type</label>
                        <select wire:model.live="type" class="mt-1 w-full rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                            <option value="PERSON">Person</option>
                            <option value="SHOP">Shop / wholesale</option>
                        </select>
                    </div>
                    <div>
                        <label class="text-xs font-semibold uppercase tracking-wide text-slate-600">Name</label>
                        <input type="text" wire:model="name" class="mt-1 w-full rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                        @error('name') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="text-xs font-semibold uppercase tracking-wide text-slate-600">Business name</label>
                        <input type="text" wire:model="businessName" class="mt-1 w-full rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                    </div>
                    <div>
                        <label class="text-xs font-semibold uppercase tracking-wide text-slate-600">Phone</label>
                        <input type="text" wire:model="phone" class="mt-1 w-full rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                    </div>
                    <div>
                        <label class="text-xs font-semibold uppercase tracking-wide text-slate-600">Email</label>
                        <input type="email" wire:model="email" class="mt-1 w-full rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                        @error('email') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="text-xs font-semibold uppercase tracking-wide text-slate-600">Credit limit ({{ $currency }})</label>
                        <input type="text" inputmode="decimal" wire:model="creditLimit" class="mt-1 w-full rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                        @error('creditLimit') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <div class="sm:col-span-2">
                        <label class="text-xs font-semibold uppercase tracking-wide text-slate-600">Address</label>
                        <input type="text" wire:model="address" class="mt-1 w-full rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                    </div>
                    <div class="sm:col-span-2">
                        <label class="text-xs font-semibold uppercase tracking-wide text-slate-600">Notes</label>
                        <textarea wire:model="notes" rows="2" class="mt-1 w-full rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500"></textarea>
                    </div>
                    <label class="flex items-center gap-2 text-sm text-slate-700 sm:col-span-2">
                        <input type="checkbox" wire:model="isActive" class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"> Active
                    </label>
                </div>
                <div class="mt-4 flex justify-end gap-2">
                    <button type="button" wire:click="$set('modalOpen', false)" class="rounded-lg border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">Cancel</button>
                    <button type="submit" class="rounded-lg bg-indigo-600 px-3 py-2 text-sm font-semibold text-white hover:bg-indigo-700">Save customer</button>
                </div>
            </form>
        </div>
    @endif

    @if ($collectOpen)
        <div class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-900/60 p-4 pt-16">
            <form wire:submit="saveCollection" class="w-full max-w-md rounded-xl bg-white p-5 shadow-xl">
                <h2 class="text-base font-semibold text-slate-900">Collect customer due</h2>
                <p class="mt-1 text-xs text-slate-500">Choose an invoice or pay oldest invoices first. Partial payments reduce the earliest unpaid installment automatically.</p>
                <div class="mt-3"><label for="collect-invoice" class="text-xs font-semibold text-slate-600">Invoice / installment plan</label><select id="collect-invoice" wire:model.live="collectSaleId" class="mt-1 w-full rounded-lg border-slate-300 text-sm"><option value="">Oldest invoices first</option>@foreach ($collectInvoices as $invoice)<option value="{{ $invoice->id }}">{{ $invoice->invoice_no }} · due {{ $currency }}{{ $invoice->due_total }}</option>@endforeach</select>@error('collectSaleId')<p class="text-xs text-rose-600">{{ $message }}</p>@enderror</div>
                @php($selectedInvoice = $collectInvoices->firstWhere('id', (int) $collectSaleId))
                @if ($selectedInvoice && $selectedInvoice->paymentSchedules->isNotEmpty())
                    <div class="mt-3 max-h-40 overflow-auto rounded-lg bg-slate-50 p-2 text-xs">
                        @foreach ($selectedInvoice->paymentSchedules as $scheduled)
                            <p class="flex justify-between gap-2 {{ $scheduled->remaining()->isPositive() && $scheduled->due_date->toDateString() < \App\Support\BusinessClock::today()->toDateString() ? 'font-semibold text-rose-700' : 'text-slate-600' }}"><span>{{ $scheduled->due_date->format('d M Y') }} · {{ $scheduled->type === 'INSTALLMENT' ? 'Installment #'.$scheduled->sequence : 'Clearance' }}</span><span>Remaining {{ $currency }}{{ $scheduled->remaining()->formatted() }}</span></p>
                        @endforeach
                    </div>
                @endif
                <div class="mt-3 space-y-3">
                    <div>
                        <label class="text-xs font-semibold uppercase tracking-wide text-slate-600">Amount</label>
                        <input type="text" inputmode="decimal" wire:model="collectAmount" class="mt-1 w-full rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                        @error('collectAmount') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                        @error('amount') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="text-xs font-semibold uppercase tracking-wide text-slate-600">Method</label>
                        <select wire:model="collectMethod" class="mt-1 w-full rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                            @foreach ($paymentMethods as $method)
                                <option value="{{ $method->value }}">{{ $method->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="text-xs font-semibold uppercase tracking-wide text-slate-600">Reference</label>
                        <input type="text" wire:model="collectReference" class="mt-1 w-full rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                    </div>
                    <div>
                        <label class="text-xs font-semibold uppercase tracking-wide text-slate-600">Notes</label>
                        <input type="text" wire:model="collectNotes" class="mt-1 w-full rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                    </div>
                </div>
                <div class="mt-4 flex justify-end gap-2">
                    <button type="button" wire:click="$set('collectOpen', false)" class="rounded-lg border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">Cancel</button>
                    <button type="submit" wire:loading.attr="disabled" class="rounded-lg bg-emerald-600 px-3 py-2 text-sm font-semibold text-white hover:bg-emerald-700">Post collection</button>
                </div>
            </form>
        </div>
    @endif
</div>
