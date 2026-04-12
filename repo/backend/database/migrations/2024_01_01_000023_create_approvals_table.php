<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Immutable approval actions table.
 * Records each approve/reject/reassign/add-approver/withdraw action.
 * Append-only — no updates or deletes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approvals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workflow_node_id');
            $table->uuid('actor_id');               // The user who took the action
            $table->string('action', 20);           // App\Domain\Workflow\Enums\ApprovalAction
            $table->text('reason')->nullable();     // Required for reject/reassign
            $table->uuid('target_user_id')->nullable(); // For reassign/add-approver actions
            $table->timestamp('actioned_at');
            $table->timestamps();

            $table->foreign('workflow_node_id')
                ->references('id')
                ->on('workflow_nodes')
                ->cascadeOnDelete();

            $table->foreign('actor_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('target_user_id')->references('id')->on('users')->nullOnDelete();

            $table->index('workflow_node_id');
            $table->index('actor_id');
            $table->index('actioned_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approvals');
    }
};
