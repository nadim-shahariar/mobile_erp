<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\BusinessUnit;
use Illuminate\Database\Seeder;

/**
 * Bootstrap branch records. Names are configurable placeholders: the owner must
 * rename them to the real shop locations from the settings screen.
 */
class BusinessUnitSeeder extends Seeder
{
    public function run(): void
    {
        $units = [
            ['code' => 'U1', 'name' => env('ERP_UNIT_ONE_NAME', 'Unit 1'), 'sort_order' => 1],
            ['code' => 'U2', 'name' => env('ERP_UNIT_TWO_NAME', 'Unit 2'), 'sort_order' => 2],
        ];

        foreach ($units as $unit) {
            BusinessUnit::query()->updateOrCreate(
                ['code' => $unit['code']],
                [
                    'name' => $unit['name'],
                    'timezone' => config('erp.timezone'),
                    'is_active' => true,
                    'sort_order' => $unit['sort_order'],
                ],
            );
        }
    }
}
