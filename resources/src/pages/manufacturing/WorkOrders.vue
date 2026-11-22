<template>
  <div class="page">
    <PageHeader
      :title="$t('Mrp_Shop_Floor')"
      :subtitle="$t('Mrp_Shop_Floor_Subtitle')"
      :breadcrumb="[$t('Manufacturing'), $t('Mrp_Shop_Floor')]"
    />

    <a-card size="small" style="margin-bottom: 16px">
      <div class="toolbar">
        <a-input-search
          v-model:value="crud.search.value" :placeholder="$t('Mrp_Search_Wo_Placeholder')"
          allow-clear class="tb-search" @search="crud.reload"
        />
        <a-select
          v-model:value="filters.status" class="tb-item" allow-clear
          :placeholder="$t('Mrp_All_Statuses')" :options="WORK_ORDER_STATUSES" @change="crud.reload"
        />
        <a-select
          v-model:value="filters.work_center_id" class="tb-item" allow-clear show-search
          option-filter-prop="label" :placeholder="$t('Mrp_All_Work_Centres')"
          :options="workCenterOptions" @change="crud.reload"
        />
        <a-checkbox v-model:checked="filters.include_all" class="tb-check" @change="crud.reload">
          {{ $t('Mrp_Include_Unreleased') }}
        </a-checkbox>
      </div>
    </a-card>

    <DataTable :crud="crud" :columns="columns" :searchable="false">
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'operation'">
          <strong>{{ record.name }}</strong>
          <div class="sub">{{ $t('Mrp_Step') }} {{ record.sequence }} · {{ record.work_center_name || $t('Mrp_No_Work_Centre') }}</div>
        </template>
        <template v-else-if="column.key === 'order'">
          <a class="link" @click="$router.push(`/mrp/production-orders/${record.production_order_id}`)">
            {{ record.order_reference }}
          </a>
          <div class="sub">{{ record.product_name }}</div>
        </template>
        <template v-else-if="column.key === 'status'">
          <a-space :size="4">
            <a-tag :color="optionOf(WORK_ORDER_STATUSES, record.status).color">
              {{ labelOf(WORK_ORDER_STATUSES, record.status) }}
            </a-tag>
            <a-tag v-if="record.requires_qc" class="mini">{{ $t('Mrp_Qc') }}</a-tag>
          </a-space>
        </template>
        <template v-else-if="column.key === 'time'">
          <span v-if="record.status === 'completed'">
            {{ number(record.actual_minutes, 0) }} / {{ number(record.planned_minutes, 0) }} min
            <a-tag
              v-if="record.time_variance_pct !== null" class="mini"
              :color="varianceColor(record.time_variance_pct)"
            >{{ signedPct(record.time_variance_pct) }}</a-tag>
          </span>
          <span v-else-if="record.status === 'in_progress'" class="running">
            {{ $t('Mrp_Running_Since') }} {{ shortTime(record.started_at) }}
          </span>
          <span v-else class="muted">{{ number(record.planned_minutes, 0) }} {{ $t('Mrp_Min_Allowed') }}</span>
        </template>
        <template v-else-if="column.key === 'employee_name'">
          {{ record.employee_name || '—' }}
        </template>
        <template v-else-if="column.key === 'actions'">
          <a-button
            v-if="record.status === 'pending'" type="primary" size="small"
            :disabled="!['released', 'in_progress'].includes(record.order_status)"
            @click="start(record)"
          >
            {{ $t('Mrp_Start') }}
          </a-button>
          <a-button
            v-else-if="record.status === 'in_progress'" size="small" @click="openFinish(record)"
          >
            {{ $t('Mrp_Finish') }}
          </a-button>
          <span v-else class="muted">—</span>
        </template>
      </template>
    </DataTable>

    <a-modal
      :open="finishOpen" :title="`${$t('Mrp_Finish')} — ${finishing?.name || ''}`" :width="480"
      :confirm-loading="saving" :ok-text="$t('Mrp_Finish')" :cancel-text="$t('Cancel')"
      @ok="submitFinish" @cancel="finishOpen = false"
    >
      <a-form layout="vertical">
        <a-form-item :label="$t('Mrp_Minutes_Taken')" :extra="$t('Mrp_Minutes_Taken_Hint')">
          <a-input-number v-model:value="finishForm.actual_minutes" :min="0" style="width: 100%" />
        </a-form-item>
        <a-form-item :label="$t('Operator')" :extra="$t('Mrp_Operator_Rate_Hint')">
          <a-select
            v-model:value="finishForm.employee_id" show-search option-filter-prop="label"
            :options="employeeOptions" allow-clear :placeholder="$t('Mrp_Who_Did_The_Work')"
          />
        </a-form-item>
        <a-row :gutter="12">
          <a-col :span="12">
            <a-form-item :label="$t('Mrp_Good')">
              <a-input-number v-model:value="finishForm.qty_completed" :min="0" style="width: 100%" />
            </a-form-item>
          </a-col>
          <a-col :span="12">
            <a-form-item :label="$t('Mrp_Rejected')">
              <a-input-number v-model:value="finishForm.qty_rejected" :min="0" style="width: 100%" />
            </a-form-item>
          </a-col>
        </a-row>
        <a-form-item :label="$t('Note')" style="margin-bottom: 0">
          <a-textarea v-model:value="finishForm.notes" :rows="2" allow-clear />
        </a-form-item>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * The shop-floor queue.
 *
 * Defaults to operations on released orders only — an operator does not want to
 * see steps for orders whose material has not been issued, because they cannot
 * be worked yet.
 */
