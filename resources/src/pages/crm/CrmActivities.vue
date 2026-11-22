<template>
  <div class="page">
    <PageHeader :title="$t('Crm_Activities')" :breadcrumb="[$t('Crm'), $t('Crm_Activities')]">
      <template #actions>
        <a-segmented v-model:value="view" :options="viewOptions" />
        <a-button @click="$router.push('/crm/my-day')"><template #icon><CalendarOutlined /></template>{{ $t('Crm_My_Day') }}</a-button>
        <a-button v-if="canAdd" type="primary" @click="newOpen = true"><template #icon><PlusOutlined /></template>{{ $t('Crm_New_Activity') }}</a-button>
      </template>
    </PageHeader>

    <div class="filters">
      <a-segmented v-if="view === 'list'" v-model:value="status" :options="statusOptions" @change="reload" />
      <a-select v-model:value="type" :options="typeOptions" :placeholder="$t('Crm_Type')" allow-clear style="width: 140px" @change="reload" />
      <a-select v-model:value="owner" :options="ownerOptions" :placeholder="$t('Crm_Assigned_To')" allow-clear style="width: 180px" @change="reload" />
      <a-range-picker v-if="view === 'list'" v-model:value="range" value-format="YYYY-MM-DD" @change="reload" />
    </div>

    <!-- List -->
    <DataTable v-if="view === 'list'" :crud="crud" :columns="columns">
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'subject'">
          <div class="subj">
            <a-tooltip :title="record.status === 'open' ? $t('Crm_Mark_Done') : $t('Crm_Reopen')">
              <a-checkbox :checked="record.status === 'done'" :disabled="!canEdit || record.can_manage === false" @change="toggle(record)" />
            </a-tooltip>
            <span class="ic" :style="icStyle(record)"><component :is="typeOf(record).icon" :size="14" /></span>
            <span class="subj-text">
              <span class="subj-title" :class="{ struck: record.status === 'done' }">{{ record.subject }}</span>
              <span class="subj-sub">
                {{ labelOf(ACTIVITY_TYPES, record.type) }}
                <template v-if="record.outcome"> · {{ labelOf(CALL_OUTCOMES, record.outcome) }}</template>
                <template v-if="record.priority === 'high'"> · <b class="hi">{{ $t('Crm_Priority_High') }}</b></template>
              </span>
            </span>
          </div>
        </template>
        <template v-else-if="column.key === 'related'">
          <router-link v-if="record.deal" :to="`/crm/deals/${record.deal.id}`">{{ record.deal.title }}</router-link>
          <router-link v-else-if="record.lead" :to="`/crm/leads/${record.lead.id}`">{{ record.lead.name }}</router-link>
          <router-link v-else-if="record.client" :to="`/crm/customers/${record.client.id}`">{{ record.client.name }}</router-link>
          <span v-else class="muted">—</span>
        </template>
        <template v-else-if="column.key === 'when'">
          <span v-if="record.status === 'open'" :class="{ late: record.is_overdue }">{{ dueLabel(record.due_at, dateTime) }}</span>
          <span v-else class="muted">{{ $t('Crm_Done') }} · {{ dateTime(record.done_at) }}</span>
        </template>
        <template v-else-if="column.key === 'owner'">
          <span v-if="record.owner" class="owner">
            <a-avatar :size="22" :style="{ background: avatarColor(record.owner.name), fontSize: '10px' }">{{ initials(record.owner.name) }}</a-avatar>
            {{ record.owner.name }}
          </span>
        </template>
        <template v-else-if="column.key === 'actions'">
          <a-space v-if="record.can_manage !== false" :size="0">
            <a-button v-if="canEdit" type="text" size="small" @click="edit(record)"><template #icon><EditOutlined /></template></a-button>
            <a-button v-if="canDelete" type="text" size="small" danger @click="crud.remove(record, { label: record.subject })"><template #icon><DeleteOutlined /></template></a-button>
          </a-space>
        </template>
      </template>
    </DataTable>

    <!-- Calendar -->
    <a-card v-else :bordered="false" class="card">
      <a-row :gutter="16">
        <a-col :xs="24" :lg="16">
          <a-spin :spinning="calLoading">
            <a-calendar v-model:value="calDay" @panel-change="onPanel">
              <template #dateCellRender="{ current }">
                <ul class="cal-events">
                  <li v-for="a in onDay(current).slice(0, 3)" :key="a.id" :class="{ done: a.status === 'done', late: a.is_overdue }">
                    <span class="cal-dot" :style="{ background: typeOf(a).color }"></span>{{ a.subject }}
                  </li>
                  <li v-if="onDay(current).length > 3" class="more">+{{ onDay(current).length - 3 }}</li>
                </ul>
              </template>
            </a-calendar>
          </a-spin>
        </a-col>
        <a-col :xs="24" :lg="8">
          <div class="day-title">{{ calDay.toDate().toLocaleDateString(undefined, { weekday: 'long', day: 'numeric', month: 'long' }) }}</div>
          <CrmActivityFeed :activities="onDay(calDay)" plain show-related :can-edit="canEdit" :can-delete="canDelete"
            :empty-text="$t('Crm_Nothing_This_Day')" @changed="loadCalendar(true)" />
        </a-col>
      </a-row>
    </a-card>

    <a-modal v-model:open="newOpen" :title="$t('Crm_New_Activity')" :footer="null" destroy-on-close width="620px">
      <CrmActivityComposer default-type="task" cancellable @saved="afterSave" @cancel="newOpen = false" />
    </a-modal>
    <a-modal v-model:open="editOpen" :title="$t('Crm_Edit_Activity')" :footer="null" destroy-on-close width="620px">
      <CrmActivityComposer :activity="editing" @saved="afterSave" @cancel="editOpen = false" />
    </a-modal>
  </div>
