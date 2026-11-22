<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Client Portal is now a plan feature (`client_portal`, gated by
 * `tenant.feature:client_portal` on the admin API and PortalSettings::enabled()
 * on the portal itself). Fresh installs seed it on Professional and
 * Enterprise; plans that existed before are granted it here so their tenants
 * keep the portal they already use. Super admins can untick it per plan
 * afterwards — this runs once.
 */
return new class extends Migration
{
    private const FEATURE = 'client_portal';

    public function up(): void
    {
        $plans = DB::connection('central')->table('plans')->select('id', 'features')->get();

        foreach ($plans as $plan) {
            $features = json_decode($plan->features ?? '[]', true) ?: [];

            // Features are normally a list (["pos", …]); a map form ({"pos": true}) is kept as a map.
            if (! array_is_list($features)) {
                if (! empty($features[self::FEATURE])) {
                    continue;
                }
                $features[self::FEATURE] = true;
            } elseif (in_array(self::FEATURE, $features, true)) {
                continue;
            } else {
                $features[] = self::FEATURE;
            }

            DB::connection('central')->table('plans')
                ->where('id', $plan->id)
                ->update(['features' => json_encode($features)]);
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
