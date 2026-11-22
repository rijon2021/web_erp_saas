<?php

namespace App\Http\Controllers\Api\Portal;

use App\Http\Controllers\Controller;
use App\Models\RentalContract;
use App\Models\RentalCustomer;
use App\Models\RentalInvoice;
use App\Models\RentalMaintenance;
use App\Models\RentalPayment;
use App\Services\Rental\RentalBillingService;
use App\Services\Rental\RentalContractService;
use App\Services\Rental\RentalMaintenanceService;
use App\Services\Rental\RentalPdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Client portal — the customer's own rentals: contracts (with agreement PDF and
 * online signing), rental invoices (PDF) and maintenance requests.
 *
 * Everything is scoped to the rental profile(s) of the logged-in portal
 * client's client_id. Drafts and cancelled contracts are never shown.
 */
class PortalRentalsController extends Controller
{
    private const VISIBLE = ['active', 'ended', 'terminated', 'renewed'];

    public function __construct(private RentalBillingService $billing)
    {
        // SaaS: these portal routes sit outside tenant.feature (the portal has
        // no plan middleware), so a tenant without Rental Management on its
        // plan / not migrated answers 404 instead of touching rental tables.
        // summary() answers is_renter=false on its own (the nav probe).
        $this->middleware(function ($request, $next) {
            if (! self::moduleEnabled()) {
                return response()->json(['message' => 'Not found.'], 404);
            }

            return $next($request);
        })->except('summary');
    }

    /** Small summary for the portal: is this customer a renter at all, and what is owed. */
    public function summary(Request $request)
    {
        $ids = $this->customerIds();
        if (! $ids) {
            return response()->json(['is_renter' => false]);
        }
        $this->billing->applyLateFees();
        $open = RentalInvoice::with('contract')->whereIn('rental_customer_id', $ids)->whereIn('status', ['unpaid', 'partial'])->get();

        return response()->json([
            'is_renter' => true,
            'active_contracts' => RentalContract::whereNull('deleted_at')->whereIn('rental_customer_id', $ids)->where('status', 'active')->count(),
            'outstanding' => round($open->sum(fn ($i) => $i->balance()), 2),
            'overdue' => round($open->filter(fn ($i) => $i->isOverdue((int) optional($i->contract)->grace_days))->sum(fn ($i) => $i->balance()), 2),
            'unsigned' => RentalContract::whereNull('deleted_at')->whereIn('rental_customer_id', $ids)->where('status', 'active')->whereNull('signature')->count(),
        ]);
    }

    public function index(Request $request)
    {
        $ids = $this->customerIds();
        $soonDays = (int) \App\Models\RentalSetting::current()->expiring_soon_days;

        $query = RentalContract::with('items')->whereNull('deleted_at')->whereIn('rental_customer_id', $ids ?: [0])
            ->whereIn('status', self::VISIBLE)
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('search'), function ($q) use ($request) {
                $s = $request->search;
                $q->where(fn ($w) => $w->where('contract_number', 'LIKE', "%{$s}%")
                    ->orWhereHas('items', fn ($i) => $i->where('item_name', 'LIKE', "%{$s}%")));
            });

        $sort = in_array($request->sort, ['contract_number', 'start_date', 'end_date', 'total_amount'], true) ? $request->sort : 'start_date';
        $dir = strtolower((string) $request->dir) === 'asc' ? 'asc' : 'desc';
        $total = (clone $query)->count();
        $size = max(1, min(100, (int) ($request->input('limit') ?? $request->input('page_size') ?? 10)));
        $page = max(1, (int) ($request->page ?? 1));

        $rows = $query->orderBy($sort, $dir)->offset(($page - 1) * $size)->limit($size)->get();

