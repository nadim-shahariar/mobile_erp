<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\BusinessUnit;
use App\Models\StaffProfile;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Demo staff for local development and acceptance walkthroughs.
 * Passwords are shown in the README; these accounts are clearly demo-only.
 */
class StaffSeeder extends Seeder
{
    public function run(): void
    {
        $units = BusinessUnit::query()->ordered()->get()->keyBy('code');

        $people = [
            ['name' => 'Demo Manager', 'email' => 'manager@example.com', 'role' => 'manager', 'designation' => 'Manager', 'units' => ['U1', 'U2'], 'code' => 'EMP-0002'],
            ['name' => 'Demo Cashier', 'email' => 'cashier@example.com', 'role' => 'cashier', 'designation' => 'Cashier', 'units' => ['U1'], 'code' => 'EMP-0003'],
            ['name' => 'Demo Staff', 'email' => 'staff@example.com', 'role' => 'staff', 'designation' => 'Sales Staff', 'units' => ['U2'], 'code' => 'EMP-0004'],
        ];

        foreach ($people as $person) {
            $user = User::query()->updateOrCreate(
                ['email' => $person['email']],
                [
                    'name' => $person['name'],
                    'password' => 'Demo!Pass2026',
                    'is_active' => true,
                    'email_verified_at' => now(),
                ],
            );

            if ($user->roles()->doesntExist()) {
                $user->assignRole($person['role']);
            }

            $unitIds = collect($person['units'])
                ->map(fn (string $code) => $units->get($code)?->getKey())
                ->filter()
                ->values()
                ->all();

            $user->businessUnits()->syncWithoutDetaching($unitIds);

            StaffProfile::query()->firstOrCreate(
                ['user_id' => $user->getKey()],
                [
                    'code' => $person['code'],
                    'designation' => $person['designation'],
                    'joined_at' => now()->toDateString(),
                ],
            );
        }
    }
}
