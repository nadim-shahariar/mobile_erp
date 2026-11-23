<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

use App\Domain\Accounting\ChartOfAccounts;
use App\Domain\Accounting\JournalPostingService;
use App\Enums\AccessoryMovementReason;
use App\Exceptions\DomainRuleViolationException;
use App\Exceptions\InsufficientStockException;
use App\Models\AccessoryStockBalance;
use App\Models\AccessoryStockMovement;
use App\Models\BusinessUnit;
use App\Models\ProductVariant;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\BusinessClock;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Quantity-tracked accessory stock. All mutations lock the balance row and
 * write an append-only movement, so on-hand quantity can always be rebuilt.
 */
final class AccessoryStockService
{
    public function __construct(
        private readonly ChartOfAccounts $accounts,
        private readonly JournalPostingService $journal,
    ) {}

    public function receive(
        int $businessUnitId,
        int $variantId,
        int $quantity,
        string|Money|null $unitCost,
        AccessoryMovementReason $reason,
        ?User $actor = null,
        ?Model $source = null,
        ?string $note = null,
        ?int $supplierId = null,
    ): AccessoryStockBalance {
        if ($quantity <= 0) {
            throw DomainRuleViolationException::message(
                'Received quantity must be greater than zero.',
                ['quantity' => 'Must be at least 1.'],
            );
        }

        return DB::transaction(function () use ($businessUnitId, $variantId, $quantity, $unitCost, $reason, $actor, $source, $note, $supplierId): AccessoryStockBalance {
            $balance = $this->lockBalance($businessUnitId, $variantId);

            $oldQty = (int) $balance->qty_on_hand;
            $oldAvg = (string) $balance->average_cost;
            $cost = $unitCost === null ? Money::of($oldAvg) : Money::of($unitCost);

            $newQty = $oldQty + $quantity;

            $movementValue = bcmul((string) $quantity, $cost->amount, 6);
            $existingValue = bcmul((string) $oldQty, $oldAvg, 6);
            $newAverage = $newQty > 0
                ? Money::roundToScale(bcdiv(bcadd($existingValue, $movementValue, 6), (string) $newQty, 8), 4)
                : '0.0000';

            $balance->forceFill([
                'qty_on_hand' => $newQty,
                'average_cost' => $newAverage,
                'version' => (int) $balance->version + 1,
            ])->save();

            $movement = AccessoryStockMovement::create([
                'business_unit_id' => $businessUnitId,
                'supplier_id' => $supplierId,
                'product_variant_id' => $variantId,
                'quantity_delta' => $quantity,
                'reason' => $reason,
                'unit_cost' => $cost->amount,
                'balance_after' => $newQty,
                'source_type' => $source?->getMorphClass(),
                'source_id' => $source?->getKey(),
                'actor_id' => $actor?->getKey(),
                'note' => $note,
            ]);

            if (in_array($reason, [AccessoryMovementReason::Opening, AccessoryMovementReason::AdjustmentIn], true)) {
                $value = $cost->mul($quantity);

                if ($value->isPositive()) {
                    $businessUnit = BusinessUnit::query()->findOrFail($businessUnitId);

                    $this->journal->post(
                        lines: [
                            ['account_id' => $this->accounts->accessoryInventory()->getKey(), 'debit' => $value->amount, 'memo' => 'Accessory stock in'],
                            ['account_id' => $this->accounts->openingEquity()->getKey(), 'credit' => $value->amount, 'memo' => 'Accessory opening equity'],
                        ],
                        memo: 'Accessory stock in '.($movement->id),
                        entryDate: BusinessClock::today(),
                        sourceKey: 'accessory-movement:'.$movement->id,
                        source: $businessUnit,
                        actor: $actor,
                    );
                }
            }

            AuditLogger::record(
                'inventory.accessory_received',
                $balance,
                ['qty_on_hand' => $oldQty, 'average_cost' => $oldAvg],
                ['qty_on_hand' => $newQty, 'average_cost' => $newAverage, 'reason' => $reason->value],
                $note,
                $businessUnitId,
                $actor,
            );

            return $balance->refresh();
        });
    }

