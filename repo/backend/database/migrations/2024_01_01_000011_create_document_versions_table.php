<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('document_id');
            $table->unsignedInteger('version_number');       // Auto-incremented within document scope
            $table->string('status', 20)->default('current'); // App\Domain\Document\Enums\VersionStatus

            // Encrypted file storage reference
            $table->text('file_path');                       // Encrypted path string
            $table->string('original_filename', 255);
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('file_size_bytes');
            $table->string('sha256_fingerprint', 64);

            // Preview metadata (from questions.md #1 — metadata-only, no rendered previews)
            $table->unsignedSmallInteger('page_count')->nullable();   // For PDFs
            $table->unsignedSmallInteger('sheet_count')->nullable();  // For XLSX
            $table->boolean('is_previewable')->default(false);
            $table->boolean('thumbnail_available')->default(false);

            $table->uuid('created_by');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->foreign('document_id')->references('id')->on('documents')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->restrictOnDelete();

            // Unique constraint: only one of each version number per document
            $table->unique(['document_id', 'version_number']);

            $table->index('document_id');
            $table->index('status');
            $table->index('sha256_fingerprint');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_versions');
    }
};
