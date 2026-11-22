<template>
  <div class="page">
    <PageHeader :title="$t('Crm_Reports')" :subtitle="rangeLabel" :breadcrumb="[$t('Crm'), $t('Crm_Reports')]">
      <template #actions>
        <a-button v-if="meta.can_targets" @click="$router.push('/crm/targets')"><template #icon><AimOutlined /></template>{{ $t('Crm_Targets') }}</a-button>
      </template>
    </PageHeader>

    <!-- Filters -->
    <div class="filters">
      <div class="presets">
        <button v-for="p in presets" :key="p.key" type="button" class="preset" :class="{ active: preset === p.key }" @click="setPreset(p.key)">{{ p.label }}</button>
      </div>
      <a-range-picker v-model:value="range" value-format="YYYY-MM-DD" :allow-clear="false" @change="onRange" />
      <a-select v-if="meta.view_all" v-model:value="owner" :options="ownerOptions" style="width: 200px" show-search option-filter-prop="label" @change="reloadActive" />
      <a-select v-if="tab === 'overview' && meta.pipelines?.length > 1" v-model:value="pipeline" :options="pipelineOptions" style="width: 170px" @change="reloadActive" />
    </div>

    <a-card :bordered="false" class="card shell">
      <a-tabs v-model:activeKey="tab" @change="loadTab">
        <a-tab-pane key="overview" :tab="$t('Crm_Overview')" />
        <a-tab-pane key="winloss" :tab="$t('Crm_Win_Loss')" />
        <a-tab-pane key="forecast" :tab="$t('Crm_Forecast')" />
        <a-tab-pane key="reps" :tab="$t('Crm_Leaderboard')" />
        <a-tab-pane key="activities" :tab="$t('Crm_Activities')" />
        <a-tab-pane key="sources" :tab="$t('Crm_Sources_Channels')" />
      </a-tabs>

      <a-spin :spinning="loading">
        <!-- ============ Overview ============ -->
        <template v-if="tab === 'overview' && data.overview">
          <div class="kpis">
            <div v-for="k in overviewKpis" :key="k.key" class="kpi">
              <span class="kpi-ic" :style="{ color: k.color, background: tint(k.color, 0.13) }"><component :is="k.icon" :size="18" /></span>
              <span class="kpi-text"><span class="kpi-value">{{ k.value }}</span><span class="kpi-label">{{ k.label }}</span><span v-if="k.note" class="kpi-note">{{ k.note }}</span></span>
            </div>
          </div>
          <a-row :gutter="16">
            <a-col :xs="24" :xl="14">
              <ReportChart :title="$t('Crm_Won_Vs_Lost_Value')" :data="data.overview.trend" type="bar" :height="280" :format="money"
                :fields="[{ key: 'won_value', label: $t('Crm_Won') }, { key: 'lost_value', label: $t('Crm_Lost') }]" />
              <ReportChart :title="$t('Crm_New_Leads')" :data="data.overview.trend" type="area" :height="220" :fields="[{ key: 'new_leads', label: $t('Crm_New_Leads') }]" />
            </a-col>
            <a-col :xs="24" :xl="10">
              <div class="panel">
                <div class="panel-title">
                  {{ $t('Crm_Deal_Funnel') }}<small v-if="data.overview.funnel.pipeline"> · {{ data.overview.funnel.pipeline.name }}</small>
                </div>
                <div class="hint">{{ $t('Crm_Funnel_Hint') }}</div>
                <div v-if="data.overview.funnel.total" class="funnel">
                  <div v-for="(s, i) in data.overview.funnel.stages" :key="s.id" class="fn-row">
                    <div class="fn-bar" :style="{ width: `${Math.max(14, s.from_start || 0)}%`, background: s.color }">
                      <span>{{ s.count }}</span>
                    </div>
                    <div class="fn-text">
                      <span class="fn-name">{{ s.id === 'won' ? $t('Crm_Won') : s.name }}</span>
                      <span class="fn-sub">{{ money(s.value) }}<template v-if="i > 0 && s.from_previous !== null"> · {{ s.from_previous }}% {{ $t('Crm_From_Previous') }}</template></span>
                    </div>
                  </div>
                </div>
                <a-empty v-else :image="simpleImage" :description="$t('Crm_No_Deals_In_Range')" />
              </div>
            </a-col>
          </a-row>
        </template>

        <!-- ============ Win & loss ============ -->
        <template v-else-if="tab === 'winloss' && data.winloss">
          <ReportChart :title="$t('Crm_Deals_Won_Lost')" :data="data.winloss.trend" type="bar" :height="260"
            :fields="[{ key: 'won_deals', label: $t('Crm_Won') }, { key: 'lost_deals', label: $t('Crm_Lost') }]" />
          <a-row :gutter="16">
            <a-col :xs="24" :lg="12">
              <div class="panel">
                <div class="panel-title">{{ $t('Crm_Why_Deals_Lost') }}</div>
                <div v-if="data.winloss.deal_reasons.length" class="bars">
                  <div v-for="r in data.winloss.deal_reasons" :key="r.id || 'none'" class="bar-row">
                    <span class="bar-label">{{ r.name || $t('Crm_No_Reason') }}</span>
                    <span class="bar-track"><span class="bar-fill" :style="{ width: pct(r.count, maxOf(data.winloss.deal_reasons)), background: '#dc2626' }"></span></span>
                    <span class="bar-value">{{ r.count }}<small> · {{ money(r.value) }}</small></span>
                  </div>
                </div>
                <a-empty v-else :image="simpleImage" />
              </div>
            </a-col>
            <a-col :xs="24" :lg="12">
              <div class="panel">
                <div class="panel-title">{{ $t('Crm_Why_Leads_Lost') }}</div>
                <div v-if="data.winloss.lead_reasons.length" class="bars">
                  <div v-for="r in data.winloss.lead_reasons" :key="r.id || 'none'" class="bar-row">
                    <span class="bar-label">{{ r.name || $t('Crm_No_Reason') }}</span>
                    <span class="bar-track"><span class="bar-fill" :style="{ width: pct(r.count, maxOf(data.winloss.lead_reasons)), background: '#f97316' }"></span></span>
                    <span class="bar-value">{{ r.count }}</span>
                  </div>
                </div>
                <a-empty v-else :image="simpleImage" />
              </div>
            </a-col>
            <a-col :xs="24" :lg="8">
              <div class="panel">
                <div class="panel-title">{{ $t('Crm_Lost_At_Stage') }}</div>
                <div v-if="data.winloss.lost_by_stage.length" class="bars">
                  <div v-for="(r, i) in data.winloss.lost_by_stage" :key="i" class="bar-row">
                    <span class="bar-label"><span class="dot" :style="{ background: r.color }"></span>{{ r.name || '—' }}</span>
                    <span class="bar-track"><span class="bar-fill" :style="{ width: pct(r.count, maxOf(data.winloss.lost_by_stage)), background: r.color || '#94a3b8' }"></span></span>
                    <span class="bar-value">{{ r.count }}</span>
                  </div>
                </div>
                <a-empty v-else :image="simpleImage" />
              </div>
            </a-col>
            <a-col :xs="24" :lg="8">
              <div class="panel">
                <div class="panel-title ok">{{ $t('Crm_Biggest_Wins') }}</div>
                <router-link v-for="d in data.winloss.top_won" :key="d.id" :to="`/crm/deals/${d.id}`" class="deal-row">
                  <span class="dr-main"><strong>{{ d.title }}</strong><small>{{ d.who || '' }}<template v-if="d.owner"> · {{ d.owner }}</template></small></span>
                  <span class="dr-value ok">{{ money(d.value) }}</span>
                </router-link>
                <a-empty v-if="!data.winloss.top_won.length" :image="simpleImage" />
              </div>
            </a-col>
            <a-col :xs="24" :lg="8">
              <div class="panel">
                <div class="panel-title bad">{{ $t('Crm_Biggest_Losses') }}</div>
                <router-link v-for="d in data.winloss.top_lost" :key="d.id" :to="`/crm/deals/${d.id}`" class="deal-row">
                  <span class="dr-main"><strong>{{ d.title }}</strong><small>{{ d.reason || $t('Crm_No_Reason') }}<template v-if="d.owner"> · {{ d.owner }}</template></small></span>
                  <span class="dr-value bad">{{ money(d.value) }}</span>
                </router-link>
                <a-empty v-if="!data.winloss.top_lost.length" :image="simpleImage" />
              </div>
            </a-col>
          </a-row>
        </template>

        <!-- ============ Forecast ============ -->
        <template v-else-if="tab === 'forecast' && data.forecast">
          <div class="hint top">{{ $t('Crm_Forecast_Hint') }}</div>
          <div class="fc-head">
            <div class="fc-box">
              <span class="fc-l">{{ $t('Crm_Won_This_Month') }}</span>
              <span class="fc-v ok">{{ money(data.forecast.this_month.won) }}</span>
            </div>
            <div class="fc-box">
              <span class="fc-l">{{ $t('Crm_Projected_This_Month') }}</span>
              <span class="fc-v">{{ money(data.forecast.this_month.projected) }}</span>
              <span class="fc-n">{{ $t('Crm_Projected_Hint') }}</span>
            </div>
            <div v-if="data.forecast.this_month.target" class="fc-box">
              <span class="fc-l">{{ $t('Crm_Monthly_Target') }}</span>
              <span class="fc-v">{{ money(data.forecast.this_month.target) }}</span>
              <a-progress :percent="Math.min(100, Math.round(data.forecast.this_month.projected / data.forecast.this_month.target * 100))"
                :success="{ percent: Math.min(100, Math.round(data.forecast.this_month.won / data.forecast.this_month.target * 100)) }" size="small" />
            </div>
            <div class="fc-box">
              <span class="fc-l">{{ $t('Crm_Open_Pipeline') }}</span>
              <span class="fc-v">{{ money(data.forecast.totals.value) }}</span>
              <span class="fc-n">{{ $t('Crm_Deals_N', { n: data.forecast.totals.count }) }} · {{ $t('Crm_Weighted') }} {{ money(data.forecast.totals.weighted) }}</span>
            </div>
          </div>
          <div class="panel">
            <div class="panel-title">{{ $t('Crm_Expected_Close') }}</div>
            <div class="months">
              <div v-for="m in data.forecast.months" :key="m.key" class="mcol" :class="{ late: m.key === 'overdue' }">
                <div class="m-bars">
                  <div class="m-bar total" :style="{ height: hpct(m.value) }" :title="money(m.value)"></div>
                  <div class="m-bar weighted" :style="{ height: hpct(m.weighted) }" :title="money(m.weighted)"></div>
                </div>
                <div class="m-label">{{ monthLabel(m.key) }}</div>
                <div class="m-value">{{ money(m.value) }}</div>
                <div class="m-sub">{{ m.count }} · {{ money(m.weighted) }}</div>
              </div>
            </div>
            <div class="legend"><span><i class="lg total"></i>{{ $t('Crm_Total_Value') }}</span><span><i class="lg weighted"></i>{{ $t('Crm_Weighted_By_Stage') }}</span></div>
          </div>
          <a-row :gutter="16">
            <a-col :xs="24" :lg="14">
              <div class="panel">
                <div class="panel-title">{{ $t('Crm_Closing_This_Month') }}</div>
                <router-link v-for="d in data.forecast.closing" :key="d.id" :to="`/crm/deals/${d.id}`" class="deal-row">
                  <span class="dr-main">
                    <strong>{{ d.title }}</strong>
                    <small>{{ d.who || '' }}<template v-if="d.owner"> · {{ d.owner }}</template> · {{ date(d.expected_close_date) }}</small>
                  </span>
                  <span class="pill" :style="{ color: d.stage_color, background: tint(d.stage_color || '#94a3b8') }">{{ d.stage }} · {{ d.probability }}%</span>
                  <span class="dr-value" :class="{ bad: d.overdue }">{{ money(d.value) }}</span>
                </router-link>
                <a-empty v-if="!data.forecast.closing.length" :image="simpleImage" />
              </div>
            </a-col>
            <a-col :xs="24" :lg="10">
              <div class="panel">
                <div class="panel-head">
                  <span class="panel-title">{{ $t('Crm_Pipeline_By_Rep') }}</span>
                  <a-button size="small" type="text" @click="csv('forecast-reps', data.forecast.owners, forecastCols)"><template #icon><DownloadOutlined /></template></a-button>
                </div>
                <a-table :data-source="data.forecast.owners" :columns="forecastCols" size="small" :pagination="false" row-key="id">
                  <template #bodyCell="{ column, record }">
                    <template v-if="column.key === 'name'">{{ record.name || $t('Crm_Unassigned') }}</template>
                    <template v-else-if="column.money">{{ money(record[column.key]) }}</template>
                  </template>
                </a-table>
              </div>
            </a-col>
          </a-row>
        </template>

        <!-- ============ Leaderboard ============ -->
        <template v-else-if="tab === 'reps' && data.reps">
          <div v-if="podium.length" class="podium">
            <div v-for="(r, i) in podium" :key="r.id" class="pod" :class="`p${i + 1}`">
              <span class="medal">{{ i + 1 }}</span>
              <a-avatar :size="44" :style="{ background: avatarColor(r.name) }">{{ initials(r.name) }}</a-avatar>
              <div class="pod-name">{{ r.name }}</div>
              <div class="pod-value">{{ money(r.won_value) }}</div>
              <div class="pod-sub">{{ $t('Crm_N_Won', { n: r.won_deals }) }}<template v-if="r.target_percent !== null"> · {{ $t('Crm_Pct_Of_Target', { n: r.target_percent }) }}</template></div>
            </div>
          </div>
          <div class="panel">
            <div class="panel-head">
              <span class="panel-title">{{ $t('Crm_Leaderboard') }}</span>
              <a-button size="small" type="text" @click="csv('leaderboard', data.reps.rows, repCols)"><template #icon><DownloadOutlined /></template> CSV</a-button>
            </div>
            <a-table :data-source="data.reps.rows" :columns="repCols" size="small" :pagination="false" row-key="id" :scroll="{ x: 1100 }">
              <template #bodyCell="{ column, record, index }">
                <template v-if="column.key === 'name'">
                  <span class="rep"><span class="rank">{{ index + 1 }}</span><a-avatar :size="24" :style="{ background: avatarColor(record.name), fontSize: '10px' }">{{ initials(record.name) }}</a-avatar>{{ record.name }}</span>
                </template>
                <template v-else-if="column.key === 'win_rate'">{{ record.win_rate === null ? '—' : `${record.win_rate}%` }}</template>
                <template v-else-if="column.key === 'target_percent'">
                  <a-progress v-if="record.target_percent !== null" :percent="Math.min(100, record.target_percent)" :format="() => `${record.target_percent}%`" size="small" :stroke-color="record.target_percent >= 100 ? '#16a34a' : undefined" />
                  <span v-else class="muted">—</span>
                </template>
                <template v-else-if="column.money">{{ money(record[column.key]) }}</template>
              </template>
            </a-table>
            <div v-if="!data.reps.period" class="hint top">{{ $t('Crm_Target_Column_Hint') }}</div>
          </div>
        </template>

        <!-- ============ Activities ============ -->
        <template v-else-if="tab === 'activities' && data.activities">
          <div class="kpis">
            <div v-for="ty in actTypes" :key="ty.value" class="kpi">
              <span class="kpi-ic" :style="{ color: ty.color, background: tint(ty.color, 0.13) }"><component :is="ty.icon" :size="18" /></span>
              <span class="kpi-text"><span class="kpi-value">{{ data.activities.by_type[ty.value] || 0 }}</span><span class="kpi-label">{{ ty.label }}</span></span>
            </div>
            <div class="kpi">
              <span class="kpi-ic" style="color:#dc2626;background:rgba(220,38,38,.12)"><AlarmClock :size="18" /></span>
              <span class="kpi-text"><span class="kpi-value">{{ data.activities.overdue }}</span><span class="kpi-label">{{ $t('Crm_Overdue_Now') }}</span><span class="kpi-note">{{ data.activities.open }} {{ $t('Crm_Planned_Lower') }}</span></span>
            </div>
          </div>
          <ReportChart :title="$t('Crm_Activities_Done')" :data="data.activities.trend" type="bar" :height="260"
            :fields="actTypes.filter(x => x.value !== 'note').map(x => ({ key: x.value, label: x.label }))" />
          <a-row :gutter="16">
            <a-col :xs="24" :lg="16">
              <div class="panel">
                <div class="panel-head">
                  <span class="panel-title">{{ $t('Crm_By_Rep') }}</span>
                  <a-button size="small" type="text" @click="csv('activities', data.activities.by_owner, actCols)"><template #icon><DownloadOutlined /></template> CSV</a-button>
                </div>
                <a-table :data-source="data.activities.by_owner" :columns="actCols" size="small" :pagination="false" row-key="id" />
              </div>
            </a-col>
            <a-col :xs="24" :lg="8">
              <div class="panel">
                <div class="panel-title">{{ $t('Crm_Call_Outcomes') }}</div>
                <div v-if="Object.keys(data.activities.outcomes || {}).length" class="bars">
                  <div v-for="(n, key) in data.activities.outcomes" :key="key" class="bar-row">
                    <span class="bar-label"><span class="dot" :style="{ background: outcomeOf(key).color }"></span>{{ labelOf(CALL_OUTCOMES, key) }}</span>
                    <span class="bar-track"><span class="bar-fill" :style="{ width: pct(n, maxOutcome), background: outcomeOf(key).color }"></span></span>
                    <span class="bar-value">{{ n }}</span>
                  </div>
                </div>
                <a-empty v-else :image="simpleImage" />
              </div>
            </a-col>
          </a-row>
        </template>

        <!-- ============ Sources & channels ============ -->
        <template v-else-if="tab === 'sources' && data.sources">
          <div v-for="block in sourceBlocks" :key="block.key" class="panel">
            <div class="panel-head">
              <span class="panel-title">{{ block.title }}</span>
              <a-button size="small" type="text" @click="csv(block.key, block.rows, srcCols)"><template #icon><DownloadOutlined /></template> CSV</a-button>
            </div>
            <a-table v-if="block.rows.length" :data-source="block.rows" :columns="srcCols" size="small" :pagination="false" :row-key="(r) => String(r.key)" :scroll="{ x: 900 }">
              <template #bodyCell="{ column, record }">
                <template v-if="column.key === 'name'"><span class="src"><span class="dot" :style="{ background: record.color || '#94a3b8' }"></span>{{ record.name }}</span></template>
                <template v-else-if="column.key === 'conversion'">
                  <span class="conv"><span class="conv-track"><span class="conv-fill" :style="{ width: `${record.conversion || 0}%` }"></span></span>{{ record.conversion === null ? '—' : `${record.conversion}%` }}</span>
                </template>
                <template v-else-if="column.money">{{ money(record[column.key]) }}</template>
              </template>
            </a-table>
            <a-empty v-else :image="simpleImage" />
          </div>
        </template>
      </a-spin>
    </a-card>
  </div>
