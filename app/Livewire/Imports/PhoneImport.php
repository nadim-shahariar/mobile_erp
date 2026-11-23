<?php

declare(strict_types=1);

namespace App\Livewire\Imports;

use App\Domain\Imports\PhoneImportService;
use App\Domain\Purchases\PurchaseService;
use App\Exceptions\ErpException;
use App\Models\BusinessUnit;
use App\Models\ImportBatch;
use App\Models\ProductVariant;
use App\Models\Supplier;
use App\Support\BusinessClock;
use App\Support\DocumentNumberService;
use App\Support\UnitContext;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

#[Layout('layouts.app')]
class PhoneImport extends Component
{
    use WithFileUploads;

    public $file = null;

    public ?int $batchId = null;

    /** @var list<string> */
    public array $headers = [];

    /** @var array<string, int|string> */
    public array $mapping = [];

    /** @var list<array<string, mixed>> */
    public array $previewRows = [];

    public int $totalRows = 0;

    public int $validRows = 0;

    public int $invalidRows = 0;

    public ?string $defaultVariantId = null;

    public string $unitId = '';

    public ?string $supplierId = null;

    public string $purchaseDate = '';

    public bool $importOnlyValid = false;

    public function mount(UnitContext $unitContext): void
    {
        $this->authorize('create', ImportBatch::class);

        $current = $unitContext->current(auth()->user());
        $this->unitId = (string) ($current?->getKey() ?? '');
        $this->purchaseDate = BusinessClock::today()->toDateString();
        $this->mapping = array_fill_keys(array_keys(PhoneImportService::FIELDS), '');
    }

    public function updatedFile(PhoneImportService $importService, DocumentNumberService $numbers): void
    {
        $this->authorize('create', ImportBatch::class);

        $this->validate([
            'file' => ['required', 'file', 'extensions:xlsx,xls,csv,txt', 'max:10240'],
        ], [
            'file.extensions' => 'Upload an .xlsx, .xls or .csv file.',
        ]);

        $path = $this->file->store('imports', 'local');

        try {
            $read = $importService->read(Storage::disk('local')->path($path));
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($path);
            $this->addError('file', 'Could not read that spreadsheet: '.$exception->getMessage());

            return;
        }

        if ($read['headers'] === []) {
            Storage::disk('local')->delete($path);
            $this->addError('file', 'The first row must contain column headers.');

            return;
        }

        $batch = ImportBatch::create([
            'batch_no' => $numbers->next('import'),
            'business_unit_id' => $this->unitId !== '' ? (int) $this->unitId : null,
            'user_id' => auth()->id(),
            'original_filename' => $this->file->getClientOriginalName(),
            'stored_path' => $path,
            'status' => 'UPLOADED',
            'headers' => $read['headers'],
            'mapping' => [],
            'total_rows' => count($read['rows']),
        ]);

        $this->batchId = $batch->getKey();
        $this->headers = $read['headers'];
        $this->mapping = $importService->guessMapping($read['headers']);
        $this->refreshPreview($importService);
    }

    public function updatedMapping(): void
    {
        $this->refreshPreview(app(PhoneImportService::class));
    }

    public function updatedDefaultVariantId(): void
    {
        $this->refreshPreview(app(PhoneImportService::class));
    }

