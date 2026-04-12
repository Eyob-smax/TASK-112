<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name')->unique();       // e.g. 'admin', 'manager', 'staff'
            $table->string('guard_name')->default('sanctum');
            $table->string('type', 30);             // App\Domain\Auth\Enums\RoleType value
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index('guard_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
