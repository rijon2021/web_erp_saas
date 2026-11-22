<template>
  <div class="page">
    <PageHeader :title="$t('Rental_Maintenance')" :breadcrumb="[$t('Rental_Management'), $t('Rental_Maintenance')]">
      <template #actions>
        <a-button v-if="tab === 'schedules'" type="primary" @click="openSchedule()">
          <template #icon><PlusOutlined /></template>{{ $t('Rental_New_Schedule') }}
        </a-button>
        <a-button v-else type="primary" @click="openForm()">
          <template #icon><PlusOutlined /></template>{{ $t('Rental_New_Job') }}
        </a-button>
      </template>
    </PageHeader>

    <a-segmented v-model:value="tab" :options="tabOptions" class="tabs" />

    <!-- ================= Jobs ================= -->
    <template v-if="tab === 'jobs'">
      <div class="tiles">
        <div class="tile"><span>{{ $t('Rental_Open_Jobs') }}</span><strong>{{ summary.counts?.active || 0 }}</strong></div>
        <div class="tile bad"><span>{{ $t('Rental_Urgent') }}</span><strong>{{ summary.urgent || 0 }}</strong></div>
        <div class="tile warn"><span>{{ $t('Rental_Overdue_Jobs') }}</span><strong>{{ summary.counts?.overdue || 0 }}</strong></div>
        <div class="tile"><span>{{ $t('Rental_Maint_Cost_Month') }}</span><strong>{{ money(summary.cost_month || 0) }}</strong></div>
      </div>

      <div class="stages" role="tablist">
        <button
          v-for="s in stageTabs" :key="s.value" type="button" role="tab"
          class="stage" :class="{ active: stage === s.value }" @click="setStage(s.value)"
        >
          {{ s.label }} <span class="stage-count">{{ summary.counts?.[s.value] ?? 0 }}</span>
        </button>
      </div>

      <DataTable :crud="crud" :columns="columns">
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'number'">
            <button type="button" class="num" @click="openJob(record.id)">
              <span class="prio" :style="{ background: optionOf(MAINT_PRIORITIES, record.priority).hex }"></span>
              {{ record.number }}
            </button>
            <span class="sub">{{ labelOf(MAINT_TYPES, record.type) }}<template v-if="record.source !== 'manual'"> · {{ sourceLabel(record.source) }}</template></span>
          </template>
          <template v-else-if="column.key === 'title'">
            <span class="strong">{{ record.title }}</span>
            <router-link :to="`/rental/items/${record.rental_item_id}`" class="sub">{{ record.item_name }} · {{ record.item_code }}</router-link>
          </template>
          <template v-else-if="column.key === 'priority'">
            <a-tag :color="optionOf(MAINT_PRIORITIES, record.priority).color">{{ labelOf(MAINT_PRIORITIES, record.priority) }}</a-tag>
          </template>
          <template v-else-if="column.key === 'scheduled_start'">
            <template v-if="record.scheduled_start">
              {{ dateTime(record.scheduled_start) }}
              <span v-if="record.scheduled_end" class="sub">→ {{ dateTime(record.scheduled_end) }}</span>
            </template>
            <span v-else class="muted">—</span>
            <span v-if="record.overdue" class="sub bad">{{ $t('Rental_Overdue_Amount') }}</span>
          </template>
          <template v-else-if="column.key === 'who'">
            {{ record.employee_name || record.vendor || '—' }}
          </template>
          <template v-else-if="column.key === 'cost'">
            {{ record.cost ? money(record.cost) : '—' }}
          </template>
          <template v-else-if="column.key === 'status'">
            <a-tag :color="optionOf(MAINT_STATUSES, record.status).color">{{ labelOf(MAINT_STATUSES, record.status) }}</a-tag>
          </template>
          <template v-else-if="column.key === 'actions'">
            <a-space :size="0">
              <a-tooltip v-if="['open', 'scheduled'].includes(record.status)" :title="$t('Rental_Start_Job')">
                <a-button type="text" size="small" @click="startJob(record)"><template #icon><PlayCircleOutlined /></template></a-button>
              </a-tooltip>
              <a-tooltip v-if="['open', 'scheduled', 'in_progress'].includes(record.status)" :title="$t('Rental_Complete_Job')">
                <a-button type="text" size="small" @click="openComplete(record)"><template #icon><CheckCircleOutlined /></template></a-button>
              </a-tooltip>
              <a-tooltip :title="$t('View')">
                <a-button type="text" size="small" @click="openJob(record.id)"><template #icon><EyeOutlined /></template></a-button>
              </a-tooltip>
            </a-space>
          </template>
        </template>
      </DataTable>
    </template>

    <!-- ================= Schedules ================= -->
    <a-card v-else size="small">
      <a-spin :spinning="schedLoading">
        <div v-if="schedules.length" class="scheds">
          <div v-for="s in schedules" :key="s.id" class="sched" :class="{ off: !s.active }">
            <span class="sched-ic"><ScheduleOutlined /></span>
            <span class="sched-body">
              <strong>{{ s.title }}</strong>
              <router-link :to="`/rental/items/${s.rental_item_id}`" class="sub">{{ s.item_name }} · {{ s.item_code }}</router-link>
              <span class="sched-rule">{{ ruleText(s) }}</span>
            </span>
            <span class="sched-due">
              <template v-if="s.next_due_date">
                <span class="sub">{{ $t('Rental_Next_Due') }}</span>
                <strong :class="{ bad: s.days_left !== null && s.days_left < 0, warn: s.days_left !== null && s.days_left >= 0 && s.days_left <= s.lead_days }">{{ date(s.next_due_date) }}</strong>
              </template>
              <template v-if="s.next_due_meter !== null">
                <span class="sub">{{ $t('Rental_At_Meter') }}</span>
                <strong>{{ s.next_due_meter }} <small>({{ $t('Rental_Now') }} {{ s.item_meter ?? '—' }})</small></strong>
              </template>
            </span>
            <a-tag v-if="!s.active">{{ $t('Rental_Paused') }}</a-tag>
            <a-space :size="0">
              <a-button type="text" size="small" @click="openSchedule(s)"><template #icon><EditOutlined /></template></a-button>
              <a-button type="text" size="small" danger @click="removeSchedule(s)"><template #icon><DeleteOutlined /></template></a-button>
            </a-space>
          </div>
        </div>
        <div v-else class="empty">
          <ScheduleOutlined class="empty-ic" />
          <p>{{ $t('Rental_No_Schedules') }}</p>
          <a-button type="primary" @click="openSchedule()"><template #icon><PlusOutlined /></template>{{ $t('Rental_New_Schedule') }}</a-button>
        </div>
      </a-spin>
    </a-card>

    <!-- ================= Job drawer ================= -->
    <a-drawer v-model:open="drawerOpen" :width="drawerWidth" :title="job ? `${job.number} — ${job.title}` : ''" destroy-on-close>
      <a-spin :spinning="jobLoading">
        <template v-if="job">
          <div class="dj-tags">
            <a-tag :color="optionOf(MAINT_STATUSES, job.status).color">{{ labelOf(MAINT_STATUSES, job.status) }}</a-tag>
            <a-tag :color="optionOf(MAINT_PRIORITIES, job.priority).color">{{ labelOf(MAINT_PRIORITIES, job.priority) }}</a-tag>
            <a-tag>{{ labelOf(MAINT_TYPES, job.type) }}</a-tag>
            <a-tag v-if="job.overdue" color="error">{{ $t('Rental_Overdue_Amount') }}</a-tag>
          </div>
          <a-descriptions :column="1" size="small" bordered class="dj-desc">
            <a-descriptions-item :label="$t('Rental_Item')"><router-link :to="`/rental/items/${job.rental_item_id}`">{{ job.item_name }} ({{ job.item_code }})</router-link></a-descriptions-item>
            <a-descriptions-item v-if="job.contract" :label="$t('Rental_Contract')"><router-link :to="`/rental/contracts/${job.contract.id}`">{{ job.contract.contract_number }}</router-link></a-descriptions-item>
            <a-descriptions-item v-if="job.schedule" :label="$t('Rental_Schedule')">{{ job.schedule.title }}</a-descriptions-item>
            <a-descriptions-item :label="$t('Rental_Planned')">{{ job.scheduled_start ? dateTime(job.scheduled_start) : '—' }}<template v-if="job.scheduled_end"> → {{ dateTime(job.scheduled_end) }}</template></a-descriptions-item>
            <a-descriptions-item :label="$t('Rental_Assigned_To')">{{ job.employee_name || job.vendor || '—' }}</a-descriptions-item>
            <a-descriptions-item v-if="job.started_at" :label="$t('Rental_Started')">{{ dateTime(job.started_at) }}</a-descriptions-item>
            <a-descriptions-item v-if="job.completed_at" :label="$t('Rental_Completed')">{{ dateTime(job.completed_at) }}</a-descriptions-item>
            <a-descriptions-item :label="$t('Rental_Cost')">{{ money(job.cost) }}</a-descriptions-item>
          </a-descriptions>
          <h4 v-if="job.description" class="sub-title">{{ $t('Description') }}</h4>
          <p v-if="job.description" class="prose">{{ job.description }}</p>
          <h4 v-if="job.resolution" class="sub-title">{{ $t('Rental_What_Was_Done') }}</h4>
          <p v-if="job.resolution" class="prose">{{ job.resolution }}</p>
          <p v-if="job.cancel_reason" class="prose muted">{{ job.cancel_reason }}</p>
          <a-image-preview-group v-if="job.photos.length">
            <div class="dj-photos"><a-image v-for="p in job.photos" :key="p.file" :src="p.url" :width="84" :height="84" /></div>
          </a-image-preview-group>
          <div v-if="job.expenses.length" class="dj-exp">
            <DollarOutlined /> {{ $t('Rental_Expense_Recorded') }}: <strong>{{ money(job.expenses.reduce((s, e) => s + e.amount, 0)) }}</strong>
          </div>
        </template>
      </a-spin>
      <template v-if="job" #footer>
        <div class="dj-actions">
          <a-button v-if="!['in_progress', 'completed', 'cancelled'].includes(job.status)" danger type="text" @click="removeJob(job)"><DeleteOutlined /></a-button>
          <span class="grow"></span>
          <a-button v-if="['open', 'scheduled', 'in_progress'].includes(job.status)" @click="cancelJob(job)">{{ $t('Rental_Cancel_Job') }}</a-button>
          <a-button v-if="['open', 'scheduled', 'in_progress'].includes(job.status)" @click="openForm(job)">{{ $t('Edit') }}</a-button>
          <a-button v-if="['open', 'scheduled'].includes(job.status)" @click="startJob(job)">{{ $t('Rental_Start_Job') }}</a-button>
          <a-button v-if="['open', 'scheduled', 'in_progress'].includes(job.status)" type="primary" @click="openComplete(job)">{{ $t('Rental_Complete_Job') }}</a-button>
        </div>
      </template>
    </a-drawer>

    <!-- ================= Job form ================= -->
    <a-modal v-model:open="formOpen" :title="form.id ? $t('Rental_Edit_Job') : $t('Rental_New_Job')" :ok-text="$t('Save')" :cancel-text="$t('Cancel')"
      :confirm-loading="saving" width="640px" @ok="saveForm">
      <a-form layout="vertical" class="mform">
        <a-row :gutter="12">
          <a-col :span="24">
            <a-form-item :label="$t('Rental_Item')" required>
              <a-select v-model:value="form.rental_item_id" show-search option-filter-prop="label" :options="itemOptions" />
            </a-form-item>
          </a-col>
          <a-col :span="24">
            <a-form-item :label="$t('Rental_Job_Title')" required>
              <a-input v-model:value="form.title" :placeholder="$t('Rental_Job_Title_Placeholder')" />
            </a-form-item>
          </a-col>
          <a-col :xs="12" :md="8">
            <a-form-item :label="$t('type')"><a-select v-model:value="form.type" :options="typeOptions" /></a-form-item>
          </a-col>
          <a-col :xs="12" :md="8">
            <a-form-item :label="$t('Rental_Priority')"><a-select v-model:value="form.priority" :options="priorityOptions" /></a-form-item>
          </a-col>
          <a-col v-if="!form.id" :xs="24" :md="8">
            <a-form-item :label="$t('Status')"><a-select v-model:value="form.status" :options="newStatusOptions" /></a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Rental_Planned_Start')" :required="form.status === 'scheduled'">
              <a-date-picker v-model:value="form.scheduled_start" show-time format="YYYY-MM-DD HH:mm" value-format="YYYY-MM-DD[T]HH:mm:ssZ" style="width: 100%" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Rental_Planned_End')" :extra="$t('Rental_Planned_End_Help')">
              <a-date-picker v-model:value="form.scheduled_end" show-time format="YYYY-MM-DD HH:mm" value-format="YYYY-MM-DD[T]HH:mm:ssZ" style="width: 100%" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Rental_Assigned_To')"><a-select v-model:value="form.employee_id" :options="employeeOptions" allow-clear show-search option-filter-prop="label" /></a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Rental_Vendor')"><a-input v-model:value="form.vendor" allow-clear /></a-form-item>
          </a-col>
          <a-col :xs="12" :md="8">
            <a-form-item :label="$t('Rental_Estimated_Cost')"><a-input-number v-model:value="form.cost" :min="0" style="width: 100%" /></a-form-item>
          </a-col>
          <a-col v-if="selectedItem && selectedItem.quantity > 1" :xs="12" :md="8">
            <a-form-item :label="$t('Rental_Units_Blocked')"><a-input-number v-model:value="form.quantity" :min="1" :max="selectedItem.quantity" :precision="0" style="width: 100%" /></a-form-item>
          </a-col>
          <a-col :span="24">
            <a-form-item :label="$t('Description')"><a-textarea v-model:value="form.description" :rows="3" /></a-form-item>
          </a-col>
          <a-col :span="24">
            <div class="photos">
              <div v-for="(p, i) in formPhotos" :key="p.key" class="photo">
                <img :src="p.url" alt="" />
                <button type="button" class="photo-x" @click="formPhotos.splice(i, 1)"><CloseOutlined /></button>
              </div>
              <a-upload v-if="formPhotos.length < 12" :show-upload-list="false" accept="image/*" multiple :before-upload="pickPhoto">
                <button type="button" class="photo-add"><CameraOutlined /><span>{{ $t('Rental_Add_Photos') }}</span></button>
              </a-upload>
            </div>
          </a-col>
        </a-row>
      </a-form>
    </a-modal>

    <!-- ================= Complete ================= -->
    <a-modal v-model:open="completeOpen" :title="$t('Rental_Complete_Job')" :ok-text="$t('Rental_Complete_Job')" :cancel-text="$t('Cancel')"
      :confirm-loading="saving" @ok="doComplete">
      <a-form layout="vertical">
        <a-form-item :label="$t('Rental_What_Was_Done')"><a-textarea v-model:value="done.resolution" :rows="3" /></a-form-item>
        <a-row :gutter="12">
          <a-col :span="12"><a-form-item :label="$t('Rental_Final_Cost')"><a-input-number v-model:value="done.cost" :min="0" style="width: 100%" /></a-form-item></a-col>
          <a-col :span="12"><a-form-item :label="$t('Rental_Completed')"><a-date-picker v-model:value="done.completed_at" show-time format="YYYY-MM-DD HH:mm" value-format="YYYY-MM-DD[T]HH:mm:ssZ" style="width: 100%" /></a-form-item></a-col>
          <a-col :span="12"><a-form-item :label="$t('Rental_Condition_After')"><a-select v-model:value="done.condition" :options="conditionOptions" allow-clear /></a-form-item></a-col>
          <a-col :span="12"><a-form-item :label="$t('Rental_Meter_Reading')"><a-input-number v-model:value="done.meter_reading" :min="0" style="width: 100%" /></a-form-item></a-col>
        </a-row>
        <template v-if="canExpenses && done.cost > 0">
          <a-checkbox v-model:checked="done.add_expense">{{ $t('Rental_Record_As_Expense') }}</a-checkbox>
          <a-row v-if="done.add_expense" :gutter="12" class="mt">
            <a-col :span="12"><a-select v-model:value="done.payment_method_id" :options="methodOptions" style="width: 100%" /></a-col>
            <a-col :span="12"><a-select v-model:value="done.account_id" :options="accountOptions" allow-clear :placeholder="$t('Account')" style="width: 100%" /></a-col>
          </a-row>
        </template>
      </a-form>
    </a-modal>

    <!-- ================= Schedule form ================= -->
    <a-modal v-model:open="schedOpen" :title="sched.id ? $t('Rental_Edit_Schedule') : $t('Rental_New_Schedule')" :ok-text="$t('Save')" :cancel-text="$t('Cancel')"
      :confirm-loading="saving" width="600px" @ok="saveSchedule">
      <a-form layout="vertical">
        <a-row :gutter="12">
          <a-col :span="24"><a-form-item :label="$t('Rental_Item')" required><a-select v-model:value="sched.rental_item_id" show-search option-filter-prop="label" :options="itemOptions" /></a-form-item></a-col>
          <a-col :xs="24" :md="16"><a-form-item :label="$t('Rental_Job_Title')" required><a-input v-model:value="sched.title" :placeholder="$t('Rental_Schedule_Title_Placeholder')" /></a-form-item></a-col>
          <a-col :xs="24" :md="8"><a-form-item :label="$t('type')"><a-select v-model:value="sched.type" :options="typeOptions.filter(o => o.value !== 'repair' || sched.type === 'repair')" /></a-form-item></a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Rental_Every')">
              <a-input-group compact>
                <a-input-number v-model:value="sched.interval_value" :min="1" :precision="0" style="width: 45%" />
                <a-select v-model:value="sched.interval_unit" :options="unitOptions" style="width: 55%" />
              </a-input-group>
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Rental_Every_Meter')" :extra="$t('Rental_Every_Meter_Help')">
              <a-input-number v-model:value="sched.meter_interval" :min="1" style="width: 100%" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="8"><a-form-item :label="$t('Rental_First_Due')"><a-date-picker v-model:value="sched.next_due_date" value-format="YYYY-MM-DD" style="width: 100%" /></a-form-item></a-col>
          <a-col :xs="12" :md="8"><a-form-item :label="$t('Rental_Open_Days_Before')"><a-input-number v-model:value="sched.lead_days" :min="0" :precision="0" style="width: 100%" /></a-form-item></a-col>
          <a-col :xs="12" :md="8"><a-form-item :label="$t('Rental_Blocks_For')"><a-input-number v-model:value="sched.duration_hours" :min="1" :precision="0" style="width: 100%" :addon-after="$t('Rental_Unit_Hours')" /></a-form-item></a-col>
          <a-col :span="24"><a-form-item :label="$t('Rental_Note')"><a-input v-model:value="sched.notes" /></a-form-item></a-col>
          <a-col :span="24"><a-checkbox v-model:checked="sched.active">{{ $t('Rental_Schedule_Active') }}</a-checkbox></a-col>
        </a-row>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * Maintenance — jobs (rental/maintenance) and preventive schedules
 * (rental/maintenance/schedules). Deep links: ?new=1&item=ID opens the form,
 * ?job=ID opens a job.
 */
