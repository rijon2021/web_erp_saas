<template>
  <div class="page">
    <PageHeader :title="$t('Crm_Targets')" :subtitle="$t('Crm_Targets_Subtitle')" :breadcrumb="[$t('Crm'), $t('Crm_Targets')]">
      <template #actions>
        <a-button :loading="copying" @click="copyPrevious"><template #icon><CopyOutlined /></template>{{ periodType === 'quarter' ? $t('Crm_Copy_Last_Quarter') : $t('Crm_Copy_Last_Month') }}</a-button>
        <a-button type="primary" :loading="saving" :disabled="!dirty" @click="save"><template #icon><SaveOutlined /></template>{{ $t('Save') }}</a-button>
      </template>
    </PageHeader>

    <div class="bar">
      <a-segmented v-model:value="periodType" :options="periodOptions" @change="onType" />
      <div class="nav">
        <a-button shape="circle" size="small" @click="shift(-1)"><template #icon><LeftOutlined /></template></a-button>
        <span class="period">{{ periodLabel }}</span>
        <a-button shape="circle" size="small" @click="shift(1)"><template #icon><RightOutlined /></template></a-button>
        <a-button v-if="!isCurrent" size="small" type="link" @click="goCurrent">{{ $t('Crm_Today') }}</a-button>
      </div>
      <span v-if="isCurrent" class="elapsed">
        <a-progress :percent="elapsed" :show-info="false" size="small" style="width: 120px" />
        {{ tp('Crm_Period_Elapsed', '{n}% of the period elapsed', { n: elapsed }) }}
      </span>
    </div>

    <a-spin :spinning="loading">
      <a-card :bordered="false" class="card">
        <div class="grid-wrap">
          <table class="grid">
            <thead>
              <tr>
                <th class="who">{{ $t('Crm_Sales_Rep') }}</th>
                <th v-for="m in metrics" :key="m.key">{{ metricLabel(m.key) }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in rows" :key="row.key" :class="{ team: row.key === 'team' }">
                <td class="who">
                  <span class="person">
                    <a-avatar v-if="row.key !== 'team'" :size="28" :style="{ background: avatarColor(row.name), fontSize: '11px' }">{{ initials(row.name) }}</a-avatar>
                    <span v-else class="team-ic"><TeamOutlined /></span>
                    <span><strong>{{ row.name }}</strong><small v-if="row.key === 'team'">{{ $t('Crm_Team_Target_Hint') }}</small></span>
                  </span>
                </td>
                <td v-for="m in metrics" :key="m.key" class="cell">
                  <a-input-number
                    v-model:value="values[row.key][m.key]" :min="0" :precision="m.money ? 2 : 0" size="small" class="num"
                    :placeholder="$t('Crm_No_Target')" @change="dirty = true"
                  />
                  <div class="prog">
                    <template v-if="values[row.key][m.key]">
                      <div class="track">
                        <div class="fill" :style="{ width: `${Math.min(100, percent(row.key, m.key))}%`, background: barColor(percent(row.key, m.key)) }"></div>
                        <div v-if="isCurrent" class="pace" :style="{ left: `${elapsed}%` }" :title="$t('Crm_Pace_Hint')"></div>
                      </div>
                      <span class="pct" :style="{ color: barColor(percent(row.key, m.key)) }">{{ percent(row.key, m.key) }}%</span>
                    </template>
                    <span class="actual">{{ fmt(m, actual(row.key, m.key)) }}<template v-if="values[row.key][m.key]"> / {{ fmt(m, values[row.key][m.key]) }}</template></span>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <div class="hint">{{ $t('Crm_Targets_Hint') }}</div>
      </a-card>
    </a-spin>
  </div>
</template>

<script setup>
/** CRM sales targets — GET/PUT crm/targets: one goal per rep (or the whole team) and metric, per month or quarter. */
import { ref, computed, onMounted } from 'vue';
import dayjs from 'dayjs';
import { message, Modal } from 'ant-design-vue';
import { SaveOutlined, CopyOutlined, LeftOutlined, RightOutlined, TeamOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import { useFormat } from '../../composables/useFormat';
import http from '../../lib/http';
import { t, i18n } from '../../i18n';
import { initials, avatarColor } from './crmOptions';

const { money } = useFormat();
const loading = ref(false);
const saving = ref(false);
const copying = ref(false);
const dirty = ref(false);
const periodType = ref('month');
const periodStart = ref(dayjs().startOf('month').format('YYYY-MM-DD'));
const payload = ref({ metrics: [], users: [], targets: {}, actuals: {} });
const values = ref({});

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

const periodOptions = computed(() => [{ value: 'month', label: t('Crm_Monthly', 'Monthly') }, { value: 'quarter', label: t('Crm_Quarterly', 'Quarterly') }]);
const metrics = computed(() => payload.value.metrics);
const rows = computed(() => [
  { key: 'team', name: t('Crm_Whole_Team', 'Whole team') },
  ...payload.value.users.map(u => ({ key: String(u.id), id: u.id, name: u.name })),
]);
const periodLabel = computed(() => {
  const d = dayjs(periodStart.value);
  return periodType.value === 'quarter' ? `Q${Math.floor(d.month() / 3) + 1} ${d.year()}` : d.format('MMMM YYYY');
});
const isCurrent = computed(() => {
  const now = dayjs();
  const s = dayjs(periodStart.value);
  const e = periodType.value === 'quarter' ? s.add(3, 'month') : s.add(1, 'month');
  return !now.isBefore(s) && now.isBefore(e);
});
const elapsed = computed(() => {
  const s = dayjs(payload.value.period_start || periodStart.value);
  const e = dayjs(payload.value.period_end || periodStart.value).endOf('day');
  return Math.max(0, Math.min(100, Math.round((dayjs().diff(s) / Math.max(1, e.diff(s))) * 100)));
});

function actual(rowKey, metric) { return payload.value.actuals?.[rowKey]?.[metric] ?? 0; }
function percent(rowKey, metric) {
  const target = Number(values.value[rowKey]?.[metric]) || 0;
  return target > 0 ? Math.round((actual(rowKey, metric) / target) * 100) : 0;
}
function barColor(p) {
  if (p >= 100) return '#16a34a';
  if (!isCurrent.value) return p >= 80 ? '#f59e0b' : '#dc2626';
  return p >= elapsed.value ? '#16a34a' : (p >= elapsed.value * 0.7 ? '#f59e0b' : '#dc2626');
}
function fmt(m, v) { return m.money ? money(v || 0) : Math.round(Number(v) || 0); }

function quarterStart(d) { return d.month(Math.floor(d.month() / 3) * 3).startOf('month'); }
function onType() {
  const d = dayjs(periodStart.value);
  periodStart.value = (periodType.value === 'quarter' ? quarterStart(d) : d.startOf('month')).format('YYYY-MM-DD');
  confirmLeave(load);
}
function shift(dir) {
  confirmLeave(() => {
    periodStart.value = dayjs(periodStart.value).add(dir * (periodType.value === 'quarter' ? 3 : 1), 'month').format('YYYY-MM-DD');
    load();
  });
}
function goCurrent() {
  confirmLeave(() => {
    const now = dayjs();
    periodStart.value = (periodType.value === 'quarter' ? quarterStart(now) : now.startOf('month')).format('YYYY-MM-DD');
    load();
  });
}
function confirmLeave(fn) {
  if (!dirty.value) { fn(); return; }
  Modal.confirm({
    title: t('Crm_Unsaved_Targets', 'Discard unsaved changes?'),
    okText: t('Crm_Discard', 'Discard'), cancelText: t('Cancel', 'Cancel'),
    onOk: () => { dirty.value = false; fn(); },
  });
}

async function load() {
  loading.value = true;
  try {
    const res = await http.get('crm/targets', { period_type: periodType.value, period_start: periodStart.value });
    payload.value = res;
    periodStart.value = res.period_start;
    const v = {};
    for (const r of [{ key: 'team' }, ...res.users.map(u => ({ key: String(u.id) }))]) {
      v[r.key] = {};
      for (const m of res.metrics) v[r.key][m.key] = res.targets?.[r.key]?.[m.key]?.target || null;
    }
    values.value = v;
    dirty.value = false;
  } catch (e) {
    message.error(e?.data?.message || t('Crm_Err_Could_Not_Load_The_Targets', 'Could not load the targets'));
  } finally {
    loading.value = false;
  }
}

async function save() {
  saving.value = true;
  const out = [];
  for (const r of rows.value) {
    for (const m of metrics.value) {
      out.push({ user_id: r.key === 'team' ? null : r.id, metric: m.key, value: values.value[r.key][m.key] ?? '' });
    }
  }
  try {
    await http.put('crm/targets', { period_type: periodType.value, period_start: periodStart.value, rows: out });
    message.success(t('Saved', 'Saved'));
    await load();
  } catch (e) {
    const errors = e?.data?.errors;
    message.error((errors && Object.values(errors)[0]?.[0]) || e?.data?.message || t('Gen_Could_Not_Save', 'Could not save'));
  } finally {
    saving.value = false;
  }
}

async function copyPrevious() {
  copying.value = true;
  try {
    const res = await http.post('crm/targets/copy', { period_type: periodType.value, period_start: periodStart.value });
    message.success(tp('Crm_Targets_Copied', '{n} targets copied', { n: res.copied }));
    await load();
  } catch (e) {
    message.error(e?.data?.message || t('Crm_Err_Could_Not_Copy', 'Could not copy'));
  } finally {
    copying.value = false;
  }
}

onMounted(load);
</script>

<style scoped>
.bar { display: flex; flex-wrap: wrap; align-items: center; gap: 14px; margin-bottom: 14px; }
.nav { display: flex; align-items: center; gap: 8px; }
.period { font-weight: 700; font-size: 15px; min-width: 140px; text-align: center; }
.elapsed { display: inline-flex; align-items: center; gap: 8px; font-size: 12.5px; opacity: 0.7; }
.card { border-radius: 14px; }
.grid-wrap { overflow-x: auto; }
.grid { width: 100%; border-collapse: separate; border-spacing: 0; min-width: 900px; }
.grid th { font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.03em; opacity: 0.6; text-align: start; padding: 8px 10px; border-bottom: 1px solid rgba(128, 128, 128, 0.18); }
.grid td { padding: 10px; border-bottom: 1px solid rgba(128, 128, 128, 0.1); vertical-align: top; }
.grid tr.team td { background: rgba(var(--app-primary-rgb, 109, 40, 217), 0.04); }
.who { position: sticky; left: 0; background: var(--ant-color-bg-container, #fff); z-index: 1; min-width: 200px; }
.grid tr.team td.who { background: color-mix(in srgb, var(--app-primary, #6d28d9) 4%, var(--ant-color-bg-container, #fff)); }
.person { display: flex; align-items: center; gap: 8px; }
.person small { display: block; font-size: 11.5px; opacity: 0.55; font-weight: 400; }
.team-ic { width: 28px; height: 28px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; background: rgba(var(--app-primary-rgb, 109, 40, 217), 0.14); color: var(--app-primary, #6d28d9); }
.cell { min-width: 150px; }
.num { width: 100%; }
.prog { margin-top: 6px; display: flex; flex-wrap: wrap; align-items: center; gap: 4px 8px; }
.track { position: relative; flex: 1; min-width: 70px; height: 7px; border-radius: 999px; background: rgba(128, 128, 128, 0.14); }
.fill { position: absolute; inset: 0 auto 0 0; border-radius: 999px; transition: width 0.3s; }
.pace { position: absolute; top: -3px; width: 2px; height: 13px; background: rgba(15, 23, 42, 0.45); border-radius: 1px; }
.pct { font-size: 12px; font-weight: 700; }
.actual { flex-basis: 100%; font-size: 11.5px; opacity: 0.6; }
.hint { font-size: 12px; opacity: 0.6; margin-top: 12px; }
</style>
