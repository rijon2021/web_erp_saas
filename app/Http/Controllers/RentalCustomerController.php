<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\RentalContract;
use App\Models\RentalInvoice;
use App\Services\Rental\RentalBillingService;
use App\Models\RentalCustomer;
use App\Models\RentalCustomerDocument;
use App\Models\RentalSetting;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Rental customers — ordinary Stocky clients plus a rental profile.
 *
 * The client keeps name / phone / email / address (so sales, balances, SMS and
 * the portal all keep working off one record); `rental_customers` holds the
 * identity document, work, emergency contact, guarantor and blacklist flag.
 * Deleting a rental customer removes the profile only — never the client.
 *
 * Saves are multipart POSTs (ID scans ride along), so edit posts to
 * rental/customers/{id}/update.
 */
class RentalCustomerController extends Controller
{
    private const SORTABLE = [
        'name' => 'clients.name',
        'id_expiry' => 'rental_customers.id_expiry',
        'rating' => 'rental_customers.rating',
        'created_at' => 'rental_customers.created_at',
    ];

    private const ID_FILE_RULE = 'nullable|file|mimes:jpg,jpeg,png,webp,pdf|max:5120';
    private const DOC_FILE_RULE = 'required|file|mimes:pdf,jpg,jpeg,png,webp,doc,docx,xls,xlsx,txt|max:10240';

    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', RentalCustomer::class);

        $perPage = (int) ($request->limit ?? 10);
        $page = max(1, (int) $request->get('page', 1));
        $order = self::SORTABLE[$request->SortField] ?? 'rental_customers.created_at';
        $dir = strtolower((string) $request->SortType) === 'asc' ? 'asc' : 'desc';

        $soonDays = (int) RentalSetting::current()->expiring_soon_days;
        $today = Carbon::today()->toDateString();
        $soon = Carbon::today()->addDays($soonDays)->toDateString();

        $query = $this->baseQuery();

        if ($request->get('standing') === 'blacklisted') {
            $query->where('rental_customers.is_blacklisted', true);
        } elseif ($request->get('standing') === 'good') {
            $query->where('rental_customers.is_blacklisted', false);
        }

