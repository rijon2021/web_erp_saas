<template>
  <div class="page rst-rep">
    <PageHeader :title="$t('Rst_Reports')" :breadcrumb="[$t('Restaurant_Management'), $t('Rst_Reports')]">
      <template #actions>
        <a-space wrap>
          <a-range-picker v-model:value="range" value-format="YYYY-MM-DD" :allow-clear="false" :presets="presets" @change="load" />
          <a-select v-if="branches.length > 1" v-model:value="branch" allow-clear :placeholder="$t('Rst_All_Branches')" style="min-width: 160px" :options="branches.map(b => ({ value: b.id, label: b.name }))" @change="load" />
          <a-button :disabled="!exportRows.length" :loading="exporting" @click="doExport"><FileExcelOutlined /> {{ $t('Export_Excel') }}</a-button>
        </a-space>
      </template>
    </PageHeader>

    <a-tabs v-model:activeKey="tab" @change="load">
      <a-tab-pane key="sales" :tab="$t('Rst_Rep_Sales')" />
      <a-tab-pane key="dishes" :tab="$t('Rst_Rep_Dishes')" />
      <a-tab-pane key="voids" :tab="$t('Rst_Rep_Voids')" />
      <a-tab-pane key="staff" :tab="$t('Rst_Rep_Staff')" />
      <a-tab-pane key="kitchen" :tab="$t('Rst_Rep_Kitchen')" />
      <a-tab-pane key="reservations" :tab="$t('Rst_Rep_Reservations')" />
    </a-tabs>

    <a-alert v-if="!ready" type="warning" show-icon :message="$t('Rst_Not_Ready')" />
    <a-spin v-else :spinning="loading">
      <template v-if="data && dataType === tab">
        <!-- Sales -->
        <template v-if="tab === 'sales'">
          <div class="tiles">
            <div class="tile"><span>{{ $t('Rst_Sales') }}</span><strong class="num">{{ moneyBase(data.totals.total) }}</strong></div>
            <div class="tile"><span>{{ $t('Rst_Bills') }}</span><strong class="num">{{ data.totals.bills }}</strong></div>
            <div class="tile"><span>{{ $t('Rst_Covers') }}</span><strong class="num">{{ data.totals.covers }}</strong></div>
            <div class="tile"><span>{{ $t('Rst_Online_Orders') }}</span><strong class="num">{{ moneyBase(data.totals.online_sales) }}</strong></div>
            <div class="tile"><span>{{ $t('Rst_Service_Charge') }}</span><strong class="num">{{ moneyBase(data.totals.service) }}</strong></div>
            <div class="tile"><span>{{ $t('Rst_Tips') }}</span><strong class="num">{{ moneyBase(data.totals.tips) }}</strong></div>
          </div>
          <a-table size="small" :columns="salesCols" :data-source="data.rows" row-key="date" :pagination="{ pageSize: 31, hideOnSinglePage: true }" :scroll="{ x: 900 }" />
          <h3 class="sub">{{ $t('Rst_Payment_Methods') }}</h3>
          <a-table size="small" :columns="methodCols" :data-source="data.methods" row-key="id" :pagination="false" :locale="{ emptyText: $t('Rst_No_Sales_Yet') }" style="max-width: 560px" />
        </template>

        <!-- Dishes -->
        <template v-else-if="tab === 'dishes'">
          <a-input-search v-model:value="dishSearch" allow-clear :placeholder="$t('Rst_Search_Dishes')" style="max-width: 280px; margin-bottom: 12px" />
          <a-table size="small" :columns="dishCols" :data-source="dishRows" row-key="id" :pagination="{ pageSize: 50, hideOnSinglePage: true }" :scroll="{ x: 860 }" />
          <p class="muted small">{{ $t('Rst_Dishes_Note') }}</p>
        </template>

        <!-- Voids -->
        <template v-else-if="tab === 'voids'">
          <div class="tiles">
            <div class="tile"><span>{{ $t('Rst_Voided_Dishes') }}</span><strong class="num">{{ data.totals.items }}</strong></div>
            <div class="tile"><span>{{ $t('Rst_Voided_Value') }}</span><strong class="num">{{ moneyBase(data.totals.items_value) }}</strong></div>
            <div class="tile"><span>{{ $t('Rst_Cancelled_Orders') }}</span><strong class="num">{{ data.totals.orders }}</strong></div>
          </div>
          <h3 class="sub">{{ $t('Rst_Voided_Dishes') }}</h3>
          <a-table size="small" :columns="voidCols" :data-source="data.items" row-key="id" :pagination="{ pageSize: 25, hideOnSinglePage: true }" :scroll="{ x: 900 }" />
          <h3 class="sub">{{ $t('Rst_Cancelled_Orders') }}</h3>
          <a-table size="small" :columns="cancelCols" :data-source="data.orders" row-key="id" :pagination="{ pageSize: 25, hideOnSinglePage: true }" :scroll="{ x: 760 }" />
        </template>

        <!-- Staff -->
        <template v-else-if="tab === 'staff'">
          <h3 class="sub">{{ $t('Rst_Waiters') }}</h3>
          <a-table size="small" :columns="waiterCols" :data-source="data.waiters" row-key="id" :pagination="false" :scroll="{ x: 760 }" />
          <h3 class="sub">{{ $t('Rst_Cashiers') }}</h3>
          <a-table size="small" :columns="cashierCols" :data-source="data.cashiers" row-key="id" :pagination="false" :scroll="{ x: 640 }" />
        </template>

        <!-- Kitchen -->
        <template v-else-if="tab === 'kitchen'">
          <div class="tiles">
            <div class="tile"><span>{{ $t('Rst_Tickets') }}</span><strong class="num">{{ data.tickets }}</strong></div>
            <div class="tile"><span>{{ $t('Rst_Avg_Prep') }}</span><strong class="num">{{ mins(data.avg_minutes) }}</strong></div>
            <div class="tile"><span>{{ $t('Rst_Median_Prep') }}</span><strong class="num">{{ mins(data.median_minutes) }}</strong></div>
            <div class="tile"><span>{{ $t('Rst_P90_Prep') }}</span><strong class="num">{{ mins(data.p90_minutes) }}</strong></div>
            <div class="tile"><span>{{ $t('Rst_Late_Share', { n: data.late_minutes }) }}</span><strong class="num">{{ data.late_pct === null ? '—' : data.late_pct + '%' }}</strong></div>
          </div>
          <h3 class="sub">{{ $t('Rst_Stations') }}</h3>
          <a-table size="small" :columns="stationCols" :data-source="data.stations" :row-key="r => r.id ?? 'none'" :pagination="false" style="max-width: 720px" />
          <h3 class="sub">{{ $t('Rst_Tickets_By_Hour') }}</h3>
          <div class="hour-bars" role="img" :aria-label="$t('Rst_Tickets_By_Hour')">
            <div v-for="h in busyHours" :key="h.hour" class="hb" :title="`${h.hour}:00 · ${h.tickets}`">
              <span class="hb-val num">{{ h.tickets || '' }}</span>
              <span class="hb-bar" :style="{ height: barH(h.tickets) }" />
              <span class="hb-lbl num">{{ String(h.hour).padStart(2, '0') }}</span>
            </div>
          </div>
        </template>

        <!-- Reservations -->
        <template v-else-if="tab === 'reservations'">
          <a-alert v-if="data.ready === false" type="info" show-icon :message="$t('Rst_Not_Ready')" />
          <template v-else>
            <div class="tiles">
              <div class="tile"><span>{{ $t('Rst_Bookings') }}</span><strong class="num">{{ data.bookings }}</strong></div>
              <div class="tile"><span>{{ $t('Rst_Covers') }}</span><strong class="num">{{ data.covers }}</strong></div>
              <div class="tile"><span>{{ $t('Rst_No_Show_Rate') }}</span><strong class="num">{{ data.no_show_rate === null ? '—' : data.no_show_rate + '%' }}</strong></div>
              <div class="tile"><span>{{ $t('Rst_Waitlist_Parties') }}</span><strong class="num">{{ data.waitlist.parties }}</strong></div>
              <div class="tile"><span>{{ $t('Rst_Avg_Wait') }}</span><strong class="num">{{ mins(data.waitlist.avg_wait_minutes) }}</strong></div>
            </div>
            <div class="two">
              <a-table size="small" :columns="statusCols" :data-source="data.statuses" row-key="status" :pagination="false" />
              <a-table size="small" :columns="sourceCols" :data-source="data.sources" row-key="source" :pagination="false" />
            </div>
            <p class="muted small">{{ $t('Rst_Waitlist_Summary', { seated: data.waitlist.seated, left: data.waitlist.left }) }}</p>
          </template>
        </template>
      </template>
    </a-spin>
  </div>
