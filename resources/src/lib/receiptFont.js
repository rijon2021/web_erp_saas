/**
 * Custom POS receipt font (Settings → System → POS Receipt).
 *
 * Print popups load only /css/pos_print.css, which flattens every element to
 * 12px at print time with element selectors — so a custom size/family must be
 * forced with !important to win. Inter (the pre-Vue3 admin font), Ubuntu (the
 * legacy pos_print.css stack) and Roboto are self-hosted under /fonts via
 * /css/receipt-fonts.css — no external request, POS keeps printing offline;
 * anything else falls back through its CSS stack to installed fonts.
 */

const LOCAL_FONTS = ['Inter', 'Ubuntu', 'Roboto'];
const LOCAL_FONTS_CSS = '/css/receipt-fonts.css';

// Keep to a charset safe for injection into <style> tags.
export function sanitizeFontFamily(value) {
  return String(value || '').trim().replace(/[^a-zA-Z0-9 ,'\-]/g, '');
}

/**
 * Head markup (<link> + <style>) for a print window. `scope` is the selector
 * the receipt lives under ('#invoice-POS' or 'body' depending on whether the
 * popup recreates the wrapper). Returns '' when both settings are default.
 */
export function receiptFontHeadTags(posSettings, scope = '#invoice-POS') {
  const ps = posSettings || {};
  const family = sanitizeFontFamily(ps.receipt_font_family);
  const size = Number(ps.receipt_font_size);
  let tags = '';
  if (LOCAL_FONTS.some(name => family.includes(name))) {
    tags += `<link rel="stylesheet" href="${LOCAL_FONTS_CSS}">`;
  }
  let css = '';
  if (family) css += `${scope}, ${scope} * { font-family: ${family} !important; }`;
  if (size >= 8 && size <= 24) css += `${scope}, ${scope} * { font-size: ${size}px !important; line-height: 1.5 !important; }`;
  if (css) tags += `<style>${css}</style>`;
  return tags;
}

/**
 * Normalize the configured receipt roll width (58 / 80 / 88 mm).
 */
export function receiptPaperSize(posSettings) {
  const n = Number((posSettings || {}).receipt_paper_size);
  return [58, 80, 88].includes(n) ? n : 80;
}

/**
 * <style> tag that sizes the printed page to the receipt roll. Without an
 * explicit `@page size` the browser falls back to the printer's default
 * sheet (A4/Letter), so the 80mm receipt previews and prints on a full A4
 * page even when Settings → POS → Invoice format is "thermal". @page cannot
 * be scoped by a body class, so each print document injects its own.
 */
export function receiptPageHeadTag(posSettings) {
  const mm = receiptPaperSize(posSettings);
  return `<style>@page { size: ${mm}mm auto; margin: 4mm; }</style>`;
}

/**
 * Fire `fn` once the popup's webfonts have applied (or after `fallbackMs` if
 * fonts.ready never resolves). Calling print() on a bare timer can snapshot
 * the page before the woff2 loads, printing the fallback font instead of the
 * one chosen in Settings → POS Receipt.
 */
export function whenPrintFontsReady(win, fn, fallbackMs = 1200) {
  let done = false;
  const once = () => { if (!done) { done = true; fn(); } };
  try {
    const fonts = win.document && win.document.fonts;
    if (fonts && fonts.ready && typeof fonts.ready.then === 'function') {
      fonts.ready.then(() => setTimeout(once, 50));
    }
  } catch (e) { /* fall through to the timer */ }
  setTimeout(once, fallbackMs);
}

/**
 * Fire `fn` once the popup can be snapshotted: webfonts applied, the print
 * stylesheet parsed AND every <img> (company/branch logo, QR data-URLs)
 * loaded or errored — with a hard ceiling so printing never hangs.
 *
 * A fonts-only wait calls print() ~150ms after document.write(). Chrome does
 * not wait for pending images at print() time, so a logo that is not in the
 * browser cache yet (first print after upload, slow hosting, a new device)
 * prints as an empty box. The mobile iframe path already gated on images;
 * every window.open() receipt popup goes through this instead.
 */
export function whenPrintReady(win, fn, fallbackMs = 3000) {
  let done = false;
  const once = () => { if (!done) { done = true; fn(); } };
  let doc = null;
  try { doc = win.document; } catch (e) { doc = null; }
  if (!doc) { setTimeout(once, 0); return; }

  const fonts = new Promise((resolve) => whenPrintFontsReady(win, resolve, Math.min(fallbackMs, 1200)));

  const css = new Promise((resolve) => {
    let links = [];
    try { links = Array.from(doc.querySelectorAll('link[rel="stylesheet"]')); } catch (e) {}
    let pending = 0;
    links.forEach((link) => {
      try { if (link.sheet) return; } catch (e) {}
      pending++;
      const settle = () => { pending--; if (pending <= 0) resolve(); };
      link.addEventListener('load', settle, { once: true });
      link.addEventListener('error', settle, { once: true });
    });
    if (pending === 0) resolve();
  });

  const images = new Promise((resolve) => {
    let imgs = [];
    try { imgs = Array.from(doc.images || []); } catch (e) {}
    let pending = 0;
    imgs.forEach((img) => {
      if (img.complete) return;
      pending++;
      const settle = () => { pending--; if (pending <= 0) resolve(); };
      img.addEventListener('load', settle, { once: true });
      img.addEventListener('error', settle, { once: true });
    });
    if (pending === 0) resolve();
  });

  Promise.all([fonts, css, images]).then(once, once);
  setTimeout(once, fallbackMs);
}

/**
 * SaaS: the tenant-selected dashboard font (Settings → Appearance) also applies
 * to print documents. Popups/iframes do NOT inherit the parent page fonts, so
 * the Google Fonts <link> (curated families only — the server whitelists the
 * name) and the font-family override are re-declared inside each document.
 * window.__tenantFont / __tenantFontStack are set by partials/tenant-font,
 * which layouts/next.blade.php includes. Returns "" when the tenant kept the
 * default font. Emit it BEFORE receiptFontHeadTags() so an explicit POS
 * receipt font (when the API supports it) still wins.
 */
export function tenantFontHeadTags(scope = "#invoice-POS") {
  try {
    const stack = (typeof window !== "undefined" && window.__tenantFontStack) ? String(window.__tenantFontStack) : "";
    if (!stack) return "";
    const gf = (typeof window !== "undefined" && window.__tenantFont) ? String(window.__tenantFont) : "";
    const link = gf
      ? '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=' + gf.replace(/s+/g, "+") + ':wght@400;500;600;700&display=swap">'
      : "";
    return link + "<style>" + scope + ", " + scope + " * { font-family: " + stack + " !important; }</style>";
  } catch (e) { return ""; }
}
