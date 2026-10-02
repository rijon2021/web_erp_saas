<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Account extends Model
{
    use HasFactory;

    protected $dates = ['deleted_at'];

    protected $fillable = [
        'account_num', 'account_name', 'initial_balance', 'balance', 'note', 'created_at', 'updated_at', 'deleted_at',
    ];

    protected $casts = [
        'initial_balance' => 'double',
        'balance' => 'double',
    ];

    /**
     * Move an account's balance by $delta (negative to decrease), atomically.
     *
     * Balances are written from every money path in the app, and several of
     * those run concurrently — two cashiers can settle against the same till
     * inside the same millisecond. Reading the balance into PHP and writing it
     * back loses one of the two updates, so this issues a single
     * "SET balance = balance +/- ?" and lets the database serialise it.
     *
     * A missing account or a zero delta is a no-op: the accounts module is
     * optional, so payments may legitimately carry no account_id, and an
     * account that has been deleted simply matches nothing.
     */
    public static function adjustBalance($accountId, $delta): void
    {
        $delta = (float) $delta;

        if (empty($accountId) || abs($delta) < 0.00001) {
            return;
        }

        $query = static::where('id', $accountId);

        if ($delta > 0) {
            $query->increment('balance', $delta);
        } else {
            $query->decrement('balance', abs($delta));
        }
    }
}
