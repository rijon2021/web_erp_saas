<template>
  <div class="page">
    <PageHeader :title="$t('Rental_Expenses')" :breadcrumb="[$t('Rental_Management'), $t('Rental_Expenses')]">
      <template #actions>
        <a-button type="primary" @click="openForm()">
          <template #icon><PlusOutlined /></template>{{ $t('Rental_Add_Expense') }}
        </a-button>
      </template>
    </PageHeader>

    <!-- Category split -->
    <div class="cats">
      <div class="cat-total">
        <span>{{ $t('Total') }}</span>
        <strong>{{ money(crud.payload.value?.total_amount || 0) }}</strong>
      </div>
      <button
        v-for="c in categoryChips" :key="c.value" type="button" class="cat"
        :class="{ active: category === c.value }" @click="toggleCategory(c.value)"
      >
        <span class="cat-dot" :class="`c-${c.value}`"></span>
        <span class="cat-name">{{ c.label }}</span>
        <strong>{{ money(c.total) }}</strong>
      </button>
    </div>

    <a-card size="small" class="block">
      <div class="toolbar">
        <DateRangePicker v-model:value="range" class="tb-range" @change="crud.reload" />
        <a-select v-model:value="itemId" :options="itemOptions" allow-clear show-search option-filter-prop="label" :placeholder="$t('Rental_Item')" class="tb-item" @change="crud.reload" />
      </div>
    </a-card>

    <DataTable :crud="crud" :columns="columns">
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'expense_date'">{{ date(record.expense_date) }}</template>
        <template v-else-if="column.key === 'category'">
          <a-tag :color="optionOf(EXPENSE_CATEGORIES, record.category).color">{{ labelOf(EXPENSE_CATEGORIES, record.category) }}</a-tag>
        </template>
        <template v-else-if="column.key === 'item'">
          <router-link v-if="record.rental_item_id" :to="`/rental/items/${record.rental_item_id}`">{{ record.item_name }}</router-link>
          <span v-else class="muted">{{ $t('Rental_General') }}</span>
          <router-link v-if="record.rental_maintenance_id" :to="`/rental/maintenance?job=${record.rental_maintenance_id}`" class="sub">{{ record.maintenance_number }}</router-link>
        </template>
        <template v-else-if="column.key === 'details'">
          <span class="strong">{{ record.vendor || '—' }}</span>
          <span class="sub">{{ record.description || record.reference || '' }}</span>
        </template>
        <template v-else-if="column.key === 'method'">
          {{ record.method || '—' }}<span v-if="record.account" class="sub">{{ record.account }}</span>
        </template>
        <template v-else-if="column.key === 'amount'">
          <strong class="amt">{{ money(record.amount) }}</strong>
        </template>
        <template v-else-if="column.key === 'actions'">
          <a-space :size="0">
            <a-tooltip v-if="record.attachment_url" :title="$t('Rental_Receipt')">
              <a-button type="text" size="small" :href="record.attachment_url" target="_blank"><template #icon><PaperClipOutlined /></template></a-button>
            </a-tooltip>
            <a-button type="text" size="small" @click="openForm(record)"><template #icon><EditOutlined /></template></a-button>
            <a-button type="text" size="small" danger @click="remove(record)"><template #icon><DeleteOutlined /></template></a-button>
          </a-space>
        </template>
      </template>
    </DataTable>

    <a-modal v-model:open="formOpen" :title="form.id ? $t('Rental_Edit_Expense') : $t('Rental_Add_Expense')" :ok-text="$t('Save')" :cancel-text="$t('Cancel')"
      :confirm-loading="saving" width="600px" @ok="save">
      <a-form layout="vertical">
        <a-row :gutter="12">
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Category')" required><a-select v-model:value="form.category" :options="categoryOptions" /></a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Rental_Item')" :extra="$t('Rental_Expense_Item_Help')">
              <a-select v-model:value="form.rental_item_id" :options="itemOptions" allow-clear show-search option-filter-prop="label" />
            </a-form-item>
          </a-col>
          <a-col :xs="12" :md="8">
            <a-form-item :label="$t('Amount')" required><a-input-number v-model:value="form.amount" :min="0.01" :precision="2" style="width: 100%" /></a-form-item>
          </a-col>
          <a-col :xs="12" :md="8">
            <a-form-item :label="$t('date')" required><a-date-picker v-model:value="form.expense_date" value-format="YYYY-MM-DD" style="width: 100%" :allow-clear="false" /></a-form-item>
          </a-col>
          <a-col :xs="24" :md="8">
            <a-form-item :label="$t('Rental_Vendor')"><a-input v-model:value="form.vendor" allow-clear /></a-form-item>
          </a-col>
          <a-col :xs="12" :md="8">
            <a-form-item :label="$t('Paymentchoice')"><a-select v-model:value="form.payment_method_id" :options="methodOptions" allow-clear /></a-form-item>
          </a-col>
          <a-col :xs="12" :md="8">
            <a-form-item :label="$t('Account')"><a-select v-model:value="form.account_id" :options="accountOptions" allow-clear /></a-form-item>
          </a-col>
          <a-col :xs="24" :md="8">
            <a-form-item :label="$t('Reference')"><a-input v-model:value="form.reference" allow-clear /></a-form-item>
          </a-col>
          <a-col :span="24">
            <a-form-item :label="$t('Description')"><a-textarea v-model:value="form.description" :rows="2" /></a-form-item>
          </a-col>
          <a-col :span="24">
            <a-form-item :label="$t('Rental_Receipt')" class="no-mb">
              <div class="receipt">
                <a v-if="form.attachment_url && !removeAttachment && !file" :href="form.attachment_url" target="_blank"><PaperClipOutlined /> {{ $t('Rental_Current_Receipt') }}</a>
                <span v-if="file">{{ file.name }}</span>
                <a-upload :show-upload-list="false" accept=".pdf,image/*" :before-upload="f => { file = f; return false; }">
                  <a-button size="small"><template #icon><UploadOutlined /></template>{{ $t('Rental_Upload') }}</a-button>
                </a-upload>
                <a v-if="(form.attachment_url && !removeAttachment) || file" class="danger" @click="file = null; removeAttachment = true">{{ $t('Delete') }}</a>
              </div>
            </a-form-item>
          </a-col>
        </a-row>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * Rental expenses — GET rental/expenses → { expenses, totalRows, total_amount, by_category }.
 * Saving posts a "Rental Expenses / Cash" journal and lowers the chosen
 * account; editing re-posts it, deleting reverses it. ?new=1&item=ID opens
 * the form pre-filled.
 */
