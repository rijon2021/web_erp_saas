<template>
  <div class="page">
    <PageHeader
      :title="inv ? inv.invoice_number : $t('Rental_Invoice')"
      :subtitle="inv ? inv.customer.name : ''"
      :breadcrumb="[$t('Rental_Management'), $t('Rental_Invoices'), inv ? inv.invoice_number : '']"
    >
      <template #actions>
        <a-button @click="$router.back()">
          <template #icon><ArrowLeftOutlined /></template>
          {{ $t('Back') }}
        </a-button>
        <template v-if="inv">
          <a-dropdown-button @click="printPdf">
            <PrinterOutlined /> {{ $t('Rental_Print') }}
            <template #overlay>
              <a-menu>
                <a-menu-item key="download" @click="downloadPdf"><DownloadOutlined /> {{ $t('Rental_Download_PDF') }}</a-menu-item>
              </a-menu>
            </template>
          </a-dropdown-button>
          <a-button v-if="canRemind && inv.balance > 0" @click="remindOpen = true">
            <template #icon><BellOutlined /></template>
            {{ $t('Rental_Send_Reminder') }}
          </a-button>
          <a-button v-if="canManage && inv.status !== 'void' && !inv.paid_amount" danger @click="voidReason = ''; voidOpen = true">
            <template #icon><StopOutlined /></template>
            {{ $t('Rental_Void') }}
          </a-button>
          <a-button v-if="canPay && inv.balance > 0" type="primary" @click="payOpen = true">
            <template #icon><DollarOutlined /></template>
            {{ $t('Rental_Record_Payment') }}
          </a-button>
        </template>
      </template>
    </PageHeader>

    <div v-if="loading" class="loading"><a-spin size="large" /></div>

    <a-result v-else-if="!inv" status="404" :title="$t('Rental_Invoice_Not_Found')" />

    <template v-else>
      <a-alert v-if="inv.status === 'void'" type="warning" show-icon class="block"
        :message="`${$t('Rental_Invoice_Voided')} ${dateTime(inv.voided_at)}`" :description="inv.void_reason || undefined" />

      <a-row :gutter="16">
        <a-col :xs="24" :lg="16">
          <a-card size="small" class="block paper">
            <div class="paper-head">
              <div>
                <span class="paper-kicker">{{ inv.kind === 'charge' ? $t('Rental_Charge') : $t('Rental_Rent') }}</span>
                <h2 class="paper-title">{{ inv.kind === 'charge' ? inv.description : periodText }}</h2>
              </div>
              <a-tag :color="optionOf(INVOICE_STATUSES, invoiceBadge(inv)).color" class="paper-tag">{{ labelOf(INVOICE_STATUSES, invoiceBadge(inv)) }}</a-tag>
            </div>

            <dl class="meta">
              <div><dt>{{ $t('Rental_Contract') }}</dt><dd><router-link :to="`/rental/contracts/${inv.rental_contract_id}`">{{ inv.contract_number }}</router-link></dd></div>
              <div><dt>{{ $t('Rental_Issued') }}</dt><dd>{{ date(inv.issue_date) }}</dd></div>
              <div><dt>{{ $t('Rental_Due_Date') }}</dt><dd :class="{ bad: inv.overdue }">{{ date(inv.due_date) }}</dd></div>
              <div v-if="inv.overdue"><dt>{{ $t('Rental_Days_Late') }}</dt><dd class="bad">{{ inv.days_overdue }}</dd></div>
            </dl>

            <table v-if="inv.contract && inv.contract.lines.length" class="lines">
              <thead>
                <tr><th>{{ $t('Rental_Item') }}</th><th class="right">{{ $t('Quantity') }}</th><th class="right">{{ $t('Rental_Rate') }}</th><th class="right">{{ $t('Amount') }}</th></tr>
              </thead>
              <tbody>
                <tr v-for="(l, i) in inv.contract.lines" :key="i">
                  <td>{{ l.item_name }}</td>
                  <td class="right">{{ l.quantity }}</td>
                  <td class="right">{{ money(l.rate) }} <small>{{ perLabel(inv.contract.rate_period) }}</small></td>
                  <td class="right">{{ money(l.amount) }}</td>
                </tr>
              </tbody>
            </table>

            <dl class="totals">
              <div><dt>{{ $t('SubTotal') }}</dt><dd>{{ money(inv.subtotal) }}</dd></div>
              <div v-if="inv.discount"><dt>{{ $t('Discount') }}</dt><dd>− {{ money(inv.discount) }}</dd></div>
              <div v-if="inv.tax_amount"><dt>{{ $t('Rental_Tax') }}</dt><dd>{{ money(inv.tax_amount) }}</dd></div>
              <div v-if="inv.late_fee" class="late">
                <dt>
                  {{ $t('Rental_Late_Fee') }}
                  <a-tag v-if="inv.late_fee_waived" color="default" class="mini">{{ $t('Rental_Waived') }}</a-tag>
                  <a v-if="canManage && inv.status !== 'void'" class="waive" @click="toggleWaive">
                    {{ inv.late_fee_waived ? $t('Rental_Restore_Fee') : $t('Rental_Waive') }}
                  </a>
                </dt>
                <dd :class="{ struck: inv.late_fee_waived }">{{ money(inv.late_fee) }}</dd>
              </div>
              <div class="strong"><dt>{{ $t('Total') }}</dt><dd>{{ money(inv.total) }}</dd></div>
              <div v-if="inv.paid_amount"><dt>{{ $t('Paid') }}</dt><dd class="ok">− {{ money(inv.paid_amount) }}</dd></div>
              <div class="grand"><dt>{{ $t('Rental_Balance_Due') }}</dt><dd>{{ money(inv.balance) }}</dd></div>
            </dl>

            <p v-if="inv.contract && inv.contract.late_fee_type !== 'none'" class="terms"><ClockCircleOutlined /> {{ lateRule }}</p>
            <p v-if="inv.notes" class="notes">{{ inv.notes }}</p>
          </a-card>
        </a-col>

        <a-col :xs="24" :lg="8">
          <a-card size="small" :title="$t('Customer')" class="block">
            <router-link :to="`/rental/customers/${inv.customer.id}`" class="cust">
              <a-avatar :size="40" :style="{ background: avatarColor(inv.customer.name) }">{{ initials(inv.customer.name) }}</a-avatar>
              <span class="cust-body">
                <strong>{{ inv.customer.name }}</strong>
                <span>{{ inv.customer.phone || inv.customer.email || '—' }}</span>
              </span>
            </router-link>
          </a-card>

          <a-card size="small" :title="$t('Rental_Payments')" class="block">
            <div v-if="inv.payments.length" class="pays">
              <div v-for="p in inv.payments" :key="p.id" class="pay">
                <CheckCircleFilled class="pay-ic" />
                <span class="pay-body">
                  <strong>{{ money(p.amount) }}</strong>
                  <span>{{ date(p.payment_date) }} · {{ p.method || '—' }}<template v-if="p.reference"> · {{ p.reference }}</template></span>
                  <span class="pay-num">{{ p.payment_number }}</span>
                </span>
              </div>
            </div>
            <a-empty v-else :image="simpleEmptyImage" :description="$t('Rental_No_Payments')" />
          </a-card>
        </a-col>
      </a-row>
    </template>

    <RentalReminderModal v-if="inv" v-model:open="remindOpen" :invoice-id="inv.id" :types="['rent_due', 'rent_overdue']" :default-type="inv.overdue ? 'rent_overdue' : 'rent_due'" />
    <RentalPaymentModal v-if="inv" v-model:open="payOpen" :invoice="inv" :owed="inv.balance" @saved="load(false)" />

    <a-modal v-model:open="voidOpen" :title="$t('Rental_Void_Invoice')" :ok-text="$t('Rental_Void')" :cancel-text="$t('Cancel')"
      :ok-button-props="{ danger: true }" :confirm-loading="busy" @ok="doVoid">
      <p class="muted">{{ $t('Rental_Void_Help') }}</p>
      <a-textarea v-model:value="voidReason" :rows="3" :placeholder="$t('Rental_Blacklist_Reason')" />
    </a-modal>
  </div>
