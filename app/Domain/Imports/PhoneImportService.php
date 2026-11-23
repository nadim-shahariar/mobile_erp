<?php

declare(strict_types=1);

namespace App\Domain\Imports;

use App\Domain\Inventory\ImeiNormalizer;
use App\Models\ProductVariant;
use App\Models\SerializedIdentifier;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Reads and validates an XLSX/CSV phone import.
 *
 * The field set mirrors the shop's existing sheet row (model, IMEI, buying
 * party, storage, battery, cycles, colour, box, buy/sell price, receiving date,
 * adapter/cable). Sold/customer/profit columns are intentionally not imported:
 * this wizard books handsets into current stock, it does not fabricate history.
 *
 * Nothing is written here: the wizard shows a per-row dry run first, and only
 * valid rows are converted into a real purchase receipt on commit.
 */
final class PhoneImportService
{
    public const FIELDS = [
        'imei1' => ['label' => 'IMEI 1', 'required' => true],
        'imei2' => ['label' => 'IMEI 2', 'required' => false],
        'model' => ['label' => 'Model', 'required' => false],
        'brand' => ['label' => 'Brand', 'required' => false],
        'storage' => ['label' => 'Storage', 'required' => false],
        'colour' => ['label' => 'Colour', 'required' => false],
        'acquisition_cost' => ['label' => 'Buy price', 'required' => true],
        'selling_price' => ['label' => 'Selling price', 'required' => false],
        'buying_party' => ['label' => 'Buying party', 'required' => false],
        'condition_grade' => ['label' => 'Condition grade', 'required' => false],
        'battery_health' => ['label' => 'Battery health %', 'required' => false],
        'cycle_count' => ['label' => 'Cycle count', 'required' => false],
        'box_kit' => ['label' => 'Box / kit', 'required' => false],
        'status_note' => ['label' => 'Due / status reason', 'required' => false],
        'receiving_date' => ['label' => 'Receiving date', 'required' => false],
        'adapter_name' => ['label' => 'Adapter name', 'required' => false],
        'adapter_price' => ['label' => 'Adapter price', 'required' => false],
        'cable_name' => ['label' => 'Cable name', 'required' => false],
        'cable_price' => ['label' => 'Cable price', 'required' => false],
    ];

    public function __construct(private readonly ImeiNormalizer $normalizer) {}

    /**
     * @return array{headers: list<string>, rows: list<list<string>>}
     */
    public function read(string $absolutePath): array
    {
        $reader = IOFactory::createReaderForFile($absolutePath);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($absolutePath);
        $sheet = $spreadsheet->getActiveSheet();
        $data = $sheet->toArray(null, true, false, false);

        if ($data === []) {
            return ['headers' => [], 'rows' => []];
        }

        $headers = array_map(
            fn ($header) => trim((string) $header),
            array_shift($data),
        );

        $rows = [];

        foreach ($data as $row) {
            $row = array_map(fn ($cell) => trim((string) $cell), $row);

            if (implode('', $row) === '') {
                continue;
            }

            $rows[] = $row;

            if (count($rows) >= 2000) {
                break;
            }
        }

        $spreadsheet->disconnectWorksheets();

        return ['headers' => $headers, 'rows' => $rows];
    }

