<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Car Dealership theme: an enquiry, test-drive booking, trade-in valuation,
 * finance enquiry or reservation sent from the storefront.
 */
class VehicleLead extends Model
{
    protected $table = 'vehicle_leads';

    public const TYPES = ['enquiry', 'test_drive', 'trade_in', 'finance', 'reservation'];

    public const STATUSES = ['new', 'contacted', 'booked', 'won', 'lost'];

    public const TIMES = ['morning', 'afternoon', 'evening'];

    protected $fillable = [
        'type', 'product_id', 'product_name', 'client_id', 'name', 'email', 'phone', 'message',
        'preferred_date', 'preferred_time', 'trade_plate', 'trade_mileage', 'trade_vehicle',
        'finance_deposit', 'finance_term', 'wants_finance', 'has_trade_in', 'status',
        'assigned_to', 'staff_note',
    ];

    protected $casts = [
        'product_id' => 'integer',
        'client_id' => 'integer',
        'assigned_to' => 'integer',
        'trade_mileage' => 'integer',
        'finance_deposit' => 'decimal:2',
        'finance_term' => 'integer',
        'wants_finance' => 'boolean',
        'has_trade_in' => 'boolean',
        'preferred_date' => 'date',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }
}
