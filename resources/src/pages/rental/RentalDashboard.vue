<template>
  <div class="page">
    <PageHeader
      :title="$t('Rental_Dashboard')"
      :subtitle="$t('Rental_Dashboard_Subtitle')"
      :breadcrumb="[$t('Rental_Management'), $t('Dashboard')]"
    >
      <template #actions>
        <a-button v-if="canView" @click="$router.push('/rental/items')">
          <template #icon><UnorderedListOutlined /></template>
          {{ $t('Rental_Items') }}
        </a-button>
        <a-button v-if="canAdd" type="primary" @click="$router.push('/rental/items/create')">
          <template #icon><PlusOutlined /></template>
          {{ $t('Rental_Add_Item') }}
        </a-button>
      </template>
    </PageHeader>

    <a-spin :spinning="loading">
      <!-- KPI strip: each tile opens the list it counts -->
      <div class="kpis">
        <button v-for="k in kpis" :key="k.key" type="button" class="kpi" @click="goList(k.status)">
          <span class="kpi-ic" :class="`kpi-ic--${k.tone}`"><component :is="k.icon" /></span>
          <span class="kpi-text">
            <span class="kpi-value">{{ k.value }}</span>
            <span class="kpi-label">{{ k.label }}</span>
          </span>
        </button>
      </div>

      <!-- Contracts strip -->
      <div v-if="canContracts" class="cstrip">
        <button type="button" class="cs" @click="$router.push('/rental/contracts?stage=active')">
          <span class="cs-value">{{ cf.running || 0 }}</span>
          <span class="cs-label">{{ $t('Rental_Running_Contracts') }}</span>
        </button>
        <button type="button" class="cs" @click="$router.push('/rental/contracts?stage=upcoming')">
          <span class="cs-value">{{ cf.upcoming || 0 }}</span>
          <span class="cs-label">{{ labelOf(CONTRACT_STAGES, 'upcoming') }}</span>
        </button>
        <button type="button" class="cs" :class="{ warn: cf.expiring }" @click="$router.push('/rental/contracts?stage=expiring')">
          <span class="cs-value">{{ cf.expiring || 0 }}</span>
          <span class="cs-label">{{ labelOf(CONTRACT_STAGES, 'expiring') }}</span>
        </button>
        <button type="button" class="cs" :class="{ bad: cf.overdue }" @click="$router.push('/rental/contracts?stage=overdue')">
          <span class="cs-value">{{ cf.overdue || 0 }}</span>
          <span class="cs-label">{{ labelOf(CONTRACT_STAGES, 'overdue') }}</span>
        </button>
        <div class="cs cs--money">
          <span class="cs-value">{{ money(cf.monthly_recurring || 0) }}</span>
          <span class="cs-label">{{ $t('Rental_Monthly_Recurring') }}</span>
        </div>
      </div>

      <!-- Money strip -->
      <div v-if="canBilling" class="mstrip">
        <button type="button" class="ms" @click="$router.push('/rental/invoices')">
          <span class="ms-label">{{ $t('Rental_Outstanding') }}</span>
          <span class="ms-value">{{ money(bl.outstanding || 0) }}</span>
        </button>
        <button type="button" class="ms" :class="{ bad: bl.overdue }" @click="$router.push('/rental/invoices?stage=overdue')">
          <span class="ms-label">{{ $t('Rental_Overdue_Amount') }}</span>
          <span class="ms-value">{{ money(bl.overdue || 0) }}</span>
          <span v-if="bl.overdue_count" class="ms-note">{{ $t('Rental_N_Invoices', { n: bl.overdue_count }) }}</span>
        </button>
        <div class="ms">
          <span class="ms-label">{{ $t('Rental_Due_This_Week') }}</span>
          <span class="ms-value">{{ money(bl.due_week || 0) }}</span>
        </div>
        <button type="button" class="ms" @click="$router.push('/rental/reports?report=deposits')">
          <span class="ms-label">{{ $t('Rental_Deposit_Held') }}</span>
          <span class="ms-value">{{ money(bl.deposits_held || 0) }}</span>
        </button>
        <button type="button" class="ms ok" @click="$router.push('/rental/payments')">
          <span class="ms-label">{{ $t('Rental_Collected_Month') }}</span>
          <span class="ms-value">{{ money(bl.collected_month || 0) }}</span>
        </button>
      </div>

      <a-row :gutter="16">
        <a-col :xs="24" :xl="16">
          <ReportChart
            v-if="canBilling && (bl.trend || []).length"
            :data="bl.trend"
            :fields="[{ key: 'billed', label: $t('Rental_Billed') }, { key: 'collected', label: $t('Rental_Collected') }]"
            :title="$t('Rental_Billing_Trend')"
            type="bar" x-key="month" :height="260" :format="money"
          />

          <!-- Occupancy + money -->
          <a-card size="small" class="block">
            <div class="hero">
              <div class="hero-gauge">
                <a-progress
                  type="dashboard" :percent="Number(data.occupancy || 0)" :size="148"
                  :stroke-width="9" stroke-color="var(--app-primary, #6d28d9)"
                  :format="p => `${p}%`"
                />
                <span class="hero-gauge-label">{{ $t('Rental_Occupancy') }}</span>
              </div>
              <div class="hero-stats">
                <div class="hero-stat">
                  <span class="hs-label">{{ $t('Rental_Monthly_Potential') }}</span>
                  <span class="hs-value">{{ money(data.monthly_potential || 0) }}</span>
                  <span class="hs-note">{{ $t('Rental_Monthly_Potential_Help') }}</span>
                </div>
                <div class="hero-stat">
                  <span class="hs-label">{{ $t('Rental_Portfolio_Value') }}</span>
                  <span class="hs-value">{{ money(data.portfolio_value || 0) }}</span>
                  <span class="hs-note">{{ $t('Rental_Portfolio_Value_Help') }}</span>
                </div>
              </div>
            </div>

            <!-- One stacked bar for the whole register's status split -->
            <div v-if="data.total" class="split">
              <div class="split-bar" role="img" :aria-label="$t('Rental_Status_Split')">
                <span
                  v-for="s in statusSplit" :key="s.value" class="split-seg"
                  :style="{ width: `${s.percent}%`, background: s.hex }" :title="`${s.label}: ${s.count}`"
                ></span>
              </div>
              <div class="split-legend">
                <button
                  v-for="s in statusSplit" :key="s.value" type="button" class="legend-item"
                  @click="goList(s.value)"
                >
                  <span class="legend-dot" :style="{ background: s.hex }"></span>
                  {{ s.label }} <strong>{{ s.count }}</strong>
                </button>
              </div>
            </div>
          </a-card>

          <!-- Recently added -->
          <a-card size="small" :title="$t('Rental_Recently_Added')" class="block">
            <template #extra>
              <a v-if="canView" @click="$router.push('/rental/items')">{{ $t('Rental_View_All') }}</a>
            </template>
            <div v-if="recent.length" class="recent">
              <button v-for="r in recent" :key="r.id" type="button" class="recent-item" @click="open(r.id)">
                <span class="recent-media">
                  <img v-if="r.image_url" :src="r.image_url" :alt="r.name" loading="lazy" />
                  <component :is="typeIcon(r.type)" v-else :size="26" :stroke-width="1.5" />
                </span>
                <span class="recent-body">
                  <span class="recent-name">{{ r.name }}</span>
                  <span class="recent-meta">{{ r.code }}<template v-if="r.category_name"> · {{ r.category_name }}</template></span>
                  <span class="recent-foot">
                    <a-tag :color="optionOf(RENTAL_STATUSES, r.status).color">{{ labelOf(RENTAL_STATUSES, r.status) }}</a-tag>
                    <span v-if="r.headline_rate !== null" class="recent-price">
                      {{ money(r.headline_rate) }} <small>{{ perLabel(r.default_period) }}</small>
                    </span>
                  </span>
                </span>
              </button>
            </div>
            <div v-else class="empty">
              <span class="empty-ic"><KeyRound :size="28" :stroke-width="1.6" /></span>
              <p>{{ $t('Rental_No_Items_Help') }}</p>
              <a-button v-if="canAdd" type="primary" @click="$router.push('/rental/items/create')">
                <template #icon><PlusOutlined /></template>
                {{ $t('Rental_Add_First_Item') }}
              </a-button>
            </div>
          </a-card>
        </a-col>

        <a-col :xs="24" :xl="8">
          <!-- Biggest overdue balances -->
          <a-card v-if="canBilling && (bl.top_overdue || []).length" size="small" class="block">
            <template #title>
              <span class="card-title"><WarningOutlined style="color: #dc2626" /> {{ $t('Rental_Overdue_Invoices') }}</span>
            </template>
            <div class="mlist">
              <button v-for="o in bl.top_overdue" :key="o.id" type="button" class="mrow" @click="$router.push(`/rental/invoices/${o.id}`)">
                <span class="mrow-body">
                  <span class="mrow-name">{{ o.customer_name }}</span>
                  <span class="mrow-meta">{{ o.invoice_number }} · {{ o.contract_number }} · {{ date(o.due_date) }}</span>
                </span>
                <strong class="od-amt">{{ money(o.balance) }}</strong>
              </button>
            </div>
          </a-card>

          <!-- Contracts ending soon -->
          <a-card v-if="canContracts" size="small" class="block">
            <template #title>
              <span class="card-title"><FieldTimeOutlined /> {{ $t('Rental_Ending_Soon') }}</span>
            </template>
            <div v-if="endingSoon.length" class="mlist">
              <button v-for="e in endingSoon" :key="e.id" type="button" class="mrow" @click="$router.push(`/rental/contracts/${e.id}`)">
                <span class="mrow-body">
                  <span class="mrow-name">{{ e.customer_name }}</span>
                  <span class="mrow-meta">{{ e.contract_number }} · {{ date(e.end_date) }}</span>
                </span>
                <a-tag v-if="e.renewed" color="purple">{{ labelOf(CONTRACT_STAGES, 'renewed') }}</a-tag>
                <a-tag v-else :color="e.days_left < 0 ? 'error' : 'warning'">{{ e.days_left < 0 ? labelOf(CONTRACT_STAGES, 'overdue') : $t('Fleet_Days_Short', { n: e.days_left }) }}</a-tag>
              </button>
            </div>
            <div v-else class="all-clear">
              <CheckCircleOutlined />
              <span>{{ $t('Rental_Nothing_Ending') }}</span>
            </div>
          </a-card>

          <!-- What the register is made of -->
          <a-card size="small" :title="$t('Rental_By_Type')" class="block">
            <div v-if="composition.length" class="breakdown">
              <button
                v-for="row in composition" :key="row.type" type="button" class="breakdown-row"
                @click="$router.push(`/rental/items?type=${row.type}`)"
              >
                <span class="breakdown-ic"><component :is="typeIcon(row.type)" :size="16" :stroke-width="1.8" /></span>
                <span class="breakdown-main">
                  <span class="breakdown-top">
                    <span>{{ labelOf(RENTAL_TYPES, row.type) }}</span>
                    <strong>{{ row.count }}</strong>
                  </span>
                  <a-progress
                    :percent="row.percent" :show-info="false" size="small"
                    stroke-color="var(--app-primary, #6d28d9)"
                  />
                </span>
              </button>
            </div>
            <a-empty v-else :image="simpleEmptyImage" :description="$t('Rental_No_Items_Yet')" />
          </a-card>

          <!-- Items off the market for repairs -->
          <a-card size="small" class="block">
            <template #title>
              <span class="card-title">
                <ToolOutlined :style="{ color: maintenanceList.length ? '#d97706' : undefined }" />
                {{ $t('Rental_In_Maintenance') }}
              </span>
            </template>
            <template #extra>
              <a-space :size="4">
                <a-tag v-if="(data.maintenance_jobs || {}).urgent" color="error">{{ $t('Rental_N_Urgent', { n: data.maintenance_jobs.urgent }) }}</a-tag>
                <a-tag v-if="(data.maintenance_jobs || {}).open" color="warning">{{ $t('Rental_N_Open_Jobs', { n: data.maintenance_jobs.open }) }}</a-tag>
                <router-link v-if="canMaintenance" to="/rental/maintenance">{{ $t('Rental_View_All') }}</router-link>
              </a-space>
            </template>
            <div v-if="maintenanceList.length" class="mlist">
              <button v-for="m in maintenanceList" :key="m.id" type="button" class="mrow" @click="open(m.id)">
                <span class="mrow-ic"><component :is="typeIcon(m.type)" :size="16" :stroke-width="1.8" /></span>
                <span class="mrow-body">
                  <span class="mrow-name">{{ m.name }}</span>
                  <span class="mrow-meta">{{ m.code }} · {{ labelOf(RENTAL_CONDITIONS, m.condition) }}</span>
                </span>
                <span class="mrow-date">{{ m.since ? date(m.since) : '' }}</span>
              </button>
            </div>
            <div v-else class="all-clear">
              <CheckCircleOutlined />
              <span>{{ $t('Rental_Nothing_In_Maintenance') }}</span>
            </div>
          </a-card>
        </a-col>
      </a-row>
    </a-spin>
  </div>
