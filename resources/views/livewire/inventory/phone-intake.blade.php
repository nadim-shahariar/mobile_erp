<div class="mx-auto min-w-0 max-w-full">
    @unless ($embedded ?? false)
    <x-erp.page-header title="Add stock" subtitle="Enter one item per line. Duplicate a phone line to reuse its details with a new IMEI.">
        <x-slot:actions>
            <a href="{{ route('inventory.index') }}" class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700">Back to inventory</a>
        </x-slot:actions>
    </x-erp.page-header>
    @endunless

    <div class="mb-4 flex flex-wrap gap-1 rounded-xl border border-slate-200 bg-white p-1 sm:w-fit">
        @foreach (['phone' => 'Phone / IMEI', 'product' => 'Product / accessory'] as $value => $label)
            <button type="button" wire:click="$set('mode', '{{ $value }}')" class="rounded-lg px-4 py-2 text-sm font-semibold {{ $mode === $value ? 'bg-indigo-600 text-white' : 'text-slate-600 hover:bg-slate-50' }}">{{ $label }}</button>
        @endforeach
    </div>

    @if (session()->has('success'))
        <div role="status" class="mb-4 rounded-lg bg-green-50 px-4 py-3 text-sm text-green-800">{{ session('success') }}</div>
    @endif
    @if ($errors->any())
        <div role="alert" class="mb-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800">Please fix the highlighted line. No lines have been saved.</div>
    @endif
    @error('form') <p class="mb-3 text-sm text-rose-700">{{ $message }}</p> @enderror

    @php
        $isProduct = $mode === 'product';
        $property = $isProduct ? 'productRows' : 'phoneRows';
        $rows = $isProduct ? $productRows : $phoneRows;
        $fields = $isProduct ? [
            'productSku' => ['SKU / barcode', 'text', 'accessory-options'],
            'productName' => ['Product name', 'text'],
            'productSupplier' => ['Supplier', 'text', 'supplier-options'],
            'productStock' => ['Quantity', 'number'],
            'productBuyingPrice' => ['Buying price ('.$currency.')', 'decimal'],
            'productSellingPrice' => ['Selling price ('.$currency.')', 'decimal'],
            'unitId' => ['Received at branch', 'unit'],
        ] : [
            'primaryImei' => ['IMEI 1', 'text'],
            'brandName' => ['Brand', 'text', 'brand-options'],
            'modelName' => ['Model / number', 'text'],
            'buyingParty' => ['Supplier', 'text', 'supplier-options'],
            'country' => ['Variant / country', 'text', 'country-options'],
            'colour' => ['Colour', 'text', 'colour-options'],
            'storage' => ['Storage', 'text'],
            'ram' => ['RAM', 'text'],
            'batteryHealth' => ['Battery health %', 'number'],
            'cycleCount' => ['Cycle count', 'number'],
            'boxKit' => ['Box / kit', 'text'],
            'statusNote' => ['Status reason', 'text'],
            'notes' => ['Notes', 'text'],
            'category' => ['Category', 'category'],
            'acquisitionCost' => ['Buying price ('.$currency.')', 'decimal'],
            'expectedSellingPrice' => ['Asking sell price ('.$currency.')', 'decimal'],
            'unitId' => ['Received at branch', 'unit'],
            'acquiredAt' => ['Received on', 'date'],
            'secondaryImei' => ['IMEI 2', 'text'],
            'conditionGrade' => ['Condition grade', 'text'],
        ];
    @endphp

    <form wire:key="entry-form-{{ $mode }}" wire:submit="saveRows" class="min-w-0 space-y-4">
        <div class="flex flex-wrap items-center gap-2">
            <button type="button" wire:click="addLine" wire:loading.attr="disabled" class="rounded-lg bg-indigo-600 px-3 py-2 text-sm font-semibold text-white">+ Add line</button>
            <button type="button" wire:click="removeSelectedLines" @disabled(count($selectedRows) === 0) class="rounded-lg border border-rose-200 px-3 py-2 text-sm font-semibold text-rose-700 disabled:opacity-40">Remove selected ({{ count($selectedRows) }})</button>
            <span class="text-sm text-slate-500">{{ count($rows) }} line(s)</span>
        </div>

        <div class="min-w-0 overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-sm" tabindex="0" aria-label="Stock entry lines. Scroll horizontally to see all columns.">
            <table class="w-full border-collapse text-left text-sm">
                <thead class="bg-slate-50 text-xs font-semibold text-slate-600">
                    <tr>
                        <th class="whitespace-nowrap px-3 py-3">Select</th>
                        <th class="px-2 py-3">Line</th>
                        @foreach ($fields as [$label])
                            <th class="whitespace-nowrap px-2 py-3">{{ $label }}</th>
                        @endforeach
                        <th class="px-3 py-3">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200">
                    @foreach ($rows as $key => $row)
                        <tr wire:key="{{ $property }}-{{ $key }}" class="align-top hover:bg-slate-50/50">
                            <td class="px-3 py-4"><input type="checkbox" wire:model.live="selectedRows" value="{{ $key }}" aria-label="Select line {{ $loop->iteration }}" class="rounded border-slate-300 text-indigo-600"></td>
                            <td class="px-2 py-4 text-slate-500">{{ $loop->iteration }}</td>
                            @foreach ($fields as $field => [$label, $type])
                                @php
                                    $binding = $property.'.'.$key.'.'.$field;
                                    $list = $fields[$field][2] ?? null;
                                    if ($field === 'modelName') $list = 'models-'.$key;
                                @endphp
                                <td wire:key="cell-{{ $property }}-{{ $key }}-{{ $field }}" class="px-2 py-2">
                                    @include('livewire.inventory.partials.entry-cell')
                                    @if (! $isProduct && $field === 'primaryImei' && isset($existingPhones[$key]))
                                        @php($existing = $existingPhones[$key])
                                        <div class="mt-2 max-w-52 rounded-lg bg-amber-50 p-2 text-xs text-amber-900">
                                            <p class="font-semibold">Previously registered handset</p>
                                            <p>{{ $existing->displayName() }}</p>
                                            <p>{{ $existing->current_custody->label() }} · {{ $existing->cycles()->count() }} cycle(s)</p>
                                            <a href="{{ route('imei.history', $existing->primaryIdentifier()) }}" target="_blank" rel="noopener" class="font-semibold underline">View history</a>
                                            @if (! $existing->activeCycle && in_array($existing->current_custody, [\App\Enums\Custody::WithCustomer, \App\Enums\Custody::ReturnPendingInspection], true))
                                                <p class="mt-1">Update condition and buying price, then restock.</p>
                                                <label class="mt-2 flex items-start gap-2"><input type="checkbox" wire:model="phoneRows.{{ $key }}.restock" class="rounded border-amber-300">Restock with Save all</label>
                                            @else
                                                <p class="mt-1 font-semibold">Already owned / movement pending. Use Transfers or Returns; another stock cycle cannot be opened.</p>
                                            @endif
                                        </div>
                                    @endif
                                    @if ($field === 'modelName')
                                        <datalist id="models-{{ $key }}">
                                            @foreach ($phoneModels->filter(fn ($model) => mb_strtolower($model->brand?->name ?? '') === mb_strtolower(trim($row['brandName'] ?? ''))) as $model)
                                                <option value="{{ $model->name }}">
                                            @endforeach
                                        </datalist>
                                    @endif
                                </td>
                            @endforeach
                            <td class="min-w-40 space-y-2 px-3 py-2">
                                <button type="button" wire:click="duplicateLine('{{ $key }}')" class="w-full whitespace-nowrap rounded-lg border border-indigo-200 px-3 py-2 text-xs font-semibold text-indigo-700">Duplicate line</button>
                                @if (! $isProduct && isset($existingPhones[$key]) && ! $existingPhones[$key]->activeCycle && in_array($existingPhones[$key]->current_custody, [\App\Enums\Custody::WithCustomer, \App\Enums\Custody::ReturnPendingInspection], true))
                                    <button type="button" wire:click="restockLine('{{ $key }}')" wire:loading.attr="disabled" class="w-full whitespace-nowrap rounded-lg bg-amber-600 px-3 py-2 text-xs font-semibold text-white">Restock line</button>
                                @endif

                                @error($property.'.'.$key.'.form') <p class="text-xs text-rose-600">{{ $message }}</p> @enderror
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p class="text-xs text-slate-500">Scroll across to complete a line. Duplicate keeps product details; phone IMEIs are cleared. All lines save together.</p>
        @if ($isProduct)
            <p class="rounded-lg bg-slate-50 p-3 text-xs text-slate-600">Select an existing SKU to fill its details, or enter a new SKU and product name. Quantity adds to existing stock. Manual stock entry posts to opening equity; use Purchases for supplier payments.</p>
        @endif
        <div class="flex flex-col gap-2 sm:flex-row sm:justify-end">
            <button type="button" wire:click="addLine" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700">+ Add line</button>
            <button type="submit" wire:loading.attr="disabled" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50"><span wire:loading.remove wire:target="saveRows">Save all {{ count($rows) }} line(s)</span><span wire:loading wire:target="saveRows">Saving…</span></button>
        </div>
    </form>
    <datalist id="brand-options">@foreach ($brands as $brand)<option value="{{ $brand->name }}">@endforeach</datalist>
    <datalist id="supplier-options">@foreach ($suppliers as $supplier)<option value="{{ $supplier->name }}">@endforeach</datalist>
    <datalist id="country-options">@foreach ($countries as $countryOption)<option value="{{ $countryOption }}">@endforeach</datalist>
    <datalist id="colour-options">@foreach ($colours as $colourOption)<option value="{{ $colourOption }}">@endforeach</datalist>
    <datalist id="accessory-options">@foreach ($accessoryVariants as $variant)<option value="{{ $variant->sku }}">{{ $variant->product->name }}</option>@endforeach</datalist>
</div>
