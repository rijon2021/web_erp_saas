<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\CrmActivity;
use App\Models\CrmDeal;
use App\Models\CrmLead;
use App\Models\CrmSetting;
use App\Models\User;
use App\Services\Crm\CrmActivityService;
use App\Services\Crm\CrmDealService;
use App\Services\Crm\CrmLeadService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * CRM activities & tasks: list, calendar, "My Day", log / plan, complete
 * (optionally scheduling the next step), reopen, edit, delete.
 */
class CrmActivityController extends Controller
{
    public function __construct(
        private CrmActivityService $service,
        private CrmLeadService $leads,
        private CrmDealService $deals,
    ) {
    }

    public function meta(Request $request)
    {
        $user = $request->user('api');
        $this->authorizeForUser($user, 'view', CrmActivity::class);

        return response()->json([
            'owners' => User::whereNull('deleted_at')->where('statut', 1)->orderBy('firstname')->get(['id', 'firstname', 'lastname', 'username'])
                ->map(fn ($u) => ['id' => $u->id, 'name' => CrmLeadService::userName($u)])->values(),
            'default_reminder_minutes' => CrmSetting::current()->default_reminder_minutes,
            'can' => ['view_all' => $user->can('viewAll', CrmLead::class)],
            'me' => $user->id,
        ]);
    }

    public function index(Request $request)
    {
        $user = $request->user('api');
        $this->authorizeForUser($user, 'view', CrmActivity::class);

        $perPage = (int) ($request->limit ?? 15);
        $page = max(1, (int) $request->get('page', 1));
        $dir = strtolower((string) $request->SortType) === 'desc' ? 'desc' : 'asc';

        $filtered = $this->applyFilters($this->service->visible($user)->select('crm_activities.*'), $request, $user);
        $query = $this->applyStatus(clone $filtered, (string) $request->get('status', ''));

        $totalRows = (clone $query)->count();
        if ($perPage === -1) {
            $perPage = max(1, $totalRows);
        }
        $rows = $query->with('owner', 'lead', 'deal', 'client')
            ->orderByRaw("CASE WHEN crm_activities.status = 'open' THEN 0 ELSE 1 END")
            ->orderByRaw('COALESCE(crm_activities.due_at, crm_activities.done_at, crm_activities.created_at) ' . $dir)
            ->offset(($page * $perPage) - $perPage)->limit($perPage)->get();

        return response()->json([
            'totalRows' => $totalRows,
            'activities' => $rows->map(fn ($a) => $this->service->present($a, $user))->values(),
            'summary' => [
                'open' => $this->applyStatus(clone $filtered, 'open')->count(),
                'overdue' => $this->applyStatus(clone $filtered, 'overdue')->count(),
                'today' => $this->applyStatus(clone $filtered, 'today')->count(),
                'done_week' => (clone $filtered)->where('crm_activities.status', 'done')->where('crm_activities.done_at', '>=', Carbon::now()->startOfWeek())->count(),
            ],
        ]);
    }

    /** Calendar range: open ones on their due date, done ones on the day they were done. */
    public function calendar(Request $request)
    {
        $user = $request->user('api');
        $this->authorizeForUser($user, 'view', CrmActivity::class);
        $request->validate(['start' => 'required|date', 'end' => 'required|date|after_or_equal:start']);
        $start = Carbon::parse($request->start)->startOfDay();
        $end = Carbon::parse($request->end)->endOfDay();

        $rows = $this->applyFilters($this->service->visible($user)->select('crm_activities.*'), $request, $user)
            ->where(fn ($q) => $q->whereBetween('crm_activities.due_at', [$start, $end])->orWhereBetween('crm_activities.done_at', [$start, $end]))
            ->with('owner', 'lead', 'deal', 'client')->orderByRaw('COALESCE(crm_activities.due_at, crm_activities.done_at)')->limit(2000)->get();

        return response()->json(['activities' => $rows->map(fn ($a) => $this->service->present($a, $user))->values()]);
    }

