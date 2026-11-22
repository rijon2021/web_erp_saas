<?php

namespace App\Services\Restaurant;

use App\Models\RestaurantReservation;
use App\Models\RestaurantSetting;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Restaurant dashboard + reports (phase 9). Plain aggregate queries, no new
 * tables. Money is in the base currency.
 *
 *  - Bill sales  = rst_order_payments.amount (each payment is a POS sale:
 *                  dishes, service charge and tip, after discount and rounding).
 *  - Online      = online_orders.total of the online orders that reached the
 *                  kitchen (rst_orders.source = online, not cancelled).
 *  - Dish sales  = rst_order_items.line_total (before tax) of non-void dishes
 *                  on paid orders; cost = qty × products.cost.
 *
 * Scope: $scope = the user's warehouse ids (null = all) — orders without a
 * warehouse are shared and always count (also under a branch filter);
 * $branch narrows to one warehouse.
 * Times are the tenant's wall clock (created_at as stored).
 */
class ReportService
{
    public const MAX_DAYS = 366;

    public function __construct(private ?array $scope = null, private ?int $branch = null)
    {
    }

    public function forScope(?array $scope, ?int $branch): self
    {
        return new self($scope, $branch ?: null);
    }

    // ------------------------------------------------------------------ dashboard

    public function dashboard(Carbon $from, Carbon $to, Carbon $prevFrom, Carbon $prevTo, bool $live): array
    {
        $cur = $this->totals($from, $to);
        $prev = $this->totals($prevFrom, $prevTo);

        return [
            'kpis' => [
                'sales' => ['value' => $cur['sales'], 'prev' => $prev['sales']],
                'bills' => ['value' => $cur['bills'], 'prev' => $prev['bills']],
                'covers' => ['value' => $cur['covers'], 'prev' => $prev['covers']],
                'avg_bill' => ['value' => $cur['avg_bill'], 'prev' => $prev['avg_bill']],
                'voids' => ['value' => $cur['void_value'] + $cur['discounts'], 'void_count' => $cur['void_count'], 'discount_count' => $cur['discount_count']],
            ],
            'hours' => $this->hourly($from, $to),
            'days' => $from->copy()->startOfDay()->diffInDays($to->copy()->startOfDay()) >= 1 ? $this->daily($from, $to) : [],
            'types' => $this->orderTypes($from, $to),
            'dishes' => array_slice($this->dishes($from, $to), 0, 8),
            'waiters' => array_slice($this->waiters($from, $to), 0, 6),
            'now' => $live ? $this->now() : null,
        ];
    }

    /** Headline totals for a range. */
    public function totals(Carbon $from, Carbon $to): array
    {
        $p = $this->payments($from, $to)->selectRaw('COUNT(*) AS n, COALESCE(SUM(p.amount),0) AS amount, COALESCE(SUM(p.discount),0) AS discount, SUM(CASE WHEN p.discount > 0 THEN 1 ELSE 0 END) AS dn')->first();
        $online = $this->onlineOrders($from, $to)->selectRaw('COUNT(*) AS n, COALESCE(SUM(oo.total),0) AS total')->first();
        $covers = (int) $this->orders()->where('o.status', 'paid')->where('o.type', 'dine_in')->whereBetween('o.closed_at', [$from, $to])->sum('o.guests');
        $voids = $this->items()->where('i.status', 'void')->whereBetween('i.voided_at', [$from, $to])->selectRaw('COUNT(*) AS n, COALESCE(SUM(i.line_total),0) AS v')->first();
        $bills = (int) $p->n;
        $billSales = round((float) $p->amount, 2);

        return [
            'sales' => round($billSales + (float) $online->total, 2),
            'bill_sales' => $billSales,
            'bills' => $bills,
            'online_orders' => (int) $online->n,
            'online_sales' => round((float) $online->total, 2),
            'covers' => $covers,
            'avg_bill' => $bills ? round($billSales / $bills, 2) : 0.0,
            'discounts' => round((float) $p->discount, 2),
            'discount_count' => (int) $p->dn,
            'void_value' => round((float) $voids->v, 2),
            'void_count' => (int) $voids->n,
        ];
    }

