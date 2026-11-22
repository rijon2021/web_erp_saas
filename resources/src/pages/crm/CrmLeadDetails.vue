<template>
  <div class="page">
    <PageHeader :title="lead ? lead.name : $t('Crm_Lead')" :breadcrumb="[$t('Crm'), $t('Crm_Leads'), lead ? lead.lead_number : '']">
      <template #actions>
        <a-button @click="$router.push('/crm/leads')">
          <template #icon><ArrowLeftOutlined /></template>
          {{ $t('Back') }}
        </a-button>
        <template v-if="lead && !lead.client_id">
          <a-button v-if="canDelete" danger type="text" @click="remove"><template #icon><DeleteOutlined /></template></a-button>
          <a-button v-if="canEdit && kind === 'lost'" @click="reopen" :loading="busy === 'reopen'">
            <template #icon><RedoOutlined /></template>{{ $t('Crm_Reopen') }}
          </a-button>
          <a-button v-if="canEdit && kind === 'open'" danger @click="openLost">
            <template #icon><CloseCircleOutlined /></template>{{ $t('Crm_Mark_Lost') }}
          </a-button>
          <a-button v-if="canEdit" @click="$router.push(`/crm/leads/${id}/edit`)">
            <template #icon><EditOutlined /></template>{{ $t('Edit') }}
          </a-button>
          <a-button v-if="canEdit && kind === 'open'" type="primary" @click="convertOpen = true">
            <template #icon><UserAddOutlined /></template>{{ $t('Crm_Convert_To_Customer') }}
          </a-button>
        </template>
        <a-button v-if="lead && lead.client && canCustomers" type="primary" @click="$router.push(`/crm/customers/${lead.client.id}`)">
          <template #icon><UserOutlined /></template>{{ $t('Crm_Open_Customer') }}
        </a-button>
      </template>
    </PageHeader>

    <a-spin :spinning="loading">
      <template v-if="lead">
        <!-- Hero -->
        <a-card :bordered="false" class="hero">
          <div class="hero-main">
            <a-avatar :size="58" :style="{ background: avatarColor(lead.name), fontSize: '20px' }">
              <template v-if="lead.type === 'company'"><Building2 :size="26" /></template>
              <template v-else>{{ initials(lead.name) }}</template>
            </a-avatar>
            <div class="hero-text">
              <div class="hero-name">
                {{ lead.name }}
                <span class="rating" :style="{ color: rating.color, background: tint(rating.color, 0.12) }">
                  <component :is="rating.icon" :size="13" /> {{ labelOf(RATINGS, lead.rating) }}
                </span>
              </div>
              <div class="hero-sub">
                <span class="mono">{{ lead.lead_number }}</span>
                <template v-if="lead.job_title"> · {{ lead.job_title }}</template>
                <template v-if="lead.type === 'person' && lead.company_name"> · {{ lead.company_name }}</template>
                <template v-if="lead.type === 'company' && personName"> · {{ personName }}</template>
              </div>
            </div>
            <a-popover v-if="lead.score_breakdown" placement="bottomRight" :title="$t('Crm_Score_Breakdown')">
              <template #content>
                <div class="sb">
                  <div v-for="b in lead.score_breakdown" :key="b.id" class="sb-row">
                    <span>{{ critLabel(b) }}</span>
                    <span class="sb-pts" :class="{ neg: b.points < 0 }">{{ b.points > 0 ? '+' : '' }}{{ b.points }}</span>
                  </div>
                  <div v-if="!lead.score_breakdown.length" class="muted">{{ $t('Crm_Score_None') }}</div>
                  <div class="sb-foot muted">{{ $t('Crm_Score_Scale_Hint') }}</div>
                </div>
              </template>
              <div class="hero-score" :style="{ '--sc': scoreColor(lead.score) }">
                <span class="hs-num">{{ lead.score }}</span>
                <span class="hs-label">{{ $t('Crm_Score') }}</span>
              </div>
            </a-popover>
            <div class="hero-value">
              <span class="hv-label">{{ $t('Crm_Estimated_Value') }}</span>
              <span class="hv-amount">{{ money(lead.estimated_value || 0) }}</span>
            </div>
          </div>

          <!-- Status steps (open statuses); lost / converted shown as a banner -->
          <div v-if="kind === 'open'" class="steps">
            <button
              v-for="(s, i) in openStatuses" :key="s.id" type="button" class="step"
              :class="{ done: i < currentIndex, current: i === currentIndex }"
              :style="i <= currentIndex ? { background: s.color, borderColor: s.color } : {}"
              :disabled="!canEdit || busy === 'status'"
              @click="setStatus(s)"
            >
              <CheckOutlined v-if="i < currentIndex" /> {{ s.name }}
            </button>
          </div>
          <a-alert v-else-if="kind === 'lost'" type="error" show-icon class="banner">
            <template #message>{{ $t('Crm_Lead_Lost') }}<template v-if="lead.lost_reason">: {{ lead.lost_reason }}</template></template>
            <template #description>
              <span>{{ dateTime(lead.lost_at) }}</span><template v-if="lead.lost_note"> — {{ lead.lost_note }}</template>
            </template>
          </a-alert>
          <a-alert v-else type="success" show-icon class="banner">
            <template #message>
              {{ $t('Crm_Lead_Converted') }}<template v-if="lead.client">:
                <router-link v-if="canCustomers" :to="`/crm/customers/${lead.client.id}`">{{ lead.client.name }}</router-link>
                <template v-else>{{ lead.client.name }}</template>
              </template>
            </template>
            <template v-if="lead.converted_at" #description>{{ dateTime(lead.converted_at) }}</template>
          </a-alert>
        </a-card>

        <a-alert v-if="dupCount && !lead.client_id" type="warning" show-icon class="dups">
          <template #message>{{ $t('Crm_Possible_Duplicates') }}</template>
          <template #description>
            <div v-for="d in lead.duplicates.clients" :key="`c${d.id}`" class="dup">
              <a-tag color="green">{{ $t('Customer') }}</a-tag>
              <router-link :to="`/crm/customers/${d.id}`">{{ d.name }}</router-link>
              <span class="muted">· {{ d.match === 'email' ? d.email : d.phone }}</span>
            </div>
            <div v-for="d in lead.duplicates.leads" :key="`l${d.id}`" class="dup">
              <a-tag color="purple">{{ $t('Crm_Lead') }}</a-tag>
              <router-link :to="`/crm/leads/${d.id}`">{{ d.name }}</router-link>
              <span class="muted">· {{ d.lead_number }} · {{ d.status }}<template v-if="d.owner"> · {{ d.owner }}</template></span>
            </div>
          </template>
        </a-alert>

        <a-row :gutter="16">
          <a-col :xs="24" :lg="9">
            <a-card :bordered="false" class="card">
              <div class="card-title">{{ $t('Crm_Contact_Details') }}</div>
              <div class="contact">
                <a v-if="lead.email" :href="`mailto:${lead.email}`" class="c-row"><MailOutlined /> {{ lead.email }}</a>
                <div v-if="lead.phone" class="c-row">
                  <PhoneOutlined /> <a :href="`tel:${lead.phone}`">{{ lead.phone }}</a>
                  <a :href="whatsApp" target="_blank" rel="noopener" class="wa" :title="$t('Crm_Open_WhatsApp')"><MessageCircle :size="14" /></a>
                </div>
                <a v-if="lead.website" :href="websiteUrl" target="_blank" rel="noopener" class="c-row"><GlobalOutlined /> {{ lead.website }}</a>
                <div v-if="address" class="c-row"><EnvironmentOutlined /> {{ address }}</div>
                <div v-if="lead.industry" class="c-row"><AppstoreOutlined /> {{ lead.industry }}</div>
                <div v-if="!lead.email && !lead.phone && !lead.website && !address" class="muted">{{ $t('Crm_No_Contact_Details') }}</div>
              </div>
            </a-card>

            <a-card :bordered="false" class="card">
              <div class="card-title">{{ $t('Crm_Details') }}</div>
              <dl class="facts">
                <dt>{{ $t('Crm_Owner') }}</dt>
                <dd>
                  <a-select
                    v-if="canEdit && !lead.client_id" :value="lead.owner?.id" :options="ownerOptions" allow-clear size="small"
                    :placeholder="$t('Crm_Unassigned_Pool')" style="width: 100%" :loading="busy === 'owner'" @change="setOwner"
                  />
                  <span v-else>{{ lead.owner?.name || $t('Crm_Unassigned') }}</span>
                </dd>
                <dt>{{ $t('Crm_Source') }}</dt>
                <dd><span v-if="lead.source" class="src"><span class="dot" :style="{ background: lead.source.color }"></span>{{ lead.source.name }}</span><span v-else class="muted">—</span></dd>
                <dt>{{ $t('Crm_Came_In_Through') }}</dt>
                <dd>
                  <span class="src"><span class="dot" :style="{ background: optionOf(CAPTURE_CHANNELS, lead.captured_via || 'manual').color }"></span>{{ labelOf(CAPTURE_CHANNELS, lead.captured_via || 'manual') }}</span>
                  <span v-if="lead.web_form" class="muted"> · {{ lead.web_form.name }}</span>
                </dd>
                <dt>{{ $t('Crm_Marketing') }}</dt>
                <dd>
                  <a-tooltip :title="lead.marketing_opt_out ? `${$t('Crm_Opted_Out_Since')} ${dateTime(lead.marketing_opt_out_at)}` : $t('Crm_Marketing_Hint')">
                    <a-switch
                      :checked="!lead.marketing_opt_out" size="small" :disabled="!canEdit || !!lead.client_id" :loading="busy === 'marketing'"
                      @change="setMarketing"
                    />
                  </a-tooltip>
                  <span class="muted small-gap">{{ lead.marketing_opt_out ? $t('Crm_Unsubscribed') : $t('Crm_Receives_Campaigns') }}</span>
                </dd>
                <dt>{{ $t('Status') }}</dt>
                <dd><span v-if="lead.status" class="status" :style="{ color: lead.status.color, background: tint(lead.status.color) }">{{ lead.status.name }}</span></dd>
                <dt>{{ $t('Crm_Created') }}</dt>
                <dd>{{ dateTime(lead.created_at) }}<span v-if="lead.created_by_name" class="muted"> · {{ lead.created_by_name }}</span></dd>
                <dt>{{ $t('Crm_Last_Activity') }}</dt>
                <dd>
                  {{ lead.last_activity_at ? dateTime(lead.last_activity_at) : '—' }}
                  <a-tag v-if="lead.is_stale" color="default" class="mini">{{ $t('Crm_Stale') }}</a-tag>
                </dd>
              </dl>
              <div class="tags-head">{{ $t('Crm_Tags') }}</div>
              <a-select
                v-if="canEdit && !lead.client_id" v-model:value="tagValues" mode="tags" :options="tagOptions" option-filter-prop="label"
                :placeholder="$t('Crm_Tag_Pick_Or_Type')" style="width: 100%" @blur="saveTags" @deselect="saveTags"
              />
              <div v-else class="tags">
                <span v-for="tg in lead.tags" :key="tg.id" class="tag" :style="{ color: tg.color, background: tint(tg.color) }">{{ tg.name }}</span>
                <span v-if="!lead.tags.length" class="muted">—</span>
              </div>
            </a-card>
          </a-col>

          <a-col :xs="24" :lg="15">
            <a-card v-if="lead.activities || canMessage" :bordered="false" class="card">
              <a-tabs :default-active-key="lead.activities ? 'activities' : 'conversation'" class="work-tabs">
                <a-tab-pane v-if="lead.activities" key="activities" :tab="$t('Crm_Activities')">
                  <a-alert
                    v-if="kind === 'open' && !lead.next_activity" type="warning" show-icon class="nonext"
                    :message="$t('Crm_No_Next_Step')" :description="$t('Crm_No_Next_Step_Hint')"
                  />
                  <CrmActivityComposer v-if="canLogActivity && kind === 'open'" :lead-id="lead.id" compact class="composer-box" @saved="load(false)" />
                  <CrmActivityFeed :activities="lead.activities" :can-delete="canDeleteActivity" :can-edit="canEditActivity" show-deal @changed="load(false)" />
                </a-tab-pane>
                <a-tab-pane v-if="canMessage" key="conversation" :tab="$t('Crm_Conversation')">
                  <CrmConversation :lead-id="lead.id" />
                </a-tab-pane>
              </a-tabs>
            </a-card>

            <a-card v-if="lead.deals" :bordered="false" class="card">
              <div class="card-head">
                <span class="card-title mb0">{{ $t('Crm_Deals') }}</span>
                <a-button v-if="canAddDeal && kind !== 'lost'" size="small" type="primary" ghost @click="$router.push(`/crm/deals/create?lead=${lead.id}`)">
                  <template #icon><PlusOutlined /></template>{{ $t('Crm_New_Deal') }}
                </a-button>
              </div>
              <div v-if="lead.deals.length" class="deals">
                <router-link v-for="d in lead.deals" :key="d.id" :to="`/crm/deals/${d.id}`" class="deal-row">
                  <span class="deal-title">{{ d.title }}<small class="muted"> · {{ d.deal_number }}</small></span>
                  <a-tag v-if="d.status !== 'open'" :color="d.status === 'won' ? 'success' : 'error'">{{ d.status === 'won' ? $t('Crm_Deal_Won') : $t('Crm_Deal_Lost') }}</a-tag>
                  <span v-else-if="d.stage" class="status" :style="{ color: d.stage.color, background: tint(d.stage.color) }">{{ d.stage.name }}</span>
                  <span class="deal-value">{{ money(d.value) }}</span>
                </router-link>
              </div>
              <div v-else class="muted">{{ $t('Crm_No_Deals_Yet') }}</div>
            </a-card>

            <a-card v-if="lead.description" :bordered="false" class="card">
              <div class="card-title">{{ $t('Description') }}</div>
              <div class="desc">{{ lead.description }}</div>
            </a-card>

            <a-card :bordered="false" class="card">
              <div class="card-title">{{ $t('Crm_History') }}</div>
              <a-timeline class="timeline">
                <a-timeline-item v-for="e in lead.events" :key="e.id" :color="eventColor(e)">
                  <div class="ev-head">
                    <span class="ev-title">{{ eventText(e) }}</span>
                    <a-tooltip :title="dateTime(e.created_at)"><span class="ev-time">{{ ago(e.created_at) }}</span></a-tooltip>
                  </div>
                  <div v-if="e.note" class="ev-note">{{ e.note }}</div>
                  <div v-if="e.user_name" class="ev-user">{{ e.user_name }}</div>
                </a-timeline-item>
              </a-timeline>
            </a-card>
          </a-col>
        </a-row>
      </template>
    </a-spin>

    <CrmConvertModal :open="convertOpen" :lead="lead" @close="convertOpen = false" @converted="afterConvert" />

    <a-modal v-model:open="lostOpen" :title="$t('Crm_Mark_Lost')" :ok-text="$t('Crm_Mark_Lost')" :cancel-text="$t('Cancel')"
      :ok-button-props="{ danger: true }" :confirm-loading="busy === 'lost'" @ok="doLost">
      <a-form layout="vertical">
        <a-form-item :label="$t('Crm_Lost_Reason')" required>
          <a-select v-model:value="lostForm.crm_lost_reason_id" :options="reasonOptions" :placeholder="$t('Crm_Choose_Reason')" />
        </a-form-item>
        <a-form-item v-if="lostStatuses.length > 1" :label="$t('Status')">
          <a-select v-model:value="lostForm.crm_lead_status_id" :options="lostStatuses.map(s => ({ value: s.id, label: s.name }))" />
        </a-form-item>
        <a-form-item :label="$t('Crm_Note')" class="mb0">
          <a-textarea v-model:value="lostForm.lost_note" :rows="3" :maxlength="1000" />
        </a-form-item>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
