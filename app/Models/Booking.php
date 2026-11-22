<?php

namespace App\Models;

use App\Observers\BookingObserver;
use App\Traits\HasShareToken;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Booking extends Model
{
    use HasFactory, HasShareToken, SoftDeletes;

    const SHARE_TYPE = 'booking';

    /** Where the booking came from: entered by staff, or sent from the store website. */
    const SOURCES = ['staff', 'website'];

    /** Website booking modes: the customer picked a free time, or sent a request with a preferred time. */
    const MODES = ['slot', 'request'];

    protected static function booted(): void
    {
        static::observe(BookingObserver::class);
    }

    protected $dates = ['booking_date', 'deleted_at'];

    protected $fillable = [
        'Ref',
        'customer_id',
        'product_id',
        'tray_id',
        'sales_person_id',
        'product_name',
        'product_description',
        'price',
        'booking_date',
        'booking_time',
        'booking_end_time',
        'delivery_date',
        'delivery_time',
        'delivery_address',
        'amount',
        'status',
        'notes',
        'google_calendar_event_id',
        // Booking Management upgrade (migration 2026_10_25_000003)
        'source',
        'booking_mode',
        'business_type',
        'staff_id',
        'warehouse_id',
        'duration_minutes',
        'seats',
        'preferred_date',
        'preferred_window',
        'request_fields',
        'public_token',
        'hold_until',
        'confirmed_at',
        'cancelled_at',
        'cancelled_by',
        'cancel_reason',
        'ecommerce_client_id',
        // Phase 4 (migration 2026_10_25_000006)
        'sale_id',
        'confirmed_by',
        'arrived_at',
        'completed_at',
        // Phase 6 (migration 2026_10_25_000007)
        'reminder_sent_at',
        'proposed_date',
        'proposed_time',
        'proposed_end_time',
        'proposed_staff_id',
        'proposed_at',
        'proposal_note',
        // Phase 8 (migration 2026_10_25_000008)
        'deposit_amount',
        'deposit_status',
        'deposit_paid',
        'deposit_paid_at',
        'deposit_gateway',
        'deposit_reference',
        'deposit_note',
        'crm_lead_id',
        'crm_deal_id',
    ];

    protected $casts = [
        'customer_id' => 'integer',
        'product_id' => 'integer',
        'tray_id' => 'integer',
        'sales_person_id' => 'integer',
        'price' => 'decimal:2',
        'amount' => 'decimal:2',
        'staff_id' => 'integer',
        'warehouse_id' => 'integer',
        'duration_minutes' => 'integer',
        'seats' => 'integer',
        'request_fields' => 'array',
        'hold_until' => 'datetime',
        'confirmed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'ecommerce_client_id' => 'integer',
        'sale_id' => 'integer',
        'confirmed_by' => 'integer',
        'arrived_at' => 'datetime',
        'completed_at' => 'datetime',
        'reminder_sent_at' => 'datetime',
        'proposed_at' => 'datetime',
        'proposed_staff_id' => 'integer',
        'deposit_amount' => 'float',
        'deposit_paid' => 'float',
        'deposit_paid_at' => 'datetime',
        'crm_lead_id' => 'integer',
        'crm_deal_id' => 'integer',
    ];

    /** @var array<string, bool> tenant DB name → phase 8 columns exist */
    private static array $depositsReady = [];

    /** Whether migration 2026_10_25_000008 has run (deposits + CRM links). */
    public static function depositsReady(): bool
    {
        $key = (string) \Illuminate\Support\Facades\DB::connection()->getDatabaseName();
        if (! isset(self::$depositsReady[$key])) {
            try {
                self::$depositsReady[$key] = self::notificationsReady()
                    && \Illuminate\Support\Facades\Schema::hasTable('bk_payments')
                    && \Illuminate\Support\Facades\Schema::hasColumn('bookings', 'deposit_status');
            } catch (\Throwable $e) {
                self::$depositsReady[$key] = false;
            }
        }

        return self::$depositsReady[$key];
    }

    public static function forgetDepositsReady(): void
    {
        self::$depositsReady = [];
    }

    /** A deposit is asked for and nothing has been paid yet. */
    public function depositDue(): bool
    {
        return $this->deposit_status === 'due' && (float) $this->deposit_amount > 0;
    }

    /** @var array<string, bool> tenant DB name → phase 6 columns exist */
    private static array $notifyReady = [];

    /** Whether migration 2026_10_25_000007 has run (notifications + suggested times). */
    public static function notificationsReady(): bool
    {
        $key = (string) \Illuminate\Support\Facades\DB::connection()->getDatabaseName();
        if (! isset(self::$notifyReady[$key])) {
            try {
                self::$notifyReady[$key] = self::workflowReady()
                    && \Illuminate\Support\Facades\Schema::hasTable('bk_messages')
                    && \Illuminate\Support\Facades\Schema::hasColumn('bookings', 'proposed_at');
            } catch (\Throwable $e) {
                self::$notifyReady[$key] = false;
            }
        }

        return self::$notifyReady[$key];
    }

    public static function forgetNotificationsReady(): void
    {
        self::$notifyReady = [];
    }

    /** A time staff suggested is waiting for the customer's answer. */
    public function hasProposal(): bool
    {
        return $this->status === 'pending' && $this->proposed_at !== null && $this->proposed_date !== null;
    }

    /** Every status value; the first four are the original ones. */
    const STATUSES = ['pending', 'confirmed', 'cancelled', 'completed', 'arrived', 'declined', 'no_show'];

    /** @var array<string, bool> tenant DB name → phase 4 columns exist */
    private static array $workflowReady = [];

    /** Whether migration 2026_10_25_000006 has run (and the earlier booking ones). */
    public static function workflowReady(): bool
    {
        $key = (string) \Illuminate\Support\Facades\DB::connection()->getDatabaseName();
        if (! isset(self::$workflowReady[$key])) {
            try {
                self::$workflowReady[$key] = \App\Models\BookingStaff::tablesReady()
                    && \Illuminate\Support\Facades\Schema::hasColumn('bookings', 'sale_id');
            } catch (\Throwable $e) {
                self::$workflowReady[$key] = false;
            }
        }

        return self::$workflowReady[$key];
    }

    public static function forgetWorkflowReady(): void
    {
        self::$workflowReady = [];
    }

    public function staff()
    {
        return $this->belongsTo(BookingStaff::class, 'staff_id')->withTrashed();
    }

    /** Bookings sent from the store website. */
    public function scopeFromWebsite($query)
    {
        return $query->where('source', 'website');
    }

    public function customer()
    {
        return $this->belongsTo(Client::class, 'customer_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function tray()
    {
        return $this->belongsTo(Tray::class, 'tray_id');
    }

    public function salesPerson()
    {
        return $this->belongsTo(User::class, 'sales_person_id');
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }
}












