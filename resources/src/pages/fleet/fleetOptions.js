import { Car, Truck, Bus, Bike, Forklift, Caravan, Container, Package } from 'lucide-vue-next';
import { i18n } from '../../i18n';

const tr = (k, p) => i18n.global.t(k, p || {});

/**
 * Shared vocabulary for the fleet module: the enum values the API stores, the
 * label each one shows and the antd tag colour it wears. Every fleet page reads
 * these, so a status can never look one way in the list and another in a report.
 *
 * Type icons come from lucide (the sidebar's icon set) rather than antd, which
 * ships a car and nothing else — a fleet where a bus, a forklift and a trailer
 * all render as the same car glyph tells the user nothing.
 */

export const VEHICLE_TYPES = [
    { value: 'car', get label() { return tr('Fl_Car'); }, icon: Car },
    { value: 'van', get label() { return tr('Fl_Van'); }, icon: Caravan },
    { value: 'truck', get label() { return tr('Fl_Truck'); }, icon: Truck },
    { value: 'bus', get label() { return tr('Fl_Bus'); }, icon: Bus },
    { value: 'motorcycle', get label() { return tr('Fl_Motorcycle'); }, icon: Bike },
    { value: 'forklift', get label() { return tr('Fl_Forklift'); }, icon: Forklift },
    { value: 'trailer', get label() { return tr('Fl_Trailer'); }, icon: Container },
    { value: 'other', get label() { return tr('Fl_Other'); }, icon: Package },
];

/** The glyph for a vehicle's type, falling back to the generic car. */
export function typeIcon(type) {
    return (VEHICLE_TYPES.find(t => t.value === type) || VEHICLE_TYPES[0]).icon;
}

export const VEHICLE_STATUSES = [
    { value: 'active', get label() { return tr('Fl_Active'); }, color: 'success' },
    { value: 'maintenance', get label() { return tr('Fl_In_Maintenance'); }, color: 'warning' },
    { value: 'inactive', get label() { return tr('Fl_Inactive'); }, color: 'default' },
    { value: 'sold', get label() { return tr('Fl_Sold'); }, color: 'error' },
];

export const FUEL_TYPES = [
    { value: 'petrol', get label() { return tr('Fl_Petrol'); } },
    { value: 'diesel', get label() { return tr('Fl_Diesel'); } },
    { value: 'electric', get label() { return tr('Fl_Electric'); } },
    { value: 'hybrid', get label() { return tr('Fl_Hybrid'); } },
    { value: 'lpg', get label() { return tr('Fl_LPG'); } },
    { value: 'cng', get label() { return tr('Fl_CNG'); } },
];

export const MAINTENANCE_TYPES = [
    { value: 'service', get label() { return tr('Fl_Service'); }, color: 'blue' },
    { value: 'repair', get label() { return tr('Fl_Repair'); }, color: 'volcano' },
    { value: 'tyres', get label() { return tr('Fl_Tyres'); }, color: 'purple' },
    { value: 'inspection', get label() { return tr('Fl_Inspection'); }, color: 'cyan' },
    { value: 'insurance', get label() { return tr('Fl_Insurance'); }, color: 'geekblue' },
    { value: 'other', get label() { return tr('Fl_Other'); }, color: 'default' },
];

export const MAINTENANCE_STATUSES = [
    { value: 'scheduled', get label() { return tr('Fl_Scheduled'); }, color: 'processing' },
    { value: 'in_progress', get label() { return tr('Fl_In_Progress'); }, color: 'warning' },
    { value: 'completed', get label() { return tr('Fl_Completed'); }, color: 'success' },
];

export const ASSIGNMENT_STATUSES = [
    { value: 'active', get label() { return tr('Fl_Out'); }, color: 'processing' },
    { value: 'completed', get label() { return tr('Fl_Returned'); }, color: 'success' },
    { value: 'cancelled', get label() { return tr('Fl_Cancelled'); }, color: 'default' },
];

/** Look one up, falling back to the raw value so nothing renders blank. */
export function optionOf(list, value) {
    return list.find(o => o.value === value) || { value, label: value || '—', color: 'default' };
}

export function labelOf(list, value) {
    return optionOf(list, value).label;
}

/**
 * How urgent a renewal is. `days` is negative once the date has passed.
 * Returns null when there is nothing to warn about, so callers can `v-if` it.
 */
export function expiryTone(days, soonWithin = 30) {
    if (days === null || days === undefined) return null;
    if (days < 0) return { color: 'error', text: tr('Fl_Overdue_By_N_D', { n: Math.abs(days) }), level: 'overdue' };
    if (days === 0) return { color: 'error', text: tr('Fl_Due_Today'), level: 'overdue' };
    if (days <= soonWithin) return { color: 'warning', text: tr('Fl_N_D_Left', { n: days }), level: 'soon' };
    return null;
}
