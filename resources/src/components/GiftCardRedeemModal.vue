<template>
  <a-modal
    :open="open"
    :title="$t('CW_Redeem_Gift_Card')"
    :confirm-loading="saving"
    :ok-text="$t('CW_Redeem')"
    :cancel-text="$t('Delete_cancelButtonText')"
    @update:open="v => $emit('update:open', v)"
    @ok="submit"
  >
    <a-form layout="vertical" style="margin-top: 8px" @submit.prevent="submit">
      <a-form-item :label="$t('Customer')">
        <a-input :value="client?.name" disabled />
      </a-form-item>
      <a-alert type="info" show-icon style="margin-bottom: 12px" :message="$t('CW_Redeem_Help')" />
      <a-form-item :label="$t('CW_Gift_Card_Code') + ' *'">
        <a-input
          v-model:value="code" :maxlength="60" allow-clear
          style="text-transform: uppercase; font-family: monospace"
          @press-enter="submit"
        />
      </a-form-item>
    </a-form>
  </a-modal>
</template>

<script setup>
/**
 * Redeem a gift card / voucher / store-credit code into a customer's wallet.
 * POST customer_wallets/{id}/redeem { code } → { success, credited, balance }.
 * Needs gift_cards_manage. Refusals (gift_card_invalid, gift_card_redeemed,
 * gift_card_not_redeemable, wallet_frozen…) map to CW_Err_* translations.
 */
import { ref, watch } from 'vue';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { useFormat } from '../composables/useFormat';
import { customerWalletError } from '../composables/useCustomerWallet';
import http from '../lib/http';

const props = defineProps({
  open: { type: Boolean, default: false },
  client: { type: Object, default: null }, // { id, name }
});
const emit = defineEmits(['update:open', 'done']);

const { t } = useI18n();
const { money } = useFormat();

const saving = ref(false);
const code = ref('');
watch(() => props.open, v => { if (v) code.value = ''; });

async function submit() {
  const c = String(code.value || '').trim().toUpperCase();
  if (!c) {
    message.warning(t('CW_Err_gift_card_code_required'));
    return;
  }
  if (saving.value) return;
  saving.value = true;
  try {
    const res = await http.post(`customer_wallets/${props.client.id}/redeem`, { code: c });
    message.success(`${t('CW_Redeemed_Ok')}: ${money(res?.credited || 0)}`);
    emit('update:open', false);
    emit('done', res);
  } catch (e) {
    message.error(customerWalletError(e));
  } finally {
    saving.value = false;
  }
}
</script>
