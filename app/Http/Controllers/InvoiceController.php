<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Support\BusinessClock;
use App\Support\UnitContext;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Storage;

class InvoiceController extends Controller
{
    public function logo()
    {
        $path = (string) config('erp.company.logo_path');
        abort_unless(str_starts_with($path, 'branding/logos/') && Storage::disk('public')->exists($path), 404);

        return Storage::disk('public')->response($path, null, ['Cache-Control' => 'private, max-age=300']);
    }

    public function preview(): View
    {
        $this->authorize('settings.manage');
        $sale = new Sale([
            'invoice_no' => 'PREVIEW-001', 'type' => 'RETAIL', 'status' => 'POSTED',
            'posted_at' => BusinessClock::now(), 'subtotal' => '11000', 'grand_total' => '11000',
            'paid_total' => '5000', 'due_total' => '6000', 'line_discount_total' => '0', 'invoice_discount_total' => '0',
            'trade_in_credit' => '0', 'deposit_credit' => '0', 'emi_rate' => '0', 'emi_charge' => '0',
        ]);
        $sale->setRelation('customer', new Customer(['name' => 'Sample Customer', 'phone' => '01XXXXXXXXX', 'address' => 'Customer address', 'type' => 'PERSON']));
        $sale->setRelation('creator', auth()->user());
        $sale->setRelation('businessUnit', app(UnitContext::class)->current(auth()->user()));
        $sale->setRelation('items', collect([new SaleItem([
            'line_type' => 'PHONE', 'description' => 'Sample Phone 128GB 8GB Blue', 'identifier' => '000000000000000',
            'quantity' => 1, 'unit_price' => '11000', 'discount' => '0', 'line_total' => '11000',
            'details' => ['battery_health' => 95, 'category' => 'PRE_OWNED', 'box_kit' => 'Only set'],
        ])]));
        $sale->setRelation('payments', collect([new SalePayment(['method' => 'CASH', 'amount' => '5000', 'received_at' => BusinessClock::now()])]));
        $sale->setRelation('paymentSchedules', collect());
        $sale->setRelation('exchange', null);
        $sale->setRelation('externalDelivery', null);

        return view('invoices.show', ['sale' => $sale, 'company' => config('erp.company'), 'currency' => config('erp.currency.symbol'), 'isPreview' => true]);
    }

    public function show(Sale $sale): View
    {
        $this->authorize('view', $sale);

        $sale->load([
            'items.variant.product.brand',
            'exchange',
            'externalDelivery.customer',
            'paymentSchedules',
            'payments.account',
            'customer',
            'businessUnit',
            'creator',
        ]);

        return view('invoices.show', [
            'sale' => $sale,
            'company' => config('erp.company'),
            'currency' => config('erp.currency.symbol'),
        ]);
    }
}
