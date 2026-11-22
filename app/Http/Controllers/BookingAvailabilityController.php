<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\BookingStaff;
use App\Services\Booking\AvailabilityService;
use Illuminate\Http\Request;

/**
 * Free times for staff (any booking permission). The website asks the same
 * AvailabilityService with online rules in phase 5.
 *
 * GET bookings/availability        ?product_id&date&staff_id?&seats? → {slots, staff, closed}
 * GET bookings/availability/days   ?product_id&from&to&staff_id?&seats? → {days: {date: free start times}}
 * GET bookings/availability/check  ?product_id?&staff_id&date&time&end_time?&seats?&ignore_id? → check result
 */
class BookingAvailabilityController extends BaseController
{
    public function slots(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'bookings_any', Booking::class);
        if (! BookingStaff::tablesReady()) {
            return response()->json(['ready' => false, 'slots' => [], 'staff' => []]);
        }

        $data = $request->validate([
            'product_id' => 'required|integer',
            'date' => 'required|date_format:Y-m-d',
            'staff_id' => 'nullable|integer',
            'seats' => 'nullable|integer|min:1|max:500',
            'ignore_id' => 'nullable|integer',
        ]);

        $svc = new AvailabilityService();
        $productId = (int) $data['product_id'];
        $slots = $svc->slots($productId, $data['date'], isset($data['staff_id']) ? (int) $data['staff_id'] : null, (int) ($data['seats'] ?? 1), [
            'ignore_booking_id' => isset($data['ignore_id']) ? (int) $data['ignore_id'] : null,
        ]);
        $staff = $svc->serviceStaff($productId);

        // Why each staff member has nothing that day (day off, holiday…), for the page to explain.
        $closed = [];
        if (! $slots) {
            foreach ($staff as $member) {
                if (isset($data['staff_id']) && (int) $data['staff_id'] !== $member->id) {
                    continue;
                }
                $check = $svc->check($productId, $member->id, $data['date'], '00:00', '00:05');
                if (in_array($check['reason'], ['day_off', 'holiday', 'leave', 'time_off'], true)) {
                    $closed[$member->id] = $check['reason'];
                }
            }
        }

        return response()->json([
            'ready' => true,
            'bookable' => $svc->detail($productId) !== null,
            'slots' => $slots,
            'staff' => $staff->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'color' => $s->color])->values(),
            'closed' => $closed,
        ]);
    }

    public function days(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'bookings_any', Booking::class);
        if (! BookingStaff::tablesReady()) {
            return response()->json(['ready' => false, 'days' => []]);
        }

        $data = $request->validate([
            'product_id' => 'required|integer',
            'from' => 'required|date_format:Y-m-d',
            'to' => 'required|date_format:Y-m-d|after_or_equal:from',
            'staff_id' => 'nullable|integer',
            'seats' => 'nullable|integer|min:1|max:500',
        ]);
        if (\Carbon\Carbon::parse($data['from'])->diffInDays(\Carbon\Carbon::parse($data['to']), true) > 62) {
            return response()->json(['message' => __('Pick a range of at most two months.')], 422);
        }

        $days = (new AvailabilityService())->days(
            (int) $data['product_id'], $data['from'], $data['to'],
            isset($data['staff_id']) ? (int) $data['staff_id'] : null, (int) ($data['seats'] ?? 1)
        );

        return response()->json(['ready' => true, 'days' => (object) $days]);
    }

    public function check(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'bookings_any', Booking::class);
        if (! BookingStaff::tablesReady()) {
            return response()->json(['ready' => false, 'ok' => true, 'reason' => null]);
        }

        $data = $request->validate([
            'product_id' => 'nullable|integer',
            'staff_id' => 'required|integer',
            'date' => 'required|date_format:Y-m-d',
            'time' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d/'],
            'end_time' => ['nullable', 'regex:/^([01]\d|2[0-3]):[0-5]\d/'],
            'seats' => 'nullable|integer|min:1|max:500',
            'ignore_id' => 'nullable|integer',
        ]);

        $result = (new AvailabilityService())->check(
            isset($data['product_id']) ? (int) $data['product_id'] : null,
            (int) $data['staff_id'], $data['date'], substr($data['time'], 0, 5),
            isset($data['end_time']) ? substr($data['end_time'], 0, 5) : null,
            (int) ($data['seats'] ?? 1),
            isset($data['ignore_id']) ? (int) $data['ignore_id'] : null
        );

        return response()->json(['ready' => true] + $result);
    }
}
