<template>
  <div class="page bkm-rep">
    <PageHeader :title="$t('Bkm_Reports')" :breadcrumb="[$t('Booking Management'), $t('Bkm_Reports')]">
      <template #actions>
        <a-space wrap>
          <a-range-picker v-model:value="range" value-format="YYYY-MM-DD" :allow-clear="false" :presets="presets" @change="load" />
          <a-select v-if="branches.length > 1" v-model:value="branch" allow-clear :placeholder="$t('Bkm_All_Branches')" style="min-width: 160px" :options="branches.map(b => ({ value: b.id, label: b.name }))" @change="load" />
          <a-button :disabled="!exportRows.length" :loading="exporting" @click="doExport"><FileExcelOutlined /> {{ $t('Export_Excel') }}</a-button>
        </a-space>
      </template>
    </PageHeader>

    <a-tabs v-model:activeKey="tab" @change="load">
      <a-tab-pane key="bookings" :tab="$t('Bkm_Rep_Bookings')" />
      <a-tab-pane key="services" :tab="$t('Bkm_Rep_Services')" />
      <a-tab-pane key="staff" :tab="$t('Bkm_Rep_Staff')" />
      <a-tab-pane key="times" :tab="$t('Bkm_Rep_Times')" />
      <a-tab-pane key="website" :tab="$t('Bkm_Rep_Website')" />
      <a-tab-pane key="lost" :tab="$t('Bkm_Rep_Lost')" />
      <a-tab-pane key="customers" :tab="$t('Bkm_Rep_Customers')" />
    </a-tabs>

    <a-alert v-if="!ready" type="warning" show-icon :message="$t('Bkm_Not_Ready')" />
    <a-spin v-else :spinning="loading">
      <template v-if="data && dataType === tab">
        <!-- Bookings -->
        <template v-if="tab === 'bookings'">
          <div class="tiles">
            <div class="tile"><span>{{ $t('Bkm_Bookings') }}</span><strong class="num">{{ data.totals.total }}</strong></div>
            <div class="tile"><span>{{ $t('Completed') }}</span><strong class="num">{{ data.totals.completed }}</strong></div>
            <div class="tile"><span>{{ $t('Bkm_Status_No_Show') }}</span><strong class="num">{{ data.totals.no_show }}</strong></div>
            <div class="tile"><span>{{ $t('Cancelled') }}</span><strong class="num">{{ data.totals.cancelled }}</strong></div>
            <div class="tile"><span>{{ $t('Bkm_Revenue') }}</span><strong class="num">{{ moneyBase(data.totals.revenue) }}</strong></div>
            <div class="tile"><span>{{ $t('Bkm_Paid') }}</span><strong class="num">{{ moneyBase(data.totals.paid) }}</strong></div>
            <div class="tile"><span>{{ $t('Bkm_Booked_Value') }}</span><strong class="num">{{ moneyBase(data.totals.booked_value) }}</strong></div>
          </div>
          <a-table size="small" :columns="dayCols" :data-source="data.rows" row-key="date" :pagination="{ pageSize: 31, hideOnSinglePage: true }" :scroll="{ x: 1000 }" :locale="{ emptyText: $t('Bkm_No_Bookings_Period') }" />
          <p class="muted small">{{ $t('Bkm_Rep_Bookings_Note') }}</p>
        </template>

        <!-- Services -->
        <template v-else-if="tab === 'services'">
          <a-input-search v-model:value="search" allow-clear :placeholder="$t('Bkm_Search_Services')" style="max-width: 280px; margin-bottom: 12px" />
          <a-table size="small" :columns="serviceCols" :data-source="serviceRows" :row-key="r => r.id || 'none'" :pagination="{ pageSize: 50, hideOnSinglePage: true }" :scroll="{ x: 960 }" :locale="{ emptyText: $t('Bkm_No_Bookings_Period') }" />
        </template>

        <!-- Staff -->
        <template v-else-if="tab === 'staff'">
          <a-alert v-if="!data.utilisation" type="info" show-icon :message="$t('Bkm_Utilisation_Range', { n: data.util_max_days })" style="margin-bottom: 12px" />
          <a-table size="small" :columns="staffCols" :data-source="data.rows" :row-key="r => r.id || 'none'" :pagination="false" :scroll="{ x: 980 }" :locale="{ emptyText: $t('Bkm_No_Bookings_Period') }" />
          <p class="muted small">{{ $t('Bkm_Utilisation_Note') }}</p>
        </template>

        <!-- Busiest times -->
        <template v-else-if="tab === 'times'">
          <div class="tiles">
            <div class="tile"><span>{{ $t('Bkm_Bookings') }}</span><strong class="num">{{ data.total }}</strong></div>
            <div class="tile"><span>{{ $t('Bkm_Busiest_Slot') }}</span><strong>{{ data.busiest ? `${dayName(data.busiest.weekday)} ${hh(data.busiest.hour)}` : '—' }}</strong></div>
            <div class="tile"><span>{{ $t('Bkm_Avg_Lead') }}</span><strong class="num">{{ data.avg_lead_days === null ? '—' : $t('Bkm_Days_N', { n: data.avg_lead_days }) }}</strong></div>
          </div>
          <div class="card-head">
            <h3 class="sub">{{ $t('Bkm_Heatmap') }}</h3>
            <a-button size="small" type="link" @click="asTable = !asTable">{{ asTable ? $t('Bkm_Show_Chart') : $t('Bkm_Show_Table') }}</a-button>
          </div>
          <a-empty v-if="!data.cells.length" :description="$t('Bkm_No_Bookings_Period')" />
          <template v-else>
            <div v-if="!asTable" class="heat-wrap">
              <table class="heat" :aria-label="$t('Bkm_Heatmap')">
                <thead>
                  <tr><th scope="col" /><th v-for="h in heatHours" :key="h" scope="col" class="num">{{ String(h).padStart(2, '0') }}</th></tr>
                </thead>
                <tbody>
                  <tr v-for="wd in WEEKDAYS" :key="wd">
                    <th scope="row">{{ dayName(wd, 'short') }}</th>
                    <td v-for="h in heatHours" :key="h">
                      <span :class="['cell', 'l' + level(cellCount(wd, h))]" :title="`${dayName(wd)} ${hh(h)} · ${cellCount(wd, h)}`">{{ cellCount(wd, h) || '' }}</span>
                    </td>
                  </tr>
                </tbody>
              </table>
              <div class="heat-legend" aria-hidden="true">
                <span class="muted small">{{ $t('Bkm_Fewer') }}</span>
                <span v-for="l in 5" :key="l" :class="['cell', 'l' + l]" />
                <span class="muted small">{{ $t('Bkm_More') }}</span>
              </div>
            </div>
            <a-table v-else size="small" :columns="cellCols" :data-source="cellRows" :row-key="r => r.weekday + '-' + r.hour" :pagination="{ pageSize: 50, hideOnSinglePage: true }" style="max-width: 520px" />
          </template>
          <div class="two">
            <div>
              <h3 class="sub">{{ $t('Bkm_By_Weekday') }}</h3>
              <div v-for="w in data.weekdays" :key="w.weekday" class="type-row">
                <div class="type-line"><span>{{ dayName(w.weekday) }}</span><span class="num">{{ w.bookings }}</span></div>
                <div class="bar-track" role="img" :aria-label="`${dayName(w.weekday)} ${w.bookings}`"><div class="bar-fill" :style="{ width: pctOf(w.bookings, maxWeekday) + '%' }" /></div>
              </div>
            </div>
            <div>
              <h3 class="sub">{{ $t('Bkm_Lead_Time') }}</h3>
              <div v-for="b in data.lead" :key="b.bucket" class="type-row">
                <div class="type-line"><span>{{ leadLabel(b.bucket) }}</span><span class="num">{{ b.bookings }} · {{ pctOf(b.bookings, leadTotal) }}%</span></div>
                <div class="bar-track" role="img" :aria-label="`${leadLabel(b.bucket)} ${b.bookings}`"><div class="bar-fill" :style="{ width: pctOf(b.bookings, leadTotal) + '%' }" /></div>
              </div>
            </div>
          </div>
        </template>

        <!-- Website -->
        <template v-else-if="tab === 'website'">
          <div class="tiles">
            <div class="tile"><span>{{ $t('Bkm_Website_Requests') }}</span><strong class="num">{{ data.totals.requests }}</strong><em class="muted small">{{ $t('Bkm_Slot_Request_Split', { slot: data.totals.slot, request: data.totals.request }) }}</em></div>
            <div class="tile"><span>{{ $t('Bkm_Accepted_Rate') }}</span><strong class="num">{{ pctText(data.totals.conversion) }}</strong></div>
            <div class="tile"><span>{{ $t('Confirmed') }}</span><strong class="num">{{ data.totals.confirmed }}</strong></div>
            <div class="tile"><span>{{ $t('Bkm_Status_Declined') }}</span><strong class="num">{{ data.totals.declined }}</strong></div>
            <div class="tile"><span>{{ $t('Bkm_Cancelled_By_Customer') }}</span><strong class="num">{{ data.totals.cancelled_customer }}</strong></div>
            <div class="tile"><span>{{ $t('Bkm_Still_Pending') }}</span><strong class="num">{{ data.totals.pending }}</strong><em v-if="data.totals.expired" class="muted small">{{ $t('Bkm_Expired_Holds_N', { n: data.totals.expired }) }}</em></div>
            <div class="tile"><span>{{ $t('Bkm_Avg_Answer_Time') }}</span><strong class="num">{{ minutesText(data.totals.avg_answer_minutes) }}</strong><em class="muted small">{{ $t('Bkm_Median_N', { n: minutesText(data.totals.median_answer_minutes) }) }}</em></div>
            <div class="tile"><span>{{ $t('Bkm_Suggested_Times') }}</span><strong class="num">{{ data.totals.suggested }}</strong><em class="muted small">{{ $t('Bkm_Suggested_Kept_N', { n: data.totals.suggested_kept }) }}</em></div>
          </div>
          <div class="two">
            <div>
              <h3 class="sub">{{ $t('Bkm_Requests_By_Day') }}</h3>
              <a-table size="small" :columns="webDayCols" :data-source="data.days" row-key="date" :pagination="{ pageSize: 31, hideOnSinglePage: true }" :locale="{ emptyText: $t('Bkm_No_Requests') }" />
            </div>
            <div>
              <h3 class="sub">{{ $t('Bkm_Requests_By_Service') }}</h3>
              <a-table size="small" :columns="webServiceCols" :data-source="data.services" :row-key="r => r.id || 'none'" :pagination="{ pageSize: 20, hideOnSinglePage: true }" :locale="{ emptyText: $t('Bkm_No_Requests') }" />
            </div>
          </div>
          <p class="muted small">{{ $t('Bkm_Rep_Website_Note') }}</p>
        </template>

        <!-- Cancellations & no-shows -->
        <template v-else-if="tab === 'lost'">
          <div class="tiles">
            <div class="tile"><span>{{ $t('Cancelled') }}</span><strong class="num">{{ data.totals.cancelled }}</strong><em class="muted small">{{ $t('Bkm_By_Customer_N', { n: data.totals.by_customer }) }}</em></div>
            <div class="tile"><span>{{ $t('Bkm_Status_Declined') }}</span><strong class="num">{{ data.totals.declined }}</strong></div>
            <div class="tile"><span>{{ $t('Bkm_Status_No_Show') }}</span><strong class="num">{{ data.totals.no_show }}</strong></div>
            <div class="tile"><span>{{ $t('Bkm_Lost_Share') }}</span><strong class="num">{{ pctText(data.totals.share) }}</strong></div>
            <div class="tile"><span>{{ $t('Bkm_Lost_Value') }}</span><strong class="num">{{ moneyBase(data.totals.value) }}</strong></div>
          </div>
          <template v-if="data.reasons.length">
            <h3 class="sub">{{ $t('Bkm_Top_Reasons') }}</h3>
            <a-table size="small" :columns="reasonCols" :data-source="data.reasons" row-key="reason" :pagination="false" style="max-width: 560px" />
          </template>
          <h3 class="sub">{{ $t('Bkm_Lost_Bookings') }}</h3>
          <a-table size="small" :columns="lostCols" :data-source="data.rows" row-key="id" :pagination="{ pageSize: 25, hideOnSinglePage: true }" :scroll="{ x: 1000 }" :custom-row="r => (canBookings ? { onClick: () => openBooking(r.id), style: 'cursor: pointer' } : {})" :locale="{ emptyText: $t('Bkm_Nothing_Lost') }" />
          <p v-if="data.truncated" class="muted small">{{ $t('Bkm_List_Truncated') }}</p>
        </template>

        <!-- Customers -->
        <template v-else-if="tab === 'customers'">
          <div class="tiles">
            <div class="tile"><span>{{ $t('Bkm_Customers') }}</span><strong class="num">{{ data.totals.customers }}</strong></div>
            <div class="tile"><span>{{ $t('Bkm_New_Customers') }}</span><strong class="num">{{ data.totals.new }}</strong></div>
            <div class="tile"><span>{{ $t('Bkm_Returning_Customers') }}</span><strong class="num">{{ data.totals.returning }}</strong></div>
            <div class="tile"><span>{{ $t('Bkm_Repeat_Customers') }}</span><strong class="num">{{ data.totals.repeat }}</strong></div>
            <div class="tile"><span>{{ $t('Bkm_Avg_Bookings_Customer') }}</span><strong class="num">{{ data.totals.avg_bookings ?? '—' }}</strong></div>
          </div>
          <a-input-search v-model:value="search" allow-clear :placeholder="$t('Bkm_Search_Customers')" style="max-width: 280px; margin-bottom: 12px" />
          <a-table size="small" :columns="customerCols" :data-source="customerRows" row-key="id" :pagination="{ pageSize: 50, hideOnSinglePage: true }" :scroll="{ x: 900 }" :locale="{ emptyText: $t('Bkm_No_Bookings_Period') }" />
          <p class="muted small">{{ $t('Bkm_Rep_Customers_Note') }}</p>
        </template>
      </template>
    </a-spin>

    <BookingQuickView v-model:open="viewOpen" :booking-id="viewId" @changed="load" />
  </div>
