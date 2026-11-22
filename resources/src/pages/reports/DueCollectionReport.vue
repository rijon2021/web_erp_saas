<template>
  <ReportPage
    :title="$t('Due_Collection_Report')"
    :breadcrumb="[$t('Reports'), $t('Due_Collection_Report')]"
    :crud="crud"
    :columns="columns"
    row-key="id"
    export-endpoint="report/due_collection"
    :export-params="filterParams"
    export-rows-key="report"
  >
    <template #actions>
      <ViewCurrencySelect />
    </template>

    <template #filters>
      <DateRangePicker v-model:value="range" :allow-clear="false" @change="crud.reload()" />
      <a-select
        v-model:value="clientId" allow-clear show-search option-filter-prop="label" style="width: 200px"
        :placeholder="$t('Customer')" :options="clientOptions" @change="crud.reload()"
      />
      <a-select
        v-model:value="methodId" allow-clear show-search option-filter-prop="label" style="width: 180px"
        :placeholder="$t('ModePaiement')" :options="methodOptions" @change="crud.reload()"
      />
      <a-select
        v-model:value="collectorId" allow-clear show-search option-filter-prop="label" style="width: 180px"
        :placeholder="$t('Collected_By')" :options="collectorOptions" @change="crud.reload()"
      />
      <a-select
        v-if="warehouseOptions.length > 1"
        v-model:value="warehouseId" allow-clear show-search option-filter-prop="label" style="width: 180px"
        :placeholder="$t('warehouse')" :options="warehouseOptions" @change="crud.reload()"
      />
    </template>

    <template #chart>
      <a-alert type="info" show-icon :message="$t('Due_Collection_Help')" style="margin-bottom: 16px" />

      <!-- KPI tiles — totals over the whole filtered set (all pages). -->
      <a-row :gutter="[16, 16]" style="margin-bottom: 16px">
        <a-col v-for="k in kpiTiles" :key="k.label" :xs="12" :sm="12" :md="6">
          <a-card size="small" class="kpi-card">
            <div class="kpi-inner">
              <div class="kpi-icon" :style="{ background: k.tint, color: k.color }">
                <component :is="k.icon" />
              </div>
              <div class="kpi-text">
                <a-typography-text type="secondary" class="kpi-label">{{ k.label }}</a-typography-text>
                <div class="kpi-value">
                  <a-spin v-if="crud.loading.value" size="small" />
                  <template v-else>{{ k.value }}</template>
                </div>
              </div>
            </div>
          </a-card>
        </a-col>
      </a-row>

      <a-row :gutter="[16, 16]" style="margin-bottom: 16px">
        <a-col :xs="24" :xl="9">
          <a-card size="small" class="chart-card" :title="$t('ModePaiement')">
            <apexchart
              v-if="donutSeries.length"
              type="donut"
              height="300"
              :options="donutOptions"
              :series="donutSeries"
            />
            <a-empty v-else :description="$t('NodataAvailable')" style="padding: 48px 0" />
          </a-card>
        </a-col>
        <a-col :xs="24" :xl="15">
          <ReportChart
            :data="timeseries"
            :fields="[{ key: 'amount', label: t('Amount') }]"
            :title="$t('Collections_Over_Time')"
            type="bar"
            x-key="d"
            :format="money"
            :height="300"
          />
        </a-col>
      </a-row>

      <!-- Date-wise totals: one row per day with collections in the range. -->
      <a-collapse v-model:activeKey="dailyOpen" ghost style="margin-bottom: 8px">
        <a-collapse-panel key="daily" :header="`${$t('Totals_By_Day')} (${daily.length})`">
          <a-table
            :columns="dailyColumns"
            :data-source="daily"
            row-key="d"
            size="small"
            :pagination="{ pageSize: 10, hideOnSinglePage: true }"
          >
            <template #bodyCell="{ column, record }">
              <template v-if="column.key === 'd'">{{ date(record.d) }}</template>
              <template v-else-if="column.key === 'amount'">{{ money(record.amount) }}</template>
            </template>
            <template #summary>
              <a-table-summary-row v-if="daily.length">
                <a-table-summary-cell :index="0"><strong>{{ $t('Total') }}</strong></a-table-summary-cell>
                <a-table-summary-cell :index="1" align="right"><strong>{{ dailyTotals.count }}</strong></a-table-summary-cell>
                <a-table-summary-cell :index="2" align="right"><strong>{{ dailyTotals.customers }}</strong></a-table-summary-cell>
                <a-table-summary-cell :index="3" align="right"><strong>{{ money(dailyTotals.amount) }}</strong></a-table-summary-cell>
              </a-table-summary-row>
            </template>
          </a-table>
        </a-collapse-panel>
      </a-collapse>
    </template>

    <template #bodyCell="{ column, record }">
      <template v-if="column.key === 'date'">{{ date(record.date) }}</template>
      <template v-else-if="column.key === 'sale_date'">{{ date(record.sale_date) }}</template>
      <template v-else-if="column.key === 'Ref_Sale'">
        <router-link :to="{ name: 'sale-details', params: { id: record.sale_id } }">{{ record.Ref_Sale }}</router-link>
      </template>
      <template v-else-if="column.key === 'due_after'">
        <a-tag :color="Number(record.due_after) > 0 ? 'orange' : 'green'">{{ money(record.due_after) }}</a-tag>
      </template>
      <template v-else-if="MONEY_KEYS.includes(column.key)">{{ money(record[column.key]) }}</template>
    </template>
  </ReportPage>
