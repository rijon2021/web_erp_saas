<template>
  <a-spin :spinning="loading">
    <div v-if="b" class="booking-panel">
      <div class="head">
        <h2 class="title">{{ b.Ref }}</h2>
        <a-tag :color="statusTag(b.status)">{{ statusLabel(b.status) }}</a-tag>
        <a-tag v-if="b.source === 'website'" color="blue">{{ $t('Bkm_From_Website') }}</a-tag>
        <a-tag v-if="needsTime" color="cyan">{{ $t('Bkm_Needs_A_Time') }}</a-tag>
        <span v-if="b.created_at" class="muted small">{{ sentText }}</span>
      </div>

      <a-alert v-if="holdText" :type="b.hold_expired ? 'warning' : 'info'" show-icon class="block" :message="holdText" />
      <a-alert v-if="b.check && !b.check.ok" type="error" show-icon class="block" :message="reasonText(b.check.reason, b.check)" />
      <a-alert v-if="b.proposal" type="info" show-icon class="block" :message="$t('Bkm_Proposal_Waiting')">
        <template #description>
          {{ b.proposal.date }} · {{ b.proposal.time }}<template v-if="b.proposal.end_time"> – {{ b.proposal.end_time }}</template><template v-if="b.proposal.staff"> · {{ b.proposal.staff }}</template>
          <div v-if="b.proposal.note" class="pre muted small">{{ b.proposal.note }}</div>
        </template>
      </a-alert>

      <dl class="facts">
        <div><dt>{{ $t('Customer') }}</dt><dd>{{ b.customer?.name || '—' }}</dd></div>
        <div v-if="b.customer?.phone">
          <dt>{{ $t('Phone') }}</dt>
          <dd><a :href="`tel:${b.customer.phone}`">{{ b.customer.phone }}</a> · <a :href="waLink(b.customer.phone)" target="_blank" rel="noopener">WhatsApp</a></dd>
        </div>
        <div v-if="b.customer?.email"><dt>{{ $t('Email') }}</dt><dd><a :href="`mailto:${b.customer.email}`">{{ b.customer.email }}</a></dd></div>
        <div><dt>{{ $t('Bkm_Service') }}</dt><dd>{{ b.product?.name || '—' }}<span v-if="b.seats > 1" class="muted"> × {{ b.seats }}</span></dd></div>
        <div><dt>{{ $t('Bkm_Staff') }}</dt><dd>{{ b.staff?.name || $t('Bkm_Not_Assigned') }}</dd></div>
        <div>
          <dt>{{ needsTime ? $t('Bkm_Preferred') : $t('Bkm_When') }}</dt>
          <dd v-if="needsTime">{{ b.preferred_date || b.date }} · {{ windowLabel(b.preferred_window) }}</dd>
          <dd v-else>{{ b.date }} · {{ b.time }}<template v-if="b.end_time"> – {{ b.end_time }}</template></dd>
        </div>
        <div v-if="b.price !== null"><dt>{{ $t('Price') }}</dt><dd>{{ money(b.price) }}</dd></div>
        <div v-for="f in b.request_fields" :key="f.id">
          <dt>{{ fieldLabel(f) }}</dt>
          <dd v-if="f.input === 'file' && f.value && f.value.url"><a :href="f.value.url" target="_blank" rel="noopener">{{ f.value.name }}</a></dd>
          <dd v-else class="pre">{{ fieldValue(f) }}</dd>
        </div>
        <div v-if="b.notes" class="wide"><dt>{{ $t('Note') }}</dt><dd class="pre">{{ b.notes }}</dd></div>
        <div v-if="b.cancel_reason" class="wide"><dt>{{ $t('Reason') }}</dt><dd class="pre">{{ b.cancel_reason }}</dd></div>
        <div v-if="b.sale_id"><dt>{{ $t('Bkm_Sale') }}</dt><dd><router-link :to="`/sales/${b.sale_id}`" @click="$emit('navigate')">#{{ b.sale_id }}</router-link></dd></div>
        <div v-if="b.deposit">
          <dt>{{ $t('Bkm_Deposit') }}</dt>
          <dd>
            <a-tag :color="DEPOSIT_TAGS[b.deposit.status] || 'default'" class="tag">{{ depositStatus(b.deposit.status) }}</a-tag>
            {{ money(b.deposit.status === 'paid' || b.deposit.status === 'refunded' ? b.deposit.paid : b.deposit.amount) }}
            <div v-if="b.deposit.paid_at" class="muted small">{{ b.deposit.gateway_label }} · {{ fmt(b.deposit.paid_at) }}<template v-if="b.deposit.reference"> · {{ b.deposit.reference }}</template></div>
            <div v-if="b.deposit.note" class="muted small pre">{{ b.deposit.note }}</div>
          </dd>
        </div>
        <div v-if="b.crm">
          <dt>{{ $t('Bkm_Crm') }}</dt>
          <dd>
            <router-link v-if="b.crm.deal_id" :to="`/crm/deals/${b.crm.deal_id}`" @click="$emit('navigate')">{{ $t('Bkm_Crm_Open_Deal') }}</router-link>
            <router-link v-else-if="b.crm.lead_id" :to="`/crm/leads/${b.crm.lead_id}`" @click="$emit('navigate')">{{ $t('Bkm_Crm_Open_Lead') }}</router-link>
          </dd>
        </div>
        <div v-if="b.status_url" class="wide">
          <dt>{{ $t('Bkm_Status_Page') }}</dt>
          <dd><a :href="b.status_url" target="_blank" rel="noopener">{{ $t('Bkm_Open_Status_Page') }}</a> · <a href="#" @click.prevent="copyLink">{{ $t('Bkm_Copy_Link') }}</a></dd>
        </div>
      </dl>
      <a-alert v-if="depositAlert" type="warning" show-icon class="block" :message="depositAlert" />

      <!-- Schedule (confirm / reschedule) -->
      <div v-if="mode === 'confirm' || mode === 'reschedule' || mode === 'propose'" class="panel">
        <div class="panel-title">{{ mode === 'confirm' ? $t('Bkm_Confirm_Time') : (mode === 'propose' ? $t('Bkm_Suggest_Time') : $t('Bkm_Reschedule')) }}</div>
        <p v-if="mode === 'propose'" class="muted small" style="margin-top: -4px">{{ $t('Bkm_Suggest_Time_Help') }}</p>
        <a-row :gutter="10">
          <a-col :xs="24" :sm="12">
            <a-form-item :label="$t('Bkm_Staff')" class="tight">
              <a-select v-model:value="when.staff_id" allow-clear :placeholder="$t('Bkm_Pick_Free_Staff')" :options="staffOptions" @change="runCheck" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :sm="12">
            <a-form-item :label="$t('date')" class="tight">
              <a-date-picker v-model:value="when.date" value-format="YYYY-MM-DD" :allow-clear="false" style="width: 100%" @change="runCheck" />
            </a-form-item>
          </a-col>
          <a-col :span="12">
            <a-form-item :label="$t('Start_Time')" class="tight">
              <a-time-picker v-model:value="when.time" value-format="HH:mm" format="HH:mm" :minute-step="5" style="width: 100%" @change="onStartChange" />
            </a-form-item>
          </a-col>
          <a-col :span="12">
            <a-form-item :label="$t('End_Time')" class="tight">
              <a-time-picker v-model:value="when.end_time" value-format="HH:mm" format="HH:mm" :minute-step="5" style="width: 100%" @change="runCheck" />
            </a-form-item>
          </a-col>
        </a-row>
        <p v-if="check" :class="['check', check.ok ? 'ok' : 'bad']" role="status">
          {{ check.ok ? $t('Bkm_Time_Free') : reasonText(check.reason, check) }}
        </p>
        <p v-else-if="!when.staff_id && staffOptions.length" class="muted small">{{ $t('Bkm_Auto_Staff_Help') }}</p>
        <a-textarea v-if="mode === 'propose'" v-model:value="note" :rows="2" :maxlength="500" :placeholder="$t('Bkm_Suggest_Note')" style="margin-top: 8px" />
        <div class="panel-actions">
          <a-button @click="mode = null">{{ $t('Cancel') }}</a-button>
          <a-button type="primary" :loading="busy" :disabled="!when.date || !when.time" @click="submitSchedule">
            {{ mode === 'confirm' ? $t('Bkm_Confirm') : (mode === 'propose' ? $t('Bkm_Send_Suggestion') : $t('Save')) }}
          </a-button>
        </div>
      </div>

      <!-- Deposit: marked refunded / asked for -->
      <div v-else-if="mode === 'deposit_refunded' || mode === 'deposit_request'" class="panel">
        <div class="panel-title">{{ mode === 'deposit_refunded' ? $t('Bkm_Deposit_Mark_Refunded') : $t('Bkm_Deposit_Ask_Customer') }}</div>
        <template v-if="mode === 'deposit_refunded'">
          <p class="muted small">{{ $t('Bkm_Deposit_Refund_Help') }}</p>
          <a-textarea v-model:value="reason" :rows="2" :maxlength="500" :placeholder="$t('Bkm_Reason_Optional')" />
        </template>
        <template v-else>
          <p class="muted small">{{ $t('Bkm_Deposit_Ask_Help') }}</p>
          <a-input-number v-model:value="depositAmount" :min="0.01" :precision="2" style="width: 200px" />
        </template>
        <div class="panel-actions">
          <a-button @click="mode = null">{{ $t('Back') }}</a-button>
          <a-button type="primary" :loading="busy" :disabled="mode === 'deposit_request' && !(depositAmount > 0)" @click="submitDeposit">{{ $t('Save') }}</a-button>
        </div>
      </div>

      <!-- Reason (decline / cancel) -->
      <div v-else-if="mode === 'decline' || mode === 'cancel'" class="panel">
        <div class="panel-title">{{ mode === 'decline' ? $t('Bkm_Decline') : $t('Bkm_Cancel_Booking') }}</div>
        <a-textarea v-model:value="reason" :rows="2" :maxlength="500" :placeholder="$t('Bkm_Reason_Optional')" />
        <p v-if="b.source === 'website'" class="muted small">{{ $t('Bkm_Reason_Shared') }}</p>
        <div class="panel-actions">
          <a-button @click="mode = null">{{ $t('Back') }}</a-button>
          <a-button type="primary" danger :loading="busy" @click="submitReason">{{ mode === 'decline' ? $t('Bkm_Decline') : $t('Bkm_Cancel_Booking') }}</a-button>
        </div>
      </div>

      <div v-else class="actions">
        <a-button v-if="has('confirm')" type="primary" @click="openSchedule('confirm')">{{ $t('Bkm_Confirm') }}</a-button>
        <a-button v-if="has('arrive')" type="primary" :loading="busy" @click="act('arrive')">{{ $t('Bkm_Mark_Arrived') }}</a-button>
        <a-button v-if="has('charge')" :type="b.status === 'arrived' ? 'primary' : 'default'" @click="chargeOpen = true">{{ $t('Bkm_Complete_Charge') }}</a-button>
        <a-button v-if="has('complete')" :loading="busy" @click="act('complete')">{{ $t('Bkm_Complete_No_Charge') }}</a-button>
        <a-button v-if="has('reschedule')" @click="openSchedule('reschedule')">{{ $t('Bkm_Reschedule') }}</a-button>
        <a-button v-if="has('propose')" @click="openSchedule('propose')">{{ $t('Bkm_Suggest_Time') }}</a-button>
        <a-button v-if="has('no_show')" :loading="busy" @click="act('no-show')">{{ $t('Bkm_Mark_No_Show') }}</a-button>
        <a-button v-if="has('restore')" :loading="busy" @click="act('restore')">{{ $t('Bkm_Restore') }}</a-button>
        <a-button v-if="has('deposit_waive')" :loading="busy" @click="waiveDeposit">{{ $t('Bkm_Deposit_Waive') }}</a-button>
        <a-button v-if="has('deposit_request')" @click="openDeposit('deposit_request')">{{ $t('Bkm_Deposit_Ask_Customer') }}</a-button>
        <a-button v-if="has('deposit_refunded')" @click="openDeposit('deposit_refunded')">{{ $t('Bkm_Deposit_Mark_Refunded') }}</a-button>
        <a-button v-if="has('decline')" danger @click="openReason('decline')">{{ $t('Bkm_Decline') }}</a-button>
        <a-button v-if="has('cancel')" danger type="text" @click="openReason('cancel')">{{ $t('Bkm_Cancel_Booking') }}</a-button>
        <a-button type="link" @click="edit">{{ $t('Edit') }}</a-button>
      </div>

      <!-- Phase 6: emails / WhatsApp messages about this booking -->
      <div v-if="messages.length" class="messages">
        <div class="panel-title">{{ $t('Bkm_Messages') }}</div>
        <ul>
          <li v-for="(m, i) in messages" :key="i">
            <a-tag :color="m.status === 'sent' ? 'green' : (m.status === 'failed' ? 'red' : 'default')" class="tag">{{ messageStatus(m.status) }}</a-tag>
            <span>{{ messageKind(m.kind) }} · {{ m.channel === 'whatsapp' ? 'WhatsApp' : $t('Email') }} → {{ m.recipient === 'owner' ? $t('Bkm_You') : (m.to || $t('Customer')) }}</span>
            <span class="muted small">{{ fmt(m.at) }}</span>
            <div v-if="m.error" class="muted small">{{ m.error }}</div>
          </li>
        </ul>
      </div>
    </div>

    <BookingChargeModal v-model:open="chargeOpen" :booking-id="b?.id || null" @charged="onCharged" />
  </a-spin>
