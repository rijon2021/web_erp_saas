<?php

namespace App\Services\Restaurant;

use App\Models\Product;
use App\Models\RestaurantAddonGroup;
use App\Models\RestaurantAddonOption;
use App\Models\RestaurantDishDetail;
use App\Models\RestaurantSetting;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The restaurant menu as staff (phase 4) and the online store (phase 8) see
 * it, and the one place that checks an add-on selection and prices it.
 */
class MenuService
{
    /**
     * The whole menu with what each dish needs on an ordering screen
     * (photo, dietary tags, add-on groups, variants, sold out / not served now).
     * $store: only what the online store sells (not hidden from it, category
     * shown in the store) — the QR table menu and the restaurant store theme.
     *
     * @return array{categories: \Illuminate\Support\Collection, dishes: \Illuminate\Support\Collection}
     */
    public function catalog(bool $store = false): array
    {
        $q = $store ? \App\Services\CheckoutService::sellableProducts() : Product::query()->whereNull('deleted_at')->where('is_active', 1);
        $products = $q->whereNotIn('id', BillingService::systemProductIds())
            ->when(\Illuminate\Support\Facades\Schema::hasColumn('products', 'not_selling'), fn ($q) => $q->where(fn ($w) => $w->whereNull('not_selling')->orWhere('not_selling', 0)))
            ->with('images:id,product_id,image_path,is_main,sort_order')
            ->orderBy('name')->limit(2000)
            ->get(['id', 'name', 'code', 'price', 'type', 'category_id', 'image', 'note', 'TaxNet', 'tax_method', 'discount', 'discount_method']);
        $catQ = \App\Models\Category::whereIn('id', $products->pluck('category_id')->filter()->unique())->whereNull('deleted_at');
        if ($store && \Illuminate\Support\Facades\Schema::hasColumn('categories', 'show_in_store')) {
            $catQ->where(fn ($w) => $w->whereNull('show_in_store')->orWhere('show_in_store', 1));
        }
        $categories = $catQ->orderBy('name')->get(['id', 'name']);
        if ($store) {
            $products = $products->whereIn('category_id', $categories->pluck('id'))->values();
        }
        $ids = $products->pluck('id')->all();
        // The store theme also works without the module: no dish details or add-ons then.
        $ready = RestaurantDishDetail::tablesReady() && (! $store || \App\Support\RestaurantModule::menuReady());
        $details = $ready ? $this->detailsFor($ids) : collect();
        $groups = $ready ? $this->groupsFor($ids) : [];
        $variants = \App\Models\ProductVariant::whereIn('product_id', $products->where('type', 'is_variant')->pluck('id'))->whereNull('deleted_at')
            ->orderBy('id')->get(['id', 'product_id', 'name', 'price'])->groupBy('product_id');
        $periods = RestaurantSetting::tablesReady() ? RestaurantSetting::current()->periodsAt() : [];

        return [
            'categories' => $categories,
            'dishes' => $products->map(function (Product $p) use ($details, $groups, $variants, $periods) {
                $d = $details[$p->id] ?? null;

                return [
                    'id' => $p->id,
                    'name' => $p->name,
                    'code' => $p->code,
                    'price' => (float) $p->price,
                    'category_id' => $p->category_id,
                    'image_url' => product_image_url($p->primaryProductImageFilename()),
                    'description' => \Illuminate\Support\Str::limit(strip_tags((string) $p->note), 200),
                    'dietary' => array_values($d->dietary ?? []),
                    'spice_level' => $d->spice_level ?? null,
                    'prep_minutes' => $d->prep_minutes ?? null,
                    'calories' => $d->calories ?? null,
                    'sold_out' => $d ? $d->isSoldOut() : false,
                    'available' => $this->availableNow($d, $periods),
                    'groups' => $groups[$p->id] ?? [],
                    'variants' => ($variants[$p->id] ?? collect())->map(fn ($v) => ['id' => $v->id, 'name' => $v->name, 'price' => (float) $v->price])->values(),
                ];
            })->values(),
        ];
    }

    /**
     * Add-on groups per product, in the dish's order, with their available options.
     *
     * @param  int[]  $productIds
     * @return array<int, array> product_id => [ ['id','name','min','max','options' => [...]], … ]
     */
    public function groupsFor(array $productIds): array
    {
        if (! $productIds) {
            return [];
        }
        $links = DB::table('rst_dish_addon_group')->whereIn('product_id', $productIds)->orderBy('sort_order')->orderBy('id')->get();
        if ($links->isEmpty()) {
            return [];
        }
        $groups = RestaurantAddonGroup::whereIn('id', $links->pluck('group_id')->unique())->where('is_active', true)
            ->with(['options' => fn ($q) => $q->select('id', 'group_id', 'name', 'price', 'is_default', 'is_available', 'sort_order')])
            ->get()->keyBy('id');

        $out = [];
        foreach ($links as $l) {
            $g = $groups[$l->group_id] ?? null;
            if (! $g) {
                continue;
            }
            $out[(int) $l->product_id][] = [
                'id' => $g->id,
                'name' => $g->name,
                'min' => $g->min_select,
                'max' => max($g->min_select, $g->max_select),
                'options' => $g->options->map(fn (RestaurantAddonOption $o) => [
                    'id' => $o->id, 'name' => $o->name, 'price' => (float) $o->price,
                    'is_default' => $o->is_default, 'available' => $o->is_available,
                ])->values()->all(),
            ];
        }

        return $out;
    }

