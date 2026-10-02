/**
 * Branch letterhead for receipts.
 *
 * Every document belongs to a warehouse, and that warehouse is the branch that
 * issued it, so the receipt header shows the warehouse's own name, address,
 * phone, email and logo instead of the head-office company settings. Each
 * field falls back to the company value on its own, which mirrors the server
 * side (App\Services\InvoiceIssuerService) so an online receipt and an offline
 * one print the same header.
 *
 * The module is opt-in (System Settings -> Features -> Warehouse Letterhead).
 * While it is off the server sends a plain company block (`is_branch: false`)
 * and the POS bootstrap ships `enable_warehouse_letterhead: false`, so both
 * helpers below return the settings untouched.
 */

const text = v => (v === null || v === undefined ? '' : String(v).trim());

/**
 * City / zip / country joined into the line printed under the street address.
 */
function localityLine(warehouse) {
  const near = [text(warehouse.zip), text(warehouse.city)].filter(Boolean).join(' ');
  return [near, text(warehouse.country)].filter(Boolean).join(', ');
}

/**
 * Build an issuer block from a warehouse row (as returned by the POS bootstrap)
 * plus the global company settings. Returns null when there is no warehouse,
 * which means "use the company block unchanged".
 */
export function issuerFromWarehouse(warehouse, setting = {}) {
  if (!warehouse) return null;

  const address = text(warehouse.address) || text(setting.CompanyAdress);
  const locality = localityLine(warehouse);

  return {
    name: text(warehouse.name) || text(setting.CompanyName),
    address,
    full_address: [address, locality].filter(Boolean).join(', '),
    phone: text(warehouse.mobile) || text(setting.CompanyPhone),
    email: text(warehouse.email) || text(setting.email),
    logo: text(warehouse.logo) || text(setting.logo),
    footer: text(warehouse.invoice_footer),
    warehouse_name: text(warehouse.name),
    is_branch: true,
  };
}

/**
 * Overlay an issuer block onto a settings object, so the receipt templates can
 * keep reading `setting.CompanyName` / `CompanyAdress` / `CompanyPhone` and
 * still print the issuing branch. Returns a new object; a null issuer returns
 * the settings untouched.
 */
export function applyIssuer(setting, issuer) {
  // No issuer, or the feature is off (the server then sends the plain company
  // block, flagged is_branch: false) — leave the settings alone.
  if (!issuer || issuer.is_branch === false) return setting || {};

  return {
    ...(setting || {}),
    CompanyName: issuer.name || (setting || {}).CompanyName,
    CompanyAdress: issuer.full_address || issuer.address || (setting || {}).CompanyAdress,
    CompanyPhone: issuer.phone || (setting || {}).CompanyPhone,
    email: issuer.email || (setting || {}).email,
    logo: issuer.logo || (setting || {}).logo,
    // The branch note: every POS receipt layout renders it below the customer
    // note via .receipt-branch-note. Empty string when the branch has none.
    issuer_footer: issuer.footer || '',
    // A branch note replaces the global invoice footer on the invoice pages.
    ...(issuer.footer
      ? { invoice_footer: issuer.footer, is_invoice_footer: 1 }
      : {}),
  };
}

/**
 * Remove everything applyIssuer() overlays, leaving the plain company settings.
 *
 * Needed wherever a settings object is REUSED as the base of a later merge (the
 * POS keeps one `invoice_pos.setting` for the whole session and re-merges the
 * bootstrap payload into it). The company fields are overwritten by that merge
 * anyway, but `issuer_footer` and the invoice-footer pair are not part of the
 * bootstrap payload, so without this the branch note of the last receipt would
 * survive — and get cached — onto the next one.
 */
export function stripIssuer(setting) {
  const { issuer_footer, invoice_footer, is_invoice_footer, ...rest } = setting || {};

  return rest;
}

/**
 * Convenience: resolve a warehouse id against a list of warehouse rows and
 * overlay the result onto the settings.
 */
export function applyWarehouseIssuer(setting, warehouses, warehouseId) {
  if (!setting || !setting.enable_warehouse_letterhead) return setting || {};
  if (!warehouseId || !Array.isArray(warehouses)) return setting || {};
  const match = warehouses.find(w => String(w.id) === String(warehouseId));

  return applyIssuer(setting, issuerFromWarehouse(match, setting));
}
