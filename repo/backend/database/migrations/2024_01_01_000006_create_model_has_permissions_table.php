<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spatie Laravel Permission — model_has_permissions pivot table.
 * Assigns direct permissions to users (elevated visibility grants).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('model_has_permissions', function (Blueprint $table) {
            $table->uuid('permission_id');
            $table->string('model_type');
            $table->uuid('model_id');

            $table->primary(['permission_id', 'model_id', 'model_type']);

            $table->foreign('permission_id')
                ->references('id')
                ->on('permissions')
                ->cascadeOnDelete();

            $table->index(['model_id', 'model_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('model_has_permissions');
    }
};
