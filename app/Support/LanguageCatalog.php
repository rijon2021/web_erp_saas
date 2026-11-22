<?php

namespace App\Support;

/**
 * Every language a database-backed surface can install (ported from stocky).
 * In this SaaS it serves the client portal: ScopedLanguageInstaller /
 * PortalLanguageInstaller read the packs through groupPackPath() and the
 * portal shell reads rtlLocales() / isoTag() / find()['flag']. The tenant
 * back-office languages keep their own list (App\Support\TenantLanguages).
 *
 * The four DEFAULT_LOCALES are installed for every tenant by the migration
 * that creates portal_languages — every other pack is installed on demand
 * from Client Portal → Portal Languages.
 */
class LanguageCatalog
{
    public const DEFAULT_LOCALES = ['en', 'fr', 'ar', 'es'];

    /** Locales that can never be uninstalled (plus whichever is the default). */
    public const PROTECTED_LOCALES = ['en'];

    public static function all(): array
    {
        return [
            // ── Default packs (installed automatically) ──
            'en' => ['name' => 'English', 'flag' => 'gb.svg'],
            'fr' => ['name' => 'Français', 'flag' => 'fr.svg'],
            'ar' => ['name' => 'العربية', 'flag' => 'sa.svg', 'rtl' => true],
            'es' => ['name' => 'Spanish', 'flag' => 'es.svg'],

            // ── Optional packs (installed from the Languages page) ──
            'tur' => ['name' => 'Turkish', 'flag' => 'tr.svg'],
            'thai' => ['name' => 'Thaï', 'flag' => 'th.svg'],
            'hn' => ['name' => 'Hindi', 'flag' => 'in.svg'],
            'de' => ['name' => 'German', 'flag' => 'de.svg'],
            'it' => ['name' => 'Italian', 'flag' => 'it.svg'],
            'Ind' => ['name' => 'Indonesian', 'flag' => 'id.svg'],
            'sm_ch' => ['name' => 'Simplified Chinese', 'flag' => 'cn.svg'],
            'tr_ch' => ['name' => 'Traditional Chinese', 'flag' => 'cn.svg'],
            'ru' => ['name' => 'Russian', 'flag' => 'ru.svg'],
            'vn' => ['name' => 'Vietnamese', 'flag' => 'vn.svg'],
            'kr' => ['name' => 'Korean', 'flag' => 'kr.svg'],
            'ba' => ['name' => 'Bangla', 'flag' => 'bd.svg'],
            'br' => ['name' => 'Portuguese', 'flag' => 'pt.svg'],
            'da' => ['name' => 'Danish', 'flag' => 'dk.svg'],
            'ja' => ['name' => 'Japanese', 'flag' => 'jp.svg'],
            'pl' => ['name' => 'Polish', 'flag' => 'pl.svg'],
            'sw' => ['name' => 'Swahili', 'flag' => 'ke.svg', 'file' => 'ke.php'],
            'ha' => ['name' => 'Hausa', 'flag' => 'ng.svg'],
            'yo' => ['name' => 'Yoruba', 'flag' => 'ng.svg'],
            'am' => ['name' => 'Amharic', 'flag' => 'et.svg', 'file' => 'et.php'],
            'ur' => ['name' => 'اردو', 'flag' => 'pk.svg', 'rtl' => true],
            'fa' => ['name' => 'فارسی', 'flag' => 'ir.svg', 'rtl' => true],
            'nl' => ['name' => 'Nederlands', 'flag' => 'nl.svg'],
            'el' => ['name' => 'Ελληνικά', 'flag' => 'gr.svg'],
            'uk' => ['name' => 'Українська', 'flag' => 'ua.svg'],
            'ms' => ['name' => 'Bahasa Melayu', 'flag' => 'my.svg'],
            'fil' => ['name' => 'Filipino', 'flag' => 'ph.svg'],
            'ro' => ['name' => 'Română', 'flag' => 'ro.svg'],
            'cs' => ['name' => 'Čeština', 'flag' => 'cz.svg'],
            'sv' => ['name' => 'Svenska', 'flag' => 'se.svg'],
            'hu' => ['name' => 'Magyar', 'flag' => 'hu.svg'],
            'fi' => ['name' => 'Suomi', 'flag' => 'fi.svg'],
            'no' => ['name' => 'Norsk', 'flag' => 'no.svg'],
            'ta' => ['name' => 'தமிழ்', 'flag' => 'in.svg'],
            'pa' => ['name' => 'ਪੰਜਾਬੀ', 'flag' => 'in.svg'],
            'km' => ['name' => 'ខ្មែរ', 'flag' => 'kh.svg'],
            'my' => ['name' => 'မြန်မာ', 'flag' => 'mm.svg'],
            'ne' => ['name' => 'नेपाली', 'flag' => 'np.svg'],
            'uz' => ['name' => 'Oʻzbekcha', 'flag' => 'uz.svg'],
            'kk' => ['name' => 'Қазақша', 'flag' => 'kz.svg'],
            'az' => ['name' => 'Azərbaycanca', 'flag' => 'az.svg'],
            'so' => ['name' => 'Soomaali', 'flag' => 'so.svg'],
            'ig' => ['name' => 'Igbo', 'flag' => 'ng.svg'],
        ];
    }

