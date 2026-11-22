/**
 * Shared vocabulary for the hospital module: the enum values the API stores,
 * the label each shows and the antd tag colour it wears. Every hospital page
 * reads these, so a status can never look one way in a list and another in a
 * report.
 *
 * Colour choices are clinical, not decorative: anything meaning "needs
 * attention now" is red, "in progress" is amber, "settled" is green.
 */

import { i18n } from '../../i18n';

const tr = (k) => i18n.global.t(k);

export const GENDERS = [
    { value: 'male', get label() { return tr('Hm_Male'); } },
    { value: 'female', get label() { return tr('Hm_Female'); } },
    { value: 'other', get label() { return tr('Hm_Other'); } },
];

export const BLOOD_GROUPS = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-']
    .map(g => ({ value: g, label: g }));

export const PATIENT_STATUSES = [
    { value: 'active', get label() { return tr('Hm_Active'); }, color: 'success' },
    { value: 'inactive', get label() { return tr('Hm_Inactive'); }, color: 'default' },
    { value: 'deceased', get label() { return tr('Hm_Deceased'); }, color: 'error' },
];

export const APPOINTMENT_TYPES = [
    { value: 'consultation', get label() { return tr('Hm_Consultation'); }, color: 'blue' },
    { value: 'follow_up', get label() { return tr('Hm_Follow_Up'); }, color: 'cyan' },
    { value: 'procedure', get label() { return tr('Hm_Procedure'); }, color: 'purple' },
    { value: 'emergency', get label() { return tr('Hm_Emergency'); }, color: 'red' },
    { value: 'teleconsult', get label() { return tr('Hm_Teleconsult'); }, color: 'geekblue' },
];

export const APPOINTMENT_STATUSES = [
    { value: 'scheduled', get label() { return tr('Hm_Scheduled'); }, color: 'default' },
    { value: 'confirmed', get label() { return tr('Hm_Confirmed'); }, color: 'processing' },
    { value: 'arrived', get label() { return tr('Hm_Arrived'); }, color: 'warning' },
    { value: 'completed', get label() { return tr('Hm_Completed'); }, color: 'success' },
    { value: 'cancelled', get label() { return tr('Hm_Cancelled'); }, color: 'default' },
    { value: 'no_show', get label() { return tr('Hm_No_Show'); }, color: 'error' },
];

export const VISIT_TYPES = [
    { value: 'opd', get label() { return tr('Hm_OPD'); }, color: 'blue' },
    { value: 'emergency', get label() { return tr('Hm_Emergency'); }, color: 'red' },
    { value: 'follow_up', get label() { return tr('Hm_Follow_Up'); }, color: 'cyan' },
    { value: 'teleconsult', get label() { return tr('Hm_Teleconsult'); }, color: 'geekblue' },
];

export const VISIT_STATUSES = [
    { value: 'open', get label() { return tr('Hm_Open'); }, color: 'processing' },
    { value: 'completed', get label() { return tr('Hm_Completed'); }, color: 'success' },
];

export const WARD_TYPES = [
    { value: 'general', get label() { return tr('Hm_General'); } },
    { value: 'private', get label() { return tr('Hm_Private'); } },
    { value: 'semi_private', get label() { return tr('Hm_Semi_Private'); } },
    { value: 'icu', get label() { return tr('Hm_ICU'); } },
    { value: 'nicu', get label() { return tr('Hm_NICU'); } },
    { value: 'maternity', get label() { return tr('Hm_Maternity'); } },
    { value: 'isolation', get label() { return tr('Hm_Isolation'); } },
];

export const BED_STATUSES = [
    { value: 'available', get label() { return tr('Hm_Available'); }, color: 'success' },
    { value: 'occupied', get label() { return tr('Hm_Occupied'); }, color: 'error' },
    { value: 'reserved', get label() { return tr('Hm_Reserved'); }, color: 'warning' },
    { value: 'maintenance', get label() { return tr('Hm_Maintenance'); }, color: 'default' },
];

export const ADMISSION_STATUSES = [
    { value: 'admitted', get label() { return tr('Hm_Admitted'); }, color: 'processing' },
    { value: 'discharged', get label() { return tr('Hm_Discharged'); }, color: 'success' },
    { value: 'transferred', get label() { return tr('Hm_Transferred'); }, color: 'warning' },
    { value: 'deceased', get label() { return tr('Hm_Deceased'); }, color: 'error' },
];

export const LAB_PRIORITIES = [
    { value: 'routine', get label() { return tr('Hm_Routine'); }, color: 'default' },
    { value: 'urgent', get label() { return tr('Hm_Urgent'); }, color: 'warning' },
    { value: 'stat', get label() { return tr('Hm_STAT'); }, color: 'error' },
];

