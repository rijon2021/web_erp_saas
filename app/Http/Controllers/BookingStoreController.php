<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\BookingPayment;
use App\Models\StoreSetting;
use App\Services\Booking\BookingDepositService;
use App\Services\Booking\BookingWebsiteException;
use App\Services\Booking\BookingWebsiteService;
use App\Support\BookingTheme;
use App\Support\BookingTypes;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Booking storefront theme (StoreSetting->theme === 'booking'). Lists the
 * services and team of Booking Management and takes bookings that land in
 * Booking Management › Requests (see BookingWebsiteService).
 *
 *   GET  /                               home: hero booking form, services, how it works, team, about, hours
 *   GET  /shop                           → home#services
 *   GET  /book?service&staff&date&time&mode   the booking steps
 *   GET  /book/slots?service_id&date&staff_id&seats → free start times (JSON)
 *   GET  /book/days?service_id&from&to&staff_id&seats → free times per day (JSON)
 *   POST /book                           booking (JSON; multipart when files ride along)
 *   GET  /appointment/{token}            the customer's status page (the token is the secret)
 *   POST /appointment/{token}/cancel
 *   GET  /appointment/{token}/calendar.ics
 *   GET  /account/appointments           signed-in customer's bookings
 *
 * (The admin app owns /bookings/*, so the storefront uses /book and /appointment.)
 */
class BookingStoreController extends StoreFrontController
{
    public function __construct(private BookingWebsiteService $web)
    {
    }

    /** Variables every page of the theme passes to the layout. */
    private function base(array $extra = []): array
    {
        $s = StoreSetting::firstOrFail();
        $ready = BookingWebsiteService::available();

        return array_merge([
            's' => $s,
            'opts' => BookingTheme::options($s),
            'bkReady' => $ready,
            'bkModes' => $ready ? $this->web->modes() : [],
            'bkSettings' => $ready ? $this->web->settings() : null,
            // The layout's product category drawer stays empty: services have their own list.
            'categories' => collect(),
            'showCategoryBar' => false,
        ], $extra);
    }

    public function home(Request $request)
    {
        $data = $this->base();
        if (! $data['bkReady']) {
            return view('store.booking.unavailable', $data);
        }
        $services = $this->web->services();
        $opts = $data['opts'];

        return view('store.booking.home', $data + [
            'services' => $services,
            'serviceCategories' => $this->web->categories($services),
            'team' => $opts['sections']['team'] ? $this->web->team() : collect(),
            'hours' => $this->hours($opts),
            'windows' => $this->web->settings()->enabledWindows(),
        ]);
    }

    /** GET /book — the booking steps (service → staff → time → details). */
    public function book(Request $request)
    {
        if (! self::themeActive()) {
            abort(404);
        }
        $data = $this->base();
        if (! $data['bkReady']) {
            return view('store.booking.unavailable', $data);
        }
        $services = $this->web->services();
        $fields = [];
        foreach ($services as $svc) {
            $fields[$svc['id']] = $this->web->formFieldsForPage($svc);
        }
        $user = Auth::guard('store')->user();
        $s = $this->web->settings();
        $mode = in_array($request->query('mode'), $data['bkModes'], true) ? $request->query('mode') : null;

        return view('store.booking.book', $data + [
            'services' => $services,
            'serviceCategories' => $this->web->categories($services),
            'fieldsByService' => $fields,
            'windows' => $s->enabledWindows(),
            'terms' => trim((string) ($s->terms ?? '')),
            'customer' => $user,
            'initial' => [
                'service' => (int) $request->query('service') ?: null,
                'staff' => (int) $request->query('staff') ?: null,
                'date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->query('date')) ? (string) $request->query('date') : null,
                'time' => preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $request->query('time')) ? (string) $request->query('time') : null,
                'window' => array_key_exists((string) $request->query('window'), $s->enabledWindows()) ? (string) $request->query('window') : null,
                'seats' => max(1, (int) $request->query('seats', 1)),
                'mode' => $mode,
            ],
            'limits' => [
                'today' => Carbon::today()->toDateString(),
                'last' => Carbon::today()->addDays(max(1, (int) $s->max_days_ahead))->toDateString(),
            ],
            'seo' => ['title' => __('messages.BkmBookTitle').' — '.($data['s']->store_name ?: config('app.name')), 'canonical' => store_base_url('book')],
        ]);
    }

    /** GET /book/slots */
    public function slots(Request $request)
    {
        $svc = $this->serviceOr404($request);
        $v = $request->validate([
            'date' => 'required|date_format:Y-m-d',
            'staff_id' => 'nullable|integer',
            'seats' => 'nullable|integer|min:1|max:500',
        ]);
        $staffId = ! empty($v['staff_id']) ? (int) $v['staff_id'] : null;
        if ($staffId && ! collect($svc['staff'])->contains('id', $staffId)) {
            return response()->json(['slots' => []]);
        }
        $slots = $this->web->slots($svc, $v['date'], $staffId, (int) ($v['seats'] ?? 1));

        // Staff ids stay on the server: the visitor sees times (and seats left for a class).
        return response()->json(['slots' => array_map(fn ($s) => ['time' => $s['time'], 'end' => $s['end'], 'seats_left' => $s['seats_left']], $slots)]);
    }

    /** GET /book/days */
    public function days(Request $request)
    {
        $svc = $this->serviceOr404($request);
        $v = $request->validate([
            'from' => 'required|date_format:Y-m-d',
            'to' => 'required|date_format:Y-m-d|after_or_equal:from',
            'staff_id' => 'nullable|integer',
            'seats' => 'nullable|integer|min:1|max:500',
        ]);
        if (Carbon::parse($v['from'])->diffInDays(Carbon::parse($v['to']), true) > 42) {
            return response()->json(['message' => __('messages.BkmErrDate')], 422);
        }
        $staffId = ! empty($v['staff_id']) ? (int) $v['staff_id'] : null;
        if ($staffId && ! collect($svc['staff'])->contains('id', $staffId)) {
            return response()->json(['days' => (object) []]);
        }

        return response()->json(['days' => (object) $this->web->days($svc, $v['from'], $v['to'], $staffId, (int) ($v['seats'] ?? 1))]);
    }

    /** POST /book — multipart when a photo / document rides along. */
    public function store(Request $request)
    {
        // Honeypot: real visitors never fill the hidden "website" field.
        if (trim((string) $request->input('website', '')) !== '') {
            return response()->json(['ok' => true]);
        }
        if (! self::themeActive() || ! $this->web->bookingOpen()) {
            return response()->json(['message' => __('messages.BkmErrClosed')], 409);
        }
        $svc = $this->web->service((int) $request->input('service_id'));
        if (! $svc) {
            return response()->json(['message' => __('messages.BkmErrService'), 'errors' => ['service_id' => [__('messages.BkmErrService')]]], 422);
        }
        $terms = trim((string) ($this->web->settings()->terms ?? '')) !== '';
        $request->validate([
            'service_id' => 'required|integer',
            'mode' => 'required|in:slot,request',
            'date' => 'required|date_format:Y-m-d',
            'time' => ['required_if:mode,slot', 'nullable', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'window' => 'required_if:mode,request|nullable|string|max:20',
            'staff_id' => 'nullable|integer',
            'seats' => 'nullable|integer|min:1|max:500',
            'name' => 'required|string|max:150',
            'phone' => 'required|string|max:40',
            'email' => 'required|email|max:190',
            'fields' => 'nullable|array',
            'field_files' => 'nullable|array',
            'accept_terms' => $terms ? 'accepted' : 'nullable',
        ] + $this->web->fieldRules($svc), [], $this->web->fieldAttributes($svc) + [
            'name' => mb_strtolower(__('messages.BkmYourName')),
            'phone' => mb_strtolower(__('messages.Phone')),
            'email' => mb_strtolower(__('messages.Email')),
            'accept_terms' => mb_strtolower(__('messages.BkmTerms')),
        ]);

        // At most 10 website bookings per connection a day, on top of the per-minute limit.
        $dayKey = 'bkm-book-day:'.$request->ip();
        if (RateLimiter::tooManyAttempts($dayKey, 10)) {
            return response()->json(['message' => __('messages.BkmErrTooManyToday')], 429);
        }

        try {
            $b = $this->web->book($svc, [
                'mode' => $request->input('mode'),
                'date' => $request->input('date'),
                'time' => $request->input('time'),
                'window' => $request->input('window'),
                'staff_id' => $request->input('staff_id'),
                'seats' => $request->input('seats', 1),
                'name' => $request->input('name'),
                'phone' => $request->input('phone'),
                'email' => $request->input('email'),
                'fields' => (array) $request->input('fields', []),
            ], Auth::guard('store')->user(), array_filter((array) $request->file('field_files', [])));
        } catch (BookingWebsiteException $e) {
            $body = ['message' => $e->getMessage()];
            if ($e->field()) {
                $body['errors'] = [$e->field() => [$e->getMessage()]];
            }

            return response()->json($body, $e->status());
        }
        RateLimiter::hit($dayKey, 86400);

        return response()->json([
            'ok' => true,
            'ref' => $b->Ref,
            'status' => $b->status,
            'redirect' => store_base_url('appointment/'.$b->public_token),
        ], 201);
    }

    /** GET /appointment/{token} */
    public function status(Request $request, string $token)
    {
        $b = $this->findByToken($token);
        if (! $b) {
            abort(404);
        }
        $deposit = $this->depositView($b);
        if ($deposit['reconciled']) {
            $b = $this->findByToken($token);
            $deposit = $this->depositView($b);
        }
        $data = $this->base();
        $defs = BookingTypes::allFields();
        $answers = collect((array) ($b->request_fields ?? []))
            ->reject(fn ($v, $k) => str_starts_with((string) $k, '_'))
            ->map(function ($v, $k) use ($defs) {
                $f = $defs[$k] ?? ['id' => $k, 'label' => ucfirst(str_replace('_', ' ', (string) $k)), 'input' => 'text'];
                $text = match (true) {
                    $f['input'] === 'bool' => $v ? __('messages.BkmYes') : __('messages.BkmNo'),
                    $f['input'] === 'choice' => BookingTypes::optionLabel((string) $v),
                    $f['input'] === 'file' && is_array($v) => (string) ($v['name'] ?? ''),
                    is_array($v) => implode(', ', $v),
                    default => (string) $v,
                };

                return ['label' => BookingTypes::fieldLabel($f), 'value' => $text];
            })->values();

        return view('store.booking.status', $data + [
            'b' => $b,
            'stage' => $this->web->stage($b),
            'canCancel' => $this->web->canCancel($b),
            'customerCancel' => (bool) $this->web->settings()->customer_cancel,
            'cancelHours' => (int) $this->web->settings()->cancel_notice_hours,
            'priceDisplay' => $b->product_id ? \App\Models\BookingServiceDetail::where('product_id', $b->product_id)->value('price_display') : 'hidden',
            'answers' => $answers,
            'windows' => $this->web->settings()->timeWindows(),
            'deposit' => $deposit,
            'seo' => ['title' => __('messages.BkmStatusTitle', ['ref' => $b->Ref]), 'canonical' => store_base_url('appointment/'.$token)],
        ]);
    }

    /** POST /appointment/{token}/cancel */
    public function cancel(Request $request, string $token)
    {
        $b = $this->findByToken($token);
        if (! $b) {
            abort(404);
        }
        try {
            $this->web->cancelByCustomer($b);
        } catch (BookingWebsiteException $e) {
            return $request->expectsJson()
                ? response()->json(['message' => $e->getMessage()], $e->status())
                : redirect(store_base_url('appointment/'.$token))->with('bk_error', $e->getMessage());
        }

        return $request->expectsJson()
            ? response()->json(['ok' => true])
            : redirect(store_base_url('appointment/'.$token))->with('bk_notice', __('messages.BkmCancelledNotice'));
    }

    /** POST /appointment/{token}/deposit — off to the chosen gateway to pay the deposit. */
    public function payDeposit(Request $request, string $token)
    {
        $b = $this->findByToken($token);
        if (! $b || ! BookingDepositService::ready()) {
            abort(404);
        }
        $gateway = (string) $request->input('gateway', '');
        try {
            $url = app(BookingDepositService::class)->start($b, $gateway);
        } catch (BookingWebsiteException $e) {
            return redirect(store_base_url('appointment/'.$token))->with('bk_error', $e->getMessage());
        }

        return redirect()->away($url);
    }

    /** GET /appointment/{token}/deposit/{payment}/return — back from the gateway; the payment is checked with it. */
    public function depositReturn(Request $request, string $token, int $payment)
    {
        $b = $this->findByToken($token);
        if (! $b || ! BookingDepositService::ready()) {
            abort(404);
        }
        $p = BookingPayment::where('booking_id', $b->id)->find($payment);
        if (! $p) {
            abort(404);
        }
        $paid = app(BookingDepositService::class)->confirmReturn($b, $p);

        return redirect(store_base_url('appointment/'.$token))->with(
            $paid ? 'bk_notice' : 'bk_error',
            $paid ? __('messages.BkmDepPaidNotice') : __('messages.BkmDepNotPaidYet')
        );
    }

    /** POST /appointment/{token}/accept — the customer takes the time staff suggested. */
    public function acceptProposal(Request $request, string $token)
    {
        $b = $this->findByToken($token);
        if (! $b || ! Booking::notificationsReady()) {
            abort(404);
        }
        try {
            app(\App\Services\Booking\BookingWorkflow::class)->acceptProposal($b);
        } catch (\App\Services\Booking\BookingWorkflowException $e) {
            $msg = $e->reason === 'proposal_taken' ? __('messages.BkmErrProposalTaken') : __('messages.BkmErrNoProposal');

            return redirect(store_base_url('appointment/'.$token))->with('bk_error', $msg);
        }

        return redirect(store_base_url('appointment/'.$token))->with('bk_notice', __('messages.BkmProposalAcceptedNotice'));
    }

    /** POST /appointment/{token}/decline-time — the customer asks for another time. */
    public function declineProposal(Request $request, string $token)
    {
        $b = $this->findByToken($token);
        if (! $b || ! Booking::notificationsReady()) {
            abort(404);
        }
        try {
            app(\App\Services\Booking\BookingWorkflow::class)->declineProposal($b);
        } catch (\App\Services\Booking\BookingWorkflowException $e) {
            return redirect(store_base_url('appointment/'.$token))->with('bk_error', __('messages.BkmErrNoProposal'));
        }

        return redirect(store_base_url('appointment/'.$token))->with('bk_notice', __('messages.BkmProposalDeclinedNotice'));
    }

    /** GET /appointment/{token}/calendar.ics — confirmed bookings only. */
    public function ics(Request $request, string $token)
    {
        $b = $this->findByToken($token);
        if (! $b || ! in_array($b->status, ['confirmed', 'arrived'], true)) {
            abort(404);
        }
        return response(\App\Services\Booking\BookingIcs::make($b), 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.\App\Services\Booking\BookingIcs::filename($b).'"',
        ]);
    }

    /** GET /account/appointments — bookings of the signed-in customer. */
    public function account(Request $request)
    {
        $data = $this->base();
        $user = Auth::guard('store')->user();
        $bookings = collect();
        if ($data['bkReady'] && $user) {
            $clientId = (int) $user->client_id;
            $bookings = Booking::with('product')->whereNull('deleted_at')
                ->where(fn ($q) => $q->where('ecommerce_client_id', $user->id)->when($clientId, fn ($w) => $w->orWhere('customer_id', $clientId)))
                ->orderByDesc('booking_date')->orderByDesc('booking_time')->limit(50)->get();
        }

        return view('store.booking.account', $data + ['bookings' => $bookings, 'web' => $this->web]);
    }

    /** The Booking theme is the store's theme right now (booking pages and endpoints need it). */
    public static function themeActive(): bool
    {
        return (StoreSetting::query()->value('theme') ?? 'default') === BookingTheme::KEY;
    }

    // ------------------------------------------------------------------

    private function serviceOr404(Request $request): array
    {
        if (! self::themeActive() || ! BookingWebsiteService::available()) {
            abort(response()->json(['message' => __('messages.BkmErrClosed')], 404));
        }
        $svc = $this->web->service((int) $request->query('service_id'));
        if (! $svc) {
            abort(response()->json(['message' => __('messages.BkmErrService')], 404));
        }

        return $svc;
    }

    private function findByToken(string $token): ?Booking
    {
        if (! Booking::workflowReady() || ! preg_match('/^[A-Za-z0-9]{20,64}$/', $token)) {
            return null;
        }

        return Booking::with(['product', 'staff', 'customer'])->whereNull('deleted_at')
            ->where('source', 'website')->where('public_token', $token)->first();
    }

    /**
     * What the status page shows about the deposit. A pending attempt (the
     * customer paid but never came back) is looked up with the gateway once.
     */
    private function depositView(Booking $b): array
    {
        $out = ['ready' => false, 'reconciled' => false];
        if (! BookingDepositService::ready() || ! $b->deposit_status) {
            return $out;
        }
        $svc = app(BookingDepositService::class);
        if ($b->depositDue()) {
            $attempt = BookingPayment::where('booking_id', $b->id)->where('status', 'pending')->whereNotNull('reference')
                ->where('created_at', '>=', now()->subDays(2))->orderByDesc('id')->first();
            if ($attempt && $svc->reconcile($attempt)) {
                return ['ready' => true, 'reconciled' => true];
            }
        }

        return [
            'ready' => true,
            'reconciled' => false,
            'status' => $b->deposit_status,
            // Still to pay (an earlier smaller payment counts).
            'amount' => BookingDepositService::money($b->depositDue() ? $svc->remaining($b) : (float) $b->deposit_amount),
            'paid' => BookingDepositService::money((float) $b->deposit_paid),
            'paid_at' => $b->deposit_paid_at,
            'payable' => $svc->payableNow($b),
            'later' => $b->depositDue() && $b->status === 'pending' && $b->booking_mode === 'request',
            'gateways' => $svc->payableNow($b) ? $svc->gateways() : [],
            'note' => trim((string) ($svc->settings()->deposit_note ?? '')),
        ];
    }

    /** Opening hours: the owner's text, else worked out from the staff hours. */
    private function hours(array $opts): array
    {
        $lines = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string) $opts['opening_hours']))));
        if ($lines) {
            return array_map(fn ($l) => ['day' => $l, 'hours' => null, 'raw' => true], $lines);
        }

        return array_map(fn ($r) => $r + ['raw' => false], $this->web->openingHours());
    }
}
