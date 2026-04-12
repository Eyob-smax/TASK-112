<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const UNIQUE_INDEX = 'cfg_versions_set_ver_uq';
    private const STATUS_INDEX = 'cfg_versions_status_idx';
    private const CANARY_STARTED_INDEX = 'cfg_versions_canary_started_idx';

    public function up(): void
    {
        if (Schema::hasTable('configuration_versions')) {
            $this->ensureIndexes();
            return;
        }

        Schema::create('configuration_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('configuration_set_id');
            $table->unsignedInteger('version_number');
            $table->json('payload');                              // Full configuration payload
            $table->string('status', 20)->default('draft');      // App\Domain\Configuration\Enums\RolloutStatus

            // Canary rollout tracking
            $table->string('canary_target_type', 20)->nullable(); // 'store' | 'user'
            $table->unsignedInteger('canary_target_count')->default(0);
            $table->unsignedInteger('canary_eligible_count')->default(0);
            $table->decimal('canary_percent', 5, 2)->default(0);
            $table->timestamp('canary_started_at')->nullable();
            $table->timestamp('promoted_at')->nullable();
            $table->timestamp('rolled_back_at')->nullable();
            $table->timestamp('activated_at')->nullable();

            $table->uuid('created_by');
            $table->text('change_summary')->nullable();
            $table->timestamps();

            $table->foreign('configuration_set_id')
                ->references('id')
                ->on('configuration_sets')
                ->cascadeOnDelete();

            $table->foreign('created_by')->references('id')->on('users')->restrictOnDelete();

            // Unique: only one version number per configuration set
            $table->unique(['configuration_set_id', 'version_number'], self::UNIQUE_INDEX);

            $table->index('status', self::STATUS_INDEX);
            $table->index('canary_started_at', self::CANARY_STARTED_INDEX);
        });
    }

    private function ensureIndexes(): void
    {
        if (!$this->indexExists('configuration_versions', self::UNIQUE_INDEX)) {
            Schema::table('configuration_versions', function (Blueprint $table) {
                $table->unique(['configuration_set_id', 'version_number'], self::UNIQUE_INDEX);
            });
        }

        if (!$this->indexExists('configuration_versions', self::STATUS_INDEX)) {
            Schema::table('configuration_versions', function (Blueprint $table) {
                $table->index('status', self::STATUS_INDEX);
            });
        }

        if (!$this->indexExists('configuration_versions', self::CANARY_STARTED_INDEX)) {
            Schema::table('configuration_versions', function (Blueprint $table) {
                $table->index('canary_started_at', self::CANARY_STARTED_INDEX);
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
        Schema::dropIfExists('configuration_versions');
    }
};
