/**
 * A sale return's share of the sale's order-level discount (header discount,
 * points, promotions): the reduction × returned lines value / sale lines value.
 * A full return gets the whole reduction, so it refunds what the customer
 * paid. `meta` is the sale_lines_total / sale_order_discount pair that
 * create_sell_return and edit_sell_return send; lines use the return form's
 * own per-unit Net_price + taxe, the same math the server summed the sale with.
 */
export function returnDiscountShare(details, meta, decimals = 2) {
    const linesTotal = Number(meta?.sale_lines_total) || 0;
    const reduction = Number(meta?.sale_order_discount) || 0;
    if (linesTotal <= 0 || reduction <= 0) return 0;

    const returned = (details || []).reduce(
        (sum, d) => sum + (Number(d.quantity) || 0) * ((Number(d.Net_price) || 0) + (Number(d.taxe) || 0)),
        0
    );

    return Number(Math.min(reduction, (reduction * returned) / linesTotal).toFixed(decimals));
}
