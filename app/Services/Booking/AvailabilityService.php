<?php

namespace App\Services\Booking;

use App\Models\Booking;
use App\Models\BookingServiceDetail;
use App\Models\BookingSetting;
use App\Models\BookingStaff;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * When a staff member is free for a service.
 *
 * Working time for a day = the staff member's hours for that weekday, minus
 * the break, minus their days off (bk_staff_time_off), and nothing at all on
 * a company holiday or a day of approved HRM leave (a half day of leave blocks
 * the whole day — HRM does not say which half).
 *
 * Busy time = their bookings that day in a BLOCKING status, each widened by
 * its service's gap before / after. Not busy: a website booking whose hold
 * has run out (pending with hold_until in the past) and a request that has no
 * time yet (booking_mode = request, still pending). Bookings without a
 * staff member block nobody.
 *
 * A start time fits when the service itself lies inside working time and the
 * service plus its gaps touches no busy time. Classes (seats > 1) share: a
 * booking of the same service at the same start uses seats instead of
 * blocking, until the seats run out.
 *
 * All times are wall-clock minutes from midnight in the tenant timezone
 * (config('app.timezone')); dates are "Y-m-d".
 */
class AvailabilityService
{
    /** Booking statuses that take up staff time. */
    public const BLOCKING = ['pending', 'confirmed', 'arrived', 'completed'];

    private BookingSetting $settings;

    /** @var array<int, array<int, object>> staff id → weekday → hours row */
    private array $hours = [];

    /** @var array<int, array<int, object>> staff id → time-off rows */
    private array $timeOff = [];

    /** @var array<int, array<int, array{0: string, 1: string}>> employee id → [start, end] approved leave */
    private array $leave = [];

    /** @var array<int, array{0: string, 1: string}> company holidays [start, end] */
    private array $holidays = [];

    /** @var array<string, array<int, array<int, array>>> date → staff id → busy entries */
    private array $busy = [];

    /** @var array<int, BookingServiceDetail|null> */
    private array $details = [];

    /** @var array{from: string, to: string, ids: int[], ignore: ?int}|null */
    private ?array $loaded = null;

    public function __construct(?BookingSetting $settings = null)
    {
        $this->settings = $settings ?? BookingSetting::current();
    }

    // ------------------------------------------------------------------
    // Public API
    // ------------------------------------------------------------------

    /** Booking details of a live service product, or null when it is not bookable. */
    public function detail(int $productId): ?BookingServiceDetail
    {
        if (! array_key_exists($productId, $this->details)) {
            $this->details[$productId] = BookingServiceDetail::where('product_id', $productId)
                ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('products')
                    ->whereColumn('products.id', 'bk_services.product_id')
                    ->whereNull('products.deleted_at')->where('products.type', 'is_service'))
                ->first();
        }

