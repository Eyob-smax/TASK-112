<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_template_nodes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workflow_template_id');
            $table->unsignedSmallInteger('node_order');     // Execution order within template
            $table->string('node_type', 20);                // App\Domain\Workflow\Enums\NodeType
            $table->uuid('role_required')->nullable();       // Assignable by role
            $table->uuid('user_required')->nullable();       // Or by specific user
            $table->unsignedSmallInteger('sla_business_days')->default(2);
            $table->boolean('is_parallel')->default(false); // Parallel sign-off node

            // Conditional branching support
            $table->string('condition_field', 100)->nullable();    // Field to evaluate
            $table->string('condition_operator', 20)->nullable();  // 'gt', 'lt', 'eq', 'gte', 'lte'
            $table->string('condition_value', 255)->nullable();    // Value to compare against

            $table->text('label')->nullable();               // Human-readable node description
            $table->timestamps();

            $table->foreign('workflow_template_id')
                ->references('id')
                ->on('workflow_templates')
                ->cascadeOnDelete();

            $table->foreign('role_required')->references('id')->on('roles')->nullOnDelete();
            $table->foreign('user_required')->references('id')->on('users')->nullOnDelete();

            $table->index('workflow_template_id');
            $table->index(['workflow_template_id', 'node_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_template_nodes');
    }
};
