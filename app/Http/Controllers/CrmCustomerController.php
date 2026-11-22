<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\CrmActivity;
use App\Models\CrmContact;
use App\Models\CrmDeal;
use App\Models\CrmLead;
use App\Models\Quotation;
use App\Models\Sale;
use App\Services\Crm\CrmActivityService;
use App\Services\Crm\CrmDealService;
use App\Services\Crm\CrmLeadService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * CRM view of customers (existing `clients`): the list with CRM figures,
 * the Customer 360 page, and the customer's contact people.
 */
class CrmCustomerController extends Controller
{
    public function __construct(
        private CrmLeadService $leads,
        private CrmDealService $deals,
        private CrmActivityService $activities,
    ) {
    }

    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'customers', CrmLead::class);

        $perPage = (int) ($request->limit ?? 10);
        $page = max(1, (int) $request->get('page', 1));
        $sortable = ['name' => 'clients.name', 'open_value' => 'open_value', 'won_value' => 'won_value', 'last_activity' => 'last_activity'];
        $order = $sortable[$request->SortField] ?? 'last_activity';
        $dir = strtolower((string) $request->SortType) === 'asc' ? 'asc' : 'desc';

        $query = Client::query()->whereNull('clients.deleted_at')
            ->select('clients.id', 'clients.name', 'clients.code', 'clients.email', 'clients.phone', 'clients.city')
            ->selectSub(CrmDeal::query()->whereNull('deleted_at')->where('status', 'open')->whereColumn('client_id', 'clients.id')->selectRaw('COUNT(*)'), 'open_deals')
            ->selectSub(CrmDeal::query()->whereNull('deleted_at')->where('status', 'open')->whereColumn('client_id', 'clients.id')->selectRaw('COALESCE(SUM(value),0)'), 'open_value')
            ->selectSub(CrmDeal::query()->whereNull('deleted_at')->where('status', 'won')->whereColumn('client_id', 'clients.id')->selectRaw('COALESCE(SUM(value),0)'), 'won_value')
            ->selectSub(CrmActivity::query()->whereNull('deleted_at')->where('status', 'done')->whereColumn('client_id', 'clients.id')->selectRaw('MAX(done_at)'), 'last_activity')
            ->selectSub(CrmContact::query()->whereNull('deleted_at')->whereColumn('client_id', 'clients.id')->selectRaw('COUNT(*)'), 'contacts_count');

        // "In the CRM": customers with a deal, a converted lead, an activity or a contact.
        if ($request->get('scope', 'crm') === 'crm') {
            $query->where(fn ($q) => $q
                ->whereExists(fn ($w) => $w->from('crm_deals')->whereColumn('crm_deals.client_id', 'clients.id')->whereNull('crm_deals.deleted_at'))
                ->orWhereExists(fn ($w) => $w->from('crm_leads')->whereColumn('crm_leads.client_id', 'clients.id')->whereNull('crm_leads.deleted_at'))
                ->orWhereExists(fn ($w) => $w->from('crm_activities')->whereColumn('crm_activities.client_id', 'clients.id')->whereNull('crm_activities.deleted_at'))
                ->orWhereExists(fn ($w) => $w->from('crm_contacts')->whereColumn('crm_contacts.client_id', 'clients.id')->whereNull('crm_contacts.deleted_at')));
        }
        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(fn ($q) => $q->where('clients.name', 'LIKE', "%{$s}%")->orWhere('clients.phone', 'LIKE', "%{$s}%")
                ->orWhere('clients.email', 'LIKE', "%{$s}%")->orWhere('clients.code', 'LIKE', "%{$s}%"));
        }

        $totalRows = DB::query()->fromSub((clone $query), 'c')->count();
        if ($perPage === -1) {
            $perPage = max(1, $totalRows);
        }
        $rows = $query->orderByRaw($order . ' IS NULL')->orderBy($order, $dir)->orderBy('clients.name')
            ->offset(($page * $perPage) - $perPage)->limit($perPage)->get();

        return response()->json([
            'totalRows' => $totalRows,
            'customers' => $rows->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'code' => $c->code,
                'email' => $c->email,
                'phone' => $c->phone,
                'city' => $c->city,
                'open_deals' => (int) $c->open_deals,
                'open_value' => round((float) $c->open_value, 2),
                'won_value' => round((float) $c->won_value, 2),
                'last_activity' => $c->last_activity ? \Carbon\Carbon::parse($c->last_activity)->toIso8601String() : null,
                'contacts_count' => (int) $c->contacts_count,
            ])->values(),
        ]);
    }

    /** Customer 360: profile, contacts, deals, leads, activities, quotations, sales and money. */
    public function show(Request $request, $id)
    {
        $user = $request->user('api');
        $this->authorizeForUser($user, 'customers', CrmLead::class);
        $c = Client::whereNull('deleted_at')->findOrFail($id);

        $deals = $user->can('view', CrmDeal::class)
            ? $this->deals->visible($user)->with('stage', 'owner', 'nextActivity')->where('client_id', $c->id)->orderByRaw("FIELD(status, 'open', 'won', 'lost')")->orderByDesc('created_at')->limit(100)->get()
            : collect();
        $leads = $user->can('view', CrmLead::class)
            ? $this->leads->visible($user)->with('status')->where('client_id', $c->id)->orderByDesc('converted_at')->limit(50)->get()
            : collect();
        $acts = $user->can('view', CrmActivity::class)
            ? $this->activities->visible($user)->with('owner', 'deal', 'lead')->where('client_id', $c->id)
                ->orderByRaw("CASE WHEN status = 'open' THEN 0 ELSE 1 END")->orderByRaw('COALESCE(due_at, done_at, created_at) DESC')->limit(200)->get()
            : collect();

        $canSales = $user->can('view', Sale::class);
        $canQuotes = $user->can('view', Quotation::class);
        $sales = Sale::whereNull('deleted_at')->where('client_id', $c->id);
        $money = [
            'sales_count' => (clone $sales)->count(),
            'sales_total' => round((float) (clone $sales)->sum('GrandTotal'), 2),
            'unpaid' => round((float) (clone $sales)->selectRaw('COALESCE(SUM(GrandTotal - paid_amount), 0) as due')->value('due'), 2),
            'last_sale' => optional((clone $sales)->max('date')) ? (string) (clone $sales)->max('date') : null,
            'open_value' => round((float) $deals->where('status', 'open')->sum('value'), 2),
            'won_value' => round((float) $deals->where('status', 'won')->sum('value'), 2),
        ];

        return response()->json(['customer' => [
            'id' => $c->id,
            'name' => $c->name,
            'code' => $c->code,
            'email' => $c->email,
            'phone' => $c->phone,
            'address' => $c->adresse,
            'city' => $c->city,
            'country' => $c->country,
            'tax_number' => $c->tax_number,
            'created_at' => optional($c->created_at)->toIso8601String(),
            'contacts' => CrmContact::whereNull('deleted_at')->where('client_id', $c->id)->orderByDesc('is_primary')->orderBy('name')->get()
                ->map(fn ($p) => $p->only(['id', 'name', 'job_title', 'email', 'phone', 'is_primary', 'notes']))->values(),
            'money' => $canSales ? $money : array_intersect_key($money, ['open_value' => 1, 'won_value' => 1]),
            'deals' => $deals->map(fn ($d) => [
                'id' => $d->id, 'deal_number' => $d->deal_number, 'title' => $d->title, 'status' => $d->status,
                'value' => (float) $d->value, 'expected_close_date' => optional($d->expected_close_date)->toDateString(),
                'stage' => $d->stage ? ['name' => $d->stage->name, 'color' => $d->stage->color] : null,
                'owner' => CrmLeadService::userName($d->owner),
                'next_activity' => CrmActivityService::nextStep($d->nextActivity),
            ])->values(),
            'leads' => $leads->map(fn ($l) => [
                'id' => $l->id, 'lead_number' => $l->lead_number, 'name' => $l->name,
                'converted_at' => optional($l->converted_at)->toIso8601String(),
                'status' => $l->status ? ['name' => $l->status->name, 'color' => $l->status->color] : null,
            ])->values(),
            'activities' => $user->can('view', CrmActivity::class)
                ? $acts->map(fn ($a) => $this->activities->present($a, $user))->values()
                : null,
            'quotations' => $canQuotes
                ? Quotation::whereNull('deleted_at')->where('client_id', $c->id)->orderByDesc('id')->limit(15)
                    ->get(['id', 'Ref', 'date', 'GrandTotal', 'statut', 'converted_sale_id', 'source'])
                    ->map(fn ($q) => [
                        'id' => $q->id, 'ref' => $q->Ref, 'date' => $q->date, 'total' => (float) $q->GrandTotal,
                        'status' => $q->statut, 'converted_sale_id' => $q->converted_sale_id, 'from_crm' => $q->source === 'crm',
                    ])->values()
                : null,
            'sales' => $canSales
                ? (clone $sales)->orderByDesc('id')->limit(15)->get(['id', 'Ref', 'date', 'GrandTotal', 'paid_amount', 'payment_statut'])
                    ->map(fn ($s) => [
                        'id' => $s->id, 'ref' => $s->Ref, 'date' => $s->date, 'total' => (float) $s->GrandTotal,
                        'paid' => (float) $s->paid_amount, 'payment_status' => $s->payment_statut,
                    ])->values()
                : null,
            'can' => [
                'contacts' => $user->can('manageContacts', CrmLead::class),
                'customer_edit' => $user->can('update', Client::class),
            ],
        ]]);
    }

    public function storeContact(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'manageContacts', CrmLead::class);
        $c = Client::whereNull('deleted_at')->findOrFail($id);
        $data = $this->validatedContact($request);

        $contact = DB::transaction(function () use ($c, $data, $request) {
            if (! empty($data['is_primary']) || ! CrmContact::whereNull('deleted_at')->where('client_id', $c->id)->exists()) {
                CrmContact::where('client_id', $c->id)->update(['is_primary' => false]);
                $data['is_primary'] = true;
            }

            return CrmContact::create($data + ['client_id' => $c->id, 'created_by' => $request->user('api')->id]);
        });

        return response()->json(['success' => true, 'id' => $contact->id]);
    }

    public function updateContact(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'manageContacts', CrmLead::class);
        $contact = CrmContact::whereNull('deleted_at')->findOrFail($id);
        $data = $this->validatedContact($request);

        DB::transaction(function () use ($contact, $data) {
            if (! empty($data['is_primary'])) {
                CrmContact::where('client_id', $contact->client_id)->where('id', '!=', $contact->id)->update(['is_primary' => false]);
            }
            $contact->update($data);
            // Unticking the only primary contact hands "primary" to the oldest other one
            // (or keeps it on this one when it is alone).
            $live = CrmContact::whereNull('deleted_at')->where('client_id', $contact->client_id);
            if (! (clone $live)->where('is_primary', true)->exists()) {
                $next = (clone $live)->where('id', '!=', $contact->id)->orderBy('id')->first() ?? $contact;
                $next->update(['is_primary' => true]);
            }
        });

        return response()->json(['success' => true]);
    }

    public function destroyContact(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'manageContacts', CrmLead::class);
        $contact = CrmContact::whereNull('deleted_at')->findOrFail($id);
        DB::transaction(function () use ($contact) {
            $contact->delete();
            CrmDeal::where('crm_contact_id', $contact->id)->update(['crm_contact_id' => null]);
            // Keep one primary contact when others remain.
            if ($contact->is_primary) {
                optional(CrmContact::whereNull('deleted_at')->where('client_id', $contact->client_id)->orderBy('id')->first())->update(['is_primary' => true]);
            }
        });

        return response()->json(['success' => true]);
    }

    private function validatedContact(Request $request): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:191',
            'job_title' => 'nullable|string|max:120',
            'email' => 'nullable|email|max:191',
            'phone' => 'nullable|string|max:50',
            'is_primary' => 'nullable|boolean',
            'notes' => 'nullable|string|max:1000',
        ]);
        $data['is_primary'] = $request->boolean('is_primary');

        return $data;
    }
}
