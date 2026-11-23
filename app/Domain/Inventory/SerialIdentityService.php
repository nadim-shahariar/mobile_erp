<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

use App\Enums\Custody;
use App\Enums\ImeiStatus;
use App\Enums\Sellability;
use App\Exceptions\DomainRuleViolationException;
use App\Exceptions\DuplicateImeiException;
use App\Exceptions\InvalidImeiException;
use App\Models\ProductVariant;
use App\Models\SerializedIdentifier;
use App\Models\SerializedUnit;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates and maintains handset identities.
 *
 * Uniqueness is enforced by serialized_identifiers.normalized_value, which is
 * independent of which slot (IMEI1/IMEI2) holds the value, so a number cannot
 * appear as IMEI1 on one handset and IMEI2 on another.
 */
final class SerialIdentityService
{
    public function __construct(private readonly ImeiNormalizer $normalizer) {}

    /**
     * @param  array{primary_imei?: ?string, secondary_imei?: ?string, allow_legacy?: bool, generate_internal_serial?: bool, flag_reason?: ?string}  $identity
     * @param  array{battery_health?: ?int, cycle_count?: ?int, condition_grade?: ?string, box_kit?: ?string, notes?: ?string, initial_unit_id: int}  $attributes
     */
    public function register(ProductVariant $variant, array $identity, array $attributes, User $actor): SerializedUnit
    {
        $initialUnitId = (int) ($attributes['initial_unit_id'] ?? 0);

        if ($initialUnitId <= 0) {
            throw DomainRuleViolationException::message(
                'A receiving business unit is required to register a handset.',
                ['unit_id' => 'Choose the unit that physically receives this handset.'],
            );
        }

        $primary = $this->normalizer->normalize($identity['primary_imei'] ?? null);
        $secondary = $this->normalizer->normalize($identity['secondary_imei'] ?? null);
        $allowLegacy = (bool) ($identity['allow_legacy'] ?? false);
        $internal = (bool) ($identity['generate_internal_serial'] ?? false);
        $flagReason = trim((string) ($identity['flag_reason'] ?? ''));

        $validated = $this->normalizer->validatePair($primary, $secondary, $allowLegacy, $internal);

        if ($validated['status'] !== ImeiStatus::Valid && $flagReason === '') {
            throw DomainRuleViolationException::message(
                'A reason is required when registering a legacy/unverified identity or an internal serial.',
                ['flag_reason' => 'Explain why this handset has no verifiable IMEI.'],
            );
        }

        if ($allowLegacy && ! $actor->can('inventory.imei.override')) {
            throw DomainRuleViolationException::message(
                'You are not allowed to flag IMEIs as legacy/unverified.',
                ['primary_imei' => 'Missing permission: inventory.imei.override'],
            );
        }

        $expectedSellingPrice = isset($attributes['expected_selling_price']) && $attributes['expected_selling_price'] !== ''
            ? Money::of($attributes['expected_selling_price'])
            : null;

        if ($expectedSellingPrice?->isNegative()) {
            throw DomainRuleViolationException::message(
                'Expected selling price cannot be negative.',
                ['expected_selling_price' => 'Enter zero or a positive amount.'],
            );
        }

        $this->assertIdentifiersAreFree($validated['primary'], $validated['secondary']);

        try {
            return DB::transaction(function () use ($variant, $validated, $attributes, $actor, $flagReason, $internal, $expectedSellingPrice): SerializedUnit {
                $unit = SerializedUnit::create([
                    'product_variant_id' => $variant->getKey(),
                    'primary_imei' => $validated['primary'],
                    'secondary_imei' => $validated['secondary'],
                    'imei_status' => $validated['status'],
                    'imei_flag_reason' => $validated['status'] === ImeiStatus::Valid ? null : $flagReason,
                    'battery_health' => $attributes['battery_health'] ?? null,
                    'cycle_count' => $attributes['cycle_count'] ?? null,
                    'condition_grade' => $attributes['condition_grade'] ?? null,
                    'box_kit' => $attributes['box_kit'] ?? null,
                    'expected_selling_price' => $expectedSellingPrice?->amount,
                    'status_note' => filled($attributes['status_note'] ?? null)
                        ? mb_substr(trim((string) $attributes['status_note']), 0, 255)
                        : null,
                    'notes' => $attributes['notes'] ?? null,
                    // Registered but not sellable until a cycle is opened and
                    // the purchase/inspection receipt activates it.
                    'current_custody' => Custody::InUnit,
                    'current_unit_id' => (int) $attributes['initial_unit_id'],
                    'sellability_status' => Sellability::NotForSale,
                    'version' => 0,
                    'created_by' => $actor->getKey(),
                ]);

                if ($validated['primary'] !== null) {
                    $this->storeIdentifier($unit, SerializedIdentifier::TYPE_IMEI1, $validated['primary']);
                }

                if ($validated['secondary'] !== null) {
                    $this->storeIdentifier($unit, SerializedIdentifier::TYPE_IMEI2, $validated['secondary']);
                }

                if ($internal) {
                    $serial = 'SN-'.Str::upper((string) Str::ulid());
                    $this->storeIdentifier($unit, SerializedIdentifier::TYPE_SERIAL, $serial);
                    $unit->forceFill(['primary_imei' => $serial])->save();
                }

                AuditLogger::record(
                    'inventory.unit_registered',
                    $unit,
                    [],
                    $unit->only(['product_variant_id', 'primary_imei', 'secondary_imei', 'imei_status', 'current_unit_id']),
                    $flagReason ?: null,
                    (int) $attributes['initial_unit_id'],
                    $actor,
                );

                return $unit->refresh();
            });
        } catch (UniqueConstraintViolationException $exception) {
            throw DuplicateImeiException::forValue(
                (string) ($validated['primary'] ?? $validated['secondary']),
                'primary_imei',
            );
        }
    }

