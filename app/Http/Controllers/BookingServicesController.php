<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\BookingServiceDetail;
use App\Models\BookingSetting;
use App\Models\BookingStaff;
use App\Models\Category;
use App\Models\Product;
use App\Services\Booking\BookingDepositService;
use App\Support\BookingTypes;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Booking Management › Services (permission bookings_services).
 *
 * Services are service products (products.type = is_service); this page adds
 * their booking details (bk_services). Products are created and edited on
 * the product form — "Add service" opens it preset to a service.
 *
 * GET    bookings/services                        → {ready, rows, counts, categories, types}
 * PUT    bookings/services/{productId}            → {row}   set up / edit booking details
 * DELETE bookings/services/{productId}            → remove the booking details (not bookable)
 * POST   bookings/services/bulk                   → {product_ids, action: setup|show|hide|remove}
 * GET    bookings/services/suggested-categories   → {groups: [{type, names: [{name, exists}]}]}
 * POST   bookings/services/suggested-categories   → {names} creates the missing product categories
 */
class BookingServicesController extends BaseController
{
    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'bookings_services', Booking::class);

        if (! BookingServiceDetail::tablesReady()) {
            return response()->json(['ready' => false, 'rows' => [], 'counts' => null, 'categories' => [], 'types' => BookingTypes::enabled()]);
        }

        $request->validate([
            'search' => 'nullable|string|max:120',
            'category_id' => 'nullable|integer',
            'status' => ['nullable', Rule::in(['all', 'set_up', 'website', 'hidden', 'not_set_up'])],
        ]);

        $base = $this->scopeProductsToWarehouses(
            DB::table('products')->whereNull('products.deleted_at')->where('products.type', 'is_service')
        )->leftJoin('bk_services as s', 's.product_id', '=', 'products.id');

        $counts = (clone $base)->selectRaw(
            'COUNT(*) as all_count, SUM(s.id IS NOT NULL) as set_up, SUM(s.id IS NOT NULL AND s.show_on_website = 1) as website, SUM(s.id IS NULL) as not_set_up'
        )->first();

        $q = (clone $base)
            ->leftJoin('categories as c', 'c.id', '=', 'products.category_id')
            ->when($request->filled('search'), function ($q) use ($request) {
                $s = '%'.str_replace(['%', '_'], ['\%', '\_'], trim($request->search)).'%';
                $q->where(fn ($w) => $w->where('products.name', 'like', $s)->orWhere('products.code', 'like', $s));
            })
            ->when($request->filled('category_id'), fn ($q) => $q->where('products.category_id', (int) $request->category_id))
            ->when($request->status === 'set_up', fn ($q) => $q->whereNotNull('s.id'))
            ->when($request->status === 'website', fn ($q) => $q->whereNotNull('s.id')->where('s.show_on_website', 1))
            ->when($request->status === 'hidden', fn ($q) => $q->whereNotNull('s.id')->where('s.show_on_website', 0))
            ->when($request->status === 'not_set_up', fn ($q) => $q->whereNull('s.id'))
            ->orderByRaw('s.id IS NULL')
            ->orderBy('s.sort_order')
            ->orderBy('products.name')
            ->limit(1000)
            ->select([
                'products.id', 'products.name', 'products.code', 'products.price', 'products.image', 'products.category_id',
                'c.name as category_name',
                's.id as detail_id', 's.business_type', 's.duration_minutes', 's.buffer_before', 's.buffer_after', 's.seats',
                's.price_display', 's.booking_modes', 's.show_on_website', 's.featured', 's.sort_order', 's.short_description',
            ])
            ->addSelect($this->depositColumns());

        $categories = $this->scopeProductsToWarehouses(
            DB::table('products')->whereNull('products.deleted_at')->where('products.type', 'is_service')
        )->join('categories as c', 'c.id', '=', 'products.category_id')->whereNull('c.deleted_at')
            ->distinct()->orderBy('c.name')->get(['c.id', 'c.name']);

        return response()->json([
            'ready' => true,
            'rows' => $this->withStaff($q->get())->map(fn ($r) => $this->presentRow($r))->values(),
            'counts' => [
                'all' => (int) ($counts->all_count ?? 0),
                'set_up' => (int) ($counts->set_up ?? 0),
                'website' => (int) ($counts->website ?? 0),
                'not_set_up' => (int) ($counts->not_set_up ?? 0),
            ],
            'categories' => $categories,
            'types' => BookingTypes::enabled(),
            // Phase 8: the default deposit a service follows unless it has its own.
            'deposits' => Booking::depositsReady() ? [
                'ready' => true,
                'default' => app(BookingDepositService::class)->ruleFor(null),
                'currency' => app(BookingDepositService::class)->currency(),
            ] : ['ready' => false],
            // Phase 3: who can be linked to a service (empty before the staff migration).
            'staff' => BookingStaff::tablesReady()
                ? BookingStaff::query()->active()->orderBy('sort_order')->orderBy('name')->get(['id', 'name', 'color'])
                : [],
        ]);
    }

    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'bookings_services', Booking::class);
        $this->ensureReady();
        $product = $this->serviceProduct($id);

        $data = $request->validate([
            'business_type' => ['nullable', 'string', Rule::in(BookingTypes::TYPES)],
            'duration_minutes' => 'required|integer|min:5|max:1440',
            'buffer_before' => 'required|integer|min:0|max:240',
            'buffer_after' => 'required|integer|min:0|max:240',
            'seats' => 'required|integer|min:1|max:500',
            'price_display' => ['required', Rule::in(BookingServiceDetail::PRICE_DISPLAYS)],
            'booking_modes' => ['required', Rule::in(BookingServiceDetail::BOOKING_MODES)],
            'show_on_website' => 'required|boolean',
            'featured' => 'required|boolean',
            'sort_order' => 'nullable|integer|min:0|max:100000',
            'short_description' => 'nullable|string|max:500',
            // Phase 3: the staff who do it (absent = leave the links as they are; [] = anyone).
            'staff_ids' => 'sometimes|array|max:500',
            'staff_ids.*' => 'integer',
            // Phase 8: the service's own deposit (null = the Booking Settings default)
            'deposit_type' => ['sometimes', 'nullable', Rule::in(BookingSetting::DEPOSIT_TYPES)],
            'deposit_value' => 'sometimes|nullable|numeric|min:0|max:99999999',
        ]);
        if (! Booking::depositsReady()) {
            unset($data['deposit_type'], $data['deposit_value']);
        } elseif (array_key_exists('deposit_type', $data)) {
            if ($data['deposit_type'] === 'percent' && (float) ($data['deposit_value'] ?? 0) > 100) {
                return response()->json(['message' => __('A percentage deposit can be at most 100.'), 'errors' => ['deposit_value' => [__('A percentage deposit can be at most 100.')]]], 422);
            }
            $data['deposit_value'] = in_array($data['deposit_type'], ['fixed', 'percent'], true) ? round((float) ($data['deposit_value'] ?? 0), 2) : null;
        }
        $staffIds = array_key_exists('staff_ids', $data) ? array_values(array_unique(array_map('intval', $data['staff_ids']))) : null;
        unset($data['staff_ids']);
        $data['sort_order'] = (int) ($data['sort_order'] ?? 0);
        $data['short_description'] = trim((string) ($data['short_description'] ?? '')) ?: null;
        $data['featured'] = $data['featured'] && $data['show_on_website'];

        DB::transaction(function () use ($product, $data, $staffIds) {
            BookingServiceDetail::updateOrCreate(['product_id' => $product->id], $data);
            if ($staffIds !== null && BookingStaff::tablesReady()) {
                $valid = BookingStaff::whereIn('id', $staffIds)->pluck('id')->all();
                DB::table('bk_service_staff')->where('product_id', $product->id)->delete();
                if ($valid) {
                    DB::table('bk_service_staff')->insert(array_map(fn ($sid) => ['product_id' => $product->id, 'staff_id' => $sid], $valid));
                }
            }
        });

        return response()->json(['row' => $this->rowFor($product->id)]);
    }

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'bookings_services', Booking::class);
        $this->ensureReady();
        $product = $this->serviceProduct($id);

        BookingServiceDetail::where('product_id', $product->id)->delete();

        return response()->json(['row' => $this->rowFor($product->id)]);
    }

    public function bulk(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'bookings_services', Booking::class);
        $this->ensureReady();

        $data = $request->validate([
            'product_ids' => 'required|array|min:1|max:500',
            'product_ids.*' => 'integer',
            'action' => ['required', Rule::in(['setup', 'show', 'hide', 'remove'])],
        ]);

        // Only service products this user may see; anything else is ignored.
        $ids = $this->scopeProductsToWarehouses(
            DB::table('products')->whereNull('products.deleted_at')->where('products.type', 'is_service')
                ->whereIn('products.id', array_unique(array_map('intval', $data['product_ids'])))
        )->pluck('products.id')->all();

        $changed = 0;
        DB::transaction(function () use ($data, $ids, &$changed) {
            switch ($data['action']) {
                case 'setup':
                    $have = BookingServiceDetail::whereIn('product_id', $ids)->pluck('product_id')->all();
                    $enabled = BookingTypes::enabled();
                    foreach (array_diff($ids, $have) as $pid) {
                        BookingServiceDetail::create([
                            'product_id' => $pid,
                            'business_type' => count($enabled) === 1 ? $enabled[0] : null,
                            'duration_minutes' => BookingServiceDetail::DEFAULT_DURATION,
                        ]);
                        $changed++;
                    }
                    break;
                case 'show':
                case 'hide':
                    $changed = BookingServiceDetail::whereIn('product_id', $ids)
                        ->update(['show_on_website' => $data['action'] === 'show'] + ($data['action'] === 'hide' ? ['featured' => false] : []));
                    break;
                case 'remove':
                    $changed = BookingServiceDetail::whereIn('product_id', $ids)->delete();
                    break;
            }
        });

        return response()->json(['changed' => $changed]);
    }

    public function suggestions(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'bookings_services', Booking::class);

        $existing = $this->existingCategoryNames();
        $groups = [];
        foreach (BookingTypes::enabled() as $type) {
            $names = BookingTypes::suggestedCategories($type);
            if (! $names) {
                continue;
            }
            $groups[] = [
                'type' => $type,
                'names' => array_map(fn ($n) => ['name' => $n, 'exists' => isset($existing[mb_strtolower($n)])], $names),
            ];
        }

        return response()->json(['groups' => $groups]);
    }

    public function createSuggested(Request $request)
    {
        $user = $request->user('api');
        $this->authorizeForUser($user, 'bookings_services', Booking::class);
        $this->authorizeForUser($user, 'create', Category::class);

        $allowed = [];
        foreach (BookingTypes::TYPES as $type) {
            foreach (BookingTypes::suggestedCategories($type) as $n) {
                $allowed[] = $n;
            }
        }
        $data = $request->validate([
            'names' => 'required|array|min:1|max:50',
            'names.*' => ['string', Rule::in(array_values(array_unique($allowed)))],
        ]);

        $existing = $this->existingCategoryNames();
        $created = [];
        foreach (array_unique($data['names']) as $name) {
            if (isset($existing[mb_strtolower($name)])) {
                continue;
            }
            $created[] = Category::create([
                'name' => $name,
                'code' => $this->categoryCode($name),
                'show_in_store' => true,
            ])->only(['id', 'name']);
            $existing[mb_strtolower($name)] = true;
        }

        return response()->json(['created' => $created]);
    }

    // ------------------------------------------------------------------

    private function ensureReady(): void
    {
        if (! BookingServiceDetail::tablesReady()) {
            abort(response()->json(['message' => __('Run the latest database update first.')], 503));
        }
    }

    private function serviceProduct($id): Product
    {
        $product = Product::whereNull('deleted_at')->where('type', 'is_service')->findOrFail((int) $id);
        $this->abortIfProductDenied($product);

        return $product;
    }

    private function rowFor(int $productId): array
    {
        $r = DB::table('products')->where('products.id', $productId)
            ->leftJoin('bk_services as s', 's.product_id', '=', 'products.id')
            ->leftJoin('categories as c', 'c.id', '=', 'products.category_id')
            ->first([
                'products.id', 'products.name', 'products.code', 'products.price', 'products.image', 'products.category_id',
                'c.name as category_name',
                's.id as detail_id', 's.business_type', 's.duration_minutes', 's.buffer_before', 's.buffer_after', 's.seats',
                's.price_display', 's.booking_modes', 's.show_on_website', 's.featured', 's.sort_order', 's.short_description',
                ...$this->depositColumns(),
            ]);

        return $this->presentRow($this->withStaff(collect([$r]))->first());
    }

    /** Adds `staff_ids` (linked staff; [] = anyone) to each row. */
    private function withStaff($rows)
    {
        $links = BookingStaff::tablesReady()
            ? DB::table('bk_service_staff as l')->join('bk_staff as st', 'st.id', '=', 'l.staff_id')->whereNull('st.deleted_at')
                ->whereIn('l.product_id', collect($rows)->pluck('id')->all())->get(['l.product_id', 'l.staff_id'])->groupBy('product_id')
            : collect();

        return collect($rows)->each(function ($r) use ($links) {
            $r->staff_ids = collect($links[$r->id] ?? [])->pluck('staff_id')->map(fn ($v) => (int) $v)->values()->all();
        });
    }

    private function presentRow($r): array
    {
        $set = $r->detail_id !== null;

        return [
            'id' => (int) $r->id,
            'name' => $r->name,
            'code' => $r->code,
            'price' => (float) $r->price,
            'image' => $r->image && $r->image !== 'no-image.png' ? $r->image : null,
            'category_id' => $r->category_id ? (int) $r->category_id : null,
            'category_name' => $r->category_name,
            'set_up' => $set,
            'staff_ids' => $r->staff_ids ?? [],
            'detail' => $set ? [
                'business_type' => $r->business_type,
                'duration_minutes' => (int) $r->duration_minutes,
                'buffer_before' => (int) $r->buffer_before,
                'buffer_after' => (int) $r->buffer_after,
                'seats' => (int) $r->seats,
                'price_display' => $r->price_display,
                'booking_modes' => $r->booking_modes,
                'show_on_website' => (bool) $r->show_on_website,
                'featured' => (bool) $r->featured,
                'sort_order' => (int) $r->sort_order,
                'short_description' => (string) ($r->short_description ?? ''),
                'deposit_type' => property_exists($r, 'deposit_type') ? $r->deposit_type : null,
                'deposit_value' => property_exists($r, 'deposit_value') && $r->deposit_value !== null ? (float) $r->deposit_value : null,
            ] : null,
        ];
    }

    /** The phase 8 deposit columns, once that migration has run. */
    private function depositColumns(): array
    {
        return Booking::depositsReady() ? ['s.deposit_type', 's.deposit_value'] : [];
    }

    /** lower-cased name => true for every live product category. */
    private function existingCategoryNames(): array
    {
        $out = [];
        foreach (Category::whereNull('deleted_at')->pluck('name') as $n) {
            $out[mb_strtolower(trim((string) $n))] = true;
        }

        return $out;
    }

    /** A short unique code from the name: "Skin & spa" → "SKIN-SPA" (then -2, -3…). */
    private function categoryCode(string $name): string
    {
        $base = Str::upper(Str::limit(Str::slug($name), 20, '')) ?: 'CAT';
        $code = $base;
        $n = 2;
        while (Category::withTrashed()->where('code', $code)->exists()) {
            $code = $base.'-'.$n++;
        }

        return $code;
    }
}
