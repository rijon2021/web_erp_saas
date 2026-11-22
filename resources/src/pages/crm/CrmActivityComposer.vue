<template>
  <div class="composer">
    <!-- Kind -->
    <div class="types">
      <button
        v-for="ty in types" :key="ty.value" type="button" class="type"
        :class="{ on: form.type === ty.value }" :style="form.type === ty.value ? { color: ty.color, borderColor: ty.color, background: tint(ty.color, 0.1) } : {}"
        @click="setType(ty.value)"
      >
        <component :is="ty.icon" :size="15" /> {{ ty.label }}
      </button>
    </div>

    <a-segmented v-model:value="form.status" :options="modeOptions" size="small" class="mode" />

    <a-input v-model:value="form.subject" :maxlength="191" :placeholder="subjectPlaceholder" class="gap" />

    <!-- When -->
    <div class="row">
      <template v-if="form.status === 'open'">
        <a-date-picker
          v-model:value="form.due_at" show-time format="YYYY-MM-DD HH:mm" :value-format="DT" :placeholder="$t('Crm_Due')"
          :minute-step="5" class="grow" :allow-clear="false"
        />
        <div class="quick">
          <a-button size="small" @click="quickDue(0, 1)">{{ $t('Crm_In_1_Hour') }}</a-button>
          <a-button size="small" @click="quickDue(1)">{{ $t('Crm_Tomorrow') }}</a-button>
          <a-button size="small" @click="quickDue(7)">{{ $t('Crm_Next_Week') }}</a-button>
        </div>
      </template>
      <a-date-picker
        v-else v-model:value="form.done_at" show-time format="YYYY-MM-DD HH:mm" :value-format="DT"
        :placeholder="$t('Crm_Done_Now')" class="grow" :disabled-date="d => d && d.isAfter(dayjs(), 'day')"
      />
    </div>

    <!-- Call outcome -->
    <div v-if="form.type === 'call' && form.status === 'done'" class="chips">
      <span class="lbl">{{ $t('Crm_Outcome') }}</span>
      <a-tag
        v-for="o in outcomes" :key="o.value" :color="form.outcome === o.value ? o.color : undefined" class="chip"
        @click="form.outcome = form.outcome === o.value ? null : o.value"
      >{{ o.label }}</a-tag>
    </div>

    <!-- Meeting -->
    <div v-if="form.type === 'meeting'" class="row">
      <a-select v-model:value="form.duration_minutes" :options="durations" :placeholder="$t('Crm_Duration')" allow-clear style="width: 150px" />
      <a-input v-model:value="form.location" :maxlength="191" :placeholder="$t('Crm_Location')" class="grow" />
    </div>

    <!-- Planned: reminder / priority / who -->
    <div v-if="form.status === 'open'" class="row">
      <a-select v-model:value="form.reminder_minutes" :options="reminderOptions" style="width: 190px">
        <template #suffixIcon><BellOutlined /></template>
      </a-select>
      <a-select v-model:value="form.priority" :options="priorityOptions" style="width: 120px" />
      <a-select
        v-if="meta.can?.view_all" v-model:value="form.owner_id" :options="ownerOptions" show-search option-filter-prop="label"
        :placeholder="$t('Crm_Assigned_To')" class="grow"
      />
    </div>

    <a-textarea v-model:value="form.description" :rows="compact ? 2 : 3" :maxlength="10000" :placeholder="$t('Crm_Activity_Details')" class="gap" />

    <div class="actions">
      <a-button v-if="activity || cancellable" @click="$emit('cancel')">{{ $t('Cancel') }}</a-button>
      <a-button type="primary" :loading="saving" @click="save">
        <template #icon><CheckOutlined v-if="form.status === 'done'" /><ClockCircleOutlined v-else /></template>
        {{ activity ? $t('Save') : (form.status === 'done' ? $t('Crm_Log_It') : $t('Crm_Plan_It')) }}
      </a-button>
    </div>
  </div>
</template>

<script setup>
/**
 * Log or plan a CRM activity — POST crm/activities (PUT when `activity` is
 * given). Link it with leadId / dealId / clientId; emits `saved`.
 */
import { ref, computed, onMounted, watch } from 'vue';
import dayjs from 'dayjs';
import { message } from 'ant-design-vue';
import { CheckOutlined, ClockCircleOutlined, BellOutlined } from '@ant-design/icons-vue';
import http from '../../lib/http';
import { t } from '../../i18n';
import { ACTIVITY_TYPES, CALL_OUTCOMES, PRIORITIES, REMINDERS, tr, tint } from './crmOptions';

const props = defineProps({
  leadId: { type: [Number, String], default: null },
  dealId: { type: [Number, String], default: null },
  clientId: { type: [Number, String], default: null },
  activity: { type: Object, default: null },
  defaultType: { type: String, default: 'call' },
  compact: { type: Boolean, default: false },
  cancellable: { type: Boolean, default: false },
});
const emit = defineEmits(['saved', 'cancel']);

const DT = 'YYYY-MM-DD[T]HH:mm:ssZ';
const saving = ref(false);
// Shared across instances on a page.
const meta = ref(window.__crmActivityMeta || { owners: [], can: {}, default_reminder_minutes: 15 });

