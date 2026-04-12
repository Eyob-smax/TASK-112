<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Local to-do queue for offline event delivery.
 *
 * Used for: SLA reminder notifications, workflow assignments,
 * approval request notifications, and other internal queued events.
 * No external webhook or email — LAN-local queue only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('to_do_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');                         // Assignee
            $table->uuid('workflow_node_id')->nullable();    // If triggered by a workflow SLA
            $table->string('type', 50);                      // e.g. 'sla_reminder', 'approval_request'
            $table->string('title', 255);
            $table->text('body');
            $table->timestamp('due_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();

            $table->foreign('workflow_node_id')
                ->references('id')
                ->on('workflow_nodes')
                ->nullOnDelete();

            $table->index('user_id');
            $table->index('completed_at');
            $table->index('due_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('to_do_items');
    }
};
