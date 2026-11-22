import { Building2, Hammer, Car, Package } from 'lucide-vue-next';
import { t } from '../../i18n';

/**
 * Shared vocabulary for the rental module: the enum values the API stores, the
 * i18n key + English fallback each one shows and the antd tag colour it wears.
 * Every rental page reads these, so a status can never look one way in the list
 * and another on the dashboard.
 *
 * Lists are translated through tr() inside a computed(), so they re-render
 * when the language changes.
 */

export const RENTAL_TYPES = [
    { value: 'property', key: 'Rental_Type_Property', label: 'Property', icon: Building2 },
    { value: 'equipment', key: 'Rental_Type_Equipment', label: 'Equipment', icon: Hammer },
    { value: 'vehicle', key: 'Rental_Type_Vehicle', label: 'Vehicle', icon: Car },
    { value: 'other', key: 'Rental_Type_Other', label: 'Other', icon: Package },
];

export const RENTAL_STATUSES = [
    { value: 'available', key: 'Rental_Status_Available', label: 'Available', color: 'success' },
    { value: 'reserved', key: 'Rental_Status_Reserved', label: 'Reserved', color: 'processing' },
    { value: 'rented', key: 'Rental_Status_Rented', label: 'Rented', color: 'purple' },
    { value: 'maintenance', key: 'Rental_Status_Maintenance', label: 'Maintenance', color: 'warning' },
    { value: 'inactive', key: 'Rental_Status_Inactive', label: 'Inactive', color: 'default' },
];

export const RENTAL_CONDITIONS = [
    { value: 'new', key: 'Rental_Condition_New', label: 'New', color: 'cyan' },
    { value: 'excellent', key: 'Rental_Condition_Excellent', label: 'Excellent', color: 'green' },
    { value: 'good', key: 'Rental_Condition_Good', label: 'Good', color: 'blue' },
    { value: 'fair', key: 'Rental_Condition_Fair', label: 'Fair', color: 'gold' },
    { value: 'poor', key: 'Rental_Condition_Poor', label: 'Poor', color: 'orange' },
    { value: 'damaged', key: 'Rental_Condition_Damaged', label: 'Damaged', color: 'red' },
];

/** `rate` is the API's rate column; `per` is the short suffix after a price. */
export const RENTAL_PERIODS = [
    { value: 'hour', key: 'Rental_Period_Hourly', label: 'Hourly', rate: 'hourly_rate', perKey: 'Rental_Per_Hour', per: '/ hour', unitKey: 'Rental_Unit_Hours', unit: 'hours' },
    { value: 'day', key: 'Rental_Period_Daily', label: 'Daily', rate: 'daily_rate', perKey: 'Rental_Per_Day', per: '/ day', unitKey: 'Rental_Unit_Days', unit: 'days' },
    { value: 'week', key: 'Rental_Period_Weekly', label: 'Weekly', rate: 'weekly_rate', perKey: 'Rental_Per_Week', per: '/ week', unitKey: 'Rental_Unit_Weeks', unit: 'weeks' },
    { value: 'month', key: 'Rental_Period_Monthly', label: 'Monthly', rate: 'monthly_rate', perKey: 'Rental_Per_Month', per: '/ month', unitKey: 'Rental_Unit_Months', unit: 'months' },
    { value: 'year', key: 'Rental_Period_Yearly', label: 'Yearly', rate: 'yearly_rate', perKey: 'Rental_Per_Year', per: '/ year', unitKey: 'Rental_Unit_Years', unit: 'years' },
];

export const ID_TYPES = [
    { value: 'national_id', key: 'Rental_ID_National', label: 'National ID' },
    { value: 'passport', key: 'Rental_ID_Passport', label: 'Passport' },
    { value: 'driving_license', key: 'Rental_ID_Driving_License', label: 'Driving license' },
    { value: 'residence_permit', key: 'Rental_ID_Residence_Permit', label: 'Residence permit' },
    { value: 'other', key: 'Rental_ID_Other', label: 'Other document' },
];

