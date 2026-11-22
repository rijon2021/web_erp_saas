<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A rental agreement: one rental customer, one or more rental items, a date
 * range and a price per `rate_period`. Lifecycle and money rules live in
 * App\Services\Rental\RentalContractService.
 */
class RentalContract extends Model
{
    use SoftDeletes;

    protected $table = 'rental_contracts';

    /** Upload directory (signatures, attachments), relative to public/. */
    public const FILE_DIR = 'rental_contracts'; // under upload_path(): images/tenants/{id}/rental_contracts

    public const STATUSES = ['draft', 'active', 'ended', 'terminated', 'renewed', 'cancelled'];
    /** Statuses that are over — nothing can change any more. */
    public const CLOSED = ['ended', 'terminated', 'renewed', 'cancelled'];

    protected $fillable = [
        'contract_number', 'rental_customer_id', 'client_id', 'warehouse_id', 'status',
        'start_date', 'end_date', 'rate_period', 'billing_type', 'periods',
        'rent_amount', 'discount', 'tax_rate', 'tax_amount', 'total_amount', 'contract_value', 'deposit_amount',
        'grace_days', 'late_fee_type', 'late_fee_value', 'late_fee_frequency',
        'terms', 'notes', 'signed_name', 'signature', 'signed_at',
        'activated_at', 'checked_out_at', 'returned_at', 'ended_at', 'terminated_at', 'termination_reason', 'cancelled_at',
        'renewed_from_id', 'renewal_id', 'created_by',
    ];

    protected $casts = [
        'rental_customer_id' => 'integer',
        'client_id' => 'integer',
        'warehouse_id' => 'integer',
        'start_date' => 'datetime',
        'end_date' => 'datetime',
        'periods' => 'integer',
        'rent_amount' => 'decimal:2',
        'discount' => 'decimal:2',
        'tax_rate' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'contract_value' => 'decimal:2',
        'deposit_amount' => 'decimal:2',
        'grace_days' => 'integer',
        'late_fee_value' => 'decimal:2',
        'signed_at' => 'datetime',
        'activated_at' => 'datetime',
        'checked_out_at' => 'datetime',
        'returned_at' => 'datetime',
        'ended_at' => 'datetime',
        'terminated_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'renewed_from_id' => 'integer',
        'renewal_id' => 'integer',
        'created_by' => 'integer',
    ];

    public function customer()
    {
        return $this->belongsTo(RentalCustomer::class, 'rental_customer_id', 'id');
    }

    public function client()
    {
        return $this->belongsTo(Client::class, 'client_id', 'id');
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id', 'id');
    }

    public function items()
    {
        return $this->hasMany(RentalContractItem::class, 'rental_contract_id', 'id');
    }

    public function events()
    {
        return $this->hasMany(RentalContractEvent::class, 'rental_contract_id', 'id');
    }

    public function attachments()
    {
        return $this->hasMany(RentalContractAttachment::class, 'rental_contract_id', 'id');
    }

    public function handovers()
    {
        return $this->hasMany(RentalHandover::class, 'rental_contract_id', 'id');
    }

    public function deposits()
    {
        return $this->hasMany(RentalDepositTransaction::class, 'rental_contract_id', 'id');
    }

    /**
     * Deposit still held: collected - refunded - applied - retained.
     * Pass a loaded collection to avoid a query.
     */
    public function depositHeld($transactions = null): float
    {
        $rows = $transactions ?? $this->deposits()->whereNull('deleted_at')->get();
        $sum = fn ($type) => (float) $rows->where('type', $type)->sum('amount');

        return round($sum('collected') - $sum('refunded') - $sum('applied') - $sum('retained'), 2);
    }

    public function renewedFrom()
    {
        return $this->belongsTo(self::class, 'renewed_from_id', 'id');
    }

    public function renewal()
    {
        return $this->belongsTo(self::class, 'renewal_id', 'id');
    }

    public function isClosed(): bool
    {
        return in_array($this->status, self::CLOSED, true);
    }

    /**
     * The badge the UI shows: the stored status, refined by the dates while
     * the contract is active — upcoming (not started), overdue (end passed),
     * expiring (ends within the "expiring soon" window).
     */
    public function stage(?int $soonDays = null): string
    {
        if ($this->status !== 'active') {
            return $this->status;
        }

        $now = Carbon::now();
        if ($this->start_date && $this->start_date->gt($now)) {
            return 'upcoming';
        }
        if ($this->end_date) {
            if ($this->end_date->lt($now)) {
                return 'overdue';
            }
            $soonDays = $soonDays ?? (int) RentalSetting::current()->expiring_soon_days;
            if ($this->end_date->lte($now->copy()->addDays($soonDays))) {
                return 'expiring';
            }
        }

        return 'active';
    }

    public static function fileUrl(?string $file): ?string
    {
        return $file ? url(upload_path(self::FILE_DIR) . '/' . $file) : null;
    }
}
