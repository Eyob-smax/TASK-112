<?php

namespace App\Infrastructure\Persistence;

use App\Domain\Configuration\Enums\RolloutStatus;
use App\Models\ConfigurationVersion;
use Illuminate\Support\Facades\DB;

/**
 * Handles transactional configuration version operations.
 *
 * CRITICAL INVARIANTS:
 *   - Version number max()+1 is computed INSIDE the DB::transaction — never before it
 *   - DB unique constraint (configuration_set_id, version_number) acts as collision safety net
 */
class EloquentConfigurationRepository
{
    /**
     * Create a new configuration version with atomically assigned version number.
     *
     * The version number is computed as max(existing) + 1 inside a transaction
     * to prevent race conditions when multiple users create versions simultaneously.
     *
     * @param string $configSetId  UUID of the parent configuration set
     * @param array  $versionData  Additional fields (payload, change_summary, created_by)
     */
    public function createVersion(string $configSetId, array $versionData): ConfigurationVersion
    {
        return DB::transaction(function () use ($configSetId, $versionData) {
            $max = ConfigurationVersion::where('configuration_set_id', $configSetId)
                ->max('version_number') ?? 0;

            return ConfigurationVersion::create([
                ...$versionData,
                'configuration_set_id' => $configSetId,
                'version_number'       => $max + 1,
                'status'               => RolloutStatus::Draft->value,
            ]);
        });
    }

    /**
     * Find the currently active version (canary or promoted) for a configuration set.
     *
     * Returns the highest version number that is in an active status.
     */
    public function findActiveVersion(string $configSetId): ?ConfigurationVersion
    {
        return ConfigurationVersion::where('configuration_set_id', $configSetId)
            ->whereIn('status', [
                RolloutStatus::Canary->value,
                RolloutStatus::Promoted->value,
            ])
            ->orderBy('version_number', 'desc')
            ->first();
    }
}
