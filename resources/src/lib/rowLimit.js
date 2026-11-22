/**
 * Row limit for loading a whole list at once.
 *
 * "All" and the list exports make PHP hold every row in memory, and past
 * roughly 50,000 rows that request dies with a server error. Above
 * BIG_LIST_ROWS the UI stops offering it and says why. Real paging keeps working
 * at any size: only the load-everything requests are stopped here.
 *
 * Open lists report their filtered row count, so http.js can refuse a
 * limit=-1 export in one place instead of in each of the export buttons. Only
 * lists on screen are tracked: a small dropdown lookup on another page that
 * happens to use the same endpoint must never be refused. No imports on
 * purpose, http.js is shared with the portal and customer display bundles.
 */
export const BIG_LIST_ROWS = 20000;

const openLists = new Map(); // endpoint -> filtered row count
let onRefused = null;

const normalize = url => String(url).replace(/^\//, '');

export function noteListTotal(endpoint, total) {
    openLists.set(normalize(endpoint), Number(total) || 0);
}

export function forgetList(endpoint) {
    openLists.delete(normalize(endpoint));
}

export function onBigExportRefused(handler) {
    onRefused = handler;
}

/** True when `total` rows is too many to export; tells the user why. */
export function refuseBigExport(total) {
    if (!(Number(total) > BIG_LIST_ROWS)) return false;
    if (onRefused) onRefused();
    return true;
}

/** Same check for a limit=-1 request on a list that is on screen. */
export function refuseAllRows(url) {
    return refuseBigExport(openLists.get(normalize(url)));
}
