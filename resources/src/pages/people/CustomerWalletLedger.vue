<template>
  <div class="page">
    <PageHeader :title="$t('CW_Wallet_Ledger')" :breadcrumb="[$t('CW_Customer_Wallets'), $t('CW_Wallet_Ledger')]">
      <template #actions>
        <a-button @click="$router.push('/customers/wallets')">
          <template #icon><LeftOutlined /></template>
          {{ $t('Back') }}
        </a-button>
        <a-button v-if="auth.can('Customers_view')" @click="$router.push(`/customers/${id}/ledger`)">
          <template #icon><ProfileOutlined /></template>
          {{ $t('Customer_Ledger') }}
        </a-button>
        <template v-if="enabled">
          <template v-if="canManage">
            <a-button type="primary" @click="openOps('top_up')">
              <template #icon><PlusCircleOutlined /></template>
              {{ $t('SW_Top_Up') }}
            </a-button>
            <a-button @click="openOps('refund')">
              <template #icon><MinusCircleOutlined /></template>
              {{ $t('CW_Cash_Refund') }}
            </a-button>
            <a-button @click="openOps('adjust')">
              <template #icon><CalculatorOutlined /></template>
              {{ $t('SW_Adjust') }}
            </a-button>
          </template>
          <a-button v-if="canGift" @click="redeemOpen = true">
            <template #icon><GiftOutlined /></template>
            {{ $t('CW_Redeem_Gift_Card') }}
          </a-button>
          <a-button v-if="canManage" :danger="wallet.status !== 'frozen'" @click="toggleStatus">
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
      <a-alert v-if="migrated === false" type="error" show-icon style="margin-bottom: 16px" :message="$t('CW_Not_Migrated')" />
      <a-alert v-else-if="!enabled" type="warning" show-icon style="margin-bottom: 16px" :message="$t('CW_Disabled_Hint')" />

      <!-- Customer banner -->
      <a-card :bordered="false" class="ledger-hero" style="margin-bottom: 16px">
        <div class="hero-main">
          <a-avatar :size="60" class="hero-avatar">{{ initials }}</a-avatar>
          <div class="hero-info">
            <div class="hero-name-row">
              <span class="hero-name">{{ client.name || '-' }}</span>
              <a-tag v-if="client.code" color="blue" class="hero-code">#{{ client.code }}</a-tag>
              <a-tag :color="wallet.status === 'frozen' ? 'error' : 'success'">
                {{ wallet.status === 'frozen' ? $t('SW_Frozen') : $t('Active') }}
              </a-tag>
            </div>
            <div class="hero-meta">
              <span v-if="client.email" class="meta-chip"><MailOutlined /> {{ client.email }}</span>
              <span v-if="client.phone" class="meta-chip"><PhoneOutlined /> {{ client.phone }}</span>
              <span v-if="cityCountry" class="meta-chip"><EnvironmentOutlined /> {{ cityCountry }}</span>
            </div>
          </div>
          <div class="hero-balance">
            <div class="hero-balance-label">{{ $t('SW_Wallet_Balance') }}</div>
            <div class="hero-balance-value" :style="{ color: wallet.balance < 0 ? '#ff4d4f' : '#52c41a' }">{{ money(wallet.balance) }}</div>
            <div v-if="Number(wallet.pending_withdrawals) > 0" class="hero-pending">
              {{ $t('CW_Pending_Withdrawals') }}: {{ money(wallet.pending_withdrawals) }}
            </div>
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

      <a-card size="small" :title="$t('SW_Wallet_Moves')">
        <div class="filters">
          <a-select v-model:value="txFilters.type" style="width: 140px" allow-clear :placeholder="$t('Type')" @change="txCrud.reload()">
            <a-select-option value="credit">{{ $t('Credit') }}</a-select-option>
            <a-select-option value="debit">{{ $t('Debit') }}</a-select-option>
          </a-select>
          <a-select v-model:value="txFilters.source" style="width: 220px" allow-clear :placeholder="$t('Source')" @change="txCrud.reload()">
            <a-select-option v-for="s in sources" :key="s" :value="s">{{ srcLabel(s) }}</a-select-option>
          </a-select>
          <a-range-picker v-model:value="txFilters.range" value-format="YYYY-MM-DD" @change="txCrud.reload()" />
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
            <template v-else-if="column.key === 'source'">{{ srcLabel(record.source) }}</template>
            <template v-else-if="column.key === 'reference'">
              <template v-if="record.reference">
                <router-link v-if="referenceRoute(record)" :to="referenceRoute(record)">{{ record.reference }}</router-link>
                <span v-else>{{ record.reference }}</span>
                <div v-if="record.reference_kind" class="muted small">{{ kindLabel(record.reference_kind) }}</div>
              </template>
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
      </a-card>
    </template>

    <CustomerWalletOpsModal
      v-model:open="opsOpen"
      :mode="opsMode"
      :client="client"
      :balance="Number(wallet.balance) || 0"
      :status="wallet.status"
      :accounts="accounts"
      :payment-methods="paymentMethods"
      @done="refresh"
    />
    <GiftCardRedeemModal v-model:open="redeemOpen" :client="client" @done="refresh" />
  </div>
