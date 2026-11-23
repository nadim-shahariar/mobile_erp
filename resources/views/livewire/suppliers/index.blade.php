<div>
    <x-erp.page-header title="Suppliers" subtitle="Businesses and individuals you buy handsets or accessories from. Purchase documents reference these records.">
        <x-slot:actions>
            @if ($canManage)
                <button type="button" wire:click="openModal" class="rounded-lg bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700">New supplier</button>
            @endif
        </x-slot:actions>
    </x-erp.page-header>

    @if(session('success'))<p class="mb-3 rounded bg-green-50 p-3 text-green-800">{{ session('success') }}</p>@endif
    @if($accountsEnabled)<p class="mb-3 text-sm text-slate-500">Received purchases and payments for the current branch. Pending lot orders are excluded.</p>@endif
    <div class="mb-4">
        <input type="search" wire:model.live.debounce.400ms="search" placeholder="Search name or phone…" class="w-full max-w-md rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500" aria-label="Search suppliers">
    </div>

    <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-left text-[11px] uppercase tracking-wider text-slate-500">
                    <tr>
                        <th class="px-4 py-2.5 font-semibold">Name</th>
                        <th class="px-4 py-2.5 font-semibold">Type</th>
                        <th class="px-4 py-2.5 font-semibold">Phone</th>
                        <th class="px-4 py-2.5 font-semibold">Contact</th>
                        <th class="px-4 py-2.5 text-right font-semibold">Purchases</th>
                        @if($accountsEnabled)<th class="p-3">Products purchased (৳)</th><th class="p-3">Paid (৳)</th><th class="p-3">Due (৳)</th>@endif
                        <th class="px-4 py-2.5 font-semibold">Status</th>
                        <th class="px-4 py-2.5"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($suppliers as $supplier)
                        <tr wire:key="supplier-{{ $supplier->id }}" class="hover:bg-slate-50/70">
                            <td class="px-4 py-2.5 font-medium text-slate-800">{{ $supplier->name }}</td>
                            <td class="px-4 py-2.5 text-slate-600">{{ ucfirst(strtolower($supplier->type)) }}</td>
                            <td class="px-4 py-2.5 text-slate-600">{{ $supplier->phone ?? '—' }}</td>
                            <td class="px-4 py-2.5 text-slate-600">{{ $supplier->contact_person ?? '—' }}</td>
                            <td class="px-4 py-2.5 text-right tabular-nums text-slate-700">{{ $supplier->purchases_count }}</td>
                            @if($accountsEnabled)<td class="p-3">{{ number_format($supplier->purchased_total ?? 0,2) }}</td><td class="p-3">{{ number_format($supplier->paid_total ?? 0,2) }}</td><td class="p-3 font-semibold text-amber-700">{{ number_format($supplier->due_total ?? 0,2) }} @can('purchase.approve')@if($supplier->due_total > 0)<button wire:click="openPayment({{ $supplier->id }})" class="ml-2 text-indigo-600">Pay due</button>@endif@endcan</td>@endif
                            <td class="px-4 py-2.5"><x-erp.badge :tone="$supplier->is_active ? 'green' : 'slate'">{{ $supplier->is_active ? 'Active' : 'Inactive' }}</x-erp.badge></td>
                            <td class="px-4 py-2.5 text-right">
                                @if ($canManage)
                                    <button type="button" wire:click="openModal({{ $supplier->id }})" class="rounded border border-slate-300 px-2 py-1 text-xs font-medium text-slate-700 hover:bg-slate-100">Edit</button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-4 py-12 text-center text-sm text-slate-500">No suppliers yet. Add one, or buy from an individual seller without a supplier record.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($suppliers->hasPages())
            <div class="border-t border-slate-200 px-4 py-3">{{ $suppliers->links() }}</div>
        @endif
    </div>

    @if($accountsEnabled)
    <details class="mt-4 rounded bg-white p-4"><summary class="cursor-pointer font-semibold">Recent purchases · paid / partial / due</summary><div class="overflow-x-auto"><table class="w-full text-sm"><thead><tr class="text-left"><th>Purchase</th><th>Supplier</th><th>Total</th><th>Paid</th><th>Due</th><th>Status</th></tr></thead><tbody>@foreach($purchaseHistory as $purchase)<tr class="border-t"><td class="p-2"><a class="text-indigo-600" href="{{ route('purchases.show',$purchase) }}">{{ $purchase->document_no }}</a></td><td>{{ $purchase->supplier?->name ?? $purchase->buying_party_name }}</td><td>{{ $purchase->total }}</td><td>{{ $purchase->paid_amount }}</td><td>{{ $purchase->due_amount }}</td><td>{{ $purchase->due_amount == 0 ? 'Paid' : ($purchase->paid_amount > 0 ? 'Partial paid' : 'Due') }}</td></tr>@endforeach</tbody></table></div></details>
    <details class="mt-3 rounded bg-white p-4"><summary class="cursor-pointer font-semibold">Recent supplier due payments</summary>@foreach($payments as $payment)<p class="border-t py-2 text-sm">{{ $suppliers->firstWhere('id',$payment->supplier_id)?->name ?? \App\Models\Supplier::find($payment->supplier_id)?->name }} · ৳{{ $payment->amount }} · {{ $payment->payment_method }} · {{ $payment->created_at->format('d M Y') }} · {{ $payment->reference }}</p>@endforeach</details>
    @endif
    @if($paymentSupplier)
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 p-4"><form wire:submit="paySupplier" class="w-full max-w-lg space-y-3 rounded-xl bg-white p-5"><h2 class="font-semibold">Pay supplier due</h2><p>Payment settles the oldest unpaid purchases in the current branch.</p><label class="block">Amount<input type="number" min="0.01" step="0.01" wire:model="paymentAmount" class="block w-full rounded border-slate-300"></label><label class="block">Method<select wire:model="paymentMethod" class="block w-full rounded border-slate-300">@foreach(\App\Enums\PaymentMethod::cases() as $method)<option value="{{ $method->value }}">{{ $method->label() }}</option>@endforeach</select></label><label class="block">Reference<input wire:model="paymentReference" class="block w-full rounded border-slate-300"></label>@foreach($errors->all() as $error)<p class="text-rose-600">{{ $error }}</p>@endforeach<button type="button" wire:click="$set('paymentSupplier',null)" class="rounded border px-3 py-2">Close</button> <button wire:loading.attr="disabled" class="rounded bg-indigo-600 px-3 py-2 text-white">Save payment</button></form></div>
    @endif
    @if ($modalOpen)
        <div class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-900/60 p-4 pt-16">
            <form wire:submit="save" class="w-full max-w-lg rounded-xl bg-white p-5 shadow-xl">
                <h2 class="text-base font-semibold text-slate-900">{{ $editingId ? 'Edit supplier' : 'New supplier' }}</h2>
                <div class="mt-4 grid gap-3 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <label class="text-xs font-semibold uppercase tracking-wide text-slate-600">Name</label>
                        <input type="text" wire:model="name" class="mt-1 w-full rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                        @error('name') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="text-xs font-semibold uppercase tracking-wide text-slate-600">Type</label>
                        <select wire:model="type" class="mt-1 w-full rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                            <option value="BUSINESS">Business</option>
                            <option value="PERSON">Person</option>
                        </select>
                    </div>
                    <div>
                        <label class="text-xs font-semibold uppercase tracking-wide text-slate-600">Phone</label>
                        <input type="text" wire:model="phone" class="mt-1 w-full rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                    </div>
                    <div>
                        <label class="text-xs font-semibold uppercase tracking-wide text-slate-600">Contact person</label>
                        <input type="text" wire:model="contactPerson" class="mt-1 w-full rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                    </div>
                    <div>
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
                    <button type="submit" class="rounded-lg bg-indigo-600 px-3 py-2 text-sm font-semibold text-white hover:bg-indigo-700">Save supplier</button>
                </div>
            </form>
        </div>
    @endif
</div>
