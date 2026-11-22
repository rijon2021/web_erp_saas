<?php

namespace App\Http\Controllers\Api\Store;

use App\Http\Controllers\Controller;
use App\Models\OnlineOrder;
use App\Models\Sale;
use App\Models\StoreSetting;
use App\Services\OnlineOrderConfirmer;
use Auth;
use DB;
use Illuminate\Http\Request;

class OnlineOrdersApiController extends Controller
{
    /**
     * GET /store/orders
     * Query: page, per_page, q (Ref or customer), from, to, sort, dir
     * Note: online orders don’t have per-row status yet — we return "ordered".
     */
    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', StoreSetting::class);
        $q = trim((string) $request->query('q', ''));
        $from = $request->query('from');            // YYYY-MM-DD
        $to = $request->query('to');              // YYYY-MM-DD
        $statusFilter = trim((string) $request->query('status', ''));
        $preorderFilter = $request->query('preorder', '');
        $proofFilter = $request->query('proof', '');
        $sort = $request->query('sort', 'created_at');
        $dir = $request->query('dir', 'desc');
        $per = max(1, min(200, (int) $request->query('per_page', 10)));

        $allowedSort = ['date', 'created_at', 'total', 'ref', 'id'];

        $orders = OnlineOrder::query()
            ->with('client')
            ->withCount(['paymentProofs as pending_proofs_count' => fn ($p) => $p->where('status', 'pending')])
            ->when($q !== '', function ($qq) use ($q) {
                $qq->where(function ($w) use ($q) {
                    $w->where('ref', 'like', "%{$q}%")
                        ->orWhereHas('client', function ($c) use ($q) {
                            $c->where('name', 'like', "%{$q}%")
                                ->orWhere('email', 'like', "%{$q}%")
                                ->orWhere('phone', 'like', "%{$q}%");
                        });
                });
            })
            ->when($statusFilter !== '' && in_array($statusFilter, ['pending', 'confirmed', 'cancelled'], true),
                fn ($qq) => $qq->where('status', $statusFilter))
            ->when($preorderFilter === 'yes', fn ($qq) => $qq->where('has_preorder_items', true))
            ->when($preorderFilter === 'no', fn ($qq) => $qq->where(function ($w) {
                $w->where('has_preorder_items', false)->orWhereNull('has_preorder_items');
            }))
            ->when($proofFilter === 'pending', fn ($qq) => $qq->whereHas('paymentProofs', fn ($p) => $p->where('status', 'pending')))
            ->when($from, fn ($qq) => $qq->whereDate('date', '>=', $from))
            ->when($to, fn ($qq) => $qq->whereDate('date', '<=', $to))
            ->when(in_array($sort, $allowedSort, true),
                fn ($qq) => $qq->orderBy($sort, $dir),
                fn ($qq) => $qq->latest())
            ->paginate($per);

        $data = $orders->getCollection()->map(function (OnlineOrder $o) {
            return [
                'id' => $o->id,
                'code' => $o->ref,
                // A guest order shows the name typed at checkout, not the matched customer's.
                'customer_name' => ($o->is_guest ? $o->customer_name : null) ?: optional($o->client)->name,
                'is_guest' => (bool) $o->is_guest,
                'status' => $o->status,
                'has_preorder_items' => (bool) $o->has_preorder_items,
                'total' => (float) $o->total,
                'payment_method' => $o->payment_method ?? 'cod',
                'payment_status' => $o->payment_status ?? 'pending',
                'delivery_method' => $o->delivery_method ?? 'ship',
                'has_pending_proof' => (int) ($o->pending_proofs_count ?? 0) > 0,
                // Paid online but auto-confirm could not run (e.g. stock ran out)
                'auto_confirm_error' => $o->status === 'pending' ? ($o->auto_confirm_error ?? null) : null,
                'created_at' => optional($o->created_at)->toDateTimeString() ?? (string) $o->date,
            ];
        });

