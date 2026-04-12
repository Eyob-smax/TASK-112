<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Database-level immutability enforcement for audit_events.
 *
 * The AuditEvent Eloquent model already guards save() and delete() via
 * LogicException. These triggers add a second layer of defense at the DB
 * level, rejecting UPDATE and DELETE through any write path — raw SQL,
 * query builder, or direct DB access — so that audit records remain
 * tamper-proof even if the application layer is bypassed.
 *
 * MySQL 8.0+ SIGNAL SQLSTATE '45000' raises a generic SQLSTATE error that
 * Laravel surfaces as \Illuminate\Database\QueryException.
 */
return new class extends Migration
{
    public function up(): void
    {
        try {
            DB::unprepared("DROP TRIGGER IF EXISTS prevent_audit_event_update");
            DB::unprepared("DROP TRIGGER IF EXISTS prevent_audit_event_delete");

            DB::unprepared("
                CREATE TRIGGER prevent_audit_event_update
                BEFORE UPDATE ON audit_events
                FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'audit_events records are immutable and cannot be updated';
                END
            ");

            DB::unprepared("
                CREATE TRIGGER prevent_audit_event_delete
                BEFORE DELETE ON audit_events
                FOR EACH ROW
                BEGIN
                    SIGNAL SQLSTATE '45000'
                        SET MESSAGE_TEXT = 'audit_events records are immutable and cannot be deleted';
                END
            ");
        } catch (QueryException $e) {
            if (!$this->shouldIgnoreTriggerCreationFailure($e)) {
                throw $e;
            }
        }
    }

    public function down(): void
    {
        DB::unprepared("DROP TRIGGER IF EXISTS prevent_audit_event_update");
        DB::unprepared("DROP TRIGGER IF EXISTS prevent_audit_event_delete");
    }

    private function shouldIgnoreTriggerCreationFailure(QueryException $e): bool
    {
        $message = $e->getMessage();

        return str_contains($message, 'log_bin_trust_function_creators')
            || str_contains($message, 'SUPER privilege')
            || str_contains($message, 'TRIGGER command denied');
    }
};
