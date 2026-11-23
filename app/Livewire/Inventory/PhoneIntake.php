<?php

declare(strict_types=1);

namespace App\Livewire\Inventory;

use App\Domain\Inventory\ManualIntakeService;
use App\Domain\Inventory\ProductIntakeService;
use App\Domain\Inventory\SerialIdentityService;
use App\Enums\ProductType;
use App\Exceptions\ErpException;
use App\Models\AccessoryStockBalance;
use App\Models\Brand;
use App\Models\BusinessUnit;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SerializedUnit;
use App\Models\Supplier;
use App\Support\UnitContext;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.app')]
class PhoneIntake extends Component
{
    #[Url(as: 'unit')]
    public string $unitId = '';

    #[Url]
    public string $mode = 'phone';

    public bool $restock = false;

    public string $variantId = '';

    public string $brandName = '';

    public string $modelName = '';

    public string $brandId = '';

    public string $modelId = '';

    public ?string $country = null;

    public ?string $colour = null;

    public ?string $storage = null;

    public ?string $ram = null;

    public string $category = 'PRE_OWNED';

    public function updatedBrandName(): void
    {
        $this->brandName = trim($this->brandName);
        $this->brandId = (string) (Brand::whereRaw('LOWER(name) = ?', [mb_strtolower($this->brandName)])->value('id') ?? '');
        $this->modelName = '';
        $this->updatedBrandId();
    }

