/**
 * Printable shipping labels for couriers that don't issue their own airway
 * bill (manual couriers, AfterShip / 17TRACK). One 100×150 mm label per page:
 * sender, receiver, COD amount, tracking barcode. Data comes from
 * GET couriers/shipments/{id}/label (type 'stocky') or POST couriers/shipments/labels.
 */
import JsBarcode from 'jsbarcode';

const esc = v => String(v ?? '')
  .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
  .replace(/"/g, '&quot;').replace(/'/g, '&#39;');

function barcodeSvg(value) {
  if (!value) return '';
  const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
  try {
    JsBarcode(svg, String(value), { format: 'CODE128', height: 56, margin: 0, fontSize: 14, width: 2 });
  } catch (e) {
    return `<div class="tn">${esc(value)}</div>`;
  }
  svg.setAttribute('style', 'max-width:100%;height:auto');
  return svg.outerHTML;
}

function labelHtml(l, t, money) {
  const cod = Number(l.cod_amount) > 0;
  return `
  <section class="label">
    <header>
      <div class="courier">${esc(l.courier_name)}</div>
      <div class="ref">${esc(t('Cr_Order'))}: ${esc(l.sale_ref)}<br>${esc(l.booked_at)}</div>
    </header>
    <div class="barcode">${barcodeSvg(l.tracking_number)}</div>
    <div class="box to">
      <div class="cap">${esc(t('Cr_Ship_To'))}</div>
      <div class="name">${esc(l.to?.name)}</div>
      <div>${esc(l.to?.phone)}</div>
      <div>${esc(l.to?.address)}</div>
      ${l.to?.city ? `<div class="city">${esc(l.to.city)}</div>` : ''}
    </div>
    <div class="row">
      <div class="box cod ${cod ? 'due' : ''}">
        <div class="cap">${esc(cod ? t('Cr_Collect_COD') : t('Cr_Prepaid'))}</div>
        <div class="amount">${cod ? esc(money(l.cod_amount)) : '—'}</div>
      </div>
      <div class="box meta">
        <div>${esc(t('Cr_Weight'))}: ${esc(l.weight ?? '—')} kg</div>
        <div>${esc(t('Cr_Pieces'))}: ${esc(l.pieces ?? 1)}</div>
        ${l.allow_open_parcel ? `<div>${esc(t('Cr_Open_Allowed'))}</div>` : ''}
      </div>
    </div>
    ${l.items ? `<div class="box items"><div class="cap">${esc(t('Cr_Contents'))}</div>${esc(l.items)}</div>` : ''}
    ${l.notes ? `<div class="box items"><div class="cap">${esc(t('Note'))}</div>${esc(l.notes)}</div>` : ''}
    <div class="box from">
      <div class="cap">${esc(t('Cr_From'))}</div>
      <div class="name">${esc(l.from?.name)}</div>
      <div>${esc(l.from?.phone)}</div>
      <div>${esc(l.from?.address)}</div>
    </div>
  </section>`;
}

const CSS = `
  @page { size: 100mm 150mm; margin: 0; }
  * { box-sizing: border-box; }
  body { margin: 0; font-family: Arial, Helvetica, sans-serif; color: #000; font-size: 11px; }
  .label { width: 100mm; height: 150mm; padding: 4mm; page-break-after: always; display: flex; flex-direction: column; gap: 2mm; overflow: hidden; }
  .label:last-child { page-break-after: auto; }
  header { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #000; padding-bottom: 2mm; }
  .courier { font-size: 16px; font-weight: 700; }
  .ref { text-align: right; font-size: 10px; }
  .barcode { text-align: center; padding: 1mm 0; }
  .tn { font-size: 18px; font-weight: 700; letter-spacing: 1px; }
  .box { border: 1px solid #000; padding: 2mm; }
  .cap { font-size: 9px; text-transform: uppercase; font-weight: 700; margin-bottom: 1mm; }
  .name { font-size: 14px; font-weight: 700; }
  .city { font-size: 14px; font-weight: 700; margin-top: 1mm; }
  .row { display: flex; gap: 2mm; }
  .row .box { flex: 1; }
  .cod.due { background: #000; color: #fff; }
  .amount { font-size: 18px; font-weight: 700; }
  .items { font-size: 10px; max-height: 18mm; overflow: hidden; }
  .from { margin-top: auto; font-size: 10px; }
`;

/** Open a print window with one label per shipment. Returns false if blocked. */
export function printLabels(labels, { t, money }) {
  const win = window.open('', '_blank', 'width=480,height=720');
  if (!win) return false;
  const body = labels.map(l => labelHtml(l, t, money)).join('');
  win.document.open();
  win.document.write(`<!doctype html><html><head><meta charset="utf-8"><title>${esc(t('Cr_Shipping_Labels'))}</title><style>${CSS}</style></head><body>${body}</body></html>`);
  win.document.close();
  setTimeout(() => { win.focus(); win.print(); }, 300);
  return true;
}
