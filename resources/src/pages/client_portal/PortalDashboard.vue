<template>
  <div class="page">
    <PageHeader :title="$t('Portal_Dashboard')" :breadcrumb="[$t('Client_Portal'), $t('Portal_Dashboard')]">
      <template #actions>
        <a-button href="/portal" target="_blank" rel="noopener">
          <template #icon><ExternalLink :size="14" class="btn-ic" /></template>
          {{ $t('Portal_Open_Portal') }}
        </a-button>
        <a-button type="primary" @click="router.push('/client-portal/accounts')">
          <template #icon><UserCog :size="14" class="btn-ic" /></template>
          {{ $t('Portal_Accounts') }}
        </a-button>
      </template>
    </PageHeader>

    <a-spin :spinning="loading">
      <a-alert
        v-if="loaded && !data.portal.enabled"
        type="warning" show-icon style="margin-bottom: 16px"
        :message="$t('Portal_Is_Off')"
        :description="$t('Portal_Is_Off_Help')"
      >
        <template v-if="auth.can('portal_settings')" #action>
          <a-button size="small" @click="router.push('/client-portal/settings')">{{ $t('Portal_Settings') }}</a-button>
        </template>
      </a-alert>

      <!-- Accounts -->
      <a-row :gutter="[16, 16]" style="margin-bottom: 16px">
        <a-col v-for="s in statCards" :key="s.key" :xs="12" :md="8" :xl="4">
          <a-card size="small" hoverable class="stat" @click="s.to && router.push(s.to)">
            <a-statistic :title="s.title" :value="s.value" :value-style="{ color: s.color }" />
            <div v-if="s.sub" class="stat-sub">{{ s.sub }}</div>
          </a-card>
        </a-col>
      </a-row>

      <a-row :gutter="[16, 16]">
        <!-- Waiting for staff -->
        <a-col :xs="24" :lg="8">
          <a-card size="small" :title="$t('Portal_Waiting_For_You')" class="full">
            <div class="work-list">
              <a v-for="w in workItems" :key="w.key" class="work" :class="{ zero: !w.count }" @click="router.push(w.to)">
                <span class="work-ic" :style="{ color: w.color, background: w.bg }"><component :is="w.icon" :size="16" /></span>
                <span class="work-label">{{ w.label }}</span>
                <a-badge :count="w.count" :show-zero="true" :number-style="w.count ? { background: w.color } : { background: 'rgba(0,0,0,.15)' }" />
              </a>
            </div>
            <div class="modules-note">
              <Settings2 :size="13" />
              {{ $t('Portal_Modules_On', { on: data.portal.modules_on, total: data.portal.modules_total }) }}
              <router-link v-if="auth.can('portal_settings')" to="/client-portal/settings">{{ $t('Portal_Settings') }}</router-link>
            </div>
          </a-card>
        </a-col>

        <!-- Recent accounts -->
        <a-col :xs="24" :lg="8">
          <a-card size="small" :title="$t('Portal_Recent_Accounts')" class="full" :body-style="{ padding: 0 }">
            <template #extra><router-link to="/client-portal/accounts">{{ $t('View_all') }}</router-link></template>
            <a-list :data-source="data.recent_accounts" size="small" :locale="{ emptyText: $t('NodataAvailable') }">
              <template #renderItem="{ item }">
                <a-list-item style="padding: 10px 16px">
                  <a-list-item-meta :title="item.client_name || '—'" :description="item.email" />
                  <PortalStateTag :account="item" />
                </a-list-item>
              </template>
            </a-list>
          </a-card>
        </a-col>

        <!-- Recent activity -->
        <a-col :xs="24" :lg="8">
          <a-card size="small" :title="$t('Portal_Recent_Activity')" class="full" :body-style="{ padding: 0 }">
            <a-list :data-source="data.activity" size="small" :locale="{ emptyText: $t('NodataAvailable') }">
              <template #renderItem="{ item }">
                <a-list-item class="act" style="padding: 10px 16px" @click="openActivity(item)">
                  <a-list-item-meta>
                    <template #avatar>
                      <span class="work-ic" :style="activityStyle(item.type)"><component :is="activityIcon(item.type)" :size="15" /></span>
                    </template>
                    <template #title>{{ item.client_name || '—' }} <span class="act-type">· {{ activityLabel(item.type) }}</span></template>
                    <template #description>{{ item.text || item.ref }}<span class="act-time"> · {{ dateTime(item.at) }}</span></template>
                  </a-list-item-meta>
                </a-list-item>
              </template>
            </a-list>
          </a-card>
        </a-col>
      </a-row>
    </a-spin>
  </div>
