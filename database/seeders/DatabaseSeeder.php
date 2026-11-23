<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Bootstrap data only: permissions, units and the owner account from env.
     * Demo catalogue/stock lives in DemoDataSeeder and is never run by default.
     */
    public function run(): void
    {
        $this->call([
            PermissionSeeder::class,
            BusinessUnitSeeder::class,
            AccountSeeder::class,
            AdminUserSeeder::class,
        ]);
    }
}
