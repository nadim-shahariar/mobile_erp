<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\BusinessClock;
use App\Support\DocumentNumberService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsErpScenario;
use Tests\TestCase;

class DocumentNumberServiceTest extends TestCase
{
    use BuildsErpScenario;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootErp();
    }

    public function test_global_and_unit_series_are_distinct_and_sequential(): void
    {
        $service = app(DocumentNumberService::class);
        $year = BusinessClock::now()->format('Y');

        $this->assertSame("INV-{$year}-000001", $service->next('sale'));
        $this->assertSame("INV-{$year}-000002", $service->next('sale'));
        $this->assertSame("INV-U1-{$year}-000001", $service->next('sale', $this->unitOne->id));
        $this->assertSame("INV-U1-{$year}-000002", $service->next('sale', $this->unitOne->id));
        $this->assertSame("INV-{$year}-000003", $service->next('sale'));
    }
}
