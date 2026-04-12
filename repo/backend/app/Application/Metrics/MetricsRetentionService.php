<?php

namespace App\Application\Metrics;

use App\Domain\Audit\Contracts\AuditEventRepositoryInterface;
use App\Domain\Audit\Enums\AuditAction;
use App\Models\MetricsSnapshot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Records metrics snapshots and manages their retention lifecycle.
 *
 * Retention window is driven by config('meridian.retention.metrics_days') — default 90 days.
 */
class MetricsRetentionService
{
    /**
     * Record a metric snapshot.
     *
     * @param string $metricType One of: request_timing, queue_depth, failed_approvals
     * @param float  $value      The measured value
     * @param array  $labels     Optional key/value labels for filtering/grouping
     */
    public function record(string $metricType, float $value, array $labels = []): MetricsSnapshot
    {
        $retentionDays = (int) config('meridian.retention.metrics_days', 90);

        return DB::transaction(function () use ($metricType, $value, $labels, $retentionDays): MetricsSnapshot {
            $snapshot = MetricsSnapshot::create([
                'metric_type'    => $metricType,
                'value'          => $value,
                'labels'         => $labels,
                'recorded_at'    => now(),
                'retained_until' => now()->addDays($retentionDays),
            ]);

            $this->recordAudit(
                action: AuditAction::Create,
                auditableType: MetricsSnapshot::class,
                auditableId: $snapshot->id,
                afterHash: hash('sha256', json_encode($snapshot->toArray())),
            );

            return $snapshot;
        });
    }

    /**
     * Delete metrics snapshots whose retention window has expired.
     *
     * @return int Number of rows deleted
     */
    public function pruneExpired(): int
    {
        $expired = MetricsSnapshot::where('retained_until', '<', now())->get();

        if ($expired->isEmpty()) {
            return 0;
        }

        return DB::transaction(function () use ($expired): int {
            $deleted = MetricsSnapshot::whereIn('id', $expired->pluck('id'))->delete();

            foreach ($expired as $snapshot) {
                $this->recordAudit(
                    action: AuditAction::Delete,
                    auditableType: MetricsSnapshot::class,
                    auditableId: $snapshot->id,
                    beforeHash: hash('sha256', json_encode($snapshot->toArray())),
                    afterHash: hash('sha256', json_encode([
                        'id'      => $snapshot->id,
                        'deleted' => true,
                    ])),
                );
            }

            return $deleted;
        });
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
                'channel' => 'metrics',
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
