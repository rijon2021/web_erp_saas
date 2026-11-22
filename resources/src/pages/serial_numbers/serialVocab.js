/** Serial-number status vocabulary — backend enums, i18n keys `Status_{value}`. */

export const SERIAL_STATUSES = [
    'available', 'sold', 'returned_customer', 'returned_supplier', 'damaged', 'reserved',
    // Set by adjustments: assembled into a kit (combo) / written off.
    'in_kit', 'written_off',
];

export function serialStatusColor(status) {
    const map = {
        available: 'success',
        sold: 'processing',
        returned_customer: 'warning',
        returned_supplier: 'warning',
        damaged: 'error',
        reserved: 'default',
        in_kit: 'purple',
        written_off: 'error',
    };
    return map[status] || 'default';
}
