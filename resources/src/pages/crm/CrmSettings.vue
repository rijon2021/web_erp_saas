<template>
  <div class="page">
    <PageHeader :title="$t('Crm_Settings')" :subtitle="$t('Crm_Settings_Subtitle')" :breadcrumb="[$t('Crm'), $t('Crm_Settings')]" />

    <a-spin :spinning="loading">
      <a-card :bordered="false" class="card">
        <a-tabs v-model:activeKey="tab">
          <!-- General -->
          <a-tab-pane key="general" :tab="$t('Crm_General')">
            <a-form layout="vertical" class="general">
              <a-row :gutter="16">
                <a-col :xs="24" :md="8">
                  <a-form-item :label="$t('Crm_Lead_Prefix')" :extra="`${(settings.lead_prefix || 'LD').toUpperCase()}-0001`">
                    <a-input v-model:value="settings.lead_prefix" :maxlength="10" />
                  </a-form-item>
                </a-col>
                <a-col :xs="24" :md="8">
                  <a-form-item :label="$t('Crm_Deal_Prefix')" :extra="`${(settings.deal_prefix || 'DL').toUpperCase()}-0001`">
                    <a-input v-model:value="settings.deal_prefix" :maxlength="10" />
                  </a-form-item>
                </a-col>
                <a-col :xs="24" :md="8">
                  <a-form-item :label="$t('Crm_Stale_After')" :extra="$t('Crm_Stale_After_Hint')">
                    <a-input-number v-model:value="settings.stale_lead_days" :min="1" :max="365" style="width: 100%" :addon-after="$t('Crm_Days')" />
                  </a-form-item>
                </a-col>
                <a-col :xs="24" :md="12">
                  <a-form-item :label="$t('Crm_New_Lead_Owner')">
                    <a-radio-group v-model:value="settings.default_owner" class="radios">
                      <a-radio value="creator">{{ $t('Crm_Owner_Creator') }}</a-radio>
                      <a-radio value="pool">{{ $t('Crm_Owner_Pool') }}</a-radio>
                      <a-radio value="rules">{{ $t('Crm_Owner_Rules') }}</a-radio>
                    </a-radio-group>
                  </a-form-item>
                </a-col>
                <a-col :xs="24" :md="12">
                  <a-form-item :label="$t('Crm_Duplicate_Check')">
                    <a-switch v-model:checked="settings.duplicate_check" />
                    <span class="hint">{{ $t('Crm_Duplicate_Check_Hint') }}</span>
                  </a-form-item>
                </a-col>
                <a-col :span="24"><div class="sub-title">{{ $t('Crm_Task_Reminders') }}</div></a-col>
                <a-col :xs="24" :md="8">
                  <a-form-item :label="$t('Crm_Default_Reminder')" :extra="$t('Crm_Default_Reminder_Hint')">
                    <a-select v-model:value="settings.default_reminder_minutes" :options="reminderOptions" />
                  </a-form-item>
                </a-col>
                <a-col :xs="24" :md="16">
                  <a-form-item :label="$t('Crm_Send_Reminders_By')">
                    <a-checkbox v-model:checked="settings.reminder_email">{{ $t('Crm_By_Email') }}</a-checkbox>
                    <!-- SaaS: no mobile push service, so the push channel is not offered. -->
                    <div class="hint block">{{ $t('Crm_Reminders_Cron_Hint') }}</div>
                  </a-form-item>
                </a-col>
              </a-row>
              <a-button type="primary" :loading="saving" :disabled="!loaded" @click="saveGeneral">
                <template #icon><SaveOutlined /></template>{{ $t('Save') }}
              </a-button>
            </a-form>
          </a-tab-pane>

          <!-- Pipelines & stages -->
          <a-tab-pane key="pipelines" :tab="$t('Crm_Pipelines')">
            <div class="list-head">
              <span class="hint">{{ $t('Crm_Pipelines_Hint') }}</span>
              <a-button type="primary" @click="openPipeline()"><template #icon><PlusOutlined /></template>{{ $t('Crm_New_Pipeline') }}</a-button>
            </div>
            <div class="pipes">
              <div v-for="p in pipelines" :key="p.id" class="pipe">
                <div class="pipe-head">
                  <span class="pipe-name">{{ p.name }}</span>
                  <a-tag v-if="p.is_default" color="purple">{{ $t('Crm_Default') }}</a-tag>
                  <span class="used">{{ tp('Crm_Deals_N', '{n} deals', { n: p.deals }) }}</span>
                  <a-space :size="0" class="pipe-actions">
                    <a-button type="text" size="small" @click="openPipeline(p)"><template #icon><EditOutlined /></template></a-button>
                    <a-button type="text" size="small" danger @click="removePipeline(p)"><template #icon><DeleteOutlined /></template></a-button>
                  </a-space>
                </div>
                <div class="rows">
                  <div v-for="(s, i) in p.stages" :key="s.id" class="row">
                    <div class="order">
                      <a-button type="text" size="small" :disabled="i === 0" @click="moveStage(p, i, -1)"><template #icon><UpOutlined /></template></a-button>
                      <a-button type="text" size="small" :disabled="i === p.stages.length - 1" @click="moveStage(p, i, 1)"><template #icon><DownOutlined /></template></a-button>
                    </div>
                    <span class="swatch" :style="{ background: s.color }"></span>
                    <span class="name">{{ s.name }}</span>
                    <span class="prob">{{ s.probability }}%</span>
                    <a-tooltip v-if="s.rot_days" :title="$t('Crm_Rot_Days_Hint')"><span class="rotd">⏳ {{ s.rot_days }}{{ $t('Crm_Days_Short') }}</span></a-tooltip>
                    <span class="used">{{ tp('Crm_Deals_N', '{n} deals', { n: s.deals }) }}</span>
                    <a-space :size="0">
                      <a-button type="text" size="small" @click="openStage(p, s)"><template #icon><EditOutlined /></template></a-button>
                      <a-button type="text" size="small" danger @click="removeStage(s)"><template #icon><DeleteOutlined /></template></a-button>
                    </a-space>
                  </div>
                  <a-button type="dashed" size="small" class="add-stage" @click="openStage(p)"><PlusOutlined /> {{ $t('Crm_Add_Stage') }}</a-button>
                </div>
              </div>
            </div>
          </a-tab-pane>

          <!-- Automation (phase 6) -->
          <a-tab-pane key="capture" :tab="$t('Crm_Lead_Capture')">
            <CrmAutomation section="capture" />
          </a-tab-pane>
          <a-tab-pane key="assignment" :tab="$t('Crm_Assignment_Rules')">
            <CrmAutomation section="assignment" :owner-mode="settings.default_owner" />
          </a-tab-pane>
          <a-tab-pane key="scoring" :tab="$t('Crm_Lead_Scoring')">
            <CrmAutomation section="scoring" />
          </a-tab-pane>

          <!-- Lookup lists -->
          <a-tab-pane v-for="L in lists" :key="L.type" :tab="L.title">
            <div class="list-head">
              <span class="hint">{{ L.hint }}</span>
              <a-button type="primary" @click="openForm(L)"><template #icon><PlusOutlined /></template>{{ $t('Crm_Add') }}</a-button>
            </div>
            <div class="rows">
              <div v-for="(row, i) in data[L.key]" :key="row.id" class="row">
                <div v-if="L.ordered" class="order">
                  <a-button type="text" size="small" :disabled="i === 0" @click="move(L, i, -1)"><template #icon><UpOutlined /></template></a-button>
                  <a-button type="text" size="small" :disabled="i === data[L.key].length - 1" @click="move(L, i, 1)"><template #icon><DownOutlined /></template></a-button>
                </div>
                <span v-if="L.colored" class="swatch" :style="{ background: row.color }"></span>
                <span class="name">{{ row.name }}</span>
                <a-tag v-if="L.type === 'statuses'" :color="optionOf(STATUS_KINDS, row.kind).color">{{ labelOf(STATUS_KINDS, row.kind) }}</a-tag>
                <a-tag v-if="row.is_default" color="purple">{{ $t('Crm_Starting_Status') }}</a-tag>
                <a-tag v-if="L.type === 'sources' && !row.is_active">{{ $t('Crm_Hidden') }}</a-tag>
                <span class="used">{{ tp('Crm_Used_N', '{n} leads', { n: row.used }) }}</span>
                <a-space :size="0">
                  <a-button type="text" size="small" @click="openForm(L, row)"><template #icon><EditOutlined /></template></a-button>
                  <a-button type="text" size="small" danger @click="remove(L, row)"><template #icon><DeleteOutlined /></template></a-button>
                </a-space>
              </div>
              <a-empty v-if="!data[L.key]?.length" :image="simpleImage" />
            </div>
          </a-tab-pane>
        </a-tabs>
      </a-card>
    </a-spin>

    <a-modal v-model:open="formOpen" :title="formTitle" :ok-text="$t('Save')" :cancel-text="$t('Cancel')" :confirm-loading="saving" @ok="saveForm">
      <a-form layout="vertical">
        <a-form-item :label="$t('Name')" required>
          <a-input v-model:value="form.name" :maxlength="160" @press-enter="saveForm" />
        </a-form-item>
        <a-form-item v-if="current?.colored" :label="$t('Crm_Colour')">
          <div class="swatches">
            <button v-for="c in SWATCHES" :key="c" type="button" class="sw" :class="{ on: form.color === c }" :style="{ background: c }" @click="form.color = c"></button>
            <input v-model="form.color" type="color" class="sw-input" />
          </div>
        </a-form-item>
        <template v-if="current?.type === 'statuses'">
          <a-form-item :label="$t('Crm_Status_Kind')" :extra="$t('Crm_Status_Kind_Hint')">
            <a-radio-group v-model:value="form.kind" :options="kindOptions" option-type="button" />
          </a-form-item>
          <a-checkbox v-model:checked="form.is_default" :disabled="form.kind !== 'open'">{{ $t('Crm_Starting_Status_Hint') }}</a-checkbox>
        </template>
        <a-checkbox v-if="current?.type === 'sources'" v-model:checked="form.is_active">{{ $t('Crm_Source_Active') }}</a-checkbox>
      </a-form>
    </a-modal>

    <a-modal v-model:open="pipeOpen" :title="pipeForm.id ? $t('Crm_Edit_Pipeline') : $t('Crm_New_Pipeline')" :ok-text="$t('Save')" :cancel-text="$t('Cancel')" :confirm-loading="saving" @ok="savePipeline">
      <a-form layout="vertical">
        <a-form-item :label="$t('Name')" required><a-input v-model:value="pipeForm.name" :maxlength="120" @press-enter="savePipeline" /></a-form-item>
        <a-checkbox v-model:checked="pipeForm.is_default">{{ $t('Crm_Default_Pipeline_Hint') }}</a-checkbox>
        <div v-if="!pipeForm.id" class="hint top">{{ $t('Crm_New_Pipeline_Stages_Hint') }}</div>
      </a-form>
    </a-modal>

    <a-modal v-model:open="stageOpen" :title="stageForm.id ? $t('Crm_Edit_Stage') : $t('Crm_Add_Stage')" :ok-text="$t('Save')" :cancel-text="$t('Cancel')" :confirm-loading="saving" @ok="saveStage">
      <a-form layout="vertical">
        <a-form-item :label="$t('Name')" required><a-input v-model:value="stageForm.name" :maxlength="120" /></a-form-item>
        <a-form-item :label="$t('Crm_Colour')">
          <div class="swatches">
            <button v-for="c in SWATCHES" :key="c" type="button" class="sw" :class="{ on: stageForm.color === c }" :style="{ background: c }" @click="stageForm.color = c"></button>
            <input v-model="stageForm.color" type="color" class="sw-input" />
          </div>
        </a-form-item>
        <a-form-item :label="`${$t('Crm_Win_Probability')} — ${stageForm.probability}%`" :extra="$t('Crm_Win_Probability_Hint')">
          <a-slider v-model:value="stageForm.probability" :min="0" :max="100" :step="5" />
        </a-form-item>
        <a-form-item :label="$t('Crm_Rot_Days')" :extra="$t('Crm_Rot_Days_Hint')" class="mb0">
          <a-input-number v-model:value="stageForm.rot_days" :min="1" :max="365" style="width: 160px" :addon-after="$t('Crm_Days')" />
        </a-form-item>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
