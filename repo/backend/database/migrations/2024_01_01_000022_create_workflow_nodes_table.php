<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_nodes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workflow_instance_id');
            $table->uuid('template_node_id')->nullable();   // Null for dynamically added nodes
            $table->unsignedSmallInteger('node_order');
            $table->string('node_type', 20);                // App\Domain\Workflow\Enums\NodeType
            $table->uuid('assigned_to')->nullable();         // User currently responsible
            $table->string('status', 20)->default('pending'); // App\Domain\Workflow\Enums\WorkflowStatus

            // SLA tracking
            $table->timestamp('sla_due_at');
            $table->timestamp('reminded_at')->nullable();

            // Completion
            $table->timestamp('completed_at')->nullable();

            // Branching condition (copied from template for execution)
            $table->string('condition_field', 100)->nullable();
            $table->string('condition_operator', 20)->nullable();
            $table->string('condition_value', 255)->nullable();

            $table->text('label')->nullable();
            $table->timestamps();

            $table->foreign('workflow_instance_id')
                ->references('id')
                ->on('workflow_instances')
                ->cascadeOnDelete();

            $table->foreign('template_node_id')
                ->references('id')
                ->on('workflow_template_nodes')
                ->nullOnDelete();

            $table->foreign('assigned_to')->references('id')->on('users')->nullOnDelete();

            $table->index('workflow_instance_id');
            $table->index('assigned_to');
            $table->index('status');
            $table->index('sla_due_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_nodes');
    }
};
