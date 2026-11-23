<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\BusinessUnit;
use App\Models\StaffProfile;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Creates the first owner account from environment variables.
 *
 * NEVER leave the bootstrap password in place on a production deployment:
 * the seeder warns and the account must change it on first login.
 */
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = (string) env('ERP_OWNER_EMAIL', 'owner@example.com');
        $password = (string) env('ERP_OWNER_PASSWORD', 'ChangeMe!2026');

        $owner = User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => env('ERP_OWNER_NAME', 'Shop Owner'),
                'password' => $password,
                'is_active' => true,
                'email_verified_at' => now(),
            ],
        );

        if ($owner->roles()->doesntExist()) {
            $owner->assignRole('owner');
        }

        $units = BusinessUnit::query()->ordered()->get();

        if ($units->isNotEmpty() && $owner->businessUnits()->doesntExist()) {
            $owner->businessUnits()->attach($units->pluck('id')->all());

            $units->first()->users()->updateExistingPivot($owner->getKey(), ['is_default' => true]);
        }

        StaffProfile::query()->firstOrCreate(
            ['user_id' => $owner->getKey()],
            [
                'code' => 'EMP-0001',
                'designation' => 'Owner',
                'joined_at' => now()->toDateString(),
            ],
        );

        if ($password === 'ChangeMe!2026') {
            $this->command?->warn('Bootstrap owner password is the default. Change it immediately after login.');
        }
    }
}
