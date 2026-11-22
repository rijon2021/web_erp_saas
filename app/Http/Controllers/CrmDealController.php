<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\CrmDeal;
use App\Models\CrmLead;
use App\Models\CrmLostReason;
use App\Models\CrmPipeline;
use App\Models\CrmPipelineStage;
use App\Models\CrmTag;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Crm\CrmDealService;
use App\Services\Crm\CrmLeadService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * CRM deals: list, kanban board per pipeline, create / edit with product
 * lines, detail with history, move between stages, won / lost / reopen.
 * Visibility follows the lead rule (own + unassigned, or all with crm_view_all).
 */
class CrmDealController extends Controller
{
    private const SORTABLE = [
        'deal_number' => 'crm_deals.deal_number',
        'title' => 'crm_deals.title',
        'value' => 'crm_deals.value',
        'expected_close_date' => 'crm_deals.expected_close_date',
        'created_at' => 'crm_deals.created_at',
    ];

    public function __construct(private CrmDealService $service, private CrmLeadService $leads)
    {
    }

    public function meta(Request $request)
    {
        $user = $request->user('api');
        $this->authorizeForUser($user, 'view', CrmDeal::class);

        return response()->json([
            'pipelines' => $this->pipelines(),
            'default_pipeline_id' => optional(CrmPipeline::defaultPipeline())->id,
            'lost_reasons' => CrmLostReason::orderBy('sort_order')->orderBy('name')->get(['id', 'name']),
            'tags' => CrmTag::orderBy('name')->get(['id', 'name', 'color']),
            'owners' => User::whereNull('deleted_at')->where('statut', 1)->orderBy('firstname')->get(['id', 'firstname', 'lastname', 'username'])
                ->map(fn ($u) => ['id' => $u->id, 'name' => CrmLeadService::userName($u)])->values(),
            'can' => ['view_all' => $user->can('viewAll', CrmLead::class)],
            'me' => $user->id,
        ]);
    }

    public function index(Request $request)
    {
        $user = $request->user('api');
        $this->authorizeForUser($user, 'view', CrmDeal::class);
        app(\App\Services\Crm\CrmConversionService::class)->syncQuotationWins();

        $perPage = (int) ($request->limit ?? 10);
        $page = max(1, (int) $request->get('page', 1));
        $order = self::SORTABLE[$request->SortField] ?? 'crm_deals.created_at';
        $dir = strtolower((string) $request->SortType) === 'asc' ? 'asc' : 'desc';

        $filtered = $this->applyFilters($this->service->visible($user)->select('crm_deals.*'), $request, $user);
        $query = clone $filtered;
        $status = (string) $request->get('status', 'open');
        if (in_array($status, CrmDeal::STATUSES, true)) {
            $query->where('crm_deals.status', $status);
        }

        $totalRows = (clone $query)->count();
        if ($perPage === -1) {
            $perPage = max(1, $totalRows);
        }
        $rows = $query->with('stage', 'pipeline', 'owner', 'lead', 'client', 'tags', 'nextActivity')
            ->orderBy($order, $dir)->orderBy('crm_deals.id', 'desc')
            ->offset(($page * $perPage) - $perPage)->limit($perPage)->get();

        $open = (clone $filtered)->where('crm_deals.status', 'open')->with('stage')->get(['crm_deals.id', 'crm_deals.value', 'crm_deals.crm_pipeline_stage_id']);
        $monthStart = Carbon::now()->startOfMonth();

        return response()->json([
            'totalRows' => $totalRows,
            'deals' => $rows->map(fn ($d) => $this->present($d))->values(),
            'summary' => [
                'open' => $open->count(),
                'open_value' => round((float) $open->sum('value'), 2),
                'weighted' => round($open->sum(fn ($d) => $d->weightedValue()), 2),
                'won' => (clone $filtered)->where('crm_deals.status', 'won')->count(),
                'lost' => (clone $filtered)->where('crm_deals.status', 'lost')->count(),
                'won_month' => (clone $filtered)->where('crm_deals.status', 'won')->where('crm_deals.won_at', '>=', $monthStart)->count(),
                'won_month_value' => round((float) (clone $filtered)->where('crm_deals.status', 'won')->where('crm_deals.won_at', '>=', $monthStart)->sum('crm_deals.value'), 2),
                'overdue' => (clone $filtered)->where('crm_deals.status', 'open')->whereDate('crm_deals.expected_close_date', '<', Carbon::today())->count(),
            ],
        ]);
    }

