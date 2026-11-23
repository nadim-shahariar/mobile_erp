<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AccessoryStockBalance;
use App\Models\InventoryCycle;
use App\Models\Purchase;
use App\Models\SerializedUnit;
use Database\Seeders\AdminUserSeeder;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsErpScenario;
use Tests\TestCase;

class DemoDataSeederTest extends TestCase
{
    use BuildsErpScenario;
    use RefreshDatabase;

    public function test_demo_seed_creates_real_cycles_and_received_stock(): void
    {
        $this->bootErp();
        $this->seed(AdminUserSeeder::class);
        $this->seed(DemoDataSeeder::class);

        $this->assertGreaterThanOrEqual(6, SerializedUnit::query()->count());
        $this->assertSame(
            SerializedUnit::query()->count(),
            InventoryCycle::query()->count(),
            'Each demo handset must have exactly one acquisition cycle.',
        );

        $this->assertTrue(
            Purchase::query()->where('status', 'RECEIVED')->exists(),
            'The demo purchase must be received.',
        );

        $this->assertGreaterThan(
            0,
            AccessoryStockBalance::query()->where('qty_on_hand', '>', 0)->count(),
            'Demo accessory stock must exist.',
        );

        $this->assertSame(
            SerializedUnit::query()->count(),
            SerializedUnit::query()->whereNotNull('primary_imei')->count(),
        );
    }
}
