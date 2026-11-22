<template>
  <div class="page">
    <PageHeader
      :title="$t('Mrp_Work_Centres')"
      :subtitle="$t('Mrp_Work_Centres_Subtitle')"
      :breadcrumb="[$t('Manufacturing'), $t('Mrp_Work_Centres')]"
    >
      <template #actions>
        <a-button type="primary" @click="openForm(null)">
          <template #icon><PlusOutlined /></template>
          {{ $t('Mrp_New_Work_Centre') }}
        </a-button>
      </template>
    </PageHeader>

    <DataTable :crud="crud" :columns="columns">
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'name'">
          <strong>{{ record.name }}</strong>
          <div class="sub">{{ record.code }}</div>
        </template>
        <template v-else-if="column.key === 'rates'">
          {{ money(record.hourly_cost) }}/h {{ $t('Mrp_Labour') }}
          <div class="sub">{{ money(record.overhead_rate) }}/h {{ $t('Mrp_Overhead_Lc') }}</div>
        </template>
        <template v-else-if="column.key === 'efficiency_pct'">
          <a-tag :color="efficiencyColor(record.efficiency_pct)">{{ record.efficiency_pct }}%</a-tag>
        </template>
        <template v-else-if="column.key === 'capacity_per_hour'">
          {{ record.capacity_per_hour ? number(record.capacity_per_hour, 2) + ' /h' : '—' }}
        </template>
        <template v-else-if="column.key === 'open_operations'">
          <a-tag v-if="record.open_operations" color="processing">{{ record.open_operations }}</a-tag>
          <span v-else class="muted">—</span>
        </template>
        <template v-else-if="column.key === 'is_active'">
          <a-tag :color="record.is_active ? 'success' : 'default'">
            {{ record.is_active ? $t('Mrp_Active') : $t('Mrp_Inactive') }}
          </a-tag>
        </template>
        <template v-else-if="column.key === 'actions'">
          <a-space :size="0">
            <a-tooltip :title="$t('Edit')">
              <a-button type="text" size="small" @click="openForm(record)">
                <template #icon><EditOutlined /></template>
              </a-button>
            </a-tooltip>
            <a-tooltip :title="$t('Del')">
              <a-button type="text" size="small" danger @click="crud.remove(record, { label: record.name })">
                <template #icon><DeleteOutlined /></template>
              </a-button>
            </a-tooltip>
          </a-space>
        </template>
      </template>
    </DataTable>

    <a-modal
      :open="formOpen" :title="editing ? $t('Mrp_Edit_Work_Centre') : $t('Mrp_New_Work_Centre')" :width="620"
      :confirm-loading="saving" :ok-text="$t('submit')" :cancel-text="$t('Cancel')"
      @ok="submit" @cancel="formOpen = false"
    >
      <a-form ref="formRef" :model="form" :rules="rules" layout="vertical">
        <a-row :gutter="16">
          <a-col :xs="24" :md="8">
            <a-form-item :label="$t('Mrp_Code') + ' *'" name="code">
              <a-input v-model:value="form.code" :placeholder="$t('Mrp_Eg_Work_Centre_Code')" allow-clear />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="16">
            <a-form-item :label="$t('Name') + ' *'" name="name">
              <a-input v-model:value="form.name" :placeholder="$t('Mrp_Eg_Work_Centre_Name')" allow-clear />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Warehouse')" name="warehouse_id">
              <a-select
                v-model:value="form.warehouse_id" show-search option-filter-prop="label"
                :options="warehouseOptions" allow-clear :placeholder="$t('Mrp_Any_Warehouse')"
              />
            </a-form-item>
          </a-col>
          <a-col :xs="12" :md="12">
            <a-form-item :label="$t('Mrp_Units_Per_Hour')" name="capacity_per_hour" :extra="$t('Mrp_Nominal_Throughput')">
              <a-input-number v-model:value="form.capacity_per_hour" :min="0" style="width: 100%" />
            </a-form-item>
          </a-col>
          <a-col :xs="12" :md="8">
            <a-form-item :label="$t('Mrp_Labour_Per_Hour')" name="hourly_cost">
              <a-input-number v-model:value="form.hourly_cost" :min="0" style="width: 100%" />
            </a-form-item>
          </a-col>
          <a-col :xs="12" :md="8">
            <a-form-item :label="$t('Mrp_Overhead_Per_Hour')" name="overhead_rate">
              <a-input-number v-model:value="form.overhead_rate" :min="0" style="width: 100%" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="8">
            <a-form-item
              :label="$t('Mrp_Efficiency_Pct')" name="efficiency_pct"
              :extra="$t('Mrp_Efficiency_Hint')"
            >
              <a-input-number v-model:value="form.efficiency_pct" :min="1" :max="200" style="width: 100%" />
            </a-form-item>
          </a-col>
          <a-col :span="24">
            <a-form-item :label="$t('Description')">
              <a-textarea v-model:value="form.description" :rows="2" allow-clear />
            </a-form-item>
          </a-col>
          <a-col :span="24">
            <a-form-item style="margin-bottom: 0">
              <a-checkbox v-model:checked="form.is_active">{{ $t('Mrp_Active') }}</a-checkbox>
            </a-form-item>
          </a-col>
        </a-row>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * Work centres.
 *
 * Efficiency below 100% stretches an operation rather than shrinking it: at 80%
 * an hour of standard work takes 75 minutes. The field's hint says so, because
 * the intuitive reading is the wrong way round.
 */
