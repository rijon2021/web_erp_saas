<?php

namespace App\Services\Booking;

use App\Events\BookingChanged;
use App\Http\Controllers\Booking\BookingPosBridge;
use App\Models\Booking;
use App\Models\BookingServiceDetail;
use App\Models\BookingSetting;
use App\Models\BookingStaff;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Moves a booking through its statuses:
 *
 *   pending ──confirm──▶ confirmed ──arrive──▶ arrived ──complete──▶ completed
 *      │                    │  │                  │
 *      └─decline─▶ declined │  └──no-show──▶ no_show (only once the start time has passed)
 *                           └──cancel───▶ cancelled (also from pending / arrived)
 *   declined | cancelled | no_show ──restore──▶ confirmed (if it had been) or pending
 *
 * Every step that sets a time (confirm, reschedule, restore) runs
 * AvailabilityService::check() for the staff member, under a lock on that
 * staff row so two people cannot confirm the same free time; `force` skips
 * the check (staff override). "Charge" creates a POS sale (BookingPosBridge)
 * for the service × seats and completes the booking. Each step fires
 * App\Events\BookingChanged.
 */
class BookingWorkflow
{
    public const OPEN = ['pending', 'confirmed', 'arrived'];

    // ------------------------------------------------------------------
    // Steps
    // ------------------------------------------------------------------

    /** @param  array{staff_id?: ?int, date?: string, time?: string, end_time?: ?string}  $when */
    public function confirm(Booking $booking, User $by, array $when = [], bool $force = false): Booking
    {
        return $this->step($booking, ['pending'], function (Booking $b) use ($by, $when, $force) {
            $this->schedule($b, $when, $force, $b->booking_mode === 'request' && empty($when['time']) && ! $b->confirmed_at);
            $b->status = 'confirmed';
            $b->confirmed_at = now();
            $b->confirmed_by = $by->id;
            $b->hold_until = null;
            $this->clearProposal($b);

            return 'confirmed';
        }, $by);
    }

    public function decline(Booking $booking, User $by, ?string $reason): Booking
    {
        return $this->step($booking, ['pending'], function (Booking $b) use ($reason) {
            $b->status = 'declined';
            $this->closeWith($b, $reason, 'staff');

            return 'declined';
        }, $by);
    }

    public function cancel(Booking $booking, ?User $by, ?string $reason, string $who = 'staff'): Booking
    {
        return $this->step($booking, self::OPEN, function (Booking $b) use ($reason, $who) {
            $b->status = 'cancelled';
            $this->closeWith($b, $reason, $who);

            return 'cancelled';
        }, $by);
    }

    public function reschedule(Booking $booking, User $by, array $when, bool $force = false): Booking
    {
        return $this->step($booking, ['pending', 'confirmed'], function (Booking $b) use ($when, $force) {
            $this->schedule($b, $when, $force, false);
            $this->clearProposal($b);
            if (Booking::notificationsReady()) {
                $b->reminder_sent_at = null; // a new time gets a new reminder
            }

            return 'rescheduled';
        }, $by);
    }

    /**
     * Suggest another time for a pending booking; the customer accepts it on
     * their status page. The time is checked now (force skips that) and again
     * when the customer accepts — it is not held in between.
     */
    public function propose(Booking $booking, User $by, array $when, ?string $note = null, bool $force = false): Booking
    {
        if (! Booking::notificationsReady()) {
            throw new BookingWorkflowException(__('Run the latest database update first.'), 'not_ready', [], 503);
        }

        return $this->step($booking, ['pending'], function (Booking $b) use ($when, $note, $force) {
            if (empty($when['date']) || empty($when['time'])) {
                throw new BookingWorkflowException(__('Pick a valid date and start time.'), 'bad_time');
            }
            // Work it out on a copy: the booking keeps its own time until the customer accepts.
            $probe = $b->replicate();
            $probe->id = $b->id;
            $this->schedule($probe, $when, $force, false);
            $b->proposed_date = Carbon::parse($probe->booking_date)->toDateString();
            $b->proposed_time = $this->hhmm($probe->booking_time);
            $b->proposed_end_time = $this->hhmm($probe->booking_end_time);
            $b->proposed_staff_id = $probe->staff_id;
            $b->proposed_at = now();
            $b->proposal_note = trim((string) $note) !== '' ? mb_substr(trim((string) $note), 0, 500) : null;

            return 'proposed';
        }, $by);
    }

