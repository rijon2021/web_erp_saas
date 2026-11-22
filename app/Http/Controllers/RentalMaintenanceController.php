<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Employee;
use App\Models\PaymentMethod;
use App\Models\RentalItem;
use App\Models\RentalMaintenance;
use App\Models\RentalMaintenanceSchedule;
use App\Models\Setting;
use App\Services\Rental\RentalContractService;
use App\Services\Rental\RentalMaintenanceService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Maintenance jobs (list, create/edit with photos, start, complete, cancel)
 * and preventive schedules. Everything sits behind `rnt_maintenance`.
 */
class RentalMaintenanceController extends Controller
{
    public function __construct(
        private RentalMaintenanceService $maintenance,
        private RentalContractService $contracts,
    ) {
    }

    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'maintenance', RentalItem::class);
        $this->maintenance->runSchedules();

        $perPage = (int) ($request->limit ?? 10);
        $page = max(1, (int) $request->get('page', 1));
        $sortable = ['number', 'priority', 'status', 'scheduled_start', 'cost', 'created_at'];
        $order = in_array($request->SortField, $sortable, true) ? 'rental_maintenance.' . $request->SortField : 'rental_maintenance.created_at';
        $dir = strtolower((string) $request->SortType) === 'asc' ? 'asc' : 'desc';

        $base = RentalMaintenance::query()
            ->join('rental_items', 'rental_items.id', '=', 'rental_maintenance.rental_item_id')
            ->whereNull('rental_maintenance.deleted_at')
            ->whereNull('rental_items.deleted_at')
            ->select('rental_maintenance.*', 'rental_items.name as item_name', 'rental_items.code as item_code', 'rental_items.type as item_type')
            ->when($request->filled('rental_item_id'), fn ($q) => $q->where('rental_maintenance.rental_item_id', $request->rental_item_id))
            ->when($request->filled('priority'), fn ($q) => $q->where('rental_maintenance.priority', $request->priority))
            ->when($request->filled('search'), function ($q) use ($request) {
                $s = $request->search;
                $q->where(fn ($w) => $w->where('rental_maintenance.number', 'LIKE', "%{$s}%")
                    ->orWhere('rental_maintenance.title', 'LIKE', "%{$s}%")
                    ->orWhere('rental_maintenance.vendor', 'LIKE', "%{$s}%")
                    ->orWhere('rental_items.name', 'LIKE', "%{$s}%"));
            });

        $counts = [];
        foreach (['active', 'requests', 'open', 'scheduled', 'in_progress', 'overdue', 'completed', 'cancelled', 'all'] as $stage) {
            $q = clone $base;
            $this->applyStage($q, $stage);
            $counts[$stage] = $q->count();
        }
        $urgent = (clone $base)->whereIn('rental_maintenance.status', RentalMaintenance::OPEN)->where('rental_maintenance.priority', 'urgent')->count();
        $costMonth = (float) (clone $base)->where('rental_maintenance.status', 'completed')
            ->where('rental_maintenance.completed_at', '>=', Carbon::now()->startOfMonth())->sum('rental_maintenance.cost');

        $query = clone $base;
        $this->applyStage($query, (string) $request->get('stage', 'active'));
        $totalRows = (clone $query)->count();
        if ($perPage === -1) {
            $perPage = max(1, $totalRows);
        }
        // Urgent first when looking at work to do.
        $rows = $query->with('employee')
            ->when($request->get('stage', 'active') === 'active' && ! in_array($request->SortField, $sortable, true),
                fn ($q) => $q->orderByRaw("FIELD(rental_maintenance.priority, 'urgent', 'high', 'normal', 'low')"))
            ->orderBy($order, $dir)
            ->offset(($page * $perPage) - $perPage)->limit($perPage)->get();

        return response()->json([
            'totalRows' => $totalRows,
            'jobs' => $rows->map(fn ($m) => $this->present($m))->values(),
            'summary' => ['counts' => $counts, 'urgent' => $urgent, 'cost_month' => round($costMonth, 2)],
        ]);
    }

    public function meta(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'maintenance', RentalItem::class);
        $setting = Setting::whereNull('deleted_at')->first();

        return response()->json([
            'items' => RentalItem::whereNull('deleted_at')->orderBy('name')
                ->get(['id', 'code', 'name', 'type', 'status', 'quantity', 'meter_reading', 'meter_unit', 'condition']),
            'employees' => Employee::orderBy('firstname')->get(['id', 'firstname', 'lastname'])
                ->map(fn ($e) => ['id' => $e->id, 'name' => trim($e->firstname . ' ' . $e->lastname)])->values(),
            'payment_methods' => PaymentMethod::active()->exceptCustomerWallet()->whereNull('deleted_at')->orderBy('name')->get(['id', 'name']),
            'accounts' => Account::whereNull('deleted_at')->orderBy('account_name')->get(['id', 'account_name']),
            'default_payment_method_id' => optional($setting)->default_payment_method_id,
            'default_account_id' => optional($setting)->default_account_id,
        ]);
    }

    public function show(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'maintenance', RentalItem::class);

        $m = RentalMaintenance::with('item', 'employee', 'contract', 'schedule', 'expenses')->whereNull('deleted_at')->findOrFail($id);

        return response()->json(['job' => array_merge($this->present($m), [
            'description' => $m->description,
            'resolution' => $m->resolution,
            'cancel_reason' => $m->cancel_reason,
            'meter_reading' => $m->meter_reading === null ? null : (float) $m->meter_reading,
            'photos' => collect($m->photos ?: [])->map(fn ($f) => ['file' => $f, 'url' => RentalMaintenance::fileUrl($f)])->values(),
            'contract' => $m->contract ? ['id' => $m->contract->id, 'contract_number' => $m->contract->contract_number] : null,
            'schedule' => $m->schedule ? ['id' => $m->schedule->id, 'title' => $m->schedule->title] : null,
            'item_meter' => optional($m->item)->meter_reading === null ? null : (float) $m->item->meter_reading,
            'item_meter_unit' => optional($m->item)->meter_unit,
            'item_condition' => optional($m->item)->condition,
            'expenses' => $m->expenses->whereNull('deleted_at')->map(fn ($e) => [
                'id' => $e->id, 'amount' => (float) $e->amount, 'date' => optional($e->expense_date)->toDateString(), 'category' => $e->category,
            ])->values(),
        ])]);
    }

    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'maintenance', RentalItem::class);

        $data = $this->validated($request);
        $data['photos'] = $this->storePhotos($request, []);
        $job = DB::transaction(fn () => $this->maintenance->create($data));
        $this->contracts->sync([$job->rental_item_id]);

        return response()->json(['success' => true, 'id' => $job->id]);
    }

    /** Multipart POST (photos). Changing status here is not allowed — use the actions. */
    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'maintenance', RentalItem::class);

        $job = RentalMaintenance::whereNull('deleted_at')->findOrFail($id);
        if (in_array($job->status, ['completed', 'cancelled'], true)) {
            return $this->refuse('This job is closed and can no longer be edited.');
        }
        $data = $this->validated($request, $job);
        unset($data['status']);
        if ($job->status === 'in_progress' && (int) $data['rental_item_id'] !== (int) $job->rental_item_id) {
            return $this->refuse('This job is in progress on its item. Complete or cancel it, then open a job for the other item.');
        }
        if (in_array($job->status, ['open', 'scheduled'], true)) {
            $data['status'] = $data['scheduled_start'] ? 'scheduled' : 'open';
        }

        $keep = array_values(array_intersect($this->decodeList($request->keep_photos), $job->photos ?: []));
        foreach (array_diff($job->photos ?: [], $keep) as $gone) {
            $this->deleteFile($gone);
        }
        $data['photos'] = $this->storePhotos($request, $keep);
        $oldItem = $job->rental_item_id;
        $job->update($data);
        $this->contracts->sync(array_unique([$oldItem, $job->rental_item_id]));

        return response()->json(['success' => true, 'id' => $job->id]);
    }

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'maintenance', RentalItem::class);

        $job = RentalMaintenance::whereNull('deleted_at')->findOrFail($id);
        if ($job->status === 'in_progress') {
            return $this->refuse('Complete or cancel the job before deleting it.');
        }
        if ($job->expenses()->whereNull('deleted_at')->exists()) {
            return $this->refuse('This job has expenses. Delete them first.');
        }
        if ($job->status !== 'completed') {
            $this->maintenance->skipCycle($job);
        }
        foreach ($job->photos ?: [] as $photo) {
            $this->deleteFile($photo);
        }
        $job->delete();
        $this->contracts->sync([$job->rental_item_id]);

        return response()->json(['success' => true]);
    }

    public function start(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'maintenance', RentalItem::class);

        $job = RentalMaintenance::whereNull('deleted_at')->findOrFail($id);
        if (! in_array($job->status, ['open', 'scheduled'], true)) {
            return $this->refuse('Only open or scheduled jobs can be started.');
        }
        $this->maintenance->start($job);

        return response()->json(['success' => true]);
    }

    public function complete(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'maintenance', RentalItem::class);

        $job = RentalMaintenance::whereNull('deleted_at')->findOrFail($id);
        if (! in_array($job->status, RentalMaintenance::OPEN, true)) {
            return $this->refuse('This job is already closed.');
        }
        $data = $request->validate([
            'completed_at' => 'nullable|date',
            'resolution' => 'nullable|string|max:5000',
            'cost' => 'nullable|numeric|min:0',
            'meter_reading' => 'nullable|numeric|min:0',
            'condition' => ['nullable', Rule::in(RentalItem::CONDITIONS)],
            'add_expense' => 'nullable|boolean',
            'payment_method_id' => 'nullable|integer|exists:payment_methods,id',
            'account_id' => ['nullable', 'integer', \Illuminate\Validation\Rule::exists('accounts', 'id')->whereNull('deleted_at')],
        ]);
        if (! empty($data['add_expense']) && ! $request->user('api')->can('expenses', RentalItem::class)) {
            return $this->refuse('You are not allowed to record expenses.');
        }
        $this->maintenance->complete($job, $data);

        return response()->json(['success' => true]);
    }

    public function cancel(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'maintenance', RentalItem::class);

        $job = RentalMaintenance::whereNull('deleted_at')->findOrFail($id);
        if (! in_array($job->status, RentalMaintenance::OPEN, true)) {
            return $this->refuse('This job is already closed.');
        }
        $request->validate(['reason' => 'nullable|string|max:2000']);
        $this->maintenance->cancel($job, $request->reason);

        return response()->json(['success' => true]);
    }

    // ------------------------------------------------------------------
    // Preventive schedules
    // ------------------------------------------------------------------

    public function schedules(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'maintenance', RentalItem::class);
        $today = Carbon::today();

        $rows = RentalMaintenanceSchedule::with('item')->whereNull('deleted_at')
            ->when($request->filled('rental_item_id'), fn ($q) => $q->where('rental_item_id', $request->rental_item_id))
            ->orderByRaw('next_due_date IS NULL')->orderBy('next_due_date')->get();

        return response()->json(['schedules' => $rows->map(fn ($s) => [
            'id' => $s->id,
            'rental_item_id' => $s->rental_item_id,
            'item_name' => optional($s->item)->name,
            'item_code' => optional($s->item)->code,
            'item_meter' => optional($s->item)->meter_reading === null ? null : (float) $s->item->meter_reading,
            'meter_unit' => optional($s->item)->meter_unit,
            'title' => $s->title,
            'type' => $s->type,
            'interval_value' => $s->interval_value,
            'interval_unit' => $s->interval_unit,
            'meter_interval' => $s->meter_interval === null ? null : (float) $s->meter_interval,
            'last_done_at' => optional($s->last_done_at)->toDateString(),
            'next_due_date' => optional($s->next_due_date)->toDateString(),
            'next_due_meter' => $s->next_due_meter === null ? null : (float) $s->next_due_meter,
            'days_left' => $s->next_due_date ? (int) $today->diffInDays($s->next_due_date, false) : null,
            'lead_days' => (int) $s->lead_days,
            'duration_hours' => (int) $s->duration_hours,
            'active' => (bool) $s->active,
            'notes' => $s->notes,
        ])->values()]);
    }

    public function storeSchedule(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'maintenance', RentalItem::class);

        $s = RentalMaintenanceSchedule::create(array_merge($this->validatedSchedule($request), ['created_by' => optional($request->user('api'))->id]));
        $this->maintenance->runSchedules();

        return response()->json(['success' => true, 'id' => $s->id]);
    }

    public function updateSchedule(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'maintenance', RentalItem::class);

        $schedule = RentalMaintenanceSchedule::whereNull('deleted_at')->findOrFail($id);
        $schedule->update($this->validatedSchedule($request, $schedule));
        $this->maintenance->runSchedules();

        return response()->json(['success' => true]);
    }

    public function destroySchedule(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'maintenance', RentalItem::class);

        RentalMaintenanceSchedule::whereNull('deleted_at')->findOrFail($id)->delete();

        return response()->json(['success' => true]);
    }

    // ------------------------------------------------------------------

    private function applyStage($query, string $stage): void
    {
        $now = Carbon::now();
        switch ($stage) {
            case 'active':
                $query->whereIn('rental_maintenance.status', RentalMaintenance::OPEN);
                return;
            case 'requests':
                // Problems reported by customers from the client portal, still to handle.
                $query->where('rental_maintenance.source', 'portal')->whereIn('rental_maintenance.status', RentalMaintenance::OPEN);
                return;
            case 'overdue':
                $query->where(fn ($q) => $q
                    ->where(fn ($w) => $w->where('rental_maintenance.status', 'scheduled')->where('rental_maintenance.scheduled_start', '<', $now))
                    ->orWhere(fn ($w) => $w->where('rental_maintenance.status', 'in_progress')->whereNotNull('rental_maintenance.scheduled_end')->where('rental_maintenance.scheduled_end', '<', $now)));
                return;
            case 'all':
                return;
            default:
                if (in_array($stage, RentalMaintenance::STATUSES, true)) {
                    $query->where('rental_maintenance.status', $stage);
                }
        }
    }

    private function present(RentalMaintenance $m): array
    {
        return [
            'id' => $m->id,
            'number' => $m->number,
            'rental_item_id' => $m->rental_item_id,
            'item_name' => $m->item_name ?? optional($m->item)->name,
            'item_code' => $m->item_code ?? optional($m->item)->code,
            'item_type' => $m->item_type ?? optional($m->item)->type,
            'quantity' => (int) $m->quantity,
            'title' => $m->title,
            'type' => $m->type,
            'priority' => $m->priority,
            'status' => $m->status,
            'source' => $m->source,
            'overdue' => $m->isOverdue(),
            'scheduled_start' => optional($m->scheduled_start)->toIso8601String(),
            'scheduled_end' => optional($m->scheduled_end)->toIso8601String(),
            'started_at' => optional($m->started_at)->toIso8601String(),
            'completed_at' => optional($m->completed_at)->toIso8601String(),
            'employee_id' => $m->employee_id,
            'employee_name' => $m->employee ? trim($m->employee->firstname . ' ' . $m->employee->lastname) : null,
            'vendor' => $m->vendor,
            'cost' => (float) $m->cost,
            'photo_count' => count($m->photos ?: []),
            'created_at' => optional($m->created_at)->toIso8601String(),
        ];
    }

    private function validated(Request $request, ?RentalMaintenance $job = null): array
    {
        $data = $request->validate([
            'rental_item_id' => 'required|integer|exists:rental_items,id',
            'quantity' => 'nullable|integer|min:1',
            'title' => 'required|string|max:191',
            'type' => ['required', Rule::in(RentalMaintenance::TYPES)],
            'priority' => ['required', Rule::in(RentalMaintenance::PRIORITIES)],
            'status' => ['nullable', Rule::in(['open', 'scheduled', 'in_progress'])],
            'scheduled_start' => 'nullable|date|required_if:status,scheduled|required_with:scheduled_end',
            'scheduled_end' => 'nullable|date|after:scheduled_start',
            'employee_id' => 'nullable|integer|exists:employees,id',
            'vendor' => 'nullable|string|max:191',
            'cost' => 'nullable|numeric|min:0',
            'description' => 'nullable|string|max:5000',
            'new_photos' => 'nullable|array|max:12',
            'new_photos.*' => 'image|max:8192',
        ], ['scheduled_start.required_if' => 'A scheduled job needs a start date.', 'scheduled_start.required_with' => 'Set a start date before the end date.']);

        $item = RentalItem::find($data['rental_item_id']);

        return [
            'rental_item_id' => (int) $data['rental_item_id'],
            'quantity' => min(max(1, (int) ($data['quantity'] ?? 1)), max(1, (int) optional($item)->quantity)),
            'title' => $data['title'],
            'type' => $data['type'],
            'priority' => $data['priority'],
            'status' => $data['status'] ?? ($job ? $job->status : (! empty($data['scheduled_start']) ? 'scheduled' : 'open')),
            'scheduled_start' => ! empty($data['scheduled_start']) ? Carbon::parse($data['scheduled_start'])->setTimezone(config('app.timezone')) : null,
            'scheduled_end' => ! empty($data['scheduled_end']) ? Carbon::parse($data['scheduled_end'])->setTimezone(config('app.timezone')) : null,
            'employee_id' => $data['employee_id'] ?? null,
            'vendor' => $data['vendor'] ?? null,
            'cost' => (float) ($data['cost'] ?? 0),
            'description' => $data['description'] ?? null,
        ];
    }

    private function validatedSchedule(Request $request, ?RentalMaintenanceSchedule $existing = null): array
    {
        $data = $request->validate([
            'rental_item_id' => 'required|integer|exists:rental_items,id',
            'title' => 'required|string|max:191',
            'type' => ['required', Rule::in(['service', 'inspection', 'cleaning', 'repair', 'other'])],
            'interval_value' => 'nullable|integer|min:1|max:1000|required_without:meter_interval',
            'interval_unit' => ['nullable', Rule::in(['day', 'week', 'month', 'year']), 'required_with:interval_value'],
            'meter_interval' => 'nullable|numeric|min:1',
            'next_due_date' => 'nullable|date',
            'next_due_meter' => 'nullable|numeric|min:0',
            'lead_days' => 'nullable|integer|min:0|max:365',
            'duration_hours' => 'nullable|integer|min:1|max:2160',
            'active' => 'nullable|boolean',
            'notes' => 'nullable|string|max:2000',
        ], ['interval_value.required_without' => 'Set a time interval, a meter interval, or both.']);

        $item = RentalItem::find($data['rental_item_id']);
        $meterInterval = $data['meter_interval'] ?? null;
        $intervalValue = $data['interval_value'] ?? null;

        // Meter target: as sent; else unchanged on edit (unless the interval
        // or item changed); else the current reading + interval.
        $sameMeterRule = $existing && (float) $existing->meter_interval === (float) $meterInterval
            && (int) $existing->rental_item_id === (int) $data['rental_item_id'];
        $nextMeter = $data['next_due_meter'] ?? ($sameMeterRule && $existing->next_due_meter !== null
            ? (float) $existing->next_due_meter
            : ($meterInterval && optional($item)->meter_reading !== null ? round((float) $item->meter_reading + (float) $meterInterval, 2) : null));
        // A time-based schedule without a due date would never fire.
        $nextDate = $data['next_due_date'] ?? null;
        if (! $nextDate && $intervalValue) {
            $nextDate = optional(optional($existing)->next_due_date)->toDateString()
                ?? $this->contracts->addPeriods(Carbon::today(), $data['interval_unit'], (int) $intervalValue)->toDateString();
        }

        return [
            'rental_item_id' => (int) $data['rental_item_id'],
            'title' => $data['title'],
            'type' => $data['type'],
            'interval_value' => $data['interval_value'] ?? null,
            'interval_unit' => isset($data['interval_value']) ? $data['interval_unit'] : null,
            'meter_interval' => $meterInterval,
            'next_due_date' => $nextDate,
            'next_due_meter' => $meterInterval ? $nextMeter : null,
            'lead_days' => (int) ($data['lead_days'] ?? 7),
            'duration_hours' => (int) ($data['duration_hours'] ?? 24),
            'active' => $request->has('active') ? $request->boolean('active') : true,
            'notes' => $data['notes'] ?? null,
        ];
    }

    private function decodeList($value): array
    {
        if (is_array($value)) {
            return array_values(array_filter($value, 'is_string'));
        }
        $decoded = is_string($value) ? json_decode($value, true) : null;

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
    }

    private function storePhotos(Request $request, array $keep): array
    {
        $files = (array) $request->file('new_photos', []);
        if (! $files) {
            return $keep;
        }
        $dir = upload_public_path(RentalMaintenance::FILE_DIR);
        if (! file_exists($dir)) {
            mkdir($dir, 0755, true);
        }
        foreach ($files as $file) {
            if (count($keep) >= 12) {
                break;
            }
            $name = Str::random(40) . '.' . \App\Services\Rental\RentalUpload::extension($file, 'image');
            $file->move($dir, $name);
            $keep[] = $name;
        }

        return $keep;
    }

    private function deleteFile($name): void
    {
        if (is_string($name) && $name !== '' && ! str_contains($name, '/') && ! str_contains($name, '\\')) {
            @unlink(upload_public_path(RentalMaintenance::FILE_DIR . '/' . $name));
        }
    }

    private function refuse(string $message)
    {
        return response()->json(['message' => $message], 422);
    }
}