    /** Sales per hour of the day: dine-in vs takeaway, delivery and online. */
    public function hourly(Carbon $from, Carbon $to): array
    {
        $rows = array_fill(0, 24, ['dine_in' => 0.0, 'other' => 0.0]);
        foreach ($this->payments($from, $to)->selectRaw("HOUR(p.created_at) AS h, SUM(CASE WHEN o.type = 'dine_in' THEN p.amount ELSE 0 END) AS d, SUM(CASE WHEN o.type = 'dine_in' THEN 0 ELSE p.amount END) AS t")->groupByRaw('HOUR(p.created_at)')->get() as $r) {
            $rows[(int) $r->h]['dine_in'] += (float) $r->d;
            $rows[(int) $r->h]['other'] += (float) $r->t;
        }
        foreach ($this->onlineOrders($from, $to)->selectRaw('HOUR(o.created_at) AS h, SUM(oo.total) AS t')->groupByRaw('HOUR(o.created_at)')->get() as $r) {
            $rows[(int) $r->h]['other'] += (float) $r->t;
        }

        return array_map(fn ($h, $r) => ['hour' => $h, 'dine_in' => round($r['dine_in'], 2), 'other' => round($r['other'], 2)], array_keys($rows), $rows);
    }

    /** Sales per day: dine-in vs the rest (for ranges longer than a day). */
    public function daily(Carbon $from, Carbon $to): array
    {
        $out = [];
        for ($d = $from->copy()->startOfDay(); $d->lte($to); $d->addDay()) {
            $out[$d->format('Y-m-d')] = ['date' => $d->format('Y-m-d'), 'dine_in' => 0.0, 'other' => 0.0];
        }
        foreach ($this->payments($from, $to)->selectRaw("DATE(p.created_at) AS d, SUM(CASE WHEN o.type = 'dine_in' THEN p.amount ELSE 0 END) AS di, SUM(CASE WHEN o.type = 'dine_in' THEN 0 ELSE p.amount END) AS t")->groupByRaw('DATE(p.created_at)')->get() as $r) {
            if (isset($out[$r->d])) {
                $out[$r->d]['dine_in'] += (float) $r->di;
                $out[$r->d]['other'] += (float) $r->t;
            }
        }
        foreach ($this->onlineOrders($from, $to)->selectRaw('DATE(o.created_at) AS d, SUM(oo.total) AS t')->groupByRaw('DATE(o.created_at)')->get() as $r) {
            if (isset($out[$r->d])) {
                $out[$r->d]['other'] += (float) $r->t;
            }
        }

        return array_values(array_map(fn ($r) => ['date' => $r['date'], 'dine_in' => round($r['dine_in'], 2), 'other' => round($r['other'], 2)], $out));
    }

    /** Sales by order type (bills) plus online orders, with shares. */
    public function orderTypes(Carbon $from, Carbon $to): array
    {
        $byType = $this->payments($from, $to)->selectRaw('o.type AS t, COUNT(*) AS n, SUM(p.amount) AS v')->groupBy('o.type')->get()->keyBy('t');
        $online = $this->onlineOrders($from, $to)->selectRaw('COUNT(*) AS n, COALESCE(SUM(oo.total),0) AS v')->first();
        $rows = [];
        foreach (['dine_in', 'takeaway', 'delivery'] as $t) {
            $rows[] = ['type' => $t, 'orders' => (int) ($byType[$t]->n ?? 0), 'sales' => round((float) ($byType[$t]->v ?? 0), 2)];
        }
        $rows[] = ['type' => 'online', 'orders' => (int) $online->n, 'sales' => round((float) $online->v, 2)];
        $sum = array_sum(array_column($rows, 'sales'));

        return array_map(fn ($r) => $r + ['pct' => $sum > 0 ? round($r['sales'] / $sum * 100, 1) : 0.0], $rows);
    }

