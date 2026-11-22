<template>
  <div class="page">
    <PageHeader
      :title="$t('Assignments')"
      :subtitle="$t('Asset_Assignments_Subtitle')"
      :breadcrumb="[$t('Asset_Management'), $t('Assignments')]"
    >
      <template #actions>
        <a-button type="primary" @click="openForm(null)">
          <template #icon><PlusOutlined /></template>
          {{ $t('Asset_Assign_Asset') }}
        </a-button>
      </template>
    </PageHeader>

    <a-card size="small" style="margin-bottom: 16px">
      <div class="toolbar">
        <a-input-search
          v-model:value="crud.search.value" :placeholder="$t('Asset_Search_Asset_Or_Person')"
          allow-clear class="tb-search" @search="crud.reload"
        />
        <a-select
          v-model:value="filters.asset_id" class="tb-item" allow-clear show-search
          option-filter-prop="label" :placeholder="$t('Asset_All_Assets')"
          :options="assetOptions" @change="crud.reload"
        />
        <a-select
          v-model:value="filters.user_id" class="tb-item" allow-clear show-search
          option-filter-prop="label" :placeholder="$t('Asset_All_People')"
          :options="userOptions" @change="crud.reload"
        />
        <a-select
          v-model:value="filters.status" class="tb-item" allow-clear
          :placeholder="$t('All_Status')" :options="statusFilterOptions" @change="crud.reload"
        />
      </div>
    </a-card>

    <DataTable :crud="crud" :columns="columns" :searchable="false" selectable>
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'asset'">
          <a class="link" @click="$router.push(`/assets/${record.asset_id}`)">
            {{ record.asset_name }}
          </a>
          <div class="sub">{{ record.asset_tag }}</div>
        </template>
        <template v-else-if="column.key === 'user_name'">
          {{ record.user_name || '—' }}
        </template>
        <template v-else-if="column.key === 'assigned_on'">{{ date(record.assigned_on) }}</template>
        <template v-else-if="column.key === 'due_back_on'">
          <span v-if="!record.due_back_on" class="muted">—</span>
          <a-tag v-else :color="record.is_overdue ? 'error' : 'default'">{{ date(record.due_back_on) }}</a-tag>
        </template>
        <template v-else-if="column.key === 'returned_on'">
          {{ record.returned_on ? date(record.returned_on) : '—' }}
        </template>
        <template v-else-if="column.key === 'days_held'">
          {{ $t('Asset_N_Days_Short', { n: record.days_held }) }}
        </template>
        <template v-else-if="column.key === 'status'">
          <a-tag :color="record.is_overdue ? 'error' : optionOf(ASSIGNMENT_STATUSES, record.status).color">
            {{ record.is_overdue ? $t('Overdue') : labelOf(ASSIGNMENT_STATUSES, record.status) }}
          </a-tag>
        </template>
        <template v-else-if="column.key === 'actions'">
          <a-space :size="0">
            <a-tooltip v-if="record.status === 'assigned'" :title="$t('Asset_Check_In')">
              <a-button type="text" size="small" @click="openCheckin(record)">
                <template #icon><RollbackOutlined style="color: #16a34a" /></template>
              </a-button>
            </a-tooltip>
            <a-tooltip :title="$t('Edit')">
              <a-button type="text" size="small" @click="openForm(record)">
                <template #icon><EditOutlined /></template>
              </a-button>
            </a-tooltip>
            <a-tooltip :title="$t('Del')">
              <a-button type="text" size="small" danger @click="crud.remove(record, { label: record.asset_name })">
                <template #icon><DeleteOutlined /></template>
              </a-button>
            </a-tooltip>
          </a-space>
        </template>
      </template>
    </DataTable>

    <!-- assign / edit -->
    <a-modal
      :open="formOpen" :title="editing ? $t('Asset_Edit_Assignment') : $t('Asset_Assign_Asset')" :width="600"
      :confirm-loading="saving" :ok-text="$t('submit')" :cancel-text="$t('Cancel')"
      @ok="submit" @cancel="formOpen = false"
    >
      <a-alert
        v-if="editing" type="info" show-icon banner style="margin-bottom: 12px"
        :message="$t('Asset_Assignment_Edit_Info')"
      />
      <a-form ref="formRef" :model="form" :rules="rules" layout="vertical">
        <a-row :gutter="16">
          <a-col :span="24">
            <a-form-item :label="$t('Asset') + ' *'" name="asset_id">
              <a-select
                v-model:value="form.asset_id" show-search option-filter-prop="label"
                :options="availableAssetOptions" :disabled="!!editing"
                :placeholder="$t('Asset_Select_Asset')"
              />
            </a-form-item>
          </a-col>
          <a-col :span="24">
            <a-form-item :label="$t('Asset_Assign_To') + ' *'" name="user_id">
              <a-select
                v-model:value="form.user_id" show-search option-filter-prop="label"
                :options="userOptions" :disabled="!!editing" :placeholder="$t('Asset_Select_Person')"
              />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Asset_Assigned_On') + ' *'" name="assigned_on">
              <a-date-picker v-model:value="form.assigned_on" style="width: 100%" value-format="YYYY-MM-DD" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Asset_Due_Back')" name="due_back_on" :extra="$t('Asset_Open_Ended_Loan_Hint')">
              <a-date-picker v-model:value="form.due_back_on" style="width: 100%" value-format="YYYY-MM-DD" />
            </a-form-item>
          </a-col>
          <a-col :span="24">
            <a-form-item :label="$t('Asset_Condition_Out')">
              <a-input v-model:value="form.condition_out" :placeholder="$t('Asset_Condition_Out_Placeholder')" allow-clear />
            </a-form-item>
          </a-col>
          <a-col :span="24">
            <a-form-item :label="$t('Note')" style="margin-bottom: 0">
              <a-textarea v-model:value="form.notes" :rows="2" allow-clear />
            </a-form-item>
          </a-col>
        </a-row>
      </a-form>
    </a-modal>

    <!-- check in -->
    <a-modal
      :open="checkinOpen" :title="$t('Asset_Check_Asset_In')" :width="480"
      :confirm-loading="saving" :ok-text="$t('Asset_Check_In')" :cancel-text="$t('Cancel')"
      @ok="submitCheckin" @cancel="checkinOpen = false"
    >
      <p v-if="checkinRecord" class="checkin-lead">
        {{ $t('Asset_Checkin_Lead', {
          asset: checkinRecord.asset_name,
          tag: checkinRecord.asset_tag,
          holder: checkinRecord.user_name || $t('Asset_The_Holder'),
        }) }}
      </p>
      <a-form layout="vertical">
        <a-form-item :label="$t('Asset_Returned_On') + ' *'">
          <a-date-picker v-model:value="checkinForm.returned_on" style="width: 100%" value-format="YYYY-MM-DD" />
        </a-form-item>
        <a-form-item :label="$t('Asset_Condition_In')" style="margin-bottom: 0">
          <a-input v-model:value="checkinForm.condition_in" :placeholder="$t('Asset_Condition_In_Placeholder')" allow-clear />
        </a-form-item>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * The custody register.
 *
 * "Overdue" is a filter over open rows rather than a stored status, so a loan
 * turns red the day it passes its return date without anything having to run.
 * The asset select only offers kit that is actually free — an asset already out
 * cannot be handed to a second person, and the API refuses it too.
 */
