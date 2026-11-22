<template>
  <div class="page">
    <PageHeader
      :title="$t('Page_Projects_Dashboard')"
      :subtitle="$t('Pm_Dashboard_Subtitle')"
      :breadcrumb="[$t('Pm_Projects_Management'), $t('Dashboard')]"
    >
      <template #actions>
        <a-button @click="$router.push('/tasks/board')">
          <template #icon><AppstoreOutlined /></template>
          {{ $t('Pm_Task_Board') }}
        </a-button>
        <a-button type="primary" @click="$router.push('/projects/create')">
          <template #icon><PlusOutlined /></template>
          {{ $t('Pm_New_Project') }}
        </a-button>
      </template>
    </PageHeader>

    <a-spin :spinning="loading">
      <div class="kpis">
        <button type="button" class="kpi" @click="$router.push('/projects')">
          <span class="kpi-ic kpi-ic--brand"><ProjectOutlined /></span>
          <span class="kpi-text">
            <span class="kpi-value">{{ n(data.projects_open) }}</span>
            <span class="kpi-label">{{ $t('Pm_Open_Projects') }}</span>
          </span>
          <span class="kpi-note">{{ n(data.projects_total) }} {{ $t('Pm_Total') }}</span>
        </button>

        <button type="button" class="kpi" @click="$router.push('/projects')">
          <span class="kpi-ic kpi-ic--warn"><ClockCircleOutlined /></span>
          <span class="kpi-text">
            <span class="kpi-value">{{ n(data.projects_overdue) }}</span>
            <span class="kpi-label">{{ $t('Pm_Projects_Past_Due') }}</span>
          </span>
        </button>

        <button type="button" class="kpi" @click="$router.push('/tasks/board')">
          <span class="kpi-ic kpi-ic--info"><CheckSquareOutlined /></span>
          <span class="kpi-text">
            <span class="kpi-value">
              {{ data.tasks_completion !== null && data.tasks_completion !== undefined
                ? data.tasks_completion + '%' : '—' }}
            </span>
            <span class="kpi-label">{{ $t('Pm_Tasks_Complete') }}</span>
          </span>
          <span class="kpi-note">{{ n(data.tasks_done) }} / {{ n(data.tasks_total) }}</span>
        </button>

        <button type="button" class="kpi" @click="$router.push('/tasks')">
          <span class="kpi-ic kpi-ic--danger"><ExclamationCircleOutlined /></span>
          <span class="kpi-text">
            <span class="kpi-value">{{ n(data.tasks_overdue) }}</span>
            <span class="kpi-label">{{ $t('Pm_Tasks_Past_Due') }}</span>
          </span>
        </button>

        <button type="button" class="kpi" @click="$router.push('/projects/milestones')">
          <span class="kpi-ic kpi-ic--flag"><FlagOutlined /></span>
          <span class="kpi-text">
            <span class="kpi-value">{{ n(data.milestones_open) }}</span>
            <span class="kpi-label">{{ $t('Pm_Open_Milestones') }}</span>
          </span>
          <span v-if="data.milestones_overdue" class="kpi-note kpi-note--danger">
            {{ data.milestones_overdue }} {{ $t('Pm_Late') }}
          </span>
        </button>

        <button type="button" class="kpi" @click="$router.push('/projects/timesheets')">
          <span class="kpi-ic kpi-ic--time"><FieldTimeOutlined /></span>
          <span class="kpi-text">
            <span class="kpi-value">{{ number(data.hours_month || 0, 1) }}h</span>
            <span class="kpi-label">{{ $t('Pm_Logged_This_Month') }}</span>
          </span>
          <span v-if="data.billable_month" class="kpi-note">{{ money(data.billable_month) }}</span>
        </button>
      </div>

      <a-row :gutter="16">
        <a-col :xs="24" :xl="16">
          <ReportChart
            :data="data.hours_trend || []"
            :fields="[{ key: 'hours', label: $t('Pm_Hours_Logged') }]"
            :title="$t('Pm_Effort_Last_14_Days')"
            type="bar"
            :height="260"
          />

          <a-card size="small" :title="$t('Pm_Active_Projects')" style="margin-bottom: 16px">
            <template #extra>
              <a-button type="link" size="small" @click="$router.push('/projects')">{{ $t('All') }}</a-button>
            </template>
            <div v-if="(data.active_projects || []).length" class="plist">
              <button
                v-for="p in data.active_projects" :key="p.id" type="button" class="prow"
                @click="$router.push(`/projects/${p.id}/edit`)"
              >
                <span class="prow-head">
                  <span class="prow-title">{{ p.title }}</span>
                  <a-tag :color="optionOf(PROJECT_STATUSES, p.status).color">
                    {{ labelOf(PROJECT_STATUSES, p.status) }}
                  </a-tag>
                </span>
                <span class="prow-meta">
                  {{ p.client_name || $t('Pm_No_Client') }} · {{ p.tasks_done }}/{{ p.tasks }} {{ $t('Pm_Tasks_Lc') }}
                  · {{ number(p.hours, 1) }}h
                </span>
                <a-progress
                  :percent="p.progress" size="small" :stroke-color="progressColor(p.progress)"
                  class="prow-bar"
                />
              </button>
            </div>
            <a-empty v-else :image="simpleEmptyImage" :description="$t('Pm_No_Open_Projects')" />
          </a-card>
        </a-col>

        <a-col :xs="24" :xl="8">
          <a-card size="small" :title="$t('Pm_Upcoming_Milestones')" style="margin-bottom: 16px">
            <template #extra>
              <a-button type="link" size="small" @click="$router.push('/projects/milestones')">{{ $t('All') }}</a-button>
            </template>
            <div v-if="(data.upcoming_milestones || []).length" class="mlist">
              <button
                v-for="m in data.upcoming_milestones" :key="m.id" type="button" class="mrow"
                @click="$router.push('/projects/milestones')"
              >
                <span class="mrow-body">
                  <span class="mrow-title">{{ m.title }}</span>
                  <span class="mrow-meta">{{ m.project_title }} · {{ date(m.due_date) }}</span>
                </span>
                <a-tag v-if="dueLabel(m.days_to_due)" :color="dueLabel(m.days_to_due).color">
                  {{ dueLabel(m.days_to_due).text }}
                </a-tag>
              </button>
            </div>
            <a-empty v-else :image="simpleEmptyImage" :description="$t('Pm_Nothing_Scheduled')" style="padding: 16px 0" />
          </a-card>

          <a-card size="small" :title="$t('Pm_Workload_This_Month')" style="margin-bottom: 16px">
            <div v-if="(data.workload || []).length" class="wlist">
              <div v-for="w in data.workload" :key="w.id" class="wrow">
                <span class="wrow-name">{{ w.name }}</span>
                <a-progress
                  :percent="workloadPercent(w.hours)" :show-info="false" size="small"
                  stroke-color="#6d28d9" class="wrow-bar"
                />
                <span class="wrow-hours">{{ number(w.hours, 1) }}h</span>
              </div>
            </div>
            <a-empty v-else :image="simpleEmptyImage" :description="$t('Pm_No_Time_Logged_Yet')" />
          </a-card>

          <a-card size="small" :title="$t('Pm_Task_Breakdown')">
            <div v-if="(data.tasks_by_status || []).length" class="breakdown">
              <div v-for="row in data.tasks_by_status" :key="row.status" class="brow">
                <a-tag :color="optionOf(TASK_STATUSES, row.status).color">
                  {{ labelOf(TASK_STATUSES, row.status) }}
                </a-tag>
                <span class="bcount">{{ row.count }}</span>
              </div>
            </div>
            <a-empty v-else :image="simpleEmptyImage" :description="$t('Pm_No_Tasks_Yet')" />
          </a-card>
        </a-col>
      </a-row>
    </a-spin>
  </div>
