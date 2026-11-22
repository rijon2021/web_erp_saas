<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RentalCustomerDocument extends Model
{
    protected $table = 'rental_customer_documents';

    protected $fillable = [
        'rental_customer_id', 'title', 'file_name', 'original_name', 'mime', 'size', 'uploaded_by',
    ];

    protected $casts = [
        'rental_customer_id' => 'integer',
        'size' => 'integer',
        'uploaded_by' => 'integer',
    ];

    public function customer()
    {
        return $this->belongsTo(RentalCustomer::class, 'rental_customer_id', 'id');
    }
}
