<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records every controlled download of a document version.
 * For PDFs, also records the watermark text that was applied.
 *
 * This table is append-only — no updates or deletes permitted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_download_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('document_version_id');
            $table->uuid('downloaded_by');
            $table->timestamp('downloaded_at');

            // Watermark tracking (PDFs only — from original prompt)
            $table->string('watermark_text', 500)->nullable(); // "{username} {timestamp}"
            $table->boolean('watermark_applied')->default(false);
            $table->boolean('is_pdf')->default(false);

            $table->string('ip_address', 45);
            $table->timestamps();

            $table->foreign('document_version_id')
                ->references('id')
                ->on('document_versions')
                ->restrictOnDelete();

            $table->foreign('downloaded_by')
                ->references('id')
                ->on('users')
                ->restrictOnDelete();

            $table->index('document_version_id');
            $table->index('downloaded_by');
            $table->index('downloaded_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_download_records');
    }
};
