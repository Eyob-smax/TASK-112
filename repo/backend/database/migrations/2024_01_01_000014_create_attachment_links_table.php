<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attachment_links', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('attachment_id');

            // Opaque token — the actual credential for LAN link resolution
            $table->string('token', 64)->unique();

            // TTL enforcement
            $table->timestamp('expires_at');              // Hard expiry — max 72 hours from creation
            $table->boolean('is_single_use')->default(false);

            // Single-use consumption tracking (atomic — enforced via DB transaction)
            $table->timestamp('consumed_at')->nullable();
            $table->uuid('consumed_by')->nullable();

            // Revocation
            $table->timestamp('revoked_at')->nullable();
            $table->uuid('revoked_by')->nullable();
            $table->string('revocation_reason', 500)->nullable();

            // Creation audit
            $table->uuid('created_by');
            $table->string('ip_restriction', 45)->nullable(); // Optional: restrict to requesting IP

            $table->timestamps();

            $table->foreign('attachment_id')->references('id')->on('attachments')->cascadeOnDelete();
            $table->foreign('consumed_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('revoked_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->restrictOnDelete();

            $table->index('attachment_id');
            $table->index('expires_at');
            $table->index('consumed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attachment_links');
    }
};
