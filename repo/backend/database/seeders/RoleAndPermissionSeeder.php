<?php

namespace Database\Seeders;

use App\Domain\Auth\Enums\RoleType;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Seeds all roles and permissions for the Meridian RBAC system.
 *
 * Safe to re-run — uses firstOrCreate for idempotency.
 * Guard name is 'sanctum' throughout (matches the API guard).
 */
class RoleAndPermissionSeeder extends Seeder
{
    private const GUARD = 'sanctum';

    /**
     * Permissions that operate across the entire system rather than a single department.
     * All other permissions default to 'own_department' scope.
     */
    private const PERMISSION_SCOPE = [
        'view audit events'  => 'system_wide',
        'manage backups'     => 'system_wide',
        'view metrics'       => 'system_wide',
        'manage departments' => 'system_wide',
        'view departments'   => 'system_wide',
        'view roles'         => 'system_wide',
    ];

    /**
     * All permissions in the system.
     */
    private const PERMISSIONS = [
        // Document management
        'view documents',
        'create documents',
        'update documents',
        'archive documents',

        // Configuration center
        'manage configuration',
        'view configuration',
        'manage rollouts',

        // Workflow
        'manage workflow templates',
        'view workflow',
        'manage workflow instances',
        'approve workflow nodes',

        // Sales
        'create sales',
        'view sales',
        'manage sales',
        'void sales',

        // Attachments
        'upload attachments',
        'download attachments',
        'revoke attachments',

        // Audit / Admin
        'view audit events',
        'manage backups',
        'view metrics',

        // Departments / Roles
        'view departments',
        'manage departments',
        'view roles',
    ];

    /**
     * Role → permission assignments.
     */
    private const ROLE_PERMISSIONS = [
        'admin' => [
            // Admin receives ALL permissions — handled programmatically below
        ],
        'manager' => [
            'view documents', 'create documents', 'update documents', 'archive documents',
            'manage configuration', 'view configuration', 'manage rollouts',
            'manage workflow templates', 'view workflow', 'manage workflow instances', 'approve workflow nodes',
            'create sales', 'view sales', 'manage sales', 'void sales',
            'upload attachments', 'download attachments', 'revoke attachments',
            'view audit events',
            'view metrics',
            'view departments',
        ],
        'staff' => [
            'view documents', 'create documents',
            'view configuration',
            'view workflow', 'approve workflow nodes',
            'create sales', 'view sales',
            'upload attachments', 'download attachments',
            'view departments',
        ],
        'auditor' => [
            'view documents',
            'view configuration',
            'view workflow',
            'view sales',
            'download attachments',
            'view audit events',
            'view metrics',
        ],
        'viewer' => [
            'view documents',
            'view configuration',
        ],
    ];

    public function run(): void
    {
        // Reset Spatie permission cache so changes take effect immediately
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // 1. Create all permissions
        foreach (self::PERMISSIONS as $permissionName) {
            Permission::firstOrCreate(
                ['name' => $permissionName, 'guard_name' => self::GUARD],
                [
                    'id'    => (string) Str::uuid(),
                    'scope' => self::PERMISSION_SCOPE[$permissionName] ?? 'own_department',
                ],
            );
        }

        // 2. Create roles and assign permissions
        foreach (self::ROLE_PERMISSIONS as $roleName => $permissionNames) {
            $roleType = RoleType::from($roleName);

            $role = Role::firstOrCreate(
                ['name' => $roleName, 'guard_name' => self::GUARD],
                ['id' => (string) Str::uuid(), 'type' => $roleType->value],
            );

            if ($roleName === 'admin') {
                // Admin gets all permissions
                $role->syncPermissions(Permission::where('guard_name', self::GUARD)->get());
            } else {
                $role->syncPermissions($permissionNames);
            }
        }
    }
}
