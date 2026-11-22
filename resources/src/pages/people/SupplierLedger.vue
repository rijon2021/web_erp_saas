<template>
  <div class="page">
    <PageHeader :title="$t('Supplier_Ledger')" :breadcrumb="[$t('Suppliers'), $t('Supplier_Ledger')]">
      <template #actions>
        <a-button @click="$router.push('/suppliers/wallets')">
          <template #icon><LeftOutlined /></template>
          {{ $t('Back') }}
        </a-button>
        <template v-if="canManage && enabled">
          <a-button type="primary" @click="openOps('top_up')">
            <template #icon><PlusCircleOutlined /></template>
            {{ $t('SW_Top_Up') }}
          </a-button>
          <a-button @click="openOps('refund')">
            <template #icon><MinusCircleOutlined /></template>
            {{ $t('SW_Refund') }}
          </a-button>
          <a-button @click="openOps('adjust')">
            <template #icon><CalculatorOutlined /></template>
            {{ $t('SW_Adjust') }}
          </a-button>
          <a-button :danger="wallet.status !== 'frozen'" @click="toggleStatus">
            <template #icon><component :is="wallet.status === 'frozen' ? UnlockOutlined : LockOutlined" /></template>
            {{ wallet.status === 'frozen' ? $t('SW_Unfreeze') : $t('SW_Freeze') }}
          </a-button>
        </template>
      </template>
    </PageHeader>

    <div v-if="loading" style="display: flex; justify-content: center; padding: 96px 0">
      <a-spin size="large" />
    </div>

    <template v-else>
      <a-alert v-if="!enabled" type="warning" show-icon style="margin-bottom: 16px" :message="$t('SW_Disabled_Hint')" />

      <!-- Supplier banner -->
      <a-card :bordered="false" class="ledger-hero" style="margin-bottom: 16px">
        <div class="hero-main">
          <a-avatar :size="60" class="hero-avatar">{{ initials }}</a-avatar>
          <div class="hero-info">
            <div class="hero-name-row">
              <span class="hero-name">{{ provider.name || '-' }}</span>
              <a-tag v-if="provider.code" color="blue" class="hero-code">#{{ provider.code }}</a-tag>
              <a-tag :color="wallet.status === 'frozen' ? 'error' : 'success'">
                {{ wallet.status === 'frozen' ? $t('SW_Frozen') : $t('Active') }}
              </a-tag>
            </div>
            <div class="hero-meta">
              <span v-if="provider.email" class="meta-chip"><MailOutlined /> {{ provider.email }}</span>
              <span v-if="provider.phone" class="meta-chip"><PhoneOutlined /> {{ provider.phone }}</span>
              <span v-if="cityCountry" class="meta-chip"><EnvironmentOutlined /> {{ cityCountry }}</span>
              <span v-if="provider.tax_number" class="meta-chip"><IdcardOutlined /> {{ provider.tax_number }}</span>
            </div>
          </div>
          <div class="hero-balance">
            <div class="hero-balance-label">{{ $t('SW_Wallet_Balance') }}</div>
            <div class="hero-balance-value" :style="{ color: wallet.balance < 0 ? '#ff4d4f' : '#52c41a' }">{{ money(wallet.balance) }}</div>
          </div>
        </div>
      </a-card>

      <!-- KPI tiles -->
      <a-row :gutter="[16, 16]" style="margin-bottom: 16px">
        <a-col v-for="kpi in kpis" :key="kpi.label" :xs="12" :sm="8" :md="6" :xl="6">
          <a-card :bordered="false" class="kpi-card">
            <div class="kpi-inner">
              <div class="kpi-icon" :style="{ background: kpi.tint, color: kpi.color }">
                <component :is="kpi.icon" />
              </div>
              <div class="kpi-text">
                <div class="kpi-label">{{ $t(kpi.label) }}</div>
                <div class="kpi-value" :style="kpi.style">{{ kpi.text }}</div>
              </div>
            </div>
          </a-card>
        </a-col>
      </a-row>

      <a-tabs v-model:activeKey="tab">
        <!-- Wallet moves -->
        <a-tab-pane key="wallet" :tab="$t('SW_Wallet_Moves')">
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
              <template v-if="column.key === 'type'">
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

        <!-- Purchases / returns / payments reuse the supplier report tabs (Reports_suppliers). -->
        <template v-if="auth.can('Reports_suppliers')">
          <a-tab-pane key="purchases" :tab="$t('Purchases')">
            <ReportTab
              endpoint="report/provider_purchases" rows-key="purchases" row-key="id"
              :columns="purchaseColumns" :extra-params="{ id }" :title="titleFor('Purchases')"
              :sums="MONEY_SUMS"
            >
              <template #bodyCell="{ column, record }">
                <template v-if="column.key === 'Ref'">
                  <router-link :to="`/purchases/${record.id}`">{{ record.Ref }}</router-link>
                </template>
                <template v-else-if="MONEY_KEYS.includes(column.key)">{{ money(record[column.key]) }}</template>
                <template v-else-if="TAG_KEYS.includes(column.key)">
                  <StatusTag :tag="documentTag('purchase', column.key, record)" />
                </template>
              </template>
            </ReportTab>
          </a-tab-pane>

          <a-tab-pane key="payments" :tab="$t('Payments')">
            <ReportTab
              endpoint="report/provider_payments" rows-key="payments" row-key="Ref"
              :columns="paymentColumns" :extra-params="{ id }" :title="titleFor('Payments')"
              :sums="[{ key: 'montant', money: true }]"
            >
              <template #bodyCell="{ column, record }">
                <template v-if="column.key === 'montant'">{{ money(record.montant) }}</template>
              </template>
            </ReportTab>
          </a-tab-pane>

          <a-tab-pane key="returns" :tab="$t('Returns')">
            <ReportTab
              endpoint="report/provider_returns" rows-key="returns_supplier" row-key="id"
              :columns="returnColumns" :extra-params="{ id }" :title="titleFor('Returns')"
              :sums="MONEY_SUMS"
            >
              <template #bodyCell="{ column, record }">
                <template v-if="column.key === 'Ref'">
                  <router-link :to="`/purchase-returns/${record.id}`">{{ record.Ref }}</router-link>
                </template>
                <template v-else-if="MONEY_KEYS.includes(column.key)">{{ money(record[column.key]) }}</template>
                <template v-else-if="TAG_KEYS.includes(column.key)">
                  <StatusTag :tag="documentTag('purchase_return', column.key, record)" />
                </template>
              </template>
            </ReportTab>
          </a-tab-pane>
        </template>
      </a-tabs>
    </template>

    <SupplierWalletOpsModal
      v-model:open="opsOpen"
      :mode="opsMode"
      :provider="provider"
      :balance="Number(wallet.balance) || 0"
      :accounts="accounts"
      :payment-methods="paymentMethods"
      @done="refresh"
    />
  </div>
