<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

use App\Enums\AccessoryMovementReason;
use App\Enums\ProductType;
use App\Exceptions\DomainRuleViolationException;
use App\Models\AccessoryStockBalance;
use App\Models\BusinessUnit;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Supplier;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Quantity-tracked product (accessory) intake from the unified stock-entry
 * screen. The SKU is the identity: an existing accessory SKU receives stock,
 * a new one creates the product + variant first.
 *
 * This is a manual/opening stock entry and posts to opening equity. Purchases
 * from a supplier must go through Purchases so payable/cash is recorded.
 */
final class ProductIntakeService
{
    public function __construct(
        private readonly AccessoryStockService $stock,
    ) {}

    /**
     * @param  array{name: string, sku: string, buying_price: string|int|float, selling_price?: string|int|float|null, quantity: int}  $data
     * @return array{variant: ProductVariant, balance: AccessoryStockBalance, created: bool}
     */
    public function add(array $data, BusinessUnit $unit, User $actor): array
    {
        $sku = trim((string) $data['sku']);
        $name = trim((string) $data['name']);
        $quantity = (int) $data['quantity'];
        $buying = Money::of($data['buying_price'] ?? '0');
        $selling = filled($data['selling_price'] ?? null) ? Money::of($data['selling_price']) : null;

        if ($sku === '' || $name === '') {
            throw DomainRuleViolationException::message(
                'Product name and SKU are required.',
                ['product_name' => 'Both name and SKU are required.'],
            );
        }

        if ($quantity < 1) {
            throw DomainRuleViolationException::message(
                'Stock quantity must be at least one.',
                ['stock_quantity' => 'Enter at least 1.'],
            );
        }

        if ($buying->isNegative() || ($selling?->isNegative() ?? false)) {
            throw DomainRuleViolationException::message(
                'Buying and selling prices cannot be negative.',
                ['buying_price' => 'Enter zero or a positive amount.'],
            );
        }

        try {
            return DB::transaction(function () use ($unit, $actor, $sku, $name, $quantity, $buying, $selling, $data): array {
                /** @var ProductVariant|null $variant */
                $variant = ProductVariant::query()
                    ->with('product')
                    ->where('sku', $sku)
                    ->lockForUpdate()
                    ->first();

                $created = false;

                if ($variant !== null) {
                    if ($variant->product?->product_type !== ProductType::Accessory) {
                        throw DomainRuleViolationException::message(
                            'That SKU belongs to a phone product. Product entry only accepts accessory SKUs.',
                            ['product_sku' => 'This SKU is used by a phone.'],
                        );
                    }

                    $variant->forceFill([
                        'default_purchase_price' => $buying->amount,
                        'default_selling_price' => $selling?->amount ?? $variant->default_selling_price,
                        'is_active' => true,
                    ])->save();
                } else {
                    $product = Product::create([
                        'product_type' => ProductType::Accessory,
                        'name' => $name,
                        'model' => null,
                        'sku' => null,
                        'is_active' => true,
                    ]);

                    $variant = ProductVariant::create([
                        'product_id' => $product->getKey(),
                        'sku' => $sku,
                        'default_purchase_price' => $buying->amount,
                        'default_selling_price' => $selling?->amount,
                        'reorder_point' => 0,
                        'is_active' => true,
                    ]);

                    $created = true;

                    AuditLogger::record(
                        'catalog.product_created',
                        $product,
                        [],
                        $product->only(['name', 'product_type', 'is_active']),
                        'Created from stock entry',
                        $unit->getKey(),
                        $actor,
                    );
                }

                $supplier = null;
                $supplierName = trim((string) ($data['supplier_name'] ?? ''));
                if ($supplierName !== '') {
                    $supplier = Supplier::whereRaw('LOWER(name) = ?', [mb_strtolower($supplierName)])->first();
                    if (! $supplier) {
                        $supplier = Supplier::create(['name' => $supplierName, 'type' => Supplier::TYPE_BUSINESS, 'is_active' => true]);
                        AuditLogger::record('supplier.created', $supplier, [], $supplier->toArray(), 'Accessory receipt', $unit->id, $actor);
                    }
                    if (! $supplier->is_active) {
                        throw DomainRuleViolationException::message('Supplier is inactive.', ['supplier_name' => 'Select an active supplier.']);
                    }
                }
                $balance = $this->stock->receive(
                    businessUnitId: $unit->getKey(),
                    variantId: $variant->getKey(),
                    quantity: $quantity,
                    unitCost: $buying,
                    reason: AccessoryMovementReason::Opening,
                    actor: $actor,
                    note: 'Manual product stock entry',
                    supplierId: $supplier?->id,
                );

                AuditLogger::record(
                    'inventory.product_stock_added',
                    $variant,
                    [],
                    ['sku' => $sku, 'quantity' => $quantity, 'buying_price' => $buying->amount, 'unit_id' => $unit->getKey()],
                    null,
                    $unit->getKey(),
                    $actor,
                );

                return [
                    'variant' => $variant->refresh()->load('product'),
                    'balance' => $balance,
                    'created' => $created,
                    'quantity' => $quantity,
                ];
            });
        } catch (UniqueConstraintViolationException) {
            throw DomainRuleViolationException::message(
                'That SKU was just created by another entry. Re-enter it to add stock to the existing product.',
                ['product_sku' => 'Duplicate SKU.'],
            );
        }
    }
}
