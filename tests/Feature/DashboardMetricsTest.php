<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Inventory\AccessoryStockService;
use App\Domain\Reporting\InventoryMetrics;
use App\Enums\AccessoryMovementReason;
use App\Exceptions\UnitAccessDeniedException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsErpScenario;
use Tests\TestCase;

class DashboardMetricsTest extends TestCase
{
    use BuildsErpScenario;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootErp();
    }

    public function test_metrics_come_from_real_posted_stock(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id, $this->unitTwo->id]);
        $phone = $this->phoneVariant();
        $accessory = $this->accessoryVariant();

        $this->addPhoneToStock($owner, $this->unitOne, $phone, $this->validImei(601), '50000.00');
        $this->addPhoneToStock($owner, $this->unitTwo, $phone, $this->validImei(602), '40000.00');

        app(AccessoryStockService::class)->receive(
            $this->unitOne->id,
            $accessory->id,
            5,
            '100.00',
            AccessoryMovementReason::Opening,
            $owner,
        );

        $metrics = app(InventoryMetrics::class);

        $all = $metrics->summary($owner, null);
        $this->assertSame(2, $all['counts']['sellable']);
        $this->assertSame('90000.00', $all['stock_value']->amount);
        $this->assertSame('500.00', $all['accessory_value']->amount);
        $this->assertSame(1, $all['low_stock_count']);
        $this->assertCount(2, $all['units']);

        $unitOne = $metrics->summary($owner, $this->unitOne->id);
        $this->assertSame(1, $unitOne['counts']['sellable']);
        $this->assertSame('50000.00', $unitOne['stock_value']->amount);
        $this->assertSame('500.00', $unitOne['accessory_value']->amount);
    }

    public function test_metrics_do_not_leak_another_units_stock(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id, $this->unitTwo->id]);
        $phone = $this->phoneVariant();

        $this->addPhoneToStock($owner, $this->unitTwo, $phone, $this->validImei(603));

        $unitOneManager = $this->staff('manager', [$this->unitOne->id]);
        $summary = app(InventoryMetrics::class)->summary($unitOneManager, null);

        $this->assertSame(0, $summary['counts']['sellable']);
        $this->assertSame('0.00', $summary['stock_value']->amount);

        $this->expectException(UnitAccessDeniedException::class);
        app(InventoryMetrics::class)->summary($unitOneManager, $this->unitTwo->id);
    }

    public function test_dashboard_page_renders_live_counts(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id]);
        $phone = $this->phoneVariant();
        $this->addPhoneToStock($owner, $this->unitOne, $phone, $this->validImei(604));

        $this->actingAs($owner)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Available phones')
            ->assertSee('Phone stock value')
            ->assertSee('Today\'s sales')
            ->assertSee('Outstanding due');
    }
}
