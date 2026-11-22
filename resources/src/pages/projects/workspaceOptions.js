/**
 * Shared vocabulary for the Projects Management workspace.
 *
 * Project and task statuses are the values the EXISTING projects/tasks tables
 * already store — they are re-declared here (not invented) so the new pages
 * label them identically to the old ones.
 */
import { i18n } from '../../i18n';

const tr = (k, p) => i18n.global.t(k, p || {});

export const PROJECT_STATUSES = [
    { value: 'not_started', get label() { return tr('Pm_Not_Started'); }, color: 'default' },
    { value: 'progress', get label() { return tr('Pm_In_Progress'); }, color: 'processing' },
    { value: 'hold', get label() { return tr('Pm_On_Hold'); }, color: 'warning' },
    { value: 'completed', get label() { return tr('Pm_Completed'); }, color: 'success' },
    { value: 'cancelled', get label() { return tr('Pm_Cancelled'); }, color: 'error' },
];

export const TASK_STATUSES = [
    { value: 'not_started', get label() { return tr('Pm_Not_Started'); }, color: 'default' },
    { value: 'progress', get label() { return tr('Pm_In_Progress'); }, color: 'processing' },
    { value: 'completed', get label() { return tr('Pm_Completed'); }, color: 'success' },
    { value: 'cancelled', get label() { return tr('Pm_Cancelled'); }, color: 'error' },
];

export const TASK_PRIORITIES = [
    { value: 'low', get label() { return tr('Pm_Low'); }, color: 'default' },
    { value: 'medium', get label() { return tr('Pm_Medium'); }, color: 'processing' },
    { value: 'high', get label() { return tr('Pm_High'); }, color: 'warning' },
    { value: 'urgent', get label() { return tr('Pm_Urgent'); }, color: 'error' },
];

export const MILESTONE_STATUSES = [
    { value: 'pending', get label() { return tr('Pm_Pending'); }, color: 'default' },
    { value: 'in_progress', get label() { return tr('Pm_In_Progress'); }, color: 'processing' },
    { value: 'completed', get label() { return tr('Pm_Completed'); }, color: 'success' },
    { value: 'delayed', get label() { return tr('Pm_Delayed'); }, color: 'error' },
];

/** Board column order — left to right is the natural flow of work. */
export const BOARD_COLUMNS = [
    { key: 'not_started', get label() { return tr('Pm_Not_Started'); }, color: '#8c8c8c' },
    { key: 'progress', get label() { return tr('Pm_In_Progress'); }, color: '#1677ff' },
    { key: 'completed', get label() { return tr('Pm_Completed'); }, color: '#16a34a' },
    { key: 'cancelled', get label() { return tr('Pm_Cancelled'); }, color: '#ff4d4f' },
];

export function optionOf(list, value) {
    return list.find(o => o.value === value) || { value, label: value || '—', color: 'default' };
}

export function labelOf(list, value) {
    return optionOf(list, value).label;
}

/** Progress bar colour: green when done, amber mid-way, grey at the start. */
export function progressColor(percent) {
    if (percent >= 100) return '#16a34a';
    if (percent >= 50) return '#6d28d9';
    if (percent > 0) return '#faad14';
    return '#d9d9d9';
}

/** "in 3 days" / "5 days late" — null when there is no deadline to speak of. */
export function dueLabel(days) {
    if (days === null || days === undefined) return null;
    if (days < 0) return { text: tr('Pm_N_D_Late', { n: Math.abs(days) }), color: 'error' };
    if (days === 0) return { text: tr('Pm_Due_Today'), color: 'warning' };
    if (days <= 7) return { text: tr('Pm_In_N_D', { n: days }), color: 'warning' };
    return { text: tr('Pm_In_N_D', { n: days }), color: 'default' };
}
