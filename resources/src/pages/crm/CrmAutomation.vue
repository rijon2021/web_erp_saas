<template>
  <a-spin :spinning="loading">
    <!-- ================= Lead capture ================= -->
    <div v-if="section === 'capture'" class="wrap">
      <p class="lead-in">{{ $t('Crm_Capture_Intro') }}</p>
      <div class="channels">
        <div class="channel">
          <span class="ch-ic" :style="icStyle('#0ea5e9')"><FileInput :size="18" /></span>
          <div class="ch-text">
            <div class="ch-name">{{ $t('Crm_Web_Forms') }} <span class="count">{{ captured.web_form || 0 }}</span></div>
            <div class="ch-hint">{{ $t('Crm_Capture_Web_Forms_Hint') }}</div>
          </div>
          <a-button v-if="canForms" @click="$router.push('/crm/web-forms')">{{ $t('Crm_Manage_Forms') }}</a-button>
        </div>
        <div v-for="c in captureChannels" :key="c.flag" class="channel">
          <span class="ch-ic" :style="icStyle(c.color)"><component :is="c.icon" :size="18" /></span>
          <div class="ch-text">
            <div class="ch-name">{{ c.label }} <span v-if="c.via" class="count">{{ captured[c.via] || 0 }}</span></div>
            <div class="ch-hint">{{ c.hint }}</div>
          </div>
          <a-switch v-model:checked="capture[c.flag]" />
        </div>
      </div>

      <div class="sub-title">{{ $t('Crm_After_Capture') }}</div>
      <a-form layout="vertical" class="form-narrow">
        <a-form-item :label="$t('Crm_Followup_Task')" :extra="$t('Crm_Followup_Task_Hint')">
          <a-select v-model:value="capture.capture_followup_hours" :options="followupOptions" style="width: 260px" />
        </a-form-item>
        <a-form-item>
          <a-checkbox v-model:checked="capture.capture_notify">{{ $t('Crm_Notify_New_Lead') }}</a-checkbox>
        </a-form-item>
      </a-form>

      <div class="sub-title">{{ $t('Crm_Stale_Alerts') }}</div>
      <a-form layout="vertical" class="form-narrow">
        <a-form-item :extra="tp('Crm_Stale_Alerts_Hint', 'Each morning, every rep gets one email / push listing their leads with no activity for {n} days and their deals stuck in a stage.', { n: capture.stale_lead_days })">
          <a-checkbox v-model:checked="capture.stale_alerts">{{ $t('Crm_Send_Stale_Digest') }}</a-checkbox>
        </a-form-item>
        <a-form-item :extra="$t('Crm_Weekly_Report_Hint')">
          <a-checkbox v-model:checked="capture.weekly_report">{{ $t('Crm_Weekly_Report') }}</a-checkbox>
        </a-form-item>
        <div class="cron">
          <span class="muted">{{ $t('Crm_Daily_Cron_Hint') }}</span>
          <code>0 8 * * * cd /path-to-stocky &amp;&amp; php artisan crm:daily</code>
        </div>
      </a-form>
      <a-button type="primary" :loading="saving" @click="saveCapture"><template #icon><SaveOutlined /></template>{{ $t('Save') }}</a-button>
    </div>

    <!-- ================= Assignment rules ================= -->
    <div v-else-if="section === 'assignment'" class="wrap">
      <div class="list-head">
        <span class="hint">{{ $t('Crm_Assignment_Intro') }}</span>
        <a-button type="primary" @click="openRule()"><template #icon><PlusOutlined /></template>{{ $t('Crm_New_Rule') }}</a-button>
      </div>
      <a-alert v-if="ownerMode !== 'rules'" type="info" show-icon class="gap-b" :message="$t('Crm_Rules_Manual_Hint')" />
      <div class="rules">
        <div v-for="(r, i) in rules" :key="r.id" class="rule" :class="{ off: !r.is_active }">
          <div class="order">
            <a-button type="text" size="small" :disabled="i === 0" @click="moveRule(i, -1)"><template #icon><UpOutlined /></template></a-button>
            <a-button type="text" size="small" :disabled="i === rules.length - 1" @click="moveRule(i, 1)"><template #icon><DownOutlined /></template></a-button>
          </div>
          <span class="step-no">{{ i + 1 }}</span>
          <div class="rule-body">
            <div class="rule-name">{{ r.name }}</div>
            <div class="conds">
              <span class="when">{{ $t('Crm_When') }}</span>
              <template v-if="hasConditions(r)">
                <span v-for="c in r.conditions.channels" :key="`c${c}`" class="chip">{{ labelOf(CAPTURE_CHANNELS, c) }}</span>
                <span v-for="s in r.conditions.source_ids" :key="`s${s}`" class="chip src"><span class="dot" :style="{ background: sourceOf(s)?.color }"></span>{{ sourceOf(s)?.name || '#' + s }}</span>
                <span v-for="c in r.conditions.countries" :key="`k${c}`" class="chip"><GlobalOutlined /> {{ c }}</span>
                <span v-if="r.conditions.min_value" class="chip">≥ {{ money(r.conditions.min_value) }}</span>
              </template>
              <span v-else class="chip any">{{ $t('Crm_Any_Lead') }}</span>
            </div>
            <div class="conds">
              <span class="when">{{ r.method === 'round_robin' ? $t('Crm_Rotate_Between') : $t('Crm_Give_To') }}</span>
              <span v-for="u in r.users" :key="u.id" class="chip user" :class="{ next: u.next, gone: !u.active }">
                <a-avatar :size="18" :style="{ background: avatarColor(u.name), fontSize: '9px' }">{{ initials(u.name) }}</a-avatar>
                {{ u.name }}<a-tooltip v-if="u.next" :title="$t('Crm_Next_In_Turn')"><span class="next-dot"></span></a-tooltip>
              </span>
            </div>
          </div>
          <span class="used">{{ tp('Crm_Assigned_N', '{n} assigned', { n: r.assigned_count }) }}</span>
          <a-switch :checked="r.is_active" size="small" @change="(v) => toggleRule(r, v)" />
          <a-space :size="0">
            <a-button type="text" size="small" @click="openRule(r)"><template #icon><EditOutlined /></template></a-button>
            <a-button type="text" size="small" danger @click="removeRule(r)"><template #icon><DeleteOutlined /></template></a-button>
          </a-space>
        </div>
        <div v-if="rules.length" class="fallthrough"><ArrowDownOutlined /> {{ $t('Crm_Rules_No_Match') }}</div>
        <a-empty v-else :image="simpleImage" :description="$t('Crm_No_Rules')" />
      </div>
    </div>

    <!-- ================= Lead scoring ================= -->
    <div v-else-if="section === 'scoring'" class="wrap">
      <div class="score-top">
        <div class="score-switch">
          <a-switch v-model:checked="capture.scoring_enabled" @change="saveScoringSwitch" />
          <div>
            <div class="ch-name">{{ $t('Crm_Lead_Scoring_On') }}</div>
            <div class="ch-hint">{{ $t('Crm_Lead_Scoring_Hint') }}</div>
          </div>
        </div>
        <div v-if="capture.scoring_enabled" class="spread">
          <div class="sp"><span class="sp-v" style="color:#16a34a">{{ data.score_spread?.hot || 0 }}</span><span class="sp-l">70–100</span></div>
          <div class="sp"><span class="sp-v" style="color:#f59e0b">{{ data.score_spread?.warm || 0 }}</span><span class="sp-l">40–69</span></div>
          <div class="sp"><span class="sp-v" style="color:#94a3b8">{{ data.score_spread?.cold || 0 }}</span><span class="sp-l">0–39</span></div>
        </div>
      </div>
      <template v-if="capture.scoring_enabled">
        <div class="list-head">
          <span class="hint">{{ $t('Crm_Scoring_Rules_Hint') }}</span>
          <a-space>
            <a-button :loading="recalculating" @click="recalculate"><template #icon><ReloadOutlined /></template>{{ $t('Crm_Recalculate') }}</a-button>
            <a-button type="primary" @click="openScore()"><template #icon><PlusOutlined /></template>{{ $t('Crm_Add_Rule') }}</a-button>
          </a-space>
        </div>
        <div class="rows">
          <div v-for="r in scoring" :key="r.id" class="row" :class="{ off: !r.is_active }">
            <span class="pts" :class="{ neg: r.points < 0 }">{{ r.points > 0 ? '+' : '' }}{{ r.points }}</span>
            <span class="name">{{ critLabel(r) }}</span>
            <a-switch :checked="r.is_active" size="small" @change="(v) => toggleScore(r, v)" />
            <a-space :size="0">
              <a-button type="text" size="small" @click="openScore(r)"><template #icon><EditOutlined /></template></a-button>
              <a-button type="text" size="small" danger @click="removeScore(r)"><template #icon><DeleteOutlined /></template></a-button>
            </a-space>
          </div>
          <a-empty v-if="!scoring.length" :image="simpleImage" />
          <div class="muted small">{{ $t('Crm_Score_Scale_Hint') }}</div>
        </div>
      </template>
    </div>

    <!-- Rule modal -->
    <a-modal v-model:open="ruleOpen" :title="ruleForm.id ? $t('Crm_Edit_Rule') : $t('Crm_New_Rule')" :ok-text="$t('Save')" :cancel-text="$t('Cancel')" :confirm-loading="saving" width="620px" @ok="saveRule">
      <a-form layout="vertical">
        <a-form-item :label="$t('Name')" required><a-input v-model:value="ruleForm.name" :maxlength="160" :placeholder="$t('Crm_Rule_Name_Placeholder')" /></a-form-item>
        <div class="sub-title">{{ $t('Crm_When_Lead_Matches') }}</div>
        <a-row :gutter="12">
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Crm_Came_In_Through')"><a-select v-model:value="ruleForm.channels" mode="multiple" :options="channelOptions" :placeholder="$t('Crm_Any')" /></a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Crm_Source')"><a-select v-model:value="ruleForm.source_ids" mode="multiple" :options="sourceOptions" :placeholder="$t('Crm_Any')" option-filter-prop="label" /></a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Country')" :extra="$t('Crm_Countries_Hint')"><a-select v-model:value="ruleForm.countries" mode="tags" :placeholder="$t('Crm_Any')" :token-separators="[',']" /></a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Crm_Min_Value')"><a-input-number v-model:value="ruleForm.min_value" :min="0" style="width: 100%" :placeholder="$t('Crm_Any')" /></a-form-item>
          </a-col>
        </a-row>
        <div class="sub-title">{{ $t('Crm_Then') }}</div>
        <a-form-item>
          <a-radio-group v-model:value="ruleForm.method" button-style="solid">
            <a-radio-button value="round_robin"><Repeat :size="13" class="ic" /> {{ $t('Crm_Rotate_Between') }}</a-radio-button>
            <a-radio-button value="user"><UserRound :size="13" class="ic" /> {{ $t('Crm_Give_To_One') }}</a-radio-button>
          </a-radio-group>
        </a-form-item>
        <a-form-item :label="ruleForm.method === 'user' ? $t('Crm_Sales_Rep') : $t('Crm_Sales_Reps')" required>
          <a-select
            v-if="ruleForm.method === 'user'" :value="ruleForm.user_ids[0]" :options="userOptions" show-search option-filter-prop="label"
            @change="(v) => (ruleForm.user_ids = v ? [v] : [])"
          />
          <a-select v-else v-model:value="ruleForm.user_ids" mode="multiple" :options="userOptions" show-search option-filter-prop="label" />
        </a-form-item>
        <a-checkbox v-model:checked="ruleForm.is_active">{{ $t('Crm_Rule_Active') }}</a-checkbox>
      </a-form>
    </a-modal>

    <!-- Scoring modal -->
    <a-modal v-model:open="scoreOpen" :title="scoreForm.id ? $t('Crm_Edit_Rule') : $t('Crm_Add_Rule')" :ok-text="$t('Save')" :cancel-text="$t('Cancel')" :confirm-loading="saving" @ok="saveScore">
      <a-form layout="vertical">
        <a-form-item :label="$t('Crm_If_The_Lead')" required>
          <a-select v-model:value="scoreForm.criterion" :options="criterionOptions" @change="scoreForm.value = undefined" />
        </a-form-item>
        <a-form-item v-if="kind" :label="$t('Crm_Value')" required>
          <a-select v-if="kind === 'rating'" v-model:value="scoreForm.value" :options="ratingOptions" />
          <a-select v-else-if="kind === 'source'" v-model:value="scoreForm.value" :options="sourceOptions.map(o => ({ ...o, value: String(o.value) }))" option-filter-prop="label" show-search />
          <a-select v-else-if="kind === 'tag'" v-model:value="scoreForm.value" :options="tagOptions" option-filter-prop="label" show-search />
          <a-select v-else-if="kind === 'channel'" v-model:value="scoreForm.value" :options="channelOptions" />
          <a-input-number v-else-if="kind === 'number' || kind === 'days'" v-model:value="scoreForm.value" :min="kind === 'days' ? 1 : 0" style="width: 100%" :addon-after="kind === 'days' ? $t('Crm_Days') : undefined" />
          <a-input v-else v-model:value="scoreForm.value" :maxlength="120" />
        </a-form-item>
        <a-form-item :label="`${$t('Crm_Points')} — ${scoreForm.points > 0 ? '+' : ''}${scoreForm.points}`" :extra="$t('Crm_Points_Hint')">
          <a-slider v-model:value="scoreForm.points" :min="-50" :max="50" :step="5" :marks="{ '-50': '-50', 0: '0', 50: '+50' }" />
        </a-form-item>
        <a-checkbox v-model:checked="scoreForm.is_active">{{ $t('Crm_Rule_Active') }}</a-checkbox>
      </a-form>
    </a-modal>
  </a-spin>
