import { Flame, Sun, Snowflake, Phone, Users, Mail, StickyNote, SquareCheck } from 'lucide-vue-next';
import { t } from '../../i18n';

/**
 * Shared vocabulary for the CRM module: enum values the API stores, the i18n
 * key + English fallback each one shows, and its colour. Lookups that users
 * configure (sources, statuses, tags) come from the API with their own colour.
 */

export const LEAD_TYPES = [
    { value: 'person', key: 'Crm_Type_Person', label: 'Person' },
    { value: 'company', key: 'Crm_Type_Company', label: 'Company' },
];

export const RATINGS = [
    { value: 'hot', key: 'Crm_Rating_Hot', label: 'Hot', color: '#dc2626', icon: Flame },
    { value: 'warm', key: 'Crm_Rating_Warm', label: 'Warm', color: '#d97706', icon: Sun },
    { value: 'cold', key: 'Crm_Rating_Cold', label: 'Cold', color: '#0284c7', icon: Snowflake },
];

export const STATUS_KINDS = [
    { value: 'open', key: 'Crm_Kind_Open', label: 'Open', color: 'processing' },
    { value: 'converted', key: 'Crm_Kind_Converted', label: 'Converted', color: 'success' },
    { value: 'lost', key: 'Crm_Kind_Lost', label: 'Lost', color: 'error' },
];

/** Lead history entries. */
export const LEAD_EVENTS = {
    created: { key: 'Crm_Event_Created', label: 'Lead created', color: '#6366f1' },
    imported: { key: 'Crm_Event_Imported', label: 'Imported from a file', color: '#6366f1' },
    updated: { key: 'Crm_Event_Updated', label: 'Details edited', color: '#94a3b8' },
    status_changed: { key: 'Crm_Event_Status_Changed', label: 'Status changed', color: '#8b5cf6' },
    owner_changed: { key: 'Crm_Event_Owner_Changed', label: 'Owner changed', color: '#0ea5e9' },
    lost: { key: 'Crm_Event_Lost', label: 'Marked as lost', color: '#dc2626' },
    reopened: { key: 'Crm_Event_Reopened', label: 'Reopened', color: '#16a34a' },
    deal_created: { key: 'Crm_Event_Deal_Created', label: 'Deal created', color: '#0ea5e9' },
    deal_won: { key: 'Crm_Event_Deal_Won', label: 'Deal won', color: '#16a34a' },
    deal_lost: { key: 'Crm_Event_Deal_Lost', label: 'Deal lost', color: '#dc2626' },
    converted: { key: 'Crm_Event_Converted', label: 'Converted to a customer', color: '#16a34a' },
    captured: { key: 'Crm_Event_Captured', label: 'Captured', color: '#0ea5e9' },
    campaign: { key: 'Crm_Event_Campaign', label: 'Received a marketing campaign', color: '#ec4899' },
    unsubscribed: { key: 'Crm_Event_Unsubscribed', label: 'Unsubscribed from marketing', color: '#94a3b8' },
    resubscribed: { key: 'Crm_Event_Resubscribed', label: 'Subscribed to marketing again', color: '#16a34a' },
};

/** Where a lead came from (crm_leads.captured_via; null = typed in by hand). */
export const CAPTURE_CHANNELS = [
    { value: 'manual', key: 'Crm_Via_Manual', label: 'Added by hand', color: '#64748b' },
    { value: 'import', key: 'Crm_Via_Import', label: 'Imported', color: '#6366f1' },
    { value: 'web_form', key: 'Crm_Via_Web_Form', label: 'Web form', color: '#0ea5e9' },
    { value: 'store_message', key: 'Crm_Via_Store_Message', label: 'Store contact form', color: '#8b5cf6' },
    { value: 'quote_request', key: 'Crm_Via_Quote_Request', label: 'Quote request', color: '#f59e0b' },
    { value: 'property_inquiry', key: 'Crm_Via_Property_Inquiry', label: 'Property inquiry', color: '#14b8a6' },
    { value: 'portal_request', key: 'Crm_Via_Portal_Request', label: 'Portal request', color: '#ec4899' },
    { value: 'whatsapp', key: 'Crm_Via_WhatsApp', label: 'WhatsApp', color: '#16a34a' },
    { value: 'vehicle_lead', key: 'Crm_Via_Vehicle_Lead', label: 'Vehicle enquiry', color: '#0f766e' },
    { value: 'booking', key: 'Crm_Via_Booking', label: 'Website booking', color: '#6d28d9' },
];

