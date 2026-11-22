// qrcodejs on demand, same source chain as Sales.vue / PosPage (CDN →
// repaired local copy → setup copy). Resolves either way; check window.QRCode.
let qrLibPromise = null;

export function loadQrLib() {
  if (window.QRCode) return Promise.resolve();
  if (qrLibPromise) return qrLibPromise;
  const sources = [
    'https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js',
    '/vendor/qrcode/qrcode.min.js?v=full-3',
    '/assets_setup/js/qrcode.js',
  ];
  qrLibPromise = new Promise(resolve => {
    const tryNext = i => {
      if (i >= sources.length || window.QRCode) return resolve();
      const el = document.createElement('script');
      el.src = sources[i];
      el.onload = () => (window.QRCode ? resolve() : tryNext(i + 1));
      el.onerror = () => tryNext(i + 1);
      document.head.appendChild(el);
    };
    tryNext(0);
  }).then(() => { if (!window.QRCode) qrLibPromise = null; });
  return qrLibPromise;
}

/** Draws `text` as a QR code inside `el`. Returns false when the library is unavailable. */
export async function renderQr(el, text, size = 180) {
  await loadQrLib();
  if (!el || !window.QRCode) return false;
  el.innerHTML = '';
  try {
    new window.QRCode(el, {
      text: String(text),
      width: size,
      height: size,
      correctLevel: window.QRCode.CorrectLevel ? window.QRCode.CorrectLevel.M : 0,
    });
    return true;
  } catch (e) {
    return false;
  }
}
