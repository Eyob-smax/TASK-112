<?php

namespace App\Jobs;

use App\Application\Logging\StructuredLogger;
use App\Domain\Audit\Contracts\AuditEventRepositoryInterface;
use App\Domain\Audit\Enums\AuditAction;
use App\Models\AttachmentLink;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Prunes expired, consumed, and revoked share links from the database.
 *
 * Scheduled every 15 minutes.
 *
 * LAN share-link expiry is enforced at runtime by ExpiryEvaluator::isLinkExpired()
 * checking expires_at.isPast(). This job performs offline cleanup — deleting
 * records that are no longer usable (expired, consumed, or revoked) and are
 * older than the cleanup grace period (24 hours) to allow admin/audit queries
 * to see recent terminations before removal.
 *
 * Audit provenance is maintained in the audit_events table via LinkConsume and
 * LinkRevoke actions recorded at resolution/revocation time; physical deletion
 * of the link record after the grace period does not break audit continuity.
 */
class ExpireAttachmentLinksJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Grace period after expiry/consumption before physical deletion (hours).
     */
    private const CLEANUP_GRACE_HOURS = 24;

    public function handle(StructuredLogger $logger, AuditEventRepositoryInterface $audit): void
    {
        $cutoff = now()->subHours(self::CLEANUP_GRACE_HOURS);

        // Expired by TTL — no prior audit event exists for these; emit one per link before deletion.
        $expiredLinks = AttachmentLink::query()
            ->where('expires_at', '<', $cutoff)
            ->whereNull('revoked_at')
            ->whereNull('consumed_at')
            ->get();

        $expiredCount = 0;
        foreach ($expiredLinks as $link) {
            $beforeHash = hash('sha256', json_encode($link->toArray()));
            $audit->record(
                correlationId: hash('sha256', 'expire_link:' . $link->id),
                action:        AuditAction::Delete,
                actorId:       null,
                auditableType: AttachmentLink::class,
                auditableId:   $link->id,
                beforeHash:    $beforeHash,
                afterHash:     $beforeHash,
                payload:       ['reason' => 'ttl_expired'],
                ipAddress:     '127.0.0.1',
            );
            $link->delete();
            $expiredCount++;
        }

        // Consumed single-use links — physical cleanup; prior LinkConsume audit event exists at
        // consume time, but strict every-write-auditable requires a Delete event here too.
        $consumedLinks = AttachmentLink::query()
            ->whereNotNull('consumed_at')
            ->where('consumed_at', '<', $cutoff)
            ->get();

        $consumedCount = 0;
        foreach ($consumedLinks as $link) {
            $beforeHash = hash('sha256', json_encode($link->toArray()));
            $audit->record(
                correlationId: hash('sha256', 'prune_consumed_link:' . $link->id),
                action:        AuditAction::Delete,
                actorId:       null,
                auditableType: AttachmentLink::class,
                auditableId:   $link->id,
                beforeHash:    $beforeHash,
                afterHash:     $beforeHash,
                payload:       ['reason' => 'consumed_link_pruned'],
                ipAddress:     '127.0.0.1',
            );
            $link->delete();
            $consumedCount++;
        }

        // Revoked links — physical cleanup; prior LinkRevoke audit event exists at
        // revocation time, but strict every-write-auditable requires a Delete event here too.
        $revokedLinks = AttachmentLink::query()
            ->whereNotNull('revoked_at')
            ->where('revoked_at', '<', $cutoff)
            ->get();

        $revokedCount = 0;
        foreach ($revokedLinks as $link) {
            $beforeHash = hash('sha256', json_encode($link->toArray()));
            $audit->record(
                correlationId: hash('sha256', 'prune_revoked_link:' . $link->id),
                action:        AuditAction::Delete,
                actorId:       null,
                auditableType: AttachmentLink::class,
                auditableId:   $link->id,
                beforeHash:    $beforeHash,
                afterHash:     $beforeHash,
                payload:       ['reason' => 'revoked_link_pruned'],
                ipAddress:     '127.0.0.1',
            );
            $link->delete();
            $revokedCount++;
        }

        $total = $expiredCount + $consumedCount + $revokedCount;

        if ($total > 0) {
            $logger->info('Expired/consumed/revoked attachment links pruned', [
                'expired_count'  => $expiredCount,
                'consumed_count' => $consumedCount,
                'revoked_count'  => $revokedCount,
            ], 'maintenance');
        }
    }
}
