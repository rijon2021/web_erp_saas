<template>
  <div class="page">
    <PageHeader :title="$t('Crm_Deals')" :breadcrumb="[$t('Crm'), $t('Crm_Deals')]">
      <template #actions>
        <a-button @click="$router.push('/crm/pipeline')"><template #icon><AppstoreOutlined /></template>{{ $t('Crm_Board_View') }}</a-button>
        <a-button v-if="canAdd" type="primary" @click="$router.push('/crm/deals/create')"><template #icon><PlusOutlined /></template>{{ $t('Crm_New_Deal') }}</a-button>
      </template>
    </PageHeader>

    <div class="tiles">
      <div class="tile">
        <span class="tile-ic" style="color:#6366f1;background:rgba(99,102,241,.13)"><Briefcase :size="18" /></span>
        <span class="tile-text"><span class="tile-value">{{ money(summary.open_value || 0) }}</span><span class="tile-label">{{ summary.open || 0 }} {{ $t('Crm_Open_Deals_Lower') }}</span></span>
      </div>
      <div class="tile">
        <span class="tile-ic" style="color:#8b5cf6;background:rgba(139,92,246,.13)"><Scale :size="18" /></span>
        <span class="tile-text"><span class="tile-value">{{ money(summary.weighted || 0) }}</span><span class="tile-label">{{ $t('Crm_Weighted_Forecast') }}</span></span>
      </div>
      <div class="tile">
        <span class="tile-ic" style="color:#16a34a;background:rgba(22,163,74,.13)"><Trophy :size="18" /></span>
        <span class="tile-text"><span class="tile-value">{{ money(summary.won_month_value || 0) }}</span><span class="tile-label">{{ summary.won_month || 0 }} {{ $t('Crm_Won_This_Month_Lower') }}</span></span>
      </div>
      <button type="button" class="tile" :class="{ active: quick === 'overdue' }" @click="toggleQuick('overdue')">
        <span class="tile-ic" style="color:#dc2626;background:rgba(220,38,38,.12)"><CalendarX :size="18" /></span>
        <span class="tile-text"><span class="tile-value">{{ summary.overdue || 0 }}</span><span class="tile-label">{{ $t('Crm_Past_Close_Date') }}</span></span>
      </button>
    </div>

    <div class="filters">
      <a-segmented v-model:value="status" :options="statusOptions" @change="reload" />
      <a-select v-if="meta.pipelines.length > 1" v-model:value="pipeline" :options="pipelineOptions" :placeholder="$t('Crm_Pipeline')" allow-clear style="width: 170px" @change="reload" />
      <a-select v-model:value="owner" :options="ownerOptions" :placeholder="$t('Crm_Owner')" allow-clear style="width: 170px" @change="reload" />
      <a-select v-model:value="tag" :options="tagOptions" :placeholder="$t('Crm_Tag')" allow-clear show-search option-filter-prop="label" style="width: 150px" @change="reload" />
      <a-range-picker v-model:value="closeRange" value-format="YYYY-MM-DD" :placeholder="[$t('Crm_Close_From'), $t('Crm_Close_To')]" @change="reload" />
    </div>

    <DataTable :crud="crud" :columns="columns" :selectable="canDelete">
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'title'">
          <button type="button" class="deal" @click="open(record)">
            <span class="deal-title">{{ record.title }}</span>
            <span class="deal-sub">{{ record.deal_number }}<template v-if="record.contact"> · {{ record.contact }}</template></span>
          </button>
        </template>
        <template v-else-if="column.key === 'stage'">
          <a-tag v-if="record.status !== 'open'" :color="optionOf(DEAL_STATUSES, record.status).color">{{ labelOf(DEAL_STATUSES, record.status) }}</a-tag>
          <span v-else-if="record.stage" class="stage" :style="{ color: record.stage.color, background: tint(record.stage.color) }">{{ record.stage.name }} · {{ record.stage.probability }}%</span>
          <a-tooltip v-if="record.is_rotting" :title="$t('Crm_Rotting_Hint')"><Hourglass :size="13" class="rot" /></a-tooltip>
        </template>
        <template v-else-if="column.key === 'next'">
          <span v-if="record.next_activity" class="next" :class="{ late: record.next_activity.is_overdue }">
            <component :is="actIcon(record.next_activity.type)" :size="13" /> {{ dueLabel(record.next_activity.due_at, date) }}
            <span class="next-subj">{{ record.next_activity.subject }}</span>
          </span>
          <span v-else-if="record.status === 'open'" class="next none"><TriangleAlert :size="13" /> {{ $t('Crm_No_Next_Step_Short') }}</span>
          <span v-else class="muted">—</span>
        </template>
        <template v-else-if="column.key === 'value'">
          <span class="money">{{ money(record.value) }}</span>
          <span v-if="record.status === 'open'" class="weighted">{{ money(record.weighted) }}</span>
        </template>
        <template v-else-if="column.key === 'expected_close_date'">
          <span v-if="record.expected_close_date" :class="{ late: record.is_overdue }">{{ date(record.expected_close_date) }}</span>
          <span v-else class="muted">—</span>
        </template>
        <template v-else-if="column.key === 'owner'">
          <span v-if="record.owner" class="owner">
            <a-avatar :size="22" :style="{ background: avatarColor(record.owner.name), fontSize: '10px' }">{{ initials(record.owner.name) }}</a-avatar>
            {{ record.owner.name }}
          </span>
          <a-tag v-else color="warning">{{ $t('Crm_Unassigned') }}</a-tag>
        </template>
        <template v-else-if="column.key === 'actions'">
          <a-space :size="0">
            <a-button type="text" size="small" @click="open(record)"><template #icon><EyeOutlined /></template></a-button>
            <a-button v-if="canEdit" type="text" size="small" @click="$router.push(`/crm/deals/${record.id}/edit`)"><template #icon><EditOutlined /></template></a-button>
            <a-button v-if="canDelete" type="text" size="small" danger @click="crud.remove(record, { label: record.title })"><template #icon><DeleteOutlined /></template></a-button>
          </a-space>
        </template>
      </template>
    </DataTable>
  </div>