/** One lead: who, status steps, owner / tags inline, duplicates and history. */
import { ref, computed, onMounted, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { message, Modal } from 'ant-design-vue';
import {
  ArrowLeftOutlined, EditOutlined, DeleteOutlined, CloseCircleOutlined, RedoOutlined, CheckOutlined,
  MailOutlined, PhoneOutlined, GlobalOutlined, EnvironmentOutlined, AppstoreOutlined, PlusOutlined, UserAddOutlined, UserOutlined,
} from '@ant-design/icons-vue';
import { Building2, MessageCircle } from 'lucide-vue-next';
import CrmActivityComposer from './CrmActivityComposer.vue';
import CrmActivityFeed from './CrmActivityFeed.vue';
import CrmConvertModal from './CrmConvertModal.vue';
import CrmConversation from './CrmConversation.vue';
import PageHeader from '../../components/PageHeader.vue';
import { useFormat } from '../../composables/useFormat';
import { useAuthStore } from '../../stores/auth';
import http from '../../lib/http';
import { t, i18n } from '../../i18n';
import { RATINGS, LEAD_EVENTS, CAPTURE_CHANNELS, SCORE_CRITERIA, optionOf, labelOf, initials, avatarColor, tint, ago, scoreColor } from './crmOptions';

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();
const canLogActivity = computed(() => auth.can('crm_activities_add'));
const canEditActivity = computed(() => auth.can('crm_activities_edit'));
const canDeleteActivity = computed(() => auth.can('crm_activities_delete'));
const canMessage = computed(() => auth.can('crm_messages'));
const { money, dateTime } = useFormat();
const id = computed(() => route.params.id);

const canEdit = computed(() => auth.can('crm_leads_edit'));
const canDelete = computed(() => auth.can('crm_leads_delete'));
const canAddDeal = computed(() => auth.can('crm_deals_add'));
const canCustomers = computed(() => auth.can('crm_customers_view'));
const convertOpen = ref(false);
function afterConvert(res) {
  convertOpen.value = false;
  if (res?.deal_id) router.push(`/crm/deals/${res.deal_id}`);
  else load(false);
}

const lead = ref(null);
const meta = ref({ statuses: [], tags: [], owners: [], lost_reasons: [], can: {} });
const loading = ref(false);
const busy = ref('');

const kind = computed(() => lead.value?.status?.kind || 'open');
const rating = computed(() => optionOf(RATINGS, lead.value?.rating));
const openStatuses = computed(() => meta.value.statuses.filter(s => s.kind === 'open'));
const lostStatuses = computed(() => meta.value.statuses.filter(s => s.kind === 'lost'));
const currentIndex = computed(() => openStatuses.value.findIndex(s => s.id === lead.value?.status?.id));
const personName = computed(() => [lead.value?.first_name, lead.value?.last_name].filter(Boolean).join(' '));
const address = computed(() => [lead.value?.address, lead.value?.city, lead.value?.country].filter(Boolean).join(', '));
const websiteUrl = computed(() => (/^https?:\/\//i.test(lead.value?.website || '') ? lead.value.website : `https://${lead.value?.website}`));
const whatsApp = computed(() => `https://wa.me/${String(lead.value?.phone || '').replace(/\D/g, '')}`);
const dupCount = computed(() => (lead.value?.duplicates?.leads?.length || 0) + (lead.value?.duplicates?.clients?.length || 0));
const ownerOptions = computed(() => (meta.value.can?.view_all ? meta.value.owners : meta.value.owners.filter(o => o.id === meta.value.me))
  .map(o => ({ value: o.id, label: o.name })));
const tagOptions = computed(() => meta.value.tags.map(tg => ({ value: tg.id, label: tg.name })));
const reasonOptions = computed(() => meta.value.lost_reasons.map(r => ({ value: r.id, label: r.name })));

const tagValues = ref([]);
watch(lead, (l) => { tagValues.value = (l?.tags || []).map(tg => tg.id); });

function tp(key, fallback, params) {
  const out = i18n.global.t(key, params);
  return out !== key ? out : Object.entries(params).reduce((s, [k, v]) => s.replace(`{${k}}`, v ?? ''), fallback);
}
/** "Rating is Hot", "Activity in the last 7 days"… for the score popover. */
function critLabel(b) {
  const c = optionOf(SCORE_CRITERIA, b.criterion);
  const name = c.key ? t(c.key, c.label) : b.criterion;
  if (!c.kind || b.value === null || b.value === '') return name;
  let v = b.value;
  if (c.kind === 'rating') v = labelOf(RATINGS, b.value);
  else if (c.kind === 'channel') v = labelOf(CAPTURE_CHANNELS, b.value);
  else if (c.kind === 'source') v = meta.value.sources?.find(s => String(s.id) === String(b.value))?.name || b.value;
  else if (c.kind === 'tag') v = meta.value.tags?.find(x => String(x.id) === String(b.value))?.name || b.value;
  return name.includes('…') ? name.replace('…', v) : `${name} ${v}`;
}
function eventColor(e) { return (LEAD_EVENTS[e.type] || {}).color || '#94a3b8'; }
function eventText(e) {
  const m = e.meta || {};
  if (e.type === 'status_changed') return tp('Crm_Event_Status_From_To', 'Status: {from} → {to}', { from: m.from || '—', to: m.to || '—' });
  if (e.type === 'owner_changed') return tp('Crm_Event_Owner_From_To', 'Owner: {from} → {to}', { from: m.from || t('Crm_Unassigned', 'Unassigned'), to: m.to || t('Crm_Unassigned', 'Unassigned') });
  if (e.type === 'lost' && m.reason) return `${t(LEAD_EVENTS.lost.key, LEAD_EVENTS.lost.label)} — ${m.reason}`;
  if (e.type === 'converted' && m.client) return `${t(LEAD_EVENTS.converted.key, LEAD_EVENTS.converted.label)} — ${m.client}`;
  if (['deal_created', 'deal_won', 'deal_lost'].includes(e.type) && m.title) return `${t(LEAD_EVENTS[e.type].key, LEAD_EVENTS[e.type].label)} — ${m.title}`;
  if (e.type === 'captured') {
    const via = labelOf(CAPTURE_CHANNELS, m.via);
    const head = m.again ? tp('Crm_Event_Captured_Again', 'Got in touch again via {via}', { via }) : tp('Crm_Event_Captured_Via', 'Captured via {via}', { via });
    return [head, m.form ? `“${m.form}”` : '', m.rule ? tp('Crm_Event_Assigned_By_Rule', 'assigned by rule “{rule}”', { rule: m.rule }) : ''].filter(Boolean).join(' · ');
  }
  if (e.type === 'campaign' && m.campaign) return `${t(LEAD_EVENTS.campaign.key, LEAD_EVENTS.campaign.label)} — “${m.campaign}”${m.channel ? ` (${m.channel})` : ''}`;
  if (e.type === 'created' && m.rule) return `${t(LEAD_EVENTS.created.key, LEAD_EVENTS.created.label)} · ${tp('Crm_Event_Assigned_By_Rule', 'assigned by rule “{rule}”', { rule: m.rule })}`;
  const ev = LEAD_EVENTS[e.type];
  return ev ? t(ev.key, ev.label) : e.type;
}

async function load(spinner = true) {
  if (spinner) loading.value = true;
  try {
    const [m, d] = await Promise.all([meta.value.statuses.length ? meta.value : http.get('crm/leads/meta'), http.get(`crm/leads/${id.value}`)]);
    meta.value = m;
    lead.value = d.lead;
  } catch (e) {
    message.error(e?.data?.message || t('Crm_Err_Could_Not_Load_This_Lead', 'Could not load this lead'));
    if (e?.status === 403 || e?.status === 404) router.push('/crm/leads');
  } finally {
    loading.value = false;
  }
}

async function setStatus(s) {
  if (s.id === lead.value.status?.id) return;
  busy.value = 'status';
  try {
    await http.post(`crm/leads/${id.value}/status`, { crm_lead_status_id: s.id });
    await load(false);
  } catch (e) {
    message.error(e?.data?.message || t('Gen_Could_Not_Update', 'Could not update'));
  } finally {
    busy.value = '';
  }
}

async function setMarketing(receives) {
  busy.value = 'marketing';
  try {
    await http.post(`crm/leads/${id.value}/marketing`, { opt_out: !receives });
    await load(false);
  } catch (e) {
    message.error(e?.data?.message || t('Gen_Could_Not_Update', 'Could not update'));
  } finally {
    busy.value = '';
  }
}
async function setOwner(v) {
  busy.value = 'owner';
  try {
    await http.post('crm/leads/bulk', { ids: [Number(id.value)], action: 'assign', owner_id: v || '' });
    message.success(t('Updated_in_successfully', 'Updated successfully'));
    await load(false);
  } catch (e) {
    message.error(e?.data?.message || t('Gen_Could_Not_Update', 'Could not update'));
  } finally {
    busy.value = '';
  }
}

async function saveTags() {
  const before = (lead.value?.tags || []).map(tg => tg.id);
  const now = tagValues.value;
  const added = now.filter(v => !before.includes(v));
  const removed = before.filter(v => !now.includes(v));
  if (!added.length && !removed.length) return;
  try {
    for (const v of added) await http.post('crm/leads/bulk', { ids: [Number(id.value)], action: 'tag', tag: v });
    for (const v of removed) await http.post('crm/leads/bulk', { ids: [Number(id.value)], action: 'untag', tag: v });
    if (added.some(v => typeof v === 'string')) meta.value = await http.get('crm/leads/meta');
    await load(false);
  } catch (e) {
    message.error(e?.data?.message || t('Gen_Could_Not_Update', 'Could not update'));
  }
}

const lostOpen = ref(false);
const lostForm = ref({ crm_lost_reason_id: undefined, crm_lead_status_id: undefined, lost_note: '' });
function openLost() {
  lostForm.value = { crm_lost_reason_id: undefined, crm_lead_status_id: lostStatuses.value[0]?.id, lost_note: '' };
  lostOpen.value = true;
}
async function doLost() {
  if (!lostForm.value.crm_lost_reason_id) { message.error(t('Crm_Choose_Reason', 'Choose a reason')); return; }
  busy.value = 'lost';
  try {
    await http.post(`crm/leads/${id.value}/lost`, lostForm.value);
    lostOpen.value = false;
    await load(false);
  } catch (e) {
    message.error(e?.data?.message || t('Gen_Could_Not_Update', 'Could not update'));
  } finally {
    busy.value = '';
  }
}

async function reopen() {
  busy.value = 'reopen';
  try {
    await http.post(`crm/leads/${id.value}/reopen`);
    await load(false);
  } catch (e) {
    message.error(e?.data?.message || t('Gen_Could_Not_Update', 'Could not update'));
  } finally {
    busy.value = '';
  }
}

function remove() {
  Modal.confirm({
    title: t('Delete_Title', 'Are you sure?'),
    content: lead.value.name,
    okType: 'danger',
    okText: t('Delete_confirmButtonText', 'Yes, delete it!'),
    cancelText: t('Delete_cancelButtonText', 'Cancel'),
    async onOk() {
      try {
        await http.delete(`crm/leads/${id.value}`);
        message.success(t('Deleted_in_successfully', 'Deleted successfully'));
        router.push('/crm/leads');
      } catch (e) {
        message.error(e?.data?.message || t('Gen_Could_Not_Delete', 'Could not delete'));
      }
    },
  });
}

watch(id, (v) => { if (v) load(); });
onMounted(load);
</script>

<style scoped>
.muted { color: rgba(128, 128, 128, 0.8); }
.small-gap { margin-inline-start: 8px; font-size: 12.5px; }
.mono { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 12.5px; }
.mb0 { margin-bottom: 0; }
.dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; }
.card, .hero { margin-bottom: 16px; border-radius: 14px; }
.card-title { font-weight: 600; font-size: 15px; margin-bottom: 12px; }

.hero-main { display: flex; align-items: center; gap: 16px; flex-wrap: wrap; }
.hero-text { flex: 1; min-width: 220px; }
.hero-name { display: flex; align-items: center; gap: 10px; font-size: 20px; font-weight: 600; flex-wrap: wrap; }
.hero-sub { opacity: 0.65; margin-top: 2px; }
.rating { display: inline-flex; align-items: center; gap: 4px; padding: 2px 9px; border-radius: 999px; font-size: 12px; font-weight: 600; }
.hero-value { text-align: end; }
.hv-label { display: block; font-size: 12px; opacity: 0.6; }
.hero-score {
  width: 60px; height: 60px; border-radius: 50%; display: flex; flex-direction: column; align-items: center; justify-content: center;
  border: 3px solid var(--sc); background: color-mix(in srgb, var(--sc) 10%, transparent); cursor: help; flex: none;
}
.hs-num { font-size: 19px; font-weight: 700; line-height: 1; color: var(--sc); }
.hs-label { font-size: 10px; text-transform: uppercase; letter-spacing: 0.05em; opacity: 0.6; margin-top: 2px; }
.sb { min-width: 240px; display: flex; flex-direction: column; gap: 6px; }
.sb-row { display: flex; justify-content: space-between; gap: 16px; font-size: 13px; }
.sb-pts { font-weight: 700; color: #16a34a; }
.sb-pts.neg { color: #dc2626; }
.sb-foot { font-size: 11.5px; border-top: 1px solid rgba(128, 128, 128, 0.15); padding-top: 6px; margin-top: 2px; }
.hv-amount { font-size: 22px; font-weight: 700; color: var(--app-primary, #6d28d9); }

.steps { display: flex; margin-top: 18px; gap: 4px; overflow-x: auto; }
.step {
  flex: 1; min-width: 110px; padding: 8px 14px; border: 1px solid rgba(128, 128, 128, 0.25); background: rgba(128, 128, 128, 0.06);
  color: inherit; font: inherit; font-size: 13px; font-weight: 500; cursor: pointer; white-space: nowrap;
  clip-path: polygon(0 0, calc(100% - 10px) 0, 100% 50%, calc(100% - 10px) 100%, 0 100%, 10px 50%);
  transition: filter 0.15s;
}
.step:first-child { clip-path: polygon(0 0, calc(100% - 10px) 0, 100% 50%, calc(100% - 10px) 100%, 0 100%); border-radius: 8px 0 0 8px; }
.step.done, .step.current { color: #fff; }
.step.done { opacity: 0.75; }
.step:hover:not(:disabled) { filter: brightness(0.95); }
.step:disabled { cursor: default; }
.banner { margin-top: 16px; }
.dups { margin-bottom: 16px; border-radius: 12px; }
.dup { display: flex; align-items: center; gap: 6px; margin-top: 4px; flex-wrap: wrap; }

.contact { display: flex; flex-direction: column; gap: 10px; }
.c-row { display: flex; align-items: center; gap: 8px; color: inherit; word-break: break-word; }
a.c-row:hover { color: var(--app-primary, #6d28d9); }
.wa { color: #16a34a; display: inline-flex; margin-inline-start: 4px; }

.facts { display: grid; grid-template-columns: 110px 1fr; gap: 10px 12px; margin: 0 0 14px; align-items: center; }
.facts dt { font-size: 12.5px; opacity: 0.6; }
.facts dd { margin: 0; }
.src { display: inline-flex; align-items: center; gap: 6px; }
.status { display: inline-block; padding: 2px 10px; border-radius: 999px; font-size: 12px; font-weight: 600; }
.mini { margin-inline-start: 6px; }
.tags-head { font-size: 12.5px; opacity: 0.6; margin-bottom: 6px; }
.tags { display: flex; flex-wrap: wrap; gap: 4px; }
.tag { padding: 1px 8px; border-radius: 6px; font-size: 11.5px; font-weight: 500; }
.desc { white-space: pre-line; line-height: 1.6; }
.card-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px; }
.deals { display: flex; flex-direction: column; }
.deal-row { display: flex; align-items: center; gap: 10px; padding: 8px 0; border-bottom: 1px solid rgba(128, 128, 128, 0.12); color: inherit; }
.deal-row:last-child { border-bottom: 0; }
.deal-row:hover .deal-title { color: var(--app-primary, #6d28d9); }
.deal-title { flex: 1; font-weight: 500; min-width: 0; }
.deal-value { font-weight: 600; }

.timeline { margin-top: 6px; }
.ev-head { display: flex; justify-content: space-between; gap: 12px; }
.ev-title { font-weight: 500; }
.ev-time { font-size: 12px; opacity: 0.55; white-space: nowrap; }
.ev-note { margin-top: 2px; opacity: 0.8; white-space: pre-line; }
.ev-user { font-size: 12px; opacity: 0.55; margin-top: 2px; }
.nonext { margin-bottom: 12px; border-radius: 10px; }
.composer-box { padding: 12px; border: 1px solid rgba(128, 128, 128, 0.18); border-radius: 12px; margin-bottom: 16px; background: rgba(128, 128, 128, 0.03); }
</style>
