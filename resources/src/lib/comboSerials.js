/**
 * Serial rules for combo lines, shared by the sale form, POS and sale return.
 * A combo line carries serial_components = [{product_id, name, code, per}]
 * (its serial-tracked components) and serial_groups = {product_id: [serial]};
 * each component needs per × the line's base units (quantity × pack × unit,
 * see lib/serialUnits) — the same count the backend's
 * SerialNumberService::assertComboCountsMatch enforces.
 */
import { lineSerialUnits } from './serialUnits';

/** @param units the combo line's base units — lineSerialUnits(line). */
export function comboSerialRequired(component, units) {
    return Math.round((Number(component.per) || 1) * (Number(units) || 0));
}

export function hasComboSerials(line) {
    return Array.isArray(line?.serial_components) && line.serial_components.length > 0;
}

/** True when any tracked component has the wrong number of serials. */
export function comboSerialMismatch(line) {
    if (!hasComboSerials(line)) return false;
    const groups = line.serial_groups || {};
    const units = lineSerialUnits(line);
    return line.serial_components.some(c =>
        (groups[c.product_id] || []).length !== comboSerialRequired(c, units));
}

/**
 * Store one component's selection and re-flatten the line's serial_numbers /
 * imei_number, which is what gets submitted.
 */
export function setComboSerialGroup(line, productId, serials) {
    line.serial_groups = { ...(line.serial_groups || {}), [productId]: serials };
    const flat = line.serial_components.flatMap(c => line.serial_groups[c.product_id] || []);
    line.serial_numbers = flat;
    line.imei_number = flat.join(',');
}
