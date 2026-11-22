<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Online Store per-submenu permissions (store_*) replaced the broader
 * permissions that used to guard those pages. When an updater first creates
 * one of them, every role that held the old guard gets it, so existing staff
 * roles keep the access they had. Only freshly created slugs are handled —
 * re-running an update never re-grants what an admin later removed.
 */
class StorePermissionGrants
{
    private const STORE = ['Store_settings_view', 'Orders_view', 'Collections_view', 'Banners_view', 'Subscribers_view', 'Messages_view', 'payment_gateway'];

    /** new slug => roles holding ANY of these inherit it */
    public static function inherited(): array
    {
        $settings = ['Store_settings_view'];

        return [
            'store_visit' => self::STORE,
            'store_invite_codes' => $settings,
            'store_pending_customers' => $settings,
            'store_customers_without_login' => self::STORE,
            'store_customers_with_login' => self::STORE,
            'store_shipping_methods' => $settings,
            'store_tax_rates' => $settings,
            'store_coupons' => $settings,
            'store_flash_sales' => $settings,
            'store_reviews' => $settings,
            'store_popups' => $settings,
            'store_quote_requests' => ['Orders_view', 'Store_settings_view'],
            'store_returns' => ['Orders_view', 'Store_settings_view'],
            'store_pages' => $settings,
            'store_menus' => $settings,
            'store_vehicle_leads' => ['Orders_view', 'Store_settings_view'],
            // Courier report (phase 7): whoever could see courier shipments sees the report.
            'courier_reports' => ['courier_shipments'],
            // Asset Management lifecycle: whoever managed the asset register keeps the whole module.
            'asset_assignments' => ['assets'],
            'asset_maintenance' => ['assets'],
            'asset_transfers' => ['assets'],
            'asset_reports' => ['assets'],
        ];
    }

    /**
     * @param  array<string,int>  $created  slug => permission id, for slugs created in this run
     */
    public static function grant(array $created): void
    {
        $map = array_intersect_key(self::inherited(), $created);
        if (! $map) {
            return;
        }

        // The customer lists also required Customers_view before.
        $customerRoles = self::rolesHolding(['Customers_view']);

        foreach ($map as $slug => $parents) {
            $roleIds = self::rolesHolding($parents);
            if (str_starts_with($slug, 'store_customers_')) {
                $roleIds = array_intersect($roleIds, $customerRoles);
            }

            $existing = DB::table('permission_role')->where('permission_id', $created[$slug])->pluck('role_id')->all();
            $rows = array_map(
                fn ($roleId) => ['permission_id' => $created[$slug], 'role_id' => $roleId],
                array_values(array_diff($roleIds, $existing))
            );
            if ($rows) {
                DB::table('permission_role')->insert($rows);
            }
        }
    }

    private static function rolesHolding(array $names): array
    {
        return DB::table('permission_role')
            ->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
            ->join('roles', 'roles.id', '=', 'permission_role.role_id')
            ->whereIn('permissions.name', $names)
            ->whereNull('roles.deleted_at')
            ->distinct()
            ->pluck('permission_role.role_id')
            ->all();
    }
}
