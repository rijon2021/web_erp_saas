<template>
  <div class="page">
    <PageHeader :title="$t('Suppliers')" :breadcrumb="[$t('People'), $t('Suppliers')]">
      <template #actions>
        <a-button v-if="auth.can('Suppliers_add')" type="primary" @click="$router.push('/suppliers/create')">
          <template #icon><PlusOutlined /></template>
          {{ $t('Add') }}
        </a-button>
      </template>
    </PageHeader>

    <!-- Summary cards — totals across every supplier matching the current search
         (a separate unpaginated fetch), not just the loaded page. -->
    <a-row :gutter="[16, 16]" style="margin-bottom: 16px">
      <a-col :xs="24" :sm="8" :md="8">
        <a-card size="small" class="stat-card">
          <div class="stat-inner">
            <div class="stat-icon" style="background: rgba(109, 40, 217, 0.12); color: #6d28d9"><ShopOutlined /></div>
            <div class="stat-meta">
              <div class="stat-label">{{ $t('Suppliers') }}</div>
              <div class="stat-value">
                <a-spin v-if="cardsLoading" size="small" />
                <template v-else>{{ supplierCount }}</template>
              </div>
            </div>
          </div>
        </a-card>
      </a-col>
      <a-col :xs="24" :sm="8" :md="8">
        <a-card size="small" class="stat-card">
          <div class="stat-inner">
            <div class="stat-icon" style="background: rgba(255, 77, 79, 0.12); color: #ff4d4f"><DollarOutlined /></div>
            <div class="stat-meta">
              <div class="stat-label">{{ $t('Total_Due') }}</div>
              <div class="stat-value" :style="{ color: totals.due > 0 ? '#ff4d4f' : undefined }">
                <a-spin v-if="cardsLoading" size="small" />
                <template v-else>{{ money(totals.due) }}</template>
              </div>
            </div>
          </div>
        </a-card>
      </a-col>
      <a-col :xs="24" :sm="8" :md="8">
        <a-card size="small" class="stat-card">
          <div class="stat-inner">
            <div class="stat-icon" style="background: rgba(250, 173, 20, 0.14); color: #faad14"><RollbackOutlined /></div>
            <div class="stat-meta">
              <div class="stat-label">{{ $t('Total_Purchase_Return_Due') }}</div>
              <div class="stat-value" :style="{ color: totals.returnDue > 0 ? '#faad14' : undefined }">
                <a-spin v-if="cardsLoading" size="small" />
                <template v-else>{{ money(totals.returnDue) }}</template>
              </div>
            </div>
          </div>
        </a-card>
      </a-col>
    </a-row>

    <DataTable :crud="crud" :columns="columns" selectable>
      <template #bodyCell="{ column, record }">
        <template v-if="MONEY_KEYS.includes(column.key)">{{ money(record[column.key]) }}</template>
        <template v-else-if="column.key === 'due'">
          <strong :style="{ color: Number(record.due) > 0 ? '#ff4d4f' : undefined }">
            {{ money(record.due) }}
          </strong>
        </template>
        <template v-else-if="column.key === 'return_Due'">
          <strong :style="{ color: Number(record.return_Due) > 0 ? '#faad14' : undefined }">
            {{ money(record.return_Due) }}
          </strong>
        </template>
        <template v-else-if="column.key === 'net_balance'">
          <strong :style="{ color: Number(record.net_balance) > 0 ? '#ff4d4f' : undefined }">
            {{ money(record.net_balance) }}
          </strong>
        </template>
        <template v-else-if="column.key === 'actions'">
          <a-dropdown :trigger="['click']">
            <a-button type="text" size="small">
              <template #icon><MoreOutlined style="font-size: 18px" /></template>
            </a-button>
            <template #overlay>
              <a-menu @click="({ key }) => onAction(key, record)">
                <!-- Opening balance counts: pay_supplier_due allocates to it first. -->
                <a-menu-item
                  v-if="(Number(record.due) || 0) + (Number(record.opening_balance) || 0) > 0 && auth.can('pay_supplier_due')"
                  key="pay-due"
                >
                  <DollarOutlined /> {{ $t('pay_all_purchase_due_at_a_time') }}
                </a-menu-item>
                <a-menu-item v-if="Number(record.return_Due) > 0 && auth.can('pay_purchase_return_due')" key="pay-return">
                  <DollarOutlined /> {{ $t('pay_all_purchase_return_due_at_a_time') }}
                </a-menu-item>
                <a-menu-item v-if="walletEnabled && auth.can('supplier_wallet_view')" key="wallet">
                  <WalletOutlined /> {{ $t('Supplier_Wallet') }}
                </a-menu-item>
                <a-menu-item v-if="auth.can('Suppliers_edit')" key="opening-balance">
                  <CalculatorOutlined /> {{ $t('Adjust_Opening_Balance') }}
                </a-menu-item>
                <a-menu-item v-if="auth.can('Suppliers_edit')" key="edit">
                  <EditOutlined /> {{ $t('Edit_Provider') }}
                </a-menu-item>
                <a-menu-divider v-if="auth.can('Suppliers_delete')" />
                <a-menu-item v-if="auth.can('Suppliers_delete')" key="delete" danger>
                  <DeleteOutlined /> {{ $t('Delete_Provider') }}
                </a-menu-item>
              </a-menu>
            </template>
          </a-dropdown>
        </template>
      </template>
    </DataTable>

    <a-modal
      v-model:open="payOpen"
      :title="payKind === 'due' ? $t('Pay_Due') : $t('Total_Purchase_Return_Due')"
      width="720px"
      :footer="null"
    >
      <a-alert
        v-if="payKind === 'due'"
        type="info" show-icon style="margin-bottom: 16px"
        :message="`${$t('Payment_Allocation')}: ${$t('Payment_Allocation_description')}`"
      />
      <a-row :gutter="16" style="margin-bottom: 16px">
        <template v-if="payKind === 'due'">
          <a-col :span="8">
            <a-statistic :title="$t('Opening_Balance')" :value="money(openingBalance)" />
          </a-col>
          <a-col :span="8">
            <a-statistic :title="$t('Due')" :value="money(dueAmount)" />
          </a-col>
          <a-col :span="8">
            <a-statistic
              :title="$t('Total_Due')" :value="money(totalDue)"
              :value-style="{ color: totalDue > 0 ? '#ff4d4f' : '#52c41a' }"
            />
          </a-col>
        </template>
        <a-col v-else :span="24">
          <a-statistic
            :title="$t('Total_Purchase_Return_Due')" :value="money(totalDue)"
            :value-style="{ color: totalDue > 0 ? '#ff4d4f' : '#52c41a' }"
          />
        </a-col>
      </a-row>

      <a-form layout="vertical">
        <a-form-item :label="$t('Supplier')">
          <a-input :value="payTarget?.name" disabled />
        </a-form-item>
        <a-form-item :label="$t('Paying_Amount') + ' *'">
          <a-input-number v-model:value="payForm.amount" :min="0" :max="totalDue" style="width: 100%" />
          <div class="muted">{{ $t('Maximum_payment') }}: <strong>{{ money(totalDue) }}</strong></div>
        </a-form-item>
        <!-- Supplier Wallet tender: settle the due from the prepaid balance. -->
        <a-form-item v-if="payKind === 'due' && walletEnabled">
          <a-space align="start">
            <a-switch v-model:checked="payForm.from_wallet" />
            <div>
              <div>{{ $t('SW_Pay_From_Wallet') }}</div>
              <div class="muted">
                {{ $t('SW_Pay_From_Wallet_Help') }} — {{ $t('SW_Wallet_Balance') }}:
                <strong :style="{ color: Number(payTarget?.wallet_balance) < 0 ? '#ff4d4f' : '#52c41a' }">{{ money(payTarget?.wallet_balance) }}</strong>
              </div>
            </div>
          </a-space>
        </a-form-item>
        <template v-if="!(payKind === 'due' && payForm.from_wallet)">
          <a-form-item :label="$t('Paymentchoice') + ' *'">
            <a-select
              v-model:value="payForm.payment_method_id" :placeholder="$t('PleaseSelect')"
              :options="opts('payment_methods')"
            />
          </a-form-item>
          <a-form-item :label="$t('Account')">
            <a-select
              v-model:value="payForm.account_id" allow-clear :placeholder="$t('Choose_Account')"
              :options="accountOptions"
            />
          </a-form-item>
        </template>
        <a-form-item :label="$t('Please_provide_any_details')">
          <a-textarea v-model:value="payForm.notes" :rows="3" />
        </a-form-item>
        <a-button type="primary" :loading="paying" @click="submitPay">{{ $t('submit') }}</a-button>
      </a-form>
    </a-modal>

    <!-- Adjust opening balance -->
    <a-modal
      v-model:open="obOpen"
      :title="$t('Adjust_Opening_Balance')"
      :confirm-loading="obSaving"
      :ok-text="$t('submit')"
      :cancel-text="$t('Delete_cancelButtonText')"
      @ok="submitOpeningBalance"
    >
      <a-form layout="vertical" style="margin-top: 8px">
        <a-form-item :label="$t('Supplier')">
          <a-input :value="obForm.provider_name" disabled />
        </a-form-item>
        <a-form-item :label="$t('Opening_Balance')">
          <a-input :value="money(obForm.current)" disabled />
        </a-form-item>
        <a-form-item :label="$t('Mode') || 'Mode'">
          <a-radio-group v-model:value="obForm.mode" button-style="solid">
            <a-radio-button value="add">{{ $t('Add') }}</a-radio-button>
            <a-radio-button value="subtract">{{ $t('Subtract') || 'Subtract' }}</a-radio-button>
            <a-radio-button value="set">{{ $t('Set') || 'Set' }}</a-radio-button>
          </a-radio-group>
        </a-form-item>
        <a-form-item :label="$t('Amount')" required>
          <a-input-number v-model:value="obForm.amount" style="width: 100%" :min="0" />
        </a-form-item>
        <a-alert
          v-if="obForm.amount !== null && obForm.amount !== ''"
          type="info" show-icon
          :message="`${$t('Opening_Balance')}: ${money(obForm.current)} → ${money(obPreview)}`"
        />
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * Mirror of Customers (see people/Customers.vue). Endpoints: GET providers →
 * { providers, totalRows, accounts, payment_methods }; POST pay_supplier_due /
 * pay_purchase_return_due { provider_id, amount, notes, payment_method_id,
 * account_id }. Pay-due button additionally needs the `pay_supplier_due`
 * permission (legacy gates it). CSV import stays in legacy.
 */
