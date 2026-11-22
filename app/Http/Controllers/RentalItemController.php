<?php

namespace App\Http\Controllers;

use App\Models\RentalCategory;
use App\Models\RentalContract;
use App\Models\RentalItem;
use App\Models\RentalSetting;
use App\Models\Warehouse;
use App\Services\Rental\RentalContractService;
use App\Support\RentalTypes;
use App\Traits\ScopesWarehouseAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Rental register — properties, equipment, vehicles and anything else the
 * business rents out.
 *
 * Photos go to the tenant upload folder (upload_path: images/tenants/{id}/rentals); the
 * `images` column keeps the file names in display order, the first being the
 * cover. Saves are multipart POSTs (PHP cannot parse a multipart PUT body), so
 * the edit form posts to rental/items/{id}/update.
 *
 * List endpoints follow the admin's usual contract
 * (page/SortField/SortType/search/limit -> { items, totalRows }) so the Vue
 * `useCrudTable` composable drives them unchanged.
 */
class RentalItemController extends Controller
{
    use ScopesWarehouseAccess;

    private const MAX_IMAGES = 12;

    private const SORTABLE = [
        'id', 'code', 'name', 'type', 'status', 'condition', 'quantity',
        'daily_rate', 'monthly_rate', 'deposit_amount', 'created_at',
    ];

    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', RentalItem::class);
        app(RentalContractService::class)->sync();

        $perPage = (int) ($request->limit ?? 10);
        $page = max(1, (int) $request->get('page', 1));
        $order = in_array($request->SortField, self::SORTABLE, true) ? $request->SortField : 'created_at';
        $dir = strtolower((string) $request->SortType) === 'asc' ? 'asc' : 'desc';

        $query = RentalItem::with('category', 'warehouse')->whereNull('deleted_at');

        // Items with no branch belong to the whole company and stay visible.
        $this->scopeToWarehouses($query, 'warehouse_id', true);

