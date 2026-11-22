<?php

namespace App\Support;

/**
 * Every language the super-admin panel, landing page, billing screens and PDF
 * invoices can be installed in. A language is "installed" once it has a row in
 * central_languages; everything else here shows under Languages > Available.
 *
 * Translations ship with the code: resources/lang/{locale} for the original ten,
 * resources/lang-packs/{locale} for the rest. Installing copies them into
 * central_translations, where the super admin edits them.
 */
class CentralLanguageCatalog
{
    /** Installed on a fresh setup, in this order. */
    public const DEFAULT_INSTALLED = ['en', 'fr', 'ar', 'es', 'hi', 'bn', 'tr', 'de', 'pt', 'ur'];

    public const ALL = [
        'en' => ['name' => 'English',    'native' => 'English',          'flag' => 'gb.svg', 'rtl' => false],
        'fr' => ['name' => 'French',     'native' => 'Français',         'flag' => 'fr.svg', 'rtl' => false],
        'ar' => ['name' => 'Arabic',     'native' => 'العربية',          'flag' => 'sa.svg', 'rtl' => true],
        'es' => ['name' => 'Spanish',    'native' => 'Español',          'flag' => 'es.svg', 'rtl' => false],
        'hi' => ['name' => 'Hindi',      'native' => 'हिन्दी',             'flag' => 'in.svg', 'rtl' => false],
        'bn' => ['name' => 'Bengali',    'native' => 'বাংলা',             'flag' => 'bd.svg', 'rtl' => false],
        'tr' => ['name' => 'Turkish',    'native' => 'Türkçe',           'flag' => 'tr.svg', 'rtl' => false],
        'de' => ['name' => 'German',     'native' => 'Deutsch',          'flag' => 'de.svg', 'rtl' => false],
        'pt' => ['name' => 'Portuguese', 'native' => 'Português',        'flag' => 'pt.svg', 'rtl' => false],
        'ur' => ['name' => 'Urdu',       'native' => 'اردو',             'flag' => 'pk.svg', 'rtl' => true],
        'it' => ['name' => 'Italian',    'native' => 'Italiano',         'flag' => 'it.svg', 'rtl' => false],
        'nl' => ['name' => 'Dutch',      'native' => 'Nederlands',       'flag' => 'nl.svg', 'rtl' => false],
        'ru' => ['name' => 'Russian',    'native' => 'Русский',          'flag' => 'ru.svg', 'rtl' => false],
        'uk' => ['name' => 'Ukrainian',  'native' => 'Українська',       'flag' => 'ua.svg', 'rtl' => false],
        'pl' => ['name' => 'Polish',     'native' => 'Polski',           'flag' => 'pl.svg', 'rtl' => false],
        'ro' => ['name' => 'Romanian',   'native' => 'Română',           'flag' => 'ro.svg', 'rtl' => false],
        'cs' => ['name' => 'Czech',      'native' => 'Čeština',          'flag' => 'cz.svg', 'rtl' => false],
        'el' => ['name' => 'Greek',      'native' => 'Ελληνικά',         'flag' => 'gr.svg', 'rtl' => false],
        'sv' => ['name' => 'Swedish',    'native' => 'Svenska',          'flag' => 'se.svg', 'rtl' => false],
        'da' => ['name' => 'Danish',     'native' => 'Dansk',            'flag' => 'dk.svg', 'rtl' => false],
        'fi' => ['name' => 'Finnish',    'native' => 'Suomi',            'flag' => 'fi.svg', 'rtl' => false],
        'hu' => ['name' => 'Hungarian',  'native' => 'Magyar',           'flag' => 'hu.svg', 'rtl' => false],
        'zh' => ['name' => 'Chinese (Simplified)', 'native' => '简体中文', 'flag' => 'cn.svg', 'rtl' => false],
        'ja' => ['name' => 'Japanese',   'native' => '日本語',            'flag' => 'jp.svg', 'rtl' => false],
        'ko' => ['name' => 'Korean',     'native' => '한국어',            'flag' => 'kr.svg', 'rtl' => false],
        'id' => ['name' => 'Indonesian', 'native' => 'Bahasa Indonesia', 'flag' => 'id.svg', 'rtl' => false],
        'ms' => ['name' => 'Malay',      'native' => 'Bahasa Melayu',    'flag' => 'my.svg', 'rtl' => false],
        'vi' => ['name' => 'Vietnamese', 'native' => 'Tiếng Việt',       'flag' => 'vn.svg', 'rtl' => false],
        'th' => ['name' => 'Thai',       'native' => 'ไทย',              'flag' => 'th.svg', 'rtl' => false],
        'tl' => ['name' => 'Filipino',   'native' => 'Filipino',         'flag' => 'ph.svg', 'rtl' => false],
        'fa' => ['name' => 'Persian',    'native' => 'فارسی',            'flag' => 'ir.svg', 'rtl' => true],
        'sw' => ['name' => 'Swahili',    'native' => 'Kiswahili',        'flag' => 'ke.svg', 'rtl' => false],
        'ha' => ['name' => 'Hausa',      'native' => 'Hausa',            'flag' => 'ng.svg', 'rtl' => false],
        'am' => ['name' => 'Amharic',    'native' => 'አማርኛ',             'flag' => 'et.svg', 'rtl' => false],
        'ta' => ['name' => 'Tamil',      'native' => 'தமிழ்',             'flag' => 'in.svg', 'rtl' => false],
        'pa' => ['name' => 'Punjabi',    'native' => 'ਪੰਜਾਬੀ',            'flag' => 'in.svg', 'rtl' => false],
    ];

    public static function get(string $locale): ?array
    {
        return self::ALL[$locale] ?? null;
    }

    public static function has(string $locale): bool
    {
        return isset(self::ALL[$locale]);
    }

    /** Locales whose RTL default is on (used before the DB is reachable). */
    public static function rtlLocales(): array
    {
        return array_keys(array_filter(self::ALL, fn ($l) => $l['rtl']));
    }
}
