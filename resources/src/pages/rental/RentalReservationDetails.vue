<template>
  <div class="page">
    <PageHeader
      :title="r ? r.reservation_number : $t('Rental_Reservation')"
      :subtitle="r ? r.customer.name : ''"
      :breadcrumb="[$t('Rental_Management'), $t('Rental_Reservations'), r ? r.reservation_number : '']"
    >
      <template #actions>
        <a-button @click="$router.push('/rental/reservations')">
          <template #icon><ArrowLeftOutlined /></template>
          {{ $t('Back') }}
        </a-button>
        <template v-if="r">
          <a-button v-if="canDelete && r.status !== 'converted' && !r.contract_id" danger type="text" @click="remove">
            <template #icon><DeleteOutlined /></template>
          </a-button>
          <a-button v-if="canEdit && live && !r.contract_id" danger @click="cancelOpen = true">
            <template #icon><CloseCircleOutlined /></template>
            {{ $t('Rental_Cancel_Hold') }}
          </a-button>
          <a-button v-if="canEdit && ['held', 'expired'].includes(r.status)" @click="openExtend">
            <template #icon><FieldTimeOutlined /></template>
            {{ r.status === 'expired' ? $t('Rental_Revive_Hold') : $t('Rental_Extend_Hold') }}
          </a-button>
          <a-button v-if="canEdit && live && !r.contract_id" @click="$router.push(`/rental/reservations/${id}/edit`)">
            <template #icon><EditOutlined /></template>
            {{ $t('Edit') }}
          </a-button>
          <a-button v-if="canEdit && live && !r.contract_id && r.source === 'website' && !r.confirmed_at" :loading="busy === 'confirm'" @click="confirmBooking">
            <template #icon><SafetyCertificateOutlined /></template>
            {{ $t('Rental_Confirm_Booking') }}
          </a-button>
          <a-dropdown-button v-if="canConvert && live && !r.contract_id" type="primary" :loading="busy === 'convert'" @click="convert(true)">
            <CheckOutlined /> {{ $t('Rental_Convert_Activate') }}
            <template #overlay>
              <a-menu>
                <a-menu-item key="draft" :disabled="!!busy" @click="convert(false)">
                  <FileTextOutlined /> {{ $t('Rental_Convert_Draft') }}
                </a-menu-item>
              </a-menu>
            </template>
          </a-dropdown-button>
          <a-button v-if="r.contract_id" type="primary" @click="$router.push(`/rental/contracts/${r.contract_id}`)">
            <template #icon><FileDoneOutlined /></template>
            {{ $t('Rental_Open_Contract') }} {{ r.contract_number }}
          </a-button>
        </template>
      </template>
    </PageHeader>

    <div v-if="loading" class="loading"><a-spin size="large" /></div>

    <a-result v-else-if="!r" status="404" :title="$t('Rental_Reservation_Not_Found')">
      <template #extra>
        <a-button type="primary" @click="$router.push('/rental/reservations')">{{ $t('Rental_Reservations') }}</a-button>
      </template>
    </a-result>

    <template v-else>
      <a-alert
        v-if="r.source === 'website' && live && !r.contract_id && !r.confirmed_at" type="warning" show-icon class="block"
        :message="$t('Rental_Website_Request_Notice')" :description="$t('Rental_Website_Request_Help')"
      />
      <a-alert
        v-else-if="r.source === 'website' && r.confirmed_at && r.status === 'held'" type="success" show-icon class="block"
        :message="`${$t('Rental_Confirmed')} ${dateTime(r.confirmed_at)}`"
      />
      <a-alert v-if="r.contract_id && r.status === 'held'" type="info" show-icon class="block">
        <template #message>
          {{ $t('Rental_Hold_Linked_Draft') }}
          <router-link :to="`/rental/contracts/${r.contract_id}`">{{ r.contract_number }}</router-link>
        </template>
      </a-alert>
      <a-alert v-if="r.status === 'cancelled'" type="warning" show-icon class="block"
        :message="`${$t('Rental_Hold_Cancelled')} ${dateTime(r.cancelled_at)}`" :description="r.cancel_reason || undefined" />
      <a-alert v-if="r.status === 'expired'" type="warning" show-icon class="block" :message="$t('Rental_Hold_Expired_Notice')" />
      <a-alert v-if="r.customer.is_blacklisted" type="error" show-icon class="block" :message="$t('Rental_Customer_Blacklisted_Notice')" />

      <!-- Hold hero -->
      <a-card size="small" class="block hero">
        <div class="hero-grid">
          <div class="hold" :class="r.stage">
            <span class="hold-ic"><LockOutlined v-if="live" /><UnlockOutlined v-else /></span>
            <span class="hold-body">
              <a-tag :color="optionOf(RESERVATION_STAGES, r.stage).color">{{ labelOf(RESERVATION_STAGES, r.stage) }}</a-tag>
              <template v-if="live">
                <strong class="hold-countdown">{{ countdown }}</strong>
                <span class="hold-until">{{ $t('Rental_Held_Until') }} {{ dateTime(r.hold_until) }}</span>
              </template>
              <span v-else-if="r.status === 'converted'" class="hold-until">{{ $t('Rental_Converted_On') }} {{ dateTime(r.converted_at) }}</span>
            </span>
          </div>
          <div class="fig">
            <span class="fig-label">{{ $t('Rental_Period') }}</span>
            <span class="fig-value sm">{{ dateTime(r.start_date) }}</span>
            <span class="fig-note">→ {{ dateTime(r.end_date) }} · {{ r.periods }} {{ unitLabel(r.rate_period) }}</span>
          </div>
          <div class="fig">
            <span class="fig-label">{{ r.billing_type === 'upfront' ? $t('Rental_Total_Upfront') : $t('Rental_Each_Bill') }}</span>
            <span class="fig-value accent">{{ money(r.total_amount) }}</span>
            <span class="fig-note">{{ r.billing_type === 'upfront' ? labelOf(BILLING_TYPES, 'upfront') : perLabel(r.rate_period) }}</span>
          </div>
          <div class="fig">
            <span class="fig-label">{{ $t('Rental_Security_Deposit') }}</span>
            <span class="fig-value">{{ money(r.deposit_amount) }}</span>
          </div>
        </div>
      </a-card>

      <a-row :gutter="16">
        <a-col :xs="24" :lg="16">
          <a-card size="small" :title="$t('Rental_Items')" class="block">
            <div class="lines">
              <router-link v-for="l in r.lines" :key="l.id" :to="`/rental/items/${l.rental_item_id}`" class="line">
                <span class="line-media">
                  <img v-if="l.image_url" :src="l.image_url" alt="" loading="lazy" />
                  <component :is="typeIcon(l.item_type)" v-else :size="20" :stroke-width="1.6" />
                </span>
                <span class="line-body">
                  <span class="line-name">{{ l.item_name }}</span>
                  <span class="line-sub">{{ l.item_code }}<template v-if="l.quantity > 1"> · × {{ l.quantity }}</template></span>
                </span>
                <span class="line-rate">{{ money(l.rate) }} <small>{{ perLabel(r.rate_period) }}</small></span>
                <strong class="line-amount">{{ money(l.amount) }}</strong>
              </router-link>
            </div>
            <dl class="totals">
              <div><dt>{{ $t('Rental_Rent_Per_Period') }}</dt><dd>{{ money(r.rent_amount) }}</dd></div>
              <div v-if="r.discount"><dt>{{ $t('Discount') }}</dt><dd>− {{ money(r.discount) }}</dd></div>
              <div v-if="r.tax_amount"><dt>{{ $t('Rental_Tax') }} ({{ r.tax_rate }}%)</dt><dd>{{ money(r.tax_amount) }}</dd></div>
              <div class="strong"><dt>{{ $t('Rental_Contract_Value') }}</dt><dd>{{ r.contract_value !== null ? money(r.contract_value) : '—' }}</dd></div>
            </dl>
          </a-card>
        </a-col>

        <a-col :xs="24" :lg="8">
          <a-card size="small" :title="$t('Customer')" class="block">
            <router-link :to="`/rental/customers/${r.customer.id}`" class="cust">
              <a-avatar :size="44" :style="{ background: avatarColor(r.customer.name) }">{{ initials(r.customer.name) }}</a-avatar>
              <span class="cust-body">
                <strong>{{ r.customer.name }}</strong>
                <span>{{ r.customer.phone || r.customer.email || '—' }}</span>
              </span>
            </router-link>
            <div class="cust-tags">
              <a-tag :color="optionOf(ID_STATUSES, r.customer.id_status).color">{{ labelOf(ID_STATUSES, r.customer.id_status) }}</a-tag>
            </div>
          </a-card>
          <!-- What the customer typed on the website booking form -->
          <a-card v-if="r.request_fields" size="small" class="block">
            <template #title><GlobalOutlined /> {{ $t('Rental_Website_Request') }}</template>
            <template v-if="r.status_url" #extra>
              <a :href="r.status_url" target="_blank" rel="noopener">{{ $t('Rental_Customer_Page') }}</a>
            </template>
            <dl class="answers">
              <template v-if="r.request_fields.contact">
                <div><dt>{{ $t('Name') }}</dt><dd>{{ r.request_fields.contact.name || '—' }}</dd></div>
                <div><dt>{{ $t('Phone') }}</dt><dd><a v-if="r.request_fields.contact.phone" :href="`tel:${r.request_fields.contact.phone}`">{{ r.request_fields.contact.phone }}</a><template v-else>—</template></dd></div>
                <div><dt>{{ $t('Email') }}</dt><dd><a v-if="r.request_fields.contact.email" :href="`mailto:${r.request_fields.contact.email}`">{{ r.request_fields.contact.email }}</a><template v-else>—</template></dd></div>
              </template>
              <div v-for="a in (r.request_fields.answers || [])" :key="a.id">
                <dt>{{ fieldLabel(a) }}</dt>
                <dd>{{ answerText(a) }}</dd>
              </div>
            </dl>
            <p v-if="(r.request_fields.answers || []).some(a => a.input === 'file')" class="muted answers-note">{{ $t('Rental_Website_File_Note') }}</p>
          </a-card>
          <a-card v-if="r.notes" size="small" :title="r.source === 'website' ? $t('Rental_Customer_Notes') : $t('Rental_Internal_Notes')" class="block">
            <p class="prose">{{ r.notes }}</p>
          </a-card>
        </a-col>
      </a-row>
    </template>

    <!-- Extend / revive hold -->
    <a-modal v-model:open="extendOpen" :title="r && r.status === 'expired' ? $t('Rental_Revive_Hold') : $t('Rental_Extend_Hold')"
      :ok-text="$t('Save')" :cancel-text="$t('Cancel')" :confirm-loading="busy === 'extend'" @ok="doExtend">
      <a-form layout="vertical">
        <a-form-item :label="$t('Rental_Hold_Until')" required>
          <a-date-picker v-model:value="holdUntil" show-time format="YYYY-MM-DD HH:mm" value-format="YYYY-MM-DD[T]HH:mm:ssZ" style="width: 100%" />
        </a-form-item>
        <div class="quick">
          <button v-for="h in [24, 48, 72, 168]" :key="h" type="button" class="quick-chip" @click="bump(h)">{{ h >= 48 ? $t('Rental_Plus_Days', { n: h / 24 }) : $t('Rental_Plus_Hours', { n: h }) }}</button>
        </div>
      </a-form>
    </a-modal>

    <!-- Cancel -->
    <a-modal v-model:open="cancelOpen" :title="$t('Rental_Cancel_Hold')" :ok-text="$t('Rental_Cancel_Hold')" :cancel-text="$t('Cancel')"
      :ok-button-props="{ danger: true }" :confirm-loading="busy === 'cancel'" @ok="doCancel">
      <p class="muted">{{ $t('Rental_Cancel_Hold_Help') }}</p>
      <a-textarea v-model:value="cancelReason" :rows="3" :placeholder="$t('Rental_Blacklist_Reason')" />
    </a-modal>
  </div>
