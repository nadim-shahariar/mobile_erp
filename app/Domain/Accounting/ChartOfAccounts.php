<?php

declare(strict_types=1);

namespace App\Domain\Accounting;

use App\Enums\PaymentMethod;
use App\Models\Account;
use App\Models\BusinessUnit;

/**
 * Resolves (and lazily creates) the chart of accounts and per-unit payment
 * accounts. Codes are stable so reports and journals can rely on them.
 */
final class ChartOfAccounts
{
    /** @var array<string, Account> */
    private array $memo = [];

    public const SYSTEM_ACCOUNTS = [
        ['code' => '1100', 'name' => 'Trade Receivables', 'class' => Account::CLASS_ASSET, 'type' => Account::TYPE_RECEIVABLE],
        ['code' => '1200', 'name' => 'Inventory — Handsets', 'class' => Account::CLASS_ASSET, 'type' => Account::TYPE_INVENTORY_HANDSET],
        ['code' => '1210', 'name' => 'Inventory — Accessories', 'class' => Account::CLASS_ASSET, 'type' => Account::TYPE_INVENTORY_ACCESSORY],
        ['code' => '1300', 'name' => 'Staff Salary Advances', 'class' => Account::CLASS_ASSET, 'type' => 'STAFF_ADVANCE'],
        ['code' => '2400', 'name' => 'External Shop / Person Deposits', 'class' => Account::CLASS_LIABILITY, 'type' => 'DELIVERY_DEPOSIT'],
        ['code' => '2300', 'name' => 'Salaries Payable', 'class' => Account::CLASS_LIABILITY, 'type' => 'SALARY_PAYABLE'],
        ['code' => '6400', 'name' => 'Staff Salary Expense', 'class' => Account::CLASS_EXPENSE, 'type' => 'SALARY_EXPENSE'],
        ['code' => '2100', 'name' => 'Exchange Clearing', 'class' => Account::CLASS_LIABILITY, 'type' => 'EXCHANGE_CLEARING'],
        ['code' => '2000', 'name' => 'Supplier Payables', 'class' => Account::CLASS_LIABILITY, 'type' => Account::TYPE_PAYABLE],
        ['code' => '3000', 'name' => 'Owner Equity', 'class' => Account::CLASS_EQUITY, 'type' => 'EQUITY'],
        ['code' => '3100', 'name' => 'Opening Balance Equity', 'class' => Account::CLASS_EQUITY, 'type' => 'OPENING_EQUITY'],
        ['code' => '4100', 'name' => 'EMI Charges Income', 'class' => Account::CLASS_INCOME, 'type' => 'EMI_INCOME'],
        ['code' => '4000', 'name' => 'Sales Revenue', 'class' => Account::CLASS_INCOME, 'type' => Account::TYPE_REVENUE],
        ['code' => '4900', 'name' => 'Purchase Discounts Received', 'class' => Account::CLASS_INCOME, 'type' => 'PURCHASE_DISCOUNT'],
        ['code' => '5000', 'name' => 'Cost of Goods Sold — Handsets', 'class' => Account::CLASS_EXPENSE, 'type' => Account::TYPE_COGS_HANDSET],
        ['code' => '5010', 'name' => 'Cost of Goods Sold — Accessories', 'class' => Account::CLASS_EXPENSE, 'type' => Account::TYPE_COGS_ACCESSORY],
        ['code' => '5900', 'name' => 'Sales Discounts', 'class' => Account::CLASS_EXPENSE, 'type' => 'DISCOUNT'],
        ['code' => '6100', 'name' => 'Purchase Other Charges', 'class' => Account::CLASS_EXPENSE, 'type' => 'PURCHASE_CHARGE'],
        ['code' => '6300', 'name' => 'Operating Expenses', 'class' => Account::CLASS_EXPENSE, 'type' => 'OPERATING_EXPENSE'],
        ['code' => '6200', 'name' => 'Inventory Adjustments & Write-offs', 'class' => Account::CLASS_EXPENSE, 'type' => 'INVENTORY_ADJUSTMENT'],
    ];

    public function ensure(): void
    {
        foreach (self::SYSTEM_ACCOUNTS as $definition) {
            $this->memo[$definition['code']] = Account::query()->updateOrCreate(
                ['code' => $definition['code']],
                [
                    'name' => $definition['name'],
                    'class' => $definition['class'],
                    'type' => $definition['type'],
                    'business_unit_id' => null,
                    'is_payment_account' => false,
                    'is_active' => true,
                ],
            );
        }
    }

    public function account(string $code): Account
    {
        if (! isset($this->memo[$code])) {
            $this->memo[$code] = Account::query()->where('code', $code)->first();
        }

        if ($this->memo[$code] === null) {
            $this->ensure();
            $this->memo[$code] = Account::query()->where('code', $code)->first();
        }

        if ($this->memo[$code] === null) {
            throw new \RuntimeException("Ledger account [{$code}] is not configured.");
        }

        return $this->memo[$code];
    }

    public function receivable(): Account
    {
        return $this->account('1100');
    }

    public function openingEquity(): Account
    {
        return $this->account('3100');
    }

    public function inventoryAdjustment(): Account
    {
        return $this->account('6200');
    }

    public function handsetInventory(): Account
    {
        return $this->account('1200');
    }

    public function accessoryInventory(): Account
    {
        return $this->account('1210');
    }

    public function supplierPayable(): Account
    {
        return $this->account('2000');
    }

    public function salesRevenue(): Account
    {
        return $this->account('4000');
    }

    public function purchaseDiscount(): Account
    {
        return $this->account('4900');
    }

    public function purchaseCharges(): Account
    {
        return $this->account('6100');
    }

    public function handsetCogs(): Account
    {
        return $this->account('5000');
    }

    public function accessoryCogs(): Account
    {
        return $this->account('5010');
    }

    public function paymentAccount(BusinessUnit $unit, PaymentMethod $method): Account
    {
        $type = $method->accountType();
        $code = $type.'-'.$unit->code;

        if (! isset($this->memo[$code])) {
            $this->memo[$code] = Account::query()->firstOrCreate(
                ['code' => $code],
                [
                    'name' => $method->label().' — '.$unit->name,
                    'class' => Account::CLASS_ASSET,
                    'type' => $type,
                    'business_unit_id' => $unit->getKey(),
                    'is_payment_account' => true,
                    'is_active' => true,
                ],
            );
        }

        return $this->memo[$code];
    }
}
