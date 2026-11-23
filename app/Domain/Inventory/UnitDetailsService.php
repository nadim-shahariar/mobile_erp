<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

use App\Enums\Custody;
use App\Exceptions\DomainRuleViolationException;
use App\Models\SerializedUnit;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Edits non-financial handset profile data from the grid. Cost, IMEI identity
 * and state are deliberately excluded: those have dedicated, audited flows.
 */
final class UnitDetailsService
{
    /**
     * @param  array{battery_health?: int|string|null, cycle_count?: int|string|null, condition_grade?: ?string, box_kit?: ?string, notes?: ?string}  $data
     */
    public function update(SerializedUnit $unit, array $data, User $actor): SerializedUnit
    {
        return DB::transaction(function () use ($unit, $data, $actor): SerializedUnit {
            /** @var SerializedUnit $locked */
            $locked = SerializedUnit::query()->whereKey($unit->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->current_custody === Custody::WrittenOff) {
                throw DomainRuleViolationException::message(
                    'A written-off handset profile cannot be edited.',
                    ['unit' => 'Handset is written off.'],
                );
            }

            $old = $locked->only(['battery_health', 'cycle_count', 'condition_grade', 'box_kit', 'notes', 'expected_selling_price', 'status_note']);

            $expectedSellingPrice = null;

            if (($data['expected_selling_price'] ?? null) !== null && $data['expected_selling_price'] !== '') {
                $expectedSellingPrice = Money::of($data['expected_selling_price']);

                if ($expectedSellingPrice->isNegative()) {
                    throw DomainRuleViolationException::message(
                        'Expected selling price cannot be negative.',
                        ['expected_selling_price' => 'Enter zero or a positive amount.'],
                    );
                }
            }

            $locked->forceFill([
                'battery_health' => $this->nullableInt($data['battery_health'] ?? null),
                'cycle_count' => $this->nullableInt($data['cycle_count'] ?? null),
                'condition_grade' => $this->nullableString($data['condition_grade'] ?? null),
                'box_kit' => $this->nullableString($data['box_kit'] ?? null),
                'notes' => $this->nullableString($data['notes'] ?? null),
                'expected_selling_price' => array_key_exists('expected_selling_price', $data)
                    ? $expectedSellingPrice?->amount
                    : $locked->expected_selling_price,
                'status_note' => array_key_exists('status_note', $data)
                    ? $this->nullableString($data['status_note'])
                    : $locked->status_note,
                'version' => (int) $locked->version + 1,
            ])->save();

            AuditLogger::record(
                'inventory.unit_details_updated',
                $locked,
                $old,
                $locked->only(['battery_health', 'cycle_count', 'condition_grade', 'box_kit', 'notes', 'expected_selling_price', 'status_note']),
                null,
                $locked->current_unit_id,
                $actor,
            );

            return $locked->refresh();
        });
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
