<?php

declare(strict_types=1);

namespace App\Livewire\Inventory;

use App\Domain\Inventory\ImeiNormalizer;
use App\Domain\Purchases\PurchaseService;
use App\Exceptions\ErpException;
use App\Models\BusinessUnit;
use App\Models\ProductVariant;
use App\Models\SerializedIdentifier;
use App\Models\SerializedUnit;
use App\Models\Supplier;
use App\Support\BusinessClock;
use App\Support\UnitContext;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class BulkEntry extends Component
{
    public string $unitId = '';

    public ?string $supplierId = null;

    public string $variantId = '';

    public string $unitCost = '';

    public string $purchaseDate = '';

    public string $payload = '';

    public string $notes = '';

    public function mount(UnitContext $unitContext): void
    {
        $this->authorize('import', SerializedUnit::class);

        $current = $unitContext->current(auth()->user());
        $this->unitId = (string) ($current?->getKey() ?? '');
        $this->purchaseDate = BusinessClock::today()->toDateString();
    }

    public function save(PurchaseService $purchases, ImeiNormalizer $normalizer): void
    {
        $this->authorize('import', SerializedUnit::class);

        $user = auth()->user();
        $accessible = $user->accessibleUnitIds();

        if (! $user->can('purchase.create')) {
            $this->addError('form', 'Bulk entry books a purchase, so purchase.create is required.');

            return;
        }

        $this->supplierId = $this->supplierId === '' ? null : $this->supplierId;

        $validated = $this->validate([
            'unitId' => ['required', 'integer', Rule::in($accessible)],
            'supplierId' => ['nullable', 'integer', 'exists:suppliers,id'],
            'variantId' => ['required', 'integer', 'exists:product_variants,id'],
            'unitCost' => ['required', 'numeric', 'min:0'],
            'purchaseDate' => ['required', 'date'],
            'payload' => ['required', 'string'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $parsed = $this->parse($normalizer);

        if ($parsed['errors'] !== []) {
            $shown = array_slice($parsed['errors'], 0, 5);
            $extra = count($parsed['errors']) - count($shown);
            $this->addError('payload', implode(' ', $shown).($extra > 0 ? " (+{$extra} more error(s))" : ''));

            return;
        }

        if ($parsed['rows'] === []) {
            $this->addError('payload', 'Paste at least one IMEI.');

            return;
        }

        $variant = ProductVariant::query()->with('product')->findOrFail((int) $validated['variantId']);

        $lines = [];

        foreach ($parsed['rows'] as $row) {
            $lines[] = [
                'product_variant_id' => $variant->getKey(),
                'line_type' => 'PHONE',
                'quantity' => 1,
                'unit_cost' => $row['cost'] ?? (string) $validated['unitCost'],
                'imei_1' => $row['imei1'],
                'imei_2' => $row['imei2'],
                'serial_payload' => ['condition_grade' => 'A'],
                'notes' => $validated['notes'] ?: null,
            ];
        }

        try {
            $purchase = $purchases->create([
                'supplier_id' => ! empty($validated['supplierId']) ? (int) $validated['supplierId'] : null,
                'business_unit_id' => (int) $validated['unitId'],
                'purchase_date' => $validated['purchaseDate'],
                'paid_amount' => '0',
                'reference' => 'BULK-'.BusinessClock::now()->format('YmdHis'),
                'notes' => $validated['notes'] ?: 'Bulk IMEI entry',
            ], $lines, $user);

            if ($user->can('purchase.approve')) {
                $purchases->receive($purchase, $user);
                session()->flash('success', count($lines)." handset(s) registered and received on purchase {$purchase->document_no}.");
            } else {
                session()->flash('error', "Draft purchase {$purchase->document_no} created with ".count($lines).' line(s). A manager must receive it.');
            }

            $this->redirect(route('purchases.show', $purchase), navigate: true);
        } catch (ErpException $exception) {
            foreach ($exception->errors() as $field => $message) {
                $this->addError($field, $message);
            }

            $this->addError('form', $exception->getMessage());
        }
    }

    /**
     * @return array{rows: list<array{imei1: string, imei2: ?string, cost: ?string}>, errors: list<string>}
     */
    private function parse(ImeiNormalizer $normalizer): array
    {
        $rows = [];
        $errors = [];
        $seen = [];
        $lines = preg_split('/\r\n|\r|\n/', trim($this->payload)) ?: [];

        foreach ($lines as $lineNumber => $line) {
            if (trim($line) === '') {
                continue;
            }

            if ($lineNumber === 0 && preg_match('/imei/i', $line)) {
                continue;
            }

            $cells = str_contains($line, "\t") ? explode("\t", $line) : str_getcsv($line);
            $imei1 = $normalizer->normalize($cells[0] ?? null);
            $imei2 = $normalizer->normalize($cells[1] ?? null);
            $cost = isset($cells[2]) && trim((string) $cells[2]) !== '' ? trim((string) $cells[2]) : null;

            if ($imei1 === null) {
                $errors[] = 'Line '.($lineNumber + 1).': missing IMEI.';

                continue;
            }

            if (! $normalizer->isWellFormed($imei1)) {
                $errors[] = 'Line '.($lineNumber + 1).": IMEI {$imei1} is not a valid 15-digit IMEI. Use the import wizard for flagged legacy records.";

                continue;
            }

            if ($imei2 !== null && ! $normalizer->isWellFormed($imei2)) {
                $errors[] = 'Line '.($lineNumber + 1).": IMEI 2 {$imei2} is invalid.";

                continue;
            }

            if ($imei2 !== null && $imei1 === $imei2) {
                $errors[] = 'Line '.($lineNumber + 1).': IMEI 2 must differ from IMEI 1.';

                continue;
            }

            foreach (array_filter([$imei1, $imei2]) as $value) {
                if (isset($seen[$value])) {
                    $errors[] = 'Line '.($lineNumber + 1).": IMEI {$value} is repeated in this paste.";

                    continue 2;
                }

                $seen[$value] = true;
            }

            if ($cost !== null && ! is_numeric($cost)) {
                $errors[] = 'Line '.($lineNumber + 1).': cost must be numeric.';

                continue;
            }

            $rows[] = ['imei1' => $imei1, 'imei2' => $imei2, 'cost' => $cost];
        }

        if ($rows !== []) {
            $existing = SerializedIdentifier::query()
                ->whereIn('normalized_value', array_keys($seen))
                ->pluck('normalized_value')
                ->all();

            foreach ($existing as $duplicate) {
                $errors[] = "IMEI {$duplicate} is already registered to another handset.";
            }
        }

        return ['rows' => $rows, 'errors' => $errors];
    }

    public function render(ImeiNormalizer $normalizer): View
    {
        $preview = $this->payload === ''
            ? ['rows' => [], 'errors' => []]
            : $this->parse($normalizer);

        return view('livewire.inventory.bulk-entry', [
            'preview' => $preview,
            'variants' => ProductVariant::query()->with('product.brand')
                ->whereHas('product', fn ($query) => $query->where('product_type', 'PHONE'))
                ->get()
                ->sortBy(fn (ProductVariant $variant) => $variant->displayName())
                ->values(),
            'suppliers' => Supplier::query()->active()->orderBy('name')->get(),
            'units' => BusinessUnit::query()->whereIn('id', auth()->user()->accessibleUnitIds())->active()->ordered()->get(),
            'canApprove' => auth()->user()->can('purchase.approve'),
            'currency' => config('erp.currency.symbol'),
        ]);
    }
}
