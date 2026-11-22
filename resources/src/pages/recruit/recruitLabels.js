import { i18n } from '../../i18n';

/**
 * Display labels for the enum values the recruit API stores (application
 * stage, candidate source, interview type/status, job type/status). Values
 * without a key fall back to humanized snake_case, as before.
 */
const LABEL_KEYS = {
    // application stages
    applied: 'Rc_Applied',
    screening: 'Rc_Screening',
    shortlisted: 'Rc_Shortlisted',
    interview: 'Rc_Interview',
    offered: 'Rc_Offered',
    hired: 'Hired',
    rejected: 'Rejected',
    // candidate sources
    website: 'Rc_Website',
    referral: 'Rc_Referral',
    linkedin: 'LinkedIn',
    job_board: 'Rc_Job_Board',
    agency: 'Rc_Agency',
    walk_in: 'Rc_Walk_In',
    other: 'Other',
    // interview types
    phone: 'Phone',
    video: 'Rc_Video',
    in_person: 'Rc_In_Person',
    technical: 'Rc_Technical',
    panel: 'Rc_Panel',
    group: 'Group',
    // interview statuses
    scheduled: 'Scheduled',
    completed: 'Completed',
    cancelled: 'Cancelled',
    no_show: 'Rc_No_Show',
    rescheduled: 'Rc_Rescheduled',
    // job types
    full_time: 'Full_Time',
    part_time: 'Part_Time',
    contract: 'Contract',
    internship: 'Internship',
    remote: 'Remote',
    // job statuses
    draft: 'Draft',
    open: 'Open',
    on_hold: 'On_Hold',
    closed: 'Closed',
};

export function recruitLabel(value) {
    if (!value) return '-';
    const key = LABEL_KEYS[value];
    return key ? i18n.global.t(key) : String(value).replace(/_/g, ' ');
}
