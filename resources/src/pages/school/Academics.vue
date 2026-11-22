<template>
  <div class="page">
    <PageHeader
      :title="$t('Sc_Academic_Setup')"
      :subtitle="$t('Sc_Academic_Setup_Hint')"
      :breadcrumb="[$t('Sc_School'), $t('Sc_Academics')]"
    >
      <template #actions>
        <a-button type="primary" @click="openForm(null)">
          <template #icon><PlusOutlined /></template>
          {{ addLabel }}
        </a-button>
      </template>
    </PageHeader>

    <a-card size="small">
      <a-tabs v-model:activeKey="tab" @change="load">
        <a-tab-pane key="years" :tab="$t('Sc_Academic_Years')" />
        <a-tab-pane key="classes" :tab="$t('Sc_Classes')" />
        <a-tab-pane key="sections" :tab="$t('Sc_Sections')" />
        <a-tab-pane key="subjects" :tab="$t('Sc_Subjects')" />
      </a-tabs>

      <div v-if="tab !== 'years'" class="toolbar">
        <a-input-search
          v-model:value="search" :placeholder="$t('Sc_Search_Ellipsis')" allow-clear class="tb-search" @search="load"
        />
        <a-select
          v-if="tab === 'sections' || tab === 'subjects'"
          v-model:value="classFilter" class="tb-item" allow-clear show-search
          option-filter-prop="label" :placeholder="$t('Sc_All_Classes')"
          :options="classOptions" @change="load"
        />
      </div>

      <a-table
        :columns="columns" :data-source="rows" :loading="loading"
        :row-key="r => r.id" :pagination="false" :scroll="{ x: 'max-content' }" size="small"
      >
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'is_current'">
            <a-tag v-if="record.is_current" color="success">{{ $t('Sc_Current') }}</a-tag>
            <a-button v-else size="small" type="link" @click="makeCurrent(record)">{{ $t('Sc_Make_Current') }}</a-button>
          </template>
          <template v-else-if="column.key === 'dates'">
            {{ date(record.start_date) }} → {{ date(record.end_date) }}
          </template>
          <template v-else-if="column.key === 'is_active'">
            <a-tag :color="record.is_active ? 'success' : 'default'">
              {{ record.is_active ? $t('Sc_Active') : $t('Sc_Inactive') }}
            </a-tag>
          </template>
          <template v-else-if="column.key === 'type'">
            <a-tag :color="optionOf(SUBJECT_TYPES, record.type).color">
              {{ labelOf(SUBJECT_TYPES, record.type) }}
            </a-tag>
          </template>
          <template v-else-if="column.key === 'occupancy'">
            <span v-if="record.capacity">
              {{ record.students }} / {{ record.capacity }}
              <a-tag v-if="record.is_full" color="error" class="tag-tight">{{ $t('Sc_Full') }}</a-tag>
            </span>
            <span v-else>{{ record.students }}</span>
          </template>
          <template v-else-if="column.key === 'contains'">
            <a-space :size="4">
              <a-tag>{{ $t('Sc_N_Sections', { n: record.sections }) }}</a-tag>
              <a-tag>{{ $t('Sc_N_Subjects', { n: record.subjects }) }}</a-tag>
              <a-tag color="purple">{{ $t('Sc_N_Students', { n: record.students }) }}</a-tag>
            </a-space>
          </template>
          <template v-else-if="column.key === 'actions'">
            <a-space :size="0">
              <a-tooltip :title="$t('Edit')">
                <a-button type="text" size="small" @click="openForm(record)">
                  <template #icon><EditOutlined /></template>
                </a-button>
              </a-tooltip>
              <a-tooltip :title="$t('Delete')">
                <a-button type="text" size="small" danger @click="remove(record)">
                  <template #icon><DeleteOutlined /></template>
                </a-button>
              </a-tooltip>
            </a-space>
          </template>
        </template>
        <template #emptyText>
          <a-empty :image="simpleEmptyImage" :description="emptyText">
            <a-button type="primary" size="small" @click="openForm(null)">{{ addLabel }}</a-button>
          </a-empty>
        </template>
      </a-table>
    </a-card>

    <!-- One modal, four shapes: the fields differ but the flow does not. -->
    <a-modal
      :open="formOpen" :title="modalTitle" :width="560"
      :confirm-loading="saving" :ok-text="$t('submit')" :cancel-text="$t('Cancel')"
      @ok="submit" @cancel="formOpen = false"
    >
      <a-form ref="formRef" :model="form" :rules="rules" layout="vertical">
        <template v-if="tab === 'years'">
          <a-form-item :label="$t('Name') + ' *'" name="name">
            <a-input v-model:value="form.name" :placeholder="$t('Sc_Year_Name_Placeholder')" allow-clear />
          </a-form-item>
          <a-row :gutter="16">
            <a-col :span="12">
              <a-form-item :label="$t('Sc_Starts') + ' *'" name="start_date">
                <a-date-picker v-model:value="form.start_date" style="width: 100%" value-format="YYYY-MM-DD" />
              </a-form-item>
            </a-col>
            <a-col :span="12">
              <a-form-item :label="$t('Sc_Ends') + ' *'" name="end_date">
                <a-date-picker v-model:value="form.end_date" style="width: 100%" value-format="YYYY-MM-DD" />
              </a-form-item>
            </a-col>
          </a-row>
          <a-form-item style="margin-bottom: 0">
            <a-checkbox v-model:checked="form.is_current">
              {{ $t('Sc_Current_Year_Hint') }}
            </a-checkbox>
          </a-form-item>
        </template>

        <template v-else-if="tab === 'classes'">
          <a-row :gutter="16">
            <a-col :xs="24" :md="14">
              <a-form-item :label="$t('Name') + ' *'" name="name">
                <a-input v-model:value="form.name" :placeholder="$t('Sc_Class_Name_Placeholder')" allow-clear />
              </a-form-item>
            </a-col>
            <a-col :xs="12" :md="10">
              <a-form-item :label="$t('Code')">
                <a-input v-model:value="form.code" allow-clear />
              </a-form-item>
            </a-col>
            <a-col :xs="12" :md="8">
              <a-form-item :label="$t('Sc_Level')" :extra="$t('Sc_Level_Hint')">
                <a-input-number v-model:value="form.level" :min="0" style="width: 100%" />
              </a-form-item>
            </a-col>
          </a-row>
          <a-form-item :label="$t('Description')">
            <a-textarea v-model:value="form.description" :rows="2" />
          </a-form-item>
          <a-form-item style="margin-bottom: 0">
            <a-checkbox v-model:checked="form.is_active">{{ $t('Sc_Active') }}</a-checkbox>
          </a-form-item>
        </template>

        <template v-else-if="tab === 'sections'">
          <a-row :gutter="16">
            <a-col :xs="24" :md="12">
              <a-form-item :label="$t('Sc_Class') + ' *'" name="class_id">
                <a-select
                  v-model:value="form.class_id" show-search option-filter-prop="label"
                  :options="classOptions" :placeholder="$t('Sc_Select_A_Class')"
                />
              </a-form-item>
            </a-col>
            <a-col :xs="12" :md="12">
              <a-form-item :label="$t('Sc_Section_Name') + ' *'" name="name">
                <a-input v-model:value="form.name" :placeholder="$t('Sc_Section_Name_Placeholder')" allow-clear />
              </a-form-item>
            </a-col>
            <a-col :xs="12" :md="8">
              <a-form-item :label="$t('Sc_Capacity')">
                <a-input-number v-model:value="form.capacity" :min="1" style="width: 100%" />
              </a-form-item>
            </a-col>
            <a-col :xs="12" :md="8">
              <a-form-item :label="$t('Sc_Room')">
                <a-input v-model:value="form.room" allow-clear />
              </a-form-item>
            </a-col>
            <a-col :xs="24" :md="8">
              <a-form-item :label="$t('Sc_Form_Teacher')">
                <a-select
                  v-model:value="form.teacher_id" allow-clear show-search
                  option-filter-prop="label" :options="teacherOptions" :placeholder="$t('Sc_None')"
                />
              </a-form-item>
            </a-col>
          </a-row>
          <a-form-item style="margin-bottom: 0">
            <a-checkbox v-model:checked="form.is_active">{{ $t('Sc_Active') }}</a-checkbox>
          </a-form-item>
        </template>

        <template v-else>
          <a-row :gutter="16">
            <a-col :xs="24" :md="14">
              <a-form-item :label="$t('Sc_Subject') + ' *'" name="name">
                <a-input v-model:value="form.name" :placeholder="$t('Sc_Subject_Name_Placeholder')" allow-clear />
              </a-form-item>
            </a-col>
            <a-col :xs="12" :md="10">
              <a-form-item :label="$t('Code')">
                <a-input v-model:value="form.code" allow-clear />
              </a-form-item>
            </a-col>
            <a-col :xs="24" :md="12">
              <a-form-item :label="$t('Sc_Class')">
                <a-select
                  v-model:value="form.class_id" allow-clear show-search option-filter-prop="label"
                  :options="classOptions" :placeholder="$t('Sc_All_Classes')"
                />
              </a-form-item>
            </a-col>
            <a-col :xs="12" :md="12">
              <a-form-item :label="$t('Type') + ' *'" name="type">
                <a-select v-model:value="form.type" :options="SUBJECT_TYPES" />
              </a-form-item>
            </a-col>
            <a-col :xs="12" :md="12">
              <a-form-item :label="$t('Sc_Credit')" :extra="$t('Sc_Credit_Hint')">
                <a-input-number v-model:value="form.credit" :min="0" :step="0.5" style="width: 100%" />
              </a-form-item>
            </a-col>
            <a-col :xs="12" :md="12">
              <a-form-item :label="$t('Sc_Pass_Mark_Percent')" :extra="$t('Sc_Pass_Mark_Hint')">
                <a-input-number v-model:value="form.pass_mark" :min="0" :max="100" style="width: 100%" />
              </a-form-item>
            </a-col>
          </a-row>
          <a-form-item style="margin-bottom: 0">
            <a-checkbox v-model:checked="form.is_active">{{ $t('Sc_Active') }}</a-checkbox>
          </a-form-item>
        </template>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * The academic structure, as one setup screen with four tabs — a school
 * configures years, classes, sections and subjects together at the start of a
 * year, and they share one permission.
 *
 * Deletes refuse rather than cascade wherever students are attached; the
 * backend enforces it and the error explains what to move first.
 */
