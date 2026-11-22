<template>
  <div class="page">
    <PageHeader :title="$t('Supplier_Wallets')" :breadcrumb="[$t('People'), $t('Supplier_Wallets')]">
      <template #actions>
        <a-button @click="$router.push('/suppliers')">
          <template #icon><TeamOutlined /></template>
          {{ $t('Suppliers') }}
        </a-button>
      </template>
    </PageHeader>

    <a-alert
      v-if="payload && payload.enabled === false"
      type="warning" show-icon style="margin-bottom: 16px"
      :message="$t('SW_Disabled_Hint')"
    />

    <!-- Summary cards: across every supplier wallet, not just this page. -->
    <a-row :gutter="[16, 16]" style="margin-bottom: 16px">
      <a-col v-for="card in cards" :key="card.label" :xs="12" :md="6">
        <a-card size="small" class="stat-card">
          <div class="stat-inner">
            <div class="stat-icon" :style="{ background: card.tint, color: card.color }"><component :is="card.icon" /></div>
            <div class="stat-meta">
              <div class="stat-label">{{ $t(card.label) }}</div>
              <div class="stat-value" :style="{ color: card.valueColor }">
                <a-spin v-if="crud.loading.value && !payload" size="small" />
                <template v-else>{{ card.text }}</template>
              </div>
            </div>
          </div>
        </a-card>
      </a-col>
    </a-row>

    <a-tabs v-model:activeKey="tab">
      <!-- ================================ balances ================================ -->
      <a-tab-pane key="balances" :tab="$t('SW_Balances')">
        <div class="filters">
          <a-select v-model:value="balanceFilters.status" style="width: 160px" allow-clear :placeholder="$t('Status')" @change="crud.fetchRows()">
            <a-select-option value="active">{{ $t('Active') }}</a-select-option>
            <a-select-option value="frozen">{{ $t('SW_Frozen') }}</a-select-option>
          </a-select>
          <a-checkbox v-model:checked="balanceFilters.onlyWithBalance" @change="crud.fetchRows()">{{ $t('SW_Only_With_Balance') }}</a-checkbox>
        </div>

        <DataTable :crud="crud" :columns="columns">
          <template #bodyCell="{ column, record }">
            <template v-if="column.key === 'name'">
              <a @click="goLedger(record)">{{ record.name }}</a>
            </template>
            <template v-else-if="column.key === 'balance'">
              <strong :style="{ color: Number(record.balance) < 0 ? '#ff4d4f' : Number(record.balance) > 0 ? '#52c41a' : undefined }">
                {{ money(record.balance) }}
              </strong>
            </template>
            <template v-else-if="column.key === 'status'">
              <a-tag :color="record.status === 'frozen' ? 'error' : 'success'">
                {{ record.status === 'frozen' ? $t('SW_Frozen') : $t('Active') }}
              </a-tag>
            </template>
            <template v-else-if="column.key === 'last_activity'">
              {{ record.last_activity ? dateTime(record.last_activity) : '-' }}
            </template>
            <template v-else-if="column.key === 'actions'">
              <a-dropdown :trigger="['click']">
                <a-button type="text" size="small">
                  <template #icon><MoreOutlined style="font-size: 18px" /></template>
                </a-button>
                <template #overlay>
                  <a-menu @click="({ key }) => onAction(key, record)">
                    <a-menu-item key="ledger"><EyeOutlined /> {{ $t('Supplier_Ledger') }}</a-menu-item>
                    <template v-if="canManage">
                      <a-menu-divider />
                      <a-menu-item key="top_up"><PlusCircleOutlined /> {{ $t('SW_Top_Up') }}</a-menu-item>
                      <a-menu-item key="refund"><MinusCircleOutlined /> {{ $t('SW_Refund') }}</a-menu-item>
                      <a-menu-item key="adjust"><CalculatorOutlined /> {{ $t('SW_Adjust') }}</a-menu-item>
                      <a-menu-item key="status">
                        <template v-if="record.status === 'frozen'"><UnlockOutlined /> {{ $t('SW_Unfreeze') }}</template>
                        <template v-else><LockOutlined /> {{ $t('SW_Freeze') }}</template>
                      </a-menu-item>
                    </template>
                  </a-menu>
                </template>
              </a-dropdown>
            </template>
          </template>
        </DataTable>
      </a-tab-pane>

      <!-- ============================== transactions ============================== -->
      <a-tab-pane key="transactions" :tab="$t('Transactions')">
        <div class="filters">
          <a-select v-model:value="txFilters.type" style="width: 140px" allow-clear :placeholder="$t('Type')" @change="txCrud.fetchRows()">
            <a-select-option value="credit">{{ $t('Credit') }}</a-select-option>
            <a-select-option value="debit">{{ $t('Debit') }}</a-select-option>
          </a-select>
          <a-select v-model:value="txFilters.source" style="width: 220px" allow-clear :placeholder="$t('Source')" @change="txCrud.fetchRows()">
            <a-select-option v-for="s in SOURCES" :key="s" :value="s">{{ $t('SW_Src_' + s) }}</a-select-option>
          </a-select>
          <a-range-picker v-model:value="txFilters.range" value-format="YYYY-MM-DD" @change="txCrud.fetchRows()" />
          <span class="sums">
            {{ $t('Credit') }}: <strong style="color: #52c41a">{{ money(txCrud.payload.value?.sums?.credits || 0) }}</strong>
            &nbsp;·&nbsp;
            {{ $t('Debit') }}: <strong style="color: #ff4d4f">{{ money(txCrud.payload.value?.sums?.debits || 0) }}</strong>
          </span>
        </div>

        <DataTable :crud="txCrud" :columns="txColumns">
          <template #bodyCell="{ column, record }">
            <template v-if="column.key === 'provider_name'">
              <a @click="$router.push(`/suppliers/${record.provider_id}/ledger`)">{{ record.provider_name }}</a>
            </template>
            <template v-else-if="column.key === 'type'">
              <a-tag :color="record.type === 'credit' ? 'success' : 'error'">{{ record.type === 'credit' ? $t('Credit') : $t('Debit') }}</a-tag>
            </template>
            <template v-else-if="column.key === 'source'">{{ $t('SW_Src_' + record.source) }}</template>
            <template v-else-if="column.key === 'reference'">
              <span v-if="record.reference">{{ record.reference }}</span>
              <span v-else class="muted">-</span>
            </template>
            <template v-else-if="column.key === 'amount'">
              <strong :style="{ color: record.type === 'credit' ? '#52c41a' : '#ff4d4f' }">
                {{ record.type === 'credit' ? '+' : '-' }}{{ money(record.amount) }}
              </strong>
            </template>
            <template v-else-if="column.key === 'balance_after'">{{ money(record.balance_after) }}</template>
            <template v-else-if="column.key === 'via'">{{ record.account_name || record.payment_method || '-' }}</template>
          </template>
        </DataTable>
      </a-tab-pane>
    </a-tabs>

    <SupplierWalletOpsModal
      v-model:open="opsOpen"
      :mode="opsMode"
      :provider="opsTarget"
      :balance="Number(opsTarget?.balance) || 0"
      :accounts="payload?.accounts || []"
      :payment-methods="payload?.payment_methods || []"
      @done="refreshAll"
    />
  </div>
