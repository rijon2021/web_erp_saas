<template>
  <a-modal
    :open="open" :title="$t('Bkm_Complete_Charge')" :ok-text="okText" :confirm-loading="saving"
    :ok-button-props="{ disabled: loading || !quote || !quote.lines.length || (requireAccount && !form.account_id) }"
    destroy-on-close @ok="submit" @cancel="$emit('update:open', false)"
  >
    <a-spin :spinning="loading">
      <template v-if="quote">
        <p class="muted">{{ booking?.Ref }} · {{ booking?.customer?.name || '—' }} · {{ booking?.date }} {{ booking?.time }}</p>
        <a-alert v-if="!quote.lines.length" type="warning" show-icon :message="$t('Bkm_No_Service_To_Charge')" />
        <table v-else class="lines">
          <tbody>
            <tr v-for="l in quote.lines" :key="l.product_id">
              <th scope="row">{{ l.name }} <span v-if="l.qty > 1" class="muted">× {{ l.qty }}</span></th>
              <td>{{ money(l.subtotal) }}</td>
            </tr>
            <tr v-if="form.discount > 0">
              <th scope="row">{{ $t('Discount') }}</th>
              <td>− {{ money(Math.min(form.discount, quote.subtotal)) }}</td>
            </tr>
            <tr class="total">
              <th scope="row">{{ $t('Total') }}</th>
              <td>{{ money(total) }}</td>
            </tr>
            <template v-if="deposit > 0">
              <tr>
                <th scope="row">{{ $t('Bkm_Deposit_Paid_Online') }}</th>
                <td>− {{ money(deposit) }}</td>
              </tr>
              <tr class="total">
                <th scope="row">{{ $t('Bkm_Left_To_Pay') }}</th>
                <td>{{ money(dueNow) }}</td>
              </tr>
            </template>
          </tbody>
        </table>
        <a-alert v-if="quote.product?.deleted" type="info" show-icon class="gap" :message="$t('Bkm_Service_Deleted_Note')" />

        <a-form layout="vertical" class="gap">
          <a-form-item :label="$t('Discount')">
            <a-input-number v-model:value="form.discount" :min="0" :max="quote.subtotal" :precision="2" style="width: 160px" />
          </a-form-item>
          <a-checkbox v-model:checked="payLater" class="gap-b">{{ $t('Bkm_Pay_Later') }}</a-checkbox>
          <template v-if="!payLater">
            <a-row :gutter="12">
              <a-col :span="12">
                <a-form-item :label="$t('Bkm_Payment_Method')" required>
                  <a-select v-model:value="form.payment_method_id" :options="methods.map((m) => ({ value: m.id, label: m.name }))" />
                </a-form-item>
              </a-col>
              <a-col :span="12">
                <a-form-item :label="$t('Bkm_Amount_Received')">
                  <a-input-number v-model:value="form.amount" :min="0" :precision="2" style="width: 100%" />
                </a-form-item>
              </a-col>
            </a-row>
            <a-form-item v-if="accounts.length" :label="$t('Account')" :required="requireAccount">
              <a-select v-model:value="form.account_id" allow-clear :options="accounts.map((a) => ({ value: a.id, label: a.name }))" />
            </a-form-item>
            <p v-if="change > 0" class="change">{{ $t('Bkm_Change_Due') }}: <strong>{{ money(change) }}</strong></p>
            <p v-else-if="form.amount < dueNow" class="muted">{{ $t('Bkm_Part_Payment') }}</p>
          </template>
          <template v-else>
            <p class="muted">{{ $t('Bkm_Pay_Later_Help') }}</p>
            <!-- The deposit already paid online still goes into an account. -->
            <a-form-item v-if="(deposit > 0 || requireAccount) && accounts.length" :label="$t('Account')" :required="requireAccount">
              <a-select v-model:value="form.account_id" allow-clear :options="accounts.map((a) => ({ value: a.id, label: a.name }))" />
            </a-form-item>
          </template>
        </a-form>
      </template>
    </a-spin>
  </a-modal>
</template>

<script setup>
/**
 * "Complete & charge": GET bookings/{id}/charge → {booking, quote, payment_methods, accounts, require_account};
 * POST bookings/{id}/charge {payments, discount, sale_uuid}. The sale goes through the POS pipeline
 * (App\Http\Controllers\Booking\BookingPosBridge). A fresh sale_uuid per opening makes a retry safe.
 */
import { ref, computed, watch } from 'vue';
import { message } from 'ant-design-vue';
import http from '../../lib/http';
import { t } from '../../i18n';
import { useFormat } from '../../composables/useFormat';
import { reasonText } from './bookingStatus';

