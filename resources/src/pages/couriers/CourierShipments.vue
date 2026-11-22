<template>
  <div class="page">
    <PageHeader :title="$t('Cr_Courier_Shipments')" :breadcrumb="[$t('Cr_Couriers'), $t('Cr_Courier_Shipments')]">
      <template #extra>
        <a-button :disabled="!selectedIds.length" @click="printMany(selectedIds)">
          <template #icon><PrinterOutlined /></template>
          {{ $t('Cr_Print_Labels') }}<span v-if="selectedIds.length"> ({{ selectedIds.length }})</span>
        </a-button>
      </template>
    </PageHeader>

    <a-row :gutter="[12, 12]" style="margin-bottom: 16px">
      <a-col v-for="k in kpiCards" :key="k.key" :xs="12" :md="8" :xl="4">
        <a-card size="small" hoverable class="kpi" :class="{ active: tab === k.tab }" @click="k.tab && setTab(k.tab)">
          <div class="kpi-label">{{ k.label }}</div>
          <div class="kpi-value" :style="{ color: k.color }">{{ k.value }}</div>
        </a-card>
      </a-col>
    </a-row>

    <a-card size="small" :body-style="{ padding: 0 }">
      <a-tabs v-model:active-key="tab" class="tabs" @change="setTab">
        <a-tab-pane key="all" :tab="$t('All')" />
        <a-tab-pane key="on_the_way" :tab="$t('Cr_On_The_Way')" />
        <a-tab-pane key="returns" :tab="$t('Cr_Returns_To_Review')" />
        <a-tab-pane key="cod" :tab="$t('Cr_COD_Outstanding')" />
        <a-tab-pane key="errors" :tab="$t('Cr_Sync_Errors')" />
      </a-tabs>
      <div class="toolbar">
        <a-input-search v-model:value="filters.search" :placeholder="$t('Cr_Search_Shipments')" allow-clear style="width: 260px" @search="reload" />
        <a-select v-model:value="filters.account_id" :options="accountOptions" :placeholder="$t('Cr_Courier_Account')" allow-clear style="width: 200px" @change="reload" />
        <a-select v-if="tab === 'all'" v-model:value="filters.courier_status" :options="statusOptions" :placeholder="$t('Status')" allow-clear style="width: 180px" @change="reload" />
        <a-range-picker v-model:value="dates" value-format="YYYY-MM-DD" @change="reload" />
      </div>

      <a-table
        :columns="columns" :data-source="rows" :loading="loading" size="middle" row-key="id"
        :row-selection="{ selectedRowKeys: selectedIds, onChange: keys => (selectedIds = keys) }"
        :pagination="{ current: page, pageSize: limit, total, showSizeChanger: true }"
        :scroll="{ x: 'max-content' }" @change="onTableChange"
      >
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'tracking'">
            <a v-if="record.tracking_link" :href="record.tracking_link" target="_blank" rel="noopener"><strong>{{ record.tracking_number }}</strong></a>
            <strong v-else>{{ record.tracking_number }}</strong>
            <div class="muted">{{ record.account_name }}</div>
          </template>
          <template v-else-if="column.key === 'sale'">
            <router-link :to="`/sales/${record.sale_id}`">{{ record.sale_ref }}</router-link>
            <div class="muted">
              {{ record.client_name }}
              <ReturnRiskBadge v-if="record.return_risk && ['high', 'medium'].includes(record.return_risk.level)" :risk="record.return_risk" :show-rate="false" class="list-risk" />
            </div>
          </template>
          <template v-else-if="column.key === 'city'">{{ record.destination_city }}</template>
          <template v-else-if="column.key === 'status'">
            <a-tag :color="courierStatus(record.courier_status)?.color">
              {{ courierStatus(record.courier_status) ? $t(courierStatus(record.courier_status).key) : record.courier_status }}
            </a-tag>
            <a-tooltip v-if="record.sync_error" :title="record.sync_error"><WarningOutlined style="color: #fa8c16" /></a-tooltip>
            <div v-if="record.courier_status_raw" class="muted">{{ record.courier_status_raw }}</div>
          </template>
          <template v-else-if="column.key === 'cod'">
            <template v-if="record.cod_amount > 0">
              {{ money(record.cod_amount) }}
              <div><a-tag :color="codColor(record.cod_status)" style="margin: 0">{{ $t('Cr_COD_' + (record.cod_status || 'none')) }}</a-tag></div>
            </template>
            <span v-else class="muted">{{ $t('Cr_Prepaid') }}</span>
          </template>
          <template v-else-if="column.key === 'return'">
            <a-tag v-if="record.return_status" :color="record.return_status === 'pending' ? 'warning' : 'default'">{{ $t('Cr_Return_' + record.return_status) }}</a-tag>
          </template>
          <template v-else-if="column.key === 'booked'">{{ dateTime(record.booked_at) }}</template>
          <template v-else-if="column.key === 'actions'">
            <CourierShipmentActions :shipment="record" @updated="onUpdated" @timeline="openTimeline" />
          </template>
        </template>
      </a-table>
    </a-card>

    <a-drawer v-model:open="drawer.open" :title="drawer.title" width="480">
      <a-spin :spinning="drawer.loading">
        <CourierTimeline :events="drawer.events" />
      </a-spin>
    </a-drawer>
  </div>
</template>

<script setup>
/**
 * Courier shipments — GET couriers/shipments?page&limit&search&account_id&
 * courier_status&cod_status&return_status&date_from&date_to&sync_errors
 * → {shipments, totalRows, kpis, accounts}. Tabs are preset filters; the
 * KPI cards jump to them. Labels print for the selected rows.
 */
