<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->addCheckConstraint(
            table: 'documents',
            name: 'chk_documents_status_allowed',
            expression: "status IN ('draft','published','archived')"
        );

        $this->addCheckConstraint(
            table: 'configuration_versions',
            name: 'chk_configuration_versions_status_allowed',
            expression: "status IN ('draft','canary','promoted','rolled_back')"
        );

        $this->addCheckConstraint(
            table: 'sales_documents',
            name: 'chk_sales_documents_status_allowed',
            expression: "status IN ('draft','reviewed','completed','voided')"
        );

        $this->addCheckConstraint(
            table: 'returns',
            name: 'chk_returns_status_allowed',
            expression: "status IN ('pending','completed','rejected')"
        );
    }

    public function down(): void
    {
        $this->dropCheckConstraint('returns', 'chk_returns_status_allowed');
        $this->dropCheckConstraint('sales_documents', 'chk_sales_documents_status_allowed');
        $this->dropCheckConstraint('configuration_versions', 'chk_configuration_versions_status_allowed');
        $this->dropCheckConstraint('documents', 'chk_documents_status_allowed');
    }

    private function addCheckConstraint(string $table, string $name, string $expression): void
    {
        $driver = DB::getDriverName();

        if ($driver !== 'mysql' && $driver !== 'pgsql') {
            return;
        }

        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$expression})");
    }

    private function dropCheckConstraint(string $table, string $name): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE {$table} DROP CHECK {$name}");
            return;
        }

        if ($driver === 'pgsql') {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$name}");
        }
    }
};