    /** What is happening on the floor and in the kitchen right now. */
    public function now(): array
    {
        $tables = DB::table('rst_tables as t')->join('rst_areas as a', 'a.id', '=', 't.area_id')
            ->whereNull('t.deleted_at')->whereNull('a.deleted_at')->where('t.is_active', 1)->where('a.is_active', 1);
        $this->scopeCol($tables, 'a.warehouse_id');
        $t = (clone $tables)->selectRaw("COUNT(*) AS n, SUM(CASE WHEN t.status IN ('occupied','bill') THEN 1 ELSE 0 END) AS busy, SUM(CASE WHEN t.status = 'bill' THEN 1 ELSE 0 END) AS bill")->first();
        $late = (int) (RestaurantSetting::current()->late_ticket_minutes ?: 20);
        $tickets = DB::table('rst_kitchen_tickets as k')->join('rst_orders as o', 'o.id', '=', 'k.order_id')
            ->where('o.status', '!=', 'cancelled')->whereIn('k.status', ['pending', 'preparing']);
        $this->scopeCol($tickets, 'o.warehouse_id');
        $q = (clone $tickets)->selectRaw('COUNT(*) AS n, SUM(CASE WHEN k.sent_at < ? THEN 1 ELSE 0 END) AS late', [now()->subMinutes($late)])->first();
        $todayStart = now()->startOfDay();
        $avgPrep = $this->prepStats($todayStart, now())['avg_minutes'];
        $bookings = null;
        if (RestaurantReservation::tablesReady()) {
            $b = DB::table('rst_reservations')->whereIn('status', RestaurantReservation::UPCOMING)->where('starts_at', '>=', now()->subMinutes((int) (RestaurantSetting::current()->no_show_minutes ?? 15)))->where('starts_at', '<', now()->endOfDay());
            $this->scopeCol($b, 'warehouse_id');
            $bookings = (int) $b->count();
        }
        $onlineWaiting = 0;
        if (Schema::hasTable('online_orders')) {
            $ow = DB::table('online_orders')->where('status', 'pending');
            $onlineWaiting = (int) $this->scopeCol($ow, 'warehouse_id')->count();
        }

        return [
            'tables' => (int) $t->n,
            'tables_busy' => (int) $t->busy,
            'bills_requested' => (int) $t->bill,
            'kitchen_queue' => (int) $q->n,
            'late_tickets' => (int) $q->late,
            'bookings_to_come' => $bookings,
            'online_waiting' => $onlineWaiting,
            'avg_prep_minutes' => $avgPrep,
        ];
    }

    // ------------------------------------------------------------------ reports

