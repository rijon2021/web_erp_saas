<?php

namespace App\Services\Booking;

use App\Models\Booking;
use App\Models\BookingStaff;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Booking Management › Dashboard and Reports.
 *
 * Bookings count by their appointment date (booking_date); website figures
 * (requests, answer time, conversion) count by when the request arrived
 * (created_at). Revenue = the POS sale a booking was charged to, net of its
 * sale returns; deleted sales count nothing. "Booked value" is what kept
 * bookings not charged yet are worth (booking price, else the service price,
 * × seats, before tax).
 *
 * Scope: $scope = the user's warehouse ids (null = all) — bookings without a
 * branch are shared and always count (also under a branch filter); $branch
 * narrows to one branch.
 */
class BookingReportService
{
    public const MAX_DAYS = 366;

    /** Working-hours utilisation is only worked out for ranges up to this long. */
    public const UTIL_MAX_DAYS = 92;

    public const REPORTS = ['bookings', 'services', 'staff', 'times', 'website', 'lost', 'customers'];

    /** Happened or will happen. */
    public const KEPT = ['confirmed', 'arrived', 'completed'];

    /** Did not happen. */
    public const LOST = ['cancelled', 'declined', 'no_show'];

    /** Rows loaded per range (rows() is called with the same range more than once). */
    private array $cache = [];

    public function __construct(private ?array $scope = null, private ?int $branch = null)
    {
    }

    // ------------------------------------------------------------------
    // Dashboard
    // ------------------------------------------------------------------

    public function dashboard(Carbon $from, Carbon $to, Carbon $pf, Carbon $pt, bool $withToday): array
    {
        $cur = $this->rows($from, $to);
        $prev = $this->rows($pf, $pt);
        $web = $this->websiteRows($from, $to);
        $webPrev = $this->websiteRows($pf, $pt);

        $kpis = [];
        foreach (['value' => [$cur, $web], 'prev' => [$prev, $webPrev]] as $key => [$rows, $w]) {
            $k = $this->counts($rows);
            $kpis['bookings'][$key] = $k['total'];
            $kpis['completed'][$key] = $k['completed'];
            $kpis['no_show_rate'][$key] = $this->pct($k['no_show'], $k['completed'] + $k['arrived'] + $k['no_show']);
            $kpis['cancel_rate'][$key] = $this->pct($k['cancelled'], $k['total']);
            $kpis['revenue'][$key] = round($rows->sum('revenue'), 2);
            $kpis['website'][$key] = $w->count();
            $kpis['answer_minutes'][$key] = $this->avg($w->pluck('answer_minutes')->filter(fn ($v) => $v !== null));
            $kpis['conversion'][$key] = $this->conversion($w);
        }

        $single = $from->isSameDay($to);
        $series = $single ? $this->byHour($cur) : $this->byDay($cur, $from, $to);

        return [
            'kpis' => $kpis,
            'booked_value' => round($cur->whereIn('status', self::KEPT)->whereNull('sale_total')->sum('expected'), 2),
            'hours' => $single ? $series : [],
            'days' => $single ? [] : $series,
            'statuses' => $this->statusRows($cur),
            'services' => array_slice($this->serviceRows($cur), 0, 6),
            'staff' => array_slice($this->staffRows($cur, $from, $to, false), 0, 6),
            'sources' => [
                ['source' => 'website', 'bookings' => $cur->where('source', 'website')->where('status', '!=', 'declined')->count()],
                ['source' => 'staff', 'bookings' => $cur->where('source', '!=', 'website')->where('status', '!=', 'declined')->count()],
            ],
            'today' => $withToday ? $this->today() : null,
        ];
    }