</template>

<script setup>
/** CRM Reports — GET crm/reports/{section}?from&to&owner (a rep without "view all" only ever sees their own numbers). */
import { ref, computed, onMounted } from 'vue';
import dayjs from 'dayjs';
import { message, Empty } from 'ant-design-vue';
import { AimOutlined, DownloadOutlined } from '@ant-design/icons-vue';
import { UserPlus, BadgeCheck, Trophy, Percent, Coins, Timer, Activity, Wallet, AlarmClock } from 'lucide-vue-next';
import PageHeader from '../../components/PageHeader.vue';
import ReportChart from '../../components/ReportChart.vue';
import { useFormat } from '../../composables/useFormat';
import http from '../../lib/http';
import { t, i18n } from '../../i18n';
import { ACTIVITY_TYPES, CALL_OUTCOMES, CAPTURE_CHANNELS, tr, optionOf, labelOf, initials, avatarColor, tint } from './crmOptions';

function tp(key, fallback, params) {
  const out = i18n.global.t(key, params);
  return out !== key ? out : Object.entries(params).reduce((x, [k, v]) => x.replace(`{${k}}`, v ?? ''), fallback);
}
const { money, date } = useFormat();
const simpleImage = Empty.PRESENTED_IMAGE_SIMPLE;

const meta = ref({ owners: [], pipelines: [] });
const tab = ref('overview');
const loading = ref(false);
const data = ref({});
const cacheKey = ref({});
const owner = ref('all');
const pipeline = ref(undefined);
const preset = ref('this_month');
const range = ref(presetRange('this_month'));

