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
      <a-form-item :label="$t('Supplier')">
        <a-input :value="provider?.name" disabled />
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
 * Supplier wallet money moves — one modal, three modes:
 *   top_up  POST supplier_wallets/{id}/top-up   (cash → wallet)
 *   refund  POST supplier_wallets/{id}/refund   (wallet → cash)
 *   adjust  POST supplier_wallets/{id}/adjust   (credit|debit, no cash leg)
 * Emits `done` with the server reply ({ transaction, balance }) so the parent
 * can refresh. 422 replies carry `code` (wallet_insufficient_balance,
 * wallet_frozen…) which maps to an SW_Err_* translation.
 */
import { ref, computed, watch } from 'vue';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import dayjs from 'dayjs';
import { useFormat } from '../composables/useFormat';
import http from '../lib/http';

const props = defineProps({
  open: { type: Boolean, default: false },
  mode: { type: String, default: 'top_up' }, // top_up | refund | adjust
  provider: { type: Object, default: null },  // { id, name }
  balance: { type: Number, default: 0 },
  accounts: { type: Array, default: () => [] },        // [{ id, account_name }]
  paymentMethods: { type: Array, default: () => [] },  // [{ id, name }]
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
  top_up: t('SW_Top_Up'), refund: t('SW_Refund'), adjust: t('SW_Adjust'),
}[props.mode] || t('SW_Top_Up')));

const hint = computed(() => ({
  top_up: 'SW_Top_Up_Help', refund: 'SW_Refund_Help', adjust: 'SW_Adjust_Help',
}[props.mode] || 'SW_Top_Up_Help'));

const endpoint = computed(() => ({ top_up: 'top-up', refund: 'refund', adjust: 'adjust' }[props.mode]));

const methodOptions = computed(() => props.paymentMethods.map(m => ({ value: m.id, label: m.name })));
const accountOptions = computed(() => props.accounts.map(a => ({ value: a.id, label: a.account_name })));

const preview = computed(() => {
  const amt = Number(form.value.amount) || 0;
  const up = props.mode === 'top_up' || (props.mode === 'adjust' && form.value.type === 'credit');
  return +(props.balance + (up ? amt : -amt)).toFixed(2);
});

function errorText(e) {
  const code = e?.data?.code;
  if (code) {
    const key = 'SW_Err_' + code;
    const txt = t(key);
    if (txt && txt !== key) return txt;
  }
  return e?.data?.message || t('InvalidData');
}

async function submit() {
  const amount = Number(form.value.amount) || 0;
  if (amount <= 0) {
    message.warning(t('Amount'));
    return;
  }
  if (props.mode === 'adjust' && !String(form.value.note || '').trim()) {
    message.warning(t('Reason'));
    return;
  }
  saving.value = true;
  try {
    const body = {
      amount,
      date: form.value.date,
      note: form.value.note,
      payment_method_id: form.value.payment_method_id || null,
      account_id: form.value.account_id || null,
    };
    if (props.mode === 'adjust') body.type = form.value.type;
    const res = await http.post(`supplier_wallets/${props.provider.id}/${endpoint.value}`, body);
    message.success(t('Successfully_Created'));
    emit('update:open', false);
    emit('done', res);
  } catch (e) {
    message.error(errorText(e));
  } finally {
    saving.value = false;
  }
}
</script>
