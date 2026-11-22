<template>
  <div class="page">
    <PageHeader :title="$t('Rental_Contracts')" :breadcrumb="[$t('Rental_Management'), $t('Rental_Contracts')]">
      <template #actions>
        <a-button v-if="canAdd" type="primary" @click="$router.push('/rental/contracts/create')">
          <template #icon><PlusOutlined /></template>
          {{ $t('Rental_New_Contract') }}
        </a-button>
      </template>
    </PageHeader>

    <!-- Stage tabs with live counts -->
    <div class="stages" role="tablist">
      <button
        v-for="s in stageTabs" :key="s.value" type="button" role="tab"
        class="stage" :class="[{ active: stage === s.value }, `tone-${s.tone}`]"
        :aria-selected="stage === s.value" @click="setStage(s.value)"
      >
        {{ s.label }}
        <span class="stage-count">{{ summary[s.value] ?? 0 }}</span>
      </button>
    </div>

    <DataTable :crud="crud" :columns="columns">
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'contract_number'">
          <button type="button" class="num" @click="open(record)">
            <span class="num-text">{{ record.contract_number }}</span>
            <a-tooltip v-if="record.is_signed" :title="$t('Rental_Signed')"><EditFilled class="signed" /></a-tooltip>
          </button>
          <span v-if="record.renewed_from_id" class="sub">{{ $t('Rental_Renewal') }}</span>
        </template>

        <template v-else-if="column.key === 'customer_name'">
          <span class="cust">
            <a-avatar :size="28" :style="{ background: avatarColor(record.customer_name) }">{{ initials(record.customer_name) }}</a-avatar>
            <span>
              <span class="cust-name">{{ record.customer_name }}</span>
              <span class="sub">{{ record.customer_phone || '' }}</span>
            </span>
          </span>
        </template>

        <template v-else-if="column.key === 'items'">
          <a-tooltip :title="record.items_summary.join(', ')">
            <span class="items">{{ record.items_summary.slice(0, 2).join(', ') }}<template v-if="record.items_summary.length > 2"> +{{ record.items_summary.length - 2 }}</template></span>
          </a-tooltip>
        </template>

        <template v-else-if="column.key === 'start_date'">
          <div class="period">
            <span>{{ date(record.start_date) }} → {{ record.end_date ? date(record.end_date) : $t('Rental_Open_Ended') }}</span>
            <a-progress
              v-if="record.progress !== null && ['active', 'expiring', 'overdue'].includes(record.stage)"
              :percent="record.progress" :show-info="false" size="small"
              :stroke-color="record.stage === 'overdue' ? '#dc2626' : record.stage === 'expiring' ? '#d97706' : 'var(--app-primary, #6d28d9)'"
              class="period-bar"
            />
            <span v-if="daysText(record)" class="sub" :class="{ warn: record.stage === 'expiring', bad: record.stage === 'overdue' }">{{ daysText(record) }}</span>
          </div>
        </template>

        <template v-else-if="column.key === 'total_amount'">
          <span class="money">{{ money(record.total_amount) }}</span>
          <span class="sub">{{ record.billing_type === 'upfront' ? labelOf(BILLING_TYPES, 'upfront') : perLabel(record.rate_period) }}</span>
        </template>

        <template v-else-if="column.key === 'contract_value'">
          {{ record.contract_value !== null ? money(record.contract_value) : '—' }}
        </template>

        <template v-else-if="column.key === 'stage'">
          <a-tag :color="optionOf(CONTRACT_STAGES, record.stage).color">{{ labelOf(CONTRACT_STAGES, record.stage) }}</a-tag>
        </template>

        <template v-else-if="column.key === 'actions'">
          <a-space :size="0">
            <a-tooltip :title="$t('View')">
              <a-button type="text" size="small" @click="open(record)">
                <template #icon><EyeOutlined /></template>
              </a-button>
            </a-tooltip>
            <a-tooltip v-if="canEdit && ['draft', 'active'].includes(record.status)" :title="$t('Edit')">
              <a-button type="text" size="small" @click="$router.push(`/rental/contracts/${record.id}/edit`)">
                <template #icon><EditOutlined /></template>
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
 * Rental contracts — GET rental/contracts → { contracts, totalRows, summary }.
 * The stage tabs mix stored statuses (draft, cancelled) with date-derived
 * ones (upcoming, expiring, overdue); the API computes both.
 */
