<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('returns', function (Blueprint $table) {
            $table->string('operation_type', 20)->default('return')->after('return_document_number');
            $table->index('operation_type');
        });

        $driver = DB::getDriverName();
        if ($driver === 'mysql' || $driver === 'pgsql') {
            DB::statement("ALTER TABLE returns ADD CONSTRAINT chk_returns_operation_type_allowed CHECK (operation_type IN ('return','exchange'))");
        }

        DB::table('returns')
            ->whereNull('operation_type')
            ->update(['operation_type' => 'return']);
    }

    public function down(): void
    {
        $driver = DB::getDriverName();
        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE returns DROP CHECK chk_returns_operation_type_allowed');
        }
        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE returns DROP CONSTRAINT IF EXISTS chk_returns_operation_type_allowed');
        }

        Schema::table('returns', function (Blueprint $table) {
            $table->dropIndex(['operation_type']);
            $table->dropColumn('operation_type');
        });
    }
};
