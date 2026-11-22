<template>
  <div class="page">
    <PageHeader :title="$t('Crm_Dashboard')" :subtitle="$t('Crm_Dashboard_Subtitle')" :breadcrumb="[$t('Crm'), $t('Dashboard')]">
      <template #actions>
        <a-button v-if="d.can_reports" @click="$router.push('/crm/reports')"><template #icon><BarChartOutlined /></template>{{ $t('Crm_Reports') }}</a-button>
        <a-button v-if="canView" @click="$router.push('/crm/leads')"><template #icon><UnorderedListOutlined /></template>{{ $t('Crm_Leads') }}</a-button>
        <a-button v-if="canAdd" type="primary" @click="$router.push('/crm/leads/create')"><template #icon><UserAddOutlined /></template>{{ $t('Crm_New_Lead') }}</a-button>
      </template>
    </PageHeader>

    <a-spin :spinning="loading">
      <!-- KPIs -->
      <div class="kpis">
        <button v-for="k in kpiTiles" :key="k.key" type="button" class="kpi" :disabled="!k.to || !canView" @click="k.to && $router.push(k.to)">
          <span class="kpi-ic" :style="{ color: k.color, background: tint(k.color, 0.13) }"><component :is="k.icon" :size="19" /></span>
          <span class="kpi-text">
            <span class="kpi-value">{{ k.value }}</span>
            <span class="kpi-label">{{ k.label }}</span>
            <span v-if="k.note" class="kpi-note" :class="k.noteTone">{{ k.note }}</span>
          </span>
        </button>
      </div>

      <!-- Pipeline money -->
      <div v-if="d.deals" class="pstrip">
        <button type="button" class="ps" @click="$router.push('/crm/pipeline')">
          <span class="ps-label">{{ $t('Crm_Pipeline_Value') }}</span>
          <span class="ps-value">{{ money(d.deals.open_value) }}</span>
          <span class="ps-note">{{ d.deals.open }} {{ $t('Crm_Open_Deals_Lower') }}</span>
        </button>
        <div class="ps">
          <span class="ps-label">{{ $t('Crm_Weighted_Forecast') }}</span>
          <span class="ps-value">{{ money(d.deals.weighted) }}</span>
        </div>
        <button type="button" class="ps ok" @click="$router.push('/crm/deals?status=won')">
          <span class="ps-label">{{ $t('Crm_Won_This_Month') }}</span>
          <span class="ps-value">{{ money(d.deals.won_month_value) }}</span>
          <span class="ps-note">{{ d.deals.won_month }} {{ $t('Crm_Deals_Lower') }}<template v-if="d.deals.win_rate_month !== null"> · {{ d.deals.win_rate_month }}% {{ $t('Crm_Win_Rate') }}</template></span>
        </button>
        <button type="button" class="ps" :class="{ bad: d.deals.overdue }" @click="$router.push('/crm/deals?quick=overdue')">
          <span class="ps-label">{{ $t('Crm_Past_Close_Date') }}</span>
          <span class="ps-value">{{ d.deals.overdue }}</span>
        </button>
        <button type="button" class="ps" @click="$router.push('/crm/deals?quick=closing_month')">
          <span class="ps-label">{{ $t('Crm_Closing_This_Month') }}</span>
          <span class="ps-value">{{ d.deals.closing_month }}</span>
        </button>
      </div>

      <a-row v-if="(d.targets || []).length || (d.forecast || []).length" :gutter="16">
        <a-col v-if="(d.targets || []).length" :xs="24" :xl="(d.forecast || []).length ? 14 : 24">
          <a-card :bordered="false" class="card">
            <div class="card-head">
              <span class="card-title">{{ $t('Crm_Targets_This_Month') }}</span>
              <span class="pace-note">{{ tp('Crm_Period_Elapsed', '{n}% of the period elapsed', { n: d.targets[0].expected_percent }) }}</span>
            </div>
            <div class="targets">
              <div v-for="tg in d.targets" :key="tg.id" class="tg">
                <div class="tg-head">
                  <span class="tg-name">{{ metricLabel(tg.metric) }} <a-tag v-if="tg.scope === 'team'" class="mini">{{ $t('Crm_Team') }}</a-tag></span>
                  <span class="tg-num"><strong>{{ tg.money ? money(tg.actual) : tg.actual }}</strong> / {{ tg.money ? money(tg.target) : tg.target }}</span>
                </div>
                <div class="tg-track">
                  <div class="tg-fill" :style="{ width: `${Math.min(100, tg.percent || 0)}%`, background: paceColor(tg) }"></div>
                  <div class="tg-pace" :style="{ left: `${tg.expected_percent}%` }"></div>
                </div>
                <div class="tg-pct" :style="{ color: paceColor(tg) }">{{ tg.percent ?? 0 }}%</div>
              </div>
            </div>
          </a-card>
        </a-col>
        <a-col v-if="(d.forecast || []).length" :xs="24" :xl="(d.targets || []).length ? 10 : 24">
          <a-card :bordered="false" class="card">
            <div class="card-head">
              <span class="card-title">{{ $t('Crm_Forecast') }}</span>
              <a-button v-if="d.can_reports" type="link" size="small" @click="$router.push('/crm/reports')">{{ $t('Crm_Reports') }} →</a-button>
            </div>
            <div class="fcast">
              <div v-for="m in d.forecast" :key="m.key" class="fc" :class="{ late: m.key === 'overdue' }">
                <div class="fc-bars">
                  <div class="fc-bar total" :style="{ height: fh(m.value) }"></div>
                  <div class="fc-bar weighted" :style="{ height: fh(m.weighted) }"></div>
                </div>
                <div class="fc-label">{{ m.key === 'overdue' ? $t('Crm_Overdue') : monthShort(m.key) }}</div>
                <div class="fc-value">{{ money(m.value) }}</div>
              </div>
            </div>
          </a-card>
        </a-col>
      </a-row>

      <a-row :gutter="16">
        <a-col :xs="24" :xl="16">
          <a-card :bordered="false" class="card">
            <div class="card-title">{{ $t('Crm_Leads_Last_30') }}</div>
            <ReportChart
              v-if="(d.trend || []).length"
              :data="d.trend" type="area" :height="260"
              :fields="[{ key: 'created', label: $t('Crm_New_Leads') }, { key: 'converted', label: $t('Crm_Converted') }]"
            />
          </a-card>
        </a-col>
        <a-col :xs="24" :xl="8">
          <a-card :bordered="false" class="card">
            <div class="card-title">{{ $t('Crm_Leads_By_Status') }}</div>
            <div class="bars">
              <button v-for="s in d.by_status || []" :key="s.id" type="button" class="bar-row" :disabled="!canView" @click="$router.push(`/crm/leads?status=${s.id}`)">
                <span class="bar-label"><span class="dot" :style="{ background: s.color }"></span>{{ s.name }}</span>
                <span class="bar-track"><span class="bar-fill" :style="{ width: pct(s.count, maxStatus), background: s.color }"></span></span>
                <span class="bar-value">{{ s.count }}</span>
              </button>
            </div>
          </a-card>
        </a-col>
      </a-row>

      <a-row :gutter="16">
        <a-col :xs="24" :lg="12">
          <a-card :bordered="false" class="card">
            <div class="card-title">{{ $t('Crm_Leads_By_Source') }}</div>
            <div v-if="(d.by_source || []).length" class="bars">
              <div v-for="s in d.by_source" :key="s.id || 'none'" class="bar-row static">
                <span class="bar-label"><span class="dot" :style="{ background: s.color }"></span>{{ s.name || $t('Crm_No_Source') }}</span>
                <span class="bar-track">
                  <span class="bar-fill" :style="{ width: pct(s.count, maxSource), background: tint(s.color, 0.45) }"></span>
                  <span class="bar-fill over" :style="{ width: pct(s.converted, maxSource), background: s.color }"></span>
                </span>
                <span class="bar-value">{{ s.count }}<small v-if="s.converted"> · {{ s.converted }} ✓</small></span>
              </div>
            </div>
            <a-empty v-else :image="simpleImage" />
          </a-card>
        </a-col>
        <a-col :xs="24" :lg="12">
          <a-card :bordered="false" class="card">
            <div class="card-title">{{ $t('Crm_Open_Leads_By_Owner') }}</div>
            <div v-if="(d.by_owner || []).length" class="owners">
              <div v-for="o in d.by_owner" :key="o.id || 'pool'" class="owner-row">
                <a-avatar :size="30" :style="{ background: o.id ? avatarColor(o.name) : '#94a3b8' }">{{ o.id ? initials(o.name) : '?' }}</a-avatar>
                <span class="owner-name">{{ o.name || $t('Crm_Unassigned') }}</span>
                <span class="owner-count">{{ o.count }}</span>
                <span class="owner-value">{{ money(o.value) }}</span>
              </div>
            </div>
            <a-empty v-else :image="simpleImage" />
          </a-card>
        </a-col>
      </a-row>

      <a-card :bordered="false" class="card">
        <div class="card-head">
          <span class="card-title">{{ $t('Crm_Recent_Leads') }}</span>
          <a-button v-if="canView" type="link" @click="$router.push('/crm/leads')">{{ $t('Crm_View_All') }}</a-button>
        </div>
        <div v-if="(d.recent || []).length" class="recent">
          <button v-for="l in d.recent" :key="l.id" type="button" class="recent-row" :disabled="!canView" @click="$router.push(`/crm/leads/${l.id}`)">
            <a-avatar :size="34" :style="{ background: avatarColor(l.name) }">{{ initials(l.name) }}</a-avatar>
            <span class="recent-text">
              <span class="recent-name">{{ l.name }} <component :is="ratingIcon(l.rating)" v-if="l.rating === 'hot'" :size="13" class="hot" /></span>
              <span class="recent-sub">{{ l.lead_number }}<template v-if="l.source"> · {{ l.source }}</template><template v-if="l.owner"> · {{ l.owner }}</template></span>
            </span>
            <span v-if="l.status" class="status" :style="{ color: l.status.color, background: tint(l.status.color) }">{{ l.status.name }}</span>
            <span class="recent-value">{{ l.estimated_value ? money(l.estimated_value) : '' }}</span>
            <span class="recent-time">{{ ago(l.created_at) }}</span>
          </button>
        </div>
        <a-empty v-else :description="$t('Crm_No_Leads_Yet')" :image="simpleImage">
          <a-button v-if="canAdd" type="primary" @click="$router.push('/crm/leads/create')">{{ $t('Crm_New_Lead') }}</a-button>
        </a-empty>
      </a-card>
    </a-spin>
  </div>
