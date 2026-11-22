<template>
  <div class="page">
    <PageHeader :title="$t('Oc_L_Title')" :breadcrumb="[$t('Int_Ecommerce_Platforms'), 'OpenCart', $t('Logs')]">
      <template #actions>
        <a-space wrap>
          <a-button :loading="loading" @click="refresh">
            <template #icon><ReloadOutlined /></template>{{ $t('Refresh') }}
          </a-button>
          <a-tooltip :title="$t('Oc_L_Export_Help')">
            <a-button :loading="exporting" :disabled="!logs.total" @click="exportCsv">
              <template #icon><DownloadOutlined /></template>{{ $t('Oc_L_Export') }}
            </a-button>
          </a-tooltip>
          <a-popconfirm :title="$t('Int_Clear_All_Logs_Confirm', { name: 'OpenCart' })" :ok-text="$t('Yes')" :cancel-text="$t('No')" @confirm="clearLogs">
            <a-button danger :disabled="!summary.total">
              <template #icon><DeleteOutlined /></template>{{ $t('Clear_Logs') }}
            </a-button>
          </a-popconfirm>
          <router-link to="/integrations/opencart">
            <a-button type="primary"><template #icon><SettingOutlined /></template>{{ $t('Settings') }}</a-button>
          </router-link>
        </a-space>
      </template>
    </PageHeader>

    <p class="muted intro">{{ $t('Oc_L_Intro') }}</p>

    <!-- ============================ Summary ============================ -->
    <a-row :gutter="[12, 12]" style="margin-bottom: 16px">
      <a-col :xs="12" :md="6">
        <div class="tile" @click="setLevel(undefined)">
          <div class="tile-n">{{ summary.total || 0 }}</div>
          <div class="muted small">{{ $t('Oc_L_Entries') }}</div>
        </div>
      </a-col>
      <a-col :xs="12" :md="6">
        <div class="tile tile--error" :class="{ 'tile--on': filters.level === 'error' }" @click="setLevel('error')">
          <div class="tile-n">{{ summary.levels.error || 0 }}</div>
          <div class="muted small">{{ $t('Oc_L_Errors') }} · {{ $t('Oc_L_Last_24h', { n: summary.last_24h.error || 0 }) }}</div>
        </div>
      </a-col>
      <a-col :xs="12" :md="6">
        <div class="tile tile--warn" :class="{ 'tile--on': filters.level === 'warning' }" @click="setLevel('warning')">
          <div class="tile-n">{{ summary.levels.warning || 0 }}</div>
          <div class="muted small">{{ $t('Oc_L_Warnings') }} · {{ $t('Oc_L_Last_24h', { n: summary.last_24h.warning || 0 }) }}</div>
        </div>
      </a-col>
      <a-col :xs="12" :md="6">
        <div class="tile tile--info">
          <div class="small"><strong>{{ $t('Oc_L_Last_Pull') }}</strong></div>
          <template v-if="summary.last_pull">
            <div class="small">{{ summary.last_pull.message }}</div>
            <div v-if="lastPullTotal !== null" class="small">{{ $t('Oc_L_Store_Total', { n: lastPullTotal }) }}</div>
            <div class="muted small">{{ dateTime(summary.last_pull.created_at) }}</div>
          </template>
          <div v-else class="muted small">{{ $t('Oc_L_None') }}</div>
        </div>
      </a-col>
    </a-row>

    <a-alert
      v-if="summary.last_error" type="error" show-icon style="margin-bottom: 16px"
      :message="$t('Oc_L_Last_Error') + ' · ' + dateTime(summary.last_error.created_at)"
    >
      <template #description>
        <div>{{ summary.last_error.message }}</div>
        <div v-for="h in explain(summary.last_error)" :key="h.key" class="small" style="margin-top: 4px">
          <span>{{ h.hint }}</span> <strong v-if="h.fix">{{ h.fix }}</strong>
        </div>
      </template>
    </a-alert>

    <!-- ============================ Filters ============================ -->
    <a-card size="small" style="margin-bottom: 16px">
      <div class="toolbar">
        <a-input-search v-model:value="filters.q" :placeholder="$t('Oc_L_Search')" allow-clear class="tb-search" @search="reload" />
        <a-select v-model:value="filters.level" class="tb-small" allow-clear :placeholder="$t('Oc_L_All_Levels')" :options="levelOptions" @change="reload" />
        <a-select v-model:value="filters.action" class="tb-item" allow-clear :placeholder="$t('Oc_L_All_Actions')" :options="actionOptions" @change="reload" />
        <a-range-picker v-model:value="filters.range" class="tb-range" value-format="YYYY-MM-DD" @change="reload" />
      </div>
      <div class="muted small" style="margin-top: 8px">{{ $t('Oc_L_Open_Row') }}</div>
    </a-card>

    <!-- ============================ List ============================ -->
    <a-table
      size="small" row-key="id" :loading="loading" :data-source="logs.data" :columns="columns"
      :pagination="{ current: logs.current_page, pageSize: logs.per_page, total: logs.total, showSizeChanger: true, pageSizeOptions: ['25', '50', '100'] }"
      :scroll="{ x: 900 }" :expand-row-by-click="true" :row-class-name="r => 'row--' + r.level"
      @change="onTableChange"
    >
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'created_at'">{{ dateTime(record.created_at) }}</template>
        <template v-else-if="column.key === 'level'">
          <a-tag :color="levelColor(record.level)">{{ levelLabel(record.level) }}</a-tag>
        </template>
        <template v-else-if="column.key === 'action'">{{ actionLabel(record.action) }}</template>
        <template v-else-if="column.key === 'message'">
          <div class="msg">{{ record.message }}</div>
          <div v-if="explain(record)[0]" class="muted small one-line">{{ explain(record)[0].hint }}</div>
        </template>
      </template>

      <template #expandedRowRender="{ record }">
        <div class="detail">
          <div v-for="h in explain(record)" :key="h.key" class="explain">
            <div><strong>{{ $t('Oc_L_What_It_Means') }}</strong> {{ h.hint }}</div>
            <div v-if="h.fix"><strong>{{ $t('Oc_L_What_To_Do') }}</strong> {{ h.fix }}</div>
          </div>

          <template v-if="details(record).values.length || details(record).lists.length">
            <div class="detail-title">{{ $t('Oc_L_Details') }}</div>
            <a-descriptions v-if="details(record).values.length" size="small" :column="{ xs: 1, md: 2 }" bordered style="margin-bottom: 8px">
              <a-descriptions-item v-for="v in details(record).values" :key="v.label" :label="v.label">{{ v.value }}</a-descriptions-item>
            </a-descriptions>
            <div v-for="l in details(record).lists" :key="l.key" class="detail-list">
              <div class="small"><strong>{{ l.label }}</strong> ({{ l.total }})</div>
              <ul>
                <li v-for="(item, i) in l.items" :key="i">{{ item }}</li>
                <li v-if="l.total > l.items.length" class="muted">+{{ l.total - l.items.length }}</li>
              </ul>
            </div>
          </template>

          <a-collapse v-if="record.context && Object.keys(record.context).length" ghost>
            <a-collapse-panel key="raw" :header="$t('Oc_L_Raw')">
              <pre class="raw">{{ JSON.stringify(record.context, null, 2) }}</pre>
            </a-collapse-panel>
          </a-collapse>

          <div v-if="record.level === 'error'" class="muted small">{{ $t('Oc_L_Support') }}</div>
        </div>
      </template>
    </a-table>
  </div>
