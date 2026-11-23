<?php

declare(strict_types=1);

namespace App\Livewire\Settings;

use App\Models\BusinessUnit;
use App\Models\Setting;
use App\Support\AuditLogger;
use App\Support\UnitContext;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

#[Layout('layouts.app')]
class SettingsPage extends Component
{
    use WithFileUploads;

    public string $defaultUnitId = '';

    public array $unitDetails = [];

    public $logoUpload;

    public bool $removeLogo = false;

    public string $invoiceTitle = '';

    public string $invoiceTagline = '';

    public string $brandSubtitle = '';

    public string $invoiceAccent = '#008895';

    public string $termsHeading = '';

    public string $invoiceTerms = '';

    public string $customerSignature = '';

    public string $authorizedSignature = '';

    public string $invoiceFooter = '';

    private const INVOICE_FIELDS = [
        'invoiceTitle' => 'invoice.title', 'invoiceTagline' => 'invoice.tagline',
        'brandSubtitle' => 'invoice.brand_subtitle', 'invoiceAccent' => 'invoice.accent_color',
        'termsHeading' => 'invoice.terms_heading', 'invoiceTerms' => 'invoice.terms',
        'customerSignature' => 'invoice.customer_signature', 'authorizedSignature' => 'invoice.authorized_signature',
        'invoiceFooter' => 'invoice.footer_note',
    ];

    public string $companyName = '';

    public string $companyAddress = '';

    public string $companyPhone = '';

    public string $companyEmail = '';

    public string $timezone = 'Asia/Dhaka';

    public string $currencySymbol = '৳';

    public bool $allowNegativeAccessoryStock = false;

    public bool $guestExpenseRequiresName = false;

    public bool $lowStockAlerts = true;

    public bool $supplierAccounts = true;

    public bool $purchaseLots = true;

    public bool $requireImeiLuhn = true;

    public function mount(): void
    {
        $this->authorize('settings.manage');

        $this->defaultUnitId = (string) (Setting::get('units.default_id') ?? app(UnitContext::class)->current(auth()->user())?->id ?? '');
        $this->unitDetails = BusinessUnit::whereIn('id', auth()->user()->accessibleUnitIds())->ordered()->get()
            ->mapWithKeys(fn ($unit) => [$unit->id => $unit->only('name', 'address', 'phone')])->all();
        $this->supplierAccounts = (bool) Setting::get('features.supplier_accounts', true);
        $this->purchaseLots = (bool) Setting::get('features.purchase_lots', true);
        foreach (self::INVOICE_FIELDS as $field => $key) {
            $this->{$field} = (string) Setting::get($key, config('erp.'.$key));
        }
        $this->companyName = (string) Setting::get('company.name', config('erp.company.name'));
        $this->companyAddress = (string) Setting::get('company.address', config('erp.company.address'));
        $this->companyPhone = (string) Setting::get('company.phone', config('erp.company.phone'));
        $this->companyEmail = (string) Setting::get('company.email', config('erp.company.email'));
        $this->timezone = (string) Setting::get('timezone', config('erp.timezone'));
        $this->currencySymbol = (string) Setting::get('currency.symbol', config('erp.currency.symbol'));
        $this->allowNegativeAccessoryStock = (bool) Setting::get('policy.allow_negative_accessory_stock', config('erp.policy.allow_negative_accessory_stock'));
        $this->guestExpenseRequiresName = (bool) Setting::get('policy.guest_expense_requires_name', config('erp.policy.guest_expense_requires_name'));
        $this->lowStockAlerts = (bool) Setting::get('policy.low_stock_alerts', config('erp.policy.low_stock_alerts'));
        $this->requireImeiLuhn = (bool) Setting::get('imei.require_luhn', config('erp.imei.require_luhn'));
    }