</template>

<script setup>
/**
 * CRM Settings → Lead capture / Assignment rules / Lead scoring
 * (GET crm/automation; one `section` per settings tab).
 */
import { ref, computed, onMounted } from 'vue';
import { message, Modal, Empty } from 'ant-design-vue';
import {
  SaveOutlined, PlusOutlined, EditOutlined, DeleteOutlined, UpOutlined, DownOutlined, ReloadOutlined, GlobalOutlined, ArrowDownOutlined,
} from '@ant-design/icons-vue';
import { FileInput, Store, FileQuestionMark, Building, UserRoundCheck, MessageCircle, Repeat, UserRound } from 'lucide-vue-next';
import { useFormat } from '../../composables/useFormat';
import { useAuthStore } from '../../stores/auth';
import http from '../../lib/http';
import { t, i18n } from '../../i18n';
import { CAPTURE_CHANNELS, SCORE_CRITERIA, RATINGS, tr, optionOf, labelOf, initials, avatarColor, tint } from './crmOptions';

const props = defineProps({
  section: { type: String, required: true },
  ownerMode: { type: String, default: 'creator' },
});
const auth = useAuthStore();
const { money } = useFormat();
const simpleImage = Empty.PRESENTED_IMAGE_SIMPLE;
const canForms = computed(() => auth.can('crm_web_forms'));

