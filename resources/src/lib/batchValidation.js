/**
 * Batch-allocation validation for the document forms, copied rule-for-rule
 * from legacy create_sale.vue's hasBatchValidationErrors /
 * firstBatchErrorMessage. A "select" allocation row is
 * {product_batch_id, batch_no, expiry_date, qty_available, qty}.
 */

export function batchTotalQty(line) {
    return (Array.isArray(line.batches) ? line.batches : [])
        .reduce((sum, b) => sum + (Number(b.qty) || 0), 0);
}

function hasDuplicates(batches) {
    const seen = new Set();
    for (const b of batches) {
        if (!b.product_batch_id) continue;
        if (seen.has(b.product_batch_id)) return true;
        seen.add(b.product_batch_id);
    }
    return false;
}

function sumQty(batches) {
    return batches.reduce((sum, b) => sum + (Number(b.qty) || 0), 0);
}

/**
 * Select-style rules shared by the sale, quotation, transfer, damage and
 * adjustment forms. Options let a form relax them per line:
 *  - optional(line): no batch chosen is fine (the backend allocates FEFO);
 *    rows left without a batch are ignored, but any chosen row must be valid
 *    and the chosen rows must then add up to the line quantity.
 *  - unlimited(line): the line puts stock IN (an "add" adjustment), so the
 *    picked quantity is not capped by what the batch currently holds.
 * Returns the failing key (a translation key) and label, or null.
 */
function firstSelectFailure(lines, options = {}) {
    const { optional, unlimited } = options;
    for (const d of lines) {
        if (!d || !d.is_batch_tracked) continue;
        let batches = Array.isArray(d.batches) ? d.batches : [];
        const label = d.name || d.code || '';
        const isOptional = typeof optional === 'function' && optional(d);
        const isUnlimited = typeof unlimited === 'function' && unlimited(d);
        if (isOptional) {
            batches = batches.filter(b => b && b.product_batch_id);
            if (batches.length === 0) continue;
        }
        if (batches.length === 0) return { key: 'Select_Batch_Required_For', label };
        for (const b of batches) {
            if (!b.product_batch_id) return { key: 'Select_Batch_Required_For', label };
            const q = Number(b.qty);
            if (!(q > 0)) return { key: 'Batch_Qty_Required_For', label };
            if (!isUnlimited && q > (Number(b.qty_available) || 0)) return { key: 'Batch_Qty_Exceeds_Available', label };
        }
        if (hasDuplicates(batches)) return { key: 'Duplicate_Batch_Selected', label };
        if (Math.abs(sumQty(batches) - (Number(d.quantity) || 0)) > 0.0001) return { key: 'Total_batch_qty_mismatch', label };
    }
    return null;
}

/** Sale/quotation-style validation (allocating EXISTING batches). */
export function hasBatchSelectErrors(lines, options) {
    return firstSelectFailure(lines, options) !== null;
}

/** First failing line's message, with legacy's exact keys. `t` = i18n. */
export function firstBatchSelectError(lines, t, options) {
    const f = firstSelectFailure(lines, options);
    if (!f) return '';
    return f.key === 'Total_batch_qty_mismatch'
        ? `${t(f.key)} — ${f.label}`
        : `${t(f.key)} ${f.label}`;
}

/** Purchase-style validation (ENTERING new batches: only presence + total). */
export function hasBatchEntryErrors(lines) {
    return lines.some(d => {
        if (!d || !d.is_batch_tracked) return false;
        if (!Array.isArray(d.batches) || d.batches.length === 0) return true;
        return Math.abs(batchTotalQty(d) - (Number(d.quantity) || 0)) > 0.0001;
    });
}

/**
 * Quantity-weighted average of the batch unit costs entered on a purchase
 * line. Rows without a numeric unit cost are left out of both the numerator
 * and the denominator, so a cleared cost field does not drag the average to
 * zero. Returns null when no priced row has a quantity.
 */
export function batchWeightedAvgCost(line) {
    const rows = Array.isArray(line?.batches) ? line.batches : [];
    let qty = 0;
    let value = 0;
    for (const b of rows) {
        const q = Number(b.qty);
        const c = b.unit_cost === null || b.unit_cost === '' ? NaN : Number(b.unit_cost);
        if (!(q > 0) || !Number.isFinite(c)) continue;
        qty += q;
        value += q * c;
    }
    return qty > 0 ? value / qty : null;
}

/**
 * Purchase rule: a batch-tracked line is costed at the weighted average of
 * its batches, never at a hand-typed figure. Writes Unit_cost on the line and
 * returns true when it did; the caller recomputes the line afterwards.
 */
export function syncLineCostFromBatches(line) {
    if (!line || !line.is_batch_tracked) return false;
    const avg = batchWeightedAvgCost(line);
    if (avg === null) return false;
    line.Unit_cost = avg;
    return true;
}
