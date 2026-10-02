<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class PaymentMethod extends Model
{
    use HasFactory;

    protected $dates = ['deleted_at'];

    protected $fillable = ['name', 'is_active', 'is_cash'];

    protected $casts = ['is_active' => 'boolean', 'is_cash' => 'boolean'];

    /**
     * Methods offered when RECORDING a new payment. Listings that describe
     * existing payments (reports, filters) must NOT use this scope — a
     * disabled method still appears on historical records.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', 1);
    }

    /**
     * Methods that put physical notes and coins in the drawer, so the cash
     * register close can separate them from card, transfer and credit tenders.
     * Disabled methods are included on purpose: a method retired mid-shift
     * still took real cash earlier in that shift.
     */
    public function scopeCash($query)
    {
        return $query->where('is_cash', 1);
    }

    /**
     * Ids of the tenant's cash methods, for queries that sum payment rows.
     *
     * Returns an empty array only when the tenant has genuinely flagged none —
     * callers must treat that as "no cash expected", not as "no filter". The
     * column check keeps this safe on a tenant whose migrations haven't run.
     */
    public static function cashMethodIds(): array
    {
        if (! Schema::hasColumn('payment_methods', 'is_cash')) {
            return [];
        }

        return static::whereNull('deleted_at')->where('is_cash', 1)->pluck('id')->all();
    }
}
