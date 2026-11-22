<template>
  <ReportPage
    :title="$t('POS_Sales_Report')"
    :breadcrumb="[$t('Reports'), $t('POS_Sales_Report')]"
    :crud="crud"
    :columns="columns"
    row-key="id"
    inline-filters
    export-endpoint="report/pos_sales"
    :export-params="filterParams"
    export-rows-key="report"
  >
    <template #actions>
      <ViewCurrencySelect />
    </template>

    <template #filters>
      <DateRangePicker v-model:value="range" :allow-clear="false" @change="crud.reload()" />
      <a-select
        v-model:value="source" style="width: 150px" :options="sourceOptions" @change="crud.reload()"
      />
      <a-select
        v-model:value="sellerId" allow-clear show-search option-filter-prop="label" style="width: 180px"
        :placeholder="$t('Seller')" :options="sellerOptions" @change="crud.reload()"
      />
      <a-select
        v-model:value="methodIds" mode="multiple" allow-clear show-search option-filter-prop="label"
        :max-tag-count="2" style="min-width: 220px"
        :placeholder="$t('PosRep_Payment_Methods')" :options="methodOptions" @change="crud.reload()"
      />
      <a-select
        v-model:value="clientId" allow-clear show-search option-filter-prop="label" style="width: 180px"
        :placeholder="$t('Customer')" :options="clientOptions" @change="crud.reload()"
      />
      <a-select
        v-if="warehouseOptions.length > 1"
        v-model:value="warehouseId" allow-clear show-search option-filter-prop="label" style="width: 170px"
        :placeholder="$t('warehouse')" :options="warehouseOptions" @change="crud.reload()"
      />
    </template>

    <template #chart>
      <a-tabs v-model:activeKey="tab" style="margin-bottom: 8px" @change="onTabChange">
        <a-tab-pane key="seller" :tab="$t('PosRep_By_Seller')" />
        <a-tab-pane key="transfer" :tab="$t('PosRep_Transfer')" />
        <a-tab-pane key="cash" :tab="$t('PosRep_Cash')" />
      </a-tabs>

      <a-alert v-if="presetWarning" type="warning" show-icon :message="presetWarning" style="margin-bottom: 16px" />
      <a-alert
        v-else type="info" show-icon style="margin-bottom: 16px"
        :message="tab === 'seller' ? $t('PosRep_Help_Seller') : $t('PosRep_Help_Methods')"
      />

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
        <!-- Per-seller totals: click a row to narrow the list to that seller. -->
        <a-col :xs="24" :xl="15">
          <a-card size="small" class="chart-card" :title="$t('PosRep_Totals_By_Seller')">
            <template v-if="sellerId" #extra>
              <a-button size="small" type="link" @click="clearSeller">{{ $t('All') }}</a-button>
            </template>
            <a-table
              :columns="sellerColumns"
              :data-source="sellerSummary"
              row-key="seller_id"
              size="small"
              :pagination="{ pageSize: 8, hideOnSinglePage: true }"
              :custom-row="sellerRowProps"
              :row-class-name="r => (r.seller_id === sellerId ? 'seller-row-active' : 'seller-row')"
            >
              <template #bodyCell="{ column, record }">
                <template v-if="MONEY_KEYS.includes(column.key)">{{ money(record[column.key]) }}</template>
              </template>
              <template #summary>
                <a-table-summary-row v-if="sellerSummary.length > 1">
                  <a-table-summary-cell :index="0"><strong>{{ $t('Total') }}</strong></a-table-summary-cell>
                  <a-table-summary-cell
                    v-for="(c, i) in sellerColumns.slice(1)" :key="c.key" :index="i + 1" align="right"
                  >
                    <strong>{{ c.key === 'count' ? sellerTotals.count : money(sellerTotals[c.key]) }}</strong>
                  </a-table-summary-cell>
                </a-table-summary-row>
              </template>
            </a-table>
          </a-card>
        </a-col>
        <a-col :xs="24" :xl="9">
          <a-card size="small" class="chart-card" :title="$t('ModePaiement')">
            <apexchart
              v-if="donutSeries.length"
              type="donut"
              height="280"
              :options="donutOptions"
              :series="donutSeries"
            />
            <a-empty v-else :description="$t('NodataAvailable')" style="padding: 48px 0" />
          </a-card>
        </a-col>
      </a-row>
    </template>

    <template #bodyCell="{ column, record }">
      <template v-if="column.key === 'date'">{{ date(record.date) }}</template>
      <template v-else-if="column.key === 'Ref'">
        <router-link :to="{ name: 'sale-details', params: { id: record.id } }">{{ record.Ref }}</router-link>
      </template>
      <template v-else-if="column.key === 'source'">
        <a-tag :color="record.source === 'pos' ? 'blue' : 'default'">
          {{ record.source === 'pos' ? $t('POS') : $t('PosRep_Back_Office') }}
        </a-tag>
      </template>
      <template v-else-if="column.key === 'payments'">
        <a-space :size="4" wrap>
          <a-tag v-for="p in record.payments" :key="p.method" :color="isSelectedName(p.method) ? 'green' : 'default'">
            {{ p.method }} {{ money(p.amount) }}
          </a-tag>
        </a-space>
      </template>
      <template v-else-if="column.key === 'due'">
        <a-tag :color="Number(record.due) > 0 ? 'orange' : 'green'">{{ money(record.due) }}</a-tag>
      </template>
      <template v-else-if="MONEY_KEYS.includes(column.key)">{{ money(record[column.key]) }}</template>
    </template>
  </ReportPage>
