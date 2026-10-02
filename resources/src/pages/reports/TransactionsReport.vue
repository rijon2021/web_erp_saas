<template>
  <ReportPage
    :title="$t('Report_Transactions')"
    :breadcrumb="[$t('Reports'), $t('Report_Transactions')]"
    :crud="crud"
    :columns="columns"
    row-key="id"
    export-endpoint="report/report_transactions"
    :export-params="filterParams"
    export-rows-key="payments"
  >
    <template #actions>
      <!-- Multi-Currency: view amounts converted (display-only) -->
      <ViewCurrencySelect />
    </template>
    <template #filters>
      <DateRangePicker v-model:value="range" allow-clear @change="crud.reload()" />
      <a-select
        v-model:value="clientId" style="width: 170px" allow-clear show-search option-filter-prop="label"
        :placeholder="$t('Customer')" :options="opts('clients')" @change="crud.reload()" />
      <a-select
        v-model:value="providerId" style="width: 170px" allow-clear show-search option-filter-prop="label"
        :placeholder="$t('Supplier')" :options="opts('suppliers')" @change="crud.reload()" />
      <a-select
        v-model:value="paymentMethodId" style="width: 170px" allow-clear show-search option-filter-prop="label"
        :placeholder="$t('ModePaiement')" :options="opts('payment_methods')" @change="crud.reload()" />
    </template>

    <template #bodyCell="{ column, record }">
      <template v-if="column.key === 'date'">{{ date(record.date) }}</template>
      <template v-else-if="column.key === 'montant'">
        <strong>{{ money(record.montant) }}</strong>
      </template>
      <template v-else-if="column.key === 'payment_method'">
        <a-tag>{{ record.payment_method }}</a-tag>
      </template>
    </template>

    <!-- Summary by payment method (legacy report's bottom card) -->
    <template #after>
      <a-card size="small" style="margin-top: 16px" :title="tf('Summary_by_Payment_Method', 'Summary by Payment Method')">
        <template #extra>
          <a-button size="small" :disabled="!summaryRows.length" :loading="exportingSummary" @click="exportSummaryPdf">
            <template #icon><FilePdfOutlined /></template>
            {{ tf('Summary_PDF', 'Summary PDF') }}
          </a-button>
        </template>
        <a-table
          size="small" :columns="summaryColumns" :data-source="summaryRows" row-key="payment_method"
          :pagination="false" :loading="crud.loading.value" :scroll="{ x: 'max-content' }">
          <template #bodyCell="{ column, record }">
            <template v-if="column.money">{{ money(record[column.dataIndex] || 0) }}</template>
          </template>
          <template v-if="summaryRows.length" #summary>
            <a-table-summary-row>
              <a-table-summary-cell v-for="(col, i) in summaryColumns" :key="col.key" :align="col.align">
                <strong v-if="i === 0">{{ $t('Total') }}</strong>
                <strong v-else>{{ money(summaryTotals[col.dataIndex]) }}</strong>
              </a-table-summary-cell>
            </a-table-summary-row>
          </template>
        </a-table>
      </a-card>
    </template>
  </ReportPage>
</template>

<script setup>
/**
 * Legacy also offers sale_id/purchase_id pickers; not carried over (huge
 * option lists for a rarely-used narrowing — the search box covers it).
 */
import { ref, computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { message } from 'ant-design-vue';
import { FilePdfOutlined } from '@ant-design/icons-vue';
import { t as tf } from '../../i18n';
import { exportPdf } from '../../lib/exporters';
import ReportPage from '../../components/ReportPage.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import DateRangePicker from '../../components/DateRangePicker.vue';
import ViewCurrencySelect from '../../components/ViewCurrencySelect.vue';

const { t } = useI18n();
const { money, date } = useFormat();

const range = ref(null);
const clientId = ref(undefined);
const providerId = ref(undefined);
const paymentMethodId = ref(undefined);

const filterParams = () => ({
  ...(range.value?.[0] ? { from: range.value[0].format('YYYY-MM-DD') } : {}),
  ...(range.value?.[1] ? { to: range.value[1].format('YYYY-MM-DD') } : {}),
  ...(clientId.value ? { client_id: clientId.value } : {}),
  ...(providerId.value ? { provider_id: providerId.value } : {}),
  ...(paymentMethodId.value ? { payment_method_id: paymentMethodId.value } : {}),
});

// Payload: { payments, totalRows, clients, suppliers, sales, purchases,
// payment_methods, payment_summary } — rowsKey required.
const crud = useCrudTable('report/report_transactions', {
  rowsKey: 'payments',
  sortField: 'date',
  sortType: 'desc',
  params: filterParams,
});

const opts = key =>
  (crud.payload.value?.[key] || []).map(x => ({ value: x.id, label: x.name }));

const columns = computed(() => [
  { title: t('date'), key: 'date', dataIndex: 'date', sorter: true, exportValue: r => date(r.date) },
  { title: t('Reference'), dataIndex: 'Ref', key: 'Ref', sorter: true },
  { title: t('Sale'), dataIndex: 'Ref_Sale', key: 'Ref_Sale' },
  { title: t('Customer'), dataIndex: 'client_name', key: 'client_name' },
  { title: t('ModePaiement'), key: 'payment_method', dataIndex: 'payment_method', exportValue: r => r.payment_method },
  { title: t('Account'), dataIndex: 'account_name', key: 'account_name' },
  { title: t('Amount'), key: 'montant', dataIndex: 'montant', sorter: true, align: 'right', sum: 'money', exportValue: r => money(r.montant) },
  { title: t('AddedBy'), dataIndex: 'user_name', key: 'user_name' },
]);

// Per-method totals over the filtered date range (server-computed; not paged).
const summaryRows = computed(() => crud.payload.value?.payment_summary || []);

const summaryColumns = computed(() => [
  { title: t('Payment_Method'), dataIndex: 'payment_method', key: 'payment_method' },
  ...[
    ['sale_total', tf('Total_Sales', 'Total Sales')],
    ['sale_return_total', tf('Sale_Refunds', 'Sale Refunds')],
    ['purchase_total', tf('Total_Purchases', 'Total Purchases')],
    ['purchase_return_total', tf('Purchase_Refunds', 'Purchase Refunds')],
    ['expense_total', tf('Total_Expenses', 'Total Expenses')],
  ].map(([key, title]) => ({
    title, dataIndex: key, key, align: 'right', money: true, exportValue: r => money(r[key] || 0),
  })),
]);

const summaryTotals = computed(() => {
  const out = {};
  for (const col of summaryColumns.value) {
    if (col.money) out[col.dataIndex] = summaryRows.value.reduce((a, r) => a + (Number(r[col.dataIndex]) || 0), 0);
  }
  return out;
});

const exportingSummary = ref(false);
async function exportSummaryPdf() {
  exportingSummary.value = true;
  try {
    const foot = summaryColumns.value.map((c, i) => (i === 0 ? t('Total') : money(summaryTotals.value[c.dataIndex])));
    await exportPdf(tf('Summary_by_Payment_Method', 'Summary by Payment Method'), summaryColumns.value, summaryRows.value, { foot });
  } catch (e) {
    console.error('[summary-export]', e);
    message.error(t('InvalidData'));
  } finally {
    exportingSummary.value = false;
  }
}

onMounted(crud.fetchRows);
</script>