const types = computed(() => tr(ACTIVITY_TYPES));
const outcomes = computed(() => tr(CALL_OUTCOMES));
const priorityOptions = computed(() => tr(PRIORITIES).map(p => ({ value: p.value, label: p.label })));
const reminderOptions = computed(() => tr(REMINDERS).map(r => ({ value: r.value, label: r.label })));
const ownerOptions = computed(() => meta.value.owners.map(o => ({ value: o.id, label: o.name })));
const modeOptions = computed(() => [
  { value: 'done', label: t('Crm_Log_Done', 'Log what happened') },
  { value: 'open', label: t('Crm_Plan_Ahead', 'Plan it') },
]);
const durations = [15, 30, 45, 60, 90, 120, 180].map(m => ({ value: m, label: m < 60 ? `${m} min` : `${m / 60} h` }));
const subjectPlaceholder = computed(() => ({
  call: t('Crm_Ph_Call', 'e.g. Discuss the quote'),
  meeting: t('Crm_Ph_Meeting', 'e.g. Product demo at their office'),
  task: t('Crm_Ph_Task', 'e.g. Send the catalogue'),
  email: t('Crm_Ph_Email', 'e.g. Sent the price list'),
  note: t('Crm_Ph_Note', 'Short title for the note'),
}[form.value.type]));

function blank(type) {
  const ty = ACTIVITY_TYPES.find(x => x.value === type) || ACTIVITY_TYPES[0];
  return {
    type: ty.value, status: ty.mode, subject: '', description: '', priority: 'normal', outcome: null,
    due_at: dayjs().add(1, 'day').hour(9).minute(0).second(0).format(DT), done_at: null,
    duration_minutes: ty.value === 'meeting' ? 60 : null, location: '',
    reminder_minutes: meta.value.default_reminder_minutes ?? null, owner_id: meta.value.me,
  };
}
const form = ref(blank(props.defaultType));

function fill(a) {
  form.value = a
    ? {
      type: a.type, status: a.status, subject: a.subject, description: a.description || '', priority: a.priority || 'normal',
      outcome: a.outcome, due_at: a.due_at ? dayjs(a.due_at).format(DT) : dayjs().add(1, 'day').hour(9).minute(0).second(0).format(DT),
      done_at: a.done_at ? dayjs(a.done_at).format(DT) : null, duration_minutes: a.duration_minutes, location: a.location || '',
      reminder_minutes: a.reminder_minutes, owner_id: a.owner?.id,
    }
    : blank(props.defaultType);
}
watch(() => props.activity, fill);

function setType(type) {
  const ty = ACTIVITY_TYPES.find(x => x.value === type);
  form.value.type = type;
  if (!props.activity) form.value.status = ty.mode;
  if (type === 'meeting' && !form.value.duration_minutes) form.value.duration_minutes = 60;
}
function quickDue(days, hours = 0) {
  const base = hours ? dayjs().add(hours, 'hour').minute(0) : dayjs().add(days, 'day').hour(9).minute(0);
  form.value.due_at = base.second(0).format(DT);
}

async function save() {
  const f = form.value;
  if (!f.subject.trim()) {
    f.subject = types.value.find(x => x.value === f.type)?.label || '';
  }
  if (f.status === 'open' && !f.due_at) { message.error(t('Crm_Due_Required', 'Choose when it is due')); return; }
  saving.value = true;
  const body = {
    type: f.type, subject: f.subject, description: f.description, status: f.status, priority: f.priority,
    due_at: f.status === 'open' ? f.due_at : '', done_at: f.status === 'done' ? (f.done_at || '') : '',
    duration_minutes: f.type === 'meeting' ? (f.duration_minutes || '') : '', location: f.type === 'meeting' ? f.location : '',
    outcome: f.type === 'call' && f.status === 'done' ? (f.outcome || '') : '',
    reminder_minutes: f.status === 'open' ? (f.reminder_minutes ?? '') : '',
    owner_id: f.owner_id || '',
    crm_lead_id: props.activity ? (props.activity.crm_lead_id || '') : (props.leadId || ''),
    crm_deal_id: props.activity ? (props.activity.crm_deal_id || '') : (props.dealId || ''),
    client_id: props.activity ? (props.activity.client_id || '') : (props.clientId || ''),
  };
  try {
    if (props.activity) await http.put(`crm/activities/${props.activity.id}`, body);
    else await http.post('crm/activities', body);
    message.success(f.status === 'done' ? t('Crm_Logged', 'Logged') : t('Crm_Planned', 'Planned'));
    if (!props.activity) {
      const keepType = f.type;
      form.value = blank(keepType);
    }
    emit('saved');
  } catch (e) {
    const errors = e?.data?.errors;
    message.error((errors && Object.values(errors)[0]?.[0]) || e?.data?.message || t('Gen_Could_Not_Save', 'Could not save'));
  } finally {
    saving.value = false;
  }
}

onMounted(async () => {
  if (!window.__crmActivityMeta) {
    try {
      window.__crmActivityMeta = await http.get('crm/activities/meta');
      meta.value = window.__crmActivityMeta;
    } catch (e) { /* defaults stay */ }
  }
  fill(props.activity);
});
</script>

<style scoped>
.composer { display: flex; flex-direction: column; gap: 10px; }
.types { display: flex; flex-wrap: wrap; gap: 6px; }
.type {
  display: inline-flex; align-items: center; gap: 6px; padding: 5px 12px; border-radius: 999px;
  border: 1px solid rgba(128, 128, 128, 0.25); background: transparent; color: inherit; font: inherit; font-size: 13px; cursor: pointer;
}
.type.on { font-weight: 600; }
.mode { align-self: flex-start; }
.row { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
.grow { flex: 1; min-width: 180px; }
.quick { display: flex; gap: 4px; flex-wrap: wrap; }
.chips { display: flex; flex-wrap: wrap; gap: 4px; align-items: center; }
.chip { cursor: pointer; user-select: none; }
.lbl { font-size: 12.5px; opacity: 0.65; margin-inline-end: 4px; }
.actions { display: flex; justify-content: flex-end; gap: 8px; }
</style>
