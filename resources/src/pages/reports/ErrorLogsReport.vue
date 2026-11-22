<template>
  <div>
    <ReportPage
      :title="$t('Error_Logs')"
      :breadcrumb="[$t('Reports'), $t('Error_Logs')]"
      :crud="crud"
      :columns="columns"
      row-key="id"
      export-endpoint="error-logs"
      :export-params="exportParams"
      :export-select="p => p?.logs || []"
    >
      <!-- Health view over the WHOLE filtered set (backend summary /
           timeseries / by_context), not the visible page. -->
      <template #chart>
        <a-card size="small" class="err-banner" :class="{ clear: !hasErrors }" style="margin-bottom: 16px">
          <div class="banner-inner">
            <span class="banner-icon" :class="{ clear: !hasErrors }">
              <CheckCircleOutlined v-if="!hasErrors && !crud.loading.value" />
              <BugOutlined v-else />
            </span>
            <div class="banner-text">
              <div class="banner-headline">
                <a-spin v-if="crud.loading.value" size="small" />
                <template v-else-if="hasErrors">
                  {{ fmt(summary.total) }} {{ tf('Errors_Logged', 'errors logged') }}
                </template>
                <template v-else>{{ tf('No_Errors_Logged', 'No errors logged — system healthy.') }}</template>
              </div>
              <a-typography-text v-if="hasErrors && !crud.loading.value" type="secondary">
                <strong :style="summary.last_24h ? { color: '#f43f5e' } : null">{{ fmt(summary.last_24h) }}</strong>
                {{ tf('In_Last_24h', 'in the last 24 hours') }}
                · {{ fmt(summary.last_7d) }} {{ tf('In_Last_7d', 'in the last 7 days') }}
                <template v-if="summary.top_context">
                  · {{ tf('Top_Context', 'top context') }}: <a-tag color="error" style="margin: 0">{{ summary.top_context }}</a-tag>
                </template>
              </a-typography-text>
              <a-typography-text v-else-if="!crud.loading.value" type="secondary">
                {{ tf('No_Errors_Hint', 'Nothing recorded in the selected scope.') }}
              </a-typography-text>
            </div>
          </div>
        </a-card>

        <a-row v-if="hasErrors" :gutter="[16, 16]" style="margin-bottom: 16px">
          <a-col :xs="24" :xl="15">
            <a-card size="small" class="chart-card" :title="tf('Errors_Per_Day', 'Errors per day')">
              <apexchart
                v-if="tsSeries[0].data.length"
                type="bar"
                height="300"
                :options="tsOptions"
                :series="tsSeries"
              />
              <a-empty v-else :description="$t('NodataAvailable')" style="padding: 48px 0" />
            </a-card>
          </a-col>
          <a-col :xs="24" :xl="9">
            <a-card size="small" class="chart-card" :title="tf('By_Context', 'By context')">
              <template v-if="byContext.length">
                <div v-for="c in byContext" :key="c.name" class="ctx-row">
                  <a-typography-text class="ctx-name">{{ c.name }}</a-typography-text>
                  <div class="ctx-meter">
                    <span class="ctx-count">{{ fmt(c.count) }}</span>
                    <span class="ctx-track">
                      <span class="ctx-fill" :style="{ width: shareOf(c) + '%' }"></span>
                    </span>
                  </div>
                </div>
              </template>
              <a-empty v-else :description="$t('NodataAvailable')" style="padding: 32px 0" />
            </a-card>
          </a-col>
        </a-row>
      </template>

      <template #filters>
        <DateRangePicker v-model:value="range" allow-clear @change="crud.reload()" />
        <a-select
          v-model:value="contextFilter"
          style="width: 220px"
          allow-clear
          show-search
          :placeholder="$t('Context')"
          :options="contextOptions"
          @change="crud.reload()"
        />
      </template>

      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'occurred_at'">{{ dateTime(record.occurred_at) }}</template>
        <template v-else-if="column.key === 'context'">
          <a-tag color="error">{{ record.context }}</a-tag>
        </template>
        <template v-else-if="column.key === 'message'">
          <div class="err-message">{{ record.message }}</div>
        </template>
        <template v-else-if="column.key === 'location'">
          <a-typography-text v-if="locationOf(record)" code class="err-location">{{ locationOf(record) }}</a-typography-text>
          <span v-else>—</span>
        </template>
        <template v-else-if="column.key === 'actions'">
          <a-button size="small" @click="openEntry(record)">
            <template #icon><EyeOutlined /></template>
            {{ $t('View') }}
          </a-button>
        </template>
      </template>
    </ReportPage>

    <!-- Full error: message, where it happened, extra fields and stack trace -->
    <a-drawer
      v-model:open="entryOpen"
      :title="$t('Details')"
      placement="right"
      :width="drawerWidth"
    >
      <template v-if="entry">
        <a-alert type="error" :message="entry.message" style="margin-bottom: 16px; white-space: pre-wrap; word-break: break-word" />

        <a-descriptions :column="1" size="small" bordered>
          <a-descriptions-item :label="$t('Context')">
            <a-tag color="error" style="margin: 0">{{ entry.context || '—' }}</a-tag>
          </a-descriptions-item>
          <a-descriptions-item :label="$t('Occurred_At')">{{ dateTime(entry.occurred_at) }}</a-descriptions-item>
          <a-descriptions-item v-if="entryDetails.level" :label="$t('Level')">{{ entryDetails.level }}</a-descriptions-item>
          <a-descriptions-item v-if="entryDetails.exception" :label="tf('Err_Exception', 'Exception')">
            <span class="err-break">{{ entryDetails.exception }}</span>
            <template v-if="entryDetails.code"> ({{ entryDetails.code }})</template>
          </a-descriptions-item>
          <a-descriptions-item v-if="entryDetails.file" :label="$t('File')">
            <span class="err-break">{{ entryDetails.file }}<template v-if="entryDetails.line">:{{ entryDetails.line }}</template></span>
          </a-descriptions-item>
          <a-descriptions-item v-if="entryDetails.user_id" :label="$t('User')">#{{ entryDetails.user_id }}</a-descriptions-item>
          <a-descriptions-item v-for="(value, key) in extraFields" :key="key" :label="humanize(key)">
            <span class="err-break">{{ value }}</span>
          </a-descriptions-item>
        </a-descriptions>

        <template v-if="entryTrace">
          <div class="trace-head">
            <h4>{{ tf('Err_Stack_Trace', 'Stack trace') }}</h4>
            <a-button size="small" @click="copyTrace">
              <template #icon><CopyOutlined /></template>
              {{ $t('Copy') }}
            </a-button>
          </div>
          <pre class="trace">{{ entryTrace }}</pre>
        </template>
      </template>
    </a-drawer>
  </div>