</template>

<script setup>
/**
 * OpenCart › Logs. Reads GET opencart/logs (level, action, q, from, to,
 * page, per_page), GET opencart/logs/summary for the tiles, GET
 * opencart/logs/export for a CSV of the same filters, DELETE opencart/logs.
 *
 * Each row is explained in plain words by explain(): the server writes the
 * technical message (store errors, counts), this page matches it to a
 * meaning and a fix (keys Oc_L_H_* / Oc_L_F_*). A new log message in the
 * services needs a case here to get an explanation; without one an error
 * still gets the generic hint.
 */
import { ref, computed, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { DownloadOutlined, DeleteOutlined, ReloadOutlined, SettingOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import { useFormat } from '../../composables/useFormat';
import http from '../../lib/http';

const { t, te } = useI18n();
const { dateTime } = useFormat();
const route = useRoute();

const LIST_CAP = 50;

const loading = ref(false);
const exporting = ref(false);
const summary = ref({ total: 0, levels: {}, last_24h: {}, actions: [], last_error: null, last_pull: null });
const logs = ref({ data: [], current_page: 1, per_page: 25, total: 0 });
const filters = ref({
  q: '',
  level: ['error', 'warning', 'info'].includes(route.query.level) ? route.query.level : undefined,
  action: route.query.action || undefined,
  range: null,
});

const columns = computed(() => [
  { title: t('Oc_L_Col_Date'), key: 'created_at', width: 170 },
  { title: t('Oc_L_Col_Level'), key: 'level', width: 100 },
  { title: t('Oc_L_Col_Action'), key: 'action', width: 190 },
  { title: t('Oc_L_Col_Message'), key: 'message' },
]);

const levelOptions = computed(() => [
  { value: 'error', label: t('Cr_Log_Error') },
  { value: 'warning', label: t('Warning') },
  { value: 'info', label: t('Info') },
]);

const actionOptions = computed(() => (summary.value.actions || []).map(a => ({
  value: a.action,
  label: actionLabel(a.action) + ' (' + a.count + ')',
})));

const lastPullTotal = computed(() => {
  const ctx = summary.value.last_pull?.context;
  return ctx && ctx.store_total !== undefined && ctx.store_total !== null ? ctx.store_total : null;
});

// ------------------------------------------------------------ labels --

function has(key) {
  return te(key) || te(key, 'en');
}

function actionLabel(action) {
  const key = 'Oc_L_Act_' + String(action || '').replace(/[^a-z0-9]+/gi, '_');
  return has(key) ? t(key) : action;
}

function levelLabel(level) {
  return level === 'error' ? t('Cr_Log_Error') : level === 'warning' ? t('Warning') : t('Info');
}

function levelColor(level) {
  return level === 'error' ? 'error' : level === 'warning' ? 'warning' : 'default';
}

// ------------------------------------------------------- explanation --

function asList(value) {
  if (Array.isArray(value)) return value;
  if (value && typeof value === 'object') return Object.values(value);
  return [];
}

function failedCount(text) {
  const m = String(text || '').match(/(\d+) failed/);
  return m ? parseInt(m[1], 10) : 0;
}

/** Plain-words meaning and fix for a log row; several can apply. */
function explain(record) {
  if (!record) return [];
  const msg = String(record.message || '');
  const ctx = record.context || {};
  const blob = msg + ' ' + JSON.stringify(ctx);
  const out = [];
  const add = (key, withFix = true) => {
    if (out.some(h => h.key === key)) return;
    out.push({ key, hint: t('Oc_L_H_' + key), fix: withFix ? t('Oc_L_F_' + key) : '' });
  };

  // Connection problems, whatever step met them.
  if (/secret does not match/i.test(blob)) add('Secret');
  else if (/clock is too far/i.test(blob)) add('Clock');
  else if (/module is disabled/i.test(blob)) add('Disabled');
  else if (/redirects to/i.test(blob)) add('Redirect');
  else if (/not configured/i.test(blob)) add('Not_Configured');
  else if (/could not reach the store|did not answer at this address|not responding|timed out|curl error/i.test(blob)) add('Unreachable');

  // Product problems reported by the store.
  if (/already used by/i.test(blob)) add('Model_Taken');
  if (/model and name are required|variant model is required/i.test(blob)) add('Model_Missing');

  switch (record.action) {
    case 'orders.import':
      if (/held/i.test(msg)) add('Held');
      break;
    case 'orders.retry':
      add('Given_Up');
      break;
    case 'orders.review':
      add('Review');
      break;
    case 'orders.reverse':
      add('Reversed', false);
      break;
    case 'orders.catch_up':
      if (/stopped after/i.test(msg)) add('Backlog');
      break;
    case 'auto_sync':
      if (/paused/i.test(msg)) add('Paused');
      else if (/busy/i.test(msg)) add('Busy', false);
      break;
    case 'products.pull':
      if (ctx.connector_codes === false && ctx.code_source && !['model', 'sku'].includes(ctx.code_source)) add('Old_Connector');
      else if (ctx.code_fallback_count > 0) add('Code_Fallback');
      if (ctx.codes_fixed_count > 0) add('Codes_Fixed', false);
      if (asList(ctx.codes_suffixed).length) add('Codes_Suffixed');
      if (failedCount(msg) > 0) add('Pull_Failed');
      if (asList(ctx.options_without_variants).length) add('Options');
      if (!out.length) add('Pull_Ok', false);
      break;
    case 'products.push':
      if (asList(ctx.warnings).length || asList(ctx.errors).length) add('Push_Warnings');
      break;
    case 'inventory.push':
      if (/[1-9]\d* no longer in the store/.test(msg)) add('Missing_Remote');
      break;
    default:
      break;
  }

  if (!out.length && record.level === 'error') add('Generic');
  return out;
}

// ------------------------------------------------------------ details --

const DETAIL_VALUES = [
  ['code_source', 'Oc_L_D_Code_Source'],
  ['code_fallback_count', 'Oc_L_D_Code_Fallback_Count'],
  ['codes_seen', 'Oc_L_D_Codes_Seen'],
  ['store_total', 'Oc_L_D_Store_Total'],
  ['per_page', 'Oc_L_D_Per_Page'],
  ['opencart_order_id', 'Oc_L_D_Order'],
  ['sale_id', 'Oc_L_D_Sale'],
];
const DETAIL_LISTS = [
  ['codes_fixed', 'Oc_L_D_Codes_Fixed'],
  ['code_fallback', 'Oc_L_D_Code_Fallback'],
  ['errors', 'Oc_L_D_Errors'],
  ['warnings', 'Oc_L_D_Warnings'],
  ['codes_suffixed', 'Oc_L_D_Codes_Suffixed'],
  ['options_without_variants', 'Oc_L_D_Options'],
  ['unmatched', 'Oc_L_D_Unmatched'],
  ['product_ids', 'Oc_L_D_Product_Ids'],
  ['stock_product_ids', 'Oc_L_D_Stock_Ids'],
];

function itemText(item) {
  if (item === null || item === undefined) return '';
  if (typeof item !== 'object') return String(item);
  const parts = [];
  for (const [key, value] of Object.entries(item)) {
    if (value === null || value === '' || value === undefined) continue;
    const label = key === 'error' ? t('Oc_L_D_Error') : key.replace(/_/g, ' ');
    parts.push(label + ': ' + (typeof value === 'object' ? JSON.stringify(value) : value));
  }
  return parts.join(' · ');
}

/** The known context keys as labelled values and lists. */
function details(record) {
  const ctx = record?.context || {};
  const values = DETAIL_VALUES
    .filter(([key]) => ctx[key] !== undefined && ctx[key] !== null && ctx[key] !== '')
    .map(([key, label]) => ({
      label: t(label),
      value: key === 'code_source' ? String(ctx[key]).toUpperCase()
        : ctx[key] && typeof ctx[key] === 'object' ? Object.entries(ctx[key]).map(([k, v]) => k.toUpperCase() + ': ' + v).join(' · ')
          : ctx[key],
    }));
  const lists = DETAIL_LISTS
    .map(([key, label]) => {
      const all = asList(ctx[key]);
      return { key, label: t(label), total: all.length, items: all.slice(0, LIST_CAP).map(itemText).filter(Boolean) };
    })
    .filter(l => l.total > 0);
  return { values, lists };
}

// ------------------------------------------------------------ loading --

function query(page, perPage) {
  const p = new URLSearchParams();
  if (page) {
    p.set('page', String(page));
    p.set('per_page', String(perPage || logs.value.per_page || 25));
  }
  const f = filters.value;
  if (f.q && f.q.trim()) p.set('q', f.q.trim());
  if (f.level) p.set('level', f.level);
  if (f.action) p.set('action', f.action);
  if (f.range && f.range[0]) p.set('from', f.range[0]);
  if (f.range && f.range[1]) p.set('to', f.range[1]);
  return p.toString();
}

async function loadLogs(page = 1, perPage) {
  loading.value = true;
  try {
    logs.value = await http.get('opencart/logs?' + query(page, perPage));
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    loading.value = false;
  }
}

async function loadSummary() {
  try {
    summary.value = { ...summary.value, ...(await http.get('opencart/logs/summary')) };
  } catch (e) { /* tiles stay at zero */ }
}

function reload() {
  loadLogs(1);
}

function refresh() {
  loadSummary();
  loadLogs(logs.value.current_page || 1);
}

function setLevel(level) {
  filters.value.level = level;
  reload();
}

function onTableChange(pagination) {
  loadLogs(pagination.current, pagination.pageSize);
}

async function exportCsv() {
  exporting.value = true;
  try {
    const stamp = new Date().toISOString().slice(0, 19).replace(/[T:]/g, '-');
    await http.download('opencart/logs/export?' + query(), 'opencart-logs-' + stamp + '.csv');
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    exporting.value = false;
  }
}

async function clearLogs() {
  try {
    await http.delete('opencart/logs');
    await loadSummary();
    await loadLogs(1);
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  }
}

onMounted(() => {
  loadSummary();
  loadLogs(1);
});
</script>

<style scoped>
.muted {
  opacity: 0.65;
}
.small {
  font-size: 12px;
}
.intro {
  max-width: 860px;
  margin: 0 0 16px;
}
.tile {
  height: 100%;
  border: 1px solid rgba(128, 128, 128, 0.2);
  border-radius: 10px;
  padding: 10px 14px;
  cursor: pointer;
  transition: border-color 0.15s;
}
.tile--info {
  cursor: default;
}
.tile--on,
.tile:not(.tile--info):hover {
  border-color: rgba(22, 119, 255, 0.6);
}
.tile-n {
  font-size: 22px;
  font-weight: 600;
  line-height: 1.3;
}
.tile--error .tile-n {
  color: #cf1322;
}
.tile--warn .tile-n {
  color: #d48806;
}
.toolbar {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}
.tb-search {
  flex: 1 1 260px;
  min-width: 200px;
}
.tb-small {
  width: 150px;
}
.tb-item {
  width: 230px;
}
.tb-range {
  width: 260px;
}
.msg {
  white-space: pre-wrap;
  word-break: break-word;
}
.one-line {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  max-width: 640px;
}
.detail {
  padding: 4px 8px;
}
.explain {
  margin-bottom: 10px;
  padding: 8px 12px;
  border-left: 3px solid rgba(22, 119, 255, 0.5);
  background: rgba(22, 119, 255, 0.05);
  border-radius: 4px;
}
.explain > div + div {
  margin-top: 4px;
}
.detail-title {
  font-weight: 600;
  margin: 6px 0;
}
.detail-list ul {
  margin: 4px 0 10px;
  padding-left: 20px;
  max-height: 220px;
  overflow: auto;
}
.raw {
  max-height: 300px;
  overflow: auto;
  font-size: 12px;
  margin: 0;
  white-space: pre-wrap;
  word-break: break-word;
}
:deep(.row--error) td {
  background: rgba(255, 77, 79, 0.06);
}
@media (max-width: 640px) {
  .tb-small,
  .tb-item,
  .tb-range {
    width: 100%;
  }
}
</style>
