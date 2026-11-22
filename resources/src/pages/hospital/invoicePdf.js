// The hospital invoice PDF, shared by the Billing page and the patient's Billing tab.
import { message } from 'ant-design-vue';
import http from '../../lib/http';
import { t } from '../../i18n';

/** Open the PDF in a new tab, where the browser's viewer prints it. */
export async function printInvoice(invoice) {
  try {
    const blob = await http.blob(`hospital/invoices/${invoice.id}/pdf?preview=1`);
    const url = window.URL.createObjectURL(blob);
    window.open(url, '_blank');
    setTimeout(() => window.URL.revokeObjectURL(url), 60000);
  } catch (e) {
    message.error(t('Hm_Err_Could_Not_Generate_The_PDF', 'Could not generate the PDF'));
  }
}

export async function downloadInvoice(invoice) {
  try {
    await http.download(`hospital/invoices/${invoice.id}/pdf`, `Hospital_Invoice_${invoice.reference}.pdf`);
  } catch (e) {
    message.error(t('Hm_Err_Could_Not_Generate_The_PDF', 'Could not generate the PDF'));
  }
}