    /** Sales report: one row per day, totals and payment methods. */
    public function sales(Carbon $from, Carbon $to): array
    {
        $days = [];
        for ($d = $from->copy()->startOfDay(); $d->lte($to); $d->addDay()) {
            $days[$d->format('Y-m-d')] = ['date' => $d->format('Y-m-d'), 'bills' => 0, 'covers' => 0, 'bill_sales' => 0.0, 'service' => 0.0, 'tips' => 0.0, 'discounts' => 0.0, 'rounding' => 0.0, 'online_orders' => 0, 'online_sales' => 0.0, 'total' => 0.0];
        }
        foreach ($this->payments($from, $to)->selectRaw('DATE(p.created_at) AS d, COUNT(*) AS n, SUM(p.amount) AS a, SUM(p.service_charge) AS s, SUM(p.tip) AS t, SUM(p.discount) AS dc, SUM(p.rounding) AS r')->groupByRaw('DATE(p.created_at)')->get() as $r) {
            if (isset($days[$r->d])) {
                $days[$r->d] = array_merge($days[$r->d], ['bills' => (int) $r->n, 'bill_sales' => round((float) $r->a, 2), 'service' => round((float) $r->s, 2), 'tips' => round((float) $r->t, 2), 'discounts' => round((float) $r->dc, 2), 'rounding' => round((float) $r->r, 2)]);
            }
        }
        foreach ($this->orders()->where('o.status', 'paid')->where('o.type', 'dine_in')->whereBetween('o.closed_at', [$from, $to])->selectRaw('DATE(o.closed_at) AS d, SUM(o.guests) AS g')->groupByRaw('DATE(o.closed_at)')->get() as $r) {
            if (isset($days[$r->d])) {
                $days[$r->d]['covers'] = (int) $r->g;
            }
        }
        foreach ($this->onlineOrders($from, $to)->selectRaw('DATE(o.created_at) AS d, COUNT(*) AS n, SUM(oo.total) AS t')->groupByRaw('DATE(o.created_at)')->get() as $r) {
            if (isset($days[$r->d])) {
                $days[$r->d]['online_orders'] = (int) $r->n;
                $days[$r->d]['online_sales'] = round((float) $r->t, 2);
            }
        }
        $rows = array_values(array_map(fn ($r) => array_merge($r, ['total' => round($r['bill_sales'] + $r['online_sales'], 2)]), $days));
        $sum = [];
        foreach (['bills', 'covers', 'bill_sales', 'service', 'tips', 'discounts', 'rounding', 'online_orders', 'online_sales', 'total'] as $k) {
            $sum[$k] = round(array_sum(array_column($rows, $k)), 2);
        }

        return ['rows' => $rows, 'totals' => $sum, 'methods' => $this->paymentMethods($from, $to)];
    }

    /** Money taken per payment method (the POS payments of the bills). */
    public function paymentMethods(Carbon $from, Carbon $to): array
    {
        if (! Schema::hasTable('payment_sales')) {
            return [];
        }
        $q = $this->payments($from, $to)->join('payment_sales as ps', 'ps.sale_id', '=', 'p.sale_id')
            ->leftJoin('payment_methods as pm', 'pm.id', '=', 'ps.payment_method_id')
            ->whereNull('ps.deleted_at')
            ->selectRaw('ps.payment_method_id AS id, MAX(pm.name) AS name, COUNT(*) AS n, SUM(ps.montant) AS v')
            ->groupBy('ps.payment_method_id')->orderByDesc('v');

        return $q->get()->map(fn ($r) => ['id' => (int) $r->id, 'name' => $r->name ?: ('#'.$r->id), 'count' => (int) $r->n, 'amount' => round((float) $r->v, 2)])->all();
    }

    /** Dishes sold: quantity, sales (before tax), cost and margin, most sold first. */
    public function dishes(Carbon $from, Carbon $to): array
    {
        $rows = $this->items()->join('products as pr', 'pr.id', '=', 'i.product_id')->leftJoin('categories as c', 'c.id', '=', 'pr.category_id')
            ->where('o.status', 'paid')->where('i.status', '!=', 'void')->whereBetween('o.closed_at', [$from, $to])
            ->whereNotIn('i.product_id', BillingService::systemProductIds() ?: [0])
            ->selectRaw('i.product_id AS id, MAX(pr.name) AS name, MAX(c.name) AS category, SUM(i.qty) AS qty, SUM(i.line_total) AS sales, SUM(i.qty * COALESCE(pr.cost,0)) AS cost')
            ->groupBy('i.product_id')->orderByDesc('qty')->limit(500)->get();
        $voided = $this->items()->where('i.status', 'void')->whereBetween('i.voided_at', [$from, $to])->selectRaw('i.product_id AS id, SUM(i.qty) AS q')->groupBy('i.product_id')->pluck('q', 'id');

        return $rows->map(function ($r) use ($voided) {
            $sales = round((float) $r->sales, 2);
            $cost = round((float) $r->cost, 2);

            return [
                'id' => (int) $r->id, 'name' => $r->name, 'category' => $r->category, 'qty' => round((float) $r->qty, 3),
                'sales' => $sales, 'cost' => $cost, 'margin' => round($sales - $cost, 2),
                'margin_pct' => $sales > 0 ? round(($sales - $cost) / $sales * 100, 1) : null,
                'voided_qty' => round((float) ($voided[$r->id] ?? 0), 3),
            ];
        })->all();
    }

