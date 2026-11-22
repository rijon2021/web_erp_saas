<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Courier Integration is a plan feature (`couriers`, gated by
 * `tenant.feature:couriers`). Fresh installs seed it on Professional and
 * Enterprise; plans that existed before the module are granted it here so
 * their tenants see the Couriers menu after the update. The
 * `max_courier_accounts` limit is added as unlimited (-1) where missing, so
 * it appears on the plan form ready to cap. Super admins can untick the
 * feature or set the limit per plan afterwards — this runs once.
 */
return new class extends Migration
{
    private const FEATURE = 'couriers';
    private const LIMIT = 'max_courier_accounts';

    public function up(): void
    {
        $plans = DB::connection('central')->table('plans')->select('id', 'features', 'limits')->get();

        foreach ($plans as $plan) {
            $features = json_decode($plan->features ?? '[]', true) ?: [];
            $limits = json_decode($plan->limits ?? '[]', true) ?: [];
            $changes = [];

            // Features are normally a list (["pos", …]); a map form ({"pos": true}) is kept as a map.
            if (! array_is_list($features)) {
                if (empty($features[self::FEATURE])) {
                    $features[self::FEATURE] = true;
                    $changes['features'] = json_encode($features);
                }
            } elseif (! in_array(self::FEATURE, $features, true)) {
                $features[] = self::FEATURE;
                $changes['features'] = json_encode(array_values($features));
            }
            if (! array_key_exists(self::LIMIT, $limits)) {
                $limits[self::LIMIT] = -1;
                $changes['limits'] = json_encode($limits);
            }

            if ($changes) {
                DB::connection('central')->table('plans')->where('id', $plan->id)->update($changes);
            }
        }
    }

    public function down(): void
    {
        $plans = DB::connection('central')->table('plans')->select('id', 'features')->get();

        foreach ($plans as $plan) {
            $features = json_decode($plan->features ?? '[]', true) ?: [];
            if (! array_is_list($features)) {
                if (array_key_exists(self::FEATURE, $features)) {
                    unset($features[self::FEATURE]);
                    DB::connection('central')->table('plans')->where('id', $plan->id)->update(['features' => json_encode($features)]);
                }

                continue;
            }
            $stripped = array_values(array_diff($features, [self::FEATURE]));

            if ($stripped !== $features) {
                DB::connection('central')->table('plans')
                    ->where('id', $plan->id)
                    ->update(['features' => json_encode($stripped)]);
            }
        }
    }
};