</template>

<script setup>
/**
 * One reservation (hold): countdown, items and estimate, with convert
 * (to an active contract, or a draft that keeps the hold), extend/revive and
 * cancel.
 */
import { ref, computed, createVNode, onMounted, onBeforeUnmount, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { message, Modal } from 'ant-design-vue';
import dayjs from 'dayjs';
import {
  ArrowLeftOutlined, DeleteOutlined, CloseCircleOutlined, FieldTimeOutlined, EditOutlined, CheckOutlined,
  FileTextOutlined, FileDoneOutlined, LockOutlined, UnlockOutlined, ExclamationCircleOutlined,
  SafetyCertificateOutlined, GlobalOutlined,
} from '@ant-design/icons-vue';
import { fieldLabel, optionLabel } from './rentalTypes';
import PageHeader from '../../components/PageHeader.vue';
import { useFormat } from '../../composables/useFormat';
import { useAuthStore } from '../../stores/auth';
import http from '../../lib/http';
import { t } from '../../i18n';
import {
  RESERVATION_STAGES, BILLING_TYPES, ID_STATUSES,
  labelOf, optionOf, perLabel, unitLabel, typeIcon, initials, avatarColor,
} from './rentalOptions';

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();
const { money, dateTime } = useFormat();

const canEdit = computed(() => auth.can('rnt_reservations_edit'));
const canDelete = computed(() => auth.can('rnt_reservations_delete'));
const canConvert = computed(() => auth.can('rnt_contracts_add'));

const id = computed(() => route.params.id);
const r = ref(null);
const loading = ref(false);
const busy = ref('');
const now = ref(dayjs());
let ticker = null;

const live = computed(() => r.value && ['held', 'expiring'].includes(r.value.stage));

/** "1d 4h 12m" until the hold runs out, ticking every 30s. */
const countdown = computed(() => {
  if (!r.value) return '';
  const mins = Math.max(0, dayjs(r.value.hold_until).diff(now.value, 'minute'));
  const d = Math.floor(mins / 1440);
  const h = Math.floor((mins % 1440) / 60);
  const m = mins % 60;
  return [d ? `${d}d` : '', h ? `${h}h` : '', `${m}m`].filter(Boolean).join(' ');
});

async function load(spinner = true) {
  if (spinner) loading.value = true;
  try {
    const data = await http.get(`rental/reservations/${id.value}`);
    r.value = data?.reservation || null;
  } catch (e) {
    if (spinner) r.value = null;
    if (e?.status !== 404) message.error(t('Rental_Could_Not_Load_Reservation', 'Could not load this reservation'));
  } finally {
    loading.value = false;
  }
}

function errorText(e) {
  const errors = e?.data?.errors;
  return (errors && Object.values(errors)[0]?.[0]) || e?.data?.message || t('InvalidData', 'Something went wrong');
}

/** A website form answer as text (choices / yes-no translated). */
function answerText(a) {
  const v = a.value;
  if (a.input === 'bool') return v ? t('Yes', 'Yes') : t('No', 'No');
  if (a.input === 'choice') return optionLabel(v);
  if (Array.isArray(v)) return v.join(', ');
  return v === null || v === undefined || v === '' ? '—' : String(v);
}

/** Accept a website booking: the hold runs to the end of the rental and the customer is emailed. */
async function confirmBooking() {
  if (busy.value) return;
  busy.value = 'confirm';
  try {
    await http.post(`rental/reservations/${id.value}/confirm`);
    message.success(t('Rental_Booking_Confirmed_Msg', 'Booking confirmed — the customer has been emailed'));
    await load(false);
  } catch (e) {
    message.error(errorText(e));
  } finally {
    busy.value = '';
  }
}

async function convert(activate) {
  if (busy.value) return; // one convert at a time
  busy.value = 'convert';
  try {
    const res = await http.post(`rental/reservations/${id.value}/convert`, { activate: activate ? 1 : 0 });
    message.success(t('Rental_Converted_Ok', 'Contract created'));
    if (res?.contract_id) router.push(`/rental/contracts/${res.contract_id}`);
  } catch (e) {
    message.error(errorText(e), 6);
  } finally {
    busy.value = '';
  }
}

// ----- extend -----
const extendOpen = ref(false);
const holdUntil = ref(null);

function openExtend() {
  const from = r.value.status === 'expired' || dayjs(r.value.hold_until).isBefore(dayjs()) ? dayjs() : dayjs(r.value.hold_until);
  holdUntil.value = from.add(24, 'hour').format('YYYY-MM-DD[T]HH:mm:ssZ');
  extendOpen.value = true;
}

function bump(hours) {
  const from = r.value.status === 'expired' ? dayjs() : dayjs(r.value.hold_until);
  holdUntil.value = (from.isBefore(dayjs()) ? dayjs() : from).add(hours, 'hour').format('YYYY-MM-DD[T]HH:mm:ssZ');
}

async function doExtend() {
  busy.value = 'extend';
  try {
    await http.post(`rental/reservations/${id.value}/extend-hold`, { hold_until: holdUntil.value });
    message.success(t('Updated_in_successfully', 'Updated successfully'));
    extendOpen.value = false;
    await load(false);
  } catch (e) {
    message.error(errorText(e), 6);
  } finally {
    busy.value = '';
  }
}

// ----- cancel -----
const cancelOpen = ref(false);
const cancelReason = ref('');

async function doCancel() {
  busy.value = 'cancel';
  try {
    await http.post(`rental/reservations/${id.value}/cancel`, { reason: cancelReason.value || null });
    message.success(t('Updated_in_successfully', 'Updated successfully'));
    cancelOpen.value = false;
    await load(false);
  } catch (e) {
    message.error(errorText(e));
  } finally {
    busy.value = '';
  }
}

function remove() {
  Modal.confirm({
    title: t('Delete_Title', 'Are you sure?'),
    icon: createVNode(ExclamationCircleOutlined),
    content: r.value.reservation_number,
    okType: 'danger',
    okText: t('Delete_confirmButtonText', 'Yes, delete it!'),
    cancelText: t('Delete_cancelButtonText', 'Cancel'),
    async onOk() {
      try {
        await http.delete(`rental/reservations/${id.value}`);
        message.success(t('Deleted_Successfully', 'Deleted successfully'));
        router.push('/rental/reservations');
      } catch (e) {
        message.error(errorText(e));
      }
    },
  });
}

watch(id, (v) => { if (v) load(); });
onMounted(() => {
  load();
  ticker = setInterval(() => { now.value = dayjs(); }, 30000);
});
onBeforeUnmount(() => clearInterval(ticker));
</script>

<style scoped>
.loading {
  display: flex;
  justify-content: center;
  padding: 96px 0;
}
.block {
  margin-bottom: 16px;
}
.muted {
  opacity: 0.6;
}
.prose {
  margin: 0;
  white-space: pre-line;
}
.answers {
  margin: 0;
  display: grid;
  gap: 8px;
}
.answers > div {
  display: grid;
  grid-template-columns: minmax(0, 0.9fr) minmax(0, 1.1fr);
  gap: 10px;
}
.answers dt {
  opacity: 0.6;
  font-size: 12.5px;
}
.answers dd {
  margin: 0;
  font-weight: 500;
  word-break: break-word;
  white-space: pre-line;
}
.answers-note {
  margin: 10px 0 0;
  font-size: 12px;
}
.hero-grid {
  display: grid;
  grid-template-columns: 1.4fr repeat(3, 1fr);
  gap: 12px;
}
.hold {
  display: flex;
  align-items: center;
  gap: 14px;
  padding: 14px 16px;
  border-radius: 14px;
  background: rgba(2, 132, 199, 0.08);
}
.hold.expiring {
  background: rgba(217, 119, 6, 0.1);
}
.hold.converted {
  background: rgba(22, 163, 74, 0.08);
}
.hold.expired,
.hold.cancelled {
  background: rgba(128, 128, 128, 0.08);
}
.hold-ic {
  width: 44px;
  height: 44px;
  flex: none;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 12px;
  font-size: 20px;
  color: #0284c7;
  background: rgba(2, 132, 199, 0.14);
}
.hold.expiring .hold-ic {
  color: #d97706;
  background: rgba(217, 119, 6, 0.16);
}
.hold.converted .hold-ic {
  color: #16a34a;
  background: rgba(22, 163, 74, 0.14);
}
.hold-body {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 2px;
}
.hold-countdown {
  font-size: 22px;
  line-height: 1.2;
}
.hold-until {
  font-size: 12px;
  opacity: 0.65;
}
.fig {
  display: flex;
  flex-direction: column;
  padding: 12px 14px;
  border-radius: 12px;
  background: rgba(128, 128, 128, 0.07);
}
.fig-label {
  font-size: 12px;
  opacity: 0.65;
}
.fig-value {
  font-size: 19px;
  font-weight: 700;
}
.fig-value.sm {
  font-size: 15px;
}
.fig-value.accent {
  color: var(--app-primary, #6d28d9);
}
.fig-note {
  font-size: 11.5px;
  opacity: 0.6;
}
.lines {
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.line {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 8px 10px;
  border: 1px solid rgba(128, 128, 128, 0.18);
  border-radius: 12px;
  color: inherit;
}
.line:hover {
  border-color: rgba(var(--app-primary-rgb, 109, 40, 217), 0.5);
}
.line-media {
  width: 52px;
  height: 42px;
  flex: none;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 9px;
  overflow: hidden;
  background: rgba(128, 128, 128, 0.12);
}
.line-media img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}
.line-body {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
}
.line-name {
  font-weight: 500;
}
.line-sub {
  font-size: 12px;
  opacity: 0.55;
}
.line-rate {
  font-size: 13px;
  white-space: nowrap;
}
.line-rate small {
  opacity: 0.6;
}
.line-amount {
  min-width: 100px;
  text-align: end;
}
.totals {
  margin: 14px 0 0 auto;
  max-width: 340px;
}
.totals > div {
  display: flex;
  justify-content: space-between;
  padding: 6px 0;
  border-bottom: 1px dashed rgba(128, 128, 128, 0.22);
  font-size: 13px;
}
.totals dd {
  margin: 0;
  font-weight: 500;
}
.totals .strong dd,
.totals .strong dt {
  font-weight: 700;
}
.cust {
  display: flex;
  align-items: center;
  gap: 12px;
  color: inherit;
}
.cust-body {
  display: flex;
  flex-direction: column;
}
.cust-body span {
  font-size: 12.5px;
  opacity: 0.6;
}
.cust-tags {
  margin-top: 12px;
}
.quick {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}
.quick-chip {
  padding: 3px 11px;
  border: 1px solid rgba(128, 128, 128, 0.28);
  border-radius: 999px;
  background: transparent;
  color: inherit;
  font: inherit;
  font-size: 12.5px;
  cursor: pointer;
}
.quick-chip:hover {
  border-color: var(--app-primary, #6d28d9);
  color: var(--app-primary, #6d28d9);
}

@media (max-width: 991px) {
  .hero-grid {
    grid-template-columns: 1fr 1fr;
  }
  .hold {
    grid-column: 1 / -1;
  }
}
@media (max-width: 575px) {
  .line-rate {
    display: none;
  }
}
</style>
