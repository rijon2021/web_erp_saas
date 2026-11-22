<template>
  <div class="page">
    <PageHeader :title="$t('Audit_Log')" :breadcrumb="[$t('Settings'), $t('Audit_Log')]">
      <template #actions>
        <a-space>
          <a-button :loading="exporting" :disabled="!ready" @click="exportRows"><FileExcelOutlined /> {{ $t('Export_Excel') }}</a-button>
          <a-button :loading="crud.loading.value" @click="crud.fetchRows()"><ReloadOutlined /> {{ $t('Refresh') }}</a-button>
        </a-space>
      </template>
    </PageHeader>

    <a-alert v-if="!ready" type="info" show-icon :message="$t('Al_Not_Ready')" style="margin-bottom: 16px" />

    <!-- Per-event summary (across the current filters, not just this page) -->
    <a-row :gutter="[16, 16]" style="margin-bottom: 16px">
      <a-col v-for="card in eventCards" :key="card.key" :xs="12" :sm="8" :xl="4">
        <a-card :bordered="false" class="kpi-card" :class="{ 'kpi-card--active': filters.event === card.event }" hoverable @click="setEvent(card.event)">
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
        <a-input-search v-model:value="crud.search.value" :placeholder="$t('Al_Search')" allow-clear class="tb-search" @search="crud.reload" />
        <a-select v-model:value="filters.event" class="tb-small" allow-clear :placeholder="$t('Event')" :options="eventOptions" @change="crud.reload" />
        <a-select v-model:value="filters.subject_type" class="tb-item" allow-clear show-search option-filter-prop="label"
          :placeholder="$t('Al_Record_Type')" :options="typeOptions" @change="onTypeChange" />
        <a-select v-model:value="filters.user_id" class="tb-item" allow-clear show-search option-filter-prop="label"
          :placeholder="$t('User')" :options="userOptions" @change="crud.reload" />
        <a-range-picker v-model:value="filters.range" class="tb-range" value-format="YYYY-MM-DD" @change="crud.reload" />
      </div>
      <div v-if="filters.subject_id || filters.batch" class="active-filters">
        <a-tag v-if="filters.subject_id" closable color="purple" @close="clearRecord">
          {{ $t('Al_Filter_Record', { name: recordFilterName }) }}
        </a-tag>
        <a-tag v-if="filters.batch" closable color="blue" @close="clearBatch">{{ $t('Al_Filter_Request') }}</a-tag>
      </div>
      <div class="muted hint">{{ $t('Al_Help') }}</div>
    </a-card>

    <DataTable :crud="crud" :columns="columns" :searchable="false" row-key="id">
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'created_at'">{{ dateTime(record.created_at) }}</template>
        <template v-else-if="column.key === 'user_name'">
          <span v-if="record.user_name">{{ record.user_name }}</span>
          <span v-else class="muted">{{ $t('Al_System') }}</span>
          <!-- Changed through the REST API with a Settings > API key. -->
          <a-tag v-if="record.channel === 'api'" color="geekblue" style="margin-left: 6px">API</a-tag>
          <div v-if="record.impersonator" class="small admin">{{ $t('Al_Via_Admin', { name: record.impersonator }) }}</div>
        </template>
        <template v-else-if="column.key === 'event'">
          <a-tag :color="eventColor(record.event)">{{ eventLabel(record.event) }}</a-tag>
        </template>
        <template v-else-if="column.key === 'subject'">
          <template v-if="record.subject_type">
            <div class="muted small">{{ typeLabel(record.subject_type) }}</div>
            <router-link v-if="recordLink(record)" :to="recordLink(record)">{{ subjectName(record) }}</router-link>
            <span v-else>{{ subjectName(record) }}</span>
          </template>
          <span v-else class="muted">—</span>
        </template>
        <template v-else-if="column.key === 'changes'">
          <span class="changes">{{ changeSummary(record) }}</span>
        </template>
        <template v-else-if="column.key === 'actions'">
          <a-button type="link" size="small" @click="inspect(record)">{{ $t('Details') }}</a-button>
        </template>
      </template>
    </DataTable>

    <!-- Entry details -->
    <a-drawer :open="drawerOpen" :title="$t('Al_Entry_Details')" placement="right" :width="drawerWidth" @close="drawerOpen = false">
      <template v-if="entry">
        <a-descriptions bordered size="small" :column="1">
          <a-descriptions-item :label="$t('Event')">
            <a-tag :color="eventColor(entry.event)">{{ eventLabel(entry.event) }}</a-tag>
          </a-descriptions-item>
          <a-descriptions-item :label="$t('Al_When')">{{ dateTime(entry.created_at) }}</a-descriptions-item>
          <a-descriptions-item :label="$t('Al_By')">
            {{ entry.user_name || $t('Al_System') }}
            <div v-if="entry.impersonator" class="admin">{{ $t('Al_Via_Admin', { name: entry.impersonator }) }}</div>
          </a-descriptions-item>
          <a-descriptions-item v-if="entry.subject_type" :label="$t('Al_Record')">
            <span class="muted">{{ typeLabel(entry.subject_type) }} · </span>
            <router-link v-if="recordLink(entry)" :to="recordLink(entry)">{{ subjectName(entry) }}</router-link>
            <span v-else>{{ subjectName(entry) }}</span>
          </a-descriptions-item>
          <a-descriptions-item :label="$t('IP_Address')">{{ entry.ip_address || '—' }}</a-descriptions-item>
          <a-descriptions-item v-if="entry.method" :label="$t('Al_Request')"><code>{{ entry.method }} {{ entry.url }}</code></a-descriptions-item>
          <a-descriptions-item v-if="entry.user_agent" :label="$t('Al_Device')"><span class="small">{{ entry.user_agent }}</span></a-descriptions-item>
        </a-descriptions>

        <a-typography-title :level="5" class="block-title">{{ diffTitle(entry) }}</a-typography-title>
        <a-table v-if="diffRows(entry).length" :data-source="diffRows(entry)" :columns="diffColumns(entry)" :pagination="false"
          size="small" row-key="field" :scroll="{ x: 420 }">
          <template #bodyCell="{ column, record }">
            <template v-if="column.key === 'field'"><span class="field">{{ record.label }}</span></template>
            <template v-else-if="column.key === 'before'"><span class="val val--old">{{ record.before }}</span></template>
            <template v-else-if="column.key === 'after'"><span class="val val--new">{{ record.after }}</span></template>
          </template>
        </a-table>
        <p v-else class="muted">{{ $t('Al_No_Values') }}</p>

        <div class="drawer-actions">
          <a-button v-if="entry.subject_type && entry.subject_id" @click="showRecordHistory(entry)"><HistoryOutlined /> {{ $t('Al_Record_History') }}</a-button>
          <a-button v-if="entry.batch" @click="showBatch(entry)"><ApartmentOutlined /> {{ $t('Al_Same_Request') }}</a-button>
        </div>
      </template>
    </a-drawer>
  </div>
