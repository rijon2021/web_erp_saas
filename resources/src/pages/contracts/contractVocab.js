/**
 * Contract vocabularies — values and English labels hardcoded in legacy too.
 * Party types: customer | employee.
 */
import { i18n } from '../../i18n';

const tr = (k) => i18n.global.t(k);

export const CONTRACT_TYPES = [
    { value: 'service', get label() { return tr('Service'); } },
    { value: 'lease', get label() { return tr('Ct_Lease'); } },
    { value: 'sales', get label() { return tr('Sales'); } },
    { value: 'nda', get label() { return tr('Ct_NDA'); } },
    { value: 'employment', get label() { return tr('Ct_Employment'); } },
    { value: 'other', get label() { return tr('Other'); } },
];

export const CONTRACT_STATUSES = [
    { value: 'draft', get label() { return tr('Draft'); } },
    { value: 'active', get label() { return tr('Active'); } },
    { value: 'expired', get label() { return tr('Ct_Expired'); } },
    { value: 'cancelled', get label() { return tr('Cancelled'); } },
];

export const contractTypeLabel = v => CONTRACT_TYPES.find(x => x.value === v)?.label || v;
export const contractStatusLabel = v => CONTRACT_STATUSES.find(x => x.value === v)?.label || v;

export function contractStatusColor(v) {
    switch (v) {
        case 'active': return 'success';
        case 'draft': return 'default';
        case 'expired': return 'warning';
        case 'cancelled': return 'error';
        default: return 'default';
    }
}
