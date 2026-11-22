<?php

namespace App\Services\Booking;

use App\Events\BookingChanged;
use App\Models\Booking;
use App\Models\BookingPayment;
use App\Models\BookingServiceDetail;
use App\Models\BookingSetting;
use App\Models\BookingStaff;
use App\Models\PaymentSetting;
use App\Models\StoreSetting;
use App\Models\User;
use App\Services\StoreCurrencyService;
use App\Services\TenantFlutterwaveService;
use App\Services\TenantMoyasarService;
use App\Services\TenantMyfatoorahService;
use App\Services\TenantPaypalService;
use App\Services\TenantPaystackService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Deposits paid online for website bookings.
 *
 * Booking Settings › Deposit sets the default (none | fixed amount |
 * percentage of the price | the whole price); a service can have its own.
 * A booking for a free time that needs a deposit waits as pending and holds
 * its time for deposit_minutes; once paid it is held until staff answer it
 * (or confirmed straight away when auto-confirm is on). Requests are asked
 * for the deposit after staff confirm them, when deposit_on_requests is on.
 *
 * The customer pays on the gateway's own page (Stripe Checkout, PayPal,
 * Paystack, Flutterwave, Moyasar, MyFatoorah — whichever the store has
 * set up under Online Store › Payment Gateway, in the store's base
 * currency). Every attempt is a bk_payments row; the return leg and the
 * expiry run re-check the payment with the gateway by the reference we
 * stored, never by what the browser sends back.
 */
class BookingDepositService
{
    /** gateway key => Online Store payment-method key (StoreSetting::PAYMENT_METHOD_COLUMNS). */
    public const GATEWAYS = [
        'stripe' => 'credit_card',
        'paypal' => 'paypal',
        'paystack' => 'paystack',
        'flutterwave' => 'flutterwave',
        'moyasar' => 'moyasar',
        'myfatoorah' => 'myfatoorah',
    ];

    public const LABELS = [
        'stripe' => 'Card (Stripe)',
        'paypal' => 'PayPal',
        'paystack' => 'Paystack',
        'flutterwave' => 'Flutterwave',
        'moyasar' => 'Moyasar',
        'myfatoorah' => 'MyFatoorah',
    ];

    private const ZERO_DECIMAL = ['BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF'];

    /** Currencies with three decimals (Stripe wants ×1000, last digit 0). */
    private const THREE_DECIMAL = ['BHD', 'JOD', 'KWD', 'OMR', 'TND'];

    /** Attempts one booking may start, and how long after booking a lapsed hold can still be renewed. */
    public const MAX_ATTEMPTS = 5;

    public const RENEW_WITHIN_HOLDS = 4;

    private ?BookingSetting $settings = null;

    private ?array $gateways = null;

    public static function ready(): bool
    {
        return Booking::depositsReady();
    }

    public function settings(): BookingSetting
    {
        return $this->settings ??= BookingSetting::current();
    }

    // ------------------------------------------------------------------
    // How much
    // ------------------------------------------------------------------

    /** ['type' => none|fixed|percent|full, 'value' => float] for a service (its own, else the default). */
    public function ruleFor(?int $productId): array
    {
        $s = $this->settings();
        $type = in_array($s->deposit_type, BookingSetting::DEPOSIT_TYPES, true) ? $s->deposit_type : 'none';
        $value = (float) $s->deposit_value;
        if ($productId && self::ready()) {
            $d = BookingServiceDetail::where('product_id', $productId)->first(['deposit_type', 'deposit_value']);
            if ($d && in_array($d->deposit_type, BookingSetting::DEPOSIT_TYPES, true)) {
                $type = $d->deposit_type;
                $value = (float) $d->deposit_value;
            }
        }

        return ['type' => $type, 'value' => max(0, $value)];
    }

    /** The deposit for a service at a price (per person) × seats; 0 = none. Never more than the price when there is one. */
    public function amountFor(?int $productId, float $price, int $seats = 1): float
    {
        if (! self::ready()) {
            return 0.0;
        }
        $rule = $this->ruleFor($productId);
        $total = max(0, $price) * max(1, $seats);
        $amount = match ($rule['type']) {
            'fixed' => $total > 0 ? min($rule['value'], $total) : $rule['value'],
            'percent' => $total * min(100, $rule['value']) / 100,
            'full' => $total,
            default => 0.0,
        };

        return $this->round($amount);
    }

