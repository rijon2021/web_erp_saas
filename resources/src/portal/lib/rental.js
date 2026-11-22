/**
 * Rental helpers for the portal views (My rentals, rental invoices, requests).
 * Labels go through the view's tr(key, fallback) so a stale locale file
 * still shows English.
 */

/** Tabler tone for a contract stage (see RentalContract::stage()). */
export function rentalStageTone(stage) {
  switch (stage) {
    case 'active': return 'green';
    case 'expiring': return 'orange';
    case 'overdue': return 'red';
    case 'upcoming': return 'blue';
    case 'renewed': return 'purple';
    case 'terminated': return 'red';
    default: return 'secondary';
  }
}

export function rentalStageLabel(vm, stage, status) {
  const map = {
    active: ['rental_stage_active', 'Active'],
    upcoming: ['rental_stage_upcoming', 'Starts soon'],
    expiring: ['rental_stage_expiring', 'Ending soon'],
    overdue: ['rental_stage_overdue', 'Return overdue'],
    ended: ['rental_stage_ended', 'Ended'],
    terminated: ['rental_stage_terminated', 'Terminated'],
    renewed: ['rental_stage_renewed', 'Renewed'],
  };
  const m = map[stage] || map[status];
  return m ? vm.tr(m[0], m[1]) : (stage || status || '—');
}

export function rentalPeriodLabel(vm, period) {
  const map = {
    hour: ['rental_per_hour', 'hour'],
    day: ['rental_per_day', 'day'],
    week: ['rental_per_week', 'week'],
    month: ['rental_per_month', 'month'],
    year: ['rental_per_year', 'year'],
  };
  const m = map[period];
  return m ? vm.tr(m[0], m[1]) : (period || '');
}

/** Rental invoice status: overdue wins over unpaid/partial. */
export function rentalInvoiceTone(inv) {
  if (inv.status === 'paid') return 'green';
  if (inv.overdue) return 'red';
  if (inv.status === 'partial') return 'blue';
  return 'yellow';
}

export function rentalInvoiceLabel(vm, inv) {
  if (inv.status !== 'paid' && inv.overdue) return vm.tr('rental_overdue', 'Overdue');
  return vm.statusLabel(inv.status);
}

export function requestTone(status) {
  switch (status) {
    case 'completed': return 'green';
    case 'in_progress': return 'blue';
    case 'scheduled': return 'azure';
    case 'cancelled': return 'secondary';
    default: return 'yellow';
  }
}

export function requestLabel(vm, status) {
  const map = {
    open: ['rental_req_received', 'Received'],
    scheduled: ['rental_req_scheduled', 'Scheduled'],
    in_progress: ['rental_req_in_progress', 'Being fixed'],
    completed: ['rental_req_done', 'Fixed'],
    cancelled: ['rental_req_closed', 'Closed'],
  };
  const m = map[status];
  return m ? vm.tr(m[0], m[1]) : status;
}
