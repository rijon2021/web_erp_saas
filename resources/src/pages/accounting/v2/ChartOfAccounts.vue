<template>
  <div class="page">
    <PageHeader :title="$t('Chart_of_Accounts_Title')" :breadcrumb="[$t('Accounting'), $t('Chart_of_Accounts_Title')]">
      <template #extra>
        <a-button type="primary" @click="openCreate">
          <template #icon><PlusOutlined /></template>
          {{ $t('Add') }}
        </a-button>
      </template>
    </PageHeader>

    <DataTable :crud="crud" :columns="columns">
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'parent'">
          {{ parentName(record.parent_id) }}
        </template>
        <template v-else-if="column.key === 'type'">
          <span style="text-transform: capitalize">{{ record.type }}</span>
        </template>
        <template v-else-if="column.key === 'active'">
          <a-tag :color="record.is_active ? 'success' : 'default'">{{ record.is_active ? $t('Yes') : $t('No') }}</a-tag>
        </template>
        <template v-else-if="column.key === 'actions'">
          <a-space>
            <a-tooltip :title="$t('Account_Ledger')">
              <a-button size="small" @click="openLedger(record)">
                <template #icon><FileSearchOutlined /></template>
              </a-button>
            </a-tooltip>
            <a-button size="small" @click="openEdit(record)">
              <template #icon><EditOutlined /></template>
            </a-button>
            <a-button size="small" danger @click="confirmRemove(record)">
              <template #icon><DeleteOutlined /></template>
            </a-button>
          </a-space>
        </template>
      </template>
    </DataTable>

    <a-modal
      v-model:open="modalOpen" :title="editing ? $t('Edit_Account') : $t('New_Account')"
      :confirm-loading="saving"
      :ok-button-props="{ disabled: !canSave }"
      @ok="save"
    >
      <a-form layout="vertical" style="margin-top: 12px">
        <a-form-item :label="$t('Code') + ' *'">
          <a-input v-model:value="form.code" :placeholder="$t('Example_Code')" />
        </a-form-item>
        <a-form-item :label="$t('Name') + ' *'">
          <a-input v-model:value="form.name" :placeholder="$t('Example_Name')" />
        </a-form-item>
        <a-row :gutter="16">
          <a-col :span="12">
            <a-form-item :label="$t('Type') + ' *'">
              <a-select v-model:value="form.type" :placeholder="$t('Select_Type')">
                <a-select-option value="asset">{{ $t('Asset') }}</a-select-option>
                <a-select-option value="liability">{{ $t('Liability') }}</a-select-option>
                <a-select-option value="equity">{{ $t('Equity') }}</a-select-option>
                <a-select-option value="income">{{ $t('Income') }}</a-select-option>
                <a-select-option value="expense">{{ $t('Expense') }}</a-select-option>
              </a-select>
            </a-form-item>
          </a-col>
          <a-col :span="12">
            <a-form-item :label="$t('Parent')">
              <a-select v-model:value="form.parent_id" show-search option-filter-prop="label">
                <a-select-option :value="null">{{ $t('None') }}</a-select-option>
                <a-select-option v-for="p in crud.rows.value" :key="p.id" :value="p.id" :label="p.code + ' — ' + p.name">
                  {{ p.code }} — {{ p.name }}
                </a-select-option>
              </a-select>
            </a-form-item>
          </a-col>
        </a-row>
        <a-form-item :label="$t('Status')">
          <a-select v-model:value="form.is_active">
            <a-select-option :value="1">{{ $t('Active') }}</a-select-option>
            <a-select-option :value="0">{{ $t('Inactive') }}</a-select-option>
          </a-select>
        </a-form-item>
      </a-form>
    </a-modal>

    <a-drawer
      v-model:open="ledgerOpen"
      :title="ledgerAccount ? `${$t('Account_Ledger')} — ${ledgerAccount.code} — ${ledgerAccount.name}` : $t('Account_Ledger')"
      width="min(960px, 100vw)"
      destroy-on-close
    >
      <a-row :gutter="[12, 12]" align="bottom" style="margin-bottom: 12px">
        <a-col :xs="12" :md="8">
          <div class="filter-label">{{ $t('From') }}</div>
          <a-input v-model:value="ledgerFilters.from" type="date" />
        </a-col>
        <a-col :xs="12" :md="8">
          <div class="filter-label">{{ $t('To') }}</div>
          <a-input v-model:value="ledgerFilters.to" type="date" />
        </a-col>
        <a-col :xs="24" :md="8">
          <a-button type="primary" @click="ledger.reload()">
            <template #icon><ReloadOutlined /></template>
            {{ $t('Apply') }}
          </a-button>
        </a-col>
      </a-row>

      <a-row :gutter="[12, 12]" style="margin-bottom: 12px">
        <a-col :xs="12" :md="6"><a-statistic :title="$t('Opening_Balance')" :value="number(ledgerSummary.opening)" /></a-col>
        <a-col :xs="12" :md="6"><a-statistic :title="$t('Debit')" :value="number(ledgerSummary.debit)" /></a-col>
        <a-col :xs="12" :md="6"><a-statistic :title="$t('Credit')" :value="number(ledgerSummary.credit)" /></a-col>
        <a-col :xs="12" :md="6"><a-statistic :title="$t('Closing_Balance')" :value="number(ledgerSummary.closing)" /></a-col>
      </a-row>

      <DataTable :crud="ledger" :columns="ledgerColumns" :searchable="false">
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'description'">
            {{ record.description || '-' }}
            <div v-if="record.memo" class="memo">{{ record.memo }}</div>
          </template>
          <template v-else-if="column.key === 'debit'">{{ record.debit ? number(record.debit) : '' }}</template>
          <template v-else-if="column.key === 'credit'">{{ record.credit ? number(record.credit) : '' }}</template>
          <template v-else-if="column.key === 'balance'"><strong>{{ number(record.balance) }}</strong></template>
        </template>
      </DataTable>
    </a-drawer>
  </div>