const loading = ref(false);
const saving = ref(false);
const data = ref({});
const capture = ref({});
const captured = computed(() => data.value.captured || {});
const rules = ref([]);
const scoring = ref([]);

function tp(key, fallback, params) {
  const out = i18n.global.t(key, params);
  return out !== key ? out : Object.entries(params).reduce((s, [k, v]) => s.replace(`{${k}}`, v ?? ''), fallback);
}
function errText(e, fallback) {
  const errors = e?.data?.errors;
  return (errors && Object.values(errors)[0]?.[0]) || e?.data?.message || fallback;
}
function icStyle(c) { return { color: c, background: tint(c, 0.13) }; }

const captureChannels = computed(() => [
  { flag: 'capture_store_messages', via: 'store_message', icon: Store, color: '#8b5cf6', label: t('Crm_Capture_Store_Messages', 'Store contact form'), hint: t('Crm_Capture_Store_Messages_Hint', 'Messages sent from the online store\'s contact page become leads.') },
  { flag: 'capture_quote_requests', via: 'quote_request', icon: FileQuestionMark, color: '#f59e0b', label: t('Crm_Capture_Quote_Requests', 'Store quote requests'), hint: t('Crm_Capture_Quote_Requests_Hint', '"Request a quotation" from the store: a lead, or a deal when the visitor is already a customer.') },
  { flag: 'capture_property_inquiries', via: 'property_inquiry', icon: Building, color: '#14b8a6', label: t('Crm_Capture_Property_Inquiries', 'Property inquiries'), hint: t('Crm_Capture_Property_Inquiries_Hint', 'Inquiries from the Real Estate storefront become leads (the property price is the estimated value).') },
  { flag: 'capture_portal_requests', via: null, icon: UserRoundCheck, color: '#ec4899', label: t('Crm_Capture_Portal_Requests', 'Portal quotation requests'), hint: t('Crm_Capture_Portal_Requests_Hint', 'A customer asking for a quotation in the client portal opens a deal, linked to that quotation.') },
  { flag: 'capture_whatsapp_unknown', via: 'whatsapp', icon: MessageCircle, color: '#16a34a', label: t('Crm_Capture_WhatsApp', 'WhatsApp from new numbers'), hint: t('Crm_Capture_WhatsApp_Hint', 'A WhatsApp message from a number the CRM doesn\'t know starts a new lead.') },
]);
const followupOptions = computed(() => [
  { value: null, label: t('Crm_No_Followup', 'No follow-up task') },
  ...[0, 1, 2, 4, 8, 24, 48].map(h => ({ value: h, label: h === 0 ? t('Crm_Followup_Now', 'Due right away') : tp('Crm_Followup_In_H', 'Due in {n} h', { n: h }) })),
]);

