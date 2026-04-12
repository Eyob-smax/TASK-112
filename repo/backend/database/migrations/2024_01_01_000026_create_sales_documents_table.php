<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('document_number', 30)->unique(); // SITE-YYYYMMDD-000001
            $table->string('site_code', 10);
            $table->string('status', 20)->default('draft');  // App\Domain\Sales\Enums\SalesStatus
            $table->uuid('department_id');
            $table->uuid('created_by');
            $table->uuid('reviewed_by')->nullable();

            // State transition timestamps
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->text('voided_reason')->nullable();

            // Workflow linkage
            $table->uuid('workflow_instance_id')->nullable();

            // Outbound linkage (requires final approval / completed state)
            $table->timestamp('outbound_linked_at')->nullable();
            $table->uuid('outbound_linked_by')->nullable();

            // Financial summary
            $table->decimal('total_amount', 15, 2)->default(0);

            // Sensitive notes — field-level masking applied in API responses for lower roles
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('department_id')->references('id')->on('departments')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('reviewed_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('workflow_instance_id')->references('id')->on('workflow_instances')->nullOnDelete();
            $table->foreign('outbound_linked_by')->references('id')->on('users')->nullOnDelete();

            $table->index('site_code');
            $table->index('status');
            $table->index('department_id');
            $table->index('created_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_documents');
    }
};
