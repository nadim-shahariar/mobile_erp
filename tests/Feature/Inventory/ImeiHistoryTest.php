<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Domain\Transfers\StockTransferService;
use App\Models\SerializedUnit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsErpScenario;
use Tests\TestCase;

class ImeiHistoryTest extends TestCase
{
    use BuildsErpScenario;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootErp();
    }

    public function test_timeline_shows_every_lifecycle_event_for_the_same_imei(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id, $this->unitTwo->id]);
        $variant = $this->phoneVariant();
        $imei = $this->validImei(401);

        $unit = $this->addPhoneToStock($owner, $this->unitTwo, $variant, $imei);

        $transfers = app(StockTransferService::class);
        $transfer = $transfers->request($this->unitTwo, $this->unitOne, [$unit->id], [], $owner, 'History test');
        $transfers->dispatch($transfer, $owner);
        $transfers->receive($transfer, $owner);

        $response = $this->actingAs($owner)->get(route('imei.history', $imei));

        $response->assertOk()
            ->assertSee($imei)
            ->assertSee('Event ledger')
            ->assertSee('Transfer Requested')
            ->assertSee('Transfer Dispatched')
            ->assertSee('Transfer Received')
            ->assertSee('Cycle 1');

        $this->assertSame(1, SerializedUnit::query()->count());
    }

    public function test_user_without_access_to_the_handsets_unit_gets_forbidden(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id, $this->unitTwo->id]);
        $variant = $this->phoneVariant();
        $imei = $this->validImei(402);

        $this->addPhoneToStock($owner, $this->unitOne, $variant, $imei);

        $unitTwoManager = $this->staff('manager', [$this->unitTwo->id]);

        $this->actingAs($unitTwoManager)->get(route('imei.history', $imei))->assertForbidden();
    }

    public function test_unknown_imei_is_reported_honestly(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id]);

        $this->actingAs($owner)
            ->get(route('imei.history', '999999999999999'))
            ->assertOk()
            ->assertSee('No handset found');
    }
}
