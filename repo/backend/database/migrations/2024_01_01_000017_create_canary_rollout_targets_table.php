<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const UNIQUE_INDEX = 'canary_targets_cfg_target_uq';
    private const TARGET_LOOKUP_INDEX = 'canary_targets_type_target_idx';

    public function up(): void
    {
        if (Schema::hasTable('canary_rollout_targets')) {
            $this->ensureIndexes();
            return;
        }

        Schema::create('canary_rollout_targets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('configuration_version_id');
            $table->string('target_type', 20);  // 'store' | 'user'
            $table->uuid('target_id');           // Store UUID or User UUID
            $table->timestamp('assigned_at');
            $table->timestamps();

            $table->foreign('configuration_version_id')
                ->references('id')
                ->on('configuration_versions')
                ->cascadeOnDelete();

            // Prevent duplicate assignments
            $table->unique(['configuration_version_id', 'target_type', 'target_id'], self::UNIQUE_INDEX);

            $table->index(['target_type', 'target_id'], self::TARGET_LOOKUP_INDEX);
        });
    }

    private function ensureIndexes(): void
    {
        if (!$this->indexExists('canary_rollout_targets', self::UNIQUE_INDEX)) {
            Schema::table('canary_rollout_targets', function (Blueprint $table) {
                $table->unique(['configuration_version_id', 'target_type', 'target_id'], self::UNIQUE_INDEX);
            });
        }

        if (!$this->indexExists('canary_rollout_targets', self::TARGET_LOOKUP_INDEX)) {
            Schema::table('canary_rollout_targets', function (Blueprint $table) {
                $table->index(['target_type', 'target_id'], self::TARGET_LOOKUP_INDEX);
            });
        }
    }

    private function indexExists(string $tableName, string $indexName): bool
    {
        return DB::table('information_schema.statistics')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', $tableName)
            ->where('index_name', $indexName)
            ->exists();
    }

    public function down(): void
    {
        Schema::dropIfExists('canary_rollout_targets');
    }
};
