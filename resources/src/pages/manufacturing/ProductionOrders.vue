<template>
  <div class="page">
    <PageHeader
      :title="$t('Mrp_Production_Orders')"
      :subtitle="$t('Mrp_Production_Orders_Subtitle')"
      :breadcrumb="[$t('Manufacturing'), $t('Mrp_Production_Orders')]"
    >
      <template #actions>
        <a-button type="primary" @click="$router.push('/mrp/production-orders/create')">
          <template #icon><PlusOutlined /></template>
          {{ $t('Mrp_New_Order') }}
        </a-button>
      </template>
    </PageHeader>

    <a-card size="small" style="margin-bottom: 16px">
      <div class="toolbar">
        <a-input-search
          v-model:value="crud.search.value" :placeholder="$t('Mrp_Search_Order_Placeholder')"
          allow-clear class="tb-search" @search="crud.reload"
        />
        <a-select
          v-model:value="filters.status" class="tb-item" allow-clear
          :placeholder="$t('Mrp_All_Statuses')" :options="statusOptions" @change="crud.reload"
        />
        <a-select
          v-model:value="filters.priority" class="tb-item" allow-clear
          :placeholder="$t('Mrp_All_Priorities')" :options="PRIORITIES" @change="crud.reload"
        />
        <a-select
          v-model:value="filters.warehouse_id" class="tb-item" allow-clear show-search
          option-filter-prop="label" :placeholder="$t('Cr_All_Warehouses')"
          :options="warehouseOptions" @change="crud.reload"
        />
        <a-range-picker v-model:value="filters.range" class="tb-range" value-format="YYYY-MM-DD" @change="crud.reload" />
        <a-tag v-if="totals.cost" color="purple" class="tb-total">{{ $t('Mrp_Cost') }}: {{ money(totals.cost) }}</a-tag>
      </div>
    </a-card>

    <DataTable :crud="crud" :columns="columns" :searchable="false">
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'reference'">
          <a class="link" @click="$router.push(`/mrp/production-orders/${record.id}`)">{{ record.reference }}</a>
          <div v-if="record.is_late" class="late">{{ $t('Mrp_Past_Due') }}</div>
        </template>
        <template v-else-if="column.key === 'product'">
          {{ record.product_name }}
          <div class="sub">{{ record.product_code }}</div>
        </template>
        <template v-else-if="column.key === 'batch_no'">
          <template v-if="record.batch_no">
            {{ record.batch_no }}
            <div v-if="record.expiry_date" class="sub">{{ $t('Mrp_Exp_Date', { date: record.expiry_date }) }}</div>
          </template>
          <span v-else class="sub">—</span>
        </template>
        <template v-else-if="column.key === 'qty'">
          <div class="qty">
            <strong>{{ number(record.qty_produced, 0) }}</strong>
            <span class="qty-of">/ {{ number(record.qty_planned, 0) }}</span>
          </div>
          <a-progress
            :percent="record.progress_pct" size="small" :show-info="false"
            :status="record.status === 'cancelled' ? 'exception' : undefined"
          />
        </template>
        <template v-else-if="column.key === 'status'">
          <a-space direction="vertical" :size="2">
            <a-tag :color="optionOf(ORDER_STATUSES, record.status).color">
              {{ labelOf(ORDER_STATUSES, record.status) }}
            </a-tag>
            <a-tag v-if="record.materials_issued" class="mini">{{ $t('Mrp_Materials_Issued') }}</a-tag>
          </a-space>
        </template>
        <template v-else-if="column.key === 'priority'">
          <a-tag :color="optionOf(PRIORITIES, record.priority).color">
            {{ labelOf(PRIORITIES, record.priority) }}
          </a-tag>
        </template>
        <template v-else-if="column.key === 'planned_start'">
          {{ record.planned_start ? date(record.planned_start) : '—' }}
          <div v-if="record.planned_end" class="sub">{{ $t('Mrp_To') }} {{ date(record.planned_end) }}</div>
        </template>
        <template v-else-if="column.key === 'total_cost'">
          {{ money(record.total_cost) }}
          <div v-if="record.unit_cost" class="sub">{{ money(record.unit_cost) }} / {{ $t('Mrp_Unit') }}</div>
        </template>
        <template v-else-if="column.key === 'variance'">
          <a-tag v-if="record.cost_variance_pct !== null" :color="varianceColor(record.cost_variance_pct)">
            {{ signedPct(record.cost_variance_pct) }}
          </a-tag>
          <span v-else class="muted">—</span>
        </template>
        <template v-else-if="column.key === 'actions'">
          <a-space :size="0">
            <a-tooltip :title="$t('Mrp_Open')">
              <a-button type="text" size="small" @click="$router.push(`/mrp/production-orders/${record.id}`)">
                <template #icon><EyeOutlined style="color: #6d28d9" /></template>
              </a-button>
            </a-tooltip>
            <a-tooltip v-if="canRelease(record)" :title="$t('Mrp_Release')">
              <a-button type="text" size="small" :loading="busy === record.id" @click="release(record)">
                <template #icon><PlayCircleOutlined style="color: #16a34a" /></template>
              </a-button>
            </a-tooltip>
            <a-tooltip v-if="canEdit(record)" :title="$t('Edit')">
              <a-button type="text" size="small" @click="$router.push(`/mrp/production-orders/${record.id}/edit`)">
                <template #icon><EditOutlined /></template>
              </a-button>
            </a-tooltip>
            <a-tooltip v-if="canCancel(record)" :title="$t('Cancel')">
              <a-button type="text" size="small" danger @click="confirmCancel(record)">
                <template #icon><StopOutlined /></template>
              </a-button>
            </a-tooltip>
          </a-space>
        </template>
      </template>
    </DataTable>

    <!-- shortages, shown when a release is refused -->
    <a-modal
      :open="shortageOpen" :title="$t('Mrp_Not_Enough_Material')" :width="640"
      :cancel-text="$t('Cancel')" :ok-text="$t('Mrp_Release_Anyway')" ok-type="danger"
      :confirm-loading="busy !== null" @ok="releaseAnyway" @cancel="shortageOpen = false"
    >
      <a-alert
        type="warning" show-icon style="margin-bottom: 14px"
        :message="$t('Mrp_Release_Negative_Warning')"
        :description="$t('Mrp_Release_Negative_Detail')"
      />
      <a-table
        size="small" :columns="shortageColumns" :data-source="shortages"
        row-key="product_id" :pagination="false"
      >
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'short_by'">
            <span class="bad">{{ number(record.short_by, 2) }}</span>
          </template>
        </template>
      </a-table>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * The order list.
 *
 * Release is offered inline because it is the action people take most, but a
 * refusal opens the shortage list rather than a bare error — knowing an order
 * cannot start is only half of what the user needs; the other half is which
 * component to chase.
 */
