<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Product;
use App\Models\RestaurantAddonGroup;
use App\Models\RestaurantAddonOption;
use App\Models\RestaurantDishDetail;
use App\Models\RestaurantSetting;
use App\Models\RestaurantStation;
use App\Services\Restaurant\BillingService;
use App\Services\Restaurant\MenuService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Restaurant Management › Menu (permission rst_menu): dishes (details, add-on
 * groups, sold out today), add-on groups, kitchen stations and meal times.
 */
class RestaurantMenuController extends BaseController
{
    public function __construct(private MenuService $menu)
    {
    }

    /** GET restaurant/menu/setup → {ready, stations, groups, menu_times, categories} */
    public function setup(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'rst_menu', RestaurantSetting::class);
        if (! RestaurantDishDetail::tablesReady()) {
            return response()->json(['ready' => false]);
        }

        return response()->json([
            'ready' => true,
            'stations' => RestaurantStation::orderBy('sort_order')->orderBy('id')->get(['id', 'name', 'is_active']),
            'groups' => $this->groupsPayload(),
            'menu_times' => RestaurantSetting::current()->menuTimes(),
            'categories' => Category::whereNull('deleted_at')->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /** GET restaurant/menu/dishes?search&category_id&only=configured&page&per_page */
    public function dishes(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'rst_menu', RestaurantSetting::class);
        if (! RestaurantDishDetail::tablesReady()) {
            return response()->json(['data' => [], 'meta' => ['total' => 0]]);
        }
        $q = Product::query()->whereNull('products.deleted_at')->where('products.is_active', 1)
            ->whereNotIn('products.id', BillingService::systemProductIds())
            ->select('products.id', 'products.name', 'products.code', 'products.price', 'products.category_id', 'products.type', 'products.image')
            ->with(['category:id,name', 'images:id,product_id,image_path,is_main,sort_order']);
        // Settings › "Restrict products by warehouse": only the user's own products.
        $this->scopeProductsToWarehouses($q);
        // The add-on ingredient picker: only products that have one stock row.
        if ($request->input('only') === 'ingredients') {
            $q->whereIn('products.type', ['is_single', 'is_combo']);
        }
        if ($request->filled('search')) {
            $term = '%'.$request->search.'%';
            $q->where(fn ($w) => $w->where('products.name', 'like', $term)->orWhere('products.code', 'like', $term));
        }
        if ($request->filled('category_id')) {
            $q->where('products.category_id', (int) $request->category_id);
        }
        if ($request->input('only') === 'configured') {
            $q->where(fn ($w) => $w->whereExists(fn ($s) => $s->select(DB::raw(1))->from('rst_dish_details')->whereColumn('rst_dish_details.product_id', 'products.id'))
                ->orWhereExists(fn ($s) => $s->select(DB::raw(1))->from('rst_dish_addon_group')->whereColumn('rst_dish_addon_group.product_id', 'products.id')));
        }
        $rows = $q->orderBy('products.name')->paginate(max(1, min(100, (int) $request->input('per_page', 20))));
        $ids = $rows->getCollection()->pluck('id')->all();
        $details = $this->menu->detailsFor($ids);
        $groups = DB::table('rst_dish_addon_group')->whereIn('product_id', $ids)->orderBy('sort_order')->get()->groupBy('product_id');

        return response()->json([
            'data' => $rows->getCollection()->map(function (Product $p) use ($details, $groups) {
                $d = $details[$p->id] ?? null;

                return [
                    'id' => $p->id, 'name' => $p->name, 'code' => $p->code, 'price' => (float) $p->price,
                    'type' => $p->type, 'category' => $p->category->name ?? null,
                    'image_url' => product_image_url($p->primaryProductImageFilename()),
                    'details' => $this->detailPayload($d),
                    'addon_group_ids' => ($groups[$p->id] ?? collect())->pluck('group_id')->map(fn ($v) => (int) $v)->values(),
                ];
            })->values(),
            'meta' => ['total' => $rows->total(), 'page' => $rows->currentPage(), 'pages' => $rows->lastPage()],
        ]);
    }

    /** PUT restaurant/menu/dishes/{productId} */
    public function saveDish(Request $request, $productId)
    {
        $this->authorizeForUser($request->user('api'), 'rst_menu', RestaurantSetting::class);
        $this->ready();
        $product = Product::whereNull('deleted_at')->findOrFail((int) $productId);
        $this->abortIfProductDenied($product);
        $data = $this->validateDish($request);
        // Groups left out of the request stay as they are (null), an empty list removes them.
        $this->menu->saveDish($product->id, $data, $request->has('addon_group_ids') ? ($data['addon_group_ids'] ?? []) : null);

        return response()->json(['details' => $this->detailPayload(RestaurantDishDetail::where('product_id', $product->id)->with('station:id,name')->first())]);
    }

    /** POST restaurant/menu/dishes/{productId}/sold-out {sold_out: bool} */
    public function soldOut(Request $request, $productId)
    {
        $this->authorizeForUser($request->user('api'), 'rst_menu', RestaurantSetting::class);
        $this->ready();
        $product = Product::whereNull('deleted_at')->findOrFail((int) $productId);
        $this->abortIfProductDenied($product);
        $soldOut = (bool) $request->validate(['sold_out' => 'required|boolean'])['sold_out'];
        $d = RestaurantDishDetail::firstOrNew(['product_id' => $product->id]);
        $d->sold_out_on = $soldOut ? now()->toDateString() : null;
        if ($d->exists || $soldOut) {
            $d->save();
        }

        return response()->json(['sold_out' => $soldOut]);
    }

    // ---------- stations ----------

    public function storeStation(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'rst_menu', RestaurantSetting::class);
        $this->ready();
        $data = $request->validate(['name' => 'required|string|max:60', 'is_active' => 'nullable|boolean']);
        $s = RestaurantStation::create(['name' => trim($data['name']), 'is_active' => $data['is_active'] ?? true, 'sort_order' => (int) RestaurantStation::max('sort_order') + 1]);

        return response()->json(['id' => $s->id], 201);
    }

