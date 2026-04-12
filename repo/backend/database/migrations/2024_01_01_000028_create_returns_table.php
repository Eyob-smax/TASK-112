<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('returns', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('sales_document_id');             // Original sale being returned
            $table->string('return_document_number', 30)->unique(); // Same format as sales doc
            $table->string('reason_code', 30);             // App\Domain\Sales\Enums\ReturnReasonCode
            $table->text('reason_detail')->nullable();
            $table->boolean('is_defective')->default(false);

            // Restock fee calculation — stored at decision time, immutable after completion
            $table->decimal('restock_fee_percent', 5, 2)->default(10);
            $table->decimal('restock_fee_amount', 15, 2)->default(0);
            $table->decimal('return_amount', 15, 2);       // Value of items being returned
            $table->decimal('refund_amount', 15, 2);       // return_amount - restock_fee_amount

            $table->string('status', 20)->default('pending'); // pending | completed | rejected
            $table->timestamp('completed_at')->nullable();

            $table->uuid('created_by');
            $table->uuid('completed_by')->nullable();
            $table->timestamps();

            $table->foreign('sales_document_id')
                ->references('id')
                ->on('sales_documents')
                ->restrictOnDelete();

            $table->foreign('created_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('completed_by')->references('id')->on('users')->nullOnDelete();

            $table->index('sales_document_id');
            $table->index('status');
            $table->index('reason_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('returns');
    }
};