    /**
     * @param  list<string>  $headers
     * @return array<string, int|string>
     */
    public function guessMapping(array $headers): array
    {
        $normalized = [];

        foreach ($headers as $index => $header) {
            $normalized[$index] = strtolower(preg_replace('/[^a-z0-9]+/i', ' ', $header) ?? '');
        }

        $mapping = array_fill_keys(array_keys(self::FIELDS), '');

        foreach ($normalized as $index => $header) {
            if ($mapping['imei2'] === '' && preg_match('/imei\s*(2|no\s*2)/', $header)) {
                $mapping['imei2'] = $index;
            } elseif ($mapping['imei1'] === '' && str_contains($header, 'imei')) {
                $mapping['imei1'] = $index;
            } elseif ($mapping['adapter_price'] === '' && preg_match('/adapter.*(price|amount)/', $header)) {
                $mapping['adapter_price'] = $index;
            } elseif ($mapping['adapter_name'] === '' && str_contains($header, 'adapter')) {
                $mapping['adapter_name'] = $index;
            } elseif ($mapping['cable_price'] === '' && preg_match('/cable.*(price|amount)/', $header)) {
                $mapping['cable_price'] = $index;
            } elseif ($mapping['cable_name'] === '' && str_contains($header, 'cable')) {
                $mapping['cable_name'] = $index;
            } elseif ($mapping['selling_price'] === '' && preg_match('/sell(ing)?\s*(price|rate)|asking\s*price/', $header)) {
                $mapping['selling_price'] = $index;
            } elseif ($mapping['acquisition_cost'] === '' && preg_match('/buy(ing)?\s*(price|rate)|purchase\s*(price|rate|cost)|cost\s*price|^cost$|^buy$/', $header)) {
                $mapping['acquisition_cost'] = $index;
            } elseif ($mapping['buying_party'] === '' && preg_match('/buying\s*party|bought\s*from|seller|supplier|party/', $header)) {
                $mapping['buying_party'] = $index;
            } elseif ($mapping['status_note'] === '' && preg_match('/due\s*for\s*reason|reason|remark|note|comment/', $header)) {
                $mapping['status_note'] = $index;
            } elseif ($mapping['receiving_date'] === '' && preg_match('/receiv(ing|ed)\s*date|receive\s*date|purchase\s*date|inward\s*date/', $header)) {
                $mapping['receiving_date'] = $index;
            } elseif ($mapping['condition_grade'] === '' && preg_match('/condition|grade/', $header)) {
                $mapping['condition_grade'] = $index;
            } elseif ($mapping['battery_health'] === '' && preg_match('/battery|health/', $header)) {
                $mapping['battery_health'] = $index;
            } elseif ($mapping['cycle_count'] === '' && str_contains($header, 'cycle')) {
                $mapping['cycle_count'] = $index;
            } elseif ($mapping['box_kit'] === '' && preg_match('/box|kit/', $header)) {
                $mapping['box_kit'] = $index;
            } elseif ($mapping['brand'] === '' && str_contains($header, 'brand')) {
                $mapping['brand'] = $index;
            } elseif ($mapping['storage'] === '' && preg_match('/storage|memory|rom/', $header)) {
                $mapping['storage'] = $index;
            } elseif ($mapping['colour'] === '' && preg_match('/colou?r/', $header)) {
                $mapping['colour'] = $index;
            } elseif ($mapping['model'] === '' && preg_match('/model|product|item|device|handset/', $header)) {
                $mapping['model'] = $index;
            }
        }

        return $mapping;
    }

