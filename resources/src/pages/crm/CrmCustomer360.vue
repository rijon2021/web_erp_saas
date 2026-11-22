<template>
  <div class="page">
    <PageHeader :title="c ? c.name : $t('Customer')" :breadcrumb="[$t('Crm'), $t('Crm_Customers'), c ? c.name : '']">
      <template #actions>
        <a-button @click="$router.push('/crm/customers')"><template #icon><ArrowLeftOutlined /></template>{{ $t('Back') }}</a-button>
        <a-button v-if="c && canAddDeal" type="primary" @click="$router.push(`/crm/deals/create?client=${c.id}`)"><template #icon><PlusOutlined /></template>{{ $t('Crm_New_Deal') }}</a-button>
      </template>
    </PageHeader>

    <a-spin :spinning="loading">
      <template v-if="c">
        <a-card :bordered="false" class="hero">
          <div class="hero-main">
            <a-avatar :size="58" :style="{ background: avatarColor(c.name), fontSize: '20px' }">{{ initials(c.name) }}</a-avatar>
            <div class="hero-text">
              <div class="hero-name">{{ c.name }}</div>
              <div class="hero-sub">
                <span class="mono">#{{ c.code }}</span>
                <template v-if="primary"> · {{ primary.name }}<template v-if="primary.job_title"> ({{ primary.job_title }})</template></template>
                <template v-if="c.city"> · {{ c.city }}</template>
              </div>
            </div>
          </div>
          <div class="figs">
            <div class="fig"><span class="f-label">{{ $t('Crm_Open_Pipeline') }}</span><span class="f-value">{{ money(c.money.open_value || 0) }}</span></div>
            <div class="fig"><span class="f-label">{{ $t('Crm_Won_Deals') }}</span><span class="f-value ok">{{ money(c.money.won_value || 0) }}</span></div>
            <template v-if="c.money.sales_count !== undefined">
              <div class="fig"><span class="f-label">{{ $t('Crm_Lifetime_Sales') }}</span><span class="f-value">{{ money(c.money.sales_total) }}</span><span class="f-note">{{ c.money.sales_count }} {{ $t('Crm_Sales_Lower') }}</span></div>
              <div class="fig"><span class="f-label">{{ $t('Crm_Unpaid') }}</span><span class="f-value" :class="{ bad: c.money.unpaid > 0 }">{{ money(c.money.unpaid) }}</span></div>
              <div class="fig"><span class="f-label">{{ $t('Crm_Last_Sale') }}</span><span class="f-value small">{{ c.money.last_sale ? date(c.money.last_sale) : '—' }}</span></div>
            </template>
          </div>
        </a-card>

        <a-row :gutter="16">
          <a-col :xs="24" :lg="8">
            <a-card :bordered="false" class="card">
              <div class="card-title">{{ $t('Crm_Contact_Details') }}</div>
              <div class="contact">
                <a v-if="c.email" :href="`mailto:${c.email}`"><MailOutlined /> {{ c.email }}</a>
                <a v-if="c.phone" :href="`tel:${c.phone}`"><PhoneOutlined /> {{ c.phone }}</a>
                <span v-if="address"><EnvironmentOutlined /> {{ address }}</span>
                <span v-if="c.tax_number"><IdcardOutlined /> {{ c.tax_number }}</span>
                <span class="muted small">{{ $t('Crm_Customer_Since') }} {{ date(c.created_at) }}</span>
              </div>
            </a-card>

            <a-card :bordered="false" class="card">
              <div class="card-head">
                <span class="card-title mb0">{{ $t('Crm_Contacts') }}</span>
                <a-button v-if="c.can.contacts" size="small" @click="openContact()"><template #icon><PlusOutlined /></template>{{ $t('Crm_Add') }}</a-button>
              </div>
              <div v-if="c.contacts.length" class="people">
                <div v-for="p in c.contacts" :key="p.id" class="person">
                  <a-avatar :size="32" :style="{ background: avatarColor(p.name), fontSize: '12px' }">{{ initials(p.name) }}</a-avatar>
                  <div class="p-text">
                    <div class="p-name">{{ p.name }} <StarFilled v-if="p.is_primary" class="star" /></div>
                    <div class="p-sub">{{ p.job_title || '' }}</div>
                    <div class="p-links">
                      <a v-if="p.email" :href="`mailto:${p.email}`"><MailOutlined /></a>
                      <a v-if="p.phone" :href="`tel:${p.phone}`"><PhoneOutlined /> {{ p.phone }}</a>
                    </div>
                  </div>
                  <a-space v-if="c.can.contacts" :size="0" class="tools">
                    <a-button type="text" size="small" @click="openContact(p)"><template #icon><EditOutlined /></template></a-button>
                    <a-button type="text" size="small" danger @click="removeContact(p)"><template #icon><DeleteOutlined /></template></a-button>
                  </a-space>
                </div>
              </div>
              <div v-else class="muted">{{ $t('Crm_No_Contacts') }}</div>
            </a-card>
          </a-col>

          <a-col :xs="24" :lg="16">
            <a-card :bordered="false" class="card">
              <a-tabs v-model:activeKey="tab">
                <a-tab-pane v-if="c.activities" key="activities" :tab="`${$t('Crm_Activities')} (${c.activities.length})`">
                  <CrmActivityComposer v-if="canLogActivity" :client-id="c.id" compact class="composer-box" @saved="load(false)" />
                  <CrmActivityFeed :activities="c.activities" show-related :can-edit="canEditActivity" :can-delete="canDeleteActivity" @changed="load(false)" />
                </a-tab-pane>
                <a-tab-pane v-if="canMessage" key="conversation" :tab="$t('Crm_Conversation')">
                  <CrmConversation :client-id="c.id" />
                </a-tab-pane>
                <a-tab-pane key="deals" :tab="`${$t('Crm_Deals')} (${c.deals.length})`">
                  <div v-if="c.deals.length" class="rows">
                    <router-link v-for="d in c.deals" :key="d.id" :to="`/crm/deals/${d.id}`" class="row">
                      <span class="r-main"><strong>{{ d.title }}</strong><small> · {{ d.deal_number }}<template v-if="d.owner"> · {{ d.owner }}</template></small></span>
                      <a-tag v-if="d.status !== 'open'" :color="d.status === 'won' ? 'success' : 'error'">{{ d.status === 'won' ? $t('Crm_Deal_Won') : $t('Crm_Deal_Lost') }}</a-tag>
                      <span v-else-if="d.stage" class="pill" :style="{ color: d.stage.color, background: tint(d.stage.color) }">{{ d.stage.name }}</span>
                      <span class="r-value">{{ money(d.value) }}</span>
                    </router-link>
                  </div>
                  <a-empty v-else :image="simpleImage" :description="$t('Crm_No_Deals_Yet')" />
                </a-tab-pane>
                <a-tab-pane v-if="c.quotations" key="quotations" :tab="`${$t('Quotations')} (${c.quotations.length})`">
                  <div v-if="c.quotations.length" class="rows">
                    <router-link v-for="q in c.quotations" :key="q.id" :to="`/quotations/${q.id}`" class="row">
                      <span class="r-main"><strong class="mono">{{ q.ref }}</strong><small> · {{ date(q.date) }}</small><a-tag v-if="q.from_crm" color="purple" class="mini">CRM</a-tag></span>
                      <a-tag v-if="q.converted_sale_id" color="success">{{ $t('Crm_Converted_To_Sale') }}</a-tag>
                      <a-tag v-else>{{ q.status === 'sent' ? $t('Sent') : $t('Pending') }}</a-tag>
                      <span class="r-value">{{ money(q.total) }}</span>
                    </router-link>
                  </div>
                  <a-empty v-else :image="simpleImage" />
                </a-tab-pane>
                <a-tab-pane v-if="c.sales" key="sales" :tab="`${$t('Sales')} (${c.money.sales_count || 0})`">
                  <div v-if="c.sales.length" class="rows">
                    <router-link v-for="s in c.sales" :key="s.id" :to="`/sales/${s.id}`" class="row">
                      <span class="r-main"><strong class="mono">{{ s.ref }}</strong><small> · {{ date(s.date) }}</small></span>
                      <a-tag :color="s.payment_status === 'paid' ? 'success' : (s.payment_status === 'partial' ? 'processing' : 'warning')">{{ $t(s.payment_status === 'paid' ? 'Paid' : (s.payment_status === 'partial' ? 'partial' : 'Unpaid')) }}</a-tag>
                      <span class="r-value">{{ money(s.total) }}<small v-if="s.total - s.paid > 0.004" class="due"> · {{ $t('Hosp_Amount_Due', { amount: money(s.total - s.paid) }) }}</small></span>
                    </router-link>
                  </div>
                  <a-empty v-else :image="simpleImage" />
                </a-tab-pane>
                <a-tab-pane v-if="c.leads.length" key="leads" :tab="`${$t('Crm_Leads')} (${c.leads.length})`">
                  <div class="rows">
                    <router-link v-for="l in c.leads" :key="l.id" :to="`/crm/leads/${l.id}`" class="row">
                      <span class="r-main"><strong>{{ l.name }}</strong><small> · {{ l.lead_number }}</small></span>
                      <span v-if="l.status" class="pill" :style="{ color: l.status.color, background: tint(l.status.color) }">{{ l.status.name }}</span>
                      <span class="r-value small">{{ l.converted_at ? date(l.converted_at) : '' }}</span>
                    </router-link>
                  </div>
                </a-tab-pane>
              </a-tabs>
            </a-card>
          </a-col>
        </a-row>
      </template>
    </a-spin>

    <a-modal v-model:open="contactOpen" :title="contactForm.id ? $t('Crm_Edit_Contact') : $t('Crm_New_Contact')" :ok-text="$t('Save')" :cancel-text="$t('Cancel')" :confirm-loading="saving" @ok="saveContact">
      <a-form layout="vertical">
        <a-form-item :label="$t('Name')" required><a-input v-model:value="contactForm.name" :maxlength="191" /></a-form-item>
        <a-form-item :label="$t('Crm_Job_Title')"><a-input v-model:value="contactForm.job_title" :maxlength="120" /></a-form-item>
        <a-row :gutter="12">
          <a-col :span="12"><a-form-item :label="$t('Email')"><a-input v-model:value="contactForm.email" :maxlength="191" /></a-form-item></a-col>
          <a-col :span="12"><a-form-item :label="$t('Phone')"><a-input v-model:value="contactForm.phone" :maxlength="50" /></a-form-item></a-col>
        </a-row>
        <a-form-item :label="$t('Crm_Note')"><a-textarea v-model:value="contactForm.notes" :rows="2" :maxlength="1000" /></a-form-item>
        <a-checkbox v-model:checked="contactForm.is_primary">{{ $t('Crm_Primary_Contact') }}</a-checkbox>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