/** CRM Settings: numbering, owner rule, stale days, duplicate check + the lookup lists. */
import { ref, computed, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import { message, Modal, Empty } from 'ant-design-vue';
import { SaveOutlined, PlusOutlined, EditOutlined, DeleteOutlined, UpOutlined, DownOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import CrmAutomation from './CrmAutomation.vue';
import http from '../../lib/http';
import { t, i18n } from '../../i18n';
import { STATUS_KINDS, SWATCHES, REMINDERS, tr, optionOf, labelOf } from './crmOptions';

const route = useRoute();
const simpleImage = Empty.PRESENTED_IMAGE_SIMPLE;
const tab = ref(['pipelines', 'capture', 'assignment', 'scoring', 'sources', 'statuses', 'lost-reasons', 'tags'].includes(route.query.tab) ? route.query.tab : 'general');
const loading = ref(false);
const loaded = ref(false);
const saving = ref(false);
const settings = ref({
  lead_prefix: 'LD', deal_prefix: 'DL', default_owner: 'creator', stale_lead_days: 14, duplicate_check: true,
  default_reminder_minutes: 15, reminder_email: true, reminder_push: true,
});
const reminderOptions = computed(() => tr(REMINDERS).map(r => ({ value: r.value, label: r.label })));
const data = ref({ sources: [], statuses: [], lost_reasons: [], tags: [] });

const lists = computed(() => [
  { type: 'sources', key: 'sources', title: t('Crm_Lead_Sources', 'Lead sources'), hint: t('Crm_Sources_Hint', 'Where your leads come from.'), colored: true, ordered: true },
  { type: 'statuses', key: 'statuses', title: t('Crm_Lead_Statuses', 'Lead statuses'), hint: t('Crm_Statuses_Hint', 'The steps a lead goes through. The order here is the order of the steps.'), colored: true, ordered: true },
  { type: 'lost-reasons', key: 'lost_reasons', title: t('Crm_Lost_Reasons', 'Lost reasons'), hint: t('Crm_Lost_Reasons_Hint', 'Asked when a lead or deal is marked as lost.'), colored: false, ordered: true },
  { type: 'tags', key: 'tags', title: t('Crm_Tags', 'Tags'), hint: t('Crm_Tags_Hint', 'Free labels to group and filter leads.'), colored: true, ordered: false },
]);
const kindOptions = computed(() => tr(STATUS_KINDS).map(k => ({ value: k.value, label: k.label })));

function tp(key, fallback, params) {
  const out = i18n.global.t(key, params);
  return out !== key ? out : Object.entries(params).reduce((s, [k, v]) => s.replace(`{${k}}`, v), fallback);
}

async function load() {
  loading.value = true;
  try {
    const res = await http.get('crm/settings');
    settings.value = { ...settings.value, ...res.settings };
    data.value = { sources: res.sources, statuses: res.statuses, lost_reasons: res.lost_reasons, tags: res.tags };
    loaded.value = true;
  } catch (e) {
    message.error(t('Crm_Err_Could_Not_Load_The_CRM_Settings', 'Could not load the CRM settings'));
  } finally {
    loading.value = false;
  }
}

async function saveGeneral() {
  saving.value = true;
  try {
    await http.put('crm/settings', settings.value);
    message.success(t('Updated_in_successfully', 'Updated successfully'));
    await load();
  } catch (e) {
    const errors = e?.data?.errors;
    message.error((errors && Object.values(errors)[0]?.[0]) || e?.data?.message || t('Gen_Could_Not_Save', 'Could not save'));
  } finally {
    saving.value = false;
  }
}

// ----- lookup form -----
const formOpen = ref(false);
const current = ref(null);
const form = ref({});
const formTitle = computed(() => (form.value.id ? t('Edit', 'Edit') : t('Crm_Add', 'Add')) + (current.value ? ` — ${current.value.title}` : ''));
function openForm(L, row = null) {
  current.value = L;
  form.value = row
    ? { ...row }
    : { name: '', color: SWATCHES[Math.floor(Math.random() * SWATCHES.length)], kind: 'open', is_default: false, is_active: true };
  formOpen.value = true;
}
async function saveForm() {
  if (!form.value.name?.trim()) { message.error(t('Field_is_required', 'This field is required')); return; }
  saving.value = true;
  const L = current.value;
  const body = { name: form.value.name, color: form.value.color, kind: form.value.kind, is_default: !!form.value.is_default, is_active: !!form.value.is_active };
  try {
    if (form.value.id) await http.put(`crm/settings/${L.type}/${form.value.id}`, body);
    else await http.post(`crm/settings/${L.type}`, body);
    formOpen.value = false;
    await load();
  } catch (e) {
    const errors = e?.data?.errors;
    message.error((errors && Object.values(errors)[0]?.[0]) || e?.data?.message || t('Gen_Could_Not_Save', 'Could not save'));
  } finally {
    saving.value = false;
  }
}

function remove(L, row) {
  Modal.confirm({
    title: t('Delete_Title', 'Are you sure?'),
    content: row.used ? `${row.name} — ${tp('Crm_Used_N', '{n} leads', { n: row.used })}` : row.name,
    okType: 'danger',
    okText: t('Delete_confirmButtonText', 'Yes, delete it!'),
    cancelText: t('Delete_cancelButtonText', 'Cancel'),
    async onOk() {
      try {
        await http.delete(`crm/settings/${L.type}/${row.id}`);
        await load();
      } catch (e) {
        message.error(e?.data?.message || t('Gen_Could_Not_Delete', 'Could not delete'));
      }
    },
  });
}

async function move(L, i, dir) {
  const rows = [...data.value[L.key]];
  const [r] = rows.splice(i, 1);
  rows.splice(i + dir, 0, r);
  data.value[L.key] = rows;
  try {
    await http.post(`crm/settings/${L.type}/reorder`, { ids: rows.map(x => x.id) });
  } catch (e) {
    message.error(t('Crm_Could_Not_Save_Order', 'Could not save the order'));
    load();
  }
}

// ----- pipelines & stages -----
const pipelines = ref([]);
async function loadPipelines() {
  try { pipelines.value = (await http.get('crm/pipelines')).pipelines || []; } catch (e) { /* tab stays empty */ }
}
function errText(e, fallback) {
  const errors = e?.data?.errors;
  return (errors && Object.values(errors)[0]?.[0]) || e?.data?.message || fallback;
}

const pipeOpen = ref(false);
const pipeForm = ref({ id: null, name: '', is_default: false });
function openPipeline(p = null) {
  pipeForm.value = p ? { id: p.id, name: p.name, is_default: p.is_default } : { id: null, name: '', is_default: false };
  pipeOpen.value = true;
}
async function savePipeline() {
  if (!pipeForm.value.name?.trim()) { message.error(t('Field_is_required', 'This field is required')); return; }
  saving.value = true;
  try {
    const body = { name: pipeForm.value.name, is_default: !!pipeForm.value.is_default };
    if (pipeForm.value.id) await http.put(`crm/pipelines/${pipeForm.value.id}`, body);
    else await http.post('crm/pipelines', body);
    pipeOpen.value = false;
    await loadPipelines();
  } catch (e) {
    message.error(errText(e, t('Gen_Could_Not_Save', 'Could not save')));
  } finally {
    saving.value = false;
  }
}
function removePipeline(p) {
  Modal.confirm({
    title: t('Delete_Title', 'Are you sure?'),
    content: p.name,
    okType: 'danger',
    okText: t('Delete_confirmButtonText', 'Yes, delete it!'),
    cancelText: t('Delete_cancelButtonText', 'Cancel'),
    async onOk() {
      try { await http.delete(`crm/pipelines/${p.id}`); await loadPipelines(); } catch (e) { message.error(errText(e, t('Gen_Could_Not_Delete', 'Could not delete'))); }
    },
  });
}

const stageOpen = ref(false);
const stageForm = ref({});
function openStage(p, s = null) {
  stageForm.value = s
    ? { id: s.id, pipeline_id: p.id, name: s.name, color: s.color, probability: s.probability, rot_days: s.rot_days }
    : { id: null, pipeline_id: p.id, name: '', color: SWATCHES[Math.floor(Math.random() * SWATCHES.length)], probability: 50, rot_days: null };
  stageOpen.value = true;
}
async function saveStage() {
  if (!stageForm.value.name?.trim()) { message.error(t('Field_is_required', 'This field is required')); return; }
  saving.value = true;
  const f = stageForm.value;
  const body = { name: f.name, color: f.color, probability: f.probability, rot_days: f.rot_days || null };
  try {
    if (f.id) await http.put(`crm/pipeline-stages/${f.id}`, body);
    else await http.post(`crm/pipelines/${f.pipeline_id}/stages`, body);
    stageOpen.value = false;
    await loadPipelines();
  } catch (e) {
    message.error(errText(e, t('Gen_Could_Not_Save', 'Could not save')));
  } finally {
    saving.value = false;
  }
}
function removeStage(s) {
  Modal.confirm({
    title: t('Delete_Title', 'Are you sure?'),
    content: s.name,
    okType: 'danger',
    okText: t('Delete_confirmButtonText', 'Yes, delete it!'),
    cancelText: t('Delete_cancelButtonText', 'Cancel'),
    async onOk() {
      try { await http.delete(`crm/pipeline-stages/${s.id}`); await loadPipelines(); } catch (e) { message.error(errText(e, t('Gen_Could_Not_Delete', 'Could not delete'))); }
    },
  });
}
async function moveStage(p, i, dir) {
  const rows = [...p.stages];
  const [r] = rows.splice(i, 1);
  rows.splice(i + dir, 0, r);
  p.stages = rows;
  try { await http.post(`crm/pipelines/${p.id}/stages/reorder`, { ids: rows.map(x => x.id) }); } catch (e) { message.error(t('Crm_Could_Not_Save_Order', 'Could not save the order')); loadPipelines(); }
}

onMounted(() => { load(); loadPipelines(); });
</script>

<style scoped>
.card { border-radius: 14px; }
.general { max-width: 900px; }
.hint { font-size: 12.5px; opacity: 0.6; margin-inline-start: 8px; }
.list-head .hint { margin: 0; }
.radios { display: flex; flex-direction: column; gap: 6px; }
.list-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 12px; }
.rows { display: flex; flex-direction: column; gap: 6px; max-width: 760px; }
.row { display: flex; align-items: center; gap: 10px; padding: 8px 12px; border: 1px solid rgba(128, 128, 128, 0.18); border-radius: 10px; }
.order { display: flex; flex-direction: column; margin: -4px 0; }
.order :deep(.ant-btn) { height: 18px; }
.swatch { width: 14px; height: 14px; border-radius: 4px; flex: none; }
.name { font-weight: 500; flex: 1; min-width: 0; }
.used { font-size: 12px; opacity: 0.55; white-space: nowrap; }
.swatches { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
.sw { width: 26px; height: 26px; border-radius: 8px; border: 2px solid transparent; cursor: pointer; }
.sw.on { border-color: rgba(0, 0, 0, 0.55); box-shadow: 0 0 0 2px #fff inset; }
.sw-input { width: 34px; height: 28px; padding: 0; border: 0; background: none; cursor: pointer; }
.pipes { display: flex; flex-direction: column; gap: 18px; max-width: 760px; }
.pipe { border: 1px solid rgba(128, 128, 128, 0.2); border-radius: 14px; padding: 14px; }
.pipe-head { display: flex; align-items: center; gap: 10px; margin-bottom: 10px; }
.pipe-name { font-weight: 600; font-size: 15px; }
.pipe-actions { margin-inline-start: auto; }
.prob { font-size: 12px; font-weight: 600; padding: 1px 8px; border-radius: 999px; background: rgba(128, 128, 128, 0.14); }
.rotd { font-size: 12px; opacity: 0.7; }
.add-stage { align-self: flex-start; margin-top: 4px; }
.hint.top { display: block; margin: 10px 0 0; }
.hint.block { display: block; margin: 6px 0 0; }
.sub-title { font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; opacity: 0.55; margin: 4px 0 10px; }
</style>