import { ref, reactive, computed, createVNode, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import { message, Modal } from 'ant-design-vue';
import dayjs from 'dayjs';
import { PlusOutlined, EditOutlined, DeleteOutlined, PaperClipOutlined, UploadOutlined, ExclamationCircleOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import DateRangePicker from '../../components/DateRangePicker.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import { uploadForm } from '../../lib/upload';
import http from '../../lib/http';
import { t } from '../../i18n';
import { EXPENSE_CATEGORIES, tr, labelOf, optionOf } from './rentalOptions';

const route = useRoute();
const { money, date } = useFormat();

const range = ref(null);
const itemId = ref(route.query.item ? Number(route.query.item) : undefined);
const category = ref(undefined);
const fmt = d => (d ? (typeof d === 'string' ? d.slice(0, 10) : d.format('YYYY-MM-DD')) : '');

const crud = useCrudTable('rental/expenses', {
  rowsKey: 'expenses',
  sortField: 'expense_date',
  params: () => ({
    from: fmt(range.value?.[0]),
    to: fmt(range.value?.[1]),
    rental_item_id: itemId.value || '',
    category: category.value || '',
  }),
});

const categoryOptions = computed(() => tr(EXPENSE_CATEGORIES));
const categoryChips = computed(() => {
  const by = crud.payload.value?.by_category || {};
  return tr(EXPENSE_CATEGORIES).map(c => ({ ...c, total: Number(by[c.value] || 0) })).filter(c => c.total > 0 || category.value === c.value);
});
function toggleCategory(v) {
  category.value = category.value === v ? undefined : v;
  crud.reload();
}

const columns = computed(() => [
  { title: t('date'), key: 'expense_date', dataIndex: 'expense_date', sorter: true, width: 120 },
  { title: t('Category'), key: 'category', dataIndex: 'category', sorter: true, width: 130 },
  { title: t('Rental_Item'), key: 'item', width: 200 },
  { title: t('Rental_Vendor'), key: 'details' },
  { title: t('Paymentchoice'), key: 'method', width: 150 },
  { title: t('Amount'), key: 'amount', dataIndex: 'amount', sorter: true, width: 130, sum: 'money' },
  { title: '', key: 'actions', width: 120 },
]);

// ----- meta -----
const meta = ref({ items: [], payment_methods: [], accounts: [] });
const itemOptions = computed(() => meta.value.items.map(i => ({ value: i.id, label: `${i.name} · ${i.code}` })));
const methodOptions = computed(() => meta.value.payment_methods.map(m => ({ value: m.id, label: m.name })));
const accountOptions = computed(() => meta.value.accounts.map(a => ({ value: a.id, label: a.account_name })));

// ----- form -----
const formOpen = ref(false);
const saving = ref(false);
const form = reactive({});
const file = ref(null);
const removeAttachment = ref(false);

function openForm(row = null) {
  Object.assign(form, {
    id: row?.id || null,
    category: row?.category || 'repair',
    rental_item_id: row?.rental_item_id || (route.query.item ? Number(route.query.item) : undefined),
    amount: row?.amount || null,
    expense_date: row?.expense_date || dayjs().format('YYYY-MM-DD'),
    vendor: row?.vendor || '',
    payment_method_id: row?.payment_method_id || meta.value.default_payment_method_id || undefined,
    account_id: row ? row.account_id || undefined : meta.value.default_account_id || undefined,
    reference: row?.reference || '',
    description: row?.description || '',
    attachment_url: row?.attachment_url || null,
  });
  file.value = null;
  removeAttachment.value = false;
  formOpen.value = true;
}

async function save() {
  if (!form.amount || !form.category) {
    message.error(t('Field_is_required', 'This field is required'));
    return;
  }
  const fd = new FormData();
  // Empty values are sent as '' (the server reads them as null) so an edit can clear a field.
  ['category', 'rental_item_id', 'amount', 'expense_date', 'vendor', 'payment_method_id', 'account_id', 'reference', 'description']
    .forEach((k) => fd.append(k, form[k] === null || form[k] === undefined ? '' : form[k]));
  if (file.value) fd.append('attachment_file', file.value);
  else if (removeAttachment.value) fd.append('remove_attachment', '1');

  saving.value = true;
  try {
    const res = await uploadForm(form.id ? `rental/expenses/${form.id}/update` : 'rental/expenses', fd);
    if (res.status >= 200 && res.status < 300) {
      message.success(t('Saved', 'Saved'));
      formOpen.value = false;
      crud.fetchRows();
    } else {
      const errors = res.data?.errors;
      message.error((errors && Object.values(errors)[0]?.[0]) || res.data?.message || t('Gen_Could_Not_Save', 'Could not save'));
    }
  } catch (e) {
    message.error(t('Gen_Could_Not_Save', 'Could not save'));
  } finally {
    saving.value = false;
  }
}

function remove(row) {
  Modal.confirm({
    title: t('Delete_Title', 'Are you sure?'),
    icon: createVNode(ExclamationCircleOutlined),
    content: `${labelOf(EXPENSE_CATEGORIES, row.category)} — ${money(row.amount)}`,
    okType: 'danger',
    okText: t('Delete_confirmButtonText', 'Yes, delete it!'),
    cancelText: t('Delete_cancelButtonText', 'Cancel'),
    async onOk() {
      try {
        await http.delete(`rental/expenses/${row.id}`);
        message.success(t('Deleted_in_successfully', 'Deleted successfully'));
        crud.fetchRows();
      } catch (e) {
        message.error(e?.data?.message || t('Gen_Could_Not_Delete', 'Could not delete'));
      }
    },
  });
}

onMounted(async () => {
  crud.fetchRows();
  try {
    meta.value = await http.get('rental/expenses/meta');
  } catch (e) {
    message.error(t('Rental_Err_Could_Not_Load_The_Form_Options', 'Could not load the form options'));
  }
  if (route.query.new) openForm();
});
</script>

<style scoped>
.block {
  margin-bottom: 14px;
}
.cats {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-bottom: 14px;
}
.cat-total {
  display: flex;
  flex-direction: column;
  justify-content: center;
  padding: 8px 16px;
  border-radius: 12px;
  background: rgba(var(--app-primary-rgb, 109, 40, 217), 0.08);
}
.cat-total span {
  font-size: 11.5px;
  opacity: 0.65;
}
.cat-total strong {
  font-size: 18px;
  color: var(--app-primary, #6d28d9);
}
.cat {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 8px 12px;
  border: 1px solid rgba(128, 128, 128, 0.2);
  border-radius: 12px;
  background: transparent;
  color: inherit;
  font: inherit;
  font-size: 13px;
  cursor: pointer;
}
.cat.active {
  border-color: var(--app-primary, #6d28d9);
  background: rgba(var(--app-primary-rgb, 109, 40, 217), 0.06);
}
.cat-dot {
  width: 9px;
  height: 9px;
  border-radius: 50%;
  background: #94a3b8;
}
.c-maintenance { background: #ea580c; }
.c-repair { background: #c2410c; }
.c-utilities { background: #0891b2; }
.c-insurance { background: #4f46e5; }
.c-tax { background: #7c3aed; }
.c-cleaning { background: #16a34a; }
.c-fuel { background: #ca8a04; }
.c-fees { background: #db2777; }
.toolbar {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
}
.tb-range {
  width: 300px;
}
.tb-item {
  width: 240px;
}
.muted {
  opacity: 0.55;
}
.sub {
  display: block;
  font-size: 11.5px;
  opacity: 0.65;
}
.strong {
  display: block;
  font-weight: 500;
}
.amt {
  color: #dc2626;
}
.receipt {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 10px;
}
.danger {
  color: #dc2626;
}
.no-mb {
  margin-bottom: 0;
}
@media (max-width: 767px) {
  .tb-range,
  .tb-item {
    width: 100%;
  }
}
</style>
