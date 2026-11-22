<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The rental profile of an ordinary Stocky client. Name, phone, email and
 * address stay on the client; this row adds identity, work, emergency contact,
 * guarantor and standing (blacklist / rating).
 */
class RentalCustomer extends Model
{
    use SoftDeletes;

    protected $table = 'rental_customers';

    /** Upload directory, relative to public/. */
    public const FILE_DIR = 'rental_customers'; // under upload_path(): images/tenants/{id}/rental_customers

    public const ID_TYPES = ['national_id', 'passport', 'driving_license', 'residence_permit', 'other'];

    protected $fillable = [
        'client_id',
        'id_type', 'id_number', 'id_expiry', 'id_front', 'id_back', 'date_of_birth', 'nationality',
        'occupation', 'employer', 'employer_phone', 'monthly_income',
        'emergency_name', 'emergency_phone', 'emergency_relation',
        'guarantor_name', 'guarantor_phone', 'guarantor_id_number', 'guarantor_relation', 'guarantor_address',
        'is_blacklisted', 'blacklist_reason', 'blacklisted_at', 'blacklisted_by', 'rating',
        'notes', 'created_by',
    ];

    protected $casts = [
        'client_id' => 'integer',
        'id_expiry' => 'date',
        'date_of_birth' => 'date',
        'monthly_income' => 'decimal:2',
        'is_blacklisted' => 'boolean',
        'blacklisted_at' => 'datetime',
        'blacklisted_by' => 'integer',
        'rating' => 'integer',
        'created_by' => 'integer',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class, 'client_id', 'id');
    }

    public function documents()
    {
        return $this->hasMany(RentalCustomerDocument::class, 'rental_customer_id', 'id');
    }

    public static function fileUrl(?string $file): ?string
    {
        return $file ? url(upload_path(self::FILE_DIR) . '/' . $file) : null;
    }

    /**
     * missing | expired | expiring | valid — `expiring` uses the module's
     * "expiring soon" window from Rental Settings.
     */
    public function idStatus(?int $soonDays = null): string
    {
        if (! $this->id_number && ! $this->id_expiry) {
            return 'missing';
        }
        if (! $this->id_expiry) {
            return 'valid';
        }

        $soonDays = $soonDays ?? (int) RentalSetting::current()->expiring_soon_days;
        $today = Carbon::today();

        if ($this->id_expiry->lt($today)) {
            return 'expired';
        }

        return $this->id_expiry->lte($today->copy()->addDays($soonDays)) ? 'expiring' : 'valid';
    }
}