</template>

<script setup>
/** All activities — list (GET crm/activities) or month calendar (GET crm/activities/calendar). */
import { ref, computed, onMounted, watch } from 'vue';
import { useRoute } from 'vue-router';
import dayjs from 'dayjs';
import { message } from 'ant-design-vue';
import { PlusOutlined, CalendarOutlined, EditOutlined, DeleteOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import CrmActivityFeed from './CrmActivityFeed.vue';
import CrmActivityComposer from './CrmActivityComposer.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import { useAuthStore } from '../../stores/auth';
import http from '../../lib/http';
import { t } from '../../i18n';
import { ACTIVITY_TYPES, CALL_OUTCOMES, tr, optionOf, labelOf, tint, initials, avatarColor, dueLabel } from './crmOptions';

const route = useRoute();
const auth = useAuthStore();
const { dateTime } = useFormat();
const canAdd = computed(() => auth.can('crm_activities_add'));
const canEdit = computed(() => auth.can('crm_activities_edit'));
const canDelete = computed(() => auth.can('crm_activities_delete'));

const meta = ref({ owners: [], can: {} });
const view = ref(route.query.view === 'calendar' ? 'calendar' : 'list');
const status = ref(route.query.status ?? 'open');
const type = ref(undefined);
const owner = ref(route.query.owner || undefined);
const range = ref(null);

const viewOptions = computed(() => [{ value: 'list', label: t('Crm_List_View', 'List') }, { value: 'calendar', label: t('Crm_Calendar', 'Calendar') }]);
const statusOptions = computed(() => [
  { value: 'open', label: t('Crm_Kind_Open', 'Open') },
  { value: 'overdue', label: t('Crm_Overdue', 'Overdue') },
  { value: 'today', label: t('Crm_Today', 'Today') },
  { value: 'week', label: t('Crm_Next_7_Days', 'Next 7 days') },
  { value: 'done', label: t('Crm_Done', 'Done') },
  { value: '', label: t('All', 'All') },
]);
const typeOptions = computed(() => tr(ACTIVITY_TYPES).map(x => ({ value: x.value, label: x.label })));
const ownerOptions = computed(() => [
  { value: 'me', label: t('Crm_Mine', 'Mine') },
  ...(meta.value.can?.view_all ? meta.value.owners.map(o => ({ value: String(o.id), label: o.name })) : []),
]);

const crud = useCrudTable('crm/activities', {
  rowsKey: 'activities',
  limit: 15,
  params: () => ({ status: status.value ?? '', type: type.value || '', owner: owner.value || '', from: range.value?.[0] || '', to: range.value?.[1] || '' }),
});

function typeOf(a) { return optionOf(ACTIVITY_TYPES, a.type); }
function icStyle(a) { const c = typeOf(a).color; return { color: c, background: tint(c, 0.13) }; }
function reload() { if (view.value === 'list') crud.reload(); else loadCalendar(true); }

async function toggle(a) {
  try {
    await http.post(`crm/activities/${a.id}/${a.status === 'open' ? 'complete' : 'reopen'}`);
    crud.fetchRows();
  } catch (e) {
    message.error(e?.data?.message || t('Gen_Could_Not_Update', 'Could not update'));
  }
}

// ----- calendar -----
const calDay = ref(dayjs());
const calRows = ref([]);
const calLoading = ref(false);
let loadedMonth = '';
let calSeq = 0;
async function loadCalendar(force = false) {
  const m = calDay.value.format('YYYY-MM');
  if (!force && m === loadedMonth) return;
  const seq = ++calSeq;
  calLoading.value = true;
  try {
    const res = await http.get('crm/activities/calendar', {
      start: calDay.value.startOf('month').subtract(7, 'day').format('YYYY-MM-DD'),
      end: calDay.value.endOf('month').add(14, 'day').format('YYYY-MM-DD'),
      type: type.value || '', owner: owner.value || '',
    });
    if (seq !== calSeq) return;
    calRows.value = res.activities || [];
    loadedMonth = m;
  } catch (e) {
    message.error(t('Crm_Err_Could_Not_Load_The_Calendar', 'Could not load the calendar'));
  } finally {
    calLoading.value = false;
  }
}
function onPanel(value) { calDay.value = value; loadCalendar(); }
function onDay(d) {
  const key = d.format('YYYY-MM-DD');
  return calRows.value.filter(a => dayjs(a.status === 'open' ? a.due_at : a.done_at).format('YYYY-MM-DD') === key);
}
watch(calDay, () => loadCalendar());
watch(view, (v) => { if (v === 'calendar') loadCalendar(true); else crud.fetchRows(); });

const newOpen = ref(false);
const editOpen = ref(false);
const editing = ref(null);
function edit(a) { editing.value = a; editOpen.value = true; }
function afterSave() { newOpen.value = false; editOpen.value = false; reload(); }

const columns = computed(() => [
  { title: t('Crm_Activity'), key: 'subject' },
  { title: t('Crm_Related_To'), key: 'related', width: 220 },
  { title: t('Crm_When'), key: 'when', width: 200 },
  { title: t('Crm_Assigned_To'), key: 'owner', width: 170 },
  { title: '', key: 'actions', width: 90 },
]);

onMounted(async () => {
  try { meta.value = await http.get('crm/activities/meta'); } catch (e) { /* filters stay short */ }
  if (view.value === 'list') crud.fetchRows(); else loadCalendar(true);
});
</script>

<style scoped>
.muted { color: rgba(128, 128, 128, 0.75); }
.card { border-radius: 14px; }
.filters { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 12px; align-items: center; }
.subj { display: flex; align-items: center; gap: 10px; min-width: 240px; }
.ic { width: 28px; height: 28px; flex: none; display: inline-flex; align-items: center; justify-content: center; border-radius: 8px; }
.subj-text { display: flex; flex-direction: column; min-width: 0; }
.subj-title { font-weight: 500; }
.subj-title.struck { text-decoration: line-through; opacity: 0.6; }
.subj-sub { font-size: 12px; opacity: 0.6; }
.hi { color: #dc2626; }
.late { color: #dc2626; font-weight: 600; }
.owner { display: inline-flex; align-items: center; gap: 6px; }
.cal-events { list-style: none; padding: 0; margin: 0; font-size: 11.5px; }
.cal-events li { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; line-height: 1.6; }
.cal-events li.done { opacity: 0.5; text-decoration: line-through; }
.cal-events li.late { color: #dc2626; }
.cal-events .more { opacity: 0.6; }
.cal-dot { display: inline-block; width: 6px; height: 6px; border-radius: 50%; margin-inline-end: 4px; vertical-align: 1px; }
.day-title { font-weight: 600; font-size: 15px; margin: 8px 0 12px; text-transform: capitalize; }
</style>
