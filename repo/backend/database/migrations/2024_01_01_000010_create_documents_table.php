<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('title');
            $table->string('document_type', 50);      // e.g. 'policy', 'form', 'procedure'
            $table->uuid('department_id');
            $table->uuid('owner_id');                  // The user responsible for this document
            $table->string('status', 20)->default('draft'); // App\Domain\Document\Enums\DocumentStatus
            $table->text('description')->nullable();
            $table->string('access_control_scope', 30); // App\Domain\Auth\Enums\PermissionScope

            // Archive fields — immutable once set
            $table->boolean('is_archived')->default(false);
            $table->timestamp('archived_at')->nullable();
            $table->uuid('archived_by')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('department_id')->references('id')->on('departments')->restrictOnDelete();
            $table->foreign('owner_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('archived_by')->references('id')->on('users')->nullOnDelete();

            $table->index('department_id');
            $table->index('owner_id');
            $table->index('status');
            $table->index('is_archived');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