function presetRange(key) {
  const now = dayjs();
  const f = 'YYYY-MM-DD';
  const quarterStart = now.month(Math.floor(now.month() / 3) * 3).startOf('month');
  return ({
    this_month: [now.startOf('month'), now.endOf('month')],
    last_month: [now.subtract(1, 'month').startOf('month'), now.subtract(1, 'month').endOf('month')],
    this_quarter: [quarterStart, quarterStart.add(2, 'month').endOf('month')],
    last_quarter: [quarterStart.subtract(3, 'month'), quarterStart.subtract(1, 'month').endOf('month')],
    last_30: [now.subtract(29, 'day'), now],
    this_year: [now.startOf('year'), now.endOf('year')],
  }[key] || [now.startOf('month'), now.endOf('month')]).map(d => d.format(f));
}
const presets = computed(() => [
  { key: 'this_month', label: t('This_Month', 'This month') },
  { key: 'last_month', label: t('Crm_Last_Month', 'Last month') },
  { key: 'this_quarter', label: t('Crm_This_Quarter', 'This quarter') },
  { key: 'last_quarter', label: t('Crm_Last_Quarter', 'Last quarter') },
  { key: 'last_30', label: t('Crm_Last_30_Days', 'Last 30 days') },
  { key: 'this_year', label: t('Crm_This_Year', 'This year') },
]);
const rangeLabel = computed(() => `${date(range.value[0])} – ${date(range.value[1])}`);
const ownerOptions = computed(() => [{ value: 'all', label: t('Crm_All_Reps', 'All reps') }, ...meta.value.owners.map(o => ({ value: String(o.id), label: o.name }))]);
const pipelineOptions = computed(() => meta.value.pipelines.map(p => ({ value: p.id, label: p.name })));

