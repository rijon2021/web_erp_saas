<template>
  <div class="page">
    <PageHeader :title="$t('Rental_Reports')" :breadcrumb="[$t('Rental_Management'), $t('Rental_Reports')]">
      <template #actions>
        <a-button :loading="exporting === 'excel'" @click="doExport('excel')">
          <template #icon><FileExcelOutlined /></template>{{ $t('Export_Excel') }}
        </a-button>
        <a-button :loading="exporting === 'pdf'" @click="doExport('pdf')">
          <template #icon><FilePdfOutlined /></template>{{ $t('Export_PDF') }}
        </a-button>
      </template>
    </PageHeader>

    <a-card size="small" class="block">
      <a-tabs v-model:activeKey="report" @change="onReportChange">
        <a-tab-pane v-for="r in reports" :key="r.key">
          <template #tab><span class="tab"><component :is="r.icon" /> {{ r.title }}</span></template>
        </a-tab-pane>
      </a-tabs>
      <p class="hint">{{ current.hint }}</p>

      <div class="toolbar">
        <a-input-search v-model:value="crud.search.value" :placeholder="$t('Search')" allow-clear class="tb-search" @search="crud.reload" />
        <DateRangePicker v-if="current.range" v-model:value="range" class="tb-range" @change="crud.reload" />
        <a-select v-if="current.itemFilters" v-model:value="filters.type" :options="typeOptions" allow-clear :placeholder="$t('type')" class="tb-item" @change="crud.reload" />
        <a-checkbox v-if="report === 'deposits'" v-model:checked="filters.only_held" @change="crud.reload">{{ $t('Rental_Only_Held') }}</a-checkbox>
        <a-select v-if="report === 'reminders'" v-model:value="filters.status" :options="reminderStatusOptions" allow-clear :placeholder="$t('Status')" class="tb-item" @change="crud.reload" />
      </div>

      <div v-if="tiles.length" class="tiles">
        <div v-for="tile in tiles" :key="tile.label" class="tile" :class="tile.tone">
          <span>{{ tile.label }}</span>
          <strong>{{ tile.value }}</strong>
        </div>
      </div>
    </a-card>

    <DataTable :crud="crud" :columns="columns" :searchable="false">
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'item_name'">
          <router-link v-if="record.id" :to="`/rental/items/${record.id}`" class="strong">{{ record.item_name }}</router-link>
          <span v-else class="muted">{{ $t('Rental_General') }}</span>
          <span v-if="record.code" class="sub">{{ record.code }}</span>
        </template>
        <template v-else-if="column.key === 'customer_name' && report === 'aging'">
          <router-link :to="`/rental/customers/${record.id}`" class="strong">{{ record.customer_name }}</router-link>
          <router-link :to="`/rental/customers/${record.id}/statement`" class="sub">{{ $t('Rental_Statement') }}</router-link>
        </template>
        <template v-else-if="column.key === 'contract_number'">
          <router-link v-if="(report !== 'reminders' && record.id) || record.rental_contract_id" :to="`/rental/contracts/${record.id && report !== 'reminders' ? record.id : record.rental_contract_id}`" class="mono">{{ record.contract_number }}</router-link>
          <span v-else class="mono">{{ record.contract_number || '—' }}</span>
        </template>
        <template v-else-if="column.key === 'type' && report !== 'reminders'">{{ labelOf(RENTAL_TYPES, record.type) }}</template>
        <template v-else-if="column.key === 'occupancy'">
          <div class="occ">
            <a-progress :percent="record.occupancy" size="small" :show-info="false" :stroke-color="occColor(record.occupancy)" class="occ-bar" />
            <span>{{ record.occupancy }}%</span>
          </div>
        </template>
        <template v-else-if="column.key === 'margin'">
          <span v-if="record.margin !== null" :class="record.margin < 0 ? 'bad' : 'ok'">{{ record.margin }}%</span>
          <span v-else class="muted">—</span>
        </template>
        <template v-else-if="column.key === 'profit'">
          <strong :class="record.profit < 0 ? 'bad' : 'ok'">{{ money(record.profit) }}</strong>
        </template>
        <template v-else-if="column.key === 'end_date'">
          {{ dateTime(record.end_date) }}
          <span class="sub" :class="{ bad: record.days_left <= 3 }">{{ tp('Rental_Days_Remaining', '{n} day(s) left', { n: record.days_left }) }}</span>
        </template>
        <template v-else-if="column.key === 'renewal'">
          <a-tag v-if="record.renewal" color="purple">{{ record.renewal }}</a-tag>
          <span v-else class="muted">—</span>
        </template>
        <template v-else-if="column.key === 'created_at'">{{ dateTime(record.created_at) }}</template>
        <template v-else-if="column.key === 'rtype'">{{ reminderType(record.type) }}<a-tag v-if="record.manual" class="mini">{{ $t('Rental_Manual') }}</a-tag></template>
        <template v-else-if="column.key === 'channel'">{{ channelLabel(record.channel) }}</template>
        <template v-else-if="column.key === 'rstatus'">
          <a-tooltip :title="record.error || ''">
            <a-tag :color="{ sent: 'success', failed: 'error', skipped: 'default' }[record.status]">{{ reminderStatus(record.status) }}</a-tag>
          </a-tooltip>
        </template>
        <template v-else-if="column.key === 'oldest_due'">{{ date(record.oldest_due) }}</template>
        <template v-else-if="column.money">
          <span :class="{ bad: column.bad && record[column.dataIndex] > 0, muted: !record[column.dataIndex] }">{{ record[column.dataIndex] ? money(record[column.dataIndex]) : '—' }}</span>
        </template>
        <template v-else-if="column.days">{{ number(record[column.dataIndex], 1) }}</template>
      </template>
    </DataTable>
  </div>
