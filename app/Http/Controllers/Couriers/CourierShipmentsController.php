<?php

namespace App\Http\Controllers\Couriers;

use App\Http\Controllers\BaseController;
use App\Models\CourierAccount;
use App\Models\Sale;
use App\Models\Shipment;
use App\Services\Couriers\CourierBookingService;
use App\Services\Couriers\CourierException;
use App\Services\Couriers\CourierLogger;
use App\Services\Couriers\CourierRegistry;
use App\Services\Couriers\CourierStatus;
use App\Services\Couriers\ReturnProcessor;
use App\Services\Couriers\ReturnRisk;
use App\Services\Couriers\ShipmentSync;
use App\Services\Couriers\ShipmentUpdater;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Courier shipments: booking from sales (single + bulk), the shipments list,
 * timeline, manual status / COD updates, labels, and the returns queue.
 * Abilities: courier_book (anything that changes a shipment),
 * courier_shipments (viewing + status refresh). Sale access follows the
 * same warehouse / record-view rules as the sales screens.
 */
class CourierShipmentsController extends BaseController
{
    public function __construct(
        private CourierBookingService $booking,
        private ShipmentUpdater $updater,
    ) {
    }

    // ---------------------------------------------------------------- sale

    /** Courier panel of one sale. */
    public function saleShipment(Request $request, $saleId)
    {
        $this->authorizeForUser($request->user('api'), 'courier_shipments', CourierAccount::class);
        $sale = $this->accessibleSale($request, $saleId);

        $shipment = Shipment::query()->where('sale_id', $sale->id)->whereNull('deleted_at')
            ->whereNotNull('courier_account_id')->latest('id')->first();

        return response()->json([
            'shipment' => $shipment ? $this->present($shipment, true) : null,
            // A returned parcel can be sent again, unless its goods were restocked.
            'can_book' => ! $this->booking->activeShipment($sale) && $this->booking->returnedShipment($sale)?->return_status !== 'restocked',
        ]);
    }

    /** Booking form for one sale. */
    public function bookingForm(Request $request, $saleId)
    {
        $this->authorizeForUser($request->user('api'), 'courier_book', CourierAccount::class);
        $sale = $this->accessibleSale($request, $saleId);

        $accounts = $this->bookableAccounts();
        $account = $request->filled('account_id')
            ? CourierAccount::query()->where('active', true)->find($request->integer('account_id'))
            : $this->booking->suggestedAccount($sale);

        return response()->json([
            'sale' => $this->saleSummary($sale),
            'accounts' => $accounts,
            'account_id' => $account?->id,
            'form' => $this->booking->defaults($sale, $account),
            'already_booked' => (bool) $this->booking->activeShipment($sale),
        ]);
    }

