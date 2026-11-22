import { Modal, message } from 'ant-design-vue';
import http from '../../lib/http';
import { t } from '../../i18n';

/**
 * Booking statuses, the reasons the workflow API gives when a step cannot go
 * ahead (App\Services\Booking\BookingWorkflow / AvailabilityService::check),
 * and runAction(): POST bookings/{id}/<action>, offering "Save anyway" when
 * the API says the time clashes but staff may override (can_force).
 */

// Calendar colours: text on bg passes 4.5:1; borders differ in lightness too.
export const STATUSES = [
  { value: 'pending', key: 'Pending', fallback: 'Pending', tag: 'warning', bg: '#fff1d6', border: '#d48806', text: '#7a4a00' },
  { value: 'confirmed', key: 'Confirmed', fallback: 'Confirmed', tag: 'processing', bg: '#dbeafe', border: '#1d4ed8', text: '#1e3a8a' },
  { value: 'arrived', key: 'Bkm_Status_Arrived', fallback: 'Arrived', tag: 'purple', bg: '#ede9fe', border: '#6d28d9', text: '#4c1d95' },
  { value: 'completed', key: 'Completed', fallback: 'Completed', tag: 'success', bg: '#dcfce7', border: '#15803d', text: '#14532d' },
  { value: 'no_show', key: 'Bkm_Status_No_Show', fallback: 'No-show', tag: 'magenta', bg: '#fce7f3', border: '#be185d', text: '#831843' },
  { value: 'declined', key: 'Bkm_Status_Declined', fallback: 'Declined', tag: 'default', bg: '#f1f5f9', border: '#64748b', text: '#334155' },
  { value: 'cancelled', key: 'Cancelled', fallback: 'Cancelled', tag: 'error', bg: '#fee2e2', border: '#b91c1c', text: '#7f1d1d' },
];

const BY_VALUE = Object.fromEntries(STATUSES.map((s) => [s.value, s]));

export function statusLabel(value) {
  const s = BY_VALUE[value];
  return s ? t(s.key, s.fallback) : String(value || '');
}

export function statusTag(value) {
  return BY_VALUE[value]?.tag || 'default';
}

export function statusColors(value) {
  return BY_VALUE[value] || BY_VALUE.pending;
}

export function statusOptions() {
  return STATUSES.map((s) => ({ value: s.value, label: statusLabel(s.value) }));
}

const REASONS = {
  staff_unknown: 'That staff member is not bookable.',
  staff_not_for_service: 'That staff member does not do this service.',
  day_off: 'That staff member does not work on that day.',
  holiday: 'That day is a company holiday.',
  leave: 'That staff member is on leave that day.',
  time_off: 'That staff member has the day off.',
  outside_hours: 'That time is outside the working hours.',
  clash: 'That time clashes with another booking.',
  class_full: 'The class is full at that time.',
  daily_limit: 'That staff member has reached their bookings for the day.',
  no_staff_free: 'Nobody who does this service is free at that time.',
  needs_time: 'Give this request a date and a start time first.',
  not_started: 'A booking can be marked as a no-show only once its start time has passed.',
  wrong_status: 'This booking has moved on since you opened it. Refresh and try again.',
  bad_time: 'Pick a valid date and start time.',
};

/** Translated text for a reason code, with the clashing bookings / hours appended. */
export function reasonText(reason, extra = {}) {
  let text = REASONS[reason] ? t(`Bkm_Reason_${reason}`, REASONS[reason]) : (extra.message || t('Bkm_Could_Not_Save', 'Could not save.'));
  if (reason === 'clash' && extra.clashes?.length) {
    text += ' ' + extra.clashes.map((c) => `${c.ref} (${c.time})`).join(', ');
  }
  if (reason === 'outside_hours' && extra.hours) {
    text += ` ${t('Bkm_Working_Hours', 'Working hours')}: ${extra.hours}`;
  }
  return text;
}

/**
 * POST bookings/{id}/{action}. On 422 with can_force, asks "Save anyway?" and
 * retries with force=1. Resolves to the updated booking, or null when the
 * user gave up / it failed (a message is shown).
 */
export async function runAction(id, action, body = {}) {
  const url = `bookings/${id}/${action}`;
  try {
    const data = await http.post(url, body);
    return data?.booking || null;
  } catch (e) {
    const d = e?.data || {};
    if (e?.status === 422 && d.can_force) {
      const ok = await new Promise((resolve) => {
        Modal.confirm({
          title: t('Bkm_Clash_Title', 'This time is not free'),
          content: reasonText(d.reason, d),
          okText: t('Bkm_Save_Anyway', 'Save anyway'),
          cancelText: t('Cancel'),
          onOk: () => resolve(true),
          onCancel: () => resolve(false),
        });
      });
      if (!ok) return null;
      try {
        const data = await http.post(url, { ...body, force: 1 });
        return data?.booking || null;
      } catch (e2) {
        message.error(reasonText(e2?.data?.reason, e2?.data || {}));
        return null;
      }
    }
    const errors = d.errors ? Object.values(d.errors)[0]?.[0] : null;
    message.error(d.reason ? reasonText(d.reason, d) : (errors || d.message || t('Bkm_Could_Not_Save', 'Could not save.')));
    return null;
  }
}

/** Time windows of a request ("morning" → "Morning"). */
export function windowLabel(key) {
  const map = { morning: ['Bkm_Morning', 'Morning'], afternoon: ['Bkm_Afternoon', 'Afternoon'], evening: ['Bkm_Evening', 'Evening'] };
  return map[key] ? t(...map[key]) : (key || '');
}