</template>

<script setup>
/**
 * People → Supplier Wallets. GET supplier_wallets → { wallets, totalRows,
 * totals, enabled, allow_negative, accounts, payment_methods } (every
 * supplier, balance 0 when no wallet row yet); GET supplier_wallets/
 * transactions → { transactions, totalRows, sums }. Money moves go through
 * SupplierWalletOpsModal; freeze/unfreeze POSTs supplier_wallets/{id}/status.
 */
import { ref, computed, onMounted, createVNode } from 'vue';
import { useRouter } from 'vue-router';
import { message, Modal } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import {
  MoreOutlined, EyeOutlined, PlusCircleOutlined, MinusCircleOutlined, CalculatorOutlined,
  LockOutlined, UnlockOutlined, TeamOutlined, WalletOutlined, WarningOutlined, ExclamationCircleOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import SupplierWalletOpsModal from '../../components/SupplierWalletOpsModal.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import { useAuthStore } from '../../stores/auth';
import http from '../../lib/http';

const { t } = useI18n();
const { money, dateTime } = useFormat();
const auth = useAuthStore();
const router = useRouter();

const SOURCES = ['top_up', 'refund', 'adjustment', 'purchase_payment', 'return_credit', 'opening_balance_payment', 'reversal'];

const canManage = computed(() => auth.can('supplier_wallet_manage'));
const tab = ref('balances');

/* ---------------------------------------------------------------- balances */
const balanceFilters = ref({ status: undefined, onlyWithBalance: false });
const crud = useCrudTable('supplier_wallets', {
  rowsKey: 'wallets',
  sortField: 'balance',
  params: () => ({
    status: balanceFilters.value.status || '',
    only_with_balance: balanceFilters.value.onlyWithBalance ? 1 : 0,
  }),
});
const payload = computed(() => crud.payload.value);

const columns = computed(() => [
  { title: t('Action'), key: 'actions', width: 70, align: 'center', fixed: 'left' },
  { title: t('SupplierCode'), dataIndex: 'code', key: 'code', sorter: true },
  { title: t('SupplierName'), dataIndex: 'name', key: 'name', sorter: true },
  { title: t('Phone'), dataIndex: 'phone', key: 'phone' },
  { title: t('Email'), dataIndex: 'email', key: 'email' },
  { title: t('SW_Wallet_Balance'), dataIndex: 'balance', key: 'balance', sorter: true, align: 'right', exportValue: r => money(r.balance) },
  { title: t('Status'), dataIndex: 'status', key: 'status', sorter: true },
  { title: t('Last_Activity'), dataIndex: 'last_activity', key: 'last_activity' },
]);

const cards = computed(() => {
  const tot = payload.value?.totals || {};
  return [
    { label: 'SW_Total_Advances', text: money(tot.positive || 0), icon: WalletOutlined, color: '#52c41a', tint: 'rgba(82,196,26,0.12)', valueColor: '#52c41a' },
    { label: 'SW_Overdrawn', text: money(tot.negative || 0), icon: WarningOutlined, color: '#ff4d4f', tint: 'rgba(255,77,79,0.12)', valueColor: Number(tot.negative) < 0 ? '#ff4d4f' : undefined },
    { label: 'SW_Wallets_Count', text: String(tot.wallets || 0), icon: TeamOutlined, color: '#1677ff', tint: 'rgba(22,119,255,0.12)' },
    { label: 'SW_Frozen_Count', text: String(tot.frozen || 0), icon: LockOutlined, color: '#faad14', tint: 'rgba(250,173,20,0.14)' },
  ];
});

/* ------------------------------------------------------------ transactions */
const txFilters = ref({ type: undefined, source: undefined, range: null });
const txCrud = useCrudTable('supplier_wallets/transactions', {
  rowsKey: 'transactions',
  params: () => ({
    type: txFilters.value.type || '',
    source: txFilters.value.source || '',
    from: txFilters.value.range?.[0] || '',
    to: txFilters.value.range?.[1] || '',
  }),
});

const txColumns = computed(() => [
  { title: t('date'), dataIndex: 'date', key: 'date', sorter: true },
  { title: t('Supplier'), dataIndex: 'provider_name', key: 'provider_name' },
  { title: t('Type'), dataIndex: 'type', key: 'type', sorter: true },
  { title: t('Source'), dataIndex: 'source', key: 'source', sorter: true, exportValue: r => t('SW_Src_' + r.source) },
  { title: t('Reference'), dataIndex: 'reference', key: 'reference' },
  { title: t('Amount'), dataIndex: 'amount', key: 'amount', sorter: true, align: 'right', exportValue: r => (r.type === 'credit' ? '+' : '-') + money(r.amount) },
  { title: t('SW_Balance_After'), dataIndex: 'balance_after', key: 'balance_after', align: 'right', exportValue: r => money(r.balance_after) },
  { title: t('Account'), key: 'via', exportValue: r => r.account_name || r.payment_method || '' },
  { title: t('User'), dataIndex: 'user_name', key: 'user_name' },
  { title: t('Note'), dataIndex: 'note', key: 'note' },
]);

/* ------------------------------------------------------------------ actions */
const opsOpen = ref(false);
const opsMode = ref('top_up');
const opsTarget = ref(null);

function goLedger(record) {
  router.push(`/suppliers/${record.id}/ledger`);
}

function onAction(key, record) {
  if (key === 'ledger') return goLedger(record);
  if (key === 'status') return toggleStatus(record);
  opsMode.value = key;
  opsTarget.value = record;
  opsOpen.value = true;
}

function toggleStatus(record) {
  const next = record.status === 'frozen' ? 'active' : 'frozen';
  Modal.confirm({
    title: next === 'frozen' ? t('SW_Freeze') : t('SW_Unfreeze'),
    icon: createVNode(ExclamationCircleOutlined),
    content: next === 'frozen' ? t('SW_Freeze_Confirm') : t('SW_Unfreeze_Confirm'),
    okText: t('submit'),
    cancelText: t('Delete_cancelButtonText'),
    async onOk() {
      try {
        await http.post(`supplier_wallets/${record.id}/status`, { status: next });
        message.success(t('Successfully_Updated'));
        refreshAll();
      } catch (e) {
        message.error(e?.data?.message || t('InvalidData'));
      }
    },
  });
}

function refreshAll() {
  crud.fetchRows();
  txCrud.fetchRows();
}

// useCrudTable never loads on its own: fetch both tabs once on open.
onMounted(refreshAll);
</script>

<style scoped>
.filters {
  display: flex;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
  margin-bottom: 12px;
}
.sums {
  margin-inline-start: auto;
  font-size: 13px;
}
.muted {
  color: rgba(var(--ink-rgb), 0.45);
}
.stat-card { border-radius: 10px; }
.stat-inner { display: flex; align-items: center; gap: 12px; }
.stat-icon {
  width: 44px; height: 44px; border-radius: 10px;
  display: flex; align-items: center; justify-content: center;
  font-size: 20px; flex: none;
}
.stat-meta { min-width: 0; }
.stat-label { opacity: 0.65; font-size: 13px; margin-bottom: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.stat-value { font-size: 20px; font-weight: 700; white-space: nowrap; }
@media (max-width: 575px) {
  .stat-icon { width: 36px; height: 36px; font-size: 16px; }
  .stat-value { font-size: 16px; }
}
</style>
