<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A check-out (items leave) or check-in (items come back) on a contract. */
class RentalHandover extends Model
{
    protected $table = 'rental_handovers';

    /** Photos + signature directory, relative to public/. */
    public const FILE_DIR = 'rental_handovers'; // under upload_path(): images/tenants/{id}/rental_handovers

    protected $fillable = [
        'rental_contract_id', 'type', 'happened_at', 'checklist', 'photos',
        'signature', 'signed_name', 'notes', 'charges_total', 'user_id',
    ];

    protected $casts = [
        'rental_contract_id' => 'integer',
        'happened_at' => 'datetime',
        'checklist' => 'array',
        'photos' => 'array',
        'charges_total' => 'decimal:2',
        'user_id' => 'integer',
    ];

    public function items()
    {
        return $this->hasMany(RentalHandoverItem::class, 'rental_handover_id', 'id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public static function fileUrl(?string $file): ?string
    {
        return $file ? url(upload_path(self::FILE_DIR) . '/' . $file) : null;
    }
}
