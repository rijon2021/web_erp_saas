<template>
  <div class="page">
    <PageHeader :title="$t('Hm_Doctors')" :breadcrumb="[$t('Hm_Hospital'), $t('Hm_Doctors')]">
      <template #actions>
        <a-button type="primary" @click="openForm(null)">
          <template #icon><PlusOutlined /></template>
          {{ $t('Add') }}
        </a-button>
      </template>
    </PageHeader>

    <a-card size="small" style="margin-bottom: 16px">
      <div class="toolbar">
        <a-input-search
          v-model:value="crud.search.value" :placeholder="$t('Hm_Search_Doctor_Placeholder')"
          allow-clear class="tb-search" @search="crud.reload"
        />
        <a-select
          v-model:value="filters.department_id" class="tb-item" allow-clear show-search
          option-filter-prop="label" :placeholder="$t('Hm_All_Departments')"
          :options="departmentOptions" @change="crud.reload"
        />
        <a-select
          v-model:value="filters.active" class="tb-item-sm" allow-clear :placeholder="$t('Hm_All')"
          :options="[{ value: '1', label: $t('Hm_Active') }, { value: '0', label: $t('Hm_Inactive') }]"
          @change="crud.reload"
        />
      </div>
    </a-card>

    <DataTable :crud="crud" :columns="columns" :searchable="false" selectable>
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'name'">
          <div class="cell-d">
            <a-avatar :size="36" :src="record.image_url" class="cell-avatar">
              {{ initials(record.name) }}
            </a-avatar>
            <div class="cell-text">
              <div class="cell-name">{{ record.name }}</div>
              <div class="cell-sub">{{ record.specialty || record.qualification || '—' }}</div>
            </div>
          </div>
        </template>
        <template v-else-if="column.key === 'fee'">{{ money(record.consultation_fee) }}</template>
        <template v-else-if="column.key === 'availability'">
          <a-space :size="3" wrap>
            <a-tag
              v-for="day in WEEK_DAYS" :key="day.value"
              :color="record.availability?.[day.value] ? 'purple' : 'default'"
              class="day-tag"
            >
              {{ day.label }}
            </a-tag>
          </a-space>
        </template>
        <template v-else-if="column.key === 'today'">
          <a-badge
            :count="record.appointments_today" :show-zero="true"
            :number-style="{ backgroundColor: record.appointments_today ? '#6d28d9' : '#bfbfbf' }"
          />
        </template>
        <template v-else-if="column.key === 'is_active'">
          <a-tag :color="record.is_active ? 'success' : 'default'">
            {{ record.is_active ? $t('Hm_Active') : $t('Hm_Inactive') }}
          </a-tag>
        </template>
        <template v-else-if="column.key === 'actions'">
          <a-space :size="0">
            <a-tooltip :title="$t('Hm_Todays_Schedule')">
              <a-button type="text" size="small" @click="openSchedule(record)">
                <template #icon><CalendarOutlined /></template>
              </a-button>
            </a-tooltip>
            <a-tooltip :title="$t('Edit')">
              <a-button type="text" size="small" @click="openForm(record)">
                <template #icon><EditOutlined /></template>
              </a-button>
            </a-tooltip>
            <a-tooltip :title="$t('Delete')">
              <a-button type="text" size="small" danger @click="crud.remove(record, { label: record.name })">
                <template #icon><DeleteOutlined /></template>
              </a-button>
            </a-tooltip>
          </a-space>
        </template>
      </template>
    </DataTable>

    <!-- Doctor form -->
    <a-modal
      :open="formOpen" :title="editing ? $t('Hm_Edit_Doctor') : $t('Hm_New_Doctor')" :width="720"
      :confirm-loading="saving" :ok-text="$t('submit')" :cancel-text="$t('Cancel')"
      @ok="submit" @cancel="formOpen = false"
    >
      <a-form ref="formRef" :model="form" :rules="rules" layout="vertical">
        <a-row :gutter="16">
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Name') + ' *'" name="name">
              <a-input v-model:value="form.name" :placeholder="$t('Hm_Doctor_Name_Placeholder')" allow-clear />
            </a-form-item>
          </a-col>
          <a-col :xs="12" :md="6">
            <a-form-item :label="$t('Code')">
              <a-input v-model:value="form.code" allow-clear />
            </a-form-item>
          </a-col>
          <a-col :xs="12" :md="6">
            <a-form-item :label="$t('Hm_Consultation_Fee')">
              <a-input-number v-model:value="form.consultation_fee" :min="0" style="width: 100%" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="8">
            <a-form-item :label="$t('Hm_Department')">
              <a-select
                v-model:value="form.department_id" allow-clear show-search
                option-filter-prop="label" :options="departmentOptions" :placeholder="$t('Hm_None')"
              />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="8">
            <a-form-item :label="$t('Hm_Specialty')">
              <a-input v-model:value="form.specialty" :placeholder="$t('Hm_Specialty_Placeholder')" allow-clear />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="8">
            <a-form-item :label="$t('Hm_Qualification')">
              <a-input v-model:value="form.qualification" :placeholder="$t('Hm_Qualification_Placeholder')" allow-clear />
            </a-form-item>
          </a-col>
          <a-col :xs="12" :md="8">
            <a-form-item :label="$t('Hm_Licence_No')">
              <a-input v-model:value="form.license_no" allow-clear />
            </a-form-item>
          </a-col>
          <a-col :xs="12" :md="8">
            <a-form-item :label="$t('Phone')">
              <a-input v-model:value="form.phone" allow-clear />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="8">
            <a-form-item :label="$t('Email')" name="email">
              <a-input v-model:value="form.email" allow-clear />
            </a-form-item>
          </a-col>
        </a-row>

        <a-divider orientation="left" class="divider">{{ $t('Hm_Clinic_Hours') }}</a-divider>
        <p class="hint">{{ $t('Hm_Clinic_Hours_Hint') }}</p>
        <div class="hours">
          <div v-for="day in WEEK_DAYS" :key="day.value" class="hours-row">
            <a-checkbox
              :checked="!!availability[day.value]"
              @change="e => toggleDay(day.value, e.target.checked)"
            >
              {{ day.label }}
            </a-checkbox>
            <a-time-picker
              :value="timeValue(day.value, 0)" format="HH:mm" value-format="HH:mm"
              :disabled="!availability[day.value]" :placeholder="$t('From')" class="hours-time"
              @update:value="v => setTime(day.value, 0, v)"
            />
            <a-time-picker
              :value="timeValue(day.value, 1)" format="HH:mm" value-format="HH:mm"
              :disabled="!availability[day.value]" :placeholder="$t('To')" class="hours-time"
              @update:value="v => setTime(day.value, 1, v)"
            />
          </div>
        </div>

        <a-row :gutter="16" style="margin-top: 8px">
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Hm_Photo')">
              <a-space>
                <a-upload :show-upload-list="false" accept="image/*" :before-upload="pickImage">
                  <a-button>
                    <template #icon><UploadOutlined /></template>
                    {{ previewUrl ? $t('Hm_Replace') : $t('Upload') }}
                  </a-button>
                </a-upload>
                <a-avatar v-if="previewUrl" :size="40" :src="previewUrl" />
              </a-space>
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Status')">
              <a-checkbox v-model:checked="form.is_active">{{ $t('Hm_Accepting_Appointments') }}</a-checkbox>
            </a-form-item>
          </a-col>
        </a-row>
      </a-form>
    </a-modal>

    <!-- Schedule drawer -->
    <a-drawer :open="scheduleOpen" :width="460" :title="scheduleDoctor?.name" @close="scheduleOpen = false">
      <a-date-picker v-model:value="scheduleDate" style="width: 100%" value-format="YYYY-MM-DD" @change="loadSchedule" />
      <a-alert
        v-if="schedule && !schedule.working" type="warning" show-icon
        :message="$t('Hm_No_Clinic_Hours_That_Day')" style="margin-top: 12px"
      />
      <a-alert
        v-else-if="schedule?.hours" type="info" show-icon
        :message="`${$t('Hm_Clinic_Hours')} ${schedule.hours[0]} – ${schedule.hours[1]}`" style="margin-top: 12px"
      />
      <a-list :data-source="schedule?.appointments || []" size="small" style="margin-top: 12px">
        <template #renderItem="{ item }">
          <a-list-item>
            <a-list-item-meta :title="item.patient_name" :description="`${clock(item.scheduled_at)} · ${$t('Hm_N_Min', { n: item.duration_minutes })}`" />
            <a-tag :color="optionOf(APPOINTMENT_STATUSES, item.status).color">
              {{ labelOf(APPOINTMENT_STATUSES, item.status) }}
            </a-tag>
          </a-list-item>
        </template>
        <template #empty><a-empty :description="$t('Hm_Nothing_Booked')" /></template>
      </a-list>
    </a-drawer>
  </div>