const channelOptions = computed(() => tr(CAPTURE_CHANNELS).map(c => ({ value: c.value, label: c.label })));
const sourceOptions = computed(() => (data.value.sources || []).map(s => ({ value: s.id, label: s.name })));
const tagOptions = computed(() => (data.value.tags || []).map(x => ({ value: String(x.id), label: x.name })));
const userOptions = computed(() => (data.value.users || []).map(u => ({ value: u.id, label: u.name })));
const ratingOptions = computed(() => tr(RATINGS).map(r => ({ value: r.value, label: r.label })));
const criterionOptions = computed(() => tr(SCORE_CRITERIA).map(c => ({ value: c.value, label: c.label })));
function sourceOf(id) { return (data.value.sources || []).find(s => s.id === Number(id)); }
function hasConditions(r) {
  const c = r.conditions || {};
  return (c.channels?.length || c.source_ids?.length || c.countries?.length || c.min_value);
}

async function load() {
  loading.value = true;
  try {
    data.value = await http.get('crm/automation');
    capture.value = { ...data.value.capture };
    rules.value = data.value.assignment_rules || [];
    scoring.value = data.value.scoring_rules || [];
  } catch (e) {
    message.error(errText(e, t('Gen_Could_Not_Load', 'Could not load')));
  } finally {
    loading.value = false;
  }
}