</template>

<script setup>
/**
 * POS Sales report — one row per sale, with three tabs that are the same list
 * under different preset filters:
 *   By Seller : every payment method, plus a per-seller totals table (click a
 *               seller to list only their sales).
 *   Transfer  : methods whose name looks like a transfer (editable).
 *   Cash      : methods flagged "cash" in Settings > Payment Methods (editable).
 * A sale paid with several methods shows on each matching tab; the
 * "Paid by selected methods" column holds only that share.
 *
 * Payload (report/pos_sales): { report, totalRows, stats, seller_summary,
 *   method_breakdown, clients, sellers, payment_methods, warehouses }
 */
import { ref, computed, onMounted } from 'vue';
import dayjs from 'dayjs';
import { useI18n } from 'vue-i18n';
import {
  ShoppingCartOutlined, DollarOutlined, WalletOutlined, TeamOutlined,
} from '@ant-design/icons-vue';
import ReportPage from '../../components/ReportPage.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import { useUiStore } from '../../stores/ui';
import DateRangePicker from '../../components/DateRangePicker.vue';
import ViewCurrencySelect from '../../components/ViewCurrencySelect.vue';

const { t } = useI18n();
const { money, date } = useFormat();
const ui = useUiStore();

const MONEY_KEYS = ['GrandTotal', 'paid_amount', 'method_amount', 'total', 'paid', 'due', 'method_total'];

// Name patterns used to preselect the Transfer / Cash tabs. Cash prefers the
// "is cash" flag; the name is only a fallback for tenants that never set it.
const TRANSFER_RE = /transfer|virement|bank|wire|tranf|transferencia|überweisung|havale|تحويل|حوالة/i;
const CASH_RE = /cash|esp[eè]ce|efectivo|bargeld|nakit|نقد|كاش/i;

const range = ref([dayjs().subtract(29, 'day'), dayjs()]);
const tab = ref('seller');
const source = ref('pos');
const sellerId = ref(undefined);
const methodIds = ref([]);
const clientId = ref(undefined);
const warehouseId = ref(undefined);
const presetWarning = ref('');

const filterParams = () => ({
  from: range.value?.[0]?.format?.('YYYY-MM-DD') || '',
  to: range.value?.[1]?.format?.('YYYY-MM-DD') || '',
  source: source.value,
  seller_id: sellerId.value || '',
  payment_method_ids: (methodIds.value || []).join(','),
  client_id: clientId.value || '',
  warehouse_id: warehouseId.value || '',
});

const crud = useCrudTable('report/pos_sales', {
  rowsKey: 'report',
  sortField: 'date',
  sortType: 'desc',
  params: filterParams,
});

const payload = computed(() => crud.payload.value || {});
const methods = computed(() => payload.value.payment_methods || []);
const sellerSummary = computed(() => payload.value.seller_summary || []);
const hasMethodFilter = computed(() => (methodIds.value || []).length > 0);

const toOptions = (list, labelKey = 'name') => (list || []).map(x => ({ value: x.id, label: x[labelKey] }));
const clientOptions = computed(() => toOptions(payload.value.clients));
const sellerOptions = computed(() => toOptions(payload.value.sellers));
const methodOptions = computed(() => toOptions(methods.value));
const warehouseOptions = computed(() => toOptions(payload.value.warehouses));
const sourceOptions = computed(() => [
  { value: 'pos', label: t('POS') },
  { value: 'backoffice', label: t('PosRep_Back_Office') },
  { value: 'all', label: t('All') },
]);

const selectedNames = computed(() => new Set(
  methods.value.filter(m => methodIds.value.includes(m.id)).map(m => m.name),
));
const isSelectedName = name => hasMethodFilter.value && selectedNames.value.has(name);

function presetFor(key) {
  if (key === 'cash') {
    const flagged = methods.value.filter(m => m.is_cash);
    return (flagged.length ? flagged : methods.value.filter(m => CASH_RE.test(m.name))).map(m => m.id);
  }
  if (key === 'transfer') return methods.value.filter(m => TRANSFER_RE.test(m.name)).map(m => m.id);
  return [];
}

function onTabChange(key) {
  methodIds.value = presetFor(key);
  presetWarning.value = '';
  if (key === 'cash' && !methodIds.value.length) presetWarning.value = t('PosRep_No_Cash_Methods');
  if (key === 'transfer' && !methodIds.value.length) presetWarning.value = t('PosRep_No_Transfer_Methods');
  crud.reload();
}

