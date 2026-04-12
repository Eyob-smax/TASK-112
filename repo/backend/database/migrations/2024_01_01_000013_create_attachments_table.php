<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attachments', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Polymorphic association — attachments can be linked to any business record
            $table->string('record_type', 100);   // e.g. 'App\Models\SalesDocument'
            $table->uuid('record_id');             // UUID of the associated record

            // File metadata
            $table->string('original_filename', 255);
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('file_size_bytes');
            $table->string('sha256_fingerprint', 64);  // For deduplication — unique within a record

            // Encrypted storage — AES-256-CBC via Laravel Crypt
            $table->text('encrypted_path');            // Encrypted path under storage/app/attachments/
            $table->string('encryption_key_id', 64);   // Key identifier for rotation support

            // Status and validity
            $table->string('status', 20)->default('active'); // App\Domain\Attachment\Enums\AttachmentStatus
            $table->unsignedSmallInteger('validity_days')->default(365);
            $table->timestamp('expires_at')->nullable();

            // Ownership
            $table->uuid('uploaded_by');
            $table->uuid('department_id')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('uploaded_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('department_id')->references('id')->on('departments')->nullOnDelete();

            // Index for morph lookup
            $table->index(['record_type', 'record_id']);
            $table->index('sha256_fingerprint');
            $table->index('status');
            $table->index('expires_at');
            $table->index('uploaded_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');
    }
};
