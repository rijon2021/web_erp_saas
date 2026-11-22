<template>
  <div class="page">
    <PageHeader
      :title="$t('Maintenance')"
      :subtitle="$t('Asset_Maintenance_Subtitle')"
      :breadcrumb="[$t('Asset_Management'), $t('Maintenance')]"
    >
      <template #actions>
        <a-button type="primary" @click="openForm(null)">
          <template #icon><PlusOutlined /></template>
          {{ $t('Asset_Log_Job') }}
        </a-button>
      </template>
    </PageHeader>

    <a-card size="small" style="margin-bottom: 16px">
      <div class="toolbar">
        <a-input-search
          v-model:value="crud.search.value" :placeholder="$t('Asset_Search_Maintenance')"
          allow-clear class="tb-search" @search="crud.reload"
        />
        <a-select
          v-model:value="filters.asset_id" class="tb-item" allow-clear show-search
          option-filter-prop="label" :placeholder="$t('Asset_All_Assets')"
          :options="assetOptions" @change="crud.reload"
        />
        <a-select
          v-model:value="filters.type" class="tb-item" allow-clear
          :placeholder="$t('All_types')" :options="typeOptions" @change="crud.reload"
        />
        <a-select
          v-model:value="filters.status" class="tb-item" allow-clear
          :placeholder="$t('All_Status')" :options="statusFilterOptions" @change="crud.reload"
        />
        <a-range-picker v-model:value="filters.range" class="tb-range" value-format="YYYY-MM-DD" @change="crud.reload" />
        <a-tag v-if="totalCost" color="purple" class="tb-total">{{ $t('Total') }}: {{ money(totalCost) }}</a-tag>
      </div>
    </a-card>

    <a-alert
      type="info" show-icon banner style="margin-bottom: 12px"
      :message="$t('Asset_Maintenance_Info')"
    />

    <DataTable :crud="crud" :columns="columns" :searchable="false" selectable>
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'asset'">
          <a class="link" @click="$router.push(`/assets/${record.asset_id}`)">{{ record.asset_name }}</a>
          <div class="sub">{{ record.asset_tag }}</div>
        </template>
        <template v-else-if="column.key === 'type'">
          <a-tag :color="optionOf(MAINTENANCE_TYPES, record.type).color">
            {{ labelOf(MAINTENANCE_TYPES, record.type) }}
          </a-tag>
        </template>
        <template v-else-if="column.key === 'scheduled_date'">
          <a-tag :color="record.is_overdue ? 'error' : 'default'">{{ date(record.scheduled_date) }}</a-tag>
        </template>
        <template v-else-if="column.key === 'completed_date'">
          {{ record.completed_date ? date(record.completed_date) : '—' }}
        </template>
        <template v-else-if="column.key === 'next_due_date'">
          {{ record.next_due_date ? date(record.next_due_date) : '—' }}
        </template>
        <template v-else-if="column.key === 'cost'">{{ money(record.cost) }}</template>
        <template v-else-if="column.key === 'status'">
          <a-dropdown :trigger="['click']">
            <a-tag class="clickable" :color="optionOf(MAINTENANCE_STATUSES, record.status).color">
              {{ labelOf(MAINTENANCE_STATUSES, record.status) }}
              <DownOutlined style="font-size: 9px" />
            </a-tag>
            <template #overlay>
              <a-menu @click="({ key }) => setStatus(record, key)">
                <a-menu-item
                  v-for="s in statusOptions" :key="s.value"
                  :disabled="s.value === record.status"
                >
                  {{ s.label }}
                </a-menu-item>
              </a-menu>
            </template>
          </a-dropdown>
        </template>
        <template v-else-if="column.key === 'actions'">
          <a-space :size="0">
            <a-tooltip :title="$t('Edit')">
              <a-button type="text" size="small" @click="openForm(record)">
                <template #icon><EditOutlined /></template>
              </a-button>
            </a-tooltip>
            <a-tooltip :title="$t('Del')">
              <a-button type="text" size="small" danger @click="crud.remove(record, { label: record.title })">
                <template #icon><DeleteOutlined /></template>
              </a-button>
            </a-tooltip>
          </a-space>
        </template>
      </template>
    </DataTable>

    <a-modal
      :open="formOpen" :title="editing ? $t('Rental_Edit_Job') : $t('Asset_Log_Maintenance')" :width="640"
      :confirm-loading="saving" :ok-text="$t('submit')" :cancel-text="$t('Cancel')"
      @ok="submit" @cancel="formOpen = false"
    >
      <a-form ref="formRef" :model="form" :rules="rules" layout="vertical">
        <a-row :gutter="16">
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Asset') + ' *'" name="asset_id">
              <a-select
                v-model:value="form.asset_id" show-search option-filter-prop="label"
                :options="assetOptions" :disabled="!!editing" :placeholder="$t('Asset_Select_Asset')"
              />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Rental_Work') + ' *'" name="title">
              <a-input v-model:value="form.title" :placeholder="$t('Asset_Work_Placeholder')" allow-clear />
            </a-form-item>
          </a-col>
          <a-col :xs="12" :md="8">
            <a-form-item :label="$t('Type') + ' *'" name="type">
              <a-select v-model:value="form.type" :options="typeOptions" />
            </a-form-item>
          </a-col>
          <a-col :xs="12" :md="8">
            <a-form-item :label="$t('Status') + ' *'" name="status">
              <a-select v-model:value="form.status" :options="statusOptions" />
            </a-form-item>
          </a-col>
          <a-col :xs="12" :md="8">
            <a-form-item :label="$t('Scheduled') + ' *'" name="scheduled_date">
              <a-date-picker v-model:value="form.scheduled_date" style="width: 100%" value-format="YYYY-MM-DD" />
            </a-form-item>
          </a-col>
          <a-col :xs="12" :md="8">
            <a-form-item
              :label="$t('Completed')" name="completed_date"
              :extra="form.status === 'completed' ? $t('Asset_Defaults_To_Today') : $t('Asset_Completed_Date_Hint')"
            >
              <a-date-picker
                v-model:value="form.completed_date" style="width: 100%"
                value-format="YYYY-MM-DD" :disabled="form.status !== 'completed'"
              />
            </a-form-item>
          </a-col>
          <a-col :xs="12" :md="8">
            <a-form-item :label="$t('Cost')" name="cost">
              <a-input-number v-model:value="form.cost" :min="0" :step="1" style="width: 100%" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="8">
            <a-form-item :label="$t('Rental_Vendor')">
              <a-input v-model:value="form.vendor" allow-clear />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Rental_Next_Due')" :extra="$t('Asset_Next_Due_Hint')">
              <a-date-picker v-model:value="form.next_due_date" style="width: 100%" value-format="YYYY-MM-DD" />
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
  </div>
