<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Individual rules within a configuration version.
 *
 * Covers: coupon/promotion rules, purchase limits, blacklist/whitelist entries,
 * campaign rules, landing topics, ad slots, homepage modules.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('configuration_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('configuration_version_id');
            $table->string('rule_type', 30);       // App\Domain\Configuration\Enums\PolicyType
            $table->string('rule_key', 255);        // Rule identifier within its type
            $table->json('rule_value');              // The rule payload (flexible JSON)
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('priority')->default(0); // Higher = evaluated first
            $table->text('description')->nullable();
            $table->timestamps();

            $table->foreign('configuration_version_id')
                ->references('id')
                ->on('configuration_versions')
                ->cascadeOnDelete();

            $table->index('configuration_version_id');
            $table->index('rule_type');
            $table->index(['rule_type', 'rule_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('configuration_rules');
    }
};
