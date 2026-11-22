import { reactive } from 'vue';

/**
 * What the business switched on in Client Portal → Portal Settings, as sent by
 * GET /portal/me (`portal_settings`). Filled by PortalLayout's loadMe(); until
 * then everything counts as on, so nothing flickers away for the common case.
 * The API enforces the same switches — this only keeps the UI from offering
 * what would be refused.
 */
export const portalSettings = reactive({
  loaded: false,
  modules: {},
  actions: {},
  welcome_message: '',
});

export function applyPortalSettings(data) {
  if (!data) return;
  portalSettings.modules = data.modules || {};
  portalSettings.actions = data.actions || {};
  portalSettings.welcome_message = data.welcome_message || '';
  portalSettings.loaded = true;
}

export const moduleOn = (key) => portalSettings.modules[key] !== false;
export const actionOn = (key) => portalSettings.actions[key] !== false;

/**
 * Module that owns a portal path, e.g. '/invoices/12' → 'invoices'. SaaS:
 * 'requests' is Protocol & Requests, 'deals' the CRM (stocky's "requests").
 * The server already narrows `modules` by the tenant's plan.
 */
const PATH_MODULES = {
  invoices: 'invoices',
  payments: 'payments',
  statement: 'statement',
  requests: 'requests',
  deals: 'deals',
  quotations: 'quotations',
  appointments: 'appointments',
  bookings: 'bookings',
  contracts: 'contracts',
  rentals: 'rentals',
  'rental-invoices': 'rentals',
  'rental-requests': 'rentals',
  help: 'help',
};

export function moduleForPath(path) {
  const first = String(path || '').split('/').filter(Boolean)[0];
  return PATH_MODULES[first] || null;
}

export const pathAllowed = (path) => {
  const m = moduleForPath(path);
  if (!m) return true;
  if (!moduleOn(m)) return false;
  if (path === '/quotations/new') return actionOn('quotation_requests');
  if (path === '/appointments/new') return actionOn('appointment_booking');
  return true;
};
