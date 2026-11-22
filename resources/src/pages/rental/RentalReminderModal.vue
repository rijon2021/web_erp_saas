<template>
  <a-modal
    :open="open" :title="$t('Rental_Send_Reminder')" :ok-text="$t('Rental_Send')" :cancel-text="$t('Cancel')"
    :confirm-loading="sending" @ok="send" @cancel="$emit('update:open', false)"
  >
    <a-form layout="vertical">
      <a-form-item :label="$t('type')">
        <a-radio-group v-model:value="type" class="types">
          <a-radio v-for="o in typeOptions" :key="o.value" :value="o.value">{{ o.label }}</a-radio>
        </a-radio-group>
      </a-form-item>
      <a-form-item :label="$t('Rental_Channels')" class="no-mb">
        <a-checkbox-group v-model:value="channels" :options="channelOptions" />
      </a-form-item>
      <p class="hint">{{ $t('Rental_Reminder_Template_Hint') }}</p>
    </a-form>
  </a-modal>
</template>

<script setup>
/**
 * Send one reminder now (POST rental/reminders/send), using the wording set in
 * Rental Settings → Reminders. Offers only the reminder types that fit where
 * it was opened from (an invoice, or a contract).
 */
import { ref, computed, watch } from 'vue';
import { message } from 'ant-design-vue';
import http from '../../lib/http';
import { t } from '../../i18n';

const props = defineProps({
  open: { type: Boolean, default: false },
  invoiceId: { type: [Number, String], default: null },
  contractId: { type: [Number, String], default: null },
  types: { type: Array, default: () => ['rent_due', 'rent_overdue', 'contract_expiring', 'return_due'] },
  defaultType: { type: String, default: '' },
});
const emit = defineEmits(['update:open', 'sent']);

const LABELS = {
  rent_due: ['Rental_Rem_Rent_Due', 'Rent due'],
  rent_overdue: ['Rental_Rem_Rent_Overdue', 'Rent overdue'],
  contract_expiring: ['Rental_Rem_Expiring', 'Contract ending'],
  return_due: ['Rental_Rem_Return_Due', 'Return due'],
};
const typeOptions = computed(() => props.types.map(v => ({ value: v, label: t(LABELS[v][0], LABELS[v][1]) })));
const channelOptions = computed(() => [
  { value: 'email', label: t('Email', 'Email') },
  { value: 'sms', label: 'SMS' },
  { value: 'whatsapp', label: 'WhatsApp' },
]);

const type = ref('');
const channels = ref(['email']);
const sending = ref(false);

watch(() => props.open, (v) => {
  if (v) type.value = props.defaultType || props.types[0];
});

async function send() {
  if (!channels.value.length) {
    message.error(t('Rental_Pick_Channel', 'Pick at least one channel'));
    return;
  }
  sending.value = true;
  try {
    const res = await http.post('rental/reminders/send', {
      type: type.value,
      rental_invoice_id: props.invoiceId || null,
      rental_contract_id: props.invoiceId ? null : props.contractId,
      channels: channels.value,
    });
    const r = res?.results || {};
    const failed = channels.value.filter(c => !r[c]);
    if (!failed.length) {
      message.success(t('Rental_Reminder_Sent', 'Reminder sent'));
      emit('update:open', false);
      emit('sent');
    } else {
      failed.forEach(c => message.error(`${c.toUpperCase()}: ${r[`${c}_error`] || t('Rental_Failed', 'Failed')}`, 6));
      if (failed.length < channels.value.length) emit('sent');
    }
  } catch (e) {
    message.error(e?.data?.message || t('Rental_Err_Could_Not_Send', 'Could not send'));
  } finally {
    sending.value = false;
  }
}
</script>

<style scoped>
.types {
  display: flex;
  flex-direction: column;
  gap: 6px;
}
.no-mb {
  margin-bottom: 4px;
}
.hint {
  margin: 8px 0 0;
  font-size: 12px;
  opacity: 0.6;
}
</style>
