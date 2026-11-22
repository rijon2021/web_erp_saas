/**
 * Price formatting — ported 1:1 from the legacy app's resources/src/utils/priceFormat.js,
 * with the Vuex-store branches removed (the auth pinia store supplies the settings).
 * Presentational only: never used for calculations or stored values.
 *
 * Formats:
 *   comma_dot   => 1,234.56
 *   dot_comma   => 1.234,56
 *   space_comma => 1 234,56
 */

export const PRICE_FORMATS = {
    comma_dot: { thousands: ',', decimal: '.' },
    dot_comma: { thousands: '.', decimal: ',' },
    space_comma: { thousands: ' ', decimal: ',' },
};

export function normalizePriceFormatKey(input) {
    if (!input) return null;
    const raw = String(input).trim();
    if (PRICE_FORMATS[raw]) return raw;

    // Safety net: some settings rows store the human label instead of the key.
    const labelMap = {
        '1,234.56 (thousand , decimal .)': 'comma_dot',
        '1.234,56 (thousand . decimal ,)': 'dot_comma',
        '1 234,56 (thousand space, decimal ,)': 'space_comma',
    };
    return labelMap[raw] || null;
}

/**
 * "Hide Zero Decimals" system setting: drop the fraction of an already
 * formatted number when it is all zeros ("1,234.00" -> "1,234"), leaving any
 * other fraction untouched ("1,234.50" stays). `decimalSep` is the decimal
 * character the string was formatted with.
 */
export function stripZeroFraction(formatted, decimalSep = '.') {
    const s = String(formatted ?? '');
    const i = s.lastIndexOf(decimalSep);
    if (i < 0) return s;
    const frac = s.slice(i + decimalSep.length);
    return /^0+$/.test(frac) ? s.slice(0, i) : s;
}

/** Boolean "Hide Zero Decimals" setting from the auth payload, with the localStorage cache as the offline fallback. */
export function resolveHideZeroDecimals(userValue) {
    if (userValue === true || userValue === 1 || userValue === '1' || userValue === 'true') return true;
    if (userValue === false || userValue === 0 || userValue === '0' || userValue === 'false') return false;
    try {
        return window.localStorage.getItem('app_hide_zero_decimals') === '1';
    } catch (e) { /* ignore */ }
    return false;
}

export function formatPriceDisplay(value, decimals = 2, formatKey = null, hideZeroDecimals = false) {
    const d = Number.isInteger(decimals) ? decimals : 0;
    const n = Number(value);
    const safe = Number.isFinite(n) ? n : 0;
    const key = normalizePriceFormatKey(formatKey);
    const trim = s => (hideZeroDecimals ? stripZeroFraction(s, key ? PRICE_FORMATS[key].decimal : '.') : s);

    // Legacy default (en-US style) when no format is configured.
    if (!key) {
        try {
            return trim(safe.toLocaleString('en-US', {
                minimumFractionDigits: d,
                maximumFractionDigits: d,
            }));
        } catch (e) {
            const fixed = safe.toFixed(d);
            const [intPart, fracPart = ''] = fixed.split('.');
            const withCommas = intPart.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
            return d > 0 ? trim(`${withCommas}.${fracPart}`) : withCommas;
        }
    }

    const cfg = PRICE_FORMATS[key];
    const fixed = safe.toFixed(d);
    let [intPart, fracPart = ''] = fixed.split('.');
    intPart = intPart.replace(/\B(?=(\d{3})+(?!\d))/g, cfg.thousands);

    if (d <= 0) return intPart;
    if (fracPart.length < d) fracPart = fracPart.padEnd(d, '0');
    return trim(`${intPart}${cfg.decimal}${fracPart}`);
}

// Strong right-to-left letters: Hebrew, Arabic, Syriac, Thaana, NKo and the
// Hebrew/Arabic presentation forms.
const RTL_SYMBOL_RE = /[\u0590-\u08FF\uFB1D-\uFDFF\uFE70-\uFEFF]/;

/**
 * The "Currency Position" system setting, one of:
 *   'before'         → "$ 100"   (default)
 *   'before_nospace' → "$100"
 *   'after'          → "100 $"
 *   'after_nospace'  → "100$"
 * Anything else falls back to 'before'.
 */
export const CURRENCY_POSITIONS = ['before', 'before_nospace', 'after', 'after_nospace'];

export function normalizeCurrencyPosition(value) {
    return CURRENCY_POSITIONS.includes(value) ? value : 'before';
}

/** True when the symbol goes after the amount ('after' / 'after_nospace'). */
export function isCurrencyAfter(position) {
    return normalizeCurrencyPosition(position).startsWith('after');
}

/** The separator between symbol and amount for the position: ' ' or ''. */
export function currencySpace(position) {
    return normalizeCurrencyPosition(position).endsWith('_nospace') ? '' : ' ';
}

/**
 * Attach a currency symbol to an already-formatted amount, before ("SAR 100")
 * or after ("100 SAR") it, with or without a space, per the Currency Position
 * setting. Mirrors helpers::with_currency on the server.
 *
 * An Arabic/Hebrew symbol written next to digits pulls them into its
 * right-to-left run (Unicode bidi rule W2), so a browser draws "ر.س 100" as
 * "100 ر.س" on a left-to-right line and the setting looks ignored. Such
 * symbols are wrapped in First-Strong-Isolate … Pop-Directional-Isolate so the
 * text reads in the configured order in both LTR and RTL layouts. Latin
 * symbols are left untouched.
 */
export function withCurrency(symbol, formatted, position = 'before') {
    const sym = symbol == null ? '' : String(symbol).trim();
    if (!sym) return formatted;
    const shown = RTL_SYMBOL_RE.test(sym) ? `\u2068${sym}\u2069` : sym;
    const sep = currencySpace(position);
    return isCurrencyAfter(position)
        ? `${formatted}${sep}${shown}`
        : `${shown}${sep}${formatted}`;
}

/**
 * Round a monetary amount to a real Number (not a display string). Use for
 * values that feed calculations or input bounds — e.g. an a-input-number
 * :max — where a raw float sum like 121.99999999999994 would leak into the UI.
 */
export function roundAmount(value, decimals = 2) {
    const n = Number(value);
    if (!Number.isFinite(n)) return 0;
    const d = Number.isInteger(decimals) && decimals >= 0 ? decimals : 2;
    // Round via the fixed-decimal string: immune to the 1.005 * 100 = 100.49999 class of errors.
    return Number(n.toFixed(d));
}

/** Monetary precision: 2 or 3, mirroring the "Enable 3 Decimal Pricing" setting. */
export function resolvePriceDecimals(userValue) {
    const n = parseInt(userValue, 10);
    if (n === 3) return 3;
    if (n === 2) return 2;
    try {
        if (parseInt(window.localStorage.getItem('app_price_decimals'), 10) === 3) return 3;
    } catch (e) { /* ignore */ }
    return 2;
}

/**
 * Drop the bidi isolate marks withCurrency() may add. For export targets that
 * do no bidi processing (jsPDF, spreadsheets), where the invisible marks would
 * otherwise print as missing-glyph boxes or linger in cell text.
 */
export function stripBidiIsolates(value) {
    return String(value ?? '').replace(/[\u2066-\u2069]/g, '');
}
