<?php

namespace App\Application\Logging;

use App\Domain\Audit\Contracts\AuditEventRepositoryInterface;
use App\Domain\Audit\Enums\AuditAction;
use App\Models\StructuredLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Writes structured log entries to the structured_logs table.
 *
 * Sensitive fields (password, token, key, secret, authorization, etc.) are
 * automatically redacted from context arrays before persistence. Redaction is
 * applied recursively so nested arrays are sanitized as well.
 */
class StructuredLogger
{
    /**
     * Substrings that trigger redaction when found in a context key (case-insensitive).
     */
    private const SENSITIVE_SUBSTRINGS = [
        'password',
        'token',
        'secret',
        'authorization',
        'api_key',
        'encryption_key',
        'private_key',
        'access_key',
    ];

    /**
     * Write a structured log entry.
     *
     * @param string $level   PSR-3 log level: emergency, alert, critical, error, warning, notice, info, debug
     * @param string $message Human-readable description
     * @param array  $context Key/value pairs providing context (sensitive keys auto-redacted)
     * @param string $channel Application channel, e.g. 'application', 'auth', 'audit'
     */
    public function log(
        string $level,
        string $message,
        array $context = [],
        string $channel = 'application'
    ): void {
        $retentionDays = (int) config('meridian.retention.log_days', 90);

        DB::transaction(function () use ($level, $message, $context, $channel, $retentionDays): void {
            $log = StructuredLog::create([
                'level'          => $level,
                'message'        => $message,
                'context'        => $this->sanitize($context),
                'channel'        => $channel,
                'request_id'     => $this->resolveRequestId(),
                'recorded_at'    => now(),
                'retained_until' => now()->addDays($retentionDays),
            ]);

            $this->recordAudit(
                action: AuditAction::Create,
                auditableType: StructuredLog::class,
                auditableId: $log->id,
                afterHash: hash('sha256', json_encode($log->toArray())),
            );
        });
    }

    /**
     * Convenience wrappers for common PSR-3 levels.
     */
    public function info(string $message, array $context = [], string $channel = 'application'): void
    {
        $this->log('info', $message, $context, $channel);
    }

    public function warning(string $message, array $context = [], string $channel = 'application'): void
    {
        $this->log('warning', $message, $context, $channel);
    }

    public function error(string $message, array $context = [], string $channel = 'application'): void
    {
        $this->log('error', $message, $context, $channel);
    }

    public function debug(string $message, array $context = [], string $channel = 'application'): void
    {
        $this->log('debug', $message, $context, $channel);
    }

    /**
     * Redact sensitive values from a context array (recursive — all nesting levels).
     *
     * A key is considered sensitive if it contains any of the SENSITIVE_SUBSTRINGS
     * as a case-insensitive substring. When a value is itself an array the method
     * recurses into it so nested sensitive fields are also redacted.
     */
    public function sanitize(array $context): array
    {
        $sanitized = [];

        foreach ($context as $key => $value) {
            if ($this->isSensitiveKey((string) $key)) {
                $sanitized[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $sanitized[$key] = $this->sanitize($value);
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }

    /**
     * Delete log entries whose retention window has expired.
     *
     * @return int Number of rows deleted
     */
    public function prune(): int
    {
        $expired = StructuredLog::where('retained_until', '<', now())->get();

        if ($expired->isEmpty()) {
            return 0;
        }

        return DB::transaction(function () use ($expired): int {
            $deleted = StructuredLog::whereIn('id', $expired->pluck('id'))->delete();

            foreach ($expired as $log) {
                $this->recordAudit(
                    action: AuditAction::Delete,
                    auditableType: StructuredLog::class,
                    auditableId: $log->id,
                    beforeHash: hash('sha256', json_encode($log->toArray())),
                    afterHash: hash('sha256', json_encode([
                        'id'      => $log->id,
                        'deleted' => true,
                    ])),
                );
            }

            return $deleted;
        });
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function isSensitiveKey(string $key): bool
    {
        $lowerKey = strtolower($key);

        foreach (self::SENSITIVE_SUBSTRINGS as $substring) {
            if (str_contains($lowerKey, $substring)) {
                return true;
            }
        }

        return false;
    }

    private function recordAudit(
        AuditAction $action,
        string $auditableType,
        string $auditableId,
        ?string $beforeHash = null,
        ?string $afterHash = null,
    ): void {
        $audit = $this->resolveAuditRepository();

        if ($audit === null) {
            return;
        }

        $idempotencyKey = $this->resolveIdempotencyKey();
        $correlationId  = $idempotencyKey !== null
            ? hash('sha256', $idempotencyKey . ':' . $auditableId . ':' . $action->value)
            : (string) Str::uuid();

        $audit->record(
            correlationId: $correlationId,
            action: $action,
            actorId: $this->resolveActorId(),
            auditableType: $auditableType,
            auditableId: $auditableId,
            beforeHash: $beforeHash,
            afterHash: $afterHash,
            payload: [
                'channel' => 'structured_log',
            ],
            ipAddress: $this->resolveIpAddress(),
        );
    }

    private function resolveAuditRepository(): ?AuditEventRepositoryInterface
    {
        try {
            return app()->bound(AuditEventRepositoryInterface::class)
                ? app(AuditEventRepositoryInterface::class)
                : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function resolveRequestId(): ?string
    {
        try {
            return request()->header('X-Request-ID');
        } catch (\Throwable) {
            return null;
        }
    }

    private function resolveIdempotencyKey(): ?string
    {
        try {
            return request()->header('X-Idempotency-Key');
        } catch (\Throwable) {
            return null;
        }
    }

    private function resolveActorId(): ?string
    {
        try {
            $id = auth()->id();

            return $id !== null ? (string) $id : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function resolveIpAddress(): string
    {
        try {
            return request()->ip() ?? '127.0.0.1';
        } catch (\Throwable) {
            return '127.0.0.1';
        }
    }
}
