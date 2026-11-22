/**
 * Label printing for courier shipments: the courier's own label (URL or PDF)
 * when it issues one, otherwise the printable Stocky label.
 */
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import http from '../../lib/http';
import { useFormat } from '../../composables/useFormat';
import { printLabels } from './courierLabels';
import { errorText } from './courierShared';

export function useShipmentLabels() {
  const { t } = useI18n();
  const { money } = useFormat();

  function show(resp) {
    if (resp.type === 'url' && resp.url) {
      window.open(resp.url, '_blank', 'noopener');
    } else if (resp.type === 'pdf' && resp.data) {
      const bytes = Uint8Array.from(atob(resp.data), c => c.charCodeAt(0));
      const url = URL.createObjectURL(new Blob([bytes], { type: 'application/pdf' }));
      window.open(url, '_blank', 'noopener');
      setTimeout(() => URL.revokeObjectURL(url), 60000);
    } else if (resp.type === 'image' && resp.data) {
      // Image labels (UPS GIF): a 4 × 6 print page around the image.
      const mime = /^image\/(gif|png|jpeg)$/.test(resp.mime) ? resp.mime : 'image/gif';
      const title = String(t('Label')).replace(/[<&>]/g, '');
      const html = `<!doctype html><html><head><title>${title}</title><style>@page{size:4in 6in;margin:0}html,body{margin:0}`
        + 'img{display:block;width:4in;height:auto}</style></head><body>'
        + `<img src="data:${mime};base64,${resp.data}" onload="if(this.naturalWidth>this.naturalHeight){this.style.width='6in';`
        + `this.style.transformOrigin='top left';this.style.transform='translateX(4in) rotate(90deg)'}setTimeout(function(){window.print()},200)"></body></html>`;
      const url = URL.createObjectURL(new Blob([html], { type: 'text/html' }));
      if (!window.open(url, '_blank')) message.warning(t('Cr_Popup_Blocked'));
      setTimeout(() => URL.revokeObjectURL(url), 60000);
    } else if (resp.labels && resp.labels.length) {
      if (!printLabels(resp.labels, { t, money })) message.warning(t('Cr_Popup_Blocked'));
    }
  }

  async function printOne(shipmentId) {
    try {
      show(await http.get(`couriers/shipments/${shipmentId}/label`));
    } catch (e) {
      message.error(errorText(e, t('Failed')));
    }
  }

  /** Stocky labels for several shipments in one print job. */
  async function printMany(ids) {
    if (!ids.length) return;
    try {
      show(await http.post('couriers/shipments/labels', { ids }));
    } catch (e) {
      message.error(errorText(e, t('Failed')));
    }
  }

  return { printOne, printMany };
}
