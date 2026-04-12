<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotency key cache for write APIs.
 *
 * Stores hashed X-Idempotency-Key values with their cached responses.
 * TTL: 24 hours (expired records are pruned by a retention job).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('key_hash', 64);              // SHA-256 of the raw X-Idempotency-Key value
            $table->string('http_method', 10);           // The HTTP method the key was used with
            $table->string('request_path', 500);         // The request path for conflict detection
            $table->unsignedSmallInteger('response_status');
            $table->json('response_body');
            $table->timestamp('expires_at');             // created_at + 24h
            $table->timestamp('created_at');             // No updated_at — key is immutable once stored

            // Composite uniqueness: same key may not be replayed across different endpoints
            $table->unique(['key_hash', 'http_method', 'request_path'], 'idempotency_keys_scope_unique');
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
