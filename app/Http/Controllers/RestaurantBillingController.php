<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Client;
use App\Models\PaymentMethod;
use App\Models\RestaurantOrder;
use App\Models\RestaurantSetting;
use App\Models\Setting;
use App\Models\User;
use App\Services\Restaurant\BillingService;
use App\Services\Restaurant\FloorException;
use App\Services\Restaurant\OrderException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Restaurant > bill & payment (permission rst_billing; a discount also needs
 * rst_discounts). Every payment is a normal POS sale — see BillingService.
 */
class RestaurantBillingController extends BaseController
{
    public function __construct(private BillingService $billing) {}

    /** GET restaurant/billing/meta — what the bill screen needs once. */
    public function meta(Request $request)
    {
        $user = $request->user('api');
        $this->authorizeForUser($user, 'rst_billing', RestaurantSetting::class);
        $this->ready();
        $s = RestaurantSetting::current();
        $sys = Setting::whereNull('deleted_at')->first();
        $client = $sys && $sys->client_id ? Client::whereNull('deleted_at')->find($sys->client_id, ['id', 'name']) : null;

        return response()->json([
            'payment_methods' => PaymentMethod::whereNull('deleted_at')->active()->where('id', '!=', BillingService::STRIPE_METHOD_ID)
                ->orderBy('id')->get(['id', 'name', 'is_cash'])->map(fn ($m) => ['id' => $m->id, 'name' => $m->name, 'is_cash' => (bool) $m->is_cash])->values(),
            'accounts' => Account::whereNull('deleted_at')->orderBy('account_name')->get(['id', 'account_name'])->map(fn ($a) => ['id' => $a->id, 'name' => $a->account_name])->values(),
            'require_account' => Schema::hasColumn('settings', 'require_payment_account') && (bool) ($sys->require_payment_account ?? false),
            'default_client' => $client ? ['id' => $client->id, 'name' => $client->name] : null,
            'can_discount' => $user->can('rst_discounts', RestaurantSetting::class),
            'settings' => [
                'tips_enabled' => (bool) $s->tips_enabled,
                'tip_presets' => $s->tipPresets(),
                'service_charge_percent' => (float) $s->service_charge_percent,
                'service_charge_scope' => $s->service_charge_scope,
                'bill_rounding' => (float) $s->bill_rounding,
                'max_discount_percent' => (float) $s->max_discount_percent,
                'receipt_header' => $s->receipt_header,
                'receipt_footer' => $s->receipt_footer,
            ],
            'company' => [
                'name' => $sys->CompanyName ?? '',
                'phone' => $sys->CompanyPhone ?? '',
                'address' => $sys->CompanyAdress ?? '',
            ],
        ]);
    }

