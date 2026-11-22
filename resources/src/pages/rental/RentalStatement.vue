<template>
  <div class="page">
    <PageHeader
      :title="$t('Rental_Statement')"
      :subtitle="st ? st.customer.name : ''"
      :breadcrumb="[$t('Rental_Management'), $t('Rental_Customers'), st ? st.customer.name : '', $t('Rental_Statement')]"
    >
      <template #actions>
        <a-button @click="$router.push(`/rental/customers/${id}`)">
          <template #icon><ArrowLeftOutlined /></template>{{ $t('Back') }}
        </a-button>
        <a-button @click="exportExcelFile"><template #icon><FileExcelOutlined /></template>{{ $t('Export_Excel') }}</a-button>
        <a-dropdown-button type="primary" @click="printPdf">
          <PrinterOutlined /> {{ $t('Rental_Print') }}
          <template #overlay>
            <a-menu><a-menu-item key="d" @click="downloadPdf"><DownloadOutlined /> {{ $t('Rental_Download_PDF') }}</a-menu-item></a-menu>
          </template>
        </a-dropdown-button>
      </template>
    </PageHeader>

    <a-card size="small" class="block">
      <div class="toolbar">
        <DateRangePicker v-model:value="range" :allow-clear="false" @change="load" />
        <span v-if="st && st.deposit_held > 0" class="dep"><SafetyCertificateOutlined /> {{ $t('Rental_Deposit_Held') }}: <strong>{{ money(st.deposit_held) }}</strong></span>
      </div>
    </a-card>

    <a-spin :spinning="loading">
      <template v-if="st">
        <div class="sum">
          <div class="s"><span>{{ $t('Rental_Opening_Balance') }}</span><strong>{{ money(st.opening) }}</strong></div>
          <div class="op">+</div>
          <div class="s"><span>{{ $t('Rental_Billed') }}</span><strong>{{ money(st.debits) }}</strong></div>
          <div class="op">−</div>
          <div class="s"><span>{{ $t('Paid') }}</span><strong class="ok">{{ money(st.credits) }}</strong></div>
          <div class="op">=</div>
          <div class="s big"><span>{{ $t('Rental_Balance_Due') }}</span><strong>{{ money(st.closing) }}</strong></div>
        </div>

        <a-card size="small" :body-style="{ padding: 0 }">
          <table class="ledger">
            <thead>
              <tr>
                <th>{{ $t('date') }}</th>
                <th>{{ $t('Reference') }}</th>
                <th>{{ $t('Description') }}</th>
                <th class="r">{{ $t('Rental_Charges') }}</th>
                <th class="r">{{ $t('Rental_Payments') }}</th>
                <th class="r">{{ $t('Rental_Balance') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr class="carry">
                <td colspan="5">{{ $t('Rental_Brought_Forward') }}</td>
                <td class="r">{{ money(st.opening) }}</td>
              </tr>
              <tr v-for="(l, i) in st.lines" :key="i" :class="l.kind">
                <td>{{ date(l.date) }}</td>
                <td><span class="mono">{{ l.reference }}</span><span class="sub">{{ l.contract_number }}</span></td>
                <td>{{ l.description }}</td>
                <td class="r">{{ l.debit ? money(l.debit) : '' }}</td>
                <td class="r ok">{{ l.credit ? money(l.credit) : '' }}</td>
                <td class="r strong">{{ money(l.balance) }}</td>
              </tr>
              <tr v-if="!st.lines.length"><td colspan="6" class="empty">{{ $t('Rental_No_Activity') }}</td></tr>
            </tbody>
          </table>
        </a-card>
      </template>
    </a-spin>
  </div>
</template>

<script setup>
/**
 * Customer statement — GET rental/customers/{id}/statement: invoices
 * (charges) and payments between two dates with a running balance, opening
 * with what was owed before. Deposits are shown apart (held money, not debt).
 */
import { ref, computed, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import { message } from 'ant-design-vue';
import dayjs from 'dayjs';
import { ArrowLeftOutlined, FileExcelOutlined, PrinterOutlined, DownloadOutlined, SafetyCertificateOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DateRangePicker from '../../components/DateRangePicker.vue';
import { useFormat } from '../../composables/useFormat';
import { exportExcel } from '../../lib/exporters';
import http from '../../lib/http';
import { t } from '../../i18n';

const route = useRoute();
const { money, date } = useFormat();
const id = computed(() => route.params.id);
const range = ref([dayjs().subtract(3, 'month').startOf('day'), dayjs().endOf('day')]);
const st = ref(null);
const loading = ref(false);

const fmt = d => (d ? (typeof d === 'string' ? d.slice(0, 10) : d.format('YYYY-MM-DD')) : '');
const params = () => ({ start_date: fmt(range.value?.[0]), end_date: fmt(range.value?.[1]) });

async function load() {
  loading.value = true;
  try {
    st.value = await http.get(`rental/customers/${id.value}/statement`, params());
  } catch (e) {
    message.error(t('Rental_Err_Could_Not_Load_The_Statement', 'Could not load the statement'));
  } finally {
    loading.value = false;
  }
}

async function printPdf() {
  try {
    const qs = new URLSearchParams({ ...params(), preview: '1' });
    const blob = await http.blob(`rental/customers/${id.value}/statement/pdf?${qs}`);
    const url = window.URL.createObjectURL(blob);
    window.open(url, '_blank');
    setTimeout(() => window.URL.revokeObjectURL(url), 60000);
  } catch (e) {
    message.error(t('Rental_Err_Could_Not_Generate_The_PDF', 'Could not generate the PDF'));
  }
}

async function downloadPdf() {
  try {
    const qs = new URLSearchParams(params());
    await http.download(`rental/customers/${id.value}/statement/pdf?${qs}`, `Statement_${st.value?.customer?.name || id.value}.pdf`);
  } catch (e) {
    message.error(t('Rental_Err_Could_Not_Generate_The_PDF', 'Could not generate the PDF'));
  }
}

async function exportExcelFile() {
  if (!st.value) return;
  const cols = [
    { title: t('date', 'Date'), dataIndex: 'date' },
    { title: t('Reference', 'Reference'), dataIndex: 'reference' },
    { title: t('Rental_Contract', 'Contract'), dataIndex: 'contract_number' },
    { title: t('Description', 'Description'), dataIndex: 'description' },
    { title: t('Rental_Charges', 'Charges'), dataIndex: 'debit' },
    { title: t('Rental_Payments', 'Payments'), dataIndex: 'credit' },
    { title: t('Rental_Balance', 'Balance'), dataIndex: 'balance' },
  ];
  const rows = [
    { date: st.value.from, description: t('Rental_Brought_Forward', 'Balance brought forward'), balance: st.value.opening },
    ...st.value.lines,
  ];
  await exportExcel(`${t('Rental_Statement', 'Statement')} — ${st.value.customer.name}`, cols, rows);
}

onMounted(load);
</script>

<style scoped>
.block {
  margin-bottom: 14px;
}
.toolbar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 14px;
}
.dep {
  font-size: 13px;
}
.sum {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 10px;
  margin-bottom: 14px;
}
.s {
  display: flex;
  flex-direction: column;
  padding: 12px 16px;
  border: 1px solid rgba(128, 128, 128, 0.2);
  border-radius: 12px;
  min-width: 150px;
}
.s span {
  font-size: 12px;
  opacity: 0.65;
}
.s strong {
  font-size: 18px;
}
.s.big {
  border-color: var(--app-primary, #6d28d9);
  background: rgba(var(--app-primary-rgb, 109, 40, 217), 0.06);
}
.s.big strong {
  color: var(--app-primary, #6d28d9);
  font-size: 21px;
}
.op {
  font-size: 18px;
  opacity: 0.4;
}
.ledger {
  width: 100%;
  border-collapse: collapse;
  font-size: 13px;
}
.ledger th {
  padding: 10px 12px;
  font-size: 11.5px;
  font-weight: 600;
  text-align: start;
  text-transform: uppercase;
  letter-spacing: 0.03em;
  opacity: 0.7;
  border-bottom: 1px solid rgba(128, 128, 128, 0.2);
}
.ledger td {
  padding: 9px 12px;
  border-bottom: 1px solid rgba(128, 128, 128, 0.1);
  vertical-align: top;
}
.ledger .carry td {
  font-style: italic;
  opacity: 0.7;
}
.ledger tr.payment td:first-child {
  border-inline-start: 3px solid #16a34a;
}
.ledger tr.invoice td:first-child {
  border-inline-start: 3px solid var(--app-primary, #6d28d9);
}
.r {
  text-align: end !important;
}
.ok {
  color: #16a34a;
}
.strong {
  font-weight: 600;
}
.mono {
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
  font-weight: 600;
}
.sub {
  display: block;
  font-size: 11px;
  opacity: 0.6;
}
.empty {
  padding: 30px !important;
  text-align: center !important;
  opacity: 0.6;
}
</style>