</template>

<script setup>
/**
 * Due Collection report — payments received against outstanding sales, by
 * payment date. The API (report/due_collection) only returns payments recorded
 * after their sale was saved, so this is "due collected", not "cash in"
 * (Reports > Payments > Sales covers the latter).
 *
 * Payload: { report, totalRows, stats, timeseries, daily, method_breakdown,
 *            clients, payment_methods, collectors, warehouses }
 */
import { ref, computed, onMounted } from 'vue';
import dayjs from 'dayjs';
import { useI18n } from 'vue-i18n';
import {
  WalletOutlined, DollarOutlined, BarChartOutlined, CalendarOutlined,
} from '@ant-design/icons-vue';
import ReportPage from '../../components/ReportPage.vue';
import ReportChart from '../../components/ReportChart.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import { useUiStore } from '../../stores/ui';
import DateRangePicker from '../../components/DateRangePicker.vue';
import ViewCurrencySelect from '../../components/ViewCurrencySelect.vue';

const { t } = useI18n();
const { money, date } = useFormat();
const ui = useUiStore();

const MONEY_KEYS = ['grand_total', 'due_before', 'montant', 'due_after'];

// Last 30 days by default (the picker's "Today" preset narrows to today).
const range = ref([dayjs().subtract(29, 'day'), dayjs()]);
const clientId = ref(undefined);
const methodId = ref(undefined);
const collectorId = ref(undefined);
const warehouseId = ref(undefined);
const dailyOpen = ref([]);

const filterParams = () => ({
  from: range.value?.[0]?.format?.('YYYY-MM-DD') || '',
  to: range.value?.[1]?.format?.('YYYY-MM-DD') || '',
  client_id: clientId.value || '',
  payment_method_id: methodId.value || '',
  user_id: collectorId.value || '',
  warehouse_id: warehouseId.value || '',
});

const crud = useCrudTable('report/due_collection', {
  rowsKey: 'report',
  sortField: 'date',
  sortType: 'desc',
  params: filterParams,
});

const payload = computed(() => crud.payload.value || {});
const timeseries = computed(() => payload.value.timeseries || []);
const daily = computed(() => payload.value.daily || []);
const dailyTotals = computed(() => daily.value.reduce((acc, r) => ({
  count: acc.count + (Number(r.count) || 0),
  customers: acc.customers + (Number(r.customers) || 0),
  amount: acc.amount + (Number(r.amount) || 0),
}), { count: 0, customers: 0, amount: 0 }));

const toOptions = (list, labelKey = 'name') => (list || []).map(x => ({ value: x.id, label: x[labelKey] }));
const clientOptions = computed(() => toOptions(payload.value.clients));
const methodOptions = computed(() => toOptions(payload.value.payment_methods));
const collectorOptions = computed(() => toOptions(payload.value.collectors));
const warehouseOptions = computed(() => toOptions(payload.value.warehouses));

const kpiTiles = computed(() => {
  const s = payload.value.stats || {};
  const n = k => Number(s[k]) || 0;
  return [
    { label: t('Collected_Today'), value: money(n('today')), icon: CalendarOutlined, color: '#10b981', tint: 'rgba(16, 185, 129, 0.12)' },
    { label: t('Total_Collected'), value: money(n('total')), icon: DollarOutlined, color: '#6d28d9', tint: 'rgba(109, 40, 217, 0.12)' },
    { label: t('Collections'), value: n('count'), icon: WalletOutlined, color: '#1677ff', tint: 'rgba(22, 119, 255, 0.12)' },
    { label: t('Avg_Collection'), value: money(n('average')), icon: BarChartOutlined, color: '#f59e0b', tint: 'rgba(245, 158, 11, 0.14)' },
  ];
});