</template>

<script setup>
/** CRM dashboard — GET crm/dashboard (scoped to the leads the user may see). */
import { ref, computed, onMounted } from 'vue';
import { message, Empty } from 'ant-design-vue';
import { UnorderedListOutlined, UserAddOutlined, BarChartOutlined } from '@ant-design/icons-vue';
import { Users, UserPlus, BadgeCheck, Percent, UserX, Flame, Hourglass, CircleUserRound } from 'lucide-vue-next';
import PageHeader from '../../components/PageHeader.vue';
import ReportChart from '../../components/ReportChart.vue';
import { useFormat } from '../../composables/useFormat';
import { useAuthStore } from '../../stores/auth';
import http from '../../lib/http';
import { t, i18n } from '../../i18n';
import dayjs from 'dayjs';
import { RATINGS, optionOf, initials, avatarColor, tint, ago } from './crmOptions';

const auth = useAuthStore();
const { money } = useFormat();
const simpleImage = Empty.PRESENTED_IMAGE_SIMPLE;
const canView = computed(() => auth.can('crm_leads_view'));
const canAdd = computed(() => auth.can('crm_leads_add'));

const loading = ref(false);
const d = ref({});
const k = computed(() => d.value.kpis || {});

const kpiTiles = computed(() => {
  const growth = k.value.new_last_month ? Math.round(((k.value.new_month - k.value.new_last_month) / k.value.new_last_month) * 100) : null;
  return [
    { key: 'open', icon: Users, color: '#6366f1', value: k.value.open ?? 0, label: t('Crm_Open_Leads', 'Open leads'), note: money(k.value.open_value || 0), to: '/crm/leads?status=open' },
    { key: 'new', icon: UserPlus, color: '#0ea5e9', value: k.value.new_month ?? 0, label: t('Crm_New_This_Month', 'New this month'),
      note: growth === null ? '' : `${growth >= 0 ? '+' : ''}${growth}% ${t('Crm_Vs_Last_Month', 'vs last month')}`, noteTone: growth >= 0 ? 'up' : 'down', to: '/crm/leads?status=' },
    { key: 'converted', icon: BadgeCheck, color: '#16a34a', value: k.value.converted_month ?? 0, label: t('Crm_Converted_This_Month', 'Converted this month'), to: '/crm/leads?status=converted' },
    { key: 'rate', icon: Percent, color: '#8b5cf6', value: k.value.conversion_rate === null || k.value.conversion_rate === undefined ? '—' : `${k.value.conversion_rate}%`, label: t('Crm_Conversion_Rate', 'Conversion rate (90 days)') },
    { key: 'mine', icon: CircleUserRound, color: '#0d9488', value: k.value.mine ?? 0, label: t('Crm_My_Open_Leads', 'My open leads'), to: '/crm/leads?owner=me&status=open' },
    { key: 'unassigned', icon: UserX, color: '#d97706', value: k.value.unassigned ?? 0, label: t('Crm_Unassigned', 'Unassigned'), to: '/crm/leads?owner=none&status=open' },
    { key: 'hot', icon: Flame, color: '#dc2626', value: k.value.hot ?? 0, label: t('Crm_Hot_Leads', 'Hot leads'), to: '/crm/leads?quick=hot' },
    { key: 'stale', icon: Hourglass, color: '#64748b', value: k.value.stale ?? 0, label: t('Crm_Stale_Leads', 'Stale leads'), to: '/crm/leads?quick=stale' },
  ];
});
const maxStatus = computed(() => Math.max(1, ...(d.value.by_status || []).map(s => s.count)));
const maxSource = computed(() => Math.max(1, ...(d.value.by_source || []).map(s => s.count)));