        return response()->json([
            'contracts' => $rows->map(fn ($c) => [
                'id' => $c->id,
                'contract_number' => $c->contract_number,
                'items' => $c->items->map(fn ($l) => $l->item_name . ($l->quantity > 1 ? ' ×' . $l->quantity : ''))->implode(', '),
                'start_date' => optional($c->start_date)->toDateString(),
                'end_date' => optional($c->end_date)->toDateString(),
                'rate_period' => $c->rate_period,
                'billing_type' => $c->billing_type,
                'total_amount' => (float) $c->total_amount,
                'status' => $c->status,
                'stage' => $c->stage($soonDays),
                'signed' => (bool) $c->signature,
            ])->values(),
            'totalRows' => $total,
            'statuses' => self::VISIBLE,
        ]);
    }

    public function show(Request $request, $id)
    {
        $c = $this->findContract($id);
        $c->load('items.item');
        $this->billing->run($c->id);
        $soonDays = (int) \App\Models\RentalSetting::current()->expiring_soon_days;

        $invoices = RentalInvoice::where('rental_contract_id', $c->id)->where('status', '!=', 'void')->orderBy('due_date')->get();
        $payments = RentalPayment::with('method')->whereNull('deleted_at')->where('rental_contract_id', $c->id)->orderByDesc('payment_date')->get();
        $requests = RentalMaintenance::whereNull('deleted_at')->where('rental_contract_id', $c->id)->orderByDesc('created_at')->get();

        return response()->json([
            'id' => $c->id,
            'contract_number' => $c->contract_number,
            'status' => $c->status,
            'stage' => $c->stage($soonDays),
            'start_date' => optional($c->start_date)->toIso8601String(),
            'end_date' => optional($c->end_date)->toIso8601String(),
            'rate_period' => $c->rate_period,
            'billing_type' => $c->billing_type,
            'periods' => $c->periods,
            'rent_amount' => (float) $c->rent_amount,
            'tax_amount' => (float) $c->tax_amount,
            'total_amount' => (float) $c->total_amount,
            'contract_value' => $c->contract_value === null ? null : (float) $c->contract_value,
            'deposit_amount' => (float) $c->deposit_amount,
            'deposit_held' => $c->depositHeld(),
            'grace_days' => (int) $c->grace_days,
            'late_fee_type' => $c->late_fee_type,
            'late_fee_value' => (float) $c->late_fee_value,
            'late_fee_frequency' => $c->late_fee_frequency,
            'terms' => $c->terms,
            'signed' => (bool) $c->signature,
            'signed_name' => $c->signed_name,
            'signed_at' => optional($c->signed_at)->toIso8601String(),
            'can_sign' => $c->status === 'active' && ! $c->signature,
            'items' => $c->items->map(fn ($l) => [
                'id' => $l->id,
                'rental_item_id' => $l->rental_item_id,
                'item_name' => $l->item_name,
                'quantity' => (int) $l->quantity,
                'rate' => (float) $l->rate,
                'amount' => (float) $l->amount,
                'image_url' => $l->item ? $l->item->coverUrl() : null,
                'returned' => (bool) $l->returned_at,
            ])->values(),
            'invoices' => $invoices->map(fn ($i) => $this->presentInvoice($i, (int) $c->grace_days))->values(),
            'payments' => $payments->map(fn ($p) => [
                'id' => $p->id,
                'payment_number' => $p->payment_number,
                'payment_date' => optional($p->payment_date)->toDateString(),
                'amount' => (float) $p->amount,
                'method' => $p->source === 'deposit' ? 'deposit' : optional($p->method)->name,
            ])->values(),
            'requests' => $requests->map(fn ($m) => $this->presentRequest($m))->values(),
            'summary' => $this->billing->summary($invoices, (int) $c->grace_days),
        ]);
    }

    public function agreementPdf(Request $request, $id, RentalPdf $pdfs)
    {
        $c = $this->findContract($id);
        $pdf = $pdfs->agreement($c);

        return $pdf->stream('Rental_Agreement_' . $c->contract_number . '.pdf');
    }

    /** The customer signs the agreement on screen. Only active, unsigned contracts. */
    public function sign(Request $request, $id, RentalContractService $contracts)
    {
        $c = $this->findContract($id);
        if ($c->status !== 'active' || $c->signature) {
            return response()->json(['message' => __('portal.rental_cannot_sign')], 422);
        }
        $request->validate([
            'signed_name' => 'required|string|max:191',
            'signature' => 'required|string|max:2000000',
            'agree' => 'accepted',
        ]);
        if (! $contracts->saveSignature($c, $request->signed_name, $request->signature, 'portal')) {
            return response()->json(['message' => __('portal.rental_bad_signature')], 422);
        }

        return response()->json(['success' => true]);
    }

    public function invoices(Request $request)
    {
        $ids = $this->customerIds();
        $this->billing->applyLateFees();

        $query = RentalInvoice::with('contract')->whereIn('rental_customer_id', $ids ?: [0])->where('status', '!=', 'void')
            ->when($request->filled('status'), function ($q) use ($request) {
                $request->status === 'open' ? $q->whereIn('status', ['unpaid', 'partial']) : $q->where('status', $request->status);
            })
            ->when($request->filled('search'), fn ($q) => $q->where('invoice_number', 'LIKE', '%' . $request->search . '%'));

        $sort = in_array($request->sort, ['invoice_number', 'due_date', 'amount'], true) ? $request->sort : 'due_date';
        $dir = strtolower((string) $request->dir) === 'asc' ? 'asc' : 'desc';
        $all = (clone $query)->get();
        $size = max(1, min(100, (int) ($request->input('limit') ?? $request->input('page_size') ?? 10)));
        $page = max(1, (int) ($request->page ?? 1));
        $rows = $query->orderBy($sort, $dir)->orderByDesc('id')->offset(($page - 1) * $size)->limit($size)->get();

        return response()->json([
            'invoices' => $rows->map(fn ($i) => $this->presentInvoice($i, (int) optional($i->contract)->grace_days))->values(),
            'totalRows' => $all->count(),
            'outstanding' => round($all->sum(fn ($i) => $i->balance()), 2),
        ]);
    }

    public function invoicePdf(Request $request, $id, RentalPdf $pdfs)
    {
        $inv = RentalInvoice::whereIn('rental_customer_id', $this->customerIds() ?: [0])->where('status', '!=', 'void')->findOrFail($id);

        return $pdfs->invoice($inv)->stream('Rental_Invoice_' . $inv->invoice_number . '.pdf');
    }

    // ------------------------------------------------------------------
    // Maintenance requests
    // ------------------------------------------------------------------

    public function requests(Request $request)
    {
        $contractIds = RentalContract::whereIn('rental_customer_id', $this->customerIds() ?: [0])->pluck('id');
        $rows = RentalMaintenance::with('item')->whereNull('deleted_at')->whereIn('rental_contract_id', $contractIds)
            ->where('source', 'portal')->orderByDesc('created_at')->limit(200)->get();

        return response()->json([
            'requests' => $rows->map(fn ($m) => $this->presentRequest($m))->values(),
            // What the customer can report on: items of their active contracts.
            'options' => RentalContract::with('items')->whereNull('deleted_at')->whereIn('rental_customer_id', $this->customerIds() ?: [0])
                ->where('status', 'active')->get()
                ->map(fn ($c) => [
                    'id' => $c->id,
                    'contract_number' => $c->contract_number,
                    'items' => $c->items->map(fn ($l) => ['id' => $l->rental_item_id, 'name' => $l->item_name])->values(),
                ])->values(),
        ]);
    }

    public function storeRequest(Request $request, RentalMaintenanceService $maintenance)
    {
        $request->validate([
            'rental_contract_id' => 'required|integer',
            'rental_item_id' => 'required|integer',
            'title' => 'required|string|max:191',
            'description' => 'nullable|string|max:3000',
            'urgent' => 'nullable|boolean',
            'photos' => 'nullable|array|max:6',
            'photos.*' => 'image|max:8192',
        ]);
        $c = $this->findContract($request->rental_contract_id);
        if ($c->status !== 'active' || ! $c->items()->where('rental_item_id', $request->rental_item_id)->exists()) {
            return response()->json(['message' => __('portal.rental_request_not_allowed')], 422);
        }

        $photos = [];
        $files = (array) $request->file('photos', []);
        if ($files) {
            $dir = upload_public_path(RentalMaintenance::FILE_DIR);
            if (! file_exists($dir)) {
                mkdir($dir, 0755, true);
            }
            foreach (array_slice($files, 0, 6) as $file) {
                $name = Str::random(40) . '.' . \App\Services\Rental\RentalUpload::extension($file, 'image');
                $file->move($dir, $name);
                $photos[] = $name;
            }
        }

        $job = $maintenance->create([
            'rental_item_id' => (int) $request->rental_item_id,
            'rental_contract_id' => $c->id,
            'title' => $request->title,
            'type' => 'repair',
            'priority' => $request->boolean('urgent') ? 'urgent' : 'normal',
            'source' => 'portal',
            'description' => $request->description,
            'photos' => $photos,
            'created_by' => null,
        ]);
        app(RentalContractService::class)->log($c, 'portal_request', $request->title, ['job' => $job->number]);

        return response()->json(['success' => true, 'number' => $job->number]);
    }

    // ------------------------------------------------------------------

    /** Rental profile ids of the logged-in portal client (one client may have one profile). */
    private function customerIds(): array
    {
        $portalClient = Auth::guard('portal')->user();
        if ((int) $portalClient->status !== 1) {
            abort(403, __('portal.portal_disabled'));
        }
        // Rental module switched off in Settings -> Modules: the portal shows no rentals.
        if (! self::moduleEnabled()) {
            return [];
        }

        return RentalCustomer::where('client_id', $portalClient->client_id)->pluck('id')->all();
    }

    public static function moduleEnabled(): bool
    {
        $raw = \Illuminate\Support\Facades\DB::table('settings')->whereNull('deleted_at')->value('module_flags');
        $flags = $raw ? json_decode($raw, true) : null;

        if (is_array($flags) && array_key_exists('rental', $flags) && $flags['rental'] === false) {
            return false;
        }

        // SaaS: the tenant's plan must include Rental Management (and its
        // tables must be migrated).
        return \App\Services\Crm\CrmCaptureService::planAllows('rental')
            && \Illuminate\Support\Facades\Schema::hasTable('rental_customers');
    }

    private function findContract($id): RentalContract
    {
        return RentalContract::whereNull('deleted_at')->whereIn('rental_customer_id', $this->customerIds() ?: [0])
            ->whereIn('status', self::VISIBLE)->findOrFail($id);
    }

    private function presentInvoice(RentalInvoice $i, int $grace): array
    {
        return [
            'id' => $i->id,
            'invoice_number' => $i->invoice_number,
            'contract_number' => optional($i->contract)->contract_number,
            'rental_contract_id' => $i->rental_contract_id,
            'description' => $i->kind === 'charge' ? $i->description : null,
            'period_start' => optional($i->period_start)->toDateString(),
            'period_end' => optional($i->period_end)->toDateString(),
            'issue_date' => optional($i->issue_date)->toDateString(),
            'due_date' => optional($i->due_date)->toDateString(),
            'amount' => (float) $i->amount,
            'late_fee' => $i->effectiveLateFee(),
            'total' => $i->total(),
            'paid' => (float) $i->paid_amount,
            'balance' => $i->balance(),
            'status' => $i->status,
            'overdue' => $i->isOverdue($grace),
        ];
    }

    private function presentRequest(RentalMaintenance $m): array
    {
        return [
            'id' => $m->id,
            'number' => $m->number,
            'title' => $m->title,
            'item_name' => optional($m->item)->name,
            'status' => $m->status,
            'priority' => $m->priority,
            'created_at' => optional($m->created_at)->toDateString(),
            'completed_at' => optional($m->completed_at)->toDateString(),
            'from_portal' => $m->source === 'portal',
        ];
    }
}
