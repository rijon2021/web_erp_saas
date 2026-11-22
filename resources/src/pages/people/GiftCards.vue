<template>
  <div class="page">
    <PageHeader :title="$t('CW_Gift_Cards')" :breadcrumb="[$t('People'), $t('CW_Gift_Cards')]">
      <template #actions>
        <a-button v-if="auth.can('customer_wallet_view')" @click="$router.push('/customers/wallets')">
          <template #icon><WalletOutlined /></template>
          {{ $t('CW_Customer_Wallets') }}
        </a-button>
        <a-button type="primary" @click="openCreate">
          <template #icon><PlusOutlined /></template>
          {{ $t('CW_Issue_Gift_Cards') }}
        </a-button>
      </template>
    </PageHeader>

    <a-alert
      v-if="payload && payload.migrated === false"
      type="error" show-icon style="margin-bottom: 16px"
      :message="$t('CW_Not_Migrated')"
    />
    <a-alert
      v-else-if="payload && payload.enabled === false"
      type="warning" show-icon style="margin-bottom: 16px"
      :message="$t('CW_Gift_Cards_Disabled_Hint')"
    />

    <div class="filters">
      <a-select v-model:value="filters.status" style="width: 160px" allow-clear :placeholder="$t('Status')" @change="crud.reload()">
        <a-select-option v-for="s in STATUSES" :key="s" :value="s">{{ statusLabel(s) }}</a-select-option>
      </a-select>
      <a-select v-model:value="filters.type" style="width: 180px" allow-clear :placeholder="$t('Type')" @change="crud.reload()">
        <a-select-option v-for="ty in TYPES" :key="ty" :value="ty">{{ typeLabel(ty) }}</a-select-option>
      </a-select>
    </div>

    <DataTable :crud="crud" :columns="columns">
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'code'">
          <span class="code">{{ record.code }}</span>
          <a-tooltip :title="$t('Copy')">
            <a-button type="text" size="small" @click="copy(record.code)">
              <template #icon><CopyOutlined /></template>
            </a-button>
          </a-tooltip>
        </template>
        <template v-else-if="column.key === 'type'">
          <a-tag :color="TYPE_COLORS[record.type] || 'default'">{{ typeLabel(record.type) }}</a-tag>
        </template>
        <template v-else-if="column.key === 'amount'">{{ money(record.amount) }}</template>
        <template v-else-if="column.key === 'balance'">
          <strong :style="{ color: Number(record.balance) > 0 ? '#52c41a' : undefined }">{{ money(record.balance) }}</strong>
        </template>
        <template v-else-if="column.key === 'status'">
          <a-tag :color="STATUS_COLORS[record.status] || 'default'">{{ statusLabel(record.status) }}</a-tag>
        </template>
        <template v-else-if="column.key === 'expires_at'">{{ record.expires_at || '-' }}</template>
        <template v-else-if="column.key === 'redeemer'">
          <template v-if="record.redeemed_by">
            <a v-if="auth.can('customer_wallet_view')" @click="$router.push(`/customers/${record.redeemed_by}/wallet`)">{{ record.redeemer_name || ('#' + record.redeemed_by) }}</a>
            <span v-else>{{ record.redeemer_name || ('#' + record.redeemed_by) }}</span>
            <div v-if="record.redeemed_at" class="muted small">{{ dateTime(record.redeemed_at) }}</div>
          </template>
          <span v-else class="muted">-</span>
        </template>
        <template v-else-if="column.key === 'created_at'">{{ record.created_at ? dateTime(record.created_at) : '-' }}</template>
        <template v-else-if="column.key === 'actions'">
          <a-dropdown :trigger="['click']">
            <a-button type="text" size="small">
              <template #icon><MoreOutlined style="font-size: 18px" /></template>
            </a-button>
            <template #overlay>
              <a-menu @click="({ key }) => onAction(key, record)">
                <a-menu-item key="copy"><CopyOutlined /> {{ $t('CW_Copy_Code') }}</a-menu-item>
                <a-menu-item key="edit"><EditOutlined /> {{ $t('Edit') }}</a-menu-item>
                <template v-if="record.status !== 'redeemed'">
                  <a-menu-divider />
                  <a-menu-item key="delete" danger><DeleteOutlined /> {{ $t('Delete') }}</a-menu-item>
                </template>
              </a-menu>
            </template>
          </a-dropdown>
        </template>
      </template>
    </DataTable>

    <!-- Create / edit -->
    <a-modal
      v-model:open="formOpen"
      :title="editing ? $t('CW_Edit_Gift_Card') : $t('CW_Issue_Gift_Cards')"
      :confirm-loading="saving"
      :ok-text="$t('submit')"
      :cancel-text="$t('Delete_cancelButtonText')"
      width="620px"
      @ok="submit"
    >
      <a-form layout="vertical" style="margin-top: 8px">
        <a-alert
          v-if="lockedMoney"
          type="info" show-icon style="margin-bottom: 12px"
          :message="$t('CW_Redeemed_Card_Locked')"
        />

        <a-form-item v-if="!editing" :label="$t('CW_Issue_Mode')">
          <a-radio-group v-model:value="form.mode" button-style="solid">
            <a-radio-button value="single">{{ $t('CW_Single_Card') }}</a-radio-button>
            <a-radio-button value="batch">{{ $t('CW_Batch_Cards') }}</a-radio-button>
          </a-radio-group>
        </a-form-item>

        <a-row :gutter="12">
          <a-col :xs="24" :sm="12">
            <a-form-item :label="$t('Type') + ' *'">
              <a-select v-model:value="form.type" :disabled="lockedMoney">
                <a-select-option v-for="ty in TYPES" :key="ty" :value="ty">{{ typeLabel(ty) }}</a-select-option>
              </a-select>
            </a-form-item>
          </a-col>
          <a-col :xs="24" :sm="12">
            <a-form-item :label="$t('Amount') + ' *'">
              <a-input-number v-model:value="form.amount" style="width: 100%" :min="0.01" placeholder="0.00" :disabled="lockedMoney" />
            </a-form-item>
          </a-col>

          <a-col v-if="form.mode === 'single' || editing" :xs="24" :sm="12">
            <a-form-item :label="$t('CW_Gift_Card_Code')" :extra="lockedMoney ? '' : $t('CW_Code_Auto_Help')">
              <a-input
                v-model:value="form.code" :maxlength="60" allow-clear :disabled="lockedMoney"
                style="text-transform: uppercase; font-family: monospace"
              />
            </a-form-item>
          </a-col>
          <template v-else>
            <a-col :xs="12" :sm="6">
              <a-form-item :label="$t('Quantity') + ' *'">
                <a-input-number v-model:value="form.quantity" style="width: 100%" :min="1" :max="500" :precision="0" />
              </a-form-item>
            </a-col>
            <a-col :xs="12" :sm="6">
              <a-form-item :label="$t('CW_Code_Prefix')">
                <a-input v-model:value="form.prefix" :maxlength="10" style="text-transform: uppercase; font-family: monospace" />
              </a-form-item>
            </a-col>
          </template>

          <a-col :xs="24" :sm="12">
            <a-form-item :label="$t('Name')">
              <a-input v-model:value="form.name" :maxlength="120" :placeholder="$t('CW_Card_Name_Ph')" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :sm="12">
            <a-form-item :label="$t('Status')">
              <a-select v-model:value="form.status" :disabled="lockedMoney">
                <a-select-option value="active">{{ $t('Active') }}</a-select-option>
                <a-select-option value="disabled">{{ statusLabel('disabled') }}</a-select-option>
              </a-select>
            </a-form-item>
          </a-col>
          <a-col :xs="24" :sm="12">
            <a-form-item :label="$t('Expires_At')">
              <a-date-picker v-model:value="form.expires_at" value-format="YYYY-MM-DD" style="width: 100%" :disabled="lockedMoney" />
            </a-form-item>
          </a-col>
          <a-col :span="24">
            <a-form-item :label="$t('Note')">
              <a-textarea v-model:value="form.note" :rows="2" :maxlength="255" />
            </a-form-item>
          </a-col>
        </a-row>
      </a-form>
    </a-modal>

    <!-- Codes created by a batch -->
    <a-modal v-model:open="createdOpen" :title="$t('CW_Codes_Created')" :footer="null" width="520px">
      <p class="muted">{{ createdCodes.length }} × {{ $t('CW_Gift_Card_Code') }}</p>
      <a-textarea :value="createdCodes.join('\n')" :rows="10" readonly style="font-family: monospace" />
      <a-button style="margin-top: 12px" @click="copy(createdCodes.join('\n'))">
        <template #icon><CopyOutlined /></template>
        {{ $t('CW_Copy_All') }}
      </a-button>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * People → Gift Cards (plan feature customer_wallet, perm gift_cards_manage).
 * GET gift_cards → { gift_cards:[{ id, type, code, name, amount, balance,
 * status (active|redeemed|disabled|expired), expires_at, note, redeemed_by,
 * redeemer_name, redeemed_at, created_at }], totalRows, +flags }.
 * POST gift_cards creates one card (optional custom code) or a batch
 * (quantity 1–500 + prefix, codes generated server-side); PUT edits — a
 * redeemed card only keeps name/note editable; DELETE refuses redeemed cards.
 * A card is redeemed into a customer's wallet from the wallet pages.
 */