    public function commit(PurchaseService $purchases, PhoneImportService $importService): void
    {
        $this->authorize('create', ImportBatch::class);

        $user = auth()->user();

        if (! $user->can('purchase.approve')) {
            $this->addError('commit', 'Import books stock, so purchase.approve is required. Ask a manager to run the commit.');

            return;
        }

        $this->supplierId = $this->supplierId === '' ? null : $this->supplierId;

        $validated = $this->validate([
            'unitId' => ['required', 'integer', Rule::in($user->accessibleUnitIds())],
            'supplierId' => ['nullable', 'integer', 'exists:suppliers,id'],
            'purchaseDate' => ['required', 'date'],
        ]);

        if ($this->batchId === null) {
            $this->addError('commit', 'Upload a file first.');

            return;
        }

        $analysis = $this->analyze($importService);

        if ($this->totalRows === 0) {
            $this->addError('commit', 'The file has no data rows.');

            return;
        }

        if ($this->invalidRows > 0 && ! $this->importOnlyValid) {
            $this->addError('commit', 'Fix the '.$this->invalidRows.' invalid row(s), or tick "import valid rows only".');

            return;
        }

        $rows = array_values(array_filter($analysis['rows'], fn (array $row) => $row['errors'] === []));

        if ($rows === []) {
            $this->addError('commit', 'There are no valid rows to import.');

            return;
        }

        $lines = [];

        foreach ($rows as $row) {
            $lines[] = [
                'product_variant_id' => (int) $row['variant_id'],
                'line_type' => 'PHONE',
                'quantity' => 1,
                'unit_cost' => (string) $row['cost'],
                'imei_1' => $row['imei1'],
                'imei_2' => $row['imei2'],
                'buying_party_name' => $row['buying_party'],
                'expected_selling_price' => $row['selling_price'],
                'status_note' => $row['status_note'],
                'received_at' => $row['received_at'],
                'serial_payload' => [
                    'condition_grade' => $row['condition_grade'],
                    'battery_health' => $row['battery_health'],
                    'cycle_count' => $row['cycle_count'],
                    'box_kit' => $row['box_kit'],
                    'adapter_name' => $row['adapter_name'],
                    'adapter_price' => $row['adapter_price'],
                    'cable_name' => $row['cable_name'],
                    'cable_price' => $row['cable_price'],
                ],
            ];
        }

        $batch = ImportBatch::query()->findOrFail($this->batchId);

        try {
            $purchase = DB::transaction(function () use ($purchases, $validated, $lines, $user, $batch, $analysis) {
                $purchase = $purchases->create([
                    'supplier_id' => ! empty($validated['supplierId']) ? (int) $validated['supplierId'] : null,
                    'business_unit_id' => (int) $validated['unitId'],
                    'purchase_date' => $validated['purchaseDate'],
                    'paid_amount' => '0',
                    'reference' => $batch->batch_no,
                    'notes' => "Imported from {$batch->original_filename} (batch {$batch->batch_no})",
                ], $lines, $user);

                $purchases->receive($purchase, $user);

                $batch->forceFill([
                    'status' => 'COMMITTED',
                    'business_unit_id' => (int) $validated['unitId'],
                    'mapping' => $this->mapping,
                    'total_rows' => $this->totalRows,
                    'valid_rows' => $this->validRows,
                    'invalid_rows' => $this->invalidRows,
                    'committed_rows' => count($lines),
                    'error_report' => array_values(array_filter($analysis['rows'], fn (array $row) => $row['errors'] !== [])),
                    'committed_at' => BusinessClock::utcNow(),
                ])->save();

                return $purchase;
            });
        } catch (ErpException $exception) {
            $this->addError('commit', $exception->getMessage());

            return;
        }

        session()->flash('success', count($lines)." handset(s) imported on purchase {$purchase->document_no}. Batch {$batch->batch_no} logged.");

        $this->redirect(route('purchases.show', $purchase), navigate: true);
    }

    private function refreshPreview(PhoneImportService $importService): void
    {
        if ($this->batchId === null) {
            return;
        }

        $analysis = $this->analyze($importService);
        $this->previewRows = array_slice($analysis['rows'], 0, 100);
        $this->totalRows = count($analysis['rows']);
        $this->validRows = $analysis['valid'];
        $this->invalidRows = $analysis['invalid'];

        $batch = ImportBatch::query()->find($this->batchId);

        $batch?->forceFill([
            'mapping' => $this->mapping,
            'total_rows' => $this->totalRows,
            'valid_rows' => $this->validRows,
            'invalid_rows' => $this->invalidRows,
            'status' => 'ANALYZED',
        ])->save();
    }

    /** @return array{rows: list<array<string, mixed>>, valid: int, invalid: int} */
    private function analyze(PhoneImportService $importService): array
    {
        $batch = ImportBatch::query()->find($this->batchId);

        if ($batch === null) {
            return ['rows' => [], 'valid' => 0, 'invalid' => 0];
        }

        $read = $importService->read(Storage::disk('local')->path($batch->stored_path));

        return $importService->analyze(
            rows: $read['rows'],
            mapping: $this->mapping,
            defaultVariantId: $this->defaultVariantId !== null && $this->defaultVariantId !== '' ? (int) $this->defaultVariantId : null,
            variants: ProductVariant::query()
                ->with('product.brand')
                ->whereHas('product', fn ($query) => $query->where('product_type', 'PHONE'))
                ->get(),
            soldColumns: $importService->soldColumnIndexes($read['headers']),
        );
    }

    public function render(): View
    {
        return view('livewire.imports.phone-import', [
            'fields' => PhoneImportService::FIELDS,
            'units' => BusinessUnit::query()->whereIn('id', auth()->user()->accessibleUnitIds())->active()->ordered()->get(),
            'suppliers' => Supplier::query()->active()->orderBy('name')->get(),
            'variants' => ProductVariant::query()->with('product.brand')
                ->whereHas('product', fn ($query) => $query->where('product_type', 'PHONE'))
                ->get()
                ->sortBy(fn (ProductVariant $variant) => $variant->displayName())
                ->values(),
            'batch' => $this->batchId !== null ? ImportBatch::query()->find($this->batchId) : null,
            'currency' => config('erp.currency.symbol'),
        ]);
    }
}
