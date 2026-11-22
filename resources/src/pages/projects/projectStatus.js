/**
 * Project status vocabulary — values and English labels are hardcoded in the
 * legacy pages too (no i18n keys). NOTE the short values: `progress`, `hold`.
 */
import { i18n } from '../../i18n';

const tr = (k, p) => i18n.global.t(k, p || {});

export const PROJECT_STATUSES = [
    { value: 'not_started', get label() { return tr('Not_Started'); } },
    { value: 'progress', get label() { return tr('In_Progress'); } },
    { value: 'hold', get label() { return tr('On_Hold'); } },
    { value: 'completed', get label() { return tr('Completed'); } },
    { value: 'cancelled', get label() { return tr('Cancelled'); } },
];

export function projectStatusLabel(v) {
    return PROJECT_STATUSES.find(s => s.value === v)?.label || v;
}

export function projectStatusColor(v) {
    switch (v) {
        case 'progress': return 'processing';
        case 'completed': return 'success';
        case 'hold': return 'warning';
        case 'cancelled': return 'error';
        default: return 'default';
    }
}
