<template>
  <a-modal
    :open="open"
    :title="title"
    :confirm-loading="saving"
    :ok-text="$t('submit')"
    :cancel-text="$t('Delete_cancelButtonText')"
    @update:open="v => $emit('update:open', v)"
    @ok="submit"
  >
    <a-form layout="vertical" style="margin-top: 8px">
      <a-form-item :label="$t('Customer')">
        <a-input :value="client?.name" disabled />
      </a-form-item>

      <a-row :gutter="12">
        <a-col :span="12">
          <a-statistic :title="$t('SW_Wallet_Balance')" :value="money(balance)" :value-style="{ color: balance < 0 ? '#ff4d4f' : '#52c41a', fontSize: '18px' }" />
        </a-col>
        <a-col :span="12">
          <a-statistic :title="$t('SW_Balance_After')" :value="money(preview)" :value-style="{ color: preview < 0 ? '#ff4d4f' : '#1677ff', fontSize: '18px' }" />
        </a-col>
      </a-row>

      <a-alert type="info" show-icon style="margin: 12px 0" :message="$t(hint)" />
      <a-alert
        v-if="status === 'frozen'"
        type="error" show-icon style="margin-bottom: 12px"
        :message="$t('CW_Err_wallet_frozen')"
      />

      <a-form-item v-if="mode === 'adjust'" :label="$t('Type')">
        <a-radio-group v-model:value="form.type" button-style="solid">
          <a-radio-button value="credit">{{ $t('SW_Adjust_Credit') }}</a-radio-button>
          <a-radio-button value="debit">{{ $t('SW_Adjust_Debit') }}</a-radio-button>
        </a-radio-group>
      </a-form-item>

      <a-row :gutter="12">
        <a-col :span="12">
          <a-form-item :label="$t('Amount') + ' *'">
            <a-input-number v-model:value="form.amount" style="width: 100%" :min="0" placeholder="0.00" />
          </a-form-item>
        </a-col>
        <a-col :span="12">
          <a-form-item :label="$t('date')">
            <a-date-picker v-model:value="form.date" value-format="YYYY-MM-DD" style="width: 100%" />
          </a-form-item>
        </a-col>
      </a-row>

      <a-row v-if="mode !== 'adjust'" :gutter="12">
        <a-col :span="12">
          <a-form-item :label="$t('Paymentchoice')">
            <a-select v-model:value="form.payment_method_id" allow-clear :placeholder="$t('PleaseSelect')" :options="methodOptions" />
          </a-form-item>
        </a-col>
        <a-col :span="12">
          <a-form-item :label="$t('Account')">
            <a-select v-model:value="form.account_id" allow-clear :placeholder="$t('Choose_Account')" :options="accountOptions" />
          </a-form-item>
        </a-col>
      </a-row>

      <a-form-item :label="mode === 'adjust' ? $t('Reason') + ' *' : $t('Note')">
        <a-textarea v-model:value="form.note" :rows="2" :maxlength="500" />
      </a-form-item>
    </a-form>
  </a-modal>
</template>

<script setup>
/**
 * Customer wallet money moves — one modal, three modes:
 *   top_up  POST customer_wallets/{id}/top-up   (customer hands over cash → wallet)
 *   refund  POST customer_wallets/{id}/refund   (wallet → cash back to the customer)
 *   adjust  POST customer_wallets/{id}/adjust   (credit|debit, no cash leg, note required)
 * Emits `done` with the server reply ({ transaction, balance }) so the parent
 * can refresh. 422 replies carry `code` (wallet_insufficient_balance,
 * wallet_frozen…) which maps to a CW_Err_* translation.
 */
import { ref, computed, watch } from 'vue';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import dayjs from 'dayjs';
import { useFormat } from '../composables/useFormat';
import { customerWalletError } from '../composables/useCustomerWallet';
import http from '../lib/http';

const props = defineProps({
  open: { type: Boolean, default: false },
  mode: { type: String, default: 'top_up' }, // top_up | refund | adjust
  client: { type: Object, default: null },    // { id, name }
  balance: { type: Number, default: 0 },
  status: { type: String, default: 'active' }, // active | frozen
  accounts: { type: Array, default: () => [] },        // [{ id, account_name }]
  paymentMethods: { type: Array, default: () => [] },  // [{ id, name }] (wallet tender excluded server-side)
});
const emit = defineEmits(['update:open', 'done']);

const { t } = useI18n();
const { money } = useFormat();

const saving = ref(false);
const form = ref(blank());

function blank() {
  return { amount: null, date: dayjs().format('YYYY-MM-DD'), payment_method_id: undefined, account_id: undefined, note: '', type: 'credit' };
}
watch(() => props.open, v => { if (v) form.value = blank(); });

const title = computed(() => ({
  top_up: t('SW_Top_Up'), refund: t('CW_Cash_Refund'), adjust: t('SW_Adjust'),
}[props.mode] || t('SW_Top_Up')));

const hint = computed(() => ({
  top_up: 'CW_Top_Up_Help', refund: 'CW_Refund_Help', adjust: 'SW_Adjust_Help',
}[props.mode] || 'CW_Top_Up_Help'));

const endpoint = computed(() => ({ top_up: 'top-up', refund: 'refund', adjust: 'adjust' }[props.mode]));

const methodOptions = computed(() => props.paymentMethods.map(m => ({ value: m.id, label: m.name })));
const accountOptions = computed(() => props.accounts.map(a => ({ value: a.id, label: a.account_name })));

const preview = computed(() => {
  const amt = Number(form.value.amount) || 0;
  const up = props.mode === 'top_up' || (props.mode === 'adjust' && form.value.type === 'credit');
  return +(props.balance + (up ? amt : -amt)).toFixed(2);
});

async function submit() {
  const amount = Number(form.value.amount) || 0;
  if (amount <= 0) {
    message.warning(t('CW_Err_wallet_invalid_amount'));
    return;
  }
  if (props.mode === 'adjust' && !String(form.value.note || '').trim()) {
    message.warning(t('CW_Reason_Required'));
    return;
  }
  saving.value = true;
  try {
    const body = {
      amount,
      date: form.value.date,
      note: form.value.note,
    };
    if (props.mode === 'adjust') {
      body.type = form.value.type;
    } else {
      body.payment_method_id = form.value.payment_method_id || null;
      body.account_id = form.value.account_id || null;
    }
    const res = await http.post(`customer_wallets/${props.client.id}/${endpoint.value}`, body);
    message.success(t('Successfully_Created'));
    emit('update:open', false);
    emit('done', res);
  } catch (e) {
    message.error(customerWalletError(e));
  } finally {
    saving.value = false;
  }
}
</script>
