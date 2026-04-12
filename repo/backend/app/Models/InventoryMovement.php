<?php

namespace App\Models;

use App\Domain\Sales\Enums\InventoryMovementType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryMovement extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'movement_type',
        'sales_document_id',
        'return_id',
        'product_code',
        'quantity_delta',
        'stock_location',
        'reference_id',
        'created_by',
        'notes',
        'movement_at',
    ];

    protected function casts(): array
    {
        return [
            'movement_type'  => InventoryMovementType::class,
            'quantity_delta' => 'float',
            'movement_at'    => 'datetime',
        ];
    }

    // -------------------------------------------------------------------------
    // Relations
    // -------------------------------------------------------------------------

    public function salesDocument(): BelongsTo
    {
        return $this->belongsTo(SalesDocument::class);
    }

    public function returnRecord(): BelongsTo
    {
        return $this->belongsTo(ReturnRecord::class, 'return_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