    public function book(Request $request, $saleId)
    {
        $this->authorizeForUser($request->user('api'), 'courier_book', CourierAccount::class);
        $sale = $this->accessibleSale($request, $saleId);

        $data = $request->validate($this->bookingRules());
        $account = CourierAccount::query()->findOrFail($data['account_id']);
        $this->assertTrackingNumber($account, $data);

        try {
            $shipment = $this->booking->book($sale, $account, $data, $request->user('api'));
        } catch (CourierException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'shipment' => $this->present($shipment->fresh(), true)]);
    }

    /** "Compare rates": priced options for this sale's parcel (aggregators). */
    public function rates(Request $request, $saleId)
    {
        $this->authorizeForUser($request->user('api'), 'courier_book', CourierAccount::class);
        $sale = $this->accessibleSale($request, $saleId);

        $rules = $this->bookingRules();
        unset($rules['tracking_number']);
        $data = $request->validate($rules);
        $account = CourierAccount::query()->where('active', true)->findOrFail($data['account_id']);
        if (! (CourierRegistry::meta($account->courier)['capabilities']['rates'] ?? false)) {
            return response()->json(['message' => 'This courier has no rate comparison.'], 422);
        }

        try {
            $rates = CourierLogger::during($account, 'rates', null,
                fn () => CourierRegistry::make($account)->rates(\App\Services\Couriers\Data\ShipmentRequest::fromArray(['reference' => $sale->Ref] + $data)));
        } catch (CourierException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $rows = array_map(fn ($r) => $r->toArray(), $rates);
        usort($rows, fn ($a, $b) => $a['amount'] <=> $b['amount']);

        return response()->json(['rates' => $rows]);
    }

    /** Bulk: pre-filled rows for the selected sales, with what is missing. */
    public function bulkPrepare(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'courier_book', CourierAccount::class);
        $data = $request->validate([
            'sale_ids' => ['required', 'array', 'min:1', 'max:100'],
            'sale_ids.*' => ['integer'],
            'account_id' => ['nullable', 'integer'],
        ]);

        $account = ! empty($data['account_id']) ? CourierAccount::query()->where('active', true)->find($data['account_id']) : null;
        $rows = [];
        foreach ($this->accessibleSales($request, $data['sale_ids']) as $sale) {
            $acc = $account ?: $this->booking->suggestedAccount($sale);
            $form = $this->booking->defaults($sale, $acc);
            $existing = $this->booking->activeShipment($sale);

            $rows[] = [
                'sale' => $this->saleSummary($sale),
                'form' => $form,
                'booked' => $existing ? ['tracking_number' => $existing->tracking_number, 'courier' => $existing->courierAccount?->name] : null,
                'problems' => $this->problems($form),
            ];
        }

        return response()->json(['rows' => $rows, 'accounts' => $this->bookableAccounts(), 'account_id' => $account?->id]);
    }

    /** Bulk: book each sale with its pre-filled form (+ per-row tracking number). */
    public function bulkBook(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'courier_book', CourierAccount::class);
        $data = $request->validate([
            'account_id' => ['required', 'integer', Rule::exists('courier_accounts', 'id')->whereNull('deleted_at')],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.sale_id' => ['required', 'integer'],
            'items.*.tracking_number' => ['nullable', 'string', 'max:100'],
            'items.*.service' => ['nullable', 'string', 'max:190'],
            'weight' => ['nullable', 'numeric', 'min:0.001', 'max:1000'],
        ]);

        $account = CourierAccount::query()->findOrFail($data['account_id']);
        $needsTracking = CourierRegistry::meta($account->courier)['capabilities']['manual_tracking'] ?? false;
        $sales = $this->accessibleSales($request, array_column($data['items'], 'sale_id'))->keyBy('id');
        $user = $request->user('api');

        $results = [];
        foreach ($data['items'] as $item) {
            $sale = $sales->get($item['sale_id']);
            $row = ['sale_id' => $item['sale_id'], 'Ref' => $sale?->Ref, 'ok' => false, 'tracking_number' => null, 'error' => null];
            try {
                if (! $sale) {
                    throw new CourierException('Sale not found.');
                }
                $form = $this->booking->defaults($sale, $account);
                $form['tracking_number'] = $item['tracking_number'] ?? null;
                if (! empty($item['service'])) {
                    $form['service'] = $item['service'];
                }
                if (! empty($data['weight'])) {
                    $form['weight'] = (float) $data['weight'];
                }
                if ($needsTracking && empty($form['tracking_number'])) {
                    throw new CourierException('Enter the tracking number.');
                }
                if ($problems = $this->problems($form)) {
                    throw new CourierException('Missing: ' . implode(', ', $problems) . '. Book this sale on its own to fill them in.');
                }
                $shipment = $this->booking->book($sale, $account, $form, $user);
                $row['ok'] = true;
                $row['tracking_number'] = $shipment->tracking_number;
                $row['shipment_id'] = $shipment->id;
            } catch (CourierException $e) {
                $row['error'] = $e->getMessage();
            } catch (\Throwable $e) {
                report($e);
                $row['error'] = 'Booking failed: ' . $e->getMessage();
            }
            $results[] = $row;
        }

        return response()->json([
            'results' => $results,
            'booked' => count(array_filter($results, fn ($r) => $r['ok'])),
            'failed' => count(array_filter($results, fn ($r) => ! $r['ok'])),
        ]);
    }

    public function cities(Request $request, $accountId)
    {
        $this->authorizeForUser($request->user('api'), 'courier_book', CourierAccount::class);
        $account = CourierAccount::query()->where('active', true)->findOrFail($accountId);

        try {
            return response()->json(['cities' => $this->booking->cities($account, $request->query('q'))]);
        } catch (CourierException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    // ----------------------------------------------------------- shipments

    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'courier_shipments', CourierAccount::class);

        $limit = min(100, max(10, (int) $request->query('limit', 20)));
        $query = $this->scopedQuery()
            ->with(['sale:id,Ref,client_id,warehouse_id,GrandTotal,paid_amount', 'sale.client:id,name,phone', 'courierAccount:id,name,courier']);

        $query
            ->when($request->filled('account_id'), fn ($q) => $q->where('shipments.courier_account_id', $request->integer('account_id')))
            ->when($request->filled('courier_status'), fn ($q) => $q->where('shipments.courier_status', $request->query('courier_status')))
            ->when($request->filled('courier_status_in'), fn ($q) => $q->whereIn('shipments.courier_status',
                array_intersect(explode(',', (string) $request->query('courier_status_in')), CourierStatus::ALL)))
            ->when($request->filled('cod_status'), fn ($q) => $q->where('shipments.cod_status', $request->query('cod_status')))
            ->when($request->filled('return_status'), fn ($q) => $q->where('shipments.return_status', $request->query('return_status')))
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('shipments.booked_at', '>=', $request->query('date_from')))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate('shipments.booked_at', '<=', $request->query('date_to')))
            // Same rules as the KPI cards these tabs belong to.
            ->when($request->boolean('cod_outstanding'), fn ($q) => $q->whereIn('shipments.cod_status', ['pending', 'collected'])
                ->whereNotIn('shipments.courier_status', [CourierStatus::RETURNED, CourierStatus::CANCELLED]))
            ->when($request->boolean('sync_errors'), fn ($q) => $q->whereNotNull('shipments.sync_error')
                ->whereNotIn('shipments.courier_status', CourierStatus::FINAL))
            ->when($request->filled('search'), function ($q) use ($request) {
                $s = '%' . $request->query('search') . '%';
                $q->where(fn ($w) => $w->where('shipments.tracking_number', 'like', $s)
                    ->orWhere('shipments.Ref', 'like', $s)
                    ->orWhere('shipments.delivered_to', 'like', $s)
                    // order_reference = the number the courier was sent, when the sale has one.
                    ->orWhereHas('sale', fn ($sq) => $sq->where('Ref', 'like', $s)->orWhere('order_reference', 'like', $s))
                    ->orWhereHas('sale.client', fn ($cq) => $cq->where('name', 'like', $s)->orWhere('phone', 'like', $s)));
            });

        $total = (clone $query)->count();
        $page = $query->orderByDesc('shipments.booked_at')->orderByDesc('shipments.id')
            ->forPage(max(1, (int) $request->query('page', 1)), $limit)
            ->get();
        // Return risk for the page's customers in one query.
        $risks = ReturnRisk::forClients($page->map(fn (Shipment $s) => $s->sale?->client_id)->all());
        $rows = $page->map(fn (Shipment $s) => $this->present($s) + ['return_risk' => $risks[(int) $s->sale?->client_id] ?? null]);

        return response()->json([
            'shipments' => $rows,
            'totalRows' => $total,
            'kpis' => $this->kpis(),
            'accounts' => CourierAccount::query()->withTrashed()->orderBy('name')->get(['id', 'name', 'courier', 'deleted_at'])
                ->map(fn ($a) => ['id' => $a->id, 'name' => $a->name . ($a->deleted_at ? ' (deleted)' : '')]),
        ]);
    }

    public function show(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'courier_shipments', CourierAccount::class);

        return response()->json(['shipment' => $this->present($this->accessibleShipment($id), true)]);
    }

    /** Ask the courier now instead of waiting for the next sync. */
    public function refresh(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'courier_shipments', CourierAccount::class);
        $shipment = $this->accessibleShipment($id);

        $result = app(ShipmentSync::class)->syncOne($shipment, false); // staff asked: log the full exchange
        if (! $result['ok']) {
            return response()->json(['message' => $result['error']], 422);
        }

        return response()->json(['success' => true, 'changed' => $result['changed'], 'shipment' => $this->present($shipment->fresh(), true)]);
    }

    public function cancel(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'courier_book', CourierAccount::class);
        $shipment = $this->accessibleShipment($id);
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);

        return $this->attempt(fn () => $this->booking->cancel($shipment, $request->user('api'), $data['note'] ?? null), $shipment);
    }

    public function updateStatus(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'courier_book', CourierAccount::class);
        $shipment = $this->accessibleShipment($id);
        $data = $request->validate([
            'status' => ['required', Rule::in(array_diff(CourierStatus::ALL, [CourierStatus::CANCELLED]))],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        return $this->attempt(fn () => $this->updater->setStatusManually($shipment, $data['status'], $data['note'] ?? null, $request->user('api')), $shipment);
    }

    public function updateCod(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'courier_book', CourierAccount::class);
        $shipment = $this->accessibleShipment($id);
        $data = $request->validate([
            'cod_status' => ['required', Rule::in(['collected', 'remitted'])],
            'reference' => ['nullable', 'string', 'max:120'],
        ]);

        return $this->attempt(fn () => $this->updater->setCodManually($shipment, $data['cod_status'], $data['reference'] ?? null, $request->user('api')), $shipment);
    }

    public function restockReturn(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'courier_book', CourierAccount::class);
        $shipment = $this->accessibleShipment($id);
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);

        return $this->attempt(fn () => app(ReturnProcessor::class)->restock($shipment, $request->user('api'), $data['note'] ?? null), $shipment);
    }

    public function dismissReturn(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'courier_book', CourierAccount::class);
        $shipment = $this->accessibleShipment($id);
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);

        return $this->attempt(fn () => app(ReturnProcessor::class)->dismiss($shipment, $request->user('api'), $data['note'] ?? null), $shipment);
    }

    /**
     * The courier's own label (URL or PDF) when it has one, otherwise the
     * data for the printable Stocky label.
     */
    public function label(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'courier_shipments', CourierAccount::class);
        $shipment = $this->accessibleShipment($id);
        $account = $shipment->courierAccount;
        $hasLabels = $account && ! $account->trashed() && (CourierRegistry::meta($account->courier)['capabilities']['labels'] ?? false);

        if ($shipment->label_url) {
            return response()->json(['type' => 'url', 'url' => $shipment->label_url]);
        }
        if ($hasLabels) {
            try {
                $label = CourierLogger::during($account, 'label', $shipment->id, fn () => CourierRegistry::make($account)->label($shipment));
                if (! empty($label['url'])) {
                    return response()->json(['type' => 'url', 'url' => $label['url']]);
                }
                if (! empty($label['pdf'])) {
                    // JSON like every other answer here; the SPA opens it as a blob.
                    return response()->json(['type' => 'pdf', 'data' => base64_encode($label['pdf'])]);
                }
                if (! empty($label['image'])) {
                    // Image labels (UPS GIF): the SPA opens a print page around it.
                    return response()->json(['type' => 'image', 'mime' => $label['mime'] ?? 'image/gif', 'data' => base64_encode($label['image'])]);
                }
            } catch (CourierException $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
        }

        return response()->json(['type' => 'stocky', 'labels' => [$this->buildLabel($shipment, $this->company())]]);
    }

    /** Printable Stocky labels for several shipments at once. */
    public function labelData(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'courier_shipments', CourierAccount::class);
        $data = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:200'], 'ids.*' => ['integer']]);

        $company = $this->company();
        $labels = $this->scopedQuery()->whereIn('shipments.id', $data['ids'])
            ->with(['sale', 'courierAccount'])->get()
            ->map(fn (Shipment $s) => $this->buildLabel($s, $company));

        return response()->json(['labels' => $labels]);
    }

    // ------------------------------------------------------------ helpers

    private function bookingRules(): array
    {
        return [
            'account_id' => ['required', 'integer', Rule::exists('courier_accounts', 'id')->whereNull('deleted_at')],
            'consignee_name' => ['required', 'string', 'max:150'],
            'consignee_phone' => ['required', 'string', 'max:40'],
            'consignee_email' => ['nullable', 'email', 'max:150'],
            'address' => ['required', 'string', 'max:500'],
            'city' => ['required', 'string', 'max:120'],
            'courier_city_code' => ['nullable', 'string', 'max:100'],
            'courier_city_name' => ['nullable', 'string', 'max:150'],
            'state' => ['nullable', 'string', 'max:100'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'country' => ['nullable', 'string', 'max:100'],
            'zip' => ['nullable', 'string', 'max:20'],
            'cod_amount' => ['required', 'numeric', 'min:0'],
            'declared_value' => ['nullable', 'numeric', 'min:0'],
            'weight' => ['required', 'numeric', 'min:0.001', 'max:1000'],
            'parcel_length' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'parcel_width' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'parcel_height' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'pieces' => ['required', 'integer', 'min:1', 'max:999'],
            'items_description' => ['nullable', 'string', 'max:250'],
            'notes' => ['nullable', 'string', 'max:500'],
            'service' => ['nullable', 'string', 'max:190'],
            'allow_open_parcel' => ['nullable', 'boolean'],
            'tracking_number' => ['nullable', 'string', 'max:100'],
        ];
    }

    private function assertTrackingNumber(CourierAccount $account, array $data): void
    {
        if ((CourierRegistry::meta($account->courier)['capabilities']['manual_tracking'] ?? false) && empty($data['tracking_number'])) {
            throw \Illuminate\Validation\ValidationException::withMessages(['tracking_number' => 'Enter the tracking number given by the courier.']);
        }
    }

    /** What a pre-filled form still lacks for a booking. */
    private function problems(array $form): array
    {
        $labels = ['consignee_name' => 'name', 'consignee_phone' => 'phone', 'address' => 'address', 'city' => 'city'];
        $missing = [];
        foreach ($labels as $key => $label) {
            if (trim((string) ($form[$key] ?? '')) === '') {
                $missing[] = $label;
            }
        }

        return $missing;
    }

    private function bookableAccounts(): array
    {
        return CourierAccount::query()->where('active', true)->orderByDesc('is_default')->orderBy('name')->get()
            ->filter(fn ($a) => CourierRegistry::has($a->courier))
            ->map(function (CourierAccount $a) {
                $meta = CourierRegistry::meta($a->courier);

                return [
                    'id' => $a->id,
                    'name' => $a->name,
                    'courier' => $a->courier,
                    'courier_name' => $meta['name'],
                    'is_default' => (bool) $a->is_default,
                    'warehouse_id' => $a->warehouse_id,
                    'capabilities' => $meta['capabilities'],
                    'default_weight' => (float) $a->default_weight,
                    'default_service' => $a->default_service,
                ];
            })->values()->all();
    }

    private function accessibleSale(Request $request, $saleId): Sale
    {
        $sale = Sale::query()->whereNull('deleted_at')->findOrFail($saleId);
        $this->abortIfDocumentWarehouseDenied($sale);

        $user = $request->user('api');
        if (! $user->hasRecordView()) {
            $this->authorizeForUser($user, 'check_record', $sale);
        }

        return $sale;
    }

    private function accessibleSales(Request $request, array $ids)
    {
        $user = $request->user('api');

        return Sale::query()->whereNull('deleted_at')->whereIn('id', $ids)
            ->when(($scope = $this->warehouseScopeIds()) !== null, fn ($q) => $q->whereIn('warehouse_id', $scope))
            ->when(! $user->hasRecordView(), fn ($q) => $q->where('user_id', $user->id))
            ->get();
    }

    private function accessibleShipment($id): Shipment
    {
        $shipment = Shipment::query()->whereNull('deleted_at')->whereNotNull('courier_account_id')->findOrFail($id);
        $this->abortIfDocumentWarehouseDenied($shipment->sale);

        // Same rule as the sale itself: users limited to their own records.
        $user = request()->user('api');
        if ($user && ! $user->hasRecordView() && $shipment->sale) {
            $this->authorizeForUser($user, 'check_record', $shipment->sale);
        }

        return $shipment;
    }

    /** Courier shipments the user may see (warehouse scope + own records via the sale). */
    private function scopedQuery()
    {
        $user = request()->user('api');

        return Shipment::query()
            ->select('shipments.*')
            ->whereNull('shipments.deleted_at')
            ->whereNotNull('shipments.courier_account_id')
            ->when(($scope = $this->warehouseScopeIds()) !== null, fn ($q) => $q->whereHas('sale', fn ($s) => $s->whereIn('warehouse_id', $scope)))
            ->when($user && ! $user->hasRecordView(), fn ($q) => $q->whereHas('sale', fn ($s) => $s->where('user_id', $user->id)));
    }

    private function kpis(): array
    {
        $base = $this->scopedQuery();
        $byStatus = (clone $base)->reorder()->select('shipments.courier_status', DB::raw('COUNT(*) as n'))
            ->groupBy('shipments.courier_status')->pluck('n', 'courier_status');

        return [
            'by_status' => (object) $byStatus->all(),
            'on_the_way' => (int) collect([CourierStatus::BOOKED, CourierStatus::PICKED_UP, CourierStatus::IN_TRANSIT, CourierStatus::OUT_FOR_DELIVERY, CourierStatus::FAILED_ATTEMPT, CourierStatus::RETURNING])
                ->sum(fn ($s) => (int) ($byStatus[$s] ?? 0)),
            'cod_pending_amount' => round((float) (clone $base)->whereIn('shipments.cod_status', ['pending', 'collected'])
                ->whereNotIn('shipments.courier_status', [CourierStatus::RETURNED, CourierStatus::CANCELLED])->sum('shipments.cod_amount'), 2),
            'cod_collected_amount' => round((float) (clone $base)->where('shipments.cod_status', 'collected')->sum('shipments.cod_amount'), 2),
            'returns_pending' => (int) (clone $base)->where('shipments.return_status', 'pending')->count(),
            'sync_errors' => (int) (clone $base)->whereNotNull('shipments.sync_error')
                ->whereNotIn('shipments.courier_status', CourierStatus::FINAL)->count(),
        ];
    }

    private function attempt(callable $fn, Shipment $shipment)
    {
        try {
            $fn();
        } catch (CourierException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'shipment' => $this->present($shipment->fresh(), true)]);
    }

    private function saleSummary(Sale $sale): array
    {
        $sale->loadMissing('client');

        return [
            'id' => $sale->id,
            'Ref' => $sale->Ref,
            'client_id' => $sale->client_id,
            'client_name' => $sale->client?->name,
            'return_risk' => ReturnRisk::forClient($sale->client_id ? (int) $sale->client_id : null),
            'GrandTotal' => round((float) $sale->GrandTotal, 2),
            'paid_amount' => round((float) $sale->paid_amount, 2),
            'due' => max(0, round((float) $sale->GrandTotal - (float) $sale->paid_amount, 2)),
            'warehouse_id' => $sale->warehouse_id,
        ];
    }

    private function present(Shipment $s, bool $withEvents = false): array
    {
        $account = $s->courierAccount;
        $meta = $account ? CourierRegistry::meta($account->courier) : null;
        $caps = $meta['capabilities'] ?? [];

        $out = [
            'id' => $s->id,
            'Ref' => $s->Ref,
            'sale_id' => $s->sale_id,
            'sale_ref' => $s->sale?->Ref,
            'client_name' => $s->sale?->client?->name ?? $s->delivered_to,
            'client_phone' => $s->sale?->client?->phone,
            'delivered_to' => $s->delivered_to,
            'shipping_address' => $s->shipping_address,
            'destination_city' => $s->destination_city,
            'account_id' => $s->courier_account_id,
            'account_name' => $account?->name,
            'account_deleted' => (bool) $account?->trashed(),
            'courier' => $s->courier,
            'courier_name' => $meta['name'] ?? $s->courier,
            'tracking_number' => $s->tracking_number,
            'tracking_link' => $s->trackingLink(),
            'courier_status' => $s->courier_status,
            'courier_status_raw' => $s->courier_status_raw,
            'status' => $s->status,
            'cod_amount' => (float) $s->cod_amount,
            'cod_status' => $s->cod_status,
            'cod_reference' => $s->cod_reference,
            'cod_remitted_at' => $s->cod_remitted_at?->toDateTimeString(),
            'cod_payment_id' => $s->cod_payment_id,
            'shipping_charges' => $s->shipping_charges !== null ? (float) $s->shipping_charges : null,
            'charges_expense_id' => $s->charges_expense_id,
            'weight' => $s->weight,
            'pieces' => $s->pieces,
            'booked_at' => $s->booked_at?->toDateTimeString(),
            'delivered_at' => $s->delivered_at?->toDateTimeString(),
            'returned_at' => $s->returned_at?->toDateTimeString(),
            'last_synced_at' => $s->last_synced_at?->toDateTimeString(),
            'sync_error' => $s->sync_error,
            'return_status' => $s->return_status,
            'return_note' => $s->return_note,
            'return_handled_at' => $s->return_handled_at?->toDateTimeString(),
            'sale_return_id' => $s->sale_return_id,
            'has_label' => (bool) ($s->label_url || ($caps['labels'] ?? false)),
            'can' => [
                'refresh' => (bool) ($caps['tracking'] ?? false) && ! ($account?->trashed()),
                'cancel' => in_array($s->courier_status, [null, CourierStatus::BOOKED], true),
                // Corrections stay possible until the parcel is back or cancelled.
                'manual_status' => ! in_array($s->courier_status, [CourierStatus::RETURNED, CourierStatus::CANCELLED], true),
                'cod' => (float) $s->cod_amount > 0 && $s->cod_status !== 'remitted'
                    && ! in_array($s->courier_status, [CourierStatus::RETURNED, CourierStatus::CANCELLED], true),
            ],
        ];

        if ($withEvents) {
            $out['client_id'] = $s->sale?->client_id;
            $out['return_risk'] = ReturnRisk::forClient($s->sale?->client_id ? (int) $s->sale->client_id : null);
            $out['customer_tracking_url'] = $s->tracking_number ? (\App\Services\Couriers\CourierNotifier::trackingUrl($s) ?: null) : null;
            $out['events'] = $s->events()->limit(200)->get()->map(fn ($e) => [
                'id' => $e->id,
                'status' => $e->status,
                'raw_status' => $e->raw_status,
                'description' => $e->description,
                'location' => $e->location,
                'event_at' => $e->event_at?->toDateTimeString(),
                'source' => $e->source,
            ]);
        }

        return $out;
    }

    private function company(): array
    {
        $s = DB::table('settings')->whereNull('deleted_at')->first();

        return [
            'name' => $s->CompanyName ?? '',
            'phone' => $s->CompanyPhone ?? '',
            'address' => $s->CompanyAdress ?? '',
            'logo' => $s->logo ?? null,
        ];
    }

    private function buildLabel(Shipment $s, array $company): array
    {
        $account = $s->courierAccount;
        $meta = $s->courier_meta ?? [];

        return [
            'id' => $s->id,
            'tracking_number' => $s->tracking_number,
            'courier_name' => $account?->name ?? $s->courier,
            'sale_ref' => $s->sale?->Ref,
            'booked_at' => $s->booked_at?->toDateString(),
            'from' => [
                'name' => $account?->pickup_contact ?: $company['name'],
                'phone' => $account?->pickup_phone ?: $company['phone'],
                'address' => trim(($account?->pickup_address ?: $company['address']) . ($account?->pickup_city ? ', ' . $account->pickup_city : '')),
            ],
            'to' => [
                'name' => $s->delivered_to,
                'phone' => $meta['consignee']['phone'] ?? $s->sale?->client?->phone,
                'address' => $s->shipping_address,
                'city' => $s->destination_city,
            ],
            'cod_amount' => (float) $s->cod_amount,
            'weight' => $s->weight,
            'pieces' => $s->pieces,
            'items' => $meta['items'] ?? null,
            'notes' => $meta['notes'] ?? null,
            'allow_open_parcel' => (bool) ($meta['allow_open_parcel'] ?? false),
            'company' => $company,
        ];
    }
}
