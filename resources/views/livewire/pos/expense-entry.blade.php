<div>
    <div class="rounded-xl border border-slate-200 bg-white p-3 shadow-sm">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h2 class="text-sm font-semibold text-slate-800">Expenses</h2>
            <button type="button" wire:click="open" class="rounded-lg bg-amber-600 px-4 py-2 text-sm font-semibold text-white hover:bg-amber-700">+ Expense</button>
        </div>
        @if (session('expenseSuccess'))<p wire:key="expense-success-{{ $requestKey }}" x-data="{ visible: true }" x-show="visible" x-init="setTimeout(() => visible = false, 5000)" role="status" class="mt-2 rounded-lg bg-green-50 p-2 text-sm text-green-800">{{ session('expenseSuccess') }}</p>@endif
        @if ($recentExpenses->isNotEmpty())
            <button type="button" wire:click="$toggle('historyOpen')" aria-expanded="{{ $historyOpen ? 'true' : 'false' }}" class="mt-2 text-xs font-semibold text-slate-500">{{ $historyOpen ? 'Hide recent expenses' : 'View recent expenses' }}</button>
            @if ($historyOpen)
            <ul class="mt-2 divide-y divide-slate-100">
                @foreach ($recentExpenses as $expense)
                    <li wire:key="expense-{{ $expense->id }}" class="flex items-start justify-between gap-3 py-2 text-xs">
                        <div class="min-w-0"><p class="font-semibold text-slate-700">{{ $expense->recipient_name }} · {{ ucfirst(strtolower($expense->recipient_type)) }}</p><p class="break-words text-slate-500">{{ $expense->reason }}</p>@if ($expense->recipient_details)<p class="break-words text-slate-500">{{ $expense->recipient_details }}</p>@endif<p class="text-slate-400">{{ $expense->document_no }}{{ $expense->salary_month_id ? ' · From salary' : '' }}</p></div>
                        <span class="shrink-0 font-semibold text-slate-800">{{ $currency }}{{ number_format((float) $expense->amount, 2) }}</span>
                    </li>
                @endforeach
            </ul>
            @endif
        @endif
    </div>
    @if ($modalOpen)
        <div class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-900/60 p-4 pt-10 sm:pt-20" role="dialog" aria-modal="true" aria-labelledby="expense-heading" x-data x-on:keydown.escape.window="$wire.set('modalOpen', false)">
            <form wire:key="expense-form-{{ $requestKey }}" wire:submit="save" class="w-full max-w-lg space-y-4 rounded-xl bg-white p-5 shadow-xl">
                <h2 id="expense-heading" class="text-lg font-semibold text-slate-900">Add expense</h2>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div><label for="expense-amount" class="text-sm font-medium text-slate-700">Amount ({{ $currency }})</label><input id="expense-amount" wire:model="amount" type="text" inputmode="decimal" placeholder="0.00" class="mt-1 w-full rounded-lg border-slate-300">@error('amount')<p class="text-xs text-rose-600">{{ $message }}</p>@enderror</div>
                    <div><label for="expense-payment" class="text-sm font-medium text-slate-700">Paid from</label><select id="expense-payment" wire:model="paymentMethod" class="mt-1 w-full rounded-lg border-slate-300">@foreach ($methods as $method)<option value="{{ $method->value }}">{{ $method->label() }}</option>@endforeach</select>@error('paymentMethod')<p class="text-xs text-rose-600">{{ $message }}</p>@enderror</div>
                </div>
                <div><label for="expense-type" class="text-sm font-medium text-slate-700">Paid to</label><select id="expense-type" wire:model.live="recipientType" class="mt-1 w-full rounded-lg border-slate-300"><option value="STAFF">Staff</option><option value="SUPPLIER">Supplier</option><option value="OTHER">Other</option></select>@error('recipientType')<p class="text-xs text-rose-600">{{ $message }}</p>@enderror</div>
                @if ($recipientType === 'OTHER')
                    <div wire:key="expense-other"><label for="expense-name" class="text-sm font-medium text-slate-700">Recipient name</label><input id="expense-name" wire:model="recipientName" maxlength="180" class="mt-1 w-full rounded-lg border-slate-300">@error('recipientName')<p class="text-xs text-rose-600">{{ $message }}</p>@enderror
                    <label for="expense-details" class="mt-3 block text-sm font-medium text-slate-700">Recipient details</label><textarea id="expense-details" wire:model="recipientDetails" maxlength="1000" rows="2" placeholder="Who received the money? Add identifying details." class="mt-1 w-full rounded-lg border-slate-300"></textarea>@error('recipientDetails')<p class="text-xs text-rose-600">{{ $message }}</p>@enderror</div>
                @else
                    <div wire:key="expense-recipient-{{ $recipientType }}">
                        <label for="expense-recipient-name" class="text-sm font-medium text-slate-700">{{ $recipientType === 'STAFF' ? 'Staff member name' : 'Supplier name' }}</label>
                        <input id="expense-recipient-name" type="text" wire:model.live.debounce.300ms="recipientName" list="expense-recipient-options-{{ $recipientType }}" maxlength="180" placeholder="Type to select or add a new name" autocomplete="off" class="mt-1 w-full rounded-lg border-slate-300">
                        <datalist id="expense-recipient-options-{{ $recipientType }}">
                            @if ($recipientType === 'STAFF')
                                @foreach ($staff->pluck('name')->merge($staffMembers->pluck('name'))->unique()->sort() as $name)<option value="{{ $name }}">@endforeach
                            @else
                                @foreach ($suppliers as $supplier)<option value="{{ $supplier->name }}">@endforeach
                            @endif
                        </datalist>
                        <p class="mt-1 text-xs text-slate-500">Choose a suggestion, or enter a new name. New {{ $recipientType === 'STAFF' ? 'staff members' : 'suppliers' }} are saved when you save the expense.</p>
                        @error('recipientName')<p class="text-xs text-rose-600">{{ $message }}</p>@enderror
                        @error('recipientId')<p class="text-xs text-rose-600">{{ $message }}</p>@enderror
                    </div>
                @endif
                @if ($recipientType === 'STAFF')
                    <div class="rounded-lg bg-indigo-50 p-3">
                        <label class="flex items-center gap-2 text-sm font-semibold text-indigo-900"><input type="checkbox" wire:model.live="fromSalary" class="rounded border-indigo-300">From salary · salary advance / payment</label>
                        @if ($fromSalary)<label for="expense-salary-month" class="mt-2 block text-xs font-semibold">Deduct from salary month</label><input id="expense-salary-month" type="month" wire:model="salaryMonth" class="mt-1 w-full rounded-lg border-slate-300 text-sm"><p class="mt-1 text-xs text-indigo-800">This payment reduces the selected month’s salary balance.</p>@endif
                        @error('salaryMonth')<p class="text-xs text-rose-600">{{ $message }}</p>@enderror
                        @error('fromSalary')<p class="text-xs text-rose-600">{{ $message }}</p>@enderror
                    </div>
                @endif
                <div><label for="expense-reason" class="text-sm font-medium text-slate-700">Why was this paid?</label><textarea id="expense-reason" wire:model="reason" maxlength="1000" rows="3" placeholder="Expense purpose / reason" class="mt-1 w-full rounded-lg border-slate-300"></textarea>@error('reason')<p class="text-xs text-rose-600">{{ $message }}</p>@enderror</div>
                @if ($recipientType === 'SUPPLIER')<p class="text-xs text-slate-500">For an operating expense paid to this supplier. Settle purchase invoices through Purchases.</p>@endif
                <div class="flex justify-end gap-2"><button type="button" wire:click="$set('modalOpen', false)" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold">Cancel</button><button type="submit" wire:loading.attr="disabled" class="rounded-lg bg-amber-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50"><span wire:loading.remove wire:target="save">Save expense</span><span wire:loading wire:target="save">Saving…</span></button></div>
            </form>
        </div>
    @endif
</div>
