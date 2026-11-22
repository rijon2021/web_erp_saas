import { ref, computed } from 'vue';
import http from '../lib/http';
import { i18n } from '../i18n';

/**
 * Customer Wallet helpers shared by the admin payment forms and wallet pages.
 *
 * Paying (or refunding) from the wallet = choosing the payment method whose id
 * the server reports as `payment_method_id`; the backend debits/credits the
 * wallet and ignores the account for that method. GET customer_wallets/{id}/
 * summary always answers ({ enabled: false } when the feature is off), so
 * forms can call it without knowing whether the wallet exists.
 *
 *   const cw = useCustomerWalletTender();
 *   await cw.load(clientId);
 *   cw.isWalletMethod(form.payment_method_id)  // hide the account select
 */
export function useCustomerWalletTender() {
    // { enabled, balance, status, allow_negative, payment_method_id } | null
    const info = ref(null);
    let seq = 0;

    async function load(clientId) {
        const mine = ++seq;
        // Never show the previous customer's balance while the new one loads.
        info.value = null;
        if (!clientId) {
            return null;
        }
        try {
            const data = await http.get(`customer_wallets/${clientId}/summary`);
            if (mine === seq) info.value = data || null;
        } catch (e) {
            if (mine === seq) info.value = null;
        }
        return info.value;
    }

    function reset() {
        seq++;
        info.value = null;
    }

    const enabled = computed(() => !!info.value?.enabled && info.value?.payment_method_id != null);
    const balance = computed(() => Number(info.value?.balance) || 0);
    const frozen = computed(() => info.value?.status === 'frozen');

    function isWalletMethod(methodId) {
        if (!enabled.value || methodId == null || methodId === '') return false;
        return String(methodId) === String(info.value.payment_method_id);
    }

    return { info, enabled, balance, frozen, load, reset, isWalletMethod };
}

/** Every wallet_transactions.source a customer wallet can carry. */
export const CW_SOURCES = [
    'top_up', 'refund', 'adjustment', 'sale_payment', 'checkout', 'return_credit',
    'order_refund', 'gift_card', 'withdrawal', 'opening_balance_payment', 'reversal',
];

function translated(key, fallback) {
    const txt = i18n.global.t(key);
    return txt && txt !== key ? txt : fallback;
}

/** CW_Src_<source>, else the raw slug made readable. */
export function walletSourceLabel(source) {
    if (!source) return '-';
    return translated('CW_Src_' + source, String(source).replace(/_/g, ' '));
}

/** CW_Kind_<reference_kind> (Sale, SaleReturn, OnlineOrder…). */
export function walletKindLabel(kind) {
    if (!kind) return '';
    return translated('CW_Kind_' + kind, kind);
}

/** In-app page for a transaction's reference, when one exists. */
export function walletReferenceRoute(tx) {
    const id = tx?.reference_id;
    if (!id) return null;
    switch (tx.reference_kind) {
        case 'Sale': return `/sales/${id}`;
        case 'SaleReturn': return `/sale-returns/${id}`;
        case 'OnlineOrder': return `/store/orders/${id}`;
        default: return null;
    }
}

/**
 * Wallet refusals arrive as HTTP 422 { success:false, message, code }. Map the
 * code to its CW_Err_<code> translation, falling back to the server's English
 * message, then to `fallbackKey`.
 */
export function customerWalletError(e, fallbackKey = 'InvalidData') {
    const t = i18n.global.t;
    const code = e?.data?.code;
    if (code) {
        const key = 'CW_Err_' + code;
        const txt = t(key);
        if (txt && txt !== key) return txt;
    }
    return e?.data?.message || t(fallbackKey);
}
