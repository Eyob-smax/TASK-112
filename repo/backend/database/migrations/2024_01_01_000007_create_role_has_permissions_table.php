<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spatie Laravel Permission — role_has_permissions pivot table.
 * Assigns permissions to roles.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('role_has_permissions', function (Blueprint $table) {
            $table->uuid('permission_id');
            $table->uuid('role_id');

            $table->primary(['permission_id', 'role_id']);

            $table->foreign('permission_id')
                ->references('id')
                ->on('permissions')
                ->cascadeOnDelete();

            $table->foreign('role_id')
                ->references('id')
                ->on('roles')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_has_permissions');
    }
};