</template>

<script setup>
/**
 * One booking with the buttons that apply to it now (the API's `actions`
 * list). GET bookings/{id}/details → {booking, staff}; steps go through
 * runAction() (POST bookings/{id}/<step>, "Save anyway" on a clash);
 * "Complete & charge" opens BookingChargeModal. Used inline by the Requests
 * inbox and inside BookingQuickView's modal.
 */
import { ref, computed, watch } from 'vue';
import { useRouter } from 'vue-router';
import { message, Modal } from 'ant-design-vue';
import http from '../../lib/http';
import { t, i18n } from '../../i18n';
import { useFormat } from '../../composables/useFormat';
import BookingChargeModal from './BookingChargeModal.vue';
import { statusLabel, statusTag, reasonText, runAction, windowLabel } from './bookingStatus';
import { fieldLabel, optionLabel } from './bookingTypes';

const props = defineProps({ bookingId: { type: Number, default: null } });
const emit = defineEmits(['changed', 'navigate', 'failed']);
const router = useRouter();
const { money } = useFormat();

const loading = ref(false);
const busy = ref(false);
const b = ref(null);
const staff = ref([]);
const mode = ref(null);
const reason = ref('');
const note = ref('');
const messages = ref([]);
const MESSAGE_KINDS = {
  received: ['Bkm_Msg_Received', 'Booking received'], new_request: ['Bkm_Msg_New_Request', 'New booking'],
  confirmed: ['Bkm_Msg_Confirmed', 'Confirmed'], declined: ['Bkm_Msg_Declined', 'Declined'], cancelled: ['Bkm_Msg_Cancelled', 'Cancelled'],
  cancelled_by_customer: ['Bkm_Msg_Cancelled_By_Customer', 'Cancelled by the customer'], rescheduled: ['Bkm_Msg_Rescheduled', 'Time changed'],
  reminder: ['Bkm_Msg_Reminder', 'Reminder'], proposal: ['Bkm_Msg_Proposal', 'Suggested time'],
  proposal_accepted: ['Bkm_Msg_Proposal_Accepted', 'Suggested time accepted'], proposal_declined: ['Bkm_Msg_Proposal_Declined', 'Asked for another time'],
  deposit_paid: ['Bkm_Msg_Deposit_Paid', 'Deposit paid'], deposit_received: ['Bkm_Msg_Deposit_Received', 'Deposit received'],
  deposit_requested: ['Bkm_Msg_Deposit_Requested', 'Deposit asked for'],
};