    /** Deposit wanted for this booking at all (amount, and the booking kind the settings ask it for). */
    public function wantedFor(string $mode, float $amount): bool
    {
        return $amount > 0 && ($mode === 'slot' || (bool) $this->settings()->deposit_on_requests);
    }

    // ------------------------------------------------------------------
    // Where
    // ------------------------------------------------------------------

    public function currency(): string
    {
        $code = strtoupper(preg_replace('/[^A-Za-z]/', '', (string) (StoreCurrencyService::base()['code'] ?? '')) ?: 'USD');

        return substr($code, 0, 3);
    }

    /**
     * Gateways a customer can pay a deposit with right now: set up under
     * Online Store › Payment Gateway, switched on there, ticked in Booking
     * Settings (none ticked = all) and able to charge the base currency.
     *
     * @return array<string, string> key => label
     */
    public function gateways(): array
    {
        if ($this->gateways !== null) {
            return $this->gateways;
        }
        $store = StoreSetting::query()->first();
        $picked = array_values(array_filter((array) ($this->settings()->deposit_gateways ?? [])));
        $out = [];
        foreach (self::GATEWAYS as $key => $method) {
            if ($picked && ! in_array($key, $picked, true)) {
                continue;
            }
            if (! StoreSetting::paymentMethodEnabled($method, $store) || ! $this->configured($key)) {
                continue;
            }
            $out[$key] = self::LABELS[$key];
        }

        return $this->gateways = $out;
    }

    /** Set up under Online Store › Payment Gateway (and in the base currency, for the fixed-currency ones). */
    public function configured(string $key): bool
    {
        try {
            return match ($key) {
                'stripe' => (string) (PaymentSetting::current()->stripe_secret ?? '') !== '',
                'paypal' => app(TenantPaypalService::class)->isConfigured(),
                'paystack' => app(TenantPaystackService::class)->isConfigured(),
                'flutterwave' => app(TenantFlutterwaveService::class)->isConfigured(),
                'moyasar' => ($m = app(TenantMoyasarService::class))->isConfigured() && strtoupper($m->currency()) === $this->currency(),
                'myfatoorah' => ($f = app(TenantMyfatoorahService::class))->isConfigured() && strtoupper($f->currency()) === $this->currency(),
                default => false,
            };
        } catch (\Throwable $e) {
            return false;
        }
    }

    // ------------------------------------------------------------------
    // Paying
    // ------------------------------------------------------------------