export const LAB_STATUSES = [
    { value: 'ordered', get label() { return tr('Hm_Ordered'); }, color: 'default' },
    { value: 'sample_collected', get label() { return tr('Hm_Sample_Collected'); }, color: 'processing' },
    { value: 'in_progress', get label() { return tr('Hm_In_Progress'); }, color: 'warning' },
    { value: 'completed', get label() { return tr('Hm_Completed'); }, color: 'success' },
    { value: 'cancelled', get label() { return tr('Hm_Cancelled'); }, color: 'default' },
];

export const RESULT_FLAGS = [
    { value: 'normal', get label() { return tr('Hm_Normal'); }, color: 'success' },
    { value: 'low', get label() { return tr('Hm_Low'); }, color: 'warning' },
    { value: 'high', get label() { return tr('Hm_High'); }, color: 'warning' },
    { value: 'critical', get label() { return tr('Hm_Critical'); }, color: 'error' },
];

export const INVOICE_STATUSES = [
    { value: 'draft', get label() { return tr('Hm_Draft'); }, color: 'default' },
    { value: 'unpaid', get label() { return tr('Hm_Unpaid'); }, color: 'error' },
    { value: 'partial', get label() { return tr('Hm_Partly_Paid'); }, color: 'warning' },
    { value: 'paid', get label() { return tr('Hm_Paid'); }, color: 'success' },
    { value: 'cancelled', get label() { return tr('Hm_Cancelled'); }, color: 'default' },
];

export const INVOICE_ITEM_TYPES = [
    { value: 'consultation', get label() { return tr('Hm_Consultation'); } },
    { value: 'procedure', get label() { return tr('Hm_Procedure'); } },
    { value: 'medicine', get label() { return tr('Hm_Medicine'); } },
    { value: 'lab', get label() { return tr('Hm_Lab_Test'); } },
    { value: 'bed', get label() { return tr('Hm_Bed_Charge'); } },
    { value: 'other', get label() { return tr('Hm_Other'); } },
];

export const PAYMENT_METHODS = [
    { value: 'cash', get label() { return tr('Hm_Cash'); } },
    { value: 'card', get label() { return tr('Hm_Card'); } },
    { value: 'bank_transfer', get label() { return tr('Hm_Bank_Transfer'); } },
    { value: 'insurance', get label() { return tr('Hm_Insurance'); } },
    { value: 'cheque', get label() { return tr('Hm_Cheque'); } },
    { value: 'other', get label() { return tr('Hm_Other'); } },
];

export const WEEK_DAYS = [
    { value: 'mon', get label() { return tr('Hm_Mon'); } },
    { value: 'tue', get label() { return tr('Hm_Tue'); } },
    { value: 'wed', get label() { return tr('Hm_Wed'); } },
    { value: 'thu', get label() { return tr('Hm_Thu'); } },
    { value: 'fri', get label() { return tr('Hm_Fri'); } },
    { value: 'sat', get label() { return tr('Hm_Sat'); } },
    { value: 'sun', get label() { return tr('Hm_Sun'); } },
];

/** Look one up, falling back to the raw value so nothing renders blank. */
export function optionOf(list, value) {
    return list.find(o => o.value === value) || { value, label: value || '—', color: 'default' };
}

export function labelOf(list, value) {
    return optionOf(list, value).label;
}

/**
 * Vital-sign interpretation for adults, used to tint the readings on screen.
 * Deliberately coarse: this flags what a clinician should LOOK at, it does not
 * diagnose, and paediatric ranges differ enough that under-16s get no flag.
 */
export function vitalTone(key, value, age = null) {
    if (value === null || value === undefined || value === '') return null;
    const n = Number(value);
    if (Number.isNaN(n)) return null;
    if (age !== null && age !== undefined && age < 16) return null;

    const ranges = {
        temperature: [36.1, 37.8],
        pulse: [60, 100],
        bp_systolic: [90, 140],
        bp_diastolic: [60, 90],
        respiratory_rate: [12, 20],
        spo2: [95, 100],
    };

    const range = ranges[key];
    if (!range) return null;
    if (n < range[0]) return 'low';
    if (n > range[1]) return 'high';
    return 'normal';
}

/** Standard adult BMI bands. */
export function bmiBand(bmi) {
    if (!bmi) return null;
    if (bmi < 18.5) return { label: tr('Hm_Underweight'), color: 'warning' };
    if (bmi < 25) return { label: tr('Hm_Normal'), color: 'success' };
    if (bmi < 30) return { label: tr('Hm_Overweight'), color: 'warning' };
    return { label: tr('Hm_Obese'), color: 'error' };
}

/**
 * Y-m-d in the browser's own calendar. toISOString() is UTC, so the day board
 * opened on yesterday east of UTC after midnight (and tomorrow west of it).
 */
export function localDate(d = new Date()) {
    const pad = n => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
}
