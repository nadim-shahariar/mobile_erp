<div wire:poll.60s class="mb-4 min-w-0 rounded-xl border {{ $reminders->total() ? 'border-amber-300 bg-amber-50' : 'border-slate-200 bg-white' }} p-4 shadow-sm">
    <div class="flex flex-wrap items-center justify-between gap-2"><h2 class="text-sm font-semibold text-slate-900">Payment reminders <span class="rounded-full bg-amber-200 px-2 py-0.5 text-amber-900">{{ $reminders->total() }} overdue</span></h2><p class="text-sm font-semibold text-amber-900">Unpaid past deadline: {{ $currency }}{{ number_format((float) $overdueTotal, 2) }}</p></div>
    @if ($reminders->total())
        <div class="mt-3 max-h-80 overflow-auto">
            <table class="min-w-full text-left text-xs"><thead><tr class="text-slate-600"><th class="whitespace-nowrap p-2">Customer / phone</th><th class="whitespace-nowrap p-2">Invoice / branch</th><th class="whitespace-nowrap p-2">Payment deadline</th><th class="whitespace-nowrap p-2 text-right">Overdue amount</th><th class="p-2"></th></tr></thead><tbody class="divide-y divide-amber-200">
                @foreach ($reminders as $reminder)
                    <tr wire:key="reminder-{{ $reminder->id }}"><td class="p-2"><p class="font-semibold text-slate-900">{{ $reminder->sale->customer?->displayName() ?? 'Customer' }}</p>@if ($reminder->sale->customer?->phone)<a class="text-indigo-700 underline" href="tel:{{ $reminder->sale->customer->phone }}">{{ $reminder->sale->customer->phone }}</a>@else<p class="text-slate-500">Phone not recorded</p>@endif</td><td class="p-2"><a href="{{ route('invoices.show', $reminder->sale) }}" class="font-semibold text-indigo-700 underline">{{ $reminder->sale->invoice_no }}</a><p>{{ $reminder->sale->businessUnit?->name }}</p></td><td class="p-2"><p>{{ $reminder->due_date->format('d M Y') }}</p><p>{{ $reminder->type === 'INSTALLMENT' ? 'Monthly installment #'.$reminder->sequence : 'Credit / partial due' }}</p></td><td class="p-2 text-right"><p class="font-semibold text-rose-700">{{ $currency }}{{ $reminder->remaining()->formatted() }}</p><p class="whitespace-nowrap text-slate-500">Invoice remaining {{ $currency }}{{ number_format((float) $reminder->sale->due_total, 2) }}</p></td><td class="p-2">@can('payment.collect')<a href="{{ route('customers.index', ['q' => $reminder->sale->customer?->phone ?: $reminder->sale->customer?->name, 'sale' => $reminder->sale_id]) }}" class="whitespace-nowrap font-semibold text-indigo-700 underline">Collect payment</a>@endcan</td></tr>
                @endforeach
            </tbody></table>
        </div>
        @if ($reminders->hasPages())<div class="mt-3">{{ $reminders->links() }}</div>@endif
    @else
        <p class="mt-2 text-xs text-slate-500">No overdue credit or installment payments.</p>
    @endif
</div>
