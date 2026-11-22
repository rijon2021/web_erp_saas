<template>
  <div class="page bkm-dash">
    <PageHeader :title="$t('Bkm_Dashboard')" :breadcrumb="[$t('Booking Management'), $t('Bkm_Dashboard')]">
      <template #actions>
        <a-space wrap>
          <a-segmented v-model:value="period" :options="periodOptions" @change="onPeriod" />
          <a-range-picker v-if="period === 'custom'" v-model:value="range" value-format="YYYY-MM-DD" :allow-clear="false" @change="load" />
          <a-select v-if="branches.length > 1" v-model:value="branch" allow-clear :placeholder="$t('Bkm_All_Branches')" style="min-width: 160px" :options="branches.map(b => ({ value: b.id, label: b.name }))" @change="load" />
          <a-button :loading="loading" :aria-label="$t('Bkm_Refresh')" @click="load"><ReloadOutlined /></a-button>
        </a-space>
      </template>
    </PageHeader>

    <div v-if="first" class="loading"><a-spin size="large" /></div>
    <a-alert v-else-if="!ready" type="warning" show-icon :message="$t('Bkm_Not_Ready')" />

    <template v-else-if="data">
      <!-- KPI tiles -->
      <div class="kpis">
        <div v-for="k in kpis" :key="k.key" class="kpi">
          <span class="kpi-label">{{ k.label }}</span>
          <strong class="kpi-value num">{{ k.value }}</strong>
          <span v-if="k.delta" :class="['kpi-delta', k.delta.tone]">
            <ArrowUpOutlined v-if="k.delta.dir === 'up'" /><ArrowDownOutlined v-else-if="k.delta.dir === 'down'" />
            {{ k.delta.text }}
          </span>
          <span v-else-if="k.note" class="kpi-delta flat">{{ k.note }}</span>
        </div>
      </div>

      <div class="row2">
        <!-- Bookings over time -->
        <section class="card grow2">
          <div class="card-head">
            <h2>{{ byDay ? $t('Bkm_Bookings_By_Day') : $t('Bkm_Bookings_By_Hour') }}</h2>
            <a-button size="small" type="link" @click="asTable = !asTable">{{ asTable ? $t('Bkm_Show_Chart') : $t('Bkm_Show_Table') }}</a-button>
          </div>
          <a-empty v-if="!points.length" :description="$t('Bkm_No_Bookings_Period')" />
          <template v-else>
            <apexchart v-if="!asTable" type="bar" height="260" :options="chart.options" :series="chart.series" />
            <a-table v-else size="small" :pagination="false" :columns="pointCols" :data-source="points" row-key="key" :scroll="{ y: 260 }" />
          </template>
          <p v-if="data.booked_value" class="muted small">{{ $t('Bkm_Booked_Value_Note', { amount: moneyBase(data.booked_value) }) }}</p>
        </section>

        <!-- Today -->
        <section v-if="data.today" class="card grow1">
          <div class="card-head">
            <h2>{{ $t('Bkm_Today') }}</h2>
            <a-button v-if="canBookings" type="link" size="small" style="padding: 0" @click="$router.push('/bookings/day')">{{ $t('Bkm_Day_View') }} →</a-button>
          </div>
          <div class="now-grid">
            <div class="now t-violet"><span>{{ $t('Bkm_Still_To_Come') }}</span><strong class="num">{{ data.today.to_come }}</strong></div>
            <div class="now t-green"><span>{{ $t('Bkm_Here_Now') }}</span><strong class="num">{{ data.today.arrived }}</strong></div>
            <button v-if="canBookings" type="button" class="now t-amber now-btn" @click="$router.push('/bookings/requests')">
              <span>{{ $t('Bkm_Requests_Waiting') }}</span><strong class="num">{{ data.today.requests }}</strong>
            </button>
            <div v-else class="now t-amber"><span>{{ $t('Bkm_Requests_Waiting') }}</span><strong class="num">{{ data.today.requests }}</strong></div>
            <div class="now t-red"><span>{{ $t('Bkm_Not_Charged') }}</span><strong class="num">{{ data.today.not_charged }}</strong></div>
          </div>
          <p v-if="data.today.proposals || data.today.expired_holds" class="muted small">
            <span v-if="data.today.proposals">{{ $t('Bkm_Proposals_Waiting_N', { n: data.today.proposals }) }}</span>
            <span v-if="data.today.proposals && data.today.expired_holds"> · </span>
            <span v-if="data.today.expired_holds">{{ $t('Bkm_Expired_Holds_N', { n: data.today.expired_holds }) }}</span>
          </p>
          <a-empty v-if="!data.today.schedule.length" :description="$t('Bkm_Nothing_Today')" />
          <ul v-else class="sched">
            <li v-for="b in data.today.schedule" :key="b.id">
              <button type="button" :class="['sched-row', { past: b.past }]" @click="openBooking(b.id)">
                <span class="sched-time num">{{ b.time }}<template v-if="b.end_time">–{{ b.end_time }}</template></span>
                <span class="dot" :style="{ background: b.staff_color || '#94a3b8' }" aria-hidden="true" />
                <span class="sched-body">
                  <strong>{{ b.customer || b.ref }}</strong>
                  <span class="muted small">{{ [b.service, b.staff].filter(Boolean).join(' · ') }}</span>
                </span>
                <a-tag :color="statusTag(b.status)" class="sched-tag">{{ statusLabel(b.status) }}</a-tag>
              </button>
            </li>
          </ul>
          <p v-if="data.today.more" class="muted small">{{ $t('Bkm_More_Today_N', { n: data.today.more }) }}</p>
          <p class="muted small">{{ $t('Bkm_Next_7_Days_N', { n: data.today.next_7_days }) }}</p>
        </section>
      </div>

      <div class="row3">
        <section class="card">
          <div class="card-head">
            <h2>{{ $t('Bkm_Top_Services') }}</h2>
            <a-button v-if="auth.can('bookings_reports')" size="small" type="link" @click="toReport('services')">{{ $t('Bkm_All_Services') }}</a-button>
          </div>
          <a-table size="small" :pagination="false" :columns="serviceCols" :data-source="data.services" :row-key="r => r.id || 'none'" :locale="{ emptyText: $t('Bkm_No_Bookings_Period') }" :scroll="{ x: 360 }" />
        </section>

        <section class="card">
          <div class="card-head">
            <h2>{{ $t('Bkm_Staff') }}</h2>
            <a-button v-if="auth.can('bookings_reports')" size="small" type="link" @click="toReport('staff')">{{ $t('Bkm_Staff_Report') }}</a-button>
          </div>
          <a-empty v-if="!data.staff.length" :description="$t('Bkm_No_Bookings_Period')" />
          <div v-for="s in data.staff" :key="s.id || 'none'" class="person">
            <span class="avatar" :style="avatarStyle(s.color)" aria-hidden="true">{{ (s.name || '?').slice(0, 1).toUpperCase() }}</span>
            <span class="person-body">
              <strong>{{ s.name || $t('Bkm_Unassigned') }}</strong>
              <span class="muted small">{{ $t('Bkm_Staff_Line', { bookings: s.bookings, completed: s.completed, hours: s.booked_hours }) }}</span>
            </span>
            <span v-if="money" class="num strong">{{ moneyBase(s.revenue) }}</span>
          </div>
        </section>

        <section class="card">
          <div class="card-head"><h2>{{ $t('Bkm_By_Status') }}</h2></div>
          <a-empty v-if="!data.statuses.length" :description="$t('Bkm_No_Bookings_Period')" />
          <div v-for="s in data.statuses" :key="s.status" class="type-row">
            <div class="type-line"><span>{{ statusLabel(s.status) }}</span><span class="num">{{ s.bookings }} · {{ share(s.bookings, statusTotal) }}%</span></div>
            <div class="bar-track" role="img" :aria-label="`${statusLabel(s.status)} ${share(s.bookings, statusTotal)}%`">
              <div class="bar-fill" :style="{ width: share(s.bookings, statusTotal) + '%', background: statusColors(s.status).border }" />
            </div>
          </div>
          <h3 class="sub">{{ $t('Bkm_Booked_Via') }}</h3>
          <div v-for="s in data.sources" :key="s.source" class="type-line">
            <span>{{ s.source === 'website' ? $t('Bkm_Source_Website') : $t('Bkm_Source_Staff') }}</span>
            <span class="num">{{ s.bookings }} · {{ share(s.bookings, sourceTotal) }}%</span>
          </div>
        </section>
      </div>
      <p class="muted small">{{ money ? $t('Bkm_Dashboard_Note') : $t('Bkm_Dashboard_Note_No_Money') }}</p>
    </template>

    <BookingQuickView v-model:open="viewOpen" :booking-id="viewId" @changed="load" />
  </div>