import { ref, reactive, computed, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import { message } from 'ant-design-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import { WORK_ORDER_STATUSES, labelOf, optionOf, varianceColor, signedPct } from './mrpOptions';
import http from '../../lib/http';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const route = useRoute();
const { number } = useFormat();

const filters = reactive({
  status: route.query.status || undefined,
  work_center_id: undefined,
  include_all: false,
});

const crud = useCrudTable('mrp/work-orders', {
  rowsKey: 'work_orders',
  params: () => ({
    status: filters.status || '',
    work_center_id: filters.work_center_id || '',
    include_all: filters.include_all ? 1 : 0,
  }),
});

const columns = computed(() => [
  { title: t('Mrp_Operation'), key: 'operation', dataIndex: 'name' },
  { title: t('Mrp_Order'), key: 'order', dataIndex: 'order_reference', width: 200 },
  { title: t('Status'), key: 'status', dataIndex: 'status', width: 140 },
  { title: t('Mrp_Time'), key: 'time', width: 230 },
  { title: t('Operator'), key: 'employee_name', dataIndex: 'employee_name', width: 150 },
  { title: '', key: 'actions', width: 110, align: 'center' },
]);

const workCenters = ref([]);
const employees = ref([]);
const workCenterOptions = computed(() => workCenters.value.map(c => ({ value: c.id, label: c.label })));
const employeeOptions = computed(() => employees.value.map(e => ({ value: e.id, label: e.name })));

function shortTime(value) {
  if (!value) return '';
  return String(value).slice(11, 16);
}

async function start(record) {
  try {
    await http.post(`mrp/work-orders/${record.id}/start`);
    message.success(t('Mrp_Step_Started'));
    crud.fetchRows();
  } catch (e) {
    message.error(e?.data?.message || t('Mrp_Could_Not_Start_Step'));
  }
}

const finishOpen = ref(false);
const finishing = ref(null);
const saving = ref(false);
const finishForm = reactive({
  actual_minutes: null, employee_id: undefined,
  qty_completed: null, qty_rejected: null, notes: '',
});

function openFinish(record) {
  finishing.value = record;
  finishForm.actual_minutes = null;
  finishForm.employee_id = record.employee_id || undefined;
  finishForm.qty_completed = record.qty_planned || null;
  finishForm.qty_rejected = null;
  finishForm.notes = '';
  finishOpen.value = true;
}

async function submitFinish() {
  saving.value = true;
  try {
    await http.post(`mrp/work-orders/${finishing.value.id}/finish`, { ...finishForm });
    message.success(t('Mrp_Step_Finished'));
    finishOpen.value = false;
    crud.fetchRows();
  } catch (e) {
    message.error(e?.data?.message || t('Mrp_Could_Not_Finish_Step'));
  } finally {
    saving.value = false;
  }
}

onMounted(async () => {
  crud.fetchRows();
  try {
    const meta = await http.get('mrp/meta');
    workCenters.value = meta?.work_centers || [];
    employees.value = meta?.employees || [];
  } catch (e) { /* the selects stay empty */ }
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
.mini {
  font-size: 10.5px;
  line-height: 16px;
}
.link {
  color: #6d28d9;
  cursor: pointer;
  font-weight: 500;
}
.running {
  color: #d97706;
  font-size: 12.5px;
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
  width: 180px;
}
.tb-check {
  white-space: nowrap;
}
@media (max-width: 767px) {
  .tb-item {
    width: 100%;
  }
}
</style>
