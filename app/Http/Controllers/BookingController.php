<?php

namespace App\Http\Controllers;

use App\Mail\CustomEmail;
use App\Models\Booking;
use App\Models\Client;
use App\Models\EmailMessage;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Tray;
use App\Models\User;
use App\utils\helpers;
use ArPHP\I18N\Arabic;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use PDF;

class BookingController extends BaseController
{
    /**
     * List bookings with optional filters.
     *
     * Query params:
     * - page, limit, SortField, SortType, search
     * - status: pending|confirmed|cancelled|completed
     * - date: YYYY-MM-DD (booking_date)
     * - from, to: optional date range (YYYY-MM-DD) for calendar views
     */
    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Booking::class);
        $perPage = $request->limit ?: 10;
        $pageStart = (int) $request->get('page', 1);
        $offSet = ($pageStart * $perPage) - $perPage;

        $order = $request->SortField ?: 'id';
        $dir = strtolower((string) $request->input('SortType')) === 'asc' ? 'asc' : 'desc';
        if (! in_array($dir, ['asc', 'desc'], true)) {
            $dir = 'desc';
        }

        $sortableMap = [
            'id' => 'bookings.id',
            'booking_date' => 'bookings.booking_date',
            'booking_time' => 'bookings.booking_time',
            'status' => 'bookings.status',
        ];
        $order = $sortableMap[$order] ?? 'bookings.id';

        $query = Booking::leftJoin('clients', 'clients.id', '=', 'bookings.customer_id')
            ->leftJoin('products', 'products.id', '=', 'bookings.product_id')
            ->whereNull('bookings.deleted_at')
            ->select(
                'bookings.*',
                'clients.name as customer_name',
                'products.name as service_name'
            )
            // Booking Management: a branch-limited user sees their branches' bookings (and shared ones).
            ->when(\App\Models\BookingSetting::tablesReady(), fn ($q) => $this->scopeToWarehouses($q, 'bookings.warehouse_id', true))
            ->when($request->filled('status'), function ($q) use ($request) {
                $q->where('bookings.status', $request->status);
            })
            ->when($request->filled('date'), function ($q) use ($request) {
                $q->whereDate('bookings.booking_date', $request->date);
            })
            ->when($request->filled('from'), function ($q) use ($request) {
                $q->whereDate('bookings.booking_date', '>=', $request->from);
            })
            ->when($request->filled('to'), function ($q) use ($request) {
                $q->whereDate('bookings.booking_date', '<=', $request->to);
            })
            // Booking Management filters (columns from migration 2026_10_25_000003)
            ->when(\App\Models\BookingSetting::tablesReady() && $request->filled('source'), function ($q) use ($request) {
                $q->where('bookings.source', $request->source === 'website' ? 'website' : 'staff');
            })
            ->when(\App\Models\BookingSetting::tablesReady() && $request->filled('staff_id'), function ($q) use ($request) {
                $q->where('bookings.staff_id', (int) $request->staff_id);
            })
            ->when($request->filled('search'), function ($q) use ($request) {
                $s = $request->search;

                $q->where(function ($sub) use ($s) {
                    $sub->where('clients.name', 'LIKE', "%{$s}%")
                        ->orWhere('products.name', 'LIKE', "%{$s}%")
                        ->orWhere('bookings.product_name', 'LIKE', "%{$s}%")
                        ->orWhere('bookings.notes', 'LIKE', "%{$s}%")
                        ->orWhere('bookings.Ref', 'LIKE', "%{$s}%");
                });
            });

        $totalRows = $query->count();
        if ($perPage == '-1') {
            $perPage = $totalRows;
        }

        $rows = $query->offset($offSet)
            ->limit($perPage)
            ->orderBy($order, $dir)
            ->get();

        // Summary stats for the header cards. Respect date/search filters but
        // ignore the status filter so the breakdown always shows every status.
        $statsQuery = Booking::leftJoin('clients', 'clients.id', '=', 'bookings.customer_id')
            ->leftJoin('products', 'products.id', '=', 'bookings.product_id')
            ->whereNull('bookings.deleted_at')
            ->when($request->filled('date'), function ($q) use ($request) {
                $q->whereDate('bookings.booking_date', $request->date);
            })
            ->when($request->filled('from'), function ($q) use ($request) {
                $q->whereDate('bookings.booking_date', '>=', $request->from);
            })
            ->when($request->filled('to'), function ($q) use ($request) {
                $q->whereDate('bookings.booking_date', '<=', $request->to);
            })
            ->when($request->filled('search'), function ($q) use ($request) {
                $s = $request->search;
                $q->where(function ($sub) use ($s) {
                    $sub->where('clients.name', 'LIKE', "%{$s}%")
                        ->orWhere('products.name', 'LIKE', "%{$s}%")
                        ->orWhere('bookings.product_name', 'LIKE', "%{$s}%")
                        ->orWhere('bookings.notes', 'LIKE', "%{$s}%");
                });
            });

        $statusCounts = (clone $statsQuery)
            ->select('bookings.status', DB::raw('count(*) as cnt'))
            ->groupBy('bookings.status')
            ->pluck('cnt', 'status');

        $stats = [
            'total' => (int) $statusCounts->sum(),
            'pending' => (int) ($statusCounts['pending'] ?? 0),
            'confirmed' => (int) ($statusCounts['confirmed'] ?? 0),
            'completed' => (int) ($statusCounts['completed'] ?? 0),
            'cancelled' => (int) ($statusCounts['cancelled'] ?? 0),
            'revenue' => (float) (clone $statsQuery)
                ->whereIn('bookings.status', ['confirmed', 'completed'])
                ->sum('bookings.price'),
        ];

        $staffNames = \App\Models\BookingStaff::tablesReady()
            ? \App\Models\BookingStaff::withTrashed()->whereIn('id', $rows->pluck('staff_id')->filter()->unique()->all())->pluck('name', 'id')
            : collect();

        $data = [];
        foreach ($rows as $booking) {
            $item['id'] = $booking->id;
            $item['Ref'] = $booking->Ref;
            $item['customer_id'] = $booking->customer_id;
            $item['customer_name'] = $booking->customer_name;
            $item['product_id'] = $booking->product_id;
            // Prefer the free-text product name, fall back to the service product name.
            $item['product_name'] = $booking->product_name ?: $booking->service_name;
            $item['tray_id'] = $booking->tray_id;
            $item['sales_person_id'] = $booking->sales_person_id;
            $item['price'] = $booking->price;
            $item['amount'] = $booking->amount;
            $item['booking_date'] = $booking->booking_date;
            $item['booking_time'] = $booking->booking_time;
            $item['booking_end_time'] = $booking->booking_end_time;
            $item['delivery_date'] = $booking->delivery_date;
            $item['delivery_time'] = $booking->delivery_time;
            $item['delivery_address'] = $booking->delivery_address;
            $item['status'] = $booking->status;
            $item['notes'] = $booking->notes;
            // Booking Management (null before its migrations)
            $item['staff_id'] = $booking->staff_id;
            $item['staff_name'] = $booking->staff_id ? ($staffNames[$booking->staff_id] ?? null) : null;
            $item['source'] = $booking->source ?? 'staff';
            $item['booking_mode'] = $booking->booking_mode;
            $item['sale_id'] = $booking->sale_id;

            $data[] = $item;
        }

        return response()->json([
            'totalRows' => $totalRows,
            'bookings' => $data,
            'stats' => $stats,
            // Booking Management: the quick view (details + actions) needs migration 2026_10_25_000006.
            'workflow_ready' => Booking::workflowReady(),
        ]);
    }

    /**
     * Metadata for create form: customers, service products, statuses.
     */
    public function create(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', Booking::class);
        $customers = Client::whereNull('deleted_at')
            ->orderBy('name', 'asc')
            ->get(['id', 'name']);

        $products = $this->withDurations(Product::whereNull('deleted_at')
            ->where('type', 'is_service')
            ->orderBy('name', 'asc')
            ->get(['id', 'name', 'code', 'price']));

        return response()->json([
            'customers' => $customers,
            'products' => $products,
            'trays' => $this->getTrays(),
            'sales_persons' => $this->getSalesPersons(),
            'statuses' => Booking::workflowReady() ? Booking::STATUSES : ['pending', 'confirmed', 'cancelled', 'completed'],
            // Booking Management: bookable staff for the form (empty before its migrations)
            'staff' => \App\Models\BookingStaff::tablesReady()
                ? \App\Models\BookingStaff::query()->active()->orderBy('sort_order')->orderBy('name')->get(['id', 'name', 'color'])
                : [],
        ]);
    }

    /**
     * Active trays for the booking form dropdown.
     */
    /**
     * Adds each service's booking length (Booking Management › Services) as
     * `duration_minutes` (null when not set up) so the form can fill the end time.
     */
    private function withDurations($products)
    {
        $durations = \App\Models\BookingServiceDetail::durations($products->pluck('id')->all());

        return $products->map(fn ($p) => $p->toArray() + ['duration_minutes' => $durations[$p->id] ?? null])->values();
    }

    private function getTrays()
    {
        return Tray::whereNull('deleted_at')
            ->orderBy('name', 'asc')
            ->get(['id', 'name']);
    }

    /**
     * Active users (staff) for the Sales Person dropdown.
     */
    private function getSalesPersons()
    {
        return User::whereNull('deleted_at')
            ->where('statut', 1)
            ->orderBy('firstname', 'asc')
            ->orderBy('lastname', 'asc')
            ->get(['id', 'firstname', 'lastname', 'username'])
            ->map(function ($user) {
                $name = trim(($user->firstname ?? '').' '.($user->lastname ?? ''));

                return [
                    'id' => $user->id,
                    'name' => $name !== '' ? $name : $user->username,
                ];
            });
    }

    /**
     * Store a new booking.
     */
    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', Booking::class);
        // Normalize time formats before validation - handle H:i:s format and empty strings
        $requestData = $request->all();
        
        // Normalize booking_time (H:i:s to H:i)
        if (isset($requestData['booking_time']) && $requestData['booking_time'] !== null) {
            if (preg_match('/^(\d{2}:\d{2}):\d{2}$/', $requestData['booking_time'], $matches)) {
                $requestData['booking_time'] = $matches[1];
            }
        }
        
        // Normalize booking_end_time - handle empty strings and H:i:s format
        if (isset($requestData['booking_end_time']) && $requestData['booking_end_time'] === '') {
            $requestData['booking_end_time'] = null;
        } elseif (isset($requestData['booking_end_time']) && $requestData['booking_end_time'] !== null) {
            // If it's in H:i:s format, convert to H:i
            if (preg_match('/^(\d{2}:\d{2}):\d{2}$/', $requestData['booking_end_time'], $matches)) {
                $requestData['booking_end_time'] = $matches[1];
            }
        }

        // Normalize delivery_time - handle empty strings and H:i:s format
        if (isset($requestData['delivery_time']) && $requestData['delivery_time'] === '') {
            $requestData['delivery_time'] = null;
        } elseif (isset($requestData['delivery_time']) && $requestData['delivery_time'] !== null) {
            if (preg_match('/^(\d{2}:\d{2}):\d{2}$/', $requestData['delivery_time'], $matches)) {
                $requestData['delivery_time'] = $matches[1];
            }
        }

        $validated = validator($requestData, [
            'customer_id' => 'required|integer|exists:clients,id',
            'product_id' => 'nullable|integer|exists:products,id',
            'tray_id' => 'nullable|integer|exists:trays,id',
            'sales_person_id' => 'nullable|integer|exists:users,id',
            'product_name' => 'nullable|string|max:255',
            'product_description' => 'nullable|string',
            'price' => 'nullable|numeric|min:0',
            'amount' => 'nullable|numeric|min:0',
            'booking_date' => 'required|date',
            'booking_time' => 'required|date_format:H:i',
            'booking_end_time' => 'nullable|date_format:H:i',
            'delivery_date' => 'nullable|date',
            'delivery_time' => 'nullable|date_format:H:i',
            'delivery_address' => 'nullable|string',
            'status' => 'required|string|in:'.implode(',', Booking::STATUSES),
            'notes' => 'nullable|string',
        ])->validate();

        // Check and save under the staff member's lock, so two saves can't take the same time.
        return \App\Services\Booking\BookingTx::run(function () use ($request, $validated) {
            $validated = $this->withStaffCheck($request, $validated);
            if ($validated instanceof \Illuminate\Http\JsonResponse) {
                return $validated;
            }

            // Generate reference number
            $validated['Ref'] = $this->getNumberOrder();

            $booking = Booking::create($validated);

            return response()->json(['success' => true, 'id' => $booking->id], 201);
        });
    }

    /**
     * Show a single booking with relations.
     */
    public function show(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'view', Booking::class);
        $booking = Booking::with(['customer', 'product', 'tray', 'salesPerson'])
            ->whereNull('deleted_at')
            ->findOrFail($id);
        $this->guardBranch($booking);

        $salesPerson = $booking->salesPerson;
        $salesPersonName = null;
        if ($salesPerson) {
            $salesPersonName = trim(($salesPerson->firstname ?? '').' '.($salesPerson->lastname ?? ''));
            $salesPersonName = $salesPersonName !== '' ? $salesPersonName : $salesPerson->username;
        }

        return response()->json([
            'booking' => [
                'id' => $booking->id,
                'Ref' => $booking->Ref,
                'customer_id' => $booking->customer_id,
                'customer_name' => optional($booking->customer)->name,
                'product_id' => $booking->product_id,
                // Service product name (legacy dropdown)
                'service_name' => optional($booking->product)->name,
                // Free-text product details
                'product_name' => $booking->product_name,
                'product_description' => $booking->product_description,
                'tray_id' => $booking->tray_id,
                'tray_name' => optional($booking->tray)->name,
                'sales_person_id' => $booking->sales_person_id,
                'sales_person_name' => $salesPersonName,
                'price' => $booking->price,
                'amount' => $booking->amount,
                'booking_date' => $booking->booking_date,
                'booking_time' => $booking->booking_time,
                'booking_end_time' => $booking->booking_end_time,
                'delivery_date' => $booking->delivery_date,
                'delivery_time' => $booking->delivery_time,
                'delivery_address' => $booking->delivery_address,
                'status' => $booking->status,
                'notes' => $booking->notes,
            ],
        ]);
    }

    /**
     * Metadata + existing booking for edit form.
     */
    public function edit(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Booking::class);
        $booking = Booking::whereNull('deleted_at')->findOrFail($id);
        $this->guardBranch($booking);

        $customers = Client::whereNull('deleted_at')
            ->orderBy('name', 'asc')
            ->get(['id', 'name']);

        $products = $this->withDurations(Product::whereNull('deleted_at')
            ->where('type', 'is_service')
            ->orderBy('name', 'asc')
            ->get(['id', 'name', 'code', 'price']));

        return response()->json([
            'booking' => [
                'id' => $booking->id,
                'Ref' => $booking->Ref,
                'customer_id' => $booking->customer_id,
                'product_id' => $booking->product_id,
                'tray_id' => $booking->tray_id,
                'sales_person_id' => $booking->sales_person_id,
                'product_name' => $booking->product_name,
                'product_description' => $booking->product_description,
                'price' => $booking->price,
                'amount' => $booking->amount,
                'booking_date' => $booking->booking_date,
                'booking_time' => $booking->booking_time,
                'booking_end_time' => $booking->booking_end_time,
                'delivery_date' => $booking->delivery_date,
                'delivery_time' => $booking->delivery_time,
                'delivery_address' => $booking->delivery_address,
                'status' => $booking->status,
                'notes' => $booking->notes,
                'staff_id' => $booking->staff_id,
                'source' => $booking->source ?? 'staff',
            ],
            'customers' => $customers,
            'products' => $products,
            'trays' => $this->getTrays(),
            'sales_persons' => $this->getSalesPersons(),
            'statuses' => Booking::workflowReady() ? Booking::STATUSES : ['pending', 'confirmed', 'cancelled', 'completed'],
            // Booking Management: bookable staff for the form (empty before its migrations),
            // plus the booking's own staff member when they have been removed since.
            'staff' => \App\Models\BookingStaff::tablesReady()
                ? \App\Models\BookingStaff::query()->active()->orderBy('sort_order')->orderBy('name')->get(['id', 'name', 'color'])
                    ->when($booking->staff_id, function ($list) use ($booking) {
                        if ($list->contains('id', (int) $booking->staff_id)) {
                            return $list;
                        }
                        $gone = \App\Models\BookingStaff::withTrashed()->find($booking->staff_id, ['id', 'name', 'color']);

                        return $gone ? $list->push($gone->setAttribute('removed', true)) : $list;
                    })->values()
                : [],
        ]);
    }

    /**
     * Update an existing booking.
     */
    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Booking::class);
        $booking = Booking::whereNull('deleted_at')->findOrFail($id);
        $this->guardBranch($booking);

        // Normalize time formats before validation - handle H:i:s format and empty strings
        $requestData = $request->all();
        
        // Normalize booking_time (H:i:s to H:i)
        if (isset($requestData['booking_time']) && $requestData['booking_time'] !== null) {
            if (preg_match('/^(\d{2}:\d{2}):\d{2}$/', $requestData['booking_time'], $matches)) {
                $requestData['booking_time'] = $matches[1];
            }
        }
        
        // Normalize booking_end_time - handle empty strings and H:i:s format
        if (isset($requestData['booking_end_time']) && $requestData['booking_end_time'] === '') {
            $requestData['booking_end_time'] = null;
        } elseif (isset($requestData['booking_end_time']) && $requestData['booking_end_time'] !== null) {
            // If it's in H:i:s format, convert to H:i
            if (preg_match('/^(\d{2}:\d{2}):\d{2}$/', $requestData['booking_end_time'], $matches)) {
                $requestData['booking_end_time'] = $matches[1];
            }
        }

        // Normalize delivery_time - handle empty strings and H:i:s format
        if (isset($requestData['delivery_time']) && $requestData['delivery_time'] === '') {
            $requestData['delivery_time'] = null;
        } elseif (isset($requestData['delivery_time']) && $requestData['delivery_time'] !== null) {
            if (preg_match('/^(\d{2}:\d{2}):\d{2}$/', $requestData['delivery_time'], $matches)) {
                $requestData['delivery_time'] = $matches[1];
            }
        }

        $validated = validator($requestData, [
            'customer_id' => 'required|integer|exists:clients,id',
            'product_id' => 'nullable|integer|exists:products,id',
            'tray_id' => 'nullable|integer|exists:trays,id',
            'sales_person_id' => 'nullable|integer|exists:users,id',
            'product_name' => 'nullable|string|max:255',
            'product_description' => 'nullable|string',
            'price' => 'nullable|numeric|min:0',
            'amount' => 'nullable|numeric|min:0',
            'booking_date' => 'required|date',
            'booking_time' => 'required|date_format:H:i',
            'booking_end_time' => 'nullable|date_format:H:i',
            'delivery_date' => 'nullable|date',
            'delivery_time' => 'nullable|date_format:H:i',
            'delivery_address' => 'nullable|string',
            'status' => 'required|string|in:'.implode(',', Booking::STATUSES),
            'notes' => 'nullable|string',
        ])->validate();

        if ($refused = $this->chargedStatusRefusal($booking, $validated['status'])) {
            return $refused;
        }

        return \App\Services\Booking\BookingTx::run(function () use ($request, $validated, $booking) {
            $booking = Booking::whereKey($booking->id)->lockForUpdate()->firstOrFail();
            $validated = $this->withStaffCheck($request, $validated, $booking);
            if ($validated instanceof \Illuminate\Http\JsonResponse) {
                return $validated;
            }

            $booking->update($validated);

            return response()->json(['success' => true]);
        });
    }

    /**
     * Soft-delete a booking.
     */
    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', Booking::class);
        $booking = Booking::withTrashed()->findOrFail($id);
        $this->guardBranch($booking);
        
        // Check if already deleted
        if ($booking->trashed()) {
            return response()->json(['success' => true, 'message' => 'Booking already deleted']);
        }
        
        $booking->delete();

        return response()->json(['success' => true]);
    }

    /**
     * Change only the status of a booking.
     */
    public function changeStatus(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', Booking::class);
        $booking = Booking::whereNull('deleted_at')->findOrFail($id);
        $this->guardBranch($booking);

        $validated = $request->validate([
            'status' => 'required|string|in:'.implode(',', Booking::STATUSES),
        ]);
        if ($refused = $this->chargedStatusRefusal($booking, $validated['status'])) {
            return $refused;
        }

        return \App\Services\Booking\BookingTx::run(function () use ($request, $validated, $booking) {
            $booking = Booking::whereKey($booking->id)->lockForUpdate()->firstOrFail();
            // Back to a status that holds time (e.g. a cancelled booking re-opened): the time must still be free.
            if ($booking->staff_id && Booking::workflowReady() && in_array($validated['status'], ['pending', 'confirmed', 'arrived'], true)
                && ! in_array($booking->status, ['pending', 'confirmed', 'arrived', 'completed'], true) && ! $request->boolean('force')) {
                \App\Models\BookingStaff::withTrashed()->whereKey($booking->staff_id)->lockForUpdate()->first();
                $check = (new \App\Services\Booking\AvailabilityService())->check(
                    $booking->product_id ? (int) $booking->product_id : null, (int) $booking->staff_id,
                    \Carbon\Carbon::parse($booking->booking_date)->toDateString(), substr((string) $booking->booking_time, 0, 5),
                    $booking->booking_end_time ? substr((string) $booking->booking_end_time, 0, 5) : null, max(1, (int) ($booking->seats ?: 1)), (int) $booking->id
                );
                if (! $check['ok']) {
                    return response()->json([
                        'message' => \App\Services\Booking\BookingWorkflow::reasonMessage($check['reason']),
                        'reason' => $check['reason'], 'clashes' => $check['clashes'], 'hours' => $check['hours'], 'can_force' => true,
                    ], 422);
                }
            }

            return $this->applyStatus($booking, $validated['status']);
        });
    }

    private function applyStatus(Booking $booking, string $status)
    {
        $validated = ['status' => $status];
        $booking->status = $validated['status'];
        // Booking Management: keep the workflow timestamps in step with a direct status change.
        if (Booking::workflowReady()) {
            $now = now();
            match ($validated['status']) {
                'confirmed' => $booking->confirmed_at ??= $now,
                'arrived' => $booking->arrived_at ??= $now,
                'completed' => $booking->completed_at ??= $now,
                'cancelled', 'declined' => $booking->cancelled_at ??= $now,
                default => null,
            };
            if (in_array($validated['status'], ['cancelled', 'declined'], true)) {
                $booking->cancelled_by ??= 'staff';
                $booking->hold_until = null;
            }
            if ($validated['status'] === 'confirmed') {
                $booking->hold_until = null;
            }
        }
        $booking->save();

        return response()->json(['success' => true]);
    }

    /** Branch-limited users only reach bookings of their branches (bookings with no branch are shared). */
    private function guardBranch(Booking $booking): void
    {
        if (Booking::workflowReady() && $booking->warehouse_id) {
            $this->abortIfWarehouseDenied((int) $booking->warehouse_id);
        }
    }

    /** A booking already charged keeps its sale: it can't be put back to an open or cancelled status here. */
    private function chargedStatusRefusal(Booking $booking, string $status)
    {
        if (! Booking::workflowReady() || ! $booking->sale_id || $status === 'completed' || $status === $booking->status) {
            return null;
        }
        if (! \App\Models\Sale::whereNull('deleted_at')->whereKey($booking->sale_id)->exists()) {
            return null;
        }
        $msg = __('This booking was charged (sale #:id). Return or delete the sale first.', ['id' => $booking->sale_id]);

        return response()->json(['message' => $msg, 'errors' => ['status' => [$msg]]], 422);
    }

    /**
     * Booking Management: the staff member on the booking (staff_id), checked
     * against their free time unless `force` is sent. Returns the data to
     * save, or a 422 response {message, reason, clashes, hours, can_force}.
     */
    private function withStaffCheck(Request $request, array $validated, ?Booking $existing = null)
    {
        if (! Booking::workflowReady()) {
            return $validated;
        }
        if ($request->exists('staff_id')) {
            $staffId = $request->filled('staff_id') ? (int) $request->input('staff_id') : null;
            $unchanged = $existing && $staffId === ($existing->staff_id ? (int) $existing->staff_id : null);
            if ($staffId && ! $unchanged && ! \App\Models\BookingStaff::whereKey($staffId)->exists()) {
                return response()->json(['message' => __('That staff member is not bookable.'), 'errors' => ['staff_id' => [__('That staff member is not bookable.')]]], 422);
            }
            $validated['staff_id'] = $staffId;
        } else {
            $staffId = $existing?->staff_id ? (int) $existing->staff_id : null;
        }

        $date = \Carbon\Carbon::parse($validated['booking_date'])->toDateString();
        $time = substr((string) $validated['booking_time'], 0, 5);
        $end = ! empty($validated['booking_end_time']) ? substr((string) $validated['booking_end_time'], 0, 5) : null;
        $moved = ! $existing || $staffId !== ($existing->staff_id ? (int) $existing->staff_id : null)
            || $date !== \Carbon\Carbon::parse($existing->booking_date)->toDateString()
            || $time !== substr((string) $existing->booking_time, 0, 5)
            || $end !== ($existing->booking_end_time ? substr((string) $existing->booking_end_time, 0, 5) : null)
            || (int) ($validated['product_id'] ?? 0) !== (int) $existing->product_id
            // Re-opened (cancelled / declined / no-show → an open status): it takes the time again.
            || ! in_array($existing->status, ['pending', 'confirmed', 'arrived', 'completed'], true);
        if ($staffId && $moved && in_array($validated['status'], ['pending', 'confirmed', 'arrived'], true) && ! $request->boolean('force')) {
            \App\Models\BookingStaff::withTrashed()->whereKey($staffId)->lockForUpdate()->first();
            $check = (new \App\Services\Booking\AvailabilityService())->check(
                ! empty($validated['product_id']) ? (int) $validated['product_id'] : null, $staffId, $date, $time, $end,
                max(1, (int) ($existing->seats ?? 1)), $existing?->id
            );
            if (! $check['ok']) {
                return response()->json([
                    'message' => \App\Services\Booking\BookingWorkflow::reasonMessage($check['reason']),
                    'reason' => $check['reason'], 'clashes' => $check['clashes'], 'hours' => $check['hours'], 'can_force' => true,
                ], 422);
            }
        }

        if ($end !== null && ($s = \App\Services\Booking\AvailabilityService::minutes($time)) !== null) {
            $validated['duration_minutes'] = max(0, (\App\Services\Booking\AvailabilityService::minutes($end) ?? $s) - $s) ?: null;
        }
        if (! $existing || ! $existing->warehouse_id) {
            $validated['warehouse_id'] = ($staffId ? \App\Models\BookingStaff::withTrashed()->whereKey($staffId)->value('warehouse_id') : null)
                ?: (\App\Models\BookingSetting::current()->default_warehouse_id ?: null);
        }
        if ($validated['status'] === 'confirmed' && (! $existing || $existing->status !== 'confirmed')) {
            $validated['confirmed_at'] = now();
            $validated['confirmed_by'] = optional($request->user('api'))->id;
            $validated['hold_until'] = null;
        }

        return $validated;
    }

    /**
     * Generate PDF for a booking.
     */
    public function booking_pdf(Request $request, $id)
    {
        $helpers = new helpers;
        $booking = Booking::with(['customer', 'product'])
            ->whereNull('deleted_at')
            ->findOrFail($id);

        $this->authorizeDocumentView($request, $booking);

        $bookingData = [
            'id' => $booking->id,
            'Ref' => $booking->Ref,
            'customer_name' => optional($booking->customer)->name ?? '-',
            'customer_email' => optional($booking->customer)->email ?? '-',
            'customer_phone' => optional($booking->customer)->phone ?? '-',
            'customer_adr' => optional($booking->customer)->adresse ?? '-',
            'product_name' => optional($booking->product)->name ?? '-',
            'price' => $booking->price,
            'booking_date' => $booking->booking_date,
            'booking_time' => $booking->booking_time,
            'booking_end_time' => $booking->booking_end_time,
            'status' => $booking->status,
            'notes' => $booking->notes,
        ];

        $settings = Setting::whereNull('deleted_at')->first();
        $symbol = $helpers->Get_Currency_Code();

        $Html = view('pdf.booking_pdf', [
            'symbol' => $symbol,
            'setting' => $settings,
            'booking' => $bookingData,
        ])->render();

        $arabic = new Arabic;
        $p = $arabic->arIdentify($Html);
        for ($i = count($p) - 1; $i >= 0; $i -= 2) {
            $utf8ar = $arabic->utf8Glyphs(substr($Html, $p[$i - 1], $p[$i] - $p[$i - 1]));
            $Html = substr_replace($Html, $utf8ar, $p[$i - 1], $p[$i] - $p[$i - 1]);
        }

        $pdf = PDF::loadHTML($Html);

        return $pdf->download('Booking_'.$booking->id.'.pdf');
    }

    /**
     * Generate reference number for bookings.
     */
    public function getNumberOrder()
    {
        $last = DB::table('bookings')->latest('id')->first();

        if ($last && $last->Ref) {
            $item = (string) ($last->Ref ?? '');

            // Accept either '_' or '-' as separator without assuming array index exists
            if (strpos($item, '_') !== false) {
                $nwMsg = explode('_', $item);
                $sep = '_';
            } elseif (strpos($item, '-') !== false) {
                $nwMsg = explode('-', $item);
                $sep = '-';
            } else {
                $nwMsg = [];
                $sep = '_';
            }

            if (isset($nwMsg[1]) && is_numeric($nwMsg[1])) {
                $code = 'BK_'.($nwMsg[1] + 1);
            } else {
                $code = 'BK_1112';
            }
        } else {
            $code = 'BK_1111';
        }

        return $code;
    }

    /**
     * Send booking confirmation email to the customer using the 'booking' email template.
     */
    public function Send_Email(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Booking::class);

        $id = $request->input('id');
        if (! $id) {
            return response()->json(['message' => 'Booking ID is required.'], 422);
        }

        $booking = Booking::with(['customer', 'product'])
            ->whereNull('deleted_at')
            ->findOrFail($id);
        $this->guardBranch($booking);

        $customer = $booking->customer;
        if (! $customer) {
            return response()->json(['message' => 'Booking has no customer.'], 422);
        }

        $receiver_email = $customer->email;
        if (empty($receiver_email)) {
            return response()->json(['message' => 'Customer has no email address.'], 422);
        }

        $settings = Setting::whereNull('deleted_at')->first();
        $emailMessage = EmailMessage::getForLocale('booking');

        if ($emailMessage) {
            $message_body = $emailMessage->body;
            $message_subject = $emailMessage->subject;
        } else {
            $message_body = '';
            $message_subject = 'Your Booking Confirmation';
        }

        $contact_name = $customer->name ?? '';
        $business_name = $settings ? $settings->CompanyName : '';
        $booking_number = $booking->Ref ?? (string) $booking->id;
        $booking_date = $booking->booking_date ?? '';
        $start_time = $booking->booking_time ?? '';
        $end_time = $booking->booking_end_time ?? '';
        $service_name = $booking->product ? $booking->product->name : ($settings ? __('Not_Applicable') : 'N/A');
        $this->authorizeDocumentView($request, $booking);
        $booking_url = $booking->shareUrl();

        $message_body = str_replace('{contact_name}', e((string) $contact_name), $message_body);
        $message_body = str_replace('{business_name}', e((string) $business_name), $message_body);
        $message_body = str_replace('{booking_number}', $booking_number, $message_body);
        $message_body = str_replace('{booking_date}', $booking_date, $message_body);
        $message_body = str_replace('{start_time}', $start_time, $message_body);
        $message_body = str_replace('{end_time}', $end_time, $message_body);
        $message_body = str_replace('{service_name}', $service_name, $message_body);
        $message_body = str_replace('{booking_url}', $booking_url, $message_body);

        $message_subject = str_replace('{contact_name}', $contact_name, $message_subject);
        $message_subject = str_replace('{business_name}', $business_name, $message_subject);
        $message_subject = str_replace('{booking_number}', $booking_number, $message_subject);

        $email = [
            'subject' => $message_subject,
            'body' => $message_body,
            'company_name' => $business_name,
        ];

        $this->Set_config_mail();

        Mail::to($receiver_email)->send(new CustomEmail($email));

        return response()->json(['message' => 'Email sent successfully.'], 200);
    }
}


