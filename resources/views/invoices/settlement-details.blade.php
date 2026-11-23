    @if ($sale->externalDelivery)<p class="mt-3 rounded-lg bg-indigo-50 p-3 text-xs">Shop/person delivery {{ $sale->externalDelivery->reference_no }} · {{ $sale->externalDelivery->customer->name }} · deposit already applied: {{ $symbol }}{{ $sale->deposit_credit }}</p>@endif
    @if ($sale->exchange)
        @php($exchange = $sale->exchange)
        <section class="mt-4 rounded-lg border border-indigo-200 bg-indigo-50 p-3 text-sm">
            <p class="font-semibold">Exchange {{ $exchange->document_no }}</p>
            <p>Received: {{ $exchange->description }} @if ($exchange->imei) · IMEI {{ $exchange->imei }} @endif</p>
            <p>Accepted buying value: {{ $symbol }}{{ number_format((float) $exchange->buying_price, 2) }} · received into stock</p>
            <p>{{ $exchange->settlement_direction === 'COUNTER' ? 'Paid from counter to customer' : ($exchange->settlement_direction === 'CUSTOMER' ? 'Customer added' : 'Even exchange') }}: {{ $symbol }}{{ number_format((float) $exchange->settlement_amount, 2) }} · {{ $exchange->settlement_method === 'SPLIT' ? 'Split payment' : (\App\Enums\PaymentMethod::tryFrom($exchange->settlement_method)?->label() ?? $exchange->settlement_method) }}</p>
            @foreach ($exchange->settlement_payments ?? [] as $settlementPayment)
                <p class="text-xs">{{ \App\Enums\PaymentMethod::from($settlementPayment['method'])->label() }}: {{ $symbol }}{{ $settlementPayment['amount'] }} @if ($settlementPayment['reference']) · {{ $settlementPayment['reference'] }} @endif</p>
            @endforeach
            <p>Trade-in credit applied to invoice: {{ $symbol }}{{ number_format((float) $sale->trade_in_credit, 2) }}</p>
        </section>
    @endif

