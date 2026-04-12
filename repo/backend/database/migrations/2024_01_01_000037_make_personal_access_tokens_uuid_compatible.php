<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const MORPH_INDEX = 'personal_access_tokens_tokenable_type_tokenable_id_index';

    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        if (!Schema::hasTable('personal_access_tokens') || !Schema::hasColumn('personal_access_tokens', 'tokenable_id')) {
            return;
        }

        $column = DB::selectOne("SHOW COLUMNS FROM personal_access_tokens LIKE 'tokenable_id'");
        $type = strtolower((string) ($column->Type ?? ''));

        if ($type === '' || str_contains($type, 'char(36)')) {
            return;
        }

        $this->dropMorphIndex();

        DB::statement('ALTER TABLE personal_access_tokens MODIFY tokenable_id CHAR(36) NOT NULL');

        $this->createMorphIndex();
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        if (!Schema::hasTable('personal_access_tokens') || !Schema::hasColumn('personal_access_tokens', 'tokenable_id')) {
            return;
        }

        $column = DB::selectOne("SHOW COLUMNS FROM personal_access_tokens LIKE 'tokenable_id'");
        $type = strtolower((string) ($column->Type ?? ''));

        if (!str_contains($type, 'char(36)')) {
            return;
        }

        $this->dropMorphIndex();

        DB::statement('ALTER TABLE personal_access_tokens MODIFY tokenable_id BIGINT UNSIGNED NOT NULL');

        $this->createMorphIndex();
    }

    private function dropMorphIndex(): void
    {
        try {
            DB::statement('ALTER TABLE personal_access_tokens DROP INDEX ' . self::MORPH_INDEX);
        } catch (\Throwable) {
            // Index may already be absent.
        }
    }

    private function createMorphIndex(): void
    {
        try {
            DB::statement(
                'CREATE INDEX ' . self::MORPH_INDEX . ' ON personal_access_tokens (tokenable_type, tokenable_id)'
            );
        } catch (\Throwable) {
            // Index may already exist.
        }
    }
};
