<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\BookingStaff;
use App\Models\BookingStaffHour;
use App\Models\BookingStaffTimeOff;
use App\Models\Employee;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Booking\AvailabilityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Booking Management › Staff & working hours (permission bookings_staff).
 *
 * GET    bookings/staff                              → {ready, staff, employees, users, warehouses, services, holidays}
 * POST   bookings/staff                              → add (from an HRM employee, a user, or a name)
 * POST   bookings/staff/{id}/update                  → edit (multipart: photo_file / remove_photo)
 * DELETE bookings/staff/{id}                         → remove (soft; old bookings keep the link)
 * PUT    bookings/staff/{id}/hours                   → weekly hours {days: {1..7: {start, end, break_start, break_end} | null}}
 * GET    bookings/staff/{id}/shift-hours             → hours read from the employee's HRM office shift (not saved)
 * POST   bookings/staff/{id}/time-off                → add days off
 * DELETE bookings/staff/{id}/time-off/{offId}        → remove days off
 * PUT    bookings/staff/{id}/services                → {product_ids} the services this person does
 */
class BookingStaffController extends BaseController
{
    private const HHMM = 'regex:/^([01]\d|2[0-3]):[0-5]\d$/';

    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'bookings_staff', Booking::class);

        if (! BookingStaff::tablesReady()) {
            return response()->json(['ready' => false, 'staff' => []]);
        }

        $today = now()->toDateString();
        $staff = $this->scopeToWarehouses(BookingStaff::query(), 'warehouse_id', true)
            ->with(['hours', 'timeOff' => fn ($q) => $q->where('end_date', '>=', $today)->orderBy('start_date')])
            ->orderBy('sort_order')->orderBy('name')->get();
        $ids = $staff->pluck('id')->all();

        $links = DB::table('bk_service_staff')->whereIn('staff_id', $ids)->get()->groupBy('staff_id');
        $upcoming = Booking::whereIn('staff_id', $ids)->where('booking_date', '>=', $today)
            ->whereIn('status', AvailabilityService::BLOCKING)
            ->selectRaw('staff_id, COUNT(*) as n')->groupBy('staff_id')->pluck('n', 'staff_id');
        $leave = DB::table('leaves')->whereNull('deleted_at')->where('status', 'approved')->where('end_date', '>=', $today)
            ->whereIn('employee_id', $staff->pluck('employee_id')->filter()->all())
            ->orderBy('start_date')->get(['employee_id', 'start_date', 'end_date'])->groupBy('employee_id');

        $linkedEmployees = BookingStaff::whereNotNull('employee_id')->pluck('id', 'employee_id');
        $linkedUsers = BookingStaff::whereNotNull('user_id')->pluck('id', 'user_id');

        return response()->json([
            'ready' => true,
            'staff' => $staff->map(fn ($s) => $this->present(
                $s,
                $links[$s->id] ?? collect(),
                (int) ($upcoming[$s->id] ?? 0),
                $s->employee_id ? ($leave[$s->employee_id] ?? collect()) : collect()
            ))->values(),
            'employees' => Employee::whereNull('deleted_at')
                ->where(fn ($q) => $q->whereNull('leaving_date')->orWhere('leaving_date', '>=', $today))
                ->orderBy('firstname')->get(['id', 'firstname', 'lastname', 'office_shift_id'])
                ->map(fn ($e) => [
                    'id' => $e->id, 'name' => trim($e->firstname.' '.$e->lastname),
                    'has_shift' => (bool) $e->office_shift_id, 'staff_id' => $linkedEmployees[$e->id] ?? null,
                ]),
            'users' => User::where('statut', 1)->orderBy('firstname')->get(['id', 'firstname', 'lastname', 'username'])
                ->map(fn ($u) => [
                    'id' => $u->id, 'name' => trim($u->firstname.' '.$u->lastname) ?: $u->username,
                    'staff_id' => $linkedUsers[$u->id] ?? null,
                ]),
            'warehouses' => $this->scopeToWarehouses(Warehouse::whereNull('deleted_at'), 'id')->orderBy('name')->get(['id', 'name']),
            'services' => DB::table('bk_services as s')->join('products as p', 'p.id', '=', 's.product_id')
                ->whereNull('p.deleted_at')->where('p.type', 'is_service')->orderBy('s.sort_order')->orderBy('p.name')
                ->get(['p.id', 'p.name']),
            'holidays' => DB::table('holidays')->whereNull('deleted_at')->where('end_date', '>=', $today)
                ->orderBy('start_date')->limit(20)->get(['title', 'start_date', 'end_date']),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'bookings_staff', Booking::class);
        $this->ensureReady();

        $data = $request->validate([
            'employee_id' => ['nullable', 'integer', Rule::exists('employees', 'id')->whereNull('deleted_at')],
            'user_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'name' => 'nullable|string|max:120|required_without_all:employee_id,user_id',
            'title' => 'nullable|string|max:120',
            'color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'warehouse_id' => ['nullable', 'integer', Rule::exists('warehouses', 'id')->whereNull('deleted_at')],
        ]);
        if (! empty($data['warehouse_id'])) {
            $this->abortIfWarehouseDenied((int) $data['warehouse_id']);
        }
        if (! empty($data['employee_id']) && BookingStaff::where('employee_id', $data['employee_id'])->exists()) {
            throw ValidationException::withMessages(['employee_id' => [__('This employee is already bookable.')]]);
        }
        if (! empty($data['user_id']) && BookingStaff::where('user_id', $data['user_id'])->exists()) {
            throw ValidationException::withMessages(['user_id' => [__('This user is already bookable.')]]);
        }

        $employee = ! empty($data['employee_id']) ? Employee::find($data['employee_id']) : null;
        $user = ! empty($data['user_id']) ? User::find($data['user_id']) : null;
        $name = trim((string) ($data['name'] ?? ''))
            ?: ($employee ? trim($employee->firstname.' '.$employee->lastname) : '')
            ?: ($user ? (trim($user->firstname.' '.$user->lastname) ?: $user->username) : '');

        $staff = DB::transaction(function () use ($data, $employee, $user, $name) {
            $staff = BookingStaff::create([
                'employee_id' => $employee?->id,
                'user_id' => $user?->id,
                'name' => Str::limit($name, 120, ''),
                'title' => trim((string) ($data['title'] ?? '')) ?: null,
                'color' => $data['color'] ?? $this->nextColor(),
                'warehouse_id' => $data['warehouse_id'] ?? null,
                'sort_order' => (int) BookingStaff::max('sort_order') + 1,
            ]);
            // Start from the employee's office shift, else Monday–Friday 09:00–17:00.
            $days = ($employee ? $this->shiftDays($employee) : null)
                ?: array_fill_keys([1, 2, 3, 4, 5], ['start' => '09:00', 'end' => '17:00', 'break_start' => null, 'break_end' => null]);
            $this->saveDays($staff, $days);

            return $staff;
        });

        return response()->json(['staff' => $this->presentOne($staff->fresh())], 201);
    }

    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'bookings_staff', Booking::class);
        $this->ensureReady();
        $staff = $this->find($id);

        $data = $request->validate([
            'name' => 'required|string|max:120',
            'title' => 'nullable|string|max:120',
            'color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'warehouse_id' => ['nullable', 'integer', Rule::exists('warehouses', 'id')->whereNull('deleted_at')],
            'bookable_online' => 'required|boolean',
            'show_on_website' => 'required|boolean',
            'max_per_day' => 'nullable|integer|min:1|max:200',
            'sort_order' => 'nullable|integer|min:0|max:100000',
            'active' => 'required|boolean',
            'photo_file' => 'nullable|image|max:4096',
            'remove_photo' => 'nullable|boolean',
        ]);
        if (! empty($data['warehouse_id']) && (int) $data['warehouse_id'] !== (int) $staff->warehouse_id) {
            $this->abortIfWarehouseDenied((int) $data['warehouse_id']);
        }

        $staff->fill([
            'name' => trim($data['name']),
            'title' => trim((string) ($data['title'] ?? '')) ?: null,
            'color' => strtolower($data['color']),
            'warehouse_id' => $data['warehouse_id'] ?? null,
            'bookable_online' => (bool) $data['bookable_online'],
            'show_on_website' => (bool) $data['show_on_website'],
            'max_per_day' => $data['max_per_day'] ?? null,
            'sort_order' => (int) ($data['sort_order'] ?? $staff->sort_order),
            'active' => (bool) $data['active'],
        ])->save();
        $this->syncPhoto($request, $staff);

        return response()->json(['staff' => $this->presentOne($staff->fresh())]);
    }

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'bookings_staff', Booking::class);
        $this->ensureReady();
        $staff = $this->find($id);

        DB::transaction(function () use ($staff) {
            DB::table('bk_service_staff')->where('staff_id', $staff->id)->delete();
            $staff->update(['active' => false]);
            $staff->delete();
        });

        return response()->json(['success' => true]);
    }

    public function hours(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'bookings_staff', Booking::class);
        $this->ensureReady();
        $staff = $this->find($id);

        $request->validate(['days' => 'present|array']);
        $days = $this->cleanDays((array) $request->input('days', []));
        DB::transaction(fn () => $this->saveDays($staff, $days));

        return response()->json(['staff' => $this->presentOne($staff->fresh())]);
    }

    public function shiftHours(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'bookings_staff', Booking::class);
        $this->ensureReady();
        $staff = $this->find($id);

        $employee = $staff->employee_id ? Employee::find($staff->employee_id) : null;
        $days = $employee ? $this->shiftDays($employee) : null;
        if ($days === null) {
            return response()->json(['message' => __('This person has no HRM office shift.')], 422);
        }

        return response()->json(['days' => $this->daysPayload($days)]);
    }

    public function storeTimeOff(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'bookings_staff', Booking::class);
        $this->ensureReady();
        $staff = $this->find($id);

        $data = $request->validate([
            'start_date' => 'required|date_format:Y-m-d',
            'end_date' => 'required|date_format:Y-m-d|after_or_equal:start_date',
            'from_time' => ['nullable', self::HHMM],
            'to_time' => ['nullable', self::HHMM],
            'reason' => 'nullable|string|max:191',
        ]);
        if ($data['start_date'] === $data['end_date'] && ! empty($data['from_time']) && ! empty($data['to_time']) && $data['to_time'] <= $data['from_time']) {
            throw ValidationException::withMessages(['to_time' => [__('The end time must be after the start time.')]]);
        }
        $off = BookingStaffTimeOff::create([
            'staff_id' => $staff->id,
            'start_date' => $data['start_date'],
            'end_date' => $data['end_date'],
            'from_time' => $data['from_time'] ?? null,
            'to_time' => $data['to_time'] ?? null,
            'reason' => trim((string) ($data['reason'] ?? '')) ?: null,
        ]);

        return response()->json(['time_off' => $this->presentOff($off), 'staff' => $this->presentOne($staff->fresh())], 201);
    }

    public function destroyTimeOff(Request $request, $id, $offId)
    {
        $this->authorizeForUser($request->user('api'), 'bookings_staff', Booking::class);
        $this->ensureReady();
        $staff = $this->find($id);

        BookingStaffTimeOff::where('staff_id', $staff->id)->where('id', (int) $offId)->firstOrFail()->delete();

        return response()->json(['staff' => $this->presentOne($staff->fresh())]);
    }

    public function services(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'bookings_staff', Booking::class);
        $this->ensureReady();
        $staff = $this->find($id);

        $data = $request->validate([
            'product_ids' => 'present|array|max:1000',
            'product_ids.*' => 'integer',
        ]);
        $valid = DB::table('products')->whereNull('deleted_at')->where('type', 'is_service')
            ->whereIn('id', array_unique(array_map('intval', $data['product_ids'])))->pluck('id')->all();

        DB::transaction(function () use ($staff, $valid) {
            DB::table('bk_service_staff')->where('staff_id', $staff->id)->whereNotIn('product_id', $valid ?: [0])->delete();
            $have = DB::table('bk_service_staff')->where('staff_id', $staff->id)->pluck('product_id')->all();
            $rows = array_map(fn ($pid) => ['product_id' => $pid, 'staff_id' => $staff->id], array_values(array_diff($valid, $have)));
            if ($rows) {
                DB::table('bk_service_staff')->insert($rows);
            }
        });

        return response()->json(['staff' => $this->presentOne($staff->fresh())]);
    }

    // ------------------------------------------------------------------

    private function ensureReady(): void
    {
        if (! BookingStaff::tablesReady()) {
            abort(response()->json(['message' => __('Run the latest database update first.')], 503));
        }
    }

    private function find($id): BookingStaff
    {
        $staff = $this->scopeToWarehouses(BookingStaff::query(), 'warehouse_id', true)->findOrFail((int) $id);

        return $staff;
    }

    /**
     * Validated weekday map: ISO weekday => {start, end, break_start, break_end};
     * a missing / null / disabled day is a day off.
     */
    private function cleanDays(array $input): array
    {
        $out = [];
        $errors = [];
        foreach (BookingStaff::WEEKDAYS as $wd) {
            $d = $input[$wd] ?? $input[(string) $wd] ?? null;
            if (! is_array($d) || (array_key_exists('enabled', $d) && ! filter_var($d['enabled'], FILTER_VALIDATE_BOOLEAN))) {
                continue;
            }
            $t = fn ($k) => is_string($d[$k] ?? null) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $d[$k]) ? $d[$k] : null;
            $start = $t('start');
            $end = $t('end');
            if (! $start || ! $end || $end <= $start) {
                $errors["days.$wd.end"] = [__('Working hours must end after they start.')];
                continue;
            }
            $bs = $t('break_start');
            $be = $t('break_end');
            if (($bs xor $be) || ($bs && ($be <= $bs || $bs < $start || $be > $end))) {
                $errors["days.$wd.break_end"] = [__('The break must fall inside the working hours.')];
                continue;
            }
            $out[$wd] = ['start' => $start, 'end' => $end, 'break_start' => $bs, 'break_end' => $be];
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return $out;
    }

    private function saveDays(BookingStaff $staff, array $days): void
    {
        BookingStaffHour::where('staff_id', $staff->id)->whereNotIn('weekday', array_keys($days) ?: [0])->delete();
        foreach ($days as $wd => $d) {
            BookingStaffHour::updateOrCreate(['staff_id' => $staff->id, 'weekday' => (int) $wd], $d);
        }
    }

    /** ISO weekday => hours from the employee's HRM office shift, or null when there is none. */
    private function shiftDays(Employee $employee): ?array
    {
        $shift = $employee->office_shift_id ? DB::table('office_shifts')->whereNull('deleted_at')->find($employee->office_shift_id) : null;
        if (! $shift) {
            return null;
        }
        $names = [1 => 'monday', 2 => 'tuesday', 3 => 'wednesday', 4 => 'thursday', 5 => 'friday', 6 => 'saturday', 7 => 'sunday'];
        $out = [];
        foreach ($names as $wd => $day) {
            // Stored like "09:00AM" (24-hour time with a suffix): the first five characters are the time.
            $in = substr((string) ($shift->{$day.'_in'} ?? ''), 0, 5);
            $outT = substr((string) ($shift->{$day.'_out'} ?? ''), 0, 5);
            if (AvailabilityService::minutes($in) !== null && AvailabilityService::minutes($outT) !== null && $outT > $in) {
                $out[$wd] = ['start' => $in, 'end' => $outT, 'break_start' => null, 'break_end' => null];
            }
        }

        return $out;
    }

    /** All seven weekdays for the page: enabled flag + times (defaults for days off). */
    private function daysPayload(array $days): array
    {
        $out = [];
        foreach (BookingStaff::WEEKDAYS as $wd) {
            $d = $days[$wd] ?? null;
            $out[$wd] = [
                'enabled' => (bool) $d,
                'start' => $d['start'] ?? '09:00',
                'end' => $d['end'] ?? '17:00',
                'break_start' => $d['break_start'] ?? null,
                'break_end' => $d['break_end'] ?? null,
            ];
        }

        return $out;
    }

    private function presentOne(BookingStaff $s): array
    {
        $s->load(['hours', 'timeOff' => fn ($q) => $q->where('end_date', '>=', now()->toDateString())->orderBy('start_date')]);
        $links = DB::table('bk_service_staff')->where('staff_id', $s->id)->get();
        $upcoming = Booking::where('staff_id', $s->id)->where('booking_date', '>=', now()->toDateString())
            ->whereIn('status', AvailabilityService::BLOCKING)->count();
        $leave = $s->employee_id ? DB::table('leaves')->whereNull('deleted_at')->where('status', 'approved')
            ->where('employee_id', $s->employee_id)->where('end_date', '>=', now()->toDateString())
            ->orderBy('start_date')->get(['employee_id', 'start_date', 'end_date']) : collect();

        return $this->present($s, $links, $upcoming, $leave);
    }

    private function present(BookingStaff $s, $links, int $upcoming, $leave): array
    {
        $days = [];
        foreach ($s->hours as $h) {
            $days[(int) $h->weekday] = ['start' => $h->start, 'end' => $h->end, 'break_start' => $h->break_start, 'break_end' => $h->break_end];
        }

        return [
            'id' => $s->id,
            'name' => $s->name,
            'title' => (string) ($s->title ?? ''),
            'color' => $s->color,
            'photo_url' => $s->photoUrl(),
            'employee_id' => $s->employee_id,
            'user_id' => $s->user_id,
            'warehouse_id' => $s->warehouse_id,
            'bookable_online' => (bool) $s->bookable_online,
            'show_on_website' => (bool) $s->show_on_website,
            'max_per_day' => $s->max_per_day,
            'sort_order' => (int) $s->sort_order,
            'active' => (bool) $s->active,
            'days' => $this->daysPayload($days),
            'time_off' => $s->timeOff->map(fn ($o) => $this->presentOff($o))->values(),
            'hrm_leave' => collect($leave)->map(fn ($l) => ['start_date' => substr((string) $l->start_date, 0, 10), 'end_date' => substr((string) $l->end_date, 0, 10)])->values(),
            'service_ids' => collect($links)->pluck('product_id')->map(fn ($v) => (int) $v)->values(),
            'upcoming_bookings' => $upcoming,
        ];
    }

    private function presentOff(BookingStaffTimeOff $o): array
    {
        return [
            'id' => $o->id,
            'start_date' => $o->start_date?->toDateString(),
            'end_date' => $o->end_date?->toDateString(),
            'from_time' => $o->from_time,
            'to_time' => $o->to_time,
            'reason' => (string) ($o->reason ?? ''),
        ];
    }

    /** Calendar colours for new staff, in turn (all pass 4.5:1 with white text). */
    private function nextColor(): string
    {
        $palette = ['#6d28d9', '#1f5fa6', '#0b6e4f', '#b03a64', '#b45309', '#0f766e', '#4338ca', '#9d174d'];

        return $palette[BookingStaff::withTrashed()->count() % count($palette)];
    }

    /** Replace or remove the photo (multipart photo_file / remove_photo=1). */
    private function syncPhoto(Request $request, BookingStaff $staff): void
    {
        $dir = upload_public_path(BookingStaff::PHOTO_DIR);
        if ($request->hasFile('photo_file')) {
            if (! file_exists($dir)) {
                mkdir($dir, 0755, true);
            }
            $file = $request->file('photo_file');
            $name = time().'_'.Str::random(10).'.'.\App\Services\Rental\RentalUpload::extension($file, 'image');
            $file->move($dir, $name);
            $this->deletePhoto($staff->photo);
            $staff->update(['photo' => $name]);
        } elseif ($request->boolean('remove_photo') && $staff->photo) {
            $this->deletePhoto($staff->photo);
            $staff->update(['photo' => null]);
        }
    }

    private function deletePhoto($file): void
    {
        if (! $file || ! is_string($file) || str_contains($file, '/') || str_contains($file, '\\')) {
            return;
        }
        $path = upload_public_path(BookingStaff::PHOTO_DIR.'/'.$file);
        if (file_exists($path)) {
            @unlink($path);
        }
    }
}