</template>

<script setup>
/**
 * Deviant contract on both sides: endpoint is `/error-logs` (not report/*),
 * takes `per_page` instead of `limit`, and answers { logs, total } instead of
 * { <rows>, totalRows } — hence `select` and the lazy per_page param below.
 */
import { ref, computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { message } from 'ant-design-vue';
import { BugOutlined, CheckCircleOutlined, EyeOutlined, CopyOutlined } from '@ant-design/icons-vue';
import ReportPage from '../../components/ReportPage.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import { useUiStore } from '../../stores/ui';
import { t as tf } from '../../i18n';
import DateRangePicker from '../../components/DateRangePicker.vue';

const { t } = useI18n();
const { dateTime } = useFormat();
const ui = useUiStore();

const range = ref(null);
const contextFilter = ref(undefined);

const filterParams = () => ({
  ...(range.value?.[0] ? { from: range.value[0].format('YYYY-MM-DD') } : {}),
  ...(range.value?.[1] ? { to: range.value[1].format('YYYY-MM-DD') } : {}),
  ...(contextFilter.value ? { context: contextFilter.value } : {}),
});

// `params` is called at fetch time, so it can read crud.limit after creation.
let crud;
crud = useCrudTable('error-logs', {
  sortField: 'occurred_at',
  sortType: 'desc',
  params: () => ({ per_page: crud ? crud.limit.value : 10, ...filterParams() }),
  select: p => ({ rows: p?.logs || [], total: Number(p?.total) || 0 }),
});

const exportParams = () => ({ per_page: -1, ...filterParams() });

const fmt = v => (Number(v) || 0).toLocaleString();

/* ---------------------------------------------------------------- banner */
const summary = computed(() => crud.payload.value?.summary || {});
const hasErrors = computed(() => (Number(summary.value.total) || 0) > 0);

const contextOptions = computed(() =>
  (crud.payload.value?.contexts || []).map(c => ({ value: c, label: c }))
);

/* ------------------------------------------------------- errors per day */
const tsRows = computed(() => crud.payload.value?.timeseries || []);
const tsSeries = computed(() => [{
  name: tf('Errors_Logged', 'errors logged'),
  data: tsRows.value.map(r => Number(r.count) || 0),
}]);
const tsOptions = computed(() => {
  const axisColor = ui.dark ? '#d9d9d9' : '#595959';
  return {
    chart: { type: 'bar', background: 'transparent', fontFamily: 'inherit', toolbar: { show: false } },
    plotOptions: { bar: { columnWidth: '55%', borderRadius: 3 } },
    colors: ['#f43f5e'],
    dataLabels: { enabled: false },
    xaxis: {
      categories: tsRows.value.map(r => r.d),
      labels: { style: { colors: axisColor } },
      axisBorder: { show: false },
      axisTicks: { show: false },
    },
    yaxis: { labels: { style: { colors: axisColor } } },
    grid: { borderColor: 'rgba(128, 128, 128, 0.2)' },
    tooltip: { theme: ui.dark ? 'dark' : 'light' },
  };
});

/* ------------------------------------------------------------ contexts */
const byContext = computed(() => crud.payload.value?.by_context || []);
const maxContextCount = computed(() =>
  Math.max(1, ...byContext.value.map(c => Number(c.count) || 0))
);
function shareOf(c) {
  return Math.round(((Number(c.count) || 0) / maxContextCount.value) * 100);
}

/* ---------------------------------------------------------------- table */
const columns = computed(() => [
  { title: t('Occurred_At'), key: 'occurred_at', dataIndex: 'occurred_at', sorter: true, exportValue: r => dateTime(r.occurred_at) },
  { title: t('Context'), key: 'context', dataIndex: 'context', sorter: true, exportValue: r => r.context },
  { title: t('Message'), dataIndex: 'message', key: 'message', exportValue: r => r.message },
  { title: t('Location'), key: 'location', dataIndex: 'details', sorter: false, exportValue: r => locationOf(r) },
  { title: '', key: 'actions', width: 90 },
]);

/* ------------------------------------------------------------- details
 * `details` is usually JSON — imported log entries carry
 * { source, level, exception, code, file, line, user_id, context, trace },
 * app-recorded ones their own keys (e.g. Twilio: { receiver, trace }) —
 * but older rows may hold plain text. */
function parseDetails(raw) {
  if (!raw) return {};
  if (typeof raw === 'object') return raw;
  try {
    const parsed = JSON.parse(raw);
    return parsed && typeof parsed === 'object' && !Array.isArray(parsed) ? parsed : { text: String(raw) };
  } catch (e) {
    return { text: String(raw) };
  }
}

/** "Connection.php:824" — the file name is enough in a table cell. */
function locationOf(record) {
  const d = parseDetails(record.details);
  if (!d.file) return '';
  const name = String(d.file).split(/[\\/]/).pop();
  return d.line ? `${name}:${d.line}` : name;
}

const entryOpen = ref(false);
const entry = ref(null);
const drawerWidth = computed(() => Math.min(760, window.innerWidth));
const entryDetails = computed(() => parseDetails(entry.value?.details));

const KNOWN_KEYS = ['source', 'level', 'exception', 'code', 'file', 'line', 'user_id', 'trace', 'text'];
const extraFields = computed(() => {
  const out = {};
  Object.entries(entryDetails.value).forEach(([key, value]) => {
    if (KNOWN_KEYS.includes(key) || value === null || value === '') return;
    out[key] = typeof value === 'object' ? JSON.stringify(value) : String(value);
  });
  return out;
});
const entryTrace = computed(() => {
  const d = entryDetails.value;
  return String(d.trace || d.text || '').trim();
});

function openEntry(record) {
  entry.value = record;
  entryOpen.value = true;
}

async function copyTrace() {
  const text = [entry.value?.message, entryDetails.value.file ? `${entryDetails.value.file}:${entryDetails.value.line || ''}` : '', entryTrace.value]
    .filter(Boolean)
    .join('\n\n');
  try {
    await navigator.clipboard.writeText(text);
    message.success(t('Copied'));
  } catch (e) {
    message.error(t('InvalidData'));
  }
}

function humanize(key) {
  const s = String(key).replace(/_/g, ' ').replace(/([a-z\d])([A-Z])/g, '$1 $2').trim();
  return s.charAt(0).toUpperCase() + s.slice(1);
}

onMounted(() => crud.fetchRows());
</script>

<style scoped>
.chart-card { border-radius: 10px; }
/* Table cells: full message (wrapped, capped), short file:line. */
.err-message {
  max-width: 560px;
  white-space: normal;
  word-break: break-word;
  display: -webkit-box;
  -webkit-line-clamp: 3;
  -webkit-box-orient: vertical;
  overflow: hidden;
}
.err-location { white-space: nowrap; }
.err-break { word-break: break-all; }

/* Drawer stack trace */
.trace-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin: 20px 0 8px;
}
.trace-head h4 { margin: 0; }
.trace {
  max-height: 460px;
  overflow: auto;
  margin: 0;
  padding: 12px;
  border-radius: 8px;
  font-size: 12px;
  line-height: 1.55;
  white-space: pre;
  background: rgba(128, 128, 128, 0.1);
}


