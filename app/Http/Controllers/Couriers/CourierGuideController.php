<?php

namespace App\Http\Controllers\Couriers;

use App\Http\Controllers\Controller;
use App\Models\CourierAccount;
use App\Models\CourierLog;
use App\Models\CourierSetting;
use App\Models\Shipment;
use App\Services\Couriers\CourierGuide;
use App\Services\Couriers\CourierRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * Couriers → Guide: a live setup checklist for this workspace plus the
 * setup notes of every courier. Open to anyone who can see any courier page;
 * webhook URLs (secrets) only for courier_settings.
 */
class CourierGuideController extends Controller
{
    public function show(Request $request)
    {
        $user = $request->user('api');
        $canSettings = $user->can('courier_settings', CourierAccount::class);
        if (! $canSettings && ! $user->can('courier_shipments', CourierAccount::class) && ! $user->can('courier_book', CourierAccount::class)) {
            abort(403);
        }

        $accounts = CourierAccount::query()->orderByDesc('is_default')->orderBy('name')->get();
        $settings = CourierSetting::current();
        $phase7 = CourierSetting::hasPhase7Columns();

        $couriers = collect(CourierRegistry::codes())->map(function ($code) {
            $m = CourierRegistry::meta($code);
            $field = fn ($f) => ['label' => $f['label'], 'help' => $f['help'], 'required' => (bool) $f['required']];

            return [
                'code' => $code,
                'name' => $m['name'],
                'region' => $m['region'],
                'description' => $m['description'],
                'website' => $m['website'],
                'capabilities' => array_keys(array_filter($m['capabilities'])),
                'credentials' => array_map($field, $m['credentials']),
                'settings' => array_values(array_map($field, array_filter($m['settings'],
                    fn ($f) => ! str_starts_with($f['key'], 'parcel_') && ! in_array($f['key'], ['sender_email', 'sender_company'], true)))),
                'webhook' => CourierGuide::webhook($code, $m['capabilities']),
                'tips' => CourierGuide::tips($code),
            ];
        })->values();

        return response()->json([
            'checklist' => [
                'accounts' => $accounts->count(),
                'connected' => $accounts->where('last_test_ok', true)->count(),
                'cod_account' => (bool) $settings->recordsCodPayments(),
                'notifications' => $phase7 && $settings->notifies(),
                'tracking_page' => $settings->trackingPageEnabled(),
                'shipments' => Schema::hasColumn('shipments', 'courier_account_id')
                    ? Shipment::query()->whereNull('deleted_at')->whereNotNull('courier_account_id')->count() : 0,
                'errors_24h' => Schema::hasTable('courier_logs')
                    ? CourierLog::query()->where('level', 'error')->where('created_at', '>=', now()->subDay())->count() : 0,
                'phase7_ready' => $phase7,
            ],
            'accounts' => $accounts->map(function (CourierAccount $a) use ($canSettings) {
                $m = CourierRegistry::meta($a->courier);

                return [
                    'id' => $a->id,
                    'name' => $a->name,
                    'courier' => $a->courier,
                    'courier_name' => $m['name'] ?? $a->courier,
                    'active' => (bool) $a->active,
                    'last_test_ok' => $a->last_test_ok,
                    'webhook' => CourierGuide::webhook($a->courier, $m['capabilities'] ?? []),
                    'webhook_url' => $canSettings && ($m['capabilities']['webhooks'] ?? false) ? url('/api/couriers/webhook/' . $a->webhook_token) : null,
                ];
            })->values(),
            'couriers' => $couriers,
            'can_settings' => $canSettings,
        ]);
    }
}
