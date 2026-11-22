<template>
  <div>
    <a-row :gutter="[16, 16]" style="margin-bottom: 16px">
      <a-col v-for="card in summaryCards" :key="card.key" :xs="24" :sm="12" :lg="6">
        <a-card :bordered="false" class="kpi-card">
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
        <a-input-search v-model:value="crud.search.value" :placeholder="$t('Pd_Search')" allow-clear class="tb-search" @search="crud.reload" />
        <a-select v-model:value="filters.pos_terminal_id" class="tb-item" allow-clear show-search option-filter-prop="label"
          :placeholder="$t('Pd_Device')" :options="terminalOptions" @change="crud.reload" />
        <a-select v-model:value="filters.type" class="tb-item" allow-clear :placeholder="$t('Pd_Type')" :options="typeOptions" @change="crud.reload" />
        <a-select v-model:value="filters.status" class="tb-item" allow-clear :placeholder="$t('Status')" :options="statusOptions" @change="crud.reload" />
        <a-range-picker v-model:value="filters.range" class="tb-range" value-format="YYYY-MM-DD" @change="crud.reload" />
        <a-checkbox v-model:checked="filters.unlinked" @change="crud.reload">{{ $t('Pd_Only_Unlinked') }}</a-checkbox>
        <a-button :loading="crud.loading.value" @click="crud.fetchRows()"><ReloadOutlined /></a-button>
      </div>
      <div class="muted hint">{{ $t('Pd_Transactions_Help') }}</div>
    </a-card>

    <DataTable :crud="crud" :columns="columns" :searchable="false" row-key="id">
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'created_at'">{{ dateTime(record.created_at) }}</template>
        <template v-else-if="column.key === 'reference'">
          <div class="mono">{{ record.reference }}</div>
          <div v-if="record.provider_reference" class="muted small">RRN {{ record.provider_reference }}</div>
        </template>
        <template v-else-if="column.key === 'type'">
          <a-tag :color="record.type === 'sale' ? 'blue' : 'orange'">{{ typeLabel(record.type) }}</a-tag>
        </template>
        <template v-else-if="column.key === 'status'">
          <a-tooltip :title="record.message || ''">
            <a-tag :color="statusColor(record.status)">{{ statusLabel(record.status) }}</a-tag>
          </a-tooltip>
          <div v-if="isUnlinked(record)" class="warn small">{{ $t('Pd_Not_Linked') }}</div>
          <div v-else-if="Number(record.reversed) > 0" class="muted small">{{ $t('Pd_Reversed') }}: {{ amount(record, record.reversed) }}</div>
        </template>
        <template v-else-if="column.key === 'amount'">
          {{ amount(record, record.amount) }}
          <div v-if="record.installments > 1" class="muted small">{{ $t('Pd_Installments_N', { n: record.installments }) }}</div>
        </template>
        <template v-else-if="column.key === 'card'">
          <div v-if="record.card_mask" class="mono">{{ record.card_mask }}</div>
          <div class="muted small">{{ [record.card_brand, record.auth_code ? $t('Pd_Auth_Code') + ' ' + record.auth_code : null].filter(Boolean).join(' · ') || '—' }}</div>
        </template>
        <template v-else-if="column.key === 'sale_ref'">
          <router-link v-if="record.sale_id" :to="`/sales/${record.sale_id}`">{{ record.sale_ref || ('#' + record.sale_id) }}</router-link>
          <span v-else class="muted">—</span>
        </template>
        <template v-else-if="column.key === 'actions'">
          <a-space v-if="canManage && record.type === 'sale' && record.status === 'approved' && remaining(record) > 0">
            <a-button size="small" danger :loading="busyId === record.id" :disabled="Number(record.reversed) > 0" @click="confirmVoid(record)">{{ $t('Pd_Void') }}</a-button>
            <a-button size="small" :loading="busyId === record.id" @click="openRefund(record)">{{ $t('Pd_Refund') }}</a-button>
          </a-space>
          <a-tooltip v-else-if="canManage && canCheck(record)" :title="$t('Pd_Check_Status_Help')">
            <a-button size="small" :loading="busyId === record.id" @click="checkStatus(record)">{{ $t('Pd_Check_Status') }}</a-button>
          </a-tooltip>
        </template>
      </template>
    </DataTable>

    <a-modal v-model:open="refund.open" :title="$t('Pd_Refund')" :confirm-loading="refund.busy" :ok-text="$t('Pd_Refund')" :cancel-text="$t('Cancel')" @ok="doRefund">
      <p class="muted">{{ $t('Pd_Refund_Help') }}</p>
      <a-form layout="vertical">
        <a-form-item :label="$t('Amount')" :extra="$t('Pd_Refund_Max', { max: refund.record ? amount(refund.record, remaining(refund.record)) : '' })">
          <a-input-number v-model:value="refund.amount" :min="0.01" :max="refund.record ? remaining(refund.record) : undefined" :precision="decimals" style="width: 100%" />
        </a-form-item>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * Sales > POS Devices > Transactions.
 *   GET pos-terminals/transactions (pos_terminal_id, type, status, linked, from, to, search)
 *       → {rows, totalRows, ready, summary, terminals, can_manage}
 *   POST pos-terminals/transactions/{id}/void | /refund {amount}  (followed via lib/posTerminal,
 *       so bridge devices are driven from this computer)
 */