/** Customer 360 — GET crm/customers/{id}: everything the CRM and Stocky know about one customer. */
import { ref, computed, onMounted, watch } from 'vue';
import { useRoute } from 'vue-router';
import { message, Modal, Empty } from 'ant-design-vue';
import {
  ArrowLeftOutlined, PlusOutlined, MailOutlined, PhoneOutlined, EnvironmentOutlined, IdcardOutlined,
  EditOutlined, DeleteOutlined, StarFilled,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import CrmActivityComposer from './CrmActivityComposer.vue';
import CrmActivityFeed from './CrmActivityFeed.vue';
import CrmConversation from './CrmConversation.vue';
import { useFormat } from '../../composables/useFormat';
import { useAuthStore } from '../../stores/auth';
import http from '../../lib/http';
import { t } from '../../i18n';
import { initials, avatarColor, tint } from './crmOptions';

const route = useRoute();
const auth = useAuthStore();
const { money, date } = useFormat();
const simpleImage = Empty.PRESENTED_IMAGE_SIMPLE;
const canAddDeal = computed(() => auth.can('crm_deals_add'));
const canLogActivity = computed(() => auth.can('crm_activities_add'));
const canEditActivity = computed(() => auth.can('crm_activities_edit'));
const canDeleteActivity = computed(() => auth.can('crm_activities_delete'));
const canMessage = computed(() => auth.can('crm_messages'));

const c = ref(null);
const loading = ref(false);
const saving = ref(false);
const tab = ref('activities');
const primary = computed(() => c.value?.contacts?.find(p => p.is_primary));
const address = computed(() => [c.value?.address, c.value?.city, c.value?.country].filter(Boolean).join(', '));

async function load(spinner = true) {
  if (spinner) loading.value = true;
  try {
    c.value = (await http.get(`crm/customers/${route.params.id}`)).customer;
    if (!c.value.activities && tab.value === 'activities') tab.value = 'deals';
  } catch (e) {
    message.error(e?.data?.message || t('Gen_Could_Not_Load_Customer', 'Could not load this customer'));
  } finally {
    loading.value = false;
  }
}

const contactOpen = ref(false);
const contactForm = ref({});
function openContact(p = null) {
  contactForm.value = p ? { ...p } : { id: null, name: '', job_title: '', email: '', phone: '', notes: '', is_primary: !c.value.contacts.length };
  contactOpen.value = true;
}
async function saveContact() {
  if (!contactForm.value.name?.trim()) { message.error(t('Field_is_required', 'This field is required')); return; }
  saving.value = true;
  const f = contactForm.value;
  const body = { name: f.name, job_title: f.job_title || '', email: f.email || '', phone: f.phone || '', notes: f.notes || '', is_primary: !!f.is_primary };
  try {
    if (f.id) await http.put(`crm/contacts/${f.id}`, body);
    else await http.post(`crm/customers/${c.value.id}/contacts`, body);
    contactOpen.value = false;
    load(false);
  } catch (e) {
    const errors = e?.data?.errors;
    message.error((errors && Object.values(errors)[0]?.[0]) || e?.data?.message || t('Gen_Could_Not_Save', 'Could not save'));
  } finally {
    saving.value = false;
  }
}
function removeContact(p) {
  Modal.confirm({
    title: t('Delete_Title', 'Are you sure?'),
    content: p.name,
    okType: 'danger',
    okText: t('Delete_confirmButtonText', 'Yes, delete it!'),
    cancelText: t('Delete_cancelButtonText', 'Cancel'),
    async onOk() {
      try { await http.delete(`crm/contacts/${p.id}`); load(false); } catch (e) { message.error(e?.data?.message || t('Gen_Could_Not_Delete', 'Could not delete')); }
    },
  });
}

watch(() => route.params.id, (v) => { if (v) load(); });
onMounted(load);
</script>

<style scoped>
.muted { color: rgba(128, 128, 128, 0.8); }
.small { font-size: 12px; }
.mono { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 12.5px; }
.mb0 { margin-bottom: 0; }
.card, .hero { margin-bottom: 16px; border-radius: 14px; }
.card-title { font-weight: 600; font-size: 15px; margin-bottom: 12px; }
.card-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; }
.hero-main { display: flex; align-items: center; gap: 16px; }
.hero-name { font-size: 20px; font-weight: 600; }
.hero-sub { opacity: 0.65; margin-top: 2px; }
.figs { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; margin-top: 18px; }
.fig { padding: 10px 14px; border-radius: 12px; background: rgba(128, 128, 128, 0.06); display: flex; flex-direction: column; }
.f-label { font-size: 12px; opacity: 0.6; }
.f-value { font-size: 18px; font-weight: 700; }
.f-value.ok { color: #16a34a; }
.f-value.bad { color: #dc2626; }
.f-value.small { font-size: 15px; }
.f-note { font-size: 11.5px; opacity: 0.6; }
.contact { display: flex; flex-direction: column; gap: 8px; }
.contact a, .contact span { color: inherit; display: inline-flex; align-items: center; gap: 6px; word-break: break-word; }
.people { display: flex; flex-direction: column; }
.person { display: flex; align-items: flex-start; gap: 10px; padding: 8px 0; border-bottom: 1px solid rgba(128, 128, 128, 0.12); }
.person:last-child { border-bottom: 0; }
.p-text { flex: 1; min-width: 0; }
.p-name { font-weight: 600; display: flex; align-items: center; gap: 6px; }
.star { color: #f59e0b; font-size: 12px; }
.p-sub { font-size: 12px; opacity: 0.6; }
.p-links { display: flex; gap: 10px; font-size: 12.5px; margin-top: 2px; }
.p-links a { color: inherit; }
.tools { opacity: 0.5; }
.person:hover .tools { opacity: 1; }
.composer-box { padding: 12px; border: 1px solid rgba(128, 128, 128, 0.18); border-radius: 12px; margin-bottom: 16px; background: rgba(128, 128, 128, 0.03); }
.rows { display: flex; flex-direction: column; }
.row { display: flex; align-items: center; gap: 10px; padding: 10px 2px; border-bottom: 1px solid rgba(128, 128, 128, 0.12); color: inherit; }
.row:last-child { border-bottom: 0; }
.row:hover strong { color: var(--app-primary, #6d28d9); }
.r-main { flex: 1; min-width: 0; }
.r-main small { opacity: 0.6; }
.r-value { font-weight: 600; white-space: nowrap; }
.r-value .due { color: #dc2626; font-weight: 500; }
.pill { display: inline-block; padding: 2px 10px; border-radius: 999px; font-size: 12px; font-weight: 600; }
.mini { margin-inline-start: 6px; }
</style>
