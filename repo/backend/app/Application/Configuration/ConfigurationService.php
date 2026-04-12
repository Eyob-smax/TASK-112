<?php

namespace App\Application\Configuration;

use App\Domain\Audit\Contracts\AuditEventRepositoryInterface;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Configuration\Enums\RolloutStatus;
use App\Domain\Configuration\ValueObjects\CanaryConstraint;
use App\Exceptions\Configuration\CanaryCapExceededException;
use App\Exceptions\Configuration\CanaryNotReadyToPromoteException;
use App\Exceptions\Configuration\InvalidRolloutTransitionException;
use App\Infrastructure\Persistence\EloquentConfigurationRepository;
use App\Models\CanaryRolloutTarget;
use App\Models\ConfigurationRule;
use App\Models\ConfigurationSet;
use App\Models\ConfigurationVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Orchestrates configuration set and version lifecycle operations.
 *
 * Responsibilities:
 *   - Create and manage configuration sets
 *   - Create immutable, versioned configuration snapshots
 *   - Enforce canary rollout constraints (10% cap, 24h window)
 *   - Manage rollout state transitions: draft → canary → promoted / rolled_back
 *   - Record audit events for all configuration changes and rollout actions
 *
 * CRITICAL INVARIANTS:
 *   - 10% canary cap validated BEFORE any DB write
 *   - 24h promotion window enforced via CanaryConstraint::canPromote() with DateTimeImmutable
 *   - Version numbering delegated to repository (transactional max()+1)
 */
class ConfigurationService
{
    public function __construct(
        private readonly EloquentConfigurationRepository $repo,
        private readonly AuditEventRepositoryInterface $audit,
    ) {}

    // -------------------------------------------------------------------------
    // Configuration Sets
    // -------------------------------------------------------------------------

    /**
     * Create a new configuration set.
     */
    public function createSet(User $user, array $data, string $ipAddress): ConfigurationSet
    {
        $set = ConfigurationSet::create([
            'name'          => $data['name'],
            'description'   => $data['description'] ?? null,
            'department_id' => $data['department_id'] ?? null,
            'created_by'    => $user->id,
            'is_active'     => true,
        ]);

        $afterHash = hash('sha256', json_encode($set->toArray()));
        $this->recordAudit(AuditAction::Create, $user->id, ConfigurationSet::class, $set->id, $ipAddress, afterHash: $afterHash);

        return $set->load(['department', 'createdBy']);
    }

    // -------------------------------------------------------------------------
    // Configuration Versions
    // -------------------------------------------------------------------------

    /**
     * Create a new draft version for a configuration set.
     *
     * Optionally includes a set of typed rules (policy, coupon, etc.) as child records.
     */
    public function createVersion(
        User $user,
        ConfigurationSet $set,
        array $data,
        string $ipAddress
    ): ConfigurationVersion {
        $version = $this->repo->createVersion($set->id, [
            'payload'        => $data['payload'],
            'change_summary' => $data['change_summary'] ?? null,
            'created_by'     => $user->id,
        ]);

        // Create individual rule records if provided
        if (!empty($data['rules'])) {
            foreach ($data['rules'] as $rule) {
                ConfigurationRule::create([
                    'configuration_version_id' => $version->id,
                    'rule_type'                => $rule['rule_type'],
                    'rule_key'                 => $rule['rule_key'],
                    'rule_value'               => $rule['rule_value'],
                    'is_active'                => $rule['is_active'] ?? true,
                    'priority'                 => $rule['priority'] ?? 0,
                    'description'              => $rule['description'] ?? null,
                ]);
            }
        }

        $afterHash = hash('sha256', json_encode($version->toArray()));
        $this->recordAudit(AuditAction::Create, $user->id, ConfigurationVersion::class, $version->id, $ipAddress, afterHash: $afterHash);

        return $version->load(['configurationSet', 'rules']);
    }

    // -------------------------------------------------------------------------
    // Rollout Lifecycle
    // -------------------------------------------------------------------------

