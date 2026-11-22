<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\PaymentMethod;
use App\Models\RentalContract;
use App\Models\RentalDepositTransaction;
use App\Models\RentalHandover;
use App\Models\RentalInvoice;
use App\Models\RentalItem;
use App\Models\RentalSetting;
use App\Models\Setting;
use App\Services\Rental\RentalBillingService;
use App\Services\Rental\RentalHandoverService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use App\Traits\ScopesWarehouseAccess;
use Illuminate\Validation\Rule;

/**
 * Handover screens (check-out / check-in) and deposit actions.
 *
 * Check-out and check-in arrive as multipart: a `data` field holding the JSON
 * payload (lines, checklist, charges, deposit, signature…) plus `photos[]`.
 */
class RentalHandoverController extends Controller
{
    use ScopesWarehouseAccess;

    public function __construct(
        private RentalHandoverService $handovers,
        private RentalBillingService $billing,
    ) {
    }

    /** Everything the check-out / check-in wizard needs to start. */
    public function meta(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'handover', RentalContract::class);

        $type = $request->get('type') === 'checkin' ? 'checkin' : 'checkout';
        $c = RentalContract::with('items.item', 'client')->whereNull('deleted_at')->findOrFail($id);
        $this->abortIfDocumentWarehouseDenied($c);
        $this->billing->run($c->id);

        $lines = $c->items->filter(fn ($l) => $type === 'checkout' ? ! $l->checked_out_at : ! $l->returned_at)->values();
        $late = $type === 'checkin' ? $this->handovers->lateReturn($c, $lines, Carbon::now()) : ['periods' => 0, 'amount' => 0];
        $setting = Setting::whereNull('deleted_at')->first();