        return $this->details[$productId];
    }

    /** Active staff who do this service: the linked ones, or everyone when none are linked. */
    public function serviceStaff(int $productId, bool $online = false): Collection
    {
        $linked = DB::table('bk_service_staff')->where('product_id', $productId)->pluck('staff_id')->all();

        return BookingStaff::query()->active()
            ->when($linked, fn ($q) => $q->whereIn('id', $linked))
            ->when($online, fn ($q) => $q->where('bookable_online', true))
            ->orderBy('sort_order')->orderBy('name')
            ->get();
    }

    /**
     * Free start times for a service on one day.
     *
     * @param  array{online?: bool, ignore_booking_id?: int|null}  $opt  online = the website asks:
     *         only staff bookable online, inside the notice / days-ahead window.
     * @return array<int, array{time: string, end: string, staff_ids: int[], seats_left: int|null}>
     */
    public function slots(int $productId, string $date, ?int $staffId = null, int $seats = 1, array $opt = []): array
    {
        $detail = $this->detail($productId);
        if (! $detail || $seats < 1 || $seats > max(1, (int) $detail->seats)) {
            return [];
        }
        $online = (bool) ($opt['online'] ?? false);
        $ignore = $opt['ignore_booking_id'] ?? null;

        $staff = $this->serviceStaff($productId, $online);
        if ($staffId !== null) {
            $staff = $staff->where('id', $staffId)->values();
        }
        if ($staff->isEmpty() || ! $this->validDate($date)) {
            return [];
        }

        $earliest = null;
        if ($online) {
            $today = now()->toDateString();
            $last = now()->addDays(max(1, (int) $this->settings->max_days_ahead))->toDateString();
            if ($date < $today || $date > $last) {
                return [];
            }
            $earliest = now()->addMinutes((int) $this->settings->min_notice_minutes);
        }

        $this->load($staff, $date, $date, $ignore);
        $dur = max(5, (int) $detail->duration_minutes);
        $step = (int) $this->settings->slot_step_minutes ?: $dur;

        $found = [];
        foreach ($staff as $member) {
            if ($member->max_per_day && $this->countFor($member->id, $date) >= $member->max_per_day) {
                continue;
            }
            $busy = $this->busy[$date][$member->id] ?? [];
            foreach ($this->workingIntervals($member, $date) as [$ws, $we]) {
                for ($t = $ws; $t + $dur <= $we; $t += $step) {
                    if ($earliest && $this->at($date, $t)->lt($earliest)) {
                        continue;
                    }
                    [$ok, $left] = $this->fits($t, $dur, $detail, $busy, $seats);
                    if (! $ok) {
                        continue;
                    }
                    $found[$t]['staff_ids'][] = $member->id;
                    if ($left !== null) {
                        $found[$t]['seats_left'] = max($found[$t]['seats_left'] ?? 0, $left);
                    }
                }
            }
        }
        ksort($found);

        $out = [];
        foreach ($found as $t => $row) {
            $out[] = [
                'time' => self::hhmm($t),
                'end' => self::hhmm($t + $dur),
                'staff_ids' => $row['staff_ids'],
                'seats_left' => $row['seats_left'] ?? null,
            ];
        }

        return $out;
    }

    /**
     * How many free start times each day in [from, to] has (for a month
     * calendar). Same rules as slots().
     *
     * @return array<string, int> date → number of free start times
     */
    public function days(int $productId, string $from, string $to, ?int $staffId = null, int $seats = 1, bool $online = false): array
    {
        if (! $this->validDate($from) || ! $this->validDate($to) || $to < $from) {
            return [];
        }
        $staff = $this->serviceStaff($productId, $online);
        if ($staffId !== null) {
            $staff = $staff->where('id', $staffId)->values();
        }
        $this->load($staff, $from, $to, null);

        $out = [];
        $d = Carbon::createFromFormat('Y-m-d', $from)->startOfDay();
        $end = Carbon::createFromFormat('Y-m-d', $to)->startOfDay();
        for ($i = 0; $d->lte($end) && $i < 92; $d->addDay(), $i++) {
            $date = $d->toDateString();
            $out[$date] = count($this->slots($productId, $date, $staffId, $seats, ['online' => $online]));
        }

        return $out;
    }

    /**
     * Can this booking go ahead? For staff entering or confirming a booking —
     * no website notice / days-ahead rules.
     *
     * @return array{ok: bool, reason: ?string, clashes: array, hours: ?string}
     *         reason: staff_unknown | staff_not_for_service | day_off | holiday | leave | time_off
     *                 | outside_hours | clash | class_full | daily_limit | bad_time
     */
    public function check(?int $productId, int $staffId, string $date, string $time, ?string $endTime = null, int $seats = 1, ?int $ignoreBookingId = null): array
    {
        $result = fn (?string $reason, array $extra = []) => array_merge(['ok' => $reason === null, 'reason' => $reason, 'clashes' => [], 'hours' => null], $extra);

        $start = self::minutes($time);
        if ($start === null || ! $this->validDate($date)) {
            return $result('bad_time');
        }
        $member = BookingStaff::query()->active()->find($staffId);
        if (! $member) {
            return $result('staff_unknown');
        }
        $detail = $productId ? $this->detail($productId) : null;
        if ($productId) {
            $linked = DB::table('bk_service_staff')->where('product_id', $productId)->pluck('staff_id')->all();
            if ($linked && ! in_array($staffId, array_map('intval', $linked), true)) {
                return $result('staff_not_for_service');
            }
        }
        $end = $endTime !== null && $endTime !== '' ? self::minutes($endTime) : null;
        $dur = $end !== null && $end > $start ? $end - $start : max(5, (int) ($detail->duration_minutes ?? 60));

        $this->load(collect([$member]), $date, $date, $ignoreBookingId);

        $closed = $this->closedReason($member, $date);
        if ($closed) {
            return $result($closed);
        }
        $windows = $this->workingIntervals($member, $date);
        $inside = collect($windows)->contains(fn ($w) => $start >= $w[0] && $start + $dur <= $w[1]);
        if (! $inside) {
            return $result('outside_hours', ['hours' => $this->describeWindows($windows)]);
        }

        $busy = $this->busy[$date][$member->id] ?? [];
        $probe = $detail ?? new BookingServiceDetail(['product_id' => $productId, 'seats' => 1, 'buffer_before' => 0, 'buffer_after' => 0]);
        [$ok, $left] = $this->fits($start, $dur, $probe, $busy, max(1, $seats));
        if (! $ok) {
            $clashes = $this->overlapping($start, $dur, $probe, $busy);
            if (! $clashes && $left !== null) {
                return $result('class_full');
            }

            return $result('clash', ['clashes' => $clashes]);
        }
        if ($member->max_per_day && $this->countFor($member->id, $date) >= $member->max_per_day) {
            return $result('daily_limit');
        }

        return $result(null);
    }

    /** The staff member with the fewest bookings that day (ties: the order given). */
    public function pickStaff(array $staffIds, string $date): ?int
    {
        if (! $staffIds) {
            return null;
        }
        $staff = BookingStaff::whereIn('id', $staffIds)->get();
        $this->load($staff, $date, $date, $this->loaded['ignore'] ?? null);
        $best = null;
        $bestCount = PHP_INT_MAX;
        foreach ($staffIds as $id) {
            $n = $this->countFor((int) $id, $date);
            if ($n < $bestCount) {
                $best = (int) $id;
                $bestCount = $n;
            }
        }

        return $best;
    }

    /** Working intervals [[startMin, endMin], …] of one staff member on one day. */
    public function workingIntervals(BookingStaff $member, string $date): array
    {
        $this->load(collect([$member]), $date, $date, $this->loaded['ignore'] ?? null);
        if ($this->closedReason($member, $date, false)) {
            return [];
        }
        $row = $this->hours[$member->id][$this->weekday($date)] ?? null;
        $start = self::minutes($row->start);
        $end = self::minutes($row->end);
        if ($start === null || $end === null || $end <= $start) {
            return [];
        }
        $iv = [[$start, $end]];
        $bs = self::minutes($row->break_start);
        $be = self::minutes($row->break_end);
        if ($bs !== null && $be !== null && $be > $bs) {
            $iv = self::subtract($iv, $bs, $be);
        }
        foreach ($this->timeOff[$member->id] ?? [] as $off) {
            if ($date < $off->start_date || $date > $off->end_date) {
                continue;
            }
            $from = $date === $off->start_date && $off->from_time ? self::minutes($off->from_time) : 0;
            $to = $date === $off->end_date && $off->to_time ? self::minutes($off->to_time) : 1440;
            $iv = self::subtract($iv, $from ?? 0, $to ?? 1440);
        }

        return $iv;
    }

    /**
     * Minutes each staff member works over a date range (breaks, days off,
     * leave and holidays taken out) — the denominator of utilisation.
     *
     * @return array<int, int> staff id → minutes
     */
    public function workingMinutes(Collection $staff, string $from, string $to): array
    {
        $out = [];
        if ($staff->isEmpty() || ! $this->validDate($from) || ! $this->validDate($to) || $to < $from) {
            return $out;
        }
        $this->load($staff, $from, $to, null);
        foreach ($staff as $member) {
            $total = 0;
            for ($d = Carbon::parse($from); $d->toDateString() <= $to; $d->addDay()) {
                foreach ($this->workingIntervals($member, $d->toDateString()) as [$a, $b]) {
                    $total += max(0, $b - $a);
                }
            }
            $out[(int) $member->id] = $total;
        }

        return $out;
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /** Why a staff member does not work at all that day, or null. */
    private function closedReason(BookingStaff $member, string $date, bool $withTimeOff = true): ?string
    {
        if (! isset($this->hours[$member->id][$this->weekday($date)])) {
            return 'day_off';
        }
        foreach ($this->holidays as [$a, $b]) {
            if ($date >= $a && $date <= $b) {
                return 'holiday';
            }
        }
        foreach ($member->employee_id ? ($this->leave[$member->employee_id] ?? []) : [] as [$a, $b]) {
            if ($date >= $a && $date <= $b) {
                return 'leave';
            }
        }
        if ($withTimeOff) {
            $iv = $this->workingIntervalsUnchecked($member, $date);
            if (! $iv) {
                return 'time_off';
            }
        }

        return null;
    }

    /** workingIntervals() minus the whole-day checks (used by closedReason to spot a day fully taken by time off). */
    private function workingIntervalsUnchecked(BookingStaff $member, string $date): array
    {
        $row = $this->hours[$member->id][$this->weekday($date)];
        $iv = [[self::minutes($row->start) ?? 0, self::minutes($row->end) ?? 0]];
        foreach ($this->timeOff[$member->id] ?? [] as $off) {
            if ($date < $off->start_date || $date > $off->end_date) {
                continue;
            }
            $from = $date === $off->start_date && $off->from_time ? self::minutes($off->from_time) : 0;
            $to = $date === $off->end_date && $off->to_time ? self::minutes($off->to_time) : 1440;
            $iv = self::subtract($iv, $from ?? 0, $to ?? 1440);
        }

        return array_values(array_filter($iv, fn ($w) => $w[1] > $w[0]));
    }

    /**
     * Whether [t, t+dur] (plus the service's gaps) is free of busy time.
     *
     * @return array{0: bool, 1: int|null} [fits, seats left for a class (null otherwise)]
     */
    private function fits(int $t, int $dur, BookingServiceDetail $detail, array $busy, int $seats): array
    {
        $a = $t - (int) $detail->buffer_before;
        $b = $t + $dur + (int) $detail->buffer_after;
        $class = (int) $detail->seats > 1;
        $taken = 0;
        foreach ($busy as $e) {
            if ($e['end'] <= $a || $e['start'] >= $b) {
                continue;
            }
            if ($class && $e['product_id'] === (int) $detail->product_id && $e['core_start'] === $t) {
                $taken += $e['seats'];
                continue;
            }

            return [false, $class ? 0 : null];
        }
        if ($class) {
            $left = (int) $detail->seats - $taken;

            return [$left >= $seats, $left];
        }

        return [true, null];
    }

    /** Busy entries that clash with [t, t+dur] (class-mates excluded). */
    private function overlapping(int $t, int $dur, BookingServiceDetail $detail, array $busy): array
    {
        $a = $t - (int) $detail->buffer_before;
        $b = $t + $dur + (int) $detail->buffer_after;
        $out = [];
        foreach ($busy as $e) {
            if ($e['end'] <= $a || $e['start'] >= $b) {
                continue;
            }
            if ((int) $detail->seats > 1 && $e['product_id'] === (int) $detail->product_id && $e['core_start'] === $t) {
                continue;
            }
            $out[] = ['id' => $e['id'], 'ref' => $e['ref'], 'time' => self::hhmm($e['core_start']).' – '.self::hhmm($e['core_end'])];
        }

        return $out;
    }

    private function countFor(int $staffId, string $date): int
    {
        return count(array_unique(array_column($this->busy[$date][$staffId] ?? [], 'id')));
    }

    /** Loads hours, time off, leave, holidays and bookings once for the staff and date range. */
    private function load(Collection $staff, string $from, string $to, ?int $ignore): void
    {
        $ids = $staff->pluck('id')->map(fn ($id) => (int) $id)->all();
        $l = $this->loaded;
        if ($l && $from >= $l['from'] && $to <= $l['to'] && $ignore === $l['ignore'] && ! array_diff($ids, $l['ids'])) {
            return;
        }
        if ($l && $ignore === $l['ignore']) {
            // Widen to cover what was loaded before, so earlier callers stay answered.
            $ids = array_values(array_unique(array_merge($ids, $l['ids'])));
            $from = min($from, $l['from']);
            $to = max($to, $l['to']);
        }

        $this->hours = [];
        foreach (DB::table('bk_staff_hours')->whereIn('staff_id', $ids)->get() as $row) {
            $this->hours[(int) $row->staff_id][(int) $row->weekday] = $row;
        }

        $this->timeOff = [];
        foreach (DB::table('bk_staff_time_off')->whereIn('staff_id', $ids)
            ->where('start_date', '<=', $to)->where('end_date', '>=', $from)->get() as $row) {
            $row->start_date = substr((string) $row->start_date, 0, 10);
            $row->end_date = substr((string) $row->end_date, 0, 10);
            $this->timeOff[(int) $row->staff_id][] = $row;
        }

        $employeeIds = BookingStaff::withTrashed()->whereIn('id', $ids)->whereNotNull('employee_id')->pluck('employee_id')->all();
        $this->leave = [];
        if ($employeeIds) {
            foreach (DB::table('leaves')->whereIn('employee_id', $employeeIds)->whereNull('deleted_at')
                ->where('status', 'approved')->where('start_date', '<=', $to)->where('end_date', '>=', $from)
                ->get(['employee_id', 'start_date', 'end_date']) as $row) {
                $this->leave[(int) $row->employee_id][] = [substr((string) $row->start_date, 0, 10), substr((string) $row->end_date, 0, 10)];
            }
        }

        $this->holidays = DB::table('holidays')->whereNull('deleted_at')
            ->where('start_date', '<=', $to)->where('end_date', '>=', $from)
            ->get(['start_date', 'end_date'])
            ->map(fn ($h) => [substr((string) $h->start_date, 0, 10), substr((string) $h->end_date, 0, 10)])->all();

        $this->busy = [];
        $bookings = Booking::query()
            ->whereIn('staff_id', $ids)
            ->whereBetween('booking_date', [$from, $to])
            ->whereIn('status', self::BLOCKING)
            ->when($ignore, fn ($q) => $q->where('id', '!=', $ignore))
            // A website hold that ran out frees the time; NULL = held until answered.
            ->where(fn ($q) => $q->where('status', '!=', 'pending')->orWhereNull('hold_until')->orWhere('hold_until', '>', now()))
            // A request with no time yet blocks nothing.
            ->where(fn ($q) => $q->whereNull('booking_mode')->orWhere('booking_mode', '!=', 'request')->orWhere('status', '!=', 'pending'))
            ->get(['id', 'Ref', 'staff_id', 'product_id', 'booking_date', 'booking_time', 'booking_end_time', 'duration_minutes', 'seats']);
        foreach ($bookings as $b) {
            $detail = $b->product_id ? $this->detail((int) $b->product_id) : null;
            $start = self::minutes(substr((string) $b->booking_time, 0, 5)) ?? 0;
            $end = $b->booking_end_time ? self::minutes(substr((string) $b->booking_end_time, 0, 5)) : null;
            if ($end === null || $end <= $start) {
                $end = $start + max(5, (int) ($b->duration_minutes ?: ($detail->duration_minutes ?? 60)));
            }
            $date = Carbon::parse($b->booking_date)->toDateString();
            $this->busy[$date][(int) $b->staff_id][] = [
                'id' => (int) $b->id,
                'ref' => $b->Ref,
                'product_id' => (int) $b->product_id,
                'seats' => max(1, (int) ($b->seats ?? 1)),
                'core_start' => $start,
                'core_end' => min($end, 1440),
                'start' => $start - (int) ($detail->buffer_before ?? 0),
                'end' => min($end, 1440) + (int) ($detail->buffer_after ?? 0),
            ];
        }

        $this->loaded = ['from' => $from, 'to' => $to, 'ids' => $ids, 'ignore' => $ignore];
    }

    private function describeWindows(array $windows): ?string
    {
        if (! $windows) {
            return null;
        }

        return implode(', ', array_map(fn ($w) => self::hhmm($w[0]).' – '.self::hhmm($w[1]), $windows));
    }

    private function weekday(string $date): int
    {
        return Carbon::createFromFormat('Y-m-d', $date)->isoWeekday();
    }

    private function at(string $date, int $minutes): Carbon
    {
        return Carbon::createFromFormat('Y-m-d H:i', $date.' '.self::hhmm($minutes), config('app.timezone'))->second(0);
    }

    private function validDate(string $date): bool
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return false;
        }
        [$y, $m, $d] = array_map('intval', explode('-', $date));

        return checkdate($m, $d, $y);
    }

    /** "HH:MM" → minutes from midnight, or null. */
    public static function minutes(?string $hhmm): ?int
    {
        if (! is_string($hhmm) || ! preg_match('/^([01]\d|2[0-3]):([0-5]\d)/', $hhmm, $m)) {
            return null;
        }

        return (int) $m[1] * 60 + (int) $m[2];
    }

    public static function hhmm(int $minutes): string
    {
        $minutes = max(0, min(1440, $minutes)); // 1440 → "24:00" (end of day)

        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    /** Intervals minus [a, b). */
    public static function subtract(array $intervals, int $a, int $b): array
    {
        $out = [];
        foreach ($intervals as [$s, $e]) {
            if ($b <= $s || $a >= $e) {
                $out[] = [$s, $e];
                continue;
            }
            if ($a > $s) {
                $out[] = [$s, $a];
            }
            if ($b < $e) {
                $out[] = [$b, $e];
            }
        }

        return $out;
    }
}
