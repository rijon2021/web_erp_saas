/**
 * CODE128 barcode of the invoice reference as an inline SVG string, for the
 * POS receipt Layout 6 (Tabular) footer. An inline <svg> survives the
 * innerHTML clone into the print popup (a <canvas> would print blank), so the
 * same string is used by PosPage.vue, PosReceipt.vue's demo and
 * lib/posReceiptHtml.js.
 */
import JsBarcode from 'jsbarcode';

const SVG_NS = 'http://www.w3.org/2000/svg';

export function refBarcodeSvg(value) {
  const text = String(value ?? '').trim();
  if (!text || typeof document === 'undefined') return '';
  try {
    const svg = document.createElementNS(SVG_NS, 'svg');
    JsBarcode(svg, text, {
      format: 'CODE128',
      width: 2,
      height: 60,
      margin: 0,
      displayValue: false,
      background: '#ffffff',
      lineColor: '#000000',
    });
    // Stretch to the receipt width: bar ratios stay intact horizontally, so
    // the code still scans at any paper width.
    const w = svg.getAttribute('width');
    const h = svg.getAttribute('height');
    if (!svg.getAttribute('viewBox') && w && h) {
      svg.setAttribute('viewBox', `0 0 ${parseFloat(w)} ${parseFloat(h)}`);
    }
    svg.removeAttribute('width');
    svg.removeAttribute('height');
    svg.removeAttribute('style');
    svg.setAttribute('preserveAspectRatio', 'none');
    svg.setAttribute('class', 'r6-barcode-svg');
    return svg.outerHTML;
  } catch (e) {
    return '';
  }
}