import { ref, computed, onMounted, createVNode } from 'vue';
import { message, Modal, Empty } from 'ant-design-vue';
import { PlusOutlined, EditOutlined, DeleteOutlined, ExclamationCircleOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import { useFormat } from '../../composables/useFormat';
import { SUBJECT_TYPES, labelOf, optionOf } from './schoolOptions';
import http from '../../lib/http';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();
const { date } = useFormat();
const simpleEmptyImage = Empty.PRESENTED_IMAGE_SIMPLE;

const tab = ref('years');
const rows = ref([]);
const loading = ref(false);
const search = ref('');
const classFilter = ref(undefined);

const meta = ref({});
const classOptions = computed(() => (meta.value.classes || []).map(c => ({ value: c.id, label: c.name })));
const teacherOptions = computed(() => (meta.value.teachers || []).map(x => ({ value: x.id, label: x.name })));

const ENDPOINTS = {
  years: { url: 'school/years', key: 'academic_years', label: 'Sc_New_Year', empty: 'Sc_No_Years_Yet', newTitle: 'Sc_New_Academic_Year', editTitle: 'Sc_Edit_Academic_Year' },
  classes: { url: 'school/classes', key: 'classes', label: 'Sc_New_Class', empty: 'Sc_No_Classes_Yet', newTitle: 'Sc_New_Class', editTitle: 'Sc_Edit_Class' },
  sections: { url: 'school/sections', key: 'sections', label: 'Sc_New_Section', empty: 'Sc_No_Sections_Yet', newTitle: 'Sc_New_Section', editTitle: 'Sc_Edit_Section' },
  subjects: { url: 'school/subjects', key: 'subjects', label: 'Sc_New_Subject', empty: 'Sc_No_Subjects_Yet', newTitle: 'Sc_New_Subject', editTitle: 'Sc_Edit_Subject' },
};

const current = computed(() => ENDPOINTS[tab.value]);
const addLabel = computed(() => t(current.value.label));
const emptyText = computed(() => t(current.value.empty));
const modalTitle = computed(() =>
  t(editing.value ? current.value.editTitle : current.value.newTitle));

const COLUMNS = {
  years: [
    { title: t('Sc_Year'), dataIndex: 'name', key: 'name' },
    { title: t('Sc_Runs'), key: 'dates', width: 240 },
    { title: t('Sc_Students'), dataIndex: 'students', key: 'students', width: 110 },
    { title: '', key: 'is_current', width: 150 },
    { title: '', key: 'actions', width: 90 },
  ],
  classes: [
    { title: t('Sc_Class'), dataIndex: 'name', key: 'name' },
    { title: t('Code'), dataIndex: 'code', key: 'code', width: 110 },
    { title: t('Sc_Level'), dataIndex: 'level', key: 'level', width: 90 },
    { title: t('Sc_Contains'), key: 'contains', width: 300 },
    { title: t('Status'), key: 'is_active', dataIndex: 'is_active', width: 110 },
    { title: '', key: 'actions', width: 90 },
  ],
  sections: [
    { title: t('Sc_Class'), dataIndex: 'class_name', key: 'class_name', width: 150 },
    { title: t('Sc_Section'), dataIndex: 'name', key: 'name', width: 120 },
    { title: t('Sc_Occupancy'), key: 'occupancy', width: 160 },
    { title: t('Sc_Room'), dataIndex: 'room', key: 'room', width: 110 },
    { title: t('Sc_Form_Teacher'), dataIndex: 'teacher_name', key: 'teacher_name' },
    { title: t('Status'), key: 'is_active', dataIndex: 'is_active', width: 110 },
    { title: '', key: 'actions', width: 90 },
  ],
  subjects: [
    { title: t('Sc_Subject'), dataIndex: 'name', key: 'name' },
    { title: t('Code'), dataIndex: 'code', key: 'code', width: 110 },
    { title: t('Sc_Class'), dataIndex: 'class_name', key: 'class_name', width: 150 },
    { title: t('Type'), key: 'type', dataIndex: 'type', width: 120 },
    { title: t('Sc_Credit'), dataIndex: 'credit', key: 'credit', width: 90 },
    { title: t('Sc_Pass_Percent'), dataIndex: 'pass_mark', key: 'pass_mark', width: 90 },
    { title: t('Status'), key: 'is_active', dataIndex: 'is_active', width: 110 },
    { title: '', key: 'actions', width: 90 },
  ],
};

const columns = computed(() => COLUMNS[tab.value]);

async function load() {
  loading.value = true;
  try {
    const data = await http.get(current.value.url, {
      search: search.value,
      class_id: classFilter.value || '',
    });
    rows.value = data?.[current.value.key] || [];
  } catch (e) {
    message.error(t('InvalidData', 'Could not load this list'));
  } finally {
    loading.value = false;
  }
}

async function loadMeta() {
  try {
    meta.value = await http.get('school/meta');
  } catch (e) { /* selects stay empty */ }
}

// ---------------- form ----------------

const formRef = ref();
const formOpen = ref(false);
const saving = ref(false);
const editing = ref(null);
const form = ref({});

function blank() {
  return {
    years: { name: '', start_date: null, end_date: null, is_current: false },
    classes: { name: '', code: '', level: 0, description: '', is_active: true },
    sections: { class_id: classFilter.value, name: '', capacity: null, room: '', teacher_id: undefined, is_active: true },
    subjects: { name: '', code: '', class_id: classFilter.value, type: 'core', credit: 1, pass_mark: 40, is_active: true },
  }[tab.value];
}

const required = () => [{ required: true, message: t('Field_is_required', 'This field is required') }];
const rules = computed(() => {
  const base = { name: required() };
  if (tab.value === 'years') return { ...base, start_date: required(), end_date: required() };
  if (tab.value === 'sections') return { ...base, class_id: required() };
  if (tab.value === 'subjects') return { ...base, type: required() };
  return base;
});

function openForm(record) {
  editing.value = record;
  form.value = record ? { ...blank(), ...record } : blank();
  formOpen.value = true;
  formRef.value?.clearValidate?.();
}

async function submit() {
  try {
    await formRef.value.validate();
  } catch (e) {
    return;
  }

  saving.value = true;
  try {
    if (editing.value) await http.put(`${current.value.url}/${editing.value.id}`, form.value);
    else await http.post(current.value.url, form.value);
    message.success(t('Created_in_successfully', 'Saved successfully'));
    formOpen.value = false;
    editing.value = null;
    load();
    loadMeta();
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData', 'Could not save this record'));
  } finally {
    saving.value = false;
  }
}

/** Flip which year everything defaults to. */
async function makeCurrent(record) {
  try {
    await http.put(`school/years/${record.id}`, { ...record, is_current: true });
    message.success(t('Sc_Name_Is_Now_Current', { name: record.name }));
    load();
    loadMeta();
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData', 'Could not update the year'));
  }
}

function remove(record) {
  Modal.confirm({
    title: t('Sc_Delete_Name_Confirm', { name: record.name }),
    icon: createVNode(ExclamationCircleOutlined),
    content: t('Sc_Delete_Refused_Hint'),
    okText: t('Delete_confirmButtonText', 'Yes, delete it!'),
    okType: 'danger',
    cancelText: t('Delete_cancelButtonText', 'Cancel'),
    async onOk() {
      try {
        await http.delete(`${current.value.url}/${record.id}`);
        message.success(t('Deleted_in_successfully', 'Deleted successfully'));
        load();
        loadMeta();
      } catch (e) {
        message.error(e?.data?.message || t('InvalidData', 'Could not delete this record'));
      }
    },
  });
}

onMounted(() => {
  load();
  loadMeta();
});
</script>

<style scoped>
.toolbar {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  margin-bottom: 12px;
}
.tb-search {
  flex: 1 1 220px;
  max-width: 320px;
}
.tb-item {
  width: 180px;
}
.tag-tight {
  margin-inline-start: 6px;
}
</style>
