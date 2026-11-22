<template>
  <div class="page">
    <PageHeader :title="$t('Crm_My_Day')" :subtitle="todayText" :breadcrumb="[$t('Crm'), $t('Crm_My_Day')]">
      <template #actions>
        <a-select
          v-if="meta.can?.view_all" v-model:value="ownerId" :options="ownerOptions" show-search option-filter-prop="label"
          style="width: 200px" @change="load"
        />
        <a-button @click="$router.push('/crm/activities')"><template #icon><UnorderedListOutlined /></template>{{ $t('Crm_All_Activities') }}</a-button>
        <a-button v-if="canAdd" type="primary" @click="openNew()"><template #icon><PlusOutlined /></template>{{ $t('Crm_New_Activity') }}</a-button>
      </template>
    </PageHeader>

    <a-spin :spinning="loading">
      <div class="tiles">
        <div class="tile" :class="{ bad: data.counts?.overdue }">
          <span class="t-ic" style="color:#dc2626;background:rgba(220,38,38,.12)"><AlarmClock :size="18" /></span>
          <span class="t-text"><span class="t-value">{{ data.counts?.overdue || 0 }}</span><span class="t-label">{{ $t('Crm_Overdue') }}</span></span>
        </div>
        <div class="tile">
          <span class="t-ic" style="color:#f59e0b;background:rgba(245,158,11,.14)"><Sun :size="18" /></span>
          <span class="t-text"><span class="t-value">{{ data.counts?.today || 0 }}</span><span class="t-label">{{ $t('Crm_Due_Today') }}</span></span>
        </div>
        <div class="tile">
          <span class="t-ic" style="color:#6366f1;background:rgba(99,102,241,.13)"><CalendarRange :size="18" /></span>
          <span class="t-text"><span class="t-value">{{ data.counts?.upcoming || 0 }}</span><span class="t-label">{{ $t('Crm_Next_7_Days') }}</span></span>
        </div>
        <div class="tile ok">
          <span class="t-ic" style="color:#16a34a;background:rgba(22,163,74,.13)"><CircleCheck :size="18" /></span>
          <span class="t-text"><span class="t-value">{{ data.counts?.done_today || 0 }}</span><span class="t-label">{{ $t('Crm_Done_Today') }}</span></span>
        </div>
      </div>

      <a-row :gutter="16">
        <a-col :xs="24" :lg="16">
          <a-card v-if="(data.overdue || []).length" :bordered="false" class="card late-card">
            <div class="card-title red"><AlarmClock :size="16" /> {{ $t('Crm_Overdue') }}</div>
            <CrmActivityFeed :activities="data.overdue" plain show-related :can-edit="canEdit" :can-delete="canDelete" @changed="load" />
          </a-card>

          <a-card :bordered="false" class="card">
            <div class="card-title"><Sun :size="16" /> {{ $t('Crm_Today') }}</div>
            <CrmActivityFeed :activities="data.today || []" plain show-related :can-edit="canEdit" :can-delete="canDelete"
              :empty-text="$t('Crm_Nothing_Due_Today')" @changed="load" />
          </a-card>

          <a-card :bordered="false" class="card">
            <div class="card-title"><CalendarRange :size="16" /> {{ $t('Crm_Next_7_Days') }}</div>
            <template v-if="(data.upcoming || []).length">
              <div v-for="g in upcomingByDay" :key="g.day" class="day-group">
                <div class="day">{{ g.label }}</div>
                <CrmActivityFeed :activities="g.items" plain show-related :can-edit="canEdit" :can-delete="canDelete" @changed="load" />
              </div>
            </template>
            <a-empty v-else :image="simpleImage" :description="$t('Crm_Nothing_Planned')" />
          </a-card>

          <a-card v-if="(data.done_today || []).length" :bordered="false" class="card">
            <div class="card-title green"><CircleCheck :size="16" /> {{ $t('Crm_Done_Today') }}</div>
            <CrmActivityFeed :activities="data.done_today" plain show-related :can-edit="canEdit" :can-delete="canDelete" @changed="load" />
          </a-card>
        </a-col>

        <a-col :xs="24" :lg="8">
          <a-card :bordered="false" class="card">
            <div class="card-title amber"><TriangleAlert :size="16" /> {{ $t('Crm_Deals_No_Next_Step') }}</div>
            <div v-if="(data.deals_without_next_step || []).length" class="nn">
              <div v-for="d in data.deals_without_next_step" :key="d.id" class="nn-row">
                <router-link :to="`/crm/deals/${d.id}`" class="nn-title">
                  {{ d.title }}
                  <small>{{ money(d.value) }}<template v-if="d.stage"> · {{ d.stage.name }}</template></small>
                </router-link>
                <a-button v-if="canAdd" size="small" @click="openNew(d)">{{ $t('Crm_Plan') }}</a-button>
              </div>
            </div>
            <div v-else class="all-good"><CircleCheck :size="16" /> {{ $t('Crm_All_Deals_Planned') }}</div>
          </a-card>
        </a-col>
      </a-row>
    </a-spin>

    <a-modal v-model:open="newOpen" :title="newDeal ? `${$t('Crm_Plan_Next_Step')} — ${newDeal.title}` : $t('Crm_New_Activity')" :footer="null" destroy-on-close width="620px">
      <CrmActivityComposer :deal-id="newDeal?.id || null" :default-type="newDeal ? 'call' : 'task'" cancellable @saved="afterNew" @cancel="newOpen = false" />
    </a-modal>
  </div>