    /** What needs doing today: the schedule and the open website work. */
    public function today(): array
    {
        $date = now()->toDateString();
        $rows = $this->rows(now()->startOfDay(), now()->endOfDay())
            ->reject(fn ($r) => in_array($r->status, ['cancelled', 'declined'], true))
            ->sortBy('time')->values();
        $nowMin = (int) now()->format('H') * 60 + (int) now()->format('i');

        $open = $this->base()->where('b.status', 'pending')->where('b.source', 'website');
        $proposals = Booking::notificationsReady() ? (clone $open)->whereNotNull('b.proposed_at')->count() : 0;
        $expired = (clone $open)->whereNotNull('b.hold_until')->where('b.hold_until', '<', now())->count();

        return [
            'date' => $date,
            'schedule' => $rows->take(15)->map(fn ($r) => [
                'id' => $r->id, 'ref' => $r->ref, 'time' => $r->time, 'end_time' => $r->end_time,
                'customer' => $r->customer, 'service' => $r->service, 'staff' => $r->staff, 'staff_color' => $r->staff_color,
                'status' => $r->status, 'source' => $r->source, 'charged' => $r->sale_total !== null,
                'past' => $this->minutes($r->end_time ?: $r->time) < $nowMin,
            ])->all(),
            'more' => max(0, $rows->count() - 15),
            'total' => $rows->count(),
            'to_come' => $rows->filter(fn ($r) => in_array($r->status, ['pending', 'confirmed'], true) && $this->minutes($r->time) >= $nowMin)->count(),
            'arrived' => $rows->where('status', 'arrived')->count(),
            'completed' => $rows->where('status', 'completed')->count(),
            'not_charged' => $rows->whereIn('status', ['arrived', 'completed'])->whereNull('sale_total')->count(),
            'requests' => (clone $open)->count(),
            'proposals' => $proposals,
            'expired_holds' => $expired,
            'next_7_days' => $this->base()->whereIn('b.status', array_merge(self::KEPT, ['pending']))
                ->whereBetween('b.booking_date', [now()->addDay()->toDateString(), now()->addDays(7)->toDateString()])->count(),
        ];
    }

    // ------------------------------------------------------------------
    // Reports
    // ------------------------------------------------------------------

    /** Per day: every status + revenue. */
    public function bookings(Carbon $from, Carbon $to): array
    {
        $rows = $this->rows($from, $to);
        $byDate = $rows->groupBy('date');
        $out = [];
        for ($d = $from->copy()->startOfDay(); $d->lte($to); $d->addDay()) {
            $date = $d->toDateString();
            $day = $byDate->get($date, collect());
            if ($day->isEmpty()) {
                continue;
            }
            $out[] = ['date' => $date] + $this->counts($day) + [
                'seats' => (int) $day->whereNotIn('status', self::LOST)->sum('seats'),
                'revenue' => round($day->sum('revenue'), 2),
            ];
        }

        return [
            'rows' => $out,
            'totals' => $this->counts($rows) + [
                'seats' => (int) $rows->whereNotIn('status', self::LOST)->sum('seats'),
                'revenue' => round($rows->sum('revenue'), 2),
                'booked_value' => round($rows->whereIn('status', self::KEPT)->whereNull('sale_total')->sum('expected'), 2),
                'charged' => $rows->whereNotNull('sale_total')->count(),
                'paid' => round($rows->sum('paid'), 2),
            ],
        ];
    }

    public function services(Carbon $from, Carbon $to): array
    {
        return $this->serviceRows($this->rows($from, $to));
    }

    public function staff(Carbon $from, Carbon $to): array
    {
        // Whole days (Carbon 3 diffs are floats; $to is the end of its day).
        $long = (int) $from->copy()->startOfDay()->diffInDays($to->copy()->startOfDay()) + 1 > self::UTIL_MAX_DAYS;

        return [
            'rows' => $this->staffRows($this->rows($from, $to), $from, $to, ! $long),
            'utilisation' => ! $long,
            'util_max_days' => self::UTIL_MAX_DAYS,
        ];
    }

