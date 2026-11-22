<template>
  <a-modal
    :open="open"
    :title="`${editing ? $t('EditPayment') : $t('AddPayment')} — ${active?.Ref || ''}`"
    :confirm-loading="saving"
    :ok-text="$t('submit')"
    @update:open="v => $emit('update:open', v)"
    @ok="$emit('submit')"
  >
    <a-form layout="vertical">
      <a-alert type="info" show-icon style="margin-bottom: 12px" :message="$t('Payment_Amounts_Help')" />
      <a-row :gutter="12">
        <a-col :span="12">
          <a-form-item :label="$t('date')">
            <a-date-picker v-model:value="form.date" value-format="YYYY-MM-DD" style="width: 100%" />
          </a-form-item>
        </a-col>
        <a-col :span="12">
          <a-form-item :label="$t('Amount_Paid')">
            <a-input-number v-model:value="form.montant" style="width: 100%" :min="0" :max="maxDue ?? undefined" />
            <a-typography-text type="secondary" style="font-size: 12px; display: block">
              {{ $t('Amount_Paid_Help') }}
            </a-typography-text>
            <a-typography-text v-if="maxDue != null" type="secondary" style="font-size: 12px; display: block">
              {{ $t('Maximum_payment') }}: {{ money(maxDue) }}
            </a-typography-text>
          </a-form-item>
        </a-col>
        <!-- Purchase returns only: settle as supplier-wallet credit instead of cash. -->
        <a-col v-if="(wallet && wallet.enabled) || form.to_wallet" :span="24">
          <a-form-item>
            <a-space align="start">
              <a-switch v-model:checked="form.to_wallet" :disabled="wallet?.status === 'frozen'" />
              <div>
                <div>{{ $t('SW_Credit_To_Wallet') }}</div>
                <a-typography-text type="secondary" style="font-size: 12px; display: block">
                  {{ $t('SW_Credit_To_Wallet_Help') }} — {{ $t('SW_Wallet_Balance') }}: <strong>{{ money(wallet?.balance || 0) }}</strong>
                  <span v-if="wallet?.status === 'frozen'"> ({{ $t('SW_Frozen') }})</span>
                  <span v-if="wallet && !wallet.enabled"> ({{ $t('SW_Err_wallet_disabled') }})</span>
                </a-typography-text>
              </div>
            </a-space>
          </a-form-item>
        </a-col>
        <template v-if="!form.to_wallet">
          <a-col :span="12">
            <a-form-item :label="$t('Received_Amount')">
              <a-input-number v-model:value="form.received_amount" style="width: 100%" :min="0" />
              <a-typography-text type="secondary" style="font-size: 12px; display: block">
                {{ $t('Received_Amount_Help') }}
              </a-typography-text>
            </a-form-item>
          </a-col>
          <a-col :span="12">
            <a-form-item :label="$t('Paymentchoice')">
              <a-select v-model:value="form.payment_method_id" :options="methodOptions" />
            </a-form-item>
          </a-col>
          <!-- Sale returns only: the Customer Wallet tender refunds into the customer's store credit. -->
          <a-col v-if="isCustomerWalletMethod" :span="24">
            <a-alert
              :type="customerWallet?.status === 'frozen' ? 'error' : 'info'" show-icon style="margin-bottom: 16px"
              :message="customerWallet?.status === 'frozen' ? $t('CW_Err_wallet_frozen') : `${$t('CW_Refund_To_Wallet_Help')} — ${$t('SW_Wallet_Balance')}: ${money(customerWallet?.balance || 0)}`"
            />
          </a-col>
          <a-col v-else :span="12">
            <a-form-item :label="$t('Account')">
              <a-select v-model:value="form.account_id" allow-clear :placeholder="$t('Choose_Account')" :options="accountOptions" />
            </a-form-item>
          </a-col>
          <a-col :span="12">
            <a-form-item :label="$t('Change')">
              <a-input :value="money(change)" disabled />
            </a-form-item>
          </a-col>
        </template>
        <a-col :span="12">
          <a-form-item :label="$t('Due')">
            <a-input :value="money(active?.due)" disabled />
          </a-form-item>
        </a-col>
        <a-col :span="24">
          <a-form-item :label="$t('Note')">
            <a-textarea v-model:value="form.notes" :rows="2" />
          </a-form-item>
        </a-col>
      </a-row>
    </a-form>
  </a-modal>
</template>

<script setup>
/**
 * Add/edit payment form for a return document. The parent owns the `form` ref
 * (v-model style would fight the async Ref fetch) and submits on @submit.
 */
import { computed } from 'vue';
import { useFormat } from '../composables/useFormat';

const props = defineProps({
  open: { type: Boolean, default: false },
  active: { type: Object, default: null },
  editing: { type: Object, default: null },
  form: { type: Object, required: true },
  methodOptions: { type: Array, default: () => [] },
  accountOptions: { type: Array, default: () => [] },
  saving: { type: Boolean, default: false },
  /** Cap for the amount input (remaining due); null disables the cap. */
  maxDue: { type: Number, default: null },
  /**
   * Purchase returns only: GET supplier_wallets/{provider}/summary →
   * { enabled, balance, status }. When enabled, offers "credit to supplier
   * wallet" (form.to_wallet) in place of the cash fields. Null for sale returns.
   */
  wallet: { type: Object, default: null },
  /**
   * Sale returns only: GET customer_wallets/{client}/summary → { enabled,
   * balance, status, payment_method_id }. Choosing that payment method
   * refunds into the customer's wallet, so the account select is hidden.
   */
  customerWallet: { type: Object, default: null },
});

defineEmits(['update:open', 'submit']);

const { money } = useFormat();

const isCustomerWalletMethod = computed(() => {
  const cw = props.customerWallet;
  if (!cw || !cw.enabled || cw.payment_method_id == null) return false;
  return String(props.form.payment_method_id) === String(cw.payment_method_id);
});

const change = computed(() =>
  Math.max(0, (Number(props.form.received_amount) || 0) - (Number(props.form.montant) || 0))
);
</script>
