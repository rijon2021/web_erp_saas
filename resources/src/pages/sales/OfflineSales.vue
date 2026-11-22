<template>
  <div class="page">
    <PageHeader :title="$t('Offline_Sales')" :breadcrumb="[$t('Sales'), $t('Offline_Sales')]">
      <template #actions>
        <a-button :loading="crud.loading.value" @click="crud.fetchRows()"><ReloadOutlined /> {{ $t('Ofs_Refresh') }}</a-button>
      </template>
    </PageHeader>

    <a-alert v-if="!ready" type="info" show-icon :message="$t('Ofs_Not_Ready')" style="margin-bottom: 16px" />
    <a-alert v-else-if="failedCount" type="warning" show-icon style="margin-bottom: 16px" :message="$t('Ofs_Failed_Alert', { n: failedCount })">
      <template #action>
        <a-button size="small" @click="setStatus('failed')">{{ $t('Ofs_Show_Failed') }}</a-button>
      </template>
    </a-alert>

    <!-- Per-status summary (across the current filters, not just this page) -->
    <a-row :gutter="[16, 16]" style="margin-bottom: 16px">
      <a-col v-for="card in statusCards" :key="card.key" :xs="12" :sm="12" :xl="6">
        <a-card :bordered="false" class="kpi-card" :class="{ 'kpi-card--active': filters.status === card.status }" hoverable @click="setStatus(card.status)">
          <div class="kpi-inner">
            <div class="kpi-icon" :style="{ background: card.tint, color: card.color }"><component :is="card.icon" /></div>
            <div class="kpi-text">
              <div class="kpi-label">{{ card.label }}</div>
              <div class="kpi-value">{{ card.value }}</div>
            </div>
          </div>
        </a-card>
      </a-col>
    </a-row>

    <a-card size="small" style="margin-bottom: 16px">
      <div class="toolbar">
        <a-input-search v-model:value="crud.search.value" :placeholder="$t('Ofs_Search')" allow-clear class="tb-search" @search="crud.reload" />
        <a-select v-model:value="filters.status" class="tb-small" allow-clear :placeholder="$t('Status')" :options="statusOptions" @change="crud.reload" />
        <a-select v-model:value="filters.warehouse_id" class="tb-item" allow-clear show-search option-filter-prop="label"
          :placeholder="$t('warehouse')" :options="warehouseOptions" @change="crud.reload" />
        <a-select v-model:value="filters.user_id" class="tb-item" allow-clear show-search option-filter-prop="label"
          :placeholder="$t('Ofs_Cashier')" :options="cashierOptions" @change="crud.reload" />
        <a-range-picker v-model:value="filters.range" class="tb-range" value-format="YYYY-MM-DD" @change="crud.reload" />
      </div>
      <div class="muted hint">{{ $t('Ofs_Help') }}</div>
    </a-card>

    <DataTable :crud="crud" :columns="columns" :searchable="false" row-key="id">
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'offline_created_at'">
          {{ dateTime(record.offline_created_at || record.created_at) }}
        </template>
        <template v-else-if="column.key === 'grand_total'">{{ money(record.grand_total) }}</template>
        <template v-else-if="column.key === 'status'">
          <a-tag :color="statusColor(record.status)">{{ statusLabel(record.status) }}</a-tag>
          <div v-if="record.attempts > 1" class="muted small">{{ $t('Ofs_Attempts_N', { n: record.attempts }) }}</div>
        </template>
        <template v-else-if="column.key === 'result'">
          <template v-if="record.status === 'synced'">
            <router-link v-if="record.sale_id" :to="`/sales/${record.sale_id}`">{{ record.sale_ref || ('#' + record.sale_id) }}</router-link>
            <span v-if="record.resolution === 'retried'" class="muted small"> · {{ $t('Ofs_Retried_From_Here') }}</span>
          </template>
          <div v-else class="msg" :class="{ 'msg--bad': record.status === 'failed' }">{{ record.error_message || '—' }}</div>
        </template>
        <template v-else-if="column.key === 'actions'">
          <a-space>
            <a-button type="link" size="small" @click="inspect(record)">{{ $t('Ofs_Details') }}</a-button>
            <template v-if="record.status === 'failed'">
              <a-button type="link" size="small" :loading="busyId === record.id && busyAction === 'retry'" :disabled="!!busyId" @click="retry(record)">{{ $t('Ofs_Retry') }}</a-button>
              <a-button type="link" size="small" danger :disabled="!!busyId" @click="openDiscard(record)">{{ $t('Ofs_Discard') }}</a-button>
            </template>
          </a-space>
        </template>
      </template>
    </DataTable>

    <!-- Details -->
    <a-drawer :open="drawerOpen" :title="$t('Ofs_Sale_Details')" placement="right" :width="drawerWidth" @close="drawerOpen = false">
      <a-spin :spinning="loadingEntry">
        <template v-if="entry">
          <a-alert v-if="entry.status === 'failed'" type="error" show-icon style="margin-bottom: 16px"
            :message="$t('Ofs_Rejected_Because')" :description="entry.error_message || '—'" />

          <a-descriptions bordered size="small" :column="1">
            <a-descriptions-item :label="$t('Status')">
              <a-tag :color="statusColor(entry.status)">{{ statusLabel(entry.status) }}</a-tag>
              <span v-if="entry.status_code" class="muted"> HTTP {{ entry.status_code }}</span>
              <span class="muted"> · {{ $t('Ofs_Attempts_N', { n: entry.attempts }) }}</span>
            </a-descriptions-item>
            <a-descriptions-item :label="$t('Ofs_Rung_Up')">{{ dateTime(entry.offline_created_at || entry.created_at) }}</a-descriptions-item>
            <a-descriptions-item :label="$t('Ofs_Last_Attempt')">{{ dateTime(entry.last_attempt_at) }}</a-descriptions-item>
            <a-descriptions-item :label="$t('Ofs_Cashier')">{{ entry.cashier_name || '—' }}</a-descriptions-item>
            <a-descriptions-item :label="$t('warehouse')">{{ entry.warehouse_name || '—' }}</a-descriptions-item>
            <a-descriptions-item :label="$t('Customer')">{{ entry.client_name || '—' }}</a-descriptions-item>
            <a-descriptions-item v-if="entry.sale_id" :label="$t('Reference')">
              <router-link :to="`/sales/${entry.sale_id}`">{{ entry.sale_ref || ('#' + entry.sale_id) }}</router-link>
            </a-descriptions-item>
            <a-descriptions-item v-if="entry.resolution" :label="$t('Ofs_Resolution')">
              {{ entry.resolution === 'retried' ? $t('Ofs_Retried_By') : $t('Ofs_Discarded_By') }}
              {{ entry.resolved_by_name || '—' }} · {{ dateTime(entry.resolved_at) }}
              <div v-if="entry.note" class="muted">{{ entry.note }}</div>
            </a-descriptions-item>
            <a-descriptions-item v-if="entry.notes" :label="$t('Ofs_Sale_Note')">{{ entry.notes }}</a-descriptions-item>
          </a-descriptions>

          <a-typography-title :level="5" class="block-title">{{ $t('Products') }}</a-typography-title>
          <a-table :data-source="entry.lines" :columns="lineColumns" :pagination="false" size="small" row-key="__i" :scroll="{ x: 480 }">
            <template #bodyCell="{ column, record }">
              <template v-if="column.key === 'name'">
                <div>{{ record.name }}</div>
                <div v-if="record.code" class="muted small">{{ record.code }}</div>
              </template>
              <template v-else-if="column.key === 'unit_price'">{{ money(record.unit_price) }}</template>
              <template v-else-if="column.key === 'subtotal'">{{ money(record.subtotal) }}</template>
              <template v-else-if="column.key === 'stock_now'">
                <span v-if="record.stock_now === null" class="muted">—</span>
                <span v-else :class="{ 'msg--bad': entry.status === 'failed' && record.stock_now < record.quantity }">{{ number(record.stock_now) }}</span>
              </template>
            </template>
          </a-table>

          <div class="totals">
            <div v-if="entry.discount"><span>{{ $t('Discount') }}</span><span>{{ money(entry.discount) }}</span></div>
            <div v-if="entry.tax"><span>{{ $t('Tax') }}</span><span>{{ money(entry.tax) }}</span></div>
            <div v-if="entry.shipping"><span>{{ $t('Shipping') }}</span><span>{{ money(entry.shipping) }}</span></div>
            <div class="totals-grand"><span>{{ $t('Total') }}</span><span>{{ money(entry.grand_total) }}</span></div>
          </div>

          <template v-if="entry.payments && entry.payments.length">
            <a-typography-title :level="5" class="block-title">{{ $t('Ofs_Payments') }}</a-typography-title>
            <div v-for="(p, i) in entry.payments" :key="i" class="pay-row"><span>{{ p.method }}</span><span>{{ money(p.amount) }}</span></div>
          </template>

          <p v-if="entry.status === 'failed'" class="muted small" style="margin-top: 16px">{{ $t('Ofs_Retry_Help') }}</p>
          <div v-if="entry.status === 'failed'" class="drawer-actions">
            <a-button danger :disabled="!!busyId" @click="openDiscard(entry)">{{ $t('Ofs_Discard') }}</a-button>
            <a-button type="primary" :loading="busyId === entry.id && busyAction === 'retry'" :disabled="!!busyId" @click="retry(entry)">{{ $t('Ofs_Retry') }}</a-button>
          </div>
        </template>
      </a-spin>
    </a-drawer>

    <!-- Discard -->
    <a-modal v-model:open="discardOpen" :title="$t('Ofs_Discard_Title')" :ok-text="$t('Ofs_Discard')" ok-type="danger"
      :cancel-text="$t('Cancel')" :confirm-loading="busyAction === 'discard'" @ok="confirmDiscard">
      <p>{{ $t('Ofs_Discard_Text') }}</p>
      <a-textarea v-model:value="discardNote" :rows="3" :maxlength="500" :placeholder="$t('Ofs_Discard_Note')" />
    </a-modal>
  </div>