    /** Busiest weekday × hour, by weekday, and how far ahead people book. */
    public function times(Carbon $from, Carbon $to): array
    {
        $rows = $this->rows($from, $to)->reject(fn ($r) => $r->status === 'declined' || $r->pending_request);
        $grid = [];
        $weekdays = array_fill_keys(BookingStaff::WEEKDAYS, 0);
        foreach ($rows as $r) {
            $wd = Carbon::parse($r->date)->isoWeekday();
            $h = intdiv((int) $this->minutes($r->time), 60);
            $grid[$wd][$h] = ($grid[$wd][$h] ?? 0) + 1;
            $weekdays[$wd]++;
        }
        $hours = [];
        foreach ($grid as $wd => $hs) {
            foreach ($hs as $h => $n) {
                $hours[$h] = ($hours[$h] ?? 0) + $n;
            }
        }
        ksort($hours);
        $cells = [];
        foreach ($grid as $wd => $hs) {
            foreach ($hs as $h => $n) {
                $cells[] = ['weekday' => $wd, 'hour' => $h, 'bookings' => $n];
            }
        }
        $busiest = collect($cells)->sortByDesc('bookings')->first();

        $buckets = ['same_day' => 0, 'd1_2' => 0, 'd3_7' => 0, 'd8_30' => 0, 'd30_plus' => 0];
        $leads = [];
        foreach ($rows as $r) {
            if (! $r->created_at) {
                continue;
            }
            $days = Carbon::parse(substr($r->created_at, 0, 10))->diffInDays(Carbon::parse($r->date), false);
            if ($days < 0) {
                continue; // entered after the fact
            }
            $leads[] = $days;
            $key = $days < 1 ? 'same_day' : ($days <= 2 ? 'd1_2' : ($days <= 7 ? 'd3_7' : ($days <= 30 ? 'd8_30' : 'd30_plus')));
            $buckets[$key]++;
        }

        return [
            'cells' => $cells,
            'hour_min' => $hours ? (int) min(array_keys($hours)) : null,
            'hour_max' => $hours ? (int) max(array_keys($hours)) : null,
            'weekdays' => collect($weekdays)->map(fn ($n, $wd) => ['weekday' => $wd, 'bookings' => $n])->values()->all(),
            'hours' => collect($hours)->map(fn ($n, $h) => ['hour' => $h, 'bookings' => $n])->values()->all(),
            'busiest' => $busiest,
            'lead' => collect($buckets)->map(fn ($n, $k) => ['bucket' => $k, 'bookings' => $n])->values()->all(),
            'avg_lead_days' => $this->avg(collect($leads), 1),
            'total' => $rows->count(),
        ];
    }

    /** Website requests by the day they arrived: how many, how fast answered, how many became bookings. */
    public function website(Carbon $from, Carbon $to): array
    {
        $w = $this->websiteRows($from, $to);
        $pending = $w->where('status', 'pending');
        $expired = $pending->filter(fn ($r) => $r->hold_until && $r->hold_until < now()->format('Y-m-d H:i:s'));
        $answers = $w->pluck('answer_minutes')->filter(fn ($v) => $v !== null)->sort()->values();
        $suggested = 0;
        $suggestedKept = 0;
        if (Booking::notificationsReady() && $w->isNotEmpty()) {
            $ids = DB::table('bk_messages')->where('kind', 'proposal')->whereIn('booking_id', $w->pluck('id')->all())
                ->distinct()->pluck('booking_id')->map(fn ($id) => (int) $id)->all();
            $suggested = count($ids);
            $suggestedKept = $w->whereIn('id', $ids)->whereIn('status', self::KEPT)->count();
        }
        $byDay = [];
        foreach ($w->groupBy('created_date') as $date => $day) {
            $byDay[] = ['date' => $date, 'requests' => $day->count(), 'confirmed' => $day->whereIn('status', array_merge(self::KEPT, ['no_show']))->count(),
                'declined' => $day->where('status', 'declined')->count(), 'cancelled' => $day->where('status', 'cancelled')->count(), 'pending' => $day->where('status', 'pending')->count()];
        }
        usort($byDay, fn ($a, $b) => strcmp($a['date'], $b['date']));

        $byService = [];
        foreach ($w->groupBy(fn ($r) => (int) $r->product_id) as $pid => $g) {
            $byService[] = ['id' => $pid, 'name' => $g->first()->service ?: '—', 'requests' => $g->count(),
                'confirmed' => $g->whereIn('status', array_merge(self::KEPT, ['no_show']))->count(),
                'declined' => $g->where('status', 'declined')->count()];
        }
        usort($byService, fn ($a, $b) => $b['requests'] <=> $a['requests']);

        return [
            'totals' => [
                'requests' => $w->count(),
                'slot' => $w->where('mode', 'slot')->count(),
                'request' => $w->where('mode', 'request')->count(),
                'confirmed' => $w->whereIn('status', array_merge(self::KEPT, ['no_show']))->count(),
                'declined' => $w->where('status', 'declined')->count(),
                'cancelled_customer' => $w->where('status', 'cancelled')->where('cancelled_by', 'customer')->count(),
                'cancelled_staff' => $w->where('status', 'cancelled')->where('cancelled_by', '!=', 'customer')->count(),
                'pending' => $pending->count(),
                'expired' => $expired->count(),
                'conversion' => $this->conversion($w),
                'avg_answer_minutes' => $this->avg($answers),
                'median_answer_minutes' => $answers->isEmpty() ? null : (int) round($answers[intdiv($answers->count(), 2)]),
                'suggested' => $suggested,
                'suggested_kept' => $suggestedKept,
            ],
            'days' => $byDay,
            'services' => $byService,
        ];
    }