import { ref, reactive, computed, createVNode, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import { message, Modal } from 'ant-design-vue';
import dayjs from 'dayjs';
import {
  PlusOutlined, EyeOutlined, EditOutlined, DeleteOutlined, PlayCircleOutlined, CheckCircleOutlined,
  ScheduleOutlined, CloseOutlined, CameraOutlined, DollarOutlined, ExclamationCircleOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import { useAuthStore } from '../../stores/auth';
import { uploadForm } from '../../lib/upload';
import http from '../../lib/http';
import { t, i18n } from '../../i18n';
import {
  MAINT_STATUSES, MAINT_PRIORITIES, MAINT_TYPES, RENTAL_CONDITIONS, tr, labelOf, optionOf,
} from './rentalOptions';

const route = useRoute();
const auth = useAuthStore();
const { money, date, dateTime } = useFormat();
const canExpenses = computed(() => auth.can('rnt_expenses'));

const tab = ref(route.query.tab === 'schedules' ? 'schedules' : 'jobs');
const tabOptions = computed(() => [
  { value: 'jobs', label: t('Rental_Jobs', 'Jobs') },
  { value: 'schedules', label: t('Rental_Preventive', 'Preventive schedules') },
]);
const stage = ref(typeof route.query.stage === 'string' && route.query.stage ? route.query.stage : 'active');
const stageTabs = computed(() => [
  { value: 'active', label: t('Rental_To_Do', 'To do') },
  { value: 'requests', label: t('Rental_Customer_Requests', 'From customers') },
  { value: 'open', label: labelOf(MAINT_STATUSES, 'open') },
  { value: 'scheduled', label: labelOf(MAINT_STATUSES, 'scheduled') },
  { value: 'in_progress', label: labelOf(MAINT_STATUSES, 'in_progress') },
  { value: 'overdue', label: t('Rental_Overdue_Amount', 'Overdue') },
  { value: 'completed', label: labelOf(MAINT_STATUSES, 'completed') },
  { value: 'cancelled', label: labelOf(MAINT_STATUSES, 'cancelled') },
  { value: 'all', label: t('All', 'All') },
]);

const crud = useCrudTable('rental/maintenance', {
  rowsKey: 'jobs',
  params: () => ({ stage: stage.value, rental_item_id: route.query.item || '' }),
});
const summary = computed(() => crud.payload.value?.summary || {});
function setStage(v) {
  stage.value = v;
  crud.reload();
}

const columns = computed(() => [
  { title: t('Rental_Job'), key: 'number', dataIndex: 'number', sorter: true, width: 150 },
  { title: t('Rental_Job_Title'), key: 'title' },
  { title: t('Rental_Priority'), key: 'priority', dataIndex: 'priority', sorter: true, width: 110 },
  { title: t('Rental_Planned'), key: 'scheduled_start', dataIndex: 'scheduled_start', sorter: true, width: 190 },
  { title: t('Rental_Assigned_To'), key: 'who', width: 150 },
  { title: t('Rental_Cost'), key: 'cost', dataIndex: 'cost', sorter: true, width: 110, sum: 'money' },
  { title: t('Status'), key: 'status', dataIndex: 'status', sorter: true, width: 120 },
  { title: '', key: 'actions', width: 110 },
]);

function sourceLabel(source) {
  if (source === 'portal') return t('Rental_From_Customer', 'From customer');
  return source === 'return' ? t('Rental_From_Return', 'From a return') : t('Rental_From_Schedule', 'Preventive');
}

// ----- meta -----
const meta = ref({ items: [], employees: [], payment_methods: [], accounts: [] });
const itemOptions = computed(() => meta.value.items.map(i => ({ value: i.id, label: `${i.name} · ${i.code}` })));
const employeeOptions = computed(() => meta.value.employees.map(e => ({ value: e.id, label: e.name })));
const methodOptions = computed(() => meta.value.payment_methods.map(m => ({ value: m.id, label: m.name })));
const accountOptions = computed(() => meta.value.accounts.map(a => ({ value: a.id, label: a.account_name })));
const typeOptions = computed(() => tr(MAINT_TYPES));
const priorityOptions = computed(() => tr(MAINT_PRIORITIES));
const conditionOptions = computed(() => tr(RENTAL_CONDITIONS));
const newStatusOptions = computed(() => tr(MAINT_STATUSES).filter(o => ['open', 'scheduled', 'in_progress'].includes(o.value)));
const unitOptions = computed(() => [
  { value: 'day', label: t('Rental_Unit_Days', 'days') },
  { value: 'week', label: t('Rental_Unit_Weeks', 'weeks') },
  { value: 'month', label: t('Rental_Unit_Months', 'months') },
  { value: 'year', label: t('Rental_Unit_Years', 'years') },
]);

function errorText(e) {
  const errors = e?.data?.errors;
  return (errors && Object.values(errors)[0]?.[0]) || e?.data?.message || t('InvalidData', 'Something went wrong');
}

// ----- drawer -----
const drawerOpen = ref(false);
const job = ref(null);
const jobLoading = ref(false);
const drawerWidth = computed(() => (window.innerWidth < 640 ? '100%' : 520));

async function openJob(id) {
  drawerOpen.value = true;
  jobLoading.value = true;
  try {
    job.value = (await http.get(`rental/maintenance/${id}`))?.job || null;
  } catch (e) {
    message.error(errorText(e));
  } finally {
    jobLoading.value = false;
  }
}

async function refreshAll() {
  await crud.fetchRows();
  if (drawerOpen.value && job.value) await openJob(job.value.id);
}

async function startJob(row) {
  try {
    await http.post(`rental/maintenance/${row.id}/start`);
    message.success(t('Updated_in_successfully', 'Updated successfully'));
    await refreshAll();
  } catch (e) {
    message.error(errorText(e));
  }
}

function cancelJob(row) {
  let reason = '';
  Modal.confirm({
    title: t('Rental_Cancel_Job', 'Cancel job'),
    icon: createVNode(ExclamationCircleOutlined),
    content: createVNode('div', null, [
      createVNode('div', { style: 'margin-bottom: 8px' }, row.title),
      createVNode('textarea', {
        class: 'ant-input',
        rows: 3,
        maxlength: 2000,
        placeholder: t('Rental_Reason', 'Reason'),
        style: 'width: 100%',
        onInput: (e) => { reason = e.target.value; },
      }),
    ]),
    okText: t('Rental_Cancel_Job', 'Cancel job'),
    okType: 'danger',
    cancelText: t('Cancel', 'Cancel'),
    async onOk() {
      try {
        await http.post(`rental/maintenance/${row.id}/cancel`, { reason });
        message.success(t('Updated_in_successfully', 'Updated successfully'));
        await refreshAll();
      } catch (e) {
        message.error(errorText(e));
      }
    },
  });
}

function removeJob(row) {
  Modal.confirm({
    title: t('Delete_Title', 'Are you sure?'),
    icon: createVNode(ExclamationCircleOutlined),
    content: `${row.number} — ${row.title}`,
    okType: 'danger',
    okText: t('Delete_confirmButtonText', 'Yes, delete it!'),
    cancelText: t('Delete_cancelButtonText', 'Cancel'),
    async onOk() {
      try {
        await http.delete(`rental/maintenance/${row.id}`);
        drawerOpen.value = false;
        await crud.fetchRows();
      } catch (e) {
        message.error(errorText(e));
      }
    },
  });
}

// ----- job form -----
const formOpen = ref(false);
const saving = ref(false);
const form = reactive({});
const formPhotos = ref([]);
let photoSeq = 0;
const selectedItem = computed(() => meta.value.items.find(i => i.id === form.rental_item_id));

function openForm(row = null) {
  Object.assign(form, {
    id: row?.id || null,
    rental_item_id: row?.rental_item_id || (route.query.item ? Number(route.query.item) : undefined),
    title: row?.title || '',
    type: row?.type || 'repair',
    priority: row?.priority || 'normal',
    status: row?.status || 'open',
    scheduled_start: row?.scheduled_start ? dayjs(row.scheduled_start).format('YYYY-MM-DD[T]HH:mm:ssZ') : null,
    scheduled_end: row?.scheduled_end ? dayjs(row.scheduled_end).format('YYYY-MM-DD[T]HH:mm:ssZ') : null,
    employee_id: row?.employee_id || undefined,
    vendor: row?.vendor || '',
    cost: row?.cost || null,
    quantity: row?.quantity || 1,
    description: row?.description || '',
  });
  formPhotos.value = (row?.photos || []).map(p => ({ key: p.file, url: p.url, file: p.file }));
  formOpen.value = true;
}

function pickPhoto(file) {
  if (formPhotos.value.length < 12) formPhotos.value.push({ key: `n${photoSeq++}`, url: window.URL.createObjectURL(file), upload: file });
  return false;
}

async function saveForm() {
  if (!form.rental_item_id || !form.title.trim()) {
    message.error(t('Rental_Item_And_Title_Required', 'Choose an item and give the job a title'));
    return;
  }
  const fd = new FormData();
  ['rental_item_id', 'title', 'type', 'priority', 'scheduled_start', 'scheduled_end', 'employee_id', 'vendor', 'cost', 'quantity', 'description']
    .forEach((k) => { if (form[k] !== null && form[k] !== undefined && form[k] !== '') fd.append(k, form[k]); });
  if (!form.id) fd.append('status', form.status);
  fd.append('keep_photos', JSON.stringify(formPhotos.value.filter(p => p.file).map(p => p.file)));
  formPhotos.value.filter(p => p.upload).forEach(p => fd.append('new_photos[]', p.upload));

  saving.value = true;
  try {
    const res = await uploadForm(form.id ? `rental/maintenance/${form.id}/update` : 'rental/maintenance', fd);
    if (res.status >= 200 && res.status < 300) {
      message.success(t('Saved', 'Saved'));
      formOpen.value = false;
      await refreshAll();
    } else {
      message.error(errorText({ data: res.data }));
    }
  } catch (e) {
    message.error(t('Gen_Could_Not_Save', 'Could not save'));
  } finally {
    saving.value = false;
  }
}

// ----- complete -----
const completeOpen = ref(false);
const done = reactive({});
let completeId = null;

function openComplete(row) {
  completeId = row.id;
  Object.assign(done, {
    resolution: '',
    cost: row.cost || null,
    completed_at: dayjs().format('YYYY-MM-DD[T]HH:mm:ssZ'),
    condition: undefined,
    meter_reading: null,
    add_expense: true,
    payment_method_id: meta.value.default_payment_method_id || meta.value.payment_methods[0]?.id,
    account_id: meta.value.default_account_id || undefined,
  });
  completeOpen.value = true;
}

async function doComplete() {
  saving.value = true;
  try {
    await http.post(`rental/maintenance/${completeId}/complete`, { ...done, add_expense: canExpenses.value && done.add_expense && done.cost > 0 });
    message.success(t('Updated_in_successfully', 'Updated successfully'));
    completeOpen.value = false;
    await refreshAll();
  } catch (e) {
    message.error(errorText(e), 6);
  } finally {
    saving.value = false;
  }
}

// ----- schedules -----
const schedules = ref([]);
const schedLoading = ref(false);
const schedOpen = ref(false);
const sched = reactive({});

async function loadSchedules() {
  schedLoading.value = true;
  try {
    schedules.value = (await http.get('rental/maintenance/schedules', { rental_item_id: route.query.item || '' }))?.schedules || [];
  } catch (e) { /* empty */ } finally {
    schedLoading.value = false;
  }
}

function tp(key, fallback, params) {
  const out = i18n.global.t(key, params);
  if (out !== key) return out;
  return Object.entries(params).reduce((s, [k, v]) => s.replace(`{${k}}`, v), fallback);
}

function ruleText(s) {
  const parts = [];
  if (s.interval_value) {
    const unit = unitOptions.value.find(u => u.value === s.interval_unit)?.label || s.interval_unit;
    parts.push(tp('Rental_Every_N', 'Every {n} {unit}', { n: s.interval_value, unit }));
  }
  if (s.meter_interval) parts.push(tp('Rental_Every_N_Meter', 'every {n} {unit}', { n: s.meter_interval, unit: s.meter_unit || '' }));
  return parts.join(' · ');
}

function openSchedule(row = null) {
  Object.assign(sched, {
    id: row?.id || null,
    rental_item_id: row?.rental_item_id || (route.query.item ? Number(route.query.item) : undefined),
    title: row?.title || '',
    type: row?.type || 'service',
    // Editing keeps a meter-only schedule meter-only (no interval, no date).
    interval_value: row ? row.interval_value : 3,
    interval_unit: row?.interval_unit || 'month',
    meter_interval: row?.meter_interval ?? null,
    next_due_date: row ? (row.next_due_date || null) : dayjs().add(1, 'month').format('YYYY-MM-DD'),
    lead_days: row?.lead_days ?? 7,
    duration_hours: row?.duration_hours ?? 24,
    notes: row?.notes || '',
    active: row ? row.active : true,
  });
  schedOpen.value = true;
}

async function saveSchedule() {
  if (!sched.rental_item_id || !sched.title.trim()) {
    message.error(t('Rental_Item_And_Title_Required', 'Choose an item and give it a title'));
    return;
  }
  saving.value = true;
  try {
    const body = { ...sched, interval_unit: sched.interval_value ? sched.interval_unit : null };
    if (sched.id) await http.put(`rental/maintenance/schedules/${sched.id}`, body);
    else await http.post('rental/maintenance/schedules', body);
    message.success(t('Saved', 'Saved'));
    schedOpen.value = false;
    await Promise.all([loadSchedules(), crud.fetchRows()]);
  } catch (e) {
    message.error(errorText(e));
  } finally {
    saving.value = false;
  }
}

function removeSchedule(s) {
  Modal.confirm({
    title: t('Delete_Title', 'Are you sure?'),
    icon: createVNode(ExclamationCircleOutlined),
    content: s.title,
    okType: 'danger',
    okText: t('Delete_confirmButtonText', 'Yes, delete it!'),
    cancelText: t('Delete_cancelButtonText', 'Cancel'),
    async onOk() {
      try {
        await http.delete(`rental/maintenance/schedules/${s.id}`);
        message.success(t('Deleted_Successfully', 'Deleted successfully'));
        await loadSchedules();
      } catch (e) {
        message.error(errorText(e));
      }
    },
  });
}

onMounted(async () => {
  crud.fetchRows();
  loadSchedules();
  try {
    meta.value = await http.get('rental/maintenance/meta');
  } catch (e) { /* selects stay empty */ }
  if (route.query.new) openForm();
  if (route.query.job) openJob(Number(route.query.job));
});
</script>

<style scoped>
.tabs {
  margin-bottom: 14px;
}
.tiles {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
  gap: 12px;
  margin-bottom: 14px;
}
.tile {
  display: flex;
  flex-direction: column;
  padding: 12px 16px;
  border: 1px solid rgba(128, 128, 128, 0.2);
  border-radius: 14px;
}
.tile span {
  font-size: 12px;
  opacity: 0.65;
}
.tile strong {
  font-size: 20px;
}
.tile.bad strong {
  color: #dc2626;
}
.tile.warn strong {
  color: #d97706;
}
.stages {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-bottom: 14px;
}
.stage {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 6px 8px 6px 14px;
  border: 1px solid rgba(128, 128, 128, 0.25);
  border-radius: 999px;
  background: transparent;
  color: inherit;
  font: inherit;
  font-size: 13px;
  cursor: pointer;
}
.stage.active {
  border-color: var(--app-primary, #6d28d9);
  background: rgba(var(--app-primary-rgb, 109, 40, 217), 0.08);
  color: var(--app-primary, #6d28d9);
  font-weight: 500;
}
.stage-count {
  min-width: 24px;
  padding: 0 7px;
  border-radius: 999px;
  font-size: 11.5px;
  font-weight: 600;
  line-height: 20px;
  text-align: center;
  background: rgba(128, 128, 128, 0.15);
}
.num {
  display: inline-flex;
  align-items: center;
  gap: 7px;
  padding: 0;
  border: 0;
  background: none;
  color: inherit;
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
  font-weight: 600;
  cursor: pointer;
}
.num:hover {
  color: var(--app-primary, #6d28d9);
}
.prio {
  width: 8px;
  height: 8px;
  border-radius: 50%;
}
.sub {
  display: block;
  font-size: 11.5px;
  opacity: 0.65;
}
.sub.bad,
.bad {
  color: #dc2626;
  opacity: 1;
}
.warn {
  color: #d97706;
}
.strong {
  display: block;
  font-weight: 500;
}
.muted {
  opacity: 0.55;
}
.mt {
  margin-top: 10px;
}

/* schedules */
.scheds {
  display: flex;
  flex-direction: column;
}
.sched {
  display: flex;
  align-items: center;
  gap: 14px;
  padding: 12px 6px;
  border-bottom: 1px solid rgba(128, 128, 128, 0.12);
}
.sched.off {
  opacity: 0.55;
}
.sched-ic {
  width: 38px;
  height: 38px;
  flex: none;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 11px;
  font-size: 17px;
  color: var(--app-primary, #6d28d9);
  background: rgba(var(--app-primary-rgb, 109, 40, 217), 0.1);
}
.sched-body {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
}
.sched-rule {
  font-size: 12.5px;
  opacity: 0.8;
}
.sched-due {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  min-width: 140px;
}
.sched-due small {
  font-weight: 400;
  opacity: 0.6;
}
.empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  padding: 40px 16px;
  text-align: center;
}
.empty-ic {
  font-size: 32px;
  margin-bottom: 8px;
  color: var(--app-primary, #6d28d9);
}

/* drawer */
.dj-tags {
  display: flex;
  flex-wrap: wrap;
  gap: 4px;
  margin-bottom: 14px;
}
.dj-desc {
  margin-bottom: 14px;
}
.sub-title {
  margin: 14px 0 6px;
  font-size: 13.5px;
  font-weight: 600;
}
.prose {
  margin: 0;
  white-space: pre-line;
  font-size: 13px;
}
.dj-photos {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  margin-top: 14px;
}
.dj-photos :deep(.ant-image-img) {
  object-fit: cover;
  border-radius: 8px;
}
.dj-exp {
  margin-top: 14px;
  padding: 10px 12px;
  border-radius: 10px;
  font-size: 13px;
  background: rgba(22, 163, 74, 0.08);
}
.dj-actions {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 6px;
}
.grow {
  flex: 1;
}

/* form photos */
.photos {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}
.photo,
.photo-add {
  position: relative;
  width: 84px;
  height: 84px;
  border-radius: 10px;
  overflow: hidden;
}
.photo img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}
.photo-x {
  position: absolute;
  top: 4px;
  inset-inline-end: 4px;
  width: 22px;
  height: 22px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border: 0;
  border-radius: 6px;
  color: #fff;
  background: rgba(15, 23, 42, 0.7);
  cursor: pointer;
}
.photo-add {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 4px;
  border: 1.5px dashed rgba(128, 128, 128, 0.4);
  background: none;
  color: inherit;
  font: inherit;
  font-size: 11.5px;
  cursor: pointer;
}
.photo-add :deep(.anticon) {
  font-size: 20px;
  color: var(--app-primary, #6d28d9);
}
</style>