async function saveCapture() {
  saving.value = true;
  try {
    await http.put('crm/automation', capture.value);
    message.success(t('Updated_in_successfully', 'Updated successfully'));
  } catch (e) {
    message.error(errText(e, t('Gen_Could_Not_Save', 'Could not save')));
  } finally {
    saving.value = false;
  }
}
async function saveScoringSwitch(v) {
  try {
    await http.put('crm/automation', { scoring_enabled: v, capture_followup_hours: capture.value.capture_followup_hours });
    await load();
  } catch (e) {
    message.error(errText(e, t('Gen_Could_Not_Save', 'Could not save')));
  }
}

// ----- assignment rules -----
const ruleOpen = ref(false);
const ruleForm = ref({});
function openRule(r = null) {
  ruleForm.value = r
    ? { id: r.id, name: r.name, method: r.method, user_ids: [...r.user_ids], is_active: r.is_active, ...JSON.parse(JSON.stringify(r.conditions)) }
    : { id: null, name: '', method: 'round_robin', user_ids: [], is_active: true, channels: [], source_ids: [], countries: [], min_value: null };
  ruleOpen.value = true;
}
function ruleBody(f) {
  return {
    name: f.name, method: f.method, user_ids: f.user_ids, is_active: !!f.is_active,
    conditions: { channels: f.channels, source_ids: f.source_ids, countries: f.countries, min_value: f.min_value ?? '' },
  };
}
async function saveRule() {
  const f = ruleForm.value;
  if (!f.name?.trim()) { message.error(t('Field_is_required', 'This field is required')); return; }
  saving.value = true;
  try {
    if (f.id) await http.put(`crm/assignment-rules/${f.id}`, ruleBody(f));
    else await http.post('crm/assignment-rules', ruleBody(f));
    ruleOpen.value = false;
    await load();
  } catch (e) {
    message.error(errText(e, t('Gen_Could_Not_Save', 'Could not save')));
  } finally {
    saving.value = false;
  }
}
async function toggleRule(r, v) {
  try {
    await http.put(`crm/assignment-rules/${r.id}`, ruleBody({ ...r, ...r.conditions, is_active: v }));
    r.is_active = v;
  } catch (e) { message.error(errText(e, t('Gen_Could_Not_Save', 'Could not save'))); }
}
function removeRule(r) {
  Modal.confirm({
    title: t('Delete_Title', 'Are you sure?'), content: r.name, okType: 'danger',
    okText: t('Delete_confirmButtonText', 'Yes, delete it!'), cancelText: t('Delete_cancelButtonText', 'Cancel'),
    async onOk() { try { await http.delete(`crm/assignment-rules/${r.id}`); await load(); } catch (e) { message.error(errText(e, t('Gen_Could_Not_Delete', 'Could not delete'))); } },
  });
}
async function moveRule(i, dir) {
  const list = [...rules.value];
  const [r] = list.splice(i, 1);
  list.splice(i + dir, 0, r);
  rules.value = list;
  try { await http.post('crm/assignment-rules/reorder', { ids: list.map(x => x.id) }); } catch (e) { message.error(t('Crm_Could_Not_Save_Order', 'Could not save the order')); load(); }
}