    /** Cancelled, declined and no-show bookings, with who and why. */
    public function lost(Carbon $from, Carbon $to): array
    {
        $rows = $this->rows($from, $to)->whereIn('status', self::LOST)->sortBy([['date', 'desc'], ['time', 'desc']])->values();
        $reasons = [];
        foreach ($rows as $r) {
            $why = trim(mb_strtolower((string) $r->cancel_reason));
            if ($why === '') {
                continue;
            }
            $reasons[$why] = $reasons[$why] ?? ['reason' => trim((string) $r->cancel_reason), 'bookings' => 0];
            $reasons[$why]['bookings']++;
        }
        usort($reasons, fn ($a, $b) => $b['bookings'] <=> $a['bookings']);
        $all = $this->rows($from, $to);

        return [
            'totals' => [
                'cancelled' => $rows->where('status', 'cancelled')->count(),
                'by_customer' => $rows->where('status', 'cancelled')->where('cancelled_by', 'customer')->count(),
                'declined' => $rows->where('status', 'declined')->count(),
                'no_show' => $rows->where('status', 'no_show')->count(),
                'value' => round($rows->sum('expected'), 2),
                'share' => $this->pct($rows->count(), $all->count()),
            ],
            'reasons' => array_slice($reasons, 0, 8),
            'rows' => $rows->take(500)->map(fn ($r) => [
                'id' => $r->id, 'ref' => $r->ref, 'date' => $r->date, 'time' => $r->time, 'customer' => $r->customer,
                'service' => $r->service, 'staff' => $r->staff, 'status' => $r->status, 'source' => $r->source,
                'by' => $r->status === 'cancelled' ? ($r->cancelled_by === 'customer' ? 'customer' : 'staff') : null,
                'reason' => $r->cancel_reason, 'value' => round($r->expected, 2),
            ])->all(),
            'truncated' => $rows->count() > 500,
        ];
    }

    /** Who books: new vs returning and the best customers. */
    public function customers(Carbon $from, Carbon $to): array
    {
        $rows = $this->rows($from, $to)->where('status', '!=', 'declined')->filter(fn ($r) => $r->customer_id);
        $ids = $rows->pluck('customer_id')->unique()->values()->all();
        $before = $ids ? $this->base()->whereIn('b.customer_id', $ids)->where('b.booking_date', '<', $from->toDateString())
            ->whereNotIn('b.status', ['declined'])->distinct()->pluck('b.customer_id')->map(fn ($id) => (int) $id)->flip()->all() : [];
        $out = [];
        foreach ($rows->groupBy('customer_id') as $cid => $g) {
            $out[] = [
                'id' => (int) $cid, 'name' => $g->first()->customer ?: '—', 'phone' => $g->first()->customer_phone,
                'bookings' => $g->count(), 'completed' => $g->where('status', 'completed')->count(),
                'no_show' => $g->where('status', 'no_show')->count(), 'cancelled' => $g->where('status', 'cancelled')->count(),
                'revenue' => round($g->sum('revenue'), 2), 'last' => $g->max('date'),
                // New = no booking before the range.
                'new' => ! isset($before[(int) $cid]),
            ];
        }
        usort($out, fn ($a, $b) => [$b['bookings'], $b['revenue']] <=> [$a['bookings'], $a['revenue']]);
        $new = collect($out)->where('new', true)->count();

        return [
            'totals' => [
                'customers' => count($out),
                'new' => $new,
                'returning' => count($out) - $new,
                'repeat' => collect($out)->where('bookings', '>', 1)->count(),
                'avg_bookings' => count($out) ? round($rows->count() / count($out), 1) : null,
            ],
            'rows' => array_slice($out, 0, 300),
        ];
    }