    /** Kanban: the pipeline's stages with their open deals and column totals. */
    public function board(Request $request)
    {
        $user = $request->user('api');
        $this->authorizeForUser($user, 'view', CrmDeal::class);
        app(\App\Services\Crm\CrmConversionService::class)->syncQuotationWins();

        $pipeline = $request->filled('pipeline')
            ? CrmPipeline::find((int) $request->pipeline)
            : CrmPipeline::defaultPipeline();
        if (! $pipeline) {
            return response()->json(['pipeline' => null, 'stages' => []]);
        }

        $deals = $this->applyFilters($this->service->visible($user)->select('crm_deals.*'), $request, $user)
            ->where('crm_deals.crm_pipeline_id', $pipeline->id)->where('crm_deals.status', 'open')
            ->with('stage', 'owner', 'lead', 'client', 'tags', 'nextActivity')
            ->orderBy('crm_deals.position')->orderBy('crm_deals.id')->get()
            ->groupBy('crm_pipeline_stage_id');

        $monthStart = Carbon::now()->startOfMonth();
        $closed = $this->service->visible($user)->where('crm_pipeline_id', $pipeline->id)
            ->where(fn ($q) => $q->where('won_at', '>=', $monthStart)->orWhere('lost_at', '>=', $monthStart))
            ->get(['status', 'value']);

        return response()->json([
            'pipeline' => ['id' => $pipeline->id, 'name' => $pipeline->name],
            'stages' => $pipeline->stages()->get()->map(function ($s) use ($deals) {
                $cards = $deals->get($s->id, collect());

                return [
                    'id' => $s->id,
                    'name' => $s->name,
                    'color' => $s->color,
                    'probability' => (int) $s->probability,
                    'count' => $cards->count(),
                    'value' => round((float) $cards->sum('value'), 2),
                    'weighted' => round($cards->sum(fn ($d) => $d->weightedValue()), 2),
                    'deals' => $cards->map(fn ($d) => $this->present($d))->values(),
                ];
            })->values(),
            'month' => [
                'won' => $closed->where('status', 'won')->count(),
                'won_value' => round((float) $closed->where('status', 'won')->sum('value'), 2),
                'lost' => $closed->where('status', 'lost')->count(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $user = $request->user('api');
        $this->authorizeForUser($user, 'create', CrmDeal::class);
        [$data, $lines] = $this->validated($request);

        // No owner sent: the creator. Sent empty: the unassigned pool.
        if (! array_key_exists('owner_id', $data)) {
            $data['owner_id'] = $user->id;
        }
        if (! $user->can('viewAll', CrmLead::class) && $data['owner_id'] && (int) $data['owner_id'] !== (int) $user->id) {
            $data['owner_id'] = $user->id;
        }

        $deal = DB::transaction(function () use ($data, $lines, $request, $user) {
            $stage = CrmPipelineStage::findOrFail($data['crm_pipeline_stage_id']);
            $deal = CrmDeal::create(array_merge($data, [
                'deal_number' => $this->service->nextNumber(),
                'status' => 'open',
                'position' => (int) CrmDeal::where('crm_pipeline_stage_id', $stage->id)->where('status', 'open')->max('position') + 1,
                'stage_entered_at' => now(),
                'last_activity_at' => now(),
                'created_by' => $user->id,
            ]));
            if ($lines !== null) {
                $this->service->saveLines($deal, $lines);
            }
            $deal->tags()->sync($this->service->tagIds((array) $request->input('tags', [])));
            $this->service->log($deal, 'created');
            $this->service->logOnLead($deal, 'deal_created');

            return $deal;
        });

        return response()->json(['success' => true, 'id' => $deal->id]);
    }

    public function show(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'view', CrmDeal::class);
        app(\App\Services\Crm\CrmConversionService::class)->syncQuotationWins();
        $d = $this->find($request, $id, ['stage', 'pipeline', 'owner', 'creator', 'lead.status', 'client', 'tags', 'lostReason', 'products', 'nextActivity', 'contact', 'quotation',
            'events' => fn ($q) => $q->orderBy('created_at', 'desc')->orderBy('id', 'desc')->limit(200), 'events.user']);

        return response()->json(['deal' => array_merge($this->present($d), [
            'description' => $d->description,
            'value_from_products' => (bool) $d->value_from_products,
            'lost_reason' => optional($d->lostReason)->name,
            'crm_lost_reason_id' => $d->crm_lost_reason_id,
            'lost_note' => $d->lost_note,
            'won_at' => optional($d->won_at)->toIso8601String(),
            'lost_at' => optional($d->lost_at)->toIso8601String(),
            'stage_entered_at' => optional($d->stage_entered_at)->toIso8601String(),
            'created_by_name' => CrmLeadService::userName($d->creator),
            'lead' => $d->lead ? [
                'id' => $d->lead->id, 'lead_number' => $d->lead->lead_number, 'name' => $d->lead->name,
                'email' => $d->lead->email, 'phone' => $d->lead->phone, 'company_name' => $d->lead->company_name,
                'status' => $d->lead->status ? ['name' => $d->lead->status->name, 'color' => $d->lead->status->color] : null,
                'deleted' => (bool) $d->lead->deleted_at,
            ] : null,
            'client' => $d->client ? ['id' => $d->client->id, 'name' => $d->client->name, 'email' => $d->client->email, 'phone' => $d->client->phone] : null,
            'portal_visible' => (bool) $d->portal_visible,
            'portal_account' => $d->client_id && \Illuminate\Support\Facades\Schema::hasTable('portal_clients')
                && \Illuminate\Support\Facades\DB::table('portal_clients')->where('client_id', $d->client_id)->where('status', 1)->exists(),
            'crm_contact_id' => $d->crm_contact_id,
            'contact_person' => $d->contact ? $d->contact->only(['id', 'name', 'job_title', 'email', 'phone']) : null,
            'contacts' => $d->client_id
                ? \App\Models\CrmContact::whereNull('deleted_at')->where('client_id', $d->client_id)->orderByDesc('is_primary')->orderBy('name')->get(['id', 'name', 'job_title'])
                : [],
            'quotation' => $d->quotation && ! $d->quotation->deleted_at ? [
                'id' => $d->quotation->id,
                'ref' => $d->quotation->Ref,
                'date' => $d->quotation->date,
                'total' => (float) $d->quotation->GrandTotal,
                'status' => $d->quotation->statut,
                'converted_sale_id' => $d->quotation->converted_sale_id,
                'sale_ref' => $d->quotation->converted_sale_id ? optional(\App\Models\Sale::find($d->quotation->converted_sale_id))->Ref : null,
            ] : null,
            'products' => $d->products->map(fn ($p) => [
                'id' => $p->id,
                'product_id' => $p->product_id,
                'product_variant_id' => $p->product_variant_id,
                'name' => $p->name,
                'code' => $p->code,
                'quantity' => (float) $p->quantity,
                'unit_price' => (float) $p->unit_price,
                'discount_percent' => (float) $p->discount_percent,
                'tax_percent' => (float) $p->tax_percent,
                'tax_method' => (int) $p->tax_method,
                'total' => (float) $p->total,
            ])->values(),
            'activities' => $request->user('api')->can('view', \App\Models\CrmActivity::class)
                ? app(\App\Services\Crm\CrmActivityService::class)->forDeal($d)
                    ->map(fn ($a) => app(\App\Services\Crm\CrmActivityService::class)->present($a, $request->user('api')))->values()
                : null,
            'events' => $d->events->map(fn ($e) => [
                'id' => $e->id,
                'type' => $e->type,
                'note' => $e->note,
                'meta' => $e->meta,
                'user_name' => CrmLeadService::userName($e->user),
                'created_at' => optional($e->created_at)->toIso8601String(),
            ])->values(),
        ])]);
    }

    public function update(Request $request, $id)
    {
        $user = $request->user('api');
        $this->authorizeForUser($user, 'update', CrmDeal::class);
        $deal = $this->find($request, $id);
        [$data, $lines] = $this->validated($request, $deal);
        if (! $user->can('viewAll', CrmLead::class) && array_key_exists('owner_id', $data)
            && $data['owner_id'] && (int) $data['owner_id'] !== (int) $user->id) {
            return $this->refuse('You can only assign deals to yourself.');
        }

        DB::transaction(function () use ($deal, $data, $lines, $request) {
            $before = $deal->only(['owner_id', 'crm_pipeline_stage_id', 'value']);
            $stageChanged = (int) $data['crm_pipeline_stage_id'] !== (int) $deal->crm_pipeline_stage_id;
            $deal->update(array_diff_key($data, ['crm_pipeline_stage_id' => 1, 'crm_pipeline_id' => 1]));
            if ($stageChanged && $deal->status === 'open') {
                $this->service->moveTo($deal, CrmPipelineStage::findOrFail($data['crm_pipeline_stage_id']));
            } elseif ($stageChanged) {
                $deal->update(['crm_pipeline_stage_id' => $data['crm_pipeline_stage_id'], 'crm_pipeline_id' => $data['crm_pipeline_id']]);
            }
            if ($lines !== null) {
                $this->service->saveLines($deal, $lines);
            }
            if ($request->has('tags')) {
                $deal->tags()->sync($this->service->tagIds((array) $request->input('tags', [])));
            }
            $this->service->log($deal, 'updated');
            if ((int) $before['owner_id'] !== (int) $deal->owner_id) {
                $this->service->log($deal, 'owner_changed', null, [
                    'from' => CrmLeadService::userName(User::find($before['owner_id'])),
                    'to' => CrmLeadService::userName(User::find($deal->owner_id)),
                ]);
            }
        });

        return response()->json(['success' => true, 'id' => $deal->id]);
    }

    /** Share (or stop sharing) a customer's deal in their client portal. */
    public function portal(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', CrmDeal::class);
        $deal = $this->find($request, $id);
        $request->validate(['visible' => 'required|boolean']);
        if ($request->boolean('visible') && ! $deal->client_id) {
            return response()->json(['message' => 'Only a customer\'s deal can be shown in the client portal.'], 422);
        }
        $deal->forceFill(['portal_visible' => $request->boolean('visible')])->save();
        $this->service->log($deal, $deal->portal_visible ? 'portal_shared' : 'portal_hidden');

        return response()->json(['success' => true, 'portal_visible' => (bool) $deal->portal_visible]);
    }

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', CrmDeal::class);
        $this->find($request, $id)->delete();

        return response()->json(['success' => true]);
    }

    public function deleteBySelection(Request $request)
    {
        $user = $request->user('api');
        $this->authorizeForUser($user, 'delete', CrmDeal::class);
        $ids = array_map('intval', (array) $request->selectedIds);
        $count = $this->service->visible($user)->whereIn('crm_deals.id', $ids)->update(['deleted_at' => now()]);

        return response()->json(['success' => true, 'count' => $count]);
    }

    /** Drag on the board: stage (same pipeline) + 0-based position in the column. */
    public function move(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', CrmDeal::class);
        $deal = $this->find($request, $id);
        $request->validate(['crm_pipeline_stage_id' => 'required|integer', 'position' => 'nullable|integer|min:0']);
        if ($deal->status !== 'open') {
            return $this->refuse('Only open deals can be moved. Reopen it first.');
        }
        $stage = CrmPipelineStage::where('crm_pipeline_id', $deal->crm_pipeline_id)->find($request->crm_pipeline_stage_id);
        if (! $stage) {
            return $this->refuse('This stage is not in the deal\'s pipeline.');
        }

        DB::transaction(function () use ($deal, $stage, $request) {
            // Serialise moves in one pipeline so positions stay consistent.
            DB::table('crm_pipelines')->where('id', $deal->crm_pipeline_id)->lockForUpdate()->get(['id']);
            $this->service->moveTo($deal->fresh(), $stage, $request->filled('position') ? (int) $request->position : null);
        });

        return response()->json(['success' => true]);
    }

    public function won(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', CrmDeal::class);
        $deal = $this->find($request, $id);
        if ($deal->status !== 'open') {
            return $this->refuse('This deal is already closed.');
        }
        $request->validate(['note' => 'nullable|string|max:1000']);

        DB::transaction(function () use ($deal, $request) {
            $deal->update(['status' => 'won', 'won_at' => now(), 'lost_at' => null, 'crm_lost_reason_id' => null, 'lost_note' => null, 'last_activity_at' => now()]);
            $this->service->log($deal, 'won', $request->note, ['value' => (float) $deal->value]);
            $this->service->logOnLead($deal, 'deal_won', ['value' => (float) $deal->value]);
        });

        return response()->json(['success' => true]);
    }

    public function lost(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', CrmDeal::class);
        $deal = $this->find($request, $id);
        if ($deal->status !== 'open') {
            return $this->refuse('This deal is already closed.');
        }
        $request->validate([
            'crm_lost_reason_id' => 'required|integer|exists:crm_lost_reasons,id',
            'lost_note' => 'nullable|string|max:1000',
        ], ['crm_lost_reason_id.required' => 'Choose why the deal was lost.']);

        DB::transaction(function () use ($deal, $request) {
            $deal->update([
                'status' => 'lost', 'lost_at' => now(), 'won_at' => null,
                'crm_lost_reason_id' => (int) $request->crm_lost_reason_id, 'lost_note' => $request->lost_note,
                'last_activity_at' => now(),
            ]);
            $reason = optional(CrmLostReason::withTrashed()->find($request->crm_lost_reason_id))->name;
            $this->service->log($deal, 'lost', $request->lost_note, ['reason' => $reason]);
            $this->service->logOnLead($deal, 'deal_lost', ['reason' => $reason]);
        });

        return response()->json(['success' => true]);
    }

    public function reopen(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', CrmDeal::class);
        $deal = $this->find($request, $id, ['stage']);
        if ($deal->status === 'open') {
            return $this->refuse('This deal is already open.');
        }

        DB::transaction(function () use ($deal) {
            $deal->update(['status' => 'open', 'won_at' => null, 'lost_at' => null, 'crm_lost_reason_id' => null, 'lost_note' => null]);
            // Its stage may have been removed since: fall back to the pipeline's first stage.
            $stage = CrmPipelineStage::where('crm_pipeline_id', $deal->crm_pipeline_id)->find($deal->crm_pipeline_stage_id)
                ?? CrmPipelineStage::where('crm_pipeline_id', $deal->crm_pipeline_id)->orderBy('sort_order')->first();
            if ($stage) {
                $this->service->moveTo($deal->fresh(), $stage);
            }
            $this->service->log($deal, 'reopened');
        });

        return response()->json(['success' => true]);
    }

    /** Products (and variants) for the deal form, with their selling price. */
    public function products(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', CrmDeal::class);
        $s = trim((string) $request->get('search', ''));

        $products = Product::whereNull('deleted_at')
            ->when($s !== '', fn ($q) => $q->where(fn ($w) => $w->where('name', 'LIKE', "%{$s}%")->orWhere('code', 'LIKE', "%{$s}%")))
            ->orderBy('name')->limit(20)->get(['id', 'name', 'code', 'price', 'TaxNet', 'tax_method', 'is_variant']);
        $variants = ProductVariant::whereNull('deleted_at')->whereIn('product_id', $products->where('is_variant', 1)->pluck('id'))->get()->groupBy('product_id');

        $out = [];
        foreach ($products as $p) {
            $base = [
                'product_id' => $p->id,
                'tax_percent' => (float) $p->TaxNet,
                'tax_method' => (string) $p->tax_method === '2' ? 2 : 1,
            ];
            if ($p->is_variant && $variants->has($p->id)) {
                foreach ($variants[$p->id] as $v) {
                    $out[] = $base + [
                        'product_variant_id' => $v->id,
                        'name' => $p->name . ' — ' . $v->name,
                        'code' => $v->code ?: $p->code,
                        'unit_price' => (float) ($v->price ?: $p->price),
                    ];
                }
                continue;
            }
            $out[] = $base + ['product_variant_id' => null, 'name' => $p->name, 'code' => $p->code, 'unit_price' => (float) $p->price];
        }

        return response()->json(['products' => array_slice($out, 0, 30)]);
    }

    /** Existing clients for the deal form. */
    public function clients(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', CrmDeal::class);
        $s = trim((string) $request->get('search', ''));

        return response()->json(['clients' => Client::whereNull('deleted_at')
            ->when($s !== '', fn ($q) => $q->where(fn ($w) => $w->where('name', 'LIKE', "%{$s}%")->orWhere('phone', 'LIKE', "%{$s}%")
                ->orWhere('email', 'LIKE', "%{$s}%")->orWhere('code', 'LIKE', "%{$s}%")))
            ->orderBy('name')->limit(20)->get(['id', 'name', 'phone', 'email', 'code'])]);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function find(Request $request, $id, array $with = []): CrmDeal
    {
        $deal = CrmDeal::with($with)->whereNull('deleted_at')->findOrFail($id);
        if (! $this->service->canSee($request->user('api'), $deal)) {
            abort(403, 'This deal belongs to another sales rep.');
        }

        return $deal;
    }

    private function applyFilters($query, Request $request, User $user)
    {
        $query
            ->when($request->filled('pipeline'), fn ($q) => $q->where('crm_deals.crm_pipeline_id', (int) $request->pipeline))
            ->when($request->filled('stage'), fn ($q) => $q->where('crm_deals.crm_pipeline_stage_id', (int) $request->stage))
            ->when($request->filled('lead'), fn ($q) => $q->where('crm_deals.crm_lead_id', (int) $request->lead))
            ->when($request->filled('client'), fn ($q) => $q->where('crm_deals.client_id', (int) $request->client))
            ->when($request->filled('tag'), fn ($q) => $q->whereExists(fn ($w) => $w->from('crm_taggables')
                ->whereColumn('crm_taggables.taggable_id', 'crm_deals.id')->where('crm_taggables.taggable_type', 'deal')
                ->where('crm_taggables.crm_tag_id', (int) $request->tag)))
            ->when($request->filled('close_from'), fn ($q) => $q->whereDate('crm_deals.expected_close_date', '>=', $request->close_from))
            ->when($request->filled('close_to'), fn ($q) => $q->whereDate('crm_deals.expected_close_date', '<=', $request->close_to));

        $owner = (string) $request->get('owner', '');
        if ($owner === 'me') {
            $query->where('crm_deals.owner_id', $user->id);
        } elseif ($owner === 'none') {
            $query->whereNull('crm_deals.owner_id');
        } elseif (ctype_digit($owner)) {
            $query->where('crm_deals.owner_id', (int) $owner);
        }

        switch ($request->get('quick')) {
            case 'overdue':
                $query->where('crm_deals.status', 'open')->whereDate('crm_deals.expected_close_date', '<', Carbon::today());
                break;
            case 'closing_month':
                $query->where('crm_deals.status', 'open')
                    ->whereBetween('crm_deals.expected_close_date', [Carbon::today()->startOfMonth()->toDateString(), Carbon::today()->endOfMonth()->toDateString()]);
                break;
        }

        if ($request->filled('search')) {
            $s = trim($request->search);
            $query->where(function ($q) use ($s) {
                $q->where('crm_deals.title', 'LIKE', "%{$s}%")
                    ->orWhere('crm_deals.deal_number', 'LIKE', "%{$s}%")
                    ->orWhereIn('crm_deals.crm_lead_id', CrmLead::where('name', 'LIKE', "%{$s}%")->orWhere('company_name', 'LIKE', "%{$s}%")->select('id'))
                    ->orWhereIn('crm_deals.client_id', Client::where('name', 'LIKE', "%{$s}%")->select('id'));
            });
        }

        return $query;
    }

    /** [data, lines|null] — lines null when the request didn't send them. */
    private function validated(Request $request, ?CrmDeal $deal = null): array
    {
        $request->validate([
            'title' => 'required|string|max:191',
            'crm_pipeline_stage_id' => 'required|integer|exists:crm_pipeline_stages,id',
            'crm_lead_id' => 'nullable|integer',
            'client_id' => ['nullable', 'integer', Rule::exists('clients', 'id')->whereNull('deleted_at')],
            'crm_contact_id' => 'nullable|integer',
            'owner_id' => ['nullable', 'integer', Rule::exists('users', 'id')->whereNull('deleted_at')],
            'value' => 'nullable|numeric|min:0|max:999999999999',
            'expected_close_date' => 'nullable|date',
            'description' => 'nullable|string|max:10000',
            'tags' => 'nullable|array|max:30',
            'products' => 'nullable|array|max:200',
            'products.*.name' => 'required_with:products|string|max:191',
            'products.*.quantity' => 'required_with:products|numeric|min:0.001|max:99999999',
            'products.*.unit_price' => 'required_with:products|numeric|min:0|max:999999999',
            'products.*.discount_percent' => 'nullable|numeric|min:0|max:100',
            'products.*.tax_percent' => 'nullable|numeric|min:0|max:100',
            'products.*.tax_method' => 'nullable|in:1,2',
            'products.*.product_id' => 'nullable|integer',
            'products.*.product_variant_id' => 'nullable|integer',
        ], ['crm_pipeline_stage_id.required' => 'Choose a stage.']);

        $stage = CrmPipelineStage::with('pipeline')->find($request->crm_pipeline_stage_id);
        if (! $stage || ! $stage->pipeline || $stage->pipeline->deleted_at) {
            abort(response()->json(['message' => 'This stage no longer exists.', 'errors' => ['crm_pipeline_stage_id' => ['Not found.']]], 422));
        }

        $leadId = $request->crm_lead_id ? (int) $request->crm_lead_id : null;
        if ($leadId) {
            $lead = CrmLead::whereNull('deleted_at')->find($leadId);
            $kept = $deal && (int) $deal->crm_lead_id === $leadId;
            if (! $lead || (! $kept && ! $this->leads->canSee($request->user('api'), $lead))) {
                abort(response()->json(['message' => 'This lead is not available.', 'errors' => ['crm_lead_id' => ['Not available.']]], 422));
            }
        }
        $clientId = $request->client_id ? (int) $request->client_id : null;
        // A converted lead brings its client along.
        if (! $clientId && isset($lead) && $lead->client_id) {
            $clientId = (int) $lead->client_id;
        }
        if (! $leadId && ! $clientId) {
            abort(response()->json([
                'message' => 'Link the deal to a lead or a customer.',
                'errors' => ['crm_lead_id' => ['Choose a lead or a customer.']],
            ], 422));
        }

        $contactId = $request->crm_contact_id ? (int) $request->crm_contact_id : null;
        if ($contactId && ! ($clientId && \App\Models\CrmContact::whereNull('deleted_at')->where('client_id', $clientId)->whereKey($contactId)->exists())) {
            $contactId = null; // only a contact of this deal's customer
        }

        $data = [
            'title' => trim($request->title),
            'crm_contact_id' => $contactId,
            'crm_pipeline_id' => $stage->crm_pipeline_id,
            'crm_pipeline_stage_id' => $stage->id,
            'crm_lead_id' => $leadId,
            'client_id' => $clientId,
            'expected_close_date' => $request->expected_close_date ? Carbon::parse($request->expected_close_date)->toDateString() : null,
            'description' => $request->description,
        ];
        $lines = $request->has('products') ? array_values((array) $request->input('products', [])) : null;
        if (! $lines) {
            $data['value'] = round((float) $request->value, 2);
        }
        if ($request->has('owner_id')) {
            $data['owner_id'] = $request->owner_id ?: null;
        }

        return [$data, $lines];
    }

    private function pipelines()
    {
        return CrmPipeline::with('stages')->orderBy('sort_order')->orderBy('id')->get()->map(fn ($p) => [
            'id' => $p->id,
            'name' => $p->name,
            'is_default' => (bool) $p->is_default,
            'stages' => $p->stages->map(fn ($s) => $s->only(['id', 'name', 'color', 'probability', 'rot_days']))->values(),
        ])->values();
    }

    private function present(CrmDeal $d): array
    {
        $contact = $d->client ? $d->client->name : optional($d->lead)->name;

        return [
            'id' => $d->id,
            'deal_number' => $d->deal_number,
            'title' => $d->title,
            'status' => $d->status,
            'value' => (float) $d->value,
            'weighted' => $d->weightedValue(),
            'expected_close_date' => optional($d->expected_close_date)->toDateString(),
            'pipeline' => $d->pipeline ? ['id' => $d->pipeline->id, 'name' => $d->pipeline->name] : null,
            'stage' => $d->stage ? ['id' => $d->stage->id, 'name' => $d->stage->name, 'color' => $d->stage->color, 'probability' => (int) $d->stage->probability] : null,
            'owner' => $d->owner ? ['id' => $d->owner->id, 'name' => CrmLeadService::userName($d->owner)] : null,
            'contact' => $contact,
            'crm_lead_id' => $d->crm_lead_id,
            'client_id' => $d->client_id,
            'company' => optional($d->lead)->company_name,
            'tags' => $d->relationLoaded('tags') ? $d->tags->map(fn ($t) => ['id' => $t->id, 'name' => $t->name, 'color' => $t->color])->values() : [],
            'next_activity' => $d->relationLoaded('nextActivity') ? \App\Services\Crm\CrmActivityService::nextStep($d->nextActivity) : null,
            'is_rotting' => $d->isRotting(),
            'is_overdue' => $d->isOverdue(),
            'last_activity_at' => optional($d->last_activity_at)->toIso8601String(),
            'created_at' => optional($d->created_at)->toIso8601String(),
        ];
    }

    private function refuse(string $message)
    {
        return response()->json(['message' => $message], 422);
    }
}