</template>

<script setup>
/**
 * Projects Management dashboard — one GET (projects/dashboard) feeding every
 * panel, because the project, task and effort figures have to agree with each
 * other.
 *
 * The KPI tiles are buttons: each navigates to the list it counts, so a number
 * on screen is always one click from the rows behind it.
 */
import { ref, computed, onMounted } from 'vue';
import { message, Empty } from 'ant-design-vue';
import {
  PlusOutlined, ProjectOutlined, ClockCircleOutlined, CheckSquareOutlined,
  ExclamationCircleOutlined, FlagOutlined, FieldTimeOutlined, AppstoreOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import ReportChart from '../../components/ReportChart.vue';
import { useFormat } from '../../composables/useFormat';
import {
  PROJECT_STATUSES, TASK_STATUSES, labelOf, optionOf, progressColor, dueLabel,
} from './workspaceOptions';
import http from '../../lib/http';
import { t } from '../../i18n';

const { money, number, date } = useFormat();

const simpleEmptyImage = Empty.PRESENTED_IMAGE_SIMPLE;
const data = ref({});
const loading = ref(false);

const n = value => number(value || 0, 0);

const maxWorkload = computed(() =>
  Math.max(1, ...(data.value.workload || []).map(w => Number(w.hours) || 0)));

function workloadPercent(hours) {
  return Math.round(((Number(hours) || 0) / maxWorkload.value) * 100);
}

async function load() {
  loading.value = true;
  try {
    data.value = await http.get('projects/dashboard');
  } catch (e) {
    message.error(t('InvalidData', 'Could not load the projects dashboard'));
  } finally {
    loading.value = false;
  }
}

onMounted(load);
</script>

<style scoped>
.kpis {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 12px;
  margin-bottom: 16px;
}
.kpi {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 14px 16px;
  border: 1px solid rgba(128, 128, 128, 0.2);
  border-radius: 14px;
  background: transparent;
  cursor: pointer;
  text-align: left;
  color: inherit;
  font: inherit;
  transition: border-color 0.15s ease, transform 0.12s ease;
}
.kpi:hover {
  border-color: rgba(109, 40, 217, 0.5);
  transform: translateY(-1px);
}
.kpi-ic {
  width: 40px;
  height: 40px;
  flex: none;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 12px;
  font-size: 18px;
}
.kpi-ic--brand {
  color: #6d28d9;
  background: rgba(109, 40, 217, 0.12);
}
.kpi-ic--info {
  color: #0891b2;
  background: rgba(8, 145, 178, 0.12);
}
.kpi-ic--warn {
  color: #d97706;
  background: rgba(217, 119, 6, 0.14);
}
.kpi-ic--danger {
  color: #ff4d4f;
  background: rgba(255, 77, 79, 0.12);
}
.kpi-ic--flag {
  color: #4f46e5;
  background: rgba(79, 70, 229, 0.12);
}
.kpi-ic--time {
  color: #0d9488;
  background: rgba(13, 148, 136, 0.13);
}
.kpi-text {
  display: flex;
  flex-direction: column;
  min-width: 0;
}
.kpi-value {
  font-size: 18px;
  font-weight: 600;
  line-height: 1.2;
}
.kpi-label {
  font-size: 12px;
  opacity: 0.6;
}
.kpi-note {
  margin-inline-start: auto;
  font-size: 11px;
  opacity: 0.6;
  white-space: nowrap;
}
.kpi-note--danger {
  color: #ff4d4f;
  opacity: 1;
}
.plist {
  display: flex;
  flex-direction: column;
  gap: 4px;
}
.prow {
  display: flex;
  flex-direction: column;
  gap: 2px;
  width: 100%;
  padding: 10px 8px;
  border: 0;
  border-radius: 10px;
  background: none;
  cursor: pointer;
  color: inherit;
  font: inherit;
  text-align: left;
  transition: background 0.15s ease;
}
.prow:hover {
  background: rgba(128, 128, 128, 0.09);
}
.prow-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
}
.prow-title {
  font-weight: 500;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.prow-meta {
  font-size: 11.5px;
  opacity: 0.6;
}
.prow-bar {
  margin: 2px 0 0;
}
.mlist {
  display: flex;
  flex-direction: column;
  gap: 2px;
  max-height: 300px;
  overflow-y: auto;
}
.mrow {
  display: flex;
  align-items: center;
  gap: 10px;
  width: 100%;
  padding: 8px 6px;
  border: 0;
  border-radius: 9px;
  background: none;
  cursor: pointer;
  color: inherit;
  font: inherit;
  text-align: left;
  transition: background 0.15s ease;
}
.mrow:hover {
  background: rgba(128, 128, 128, 0.1);
}
.mrow-body {
  display: flex;
  flex-direction: column;
  min-width: 0;
  flex: 1;
}
.mrow-title {
  font-weight: 500;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.mrow-meta {
  font-size: 11.5px;
  opacity: 0.6;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.wlist {
  display: flex;
  flex-direction: column;
  gap: 10px;
}
.wrow {
  display: flex;
  align-items: center;
  gap: 12px;
}
.wrow-name {
  width: 110px;
  flex: none;
  font-size: 13px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.wrow-bar {
  flex: 1;
  margin: 0;
}
.wrow-hours {
  width: 52px;
  flex: none;
  text-align: right;
  font-weight: 600;
  font-size: 12.5px;
}
.breakdown {
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.brow {
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.bcount {
  font-weight: 600;
}
</style>
