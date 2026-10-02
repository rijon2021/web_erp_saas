<template>
  <div class="page">
    <PageHeader :title="$t('Reconciliation')" :breadcrumb="[$t('Accounting'), $t('Reconciliation')]">
      <template #extra>
        <a-button :loading="isLoading" @click="fetch">
          <template #icon><ReloadOutlined /></template>
          {{ $t('Refresh') }}
        </a-button>
      </template>
    </PageHeader>

    <a-alert type="info" show-icon :message="$t('Recon_Intro')" style="margin-bottom: 16px" />
    <a-alert v-if="loaded && !autoJournals" type="warning" show-icon :message="$t('Recon_Auto_Off')" style="margin-bottom: 16px" />
    <a-alert v-if="error" type="error" show-icon :message="error" style="margin-bottom: 16px" />

    <a-row :gutter="[16, 16]" style="margin-bottom: 16px">
      <a-col :xs="12" :md="6">
        <a-card size="small"><a-statistic :title="$t('Recon_Operational_Balance')" :value="number(totals.operational_balance)" /></a-card>
      </a-col>
      <a-col :xs="12" :md="6">
        <a-card size="small"><a-statistic :title="$t('Recon_GL_Balance')" :value="number(totals.gl_balance)" /></a-card>
      </a-col>
      <a-col :xs="12" :md="6">
        <a-card size="small">
          <a-statistic :title="$t('Recon_Difference')" :value="number(totals.difference)" :value-style="{ color: isZero(totals.difference) ? '#3f8600' : '#cf1322' }" />
        </a-card>
      </a-col>
      <a-col :xs="12" :md="6">
        <a-card size="small"><a-statistic :title="$t('Recon_Unreconciled')" :value="totals.unreconciled" /></a-card>
      </a-col>
    </a-row>

    <a-alert
      v-if="!isZero(unassignedCash)"
      type="warning"
      show-icon
      :message="$t('Recon_Unassigned_Cash', { amount: number(unassignedCash) })"
      style="margin-bottom: 16px"
    />

    <a-card size="small">
      <a-table :columns="columns" :data-source="rows" :loading="isLoading" :pagination="false" row-key="account_id" size="middle" :scroll="{ x: 720 }">
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'account'">
            <div><strong>{{ record.account_name }}</strong></div>
            <div class="muted">{{ record.account_num }}</div>
          </template>
          <template v-else-if="column.key === 'operational_balance'">{{ number(record.operational_balance) }}</template>
          <template v-else-if="column.key === 'gl_balance'">
            <span v-if="record.coa_id">{{ number(record.gl_balance) }}</span>
            <a-tooltip v-else :title="$t('Recon_Not_Linked')"><span class="muted">—</span></a-tooltip>
          </template>
          <template v-else-if="column.key === 'difference'">
            <span :style="{ color: record.reconciled ? '#3f8600' : '#cf1322', fontWeight: 600 }">{{ number(record.difference) }}</span>
          </template>
          <template v-else-if="column.key === 'status'">
            <a-tag :color="record.reconciled ? 'green' : 'red'">{{ record.reconciled ? $t('Recon_Reconciled') : $t('Recon_Out_Of_Balance') }}</a-tag>
          </template>
          <template v-else-if="column.key === 'action'">
            <a-button size="small" @click="openDetail(record)">{{ $t('Recon_Details') }}</a-button>
          </template>
        </template>
      </a-table>
    </a-card>

    <a-drawer v-model:open="drawer.open" :title="drawerTitle" :width="drawerWidth" destroy-on-close>
      <a-spin :spinning="drawer.loading">
        <a-row :gutter="[12, 12]" style="margin-bottom: 16px">
          <a-col :xs="24" :md="8"><a-statistic :title="$t('Recon_Operational_Balance')" :value="number(detail.summary.operational_balance)" /></a-col>
          <a-col :xs="24" :md="8"><a-statistic :title="$t('Recon_GL_Balance')" :value="number(detail.summary.gl_balance)" /></a-col>
          <a-col :xs="24" :md="8">
            <a-statistic :title="$t('Recon_Difference')" :value="number(detail.summary.difference)" :value-style="{ color: isZero(detail.summary.difference) ? '#3f8600' : '#cf1322' }" />
          </a-col>
        </a-row>

        <a-card size="small" :title="$t('Recon_Explained_By')" style="margin-bottom: 16px">
          <div v-for="item in explanation" :key="item.key" class="line">
            <span>
              {{ item.label }}
              <a-tooltip v-if="item.help" :title="item.help"><QuestionCircleOutlined class="muted" /></a-tooltip>
            </span>
            <span :class="{ muted: isZero(item.value) }">{{ number(item.value) }}</span>
          </div>
          <a-divider style="margin: 8px 0" />
          <div class="line">
            <strong>{{ $t('Recon_Unexplained') }}</strong>
            <strong :style="{ color: isZero(detail.summary.unexplained) ? '#3f8600' : '#cf1322' }">{{ number(detail.summary.unexplained) }}</strong>
          </div>
        </a-card>

        <div class="toolbar">
          <a-segmented v-model:value="drawer.status" :options="statusOptions" @change="reloadDetail" />
          <a-input-search v-model:value="drawer.search" :placeholder="$t('Search')" allow-clear style="max-width: 240px" @search="reloadDetail" />
        </div>

        <a-table
          :columns="detailColumns"
          :data-source="detail.data"
          :pagination="{ current: drawer.page, pageSize: drawer.limit, total: detail.totalRows, showSizeChanger: true }"
          :row-key="(r, i) => r.type + r.reference + i"
          size="small"
          :scroll="{ x: 860 }"
          @change="onDetailTableChange"
        >
          <template #bodyCell="{ column, record }">
            <template v-if="column.key === 'type'">{{ typeLabel(record.type) }}</template>
            <template v-else-if="column.key === 'expected'">{{ number(record.expected) }}</template>
            <template v-else-if="column.key === 'gl'">{{ number(record.gl) }}</template>
            <template v-else-if="column.key === 'difference'">
              <span :style="{ color: isZero(record.difference) ? undefined : '#cf1322' }">{{ number(record.difference) }}</span>
            </template>
            <template v-else-if="column.key === 'status'">
              <a-tooltip :title="statusHelp[record.status]">
                <a-tag :color="statusColor[record.status]">{{ statusLabel[record.status] }}</a-tag>
              </a-tooltip>
            </template>
          </template>
        </a-table>
      </a-spin>
    </a-drawer>
  </div>