</template>

<script setup>
/** Deals as a list — GET crm/deals → { deals, totalRows, summary }. */
import { ref, computed, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { PlusOutlined, AppstoreOutlined, EyeOutlined, EditOutlined, DeleteOutlined } from '@ant-design/icons-vue';
import { Briefcase, Scale, Trophy, CalendarX, Hourglass, TriangleAlert } from 'lucide-vue-next';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import { useAuthStore } from '../../stores/auth';
import http from '../../lib/http';
import { t } from '../../i18n';
import { DEAL_STATUSES, ACTIVITY_TYPES, tr, optionOf, labelOf, initials, avatarColor, tint, dueLabel } from './crmOptions';

function actIcon(type) { return optionOf(ACTIVITY_TYPES, type).icon; }

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();
const { money, date } = useFormat();
const canAdd = computed(() => auth.can('crm_deals_add'));
const canEdit = computed(() => auth.can('crm_deals_edit'));
const canDelete = computed(() => auth.can('crm_deals_delete'));

const meta = ref({ pipelines: [], owners: [], tags: [], can: {} });
const q = route.query;
const status = ref(q.quick === 'overdue' ? '' : (q.status ?? 'open'));
const pipeline = ref(q.pipeline ? Number(q.pipeline) : undefined);
const owner = ref(q.owner || undefined);
const tag = ref(undefined);
const quick = ref(['overdue', 'closing_month'].includes(q.quick) ? q.quick : '');
const closeRange = ref(null);

const crud = useCrudTable('crm/deals', {
  rowsKey: 'deals',
  sortField: 'created_at',
  params: () => ({
    status: status.value ?? '', pipeline: pipeline.value || '', owner: owner.value || '', tag: tag.value || '',
    quick: quick.value, close_from: closeRange.value?.[0] || '', close_to: closeRange.value?.[1] || '',
  }),
});
const summary = computed(() => crud.payload.value?.summary || {});

const statusOptions = computed(() => [
  { value: 'open', label: t('Crm_Deal_Open', 'Open') },
  { value: 'won', label: t('Crm_Deal_Won', 'Won') },
  { value: 'lost', label: t('Crm_Deal_Lost', 'Lost') },
  { value: '', label: t('All', 'All') },
]);
const pipelineOptions = computed(() => meta.value.pipelines.map(p => ({ value: p.id, label: p.name })));
const ownerOptions = computed(() => [
  { value: 'me', label: t('Crm_My_Deals', 'My deals') },
  { value: 'none', label: t('Crm_Unassigned', 'Unassigned') },
  ...(meta.value.can?.view_all ? meta.value.owners.map(o => ({ value: String(o.id), label: o.name })) : []),
]);
const tagOptions = computed(() => meta.value.tags.map(tg => ({ value: tg.id, label: tg.name })));

function reload() { crud.reload(); }
function toggleQuick(v) { quick.value = quick.value === v ? '' : v; if (quick.value) status.value = ''; reload(); }
function open(d) { router.push(`/crm/deals/${d.id}`); }

const columns = computed(() => [
  { title: t('Crm_Deal'), key: 'title', dataIndex: 'title', sorter: true },
  { title: t('Crm_Stage'), key: 'stage', width: 190 },
  { title: t('Crm_Next_Step'), key: 'next', width: 200 },
  { title: t('Crm_Value'), key: 'value', dataIndex: 'value', sorter: true, width: 150, align: 'right' },
  { title: t('Crm_Expected_Close'), key: 'expected_close_date', dataIndex: 'expected_close_date', sorter: true, width: 140 },
  { title: t('Crm_Owner'), key: 'owner', width: 170 },
  { title: '', key: 'actions', width: 110 },
]);

onMounted(async () => {
  crud.fetchRows();
  try { meta.value = await http.get('crm/deals/meta'); } catch (e) { /* filters stay empty */ }
});
</script>

<style scoped>
.muted { color: rgba(128, 128, 128, 0.75); }
.tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; margin-bottom: 14px; }
.tile {
  display: flex; align-items: center; gap: 12px; padding: 12px 16px; border: 1px solid rgba(128, 128, 128, 0.2);
  border-radius: 14px; background: transparent; color: inherit; font: inherit; text-align: start;
}
button.tile { cursor: pointer; }
.tile.active { border-color: var(--app-primary, #6d28d9); background: rgba(var(--app-primary-rgb, 109, 40, 217), 0.06); }
.tile-ic { width: 38px; height: 38px; flex: none; display: inline-flex; align-items: center; justify-content: center; border-radius: 11px; }
.tile-text { display: flex; flex-direction: column; min-width: 0; }
.tile-value { font-size: 18px; font-weight: 700; line-height: 1.2; }
.tile-label { font-size: 12px; opacity: 0.6; }
.filters { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 12px; align-items: center; }
.deal { display: flex; flex-direction: column; padding: 0; border: 0; background: none; color: inherit; font: inherit; text-align: start; cursor: pointer; min-width: 200px; }
.deal-title { font-weight: 600; }
.deal:hover .deal-title { color: var(--app-primary, #6d28d9); }
.deal-sub { font-size: 12px; opacity: 0.6; }
.stage { display: inline-block; padding: 2px 10px; border-radius: 999px; font-size: 12px; font-weight: 600; }
.rot { color: #f59e0b; margin-inline-start: 6px; vertical-align: -2px; }
.money { display: block; font-weight: 600; }
.weighted { display: block; font-size: 11.5px; opacity: 0.55; }
.late { color: #dc2626; font-weight: 600; }
.owner { display: inline-flex; align-items: center; gap: 6px; }
.next { display: inline-flex; align-items: center; gap: 4px; font-size: 12.5px; max-width: 220px; }
.next-subj { opacity: 0.6; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.next.late { color: #dc2626; font-weight: 600; }
.next.none { color: #d97706; }
</style>
