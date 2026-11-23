<?php

declare(strict_types=1);

namespace App\Domain\Purchases;

use App\Domain\Accounting\ChartOfAccounts;
use App\Domain\Accounting\JournalPostingService;
use App\Domain\Inventory\AccessoryStockService;
use App\Domain\Inventory\ImeiNormalizer;
use App\Domain\Inventory\InventoryIntakeService;
use App\Domain\Inventory\SerialIdentityService;
use App\Domain\Inventory\UnitAccessoryService;
use App\Enums\AccessoryMovementReason;
use App\Enums\AcquisitionSource;
use App\Enums\InventoryEventType;
use App\Enums\LineType;
use App\Enums\PaymentMethod;
use App\Enums\ProductType;
use App\Enums\PurchaseStatus;
use App\Exceptions\DomainRuleViolationException;
use App\Exceptions\DuplicateImeiException;
use App\Exceptions\UnitAccessDeniedException;
use App\Models\BusinessUnit;
use App\Models\ProductVariant;
use App\Models\Purchase;
use App\Models\PurchaseLine;
use App\Models\SerializedIdentifier;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\BusinessClock;
use App\Support\DocumentNumberService;
use App\Support\Money;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Purchase entry and receipt.
 *
 * Draft purchases capture serial numbers without touching stock. Receiving is
 * atomic: every handset identity, cycle, activation event and accessory
 * movement either all persist or none do.
 */
