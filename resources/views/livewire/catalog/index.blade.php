<div>
    <x-erp.page-header title="Catalog" subtitle="Products hold the model definition; variants are the sellable SKU/grade combination that phones and accessories attach to.">
        <x-slot:actions>
            @if ($canManage)
                <button type="button" wire:click="openProductModal" class="rounded-lg bg-indigo-600 px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-indigo-700">New product</button>
            @endif
        </x-slot:actions>
    </x-erp.page-header>

    <div class="mb-4 grid gap-2 rounded-xl border border-slate-200 bg-white p-3 shadow-sm md:grid-cols-3">
        <input type="search" wire:model.live.debounce.400ms="search" placeholder="Name, model, SKU, brand…" class="rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500 md:col-span-2" aria-label="Search catalog">
        <select wire:model.live="type" class="rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500" aria-label="Product type">
            <option value="all">Phones and accessories</option>
            <option value="PHONE">Phones</option>
            <option value="ACCESSORY">Accessories</option>
        </select>
    </div>

    @error('product') <div class="mb-3 rounded-lg border border-rose-200 bg-rose-50 px-4 py-2 text-sm text-rose-800">{{ $message }}</div> @enderror

    <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200 text-sm">
                <thead class="bg-slate-50 text-left text-[11px] uppercase tracking-wider text-slate-500">
                    <tr>
                        <th class="px-4 py-2.5 font-semibold">Product</th>
                        <th class="px-4 py-2.5 font-semibold">Type</th>
                        <th class="px-4 py-2.5 font-semibold">Brand</th>
                        <th class="px-4 py-2.5 text-right font-semibold">Variants</th>
                        <th class="px-4 py-2.5 text-right font-semibold">Available stock</th>
                        <th class="px-4 py-2.5 font-semibold">Status</th>
                        <th class="px-4 py-2.5"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($products as $product)
                        <tr wire:key="product-{{ $product->id }}" class="hover:bg-slate-50/70">
                            <td class="px-4 py-2.5">
                                <p class="font-medium text-slate-900">{{ $product->name }}</p>
                                <p class="text-xs text-slate-500">{{ $product->model ?? 'No model code' }} · {{ $product->sku ?? 'No SKU' }}</p>
                            </td>
                            <td class="px-4 py-2.5"><x-erp.badge :tone="$product->product_type === \App\Enums\ProductType::Phone ? 'indigo' : 'slate'">{{ $product->product_type->label() }}</x-erp.badge></td>
                            <td class="px-4 py-2.5 text-slate-700">{{ $product->brand?->name ?? '—' }}</td>
                            <td class="px-4 py-2.5 text-right tabular-nums text-slate-700">{{ $product->variants_count }}</td>
                            <td class="px-4 py-2.5 text-right text-xs text-slate-600">
                                @if ($product->product_type === \App\Enums\ProductType::Phone)
                                    @php($available = $product->variants->sum(fn ($variant) => (int) ($phoneStock[$variant->id] ?? 0)))
                                    {{ number_format($available) }} handset(s)
                                @else
                                    @php($qty = $product->variants->sum(fn ($variant) => (int) collect($accessoryStock[$variant->id] ?? [])->sum('qty_on_hand')))
                                    {{ number_format($qty) }} unit(s)
                                @endif
                            </td>
                            <td class="px-4 py-2.5"><x-erp.badge :tone="$product->is_active ? 'green' : 'slate'">{{ $product->is_active ? 'Active' : 'Inactive' }}</x-erp.badge></td>
                            <td class="px-4 py-2.5 text-right">
                                @if ($canManage)
                                    <button type="button" wire:click="openVariantModal({{ $product->id }})" class="rounded border border-slate-300 px-2 py-1 text-xs font-medium text-slate-700 hover:bg-slate-100">Add variant</button>
                                    <button type="button" wire:click="openProductModal({{ $product->id }})" class="rounded border border-slate-300 px-2 py-1 text-xs font-medium text-slate-700 hover:bg-slate-100">Edit</button>
                                @endif
                            </td>
                        </tr>
                        @if ($product->variants->isNotEmpty())
                            <tr class="bg-slate-50/50">
                                <td colspan="7" class="px-4 pb-2.5">
                                    <div class="flex flex-wrap gap-1.5">
                                        @foreach ($product->variants as $variant)
                                            <span class="rounded-full border border-slate-200 bg-white px-2 py-0.5 text-[11px] text-slate-600">
                                                {{ $variant->displayName() }}
                                                @if ($product->product_type === \App\Enums\ProductType::Accessory)
                                                    · stock {{ collect($accessoryStock[$variant->id] ?? [])->sum('qty_on_hand') }}
                                                @endif
                                            </span>
                                        @endforeach
                                    </div>
                                </td>
                            </tr>
                        @endif
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-12 text-center">
                                <p class="text-sm font-medium text-slate-700">No catalog products yet.</p>
                                <p class="mt-1 text-xs text-slate-500">Create a phone model before adding inventory.</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($products->hasPages())
            <div class="border-t border-slate-200 px-4 py-3">{{ $products->links() }}</div>
        @endif
    </div>

    @if ($modal === 'product')
        <div class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-900/60 p-4 pt-16">
            <form wire:submit="saveProduct" class="w-full max-w-xl rounded-xl bg-white p-5 shadow-xl">
                <h2 class="text-base font-semibold text-slate-900">{{ $editingProductId ? 'Edit product' : 'New product' }}</h2>

                <div class="mt-4 grid gap-3 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <label class="text-xs font-semibold uppercase tracking-wide text-slate-600">Name</label>
                        <input type="text" wire:model="name" class="mt-1 w-full rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                        @error('name') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="text-xs font-semibold uppercase tracking-wide text-slate-600">Type</label>
                        <select wire:model="productType" class="mt-1 w-full rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                            <option value="PHONE">Phone</option>
                            <option value="ACCESSORY">Accessory</option>
                        </select>
                    </div>
                    <div>
                        <label class="text-xs font-semibold uppercase tracking-wide text-slate-600">Brand</label>
                        <select wire:model="brandId" class="mt-1 w-full rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                            <option value="">No brand</option>
                            @foreach ($brands as $brand)
                                <option value="{{ $brand->id }}">{{ $brand->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="text-xs font-semibold uppercase tracking-wide text-slate-600">or new brand</label>
                        <input type="text" wire:model="newBrand" placeholder="e.g. Nokia" class="mt-1 w-full rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                    </div>
                    <div>
                        <label class="text-xs font-semibold uppercase tracking-wide text-slate-600">New category</label>
                        <input type="text" wire:model="newCategory" placeholder="e.g. Smartphone" class="mt-1 w-full rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                    </div>
                    <div>
                        <label class="text-xs font-semibold uppercase tracking-wide text-slate-600">Model code</label>
                        <input type="text" wire:model="model" class="mt-1 w-full rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                    </div>
                    <div>
                        <label class="text-xs font-semibold uppercase tracking-wide text-slate-600">Product SKU</label>
                        <input type="text" wire:model="sku" class="mt-1 w-full rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                        @error('sku') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <label class="flex items-center gap-2 text-sm text-slate-700 sm:col-span-2">
                        <input type="checkbox" wire:model="isActive" class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500"> Active
                    </label>
                </div>

                <div class="mt-4 flex justify-end gap-2">
                    <button type="button" wire:click="$set('modal', null)" class="rounded-lg border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">Cancel</button>
                    <button type="submit" class="rounded-lg bg-indigo-600 px-3 py-2 text-sm font-semibold text-white hover:bg-indigo-700">Save product</button>
                </div>
            </form>
        </div>
    @endif

    @if ($modal === 'variant')
        <div class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-900/60 p-4 pt-16">
            <form wire:submit="saveVariant" class="w-full max-w-xl rounded-xl bg-white p-5 shadow-xl">
                <h2 class="text-base font-semibold text-slate-900">Add variant</h2>
                <p class="mt-1 text-xs text-slate-500">A phone handset or stock item is always attached to a variant.</p>

                <div class="mt-4 grid gap-3 sm:grid-cols-2">
                    <div>
                        <label class="text-xs font-semibold uppercase tracking-wide text-slate-600">Variant SKU</label>
                        <input type="text" wire:model="variantSku" class="mt-1 w-full rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                        @error('variantSku') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="text-xs font-semibold uppercase tracking-wide text-slate-600">Storage</label>
                        <input type="text" wire:model="storage" class="mt-1 w-full rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                    </div>
                    <div>
                        <label class="text-xs font-semibold uppercase tracking-wide text-slate-600">RAM</label>
                        <input type="text" wire:model="ram" class="mt-1 w-full rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                    </div>
                    <div>
                        <label class="text-xs font-semibold uppercase tracking-wide text-slate-600">Colour</label>
                        <input type="text" wire:model="color" class="mt-1 w-full rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                    </div>
                    <div>
                        <label class="text-xs font-semibold uppercase tracking-wide text-slate-600">Grade</label>
                        <input type="text" wire:model="variantCondition" class="mt-1 w-full rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                    </div>
                    <div>
                        <label class="text-xs font-semibold uppercase tracking-wide text-slate-600">Reorder point</label>
                        <input type="number" min="0" wire:model="reorderPoint" class="mt-1 w-full rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                        @error('reorderPoint') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="text-xs font-semibold uppercase tracking-wide text-slate-600">Default buy price</label>
                        <input type="text" inputmode="decimal" wire:model="defaultPurchasePrice" class="mt-1 w-full rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                    </div>
                    <div>
                        <label class="text-xs font-semibold uppercase tracking-wide text-slate-600">Default sell price</label>
                        <input type="text" inputmode="decimal" wire:model="defaultSellingPrice" class="mt-1 w-full rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                    </div>
                    @error('storage') <p class="text-xs text-rose-600 sm:col-span-2">{{ $message }}</p> @enderror
                </div>

                <div class="mt-4 flex justify-end gap-2">
                    <button type="button" wire:click="$set('modal', null)" class="rounded-lg border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">Cancel</button>
                    <button type="submit" class="rounded-lg bg-indigo-600 px-3 py-2 text-sm font-semibold text-white hover:bg-indigo-700">Add variant</button>
                </div>
            </form>
        </div>
    @endif
</div>
