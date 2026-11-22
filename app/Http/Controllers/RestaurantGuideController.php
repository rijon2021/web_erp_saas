<?php

namespace App\Http\Controllers;

use App\Models\RestaurantSetting;
use App\Policies\RestaurantPolicy;
use App\Support\RestaurantModule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Restaurant › Guide — GET restaurant/guide: the live set-up checklist
 * (what is done, what is missing) for the guide page. Open to anyone with
 * at least one restaurant permission; read-only.
 */
class RestaurantGuideController extends BaseController
{
    public function show(Request $request)
    {
        $user = $request->user('api');
        $can = collect(RestaurantPolicy::PERMISSIONS)->mapWithKeys(fn ($p) => [$p => (bool) $user?->can($p, RestaurantSetting::class)]);
        if (! $can->contains(true)) {
            abort(403);
        }

        // Each phase's migration, by the table / column it adds.
        $migrations = [
            'settings' => Schema::hasTable('rst_settings'),
            'floor' => Schema::hasTable('rst_tables'),
            'menu' => Schema::hasTable('rst_dish_details'),
            'orders' => Schema::hasTable('rst_orders'),
            'billing' => Schema::hasTable('rst_order_payments'),
            'reservations' => Schema::hasTable('rst_reservations'),
            'online' => Schema::hasTable('rst_tables') && Schema::hasColumn('rst_tables', 'qr_token'),
        ];
        $out = [
            'module_on' => RestaurantModule::enabled(),
            'migrations' => $migrations,
            'can' => $can,
        ];
        if (! $migrations['settings']) {
            return response()->json($out);
        }

        $s = RestaurantSetting::current();
        $count = fn (string $table, ?callable $where = null) => Schema::hasTable($table) ? (int) ($where ? $where(DB::table($table)) : DB::table($table))->count() : 0;
        $softActive = fn ($q) => $q->whereNull('deleted_at')->where('is_active', 1);
        $roleWith = fn (string $perm) => Schema::hasTable('permission_role')
            ? (int) DB::table('permission_role as pr')->join('permissions as p', 'p.id', '=', 'pr.permission_id')->where('p.name', $perm)->distinct()->count('pr.role_id')
            : 0;

        return response()->json($out + [
            'settings' => [
                'order_types' => $s->orderTypes(),
                'default_warehouse' => (bool) $s->default_warehouse_id,
                'service_charge_percent' => (float) $s->service_charge_percent,
                'tips_enabled' => (bool) $s->tips_enabled,
                'kitchen_online_orders' => $s->kitchen_online_orders,
                'qr_ordering_enabled' => (bool) ($s->qr_ordering_enabled ?? false),
                'qr_auto_send' => (bool) ($s->qr_auto_send ?? false),
                'online_booking_enabled' => (bool) ($s->online_booking_enabled ?? false),
            ],
            'counts' => [
                'areas' => $migrations['floor'] ? $count('rst_areas', $softActive) : 0,
                'tables' => $migrations['floor'] ? $count('rst_tables', $softActive) : 0,
                'tables_with_qr' => $migrations['online'] ? $count('rst_tables', fn ($q) => $softActive($q)->whereNotNull('qr_token')) : 0,
                'stations' => $migrations['menu'] ? $count('rst_stations', fn ($q) => $q->where('is_active', 1)) : 0,
                'dishes' => $migrations['menu'] ? $count('rst_dish_details') : 0,
                'addon_groups' => $migrations['menu'] ? $count('rst_addon_groups', fn ($q) => $q->where('is_active', 1)) : 0,
                'payment_methods' => $count('payment_methods', fn ($q) => $q->whereNull('deleted_at')->where('is_active', 1)->where('id', '!=', 1)),
                'cash_methods' => Schema::hasColumn('payment_methods', 'is_cash') ? $count('payment_methods', fn ($q) => $q->whereNull('deleted_at')->where('is_active', 1)->where('is_cash', 1)) : 0,
                'orders' => $migrations['orders'] ? $count('rst_orders') : 0,
                'bills' => $migrations['billing'] ? $count('rst_order_payments') : 0,
                'reservations' => $migrations['reservations'] ? $count('rst_reservations') : 0,
            ],
            'roles' => [
                'waiters' => $roleWith('rst_orders_take'),
                'kitchen' => $roleWith('rst_kitchen'),
                'cashiers' => $roleWith('rst_billing'),
                'hosts' => $roleWith('rst_reservations'),
            ],
            'default_client' => (bool) DB::table('settings')->whereNull('deleted_at')->value('client_id'),
            'store' => [
                'theme' => (string) (DB::table('store_settings')->value('theme') ?? ''),
                'enabled' => (bool) (DB::table('store_settings')->value('enabled') ?? false),
            ],
        ]);
    }
}