        switch ($request->get('id_status')) {
            case 'expired':
                $query->whereDate('rental_customers.id_expiry', '<', $today);
                break;
            case 'expiring':
                $query->whereDate('rental_customers.id_expiry', '>=', $today)
                    ->whereDate('rental_customers.id_expiry', '<=', $soon);
                break;
            case 'attention':
                $query->whereDate('rental_customers.id_expiry', '<=', $soon);
                break;
            case 'missing':
                $query->whereNull('rental_customers.id_number')->whereNull('rental_customers.id_expiry');
                break;
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('clients.name', 'LIKE', "%{$search}%")
                    ->orWhere('clients.phone', 'LIKE', "%{$search}%")
                    ->orWhere('clients.email', 'LIKE', "%{$search}%")
                    ->orWhere('rental_customers.id_number', 'LIKE', "%{$search}%")
                    ->orWhere('rental_customers.guarantor_name', 'LIKE', "%{$search}%");
            });
        }

        $totalRows = (clone $query)->count();
        if ($perPage === -1) {
            $perPage = max(1, $totalRows);
        }

        $rows = $query->withCount('documents')
            ->orderBy($order, $dir)
            ->offset(($page * $perPage) - $perPage)
            ->limit($perPage)
            ->get();

        // Header counts are over every rental customer, not the filtered page.
        $all = $this->baseQuery();

        return response()->json([
            'totalRows' => $totalRows,
            'customers' => $rows->map(fn ($c) => $this->present($c, $soonDays))->values(),
            'summary' => [
                'total' => (clone $all)->count(),
                'blacklisted' => (clone $all)->where('rental_customers.is_blacklisted', true)->count(),
                'id_attention' => (clone $all)->whereDate('rental_customers.id_expiry', '<=', $soon)->count(),
            ],
        ]);
    }

    /**
     * Type-ahead over existing clients for "make this client a rental
     * customer". Clients that already have a live profile come back flagged.
     */
    public function clientSearch(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', RentalCustomer::class);

        $search = trim((string) $request->get('search', ''));

        $clients = Client::whereNull('deleted_at')
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($inner) use ($search) {
                    $inner->where('name', 'LIKE', "%{$search}%")
                        ->orWhere('phone', 'LIKE', "%{$search}%")
                        ->orWhere('email', 'LIKE', "%{$search}%")
                        ->orWhere('code', 'LIKE', "%{$search}%");
                });
            })
            ->orderBy('name')->limit(20)
            ->get(['id', 'name', 'code', 'phone', 'email', 'city']);

        $taken = RentalCustomer::whereNull('deleted_at')
            ->whereIn('client_id', $clients->pluck('id'))
            ->pluck('id', 'client_id');

        return response()->json([
            'clients' => $clients->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'code' => $c->code,
                'phone' => $c->phone,
                'email' => $c->email,
                'city' => $c->city,
                'rental_customer_id' => $taken[$c->id] ?? null,
            ])->values(),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', RentalCustomer::class);

        $isNew = $request->input('client_mode') === 'new';

        $request->validate(array_merge(
            ['client_mode' => 'required|in:existing,new'],
            $isNew ? $this->clientRules(null) : ['client_id' => 'required|integer|exists:clients,id'],
            $this->profileRules()
        ), [], $this->attributes());

        if (! $isNew) {
            $live = RentalCustomer::whereNull('deleted_at')->where('client_id', $request->client_id)->first();
            if ($live) {
                return response()->json([
                    'message' => 'This client is already a rental customer.',
                    'errors' => ['client_id' => ['This client is already a rental customer.']],
                    'rental_customer_id' => $live->id,
                ], 422);
            }
        }

        $customer = DB::transaction(function () use ($request, $isNew) {
            $clientId = $isNew
                ? $this->createClient($request)->id
                : (int) $request->client_id;

            $data = $this->profilePayload($request);
            $data['client_id'] = $clientId;
            $data['created_by'] = optional($request->user('api'))->id;
            if ($data['is_blacklisted']) {
                $data['blacklisted_at'] = now();
                $data['blacklisted_by'] = optional($request->user('api'))->id;
            }

            // A client removed from rentals earlier gets their old profile back.
            $customer = RentalCustomer::onlyTrashed()->where('client_id', $clientId)->latest('id')->first();
            if ($customer) {
                $customer->restore();
                $customer->update($data);
            } else {
                $customer = RentalCustomer::create($data);
            }

            $this->syncIdFiles($request, $customer);

            return $customer;
        });

        return response()->json(['success' => true, 'id' => $customer->id]);
    }

    public function show(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'view', RentalCustomer::class);

        $customer = RentalCustomer::with('client')->whereNull('deleted_at')->findOrFail($id);
        $soonDays = (int) RentalSetting::current()->expiring_soon_days;

        return response()->json(['customer' => array_merge(
            $this->present($customer, $soonDays),
            $this->details($customer),
            [
                'documents' => $customer->documents()->orderBy('created_at', 'desc')->get()
                    ->map(fn ($d) => $this->presentDocument($d))->values(),
                'billing' => $this->billingSummary($customer->id),
                'contracts' => RentalContract::with('items')->whereNull('deleted_at')
                    ->where('rental_customer_id', $customer->id)
                    ->orderBy('start_date', 'desc')->limit(100)->get()
                    ->map(fn ($c) => [
                        'id' => $c->id,
                        'contract_number' => $c->contract_number,
                        'status' => $c->status,
                        'stage' => $c->stage($soonDays),
                        'start_date' => optional($c->start_date)->toIso8601String(),
                        'end_date' => optional($c->end_date)->toIso8601String(),
                        'rate_period' => $c->rate_period,
                        'total_amount' => (float) $c->total_amount,
                        'contract_value' => $c->contract_value === null ? null : (float) $c->contract_value,
                        'items_summary' => $c->items->pluck('item_name')->values(),
                    ])->values(),
            ]
        )]);
    }

    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', RentalCustomer::class);

        $customer = RentalCustomer::whereNull('deleted_at')->findOrFail($id);

        $request->validate(array_merge(
            $this->clientRules($customer->client_id),
            $this->profileRules()
        ), [], $this->attributes());

        DB::transaction(function () use ($request, $customer) {
            Client::whereKey($customer->client_id)->update($this->clientPayload($request));

            $data = $this->profilePayload($request);
            if ($data['is_blacklisted'] && ! $customer->is_blacklisted) {
                $data['blacklisted_at'] = now();
                $data['blacklisted_by'] = optional($request->user('api'))->id;
            } elseif (! $data['is_blacklisted']) {
                $data['blacklist_reason'] = null;
                $data['blacklisted_at'] = null;
                $data['blacklisted_by'] = null;
            }
            $customer->update($data);

            $this->syncIdFiles($request, $customer);
        });

        return response()->json(['success' => true, 'id' => $customer->id]);
    }

    /** Removes the rental profile only; the client and their history stay. */
    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', RentalCustomer::class);

        $customer = RentalCustomer::whereNull('deleted_at')->findOrFail($id);
        $this->guardNoOpenContracts([$customer->id]);
        $customer->delete();

        return response()->json(['success' => true]);
    }

    public function deleteBySelection(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'delete', RentalCustomer::class);

        $ids = (array) $request->selectedIds;
        $this->guardNoOpenContracts($ids);
        RentalCustomer::whereIn('id', $ids)->delete();

        return response()->json(['success' => true, 'count' => count($ids)]);
    }

    /** Blacklist / clear in one call; a reason is required to blacklist. */
    public function blacklist(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', RentalCustomer::class);

        $customer = RentalCustomer::whereNull('deleted_at')->findOrFail($id);
        $request->validate([
            'blacklisted' => 'required|boolean',
            'reason' => 'required_if:blacklisted,1,true|nullable|string|max:2000',
        ], ['reason.required_if' => 'Please give a reason for blacklisting this customer.']);

        if ($request->boolean('blacklisted')) {
            $customer->update([
                'is_blacklisted' => true,
                'blacklist_reason' => $request->reason,
                'blacklisted_at' => now(),
                'blacklisted_by' => optional($request->user('api'))->id,
            ]);
        } else {
            $customer->update([
                'is_blacklisted' => false,
                'blacklist_reason' => null,
                'blacklisted_at' => null,
                'blacklisted_by' => null,
            ]);
        }

        return response()->json(['success' => true]);
    }

    // ------------------------------------------------------------------
    // Documents
    // ------------------------------------------------------------------

    public function storeDocument(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', RentalCustomer::class);

        $customer = RentalCustomer::whereNull('deleted_at')->findOrFail($id);
        $request->validate([
            'file' => self::DOC_FILE_RULE,
            'title' => 'nullable|string|max:191',
        ]);

        $file = $request->file('file');
        $original = $file->getClientOriginalName();
        $mime = $file->getClientMimeType();
        $size = (int) $file->getSize();
        $stored = $this->storeFile($file);

        $doc = RentalCustomerDocument::create([
            'rental_customer_id' => $customer->id,
            'title' => trim((string) $request->title) ?: pathinfo($original, PATHINFO_FILENAME),
            'file_name' => $stored,
            'original_name' => Str::limit($original, 185, ''),
            'mime' => $mime,
            'size' => $size,
            'uploaded_by' => optional($request->user('api'))->id,
        ]);

        return response()->json(['success' => true, 'document' => $this->presentDocument($doc)]);
    }

    public function destroyDocument(Request $request, $id, $documentId)
    {
        $this->authorizeForUser($request->user('api'), 'update', RentalCustomer::class);

        $doc = RentalCustomerDocument::where('rental_customer_id', $id)->findOrFail($documentId);
        $this->deleteFile($doc->file_name);
        $doc->delete();

        return response()->json(['success' => true]);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** What this customer owes across all their contracts. */
    private function billingSummary(int $customerId): array
    {
        $billing = app(RentalBillingService::class);
        $billing->applyLateFees();
        $invoices = RentalInvoice::with('contract')->where('rental_customer_id', $customerId)->get();

        return $billing->summary($invoices);
    }

    private function guardNoOpenContracts(array $ids): void
    {
        $numbers = RentalContract::whereNull('deleted_at')
            ->whereIn('status', ['draft', 'active'])
            ->whereIn('rental_customer_id', $ids)
            ->limit(5)->pluck('contract_number');

        if ($numbers->isNotEmpty()) {
            abort(response()->json([
                'message' => 'This customer has open contracts (' . $numbers->implode(', ') . '). End or cancel them first.',
            ], 422));
        }
        $holds = \App\Models\RentalReservation::whereNull('deleted_at')->where('status', 'held')
            ->whereIn('rental_customer_id', $ids)->limit(5)->pluck('reservation_number');
        if ($holds->isNotEmpty()) {
            abort(response()->json([
                'message' => 'This customer has held reservations (' . $holds->implode(', ') . '). Cancel or convert them first.',
            ], 422));
        }
        if (RentalInvoice::whereIn('rental_customer_id', $ids)->whereIn('status', ['unpaid', 'partial'])->exists()) {
            abort(response()->json([
                'message' => 'This customer still owes money on rental invoices. Settle or void them first.',
            ], 422));
        }
    }

    private function baseQuery()
    {
        return RentalCustomer::query()
            ->join('clients', 'clients.id', '=', 'rental_customers.client_id')
            ->whereNull('rental_customers.deleted_at')
            ->whereNull('clients.deleted_at')
            ->select(
                'rental_customers.*',
                'clients.name as client_name',
                'clients.code as client_code',
                'clients.phone as client_phone',
                'clients.email as client_email',
                'clients.city as client_city'
            );
    }

    /** The row shape the list and the detail header share. */
    private function present(RentalCustomer $c, int $soonDays): array
    {
        $client = $c->relationLoaded('client') ? $c->client : null;

        return [
            'id' => $c->id,
            'client_id' => $c->client_id,
            'name' => $client ? $client->name : $c->client_name,
            'client_code' => $client ? $client->code : $c->client_code,
            'phone' => $client ? $client->phone : $c->client_phone,
            'email' => $client ? $client->email : $c->client_email,
            'city' => $client ? $client->city : $c->client_city,
            'id_type' => $c->id_type,
            'id_number' => $c->id_number,
            'id_expiry' => optional($c->id_expiry)->toDateString(),
            'id_status' => $c->idStatus($soonDays),
            'id_days_left' => $c->id_expiry ? (int) round(Carbon::today()->diffInDays($c->id_expiry, false)) : null,
            'is_blacklisted' => (bool) $c->is_blacklisted,
            'rating' => $c->rating,
            'guarantor_name' => $c->guarantor_name,
            'documents_count' => (int) ($c->documents_count ?? 0),
            'created_at' => optional($c->created_at)->toIso8601String(),
        ];
    }

    private function details(RentalCustomer $c): array
    {
        $client = $c->client;

        return [
            'adresse' => optional($client)->adresse,
            'country' => optional($client)->country,
            'id_front' => $c->id_front,
            'id_back' => $c->id_back,
            'id_front_url' => RentalCustomer::fileUrl($c->id_front),
            'id_back_url' => RentalCustomer::fileUrl($c->id_back),
            'date_of_birth' => optional($c->date_of_birth)->toDateString(),
            'nationality' => $c->nationality,
            'occupation' => $c->occupation,
            'employer' => $c->employer,
            'employer_phone' => $c->employer_phone,
            'monthly_income' => $c->monthly_income === null ? null : (float) $c->monthly_income,
            'emergency_name' => $c->emergency_name,
            'emergency_phone' => $c->emergency_phone,
            'emergency_relation' => $c->emergency_relation,
            'guarantor_phone' => $c->guarantor_phone,
            'guarantor_id_number' => $c->guarantor_id_number,
            'guarantor_relation' => $c->guarantor_relation,
            'guarantor_address' => $c->guarantor_address,
            'blacklist_reason' => $c->blacklist_reason,
            'blacklisted_at' => optional($c->blacklisted_at)->toIso8601String(),
            'notes' => $c->notes,
        ];
    }

    private function presentDocument(RentalCustomerDocument $d): array
    {
        return [
            'id' => $d->id,
            'title' => $d->title,
            'original_name' => $d->original_name,
            'mime' => $d->mime,
            'size' => (int) $d->size,
            'url' => RentalCustomer::fileUrl($d->file_name),
            'created_at' => optional($d->created_at)->toIso8601String(),
        ];
    }

    private function clientRules($clientId): array
    {
        $ecommerceId = $clientId
            ? DB::table('ecommerce_clients')->where('client_id', $clientId)->whereNull('deleted_at')->value('id')
            : null;

        return [
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:50',
            'email' => [
                'nullable', 'email', 'max:255',
                Rule::unique('clients', 'email')->ignore($clientId)->whereNull('deleted_at'),
                Rule::unique('ecommerce_clients', 'email')->ignore($ecommerceId)->whereNull('deleted_at'),
            ],
            'adresse' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:100',
            'country' => 'nullable|string|max:100',
        ];
    }

    private function profileRules(): array
    {
        return [
            'id_type' => ['nullable', Rule::in(RentalCustomer::ID_TYPES)],
            'id_number' => 'nullable|string|max:100',
            'id_expiry' => 'nullable|date',
            'date_of_birth' => 'nullable|date|before:today',
            'nationality' => 'nullable|string|max:100',
            'occupation' => 'nullable|string|max:150',
            'employer' => 'nullable|string|max:191',
            'employer_phone' => 'nullable|string|max:50',
            'monthly_income' => 'nullable|numeric|min:0',
            'emergency_name' => 'nullable|string|max:191',
            'emergency_phone' => 'nullable|string|max:50',
            'emergency_relation' => 'nullable|string|max:100',
            'guarantor_name' => 'nullable|string|max:191',
            'guarantor_phone' => 'nullable|string|max:50',
            'guarantor_id_number' => 'nullable|string|max:100',
            'guarantor_relation' => 'nullable|string|max:100',
            'guarantor_address' => 'nullable|string|max:255',
            'rating' => 'nullable|integer|min:1|max:5',
            'blacklist_reason' => 'nullable|string|max:2000',
            'notes' => 'nullable|string|max:5000',
            'id_front_file' => self::ID_FILE_RULE,
            'id_back_file' => self::ID_FILE_RULE,
        ];
    }

    private function attributes(): array
    {
        return [
            'client_id' => 'customer',
            'adresse' => 'address',
            'id_front_file' => 'ID front',
            'id_back_file' => 'ID back',
        ];
    }

    private function clientPayload(Request $request): array
    {
        return [
            'name' => $request->name,
            'phone' => $request->phone,
            'email' => $request->email ?: null,
            'adresse' => $request->adresse,
            'city' => $request->city,
            'country' => $request->country,
        ];
    }

    /** Same numbering as ClientController::getNumberOrder. */
    private function createClient(Request $request): Client
    {
        // SaaS: a new customer counts against the plan's max_customers.
        \App\Support\PlanLimitGuard::abortIfReached('max_customers');
        $last = DB::table('clients')->latest('id')->first();

        return Client::create(array_merge($this->clientPayload($request), [
            'code' => $last ? $last->code + 1 : 1,
            'is_royalty_eligible' => 0,
            'opening_balance' => 0,
            'credit_limit' => 0,
        ]));
    }

    private function profilePayload(Request $request): array
    {
        $val = fn ($key) => ($request->{$key} === null || $request->{$key} === '') ? null : $request->{$key};
        $blacklisted = $request->boolean('is_blacklisted');

        return [
            'id_type' => $val('id_type'),
            'id_number' => $val('id_number'),
            'id_expiry' => $val('id_expiry'),
            'date_of_birth' => $val('date_of_birth'),
            'nationality' => $val('nationality'),
            'occupation' => $val('occupation'),
            'employer' => $val('employer'),
            'employer_phone' => $val('employer_phone'),
            'monthly_income' => $val('monthly_income'),
            'emergency_name' => $val('emergency_name'),
            'emergency_phone' => $val('emergency_phone'),
            'emergency_relation' => $val('emergency_relation'),
            'guarantor_name' => $val('guarantor_name'),
            'guarantor_phone' => $val('guarantor_phone'),
            'guarantor_id_number' => $val('guarantor_id_number'),
            'guarantor_relation' => $val('guarantor_relation'),
            'guarantor_address' => $val('guarantor_address'),
            'rating' => $val('rating'),
            'is_blacklisted' => $blacklisted,
            'blacklist_reason' => $blacklisted ? $val('blacklist_reason') : null,
            'notes' => $val('notes'),
        ];
    }

    /** Replace or remove the two ID scans. */
    private function syncIdFiles(Request $request, RentalCustomer $customer): void
    {
        $changes = [];
        foreach (['id_front', 'id_back'] as $field) {
            if ($request->hasFile($field . '_file')) {
                $this->deleteFile($customer->{$field});
                $changes[$field] = $this->storeFile($request->file($field . '_file'));
            } elseif ($request->boolean('remove_' . $field)) {
                $this->deleteFile($customer->{$field});
                $changes[$field] = null;
            }
        }
        if ($changes) {
            $customer->update($changes);
        }
    }

    /** Random, unguessable names — these are identity documents. */
    private function storeFile($file): string
    {
        $path = upload_public_path(RentalCustomer::FILE_DIR);
        if (! file_exists($path)) {
            mkdir($path, 0755, true);
        }

        $extension = \App\Services\Rental\RentalUpload::extension($file, 'document');
        $filename = Str::random(40) . '.' . $extension;
        $file->move($path, $filename);

        return $filename;
    }

    private function deleteFile($filename): void
    {
        if (! $filename || ! is_string($filename) || str_contains($filename, '/') || str_contains($filename, '\\')) {
            return;
        }
        $path = upload_public_path(RentalCustomer::FILE_DIR . '/' . $filename);
        if (file_exists($path)) {
            @unlink($path);
        }
    }
}