    // ------------------------------------------------------------------
    // Building blocks
    // ------------------------------------------------------------------

    /** @return array{total:int, pending:int, confirmed:int, arrived:int, completed:int, cancelled:int, declined:int, no_show:int} total leaves declined out */
    private function counts(Collection $rows): array
    {
        $by = $rows->countBy('status');
        $out = ['total' => $rows->where('status', '!=', 'declined')->count()];
        foreach (Booking::STATUSES as $s) {
            $out[$s] = (int) ($by[$s] ?? 0);
        }

        return $out;
    }

    private function statusRows(Collection $rows): array
    {
        $out = [];
        foreach ($rows->countBy('status') as $status => $n) {
            $out[] = ['status' => $status, 'bookings' => $n];
        }
        usort($out, fn ($a, $b) => array_search($a['status'], Booking::STATUSES, true) <=> array_search($b['status'], Booking::STATUSES, true));

        return $out;
    }

    private function serviceRows(Collection $rows): array
    {
        $out = [];
        foreach ($rows->groupBy(fn ($r) => (int) $r->product_id) as $pid => $g) {
            // Time actually given to the service (as for staff): kept bookings and no-shows.
            $kept = $g->whereIn('status', array_merge(self::KEPT, ['no_show']));
            $out[] = [
                'id' => $pid, 'name' => $g->first()->service ?: '—', 'category' => $g->first()->category,
                'bookings' => $g->where('status', '!=', 'declined')->count(),
                'completed' => $g->where('status', 'completed')->count(),
                'no_show' => $g->where('status', 'no_show')->count(),
                'cancelled' => $g->whereIn('status', ['cancelled', 'declined'])->count(),
                'seats' => (int) $kept->sum('seats'),
                'hours' => round($kept->sum('minutes') / 60, 1),
                'revenue' => round($g->sum('revenue'), 2),
                'avg_revenue' => $g->whereNotNull('sale_total')->count() ? round($g->sum('revenue') / $g->whereNotNull('sale_total')->count(), 2) : null,
            ];
        }
        usort($out, fn ($a, $b) => [$b['bookings'], $b['revenue']] <=> [$a['bookings'], $a['revenue']]);

        return $out;
    }

    private function staffRows(Collection $rows, Carbon $from, Carbon $to, bool $withUtilisation): array
    {
        $groups = $rows->groupBy(fn ($r) => (int) $r->staff_id);
        $working = [];
        $names = collect();
        if ($withUtilisation) {
            // Everyone who had bookings, plus active staff who worked but had none.
            $booked = array_values(array_filter($groups->keys()->all()));
            $staff = $this->scopeCol(BookingStaff::withTrashed()->where(fn ($q) => $q->whereIn('id', $booked ?: [0])
                ->orWhere(fn ($a) => $a->where('active', true)->whereNull('deleted_at'))), 'warehouse_id')
                ->get();
            $working = (new AvailabilityService())->workingMinutes($staff, $from->toDateString(), $to->toDateString());
            foreach ($staff as $s) {
                if (! $groups->has((int) $s->id) && ($working[(int) $s->id] ?? 0) > 0) {
                    $groups->put((int) $s->id, collect());
                }
            }
            $names = $staff->keyBy('id');
        }
        $out = [];
        foreach ($groups as $sid => $g) {
            $kept = $g->whereIn('status', array_merge(self::KEPT, ['no_show']));
            $first = $g->first();
            $minutes = (int) $kept->sum('minutes');
            $work = $sid && $withUtilisation ? (int) ($working[$sid] ?? 0) : null;
            $out[] = [
                'id' => $sid ?: null,
                'name' => $first->staff ?? (isset($names[$sid]) ? $names[$sid]->name : null),
                'color' => $first->staff_color ?? (isset($names[$sid]) ? $names[$sid]->color : null),
                'bookings' => $g->where('status', '!=', 'declined')->count(),
                'completed' => $g->where('status', 'completed')->count(),
                'no_show' => $g->where('status', 'no_show')->count(),
                'cancelled' => $g->where('status', 'cancelled')->count(),
                'booked_hours' => round($minutes / 60, 1),
                'working_hours' => $work === null ? null : round($work / 60, 1),
                'utilisation' => $work ? min(100, (int) round($minutes / $work * 100)) : null,
                'revenue' => round($g->sum('revenue'), 2),
            ];
        }
        usort($out, fn ($a, $b) => [$a['id'] === null, -$a['bookings'], -$a['revenue']] <=> [$b['id'] === null, -$b['bookings'], -$b['revenue']]);

        return $out;
    }

