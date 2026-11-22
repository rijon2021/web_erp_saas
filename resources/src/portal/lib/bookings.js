/**
 * Booking Management in the client portal: labels and tones shared by the
 * list and the detail page. `vm` is the component (for tr()); every string
 * has an English fallback until the tenant's portal language has it.
 */

const STAGES = {
  pending: ['bookings_stage_pending', 'Waiting for confirmation'],
  requested: ['bookings_stage_requested', 'Request sent'],
  confirmed: ['bookings_stage_confirmed', 'Confirmed'],
  completed: ['bookings_stage_completed', 'Completed'],
  declined: ['bookings_stage_declined', 'Not available'],
  cancelled: ['bookings_stage_cancelled', 'Cancelled'],
  no_show: ['bookings_stage_no_show', 'Missed'],
};

const WINDOWS = {
  morning: ['bookings_window_morning', 'Morning'],
  afternoon: ['bookings_window_afternoon', 'Afternoon'],
  evening: ['bookings_window_evening', 'Evening'],
};

/** vm.tr() with {params}; the English fallback gets the same substitution. */
export function trp(vm, key, fallback, params) {
  const out = vm.tr(key, null, params);
  if (out) return out;
  return Object.entries(params || {}).reduce((s, [k, v]) => s.split(`{${k}}`).join(String(v)), fallback);
}

export function bookingStageLabel(stage, vm) {
  const s = STAGES[stage];
  return s ? vm.tr(s[0], s[1]) : stage;
}

export function bookingStageTone(stage) {
  if (stage === 'confirmed' || stage === 'completed') return 'green';
  if (stage === 'declined' || stage === 'cancelled') return 'red';
  if (stage === 'no_show') return 'secondary';
  return 'yellow';
}

/** "Mon 3 Nov 2026, 10:00 – 10:45" or "Mon 3 Nov 2026 · Morning" for a request without a time. */
export function bookingWhen(b, vm) {
  if (!b || !b.date) return '—';
  const d = new Date(`${b.date}T00:00:00`);
  const day = isNaN(d) ? b.date : d.toLocaleDateString(undefined, { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' });
  if (!b.time) {
    const w = WINDOWS[b.window];
    return w ? `${day} · ${vm.tr(w[0], w[1])}` : day;
  }
  return `${day}, ${b.time}${b.end_time ? ` – ${b.end_time}` : ''}`;
}