</template>

<script setup>
/**
 * Booking Management › Dashboard (permission bookings, money with bookings_reports) —
 * GET bookings/dashboard?period&from&to&warehouse_id (App\Services\Booking\BookingReportService).
 * Bookings count by appointment date; website figures by when the request arrived.
 * Chart colours (violet / amber / pink, brighter violet + pink in dark mode) pass the dataviz palette checks.
 */
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import { useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { ReloadOutlined, ArrowUpOutlined, ArrowDownOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';
import { useAuthStore } from '../../stores/auth';
import { useUiStore } from '../../stores/ui';
import { useFormat } from '../../composables/useFormat';
import BookingQuickView from './BookingQuickView.vue';
import { statusLabel, statusTag, statusColors } from './bookingStatus';

const { t } = useI18n();
const router = useRouter();
const auth = useAuthStore();
const ui = useUiStore();
const { moneyBase } = useFormat();

const first = ref(true);
const loading = ref(false);
const ready = ref(true);
const data = ref(null);
const money = ref(false);
const branches = ref([]);
const period = ref('today');
const range = ref([]);
const branch = ref(undefined);
const asTable = ref(false);
const viewOpen = ref(false);
const viewId = ref(null);
let timer = null;

const periodOptions = computed(() => [
  { value: 'today', label: t('Bkm_Today') },
  { value: 'week', label: t('Bkm_Last_7_Days') },
  { value: 'next7', label: t('Bkm_Next_7_Days') },
  { value: 'month', label: t('Bkm_This_Month') },
  { value: 'custom', label: t('Bkm_Custom') },
]);
const byDay = computed(() => !!(data.value && data.value.days && data.value.days.length));
const compareLabel = computed(() => (period.value === 'today' ? t('Bkm_Vs_Same_Day_Last_Week') : t('Bkm_Vs_Previous_Period')));

// higherIsBetter=false for rates where up is bad (no-shows, cancellations, answer time).
function delta(cur, prev, { points = false, higherIsBetter = true } = {}) {
  if (cur === null || cur === undefined || prev === null || prev === undefined) return null;
  if (points) {
    const d = cur - prev;
    const dir = d > 0 ? 'up' : d < 0 ? 'down' : 'flat';
    return { dir, tone: tone(dir, higherIsBetter), text: `${d > 0 ? '+' : ''}${d} ${t('Bkm_Points')} ${compareLabel.value}` };
  }
  if (!prev) return cur ? { dir: 'up', tone: tone('up', higherIsBetter), text: t('Bkm_New_Vs', { what: compareLabel.value }) } : null;
  const pct = Math.round(((cur - prev) / prev) * 100);
  const dir = pct > 0 ? 'up' : pct < 0 ? 'down' : 'flat';
  return { dir, tone: tone(dir, higherIsBetter), text: `${pct > 0 ? '+' : ''}${pct}% ${compareLabel.value}` };
}
function tone(dir, higherIsBetter) {
  if (dir === 'flat') return 'flat';
  return (dir === 'up') === higherIsBetter ? 'good' : 'bad';
}
const pctText = v => (v === null || v === undefined ? '—' : v + '%');
function minutesText(v) {
  if (v === null || v === undefined) return '—';
  if (v < 60) return t('Bkm_Minutes_N', { n: v });
  if (v < 1440) return t('Bkm_Hours_N', { n: Math.round(v / 6) / 10 });
  return t('Bkm_Days_N', { n: Math.round(v / 144) / 10 });
}
const kpis = computed(() => {
  const k = data.value.kpis;
  const list = [
    { key: 'bookings', label: t('Bkm_Bookings'), value: k.bookings.value, delta: delta(k.bookings.value, k.bookings.prev) },
    { key: 'completed', label: t('Completed'), value: k.completed.value, delta: delta(k.completed.value, k.completed.prev) },
    { key: 'no_show', label: t('Bkm_No_Show_Rate'), value: pctText(k.no_show_rate.value), delta: delta(k.no_show_rate.value, k.no_show_rate.prev, { points: true, higherIsBetter: false }) },
    { key: 'cancel', label: t('Bkm_Cancel_Rate'), value: pctText(k.cancel_rate.value), delta: delta(k.cancel_rate.value, k.cancel_rate.prev, { points: true, higherIsBetter: false }) },
  ];
  if (money.value && k.revenue) {
    list.push({ key: 'revenue', label: t('Bkm_Revenue'), value: moneyBase(k.revenue.value), delta: delta(k.revenue.value, k.revenue.prev) });
  }
  list.push(
    { key: 'website', label: t('Bkm_Website_Requests'), value: k.website.value, delta: delta(k.website.value, k.website.prev) },
    { key: 'accepted', label: t('Bkm_Accepted_Rate'), value: pctText(k.conversion.value), delta: delta(k.conversion.value, k.conversion.prev, { points: true }), note: t('Bkm_Accepted_Rate_Note') },
    { key: 'answer', label: t('Bkm_Avg_Answer_Time'), value: minutesText(k.answer_minutes.value), delta: delta(k.answer_minutes.value, k.answer_minutes.prev, { higherIsBetter: false }) },
  );
  return list;
});

// Hours with bookings (padded by one), or every day of the range.
const points = computed(() => {
  if (!data.value) return [];
  const has = p => p.kept || p.pending || p.lost;
  if (byDay.value) {
    const days = data.value.days;
    if (!days.some(has)) return [];
    const full = days[0].date.slice(0, 4) !== days[days.length - 1].date.slice(0, 4);
    return days.map(d => ({ key: d.date, label: full ? d.date : d.date.slice(5), kept: d.kept, pending: d.pending, lost: d.lost }));
  }
  const hrs = data.value.hours.filter(has).map(h => h.hour);
  if (!hrs.length) return [];
  const from = Math.max(0, Math.min(...hrs) - 1);
  const to = Math.min(23, Math.max(...hrs) + 1);
  return data.value.hours.filter(h => h.hour >= from && h.hour <= to).map(h => ({ key: 'h' + h.hour, label: String(h.hour).padStart(2, '0') + ':00', kept: h.kept, pending: h.pending, lost: h.lost }));
});
const pointCols = computed(() => [
  { title: byDay.value ? t('Date') : t('Bkm_Hour'), dataIndex: 'label' },
  { title: t('Bkm_Series_Kept'), dataIndex: 'kept', align: 'right' },
  { title: t('Bkm_Series_Pending'), dataIndex: 'pending', align: 'right' },
  { title: t('Bkm_Series_Lost'), dataIndex: 'lost', align: 'right' },
]);
const chart = computed(() => {
  const dark = ui.dark;
  const ink = dark ? '#c9c9d6' : '#54546a';
  return {
    series: [
      { name: t('Bkm_Series_Kept'), data: points.value.map(p => p.kept) },
      { name: t('Bkm_Series_Pending'), data: points.value.map(p => p.pending) },
      { name: t('Bkm_Series_Lost'), data: points.value.map(p => p.lost) },
    ],
    options: {
      chart: { type: 'bar', stacked: true, background: 'transparent', foreColor: ink, fontFamily: 'inherit', toolbar: { show: false } },
      colors: dark ? ['#8b5cf6', '#d97706', '#ec4899'] : ['#6d28d9', '#d97706', '#db2777'],
      plotOptions: { bar: { columnWidth: '55%', borderRadius: 4, borderRadiusApplication: 'end', borderRadiusWhenStacked: 'last' } },
      stroke: { show: true, width: 2, colors: [dark ? '#1f1f1f' : '#ffffff'] },
      dataLabels: { enabled: false },
      legend: { position: 'top', horizontalAlign: 'left', labels: { colors: ink }, markers: { size: 6 } },
      xaxis: { categories: points.value.map(p => p.label), axisBorder: { show: false }, axisTicks: { show: false } },
      yaxis: { forceNiceScale: true, labels: { formatter: v => Math.round(v) } },
      grid: { borderColor: dark ? 'rgba(255,255,255,0.08)' : 'rgba(0,0,0,0.06)', strokeDashArray: 4 },
      tooltip: { theme: dark ? 'dark' : 'light', shared: true, intersect: false },
      states: { hover: { filter: { type: 'darken', value: 0.9 } } },
    },
  };
});
const serviceCols = computed(() => {
  const cols = [
    { title: t('Bkm_Service'), dataIndex: 'name', ellipsis: true },
    { title: t('Bkm_Bookings'), dataIndex: 'bookings', align: 'right', width: 90 },
    { title: t('Completed'), dataIndex: 'completed', align: 'right', width: 96 },
  ];
  if (money.value) cols.push({ title: t('Bkm_Revenue'), dataIndex: 'revenue', align: 'right', customRender: ({ value }) => moneyBase(value) });
  return cols;
});
const statusTotal = computed(() => (data.value ? data.value.statuses.reduce((a, s) => a + s.bookings, 0) : 0));
const sourceTotal = computed(() => (data.value ? data.value.sources.reduce((a, s) => a + s.bookings, 0) : 0));
function share(n, total) { return total ? Math.round((n / total) * 100) : 0; }
function avatarStyle(color) { return color ? { background: color, color: '#fff' } : {}; }

/** Booking details and actions need the bookings permission (reports alone only shows figures). */
const canBookings = computed(() => auth.can('bookings'));
function openBooking(id) { if (!canBookings.value) return; viewId.value = id; viewOpen.value = true; }
function toReport(tab) { router.push({ path: '/bookings/reports', query: { tab, from: data.value.from, to: data.value.to } }); }

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
    const r = await http.get('bookings/dashboard', params);
    ready.value = r.ready !== false;
    if (r.ready !== false) {
      data.value = { ...r.data, from: r.from, to: r.to };
      money.value = !!r.money;
      branches.value = r.warehouses || [];
    }
  } catch (e) { /* keep the last figures */ } finally {
    loading.value = false;
    first.value = false;
  }
}
onMounted(() => {
  load();
  timer = setInterval(() => { if (!document.hidden && !viewOpen.value && data.value && data.value.today) load(); }, 60000);
});
onBeforeUnmount(() => clearInterval(timer));
</script>