    public function save(): void
    {
        $this->authorize('settings.manage');

        $allowedUnits = BusinessUnit::whereIn('id', auth()->user()->accessibleUnitIds())->pluck('id')->all();
        abort_unless(count(array_diff(array_keys($this->unitDetails), $allowedUnits)) === 0, 403);
        $validated = $this->validate([
            'defaultUnitId' => ['required', 'integer', Rule::in($allowedUnits), Rule::exists('business_units', 'id')->where('is_active', true)],
            'unitDetails' => ['array'],
            'unitDetails.*.name' => ['required', 'string', 'max:180'],
            'unitDetails.*.address' => ['nullable', 'string', 'max:255'],
            'unitDetails.*.phone' => ['nullable', 'string', 'max:120'],
            'logoUpload' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
            'removeLogo' => ['boolean'],
            'invoiceTitle' => ['required', 'string', 'max:60'],
            'invoiceTagline' => ['nullable', 'string', 'max:180'],
            'brandSubtitle' => ['nullable', 'string', 'max:80'],
            'invoiceAccent' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'termsHeading' => ['nullable', 'string', 'max:100'],
            'invoiceTerms' => ['nullable', 'string', 'max:8000'],
            'customerSignature' => ['nullable', 'string', 'max:80'],
            'authorizedSignature' => ['nullable', 'string', 'max:80'],
            'invoiceFooter' => ['nullable', 'string', 'max:300'],
            'companyName' => ['required', 'string', 'max:180'],
            'companyAddress' => ['nullable', 'string', 'max:255'],
            'companyPhone' => ['nullable', 'string', 'max:120'],
            'companyEmail' => ['nullable', 'email', 'max:190'],
            'timezone' => ['required', 'timezone'],
            'currencySymbol' => ['required', 'string', 'max:8'],
            'allowNegativeAccessoryStock' => ['boolean'],
            'guestExpenseRequiresName' => ['boolean'],
            'lowStockAlerts' => ['boolean'],
            'requireImeiLuhn' => ['boolean'],
            'supplierAccounts' => ['boolean'],
            'purchaseLots' => ['boolean'],
        ]);

        $pairs = [
            'units.default_id' => ['value' => (int) $validated['defaultUnitId'], 'group' => 'units'],
            'features.supplier_accounts' => ['value' => $validated['supplierAccounts'], 'group' => 'features'],
            'features.purchase_lots' => ['value' => $validated['purchaseLots'], 'group' => 'features'],
            'company.name' => ['value' => $validated['companyName'], 'group' => 'company'],
            'company.address' => ['value' => $validated['companyAddress'] ?: '', 'group' => 'company'],
            'company.phone' => ['value' => $validated['companyPhone'] ?: '', 'group' => 'company'],
            'company.email' => ['value' => $validated['companyEmail'] ?: '', 'group' => 'company'],
            'timezone' => ['value' => $validated['timezone'], 'group' => 'locale'],
            'currency.symbol' => ['value' => $validated['currencySymbol'], 'group' => 'locale'],
            'policy.allow_negative_accessory_stock' => ['value' => $validated['allowNegativeAccessoryStock'], 'group' => 'policy'],
            'policy.guest_expense_requires_name' => ['value' => $validated['guestExpenseRequiresName'], 'group' => 'policy'],
            'policy.low_stock_alerts' => ['value' => $validated['lowStockAlerts'], 'group' => 'policy'],
            'imei.require_luhn' => ['value' => $validated['requireImeiLuhn'], 'group' => 'imei'],
        ];

        foreach (self::INVOICE_FIELDS as $field => $key) {
            $pairs[$key] = ['value' => $validated[$field] ?? '', 'group' => 'invoice'];
        }
        if ($this->logoUpload) {
            $pairs['company.logo_path'] = ['value' => $this->logoUpload->store('branding/logos', 'public'), 'group' => 'company'];
        } elseif ($this->removeLogo) {
            $pairs['company.logo_path'] = ['value' => '', 'group' => 'company'];
        }
        DB::transaction(function () use ($pairs, $validated, $allowedUnits) {
            foreach ($validated['unitDetails'] as $id => $details) {
                $unit = BusinessUnit::whereIn('id', $allowedUnits)->lockForUpdate()->findOrFail($id);
                $old = $unit->only('name', 'address', 'phone');
                $attributes = ['name' => $details['name'], 'address' => $details['address'] ?? null, 'phone' => $details['phone'] ?? null];
                $unit->update($attributes);
                if ($old !== $attributes) {
                    AuditLogger::record('business_unit.updated', $unit, $old, $attributes, null, $unit->id, auth()->user());
                }
            }
            foreach ($pairs as $key => $payload) {
                Setting::put($key, $payload['value'], $payload['group'], auth()->id());
            }
        });
        foreach ($pairs as $key => $payload) {
            config(['erp.'.$key => $payload['value']]);
        }
        unset($validated['logoUpload']);
        $this->logoUpload = null;
        $this->removeLogo = false;

        AuditLogger::record('settings.updated', null, [], $validated, null, null, auth()->user());

        session()->flash('success', 'Settings saved. They apply to every new request.');
    }

    public function render(): View
    {
        return view('livewire.settings.page', [
            'businessUnits' => BusinessUnit::whereIn('id', auth()->user()->accessibleUnitIds())->ordered()->get(),
            'logoAvailable' => filled(config('erp.company.logo_path')),
            'timezones' => ['Asia/Dhaka', 'Asia/Kolkata', 'Asia/Dubai', 'Asia/Singapore', 'UTC'],
        ]);
    }
}
