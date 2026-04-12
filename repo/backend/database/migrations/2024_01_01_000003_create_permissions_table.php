<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name')->unique();       // e.g. 'documents.publish', 'attachments.share'
            $table->string('guard_name')->default('sanctum');
            $table->string('scope', 30);            // App\Domain\Auth\Enums\PermissionScope value
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index('guard_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permissions');
    }
};
