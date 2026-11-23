<div class="mx-auto max-w-4xl">
    <x-erp.page-header title="Request unit transfer" subtitle="Stock movement is two-step: request reserves the handsets, dispatch puts them in transit, receipt lands them in the destination unit.">
        <x-slot:actions>
            <a href="{{ route('transfers.index') }}" class="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Back</a>
        </x-slot:actions>
    </x-erp.page-header>

    @error('form')
        <div class="mb-3 rounded-lg border border-rose-200 bg-rose-50 px-4 py-2 text-sm text-rose-800">{{ $message }}</div>
    @enderror

    <form wire:submit="save" class="space-y-4">
        <x-erp.card title="Route">
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="transfer-from" class="text-xs font-semibold uppercase tracking-wide text-slate-600">From unit</label>
                    <select id="transfer-from" wire:model.live="fromUnitId" class="mt-1 w-full rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="">Select source</option>
                        @foreach ($units as $unit)
                            <option value="{{ $unit->id }}">{{ $unit->name }}</option>
                        @endforeach
                    </select>
                    @error('fromUnitId') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="transfer-to" class="text-xs font-semibold uppercase tracking-wide text-slate-600">To unit</label>
                    <select id="transfer-to" wire:model="toUnitId" class="mt-1 w-full rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="">Select destination</option>
                        @foreach ($units as $unit)
                            @if ((string) $unit->id !== $fromUnitId)
                                <option value="{{ $unit->id }}">{{ $unit->name }}</option>
                            @endif
                        @endforeach
                    </select>
                    @error('toUnitId') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
            </div>
        </x-erp.card>

        <x-erp.card title="Handsets" description="Only available handsets in the source unit can be selected.">
            <div class="flex flex-wrap gap-2">
                <input type="search" wire:model.live.debounce.300ms="phoneSearch" wire:keydown.enter.prevent="addPhoneByImei" placeholder="Scan or type IMEI…" class="min-w-[240px] flex-1 rounded-lg border-slate-300 font-mono text-sm focus:border-indigo-500 focus:ring-indigo-500" aria-label="Search IMEI">
                <button type="button" wire:click="addPhoneByImei" class="rounded-lg bg-slate-900 px-3 py-2 text-sm font-semibold text-white hover:bg-slate-800">Add</button>
            </div>
            @error('phoneSearch') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
            @error('selectedUnitIds') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror

            @if ($searchResults->isNotEmpty())
                <ul class="mt-2 divide-y divide-slate-100 rounded-lg border border-slate-200">
                    @foreach ($searchResults as $result)
                        <li class="flex items-center justify-between gap-2 px-3 py-2 text-sm">
                            <div>
                                <p class="font-medium text-slate-800">{{ $result->displayName() }}</p>
                                <p class="font-mono text-xs text-slate-500">{{ $result->primary_imei }}</p>
                            </div>
                            <button type="button" wire:click="addPhone({{ $result->id }})" class="rounded border border-slate-300 px-2 py-1 text-xs font-medium text-slate-700 hover:bg-slate-100">Add</button>
                        </li>
                    @endforeach
                </ul>
            @endif

            @if ($selected->isEmpty())
                <p class="mt-3 text-xs text-slate-500">No handsets selected yet.</p>
            @else
                <ul class="mt-3 divide-y divide-slate-100 rounded-lg border border-slate-200">
                    @foreach ($selected as $unit)
                        <li class="flex items-center justify-between gap-2 px-3 py-2">
                            <div>
                                <p class="text-sm font-medium text-slate-800">{{ $unit->displayName() }}</p>
                                <p class="font-mono text-xs text-slate-500">{{ $unit->primary_imei }} · {{ $unit->currentUnit?->name }}</p>
                            </div>
                            <button type="button" wire:click="removePhone({{ $unit->id }})" class="text-xs font-medium text-rose-600 hover:text-rose-700">Remove</button>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-erp.card>

        <x-erp.card title="Accessories" description="Optional quantity lines. Reserved on request, moved on dispatch.">
            <x-slot:actions>
                <button type="button" wire:click="addAccessoryLine" class="rounded-lg bg-slate-100 px-2.5 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-200">+ Accessory line</button>
            </x-slot:actions>

            @forelse ($accessoryLines as $index => $line)
                <div class="mb-2 grid gap-2 sm:grid-cols-3" wire:key="acc-{{ $index }}">
                    <select wire:model="accessoryLines.{{ $index }}.product_variant_id" class="rounded-lg border-slate-300 text-sm sm:col-span-2 focus:border-indigo-500 focus:ring-indigo-500" aria-label="Accessory">
                        <option value="">Select accessory…</option>
                        @foreach ($accessoryBalances as $balance)
                            <option value="{{ $balance->product_variant_id }}">{{ $balance->variant?->displayName() }} ({{ $balance->qty_on_hand }} on hand)</option>
                        @endforeach
                    </select>
                    <div class="flex gap-2">
                        <input type="number" min="1" wire:model="accessoryLines.{{ $index }}.quantity" class="w-24 rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500" aria-label="Quantity">
                        <button type="button" wire:click="removeAccessoryLine({{ $index }})" class="rounded-lg border border-slate-300 px-2 text-xs font-medium text-slate-600 hover:bg-slate-50">Remove</button>
                    </div>
                    @error("accessoryLines.{$index}.product_variant_id") <p class="text-xs text-rose-600 sm:col-span-3">{{ $message }}</p> @enderror
                    @error("accessoryLines.{$index}.quantity") <p class="text-xs text-rose-600 sm:col-span-3">{{ $message }}</p> @enderror
                </div>
            @empty
                <p class="text-xs text-slate-500">No accessory lines.</p>
            @endforelse
        </x-erp.card>

        <x-erp.card title="Dispatch and note">
            <label for="transfer-notes" class="text-xs font-semibold uppercase tracking-wide text-slate-600">Note</label>
            <textarea id="transfer-notes" wire:model="notes" rows="2" class="mt-1 w-full rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500" placeholder="Reason for the movement, courier, etc."></textarea>
            @error('notes') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror

            @if ($canApprove)
                <label class="mt-3 flex items-start gap-2 text-sm text-slate-700">
                    <input type="checkbox" wire:model="dispatchNow" class="mt-0.5 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                    <span>Dispatch immediately (I approve this movement)</span>
                </label>
            @else
                <p class="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800">You may request the transfer. A manager with <span class="font-mono">stock.transfer.approve</span> will dispatch it.</p>
            @endif

            <div class="mt-4 flex justify-end gap-2">
                <a href="{{ route('transfers.index') }}" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">Cancel</a>
                <button type="submit" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700" wire:loading.attr="disabled">
                    <span wire:loading.remove>Save transfer</span>
                    <span wire:loading>Saving…</span>
                </button>
            </div>
        </x-erp.card>
    </form>
</div>