</template>

<script setup>
/**
 * Booking Management › Reports (permission bookings_reports) — GET bookings/reports/{type}?from&to&warehouse_id;
 * types bookings | services | staff | times | website | lost | customers (App\Services\Booking\BookingReportService).
 * Heatmap = one-hue violet ramp, light → dark (darker steps flipped for dark mode).
 */
import { ref, computed, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import dayjs from 'dayjs';
import { FileExcelOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';
import { i18n } from '../../i18n';
import { exportExcel } from '../../lib/exporters';
import { useFormat } from '../../composables/useFormat';
import BookingQuickView from './BookingQuickView.vue';
import { useAuthStore } from '../../stores/auth';
import { statusLabel } from './bookingStatus';

const { t } = useI18n();
const route = useRoute();
const { moneyBase } = useFormat();

const TABS = ['bookings', 'services', 'staff', 'times', 'website', 'lost', 'customers'];
const WEEKDAYS = [1, 2, 3, 4, 5, 6, 7];
const tab = ref(TABS.includes(route.query.tab) ? route.query.tab : 'bookings');
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
const search = ref('');
const asTable = ref(false);
const viewOpen = ref(false);
const viewId = ref(null);

const presets = computed(() => [
  { label: t('Bkm_Today'), value: [dayjs(), dayjs()] },
  { label: t('Bkm_Last_7_Days'), value: [dayjs().subtract(6, 'day'), dayjs()] },
  { label: t('Bkm_Last_30_Days'), value: [dayjs().subtract(29, 'day'), dayjs()] },
  { label: t('Bkm_This_Month'), value: [dayjs().startOf('month'), dayjs().endOf('month')] },
  { label: t('Bkm_Last_Month'), value: [dayjs().subtract(1, 'month').startOf('month'), dayjs().subtract(1, 'month').endOf('month')] },
  { label: t('Bkm_Next_30_Days'), value: [dayjs(), dayjs().add(29, 'day')] },
]);

const money = ({ value }) => moneyBase(value);
const pctText = v => (v === null || v === undefined ? '—' : v + '%');
const hh = h => String(h).padStart(2, '0') + ':00';
function minutesText(v) {
  if (v === null || v === undefined) return '—';
  if (v < 60) return t('Bkm_Minutes_N', { n: v });
  if (v < 1440) return t('Bkm_Hours_N', { n: Math.round(v / 6) / 10 });
  return t('Bkm_Days_N', { n: Math.round(v / 144) / 10 });
}
function locale() {
  const l = i18n.global.locale;
  return (typeof l === 'string' ? l : l?.value) || 'en';
}
/** ISO weekday name in the user's language (2024-01-01 was a Monday). */
function dayName(wd, style = 'long') {
  try {
    return new Intl.DateTimeFormat(locale(), { weekday: style }).format(new Date(2024, 0, wd));
  } catch (e) {
    return String(wd);
  }
}
function leadLabel(b) {
  return { same_day: t('Bkm_Lead_Same_Day'), d1_2: t('Bkm_Lead_1_2'), d3_7: t('Bkm_Lead_3_7'), d8_30: t('Bkm_Lead_8_30'), d30_plus: t('Bkm_Lead_30_Plus') }[b] || b;
}
function pctOf(n, total) { return total ? Math.round((n / total) * 100) : 0; }

const dayCols = computed(() => [
  { title: t('Date'), dataIndex: 'date', fixed: 'left', width: 110 },
  { title: t('Bkm_Bookings'), dataIndex: 'total', align: 'right' },
  { title: t('Pending'), dataIndex: 'pending', align: 'right' },
  { title: t('Confirmed'), dataIndex: 'confirmed', align: 'right' },
  { title: t('Bkm_Status_Arrived'), dataIndex: 'arrived', align: 'right' },
  { title: t('Completed'), dataIndex: 'completed', align: 'right' },
  { title: t('Cancelled'), dataIndex: 'cancelled', align: 'right' },
  { title: t('Bkm_Status_Declined'), dataIndex: 'declined', align: 'right' },
  { title: t('Bkm_Status_No_Show'), dataIndex: 'no_show', align: 'right' },
  { title: t('Bkm_Seats'), dataIndex: 'seats', align: 'right' },
  { title: t('Bkm_Revenue'), dataIndex: 'revenue', align: 'right', customRender: money },
]);
const serviceCols = computed(() => [
  { title: t('Bkm_Service'), dataIndex: 'name', fixed: 'left', width: 200, ellipsis: true },
  { title: t('Category'), dataIndex: 'category', ellipsis: true },
  { title: t('Bkm_Bookings'), dataIndex: 'bookings', align: 'right', sorter: (a, b) => a.bookings - b.bookings },
  { title: t('Completed'), dataIndex: 'completed', align: 'right' },
  { title: t('Bkm_Status_No_Show'), dataIndex: 'no_show', align: 'right' },
  { title: t('Bkm_Cancelled_Declined'), dataIndex: 'cancelled', align: 'right' },
  { title: t('Bkm_Seats'), dataIndex: 'seats', align: 'right' },
  { title: t('Bkm_Hours_Booked'), dataIndex: 'hours', align: 'right', sorter: (a, b) => a.hours - b.hours },
  { title: t('Bkm_Revenue'), dataIndex: 'revenue', align: 'right', customRender: money, sorter: (a, b) => a.revenue - b.revenue },
  { title: t('Bkm_Avg_Per_Booking'), dataIndex: 'avg_revenue', align: 'right', customRender: ({ value }) => (value === null ? '—' : moneyBase(value)), exportValue: r => r.avg_revenue },
]);
const staffCols = computed(() => [
  { title: t('Bkm_Staff_Member'), dataIndex: 'name', fixed: 'left', width: 180, customRender: ({ value }) => value || t('Bkm_Unassigned'), exportValue: r => r.name || t('Bkm_Unassigned') },
  { title: t('Bkm_Bookings'), dataIndex: 'bookings', align: 'right', sorter: (a, b) => a.bookings - b.bookings },
  { title: t('Completed'), dataIndex: 'completed', align: 'right' },
  { title: t('Bkm_Status_No_Show'), dataIndex: 'no_show', align: 'right' },
  { title: t('Cancelled'), dataIndex: 'cancelled', align: 'right' },
  { title: t('Bkm_Hours_Booked'), dataIndex: 'booked_hours', align: 'right' },
  { title: t('Bkm_Hours_Working'), dataIndex: 'working_hours', align: 'right', customRender: ({ value }) => (value === null ? '—' : value) },
  { title: t('Bkm_Utilisation'), dataIndex: 'utilisation', width: 150, customRender: ({ value }) => (value === null ? '—' : `${value}%`), exportValue: r => r.utilisation },
  { title: t('Bkm_Revenue'), dataIndex: 'revenue', align: 'right', customRender: money, sorter: (a, b) => a.revenue - b.revenue },
]);
const cellCols = computed(() => [
  { title: t('Bkm_Weekday'), dataIndex: 'weekday', customRender: ({ value }) => dayName(value), exportValue: r => dayName(r.weekday) },
  { title: t('Bkm_Hour'), dataIndex: 'hour', customRender: ({ value }) => hh(value), exportValue: r => hh(r.hour) },
  { title: t('Bkm_Bookings'), dataIndex: 'bookings', align: 'right', sorter: (a, b) => a.bookings - b.bookings },
]);
const webDayCols = computed(() => [
  { title: t('Date'), dataIndex: 'date' },
  { title: t('Bkm_Requests'), dataIndex: 'requests', align: 'right' },
  { title: t('Confirmed'), dataIndex: 'confirmed', align: 'right' },
  { title: t('Bkm_Status_Declined'), dataIndex: 'declined', align: 'right' },
  { title: t('Cancelled'), dataIndex: 'cancelled', align: 'right' },
  { title: t('Pending'), dataIndex: 'pending', align: 'right' },
]);
const webServiceCols = computed(() => [
  { title: t('Bkm_Service'), dataIndex: 'name', ellipsis: true },
  { title: t('Bkm_Requests'), dataIndex: 'requests', align: 'right' },
  { title: t('Confirmed'), dataIndex: 'confirmed', align: 'right' },
  { title: t('Bkm_Status_Declined'), dataIndex: 'declined', align: 'right' },
]);
const reasonCols = computed(() => [
  { title: t('Bkm_Reason'), dataIndex: 'reason' },
  { title: t('Bkm_Bookings'), dataIndex: 'bookings', align: 'right', width: 100 },
]);
const lostCols = computed(() => [
  { title: t('Date'), dataIndex: 'date', width: 150, customRender: ({ record }) => `${record.date} ${record.time || ''}`, exportValue: r => `${r.date} ${r.time || ''}` },
  { title: t('Reference'), dataIndex: 'ref' },
  { title: t('Customer'), dataIndex: 'customer', ellipsis: true },
  { title: t('Bkm_Service'), dataIndex: 'service', ellipsis: true },
  { title: t('Bkm_Staff_Member'), dataIndex: 'staff' },
  { title: t('Status'), dataIndex: 'status', customRender: ({ value }) => statusLabel(value), exportValue: r => statusLabel(r.status) },
  { title: t('Bkm_Cancelled_By'), dataIndex: 'by', customRender: ({ value }) => byLabel(value), exportValue: r => byLabel(r.by) },
  { title: t('Bkm_Reason'), dataIndex: 'reason', ellipsis: true },
  { title: t('Bkm_Value'), dataIndex: 'value', align: 'right', customRender: money },
]);
const customerCols = computed(() => [
  { title: t('Customer'), dataIndex: 'name', fixed: 'left', width: 200, ellipsis: true },
  { title: t('Phone'), dataIndex: 'phone' },
  { title: t('Bkm_Bookings'), dataIndex: 'bookings', align: 'right', sorter: (a, b) => a.bookings - b.bookings },
  { title: t('Completed'), dataIndex: 'completed', align: 'right' },
  { title: t('Bkm_Status_No_Show'), dataIndex: 'no_show', align: 'right', sorter: (a, b) => a.no_show - b.no_show },
  { title: t('Cancelled'), dataIndex: 'cancelled', align: 'right' },
  { title: t('Bkm_Revenue'), dataIndex: 'revenue', align: 'right', customRender: money, sorter: (a, b) => a.revenue - b.revenue },
  { title: t('Bkm_Last_Booking'), dataIndex: 'last' },
  { title: t('Bkm_Customer_Type'), dataIndex: 'new', customRender: ({ value }) => (value ? t('Bkm_New') : t('Bkm_Returning')), exportValue: r => (r.new ? t('Bkm_New') : t('Bkm_Returning')) },
]);
function byLabel(v) { return v === 'customer' ? t('Customer') : v === 'staff' ? t('Bkm_Staff') : ''; }

const serviceRows = computed(() => {
  const q = search.value.trim().toLowerCase();
  const rows = Array.isArray(data.value) ? data.value : [];
  return q ? rows.filter(r => (r.name || '').toLowerCase().includes(q) || (r.category || '').toLowerCase().includes(q)) : rows;
});
const customerRows = computed(() => {
  const q = search.value.trim().toLowerCase();
  const rows = (data.value && data.value.rows) || [];
  return q ? rows.filter(r => (r.name || '').toLowerCase().includes(q) || (r.phone || '').toLowerCase().includes(q)) : rows;
});

// Heatmap: hours that had bookings, five steps of one hue.
const heatHours = computed(() => {
  const d = data.value;
  if (!d || d.hour_min === null || d.hour_min === undefined) return [];
  const out = [];
  for (let h = d.hour_min; h <= d.hour_max; h++) out.push(h);
  return out;
});
const cellMap = computed(() => {
  const m = {};
  ((data.value && data.value.cells) || []).forEach(c => { m[`${c.weekday}-${c.hour}`] = c.bookings; });
  return m;
});
const maxCell = computed(() => Math.max(1, ...Object.values(cellMap.value)));
function cellCount(wd, h) { return cellMap.value[`${wd}-${h}`] || 0; }
function level(n) { return n ? Math.min(5, Math.max(1, Math.ceil((n / maxCell.value) * 5))) : 0; }
const cellRows = computed(() => [...((data.value && data.value.cells) || [])].sort((a, b) => b.bookings - a.bookings));
const maxWeekday = computed(() => Math.max(1, ...((data.value && data.value.weekdays) || []).map(w => w.bookings)));
const leadTotal = computed(() => ((data.value && data.value.lead) || []).reduce((a, b) => a + b.bookings, 0));

const auth = useAuthStore();
/** Booking details need the bookings permission (reports alone only shows figures). */
const canBookings = computed(() => auth.can('bookings'));
function openBooking(id) { if (!canBookings.value) return; viewId.value = id; viewOpen.value = true; }

// What "Export" writes for the open tab.
const exportSpec = computed(() => {
  const d = data.value;
  if (!d || dataType.value !== tab.value) return null;
  switch (tab.value) {
    case 'bookings': return { cols: dayCols.value, rows: d.rows || [] };
    case 'services': return { cols: serviceCols.value, rows: serviceRows.value };
    case 'staff': return { cols: staffCols.value, rows: d.rows || [] };
    case 'times': return { cols: cellCols.value, rows: cellRows.value };
    case 'website': return { cols: webDayCols.value, rows: d.days || [] };
    case 'lost': return { cols: lostCols.value, rows: d.rows || [] };
    case 'customers': return { cols: customerCols.value, rows: customerRows.value };
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
    await exportExcel(`bookings-${tab.value}-${range.value[0]}_${range.value[1]}`, cols, spec.rows);
  } catch (e) { message.error(t('Failed')); } finally { exporting.value = false; }
}

let loadSeq = 0;
async function load() {
  const seq = ++loadSeq;
  loading.value = true;
  const type = tab.value;
  data.value = null;
  dataType.value = '';
  search.value = '';
  try {
    const params = { from: range.value[0], to: range.value[1] };
    if (branch.value) params.warehouse_id = branch.value;
    const r = await http.get(`bookings/reports/${type}`, params);
    if (seq !== loadSeq) return; // a newer tab / range / branch was asked for meanwhile
    if (r.from && r.to && (r.from !== range.value[0] || r.to !== range.value[1])) range.value = [r.from, r.to];
    ready.value = r.ready !== false;
    data.value = r.ready !== false ? r.data : null;
    dataType.value = type;
    branches.value = r.warehouses || branches.value;
  } catch (e) {
    if (seq === loadSeq) message.error(e?.data?.message || t('Failed'));
  } finally {
    if (seq === loadSeq) loading.value = false;
  }
}
onMounted(load);
</script>

<style scoped>
.bkm-rep { display: flex; flex-direction: column; gap: 4px; }
.num { font-variant-numeric: tabular-nums; }
.muted { color: rgba(0, 0, 0, 0.55); }
.small { font-size: 12px; }
.tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 10px; margin-bottom: 14px; }
.tile { background: var(--ant-color-bg-container, #fff); border: 1px solid rgba(5, 5, 5, 0.06); border-radius: 12px; padding: 12px 14px; display: flex; flex-direction: column; gap: 4px; }
.tile span { font-size: 12px; color: rgba(0, 0, 0, 0.55); }
.tile strong { font-size: 20px; }
.tile em { font-style: normal; }
.sub { font-size: 15px; margin: 18px 0 8px; }
.card-head { display: flex; justify-content: space-between; align-items: center; gap: 8px; }
.two { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 18px; margin-bottom: 10px; }
.type-row { display: flex; flex-direction: column; gap: 4px; margin-bottom: 10px; }
.type-line { display: flex; justify-content: space-between; gap: 8px; font-size: 14px; }
.bar-track { height: 8px; border-radius: 4px; background: rgba(0, 0, 0, 0.06); overflow: hidden; }
.bar-fill { height: 8px; border-radius: 4px; background: #6d28d9; min-width: 2px; }
.heat-wrap { overflow-x: auto; padding-bottom: 6px; }
.heat { border-collapse: separate; border-spacing: 3px; font-size: 12px; }
.heat th { font-weight: 500; color: rgba(0, 0, 0, 0.6); padding: 0 4px; white-space: nowrap; }
.heat thead th { text-align: center; }
.heat tbody th { text-align: end; }
.heat td { padding: 0; }
.cell { display: inline-flex; align-items: center; justify-content: center; width: 32px; height: 28px; border-radius: 4px; font-size: 11px; font-variant-numeric: tabular-nums; }
.l0 { background: rgba(0, 0, 0, 0.04); }
.l1 { background: #ede9fe; color: #4c1d95; }
.l2 { background: #c4b5fd; color: #3b0764; }
.l3 { background: #a78bfa; color: #2e1065; }
.l4 { background: #7c3aed; color: #fff; }
.l5 { background: #5b21b6; color: #fff; }
.heat-legend { display: flex; align-items: center; gap: 4px; margin-top: 8px; }
.heat-legend .cell { width: 18px; height: 12px; }
:global(html.dark) .bkm-rep .muted, :global(html.dark) .bkm-rep .tile span, :global(html.dark) .bkm-rep .heat th { color: rgba(255, 255, 255, 0.6); }
:global(html.dark) .bkm-rep .tile { border-color: rgba(255, 255, 255, 0.08); }
:global(html.dark) .bkm-rep .bar-track { background: rgba(255, 255, 255, 0.1); }
:global(html.dark) .bkm-rep .bar-fill { background: #8b5cf6; }
:global(html.dark) .bkm-rep .l0 { background: rgba(255, 255, 255, 0.05); }
:global(html.dark) .bkm-rep .l1 { background: #2e1f5e; color: #ddd6fe; }
:global(html.dark) .bkm-rep .l2 { background: #4527a0; color: #ede9fe; }
:global(html.dark) .bkm-rep .l3 { background: #6d3fd6; color: #fff; }
:global(html.dark) .bkm-rep .l4 { background: #8b5cf6; color: #fff; }
:global(html.dark) .bkm-rep .l5 { background: #b9a3ff; color: #1e1048; }
</style>
