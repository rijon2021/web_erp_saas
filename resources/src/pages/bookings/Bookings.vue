<template>
  <div class="page">
    <PageHeader :title="$t('Booking_List')" :breadcrumb="[$t('Bookings'), $t('Booking_List')]">
      <template #actions>
        
        <a-button type="primary" @click="$router.push('/bookings/create')">
          <template #icon><PlusOutlined /></template>
          {{ $t('Add') }}
        </a-button>
      </template>
    </PageHeader>

    <!-- Stat tiles (from the list payload) -->
    <a-row :gutter="[16, 16]" style="margin-bottom: 16px">
      <a-col v-for="tile in tiles" :key="tile.label" :xs="12" :md="6">
        <a-card size="small">
          <a-spin :spinning="loading">
            <a-statistic :title="tile.label" :value="tile.value" :value-style="tile.style" />
          </a-spin>
        </a-card>
      </a-col>
    </a-row>

    <a-card :body-style="{ padding: 0 }">
      <div style="display: flex; gap: 12px; flex-wrap: wrap; padding: 16px; border-bottom: 1px solid rgba(5,5,5,0.06)">
        <a-input-search
          :value="crud.search.value"
          :placeholder="$t('Search_this_table')"
          allow-clear
          style="max-width: 260px"
          @update:value="v => (crud.search.value = v)"
          @search="crud.reload()"
        />
        <a-select
          v-model:value="status"
          style="width: 160px"
          allow-clear
          :placeholder="$t('Status')"
          :options="statusOptions"
          @change="crud.reload()"
        />
        <a-date-picker v-model:value="day" value-format="YYYY-MM-DD" allow-clear @change="crud.reload()" />
        <a-select
          v-model:value="source" style="width: 170px" allow-clear :placeholder="$t('Bkm_Source')"
          :options="[{ value: 'website', label: $t('Bkm_From_Website') }, { value: 'staff', label: $t('Bkm_Entered_By_Staff') }]"
          @change="crud.reload()"
        />
      </div>

      <a-table
        :columns="columns"
        :data-source="crud.rows.value"
        :loading="crud.loading.value"
        :pagination="crud.pagination.value"
        row-key="id"
        :scroll="{ x: 'max-content' }"
        size="middle"
        @change="crud.onChange"
      >
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'booking_date'">{{ date(record.booking_date) }}</template>
          <template v-else-if="column.key === 'price'">{{ money(record.price) }}</template>
          <template v-else-if="column.key === 'status'">
            <a-tag :color="statusColor(record.status)">{{ statusLabel(record.status) }}</a-tag>
            <a-tag v-if="record.source === 'website'" color="blue">{{ $t('Bkm_Web') }}</a-tag>
          </template>
          <template v-else-if="column.key === 'staff_name'">{{ record.staff_name || '—' }}</template>
          <template v-else-if="column.key === 'actions'">
            <a-space>
              <a-tooltip v-if="crud.payload.value?.workflow_ready" :title="$t('Bkm_View_Actions')">
                <a-button type="text" size="small" @click="openView(record)">
                  <template #icon><EyeOutlined /></template>
                </a-button>
              </a-tooltip>
              <a-tooltip :title="$t('Edit')">
                <a-button type="text" size="small" @click="$router.push(`/bookings/${record.id}/edit`)">
                  <template #icon><EditOutlined style="color: #52c41a" /></template>
                </a-button>
              </a-tooltip>
              <a-tooltip :title="$t('Del')">
                <a-button type="text" size="small" danger @click="crud.remove(record)">
                  <template #icon><DeleteOutlined /></template>
                </a-button>
              </a-tooltip>
            </a-space>
          </template>
        </template>
      </a-table>
    </a-card>

    <BookingQuickView v-model:open="viewOpen" :booking-id="viewId" @changed="crud.reload()" />
  </div>
</template>

<script setup>
/**
 * Bookings list: GET bookings → {bookings, totalRows, stats} with status and
 * date filters. Create/edit are BookingForm.vue; the calendar is
 * BookingCalendar.vue. Still on legacy: PDF and send-email.
 */
import { ref, computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { PlusOutlined, EditOutlined, DeleteOutlined, EyeOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import BookingQuickView from './BookingQuickView.vue';
import { statusLabel as bkStatusLabel, statusTag, statusOptions as bkStatusOptions } from './bookingStatus';

const { t } = useI18n();
const { money, date } = useFormat();

const status = ref(undefined);
const day = ref(null);
const source = ref(undefined);
const viewOpen = ref(false);
const viewId = ref(null);

function openView(record) {
  viewId.value = record.id;
  viewOpen.value = true;
}

const filterParams = () => ({
  ...(status.value ? { status: status.value } : {}),
  ...(day.value ? { date: day.value } : {}),
  ...(source.value ? { source: source.value } : {}),
});

// Payload: { bookings, totalRows, stats: {total, pending, confirmed, completed} }
const crud = useCrudTable('bookings', {
  rowsKey: 'bookings',
  sortField: 'booking_date',
  sortType: 'desc',
  params: filterParams,
});

const loading = computed(() => crud.loading.value);
const stats = computed(() => crud.payload.value?.stats || {});
const tiles = computed(() => [
  { label: t('Total'), value: stats.value.total || 0 },
  { label: t('Pending'), value: stats.value.pending || 0, style: { color: '#faad14' } },
  { label: t('Confirmed'), value: stats.value.confirmed || 0, style: { color: '#1677ff' } },
  { label: t('Completed'), value: stats.value.completed || 0, style: { color: '#52c41a' } },
]);

const statusOptions = computed(() => bkStatusOptions());

const columns = computed(() => [
  { title: t('Reference'), dataIndex: 'Ref', key: 'Ref', sorter: true },
  { title: t('Customer'), dataIndex: 'customer_name', key: 'customer_name' },
  { title: t('ProductName'), dataIndex: 'product_name', key: 'product_name' },
  { title: t('Price'), key: 'price', dataIndex: 'price', align: 'right', exportValue: r => money(r.price) },
  { title: t('date'), key: 'booking_date', dataIndex: 'booking_date', sorter: true, exportValue: r => date(r.booking_date) },
  { title: t('Time_In'), dataIndex: 'booking_time', key: 'booking_time' },
  { title: t('Time_Out'), dataIndex: 'booking_end_time', key: 'booking_end_time' },
  { title: t('Bkm_Staff', 'Staff'), dataIndex: 'staff_name', key: 'staff_name', exportValue: r => r.staff_name || '' },
  { title: t('Status'), key: 'status', dataIndex: 'status', exportValue: r => statusLabel(r.status) },
  { title: t('Action'), key: 'actions', width: 140, align: 'center' },
]);

function statusLabel(s) {
  return bkStatusLabel(s);
}

function statusColor(s) {
  return statusTag(s);
}

onMounted(crud.fetchRows);
</script>
