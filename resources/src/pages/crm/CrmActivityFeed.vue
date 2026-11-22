<template>
  <div class="feed">
    <div v-if="planned.length" class="section">
      <div v-if="!plain" class="sec-title">{{ $t('Crm_Planned') }} <span class="count">{{ planned.length }}</span></div>
      <div v-for="a in planned" :key="a.id" class="item open" :class="{ late: a.is_overdue }">
        <a-checkbox :checked="false" :disabled="!manage(a)" @change="openComplete(a)" />
        <span class="ic" :style="icStyle(a)"><component :is="typeOf(a).icon" :size="14" /></span>
        <div class="body">
          <div class="line1">
            <span class="subject">{{ a.subject }}</span>
            <a-tag v-if="a.priority === 'high'" color="error" class="mini">{{ $t('Crm_Priority_High') }}</a-tag>
          </div>
          <div class="line2">
            <span class="due" :class="{ late: a.is_overdue }"><ClockCircleOutlined /> {{ dueLabel(a.due_at, dateTime) }}</span>
            <span v-if="a.duration_minutes">· {{ $t('Hosp_N_Min', { n: a.duration_minutes }) }}</span>
            <span v-if="a.location">· {{ a.location }}</span>
            <span v-if="a.owner">· {{ a.owner.name }}</span>
            <router-link v-if="showDeal && a.deal" :to="`/crm/deals/${a.deal.id}`">· {{ a.deal.title }}</router-link>
            <template v-if="showRelated">
              <router-link v-if="a.deal" :to="`/crm/deals/${a.deal.id}`" class="rel">· {{ a.deal.title }}</router-link>
              <router-link v-else-if="a.lead" :to="`/crm/leads/${a.lead.id}`" class="rel">· {{ a.lead.name }}</router-link>
              <router-link v-else-if="a.client" :to="`/crm/customers/${a.client.id}`" class="rel">· {{ a.client.name }}</router-link>
            </template>
          </div>
          <div v-if="a.description" class="desc">{{ a.description }}</div>
        </div>
        <a-space v-if="manage(a)" :size="0" class="tools">
          <a-button type="text" size="small" @click="edit(a)"><template #icon><EditOutlined /></template></a-button>
          <a-button v-if="canDelete" type="text" size="small" danger @click="remove(a)"><template #icon><DeleteOutlined /></template></a-button>
        </a-space>
      </div>
    </div>

    <div v-if="done.length" class="section">
      <div v-if="!plain" class="sec-title">{{ $t('Crm_Done_Activities') }} <span class="count">{{ done.length }}</span></div>
      <div v-for="a in done" :key="a.id" class="item">
        <span class="ic" :style="icStyle(a)"><component :is="typeOf(a).icon" :size="14" /></span>
        <div class="body">
          <div class="line1">
            <span class="subject">{{ a.subject }}</span>
            <a-tag v-if="a.outcome" :color="optionOf(CALL_OUTCOMES, a.outcome).color" class="mini">{{ labelOf(CALL_OUTCOMES, a.outcome) }}</a-tag>
          </div>
          <div class="line2">
            <a-tooltip :title="dateTime(a.done_at)"><span>{{ labelOf(ACTIVITY_TYPES, a.type) }} · {{ ago(a.done_at) }}</span></a-tooltip>
            <span v-if="a.owner">· {{ a.owner.name }}</span>
            <router-link v-if="showDeal && a.deal" :to="`/crm/deals/${a.deal.id}`">· {{ a.deal.title }}</router-link>
            <template v-if="showRelated">
              <router-link v-if="a.deal" :to="`/crm/deals/${a.deal.id}`" class="rel">· {{ a.deal.title }}</router-link>
              <router-link v-else-if="a.lead" :to="`/crm/leads/${a.lead.id}`" class="rel">· {{ a.lead.name }}</router-link>
              <router-link v-else-if="a.client" :to="`/crm/customers/${a.client.id}`" class="rel">· {{ a.client.name }}</router-link>
            </template>
          </div>
          <div v-if="a.description" class="desc">{{ a.description }}</div>
        </div>
        <a-space v-if="manage(a)" :size="0" class="tools">
          <a-tooltip :title="$t('Crm_Reopen')"><a-button type="text" size="small" @click="reopen(a)"><template #icon><RedoOutlined /></template></a-button></a-tooltip>
          <a-button type="text" size="small" @click="edit(a)"><template #icon><EditOutlined /></template></a-button>
          <a-button v-if="canDelete" type="text" size="small" danger @click="remove(a)"><template #icon><DeleteOutlined /></template></a-button>
        </a-space>
      </div>
    </div>

    <a-empty v-if="!activities.length" :image="simpleImage" :description="emptyText || $t('Crm_No_Activities')" />

    <!-- Complete -->
    <a-modal v-model:open="completeOpen" :title="$t('Crm_Mark_Done')" :ok-text="$t('Crm_Mark_Done')" :cancel-text="$t('Cancel')" :confirm-loading="busy" @ok="doComplete">
      <p class="cm-subject">{{ completing?.subject }}</p>
      <div v-if="completing?.type === 'call'" class="chips">
        <span class="lbl">{{ $t('Crm_Outcome') }}</span>
        <a-tag v-for="o in outcomes" :key="o.value" :color="cForm.outcome === o.value ? o.color : undefined" class="chip"
          @click="cForm.outcome = cForm.outcome === o.value ? null : o.value">{{ o.label }}</a-tag>
      </div>
      <a-textarea v-model:value="cForm.note" :rows="3" :maxlength="5000" :placeholder="$t('Crm_What_Happened')" class="gap" />
      <a-checkbox v-model:checked="cForm.plan" class="gap">{{ $t('Crm_Plan_Next_Step') }}</a-checkbox>
      <div v-if="cForm.plan" class="next">
        <a-select v-model:value="cForm.next_type" :options="typeOptions" style="width: 130px" />
        <a-input v-model:value="cForm.next_subject" :maxlength="191" :placeholder="$t('Crm_Next_Step_Subject')" class="grow" />
        <a-date-picker v-model:value="cForm.next_due" show-time format="YYYY-MM-DD HH:mm" :value-format="DT" :minute-step="5" style="width: 100%" />
      </div>
    </a-modal>

    <!-- Edit -->
    <a-modal v-model:open="editOpen" :title="$t('Crm_Edit_Activity')" :footer="null" destroy-on-close width="620px">
      <CrmActivityComposer :activity="editing" @saved="afterEdit" @cancel="editOpen = false" />
    </a-modal>
  </div>
