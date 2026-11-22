import {
    Package, Boxes, Users, ReceiptText, LayoutGrid, Truck,
} from 'lucide-vue-next';
import { i18n } from '../../i18n';

const tr = (k) => i18n.global.t(k);

/**
 * Shared vocabulary for the Shopify module: the values the API stores, the
 * label each shows and the antd colour it wears. Every Shopify page reads from
 * here, so a run cannot look "failed" on one screen and "error" on another.
 */

export const STORE_STATUSES = [
    { value: 'connected', get label() { return tr('Connected'); }, color: 'success' },
    { value: 'error', get label() { return tr('Cr_Log_Error'); }, color: 'error' },
    { value: 'disconnected', get label() { return tr('Not_connected'); }, color: 'default' },
];

/**
 * The six things this module moves. `directions` is the honest list of what is
 * possible, not what is convenient: orders are pull-only because Shopify is
 * where a customer places one — pushing an ERP sale back would invent an order
 * that never happened.
 */
export const ENTITIES = [
    {
        value: 'products',
        get label() { return tr('Products'); },
        icon: Package,
        directions: ['push', 'pull'],
        get hint() { return tr('Shp_Hint_Products'); },
    },
    {
        value: 'inventory',
        get label() { return tr('Inventory'); },
        icon: Boxes,
        directions: ['push', 'pull'],
        get hint() { return tr('Shp_Hint_Inventory'); },
    },
    {
        value: 'customers',
        get label() { return tr('Customers'); },
        icon: Users,
        directions: ['push', 'pull'],
        get hint() { return tr('Shp_Hint_Customers'); },
    },
    {
        value: 'orders',
        get label() { return tr('Orders'); },
        icon: ReceiptText,
        directions: ['pull'],
        get hint() { return tr('Shp_Hint_Orders'); },
    },
    {
        value: 'collections',
        get label() { return tr('Collections'); },
        icon: LayoutGrid,
        directions: ['push', 'pull'],
        get hint() { return tr('Shp_Hint_Collections'); },
    },
    {
        value: 'fulfillments',
        get label() { return tr('Shp_Fulfillments'); },
        icon: Truck,
        directions: ['push', 'pull'],
        get hint() { return tr('Shp_Hint_Fulfillments'); },
    },
];

/**
 * What a store syncs automatically when nobody has chosen a plan. Mirrors
 * ShopifyStore::DEFAULT_AUTO_PLAN on the server — the two pair of steps every
 * shop wants and no shop is surprised by: orders in, stock out.
 */
export const DEFAULT_AUTO_PLAN = [
    { entity: 'orders', direction: 'pull' },
    { entity: 'inventory', direction: 'push' },
];

/** Every entity+direction an automatic step could be. */
export function autoStepChoices() {
    return ENTITIES.flatMap(e => e.directions.map(d => ({ entity: e.value, direction: d })));
}

export function stepKey(step) {
    return `${step.entity}:${step.direction}`;
}

/** "Orders · Pull" — short enough for a tag, clear about which way it goes. */
export function stepLabel(step) {
    return `${entityOf(step.entity).label} · ${optionOf(DIRECTIONS, step.direction).short}`;
}

export const RUN_STATUSES = [
    { value: 'pending', get label() { return tr('Pending'); }, color: 'default' },
    { value: 'running', get label() { return tr('Running'); }, color: 'processing' },
    { value: 'completed', get label() { return tr('Completed'); }, color: 'success' },
    { value: 'failed', get label() { return tr('Failed'); }, color: 'error' },
    { value: 'cancelled', get label() { return tr('Cancelled'); }, color: 'warning' },
    // Not a stored status: a run whose worker stopped sending heartbeats.
    { value: 'stale', get label() { return tr('Shp_Stalled'); }, color: 'error' },
];

export const DIRECTIONS = [
    { value: 'push', label: 'ERP → Shopify', get short() { return tr('Shp_Push'); }, color: 'blue' },
    { value: 'pull', label: 'Shopify → ERP', get short() { return tr('Shp_Pull'); }, color: 'purple' },
];

export const LOG_LEVELS = [
    { value: 'info', get label() { return tr('Info'); }, color: 'default' },
    { value: 'warning', get label() { return tr('Warning'); }, color: 'warning' },
    { value: 'error', get label() { return tr('Cr_Log_Error'); }, color: 'error' },
];

export const LINK_TYPES = [
    { value: 'product', get label() { return tr('Product'); } },
    { value: 'variant', get label() { return tr('Variant'); } },
    { value: 'inventory_item', get label() { return tr('Shp_Inventory_Item'); } },
    { value: 'customer', get label() { return tr('Customer'); } },
    { value: 'order', get label() { return tr('Order'); } },
    { value: 'collection', get label() { return tr('Collection'); } },
];

export const PRICE_FIELDS = [
    { value: 'price', get label() { return tr('Shp_Retail_Price'); } },
    { value: 'wholesale_price', get label() { return tr('Wholesale_Price'); } },
    { value: 'min_price', get label() { return tr('Shp_Minimum_Price'); } },
];

export const WEBHOOK_STATUSES = [
    { value: 'pending', get label() { return tr('Pending'); }, color: 'processing' },
    { value: 'processed', get label() { return tr('Processed'); }, color: 'success' },
    { value: 'failed', get label() { return tr('Failed'); }, color: 'error' },
    { value: 'ignored', get label() { return tr('Status_Ignored'); }, color: 'default' },
];

/**
 * Topics worth subscribing to, shown on the store's webhook tab so the values
 * can be copied into Shopify rather than remembered.
 */
export const WEBHOOK_TOPICS = [
    'orders/create',
    'orders/updated',
    'orders/cancelled',
    'orders/fulfilled',
    'products/create',
    'products/update',
    'customers/create',
    'customers/update',
    'inventory_levels/update',
];

export function optionOf(list, value) {
    return list.find(o => o.value === value) || { value, label: value || '—', color: 'default' };
}

export function labelOf(list, value) {
    return optionOf(list, value).label;
}

export function entityOf(value) {
    return ENTITIES.find(e => e.value === value) || { value, label: value, icon: Package, directions: [] };
}

/** Whether an entity can travel in a given direction at all. */
export function supports(entity, direction) {
    return entityOf(entity).directions.includes(direction);
}

/** A run is worth polling only while it might still change. */
export function isLive(status) {
    return status === 'running' || status === 'pending';
}
