@if ($exchangeModalOpen)
    <div class="fixed inset-0 z-50 overflow-y-auto bg-slate-900/60 p-4 sm:p-8" role="dialog" aria-modal="true" aria-labelledby="exchange-title">
        <form wire:submit="useExchange" class="mx-auto max-w-5xl space-y-4 rounded-xl bg-white p-5 shadow-xl">
            <h2 id="exchange-title" class="text-lg font-semibold text-slate-900">Exchange · incoming product</h2>
            <p class="text-sm text-slate-500">Enter what the customer is giving us. Save with the outgoing sale to receive this product into stock.</p>
            @error('exchange')<p role="alert" class="rounded-lg bg-rose-50 p-3 text-sm text-rose-700">{{ $message }}</p>@enderror
            @error('sale')<p class="text-sm text-rose-700">{{ $message }}</p>@enderror
            <div><label for="exchange-kind" class="text-xs font-semibold text-slate-600">Product type</label><select id="exchange-kind" wire:model.live="incoming.kind" class="mt-1 w-full rounded-lg border-slate-300 sm:w-64"><option value="PHONE">Phone / IMEI</option><option value="ACCESSORY">Product / accessory (1 unit)</option></select></div>
            @php
                $exchangeFields = $incoming['kind'] === 'PHONE' ? [
                    'primaryImei' => ['IMEI 1', 'text'], 'secondaryImei' => ['IMEI 2', 'text'],
                    'brandName' => ['Brand', 'text', 'exchange-brand-options'],
                    'modelName' => ['Model / number', 'text', 'exchange-model-options'],
                    'country' => ['Variant / country', 'text', 'exchange-country-options'],
                    'colour' => ['Colour', 'text', 'exchange-colour-options'],
                    'storage' => ['Storage', 'text'], 'ram' => ['RAM', 'text'],
                    'batteryHealth' => ['Battery health %', 'number'], 'cycleCount' => ['Cycle count', 'number'],
                    'boxKit' => ['Box / kit', 'text'], 'conditionGrade' => ['Condition grade', 'text'],
                ] : ['productSku' => ['SKU / barcode', 'text', 'exchange-sku-options'], 'productName' => ['Product name', 'text']];
                $exchangeFields += ['buyingPrice' => ['Accepted buying value ('.$currency.')', 'decimal'], 'askingPrice' => ['Asking selling price ('.$currency.')', 'decimal'], 'statusNote' => ['Status reason', 'text'], 'notes' => ['Notes', 'text']];
            @endphp
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ($exchangeFields as $field => [$label, $type])
                    <div wire:key="exchange-field-{{ $field }}"><label for="exchange-{{ $field }}" class="text-xs font-semibold text-slate-600">{{ $label }}</label>
                        <input id="exchange-{{ $field }}" type="{{ $type === 'decimal' ? 'text' : $type }}"
                            @if (in_array($field, ['primaryImei', 'secondaryImei', 'productSku', 'brandName', 'buyingPrice'])) wire:model.blur="incoming.{{ $field }}" @else wire:model="incoming.{{ $field }}" @endif
                            @if (isset($exchangeFields[$field][2])) list="{{ $exchangeFields[$field][2] }}" @endif
                            @if ($type === 'decimal') inputmode="decimal" @endif
                            @if ($type === 'number') min="0" @endif
                            @if ($field === 'batteryHealth') max="100" @endif
                            class="mt-1 w-full rounded-lg border-slate-300 text-sm">
                        @error('incoming.'.$field)<p class="text-xs text-rose-600">{{ $message }}</p>@enderror
                    </div>
                @endforeach
                <div><label for="exchange-category" class="text-xs font-semibold text-slate-600">Category</label><select id="exchange-category" wire:model="incoming.category" class="mt-1 w-full rounded-lg border-slate-300 text-sm"><option value="PRE_OWNED">Pre owned</option><option value="NEW">New</option></select></div>
            </div>
            @if ($incomingMatch && $incoming['kind'] === 'PHONE')
                <div class="rounded-lg bg-amber-50 p-3 text-sm text-amber-900">
                    <p class="font-semibold">Previously registered: {{ $incomingMatch->displayName() }}</p>
                    <p>{{ $incomingMatch->current_custody->label() }} · <a target="_blank" rel="noopener" href="{{ route('imei.history', $incomingMatch->primaryIdentifier()) }}" class="underline">View full history</a></p>
                    @if ($incomingMatch->activeCycle)<p>This phone is still owned. Resolve its existing stock / transfer before accepting it again.</p>@else<p>Its earlier history will remain. Update battery health or other details before receiving it again.</p>@endif
                </div>
            @endif
            <label class="flex items-center gap-2 rounded-lg bg-green-50 p-3 text-sm font-semibold text-green-800"><input type="checkbox" wire:model="incoming.inStock" class="rounded border-green-300">In stock · receive into {{ $unit?->name }} when exchange is posted</label>
            @error('incoming.inStock')<p class="text-xs text-rose-600">Confirm receipt into stock.</p>@enderror
            <p class="text-xs text-slate-500">The accepted buying value becomes this product’s new cost basis. No stock or payment is posted until you complete the exchange.</p>
            <div class="flex justify-end gap-2"><button type="button" wire:click="$set('exchangeModalOpen', false)" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold">Cancel</button><button type="submit" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white">Use in exchange</button></div>
            <datalist id="exchange-brand-options">@foreach ($brands as $brand)<option value="{{ $brand->name }}">@endforeach</datalist>
            <datalist id="exchange-model-options">@foreach ($exchangeModels as $model)<option value="{{ $model->name }}">@endforeach</datalist>
            <datalist id="exchange-country-options">@foreach ($exchangeCountries as $country)<option value="{{ $country }}">@endforeach</datalist>
            <datalist id="exchange-colour-options">@foreach ($exchangeColours as $colour)<option value="{{ $colour }}">@endforeach</datalist>
            <datalist id="exchange-sku-options">@foreach ($exchangeAccessories as $variant)<option value="{{ $variant->sku }}">@endforeach</datalist>
        </form>
    </div>
@endif