    /** The customer takes the suggested time: it becomes the booking's time and the booking is confirmed. */
    public function acceptProposal(Booking $booking): Booking
    {
        return $this->step($booking, ['pending'], function (Booking $b) {
            if (! $b->hasProposal()) {
                throw new BookingWorkflowException(__('There is no suggested time to accept.'), 'no_proposal');
            }
            try {
                $this->schedule($b, [
                    'staff_id' => $b->proposed_staff_id, 'date' => Carbon::parse($b->proposed_date)->toDateString(),
                    'time' => $b->proposed_time, 'end_time' => $b->proposed_end_time,
                ], false, false);
            } catch (BookingWorkflowException $e) {
                throw new BookingWorkflowException(__('The suggested time is no longer free.'), 'proposal_taken', [], 409);
            }
            $this->clearProposal($b);
            $b->status = 'confirmed';
            $b->confirmed_at = now();
            $b->hold_until = null;

            return 'proposal_accepted';
        }, null);
    }

    /** The customer asks for another time: the suggestion is dropped, the booking stays pending. */
    public function declineProposal(Booking $booking): Booking
    {
        return $this->step($booking, ['pending'], function (Booking $b) {
            if (! $b->hasProposal()) {
                throw new BookingWorkflowException(__('There is no suggested time to decline.'), 'no_proposal');
            }
            $this->clearProposal($b);

            return 'proposal_declined';
        }, null);
    }

    private function clearProposal(Booking $b): void
    {
        if (! Booking::notificationsReady()) {
            return;
        }
        $b->proposed_date = null;
        $b->proposed_time = null;
        $b->proposed_end_time = null;
        $b->proposed_staff_id = null;
        $b->proposed_at = null;
        $b->proposal_note = null;
    }

    public function arrive(Booking $booking, User $by): Booking
    {
        return $this->step($booking, ['confirmed'], function (Booking $b) {
            $b->status = 'arrived';
            $b->arrived_at = now();

            return 'arrived';
        }, $by);
    }

    public function noShow(Booking $booking, User $by): Booking
    {
        return $this->step($booking, ['confirmed'], function (Booking $b) {
            if ($this->startsAt($b)->isFuture()) {
                throw new BookingWorkflowException(__('A booking can be marked as a no-show only once its start time has passed.'), 'not_started');
            }
            $b->status = 'no_show';

            return 'no_show';
        }, $by);
    }

    public function complete(Booking $booking, User $by): Booking
    {
        return $this->step($booking, ['confirmed', 'arrived'], function (Booking $b) {
            $b->status = 'completed';
            $b->completed_at = $b->completed_at ?? now();

            return 'completed';
        }, $by);
    }

    public function restore(Booking $booking, User $by, bool $force = false): Booking
    {
        return $this->step($booking, ['cancelled', 'declined', 'no_show'], function (Booking $b) use ($force) {
            $wasConfirmed = (bool) $b->confirmed_at;
            if ($b->staff_id && ! $force) {
                $this->assertFree($b, (int) $b->staff_id, $this->dateOf($b), $this->hhmm($b->booking_time), $this->hhmm($b->booking_end_time));
            }
            $b->status = $wasConfirmed ? 'confirmed' : 'pending';
            $b->cancelled_at = null;
            $b->cancelled_by = null;
            $b->cancel_reason = null;

            return 'restored';
        }, $by);
    }

    // ------------------------------------------------------------------
    // Charge
    // ------------------------------------------------------------------