// ----- deposit (phase 8) -----
const DEPOSIT_TAGS = { due: 'orange', paid: 'green', waived: 'default', refunded: 'purple' };
function depositStatus(s) {
  return {
    due: t('Bkm_Deposit_Due', 'Not paid yet'), paid: t('Bkm_Deposit_Paid', 'Paid'),
    waived: t('Bkm_Deposit_Waived', 'Not needed'), refunded: t('Bkm_Deposit_Refunded', 'Refunded'),
  }[s] || s;
}
const depositAmount = ref(null);
/** A paid deposit on a booking that is no longer going ahead, or paid after its time was taken. */
const depositAlert = computed(() => {
  const d = b.value?.deposit;
  if (!d || d.status !== 'paid') return '';
  if (['cancelled', 'declined'].includes(b.value.status)) return t('Bkm_Deposit_Refund_Reminder', 'This booking is not going ahead but its deposit was paid. Refund it in your payment provider, then mark it refunded.');
  if (b.value.status === 'pending' && b.value.check && !b.value.check.ok) return t('Bkm_Deposit_Paid_Taken', 'The deposit was paid after the hold ran out and this time is no longer free. Suggest another time or refund it.');
  return '';
});
function openDeposit(which) {
  reason.value = '';
  depositAmount.value = b.value?.deposit?.amount || null;
  mode.value = which;
}
async function submitDeposit() {
  if (mode.value === 'deposit_refunded') {
    await act('deposit/refunded', { note: reason.value || null });
  } else {
    await act('deposit/request', { amount: depositAmount.value });
  }
}
function waiveDeposit() {
  Modal.confirm({
    title: t('Bkm_Deposit_Waive_Title', 'No deposit for this booking?'),
    content: t('Bkm_Deposit_Waive_Help', 'The customer will not be asked to pay it, and the booking is kept until you answer it.'),
    okText: t('Bkm_Deposit_Waive', 'No deposit needed'),
    cancelText: t('Cancel'),
    onOk: () => act('deposit/waive'),
  });
}
async function copyLink() {
  try {
    await navigator.clipboard.writeText(b.value.status_url);
    message.success(t('Bkm_Link_Copied', 'Link copied'));
  } catch (e) {
    message.info(b.value.status_url);
  }
}
function messageKind(k) {
  return MESSAGE_KINDS[k] ? t(...MESSAGE_KINDS[k]) : k;
}
function messageStatus(s) {
  return { sent: t('Bkm_Msg_Sent', 'Sent'), failed: t('Bkm_Msg_Failed', 'Failed'), skipped: t('Bkm_Msg_Skipped', 'Not sent') }[s] || s;
}
const when = ref({ staff_id: undefined, date: null, time: null, end_time: null });
const check = ref(null);
const chargeOpen = ref(false);

