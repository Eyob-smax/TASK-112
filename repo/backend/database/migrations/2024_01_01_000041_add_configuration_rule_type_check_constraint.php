<?php

use App\Domain\Configuration\Enums\PolicyType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->addCheckConstraint(
            table: 'configuration_rules',
            name: 'chk_configuration_rules_rule_type_allowed',
            expression: sprintf(
                "rule_type IN (%s)",
                implode(',', array_map(
                    static fn (PolicyType $type): string => "'" . $type->value . "'",
                    PolicyType::cases(),
                ))
            )
        );
    }

    public function down(): void
    {
        $this->dropCheckConstraint('configuration_rules', 'chk_configuration_rules_rule_type_allowed');
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