    /** Voided dishes and cancelled orders, newest first. */
    public function voids(Carbon $from, Carbon $to): array
    {
        $users = fn ($ids) => DB::table('users')->whereIn('id', array_filter(array_unique($ids)))->get(['id', 'username', 'firstname', 'lastname'])
            ->mapWithKeys(fn ($u) => [$u->id => trim($u->firstname.' '.$u->lastname) ?: $u->username]);
        $items = $this->items()->leftJoin('rst_tables as t', 't.id', '=', 'o.table_id')
            ->where('i.status', 'void')->whereBetween('i.voided_at', [$from, $to])
            ->where('o.status', '!=', 'cancelled')
            ->orderByDesc('i.voided_at')->limit(500)
            ->get(['i.id', 'i.name', 'i.qty', 'i.line_total', 'i.void_reason', 'i.voided_by', 'i.voided_at', 'o.ref', 'o.type', 't.name as table_name']);
        $orders = $this->orders()->leftJoin('rst_tables as t', 't.id', '=', 'o.table_id')
            ->where('o.status', 'cancelled')->whereBetween('o.closed_at', [$from, $to])
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('rst_order_items as ci')->whereColumn('ci.order_id', 'o.id'))
            ->orderByDesc('o.closed_at')->limit(500)
            ->get(['o.id', 'o.ref', 'o.type', 'o.cancel_reason', 'o.cancelled_by', 'o.closed_at', 't.name as table_name']);
        $names = $users(array_merge($items->pluck('voided_by')->all(), $orders->pluck('cancelled_by')->all()));
        $cancelValue = $orders->isEmpty() ? collect() : DB::table('rst_order_items')->whereIn('order_id', $orders->pluck('id'))->groupBy('order_id')->selectRaw('order_id, SUM(line_total) AS v')->pluck('v', 'order_id');