</template>

<script setup>
/**
 * One rental invoice: lines, totals with the late fee (waivable), payments
 * applied to it, record payment, void and PDF.
 */
import { ref, computed, onMounted, watch } from 'vue';
import { useRoute } from 'vue-router';
import { message, Empty } from 'ant-design-vue';
import {
  ArrowLeftOutlined, PrinterOutlined, DownloadOutlined, StopOutlined, DollarOutlined,
  ClockCircleOutlined, CheckCircleFilled, BellOutlined,
} from '@ant-design/icons-vue';
import RentalReminderModal from './RentalReminderModal.vue';
import PageHeader from '../../components/PageHeader.vue';
import RentalPaymentModal from './RentalPaymentModal.vue';
import { useFormat } from '../../composables/useFormat';
import { useAuthStore } from '../../stores/auth';
import http from '../../lib/http';
import { t, i18n } from '../../i18n';
import { INVOICE_STATUSES, labelOf, optionOf, invoiceBadge, perLabel, initials, avatarColor } from './rentalOptions';

const route = useRoute();
const auth = useAuthStore();
const { money, date, dateTime } = useFormat();
const simpleEmptyImage = Empty.PRESENTED_IMAGE_SIMPLE;

const canPay = computed(() => auth.can('rnt_payments_add'));
const canManage = computed(() => auth.can('rnt_billing_manage'));
const canRemind = computed(() => auth.can('rnt_reminders'));
const remindOpen = ref(false);

