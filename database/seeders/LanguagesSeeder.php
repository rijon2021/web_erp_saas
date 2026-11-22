<?php

namespace Database\Seeders;

use App\Models\Central\GeneralSetting;
use App\Support\TenantLanguages;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class LanguagesSeeder extends Seeder
{
    public function run()
    {
        // Which languages start active is set by the Super Admin
        // (General Settings → Tenant → Enabled Languages); all by default.
        // is_active is only written on insert, so re-running the seeder
        // never overrides what a tenant changed later.
        $enabled = $this->enabledLocales();

        DB::table('languages')->insertOrIgnore(array_map(fn (array $language) => $language + [
            'is_active' => in_array($language['locale'], $enabled, true),
            'is_default' => false,
        ], TenantLanguages::ALL));

        // The default language (Super Admin tenant default) is always
        // activated, otherwise it would be missing from the switcher.
        $defaultLocale = DB::table('settings')->value('default_language') ?? 'en';
        DB::table('languages')->update(['is_default' => false]);
        DB::table('languages')->where('locale', $defaultLocale)->update(['is_default' => true, 'is_active' => true]);
    }

    /**
     * @return string[]
     */
    protected function enabledLocales(): array
    {
        try {
            $selected = GeneralSetting::instance()->getTenantDefaults()['enabled_languages'];
        } catch (\Throwable) {
            $selected = null;
        }

        return is_array($selected) ? $selected : TenantLanguages::locales();
    }
}
