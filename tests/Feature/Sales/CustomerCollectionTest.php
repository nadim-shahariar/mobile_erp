<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Domain\Reporting\SalesMetrics;
use App\Domain\Sales\CustomerPaymentService;
use App\Domain\Sales\SaleService;
use App\Enums\CustomerType;
use App\Enums\PaymentMethod;
use App\Exceptions\ErpException;
use App\Models\Customer;
use App\Models\JournalEntry;
use App\Models\Sale;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsErpScenario;
use Tests\TestCase;

class CustomerCollectionTest extends TestCase
{
    use BuildsErpScenario;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootErp();
    }

    public function test_partial_collection_reduces_due_without_new_revenue(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id]);
        $phone = $this->phoneVariant();
        $serialized = $this->addPhoneToStock($owner, $this->unitOne, $phone, $this->validImei(901));

        $shop = Customer::create([
            'type' => CustomerType::Shop,
            'name' => 'Shop B',
            'business_name' => 'Shop B Mobile',
            'credit_limit' => '100000',
            'is_active' => true,
        ]);

        $sale = app(SaleService::class)->post([
            'business_unit_id' => $this->unitOne->id,
            'customer_id' => $shop->id,
            'items' => [['line_type' => 'PHONE', 'serialized_unit_id' => $serialized->id, 'unit_price' => '60000']],
            'payments' => [['method' => 'CASH', 'amount' => '45000']],
        ], $owner);

        $this->assertSame('15000.00', $sale->due_total);

        $payment = app(CustomerPaymentService::class)->collect(
            customer: $shop,
            unit: $this->unitOne,
            method: PaymentMethod::Cash,
            amount: '10000',
            reference: 'CASH-1',
            notes: 'Part settlement',
            actor: $owner,
            idempotencyKey: 'collect-1',
        );

        $sale->refresh();
        $this->assertSame('55000.00', $sale->paid_total);
        $this->assertSame('5000.00', $sale->due_total);
        $this->assertCount(1, $payment->allocations);
        $this->assertSame('10000.00', $payment->allocations->first()->amount);
        $this->assertSame('COL-2026-000001', $payment->receipt_no);

        $entry = JournalEntry::query()->where('source_key', 'collection:'.$payment->id)->firstOrFail();
        $this->assertSame(
            Money::of((string) $entry->totalDebit())->amount,
            Money::of((string) $entry->totalCredit())->amount,
        );

        // Collections are not new sales revenue.
        $this->assertSame(1, Sale::query()->count());

        $metrics = app(SalesMetrics::class)->summary($owner, $this->unitOne->id);
        $this->assertSame('60000.00', $metrics['sales_total']->amount);
        $this->assertSame('55000.00', $metrics['collected_total']->amount);
        $this->assertSame('5000.00', $metrics['outstanding_due']->amount);
    }

    public function test_collection_cannot_exceed_outstanding_due(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id]);
        $phone = $this->phoneVariant();
        $serialized = $this->addPhoneToStock($owner, $this->unitOne, $phone, $this->validImei(902));

        $customer = Customer::create([
            'type' => CustomerType::Person,
            'name' => 'Rahim',
            'is_active' => true,
        ]);

        $sale = app(SaleService::class)->post([
            'business_unit_id' => $this->unitOne->id,
            'customer_id' => $customer->id,
            'items' => [['line_type' => 'PHONE', 'serialized_unit_id' => $serialized->id, 'unit_price' => '60000']],
            'payments' => [['method' => 'CASH', 'amount' => '50000']],
        ], $owner);

        $this->expectException(ErpException::class);

        app(CustomerPaymentService::class)->collect(
            customer: $customer,
            unit: $this->unitOne,
            method: PaymentMethod::Cash,
            amount: '20000',
            reference: null,
            notes: null,
            actor: $owner,
        );
    }
}
