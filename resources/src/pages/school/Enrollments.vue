<template>
  <div class="page">
    <PageHeader :title="$t('Sc_Enrolment')" :subtitle="$t('Sc_Enrolment_Subtitle')" :breadcrumb="[$t('Sc_School'), $t('Sc_Enrolment')]">
      <template #actions>
        <a-button @click="promoteOpen = true">
          <template #icon><RiseOutlined /></template>
          {{ $t('Sc_Promote_Class') }}
        </a-button>
        <a-button type="primary" @click="openForm(null)">
          <template #icon><PlusOutlined /></template>
          {{ $t('Sc_Enrol_Student') }}
        </a-button>
      </template>
    </PageHeader>

    <a-card size="small" style="margin-bottom: 16px">
      <div class="toolbar">
        <a-input-search
          v-model:value="crud.search.value" :placeholder="$t('Sc_Search_Student_Or_Admission')"
          allow-clear class="tb-search" @search="crud.reload"
        />
        <a-select
          v-model:value="filters.academic_year_id" class="tb-item" show-search
          option-filter-prop="label" :options="yearOptions" @change="crud.reload"
        />
        <a-select
          v-model:value="filters.class_id" class="tb-item" allow-clear show-search
          option-filter-prop="label" :placeholder="$t('Sc_All_Classes')"
          :options="classOptions" @change="onClassChange"
        />
        <a-select
          v-model:value="filters.section_id" class="tb-item-sm" allow-clear show-search
          option-filter-prop="label" :placeholder="$t('Sc_Section')"
          :options="sectionOptions" @change="crud.reload"
        />
        <a-select
          v-model:value="filters.status" class="tb-item-sm" allow-clear
          :placeholder="$t('Status')" :options="ENROLLMENT_STATUSES" @change="crud.reload"
        />
      </div>
    </a-card>

    <DataTable :crud="crud" :columns="columns" :searchable="false">
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'student'">
          <button type="button" class="link-cell" @click="$router.push(`/school/students/${record.student_id}`)">
            <div class="cell-name">{{ record.student_name }}</div>
            <div class="cell-adm">{{ record.admission_number }}</div>
          </button>
        </template>
        <template v-else-if="column.key === 'class'">
          <a-tag color="purple">{{ record.class_name }}</a-tag>
          <a-tag v-if="record.section_name">{{ record.section_name }}</a-tag>
        </template>
        <template v-else-if="column.key === 'status'">
          <a-tag :color="optionOf(ENROLLMENT_STATUSES, record.status).color">
            {{ labelOf(ENROLLMENT_STATUSES, record.status) }}
          </a-tag>
        </template>
        <template v-else-if="column.key === 'enrolled_on'">
          {{ record.enrolled_on ? date(record.enrolled_on) : '—' }}
        </template>
        <template v-else-if="column.key === 'actions'">
          <a-space :size="0">
            <a-tooltip :title="$t('Edit')">
              <a-button type="text" size="small" @click="openForm(record)">
                <template #icon><EditOutlined /></template>
              </a-button>
            </a-tooltip>
            <a-tooltip :title="$t('Delete')">
              <a-button type="text" size="small" danger @click="crud.remove(record, { label: record.student_name })">
                <template #icon><DeleteOutlined /></template>
              </a-button>
            </a-tooltip>
          </a-space>
        </template>
      </template>
    </DataTable>

    <!-- Enrol / move -->
    <a-modal
      :open="formOpen" :title="editing ? $t('Sc_Move_Student') : $t('Sc_Enrol_Student')" :width="560"
      :confirm-loading="saving" :ok-text="$t('submit')" :cancel-text="$t('Cancel')"
      @ok="submit" @cancel="formOpen = false"
    >
      <a-form ref="formRef" :model="form" :rules="rules" layout="vertical">
        <a-form-item v-if="!editing" :label="$t('Sc_Student') + ' *'" name="student_id">
          <StudentPicker v-model="form.student_id" />
        </a-form-item>
        <p v-else class="target">{{ editing.student_name }} · {{ editing.admission_number }}</p>

        <a-row :gutter="16">
          <a-col :xs="24" :md="12">
            <a-form-item v-if="!editing" :label="$t('Sc_Academic_Year') + ' *'" name="academic_year_id">
              <a-select v-model:value="form.academic_year_id" :options="yearOptions" show-search option-filter-prop="label" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Sc_Class') + ' *'" name="class_id">
              <a-select
                v-model:value="form.class_id" show-search option-filter-prop="label"
                :options="classOptions" @change="form.section_id = undefined"
              />
            </a-form-item>
          </a-col>
          <a-col :xs="12" :md="12">
            <a-form-item :label="$t('Sc_Section')">
              <a-select
                v-model:value="form.section_id" allow-clear show-search option-filter-prop="label"
                :options="formSectionOptions" :disabled="!form.class_id" :placeholder="$t('Sc_Any')"
              />
            </a-form-item>
          </a-col>
          <a-col :xs="12" :md="12">
            <a-form-item :label="$t('Sc_Roll_Number')">
              <a-input v-model:value="form.roll_number" allow-clear />
            </a-form-item>
          </a-col>
          <a-col v-if="editing" :span="24">
            <a-form-item :label="$t('Status') + ' *'" name="status">
              <a-select v-model:value="form.status" :options="ENROLLMENT_STATUSES" />
            </a-form-item>
          </a-col>
        </a-row>
      </a-form>
    </a-modal>

    <!-- Promotion -->
    <a-modal
      :open="promoteOpen" :title="$t('Sc_Promote_A_Class')" :width="620"
      :confirm-loading="promoting" :ok-text="$t('Sc_Promote')" :cancel-text="$t('Cancel')"
      @ok="submitPromote" @cancel="promoteOpen = false"
    >
      <a-alert
        type="info" show-icon banner
        :message="$t('Sc_Promote_Hint')"
        style="margin-bottom: 16px"
      />
      <a-form layout="vertical">
        <a-row :gutter="16">
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Sc_From_Year')">
              <a-select v-model:value="promote.from_year_id" :options="yearOptions" show-search option-filter-prop="label" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Sc_To_Year')">
              <a-select v-model:value="promote.to_year_id" :options="yearOptions" show-search option-filter-prop="label" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Sc_From_Class')">
              <a-select
                v-model:value="promote.class_id" :options="classOptions" show-search
                option-filter-prop="label" @change="suggestTargetClass"
              />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Sc_Into_Class')" :extra="$t('Sc_Suggested_By_Level')">
              <a-select v-model:value="promote.to_class_id" :options="classOptions" show-search option-filter-prop="label" />
            </a-form-item>
          </a-col>
          <a-col :span="24">
            <a-form-item :label="$t('Sc_Into_Section')">
              <a-select
                v-model:value="promote.to_section_id" allow-clear show-search option-filter-prop="label"
                :options="promoteSectionOptions" :disabled="!promote.to_class_id" :placeholder="$t('Sc_Any')"
              />
            </a-form-item>
          </a-col>
        </a-row>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * Enrolment: who is in which class this year, and the end-of-year promotion.
 *
 * The promotion dialog suggests the next class by LEVEL rather than making
 * anyone remember the order, and states plainly that it is re-runnable — the
 * fear of double-promoting is why schools do this by hand.
 */
