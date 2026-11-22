<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A person at a customer company (clients row). One can be the primary contact. */
class CrmContact extends Model
{
    use SoftDeletes;

    protected $table = 'crm_contacts';

    protected $fillable = ['client_id', 'name', 'job_title', 'email', 'phone', 'is_primary', 'notes', 'created_by'];

    protected $casts = [
        'client_id' => 'integer',
        'is_primary' => 'boolean',
        'created_by' => 'integer',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class, 'client_id');
    }
}