        return response()->json([
            'data' => $data,
            'meta' => [
                'total' => $orders->total(),
                'page' => $orders->currentPage(),
                'pages' => $orders->lastPage(),
            ],
        ]);
    }

    /**
     * GET /store/orders/{id}
     * Returns header + items for details view
     */
    public function show(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'view', StoreSetting::class);
        $order = OnlineOrder::with(['items.product', 'items.productVariant', 'client', 'warehouse', 'pickupBranch', 'paymentProofs'])
            ->findOrFail($id);

        $subtotal = (float) $order->items->sum(function ($i) {
            return (float) $i->price * (float) $i->qty;
        });

        return response()->json([
            'id' => $order->id,
            'code' => $order->ref,
            'status' => $order->status,
            'has_preorder_items' => (bool) $order->has_preorder_items,
            'shipping_status' => null,
            'customer_name' => $order->customer_name ?: optional($order->client)->name,
            'is_guest' => (bool) $order->is_guest,
            'customer_email' => $order->customer_email ?: optional($order->client)->email,
            'customer_phone' => $order->customer_phone ?: optional($order->client)->phone,
            'customer_address' => $order->shipping_address
                ? trim(implode(', ', array_filter([$order->shipping_address, $order->shipping_city, $order->shipping_state, $order->shipping_zip, $order->shipping_country])))
                : optional($order->client)->adresse,
            'shipping_city' => $order->shipping_city,
            'shipping_state' => $order->shipping_state,
            'shipping_zip' => $order->shipping_zip,
            'shipping_country' => $order->shipping_country,

            'warehouse_id' => $order->warehouse_id,
            'warehouse_name' => optional($order->warehouse)->name,

            // Prefer the totals frozen on the order; legacy orders fall back to the line sum.
            'subtotal' => (float) $order->subtotal > 0 ? (float) $order->subtotal : $subtotal,
            'tax' => (float) $order->tax,
            'tax_rate' => (float) $order->tax_rate,
            'shipping' => (float) $order->shipping_cost,
            'shipping_method_name' => $order->shipping_method_name,
            'discount' => (float) $order->discount,
            'coupon_code' => $order->coupon_code,
            'total' => (float) $order->total,
            'delivered_at' => optional($order->delivered_at)->toDateTimeString(),

            'payment_method' => $order->payment_method ?? 'cod',
            'payment_status' => $order->payment_status ?? 'pending',
            'auto_confirm_error' => $order->status === 'pending' ? ($order->auto_confirm_error ?? null) : null,
            'sale' => $this->linkedSale($order),

            // Pickup (collected at a branch) vs shipped
            'delivery_method' => $order->delivery_method ?? 'ship',
            'pickup_branch_id' => $order->pickup_branch_id,
            'pickup_branch_name' => optional($order->pickupBranch)->name,

            // Offline payments: what the shopper uploaded, newest first.
            'payment_proofs' => $order->paymentProofs->sortByDesc('id')->map(fn ($p) => [
                'id' => $p->id,
                'payment_method' => $p->payment_method,
                'reference_number' => $p->reference_number,
                'amount' => (float) $p->amount,
                'paid_at' => optional($p->paid_at)->toDateString(),
                'note' => $p->note,
                'status' => $p->status,
                'reject_reason' => $p->reject_reason,
                'file_url' => $p->fileUrl(),
                'submitted_at' => optional($p->created_at)->toDateTimeString(),
                'reviewed_at' => optional($p->reviewed_at)->toDateTimeString(),
            ])->values(),

            'items' => $order->items->map(function ($d) {
                $name = optional($d->product)->name ?? ('#'.$d->product_id);
                $variant = optional($d->productVariant)->name;

                return [
                    'id' => $d->id,
                    'product_id' => $d->product_id,
                    'product_variant_id' => $d->product_variant_id,
                    'name' => ($variant ? ($name.' - '.$variant) : $name).(! empty($d->options_label) ? ' ('.$d->options_label.')' : ''),
                    'qty' => (float) $d->qty,
                    'price' => (float) $d->price,
                    'line_total' => (float) $d->line_total,
                    'is_preorder' => (bool) $d->is_preorder,
                ];
            })->values(),
        ]);
    }

    /** The Sale confirming this order created, for the details page. */
    protected function linkedSale(OnlineOrder $order): ?array
    {
        $sale = $order->sale_id ? Sale::whereNull('deleted_at')->find($order->sale_id) : null;
        if (! $sale) {
            return null;
        }

        return [
            'id' => $sale->id,
            'ref' => $sale->Ref,
            'payment_status' => $sale->payment_statut,
            'paid' => (float) $sale->paid_amount,
            'due' => max(0, round((float) $sale->GrandTotal - (float) $sale->paid_amount, 2)),
        ];
    }

    /**
     * POST /store/orders/{id}/payment-proofs/{proofId}/review
     * Verify a shopper-submitted GCash / bank-transfer payment. Approving is
     * what marks the order paid, so a later confirm records the payment
     * against the generated Sale.
     */
    public function reviewProof(Request $request, $id, $proofId)
    {
        $this->authorizeForUser($request->user('api'), 'view', StoreSetting::class);

        $data = $request->validate([
            'status' => ['required', 'in:approved,rejected'],
            'reject_reason' => ['nullable', 'string', 'max:255'],
        ]);

        $order = OnlineOrder::findOrFail($id);
        $proof = $order->paymentProofs()->findOrFail($proofId);

        if ($proof->status !== 'pending') {
            return response()->json(['error' => __('messages.PaymentProofAlreadyReviewed')], 422);
        }

        \Illuminate\Support\Facades\DB::transaction(function () use ($proof, $order, $data, $request) {
            $proof->update([
                'status' => $data['status'],
                'reject_reason' => $data['status'] === 'rejected' ? ($data['reject_reason'] ?? null) : null,
                'reviewed_by' => optional($request->user('api'))->id,
                'reviewed_at' => now(),
            ]);

            if ($data['status'] === 'approved' && ($order->payment_status ?? 'pending') !== 'paid') {
                $order->update(['payment_status' => 'paid']);
            }
        });

        // Let the customer know either way; a mail failure must not undo the review.
        try {
            $to = $order->customer_email ?: optional($order->client)->email;
            if ($to) {
                \Illuminate\Support\Facades\Mail::to($to)->send(
                    new \App\Mail\PaymentProofReviewed($order->fresh(), $proof->fresh())
                );
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Payment proof review email failed: '.$e->getMessage());
        }

        return response()->json([
            'success' => true,
            'status' => $proof->status,
            'payment_status' => $order->fresh()->payment_status,
        ]);
    }

    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'view', StoreSetting::class);
        $data = $request->validate([
            'status' => ['required', \Illuminate\Validation\Rule::in(OnlineOrder::STATUSES)],
        ]);

        $order = OnlineOrder::with(['items'])->findOrFail($id);
        $newStatus = $data['status'];

        // Fast path: no-op
        if ($order->status === $newStatus) {
            return response()->json(['ok' => true, 'status' => $order->status]);
        }

        // Reject any transition that is not part of the defined lifecycle.
        if (! OnlineOrder::canTransition($order->status, $newStatus)) {
            return response()->json([
                'error' => "Cannot move an order from '{$order->status}' to '{$newStatus}'.",
            ], 422);
        }

        // ---- SHIP / DELIVER (fulfilment; stock already committed at confirm) ----
        if (in_array($newStatus, ['shipped', 'delivered'], true)) {
            $order->status = $newStatus;
            if ($newStatus === 'delivered') {
                $order->delivered_at = now(); // starts the return window
            }
            $order->save();

            return response()->json(['ok' => true, 'status' => $newStatus]);
        }

        // ---- CANCEL (only from pending) ----
        if ($newStatus === 'cancelled') {
            if ($order->status !== 'pending') {
                return response()->json(['error' => 'Only pending orders can be cancelled.'], 422);
            }
            DB::transaction(function () use ($order) {
                $order->status = 'cancelled';
                $order->save();

                // Paid from the customer wallet: the money goes back to it
                // (capped at what was charged, so a later approved request
                // for the same order refunds nothing twice).
                if ($order->payment_method === 'wallet' && ($order->payment_status ?? '') === 'paid') {
                    $tx = app(\App\Services\CustomerWallet\CustomerWalletService::class)
                        ->refundOrder($order, (float) $order->total, optional(Auth::user())->id);
                    if ($tx) {
                        $order->payment_status = 'refunded';
                        $order->save();
                    }
                }
            });

            return response()->json(['ok' => true, 'status' => 'cancelled']);
        }

        // ---- CONFIRM (only from pending) → create Sale, decrement stock ----
        // The work lives in OnlineOrderConfirmer, shared with auto-confirm
        // after an online payment (Store > Payment Gateway switch).
        if ($newStatus === 'confirmed') {
            if ($order->status !== 'pending') {
                return response()->json(['error' => 'Only pending orders can be confirmed.'], 422);
            }

            $confirmer = app(OnlineOrderConfirmer::class);
            $warehouseId = $confirmer->resolveWarehouseId($order);
            if (! $warehouseId) {
                return response()->json(['error' => 'No warehouse configured.'], 422);
            }

            // Pre-check stock (outside tx just to compile the list; final check in tx w/ locks)
            // Pre-order items are exempt from stock validation
            $insufficient = $confirmer->insufficientStock($order, $warehouseId);
            if (! empty($insufficient)) {
                return response()->json([
                    'error' => 'Insufficient stock for one or more items.',
                    'items' => $insufficient,
                ], 422);
            }

            try {
                $sale = $confirmer->confirm($order, optional(Auth::user())->id);
            } catch (\App\Exceptions\OnlineOrderConfirmException $e) {
                return response()->json(['error' => $e->getMessage()], 422);
            }

            return response()->json([
                'ok' => true,
                'status' => 'confirmed',
                'sale_id' => $sale->id,
                'sale_ref' => $sale->Ref,
            ]);
        }

        // Fallback (shouldn’t hit)
        return response()->json(['error' => 'Unsupported transition.'], 422);
    }
}
