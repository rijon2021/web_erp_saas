import { toRaw } from 'vue';
import { createI18n } from 'vue-i18n';
import { supportMessages } from './support';
import { fallbackMessages } from './fallback';

/**
 * Translations come from the SAME source as the legacy admin:
 *   GET /api/translations/{locale}  ->  flat { key: value } map (public route)
 * and the active language lives in localStorage under 'language' — the same key
 * the Vue 2 app uses, so switching language in either app carries over.
 *
 * Keys are the legacy underscore style: $t('Search_this_table').
 */

export const SUPPORTED_LOCALES = [
    { value: 'en', label: 'English', flag: '🇬🇧' },
    { value: 'fr', label: 'Français', flag: '🇫🇷' },
    { value: 'es', label: 'Español', flag: '🇪🇸' },
    { value: 'ar', label: 'العربية', flag: '🇸🇦' },
];

const RTL_LOCALES = ['ar'];

export function isRtlLocale(locale) {
    return RTL_LOCALES.includes(locale);
}

export function storedLocale() {
    try {
        return window.localStorage.getItem('language') || 'en';
    } catch (e) {
        return 'en';
    }
}

// Case-insensitive rescue index: locale -> { lowercased key: value }. The
// legacy key set mixes casings ('warehouse' vs 'Warehouse'); when a lookup
// misses only because of casing, serve the other casing's translation instead
// of leaking the raw key.
const lowerIndex = new Map();

function indexLocale(locale, messages) {
    const idx = Object.create(null);
    for (const k of Object.keys(messages || {})) idx[k.toLowerCase()] = messages[k];
    lowerIndex.set(locale, idx);
}

// Per-messages-object lowercase index for messageResolver below, rebuilt
// automatically whenever setLocaleMessage swaps in a new object.
const resolverIndex = new WeakMap();

// Exact key first, then the same locale's other casing, BEFORE vue-i18n moves
// on to the fallback locale. The `missing` hook alone can't do this: its value
// for the active locale is overwritten when the loop goes on to 'en'. The
// translations table's unique index is case-insensitive, so the seeder keeps
// one casing per key (POS_Settings vs Pos_Settings) and the other never exists.
// All messages are flat — dotted keys like 'pos.Cart' are literal keys.
function messageResolver(obj, path) {
    if (obj == null) return null;
    const exact = obj[path];
    if (exact != null) return exact;
    const raw = toRaw(obj);
    let idx = resolverIndex.get(raw);
    if (!idx) {
        idx = Object.create(null);
        for (const k of Object.keys(raw)) {
            const lk = k.toLowerCase();
            if (idx[lk] === undefined) idx[lk] = raw[k];
        }
        resolverIndex.set(raw, idx);
    }
    const v = idx[String(path).toLowerCase()];
    return v === undefined ? null : v;
}

export const i18n = createI18n({
    legacy: false,          // Composition API mode
    globalInjection: true,  // keep $t() available in templates
    locale: storedLocale(),
    fallbackLocale: 'en',
    messages: {},
    messageResolver,
    missingWarn: false,
    fallbackWarn: false,
    // Runs only on a miss; active locale's other casing first, then English's.
    missing: (locale, key) => {
        const lk = String(key).toLowerCase();
        for (const loc of [locale, 'en']) {
            const idx = lowerIndex.get(loc);
            if (idx && idx[lk] !== undefined) return idx[lk];
        }
        return undefined;
    },
});

const loaded = new Set();

// Offline fallback: last successfully fetched messages per locale. The API
// stays the source of truth (revalidated with cache: 'no-cache' every boot); this
// copy is only read when that fetch fails, so an offline reload of the POS
// doesn't render raw i18n keys.
const CACHE_PREFIX = 'stocky_i18n_cache_v1_';

function readCachedMessages(locale) {
    try {
        const raw = window.localStorage.getItem(CACHE_PREFIX + locale);
        if (!raw) return null;
        const parsed = JSON.parse(raw);
        return parsed && typeof parsed === 'object' ? parsed : null;
    } catch (e) {
        return null;
    }
}

function writeCachedMessages(locale, messages) {
    try {
        window.localStorage.setItem(CACHE_PREFIX + locale, JSON.stringify(messages || {}));
    } catch (e) { /* quota/private mode — offline fallback just won't exist */ }
}

/**
 * Fetches a locale's messages once and activates it. Never throws: on failure
 * it falls back to the last cached copy, and only when there is no cache does
 * $t() echo the key (which stays readable because the legacy keys are
 * English-ish, e.g. 'Search_this_table').
 */
export async function loadLocale(locale) {
    // English is the fallbackLocale: a key a locale's table doesn't carry yet
    // (new keys land in en.php first) must render its English text, not the
    // raw key — vue-i18n can only fall back to messages it has loaded.
    await Promise.all([ensureMessages(locale), locale !== 'en' ? ensureMessages('en') : null]);

    i18n.global.locale.value = locale;
    // Only lang here: <html dir> follows the ui store's direction (App.vue),
    // the same value Ant's ConfigProvider uses, so the two can't disagree.
    document.documentElement.setAttribute('lang', locale);
    try {
        window.localStorage.setItem('language', locale);
    } catch (e) { /* ignore storage errors */ }
}

async function ensureMessages(locale) {
    if (!loaded.has(locale)) {
        let messages = null;
        try {
            const res = await fetch(`/api/translations/${locale}`, {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
                // Translations are edited at runtime (admin UI, seeder), so the
                // copy is always revalidated: the server answers 304 (empty
                // body) via its ETag when nothing changed, and the browser then
                // hands back its stored copy — never a stale one.
                cache: 'no-cache',
            });
            if (res.ok) {
                messages = await res.json();
                writeCachedMessages(locale, messages);
            }
        } catch (e) {
            console.warn(`[stocky-next] could not load translations for "${locale}" — trying offline cache`);
        }
        if (!messages) messages = readCachedMessages(locale);
        // Bundled strings (i18n/fallback.js for keys this product's seeder
        // lacks, i18n/support.js for the Support module) sit UNDER the DB
        // translations — a DB value always wins — as the legacy i18n.loader did,
        // so the UI stays readable on a tenant whose table was never re-seeded.
        const merged = Object.assign({}, fallbackMessages(locale), supportMessages(locale), messages || {});
        i18n.global.setLocaleMessage(locale, merged);
        indexLocale(locale, merged);
        if (messages) loaded.add(locale);
    }
}

/** Translate with a human-readable fallback when a key is missing. */
export function t(key, fallback) {
    const out = i18n.global.t(key);
    return out === key && fallback ? fallback : out;
}