function sellerRowProps(record) {
  return {
    onClick: () => {
      sellerId.value = sellerId.value === record.seller_id ? undefined : record.seller_id;
      crud.reload();
    },
  };
}
function clearSeller() {
  sellerId.value = undefined;
  crud.reload();
}

const sellerTotals = computed(() => sellerSummary.value.reduce((acc, r) => ({
  count: acc.count + (Number(r.count) || 0),
  total: acc.total + (Number(r.total) || 0),
  paid: acc.paid + (Number(r.paid) || 0),
  due: acc.due + (Number(r.due) || 0),
  method_total: acc.method_total + (Number(r.method_total) || 0),
}), { count: 0, total: 0, paid: 0, due: 0, method_total: 0 }));

const sellerColumns = computed(() => [
  { title: t('Seller'), dataIndex: 'seller_name', key: 'seller_name' },
  { title: t('PosRep_Sales_Count'), dataIndex: 'count', key: 'count', align: 'right' },
  { title: t('Total'), dataIndex: 'total', key: 'total', align: 'right' },
  { title: t('Paid'), dataIndex: 'paid', key: 'paid', align: 'right' },
  { title: t('Due'), dataIndex: 'due', key: 'due', align: 'right' },
  ...(hasMethodFilter.value
    ? [{ title: t('PosRep_Paid_By_Method'), dataIndex: 'method_total', key: 'method_total', align: 'right' }]
    : []),
]);

const kpiTiles = computed(() => {
  const s = payload.value.stats || {};
  const n = k => Number(s[k]) || 0;
  const tiles = [
    { label: t('PosRep_Sales_Count'), value: n('count'), icon: ShoppingCartOutlined, color: '#1677ff', tint: 'rgba(22, 119, 255, 0.12)' },
    { label: t('Total_Sales'), value: money(n('total')), icon: DollarOutlined, color: '#6d28d9', tint: 'rgba(109, 40, 217, 0.12)' },
  ];
  if (hasMethodFilter.value) {
    tiles.push({ label: t('PosRep_Paid_By_Method'), value: money(n('method_total')), icon: WalletOutlined, color: '#10b981', tint: 'rgba(16, 185, 129, 0.12)' });
  } else {
    tiles.push({ label: t('PosRep_Total_Paid'), value: money(n('paid')), icon: WalletOutlined, color: '#10b981', tint: 'rgba(16, 185, 129, 0.12)' });
  }
  tiles.push({ label: t('Sellers'), value: n('sellers'), icon: TeamOutlined, color: '#f59e0b', tint: 'rgba(245, 158, 11, 0.14)' });
  return tiles;
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

const columns = computed(() => [
  { title: t('date'), dataIndex: 'date', key: 'date', sorter: true, exportValue: r => date(r.date) },
  { title: t('Reference'), dataIndex: 'Ref', key: 'Ref', sorter: true },
  { title: t('Seller'), dataIndex: 'seller_name', key: 'seller_name', sorter: true },
  { title: t('Cashier'), dataIndex: 'cashier_name', key: 'cashier_name' },
  { title: t('Customer'), dataIndex: 'client_name', key: 'client_name', sorter: true },
  { title: t('warehouse'), dataIndex: 'warehouse_name', key: 'warehouse_name', sorter: true },
  ...(source.value === 'all'
    ? [{ title: t('Source'), dataIndex: 'source', key: 'source', exportValue: r => (r.source === 'pos' ? t('POS') : t('PosRep_Back_Office')) }]
    : []),
  { title: t('Total'), dataIndex: 'GrandTotal', key: 'GrandTotal', sorter: true, align: 'right', sum: 'money', exportValue: r => money(r.GrandTotal) },
  { title: t('Paid'), dataIndex: 'paid_amount', key: 'paid_amount', sorter: true, align: 'right', sum: 'money', exportValue: r => money(r.paid_amount) },
  { title: t('Due'), dataIndex: 'due', key: 'due', align: 'right', sum: 'money', exportValue: r => money(r.due) },
  ...(hasMethodFilter.value
    ? [{ title: t('PosRep_Paid_By_Method'), dataIndex: 'method_amount', key: 'method_amount', sorter: true, align: 'right', sum: 'money', exportValue: r => money(r.method_amount) }]
    : []),
  {
    title: t('PosRep_Payments'), dataIndex: 'payments', key: 'payments',
    exportValue: r => (r.payments || []).map(p => `${p.method} ${money(p.amount)}`).join(', '),
  },
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
:deep(.seller-row),
:deep(.seller-row-active) { cursor: pointer; }
:deep(.seller-row-active > td) { background: rgba(22, 119, 255, 0.08) !important; }

@media (max-width: 575px) {
  .kpi-value { font-size: 16px; }
}
</style>
