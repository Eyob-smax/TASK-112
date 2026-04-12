<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('event_type', 100);           // e.g. 'sales_document', 'configuration_change'
            $table->uuid('department_id')->nullable();    // Null = applies system-wide

            // Conditional activation by amount thresholds
            $table->decimal('amount_threshold_min', 15, 2)->nullable();
            $table->decimal('amount_threshold_max', 15, 2)->nullable();

            $table->boolean('is_active')->default(true);
            $table->uuid('created_by');
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('department_id')->references('id')->on('departments')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->restrictOnDelete();

            $table->index('event_type');
            $table->index('department_id');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_templates');
    }
};