<style scoped>
.bkm-dash { display: flex; flex-direction: column; gap: 16px; }
.loading { display: flex; justify-content: center; padding: 96px 0; }
.num { font-variant-numeric: tabular-nums; }
.strong { font-weight: 600; }
.muted { color: rgba(0, 0, 0, 0.55); }
.small { font-size: 12px; }
.kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 12px; }
.kpi, .card { background: var(--ant-color-bg-container, #fff); border: 1px solid rgba(5, 5, 5, 0.06); border-radius: 14px; }
.kpi { padding: 16px; display: flex; flex-direction: column; gap: 6px; }
.kpi-label { font-size: 13px; color: rgba(0, 0, 0, 0.55); }
.kpi-value { font-size: 26px; line-height: 1.15; }
.kpi-delta { font-size: 12px; font-weight: 600; display: inline-flex; gap: 4px; align-items: center; }
.kpi-delta.good { color: #166534; }
.kpi-delta.bad { color: #b91c1c; }
.kpi-delta.flat { color: rgba(0, 0, 0, 0.55); font-weight: 500; }
.row2, .row3 { display: flex; gap: 16px; flex-wrap: wrap; }
.card { padding: 16px 18px; min-width: 0; flex: 1 1 320px; }
.grow2 { flex: 2 1 560px; }
.grow1 { flex: 1 1 340px; display: flex; flex-direction: column; gap: 12px; }
.card-head { display: flex; justify-content: space-between; align-items: center; gap: 8px; margin-bottom: 8px; }
.card-head h2 { margin: 0; font-size: 16px; }
.sub { font-size: 14px; margin: 14px 0 6px; }
.now-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
.now { border-radius: 12px; padding: 12px; display: flex; flex-direction: column; gap: 2px; text-align: start; border: 0; font: inherit; }
.now span { font-size: 12px; }
.now strong { font-size: 22px; }
.now-btn { cursor: pointer; }
.now-btn:hover { filter: brightness(0.97); }
.now-btn:focus-visible { outline: 2px solid #6d28d9; outline-offset: 2px; }
.t-violet { background: #f5f3ff; color: #4c1d95; }
.t-amber { background: #fffbeb; color: #78350f; }
.t-green { background: #f0fdf4; color: #14532d; }
.t-red { background: #fef2f2; color: #7f1d1d; }
.sched { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 2px; max-height: 360px; overflow-y: auto; }
.sched-row { width: 100%; display: flex; align-items: center; gap: 10px; padding: 7px 8px; border: 0; background: transparent; border-radius: 8px; text-align: start; cursor: pointer; font: inherit; color: inherit; }
.sched-row:hover { background: rgba(109, 40, 217, 0.06); }
.sched-row:focus-visible { outline: 2px solid #6d28d9; outline-offset: 1px; }
.sched-row.past { opacity: 0.6; }
.sched-time { width: 92px; flex: none; font-size: 13px; font-weight: 600; }
.dot { width: 10px; height: 10px; border-radius: 999px; flex: none; }
.sched-body { flex: 1; min-width: 0; display: flex; flex-direction: column; }
.sched-body strong, .sched-body span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.sched-tag { margin: 0; flex: none; }
.type-row { display: flex; flex-direction: column; gap: 4px; margin-bottom: 10px; }
.type-line { display: flex; justify-content: space-between; gap: 8px; font-size: 14px; padding: 2px 0; }
.bar-track { height: 8px; border-radius: 4px; background: rgba(0, 0, 0, 0.06); overflow: hidden; }
.bar-fill { height: 8px; border-radius: 4px; min-width: 2px; }
.person { display: flex; align-items: center; gap: 10px; padding: 6px 0; font-size: 14px; }
.avatar { width: 34px; height: 34px; border-radius: 999px; background: #ede9fe; color: #5b21b6; font-weight: 700; display: inline-flex; align-items: center; justify-content: center; flex: none; }
.person-body { flex: 1; min-width: 0; display: flex; flex-direction: column; }
@media (max-width: 480px) {
  .sched-time { width: 76px; font-size: 12px; }
}
:global(html.dark) .bkm-dash .muted, :global(html.dark) .bkm-dash .kpi-label, :global(html.dark) .bkm-dash .kpi-delta.flat { color: rgba(255, 255, 255, 0.6); }
:global(html.dark) .bkm-dash .kpi, :global(html.dark) .bkm-dash .card { border-color: rgba(255, 255, 255, 0.08); }
:global(html.dark) .bkm-dash .bar-track { background: rgba(255, 255, 255, 0.1); }
:global(html.dark) .bkm-dash .kpi-delta.good { color: #4ade80; }
:global(html.dark) .bkm-dash .kpi-delta.bad { color: #f87171; }
:global(html.dark) .bkm-dash .sched-row:hover { background: rgba(139, 92, 246, 0.14); }
</style>
