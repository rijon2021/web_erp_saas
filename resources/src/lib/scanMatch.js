import { formatLength, resolveScaleScan } from './scaleBarcode';

/** True when `code` is one of the product's additional barcodes (product_barcodes). */
export function hasExtraBarcode(product, code) {
    return Array.isArray(product?.barcodes) && product.barcodes.includes(code);
}

/**
 * Resolve a scanned/typed barcode to a product, mirroring the legacy create
 * forms' search():
 *  - weighing forms (sales, transfers) read weighing-scale labels with the
 *    formats from System Settings > Weighing scale (`scale` = scaleConfig()):
 *    a product whose code equals the scan wins, otherwise the label's item
 *    code finds the product and its value becomes the quantity. A price label
 *    is divided by priceOf(product) into a weight.
 *  - otherwise a product whose `code` equals the input, or whose `barcode`
 *    contains it, and only when that yields exactly one product (legacy only
 *    auto-adds a single match; ambiguous input falls back to manual search).
 *
 * Returns { idx, weight, price } — idx into the products array, weight or
 * null, and a price label's printed price — or null when nothing matched.
 */
export function resolveScan(products, rawCode, { weighing = false, scale = null, priceOf } = {}) {
    const code = String(rawCode ?? '').trim();
    if (!code || !Array.isArray(products)) return null;

    // Only input as long as a scale label; anything else is a normal search.
    if (weighing && scale?.enabled && /^\d+$/.test(code)
        && scale.formats.some(f => formatLength(f) === code.length)) {
        const idx = products.findIndex(p => p.code === code);
        if (idx !== -1) return { idx, weight: null };

        const hit = resolveScaleScan(products, code, scale.formats, priceOf);
        // A label that fits a scale format never falls through to text search.
        if (hit) return hit.idx === -1 ? null : { idx: hit.idx, weight: hit.weight, price: hit.price };
    }

    // Regular barcode: exact code, barcode-contains, or an exact additional
    // barcode (manufacturer EAN …), single match only.
    const matches = [];
    products.forEach((p, i) => {
        if (p.code === code || String(p.barcode ?? '').includes(code) || hasExtraBarcode(p, code)) matches.push(i);
    });
    return matches.length === 1 ? { idx: matches[0], weight: null } : null;
}