</template>

<script setup>
/**
 * Chart of accounts (v2) — GET accounting/v2/coa → {data, totalRows}, default
 * sort code asc. Save POST/PUT accounting/v2/coa[/{id}] {code, name, type,
 * parent_id, is_active 1|0}; save enabled only when code+name+type set
 * (legacy canSave). Parent select lists the CURRENT PAGE's accounts, like
 * legacy did.
 */
import { ref, computed, onMounted } from 'vue';
import { message, Modal } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { PlusOutlined, EditOutlined, DeleteOutlined, FileSearchOutlined, ReloadOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../../components/PageHeader.vue';
import DataTable from '../../../components/DataTable.vue';
import { useCrudTable } from '../../../composables/useCrudTable';
import { useFormat } from '../../../composables/useFormat';
import http from '../../../lib/http';

const { t } = useI18n();
const { number } = useFormat();

const crud = useCrudTable('accounting/v2/coa', {
  sortField: 'code',
  sortType: 'asc',
  select: p => ({ rows: (p && p.data) || [], total: (p && (p.totalRows ?? 0)) || 0 }),
});

const modalOpen = ref(false);
const editing = ref(false);
const saving = ref(false);
const form = ref({ id: null, code: '', name: '', type: undefined, parent_id: null, is_active: 1 });

const canSave = computed(() => !!(form.value.code && form.value.name && form.value.type));

const columns = computed(() => [
  { title: t('Code'), dataIndex: 'code', key: 'code', sorter: true },
  { title: t('Name'), dataIndex: 'name', key: 'name', sorter: true },
  { title: t('Parent'), key: 'parent' },
  { title: t('Type'), key: 'type' },
  { title: t('Active'), key: 'active', width: 90, align: 'center' },
  { title: t('Action'), key: 'actions', width: 140 },
]);

// Ledger drawer: GET accounting/v2/reports/account-ledger {coa_id, from, to}
// -> {data, totalRows, opening, debit, credit, closing}; each row carries its
// running balance, computed server-side so it stays right across pages.
const ledgerOpen = ref(false);
const ledgerAccount = ref(null);
const ledgerFilters = ref({ from: '', to: '' });
const ledgerSummary = ref({ opening: 0, debit: 0, credit: 0, closing: 0 });

const ledger = useCrudTable('accounting/v2/reports/account-ledger', {
  params: () => ({
    coa_id: ledgerAccount.value ? ledgerAccount.value.id : '',
    from: ledgerFilters.value.from || '',
    to: ledgerFilters.value.to || '',
  }),
  select: p => {
    ledgerSummary.value = {
      opening: Number(p && p.opening) || 0,
      debit: Number(p && p.debit) || 0,
      credit: Number(p && p.credit) || 0,
      closing: Number(p && p.closing) || 0,
    };
    return { rows: (p && p.data) || [], total: (p && p.totalRows) || 0 };
  },
});

const ledgerColumns = computed(() => [
  { title: t('date'), dataIndex: 'date', key: 'date', width: 110 },
  { title: t('Description'), key: 'description' },
  { title: t('Debit'), key: 'debit', align: 'right' },
  { title: t('Credit'), key: 'credit', align: 'right' },
  { title: t('Balance'), key: 'balance', align: 'right' },
]);

function openLedger(row) {
  ledgerAccount.value = row;
  ledgerFilters.value = { from: '', to: '' };
  ledgerSummary.value = { opening: 0, debit: 0, credit: 0, closing: 0 };
  ledger.rows.value = [];
  ledgerOpen.value = true;
  ledger.reload();
}

function parentName(id) {
  if (!id) return t('None');
  const p = crud.rows.value.find(x => x.id === id);
  return p ? `${p.code} — ${p.name}` : '-';
}
function openCreate() {
  editing.value = false;
  form.value = { id: null, code: '', name: '', type: undefined, parent_id: null, is_active: 1 };
  modalOpen.value = true;
}
function openEdit(row) {
  editing.value = true;
  form.value = {
    id: row.id, code: row.code, name: row.name, type: row.type,
    parent_id: row.parent_id, is_active: row.is_active ? 1 : 0,
  };
  modalOpen.value = true;
}
async function save() {
  if (!canSave.value) return;
  saving.value = true;
  try {
    if (editing.value) {
      await http.put(`accounting/v2/coa/${form.value.id}`, form.value);
      message.success(t('Account_Updated'));
    } else {
      await http.post('accounting/v2/coa', form.value);
      message.success(t('Account_Created'));
    }
    modalOpen.value = false;
    await crud.fetchRows();
  } catch (e) {
    message.error(t('Operation_Failed'));
  } finally {
    saving.value = false;
  }
}
function confirmRemove(row) {
  Modal.confirm({
    title: t('Delete'),
    content: `${t('Delete_Account_Warning')} — ${row.code} — ${row.name}`,
    okText: t('Delete'),
    okType: 'danger',
    cancelText: t('Cancel'),
    async onOk() {
      try {
        await http.delete(`accounting/v2/coa/${row.id}`);
        message.success(t('Deleted_Successfully'));
        await crud.fetchRows();
      } catch (e) {
        message.error(t('Delete_Failed'));
      }
    },
  });
}

onMounted(crud.fetchRows);
</script>

<style scoped>
.filter-label {
  font-size: 12px;
  color: rgba(var(--ink-rgb), 0.55);
  margin-bottom: 4px;
}
.memo {
  font-size: 12px;
  color: rgba(var(--ink-rgb), 0.55);
}
</style>