    /**
     * What "Complete & charge" would bill: the service × seats at the booking
     * price (else the product price), with the product's tax.
     *
     * @return array{lines: array, subtotal: float, discount: float, grand_total: float, product: ?array, sale_id: ?int}
     */
    public function quote(Booking $b, float $discount = 0): array
    {
        $product = $b->product_id ? Product::find($b->product_id) : null;
        $lines = [];
        $subtotal = 0.0;
        if ($product) {
            $qty = max(1, (int) ($b->seats ?: 1));
            $price = $b->price !== null && (float) $b->price > 0 ? (float) $b->price : (float) $product->price;
            $calc = $this->line($price, $qty, (float) ($product->TaxNet ?? 0), (string) ($product->tax_method ?? '1'));
            $lines[] = [
                'product_id' => $product->id, 'name' => $product->name, 'qty' => $qty, 'unit_price' => round($price, 2),
                'tax_percent' => (float) ($product->TaxNet ?? 0), 'tax_method' => (string) ($product->tax_method ?? '1'),
                'subtotal' => $calc['subtotal'],
            ];
            $subtotal = $calc['subtotal'];
        }
        $discount = round(max(0, min($discount, $subtotal)), 2);
        $grand = round($subtotal - $discount, 2);
        $deposit = $this->depositCredit($b, $grand);

        return [
            'lines' => $lines,
            'subtotal' => round($subtotal, 2),
            'discount' => $discount,
            'grand_total' => $grand,
            // Phase 8: a deposit paid online counts as paid on the sale.
            'deposit_paid' => $deposit,
            'due_now' => round(max(0, $grand - $deposit), 2),
            'product' => $product ? ['id' => $product->id, 'name' => $product->name, 'deleted' => (bool) $product->deleted_at] : null,
            'sale_id' => $b->sale_id,
        ];
    }

    /**
     * Create the POS sale and complete the booking. A booking already
     * charged returns its sale; the same $saleUuid retried returns the same
     * sale (POS idempotency).
     *
     * @param  array<int, array{payment_method_id: int, amount: float, account_id?: ?int}>  $payments  amount 0 = pay later (sale stays unpaid)
     * @return array{sale_id: int, booking: Booking, grand_total: float, change: float}
     */
    public function charge(Booking $booking, User $by, array $payments, float $discount, ?string $saleUuid): array
    {
        // One transaction with the booking row locked: a double click with two
        // different sale UUIDs waits here and then finds the first sale.
        $result = DB::transaction(fn () => $this->chargeLocked($booking, $by, $payments, $discount, $saleUuid));
        if ($result['created']) {
            event(new BookingChanged($result['booking'], 'charged', $result['from'], $by->id));
        }
        unset($result['created'], $result['from']);

        return $result;
    }

