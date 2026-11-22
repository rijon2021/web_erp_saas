import { i18n } from '../../i18n';

/**
 * Display labels for the enum values the meeting API stores (status, type,
 * attendance, invitation, note type/status). Values without a key — e.g. log
 * actions or platform brand names — fall back to humanized snake_case.
 */
const LABEL_KEYS = {
    scheduled: 'Scheduled',
    ongoing: 'Ongoing',
    completed: 'Completed',
    cancelled: 'Cancelled',
    physical: 'Physical',
    online: 'Online',
    other: 'Other',
    pending: 'Pending',
    present: 'Present',
    absent: 'Absent',
    late: 'Late',
    invited: 'Invited',
    accepted: 'Accepted',
    declined: 'Declined',
    tentative: 'Tentative',
    note: 'Note',
    decision: 'Decision',
    action_item: 'Action_Item',
    open: 'Open',
    in_progress: 'In_Progress',
    done: 'Done',
};

const humanize = v => String(v).replace(/_/g, ' ');

export function meetingLabel(value, fallback = humanize) {
    if (!value) return '-';
    const key = LABEL_KEYS[value];
    return key ? i18n.global.t(key) : fallback(value);
}