    /**
     * @param  list<list<string>>  $rows
     * @param  array<string, int|string>  $mapping
     * @param  Collection<int, ProductVariant>  $variants
     * @return array{rows: list<array<string, mixed>>, valid: int, invalid: int}
     */
    public function analyze(array $rows, array $mapping, ?int $defaultVariantId, Collection $variants, array $soldColumns = []): array
    {
        $defaultVariant = $defaultVariantId !== null ? $variants->firstWhere('id', $defaultVariantId) : null;

        $analyzed = [];
        $seen = [];

        foreach ($rows as $index => $row) {
            $rowNumber = $index + 2;
            $errors = [];

            $cell = function (string $field) use ($row, $mapping): ?string {
                $column = $mapping[$field] ?? '';

                if ($column === '' || ! isset($row[(int) $column])) {
                    return null;
                }

                $value = trim((string) $row[(int) $column]);

                return $value === '' ? null : $value;
            };

            foreach ($soldColumns as $soldColumn) {
                if (isset($row[$soldColumn]) && trim((string) $row[$soldColumn]) !== '') {
                    $errors[] = 'This row has a selling date / customer, so it is a sold or historical row. Import only inbound stock; historical sales need the legacy-archive workflow.';

                    break;
                }
            }

            $imei1 = $this->normalizer->normalize($cell('imei1'));
            $imei2 = $this->normalizer->normalize($cell('imei2'));

            if ($imei1 === null) {
                $errors[] = 'IMEI 1 is missing.';
            } elseif (! $this->normalizer->isWellFormed($imei1)) {
                $errors[] = "IMEI 1 [{$imei1}] is not a valid 15-digit IMEI (legacy rows must be added manually with an override).";
            }

            if ($imei2 !== null && ! $this->normalizer->isWellFormed($imei2)) {
                $errors[] = "IMEI 2 [{$imei2}] is invalid.";
            }

            if ($imei1 !== null && $imei1 === $imei2) {
                $errors[] = 'IMEI 2 must differ from IMEI 1.';
            }

            foreach (array_filter([$imei1, $imei2]) as $value) {
                if (isset($seen[$value])) {
                    $errors[] = "IMEI [{$value}] is duplicated in this file (row {$seen[$value]}).";
                } else {
                    $seen[$value] = $rowNumber;
                }
            }

            [$variant, $variantError] = $this->resolveVariant(
                variants: $variants,
                model: $cell('model'),
                brand: $cell('brand'),
                storage: $cell('storage'),
                colour: $cell('colour'),
                defaultVariant: $defaultVariant,
            );

            if ($variantError !== null) {
                $errors[] = $variantError;
            }

            $cost = $cell('acquisition_cost');

            if ($cost === null) {
                $errors[] = 'Buy price is missing.';
            } elseif (! is_numeric(str_replace(',', '', $cost))) {
                $errors[] = "Buy price [{$cost}] is not numeric.";
            }

            $sellingPrice = $this->numericOrNull($cell('selling_price'), 'Selling price', $errors);
            $battery = $this->numericOrNull($cell('battery_health'), 'Battery health', $errors);
            $cycles = $this->numericOrNull($cell('cycle_count'), 'Cycle count', $errors);
            $adapterPrice = $this->numericOrNull($cell('adapter_price'), 'Adapter price', $errors);
            $cablePrice = $this->numericOrNull($cell('cable_price'), 'Cable price', $errors);

            if ($battery !== null && ($battery < 0 || $battery > 100)) {
                $errors[] = 'Battery health must be between 0 and 100.';
            }

            if ($cycles !== null && $cycles < 0) {
                $errors[] = 'Cycle count cannot be negative.';
            }

            if ($sellingPrice !== null && $sellingPrice < 0) {
                $errors[] = 'Selling price cannot be negative.';
            }

            if ($adapterPrice !== null && $adapterPrice < 0) {
                $errors[] = 'Adapter price cannot be negative.';
            }

            if ($cablePrice !== null && $cablePrice < 0) {
                $errors[] = 'Cable price cannot be negative.';
            }

            $adapterName = $cell('adapter_name');
            $cableName = $cell('cable_name');

            if ($adapterName === null && $adapterPrice !== null && $adapterPrice > 0) {
                $errors[] = 'Adapter price is present but the adapter name is blank.';
            }

            if ($cableName === null && $cablePrice !== null && $cablePrice > 0) {
                $errors[] = 'Cable price is present but the cable name is blank.';
            }

            $receivedAt = null;

            if ($cell('receiving_date') !== null) {
                $receivedAt = $this->parseDate($cell('receiving_date'));

                if ($receivedAt === null) {
                    $errors[] = "Receiving date [{$cell('receiving_date')}] is not a valid date.";
                }
            }

            $analyzed[] = [
                'row' => $rowNumber,
                'imei1' => $imei1,
                'imei2' => $imei2,
                'variant_id' => $variant?->getKey(),
                'variant_label' => $variant?->displayName(),
                'cost' => $cost !== null ? str_replace(',', '', $cost) : null,
                'selling_price' => $sellingPrice !== null ? number_format($sellingPrice, 2, '.', '') : null,
                'buying_party' => $cell('buying_party'),
                'condition_grade' => $cell('condition_grade'),
                'battery_health' => $battery !== null ? (int) $battery : null,
                'cycle_count' => $cycles !== null ? (int) $cycles : null,
                'box_kit' => $cell('box_kit'),
                'status_note' => $cell('status_note'),
                'received_at' => $receivedAt,
                'adapter_name' => $adapterName,
                'adapter_price' => $adapterPrice !== null ? number_format($adapterPrice, 2, '.', '') : null,
                'cable_name' => $cableName,
                'cable_price' => $cablePrice !== null ? number_format($cablePrice, 2, '.', '') : null,
                'errors' => $errors,
            ];
        }

        $allValues = array_keys($seen);

        if ($allValues !== []) {
            $existing = SerializedIdentifier::query()
                ->whereIn('normalized_value', $allValues)
                ->pluck('normalized_value')
                ->flip();

            foreach ($analyzed as $position => $row) {
                foreach (array_filter([$row['imei1'], $row['imei2']]) as $value) {
                    if ($existing->has($value)) {
                        $analyzed[$position]['errors'][] = "IMEI [{$value}] already exists in the inventory.";
                    }
                }
            }
        }

        $valid = count(array_filter($analyzed, fn (array $row) => $row['errors'] === []));

        return [
            'rows' => $analyzed,
            'valid' => $valid,
            'invalid' => count($analyzed) - $valid,
        ];
    }

