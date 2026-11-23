<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

use App\Enums\Custody;
use App\Exceptions\DomainRuleViolationException;
use App\Models\InventoryCycle;
use App\Models\SerializedUnit;
use App\Models\SerializedUnitAccessory;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Manages the adapter/cable bundle the shop records on each handset row.
 *
 * Bundle prices are recorded exactly as entered (including zero) and do not
 * alter the handset acquisition cost basis, which stays driven by the purchase
 * line or manual intake cost.
 */
final class UnitAccessoryService
{
    /**
     * @param  array{adapter_name?: ?string, adapter_price?: string|int|float|null, cable_name?: ?string, cable_price?: string|int|float|null}  $bundle
     */
    public function syncBundle(
        SerializedUnit $unit,
        array $bundle,
        ?InventoryCycle $cycle = null,
        ?User $actor = null,
    ): void {
        $prepared = $this->prepare($bundle);

        DB::transaction(function () use ($unit, $prepared, $cycle, $actor): void {
            $locked = SerializedUnit::query()->whereKey($unit->getKey())->lockForUpdate()->firstOrFail();

            if (in_array($locked->current_custody, [Custody::WithCustomer, Custody::WrittenOff], true)) {
                throw DomainRuleViolationException::message(
                    'The adapter/cable bundle cannot be changed while the handset is with a customer or written off.',
                    ['extras' => 'This handset is not in company custody.'],
                );
            }

            $old = $locked->accessories()
                ->whereIn('accessory_type', [SerializedUnitAccessory::TYPE_ADAPTER, SerializedUnitAccessory::TYPE_CABLE])
                ->get()
                ->map(fn (SerializedUnitAccessory $accessory) => [
                    'type' => $accessory->accessory_type,
                    'name' => $accessory->name,
                    'price' => (string) $accessory->price,
                ])
                ->all();

            $locked->accessories()
                ->whereIn('accessory_type', [SerializedUnitAccessory::TYPE_ADAPTER, SerializedUnitAccessory::TYPE_CABLE])
                ->delete();

            foreach ($prepared as $type => $row) {
                $locked->accessories()->create([
                    'inventory_cycle_id' => $cycle?->getKey() ?? $locked->activeCycle()->value('id'),
                    'accessory_type' => $type,
                    'name' => $row['name'],
                    'price' => $row['price'],
                ]);
            }

            AuditLogger::record(
                'inventory.accessory_bundle_updated',
                $locked,
                ['bundle' => $old],
                ['bundle' => array_map(fn (array $row, string $type) => ['type' => $type] + $row, $prepared, array_keys($prepared))],
                null,
                $locked->current_unit_id,
                $actor,
            );

            $unit->unsetRelation('accessories');
        });
    }

    /**
     * @return array{adapter_name: string, adapter_price: string, cable_name: string, cable_price: string}
     */
    public function bundle(SerializedUnit $unit): array
    {
        $accessories = $unit->accessories->keyBy('accessory_type');

        return [
            'adapter_name' => (string) ($accessories->get(SerializedUnitAccessory::TYPE_ADAPTER)?->name ?? ''),
            'adapter_price' => (string) ($accessories->get(SerializedUnitAccessory::TYPE_ADAPTER)?->price ?? ''),
            'cable_name' => (string) ($accessories->get(SerializedUnitAccessory::TYPE_CABLE)?->name ?? ''),
            'cable_price' => (string) ($accessories->get(SerializedUnitAccessory::TYPE_CABLE)?->price ?? ''),
        ];
    }

    public function bundleTotal(SerializedUnit $unit): Money
    {
        return $unit->accessories->reduce(
            fn (Money $carry, SerializedUnitAccessory $accessory) => $carry->add($accessory->price),
            Money::zero(),
        );
    }

    /**
     * @param  array{adapter_name?: ?string, adapter_price?: string|int|float|null, cable_name?: ?string, cable_price?: string|int|float|null}  $bundle
     * @return array<string, array{name: string, price: string}>
     */
    private function prepare(array $bundle): array
    {
        $prepared = [];

        foreach ([
            SerializedUnitAccessory::TYPE_ADAPTER => ['adapter_name', 'adapter_price'],
            SerializedUnitAccessory::TYPE_CABLE => ['cable_name', 'cable_price'],
        ] as $type => [$nameKey, $priceKey]) {
            $name = trim((string) ($bundle[$nameKey] ?? ''));
            $rawPrice = $bundle[$priceKey] ?? null;
            $price = Money::of($rawPrice === '' || $rawPrice === null ? '0' : $rawPrice);

            if ($price->isNegative()) {
                throw DomainRuleViolationException::message(
                    strtoupper($type).' price cannot be negative.',
                    [$priceKey => 'Enter zero or a positive amount.'],
                );
            }

            if ($name === '' && $price->isZero()) {
                continue;
            }

            if ($name === '') {
                throw DomainRuleViolationException::message(
                    'An item name is required when a '.strtolower($type).' price is entered.',
                    [$nameKey => 'Enter the adapter/cable name or clear the price.'],
                );
            }

            $prepared[$type] = ['name' => mb_substr($name, 0, 120), 'price' => $price->amount];
        }

        return $prepared;
    }
}