import { ref, computed, onMounted, createVNode } from 'vue';
import { message, Modal } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import {
  PlusOutlined, EditOutlined, DeleteOutlined, MoreOutlined, CopyOutlined, WalletOutlined,
  ExclamationCircleOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import { customerWalletError } from '../../composables/useCustomerWallet';
import { useAuthStore } from '../../stores/auth';
import http from '../../lib/http';

const { t } = useI18n();
const { money, dateTime } = useFormat();
const auth = useAuthStore();

const TYPES = ['gift_card', 'voucher', 'store_credit'];
const STATUSES = ['active', 'redeemed', 'disabled', 'expired'];
const TYPE_COLORS = { gift_card: 'magenta', voucher: 'purple', store_credit: 'cyan' };
const STATUS_COLORS = { active: 'success', redeemed: 'blue', disabled: 'default', expired: 'warning' };

const typeLabel = ty => ({ gift_card: t('CW_Type_gift_card'), voucher: t('CW_Type_voucher'), store_credit: t('CW_Type_store_credit') }[ty] || ty);
const statusLabel = s => ({ active: t('Active'), redeemed: t('CW_Redeemed'), disabled: t('CW_Status_Disabled'), expired: t('CW_Status_Expired') }[s] || s);

const filters = ref({ status: undefined, type: undefined });
const crud = useCrudTable('gift_cards', {
  rowsKey: 'gift_cards',
  params: () => ({ status: filters.value.status || '', type: filters.value.type || '' }),
});
const payload = computed(() => crud.payload.value);

const columns = computed(() => [
  { title: t('Action'), key: 'actions', width: 70, align: 'center', fixed: 'left' },
  { title: t('CW_Gift_Card_Code'), dataIndex: 'code', key: 'code' },
  { title: t('Type'), dataIndex: 'type', key: 'type', exportValue: r => typeLabel(r.type) },
  { title: t('Name'), dataIndex: 'name', key: 'name' },
  { title: t('Amount'), dataIndex: 'amount', key: 'amount', align: 'right', exportValue: r => money(r.amount) },
  { title: t('Balance'), dataIndex: 'balance', key: 'balance', align: 'right', exportValue: r => money(r.balance) },
  { title: t('Status'), dataIndex: 'status', key: 'status', exportValue: r => statusLabel(r.status) },
  { title: t('Expires_At'), dataIndex: 'expires_at', key: 'expires_at' },
  { title: t('CW_Redeemed_By'), key: 'redeemer', exportValue: r => r.redeemer_name || '' },
  { title: t('Created_At'), dataIndex: 'created_at', key: 'created_at', defaultHidden: true },
  { title: t('Note'), dataIndex: 'note', key: 'note', defaultHidden: true },
]);

/* ------------------------------------------------------------------ copy */
async function copy(text) {
  try {
    await navigator.clipboard.writeText(String(text || ''));
    message.success(t('Copied'));
  } catch (e) {
    // Insecure origins have no clipboard API: fall back to a hidden textarea.
    const ta = document.createElement('textarea');
    ta.value = String(text || '');
    ta.style.position = 'fixed';
    ta.style.opacity = '0';
    document.body.appendChild(ta);
    ta.select();
    try {
      document.execCommand('copy');
      message.success(t('Copied'));
    } catch (err) {
      message.error(t('InvalidData'));
    } finally {
      document.body.removeChild(ta);
    }
  }
}

/* --------------------------------------------------------- create / edit */
const formOpen = ref(false);
const saving = ref(false);
const editing = ref(null);
const form = ref(blank());
const createdOpen = ref(false);
const createdCodes = ref([]);

function blank() {
  return {
    mode: 'single', type: 'gift_card', amount: null, code: '', quantity: 10, prefix: 'GC',
    name: '', status: 'active', expires_at: null, note: '',
  };
}

// A redeemed card keeps its money fields — only name / note change.
const lockedMoney = computed(() => !!editing.value && editing.value.status === 'redeemed');

function openCreate() {
  editing.value = null;
  form.value = blank();
  formOpen.value = true;
}

function openEdit(record) {
  editing.value = record;
  form.value = {
    mode: 'single',
    type: record.type,
    amount: Number(record.amount) || null,
    code: record.code || '',
    quantity: 1,
    prefix: '',
    name: record.name || '',
    // 'expired' is a presented state of an active card past its date.
    status: record.status === 'disabled' ? 'disabled' : 'active',
    expires_at: record.expires_at || null,
    note: record.note || '',
  };
  formOpen.value = true;
}

async function submit() {
  const f = form.value;
  let body;
  if (lockedMoney.value) {
    body = { name: f.name || null, note: f.note || null };
  } else {
    const amount = Number(f.amount) || 0;
    if (amount <= 0) {
      message.warning(t('CW_Err_wallet_invalid_amount'));
      return;
    }
    body = {
      type: f.type,
      name: f.name || null,
      amount,
      status: f.status || 'active',
      expires_at: f.expires_at || null,
      note: f.note || null,
    };
    const code = String(f.code || '').trim().toUpperCase();
    if (editing.value || f.mode === 'single') {
      if (code && !/^[A-Z0-9_-]+$/.test(code)) {
        message.warning(t('CW_Code_Invalid_Chars'));
        return;
      }
      if (code) body.code = code;
    } else {
      const qty = Math.round(Number(f.quantity) || 0);
      if (qty < 1 || qty > 500) {
        message.warning(t('CW_Quantity_Range'));
        return;
      }
      body.quantity = qty;
      body.prefix = String(f.prefix || '').trim().toUpperCase() || 'GC';
    }
  }

  saving.value = true;
  try {
    if (editing.value) {
      await http.put(`gift_cards/${editing.value.id}`, body);
      message.success(t('Successfully_Updated'));
    } else {
      const res = await http.post('gift_cards', body);
      message.success(t('Successfully_Created'));
      const codes = (res?.gift_cards || []).map(c => c.code);
      if (codes.length > 1) {
        createdCodes.value = codes;
        createdOpen.value = true;
      }
    }
    formOpen.value = false;
    crud.fetchRows();
  } catch (e) {
    message.error(customerWalletError(e));
  } finally {
    saving.value = false;
  }
}

/* ---------------------------------------------------------------- delete */
function remove(record) {
  Modal.confirm({
    title: t('Delete_Title'),
    icon: createVNode(ExclamationCircleOutlined),
    content: `${t('Delete_Text')} (${record.code})`,
    okText: t('Delete_confirmButtonText'),
    okType: 'danger',
    cancelText: t('Delete_cancelButtonText'),
    async onOk() {
      try {
        await http.delete(`gift_cards/${record.id}`);
        message.success(t('Deleted_in_successfully'));
        crud.fetchRows();
      } catch (e) {
        message.error(customerWalletError(e));
      }
    },
  });
}

function onAction(key, record) {
  if (key === 'copy') return copy(record.code);
  if (key === 'edit') return openEdit(record);
  if (key === 'delete') return remove(record);
}

onMounted(() => crud.fetchRows());
</script>

<style scoped>
.filters {
  display: flex;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
  margin-bottom: 12px;
}
.code {
  font-family: monospace;
  font-weight: 600;
  letter-spacing: 0.5px;
}
.muted { color: rgba(var(--ink-rgb), 0.45); }
.small { font-size: 12px; }
</style>
