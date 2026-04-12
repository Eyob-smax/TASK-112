<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Structured application logs with 90-day retention.
 *
 * Used for offline troubleshooting — request timing, auth events, job outcomes.
 * Queryable via admin API. Pruned by PruneRetentionJob after retained_until.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('structured_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('level', 20);            // emergency | alert | critical | error | warning | notice | info | debug
            $table->text('message');
            $table->json('context')->nullable();    // Structured contextual data (no sensitive fields)
            $table->string('channel', 50)->default('application');
            $table->string('request_id', 64)->nullable(); // Correlation across a single request
            $table->timestamp('recorded_at');
            $table->timestamp('retained_until');    // recorded_at + 90 days
            $table->timestamps();

            $table->index('level');
            $table->index('channel');
            $table->index('recorded_at');
            $table->index('retained_until');
            $table->index('request_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('structured_logs');
    }
};