    /** One person's day: overdue, today, the next 7 days, done today, deals with no next step. */
    public function myDay(Request $request)
    {
        $user = $request->user('api');
        $this->authorizeForUser($user, 'view', CrmActivity::class);
        $ownerId = $user->id;
        if ($request->filled('owner') && ctype_digit((string) $request->owner) && $user->can('viewAll', CrmLead::class)) {
            $ownerId = (int) $request->owner;
        }

        $now = Carbon::now();
        $mine = fn () => CrmActivity::with('lead', 'deal', 'client', 'owner')->whereNull('deleted_at')->where('owner_id', $ownerId);
        $present = fn ($rows) => $rows->map(fn ($a) => $this->service->present($a, $user))->values();

        $overdue = $mine()->where('status', 'open')->where('due_at', '<', $now)->orderBy('due_at')->limit(100)->get();
        $today = $mine()->where('status', 'open')->whereBetween('due_at', [$now, $now->copy()->endOfDay()])->orderBy('due_at')->get();
        $upcoming = $mine()->where('status', 'open')->whereBetween('due_at', [$now->copy()->endOfDay(), $now->copy()->addDays(7)->endOfDay()])
            ->orderBy('due_at')->limit(100)->get();
        $doneToday = $mine()->where('status', 'done')->where('done_at', '>=', $now->copy()->startOfDay())->orderByDesc('done_at')->get();
        $undated = $mine()->where('status', 'open')->whereNull('due_at')->orderByDesc('created_at')->limit(50)->get();

        // Open deals of this person with nothing planned.
        $noNext = CrmDeal::with('stage')->whereNull('deleted_at')->where('status', 'open')->where('owner_id', $ownerId)
            ->whereDoesntHave('activities', fn ($q) => $q->where('status', 'open')->whereNull('deleted_at'))
            ->orderByDesc('value')->limit(10)->get()
            ->map(fn ($d) => [
                'id' => $d->id, 'title' => $d->title, 'deal_number' => $d->deal_number, 'value' => (float) $d->value,
                'stage' => $d->stage ? ['name' => $d->stage->name, 'color' => $d->stage->color] : null,
            ])->values();

        return response()->json([
            'owner_id' => $ownerId,
            'overdue' => $present($overdue),
            'today' => $present($today),
            'upcoming' => $present($upcoming),
            'undated' => $present($undated),
            'done_today' => $present($doneToday),
            'deals_without_next_step' => $noNext,
            'counts' => [
                'overdue' => $overdue->count(),
                'today' => $today->count(),
                'upcoming' => $upcoming->count(),
                'done_today' => $doneToday->count(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user('api');
        $this->authorizeForUser($user, 'create', CrmActivity::class);
        $data = $this->validated($request, $user);

        $a = DB::transaction(function () use ($data, $user) {
            $a = CrmActivity::create($data + ['created_by' => $user->id]);
            $this->service->touchRecords($a);

            return $a;
        });

        return response()->json(['success' => true, 'id' => $a->id]);
    }

    public function show(Request $request, $id)
    {
        $user = $request->user('api');
        $this->authorizeForUser($user, 'view', CrmActivity::class);
        $a = $this->find($request, $id, ['owner', 'lead', 'deal', 'client']);

        return response()->json(['activity' => $this->service->present($a, $user)]);
    }

    public function update(Request $request, $id)
    {
        $user = $request->user('api');
        $this->authorizeForUser($user, 'update', CrmActivity::class);
        $a = $this->find($request, $id);
        $this->assertManage($user, $a);
        $data = $this->validated($request, $user, $a);

        DB::transaction(function () use ($a, $data) {
            $dueChanged = ($data['due_at'] ?? null) && (! $a->due_at || ! $a->due_at->equalTo($data['due_at']));
            $reminderChanged = array_key_exists('reminder_minutes', $data) && $data['reminder_minutes'] !== $a->reminder_minutes;
            // A new due time or reminder means the reminder has to go out again.
            $a->update($data + (($dueChanged || $reminderChanged) ? ['reminded_at' => null] : []));
            $this->service->touchRecords($a);
        });

        return response()->json(['success' => true]);
    }

    public function destroy(Request $request, $id)
    {
        $user = $request->user('api');
        $this->authorizeForUser($user, 'delete', CrmActivity::class);
        $a = $this->find($request, $id);
        $this->assertManage($user, $a);
        $a->delete();

        return response()->json(['success' => true]);
    }

    /**
     * Mark done (with an optional call outcome and note), and optionally plan
     * the next step in the same go.
     */
    public function complete(Request $request, $id)
    {
        $user = $request->user('api');
        $this->authorizeForUser($user, 'update', CrmActivity::class);
        $a = $this->find($request, $id);
        $this->assertManage($user, $a);
        $request->validate([
            'outcome' => ['nullable', Rule::in(CrmActivity::OUTCOMES)],
            'note' => 'nullable|string|max:5000',
            'next.type' => ['nullable', Rule::in(CrmActivity::TYPES)],
            'next.subject' => 'nullable|string|max:191',
            'next.due_at' => 'nullable|date',
        ]);
        if ($a->status === 'done') {
            return $this->refuse('This activity is already done.');
        }

        $next = DB::transaction(function () use ($a, $request, $user) {
            $note = trim((string) $request->note);
            $a->update([
                'status' => 'done',
                'done_at' => now(),
                'outcome' => $a->type === 'call' ? ($request->outcome ?: $a->outcome) : $a->outcome,
                'description' => $note !== '' ? trim(($a->description ? $a->description . "\n\n" : '') . $note) : $a->description,
            ]);
            $this->service->touchRecords($a);

            $n = (array) $request->input('next', []);
            if (! empty($n['due_at']) && ! empty($n['subject'])) {
                $next = CrmActivity::create([
                    'type' => $n['type'] ?? 'task',
                    'subject' => mb_substr(trim($n['subject']), 0, 191),
                    'status' => 'open',
                    'priority' => 'normal',
                    'due_at' => Carbon::parse($n['due_at'])->setTimezone(config('app.timezone')),
                    'crm_lead_id' => $a->crm_lead_id,
                    'crm_deal_id' => $a->crm_deal_id,
                    'client_id' => $a->client_id,
                    'owner_id' => $a->owner_id ?: $user->id,
                    'reminder_minutes' => CrmSetting::current()->default_reminder_minutes,
                    'created_by' => $user->id,
                ]);

                return $next->id;
            }

            return null;
        });

        return response()->json(['success' => true, 'next_id' => $next]);
    }

    public function reopen(Request $request, $id)
    {
        $user = $request->user('api');
        $this->authorizeForUser($user, 'update', CrmActivity::class);
        $a = $this->find($request, $id);
        $this->assertManage($user, $a);
        if ($a->status === 'open') {
            return $this->refuse('This activity is already open.');
        }
        if (! $a->due_at) {
            $a->due_at = Carbon::now()->addHour()->startOfHour();
        }
        $a->update(['status' => 'open', 'done_at' => null, 'due_at' => $a->due_at, 'reminded_at' => null]);

        return response()->json(['success' => true]);
    }

    // ------------------------------------------------------------------

    private function find(Request $request, $id, array $with = []): CrmActivity
    {
        $a = $this->service->visible($request->user('api'))->with($with)->find($id);
        abort_unless($a, 404);

        return $a;
    }

    private function assertManage(User $user, CrmActivity $a): void
    {
        if (! $this->service->canManage($user, $a)) {
            abort(response()->json(['message' => 'Only the person it is assigned to (or who created it) can change this activity.'], 403));
        }
    }

    private function applyFilters($query, Request $request, User $user)
    {
        $query
            ->when($request->filled('type'), fn ($q) => $q->where('crm_activities.type', $request->type))
            ->when($request->filled('lead'), fn ($q) => $q->where('crm_activities.crm_lead_id', (int) $request->lead))
            ->when($request->filled('deal'), fn ($q) => $q->where('crm_activities.crm_deal_id', (int) $request->deal))
            ->when($request->filled('client'), fn ($q) => $q->where('crm_activities.client_id', (int) $request->client))
            ->when($request->filled('from'), fn ($q) => $q->whereRaw('DATE(COALESCE(crm_activities.due_at, crm_activities.done_at, crm_activities.created_at)) >= ?', [$request->from]))
            ->when($request->filled('to'), fn ($q) => $q->whereRaw('DATE(COALESCE(crm_activities.due_at, crm_activities.done_at, crm_activities.created_at)) <= ?', [$request->to]))
            ->when($request->filled('search'), fn ($q) => $q->where(fn ($w) => $w->where('crm_activities.subject', 'LIKE', '%' . $request->search . '%')
                ->orWhere('crm_activities.description', 'LIKE', '%' . $request->search . '%')));

        $owner = (string) $request->get('owner', '');
        if ($owner === 'me') {
            $query->where('crm_activities.owner_id', $user->id);
        } elseif ($owner === 'none') {
            $query->whereNull('crm_activities.owner_id');
        } elseif (ctype_digit($owner)) {
            $query->where('crm_activities.owner_id', (int) $owner);
        }

        return $query;
    }

    private function applyStatus($query, string $status)
    {
        $now = Carbon::now();
        switch ($status) {
            case 'open':
                return $query->where('crm_activities.status', 'open');
            case 'done':
                return $query->where('crm_activities.status', 'done');
            case 'overdue':
                return $query->where('crm_activities.status', 'open')->where('crm_activities.due_at', '<', $now);
            case 'today':
                return $query->where('crm_activities.status', 'open')
                    ->whereBetween('crm_activities.due_at', [$now->copy()->startOfDay(), $now->copy()->endOfDay()]);
            case 'week':
                return $query->where('crm_activities.status', 'open')
                    ->whereBetween('crm_activities.due_at', [$now->copy()->startOfDay(), $now->copy()->addDays(7)->endOfDay()]);
        }

        return $query;
    }

    private function validated(Request $request, User $user, ?CrmActivity $existing = null): array
    {
        $request->validate([
            'type' => ['required', Rule::in(CrmActivity::TYPES)],
            'subject' => 'required|string|max:191',
            'description' => 'nullable|string|max:10000',
            'status' => ['required', Rule::in(['open', 'done'])],
            'priority' => ['nullable', Rule::in(CrmActivity::PRIORITIES)],
            'due_at' => 'nullable|date|required_if:status,open',
            'done_at' => 'nullable|date',
            'duration_minutes' => 'nullable|integer|min:1|max:1440',
            'location' => 'nullable|string|max:191',
            'outcome' => ['nullable', Rule::in(CrmActivity::OUTCOMES)],
            'crm_lead_id' => 'nullable|integer',
            'crm_deal_id' => 'nullable|integer',
            'client_id' => ['nullable', 'integer', Rule::exists('clients', 'id')->whereNull('deleted_at')],
            'owner_id' => ['nullable', 'integer', Rule::exists('users', 'id')->whereNull('deleted_at')],
            'reminder_minutes' => 'nullable|integer|min:0|max:10080',
        ], ['due_at.required_if' => 'A planned activity needs a due date.']);

        $tz = config('app.timezone');
        $leadId = $request->crm_lead_id ? (int) $request->crm_lead_id : null;
        $dealId = $request->crm_deal_id ? (int) $request->crm_deal_id : null;
        if ($leadId && (! $existing || (int) $existing->crm_lead_id !== $leadId)) {
            $lead = CrmLead::whereNull('deleted_at')->find($leadId);
            if (! $lead || ! $this->leads->canSee($user, $lead)) {
                abort(response()->json(['message' => 'This lead is not available.'], 422));
            }
        }
        if ($dealId && (! $existing || (int) $existing->crm_deal_id !== $dealId)) {
            $deal = CrmDeal::whereNull('deleted_at')->find($dealId);
            if (! $deal || ! $this->deals->canSee($user, $deal)) {
                abort(response()->json(['message' => 'This deal is not available.'], 422));
            }
        }

        $owner = $request->owner_id ? (int) $request->owner_id : ($existing ? $existing->owner_id : $user->id);
        if ($owner && (int) $owner !== (int) $user->id && ! $user->can('viewAll', CrmLead::class)
            && (! $existing || (int) $existing->owner_id !== (int) $owner)) {
            abort(response()->json(['message' => 'You can only assign activities to yourself.'], 422));
        }

        $status = $request->status;
        $data = $this->service->fillLinks([
            'type' => $request->type,
            'subject' => trim($request->subject),
            'description' => $request->description,
            'status' => $status,
            'priority' => $request->priority ?: 'normal',
            'due_at' => $request->due_at ? Carbon::parse($request->due_at)->setTimezone($tz) : null,
            'done_at' => $status === 'done'
                ? ($request->done_at ? Carbon::parse($request->done_at)->setTimezone($tz) : ($existing && $existing->done_at ? $existing->done_at : Carbon::now()))
                : null,
            'duration_minutes' => $request->duration_minutes ?: null,
            'location' => $request->location,
            'outcome' => $request->type === 'call' ? ($request->outcome ?: null) : null,
            'crm_lead_id' => $leadId,
            'crm_deal_id' => $dealId,
            'client_id' => $request->client_id ? (int) $request->client_id : null,
            'owner_id' => $owner,
        ]);
        if ($data['done_at'] && $data['done_at']->gt(Carbon::now()->addMinutes(5))) {
            abort(response()->json(['message' => 'A done activity cannot be in the future. Plan it instead.'], 422));
        }
        // Reminders only make sense for planned items.
        if ($status === 'open') {
            $data['reminder_minutes'] = $request->has('reminder_minutes')
                ? ($request->reminder_minutes === null || $request->reminder_minutes === '' ? null : (int) $request->reminder_minutes)
                : ($existing ? $existing->reminder_minutes : CrmSetting::current()->default_reminder_minutes);
        } else {
            $data['reminder_minutes'] = null;
        }

        return $data;
    }

    private function refuse(string $message)
    {
        return response()->json(['message' => $message], 422);
    }
}