import { ref, reactive, computed, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import { PlusOutlined, EditOutlined, DeleteOutlined, RiseOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import StudentPicker from './StudentPicker.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import { ENROLLMENT_STATUSES, labelOf, optionOf } from './schoolOptions';
import http from '../../lib/http';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();
const { date } = useFormat();

const filters = reactive({
  academic_year_id: undefined, class_id: undefined, section_id: undefined, status: undefined,
});

const crud = useCrudTable('school/enrollments', {
  rowsKey: 'enrollments',
  params: () => ({
    academic_year_id: filters.academic_year_id || '',
    class_id: filters.class_id || '',
    section_id: filters.section_id || '',
    status: filters.status || '',
  }),
});

const meta = ref({});
const yearOptions = computed(() => (meta.value.academic_years || []).map(y => ({
  value: y.id, label: y.is_current ? t('Sc_Year_Name_Current', { name: y.name }) : y.name,
})));
const classOptions = computed(() => (meta.value.classes || []).map(c => ({ value: c.id, label: c.name })));
const sectionOptions = computed(() => (meta.value.sections || [])
  .filter(s => !filters.class_id || s.class_id === filters.class_id)
  .map(s => ({ value: s.id, label: s.name })));
const formSectionOptions = computed(() => (meta.value.sections || [])
  .filter(s => s.class_id === form.value.class_id)
  .map(s => ({ value: s.id, label: s.name })));
const promoteSectionOptions = computed(() => (meta.value.sections || [])
  .filter(s => s.class_id === promote.to_class_id)
  .map(s => ({ value: s.id, label: s.name })));

function onClassChange() {
  filters.section_id = undefined;
  crud.reload();
}

const columns = computed(() => [
  { title: t('Sc_Student'), key: 'student', dataIndex: 'student_name' },
  { title: t('Sc_Year'), dataIndex: 'year_name', key: 'year_name', width: 140 },
  { title: t('Sc_Class'), key: 'class', width: 180 },
  { title: t('Sc_Roll'), dataIndex: 'roll_number', key: 'roll_number', width: 90 },
  { title: t('Sc_Enrolled'), key: 'enrolled_on', dataIndex: 'enrolled_on', width: 130 },
  { title: t('Status'), key: 'status', dataIndex: 'status', width: 130 },
  { title: '', key: 'actions', width: 90 },
]);

// ---------------- enrol / move ----------------

const formRef = ref();
const formOpen = ref(false);
const saving = ref(false);
const editing = ref(null);
const form = ref(empty());

function empty() {
  return {
    student_id: undefined,
    academic_year_id: meta.value.current_year_id,
    class_id: undefined, section_id: undefined, roll_number: '', status: 'active',
  };
}

const required = () => [{ required: true, message: t('Field_is_required', 'This field is required') }];
const rules = computed(() => ({
  student_id: editing.value ? [] : required(),
  academic_year_id: editing.value ? [] : required(),
  class_id: required(),
  status: editing.value ? required() : [],
}));

function openForm(record) {
  editing.value = record;
  form.value = record
    ? {
        student_id: record.student_id,
        academic_year_id: record.academic_year_id,
        class_id: record.class_id,
        section_id: record.section_id || undefined,
        roll_number: record.roll_number || '',
        status: record.status,
      }
    : { ...empty(), academic_year_id: filters.academic_year_id || meta.value.current_year_id };
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
    if (editing.value) await http.put(`school/enrollments/${editing.value.id}`, form.value);
    else await http.post('school/enrollments', form.value);
    message.success(t('Created_in_successfully', 'Saved successfully'));
    formOpen.value = false;
    editing.value = null;
    crud.fetchRows();
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData', 'Could not save this enrolment'));
  } finally {
    saving.value = false;
  }
}

// ---------------- promotion ----------------

const promoteOpen = ref(false);
const promoting = ref(false);
const promote = reactive({
  from_year_id: undefined, to_year_id: undefined,
  class_id: undefined, to_class_id: undefined, to_section_id: undefined,
});

/** Next class by level — the order schools actually promote in. */
function suggestTargetClass() {
  const classes = meta.value.classes || [];
  const from = classes.find(c => c.id === promote.class_id);
  if (!from) return;
  const next = classes
    .filter(c => Number(c.level) > Number(from.level))
    .sort((a, b) => a.level - b.level)[0];
  promote.to_class_id = next ? next.id : undefined;
  promote.to_section_id = undefined;
}

async function submitPromote() {
  if (!promote.from_year_id || !promote.to_year_id || !promote.class_id || !promote.to_class_id) {
    message.warning(t('Sc_Choose_Years_And_Classes'));
    return;
  }

  promoting.value = true;
  try {
    const data = await http.post('school/enrollments/promote', { ...promote });
    message.success(t('Sc_Promote_Result', { promoted: data.promoted, repeated: data.repeated, skipped: data.skipped }));
    promoteOpen.value = false;
    crud.fetchRows();
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData', 'Could not promote this class'));
  } finally {
    promoting.value = false;
  }
}

onMounted(async () => {
  try {
    meta.value = await http.get('school/meta');
    filters.academic_year_id = meta.value.current_year_id;
    promote.from_year_id = meta.value.current_year_id;
  } catch (e) { /* selects stay empty */ }
  crud.fetchRows();
});
</script>

<style scoped>
.toolbar {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
}
.tb-search {
  flex: 1 1 220px;
  min-width: 180px;
}
.tb-item {
  width: 170px;
}
.tb-item-sm {
  width: 130px;
}
.link-cell {
  border: 0;
  background: none;
  padding: 0;
  cursor: pointer;
  color: inherit;
  font: inherit;
  text-align: left;
}
.cell-name {
  font-weight: 500;
}
.link-cell:hover .cell-name {
  color: #6d28d9;
}
.cell-adm {
  font-size: 11.5px;
  opacity: 0.55;
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
}
.target {
  margin: 0 0 14px;
  padding: 10px 12px;
  border-radius: 10px;
  background: rgba(128, 128, 128, 0.1);
  font-size: 13px;
}
</style>
