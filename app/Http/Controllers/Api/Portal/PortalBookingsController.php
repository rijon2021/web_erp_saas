<?php

namespace App\Http\Controllers\Api\Portal;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\BookingStaff;
use App\Services\Booking\BookingDepositService;
use App\Services\Booking\BookingWebsiteException;
use App\Services\Booking\BookingWebsiteService;
use App\Services\Booking\BookingWorkflow;
use App\Services\Booking\BookingWorkflowException;
use App\Support\BookingModule;
use App\Support\BookingTypes;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Client portal — the customer's bookings (Booking Management): upcoming and
 * past visits, cancelling (Booking Settings › customers can cancel online),
 * answering a suggested time, and a link to pay a deposit on the booking's
 * status page.
 *
 * Scoped to the logged-in portal client's client_id. Declined website
 * requests stay visible so the customer sees the answer.
 */
class PortalBookingsController extends Controller
{
    public function __construct(private BookingWebsiteService $web)
    {
        // SaaS: no plan middleware on portal routes, so a tenant without
        // Booking Management (plan / switched off / not migrated) answers 404.
        // summary() answers enabled=false on its own (the nav probe).
        $this->middleware(function ($request, $next) {
            if (! self::moduleEnabled()) {
                return response()->json(['message' => 'Not found.'], 404);
            }

            return $next($request);
        })->except('summary');
    }