    /**
     * Header indexes that indicate a sold/historical row. These columns are not
     * mapped into inventory; if they contain data the row is rejected so no
     * phantom stock or fabricated sale history is created.
     *
     * @param  list<string>  $headers
     * @return list<int>
     */
    public function soldColumnIndexes(array $headers): array
    {
        $indexes = [];

        foreach ($headers as $index => $header) {
            $normalized = strtolower(preg_replace('/[^a-z0-9]+/i', ' ', $header) ?? '');

            if (preg_match('/selling\s*date|sold\s*date|customer\s*name/', $normalized)) {
                $indexes[] = $index;
            }
        }

        return $indexes;
    }

    /**
     * Resolve the catalog variant from whichever sheet columns are mapped.
     * Model alone is not enough when a product has several storage/colour
     * variants, so brand/storage/colour narrow the candidates explicitly.
     *
     * @param  Collection<int, ProductVariant>  $variants
     * @return array{0: ?ProductVariant, 1: ?string}
     */
    private function resolveVariant(
        Collection $variants,
        ?string $model,
        ?string $brand,
        ?string $storage,
        ?string $colour,
        ?ProductVariant $defaultVariant,
    ): array {
        $hasFilters = $model !== null || $brand !== null || $storage !== null || $colour !== null;

        if (! $hasFilters) {
            return [$defaultVariant, $defaultVariant === null ? 'No phone model could be resolved for this row.' : null];
        }

        $candidates = $variants;

        if ($model !== null) {
            $needle = mb_strtolower($model);

            $candidates = $candidates->filter(function (ProductVariant $variant) use ($needle): bool {
                $product = $variant->product;
                $haystacks = [
                    (string) $variant->sku,
                    (string) $product?->name,
                    (string) $product?->model,
                    $variant->displayName(),
                    trim(($product?->name ?? '').' '.($variant->storage ?? '')),
                ];

                foreach ($haystacks as $haystack) {
                    $haystack = mb_strtolower(trim((string) $haystack));

                    if ($haystack !== '' && ($haystack === $needle || str_contains($haystack, $needle))) {
                        return true;
                    }
                }

                return false;
            });
        }

        if ($brand !== null) {
            $needle = mb_strtolower($brand);
            $candidates = $candidates->filter(fn (ProductVariant $variant) => mb_strtolower((string) $variant->product?->brand?->name) === $needle
                || str_contains(mb_strtolower((string) $variant->product?->brand?->name), $needle));
        }

        if ($storage !== null) {
            $needle = $this->normalizeAttribute($storage);
            $candidates = $candidates->filter(fn (ProductVariant $variant) => $this->normalizeAttribute((string) $variant->storage) === $needle);
        }

        if ($colour !== null) {
            $needle = mb_strtolower(trim($colour));
            $candidates = $candidates->filter(fn (ProductVariant $variant) => mb_strtolower(trim((string) $variant->color)) === $needle);
        }

        if ($candidates->count() === 1) {
            return [$candidates->first(), null];
        }

        if ($candidates->count() === 0) {
            return [null, 'Model/brand/storage/colour do not match any catalog variant.'];
        }

        return [null, "Model matches {$candidates->count()} catalog variants; map brand/storage/colour to disambiguate."];
    }

    private function normalizeAttribute(string $value): string
    {
        return mb_strtoupper(preg_replace('/\s+/', '', $value) ?? '');
    }

    /** @param list<string> $errors */
    private function numericOrNull(?string $value, string $label, array &$errors): ?float
    {
        if ($value === null) {
            return null;
        }

        $cleaned = str_replace([',', ' '], '', $value);

        if (! is_numeric($cleaned)) {
            $errors[] = "{$label} [{$value}] is not numeric.";

            return null;
        }

        return (float) $cleaned;
    }

    private function parseDate(string $value): ?string
    {
        if (is_numeric($value) && (float) $value > 20000 && (float) $value < 80000) {
            try {
                return ExcelDate::excelToDateTimeObject((float) $value)->format('Y-m-d');
            } catch (\Throwable) {
                return null;
            }
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
