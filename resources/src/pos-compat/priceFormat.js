// Price formatting helper for frontend display (e.g., POS)
// This helper is purely presentational and does NOT affect calculations or stored values.

// Separators, key normalisation and the formatter itself are the shared Vue 3
// admin implementation (resources/src/lib/priceFormat.js), so the POS, its
// receipts and the rest of the app render an amount identically, including
// the "Hide Zero Decimals" setting (formatPriceDisplay's 4th argument).
export {
  PRICE_FORMATS,
  normalizePriceFormatKey,
  formatPriceDisplay,
  stripZeroFraction,
  resolveHideZeroDecimals,
} from '../lib/priceFormat';

// Get the selected price format from:
// - explicit settings object (preferred)
// - Vuex store getter (getPriceFormat) - from get_user_auth API
// This helper never throws; it returns null if no valid format is found.
export function getPriceFormatSetting({ settings = null, store = null } = {}) {
  // 1) Explicit settings object (e.g., System Settings API payload)
  if (settings && settings.price_format) {
    const key = normalizePriceFormatKey(settings.price_format);
    if (key) {
      return key;
    }
  }

  // 2) Vuex store getter (from get_user_auth API)
  if (store && typeof store.getters === 'object' && store.getters.getPriceFormat) {
    try {
      const priceFormat = store.getters.getPriceFormat;
      const key = normalizePriceFormatKey(priceFormat);
      if (key) {
        return key;
      }
    } catch (e) {
      // ignore
    }
  }

  // No valid setting => use default behavior (caller should treat null as "legacy" formatting)
  return null;
}

// Resolve the configured monetary precision (2 or 3) from:
// - explicit settings object (System Settings API payload: enable_3_decimal_pricing)
// - Vuex store getter (getPriceDecimals) - from get_user_auth API
// - localStorage cache (set at login) as an offline-friendly fallback
// Always returns 2 or 3; defaults to 2.
export function getPriceDecimals({ settings = null, store = null } = {}) {
  // 1) Explicit settings object
  if (settings && typeof settings.enable_3_decimal_pricing !== 'undefined') {
    const enabled = settings.enable_3_decimal_pricing === true
      || settings.enable_3_decimal_pricing === 1
      || settings.enable_3_decimal_pricing === '1';
    return enabled ? 3 : 2;
  }

  // 2) Vuex store getter
  if (store && typeof store.getters === 'object' && store.getters.getPriceDecimals) {
    try {
      const n = parseInt(store.getters.getPriceDecimals, 10);
      if (n === 3) return 3;
      if (n === 2) return 2;
    } catch (e) {
      // ignore
    }
  }

  // 3) localStorage cache
  if (typeof window !== 'undefined' && window.localStorage) {
    try {
      const cached = parseInt(window.localStorage.getItem('app_price_decimals'), 10);
      if (cached === 3) return 3;
    } catch (e) {
      // ignore
    }
  }

  return 2;
}

// Cache the monetary precision (2 or 3) into localStorage for quick frontend access
export function cachePriceDecimals(decimals) {
  const n = (parseInt(decimals, 10) === 3) ? 3 : 2;
  if (typeof window === 'undefined' || !window.localStorage) return;
  try {
    window.localStorage.setItem('app_price_decimals', String(n));
  } catch (e) {
    // ignore quota or storage errors
  }
}

// Cache a selected price format key into localStorage for quick frontend access
export function cachePriceFormat(formatKey) {
  const key = normalizePriceFormatKey(formatKey);
  if (!key) return;
  if (typeof window === 'undefined' || !window.localStorage) return;
  try {
    window.localStorage.setItem('app_price_format', key);
  } catch (e) {
    // ignore quota or storage errors
  }
}


// Currency symbol placement (System Settings → Currency Position) — shared
// with the Vue 3 admin so the POS, its receipts and the rest of the app agree.
export { withCurrency, normalizeCurrencyPosition, isCurrencyAfter, currencySpace, CURRENCY_POSITIONS } from '../lib/priceFormat';