/** Computed by the API from the expiry date and the "expiring soon" window. */
export const ID_STATUSES = [
    { value: 'valid', key: 'Rental_ID_Valid', label: 'ID valid', color: 'success' },
    { value: 'expiring', key: 'Rental_ID_Expiring', label: 'ID expiring', color: 'warning' },
    { value: 'expired', key: 'Rental_ID_Expired', label: 'ID expired', color: 'error' },
    { value: 'missing', key: 'Rental_ID_Missing', label: 'No ID on file', color: 'default' },
];

/**
 * A contract's badge: its stored status, refined by dates while active
 * (upcoming / expiring / overdue) — computed by the API as `stage`.
 */
export const CONTRACT_STAGES = [
    { value: 'draft', key: 'Rental_Stage_Draft', label: 'Draft', color: 'default' },
    { value: 'upcoming', key: 'Rental_Stage_Upcoming', label: 'Upcoming', color: 'processing' },
    { value: 'active', key: 'Rental_Stage_Active', label: 'Active', color: 'success' },
    { value: 'expiring', key: 'Rental_Stage_Expiring', label: 'Expiring soon', color: 'warning' },
    { value: 'overdue', key: 'Rental_Stage_Overdue', label: 'Overdue', color: 'error' },
    { value: 'ended', key: 'Rental_Stage_Ended', label: 'Ended', color: 'cyan' },
    { value: 'terminated', key: 'Rental_Stage_Terminated', label: 'Terminated', color: 'volcano' },
    { value: 'renewed', key: 'Rental_Stage_Renewed', label: 'Renewed', color: 'purple' },
    { value: 'cancelled', key: 'Rental_Stage_Cancelled', label: 'Cancelled', color: 'default' },
];

/** Reservation (hold) badge — `stage` from the API. */
export const RESERVATION_STAGES = [
    { value: 'held', key: 'Rental_Hold_Held', label: 'Held', color: 'processing' },
    { value: 'expiring', key: 'Rental_Hold_Expiring', label: 'Hold ending', color: 'warning' },
    { value: 'converted', key: 'Rental_Hold_Converted', label: 'Converted', color: 'success' },
    { value: 'expired', key: 'Rental_Hold_Expired', label: 'Expired', color: 'default' },
    { value: 'cancelled', key: 'Rental_Hold_Cancelled', label: 'Cancelled', color: 'default' },
];

/** Invoice badge: stored status, with "overdue" layered on by the API flag. */
export const INVOICE_STATUSES = [
    { value: 'unpaid', key: 'Rental_Inv_Unpaid', label: 'Unpaid', color: 'default' },
    { value: 'partial', key: 'Rental_Inv_Partial', label: 'Partly paid', color: 'warning' },
    { value: 'paid', key: 'Rental_Inv_Paid', label: 'Paid', color: 'success' },
    { value: 'void', key: 'Rental_Inv_Void', label: 'Void', color: 'default' },
    { value: 'overdue', key: 'Rental_Inv_Overdue', label: 'Overdue', color: 'error' },
];

/** The status to show for an invoice row (overdue wins over unpaid/partial). */
export function invoiceBadge(inv) {
    return inv.overdue ? 'overdue' : inv.status;
}

export const MAINT_STATUSES = [
    { value: 'open', key: 'Rental_Mt_Open', label: 'Open', color: 'default' },
    { value: 'scheduled', key: 'Rental_Mt_Scheduled', label: 'Scheduled', color: 'processing' },
    { value: 'in_progress', key: 'Rental_Mt_In_Progress', label: 'In progress', color: 'warning' },
    { value: 'completed', key: 'Rental_Mt_Completed', label: 'Completed', color: 'success' },
    { value: 'cancelled', key: 'Rental_Mt_Cancelled', label: 'Cancelled', color: 'default' },
];

export const MAINT_PRIORITIES = [
    { value: 'low', key: 'Rental_Pr_Low', label: 'Low', color: 'default', hex: '#94a3b8' },
    { value: 'normal', key: 'Rental_Pr_Normal', label: 'Normal', color: 'blue', hex: '#0284c7' },
    { value: 'high', key: 'Rental_Pr_High', label: 'High', color: 'orange', hex: '#ea580c' },
    { value: 'urgent', key: 'Rental_Pr_Urgent', label: 'Urgent', color: 'red', hex: '#dc2626' },
];

