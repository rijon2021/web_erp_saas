<?php

namespace App\Http\Controllers\Central\Super;

use App\Http\Controllers\Controller;
use App\Models\Central\Addon;
use App\Models\Central\Plan;
use App\Models\Central\TenantAddon;
use App\Services\Billing\AddonService;
use App\Tenant;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Super admin → Add-ons: the catalogue (which modules / premium themes are for
 * sale, at what price, to which plans), every tenant's add-ons, and manual
 * grant / revoke from the tenant page.
 */
class AddonController extends Controller
{
    public function index(Request $request): View
    {
        $ready = Addon::tablesReady();
        $rows = $ready ? Addon::all()->keyBy('key') : collect();

        $catalog = [];
        foreach (Addon::catalog() as $key => $meta) {
            $row = $rows->get($key);
            $catalog[] = $meta + [
                'row' => $row,
                'sort' => $row?->sort_order ?? 9999,
            ];
        }
        // Items for sale first (in their order), then the rest alphabetically.
        usort($catalog, fn ($a, $b) => [($a['row']?->is_active ? 0 : 1), $a['sort'], $a['label']]
            <=> [($b['row']?->is_active ? 0 : 1), $b['sort'], $b['label']]);

        $status = (string) $request->query('status', '');
        $sales = $ready
            ? TenantAddon::with('tenant')
                ->when($status !== '', fn ($q) => $q->where('status', $status))
                ->latest('id')->paginate(25)->withQueryString()
            : null;

        return view('central.super.addons.index', [
            'ready'    => $ready,
            'extras'   => Addon::extrasReady(),
            'catalog'  => $catalog,
            'plans'    => Plan::orderBy('price')->get(['id', 'name', 'price']),
            'sales'    => $sales,
            'status'   => $status,
            'statuses' => [
                TenantAddon::STATUS_ACTIVE, TenantAddon::STATUS_PENDING, TenantAddon::STATUS_EXPIRED,
                TenantAddon::STATUS_CANCELLED, TenantAddon::STATUS_REFUNDED, TenantAddon::STATUS_REVOKED,
            ],
        ]);
    }

