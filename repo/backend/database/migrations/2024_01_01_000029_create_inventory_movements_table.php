<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only ledger of inventory movements.
 *
 * From questions.md (ambiguity #7):
 *   - Returns create compensating inventory movements tied to original sales
 *   - Movements are at inventory-movement level, preserving auditability
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('movement_type', 20);            // App\Domain\Sales\Enums\InventoryMovementType
            $table->uuid('sales_document_id')->nullable();  // Source sale (for return compensation)
            $table->uuid('return_id')->nullable();           // Source return record
            $table->string('product_code', 100);
            $table->decimal('quantity_delta', 12, 4);       // Positive = stock in, Negative = stock out
            $table->string('stock_location', 100)->nullable(); // Physical location or warehouse code
            $table->uuid('reference_id')->nullable();        // Generic reference for audit trail
            $table->uuid('created_by');
            $table->text('notes')->nullable();
            $table->timestamp('movement_at');               // When the movement occurred (business time)
            $table->timestamps();

            $table->foreign('sales_document_id')
                ->references('id')
                ->on('sales_documents')
                ->nullOnDelete();

            $table->foreign('return_id')
                ->references('id')
                ->on('returns')
                ->nullOnDelete();

            $table->foreign('created_by')->references('id')->on('users')->restrictOnDelete();

            $table->index('product_code');
            $table->index('movement_type');
            $table->index('movement_at');
            $table->index('sales_document_id');
            $table->index('return_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
    }
};