/** Scoring rule criteria; `kind` = the value it needs (see CrmScoringRule::CRITERIA). */
export const SCORE_CRITERIA = [
    { value: 'has_email', key: 'Crm_Crit_Has_Email', label: 'Has an email address', kind: null },
    { value: 'has_phone', key: 'Crm_Crit_Has_Phone', label: 'Has a phone number', kind: null },
    { value: 'has_company', key: 'Crm_Crit_Has_Company', label: 'Has a company name', kind: null },
    { value: 'is_company', key: 'Crm_Crit_Is_Company', label: 'Is a company lead', kind: null },
    { value: 'rating', key: 'Crm_Crit_Rating', label: 'Rating is', kind: 'rating' },
    { value: 'source', key: 'Crm_Crit_Source', label: 'Source is', kind: 'source' },
    { value: 'captured_via', key: 'Crm_Crit_Captured_Via', label: 'Came in through', kind: 'channel' },
    { value: 'value_at_least', key: 'Crm_Crit_Value_At_Least', label: 'Estimated value at least', kind: 'number' },
    { value: 'country', key: 'Crm_Crit_Country', label: 'Country is', kind: 'text' },
    { value: 'tag', key: 'Crm_Crit_Tag', label: 'Has the tag', kind: 'tag' },
    { value: 'recent_activity', key: 'Crm_Crit_Recent_Activity', label: 'Activity in the last … days', kind: 'days' },
    { value: 'no_activity', key: 'Crm_Crit_No_Activity', label: 'No activity for … days', kind: 'days' },
    { value: 'done_activities_at_least', key: 'Crm_Crit_Done_Activities', label: 'Completed activities at least', kind: 'number' },
    { value: 'has_open_deal', key: 'Crm_Crit_Has_Open_Deal', label: 'Has an open deal', kind: null },
    { value: 'has_reply', key: 'Crm_Crit_Has_Reply', label: 'Replied on WhatsApp', kind: null },
];

/** Score badge colour: 70+ hot, 40+ warm, else cold. */
export function scoreColor(score) {
    const s = Number(score) || 0;
    return s >= 70 ? '#16a34a' : (s >= 40 ? '#f59e0b' : '#94a3b8');
}

export const DEAL_STATUSES = [
    { value: 'open', key: 'Crm_Deal_Open', label: 'Open', color: 'processing' },
    { value: 'won', key: 'Crm_Deal_Won', label: 'Won', color: 'success' },
    { value: 'lost', key: 'Crm_Deal_Lost', label: 'Lost', color: 'error' },
];

/** Deal history entries. */
export const DEAL_EVENTS = {
    created: { key: 'Crm_Event_Deal_Created', label: 'Deal created', color: '#6366f1' },
    updated: { key: 'Crm_Event_Updated', label: 'Details edited', color: '#94a3b8' },
    stage_changed: { key: 'Crm_Event_Stage_Changed', label: 'Moved', color: '#8b5cf6' },
    owner_changed: { key: 'Crm_Event_Owner_Changed', label: 'Owner changed', color: '#0ea5e9' },
    won: { key: 'Crm_Event_Deal_Won', label: 'Deal won', color: '#16a34a' },
    lost: { key: 'Crm_Event_Deal_Lost', label: 'Deal lost', color: '#dc2626' },
    reopened: { key: 'Crm_Event_Reopened', label: 'Reopened', color: '#16a34a' },
    quotation_created: { key: 'Crm_Event_Quotation_Created', label: 'Quotation created', color: '#0ea5e9' },
    portal_shared: { key: 'Crm_Event_Portal_Shared', label: 'Shown in the client portal', color: '#0ea5e9' },
    portal_hidden: { key: 'Crm_Event_Portal_Hidden', label: 'Hidden from the client portal', color: '#94a3b8' },
};

/** Activity kinds: icon, colour, and whether a new one is usually logged (done) or planned. */
export const ACTIVITY_TYPES = [
    { value: 'call', key: 'Crm_Act_Call', label: 'Call', icon: Phone, color: '#0ea5e9', mode: 'done' },
    { value: 'meeting', key: 'Crm_Act_Meeting', label: 'Meeting', icon: Users, color: '#8b5cf6', mode: 'open' },
    { value: 'task', key: 'Crm_Act_Task', label: 'Task', icon: SquareCheck, color: '#f59e0b', mode: 'open' },
    { value: 'email', key: 'Crm_Act_Email', label: 'Email', icon: Mail, color: '#16a34a', mode: 'done' },
    { value: 'note', key: 'Crm_Act_Note', label: 'Note', icon: StickyNote, color: '#64748b', mode: 'done' },
];

export const CALL_OUTCOMES = [
    { value: 'connected', key: 'Crm_Outcome_Connected', label: 'Connected', color: 'success' },
    { value: 'no_answer', key: 'Crm_Outcome_No_Answer', label: 'No answer', color: 'default' },
    { value: 'voicemail', key: 'Crm_Outcome_Voicemail', label: 'Left voicemail', color: 'processing' },
    { value: 'busy', key: 'Crm_Outcome_Busy', label: 'Busy', color: 'warning' },
    { value: 'wrong_number', key: 'Crm_Outcome_Wrong_Number', label: 'Wrong number', color: 'error' },
];