import { ref, computed, watch, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { useRouter } from 'vue-router';
import {
  PlusOutlined, EditOutlined, DeleteOutlined, DollarOutlined, RollbackOutlined,
  EyeOutlined, MoreOutlined, ShopOutlined, CalculatorOutlined, WalletOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import { useAuthStore } from '../../stores/auth';
import http from '../../lib/http';

const { t } = useI18n();
const { money, roundMoney } = useFormat();
const auth = useAuthStore();
const router = useRouter();

const MONEY_KEYS = ['credit_limit', 'opening_balance', 'wallet_balance', 'net_after_wallet'];

// Supplier Wallet: the list payload says whether the feature is on; the
// wallet columns, the ledger action and the "pay from wallet" tender follow it.
const walletEnabled = computed(() => !!crud.payload.value?.supplier_wallet?.enabled);

// Payload: { providers, totalRows, accounts, payment_methods }
const crud = useCrudTable('providers', { rowsKey: 'providers' });

const opts = key =>
  (crud.payload.value?.[key] || []).map(x => ({ value: x.id, label: x.name }));
// Accounts come back with `account_name`, not `name`.
const accountOptions = computed(() =>
  (crud.payload.value?.accounts || []).map(a => ({ value: a.id, label: a.account_name }))
);

const columns = computed(() => [
  { title: t('Action'), key: 'actions', width: 70, align: 'center', fixed: 'left' },
  { title: t('SupplierCode'), dataIndex: 'code', key: 'code', sorter: true },
  { title: t('SupplierName'), dataIndex: 'name', key: 'name', sorter: true },
  { title: t('Phone'), dataIndex: 'phone', key: 'phone' },
  { title: t('Email'), dataIndex: 'email', key: 'email' },
  { title: t('City'), dataIndex: 'city', key: 'city' },
  { title: t('Tax_Number'), dataIndex: 'tax_number', key: 'tax_number' },
  { title: t('Credit_Limit'), key: 'credit_limit', dataIndex: 'credit_limit', align: 'right', exportValue: r => money(r.credit_limit) },
  { title: t('Opening_Balance'), key: 'opening_balance', dataIndex: 'opening_balance', align: 'right', exportValue: r => money(r.opening_balance) },
  { title: t('Due'), key: 'due', dataIndex: 'due', sorter: true, align: 'right', exportValue: r => money(r.due) },
  { title: t('Total_Purchase_Return_Due'), key: 'return_Due', dataIndex: 'return_Due', align: 'right', exportValue: r => money(r.return_Due) },
  // Opening balance + purchase due - return due, as the API computes it.
  { title: t('Total_Due'), key: 'net_balance', dataIndex: 'net_balance', align: 'right', exportValue: r => money(r.net_balance) },
  // Prepaid balance held with the supplier, and the due once it is applied.
  ...(walletEnabled.value ? [
    { title: t('SW_Wallet_Balance'), key: 'wallet_balance', dataIndex: 'wallet_balance', align: 'right', exportValue: r => money(r.wallet_balance) },
    { title: t('SW_Net_After_Wallet'), key: 'net_after_wallet', dataIndex: 'net_after_wallet', align: 'right', exportValue: r => money(r.net_after_wallet) },
  ] : []),
]);

/* ------------------------------------------------- summary card totals */
// Sums across every supplier matching the current search (not just the current
// page): one unpaginated fetch that reuses the same list endpoint the exports use.
const supplierCount = computed(() => crud.total.value);
const totals = ref({ due: 0, returnDue: 0 });
const totalsLoading = ref(false);
// Keeps the stat cards in a skeleton state from first mount until both the row
// fetch and the totals fetch have completed — including direct navigation.
const initialLoading = ref(true);
const cardsLoading = computed(() => initialLoading.value || crud.loading.value || totalsLoading.value);

// Sequence guard: a slow response for an older search must not overwrite the
// totals of a newer one.
let totalsSeq = 0;
async function loadTotals() {
  const seq = ++totalsSeq;
  totalsLoading.value = true;
  try {
    const data = await http.get('providers', { page: 1, limit: -1, search: crud.search.value, SortField: 'id', SortType: 'desc' });
    if (seq !== totalsSeq) return;
    const rows = data.providers || [];
    totals.value = {
      // Opening balance is owed too — same basis as the pay-due modal's total.
      due: rows.reduce((s, r) => s + (Number(r.opening_balance) || 0) + (Number(r.due) || 0), 0),
      returnDue: rows.reduce((s, r) => s + (Number(r.return_Due) || 0), 0),
    };
  } catch (e) {
    // Leave the last-known totals in place on a transient failure.
  } finally {
    if (seq === totalsSeq) totalsLoading.value = false;
  }
}

// Refresh the sums after every row fetch whose search term or row count
// differs from the last one (typed search, add, delete, bulk delete) —
// pagination and sorting alone leave both intact. Keyed on the fetched rows so
// the totals request always carries the search the table actually used. The
// first load is handled explicitly in onMounted, so skip it here.
let totalsKey = '';
watch(() => crud.rows.value, () => {
  const key = `${crud.search.value}|${crud.total.value}`;
  if (key === totalsKey) return;
  totalsKey = key;
  if (!initialLoading.value) loadTotals();
});

/* --------------------------------------------------- row action dispatch */
function onAction(key, record) {
  const go = {
    'pay-due': () => openPay(record, 'due'),
    'pay-return': () => openPay(record, 'return'),
    'opening-balance': () => openOpeningBalance(record),
    wallet: () => router.push(`/suppliers/${record.id}/ledger`),
    edit: () => router.push(`/suppliers/${record.id}/edit`),
    delete: () => crud.remove(record),
  };
  go[key]?.();
}

/* ------------------------------------------------------------- payments */
const payOpen = ref(false);
const payKind = ref('due');
const paying = ref(false);
const payTarget = ref(null);
const payForm = ref({ amount: 0, payment_method_id: undefined, account_id: undefined, notes: '' });

// Opening balance is paid first, then purchases — so the due total for a
// payment is opening_balance + purchase due (matches pay_supplier_due). Returns
// have no opening-balance component.
const openingBalance = computed(() => Number(payTarget.value?.opening_balance) || 0);
const dueAmount = computed(() =>
  Number(payKind.value === 'due' ? payTarget.value?.due : payTarget.value?.return_Due) || 0
);
// Rounded: this is the paying-amount input's :max and prefill — a raw float
// sum would surface as e.g. 121.99999999999994 in the field.
const totalDue = computed(() =>
  roundMoney(payKind.value === 'due' ? openingBalance.value + dueAmount.value : dueAmount.value)
);

function openPay(record, kind) {
  payTarget.value = record;
  payKind.value = kind;
  payForm.value = { amount: totalDue.value, payment_method_id: undefined, account_id: undefined, notes: '', from_wallet: false };
  payOpen.value = true;
}

async function submitPay() {
  if (!(Number(payForm.value.amount) > 0)) {
    message.warning(t('Amount'));
    return;
  }
  const fromWallet = payKind.value === 'due' && walletEnabled.value && !!payForm.value.from_wallet;
  if (!fromWallet && !payForm.value.payment_method_id) {
    message.warning(t('Paymentchoice'));
    return;
  }
  paying.value = true;
  try {
    const endpoint = payKind.value === 'due' ? 'pay_supplier_due' : 'pay_purchase_return_due';
    await http.post(endpoint, {
      provider_id: payTarget.value.id,
      amount: payForm.value.amount,
      notes: payForm.value.notes,
      payment_method_id: fromWallet ? null : payForm.value.payment_method_id,
      account_id: fromWallet ? null : payForm.value.account_id,
      from_wallet: fromWallet ? 1 : 0,
    });
    message.success(t('Successfully_Created'));
    payOpen.value = false;
    crud.fetchRows();
    loadTotals(); // dues changed but the supplier count didn't — refresh sums
  } catch (e) {
    // Wallet refusals (frozen, insufficient balance) arrive as 422 with a code.
    const code = e?.data?.code;
    const key = code ? 'SW_Err_' + code : '';
    message.error((key && t(key) !== key) ? t(key) : (e?.data?.message || t('InvalidData')));
  } finally {
    paying.value = false;
  }
}

/* ------------------------------------------- adjust opening balance */
const obOpen = ref(false);
const obSaving = ref(false);
const obForm = ref({ provider_id: null, provider_name: '', current: 0, mode: 'add', amount: null });

const obPreview = computed(() => {
  const amt = parseFloat(obForm.value.amount) || 0;
  if (obForm.value.mode === 'add') return obForm.value.current + Math.abs(amt);
  if (obForm.value.mode === 'subtract') return obForm.value.current - Math.abs(amt);
  return amt;
});

function openOpeningBalance(record) {
  obForm.value = {
    provider_id: record.id, provider_name: record.name,
    current: parseFloat(record.opening_balance) || 0, mode: 'add', amount: null,
  };
  obOpen.value = true;
}
async function submitOpeningBalance() {
  const amount = parseFloat(obForm.value.amount);
  if (isNaN(amount)) {
    message.warning(t('Amount'));
    return;
  }
  obSaving.value = true;
  try {
    await http.post(`providers/${obForm.value.provider_id}/adjust-opening-balance`, { mode: obForm.value.mode, amount });
    message.success(t('Successfully_Updated'));
    obOpen.value = false;
    crud.fetchRows();
    loadTotals();
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    obSaving.value = false;
  }
}

onMounted(async () => {
  await crud.fetchRows();
  totalsKey = `${crud.search.value}|${crud.total.value}`;
  await loadTotals();
  initialLoading.value = false;
});
</script>

<style scoped>
.stat-card {
  border-radius: 10px;
}
.stat-inner {
  display: flex;
  align-items: center;
  gap: 12px;
}
.stat-icon {
  width: 44px;
  height: 44px;
  border-radius: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 20px;
  flex: none;
}
.stat-meta {
  min-width: 0;
}
/* opacity + inherit so the label/value follow the card's theme text colour. */
.stat-label {
  opacity: 0.65;
  font-size: 13px;
  margin-bottom: 2px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.stat-value {
  font-size: 20px;
  font-weight: 700;
  white-space: nowrap;
}
@media (max-width: 575px) {
  .stat-inner {
    gap: 8px;
  }
  .stat-icon {
    width: 36px;
    height: 36px;
    font-size: 16px;
  }
  .stat-value {
    font-size: 16px;
  }
}
</style>
