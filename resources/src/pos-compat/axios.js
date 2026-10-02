/**
 * Axios-shaped adapter over lib/http for the POS port: the 6k-line legacy
 * script calls `axios.get(url).then(r => r.data)` everywhere, so responses
 * are wrapped back into `{ status, data }` (http.raw keeps the HTTP status,
 * which offline-sync callers read as `response.status`). Supports the config
 * subset POS uses:
 * { params }, { responseType: 'blob' | 'text' }, { timeout } (ignored — fetch
 * has no trivial timeout and the legacy value was advisory).
 */
import http from '../lib/http';

function csrfHeaders() {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);
    return match ? { 'X-XSRF-TOKEN': decodeURIComponent(match[1]) } : {};
}

// Non-JSON GETs: PDF blobs and the A4 invoice HTML (`sale_print_html/{id}`).
// http.raw always calls res.json(), which throws on an HTML body, so these
// bypass it and read the body in the requested shape.
async function rawGet(url, responseType) {
    const res = await fetch('/api/' + url.replace(/^\//, ''), {
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest', ...csrfHeaders() },
    });
    if (!res.ok) {
        const err = new Error(`HTTP ${res.status}`);
        err.response = { status: res.status };
        throw err;
    }
    const data = responseType === 'text' ? await res.text() : await res.blob();
    return { status: res.status, data };
}

function wrapError(e) {
    // Legacy reads error.response.data.* — mirror that shape.
    e.response = e.response || { status: e.status, data: e.data };
    throw e;
}

export default {
    async get(url, config = {}) {
        if (config.responseType === 'blob' || config.responseType === 'text') {
            return rawGet(url, config.responseType);
        }
        try {
            return await http.raw('GET', url, { params: config.params });
        } catch (e) { wrapError(e); }
    },
    async post(url, body, config = {}) {
        try {
            return body instanceof FormData
                ? await http.raw('POST', url, { formData: body })
                : await http.raw('POST', url, { body });
        } catch (e) { wrapError(e); }
    },
    async put(url, body) {
        try {
            return await http.raw('PUT', url, { body });
        } catch (e) { wrapError(e); }
    },
    async delete(url) {
        try {
            return await http.raw('DELETE', url);
        } catch (e) { wrapError(e); }
    },
};