    /**
     * Correct identifiers on an existing, not-yet-sold handset.
     */
    public function updateIdentifiers(
        SerializedUnit $unit,
        ?string $primaryRaw,
        ?string $secondaryRaw,
        User $actor,
        bool $allowLegacy = false,
        ?string $flagReason = null,
    ): SerializedUnit {
        $unit->refresh();

        if (in_array($unit->current_custody, [
            Custody::WithCustomer,
            Custody::WrittenOff,
        ], true)) {
            throw DomainRuleViolationException::message(
                'Identifiers cannot be changed while the handset is with a customer or written off.',
                ['primary_imei' => 'This handset is not in company custody.'],
            );
        }

        $primary = $this->normalizer->normalize($primaryRaw);
        $secondary = $this->normalizer->normalize($secondaryRaw);

        if ($primary === null && $secondary === null) {
            throw InvalidImeiException::missing();
        }

        $validated = $this->normalizer->validatePair($primary, $secondary, $allowLegacy, false);

        if ($validated['status'] === ImeiStatus::LegacyUnverified) {
            if (trim((string) $flagReason) === '') {
                throw DomainRuleViolationException::message(
                    'A reason is required to keep an unverified/legacy IMEI.',
                    ['flag_reason' => 'Explain why this IMEI is unverified.'],
                );
            }

            if (! $actor->can('inventory.imei.override')) {
                throw DomainRuleViolationException::message(
                    'You are not allowed to flag IMEIs as legacy/unverified.',
                    ['primary_imei' => 'Missing permission: inventory.imei.override'],
                );
            }
        }

        $this->assertIdentifiersAreFree($validated['primary'], $validated['secondary'], $unit->getKey());

        $old = $unit->only(['primary_imei', 'secondary_imei', 'imei_status']);

        try {
            DB::transaction(function () use ($unit, $validated, $actor, $flagReason): void {
                $wanted = array_filter([
                    SerializedIdentifier::TYPE_IMEI1 => $validated['primary'],
                    SerializedIdentifier::TYPE_IMEI2 => $validated['secondary'],
                ], fn ($value) => $value !== null);

                $existing = $unit->identifiers()->get()->keyBy('identifier_type');

                foreach ($existing as $type => $identifier) {
                    if (! array_key_exists($type, $wanted)) {
                        $identifier->delete();
                    }
                }

                foreach ($wanted as $type => $value) {
                    $identifier = $existing->get($type);

                    if ($identifier === null) {
                        $this->storeIdentifier($unit, $type, $value);
                    } elseif ($identifier->normalized_value !== $value) {
                        $identifier->forceFill(['normalized_value' => $value, 'raw_value' => $value])->save();
                    }
                }

                $unit->forceFill([
                    'primary_imei' => $validated['primary'],
                    'secondary_imei' => $validated['secondary'],
                    'imei_status' => $validated['status'],
                    'imei_flag_reason' => $validated['status'] === ImeiStatus::Valid ? null : trim((string) $flagReason),
                    'version' => (int) $unit->version + 1,
                ])->save();

                AuditLogger::record(
                    'inventory.identifiers_updated',
                    $unit,
                    $old,
                    $unit->only(['primary_imei', 'secondary_imei', 'imei_status']),
                    $flagReason,
                    $unit->current_unit_id,
                    $actor,
                );
            });
        } catch (UniqueConstraintViolationException) {
            throw DuplicateImeiException::forValue((string) ($validated['primary'] ?? $validated['secondary']));
        }

        return $unit->refresh();
    }

    public function findByImei(?string $raw): ?SerializedUnit
    {
        $normalized = $this->normalizer->normalize($raw);

        if ($normalized === null) {
            return null;
        }

        $identifier = SerializedIdentifier::query()
            ->where('normalized_value', $normalized)
            ->first();

        return $identifier?->unit()
            ->with(['variant.product.brand', 'currentUnit', 'activeCycle', 'lastEvent'])
            ->first();
    }

    private function assertIdentifiersAreFree(?string $primary, ?string $secondary, ?int $ignoreUnitId = null): void
    {
        foreach (['primary_imei' => $primary, 'secondary_imei' => $secondary] as $field => $value) {
            if ($value === null) {
                continue;
            }

            $query = SerializedIdentifier::query()->where('normalized_value', $value);

            if ($ignoreUnitId !== null) {
                $query->where('serialized_unit_id', '!=', $ignoreUnitId);
            }

            if ($query->exists()) {
                throw DuplicateImeiException::forValue($value, $field);
            }
        }
    }

    private function storeIdentifier(SerializedUnit $unit, string $type, string $normalizedValue): void
    {
        SerializedIdentifier::create([
            'serialized_unit_id' => $unit->getKey(),
            'identifier_type' => $type,
            'raw_value' => $normalizedValue,
            'normalized_value' => $normalizedValue,
        ]);
    }
}
