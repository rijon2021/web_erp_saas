/**
 * Marketing vocabulary shared by the module's pages. Channel and status
 * values are backend enums (campaigns.type / campaigns.status); labels are
 * displayed as brand names for channels (Email is translated) and as
 * translated status words; unknown values fall back to the raw value with
 * underscores replaced.
 */
import { i18n } from '../../i18n';

const tr = (k) => i18n.global.t(k);

export const CHANNELS = ['sms', 'email', 'whatsapp'];

export const CAMPAIGN_STATUSES = ['draft', 'scheduled', 'sending', 'sent', 'failed', 'cancelled'];

const CHANNEL_LABELS = { sms: 'SMS', whatsapp: 'WhatsApp' };

export function channelLabel(type) {
    if (type === 'email') return tr('Email');
    return CHANNEL_LABELS[type] || type || '—';
}

/** Campaign + recipient status words → translation keys. */
const STATUS_LABEL_KEYS = {
    draft: 'Draft', scheduled: 'Scheduled', sending: 'Sending', sent: 'Sent',
    failed: 'Failed', cancelled: 'Cancelled', pending: 'Pending',
    delivered: 'Delivered', skipped: 'Skipped',
};

/** Translated status label; otherwise legacy's format_label(): "not_started" → "not started". */
export function plainLabel(v) {
    if (STATUS_LABEL_KEYS[v]) return tr(STATUS_LABEL_KEYS[v]);
    return v ? String(v).replace(/_/g, ' ') : '—';
}

const STATUS_COLORS = {
    draft: 'default',
    scheduled: 'blue',
    sending: 'orange',
    sent: 'green',
    failed: 'red',
    cancelled: 'default',
};

export function campaignStatusColor(status) {
    return STATUS_COLORS[status] || 'default';
}

const RECIPIENT_COLORS = {
    pending: 'orange',
    sent: 'green',
    delivered: 'green',
    failed: 'red',
    skipped: 'default',
};

export function recipientStatusColor(status) {
    return RECIPIENT_COLORS[status] || 'default';
}

/** Statuses a campaign can be edited/sent in (legacy canEdit/canSend). */
export function campaignEditable(status) {
    return ['draft', 'scheduled', 'failed', 'cancelled'].includes(status);
}

/** stuck = the campaign's is_stuck flag: a send that stalled can be retried. */
export function campaignSendable(status, stuck = false) {
    return ['draft', 'scheduled', 'failed'].includes(status) || (status === 'sending' && !!stuck);
}

/** Placeholders the dispatcher replaces per recipient. */
export const PERSONALIZATION_VARIABLES = [
    '{customer_name}', '{phone}', '{email}', '{last_purchase}', '{total_spent}',
];