    public function updatedModelName(): void
    {
        $this->modelName = trim($this->modelName);
        $this->modelId = $this->brandId === '' ? '' : (string) (Product::phones()
            ->where('brand_id', $this->brandId)->where('is_active', true)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($this->modelName)])->value('id') ?? '');
        $this->updatedModelId();
    }

    public function updatedBrandId(): void
    {
        $this->modelId = '';
        $this->updatedModelId();
    }

    public function updatedModelId(): void
    {
        $this->variantId = '';
        $this->country = $this->colour = $this->storage = $this->ram = null;
    }

    public ?string $buyingParty = null;

    public string $primaryImei = '';

    public ?string $secondaryImei = null;

    public ?string $conditionGrade = 'A';

    public ?string $batteryHealth = null;

    public ?string $cycleCount = null;

    public ?string $boxKit = null;

    public string $acquisitionCost = '';

    public ?string $expectedSellingPrice = null;

    public string $acquiredAt = '';

    public ?string $notes = null;

    public ?string $statusNote = null;

    public ?string $adapterName = null;

    public ?string $adapterPrice = null;

    public ?string $cableName = null;

    public ?string $cablePrice = null;

    public string $productSupplier = '';

    public string $productName = '';

    public string $productSku = '';

    public string $productBuyingPrice = '';

    public ?string $productSellingPrice = null;

    public string $productStock = '1';

    public ?string $matchedProduct = null;

    public bool $allowLegacy = false;

    public bool $generateInternalSerial = false;

    public ?string $flagReason = null;

    public array $phoneRows = [];

    public array $productRows = [];

    public array $selectedRows = [];

    private bool $savingRows = false;

    private const PHONE_FIELDS = [
        'restock', 'unitId', 'variantId', 'brandName', 'modelName', 'brandId', 'modelId', 'country',
        'colour', 'storage', 'ram', 'category', 'buyingParty', 'primaryImei', 'secondaryImei',
        'conditionGrade', 'batteryHealth', 'cycleCount', 'boxKit', 'acquisitionCost',
        'expectedSellingPrice', 'acquiredAt', 'notes', 'statusNote', 'adapterName',
        'adapterPrice', 'cableName', 'cablePrice', 'allowLegacy', 'generateInternalSerial', 'flagReason',
    ];

    private const PRODUCT_FIELDS = [
        'unitId', 'productName', 'productSku', 'productSupplier', 'productBuyingPrice',
        'productSellingPrice', 'productStock',
    ];

    private function rowProperty(): string
    {
        return $this->mode === 'product' ? 'productRows' : 'phoneRows';
    }

    private function entryDefaults(bool $product = false): array
    {
        $defaults = (new \ReflectionClass(self::class))->getDefaultProperties();
        $row = [];
        foreach ($product ? self::PRODUCT_FIELDS : self::PHONE_FIELDS as $field) {
            $row[$field] = $defaults[$field];
        }
        $row['unitId'] = $this->unitId;
        if (! $product) {
            $row['acquiredAt'] = now()->toDateString();
        }

        return $row;
    }

    public function addLine(): void
    {
        $property = $this->rowProperty();
        if (count($this->{$property}) >= 100) {
            $this->addError('form', 'Save the current 100 lines before adding more.');

            return;
        }
        $this->{$property}[(string) Str::uuid()] = $this->entryDefaults($this->mode === 'product');
    }

    public function duplicateLine(string $key): void
    {
        $property = $this->rowProperty();
        if (! isset($this->{$property}[$key]) || count($this->{$property}) >= 100) {
            return;
        }
        $row = $this->{$property}[$key];
        if ($this->mode !== 'product') {
            $row['restock'] = false;
            $row['variantId'] = '';
            $row['primaryImei'] = '';
            $row['secondaryImei'] = null;
            $row['generateInternalSerial'] = false;
        }
        $this->{$property}[(string) Str::uuid()] = $row;
    }

    public function removeSelectedLines(): void
    {
        $property = $this->rowProperty();
        foreach ($this->selectedRows as $key) {
            unset($this->{$property}[$key]);
        }
        $this->selectedRows = [];
        $this->resetErrorBag();
        if ($this->{$property} === []) {
            $this->addLine();
        }
    }

    public function restockLine(string $key, ManualIntakeService $intake, ProductIntakeService $products): void
    {
        if ($this->mode !== 'phone' || ! isset($this->phoneRows[$key])) {
            return;
        }
        $allRows = $this->phoneRows;
        $this->phoneRows = [$key => array_merge($allRows[$key], ['restock' => true])];
        try {
            $this->saveRows($intake, $products);
        } catch (\Throwable $exception) {
            $this->phoneRows = $allRows;
            throw $exception;
        }
        unset($allRows[$key]);
        if ($allRows !== []) {
            $this->phoneRows = $allRows;
        }
    }

    public function updatedPhoneRows(mixed $value, ?string $path): void
    {
        if ($path === null || ! str_contains($path, '.')) {
            return;
        }
        [$key, $field] = explode('.', $path, 2);
        if (! in_array($field, ['primaryImei', 'secondaryImei'], true) || ! isset($this->phoneRows[$key])) {
            return;
        }
        $this->phoneRows[$key]['restock'] = false;
        $this->phoneRows[$key]['variantId'] = '';
        $unit = app(SerialIdentityService::class)->findByImei((string) $value);
        if (! $unit || ! auth()->user()->can('view', $unit)) {
            return;
        }
        $variant = $unit->variant;
        $this->phoneRows[$key] = array_merge($this->phoneRows[$key], [
            'variantId' => (string) $variant->id,
            'brandName' => $variant->product->brand?->name ?? '',
            'modelName' => $variant->product->name,
            'country' => $variant->country, 'colour' => $variant->color,
            'storage' => $variant->storage, 'ram' => $variant->ram,
            'primaryImei' => $unit->primary_imei ?? '', 'secondaryImei' => $unit->secondary_imei,
            'batteryHealth' => $unit->battery_health === null ? null : (string) $unit->battery_health,
            'cycleCount' => $unit->cycle_count === null ? null : (string) $unit->cycle_count,
            'conditionGrade' => $unit->condition_grade, 'boxKit' => $unit->box_kit,
            'expectedSellingPrice' => $unit->expected_selling_price,
            'category' => $unit->inventory_category ?? 'PRE_OWNED',
            'notes' => $unit->notes, 'statusNote' => $unit->status_note,
        ]);
    }

    public function updatedProductRows(mixed $value, ?string $path): void
    {
        if ($path === null || ! str_contains($path, '.')) {
            return;
        }
        [$key, $field] = explode('.', $path, 2);
        if ($field !== 'productSku' || ! isset($this->productRows[$key])) {
            return;
        }
        $variant = ProductVariant::with('product')->where('sku', trim((string) $value))->first();
        if ($variant?->product?->product_type === ProductType::Accessory) {
            $this->productRows[$key]['productName'] = $variant->product->name;
            $this->productRows[$key]['productBuyingPrice'] = (string) $variant->default_purchase_price;
            $this->productRows[$key]['productSellingPrice'] = $variant->default_selling_price === null ? null : (string) $variant->default_selling_price;
        }
    }

    public function saveRows(ManualIntakeService $intake, ProductIntakeService $products): void
    {
        $this->authorize('create', SerializedUnit::class);
        $property = $this->rowProperty();
        $this->validate([$property => ['required', 'array', 'min:1', 'max:100']]);
        $fields = $this->mode === 'product' ? self::PRODUCT_FIELDS : self::PHONE_FIELDS;
        $defaults = $this->entryDefaults($this->mode === 'product');
        $original = [];
        foreach ($fields as $field) {
            $original[$field] = $this->{$field};
        }
        $this->savingRows = true;
        $this->resetErrorBag();
        try {
            DB::transaction(function () use ($property, $fields, $defaults, $intake, $products) {
                foreach ($this->{$property} as $key => $row) {
                    try {
                        foreach ($fields as $field) {
                            $this->{$field} = $row[$field] ?? $defaults[$field];
                        }
                        $this->save($intake, $products);
                        if ($this->getErrorBag()->isNotEmpty()) {
                            throw ValidationException::withMessages($this->getErrorBag()->toArray());
                        }
                    } catch (ValidationException $exception) {
                        $mapped = [];
                        foreach ($exception->errors() as $field => $messages) {
                            $field = match ($field) {
                                'primary_imei' => 'primaryImei', 'secondary_imei' => 'secondaryImei',
                                'flag_reason' => 'flagReason', 'product_variant_id' => 'modelName',
                                default => $field,
                            };
                            $mapped["{$property}.{$key}.{$field}"] = $messages;
                        }
                        throw ValidationException::withMessages($mapped);
                    }
                }
            });
        } finally {
            foreach ($original as $field => $value) {
                $this->{$field} = $value;
            }
            $this->savingRows = false;
        }
        session()->flash('success', count($this->{$property}).' line(s) added to stock.');
        $this->{$property} = [];
        $this->selectedRows = [];
        $this->addLine();
    }

    public function mount(UnitContext $unitContext): void
    {
        $this->authorize('create', SerializedUnit::class);

        $current = $unitContext->current(auth()->user());
        $this->unitId = (string) ($current?->getKey() ?? '');
        $this->acquiredAt = now()->toDateString();
        $this->phoneRows[(string) Str::uuid()] = $this->entryDefaults();
        $this->productRows[(string) Str::uuid()] = $this->entryDefaults(true);
    }

    public function updatedMode(): void
    {
        $this->selectedRows = [];
        $this->resetErrorBag();
    }

    public function updatedProductSku(?string $sku): void
    {
        $this->matchedProduct = null;
        $sku = trim((string) $sku);

        if ($sku === '') {
            return;
        }

        $variant = ProductVariant::query()
            ->with('product')
            ->where('sku', $sku)
            ->first();

        if ($variant === null) {
            return;
        }

        if ($variant->product?->product_type !== ProductType::Accessory) {
            $this->matchedProduct = 'This SKU belongs to a phone. Product entry only accepts accessory SKUs.';

            return;
        }

        $this->productName = (string) $variant->product?->name;
        $this->productBuyingPrice = $variant->default_purchase_price !== null ? (string) $variant->default_purchase_price : '';
        $this->productSellingPrice = $variant->default_selling_price !== null ? (string) $variant->default_selling_price : '';

        $stock = AccessoryStockBalance::query()
            ->where('business_unit_id', (int) $this->unitId)
            ->where('product_variant_id', $variant->getKey())
            ->first();

        $this->matchedProduct = 'Existing product: '.$variant->displayName().' · current stock '.($stock?->qty_on_hand ?? 0).'. Stock will be added.';
    }

    public function updatedVariantId(?string $variantId): void
    {
        $selected = ProductVariant::with('product')->find((int) $variantId);
        if ($selected) {
            $this->modelName = $selected->product->name;
            $this->brandName = (string) $selected->product->brand?->name;
            $this->modelId = (string) $selected->product_id;
            $this->brandId = (string) $selected->product->brand_id;
            $this->country = $selected->country;
            $this->colour = $selected->color;
            $this->storage = $selected->storage;
            $this->ram = $selected->ram;
        }
        if (($this->expectedSellingPrice ?? '') !== '' || $variantId === null || $variantId === '') {
            return;
        }

        $variant = ProductVariant::query()->find((int) $variantId);

        if ($variant?->default_selling_price !== null) {
            $this->expectedSellingPrice = (string) $variant->default_selling_price;
        }
    }

    public function save(ManualIntakeService $intake, ProductIntakeService $products): void
    {
        $this->authorize('create', SerializedUnit::class);

        if ($this->mode === 'product') {
            $this->saveProduct($products);

            return;
        }

        $user = auth()->user();
        $accessible = $user->accessibleUnitIds();

        foreach ([
            'secondaryImei', 'conditionGrade', 'batteryHealth', 'cycleCount', 'boxKit',
            'flagReason', 'notes', 'buyingParty', 'expectedSellingPrice', 'statusNote',
            'adapterName', 'adapterPrice', 'cableName', 'cablePrice',
        ] as $field) {
            if ($this->{$field} === '') {
                $this->{$field} = null;
            }
        }

        $validated = $this->validate([
            'restock' => ['boolean'],
            'unitId' => ['required', 'integer', Rule::in($accessible)],
            'brandName' => ['required_without:variantId', 'string', 'max:100'],
            'modelName' => ['required_without:variantId', 'string', 'max:180'],
            'variantId' => ['nullable', 'integer', Rule::exists('product_variants', 'id')->where('is_active', true)],
            'country' => ['nullable', 'string', 'max:100'],
            'colour' => ['nullable', 'string', 'max:60'],
            'storage' => ['nullable', 'string', 'max:60'],
            'ram' => ['nullable', 'string', 'max:60'],
            'category' => ['required', Rule::in(['PRE_OWNED', 'NEW'])],
            'primaryImei' => ['nullable', 'string', 'max:32'],
            'secondaryImei' => ['nullable', 'string', 'max:32'],
            'conditionGrade' => ['nullable', 'string', 'max:20'],
            'batteryHealth' => ['nullable', 'integer', 'between:0,100'],
            'cycleCount' => ['nullable', 'integer', 'between:0,100000'],
            'boxKit' => ['nullable', 'string', 'max:60'],
            'acquisitionCost' => ['required', 'numeric', 'min:0', 'max:999999999'],
            'acquiredAt' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'buyingParty' => ['nullable', 'string', 'max:180'],
            'expectedSellingPrice' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'statusNote' => ['nullable', 'string', 'max:255'],
            'adapterName' => ['nullable', 'string', 'max:120'],
            'adapterPrice' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'cableName' => ['nullable', 'string', 'max:120'],
            'cablePrice' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'allowLegacy' => ['boolean'],
            'generateInternalSerial' => ['boolean'],
            'flagReason' => ['nullable', 'string', 'max:255', 'required_if:allowLegacy,true'],
        ], [
            'acquisitionCost.required' => 'A real acquisition cost is required; it becomes this handset\'s cost basis.',
        ]);

        $identities = app(SerialIdentityService::class);
        $primaryMatch = $identities->findByImei($validated['primaryImei'] ?? null);
        $existingUnit = $primaryMatch ?? $identities->findByImei($validated['secondaryImei'] ?? null);
        if ($existingUnit && ! $validated['restock']) {
            $this->addError($primaryMatch ? 'primary_imei' : 'secondary_imei', 'This IMEI was registered before. Select Restock to keep its original history.');

            return;
        }
        if ($validated['restock']) {
            if (! $existingUnit) {
                $this->addError('primary_imei', 'No previous handset found for this IMEI. Use a new entry.');

                return;
            }
            $this->authorize('update', $existingUnit);
            if (($validated['primaryImei'] ?? '') !== $existingUnit->primary_imei || ($validated['secondaryImei'] ?: null) !== $existingUnit->secondary_imei) {
                $this->addError('primary_imei', 'Restocking must keep both original IMEIs. Enter the IMEI again to load the handset.');

                return;
            }
        }

        $businessUnit = BusinessUnit::query()->findOrFail((int) $validated['unitId']);
        $variant = filled($validated['variantId'] ?? null) ? ProductVariant::query()->with('product')->findOrFail((int) $validated['variantId']) : null;

        if ($variant && ($variant->product->product_type !== ProductType::Phone || ! $variant->product->is_active)) {
            $this->addError('variantId', 'Select an active phone variant.');

            return;
        }

        try {
            $unit = DB::transaction(function () use ($variant, $validated, $intake, $businessUnit, $user, $existingUnit) {
                $country = trim((string) $validated['country']) ?: null;
                if ($country !== null) {
                    $country = ProductVariant::whereRaw('LOWER(country) = ?', [mb_strtolower($country)])->value('country') ?? $country;
                }
                $spec = [
                    'country' => $country,
                    'color' => trim((string) $this->colour) ?: null,
                    'storage' => trim((string) $this->storage) ?: null,
                    'ram' => trim((string) $this->ram) ?: null,
                ];
                if (! $variant) {
                    $brand = Brand::whereRaw('LOWER(name) = ?', [mb_strtolower(trim($validated['brandName']))])->first();
                    if (! $brand) {
                        $this->authorize('catalog.manage');
                        $brand = Brand::create(['name' => trim($validated['brandName']),
                            'slug' => Str::slug($validated['brandName']).'-'.Str::lower(Str::random(8)), 'is_active' => true]);
                    }
                    $model = Product::phones()->where('brand_id', $brand->id)->where('is_active', true)
                        ->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($validated['modelName']))])->first();
                    if (! $model) {
                        $this->authorize('catalog.manage');
                        $model = Product::create(['brand_id' => $brand->id, 'name' => trim($validated['modelName']),
                            'model' => trim($validated['modelName']), 'product_type' => ProductType::Phone, 'is_active' => true]);
                    }
                    $variant = ProductVariant::firstOrCreate(
                        array_merge(['product_id' => $model->id, 'is_active' => true], $spec),
                        ['sku' => 'PHONE-'.Str::uuid(), 'default_purchase_price' => $validated['acquisitionCost'],
                            'default_selling_price' => $validated['expectedSellingPrice']],
                    );
                }
                if ($variant->only(array_keys($spec)) !== $spec) {
                    $variant = ProductVariant::firstOrCreate(
                        array_merge(['product_id' => $variant->product_id, 'is_active' => true], $spec),
                        ['sku' => 'PHONE-'.Str::uuid(), 'default_purchase_price' => $variant->default_purchase_price,
                            'default_selling_price' => $variant->default_selling_price],
                    );
                }
                $supplier = null;
                $name = trim((string) $validated['buyingParty']);
                if ($name !== '') {
                    $supplier = Supplier::whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first();
                    if (! $supplier) {
                        $this->authorize('supplier.manage');
                        $supplier = Supplier::create(['name' => $name, 'type' => Supplier::TYPE_BUSINESS, 'is_active' => true]);
                    }
                }
                $unit = $intake->addPhone(
                    variant: $variant,
                    identity: [
                        'primary_imei' => ($validated['primaryImei'] ?? '') ?: null,
                        'secondary_imei' => $validated['secondaryImei'] ?: null,
                        'allow_legacy' => $validated['allowLegacy'],
                        'generate_internal_serial' => $validated['generateInternalSerial'],
                        'flag_reason' => $validated['flagReason'] ?: null,
                    ],
                    attributes: [
                        'battery_health' => $validated['batteryHealth'] === null ? null : (int) $validated['batteryHealth'],
                        'cycle_count' => $validated['cycleCount'] === null ? null : (int) $validated['cycleCount'],
                        'condition_grade' => $validated['conditionGrade'] ?: null,
                        'box_kit' => $validated['boxKit'] ?: null,
                        'notes' => $validated['notes'] ?: null,
                        'expected_selling_price' => $validated['expectedSellingPrice'] === null ? null : (string) $validated['expectedSellingPrice'],
                        'status_note' => $validated['statusNote'] ?: null,
                    ],
                    businessUnit: $businessUnit,
                    acquisitionCost: $validated['acquisitionCost'],
                    actor: $user,
                    acquiredAt: $validated['acquiredAt'],
                    note: $existingUnit ? 'Manual restock after return / reacquisition' : 'Manual inventory entry',
                    buyingParty: $supplier?->name,
                    existingUnit: $existingUnit,
                    bundle: [
                        'adapter_name' => $validated['adapterName'] ?: null,
                        'adapter_price' => $validated['adapterPrice'] ?: null,
                        'cable_name' => $validated['cableName'] ?: null,
                        'cable_price' => $validated['cablePrice'] ?: null,
                    ],
                );
                $unit->forceFill(['supplier_id' => $supplier?->id, 'inventory_category' => $validated['category']])->save();

                return $unit;
            });
        } catch (ErpException $exception) {
            foreach ($exception->errors() as $field => $message) {
                $this->addError($field, $message);
            }

            $this->addError('form', $exception->getMessage());

            return;
        }

        if ($this->savingRows) {
            return;
        }

        session()->flash('success', "Handset {$unit->primary_imei} added to {$businessUnit->name} with a new acquisition cycle.");

        $this->redirect(route('inventory.index', ['unit' => $businessUnit->getKey()]), navigate: true);
    }

    private function saveProduct(ProductIntakeService $products): void
    {
        $user = auth()->user();

        if ($this->productSellingPrice === '') {
            $this->productSellingPrice = null;
        }

        $validated = $this->validate([
            'unitId' => ['required', 'integer', Rule::in($user->accessibleUnitIds())],
            'productSupplier' => ['nullable', 'string', 'max:180'],
            'productName' => ['required', 'string', 'max:180'],
            'productSku' => ['required', 'string', 'max:60'],
            'productBuyingPrice' => ['required', 'numeric', 'min:0', 'max:999999999'],
            'productSellingPrice' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'productStock' => ['required', 'integer', 'min:1', 'max:100000'],
        ], [
            'productName.required' => 'Product name is required.',
            'productSku.required' => 'SKU is required so the product can be sold and tracked.',
        ]);

        $businessUnit = BusinessUnit::query()->findOrFail((int) $validated['unitId']);

        try {
            $result = $products->add([
                'supplier_name' => $validated['productSupplier'] ?? '',
                'name' => $validated['productName'],
                'sku' => $validated['productSku'],
                'buying_price' => $validated['productBuyingPrice'],
                'selling_price' => $validated['productSellingPrice'],
                'quantity' => (int) $validated['productStock'],
            ], $businessUnit, $user);
        } catch (ErpException $exception) {
            foreach ($exception->errors() as $field => $message) {
                $mapped = match ($field) {
                    'supplier_name' => 'productSupplier',
                    'product_name' => 'productName',
                    'product_sku' => 'productSku',
                    'buying_price' => 'productBuyingPrice',
                    'stock_quantity' => 'productStock',
                    default => $field,
                };

                $this->addError($mapped, $message);
            }

            $this->addError('form', $exception->getMessage());

            return;
        }

        if ($this->savingRows) {
            return;
        }

        session()->flash(
            'success',
            ($result['created'] ? 'Product created and ' : 'Stock added: ').
            $result['quantity'].' unit(s) of '.$result['variant']->displayName().
            ' · new stock '.$result['balance']->qty_on_hand.'.'
        );

        $this->redirect(route('catalog.index'), navigate: true);
    }

    public function render(): View
    {
        $variants = ProductVariant::query()
            ->with('product.brand')
            ->whereHas('product', fn ($query) => $query->where('product_type', 'PHONE')->where('is_active', true))
            ->get()
            ->sortBy(fn (ProductVariant $variant) => $variant->displayName())
            ->values();

        $matches = [];
        foreach ($this->phoneRows as $key => $row) {
            $unit = app(SerialIdentityService::class)->findByImei($row['primaryImei'] ?? null)
                ?? app(SerialIdentityService::class)->findByImei($row['secondaryImei'] ?? null);
            if ($unit && auth()->user()->can('view', $unit)) {
                $matches[$key] = $unit;
            }
        }

        return view('livewire.inventory.phone-intake', [
            'existingPhones' => $matches,
            'variants' => $variants->filter(fn ($variant) => (! $this->modelId || (string) $variant->product_id === $this->modelId) && (! $this->brandId || (string) $variant->product->brand_id === $this->brandId)),
            'accessoryVariants' => ProductVariant::with('product')->whereHas('product', fn ($q) => $q->where('product_type', 'ACCESSORY')->where('is_active', true))->orderBy('sku')->get(),
            'phoneModels' => Product::phones()->with('brand')->where('is_active', true)->orderBy('name')->get(),
            'brands' => Brand::orderBy('name')->get(),
            'models' => Product::phones()->where('is_active', true)->when($this->brandId, fn ($q) => $q->where('brand_id', $this->brandId))->orderBy('name')->get(),
            'suppliers' => Supplier::active()->orderBy('name')->get(),
            'countries' => collect(['Bangladesh', 'China', 'Hong Kong', 'India', 'Japan', 'Singapore', 'South Korea', 'United Arab Emirates', 'United Kingdom', 'United States'])
                ->merge(ProductVariant::whereNotNull('country')->distinct()->pluck('country'))->unique()->sort()->values(),
            'colours' => ProductVariant::whereNotNull('color')->distinct()->orderBy('color')->pluck('color'),
            'units' => BusinessUnit::query()->whereIn('id', auth()->user()->accessibleUnitIds())->active()->ordered()->get(),
            'canOverrideImei' => auth()->user()->can('inventory.imei.override'),
            'currency' => config('erp.currency.symbol'),
        ]);
    }
}