import { ref, reactive, computed, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import { message } from 'ant-design-vue';
import { PlusOutlined, EditOutlined, DeleteOutlined, RollbackOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import { ASSIGNMENT_STATUSES, labelOf, optionOf, tr } from './assetOptions';
import http from '../../lib/http';
import { t } from '../../i18n';

const route = useRoute();
const { date } = useFormat();

const filters = reactive({
  asset_id: route.query.asset_id ? Number(route.query.asset_id) : undefined,
  user_id: undefined,
  status: route.query.status || undefined,
});

const statusFilterOptions = computed(() => [
  ...tr(ASSIGNMENT_STATUSES).map(s => ({ value: s.value, label: s.label })),
  { value: 'overdue', label: t('Overdue', 'Overdue') },
]);

const crud = useCrudTable('asset_assignments', {
  rowsKey: 'assignments',
  bulkDeleteEndpoint: 'asset_assignments/delete/by_selection',
  params: () => ({
    asset_id: filters.asset_id || '',
    user_id: filters.user_id || '',
    status: filters.status || '',
  }),
});

const columns = computed(() => [
  { title: t('Asset', 'Asset'), key: 'asset', dataIndex: 'asset_name', sorter: true },
  { title: t('Asset_Holder', 'Holder'), key: 'user_name', dataIndex: 'user_name', sorter: true, width: 170 },
  { title: t('Asset_Assigned', 'Assigned'), key: 'assigned_on', dataIndex: 'assigned_on', sorter: true, width: 120 },
  { title: t('Asset_Due_Back', 'Due back'), key: 'due_back_on', dataIndex: 'due_back_on', sorter: true, width: 130 },
  { title: t('Returned', 'Returned'), key: 'returned_on', dataIndex: 'returned_on', sorter: true, width: 120 },
  { title: t('Asset_Days_Held', 'Days held'), key: 'days_held', dataIndex: 'days_held', width: 80, align: 'right' },
  { title: t('Status', 'Status'), key: 'status', dataIndex: 'status', sorter: true, width: 110 },
  { title: '', key: 'actions', width: 110, align: 'center' },
]);

// ---------------- meta ----------------

const meta = ref({ assets: [], users: [] });
const assetOptions = computed(() => (meta.value.assets || []).map(a => ({ value: a.id, label: a.label })));
/** Free kit only — plus whatever is already selected, so editing still shows it. */
const availableAssetOptions = computed(() => (meta.value.assets || [])
  .filter(a => !a.assigned_to_id || a.id === form.value.asset_id)
  .map(a => ({ value: a.id, label: a.label })));
const userOptions = computed(() => (meta.value.users || []).map(u => ({ value: u.id, label: u.name })));

// ---------------- form ----------------

const formRef = ref();
const formOpen = ref(false);
const saving = ref(false);
const editing = ref(null);
const form = ref(emptyForm());

function emptyForm() {
  return {
    asset_id: undefined,
    user_id: undefined,
    assigned_on: new Date().toISOString().slice(0, 10),
    due_back_on: null,
    condition_out: '',
    notes: '',
  };
}

const required = () => [{ required: true, message: t('Field_is_required', 'This field is required') }];
const rules = computed(() => ({
  asset_id: required(),
  user_id: required(),
  assigned_on: required(),
}));

function openForm(record) {
  editing.value = record;
  form.value = record
    ? {
        asset_id: record.asset_id,
        user_id: record.user_id,
        assigned_on: record.assigned_on,
        due_back_on: record.due_back_on,
        condition_out: record.condition_out || '',
        notes: record.notes || '',
      }
    : { ...emptyForm(), asset_id: filters.asset_id };
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
    if (editing.value) await http.put(`asset_assignments/${editing.value.id}`, form.value);
    else await http.post('asset_assignments', form.value);
    message.success(t('Saved_successfully', 'Saved successfully'));
    formOpen.value = false;
    editing.value = null;
    crud.fetchRows();
    loadMeta();
  } catch (e) {
    message.error(firstError(e) || t('Asset_Could_Not_Save_Assignment', 'Could not save this assignment'));
  } finally {
    saving.value = false;
  }
}

// ---------------- check in ----------------

const checkinOpen = ref(false);
const checkinRecord = ref(null);
const checkinForm = reactive({ returned_on: null, condition_in: '' });

function openCheckin(record) {
  checkinRecord.value = record;
  checkinForm.returned_on = new Date().toISOString().slice(0, 10);
  checkinForm.condition_in = '';
  checkinOpen.value = true;
}

async function submitCheckin() {
  if (!checkinForm.returned_on) {
    message.error(t('Asset_Pick_Return_Date', 'Pick the date it came back'));
    return;
  }
  saving.value = true;
  try {
    await http.post(`asset_assignments/${checkinRecord.value.id}/checkin`, { ...checkinForm });
    message.success(t('Asset_Checked_In', 'Checked in'));
    checkinOpen.value = false;
    crud.fetchRows();
    loadMeta();
  } catch (e) {
    message.error(firstError(e) || t('Asset_Could_Not_Check_In', 'Could not check this asset in'));
  } finally {
    saving.value = false;
  }
}

function firstError(e) {
  const errors = e?.data?.errors;
  if (errors) {
    const first = Object.values(errors)[0];
    if (Array.isArray(first) && first.length) return first[0];
  }
  return e?.data?.message || '';
}

async function loadMeta() {
  try {
    meta.value = await http.get('assets/workspace/meta');
  } catch (e) { /* the selects stay empty */ }
}

onMounted(() => {
  crud.fetchRows();
  loadMeta();
});
</script>

<style scoped>
.muted {
  color: rgba(128, 128, 128, 0.7);
}
.sub {
  font-size: 11.5px;
  opacity: 0.55;
}
.link {
  color: var(--app-primary, #6d28d9);
  cursor: pointer;
}
.toolbar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 10px;
}
.tb-search {
  flex: 1 1 220px;
  min-width: 180px;
}
.tb-item {
  width: 170px;
}
.checkin-lead {
  margin-bottom: 14px;
  opacity: 0.8;
}
@media (max-width: 767px) {
  .tb-item {
    width: 100%;
  }
}
</style>