const props = defineProps({ open: Boolean, bookingId: { type: Number, default: null } });
const emit = defineEmits(['update:open', 'charged']);
const { money } = useFormat();

const loading = ref(false);
const saving = ref(false);
const booking = ref(null);
const quote = ref(null);
const methods = ref([]);
const accounts = ref([]);
const requireAccount = ref(false);
const payLater = ref(false);
const form = ref({ discount: 0, payment_method_id: undefined, amount: 0, account_id: undefined });
let saleUuid = null;

const total = computed(() => Math.max(0, Math.round(((quote.value?.subtotal || 0) - Math.min(form.value.discount || 0, quote.value?.subtotal || 0)) * 100) / 100));
/** Phase 8: a deposit paid online is already on the bill (never more than it). */
const deposit = computed(() => Math.min(quote.value?.deposit_paid || 0, total.value));
const dueNow = computed(() => Math.max(0, Math.round((total.value - deposit.value) * 100) / 100));
const isCash = computed(() => !!methods.value.find((m) => m.id === form.value.payment_method_id)?.is_cash);
const change = computed(() => (isCash.value ? Math.max(0, Math.round(((form.value.amount || 0) - dueNow.value) * 100) / 100) : 0));
const okText = computed(() => (payLater.value ? t('Bkm_Complete_Unpaid', 'Complete, pay later') : t('Bkm_Take_Payment', 'Take payment')));

function uuid() {
  if (typeof crypto !== 'undefined' && crypto.randomUUID) return crypto.randomUUID();
  return 'bk-' + Date.now().toString(36) + Math.random().toString(36).slice(2, 10);
}

async function load() {
  if (!props.bookingId) return;
  loading.value = true;
  saleUuid = uuid();
  payLater.value = false;
  try {
    const data = await http.get(`bookings/${props.bookingId}/charge`);
    booking.value = data?.booking || null;
    quote.value = data?.quote || null;
    methods.value = data?.payment_methods || [];
    accounts.value = data?.accounts || [];
    requireAccount.value = !!data?.require_account;
    const cash = methods.value.find((m) => m.is_cash) || methods.value[0];
    form.value = { discount: 0, payment_method_id: cash?.id, amount: quote.value?.due_now ?? quote.value?.grand_total ?? 0, account_id: undefined };
  } catch (e) {
    message.error(e?.data?.message || t('Bkm_Could_Not_Load_Charge', 'Could not load the bill.'));
    emit('update:open', false);
  } finally {
    loading.value = false;
  }
}

// Keep the amount at the total until the user types their own.
let amountTouched = false;
watch(() => form.value.amount, (v, old) => { if (old !== undefined && v !== dueNow.value) amountTouched = true; });
watch(dueNow, (v) => { if (!amountTouched) form.value.amount = v; });
watch(() => [props.open, props.bookingId], ([open]) => { if (open) { amountTouched = false; load(); } }, { immediate: true });

async function submit() {
  if (!payLater.value && !form.value.payment_method_id) {
    message.error(t('Bkm_Pick_Payment_Method', 'Pick a payment method.'));
    return;
  }
  saving.value = true;
  try {
    const methodId = form.value.payment_method_id || methods.value[0]?.id;
    const data = await http.post(`bookings/${props.bookingId}/charge`, {
      discount: form.value.discount || 0,
      sale_uuid: saleUuid,
      payments: [{
        payment_method_id: methodId,
        amount: payLater.value ? 0 : (form.value.amount || 0),
        account_id: form.value.account_id || null,
      }],
    });
    message.success(t('Bkm_Charged', 'Payment taken and booking completed.'));
    emit('charged', data);
    emit('update:open', false);
  } catch (e) {
    const d = e?.data || {};
    message.error(d.reason ? reasonText(d.reason, d) : (d.message || t('Bkm_Could_Not_Save', 'Could not save.')));
  } finally {
    saving.value = false;
  }
}
</script>

<style scoped>
.muted { color: rgba(0, 0, 0, 0.55); }
.gap { margin-top: 12px; }
.gap-b { margin-bottom: 12px; display: inline-flex; }
.lines {
  width: 100%;
  border-collapse: collapse;
}
.lines th,
.lines td {
  padding: 6px 0;
  border-bottom: 1px solid rgba(128, 128, 128, 0.15);
  text-align: start;
  font-weight: 400;
}
.lines td {
  text-align: end;
  font-variant-numeric: tabular-nums;
}
.lines .total th,
.lines .total td {
  font-weight: 700;
  border-bottom: 0;
}
.change {
  margin: 0;
  font-size: 15px;
}
</style>
