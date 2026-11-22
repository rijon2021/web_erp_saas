<?php

namespace App\Http\Controllers\Api\Store;

use App\Http\Controllers\Controller;
use App\Models\Collection;
use App\Models\Currency;
use App\Models\Setting;
use App\Models\StoreSetting;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Intervention\Image\ImageManagerStatic as Image;

class SettingsApiController extends Controller
{
    /**
     * Return settings (create sane defaults if missing).
     * Also migrates old home_collections -> homepage_lineup (once), if present.
     */
    public function show(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', StoreSetting::class);
        $s = StoreSetting::first();
        $setting = \App\Models\Setting::first();
        $warehouses = Warehouse::whereNull('deleted_at')->get(['id', 'name']);
        $currencies = Currency::whereNull('deleted_at')->get(['id', 'name', 'symbol']);
        $default_currency_id = $setting?->Currency?->id ?? null;

        if (! $s) {
            $s = StoreSetting::create([
                'enabled' => 1,
                'store_name' => 'StoreX',
                'primary_color' => '#6c5ce7',
                'secondary_color' => '#00c2ff',
                'font_family' => 'Arial, sans-serif',
                'favicon_path' => 'images/store/favicon.ico',
                'hero_image_path' => 'images/store/hero_image.jpg',
                'language' => 'en',
                'currency_code' => $default_currency_id,
                'default_warehouse_id' => $setting?->warehouse_id ?: (Warehouse::first()?->id ?? null),
                'allow_overselling' => true,
                'hide_out_of_stock' => false,
                'hide_prices_for_guests' => false,

                'contact_email' => 'info@storex.test',
                'contact_phone' => '+1234567890',
                'contact_address' => '123 Main St, Sample City',

                'hero_title' => 'Sell online & in-store',
                'hero_subtitle' => 'Beautiful storefront. Synced inventory.',
                'seo_meta_title' => 'Online Store',
                'seo_meta_description' => 'A modern online storefront powered by your POS & Inventory system.',

                'topbar_text_left' => '🚚 Free shipping on orders over $99',
                'topbar_text_right' => '🔥 Summer deals are live!',
                'footer_text' => 'A beautiful demo storefront paired with your POS & Inventory system.',

                'social_links' => json_encode([
                    ['platform' => 'facebook',  'url' => 'https://facebook.com'],
                    ['platform' => 'instagram', 'url' => 'https://instagram.com'],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),

                'homepage_lineup' => json_encode([], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'homepage_layout' => 'default',

            ]);
        }

        // ---- One-time migration: old home_collections -> new homepage_lineup
        // Accept old shape: [{collection_id, title, limit, visible, sort_order}]
        if (empty($s->homepage_lineup) && ! empty($s->home_collections)) {
            $migrated = $this->migrateHomeCollectionsToHomepageLineup($s->home_collections);
            if (! empty($migrated)) {
                $s->homepage_lineup = $migrated;
                $s->save();
            }
        }

        $s->default_currency_id = $default_currency_id;
        // Where the storefront currently answers (custom path or the default),
        // for the settings page's "Current store URL" link.
        $s->store_effective_url = store_base_url();
        $s->app_origin = rtrim((string) url('/'), '/');

        $pendingCustomersCount = \App\Models\EcommerceClient::where('status', 0)
            ->whereNull('deleted_at')
            ->count();

        // Names for the product pickers that only store ids: the "You may also
        // like" picks and the Car Dealership reservation-deposit product.
        $pickedIds = collect(is_array($s->homepage_lineup) ? $s->homepage_lineup : [])
            ->flatMap(fn ($row) => is_array($row) ? (array) ($row['product_ids'] ?? []) : [])
            ->push(\App\Support\CarsTheme::options($s)['reserve_product_id'])
            ->filter(fn ($id) => is_numeric($id) && (int) $id > 0)
            ->map(fn ($id) => (int) $id)->unique()->take(100)->values()->all();
        $curated = $pickedIds ? \App\Models\Product::whereIn('id', $pickedIds)->whereNull('deleted_at')->get(['id', 'name']) : collect();

        // The form edits the stored theme: a premium theme the plan no longer
        // covers must stay selected (and saved) rather than read as its fallback.
        $settingsOut = $s->toArray();
        $settingsOut['theme'] = $s->storedTheme() ?: 'default';

        return response()->json([
            'settings' => $settingsOut,
            'warehouses' => $warehouses,
            'currencies' => $currencies,
            'pending_customers_count' => $pendingCustomersCount,
            'font_options' => \App\Models\Setting::fontOptions(),
            'curated_products' => $curated,
            // Car Dealership tab: show the effective texts (and trust strip) instead of blanks.
            'theme_defaults' => [\App\Support\CarsTheme::KEY => \App\Support\CarsTheme::defaults(), \App\Support\RestaurantTheme::KEY => \App\Support\RestaurantTheme::defaults(), \App\Support\RentalTheme::KEY => \App\Support\RentalTheme::defaults(), \App\Support\BookingTheme::KEY => \App\Support\BookingTheme::defaults(), \App\Support\PremiumElectronicsTheme::KEY => \App\Support\PremiumElectronicsTheme::defaults()],
            // Premium themes: which keys are premium and whether this plan unlocks them.
            'premium_themes' => [
                // Any premium theme usable (plan feature or a bought theme add-on);
                // `allowed` lists which, `purchasable` the ones for sale as add-ons.
                'available' => \App\Support\StoreThemes::allowedPremium() !== [],
                'allowed' => \App\Support\StoreThemes::allowedPremium(),
                'purchasable' => array_values(array_map(
                    fn ($k) => substr($k, strlen(\App\Models\Central\Addon::THEME_PREFIX)),
                    array_filter(
                        \App\Models\Central\Addon::saleKeysForPlan(app(\App\Services\TenantLimitsService::class)->getBasePlan()),
                        fn ($k) => str_starts_with($k, \App\Models\Central\Addon::THEME_PREFIX)
                    )
                )),
                'keys' => array_keys(\App\Support\StoreThemes::PREMIUM),
                'specs_ready' => \App\Support\StoreSpecs::ready(),
                'spec_labels' => \App\Support\StoreSpecs::ready() ? \App\Support\StoreSpecs::filterLabels([]) : [],
            ],
            // Booking theme: needs Booking Management (plan + module + its migrations).
            'booking_theme' => [
                'available' => \App\Services\Booking\BookingWebsiteService::available(),
                'types' => \App\Services\Booking\BookingWebsiteService::available() ? \App\Support\BookingTypes::enabled() : [],
            ],
            // Rental theme: available on this plan, and which business types it will show.
            'rental_theme' => [
                'available' => \App\Services\Rental\RentalWebsiteService::available(),
                'types' => \App\Services\Rental\RentalWebsiteService::available() ? \App\Support\RentalTypes::enabled() : [],
            ],
        ]);
    }

    /**
     * Update settings + handle image uploads.
     */
    public function update(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', StoreSetting::class);

        // --- Normalize boolean to 0/1 so in:0,1 passes ---
        if ($request->has('enabled')) {
            $request->merge([
                'enabled' => (int) filter_var($request->input('enabled'), FILTER_VALIDATE_BOOLEAN),
            ]);
        }
        $boolFields = array_merge(
            ['registration_enabled', 'require_invite_code', 'require_admin_approval', 'guest_checkout_enabled', 'allow_cancellations', 'auto_approve_reviews', 'cookie_consent_enabled', 'store_use_root_domain', 'store_all_warehouses'],
            // Every storefront payment switch (credit_card, cod, mobile money
            // and each online gateway) is stored as a 0/1 column.
            array_values(StoreSetting::PAYMENT_METHOD_COLUMNS),
        );
        foreach ($boolFields as $regField) {
            if ($request->has($regField)) {
                $request->merge([
                    $regField => (int) filter_var($request->input($regField), FILTER_VALIDATE_BOOLEAN),
                ]);
            }
        }
        if ($request->has('allow_overselling')) {
            $request->merge([
                'allow_overselling' => (int) filter_var($request->input('allow_overselling'), FILTER_VALIDATE_BOOLEAN),
            ]);
        }
        if ($request->has('hide_out_of_stock')) {
            $request->merge([
                'hide_out_of_stock' => (int) filter_var($request->input('hide_out_of_stock'), FILTER_VALIDATE_BOOLEAN),
            ]);
        }
        if ($request->has('hide_prices_for_guests')) {
            $request->merge([
                'hide_prices_for_guests' => (int) filter_var($request->input('hide_prices_for_guests'), FILTER_VALIDATE_BOOLEAN),
            ]);
        }
        if ($request->has('show_stock')) {
            $request->merge([
                'show_stock' => (int) filter_var($request->input('show_stock'), FILTER_VALIDATE_BOOLEAN),
            ]);
        }

        // --- Validation ---
        // Theme slide/banner/tile uploads: theme_image__<theme>__<list>__<i>.
        $themeImageRules = [];
        foreach (array_keys($request->allFiles()) as $fileKey) {
            if (preg_match('/^theme_image__(?:[a-z_]+__)?(slides|banners|tiles)__(\d{1,2})$/', $fileKey)) {
                $themeImageRules[$fileKey] = 'nullable|file|mimes:jpg,jpeg,png,webp|max:8192';
            }
        }
        $request->validate($themeImageRules);

        $data = $request->validate([
            'enabled' => 'nullable|in:0,1',
            'registration_enabled' => 'nullable|in:0,1',
            'require_invite_code' => 'nullable|in:0,1',
            'require_admin_approval' => 'nullable|in:0,1',
            'guest_checkout_enabled' => 'nullable|in:0,1',
            'allow_cancellations' => 'nullable|in:0,1',
            'return_window_days' => 'nullable|integer|min:0|max:365',
            'auto_approve_reviews' => 'nullable|in:0,1',
            'cookie_consent_enabled' => 'nullable|in:0,1',
            'allow_overselling' => 'nullable|in:0,1',
            'hide_out_of_stock' => 'nullable|in:0,1',
            'hide_prices_for_guests' => 'nullable|in:0,1',
            'show_stock' => 'nullable|in:0,1',

            // Storefront checkout: per-method on/off switches (the gateway
            // credentials themselves live on payment_settings).
            'payment_stripe_enabled' => 'nullable|in:0,1',
            'payment_cod_enabled' => 'nullable|in:0,1',
            'payment_mobile_money_enabled' => 'nullable|in:0,1',
            'payment_razorpay_enabled' => 'nullable|in:0,1',
            'payment_bkash_enabled' => 'nullable|in:0,1',
            'payment_sslcommerz_enabled' => 'nullable|in:0,1',
            'payment_paymob_enabled' => 'nullable|in:0,1',
            'payment_myfatoorah_enabled' => 'nullable|in:0,1',
            'payment_moyasar_enabled' => 'nullable|in:0,1',
            'payment_xpay_enabled' => 'nullable|in:0,1',
            'payment_payfast_enabled' => 'nullable|in:0,1',
            'payment_ligdicash_enabled' => 'nullable|in:0,1',
            'payment_dodo_enabled' => 'nullable|in:0,1',
            'payment_waafipay_enabled' => 'nullable|in:0,1',
            'payment_paypal_enabled' => 'nullable|in:0,1',
            'payment_paystack_enabled' => 'nullable|in:0,1',
            'payment_flutterwave_enabled' => 'nullable|in:0,1',

            // Manual / offline methods: the switch plus the account details
            // the shopper pays into (rendered on checkout and the order page).
            'payment_gcash_enabled' => 'nullable|in:0,1',
            'gcash_account_name' => 'nullable|string|max:150',
            'gcash_account_number' => 'nullable|string|max:60',
            'gcash_instructions' => 'nullable|string|max:1000',
            'payment_bank_transfer_enabled' => 'nullable|in:0,1',
            'bank_name' => 'nullable|string|max:150',
            'bank_account_name' => 'nullable|string|max:150',
            'bank_account_number' => 'nullable|string|max:60',
            'bank_branch' => 'nullable|string|max:150',
            'bank_instructions' => 'nullable|string|max:1000',
            'payment_cash_on_pickup_enabled' => 'nullable|in:0,1',
            'pickup_instructions' => 'nullable|string|max:1000',

            // Storefront base path (custom URL); empty = default online_store.
            // store_use_root_domain serves the store from the tenant host root
            // instead, which makes the base path irrelevant.
            'store_url_path' => 'nullable|string|max:100',
            'store_use_root_domain' => 'nullable|in:0,1',

            'store_name' => 'nullable|string|max:190',
            'theme' => 'nullable|string|in:default,real_estate,'.implode(',', \App\Support\StoreThemes::SKINS),
            'theme_options' => 'nullable',
            'primary_color' => 'nullable|string|max:20',
            'secondary_color' => 'nullable|string|max:20',
            'font_family' => 'nullable|string|max:100',

            'language' => 'nullable|string|max:10',
            // Not `required`: this endpoint takes partial payloads (the payment
            // gateway page flips one switch), and a tenant with no default
            // currency set would otherwise fail every such save with a 422.
            'default_currency_id' => 'sometimes|nullable|integer',
            'default_warehouse_id' => 'sometimes|nullable|integer',
            // Warehouses the store sells from; "all" is stored as NULL.
            'warehouse_ids' => 'nullable',
            'store_all_warehouses' => 'nullable|in:0,1',

            'contact_email' => 'nullable|email|max:190',
            'contact_phone' => 'nullable|string|max:50',
            'contact_address' => 'nullable|string|max:255',

            'hero_title' => 'nullable|string|max:255',
            'hero_subtitle' => 'nullable|string|max:1000',

            'seo_meta_title' => 'nullable|string|max:255',
            'seo_meta_description' => 'nullable|string|max:1000',

            'topbar_text_left' => 'nullable|string|max:190',
            'topbar_text_right' => 'nullable|string|max:190',
            'footer_text' => 'nullable|string|max:255',

            'social_links' => 'nullable',
            'homepage_lineup' => 'nullable',
            'homepage_layout' => 'nullable|string|in:default',
            'home_collections' => 'nullable',

            // Tracking & Analytics: comma-separated ID lists, never script.
            'google_tag_id' => ['bail', 'nullable', 'string', 'max:255', function ($attr, $value, $fail) {
                $bad = \App\Support\StoreTracking::invalidGoogle($value);
                if ($bad) {
                    $fail('Invalid Google tag ID: '.implode(', ', $bad).'. Use G-…, AW-…, GT-… or GTM-… IDs.');
                } elseif (count(\App\Support\StoreTracking::tokens($value)) > \App\Support\StoreTracking::MAX_IDS) {
                    $fail('Enter at most '.\App\Support\StoreTracking::MAX_IDS.' Google tag IDs.');
                }
            }],
            'meta_pixel_id' => ['bail', 'nullable', 'string', 'max:255', function ($attr, $value, $fail) {
                $bad = \App\Support\StoreTracking::invalidMeta($value);
                if ($bad) {
                    $fail('Invalid Meta Pixel ID: '.implode(', ', $bad).'. A Pixel ID is a number.');
                } elseif (count(\App\Support\StoreTracking::tokens($value)) > \App\Support\StoreTracking::MAX_IDS) {
                    $fail('Enter at most '.\App\Support\StoreTracking::MAX_IDS.' Meta Pixel IDs.');
                }
            }],

            // Laravel 12: image rule excludes SVG by default; using file + mimes
            'logo' => 'nullable|file|mimes:jpg,jpeg,png,webp|max:4096',
            'favicon' => 'nullable|file|mimes:jpg,jpeg,png,webp,ico|mimetypes:image/png,image/jpeg,image/webp,image/x-icon,image/vnd.microsoft.icon|max:2048',
            'hero_image' => 'nullable|file|mimes:jpg,jpeg,png,webp|max:8192',
            'gcash_qr' => 'nullable|file|mimes:jpg,jpeg,png,webp|max:4096',
        ]);

        // --- Store URL path: normalize + reject unsafe or reserved paths. The
        // default 'online_store' is stored as NULL so untouched installs keep
        // behaving exactly as before this setting existed. ---
        $urlConfigTouched = array_key_exists('store_url_path', $data)
            || array_key_exists('store_use_root_domain', $data);
        if ($urlConfigTouched) {
            $raw = trim((string) $data['store_url_path'], "/ \t\n\r");
            if ($raw === '' || strtolower($raw) === 'online_store') {
                $data['store_url_path'] = null;
            } else {
                $clean = store_sanitize_path($raw);
                if ($clean === null) {
                    return response()->json([
                        'message' => 'The store URL path is invalid or conflicts with a system route.',
                        'errors' => ['store_url_path' => ['Use letters, numbers, dashes or underscores (e.g. "shop"), avoiding reserved system paths.']],
                    ], 422);
                }
                $data['store_url_path'] = $clean;
            }
        }

        // --- The Rental theme lists Rental module items: it needs the module
        // (plan + toggle) and its website columns. Only checked when switching
        // to it, so a store already on it can still save other settings. ---
        if (($data['theme'] ?? null) === \App\Support\RentalTheme::KEY
            && StoreSetting::query()->value('theme') !== \App\Support\RentalTheme::KEY
            && ! \App\Services\Rental\RentalWebsiteService::available()) {
            return response()->json([
                'message' => 'The Rental theme needs the Rental Management module on your plan (and the latest update installed).',
                'errors' => ['theme' => ['The Rental theme needs the Rental Management module on your plan.']],
            ], 422);
        }

        // --- Same for the Booking theme: it lists Booking Management services. ---
        if (($data['theme'] ?? null) === \App\Support\BookingTheme::KEY
            && StoreSetting::query()->value('theme') !== \App\Support\BookingTheme::KEY
            && ! \App\Services\Booking\BookingWebsiteService::available()) {
            return response()->json([
                'message' => 'The Booking theme needs Booking Management on your plan (and the latest update installed).',
                'errors' => ['theme' => ['The Booking theme needs Booking Management on your plan.']],
            ], 422);
        }

        // --- Premium themes need the premium_themes plan feature. Only checked
        // when switching to one, so a store already on it (kept after a
        // downgrade, rendered as its free fallback) can still save. ---
        if (\App\Support\StoreThemes::isPremium($data['theme'] ?? null)
            && StoreSetting::query()->toBase()->value('theme') !== ($data['theme'] ?? null) // raw: the accessor would report the fallback
            && ! \App\Support\StoreThemes::allowed($data['theme'] ?? null)) {
            return response()->json([
                'message' => 'This theme is a premium theme. Buy it under Billing → Add-ons, or upgrade to a plan that includes Premium Store Themes.',
                'errors' => ['theme' => ['This theme is not included in your plan.']],
            ], 422);
        }

        // --- A blank id means "not being changed", never "clear it" ---
        foreach (['default_currency_id', 'default_warehouse_id'] as $idField) {
            if (array_key_exists($idField, $data) && ($data[$idField] === null || $data[$idField] === '')) {
                unset($data[$idField]);
            }
        }

        // --- Online-store warehouses: "all" is stored as NULL, otherwise the
        // validated id list; default_warehouse_id follows the first selected
        // warehouse for code paths that book under a single one. ---
        if ($request->has('warehouse_ids') || $request->has('store_all_warehouses')) {
            $ids = $data['warehouse_ids'] ?? [];
            if (is_string($ids)) {
                $ids = json_decode($ids, true) ?: [];
            }
            $ids = array_values(array_unique(array_filter(array_map('intval', (array) $ids))));
            $ids = empty($ids) ? [] : Warehouse::whereIn('id', $ids)
                ->whereNull('deleted_at')
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            if (! empty($data['store_all_warehouses']) || empty($ids)) {
                $data['warehouse_ids'] = null;
            } else {
                $data['warehouse_ids'] = $ids;
                $data['default_warehouse_id'] = $ids[0];
            }
        }
        unset($data['store_all_warehouses']);
        // Tenants that have not run the warehouse_ids migration yet.
        if (array_key_exists('warehouse_ids', $data)
            && ! \Illuminate\Support\Facades\Schema::hasColumn('store_settings', 'warehouse_ids')) {
            unset($data['warehouse_ids']);
        }

        // --- Decode JSON fields ---
        foreach (['social_links', 'homepage_lineup', 'home_collections'] as $key) {
            if (array_key_exists($key, $data) && is_string($data[$key])) {
                $decoded = json_decode($data[$key], true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $data[$key] = $decoded;
                }
            }
        }

        // --- Normalize unified lineup ---
        if (array_key_exists('homepage_lineup', $data)) {
            $data['homepage_lineup'] = $this->normalizeHomepageLineup($data['homepage_lineup']);
        }

        // --- Migrate legacy collections if needed ---
        if (! empty($data['home_collections']) && empty($data['homepage_lineup'])) {
            $data['homepage_lineup'] = $this->migrateHomeCollectionsToHomepageLineup($data['home_collections']);
        }
        unset($data['home_collections']); // don't persist legacy field

        // --- Tracking & Analytics IDs: canonical "ID,ID" form; skipped until
        // the tracking migration has run on this tenant. ---
        foreach (['google_tag_id' => true, 'meta_pixel_id' => false] as $trkField => $upper) {
            if (! array_key_exists($trkField, $data)) {
                continue;
            }
            if (\Illuminate\Support\Facades\Schema::hasColumn('store_settings', $trkField)) {
                $data[$trkField] = \App\Support\StoreTracking::normalize($data[$trkField], $upper);
            } else {
                unset($data[$trkField]);
            }
        }

        // --- Ensure model exists ---
        $s = StoreSetting::first() ?: new StoreSetting;

        // --- Auto-confirm paid orders: each gateway's switch (Payment Gateway
        // page) saves on its own as a {gateway: 0|1} map, merged into the
        // stored list. Anything else is ignored — notably the Store Settings
        // page, which posts every loaded setting back as multipart, turning the
        // stored list into a plain string. Unknown keys (COD, manual methods…)
        // are ignored too. ---
        $acInput = $request->input('auto_confirm_methods');
        if (is_array($acInput) && $acInput && ! array_is_list($acInput)
            && \Illuminate\Support\Facades\Schema::hasColumn('store_settings', 'auto_confirm_methods')) {
            $on = array_values(array_intersect(
                is_array($s->auto_confirm_methods) ? $s->auto_confirm_methods : [],
                StoreSetting::AUTO_CONFIRM_METHODS
            ));
            foreach ($acInput as $method => $flag) {
                if (! in_array($method, StoreSetting::AUTO_CONFIRM_METHODS, true) || ! is_scalar($flag)) {
                    continue;
                }
                $on = array_values(array_diff($on, [$method]));
                if (filter_var($flag, FILTER_VALIDATE_BOOLEAN)) {
                    $on[] = $method;
                }
            }
            $data['auto_confirm_methods'] = $on;
        }

        // --- Ensure storage directory exists ---
        $targetDir = upload_public_path('store');
        $storeRel  = upload_path('store');
        if (! File::exists($targetDir)) {
            File::makeDirectory($targetDir, 0755, true);
        }

        // ============================
        // IMAGE UPLOAD HANDLERS
        // ============================

        // --- LOGO (200x200 max) ---
        if ($request->hasFile('logo')) {
            if ($s->logo_path && File::exists(public_path($s->logo_path))) {
                File::delete(public_path($s->logo_path));
            }

            $ext = strtolower($request->file('logo')->guessExtension() ?: 'png');
            $filename = (string) Str::uuid().'.'.$ext;

            Image::make($request->file('logo')->getRealPath())
                ->resize(200, 200, function ($c) {
                    $c->aspectRatio();
                    $c->upsize();
                })
                ->encode($ext, 85)
                ->save($targetDir.'/'.$filename);

            $data['logo_path'] = $storeRel.'/'.$filename;
        }

        // --- GCASH QR (600x600 max) ---
        if ($request->hasFile('gcash_qr')) {
            if ($s->gcash_qr_path && File::exists(public_path($s->gcash_qr_path))) {
                File::delete(public_path($s->gcash_qr_path));
            }

            $ext = strtolower($request->file('gcash_qr')->guessExtension() ?: 'png');
            $filename = (string) Str::uuid().'.'.$ext;

            Image::make($request->file('gcash_qr')->getRealPath())
                ->resize(600, 600, function ($c) {
                    $c->aspectRatio();
                    $c->upsize();
                })
                ->encode($ext, 88)
                ->save($targetDir.'/'.$filename);

            $data['gcash_qr_path'] = $storeRel.'/'.$filename;
        }

        // --- FAVICON ---
        if ($request->hasFile('favicon')) {
            if ($s->favicon_path && File::exists(public_path($s->favicon_path))) {
                File::delete(public_path($s->favicon_path));
            }

            $file = $request->file('favicon');
            $mime = $file->getMimeType() ?: '';
            $ext = strtolower($file->getClientOriginalExtension() ?: $file->guessExtension() ?: '');

            if ($ext === 'ico' || in_array($mime, ['image/x-icon', 'image/vnd.microsoft.icon'], true)) {
                $filename = (string) Str::uuid().'.ico';
                $file->move($targetDir, $filename);
            } else {
                $filename = (string) Str::uuid().'.png';
                Image::make($file->getRealPath())
                    ->fit(64, 64)
                    ->encode('png')
                    ->save($targetDir.'/'.$filename);
            }

            $data['favicon_path'] = $storeRel.'/'.$filename;
        }

        // --- HERO IMAGE (1600x800 max) ---
        if ($request->hasFile('hero_image')) {
            if ($s->hero_image_path && File::exists(public_path($s->hero_image_path))) {
                File::delete(public_path($s->hero_image_path));
            }

            $ext = strtolower($request->file('hero_image')->guessExtension() ?: 'jpg');
            $filename = (string) Str::uuid().'.'.$ext;

            Image::make($request->file('hero_image')->getRealPath())
                ->resize(1600, 800, function ($c) {
                    $c->aspectRatio();
                    $c->upsize();
                })
                ->encode($ext, 82)
                ->save($targetDir.'/'.$filename);

            $data['hero_image_path'] = $storeRel.'/'.$filename;
        }

        // --- THEME OPTIONS (skin themes: electronics, toys, …) ---
        // theme_options = {<theme>: {...}}; each theme's support class sanitizes
        // its own map. Uploaded images arrive as theme_image__<theme>__<list>__<i>
        // (legacy electronics form: theme_image__<list>__<i>).
        if ($request->has('theme_options')) {
            $existing = is_array($s->theme_options) ? $s->theme_options : [];
            $incoming = $request->input('theme_options');
            if (is_string($incoming)) {
                $incoming = json_decode($incoming, true);
            }
            if (! is_array($incoming)) {
                $incoming = [];
            }
            // Legacy payload without a theme key = electronics options.
            if (! array_intersect(array_keys($incoming), \App\Support\StoreThemes::SKINS)) {
                $incoming = [\App\Support\ElectronicsTheme::KEY => $incoming];
            }

            $themeDir = upload_public_path('store/theme');
            $themeRel = upload_path('store/theme');
            foreach (\App\Support\StoreThemes::SKINS as $themeKey) {
                if (! array_key_exists($themeKey, $incoming)) {
                    continue;
                }
                $raw = $incoming[$themeKey];
                if (is_string($raw)) {
                    $raw = json_decode($raw, true);
                }
                if (! is_array($raw)) {
                    $raw = [];
                }

                // Attach uploads to the raw rows before sanitizing: upload keys
                // carry the form's row index, and sanitize() drops text-less
                // rows — which would discard an image-only slide/tile and
                // shift every later upload onto the wrong row.
                foreach ($request->allFiles() as $fileKey => $file) {
                    if (! $file || ! preg_match('/^theme_image__(?:([a-z_]+)__)?(slides|banners|tiles)__(\d{1,2})$/', $fileKey, $m)) {
                        continue;
                    }
                    $fileTheme = $m[1] !== '' ? $m[1] : \App\Support\ElectronicsTheme::KEY;
                    if ($fileTheme !== $themeKey) {
                        continue;
                    }
                    [$list, $idx] = [$m[2], (int) $m[3]];
                    if (! isset($raw[$list][$idx]) || ! is_array($raw[$list][$idx])) {
                        continue;
                    }
                    if (! File::exists($themeDir)) {
                        File::makeDirectory($themeDir, 0755, true);
                    }
                    $ext = strtolower($file->guessExtension() ?: 'jpg');
                    $filename = (string) Str::uuid().'.'.$ext;
                    // Premium full-image slides fill the screen width: keep
                    // them sharp (2560 px); everything else stays 1600 px.
                    [$maxW, $maxH] = $themeKey === \App\Support\PremiumElectronicsTheme::KEY && $list === 'slides'
                        ? [2560, 1440]
                        : [1600, 1000];
                    Image::make($file->getRealPath())
                        ->resize($maxW, $maxH, function ($c) {
                            $c->aspectRatio();
                            $c->upsize();
                        })
                        ->encode($ext, 82)
                        ->save($themeDir.'/'.$filename);

                    $old = trim((string) (is_scalar($raw[$list][$idx]['image'] ?? null) ? $raw[$list][$idx]['image'] : ''));
                    if ($old !== '' && Str::startsWith($old, [$themeRel.'/', 'images/store/theme/']) && File::exists(public_path($old))) {
                        File::delete(public_path($old));
                    }
                    $raw[$list][$idx]['image'] = $themeRel.'/'.$filename;
                }

                $existing[$themeKey] = \App\Support\StoreThemes::sanitize($themeKey, $raw);
            }
            $data['theme_options'] = $existing;
        } else {
            unset($data['theme_options']);
        }

        // ============================
        // CURRENCY HANDLING
        // ============================

        if (! empty($data['default_currency_id'])) {
            $currency = Currency::find($data['default_currency_id']);
            if ($currency) {
                $data['currency_code'] = $currency->symbol;

                $setting = \App\Models\Setting::first();
                if ($setting) {
                    $setting->currency_id = $currency->id;
                    $setting->save();
                }
            }
        }

        // ============================
        // SAVE CHANGES
        // ============================

        // Tenants that have not run the cookie-consent migration yet would
        // otherwise fail the whole save on the unknown column.
        if (array_key_exists('cookie_consent_enabled', $data)
            && ! \Illuminate\Support\Facades\Schema::hasColumn('store_settings', 'cookie_consent_enabled')) {
            unset($data['cookie_consent_enabled']);
        }

        $s->fill($data)->save();

        // The storefront routes are registered before tenancy is initialized,
        // so the base path is mirrored onto the central tenant record where
        // store_path() can read it for any request on this host.
        if ($urlConfigTouched) {
            try {
                if (function_exists('tenant') && tenant()) {
                    tenant()->update([
                        'store_url_path' => $s->store_url_path,
                        'store_use_root_domain' => (bool) $s->store_use_root_domain,
                    ]);
                }
            } catch (\Throwable $e) {
                report($e);
            }
            store_url_config_clear();
        }

        $fresh = $s->fresh();
        $fresh->store_effective_url = store_base_url();
        $fresh->app_origin = rtrim((string) url('/'), '/');
        $freshOut = $fresh->toArray();
        $freshOut['theme'] = $fresh->storedTheme() ?: 'default';

        return response()->json($freshOut);
    }

    /**
     * Return the storefront menus + link targets (pages, collections) for the editor.
     */
    public function menus(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'store_menus', StoreSetting::class);

        $s = StoreSetting::first();
        $menus = $this->normalizeMenus(is_array($s?->menus) ? $s->menus : []);

        $pages = \App\Models\StorePage::orderBy('title')->get(['id', 'title', 'slug', 'published']);
        $collections = Collection::orderBy('title')->get(['id', 'title', 'slug'])
            ->map(fn ($c) => ['title' => $c->title ?: $c->slug, 'slug' => $c->slug])
            ->filter(fn ($c) => ! empty($c['slug']))
            ->values();

        return response()->json([
            'menus' => $menus,
            'pages' => $pages,
            'collections' => $collections,
            'locales' => store_locales(),
        ]);
    }

    /**
     * Persist the storefront menus.
     */
    public function updateMenus(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'store_menus', StoreSetting::class);

        $data = $request->validate(['menus' => 'nullable']);
        $menus = $this->normalizeMenus($data['menus'] ?? []);

        $s = StoreSetting::first() ?: new StoreSetting;
        $s->menus = $menus;
        $s->save();

        return response()->json(['menus' => $menus]);
    }

