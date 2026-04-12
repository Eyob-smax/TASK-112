<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_line_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('sales_document_id');
            $table->unsignedSmallInteger('line_number');   // Sequential within document
            $table->string('product_code', 100);
            $table->string('description', 500);
            $table->decimal('quantity', 12, 4);
            $table->decimal('unit_price', 15, 4);
            $table->decimal('line_total', 15, 2);          // quantity * unit_price
            $table->timestamps();

            $table->foreign('sales_document_id')
                ->references('id')
                ->on('sales_documents')
                ->cascadeOnDelete();

            $table->unique(['sales_document_id', 'line_number']);
            $table->index('sales_document_id');
            $table->index('product_code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_line_items');
    }
};
