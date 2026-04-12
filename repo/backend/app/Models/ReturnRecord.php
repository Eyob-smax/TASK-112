<?php

namespace App\Models;

use App\Domain\Sales\Enums\ReturnReasonCode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReturnRecord extends Model
{
    use HasUuids;

    /**
     * Map to the 'returns' table.
     * Cannot name the class 'Return' — reserved keyword in PHP.
     */
    protected $table = 'returns';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'sales_document_id',
        'return_document_number',
        'operation_type',
        'reason_code',
        'reason_detail',
        'is_defective',
        'restock_fee_percent',
        'restock_fee_amount',
        'return_amount',
        'refund_amount',
        'status',
        'completed_at',
        'completed_by',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'reason_code'        => ReturnReasonCode::class,
            'is_defective'       => 'boolean',
            'restock_fee_percent' => 'float',
            'restock_fee_amount' => 'float',
            'return_amount'      => 'float',
            'refund_amount'      => 'float',
            'completed_at'       => 'datetime',
        ];
    }

    // -------------------------------------------------------------------------
    // Relations
    // -------------------------------------------------------------------------

    public function salesDocument(): BelongsTo
    {
        return $this->belongsTo(SalesDocument::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
