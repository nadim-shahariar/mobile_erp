<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Setting;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/** Applies the user's saved UI language, defaulting to English. */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->session()->get('locale');

        if ($locale === null && $request->user() !== null) {
            $locale = $request->user()->locale;
        }

        if (is_string($locale) && array_key_exists($locale, (array) config('erp.locales'))) {
            App::setLocale($locale);
        }

        $this->applyPersistedSettings();

        return $next($request);
    }

    /**
     * Overlay database-backed settings on top of config defaults. Wrapped in a
     * guard so fresh installs (before migrations) and console commands never
     * fail because the settings table does not exist yet.
     */
    private function applyPersistedSettings(): void
    {
        try {
            Setting::flushMemo();
            $overrides = [
                'company.logo_path' => 'erp.company.logo_path',
                'invoice.title' => 'erp.invoice.title',
                'invoice.tagline' => 'erp.invoice.tagline',
                'invoice.brand_subtitle' => 'erp.invoice.brand_subtitle',
                'invoice.accent_color' => 'erp.invoice.accent_color',
                'invoice.terms_heading' => 'erp.invoice.terms_heading',
                'invoice.terms' => 'erp.invoice.terms',
                'invoice.customer_signature' => 'erp.invoice.customer_signature',
                'invoice.authorized_signature' => 'erp.invoice.authorized_signature',
                'invoice.footer_note' => 'erp.invoice.footer_note',
                'company.name' => 'erp.company.name',
                'company.address' => 'erp.company.address',
                'company.phone' => 'erp.company.phone',
                'company.email' => 'erp.company.email',
                'timezone' => 'erp.timezone',
                'currency.symbol' => 'erp.currency.symbol',
                'policy.allow_negative_accessory_stock' => 'erp.policy.allow_negative_accessory_stock',
                'policy.guest_expense_requires_name' => 'erp.policy.guest_expense_requires_name',
                'policy.low_stock_alerts' => 'erp.policy.low_stock_alerts',
                'imei.require_luhn' => 'erp.imei.require_luhn',
            ];

            foreach ($overrides as $settingKey => $configKey) {
                $value = Setting::get($settingKey);

                if ($value !== null) {
                    config([$configKey => $value]);
                }
            }
        } catch (Throwable) {
            // Settings table not migrated yet or unavailable: use config defaults.
        }
    }
}
