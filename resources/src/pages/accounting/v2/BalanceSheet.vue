<template>
  <div class="page">
    <PageHeader :title="$t('Balance_Sheet_Title')" :breadcrumb="[$t('Accounting'), $t('Balance_Sheet_Title')]" />

    <a-row :gutter="[16, 16]" style="margin-bottom: 16px">
      <a-col :xs="24" :md="8">
        <a-card size="small"><a-statistic :title="$t('Assets')" :value="number(summary.assets)" :value-style="{ color: '#3f8600' }" /></a-card>
      </a-col>
      <a-col :xs="24" :md="8">
        <a-card size="small"><a-statistic :title="$t('Liabilities')" :value="number(summary.liabilities)" :value-style="{ color: '#cf1322' }" /></a-card>
      </a-col>
      <a-col :xs="24" :md="8">
        <a-card size="small"><a-statistic :title="$t('Equity')" :value="number(summary.equity)" /></a-card>
      </a-col>
    </a-row>

    <a-card size="small" style="margin-bottom: 16px">
      <a-row :gutter="[12, 12]" align="bottom">
        <a-col :xs="12" :md="8">
          <div class="filter-label">{{ $t('As_Of') }}</div>
          <a-input v-model:value="filters.to" type="date" />
        </a-col>
        <a-col :xs="12" :md="8">
          <div class="filter-label">{{ $t('Type') }}</div>
          <a-select v-model:value="filters.type" style="width: 100%">
            <a-select-option value="">{{ $t('All') }}</a-select-option>
            <a-select-option value="asset">{{ $t('Asset') }}</a-select-option>
            <a-select-option value="liability">{{ $t('Liability') }}</a-select-option>
            <a-select-option value="equity">{{ $t('Equity') }}</a-select-option>
          </a-select>
        </a-col>
        <a-col :xs="12" :md="8">
          <a-button type="primary" @click="crud.reload()">
            <template #icon><ReloadOutlined /></template>
            {{ $t('Apply') }}
          </a-button>
        </a-col>
      </a-row>
    </a-card>

    <DataTable :crud="crud" :columns="columns" row-key="coa_id">
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'type'">
          <span style="text-transform: capitalize">{{ typeLabel(record.type) }}</span>
        </template>
        <template v-else-if="column.key === 'amount'">{{ number(record.amount) }}</template>
      </template>
    </DataTable>

    <a-card size="small" style="margin-top: 16px; margin-bottom: 16px">
      <div class="totals">
        <div><span>{{ $t('Assets') }}</span><strong style="color: #3f8600">{{ number(summary.assets) }}</strong></div>
        <div><span>{{ $t('Liabilities') }}</span><strong style="color: #cf1322">{{ number(summary.liabilities) }}</strong></div>
        <div><span>{{ $t('Equity') }}</span><strong>{{ number(summary.equity) }}</strong></div>
      </div>
    </a-card>

    <a-alert
      :type="Math.abs(summary.balance) < 0.01 ? 'success' : 'warning'"
      show-icon
      :message="$t('Balance_Check')"
      :description="number(summary.balance)"
    />

    <a-alert
      v-if="inventory && Math.abs(inventory.gap) >= 0.01"
      type="warning"
      show-icon
      style="margin-top: 16px"
      :message="$t('Inventory_Out_Of_Sync')"
    >
      <template #description>
        <div style="margin-bottom: 8px">{{ $t('Inventory_Out_Of_Sync_Hint') }}</div>
        <div class="inv-figures">
          <div><span>{{ $t('Inventory_Stock_Value') }}</span><strong>{{ number(inventory.stock_value) }}</strong></div>
          <div><span>{{ $t('Inventory_Book_Value') }}</span><strong>{{ number(inventory.book_value) }}</strong></div>
          <div><span>{{ $t('Inventory_Difference') }}</span><strong>{{ number(inventory.gap) }}</strong></div>
        </div>
        <a-button v-if="inventory.can_true_up" type="primary" size="small" style="margin-top: 10px" :loading="truingUp" @click="confirmTrueUp">
          {{ $t('Inventory_True_Up') }}
        </a-button>
      </template>
    </a-alert>
  </div>