import { ref, reactive, computed, onMounted } from 'vue';
import { message, Modal } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { ReloadOutlined, CheckCircleOutlined, RollbackOutlined, CloseCircleOutlined, UnorderedListOutlined } from '@ant-design/icons-vue';
import DataTable from '../../components/DataTable.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import { voidTransaction, refundTransaction, recheckTransaction } from '../../lib/posTerminal';
import { errorText } from '../couriers/courierShared';

const { t } = useI18n();
const { dateTime, money, decimals: fmtDecimals } = useFormat();
const decimals = computed(() => Number(typeof fmtDecimals === 'function' ? fmtDecimals() : (fmtDecimals?.value ?? fmtDecimals)) || 2);

const filters = reactive({ pos_terminal_id: undefined, type: undefined, status: undefined, range: null, unlinked: false });
const summary = ref({ count: 0, approved_amount: 0, reversed_amount: 0, unsuccessful: 0 });
const terminals = ref([]);
const canManage = ref(false);
const busyId = ref(null);
const refund = reactive({ open: false, record: null, amount: null, busy: false });

const crud = useCrudTable('pos-terminals/transactions', {
  rowsKey: 'rows',
  limit: 25,
  pageSizeOptions: ['10', '25', '50', '100', '200'],
  params: () => ({
    pos_terminal_id: filters.pos_terminal_id || '',
    type: filters.type || '',
    status: filters.status || '',
    linked: filters.unlinked ? 'unlinked' : '',
    from: filters.range?.[0] || '',
    to: filters.range?.[1] || '',
  }),
  select: p => {
    summary.value = p.summary || summary.value;
    terminals.value = p.terminals || [];
    canManage.value = !!p.can_manage;
    return { rows: p.rows || [], total: p.totalRows || 0 };
  },
});

const STATUSES = ['pending', 'approved', 'declined', 'cancelled', 'failed', 'timeout'];
const statusLabel = s => t({
  pending: 'Pd_Status_Pending', approved: 'Pd_Status_Approved', declined: 'Pd_Status_Declined',
  cancelled: 'Pd_Status_Cancelled', failed: 'Pd_Status_Failed', timeout: 'Pd_Status_Timeout',
}[s] || 'Pd_Status_Pending');
const statusColor = s => ({ pending: 'processing', approved: 'success', declined: 'error', cancelled: 'default', failed: 'error', timeout: 'warning' }[s] || 'default');
const typeLabel = ty => t({ sale: 'Pd_Type_Sale', refund: 'Pd_Type_Refund', void: 'Pd_Type_Void' }[ty] || 'Pd_Type_Sale');

const terminalOptions = computed(() => terminals.value.map(x => ({ value: x.id, label: x.name })));
const typeOptions = computed(() => ['sale', 'refund', 'void'].map(v => ({ value: v, label: typeLabel(v) })));
const statusOptions = computed(() => STATUSES.map(v => ({ value: v, label: statusLabel(v) })));

const remaining = r => Math.max(0, Number(r.amount) - Number(r.reversed || 0));
// Still waiting, or ended without an answer on our side within the last day: ask the device again.
const canCheck = r => (r.status === 'pending' || (r.type === 'sale' && ['timeout', 'cancelled', 'failed'].includes(r.status)))
  && (Date.now() - new Date(String(r.created_at).replace(' ', 'T')).getTime()) < 24 * 3600 * 1000;
const isUnlinked = r => r.type === 'sale' && r.status === 'approved' && !r.sale_id && remaining(r) > 0;
const amount = (r, v) => `${Number(v || 0).toFixed(2)} ${r.currency || ''}`.trim();

