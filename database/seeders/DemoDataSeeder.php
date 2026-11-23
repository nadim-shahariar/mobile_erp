<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Inventory\AccessoryStockService;
use App\Domain\Inventory\InventoryIntakeService;
use App\Domain\Inventory\SerialIdentityService;
use App\Domain\Inventory\UnitAccessoryService;
use App\Domain\Purchases\PurchaseService;
use App\Domain\Sales\SaleService;
use App\Enums\AccessoryMovementReason;
use App\Enums\AcquisitionSource;
use App\Enums\InventoryEventType;
use App\Models\Brand;
use App\Models\BusinessUnit;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\SerializedUnit;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Demo catalogue and sample stock. Every record is demo data and must never be
 * presented as the shop's real Google Sheet history.
 */
class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $owner = User::query()->whereHas('roles', fn ($query) => $query->where('name', 'owner'))->first();

        if ($owner === null) {
            $this->command?->error('Run AdminUserSeeder before DemoDataSeeder.');

            return;
        }

        $categories = [
            'Smartphone' => 'PHONE',
            'Charger' => 'ACCESSORY',
            'Cable' => 'ACCESSORY',
            'Case & Cover' => 'ACCESSORY',
            'Screen Protector' => 'ACCESSORY',
            'Earphones' => 'ACCESSORY',
        ];

        $categoryModels = [];

        foreach ($categories as $name => $type) {
            $categoryModels[$name] = Category::query()->firstOrCreate(
                ['slug' => Str::slug($name)],
                ['name' => $name, 'product_type' => $type],
            );
        }

        $brands = [];

        foreach (['Apple', 'Samsung', 'Xiaomi', 'Realme', 'OnePlus'] as $brandName) {
            $brands[$brandName] = Brand::query()->firstOrCreate(
                ['slug' => Str::slug($brandName)],
                ['name' => $brandName],
            );
        }

        $phones = [
            ['Apple', 'iPhone 13', '128GB', '6GB', 'Midnight', 52000, 62000],
            ['Apple', 'iPhone 14 Pro', '256GB', '6GB', 'Deep Purple', 88000, 99000],
            ['Samsung', 'Galaxy S23', '256GB', '8GB', 'Phantom Black', 64000, 74000],
            ['Xiaomi', 'Redmi Note 13', '128GB', '8GB', 'Ocean Sunset', 18500, 22500],
            ['Realme', 'Realme 11 Pro', '256GB', '8GB', 'Astral Black', 21000, 25500],
            ['OnePlus', 'OnePlus Nord CE 3', '128GB', '8GB', 'Aqua Surge', 24000, 28500],
        ];

        $accessories = [
            ['Apple', '20W USB-C Power Adapter', 'Charger', 1450, 2200, 10],
            ['Samsung', '25W Travel Adapter', 'Charger', 1100, 1750, 10],
            ['Baseus', 'USB-C Braided Cable 1m', 'Cable', 180, 350, 25],
            ['Xiaomi', 'Redmi Note 13 Back Cover', 'Case & Cover', 90, 250, 20],
            ['Generic', '9H Tempered Glass', 'Screen Protector', 35, 150, 50],
            ['Realme', 'Buds T110', 'Earphones', 1900, 2900, 8],
        ];

        $phoneVariants = [];

        foreach ($phones as [$brandName, $model, $storage, $ram, $color, $buy, $sell]) {
            $product = Product::query()->firstOrCreate(
                ['name' => $model, 'brand_id' => $brands[$brandName]->id],
                [
                    'product_type' => 'PHONE',
                    'category_id' => $categoryModels['Smartphone']->id,
                    'model' => $model,
                    'sku' => 'PH-'.Str::upper(Str::slug($model, '')),
                    'is_active' => true,
                ],
            );

            $phoneVariants[] = ProductVariant::query()->firstOrCreate(
                ['product_id' => $product->id, 'storage' => $storage, 'ram' => $ram, 'color' => $color],
                [
                    'sku' => 'PH-'.Str::upper(Str::slug($model.'-'.$storage.'-'.$color, '')),
                    'default_purchase_price' => $buy,
                    'default_selling_price' => $sell,
                    'is_active' => true,
                ],
            );
        }

        foreach ($accessories as [$brandName, $name, $categoryName, $buy, $sell, $reorder]) {
            $brand = $brands[$brandName] ?? Brand::query()->firstOrCreate(
                ['slug' => Str::slug($brandName)],
                ['name' => $brandName],
            );

            $product = Product::query()->firstOrCreate(
                ['name' => $name, 'brand_id' => $brand->id],
                [
                    'product_type' => 'ACCESSORY',
                    'category_id' => $categoryModels[$categoryName]->id,
                    'model' => $name,
                    'sku' => 'AC-'.Str::upper(Str::slug($name, '')),
                    'is_active' => true,
                ],
            );

            ProductVariant::query()->firstOrCreate(
                ['product_id' => $product->id, 'sku' => 'AC-'.Str::upper(Str::slug($name, ''))],
                [
                    'default_purchase_price' => $buy,
                    'default_selling_price' => $sell,
                    'reorder_point' => $reorder,
                    'is_active' => true,
                ],
            );
        }

        $supplier = Supplier::query()->firstOrCreate(
            ['name' => 'Demo Wholesale Supplier'],
            [
                'type' => Supplier::TYPE_BUSINESS,
                'phone' => '01700000000',
                'address' => 'Demo address, Dhaka',
                'is_active' => true,
            ],
        );

        $units = BusinessUnit::query()->ordered()->get();
        $serialService = app(SerialIdentityService::class);
        $intake = app(InventoryIntakeService::class);

        foreach ($units as $unitIndex => $unit) {
            for ($i = 0; $i < 3; $i++) {
                $variant = $phoneVariants[($unitIndex * 3 + $i) % count($phoneVariants)];
                $imei = $this->demoImei($unitIndex * 3 + $i);

                if ($serialService->findByImei($imei) !== null) {
                    continue;
                }

                $serial = $serialService->register(
                    variant: $variant,
                    identity: ['primary_imei' => $imei],
                    attributes: [
                        'initial_unit_id' => $unit->id,
                        'battery_health' => 100 - $i,
                        'cycle_count' => 0,
                        'condition_grade' => 'A',
                        'box_kit' => 'Box + charger',
                        'expected_selling_price' => $variant->default_selling_price,
                    ],
                    actor: $owner,
                );

                $intake->activate(
                    unit: $serial,
                    businessUnit: $unit,
                    source: AcquisitionSource::OpeningBalance,
                    acquisitionValue: (string) $variant->default_purchase_price,
                    allocatedDirectCost: '0',
                    actor: $owner,
                    eventType: InventoryEventType::OpeningBalance,
                    sourceLabel: 'DEMO opening balance',
                    note: 'Demo data - not real shop history',
                    buyingPartyName: 'DEMO opening balance',
                );

                app(UnitAccessoryService::class)->syncBundle(
                    $serial->refresh(),
                    [
                        'adapter_name' => 'Demo USB-C adapter',
                        'adapter_price' => '1200',
                        'cable_name' => 'Demo USB-C cable',
                        'cable_price' => '300',
                    ],
                    $serial->activeCycle,
                    $owner,
                );
            }
        }

        $stock = app(AccessoryStockService::class);

        foreach ($units as $unit) {
            foreach (ProductVariant::query()->whereHas('product', fn ($q) => $q->where('product_type', 'ACCESSORY'))->get() as $variant) {
                $stock->receive(
                    businessUnitId: $unit->id,
                    variantId: $variant->id,
                    quantity: 5,
                    unitCost: $variant->default_purchase_price,
                    reason: AccessoryMovementReason::Opening,
                    actor: $owner,
                    note: 'DEMO opening stock',
                );
            }
        }

        $purchase = app(PurchaseService::class)->create(
            [
                'supplier_id' => $supplier->id,
                'business_unit_id' => $units->first()->id,
                'purchase_date' => now()->toDateString(),
                'discount_total' => '0',
                'other_charges' => '0',
                'paid_amount' => '0',
                'reference' => 'DEMO-PURCHASE',
                'notes' => 'Demo purchase - not real business data',
            ],
            [
                [
                    'product_variant_id' => $phoneVariants[0]->id,
                    'line_type' => 'PHONE',
                    'quantity' => 1,
                    'unit_cost' => (string) $phoneVariants[0]->default_purchase_price,
                    'imei_1' => $this->demoImei(20),
                ],
                [
                    'product_variant_id' => ProductVariant::query()
                        ->whereHas('product', fn ($q) => $q->where('name', '20W USB-C Power Adapter'))
                        ->firstOrFail()->id,
                    'line_type' => 'ACCESSORY',
                    'quantity' => 4,
                    'unit_cost' => '1450',
                ],
            ],
            $owner,
        );

        app(PurchaseService::class)->receive($purchase, $owner);

        $demoCustomer = Customer::query()->firstOrCreate(
            ['name' => 'Demo Wholesale Shop'],
            [
                'type' => 'SHOP',
                'business_name' => 'Demo Wholesale Shop',
                'phone' => '01800000000',
                'address' => 'Demo market, Dhaka',
                'credit_limit' => '200000.00',
                'is_active' => true,
                'notes' => 'DEMO customer - not real business data',
            ],
        );

        if (! Sale::query()->where('notes', 'DEMO sale - not real business data')->exists()) {
            $unitOne = BusinessUnit::query()->where('code', 'U1')->first();
            $phoneToSell = $unitOne
                ? SerializedUnit::query()
                    ->where('current_unit_id', $unitOne->id)
                    ->where('current_custody', 'IN_UNIT')
                    ->where('sellability_status', 'AVAILABLE')
                    ->where('expected_selling_price', '>=', '30000')
                    ->first()
                : null;

            $demoAccessory = ProductVariant::query()
                ->whereHas('product', fn ($query) => $query->where('name', '20W USB-C Power Adapter'))
                ->first();

            if ($unitOne && $phoneToSell && $demoAccessory) {
                $items = [[
                    'line_type' => 'PHONE',
                    'serialized_unit_id' => $phoneToSell->id,
                    'unit_price' => (string) $phoneToSell->expected_selling_price,
                ]];

                $items[] = [
                    'line_type' => 'ACCESSORY',
                    'product_variant_id' => $demoAccessory->id,
                    'quantity' => 1,
                    'unit_price' => (string) $demoAccessory->default_selling_price,
                ];

                app(SaleService::class)->post([
                    'business_unit_id' => $unitOne->id,
                    'customer_id' => $demoCustomer->id,
                    'items' => $items,
                    'payments' => [
                        ['method' => 'CASH', 'amount' => '30000.00'],
                    ],
                    'notes' => 'DEMO sale - not real business data',
                    'idempotency_key' => 'demo-sale-1',
                ], $owner);
            }
        }

        $this->command?->info('Demo data seeded (clearly labelled DEMO).');
    }

    private function demoImei(int $sequence): string
    {
        $base = '35678901'.str_pad((string) (100000 + $sequence), 6, '0', STR_PAD_LEFT);

        return $base.$this->luhnCheckDigit($base);
    }

    private function luhnCheckDigit(string $digits): string
    {
        $sum = 0;
        $double = true;

        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            $digit = (int) $digits[$i];

            if ($double) {
                $digit *= 2;

                if ($digit > 9) {
                    $digit -= 9;
                }
            }

            $sum += $digit;
            $double = ! $double;
        }

        return (string) ((10 - ($sum % 10)) % 10);
    }
}