</template>

<script setup>
/**
 * Rental dashboard — one GET (rental/dashboard) feeding every panel, so the
 * numbers always agree. KPI tiles and legend entries navigate to the filtered
 * item list they count.
 */
import { ref, computed, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { message, Empty } from 'ant-design-vue';
import {
  PlusOutlined, UnorderedListOutlined, AppstoreOutlined, CheckCircleOutlined,
  ClockCircleOutlined, KeyOutlined, ToolOutlined, FieldTimeOutlined, WarningOutlined,
} from '@ant-design/icons-vue';
import ReportChart from '../../components/ReportChart.vue';
import { KeyRound } from 'lucide-vue-next';
import PageHeader from '../../components/PageHeader.vue';
import { useFormat } from '../../composables/useFormat';
import { useAuthStore } from '../../stores/auth';
import http from '../../lib/http';
import { t } from '../../i18n';
import {
  RENTAL_TYPES, RENTAL_STATUSES, RENTAL_CONDITIONS, CONTRACT_STAGES,
  labelOf, optionOf, typeIcon, perLabel,
} from './rentalOptions';

const router = useRouter();
const auth = useAuthStore();
const { money, date } = useFormat();
const simpleEmptyImage = Empty.PRESENTED_IMAGE_SIMPLE;

const canView = computed(() => auth.can('rnt_items_view'));
const canAdd = computed(() => auth.can('rnt_items_add'));
const canContracts = computed(() => auth.can('rnt_contracts_view'));
const canBilling = computed(() => auth.can('rnt_billing_view'));
const canMaintenance = computed(() => auth.can('rnt_maintenance'));
const bl = computed(() => data.value.billing || {});

const data = ref({});
const loading = ref(false);

const recent = computed(() => data.value.recent || []);
const maintenanceList = computed(() => data.value.in_maintenance || []);
const cf = computed(() => data.value.contracts || {});
const endingSoon = computed(() => cf.value.ending_soon || []);

const kpis = computed(() => [
  { key: 'total', status: '', tone: 'brand', icon: AppstoreOutlined, value: data.value.total || 0, label: t('Rental_Total_Items', 'Total items') },
  { key: 'available', status: 'available', tone: 'ok', icon: CheckCircleOutlined, value: data.value.available || 0, label: labelOf(RENTAL_STATUSES, 'available') },
  { key: 'rented', status: 'rented', tone: 'rented', icon: KeyOutlined, value: data.value.rented || 0, label: labelOf(RENTAL_STATUSES, 'rented') },
  { key: 'reserved', status: 'reserved', tone: 'info', icon: ClockCircleOutlined, value: data.value.reserved || 0, label: labelOf(RENTAL_STATUSES, 'reserved') },
  { key: 'maintenance', status: 'maintenance', tone: 'warn', icon: ToolOutlined, value: data.value.maintenance || 0, label: labelOf(RENTAL_STATUSES, 'maintenance') },
]);

// Fixed status colours (status colours stay constant whatever the app accent).
const STATUS_HEX = {
  available: '#16a34a',
  reserved: '#0284c7',
  rented: '#7c3aed',
  maintenance: '#d97706',
  inactive: '#94a3b8',
};

const statusSplit = computed(() => {
  const by = data.value.by_status || {};
  const total = Number(data.value.total || 0);
  if (!total) return [];
  return RENTAL_STATUSES
    .map(s => ({ value: s.value, label: labelOf(RENTAL_STATUSES, s.value), count: Number(by[s.value] || 0), hex: STATUS_HEX[s.value] }))
    .filter(s => s.count > 0)
    .map(s => ({ ...s, percent: (s.count / total) * 100 }));
});

const composition = computed(() => {
  const rows = data.value.by_type || [];
  const total = rows.reduce((sum, r) => sum + Number(r.count || 0), 0);
  if (!total) return [];
  return [...rows]
    .sort((a, b) => b.count - a.count)
    .map(r => ({ ...r, percent: Math.round((r.count / total) * 100) }));
});

function goList(status) {
  if (!canView.value) return;
  router.push(status ? `/rental/items?status=${status}` : '/rental/items');
}

function open(id) {
  if (canView.value) router.push(`/rental/items/${id}`);
}

async function load() {
  loading.value = true;
  try {
    data.value = await http.get('rental/dashboard');
  } catch (e) {
    message.error(t('Rental_Could_Not_Load_Dashboard', 'Could not load the rental dashboard'));
  } finally {
    loading.value = false;
  }
}

onMounted(load);
</script>

<style scoped>
.block {
  margin-bottom: 16px;
}

/* ---------------- KPI strip ---------------- */
.kpis {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
  gap: 12px;
  margin-bottom: 16px;
}
.kpi {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 14px 16px;
  border: 1px solid rgba(128, 128, 128, 0.2);
  border-radius: 14px;
  background: transparent;
  cursor: pointer;
  text-align: start;
  color: inherit;
  font: inherit;
  transition: border-color 0.15s ease, transform 0.12s ease;
}
.kpi:hover {
  border-color: rgba(var(--app-primary-rgb, 109, 40, 217), 0.5);
  transform: translateY(-1px);
}
.kpi-ic {
  width: 40px;
  height: 40px;
  flex: none;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 12px;
  font-size: 18px;
}
.kpi-ic--brand {
  color: var(--app-primary, #6d28d9);
  background: rgba(var(--app-primary-rgb, 109, 40, 217), 0.12);
}
.kpi-ic--ok {
  color: #16a34a;
  background: rgba(22, 163, 74, 0.12);
}
.kpi-ic--rented {
  color: #7c3aed;
  background: rgba(124, 58, 237, 0.12);
}
.kpi-ic--info {
  color: #0284c7;
  background: rgba(2, 132, 199, 0.12);
}
.kpi-ic--warn {
  color: #d97706;
  background: rgba(217, 119, 6, 0.14);
}
.kpi-text {
  display: flex;
  flex-direction: column;
  min-width: 0;
}
.kpi-value {
  font-size: 20px;
  font-weight: 600;
  line-height: 1.2;
}
.kpi-label {
  font-size: 12px;
  opacity: 0.6;
}

/* ---------------- contracts strip ---------------- */
.cstrip {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
  gap: 0;
  margin-bottom: 16px;
  border: 1px solid rgba(128, 128, 128, 0.2);
  border-radius: 14px;
  overflow: hidden;
}
.cs {
  display: flex;
  flex-direction: column;
  padding: 12px 16px;
  border: 0;
  border-inline-end: 1px solid rgba(128, 128, 128, 0.15);
  background: transparent;
  color: inherit;
  font: inherit;
  text-align: start;
  cursor: pointer;
}
.cs:last-child {
  border-inline-end: 0;
}
.cs:hover:not(.cs--money) {
  background: rgba(var(--app-primary-rgb, 109, 40, 217), 0.05);
}
.cs--money {
  cursor: default;
  background: rgba(var(--app-primary-rgb, 109, 40, 217), 0.06);
}
.cs-value {
  font-size: 20px;
  font-weight: 700;
  line-height: 1.2;
}
.cs--money .cs-value {
  color: var(--app-primary, #6d28d9);
}
.cs.warn .cs-value {
  color: #d97706;
}
.cs.bad .cs-value {
  color: #dc2626;
}
.cs-label {
  font-size: 12px;
  opacity: 0.6;
}

/* ---------------- money strip ---------------- */
.mstrip {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  gap: 12px;
  margin-bottom: 16px;
}
.ms {
  display: flex;
  flex-direction: column;
  padding: 14px 16px;
  border: 1px solid rgba(128, 128, 128, 0.2);
  border-radius: 14px;
  background: transparent;
  color: inherit;
  font: inherit;
  text-align: start;
}
button.ms {
  cursor: pointer;
}
button.ms:hover {
  border-color: rgba(var(--app-primary-rgb, 109, 40, 217), 0.5);
}
.ms-label {
  font-size: 12px;
  opacity: 0.65;
}
.ms-value {
  font-size: 21px;
  font-weight: 700;
}
.ms.bad .ms-value {
  color: #dc2626;
}
.ms.ok .ms-value {
  color: #16a34a;
}
.ms-note {
  font-size: 11.5px;
  opacity: 0.6;
}
.od-amt {
  color: #dc2626;
  white-space: nowrap;
}

/* ---------------- hero ---------------- */
.hero {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 24px;
  padding: 6px 4px 4px;
}
.hero-gauge {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 2px;
}
.hero-gauge-label {
  font-size: 12px;
  font-weight: 500;
  opacity: 0.65;
}
.hero-stats {
  flex: 1;
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 12px;
}
.hero-stat {
  padding: 14px 16px;
  border-radius: 12px;
  background: rgba(128, 128, 128, 0.07);
}
.hs-label {
  display: block;
  font-size: 12px;
  opacity: 0.65;
}
.hs-value {
  display: block;
  margin: 2px 0;
  font-size: 22px;
  font-weight: 700;
}
.hs-note {
  display: block;
  font-size: 11.5px;
  opacity: 0.5;
}
.split {
  margin-top: 18px;
}
.split-bar {
  display: flex;
  height: 10px;
  border-radius: 999px;
  overflow: hidden;
  background: rgba(128, 128, 128, 0.12);
}
.split-seg {
  height: 100%;
}
.split-legend {
  display: flex;
  flex-wrap: wrap;
  gap: 6px 16px;
  margin-top: 10px;
}
.legend-item {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 0;
  border: 0;
  background: none;
  color: inherit;
  font: inherit;
  font-size: 12.5px;
  cursor: pointer;
}
.legend-item strong {
  font-weight: 600;
}
.legend-dot {
  width: 9px;
  height: 9px;
  border-radius: 50%;
}

/* ---------------- recent ---------------- */
.recent {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(230px, 1fr));
  gap: 12px;
}
.recent-item {
  display: flex;
  gap: 12px;
  padding: 10px;
  border: 1px solid rgba(128, 128, 128, 0.18);
  border-radius: 12px;
  background: transparent;
  color: inherit;
  font: inherit;
  text-align: start;
  cursor: pointer;
  transition: border-color 0.15s ease;
}
.recent-item:hover {
  border-color: rgba(var(--app-primary-rgb, 109, 40, 217), 0.5);
}
.recent-media {
  width: 64px;
  height: 64px;
  flex: none;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 10px;
  overflow: hidden;
  background: rgba(128, 128, 128, 0.1);
  opacity: 0.9;
}
.recent-media img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}
.recent-body {
  min-width: 0;
  display: flex;
  flex-direction: column;
}
.recent-name {
  font-weight: 600;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.recent-meta {
  font-size: 12px;
  opacity: 0.55;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.recent-foot {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 4px;
  margin-top: auto;
}
.recent-price {
  font-size: 13px;
  font-weight: 600;
}
.recent-price small {
  font-weight: 400;
  opacity: 0.6;
}

/* ---------------- breakdown ---------------- */
.breakdown {
  display: flex;
  flex-direction: column;
  gap: 6px;
}
.breakdown-row {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 6px;
  border: 0;
  border-radius: 10px;
  background: none;
  color: inherit;
  font: inherit;
  text-align: start;
  cursor: pointer;
}
.breakdown-row:hover {
  background: rgba(128, 128, 128, 0.07);
}
.breakdown-ic,
.mrow-ic {
  width: 34px;
  height: 34px;
  flex: none;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 10px;
  color: var(--app-primary, #6d28d9);
  background: rgba(var(--app-primary-rgb, 109, 40, 217), 0.1);
}
.breakdown-main {
  flex: 1;
  min-width: 0;
}
.breakdown-top {
  display: flex;
  justify-content: space-between;
  font-size: 13px;
}
.breakdown-main :deep(.ant-progress) {
  margin: 0;
}

/* ---------------- maintenance ---------------- */
.card-title {
  display: inline-flex;
  align-items: center;
  gap: 8px;
}
.mlist {
  display: flex;
  flex-direction: column;
}
.mrow {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 8px 6px;
  border: 0;
  border-bottom: 1px solid rgba(128, 128, 128, 0.12);
  background: none;
  color: inherit;
  font: inherit;
  text-align: start;
  cursor: pointer;
}
.mrow:last-child {
  border-bottom: 0;
}
.mrow:hover .mrow-name {
  color: var(--app-primary, #6d28d9);
}
.mrow-ic {
  color: #d97706;
  background: rgba(217, 119, 6, 0.12);
}
.mrow-body {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
}
.mrow-name {
  font-weight: 500;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.mrow-meta,
.mrow-date {
  font-size: 12px;
  opacity: 0.55;
}
.all-clear {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 10px 4px;
  color: #16a34a;
}

/* ---------------- empty ---------------- */
.empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  text-align: center;
  padding: 28px 16px;
}
.empty-ic {
  width: 56px;
  height: 56px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 18px;
  margin-bottom: 10px;
  color: var(--app-primary, #6d28d9);
  background: rgba(var(--app-primary-rgb, 109, 40, 217), 0.1);
}
.empty p {
  max-width: 380px;
  opacity: 0.65;
}

@media (max-width: 575px) {
  .hero {
    justify-content: center;
  }
}
</style>
