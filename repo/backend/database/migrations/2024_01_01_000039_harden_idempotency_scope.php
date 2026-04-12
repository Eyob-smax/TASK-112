<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $legacyActorScopeHash = hash('sha256', 'legacy:global');
        $legacyRequestHash    = hash('sha256', 'legacy:unknown');

        Schema::table('idempotency_keys', function (Blueprint $table) use ($legacyActorScopeHash, $legacyRequestHash) {
            $table->string('actor_scope_hash', 64)->default($legacyActorScopeHash)->after('key_hash');
            $table->string('request_hash', 64)->default($legacyRequestHash)->after('request_path');
        });

        Schema::table('idempotency_keys', function (Blueprint $table) {
            $table->dropUnique('idempotency_keys_scope_unique');
            $table->unique(
                ['key_hash', 'actor_scope_hash', 'http_method', 'request_path'],
                'idempotency_keys_scope_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('idempotency_keys', function (Blueprint $table) {
            $table->dropUnique('idempotency_keys_scope_unique');
            $table->unique(['key_hash', 'http_method', 'request_path'], 'idempotency_keys_scope_unique');
            $table->dropColumn(['actor_scope_hash', 'request_hash']);
        });
    }
};
