<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Business identity
    |--------------------------------------------------------------------------
    | Values are used on printed documents. The owner must replace them from
    | the Settings screen; nothing here is a real business fact.
    */
    'company' => [
        'name' => env('ERP_COMPANY_NAME', 'Mobile Shop ERP'),
        'address' => env('ERP_COMPANY_ADDRESS', ''),
        'phone' => env('ERP_COMPANY_PHONE', ''),
        'email' => env('ERP_COMPANY_EMAIL', ''),
        'logo_path' => env('ERP_COMPANY_LOGO', null),
    ],

    /*
    |--------------------------------------------------------------------------
    | Money and locale
    |--------------------------------------------------------------------------
    | Currency amounts are stored as DECIMAL(18,2) strings and manipulated with
    | bcmath only. Never use floats for money.
    */
    'invoice' => [
        'title' => 'CASH MEMO',
        'tagline' => '',
        'brand_subtitle' => '',
        'accent_color' => '#008895',
        'terms_heading' => 'WARRANTY & STORE TERMS',
        'terms' => "Unofficial Products: Carry a 7 Days Replacement & 12 Months Shop Service Warranty.\nOfficial Smartphones: Carry a 7 Days Replacement & 12 Months Official Brand Warranty (claimable at their official service center).\nExclusions: Accidental damage (broken/bent/scratched display, dents, burns, electric shock, liquid damage, rooting or manual updates) voids replacement and warranty.\nSoftware & Performance: Software bugs, update issues, third-party apps and gaming bugs are not covered under replacement warranty.\nParts Replacement: Customers must bear the full cost of physically damaged parts.\nApple International Warranty: Claims may take 7-9 weeks depending on international transit.\nExchange Policy: Storage upgrades, colour changes or model changes within 30 days incur a 20% deduction from invoice value.\nCondition & Checks: Display and battery health are not guaranteed for new/used devices. Inspect the device before leaving. Later claims for cosmetic scratches, external repairs or water resistance are not accepted.\nOfficial-style Warranty for Unofficial Devices: An additional 5.5% charge applies for official-equivalent claims.\nParts Warranty Items: {shop_name} will repair covered products including parts unless physically or liquid damaged.",
        'customer_signature' => 'Customer Signature',
        'authorized_signature' => 'Authorized Signature',
        'footer_note' => 'Please keep this cash memo for warranty and exchange.',
    ],

    'currency' => [
        'code' => env('ERP_CURRENCY_CODE', 'BDT'),
        'symbol' => env('ERP_CURRENCY_SYMBOL', '৳'),
        'scale' => 2,
    ],

    'locales' => [
        'en' => 'English',
        'bn' => 'বাংলা',
    ],

    /*
    |--------------------------------------------------------------------------
    | Business timezone
    |--------------------------------------------------------------------------
    | All timestamps are stored in UTC. Business-day boundaries ("today's
    | sales") and installment due dates are evaluated in this timezone.
    */
    'timezone' => env('ERP_TIMEZONE', 'Asia/Dhaka'),

    /*
    |--------------------------------------------------------------------------
    | IMEI rules
    |--------------------------------------------------------------------------
    | A valid handset IMEI is 15 digits and passes the Luhn checksum. Legacy
    | records may be imported as flagged LEGACY_UNVERIFIED identities, and
    | devices with no IMEI receive an internally generated serial, flagged
    | INTERNAL_SERIAL. Nothing is silently bypassed.
    */
    'imei' => [
        'require_luhn' => env('ERP_IMEI_REQUIRE_LUHN', true),
        'allow_legacy_override' => env('ERP_IMEI_ALLOW_LEGACY_OVERRIDE', true),
        'normalize_strip' => [' ', "\t", "\n", "\r", '-', '.', '_'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Numbering series
    |--------------------------------------------------------------------------
    | Document numbers are allocated transactionally from document_sequences.
    */
    'sequences' => [
        'purchase' => ['prefix' => 'PUR', 'padding' => 5, 'period' => 'year'],
        'transfer' => ['prefix' => 'TRF', 'padding' => 5, 'period' => 'year'],
        'sale' => ['prefix' => 'INV', 'padding' => 6, 'period' => 'year'],
        'receipt' => ['prefix' => 'RCP', 'padding' => 6, 'period' => 'year'],
        'repair' => ['prefix' => 'REP', 'padding' => 5, 'period' => 'year'],
        'exchange' => ['prefix' => 'EXC', 'padding' => 5, 'period' => 'year'],
        'expense' => ['prefix' => 'EXP', 'padding' => 6, 'period' => 'year'],
        'external' => ['prefix' => 'EXT', 'padding' => 6, 'period' => 'year'],
        'import' => ['prefix' => 'IMP', 'padding' => 5, 'period' => 'year'],
        'journal' => ['prefix' => 'JE', 'padding' => 6, 'period' => 'year'],
        'collection' => ['prefix' => 'COL', 'padding' => 6, 'period' => 'year'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Accounting / policy switches
    |--------------------------------------------------------------------------
    | Defaults documented in the specification. They are configurable through
    | Settings and must be confirmed with the owner before production.
    */
    'policy' => [
        'allow_negative_accessory_stock' => false,
        'repair_cost_capitalization' => env('ERP_REPAIR_COST_CAPITALIZE', false),
        'installment_missing_day' => 'last_valid_day',
        'expense_requires_reason' => true,
        'guest_expense_requires_name' => false,
        'discount_override_permission' => true,
        'low_stock_alerts' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Grid defaults
    |--------------------------------------------------------------------------
    */
    'grid' => [
        'per_page' => 50,
        'max_per_page' => 200,
        'default_columns' => [
            'row_no', 'brand', 'model', 'storage', 'ram', 'color', 'imei1', 'imei2',
            'battery_health', 'cycle_count', 'condition_grade', 'box_kit',
            'supplier', 'purchase_price', 'purchase_date', 'expected_price',
            'unit', 'state', 'last_activity', 'selling_price',
        ],
    ],

    'features' => [
        'two_factor' => false,
        'sms_alerts' => false,
        'whatsapp_alerts' => false,
        'thermal_print' => true,
    ],
];