</template>

<script setup>
/** One person's day — GET crm/activities/my-day. */
import { ref, computed, onMounted } from 'vue';
import dayjs from 'dayjs';
import { message, Empty } from 'ant-design-vue';
import { PlusOutlined, UnorderedListOutlined } from '@ant-design/icons-vue';
import { AlarmClock, Sun, CalendarRange, CircleCheck, TriangleAlert } from 'lucide-vue-next';
import PageHeader from '../../components/PageHeader.vue';
import CrmActivityFeed from './CrmActivityFeed.vue';
import CrmActivityComposer from './CrmActivityComposer.vue';
import { useFormat } from '../../composables/useFormat';
import { useAuthStore } from '../../stores/auth';
import http from '../../lib/http';
import { t } from '../../i18n';

const auth = useAuthStore();
const { money } = useFormat();
const simpleImage = Empty.PRESENTED_IMAGE_SIMPLE;
const canAdd = computed(() => auth.can('crm_activities_add'));
const canEdit = computed(() => auth.can('crm_activities_edit'));
const canDelete = computed(() => auth.can('crm_activities_delete'));

const loading = ref(false);
const data = ref({});
const meta = ref({ owners: [], can: {} });
const ownerId = ref(undefined);
const ownerOptions = computed(() => meta.value.owners.map(o => ({ value: o.id, label: o.name })));
const todayText = computed(() => new Date().toLocaleDateString(undefined, { weekday: 'long', day: 'numeric', month: 'long' }));

const upcomingByDay = computed(() => {
  const groups = {};
  for (const a of data.value.upcoming || []) {
    const key = dayjs(a.due_at).format('YYYY-MM-DD');
    (groups[key] = groups[key] || []).push(a);
  }
  return Object.keys(groups).sort().map(day => {
    const d = dayjs(day);
    const label = d.isSame(dayjs().add(1, 'day'), 'day') ? t('Crm_Tomorrow', 'Tomorrow') : d.toDate().toLocaleDateString(undefined, { weekday: 'long', day: 'numeric', month: 'short' });
    return { day, label, items: groups[day] };
  });
});

async function load() {
  loading.value = true;
  try {
    data.value = await http.get('crm/activities/my-day', { owner: ownerId.value || '' });
    if (!ownerId.value) ownerId.value = data.value.owner_id;
  } catch (e) {
    message.error(t('Crm_Err_Could_Not_Load_Your_Day', 'Could not load your day'));
  } finally {
    loading.value = false;
  }
}

const newOpen = ref(false);
const newDeal = ref(null);
function openNew(deal = null) { newDeal.value = deal; newOpen.value = true; }
function afterNew() { newOpen.value = false; load(); }

onMounted(async () => {
  try { meta.value = await http.get('crm/activities/meta'); } catch (e) { /* no owner switch */ }
  load();
});
</script>

<style scoped>
.card { margin-bottom: 16px; border-radius: 14px; }
.card-title { display: flex; align-items: center; gap: 8px; font-weight: 600; font-size: 15px; margin-bottom: 12px; }
.card-title.red { color: #dc2626; }
.card-title.green { color: #16a34a; }
.card-title.amber { color: #d97706; }
.late-card { box-shadow: inset 3px 0 0 #dc2626; }
.tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; margin-bottom: 16px; }
.tile { display: flex; align-items: center; gap: 12px; padding: 12px 16px; border: 1px solid rgba(128, 128, 128, 0.18); border-radius: 14px; }
.tile.bad { border-color: rgba(220, 38, 38, 0.4); }
.t-ic { width: 40px; height: 40px; flex: none; display: inline-flex; align-items: center; justify-content: center; border-radius: 12px; }
.t-text { display: flex; flex-direction: column; }
.t-value { font-size: 21px; font-weight: 700; line-height: 1.2; }
.t-label { font-size: 12px; opacity: 0.6; }
.day-group + .day-group { margin-top: 12px; }
.day { font-size: 12px; font-weight: 700; text-transform: capitalize; opacity: 0.6; margin-bottom: 4px; }
.nn { display: flex; flex-direction: column; }
.nn-row { display: flex; align-items: center; gap: 8px; padding: 8px 0; border-bottom: 1px solid rgba(128, 128, 128, 0.12); }
.nn-row:last-child { border-bottom: 0; }
.nn-title { flex: 1; min-width: 0; color: inherit; font-weight: 500; }
.nn-title small { display: block; font-weight: 400; opacity: 0.6; }
.nn-title:hover { color: var(--app-primary, #6d28d9); }
.all-good { display: flex; align-items: center; gap: 6px; color: #16a34a; }
</style>
