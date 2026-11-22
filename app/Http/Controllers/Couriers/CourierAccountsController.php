<?php

namespace App\Http\Controllers\Couriers;

use App\Http\Controllers\Controller;
use App\Models\CourierAccount;
use App\Models\Warehouse;
use App\Services\Couriers\CourierRegistry;
use App\Services\Couriers\CourierStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Tenant courier accounts: CRUD, connection test, default account.
 * Credentials are write-only — reads return which fields are set plus a short
 * tail hint, and an update only replaces the fields the request carries.
 * Routes: tenant.feature:couriers; POST couriers/accounts also tenant.limit:max_courier_accounts.
 */
class CourierAccountsController extends Controller
{
    public function catalog(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'courier_settings', CourierAccount::class);

        return response()->json(CourierRegistry::catalog());
    }

    public function index(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'courier_settings', CourierAccount::class);

        $accounts = CourierAccount::query()
            ->with('warehouse:id,name')
            ->withCount(['shipments' => fn ($q) => $q->whereNull('deleted_at')])
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get()
            ->map(fn (CourierAccount $a) => $this->present($a));

        return response()->json([
            'accounts' => $accounts,
            'warehouses' => Warehouse::query()->whereNull('deleted_at')->orderBy('name')->get(['id', 'name']),
            'catalog' => CourierRegistry::catalog(),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'courier_settings', CourierAccount::class);

        $request->validate(['courier' => ['required', 'string', Rule::in(CourierRegistry::codes())]]);
        $meta = CourierRegistry::meta($request->input('courier'));
        $data = $this->validateData($request, $meta, null);

        $account = DB::transaction(function () use ($data, $meta, $request) {
            $account = new CourierAccount();
            $this->fill($account, $data, $meta, $request);
            $account->courier = $meta['code'];
            $account->credentials = $this->credentialInput($request, $meta, []);
            $account->save();

            // The first account becomes the default automatically.
            if ($account->is_default || CourierAccount::query()->count() === 1) {
                $account->makeDefault();
            }

            return $account;
        });

        return response()->json(['success' => true, 'id' => $account->id], 201);
    }

    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'courier_settings', CourierAccount::class);

        $account = CourierAccount::query()->findOrFail($id);
        // The courier itself cannot change: its credentials would no longer fit.
        $meta = CourierRegistry::meta($account->courier);
        if (! $meta) {
            return response()->json(['message' => "Courier '{$account->courier}' is no longer available."], 422);
        }
        $data = $this->validateData($request, $meta, $account);

        DB::transaction(function () use ($account, $data, $meta, $request) {
            $this->fill($account, $data, $meta, $request);
            $account->credentials = $this->credentialInput($request, $meta, $account->credentials ?? []);
            $account->save();

            if ($account->is_default) {
                $account->makeDefault();
            }
        });

        return response()->json(['success' => true]);
    }

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'courier_settings', CourierAccount::class);

        $account = CourierAccount::query()->findOrFail($id);

        $open = $account->shipments()
            ->whereNull('deleted_at')
            ->whereNotNull('courier_status')
            ->whereNotIn('courier_status', CourierStatus::FINAL)
            ->count();
        if ($open > 0) {
            return response()->json([
                'message' => "This account still has {$open} shipment(s) on the way. Deactivate it instead, so their status keeps updating.",
            ], 422);
        }

        DB::transaction(function () use ($account) {
            $wasDefault = $account->is_default;
            $account->forceFill(['is_default' => false])->save();
            $account->delete();

            if ($wasDefault && ($next = CourierAccount::query()->where('active', true)->orderBy('id')->first())) {
                $next->makeDefault();
            }
        });

        return response()->json(['success' => true]);
    }

    public function test(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'courier_settings', CourierAccount::class);

        $account = CourierAccount::query()->findOrFail($id);

        try {
            $result = \App\Services\Couriers\CourierLogger::during($account, 'test', null, fn () => CourierRegistry::make($account)->testConnection());
            $ok = $result->ok;
            $message = $result->message;
        } catch (\Throwable $e) {
            report($e);
            $ok = false;
            $message = $e->getMessage() ?: 'Connection failed.';
        }

        $account->forceFill([
            'last_tested_at' => now(),
            'last_test_ok' => $ok,
            'last_test_message' => mb_substr($message, 0, 500),
        ])->save();

        return response()->json(['ok' => $ok, 'message' => $message], $ok ? 200 : 422);
    }

    public function makeDefault(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'courier_settings', CourierAccount::class);

        $account = CourierAccount::query()->findOrFail($id);
        if (! $account->active) {
            return response()->json(['message' => 'Activate the account before making it the default.'], 422);
        }
        $account->makeDefault();

        return response()->json(['success' => true]);
    }

    // ------------------------------------------------------------------

    private function validateData(Request $request, array $meta, ?CourierAccount $account): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:120'],
            'mode' => ['nullable', Rule::in(['test', 'live'])],
            'tracking_url' => ['nullable', 'string', 'max:500', 'regex:#^https?://#i', function ($attr, $value, $fail) {
                if ($value && ! str_contains($value, '{tracking}')) {
                    $fail('The tracking link must contain {tracking} where the tracking number goes.');
                }
            }],
            'pickup_contact' => ['nullable', 'string', 'max:120'],
            'pickup_phone' => ['nullable', 'string', 'max:40'],
            'pickup_address' => ['nullable', 'string', 'max:500'],
            'pickup_city' => ['nullable', 'string', 'max:120'],
            'warehouse_id' => ['nullable', 'integer', Rule::exists('warehouses', 'id')->whereNull('deleted_at')],
            'default_weight' => ['nullable', 'numeric', 'min:0.001', 'max:1000'],
            'default_service' => ['nullable', 'string', 'max:60'],
            'allow_open_parcel' => ['nullable', 'boolean'],
            'is_default' => ['nullable', 'boolean'],
            'active' => ['nullable', 'boolean'],
            'credentials' => ['nullable', 'array'],
            'settings' => ['nullable', 'array'],
        ];

        foreach ($meta['credentials'] as $field) {
            $key = 'credentials.' . $field['key'];
            // Required credentials must be given on create; on update an
            // omitted secret keeps the stored value.
            $missingStored = ! $account || $account->credential($field['key']) === '';
            $rules[$key] = [($field['required'] && $missingStored) ? 'required' : 'nullable', 'string', 'max:2000'];
        }

        foreach ($meta['settings'] as $field) {
            $key = 'settings.' . $field['key'];
            $rules[$key] = match ($field['type']) {
                'number' => ['nullable', 'numeric'],
                'switch' => ['nullable', 'boolean'],
                'select' => ['nullable', Rule::in(array_column($field['options'], 'value'))],
                default => ['nullable', 'string', 'max:500'],
            };
            if ($field['required']) {
                $rules[$key][0] = 'required';
            }
        }

        $data = $request->validate($rules);

        $active = filter_var($data['active'] ?? true, FILTER_VALIDATE_BOOLEAN);
        if (! $active && filter_var($data['is_default'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            throw ValidationException::withMessages(['is_default' => 'An inactive account cannot be the default.']);
        }

        return $data;
    }

    private function fill(CourierAccount $account, array $data, array $meta, Request $request): void
    {
        $account->name = $data['name'];
        // Couriers without a sandbox always run live.
        $account->mode = $meta['capabilities']['test_mode'] ? ($data['mode'] ?? 'live') : 'live';
        $account->tracking_url = ($data['tracking_url'] ?? null) ?: $meta['tracking_url'];
        $account->pickup_contact = $data['pickup_contact'] ?? null;
        $account->pickup_phone = $data['pickup_phone'] ?? null;
        $account->pickup_address = $data['pickup_address'] ?? null;
        $account->pickup_city = $data['pickup_city'] ?? null;
        $account->warehouse_id = $data['warehouse_id'] ?? null;
        $account->default_weight = $data['default_weight'] ?? 0.5;
        $account->default_service = $data['default_service'] ?? null;
        $account->allow_open_parcel = (bool) ($data['allow_open_parcel'] ?? false);
        $account->active = (bool) ($data['active'] ?? true);
        $account->is_default = $account->active && (bool) ($data['is_default'] ?? false);

        // Only the driver's own setting keys are kept.
        $settings = [];
        foreach ($meta['settings'] as $field) {
            $value = $request->input('settings.' . $field['key']);
            if ($value !== null && $value !== '') {
                $settings[$field['key']] = $field['type'] === 'switch' ? (bool) $value : $value;
            }
        }
        $account->settings = $settings ?: null;
    }

    /** Merge submitted credential fields over the stored ones (blank = keep). */
    private function credentialInput(Request $request, array $meta, array $stored): ?array
    {
        $out = [];
        foreach ($meta['credentials'] as $field) {
            $value = trim((string) $request->input('credentials.' . $field['key'], ''));
            $out[$field['key']] = $value !== '' ? $value : ($stored[$field['key']] ?? '');
        }
        $out = array_filter($out, fn ($v) => $v !== '');

        return $out ?: null;
    }

    private function present(CourierAccount $a): array
    {
        $meta = CourierRegistry::meta($a->courier);
        $credentialState = [];
        foreach ($meta['credentials'] ?? [] as $field) {
            $value = $a->credential($field['key']);
            $credentialState[$field['key']] = [
                'set' => $value !== '',
                // Passwords show only their tail; plain fields (account ids)
                // are not secret and show in full.
                'hint' => $value === '' ? null : ($field['type'] === 'password' ? '…' . mb_substr($value, -4) : $value),
            ];
        }

        return [
            'id' => $a->id,
            'courier' => $a->courier,
            'courier_name' => $meta['name'] ?? $a->courier,
            'available' => $meta !== null,
            'name' => $a->name,
            'mode' => $a->mode,
            'settings' => $a->settings ?: (object) [],
            'credentials' => (object) $credentialState,
            'tracking_url' => $a->tracking_url,
            'pickup_contact' => $a->pickup_contact,
            'pickup_phone' => $a->pickup_phone,
            'pickup_address' => $a->pickup_address,
            'pickup_city' => $a->pickup_city,
            'warehouse_id' => $a->warehouse_id,
            'warehouse_name' => $a->warehouse?->name,
            'default_weight' => (float) $a->default_weight,
            'default_service' => $a->default_service,
            'allow_open_parcel' => (bool) $a->allow_open_parcel,
            'is_default' => (bool) $a->is_default,
            'active' => (bool) $a->active,
            'shipments_count' => (int) ($a->shipments_count ?? 0),
            // Pasted into the courier's dashboard so it can push status changes.
            'webhook_url' => ($meta['capabilities']['webhooks'] ?? false) ? url('/api/couriers/webhook/' . $a->webhook_token) : null,
            'last_tested_at' => $a->last_tested_at?->toDateTimeString(),
            'last_test_ok' => $a->last_test_ok,
            'last_test_message' => $a->last_test_message,
        ];
    }
}