    /**
     * Normalize storefront menus to { header, footer_shop, footer_support } of
     * [{label, type, value}] items.
     */
    private function normalizeMenus($val): array
    {
        if (is_string($val)) {
            $val = json_decode($val, true) ?: [];
        }
        if (! is_array($val)) {
            $val = [];
        }

        $allowedTypes = ['home', 'shop', 'flash_sales', 'contact', 'wishlist', 'compare', 'page', 'collection', 'url'];
        $out = [];

        foreach (['header', 'footer_shop', 'footer_support'] as $loc) {
            $items = $val[$loc] ?? [];
            if (! is_array($items)) {
                $items = [];
            }
            $clean = [];
            foreach ($items as $it) {
                if (! is_array($it)) {
                    continue;
                }
                $label = trim((string) ($it['label'] ?? ''));
                if ($label === '') {
                    continue;
                }
                $type = (string) ($it['type'] ?? 'url');
                if (! in_array($type, $allowedTypes, true)) {
                    $type = 'url';
                }
                // Per-locale label; blanks fall back to `label` on the storefront.
                $labels = $it['label_translations'] ?? [];
                if (is_string($labels)) {
                    $labels = json_decode($labels, true);
                }
                $cleanLabels = [];
                foreach ((array) $labels as $locale => $text) {
                    if (isset(store_locales()[$locale]) && trim((string) $text) !== '') {
                        $cleanLabels[$locale] = mb_substr(trim((string) $text), 0, 80);
                    }
                }

                $clean[] = [
                    'label' => mb_substr($label, 0, 80),
                    'label_translations' => (object) $cleanLabels,
                    'type' => $type,
                    'value' => mb_substr(trim((string) ($it['value'] ?? '')), 0, 255),
                ];
                if (count($clean) >= 30) {
                    break;
                }
            }
            $out[$loc] = $clean;
        }

        return $out;
    }