function setPreset(key) { preset.value = key; range.value = presetRange(key); reloadActive(); }
function onRange() { preset.value = ''; reloadActive(); }
function params() {
  return { from: range.value[0], to: range.value[1], owner: owner.value === 'all' ? '' : owner.value, pipeline: pipeline.value || '' };
}
function reloadActive() { cacheKey.value = {}; loadTab(tab.value); }

async function loadTab(key) {
  const p = params();
  const sig = JSON.stringify(key === 'forecast' ? { owner: p.owner } : p);
  if (data.value[key] && cacheKey.value[key] === sig) return;
  loading.value = true;
  try {
    data.value = { ...data.value, [key]: await http.get(`crm/reports/${key}`, p) };
    cacheKey.value[key] = sig;
  } catch (e) {
    message.error(e?.data?.message || t('Crm_Could_Not_Load_Report', 'Could not load the report'));
  } finally {
    loading.value = false;
  }
}

// ----- overview -----
const overviewKpis = computed(() => {
  const k = data.value.overview?.kpis || {};
  return [
    { key: 'won', icon: Trophy, color: '#16a34a', value: money(k.won_value || 0), label: t('Crm_Won'), note: tp('Crm_Deals_N', '{n} deals', { n: k.won_deals || 0 }) },
    { key: 'rate', icon: Percent, color: '#8b5cf6', value: k.win_rate === null || k.win_rate === undefined ? '—' : `${k.win_rate}%`, label: t('Crm_Win_Rate_Label', 'Win rate'), note: tp('Crm_N_Lost', '{n} lost', { n: k.lost_deals || 0 }) },
    { key: 'avg', icon: Coins, color: '#0ea5e9', value: k.avg_deal ? money(k.avg_deal) : '—', label: t('Crm_Avg_Deal_Size', 'Average deal size') },
    { key: 'cycle', icon: Timer, color: '#f59e0b', value: k.avg_cycle_days === null || k.avg_cycle_days === undefined ? '—' : tp('Crm_N_Days', '{n} days', { n: k.avg_cycle_days }), label: t('Crm_Sales_Cycle', 'Average sales cycle') },
    { key: 'leads', icon: UserPlus, color: '#6366f1', value: k.new_leads ?? 0, label: t('Crm_New_Leads', 'New leads') },
    { key: 'conv', icon: BadgeCheck, color: '#0d9488', value: k.converted_leads ?? 0, label: t('Crm_Converted', 'Converted'), note: k.lead_conversion === null || k.lead_conversion === undefined ? '' : `${k.lead_conversion}% ${t('Crm_Conversion_Lower', 'conversion')}` },
    { key: 'acts', icon: Activity, color: '#ec4899', value: k.activities_done ?? 0, label: t('Crm_Activities_Done', 'Activities done') },
    { key: 'pipe', icon: Wallet, color: '#64748b', value: money(k.open_value || 0), label: t('Crm_Open_Pipeline', 'Open pipeline'), note: `${t('Crm_Weighted', 'Weighted')} ${money(k.weighted || 0)}` },
  ];
});