final class PurchaseService
{
    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly SerialIdentityService $identities,
        private readonly InventoryIntakeService $intake,
        private readonly AccessoryStockService $accessoryStock,
        private readonly ImeiNormalizer $imeiNormalizer,
        private readonly UnitAccessoryService $unitAccessories,
        private readonly ChartOfAccounts $accounts,
        private readonly JournalPostingService $journal,
    ) {}

    /**
     * @param  array{supplier_id?: ?int, business_unit_id: int, purchase_date: string, discount_total?: string, other_charges?: string, paid_amount?: string, payment_method?: ?string, reference?: ?string, notes?: ?string, idempotency_key?: ?string}  $header
     * @param  list<array{product_variant_id: int, line_type: string, quantity?: int, unit_cost: string, imei_1?: ?string, imei_2?: ?string, serial_payload?: ?array, notes?: ?string}>  $lines
     */
    public function create(array $header, array $lines, User $actor): Purchase
    {
        $unit = $this->assertUnit($header['business_unit_id'], $actor);
        $preparedLines = $this->prepareLines($lines);

        if ($preparedLines === []) {
            throw DomainRuleViolationException::message(
                'A purchase must contain at least one line.',
                ['lines' => 'Add at least one phone or accessory.'],
            );
        }

        $discount = Money::of($header['discount_total'] ?? '0');
        $otherCharges = Money::of($header['other_charges'] ?? '0');
        $paid = Money::of($header['paid_amount'] ?? '0');

        if ($discount->isNegative() || $otherCharges->isNegative()) {
            throw DomainRuleViolationException::message(
                'Discount and other charges cannot be negative.',
                ['discount_total' => 'Enter zero or a positive amount.'],
            );
        }

        $subtotal = array_reduce(
            $preparedLines,
            fn (Money $carry, array $line) => $carry->add($line['line_total']),
            Money::zero(),
        );

        $total = $subtotal->sub($discount)->add($otherCharges);

        if ($total->isNegative()) {
            throw DomainRuleViolationException::message(
                'Purchase total cannot be negative.',
                ['discount_total' => 'Discount exceeds the purchase subtotal.'],
            );
        }

        if ($paid->isNegative() || $paid->greaterThan($total)) {
            throw DomainRuleViolationException::message(
                'Paid amount must be between zero and the purchase total.',
                ['paid_amount' => 'Payment cannot exceed the purchase total.'],
            );
        }

        $idempotencyKey = $header['idempotency_key'] ?? null;

        if ($idempotencyKey !== null) {
            $existing = Purchase::query()->where('idempotency_key', $idempotencyKey)->first();

            if ($existing !== null) {
                return $existing->load('lines');
            }
        }

        try {
            return DB::transaction(function () use ($unit, $header, $preparedLines, $discount, $otherCharges, $paid, $subtotal, $total, $actor, $idempotencyKey): Purchase {
                $purchase = Purchase::create([
                    'document_no' => $this->numbers->next('purchase', null, BusinessClock::now()),
                    'supplier_id' => $header['supplier_id'] ?? null,
                    'buying_party_name' => filled($header['buying_party_name'] ?? null)
                        ? mb_substr(trim((string) $header['buying_party_name']), 0, 180)
                        : null,
                    'business_unit_id' => $unit->getKey(),
                    'purchase_date' => $header['purchase_date'],
                    'status' => PurchaseStatus::Draft,
                    'subtotal' => $subtotal->amount,
                    'discount_total' => $discount->amount,
                    'other_charges' => $otherCharges->amount,
                    'total' => $total->amount,
                    'paid_amount' => $paid->amount,
                    'due_amount' => $total->sub($paid)->amount,
                    'payment_method' => $header['payment_method'] ?? null,
                    'reference' => $header['reference'] ?? null,
                    'notes' => $header['notes'] ?? null,
                    'created_by' => $actor->getKey(),
                    'idempotency_key' => $idempotencyKey,
                ]);

                foreach ($preparedLines as $line) {
                    $purchase->lines()->create($line);
                }

                AuditLogger::record(
                    'purchase.created',
                    $purchase,
                    [],
                    $purchase->only(['document_no', 'business_unit_id', 'total', 'status']),
                    null,
                    $unit->getKey(),
                    $actor,
                );

                return $purchase->load('lines');
            });
        } catch (UniqueConstraintViolationException) {
            if ($idempotencyKey !== null) {
                $existing = Purchase::query()->where('idempotency_key', $idempotencyKey)->first();

                if ($existing !== null) {
                    return $existing->load('lines');
                }
            }

            throw DomainRuleViolationException::message(
                'A purchase with this reference already exists.',
                ['reference' => 'Duplicate purchase.'],
            );
        }
    }

    /**
     * @param  array{supplier_id?: ?int, business_unit_id: int, purchase_date: string, discount_total?: string, other_charges?: string, paid_amount?: string, payment_method?: ?string, reference?: ?string, notes?: ?string}  $header
     * @param  list<array{product_variant_id: int, line_type: string, quantity?: int, unit_cost: string, imei_1?: ?string, imei_2?: ?string, serial_payload?: ?array, notes?: ?string}>  $lines
     */
    public function updateDraft(Purchase $purchase, array $header, array $lines, User $actor): Purchase
    {
        return DB::transaction(function () use ($purchase, $header, $lines, $actor): Purchase {
            /** @var Purchase $locked */
            $locked = Purchase::query()->whereKey($purchase->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->isDraft()) {
                throw DomainRuleViolationException::message(
                    'Only draft purchases can be edited. Received purchases are immutable.',
                    ['purchase' => 'This purchase has already been received.'],
                );
            }

            $preparedLines = $this->prepareLines($lines);
            $discount = Money::of($header['discount_total'] ?? '0');
            $otherCharges = Money::of($header['other_charges'] ?? '0');
            $paid = Money::of($header['paid_amount'] ?? '0');

            $subtotal = array_reduce(
                $preparedLines,
                fn (Money $carry, array $line) => $carry->add($line['line_total']),
                Money::zero(),
            );

            $total = $subtotal->sub($discount)->add($otherCharges);

            if ($total->isNegative() || $paid->isNegative() || $paid->greaterThan($total)) {
                throw DomainRuleViolationException::message(
                    'Purchase totals are inconsistent.',
                    ['paid_amount' => 'Check discount, charges and paid amount.'],
                );
            }

            $old = $locked->only(['subtotal', 'total', 'paid_amount', 'due_amount']);
            $oldLines = $locked->lines()->count();

            $locked->lines()->delete();

            foreach ($preparedLines as $line) {
                $locked->lines()->create($line);
            }

            $locked->forceFill([
                'supplier_id' => $header['supplier_id'] ?? $locked->supplier_id,
                'buying_party_name' => filled($header['buying_party_name'] ?? null)
                    ? mb_substr(trim((string) $header['buying_party_name']), 0, 180)
                    : null,
                'purchase_date' => $header['purchase_date'] ?? $locked->purchase_date,
                'subtotal' => $subtotal->amount,
                'discount_total' => $discount->amount,
                'other_charges' => $otherCharges->amount,
                'total' => $total->amount,
                'paid_amount' => $paid->amount,
                'due_amount' => $total->sub($paid)->amount,
                'payment_method' => $header['payment_method'] ?? null,
                'reference' => $header['reference'] ?? null,
                'notes' => $header['notes'] ?? null,
            ])->save();

            AuditLogger::record(
                'purchase.updated',
                $locked,
                $old,
                $locked->only(['subtotal', 'total', 'paid_amount', 'due_amount']),
                "Lines replaced ({$oldLines} -> ".count($preparedLines).')',
                $locked->business_unit_id,
                $actor,
            );

            return $locked->refresh()->load('lines');
        });
    }

    /**
     * Receive a draft purchase into stock.
     *
     * This does not post to the ledger; the accounting module (phase 2) is the
     * single owner of journals. Purchase payable figures are recorded here and
     * will be posted there, never double counted as operating expense.
     */
    public function receive(Purchase $purchase, User $actor, CarbonInterface|string|null $receivedAt = null): Purchase
    {
        return DB::transaction(function () use ($purchase, $actor, $receivedAt): Purchase {
            /** @var Purchase $locked */
            $locked = Purchase::query()->whereKey($purchase->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status === PurchaseStatus::Received) {
                throw DomainRuleViolationException::message(
                    'This purchase has already been received.',
                    ['purchase' => 'Stock has already been booked for this document.'],
                );
            }

            if ($locked->status !== PurchaseStatus::Draft) {
                throw DomainRuleViolationException::message(
                    'Only draft purchases can be received.',
                    ['purchase' => 'This purchase is not in draft status.'],
                );
            }

            $businessUnit = BusinessUnit::query()->findOrFail($locked->business_unit_id);
            $lines = $locked->lines()->with('variant.product')->orderBy('id')->get();

            if ($lines->isEmpty()) {
                throw DomainRuleViolationException::message(
                    'Cannot receive a purchase with no lines.',
                    ['lines' => 'Add at least one line before receiving.'],
                );
            }

            foreach ($lines as $line) {
                if ($line->line_type === LineType::Phone) {
                    $this->receivePhoneLine($locked, $line, $businessUnit, $actor);
                } else {
                    $this->accessoryStock->receive(
                        businessUnitId: $businessUnit->getKey(),
                        variantId: (int) $line->product_variant_id,
                        quantity: (int) $line->quantity,
                        unitCost: $line->unit_cost,
                        reason: AccessoryMovementReason::PurchaseReceipt,
                        actor: $actor,
                        source: $line,
                        note: "Purchase {$locked->document_no}",
                    );
                }
            }

            $locked->forceFill([
                'status' => PurchaseStatus::Received,
                'received_at' => BusinessClock::toUtc($receivedAt),
                'received_by' => $actor->getKey(),
            ])->save();

            $this->postPurchaseJournal($locked, $lines, $actor, $businessUnit);

            AuditLogger::record(
                'purchase.received',
                $locked,
                ['status' => PurchaseStatus::Draft->value],
                ['status' => PurchaseStatus::Received->value, 'lines' => $lines->count()],
                null,
                $businessUnit->getKey(),
                $actor,
            );

            return $locked->refresh()->load('lines');
        });
    }

    /**
     * Capitalise inventory and settle cash/payable. Purchase discounts and
     * other charges are posted to their own accounts so the entry stays
     * balanced without pretending the inventory cost is something else.
     */
    private function postPurchaseJournal(Purchase $purchase, Collection $lines, User $actor, BusinessUnit $businessUnit): void
    {
        $phoneInventory = Money::zero();
        $accessoryInventory = Money::zero();

        foreach ($lines as $line) {
            if ($line->line_type === LineType::Phone) {
                $phoneInventory = $phoneInventory->add((string) $line->line_total);
            } else {
                $accessoryInventory = $accessoryInventory->add((string) $line->line_total);
            }
        }

        $total = Money::of((string) $purchase->total);
        $paid = Money::of((string) $purchase->paid_amount);
        $due = Money::of((string) $purchase->due_amount);
        $discount = Money::of((string) $purchase->discount_total);
        $charges = Money::of((string) $purchase->other_charges);

        if ($total->isZero() && $phoneInventory->isZero() && $accessoryInventory->isZero()) {
            return;
        }

        $lines = [];

        if ($phoneInventory->isPositive()) {
            $lines[] = ['account_id' => $this->accounts->handsetInventory()->getKey(), 'debit' => $phoneInventory->amount, 'memo' => 'Handsets received '.$purchase->document_no];
        }

        if ($accessoryInventory->isPositive()) {
            $lines[] = ['account_id' => $this->accounts->accessoryInventory()->getKey(), 'debit' => $accessoryInventory->amount, 'memo' => 'Accessories received '.$purchase->document_no];
        }

        if ($charges->isPositive()) {
            $lines[] = ['account_id' => $this->accounts->purchaseCharges()->getKey(), 'debit' => $charges->amount, 'memo' => 'Purchase charges '.$purchase->document_no];
        }

        if ($discount->isPositive()) {
            $lines[] = ['account_id' => $this->accounts->purchaseDiscount()->getKey(), 'credit' => $discount->amount, 'memo' => 'Purchase discount '.$purchase->document_no];
        }

        if ($paid->isPositive()) {
            $method = PaymentMethod::tryFrom(strtoupper((string) $purchase->payment_method)) ?? PaymentMethod::Cash;
            $lines[] = [
                'account_id' => $this->accounts->paymentAccount($businessUnit, $method)->getKey(),
                'credit' => $paid->amount,
                'memo' => 'Purchase payment '.$purchase->document_no,
            ];
        }

        if ($due->isPositive()) {
            $lines[] = ['account_id' => $this->accounts->supplierPayable()->getKey(), 'credit' => $due->amount, 'memo' => 'Supplier payable '.$purchase->document_no];
        }

        $entry = $this->journal->post(
            lines: $lines,
            memo: 'Purchase '.$purchase->document_no,
            entryDate: $purchase->purchase_date,
            sourceKey: 'purchase:'.$purchase->getKey(),
            source: $purchase,
            actor: $actor,
        );

        $purchase->forceFill(['journal_entry_id' => $entry->getKey()])->save();
    }

    public function cancel(Purchase $purchase, User $actor, ?string $reason = null): Purchase
    {
        return DB::transaction(function () use ($purchase, $actor, $reason): Purchase {
            /** @var Purchase $locked */
            $locked = Purchase::query()->whereKey($purchase->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status !== PurchaseStatus::Draft) {
                throw DomainRuleViolationException::message(
                    'Only draft purchases can be cancelled.',
                    ['purchase' => 'Received purchases must be corrected with a return document.'],
                );
            }

            $locked->forceFill(['status' => PurchaseStatus::Cancelled])->save();

            AuditLogger::record(
                'purchase.cancelled',
                $locked,
                ['status' => PurchaseStatus::Draft->value],
                ['status' => PurchaseStatus::Cancelled->value],
                $reason,
                $locked->business_unit_id,
                $actor,
            );

            return $locked;
        });
    }

    private function receivePhoneLine(Purchase $purchase, PurchaseLine $line, BusinessUnit $businessUnit, User $actor): void
    {
        if ((int) $line->quantity !== 1) {
            throw DomainRuleViolationException::message(
                'Each serialized phone line must have a quantity of exactly one.',
                ['quantity' => "Line #{$line->id} has quantity {$line->quantity}."],
            );
        }

        $payload = $line->serial_payload ?? [];
        $imei1 = $this->imeiNormalizer->normalize($line->imei_1);
        $imei2 = $this->imeiNormalizer->normalize($line->imei_2);

        if ($imei1 === null && $imei2 === null && ! ($payload['generate_internal_serial'] ?? false)) {
            throw DomainRuleViolationException::message(
                "Line #{$line->id} has no IMEI. Enter an IMEI or explicitly generate an internal serial.",
                ['imei_1' => 'IMEI is required for a serialized phone purchase line.'],
            );
        }

        $this->assertIdentifiersAvailable($imei1, $imei2, $line);

        /** @var ProductVariant $variant */
        $variant = $line->variant;

        $serializedUnit = $this->identities->register(
            variant: $variant,
            identity: [
                'primary_imei' => $imei1,
                'secondary_imei' => $imei2,
                'allow_legacy' => (bool) ($payload['allow_legacy'] ?? false),
                'generate_internal_serial' => (bool) ($payload['generate_internal_serial'] ?? false),
                'flag_reason' => $payload['flag_reason'] ?? null,
            ],
            attributes: [
                'initial_unit_id' => $businessUnit->getKey(),
                'battery_health' => $payload['battery_health'] ?? null,
                'cycle_count' => $payload['cycle_count'] ?? null,
                'condition_grade' => $payload['condition_grade'] ?? null,
                'box_kit' => $payload['box_kit'] ?? null,
                'notes' => $line->notes,
                'expected_selling_price' => $line->expected_selling_price,
                'status_note' => $line->status_note,
            ],
            actor: $actor,
        );

        $this->intake->activate(
            unit: $serializedUnit,
            businessUnit: $businessUnit,
            source: AcquisitionSource::Purchase,
            acquisitionValue: (string) $line->unit_cost,
            allocatedDirectCost: '0',
            actor: $actor,
            eventType: InventoryEventType::PurchaseReceived,
            sourceModel: $line,
            sourceLabel: $purchase->document_no,
            note: "Received on purchase {$purchase->document_no}",
            acquiredAt: ($line->received_at ?? $purchase->purchase_date)->toDateString(),
            idempotencyKey: "purchase:{$purchase->getKey()}:line:{$line->getKey()}",
            buyingPartyName: $line->buying_party_name ?? $purchase->buying_party_name ?? $purchase->supplier?->name,
        );

        $bundle = array_filter([
            'adapter_name' => $payload['adapter_name'] ?? null,
            'adapter_price' => $payload['adapter_price'] ?? null,
            'cable_name' => $payload['cable_name'] ?? null,
            'cable_price' => $payload['cable_price'] ?? null,
        ], fn ($value) => $value !== null && $value !== '');

        if ($bundle !== []) {
            $this->unitAccessories->syncBundle($serializedUnit->refresh(), $bundle, $serializedUnit->activeCycle, $actor);
        }
    }

    /**
     * @param  list<array{product_variant_id: int, line_type: string, quantity?: int, unit_cost: string, imei_1?: ?string, imei_2?: ?string, buying_party_name?: ?string, expected_selling_price?: ?string, status_note?: ?string, serial_payload?: ?array, notes?: ?string}>  $lines
     * @return list<array<string, mixed>>
     */
    private function prepareLines(array $lines): array
    {
        $prepared = [];

        foreach ($lines as $index => $line) {
            $variant = ProductVariant::query()
                ->with('product')
                ->find($line['product_variant_id'] ?? null);

            if ($variant === null) {
                throw DomainRuleViolationException::message(
                    'A purchase line references an unknown product variant.',
                    ["lines.{$index}.product_variant_id" => 'Unknown variant.'],
                );
            }

            $lineType = LineType::tryFrom((string) ($line['line_type'] ?? ''));

            if ($lineType === null) {
                throw DomainRuleViolationException::message(
                    'A purchase line has an invalid line type.',
                    ["lines.{$index}.line_type" => 'Expected PHONE or ACCESSORY.'],
                );
            }

            $expectedType = $lineType === LineType::Phone ? ProductType::Phone : ProductType::Accessory;

            if ($variant->product->product_type !== $expectedType) {
                throw DomainRuleViolationException::message(
                    'Purchase line type does not match the catalog product type.',
                    ["lines.{$index}.line_type" => "Variant belongs to a {$variant->product->product_type->label()} product."],
                );
            }

            $quantity = (int) ($line['quantity'] ?? 1);
            $unitCost = Money::of($line['unit_cost'] ?? '0');

            if ($quantity < 1) {
                throw DomainRuleViolationException::message(
                    'Purchase line quantity must be at least one.',
                    ["lines.{$index}.quantity" => 'Quantity must be at least 1.'],
                );
            }

            if ($lineType === LineType::Phone && $quantity !== 1) {
                throw DomainRuleViolationException::message(
                    'Serialized phones must be entered one line per device.',
                    ["lines.{$index}.quantity" => 'Use one line per IMEI.'],
                );
            }

            if ($unitCost->isNegative()) {
                throw DomainRuleViolationException::message(
                    'Unit cost cannot be negative.',
                    ["lines.{$index}.unit_cost" => 'Enter zero or a positive amount.'],
                );
            }

            $expectedSellingPrice = $line['expected_selling_price'] ?? null;

            if ($lineType === LineType::Phone && $expectedSellingPrice !== null && $expectedSellingPrice !== '') {
                $expected = Money::of($expectedSellingPrice);

                if ($expected->isNegative()) {
                    throw DomainRuleViolationException::message(
                        'Expected selling price cannot be negative.',
                        ["lines.{$index}.expected_selling_price" => 'Enter zero or a positive amount.'],
                    );
                }

                $expectedSellingPrice = $expected->amount;
            } else {
                $expectedSellingPrice = null;
            }

            $prepared[] = [
                'product_id' => $variant->product_id,
                'product_variant_id' => $variant->getKey(),
                'line_type' => $lineType,
                'quantity' => $quantity,
                'unit_cost' => $unitCost->amount,
                'line_total' => $unitCost->mul($quantity)->amount,
                'imei_1' => $lineType === LineType::Phone ? ($line['imei_1'] ?? null) : null,
                'imei_2' => $lineType === LineType::Phone ? ($line['imei_2'] ?? null) : null,
                'buying_party_name' => $lineType === LineType::Phone && filled($line['buying_party_name'] ?? null)
                    ? mb_substr(trim((string) $line['buying_party_name']), 0, 180)
                    : null,
                'expected_selling_price' => $expectedSellingPrice,
                'status_note' => $lineType === LineType::Phone && filled($line['status_note'] ?? null)
                    ? mb_substr(trim((string) $line['status_note']), 0, 255)
                    : null,
                'received_at' => $lineType === LineType::Phone ? $this->normaliseDate($line['received_at'] ?? null, $index) : null,
                'serial_payload' => $lineType === LineType::Phone ? ($line['serial_payload'] ?? null) : null,
                'notes' => $line['notes'] ?? null,
            ];
        }

        return $prepared;
    }

    private function normaliseDate(?string $value, int $index): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            throw DomainRuleViolationException::message(
                'A purchase line has an invalid receiving date.',
                ["lines.{$index}.received_at" => 'Use a valid date.'],
            );
        }
    }

    private function assertIdentifiersAvailable(?string $imei1, ?string $imei2, PurchaseLine $line): void
    {
        foreach ([$imei1, $imei2] as $value) {
            if ($value === null) {
                continue;
            }

            if (SerializedIdentifier::query()->where('normalized_value', $value)->exists()) {
                throw DuplicateImeiException::forValue($value, 'imei_1');
            }
        }

        if ($imei1 !== null && $imei1 === $imei2) {
            throw DomainRuleViolationException::message(
                "Line #{$line->id} repeats the same IMEI in both slots.",
                ['imei_2' => 'IMEI 2 must differ from IMEI 1.'],
            );
        }
    }

    private function assertUnit(int $unitId, User $actor): BusinessUnit
    {
        if (! $actor->hasUnitAccess($unitId)) {
            throw UnitAccessDeniedException::forUnit($unitId);
        }

        $unit = BusinessUnit::query()->find($unitId);

        if ($unit === null || ! $unit->is_active) {
            throw DomainRuleViolationException::message(
                'The selected business unit is not available.',
                ['business_unit_id' => 'Inactive or unknown unit.'],
            );
        }

        return $unit;
    }
}
