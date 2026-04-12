<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('username', 100)->unique();  // Login identifier — unique per system
            $table->string('email', 254)->nullable()->unique(); // Optional — system is username-first
            $table->string('password_hash');            // bcrypt hash — never plaintext
            $table->string('display_name', 200);
            $table->uuid('department_id');
            $table->boolean('is_active')->default(true);

            // Lockout tracking
            $table->timestamp('locked_until')->nullable();
            $table->unsignedSmallInteger('failed_attempt_count')->default(0);
            $table->timestamp('last_failed_at')->nullable();

            $table->timestamps();
            $table->softDeletes(); // Soft delete — users are deactivated, not destroyed

            $table->foreign('department_id')
                ->references('id')
                ->on('departments')
                ->restrictOnDelete();

            $table->index('username');
            $table->index('locked_until');
            $table->index('department_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