    public function issue(
        int $businessUnitId,
        int $variantId,
        int $quantity,
        AccessoryMovementReason $reason,
        ?User $actor = null,
        ?Model $source = null,
        ?string $note = null,
        bool $allowNegative = false,
    ): AccessoryStockBalance {
        if ($quantity <= 0) {
            throw DomainRuleViolationException::message(
                'Issued quantity must be greater than zero.',
                ['quantity' => 'Must be at least 1.'],
            );
        }

        return DB::transaction(function () use ($businessUnitId, $variantId, $quantity, $reason, $actor, $source, $note, $allowNegative): AccessoryStockBalance {
            $balance = $this->lockBalance($businessUnitId, $variantId);

            $available = (int) $balance->qty_on_hand - (int) $balance->qty_reserved;

            if (! $allowNegative && $quantity > $available) {
                $variant = ProductVariant::query()->with('product')->find($variantId);

                throw InsufficientStockException::accessory(
                    $variant?->displayName() ?? "variant #{$variantId}",
                    $quantity,
                    $available,
                );
            }

            $oldQty = (int) $balance->qty_on_hand;
            $newQty = $oldQty - $quantity;

            $balance->forceFill([
                'qty_on_hand' => $newQty,
                'version' => (int) $balance->version + 1,
            ])->save();

            $movement = AccessoryStockMovement::create([
                'business_unit_id' => $businessUnitId,
                'product_variant_id' => $variantId,
                'quantity_delta' => -$quantity,
                'reason' => $reason,
                'unit_cost' => $balance->average_cost,
                'balance_after' => $newQty,
                'source_type' => $source?->getMorphClass(),
                'source_id' => $source?->getKey(),
                'actor_id' => $actor?->getKey(),
                'note' => $note,
            ]);

            if (in_array($reason, [AccessoryMovementReason::AdjustmentOut, AccessoryMovementReason::WriteOff], true)) {
                $value = Money::of((string) $balance->average_cost)->mul($quantity);

                if ($value->isPositive()) {
                    $businessUnit = BusinessUnit::query()->findOrFail($businessUnitId);

                    $this->journal->post(
                        lines: [
                            ['account_id' => $this->accounts->inventoryAdjustment()->getKey(), 'debit' => $value->amount, 'memo' => 'Accessory stock out'],
                            ['account_id' => $this->accounts->accessoryInventory()->getKey(), 'credit' => $value->amount, 'memo' => 'Accessory inventory removed'],
                        ],
                        memo: 'Accessory stock out '.($movement->id),
                        entryDate: BusinessClock::today(),
                        sourceKey: 'accessory-movement:'.$movement->id,
                        source: $businessUnit,
                        actor: $actor,
                    );
                }
            }

            AuditLogger::record(
                'inventory.accessory_issued',
                $balance,
                ['qty_on_hand' => $oldQty],
                ['qty_on_hand' => $newQty, 'reason' => $reason->value],
                $note,
                $businessUnitId,
                $actor,
            );

            return $balance->refresh();
        });
    }

    public function reserve(int $businessUnitId, int $variantId, int $quantity): AccessoryStockBalance
    {
        return DB::transaction(function () use ($businessUnitId, $variantId, $quantity): AccessoryStockBalance {
            $balance = $this->lockBalance($businessUnitId, $variantId);
            $available = (int) $balance->qty_on_hand - (int) $balance->qty_reserved;

            if ($quantity > $available) {
                $variant = ProductVariant::query()->with('product')->find($variantId);

                throw InsufficientStockException::accessory(
                    $variant?->displayName() ?? "variant #{$variantId}",
                    $quantity,
                    $available,
                );
            }

            $balance->forceFill([
                'qty_reserved' => (int) $balance->qty_reserved + $quantity,
                'version' => (int) $balance->version + 1,
            ])->save();

            return $balance->refresh();
        });
    }

    public function releaseReservation(int $businessUnitId, int $variantId, int $quantity): AccessoryStockBalance
    {
        return DB::transaction(function () use ($businessUnitId, $variantId, $quantity): AccessoryStockBalance {
            $balance = $this->lockBalance($businessUnitId, $variantId);
            $reserved = max(0, (int) $balance->qty_reserved - $quantity);

            $balance->forceFill([
                'qty_reserved' => $reserved,
                'version' => (int) $balance->version + 1,
            ])->save();

            return $balance->refresh();
        });
    }

    public function adjust(
        int $businessUnitId,
        int $variantId,
        int $quantityDelta,
        string|Money|null $unitCost,
        ?string $reasonNote,
        ?User $actor = null,
    ): AccessoryStockBalance {
        if ($quantityDelta === 0) {
            throw DomainRuleViolationException::message(
                'Adjustment quantity cannot be zero.',
                ['quantity' => 'Enter a positive or negative quantity.'],
            );
        }

        if (trim((string) $reasonNote) === '') {
            throw DomainRuleViolationException::message(
                'An adjustment reason is required.',
                ['note' => 'Explain why stock is being adjusted.'],
            );
        }

        $reason = $quantityDelta > 0
            ? AccessoryMovementReason::AdjustmentIn
            : AccessoryMovementReason::AdjustmentOut;

        return $quantityDelta > 0
            ? $this->receive($businessUnitId, $variantId, $quantityDelta, $unitCost, $reason, $actor, null, $reasonNote)
            : $this->issue($businessUnitId, $variantId, abs($quantityDelta), $reason, $actor, null, $reasonNote);
    }

    public function balanceFor(int $businessUnitId, int $variantId): AccessoryStockBalance
    {
        return $this->lockBalance($businessUnitId, $variantId);
    }

    private function lockBalance(int $businessUnitId, int $variantId): AccessoryStockBalance
    {
        $balance = AccessoryStockBalance::query()
            ->where('business_unit_id', $businessUnitId)
            ->where('product_variant_id', $variantId)
            ->lockForUpdate()
            ->first();

        if ($balance !== null) {
            return $balance;
        }

        AccessoryStockBalance::create([
            'business_unit_id' => $businessUnitId,
            'product_variant_id' => $variantId,
            'qty_on_hand' => 0,
            'qty_reserved' => 0,
            'average_cost' => '0.0000',
            'version' => 0,
        ]);

        return AccessoryStockBalance::query()
            ->where('business_unit_id', $businessUnitId)
            ->where('product_variant_id', $variantId)
            ->lockForUpdate()
            ->firstOrFail();
    }
}