</template>

<script setup>
/**
 * Restaurant › Reports (permission rst_reports) — GET restaurant/reports/{type}?from&to&warehouse_id;
 * types sales | dishes | voids | staff | kitchen | reservations (App\Services\Restaurant\ReportService).
 */
import { ref, computed, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import dayjs from 'dayjs';
import { FileExcelOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';
import { exportExcel } from '../../lib/exporters';
import { useFormat } from '../../composables/useFormat';

const { t } = useI18n();
const route = useRoute();
const { moneyBase } = useFormat();

const TABS = ['sales', 'dishes', 'voids', 'staff', 'kitchen', 'reservations'];
const tab = ref(TABS.includes(route.query.tab) ? route.query.tab : 'sales');
const isDate = v => typeof v === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(v);
const range = ref([isDate(route.query.from) ? route.query.from : dayjs().subtract(29, 'day').format('YYYY-MM-DD'), isDate(route.query.to) ? route.query.to : dayjs().format('YYYY-MM-DD')]);
const branch = ref(undefined);
const branches = ref([]);
const loading = ref(false);
const ready = ref(true);
const data = ref(null);
// Which report `data` holds: a tab never renders another tab's figures.
const dataType = ref('');
const exporting = ref(false);
const dishSearch = ref('');

const presets = computed(() => [
  { label: t('Rst_Today'), value: [dayjs(), dayjs()] },
  { label: t('Rst_Last_7_Days'), value: [dayjs().subtract(6, 'day'), dayjs()] },
  { label: t('Rst_Last_30_Days'), value: [dayjs().subtract(29, 'day'), dayjs()] },
  { label: t('Rst_This_Month'), value: [dayjs().startOf('month'), dayjs()] },
  { label: t('Rst_Last_Month'), value: [dayjs().subtract(1, 'month').startOf('month'), dayjs().subtract(1, 'month').endOf('month')] },
]);

const money = ({ value }) => moneyBase(value);
const statusLabel = s => ({ booked: t('Rst_Booked'), confirmed: t('Rst_Confirmed'), seated: t('Rst_Seated'), completed: t('Rst_Completed'), cancelled: t('Rst_Cancelled'), no_show: t('Rst_No_Show') }[s] || s);
const sourceLabel = s => ({ phone: t('Rst_Source_Phone'), walk_in: t('Rst_Source_Walk_In'), online: t('Rst_Source_Online'), other: t('Rst_Source_Other') }[s] || s);
const typeLabel = s => ({ dine_in: t('Rst_Dine_In'), takeaway: t('Rst_Takeaway'), delivery: t('Rst_Delivery') }[s] || s);
function mins(v) { return v === null || v === undefined ? '—' : t('Rst_Minutes_N', { n: v }); }

const salesCols = computed(() => [
  { title: t('Date'), dataIndex: 'date', fixed: 'left', width: 110 },
  { title: t('Rst_Bills'), dataIndex: 'bills', align: 'right' },
  { title: t('Rst_Covers'), dataIndex: 'covers', align: 'right' },
  { title: t('Rst_Bill_Sales'), dataIndex: 'bill_sales', align: 'right', customRender: money },
  { title: t('Rst_Service_Charge'), dataIndex: 'service', align: 'right', customRender: money },
  { title: t('Rst_Tips'), dataIndex: 'tips', align: 'right', customRender: money },
  { title: t('Discount'), dataIndex: 'discounts', align: 'right', customRender: money },
  { title: t('Rst_Rounding'), dataIndex: 'rounding', align: 'right', customRender: money },
  { title: t('Rst_Online_Orders'), dataIndex: 'online_orders', align: 'right' },
  { title: t('Rst_Online_Sales'), dataIndex: 'online_sales', align: 'right', customRender: money },
  { title: t('Rst_Sales'), dataIndex: 'total', align: 'right', customRender: money },
]);
const methodCols = computed(() => [
  { title: t('Payment_Method'), dataIndex: 'name' },
  { title: t('Rst_Payments_Count'), dataIndex: 'count', align: 'right' },
  { title: t('Amount'), dataIndex: 'amount', align: 'right', customRender: money },
]);
const dishCols = computed(() => [
  { title: t('Rst_Dish'), dataIndex: 'name', fixed: 'left', width: 200, ellipsis: true },
  { title: t('Category'), dataIndex: 'category', ellipsis: true },
  { title: t('Rst_Sold'), dataIndex: 'qty', align: 'right', sorter: (a, b) => a.qty - b.qty },
  { title: t('Rst_Sales'), dataIndex: 'sales', align: 'right', customRender: money, sorter: (a, b) => a.sales - b.sales },
  { title: t('Rst_Cost'), dataIndex: 'cost', align: 'right', customRender: money },
  { title: t('Rst_Margin'), dataIndex: 'margin', align: 'right', customRender: money, sorter: (a, b) => a.margin - b.margin },
  { title: t('Rst_Margin_Pct'), dataIndex: 'margin_pct', align: 'right', customRender: ({ value }) => (value === null ? '—' : value + '%'), exportValue: r => r.margin_pct },
  { title: t('Rst_Voided_Qty'), dataIndex: 'voided_qty', align: 'right' },
]);
const voidCols = computed(() => [
  { title: t('Date'), dataIndex: 'at', width: 140 },
  { title: t('Rst_Order'), dataIndex: 'ref' },
  { title: t('Rst_Table'), dataIndex: 'table', customRender: ({ record }) => record.table || typeLabel(record.type), exportValue: r => r.table || typeLabel(r.type) },
  { title: t('Rst_Dish'), dataIndex: 'dish' },
  { title: t('Quantity'), dataIndex: 'qty', align: 'right' },
  { title: t('Rst_Value'), dataIndex: 'value', align: 'right', customRender: money },
  { title: t('Rst_Reason'), dataIndex: 'reason' },
  { title: t('Rst_Voided_By'), dataIndex: 'by' },
]);
const cancelCols = computed(() => [
  { title: t('Date'), dataIndex: 'at', width: 140 },
  { title: t('Rst_Order'), dataIndex: 'ref' },
  { title: t('Rst_Table'), dataIndex: 'table', customRender: ({ record }) => record.table || typeLabel(record.type) },
  { title: t('Rst_Value'), dataIndex: 'value', align: 'right', customRender: money },
  { title: t('Rst_Reason'), dataIndex: 'reason' },
  { title: t('Rst_Voided_By'), dataIndex: 'by' },
]);
const waiterCols = computed(() => [
  { title: t('Rst_Waiter'), dataIndex: 'name' },
  { title: t('Rst_Orders'), dataIndex: 'orders', align: 'right' },
  { title: t('Rst_Covers'), dataIndex: 'covers', align: 'right' },
  { title: t('Rst_Bills'), dataIndex: 'bills', align: 'right' },
  { title: t('Rst_Sales'), dataIndex: 'sales', align: 'right', customRender: money },
  { title: t('Rst_Average_Bill'), dataIndex: 'avg_bill', align: 'right', customRender: money },
  { title: t('Rst_Tips'), dataIndex: 'tips', align: 'right', customRender: money },
]);
const cashierCols = computed(() => [
  { title: t('Rst_Cashier'), dataIndex: 'name' },
  { title: t('Rst_Bills'), dataIndex: 'bills', align: 'right' },
  { title: t('Rst_Sales'), dataIndex: 'total', align: 'right', customRender: money },
  { title: t('Rst_Tips'), dataIndex: 'tips', align: 'right', customRender: money },
  { title: t('Discount'), dataIndex: 'discounts', align: 'right', customRender: money },
]);
const stationCols = computed(() => [
  { title: t('Rst_Kitchen_Station'), dataIndex: 'name', customRender: ({ value }) => value || t('Rst_Any_Station'), exportValue: r => r.name || t('Rst_Any_Station') },
  { title: t('Rst_Dishes'), dataIndex: 'dishes', align: 'right' },
  { title: t('Rst_Tickets'), dataIndex: 'tickets', align: 'right' },
  { title: t('Rst_Avg_Prep'), dataIndex: 'avg_minutes', align: 'right', customRender: ({ value }) => mins(value), exportValue: r => r.avg_minutes },
]);
const statusCols = computed(() => [
  { title: t('Status'), dataIndex: 'status', customRender: ({ value }) => statusLabel(value), exportValue: r => statusLabel(r.status) },
  { title: t('Rst_Bookings'), dataIndex: 'bookings', align: 'right' },
  { title: t('Rst_Covers'), dataIndex: 'covers', align: 'right' },
]);
const sourceCols = computed(() => [
  { title: t('Rst_Booked_Via'), dataIndex: 'source', customRender: ({ value }) => sourceLabel(value), exportValue: r => sourceLabel(r.source) },
  { title: t('Rst_Bookings'), dataIndex: 'bookings', align: 'right' },
]);

const dishRows = computed(() => {
  const q = dishSearch.value.trim().toLowerCase();
  const rows = Array.isArray(data.value) ? data.value : [];
  return q ? rows.filter(r => (r.name || '').toLowerCase().includes(q) || (r.category || '').toLowerCase().includes(q)) : rows;
});
const busyHours = computed(() => {
  const rows = (data.value && data.value.tickets_by_hour) || [];
  const used = rows.filter(r => r.tickets).map(r => r.hour);
  if (!used.length) return [];
  return rows.filter(r => r.hour >= Math.min(...used) && r.hour <= Math.max(...used));
});
const maxTickets = computed(() => Math.max(1, ...busyHours.value.map(h => h.tickets)));
function barH(n) { return Math.round((n / maxTickets.value) * 120) + 'px'; }

// What "Export" writes for the open tab.
const exportSpec = computed(() => {
  const d = data.value;
  if (!d || dataType.value !== tab.value) return null;
  switch (tab.value) {
    case 'sales': return { cols: salesCols.value, rows: d.rows || [] };
    case 'dishes': return { cols: dishCols.value, rows: dishRows.value };
    case 'voids': return { cols: voidCols.value, rows: d.items || [] };
    case 'staff': return { cols: waiterCols.value, rows: d.waiters || [] };
    case 'kitchen': return { cols: stationCols.value, rows: d.stations || [] };
    case 'reservations': return { cols: statusCols.value, rows: d.statuses || [] };
    default: return null;
  }
});
const exportRows = computed(() => (exportSpec.value ? exportSpec.value.rows : []));
async function doExport() {
  const spec = exportSpec.value;
  if (!spec) return;
  exporting.value = true;
  try {
    // Money columns go out as numbers, not formatted text.
    const cols = spec.cols.map(c => (c.customRender === money ? { ...c, exportValue: r => r[c.dataIndex] } : c));
    await exportExcel(`restaurant-${tab.value}-${range.value[0]}_${range.value[1]}`, cols, spec.rows);
  } catch (e) { message.error(t('Failed')); } finally { exporting.value = false; }
}

async function load() {
  loading.value = true;
  const type = tab.value;
  data.value = null;
  dataType.value = '';
  try {
    const params = { from: range.value[0], to: range.value[1] };
    if (branch.value) params.warehouse_id = branch.value;
    const r = await http.get(`restaurant/reports/${type}`, params);
    if (type !== tab.value) return; // a newer tab was opened meanwhile
    ready.value = r.ready !== false;
    data.value = r.ready !== false ? r.data : null;
    dataType.value = type;
    branches.value = r.warehouses || branches.value;
  } catch (e) {
    message.error(e?.data?.message || t('Failed'));
  } finally {
    loading.value = false;
  }
}
onMounted(load);
</script>

<style scoped>
.rst-rep { display: flex; flex-direction: column; gap: 4px; }
.num { font-variant-numeric: tabular-nums; }
.muted { color: rgba(0, 0, 0, 0.55); }
.small { font-size: 12px; }
.tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 10px; margin-bottom: 14px; }
.tile { background: var(--ant-color-bg-container, #fff); border: 1px solid rgba(5, 5, 5, 0.06); border-radius: 12px; padding: 12px 14px; display: flex; flex-direction: column; gap: 4px; }
.tile span { font-size: 12px; color: rgba(0, 0, 0, 0.55); }
.tile strong { font-size: 20px; }
.sub { font-size: 15px; margin: 18px 0 8px; }
.two { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 14px; margin-bottom: 10px; }
.hour-bars { display: flex; align-items: flex-end; gap: 6px; height: 170px; padding: 8px 0; overflow-x: auto; }
.hb { display: flex; flex-direction: column; align-items: center; justify-content: flex-end; gap: 4px; min-width: 28px; height: 100%; }
.hb-bar { width: 18px; border-radius: 4px 4px 0 0; background: #6d28d9; min-height: 2px; }
.hb-val, .hb-lbl { font-size: 11px; color: rgba(0, 0, 0, 0.6); }
:global(html.dark) .rst-rep .muted, :global(html.dark) .rst-rep .tile span, :global(html.dark) .rst-rep .hb-val, :global(html.dark) .rst-rep .hb-lbl { color: rgba(255, 255, 255, 0.6); }
:global(html.dark) .rst-rep .tile { border-color: rgba(255, 255, 255, 0.08); }
:global(html.dark) .rst-rep .hb-bar { background: #8b5cf6; }
</style>
