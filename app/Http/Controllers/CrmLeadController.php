<?php

namespace App\Http\Controllers;

use App\Models\CrmLead;
use App\Models\CrmLeadSource;
use App\Models\CrmLeadStatus;
use App\Models\CrmLostReason;
use App\Models\CrmSetting;
use App\Models\CrmTag;
use App\Models\User;
use App\Services\Crm\CrmLeadService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * CRM leads: list (filters, status counts), create / edit, detail with
 * history, status changes (lost with a reason, reopen), bulk actions,
 * duplicate check and CSV export. Import lives in CrmLeadImportController.
 *
 * Visibility: without crm_view_all a user sees their own leads plus the
 * unassigned pool (CrmLeadService::visible()).
 */
class CrmLeadController extends Controller
{
    private const SORTABLE = [
        'lead_number' => 'crm_leads.lead_number',
        'name' => 'crm_leads.name',
        'estimated_value' => 'crm_leads.estimated_value',
        'created_at' => 'crm_leads.created_at',
        'last_activity_at' => 'crm_leads.last_activity_at',
        'rating' => 'crm_leads.rating',
        'score' => 'crm_leads.score',
    ];

    public function __construct(private CrmLeadService $service)
    {
    }

    /** Lookups for filters and the form. */
    public function meta(Request $request)
    {
        $user = $request->user('api');
        $this->authorizeForUser($user, 'view', CrmLead::class);

        return response()->json([
            'sources' => CrmLeadSource::orderBy('sort_order')->orderBy('name')->get(['id', 'name', 'color', 'is_active']),
            'statuses' => CrmLeadStatus::orderBy('sort_order')->get(['id', 'name', 'color', 'kind', 'is_default']),
            'lost_reasons' => CrmLostReason::orderBy('sort_order')->orderBy('name')->get(['id', 'name']),
            'tags' => CrmTag::orderBy('name')->get(['id', 'name', 'color']),
            'owners' => $this->owners(),
            'default_status_id' => CrmLeadStatus::defaultId(),
            'settings' => CrmSetting::current()->only(['default_owner', 'duplicate_check', 'stale_lead_days', 'scoring_enabled']),
            'web_forms' => \App\Models\CrmWebForm::orderBy('name')->get(['id', 'name']),
            'can' => [
                'view_all' => $user->can('viewAll', CrmLead::class),
                'import_export' => $user->can('importExport', CrmLead::class),
            ],
            'me' => $user->id,
        ]);
    }

