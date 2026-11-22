<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RentalContractAttachment extends Model
{
    protected $table = 'rental_contract_attachments';

    protected $fillable = [
        'rental_contract_id', 'title', 'file_name', 'original_name', 'mime', 'size', 'uploaded_by',
    ];

    protected $casts = [
        'rental_contract_id' => 'integer',
        'size' => 'integer',
        'uploaded_by' => 'integer',
    ];
}
