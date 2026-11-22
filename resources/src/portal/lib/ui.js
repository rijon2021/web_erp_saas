/**
 * Small presentation helpers shared by the portal views (Tabler classes).
 */
import { stripZeroFraction, withCurrency } from '../../lib/priceFormat';

export function brand() {
  const b = (typeof window !== 'undefined' && window.__PORTAL_APP__) || {};
  return {
    name: (b && b.name) || 'Client Portal',
    logo: (b && b.logo) || '',
    currency: (b && b.currency) || '',
    // System Settings → Currency Position ('before' | 'before_nospace' | 'after' | 'after_nospace').
    currencyPosition: (b && b.currency_position) || 'before',
    // Enable 3 Decimal Pricing (2 | 3) and Hide Zero Decimals (100.00 shown as 100).
    priceDecimals: Number(b && b.price_decimals) === 3 ? 3 : 2,
    hideZeroDecimals: !!(b && b.hide_zero_decimals),
  };
}

export function money(n, dash = false) {
  const b = brand();
  const dec = b.priceDecimals;
  if (n == null || n === '') return dash ? '—' : (b.hideZeroDecimals ? '0' : (0).toFixed(dec));
  let formatted = Number(n).toLocaleString('en-US', { minimumFractionDigits: dec, maximumFractionDigits: dec });
  if (b.hideZeroDecimals) formatted = stripZeroFraction(formatted, '.');
  return withCurrency(b.currency, formatted, b.currencyPosition);
}

export function number(n) {
  return Number(n || 0).toLocaleString('en-US');
}

/** Tabler tone (green/blue/yellow/red/…) for a status; `badge bg-<tone>-lt`. */
export function invoiceTone(status) {
  const v = (status || '').toLowerCase();
  if (v === 'paid' || v === 'completed') return 'green';
  if (v === 'partial') return 'blue';
  return 'yellow';
}
export function quotationTone(status) {
  const v = (status || '').toLowerCase();
  if (v === 'approved' || v === 'accepted' || v === 'completed') return 'green';
  if (v === 'rejected' || v === 'cancelled') return 'red';
  return 'yellow';
}
export function appointmentTone(status) {
  const v = (status || '').toLowerCase();
  if (v === 'completed' || v === 'delivered' || v === 'ready') return 'green';
  if (v === 'in_progress' || v === 'approved') return 'blue';
  if (v === 'cancelled' || v === 'declined') return 'red';
  return 'yellow';
}
export function contractTone(status) {
  const v = (status || '').toLowerCase();
  if (v === 'active' || v === 'signed') return 'green';
  if (v === 'expired' || v === 'cancelled' || v === 'terminated') return 'red';
  return 'yellow';
}

/** Protocol & Requests (SaaS): open → received → … → approved / rejected / closed. */
export function protocolTone(status) {
  const v = (status || '').toLowerCase();
  if (v === 'approved') return 'green';
  if (v === 'rejected') return 'red';
  if (v === 'closed') return 'secondary';
  if (v === 'in_progress' || v === 'acknowledged') return 'blue';
  if (v === 'received') return 'azure';
  return 'yellow';
}

export const badge = (tone) => `badge bg-${tone}-lt`;

export function apiError(e, fallback) {
  const data = e && e.response && e.response.data ? e.response.data : e;
  return (data && data.message) || (e && e.message) || fallback;
}

export function initials(name) {
  return String(name || '')
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((w) => w.charAt(0).toUpperCase())
    .join('') || '?';
}
