<template>
  <div class="page">
    <PageHeader :title="$t('Rental_Reservations')" :breadcrumb="[$t('Rental_Management'), $t('Rental_Reservations')]">
      <template #actions>
        <a-button v-if="canAvailability" @click="$router.push('/rental/availability')">
          <template #icon><ScheduleOutlined /></template>
          {{ $t('Rental_Availability') }}
        </a-button>
        <a-button v-if="canAdd" type="primary" @click="$router.push('/rental/reservations/create')">
          <template #icon><PlusOutlined /></template>
          {{ $t('Rental_New_Reservation') }}
        </a-button>
      </template>
    </PageHeader>

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
        <template v-if="column.key === 'reservation_number'">
          <button type="button" class="num" @click="open(record)">{{ record.reservation_number }}</button>
          <a-tag v-if="record.source === 'website'" color="blue" class="src-tag"><GlobalOutlined /> {{ $t('Rental_Source_Website') }}</a-tag>
          <a-tag v-if="record.confirmed_at && record.status === 'held'" color="green" class="src-tag">{{ $t('Rental_Confirmed') }}</a-tag>
          <router-link v-if="record.contract_id" :to="`/rental/contracts/${record.contract_id}`" class="sub link">
            → {{ record.contract_number }}
          </router-link>
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
            <span>{{ record.items_summary.slice(0, 2).join(', ') }}<template v-if="record.items_summary.length > 2"> +{{ record.items_summary.length - 2 }}</template></span>
          </a-tooltip>
        </template>

        <template v-else-if="column.key === 'start_date'">
          <span class="dates">{{ dateTime(record.start_date) }}<br><span class="sub">→ {{ dateTime(record.end_date) }}</span></span>
        </template>

        <template v-else-if="column.key === 'hold_until'">
          <template v-if="['held', 'expiring'].includes(record.stage)">
            <span :class="{ warn: record.stage === 'expiring' }">{{ dateTime(record.hold_until) }}</span>
            <span class="sub" :class="{ warn: record.stage === 'expiring' }">{{ holdLeft(record) }}</span>
          </template>
          <span v-else class="muted">—</span>
        </template>

        <template v-else-if="column.key === 'total_amount'">
          <span class="money">{{ money(record.total_amount) }}</span>
          <span class="sub">{{ record.billing_type === 'upfront' ? labelOf(BILLING_TYPES, 'upfront') : perLabel(record.rate_period) }}</span>
        </template>

        <template v-else-if="column.key === 'stage'">
          <a-tag :color="optionOf(RESERVATION_STAGES, record.stage).color">{{ labelOf(RESERVATION_STAGES, record.stage) }}</a-tag>
        </template>

        <template v-else-if="column.key === 'actions'">
          <a-space :size="0">
            <a-tooltip :title="$t('View')">
              <a-button type="text" size="small" @click="open(record)">
                <template #icon><EyeOutlined /></template>
              </a-button>
            </a-tooltip>
            <a-tooltip v-if="canEdit && record.status === 'held' && !record.contract_id" :title="$t('Edit')">
              <a-button type="text" size="small" @click="$router.push(`/rental/reservations/${record.id}/edit`)">
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
 * Reservations (holds) — GET rental/reservations → { reservations, totalRows, summary }.
 * A live hold blocks its items until `hold_until`; then it is converted,
 * cancelled, or expires on its own.
 */
import { ref, computed, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { PlusOutlined, EyeOutlined, EditOutlined, ScheduleOutlined, GlobalOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import { useAuthStore } from '../../stores/auth';
import { t, i18n } from '../../i18n';
import {
  RESERVATION_STAGES, BILLING_TYPES, labelOf, optionOf, perLabel, initials, avatarColor,
} from './rentalOptions';

const auth = useAuthStore();
const route = useRoute();
const router = useRouter();
const { money, dateTime } = useFormat();

const canAdd = computed(() => auth.can('rnt_reservations_add'));
const canEdit = computed(() => auth.can('rnt_reservations_edit'));
const canAvailability = computed(() => auth.can('rnt_availability'));

const STAGES = ['all', 'held', 'expiring', 'converted', 'expired', 'cancelled', 'website'];
const stage = ref(STAGES.includes(route.query.stage) ? route.query.stage : 'held');

const stageTabs = computed(() => [
  // Website booking requests still waiting for staff (the API sends the count once migrated).
  ...('website' in (crud.payload.value?.summary || {}) ? [{ value: 'website', label: t('Rental_Website_Requests', 'Website requests'), tone: 'warn' }] : []),
  { value: 'held', label: labelOf(RESERVATION_STAGES, 'held'), tone: 'info' },
  { value: 'expiring', label: labelOf(RESERVATION_STAGES, 'expiring'), tone: 'warn' },
  { value: 'converted', label: labelOf(RESERVATION_STAGES, 'converted'), tone: 'ok' },
  { value: 'expired', label: labelOf(RESERVATION_STAGES, 'expired'), tone: 'neutral' },
  { value: 'cancelled', label: labelOf(RESERVATION_STAGES, 'cancelled'), tone: 'neutral' },
  { value: 'all', label: t('All', 'All'), tone: 'neutral' },
]);

const crud = useCrudTable('rental/reservations', {
  rowsKey: 'reservations',
  sortField: 'created_at',
  params: () => ({ stage: stage.value === 'all' ? '' : stage.value }),
});
const summary = computed(() => crud.payload.value?.summary || {});

function setStage(value) {
  stage.value = value;
  crud.reload();
}

function open(row) {
  router.push(`/rental/reservations/${row.id}`);
}

function holdLeft(row) {
  const h = row.hours_left ?? 0;
  const params = h >= 48 ? { n: Math.floor(h / 24) } : { n: h };
  const key = h >= 48 ? 'Rental_Days_Remaining' : 'Rental_Hours_Left';
  const fallback = h >= 48 ? '{n} day(s) left' : '{n}h left';
  const out = i18n.global.t(key, params);
  return out !== key ? out : fallback.replace('{n}', params.n);
}

const columns = computed(() => [
  { title: t('Rental_Reservation'), key: 'reservation_number', dataIndex: 'reservation_number', sorter: true, width: 160 },
  { title: t('Customer'), key: 'customer_name', dataIndex: 'customer_name', sorter: true },
  { title: t('Rental_Items'), key: 'items', width: 200 },
  { title: t('Rental_Period'), key: 'start_date', dataIndex: 'start_date', sorter: true, width: 190 },
  { title: t('Rental_Hold_Until'), key: 'hold_until', dataIndex: 'hold_until', sorter: true, width: 170 },
  { title: t('Rental_Estimate'), key: 'total_amount', dataIndex: 'total_amount', sorter: true, width: 140 },
  { title: t('Status'), key: 'stage', width: 120 },
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
.tone-ok .stage-count {
  color: #15803d;
  background: rgba(22, 163, 74, 0.13);
}
.tone-info .stage-count {
  color: #0369a1;
  background: rgba(2, 132, 199, 0.13);
}
.src-tag {
  display: table;
  margin: 3px 0 0;
  font-size: 11px;
}
.num {
  padding: 0;
  border: 0;
  background: none;
  color: inherit;
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
  font-weight: 600;
  cursor: pointer;
}
.num:hover {
  color: var(--app-primary, #6d28d9);
}
.sub {
  display: block;
  font-size: 11.5px;
  opacity: 0.6;
}
.sub.link {
  opacity: 1;
}
.warn {
  color: #b45309;
  opacity: 1;
}
.muted {
  opacity: 0.5;
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
.dates {
  font-size: 13px;
}
.money {
  display: block;
  font-weight: 600;
}
</style>