// ----- scoring rules -----
const scoreOpen = ref(false);
const scoreForm = ref({});
const recalculating = ref(false);
const kind = computed(() => optionOf(SCORE_CRITERIA, scoreForm.value.criterion).kind || null);
function critLabel(r) {
  const c = optionOf(SCORE_CRITERIA, r.criterion);
  const name = c.key ? t(c.key, c.label) : r.criterion;
  if (!c.kind || r.value === null || r.value === '') return name;
  let v = r.value;
  if (c.kind === 'rating') v = labelOf(RATINGS, r.value);
  else if (c.kind === 'channel') v = labelOf(CAPTURE_CHANNELS, r.value);
  else if (c.kind === 'source') v = sourceOf(r.value)?.name || `#${r.value}`;
  else if (c.kind === 'tag') v = (data.value.tags || []).find(x => String(x.id) === String(r.value))?.name || `#${r.value}`;
  else if (c.kind === 'number' && c.value === 'value_at_least') v = money(r.value);
  return name.includes('…') ? name.replace('…', v) : `${name} ${v}`;
}
function openScore(r = null) {
  scoreForm.value = r
    ? { id: r.id, criterion: r.criterion, value: ['number', 'days'].includes(optionOf(SCORE_CRITERIA, r.criterion).kind) ? Number(r.value) : r.value, points: r.points, is_active: r.is_active }
    : { id: null, criterion: 'has_email', value: undefined, points: 10, is_active: true };
  scoreOpen.value = true;
}
async function saveScore() {
  const f = scoreForm.value;
  saving.value = true;
  const body = { criterion: f.criterion, value: f.value === undefined || f.value === null ? '' : String(f.value), points: f.points, is_active: !!f.is_active };
  try {
    if (f.id) await http.put(`crm/scoring-rules/${f.id}`, body);
    else await http.post('crm/scoring-rules', body);
    scoreOpen.value = false;
    await load();
    message.info(t('Crm_Recalculate_Reminder', 'Rules saved. Click "Recalculate" to re-score existing leads now (it also runs every night).'));
  } catch (e) {
    message.error(errText(e, t('Gen_Could_Not_Save', 'Could not save')));
  } finally {
    saving.value = false;
  }
}
async function toggleScore(r, v) {
  try {
    await http.put(`crm/scoring-rules/${r.id}`, { criterion: r.criterion, value: r.value ?? '', points: r.points, is_active: v });
    r.is_active = v;
  } catch (e) { message.error(errText(e, t('Gen_Could_Not_Save', 'Could not save'))); }
}
function removeScore(r) {
  Modal.confirm({
    title: t('Delete_Title', 'Are you sure?'), content: critLabel(r), okType: 'danger',
    okText: t('Delete_confirmButtonText', 'Yes, delete it!'), cancelText: t('Delete_cancelButtonText', 'Cancel'),
    async onOk() { try { await http.delete(`crm/scoring-rules/${r.id}`); await load(); } catch (e) { message.error(errText(e, t('Gen_Could_Not_Delete', 'Could not delete'))); } },
  });
}
async function recalculate() {
  recalculating.value = true;
  try {
    const res = await http.post('crm/scoring-rules/recalculate');
    message.success(tp('Crm_Recalculated_N', 'Done — {n} lead scores changed', { n: res.changed }));
    await load();
  } catch (e) {
    message.error(errText(e, t('Crm_Could_Not_Recalculate', 'Could not recalculate')));
  } finally {
    recalculating.value = false;
  }
}

onMounted(load);
</script>

