<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class DocumentNumberSequence extends Model
{
    use HasUuids;

    protected $table = 'document_number_sequences';

    protected $fillable = [
        'site_code',
        'business_date',
        'last_sequence',
    ];

    protected $casts = [
        'last_sequence' => 'integer',
        'business_date' => 'date',
    ];
}
