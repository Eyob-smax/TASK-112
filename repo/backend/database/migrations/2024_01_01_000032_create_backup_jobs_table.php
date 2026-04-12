<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records backup job executions.
 *
 * From questions.md (ambiguity #8):
 *   - Backups include MySQL dump + attachment filesystem artifacts
 *   - 14-day retention, pruned automatically
 *   - Manifest JSON captures dump metadata and artifact sets
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_jobs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->string('status', 20)->default('pending'); // pending | running | success | failed
            $table->json('manifest')->nullable();              // Backup contents metadata
            $table->unsignedBigInteger('size_bytes')->nullable(); // Total backup size
            $table->timestamp('retention_expires_at');          // 14 days from started_at
            $table->text('error_message')->nullable();
            $table->boolean('is_manual')->default(false);       // True if triggered via admin API
            $table->timestamps();

            $table->index('status');
            $table->index('retention_expires_at');
            $table->index('started_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_jobs');
    }
};
