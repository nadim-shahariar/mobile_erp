<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Canonical permission catalogue and the bootstrap role matrix.
 *
 * Permissions are strings stored by spatie/laravel-permission. Roles are a
 * convenience for onboarding only; individual grants can be customised in the
 * Staff screen without changing code.
 */
final class Permissions
{
    public const ALL = [
        'dashboard.view',
        'inventory.view',
        'inventory.cost.view',
        'inventory.create',
        'inventory.update',
        'inventory.import',
        'inventory.adjust',
        'inventory.imei.override',
        'purchase.view',
        'purchase.create',
        'purchase.approve',
        'supplier.view',
        'supplier.manage',
        'catalog.view',
        'catalog.manage',
        'stock.transfer.view',
        'stock.transfer.create',
        'stock.transfer.approve',
        'repair.create',
        'repair.receive',
        'pos.sell',
        'pos.discount.override',
        'sale.refund',
        'sale.cancel',
        'invoice.view',
        'invoice.print',
        'customer.view',
        'customer.edit',
        'installment.manage',
        'payment.collect',
        'exchange.create',
        'exchange.approve',
        'expense.create',
        'expense.approve',
        'payroll.run',
        'staff.view',
        'staff.manage',
        'accounting.view',
        'accounting.adjust',
        'reports.sales',
        'reports.cost_profit',
        'reports.export',
        'settings.manage',
        'audit.view',
        'unit.view',
    ];

    /** Permissions a cashier may never be granted without explicit review. */
    public const SENSITIVE = [
        'inventory.cost.view',
        'reports.cost_profit',
        'accounting.view',
        'accounting.adjust',
        'settings.manage',
        'staff.manage',
    ];

    /** @return array<string, list<string>> */
    public static function roleMatrix(): array
    {
        $owner = self::ALL;

        $manager = array_values(array_diff(self::ALL, [
            'settings.manage',
            'accounting.adjust',
        ]));

        $cashier = [
            'dashboard.view',
            'inventory.view',
            'stock.transfer.view',
            'stock.transfer.create',
            'pos.sell',
            'invoice.view',
            'invoice.print',
            'customer.view',
            'customer.edit',
            'payment.collect',
            'expense.create',
        ];

        $staff = [
            'dashboard.view',
            'inventory.view',
            'inventory.create',
            'stock.transfer.view',
            'stock.transfer.create',
            'repair.create',
        ];

        return [
            'owner' => $owner,
            'manager' => $manager,
            'cashier' => $cashier,
            'staff' => $staff,
        ];
    }
}