</template>

<script setup>
/**
 * One customer's wallet. GET customer_wallets/{id} → { client, wallet:{ balance,
 * status, credits, debits, top_ups, spent, refunds, gift_cards, count,
 * pending_withdrawals }, figures:{ sale_due, return_due, net_balance }, +flags,
 * accounts, payment_methods }; the moves table pages GET customer_wallets/{id}/
 * transactions. Ops buttons need customer_wallet_manage, redeem needs
 * gift_cards_manage.
 */
import { ref, computed, onMounted, createVNode } from 'vue';
import { useRoute } from 'vue-router';
import { message, Modal } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import {
  LeftOutlined, MailOutlined, PhoneOutlined, EnvironmentOutlined, ProfileOutlined,
  CalculatorOutlined, ExclamationCircleOutlined, WalletOutlined, RollbackOutlined, DollarOutlined,
  PayCircleOutlined, PlusCircleOutlined, MinusCircleOutlined, LockOutlined, UnlockOutlined,
  GiftOutlined, RiseOutlined, FallOutlined, OrderedListOutlined, BankOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import CustomerWalletOpsModal from '../../components/CustomerWalletOpsModal.vue';
import GiftCardRedeemModal from '../../components/GiftCardRedeemModal.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import {
  customerWalletError, CW_SOURCES, walletSourceLabel, walletKindLabel, walletReferenceRoute,
} from '../../composables/useCustomerWallet';
import { useAuthStore } from '../../stores/auth';
import http from '../../lib/http';

const { t } = useI18n();
const { money } = useFormat();
const auth = useAuthStore();
const route = useRoute();
const id = route.params.id;

const srcLabel = walletSourceLabel;
const kindLabel = walletKindLabel;
const referenceRoute = walletReferenceRoute;

const loading = ref(true);
const client = ref({});
const wallet = ref({ balance: 0, status: 'active', credits: 0, debits: 0, top_ups: 0, spent: 0, refunds: 0, gift_cards: 0, count: 0, pending_withdrawals: 0 });
const figures = ref({});
const enabled = ref(true);
const migrated = ref(true);
const accounts = ref([]);
const paymentMethods = ref([]);
const canManage = computed(() => auth.can('customer_wallet_manage'));
const canGift = computed(() => auth.can('gift_cards_manage'));

const initials = computed(() =>
  (client.value.name || '?').split(/\s+/).filter(Boolean).slice(0, 2).map(w => w[0]).join('').toUpperCase() || '?'
);
const cityCountry = computed(() => [client.value.city, client.value.country].filter(Boolean).join(', '));

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
  magenta: ['#eb2f96', 'rgba(235, 47, 150, 0.12)'],
};

const kpis = computed(() => {
  const f = figures.value;
  const w = wallet.value;
  const n = (o, k) => Number(o[k]) || 0;
  const sign = v => (v > 0 ? RED : GREEN);
  const tile = (label, text, color, extra = {}) => ({ label, text, icon: extra.icon, color: color[0], tint: color[1], style: extra.style });
  return [
    tile('SW_Wallet_Balance', money(n(w, 'balance')), C.green, { icon: WalletOutlined, style: n(w, 'balance') < 0 ? RED : GREEN }),
    tile('SW_Top_Ups_Total', money(n(w, 'top_ups')), C.teal, { icon: PlusCircleOutlined }),
    tile('SW_Spent_Total', money(n(w, 'spent')), C.blue, { icon: PayCircleOutlined }),
    tile('CW_Refunds_In', money(n(w, 'refunds')), C.amber, { icon: RollbackOutlined }),
    tile('CW_Gift_Cards_Redeemed', money(n(w, 'gift_cards')), C.magenta, { icon: GiftOutlined }),
    tile('CW_Total_Credits', money(n(w, 'credits')), C.green, { icon: RiseOutlined, style: GREEN }),
    tile('CW_Total_Debits', money(n(w, 'debits')), C.red, { icon: FallOutlined, style: RED }),
    tile('CW_Moves_Count', String(n(w, 'count')), C.purple, { icon: OrderedListOutlined }),
    tile('CW_Pending_Withdrawals', money(n(w, 'pending_withdrawals')), C.amber, { icon: BankOutlined }),
    tile('Sales_Due', money(n(f, 'sale_due')), C.red, { icon: ExclamationCircleOutlined, style: sign(n(f, 'sale_due')) }),
    tile('Total_Due', money(n(f, 'net_balance')), C.indigo, { icon: DollarOutlined, style: sign(n(f, 'net_balance')) }),
    tile('Opening_Balance', money(n(client.value, 'opening_balance')), C.purple, { icon: CalculatorOutlined, style: sign(n(client.value, 'opening_balance')) }),
  ];
});

