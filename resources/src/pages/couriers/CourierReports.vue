<template>
  <div class="page">
    <PageHeader :title="$t('Cr_Courier_Reports')" :breadcrumb="[$t('Cr_Couriers'), $t('Cr_Courier_Reports')]" />

    <a-card size="small" style="margin-bottom: 16px">
      <a-space wrap :size="12">
        <DateRangePicker v-model:value="range" :allow-clear="false" @change="load" />
        <a-select
          v-model:value="accountId" style="width: 220px" allow-clear show-search option-filter-prop="label"
          :placeholder="$t('Cr_Courier_Account')" :options="accountOptions" @change="load"
        />
      </a-space>
      <div class="muted hint">{{ $t('Cr_Report_Help') }}</div>
    </a-card>

    <a-spin :spinning="loading">
      <a-row :gutter="[16, 16]" style="margin-bottom: 16px">
        <a-col v-for="k in kpis" :key="k.label" :xs="12" :md="8" :xl="4">
          <a-card size="small" class="fill">
            <div class="kpi-label">{{ k.label }}</div>
            <div class="kpi-value" :style="k.color ? { color: k.color } : null">{{ k.value }}</div>
            <div v-if="k.sub" class="muted kpi-sub">{{ k.sub }}</div>
          </a-card>
        </a-col>
      </a-row>

      <a-card size="small" :title="$t('Cr_By_Courier')" style="margin-bottom: 16px">
        <a-table :columns="accountColumns" :data-source="data.by_account || []" row-key="account_id" size="small" :pagination="false" :scroll="{ x: 900 }">
          <template #bodyCell="{ column, record }">
            <template v-if="column.key === 'account'">
              <strong>{{ record.account_name }}</strong>
              <div class="muted">{{ record.courier_name }}</div>
            </template>
            <template v-else-if="column.key === 'delivery_rate'"><RateCell :value="record.delivery_rate" good /></template>
            <template v-else-if="column.key === 'return_rate'"><RateCell :value="record.return_rate" /></template>
            <template v-else-if="column.key === 'avg_days'">{{ record.avg_delivery_days !== null ? $t('Cr_Days', { n: record.avg_delivery_days }) : '—' }}</template>
            <template v-else-if="column.money">{{ money(record[column.key]) }}</template>
          </template>
        </a-table>
      </a-card>

      <a-card size="small" :title="$t('Cr_By_City')">
        <a-table :columns="cityColumns" :data-source="data.by_city || []" row-key="city" size="small" :pagination="false" :scroll="{ x: 700 }">
          <template #bodyCell="{ column, record }">
            <template v-if="column.key === 'delivery_rate'"><RateCell :value="record.delivery_rate" good /></template>
            <template v-else-if="column.key === 'return_rate'"><RateCell :value="record.return_rate" /></template>
            <template v-else-if="column.money">{{ money(record[column.key]) }}</template>
          </template>
        </a-table>
      </a-card>
    </a-spin>
  </div>
</template>

<script setup>
/**
 * Courier report — GET couriers/reports?from&to&account_id →
 * {summary, by_account[], by_city[], accounts[]}; each row: total, delivered,
 * returned, cancelled, in_progress, failed_attempts, delivery_rate,
 * return_rate (null until something finished), cod_total, cod_collected,
 * cod_remitted, cod_outstanding, charges, avg_delivery_days.
 */
import { ref, computed, onMounted, h } from 'vue';
import dayjs from 'dayjs';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import PageHeader from '../../components/PageHeader.vue';
import DateRangePicker from '../../components/DateRangePicker.vue';
import { useFormat } from '../../composables/useFormat';
import http from '../../lib/http';
import { errorText } from './courierShared';

const { t } = useI18n();
const { money } = useFormat();

const loading = ref(true);
const range = ref([dayjs().subtract(29, 'day'), dayjs()]);
const accountId = ref(undefined);
const data = ref({ summary: {}, by_account: [], by_city: [], accounts: [] });

const accountOptions = computed(() => (data.value.accounts || []).map(a => ({ value: a.id, label: a.name })));
const pct = v => (v === null || v === undefined ? '—' : `${v}%`);