/* Payment-method mix donut — same palette and tooltip as the Payments reports. */
const DONUT_COLORS = ['#6366f1', '#22d3ee', '#f59e0b', '#ec4899', '#10b981', '#8b5cf6', '#0ea5e9', '#94a3b8'];
const donutRows = computed(() => (payload.value.method_breakdown || []).filter(r => Number(r.amount) > 0));
const donutSeries = computed(() => donutRows.value.map(r => Number(r.amount) || 0));
const donutOptions = computed(() => ({
  chart: { type: 'donut', background: 'transparent', fontFamily: 'inherit' },
  labels: donutRows.value.map(r => r.name),
  colors: DONUT_COLORS,
  legend: { position: 'bottom', labels: { colors: ui.dark ? '#d9d9d9' : '#595959' } },
  dataLabels: { enabled: false },
  stroke: { colors: [ui.dark ? '#1f1f1f' : '#ffffff'], width: 3 },
  plotOptions: { pie: { donut: { size: '70%' } } },
  tooltip: {
    theme: ui.dark ? 'dark' : 'light',
    y: {
      formatter: (v, { seriesIndex }) => {
        const row = donutRows.value[seriesIndex];
        return `${money(v)} (${row ? row.count : 0})`;
      },
    },
  },
}));

const dailyColumns = computed(() => [
  { title: t('date'), dataIndex: 'd', key: 'd' },
  { title: t('Collections'), dataIndex: 'count', key: 'count', align: 'right' },
  { title: t('Customers_Paid'), dataIndex: 'customers', key: 'customers', align: 'right' },
  { title: t('Amount'), dataIndex: 'amount', key: 'amount', align: 'right' },
]);

const columns = computed(() => [
  { title: t('date'), dataIndex: 'date', key: 'date', sorter: true, exportValue: r => date(r.date) },
  { title: t('Reference'), dataIndex: 'Ref', key: 'Ref', sorter: true },
  { title: t('Customer'), dataIndex: 'client_name', key: 'client_name', sorter: true },
  { title: t('Sale'), dataIndex: 'Ref_Sale', key: 'Ref_Sale', sorter: true },
  { title: t('Sale_Date'), dataIndex: 'sale_date', key: 'sale_date', sorter: true, exportValue: r => date(r.sale_date) },
  { title: t('Sale_Total'), dataIndex: 'grand_total', key: 'grand_total', sorter: true, align: 'right', exportValue: r => money(r.grand_total) },
  { title: t('Due_Before'), dataIndex: 'due_before', key: 'due_before', align: 'right', exportValue: r => money(r.due_before) },
  { title: t('Collected'), dataIndex: 'montant', key: 'montant', sorter: true, align: 'right', sum: 'money', exportValue: r => money(r.montant) },
  { title: t('Due_After'), dataIndex: 'due_after', key: 'due_after', align: 'right', exportValue: r => money(r.due_after) },
  { title: t('ModePaiement'), dataIndex: 'payment_method', key: 'payment_method' },
  { title: t('Account'), dataIndex: 'account_name', key: 'account_name' },
  { title: t('warehouse'), dataIndex: 'warehouse_name', key: 'warehouse_name' },
  { title: t('Collected_By'), dataIndex: 'user_name', key: 'user_name', sorter: true },
]);

onMounted(crud.fetchRows);
</script>

<style scoped>
/* KPI tiles — shared design with the other report pages. */
.kpi-card,
.chart-card { border-radius: 10px; }
.kpi-inner {
  display: flex;
  align-items: center;
  gap: 12px;
}
.kpi-icon {
  width: 44px;
  height: 44px;
  border-radius: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 20px;
  flex: 0 0 auto;
}
.kpi-text { min-width: 0; flex: 1 1 auto; }
.kpi-label {
  display: block;
  font-size: 12px;
  margin-bottom: 2px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.kpi-value {
  font-size: 20px;
  font-weight: 700;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

@media (max-width: 575px) {
  .kpi-value { font-size: 16px; }
}
</style>
