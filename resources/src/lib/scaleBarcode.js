/**
 * Weighing-scale labels (System Settings > Weighing scale). A label reads,
 * left to right:
 *
 *   prefix | item code (plu_digits) | skipped digits | value (value_digits) | check digit
 *
 * The value is a weight, or the total price the scale worked out, with
 * value_decimals implied decimals. Formats are cleaned server-side by
 * App\Services\Pos\ScaleBarcode; decoding happens here so the POS keeps
 * working offline. Used by the POS, the sale form, the transfer form and the
 * settings page's test box.
 */

/** The layout every tenant used before this setting existed. */
export const LEGACY_FORMAT = Object.freeze({
  name: 'Legacy (7-digit product code)',
  prefix: '',
  plu_digits: 7,
  skip_digits: 0,
  value_digits: 5,
  value_decimals: 3,
  value_type: 'weight',
  check_digit: false,
});

/**
 * Starting point for a new format: EAN-13 in-store label — 2, a 6-digit item
 * code, the weight in grams, check digit (1 + 6 + 5 + 1 = 13).
 */
export const DEFAULT_FORMAT = Object.freeze({
  name: '',
  prefix: '2',
  plu_digits: 6,
  skip_digits: 0,
  value_digits: 5,
  value_decimals: 3,
  value_type: 'weight',
  check_digit: true,
});

export const MAX_FORMATS = 10;
export const MIN_LENGTH = 8;
export const MAX_LENGTH = 14;

const toInt = (v, d) => (v === '' || v == null || Number.isNaN(Number(v)) ? d : Math.trunc(Number(v)));

/** Digits on a label of this format, check digit included. */
export function formatLength(f) {
  return String(f.prefix || '').length + f.plu_digits + f.skip_digits + f.value_digits + 1;
}

/** Why a format can't be used (a translation key), or null when it is fine. */
export function formatProblem(f) {
  if (f.prefix !== '' && !/^\d{1,4}$/.test(f.prefix)) return 'Scale_Err_Prefix';
  if (f.plu_digits < 1 || f.plu_digits > 8) return 'Scale_Err_Plu';
  if (f.skip_digits < 0 || f.skip_digits > 3) return 'Scale_Err_Skip';
  if (f.value_digits < 1 || f.value_digits > 8) return 'Scale_Err_Value';
  if (f.value_decimals < 0 || f.value_decimals > Math.min(6, f.value_digits)) return 'Scale_Err_Decimals';
  const len = formatLength(f);
  if (len < MIN_LENGTH || len > MAX_LENGTH) return 'Scale_Err_Length';
  return null;
}

export function cleanFormat(row) {
  const r = row && typeof row === 'object' ? row : {};
  return {
    name: String(r.name ?? '').slice(0, 60),
    prefix: String(r.prefix ?? '').trim(),
    plu_digits: toInt(r.plu_digits, 5),
    skip_digits: toInt(r.skip_digits, 0),
    value_digits: toInt(r.value_digits, 5),
    value_decimals: toInt(r.value_decimals, 3),
    value_type: r.value_type === 'price' ? 'price' : 'weight',
    check_digit: r.check_digit === true || r.check_digit === 1 || r.check_digit === '1' || r.check_digit === 'true',
  };
}

/** Usable formats from a stored value (array or JSON string). */
export function normalizeFormats(raw) {
  let list = raw;
  if (typeof list === 'string') {
    try { list = JSON.parse(list); } catch (e) { list = null; }
  }
  if (!Array.isArray(list)) return [];
  return list.slice(0, MAX_FORMATS).map(cleanFormat).filter(f => !formatProblem(f));
}

/** { enabled, formats } from a pos_settings payload. */
export function scaleConfig(posSettings) {
  const s = posSettings || {};
  // No scale columns yet (settings not loaded, or the 2026_10_27_000004
  // migration hasn't run): keep reading labels the old hard-coded way.
  if (!('scale_barcode_enabled' in s)) return { enabled: true, formats: [cleanFormat(LEGACY_FORMAT)] };
  const on = s.scale_barcode_enabled;
  return {
    enabled: on === true || on === 1 || on === '1',
    formats: normalizeFormats(s.scale_barcode_formats),
  };
}

/** GS1 mod-10 check digit (EAN-8/13, UPC-A) for the digits before it. */
export function checkDigitFor(body) {
  let sum = 0;
  const digits = String(body);
  for (let i = digits.length - 1, w = 3; i >= 0; i--, w = w === 3 ? 1 : 3) {
    sum += Number(digits[i]) * w;
  }
  return String((10 - (sum % 10)) % 10);
}

