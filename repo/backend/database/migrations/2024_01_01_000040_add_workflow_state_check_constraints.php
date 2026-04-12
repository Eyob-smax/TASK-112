<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->addCheckConstraint(
            table: 'workflow_instances',
            name: 'chk_workflow_instances_status_allowed',
            expression: "status IN ('pending','in_progress','approved','rejected','withdrawn','expired')"
        );

        $this->addCheckConstraint(
            table: 'workflow_nodes',
            name: 'chk_workflow_nodes_status_allowed',
            expression: "status IN ('pending','in_progress','approved','rejected','withdrawn','expired')"
        );

        $this->addCheckConstraint(
            table: 'workflow_nodes',
            name: 'chk_workflow_nodes_node_type_allowed',
            expression: "node_type IN ('sequential','parallel','conditional')"
        );
    }

    public function down(): void
    {
        $this->dropCheckConstraint('workflow_nodes', 'chk_workflow_nodes_node_type_allowed');
        $this->dropCheckConstraint('workflow_nodes', 'chk_workflow_nodes_status_allowed');
        $this->dropCheckConstraint('workflow_instances', 'chk_workflow_instances_status_allowed');
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
