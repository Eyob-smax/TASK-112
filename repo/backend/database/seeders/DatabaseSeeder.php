<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Order matters: roles/permissions must be seeded before any user fixtures
     * that assign roles. Dev fixtures (if added in later prompts) should be
     * called after RoleAndPermissionSeeder.
     */
    public function run(): void
    {
        $this->call([
            RoleAndPermissionSeeder::class,
            AdminBootstrapSeeder::class,
            DemoUsersSeeder::class,
        ]);
    }
}