    public static function moduleEnabled(): bool
    {
        try {
            return BookingModule::enabled() && Booking::workflowReady();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** GET /api/portal/bookings/summary — does this customer have bookings, and what needs them. */
    public function summary(Request $request)
    {
        $client = $this->client();
        if (! self::moduleEnabled()) {
            return response()->json(['enabled' => false]);
        }
        $base = $this->base($client->client_id);
        $today = Carbon::today()->toDateString();

        return response()->json([
            'enabled' => (clone $base)->exists(),
            'upcoming' => (clone $base)->whereIn('status', ['pending', 'confirmed'])->whereDate('booking_date', '>=', $today)->count(),
            'needs_answer' => Booking::notificationsReady() ? (clone $base)->where('status', 'pending')->whereNotNull('proposed_at')->count() : 0,
            'deposit_due' => Booking::depositsReady()
                ? (clone $base)->where('deposit_status', 'due')->whereNotNull('public_token')
                    ->where(fn ($q) => $q->where('status', 'confirmed')->orWhere(fn ($w) => $w->where('status', 'pending')->where('booking_mode', 'slot')))->count()
                : 0,
        ]);
    }

    /** GET /api/portal/bookings?when=upcoming|past&status=&search=&page=&limit= */
    public function index(Request $request)
    {
        $client = $this->client();
        $today = Carbon::today()->toDateString();
        $perPage = max(1, min(100, (int) $request->input('limit', 10)));
        $page = max(1, (int) $request->input('page', 1));
        $when = $request->input('when') === 'past' ? 'past' : ($request->input('when') === 'all' ? 'all' : 'upcoming');

        $q = $this->base($client->client_id)->with(['product', 'staff'])
            ->when($when === 'upcoming', fn ($w) => $w->whereIn('status', ['pending', 'confirmed', 'arrived'])->whereDate('booking_date', '>=', $today))
            ->when($when === 'past', fn ($w) => $w->where(fn ($x) => $x->whereNotIn('status', ['pending', 'confirmed', 'arrived'])->orWhereDate('booking_date', '<', $today)))
            ->when($request->filled('status'), fn ($w) => $w->where('status', (string) $request->input('status')))
            ->when($request->filled('search'), function ($w) use ($request) {
                $s = '%'.str_replace(['%', '_'], ['\%', '\_'], trim((string) $request->input('search'))).'%';
                $w->where(fn ($x) => $x->where('Ref', 'like', $s)->orWhereHas('product', fn ($p) => $p->where('name', 'like', $s)));
            });
        $total = (clone $q)->count();
        $asc = $when === 'upcoming';
        $rows = $q->orderBy('booking_date', $asc ? 'asc' : 'desc')->orderBy('booking_time', $asc ? 'asc' : 'desc')->orderByDesc('id')
            ->offset(($page - 1) * $perPage)->limit($perPage)->get();

        return response()->json([
            'totalRows' => $total,
            'bookings' => $rows->map(fn ($b) => $this->row($b))->values(),
            'book_url' => $this->bookUrl(),
        ]);
    }

    /** GET /api/portal/bookings/{id} */
    public function show(Request $request, $id)
    {
        return response()->json($this->detail($this->find($id)));
    }

    /** POST /api/portal/bookings/{id}/cancel */
    public function cancel(Request $request, $id)
    {
        $b = $this->find($id);
        try {
            $this->web->cancelByCustomer($b);
        } catch (BookingWebsiteException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status());
        }

        return response()->json(['message' => __('portal.bookings_cancelled'), 'booking' => $this->detail($this->find($id))]);
    }

    /** POST /api/portal/bookings/{id}/accept-time — take the time staff suggested. */
    public function acceptTime(Request $request, $id)
    {
        $b = $this->find($id);
        if (! Booking::notificationsReady()) {
            abort(404);
        }
        try {
            app(BookingWorkflow::class)->acceptProposal($b);
        } catch (BookingWorkflowException $e) {
            return response()->json(['message' => $e->reason === 'proposal_taken' ? __('portal.bookings_time_taken') : __('portal.bookings_no_suggestion')], 422);
        }

        return response()->json(['message' => __('portal.bookings_time_accepted'), 'booking' => $this->detail($this->find($id))]);
    }

    /** POST /api/portal/bookings/{id}/decline-time — ask for another time. */
    public function declineTime(Request $request, $id)
    {
        $b = $this->find($id);
        if (! Booking::notificationsReady()) {
            abort(404);
        }
        try {
            app(BookingWorkflow::class)->declineProposal($b);
        } catch (BookingWorkflowException $e) {
            return response()->json(['message' => __('portal.bookings_no_suggestion')], 422);
        }

        return response()->json(['message' => __('portal.bookings_time_declined'), 'booking' => $this->detail($this->find($id))]);
    }

    // ------------------------------------------------------------------

    private function client()
    {
        $client = Auth::guard('portal')->user();
        if ((int) $client->status !== 1) {
            abort(403, __('portal.portal_disabled'));
        }

        return $client;
    }

    private function base($clientId)
    {
        return Booking::query()->whereNull('deleted_at')->where('customer_id', (int) $clientId ?: -1);
    }

    private function find($id): Booking
    {
        return $this->base($this->client()->client_id)->with(['product', 'staff'])->findOrFail((int) $id);
    }

    private function row(Booking $b): array
    {
        $isRequest = $this->openRequest($b);

        return [
            'id' => $b->id,
            'Ref' => $b->Ref,
            'service' => optional($b->product)->name ?: $b->product_name,
            'date' => Carbon::parse($b->booking_date)->toDateString(),
            'time' => $isRequest ? null : substr((string) $b->booking_time, 0, 5),
            'end_time' => $isRequest || ! $b->booking_end_time ? null : substr((string) $b->booking_end_time, 0, 5),
            'window' => $isRequest ? $b->preferred_window : null,
            'staff' => $b->staff && ! $isRequest ? $b->staff->name : null,
            'status' => $b->status,
            'stage' => $this->web->stage($b),
            'needs_answer' => Booking::notificationsReady() && $b->hasProposal(),
            'deposit_due' => Booking::depositsReady() && app(BookingDepositService::class)->payableNow($b),
        ];
    }

    private function detail(Booking $b): array
    {
        $deposit = null;
        if (Booking::depositsReady() && $b->deposit_status) {
            $deposit = [
                'status' => $b->deposit_status,
                'amount' => (float) $b->deposit_amount,
                'paid' => (float) $b->deposit_paid,
                'paid_at' => $b->deposit_paid_at?->format('Y-m-d H:i'),
                'payable' => app(BookingDepositService::class)->payableNow($b),
            ];
        }
        $defs = BookingTypes::allFields();
        $answers = [];
        foreach ((array) ($b->request_fields ?? []) as $k => $v) {
            if (str_starts_with((string) $k, '_') || $v === null || $v === '' || (is_array($v) && isset($v['file']))) {
                continue;
            }
            $f = $defs[$k] ?? ['id' => $k, 'label' => ucfirst(str_replace('_', ' ', (string) $k)), 'input' => 'text'];
            $answers[] = ['label' => BookingTypes::fieldLabel($f), 'value' => match (true) {
                $f['input'] === 'bool' => $v ? __('messages.BkmYes') : __('messages.BkmNo'),
                $f['input'] === 'choice' => BookingTypes::optionLabel((string) $v),
                is_array($v) => implode(', ', array_map('strval', $v)),
                default => (string) $v,
            }];
        }
        $proposal = Booking::notificationsReady() && $b->hasProposal() ? [
            'date' => Carbon::parse($b->proposed_date)->toDateString(),
            'time' => $b->proposed_time,
            'end_time' => $b->proposed_end_time,
            'staff' => $b->proposed_staff_id ? BookingStaff::withTrashed()->whereKey($b->proposed_staff_id)->value('name') : null,
            'note' => $b->proposal_note,
        ] : null;
        $showReason = in_array($b->status, ['declined', 'cancelled'], true) && $b->cancel_reason && $b->cancelled_by !== 'customer';

        return $this->row($b) + [
            'seats' => (int) ($b->seats ?: 1),
            'answers' => $answers,
            'proposal' => $proposal,
            'deposit' => $deposit,
            'reason' => $showReason ? $b->cancel_reason : null,
            'can_cancel' => $this->web->canCancel($b),
            'status_url' => ($b->source ?? '') === 'website' && $b->public_token ? store_base_url('appointment/'.$b->public_token) : null,
            'calendar_url' => ($b->source ?? '') === 'website' && $b->public_token && in_array($b->status, ['confirmed', 'arrived'], true)
                ? store_base_url('appointment/'.$b->public_token.'/calendar.ics') : null,
            'book_url' => $this->bookUrl(),
            'created_at' => $b->created_at ? Carbon::parse($b->created_at)->format('Y-m-d H:i') : null,
        ];
    }

    /** The website's booking page, while the store uses the Booking theme. */
    private function bookUrl(): ?string
    {
        return BookingWebsiteService::available() && \App\Http\Controllers\BookingStoreController::themeActive() ? store_base_url('book') : null;
    }

    private function openRequest(Booking $b): bool
    {
        return $b->booking_mode === 'request' && $b->status === 'pending' && ! $b->confirmed_at;
    }
}
