import { defineStore } from 'pinia';
import { MENU } from '../config/menu';
import { MENU_EXTRA } from '../config/menuExtra';
import { applyMenuOrder } from '../lib/menuOrder';
import { useAuthStore } from './auth';
import { usePlanStore } from './plan';

// Where a locked module points: Billing → Add-ons, opened on that add-on.
const ADDONS_PATH = '/app/billing/addons';

/**
 * A module the plan lacks but the tenant may buy as an add-on: the entry (a
 * whole group or a single link) collapses into one link with a lock icon to
 * Billing → Add-ons, instead of disappearing. It carries no `feature`, so the
 * sidebars' plan filter lets it through; shown only to people who may buy.
 */
function lockedEntry(entry) {
    const { children, feature, permissions, ...rest } = entry;
    return {
        ...rest,
        icon: 'lock',
        to: `${ADDONS_PATH}?feature=${encodeURIComponent(feature)}`,
        permissions: [],
        locked: true,
    };
}

function lockPurchasable(entries, plan) {
    return entries.map((entry) => {
        if (entry.feature && !plan.featureEnabled(entry.feature) && plan.featurePurchasable(entry.feature)) {
            return lockedEntry(entry);
        }
        return entry.children ? { ...entry, children: lockPurchasable(entry.children, plan) } : entry;
    });
}

/**
 * The navigation actually rendered by the sidebars: the default MENU arranged
 * per the admin-saved order (Settings → Sidebar Menu). The saved order arrives
 * with get_user_auth (auth.user.sidebar_menu_order), so this recomputes both at
 * boot and live when the manager saves a new arrangement.
 */
export const useMenuStore = defineStore('menu', {
    getters: {
        menu() {
            const auth = useAuthStore();
            const order = auth.user && auth.user.sidebar_menu_order;
            let arranged = MENU.concat(MENU_EXTRA);
            if (Array.isArray(order) && order.length) {
                try {
                    arranged = applyMenuOrder(order);
                } catch (e) {
                    // A malformed saved order must never take the navigation down.
                    arranged = MENU;
                }
            }
            // Drop entries whose module the admin switched off (Settings →
            // Modules). Recomputes live when the toggles save. Applied at
            // every level: connector entries (WooCommerce, Shopify, Zapier…)
            // now live nested under the Integrations sections but keep a
            // `key` matching config/modules.js. Entries without a key are
            // never toggleable and always pass.
            // Billing → Add-ons only while the marketplace is on (Super admin →
            // Settings → Features); an older summary without the flag keeps it.
            const plan = usePlanStore();
            const addonsOn = !(plan.summary && plan.summary.addons_enabled === false);
            const dropDisabled = entries => entries
                .filter(entry => auth.moduleEnabled(entry.key))
                .filter(entry => addonsOn || entry.key !== 'billing_addons')
                .map(entry => (entry.children
                    ? { ...entry, children: dropDisabled(entry.children) }
                    : entry));
            const visible = dropDisabled(arranged);
            // Locked-but-buyable modules, for the owner and billing users only
            // (the same people the Add-ons page lets in).
            const mayBuy = auth.user && (Number(auth.user.id) === 1 || auth.can('billing_view'));
            return mayBuy ? lockPurchasable(visible, usePlanStore()) : visible;
        },
    },
});
