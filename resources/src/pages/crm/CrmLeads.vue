<template>
  <div class="page">
    <PageHeader :title="$t('Crm_Leads')" :breadcrumb="[$t('Crm'), $t('Crm_Leads')]">
      <template #actions>
        <a-dropdown v-if="canImportExport">
          <a-button>
            <template #icon><SwapOutlined /></template>
            {{ $t('Crm_Import_Export') }}
          </a-button>
          <template #overlay>
            <a-menu>
              <a-menu-item v-if="canAdd" key="import" @click="$router.push('/crm/leads/import')">
                <UploadOutlined /> {{ $t('Crm_Import_Leads') }}
              </a-menu-item>
              <a-menu-item key="export" @click="exportCsv">
                <DownloadOutlined /> {{ $t('Crm_Export_Csv') }}
              </a-menu-item>
            </a-menu>
          </template>
        </a-dropdown>
        <a-tooltip v-if="canSegment" :title="$t('Crm_Segment_From_Filters_Hint')">
          <a-button @click="toSegment"><template #icon><NotificationOutlined /></template>{{ $t('Crm_Create_Segment') }}</a-button>
        </a-tooltip>
        <a-button v-if="canAdd" type="primary" @click="$router.push('/crm/leads/create')">
          <template #icon><UserAddOutlined /></template>
          {{ $t('Crm_New_Lead') }}
        </a-button>
      </template>
    </PageHeader>

    <!-- Quick tiles -->
    <div class="tiles">
      <button type="button" class="tile" :class="{ active: quick === '' && status === 'open' && owner === '' }" @click="setTile('open')">
        <span class="tile-ic tile-ic--brand"><Users :size="18" /></span>
        <span class="tile-text"><span class="tile-value">{{ summary.open || 0 }}</span><span class="tile-label">{{ $t('Crm_Open_Leads') }}</span></span>
        <span class="tile-note">{{ money(summary.open_value || 0) }}</span>
      </button>
      <button type="button" class="tile" :class="{ active: owner === 'none' }" @click="setTile('unassigned')">
        <span class="tile-ic tile-ic--warn"><UserX :size="18" /></span>
        <span class="tile-text"><span class="tile-value">{{ summary.unassigned || 0 }}</span><span class="tile-label">{{ $t('Crm_Unassigned') }}</span></span>
      </button>
      <button type="button" class="tile" :class="{ active: quick === 'hot' }" @click="setTile('hot')">
        <span class="tile-ic tile-ic--hot"><Flame :size="18" /></span>
        <span class="tile-text"><span class="tile-value">{{ summary.hot || 0 }}</span><span class="tile-label">{{ $t('Crm_Hot_Leads') }}</span></span>
      </button>
      <button type="button" class="tile" :class="{ active: quick === 'stale' }" @click="setTile('stale')">
        <span class="tile-ic tile-ic--muted"><Hourglass :size="18" /></span>
        <span class="tile-text"><span class="tile-value">{{ summary.stale || 0 }}</span><span class="tile-label">{{ $t('Crm_Stale_Leads') }}</span></span>
      </button>
    </div>

    <!-- Status pills -->
    <div class="pills" role="tablist">
      <button type="button" class="pill" :class="{ active: status === '' }" @click="setStatus('')">
        {{ $t('All') }} <span class="pill-count">{{ summary.all || 0 }}</span>
      </button>
      <button
        v-for="s in meta.statuses" :key="s.id" type="button" class="pill"
        :class="{ active: String(status) === String(s.id) }" :style="pillStyle(s)" @click="setStatus(s.id)"
      >
        <span class="dot" :style="{ background: s.color }"></span>
        {{ s.name }} <span class="pill-count">{{ summary.by_status?.[s.id] || 0 }}</span>
      </button>
    </div>

    <!-- Filters -->
    <div class="filters">
      <a-select v-model:value="owner" :options="ownerOptions" :placeholder="$t('Crm_Owner')" allow-clear style="width: 190px" @change="reload" />
      <a-select v-model:value="source" :options="sourceOptions" :placeholder="$t('Crm_Source')" allow-clear style="width: 170px" @change="reload" />
      <a-select v-model:value="rating" :options="ratingOptions" :placeholder="$t('Crm_Rating')" allow-clear style="width: 130px" @change="reload" />
      <a-select v-model:value="tag" :options="tagOptions" :placeholder="$t('Crm_Tag')" allow-clear show-search option-filter-prop="label" style="width: 160px" @change="reload" />
      <a-select v-model:value="via" :options="viaOptions" :placeholder="$t('Crm_Came_In_Through')" allow-clear style="width: 180px" @change="reload" />
      <a-select v-if="meta.web_forms?.length" v-model:value="webForm" :options="webFormOptions" :placeholder="$t('Crm_Web_Form')" allow-clear style="width: 170px" @change="reload" />
      <a-range-picker v-model:value="range" value-format="YYYY-MM-DD" :placeholder="[$t('Crm_Created_From'), $t('Crm_Created_To')]" @change="reload" />
      <a-button v-if="hasFilters" type="link" @click="clearFilters">{{ $t('Crm_Clear_Filters') }}</a-button>
    </div>

    <DataTable :crud="crud" :columns="columns" :selectable="canEdit || canDelete">
      <template #toolbar>
        <template v-if="crud.selectedIds.value.length && canEdit">
          <a-dropdown>
            <a-button>
              <template #icon><UserSwitchOutlined /></template>
              {{ $t('Crm_Assign') }}
            </a-button>
            <template #overlay>
              <a-menu @click="({ key }) => bulk('assign', { owner_id: key === 'pool' ? '' : key })">
                <a-menu-item key="pool">{{ $t('Crm_Unassigned_Pool') }}</a-menu-item>
                <a-menu-item v-for="o in assignableOwners" :key="o.id">{{ o.name }}</a-menu-item>
              </a-menu>
            </template>
          </a-dropdown>
          <a-dropdown>
            <a-button>{{ $t('Crm_Set_Status') }}</a-button>
            <template #overlay>
              <a-menu @click="({ key }) => bulk('status', { crm_lead_status_id: key })">
                <a-menu-item v-for="s in openStatuses" :key="s.id"><span class="dot" :style="{ background: s.color }"></span> {{ s.name }}</a-menu-item>
              </a-menu>
            </template>
          </a-dropdown>
          <a-button @click="openTagModal">
            <template #icon><TagOutlined /></template>
            {{ $t('Crm_Tag') }}
          </a-button>
        </template>
      </template>

      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'name'">
          <button type="button" class="person" @click="open(record)">
            <a-avatar :size="36" :style="{ background: avatarColor(record.name) }">
              <template v-if="record.type === 'company'"><Building2 :size="16" /></template>
              <template v-else>{{ initials(record.name) }}</template>
            </a-avatar>
            <span class="person-text">
              <span class="person-name">
                {{ record.name }}
                <component :is="ratingIcon(record.rating)" v-if="record.rating === 'hot'" :size="13" class="hot" />
              </span>
              <span class="person-sub">
                <template v-if="record.type === 'person' && record.company_name">{{ record.company_name }} · </template>{{ record.phone || record.email || record.lead_number }}
              </span>
            </span>
          </button>
        </template>

        <template v-else-if="column.key === 'status'">
          <span v-if="record.status" class="status" :style="{ color: record.status.color, background: tint(record.status.color) }">
            {{ record.status.name }}
          </span>
          <a-tooltip v-if="record.is_stale" :title="$t('Crm_Stale_Hint')"><Hourglass :size="13" class="stale" /></a-tooltip>
        </template>

        <template v-else-if="column.key === 'score'">
          <span class="score" :style="{ color: scoreColor(record.score), background: tint(scoreColor(record.score), 0.14) }">{{ record.score }}</span>
        </template>

        <template v-else-if="column.key === 'next'">
          <span v-if="record.next_activity" class="next" :class="{ late: record.next_activity.is_overdue }">
            <component :is="actIcon(record.next_activity.type)" :size="13" /> {{ dueLabel(record.next_activity.due_at, date) }}
            <span class="next-subj">{{ record.next_activity.subject }}</span>
          </span>
          <span v-else-if="record.status?.kind === 'open'" class="next none"><TriangleAlert :size="13" /> {{ $t('Crm_No_Next_Step_Short') }}</span>
          <span v-else class="muted">—</span>
        </template>

        <template v-else-if="column.key === 'source'">
          <span v-if="record.source" class="src"><span class="dot" :style="{ background: record.source.color }"></span>{{ record.source.name }}</span>
          <span v-else class="muted">—</span>
        </template>

        <template v-else-if="column.key === 'owner'">
          <span v-if="record.owner" class="owner">
            <a-avatar :size="22" :style="{ background: avatarColor(record.owner.name), fontSize: '10px' }">{{ initials(record.owner.name) }}</a-avatar>
            {{ record.owner.name }}
          </span>
          <a-tag v-else color="warning">{{ $t('Crm_Unassigned') }}</a-tag>
        </template>

        <template v-else-if="column.key === 'estimated_value'">
          <span :class="{ muted: !record.estimated_value }">{{ record.estimated_value ? money(record.estimated_value) : '—' }}</span>
        </template>

        <template v-else-if="column.key === 'tags'">
          <span class="tags">
            <span v-for="tg in record.tags.slice(0, 3)" :key="tg.id" class="tag" :style="{ color: tg.color, background: tint(tg.color) }">{{ tg.name }}</span>
            <span v-if="record.tags.length > 3" class="muted">+{{ record.tags.length - 3 }}</span>
          </span>
        </template>

        <template v-else-if="column.key === 'created_at'">
          <a-tooltip :title="dateTime(record.created_at)"><span class="muted">{{ date(record.created_at) }}</span></a-tooltip>
        </template>

        <template v-else-if="column.key === 'actions'">
          <a-space :size="0">
            <a-tooltip :title="$t('View')">
              <a-button type="text" size="small" @click="open(record)"><template #icon><EyeOutlined /></template></a-button>
            </a-tooltip>
            <a-tooltip v-if="canEdit && !record.client_id" :title="$t('Edit')">
              <a-button type="text" size="small" @click="$router.push(`/crm/leads/${record.id}/edit`)"><template #icon><EditOutlined /></template></a-button>
            </a-tooltip>
            <a-tooltip v-if="canDelete" :title="$t('Delete')">
              <a-button type="text" size="small" danger @click="crud.remove(record, { label: record.name })"><template #icon><DeleteOutlined /></template></a-button>
            </a-tooltip>
          </a-space>
        </template>
      </template>
    </DataTable>

    <a-modal v-model:open="tagModal" :title="$t('Crm_Tag_Selected')" :ok-text="$t('Crm_Apply')" :cancel-text="$t('Cancel')" :confirm-loading="busy" @ok="applyTag">
      <a-radio-group v-model:value="tagAction" class="tag-action">
        <a-radio-button value="tag">{{ $t('Crm_Add_Tag') }}</a-radio-button>
        <a-radio-button value="untag">{{ $t('Crm_Remove_Tag') }}</a-radio-button>
      </a-radio-group>
      <a-select
        v-model:value="tagValue" :options="tagOptions" show-search option-filter-prop="label"
        :mode="tagAction === 'tag' ? 'tags' : undefined" :max-tag-count="1"
        :placeholder="$t('Crm_Tag_Pick_Or_Type')" style="width: 100%"
      />
    </a-modal>
  </div>
