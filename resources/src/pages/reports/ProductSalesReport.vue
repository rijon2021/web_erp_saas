<template>
  <ReportPage
    :title="$t('product_sales_report')"
    :breadcrumb="[$t('Reports'), $t('product_sales_report')]"
    :crud="crud"
    :columns="columns"
    row-key="id"
    inline-filters
    export-endpoint="report/product_sales_report"
    :export-params="filterParams"
    export-rows-key="sales"
    product-data
  >
    <template #actions>
      <!-- Multi-Currency: view amounts converted (display-only) -->
      <ViewCurrencySelect />
    </template>
    <template #filters>
      <DateRangePicker v-model:value="range" :allow-clear="false" @change="crud.reload()" />
      <a-select
        v-model:value="warehouseId" style="width: 180px" allow-clear show-search option-filter-prop="label"
        :placeholder="$t('warehouse')" :options="opts('warehouses')" @change="crud.reload()" />
      <a-select
        v-model:value="clientId" style="width: 180px" allow-clear show-search option-filter-prop="label"
        :placeholder="$t('Customer')" :options="opts('customers')" @change="crud.reload()" />
      <!-- Returns as negative lines, so the rows sum to net sales. -->
      <a-checkbox v-model:checked="includeReturns" @change="crud.reload()">{{ $t('Psr_Include_Returns') }}</a-checkbox>
    </template>

    <template #bodyCell="{ column, record }">
      <template v-if="column.key === 'date'">{{ date(record.date) }}</template>
      <template v-else-if="column.key === 'Ref'">
        {{ record.Ref }}
        <a-tag v-if="record.kind === 'return'" color="red" style="margin-left: 4px">{{ $t('Psr_Return') }}</a-tag>
      </template>
      <template v-else-if="column.key === 'unit_price'">{{ money(record.unit_price) }}</template>
      <template v-else-if="column.key === 'discount'">{{ money(record.discount) }}</template>
      <template v-else-if="column.key === 'total'">{{ money(record.total) }}</template>
    </template>
  </ReportPage>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import dayjs from 'dayjs';
import { useI18n } from 'vue-i18n';
import ReportPage from '../../components/ReportPage.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import DateRangePicker from '../../components/DateRangePicker.vue';
import ViewCurrencySelect from '../../components/ViewCurrencySelect.vue';

const { t } = useI18n();
const { money, date } = useFormat();

// Default to the last 30 days — the same window the backend applies when no
// dates are sent — so the active range is visible and can be widened.
const range = ref([dayjs().subtract(29, 'day'), dayjs()]);
const warehouseId = ref(undefined);
const clientId = ref(undefined);
const includeReturns = ref(false);

const filterParams = () => ({
  ...(range.value?.[0] ? { from: range.value[0].format('YYYY-MM-DD') } : {}),
  ...(range.value?.[1] ? { to: range.value[1].format('YYYY-MM-DD') } : {}),
  ...(warehouseId.value ? { warehouse_id: warehouseId.value } : {}),
  ...(clientId.value ? { client_id: clientId.value } : {}),
  ...(includeReturns.value ? { include_returns: 1 } : {}),
});

// Payload: { sales, totalRows, customers, warehouses }
const crud = useCrudTable('report/product_sales_report', {
  rowsKey: 'sales',
  sortField: 'date',
  sortType: 'desc',
  params: filterParams,
});

const opts = key =>
  (crud.payload.value?.[key] || []).map(x => ({ value: x.id, label: x.name }));

const columns = computed(() => [
  { title: t('date'), key: 'date', dataIndex: 'date', sorter: true, exportValue: r => date(r.date) },
  { title: t('Reference'), dataIndex: 'Ref', key: 'Ref', sorter: true, exportValue: r => r.Ref },
  { title: t('Psr_Line_Type'), key: 'kind', defaultHidden: true, exportValue: r => (r.kind === 'return' ? t('Psr_Return') : t('Sale')) },
  { title: t('Customer'), dataIndex: 'client_name', key: 'client_name' },
  { title: t('warehouse'), dataIndex: 'warehouse_name', key: 'warehouse_name' },
  { title: t('Code'), dataIndex: 'code', key: 'code', sorter: true },
  { title: t('ProductName'), dataIndex: 'product_name', key: 'product_name', sorter: true },
  { title: t('Category'), dataIndex: 'category_name', key: 'category_name' },
  { title: t('Quantity'), dataIndex: 'quantity', key: 'quantity', align: 'right', sum: true },
  { title: t('Unit_Price'), key: 'unit_price', dataIndex: 'unit_price', align: 'right', exportValue: r => Number(r.unit_price) },
  { title: t('Discount'), key: 'discount', dataIndex: 'discount', align: 'right', sum: 'money', exportValue: r => Number(r.discount) },
  { title: t('Total'), key: 'total', dataIndex: 'total', sorter: true, align: 'right', sum: 'money', exportValue: r => money(r.total) },
]);

onMounted(crud.fetchRows);
</script>
