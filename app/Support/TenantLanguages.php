<?php

namespace App\Support;

/**
 * The languages every tenant database is seeded with (LanguagesSeeder).
 * Locales match the translation files in database/seeders/translations.
 * The Super Admin picks which of these start enabled for new tenants
 * (General Settings → Tenant → Enabled Languages).
 */
class TenantLanguages
{
    public const ALL = [
        ['name' => 'English', 'locale' => 'en', 'flag' => 'gb.svg'],
        ['name' => 'Français', 'locale' => 'fr', 'flag' => 'fr.svg'],
        ['name' => 'العربية', 'locale' => 'ar', 'flag' => 'sa.svg'],
        ['name' => 'Turkish', 'locale' => 'tur', 'flag' => 'tr.svg'],
        ['name' => 'Thaï', 'locale' => 'thai', 'flag' => 'th.svg'],
        ['name' => 'Hindi', 'locale' => 'hn', 'flag' => 'in.svg'],
        ['name' => 'German', 'locale' => 'de', 'flag' => 'de.svg'],
        ['name' => 'Spanish', 'locale' => 'es', 'flag' => 'es.svg'],
        ['name' => 'Italien', 'locale' => 'it', 'flag' => 'it.svg'],
        ['name' => 'Indonesian', 'locale' => 'Ind', 'flag' => 'id.svg'],
        ['name' => 'Simplified Chinese', 'locale' => 'sm_ch', 'flag' => 'cn.svg'],
        ['name' => 'Traditional Chinese', 'locale' => 'tr_ch', 'flag' => 'cn.svg'],
        ['name' => 'Russian', 'locale' => 'ru', 'flag' => 'ru.svg'],
        ['name' => 'Vietnamese', 'locale' => 'vn', 'flag' => 'vn.svg'],
        ['name' => 'Korean', 'locale' => 'kr', 'flag' => 'kr.svg'],
        ['name' => 'Bangla', 'locale' => 'ba', 'flag' => 'bd.svg'],
        ['name' => 'Portuguese', 'locale' => 'br', 'flag' => 'pt.svg'],
        ['name' => 'Danish', 'locale' => 'da', 'flag' => 'dk.svg'],
        ['name' => 'Japanese', 'locale' => 'ja', 'flag' => 'jp.svg'],
        ['name' => 'Polish', 'locale' => 'pl', 'flag' => 'pl.svg'],
        ['name' => 'Swahili', 'locale' => 'sw', 'flag' => 'ke.svg'],
        ['name' => 'Hausa', 'locale' => 'ha', 'flag' => 'ng.svg'],
        ['name' => 'Yoruba', 'locale' => 'yo', 'flag' => 'ng.svg'],
        ['name' => 'Amharic', 'locale' => 'am', 'flag' => 'et.svg'],
    ];

    /**
     * @return string[]
     */
    public static function locales(): array
    {
        return array_column(self::ALL, 'locale');
    }
}