import { ref, reactive, computed, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import { message, Modal } from 'ant-design-vue';
import {
  PlusOutlined, EditOutlined, EyeOutlined, PlayCircleOutlined, StopOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import {
  ORDER_STATUSES, PRIORITIES, labelOf, optionOf,
  canRelease, canCancel, canEdit, varianceColor, signedPct,
} from './mrpOptions';
import http from '../../lib/http';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const route = useRoute();
const { money, number, date } = useFormat();

const filters = reactive({
  status: route.query.status || undefined,
  priority: undefined,
  warehouse_id: undefined,
  range: null,
});

const statusOptions = [
  { value: 'open', get label() { return t('Mrp_Open_Any_Unfinished'); } },
  ...ORDER_STATUSES.map(s => ({ value: s.value, get label() { return s.label; } })),
];

const crud = useCrudTable('mrp/production-orders', {
  rowsKey: 'orders',
  params: () => ({
    status: filters.status || '',
    priority: filters.priority || '',
    warehouse_id: filters.warehouse_id || '',
    from: filters.range?.[0] || '',
    to: filters.range?.[1] || '',
  }),
});

const totals = computed(() => crud.payload.value?.totals || {});

const columns = computed(() => [
  { title: t('Reference'), key: 'reference', dataIndex: 'reference', sorter: true, width: 170 },
  { title: t('Product'), key: 'product', dataIndex: 'product_name', sorter: true },
  { title: t('Batch'), key: 'batch_no', dataIndex: 'batch_no', sorter: true, width: 150 },
  { title: t('Mrp_Progress'), key: 'qty', width: 150 },
  { title: t('Status'), key: 'status', dataIndex: 'status', sorter: true, width: 150 },
  { title: t('Priority'), key: 'priority', dataIndex: 'priority', sorter: true, width: 110 },
  { title: t('Mrp_Scheduled'), key: 'planned_start', dataIndex: 'planned_start', sorter: true, width: 130 },
  { title: t('Mrp_Cost'), key: 'total_cost', dataIndex: 'total_cost', sorter: true, width: 140, align: 'right' },
  { title: t('Mrp_Vs_Plan'), key: 'variance', dataIndex: 'cost_variance_pct', width: 100, align: 'right' },
  { title: '', key: 'actions', width: 150, align: 'center' },
]);

const warehouses = ref([]);
const warehouseOptions = computed(() => warehouses.value.map(w => ({ value: w.id, label: w.name })));

// ---------------- release ----------------

const busy = ref(null);
const shortageOpen = ref(false);
const shortages = ref([]);
const pendingRelease = ref(null);

const shortageColumns = [
  { title: t('Mrp_Component'), dataIndex: 'product_name', key: 'product_name' },
  { title: t('Mrp_Code'), dataIndex: 'product_code', key: 'product_code', width: 130 },
  { title: t('Mrp_Needed'), dataIndex: 'required', key: 'required', width: 100, align: 'right' },
  { title: t('Mrp_On_Hand'), dataIndex: 'available', key: 'available', width: 100, align: 'right' },
  { title: t('Mrp_Short_By'), key: 'short_by', dataIndex: 'short_by', width: 100, align: 'right' },
];

async function release(record, allowShortage = false) {
  busy.value = record.id;
  try {
    await http.post(`mrp/production-orders/${record.id}/release`, { allow_shortage: allowShortage });
    message.success(t('Mrp_Released_Materials_Issued'));
    shortageOpen.value = false;
    crud.fetchRows();
  } catch (e) {
    const list = e?.data?.shortages || [];
    if (list.length) {
      shortages.value = list;
      pendingRelease.value = record;
      shortageOpen.value = true;
    } else {
      message.error(e?.data?.message || t('Mrp_Could_Not_Release_Order'));
    }
  } finally {
    busy.value = null;
  }
}

function releaseAnyway() {
  if (pendingRelease.value) release(pendingRelease.value, true);
}

function confirmCancel(record) {
  Modal.confirm({
    title: t('Mrp_Cancel_Ref_Confirm', { ref: record.reference }),
    content: record.materials_issued
      ? t('Mrp_Cancel_Issued_Text')
      : t('Mrp_Cancel_Clean_Text'),
    okText: t('Mrp_Cancel_Order'),
    okType: 'danger',
    cancelText: t('Mrp_Keep_It'),
    async onOk() {
      try {
        await http.post(`mrp/production-orders/${record.id}/cancel`, { reason: 'Cancelled from the order list' });
        message.success(t('Mrp_Order_Cancelled'));
        crud.fetchRows();
      } catch (e) {
        message.error(e?.data?.message || t('Mrp_Could_Not_Cancel_Order'));
      }
    },
  });
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
.bad {
  color: #dc2626;
  font-weight: 600;
}
.sub {
  font-size: 11.5px;
  opacity: 0.55;
}
.late {
  font-size: 11px;
  color: #dc2626;
}
.link {
  color: #6d28d9;
  cursor: pointer;
  font-weight: 500;
}
.qty {
  display: flex;
  align-items: baseline;
  gap: 4px;
  font-size: 13px;
}
.qty-of {
  opacity: 0.55;
  font-size: 11.5px;
}
.mini {
  font-size: 10.5px;
  line-height: 16px;
}
.toolbar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 10px;
}
.tb-search {
  flex: 1 1 200px;
  min-width: 170px;
}
.tb-item {
  width: 160px;
}
.tb-range {
  width: 230px;
}
.tb-total {
  margin-inline-start: auto;
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
