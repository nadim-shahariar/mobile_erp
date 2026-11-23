<?php

declare(strict_types=1);

namespace Tests\Feature\Imports;

use App\Domain\Imports\PhoneImportService;
use App\Livewire\Imports\PhoneImport;
use App\Models\ImportBatch;
use App\Models\ProductVariant;
use App\Models\Purchase;
use App\Models\SerializedUnit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\BuildsErpScenario;
use Tests\TestCase;

class PhoneImportTest extends TestCase
{
    use BuildsErpScenario;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootErp();
    }

    public function test_analyzer_resolves_models_and_reports_invalid_rows(): void
    {
        $variant = $this->phoneVariant();
        $service = app(PhoneImportService::class);
        $variants = ProductVariant::query()->with('product.brand')->get();

        $valid = $this->validImei(501);

        $analysis = $service->analyze(
            rows: [
                [$valid, '50000', $variant->sku],
                ['123456789012345', '40000', $variant->sku],
                [$valid, '40000', 'UNKNOWN-SKU'],
            ],
            mapping: [
                'imei1' => 0,
                'acquisition_cost' => 1,
                'model' => 2,
                'imei2' => '',
                'condition_grade' => '',
                'battery_health' => '',
                'cycle_count' => '',
                'box_kit' => '',
            ],
            defaultVariantId: null,
            variants: $variants,
        );

        $this->assertSame(3, count($analysis['rows']));
        $this->assertSame(1, $analysis['valid']);
        $this->assertSame(2, $analysis['invalid']);
        $this->assertSame($variant->id, $analysis['rows'][0]['variant_id']);

        $errors = implode(' ', $analysis['rows'][1]['errors']);
        $this->assertStringContainsString('not a valid', $errors);
    }

    public function test_duplicates_inside_the_file_and_in_stock_are_rejected(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id]);
        $variant = $this->phoneVariant();
        $existing = $this->validImei(502);
        $fresh = $this->validImei(503);

        $this->addPhoneToStock($owner, $this->unitOne, $variant, $existing);

        $analysis = app(PhoneImportService::class)->analyze(
            rows: [
                [$fresh, '50000', $variant->sku],
                [$fresh, '50000', $variant->sku],
                [$existing, '50000', $variant->sku],
            ],
            mapping: ['imei1' => 0, 'acquisition_cost' => 1, 'model' => 2],
            defaultVariantId: null,
            variants: ProductVariant::query()->with('product.brand')->get(),
        );

        $this->assertSame(2, $analysis['invalid']);
        $this->assertSame(1, $analysis['valid']);
        $this->assertStringContainsString('duplicated', implode(' ', $analysis['rows'][1]['errors']));
        $this->assertStringContainsString('already exists', implode(' ', $analysis['rows'][2]['errors']));
    }

    public function test_sold_or_historical_rows_are_rejected(): void
    {
        $variant = $this->phoneVariant();
        $service = app(PhoneImportService::class);

        $analysis = $service->analyze(
            rows: [[$this->validImei(507), '45000', $variant->sku, '2026-02-01', 'Customer A']],
            mapping: ['imei1' => 0, 'acquisition_cost' => 1, 'model' => 2],
            defaultVariantId: null,
            variants: ProductVariant::query()->with('product.brand')->get(),
            soldColumns: [3, 4],
        );

        $this->assertSame(1, $analysis['invalid']);
        $this->assertStringContainsString('sold or historical', implode(' ', $analysis['rows'][0]['errors']));
    }

    public function test_wizard_upload_and_commit_books_real_stock(): void
    {
        Storage::fake('local');

        $owner = $this->staff('owner', [$this->unitOne->id]);
        $variant = $this->phoneVariant();
        $imei = $this->validImei(504);
        $csv = "IMEI 1,Buy Price,Model\n{$imei},45000,{$variant->sku}\n";

        $file = UploadedFile::fake()->createWithContent('phones.csv', $csv);

        $component = Livewire::actingAs($owner)
            ->test(PhoneImport::class)
            ->set('file', $file)
            ->assertHasNoErrors();

        $component->assertSet('totalRows', 1)
            ->assertSet('validRows', 1)
            ->set('unitId', (string) $this->unitOne->id)
            ->set('purchaseDate', now()->toDateString())
            ->call('commit')
            ->assertHasNoErrors();

        $unit = SerializedUnit::query()->where('primary_imei', $imei)->firstOrFail();
        $this->assertSame($this->unitOne->id, $unit->current_unit_id);
        $this->assertSame('45000.00', $unit->activeCycle->acquisition_value);

        $purchase = Purchase::query()->firstOrFail();
        $this->assertSame($purchase->id, $unit->activeCycle->source->purchase_id);

        $batch = ImportBatch::query()->firstOrFail();
        $this->assertSame('COMMITTED', $batch->status);
        $this->assertSame(1, $batch->committed_rows);
        $this->assertNotNull($batch->committed_at);
    }

    public function test_the_shop_sheet_row_headers_are_recognised_and_imported(): void
    {
        Storage::fake('local');

        $owner = $this->staff('owner', [$this->unitOne->id]);
        $variant = $this->phoneVariant('iPhone 13', '128GB', 'Midnight');
        $imei = $this->validImei(506);

        $csv = "Model,Brand,Storage,Colour,IMEI,Buying Party,Buy Price,Selling Price,Battery Health,Cycle Count,Box/Kit,Receiving Date,Adapter,Adapter Price,Cables name,Cables Price,Due for reason\n"
            ."iPhone 13,Apple,128GB,Midnight,{$imei},Karim Traders,45000,61000,96,120,Box + cable,2026-01-15,20W adapter,1200,USB-C cable,300,Box slightly damaged\n";

        $file = UploadedFile::fake()->createWithContent('shop-sheet.csv', $csv);

        $component = Livewire::actingAs($owner)
            ->test(PhoneImport::class)
            ->set('file', $file)
            ->assertHasNoErrors();

        $component->assertSet('totalRows', 1)
            ->assertSet('validRows', 1)
            ->set('unitId', (string) $this->unitOne->id)
            ->set('purchaseDate', '2026-01-15')
            ->call('commit')
            ->assertHasNoErrors();

        $unit = SerializedUnit::query()->where('primary_imei', $imei)->firstOrFail();

        $this->assertSame($variant->id, $unit->product_variant_id);
        $this->assertSame('61000.00', $unit->expected_selling_price);
        $this->assertSame('Box slightly damaged', $unit->status_note);
        $this->assertSame('Karim Traders', $unit->activeCycle->buying_party_name);
        $this->assertSame('2026-01-15', $unit->activeCycle->acquired_at->timezone(config('erp.timezone'))->toDateString());
        $this->assertCount(2, $unit->accessories);
        $this->assertSame('1200.00', (string) $unit->accessories->firstWhere('accessory_type', 'ADAPTER')->price);
        $this->assertSame('300.00', (string) $unit->accessories->firstWhere('accessory_type', 'CABLE')->price);
    }

    public function test_commit_is_blocked_when_invalid_rows_exist_unless_explicitly_skipped(): void
    {
        Storage::fake('local');

        $owner = $this->staff('owner', [$this->unitOne->id]);
        $variant = $this->phoneVariant();
        $csv = "IMEI 1,Buy Price,Model\n"
            .$this->validImei(505).",45000,{$variant->sku}\n"
            ."123456789012345,45000,{$variant->sku}\n";

        $file = UploadedFile::fake()->createWithContent('phones.csv', $csv);

        Livewire::actingAs($owner)
            ->test(PhoneImport::class)
            ->set('file', $file)
            ->set('unitId', (string) $this->unitOne->id)
            ->set('purchaseDate', now()->toDateString())
            ->call('commit')
            ->assertHasErrors('commit');

        $this->assertSame(0, SerializedUnit::count());
    }
}