    /**
     * Check a dish's add-on choices and price them.
     *
     * @param  int[]  $optionIds  chosen option ids (any order)
     * @return array{options: array<int, array{id:int,group_id:int,group:string,name:string,price:float,product_id:?int,stock_qty:float}>, extra: float, label: string}
     *
     * @throws MenuException when a required group is missing, a group has too many
     *                       choices, or an option is unknown / unavailable / not offered
     */
    public function priceSelection(int $productId, array $optionIds): array
    {
        $optionIds = array_values(array_unique(array_map('intval', array_filter($optionIds, 'is_numeric'))));
        $groups = $this->groupsFor([$productId])[$productId] ?? [];
        $allowed = collect($groups)->flatMap(fn ($g) => collect($g['options'])->map(fn ($o) => $o + ['group_id' => $g['id']]))->keyBy('id');

        foreach ($optionIds as $id) {
            $o = $allowed[$id] ?? null;
            if (! $o) {
                throw new MenuException(__('That choice is not offered for this dish.'));
            }
            if (! $o['available']) {
                throw new MenuException(__(':option is sold out.', ['option' => $o['name']]));
            }
        }
        foreach ($groups as $g) {
            $n = collect($g['options'])->whereIn('id', $optionIds)->count();
            if ($n < $g['min']) {
                throw new MenuException($g['min'] === 1 ? __('Choose a :group.', ['group' => mb_strtolower($g['name'])]) : __('Choose at least :n for :group.', ['n' => $g['min'], 'group' => $g['name']]));
            }
            if ($n > $g['max']) {
                throw new MenuException(__('Choose at most :n for :group.', ['n' => $g['max'], 'group' => $g['name']]));
            }
        }

        $rows = $optionIds ? RestaurantAddonOption::whereIn('id', $optionIds)->get(['id', 'group_id', 'name', 'price', 'product_id', 'stock_qty'])->keyBy('id') : collect();
        $names = collect($groups)->pluck('name', 'id');
        $order = collect($groups)->flatMap(fn ($g) => collect($g['options'])->pluck('id'))->flip();
        $chosen = collect($optionIds)->sortBy(fn ($id) => $order[$id] ?? PHP_INT_MAX)->map(function ($id) use ($rows, $names) {
            $o = $rows[$id];

            return [
                'id' => $o->id, 'group_id' => $o->group_id, 'group' => (string) ($names[$o->group_id] ?? ''), 'name' => $o->name,
                'price' => round((float) $o->price, 2), 'product_id' => $o->product_id, 'stock_qty' => (float) $o->stock_qty,
            ];
        })->values();

        return [
            'options' => $chosen->all(),
            'extra' => round($chosen->sum('price'), 2),
            'label' => $chosen->map(fn ($o) => $o['name'].($o['price'] ? ' +'.number_format($o['price'], 2) : ''))->implode(' · '),
        ];
    }

    /**
     * Dish details keyed by product id (sold-out flag resolved for today).
     *
     * @param  int[]  $productIds
     */
    public function detailsFor(array $productIds): Collection
    {
        if (! $productIds) {
            return collect();
        }

        return RestaurantDishDetail::whereIn('product_id', $productIds)->with('station:id,name')->get()->keyBy('product_id');
    }

    /**
     * Save a dish's restaurant details and add-on groups (menu page and product
     * form). A dish with nothing set and no groups loses its details row.
     *
     * @param  int[]|null  $groupIds  null = leave the groups as they are
     */
    public function saveDish(int $productId, array $input, ?array $groupIds): void
    {
        $clean = RestaurantDishDetail::clean($input);
        if ($clean['station_id'] && ! \App\Models\RestaurantStation::whereKey($clean['station_id'])->exists()) {
            $clean['station_id'] = null;
        }
        DB::transaction(function () use ($productId, $clean, $groupIds) {
            $hasData = $clean['station_id'] || $clean['prep_minutes'] !== null || $clean['calories'] !== null || $clean['spice_level'] || $clean['dietary'] || $clean['meal_periods'];
            $existing = RestaurantDishDetail::where('product_id', $productId)->first();
            if ($hasData || ($existing && $existing->sold_out_on)) {
                RestaurantDishDetail::updateOrCreate(['product_id' => $productId], $clean);
            } elseif ($existing) {
                $existing->delete();
            }
            if ($groupIds !== null) {
                $valid = RestaurantAddonGroup::whereIn('id', array_map('intval', $groupIds))->pluck('id')->all();
                $ordered = array_values(array_unique(array_filter(array_map('intval', $groupIds), fn ($id) => in_array($id, $valid, true))));
                DB::table('rst_dish_addon_group')->where('product_id', $productId)->delete();
                foreach ($ordered as $i => $gid) {
                    DB::table('rst_dish_addon_group')->insert(['product_id' => $productId, 'group_id' => $gid, 'sort_order' => $i]);
                }
            }
        });
    }

    /** Is a dish offered right now (not sold out, inside one of its meal periods)? */
    public function availableNow(?RestaurantDishDetail $d, ?array $openPeriods = null): bool
    {
        if (! $d) {
            return true;
        }
        if ($d->isSoldOut()) {
            return false;
        }
        $periods = $d->meal_periods ?: [];
        if (! $periods) {
            return true;
        }
        $openPeriods ??= RestaurantSetting::current()->periodsAt();

        return (bool) array_intersect($periods, $openPeriods);
    }
}
