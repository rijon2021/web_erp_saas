/** Customer-facing progress of a CRM request (see PortalRequestsController::STEPS). */
export const REQUEST_STEPS = ['received', 'in_progress', 'quoted', 'accepted'];

export const STEP_FALLBACK = {
  received: 'Request received',
  in_progress: 'In progress',
  quoted: 'Quotation ready',
  accepted: 'Accepted',
};
