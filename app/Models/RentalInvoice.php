<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * One bill on a rental contract: a billing period's rent, or a manual charge.
 * Balance = amount + late fee (unless waived) − paid. Voided invoices are kept
 * for the record and ignored everywhere else.
 */
class RentalInvoice extends Model
{
    protected $table = 'rental_invoices';

    protected $fillable = [
        'invoice_number', 'rental_contract_id', 'rental_customer_id', 'client_id',
        'kind', 'sequence', 'description', 'period_start', 'period_end', 'issue_date', 'due_date',
        'subtotal', 'discount', 'tax_amount', 'amount', 'late_fee', 'late_fee_waived', 'late_fee_posted',
        'paid_amount', 'status', 'voided_at', 'void_reason', 'notes', 'created_by',
    ];

    protected $casts = [
        'rental_contract_id' => 'integer',
        'rental_customer_id' => 'integer',
        'client_id' => 'integer',
        'sequence' => 'integer',
        'period_start' => 'datetime',
        'period_end' => 'datetime',
        'issue_date' => 'date',
        'due_date' => 'date',
        'subtotal' => 'decimal:2',
        'discount' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'amount' => 'decimal:2',
        'late_fee' => 'decimal:2',
        'late_fee_waived' => 'boolean',
        'late_fee_posted' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'voided_at' => 'datetime',
        'created_by' => 'integer',
    ];

    public function contract()
    {
        return $this->belongsTo(RentalContract::class, 'rental_contract_id', 'id');
    }

    public function client()
    {
        return $this->belongsTo(Client::class, 'client_id', 'id');
    }

    public function allocations()
    {
        return $this->hasMany(RentalPaymentAllocation::class, 'rental_invoice_id', 'id');
    }

    /** The late fee that actually counts (0 once waived). */
    public function effectiveLateFee(): float
    {
        return $this->late_fee_waived ? 0.0 : (float) $this->late_fee;
    }

    public function total(): float
    {
        return round((float) $this->amount + $this->effectiveLateFee(), 2);
    }

    public function balance(): float
    {
        return $this->status === 'void' ? 0.0 : max(0, round($this->total() - (float) $this->paid_amount, 2));
    }

    /** Past its due date plus the contract's grace days, and not settled. */
    public function isOverdue(int $graceDays = 0): bool
    {
        return in_array($this->status, ['unpaid', 'partial'], true)
            && $this->balance() > 0
            && $this->due_date->copy()->addDays($graceDays)->lt(Carbon::today());
    }
}