const needsTime = computed(() => b.value?.booking_mode === 'request' && b.value?.status === 'pending' && !b.value?.confirmed_at);
const staffOptions = computed(() => staff.value.map((s) => ({ value: s.id, label: s.name })));

function locale() {
  const l = i18n.global.locale;
  return (typeof l === 'string' ? l : l?.value) || 'en';
}
function tp(key, fallback, params) {
  const out = i18n.global.t(key, params);
  if (out !== key) return out;
  return Object.entries(params).reduce((s, [k, v]) => s.replace(`{${k}}`, v), fallback);
}
function fmt(iso) {
  const at = new Date(iso);
  return isNaN(at) ? iso : at.toLocaleString(locale(), { dateStyle: 'medium', timeStyle: 'short' });
}
const holdText = computed(() => {
  if (!b.value?.hold_until || b.value.status !== 'pending') return '';
  return b.value.hold_expired
    ? tp('Bkm_Hold_Expired', 'The hold ran out at {t}; the time may have been taken since.', { t: fmt(b.value.hold_until) })
    : tp('Bkm_Hold_Until', 'The time is held for this customer until {t}.', { t: fmt(b.value.hold_until) });
});
const sentText = computed(() => tp('Bkm_Sent_At', 'Sent {t}', { t: fmt(b.value.created_at) }));

