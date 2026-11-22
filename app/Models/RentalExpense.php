<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Money spent on a rental item, or on the rental business when no item is set. */
class RentalExpense extends Model
{
    use SoftDeletes;

    protected $table = 'rental_expenses';

    /** Receipts directory, relative to public/. */
    public const FILE_DIR = 'rental_expenses'; // under upload_path(): images/tenants/{id}/rental_expenses

    public const CATEGORIES = ['maintenance', 'repair', 'utilities', 'insurance', 'tax', 'cleaning', 'fuel', 'fees', 'other'];

    protected $fillable = [
        'rental_item_id', 'rental_maintenance_id', 'category', 'amount', 'expense_date',
        'payment_method_id', 'account_id', 'vendor', 'reference', 'description', 'attachment', 'created_by',
    ];

    protected $casts = [
        'rental_item_id' => 'integer',
        'rental_maintenance_id' => 'integer',
        'amount' => 'decimal:2',
        'expense_date' => 'date',
        'payment_method_id' => 'integer',
        'account_id' => 'integer',
        'created_by' => 'integer',
    ];

    public function item()
    {
        return $this->belongsTo(RentalItem::class, 'rental_item_id', 'id');
    }

    public function maintenance()
    {
        return $this->belongsTo(RentalMaintenance::class, 'rental_maintenance_id', 'id');
    }

    public function method()
    {
        return $this->belongsTo(PaymentMethod::class, 'payment_method_id', 'id');
    }

    public function account()
    {
        return $this->belongsTo(Account::class, 'account_id', 'id');
    }

    public static function fileUrl(?string $file): ?string
    {
        return $file ? url(upload_path(self::FILE_DIR) . '/' . $file) : null;
    }
}
