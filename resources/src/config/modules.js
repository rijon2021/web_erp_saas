// Optional business modules the admin can switch on/off in System Settings →
// Modules. `key` MUST match the entry key in config/menu.js — top-level or a
// nested child's (connector entries under Ecommerce Platforms / Integrations
// carry keys too); the sidebar filter walks every level and the router guard
// keys on it. Core commerce (products, sales, purchases, people, settings,
// reports…) is intentionally not listed: it can never be turned off.
//
// Flags live in settings.module_flags ({key: bool}) and reach the SPA on the
// get_user_auth payload (auth.user.module_flags). A null map or a missing key
// means the module is ENABLED — so existing installs see no change.

import { i18n } from '../i18n';

// label/description are getters so they follow the active locale; brand-name
// labels stay literal.
const tr = (k) => i18n.global.t(k);

export const TOGGLEABLE_MODULES = [
    { key: 'Store', get label() { return tr('Mod_Store_Ecommerce'); }, get description() { return tr('Mod_Store_Desc'); }, pathPrefixes: ['store', 'realestate'] },
    { key: 'hrm', get label() { return tr('HRM'); }, get description() { return tr('Mod_Hrm_Desc'); }, pathPrefixes: ['hrm', 'contracts'] },
    { key: 'recruit', get label() { return tr('Mod_Recruits_Jobs'); }, get description() { return tr('Mod_Recruit_Desc'); }, pathPrefixes: ['recruit'] },
    { key: 'meeting', get label() { return tr('Meetings'); }, get description() { return tr('Mod_Meeting_Desc'); }, pathPrefixes: ['meeting', 'meetings'] },
    { key: 'marketing', get label() { return tr('Marketing'); }, get description() { return tr('Mod_Marketing_Desc'); }, pathPrefixes: [] },
    { key: 'accounting', get label() { return tr('Accounting'); }, get description() { return tr('Mod_Accounting_Desc'); }, pathPrefixes: ['accounting-v2'] },
    { key: 'EWallet', get label() { return tr('E_Wallet'); }, get description() { return tr('Mod_EWallet_Desc'); }, pathPrefixes: ['ewallet'] },
    { key: 'commissions', get label() { return tr('Commissions'); }, get description() { return tr('Mod_Commissions_Desc'); }, pathPrefixes: ['commissions'] },
    { key: 'promotions', get label() { return tr('Promotions'); }, get description() { return tr('Mod_Promotions_Desc'); }, pathPrefixes: ['promotions'] },
    { key: 'woocommerce_settings', label: 'WooCommerce', get description() { return tr('Mod_Woocommerce_Desc'); }, pathPrefixes: [] },
    { key: 'shopify', label: 'Shopify', get description() { return tr('Mod_Shopify_Desc'); }, pathPrefixes: ['shopify'] },
    { key: 'salla', label: 'Salla', get description() { return tr('Mod_Salla_Desc'); }, pathPrefixes: [] },
    { key: 'jumia', label: 'Jumia', get description() { return tr('Mod_Jumia_Desc'); }, pathPrefixes: [] },
    { key: 'printful', label: 'Printful', get description() { return tr('Mod_Printful_Desc'); }, pathPrefixes: [] },
    { key: 'couriers', get label() { return tr('Mod_Courier_Integration'); }, get description() { return tr('Mod_Couriers_Desc'); }, pathPrefixes: ['couriers'] },
    { key: 'prestashop', label: 'PrestaShop', get description() { return tr('Mod_Prestashop_Desc'); }, pathPrefixes: [] },
    { key: 'opencart', label: 'OpenCart', get description() { return tr('Mod_Opencart_Desc'); }, pathPrefixes: [] },
    { key: 'zapier', label: 'Zapier', get description() { return tr('Mod_Zapier_Desc'); }, pathPrefixes: [] },
    { key: 'documents', get label() { return tr('Document_Archive'); }, get description() { return tr('Mod_Documents_Desc'); }, pathPrefixes: ['documents'] },
    { key: 'subscription_product', get label() { return tr('Mod_Subscription_Products'); }, get description() { return tr('Mod_Subscription_Desc'); }, pathPrefixes: ['subscriptions'] },
    // Key matches the sidebar group key ('mrp' in config/menu.js), otherwise the
    // toggle would block the routes but leave the group visible in the sidebar.
    { key: 'mrp', get label() { return tr('Mrp_Manufacturing'); }, get description() { return tr('Mod_Mrp_Desc'); }, pathPrefixes: ['mrp'] },
    { key: 'assets', get label() { return tr('Asset_Management'); }, get description() { return tr('Mod_Assets_Desc'); }, pathPrefixes: ['assets'] },
    { key: 'projects', get label() { return tr('Mod_Projects_Tasks'); }, get description() { return tr('Mod_Projects_Desc'); }, pathPrefixes: ['projects', 'tasks'] },
    { key: 'bookings', get label() { return tr('Booking Management'); }, get description() { return tr('Mod_Bookings_Desc'); }, pathPrefixes: ['bookings'] },
    { key: 'service', get label() { return tr('Service_Maintenance'); }, get description() { return tr('Mod_Service_Desc'); }, pathPrefixes: ['service'] },
    { key: 'fleet', get label() { return tr('Fl_Fleet_Management'); }, get description() { return tr('Mod_Fleet_Desc'); }, pathPrefixes: ['fleet'] },
    { key: 'hospital', get label() { return tr('Hm_Hospital_Management'); }, get description() { return tr('Mod_Hospital_Desc'); }, pathPrefixes: ['hospital'] },
    { key: 'school', get label() { return tr('Sc_School_Management'); }, get description() { return tr('Mod_School_Desc'); }, pathPrefixes: [] },
    // Keys also gate the backend side: store / portal lead capture (crm) and
    // the client-portal rentals (rental) read settings.module_flags.
    { key: 'rental', get label() { return tr('Rental_Management'); }, get description() { return tr('Mod_Rental_Desc'); }, pathPrefixes: ['rental'] },
    { key: 'restaurant', get label() { return tr('Restaurant_Management'); }, get description() { return tr('Mod_Restaurant_Desc'); }, pathPrefixes: ['restaurant'] },
    { key: 'crm', get label() { return tr('Crm_Management'); }, get description() { return tr('Mod_Crm_Desc'); }, pathPrefixes: ['crm'] },
];

const MODULE_KEYS = new Set(TOGGLEABLE_MODULES.map(m => m.key));

// First URL segment -> module key, for the router guard.
const PREFIX_TO_MODULE = {};
for (const m of TOGGLEABLE_MODULES) {
    for (const p of m.pathPrefixes) PREFIX_TO_MODULE[p] = m.key;
}

/** True when `key` is enabled under the given flags map (null map = all on). */
export function isModuleEnabled(flags, key) {
    if (!MODULE_KEYS.has(key)) return true; // not toggleable -> always on
    if (!flags || typeof flags !== 'object') return true;
    return flags[key] !== false;
}

/** Module key owning an SPA path ('/hospital/patients' -> 'hospital'), or null. */
export function moduleKeyForPath(path) {
    const seg = String(path || '').replace(/^\/+/, '').split('/')[0];
    return PREFIX_TO_MODULE[seg] || null;
}