    public static function find(string $locale): ?array
    {
        $entry = static::all()[$locale] ?? null;
        if (! $entry) {
            return null;
        }

        return static::normalize($locale, $entry);
    }

    /** Catalog entries whose pack file is actually present on disk. */
    public static function available(): array
    {
        $out = [];
        foreach (static::all() as $locale => $entry) {
            $entry = static::normalize($locale, $entry);
            if (is_file($entry['path'])) {
                $out[$locale] = $entry;
            }
        }

        return $out;
    }

    /**
     * Pack file for a locale: the catalog's mapping, or "<locale>.php" for a
     * locale outside the catalog (a pack dropped in by hand). Null if none.
     */
    public static function packPath(string $locale): ?string
    {
        $entry = static::find($locale);
        $path = $entry ? $entry['path'] : database_path('seeders/translations/'.$locale.'.php');

        return is_file($path) ? $path : null;
    }

    /**
     * Storefront pack for a locale. The default languages keep their
     * hand-maintained resources/lang/<locale>/messages.php (developers add
     * storefront keys there); every other catalog language ships a generated
     * pack under database/seeders/store_translations/<locale>.php.
     */
    public static function storePackPath(string $locale): ?string
    {
        return static::groupPackPath('messages', 'store_translations', $locale);
    }

    /** Client portal pack: resources/lang/<locale>/portal.php or the generated pack. */
    public static function portalPackPath(string $locale): ?string
    {
        return static::groupPackPath('portal', 'portal_translations', $locale);
    }

    /**
     * Pack for a translation group: the hand-maintained
     * resources/lang/<locale>/<group>.php first, then the generated
     * database/seeders/<packDir>/<locale>.php.
     */
    public static function groupPackPath(string $group, string $packDir, string $locale): ?string
    {
        foreach ([
            lang_path($locale.'/'.$group.'.php'),
            database_path('seeders/'.$packDir.'/'.$locale.'.php'),
        ] as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    /** Catalog entries that ship a storefront pack. */
    public static function storeAvailable(): array
    {
        return static::groupAvailable('messages', 'store_translations');
    }

    /** Catalog entries that ship a pack for the given group. */
    public static function groupAvailable(string $group, string $packDir): array
    {
        $out = [];
        foreach (array_keys(static::all()) as $locale) {
            if (static::groupPackPath($group, $packDir, $locale)) {
                $out[$locale] = static::find($locale);
            }
        }

        return $out;
    }

    /** Locales the catalog marks right-to-left. */
    public static function rtlLocales(): array
    {
        return array_keys(array_filter(static::all(), fn ($e) => ! empty($e['rtl'])));
    }

    /**
     * Name of the language in itself — what a storefront visitor looks for in
     * the switcher ("Deutsch", not "German"). Falls back to the catalog name.
     */
    public static function nativeName(string $locale): string
    {
        $native = [
            'en' => 'English', 'fr' => 'Français', 'ar' => 'العربية', 'es' => 'Español',
            'tur' => 'Türkçe', 'thai' => 'ไทย', 'hn' => 'हिन्दी', 'de' => 'Deutsch',
            'it' => 'Italiano', 'Ind' => 'Bahasa Indonesia', 'sm_ch' => '简体中文',
            'tr_ch' => '繁體中文', 'ru' => 'Русский', 'vn' => 'Tiếng Việt', 'kr' => '한국어',
            'ba' => 'বাংলা', 'br' => 'Português', 'da' => 'Dansk', 'ja' => '日本語',
            'pl' => 'Polski', 'sw' => 'Kiswahili', 'ha' => 'Hausa', 'yo' => 'Yorùbá',
            'am' => 'አማርኛ',
        ];

        return $native[$locale] ?? (static::all()[$locale]['name'] ?? $locale);
    }

    /** ISO-ish tag for Carbon/HTML lang (legacy codes like 'sm_ch' → 'zh_CN'). */
    public static function isoTag(string $locale): string
    {
        return [
            'sm_ch' => 'zh_CN', 'tr_ch' => 'zh_TW', 'Ind' => 'id', 'tur' => 'tr',
            'thai' => 'th', 'hn' => 'hi', 'vn' => 'vi', 'kr' => 'ko', 'ba' => 'bn',
            'br' => 'pt_BR',
        ][$locale] ?? $locale;
    }

    public static function isDefault(string $locale): bool
    {
        return in_array($locale, static::DEFAULT_LOCALES, true);
    }

    /** Rows for the `languages` table on a fresh install. */
    public static function defaultLanguageRows(): array
    {
        $rows = [];
        foreach (static::DEFAULT_LOCALES as $locale) {
            $entry = static::find($locale);
            $rows[] = [
                'name' => $entry['name'],
                'locale' => $locale,
                'flag' => $entry['flag'],
                'is_active' => true,
                'is_default' => $locale === 'en',
            ];
        }

        return $rows;
    }

    protected static function normalize(string $locale, array $entry): array
    {
        $file = $entry['file'] ?? $locale.'.php';

        return [
            'locale' => $locale,
            'name' => $entry['name'],
            'flag' => $entry['flag'] ?? null,
            'rtl' => (bool) ($entry['rtl'] ?? false),
            'is_default_pack' => static::isDefault($locale),
            'file' => $file,
            'path' => database_path('seeders/translations/'.$file),
        ];
    }
}