</template>

<script setup>
/**
 * CRM leads — GET crm/leads → { leads, totalRows, summary }. Tiles and status
 * pills are quick filters; bulk assign / status / tag work on the selection.
 */
import { ref, computed, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { message } from 'ant-design-vue';
import {
  UserAddOutlined, EyeOutlined, EditOutlined, DeleteOutlined, SwapOutlined, UploadOutlined,
  DownloadOutlined, UserSwitchOutlined, TagOutlined, NotificationOutlined,
} from '@ant-design/icons-vue';
import { Users, UserX, Flame, Hourglass, Building2, TriangleAlert } from 'lucide-vue-next';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import { useAuthStore } from '../../stores/auth';
import http from '../../lib/http';
import { t } from '../../i18n';
import { RATINGS, ACTIVITY_TYPES, CAPTURE_CHANNELS, tr, optionOf, initials, avatarColor, tint, dueLabel, scoreColor } from './crmOptions';

function actIcon(type) { return optionOf(ACTIVITY_TYPES, type).icon; }

const auth = useAuthStore();
const route = useRoute();
const router = useRouter();
const { money, date, dateTime } = useFormat();

const canAdd = computed(() => auth.can('crm_leads_add'));
const canEdit = computed(() => auth.can('crm_leads_edit'));
const canDelete = computed(() => auth.can('crm_leads_delete'));
const canImportExport = computed(() => auth.can('crm_import_export'));

const meta = ref({ statuses: [], sources: [], tags: [], owners: [], can: {} });
const q = route.query;
const status = ref(q.status ?? 'open');
const owner = ref(q.owner || '');
const source = ref(q.source ? Number(q.source) : undefined);
const rating = ref(q.rating || undefined);
const tag = ref(q.tag ? Number(q.tag) : undefined);
const quick = ref(['hot', 'stale'].includes(q.quick) ? q.quick : '');
const via = ref(q.captured_via || undefined);
const webForm = ref(q.web_form ? Number(q.web_form) : undefined);
const range = ref(null);

const params = () => ({
  status: status.value ?? '',
  owner: owner.value || '',
  source: source.value || '',
  rating: rating.value || '',
  tag: tag.value || '',
  quick: quick.value,
  captured_via: via.value || '',
  web_form: webForm.value || '',
  from: range.value?.[0] || '',
  to: range.value?.[1] || '',
});
const crud = useCrudTable('crm/leads', { rowsKey: 'leads', sortField: 'created_at', params });
const summary = computed(() => crud.payload.value?.summary || {});

const openStatuses = computed(() => meta.value.statuses.filter(s => s.kind === 'open'));
const ownerOptions = computed(() => [
  { value: 'me', label: t('Crm_My_Leads', 'My leads') },
  { value: 'none', label: t('Crm_Unassigned', 'Unassigned') },
  ...(meta.value.can?.view_all ? meta.value.owners.map(o => ({ value: String(o.id), label: o.name })) : []),
]);
const assignableOwners = computed(() => (meta.value.can?.view_all
  ? meta.value.owners
  : meta.value.owners.filter(o => o.id === meta.value.me)));
const sourceOptions = computed(() => meta.value.sources.map(s => ({ value: s.id, label: s.name })));
const tagOptions = computed(() => meta.value.tags.map(tg => ({ value: tg.id, label: tg.name })));
const ratingOptions = computed(() => tr(RATINGS));
const viaOptions = computed(() => tr(CAPTURE_CHANNELS).map(c => ({ value: c.value, label: c.label })));
const webFormOptions = computed(() => (meta.value.web_forms || []).map(f => ({ value: f.id, label: f.name })));
const hasFilters = computed(() => !!(owner.value || source.value || rating.value || tag.value || range.value || quick.value || via.value || webForm.value));

function ratingIcon(r) { return optionOf(RATINGS, r).icon; }
function pillStyle(s) { return String(status.value) === String(s.id) ? { borderColor: s.color, background: tint(s.color, 0.08) } : {}; }

function reload() { crud.reload(); }
function setStatus(v) { status.value = v; quick.value = ''; reload(); }
function setTile(which) {
  quick.value = ['hot', 'stale'].includes(which) ? which : '';
  owner.value = which === 'unassigned' ? 'none' : (which === 'open' ? '' : owner.value);
  status.value = 'open';
  reload();
}
function clearFilters() {
  owner.value = ''; source.value = undefined; rating.value = undefined; tag.value = undefined; range.value = null; quick.value = '';
  via.value = undefined; webForm.value = undefined;
  reload();
}
function open(row) { router.push(`/crm/leads/${row.id}`); }

/** Marketing campaign to these leads: open a lead segment prefilled with the current filters. */
const canSegment = computed(() => auth.can('marketing_segments') && meta.value.can?.view_all);
function toSegment() {
  const q = { audience: 'crm_leads' };
  if (tag.value) q.tag = tag.value;
  if (source.value) q.source = source.value;
  if (/^\d+$/.test(String(status.value ?? ''))) q.status = status.value;
  if (rating.value) q.rating = rating.value;
  if (owner.value) q.owner = owner.value === 'me' ? meta.value.me : owner.value;
  if (via.value) q.captured_via = via.value;
  router.push({ path: '/marketing/segments', query: q });
}

async function bulk(action, extra) {
  const ids = [...crud.selectedIds.value];
  if (!ids.length) return;
  try {
    const res = await http.post('crm/leads/bulk', { ids, action, ...extra });
    message.success(`${t('Updated_in_successfully', 'Updated successfully')} (${res?.count ?? 0})`);
    crud.fetchRows();
    if (action === 'tag') loadMeta();
  } catch (e) {
    message.error(e?.data?.message || t('Gen_Could_Not_Update', 'Could not update'));
  }
}

const tagModal = ref(false);
const tagAction = ref('tag');
const tagValue = ref(undefined);
const busy = ref(false);
function openTagModal() { tagAction.value = 'tag'; tagValue.value = undefined; tagModal.value = true; }
async function applyTag() {
  const v = Array.isArray(tagValue.value) ? tagValue.value[tagValue.value.length - 1] : tagValue.value;
  if (v === undefined || v === null || v === '') { message.error(t('Crm_Choose_Tag', 'Choose a tag')); return; }
  busy.value = true;
  await bulk(tagAction.value, { tag: v });
  busy.value = false;
  tagModal.value = false;
}

async function exportCsv() {
  const qs = new URLSearchParams({ ...params(), search: crud.search.value || '' });
  try {
    await http.download(`crm/leads/export?${qs}`, `leads_${new Date().toISOString().slice(0, 10)}.csv`);
  } catch (e) {
    message.error(t('Crm_Err_Could_Not_Export', 'Could not export'));
  }
}

async function loadMeta() {
  try { meta.value = await http.get('crm/leads/meta'); } catch (e) { /* filters stay empty */ }
}

const columns = computed(() => [
  { title: t('Crm_Lead'), key: 'name', dataIndex: 'name', sorter: true },
  ...(meta.value.settings?.scoring_enabled ? [{ title: t('Crm_Score'), key: 'score', dataIndex: 'score', sorter: true, width: 90, align: 'center' }] : []),
  { title: t('Status'), key: 'status', width: 150 },
  { title: t('Crm_Next_Step'), key: 'next', width: 200 },
  { title: t('Crm_Source'), key: 'source', width: 140 },
  { title: t('Crm_Owner'), key: 'owner', width: 170 },
  { title: t('Crm_Estimated_Value'), key: 'estimated_value', dataIndex: 'estimated_value', sorter: true, width: 140, align: 'right' },
  { title: t('Crm_Tags'), key: 'tags', width: 180 },
  { title: t('Crm_Created'), key: 'created_at', dataIndex: 'created_at', sorter: true, width: 120 },
  { title: '', key: 'actions', width: 110 },
]);

onMounted(() => { loadMeta(); crud.fetchRows(); });
</script>

<style scoped>
.muted { color: rgba(128, 128, 128, 0.75); }
.dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; flex: none; }

.tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; margin-bottom: 14px; }
.tile {
  display: flex; align-items: center; gap: 12px; padding: 12px 16px; position: relative;
  border: 1px solid rgba(128, 128, 128, 0.2); border-radius: 14px; background: transparent;
  color: inherit; font: inherit; text-align: start; cursor: pointer; transition: border-color 0.15s, background 0.15s;
}
.tile:hover { border-color: rgba(var(--app-primary-rgb, 109, 40, 217), 0.5); }
.tile.active { border-color: var(--app-primary, #6d28d9); background: rgba(var(--app-primary-rgb, 109, 40, 217), 0.06); }
.tile-ic { width: 38px; height: 38px; flex: none; display: inline-flex; align-items: center; justify-content: center; border-radius: 11px; }
.tile-ic--brand { color: var(--app-primary, #6d28d9); background: rgba(var(--app-primary-rgb, 109, 40, 217), 0.12); }
.tile-ic--warn { color: #d97706; background: rgba(217, 119, 6, 0.14); }
.tile-ic--hot { color: #dc2626; background: rgba(220, 38, 38, 0.12); }
.tile-ic--muted { color: #64748b; background: rgba(100, 116, 139, 0.14); }
.tile-text { display: flex; flex-direction: column; }
.tile-value { font-size: 19px; font-weight: 600; line-height: 1.2; }
.tile-label { font-size: 12px; opacity: 0.6; }
.tile-note { margin-inline-start: auto; font-size: 12px; font-weight: 600; opacity: 0.7; }

.pills { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 12px; }
.pill {
  display: inline-flex; align-items: center; gap: 6px; padding: 5px 12px; border-radius: 999px;
  border: 1px solid rgba(128, 128, 128, 0.25); background: transparent; color: inherit; font: inherit; font-size: 13px; cursor: pointer;
}
.pill.active { border-color: var(--app-primary, #6d28d9); font-weight: 600; }
.pill-count { font-size: 11.5px; padding: 0 7px; border-radius: 999px; background: rgba(128, 128, 128, 0.14); }

.filters { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 12px; align-items: center; }

.person {
  display: flex; align-items: center; gap: 10px; padding: 0; border: 0; background: none;
  color: inherit; font: inherit; text-align: start; cursor: pointer; min-width: 220px;
}
.person-text { min-width: 0; }
.person-name { display: flex; align-items: center; gap: 6px; font-weight: 500; }
.person:hover .person-name { color: var(--app-primary, #6d28d9); }
.person-sub { display: block; font-size: 12px; opacity: 0.6; }
.hot { color: #dc2626; }
.stale { color: #94a3b8; margin-inline-start: 6px; vertical-align: -2px; }
.status { display: inline-block; padding: 2px 10px; border-radius: 999px; font-size: 12px; font-weight: 600; }
.score { display: inline-block; min-width: 34px; padding: 2px 8px; border-radius: 999px; font-size: 12.5px; font-weight: 700; text-align: center; }
.src { display: inline-flex; align-items: center; gap: 6px; }
.owner { display: inline-flex; align-items: center; gap: 6px; }
.tags { display: inline-flex; flex-wrap: wrap; gap: 4px; }
.tag { padding: 1px 8px; border-radius: 6px; font-size: 11.5px; font-weight: 500; }
.tag-action { margin-bottom: 12px; }
.next { display: inline-flex; align-items: center; gap: 4px; font-size: 12.5px; max-width: 220px; }
.next-subj { opacity: 0.6; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.next.late { color: #dc2626; font-weight: 600; }
.next.none { color: #d97706; }
</style>