</template>

<script setup>
/**
 * Settings > Audit Log — who created / changed / deleted what, and staff
 * sign-ins (written server-side by App\Services\Audit\AuditRecorder).
 *   GET audit_logs (event, subject_type, subject_id, user_id, batch, from, to, search[, export])
 *       → {rows, totalRows, ready, event_counts, users, types, refs}
 * Deep link: /settings/audit-log?subject_type=sale&subject_id=12 opens one
 * record's history.
 */
import { ref, reactive, computed, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import {
  ReloadOutlined, FileExcelOutlined, HistoryOutlined, ApartmentOutlined, FileTextOutlined,
  PlusCircleOutlined, EditOutlined, DeleteOutlined, LoginOutlined, WarningOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import { exportExcel } from '../../lib/exporters';
import http from '../../lib/http';

const { t } = useI18n();
const { dateTime } = useFormat();
const route = useRoute();

const filters = reactive({
  event: undefined,
  subject_type: route.query.subject_type || undefined,
  subject_id: route.query.subject_id ? Number(route.query.subject_id) : undefined,
  user_id: undefined,
  batch: undefined,
  range: null,
});
const recordFilterLabel = ref('');
const ready = ref(true);
const eventCounts = ref({});
const users = ref([]);
const types = ref([]);
const refs = ref({});

const queryParams = () => ({
  event: filters.event || '',
  subject_type: filters.subject_type || '',
  subject_id: filters.subject_id || '',
  user_id: filters.user_id || '',
  batch: filters.batch || '',
  from: filters.range?.[0] || '',
  to: filters.range?.[1] || '',
});

const crud = useCrudTable('audit_logs', {
  rowsKey: 'rows',
  limit: 25,
  sortField: 'created_at',
  pageSizeOptions: ['10', '25', '50', '100', '200'], // the server pages at 200 max
  params: queryParams,
  select: p => {
    ready.value = p.ready !== false;
    eventCounts.value = p.event_counts || {};
    users.value = p.users || [];
    types.value = p.types || [];
    refs.value = p.refs || {};
    return { rows: p.rows || [], total: p.totalRows || 0 };
  },
});

// ---------------- labels ----------------

const EVENTS = ['created', 'updated', 'deleted', 'restored', 'login', 'logout', 'login_failed'];
const EVENT_COLORS = { created: 'success', updated: 'processing', deleted: 'error', restored: 'cyan', login: 'default', logout: 'default', login_failed: 'warning' };
const eventLabel = e => t('Al_Event_' + e);
const eventColor = e => EVENT_COLORS[e] || 'default';
const typeLabel = type => t('Al_T_' + type);

const eventOptions = computed(() => EVENTS.map(e => ({ value: e, label: eventLabel(e) })));
const typeOptions = computed(() => types.value
  .map(type => ({ value: type, label: typeLabel(type) }))
  .sort((a, b) => a.label.localeCompare(b.label)));
const userOptions = computed(() => users.value.map(u => ({ value: u.id, label: u.name || ('#' + u.id) })));

const eventCards = computed(() => {
  const n = e => Number(eventCounts.value[e]) || 0;
  const total = EVENTS.reduce((sum, e) => sum + n(e), 0);
  return [
    { key: 'all', event: undefined, label: t('Total'), value: total, color: '#6d28d9', tint: 'rgba(109, 40, 217, 0.12)', icon: FileTextOutlined },
    { key: 'created', event: 'created', label: eventLabel('created'), value: n('created'), color: '#16a34a', tint: 'rgba(22, 163, 74, 0.12)', icon: PlusCircleOutlined },
    { key: 'updated', event: 'updated', label: eventLabel('updated'), value: n('updated'), color: '#2563eb', tint: 'rgba(37, 99, 235, 0.12)', icon: EditOutlined },
    { key: 'deleted', event: 'deleted', label: eventLabel('deleted'), value: n('deleted'), color: '#dc2626', tint: 'rgba(220, 38, 38, 0.12)', icon: DeleteOutlined },
    { key: 'login', event: 'login', label: eventLabel('login'), value: n('login'), color: '#64748b', tint: 'rgba(100, 116, 139, 0.14)', icon: LoginOutlined },
    { key: 'login_failed', event: 'login_failed', label: eventLabel('login_failed'), value: n('login_failed'), color: '#d97706', tint: 'rgba(217, 119, 6, 0.12)', icon: WarningOutlined },
  ];
});

function setEvent(event) {
  filters.event = event;
  crud.reload();
}

/** Record pages that exist in the admin, by audited type. */
const RECORD_ROUTES = {
  sale: id => `/sales/${id}`,
  purchase: id => `/purchases/${id}`,
  quotation: id => `/quotations/${id}`,
  sale_return: id => `/sale-returns/${id}`,
  purchase_return: id => `/purchase-returns/${id}`,
  adjustment: id => `/adjustments/${id}`,
  transfer: id => `/transfers/${id}`,
  product: id => `/products/${id}`,
  user: id => `/users/${id}/edit`,
};

function recordLink(row) {
  if (!row.subject_id || row.event === 'deleted' || !RECORD_ROUTES[row.subject_type]) return null;
  return RECORD_ROUTES[row.subject_type](row.subject_id);
}

function subjectName(row) {
  if (row.subject_label) return row.subject_label;
  return row.subject_id ? '#' + row.subject_id : typeLabel(row.subject_type);
}

/** "GrandTotal" → "Grand Total", "client_id" → "Client". */
function fieldLabel(field) {
  return String(field)
    .replace(/_id$/, '')
    .replace(/_/g, ' ')
    .replace(/([a-z])([A-Z])/g, '$1 $2')
    .replace(/\b\w/g, c => c.toUpperCase());
}

/** A stored value as text, with foreign keys shown by name. */
function showValue(field, value) {
  if (value === null || value === undefined || value === '') return '—';
  const name = refs.value[field]?.[value];
  if (name) return `${name} (#${value})`;
  if (typeof value === 'object') return JSON.stringify(value);
  return String(value);
}

function changedFields(row) {
  if (row.event === 'updated') return Object.keys(row.new_values || {});
  return [];
}

function changeSummary(row) {
  if (row.event === 'updated') {
    const fields = changedFields(row);
    if (fields.length === 1) {
      const f = fields[0];
      return `${fieldLabel(f)}: ${showValue(f, row.old_values?.[f])} → ${showValue(f, row.new_values?.[f])}`;
    }
    return fields.map(fieldLabel).join(', ');
  }
  if (row.event === 'login_failed' && row.new_values?.login) return `${t('Al_Attempted_Login')}: ${row.new_values.login}`;
  return '';
}

function diffTitle(row) {
  if (row.event === 'updated') return t('Al_Changes');
  if (row.event === 'deleted') return t('Al_Snapshot');
  return t('Al_Values');
}

function diffColumns(row) {
  if (row.event === 'updated') {
    return [
      { title: t('Al_Field'), key: 'field', width: 160 },
      { title: t('Al_Before'), key: 'before' },
      { title: t('Al_After'), key: 'after' },
    ];
  }
  return [
    { title: t('Al_Field'), key: 'field', width: 160 },
    { title: t('Al_Value'), key: 'after' },
  ];
}

function diffRows(row) {
  if (row.event === 'updated') {
    return changedFields(row).map(f => ({
      field: f, label: fieldLabel(f), before: showValue(f, row.old_values?.[f]), after: showValue(f, row.new_values?.[f]),
    }));
  }
  const values = row.event === 'deleted' ? row.old_values : row.new_values;
  return Object.keys(values || {}).map(f => ({ field: f, label: fieldLabel(f), before: '', after: showValue(f, values[f]) }));
}

const columns = computed(() => [
  { title: t('Al_When'), key: 'created_at', dataIndex: 'created_at', sorter: true, width: 165, exportValue: r => dateTime(r.created_at) },
  { title: t('Al_By'), key: 'user_name', dataIndex: 'user_name', sorter: true,
    exportValue: r => (r.user_name || t('Al_System')) + (r.impersonator ? ' (' + t('Al_Via_Admin', { name: r.impersonator }) + ')' : '') },
  { title: t('Event'), key: 'event', dataIndex: 'event', sorter: true, width: 130, exportValue: r => eventLabel(r.event) },
  { title: t('Al_Record'), key: 'subject', exportValue: r => (r.subject_type ? typeLabel(r.subject_type) + ' ' + subjectName(r) : '') },
  { title: t('Al_Changes'), key: 'changes', exportValue: r => exportChanges(r) },
  { title: t('IP_Address'), key: 'ip_address', dataIndex: 'ip_address', width: 130 },
  { title: '', key: 'actions', width: 90, align: 'right' },
]);

/** Full before → after list for the spreadsheet (the table cell is a summary). */
function exportChanges(row) {
  if (row.event !== 'updated') return changeSummary(row);
  return changedFields(row)
    .map(f => `${fieldLabel(f)}: ${showValue(f, row.old_values?.[f])} → ${showValue(f, row.new_values?.[f])}`)
    .join('; ');
}

// ---------------- details drawer ----------------

const drawerOpen = ref(false);
const entry = ref(null);
const drawerWidth = computed(() => (window.innerWidth < 760 ? '100%' : 620));

function inspect(record) {
  entry.value = record;
  drawerOpen.value = true;
}

const recordFilterName = computed(() => (filters.subject_type ? typeLabel(filters.subject_type) + ' ' : '')
  + (recordFilterLabel.value || '#' + filters.subject_id));

function showRecordHistory(row) {
  filters.subject_type = row.subject_type;
  filters.subject_id = row.subject_id;
  recordFilterLabel.value = row.subject_label || '';
  filters.batch = undefined;
  filters.event = undefined;
  drawerOpen.value = false;
  crud.reload();
}

function showBatch(row) {
  filters.batch = row.batch;
  filters.subject_type = undefined;
  filters.subject_id = undefined;
  filters.event = undefined;
  drawerOpen.value = false;
  crud.reload();
}

function clearRecord() {
  filters.subject_id = undefined;
  recordFilterLabel.value = '';
  crud.reload();
}

function clearBatch() {
  filters.batch = undefined;
  crud.reload();
}

function onTypeChange() {
  // A record filter belongs to one type.
  filters.subject_id = undefined;
  recordFilterLabel.value = '';
  crud.reload();
}

// ---------------- export ----------------

const exporting = ref(false);

async function exportRows() {
  exporting.value = true;
  try {
    const res = await http.get('audit_logs', {
      ...queryParams(), search: crud.search.value || '', page: 1, limit: 5000, export: 1, SortField: 'created_at', SortType: 'desc',
    });
    const previousRefs = refs.value;
    refs.value = res.refs || {};
    try {
      await exportExcel('audit-log', columns.value, res.rows || []);
    } finally {
      refs.value = previousRefs;
    }
  } catch (e) {
    message.error((e && e.data && e.data.message) || t('Failed'));
  } finally {
    exporting.value = false;
  }
}

onMounted(() => crud.fetchRows());
</script>

<style scoped>
.muted {
  color: rgba(var(--ink-rgb), 0.45);
}
.small {
  font-size: 11.5px;
}
.admin {
  color: #b45309;
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
  width: 180px;
}
.tb-small {
  width: 150px;
}
.tb-range {
  width: 230px;
}
.active-filters {
  margin-top: 10px;
}
.changes {
  overflow: hidden;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  font-size: 12.5px;
  word-break: break-word;
}
.field {
  font-weight: 500;
}
.val {
  white-space: pre-wrap;
  word-break: break-word;
  font-size: 12.5px;
}
.val--old {
  color: #b91c1c;
}
.val--new {
  color: #15803d;
}
.kpi-card {
  border: 1px solid var(--line);
  box-shadow: 0 1px 4px rgba(0, 0, 0, 0.04);
  height: 100%;
  cursor: pointer;
}
.kpi-card--active {
  border-color: #6d28d9;
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
.block-title {
  margin: 20px 0 8px !important;
}
.drawer-actions {
  display: flex;
  flex-wrap: wrap;
  justify-content: flex-end;
  gap: 8px;
  margin-top: 16px;
}
@media (max-width: 767px) {
  .tb-item,
  .tb-small,
  .tb-range {
    width: 100%;
  }
}
</style>