/** Item codes compare without leading zeros: a label's "00042" is PLU 42. */
export function normalizePlu(v) {
  const s = String(v ?? '').trim().replace(/^0+/, '');
  return s === '' && String(v ?? '').trim() !== '' ? '0' : s;
}

/**
 * Formats whose shape (length, prefix, all digits) fits this code, longest
 * prefix first, each read into { format, index, plu, value, checkOk }.
 */
export function decodeScaleBarcode(rawCode, formats) {
  const code = String(rawCode ?? '').trim();
  if (!/^\d+$/.test(code)) return [];
  const out = [];
  (formats || []).forEach((f, index) => {
    if (formatLength(f) !== code.length || !code.startsWith(f.prefix)) return;
    let pos = f.prefix.length;
    const plu = code.substr(pos, f.plu_digits);
    pos += f.plu_digits + f.skip_digits;
    const digits = code.substr(pos, f.value_digits);
    const value = Number(digits) / Math.pow(10, f.value_decimals);
    const checkOk = checkDigitFor(code.slice(0, -1)) === code.slice(-1);
    out.push({ format: f, index, plu, digits, value, checkOk });
  });
  return out.sort((a, b) => b.format.prefix.length - a.format.prefix.length);
}

/** Index of the product a label's item code names: Scale PLU first, then the product code. */
export function findScaleProduct(products, plu) {
  const wanted = normalizePlu(plu);
  let idx = products.findIndex(p => p.scale_plu != null && p.scale_plu !== '' && normalizePlu(p.scale_plu) === wanted);
  if (idx === -1) idx = products.findIndex(p => p.code === plu);
  return idx;
}

/**
 * Resolve a scale label against a product list.
 *  - null: no format has this shape (not a scale label — search normally).
 *  - { idx, weight, price, format }: found; weight in the label's unit. A
 *    price label is divided by the product's price (priceOf) into a weight,
 *    so the line is still priced from the catalogue.
 *  - { idx: -1, error }: looks like a scale label but can't be used —
 *    'check_digit', 'not_found' or 'no_price'.
 */
export function resolveScaleScan(products, code, formats, priceOf = p => Number(p.Net_price)) {
  const candidates = decodeScaleBarcode(code, formats);
  if (!candidates.length || !Array.isArray(products)) return null;

  let error = 'not_found';
  for (const c of candidates) {
    if (c.format.check_digit && !c.checkOk) { error = 'check_digit'; continue; }
    const idx = findScaleProduct(products, c.plu);
    if (idx === -1) continue;
    if (c.format.value_type === 'price') {
      const unit = Number(priceOf(products[idx]));
      if (!(unit > 0)) { error = 'no_price'; continue; }
      return { idx, weight: Math.round((c.value / unit) * 1000) / 1000, price: c.value, format: c.format };
    }
    return { idx, weight: c.value, price: null, format: c.format };
  }
  return { idx: -1, error };
}

/**
 * The Unit_price that makes a sale line come to `target` at its quantity,
 * keeping the line's own discount and tax — so a price label charges exactly
 * what it printed. Inverse of the POS / lib/lineCalc line maths: one unit
 * costs (U − discount) × (1 + tax) with exclusive tax, U − discount with
 * inclusive tax; discount_Method '2' is a fixed amount, anything else a percent.
 * null when it can't be done (no quantity, a 100% discount).
 */
export function unitPriceForTotal(line, target) {
  const qty = Number(line.quantity) || 0;
  if (!(qty > 0) || !(target > 0)) return null;
  const perUnit = target / qty;
  const rate = (Number(line.tax_percent) || 0) / 100;
  const discount = Number(line.discount) || 0;
  const afterDiscount = String(line.tax_method) === '2' ? perUnit : perUnit / (1 + rate);
  let unit;
  if (String(line.discount_Method) === '2') {
    unit = afterDiscount + discount;
  } else {
    const keep = 1 - discount / 100;
    unit = keep > 0 ? afterDiscount / keep : null;
  }
  return unit > 0 ? unit : null;
}

/**
 * A sample label for a format — item code 12345…, 1.500 kg or 12.50 — with a
 * valid check digit, for the settings page's "Try example".
 */
export function exampleBarcode(f) {
  const plu = '1234567890'.slice(0, f.plu_digits).padStart(f.plu_digits, '0');
  const sample = f.value_type === 'price' ? 12.5 : 1.5;
  let raw = String(Math.round(sample * Math.pow(10, f.value_decimals)));
  if (raw.length > f.value_digits) raw = '1'.padStart(f.value_digits, '0');
  const body = f.prefix + plu + '0'.repeat(f.skip_digits) + raw.padStart(f.value_digits, '0');
  return body + checkDigitFor(body);
}
