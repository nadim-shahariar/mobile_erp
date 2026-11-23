@php($invalid = $errors->has($binding))
@if ($type === 'checkbox')
    <input id="{{ $binding }}" type="checkbox" wire:model="{{ $binding }}" aria-label="{{ $label }}" class="mt-2 rounded border-slate-300 text-indigo-600">
@elseif ($type === 'unit' || $type === 'category')
    <select id="{{ $binding }}" wire:model="{{ $binding }}" aria-label="{{ $label }}" aria-invalid="{{ $invalid ? 'true' : 'false' }}" class="min-w-36 w-full rounded-lg text-sm {{ $invalid ? 'border-rose-500' : 'border-slate-300' }}">
        @if ($type === 'unit')
            <option value="">Select branch</option>
            @foreach ($units as $unit)<option value="{{ $unit->id }}">{{ $unit->name }}</option>@endforeach
        @else
            <option value="PRE_OWNED">Pre owned</option><option value="NEW">New</option>
        @endif
    </select>
@else
    <input id="{{ $binding }}" type="{{ $type === 'decimal' ? 'text' : $type }}"
        @if (in_array($field, ['productSku', 'brandName', 'primaryImei', 'secondaryImei'])) wire:model.blur="{{ $binding }}" @else wire:model="{{ $binding }}" @endif
        @if ($list) list="{{ $list }}" @endif
        @if ($type === 'decimal') inputmode="decimal" @endif
        @if ($type === 'number') min="{{ $field === 'productStock' ? 1 : 0 }}" @endif
        @if ($field === 'batteryHealth') max="100" @endif
        @if (in_array($field, ['primaryImei', 'secondaryImei'])) inputmode="numeric" maxlength="32" @endif
        aria-label="{{ $label }}" aria-invalid="{{ $invalid ? 'true' : 'false' }}"
        placeholder="{{ $list ? 'Select or type new' : $label }}"
        class="{{ in_array($field, ['brandName', 'modelName', 'buyingParty', 'productName', 'productSku', 'primaryImei', 'secondaryImei']) ? 'min-w-44' : 'min-w-32' }} w-full rounded-lg text-sm {{ $invalid ? 'border-rose-500' : 'border-slate-300' }}">
@endif
@error($binding) <p class="mt-1 max-w-48 text-xs text-rose-600">{{ $message }}</p> @enderror