function pct(v, max) { return `${Math.max(v ? 3 : 0, Math.round((v / max) * 100))}%`; }

const METRIC_LABELS = {
  won_value: ['Crm_Metric_Won_Value', 'Won value'],
  won_deals: ['Crm_Metric_Won_Deals', 'Deals won'],
  new_leads: ['Crm_Metric_New_Leads', 'New leads'],
  converted_leads: ['Crm_Metric_Converted_Leads', 'Leads converted'],
  activities_done: ['Crm_Metric_Activities', 'Activities done'],
};
function metricLabel(key) { const l = METRIC_LABELS[key]; return l ? t(l[0], l[1]) : key; }
function tp(key, fallback, params) {
  const out = i18n.global.t(key, params);
  return out !== key ? out : Object.entries(params).reduce((s, [k, v]) => s.replace(`{${k}}`, v ?? ''), fallback);
}
/** Green when at or ahead of the even pace through the month, amber when a bit behind, red when far behind. */
function paceColor(tg) {
  const p = tg.percent || 0;
  if (p >= 100 || p >= tg.expected_percent) return '#16a34a';
  return p >= tg.expected_percent * 0.7 ? '#f59e0b' : '#dc2626';
}
const maxForecast = computed(() => Math.max(1, ...(d.value.forecast || []).map(m => m.value)));
function fh(v) { return `${Math.max(v ? 4 : 0, Math.round((v / maxForecast.value) * 100))}%`; }
function monthShort(key) { return dayjs(`${key}-01`).format('MMM'); }
function ratingIcon(r) { return optionOf(RATINGS, r).icon; }