// ----- shared helpers -----
function maxOf(rows, key = 'count') { return Math.max(1, ...rows.map(r => r[key] || 0)); }
function pct(v, max) { return `${Math.max(v ? 3 : 0, Math.round((v / max) * 100))}%`; }
const maxMonth = computed(() => Math.max(1, ...(data.value.forecast?.months || []).map(m => m.value)));
function hpct(v) { return `${Math.max(v ? 4 : 0, Math.round((v / maxMonth.value) * 100))}%`; }
function monthLabel(key) {
  if (key === 'overdue') return t('Crm_Overdue', 'Overdue');
  if (key === 'later') return t('Crm_Later', 'Later');
  if (key === 'none') return t('Crm_No_Date', 'No date');
  return dayjs(`${key}-01`).format('MMM YYYY');
}
const podium = computed(() => (data.value.reps?.rows || []).filter(r => r.won_value > 0).slice(0, 3));
const actTypes = computed(() => tr(ACTIVITY_TYPES));
function outcomeOf(key) { return optionOf(CALL_OUTCOMES, key); }
const maxOutcome = computed(() => Math.max(1, ...Object.values(data.value.activities?.outcomes || {})));

const forecastCols = computed(() => [
  { title: t('Crm_Owner'), key: 'name', dataIndex: 'name' },
  { title: t('Crm_Deals'), key: 'count', dataIndex: 'count', align: 'right', width: 70 },
  { title: t('Crm_Value'), key: 'value', dataIndex: 'value', align: 'right', money: true },
  { title: t('Crm_Weighted'), key: 'weighted', dataIndex: 'weighted', align: 'right', money: true },
]);
const repCols = computed(() => [
  { title: t('Crm_Sales_Rep'), key: 'name', dataIndex: 'name', fixed: 'left', width: 200 },
  { title: t('Crm_Won'), key: 'won_value', dataIndex: 'won_value', align: 'right', money: true, sorter: (a, b) => a.won_value - b.won_value },
  { title: t('Crm_Deals_Won'), key: 'won_deals', dataIndex: 'won_deals', align: 'right', sorter: (a, b) => a.won_deals - b.won_deals },
  { title: t('Crm_Win_Rate_Label', 'Win rate'), key: 'win_rate', dataIndex: 'win_rate', align: 'right', sorter: (a, b) => (a.win_rate || 0) - (b.win_rate || 0) },
  { title: t('Crm_Target'), key: 'target_percent', dataIndex: 'target_percent', width: 150 },
  { title: t('Crm_Open_Pipeline'), key: 'open_value', dataIndex: 'open_value', align: 'right', money: true, sorter: (a, b) => a.open_value - b.open_value },
  { title: t('Crm_New_Leads'), key: 'new_leads', dataIndex: 'new_leads', align: 'right', sorter: (a, b) => a.new_leads - b.new_leads },
  { title: t('Crm_Converted'), key: 'converted_leads', dataIndex: 'converted_leads', align: 'right', sorter: (a, b) => a.converted_leads - b.converted_leads },
  { title: t('Crm_Act_Call'), key: 'calls', dataIndex: 'calls', align: 'right' },
  { title: t('Crm_Act_Meeting'), key: 'meetings', dataIndex: 'meetings', align: 'right' },
  { title: t('Crm_Act_Email'), key: 'emails', dataIndex: 'emails', align: 'right' },
  { title: t('Crm_Activities'), key: 'activities', dataIndex: 'activities', align: 'right', sorter: (a, b) => a.activities - b.activities },
]);
const actCols = computed(() => [
  { title: t('Crm_Sales_Rep'), key: 'name', dataIndex: 'name' },
  ...tr(ACTIVITY_TYPES).map(x => ({ title: x.label, key: x.value, dataIndex: x.value, align: 'right' })),
  { title: t('Total'), key: 'total', dataIndex: 'total', align: 'right' },
]);
const srcCols = computed(() => [
  { title: t('Name'), key: 'name', dataIndex: 'name', width: 200 },
  { title: t('Crm_Leads'), key: 'leads', dataIndex: 'leads', align: 'right', sorter: (a, b) => a.leads - b.leads },
  { title: t('Crm_Converted'), key: 'converted', dataIndex: 'converted', align: 'right' },
  { title: t('Crm_Conversion'), key: 'conversion', dataIndex: 'conversion', width: 160, sorter: (a, b) => (a.conversion || 0) - (b.conversion || 0) },
  { title: t('Crm_Lost'), key: 'lost', dataIndex: 'lost', align: 'right' },
  { title: t('Crm_Avg_Score'), key: 'avg_score', dataIndex: 'avg_score', align: 'right' },
  { title: t('Crm_Deals_Won'), key: 'won_deals', dataIndex: 'won_deals', align: 'right' },
  { title: t('Crm_Won_Value'), key: 'won_value', dataIndex: 'won_value', align: 'right', money: true, sorter: (a, b) => a.won_value - b.won_value },
]);
const sourceBlocks = computed(() => {
  const s = data.value.sources || {};
  const named = (rows, fallback) => (rows || []).map(r => ({ ...r, name: r.name || fallback }));
  return [
    { key: 'sources', title: t('Crm_Lead_Sources', 'Lead sources'), rows: named(s.sources, t('Crm_No_Source', 'No source')) },
    { key: 'channels', title: t('Crm_Capture_Channels', 'Capture channels'), rows: (s.channels || []).map(r => ({ ...r, name: labelOf(CAPTURE_CHANNELS, r.key || 'manual'), color: optionOf(CAPTURE_CHANNELS, r.key || 'manual').color })) },
    { key: 'forms', title: t('Crm_Web_Forms', 'Web forms'), rows: named(s.forms, '—') },
  ];
});