/* ---------------------------------------------------------------- moves */
const txFilters = ref({ type: undefined, source: undefined, range: null });
const txCrud = useCrudTable(`customer_wallets/${id}/transactions`, {
  rowsKey: 'transactions',
  params: () => ({
    type: txFilters.value.type || '',
    source: txFilters.value.source || '',
    from: txFilters.value.range?.[0] || '',
    to: txFilters.value.range?.[1] || '',
  }),
});
const sources = computed(() => {
  const fromApi = txCrud.payload.value?.sources;
  return Array.isArray(fromApi) && fromApi.length ? fromApi : CW_SOURCES;
});
const txColumns = computed(() => [
  { title: t('date'), dataIndex: 'date', key: 'date', sorter: true },
  { title: t('Type'), dataIndex: 'type', key: 'type', sorter: true, exportValue: r => (r.type === 'credit' ? t('Credit') : t('Debit')) },
  { title: t('Source'), dataIndex: 'source', key: 'source', sorter: true, exportValue: r => srcLabel(r.source) },
  { title: t('Reference'), dataIndex: 'reference', key: 'reference', exportValue: r => r.reference || '' },
  { title: t('Amount'), dataIndex: 'amount', key: 'amount', sorter: true, align: 'right', exportValue: r => (r.type === 'credit' ? '+' : '-') + money(r.amount) },
  { title: t('SW_Balance_After'), dataIndex: 'balance_after', key: 'balance_after', align: 'right', exportValue: r => money(r.balance_after) },
  { title: t('Account'), key: 'via', exportValue: r => r.account_name || r.payment_method || '' },
  { title: t('User'), dataIndex: 'user_name', key: 'user_name' },
  { title: t('Note'), dataIndex: 'note', key: 'note' },
]);

/* --------------------------------------------------------------- actions */
const opsOpen = ref(false);
const opsMode = ref('top_up');
const redeemOpen = ref(false);
function openOps(mode) {
  opsMode.value = mode;
  opsOpen.value = true;
}

function toggleStatus() {
  const next = wallet.value.status === 'frozen' ? 'active' : 'frozen';
  Modal.confirm({
    title: next === 'frozen' ? t('SW_Freeze') : t('SW_Unfreeze'),
    icon: createVNode(ExclamationCircleOutlined),
    content: next === 'frozen' ? t('CW_Freeze_Confirm') : t('CW_Unfreeze_Confirm'),
    okText: t('submit'),
    cancelText: t('Delete_cancelButtonText'),
    async onOk() {
      try {
        await http.post(`customer_wallets/${id}/status`, { status: next });
        message.success(t('Successfully_Updated'));
        refresh();
      } catch (e) {
        message.error(customerWalletError(e));
      }
    },
  });
}

async function loadHeader() {
  const data = await http.get(`customer_wallets/${id}`);
  client.value = data.client || {};
  wallet.value = { ...wallet.value, ...(data.wallet || {}) };
  figures.value = data.figures || {};
  enabled.value = data.enabled !== false;
  migrated.value = data.migrated !== false;
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
    // Customer gone / no access: nothing to act on.
    enabled.value = false;
    message.error(customerWalletError(e));
  } finally {
    loading.value = false;
  }
  txCrud.fetchRows();
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
.hero-pending { font-size: 12px; color: #faad14; margin-top: 4px; }

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
.small { font-size: 12px; }

@media (max-width: 575px) {
  .hero-main { gap: 12px; }
  .hero-name { font-size: 18px; }
  .hero-balance { text-align: start; width: 100%; }
  .kpi-value { font-size: 16px; }
}
</style>
