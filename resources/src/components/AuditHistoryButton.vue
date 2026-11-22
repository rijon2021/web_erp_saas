<template>
  <!-- One element, so a parent a-space gets one item, not an empty gap for the drawer. -->
  <span v-if="visible">
    <a-button @click="open">
      <template #icon><HistoryOutlined /></template>
      {{ $t('Al_History') }}
    </a-button>

    <a-drawer
      v-model:open="drawerOpen"
      :title="$t('Al_Record_History')"
      placement="right"
      :width="drawerWidth"
    >
      <template #extra>
        <a-button type="link" @click="openFullLog">{{ $t('Al_Open_Full_Log') }}</a-button>
      </template>

      <a-spin :spinning="loading">
        <a-empty v-if="!loading && !entries.length" :description="$t('Al_No_History')" />

        <a-timeline v-else>
          <a-timeline-item v-for="e in entries" :key="e.id" :color="dotColor(e.event)">
            <div class="h-head">
              <a-tag :color="eventColor(e.event)" style="margin: 0">{{ $t('Al_Event_' + e.event) }}</a-tag>
              <span v-if="e.subject_type !== type" class="h-child">
                {{ $t('Al_T_' + e.subject_type) }}<template v-if="e.subject_label"> · {{ e.subject_label }}</template>
              </span>
            </div>
            <div class="h-meta">
              {{ e.user_name || $t('Al_System') }} · {{ dateTime(e.created_at) }}
              <div v-if="e.impersonator" class="h-admin">{{ $t('Al_Via_Admin', { name: e.impersonator }) }}</div>
            </div>

            <!-- Created / deleted snapshots are long — the full log has them. -->
            <ul v-if="e.event === 'updated' && changed(e).length" class="h-diff">
              <li v-for="f in shown(e)" :key="f">
                <span class="h-field">{{ fieldLabel(f) }}:</span>
                <span class="val val-old">{{ showValue(f, e.old_values?.[f]) }}</span>
                <span class="h-arrow">→</span>
                <span class="val val-new">{{ showValue(f, e.new_values?.[f]) }}</span>
              </li>
              <li v-if="changed(e).length > shown(e).length">
                <a @click="expanded[e.id] = true">{{ $t('Al_More_Fields', { n: changed(e).length - shown(e).length }) }}</a>
              </li>
            </ul>
          </a-timeline-item>
        </a-timeline>

        <a-button v-if="entries.length < total" block :loading="loadingMore" @click="loadMore">
          {{ $t('Al_Load_More') }}
        </a-button>
      </a-spin>
    </a-drawer>
  </span>
</template>

<script setup>
/**
 * "History" button + drawer for record pages: the record's audit trail and
 * its children (a sale's payments…) from
 * GET audit_logs?subject_type=&subject_id=&related=1 → {rows, totalRows, refs}.
 * Hidden without the audit_log permission or when the plan lacks the
 * audit_log feature.
 *
 *   <AuditHistoryButton type="sale" />               (record id = route :id)
 *   <AuditHistoryButton type="client" :record-id="client.id" />
 *
 * `type` is an AuditRegistry key (sale, product, client…), not a class name.
 */
import { ref, reactive, computed } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { HistoryOutlined } from '@ant-design/icons-vue';
import { useAuthStore } from '../stores/auth';
import { usePlanStore } from '../stores/plan';
import { useFormat } from '../composables/useFormat';
import http from '../lib/http';

const props = defineProps({
  type: { type: String, required: true },
  recordId: { type: [Number, String], default: null },
});

const PAGE = 25;
const FIELDS_SHOWN = 6;
const EVENT_COLORS = { created: 'success', updated: 'processing', deleted: 'error', restored: 'cyan' };

const auth = useAuthStore();
const plan = usePlanStore();
const route = useRoute();
const router = useRouter();
const { dateTime } = useFormat();

const visible = computed(() => auth.can('audit_log') && plan.featureEnabled('audit_log'));
const drawerOpen = ref(false);
const loading = ref(false);
const loadingMore = ref(false);
const entries = ref([]);
const refs = ref({});
const total = ref(0);
const page = ref(1);
const expanded = reactive({});

const id = computed(() => props.recordId ?? route.params.id);
const drawerWidth = computed(() => Math.min(520, window.innerWidth));

async function fetchPage(p) {
  const data = await http.get('audit_logs', {
    subject_type: props.type,
    subject_id: id.value,
    related: 1,
    page: p,
    limit: PAGE,
    SortField: 'created_at',
    SortType: 'desc',
  });
  total.value = Number(data?.totalRows) || 0;
  // Names for foreign keys on this page (warehouse_id → "Main store"…).
  Object.entries(data?.refs || {}).forEach(([col, names]) => {
    refs.value[col] = { ...(refs.value[col] || {}), ...names };
  });
  return data?.rows || [];
}

async function open() {
  drawerOpen.value = true;
  loading.value = true;
  page.value = 1;
  refs.value = {};
  try {
    entries.value = await fetchPage(1);
  } catch (e) {
    entries.value = [];
  } finally {
    loading.value = false;
  }
}

async function loadMore() {
  loadingMore.value = true;
  try {
    const next = await fetchPage(page.value + 1);
    page.value += 1;
    entries.value = entries.value.concat(next);
  } finally {
    loadingMore.value = false;
  }
}

function openFullLog() {
  drawerOpen.value = false;
  router.push({ path: '/settings/audit-log', query: { subject_type: props.type, subject_id: id.value } });
}

const eventColor = e => EVENT_COLORS[e] || 'default';
const dotColor = e => ({ created: 'green', deleted: 'red', restored: 'cyan' }[e] || 'blue');
const changed = e => Object.keys(e.new_values || {});
const shown = e => (expanded[e.id] ? changed(e) : changed(e).slice(0, FIELDS_SHOWN));

/** Same wording as the Audit Log page: "GrandTotal" → "Grand Total", "client_id" → "Client". */
function fieldLabel(field) {
  return String(field)
    .replace(/_id$/, '')
    .replace(/_/g, ' ')
    .replace(/([a-z])([A-Z])/g, '$1 $2')
    .replace(/\b\w/g, c => c.toUpperCase());
}

function showValue(field, value) {
  if (value === null || value === undefined || value === '') return '—';
  const name = refs.value[field]?.[value];
  if (name) return `${name} (#${value})`;
  if (typeof value === 'object') return JSON.stringify(value);
  return String(value);
}
</script>

<style scoped>
.h-head { display: flex; align-items: center; flex-wrap: wrap; gap: 8px; }
.h-child { font-weight: 500; }
.h-meta { margin-top: 4px; font-size: 12px; opacity: 0.65; }
.h-admin { color: #d97706; }
.h-diff { margin: 8px 0 0; padding: 0; list-style: none; font-size: 13px; }
.h-diff li + li { margin-top: 4px; }
.h-field { margin-inline-end: 6px; opacity: 0.75; }
.h-arrow { margin: 0 6px; opacity: 0.6; }
.val {
  display: inline-block;
  max-width: 100%;
  white-space: pre-wrap;
  word-break: break-word;
  padding: 0 5px;
  border-radius: 4px;
}
.val-old {
  background: rgba(244, 63, 94, 0.1);
  text-decoration: line-through;
  text-decoration-color: rgba(244, 63, 94, 0.6);
}
.val-new { background: rgba(16, 185, 129, 0.12); }
</style>