<style scoped>
.wrap { max-width: 860px; }
.lead-in { opacity: 0.7; margin-bottom: 14px; }
.channels { display: flex; flex-direction: column; gap: 8px; margin-bottom: 22px; }
.channel { display: flex; align-items: center; gap: 14px; padding: 12px 14px; border: 1px solid rgba(128, 128, 128, 0.18); border-radius: 12px; }
.ch-ic { width: 38px; height: 38px; border-radius: 10px; display: inline-flex; align-items: center; justify-content: center; flex: none; }
.ch-text { flex: 1; min-width: 0; }
.ch-name { font-weight: 600; }
.ch-hint { font-size: 12.5px; opacity: 0.62; margin-top: 1px; }
.count { font-size: 11.5px; font-weight: 600; padding: 0 7px; border-radius: 999px; background: rgba(128, 128, 128, 0.14); margin-inline-start: 4px; }
.sub-title { font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; opacity: 0.55; margin: 6px 0 10px; }
.form-narrow { max-width: 620px; }
.cron { display: flex; flex-direction: column; gap: 4px; margin: -6px 0 18px; font-size: 12.5px; }
.cron code { padding: 6px 10px; border-radius: 8px; background: rgba(128, 128, 128, 0.1); font-size: 12px; align-self: flex-start; }
.muted { opacity: 0.6; }
.small { font-size: 12px; margin-top: 6px; }
.hint { font-size: 12.5px; opacity: 0.6; }
.list-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 12px; flex-wrap: wrap; }
.gap-b { margin-bottom: 12px; }
.rules { display: flex; flex-direction: column; gap: 8px; }
.rule { display: flex; align-items: center; gap: 10px; padding: 12px; border: 1px solid rgba(128, 128, 128, 0.18); border-radius: 12px; }
.rule.off, .row.off { opacity: 0.55; }
.order { display: flex; flex-direction: column; margin: -4px 0; }
.order :deep(.ant-btn) { height: 18px; }
.step-no { width: 24px; height: 24px; border-radius: 50%; flex: none; display: inline-flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 700; background: rgba(var(--app-primary-rgb, 109, 40, 217), 0.12); color: var(--app-primary, #6d28d9); }
.rule-body { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 5px; }
.rule-name { font-weight: 600; }
.conds { display: flex; flex-wrap: wrap; gap: 4px; align-items: center; }
.when { font-size: 11.5px; text-transform: uppercase; letter-spacing: 0.04em; opacity: 0.5; margin-inline-end: 4px; font-weight: 600; }
.chip { display: inline-flex; align-items: center; gap: 5px; font-size: 12px; padding: 1px 8px; border-radius: 999px; background: rgba(128, 128, 128, 0.12); }
.chip.any { font-style: italic; }
.chip.user { padding-inline-start: 2px; }
.chip.user.next { background: rgba(var(--app-primary-rgb, 109, 40, 217), 0.12); }
.chip.user.gone { text-decoration: line-through; opacity: 0.6; }
.next-dot { width: 6px; height: 6px; border-radius: 50%; background: var(--app-primary, #6d28d9); display: inline-block; }
.dot { width: 8px; height: 8px; border-radius: 50%; display: inline-block; }
.used { font-size: 12px; opacity: 0.55; white-space: nowrap; }
.fallthrough { font-size: 12.5px; opacity: 0.6; padding: 6px 12px; }
.score-top { display: flex; align-items: center; gap: 18px; justify-content: space-between; flex-wrap: wrap; margin-bottom: 18px; padding: 14px; border-radius: 12px; background: rgba(128, 128, 128, 0.05); }
.score-switch { display: flex; gap: 12px; align-items: flex-start; max-width: 520px; }
.spread { display: flex; gap: 18px; }
.sp { display: flex; flex-direction: column; align-items: center; }
.sp-v { font-size: 20px; font-weight: 700; line-height: 1.1; }
.sp-l { font-size: 11.5px; opacity: 0.55; }
.rows { display: flex; flex-direction: column; gap: 6px; }
.row { display: flex; align-items: center; gap: 12px; padding: 8px 12px; border: 1px solid rgba(128, 128, 128, 0.18); border-radius: 10px; }
.pts { min-width: 48px; text-align: center; font-weight: 700; font-size: 13px; padding: 2px 8px; border-radius: 999px; color: #16a34a; background: rgba(22, 163, 74, 0.12); }
.pts.neg { color: #dc2626; background: rgba(220, 38, 38, 0.1); }
.name { flex: 1; min-width: 0; font-weight: 500; }
.ic { vertical-align: -2px; margin-inline-end: 4px; }
</style>