        return response()->json([
            'contract' => [
                'id' => $c->id,
                'contract_number' => $c->contract_number,
                'status' => $c->status,
                'customer_name' => optional($c->client)->name,
                'start_date' => $c->start_date->toIso8601String(),
                'end_date' => optional($c->end_date)->toIso8601String(),
                'rate_period' => $c->rate_period,
                'deposit_amount' => (float) $c->deposit_amount,
            ],
            'lines' => $lines->map(fn ($l) => [
                'id' => $l->id,
                'rental_item_id' => $l->rental_item_id,
                'item_name' => $l->item_name,
                'item_code' => $l->item_code,
                'quantity' => (int) $l->quantity,
                'amount' => (float) $l->amount,
                'type' => optional($l->item)->type,
                'image_url' => $l->item ? $l->item->coverUrl() : null,
                'condition' => optional($l->item)->condition,
                'meter_reading' => optional($l->item)->meter_reading === null ? null : (float) $l->item->meter_reading,
                'meter_unit' => optional($l->item)->meter_unit,
                'checked_out_at' => optional($l->checked_out_at)->toIso8601String(),
            ])->values(),
            'checklist' => RentalSetting::current()->checklistFor($type),
            'deposit' => [
                'agreed' => (float) $c->deposit_amount,
                'held' => $c->depositHeld(),
            ],
            'outstanding' => round(RentalInvoice::where('rental_contract_id', $c->id)->whereIn('status', ['unpaid', 'partial'])
                ->get()->sum(fn ($i) => $i->balance()), 2),
            'late' => $late,
            'payment_methods' => PaymentMethod::active()->exceptCustomerWallet()->whereNull('deleted_at')->orderBy('name')->get(['id', 'name']),
            'accounts' => Account::whereNull('deleted_at')->orderBy('account_name')->get(['id', 'account_name']),
            'default_payment_method_id' => optional($setting)->default_payment_method_id,
            'default_account_id' => optional($setting)->default_account_id,
        ]);
    }

    public function checkout(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'handover', RentalContract::class);

        $c = RentalContract::whereNull('deleted_at')->findOrFail($id);
        $this->abortIfDocumentWarehouseDenied($c);
        if ($c->status !== 'active') {
            return $this->refuse('Only an active contract can be handed over. Activate it first.');
        }
        $data = $this->payload($request, [
            'lines' => 'required|array|min:1',
            'lines.*.id' => 'required|integer',
            'lines.*.condition' => ['nullable', Rule::in(RentalItem::CONDITIONS)],
            'lines.*.meter_reading' => 'nullable|numeric|min:0',
            'deposit.amount' => 'nullable|numeric|min:0',
            'deposit.payment_method_id' => ['nullable', 'integer', 'exists:payment_methods,id', \App\Models\PaymentMethod::notCustomerWalletRule()],
            'deposit.account_id' => ['nullable', 'integer', \Illuminate\Validation\Rule::exists('accounts', 'id')->whereNull('deleted_at')],
            'deposit.reference' => 'nullable|string|max:191',
        ]);
        if (! empty($data['deposit']['amount']) && $request->user('api') && ! $this->can($request, 'deposits')) {
            return $this->refuse('You are not allowed to collect deposits.');
        }

        $h = $this->handovers->checkout($c, $data, $this->photos($request));

        return response()->json(['success' => true, 'id' => $h->id]);
    }

    public function checkin(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'handover', RentalContract::class);

        $c = RentalContract::whereNull('deleted_at')->findOrFail($id);
        $this->abortIfDocumentWarehouseDenied($c);
        if (! in_array($c->status, ['active', 'ended', 'terminated'], true)) {
            return $this->refuse('Items can only be returned on a contract that went live.');
        }
        $data = $this->payload($request, [
            'lines' => 'required|array|min:1',
            'lines.*.id' => 'required|integer',
            'lines.*.condition' => ['nullable', Rule::in(RentalItem::CONDITIONS)],
            'lines.*.meter_reading' => 'nullable|numeric|min:0',
            'lines.*.damage_charge' => 'nullable|numeric|min:0',
            'lines.*.damage_notes' => 'nullable|string|max:2000',
            'late_charge' => 'nullable|numeric|min:0',
            'charges' => 'nullable|array|max:20',
            'charges.*.description' => 'nullable|string|max:250',
            'charges.*.amount' => 'nullable|numeric|min:0',
            'deposit.refund_amount' => 'nullable|numeric|min:0',
            'deposit.retain_amount' => 'nullable|numeric|min:0',
            'deposit.payment_method_id' => ['nullable', 'integer', 'exists:payment_methods,id', \App\Models\PaymentMethod::notCustomerWalletRule()],
            'deposit.account_id' => ['nullable', 'integer', \Illuminate\Validation\Rule::exists('accounts', 'id')->whereNull('deleted_at')],
        ]);
        $d = $data['deposit'] ?? [];
        if ((! empty($d['apply']) || ! empty($d['refund_amount']) || ! empty($d['refund_rest']) || ! empty($d['retain_amount'])) && ! $this->can($request, 'deposits')) {
            return $this->refuse('You are not allowed to settle deposits.');
        }

        $h = $this->handovers->checkin($c, $data, $this->photos($request));

        return response()->json(['success' => true, 'id' => $h->id]);
    }

    /** Deposit action outside a handover: collect / refund / retain / apply. */
    public function deposit(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'deposits', RentalContract::class);

        $c = RentalContract::whereNull('deleted_at')->findOrFail($id);
        $this->abortIfDocumentWarehouseDenied($c);
        if ($c->status === 'draft') {
            return $this->refuse('Deposits are handled on contracts that went live.');
        }
        // A cancelled contract can only give back (or keep) what it still holds.
        if ($c->status === 'cancelled' && ! in_array($request->type, ['refunded', 'retained'], true)) {
            return $this->refuse('This contract was cancelled: the deposit it still holds can only be refunded or kept.');
        }
        $request->validate([
            'type' => 'required|in:collected,refunded,retained,applied',
            'amount' => 'required_unless:type,applied|nullable|numeric|min:0.01',
            'date' => 'nullable|date|before_or_equal:today',
            'payment_method_id' => ['nullable', 'integer', 'exists:payment_methods,id', \App\Models\PaymentMethod::notCustomerWalletRule()],
            'account_id' => ['nullable', 'integer', \Illuminate\Validation\Rule::exists('accounts', 'id')->whereNull('deleted_at')],
            'reference' => 'nullable|string|max:191',
            'notes' => 'nullable|string|max:2000',
        ]);
        $opts = $request->only(['date', 'payment_method_id', 'account_id', 'reference', 'notes']);
        $amount = $request->amount === null ? null : round((float) $request->amount, 2);

        $t = DB::transaction(function () use ($request, $c, $amount, $opts) {
            switch ($request->type) {
                case 'collected': return $this->handovers->collectDeposit($c, $amount, $opts);
                case 'refunded': return $this->handovers->refundDeposit($c, $amount, $opts);
                case 'retained': return $this->handovers->retainDeposit($c, $amount, $opts);
                default: return $this->handovers->applyDeposit($c, $amount, $opts);
            }
        });
        if (! $t) {
            return $this->refuse('Nothing to apply: no deposit is held or nothing is owed.');
        }

        return response()->json(['success' => true, 'id' => $t->id]);
    }

    public function destroyDeposit(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'deposits', RentalContract::class);

        $t = RentalDepositTransaction::whereNull('deleted_at')->findOrFail($id);
        $this->abortIfDocumentWarehouseDenied(RentalContract::find($t->rental_contract_id));
        DB::transaction(fn () => $this->handovers->reverseDeposit($t));

        return response()->json(['success' => true]);
    }

    /** Deposit ledger + handover records, for the contract page. */
    public function contractHandovers(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'view', RentalContract::class);

        $c = RentalContract::with('items')->whereNull('deleted_at')->findOrFail($id);
        $this->abortIfDocumentWarehouseDenied($c);
        $deposits = RentalDepositTransaction::with('method', 'account', 'user')->whereNull('deleted_at')
            ->where('rental_contract_id', $c->id)->orderBy('transaction_date')->orderBy('id')->get();
        $handovers = RentalHandover::with('items', 'user')->where('rental_contract_id', $c->id)->orderBy('happened_at', 'desc')->get();
        $sum = fn ($type) => round((float) $deposits->where('type', $type)->sum('amount'), 2);
        $name = fn ($u) => $u ? (trim(($u->firstname ?? '') . ' ' . ($u->lastname ?? '')) ?: ($u->username ?? null)) : null;

        return response()->json([
            'deposit' => [
                'agreed' => (float) $c->deposit_amount,
                'collected' => $sum('collected'),
                'refunded' => $sum('refunded'),
                'applied' => $sum('applied'),
                'retained' => $sum('retained'),
                'held' => $c->depositHeld($deposits),
                'transactions' => $deposits->map(fn ($t) => [
                    'id' => $t->id,
                    'type' => $t->type,
                    'amount' => (float) $t->amount,
                    'date' => optional($t->transaction_date)->toDateString(),
                    'method' => optional($t->method)->name,
                    'account' => optional($t->account)->account_name,
                    'reference' => $t->reference,
                    'notes' => $t->notes,
                    'user_name' => $name($t->user),
                ])->values(),
            ],
            'lines' => $c->items->map(fn ($l) => [
                'id' => $l->id,
                'item_name' => $l->item_name,
                'quantity' => (int) $l->quantity,
                'checked_out_at' => optional($l->checked_out_at)->toIso8601String(),
                'returned_at' => optional($l->returned_at)->toIso8601String(),
            ])->values(),
            'handovers' => $handovers->map(fn ($h) => [
                'id' => $h->id,
                'type' => $h->type,
                'happened_at' => $h->happened_at->toIso8601String(),
                'checklist' => $h->checklist ?: [],
                'photos' => collect($h->photos ?: [])->map(fn ($f) => RentalHandover::fileUrl($f))->values(),
                'signature_url' => RentalHandover::fileUrl($h->signature),
                'signed_name' => $h->signed_name,
                'notes' => $h->notes,
                'charges_total' => (float) $h->charges_total,
                'user_name' => $name($h->user),
                'items' => $h->items->map(fn ($i) => [
                    'item_name' => $i->item_name,
                    'quantity' => (int) $i->quantity,
                    'condition' => $i->condition,
                    'meter_reading' => $i->meter_reading === null ? null : (float) $i->meter_reading,
                    'damage_notes' => $i->damage_notes,
                    'damage_charge' => (float) $i->damage_charge,
                    'needs_maintenance' => (bool) $i->needs_maintenance,
                ])->values(),
            ])->values(),
        ]);
    }

    // ------------------------------------------------------------------

    /** Decode + validate the JSON `data` field of a multipart handover request. */
    private function payload(Request $request, array $rules): array
    {
        $data = json_decode((string) $request->input('data', '{}'), true);
        if (! is_array($data)) {
            abort(response()->json(['message' => 'The handover data could not be read.'], 422));
        }
        Validator::make($data, array_merge($rules, [
            'happened_at' => 'nullable|date',
            'checklist' => 'nullable|array|max:50',
            'checklist.*.label' => 'nullable|string|max:191',
            'notes' => 'nullable|string|max:5000',
            'signed_name' => 'nullable|string|max:191',
            'signature' => ['nullable', 'string', 'max:2000000'],
        ]))->validate();
        $request->validate(['photos' => 'nullable|array|max:20', 'photos.*' => 'image|max:8192']);

        return $data;
    }

    private function photos(Request $request): array
    {
        return array_values((array) $request->file('photos', []));
    }

    private function can(Request $request, string $ability): bool
    {
        return $request->user('api')->can($ability, RentalContract::class);
    }

    private function refuse(string $message)
    {
        return response()->json(['message' => $message], 422);
    }
}
