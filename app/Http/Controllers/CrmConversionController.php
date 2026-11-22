<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\CrmDeal;
use App\Models\CrmLead;
use App\Models\Quotation;
use App\Models\Setting;
use App\Models\Warehouse;
use App\Services\Crm\CrmConversionService;
use App\Services\Crm\CrmDealService;
use App\Services\Crm\CrmLeadService;
use App\Traits\ScopesWarehouseAccess;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Lead → customer, and deal → quotation. */
class CrmConversionController extends Controller
{
    use ScopesWarehouseAccess;

    public function __construct(
        private CrmConversionService $conversion,
        private CrmLeadService $leads,
        private CrmDealService $deals,
    ) {
    }

    /** What the convert dialog needs: likely matches among existing customers. */
    public function convertMeta(Request $request, $id)
    {
        $user = $request->user('api');
        $this->authorizeForUser($user, 'update', CrmLead::class);
        $lead = $this->findLead($request, $id);
        $dups = $this->leads->duplicates($lead->email, $lead->phone, $lead->id);

        return response()->json([
            'matches' => $dups['clients'],
            'can_create_customer' => $user->can('create', Client::class),
            'open_deals' => CrmDeal::whereNull('deleted_at')->where('crm_lead_id', $lead->id)->where('status', 'open')->count(),
        ]);
    }

    public function convert(Request $request, $id)
    {
        $user = $request->user('api');
        $this->authorizeForUser($user, 'update', CrmLead::class);
        $lead = $this->findLead($request, $id);
        $request->validate([
            'mode' => ['required', Rule::in(['new', 'existing'])],
            'client_id' => 'required_if:mode,existing|nullable|integer',
            'deal.title' => 'nullable|string|max:191',
            'deal.crm_pipeline_stage_id' => 'nullable|integer|exists:crm_pipeline_stages,id',
            'deal.value' => 'nullable|numeric|min:0',
        ]);
        if ($request->mode === 'new' && ! $user->can('create', Client::class)) {
            return response()->json(['message' => 'You are not allowed to add customers. Link the lead to an existing customer instead.'], 403);
        }
        if (! empty($request->input('deal.title')) && ! $user->can('create', CrmDeal::class)) {
            return response()->json(['message' => 'You are not allowed to add deals.'], 403);
        }

        [$client, $deal] = $this->conversion->convertLead($lead, $request->only(['mode', 'client_id', 'deal']), $user);

        return response()->json(['success' => true, 'client_id' => $client->id, 'deal_id' => optional($deal)->id]);
    }

    public function quotationMeta(Request $request, $id)
    {
        $user = $request->user('api');
        $this->authorizeForUser($user, 'update', CrmDeal::class);
        $deal = $this->findDeal($request, $id, ['products']);
        $ids = $this->warehouseScopeIds();
        $warehouses = Warehouse::whereNull('deleted_at')->when($ids !== null, fn ($q) => $q->whereIn('id', $ids))->orderBy('name')->get(['id', 'name']);
        $default = optional(Setting::whereNull('deleted_at')->first())->warehouse_id;

        return response()->json([
            'warehouses' => $warehouses,
            'default_warehouse_id' => $warehouses->contains('id', $default) ? $default : optional($warehouses->first())->id,
            'has_customer' => (bool) $deal->client_id,
            'catalogue_lines' => $deal->products->whereNotNull('product_id')->count(),
            'custom_lines' => $deal->products->whereNull('product_id')->count(),
            'lines_total' => round((float) $deal->products->whereNotNull('product_id')->sum('total'), 2),
            'can_create_quotation' => $user->can('create', Quotation::class),
        ]);
    }

    public function quotation(Request $request, $id)
    {
        $user = $request->user('api');
        $this->authorizeForUser($user, 'update', CrmDeal::class);
        if (! $user->can('create', Quotation::class)) {
            return response()->json(['message' => 'You are not allowed to create quotations.'], 403);
        }
        $deal = $this->findDeal($request, $id);
        $request->validate([
            'warehouse_id' => ['required', 'integer', Rule::exists('warehouses', 'id')->whereNull('deleted_at')],
            'tax_rate' => 'nullable|numeric|min:0|max:100',
            'discount' => 'nullable|numeric|min:0',
            'shipping' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:5000',
        ]);
        $this->abortIfWarehouseDenied((int) $request->warehouse_id);

        [$quotation, $custom] = $this->conversion->createQuotation($deal, $request->only(['warehouse_id', 'tax_rate', 'discount', 'shipping', 'notes']), $user);

        return response()->json(['success' => true, 'quotation_id' => $quotation->id, 'ref' => $quotation->Ref, 'custom_lines' => $custom]);
    }

    private function findLead(Request $request, $id): CrmLead
    {
        $lead = CrmLead::whereNull('deleted_at')->findOrFail($id);
        abort_unless($this->leads->canSee($request->user('api'), $lead), 403, 'This lead belongs to another sales rep.');

        return $lead;
    }

    private function findDeal(Request $request, $id, array $with = []): CrmDeal
    {
        $deal = CrmDeal::with($with)->whereNull('deleted_at')->findOrFail($id);
        abort_unless($this->deals->canSee($request->user('api'), $deal), 403, 'This deal belongs to another sales rep.');

        return $deal;
    }
}