import { ref, reactive, computed, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { PrinterOutlined, WarningOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';
import { useFormat } from '../../composables/useFormat';
import { COURIER_STATUSES, courierStatus, errorText } from './courierShared';
import { useShipmentLabels } from './useShipmentLabels';
import CourierShipmentActions from './CourierShipmentActions.vue';
import CourierTimeline from './CourierTimeline.vue';
import ReturnRiskBadge from './ReturnRiskBadge.vue';

const { t } = useI18n();
const route = useRoute();
const { money, dateTime } = useFormat();
const { printMany } = useShipmentLabels();

const loading = ref(false);
const rows = ref([]);
const total = ref(0);
const page = ref(1);
const limit = ref(20);
const kpis = ref({});
const accounts = ref([]);
const selectedIds = ref([]);
const tab = ref(['on_the_way', 'returns', 'cod', 'errors'].includes(route.query.tab) ? route.query.tab : 'all');
const filters = reactive({ search: '', account_id: undefined, courier_status: undefined });
const dates = ref(null);
const drawer = reactive({ open: false, title: '', events: [], loading: false });

const ON_THE_WAY = 'booked,picked_up,in_transit,out_for_delivery,failed_attempt,returning';

const accountOptions = computed(() => accounts.value.map(a => ({ value: a.id, label: a.name })));
const statusOptions = computed(() => COURIER_STATUSES.map(s => ({ value: s.value, label: t(s.key) })));
const columns = computed(() => [
  { title: t('Cr_Tracking_Number'), key: 'tracking' },
  { title: t('Cr_Sale'), key: 'sale' },
  { title: t('City'), key: 'city' },
  { title: t('Status'), key: 'status' },
  { title: t('Cr_COD'), key: 'cod', align: 'right' },
  ...(tab.value === 'returns' || tab.value === 'all' ? [{ title: t('Cr_Return'), key: 'return' }] : []),
  { title: t('Cr_Booked_On'), key: 'booked' },
  { title: t('Actions'), key: 'actions', width: 80, align: 'center' },
]);

const kpiCards = computed(() => {
  const k = kpis.value || {};
  const by = k.by_status || {};
  return [
    { key: 'way', tab: 'on_the_way', label: t('Cr_On_The_Way'), value: k.on_the_way ?? 0, color: '#1677ff' },
    { key: 'delivered', tab: null, label: t('Cr_Status_Delivered'), value: by.delivered ?? 0, color: '#52c41a' },
    { key: 'returned', tab: null, label: t('Cr_Status_Returned'), value: by.returned ?? 0, color: '#fa541c' },
    { key: 'returns', tab: 'returns', label: t('Cr_Returns_To_Review'), value: k.returns_pending ?? 0, color: '#fa8c16' },
    { key: 'cod', tab: 'cod', label: t('Cr_COD_Outstanding'), value: money(k.cod_pending_amount ?? 0), color: '#722ed1' },
    { key: 'errors', tab: 'errors', label: t('Cr_Sync_Errors'), value: k.sync_errors ?? 0, color: '#ff4d4f' },
  ];
});

function codColor(s) {
  return { pending: 'default', collected: 'processing', remitted: 'success' }[s] || 'default';
}

function tabParams() {
  switch (tab.value) {
    case 'on_the_way': return { courier_status_in: ON_THE_WAY };
    case 'returns': return { return_status: 'pending' };
    case 'cod': return { cod_outstanding: 1 };
    case 'errors': return { sync_errors: 1 };
    default: return {};
  }
}

async function fetch() {
  loading.value = true;
  try {
    const resp = await http.get('couriers/shipments', {
      page: page.value,
      limit: limit.value,
      search: filters.search || undefined,
      account_id: filters.account_id,
      courier_status: tab.value === 'all' ? filters.courier_status : undefined,
      date_from: dates.value?.[0],
      date_to: dates.value?.[1],
      ...tabParams(),
    });
    rows.value = resp.shipments || [];
    total.value = resp.totalRows || 0;
    kpis.value = resp.kpis || {};
    accounts.value = resp.accounts || [];
  } catch (e) {
    message.error(errorText(e, t('Failed')));
  } finally {
    loading.value = false;
  }
}

function reload() {
  page.value = 1;
  selectedIds.value = [];
  fetch();
}
function setTab(key) {
  tab.value = key;
  reload();
}
function onTableChange(p) {
  page.value = p.current;
  limit.value = p.pageSize;
  fetch();
}
function onUpdated(s) {
  const i = rows.value.findIndex(r => r.id === s.id);
  if (i >= 0) rows.value.splice(i, 1, s);
  fetch();
}
async function openTimeline(s) {
  Object.assign(drawer, { open: true, title: `${s.tracking_number} · ${s.sale_ref}`, events: [], loading: true });
  try {
    const resp = await http.get(`couriers/shipments/${s.id}`);
    drawer.events = resp.shipment.events || [];
  } catch (e) {
    message.error(errorText(e, t('Failed')));
  } finally {
    drawer.loading = false;
  }
}

onMounted(fetch);
</script>

<style scoped>
.kpi {
  cursor: pointer;
}
.list-risk {
  margin-left: 6px;
  font-size: 11px;
}
.kpi.active {
  border-color: var(--ant-color-primary, #1677ff);
}
.kpi-label {
  font-size: 12px;
  color: rgba(var(--ink-rgb), 0.55);
}
.kpi-value {
  font-size: 20px;
  font-weight: 600;
}
.tabs {
  padding: 0 16px;
}
.toolbar {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  padding: 0 16px 16px;
}
.muted {
  font-size: 12px;
  color: rgba(var(--ink-rgb), 0.45);
}
</style>