/** Client-side CSV of a table (what you see is what you export). */
function csv(name, rows, cols) {
  const columns = cols.value || cols;
  const esc = (v) => {
    const s = v === null || v === undefined ? '' : String(v);
    const safe = /^[=+\-@]/.test(s) ? `'${s}` : s;
    return /[",\n;]/.test(safe) ? `"${safe.replace(/"/g, '""')}"` : safe;
  };
  const lines = [columns.map(c => esc(c.title)).join(',')]
    .concat((rows || []).map(r => columns.map(c => esc(r[c.dataIndex] ?? r[c.key])).join(',')));
  const blob = new Blob([`﻿${lines.join('\n')}`], { type: 'text/csv;charset=utf-8' });
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = `crm-${name}-${range.value[0]}_${range.value[1]}.csv`;
  a.click();
  setTimeout(() => URL.revokeObjectURL(a.href), 1000);
}

onMounted(async () => {
  try {
    meta.value = await http.get('crm/reports/meta');
    pipeline.value = meta.value.pipelines.find(p => p.is_default)?.id;
  } catch (e) { /* filters stay simple */ }
  loadTab('overview');
});
</script>

<style scoped>
.filters { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin-bottom: 14px; }
.presets { display: flex; flex-wrap: wrap; gap: 4px; padding: 3px; border-radius: 10px; background: rgba(128, 128, 128, 0.08); }
.preset { border: 0; background: none; color: inherit; font: inherit; font-size: 13px; padding: 4px 11px; border-radius: 8px; cursor: pointer; }
.preset.active { background: var(--ant-color-bg-container, #fff); color: var(--app-primary, #6d28d9); font-weight: 600; box-shadow: 0 1px 3px rgba(15, 23, 42, 0.12); }
.card { border-radius: 14px; }
.shell :deep(.ant-tabs-nav) { margin-bottom: 14px; }
.hint { font-size: 12.5px; opacity: 0.6; }
.hint.top { margin-bottom: 12px; }
.muted { opacity: 0.55; }
.dot { width: 8px; height: 8px; border-radius: 50%; display: inline-block; flex: none; }

.kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 12px; margin-bottom: 16px; }
.kpi { display: flex; align-items: center; gap: 12px; padding: 12px 14px; border: 1px solid rgba(128, 128, 128, 0.18); border-radius: 14px; }
.kpi-ic { width: 40px; height: 40px; flex: none; display: inline-flex; align-items: center; justify-content: center; border-radius: 11px; }
.kpi-text { display: flex; flex-direction: column; min-width: 0; }
.kpi-value { font-size: 19px; font-weight: 700; line-height: 1.2; }
.kpi-label { font-size: 12px; opacity: 0.6; }
.kpi-note { font-size: 11.5px; font-weight: 600; opacity: 0.7; }

.panel { border: 1px solid rgba(128, 128, 128, 0.16); border-radius: 12px; padding: 14px 16px; margin-bottom: 16px; }
.panel-title { font-weight: 600; font-size: 14.5px; margin-bottom: 10px; }
.panel-title small { font-weight: 500; opacity: 0.6; }
.panel-title.ok { color: #16a34a; }
.panel-title.bad { color: #dc2626; }
.panel-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px; }
.panel-head .panel-title { margin-bottom: 0; }

.funnel { display: flex; flex-direction: column; gap: 8px; margin-top: 12px; }
.fn-row { display: grid; grid-template-columns: 1fr 1fr; align-items: center; gap: 12px; }
.fn-bar { height: 34px; border-radius: 8px; display: flex; align-items: center; justify-content: flex-end; padding: 0 10px; color: #fff; font-weight: 700; justify-self: end; transition: width 0.4s ease; min-width: 40px; }
.fn-text { display: flex; flex-direction: column; min-width: 0; }
.fn-name { font-weight: 600; font-size: 13px; }
.fn-sub { font-size: 12px; opacity: 0.6; }

.bars { display: flex; flex-direction: column; gap: 9px; }
.bar-row { display: grid; grid-template-columns: minmax(90px, 150px) 1fr auto; align-items: center; gap: 10px; }
.bar-label { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.bar-track { position: relative; height: 10px; border-radius: 999px; background: rgba(128, 128, 128, 0.12); overflow: hidden; }
.bar-fill { position: absolute; inset: 0 auto 0 0; border-radius: 999px; }
.bar-value { font-weight: 600; font-size: 13px; text-align: end; white-space: nowrap; }
.bar-value small { font-weight: 500; opacity: 0.6; }

.deal-row { display: flex; align-items: center; gap: 10px; padding: 8px 2px; border-bottom: 1px solid rgba(128, 128, 128, 0.12); color: inherit; }
.deal-row:last-of-type { border-bottom: 0; }
.deal-row:hover strong { color: var(--app-primary, #6d28d9); }
.dr-main { flex: 1; min-width: 0; display: flex; flex-direction: column; }
.dr-main strong { font-weight: 500; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.dr-main small { font-size: 12px; opacity: 0.6; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.dr-value { font-weight: 700; white-space: nowrap; }
.ok { color: #16a34a; }
.bad { color: #dc2626; }
.pill { font-size: 11.5px; font-weight: 600; padding: 1px 8px; border-radius: 999px; white-space: nowrap; }

.fc-head { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; margin-bottom: 16px; }
.fc-box { display: flex; flex-direction: column; gap: 2px; padding: 12px 14px; border-radius: 12px; background: rgba(var(--app-primary-rgb, 109, 40, 217), 0.06); }
.fc-l { font-size: 12px; opacity: 0.65; }
.fc-v { font-size: 20px; font-weight: 700; }
.fc-n { font-size: 11.5px; opacity: 0.6; }
.months { display: grid; grid-template-columns: repeat(auto-fit, minmax(84px, 1fr)); gap: 10px; align-items: end; }
.mcol { display: flex; flex-direction: column; align-items: center; text-align: center; }
.m-bars { height: 150px; width: 100%; display: flex; align-items: flex-end; justify-content: center; gap: 4px; }
.m-bar { width: 22px; border-radius: 6px 6px 2px 2px; transition: height 0.4s ease; }
.m-bar.total { background: rgba(var(--app-primary-rgb, 109, 40, 217), 0.28); }
.m-bar.weighted { background: var(--app-primary, #6d28d9); }
.mcol.late .m-bar.total { background: rgba(220, 38, 38, 0.3); }
.mcol.late .m-bar.weighted { background: #dc2626; }
.m-label { font-size: 12px; font-weight: 600; margin-top: 6px; }
.m-value { font-size: 12.5px; font-weight: 700; }
.m-sub { font-size: 11px; opacity: 0.55; }
.legend { display: flex; gap: 16px; font-size: 12px; opacity: 0.7; margin-top: 10px; }
.lg { display: inline-block; width: 10px; height: 10px; border-radius: 3px; margin-inline-end: 5px; vertical-align: -1px; }
.lg.total { background: rgba(var(--app-primary-rgb, 109, 40, 217), 0.28); }
.lg.weighted { background: var(--app-primary, #6d28d9); }

.podium { display: flex; justify-content: center; align-items: flex-end; gap: 14px; margin: 4px 0 18px; flex-wrap: wrap; }
.pod { position: relative; width: 190px; padding: 18px 12px 14px; border-radius: 14px; text-align: center; border: 1px solid rgba(128, 128, 128, 0.16); display: flex; flex-direction: column; align-items: center; gap: 4px; }
.pod.p1 { order: 2; padding-top: 26px; border-color: rgba(234, 179, 8, 0.6); background: rgba(234, 179, 8, 0.07); }
.pod.p2 { order: 1; }
.pod.p3 { order: 3; }
.medal { position: absolute; top: -12px; width: 26px; height: 26px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 13px; color: #fff; background: #94a3b8; }
.p1 .medal { background: #eab308; }
.p3 .medal { background: #b45309; }
.pod-name { font-weight: 600; margin-top: 4px; }
.pod-value { font-size: 18px; font-weight: 800; color: #16a34a; }
.pod-sub { font-size: 12px; opacity: 0.6; }
.rep { display: inline-flex; align-items: center; gap: 8px; }
.rank { width: 18px; font-size: 12px; font-weight: 700; opacity: 0.5; text-align: end; }

.src { display: inline-flex; align-items: center; gap: 6px; }
.conv { display: inline-flex; align-items: center; gap: 8px; font-size: 12.5px; font-weight: 600; }
.conv-track { width: 70px; height: 7px; border-radius: 999px; background: rgba(128, 128, 128, 0.14); overflow: hidden; display: inline-block; }
.conv-fill { display: block; height: 100%; background: #16a34a; border-radius: 999px; }
@media (max-width: 640px) {
  .fn-row { grid-template-columns: 1fr; }
  .fn-bar { justify-self: start; }
}
</style>