    public function index(Request $request)
    {
        $user = $request->user('api');
        $this->authorizeForUser($user, 'view', CrmLead::class);

        $perPage = (int) ($request->limit ?? 10);
        $page = max(1, (int) $request->get('page', 1));
        $order = self::SORTABLE[$request->SortField] ?? 'crm_leads.created_at';
        $dir = strtolower((string) $request->SortType) === 'asc' ? 'asc' : 'desc';

        $base = $this->service->visible($user)->select('crm_leads.*');
        $filtered = $this->applyFilters(clone $base, $request, $user, true);
        $query = $this->applyStatusFilter(clone $filtered, $request);

        $totalRows = (clone $query)->count();
        if ($perPage === -1) {
            $perPage = max(1, $totalRows);
        }
        $rows = $query->with('source', 'status', 'owner', 'tags', 'nextActivity')
            ->orderBy($order, $dir)->orderBy('crm_leads.id', 'desc')
            ->offset(($page * $perPage) - $perPage)->limit($perPage)->get();

        // Status chip counts follow every filter except the status itself.
        $byStatus = (clone $filtered)->reorder()->select('crm_leads.crm_lead_status_id', DB::raw('COUNT(*) as n'))
            ->groupBy('crm_leads.crm_lead_status_id')->pluck('n', 'crm_lead_status_id');
        $stale = Carbon::now()->subDays(max(1, (int) CrmSetting::current()->stale_lead_days));
        $openIds = CrmLeadStatus::where('kind', 'open')->pluck('id');

        return response()->json([
            'totalRows' => $totalRows,
            'leads' => $rows->map(fn ($l) => $this->present($l))->values(),
            'summary' => [
                'all' => (int) $byStatus->sum(),
                'by_status' => $byStatus,
                'open' => (int) $byStatus->only($openIds->all())->sum(),
                'unassigned' => (clone $filtered)->whereNull('crm_leads.owner_id')->whereIn('crm_leads.crm_lead_status_id', $openIds)->count(),
                'hot' => (clone $filtered)->where('crm_leads.rating', 'hot')->whereIn('crm_leads.crm_lead_status_id', $openIds)->count(),
                'stale' => (clone $filtered)->whereIn('crm_leads.crm_lead_status_id', $openIds)
                    ->where(fn ($q) => $q->where('crm_leads.last_activity_at', '<', $stale)->orWhereNull('crm_leads.last_activity_at'))->count(),
                'open_value' => round((float) (clone $filtered)->whereIn('crm_leads.crm_lead_status_id', $openIds)->sum('crm_leads.estimated_value'), 2),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user('api');
        $this->authorizeForUser($user, 'create', CrmLead::class);
        $data = $this->validated($request);

        $rule = null;
        if (! array_key_exists('owner_id', $data) || ! $data['owner_id']) {
            $mode = CrmSetting::current()->default_owner;
            $data['owner_id'] = $mode === 'creator' ? $user->id : null;
            if ($mode === 'rules') {
                [$data['owner_id'], $rule] = app(\App\Services\Crm\CrmAssignmentService::class)->pick(new CrmLead($data), 'manual');
            }
        }
        // Without "view all" a rep can only give a lead to themselves or the pool (assignment rules excepted).
        if (! $rule && ! $user->can('viewAll', CrmLead::class) && $data['owner_id'] && (int) $data['owner_id'] !== (int) $user->id) {
            $data['owner_id'] = $user->id;
        }

        $lead = DB::transaction(function () use ($data, $request, $user, $rule) {
            $lead = CrmLead::create(array_merge($data, [
                'lead_number' => $this->service->nextNumber(),
                'crm_lead_status_id' => $data['crm_lead_status_id'] ?? CrmLeadStatus::defaultId(),
                'last_activity_at' => now(),
                'created_by' => $user->id,
            ]));
            $this->service->syncTags($lead, (array) $request->input('tags', []));
            $this->service->log($lead, 'created', null, $rule ? ['rule' => $rule->name] : []);

            return $lead;
        });

        return response()->json(['success' => true, 'id' => $lead->id] + ($rule ? [
            'assigned_to' => CrmLeadService::userName(User::find($lead->owner_id)),
            'can_view' => $this->service->canSee($user, $lead),
        ] : []));
    }

    public function show(Request $request, $id)
    {
        $user = $request->user('api');
        $this->authorizeForUser($user, 'view', CrmLead::class);
        $lead = $this->find($request, $id, ['source', 'status', 'owner', 'creator', 'tags', 'lostReason', 'client', 'nextActivity',
            'events' => fn ($q) => $q->orderBy('created_at', 'desc')->orderBy('id', 'desc')->limit(200), 'events.user']);

        $dup = CrmSetting::current()->duplicate_check && ! $lead->client_id
            ? $this->service->duplicates($lead->email, $lead->phone, $lead->id)
            : ['leads' => [], 'clients' => []];

        return response()->json(['lead' => array_merge($this->present($lead), [
            'first_name' => $lead->first_name,
            'last_name' => $lead->last_name,
            'website' => $lead->website,
            'industry' => $lead->industry,
            'address' => $lead->address,
            'description' => $lead->description,
            'lost_reason' => optional($lead->lostReason)->name,
            'crm_lost_reason_id' => $lead->crm_lost_reason_id,
            'lost_note' => $lead->lost_note,
            'lost_at' => optional($lead->lost_at)->toIso8601String(),
            'converted_at' => optional($lead->converted_at)->toIso8601String(),
            'client' => $lead->client ? ['id' => $lead->client->id, 'name' => $lead->client->name] : null,
            'created_by_name' => CrmLeadService::userName($lead->creator),
            'marketing_opt_out' => (bool) $lead->marketing_opt_out,
            'marketing_opt_out_at' => optional($lead->marketing_opt_out_at)->toIso8601String(),
            'web_form' => $lead->crm_web_form_id && $lead->webForm ? ['id' => $lead->webForm->id, 'name' => $lead->webForm->name] : null,
            'score_breakdown' => CrmSetting::current()->scoring_enabled ? app(\App\Services\Crm\CrmScoringService::class)->compute($lead)[1] : null,
            'updated_at' => optional($lead->updated_at)->toIso8601String(),
            'events' => $lead->events->map(fn ($e) => [
                'id' => $e->id,
                'type' => $e->type,
                'note' => $e->note,
                'meta' => $e->meta,
                'user_name' => CrmLeadService::userName($e->user),
                'created_at' => optional($e->created_at)->toIso8601String(),
            ])->values(),
            'duplicates' => $dup,
            'activities' => $request->user('api')->can('view', \App\Models\CrmActivity::class)
                ? app(\App\Services\Crm\CrmActivityService::class)->forLead($lead)
                    ->map(fn ($a) => app(\App\Services\Crm\CrmActivityService::class)->present($a, $request->user('api')))->values()
                : null,
            'deals' => $request->user('api')->can('view', \App\Models\CrmDeal::class)
                ? \App\Models\CrmDeal::with('stage')->whereNull('deleted_at')->where('crm_lead_id', $lead->id)->orderByDesc('created_at')->get()
                    ->map(fn ($d) => [
                        'id' => $d->id, 'deal_number' => $d->deal_number, 'title' => $d->title, 'status' => $d->status,
                        'value' => (float) $d->value, 'expected_close_date' => optional($d->expected_close_date)->toDateString(),
                        'stage' => $d->stage ? ['name' => $d->stage->name, 'color' => $d->stage->color] : null,
                    ])->values()
                : null,
        ])]);
    }

    public function update(Request $request, $id)
    {
        $user = $request->user('api');
        $this->authorizeForUser($user, 'update', CrmLead::class);
        $lead = $this->find($request, $id, ['tags']);
        if ($lead->client_id) {
            return $this->refuse('This lead was converted to a customer; edit the customer instead.');
        }
        $data = $this->validated($request, $lead);
        if (! $user->can('viewAll', CrmLead::class) && array_key_exists('owner_id', $data)
            && $data['owner_id'] && (int) $data['owner_id'] !== (int) $user->id) {
            return $this->refuse('You can only assign leads to yourself.');
        }

        DB::transaction(function () use ($lead, $data, $request) {
            $before = $lead->only(['crm_lead_status_id', 'owner_id']);
            $lead->update($data);
            if ($request->has('tags')) {
                $this->service->syncTags($lead, (array) $request->input('tags', []));
            }
            $this->service->log($lead, 'updated');
            $this->logChanges($lead, $before);
        });

        return response()->json(['success' => true, 'id' => $lead->id]);
    }

    /** Opt a lead out of (or back into) marketing campaigns. */
    public function marketing(Request $request, $id)
    {
        $user = $request->user('api');
        $this->authorizeForUser($user, 'update', CrmLead::class);
        $lead = $this->find($request, $id);
        $request->validate(['opt_out' => 'required|boolean']);
        $optOut = $request->boolean('opt_out');
        if ((bool) $lead->marketing_opt_out !== $optOut) {
            $lead->forceFill(['marketing_opt_out' => $optOut, 'marketing_opt_out_at' => $optOut ? now() : null])->save();
            $this->service->log($lead, $optOut ? 'unsubscribed' : 'resubscribed');
        }

        return response()->json(['success' => true, 'marketing_opt_out' => $optOut]);
    }

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', CrmLead::class);
        $lead = $this->find($request, $id);
        $lead->delete();

        return response()->json(['success' => true]);
    }

    public function deleteBySelection(Request $request)
    {
        $user = $request->user('api');
        $this->authorizeForUser($user, 'delete', CrmLead::class);
        $ids = array_map('intval', (array) $request->selectedIds);
        $count = $this->service->visible($user)->whereIn('crm_leads.id', $ids)->update(['deleted_at' => now()]);

        return response()->json(['success' => true, 'count' => $count]);
    }

    /** Move a lead to another open status, or back from lost. */
    public function changeStatus(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', CrmLead::class);
        $lead = $this->find($request, $id);
        $request->validate(['crm_lead_status_id' => 'required|integer']);
        $status = CrmLeadStatus::find($request->crm_lead_status_id);
        if (! $status) {
            return $this->refuse('This status no longer exists.');
        }
        if ($status->kind === 'converted') {
            return $this->refuse('A lead becomes "converted" by converting it to a customer.');
        }
        if ($status->kind === 'lost') {
            return $this->markLost($request, $id);
        }
        if ($lead->client_id) {
            return $this->refuse('This lead was converted to a customer.');
        }

        $before = $lead->only(['crm_lead_status_id', 'owner_id']);
        DB::transaction(function () use ($lead, $status, $before) {
            $lead->update([
                'crm_lead_status_id' => $status->id,
                'crm_lost_reason_id' => null, 'lost_note' => null, 'lost_at' => null,
                'last_activity_at' => now(),
            ]);
            $this->logChanges($lead, $before);
        });

        return response()->json(['success' => true]);
    }

    public function markLost(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', CrmLead::class);
        $lead = $this->find($request, $id);
        if ($lead->client_id) {
            return $this->refuse('This lead was converted to a customer.');
        }
        $request->validate([
            'crm_lost_reason_id' => 'required|integer|exists:crm_lost_reasons,id',
            'lost_note' => 'nullable|string|max:1000',
            'crm_lead_status_id' => 'nullable|integer',
        ], ['crm_lost_reason_id.required' => 'Choose why the lead was lost.']);

        $status = $request->crm_lead_status_id
            ? CrmLeadStatus::where('kind', 'lost')->find($request->crm_lead_status_id)
            : null;
        $status = $status ?? CrmLeadStatus::firstOfKind('lost');
        if (! $status) {
            return $this->refuse('Add a status of the "Lost" kind in CRM Settings first.');
        }

        $before = $lead->only(['crm_lead_status_id', 'owner_id']);
        DB::transaction(function () use ($lead, $status, $request, $before) {
            $lead->update([
                'crm_lead_status_id' => $status->id,
                'crm_lost_reason_id' => (int) $request->crm_lost_reason_id,
                'lost_note' => $request->lost_note,
                'lost_at' => now(),
            ]);
            $this->service->log($lead, 'lost', $request->lost_note, [
                'reason' => optional(CrmLostReason::withTrashed()->find($request->crm_lost_reason_id))->name,
            ]);
            $this->logChanges($lead, $before, false);
        });

        return response()->json(['success' => true]);
    }

    /** Back to work: the default open status, lost details cleared. */
    public function reopen(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', CrmLead::class);
        $lead = $this->find($request, $id, ['status']);
        if ($lead->client_id || $lead->statusKind() !== 'lost') {
            return $this->refuse('Only lost leads can be reopened.');
        }
        $lead->update([
            'crm_lead_status_id' => CrmLeadStatus::defaultId(),
            'crm_lost_reason_id' => null, 'lost_note' => null, 'lost_at' => null,
            'last_activity_at' => now(),
        ]);
        $this->service->log($lead, 'reopened');

        return response()->json(['success' => true]);
    }

    /** Assign owner, change status, add / remove a tag on many leads at once. */
    public function bulk(Request $request)
    {
        $user = $request->user('api');
        $this->authorizeForUser($user, 'update', CrmLead::class);
        $request->validate([
            'ids' => 'required|array|min:1|max:1000',
            'ids.*' => 'integer',
            'action' => 'required|in:assign,status,tag,untag',
            'owner_id' => 'nullable|integer',
            'crm_lead_status_id' => 'required_if:action,status|nullable|integer',
            'tag' => 'required_if:action,tag,untag|nullable',
        ]);

        $leads = $this->service->visible($user)->whereIn('crm_leads.id', $request->ids)->whereNull('crm_leads.client_id')->get();
        $done = 0;

        switch ($request->action) {
            case 'assign':
                $owner = $request->owner_id ? (int) $request->owner_id : null;
                if ($owner && ! $user->can('viewAll', CrmLead::class) && $owner !== (int) $user->id) {
                    return $this->refuse('You can only assign leads to yourself.');
                }
                if ($owner && ! User::whereNull('deleted_at')->whereKey($owner)->exists()) {
                    return $this->refuse('This user no longer exists.');
                }
                DB::transaction(function () use ($leads, $owner, &$done) {
                    foreach ($leads as $lead) {
                        if ((int) $lead->owner_id === (int) $owner) {
                            continue;
                        }
                        $before = $lead->only(['crm_lead_status_id', 'owner_id']);
                        $lead->update(['owner_id' => $owner]);
                        $this->logChanges($lead, $before);
                        $done++;
                    }
                });
                break;

            case 'status':
                $status = CrmLeadStatus::find($request->crm_lead_status_id);
                if (! $status || $status->kind !== 'open') {
                    return $this->refuse('Bulk changes can only move leads to an open status. Mark leads lost one by one, with a reason.');
                }
                DB::transaction(function () use ($leads, $status, &$done) {
                    foreach ($leads as $lead) {
                        if ((int) $lead->crm_lead_status_id === (int) $status->id) {
                            continue;
                        }
                        $before = $lead->only(['crm_lead_status_id', 'owner_id']);
                        $lead->update(['crm_lead_status_id' => $status->id, 'crm_lost_reason_id' => null, 'lost_note' => null, 'lost_at' => null]);
                        $this->logChanges($lead, $before);
                        $done++;
                    }
                });
                break;

            default:
                $tagIds = $this->service->tagIds([$request->tag]);
                if (! $tagIds) {
                    return $this->refuse('Choose a tag.');
                }
                foreach ($leads as $lead) {
                    $request->action === 'tag' ? $lead->tags()->syncWithoutDetaching($tagIds) : $lead->tags()->detach($tagIds);
                    $done++;
                }
        }

        return response()->json(['success' => true, 'count' => $done]);
    }

    /** Live duplicate check for the form (email / phone). */
    public function duplicates(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', CrmLead::class);
        if (! CrmSetting::current()->duplicate_check) {
            return response()->json(['leads' => [], 'clients' => []]);
        }

        return response()->json($this->service->duplicates(
            $request->get('email'), $request->get('phone'), $request->filled('except') ? (int) $request->except : null
        ));
    }

    /** CSV of the leads the current filters show (Excel-friendly UTF-8). */
    public function export(Request $request)
    {
        $user = $request->user('api');
        $this->authorizeForUser($user, 'importExport', CrmLead::class);

        $query = $this->applyStatusFilter(
            $this->applyFilters($this->service->visible($user)->select('crm_leads.*'), $request, $user, true), $request
        )->with('source', 'status', 'owner', 'tags')->orderBy('crm_leads.created_at', 'desc')->limit(20000);

        $headers = ['Number', 'Type', 'Name', 'First name', 'Last name', 'Company', 'Job title', 'Email', 'Phone', 'Website',
            'Industry', 'Address', 'City', 'Country', 'Source', 'Status', 'Owner', 'Rating', 'Estimated value', 'Tags',
            'Description', 'Created'];

        return response()->streamDownload(function () use ($query, $headers) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $headers);
            $query->chunk(500, function ($leads) use ($out) {
                foreach ($leads as $l) {
                    fputcsv($out, array_map([$this, 'csvSafe'], [
                        $l->lead_number, $l->type, $l->name, $l->first_name, $l->last_name, $l->company_name, $l->job_title,
                        $l->email, $l->phone, $l->website, $l->industry, $l->address, $l->city, $l->country,
                        optional($l->source)->name, optional($l->status)->name, CrmLeadService::userName($l->owner),
                        $l->rating, (float) $l->estimated_value, $l->tags->pluck('name')->implode(', '),
                        $l->description, optional($l->created_at)->toDateTimeString(),
                    ]));
                }
            });
            fclose($out);
        }, 'leads_' . now()->format('Y-m-d') . '.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** A cell starting with = + - @ would run as a formula in Excel. */
    public function csvSafe($value)
    {
        if (is_string($value) && $value !== '' && in_array($value[0], ['=', '+', '-', '@'], true)) {
            return "'" . $value;
        }

        return $value;
    }

    private function find(Request $request, $id, array $with = []): CrmLead
    {
        $lead = CrmLead::with($with)->whereNull('deleted_at')->findOrFail($id);
        if (! $this->service->canSee($request->user('api'), $lead)) {
            abort(403, 'This lead belongs to another sales rep.');
        }

        return $lead;
    }

    private function applyFilters($query, Request $request, User $user, bool $withSearch)
    {
        $query
            ->when($request->filled('source'), fn ($q) => $q->where('crm_leads.crm_lead_source_id', (int) $request->source))
            ->when($request->filled('rating'), fn ($q) => $q->where('crm_leads.rating', $request->rating))
            ->when($request->filled('captured_via'), fn ($q) => $request->captured_via === 'manual'
                ? $q->whereNull('crm_leads.captured_via')
                : $q->where('crm_leads.captured_via', $request->captured_via))
            ->when($request->filled('web_form'), fn ($q) => $q->where('crm_leads.crm_web_form_id', (int) $request->web_form))
            ->when($request->filled('tag'), fn ($q) => $q->whereExists(fn ($w) => $w->from('crm_taggables')
                ->whereColumn('crm_taggables.taggable_id', 'crm_leads.id')->where('crm_taggables.taggable_type', 'lead')
                ->where('crm_taggables.crm_tag_id', (int) $request->tag)))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('crm_leads.created_at', '>=', $request->from))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('crm_leads.created_at', '<=', $request->to));

        $owner = (string) $request->get('owner', '');
        if ($owner === 'me') {
            $query->where('crm_leads.owner_id', $user->id);
        } elseif ($owner === 'none') {
            $query->whereNull('crm_leads.owner_id');
        } elseif (ctype_digit($owner)) {
            $query->where('crm_leads.owner_id', (int) $owner);
        }

        if ($request->get('quick') === 'stale') {
            $stale = Carbon::now()->subDays(max(1, (int) CrmSetting::current()->stale_lead_days));
            $query->whereIn('crm_leads.crm_lead_status_id', CrmLeadStatus::where('kind', 'open')->pluck('id'))
                ->where(fn ($q) => $q->where('crm_leads.last_activity_at', '<', $stale)->orWhereNull('crm_leads.last_activity_at'));
        } elseif ($request->get('quick') === 'hot') {
            $query->where('crm_leads.rating', 'hot')->whereIn('crm_leads.crm_lead_status_id', CrmLeadStatus::where('kind', 'open')->pluck('id'));
        }

        if ($withSearch && $request->filled('search')) {
            $s = trim($request->search);
            $digits = CrmLead::normalizePhone($s);
            $query->where(function ($q) use ($s, $digits) {
                $q->where('crm_leads.name', 'LIKE', "%{$s}%")
                    ->orWhere('crm_leads.company_name', 'LIKE', "%{$s}%")
                    ->orWhere('crm_leads.email', 'LIKE', "%{$s}%")
                    ->orWhere('crm_leads.phone', 'LIKE', "%{$s}%")
                    ->orWhere('crm_leads.lead_number', 'LIKE', "%{$s}%")
                    ->orWhere('crm_leads.city', 'LIKE', "%{$s}%");
                if ($digits) {
                    $q->orWhere('crm_leads.phone_normalized', $digits);
                }
            });
        }

        return $query;
    }