</template>

<script setup>
/** Planned (tick to complete) and done activities of a lead / deal. Emits `changed`. */
import { ref, computed } from 'vue';
import dayjs from 'dayjs';
import { message, Modal, Empty } from 'ant-design-vue';
import { ClockCircleOutlined, EditOutlined, DeleteOutlined, RedoOutlined } from '@ant-design/icons-vue';
import CrmActivityComposer from './CrmActivityComposer.vue';
import { useFormat } from '../../composables/useFormat';
import http from '../../lib/http';
import { t } from '../../i18n';
import { ACTIVITY_TYPES, CALL_OUTCOMES, tr, optionOf, labelOf, tint, ago, dueLabel } from './crmOptions';

const props = defineProps({
  activities: { type: Array, default: () => [] },
  canDelete: { type: Boolean, default: false },
  canEdit: { type: Boolean, default: true },
  showDeal: { type: Boolean, default: false },
  showRelated: { type: Boolean, default: false },
  plain: { type: Boolean, default: false },
  emptyText: { type: String, default: '' },
});
const emit = defineEmits(['changed']);
const { dateTime } = useFormat();
const simpleImage = Empty.PRESENTED_IMAGE_SIMPLE;
const DT = 'YYYY-MM-DD[T]HH:mm:ssZ';

const planned = computed(() => props.activities.filter(a => a.status === 'open')
  .sort((a, b) => String(a.due_at || '').localeCompare(String(b.due_at || ''))));
const done = computed(() => props.activities.filter(a => a.status === 'done')
  .sort((a, b) => String(b.done_at || '').localeCompare(String(a.done_at || ''))));
const outcomes = computed(() => tr(CALL_OUTCOMES));
const typeOptions = computed(() => tr(ACTIVITY_TYPES).map(x => ({ value: x.value, label: x.label })));