onMounted(async () => {
  loading.value = true;
  try {
    d.value = await http.get('crm/dashboard');
  } catch (e) {
    message.error(t('Crm_Err_Could_Not_Load_The_CRM_Dashboard', 'Could not load the CRM dashboard'));
  } finally {
    loading.value = false;
  }
});
</script>

<style scoped>
.card { margin-bottom: 16px; border-radius: 14px; }
.card-title { font-weight: 600; font-size: 15px; margin-bottom: 12px; }
.card-head { display: flex; align-items: center; justify-content: space-between; }
.card-head .card-title { margin-bottom: 0; }
.dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; flex: none; }
.hot { color: #dc2626; }

.kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 12px; margin-bottom: 16px; }
.kpi {
  display: flex; align-items: center; gap: 12px; padding: 14px 16px; border: 1px solid rgba(128, 128, 128, 0.18);
  border-radius: 14px; background: transparent; color: inherit; font: inherit; text-align: start; cursor: pointer;
  transition: border-color 0.15s, transform 0.15s;
}
.kpi:hover:not(:disabled) { border-color: rgba(var(--app-primary-rgb, 109, 40, 217), 0.5); transform: translateY(-1px); }
.kpi:disabled { cursor: default; }
.kpi-ic { width: 42px; height: 42px; flex: none; display: inline-flex; align-items: center; justify-content: center; border-radius: 12px; }
.kpi-text { display: flex; flex-direction: column; min-width: 0; }
.kpi-value { font-size: 21px; font-weight: 700; line-height: 1.2; }
.kpi-label { font-size: 12px; opacity: 0.6; }
.kpi-note { font-size: 11.5px; font-weight: 600; opacity: 0.75; }
.kpi-note.up { color: #16a34a; opacity: 1; }
.kpi-note.down { color: #dc2626; opacity: 1; }

.pstrip { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 12px; margin-bottom: 16px; }
.ps {
  display: flex; flex-direction: column; gap: 2px; padding: 12px 16px; border-radius: 14px; border: 0; text-align: start;
  background: rgba(var(--app-primary-rgb, 109, 40, 217), 0.06); color: inherit; font: inherit;
}
button.ps { cursor: pointer; }
button.ps:hover { background: rgba(var(--app-primary-rgb, 109, 40, 217), 0.11); }
.ps.ok { background: rgba(22, 163, 74, 0.08); }
.ps.bad { background: rgba(220, 38, 38, 0.08); }
.ps.bad .ps-value { color: #dc2626; }
.ps-label { font-size: 12px; opacity: 0.65; }
.ps-value { font-size: 19px; font-weight: 700; }
.ps-note { font-size: 11.5px; opacity: 0.65; }
.bars { display: flex; flex-direction: column; gap: 10px; }
.bar-row {
  display: grid; grid-template-columns: minmax(90px, 140px) 1fr auto; align-items: center; gap: 10px;
  padding: 0; border: 0; background: none; color: inherit; font: inherit; text-align: start; cursor: pointer;
}
.bar-row.static, .bar-row:disabled { cursor: default; }
.bar-label { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.bar-track { position: relative; height: 10px; border-radius: 999px; background: rgba(128, 128, 128, 0.12); overflow: hidden; }
.bar-fill { position: absolute; inset: 0 auto 0 0; border-radius: 999px; transition: width 0.4s ease; }
.bar-value { font-weight: 600; font-size: 13px; min-width: 32px; text-align: end; }
.bar-value small { font-weight: 500; opacity: 0.6; }

.owners { display: flex; flex-direction: column; gap: 10px; }
.owner-row { display: grid; grid-template-columns: 30px 1fr auto auto; align-items: center; gap: 10px; }
.owner-name { font-weight: 500; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.owner-count { font-weight: 700; }
.owner-value { font-size: 12.5px; opacity: 0.65; min-width: 90px; text-align: end; }

.pace-note { font-size: 12px; opacity: 0.6; }
.mini { font-size: 11px; line-height: 18px; margin: 0 0 0 4px; }
.targets { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 14px 22px; margin-top: 12px; }
.tg { display: grid; grid-template-columns: 1fr auto; gap: 4px 10px; align-items: center; }
.tg-head { grid-column: span 2; display: flex; justify-content: space-between; gap: 8px; font-size: 13px; }
.tg-name { font-weight: 500; }
.tg-num { opacity: 0.75; white-space: nowrap; }
.tg-track { position: relative; height: 8px; border-radius: 999px; background: rgba(128, 128, 128, 0.14); }
.tg-fill { position: absolute; inset: 0 auto 0 0; border-radius: 999px; transition: width 0.4s ease; }
.tg-pace { position: absolute; top: -3px; width: 2px; height: 14px; border-radius: 1px; background: rgba(15, 23, 42, 0.45); }
.tg-pct { font-size: 12.5px; font-weight: 700; min-width: 38px; text-align: end; }
.fcast { display: flex; gap: 10px; align-items: flex-end; margin-top: 12px; }
.fc { flex: 1; display: flex; flex-direction: column; align-items: center; text-align: center; min-width: 0; }
.fc-bars { height: 110px; width: 100%; display: flex; align-items: flex-end; justify-content: center; gap: 3px; }
.fc-bar { width: 16px; border-radius: 5px 5px 2px 2px; transition: height 0.4s ease; }
.fc-bar.total { background: rgba(var(--app-primary-rgb, 109, 40, 217), 0.28); }
.fc-bar.weighted { background: var(--app-primary, #6d28d9); }
.fc.late .fc-bar.total { background: rgba(220, 38, 38, 0.3); }
.fc.late .fc-bar.weighted { background: #dc2626; }
.fc-label { font-size: 12px; font-weight: 600; margin-top: 6px; }
.fc-value { font-size: 11.5px; opacity: 0.65; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 100%; }
.recent { display: flex; flex-direction: column; }
.recent-row {
  display: grid; grid-template-columns: 34px 1fr auto auto 56px; align-items: center; gap: 12px; padding: 10px 4px;
  border: 0; border-bottom: 1px solid rgba(128, 128, 128, 0.12); background: none; color: inherit; font: inherit; text-align: start; cursor: pointer;
}
.recent-row:last-child { border-bottom: 0; }
.recent-row:hover:not(:disabled) .recent-name { color: var(--app-primary, #6d28d9); }
.recent-text { display: flex; flex-direction: column; min-width: 0; }
.recent-name { font-weight: 500; display: flex; align-items: center; gap: 6px; }
.recent-sub { font-size: 12px; opacity: 0.6; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.status { display: inline-block; padding: 2px 10px; border-radius: 999px; font-size: 12px; font-weight: 600; white-space: nowrap; }
.recent-value { font-weight: 600; font-size: 13px; }
.recent-time { font-size: 12px; opacity: 0.55; text-align: end; }
@media (max-width: 640px) {
  .recent-row { grid-template-columns: 34px 1fr auto; }
  .recent-value, .recent-time { display: none; }
}
</style>
