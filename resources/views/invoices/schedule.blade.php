    @if ($sale->paymentSchedules->isNotEmpty())
        <section class="mt-5 border-t border-slate-200 pt-3 text-xs">
            <h2 class="font-semibold text-slate-800">{{ $sale->paymentSchedules->first()->type === 'INSTALLMENT' ? 'Monthly installment schedule' : 'Due clearance date' }}</h2>
            <table class="mt-2 w-full text-left"><thead><tr><th class="py-1">Payment date</th><th class="py-1 text-right">Scheduled</th><th class="py-1 text-right">Paid</th><th class="py-1 text-right">Remaining</th></tr></thead><tbody>
                @foreach ($sale->paymentSchedules as $scheduled)<tr><td class="py-1">{{ $scheduled->due_date->format('d M Y') }}</td><td class="text-right">{{ $symbol }}{{ $scheduled->amount }}</td><td class="text-right">{{ $symbol }}{{ $scheduled->paid_amount }}</td><td class="text-right">{{ $symbol }}{{ $scheduled->remaining()->amount }}</td></tr>@endforeach
            </tbody></table>
        </section>
    @endif

    @if ((float) $sale->emi_rate > 0)<p class="mt-3 text-xs">EMI added: {{ $sale->emi_rate }}% on financed balance · {{ $symbol }}{{ $sale->emi_charge }} included in invoice total.</p>@endif
