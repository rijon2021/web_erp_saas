import { ref } from 'vue';
import { t as tf } from '../../i18n';

/**
 * Small shared helpers for the billing pages (legacy formatDate/capitalize +
 * the currency symbol every billing endpoint returns as `currency_symbol`).
 */
export function useBillingFormat() {
    const currencySymbol = ref('$');

    const fmtDate = d => (d ? new Date(d).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' }) : '—');
    const capitalize = s => (s ? s.charAt(0).toUpperCase() + s.slice(1) : '');
    const cycleLabel = c => (c === 'yearly' ? tf('Yearly', 'Yearly') : tf('Monthly', 'Monthly'));
    // Word-like symbols (F CFA, KSh, GH₵…) read better with a space before the
    // amount; whole amounts drop ".00" (multi-currency pricing: 6,000 F CFA).
    const money = (amount, currency) => {
        const n = Number(amount) || 0;
        const sym = currencySymbol.value || '';
        const num = Math.abs(n - Math.round(n)) < 0.005 ? Math.round(n).toLocaleString('en-US') : n.toFixed(2);
        const gap = /[A-Za-z]$/.test(sym) ? ' ' : '';
        return `${sym}${gap}${num}${currency ? ' ' + currency : ''}`;
    };
    const statusColor = s => ({
        active: 'success', paid: 'success', trial: 'processing', pending: 'warning',
        cancelled: 'default', expired: 'error', failed: 'error', refunded: 'default',
    }[s] || 'default');

    return { currencySymbol, fmtDate, capitalize, cycleLabel, money, statusColor };
}