    /**
     * Start a canary rollout for a draft version.
     *
     * Validates:
     *   1. Status must be Draft (only Draft → Canary is allowed)
     *   2. Selected targets must not exceed 10% of the eligible population
     *
     * @param string   $targetType    'store' or 'user'
     * @param string[] $targetIds     UUIDs of the targets to receive this version
     * @param int      $eligibleCount Total eligible population count for cap calculation
     *
     * @throws InvalidRolloutTransitionException If not in Draft status
     * @throws CanaryCapExceededException        If selected targets exceed 10% cap
     */
    public function startCanaryRollout(
        User $user,
        ConfigurationVersion $version,
        string $targetType,
        array $targetIds,
        int $eligibleCount,
        string $ipAddress
    ): ConfigurationVersion {
        // 1. Validate status transition
        if (!$version->status->canTransitionTo(RolloutStatus::Canary)) {
            throw new InvalidRolloutTransitionException(
                $version->status->value,
                RolloutStatus::Canary->value
            );
        }

        // 2. Enforce 10% canary cap — validated before any DB write
        $requested = count($targetIds);
        if (!CanaryConstraint::isWithinCap($requested, $eligibleCount)) {
            throw new CanaryCapExceededException(
                $requested,
                CanaryConstraint::maxTargets($eligibleCount)
            );
        }

        $percent = CanaryConstraint::computePercent($requested, $eligibleCount);

        $beforeHash = hash('sha256', json_encode($version->toArray()));

        // 3. Atomically update version and create canary targets
        DB::transaction(function () use ($version, $targetType, $targetIds, $requested, $eligibleCount, $percent) {
            $version->update([
                'status'                => RolloutStatus::Canary->value,
                'canary_target_type'    => $targetType,
                'canary_target_count'   => $requested,
                'canary_eligible_count' => $eligibleCount,
                'canary_percent'        => $percent,
                'canary_started_at'     => now(),
                'activated_at'          => now(),
            ]);

            foreach ($targetIds as $targetId) {
                CanaryRolloutTarget::create([
                    'configuration_version_id' => $version->id,
                    'target_type'              => $targetType,
                    'target_id'                => $targetId,
                    'assigned_at'              => now(),
                ]);
            }
        });

        $afterHash = hash('sha256', json_encode($version->fresh()->toArray()));
        $this->recordAudit(AuditAction::RolloutStart, $user->id, ConfigurationVersion::class, $version->id, $ipAddress, beforeHash: $beforeHash, afterHash: $afterHash);

        return $version->fresh()->load(['canaryTargets']);
    }

    /**
     * Promote a canary version to full production rollout.
     *
     * Validates:
     *   1. Status must be Canary
     *   2. Canary must have been running for at least 24 hours
     *
     * @throws InvalidRolloutTransitionException    If not in Canary status
     * @throws CanaryNotReadyToPromoteException     If 24h window has not yet elapsed
     */
    public function promoteVersion(
        User $user,
        ConfigurationVersion $version,
        string $ipAddress
    ): ConfigurationVersion {
        // 1. Status guard: only Canary can be promoted
        if (!$version->status->canPromote()) {
            throw new InvalidRolloutTransitionException(
                $version->status->value,
                RolloutStatus::Promoted->value
            );
        }

        // 2. 24h window guard
        $canaryStartedAt = \DateTimeImmutable::createFromMutable(
            $version->canary_started_at->toDateTime()
        );
        $now = new \DateTimeImmutable();

        if (!CanaryConstraint::canPromote($canaryStartedAt, $now)) {
            $earliestAt = CanaryConstraint::earliestPromotionAt($canaryStartedAt);
            throw new CanaryNotReadyToPromoteException($earliestAt);
        }

        $beforeHash = hash('sha256', json_encode($version->toArray()));
        $version->update([
            'status'       => RolloutStatus::Promoted->value,
            'promoted_at'  => now(),
            'activated_at' => now(),
        ]);
        $afterHash = hash('sha256', json_encode($version->fresh()->toArray()));

        $this->recordAudit(AuditAction::RolloutPromote, $user->id, ConfigurationVersion::class, $version->id, $ipAddress, beforeHash: $beforeHash, afterHash: $afterHash);

        return $version->fresh();
    }

    /**
     * Roll back a canary or promoted version.
     *
     * Allowed from Canary or Promoted status (both can transition to RolledBack).
     *
     * @throws InvalidRolloutTransitionException If rollback is not permitted from current status
     */
    public function rollbackVersion(
        User $user,
        ConfigurationVersion $version,
        string $ipAddress
    ): ConfigurationVersion {
        if (!$version->status->canTransitionTo(RolloutStatus::RolledBack)) {
            throw new InvalidRolloutTransitionException(
                $version->status->value,
                RolloutStatus::RolledBack->value
            );
        }

        $beforeHash = hash('sha256', json_encode($version->toArray()));
        $version->update([
            'status'         => RolloutStatus::RolledBack->value,
            'rolled_back_at' => now(),
        ]);
        $afterHash = hash('sha256', json_encode($version->fresh()->toArray()));

        $this->recordAudit(AuditAction::RolloutBack, $user->id, ConfigurationVersion::class, $version->id, $ipAddress, beforeHash: $beforeHash, afterHash: $afterHash);

        return $version->fresh();
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function recordAudit(
        AuditAction $action,
        ?string $actorId,
        string $auditableType,
        string $auditableId,
        string $ipAddress,
        array $payload = [],
        ?string $beforeHash = null,
        ?string $afterHash = null,
    ): void {
        $idempotencyKey = request()->header('X-Idempotency-Key');
        $correlationId  = $idempotencyKey !== null
            ? hash('sha256', $idempotencyKey . ':' . $auditableId . ':' . $action->value)
            : (string) Str::uuid();

        $this->audit->record(
            correlationId:  $correlationId,
            action:         $action,
            actorId:        $actorId,
            auditableType:  $auditableType,
            auditableId:    $auditableId,
            beforeHash:     $beforeHash,
            afterHash:      $afterHash,
            payload:        $payload,
            ipAddress:      $ipAddress,
        );
    }
}
