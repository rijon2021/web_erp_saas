<template>
  <a-modal
    :open="open" :title="title || $t('Rental_Record_Payment')" :ok-text="$t('Rental_Record_Payment')"
    :cancel-text="$t('Cancel')" :confirm-loading="saving" destroy-on-close @ok="save" @cancel="$emit('update:open', false)"
  >
    <div v-if="owed !== null" class="owed">
      <span>{{ invoice ? $t('Rental_Invoice_Balance') : $t('Rental_Total_Owed') }}</span>
      <strong>{{ money(owed) }}</strong>
    </div>
    <a-form ref="formRef" :model="form" :rules="rules" layout="vertical">
      <a-row :gutter="12">
        <a-col :span="12">
          <a-form-item :label="$t('Amount')" name="amount">
            <a-input-number v-model:value="form.amount" :min="0.01" :max="owed || undefined" :precision="2" style="width: 100%" />
          </a-form-item>
        </a-col>
        <a-col :span="12">
          <a-form-item :label="$t('date')" name="payment_date">
            <a-date-picker v-model:value="form.payment_date" value-format="YYYY-MM-DD" style="width: 100%" :allow-clear="false" />
          </a-form-item>
        </a-col>
      </a-row>
      <div v-if="owed" class="quick">
        <button type="button" class="quick-chip" @click="form.amount = owed">{{ $t('Rental_Pay_All') }}</button>
        <button v-if="owed >= 2" type="button" class="quick-chip" @click="form.amount = Math.round(owed * 50) / 100">50%</button>
      </div>
      <a-row :gutter="12">
        <a-col :span="12">
          <a-form-item :label="$t('Paymentchoice')" name="payment_method_id">
            <a-select v-model:value="form.payment_method_id" :options="methodOptions" />
          </a-form-item>
        </a-col>
        <a-col :span="12">
          <a-form-item :label="$t('Account')">
            <a-select v-model:value="form.account_id" :options="accountOptions" allow-clear :placeholder="$t('Rental_No_Account')" />
          </a-form-item>
        </a-col>
      </a-row>
      <a-form-item :label="$t('Reference')">
        <a-input v-model:value="form.reference" :placeholder="$t('Rental_Reference_Placeholder')" allow-clear />
      </a-form-item>
      <a-form-item :label="$t('Rental_Note')" class="no-mb">
        <a-textarea v-model:value="form.notes" :rows="2" />
      </a-form-item>
      <p v-if="!invoice" class="hint"><InfoCircleOutlined /> {{ $t('Rental_Payment_Allocation_Hint') }}</p>
    </a-form>
  </a-modal>
</template>

<script setup>
/**
 * Record a rental payment. Scope is whichever is given: one invoice, else a
 * contract, else a customer — the API spreads the money oldest-due first and
 * refuses more than is owed.
 */
import { ref, computed, watch } from 'vue';
import { message } from 'ant-design-vue';
import dayjs from 'dayjs';
import { InfoCircleOutlined } from '@ant-design/icons-vue';
import { useFormat } from '../../composables/useFormat';
import http from '../../lib/http';
import { t } from '../../i18n';

const props = defineProps({
  open: { type: Boolean, default: false },
  title: { type: String, default: '' },
  invoice: { type: Object, default: null },      // { id, balance, invoice_number }
  contractId: { type: [Number, String], default: null },
  customerId: { type: [Number, String], default: null },
  owed: { type: Number, default: null },
});
const emit = defineEmits(['update:open', 'saved']);

const { money } = useFormat();
const formRef = ref();
const saving = ref(false);
const methods = ref([]);
const accounts = ref([]);
let metaLoaded = false;

const form = ref({ amount: null, payment_date: dayjs().format('YYYY-MM-DD'), payment_method_id: undefined, account_id: undefined, reference: '', notes: '' });

const methodOptions = computed(() => methods.value.map(m => ({ value: m.id, label: m.name })));
const accountOptions = computed(() => accounts.value.map(a => ({ value: a.id, label: a.account_name })));
const rules = computed(() => ({
  amount: [{ required: true, type: 'number', message: t('Field_is_required', 'This field is required') }],
  payment_date: [{ required: true, message: t('Field_is_required', 'This field is required') }],
  payment_method_id: [{ required: true, message: t('Field_is_required', 'This field is required') }],
}));

async function loadMeta() {
  if (metaLoaded) return;
  try {
    const data = await http.get('rental/payments/meta');
    methods.value = data?.payment_methods || [];
    accounts.value = data?.accounts || [];
    if (!form.value.payment_method_id) form.value.payment_method_id = data?.default_payment_method_id || methods.value[0]?.id;
    if (!form.value.account_id && data?.default_account_id) form.value.account_id = data.default_account_id;
    metaLoaded = true;
  } catch (e) { /* the selects stay empty */ }
}

watch(() => props.open, (v) => {
  if (!v) return;
  form.value.amount = props.owed ? Math.round(props.owed * 100) / 100 : null;
  form.value.payment_date = dayjs().format('YYYY-MM-DD');
  form.value.reference = '';
  form.value.notes = '';
  loadMeta();
});

async function save() {
  try {
    await formRef.value.validate();
  } catch (e) {
    return;
  }
  saving.value = true;
  try {
    const body = {
      ...form.value,
      account_id: form.value.account_id || null,
      rental_invoice_id: props.invoice?.id || null,
      rental_contract_id: props.invoice ? null : (props.contractId || null),
      rental_customer_id: props.invoice || props.contractId ? null : (props.customerId || null),
    };
    const res = await http.post('rental/payments', body);
    message.success(`${t('Rental_Payment_Recorded', 'Payment recorded')} ${res?.payment_number || ''}`);
    emit('update:open', false);
    emit('saved', res);
  } catch (e) {
    const errors = e?.data?.errors;
    message.error((errors && Object.values(errors)[0]?.[0]) || e?.data?.message || t('Rental_Err_Could_Not_Record_The_Payment', 'Could not record the payment'), 6);
  } finally {
    saving.value = false;
  }
}
</script>

<style scoped>
.owed {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 14px;
  padding: 12px 14px;
  border-radius: 12px;
  background: rgba(var(--app-primary-rgb, 109, 40, 217), 0.08);
}
.owed strong {
  font-size: 20px;
  color: var(--app-primary, #6d28d9);
}
.quick {
  display: flex;
  gap: 6px;
  margin: -8px 0 14px;
}
.quick-chip {
  padding: 2px 10px;
  border: 1px solid rgba(128, 128, 128, 0.28);
  border-radius: 999px;
  background: transparent;
  color: inherit;
  font: inherit;
  font-size: 12px;
  cursor: pointer;
}
.quick-chip:hover {
  border-color: var(--app-primary, #6d28d9);
  color: var(--app-primary, #6d28d9);
}
.no-mb {
  margin-bottom: 4px;
}
.hint {
  margin: 10px 0 0;
  font-size: 12px;
  opacity: 0.65;
}
</style>