const summaryCards = computed(() => [
  { key: 'count', label: t('Pd_Kpi_Transactions'), value: Number(summary.value.count) || 0, color: '#2563eb', tint: 'rgba(37, 99, 235, 0.12)', icon: UnorderedListOutlined },
  { key: 'approved', label: t('Pd_Kpi_Approved'), value: money(summary.value.approved_amount || 0), color: '#16a34a', tint: 'rgba(22, 163, 74, 0.12)', icon: CheckCircleOutlined },
  { key: 'reversed', label: t('Pd_Kpi_Reversed'), value: money(summary.value.reversed_amount || 0), color: '#d97706', tint: 'rgba(217, 119, 6, 0.12)', icon: RollbackOutlined },
  { key: 'unsuccessful', label: t('Pd_Kpi_Unsuccessful'), value: Number(summary.value.unsuccessful) || 0, color: '#dc2626', tint: 'rgba(220, 38, 38, 0.12)', icon: CloseCircleOutlined },
]);

const columns = computed(() => [
  { title: t('date'), key: 'created_at', dataIndex: 'created_at', sorter: true, width: 160 },
  { title: t('Reference'), key: 'reference', dataIndex: 'reference', sorter: true },
  { title: t('Pd_Device'), key: 'terminal_name', dataIndex: 'terminal_name', sorter: true },
  { title: t('Pd_Type'), key: 'type', dataIndex: 'type', sorter: true, width: 100 },
  { title: t('Status'), key: 'status', dataIndex: 'status', sorter: true, width: 140 },
  { title: t('Amount'), key: 'amount', dataIndex: 'amount', sorter: true, align: 'right', width: 130 },
  { title: t('Pd_Card'), key: 'card' },
  { title: t('Pd_Sale'), key: 'sale_ref' },
  { title: t('Pd_Cashier'), key: 'user_name', dataIndex: 'user_name' },
  { title: t('Actions'), key: 'actions', width: 160 },
]);

function followOpts(id) {
  return { onUpdate: () => { busyId.value = id; } };
}

function report(txn, okKey) {
  if (txn && txn.status === 'approved') message.success(t(okKey));
  else message.error((txn && txn.message) || statusLabel(txn && txn.status), 6);
}

function confirmVoid(record) {
  Modal.confirm({
    title: t('Pd_Void_Confirm'),
    content: t('Pd_Void_Confirm_Help', { amount: amount(record, record.amount) }),
    okText: t('Pd_Void'),
    okType: 'danger',
    cancelText: t('Cancel'),
    async onOk() {
      busyId.value = record.id;
      try {
        report(await voidTransaction(record.id, followOpts(record.id)), 'Pd_Void_Done');
      } catch (e) {
        message.error(errorText(e, t('Failed')));
      } finally {
        busyId.value = null;
        crud.fetchRows();
      }
    },
  });
}

function openRefund(record) {
  refund.record = record;
  refund.amount = Number(remaining(record).toFixed(2));
  refund.open = true;
}

async function doRefund() {
  const record = refund.record;
  if (!record || !(Number(refund.amount) > 0) || refund.busy) return;
  busyId.value = record.id;
  refund.busy = true;
  try {
    report(await refundTransaction(record.id, Number(refund.amount), followOpts(record.id)), 'Pd_Refund_Done');
    refund.open = false;
  } catch (e) {
    message.error(errorText(e, t('Failed')));
  } finally {
    busyId.value = null;
    refund.busy = false;
    crud.fetchRows();
  }
}

// "Check status": follows a pending row (drives bridge status checks from this
// computer) or asks again about one that ended without an answer.
async function checkStatus(record) {
  busyId.value = record.id;
  try {
    const txn = await recheckTransaction(record.id, { onUpdate: () => { busyId.value = record.id; }, deadlineMs: 180000 });
    if (txn && txn.status === 'approved') message.success(t('Pd_Status_Approved'));
    else message.info(statusLabel(txn && txn.status) + (txn && txn.message ? ': ' + txn.message : ''), 6);
  } catch (e) {
    message.error(e && e.code === 'deadline' ? t('Pd_Lost_Track') : errorText(e, t('Failed')));
  } finally {
    busyId.value = null;
    crud.fetchRows();
  }
}

onMounted(() => crud.fetchRows());
</script>

<style scoped>
.muted {
  color: rgba(var(--ink-rgb), 0.45);
}
.warn {
  color: #d97706;
  font-weight: 600;
}
.small {
  font-size: 11.5px;
}
.mono {
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
  font-size: 12px;
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
  width: 160px;
}
.tb-range {
  width: 230px;
}
.kpi-card {
  border: 1px solid var(--line);
  box-shadow: 0 1px 4px rgba(0, 0, 0, 0.04);
  height: 100%;
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
@media (max-width: 767px) {
  .tb-item,
  .tb-range {
    width: 100%;
  }
}
</style>