const id = computed(() => route.params.id);
const inv = ref(null);
const loading = ref(false);
const busy = ref(false);
const payOpen = ref(false);
const voidOpen = ref(false);
const voidReason = ref('');

const periodText = computed(() => (inv.value?.period_start ? `${date(inv.value.period_start)} – ${date(inv.value.period_end)}` : ''));

function tp(key, fallback, params) {
  const out = i18n.global.t(key, params);
  if (out !== key) return out;
  return Object.entries(params).reduce((s, [k, v]) => s.replace(`{${k}}`, v), fallback);
}

const lateRule = computed(() => {
  const c = inv.value?.contract;
  if (!c) return '';
  const grace = tp('Rental_Summary_Grace', 'A payment becomes late {days} day(s) after its due date.', { days: c.grace_days });
  const amount = c.late_fee_type === 'percent'
    ? tp('Rental_Summary_Percent', '{value}% of the overdue amount', { value: c.late_fee_value })
    : money(c.late_fee_value);
  const when = c.late_fee_frequency === 'daily'
    ? t('Rental_Summary_Daily', 'is added for every day it stays unpaid.')
    : t('Rental_Summary_Once', 'is added once.');
  return `${grace} ${amount} ${when}`;
});

async function load(spinner = true) {
  if (spinner) loading.value = true;
  try {
    const data = await http.get(`rental/invoices/${id.value}`);
    inv.value = data?.invoice || null;
  } catch (e) {
    if (spinner) inv.value = null;
  } finally {
    loading.value = false;
  }
}

let waiving = false;
async function toggleWaive() {
  if (waiving) return;
  waiving = true;
  try {
    await http.post(`rental/invoices/${id.value}/waive-late-fee`, { waive: !inv.value.late_fee_waived });
    message.success(t('Updated_in_successfully', 'Updated successfully'));
    await load(false);
  } catch (e) {
    message.error(e?.data?.message || t('Gen_Could_Not_Update', 'Could not update'));
  } finally {
    waiving = false;
  }
}

async function doVoid() {
  if (!voidReason.value.trim()) {
    message.error(t('Rental_Blacklist_Reason_Required', 'Please give a reason'));
    return;
  }
  busy.value = true;
  try {
    await http.post(`rental/invoices/${id.value}/void`, { reason: voidReason.value.trim() });
    message.success(t('Updated_in_successfully', 'Updated successfully'));
    voidOpen.value = false;
    await load(false);
  } catch (e) {
    message.error(e?.data?.message || t('Rental_Err_Could_Not_Void_This_Invoice', 'Could not void this invoice'));
  } finally {
    busy.value = false;
  }
}

