<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Immutable audit event log.
 *
 * CRITICAL INVARIANTS:
 *   - No updated_at column — append-only, never updated
 *   - No soft deletes — never deleted
 *   - correlation_id is UNIQUE — same correlation_id cannot produce a second row
 *   - before_hash and after_hash are SHA-256 hashes of serialized record state
 *
 * Enforcement in application layer:
 *   - AuditEventRepository::record() checks correlation_id existence before inserting
 *   - Eloquent model overrides save() to prevent updates
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('correlation_id', 64)->unique(); // Idempotency key — no duplicates ever
            $table->uuid('actor_id')->nullable();            // Null for system-generated events
            $table->string('action', 50);                   // App\Domain\Audit\Enums\AuditAction
            $table->string('auditable_type', 100)->nullable(); // Model class name
            $table->uuid('auditable_id')->nullable();         // UUID of the affected record
            $table->string('before_hash', 64)->nullable();   // SHA-256 of record state before
            $table->string('after_hash', 64)->nullable();    // SHA-256 of record state after
            $table->json('payload')->nullable();              // Additional event data
            $table->string('ip_address', 45);                // Requester IP
            $table->timestamp('created_at');                 // NO updated_at — immutable

            // No $table->timestamps() — only created_at, no updated_at
            // No $table->softDeletes() — records are never deleted

            $table->foreign('actor_id')->references('id')->on('users')->nullOnDelete();

            $table->index('actor_id');
            $table->index('action');
            $table->index(['auditable_type', 'auditable_id']);
            $table->index('created_at');
        });

        // Schema-level enforcement: complements the application-layer guard in
        // EloquentAuditEventRepository::record(). MySQL 8.0.16+ enforces CHECK at write time.
        //
        // Rule 1: All modification actions must carry a non-null after_hash.
        //   Mirrors AuditAction::isModification() — update this list when that method changes.
        DB::statement("
            ALTER TABLE audit_events
            ADD CONSTRAINT chk_modification_requires_after_hash
            CHECK (
                action NOT IN (
                    'create','update','delete','archive',
                    'approve','reject',
                    'reassign','withdraw','add_approver',
                    'rollout_start','rollout_promote','rollout_back',
                    'sales_complete','sales_void','return_complete',
                    'password_change'
                )
                OR after_hash IS NOT NULL
            )
        ");

        // Rule 2: State-transition actions must also carry a non-null before_hash because
        //   they mutate a pre-existing record.
        //   Excluded: 'create' and 'add_approver' (both are create-like — no prior state).
        DB::statement("
            ALTER TABLE audit_events
            ADD CONSTRAINT chk_transition_requires_before_hash
            CHECK (
                action NOT IN (
                    'update','delete','archive',
                    'approve','reject',
                    'reassign','withdraw',
                    'rollout_start','rollout_promote','rollout_back',
                    'sales_complete','sales_void','return_complete',
                    'password_change'
                )
                OR before_hash IS NOT NULL
            )
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');
    }
};