export const PRIORITIES = [
    { value: 'low', key: 'Crm_Priority_Low', label: 'Low', color: '#94a3b8' },
    { value: 'normal', key: 'Crm_Priority_Normal', label: 'Normal', color: '#3b82f6' },
    { value: 'high', key: 'Crm_Priority_High', label: 'High', color: '#dc2626' },
];

/** Reminder choices, in minutes before the due time. */
export const REMINDERS = [
    { value: null, key: 'Crm_No_Reminder', label: 'No reminder' },
    { value: 0, key: 'Crm_Remind_At_Time', label: 'At the due time' },
    { value: 5, key: 'Crm_Remind_5m', label: '5 minutes before' },
    { value: 15, key: 'Crm_Remind_15m', label: '15 minutes before' },
    { value: 30, key: 'Crm_Remind_30m', label: '30 minutes before' },
    { value: 60, key: 'Crm_Remind_1h', label: '1 hour before' },
    { value: 1440, key: 'Crm_Remind_1d', label: '1 day before' },
];

/** "Today 14:30", "Tomorrow 09:00", else a date — for next-step chips. */
export function dueLabel(iso, dateFmt) {
    if (!iso) return '';
    const d = new Date(iso);
    const now = new Date();
    const day = (x) => new Date(x.getFullYear(), x.getMonth(), x.getDate()).getTime();
    const diff = Math.round((day(d) - day(now)) / 86400000);
    const time = d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    if (diff === 0) return `${t('Crm_Today', 'Today')} ${time}`;
    if (diff === 1) return `${t('Crm_Tomorrow', 'Tomorrow')} ${time}`;
    if (diff === -1) return `${t('Crm_Yesterday', 'Yesterday')} ${time}`;
    return dateFmt ? dateFmt(iso) : d.toLocaleDateString();
}
/** Line total, same rule as the server: qty × price − discount %, + tax when exclusive. */
export function lineTotal(l) {
    const net = Number(l.quantity || 0) * Number(l.unit_price || 0) * (1 - Math.min(100, Math.max(0, Number(l.discount_percent || 0))) / 100);
    const tax = Number(l.tax_method) === 2 ? 0 : Number(l.tax_percent || 0);
    return Math.round(net * (1 + tax / 100) * 100) / 100;
}

/** Default colours offered by the colour pickers in CRM Settings. */
export const SWATCHES = ['#3b82f6', '#6366f1', '#8b5cf6', '#ec4899', '#dc2626', '#f97316', '#f59e0b', '#16a34a', '#14b8a6', '#0ea5e9', '#64748b', '#0f172a'];

export function tr(list) {
    return list.map(o => ({ ...o, label: t(o.key, o.label) }));
}

export function optionOf(list, value) {
    return list.find(o => o.value === value) || { value, key: '', label: value || '—', color: 'default' };
}

export function labelOf(list, value) {
    const o = optionOf(list, value);
    return o.key ? t(o.key, o.label) : o.label;
}

export function initials(name) {
    const parts = String(name || '').trim().split(/\s+/).filter(Boolean);
    if (!parts.length) return '?';
    return (parts[0][0] + (parts.length > 1 ? parts[parts.length - 1][0] : '')).toUpperCase();
}

const AVATAR_COLORS = ['#7c3aed', '#0891b2', '#16a34a', '#d97706', '#db2777', '#4f46e5', '#0d9488', '#dc2626'];
export function avatarColor(name) {
    let h = 0;
    for (const ch of String(name || '')) h = (h * 31 + ch.charCodeAt(0)) >>> 0;
    return AVATAR_COLORS[h % AVATAR_COLORS.length];
}

/** Soft background for a hex colour (status / source pills). */
export function tint(hex, alpha = 0.14) {
    const m = /^#?([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i.exec(hex || '');
    if (!m) return 'rgba(100,116,139,0.14)';
    return `rgba(${parseInt(m[1], 16)}, ${parseInt(m[2], 16)}, ${parseInt(m[3], 16)}, ${alpha})`;
}

/** "3 days ago" style text without a dependency. */
export function ago(iso) {
    if (!iso) return '—';
    const s = Math.max(0, (Date.now() - new Date(iso).getTime()) / 1000);
    if (s < 60) return t('Crm_Just_Now', 'just now');
    const units = [[31536000, 'y'], [2592000, 'mo'], [86400, 'd'], [3600, 'h'], [60, 'min']];
    for (const [sec, u] of units) {
        if (s >= sec) return `${Math.floor(s / sec)}${u}`;
    }
    return '';
}
