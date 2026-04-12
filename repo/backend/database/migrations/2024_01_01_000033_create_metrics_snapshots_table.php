<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stores locally persisted application metrics.
 *
 * From questions.md (ambiguity #9):
 *   - Metrics stored in local tables, not external scraping (no Prometheus)
 *   - Queryable through authorized admin API
 *   - 90-day retention, pruned by PruneRetentionJob
 *
 * Metric types:
 *   - request_timing: p95 request duration samples
 *   - queue_depth: snapshot of queued job count
 *   - failed_approvals: count of workflow nodes that expired or were rejected
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('metrics_snapshots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('metric_type', 50); // request_timing | queue_depth | failed_approvals
            $table->decimal('value', 15, 4);   // Numeric metric value
            $table->json('labels')->nullable(); // Dimensional labels (route, queue name, etc.)
            $table->timestamp('recorded_at');
            $table->timestamp('retained_until'); // recorded_at + 90 days
            $table->timestamps();

            $table->index('metric_type');
            $table->index('recorded_at');
            $table->index('retained_until');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('metrics_snapshots');
    }
};