</template>

<script setup>
/**
 * Rental reports — one shell over seven endpoints (rental/reports/*), each
 * answering { rows, totalRows, totals }. Totals come from the server for the
 * whole filtered set, not the page on screen.
 */
import { ref, reactive, computed, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import { message } from 'ant-design-vue';
import {
  FileExcelOutlined, FilePdfOutlined, PieChartOutlined, RiseOutlined, ClockCircleOutlined,
  SafetyCertificateOutlined, WalletOutlined, FieldTimeOutlined, BellOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import DateRangePicker from '../../components/DateRangePicker.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import { exportExcel, exportPdf } from '../../lib/exporters';
import http from '../../lib/http';
import { t, i18n } from '../../i18n';
import { RENTAL_TYPES, EXPENSE_CATEGORIES, tr, labelOf } from './rentalOptions';
import { useRentalTypes } from './rentalTypes';

const route = useRoute();
const { money, number, date, dateTime } = useFormat();

const reports = computed(() => [
  { key: 'occupancy', icon: PieChartOutlined, title: t('Rental_Rep_Occupancy', 'Occupancy'), hint: t('Rental_Rep_Occupancy_Hint', 'How much of the period each item spent on contracts, and what it billed.'), range: true, itemFilters: true, sort: 'occupancy' },
  { key: 'profit', icon: RiseOutlined, title: t('Rental_Rep_Profit', 'Profit by item'), hint: t('Rental_Rep_Profit_Hint', 'Income billed (each contract shared by its items) minus expenses, for the period.'), range: true, itemFilters: true, sort: 'profit' },
  { key: 'aging', icon: ClockCircleOutlined, title: t('Rental_Rep_Aging', 'Outstanding by age'), hint: t('Rental_Rep_Aging_Hint', 'What each customer owes today, by how many days late (after grace).'), range: false, sort: 'total' },
  { key: 'deposits', icon: SafetyCertificateOutlined, title: t('Rental_Rep_Deposits', 'Deposits'), hint: t('Rental_Rep_Deposits_Hint', 'Deposits held per contract, and agreed deposits not collected yet.'), range: false, sort: 'held' },
  { key: 'expenses', icon: WalletOutlined, title: t('Rental_Rep_Expenses', 'Expenses'), hint: t('Rental_Rep_Expenses_Hint', 'Expenses by item and category for the period.'), range: true, sort: 'total' },
  { key: 'expiring', icon: FieldTimeOutlined, title: t('Rental_Rep_Expiring', 'Ending soon'), hint: t('Rental_Rep_Expiring_Hint', 'Active contracts ending in the period (next 30 days by default).'), range: true, sort: 'end_date', dir: 'asc' },
  { key: 'reminders', icon: BellOutlined, title: t('Rental_Rep_Reminders', 'Reminders sent'), hint: t('Rental_Rep_Reminders_Hint', 'Every reminder attempt, with its outcome.'), range: true, sort: 'created_at' },
]);

const report = ref(route.query.report && ['occupancy', 'profit', 'aging', 'deposits', 'expenses', 'expiring', 'reminders'].includes(route.query.report) ? route.query.report : 'occupancy');
const current = computed(() => reports.value.find(r => r.key === report.value) || reports.value[0]);
const range = ref(null);
const filters = reactive({ type: undefined, only_held: true, status: undefined });
// Only the tenant's business types (Rental Settings › Business type).
const rentalTypes = useRentalTypes();
const typeOptions = computed(() => rentalTypes.enabledOptions(filters.type));
const reminderStatusOptions = computed(() => ['sent', 'failed', 'skipped'].map(v => ({ value: v, label: reminderStatus(v) })));

const fmt = d => (d ? (typeof d === 'string' ? d.slice(0, 10) : d.format('YYYY-MM-DD')) : '');
const endpoint = ref(`rental/reports/${report.value}`);
const params = () => ({
  start_date: current.value.range ? fmt(range.value?.[0]) : '',
  end_date: current.value.range ? fmt(range.value?.[1]) : '',
  type: current.value.itemFilters ? filters.type || '' : '',
  only_held: filters.only_held ? '1' : '0',
  status: report.value === 'reminders' ? filters.status || '' : '',
});
const crud = useCrudTable(() => endpoint.value, {
  sortField: current.value.sort,
  sortType: current.value.dir || 'desc',
  select: p => ({ rows: p?.rows || [], total: p?.totalRows || 0 }),
  params,
});

function onReportChange() {
  endpoint.value = `rental/reports/${report.value}`;
  crud.sortField.value = current.value.sort;
  crud.sortType.value = current.value.dir || 'desc';
  crud.search.value = '';
  crud.reload();
}

function tp(key, fallback, p) {
  const out = i18n.global.t(key, p);
  if (out !== key) return out;
  return Object.entries(p).reduce((s, [k, v]) => s.replace(`{${k}}`, v), fallback);
}

function occColor(v) {
  return v >= 75 ? '#16a34a' : v >= 40 ? 'var(--app-primary, #6d28d9)' : '#d97706';
}

const REMINDER_TYPES = {
  rent_due: ['Rental_Rem_Rent_Due', 'Rent due'],
  rent_overdue: ['Rental_Rem_Rent_Overdue', 'Rent overdue'],
  contract_expiring: ['Rental_Rem_Expiring', 'Contract ending'],
  return_due: ['Rental_Rem_Return_Due', 'Return due'],
};
function reminderType(v) {
  const [k, f] = REMINDER_TYPES[v] || [v, v];
  return t(k, f);
}
function channelLabel(v) {
  return { email: t('Email', 'Email'), sms: 'SMS', whatsapp: 'WhatsApp' }[v] || v;
}
function reminderStatus(v) {
  return { sent: t('Rental_Sent', 'Sent'), failed: t('Rental_Failed', 'Failed'), skipped: t('Rental_Skipped', 'Skipped') }[v] || v;
}

// ---------------- columns ----------------
const COLUMNS = computed(() => ({
  occupancy: [
    { title: t('Rental_Item'), key: 'item_name', dataIndex: 'item_name', sorter: true },
    { title: t('type'), key: 'type', dataIndex: 'type', width: 110 },
    { title: t('Category'), key: 'category', dataIndex: 'category', width: 140 },
    { title: t('Rental_Days_Rented'), key: 'days_rented', dataIndex: 'days_rented', sorter: true, days: true, width: 120, sum: true },
    { title: t('Rental_Occupancy'), key: 'occupancy', dataIndex: 'occupancy', sorter: true, width: 190 },
    { title: t('Rental_Contracts'), key: 'contracts', dataIndex: 'contracts', sorter: true, width: 110, sum: true },
    { title: t('Rental_Billed'), key: 'billed', dataIndex: 'billed', sorter: true, money: true, width: 140, sum: 'money' },
  ],
  profit: [
    { title: t('Rental_Item'), key: 'item_name', dataIndex: 'item_name', sorter: true },
    { title: t('type'), key: 'type', dataIndex: 'type', width: 110 },
    { title: t('Rental_Income'), key: 'income', dataIndex: 'income', sorter: true, money: true, width: 140, sum: 'money' },
    { title: t('Rental_Expenses'), key: 'expenses', dataIndex: 'expenses', sorter: true, money: true, width: 140, sum: 'money' },
    { title: t('Rental_Profit'), key: 'profit', dataIndex: 'profit', sorter: true, width: 140, sum: 'money' },
    { title: t('Rental_Margin'), key: 'margin', dataIndex: 'margin', sorter: true, width: 100 },
  ],
  aging: [
    { title: t('Customer'), key: 'customer_name', dataIndex: 'customer_name', sorter: true },
    { title: t('Rental_Not_Late'), key: 'current', dataIndex: 'current', sorter: true, money: true, width: 120, sum: 'money' },
    { title: '1–30', key: 'd30', dataIndex: 'd30', sorter: true, money: true, bad: true, width: 115, sum: 'money' },
    { title: '31–60', key: 'd60', dataIndex: 'd60', sorter: true, money: true, bad: true, width: 115, sum: 'money' },
    { title: '61–90', key: 'd90', dataIndex: 'd90', sorter: true, money: true, bad: true, width: 115, sum: 'money' },
    { title: '90+', key: 'd90p', dataIndex: 'd90p', sorter: true, money: true, bad: true, width: 115, sum: 'money' },
    { title: t('Total'), key: 'total', dataIndex: 'total', sorter: true, money: true, width: 130, sum: 'money' },
    { title: t('Rental_Oldest_Due'), key: 'oldest_due', dataIndex: 'oldest_due', sorter: true, width: 120 },
  ],
  deposits: [
    { title: t('Rental_Contract'), key: 'contract_number', dataIndex: 'contract_number', sorter: true, width: 130 },
    { title: t('Customer'), key: 'customer_name', dataIndex: 'customer_name', sorter: true },
    { title: t('Rental_Deposit_Agreed'), key: 'agreed', dataIndex: 'agreed', money: true, sorter: true, width: 120, sum: 'money' },
    { title: t('Rental_Dep_Collected'), key: 'collected', dataIndex: 'collected', money: true, sorter: true, width: 120, sum: 'money' },
    { title: t('Rental_Dep_Applied'), key: 'applied', dataIndex: 'applied', money: true, sorter: true, width: 120, sum: 'money' },
    { title: t('Rental_Dep_Retained'), key: 'retained', dataIndex: 'retained', money: true, sorter: true, width: 110, sum: 'money' },
    { title: t('Rental_Dep_Refunded'), key: 'refunded', dataIndex: 'refunded', money: true, sorter: true, width: 110, sum: 'money' },
    { title: t('Rental_Deposit_Held'), key: 'held', dataIndex: 'held', money: true, sorter: true, width: 120, sum: 'money' },
    { title: t('Rental_Not_Collected'), key: 'missing', dataIndex: 'missing', money: true, bad: true, sorter: true, width: 130, sum: 'money' },
  ],
  expenses: [
    { title: t('Rental_Item'), key: 'item_name', dataIndex: 'item_name', sorter: true },
    ...tr(EXPENSE_CATEGORIES).map(c => ({ title: c.label, key: c.value, dataIndex: c.value, money: true, sorter: true, width: 110, sum: 'money' })),
    { title: t('Total'), key: 'total', dataIndex: 'total', money: true, sorter: true, width: 120, sum: 'money' },
  ],
  expiring: [
    { title: t('Rental_Contract'), key: 'contract_number', dataIndex: 'contract_number', sorter: true, width: 130 },
    { title: t('Customer'), key: 'customer_name', dataIndex: 'customer_name', sorter: true },
    { title: t('Phone'), key: 'phone', dataIndex: 'phone', width: 130 },
    { title: t('Rental_Items'), key: 'items', dataIndex: 'items', width: 200 },
    { title: t('Rental_End'), key: 'end_date', dataIndex: 'end_date', sorter: true, width: 180 },
    { title: t('Rental_Per_Bill'), key: 'total_amount', dataIndex: 'total_amount', money: true, sorter: true, width: 120 },
    { title: t('Rental_Deposit_Held'), key: 'deposit_held', dataIndex: 'deposit_held', money: true, sorter: true, width: 120, sum: 'money' },
    { title: t('Rental_Renewal'), key: 'renewal', dataIndex: 'renewal', width: 110 },
  ],
  reminders: [
    { title: t('date'), key: 'created_at', dataIndex: 'created_at', sorter: true, width: 160 },
    { title: t('type'), key: 'rtype', dataIndex: 'type', sorter: true, width: 160 },
    { title: t('Rental_Channel'), key: 'channel', dataIndex: 'channel', sorter: true, width: 100 },
    { title: t('Customer'), key: 'customer_name', dataIndex: 'customer_name', sorter: true },
    { title: t('Rental_Contract'), key: 'contract_number', dataIndex: 'contract_number', width: 120 },
    { title: t('Rental_Invoice'), key: 'invoice_number', dataIndex: 'invoice_number', width: 120 },
    { title: t('Rental_Recipient'), key: 'recipient', dataIndex: 'recipient', width: 190 },
    { title: t('Status'), key: 'rstatus', dataIndex: 'status', sorter: true, width: 100 },
  ],
}));
const columns = computed(() => COLUMNS.value[report.value]);

// ---------------- totals tiles ----------------
const tiles = computed(() => {
  const x = crud.payload.value?.totals || {};
  switch (report.value) {
    case 'occupancy': return [
      { label: t('Rental_Avg_Occupancy', 'Average occupancy'), value: `${x.occupancy ?? 0}%` },
      { label: t('Rental_Billed', 'Billed'), value: money(x.billed || 0) },
      { label: t('Rental_Contracts', 'Contracts'), value: number(x.contracts || 0, 0) },
    ];
    case 'profit': return [
      { label: t('Rental_Income', 'Income'), value: money(x.income || 0), tone: 'ok' },
      { label: t('Rental_Expenses', 'Expenses'), value: money(x.expenses || 0), tone: 'bad' },
      { label: t('Rental_Profit', 'Profit'), value: money(x.profit || 0), tone: (x.profit || 0) < 0 ? 'bad' : 'ok' },
    ];
    case 'aging': return [
      { label: t('Rental_Outstanding', 'Outstanding'), value: money(x.total || 0) },
      { label: t('Rental_Not_Late', 'Not late yet'), value: money(x.current || 0) },
      { label: t('Rental_Overdue_Amount', 'Overdue'), value: money((x.d30 || 0) + (x.d60 || 0) + (x.d90 || 0) + (x.d90p || 0)), tone: 'bad' },
      { label: '90+', value: money(x.d90p || 0), tone: 'bad' },
    ];
    case 'deposits': return [
      { label: t('Rental_Deposit_Held', 'Deposit held'), value: money(x.held || 0) },
      { label: t('Rental_Not_Collected', 'Not collected'), value: money(x.missing || 0), tone: 'bad' },
      { label: t('Rental_Dep_Retained', 'Kept'), value: money(x.retained || 0) },
    ];
    case 'expenses': return [{ label: t('Total', 'Total'), value: money(x.total || 0), tone: 'bad' }];
    case 'expiring': return [{ label: t('Rental_Deposit_Held', 'Deposit held'), value: money(x.deposit_held || 0) }];
    case 'reminders': return [
      { label: t('Rental_Sent', 'Sent'), value: number(x.sent || 0, 0), tone: 'ok' },
      { label: t('Rental_Failed', 'Failed'), value: number(x.failed || 0, 0), tone: 'bad' },
      { label: t('Rental_Skipped', 'Skipped'), value: number(x.skipped || 0, 0) },
    ];
  }
  return [];
});

// ---------------- export ----------------
const exporting = ref('');
async function doExport(kind) {
  exporting.value = kind;
  try {
    const data = await http.get(endpoint.value, {
      page: 1, limit: -1, SortField: crud.sortField.value, SortType: crud.sortType.value, search: crud.search.value, ...params(),
    });
    const rows = (data?.rows || []).map(r => ({
      ...r,
      type: report.value === 'reminders' ? reminderType(r.type) : labelOf(RENTAL_TYPES, r.type),
      item_name: r.item_name || (report.value === 'expenses' ? t('Rental_General', 'General') : r.item_name),
      end_date: r.end_date ? dateTime(r.end_date) : r.end_date,
      created_at: r.created_at ? dateTime(r.created_at) : r.created_at,
      occupancy: r.occupancy !== undefined ? `${r.occupancy}%` : undefined,
    }));
    if (!rows.length) {
      message.warning(t('Rental_Nothing_To_Export', 'Nothing to export'));
      return;
    }
    const cols = columns.value.map(c => ({ ...c, dataIndex: c.key === 'rtype' ? 'type' : c.key === 'rstatus' ? 'status' : c.dataIndex }));
    const title = `${t('Rental_Reports', 'Rental reports')} — ${current.value.title}`;
    if (kind === 'excel') await exportExcel(title, cols, rows);
    else await exportPdf(title, cols, rows);
  } catch (e) {
    message.error(t('Rental_Err_Could_Not_Export_This_Report', 'Could not export this report'));
  } finally {
    exporting.value = '';
  }
}

onMounted(crud.fetchRows);
</script>

<style scoped>
.block {
  margin-bottom: 16px;
}
.tab {
  display: inline-flex;
  align-items: center;
  gap: 6px;
}
.hint {
  margin: -6px 0 12px;
  font-size: 12.5px;
  opacity: 0.65;
}
.toolbar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 10px;
}
.tb-search {
  flex: 1 1 220px;
  max-width: 320px;
}
.tb-range {
  width: 300px;
}
.tb-item {
  width: 160px;
}
.tiles {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
  gap: 10px;
  margin-top: 14px;
}
.tile {
  display: flex;
  flex-direction: column;
  padding: 10px 14px;
  border-radius: 12px;
  background: rgba(128, 128, 128, 0.07);
}
.tile span {
  font-size: 12px;
  opacity: 0.65;
}
.tile strong {
  font-size: 19px;
}
.tile.ok strong,
.ok {
  color: #16a34a;
}
.tile.bad strong,
.bad {
  color: #dc2626;
}
.strong {
  display: block;
  font-weight: 500;
}
.sub {
  display: block;
  font-size: 11.5px;
  opacity: 0.65;
}
.sub.bad {
  opacity: 1;
}
.muted {
  opacity: 0.5;
}
.mono {
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
  font-weight: 600;
}
.mini {
  margin-inline-start: 6px;
  font-size: 10.5px;
  line-height: 16px;
}
.occ {
  display: flex;
  align-items: center;
  gap: 8px;
}
.occ-bar {
  flex: 1;
  margin: 0;
}
@media (max-width: 767px) {
  .tb-range,
  .tb-item {
    width: 100%;
  }
}
</style>
