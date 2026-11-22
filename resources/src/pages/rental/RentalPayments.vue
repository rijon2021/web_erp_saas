<template>
  <div class="page">
    <PageHeader :title="$t('Rental_Payments')" :breadcrumb="[$t('Rental_Management'), $t('Rental_Payments')]">
      <template #actions>
        <a-button @click="$router.push('/rental/invoices')">
          <template #icon><FileTextOutlined /></template>
          {{ $t('Rental_Invoices') }}
        </a-button>
      </template>
    </PageHeader>

    <a-card size="small" class="block">
      <div class="toolbar">
        <DateRangePicker v-model:value="range" class="tb-range" @change="crud.reload" />
        <a-select v-model:value="methodId" :options="methodOptions" allow-clear :placeholder="$t('Paymentchoice')" class="tb-item" @change="crud.reload" />
        <span class="total">
          {{ $t('Total') }}: <strong>{{ money(crud.payload.value?.total_amount || 0) }}</strong>
        </span>
      </div>
    </a-card>

    <DataTable :crud="crud" :columns="columns">
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'payment_number'">
          <span class="num">{{ record.payment_number }}</span>
          <span class="sub">{{ date(record.payment_date) }}</span>
        </template>
        <template v-else-if="column.key === 'customer_name'">
          <router-link :to="`/rental/customers/${record.rental_customer_id}`" class="strong">{{ record.customer_name }}</router-link>
          <router-link v-if="record.rental_contract_id" :to="`/rental/contracts/${record.rental_contract_id}`" class="sub">{{ record.contract_number }}</router-link>
        </template>
        <template v-else-if="column.key === 'invoices'">
          <span class="invs">
            <router-link v-for="i in record.invoices" :key="i.id" :to="`/rental/invoices/${i.id}`" class="inv-chip">
              {{ i.number }} <small>{{ money(i.amount) }}</small>
            </router-link>
          </span>
        </template>
        <template v-else-if="column.key === 'method'">
          {{ record.method || '—' }}
          <span v-if="record.account" class="sub">{{ record.account }}</span>
        </template>
        <template v-else-if="column.key === 'amount'">
          <span class="strong ok">{{ money(record.amount) }}</span>
        </template>
        <template v-else-if="column.key === 'actions'">
          <a-tooltip v-if="canDelete && record.source !== 'deposit'" :title="$t('Rental_Delete_Payment')">
            <a-button type="text" size="small" danger @click="remove(record)">
              <template #icon><DeleteOutlined /></template>
            </a-button>
          </a-tooltip>
        </template>
      </template>
    </DataTable>
  </div>
</template>

<script setup>
/**
 * Rental payments — GET rental/payments → { payments, totalRows, total_amount }.
 * Deleting a payment reverses it everywhere (invoices, cash account, journal).
 */
import { ref, computed, createVNode, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import { message, Modal } from 'ant-design-vue';
import { FileTextOutlined, DeleteOutlined, ExclamationCircleOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import DateRangePicker from '../../components/DateRangePicker.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import { useAuthStore } from '../../stores/auth';
import http from '../../lib/http';
import { t } from '../../i18n';

const route = useRoute();
const auth = useAuthStore();
const { money, date } = useFormat();
const canDelete = computed(() => auth.can('rnt_payments_delete'));

const range = ref(null);
const methodId = ref(undefined);
const methods = ref([]);
const methodOptions = computed(() => methods.value.map(m => ({ value: m.id, label: m.name })));

const fmt = d => (d ? (typeof d === 'string' ? d.slice(0, 10) : d.format('YYYY-MM-DD')) : '');
const crud = useCrudTable('rental/payments', {
  rowsKey: 'payments',
  sortField: 'payment_date',
  params: () => ({
    from: fmt(range.value?.[0]),
    to: fmt(range.value?.[1]),
    payment_method_id: methodId.value || '',
    rental_customer_id: route.query.customer || '',
    rental_contract_id: route.query.contract || '',
  }),
});

function remove(row) {
  Modal.confirm({
    title: t('Rental_Delete_Payment', 'Delete payment'),
    icon: createVNode(ExclamationCircleOutlined),
    content: `${row.payment_number} — ${money(row.amount)}. ${t('Rental_Delete_Payment_Help', 'The invoices it paid become unpaid again and the cash account is reversed.')}`,
    okType: 'danger',
    okText: t('Delete_confirmButtonText', 'Yes, delete it!'),
    cancelText: t('Delete_cancelButtonText', 'Cancel'),
    async onOk() {
      try {
        await http.delete(`rental/payments/${row.id}`);
        message.success(t('Deleted_in_successfully', 'Deleted successfully'));
        crud.fetchRows();
      } catch (e) {
        message.error(e?.data?.message || t('Rental_Err_Could_Not_Delete_This_Payment', 'Could not delete this payment'));
      }
    },
  });
}

const columns = computed(() => [
  { title: t('Rental_Payment'), key: 'payment_number', dataIndex: 'payment_number', width: 150 },
  { title: t('Customer'), key: 'customer_name', dataIndex: 'customer_name' },
  { title: t('Rental_Applied_To'), key: 'invoices', width: 280 },
  { title: t('Paymentchoice'), key: 'method', width: 160 },
  { title: t('Reference'), dataIndex: 'reference', key: 'reference', width: 150 },
  { title: t('Amount'), key: 'amount', dataIndex: 'amount', width: 130, sum: 'money' },
  { title: '', key: 'actions', width: 60 },
]);

onMounted(async () => {
  crud.fetchRows();
  try {
    methods.value = (await http.get('rental/payments/meta'))?.payment_methods || [];
  } catch (e) { /* filter stays empty */ }
});
</script>

<style scoped>
.block {
  margin-bottom: 14px;
}
.toolbar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 10px;
}
.tb-range {
  width: 300px;
}
.tb-item {
  width: 180px;
}
.total {
  margin-inline-start: auto;
  font-size: 14px;
}
.total strong {
  color: #16a34a;
  font-size: 16px;
}
.num {
  display: block;
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
  font-weight: 600;
}
.sub {
  display: block;
  font-size: 11.5px;
  opacity: 0.65;
}
.strong {
  display: block;
  font-weight: 600;
}
.ok {
  color: #16a34a;
}
.invs {
  display: flex;
  flex-wrap: wrap;
  gap: 4px;
}
.inv-chip {
  padding: 1px 8px;
  border-radius: 999px;
  font-size: 12px;
  background: rgba(var(--app-primary-rgb, 109, 40, 217), 0.08);
}
.inv-chip small {
  opacity: 0.7;
}
@media (max-width: 767px) {
  .tb-range,
  .tb-item {
    width: 100%;
  }
  .total {
    margin-inline-start: 0;
  }
}
</style>