    public function updateStation(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'rst_menu', RestaurantSetting::class);
        $this->ready();
        $data = $request->validate(['name' => 'required|string|max:60', 'is_active' => 'nullable|boolean']);
        RestaurantStation::findOrFail((int) $id)->update(['name' => trim($data['name']), 'is_active' => $data['is_active'] ?? true]);

        return response()->json(['success' => true]);
    }

    /** Deleting a station leaves its dishes without one (they show on every kitchen screen). */
    public function destroyStation(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'rst_menu', RestaurantSetting::class);
        $this->ready();
        $station = RestaurantStation::findOrFail((int) $id);
        DB::transaction(function () use ($station) {
            RestaurantDishDetail::where('station_id', $station->id)->update(['station_id' => null]);
            $station->delete();
        });

        return response()->json(['success' => true]);
    }

    /** POST restaurant/menu/stations/defaults — Grill, Fryer, Cold, Desserts, Drinks (skips names already there). */
    public function defaultStations(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'rst_menu', RestaurantSetting::class);
        $this->ready();
        $have = RestaurantStation::pluck('name')->map(fn ($n) => mb_strtolower($n))->all();
        $order = (int) RestaurantStation::max('sort_order');
        $added = [];
        foreach (RestaurantStation::DEFAULTS as $name) {
            if (! in_array(mb_strtolower($name), $have, true)) {
                RestaurantStation::create(['name' => $name, 'sort_order' => ++$order]);
                $added[] = $name;
            }
        }

        return response()->json(['added' => $added], 201);
    }

    public function reorderStations(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'rst_menu', RestaurantSetting::class);
        $this->ready();
        $ids = $request->validate(['ids' => 'required|array|max:100', 'ids.*' => 'integer'])['ids'];
        foreach (array_values($ids) as $i => $id) {
            RestaurantStation::whereKey((int) $id)->update(['sort_order' => $i]);
        }

        return response()->json(['success' => true]);
    }

    // ---------- add-on groups ----------

    public function storeGroup(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'rst_menu', RestaurantSetting::class);
        $this->ready();
        $data = $this->validateGroup($request);
        $group = DB::transaction(function () use ($data) {
            $g = RestaurantAddonGroup::create($this->groupAttributes($data) + ['sort_order' => (int) RestaurantAddonGroup::max('sort_order') + 1]);
            $this->syncOptions($g, $data['options']);

            return $g;
        });

        return response()->json(['id' => $group->id], 201);
    }

    public function updateGroup(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'rst_menu', RestaurantSetting::class);
        $this->ready();
        $group = RestaurantAddonGroup::findOrFail((int) $id);
        $data = $this->validateGroup($request);
        DB::transaction(function () use ($group, $data) {
            $group->update($this->groupAttributes($data));
            $this->syncOptions($group, $data['options']);
        });

        return response()->json(['success' => true]);
    }

    public function destroyGroup(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'rst_menu', RestaurantSetting::class);
        $this->ready();
        $group = RestaurantAddonGroup::findOrFail((int) $id);
        DB::transaction(function () use ($group) {
            DB::table('rst_dish_addon_group')->where('group_id', $group->id)->delete();
            $group->options()->delete();
            $group->delete();
        });

        return response()->json(['success' => true]);
    }

    /** POST restaurant/menu/options/{id}/availability {available: bool} — quick "sold out" for one choice. */
    public function optionAvailability(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'rst_menu', RestaurantSetting::class);
        $this->ready();
        $available = (bool) $request->validate(['available' => 'required|boolean'])['available'];
        RestaurantAddonOption::findOrFail((int) $id)->update(['is_available' => $available]);

        return response()->json(['available' => $available]);
    }

    // ---------- meal times ----------

    /** PUT restaurant/menu/times {breakfast: {from, to}, lunch: {…}, dinner: {…}} */
    public function saveTimes(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'rst_menu', RestaurantSetting::class);
        $this->ready();
        $rules = [];
        foreach (RestaurantDishDetail::MEAL_PERIODS as $p) {
            $rules["$p.from"] = ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'];
            $rules["$p.to"] = ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/', "different:$p.from"];
        }
        $data = $request->validate($rules);
        $s = RestaurantSetting::current();
        $s->menu_times = array_intersect_key($data, array_flip(RestaurantDishDetail::MEAL_PERIODS));
        $s->save();

        return response()->json(['menu_times' => $s->fresh()->menuTimes()]);
    }

    // ------------------------------------------------------------------

    private function ready(): void
    {
        if (! RestaurantDishDetail::tablesReady()) {
            abort(503, 'Run the latest database update first.');
        }
    }

    private function validateDish(Request $request): array
    {
        return $request->validate([
            'station_id' => ['nullable', 'integer', Rule::exists('rst_stations', 'id')->whereNull('deleted_at')],
            'prep_minutes' => 'nullable|integer|min:0|max:600',
            'calories' => 'nullable|integer|min:0|max:20000',
            'spice_level' => ['nullable', Rule::in(RestaurantDishDetail::SPICE_LEVELS)],
            'dietary' => 'nullable|array',
            'dietary.*' => Rule::in(RestaurantDishDetail::DIETARY),
            'meal_periods' => 'nullable|array',
            'meal_periods.*' => Rule::in(RestaurantDishDetail::MEAL_PERIODS),
            'addon_group_ids' => 'nullable|array|max:20',
            'addon_group_ids.*' => ['integer', Rule::exists('rst_addon_groups', 'id')->whereNull('deleted_at')],
        ]);
    }

    private function validateGroup(Request $request): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:80',
            'min_select' => 'required|integer|min:0|max:20',
            'max_select' => 'required|integer|min:1|max:20|gte:min_select',
            'is_active' => 'nullable|boolean',
            'options' => 'required|array|min:1|max:50',
            'options.*.id' => 'nullable|integer',
            'options.*.name' => 'required|string|max:80',
            'options.*.price' => 'nullable|numeric|min:0|max:1000000',
            // An ingredient must have a single stock row: not a variant product or a service.
            'options.*.product_id' => ['nullable', 'integer', Rule::exists('products', 'id')->whereNull('deleted_at')->whereIn('type', ['is_single', 'is_combo'])],
            'options.*.stock_qty' => 'nullable|numeric|min:0.001|max:100000',
            'options.*.is_default' => 'nullable|boolean',
            'options.*.is_available' => 'nullable|boolean',
        ]);
        if ($data['max_select'] > count($data['options'])) {
            $data['max_select'] = count($data['options']);
        }
        if ($data['min_select'] > count($data['options'])) {
            abort(response()->json(['message' => __('A group cannot require more choices than it has options.'), 'errors' => ['min_select' => [__('A group cannot require more choices than it has options.')]]], 422));
        }

        return $data;
    }

    private function groupAttributes(array $data): array
    {
        return [
            'name' => trim($data['name']),
            'min_select' => (int) $data['min_select'],
            'max_select' => max((int) $data['min_select'], (int) $data['max_select']),
            'is_active' => (bool) ($data['is_active'] ?? true),
        ];
    }

    /** Keep options whose id is sent, create new ones, soft-delete the rest (orders keep their names). */
    private function syncOptions(RestaurantAddonGroup $group, array $options): void
    {
        $keep = [];
        foreach (array_values($options) as $i => $o) {
            $attrs = [
                'name' => trim($o['name']),
                'price' => round((float) ($o['price'] ?? 0), 2),
                'product_id' => $o['product_id'] ?? null,
                'stock_qty' => isset($o['product_id']) && $o['product_id'] ? (float) ($o['stock_qty'] ?? 1) : 1,
                'is_default' => (bool) ($o['is_default'] ?? false),
                'is_available' => (bool) ($o['is_available'] ?? true),
                'sort_order' => $i,
            ];
            $existing = ! empty($o['id']) ? RestaurantAddonOption::where('group_id', $group->id)->whereKey((int) $o['id'])->first() : null;
            if ($existing) {
                $existing->update($attrs);
                $keep[] = $existing->id;
            } else {
                $keep[] = RestaurantAddonOption::create($attrs + ['group_id' => $group->id])->id;
            }
        }
        RestaurantAddonOption::where('group_id', $group->id)->whereNotIn('id', $keep)->delete();
    }

    private function groupsPayload(): array
    {
        $groups = RestaurantAddonGroup::orderBy('sort_order')->orderBy('id')->with(['options.product:id,name'])->get();
        $usage = DB::table('rst_dish_addon_group')->select('group_id', DB::raw('COUNT(*) AS c'))->groupBy('group_id')->pluck('c', 'group_id');

        return $groups->map(fn (RestaurantAddonGroup $g) => [
            'id' => $g->id, 'name' => $g->name, 'min_select' => $g->min_select, 'max_select' => $g->max_select,
            'is_active' => $g->is_active, 'dishes' => (int) ($usage[$g->id] ?? 0),
            'options' => $g->options->map(fn (RestaurantAddonOption $o) => [
                'id' => $o->id, 'name' => $o->name, 'price' => (float) $o->price, 'product_id' => $o->product_id,
                'product_name' => $o->product->name ?? null, 'stock_qty' => (float) $o->stock_qty,
                'is_default' => $o->is_default, 'is_available' => $o->is_available,
            ])->values(),
        ])->values()->all();
    }

    private function detailPayload(?RestaurantDishDetail $d): array
    {
        return [
            'station_id' => $d->station_id ?? null,
            'station' => $d && $d->station ? $d->station->name : null,
            'prep_minutes' => $d->prep_minutes ?? null,
            'calories' => $d->calories ?? null,
            'spice_level' => $d->spice_level ?? null,
            'dietary' => array_values($d->dietary ?? []),
            'meal_periods' => array_values($d->meal_periods ?? []),
            'sold_out' => $d ? $d->isSoldOut() : false,
        ];
    }
}
