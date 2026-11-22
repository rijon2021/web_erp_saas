<template>
  <div class="page">
    <PageHeader :title="$t('Customer_details')" :breadcrumb="[$t('Customers'), $t('Customer_details')]">
      <template #actions>
        <a-button @click="$router.push('/customers')">
          <template #icon><LeftOutlined /></template>
          {{ $t('Back') }}
        </a-button>
        <AuditHistoryButton type="client" />
        <a-button
          v-if="totalDue > 0 && auth.can('pay_due')"
          type="primary" @click="openPayDue"
        >
          <template #icon><DollarOutlined /></template>
          {{ $t('Pay_Due') }}
        </a-button>
      </template>
    </PageHeader>

    <div v-if="loading" style="display: flex; justify-content: center; padding: 96px 0">
      <a-spin size="large" />
    </div>

    <template v-else>
      <a-card size="small" style="margin-bottom: 16px">
        <a-descriptions :title="client.name" :column="{ xs: 1, sm: 2, lg: 3 }" size="small">
          <a-descriptions-item :label="$t('Code')">{{ client.code }}</a-descriptions-item>
          <a-descriptions-item :label="$t('Email')">{{ client.email || '-' }}</a-descriptions-item>
          <a-descriptions-item :label="$t('Phone')">{{ client.phone || '-' }}</a-descriptions-item>
          <a-descriptions-item :label="$t('City')">{{ client.city || '-' }}</a-descriptions-item>
          <a-descriptions-item :label="$t('Country')">{{ client.country || '-' }}</a-descriptions-item>
          <a-descriptions-item :label="$t('Tax_Number')">{{ client.tax_number || '-' }}</a-descriptions-item>
          <a-descriptions-item :label="$t('Credit_Limit')">{{ creditLimitText }}</a-descriptions-item>
          <a-descriptions-item v-if="brief.courier_return_risk" :label="$t('Cr_Return_Risk')">
            <ReturnRiskBadge :risk="brief.courier_return_risk" />
          </a-descriptions-item>
        </a-descriptions>
      </a-card>

      <a-row :gutter="[16, 16]" style="margin-bottom: 16px">
        <a-col :xs="24" :md="8">
          <a-card size="small">
            <a-statistic
              :title="$t('Opening_Balance')" :value="money(openingBalance)"
              :value-style="{ color: openingBalance > 0 ? '#ff4d4f' : '#52c41a' }"
            />
            <div class="muted">{{ $t('Previous_Dues') }}</div>
          </a-card>
        </a-col>
        <a-col :xs="24" :md="8">
          <a-card size="small">
            <a-statistic
              :title="$t('Sales_Due')" :value="money(salesDue)"
              :value-style="{ color: salesDue > 0 ? '#ff4d4f' : '#52c41a' }"
            />
            <div class="muted">{{ $t('Current_Sales') }}</div>
          </a-card>
        </a-col>
        <a-col :xs="24" :md="8">
          <a-card size="small">
            <a-statistic :title="$t('Credit_Limit')" :value="creditLimitText" :value-style="{ color: '#1677ff' }" />
            <div class="muted">{{ $t('Maximum_credit_amount_allowed_for_this_customer') }}</div>
          </a-card>
        </a-col>
        <a-col v-if="walletEnabled" :xs="24" :md="8">
          <a-card size="small">
            <a-statistic
              :title="$t('WalletBalance')" :value="money(walletBalance)"
              :value-style="{ color: walletBalance < 0 ? '#ff4d4f' : '#52c41a' }"
            />
            <div class="muted">
              {{ $t('CW_Customer_Wallet') }}
              <a-tag v-if="brief.wallet_status === 'frozen'" color="error" style="margin-inline-start: 6px">{{ $t('SW_Frozen') }}</a-tag>
              <a v-if="auth.can('customer_wallet_view')" style="margin-inline-start: 6px" @click="$router.push(`/customers/${id}/wallet`)">
                {{ $t('CW_Open_Ledger') }} →
              </a>
            </div>
          </a-card>
        </a-col>
        <a-col :xs="24" :md="8">
          <a-card size="small">
            <a-statistic :title="$t('Loyalty_Points')" :value="loyaltyPoints" :value-style="{ color: '#faad14' }" />
            <div class="muted">{{ $t('Points') }}</div>
          </a-card>
        </a-col>
      </a-row>

      <!-- Customer performance. Every figure is derived from the same
           warehouse-scoped rows as the tabs below, so the two always agree. -->
      <a-card v-if="performance" size="small" :title="$t('Customer_Performance')" style="margin-bottom: 16px">
        <template #extra>
          <a-tag :color="ratingColor">{{ $t(ratingLabel) }}</a-tag>
        </template>

        <!-- Why the rating is held down, when it is. -->
        <a-alert
          v-if="performanceFlags.length"
          type="warning"
          show-icon
          style="margin-bottom: 14px"
          :message="$t('Rating_capped')"
        >
          <template #description>
            <ul class="flag-list">
              <li v-for="f in performanceFlags" :key="f">{{ $t(f) }}</li>
            </ul>
          </template>
        </a-alert>

        <a-row :gutter="[16, 16]" align="middle">
          <a-col :xs="24" :md="7">
            <div v-if="performance.score === null" class="score-empty">
              <div class="score-empty-title">{{ $t('No_purchases_yet') }}</div>
              <div class="muted">{{ $t('Rating_appears_after_first_sale') }}</div>
            </div>
            <a-progress
              v-else
              type="dashboard"
              :percent="performance.score"
              :stroke-color="ratingHex"
              :format="p => `${p}/100`"
            />
          </a-col>

          <a-col :xs="24" :md="17">
            <a-row :gutter="[16, 12]">
              <a-col v-for="m in performanceMetrics" :key="m.label" :xs="12" :md="8">
                <div class="muted">{{ $t(m.label) }}</div>
                <div class="metric-value">{{ m.value }}</div>
              </a-col>
            </a-row>

            <template v-if="performance.score !== null">
              <a-divider style="margin: 14px 0 10px" />
              <div class="muted" style="margin-bottom: 6px">{{ $t('Score_breakdown') }}</div>
              <div class="breakdown">
                <span v-for="b in scoreBreakdown" :key="b.label">
                  {{ $t(b.label) }}
                  <strong>{{ b.points }}</strong
                  ><span class="muted">/{{ b.max }}</span>
                </span>
              </div>
            </template>
          </a-col>
        </a-row>
      </a-card>

      <a-tabs v-model:activeKey="tab">
        <a-tab-pane v-for="pane in panes" :key="pane.key" :tab="$t(pane.label)">
          <a-card size="small">
            <a-input-search
              v-model:value="pane.state.search"
              :placeholder="$t('Search')"
              allow-clear style="max-width: 280px; margin-bottom: 12px"
              @search="() => reload(pane)"
            />
            <a-table
              :columns="pane.columns" :data-source="pane.state.rows" :loading="pane.state.loading"
              :pagination="{ current: pane.state.page, pageSize: pane.state.limit, total: pane.state.total, showSizeChanger: false }"
              size="middle" :row-key="r => r.id ?? r.Ref" :scroll="{ x: 'max-content' }"
              @change="p => { pane.state.page = p.current; fetchPane(pane); }"
            >
              <template #bodyCell="{ column, record }">
                <template v-if="MONEY_KEYS.includes(column.key)">
                  <span :class="dueClass(column.key, record)">{{ money(record[column.key]) }}</span>
                </template>
                <template v-else-if="column.key === 'payment_status'">
                  <a-tag :color="paymentStatusColor(record.payment_status)">{{ record.payment_status }}</a-tag>
                </template>
                <template v-else-if="column.key === 'payment_type'">
                  <a-tag :color="record.payment_type === 'opening_balance' ? 'processing' : 'success'">
                    {{ record.payment_type === 'opening_balance' ? $t('Opening_Balance') : $t('Sale') }}
                  </a-tag>
                </template>
                <template v-else-if="column.key === 'Sale_Ref'">
                  <span v-if="record.Sale_Ref">{{ record.Sale_Ref }}</span>
                  <span v-else class="muted">-</span>
                </template>
              </template>
              <template #emptyText>
                <a-empty :description="$t('NodataAvailable')" style="padding: 24px 0" />
              </template>
            </a-table>
          </a-card>
        </a-tab-pane>
      </a-tabs>

      <a-card v-if="customFields.length" size="small" :title="$t('CustomFields')" style="margin-top: 16px">
        <a-descriptions :column="{ xs: 1, md: 2 }" size="small">
          <a-descriptions-item v-for="f in customFields" :key="f.id" :label="f.name">
            {{ customFieldValue(f) }}
          </a-descriptions-item>
        </a-descriptions>
      </a-card>
    </template>

    <!-- Pay due + printable credit note (shared with the customers list) -->
    <PayDueModal
      ref="payModal"
      :client="client"
      :opening-balance="openingBalance"
      :sales-due="salesDue"
      :payment-methods="paymentMethods"
      :accounts="accounts"
      :company="company"
      @paid="onPaid"
    />
  </div>
