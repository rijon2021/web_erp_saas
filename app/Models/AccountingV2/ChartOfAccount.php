<?php

namespace App\Models\AccountingV2;

use Illuminate\Database\Eloquent\Model;

/**
 * NEW FEATURE - SAFE ADDITION
 */
class ChartOfAccount extends Model
{
    protected $table = 'acc_chart_of_accounts';

    protected $fillable = [
        'account_id', 'code', 'name', 'type', 'parent_id', 'level', 'is_active',
    ];

    /**
     * Codes the automatic journals look up by code (AccountingHelper,
     * RentalAccounting, ReportsController). Deleting or re-coding one makes
     * getOrCreateCoa() silently create a duplicate and splits the history
     * across two rows.
     */
    public const SYSTEM_CODES = [
        '1000-CASH', '1100-AR', '1200-INV', '1300-TAXIN',
        '1300-SUPADV', '2100-AP', '2200-TAX', '2300-DEP', '2310-CUSTWAL', '3900-OBE',
        '4000-REV', '4010-RET', '4020-SHIP', '4030-DISC',
        '4200-RENT', '4210-LATE', '4220-DEPKEEP',
        '5000-COGS', '5100-INVADJ', '5110-DMG', '6200-RENTEXP', 'EXP',
    ];

    protected $appends = ['is_system'];

    public function getIsSystemAttribute(): bool
    {
        return self::isSystemCode($this->code);
    }

    public static function isSystemCode(?string $code): bool
    {
        if ($code === null || $code === '') {
            return false;
        }

        return in_array($code, self::SYSTEM_CODES, true)
            || $code === (string) config('accounting_v2.codes.retained_earnings', '3100');
    }

    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(self::class, 'parent_id');
    }
}





