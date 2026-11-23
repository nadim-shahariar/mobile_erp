@php
    $design = config('erp.invoice');
    $isPreview = $isPreview ?? false;
    $accent = preg_match('/^#[0-9a-fA-F]{6}$/', $design['accent_color'] ?? '') ? $design['accent_color'] : '#008895';
    $terms = array_filter(preg_split('/\r\n|\n|\r/', str_replace('{shop_name}', $company['name'], $design['terms'] ?? '')));
    $symbol = $currency;
    $logoUrl = filled($company['logo_path'] ?? null) ? route('branding.logo', ['v' => substr(hash('sha256', $company['logo_path']), 0, 12)]) : null;
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $sale->invoice_no }} · {{ $company['name'] }}</title>
    <style>
        :root { --accent: {{ $accent }}; --ink: #11192c; --muted: #526174; --line: #dce4ec; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #edf1f5; color: var(--ink); font: 13px/1.45 Arial, 'Helvetica Neue', sans-serif; }
        .toolbar { max-width: 820px; margin: 18px auto; padding: 0 12px; display: flex; align-items: center; justify-content: space-between; gap: 12px; }
        .toolbar a,.toolbar button { border: 1px solid #cbd5e1; border-radius: 7px; padding: 9px 13px; background: white; color: var(--ink); font: inherit; text-decoration: none; cursor: pointer; }
        .toolbar button { background: var(--accent); color: white; border-color: var(--accent); }
        .preview-note { margin: 0; color: #755600; font-size: 12px; }
        .memo { position: relative; max-width: 820px; min-height: 1080px; margin: 0 auto 30px; padding: 36px 38px 28px; background: white; box-shadow: 0 4px 24px #15223815; }
        .memo-header { display: flex; align-items: flex-start; justify-content: space-between; gap: 24px; padding-bottom: 25px; border-bottom: 2px solid var(--accent); }
        .brand { max-width: 48%; overflow-wrap: anywhere; }
        .brand img { max-height: 88px; max-width: 240px; object-fit: contain; }
        .brand-name { margin: 0; color: var(--accent); font-size: 34px; font-weight: 800; line-height: 1.08; }
        .brand-subtitle { margin: 7px 0 0; letter-spacing: .27em; font-size: 15px; font-weight: 700; text-transform: uppercase; }
        .memo-meta { text-align: right; }
        .tagline { margin: 0 0 9px; color: #62738b; font-size: 9px; font-weight: bold; letter-spacing: 1.5px; text-transform: uppercase; }
        h1 { margin: 0 0 6px; font-size: 28px; line-height: 1.15; }
        .meta-line { margin: 0; font-size: 13px; }
        .memo-number { color: var(--accent); }
        .customer { margin-top: 22px; padding: 17px 20px; background: #f6f8fa; border: 1px solid var(--line); border-radius: 9px; }
        .customer p { display: flex; gap: 8px; margin: 3px 0; font-size: 14px; }
        .customer strong { width: 78px; flex: 0 0 78px; color: #34445b; }
        .customer span { overflow-wrap: anywhere; }
        .items { margin-top: 22px; width: 100%; border-collapse: collapse; table-layout: fixed; }
        .items th { background: var(--ink); color: white; font-size: 13px; text-align: left; padding: 12px; border: 1px solid var(--ink); }
        .items td { padding: 13px 12px; vertical-align: top; border: 1px solid #ced8e3; overflow-wrap: anywhere; }
        .items th:first-child { width: 9%; } .items th:last-child { width: 23%; }
        .item-description { margin: 0; font-size: 14px; font-weight: 700; line-height: 1.4; }
        .small { font-size: 10px; color: var(--muted); } .item-extra { margin: 5px 0 0; }
        .price { white-space: nowrap; font-size: 15px; font-weight: 700; }
        .balance-area { display: grid; grid-template-columns: minmax(0, 1.3fr) minmax(0, 1fr); gap: 26px; margin-top: 20px; }
        .imei-group { margin-bottom: 11px; } .imei-label { margin: 0 0 6px; font-size: 11px; font-weight: 700; color: #34445b; }
        .imei-digits { display: flex; flex-wrap: wrap; gap: 3px; }
        .imei-digit { display: inline-flex; align-items: center; justify-content: center; min-width: 16px; height: 23px; padding: 1px 3px; border: 1px solid var(--accent); border-radius: 4px; color: var(--accent); font-weight: 700; font-family: monospace; font-size: 12px; }
        .identifier { font: 12px monospace; overflow-wrap: anywhere; }
        .totals p { display: flex; justify-content: space-between; gap: 12px; padding: 7px 0; margin: 0; border-bottom: 1px dashed #cbd5e1; }
        .totals .grand,.totals .balance { font-size: 15px; font-weight: 700; }
        .signatures { display: flex; justify-content: space-between; gap: 80px; margin: 55px 20px 24px; }
        .signature { flex: 1; border-top: 1px dashed #66768c; padding-top: 7px; text-align: center; font-size: 12px; font-weight: 700; color: #34445b; }
        .terms { margin-top: 22px; border: 1px solid var(--line); border-radius: 9px; background: #f7f9fb; overflow: hidden; }
        .terms-body { padding: 15px 19px 12px; }
        .terms h2 { margin: 0 0 9px; padding-bottom: 7px; border-bottom: 2px solid var(--accent); color: var(--accent); font-size: 13px; }
        .terms ul { list-style: square; padding-left: 15px; margin: 0; }
        .terms li { margin: 5px 0; color: #34445b; font-size: 9px; line-height: 1.5; overflow-wrap: anywhere; }
        .contact-footer { background: var(--ink); color: white; padding: 10px 14px; font-size: 10px; text-align: center; overflow-wrap: anywhere; }
        .footer-note { margin: 12px 0 0; color: #62738b; text-align: center; font-size: 9px; }
        .payment-details { margin-top: 11px; font-size: 10px; color: var(--muted); }
        .payment-details p { margin: 3px 0; }
        .supplement { margin-top: 16px; padding: 12px; border: 1px solid var(--line); border-radius: 7px; font-size: 11px; }
        .supplement p { margin: 4px 0; } .supplement table { width: 100%; border-collapse: collapse; font-size: 10px; }
        .supplement th,.supplement td { padding: 5px; text-align: left; }
        @media (max-width: 620px) { .memo { min-height: 0; padding: 22px 18px; margin: 0; } .brand-name { font-size: 25px; } h1 { font-size: 22px; } .tagline { font-size: 8px; } .brand img { max-width: 150px; } .balance-area { grid-template-columns: 1fr; gap: 10px; } .signatures { gap: 30px; margin-left: 0; margin-right: 0; } .items td,.items th { padding: 9px 7px; } .price,.item-description { font-size: 12px; } .toolbar { flex-wrap: wrap; } }
        @media print { @page { size: A4; margin: 12mm; } body { background: white; print-color-adjust: exact; -webkit-print-color-adjust: exact; font-size: 11px; } .no-print { display: none !important; } .memo { max-width: none; min-height: 0; margin: 0; padding: 0; box-shadow: none; } .memo-header,.customer,.balance-area,.signatures,.terms-body,.contact-footer { break-inside: avoid; } .items thead { display: table-header-group; } .items tr { break-inside: avoid; } .supplement { break-inside: avoid; } .terms li { font-size: 8px; margin: 4px 0; } .terms-body { padding: 12px 16px 10px; } .memo-header { padding-bottom: 20px; } .customer { margin-top: 18px; padding: 12px 17px; } .items { margin-top: 18px; } .signatures { margin-top: 45px; } }
    </style>
</head>
<body>
<div class="toolbar no-print">
    <a href="{{ $isPreview ? route('settings.index') : route('sales.index') }}">{{ $isPreview ? 'Back to Settings' : 'Invoices' }}</a>
    @if ($isPreview)<p class="preview-note">Sample preview only - no sale has been created.</p>@endif
    <div>@if (! $isPreview)<a href="{{ route('pos.index') }}">New sale</a>@endif
    @if ($isPreview || auth()->user()->can('print', $sale))<button type="button" onclick="window.print()">Print / Save PDF</button>@endif</div>
</div>
<main class="memo">
    <header class="memo-header">
        <div class="brand">@if ($logoUrl)<img src="{{ $logoUrl }}" alt="{{ $company['name'] }} logo">@else<p class="brand-name">{{ $company['name'] }}</p>@endif
            @if ($design['brand_subtitle'])<p class="brand-subtitle">{{ $design['brand_subtitle'] }}</p>@endif
        </div>
        <div class="memo-meta">@if ($design['tagline'])<p class="tagline">{{ $design['tagline'] }}</p>@endif<h1>{{ $design['title'] }}</h1><p class="meta-line"><strong>Memo No: <span class="memo-number">{{ $sale->invoice_no }}</span></strong></p><p class="meta-line"><strong>Date:</strong> {{ $sale->posted_at?->timezone(config('erp.timezone'))->format('d/m/Y') }}</p></div>
    </header>
    <section class="customer"><p><strong>Name</strong><span>: {{ $sale->customer?->displayName() ?? 'Walk-in customer' }}</span></p><p><strong>Address</strong><span>: {{ $sale->customer?->address ?: '-' }}</span></p><p><strong>Number</strong><span>: {{ $sale->customer?->phone ?: '-' }}</span></p></section>
    <table class="items"><thead><tr><th>Sl</th><th>Description</th><th>Price</th></tr></thead><tbody>
        @foreach ($sale->items as $item)
            <tr><td>{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</td><td><p class="item-description">{{ $item->description }}</p>
                @php($detail = $item->details ?? [])
                @if ($detail)<p class="item-extra small">@if (isset($detail['battery_health']))Battery health {{ $detail['battery_health'] }}% · @endif @if (filled($detail['category'] ?? null)){{ $detail['category'] === 'NEW' ? 'New' : 'Pre owned' }} · @endif {{ $detail['box_kit'] ?? '' }}</p>@endif
                @if ($item->quantity > 1 || (float) $item->discount > 0)<p class="item-extra small">Qty {{ $item->quantity }} × {{ $currency }}{{ number_format((float) $item->unit_price, 2) }} @if ((float) $item->discount > 0) · Discount {{ $currency }}{{ number_format((float) $item->discount, 2) }} @endif</p>@endif
                @if ($item->line_type->value !== 'PHONE')<p class="item-extra small">SKU: {{ $item->identifier ?: ($detail['sku'] ?? '-') }}</p>@endif
            </td><td class="price">{{ $currency }}{{ number_format((float) $item->line_total, 2) }}</td></tr>
        @endforeach
    </tbody></table>
    <section class="balance-area"><div>
        @foreach ($sale->items->where('line_type', \App\Enums\LineType::Phone) as $item)
            @foreach (array_filter([$item->identifier, $item->details['secondary_imei'] ?? null]) as $identifier)
                <div class="imei-group"><p class="imei-label">IMEI Number{{ $sale->items->count() > 1 ? ' · '.$item->description : '' }}:</p>
                    @if (preg_match('/^\d{15}$/', $identifier))<div class="imei-digits">@foreach (str_split($identifier) as $digit)<span class="imei-digit">{{ $digit }}</span>@endforeach</div><span class="no-print small identifier">{{ $identifier }}</span>@else<div class="identifier">{{ $identifier }}</div>@endif
                </div>
            @endforeach
        @endforeach
        <div class="payment-details">@foreach ($sale->payments as $payment)<p>{{ $payment->method->label() }}: {{ $currency }}{{ $payment->amount }} @if ($payment->reference) · {{ $payment->reference }} @endif</p>@endforeach
            @if ($sale->notes)<p>{{ $sale->notes }}</p>@endif
        </div>
    </div><div class="totals">@if ((float) $sale->invoice_discount_total > 0)<p><span>Invoice discount</span><span>{{ $currency }}{{ number_format((float) $sale->invoice_discount_total, 2) }}</span></p>@endif<p class="grand"><span>Total</span><span>{{ $currency }}{{ number_format((float) $sale->grand_total, 2) }}</span></p><p><span>{{ $sale->exchange ? 'Settled incl. trade-in' : ((float) $sale->deposit_credit > 0 ? 'Paid incl. deposit' : 'Paid') }}</span><span>{{ $currency }}{{ number_format((float) $sale->paid_total, 2) }}</span></p><p class="balance"><span>Balance / Due</span><span>{{ $currency }}{{ number_format((float) $sale->due_total, 2) }}</span></p></div></section>
    @if ($sale->exchange || $sale->externalDelivery)<div class="supplement">@include('invoices.settlement-details')</div>@endif
    @if ($sale->paymentSchedules->isNotEmpty() || (float) $sale->emi_rate > 0)<div class="supplement">@include('invoices.schedule')</div>@endif
    <div class="signatures"><div class="signature">{{ $design['customer_signature'] }}</div><div class="signature">{{ $design['authorized_signature'] }}</div></div>
    <section class="terms">@if ($terms)<div class="terms-body"><h2>{{ $design['terms_heading'] }}</h2><ul>@foreach ($terms as $term)@php($parts = explode(':', $term, 2))<li>@if (count($parts) === 2 && mb_strlen($parts[0]) < 90)<strong>{{ $parts[0] }}:</strong>{{ $parts[1] }}@else{{ $term }}@endif</li>@endforeach</ul></div>@endif
        <footer class="contact-footer">{{ implode(' | ', array_filter([$company['phone'] ?? '', $company['address'] ?? '', $company['email'] ?? ''])) }}</footer>
    </section>
    @if ($design['footer_note'])<p class="footer-note">{{ $design['footer_note'] }}</p>@endif
</main>
</body></html>