/** Rate with a colour: returns are bad when high, deliveries good when high. */
const RateCell = props => {
  const v = props.value;
  if (v === null || v === undefined) return h('span', { class: 'muted' }, '—');
  const bad = props.good ? v < 80 : v >= 20;
  const warn = props.good ? v < 90 : v >= 10;
  return h('span', { style: { color: bad ? '#cf1322' : warn ? '#d48806' : '#389e0d', fontWeight: 600 } }, `${v}%`);
};
// Typed props: a bare `good` attribute must become true (array syntax would leave it '').
RateCell.props = { value: { type: Number, default: null }, good: { type: Boolean, default: false } };

const kpis = computed(() => {
  const s = data.value.summary || {};
  return [
    { label: t('Cr_Total_Shipments'), value: s.total ?? 0, sub: t('Cr_In_Progress') + ': ' + (s.in_progress ?? 0) },
    { label: t('Cr_Delivery_Rate'), value: pct(s.delivery_rate), color: '#389e0d', sub: t('Cr_Status_Delivered') + ': ' + (s.delivered ?? 0) },
    { label: t('Cr_Return_Rate'), value: pct(s.return_rate), color: s.return_rate >= 20 ? '#cf1322' : undefined, sub: t('Cr_Status_Returned') + ': ' + (s.returned ?? 0) },
    { label: t('Cr_COD_Total'), value: money(s.cod_total || 0), sub: t('Cr_COD_remitted') + ': ' + money(s.cod_remitted || 0) },
    { label: t('Cr_COD_Outstanding'), value: money(s.cod_outstanding || 0), color: '#d48806', sub: t('Cr_COD_collected') + ': ' + money(s.cod_collected || 0) },
    { label: t('Cr_Avg_Delivery'), value: s.avg_delivery_days !== null && s.avg_delivery_days !== undefined ? t('Cr_Days', { n: s.avg_delivery_days }) : '—', sub: t('Cr_Courier_Charges') + ': ' + money(s.charges || 0) },
  ];
});

const baseColumns = () => [
  { title: t('Cr_Total_Shipments'), dataIndex: 'total', key: 'total', align: 'right' },
  { title: t('Cr_Status_Delivered'), dataIndex: 'delivered', key: 'delivered', align: 'right' },
  { title: t('Cr_Status_Returned'), dataIndex: 'returned', key: 'returned', align: 'right' },
  { title: t('Cr_In_Progress'), dataIndex: 'in_progress', key: 'in_progress', align: 'right' },
  { title: t('Cr_Delivery_Rate'), key: 'delivery_rate', align: 'right' },
  { title: t('Cr_Return_Rate'), key: 'return_rate', align: 'right' },
];

const accountColumns = computed(() => [
  { title: t('Cr_Courier'), key: 'account', fixed: 'left', width: 200 },
  ...baseColumns(),
  { title: t('Cr_Avg_Delivery'), key: 'avg_days', align: 'right' },
  { title: t('Cr_COD_Outstanding'), key: 'cod_outstanding', money: true, align: 'right' },
  { title: t('Cr_COD_remitted'), key: 'cod_remitted', money: true, align: 'right' },
  { title: t('Cr_Courier_Charges'), key: 'charges', money: true, align: 'right' },
]);

const cityColumns = computed(() => [
  { title: t('City'), dataIndex: 'city', key: 'city', fixed: 'left', width: 180 },
  ...baseColumns(),
  { title: t('Cr_COD_Outstanding'), key: 'cod_outstanding', money: true, align: 'right' },
]);

async function load() {
  loading.value = true;
  try {
    const res = await http.get('couriers/reports', {
      from: range.value?.[0]?.format?.('YYYY-MM-DD') || '',
      to: range.value?.[1]?.format?.('YYYY-MM-DD') || '',
      account_id: accountId.value || '',
    });
    data.value = res || data.value;
  } catch (e) {
    message.error(errorText(e, t('InvalidData')));
  } finally {
    loading.value = false;
  }
}

onMounted(load);
</script>

<style scoped>
.muted {
  color: rgba(var(--ink-rgb), 0.45);
}
.hint {
  margin-top: 8px;
  font-size: 12px;
}
.fill {
  height: 100%;
}
.kpi-label {
  font-size: 12px;
  color: rgba(var(--ink-rgb), 0.55);
}
.kpi-value {
  font-size: 22px;
  font-weight: 600;
  margin-top: 2px;
}
.kpi-sub {
  font-size: 12px;
  margin-top: 2px;
}
</style>