</template>

<script setup>
/**
 * Customer details — legacy CustomerDetails.vue. Header + three figures, four
 * independently paginated tabs (sales / payments / returns / payment returns),
 * custom fields, and the pay-due flow with its printable credit note.
 *
 * TWO legacy defects are deliberately not reproduced, both of which mislead
 * about money:
 *
 * 1. Sales Due was summed from the LOADED PAGE of the sales table, so a
 *    customer with more than ten sales showed too small a due — and that same
 *    figure capped the pay-due amount and decided whether the Pay Due button
 *    appeared at all. The true aggregate comes from GET clients/{id}/brief
 *    (sale_due / netBalance), the endpoint the ledger page already uses, so
 *    this needs no new permission.
 * 2. The payment-status badge tested `includes('paid')` before `'unpaid'`,
 *    and "unpaid" contains "paid" — every unpaid row rendered green. Ordering
 *    the check on 'unpaid' first fixes it.
 */
import { ref, reactive, computed, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { LeftOutlined, DollarOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import AuditHistoryButton from '../../components/AuditHistoryButton.vue';
import PayDueModal from '../../components/PayDueModal.vue';
import ReturnRiskBadge from '../couriers/ReturnRiskBadge.vue';
import { useAuthStore } from '../../stores/auth';
import { useFormat } from '../../composables/useFormat';
import http from '../../lib/http';

const { t } = useI18n();
const { money, roundMoney } = useFormat();
const auth = useAuthStore();
const route = useRoute();
const router = useRouter();
const id = route.params.id;

const loading = ref(true);
const tab = ref('sales');
const client = ref({});
const brief = ref({});
const paymentMethods = ref([]);
const accounts = ref([]);
const customFields = ref([]);
const company = ref({ CompanyName: '', CompanyAdress: '', CompanyPhone: '' });

const MONEY_KEYS = ['GrandTotal', 'paid_amount', 'due', 'montant'];

const openingBalance = computed(() => Number(brief.value.opening_balance ?? client.value.opening_balance) || 0);
const salesDue = computed(() => Number(brief.value.sale_due) || 0);
/** Legacy: opening balance + sales due. netBalance also nets off return dues. */
// Rounded: the raw float sum is the :max of the paying-amount input, and the
// clamp would otherwise display something like 121.99999999999994.
const totalDue = computed(() => roundMoney(openingBalance.value + salesDue.value));
const creditLimitText = computed(() =>
  Number(client.value.credit_limit) > 0 ? money(client.value.credit_limit) : t('No_limit'));
const walletEnabled = computed(() => !!brief.value.wallet_enabled);
const walletBalance = computed(() => Number(brief.value.wallet_balance) || 0);
const loyaltyPoints = computed(() => Number(brief.value.points ?? client.value.points) || 0);

// ---------------- performance ----------------

const performance = computed(() => brief.value.performance || null);

const RATINGS = {
  excellent: { label: 'Rating_excellent', color: 'success', hex: '#52c41a' },
  good: { label: 'Rating_good', color: 'processing', hex: '#1677ff' },
  fair: { label: 'Rating_fair', color: 'warning', hex: '#faad14' },
  poor: { label: 'Rating_poor', color: 'error', hex: '#ff4d4f' },
  new: { label: 'Rating_new', color: 'default', hex: '#8c8c8c' },
};
const rating = computed(() => RATINGS[performance.value?.rating] || RATINGS.new);
const ratingLabel = computed(() => rating.value.label);
const ratingColor = computed(() => rating.value.color);
const ratingHex = computed(() => rating.value.hex);

/** "-" rather than "0 days" when the customer has never bought. */
const daysSinceText = computed(() => {
  const d = performance.value?.days_since_last_purchase;
  if (d === null || d === undefined) return '-';
  return d === 0 ? t('Today') : `${d} ${t('Days_Ago')}`;
});

const performanceMetrics = computed(() => {
  const p = performance.value;
  if (!p) return [];
  return [
    { label: 'Total_Orders', value: p.orders },
    { label: 'TotalAmount', value: money(p.total_purchased) },
    { label: 'Average_Order_Value', value: money(p.average_order_value) },
    { label: 'Last_Purchase', value: p.last_purchase || '-' },
    { label: 'Last_Seen', value: daysSinceText.value },
    { label: 'Orders_Per_Month', value: p.orders_per_month },
    { label: 'Paid_Ratio', value: `${p.paid_ratio}%` },
    { label: 'Return_Rate', value: `${p.return_rate}%` },
    { label: 'First_Purchase', value: p.first_purchase || '-' },
  ];
});

const FLAG_LABELS = {
  low_payment: 'Flag_low_payment',
  dormant: 'Flag_dormant',
  high_returns: 'Flag_high_returns',
};
const performanceFlags = computed(() =>
  (performance.value?.flags || []).map(f => FLAG_LABELS[f]).filter(Boolean));

const scoreBreakdown = computed(() => {
  const b = performance.value?.breakdown;
  if (!b) return [];
  return [
    { label: 'Payment_reliability', points: b.payment, max: 40 },
    { label: 'Recency', points: b.recency, max: 30 },
    { label: 'Returns', points: b.returns, max: 30 },
  ];
});

function paymentStatusColor(status) {
  const s = String(status || '').toLowerCase();
  // 'unpaid' contains 'paid' — test it first or unpaid renders green.
  if (s.includes('unpaid')) return 'error';
  if (s.includes('partial')) return 'warning';
  if (s.includes('paid')) return 'success';
  return 'default';
}
function dueClass(key, record) {
  return key === 'due' && Number(record.due) > 0 ? 'due-open' : '';
}
function customFieldValue(f) {
  if (!f.value && f.value !== 0 && f.value !== false) return '-';
  if (f.field_type === 'checkbox') {
    return ['1', 1, true].includes(f.value) ? t('Yes') : t('No');
  }
  return f.value;
}

// ---------------- tabs ----------------

const makeState = () => reactive({ rows: [], total: 0, page: 1, limit: 10, search: '', loading: false });

const panes = computed(() => [
  {
    key: 'sales', label: 'Sales', endpoint: 'sales_client', rowsKey: 'sales', state: states.sales,
    columns: [
      { title: t('Ref'), dataIndex: 'Ref', key: 'Ref' },
      { title: t('date'), dataIndex: 'date', key: 'date' },
      { title: t('warehouse'), dataIndex: 'warehouse_name', key: 'warehouse_name' },
      { title: t('Grand_Total'), dataIndex: 'GrandTotal', key: 'GrandTotal', align: 'right' },
      { title: t('Paid'), dataIndex: 'paid_amount', key: 'paid_amount', align: 'right' },
      { title: t('Due'), dataIndex: 'due', key: 'due', align: 'right' },
      { title: t('Payment_Status'), dataIndex: 'payment_status', key: 'payment_status' },
      { title: t('Status'), dataIndex: 'statut', key: 'statut' },
    ],
  },
  {
    key: 'payments', label: 'Payments', endpoint: 'payments_client', rowsKey: 'payments', state: states.payments,
    columns: [
      { title: t('Ref'), dataIndex: 'Ref', key: 'Ref' },
      { title: t('date'), dataIndex: 'date', key: 'date' },
      { title: t('Type'), dataIndex: 'payment_type', key: 'payment_type' },
      { title: t('Sale_Ref'), dataIndex: 'Sale_Ref', key: 'Sale_Ref' },
      { title: t('Payment_Method'), dataIndex: 'payment_method', key: 'payment_method' },
      { title: t('Amount'), dataIndex: 'montant', key: 'montant', align: 'right' },
    ],
  },
  {
    key: 'returns', label: 'Returns', endpoint: 'returns_client', rowsKey: 'returns', state: states.returns,
    columns: [
      { title: t('Ref'), dataIndex: 'Ref', key: 'Ref' },
      { title: t('date'), dataIndex: 'date', key: 'date' },
      { title: t('Sale_Ref'), dataIndex: 'sale_ref', key: 'sale_ref' },
      { title: t('warehouse'), dataIndex: 'warehouse_name', key: 'warehouse_name' },
      { title: t('Grand_Total'), dataIndex: 'GrandTotal', key: 'GrandTotal', align: 'right' },
      { title: t('Paid'), dataIndex: 'paid_amount', key: 'paid_amount', align: 'right' },
      { title: t('Due'), dataIndex: 'due', key: 'due', align: 'right' },
      { title: t('Payment_Status'), dataIndex: 'payment_status', key: 'payment_status' },
      { title: t('Status'), dataIndex: 'statut', key: 'statut' },
    ],
  },
  {
    key: 'payment_returns', label: 'Payment_Returns', endpoint: 'payment_returns_client',
    rowsKey: 'payment_returns', state: states.payment_returns,
    columns: [
      { title: t('Ref'), dataIndex: 'Ref', key: 'Ref' },
      { title: t('date'), dataIndex: 'date', key: 'date' },
      { title: t('Sale_Return_Ref'), dataIndex: 'Sale_Return_Ref', key: 'Sale_Return_Ref' },
      { title: t('Payment_Method'), dataIndex: 'payment_method', key: 'payment_method' },
      { title: t('Amount'), dataIndex: 'montant', key: 'montant', align: 'right' },
    ],
  },
]);

const states = {
  sales: makeState(), payments: makeState(), returns: makeState(), payment_returns: makeState(),
};

async function fetchPane(pane) {
  const s = pane.state;
  s.loading = true;
  try {
    const data = await http.get(pane.endpoint, {
      id, page: s.page, limit: s.limit, search: s.search,
    });
    s.rows = data?.[pane.rowsKey] || [];
    s.total = Number(data?.totalRows) || 0;
  } catch (e) {
    s.rows = [];
  } finally {
    s.loading = false;
  }
}
/** A new search starts from page 1 — legacy kept the old page and showed nothing. */
function reload(pane) {
  pane.state.page = 1;
  fetchPane(pane);
}

// ---------------- pay due ----------------

const payModal = ref();

function openPayDue() {
  payModal.value?.open();
}

async function onPaid() {
  await loadCustomer();
  panes.value.forEach(fetchPane);
}

// ---------------- load ----------------

async function loadCustomer() {
  const [main, briefRes] = await Promise.allSettled([
    http.get(`clients/${id}`),
    http.get(`clients/${id}/brief`),
  ]);
  if (main.status !== 'fulfilled' || !main.value?.client) {
    message.error(t('Failed_to_load_customer'));
    router.push('/customers');
    return false;
  }
  client.value = main.value.client;
  paymentMethods.value = main.value.payment_methods || [];
  accounts.value = main.value.accounts || [];
  if (briefRes.status === 'fulfilled') brief.value = briefRes.value || {};
  return true;
}

onMounted(async () => {
  try {
    if (!(await loadCustomer())) return;

    const [fieldsRes, valuesRes, settingsRes] = await Promise.allSettled([
      http.get('custom-fields', { entity_type: 'client' }),
      http.get('custom-field-values', { entity_type: 'App\\Models\\Client', entity_id: id }),
      http.get('get_Settings_data'),
    ]);

    if (fieldsRes.status === 'fulfilled' && valuesRes.status === 'fulfilled') {
      const values = valuesRes.value?.success && valuesRes.value?.values ? valuesRes.value.values : {};
      customFields.value = (fieldsRes.value?.custom_fields || []).map(f => ({
        id: f.id, name: f.name, field_type: f.field_type,
        value: values[f.id] ? values[f.id].value : null,
      }));
    }
    if (settingsRes.status === 'fulfilled' && settingsRes.value?.setting) {
      const s = settingsRes.value.setting;
      company.value = {
        CompanyName: s.CompanyName || '', CompanyAdress: s.CompanyAdress || '', CompanyPhone: s.CompanyPhone || '',
      };
    }

    panes.value.forEach(fetchPane);
  } finally {
    loading.value = false;
  }
});
</script>

<style scoped>
.muted {
  color: rgba(var(--ink-rgb), 0.45);
  font-size: 12px;
}
.due-open {
  color: #ff4d4f;
  font-weight: 600;
}
.metric-value {
  font-size: 15px;
  font-weight: 600;
  line-height: 1.4;
}
.breakdown {
  display: flex;
  flex-wrap: wrap;
  gap: 8px 20px;
  font-size: 13px;
}
.score-empty {
  text-align: center;
  padding: 12px 0;
}
.score-empty-title {
  font-size: 15px;
  font-weight: 600;
  margin-bottom: 4px;
}
.flag-list {
  margin: 0;
  padding-inline-start: 18px;
}
</style>
