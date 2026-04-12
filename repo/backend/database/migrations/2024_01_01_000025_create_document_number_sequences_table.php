<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sequence counter for sales document number generation.
 *
 * From questions.md (ambiguity #6):
 *   - Reset per site per calendar day
 *   - Format: SITE-YYYYMMDD-000001
 *
 * Concurrency note: The application layer must use a SELECT FOR UPDATE
 * or atomic UPDATE + SELECT to safely increment this sequence.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_number_sequences', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('site_code', 10);        // Uppercase alphanumeric site identifier
            $table->date('business_date');           // The date this sequence applies to
            $table->unsignedBigInteger('last_sequence')->default(0); // Current highest issued number
            $table->timestamps();

            // Unique constraint: one sequence per site per day
            $table->unique(['site_code', 'business_date']);

            $table->index('site_code');
            $table->index('business_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_number_sequences');
    }
};