async function printPdf() {
  try {
    const blob = await http.blob(`rental/invoices/${id.value}/pdf?preview=1`);
    const url = window.URL.createObjectURL(blob);
    window.open(url, '_blank');
    setTimeout(() => window.URL.revokeObjectURL(url), 60000);
  } catch (e) {
    message.error(t('Rental_Err_Could_Not_Generate_The_PDF', 'Could not generate the PDF'));
  }
}

async function downloadPdf() {
  try {
    await http.download(`rental/invoices/${id.value}/pdf`, `Rental_Invoice_${inv.value.invoice_number}.pdf`);
  } catch (e) {
    message.error(t('Rental_Err_Could_Not_Generate_The_PDF', 'Could not generate the PDF'));
  }
}

watch(id, (v) => { if (v) load(); });
onMounted(load);
</script>

<style scoped>
.loading {
  display: flex;
  justify-content: center;
  padding: 96px 0;
}
.block {
  margin-bottom: 16px;
}
.muted {
  opacity: 0.6;
}
.paper :deep(.ant-card-body) {
  padding: 22px 24px;
}
.paper-head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
}
.paper-kicker {
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  opacity: 0.55;
}
.paper-title {
  margin: 2px 0 0;
  font-size: 19px;
  font-weight: 600;
}
.paper-tag {
  font-size: 13px;
  padding: 2px 10px;
}
.meta {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
  gap: 10px;
  margin: 18px 0;
  padding: 12px 14px;
  border-radius: 12px;
  background: rgba(128, 128, 128, 0.07);
}
.meta dt {
  font-size: 11px;
  opacity: 0.6;
}
.meta dd {
  margin: 0;
  font-weight: 600;
}
.bad {
  color: #dc2626;
}
.ok {
  color: #16a34a;
}
.lines {
  width: 100%;
  border-collapse: collapse;
  font-size: 13px;
}
.lines th {
  padding: 8px 6px;
  font-size: 11.5px;
  font-weight: 600;
  text-align: start;
  text-transform: uppercase;
  letter-spacing: 0.03em;
  opacity: 0.6;
  border-bottom: 1px solid rgba(128, 128, 128, 0.2);
}
.lines td {
  padding: 9px 6px;
  border-bottom: 1px solid rgba(128, 128, 128, 0.1);
}
.lines small {
  opacity: 0.6;
}
.right {
  text-align: end !important;
}
.totals {
  margin: 16px 0 0 auto;
  max-width: 360px;
}
.totals > div {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 10px;
  padding: 6px 0;
  font-size: 13.5px;
}
.totals dd {
  margin: 0;
  font-weight: 500;
}
.totals .strong {
  border-top: 1px solid rgba(128, 128, 128, 0.25);
  font-weight: 700;
}
.totals .grand {
  margin-top: 4px;
  padding: 10px 12px;
  border-radius: 10px;
  font-size: 16px;
  font-weight: 700;
  background: rgba(var(--app-primary-rgb, 109, 40, 217), 0.08);
}
.totals .grand dd {
  color: var(--app-primary, #6d28d9);
  font-weight: 700;
}
.late dt {
  display: flex;
  align-items: center;
  gap: 6px;
}
.waive {
  font-size: 12px;
}
.struck {
  text-decoration: line-through;
  opacity: 0.5;
}
.mini {
  margin: 0;
  font-size: 10.5px;
  line-height: 16px;
}
.terms,
.notes {
  margin: 16px 0 0;
  font-size: 12.5px;
  opacity: 0.7;
}
.terms :deep(.anticon) {
  margin-inline-end: 6px;
}
.cust {
  display: flex;
  align-items: center;
  gap: 12px;
  color: inherit;
}
.cust-body {
  display: flex;
  flex-direction: column;
}
.cust-body span {
  font-size: 12.5px;
  opacity: 0.6;
}
.pays {
  display: flex;
  flex-direction: column;
  gap: 10px;
}
.pay {
  display: flex;
  gap: 10px;
}
.pay-ic {
  margin-top: 3px;
  color: #16a34a;
}
.pay-body {
  display: flex;
  flex-direction: column;
  font-size: 12.5px;
}
.pay-body strong {
  font-size: 14px;
}
.pay-num {
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
  opacity: 0.55;
}
</style>