    /** status = a status id, or a kind: open | converted | lost. */
    private function applyStatusFilter($query, Request $request)
    {
        $status = (string) $request->get('status', '');
        if (in_array($status, CrmLeadStatus::KINDS, true)) {
            $query->whereIn('crm_leads.crm_lead_status_id', CrmLeadStatus::withTrashed()->where('kind', $status)->pluck('id'));
        } elseif (ctype_digit($status)) {
            $query->where('crm_leads.crm_lead_status_id', (int) $status);
        }

        return $query;
    }

    private function validated(Request $request, ?CrmLead $lead = null): array
    {
        $request->validate([
            'type' => ['required', Rule::in(CrmLead::TYPES)],
            'first_name' => 'nullable|string|max:100',
            'last_name' => 'nullable|string|max:100',
            'company_name' => 'nullable|string|max:191|required_if:type,company',
            'job_title' => 'nullable|string|max:120',
            'email' => 'nullable|email|max:191',
            'phone' => 'nullable|string|max:50',
            'website' => 'nullable|string|max:191',
            'industry' => 'nullable|string|max:120',
            'address' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:120',
            'country' => 'nullable|string|max:120',
            'crm_lead_source_id' => 'nullable|integer|exists:crm_lead_sources,id',
            'crm_lead_status_id' => 'nullable|integer|exists:crm_lead_statuses,id',
            'owner_id' => ['nullable', 'integer', Rule::exists('users', 'id')->whereNull('deleted_at')],
            'rating' => ['nullable', Rule::in(CrmLead::RATINGS)],
            'estimated_value' => 'nullable|numeric|min:0|max:999999999999',
            'description' => 'nullable|string|max:10000',
            'tags' => 'nullable|array|max:30',
        ], [
            'company_name.required_if' => 'A company lead needs the company name.',
        ]);

        // Every field present (null when not sent), so an edit can clear one.
        $d = [];
        foreach (['type', 'first_name', 'last_name', 'company_name', 'job_title', 'email', 'phone', 'website',
            'industry', 'address', 'city', 'country'] as $field) {
            $value = $request->input($field);
            $d[$field] = is_string($value) ? (trim($value) === '' ? null : trim($value)) : $value;
        }
        $name = CrmLead::displayName($d);
        if ($name === '') {
            abort(response()->json([
                'message' => 'Give the lead a name (first or last name, or a company).',
                'errors' => ['first_name' => ['Give the lead a name.']],
            ], 422));
        }

        if ($request->filled('crm_lead_status_id')) {
            $status = CrmLeadStatus::find($request->crm_lead_status_id);
            $kept = $lead && (int) $lead->crm_lead_status_id === (int) $request->crm_lead_status_id;
            if ($status && $status->kind !== 'open' && ! $kept) {
                abort(response()->json([
                    'message' => $status->kind === 'lost'
                        ? 'Use "Mark as lost" to drop a lead, so the reason is recorded.'
                        : 'A lead becomes "converted" by converting it to a customer.',
                    'errors' => ['crm_lead_status_id' => ['Choose an open status.']],
                ], 422));
            }
        }

        $out = array_merge($d, [
            'name' => mb_substr($name, 0, 191),
            'email' => $d['email'] ? mb_strtolower(trim($d['email'])) : null,
            'phone_normalized' => CrmLead::normalizePhone($d['phone'] ?? null),
            'crm_lead_source_id' => $request->crm_lead_source_id ?: null,
            'rating' => $request->rating ?: 'warm',
            'estimated_value' => round((float) $request->estimated_value, 2),
            'description' => $request->description,
        ]);
        if ($request->filled('crm_lead_status_id')) {
            $out['crm_lead_status_id'] = (int) $request->crm_lead_status_id;
        }
        if ($request->has('owner_id')) {
            $out['owner_id'] = $request->owner_id ?: null;
        }

        return $out;
    }

