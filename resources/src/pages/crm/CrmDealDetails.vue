<template>
  <div class="page">
    <PageHeader :title="deal ? deal.title : $t('Crm_Deal')" :breadcrumb="[$t('Crm'), $t('Crm_Deals'), deal ? deal.deal_number : '']">
      <template #actions>
        <a-button @click="$router.push('/crm/pipeline')"><template #icon><ArrowLeftOutlined /></template>{{ $t('Crm_Pipeline') }}</a-button>
        <template v-if="deal">
          <a-button v-if="canDelete" danger type="text" @click="remove"><template #icon><DeleteOutlined /></template></a-button>
          <template v-if="canEdit">
            <a-button v-if="deal.status !== 'open'" :loading="busy === 'reopen'" @click="reopen"><template #icon><RedoOutlined /></template>{{ $t('Crm_Reopen') }}</a-button>
            <template v-else>
              <a-button danger @click="openLost"><template #icon><CloseCircleOutlined /></template>{{ $t('Crm_Mark_Lost') }}</a-button>
              <a-button class="won-btn" :loading="busy === 'won'" @click="markWon"><template #icon><TrophyOutlined /></template>{{ $t('Crm_Mark_Won') }}</a-button>
            </template>
            <a-button v-if="!deal.quotation && deal.products.length && canQuote" @click="quoteOpen = true"><template #icon><FileTextOutlined /></template>{{ $t('Crm_Create_Quotation') }}</a-button>
            <a-button type="primary" @click="$router.push(`/crm/deals/${id}/edit`)"><template #icon><EditOutlined /></template>{{ $t('Edit') }}</a-button>
          </template>
        </template>
      </template>
    </PageHeader>

    <a-spin :spinning="loading">
      <template v-if="deal">
        <a-card :bordered="false" class="hero">
          <div class="hero-main">
            <div class="hero-text">
              <div class="hero-title">
                {{ deal.title }}
                <a-tag :color="optionOf(DEAL_STATUSES, deal.status).color">{{ labelOf(DEAL_STATUSES, deal.status) }}</a-tag>
              </div>
              <div class="hero-sub">
                <span class="mono">{{ deal.deal_number }}</span>
                <template v-if="deal.pipeline"> · {{ deal.pipeline.name }}</template>
                <template v-if="deal.contact"> · {{ deal.contact }}</template>
              </div>
            </div>
            <div class="figure">
              <span class="f-label">{{ $t('Crm_Value') }}</span>
              <span class="f-value">{{ money(deal.value) }}</span>
            </div>
            <div v-if="deal.status === 'open'" class="figure">
              <span class="f-label">{{ $t('Crm_Weighted') }} ({{ deal.stage?.probability || 0 }}%)</span>
              <span class="f-value muted-v">{{ money(deal.weighted) }}</span>
            </div>
          </div>

          <div v-if="deal.status === 'open'" class="steps">
            <button
              v-for="(s, i) in stages" :key="s.id" type="button" class="step"
              :class="{ done: i < currentIndex, current: i === currentIndex }"
              :style="i <= currentIndex ? { background: s.color, borderColor: s.color } : {}"
              :disabled="!canEdit || busy === 'move'" @click="moveTo(s)"
            >
              <CheckOutlined v-if="i < currentIndex" /> {{ s.name }}
            </button>
          </div>
          <a-alert v-else-if="deal.status === 'won'" type="success" show-icon class="banner"
            :message="`${$t('Crm_Deal_Won')} — ${dateTime(deal.won_at)}`" />
          <a-alert v-else type="error" show-icon class="banner">
            <template #message>{{ $t('Crm_Deal_Lost') }}<template v-if="deal.lost_reason">: {{ deal.lost_reason }}</template></template>
            <template #description>{{ dateTime(deal.lost_at) }}<template v-if="deal.lost_note"> — {{ deal.lost_note }}</template></template>
          </a-alert>
        </a-card>

        <a-row :gutter="16">
          <a-col :xs="24" :lg="9">
            <a-card :bordered="false" class="card">
              <div class="card-title">{{ deal.client ? $t('Customer') : $t('Crm_Lead') }}</div>
              <template v-if="deal.lead">
                <router-link :to="`/crm/leads/${deal.lead.id}`" class="who">
                  <a-avatar :size="38" :style="{ background: avatarColor(deal.lead.name) }">{{ initials(deal.lead.name) }}</a-avatar>
                  <span>
                    <span class="who-name">{{ deal.lead.name }}</span>
                    <span class="who-sub">{{ deal.lead.lead_number }}<template v-if="deal.lead.company_name"> · {{ deal.lead.company_name }}</template></span>
                  </span>
                </router-link>
                <div class="contact">
                  <a v-if="deal.lead.email" :href="`mailto:${deal.lead.email}`"><MailOutlined /> {{ deal.lead.email }}</a>
                  <a v-if="deal.lead.phone" :href="`tel:${deal.lead.phone}`"><PhoneOutlined /> {{ deal.lead.phone }}</a>
                </div>
              </template>
              <div v-if="deal.client" class="client" :class="{ sep: deal.lead }">
                <router-link :to="`/crm/customers/${deal.client.id}`" class="who">
                  <a-avatar :size="38" style="background: #16a34a"><UserOutlined /></a-avatar>
                  <span>
                    <span class="who-name">{{ deal.client.name }}</span>
                    <span class="who-sub">{{ deal.client.phone || deal.client.email || '' }}</span>
                  </span>
                </router-link>
                <div v-if="deal.contact_person" class="contact">
                  <span class="cp-label">{{ $t('Crm_Contact_Person') }}</span>
                  <span><strong>{{ deal.contact_person.name }}</strong><template v-if="deal.contact_person.job_title"> · {{ deal.contact_person.job_title }}</template></span>
                  <a v-if="deal.contact_person.email" :href="`mailto:${deal.contact_person.email}`"><MailOutlined /> {{ deal.contact_person.email }}</a>
                  <a v-if="deal.contact_person.phone" :href="`tel:${deal.contact_person.phone}`"><PhoneOutlined /> {{ deal.contact_person.phone }}</a>
                </div>
              </div>
            </a-card>

            <a-card :bordered="false" class="card">
              <div class="card-title">{{ $t('Crm_Details') }}</div>
              <dl class="facts">
                <dt>{{ $t('Crm_Owner') }}</dt><dd>{{ deal.owner?.name || $t('Crm_Unassigned') }}</dd>
                <dt>{{ $t('Crm_Stage') }}</dt>
                <dd><span v-if="deal.stage" class="stage" :style="{ color: deal.stage.color, background: tint(deal.stage.color) }">{{ deal.stage.name }}</span></dd>
                <dt>{{ $t('Crm_Expected_Close') }}</dt>
                <dd><span :class="{ late: deal.is_overdue }">{{ deal.expected_close_date ? date(deal.expected_close_date) : '—' }}</span></dd>
                <dt>{{ $t('Crm_In_Stage_Since') }}</dt><dd>{{ deal.stage_entered_at ? dateTime(deal.stage_entered_at) : '—' }}
                  <a-tag v-if="deal.is_rotting" color="warning" class="mini">{{ $t('Crm_Rotting') }}</a-tag></dd>
                <dt>{{ $t('Crm_Created') }}</dt><dd>{{ dateTime(deal.created_at) }}<span v-if="deal.created_by_name" class="muted"> · {{ deal.created_by_name }}</span></dd>
                <template v-if="deal.client && plan.featureEnabled('client_portal')">
                  <dt>{{ $t('Crm_Client_Portal') }}</dt>
                  <dd>
                    <a-tooltip :title="deal.portal_account ? $t('Crm_Portal_Visible_Hint') : $t('Crm_No_Portal_Account')">
                      <a-switch :checked="deal.portal_visible" size="small" :disabled="!canEdit || (!deal.portal_account && !deal.portal_visible)" :loading="portalBusy" @change="setPortal" />
                    </a-tooltip>
                    <span class="muted portal-note">{{ deal.portal_visible ? $t('Crm_Shown_To_Customer') : (deal.portal_account ? $t('Crm_Hidden_From_Customer') : $t('Crm_No_Portal_Account_Short')) }}</span>
                  </dd>
                </template>
              </dl>
              <div v-if="deal.tags.length" class="tags">
                <span v-for="tg in deal.tags" :key="tg.id" class="tag" :style="{ color: tg.color, background: tint(tg.color) }">{{ tg.name }}</span>
              </div>
            </a-card>
          </a-col>

          <a-col :xs="24" :lg="15">
            <a-card v-if="deal.activities || canMessage" :bordered="false" class="card">
              <a-tabs :default-active-key="deal.activities ? 'activities' : 'conversation'" class="work-tabs">
                <a-tab-pane v-if="deal.activities" key="activities" :tab="$t('Crm_Activities')">
                  <a-alert
                    v-if="deal.status === 'open' && !deal.next_activity" type="warning" show-icon class="nonext"
                    :message="$t('Crm_No_Next_Step')" :description="$t('Crm_No_Next_Step_Hint')"
                  />
                  <CrmActivityComposer v-if="canLogActivity && deal.status === 'open'" :deal-id="deal.id" compact class="composer-box" @saved="load(false)" />
                  <CrmActivityFeed :activities="deal.activities" :can-delete="canDeleteActivity" :can-edit="canEditActivity" @changed="load(false)" />
                </a-tab-pane>
                <a-tab-pane v-if="canMessage" key="conversation" :tab="$t('Crm_Conversation')">
                  <CrmConversation :deal-id="deal.id" />
                </a-tab-pane>
              </a-tabs>
            </a-card>

            <a-card v-if="deal.quotation" :bordered="false" class="card quote">
              <div class="q-row">
                <span class="q-ic"><FileTextOutlined /></span>
                <span class="q-text">
                  <span class="q-title">
                    {{ $t('Crm_Quotation') }}
                    <router-link :to="`/quotations/${deal.quotation.id}`" class="mono">{{ deal.quotation.ref }}</router-link>
                  </span>
                  <span class="q-sub">{{ deal.quotation.date }} · {{ money(deal.quotation.total) }}</span>
                </span>
                <a-tag v-if="deal.quotation.converted_sale_id" color="success">
                  {{ $t('Crm_Converted_To_Sale') }}
                  <router-link :to="`/sales/${deal.quotation.converted_sale_id}`">{{ deal.quotation.sale_ref }}</router-link>
                </a-tag>
                <a-tag v-else :color="deal.quotation.status === 'sent' ? 'processing' : 'default'">{{ deal.quotation.status === 'sent' ? $t('Sent') : $t('Pending') }}</a-tag>
              </div>
              <div v-if="!deal.quotation.converted_sale_id && deal.status === 'open'" class="q-hint">{{ $t('Crm_Quote_Win_Hint') }}</div>
            </a-card>

            <a-card :bordered="false" class="card">
              <div class="card-title">{{ $t('Crm_Products') }}</div>
              <a-table v-if="deal.products.length" :data-source="deal.products" :columns="productColumns" row-key="id" size="small" :pagination="false">
                <template #bodyCell="{ column, record }">
                  <template v-if="column.key === 'name'"><span class="strong">{{ record.name }}</span><small v-if="record.code" class="muted"> {{ record.code }}</small></template>
                  <template v-else-if="column.key === 'unit_price'">{{ money(record.unit_price) }}</template>
                  <template v-else-if="column.key === 'discount_percent'">{{ record.discount_percent ? `${record.discount_percent}%` : '—' }}</template>
                  <template v-else-if="column.key === 'tax_percent'">{{ record.tax_percent ? `${record.tax_percent}%${record.tax_method === 2 ? ` (${$t('Crm_Incl')})` : ''}` : '—' }}</template>
                  <template v-else-if="column.key === 'total'"><span class="strong">{{ money(record.total) }}</span></template>
                </template>
                <template #summary>
                  <a-table-summary-row>
                    <a-table-summary-cell :col-span="5" class="r">{{ $t('Total') }}</a-table-summary-cell>
                    <a-table-summary-cell class="strong">{{ money(deal.value) }}</a-table-summary-cell>
                  </a-table-summary-row>
                </template>
              </a-table>
              <div v-else class="muted">{{ $t('Crm_No_Products') }}</div>
            </a-card>

            <a-card v-if="deal.description" :bordered="false" class="card">
              <div class="card-title">{{ $t('Description') }}</div>
              <div class="desc">{{ deal.description }}</div>
            </a-card>

            <a-card :bordered="false" class="card">
              <div class="card-title">{{ $t('Crm_History') }}</div>
              <a-timeline>
                <a-timeline-item v-for="e in deal.events" :key="e.id" :color="(DEAL_EVENTS[e.type] || {}).color || '#94a3b8'">
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

    <CrmQuotationModal :open="quoteOpen" :deal-id="id" @close="quoteOpen = false" @created="afterQuote" />

    <a-modal v-model:open="lostOpen" :title="$t('Crm_Mark_Lost')" :ok-text="$t('Crm_Mark_Lost')" :cancel-text="$t('Cancel')"
      :ok-button-props="{ danger: true }" :confirm-loading="busy === 'lost'" @ok="doLost">
      <a-form layout="vertical">
        <a-form-item :label="$t('Crm_Lost_Reason')" required>
          <a-select v-model:value="lostForm.crm_lost_reason_id" :options="reasonOptions" :placeholder="$t('Crm_Choose_Reason')" />
        </a-form-item>
        <a-form-item :label="$t('Crm_Note')" class="mb0">
          <a-textarea v-model:value="lostForm.lost_note" :rows="3" :maxlength="1000" />
        </a-form-item>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