function has(action) {
  return (b.value?.actions || []).includes(action);
}
function waLink(phone) {
  return `https://wa.me/${String(phone || '').replace(/[^\d]/g, '')}`;
}
function fieldValue(f) {
  if (f.input === 'bool') return f.value ? t('Yes') : t('No');
  if (f.input === 'choice') return optionLabel(f.value);
  if (Array.isArray(f.value)) return f.value.join(', ');
  if (f.value && typeof f.value === 'object') return f.value.name || '';
  return String(f.value);
}

async function load() {
  if (!props.bookingId) {
    b.value = null;
    return;
  }
  loading.value = true;
  mode.value = null;
  try {
    const data = await http.get(`bookings/${props.bookingId}/details`);
    b.value = data?.booking || null;
    staff.value = data?.staff || [];
    messages.value = data?.messages || [];
  } catch (e) {
    message.error(e?.data?.message || t('Bkm_Could_Not_Load_Booking', 'Could not load the booking.'));
    emit('failed');
  } finally {
    loading.value = false;
  }
}
watch(() => props.bookingId, load, { immediate: true });

async function act(step, body = {}) {
  busy.value = true;
  try {
    const booking = await runAction(b.value.id, step, body);
    if (booking) {
      message.success(t('Updated_in_successfully'));
      mode.value = null;
      emit('changed', booking);
      await load();
    }
  } finally {
    busy.value = false;
  }
}