</template>

<script setup>
/**
 * Balance sheet (v2) — GET accounting/v2/reports/balance-sheet (server-side +
 * as-of/type filters) → {data, totalRows, assets, liabilities, equity,
 * balance}. Same shape as the profit & loss report: the statement totals ride
 * in the row response and stay as-of-wide while the table below lists the
 * per-account detail. Balance check green when |balance| < 0.01 (legacy
 * threshold). For a sheet as of today the response also carries
 * `inventory` {stock_value, book_value, gap, can_true_up}; a gap offers
 * POST accounting/v2/reports/inventory-true-up.
 */
import { ref, computed, onMounted } from 'vue';
import { message, Modal } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { ReloadOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../../components/PageHeader.vue';
import DataTable from '../../../components/DataTable.vue';
import { useCrudTable } from '../../../composables/useCrudTable';
import { useFormat } from '../../../composables/useFormat';
import http from '../../../lib/http';

const { t } = useI18n();
const { number } = useFormat();
const TYPE_LABEL_KEYS = { asset: 'Asset', liability: 'Liability', equity: 'Equity', income: 'Income', expense: 'Expense' };
const typeLabel = type => (TYPE_LABEL_KEYS[type] ? t(TYPE_LABEL_KEYS[type]) : type);

const filters = ref({ to: '', type: '' });
const summary = ref({ assets: 0, liabilities: 0, equity: 0, balance: 0 });
const inventory = ref(null);
const truingUp = ref(false);

const crud = useCrudTable('accounting/v2/reports/balance-sheet', {
  sortField: 'code',
  sortType: 'asc',
  params: () => ({
    to: filters.value.to || '',
    type: filters.value.type || '',
  }),
  select: p => {
    if (p) {
      summary.value = {
        assets: Number(p.assets) || 0,
        liabilities: Number(p.liabilities) || 0,
        equity: Number(p.equity) || 0,
        balance: Number(p.balance) || 0,
      };
      inventory.value = p.inventory
        ? {
            stock_value: Number(p.inventory.stock_value) || 0,
            book_value: Number(p.inventory.book_value) || 0,
            gap: Number(p.inventory.gap) || 0,
            can_true_up: !!p.inventory.can_true_up,
          }
        : null;
    }
    const rows = (p && p.data) || [];
    return { rows, total: (p && (p.totalRows ?? p.total ?? rows.length)) || 0 };
  },
});

const columns = computed(() => [
  { title: t('Code'), dataIndex: 'code', key: 'code', sorter: true },
  { title: t('Name'), dataIndex: 'name', key: 'name' },
  { title: t('Type'), key: 'type' },
  { title: t('Amount'), key: 'amount', align: 'right', sorter: true },
]);

function confirmTrueUp() {
  Modal.confirm({
    title: t('Inventory_True_Up'),
    content: t('Inventory_True_Up_Confirm'),
    okText: t('Inventory_True_Up'),
    cancelText: t('Cancel'),
    async onOk() {
      truingUp.value = true;
      try {
        await http.post('accounting/v2/reports/inventory-true-up');
        message.success(t('Inventory_True_Up_Done'));
        await crud.reload();
      } catch (e) {
        message.error(e?.data?.message || t('Operation_Failed'));
      } finally {
        truingUp.value = false;
      }
    },
  });
}

onMounted(crud.fetchRows);
</script>

<style scoped>
.filter-label {
  font-size: 12px;
  color: rgba(var(--ink-rgb), 0.55);
  margin-bottom: 4px;
}
.totals {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 6px;
}
.totals > div {
  display: flex;
  gap: 24px;
}
.inv-figures {
  display: flex;
  flex-wrap: wrap;
  gap: 8px 24px;
}
.inv-figures > div {
  display: flex;
  gap: 8px;
}
</style>
