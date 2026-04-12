<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Creates the initial Administration department and a seeded admin account.
 *
 * Safe to re-run — idempotent via firstOrCreate / doesntExist guards.
 * Must be called AFTER RoleAndPermissionSeeder so the 'admin' role exists.
 *
 * Credentials for the seeded account:
 *   Username: admin
 *   Password: Admin@Meridian1!
 *
 * Change the password immediately after first login via:
 *   PUT /api/v1/admin/users/{id}/password
 */
class AdminBootstrapSeeder extends Seeder
{
    public function run(): void
    {
        $dept = \App\Models\Department::firstOrCreate(
            ['code' => 'ADM'],
            ['name' => 'Administration'],
        );

        if (\App\Models\User::where('username', 'admin')->doesntExist()) {
            $user = \App\Models\User::create([
                'username'      => 'admin',
                'display_name'  => 'System Administrator',
                'email'         => 'admin@meridian.local',
                'password_hash' => \Illuminate\Support\Facades\Hash::make('Admin@Meridian1!'),
                'department_id' => $dept->id,
                'is_active'     => true,
            ]);
            $user->assignRole('admin');
        }
    }
}
