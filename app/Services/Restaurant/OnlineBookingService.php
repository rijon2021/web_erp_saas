<?php

namespace App\Services\Restaurant;

use App\Models\RestaurantArea;
use App\Models\RestaurantReservation;
use App\Models\RestaurantSetting;
use App\Models\RestaurantTable;
use App\Support\RestaurantModule;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Booking a table from the online store (phase 8; Restaurant Settings ›
 * Online booking). Guests see the start times between the first and last
 * booking time, at the slot step, from the notice period up to N days ahead;
 * a time is offered only while some table that fits the party is free for
 * the whole booking. The booking takes the best such table (fewest spare
 * seats) and arrives as "booked" from source "online" for staff to confirm.
 */
class OnlineBookingService
{
    public function __construct(private ReservationService $reservations)
    {
    }

    public function enabled(): bool
    {
        return RestaurantModule::enabled() && RestaurantReservation::tablesReady()
            && (bool) (RestaurantSetting::current()->online_booking_enabled ?? false);
    }

    /** Booking rules the store page shows. */
    public function rules(): array
    {
        $s = RestaurantSetting::current();
        $first = now()->addMinutes((int) $s->booking_notice_minutes);

        return [
            'max_party' => max(1, min((int) $s->online_max_party, (int) $s->max_party_size)),
            'first_date' => $first->format('Y-m-d'),
            'last_date' => now()->addDays(max(1, (int) $s->booking_days_ahead))->format('Y-m-d'),
            'from' => (string) $s->booking_from,
            'to' => (string) $s->booking_to,
        ];
    }

    /** @return array<int, array{time: string, available: bool}> */
    public function slots(string $date, int $party): array
    {
        $this->assertBookable($date, $party);
        $s = RestaurantSetting::current();
        $step = in_array((int) $s->booking_slot_minutes, [15, 30, 60], true) ? (int) $s->booking_slot_minutes : 30;
        $earliest = now()->addMinutes((int) $s->booking_notice_minutes);
        $minutes = (int) $s->reservation_minutes ?: 90;
        $out = [];
        $t = Carbon::createFromFormat('Y-m-d H:i', $date.' '.$s->booking_from, config('app.timezone'))->second(0);
        $last = Carbon::createFromFormat('Y-m-d H:i', $date.' '.$s->booking_to, config('app.timezone'))->second(0);
        // Two queries for the whole day (tables that fit + their bookings), then
        // each start time is checked in memory. book() re-checks with locks.
        $fit = $this->tables()->filter(fn ($tb) => (int) $tb->seats >= $party)->pluck('id')->all();
        $bookings = $fit ? RestaurantReservation::whereIn('table_id', $fit)->whereIn('status', RestaurantReservation::ACTIVE)
            ->where('starts_at', '<', $last->copy()->addMinutes($minutes))->where('ends_at', '>', $t)
            ->get(['table_id', 'starts_at', 'ends_at'])->groupBy('table_id') : collect();
        for ($i = 0; $t->lte($last) && $i < 200; $i++, $t->addMinutes($step)) {
            if ($t->lt($earliest)) {
                continue;
            }
            $end = $t->copy()->addMinutes($minutes);
            $free = false;
            foreach ($fit as $id) {
                if (! ($bookings[$id] ?? collect())->contains(fn ($b) => $b->starts_at->lt($end) && $b->ends_at->gt($t))) {
                    $free = true;
                    break;
                }
            }
            $out[] = ['time' => $t->format('H:i'), 'available' => $free];
        }

        return $out;
    }

