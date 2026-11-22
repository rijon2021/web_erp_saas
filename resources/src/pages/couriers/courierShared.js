/**
 * Shared bits for the courier pages: the courier lifecycle vocabulary
 * (App\Services\Couriers\CourierStatus) and API error → message.
 */

export const COURIER_STATUSES = [
    { value: 'booked', key: 'Cr_Status_Booked', color: 'processing' },
    { value: 'picked_up', key: 'Cr_Status_Picked_Up', color: 'blue' },
    { value: 'in_transit', key: 'Cr_Status_In_Transit', color: 'blue' },
    { value: 'out_for_delivery', key: 'Cr_Status_Out_For_Delivery', color: 'cyan' },
    { value: 'failed_attempt', key: 'Cr_Status_Failed_Attempt', color: 'warning' },
    { value: 'delivered', key: 'Cr_Status_Delivered', color: 'success' },
    { value: 'returning', key: 'Cr_Status_Returning', color: 'orange' },
    { value: 'returned', key: 'Cr_Status_Returned', color: 'volcano' },
    { value: 'cancelled', key: 'Cr_Status_Cancelled', color: 'default' },
];

export function courierStatus(value) {
    return COURIER_STATUSES.find(s => s.value === value) || null;
}

/** First validation message, else the server message, else the fallback. */
export function errorText(e, fallback) {
    const d = e && e.data;
    if (d && d.errors) {
        const first = Object.values(d.errors)[0];
        if (Array.isArray(first) && first.length) return first[0];
    }
    if (d && d.message) return d.message;
    return fallback;
}