        foreach (['status', 'type', 'condition', 'warehouse_id'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->{$filter});
            }
        }
        if ($request->filled('website') && RentalSetting::websiteReady()) {
            $query->where('show_on_website', $request->boolean('website'));
        }
        if ($request->filled('category_id')) {
            $query->where('rental_category_id', $request->category_id);
        }
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'LIKE', "%{$search}%")
                    ->orWhere('code', 'LIKE', "%{$search}%")
                    ->orWhere('serial_number', 'LIKE', "%{$search}%")
                    ->orWhere('plate_number', 'LIKE', "%{$search}%")
                    ->orWhere('brand', 'LIKE', "%{$search}%")
                    ->orWhere('model', 'LIKE', "%{$search}%")
                    ->orWhere('address', 'LIKE', "%{$search}%")
                    ->orWhere('city', 'LIKE', "%{$search}%");
            });
        }

        $totalRows = $query->count();
        if ($perPage === -1) {
            $perPage = max(1, $totalRows);
        }
        $offset = ($page * $perPage) - $perPage;

        $items = $query->orderBy($order, $dir)->offset($offset)->limit($perPage)->get();

        return response()->json([
            'totalRows' => $totalRows,
            'items' => $items->map(fn ($item) => $this->present($item))->values(),
        ]);
    }

    /** Selects shared by the item form and the list filters. */
    public function meta(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', RentalItem::class);

        $settings = RentalSetting::current();

        return response()->json([
            'categories' => RentalCategory::whereNull('deleted_at')->orderBy('name')->get(['id', 'name', 'type']),
            'warehouses' => Warehouse::whereNull('deleted_at')->orderBy('name')->get(['id', 'name']),
            'next_code' => RentalItem::nextCode(),
            'default_deposit' => (float) $settings->default_deposit,
            // Business types: which types to offer and each one's extra fields.
            'business' => RentalTypes::forAdmin($settings) + ['website_ready' => RentalSetting::websiteReady()],
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', RentalItem::class);

        $request->validate($this->rules(), [], $this->attributes());
        $this->guardType((string) $request->type, null);

        $data = $this->payload($request);
        $data['code'] = trim((string) $request->code) ?: RentalItem::nextCode();
        $this->ensureUniqueCode($data['code']);
        $data['images'] = $this->storeImages($request, []);
        $data['created_by'] = optional($request->user('api'))->id;

        $item = RentalItem::create($data);

        return response()->json(['success' => true, 'id' => $item->id]);
    }

    public function show(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'view', RentalItem::class);

        app(RentalContractService::class)->sync([(int) $id]);
        $item = RentalItem::with('category', 'warehouse')->whereNull('deleted_at')->findOrFail($id);
        $this->abortIfDocumentWarehouseDenied($item);

        return response()->json(['item' => array_merge($this->present($item), $this->details($item), [
            'contracts' => $this->contractHistory($item->id),
        ])]);
    }

    public function edit(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', RentalItem::class);

        $item = RentalItem::with('category', 'warehouse')->whereNull('deleted_at')->findOrFail($id);
        $this->abortIfDocumentWarehouseDenied($item);

        return response()->json(['item' => array_merge($this->present($item), $this->details($item), [
            // The form edits the stored file names, not the URLs.
            'images' => collect($item->images ?: [])->map(fn ($file) => [
                'file' => $file,
                'url' => url(upload_path(RentalItem::IMAGE_DIR) . '/' . $file),
            ])->values(),
        ])]);
    }

    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', RentalItem::class);

        $item = RentalItem::whereNull('deleted_at')->findOrFail($id);
        $this->abortIfDocumentWarehouseDenied($item);
        $request->validate($this->rules(), [], $this->attributes());
        $this->guardType((string) $request->type, $item->type);

        $data = $this->payload($request, $item);
        $code = trim((string) $request->code);
        if ($code !== '' && $code !== $item->code) {
            $this->ensureUniqueCode($code, $item->id);
            $data['code'] = $code;
        }

        // Existing photos missing from image_order were removed in the form;
        // a request without the field at all leaves the photos untouched.
        $current = $item->images ?: [];
        if (! $request->has('image_order')) {
            $request->merge(['image_order' => json_encode($current)]);
        }
        $keep = array_intersect($this->decodeList($request->image_order), $current);
        foreach (array_diff($current, $keep) as $removed) {
            $this->deleteImage($removed);
        }
        $data['images'] = $this->storeImages($request, $current);

        $item->update($data);

        return response()->json(['success' => true, 'id' => $item->id]);
    }

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', RentalItem::class);

        $item = RentalItem::whereNull('deleted_at')->findOrFail($id);
        $this->abortIfDocumentWarehouseDenied($item);
        $this->guardNotOnContract([$item->id]);
        $item->delete();

        return response()->json(['success' => true]);
    }

    public function deleteBySelection(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'delete', RentalItem::class);

        $ids = array_map('intval', (array) $request->selectedIds);
        foreach (RentalItem::whereIn('id', $ids)->whereNotNull('warehouse_id')->pluck('warehouse_id')->unique() as $wid) {
            $this->abortIfWarehouseDenied($wid);
        }
        $this->guardNotOnContract($ids);
        RentalItem::whereIn('id', $ids)->delete();

        return response()->json(['success' => true, 'count' => count($ids)]);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Only the business types the tenant runs can be picked. An item already
     * of a type that was turned off may keep it (so it can still be edited).
     */
    private function guardType(string $type, ?string $current): void
    {
        if ($type === $current || RentalTypes::isEnabled($type)) {
            return;
        }
        abort(response()->json([
            'message' => 'This type of rental is turned off in Rental Settings › Business type.',
            'errors' => ['type' => ['This type of rental is turned off in Rental Settings › Business type.']],
        ], 422));
    }

    /** Storefront URL of the item when the Rental store theme is live, else null. */
    private function websiteUrl(RentalItem $item): ?string
    {
        if ($this->rentalThemeLive === null) {
            $s = \App\Models\StoreSetting::query()->first();
            $this->rentalThemeLive = $s && ($s->theme ?? null) === 'rental' && RentalSetting::websiteReady();
        }
        if (! $this->rentalThemeLive || ! $item->show_on_website) {
            return null;
        }
        try {
            return store_base_url('rentals/' . $item->publicKey());
        } catch (\Throwable $e) {
            return null;
        }
    }

    private ?bool $rentalThemeLive = null;

    /** An item on a draft or active contract can't be deleted out from under it. */
    private function guardNotOnContract(array $ids): void
    {
        $numbers = RentalContract::whereNull('deleted_at')
            ->whereIn('status', ['draft', 'active'])
            ->whereHas('items', fn ($q) => $q->whereIn('rental_item_id', $ids))
            ->limit(5)->pluck('contract_number');

        if ($numbers->isNotEmpty()) {
            abort(response()->json([
                'message' => 'This item is on open contracts (' . $numbers->implode(', ') . '). End or cancel them first.',
            ], 422));
        }
        $holds = \App\Models\RentalReservation::whereNull('deleted_at')->where('status', 'held')
            ->whereHas('items', fn ($q) => $q->whereIn('rental_item_id', $ids))
            ->limit(5)->pluck('reservation_number');
        if ($holds->isNotEmpty()) {
            abort(response()->json([
                'message' => 'This item is held by reservations (' . $holds->implode(', ') . '). Cancel or convert them first.',
            ], 422));
        }
    }

    /** Every contract this item appears on, newest first. */
    private function contractHistory(int $itemId): array
    {
        $soonDays = (int) RentalSetting::current()->expiring_soon_days;

        return RentalContract::with('client')
            ->whereNull('deleted_at')
            ->whereHas('items', fn ($q) => $q->where('rental_item_id', $itemId))
            ->orderBy('start_date', 'desc')->limit(50)->get()
            ->map(fn ($c) => [
                'id' => $c->id,
                'contract_number' => $c->contract_number,
                'customer_name' => optional($c->client)->name,
                'status' => $c->status,
                'stage' => $c->stage($soonDays),
                'start_date' => optional($c->start_date)->toIso8601String(),
                'end_date' => optional($c->end_date)->toIso8601String(),
                'rate_period' => $c->rate_period,
                'billing_type' => $c->billing_type,
                'total_amount' => (float) $c->total_amount,
            ])->values()->all();
    }

    /** The row shape every list, card and detail header uses. */
    private function present(RentalItem $item): array
    {
        $headlineRate = $item->rateFor($item->default_period);

        // Cheapest-to-read "where / what is it" line for cards.
        $identifier = $item->type === 'property'
            ? collect([$item->address, $item->city])->filter()->implode(', ')
            : collect([$item->brand, $item->model, $item->plate_number ?: $item->serial_number])->filter()->implode(' · ');

        return [
            'id' => $item->id,
            'code' => $item->code,
            'name' => $item->name,
            'type' => $item->type,
            'status' => $item->status,
            'condition' => $item->condition,
            'rental_category_id' => $item->rental_category_id,
            'category_name' => optional($item->category)->name,
            'warehouse_id' => $item->warehouse_id,
            'warehouse_name' => optional($item->warehouse)->name,
            'quantity' => (int) $item->quantity,
            'default_period' => $item->default_period,
            'headline_rate' => $headlineRate,
            'rates' => collect(RentalItem::RATE_COLUMNS)
                ->map(fn ($column, $period) => $item->rateFor($period))
                ->all(),
            'deposit_amount' => (float) $item->deposit_amount,
            'identifier' => $identifier,
            'city' => $item->city,
            'image_url' => $item->coverUrl(),
            'image_count' => count($item->images ?: []),
            'created_at' => optional($item->created_at)->toIso8601String(),
            'show_on_website' => RentalSetting::websiteReady() ? (bool) $item->show_on_website : null,
            'featured' => (bool) $item->featured,
            'website_url' => $this->websiteUrl($item),
        ];
    }

    /** Everything else the detail page and the edit form need. */
    private function details(RentalItem $item): array
    {
        return [
            'hourly_rate' => $item->rateFor('hour'),
            'daily_rate' => $item->rateFor('day'),
            'weekly_rate' => $item->rateFor('week'),
            'monthly_rate' => $item->rateFor('month'),
            'yearly_rate' => $item->rateFor('year'),
            'min_duration' => $item->min_duration,
            'min_duration_unit' => $item->min_duration_unit,
            'address' => $item->address,
            'floor' => $item->floor,
            'area' => $item->area === null ? null : (float) $item->area,
            'area_unit' => $item->area_unit,
            'bedrooms' => $item->bedrooms,
            'bathrooms' => $item->bathrooms,
            'furnished' => (bool) $item->furnished,
            'amenities' => $item->amenities ?: [],
            'brand' => $item->brand,
            'model' => $item->model,
            'serial_number' => $item->serial_number,
            'plate_number' => $item->plate_number,
            'year' => $item->year,
            'meter_reading' => $item->meter_reading === null ? null : (float) $item->meter_reading,
            'meter_unit' => $item->meter_unit,
            'purchase_date' => optional($item->purchase_date)->toDateString(),
            'purchase_cost' => $item->purchase_cost === null ? null : (float) $item->purchase_cost,
            'description' => $item->description,
            'notes' => $item->notes,
            'image_urls' => $item->imageUrls(),
            'specs' => (object) ($item->specs ?: []),
            'slug' => $item->slug,
        ];
    }

    private function rules(): array
    {
        $periods = implode(',', RentalItem::PERIODS);

        return [
            'name' => 'required|string|max:191',
            'code' => 'nullable|string|max:64',
            'type' => ['required', Rule::in(RentalItem::TYPES)],
            'status' => ['required', Rule::in(RentalItem::STATUSES)],
            'condition' => ['required', Rule::in(RentalItem::CONDITIONS)],
            'rental_category_id' => 'nullable|exists:rental_categories,id',
            'warehouse_id' => 'nullable|exists:warehouses,id',
            'quantity' => 'nullable|integer|min:1|max:1000000',
            'default_period' => 'required|in:' . $periods,
            'hourly_rate' => 'nullable|numeric|min:0',
            'daily_rate' => 'nullable|numeric|min:0',
            'weekly_rate' => 'nullable|numeric|min:0',
            'monthly_rate' => 'nullable|numeric|min:0',
            'yearly_rate' => 'nullable|numeric|min:0',
            'deposit_amount' => 'nullable|numeric|min:0',
            'min_duration' => 'nullable|integer|min:1|max:65000',
            'min_duration_unit' => 'nullable|in:' . $periods,
            'area' => 'nullable|numeric|min:0',
            'bedrooms' => 'nullable|integer|min:0|max:255',
            'bathrooms' => 'nullable|integer|min:0|max:255',
            'year' => 'nullable|integer|min:1900|max:2200',
            'meter_reading' => 'nullable|numeric|min:0',
            'purchase_date' => 'nullable|date',
            'purchase_cost' => 'nullable|numeric|min:0',
            'new_images' => 'nullable|array|max:' . self::MAX_IMAGES,
            'new_images.*' => 'image|max:5120',
            'show_on_website' => 'nullable|boolean',
            'featured' => 'nullable|boolean',
            'slug' => 'nullable|string|max:191',
        ];
    }

    private function attributes(): array
    {
        return [
            'rental_category_id' => 'category',
            'warehouse_id' => 'branch',
            'default_period' => 'pricing period',
            'new_images.*' => 'photo',
        ];
    }

    private function payload(Request $request, ?RentalItem $existing = null): array
    {
        $num = fn ($key) => ($request->{$key} === null || $request->{$key} === '') ? null : $request->{$key};
        $type = $request->type;
        $isProperty = $type === 'property';

        $data = [
            'name' => $request->name,
            'type' => $type,
            'status' => $request->status,
            'condition' => $request->condition,
            'rental_category_id' => $request->rental_category_id ?: null,
            'warehouse_id' => $request->filled('warehouse_id')
                ? tap((int) $request->warehouse_id, fn ($w) => $this->abortIfWarehouseDenied($w))
                : null,
            'quantity' => max(1, (int) ($request->quantity ?: 1)),
            'default_period' => $request->default_period,
            'hourly_rate' => $num('hourly_rate'),
            'daily_rate' => $num('daily_rate'),
            'weekly_rate' => $num('weekly_rate'),
            'monthly_rate' => $num('monthly_rate'),
            'yearly_rate' => $num('yearly_rate'),
            'deposit_amount' => $num('deposit_amount') ?? 0,
            'min_duration' => $num('min_duration'),
            'min_duration_unit' => $num('min_duration') ? ($request->min_duration_unit ?: $request->default_period) : null,
            'purchase_date' => $request->purchase_date ?: null,
            'purchase_cost' => $num('purchase_cost'),
            'description' => $request->description,
            'notes' => $request->notes,
        ];

        // Only the field group matching the type is kept; switching an item's
        // type clears the other group instead of leaving stale values behind.
        $propertyFields = [
            'address' => $request->address,
            'city' => $request->city,
            'floor' => $request->floor,
            'area' => $num('area'),
            'area_unit' => $request->area_unit,
            'bedrooms' => $num('bedrooms'),
            'bathrooms' => $num('bathrooms'),
            'furnished' => $request->boolean('furnished'),
            'amenities' => array_values(array_filter(array_map('trim', $this->decodeList($request->amenities)))),
        ];
        $assetFields = [
            'brand' => $request->brand,
            'model' => $request->model,
            'serial_number' => $request->serial_number,
            'plate_number' => $type === 'vehicle' ? $request->plate_number : null,
            'year' => $num('year'),
            'meter_reading' => $num('meter_reading'),
            'meter_unit' => $request->meter_unit,
        ];

        foreach ($propertyFields as $key => $value) {
            $data[$key] = $isProperty ? $value : ($key === 'furnished' ? false : null);
        }
        foreach ($assetFields as $key => $value) {
            $data[$key] = $isProperty ? null : $value;
        }
        // A property can still carry a street address for "other" items.
        if ($type === 'other') {
            $data['address'] = $request->address;
            $data['city'] = $request->city;
        }
        if ($isProperty) {
            $data['quantity'] = 1;
        }

        // Website + the type's extra properties (RentalTypes), once migrated.
        if (RentalSetting::websiteReady()) {
            // specs: JSON from the multipart form; only this type's keys survive.
            if ($request->has('specs') || ! $existing) {
                $data['specs'] = RentalTypes::cleanSpecs($type, $request->input('specs')) ?: null;
            } elseif ($existing->type !== $type) {
                $data['specs'] = RentalTypes::cleanSpecs($type, $existing->specs) ?: null;
            }
            if ($request->has('show_on_website') || ! $existing) {
                $data['show_on_website'] = $request->has('show_on_website') ? $request->boolean('show_on_website') : true;
            }
            if ($request->has('featured')) {
                $data['featured'] = $request->boolean('featured');
            }
            if ($request->has('slug') || ! $existing || ! $existing->slug) {
                $data['slug'] = Str::slug(trim((string) $request->slug) ?: (string) $request->name) ?: null;
            }
        }

        return $data;
    }

    private function ensureUniqueCode(string $code, $ignoreId = null): void
    {
        $exists = RentalItem::whereNull('deleted_at')
            ->where('code', $code)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists();

        if ($exists) {
            abort(response()->json([
                'message' => 'This code is already used by another item.',
                'errors' => ['code' => ['This code is already used by another item.']],
            ], 422));
        }
    }

    /** Accepts a JSON string or an array (multipart sends JSON strings). */
    private function decodeList($value): array
    {
        if (is_array($value)) {
            return array_values(array_filter($value, 'is_string'));
        }
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
        }

        return [];
    }

    /**
     * Saves the uploaded photos and returns the final ordered file list.
     *
     * `image_order` lists the photos as the form shows them: an existing file
     * name, or "new:<i>" for the i-th file in new_images[]. Existing names not
     * belonging to this item are ignored; uploads the order forgot to mention
     * are appended, and anything past MAX_IMAGES is discarded.
     */
    private function storeImages(Request $request, array $current): array
    {
        $files = $request->file('new_images', []) ?: [];
        $uploaded = [];

        if ($files) {
            $path = upload_public_path(RentalItem::IMAGE_DIR);
            if (! file_exists($path)) {
                mkdir($path, 0755, true);
            }
            foreach (array_values($files) as $i => $file) {
                $extension = \App\Services\Rental\RentalUpload::extension($file, 'image');
                $filename = time() . '_' . Str::random(10) . '.' . $extension;
                $file->move($path, $filename);
                $uploaded[$i] = $filename;
            }
        }

        $images = [];
        foreach ($this->decodeList($request->image_order) as $token) {
            if (preg_match('/^new:(\d+)$/', $token, $m)) {
                if (isset($uploaded[(int) $m[1]])) {
                    $images[] = $uploaded[(int) $m[1]];
                }
            } elseif (in_array($token, $current, true)) {
                $images[] = $token;
            }
        }
        foreach ($uploaded as $filename) {
            if (! in_array($filename, $images, true)) {
                $images[] = $filename;
            }
        }
        $images = array_values(array_unique($images));

        foreach (array_slice($images, self::MAX_IMAGES) as $overflow) {
            $this->deleteImage($overflow);
        }

        return array_slice($images, 0, self::MAX_IMAGES);
    }

    private function deleteImage($filename): void
    {
        if (! $filename || ! is_string($filename) || str_contains($filename, '/') || str_contains($filename, '\\')) {
            return;
        }
        $path = upload_public_path(RentalItem::IMAGE_DIR . '/' . $filename);
        if (file_exists($path)) {
            @unlink($path);
        }
    }
}