</template>

<script setup>
/**
 * Reconciliation (v2) — operational cash/bank balances vs the General Ledger.
 *
 * GET accounting/v2/reports/reconciliation → one row per account
 * (operational_balance, gl_balance, difference) + unassigned_cash_gl.
 * GET accounting/v2/reports/reconciliation/{id}?status&search&page&limit →
 * summary with the items that explain the difference, status counts, and the
 * account's movements matched to their journals. Strictly read-only.
 */
import { ref, reactive, computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { ReloadOutlined, QuestionCircleOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../../components/PageHeader.vue';
import { useFormat } from '../../../composables/useFormat';
import http from '../../../lib/http';

const { t } = useI18n();
const { number } = useFormat();

const isZero = v => Math.abs(Number(v || 0)) < 0.005;

const isLoading = ref(false);
const loaded = ref(false);
const error = ref(null);
const rows = ref([]);
const totals = ref({ operational_balance: 0, gl_balance: 0, difference: 0, unreconciled: 0 });
const unassignedCash = ref(0);
const autoJournals = ref(true);

const columns = computed(() => [
  { title: t('Account'), key: 'account' },
  { title: t('Recon_Operational_Balance'), key: 'operational_balance', align: 'right' },
  { title: t('Recon_GL_Balance'), key: 'gl_balance', align: 'right' },
  { title: t('Recon_Difference'), key: 'difference', align: 'right' },
  { title: t('Status'), key: 'status', align: 'center' },
  { title: '', key: 'action', align: 'right', width: 110 },
]);

async function fetch() {
  isLoading.value = true;
  error.value = null;
  try {
    const r = await http.get('accounting/v2/reports/reconciliation');
    rows.value = (r && r.data) || [];
    totals.value = (r && r.totals) || totals.value;
    unassignedCash.value = Number((r && r.unassigned_cash_gl) || 0);
    autoJournals.value = !!(r && r.auto_journals);
    loaded.value = true;
  } catch (e) {
    error.value = (e && e.message) || t('Recon_Load_Failed');
  } finally {
    isLoading.value = false;
  }
}

// ---- Per-account drawer -------------------------------------------------

const drawer = reactive({ open: false, loading: false, account: null, status: 'issues', search: '', page: 1, limit: 25 });
const emptyDetail = () => ({
  summary: { operational_balance: 0, gl_balance: 0, difference: 0, unexplained: 0, items: {} },
  counts: {},
  data: [],
  totalRows: 0,
});
const detail = ref(emptyDetail());

const drawerTitle = computed(() => (drawer.account ? `${t('Reconciliation')} — ${drawer.account.account_name}` : t('Reconciliation')));
const drawerWidth = computed(() => (typeof window !== 'undefined' && window.innerWidth < 1000 ? '100%' : 980));

const statusLabel = computed(() => ({
  matched: t('Recon_Matched'),
  missing: t('Recon_Missing'),
  mismatch: t('Recon_Mismatch'),
  not_journaled: t('Recon_Not_Journaled'),
  ledger_only: t('Recon_Ledger_Only'),
}));
const statusHelp = computed(() => ({
  matched: t('Recon_Help_Matched'),
  missing: t('Recon_Help_Missing'),
  mismatch: t('Recon_Help_Mismatch'),
  not_journaled: t('Recon_Help_Not_Journaled'),
  ledger_only: t('Recon_Help_Ledger_Only'),
}));
const statusColor = { matched: 'green', missing: 'red', mismatch: 'orange', not_journaled: 'default', ledger_only: 'purple' };

const statusOptions = computed(() => {
  const c = detail.value.counts || {};
  const issues = (c.missing || 0) + (c.mismatch || 0) + (c.not_journaled || 0) + (c.ledger_only || 0);
  return [
    { value: 'issues', label: `${t('Recon_Issues')} (${issues})` },
    { value: 'missing', label: `${statusLabel.value.missing} (${c.missing || 0})` },
    { value: 'mismatch', label: `${statusLabel.value.mismatch} (${c.mismatch || 0})` },
    { value: 'ledger_only', label: `${statusLabel.value.ledger_only} (${c.ledger_only || 0})` },
    { value: 'not_journaled', label: `${statusLabel.value.not_journaled} (${c.not_journaled || 0})` },
    { value: 'all', label: `${t('Recon_All')} (${issues + (c.matched || 0)})` },
  ];
});

const explanation = computed(() => {
  const i = detail.value.summary.items || {};
  return [
    { key: 'opening', label: t('Recon_Opening_Balance'), help: t('Recon_Help_Opening'), value: i.opening_balance },
    { key: 'drift', label: t('Recon_Balance_Drift'), help: t('Recon_Help_Drift'), value: i.balance_drift },
    { key: 'missing', label: t('Recon_Missing'), help: t('Recon_Help_Missing'), value: i.missing },
    { key: 'mismatch', label: t('Recon_Mismatch'), help: t('Recon_Help_Mismatch'), value: i.mismatch },
    { key: 'not_journaled', label: t('Recon_Not_Journaled'), help: t('Recon_Help_Not_Journaled'), value: i.not_journaled },
    { key: 'ledger_only', label: t('Recon_Ledger_Only'), help: t('Recon_Help_Ledger_Only'), value: i.ledger_only },
  ];
});

const detailColumns = computed(() => [
  { title: t('date'), dataIndex: 'date', key: 'date', width: 110 },
  { title: t('Type'), key: 'type', width: 170 },
  { title: t('Reference'), dataIndex: 'reference', key: 'reference', width: 150 },
  { title: t('Description'), dataIndex: 'description', key: 'description', ellipsis: true },
  { title: t('Recon_Expected'), key: 'expected', align: 'right', width: 120 },
  { title: t('Recon_Ledger_Amount'), key: 'gl', align: 'right', width: 120 },
  { title: t('Recon_Difference'), key: 'difference', align: 'right', width: 110 },
  { title: t('Status'), key: 'status', align: 'center', width: 150 },
]);

// Movement types come from the account statement; journal-only rows carry
// the journal reference_type (payment_sale, expense, ...) or manual_journal.
function typeLabel(type) {
  const key = 'Recon_Type_' + type;
  const label = t(key);
  return label === key ? String(type).replace(/_/g, ' ') : label;
}

function openDetail(record) {
  drawer.account = record;
  drawer.status = 'issues';
  drawer.search = '';
  drawer.page = 1;
  detail.value = emptyDetail();
  drawer.open = true;
  loadDetail();
}

function reloadDetail() {
  drawer.page = 1;
  loadDetail();
}

function onDetailTableChange(pagination) {
  drawer.page = pagination.current;
  drawer.limit = pagination.pageSize;
  loadDetail();
}

async function loadDetail() {
  if (!drawer.account) return;
  drawer.loading = true;
  try {
    const r = await http.get(`accounting/v2/reports/reconciliation/${drawer.account.account_id}`, {
      status: drawer.status,
      search: drawer.search || '',
      page: drawer.page,
      limit: drawer.limit,
    });
    detail.value = { ...emptyDetail(), ...(r || {}) };
  } catch (e) {
    error.value = (e && e.message) || t('Recon_Load_Failed');
  } finally {
    drawer.loading = false;
  }
}

onMounted(fetch);
</script>

<style scoped>
.muted {
  color: rgba(var(--ink-rgb), 0.55);
  font-size: 12px;
}
.line {
  display: flex;
  justify-content: space-between;
  gap: 16px;
  padding: 4px 0;
}
.toolbar {
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 12px;
}
</style>