/** One deal: value, stage steps, won / lost, products, who it is with, history. */
import { ref, computed, onMounted, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { message, Modal } from 'ant-design-vue';
import {
  ArrowLeftOutlined, EditOutlined, DeleteOutlined, CloseCircleOutlined, RedoOutlined, CheckOutlined,
  TrophyOutlined, MailOutlined, PhoneOutlined, UserOutlined, FileTextOutlined,
} from '@ant-design/icons-vue';
import CrmActivityComposer from './CrmActivityComposer.vue';
import CrmActivityFeed from './CrmActivityFeed.vue';
import CrmQuotationModal from './CrmQuotationModal.vue';
import CrmConversation from './CrmConversation.vue';
import PageHeader from '../../components/PageHeader.vue';
import { useFormat } from '../../composables/useFormat';
import { useAuthStore } from '../../stores/auth';
import { usePlanStore } from '../../stores/plan';
import http from '../../lib/http';
import { t, i18n } from '../../i18n';
import { DEAL_STATUSES, DEAL_EVENTS, optionOf, labelOf, initials, avatarColor, tint, ago } from './crmOptions';

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();
const plan = usePlanStore();
const canLogActivity = computed(() => auth.can('crm_activities_add'));
const canEditActivity = computed(() => auth.can('crm_activities_edit'));
const canDeleteActivity = computed(() => auth.can('crm_activities_delete'));
const canMessage = computed(() => auth.can('crm_messages'));
const portalBusy = ref(false);
async function setPortal(visible) {
  portalBusy.value = true;
  try {
    await http.post(`crm/deals/${deal.value.id}/portal`, { visible });
    deal.value.portal_visible = visible;
  } catch (e) {
    message.error(e?.data?.message || t('Gen_Could_Not_Update', 'Could not update'));
  } finally {
    portalBusy.value = false;
  }
}
const { money, date, dateTime } = useFormat();
const id = computed(() => route.params.id);
const canEdit = computed(() => auth.can('crm_deals_edit'));
const canDelete = computed(() => auth.can('crm_deals_delete'));
const canQuote = computed(() => auth.can('Quotations_add'));
const quoteOpen = ref(false);
function afterQuote() { quoteOpen.value = false; load(false); }

const deal = ref(null);
const meta = ref({ pipelines: [], lost_reasons: [] });
const loading = ref(false);
const busy = ref('');

const stages = computed(() => meta.value.pipelines.find(p => p.id === deal.value?.pipeline?.id)?.stages || []);
const currentIndex = computed(() => stages.value.findIndex(s => s.id === deal.value?.stage?.id));
const reasonOptions = computed(() => meta.value.lost_reasons.map(r => ({ value: r.id, label: r.name })));
const productColumns = computed(() => [
  { title: t('Crm_Item', 'Item'), key: 'name' },
  { title: t('Quantity', 'Qty'), dataIndex: 'quantity', key: 'quantity', width: 80 },
  { title: t('Crm_Unit_Price', 'Unit price'), key: 'unit_price', width: 120 },
  { title: t('Crm_Discount_Pct', 'Discount %'), key: 'discount_percent', width: 100 },
  { title: t('Crm_Tax_Pct', 'Tax %'), key: 'tax_percent', width: 110 },
  { title: t('Total', 'Total'), key: 'total', width: 130 },
]);

function tp(key, fallback, params) {
  const out = i18n.global.t(key, params);
  return out !== key ? out : Object.entries(params).reduce((s, [k, v]) => s.replace(`{${k}}`, v ?? ''), fallback);
}
function eventText(e) {
  const m = e.meta || {};
  if (e.type === 'stage_changed') return tp('Crm_Event_Stage_From_To', 'Moved: {from} → {to}', { from: m.from || '—', to: m.to || '—' });
  if (e.type === 'owner_changed') return tp('Crm_Event_Owner_From_To', 'Owner: {from} → {to}', { from: m.from || t('Crm_Unassigned', 'Unassigned'), to: m.to || t('Crm_Unassigned', 'Unassigned') });
  if (e.type === 'lost' && m.reason) return `${t('Crm_Event_Deal_Lost', 'Deal lost')} — ${m.reason}`;
  if (e.type === 'won' && m.sale) return `${t('Crm_Event_Deal_Won', 'Deal won')} — ${t('Crm_Via_Sale', 'quotation turned into sale')} ${m.sale}`;
  if (e.type === 'won' && m.value !== undefined) return `${t('Crm_Event_Deal_Won', 'Deal won')} — ${money(m.value)}`;
  if (e.type === 'quotation_created' && m.ref) return `${t('Crm_Event_Quotation_Created', 'Quotation created')} — ${m.ref} (${money(m.total)})`;
  const ev = DEAL_EVENTS[e.type];
  return ev ? t(ev.key, ev.label) : e.type;
}

async function load(spinner = true) {
  if (spinner) loading.value = true;
  try {
    const [m, d] = await Promise.all([meta.value.pipelines.length ? meta.value : http.get('crm/deals/meta'), http.get(`crm/deals/${id.value}`)]);
    meta.value = m;
    deal.value = d.deal;
  } catch (e) {
    message.error(e?.data?.message || t('Crm_Err_Could_Not_Load_This_Deal', 'Could not load this deal'));
    if (e?.status === 403 || e?.status === 404) router.push('/crm/deals');
  } finally {
    loading.value = false;
  }
}

async function act(key, fn) {
  busy.value = key;
  try { await fn(); await load(false); } catch (e) { message.error(e?.data?.message || t('Gen_Could_Not_Update', 'Could not update')); } finally { busy.value = ''; }
}
function moveTo(s) {
  if (s.id === deal.value.stage?.id) return;
  act('move', () => http.post(`crm/deals/${id.value}/move`, { crm_pipeline_stage_id: s.id }));
}
function markWon() { act('won', () => http.post(`crm/deals/${id.value}/won`)); }
function reopen() { act('reopen', () => http.post(`crm/deals/${id.value}/reopen`)); }

const lostOpen = ref(false);
const lostForm = ref({ crm_lost_reason_id: undefined, lost_note: '' });
function openLost() { lostForm.value = { crm_lost_reason_id: undefined, lost_note: '' }; lostOpen.value = true; }
async function doLost() {
  if (!lostForm.value.crm_lost_reason_id) { message.error(t('Crm_Choose_Reason', 'Choose a reason')); return; }
  await act('lost', async () => { await http.post(`crm/deals/${id.value}/lost`, lostForm.value); lostOpen.value = false; });
}

function remove() {
  Modal.confirm({
    title: t('Delete_Title', 'Are you sure?'),
    content: deal.value.title,
    okType: 'danger',
    okText: t('Delete_confirmButtonText', 'Yes, delete it!'),
    cancelText: t('Delete_cancelButtonText', 'Cancel'),
    async onOk() {
      try {
        await http.delete(`crm/deals/${id.value}`);
        router.push('/crm/deals');
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
.portal-note { margin-inline-start: 8px; font-size: 12.5px; }
.mono { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 12.5px; }
.mb0 { margin-bottom: 0; }
.r { text-align: end; }
.strong { font-weight: 600; }
.card, .hero { margin-bottom: 16px; border-radius: 14px; }
.card-title { font-weight: 600; font-size: 15px; margin-bottom: 12px; }
.won-btn { color: #16a34a; border-color: #16a34a; }
.hero-main { display: flex; align-items: center; gap: 24px; flex-wrap: wrap; }
.hero-text { flex: 1; min-width: 240px; }
.hero-title { display: flex; align-items: center; gap: 10px; font-size: 20px; font-weight: 600; flex-wrap: wrap; }
.hero-sub { opacity: 0.65; margin-top: 2px; }
.figure { text-align: end; }
.f-label { display: block; font-size: 12px; opacity: 0.6; }
.f-value { font-size: 22px; font-weight: 700; color: var(--app-primary, #6d28d9); }
.muted-v { color: inherit; opacity: 0.75; font-size: 18px; }
.steps { display: flex; margin-top: 18px; gap: 4px; overflow-x: auto; }
.step {
  flex: 1; min-width: 110px; padding: 8px 14px; border: 1px solid rgba(128, 128, 128, 0.25); background: rgba(128, 128, 128, 0.06);
  color: inherit; font: inherit; font-size: 13px; font-weight: 500; cursor: pointer; white-space: nowrap;
  clip-path: polygon(0 0, calc(100% - 10px) 0, 100% 50%, calc(100% - 10px) 100%, 0 100%, 10px 50%);
}
.step:first-child { clip-path: polygon(0 0, calc(100% - 10px) 0, 100% 50%, calc(100% - 10px) 100%, 0 100%); border-radius: 8px 0 0 8px; }
.step.done, .step.current { color: #fff; }
.step.done { opacity: 0.75; }
.step:disabled { cursor: default; }
.banner { margin-top: 16px; }
.who { display: flex; align-items: center; gap: 10px; color: inherit; }
.who-name { display: block; font-weight: 600; }
.who:hover .who-name { color: var(--app-primary, #6d28d9); }
.who-sub { display: block; font-size: 12px; opacity: 0.6; }
.contact { display: flex; flex-direction: column; gap: 6px; margin-top: 12px; }
.contact a { color: inherit; display: inline-flex; align-items: center; gap: 6px; }
.client.sep { margin-top: 14px; padding-top: 14px; border-top: 1px solid rgba(128, 128, 128, 0.15); }
.facts { display: grid; grid-template-columns: 120px 1fr; gap: 10px 12px; margin: 0; align-items: center; }
.facts dt { font-size: 12.5px; opacity: 0.6; }
.facts dd { margin: 0; }
.stage { display: inline-block; padding: 2px 10px; border-radius: 999px; font-size: 12px; font-weight: 600; }
.late { color: #dc2626; font-weight: 600; }
.mini { margin-inline-start: 6px; }
.tags { display: flex; flex-wrap: wrap; gap: 4px; margin-top: 14px; }
.tag { padding: 1px 8px; border-radius: 6px; font-size: 11.5px; font-weight: 500; }
.desc { white-space: pre-line; line-height: 1.6; }
.ev-head { display: flex; justify-content: space-between; gap: 12px; }
.ev-title { font-weight: 500; }
.ev-time { font-size: 12px; opacity: 0.55; white-space: nowrap; }
.ev-note { margin-top: 2px; opacity: 0.8; white-space: pre-line; }
.ev-user { font-size: 12px; opacity: 0.55; margin-top: 2px; }
.nonext { margin-bottom: 12px; border-radius: 10px; }
.composer-box { padding: 12px; border: 1px solid rgba(128, 128, 128, 0.18); border-radius: 12px; margin-bottom: 16px; background: rgba(128, 128, 128, 0.03); }
.quote { border: 1px solid rgba(var(--app-primary-rgb, 109, 40, 217), 0.25); }
.q-row { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
.q-ic { width: 36px; height: 36px; display: inline-flex; align-items: center; justify-content: center; border-radius: 10px;
  color: var(--app-primary, #6d28d9); background: rgba(var(--app-primary-rgb, 109, 40, 217), 0.1); font-size: 16px; }
.q-text { flex: 1; min-width: 0; }
.q-title { display: block; font-weight: 600; }
.q-sub { display: block; font-size: 12px; opacity: 0.6; }
.q-hint { margin-top: 8px; font-size: 12px; opacity: 0.6; }
.cp-label { font-size: 11px; font-weight: 700; text-transform: uppercase; opacity: 0.55; }
</style>