    /** GET restaurant/billing/clients?search= — pick the customer the sale is booked to. */
    public function clients(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'rst_billing', RestaurantSetting::class);
        $term = trim((string) $request->input('search', ''));
        $q = Client::whereNull('deleted_at')->orderBy('name')->limit(20);
        if ($term !== '') {
            $like = '%'.$term.'%';
            $q->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('phone', 'like', $like)->orWhere('code', 'like', $like));
        }

        return response()->json(['data' => $q->get(['id', 'name', 'phone'])]);
    }

    /** GET restaurant/orders/{id}/bill — the order with its payments so far. */
    public function show(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'rst_billing', RestaurantSetting::class);
        $this->ready();
        $order = $this->findOrder($id);

        return response()->json(['order' => $this->presentOrder($order), 'payments' => $this->payments($order)]);
    }

    /** POST restaurant/orders/{id}/bill/quote — price the bill (nothing saved). */
    public function quote(Request $request, $id)
    {
        $user = $request->user('api');
        $this->authorizeForUser($user, 'rst_billing', RestaurantSetting::class);
        $this->ready();
        $order = $this->findOrder($id);
        $opts = $this->options($request, $user);

        return $this->run(fn () => $this->billing->quote($order, $opts), fn ($q) => ['quote' => $this->publicQuote($q)]);
    }

    /** POST restaurant/orders/{id}/bill/pay — take the payment; the bill becomes a POS sale. */
    public function pay(Request $request, $id)
    {
        $user = $request->user('api');
        $this->authorizeForUser($user, 'rst_billing', RestaurantSetting::class);
        $this->ready();
        $order = $this->findOrder($id);
        $opts = $this->options($request, $user);
        $data = $request->validate([
            'split' => 'nullable|in:full,equal,items',
            'client_id' => 'nullable|integer',
            'sale_uuid' => 'nullable|string|max:64',
            'payments' => 'required|array|min:1|max:20',
            'payments.*.payment_method_id' => 'required|integer',
            'payments.*.amount' => 'required|numeric|min:0',
            'payments.*.account_id' => 'nullable|integer',
            // What the screen showed (refused when the bill changed since).
            'expected_total' => 'nullable|numeric',
            'expected_item_ids' => 'nullable|array|max:500',
            'expected_item_ids.*' => 'integer',
        ]);
        if (isset($data['expected_total'])) {
            $opts['expected_total'] = (float) $data['expected_total'];
        }
        if (isset($data['expected_item_ids'])) {
            $opts['expected_item_ids'] = $data['expected_item_ids'];
        }
        $clientId = ! empty($data['client_id']) && Client::whereNull('deleted_at')->whereKey($data['client_id'])->exists() ? (int) $data['client_id'] : null;

        return $this->run(
            fn () => $this->billing->pay($order, $opts, $data['payments'], $user instanceof User ? $user : User::findOrFail($user->id), $clientId, $data['sale_uuid'] ?? null, $data['split'] ?? 'full'),
            fn ($r) => [
                'sale_id' => $r['sale_id'],
                'sale_ref' => DB::table('sales')->where('id', $r['sale_id'])->value('Ref'),
                'grand_total' => $r['grand_total'],
                'change' => $r['change'],
                'closed' => $r['closed'],
                'quote' => $r['quote'] ?? null,
                'order' => $this->presentOrder($r['order']),
            ]
        );
    }

    // ------------------------------------------------------------------

    private function options(Request $request, $user): array
    {
        $v = $request->validate([
            'item_ids' => 'nullable|array|max:500',
            'item_ids.*' => 'integer',
            'discount_type' => 'nullable|in:amount,percent',
            'discount_value' => 'nullable|numeric|min:0',
            'tip_type' => 'nullable|in:amount,percent',
            'tip_value' => 'nullable|numeric|min:0',
            'shares' => 'nullable|integer|min:1|max:20',
        ]);
        if ((float) ($v['discount_value'] ?? 0) > 0) {
            $this->authorizeForUser($user, 'rst_discounts', RestaurantSetting::class);
        }
        $opts = [
            'discount_type' => $v['discount_type'] ?? 'amount',
            'discount_value' => (float) ($v['discount_value'] ?? 0),
            'tip_type' => $v['tip_type'] ?? 'amount',
            'tip_value' => (float) ($v['tip_value'] ?? 0),
            'shares' => (int) ($v['shares'] ?? 1),
        ];
        if ($request->has('item_ids') && is_array($v['item_ids'] ?? null)) {
            $opts['item_ids'] = $v['item_ids'];
        }

        return $opts;
    }

    /** The quote without the internal stock lines. */
    private function publicQuote(array $q): array
    {
        unset($q['stock_lines']);

        return $q;
    }

    private function run(callable $fn, callable $ok)
    {
        try {
            $result = $fn();
        } catch (OrderException|FloorException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status());
        }

        return response()->json($ok($result));
    }

    private function ready(): void
    {
        if (! RestaurantOrder::tablesReady() || ! Schema::hasTable('rst_order_payments')) {
            abort(503, 'Run the latest database update first.');
        }
    }

    private function findOrder($id): RestaurantOrder
    {
        $order = RestaurantOrder::find((int) $id);
        if (! $order || ! $this->scopeToWarehouses(RestaurantOrder::query(), 'warehouse_id', true)->whereKey($order->id)->exists()) {
            abort(404, __('This order no longer exists.'));
        }

        return $order;
    }

    private function presentOrder(RestaurantOrder $o): array
    {
        $o->loadMissing('table:id,name');
        $items = $o->items()->get();

        return [
            'id' => $o->id,
            'ref' => $o->ref,
            'type' => $o->type,
            'status' => $o->status,
            'table' => $o->table ? ['id' => $o->table->id, 'name' => $o->table->name] : null,
            'guests' => $o->guests,
            'customer_name' => $o->customer_name,
            'client_id' => $o->client_id,
            'opened_at' => optional($o->opened_at)->toIso8601String(),
            'items' => $items->where('status', '!=', 'void')->map(fn ($i) => [
                'id' => $i->id, 'name' => $i->name, 'qty' => (float) $i->qty, 'addons_label' => $i->addons_label,
                'line_total' => (float) $i->line_total, 'seat' => $i->seat, 'course' => $i->course, 'status' => $i->status,
                'paid' => (bool) $i->sale_id, 'sale_id' => $i->sale_id,
            ])->values(),
            'unpaid_count' => $items->where('status', '!=', 'void')->whereNull('sale_id')->count(),
        ];
    }

    private function payments(RestaurantOrder $o): array
    {
        return DB::table('rst_order_payments as p')->leftJoin('sales as s', 's.id', '=', 'p.sale_id')
            ->where('p.order_id', $o->id)->orderBy('p.id')
            ->get(['p.id', 'p.sale_id', 's.Ref as sale_ref', 'p.split', 'p.shares', 'p.amount', 'p.service_charge', 'p.discount', 'p.tip', 'p.rounding', 'p.created_at'])
            ->map(fn ($r) => (array) $r)->all();
    }
}
