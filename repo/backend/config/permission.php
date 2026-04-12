<?php

return [

    'models' => [
        /*
         * Use our custom Role model that extends Spatie's with UUID support
         * and the RoleType enum cast.
         */
        'role' => App\Models\Role::class,

        /*
         * Use our custom Permission model that extends Spatie's with UUID support
         * and the PermissionScope enum cast.
         */
        'permission' => App\Models\Permission::class,
    ],

    'table_names' => [
        'roles'                 => 'roles',
        'permissions'           => 'permissions',
        'model_has_permissions' => 'model_has_permissions',
        'model_has_roles'       => 'model_has_roles',
        'role_has_permissions'  => 'role_has_permissions',
    ],

    'column_names' => [
        /*
         * Spatie's default is model_id. We use UUIDs everywhere, so this
         * must be a string column — which our migrations already declare.
         */
        'role_pivot_key'       => 'role_id',
        'permission_pivot_key' => 'permission_id',
        'model_morph_key'      => 'model_id',
        'team_foreign_key'     => 'team_id',
    ],

    /*
     * Team support disabled — this is a single-tenant, single-host deployment.
     */
    'teams' => false,

    /*
     * Register the Spatie permission middleware aliases automatically.
     * We define our own middleware, so this is disabled.
     */
    'register_permission_check_method' => true,

    'register_octane_reset_listener' => false,

    'events_enabled' => false,

    'cache' => [
        /*
         * Cache permissions for 24 hours. The cache is invalidated automatically
         * when roles/permissions are changed via the Spatie methods.
         */
        'expiration_time' => \DateInterval::createFromDateString('24 hours'),

        'key' => 'spatie.permission.cache',

        'store' => 'default',
    ],

];