</template>

<script setup>
/**
 * GET client-portal/dashboard → { stats, work, recent_accounts, activity, portal }.
 * Every tile deep-links to the list that explains it.
 */
import { ref, computed, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { message } from 'ant-design-vue';
import { ExternalLink, UserCog, Quote, CalendarCheck, MessagesSquare, UserX, Settings2, Inbox } from 'lucide-vue-next';
import PageHeader from '../../components/PageHeader.vue';
import PortalStateTag from './PortalStateTag.vue';
import { useFormat } from '../../composables/useFormat';
import { useAuthStore } from '../../stores/auth';
import http from '../../lib/http';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();
const tr = (key, fallback) => { const out = t(key); return out === key ? fallback : out; };
const router = useRouter();
const auth = useAuthStore();
const { dateTime } = useFormat();

const loading = ref(false);
const loaded = ref(false);
const data = ref({
  stats: {}, work: {}, recent_accounts: [], activity: [],
  portal: { enabled: true, modules_on: 0, modules_total: 0 },
  plan_modules: {},
});

const statCards = computed(() => {
  const s = data.value.stats;
  return [
    { key: 'total', title: t('Portal_Total_Accounts'), value: s.total || 0, color: 'var(--app-primary, #6d28d9)', to: '/client-portal/accounts' },
    { key: 'active', title: t('Active'), value: s.active || 0, color: '#10b981', to: '/client-portal/accounts?status=active' },
    {
      key: 'invited', title: t('Portal_Pending_Invites'), value: s.invited || 0, color: '#3b82f6', to: '/client-portal/accounts?status=invited',
      sub: s.expired_invites ? t('Portal_N_Expired', { n: s.expired_invites }) : '',
    },
    { key: 'disabled', title: t('Disabled'), value: s.disabled || 0, color: '#94a3b8', to: '/client-portal/accounts?status=disabled' },
    { key: 'without', title: t('Portal_Customers_Without_Access'), value: s.customers_without_access || 0, color: '#f59e0b', to: '/client-portal/accounts?grant=1' },
    { key: 'new', title: t('Portal_New_This_Month'), value: s.new_this_month || 0, color: '#0d9488', to: '/client-portal/accounts' },
  ];
});

// SaaS: a module the tenant's plan lacks has no work to show.
const inPlan = key => (data.value.plan_modules || {})[key] !== false;

const workItems = computed(() => {
  const w = data.value.work;
  const items = [];
  if (inPlan('requests') && (auth.can('protocol_view') || auth.can('protocol_manage'))) {
    items.push({ key: 'protocol', label: tr('Portal_Customer_Requests', 'Customer requests'), count: w.protocol_requests || 0, icon: Inbox, color: '#0d9488', bg: 'rgba(13,148,136,.12)', to: '/protocol/requests?channel=portal' });
  }
  if (inPlan('quotations') && auth.can('Quotations_view')) {
    items.push({ key: 'quotes', label: t('Portal_Quote_Requests'), count: w.quote_requests || 0, icon: Quote, color: '#f97316', bg: 'rgba(249,115,22,.12)', to: '/quotations?source=portal&statut=pending' });
  }
  if (inPlan('appointments') && auth.can('service_jobs')) {
    items.push({ key: 'appts', label: t('Portal_Appointments'), count: w.appointments || 0, icon: CalendarCheck, color: '#3b82f6', bg: 'rgba(59,130,246,.12)', to: '/service/jobs?source=portal&status=pending' });
  }
  if (inPlan('deals') && auth.can('crm_messages')) {
    items.push({ key: 'msgs', label: t('Portal_Unread_Messages'), count: w.unread_messages || 0, icon: MessagesSquare, color: '#8b5cf6', bg: 'rgba(139,92,246,.12)', to: '/crm/inbox?channel=portal' });
  }
  items.push({ key: 'del', label: t('Portal_Deletions_30d'), count: data.value.stats.deletions_30d || 0, icon: UserX, color: '#ef4444', bg: 'rgba(239,68,68,.12)', to: '/client-portal/deletions' });
  return items;
});

const ACTIVITY = {
  protocol: { icon: Inbox, color: '#0d9488', bg: 'rgba(13,148,136,.12)', label: 'Portal_Activity_Protocol', fallback: 'Customer request' },
  quote: { icon: Quote, color: '#f97316', bg: 'rgba(249,115,22,.12)', label: 'Portal_Activity_Quote' },
  appointment: { icon: CalendarCheck, color: '#3b82f6', bg: 'rgba(59,130,246,.12)', label: 'Portal_Activity_Appointment' },
  message: { icon: MessagesSquare, color: '#8b5cf6', bg: 'rgba(139,92,246,.12)', label: 'Portal_Activity_Message' },
};
const activityIcon = type => (ACTIVITY[type] || ACTIVITY.message).icon;
const activityStyle = type => ({ color: (ACTIVITY[type] || ACTIVITY.message).color, background: (ACTIVITY[type] || ACTIVITY.message).bg });
const activityLabel = (type) => {
  const a = ACTIVITY[type] || ACTIVITY.message;
  return a.fallback ? tr(a.label, a.fallback) : t(a.label);
};

function openActivity(item) {
  if (item.type === 'protocol') router.push(`/protocol/requests/${item.id}`);
  else if (item.type === 'quote') router.push(`/quotations/${item.id}`);
  else if (item.type === 'appointment') router.push(`/service/jobs/details/${item.id}`);
  else if (item.deal_id) router.push(`/crm/deals/${item.deal_id}`);
  else router.push('/crm/inbox?channel=portal');
}

async function load() {
  loading.value = true;
  try {
    data.value = await http.get('client-portal/dashboard');
    loaded.value = true;
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    loading.value = false;
  }
}

onMounted(load);
</script>

<style scoped>
.btn-ic { margin-right: 6px; vertical-align: -2px; }
.stat { cursor: pointer; height: 100%; }
.stat-sub { font-size: 12px; color: #f97316; margin-top: 2px; }
.full { height: 100%; }
.work-list { display: flex; flex-direction: column; gap: 4px; }
.work { display: flex; align-items: center; gap: 10px; padding: 8px; border-radius: 8px; color: inherit; cursor: pointer; }
.work:hover { background: rgba(var(--app-primary-rgb, 109, 40, 217), .06); }
.work.zero .work-label { opacity: .6; }
.work-label { flex: 1; font-weight: 500; }
.work-ic { display: inline-flex; align-items: center; justify-content: center; width: 30px; height: 30px; border-radius: 8px; flex-shrink: 0; }
.modules-note { margin-top: 12px; padding-top: 10px; border-top: 1px solid rgba(128, 128, 128, .15); font-size: 12px; opacity: .75; display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
.act { cursor: pointer; }
.act:hover { background: rgba(var(--app-primary-rgb, 109, 40, 217), .04); }
.act-type, .act-time { font-weight: 400; opacity: .6; font-size: 12px; }
</style>