import { ref, computed, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { PlusOutlined, EyeOutlined, EditOutlined, EditFilled } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import { useAuthStore } from '../../stores/auth';
import { t, i18n } from '../../i18n';
import {
  CONTRACT_STAGES, BILLING_TYPES, labelOf, optionOf, perLabel, initials, avatarColor,
} from './rentalOptions';

const auth = useAuthStore();
const route = useRoute();
const router = useRouter();
const { money, date } = useFormat();

const canAdd = computed(() => auth.can('rnt_contracts_add'));
const canEdit = computed(() => auth.can('rnt_contracts_edit'));

const STAGE_VALUES = ['all', 'active', 'upcoming', 'expiring', 'overdue', 'draft', 'closed', 'cancelled'];
const stage = ref(STAGE_VALUES.includes(route.query.stage) ? route.query.stage : 'all');

const stageTabs = computed(() => [
  { value: 'all', label: t('All', 'All'), tone: 'neutral' },
  { value: 'active', label: labelOf(CONTRACT_STAGES, 'active'), tone: 'ok' },
  { value: 'upcoming', label: labelOf(CONTRACT_STAGES, 'upcoming'), tone: 'info' },
  { value: 'expiring', label: labelOf(CONTRACT_STAGES, 'expiring'), tone: 'warn' },
  { value: 'overdue', label: labelOf(CONTRACT_STAGES, 'overdue'), tone: 'bad' },
  { value: 'draft', label: labelOf(CONTRACT_STAGES, 'draft'), tone: 'neutral' },
  { value: 'closed', label: t('Rental_Closed', 'Closed'), tone: 'neutral' },
  { value: 'cancelled', label: labelOf(CONTRACT_STAGES, 'cancelled'), tone: 'neutral' },
]);

const crud = useCrudTable('rental/contracts', {
  rowsKey: 'contracts',
  sortField: 'created_at',
  params: () => ({
    stage: stage.value === 'all' ? '' : stage.value,
    rental_customer_id: route.query.customer || '',
    rental_item_id: route.query.item || '',
  }),
});

const summary = computed(() => crud.payload.value?.summary || {});

function setStage(value) {
  stage.value = value;
  crud.reload();
}

function open(row) {
  router.push(`/rental/contracts/${row.id}`);
}

function tp(key, fallback, params) {
  const out = i18n.global.t(key, params);
  if (out !== key) return out;
  return Object.entries(params).reduce((s, [k, v]) => s.replace(`{${k}}`, v), fallback);
}

function daysText(row) {
  if (row.stage === 'overdue' && row.days_left !== null) {
    return tp('Rental_Overdue_By', 'Overdue by {n} day(s)', { n: Math.abs(row.days_left) });
  }
  if (['active', 'expiring'].includes(row.stage) && row.days_left !== null) {
    return tp('Rental_Days_Remaining', '{n} day(s) left', { n: row.days_left });
  }
  return '';
}

const columns = computed(() => [
  { title: t('Rental_Contract'), key: 'contract_number', dataIndex: 'contract_number', sorter: true, width: 140 },
  { title: t('Customer'), key: 'customer_name', dataIndex: 'customer_name', sorter: true },
  { title: t('Rental_Items'), key: 'items', width: 200 },
  { title: t('Rental_Period'), key: 'start_date', dataIndex: 'start_date', sorter: true, width: 250 },
  { title: t('Rental_Per_Bill'), key: 'total_amount', dataIndex: 'total_amount', sorter: true, width: 140 },
  { title: t('Rental_Contract_Value'), key: 'contract_value', dataIndex: 'contract_value', sorter: true, width: 140, sum: 'money' },
  { title: t('Status'), key: 'stage', width: 130 },
  { title: '', key: 'actions', width: 90 },
]);

onMounted(crud.fetchRows);
</script>

<style scoped>
.stages {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-bottom: 14px;
}
.stage {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 6px 8px 6px 14px;
  border: 1px solid rgba(128, 128, 128, 0.25);
  border-radius: 999px;
  background: transparent;
  color: inherit;
  font: inherit;
  font-size: 13px;
  cursor: pointer;
  transition: border-color 0.15s ease, background 0.15s ease;
}
.stage:hover {
  border-color: rgba(var(--app-primary-rgb, 109, 40, 217), 0.5);
}
.stage.active {
  border-color: var(--app-primary, #6d28d9);
  background: rgba(var(--app-primary-rgb, 109, 40, 217), 0.08);
  color: var(--app-primary, #6d28d9);
  font-weight: 500;
}
.stage-count {
  min-width: 24px;
  padding: 0 7px;
  border-radius: 999px;
  font-size: 11.5px;
  font-weight: 600;
  line-height: 20px;
  text-align: center;
  background: rgba(128, 128, 128, 0.15);
}
.tone-warn .stage-count {
  color: #b45309;
  background: rgba(217, 119, 6, 0.15);
}
.tone-bad .stage-count {
  color: #b91c1c;
  background: rgba(220, 38, 38, 0.13);
}
.tone-ok .stage-count {
  color: #15803d;
  background: rgba(22, 163, 74, 0.13);
}
.tone-info .stage-count {
  color: #0369a1;
  background: rgba(2, 132, 199, 0.13);
}

.num {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 0;
  border: 0;
  background: none;
  color: inherit;
  font: inherit;
  cursor: pointer;
}
.num-text {
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
  font-weight: 600;
}
.num:hover .num-text {
  color: var(--app-primary, #6d28d9);
}
.signed {
  color: #16a34a;
}
.sub {
  display: block;
  font-size: 11.5px;
  opacity: 0.6;
}
.sub.warn {
  color: #b45309;
  opacity: 1;
}
.sub.bad {
  color: #dc2626;
  opacity: 1;
}
.cust {
  display: inline-flex;
  align-items: center;
  gap: 8px;
}
.cust-name {
  display: block;
  font-weight: 500;
}
.items {
  font-size: 13px;
}
.period {
  display: flex;
  flex-direction: column;
  font-size: 13px;
}
.period-bar {
  margin: 2px 0 0;
  max-width: 200px;
}
.money {
  display: block;
  font-weight: 600;
}
</style>
