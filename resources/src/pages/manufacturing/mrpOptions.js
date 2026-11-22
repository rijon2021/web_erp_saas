import {
    FileStack, Factory, Hammer, ShieldCheck, Calculator, Cog,
} from 'lucide-vue-next';
import { i18n } from '../../i18n';

const tr = (k) => i18n.global.t(k);

/**
 * Shared vocabulary for the manufacturing module: the values the API stores,
 * the label each shows and the antd colour it wears. Every MRP page reads from
 * here, so an order cannot look "released" on one screen and "issued" on another.
 */

/**
 * The order lifecycle, in order. `step` drives the progress indicator, so it is
 * the single place that knows draft comes before planned.
 */
export const ORDER_STATUSES = [
    { value: 'draft', get label() { return tr('Mrp_Status_Draft'); }, color: 'default', step: 0 },
    { value: 'planned', get label() { return tr('Mrp_Status_Planned'); }, color: 'blue', step: 1 },
    { value: 'released', get label() { return tr('Mrp_Status_Released'); }, color: 'processing', step: 2 },
    { value: 'in_progress', get label() { return tr('Mrp_Status_In_Progress'); }, color: 'warning', step: 3 },
    { value: 'completed', get label() { return tr('Mrp_Status_Completed'); }, color: 'success', step: 4 },
    { value: 'cancelled', get label() { return tr('Mrp_Status_Cancelled'); }, color: 'error', step: -1 },
];

export const OPEN_ORDER_STATUSES = ['draft', 'planned', 'released', 'in_progress'];

export const PRIORITIES = [
    { value: 'low', get label() { return tr('Mrp_Priority_Low'); }, color: 'default' },
    { value: 'normal', get label() { return tr('Mrp_Priority_Normal'); }, color: 'blue' },
    { value: 'high', get label() { return tr('Mrp_Priority_High'); }, color: 'warning' },
    { value: 'urgent', get label() { return tr('Mrp_Priority_Urgent'); }, color: 'error' },
];

export const BOM_STATUSES = [
    { value: 'draft', get label() { return tr('Mrp_Status_Draft'); }, color: 'default' },
    { value: 'active', get label() { return tr('Mrp_Active'); }, color: 'success' },
    { value: 'archived', get label() { return tr('Mrp_Archived'); }, color: 'default' },
];

export const WORK_ORDER_STATUSES = [
    { value: 'pending', get label() { return tr('Mrp_Wo_Waiting'); }, color: 'default' },
    { value: 'in_progress', get label() { return tr('Mrp_Wo_Running'); }, color: 'processing' },
    { value: 'completed', get label() { return tr('Mrp_Wo_Done'); }, color: 'success' },
    { value: 'skipped', get label() { return tr('Mrp_Wo_Skipped'); }, color: 'warning' },
];

export const QC_STATUSES = [
    { value: 'pending', get label() { return tr('Mrp_Qc_Pending'); }, color: 'default' },
    { value: 'passed', get label() { return tr('Mrp_Qc_Passed'); }, color: 'success' },
    { value: 'partial', get label() { return tr('Mrp_Qc_Partial'); }, color: 'warning' },
    { value: 'failed', get label() { return tr('Mrp_Qc_Failed'); }, color: 'error' },
];

export const QC_TYPES = [
    { value: 'in_process', get label() { return tr('Mrp_Qc_In_Process'); } },
    { value: 'final', get label() { return tr('Mrp_Qc_Final'); } },
];

export const SUGGESTION_ACTIONS = [
    { value: 'make', get label() { return tr('Mrp_Make'); }, color: 'purple' },
    { value: 'buy', get label() { return tr('Mrp_Buy'); }, color: 'blue' },
];

export const SUGGESTION_STATUSES = [
    { value: 'pending', get label() { return tr('Mrp_Qc_Pending'); }, color: 'processing' },
    { value: 'accepted', get label() { return tr('Mrp_Accepted'); }, color: 'success' },
    { value: 'dismissed', get label() { return tr('Mrp_Dismissed'); }, color: 'default' },
];

/** Section icons, so each page reads as itself in the sidebar and headers. */
export const SECTION_ICONS = {
    boms: FileStack,
    production: Factory,
    workOrders: Hammer,
    quality: ShieldCheck,
    planning: Calculator,
    workCenters: Cog,
};

export function optionOf(list, value) {
    return list.find(o => o.value === value) || { value, label: value || '—', color: 'default' };
}

export function labelOf(list, value) {
    return optionOf(list, value).label;
}

/** Which actions an order allows, given where it is in its life. */
export function canRelease(order) {
    return ['draft', 'planned'].includes(order?.status);
}

export function canComplete(order) {
    return ['released', 'in_progress'].includes(order?.status);
}

export function canCancel(order) {
    return OPEN_ORDER_STATUSES.includes(order?.status);
}

export function canEdit(order) {
    return OPEN_ORDER_STATUSES.includes(order?.status);
}

/**
 * Colour a variance. Under budget is good, a little over is a warning, and
 * badly over is a problem — the same scale whether it is money or minutes.
 */
export function varianceColor(pct) {
    if (pct === null || pct === undefined) return 'default';
    if (pct <= 0) return 'success';
    if (pct <= 10) return 'warning';
    return 'error';
}

/** Efficiency reads the other way round: over 100% means beating the standard. */
export function efficiencyColor(pct) {
    if (pct === null || pct === undefined) return 'default';
    if (pct >= 95) return 'success';
    if (pct >= 80) return 'warning';
    return 'error';
}

export function passRateColor(pct) {
    if (pct === null || pct === undefined) return 'default';
    if (pct >= 98) return 'success';
    if (pct >= 90) return 'warning';
    return 'error';
}

/** "+12.5%" / "−4%" — a variance as a signed string. */
export function signedPct(pct) {
    if (pct === null || pct === undefined) return '—';
    const sign = pct > 0 ? '+' : '';
    return `${sign}${pct}%`;
}