    /** Save the whole catalogue (one form, one row per add-on key). */
    public function update(Request $request): RedirectResponse
    {
        if (! Addon::tablesReady()) {
            return back()->with('error', __('super.addons.migration_needed'));
        }

        // The marker is the form's last field: PHP silently drops inputs past
        // max_input_vars from the end, which would save half the catalogue
        // (every row after the cut-off switched off). Refuse instead.
        if (! $request->has('_catalog_complete')) {
            return back()->with('error', __('super.addons.flash_form_truncated', ['limit' => (int) ini_get('max_input_vars')]));
        }

        $catalog = Addon::catalog();
        $price = ['nullable', 'numeric', 'min:0', 'max:99999999'];

        $data = $request->validate([
            'addons'                    => ['array'],
            'addons.*.is_active'        => ['nullable', 'boolean'],
            'addons.*.name'             => ['nullable', 'string', 'max:120'],
            'addons.*.description'      => ['nullable', 'string', 'max:1000'],
            'addons.*.monthly_price'    => $price,
            'addons.*.yearly_price'     => $price,
            'addons.*.lifetime_price'   => $price,
            'addons.*.plan_ids'         => ['nullable', 'array'],
            'addons.*.plan_ids.*'       => ['integer'],
            'addons.*.sort_order'       => ['nullable', 'integer', 'min:0', 'max:9999'],
            'addons.*.trial_days'       => ['nullable', 'integer', 'min:0', 'max:365'],
            'addons.*.pack_size'        => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'addons.*.requires'         => ['nullable', 'string', 'max:500'],
        ]);

        $extras = Addon::extrasReady();
        $errors = [];
        $badRequires = [];
        foreach ($data['addons'] ?? [] as $key => $input) {
            if (! isset($catalog[$key])) {
                continue;
            }

            $values = [
                'type'           => $catalog[$key]['type'],
                'is_active'      => (bool) ($input['is_active'] ?? false),
                'name'           => trim((string) ($input['name'] ?? '')) ?: null,
                'description'    => trim((string) ($input['description'] ?? '')) ?: null,
                'monthly_price'  => $this->price($input['monthly_price'] ?? null),
                'yearly_price'   => $this->price($input['yearly_price'] ?? null),
                'lifetime_price' => $this->price($input['lifetime_price'] ?? null),
                // Nothing ticked = every plan.
                'plan_ids'       => ! empty($input['plan_ids']) ? array_values(array_map('intval', $input['plan_ids'])) : null,
                'sort_order'     => (int) ($input['sort_order'] ?? 0),
            ];

            if ($extras) {
                $values['trial_days'] = ! empty($input['trial_days']) && $catalog[$key]['type'] !== Addon::TYPE_LIMIT ? (int) $input['trial_days'] : null;
                $values['pack_size'] = $catalog[$key]['type'] === Addon::TYPE_LIMIT ? max(1, (int) ($input['pack_size'] ?? 1)) : null;
                // "bookings, crm" → known catalogue keys only, never itself.
                $wanted = array_filter(array_map('trim', preg_split('/[\s,;]+/', (string) ($input['requires'] ?? '')) ?: []));
                $known = array_values(array_filter($wanted, fn ($k) => isset($catalog[$k]) && $k !== $key));
                if (count($known) !== count($wanted)) {
                    $badRequires[] = $catalog[$key]['label'];
                }
                $values['requires'] = $known ?: null;
            }

            if ($values['is_active'] && $values['monthly_price'] === null && $values['yearly_price'] === null && $values['lifetime_price'] === null) {
                $errors[] = $catalog[$key]['label'];
                $values['is_active'] = false;
            }

            $existing = Addon::where('key', $key)->first();
            // Untouched, never-enabled rows are not worth storing.
            if (! $existing && ! $values['is_active'] && $values['monthly_price'] === null
                && $values['yearly_price'] === null && $values['lifetime_price'] === null
                && $values['name'] === null && $values['description'] === null
                && empty($values['trial_days']) && empty($values['requires'])) {
                continue;
            }

            Addon::updateOrCreate(['key' => $key], $values);
        }

        Addon::forgetCache();

        $redirect = redirect()->route('super.addons.index');

        $problems = [];
        if ($errors) {
            $problems[] = __('super.addons.flash_price_needed', ['names' => implode(', ', $errors)]);
        }
        if ($badRequires) {
            $problems[] = __('super.addons.flash_bad_requires', ['names' => implode(', ', $badRequires)]);
        }

        return $problems
            ? $redirect->with('error', implode(' ', $problems))
            : $redirect->with('success', __('super.addons.flash_saved'));
    }

    /** Tenant page: give an add-on for free, until a date or with no end. */
    public function grant(Request $request, Tenant $tenant, AddonService $service): RedirectResponse
    {
        if (! Addon::tablesReady()) {
            return back()->with('error', __('super.addons.migration_needed'));
        }

        $data = $request->validate([
            'addon_key' => ['required', 'string', Rule::in(array_keys(Addon::catalog()))],
            'until'     => ['nullable', 'date', 'after_or_equal:today'],
            'note'      => ['nullable', 'string', 'max:500'],
            'quantity'  => ['nullable', 'integer', 'min:1', 'max:' . Addon::MAX_PACKS],
        ]);

        try {
            $service->grant($tenant, $data['addon_key'], ! empty($data['until']) ? Carbon::parse($data['until']) : null, $data['note'] ?? null, (int) ($data['quantity'] ?? 1));
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', __('super.addons.flash_granted'));
    }

    public function revoke(TenantAddon $tenantAddon, AddonService $service): RedirectResponse
    {
        $service->revoke($tenantAddon);

        return back()->with('success', __('super.addons.flash_revoked'));
    }

    private function price(mixed $value): ?float
    {
        return $value === null || $value === '' ? null : round((float) $value, 2);
    }
}
