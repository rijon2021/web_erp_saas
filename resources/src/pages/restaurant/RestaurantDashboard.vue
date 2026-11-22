<template>
  <div class="page rst-dash">
    <PageHeader :title="$t('Rst_Dashboard')" :breadcrumb="[$t('Restaurant_Management'), $t('Rst_Dashboard')]">
      <template #actions>
        <a-space wrap>
          <a-segmented v-model:value="period" :options="periodOptions" @change="onPeriod" />
          <a-range-picker v-if="period === 'custom'" v-model:value="range" value-format="YYYY-MM-DD" :allow-clear="false" @change="load" />
          <a-select v-if="branches.length > 1" v-model:value="branch" allow-clear :placeholder="$t('Rst_All_Branches')" style="min-width: 160px" :options="branches.map(b => ({ value: b.id, label: b.name }))" @change="load" />
          <a-button :loading="loading" :aria-label="$t('Rst_Refresh')" @click="load"><ReloadOutlined /></a-button>
        </a-space>
      </template>
    </PageHeader>

    <div v-if="first" class="loading"><a-spin size="large" /></div>
    <a-alert v-else-if="!ready" type="warning" show-icon :message="$t('Rst_Not_Ready')" />

    <template v-else-if="data">
      <!-- KPI tiles -->
      <div class="kpis">
        <div v-for="k in kpis" :key="k.key" class="kpi">
          <span class="kpi-label">{{ k.label }}</span>
          <strong class="kpi-value num">{{ k.value }}</strong>
          <span v-if="k.delta" :class="['kpi-delta', k.delta.dir]">
            <ArrowUpOutlined v-if="k.delta.dir === 'up'" /><ArrowDownOutlined v-else-if="k.delta.dir === 'down'" />
            {{ k.delta.text }}
          </span>
          <span v-else-if="k.note" class="kpi-delta flat">{{ k.note }}</span>
        </div>
      </div>

      <div class="row2">
        <!-- Sales over time -->
        <section class="card grow2">
          <div class="card-head">
            <h2>{{ byDay ? $t('Rst_Sales_By_Day') : $t('Rst_Sales_By_Hour') }}</h2>
            <a-button size="small" type="link" @click="asTable = !asTable">{{ asTable ? $t('Rst_Show_Chart') : $t('Rst_Show_Table') }}</a-button>
          </div>
          <a-empty v-if="!salesPoints.length" :description="$t('Rst_No_Sales_Yet')" />
          <template v-else>
            <apexchart v-if="!asTable" type="bar" height="260" :options="salesChart.options" :series="salesChart.series" />
            <a-table v-else size="small" :pagination="false" :columns="salesCols" :data-source="salesPoints" row-key="key" :scroll="{ y: 260 }" />
          </template>
        </section>

        <!-- Right now -->
        <section v-if="data.now" class="card grow1">
          <div class="card-head"><h2>{{ $t('Rst_Right_Now') }}</h2></div>
          <div class="now-grid">
            <div class="now t-violet"><span>{{ $t('Rst_Tables_Occupied') }}</span><strong class="num">{{ data.now.tables_busy }} / {{ data.now.tables }}</strong></div>
            <div class="now t-amber"><span>{{ $t('Rst_Bills_Requested') }}</span><strong class="num">{{ data.now.bills_requested }}</strong></div>
            <div class="now t-green"><span>{{ $t('Rst_Kitchen_Queue') }}</span><strong class="num">{{ $t('Rst_Tickets_N', { n: data.now.kitchen_queue }) }}</strong></div>
            <div class="now t-red"><span>{{ $t('Rst_Late_Tickets') }}</span><strong class="num">{{ data.now.late_tickets }}</strong></div>
          </div>
          <dl class="now-list">
            <div v-if="data.now.bookings_to_come !== null"><dt>{{ $t('Rst_Bookings_To_Come') }}</dt><dd class="num">{{ data.now.bookings_to_come }}</dd></div>
            <div><dt>{{ $t('Rst_Online_Waiting') }}</dt><dd class="num">{{ data.now.online_waiting }}</dd></div>
            <div><dt>{{ $t('Rst_Avg_Kitchen_Today') }}</dt><dd class="num">{{ data.now.avg_prep_minutes !== null ? $t('Rst_Minutes_N', { n: data.now.avg_prep_minutes }) : '—' }}</dd></div>
          </dl>
          <a-space wrap>
            <a-button v-if="auth.can('rst_floor')" type="link" style="padding: 0" @click="$router.push('/restaurant/floor')">{{ $t('Rst_Open_Floor_Plan') }} →</a-button>
            <a-button v-if="auth.can('rst_kitchen')" type="link" style="padding: 0" @click="$router.push('/restaurant/kitchen')">{{ $t('Rst_Kitchen') }} →</a-button>
          </a-space>
        </section>
      </div>

      <div class="row3">
        <section class="card">
          <div class="card-head">
            <h2>{{ $t('Rst_Top_Dishes') }}</h2>
            <a-button v-if="auth.can('rst_reports')" size="small" type="link" @click="$router.push({ path: '/restaurant/reports', query: { tab: 'dishes', from: data.from, to: data.to } })">{{ $t('Rst_All_Dishes') }}</a-button>
          </div>
          <a-table size="small" :pagination="false" :columns="dishCols" :data-source="data.dishes" row-key="id" :locale="{ emptyText: $t('Rst_No_Sales_Yet') }" :scroll="{ x: 420 }" />
        </section>

        <section class="card">
          <div class="card-head"><h2>{{ $t('Rst_Order_Types') }}</h2></div>
          <div v-for="tp in data.types" :key="tp.type" class="type-row">
            <div class="type-line"><span>{{ typeLabel(tp.type) }}</span><span class="num">{{ moneyBase(tp.sales) }} · {{ tp.pct }}%</span></div>
            <div class="bar-track" role="img" :aria-label="`${typeLabel(tp.type)} ${tp.pct}%`"><div class="bar-fill" :style="{ width: tp.pct + '%' }" /></div>
            <span class="muted small">{{ $t('Rst_Orders_N', { n: tp.orders }) }}</span>
          </div>
        </section>

        <section class="card">
          <div class="card-head"><h2>{{ $t('Rst_Waiters') }}</h2></div>
          <a-empty v-if="!data.waiters.length" :description="$t('Rst_No_Sales_Yet')" />
          <div v-for="w in data.waiters" :key="w.id" class="waiter">
            <span class="avatar" aria-hidden="true">{{ (w.name || '?').slice(0, 1).toUpperCase() }}</span>
            <span class="waiter-body"><strong>{{ w.name }}</strong><span class="muted small">{{ $t('Rst_Waiter_Line', { orders: w.orders, covers: w.covers, avg: moneyBase(w.avg_bill) }) }}</span></span>
            <span class="num strong">{{ moneyBase(w.sales) }}</span>
          </div>
        </section>
      </div>
      <p class="muted small">{{ $t('Rst_Dashboard_Note') }}</p>
    </template>
  </div>