        return [
            'items' => $items->map(fn ($r) => [
                'id' => $r->id, 'at' => substr((string) $r->voided_at, 0, 16), 'ref' => $r->ref, 'table' => $r->table_name, 'type' => $r->type,
                'dish' => $r->name, 'qty' => (float) $r->qty, 'value' => (float) $r->line_total, 'reason' => $r->void_reason, 'by' => $names[$r->voided_by] ?? null,
            ])->all(),
            'orders' => $orders->map(fn ($r) => [
                'id' => $r->id, 'at' => substr((string) $r->closed_at, 0, 16), 'ref' => $r->ref, 'table' => $r->table_name, 'type' => $r->type,
                'value' => round((float) ($cancelValue[$r->id] ?? 0), 2), 'reason' => $r->cancel_reason, 'by' => $names[$r->cancelled_by] ?? null,
            ])->all(),
            'totals' => ['items' => $items->count(), 'items_value' => round((float) $items->sum('line_total'), 2), 'orders' => $orders->count()],
        ];
    }

    /** Waiters (orders they served) and cashiers (bills they took). */
    public function waiters(Carbon $from, Carbon $to): array
    {
        $rows = $this->payments($from, $to)->whereNotNull('o.waiter_id')
            ->selectRaw('o.waiter_id AS id, COUNT(DISTINCT o.id) AS orders, COUNT(*) AS bills, SUM(p.amount) AS sales, SUM(p.tip) AS tips')
            ->groupBy('o.waiter_id')->orderByDesc('sales')->get();
        $covers = $this->orders()->where('o.status', 'paid')->where('o.type', 'dine_in')->whereBetween('o.closed_at', [$from, $to])->whereNotNull('o.waiter_id')
            ->selectRaw('o.waiter_id AS id, SUM(o.guests) AS g')->groupBy('o.waiter_id')->pluck('g', 'id');
        $names = $this->userNames($rows->pluck('id')->all());

        return $rows->map(fn ($r) => [
            'id' => (int) $r->id, 'name' => $names[$r->id] ?? ('#'.$r->id), 'orders' => (int) $r->orders, 'bills' => (int) $r->bills,
            'covers' => (int) ($covers[$r->id] ?? 0), 'sales' => round((float) $r->sales, 2), 'tips' => round((float) $r->tips, 2),
            'avg_bill' => $r->bills ? round((float) $r->sales / (int) $r->bills, 2) : 0.0,
        ])->all();
    }

    public function staff(Carbon $from, Carbon $to): array
    {
        $cash = $this->payments($from, $to)->whereNotNull('p.paid_by')
            ->selectRaw('p.paid_by AS id, COUNT(*) AS bills, SUM(p.amount) AS total, SUM(p.tip) AS tips, SUM(p.discount) AS discounts')
            ->groupBy('p.paid_by')->orderByDesc('total')->get();
        $names = $this->userNames($cash->pluck('id')->all());

        return [
            'waiters' => $this->waiters($from, $to),
            'cashiers' => $cash->map(fn ($r) => ['id' => (int) $r->id, 'name' => $names[$r->id] ?? ('#'.$r->id), 'bills' => (int) $r->bills, 'total' => round((float) $r->total, 2), 'tips' => round((float) $r->tips, 2), 'discounts' => round((float) $r->discounts, 2)])->all(),
        ];
    }

    /** Kitchen speed: sent → ready per ticket, late share, per station. */
    public function kitchen(Carbon $from, Carbon $to): array
    {
        $stats = $this->prepStats($from, $to);
        $stations = DB::table('rst_order_items as i')->join('rst_orders as o', 'o.id', '=', 'i.order_id')
            ->join('rst_kitchen_tickets as k', 'k.id', '=', 'i.ticket_id')->leftJoin('rst_stations as s', 's.id', '=', 'i.station_id')
            ->where('i.status', '!=', 'void')->whereBetween('k.sent_at', [$from, $to]);
        $this->scopeCol($stations, 'o.warehouse_id');
        $perTicket = (clone $stations)->selectRaw('i.station_id AS sid, k.id AS kid, MAX(TIMESTAMPDIFF(SECOND, k.sent_at, k.ready_at)) AS secs')
            ->whereNotNull('k.ready_at')->groupBy('i.station_id', 'k.id');
        $times = DB::query()->fromSub($perTicket, 'x')->selectRaw('x.sid, AVG(x.secs) AS secs')->groupBy('x.sid')->pluck('secs', 'sid');
        $byStation = $stations->selectRaw('i.station_id AS id, MAX(s.name) AS name, SUM(i.qty) AS dishes, COUNT(DISTINCT k.id) AS tickets')
            ->groupBy('i.station_id')->orderByDesc('dishes')->get()
            ->each(function ($r) use ($times) { $r->secs = $times[$r->id ?? ''] ?? null; });
        $byHour = DB::table('rst_kitchen_tickets as k')->join('rst_orders as o', 'o.id', '=', 'k.order_id')->whereBetween('k.sent_at', [$from, $to])->where('k.status', '!=', 'void');
        $this->scopeCol($byHour, 'o.warehouse_id');
        $hours = array_fill(0, 24, 0);
        foreach ($byHour->selectRaw('HOUR(k.sent_at) AS h, COUNT(*) AS n')->groupByRaw('HOUR(k.sent_at)')->get() as $r) {
            $hours[(int) $r->h] = (int) $r->n;
        }

        return $stats + [
            'stations' => $byStation->map(fn ($r) => ['id' => $r->id ? (int) $r->id : null, 'name' => $r->name, 'dishes' => round((float) $r->dishes, 1), 'tickets' => (int) $r->tickets, 'avg_minutes' => $r->secs !== null ? round((float) $r->secs / 60, 1) : null])->all(),
            'tickets_by_hour' => array_map(fn ($h, $n) => ['hour' => $h, 'tickets' => $n], array_keys($hours), $hours),
        ];
    }

    /** Bookings by status and source, no-show rate; waitlist outcomes. */
    public function reservations(Carbon $from, Carbon $to): array
    {
        if (! RestaurantReservation::tablesReady()) {
            return ['ready' => false];
        }
        $b = DB::table('rst_reservations')->whereBetween('starts_at', [$from, $to]);
        $this->scopeCol($b, 'warehouse_id');
        $byStatus = (clone $b)->selectRaw('status, COUNT(*) AS n, SUM(party_size) AS covers')->groupBy('status')->get()->keyBy('status');
        $bySource = (clone $b)->selectRaw('source, COUNT(*) AS n')->groupBy('source')->pluck('n', 'source');
        $total = (int) $byStatus->sum('n');
        $arrivedOrNot = (int) (($byStatus['seated']->n ?? 0) + ($byStatus['completed']->n ?? 0) + ($byStatus['no_show']->n ?? 0));
        $w = DB::table('rst_waitlist')->whereBetween('created_at', [$from, $to]);
        $this->scopeCol($w, 'warehouse_id');
        $wait = (clone $w)->selectRaw("COUNT(*) AS n, SUM(CASE WHEN status = 'seated' THEN 1 ELSE 0 END) AS seated, SUM(CASE WHEN status = 'left' THEN 1 ELSE 0 END) AS left_n, AVG(CASE WHEN status = 'seated' THEN TIMESTAMPDIFF(SECOND, created_at, seated_at) END) AS secs")->first();
        $statuses = [];
        foreach (RestaurantReservation::STATUSES as $st) {
            $statuses[] = ['status' => $st, 'bookings' => (int) ($byStatus[$st]->n ?? 0), 'covers' => (int) ($byStatus[$st]->covers ?? 0)];
        }

        return [
            'ready' => true,
            'bookings' => $total,
            'covers' => (int) $byStatus->whereNotIn('status', ['cancelled', 'no_show'])->sum('covers'),
            'no_show_rate' => $arrivedOrNot ? round(($byStatus['no_show']->n ?? 0) / $arrivedOrNot * 100, 1) : null,
            'statuses' => $statuses,
            'sources' => collect(RestaurantReservation::SOURCES)->map(fn ($s) => ['source' => $s, 'bookings' => (int) ($bySource[$s] ?? 0)])->all(),
            'waitlist' => ['parties' => (int) $wait->n, 'seated' => (int) $wait->seated, 'left' => (int) $wait->left_n, 'avg_wait_minutes' => $wait->secs !== null ? round((float) $wait->secs / 60, 1) : null],
        ];
    }

    // ------------------------------------------------------------------ helpers

    /** Ticket times sent → ready: average, median, 90th percentile, share late. */
    public function prepStats(Carbon $from, Carbon $to): array
    {
        $q = DB::table('rst_kitchen_tickets as k')->join('rst_orders as o', 'o.id', '=', 'k.order_id')
            ->whereBetween('k.sent_at', [$from, $to])->whereNotNull('k.ready_at')->where('k.status', '!=', 'void');
        $this->scopeCol($q, 'o.warehouse_id');
        $late = (int) (RestaurantSetting::current()->late_ticket_minutes ?: 20) * 60;
        $expr = 'GREATEST(0, TIMESTAMPDIFF(SECOND, k.sent_at, k.ready_at))';
        $agg = (clone $q)->selectRaw("COUNT(*) AS n, AVG($expr) AS a, SUM(CASE WHEN $expr >= ? THEN 1 ELSE 0 END) AS l", [$late])->first();
        $n = (int) $agg->n;
        // The k-th fastest ticket, for the median and the 90th percentile (every ticket counts).
        $pick = function (float $p) use ($q, $expr, $n) {
            if (! $n) {
                return null;
            }
            $v = (clone $q)->selectRaw("$expr AS s")->orderBy('s')->offset((int) min($n - 1, floor($p * ($n - 1))))->limit(1)->value('s');

            return $v === null ? null : round((int) $v / 60, 1);
        };

        return [
            'tickets' => $n,
            'avg_minutes' => $n ? round((float) $agg->a / 60, 1) : null,
            'median_minutes' => $pick(0.5),
            'p90_minutes' => $pick(0.9),
            'late_pct' => $n ? round((int) $agg->l / $n * 100, 1) : null,
            'late_minutes' => (int) ($late / 60),
        ];
    }

    private function payments(Carbon $from, Carbon $to): Builder
    {
        $q = DB::table('rst_order_payments as p')->join('rst_orders as o', 'o.id', '=', 'p.order_id')->whereBetween('p.created_at', [$from, $to]);
        // The bill's POS sale deleted in Sales → no longer a sale here either.
        if (Schema::hasTable('sales')) {
            $q->whereNotExists(fn ($w) => $w->selectRaw('1')->from('sales as sd')->whereColumn('sd.id', 'p.sale_id')->whereNotNull('sd.deleted_at'));
        }

        return $this->scopeCol($q, 'o.warehouse_id');
    }

    private function onlineOrders(Carbon $from, Carbon $to): Builder
    {
        // Before the phase 8 migration there is no link column: an empty set
        // that still has the o.* / oo.* columns the callers select.
        if (! Schema::hasColumn('rst_orders', 'online_order_id') || ! Schema::hasTable('online_orders')) {
            return DB::table('rst_orders as o')->crossJoin(DB::raw('(SELECT 0 AS total, NULL AS status) AS oo'))->whereRaw('1 = 0');
        }
        $q = DB::table('rst_orders as o')->join('online_orders as oo', 'oo.id', '=', 'o.online_order_id')
            ->whereBetween('o.created_at', [$from, $to])->where('o.status', '!=', 'cancelled')->whereNull('o.deleted_at')
            ->whereNotIn('oo.status', ['cancelled', 'canceled']);

        return $this->scopeCol($q, 'o.warehouse_id');
    }

    private function orders(): Builder
    {
        return $this->scopeCol(DB::table('rst_orders as o')->whereNull('o.deleted_at'), 'o.warehouse_id');
    }

    private function items(): Builder
    {
        return $this->scopeCol(DB::table('rst_order_items as i')->join('rst_orders as o', 'o.id', '=', 'i.order_id')->whereNull('o.deleted_at'), 'o.warehouse_id');
    }

    /** User scope (orders without a warehouse are shared) + the chosen branch. */
    private function scopeCol(Builder $q, string $col): Builder
    {
        if ($this->scope !== null) {
            $ids = $this->scope;
            $q->where(fn ($w) => $w->whereIn($col, $ids ?: [0])->orWhereNull($col));
        }
        if ($this->branch) {
            $branch = $this->branch;
            $q->where(fn ($w) => $w->where($col, $branch)->orWhereNull($col));
        }

        return $q;
    }

    private function userNames(array $ids): \Illuminate\Support\Collection
    {
        return DB::table('users')->whereIn('id', array_filter(array_unique($ids)))->get(['id', 'username', 'firstname', 'lastname'])
            ->mapWithKeys(fn ($u) => [$u->id => trim($u->firstname.' '.$u->lastname) ?: $u->username]);
    }
}
