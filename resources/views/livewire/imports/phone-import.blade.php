<div class="mx-auto max-w-6xl">
    <x-erp.page-header title="Import phones from Excel / CSV" subtitle="Upload → map columns → dry run → commit. Invalid rows are never imported silently, and committed rows become a real purchase receipt." />

    @error('commit')
        <div class="mb-3 rounded-lg border border-rose-200 bg-rose-50 px-4 py-2 text-sm text-rose-800">{{ $message }}</div>
    @enderror

    <div class="grid gap-4 lg:grid-cols-3">
        <div class="space-y-4 lg:col-span-2">
            <x-erp.card title="1 · Upload file" description="The first row must be a header row. Maximum 2,000 data rows per import.">
                <input type="file" wire:model="file" accept=".xlsx,.xls,.csv,.txt" class="block w-full rounded-lg border border-slate-300 p-2 text-sm file:mr-3 file:rounded-md file:border-0 file:bg-slate-100 file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-slate-700">
                @error('file') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                <p wire:loading wire:target="file" class="mt-2 text-xs font-medium text-indigo-600">Uploading and reading spreadsheet…</p>

                @if ($batch)
                    <div class="mt-3 rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-600">
                        <p class="font-semibold text-slate-800">{{ $batch->batch_no }} · {{ $batch->original_filename }}</p>
                        <p>Status: {{ $batch->status }} · {{ $totalRows }} data row(s) · {{ $validRows }} valid · {{ $invalidRows }} invalid</p>
                    </div>
                @endif
            </x-erp.card>

            @if ($batch)
                <x-erp.card title="2 · Map columns" description="IMEI 1 and buy price are mandatory. Map model plus brand/storage/colour when a model has several variants. Sold/customer/profit columns are not imported; rows that contain a selling date or customer name are rejected so no phantom stock or fake sale history is created.">
                    <div class="grid gap-3 sm:grid-cols-2">
                        @foreach ($fields as $field => $definition)
                            <div>
                                <label for="map-{{ $field }}" class="text-xs font-semibold uppercase tracking-wide text-slate-600">
                                    {{ $definition['label'] }} @if ($definition['required'])<span class="text-rose-600">*</span>@endif
                                </label>
                                <select id="map-{{ $field }}" wire:model.live="mapping.{{ $field }}" class="mt-1 w-full rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                                    <option value="">— not mapped —</option>
                                    @foreach ($headers as $index => $header)
                                        <option value="{{ $index }}">{{ $header !== '' ? $header : 'Column '.($index + 1) }}</option>
                                    @endforeach
                                </select>
                            </div>
                        @endforeach
                        <div class="sm:col-span-2">
                            <label for="default-variant" class="text-xs font-semibold uppercase tracking-wide text-slate-600">Default model (used when the model column is not mapped)</label>
                            <select id="default-variant" wire:model.live="defaultVariantId" class="mt-1 w-full rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                                <option value="">No default — resolve each row from its model column</option>
                                @foreach ($variants as $variant)
                                    <option value="{{ $variant->id }}">{{ $variant->displayName() }} ({{ $variant->sku }})</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </x-erp.card>

                <x-erp.card title="3 · Dry run" description="Nothing has been saved to stock yet." flush>
                    <div class="flex flex-wrap gap-3 border-b border-slate-100 px-4 py-2 text-xs">
                        <span class="font-semibold text-slate-700">{{ $totalRows }} row(s)</span>
                        <span class="font-semibold text-emerald-700">{{ $validRows }} valid</span>
                        <span class="font-semibold {{ $invalidRows ? 'text-rose-700' : 'text-slate-500' }}">{{ $invalidRows }} invalid</span>
                        <span class="text-slate-500">Showing first {{ count($previewRows) }}</span>
                    </div>
                    <div class="max-h-96 overflow-auto">
                        <table class="min-w-full divide-y divide-slate-200 text-xs">
                            <thead class="bg-slate-50 text-left uppercase tracking-wide text-slate-500">
                                <tr>
                                    <th class="px-3 py-2">Row</th>
                                    <th class="px-3 py-2">IMEI 1</th>
                                    <th class="px-3 py-2">Model resolved</th>
                                    <th class="px-3 py-2">Buying party</th>
                                    <th class="px-3 py-2 text-right">Cost</th>
                                    <th class="px-3 py-2 text-right">Sell</th>
                                    <th class="px-3 py-2">Validation</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @forelse ($previewRows as $row)
                                    <tr wire:key="preview-{{ $row['row'] }}">
                                        <td class="px-3 py-1.5 text-slate-500">{{ $row['row'] }}</td>
                                        <td class="px-3 py-1.5 font-mono text-slate-800">{{ $row['imei1'] ?? '—' }}</td>
                                        <td class="px-3 py-1.5 text-slate-700">{{ $row['variant_label'] ?? 'Unresolved' }}</td>
                                        <td class="px-3 py-1.5 text-slate-700">{{ $row['buying_party'] ?? '—' }}</td>
                                        <td class="px-3 py-1.5 text-right tabular-nums text-slate-700">{{ $row['cost'] !== null ? $currency.number_format((float) $row['cost'], 2) : '—' }}</td>
                                        <td class="px-3 py-1.5 text-right tabular-nums text-slate-700">{{ $row['selling_price'] !== null ? $currency.number_format((float) $row['selling_price'], 2) : '—' }}</td>
                                        <td class="px-3 py-1.5">
                                            @if ($row['errors'] === [])
                                                <span class="font-medium text-emerald-700">Valid</span>
                                            @else
                                                <ul class="list-inside list-disc text-rose-700">
                                                    @foreach ($row['errors'] as $error)
                                                        <li>{{ $error }}</li>
                                                    @endforeach
                                                </ul>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="7" class="px-4 py-8 text-center text-slate-500">No rows parsed. Check the mapping.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </x-erp.card>
            @endif
        </div>

        <div class="space-y-4">
            <x-erp.card title="4 · Commit">
                <div class="space-y-3">
                    <div>
                        <label for="import-unit" class="text-xs font-semibold uppercase tracking-wide text-slate-600">Receiving unit</label>
                        <select id="import-unit" wire:model="unitId" class="mt-1 w-full rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                            <option value="">Select unit</option>
                            @foreach ($units as $unit)
                                <option value="{{ $unit->id }}">{{ $unit->name }}</option>
                            @endforeach
                        </select>
                        @error('unitId') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="import-supplier" class="text-xs font-semibold uppercase tracking-wide text-slate-600">Supplier</label>
                        <select id="import-supplier" wire:model="supplierId" class="mt-1 w-full rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                            <option value="">Individual seller / none</option>
                            @foreach ($suppliers as $supplier)
                                <option value="{{ $supplier->id }}">{{ $supplier->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="import-date" class="text-xs font-semibold uppercase tracking-wide text-slate-600">Purchase date</label>
                        <input id="import-date" type="date" wire:model="purchaseDate" class="mt-1 w-full rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                        @error('purchaseDate') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                    </div>
                    <label class="flex items-start gap-2 text-xs text-slate-700">
                        <input type="checkbox" wire:model="importOnlyValid" class="mt-0.5 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                        <span>Import valid rows only and skip invalid rows (the error report is stored on the batch).</span>
                    </label>
                    <button type="button" wire:click="commit" class="w-full rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 disabled:opacity-50" @disabled(! $batch || $totalRows === 0) wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="commit">Commit import</span>
                        <span wire:loading wire:target="commit">Importing…</span>
                    </button>
                </div>
            </x-erp.card>

            <div class="rounded-xl border border-dashed border-slate-300 bg-white p-4 text-xs leading-relaxed text-slate-600">
                <p class="font-semibold text-slate-800">What this import does not do</p>
                <ul class="mt-2 space-y-1">
                    <li>• It never creates historical sales or payment journals from spreadsheet rows.</li>
                    <li>• Invalid or duplicate IMEIs are rejected, not repaired automatically.</li>
                    <li>• Every imported handset gets a live acquisition cycle at the row's buy price.</li>
                    <li>• The import batch, mapping and rejected rows are retained for audit.</li>
                </ul>
            </div>
        </div>
    </div>
</div>
