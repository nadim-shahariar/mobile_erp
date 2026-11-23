<div class="mx-auto max-w-3xl">
    <section class="mb-4 rounded-xl bg-white p-4 shadow"><h2 class="font-semibold">Optional purchasing features</h2><label class="mt-2 flex items-center gap-2"><input type="checkbox" wire:model="supplierAccounts"> Supplier purchase totals, paid / partial / due and due payment</label><label class="mt-2 flex items-center gap-2"><input type="checkbox" wire:model="purchaseLots"> Supplier lot orders, partial receipt and pending cancellations</label><p class="mt-2 text-sm text-slate-500">Use Save settings below to apply. Turning off a feature hides its tools; saved records are retained.</p></section>

    <x-erp.page-header title="Settings" subtitle="Shop branding, cash memo design and business preferences. Changes are saved for this installation." />

    <form wire:submit="save" class="space-y-4">
        <x-erp.card title="Units and default workplace" description="New work starts in the default unit. You can switch units from the header when needed.">
            <label for="default-unit" class="text-sm font-semibold">Default unit</label>
            <select id="default-unit" wire:model="defaultUnitId" class="mt-1 w-full rounded-lg border-slate-300">
                @foreach($businessUnits->where('is_active', true) as $businessUnit)<option value="{{ $businessUnit->id }}">{{ $unitDetails[$businessUnit->id]['name'] ?? $businessUnit->name }}</option>@endforeach
            </select>
            @error('defaultUnitId')<p class="text-sm text-rose-600">{{ $message }}</p>@enderror
            <div class="mt-4 space-y-4">
                @foreach($businessUnits as $businessUnit)
                <div wire:key="settings-unit-{{ $businessUnit->id }}" class="rounded-lg border border-slate-200 p-3">
                    <p class="mb-2 text-xs text-slate-500">{{ $businessUnit->code }}{{ $businessUnit->is_active ? '' : ' · Inactive' }}</p>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <label class="text-sm">Unit name<input wire:model="unitDetails.{{ $businessUnit->id }}.name" class="mt-1 w-full rounded-lg border-slate-300">@error('unitDetails.'.$businessUnit->id.'.name')<p class="text-rose-600">{{ $message }}</p>@enderror</label>
                        <label class="text-sm">Phone<input wire:model="unitDetails.{{ $businessUnit->id }}.phone" class="mt-1 w-full rounded-lg border-slate-300">@error('unitDetails.'.$businessUnit->id.'.phone')<p class="text-rose-600">{{ $message }}</p>@enderror</label>
                        <label class="text-sm sm:col-span-2">Address<input wire:model="unitDetails.{{ $businessUnit->id }}.address" class="mt-1 w-full rounded-lg border-slate-300">@error('unitDetails.'.$businessUnit->id.'.address')<p class="text-rose-600">{{ $message }}</p>@enderror</label>
                    </div>
                </div>
                @endforeach
            </div>
        </x-erp.card>
        <x-erp.card title="Business identity" description="Used across the software and on customer invoices.">
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <label for="settings-name" class="text-xs font-semibold uppercase tracking-wide text-slate-600">Business name</label>
                    <input id="settings-name" type="text" wire:model="companyName" class="mt-1 w-full rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                    @error('companyName') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
                <div class="sm:col-span-2">
                    <label for="settings-address" class="text-xs font-semibold uppercase tracking-wide text-slate-600">Address</label>
                    <input id="settings-address" type="text" wire:model="companyAddress" class="mt-1 w-full rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                </div>
                <div>
                    <label for="settings-phone" class="text-xs font-semibold uppercase tracking-wide text-slate-600">Phone</label>
                    <input id="settings-phone" type="text" wire:model="companyPhone" class="mt-1 w-full rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                </div>
                <div>
                    <label for="settings-email" class="text-xs font-semibold uppercase tracking-wide text-slate-600">Email</label>
                    <input id="settings-email" type="email" wire:model="companyEmail" class="mt-1 w-full rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                    @error('companyEmail') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
            </div>
        </x-erp.card>

        <x-erp.card title="Cash memo / invoice design" description="Customize this installation for any shop. Save changes, then open the preview.">
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2"><label for="shop-logo" class="text-xs font-semibold text-slate-600">Shop logo (PNG, JPG or WebP, up to 2 MB)</label><input id="shop-logo" type="file" wire:model="logoUpload" accept="image/png,image/jpeg,image/webp" class="mt-1 block w-full text-sm">@error('logoUpload')<p class="text-xs text-rose-600">{{ $message }}</p>@enderror
                    @if ($logoAvailable)<img src="{{ route('branding.logo', ['v' => substr(hash('sha256', (string) config('erp.company.logo_path')), 0, 12)]) }}" alt="Current shop logo" class="mt-3 max-h-20 max-w-48 object-contain"><label class="mt-2 flex gap-2 text-xs"><input type="checkbox" wire:model="removeLogo">Remove current logo</label>@endif
                </div>
                @foreach (['invoiceTitle' => 'Memo title', 'invoiceTagline' => 'Tagline / slogan', 'brandSubtitle' => 'Brand second line (optional)', 'termsHeading' => 'Warranty / terms heading', 'customerSignature' => 'Customer signature label', 'authorizedSignature' => 'Shop signature label'] as $field => $label)
                    <div><label for="setting-{{ $field }}" class="text-xs font-semibold text-slate-600">{{ $label }}</label><input id="setting-{{ $field }}" wire:model="{{ $field }}" type="text" class="mt-1 w-full rounded-lg border-slate-300 text-sm">@error($field)<p class="text-xs text-rose-600">{{ $message }}</p>@enderror</div>
                @endforeach
                <div><label for="invoice-accent" class="text-xs font-semibold text-slate-600">Invoice accent colour</label><input id="invoice-accent" type="color" wire:model="invoiceAccent" class="mt-1 h-10 w-full rounded-lg border-slate-300">@error('invoiceAccent')<p class="text-xs text-rose-600">{{ $message }}</p>@enderror</div>
                <div class="sm:col-span-2"><label for="invoice-terms" class="text-xs font-semibold text-slate-600">Warranty, replacement and exchange terms</label><textarea id="invoice-terms" wire:model="invoiceTerms" rows="12" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></textarea><p class="mt-1 text-xs text-slate-500">One term per line. Use “Heading: details” for bold headings. {shop_name} is replaced with your business name.</p>@error('invoiceTerms')<p class="text-xs text-rose-600">{{ $message }}</p>@enderror</div>
                <div class="sm:col-span-2"><label for="invoice-footer" class="text-xs font-semibold text-slate-600">Invoice footer note</label><textarea id="invoice-footer" wire:model="invoiceFooter" rows="2" class="mt-1 w-full rounded-lg border-slate-300 text-sm"></textarea>@error('invoiceFooter')<p class="text-xs text-rose-600">{{ $message }}</p>@enderror</div>
            </div>
            <a href="{{ route('invoices.preview') }}" target="_blank" rel="noopener" class="mt-4 inline-block rounded-lg border border-indigo-300 px-3 py-2 text-sm font-semibold text-indigo-700">Preview saved cash memo</a>
        </x-erp.card>

        <x-erp.card title="Locale and money">
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="settings-timezone" class="text-xs font-semibold uppercase tracking-wide text-slate-600">Business timezone</label>
                    <select id="settings-timezone" wire:model="timezone" class="mt-1 w-full rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                        @foreach ($timezones as $zone)
                            <option value="{{ $zone }}">{{ $zone }}</option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-[11px] text-slate-500">Timestamps are stored in UTC; business-day boundaries use this zone.</p>
                    @error('timezone') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="settings-currency" class="text-xs font-semibold uppercase tracking-wide text-slate-600">Currency symbol</label>
                    <input id="settings-currency" type="text" wire:model="currencySymbol" class="mt-1 w-full rounded-lg border-slate-300 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                    @error('currencySymbol') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
            </div>
        </x-erp.card>

        <x-erp.card title="Policy defaults">
            <div class="space-y-3">
                <label class="flex items-start gap-2 text-sm text-slate-700">
                    <input type="checkbox" wire:model="requireImeiLuhn" class="mt-0.5 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                    <span>Require valid 15-digit IMEIs (Luhn). Turning this off makes every IMEI "legacy/unverified" unless explicitly overridden — not recommended.</span>
                </label>
                <label class="flex items-start gap-2 text-sm text-slate-700">
                    <input type="checkbox" wire:model="allowNegativeAccessoryStock" class="mt-0.5 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                    <span>Allow negative accessory stock (back-order). Off by default: stock cannot go below zero.</span>
                </label>
                <label class="flex items-start gap-2 text-sm text-slate-700">
                    <input type="checkbox" wire:model="guestExpenseRequiresName" class="mt-0.5 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                    <span>Require a guest name for hospitality expenses (applies to the expense module).</span>
                </label>
                <label class="flex items-start gap-2 text-sm text-slate-700">
                    <input type="checkbox" wire:model="lowStockAlerts" class="mt-0.5 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                    <span>Show low-stock accessory alerts on the control center.</span>
                </label>
            </div>
        </x-erp.card>

        <div class="flex justify-end">
            <button type="submit" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">Save settings</button>
        </div>
    </form>

    <x-erp.card class="mt-5" title="Build information">
        <dl class="grid gap-2 text-sm sm:grid-cols-2">
            <div><dt class="text-xs uppercase tracking-wide text-slate-500">Application</dt><dd class="text-slate-800">Mobile Shop ERP · Phase 1</dd></div>
            <div><dt class="text-xs uppercase tracking-wide text-slate-500">Laravel</dt><dd class="text-slate-800">{{ app()->version() }}</dd></div>
            <div><dt class="text-xs uppercase tracking-wide text-slate-500">PHP</dt><dd class="text-slate-800">{{ PHP_VERSION }}</dd></div>
            <div><dt class="text-xs uppercase tracking-wide text-slate-500">Storage timezone</dt><dd class="text-slate-800">UTC (business: {{ config('erp.timezone') }})</dd></div>
        </dl>
    </x-erp.card>
</div>
