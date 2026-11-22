import { i18n } from '../i18n';

/**
 * Software License Keys — shared vocabulary for the admin pages
 * (pages/license_keys/*) and the sale-details card.
 *
 * Two sources: `pool` = keys the business bought and uploaded (stock), and
 * `generated` = licenses this store creates and controls (expiry,
 * activations, suspend / revoke). A key's `status` is its stock state; a
 * generated key also has a `license_state` (active / expired / suspended /
 * revoked / inactive) computed by the server.
 */

function tr(key, fallback) {
    const txt = i18n.global.t(key);
    return txt && txt !== key ? txt : fallback;
}

export const LK_STATUSES = ['available', 'sold', 'returned', 'void', 'suspended', 'revoked'];
/** Extra list filters the server understands on top of the stock statuses. */
export const LK_STATE_FILTERS = ['active', 'expired', 'expiring'];

const STATUS_COLORS = {
    available: 'success',
    sold: 'processing',
    returned: 'warning',
    void: 'default',
    suspended: 'orange',
    revoked: 'error',
    active: 'success',
    expired: 'volcano',
    expiring: 'gold',
    inactive: 'default',
};

const STATUS_KEYS = {
    available: ['Status_available', 'Available'],
    sold: ['Status_sold', 'Sold'],
    returned: ['Returned', 'Returned'],
    void: ['LicK_St_Void', 'Written off'],
    suspended: ['LicK_St_Suspended', 'Suspended'],
    revoked: ['Revoked', 'Revoked'],
    active: ['Active', 'Active'],
    expired: ['Expired', 'Expired'],
    expiring: ['LicK_St_Expiring', 'Expiring in 30 days'],
    inactive: ['Inactive', 'Inactive'],
};

export function licenseStatusColor(status) {
    return STATUS_COLORS[status] || 'default';
}

export function licenseStatusLabel(status) {
    if (!status) return '-';
    const k = STATUS_KEYS[status];
    return k ? tr(k[0], k[1]) : String(status).replace(/_/g, ' ');
}

export function licenseSourceLabel(source) {
    return source === 'generated'
        ? tr('LicK_Source_Generated', 'Generated keys')
        : tr('LicK_Source_Pool', 'Uploaded keys');
}

export function licenseSourceColor(source) {
    return source === 'generated' ? 'purple' : 'cyan';
}

export const LK_EVENTS = [
    'imported', 'sold', 'issued', 'emailed', 'returned', 'restocked', 'voided', 'revoked',
    'suspended', 'reactivated', 'extended', 'activated', 'deactivated', 'activations_reset',
    'revealed', 'exported', 'return_reversed', 'reminded',
];

const EVENT_COLORS = {
    imported: 'blue', issued: 'blue', sold: 'green', emailed: 'green', reminded: 'green',
    returned: 'orange', return_reversed: 'blue', restocked: 'green', voided: 'gray',
    revoked: 'red', suspended: 'orange', reactivated: 'green', extended: 'blue',
    activated: 'green', deactivated: 'gray', activations_reset: 'orange',
    revealed: 'gray', exported: 'gray',
};

/** LicK_Ev_<event>, else the raw slug made readable. */
export function licenseEventLabel(event) {
    if (!event) return '-';
    return tr('LicK_Ev_' + event, String(event).replace(/_/g, ' '));
}

export function licenseEventColor(event) {
    return EVENT_COLORS[event] || 'gray';
}

export function licenseRestrictionLabel(r) {
    if (r === 'domain') return tr('LicK_Restrict_Domain', 'One domain per activation');
    if (r === 'device') return tr('LicK_Restrict_Device', 'One device per activation');
    return tr('LicK_Restrict_None', 'No restriction');
}

/**
 * Error text for a failed call: the first 422 field error, else the
 * server's message (409 = tenant migrations not run), else InvalidData.
 */
export function licenseError(e, fallbackKey = 'InvalidData') {
    const errors = e?.data?.errors;
    if (errors && typeof errors === 'object') {
        const first = Object.values(errors).flat().find(Boolean);
        if (first) return String(first);
    }
    return e?.data?.message || i18n.global.t(fallbackKey);
}

/** Whether a failure means the tenant database update has not been run. */
export function licenseNotMigrated(e) {
    return e?.status === 409 && !!e?.data?.message;
}

export async function copyText(text) {
    try {
        await navigator.clipboard.writeText(text);
        return true;
    } catch (e) {
        // Insecure context (plain http on a LAN IP): fall back to a textarea.
        try {
            const ta = document.createElement('textarea');
            ta.value = text;
            ta.setAttribute('readonly', '');
            ta.style.position = 'fixed';
            ta.style.opacity = '0';
            document.body.appendChild(ta);
            ta.select();
            const ok = document.execCommand('copy');
            document.body.removeChild(ta);
            return ok;
        } catch (err) {
            return false;
        }
    }
}