</template>

<script setup>
/**
 * Restaurant › Dashboard (permission rst_dashboard) — GET restaurant/dashboard?period&from&to&warehouse_id.
 * Sales = restaurant bills + online orders that reached the kitchen (see App\Services\Restaurant\ReportService).
 * Chart colours (purple / amber, brighter steps in dark mode) pass the dataviz palette checks.
 */
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import { useI18n } from 'vue-i18n';
import { ReloadOutlined, ArrowUpOutlined, ArrowDownOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';
import { useAuthStore } from '../../stores/auth';
import { useUiStore } from '../../stores/ui';
import { useFormat } from '../../composables/useFormat';

const { t } = useI18n();
const auth = useAuthStore();
const ui = useUiStore();
const { moneyBase } = useFormat();

const first = ref(true);
const loading = ref(false);
const ready = ref(true);
const data = ref(null);
const meta = ref({});
const branches = ref([]);
const period = ref('today');
const range = ref([]);
const branch = ref(undefined);
const asTable = ref(false);
let timer = null;

const periodOptions = computed(() => [
  { value: 'today', label: t('Rst_Today') },
  { value: 'week', label: t('Rst_Last_7_Days') },
  { value: 'month', label: t('Rst_This_Month') },
  { value: 'custom', label: t('Rst_Custom') },
]);
const byDay = computed(() => !!(data.value && data.value.days && data.value.days.length));
const compareLabel = computed(() => (period.value === 'today' ? t('Rst_Vs_Same_Day_Last_Week') : t('Rst_Vs_Previous_Period')));

function delta(cur, prev) {
  if (!prev) return cur ? { dir: 'up', text: t('Rst_New_Vs', { what: compareLabel.value }) } : null;
  const pct = Math.round(((cur - prev) / prev) * 100);
  return { dir: pct > 0 ? 'up' : pct < 0 ? 'down' : 'flat', text: `${pct > 0 ? '+' : ''}${pct}% ${compareLabel.value}` };
}
const kpis = computed(() => {
  const k = data.value.kpis;
  return [
    { key: 'sales', label: t('Rst_Sales'), value: moneyBase(k.sales.value), delta: delta(k.sales.value, k.sales.prev) },
    { key: 'bills', label: t('Rst_Bills'), value: k.bills.value, delta: delta(k.bills.value, k.bills.prev) },
    { key: 'covers', label: t('Rst_Covers'), value: k.covers.value, delta: delta(k.covers.value, k.covers.prev) },
    { key: 'avg', label: t('Rst_Average_Bill'), value: moneyBase(k.avg_bill.value), delta: delta(k.avg_bill.value, k.avg_bill.prev) },
    { key: 'voids', label: t('Rst_Voids_Discounts'), value: moneyBase(k.voids.value), note: t('Rst_Voids_Discounts_N', { voids: k.voids.void_count, discounts: k.voids.discount_count }) },
  ];
});

// Hours with sales (padded by one), or every day of the range.
const salesPoints = computed(() => {
  if (!data.value) return [];
  if (byDay.value) {
    // MM-DD unless the range spans two years (then the full date, so labels stay unique).
    const days = data.value.days;
    const full = days.length && days[0].date.slice(0, 4) !== days[days.length - 1].date.slice(0, 4);
    return days.map(d => ({ key: d.date, label: full ? d.date : d.date.slice(5), dine_in: d.dine_in, other: d.other }));
  }
  const hrs = data.value.hours.filter(h => h.dine_in || h.other).map(h => h.hour);
  if (!hrs.length) return [];
  const from = Math.max(0, Math.min(...hrs) - 1);
  const to = Math.min(23, Math.max(...hrs) + 1);
  return data.value.hours.filter(h => h.hour >= from && h.hour <= to).map(h => ({ key: 'h' + h.hour, label: String(h.hour).padStart(2, '0') + ':00', dine_in: h.dine_in, other: h.other }));
});
const salesCols = computed(() => [
  { title: byDay.value ? t('Date') : t('Rst_Hour'), dataIndex: 'label' },
  { title: t('Rst_Dine_In'), dataIndex: 'dine_in', align: 'right', customRender: ({ value }) => moneyBase(value) },
  { title: t('Rst_Takeaway_Online'), dataIndex: 'other', align: 'right', customRender: ({ value }) => moneyBase(value) },
]);
const salesChart = computed(() => {
  const dark = ui.dark;
  const ink = dark ? '#c9c9d6' : '#54546a';
  return {
    series: [
      { name: t('Rst_Dine_In'), data: salesPoints.value.map(p => p.dine_in) },
      { name: t('Rst_Takeaway_Online'), data: salesPoints.value.map(p => p.other) },
    ],
    options: {
      chart: { type: 'bar', stacked: true, background: 'transparent', foreColor: ink, fontFamily: 'inherit', toolbar: { show: false } },
      colors: dark ? ['#8b5cf6', '#d97706'] : ['#6d28d9', '#d97706'],
      plotOptions: { bar: { columnWidth: '55%', borderRadius: 4, borderRadiusApplication: 'end', borderRadiusWhenStacked: 'last' } },
      stroke: { show: true, width: 2, colors: [dark ? '#1f1f1f' : '#ffffff'] },
      dataLabels: { enabled: false },
      legend: { position: 'top', horizontalAlign: 'left', labels: { colors: ink }, markers: { size: 6 } },
      xaxis: { categories: salesPoints.value.map(p => p.label), axisBorder: { show: false }, axisTicks: { show: false } },
      yaxis: { labels: { formatter: v => moneyBase(v) } },
      grid: { borderColor: dark ? 'rgba(255,255,255,0.08)' : 'rgba(0,0,0,0.06)', strokeDashArray: 4 },
      tooltip: { theme: dark ? 'dark' : 'light', shared: true, intersect: false, y: { formatter: v => moneyBase(v) } },
      states: { hover: { filter: { type: 'darken', value: 0.9 } } },
    },
  };
});
const dishCols = computed(() => [
  { title: t('Rst_Dish'), dataIndex: 'name', ellipsis: true },
  { title: t('Rst_Sold'), dataIndex: 'qty', align: 'right', width: 70, customRender: ({ value }) => (Number.isInteger(value) ? value : Number(value).toFixed(1)) },
  { title: t('Rst_Sales'), dataIndex: 'sales', align: 'right', customRender: ({ value }) => moneyBase(value) },
  { title: t('Rst_Margin'), dataIndex: 'margin_pct', align: 'right', width: 80, customRender: ({ value }) => (value === null ? '—' : value + '%') },
]);

function typeLabel(type) { return { dine_in: t('Rst_Dine_In'), takeaway: t('Rst_Takeaway'), delivery: t('Rst_Delivery'), online: t('Rst_Online_Orders') }[type] || type; }

function onPeriod() {
  if (period.value === 'custom' && range.value.length !== 2) {
    const end = new Date();
    const start = new Date(); start.setDate(end.getDate() - 6);
    const f = d => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
    range.value = [f(start), f(end)];
  }
  load();
}
async function load() {
  loading.value = true;
  try {
    const params = { period: period.value };
    if (period.value === 'custom' && range.value.length === 2) { params.from = range.value[0]; params.to = range.value[1]; }
    if (branch.value) params.warehouse_id = branch.value;
    const r = await http.get('restaurant/dashboard', params);
    ready.value = r.ready !== false;
    if (r.ready !== false) {
      data.value = { ...r.data, from: r.from, to: r.to };
      branches.value = r.warehouses || [];
      meta.value = r;
    }
  } catch (e) { /* keep the last figures */ } finally {
    loading.value = false;
    first.value = false;
  }
}
onMounted(() => {
  load();
  timer = setInterval(() => { if (!document.hidden && period.value === 'today') load(); }, 60000);
});
onBeforeUnmount(() => clearInterval(timer));
</script>

<style scoped>
.rst-dash { display: flex; flex-direction: column; gap: 16px; }
.loading { display: flex; justify-content: center; padding: 96px 0; }
.num { font-variant-numeric: tabular-nums; }
.strong { font-weight: 600; }
.muted { color: rgba(0, 0, 0, 0.55); }
.small { font-size: 12px; }
.kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 12px; }
.kpi, .card { background: var(--ant-color-bg-container, #fff); border: 1px solid rgba(5, 5, 5, 0.06); border-radius: 14px; }
.kpi { padding: 16px; display: flex; flex-direction: column; gap: 6px; }
.kpi-label { font-size: 13px; color: rgba(0, 0, 0, 0.55); }
.kpi-value { font-size: 26px; line-height: 1.15; }
.kpi-delta { font-size: 12px; font-weight: 600; display: inline-flex; gap: 4px; align-items: center; }
.kpi-delta.up { color: #166534; }
.kpi-delta.down { color: #b91c1c; }
.kpi-delta.flat { color: rgba(0, 0, 0, 0.55); font-weight: 500; }
.row2, .row3 { display: flex; gap: 16px; flex-wrap: wrap; }
.card { padding: 16px 18px; min-width: 0; flex: 1 1 320px; }
.grow2 { flex: 2 1 560px; }
.grow1 { flex: 1 1 320px; display: flex; flex-direction: column; gap: 12px; }
.card-head { display: flex; justify-content: space-between; align-items: center; gap: 8px; margin-bottom: 8px; }
.card-head h2 { margin: 0; font-size: 16px; }
.now-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
.now { border-radius: 12px; padding: 12px; display: flex; flex-direction: column; gap: 2px; }
.now span { font-size: 12px; }
.now strong { font-size: 22px; }
.t-violet { background: #f5f3ff; color: #4c1d95; }
.t-amber { background: #fffbeb; color: #78350f; }
.t-green { background: #f0fdf4; color: #14532d; }
.t-red { background: #fef2f2; color: #7f1d1d; }
.now-list { margin: 0; display: flex; flex-direction: column; gap: 8px; font-size: 14px; }
.now-list div { display: flex; justify-content: space-between; gap: 8px; }
.now-list dt { color: rgba(0, 0, 0, 0.6); }
.now-list dd { margin: 0; font-weight: 700; }
.type-row { display: flex; flex-direction: column; gap: 4px; margin-bottom: 12px; }
.type-line { display: flex; justify-content: space-between; gap: 8px; font-size: 14px; }
.bar-track { height: 8px; border-radius: 4px; background: rgba(0, 0, 0, 0.06); overflow: hidden; }
.bar-fill { height: 8px; border-radius: 4px; background: #6d28d9; min-width: 2px; }
.waiter { display: flex; align-items: center; gap: 10px; padding: 6px 0; font-size: 14px; }
.avatar { width: 34px; height: 34px; border-radius: 999px; background: #ede9fe; color: #5b21b6; font-weight: 700; display: inline-flex; align-items: center; justify-content: center; flex: none; }
.waiter-body { flex: 1; min-width: 0; display: flex; flex-direction: column; }
:global(html.dark) .rst-dash .muted, :global(html.dark) .rst-dash .kpi-label, :global(html.dark) .rst-dash .kpi-delta.flat { color: rgba(255, 255, 255, 0.6); }
:global(html.dark) .rst-dash .kpi, :global(html.dark) .rst-dash .card { border-color: rgba(255, 255, 255, 0.08); }
:global(html.dark) .rst-dash .bar-track { background: rgba(255, 255, 255, 0.1); }
:global(html.dark) .rst-dash .bar-fill { background: #8b5cf6; }
:global(html.dark) .rst-dash .kpi-delta.up { color: #4ade80; }
:global(html.dark) .rst-dash .kpi-delta.down { color: #f87171; }
:global(html.dark) .rst-dash .now-list dt { color: rgba(255, 255, 255, 0.65); }
</style>
