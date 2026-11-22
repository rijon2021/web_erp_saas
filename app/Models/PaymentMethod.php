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
     * Id of the "Customer Wallet" tender (Customer Wallet feature), or null.
     * Read straight from settings so it works whether or not the feature is
     * on and on tenants that have not run the wallet migration.
     */
    public static function customerWalletId(): ?int
    {
        try {
            if (! Schema::hasColumn('settings', 'customer_wallet_payment_method_id')) {
                return null;
            }
            $id = \Illuminate\Support\Facades\DB::table('settings')->whereNull('deleted_at')->value('customer_wallet_payment_method_id');
        } catch (\Throwable $e) {
            return null;
        }

        return $id ? (int) $id : null;
    }

    /**
     * Leave the Customer Wallet tender out of a method list. For every form
     * that does not move a customer wallet (purchases, expenses, payroll,
     * rental, service jobs, terminals…): picking it there would record a
     * "Customer Wallet" payment that never touches the wallet.
     */
    public function scopeExceptCustomerWallet($query)
    {
        $id = static::customerWalletId();

        return $id ? $query->where($query->getModel()->getTable().'.id', '!=', $id) : $query;
    }

    /** Validation rule refusing the Customer Wallet tender where it is not supported. */
    public static function notCustomerWalletRule(): \Closure
    {
        return function ($attribute, $value, $fail) {
            $id = static::customerWalletId();
            if ($id && (int) $value === $id) {
                $fail('The Customer Wallet can only pay sales, POS and online orders.');
            }
        };
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