    /**
     * Start an online payment for the deposit and return the gateway page
     * to send the customer to.
     */
    public function start(Booking $b, string $gateway): string
    {
        if (! self::ready() || ! isset($this->gateways()[$gateway])) {
            throw new BookingWebsiteException(__('messages.BkmDepErrGateway'), 422);
        }
        $b = $this->ensurePayable($b);
        $amount = $this->remaining($b);
        $currency = $this->currency();

        $p = BookingPayment::create([
            'booking_id' => $b->id, 'gateway' => $gateway, 'amount' => $amount, 'currency' => $currency, 'status' => 'pending',
        ]);
        $return = store_base_url('appointment/'.$b->public_token.'/deposit/'.$p->id.'/return');
        $back = store_base_url('appointment/'.$b->public_token);
        $contact = (array) (($b->request_fields ?? [])['_contact'] ?? []);
        $email = (string) (($contact['email'] ?? null) ?: optional($b->customer)->email);
        $name = (string) (($contact['name'] ?? null) ?: optional($b->customer)->name);
        $phone = (string) (($contact['phone'] ?? null) ?: optional($b->customer)->phone);
        $title = __('messages.BkmDepPaymentTitle', ['ref' => $b->Ref, 'service' => optional($b->product)->name ?: __('messages.BkmBooking')]);
        $orderRef = $b->Ref.'-D'.$p->id;
        $meta = ['booking_id' => $b->id, 'bk_payment_id' => $p->id, 'booking_ref' => $b->Ref];

        try {
            [$url, $reference] = match ($gateway) {
                'stripe' => $this->stripeStart($amount, $currency, $title, $email, $meta, $return, $back, $b),
                'paypal' => (function () use ($amount, $currency, $title, $meta, $return, $back) {
                    $r = app(TenantPaypalService::class)->createOrder($amount, $currency, $title, $meta, $return, $back, $this->storeName());

                    return [$r['url'], $r['id']];
                })(),
                'paystack' => (function () use ($amount, $currency, $email, $meta, $return) {
                    $r = app(TenantPaystackService::class)->initializeTransaction($amount, $currency, $email, $meta, $return);

                    return [$r['url'], $r['reference']];
                })(),
                'flutterwave' => (function () use ($amount, $currency, $email, $name, $title, $meta, $return) {
                    $r = app(TenantFlutterwaveService::class)->initializePayment($amount, $currency, $email, $name, $title, $meta, $return, $this->storeName());

                    return [$r['url'], $r['tx_ref']];
                })(),
                'moyasar' => (function () use ($amount, $orderRef, $return, $back) {
                    $r = app(TenantMoyasarService::class)->createInvoice($amount, $orderRef, $return, $back);

                    return [$r['url'], $r['invoice_id']];
                })(),
                'myfatoorah' => (function () use ($amount, $orderRef, $name, $email, $phone, $return, $back) {
                    $r = app(TenantMyfatoorahService::class)->createInvoice($amount, $orderRef, ['name' => $name, 'email' => $email, 'phone' => $phone], $return, $back);

                    return [$r['url'], $r['invoice_id']];
                })(),
            };
        } catch (\Throwable $e) {
            Log::warning("Booking deposit ({$gateway}) could not start for booking {$b->id}: ".$e->getMessage());
            $p->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 500)]);
            throw new BookingWebsiteException(__('messages.BkmDepErrStart'), 502);
        }
        try {
            $p->update(['reference' => mb_substr((string) $reference, 0, 191)]);
        } catch (\Illuminate\Database\QueryException $e) {
            // The gateway handed back a reference another attempt already has:
            // never let one payment settle two deposits.
            $p->update(['status' => 'failed', 'error' => 'Duplicate gateway reference']);
            throw new BookingWebsiteException(__('messages.BkmDepErrStart'), 502);
        }

        return $url;
    }

    /**
     * The customer came back from the gateway: ask the gateway about the
     * payment we stored. True when the deposit is (now) paid.
     */
    public function confirmReturn(Booking $b, BookingPayment $p): bool
    {
        if ($p->booking_id !== (int) $b->id) {
            return false;
        }
        if ($p->status === 'paid') {
            return true;
        }

        return $this->reconcile($p);
    }

    /** Re-check a pending attempt with its gateway; marks it paid when it is. */
    public function reconcile(BookingPayment $p): bool
    {
        if ($p->status !== 'pending' || ! $p->reference) {
            return false;
        }
        // PayPal only takes the money when WE capture: never capture for a
        // booking that is no longer waiting for it (cancelled, paid, waived).
        if ($p->gateway === 'paypal' && ! $this->waitingFor(Booking::find($p->booking_id))) {
            BookingPayment::whereKey($p->id)->where('status', 'pending')->update([
                'status' => 'failed', 'error' => 'Not captured: the booking no longer needs this deposit.', 'updated_at' => now(),
            ]);

            return false;
        }
        try {
            $r = $this->verify($p);
        } catch (\Throwable $e) {
            Log::warning("Booking deposit verify ({$p->gateway}) failed for payment {$p->id}: ".$e->getMessage());

            return false;
        }
        if (! $r['paid']) {
            return false;
        }
        $this->markPaid($p, $r['transaction_id']);

        return true;
    }

    /** The booking still expects deposit money (open, deposit due, something left to pay). */
    private function waitingFor(?Booking $b): bool
    {
        return $b && in_array($b->status, ['pending', 'confirmed'], true) && $b->deposit_status === 'due' && $this->remaining($b) > 0;
    }

    /**
     * Record a verified payment on the booking (once, even when the return
     * leg and the expiry run both get there). Money that arrives for a booking
     * no longer waiting for it (cancelled, already paid, waived) is still
     * recorded — the gateway took it — with a note to refund it, and only the
     * owner is told.
     */
    public function markPaid(BookingPayment $p, ?string $transactionId = null): void
    {
        $events = BookingTx::run(function () use ($p, $transactionId) {
            $won = BookingPayment::whereKey($p->id)->where('status', 'pending')->update([
                'status' => 'paid', 'transaction_id' => $transactionId ? mb_substr($transactionId, 0, 191) : null,
                'paid_at' => now(), 'error' => null, 'updated_at' => now(),
            ]);
            if (! $won) {
                return [];
            }
            $b = Booking::whereKey($p->booking_id)->lockForUpdate()->first();
            if (! $b) {
                return [];
            }
            $from = $b->status;
            $late = ! $this->waitingFor($b);
            $before = (string) $b->deposit_status;
            $b->deposit_paid = $this->round((float) $b->deposit_paid + (float) $p->amount);
            $b->deposit_paid_at = now();
            $b->deposit_gateway = $p->gateway;
            $b->deposit_reference = $transactionId ?: $p->reference;
            if ($late) {
                $b->deposit_status = 'paid';
                $b->deposit_note = match (true) {
                    ! in_array($b->status, ['pending', 'confirmed', 'arrived', 'completed'], true) => 'Paid after the booking was '.str_replace('_', ' ', (string) $b->status).' — refund it.',
                    $before === 'paid' => 'Paid twice — refund the extra '.number_format((float) $p->amount, 2).'.',
                    default => 'Paid after the deposit was '.($before ?: 'removed').' — refund it or keep it towards the bill.',
                };
                $b->save();

                return [[$b->fresh(), 'deposit_paid_late', $from]];
            }
            if ($this->remaining($b) > 0) {
                // Part of it (an older, smaller payment): the rest is still due.
                $b->save();

                return [[$b->fresh(), 'deposit_partial', $from]];
            }
            $b->deposit_status = 'paid';
            $events = [['deposit_paid', $from]];

            if ($b->status === 'pending' && $b->booking_mode === 'slot') {
                if ($this->timeStillFree($b)) {
                    // Paid: the time is kept until staff answer (or confirmed now).
                    $b->hold_until = null;
                    if ($this->settings()->auto_confirm) {
                        $b->status = 'confirmed';
                        $b->confirmed_at = now();
                        $events[] = ['confirmed', 'pending'];
                    }
                } else {
                    $b->deposit_note = 'Paid after the hold ran out and the time was taken.';
                }
            }
            $b->save();

            return array_map(fn ($e) => [$b->fresh(), $e[0], $e[1]], $events);
        });
        // (deposit_partial: nothing to tell anyone yet.)
        foreach ($events as [$booking, $event, $from]) {
            event(new BookingChanged($booking, $event, $from, null));
        }
    }

    // ------------------------------------------------------------------
    // Staff actions
    // ------------------------------------------------------------------

    /** No deposit needed for this booking after all. */
    public function waive(Booking $b, User $by): Booking
    {
        return $this->staffStep($b, $by, ['due'], function (Booking $x) {
            $x->deposit_status = 'waived';
            if ($x->status === 'pending' && $x->booking_mode === 'slot' && $x->hold_until && $x->hold_until->isFuture()) {
                $x->hold_until = null;
            }
        }, 'deposit_waived');
    }

    /** The deposit went back to the customer (done in the payment provider). */
    public function markRefunded(Booking $b, User $by, ?string $note): Booking
    {
        return $this->staffStep($b, $by, ['paid'], function (Booking $x) use ($note) {
            $x->deposit_status = 'refunded';
            $x->deposit_note = trim((string) $note) !== '' ? mb_substr(trim((string) $note), 0, 500) : null;
        }, 'deposit_refunded');
    }

    /** Ask for the deposit again (a waived deposit, or a request confirmed before deposits were on). */
    public function request(Booking $b, User $by, ?float $amount = null): Booking
    {
        return $this->staffStep($b, $by, [null, 'waived', 'refunded'], function (Booking $x) use ($amount) {
            if (! in_array($x->status, ['pending', 'confirmed'], true) || ! $x->public_token) {
                throw new BookingWorkflowException(__('Only open website bookings can be asked for a deposit.'), 'wrong_status');
            }
            $value = $amount !== null ? $this->round($amount) : ((float) $x->deposit_amount ?: $this->amountFor($x->product_id, (float) $x->price, (int) ($x->seats ?: 1)));
            if ($value <= 0) {
                throw new BookingWorkflowException(__('Give the deposit an amount.'), 'no_amount');
            }
            $x->deposit_amount = $value;
            if ($x->deposit_status === 'refunded') {
                $x->deposit_paid = 0; // that money went back: the new ask starts from nothing
            }
            $x->deposit_status = 'due';
        }, 'deposit_requested');
    }

    // ------------------------------------------------------------------
    // Expiry
    // ------------------------------------------------------------------

    /**
     * Unpaid website bookings whose payment time ran out: a payment that
     * did go through (browser closed before coming back) is picked up
     * first; the rest are cancelled when deposit_auto_cancel is on.
     *
     * @return array{reconciled: int, cancelled: int}
     */
    public function expireUnpaid(): array
    {
        $out = ['reconciled' => 0, 'cancelled' => 0];
        if (! self::ready()) {
            return $out;
        }
        $autoCancel = (bool) $this->settings()->deposit_auto_cancel;
        $rows = Booking::whereNull('deleted_at')->where('source', 'website')->where('status', 'pending')
            ->where('booking_mode', 'slot')->where('deposit_status', 'due')
            ->whereNotNull('hold_until')->where('hold_until', '<', now())
            // Left for staff: only those with a payment to look up (otherwise they'd fill every run).
            ->when(! $autoCancel, fn ($q) => $q->whereExists(fn ($e) => $e->from('bk_payments')->whereColumn('bk_payments.booking_id', 'bookings.id')
                ->where('bk_payments.status', 'pending')->whereNotNull('bk_payments.reference')->where('bk_payments.created_at', '>=', now()->subDays(2))))
            ->orderBy('hold_until')->limit(200)->get();
        foreach ($rows as $b) {
            $attempts = BookingPayment::where('booking_id', $b->id)->where('status', 'pending')
                ->whereNotNull('reference')->where('created_at', '>=', now()->subDays(2))->orderByDesc('id')->get();
            $paid = false;
            foreach ($attempts as $p) {
                if ($this->reconcile($p)) {
                    $paid = true;
                    $out['reconciled']++;
                    break;
                }
            }
            if ($paid || ! $this->settings()->deposit_auto_cancel) {
                continue;
            }
            // Someone on the gateway page right now gets a few more minutes.
            if (BookingPayment::where('booking_id', $b->id)->where('status', 'pending')->where('created_at', '>=', now()->subMinutes(15))->exists()) {
                continue;
            }
            try {
                // Look again under the row lock: paid, confirmed or waived meanwhile → leave it.
                $done = BookingTx::run(function () use ($b) {
                    $x = Booking::whereKey($b->id)->lockForUpdate()->first();
                    if (! $x || $x->status !== 'pending' || $x->deposit_status !== 'due' || ! $x->hold_until || $x->hold_until->isFuture()) {
                        return false;
                    }
                    app(BookingWorkflow::class)->cancel($x, null, __('messages.BkmDepExpiredReason'), 'system');

                    return true;
                });
                $out['cancelled'] += $done ? 1 : 0;
            } catch (BookingWorkflowException $e) {
                // Answered by staff in the meantime.
            }
        }

        return $out;
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /** The booking can take a deposit now; an expired hold is renewed when the time is still free. */
    private function ensurePayable(Booking $b): Booking
    {
        return BookingTx::run(function () use ($b) {
            $b = Booking::whereKey($b->id)->lockForUpdate()->firstOrFail();
            if (! $this->payableNow($b)) {
                throw new BookingWebsiteException(__('messages.BkmDepErrNotDue'), 422);
            }
            if (BookingPayment::where('booking_id', $b->id)->count() >= self::MAX_ATTEMPTS) {
                throw new BookingWebsiteException(__('messages.BkmDepErrTooMany'), 429);
            }
            if ($b->status === 'pending' && $b->booking_mode === 'slot' && $b->hold_until && $b->hold_until->isPast()) {
                // A lapsed hold is renewed for a while, not for ever (no squatting a time unpaid).
                if ($b->created_at && $b->created_at->copy()->addMinutes($this->holdMinutes() * self::RENEW_WITHIN_HOLDS)->isPast()) {
                    throw new BookingWebsiteException(__('messages.BkmDepErrExpired'), 409);
                }
                if (! $this->timeStillFree($b)) {
                    throw new BookingWebsiteException(__('messages.BkmDepErrTaken'), 409);
                }
                $b->hold_until = now()->addMinutes($this->holdMinutes());
                $b->save();
            }

            return $b;
        });
    }

    /** The status page offers to pay: a free time while it waits or once confirmed; a request once confirmed. */
    public function payableNow(Booking $b): bool
    {
        if (! self::ready() || ! $b->depositDue() || ! $b->public_token) {
            return false;
        }

        return $b->status === 'confirmed' || ($b->status === 'pending' && $b->booking_mode === 'slot');
    }

    /** The booking's own time is still free for its staff member (checked under the staff lock). */
    private function timeStillFree(Booking $b): bool
    {
        if (! $b->staff_id) {
            return true;
        }
        BookingStaff::whereKey($b->staff_id)->lockForUpdate()->first();
        $check = (new AvailabilityService($this->settings()))->check(
            $b->product_id, (int) $b->staff_id, \Carbon\Carbon::parse($b->booking_date)->toDateString(),
            substr((string) $b->booking_time, 0, 5), $b->booking_end_time ? substr((string) $b->booking_end_time, 0, 5) : null,
            max(1, (int) $b->seats), (int) $b->id
        );

        return (bool) $check['ok'];
    }

    public function holdMinutes(): int
    {
        return max(5, (int) ($this->settings()->deposit_minutes ?: 30));
    }

    private function staffStep(Booking $b, User $by, array $from, callable $apply, string $event): Booking
    {
        $booking = BookingTx::run(function () use ($b, $from, $apply) {
            $x = Booking::whereKey($b->id)->lockForUpdate()->firstOrFail();
            if (! in_array($x->deposit_status, $from, true)) {
                throw new BookingWorkflowException(__('The deposit of this booking cannot be changed that way.'), 'wrong_deposit');
            }
            $apply($x);
            $x->save();

            return $x->fresh();
        });
        event(new BookingChanged($booking, $event, $booking->status, $by->id));

        return $booking;
    }

    /** @return array{paid: bool, transaction_id: ?string} */
    private function verify(BookingPayment $p): array
    {
        $amount = (float) $p->amount;
        $currency = (string) $p->currency;

        switch ($p->gateway) {
            case 'stripe':
                \Stripe\Stripe::setApiKey((string) PaymentSetting::current()->stripe_secret);
                $s = \Stripe\Checkout\Session::retrieve($p->reference);
                $ok = ($s->payment_status ?? '') === 'paid'
                    && strtoupper((string) $s->currency) === strtoupper($currency)
                    && (int) $s->amount_total === $this->minor($amount, $currency);

                return ['paid' => $ok, 'transaction_id' => is_string($s->payment_intent ?? null) ? $s->payment_intent : null];
            case 'paypal':
                $r = app(TenantPaypalService::class)->captureOrder($p->reference, $amount, $currency);

                return ['paid' => (bool) $r['success'], 'transaction_id' => $r['capture_id'] ?? null];
            case 'paystack':
                $r = app(TenantPaystackService::class)->verifyTransaction($p->reference, $amount, $currency);

                return ['paid' => (bool) $r['success'], 'transaction_id' => $r['transaction_id'] ?? null];
            case 'flutterwave':
                $r = app(TenantFlutterwaveService::class)->verifyTransaction($p->reference, $amount, $currency);

                return ['paid' => (bool) $r['success'], 'transaction_id' => ($r['transaction_id'] ?? '') ?: null];
            case 'moyasar':
                if (strtoupper(app(TenantMoyasarService::class)->currency()) !== strtoupper($currency)) {
                    return ['paid' => false, 'transaction_id' => null];
                }
                $r = app(TenantMoyasarService::class)->verifyInvoice($p->reference, $amount);

                return ['paid' => (bool) $r['success'], 'transaction_id' => $r['payment_id'] ?? null];
            case 'myfatoorah':
                if (strtoupper(app(TenantMyfatoorahService::class)->currency()) !== strtoupper($currency)) {
                    return ['paid' => false, 'transaction_id' => null];
                }
                $r = app(TenantMyfatoorahService::class)->verifyInvoice($p->reference, $amount);

                return ['paid' => (bool) $r['success'], 'transaction_id' => $r['transaction_id'] ?? null];
        }

        return ['paid' => false, 'transaction_id' => null];
    }

    private function stripeStart(float $amount, string $currency, string $title, string $email, array $meta, string $return, string $back, Booking $b): array
    {
        // A held time: the checkout closes soon after the hold (Stripe allows 30 min – 24 h).
        $held = $b->status === 'pending' && $b->hold_until ? max(1800, $b->hold_until->getTimestamp() - time() + 600) : 86400 - 60;
        \Stripe\Stripe::setApiKey((string) PaymentSetting::current()->stripe_secret);
        $session = \Stripe\Checkout\Session::create(array_filter([
            'mode' => 'payment',
            'payment_method_types' => ['card'],
            'line_items' => [[
                'price_data' => [
                    'currency' => strtolower($currency),
                    'unit_amount' => $this->minor($amount, $currency),
                    'product_data' => ['name' => mb_substr($title, 0, 250)],
                ],
                'quantity' => 1,
            ]],
            'customer_email' => filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null,
            'client_reference_id' => 'bk-'.$meta['bk_payment_id'],
            'metadata' => $meta,
            'success_url' => $return.'?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $back,
            'expires_at' => time() + min(86400 - 60, $held),
        ], fn ($v) => $v !== null));

        return [(string) $session->url, (string) $session->id];
    }

    private function minor(float $amount, string $currency): int
    {
        $c = strtoupper($currency);
        if (in_array($c, self::ZERO_DECIMAL, true)) {
            return (int) round($amount);
        }
        if (in_array($c, self::THREE_DECIMAL, true)) {
            return (int) (round($amount * 100) * 10);
        }

        return (int) round($amount * 100);
    }

    /** The payment method a charged booking's online deposit is recorded under ("Online deposit", made on first use). */
    public static function paymentMethodId(): int
    {
        $row = DB::table('payment_methods')->whereNull('deleted_at')->whereRaw('LOWER(name) = ?', ['online deposit'])->first();
        if ($row) {
            return (int) $row->id;
        }

        return (int) DB::table('payment_methods')->insertGetId(['name' => 'Online deposit', 'created_at' => now(), 'updated_at' => now()]);
    }

    /** A deposit amount as the customer pays it: in the store's base currency, whatever currency the visitor browses in. */
    public static function money(float $amount): string
    {
        return store_money($amount, StoreCurrencyService::base());
    }

    public function round(float $amount): float
    {
        $c = $this->currency();
        $decimals = in_array($c, self::ZERO_DECIMAL, true) ? 0 : (in_array($c, self::THREE_DECIMAL, true) ? 3 : 2);

        return round(max(0, $amount), $decimals);
    }

    /** What is still to pay on the deposit (a smaller earlier payment counts). */
    public function remaining(Booking $b): float
    {
        return $this->round(max(0, (float) $b->deposit_amount - (float) $b->deposit_paid));
    }

    private function storeName(): string
    {
        return (string) (optional(StoreSetting::query()->first())->store_name ?: config('app.name'));
    }
}