    private function byDay(Collection $rows, Carbon $from, Carbon $to): array
    {
        $by = $rows->groupBy('date');
        $out = [];
        for ($d = $from->copy()->startOfDay(); $d->lte($to); $d->addDay()) {
            $g = $by->get($d->toDateString(), collect());
            $out[] = ['date' => $d->toDateString()] + $this->split($g);
        }

        return $out;
    }

    private function byHour(Collection $rows): array
    {
        $by = $rows->groupBy(fn ($r) => intdiv((int) $this->minutes($r->time), 60));
        $out = [];
        for ($h = 0; $h < 24; $h++) {
            $out[] = ['hour' => $h] + $this->split($by->get($h, collect()));
        }

        return $out;
    }

    /** Chart split: kept (confirmed/arrived/completed), waiting (pending) and lost (cancelled/no-show; declined left out). */
    private function split(Collection $g): array
    {
        return [
            'kept' => $g->whereIn('status', self::KEPT)->count(),
            'pending' => $g->where('status', 'pending')->count(),
            'lost' => $g->whereIn('status', ['cancelled', 'no_show'])->count(),
        ];
    }

    /**
     * Bookings whose appointment falls in the range, one plain object each
     * (customer/service/staff names, minutes, revenue, booked value).
     */
    public function rows(Carbon $from, Carbon $to): Collection
    {
        $key = $from->toDateString().'|'.$to->toDateString();
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }
        $q = $this->query()->whereBetween('b.booking_date', [$from->toDateString(), $to->toDateString()]);

