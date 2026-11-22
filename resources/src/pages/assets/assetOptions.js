import {
    Laptop, Wrench, Truck, Armchair, Building2, Cpu, Boxes, Package,
} from 'lucide-vue-next';
import { t, i18n } from '../../i18n';

/**
 * Shared vocabulary for Asset Management: the enum values the API stores, the
 * label each one shows and the antd tag colour it wears. Every asset page reads
 * from here, so a status can never look one way in the register and another in
 * a report.
 *
 * Each entry carries the i18n `key` plus its English `label` fallback. Lists
 * are translated through tr() inside a computed(), so they re-render when the
 * language changes; labelOf() translates a single value.
 */

export const ASSET_STATUSES = [
    { value: 'in_use', key: 'Asset_Status_In_Use', label: 'In use', color: 'success' },
    { value: 'maintenance', key: 'Rental_In_Maintenance', label: 'In maintenance', color: 'warning' },
    { value: 'retired', key: 'Retired', label: 'Retired', color: 'default' },
];

export const MAINTENANCE_TYPES = [
    { value: 'service', key: 'Service', label: 'Service', color: 'blue' },
    { value: 'repair', key: 'Rental_Mt_Repair', label: 'Repair', color: 'volcano' },
    { value: 'inspection', key: 'Rental_Mt_Inspection', label: 'Inspection', color: 'cyan' },
    { value: 'calibration', key: 'Asset_Mt_Calibration', label: 'Calibration', color: 'purple' },
    { value: 'upgrade', key: 'Asset_Mt_Upgrade', label: 'Upgrade', color: 'geekblue' },
    { value: 'other', key: 'Other', label: 'Other', color: 'default' },
];

export const MAINTENANCE_STATUSES = [
    { value: 'scheduled', key: 'Scheduled', label: 'Scheduled', color: 'processing' },
    { value: 'in_progress', key: 'Rental_Mt_In_Progress', label: 'In progress', color: 'warning' },
    { value: 'completed', key: 'Completed', label: 'Completed', color: 'success' },
    { value: 'cancelled', key: 'Cancelled', label: 'Cancelled', color: 'default' },
];

export const ASSIGNMENT_STATUSES = [
    { value: 'assigned', key: 'Out', label: 'Out', color: 'processing' },
    { value: 'returned', key: 'Returned', label: 'Returned', color: 'success' },
];

/**
 * Depreciation methods. "None" is the default and is deliberately first: most
 * rows in an existing register have no useful life set, and pretending they
 * depreciate would invent numbers nobody entered.
 */
export const DEPRECIATION_METHODS = [
    { value: 'none', key: 'None', label: 'None', hintKey: 'Asset_Dep_None_Hint', hint: 'Book value stays at cost' },
    { value: 'straight_line', key: 'Asset_Dep_Straight_Line', label: 'Straight line', hintKey: 'Asset_Dep_Straight_Line_Hint', hint: 'Equal charge every month of the life' },
    { value: 'declining_balance', key: 'Asset_Dep_Declining_Balance', label: 'Declining balance', hintKey: 'Asset_Dep_Declining_Balance_Hint', hint: 'Double rate on the remaining value — front-loaded' },
];

/**
 * Icons for the category chips. Categories are user-defined, so these are
 * matched on the name rather than an id — a soft touch that gives "Vehicles"
 * a truck instead of the same grey box as everything else.
 */
const CATEGORY_ICONS = [
    { match: /laptop|computer|pc|it|electronic/i, icon: Laptop },
    { match: /tool|equipment|machine/i, icon: Wrench },
    { match: /vehicle|car|truck|van/i, icon: Truck },
    { match: /furniture|chair|desk|office/i, icon: Armchair },
    { match: /building|property|premise|land/i, icon: Building2 },
    { match: /server|network|hardware/i, icon: Cpu },
    { match: /stock|material|supply/i, icon: Boxes },
];

export function categoryIcon(name) {
    const hit = CATEGORY_ICONS.find(c => c.match.test(name || ''));
    return hit ? hit.icon : Package;
}

/**
 * Translate with named placeholders, e.g. tp('Asset_N_Days_Late', '{n} days late', { n: 3 }).
 * Falls back to the English text (placeholders filled in) while the key is missing.
 */
export function tp(key, fallback, params = {}) {
    const out = i18n.global.t(key, params);
    if (out !== key) return out;
    return String(fallback).replace(/\{(\w+)\}/g, (m, k) => (params[k] ?? m));
}

/** Translated copy of an option list (call inside a computed). */
export function tr(list) {
    return list.map(o => ({
        ...o,
        label: o.key ? t(o.key, o.label) : o.label,
        ...(o.hintKey ? { hint: t(o.hintKey, o.hint) } : {}),
    }));
}

/** The full option object for a value, or a safe blank so templates never throw. */
export function optionOf(list, value) {
    return list.find(o => o.value === value) || { value, key: '', label: value || '—', color: 'default' };
}

/** One value's translated label, falling back to the raw value so nothing renders blank. */
export function labelOf(list, value) {
    const o = optionOf(list, value);
    return o.key ? t(o.key, o.label) : o.label;
}

/** Colour a countdown: red once it is late, amber when it is close. */
export function dueColor(days) {
    if (days === null || days === undefined) return 'default';
    if (days < 0) return 'error';
    if (days <= 7) return 'warning';
    if (days <= 30) return 'processing';
    return 'success';
}

/** "3 days late" / "in 12 days" / "today" — the countdown as a sentence. */
export function dueLabel(days) {
    if (days === null || days === undefined) return '—';
    if (days < 0) return lateLabel(Math.abs(days));
    if (days === 0) return t('Today', 'Today');
    return days === 1
        ? t('Asset_In_One_Day', 'in 1 day')
        : tp('Asset_In_N_Days', 'in {n} days', { n: days });
}

/** "1 day late" / "3 days late". */
export function lateLabel(days) {
    return days === 1
        ? t('Asset_One_Day_Late', '1 day late')
        : tp('Asset_N_Days_Late', '{n} days late', { n: days });
}