    private function chargeLocked(Booking $booking, User $by, array $payments, float $discount, ?string $saleUuid): array
    {
        $booking = Booking::whereKey($booking->id)->lockForUpdate()->firstOrFail();
        if ($booking->sale_id && Sale::whereNull('deleted_at')->whereKey($booking->sale_id)->exists()) {
            return ['sale_id' => (int) $booking->sale_id, 'booking' => $booking, 'grand_total' => 0.0, 'change' => 0.0, 'created' => false, 'from' => $booking->status];
        }
        $this->expect($booking, ['confirmed', 'arrived', 'completed']);

        $q = $this->quote($booking, $discount);
        if (! $q['lines']) {
            throw new BookingWorkflowException(__('This booking has no service to charge.'), 'no_service');
        }
        if (collect($payments)->contains(fn ($p) => (int) ($p['payment_method_id'] ?? 0) === 1)) {
            throw new BookingWorkflowException(__('Online card payments (Stripe) are taken at the POS. Use another payment method here.'), 'stripe');
        }
        $client = (int) ($booking->customer_id ?: Setting::whereNull('deleted_at')->value('client_id'));
        $warehouseId = $this->warehouseFor($booking, $by);

        $details = [];
        foreach ($q['lines'] as $l) {
            $details[] = [
                'product_id' => $l['product_id'], 'product_variant_id' => null, 'product_type' => 'is_service', 'sale_unit_id' => null,
                'name' => $l['name'], 'quantity' => $l['qty'], 'Unit_price' => $l['unit_price'], 'tax_percent' => $l['tax_percent'],
                'tax_method' => $l['tax_method'], 'discount' => 0, 'discount_Method' => '2', 'subtotal' => $l['subtotal'], 'price_type' => 'retail',
            ];
        }
        // The deposit paid online is the first payment on the sale.
        if ($q['deposit_paid'] > 0) {
            // Same account as the rest of the bill (needed when payments must name an account).
            $account = collect($payments)->pluck('account_id')->filter()->first();
            array_unshift($payments, array_filter([
                'payment_method_id' => BookingDepositService::paymentMethodId(), 'amount' => $q['deposit_paid'],
                'account_id' => $account ? (int) $account : null,
            ], fn ($v) => $v !== null));
            $payments = array_values(array_filter($payments, fn ($p, $i) => $i === 0 || (float) ($p['amount'] ?? 0) > 0, ARRAY_FILTER_USE_BOTH));
        }
        $paid = round(collect($payments)->sum(fn ($p) => (float) ($p['amount'] ?? 0)), 2);
        $payload = [
            'client_id' => $client,
            'warehouse_id' => $warehouseId,
            'tax_rate' => 0,
            'TaxNet' => 0,
            'discount' => $q['discount'],
            'discount_Method' => '2',
            'shipping' => 0,
            'GrandTotal' => $q['grand_total'],
            'notes' => trim('Booking '.$booking->Ref.' · '.$this->dateOf($booking).' '.$this->hhmm($booking->booking_time)),
            'details' => $details,
            'payments' => array_values(array_map(fn ($p) => array_filter([
                'payment_method_id' => (int) $p['payment_method_id'],
                'amount' => round((float) $p['amount'], 2),
                'account_id' => ! empty($p['account_id']) ? (int) $p['account_id'] : null,
            ], fn ($v) => $v !== null), $payments)),
            'sale_uuid' => $saleUuid ?: null,
            'kitchen_action' => 'none',
        ];

        $request = Request::create('/api/pos/create_pos', 'POST', $payload);
        $request->setUserResolver(fn () => $by);
        // CreatePOS reads both Auth::user() and $request->user('api').
        Auth::setUser($by);
        Auth::guard('api')->setUser($by);
        $response = app(BookingPosBridge::class)->createSale($request);
        $status = $response->getStatusCode();
        $body = $response->getData(true);
        if ($status >= 300 || empty($body['success']) || empty($body['id'])) {
            throw new BookingWorkflowException($body['message'] ?? __('The sale could not be saved.'), 'sale_failed', [], $status >= 400 && $status < 500 ? 422 : 500);
        }
        $saleId = (int) $body['id'];

        $from = $booking->status;
        DB::table('bookings')->where('id', $booking->id)->update([
            'sale_id' => $saleId,
            'status' => 'completed',
            'completed_at' => $booking->completed_at ?? now(),
            'updated_at' => now(),
        ]);
        $booking = $booking->fresh();

        return [
            'sale_id' => $saleId, 'booking' => $booking, 'grand_total' => $q['grand_total'],
            'change' => round(max(0, $paid - $q['grand_total']), 2), 'created' => true, 'from' => $from,
        ];
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /** The online deposit that counts towards the sale (never more than the sale). */
    private function depositCredit(Booking $b, float $grand): float
    {
        if (! Booking::depositsReady() || $b->deposit_status !== 'paid') {
            return 0.0;
        }

        return round(max(0, min((float) $b->deposit_paid, $grand)), 2);
    }

    /** Lock the row, check the status, apply the change, save, fire the event. */
    private function step(Booking $booking, array $allowed, callable $apply, ?User $by): Booking
    {
        [$booking, $event, $from] = BookingTx::run(function () use ($booking, $allowed, $apply) {
            $b = Booking::whereKey($booking->id)->lockForUpdate()->firstOrFail();
            $this->expect($b, $allowed);
            $from = $b->status;
            $event = $apply($b);
            $b->save();

            return [$b->fresh(), $event, $from];
        });
        event(new BookingChanged($booking, $event, $from, $by?->id));

        return $booking;
    }

    private function expect(Booking $b, array $allowed): void
    {
        if (! in_array($b->status, $allowed, true)) {
            throw new BookingWorkflowException(
                __('This booking is :status, so that cannot be done now.', ['status' => str_replace('_', ' ', (string) $b->status)]),
                'wrong_status',
                ['status' => $b->status]
            );
        }
    }

    private function closeWith(Booking $b, ?string $reason, string $who): void
    {
        $b->cancelled_at = now();
        $b->cancelled_by = $who;
        $b->cancel_reason = trim((string) $reason) !== '' ? mb_substr(trim((string) $reason), 0, 500) : null;
        $b->hold_until = null;
        $this->clearProposal($b);
    }

    /**
     * Apply staff / date / time from $when (falling back to the booking's
     * own), picking a free staff member when none is set, and check it.
     */
    private function schedule(Booking $b, array $when, bool $force, bool $needTime): void
    {
        if ($needTime) {
            throw new BookingWorkflowException(__('Give this request a date and a start time first.'), 'needs_time');
        }
        $date = (string) ($when['date'] ?? $this->dateOf($b));
        $time = $this->hhmm($when['time'] ?? $b->booking_time);
        if (! $time || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new BookingWorkflowException(__('Pick a valid date and start time.'), 'bad_time');
        }
        $detail = $b->product_id ? BookingServiceDetail::where('product_id', $b->product_id)->first() : null;
        $start = AvailabilityService::minutes($time);
        $end = array_key_exists('end_time', $when) ? $this->hhmm($when['end_time']) : null;
        if ($end === null) {
            $len = (int) ($b->duration_minutes ?: ($detail->duration_minutes ?? 0));
            if (! $len && $b->booking_end_time) {
                $len = (AvailabilityService::minutes($this->hhmm($b->booking_end_time)) ?? $start) - $start;
            }
            $len = $len > 0 ? $len : 60;
            // Ending at (or past) midnight: no end time, the length is kept instead.
            $end = $start + $len < 1440 ? AvailabilityService::hhmm($start + $len) : null;
        }
        if ($end !== null && AvailabilityService::minutes($end) <= $start) {
            throw new BookingWorkflowException(__('The end time must be after the start time.'), 'bad_time');
        }

        $staffId = array_key_exists('staff_id', $when) ? ($when['staff_id'] ? (int) $when['staff_id'] : null) : ($b->staff_id ? (int) $b->staff_id : null);
        if ($staffId) {
            if (! $force) {
                $this->assertFree($b, $staffId, $date, $time, $end);
            } elseif (! BookingStaff::query()->active()->whereKey($staffId)->exists()) {
                // "Save anyway" skips the time rules, never the staff member being real.
                throw new BookingWorkflowException(self::reasonMessage('staff_unknown'), 'staff_unknown');
            }
        } elseif (! $force && BookingStaff::active()->exists()) {
            $staffId = $this->pickFree($b, $date, $time, $end);
        }

        $b->staff_id = $staffId;
        $b->booking_date = $date;
        $b->booking_time = $time;
        $b->booking_end_time = $end;
        if ($end !== null) {
            $b->duration_minutes = AvailabilityService::minutes($end) - $start;
        }
        if (! $b->warehouse_id) {
            $b->warehouse_id = ($staffId ? BookingStaff::withTrashed()->whereKey($staffId)->value('warehouse_id') : null)
                ?: (BookingSetting::current()->default_warehouse_id ?: null);
        }
    }

    /** Throws when the staff member is not free (locks their row first so two confirms queue up). */
    private function assertFree(Booking $b, int $staffId, string $date, ?string $time, ?string $end): void
    {
        BookingStaff::withTrashed()->whereKey($staffId)->lockForUpdate()->first();
        $check = (new AvailabilityService())->check(
            $b->product_id ? (int) $b->product_id : null, $staffId, $date, (string) $time, $end,
            max(1, (int) ($b->seats ?: 1)), (int) $b->id
        );
        if (! $check['ok']) {
            throw new BookingWorkflowException(self::reasonMessage($check['reason']), $check['reason'], [
                'clashes' => $check['clashes'], 'hours' => $check['hours'], 'can_force' => true,
            ]);
        }
    }

    /** The free staff member with the fewest bookings that day, or an exception. */
    private function pickFree(Booking $b, string $date, ?string $time, ?string $end): int
    {
        $svc = new AvailabilityService();
        $candidates = $b->product_id ? $svc->serviceStaff((int) $b->product_id) : BookingStaff::active()->orderBy('sort_order')->get();
        // Lock every candidate first (id order, so two confirms never deadlock), then check:
        // a check made before the lock could miss a booking committed meanwhile.
        BookingStaff::whereIn('id', $candidates->pluck('id')->all() ?: [0])->orderBy('id')->lockForUpdate()->get();
        $free = [];
        foreach ($candidates as $member) {
            $check = (new AvailabilityService())->check($b->product_id ? (int) $b->product_id : null, $member->id, $date, (string) $time, $end, max(1, (int) ($b->seats ?: 1)), (int) $b->id);
            if ($check['ok']) {
                $free[] = $member->id;
            }
        }
        if (! $free) {
            throw new BookingWorkflowException(__('Nobody who does this service is free at that time. Pick another time or a staff member.'), 'no_staff_free', ['can_force' => true]);
        }

        return (int) $svc->pickStaff($free, $date);
    }

    public static function reasonMessage(?string $reason): string
    {
        return match ($reason) {
            'staff_unknown' => __('That staff member is not bookable.'),
            'staff_not_for_service' => __('That staff member does not do this service.'),
            'day_off' => __('That staff member does not work on that day.'),
            'holiday' => __('That day is a company holiday.'),
            'leave' => __('That staff member is on leave that day.'),
            'time_off' => __('That staff member has the day off.'),
            'outside_hours' => __('That time is outside the working hours.'),
            'clash' => __('That time clashes with another booking.'),
            'class_full' => __('The class is full at that time.'),
            'daily_limit' => __('That staff member has reached their bookings for the day.'),
            default => __('That time is not available.'),
        };
    }

    private function warehouseFor(Booking $b, User $by): int
    {
        $id = (int) ($b->warehouse_id
            ?: ($b->staff_id ? BookingStaff::withTrashed()->whereKey($b->staff_id)->value('warehouse_id') : 0)
            ?: (BookingSetting::tablesReady() ? BookingSetting::current()->default_warehouse_id : 0)
            ?: Setting::whereNull('deleted_at')->value('warehouse_id'));
        if (! $id) {
            $id = (int) DB::table('user_warehouse')->where('user_id', $by->id)->value('warehouse_id');
        }
        if (! $id) {
            throw new BookingWorkflowException(__('Choose the branch for website bookings in Booking Settings, or a default warehouse in System Settings.'), 'no_warehouse');
        }

        return $id;
    }

    /** The POS line formula (lib/lineCalc.js recomputeLine) with no line discount. */
    private function line(float $price, int $qty, float $taxPercent, string $taxMethod): array
    {
        if ($taxMethod === '1') {
            $net = $price;
            $tax = round($taxPercent * $price / 100, 6);
        } else {
            $tax = round($price * ($taxPercent / 100), 6);
            $net = $price - $tax;
        }

        return ['subtotal' => round($qty * $net + $tax * $qty, 2)];
    }

    public function startsAt(Booking $b): Carbon
    {
        return Carbon::createFromFormat('Y-m-d H:i', $this->dateOf($b).' '.($this->hhmm($b->booking_time) ?? '00:00'), config('app.timezone'));
    }

    private function dateOf(Booking $b): string
    {
        return Carbon::parse($b->booking_date)->toDateString();
    }

    private function hhmm($t): ?string
    {
        return is_string($t) && preg_match('/^([01]\d|2[0-3]):[0-5]\d/', $t) ? substr($t, 0, 5) : null;
    }
}
