<div class="mx-auto max-w-4xl">
    <x-erp.page-header title="Bulk IMEI entry" subtitle="Paste one handset per line from Excel (IMEI 1, optional IMEI 2, optional cost). Every row is validated before anything is booked.">
        <x-slot:actions>
            <a href="{{ route('imports.phones') }}" class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Need models mixed? Use import wizard</a>
        </x-slot:actions>
    </x-erp.page-header>

    @error('form')
        <div class="mb-3 rounded-lg border border-rose-200 bg-rose-50 px-4 py-2 text-sm text-rose-800">{{ $message }}</div>
    @enderror

    <form wire:submit="save" class="space-y-4">
        <x-erp.card title="Batch header">
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="bulk-unit" class="text-xs font-semibold uppercase tracking-wide text-slate-600">Receiving unit</label>
                    <select id="bulk-unit" wire:model="unitId" class="mt-1 w-full rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="">Select unit</option>
                        @foreach ($units as $unit)
                            <option value="{{ $unit->id }}">{{ $unit->name }}</option>
                        @endforeach
                    </select>
                    @error('unitId') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="bulk-date" class="text-xs font-semibold uppercase tracking-wide text-slate-600">Purchase date</label>
                    <input id="bulk-date" type="date" wire:model="purchaseDate" class="mt-1 w-full rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                    @error('purchaseDate') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="bulk-variant" class="text-xs font-semibold uppercase tracking-wide text-slate-600">Model for every row</label>
                    <select id="bulk-variant" wire:model="variantId" class="mt-1 w-full rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="">Select model</option>
                        @foreach ($variants as $variant)
                            <option value="{{ $variant->id }}">{{ $variant->displayName() }}</option>
                        @endforeach
                    </select>
                    @error('variantId') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="bulk-cost" class="text-xs font-semibold uppercase tracking-wide text-slate-600">Default unit cost ({{ $currency }})</label>
                    <input id="bulk-cost" type="text" inputmode="decimal" wire:model="unitCost" class="mt-1 w-full rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                    @error('unitCost') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="bulk-supplier" class="text-xs font-semibold uppercase tracking-wide text-slate-600">Supplier</label>
                    <select id="bulk-supplier" wire:model="supplierId" class="mt-1 w-full rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="">Individual seller / none</option>
                        @foreach ($suppliers as $supplier)
                            <option value="{{ $supplier->id }}">{{ $supplier->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="bulk-notes" class="text-xs font-semibold uppercase tracking-wide text-slate-600">Note</label>
                    <input id="bulk-notes" type="text" wire:model="notes" class="mt-1 w-full rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                </div>
            </div>
        </x-erp.card>

        <x-erp.card title="Paste rows" description="Tab-separated paste from Excel works. Columns: IMEI 1 [tab] IMEI 2 [tab] cost (cost optional).">
            <textarea wire:model.live.debounce.500ms="payload" rows="8" class="w-full rounded-lg border-slate-300 font-mono text-sm focus:border-indigo-500 focus:ring-indigo-500" placeholder="356789011000001&#9;356789011000002&#9;52000"></textarea>
            @error('payload') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
            <div class="mt-2 flex flex-wrap gap-3 text-xs text-slate-600">
                <span class="font-medium text-emerald-700">{{ count($preview['rows']) }} valid row(s)</span>
                <span class="font-medium {{ count($preview['errors']) ? 'text-rose-700' : 'text-slate-500' }}">{{ count($preview['errors']) }} error(s)</span>
            </div>
            @if ($preview['errors'])
                <ul class="mt-2 max-h-40 space-y-1 overflow-y-auto rounded-lg bg-rose-50 p-3 text-xs text-rose-800">
                    @foreach ($preview['errors'] as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            @endif
            @if ($preview['rows'])
                <div class="mt-3 max-h-48 overflow-y-auto rounded-lg border border-slate-200">
                    <table class="min-w-full text-xs">
                        <thead class="bg-slate-50 text-left uppercase tracking-wide text-slate-500">
                            <tr><th class="px-2 py-1.5">IMEI 1</th><th class="px-2 py-1.5">IMEI 2</th><th class="px-2 py-1.5 text-right">Cost</th></tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach (array_slice($preview['rows'], 0, 20) as $row)
                                <tr><td class="px-2 py-1 font-mono">{{ $row['imei1'] }}</td><td class="px-2 py-1 font-mono text-slate-500">{{ $row['imei2'] ?? '—' }}</td><td class="px-2 py-1 text-right tabular-nums">{{ $row['cost'] ?? 'default' }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-erp.card>

        <div class="flex flex-wrap items-center justify-between gap-2">
            <p class="text-xs text-slate-500">
                @if ($canApprove)
                    This books a real purchase and receives stock immediately.
                @else
                    This will save a draft purchase; a manager must receive it.
                @endif
            </p>
            <div class="flex gap-2">
                <a href="{{ route('inventory.index') }}" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">Cancel</a>
                <button type="submit" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700" wire:loading.attr="disabled">
                    <span wire:loading.remove>Book {{ count($preview['rows']) }} handset(s)</span>
                    <span wire:loading>Booking…</span>
                </button>
            </div>
        </div>
    </form>
</div>