</template>

<script setup>
/**
 * Supplier ledger with the wallet at its centre. GET supplier_wallets/{id} →
 * { provider, wallet, figures, enabled, allow_negative, accounts,
 * payment_methods }; the wallet tab pages GET supplier_wallets/{id}/
 * transactions. Purchases / payments / returns reuse the supplier report
 * endpoints (same contract as reports/SupplierDetailReport.vue) and only show
 * for users holding Reports_suppliers.
 */
import { ref, computed, onMounted, createVNode } from 'vue';
import { useRoute } from 'vue-router';
import { message, Modal } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import {
  LeftOutlined, MailOutlined, PhoneOutlined, EnvironmentOutlined, IdcardOutlined,
  ShoppingOutlined, CheckCircleOutlined, CalculatorOutlined, ExclamationCircleOutlined,
  WalletOutlined, RollbackOutlined, DollarOutlined, PayCircleOutlined, PlusCircleOutlined,
  MinusCircleOutlined, LockOutlined, UnlockOutlined, SwapOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import ReportTab from '../../components/ReportTab.vue';
import StatusTag from '../../components/StatusTag.vue';
import SupplierWalletOpsModal from '../../components/SupplierWalletOpsModal.vue';
import { TAG_KEYS, documentTag } from '../../lib/statusTags';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import { useAuthStore } from '../../stores/auth';
import http from '../../lib/http';

const { t } = useI18n();
const { money } = useFormat();
const auth = useAuthStore();
const route = useRoute();
const id = route.params.id;

const SOURCES = ['top_up', 'refund', 'adjustment', 'purchase_payment', 'return_credit', 'opening_balance_payment', 'reversal'];

const loading = ref(true);
const tab = ref('wallet');
const provider = ref({});
const wallet = ref({ balance: 0, status: 'active', credits: 0, debits: 0, top_ups: 0, spent: 0, count: 0 });
const figures = ref({});
const enabled = ref(true);
const accounts = ref([]);
const paymentMethods = ref([]);
const canManage = computed(() => auth.can('supplier_wallet_manage'));

const initials = computed(() =>
  (provider.value.name || '?').split(/\s+/).filter(Boolean).slice(0, 2).map(w => w[0]).join('').toUpperCase() || '?'
);
const cityCountry = computed(() => [provider.value.city, provider.value.country].filter(Boolean).join(', '));

const RED = { color: '#ff4d4f' };
const GREEN = { color: '#52c41a' };
const C = {
  blue: ['#1677ff', 'rgba(22, 119, 255, 0.12)'],
  green: ['#52c41a', 'rgba(82, 196, 26, 0.12)'],
  purple: ['#722ed1', 'rgba(114, 46, 209, 0.12)'],
  red: ['#ff4d4f', 'rgba(255, 77, 79, 0.12)'],
  indigo: ['#2f54eb', 'rgba(47, 84, 235, 0.12)'],
  amber: ['#faad14', 'rgba(250, 173, 20, 0.14)'],
  teal: ['#13c2c2', 'rgba(19, 194, 194, 0.12)'],
};

const kpis = computed(() => {
  const f = figures.value;
  const w = wallet.value;
  const n = (o, k) => Number(o[k]) || 0;
  const sign = v => (v > 0 ? RED : GREEN);
  const tile = (label, text, color, extra = {}) => ({ label, text, icon: extra.icon, color: color[0], tint: color[1], style: extra.style });
  return [
    tile('SW_Wallet_Balance', money(n(w, 'balance')), C.green, { icon: WalletOutlined, style: n(w, 'balance') < 0 ? RED : GREEN }),
    tile('Total_Due', money(n(f, 'net_balance')), C.indigo, { icon: DollarOutlined, style: sign(n(f, 'net_balance')) }),
    tile('SW_Net_After_Wallet', money(n(f, 'net_after_wallet')), C.purple, { icon: SwapOutlined, style: sign(n(f, 'net_after_wallet')) }),
    tile('Opening_Balance', money(n(provider.value, 'opening_balance')), C.purple, { icon: CalculatorOutlined, style: sign(n(provider.value, 'opening_balance')) }),
    tile('Purchases_Grand', money(n(f, 'purchases_grand')), C.blue, { icon: ShoppingOutlined }),
    tile('Purchases_Paid', money(n(f, 'purchases_paid')), C.green, { icon: CheckCircleOutlined }),
    tile('Purchases_Due', money(n(f, 'purchases_due')), C.red, { icon: ExclamationCircleOutlined, style: sign(n(f, 'purchases_due')) }),
    tile('Returns_Due', money(n(f, 'return_due')), C.amber, { icon: RollbackOutlined, style: { color: '#faad14' } }),
    tile('SW_Top_Ups_Total', money(n(w, 'top_ups')), C.teal, { icon: PlusCircleOutlined }),
    tile('SW_Spent_Total', money(n(w, 'spent')), C.blue, { icon: PayCircleOutlined }),
  ];
});

/* ------------------------------------------------------------ wallet tab */
const txFilters = ref({ type: undefined, source: undefined, range: null });
const txCrud = useCrudTable(`supplier_wallets/${id}/transactions`, {
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
  { title: t('Type'), dataIndex: 'type', key: 'type', sorter: true },
  { title: t('Source'), dataIndex: 'source', key: 'source', sorter: true, exportValue: r => t('SW_Src_' + r.source) },
  { title: t('Reference'), dataIndex: 'reference', key: 'reference' },
  { title: t('Amount'), dataIndex: 'amount', key: 'amount', sorter: true, align: 'right', exportValue: r => (r.type === 'credit' ? '+' : '-') + money(r.amount) },
  { title: t('SW_Balance_After'), dataIndex: 'balance_after', key: 'balance_after', align: 'right', exportValue: r => money(r.balance_after) },
  { title: t('Account'), key: 'via', exportValue: r => r.account_name || r.payment_method || '' },
  { title: t('User'), dataIndex: 'user_name', key: 'user_name' },
  { title: t('Note'), dataIndex: 'note', key: 'note' },
]);

/* ------------------------------------------------------- report tabs */
const titleFor = key => `${t('Supplier_Ledger')} / ${provider.value.name || ''} / ${t(key)}`;
const MONEY_KEYS = ['GrandTotal', 'paid_amount', 'due', 'montant'];
const MONEY_SUMS = [
  { key: 'GrandTotal', money: true },
  { key: 'paid_amount', money: true },
  { key: 'due', money: true },
];
const moneyCols = () => [
  { title: t('Total'), dataIndex: 'GrandTotal', key: 'GrandTotal', align: 'right', exportValue: r => money(r.GrandTotal) },
  { title: t('Paid'), dataIndex: 'paid_amount', key: 'paid_amount', align: 'right', exportValue: r => money(r.paid_amount) },
  { title: t('Due'), dataIndex: 'due', key: 'due', align: 'right', exportValue: r => money(r.due) },
];
const purchaseColumns = computed(() => [
  { title: t('Reference'), dataIndex: 'Ref', key: 'Ref' },
  { title: t('warehouse'), dataIndex: 'warehouse_name', key: 'warehouse_name' },
  ...moneyCols(),
  { title: t('Status'), dataIndex: 'statut', key: 'statut' },
  { title: t('PaymentStatus'), dataIndex: 'payment_status', key: 'payment_status' },
]);
const returnColumns = computed(() => [
  { title: t('Reference'), dataIndex: 'Ref', key: 'Ref' },
  { title: t('warehouse'), dataIndex: 'warehouse_name', key: 'warehouse_name' },
  { title: t('Purchase_Ref'), dataIndex: 'purchase_ref', key: 'purchase_ref' },
  ...moneyCols(),
  { title: t('Status'), dataIndex: 'statut', key: 'statut' },
  { title: t('PaymentStatus'), dataIndex: 'payment_status', key: 'payment_status' },
]);
const paymentColumns = computed(() => [
  { title: t('date'), dataIndex: 'date', key: 'date' },
  { title: t('Reference'), dataIndex: 'Ref', key: 'Ref' },
  { title: t('Purchase'), dataIndex: 'purchase_Ref', key: 'purchase_Ref' },
  { title: t('ModePaiement'), dataIndex: 'payment_method', key: 'payment_method' },
  { title: t('Amount'), dataIndex: 'montant', key: 'montant', align: 'right', exportValue: r => money(r.montant) },
]);

/* --------------------------------------------------------------- actions */
const opsOpen = ref(false);
const opsMode = ref('top_up');
function openOps(mode) {
  opsMode.value = mode;
  opsOpen.value = true;
}

function toggleStatus() {
  const next = wallet.value.status === 'frozen' ? 'active' : 'frozen';
  Modal.confirm({
    title: next === 'frozen' ? t('SW_Freeze') : t('SW_Unfreeze'),
    icon: createVNode(ExclamationCircleOutlined),
    content: next === 'frozen' ? t('SW_Freeze_Confirm') : t('SW_Unfreeze_Confirm'),
    okText: t('submit'),
    cancelText: t('Delete_cancelButtonText'),
    async onOk() {
      try {
        await http.post(`supplier_wallets/${id}/status`, { status: next });
        message.success(t('Successfully_Updated'));
        refresh();
      } catch (e) {
        message.error(e?.data?.message || t('InvalidData'));
      }
    },
  });
}

async function loadHeader() {
  const data = await http.get(`supplier_wallets/${id}`);
  provider.value = data.provider || {};
  wallet.value = { ...wallet.value, ...(data.wallet || {}) };
  figures.value = data.figures || {};
  enabled.value = data.enabled !== false;
  accounts.value = data.accounts || [];
  paymentMethods.value = data.payment_methods || [];
}

async function refresh() {
  try { await loadHeader(); } catch (e) { /* keep last figures */ }
  txCrud.fetchRows();
}

onMounted(async () => {
  try {
    await loadHeader();
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    loading.value = false;
  }
  txCrud.fetchRows(); // the wallet tab table does not load on its own
});
</script>

<style scoped>
.ledger-hero {
  border: 1px solid rgba(82, 196, 26, 0.25);
  background: linear-gradient(120deg, rgba(82, 196, 26, 0.08), rgba(22, 119, 255, 0.05));
}
.hero-main { display: flex; align-items: center; gap: 18px; flex-wrap: wrap; }
.hero-avatar { flex: 0 0 auto; background: #52c41a; color: #fff; font-size: 22px; font-weight: 600; }
.hero-info { min-width: 0; flex: 1 1 auto; }
.hero-name-row { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.hero-name { font-size: 22px; font-weight: 700; line-height: 1.2; }
.hero-code { margin: 0; }
.hero-meta { display: flex; flex-wrap: wrap; gap: 8px 18px; margin-top: 8px; }
.meta-chip { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; color: rgba(var(--ink-rgb), 0.6); }
.meta-chip :deep(svg) { color: rgba(var(--ink-rgb), 0.35); }
.hero-balance { text-align: end; }
.hero-balance-label { font-size: 12px; color: rgba(var(--ink-rgb), 0.5); }
.hero-balance-value { font-size: 28px; font-weight: 700; line-height: 1.1; }

.kpi-card { border: 1px solid var(--line); box-shadow: 0 1px 4px rgba(0, 0, 0, 0.04); height: 100%; }
.kpi-inner { display: flex; align-items: center; gap: 12px; }
.kpi-icon { width: 44px; height: 44px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 20px; flex: 0 0 auto; }
.kpi-text { min-width: 0; flex: 1 1 auto; }
.kpi-label {
  font-size: 12px; color: rgba(var(--ink-rgb), 0.45); line-height: 1.3; margin-bottom: 3px;
  display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;
}
.kpi-value { font-size: 18px; font-weight: 700; line-height: 1.2; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

.filters { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; margin-bottom: 12px; }
.sums { margin-inline-start: auto; font-size: 13px; }
.muted { color: rgba(var(--ink-rgb), 0.45); }

@media (max-width: 575px) {
  .hero-main { gap: 12px; }
  .hero-name { font-size: 18px; }
  .hero-balance { text-align: start; width: 100%; }
  .kpi-value { font-size: 16px; }
}
</style>