export const MAINT_TYPES = [
    { value: 'repair', key: 'Rental_Mt_Repair', label: 'Repair' },
    { value: 'service', key: 'Rental_Mt_Service', label: 'Service' },
    { value: 'inspection', key: 'Rental_Mt_Inspection', label: 'Inspection' },
    { value: 'cleaning', key: 'Rental_Mt_Cleaning', label: 'Cleaning' },
    { value: 'other', key: 'Rental_Mt_Other', label: 'Other' },
];

export const EXPENSE_CATEGORIES = [
    { value: 'maintenance', key: 'Rental_Ex_Maintenance', label: 'Maintenance', color: 'orange' },
    { value: 'repair', key: 'Rental_Ex_Repair', label: 'Repair', color: 'volcano' },
    { value: 'utilities', key: 'Rental_Ex_Utilities', label: 'Utilities', color: 'cyan' },
    { value: 'insurance', key: 'Rental_Ex_Insurance', label: 'Insurance', color: 'geekblue' },
    { value: 'tax', key: 'Rental_Ex_Tax', label: 'Tax', color: 'purple' },
    { value: 'cleaning', key: 'Rental_Ex_Cleaning', label: 'Cleaning', color: 'green' },
    { value: 'fuel', key: 'Rental_Ex_Fuel', label: 'Fuel', color: 'gold' },
    { value: 'fees', key: 'Rental_Ex_Fees', label: 'Fees', color: 'magenta' },
    { value: 'other', key: 'Rental_Ex_Other', label: 'Other', color: 'default' },
];

export const BILLING_TYPES = [
    { value: 'recurring', key: 'Rental_Billing_Recurring', label: 'Billed every period' },
    { value: 'upfront', key: 'Rental_Billing_Upfront', label: 'Paid upfront' },
];

/** Contract history entries. */
export const CONTRACT_EVENTS = {
    created: { key: 'Rental_Event_Created', label: 'Contract created', color: 'gray' },
    updated: { key: 'Rental_Event_Updated', label: 'Contract edited', color: 'gray' },
    activated: { key: 'Rental_Event_Activated', label: 'Activated', color: 'green' },
    extended: { key: 'Rental_Event_Extended', label: 'Extended', color: 'blue' },
    renewal_created: { key: 'Rental_Event_Renewal_Created', label: 'Renewal created', color: 'purple' },
    renewed: { key: 'Rental_Event_Renewed', label: 'Closed as renewed', color: 'purple' },
    terminated: { key: 'Rental_Event_Terminated', label: 'Terminated', color: 'red' },
    ended: { key: 'Rental_Event_Ended', label: 'Ended', color: 'green' },
    cancelled: { key: 'Rental_Event_Cancelled', label: 'Cancelled', color: 'gray' },
    signed: { key: 'Rental_Event_Signed', label: 'Signed', color: 'green' },
    signature_removed: { key: 'Rental_Event_Signature_Removed', label: 'Signature removed', color: 'orange' },
    attachment_added: { key: 'Rental_Event_Attachment_Added', label: 'Attachment added', color: 'blue' },
    attachment_removed: { key: 'Rental_Event_Attachment_Removed', label: 'Attachment removed', color: 'orange' },
    payment: { key: 'Rental_Event_Payment', label: 'Payment received', color: 'green' },
    payment_deleted: { key: 'Rental_Event_Payment_Deleted', label: 'Payment deleted', color: 'red' },
    charge_added: { key: 'Rental_Event_Charge_Added', label: 'Charge added', color: 'blue' },
    invoice_voided: { key: 'Rental_Event_Invoice_Voided', label: 'Invoice voided', color: 'orange' },
    checked_out: { key: 'Rental_Event_Checked_Out', label: 'Items handed over', color: 'purple' },
    checked_in: { key: 'Rental_Event_Checked_In', label: 'Items returned', color: 'cyan' },
    deposit_collected: { key: 'Rental_Event_Deposit_Collected', label: 'Deposit collected', color: 'green' },
    deposit_refunded: { key: 'Rental_Event_Deposit_Refunded', label: 'Deposit refunded', color: 'blue' },
    deposit_applied: { key: 'Rental_Event_Deposit_Applied', label: 'Deposit used for invoices', color: 'purple' },
    deposit_retained: { key: 'Rental_Event_Deposit_Retained', label: 'Deposit kept', color: 'orange' },
    deposit_reversed: { key: 'Rental_Event_Deposit_Reversed', label: 'Deposit entry undone', color: 'gray' },
    portal_request: { key: 'Rental_Event_Portal_Request', label: 'Problem reported by the customer', color: 'orange' },
};

