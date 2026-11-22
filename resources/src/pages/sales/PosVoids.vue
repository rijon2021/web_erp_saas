<template>
  <div class="page">
    <PageHeader :title="$t('Pv_Voided_Items')" :breadcrumb="[$t('Sales'), $t('Pv_Voided_Items')]">
      <template #actions>
        <a-button :loading="crud.loading.value" @click="crud.fetchRows()"><ReloadOutlined /> {{ $t('Ofs_Refresh') }}</a-button>
      </template>
    </PageHeader>

    <a-alert v-if="!ready" type="info" show-icon :message="$t('Pv_Not_Ready')" style="margin-bottom: 16px" />

    <!-- Totals across the current filters (not just this page) -->
    <a-row :gutter="[16, 16]" style="margin-bottom: 16px">
      <a-col v-for="card in summaryCards" :key="card.key" :xs="24" :sm="8">
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
        <a-input-search v-model:value="crud.search.value" :placeholder="$t('Pv_Search')" allow-clear class="tb-search" @search="crud.reload" />
        <a-select v-model:value="filters.action" class="tb-item" allow-clear :placeholder="$t('Action')" :options="actionOptions" @change="crud.reload" />
        <a-select v-model:value="filters.warehouse_id" class="tb-item" allow-clear show-search option-filter-prop="label"
          :placeholder="$t('warehouse')" :options="warehouseOptions" @change="crud.reload" />
        <a-select v-model:value="filters.cashier_id" class="tb-item" allow-clear show-search option-filter-prop="label"
          :placeholder="$t('Ofs_Cashier')" :options="cashierOptions" @change="crud.reload" />
        <a-select v-model:value="filters.approved_by" class="tb-item" allow-clear show-search option-filter-prop="label"
          :placeholder="$t('Pv_Approved_By')" :options="approverOptions" @change="crud.reload" />
        <a-range-picker v-model:value="filters.range" class="tb-range" value-format="YYYY-MM-DD" @change="crud.reload" />
      </div>
      <div class="muted hint">{{ $t('Pv_Help') }}</div>
    </a-card>

    <DataTable :crud="crud" :columns="columns" :searchable="false" row-key="id">
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'created_at'">{{ dateTime(record.created_at) }}</template>
        <template v-else-if="column.key === 'product_name'">
          <div>{{ record.product_name }}</div>
          <div v-if="record.product_code" class="muted small">{{ record.product_code }}</div>
        </template>
        <template v-else-if="column.key === 'action'">
          <a-tag :color="actionColor(record.action)">{{ actionLabel(record.action) }}</a-tag>
        </template>
        <template v-else-if="column.key === 'quantity'">{{ number(record.quantity) }}</template>
        <template v-else-if="column.key === 'amount'">{{ money(record.amount) }}</template>
        <template v-else-if="column.key === 'approver_name'">
          {{ record.approver_name || '—' }}
          <div class="muted small">{{ record.approval_method === 'self' ? $t('Pv_Method_Self') : $t('Pv_Method_Pin') }}</div>
        </template>
      </template>
    </DataTable>
  </div>
</template>

<script setup>
/**
 * Sales > POS Voided Items — every line taken off a POS cart while POS
 * Settings > "Require manager approval to remove items" is on.
 *   GET pos/voids (action, warehouse_id, cashier_id, approved_by, from, to, search)
 *       → {rows, totalRows, ready, summary{lines, quantity, amount}, warehouses, cashiers, approvers}
 */
import { ref, reactive, computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { ReloadOutlined, DeleteOutlined, NumberOutlined, DollarOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';

const { t } = useI18n();
const { dateTime, money, number } = useFormat();

const filters = reactive({ action: undefined, warehouse_id: undefined, cashier_id: undefined, approved_by: undefined, range: null });
const ready = ref(true);
const summary = ref({ lines: 0, quantity: 0, amount: 0 });
const warehouses = ref([]);
const cashiers = ref([]);
const approvers = ref([]);

const crud = useCrudTable('pos/voids', {
  rowsKey: 'rows',
  limit: 25,
  pageSizeOptions: ['10', '25', '50', '100', '200'], // the server pages at 200 max
  params: () => ({
    action: filters.action || '',
    warehouse_id: filters.warehouse_id || '',
    cashier_id: filters.cashier_id || '',
    approved_by: filters.approved_by || '',
    from: filters.range?.[0] || '',
    to: filters.range?.[1] || '',
  }),
  select: p => {
    ready.value = p.ready !== false;
    summary.value = p.summary || { lines: 0, quantity: 0, amount: 0 };
    warehouses.value = p.warehouses || [];
    cashiers.value = p.cashiers || [];
    approvers.value = p.approvers || [];
    return { rows: p.rows || [], total: p.totalRows || 0 };
  },
});

const ACTIONS = ['remove_line', 'decrease_qty', 'clear_cart'];
const actionLabel = a => t({ remove_line: 'Pv_Action_Remove_Line', decrease_qty: 'Pv_Action_Decrease_Qty', clear_cart: 'Pv_Action_Clear_Cart' }[a] || 'Pv_Action_Remove_Line');
const actionColor = a => ({ remove_line: 'red', decrease_qty: 'orange', clear_cart: 'volcano' }[a] || 'default');
const actionOptions = computed(() => ACTIONS.map(a => ({ value: a, label: actionLabel(a) })));
const warehouseOptions = computed(() => warehouses.value.map(w => ({ value: w.id, label: w.name })));
const cashierOptions = computed(() => cashiers.value.map(u => ({ value: u.id, label: u.name })));
const approverOptions = computed(() => approvers.value.map(u => ({ value: u.id, label: u.name })));

const summaryCards = computed(() => [
  { key: 'lines', label: t('Pv_Lines_Removed'), value: Number(summary.value.lines) || 0, color: '#dc2626', tint: 'rgba(220, 38, 38, 0.12)', icon: DeleteOutlined },
  { key: 'qty', label: t('Pv_Quantity_Removed'), value: number(summary.value.quantity || 0), color: '#d97706', tint: 'rgba(217, 119, 6, 0.12)', icon: NumberOutlined },
  { key: 'amount', label: t('Pv_Value_Removed'), value: money(summary.value.amount || 0), color: '#6d28d9', tint: 'rgba(109, 40, 217, 0.12)', icon: DollarOutlined },
]);

const columns = computed(() => [
  { title: t('date'), key: 'created_at', dataIndex: 'created_at', sorter: true, width: 165 },
  { title: t('ProductName'), key: 'product_name', dataIndex: 'product_name', sorter: true },
  { title: t('Action'), key: 'action', dataIndex: 'action', sorter: true, width: 150 },
  { title: t('Quantity'), key: 'quantity', dataIndex: 'quantity', sorter: true, align: 'right', width: 90 },
  { title: t('Pv_Value'), key: 'amount', dataIndex: 'amount', sorter: true, align: 'right' },
  { title: t('Ofs_Cashier'), key: 'cashier_name', dataIndex: 'cashier_name', sorter: true },
  { title: t('Pv_Approved_By'), key: 'approver_name', dataIndex: 'approver_name', sorter: true },
  { title: t('warehouse'), key: 'warehouse_name', dataIndex: 'warehouse_name', sorter: true },
]);

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
  width: 170px;
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
