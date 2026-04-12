<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_instances', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workflow_template_id');
            $table->string('record_type', 100);    // The type of record requiring approval
            $table->uuid('record_id');              // UUID of the associated business record
            $table->string('status', 20)->default('pending'); // App\Domain\Workflow\Enums\WorkflowStatus
            $table->uuid('initiated_by');
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();

            // Withdrawal tracking
            $table->timestamp('withdrawn_at')->nullable();
            $table->uuid('withdrawn_by')->nullable();
            $table->text('withdrawal_reason')->nullable();

            $table->timestamps();

            $table->foreign('workflow_template_id')
                ->references('id')
                ->on('workflow_templates')
                ->restrictOnDelete();

            $table->foreign('initiated_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('withdrawn_by')->references('id')->on('users')->nullOnDelete();

            $table->index(['record_type', 'record_id']);
            $table->index('status');
            $table->index('initiated_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_instances');
    }
};
