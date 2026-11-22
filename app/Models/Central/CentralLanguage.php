<?php

namespace App\Models\Central;

use App\Support\CentralLanguageCatalog;
use Illuminate\Database\Eloquent\Model;

class CentralLanguage extends Model
{
    protected $connection = 'central';

    protected $fillable = [
        'name',
        'native_name',
        'locale',
        'flag',
        'is_active',
        'is_default',
        'is_rtl',
        'sort_order',
        'translations_version',
    ];

    protected $casts = [
        'is_active'  => 'boolean',
        'is_default' => 'boolean',
        'is_rtl'     => 'boolean',
        'translations_version' => 'integer',
    ];

    /** @var array<string, bool>|null locale => is_rtl, memoized per request */
    protected static ?array $rtlMap = null;

    /** Active languages, sorted for display. */
    public static function active()
    {
        return static::where('is_active', true)->orderBy('sort_order')->get();
    }

    /** Default language locale. */
    public static function defaultLocale(): string
    {
        return static::where('is_default', true)->value('locale') ?? config('app.locale', 'en');
    }

    /**
     * Whether the locale renders right-to-left. Reads the admin's per-language
     * toggle; falls back to the catalog before central_languages is reachable.
     */
    public static function isRtl(?string $locale): bool
    {
        if (! $locale) {
            return false;
        }

        if (static::$rtlMap === null) {
            try {
                static::$rtlMap = static::pluck('is_rtl', 'locale')->map(fn ($v) => (bool) $v)->all();
            } catch (\Throwable $e) {
                static::$rtlMap = [];
            }
        }

        return static::$rtlMap[$locale]
            ?? in_array($locale, CentralLanguageCatalog::rtlLocales(), true);
    }

    public static function forgetRtlMap(): void
    {
        static::$rtlMap = null;
    }

    /** Name in its own language for switchers (English name before the column is filled). */
    public function getDisplayNameAttribute(): string
    {
        return $this->native_name ?: (CentralLanguageCatalog::get($this->locale)['native'] ?? $this->name);
    }
}
