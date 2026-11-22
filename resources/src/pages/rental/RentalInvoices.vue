<template>
  <div class="page">
    <PageHeader :title="$t('Rental_Invoices')" :breadcrumb="[$t('Rental_Management'), $t('Rental_Invoices')]">
      <template #actions>
        <a-button @click="$router.push('/rental/payments')">
          <template #icon><WalletOutlined /></template>
          {{ $t('Rental_Payments') }}
        </a-button>
      </template>
    </PageHeader>

    <!-- Money tiles -->
    <div class="tiles">
      <div class="tile">
        <span class="tile-label">{{ $t('Rental_Outstanding') }}</span>
        <span class="tile-value">{{ money(summary.outstanding || 0) }}</span>
        <span class="tile-note">{{ summary.counts?.open || 0 }} {{ $t('Rental_Open_Invoices') }}</span>
      </div>
      <button type="button" class="tile tile--bad" @click="setStage('overdue')">
        <span class="tile-label">{{ $t('Rental_Overdue_Amount') }}</span>
        <span class="tile-value">{{ money(summary.overdue || 0) }}</span>
        <span class="tile-note">{{ summary.counts?.overdue || 0 }} {{ $t('Rental_Invoices_Lower') }}</span>
      </button>
      <div class="tile">
        <span class="tile-label">{{ $t('Rental_Due_This_Week') }}</span>
        <span class="tile-value">{{ money(summary.due_week || 0) }}</span>
      </div>
      <div class="tile tile--ok">
        <span class="tile-label">{{ $t('Rental_Collected_Month') }}</span>
        <span class="tile-value">{{ money(summary.collected_month || 0) }}</span>
        <span v-if="summary.late_fees" class="tile-note">{{ $t('Rental_Late_Fees_Open') }}: {{ money(summary.late_fees) }}</span>
      </div>
    </div>

    <div class="stages" role="tablist">
      <button
        v-for="s in stageTabs" :key="s.value" type="button" role="tab"
        class="stage" :class="[{ active: stage === s.value }, `tone-${s.tone}`]"
        :aria-selected="stage === s.value" @click="setStage(s.value)"
      >
        {{ s.label }}
        <span class="stage-count">{{ summary.counts?.[s.value] ?? 0 }}</span>
      </button>
    </div>

    <DataTable :crud="crud" :columns="columns">
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'invoice_number'">
          <router-link :to="`/rental/invoices/${record.id}`" class="num">{{ record.invoice_number }}</router-link>
          <span class="sub">{{ record.kind === 'charge' ? record.description : periodText(record) }}</span>
        </template>
        <template v-else-if="column.key === 'customer_name'">
          <span class="cell-strong">{{ record.customer_name }}</span>
          <router-link :to="`/rental/contracts/${record.rental_contract_id}`" class="sub">{{ record.contract_number }}</router-link>
        </template>
        <template v-else-if="column.key === 'due_date'">
          {{ date(record.due_date) }}
          <span v-if="record.overdue" class="sub bad">{{ tp('Rental_Overdue_By', 'Overdue by {n} day(s)', { n: record.days_overdue }) }}</span>
        </template>
        <template v-else-if="column.key === 'total'">
          <span class="cell-strong">{{ money(record.total) }}</span>
          <span v-if="record.late_fee && !record.late_fee_waived" class="sub bad">+ {{ money(record.late_fee) }} {{ $t('Rental_Late_Fee_Lower') }}</span>
        </template>
        <template v-else-if="column.key === 'paid_amount'">
          {{ record.paid_amount ? money(record.paid_amount) : '—' }}
        </template>
        <template v-else-if="column.key === 'balance'">
          <span :class="{ 'cell-strong': record.balance > 0 }">{{ record.balance ? money(record.balance) : '—' }}</span>
        </template>
        <template v-else-if="column.key === 'status'">
          <a-tag :color="optionOf(INVOICE_STATUSES, invoiceBadge(record)).color">{{ labelOf(INVOICE_STATUSES, invoiceBadge(record)) }}</a-tag>
        </template>
        <template v-else-if="column.key === 'actions'">
          <a-space :size="0">
            <a-tooltip v-if="canPay && record.balance > 0" :title="$t('Rental_Record_Payment')">
              <a-button type="text" size="small" @click="openPay(record)">
                <template #icon><DollarOutlined /></template>
              </a-button>
            </a-tooltip>
            <a-tooltip :title="$t('View')">
              <a-button type="text" size="small" @click="$router.push(`/rental/invoices/${record.id}`)">
                <template #icon><EyeOutlined /></template>
              </a-button>
            </a-tooltip>
          </a-space>
        </template>
      </template>
    </DataTable>

    <RentalPaymentModal v-model:open="payOpen" :invoice="payInvoice" :owed="payInvoice ? payInvoice.balance : null" @saved="crud.fetchRows" />
  </div>
</template>

<script setup>
/**
 * Rental invoices — GET rental/invoices → { invoices, totalRows, summary }.
 * Tabs: open (unpaid + partly paid), overdue (past due + the contract's grace
 * days), and each stored status.
 */