    /**
     * Return only calendar-related settings (for System Settings → Calendar tab).
     */
    public function showCalendar(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Setting::class);
        $s = Setting::first();
        if (! $s) {
            return response()->json([
                'google_calendar_connected' => false,
                'google_calendar_connect_url' => route('google_calendar.connect'),
                'google_calendar_disconnect_url' => route('google_calendar.disconnect'),
                'google_calendar_client_id' => null,
                'google_calendar_client_secret_set' => false,
                'google_calendar_redirect_uri' => null,
                'google_calendar_calendar_id' => null,
            ]);
        }
        return response()->json([
            'google_calendar_connected' => ! empty($s->google_calendar_refresh_token),
            'google_calendar_connect_url' => route('google_calendar.connect'),
            'google_calendar_disconnect_url' => route('google_calendar.disconnect'),
            'google_calendar_client_id' => $s->google_calendar_client_id,
            'google_calendar_client_secret_set' => ! empty($s->google_calendar_client_secret),
            'google_calendar_redirect_uri' => $s->google_calendar_redirect_uri,
            'google_calendar_calendar_id' => $s->google_calendar_calendar_id,
        ]);
    }

    /**
     * Update only calendar-related settings (credentials, calendar ID).
     */
    public function updateCalendar(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Setting::class);
        $data = $request->validate([
            'google_calendar_client_id' => 'nullable|string|max:255',
            'google_calendar_client_secret' => 'nullable|string|max:65535',
            'google_calendar_redirect_uri' => 'nullable|string|max:512',
            'google_calendar_calendar_id' => 'nullable|string|max:255',
        ]);
        $s = Setting::first();
        if (! $s) {
            return response()->json(['error' => 'Settings record not found.'], 404);
        }
        // Do not overwrite client_secret with empty value (user may leave it blank to keep existing)
        if (array_key_exists('google_calendar_client_secret', $data) && $data['google_calendar_client_secret'] === '') {
            unset($data['google_calendar_client_secret']);
        }
        $s->fill($data)->save();
        return response()->json(['success' => true]);
    }

    /**
     * Normalize homepage_lineup to an array of items like:
     *  - {"type":"hero"}
     *  - {"type":"newsletter"}
     *  - {"type":"best_sellers","title":"","limit":8}
     *  - {"type":"you_may_like","title":"","limit":8,"product_ids":[1,2]}
     *  - {"type":"collection","slug":"winter-sale","limit":8,"layout":"grid","title_override":""}
     */
    /** Homepage block product count: 1-24, matching the settings UI's stepper. */
    private static function clampBlockLimit($raw): int
    {
        $limit = (int) $raw;

        return $limit >= 1 && $limit <= 24 ? $limit : 8;
    }

    private function normalizeHomepageLineup($val): array
    {
        if (! $val || ! is_array($val)) {
            return [];
        }

        $out = [];
        $seenCollections = [];
        $hasHero = false;
        $hasNewsletter = false;
        $hasPromoBanner = false;
        $hasCategories = false;
        $hasBestSellers = false;
        $hasYouMayLike = false;

        foreach ($val as $row) {
            if (! is_array($row) || empty($row['type'])) {
                continue;
            }
            $type = strtolower((string) $row['type']);

            if ($type === 'hero') {
                if ($hasHero) {
                    continue;
                }
                $out[] = ['type' => 'hero'];
                $hasHero = true;

                continue;
            }

            if ($type === 'newsletter') {
                if ($hasNewsletter) {
                    continue;
                }
                $out[] = ['type' => 'newsletter'];
                $hasNewsletter = true;

                continue;
            }

            // Curated product grids. Both were accepted by the settings UI but
            // dropped here, so toggling them on never survived a reload.
            if ($type === 'best_sellers') {
                if ($hasBestSellers) {
                    continue;
                }
                $hasBestSellers = true;
                $out[] = [
                    'type' => 'best_sellers',
                    'title' => isset($row['title']) ? (string) $row['title'] : '',
                    'limit' => self::clampBlockLimit($row['limit'] ?? null),
                ];

                continue;
            }

            if ($type === 'you_may_like') {
                if ($hasYouMayLike) {
                    continue;
                }
                $hasYouMayLike = true;
                $productIds = is_array($row['product_ids'] ?? null) ? $row['product_ids'] : [];
                $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds))));
                $out[] = [
                    'type' => 'you_may_like',
                    'title' => isset($row['title']) ? (string) $row['title'] : '',
                    'limit' => self::clampBlockLimit($row['limit'] ?? null),
                    'product_ids' => $productIds,
                ];

                continue;
            }

            if ($type === 'promo_banner') {
                if ($hasPromoBanner) {
                    continue;
                }
                $hasPromoBanner = true;
                $out[] = [
                    'type' => 'promo_banner',
                    'title' => isset($row['title']) ? (string) $row['title'] : '',
                    'subtitle' => isset($row['subtitle']) ? (string) $row['subtitle'] : '',
                    'button_text' => isset($row['button_text']) ? (string) $row['button_text'] : __('Shop Now'),
                    'link' => isset($row['link']) ? (string) $row['link'] : '',
                ];

                continue;
            }

            if ($type === 'categories') {
                if ($hasCategories) {
                    continue;
                }
                $hasCategories = true;
                $out[] = [
                    'type' => 'categories',
                    'title' => isset($row['title']) ? (string) $row['title'] : '',
                ];

                continue;
            }

            if ($type === 'collection') {
                // Accept slug (preferred). Back-compat: accept "handle"
                $slug = isset($row['slug']) ? (string) $row['slug'] : (isset($row['handle']) ? (string) $row['handle'] : '');
                $slug = trim($slug);
                if ($slug === '') {
                    continue;
                }

                if (isset($seenCollections[$slug])) {
                    continue;
                } // de-dupe by slug
                $seenCollections[$slug] = true;

                $limit = isset($row['limit']) ? (int) $row['limit'] : 8;
                if ($limit <= 0) {
                    $limit = 8;
                }

                $layout = isset($row['layout']) && in_array($row['layout'], ['grid', 'carousel'], true)
                    ? $row['layout'] : 'grid';

                $titleOverride = isset($row['title_override']) ? (string) $row['title_override'] : '';

                $out[] = [
                    'type' => 'collection',
                    'slug' => $slug,
                    'limit' => $limit,
                    'layout' => $layout,
                    'title_override' => $titleOverride,
                ];
            }
        }

        return $out;
    }

    /**
     * Migrate legacy home_collections (by collection_id) to homepage_lineup (by slug).
     * Accepts rows like: [{collection_id, title, limit, visible, sort_order}]
     * Requires a Collection model with id/slug; missing slugs are skipped.
     */
    private function migrateHomeCollectionsToHomepageLineup($val): array
    {
        if (! $val || ! is_array($val)) {
            return [];
        }

        // Filter only visible rows and sort by sort_order
        $rows = array_values(array_filter($val, function ($r) {
            if (! is_array($r)) {
                return false;
            }
            if (empty($r['collection_id'])) {
                return false;
            }
            $visible = array_key_exists('visible', $r)
                ? filter_var($r['visible'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
                : true;

            return $visible !== false; // treat null as true
        }));

        usort($rows, function ($a, $b) {
            $sa = (int) ($a['sort_order'] ?? 9999);
            $sb = (int) ($b['sort_order'] ?? 9999);

            return $sa <=> $sb;
        });

        $ids = array_unique(array_map(fn ($r) => (int) $r['collection_id'], $rows));
        if (empty($ids)) {
            return [];
        }

        // Map IDs -> slugs (skip when model/table not available)
        $idToSlug = [];
        if (class_exists(Collection::class)) {
            $idToSlug = Collection::query()
                ->whereIn('id', $ids)
                ->pluck('slug', 'id')
                ->toArray();
        }

        $out = [];
        foreach ($rows as $r) {
            $cid = (int) $r['collection_id'];
            $slug = (string) ($idToSlug[$cid] ?? '');
            if ($slug === '') {
                continue;
            }

            $limit = (int) ($r['limit'] ?? 8);
            if ($limit <= 0) {
                $limit = 8;
            }

            $out[] = [
                'type' => 'collection',
                'slug' => $slug,
                'limit' => $limit,
                'layout' => 'grid',
                'title_override' => '', // legacy title is not forced on home; keep clean
            ];
        }

        // You can prepend hero/newsletter here if you want defaults; we leave as-is.
        return $out;
    }
}
