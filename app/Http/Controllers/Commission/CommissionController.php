<?php

namespace App\Http\Controllers\Commission;

use App\Http\Controllers\BaseController;
use App\Models\CommissionProgram;
use App\Models\Sale;
use App\Models\SaleCommission;
use App\Services\CommissionService;
use Illuminate\Http\Request;

class CommissionController extends BaseController
{
    protected $commissionService;

    public function __construct(CommissionService $commissionService)
    {
        $this->commissionService = $commissionService;
    }

    /** Calculate commissions for a sale (idempotent: does not duplicate). */
    public function calculateForSale(Request $request, $saleId)
    {
        $this->authorizeForUser($request->user('api'), 'create', CommissionProgram::class);
        $sale = Sale::findOrFail($saleId);
        $created = $this->commissionService->calculateForSale($sale, true);
        return $this->sendResponse([
            'created_count' => count($created),
            'commissions' => $created,
        ], __('Commissions calculated'));
    }

    /**
     * Recalculate the sales behind the ticked report rows from scratch:
     * current sale figures, and cancelled commissions worked out again.
     */
    public function recalculate(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'update', CommissionProgram::class);
        $request->validate([
            'sale_ids' => 'required|array|max:500',
            'sale_ids.*' => 'integer',
        ]);
        $changed = 0;
        foreach (Sale::whereIn('id', array_unique($request->sale_ids))->get() as $sale) {
            $changed += count($this->commissionService->calculateForSale($sale, true));
        }
        return $this->sendResponse(['updated_count' => $changed], __('Updated successfully'));
    }

    /** Approve one or more commissions (status pending -> approved). */
    public function approve(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'update', CommissionProgram::class);
        $request->validate([
            'commission_ids' => 'required|array',
            'commission_ids.*' => 'integer|exists:sale_commissions,id',
        ]);
        $updated = SaleCommission::whereIn('id', $request->commission_ids)
            ->where('status', 'pending')
            ->update(['status' => 'approved']);
        return $this->sendResponse(['updated_count' => $updated], __('Approved'));
    }

    /** Cancel one or more commissions. */
    public function cancel(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'update', CommissionProgram::class);
        $request->validate([
            'commission_ids' => 'required|array',
            'commission_ids.*' => 'integer|exists:sale_commissions,id',
        ]);
        $updated = SaleCommission::whereIn('id', $request->commission_ids)
            ->whereIn('status', ['pending', 'approved'])
            ->update(['status' => 'cancelled']);
        return $this->sendResponse(['updated_count' => $updated], __('Cancelled'));
    }
}
