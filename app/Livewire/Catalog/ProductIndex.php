<?php

declare(strict_types=1);

namespace App\Livewire\Catalog;

use App\Enums\Custody;
use App\Enums\ProductType;
use App\Enums\Sellability;
use App\Exceptions\ErpException;
use App\Models\AccessoryStockBalance;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SerializedUnit;
use App\Support\AuditLogger;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class ProductIndex extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $type = 'all';

    public ?string $modal = null;

    public ?int $editingProductId = null;

    public string $name = '';

    public string $productType = 'PHONE';

    public ?string $brandId = null;

    public string $newBrand = '';

    public string $newCategory = '';

    public string $model = '';

    public string $sku = '';

    public bool $isActive = true;

    public ?int $variantProductId = null;

    public string $variantSku = '';

    public string $storage = '';

    public string $ram = '';

    public string $color = '';

    public string $variantCondition = '';

    public string $defaultPurchasePrice = '';

    public string $defaultSellingPrice = '';

    public string $reorderPoint = '0';

    public function mount(): void
    {
        $this->authorize('viewAny', Product::class);
    }

    public function updated(): void
    {
        $this->resetPage();
    }

    public function openProductModal(?int $productId = null): void
    {
        $this->authorize('create', Product::class);
        $this->resetErrorBag();
        $this->editingProductId = $productId;

        if ($productId !== null) {
            $product = Product::query()->findOrFail($productId);
            $this->name = $product->name;
            $this->productType = $product->product_type->value;
            $this->brandId = $product->brand_id !== null ? (string) $product->brand_id : null;
            $this->model = (string) $product->model;
            $this->sku = (string) $product->sku;
            $this->isActive = $product->is_active;
            $this->newBrand = '';
            $this->newCategory = '';
        } else {
            $this->name = '';
            $this->productType = 'PHONE';
            $this->brandId = null;
            $this->model = '';
            $this->sku = '';
            $this->isActive = true;
            $this->newBrand = '';
            $this->newCategory = '';
        }

        $this->modal = 'product';
    }

    public function saveProduct(): void
    {
        $this->authorize('create', Product::class);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:180'],
            'productType' => ['required', 'in:PHONE,ACCESSORY'],
            'brandId' => ['nullable', 'integer', 'exists:brands,id'],
            'newBrand' => ['nullable', 'string', 'max:120'],
            'newCategory' => ['nullable', 'string', 'max:120'],
            'model' => ['nullable', 'string', 'max:120'],
            'sku' => ['nullable', 'string', 'max:60', 'unique:products,sku'.($this->editingProductId ? ','.$this->editingProductId : '')],
            'isActive' => ['boolean'],
        ]);

        try {
            $brandId = $validated['brandId'];

            if (! empty($validated['newBrand'])) {
                $brand = Brand::query()->firstOrCreate(
                    ['slug' => Str::slug($validated['newBrand'])],
                    ['name' => trim($validated['newBrand'])],
                );
                $brandId = $brand->id;
            }

            $categoryId = null;

            if (! empty($validated['newCategory'])) {
                $category = Category::query()->firstOrCreate(
                    ['slug' => Str::slug($validated['newCategory'])],
                    ['name' => trim($validated['newCategory']), 'product_type' => $validated['productType']],
                );
                $categoryId = $category->id;
            }

            $attributes = [
                'product_type' => $validated['productType'],
                'brand_id' => $brandId,
                'category_id' => $categoryId,
                'name' => $validated['name'],
                'model' => $validated['model'] ?: null,
                'sku' => $validated['sku'] ?: null,
                'is_active' => $validated['isActive'],
            ];

            if ($this->editingProductId !== null) {
                $product = Product::query()->findOrFail($this->editingProductId);
                $old = $product->only(array_keys($attributes));
                $product->forceFill($attributes)->save();
                AuditLogger::record('catalog.product_updated', $product, $old, $attributes, null, null, auth()->user());
            } else {
                $product = Product::create($attributes);
                AuditLogger::record('catalog.product_created', $product, [], $attributes, null, null, auth()->user());
            }
        } catch (ErpException $exception) {
            $this->addError('product', $exception->getMessage());

            return;
        }

        $this->modal = null;
        session()->flash('success', "Catalog product {$product->name} saved.");
    }

    public function openVariantModal(int $productId): void
    {
        $this->authorize('create', Product::class);
        $this->resetErrorBag();
        $this->variantProductId = $productId;
        $this->variantSku = '';
        $this->storage = '';
        $this->ram = '';
        $this->color = '';
        $this->variantCondition = '';
        $this->defaultPurchasePrice = '';
        $this->defaultSellingPrice = '';
        $this->reorderPoint = '0';
        $this->modal = 'variant';
    }

    public function saveVariant(): void
    {
        $this->authorize('create', Product::class);

        $product = Product::query()->findOrFail($this->variantProductId);

        $validated = $this->validate([
            'variantSku' => ['nullable', 'string', 'max:60', 'unique:product_variants,sku'],
            'storage' => ['nullable', 'string', 'max:40'],
            'ram' => ['nullable', 'string', 'max:40'],
            'color' => ['nullable', 'string', 'max:60'],
            'variantCondition' => ['nullable', 'string', 'max:20'],
            'defaultPurchasePrice' => ['nullable', 'numeric', 'min:0'],
            'defaultSellingPrice' => ['nullable', 'numeric', 'min:0'],
            'reorderPoint' => ['required', 'integer', 'min:0'],
        ]);

        if (
            $product->product_type === ProductType::Phone
            && trim((string) $validated['storage']) === ''
            && trim((string) $validated['color']) === ''
        ) {
            $this->addError('storage', 'Give the phone variant at least a storage or colour so it can be identified.');

            return;
        }

        $variant = ProductVariant::create([
            'product_id' => $product->getKey(),
            'sku' => $validated['variantSku'] ?: null,
            'storage' => $validated['storage'] ?: null,
            'ram' => $validated['ram'] ?: null,
            'color' => $validated['color'] ?: null,
            'condition_grade' => $validated['variantCondition'] ?: null,
            'default_purchase_price' => $validated['defaultPurchasePrice'] ?: null,
            'default_selling_price' => $validated['defaultSellingPrice'] ?: null,
            'reorder_point' => (int) $validated['reorderPoint'],
            'is_active' => true,
        ]);

        AuditLogger::record('catalog.variant_created', $variant, [], $variant->toArray(), null, null, auth()->user());

        $this->modal = null;
        session()->flash('success', "Variant added to {$product->name}.");
    }

    public function render(): View
    {
        $query = Product::query()
            ->with(['brand', 'variants'])
            ->withCount('variants');

        if ($this->type !== 'all') {
            $query->where('product_type', $this->type);
        }

        if (trim($this->search) !== '') {
            $term = '%'.trim($this->search).'%';
            $query->where(function ($builder) use ($term): void {
                $builder->where('name', 'like', $term)
                    ->orWhere('model', 'like', $term)
                    ->orWhere('sku', 'like', $term)
                    ->orWhereHas('brand', fn ($brand) => $brand->where('name', 'like', $term));
            });
        }

        $products = $query->orderBy('product_type')->orderBy('name')->paginate(25);

        $variantIds = $products->getCollection()->flatMap(fn (Product $product) => $product->variants->pluck('id'))->all();
        $unitIds = auth()->user()->accessibleUnitIds();

        $accessoryStock = AccessoryStockBalance::query()
            ->whereIn('product_variant_id', $variantIds)
            ->whereIn('business_unit_id', $unitIds)
            ->get()
            ->groupBy('product_variant_id');

        $phoneStock = SerializedUnit::query()
            ->selectRaw('product_variant_id, count(*) as total')
            ->whereIn('product_variant_id', $variantIds)
            ->where('current_custody', Custody::InUnit->value)
            ->where('sellability_status', Sellability::Available->value)
            ->whereIn('current_unit_id', $unitIds)
            ->groupBy('product_variant_id')
            ->pluck('total', 'product_variant_id');

        return view('livewire.catalog.index', [
            'products' => $products,
            'brands' => Brand::query()->orderBy('name')->get(),
            'accessoryStock' => $accessoryStock,
            'phoneStock' => $phoneStock,
            'canManage' => auth()->user()->can('catalog.manage'),
            'currency' => config('erp.currency.symbol'),
        ]);
    }
}