import { ref, computed, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import { WalletOutlined, DollarOutlined, EyeOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import RentalPaymentModal from './RentalPaymentModal.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import { useAuthStore } from '../../stores/auth';
import { t, i18n } from '../../i18n';
import { INVOICE_STATUSES, labelOf, optionOf, invoiceBadge } from './rentalOptions';

const route = useRoute();
const auth = useAuthStore();
const { money, date } = useFormat();
const canPay = computed(() => auth.can('rnt_payments_add'));

const STAGES = ['open', 'overdue', 'partial', 'paid', 'void', 'all'];
const stage = ref(STAGES.includes(route.query.stage) ? route.query.stage : 'open');

const stageTabs = computed(() => [
  { value: 'open', label: t('Rental_Open', 'Open'), tone: 'info' },
  { value: 'overdue', label: labelOf(INVOICE_STATUSES, 'overdue'), tone: 'bad' },
  { value: 'partial', label: labelOf(INVOICE_STATUSES, 'partial'), tone: 'warn' },
  { value: 'paid', label: labelOf(INVOICE_STATUSES, 'paid'), tone: 'ok' },
  { value: 'void', label: labelOf(INVOICE_STATUSES, 'void'), tone: 'neutral' },
  { value: 'all', label: t('All', 'All'), tone: 'neutral' },
]);

const crud = useCrudTable('rental/invoices', {
  rowsKey: 'invoices',
  sortField: 'due_date',
  sortType: stage.value === 'paid' ? 'desc' : 'asc',
  params: () => ({
    stage: stage.value,
    rental_contract_id: route.query.contract || '',
    rental_customer_id: route.query.customer || '',
  }),
});
const summary = computed(() => crud.payload.value?.summary || {});

function setStage(value) {
  stage.value = value;
  crud.reload();
}

function tp(key, fallback, params) {
  const out = i18n.global.t(key, params);
  if (out !== key) return out;
  return Object.entries(params).reduce((s, [k, v]) => s.replace(`{${k}}`, v), fallback);
}

function periodText(r) {
  return r.period_start ? `${date(r.period_start)} – ${date(r.period_end)}` : '';
}

const payOpen = ref(false);
const payInvoice = ref(null);
function openPay(row) {
  payInvoice.value = row;
  payOpen.value = true;
}

const columns = computed(() => [
  { title: t('Rental_Invoice'), key: 'invoice_number', dataIndex: 'invoice_number', sorter: true, width: 220 },
  { title: t('Customer'), key: 'customer_name', dataIndex: 'customer_name', sorter: true },
  { title: t('Rental_Due_Date'), key: 'due_date', dataIndex: 'due_date', sorter: true, width: 170 },
  { title: t('Total'), key: 'total', dataIndex: 'total', width: 150, sum: 'money' },
  { title: t('Paid'), key: 'paid_amount', dataIndex: 'paid_amount', width: 120, sum: 'money' },
  { title: t('Rental_Balance'), key: 'balance', dataIndex: 'balance', width: 130, sum: 'money' },
  { title: t('Status'), key: 'status', width: 120 },
  { title: '', key: 'actions', width: 90 },
]);

onMounted(crud.fetchRows);
</script>

<style scoped>
.tiles {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 12px;
  margin-bottom: 14px;
}
.tile {
  display: flex;
  flex-direction: column;
  padding: 14px 16px;
  border: 1px solid rgba(128, 128, 128, 0.2);
  border-radius: 14px;
  background: transparent;
  color: inherit;
  font: inherit;
  text-align: start;
}
button.tile {
  cursor: pointer;
}
button.tile:hover {
  border-color: rgba(220, 38, 38, 0.5);
}
.tile-label {
  font-size: 12px;
  opacity: 0.65;
}
.tile-value {
  font-size: 21px;
  font-weight: 700;
}
.tile--bad .tile-value {
  color: #dc2626;
}
.tile--ok .tile-value {
  color: #16a34a;
}
.tile-note {
  font-size: 11.5px;
  opacity: 0.6;
}
.stages {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-bottom: 14px;
}
.stage {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 6px 8px 6px 14px;
  border: 1px solid rgba(128, 128, 128, 0.25);
  border-radius: 999px;
  background: transparent;
  color: inherit;
  font: inherit;
  font-size: 13px;
  cursor: pointer;
}
.stage.active {
  border-color: var(--app-primary, #6d28d9);
  background: rgba(var(--app-primary-rgb, 109, 40, 217), 0.08);
  color: var(--app-primary, #6d28d9);
  font-weight: 500;
}
.stage-count {
  min-width: 24px;
  padding: 0 7px;
  border-radius: 999px;
  font-size: 11.5px;
  font-weight: 600;
  line-height: 20px;
  text-align: center;
  background: rgba(128, 128, 128, 0.15);
}
.tone-bad .stage-count {
  color: #b91c1c;
  background: rgba(220, 38, 38, 0.13);
}
.tone-warn .stage-count {
  color: #b45309;
  background: rgba(217, 119, 6, 0.15);
}
.tone-ok .stage-count {
  color: #15803d;
  background: rgba(22, 163, 74, 0.13);
}
.tone-info .stage-count {
  color: #0369a1;
  background: rgba(2, 132, 199, 0.13);
}
.num {
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
  font-weight: 600;
}
.sub {
  display: block;
  font-size: 11.5px;
  opacity: 0.65;
}
.sub.bad {
  color: #dc2626;
  opacity: 1;
}
.cell-strong {
  display: block;
  font-weight: 600;
}
</style>