</template>

<script setup>
/**
 * The maintenance log for the whole register, with a per-asset filter the
 * asset detail page links into.
 *
 * Status is editable straight from the table because that is the change people
 * make most — a job going in progress or finishing — and each change flows back
 * to the asset's own status server-side.
 */
import { ref, reactive, computed, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import { message } from 'ant-design-vue';
import { PlusOutlined, EditOutlined, DeleteOutlined, DownOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import { MAINTENANCE_TYPES, MAINTENANCE_STATUSES, labelOf, optionOf, tr } from './assetOptions';
import http from '../../lib/http';
import { t } from '../../i18n';

const route = useRoute();
const { money, date } = useFormat();

const filters = reactive({
  asset_id: route.query.asset_id ? Number(route.query.asset_id) : undefined,
  type: undefined,
  status: route.query.status || undefined,
  range: null,
});

const typeOptions = computed(() => tr(MAINTENANCE_TYPES).map(({ value, label }) => ({ value, label })));
const statusOptions = computed(() => tr(MAINTENANCE_STATUSES).map(({ value, label }) => ({ value, label })));
const statusFilterOptions = computed(() => [
  ...statusOptions.value,
  { value: 'overdue', label: t('Overdue', 'Overdue') },
]);

const crud = useCrudTable('asset_maintenances', {
  rowsKey: 'maintenances',
  sortField: 'scheduled_date',
  bulkDeleteEndpoint: 'asset_maintenances/delete/by_selection',
  params: () => ({
    asset_id: filters.asset_id || '',
    type: filters.type || '',
    status: filters.status || '',
    from: filters.range?.[0] || '',
    to: filters.range?.[1] || '',
  }),
});

const totalCost = computed(() => crud.payload.value?.totals?.cost || 0);

const columns = computed(() => [
  { title: t('Scheduled', 'Scheduled'), key: 'scheduled_date', dataIndex: 'scheduled_date', sorter: true, width: 125 },
  { title: t('Asset', 'Asset'), key: 'asset', dataIndex: 'asset_name', sorter: true },
  { title: t('Rental_Work', 'Work'), dataIndex: 'title', key: 'title', sorter: true },
  { title: t('Type', 'Type'), key: 'type', dataIndex: 'type', sorter: true, width: 115 },
  { title: t('Rental_Vendor', 'Vendor'), dataIndex: 'vendor', key: 'vendor', width: 140 },
  { title: t('Completed', 'Completed'), key: 'completed_date', dataIndex: 'completed_date', sorter: true, width: 115 },
  { title: t('Rental_Next_Due', 'Next due'), key: 'next_due_date', dataIndex: 'next_due_date', sorter: true, width: 115 },
  { title: t('Cost', 'Cost'), key: 'cost', dataIndex: 'cost', sorter: true, width: 110, align: 'right' },
  { title: t('Status', 'Status'), key: 'status', dataIndex: 'status', sorter: true, width: 130 },
  { title: '', key: 'actions', width: 90, align: 'center' },
]);

// ---------------- meta ----------------

const meta = ref({ assets: [] });
const assetOptions = computed(() => (meta.value.assets || []).map(a => ({ value: a.id, label: a.label })));

// ---------------- form ----------------

const formRef = ref();
const formOpen = ref(false);
const saving = ref(false);
const editing = ref(null);
const form = ref(emptyForm());

function emptyForm() {
  return {
    asset_id: undefined,
    title: '',
    type: 'service',
    status: 'scheduled',
    scheduled_date: new Date().toISOString().slice(0, 10),
    completed_date: null,
    cost: null,
    vendor: '',
    next_due_date: null,
    notes: '',
  };
}

const required = () => [{ required: true, message: t('Field_is_required', 'This field is required') }];
const rules = computed(() => ({
  asset_id: required(),
  title: required(),
  type: required(),
  status: required(),
  scheduled_date: required(),
}));

function openForm(record) {
  editing.value = record;
  form.value = record
    ? {
        asset_id: record.asset_id,
        title: record.title,
        type: record.type,
        status: record.status,
        scheduled_date: record.scheduled_date,
        completed_date: record.completed_date,
        cost: record.cost,
        vendor: record.vendor || '',
        next_due_date: record.next_due_date,
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
    if (editing.value) await http.put(`asset_maintenances/${editing.value.id}`, form.value);
    else await http.post('asset_maintenances', form.value);
    message.success(t('Saved_successfully', 'Saved successfully'));
    formOpen.value = false;
    editing.value = null;
    crud.fetchRows();
  } catch (e) {
    message.error(firstError(e) || t('Asset_Could_Not_Save_Job', 'Could not save this job'));
  } finally {
    saving.value = false;
  }
}

async function setStatus(record, status) {
  if (status === record.status) return;
  try {
    await http.post(`asset_maintenances/${record.id}/status`, { status });
    crud.fetchRows();
  } catch (e) {
    message.error(firstError(e) || t('Asset_Could_Not_Change_Status', 'Could not change the status'));
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

onMounted(async () => {
  crud.fetchRows();
  try {
    meta.value = await http.get('assets/workspace/meta');
  } catch (e) { /* the selects stay empty */ }
});
</script>

<style scoped>
.sub {
  font-size: 11.5px;
  opacity: 0.55;
}
.link {
  color: var(--app-primary, #6d28d9);
  cursor: pointer;
}
.clickable {
  cursor: pointer;
  user-select: none;
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
  width: 160px;
}
.tb-range {
  width: 240px;
}
.tb-total {
  margin-inline-start: auto;
  margin-inline-end: 0;
  font-size: 13px;
  padding: 3px 10px;
}
@media (max-width: 767px) {
  .tb-item,
  .tb-range {
    width: 100%;
  }
  .tb-total {
    margin-inline-start: 0;
  }
}
</style>