// ----- schedule -----
function openSchedule(which) {
  const x = b.value;
  when.value = {
    staff_id: x.staff?.id && !x.staff.removed ? x.staff.id : undefined,
    date: needsTime.value ? (x.preferred_date || x.date) : x.date,
    time: needsTime.value ? null : x.time,
    end_time: needsTime.value ? null : x.end_time,
  };
  check.value = null;
  note.value = '';
  mode.value = which;
  runCheck();
}
function onStartChange() {
  // Keep the length when the start moves.
  const len = b.value?.duration_minutes;
  if (len && when.value.time) {
    const [h, m] = when.value.time.split(':').map(Number);
    const end = h * 60 + m + len;
    when.value.end_time = end < 1440 ? `${String(Math.floor(end / 60)).padStart(2, '0')}:${String(end % 60).padStart(2, '0')}` : null;
  }
  runCheck();
}
let checkSeq = 0;
async function runCheck() {
  check.value = null;
  if (!when.value.staff_id || !when.value.date || !when.value.time) return;
  const seq = ++checkSeq;
  try {
    const data = await http.get('bookings/availability/check', {
      product_id: b.value.product?.id || undefined, staff_id: when.value.staff_id, date: when.value.date,
      time: when.value.time, end_time: when.value.end_time || undefined, seats: b.value.seats, ignore_id: b.value.id,
    });
    if (seq === checkSeq && data?.ready !== false) check.value = data;
  } catch (e) {
    /* the save runs the same check */
  }
}
async function submitSchedule() {
  await act(mode.value, {
    staff_id: when.value.staff_id || null, date: when.value.date, time: when.value.time,
    ...(when.value.end_time ? { end_time: when.value.end_time } : {}),
    ...(mode.value === 'propose' && note.value ? { note: note.value } : {}),
  });
}

// ----- decline / cancel -----
function openReason(which) {
  reason.value = '';
  mode.value = which;
}
async function submitReason() {
  await act(mode.value, { reason: reason.value || null });
}

async function onCharged(data) {
  emit('changed', data?.booking || b.value);
  await load();
}

function edit() {
  emit('navigate');
  router.push(`/bookings/${b.value.id}/edit`);
}

defineExpose({ reload: load });
</script>

<style scoped>
.head {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
  margin-bottom: 12px;
}
.title {
  margin: 0;
  font-size: 18px;
}
.block {
  margin-bottom: 12px;
}
.muted { color: rgba(0, 0, 0, 0.55); }
.small { font-size: 12px; }
.pre { white-space: pre-line; }
.facts {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 10px 16px;
  margin: 0 0 16px;
}
.facts .wide {
  grid-column: 1 / -1;
}
.facts dt {
  font-size: 12px;
  color: rgba(0, 0, 0, 0.55);
}
.facts dd {
  margin: 2px 0 0;
  overflow-wrap: anywhere;
}
.actions {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}
.panel {
  border: 1px solid rgba(128, 128, 128, 0.22);
  border-radius: 10px;
  padding: 12px;
}
.panel-title {
  font-weight: 600;
  margin-bottom: 8px;
}
.panel-actions {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
  margin-top: 10px;
}
.tight {
  margin-bottom: 8px;
}
.check {
  margin: 4px 0 0;
  font-size: 13px;
}
.messages {
  margin-top: 18px;
  padding-top: 12px;
  border-top: 1px solid rgba(128, 128, 128, 0.18);
}
.messages ul {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 6px;
  font-size: 13px;
}
.messages li {
  display: flex;
  flex-wrap: wrap;
  gap: 4px 8px;
  align-items: baseline;
}
.messages .tag {
  margin: 0;
}
.facts .tag {
  margin: 0 6px 0 0;
}
.check.ok { color: #135200; }
.check.bad { color: #a8071a; }
</style>
