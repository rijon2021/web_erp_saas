/**
 * HRM shared vocabulary. Employment types and leave statuses are backend
 * values; labels are translated lazily (getters) so they follow the active
 * locale.
 */
import { i18n } from '../../i18n';

const tr = (k) => i18n.global.t(k);

export const EMPLOYMENT_TYPES = [
    { value: 'full_time', get label() { return tr('Hrm_Full_Time'); } },
    { value: 'part_time', get label() { return tr('Hrm_Part_Time'); } },
    { value: 'self_employed', get label() { return tr('Hrm_Self_Employed'); } },
    { value: 'freelance', get label() { return tr('Hrm_Freelance'); } },
    { value: 'contract', get label() { return tr('Contract'); } },
    { value: 'internship', get label() { return tr('Internship'); } },
    { value: 'apprenticeship', get label() { return tr('Hrm_Apprenticeship'); } },
    { value: 'seasonal', get label() { return tr('Hrm_Seasonal'); } },
];

export const GENDERS = [
    { value: 'male', get label() { return tr('Male'); } },
    { value: 'female', get label() { return tr('Female'); } },
];

export const MARITAL_STATUSES = [
    { value: 'married', get label() { return tr('Hrm_Married'); } },
    { value: 'single', get label() { return tr('Hrm_Single'); } },
    { value: 'divorced', get label() { return tr('Hrm_Divorced'); } },
];

export const LEAVE_STATUSES = [
    { value: 'approved', get label() { return tr('Approved'); } },
    { value: 'pending', get label() { return tr('Pending'); } },
    { value: 'rejected', get label() { return tr('Rejected'); } },
];

const LEAVE_STATUS_COLORS = { approved: 'green', pending: 'orange', rejected: 'red' };

export function leaveStatusColor(status) {
    return LEAVE_STATUS_COLORS[status] || 'default';
}

export function leaveStatusLabel(status) {
    const found = LEAVE_STATUSES.find(s => s.value === status);
    return found ? found.label : (status || '—');
}