        return $this->cache[$key] = $this->hydrate($q->get());
    }

    /** Website bookings by when they arrived. */
    private function websiteRows(Carbon $from, Carbon $to): Collection
    {
        $q = $this->query()->where('b.source', 'website')
            ->whereBetween('b.created_at', [$from->copy()->startOfDay()->format('Y-m-d H:i:s'), $to->copy()->endOfDay()->format('Y-m-d H:i:s')]);

        return $this->hydrate($q->get());
    }

    private function query(): Builder
    {
        $returns = DB::table('sale_returns')->whereNull('deleted_at')->whereNotNull('sale_id')
            ->groupBy('sale_id')->select('sale_id', DB::raw('SUM(GrandTotal) as returned'));
        $hasCat = Schema::hasColumn('products', 'category_id');

        return $this->base()
            ->leftJoin('clients as c', 'c.id', '=', 'b.customer_id')
            ->leftJoin('products as p', 'p.id', '=', 'b.product_id')
            ->when($hasCat, fn ($q) => $q->leftJoin('categories as cat', 'cat.id', '=', 'p.category_id'))
            ->leftJoin('bk_services as d', 'd.product_id', '=', 'b.product_id')
            ->leftJoin('bk_staff as st', 'st.id', '=', 'b.staff_id')
            ->leftJoin('sales as s', fn ($j) => $j->on('s.id', '=', 'b.sale_id')->whereNull('s.deleted_at'))
            ->leftJoinSub($returns, 'r', 'r.sale_id', '=', 's.id')
            ->select([
                'b.id', 'b.Ref', 'b.status', 'b.source', 'b.booking_mode', 'b.customer_id', 'b.product_id', 'b.product_name', 'b.staff_id',
                'b.booking_date', 'b.booking_time', 'b.booking_end_time', 'b.duration_minutes', 'b.seats', 'b.price',
                'b.created_at', 'b.confirmed_at', 'b.cancelled_by', 'b.cancel_reason', 'b.hold_until', 'b.sale_id',
                'c.name as customer_name', 'c.phone as customer_phone', 'p.name as product_label', 'p.price as product_price',
                $hasCat ? 'cat.name as category_name' : DB::raw('NULL as category_name'),
                'd.duration_minutes as service_minutes', 'st.name as staff_name', 'st.color as staff_color',
                's.GrandTotal as sale_total', 's.paid_amount as sale_paid', 'r.returned',
            ]);
    }

    private function base(): Builder
    {
        return $this->scopeCol(DB::table('bookings as b')->whereNull('b.deleted_at'), 'b.warehouse_id');
    }

    private function hydrate(Collection $raw): Collection
    {
        return $raw->map(function ($b) {
            $time = substr((string) $b->booking_time, 0, 5);
            $end = $b->booking_end_time ? substr((string) $b->booking_end_time, 0, 5) : null;
            $start = $this->minutes($time);
            $stop = $end !== null ? $this->minutes($end) : null;
            $minutes = $start !== null && $stop !== null && $stop > $start
                ? $stop - $start
                : max(5, (int) ($b->duration_minutes ?: ($b->service_minutes ?: 60)));
            $seats = max(1, (int) ($b->seats ?: 1));
            $price = $b->price !== null && (float) $b->price > 0 ? (float) $b->price : (float) ($b->product_price ?? 0);
            $sale = $b->sale_total !== null ? (float) $b->sale_total : null;
            $created = $b->created_at ? (string) $b->created_at : null;

            return (object) [
                'id' => (int) $b->id,
                'ref' => $b->Ref,
                'status' => (string) $b->status,
                'source' => $b->source ?: 'staff',
                'mode' => $b->booking_mode,
                'pending_request' => $b->status === 'pending' && $b->booking_mode === 'request',
                'customer_id' => $b->customer_id ? (int) $b->customer_id : null,
                'customer' => $b->customer_name,
                'customer_phone' => $b->customer_phone,
                'product_id' => $b->product_id ? (int) $b->product_id : null,
                'service' => $b->product_label ?: $b->product_name,
                'category' => $b->category_name,
                'staff_id' => $b->staff_id ? (int) $b->staff_id : null,
                'staff' => $b->staff_name,
                'staff_color' => $b->staff_color,
                'date' => substr((string) $b->booking_date, 0, 10),
                'time' => $time,
                'end_time' => $end,
                'minutes' => $minutes,
                'seats' => $seats,
                'expected' => round($price * $seats, 2),
                'sale_total' => $sale,
                'revenue' => $sale !== null ? round($sale - (float) ($b->returned ?? 0), 2) : 0.0,
                'paid' => $sale !== null ? (float) $b->sale_paid : 0.0,
                'created_at' => $created,
                'created_date' => $created ? substr($created, 0, 10) : null,
                'answer_minutes' => $created && $b->confirmed_at
                    ? max(0, (int) round((strtotime((string) $b->confirmed_at) - strtotime($created)) / 60)) : null,
                'cancelled_by' => $b->cancelled_by,
                'cancel_reason' => $b->cancel_reason,
                'hold_until' => $b->hold_until ? (string) $b->hold_until : null,
            ];
        });
    }

    /** User scope (bookings without a branch are shared) + the chosen branch. */
    private function scopeCol($q, string $col)
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

    private function minutes(?string $hhmm): ?int
    {
        return AvailabilityService::minutes($hhmm);
    }

    /**
     * Share of answered website requests you took: confirmed (incl. later
     * arrived, completed or no-show) ÷ (those + declined). Customer
     * cancellations and requests still pending are left out.
     */
    private function conversion(Collection $w): ?int
    {
        $taken = $w->whereIn('status', array_merge(self::KEPT, ['no_show']))->count();

        return $this->pct($taken, $taken + $w->where('status', 'declined')->count());
    }

    private function pct(int $part, int $whole): ?int
    {
        return $whole > 0 ? (int) round($part / $whole * 100) : null;
    }

    private function avg(Collection $values, int $precision = 0): int|float|null
    {
        if ($values->isEmpty()) {
            return null;
        }
        $avg = round($values->avg(), $precision);

        return $precision === 0 ? (int) $avg : $avg;
    }
}