    /** History lines for status / owner changes since $before. */
    private function logChanges(CrmLead $lead, array $before, bool $status = true): void
    {
        if ($status && (int) $before['crm_lead_status_id'] !== (int) $lead->crm_lead_status_id) {
            $this->service->log($lead, 'status_changed', null, [
                'from' => optional(CrmLeadStatus::withTrashed()->find($before['crm_lead_status_id']))->name,
                'to' => optional(CrmLeadStatus::withTrashed()->find($lead->crm_lead_status_id))->name,
            ]);
        }
        if ((int) $before['owner_id'] !== (int) $lead->owner_id) {
            $this->service->log($lead, 'owner_changed', null, [
                'from' => CrmLeadService::userName(User::find($before['owner_id'])),
                'to' => CrmLeadService::userName(User::find($lead->owner_id)),
            ]);
        }
    }

    private function owners()
    {
        return User::whereNull('deleted_at')->where('statut', 1)->orderBy('firstname')->get(['id', 'firstname', 'lastname', 'username', 'avatar'])
            ->map(fn ($u) => ['id' => $u->id, 'name' => CrmLeadService::userName($u)])->values();
    }

    private function present(CrmLead $l): array
    {
        $staleDays = max(1, (int) CrmSetting::current()->stale_lead_days);
        $kind = $l->statusKind();

        return [
            'id' => $l->id,
            'lead_number' => $l->lead_number,
            'type' => $l->type,
            'name' => $l->name,
            'company_name' => $l->company_name,
            'job_title' => $l->job_title,
            'email' => $l->email,
            'phone' => $l->phone,
            'city' => $l->city,
            'country' => $l->country,
            'rating' => $l->rating,
            'estimated_value' => (float) $l->estimated_value,
            'score' => (int) $l->score,
            'captured_via' => $l->captured_via,
            'source' => $l->source ? ['id' => $l->source->id, 'name' => $l->source->name, 'color' => $l->source->color] : null,
            'status' => $l->status ? ['id' => $l->status->id, 'name' => $l->status->name, 'color' => $l->status->color, 'kind' => $l->status->kind] : null,
            'owner' => $l->owner ? ['id' => $l->owner->id, 'name' => CrmLeadService::userName($l->owner)] : null,
            'tags' => $l->relationLoaded('tags') ? $l->tags->map(fn ($t) => ['id' => $t->id, 'name' => $t->name, 'color' => $t->color])->values() : [],
            'next_activity' => $l->relationLoaded('nextActivity') ? \App\Services\Crm\CrmActivityService::nextStep($l->nextActivity) : null,
            'client_id' => $l->client_id,
            'is_stale' => $kind === 'open' && (! $l->last_activity_at || $l->last_activity_at->lt(Carbon::now()->subDays($staleDays))),
            'last_activity_at' => optional($l->last_activity_at)->toIso8601String(),
            'created_at' => optional($l->created_at)->toIso8601String(),
        ];
    }

    private function refuse(string $message, int $status = 422)
    {
        return response()->json(['message' => $message], $status);
    }
}
