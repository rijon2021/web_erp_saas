<?php

namespace App\Http\Controllers\Api\Store;

use App\Http\Controllers\Controller;
use App\Models\CourierAccount;
use App\Models\ShippingMethod;
use App\Models\StoreSetting;
use App\Services\Shipping\LiveShippingRates;
use App\Services\StoreCurrencyService;
use App\Services\TenantLimitsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class ShippingMethodsController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'store_shipping_methods', StoreSetting::class);

        $query = ShippingMethod::with('regions')
            ->orderBy('sort_order')
            ->orderBy('name');

        if ($request->filled('search')) {
            $query->where('name', 'like', '%'.$request->search.'%');
        }

        $methods = $query->get()->map(fn ($m) => [
            'id' => $m->id,
            'name' => $m->name,
            'price' => (float) $m->price,
            'active' => (bool) $m->active,
            'sort_order' => (int) $m->sort_order,
            'countries' => $m->regions->pluck('country')->values(),
            'type' => $m->type ?? ShippingMethod::TYPE_FLAT,
            'live_settings' => $m->live_settings ?: (object) [],
        ]);

        return response()->json([
            'methods' => $methods,
            // The canonical list the region matcher understands, so the picker
            // cannot produce a country checkout will fail to recognise.
            'country_options' => \App\Services\CountryService::options(),
            // Live carrier rates: needs the 2026_10_27 migration and a Shippo or
            // Shipbubble account under Couriers (plan feature `couriers`).
            'live_supported' => $this->liveColumns(),
            'live_accounts' => $this->liveAccounts(),
            'live_couriers' => collect(LiveShippingRates::COURIERS)
                ->map(fn ($code) => ['code' => $code, 'name' => \App\Services\Couriers\CourierRegistry::meta($code)['name'] ?? $code])
                ->values(),
            'printful_available' => $this->liveColumns() && $this->printfulAvailable(),
            'base_currency' => StoreCurrencyService::base()['code'] ?? 'USD',
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'store_shipping_methods', StoreSetting::class);

        $data = $this->validateData($request);

        $method = DB::transaction(function () use ($data) {
            $method = ShippingMethod::create($this->attributes($data));
            $this->syncRegions($method, $data['countries'] ?? []);

            return $method;
        });

        return response()->json(['success' => true, 'id' => $method->id], 201);
    }

    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'store_shipping_methods', StoreSetting::class);

        $method = ShippingMethod::findOrFail($id);
        $data = $this->validateData($request);

        DB::transaction(function () use ($method, $data) {
            $method->update($this->attributes($data));
            $this->syncRegions($method, $data['countries'] ?? []);
        });

        return response()->json(['success' => true]);
    }

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'store_shipping_methods', StoreSetting::class);

        ShippingMethod::findOrFail($id)->delete();

        return response()->json(['success' => true]);
    }

    private function validateData(Request $request): array
    {
        $type = $this->liveColumns() ? $request->input('type') : null;
        $live = in_array($type, [ShippingMethod::TYPE_SHIPPO, ShippingMethod::TYPE_PRINTFUL], true);
        $shippo = $type === ShippingMethod::TYPE_SHIPPO;

        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'price' => [$live ? 'nullable' : 'required', 'numeric', 'min:0'],
            'active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'countries' => ['nullable', 'array'],
            'countries.*' => ['string', 'max:100'],
            // Printful is always accepted so an existing Printful method stays
            // editable while Printful is off (checkout then uses its backup rate).
            'type' => ['nullable', Rule::in([ShippingMethod::TYPE_FLAT, ShippingMethod::TYPE_SHIPPO, ShippingMethod::TYPE_PRINTFUL])],
            'live_settings' => ['nullable', 'array'],
            'live_settings.courier_account_id' => $shippo
                ? ['required', 'integer', Rule::in(collect($this->liveAccounts())->pluck('id')->all())]
                : ['nullable'],
            'live_settings.handling_fee' => ['nullable', 'numeric', 'min:0'],
            'live_settings.handling_percent' => ['nullable', 'numeric', 'min:0', 'max:500'],
            'live_settings.free_over' => ['nullable', 'numeric', 'min:0'],
            'live_settings.fallback_price' => ['nullable', 'numeric', 'min:0'],
            'live_settings.exchange_rate' => ['nullable', 'numeric', 'gt:0'],
            'live_settings.max_options' => ['nullable', 'integer', 'min:0', 'max:50'],
            'live_settings.services' => ['nullable', 'array', 'max:30'],
            'live_settings.services.*' => ['string', 'max:60'],
        ]);
    }

    /** Columns to save; type/live settings only once the migration has run. */
    private function attributes(array $data): array
    {
        $attributes = [
            'name' => $data['name'],
            'price' => $data['price'] ?? 0,
            'active' => $data['active'] ?? true,
            'sort_order' => $data['sort_order'] ?? 0,
        ];
        if (! $this->liveColumns()) {
            return $attributes;
        }

        $type = $data['type'] ?? ShippingMethod::TYPE_FLAT;
        $live = in_array($type, [ShippingMethod::TYPE_SHIPPO, ShippingMethod::TYPE_PRINTFUL], true);
        $settings = null;
        if ($live) {
            $s = $data['live_settings'] ?? [];
            $number = fn ($key) => isset($s[$key]) && $s[$key] !== '' ? (float) $s[$key] : null;
            $settings = [
                // Printful quotes with the store's own Printful connection.
                'courier_account_id' => $type === ShippingMethod::TYPE_SHIPPO ? (int) $s['courier_account_id'] : null,
                'handling_fee' => $number('handling_fee'),
                'handling_percent' => $number('handling_percent'),
                'free_over' => $number('free_over'),
                'fallback_price' => $number('fallback_price'),
                'exchange_rate' => $number('exchange_rate'),
                'max_options' => (int) ($s['max_options'] ?? 0),
                'services' => array_values(array_filter(array_map(fn ($k) => trim((string) $k), $s['services'] ?? []))),
            ];
        }

        return $attributes + [
            'type' => $live ? $type : ShippingMethod::TYPE_FLAT,
            'live_settings' => $settings,
        ];
    }

    /** Printful rates: plan feature `printful` and a connected Printful store. */
    private function printfulAvailable(): bool
    {
        try {
            return \App\Services\Printful\OrderService::enabled()
                && (bool) optional(\App\Models\PrintfulSetting::query()->first())->isReady();
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function liveColumns(): bool
    {
        static $has = null;

        return $has ??= Schema::hasColumn('shipping_methods', 'type') && Schema::hasColumn('shipping_methods', 'live_settings');
    }

    /** Active rate courier accounts (Shippo, Shipbubble) a live method can quote with. */
    private function liveAccounts(): array
    {
        try {
            if (! app(TenantLimitsService::class)->hasFeature('couriers')) {
                return [];
            }

            return CourierAccount::query()->where('active', true)->whereIn('courier', LiveShippingRates::COURIERS)
                ->orderByDesc('is_default')->orderBy('name')
                ->get(['id', 'name', 'courier', 'mode'])
                ->map(fn ($a) => [
                    'id' => $a->id, 'name' => $a->name, 'mode' => $a->mode,
                    'courier' => $a->courier, 'courier_name' => \App\Services\Couriers\CourierRegistry::meta($a->courier)['name'] ?? $a->courier,
                ])
                ->all();
        } catch (\Throwable $e) {
            return []; // courier tables not migrated on this tenant
        }
    }

    private function syncRegions(ShippingMethod $method, array $countries): void
    {
        $method->regions()->delete();

        $clean = collect($countries)
            ->map(fn ($c) => trim((string) $c))
            ->filter()
            ->unique()
            ->values();

        foreach ($clean as $country) {
            $method->regions()->create(['country' => $country]);
        }
    }
}
