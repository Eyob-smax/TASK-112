<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Seeds non-admin demo users for manager/staff/auditor/viewer roles.
 *
 * Safe to re-run: users are created idempotently by username, then role
 * assignment is enforced if missing.
 */
class DemoUsersSeeder extends Seeder
{
    public function run(): void
    {
        $department = Department::firstOrCreate(
            ['code' => 'DEM'],
            ['name' => 'Demo Operations'],
        );

        $this->ensureDemoUser(
            username: 'demo_manager',
            email: 'manager@meridian.local',
            displayName: 'Demo Manager',
            role: 'manager',
            password: 'Manager@Meridian1!',
            departmentId: $department->id,
        );

        $this->ensureDemoUser(
            username: 'demo_staff',
            email: 'staff@meridian.local',
            displayName: 'Demo Staff',
            role: 'staff',
            password: 'Staff@Meridian1!',
            departmentId: $department->id,
        );

        $this->ensureDemoUser(
            username: 'demo_auditor',
            email: 'auditor@meridian.local',
            displayName: 'Demo Auditor',
            role: 'auditor',
            password: 'Auditor@Meridian1!',
            departmentId: $department->id,
        );

        $this->ensureDemoUser(
            username: 'demo_viewer',
            email: 'viewer@meridian.local',
            displayName: 'Demo Viewer',
            role: 'viewer',
            password: 'Viewer@Meridian1!',
            departmentId: $department->id,
        );
    }

    private function ensureDemoUser(
        string $username,
        string $email,
        string $displayName,
        string $role,
        string $password,
        string $departmentId,
    ): void {
        $user = User::firstOrCreate(
            ['username' => $username],
            [
                'email' => $email,
                'display_name' => $displayName,
                'password_hash' => Hash::make($password),
                'department_id' => $departmentId,
                'is_active' => true,
            ],
        );

        if (!$user->hasRole($role)) {
            $user->assignRole($role);
        }
    }
}