import { ref, computed, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import { PlusOutlined, EditOutlined, DeleteOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import { efficiencyColor } from './mrpOptions';
import http from '../../lib/http';
import { t } from '../../i18n';

const { money, number } = useFormat();

const crud = useCrudTable('mrp/work-centers', { rowsKey: 'work_centers' });

const columns = computed(() => [
  { title: t('Mrp_Work_Centre'), key: 'name', dataIndex: 'name', sorter: true },
  { title: t('Warehouse'), dataIndex: 'warehouse_name', key: 'warehouse_name', width: 160 },
  { title: t('Mrp_Rates'), key: 'rates', width: 180 },
  { title: t('Mrp_Capacity'), key: 'capacity_per_hour', dataIndex: 'capacity_per_hour', width: 110, align: 'right' },
  { title: t('Mrp_Efficiency'), key: 'efficiency_pct', dataIndex: 'efficiency_pct', width: 110, align: 'center' },
  { title: t('Mrp_Queued'), key: 'open_operations', dataIndex: 'open_operations', width: 100, align: 'center' },
  { title: t('Status'), key: 'is_active', dataIndex: 'is_active', width: 110 },
  { title: '', key: 'actions', width: 100, align: 'center' },
]);

const warehouses = ref([]);
const warehouseOptions = computed(() => warehouses.value.map(w => ({ value: w.id, label: w.name })));

const formRef = ref();
const formOpen = ref(false);
const saving = ref(false);
const editing = ref(null);
const form = ref(emptyForm());

function emptyForm() {
  return {
    code: '', name: '', warehouse_id: undefined, capacity_per_hour: 0,
    hourly_cost: 0, overhead_rate: 0, efficiency_pct: 100,
    description: '', is_active: true,
  };
}

const required = () => [{ required: true, message: t('Field_is_required', 'This field is required') }];
const rules = computed(() => ({ code: required(), name: required() }));

function openForm(record) {
  editing.value = record;
  form.value = record ? { ...record } : emptyForm();
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
    if (editing.value) await http.put(`mrp/work-centers/${editing.value.id}`, form.value);
    else await http.post('mrp/work-centers', form.value);
    message.success(t('Created_in_successfully', 'Saved successfully'));
    formOpen.value = false;
    editing.value = null;
    crud.fetchRows();
  } catch (e) {
    message.error(firstError(e) || t('Mrp_Could_Not_Save_Work_Centre'));
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

onMounted(async () => {
  crud.fetchRows();
  try {
    const meta = await http.get('mrp/meta');
    warehouses.value = meta?.warehouses || [];
  } catch (e) { /* the warehouse select stays empty */ }
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
</style>
