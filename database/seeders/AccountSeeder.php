<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Accounting\ChartOfAccounts;
use Illuminate\Database\Seeder;

class AccountSeeder extends Seeder
{
    public function run(): void
    {
        app(ChartOfAccounts::class)->ensure();
    }
}