function typeOf(a) { return optionOf(ACTIVITY_TYPES, a.type); }
function icStyle(a) { const c = typeOf(a).color || '#64748b'; return { color: c, background: tint(c, 0.13) }; }
function manage(a) { return props.canEdit && a.can_manage !== false; }

const completeOpen = ref(false);
const completing = ref(null);
const busy = ref(false);
const cForm = ref({});
function openComplete(a) {
  completing.value = a;
  cForm.value = {
    outcome: null, note: '', plan: false, next_type: a.type === 'note' ? 'task' : a.type,
    next_subject: '', next_due: dayjs().add(2, 'day').hour(9).minute(0).second(0).format(DT),
  };
  completeOpen.value = true;
}
async function doComplete() {
  busy.value = true;
  const f = cForm.value;
  try {
    await http.post(`crm/activities/${completing.value.id}/complete`, {
      outcome: f.outcome || '', note: f.note || '',
      next: f.plan && f.next_subject.trim() ? { type: f.next_type, subject: f.next_subject, due_at: f.next_due } : {},
    });
    completeOpen.value = false;
    emit('changed');
  } catch (e) {
    message.error(e?.data?.message || t('Gen_Could_Not_Update', 'Could not update'));
  } finally {
    busy.value = false;
  }
}

async function reopen(a) {
  try { await http.post(`crm/activities/${a.id}/reopen`); emit('changed'); } catch (e) { message.error(e?.data?.message || t('Gen_Could_Not_Update', 'Could not update')); }
}

const editOpen = ref(false);
const editing = ref(null);
function edit(a) { editing.value = a; editOpen.value = true; }
function afterEdit() { editOpen.value = false; emit('changed'); }

function remove(a) {
  Modal.confirm({
    title: t('Delete_Title', 'Are you sure?'),
    content: a.subject,
    okType: 'danger',
    okText: t('Delete_confirmButtonText', 'Yes, delete it!'),
    cancelText: t('Delete_cancelButtonText', 'Cancel'),
    async onOk() {
      try { await http.delete(`crm/activities/${a.id}`); emit('changed'); } catch (e) { message.error(e?.data?.message || t('Gen_Could_Not_Delete', 'Could not delete')); }
    },
  });
}
</script>

<style scoped>
.feed { display: flex; flex-direction: column; gap: 14px; }
.sec-title { font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; opacity: 0.6; margin-bottom: 6px; }
.count { font-weight: 600; padding: 0 6px; border-radius: 999px; background: rgba(128, 128, 128, 0.15); margin-inline-start: 4px; }
.item { display: flex; align-items: flex-start; gap: 10px; padding: 10px 4px; border-bottom: 1px solid rgba(128, 128, 128, 0.12); }
.item:last-child { border-bottom: 0; }
.item.open { padding-inline: 8px; border-radius: 10px; border: 1px solid rgba(128, 128, 128, 0.15); margin-bottom: 6px; }
.item.open.late { border-color: rgba(220, 38, 38, 0.35); background: rgba(220, 38, 38, 0.03); }
.ic { width: 28px; height: 28px; flex: none; display: inline-flex; align-items: center; justify-content: center; border-radius: 8px; }
.body { flex: 1; min-width: 0; }
.line1 { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
.subject { font-weight: 500; }
.line2 { font-size: 12px; opacity: 0.65; display: flex; flex-wrap: wrap; gap: 4px; margin-top: 2px; }
.line2 a { color: inherit; text-decoration: underline; }
.line2 a.rel { font-weight: 600; text-decoration: none; opacity: 1; }
.due.late { color: #dc2626; font-weight: 600; opacity: 1; }
.desc { white-space: pre-line; margin-top: 4px; font-size: 13px; opacity: 0.85; }
.mini { font-size: 11px; line-height: 18px; margin: 0; }
.tools { opacity: 0.5; }
.item:hover .tools { opacity: 1; }
.cm-subject { font-weight: 600; }
.chips { display: flex; flex-wrap: wrap; gap: 4px; align-items: center; margin-bottom: 10px; }
.chip { cursor: pointer; user-select: none; }
.lbl { font-size: 12.5px; opacity: 0.65; margin-inline-end: 4px; }
.gap { margin-top: 10px; }
.next { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 10px; }
.grow { flex: 1; min-width: 160px; }
</style>
