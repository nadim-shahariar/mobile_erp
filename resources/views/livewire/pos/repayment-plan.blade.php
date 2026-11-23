@unless ($exchangeEnabled)
    <div class="mt-3 space-y-3 rounded-lg border border-slate-200 bg-slate-50 p-3">
        <div><label for="sale-terms" class="text-xs font-semibold text-slate-700">Sale / payment terms</label><select id="sale-terms" wire:model.live="saleTerms" class="mt-1 w-full rounded-lg border-slate-300 text-sm"><option value="PARTIAL">Cash / partial payment</option><option value="CREDIT">Credit sale · nothing paid now</option><option value="INSTALLMENT">Monthly installments</option></select></div>
        @if ($totals['due']->isPositive())
            @if ($saleTerms === 'INSTALLMENT')
                <div><label for="emi-rate" class="text-xs font-semibold">EMI added percentage (%)</label><input id="emi-rate" wire:model.live.debounce.400ms="emiRate" inputmode="decimal" class="mt-1 w-full rounded-lg border-slate-300 text-sm">@error('emiRate')<p class="text-xs text-rose-600">{{ $message }}</p>@enderror<p class="mt-1 text-xs text-slate-500">Flat percentage on the cash price minus upfront payment. Added: {{ $currency }}{{ $totals['emi_charge']->formatted() }}. Total including EMI: {{ $currency }}{{ $totals['total']->formatted() }}.</p></div>
                <div><label for="installment-months" class="text-xs font-semibold text-slate-700">Number of EMI months</label><input id="installment-months" type="number" min="1" max="120" step="1" wire:model.live.debounce.300ms="installmentMonths" placeholder="e.g. 6 or 12" class="mt-1 w-full rounded-lg border-slate-300 text-sm">@error('installmentMonths')<p class="text-xs text-rose-600">{{ $message }}</p>@enderror</div>
                <div><label for="monthly-installment" class="text-xs font-semibold text-slate-700">Monthly installment · auto calculated ({{ $currency }})</label><input id="monthly-installment" type="text" readonly value="{{ $monthlyInstallment }}" placeholder="Enter months and EMI %" class="mt-1 w-full rounded-lg border-slate-300 bg-slate-100 text-sm font-semibold"><p class="mt-1 text-xs text-slate-500">Remaining balance including EMI ÷ number of months. The last installment adjusts any remaining cents.</p>@error('monthlyInstallment')<p class="text-xs text-rose-600">{{ $message }}</p>@enderror</div>
                <div><label for="first-installment-date" class="text-xs font-semibold text-slate-700">First installment payment date</label><input id="first-installment-date" type="date" wire:model.live="firstInstallmentDate" min="{{ \App\Support\BusinessClock::today()->toDateString() }}" class="mt-1 w-full rounded-lg border-slate-300 text-sm">@error('firstInstallmentDate')<p class="text-xs text-rose-600">{{ $message }}</p>@enderror</div>
            @else
                <div><label for="due-clearance-date" class="text-xs font-semibold text-slate-700">Due clearance date</label><input id="due-clearance-date" type="date" wire:model.live="dueClearanceDate" min="{{ \App\Support\BusinessClock::today()->toDateString() }}" class="mt-1 w-full rounded-lg border-slate-300 text-sm">@error('dueClearanceDate')<p class="text-xs text-rose-600">{{ $message }}</p>@enderror</div>
            @endif
            @if ($repaymentPreview !== [])
                <div class="max-h-40 overflow-y-auto rounded-lg bg-white p-2 text-xs">
                    <p class="mb-1 font-semibold">{{ count($repaymentPreview) }} scheduled payment(s) · EMI rate {{ $emiRate }}%</p>
                    @foreach ($repaymentPreview as $scheduled)<p class="flex justify-between gap-2"><span>{{ $scheduled['due_date'] }}</span><span>{{ $currency }}{{ number_format((float) $scheduled['amount'], 2) }}</span></p>@endforeach
                </div>
            @endif
            <p class="text-xs text-slate-500">Select a registered customer. After the payment date passes, unpaid amounts appear in Payment reminders with the customer’s phone number.</p>
        @endif
    </div>
@endunless