</template>

<script setup>
/**
 * Sales > Offline Sales — every POS sale a till rang up offline and later
 * uploaded, for all tills (recorded server-side by OfflineSaleRecorder).
 *   GET  pos/offline_sales (status, warehouse_id, user_id, from, to, search)
 *        → {rows, totalRows, ready, status_counts, warehouses, cashiers}
 *   GET  pos/offline_sales/{id} → {sale: {..., lines[stock_now], payments}}
 *   POST pos/offline_sales/{id}/retry   (replayed as the original cashier)
 *   POST pos/offline_sales/{id}/discard {note}
 */
import { ref, reactive, computed, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import {
  ReloadOutlined, CloudUploadOutlined, CheckCircleOutlined, WarningOutlined, StopOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import http from '../../lib/http';

const { t } = useI18n();
const { dateTime, money, number } = useFormat();

const filters = reactive({ status: undefined, warehouse_id: undefined, user_id: undefined, range: null });
const ready = ref(true);
const statusCounts = ref({});
const warehouses = ref([]);
const cashiers = ref([]);

const crud = useCrudTable('pos/offline_sales', {
  rowsKey: 'rows',
  limit: 25,
  pageSizeOptions: ['10', '25', '50', '100', '200'], // the server pages at 200 max
  params: () => ({
    status: filters.status || '',
    warehouse_id: filters.warehouse_id || '',
    user_id: filters.user_id || '',
    from: filters.range?.[0] || '',
    to: filters.range?.[1] || '',
  }),
  select: p => {
    ready.value = p.ready !== false;
    statusCounts.value = p.status_counts || {};
    warehouses.value = p.warehouses || [];
    cashiers.value = p.cashiers || [];
    return { rows: p.rows || [], total: p.totalRows || 0 };
  },
});

const STATUSES = ['synced', 'failed', 'discarded'];
const statusLabel = s => t({ synced: 'Ofs_Status_Synced', failed: 'Ofs_Status_Failed', discarded: 'Ofs_Status_Discarded' }[s] || 'Ofs_Status_Failed');
const statusColor = s => ({ synced: 'success', failed: 'error', discarded: 'default' }[s] || 'default');
const statusOptions = computed(() => STATUSES.map(s => ({ value: s, label: statusLabel(s) })));
const warehouseOptions = computed(() => warehouses.value.map(w => ({ value: w.id, label: w.name })));
const cashierOptions = computed(() => cashiers.value.map(u => ({ value: u.id, label: u.name })));
const failedCount = computed(() => Number(statusCounts.value.failed) || 0);

const statusCards = computed(() => {
  const n = s => Number(statusCounts.value[s]) || 0;
  return [
    { key: 'all', status: undefined, label: t('Total'), value: n('synced') + n('failed') + n('discarded'), color: '#6d28d9', tint: 'rgba(109, 40, 217, 0.12)', icon: CloudUploadOutlined },
    { key: 'synced', status: 'synced', label: t('Ofs_Status_Synced'), value: n('synced'), color: '#16a34a', tint: 'rgba(22, 163, 74, 0.12)', icon: CheckCircleOutlined },
    { key: 'failed', status: 'failed', label: t('Ofs_Status_Failed'), value: n('failed'), color: '#dc2626', tint: 'rgba(220, 38, 38, 0.12)', icon: WarningOutlined },
    { key: 'discarded', status: 'discarded', label: t('Ofs_Status_Discarded'), value: n('discarded'), color: '#64748b', tint: 'rgba(100, 116, 139, 0.14)', icon: StopOutlined },
  ];
});

function setStatus(status) {
  filters.status = status;
  crud.reload();
}

const columns = computed(() => [
  { title: t('Ofs_Rung_Up'), key: 'offline_created_at', dataIndex: 'offline_created_at', sorter: true, width: 165 },
  { title: t('Ofs_Cashier'), key: 'cashier_name', dataIndex: 'cashier_name', sorter: true },
  { title: t('warehouse'), key: 'warehouse_name', dataIndex: 'warehouse_name', sorter: true },
  { title: t('Customer'), key: 'client_name', dataIndex: 'client_name', sorter: true },
  { title: t('Total'), key: 'grand_total', dataIndex: 'grand_total', sorter: true, align: 'right' },
  { title: t('Status'), key: 'status', dataIndex: 'status', sorter: true, width: 120 },
  { title: t('Ofs_Result'), key: 'result' },
  { title: '', key: 'actions', width: 200, align: 'right' },
]);

const lineColumns = computed(() => [
  { title: t('ProductName'), key: 'name' },
  { title: t('Quantity'), dataIndex: 'quantity', key: 'quantity', align: 'right', width: 70 },
  { title: t('Price'), key: 'unit_price', align: 'right' },
  { title: t('SubTotal'), key: 'subtotal', align: 'right' },
  { title: t('Ofs_Stock_Now'), key: 'stock_now', align: 'right', width: 90 },
]);

// ---------------- details drawer ----------------

const drawerOpen = ref(false);
const loadingEntry = ref(false);
const entry = ref(null);
const drawerWidth = computed(() => (window.innerWidth < 760 ? '100%' : 640));

async function loadEntry(id) {
  loadingEntry.value = true;
  try {
    const res = await http.get(`pos/offline_sales/${id}`);
    const sale = res.sale || null;
    if (sale) sale.lines = (sale.lines || []).map((l, i) => ({ ...l, __i: i }));
    entry.value = sale;
  } catch (e) {
    message.error(errorText(e));
  } finally {
    loadingEntry.value = false;
  }
}

function inspect(record) {
  entry.value = null;
  drawerOpen.value = true;
  loadEntry(record.id);
}

// ---------------- retry / discard ----------------

const busyId = ref(null);
const busyAction = ref(null);

function errorText(e) {
  return (e && e.data && (e.data.message || e.data.error)) || t('Failed');
}

async function retry(record) {
  busyId.value = record.id;
  busyAction.value = 'retry';
  try {
    const res = await http.post(`pos/offline_sales/${record.id}/retry`);
    message.success(res && res.message && res.message !== 'Synced.' ? res.message : t('Ofs_Retry_Success'));
  } catch (e) {
    message.error(t('Ofs_Retry_Failed') + ': ' + errorText(e), 6);
  } finally {
    busyId.value = null;
    busyAction.value = null;
    crud.fetchRows();
    if (drawerOpen.value && entry.value && entry.value.id === record.id) loadEntry(record.id);
  }
}

const discardOpen = ref(false);
const discardNote = ref('');
const discardTarget = ref(null);

function openDiscard(record) {
  discardTarget.value = record;
  discardNote.value = '';
  discardOpen.value = true;
}

async function confirmDiscard() {
  const record = discardTarget.value;
  if (!record) return;
  busyId.value = record.id;
  busyAction.value = 'discard';
  try {
    await http.post(`pos/offline_sales/${record.id}/discard`, { note: discardNote.value || null });
    message.success(t('Ofs_Discarded'));
    discardOpen.value = false;
  } catch (e) {
    message.error(errorText(e));
  } finally {
    busyId.value = null;
    busyAction.value = null;
    crud.fetchRows();
    if (drawerOpen.value && entry.value && entry.value.id === record.id) loadEntry(record.id);
  }
}

onMounted(() => crud.fetchRows());
</script>

<style scoped>
.muted {
  color: rgba(var(--ink-rgb), 0.45);
}
.small {
  font-size: 11.5px;
}
.hint {
  margin-top: 8px;
  font-size: 12px;
}
.toolbar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 10px;
}
.tb-search {
  flex: 1 1 200px;
  min-width: 170px;
}
.tb-item {
  width: 180px;
}
.tb-small {
  width: 140px;
}
.tb-range {
  width: 230px;
}
.msg {
  overflow: hidden;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  font-size: 12.5px;
}
.msg--bad {
  color: #dc2626;
}
.kpi-card {
  border: 1px solid var(--line);
  box-shadow: 0 1px 4px rgba(0, 0, 0, 0.04);
  height: 100%;
  cursor: pointer;
}
.kpi-card--active {
  border-color: #6d28d9;
}
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
.kpi-text {
  min-width: 0;
  flex: 1 1 auto;
}
.kpi-label {
  font-size: 12px;
  color: rgba(var(--ink-rgb), 0.45);
  line-height: 1.3;
  margin-bottom: 3px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.kpi-value {
  font-size: 18px;
  font-weight: 700;
  line-height: 1.2;
}
.block-title {
  margin: 20px 0 8px !important;
}
.totals {
  margin-top: 10px;
  margin-left: auto;
  max-width: 280px;
  font-size: 13px;
}
.totals > div,
.pay-row {
  display: flex;
  justify-content: space-between;
  padding: 3px 0;
}
.totals-grand {
  font-weight: 700;
  border-top: 1px solid var(--line);
  margin-top: 4px;
  padding-top: 6px !important;
}
.drawer-actions {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
  margin-top: 12px;
}
@media (max-width: 767px) {
  .tb-item,
  .tb-small,
  .tb-range {
    width: 100%;
  }
}
</style>
