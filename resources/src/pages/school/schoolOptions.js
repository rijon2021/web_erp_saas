/**
 * Shared vocabulary for the school module: the enum values the API stores, the
 * label each shows and the antd tag colour it wears. Every school page reads
 * these, so a status can never look one way in a list and another in a report.
 */

import { i18n } from '../../i18n';

const tr = (k) => i18n.global.t(k);

export const GENDERS = [
    { value: 'male', get label() { return tr('Sc_Male'); } },
    { value: 'female', get label() { return tr('Sc_Female'); } },
    { value: 'other', get label() { return tr('Sc_Other'); } },
];

export const STUDENT_STATUSES = [
    { value: 'active', get label() { return tr('Sc_Active'); }, color: 'success' },
    { value: 'inactive', get label() { return tr('Sc_Inactive'); }, color: 'default' },
    { value: 'graduated', get label() { return tr('Sc_Graduated'); }, color: 'processing' },
    { value: 'transferred', get label() { return tr('Sc_Transferred'); }, color: 'warning' },
    { value: 'expelled', get label() { return tr('Sc_Expelled'); }, color: 'error' },
];

export const ENROLLMENT_STATUSES = [
    { value: 'active', get label() { return tr('Sc_Active'); }, color: 'success' },
    { value: 'promoted', get label() { return tr('Sc_Promoted'); }, color: 'processing' },
    { value: 'repeated', get label() { return tr('Sc_Repeated'); }, color: 'warning' },
    { value: 'transferred', get label() { return tr('Sc_Transferred'); }, color: 'default' },
    { value: 'left', get label() { return tr('Sc_Left'); }, color: 'error' },
];

export const ATTENDANCE_STATUSES = [
    { value: 'present', get label() { return tr('Sc_Present'); }, color: 'success', get short() { return tr('Sc_Present_Short'); } },
    { value: 'absent', get label() { return tr('Sc_Absent'); }, color: 'error', get short() { return tr('Sc_Absent_Short'); } },
    { value: 'late', get label() { return tr('Sc_Late'); }, color: 'warning', get short() { return tr('Sc_Late_Short'); } },
    { value: 'excused', get label() { return tr('Sc_Excused'); }, color: 'processing', get short() { return tr('Sc_Excused_Short'); } },
    { value: 'half_day', get label() { return tr('Sc_Half_Day'); }, color: 'default', get short() { return tr('Sc_Half_Day_Short'); } },
];

export const SUBJECT_TYPES = [
    { value: 'core', get label() { return tr('Sc_Core'); }, color: 'blue' },
    { value: 'elective', get label() { return tr('Sc_Elective'); }, color: 'purple' },
    { value: 'optional', get label() { return tr('Sc_Optional'); }, color: 'default' },
];

export const EXAM_TERMS = [
    { value: 'term_1', get label() { return tr('Sc_Term_1'); } },
    { value: 'term_2', get label() { return tr('Sc_Term_2'); } },
    { value: 'term_3', get label() { return tr('Sc_Term_3'); } },
    { value: 'final', get label() { return tr('Sc_Final'); } },
    { value: 'other', get label() { return tr('Sc_Other'); } },
];

export const EXAM_STATUSES = [
    { value: 'draft', get label() { return tr('Sc_Draft'); }, color: 'default' },
    { value: 'scheduled', get label() { return tr('Sc_Scheduled'); }, color: 'processing' },
    { value: 'ongoing', get label() { return tr('Sc_Ongoing'); }, color: 'warning' },
    { value: 'completed', get label() { return tr('Sc_Completed'); }, color: 'cyan' },
    { value: 'published', get label() { return tr('Sc_Published'); }, color: 'success' },
];

export const FEE_FREQUENCIES = [
    { value: 'once', get label() { return tr('Sc_One_Off'); } },
    { value: 'monthly', get label() { return tr('Sc_Monthly'); } },
    { value: 'termly', get label() { return tr('Sc_Termly'); } },
    { value: 'yearly', get label() { return tr('Sc_Yearly'); } },
];

export const INVOICE_STATUSES = [
    { value: 'draft', get label() { return tr('Sc_Draft'); }, color: 'default' },
    { value: 'unpaid', get label() { return tr('Sc_Unpaid'); }, color: 'error' },
    { value: 'partial', get label() { return tr('Sc_Partly_Paid'); }, color: 'warning' },
    { value: 'paid', get label() { return tr('Sc_Paid'); }, color: 'success' },
    { value: 'cancelled', get label() { return tr('Sc_Cancelled'); }, color: 'default' },
];

export const PAYMENT_METHODS = [
    { value: 'cash', get label() { return tr('Sc_Cash'); } },
    { value: 'card', get label() { return tr('Sc_Card'); } },
    { value: 'bank_transfer', get label() { return tr('Sc_Bank_Transfer'); } },
    { value: 'cheque', get label() { return tr('Sc_Cheque'); } },
    { value: 'mobile_money', get label() { return tr('Sc_Mobile_Money'); } },
    { value: 'other', get label() { return tr('Sc_Other'); } },
];

export const WEEK_DAYS = [
    { value: 'mon', get label() { return tr('Sc_Monday'); }, get short() { return tr('Sc_Mon'); } },
    { value: 'tue', get label() { return tr('Sc_Tuesday'); }, get short() { return tr('Sc_Tue'); } },
    { value: 'wed', get label() { return tr('Sc_Wednesday'); }, get short() { return tr('Sc_Wed'); } },
    { value: 'thu', get label() { return tr('Sc_Thursday'); }, get short() { return tr('Sc_Thu'); } },
    { value: 'fri', get label() { return tr('Sc_Friday'); }, get short() { return tr('Sc_Fri'); } },
    { value: 'sat', get label() { return tr('Sc_Saturday'); }, get short() { return tr('Sc_Sat'); } },
    { value: 'sun', get label() { return tr('Sc_Sunday'); }, get short() { return tr('Sc_Sun'); } },
];

/** Look one up, falling back to the raw value so nothing renders blank. */
export function optionOf(list, value) {
    return list.find(o => o.value === value) || { value, label: value || '—', color: 'default' };
}

export function labelOf(list, value) {
    return optionOf(list, value).label;
}

/**
 * Colour for a grade, matching the backend's default scale in
 * ExamResult::gradeFor(). If a school changes that scale, change this too —
 * they are two halves of one decision.
 */
export function gradeColor(grade) {
    if (!grade) return 'default';
    if (grade === 'AB') return 'default';
    if (grade.startsWith('A')) return 'success';
    if (grade.startsWith('B')) return 'cyan';
    if (grade === 'C') return 'processing';
    if (grade === 'D') return 'warning';
    return 'error';
}

/** Attendance / collection rates share one traffic-light reading. */
export function rateColor(rate, { good = 90, fair = 75 } = {}) {
    if (rate === null || rate === undefined) return 'default';
    if (rate >= good) return 'success';
    if (rate >= fair) return 'warning';
    return 'error';
}
