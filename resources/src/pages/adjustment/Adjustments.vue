<template>
  <div class="page">
    <PageHeader :title="$t('ListAdjustments')" :breadcrumb="[$t('Adjustment'), $t('ListAdjustments')]">
      <template #actions>
        <a-space wrap>
          <a-tooltip :title="$t('Export_Items_Help')">
            <a-button :loading="exporting" @click="exportItems">
              <template #icon><FileExcelOutlined /></template>
              {{ $t('Export_Items') }}
            </a-button>
          </a-tooltip>
          <a-button v-if="auth.can('adjustment_add')" type="primary" @click="$router.push('/adjustments/create')">
            <template #icon><PlusOutlined /></template>
            {{ $t('CreateAdjustment') }}
          </a-button>
        </a-space>
      </template>
    </PageHeader>

    <a-card size="small" style="margin-bottom: 16px">
      <a-row :gutter="[16, 8]">
        <a-col :xs="12" :md="8">
          <div class="filter-label">{{ $t('date') }}</div>
          <a-date-picker v-model:value="filters.date" value-format="YYYY-MM-DD" style="width: 100%" @change="crud.reload()" />
        </a-col>
        <a-col :xs="12" :md="8">
          <div class="filter-label">{{ $t('warehouse') }}</div>
          <a-select
            v-model:value="filters.warehouse_id" style="width: 100%" allow-clear show-search
            option-filter-prop="label" :placeholder="$t('Choose_Warehouse')" :options="warehouseOptions"
            @change="crud.reload()"
          />
        </a-col>
      </a-row>
    </a-card>

    <DataTable :crud="crud" :columns="columns">
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'date'">{{ dateTime(record.date) }}</template>
        <template v-else-if="column.key === 'Ref'">
          <a @click="$router.push(`/adjustments/${record.id}`)">{{ record.Ref }}</a>
          <div v-if="record.notes" class="row-note">{{ record.notes }}</div>
        </template>
        <template v-else-if="column.key === 'stock_change'">
          <span v-if="Number(record.qty_in)" class="qty-in">+{{ Number(record.qty_in) }}</span>
          <span v-if="Number(record.qty_in) && Number(record.qty_out)"> / </span>
          <span v-if="Number(record.qty_out)" class="qty-out">−{{ Number(record.qty_out) }}</span>
        </template>
        <template v-else-if="column.key === 'actions'">
          <a-space>
            <a-tooltip :title="$t('DownloadPdf')">
              <a-button type="text" size="small" @click="downloadPdf(record)">
                <template #icon><FilePdfOutlined /></template>
              </a-button>
            </a-tooltip>
            <a-tooltip :title="$t('AdjustmentDetail')">
              <a-button type="text" size="small" @click="$router.push(`/adjustments/${record.id}`)">
                <template #icon><EyeOutlined style="color: #1677ff" /></template>
              </a-button>
            </a-tooltip>
            <a-tooltip v-if="auth.can('adjustment_edit')" :title="$t('Edit')">
              <a-button type="text" size="small" @click="$router.push(`/adjustments/${record.id}/edit`)">
                <template #icon><EditOutlined style="color: #52c41a" /></template>
              </a-button>
            </a-tooltip>
            <a-tooltip v-if="auth.can('adjustment_delete')" :title="$t('Del')">
              <a-button type="text" size="small" danger @click="crud.remove(record, { label: record.Ref, detail: deleteImpact(record) })">
                <template #icon><DeleteOutlined /></template>
              </a-button>
            </a-tooltip>
          </a-space>
        </template>
      </template>
    </DataTable>
  </div>
</template>

<script setup>
/**
 * GET adjustments → {adjustments, warehouses, totalRows}; filter params Ref
 * (always-sent empty), warehouse_id, date. No bulk delete in legacy. Rows:
 * date, Ref, warehouse_name, items (product count). PDF adjustment_pdf/{id}.
 */
import { ref, computed } from 'vue';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import {
  PlusOutlined, EyeOutlined, EditOutlined, DeleteOutlined, FilePdfOutlined, FileExcelOutlined,
} from '@ant-design/icons-vue';
import { exportDocumentLines } from '../../lib/documentLinesExport';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import { useAuthStore } from '../../stores/auth';
import http from '../../lib/http';

const { t } = useI18n();
const { dateTime } = useFormat();
const auth = useAuthStore();

const filters = ref({ date: null, warehouse_id: undefined });

const listParams = () => ({
  Ref: '',
  warehouse_id: filters.value.warehouse_id || '',
  date: filters.value.date || '',
});

const crud = useCrudTable('adjustments', {
  rowsKey: 'adjustments',
  params: listParams,
});
crud.fetchRows();

/** Every adjustment line under the current filters, one row per product. */
const exporting = ref(false);
async function exportItems() {
  exporting.value = true;
  try {
    const data = await http.get('adjustments', {
      page: 1,
      limit: -1,
      SortField: crud.sortField.value,
      SortType: crud.sortType.value,
      search: crud.search.value,
      ...listParams(),
    });
    await exportDocumentLines('adjustments', data?.adjustments || [], 'adjustments_items');
  } catch (e) {
    message.error(t('InvalidData'));
  } finally {
    exporting.value = false;
  }
}

const warehouseOptions = computed(() =>
  (crud.payload.value?.warehouses || []).map(w => ({ value: w.id, label: w.name }))
);

const columns = computed(() => [
  { title: t('date'), dataIndex: 'date', key: 'date', sorter: true, exportValue: r => dateTime(r.date) },
  { title: t('Reference'), dataIndex: 'Ref', key: 'Ref', sorter: true },
  { title: t('warehouse'), dataIndex: 'warehouse_name', key: 'warehouse_name', sorter: true },
  { title: t('TotalProducts'), dataIndex: 'items', key: 'items', align: 'right' },
  {
    title: t('Adjustment_Stock_Change'), key: 'stock_change', align: 'right',
    exportValue: r => [Number(r.qty_in) ? `+${Number(r.qty_in)}` : '', Number(r.qty_out) ? `-${Number(r.qty_out)}` : ''].filter(Boolean).join(' / '),
  },
  { title: t('Action'), key: 'actions', width: 160, align: 'center' },
]);

/**
 * Deleting a log reverses every quantity on it: what it added comes back off
 * stock and what it removed goes back on. Said up front, because one
 * full-catalogue upload is one log covering every product in the file.
 */
function deleteImpact(record) {
  return t('Adjustment_Delete_Impact', {
    products: Number(record.products) || 0,
    removed: Number(record.qty_in) || 0,
    restored: Number(record.qty_out) || 0,
  });
}

function downloadPdf(record) {
  http.download(`adjustment_pdf/${record.id}`, `Adjustment_${record.Ref}.pdf`)
    .catch(() => message.error(t('InvalidData')));
}
</script>

<style scoped>
.row-note {
  color: rgba(var(--ink-rgb), 0.55);
  font-size: 12px;
}
.qty-in {
  color: #389e0d;
}
.qty-out {
  color: #cf1322;
}
.filter-label {
  margin-bottom: 4px;
  color: rgba(var(--ink-rgb), 0.55);
  font-size: 13px;
}
</style>