/** Initials for an avatar: "Sara El Amrani" -> "SE". */
export function initials(name) {
    const parts = String(name || '').trim().split(/\s+/).filter(Boolean);
    if (!parts.length) return '?';
    return (parts[0][0] + (parts.length > 1 ? parts[parts.length - 1][0] : '')).toUpperCase();
}

/** A stable colour per name, so a customer keeps the same avatar everywhere. */
const AVATAR_COLORS = ['#7c3aed', '#0891b2', '#16a34a', '#d97706', '#db2777', '#4f46e5', '#0d9488', '#dc2626'];
export function avatarColor(name) {
    let h = 0;
    for (const ch of String(name || '')) h = (h * 31 + ch.charCodeAt(0)) >>> 0;
    return AVATAR_COLORS[h % AVATAR_COLORS.length];
}

/**
 * Suggested amenities for property items. The English text is what gets
 * stored (amenities is a free-tag list); only the shown label is translated.
 */
export const AMENITIES = [
    { value: 'Parking', key: 'Rental_Amenity_Parking', label: 'Parking' },
    { value: 'Air conditioning', key: 'Rental_Amenity_Air_Conditioning', label: 'Air conditioning' },
    { value: 'Heating', key: 'Rental_Amenity_Heating', label: 'Heating' },
    { value: 'Balcony', key: 'Rental_Amenity_Balcony', label: 'Balcony' },
    { value: 'Elevator', key: 'Rental_Amenity_Elevator', label: 'Elevator' },
    { value: 'Wi-Fi', key: 'Rental_Amenity_Wifi', label: 'Wi-Fi' },
    { value: 'Security', key: 'Security', label: 'Security' },
    { value: 'Garden', key: 'Rental_Amenity_Garden', label: 'Garden' },
    { value: 'Swimming pool', key: 'Rental_Amenity_Swimming_Pool', label: 'Swimming pool' },
    { value: 'Storage', key: 'Rental_Amenity_Storage', label: 'Storage' },
    { value: 'Kitchen', key: 'Kitchen', label: 'Kitchen' },
    { value: 'Laundry', key: 'Rental_Amenity_Laundry', label: 'Laundry' },
];

/** Translated amenity label; custom (typed) amenities show as entered. */
export function amenityLabel(a) {
    const o = AMENITIES.find(x => x.value === a);
    return o ? t(o.key, o.label) : a;
}

/** Translated copy of an option list (call inside a computed). */
export function tr(list) {
    return list.map(o => ({ ...o, label: t(o.key, o.label) }));
}

/** Look one up, falling back to the raw value so nothing renders blank. */
export function optionOf(list, value) {
    return list.find(o => o.value === value) || { value, key: '', label: value || '—', color: 'default' };
}

export function labelOf(list, value) {
    const o = optionOf(list, value);
    return o.key ? t(o.key, o.label) : o.label;
}

/** The glyph for an item's type, falling back to the generic package. */
export function typeIcon(type) {
    return (RENTAL_TYPES.find(o => o.value === type) || RENTAL_TYPES[3]).icon;
}

/** "/ day", "/ month"… translated. */
export function perLabel(period) {
    const p = RENTAL_PERIODS.find(o => o.value === period);
    return p ? t(p.perKey, p.per) : '';
}

/** "days", "months"… translated. */
export function unitLabel(period) {
    const p = RENTAL_PERIODS.find(o => o.value === period);
    return p ? t(p.unitKey, p.unit) : '';
}
