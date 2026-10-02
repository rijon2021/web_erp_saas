/**
 * Tenant-aware public URL for an uploaded file.
 *
 * Uploads live under a per-tenant folder — `upload_path()` on the server,
 * exposed to the SPA as window.__uploadPath by layouts/next.blade.php (the
 * legacy app read the same value into Vue.prototype.$uploadPath / $imgUrl).
 * On a single-tenant install it is plain "images".
 *
 * Every page copied from the stocky reference hard-codes '/images/<folder>/…';
 * ALL of them must go through this helper instead (migration rule 9).
 *
 *   uploadUrl('brands', row.image)       -> /<tenant-path>/brands/<file>
 *   uploadUrl('avatar', user.avatar)     -> /<tenant-path>/avatar/<file>
 *   uploadUrl('', settings.logo)         -> /<tenant-path>/<file>
 */
export function uploadPath() {
    const p = typeof window !== 'undefined' && window.__uploadPath ? String(window.__uploadPath) : 'images';
    return p.replace(/^\/+|\/+$/g, '');
}

export function uploadUrl(folder, file) {
    if (!file) return '';
    // Absolute, data: and root-relative URLs (e.g. a server-resolved logo URL
    // from receipt_logo_url()) are already complete.
    if (/^(https?:)?\/\//.test(file) || String(file).startsWith('data:') || String(file).startsWith('/')) return file;
    const parts = [uploadPath()];
    if (folder) parts.push(String(folder).replace(/^\/+|\/+$/g, ''));
    parts.push(String(file).replace(/^\/+/, ''));
    return '/' + parts.join('/');
}

export default uploadUrl;

/**
 * URL for a path that is ALREADY stored relative to /public (e.g.
 * "images/tenants/<id>/properties/x.jpg" or a seeded CDN URL). Unlike
 * uploadUrl() it never prepends the tenant folder — it only normalises:
 * absolute / data: / root-relative values pass through, anything else gets a
 * single leading slash. Returns '' for empty input.
 */
export function publicUrl(path) {
    if (!path) return '';
    const p = String(path);
    if (/^(https?:)?\/\//.test(p) || p.startsWith('data:') || p.startsWith('/')) return p;
    return '/' + p.replace(/^\/+/, '');
}

/**
 * <img @error> handler: swap in the shared placeholder instead of a broken
 * icon. It deliberately keeps no per-element state (Vue reuses <img> nodes
 * across table rows / gallery tiles), and it bails when the placeholder
 * itself is what failed so it can never loop.
 */
export const IMAGE_FALLBACK = '/images/no-image.png';
export function onImageError(e) {
    const el = e && e.target;
    if (!el || !el.src || el.src.endsWith(IMAGE_FALLBACK)) return;
    el.src = IMAGE_FALLBACK;
}