    /** @param  array  $data  customer_name, customer_phone, customer_email?, party_size, date, time, note? */
    public function book(array $data): RestaurantReservation
    {
        $party = (int) ($data['party_size'] ?? 0);
        $date = (string) ($data['date'] ?? '');
        $time = (string) ($data['time'] ?? '');
        $this->assertBookable($date, $party);
        $s = RestaurantSetting::current();
        if (! in_array($time, array_column($this->slots($date, $party), 'time'), true)) {
            throw new ReservationException(__('Choose one of the times offered.'));
        }
        if (! trim((string) ($data['customer_phone'] ?? ''))) {
            throw new ReservationException(__('Enter a phone number so we can reach you.'));
        }
        // Checked here: below, any refusal from a table just moves on to the next one.
        if (! trim((string) ($data['customer_name'] ?? ''))) {
            throw new ReservationException(__('Enter the guest\'s name.'));
        }
        // One phone number holds at most 3 upcoming online bookings (a script
        // cannot fill the tables for weeks with made-up names).
        $digits = preg_replace('/\D+/', '', (string) $data['customer_phone']);
        if (strlen($digits) < 6) {
            throw new ReservationException(__('Enter a phone number so we can reach you.'));
        }
        $held = RestaurantReservation::where('source', 'online')->whereIn('status', RestaurantReservation::UPCOMING)->where('starts_at', '>=', now())
            ->get(['customer_phone'])->filter(fn ($r) => preg_replace('/\D+/', '', (string) $r->customer_phone) === $digits)->count();
        if ($held >= 3) {
            throw new ReservationException(__('This phone number already has several bookings with us. Please call us to book more.'));
        }

        return DB::transaction(function () use ($data, $party, $date, $time, $s) {
            // Lock the tables while choosing, so two guests cannot take the last one.
            $tables = $this->tables(true);
            $start = Carbon::createFromFormat('Y-m-d H:i', $date.' '.$time, config('app.timezone'))->second(0);
            // Best tables first; one taken a moment ago (the save re-checks with
            // a locking read) just moves on to the next.
            foreach ($this->candidates($tables, $start, (int) $s->reservation_minutes ?: 90, $party) as $tableId) {
                try {
                    return $this->reservations->save(null, [
                        'customer_name' => $data['customer_name'] ?? null,
                        'customer_phone' => $data['customer_phone'] ?? null,
                        'customer_email' => $data['customer_email'] ?? null,
                        'party_size' => $party,
                        'date' => $date,
                        'time' => $time,
                        'table_id' => $tableId,
                        'source' => 'online',
                        'note' => $data['note'] ?? null,
                    ], null);
                } catch (ReservationException $e) {
                    continue; // never shown: it may name the guest holding that table
                }
            }

            throw new ReservationException(__('Sorry, that time has just been taken. Please choose another.'));
        });
    }

    private function assertBookable(string $date, int $party): void
    {
        if (! $this->enabled()) {
            throw new ReservationException(__('Online booking is not available right now.'), 404);
        }
        $r = $this->rules();
        if ($party < 1 || $party > $r['max_party']) {
            throw new ReservationException(__('For more than :n people, please call us.', ['n' => $r['max_party']]));
        }
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date < $r['first_date'] || $date > $r['last_date']) {
            throw new ReservationException(__('Choose a date between :from and :to.', ['from' => $r['first_date'], 'to' => $r['last_date']]));
        }
    }

    /**
     * Active, un-joined tables of active areas — of the restaurant's branch
     * (Restaurant Settings › default warehouse) when one is set, plus areas
     * shared by every branch.
     */
    private function tables(bool $lock = false)
    {
        $wh = (int) RestaurantSetting::current()->default_warehouse_id;
        $areas = RestaurantArea::where('is_active', true)
            ->when($wh, fn ($q) => $q->where(fn ($w) => $w->whereNull('warehouse_id')->orWhere('warehouse_id', $wh)))
            ->pluck('id');
        $q = RestaurantTable::whereIn('area_id', $areas)->where('is_active', true)->whereNull('merged_into_id')->orderBy('id');

        return $lock ? $q->lockForUpdate()->get() : $q->get();
    }

    private function bestTable($tables, Carbon $start, int $minutes, int $party): ?int
    {
        return $this->candidates($tables, $start, $minutes, $party)[0] ?? null;
    }

    /** @return int[] free tables that fit, best first */
    private function candidates($tables, Carbon $start, int $minutes, int $party): array
    {
        $ids = $tables->pluck('id');
        if ($ids->isEmpty()) {
            return [];
        }
        $avail = $this->reservations->availability(RestaurantTable::query()->whereIn('rst_tables.id', $ids), $start, $start->copy()->addMinutes($minutes), $party);

        return array_values(array_map(fn ($a) => $a['id'], array_filter($avail, fn ($a) => $a['free'] && $a['fits'])));
    }
}