</template>

<script setup>
/**
 * Practitioner register, with the weekly clinic hours the appointment booker
 * warns against. Availability is edited as a day-by-day grid rather than free
 * JSON — a mistyped schedule silently breaks every booking warning.
 */
import { ref, reactive, computed, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import {
  PlusOutlined, EditOutlined, DeleteOutlined, UploadOutlined, CalendarOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import { uploadForm } from '../../lib/upload';
import { WEEK_DAYS, APPOINTMENT_STATUSES, labelOf, optionOf, localDate } from './hospitalOptions';
import http from '../../lib/http';
import { t } from '../../i18n';

const { money } = useFormat();
const filters = reactive({ department_id: undefined, active: undefined });

const crud = useCrudTable('hospital/doctors', {
  rowsKey: 'doctors',
  sortField: 'name',
  sortType: 'asc',
  params: () => ({ department_id: filters.department_id || '', active: filters.active || '' }),
});

const departments = ref([]);
const departmentOptions = computed(() => departments.value.map(d => ({ value: d.id, label: d.name })));

const columns = computed(() => [
  { title: t('Hm_Doctor'), key: 'name', dataIndex: 'name', sorter: true },
  { title: t('Hm_Department'), dataIndex: 'department_name', key: 'department_name', width: 160 },
  { title: t('Phone'), dataIndex: 'phone', key: 'phone', width: 140 },
  { title: t('Hm_Fee'), key: 'fee', dataIndex: 'consultation_fee', sorter: true, width: 120 },
  { title: t('Hm_Clinic_Days'), key: 'availability', width: 230 },
  { title: t('Hm_Today'), key: 'today', width: 90 },
  { title: t('Status'), key: 'is_active', dataIndex: 'is_active', sorter: true, width: 110 },
  { title: '', key: 'actions', width: 120 },
]);

function initials(name) {
  return String(name || '?').replace(/^Dr\.?\s*/i, '').split(/\s+/).filter(Boolean)
    .slice(0, 2).map(p => p[0].toUpperCase()).join('');
}

// ---------------- form ----------------

const formRef = ref();
const formOpen = ref(false);
const saving = ref(false);
const editing = ref(null);
const imageFile = ref(null);
const previewUrl = ref('');
const availability = reactive({});

const form = ref(empty());

function empty() {
  return {
    name: '', code: '', department_id: undefined, specialty: '', qualification: '',
    license_no: '', phone: '', email: '', consultation_fee: null, is_active: true, notes: '',
  };
}

const rules = computed(() => ({
  name: [{ required: true, message: t('Field_is_required', 'This field is required') }],
  email: [{ type: 'email', message: t('Enter_Valid_Email') }],
}));

function openForm(record) {
  editing.value = record;
  form.value = record ? { ...empty(), ...record, department_id: record.department_id || undefined } : empty();
  previewUrl.value = record?.image_url || '';
  imageFile.value = null;

  Object.keys(availability).forEach(k => delete availability[k]);
  Object.entries(record?.availability || {}).forEach(([day, range]) => {
    availability[day] = [...range];
  });

  formOpen.value = true;
  formRef.value?.clearValidate?.();
}

function toggleDay(day, on) {
  if (on) availability[day] = availability[day] || ['09:00', '17:00'];
  else delete availability[day];
}
function timeValue(day, index) {
  return availability[day] ? availability[day][index] : null;
}
function setTime(day, index, value) {
  if (!availability[day]) return;
  availability[day][index] = value;
}

function pickImage(file) {
  imageFile.value = file;
  previewUrl.value = window.URL.createObjectURL(file);
  return false;
}

async function submit() {
  try {
    await formRef.value.validate();
  } catch (e) {
    return;
  }

  const fd = new FormData();
  Object.entries(form.value).forEach(([key, value]) => {
    if (value === null || value === undefined || value === '') return;
    if (['id', 'image', 'image_url', 'availability', 'department_name', 'appointments_today'].includes(key)) return;
    fd.append(key, value);
  });
  fd.append('is_active', form.value.is_active ? 1 : 0);
  fd.append('availability', JSON.stringify(availability));
  if (imageFile.value) fd.append('image', imageFile.value);

  saving.value = true;
  try {
    const url = editing.value ? `hospital/doctors/${editing.value.id}/update` : 'hospital/doctors';
    const res = await uploadForm(url, fd);
    if (res.status >= 200 && res.status < 300) {
      message.success(t('Created_in_successfully', 'Saved successfully'));
      formOpen.value = false;
      editing.value = null;
      crud.fetchRows();
      return;
    }
    message.error(firstError(res.data) || t('InvalidData', 'Could not save this doctor'));
  } catch (e) {
    message.error(t('InvalidData', 'Could not save this doctor'));
  } finally {
    saving.value = false;
  }
}

function firstError(data) {
  const errors = data?.errors;
  if (errors) {
    const first = Object.values(errors)[0];
    if (Array.isArray(first) && first.length) return first[0];
  }
  return data?.message || '';
}

// ---------------- schedule ----------------

const scheduleOpen = ref(false);
const scheduleDoctor = ref(null);
const scheduleDate = ref(localDate());
const schedule = ref(null);

function clock(iso) {
  if (!iso) return '';
  const d = new Date(iso);
  return `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`;
}

function openSchedule(doctor) {
  scheduleDoctor.value = doctor;
  scheduleDate.value = localDate();
  scheduleOpen.value = true;
  loadSchedule();
}

async function loadSchedule() {
  if (!scheduleDoctor.value) return;
  try {
    schedule.value = await http.get(`hospital/doctors/${scheduleDoctor.value.id}/schedule`, { date: scheduleDate.value });
  } catch (e) {
    schedule.value = null;
  }
}

onMounted(async () => {
  crud.fetchRows();
  try {
    const meta = await http.get('hospital/meta');
    departments.value = meta?.departments || [];
  } catch (e) { /* the filter stays empty */ }
});
</script>

<style scoped>
.toolbar {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
}
.tb-search {
  flex: 1 1 240px;
  min-width: 200px;
}
.tb-item {
  width: 180px;
}
.tb-item-sm {
  width: 120px;
}
.cell-d {
  display: flex;
  align-items: center;
  gap: 10px;
  min-width: 200px;
}
.cell-avatar {
  flex: none;
  background: rgba(13, 148, 136, 0.15);
  color: #0d9488;
  font-size: 13px;
}
.cell-name {
  font-weight: 500;
}
.cell-sub {
  font-size: 11.5px;
  opacity: 0.55;
}
.day-tag {
  margin-inline-end: 0;
  font-size: 10.5px;
  padding-inline: 5px;
}
.divider {
  margin: 8px 0 4px;
}
.hint {
  margin: 0 0 10px;
  font-size: 12px;
  opacity: 0.6;
}
.hours {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
  gap: 8px 16px;
}
.hours-row {
  display: flex;
  align-items: center;
  gap: 8px;
}
.hours-row :deep(.ant-checkbox-wrapper) {
  width: 62px;
  flex: none;
}
.hours-time {
  flex: 1;
  min-width: 0;
}
</style>
