<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Domain\Inventory\InventoryIntakeService;
use App\Domain\Inventory\SerialIdentityService;
use App\Domain\Purchases\PurchaseService;
use App\Enums\AcquisitionSource;
use App\Enums\InventoryEventType;
use App\Enums\ProductType;
use App\Models\Brand;
use App\Models\BusinessUnit;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Purchase;
use App\Models\SerializedUnit;
use App\Models\User;
use App\Support\UnitContext;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

trait BuildsErpScenario
{
    protected BusinessUnit $unitOne;

    protected BusinessUnit $unitTwo;

    protected function bootErp(): void
    {
        $this->seed(PermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->unitOne = BusinessUnit::create([
            'code' => 'U1',
            'name' => 'Unit 1',
            'timezone' => config('erp.timezone'),
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $this->unitTwo = BusinessUnit::create([
            'code' => 'U2',
            'name' => 'Unit 2',
            'timezone' => config('erp.timezone'),
            'is_active' => true,
            'sort_order' => 2,
        ]);
    }

    /** @param list<int> $unitIds */
    protected function staff(string $role = 'owner', array $unitIds = []): User
    {
        $unitIds = $unitIds === [] ? [$this->unitOne->id] : $unitIds;

        $user = User::create([
            'name' => ucfirst($role).' '.Str::random(4),
            'email' => $role.'-'.Str::random(8).'@example.test',
            'password' => 'Secret!12345',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        $user->assignRole($role);
        $user->businessUnits()->attach($unitIds);
        app(UnitContext::class)->set($user, BusinessUnit::query()->find($unitIds[0]));

        return $user->refresh();
    }

    protected function phoneVariant(string $name = 'iPhone 13', string $storage = '128GB', string $color = 'Midnight'): ProductVariant
    {
        $brand = Brand::query()->firstOrCreate(
            ['slug' => Str::slug('Apple')],
            ['name' => 'Apple'],
        );

        $product = Product::query()->firstOrCreate(
            ['name' => $name, 'brand_id' => $brand->id],
            [
                'product_type' => ProductType::Phone,
                'model' => $name,
                'sku' => 'PH-'.Str::upper(Str::slug($name)),
                'is_active' => true,
            ],
        );

        return ProductVariant::query()->firstOrCreate(
            ['product_id' => $product->id, 'storage' => $storage, 'color' => $color],
            [
                'sku' => 'PH-'.Str::upper(Str::slug($name.'-'.$storage.'-'.$color)),
                'ram' => '6GB',
                'default_purchase_price' => '50000.00',
                'default_selling_price' => '60000.00',
                'is_active' => true,
            ],
        );
    }

    protected function accessoryVariant(string $name = '20W Charger', string $purchase = '1450.00', string $selling = '2200.00'): ProductVariant
    {
        $brand = Brand::query()->firstOrCreate(
            ['slug' => Str::slug('Generic')],
            ['name' => 'Generic'],
        );

        $product = Product::query()->firstOrCreate(
            ['name' => $name, 'brand_id' => $brand->id],
            [
                'product_type' => ProductType::Accessory,
                'model' => $name,
                'sku' => 'AC-'.Str::upper(Str::slug($name)),
                'is_active' => true,
            ],
        );

        return ProductVariant::query()->firstOrCreate(
            ['product_id' => $product->id, 'sku' => 'AC-'.Str::upper(Str::slug($name))],
            [
                'default_purchase_price' => $purchase,
                'default_selling_price' => $selling,
                'reorder_point' => 5,
                'is_active' => true,
            ],
        );
    }

    protected function addPhoneToStock(User $actor, BusinessUnit $unit, ProductVariant $variant, string $imei, string $cost = '50000.00'): SerializedUnit
    {
        $serial = app(SerialIdentityService::class)->register(
            variant: $variant,
            identity: ['primary_imei' => $imei],
            attributes: [
                'initial_unit_id' => $unit->id,
                'battery_health' => 97,
                'cycle_count' => 12,
                'condition_grade' => 'A',
                'box_kit' => 'Box + cable',
            ],
            actor: $actor,
        );

        app(InventoryIntakeService::class)->activate(
            unit: $serial,
            businessUnit: $unit,
            source: AcquisitionSource::OpeningBalance,
            acquisitionValue: $cost,
            allocatedDirectCost: '0',
            actor: $actor,
            eventType: InventoryEventType::OpeningBalance,
            sourceLabel: 'Test opening balance',
        );

        return $serial->refresh();
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @param  array<string, mixed>  $header
     */
    protected function createPurchase(User $actor, BusinessUnit $unit, array $lines, array $header = []): Purchase
    {
        return app(PurchaseService::class)->create(array_merge([
            'business_unit_id' => $unit->id,
            'purchase_date' => now()->toDateString(),
            'paid_amount' => '0',
            'discount_total' => '0',
            'other_charges' => '0',
            'reference' => 'TEST-'.Str::random(6),
        ], $header), $lines, $actor);
    }

    protected function receivePurchase(User $actor, Purchase $purchase): Purchase
    {
        return app(PurchaseService::class)->receive($purchase, $actor);
    }

    /**
     * Deterministic Luhn-valid IMEI for tests.
     */
    protected function validImei(int $seed): string
    {
        $base = '3567890'.str_pad((string) $seed, 7, '0', STR_PAD_LEFT);
        $sum = 0;
        $double = true;

        for ($i = strlen($base) - 1; $i >= 0; $i--) {
            $digit = (int) $base[$i];

            if ($double) {
                $digit *= 2;

                if ($digit > 9) {
                    $digit -= 9;
                }
            }

            $sum += $digit;
            $double = ! $double;
        }

        return $base.((10 - ($sum % 10)) % 10);
    }
}