/* Incident banner: red while errors exist, green when the scope is clean. */
.err-banner {
  border-radius: 10px;
  border-inline-start: 4px solid #f43f5e;
  background: rgba(244, 63, 94, 0.05);
}
.err-banner.clear {
  border-inline-start-color: #10b981;
  background: rgba(16, 185, 129, 0.05);
}
.banner-inner {
  display: flex;
  align-items: center;
  gap: 14px;
}
.banner-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 44px;
  height: 44px;
  border-radius: 12px;
  font-size: 22px;
  flex: none;
  color: #f43f5e;
  background: rgba(244, 63, 94, 0.12);
}
.banner-icon.clear {
  color: #10b981;
  background: rgba(16, 185, 129, 0.12);
}
.banner-text { min-width: 0; }
.banner-headline {
  font-size: 18px;
  font-weight: 700;
  line-height: 1.3;
}

/* Context rows: name, count + share meter. */
.ctx-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 8px 4px;
}
.ctx-row + .ctx-row { border-top: 1px solid rgba(128, 128, 128, 0.14); }
.ctx-name {
  min-width: 0;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.ctx-meter {
  display: flex;
  align-items: center;
  gap: 8px;
  flex: none;
}
.ctx-count {
  font-weight: 600;
  min-width: 34px;
  text-align: end;
}
.ctx-track {
  display: inline-block;
  width: 90px;
  height: 6px;
  border-radius: 999px;
  background: rgba(128, 128, 128, 0.2);
  overflow: hidden;
}
.ctx-fill {
  display: block;
  height: 100%;
  border-radius: 999px;
  background: #f43f5e;
}
</style>
