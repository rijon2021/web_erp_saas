<template>
  <div class="page">
    <PageHeader
      :title="pageTitle"
      :breadcrumb="[$t('Rental_Management'), isReservation ? $t('Rental_Reservations') : $t('Rental_Contracts'), isEdit ? number : $t('Add')]"
    >
      <template #actions>
        <a-button @click="goBack">{{ $t('Cancel') }}</a-button>
        <a-button v-if="isReservation" type="primary" :loading="saving === 'draft'" :disabled="!!saving || hasConflicts" @click="submit(false)">
          <template #icon><LockOutlined /></template>
          {{ $t('Rental_Hold_Items') }}
        </a-button>
        <a-button v-else :loading="saving === 'draft'" :disabled="!!saving" @click="submit(false)">
          {{ isEdit ? $t('Save') : $t('Rental_Save_Draft') }}
        </a-button>
        <a-button v-if="!isReservation && (!isEdit || status === 'draft')" type="primary" :loading="saving === 'active'" :disabled="!!saving || hasConflicts" @click="submit(true)">
          <template #icon><CheckOutlined /></template>
          {{ $t('Rental_Save_Activate') }}
        </a-button>
      </template>
    </PageHeader>

    <div v-if="loading" class="loading"><a-spin size="large" /></div>

    <a-form v-else ref="formRef" :model="form" :rules="rules" layout="vertical">
      <a-alert v-if="isReservation" type="info" show-icon class="block" :message="$t('Rental_Reservation_Form_Note')" />
      <a-alert v-if="!isReservation && status === 'active'" type="info" show-icon class="block" :message="$t('Rental_Editing_Active_Note')" />

      <a-row :gutter="16">
        <a-col :xs="24" :lg="16">
          <!-- Customer -->
          <a-card size="small" class="block">
            <template #title><span class="card-title"><UserOutlined /> {{ $t('Customer') }}</span></template>
            <template #extra>
              <router-link v-if="canAddCustomer" to="/rental/customers/create" target="_blank">
                <PlusOutlined /> {{ $t('Rental_Add_Customer') }}
              </router-link>
            </template>
            <a-form-item name="rental_customer_id" class="no-mb">
              <a-select
                v-model:value="form.rental_customer_id" show-search :filter-option="false"
                :options="customerOptions" :placeholder="$t('Rental_Search_Customer_Placeholder')"
                :not-found-content="$t('Rental_No_Customers_Found')"
                @search="searchCustomers" @focus="searchCustomers('')"
              >
                <template #option="{ label, sub, blacklisted }">
                  <div class="opt">
                    <span class="opt-main">{{ label }}</span>
                    <span class="opt-sub">{{ sub }}</span>
                    <a-tag v-if="blacklisted" color="error" class="opt-tag">{{ $t('Rental_Blacklisted') }}</a-tag>
                  </div>
                </template>
              </a-select>
            </a-form-item>
            <div v-if="pickedCustomer" class="picked">
              <a-avatar :size="40" :style="{ background: avatarColor(pickedCustomer.name) }">{{ initials(pickedCustomer.name) }}</a-avatar>
              <div class="picked-text">
                <strong>{{ pickedCustomer.name }}</strong>
                <span>{{ [pickedCustomer.phone, pickedCustomer.id_number].filter(Boolean).join(' · ') || '—' }}</span>
              </div>
              <a-tag :color="optionOf(ID_STATUSES, pickedCustomer.id_status).color">{{ labelOf(ID_STATUSES, pickedCustomer.id_status) }}</a-tag>
            </div>
          </a-card>

          <!-- Period -->
          <a-card size="small" class="block">
            <template #title><span class="card-title"><CalendarOutlined /> {{ $t('Rental_Period') }}</span></template>
            <a-form-item :label="$t('Rental_Charged_Per')">
              <a-radio-group v-model:value="form.rate_period" button-style="solid" class="wrap" @change="onPeriodChange">
                <a-radio-button v-for="p in periodOptions" :key="p.value" :value="p.value">{{ p.label }}</a-radio-button>
              </a-radio-group>
            </a-form-item>
            <a-row :gutter="16">
              <a-col :xs="24" :md="12">
                <a-form-item :label="$t('Rental_Start')" name="start_date">
                  <a-date-picker
                    v-model:value="form.start_date" show-time format="YYYY-MM-DD HH:mm"
                    value-format="YYYY-MM-DD[T]HH:mm:ssZ" style="width: 100%" :allow-clear="false"
                  />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="12">
                <a-form-item :label="$t('Rental_End')" name="end_date">
                  <a-date-picker
                    v-model:value="form.end_date" show-time format="YYYY-MM-DD HH:mm"
                    value-format="YYYY-MM-DD[T]HH:mm:ssZ" style="width: 100%"
                    :disabled="openEnded" :placeholder="openEnded ? $t('Rental_Open_Ended') : ''"
                    :disabled-date="d => form.start_date && d.isBefore(dayjs(form.start_date), 'day')"
                  />
                </a-form-item>
              </a-col>
            </a-row>
            <div class="quick">
              <span class="quick-label">{{ $t('Rental_Quick_Duration') }}</span>
              <button
                v-for="n in quickCounts" :key="n" type="button" class="quick-chip"
                :disabled="openEnded" @click="setDuration(n)"
              >{{ n }} {{ unitLabel(form.rate_period) }}</button>
              <a-checkbox v-if="!isReservation && form.billing_type === 'recurring'" v-model:checked="openEnded" class="quick-open">
                {{ $t('Rental_Open_Ended_Help') }}
              </a-checkbox>
            </div>
            <a-row v-if="isReservation" :gutter="16" class="hold-row">
              <a-col :xs="24" :md="12">
                <a-form-item :label="$t('Rental_Hold_Until')" name="hold_until" :extra="$t('Rental_Hold_Until_Help')" class="no-mb">
                  <a-date-picker
                    v-model:value="form.hold_until" show-time format="YYYY-MM-DD HH:mm"
                    value-format="YYYY-MM-DD[T]HH:mm:ssZ" style="width: 100%" :allow-clear="false"
                    :disabled-date="d => d.isBefore(dayjs(), 'day')"
                  />
                </a-form-item>
              </a-col>
            </a-row>
          </a-card>

          <!-- Items -->
          <a-card size="small" class="block">
            <template #title><span class="card-title"><AppstoreOutlined /> {{ $t('Rental_Items') }}</span></template>
            <div class="lines">
              <div class="line line--head">
                <span>{{ $t('Rental_Item') }}</span>
                <span>{{ $t('Quantity') }}</span>
                <span>{{ $t('Rental_Rate') }} {{ perLabel(form.rate_period) }}</span>
                <span class="right">{{ $t('Rental_Line_Total') }}</span>
                <span></span>
              </div>
              <div v-for="(line, i) in form.items" :key="line.key" class="line" :class="{ 'line--bad': conflictFor(line.rental_item_id) }">
                <a-select
                  v-model:value="line.rental_item_id" show-search option-filter-prop="label"
                  :options="itemOptions(line)" :placeholder="$t('Rental_Pick_Item')"
                  @change="id => onItemPicked(line, id)"
                >
                  <template #option="{ label, code, status, qty }">
                    <div class="opt">
                      <span class="opt-main">{{ label }}</span>
                      <span class="opt-sub">{{ code }}<template v-if="qty > 1"> · ×{{ qty }}</template></span>
                      <a-tag :color="optionOf(RENTAL_STATUSES, status).color" class="opt-tag">{{ labelOf(RENTAL_STATUSES, status) }}</a-tag>
                    </div>
                  </template>
                </a-select>
                <a-input-number v-model:value="line.quantity" :min="1" :max="maxQty(line)" :precision="0" style="width: 100%" />
                <a-input-number v-model:value="line.rate" :min="0" style="width: 100%" @change="line.rateTouched = true" />
                <span class="right line-total">{{ money((line.rate || 0) * (line.quantity || 0)) }}</span>
                <a-button type="text" danger :disabled="form.items.length === 1" @click="form.items.splice(i, 1)">
                  <template #icon><DeleteOutlined /></template>
                </a-button>
                <div v-if="conflictFor(line.rental_item_id)" class="line-error">
                  <WarningOutlined /> {{ conflictText(conflictFor(line.rental_item_id)) }}
                </div>
                <div v-else-if="noRateFor(line)" class="line-warn">
                  <InfoCircleOutlined /> {{ $t('Rental_No_Rate_For_Period') }}
                </div>
              </div>
            </div>
            <a-button type="dashed" block class="add-line" @click="addLine">
              <template #icon><PlusOutlined /></template>
              {{ $t('Rental_Add_Item_Line') }}
            </a-button>
          </a-card>

          <!-- Billing -->
          <a-card size="small" class="block">
            <template #title><span class="card-title"><DollarOutlined /> {{ $t('Rental_Billing') }}</span></template>
            <a-form-item :label="$t('Rental_Billing_Type')">
              <a-segmented v-model:value="form.billing_type" :options="billingOptions" block @change="onBillingChange" />
            </a-form-item>
            <a-row :gutter="16">
              <a-col :xs="12" :md="6">
                <a-form-item :label="$t('Discount')" :extra="form.billing_type === 'upfront' ? $t('Rental_Discount_Whole') : $t('Rental_Discount_Per_Bill')">
                  <a-input-number v-model:value="form.discount" :min="0" style="width: 100%" />
                </a-form-item>
              </a-col>
              <a-col :xs="12" :md="6">
                <a-form-item :label="$t('Rental_Tax_Rate')">
                  <a-input-number v-model:value="form.tax_rate" :min="0" :max="100" style="width: 100%" addon-after="%" />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="12">
                <a-form-item :label="$t('Rental_Security_Deposit')">
                  <a-input-number v-model:value="form.deposit_amount" :min="0" style="width: 100%" />
                  <a v-if="suggestedDeposit && suggestedDeposit !== form.deposit_amount" class="hint-link" @click="form.deposit_amount = suggestedDeposit">
                    {{ $t('Rental_Use_Item_Deposits') }} ({{ money(suggestedDeposit) }})
                  </a>
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="12">
                <a-form-item :label="$t('Warehouse')">
                  <a-select v-model:value="form.warehouse_id" allow-clear :options="warehouseOptions" :placeholder="$t('Rental_All_Branches')" />
                </a-form-item>
              </a-col>
            </a-row>

            <a-collapse v-if="!isReservation" ghost class="late">
              <a-collapse-panel key="late" :header="`${$t('Rental_Late_Payments')} — ${lateSummary}`">
                <a-row :gutter="16">
                  <a-col :xs="12" :md="6">
                    <a-form-item :label="$t('Rental_Grace_Period')">
                      <a-input-number v-model:value="form.grace_days" :min="0" :max="365" :precision="0" style="width: 100%" :addon-after="$t('Rental_Unit_Days')" />
                    </a-form-item>
                  </a-col>
                  <a-col :xs="12" :md="6">
                    <a-form-item :label="$t('Rental_Late_Fee')">
                      <a-select v-model:value="form.late_fee_type" :options="feeTypeOptions" />
                    </a-form-item>
                  </a-col>
                  <a-col v-if="form.late_fee_type !== 'none'" :xs="12" :md="6">
                    <a-form-item :label="form.late_fee_type === 'percent' ? $t('Rental_Late_Fee_Percent') : $t('Rental_Late_Fee_Amount')">
                      <a-input-number v-model:value="form.late_fee_value" :min="0" :max="form.late_fee_type === 'percent' ? 100 : undefined" style="width: 100%" />
                    </a-form-item>
                  </a-col>
                  <a-col v-if="form.late_fee_type !== 'none'" :xs="12" :md="6">
                    <a-form-item :label="$t('Rental_Late_Fee_Charged')">
                      <a-select v-model:value="form.late_fee_frequency" :options="frequencyOptions" />
                    </a-form-item>
                  </a-col>
                </a-row>
              </a-collapse-panel>
            </a-collapse>
          </a-card>

          <!-- Terms -->
          <a-card size="small" class="block">
            <template #title><span class="card-title"><FileTextOutlined /> {{ isReservation ? $t('Rental_Internal_Notes') : $t('Rental_Terms_Notes') }}</span></template>
            <a-form-item v-if="!isReservation" :label="$t('Rental_Agreement_Terms')" :extra="$t('Rental_Terms_Help')">
              <a-textarea v-model:value="form.terms" :rows="6" />
            </a-form-item>
            <a-form-item :label="isReservation ? '' : $t('Rental_Internal_Notes')" class="no-mb">
              <a-textarea v-model:value="form.notes" :rows="3" :placeholder="$t('Rental_Notes_Placeholder')" />
            </a-form-item>
          </a-card>
        </a-col>

        <!-- Live summary -->
        <a-col :xs="24" :lg="8">
          <div class="sticky">
            <a-card size="small" class="summary">
              <div class="sum-head">
                <span class="sum-number">{{ number || '—' }}</span>
                <a-spin v-if="calculating" size="small" />
              </div>
              <div class="sum-big">
                <span class="sum-label">{{ form.billing_type === 'upfront' ? $t('Rental_Total_Upfront') : $t('Rental_Each_Bill') }}</span>
                <span class="sum-amount">{{ money(totals.total_amount || 0) }}</span>
                <span v-if="form.billing_type === 'recurring'" class="sum-per">{{ perLabel(form.rate_period) }}</span>
              </div>
              <dl class="sum-rows">
                <div><dt>{{ $t('Rental_Duration') }}</dt><dd>{{ totals.periods ? `${totals.periods} ${unitLabel(form.rate_period)}` : $t('Rental_Open_Ended') }}</dd></div>
                <div><dt>{{ $t('Rental_Rent_Per_Period') }}</dt><dd>{{ money(totals.rent_amount || 0) }}</dd></div>
                <div v-if="form.discount"><dt>{{ $t('Discount') }}</dt><dd>− {{ money(form.discount) }}</dd></div>
                <div v-if="totals.tax_amount"><dt>{{ $t('Rental_Tax') }} ({{ form.tax_rate }}%)</dt><dd>{{ money(totals.tax_amount) }}</dd></div>
                <div class="sum-strong"><dt>{{ $t('Rental_Contract_Value') }}</dt><dd>{{ totals.contract_value !== null && totals.contract_value !== undefined ? money(totals.contract_value) : '—' }}</dd></div>
                <div><dt>{{ $t('Rental_Security_Deposit') }}</dt><dd>{{ money(form.deposit_amount || 0) }}</dd></div>
              </dl>
              <div v-if="isReservation && form.hold_until" class="sum-hold">
                <LockOutlined /> {{ $t('Rental_Held_Until') }} {{ dateTime(form.hold_until) }}
              </div>
              <a-alert v-if="hasConflicts" type="error" show-icon :message="isReservation ? $t('Rental_Conflicts_Block_Hold') : $t('Rental_Conflicts_Block')" class="sum-alert" />
              <a-alert v-else-if="pickedCustomer && pickedCustomer.id_status === 'expired'" type="warning" show-icon :message="$t('Rental_ID_Expired_Warning')" class="sum-alert" />
            </a-card>
          </div>
        </a-col>
      </a-row>
    </a-form>
  </div>
</template>

<script setup>
/**
 * Create / edit a rental contract.
 *
 * Totals and double-booking are previewed live via POST rental/contracts/calculate
 * (the same maths the save runs), debounced on every relevant change.
 * "Save & activate" activates straight away; the API refuses it while any
 * item is double-booked.
 */
import { ref, computed, watch, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { message } from 'ant-design-vue';
import dayjs from 'dayjs';
import {
  UserOutlined, CalendarOutlined, AppstoreOutlined, DollarOutlined, FileTextOutlined,
  PlusOutlined, DeleteOutlined, CheckOutlined, WarningOutlined, InfoCircleOutlined, LockOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import { useFormat } from '../../composables/useFormat';
import { useAuthStore } from '../../stores/auth';
import http from '../../lib/http';
import { t, i18n } from '../../i18n';
import {
  RENTAL_PERIODS, RENTAL_STATUSES, BILLING_TYPES, ID_STATUSES,
  tr, labelOf, optionOf, perLabel, unitLabel, initials, avatarColor,
} from './rentalOptions';

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();
const { money, dateTime } = useFormat();

const canAddCustomer = computed(() => auth.can('rnt_customers_add'));
const isEdit = computed(() => !!route.params.id);
// The same form edits reservations (holds): no late fees, terms or
// open-ended dates, plus a "hold until".
const isReservation = computed(() => String(route.name || '').startsWith('rental-reservation'));
const base = computed(() => (isReservation.value ? 'rental/reservations' : 'rental/contracts'));
const pageTitle = computed(() => {
  if (isReservation.value) return isEdit.value ? `${t('Rental_Edit_Reservation', 'Edit reservation')} ${number.value}` : t('Rental_New_Reservation', 'New reservation');
  return isEdit.value ? `${t('Rental_Edit_Contract', 'Edit contract')} ${number.value}` : t('Rental_New_Contract', 'New contract');
});
const formRef = ref();
const loading = ref(true);
const saving = ref('');
const number = ref('');
const status = ref('draft');

let lineSeq = 0;
const newLine = () => ({ key: lineSeq++, rental_item_id: undefined, quantity: 1, rate: null, rateTouched: false });

const form = ref({
  rental_customer_id: undefined,
  rate_period: 'month',
  billing_type: 'recurring',
  start_date: dayjs().minute(0).second(0).format('YYYY-MM-DD[T]HH:mm:ssZ'),
  end_date: null,
  items: [newLine()],
  discount: 0,
  tax_rate: 0,
  deposit_amount: 0,
  warehouse_id: undefined,
  grace_days: 0,
  late_fee_type: 'none',
  late_fee_value: 0,
  late_fee_frequency: 'once',
  terms: '',
  notes: '',
  hold_until: null,
});
const openEnded = ref(false);

const periodOptions = computed(() => tr(RENTAL_PERIODS));
const billingOptions = computed(() => tr(BILLING_TYPES));
const feeTypeOptions = computed(() => [
  { value: 'none', label: t('Rental_Late_Fee_None', 'No late fee') },
  { value: 'fixed', label: t('Rental_Late_Fee_Fixed', 'Fixed amount') },
  { value: 'percent', label: t('Rental_Late_Fee_Percentage', 'Percentage') },
]);
const frequencyOptions = computed(() => [
  { value: 'once', label: t('Rental_Late_Fee_Once', 'Once per late payment') },
  { value: 'daily', label: t('Rental_Late_Fee_Daily', 'Every day it stays late') },
]);
const quickCounts = computed(() => ({ hour: [2, 4, 8, 24], day: [1, 3, 7, 14], week: [1, 2, 4, 8], month: [1, 3, 6, 12], year: [1, 2, 3, 5] }[form.value.rate_period]));

const lateSummary = computed(() => {
  const f = form.value;
  if (f.late_fee_type === 'none') return t('Rental_Late_Fee_None', 'No late fee');
  const v = f.late_fee_type === 'percent' ? `${f.late_fee_value || 0}%` : money(f.late_fee_value || 0);
  return `${v} · ${f.grace_days || 0} ${t('Rental_Unit_Days', 'days')}`;
});

const rules = computed(() => ({
  rental_customer_id: [{ required: true, message: t('Rental_Pick_Customer', 'Pick a customer') }],
  start_date: [{ required: true, message: t('Field_is_required', 'This field is required') }],
  end_date: openEnded.value && !isReservation.value ? [] : [{ required: true, message: t('Rental_End_Required', 'Pick an end date, or make it open-ended') }],
  ...(isReservation.value ? { hold_until: [{ required: true, message: t('Field_is_required', 'This field is required') }] } : {}),
}));

// ----- meta -----
const items = ref([]);
const warehouses = ref([]);
const warehouseOptions = computed(() => warehouses.value.map(w => ({ value: w.id, label: w.name })));
const itemById = id => items.value.find(i => i.id === id);

function itemOptions(line) {
  const taken = new Set(form.value.items.filter(l => l !== line).map(l => l.rental_item_id));
  return items.value.map(i => ({
    value: i.id, label: i.name, code: i.code, status: i.status, qty: i.quantity,
    disabled: taken.has(i.id) || i.status === 'maintenance',
  }));
}

function maxQty(line) {
  return itemById(line.rental_item_id)?.quantity || 1;
}

function rateOf(item) {
  return item?.rates?.[form.value.rate_period] ?? null;
}

function noRateFor(line) {
  const item = itemById(line.rental_item_id);
  return item && rateOf(item) === null && !line.rateTouched;
}

function onItemPicked(line, id) {
  const item = itemById(id);
  line.rateTouched = false;
  line.quantity = 1;
  line.rate = rateOf(item) ?? 0;
}

/** New period → reprice lines the user hasn't priced by hand. */
function onPeriodChange() {
  form.value.items.forEach((line) => {
    const item = itemById(line.rental_item_id);
    if (item && !line.rateTouched) line.rate = rateOf(item) ?? 0;
  });
}

function onBillingChange(value) {
  if (value === 'upfront') openEnded.value = false;
}

function addLine() {
  form.value.items.push(newLine());
}

function setDuration(n) {
  const start = dayjs(form.value.start_date);
  if (!start.isValid()) return;
  form.value.end_date = start.add(n, form.value.rate_period).format('YYYY-MM-DD[T]HH:mm:ssZ');
}

watch(openEnded, (v) => { if (v) form.value.end_date = null; });

const suggestedDeposit = computed(() => form.value.items.reduce((sum, l) => {
  const item = itemById(l.rental_item_id);
  return sum + (item ? Number(item.deposit_amount || 0) * (l.quantity || 1) : 0);
}, 0));

// ----- customers -----
const customers = ref([]);
let custTimer = null;
const customerOptions = computed(() => customers.value.map(c => ({
  value: c.id,
  label: c.name,
  sub: [c.phone, c.id_number].filter(Boolean).join(' · '),
  blacklisted: c.is_blacklisted,
  // An edited contract keeps its customer even if they were blacklisted since.
  disabled: c.is_blacklisted && !(isEdit.value && c.id === originalCustomer.value),
})));
const pickedCustomer = computed(() => customers.value.find(c => c.id === form.value.rental_customer_id) || null);
const originalCustomer = ref(null);

function searchCustomers(value, params = {}) {
  clearTimeout(custTimer);
  custTimer = setTimeout(async () => {
    try {
      const data = await http.get('rental/contracts/customer-search', { search: value || '', ...params });
      const list = data?.customers || [];
      // Keep the picked customer in the list so its label survives a new search.
      const picked = pickedCustomer.value;
      customers.value = picked && !list.some(c => c.id === picked.id) ? [picked, ...list] : list;
    } catch (e) { /* keep list */ }
  }, params.id ? 0 : 250);
}

// ----- live totals + conflicts -----
const totals = ref({});
const conflicts = ref([]);
const calculating = ref(false);
const hasConflicts = computed(() => conflicts.value.length > 0);
let calcTimer = null;
let calcSeq = 0;

function payloadLines() {
  return form.value.items
    .filter(l => l.rental_item_id)
    .map(l => ({ rental_item_id: l.rental_item_id, quantity: l.quantity || 1, rate: l.rate || 0 }));
}

function recalc() {
  clearTimeout(calcTimer);
  calcTimer = setTimeout(async () => {
    if (!form.value.start_date) return;
    const seq = ++calcSeq;
    calculating.value = true;
    try {
      const data = await http.post('rental/contracts/calculate', {
        start_date: form.value.start_date,
        end_date: openEnded.value ? null : form.value.end_date,
        rate_period: form.value.rate_period,
        billing_type: form.value.billing_type,
        discount: form.value.discount || 0,
        tax_rate: form.value.tax_rate || 0,
        items: payloadLines(),
        contract_id: isEdit.value && !isReservation.value ? route.params.id : null,
        reservation_id: isEdit.value && isReservation.value ? route.params.id : null,
      });
      if (seq !== calcSeq) return;
      totals.value = data?.totals || {};
      conflicts.value = data?.conflicts || [];
    } catch (e) { /* keep last preview */ } finally {
      if (seq === calcSeq) calculating.value = false;
    }
  }, 350);
}

watch(() => [
  form.value.start_date, form.value.end_date, form.value.rate_period, form.value.billing_type,
  form.value.discount, form.value.tax_rate, openEnded.value,
  JSON.stringify(payloadLines()),
], recalc);

function conflictFor(itemId) {
  return conflicts.value.find(c => c.item_id === itemId) || null;
}

function tp(key, fallback, params) {
  const out = i18n.global.t(key, params);
  if (out !== key) return out;
  return Object.entries(params).reduce((s, [k, v]) => s.replace(`{${k}}`, v), fallback);
}

function conflictText(c) {
  if (c.reason === 'maintenance') return t('Rental_Conflict_Maintenance', 'This item is in maintenance.');
  if (c.reason === 'inactive') return t('Rental_Conflict_Inactive', 'This item is inactive.');
  const base = tp('Rental_Conflict_Booked', 'Only {free} of {wanted} free for these dates', { free: c.available, wanted: c.requested });
  return c.contracts?.length ? `${base} (${c.contracts.join(', ')})` : base;
}

// ----- load / save -----
async function load() {
  loading.value = true;
  try {
    const [meta, record] = await Promise.all([
      http.get('rental/contracts/meta'),
      isEdit.value ? http.get(`${base.value}/${route.params.id}`) : Promise.resolve(null),
    ]);
    items.value = meta?.items || [];
    warehouses.value = meta?.warehouses || [];

    const c = record?.contract || record?.reservation;
    if (c) {
      number.value = c.contract_number || c.reservation_number;
      status.value = c.status;
      originalCustomer.value = c.rental_customer_id;
      form.value = {
        rental_customer_id: c.rental_customer_id,
        rate_period: c.rate_period,
        billing_type: c.billing_type,
        start_date: dayjs(c.start_date).format('YYYY-MM-DD[T]HH:mm:ssZ'),
        end_date: c.end_date ? dayjs(c.end_date).format('YYYY-MM-DD[T]HH:mm:ssZ') : null,
        items: c.lines.map(l => ({ key: lineSeq++, rental_item_id: l.rental_item_id, quantity: l.quantity, rate: l.rate, rateTouched: true })),
        discount: c.discount,
        tax_rate: c.tax_rate,
        deposit_amount: c.deposit_amount,
        warehouse_id: c.warehouse_id || undefined,
        grace_days: c.grace_days ?? 0,
        late_fee_type: c.late_fee_type || 'none',
        late_fee_value: c.late_fee_value ?? 0,
        late_fee_frequency: c.late_fee_frequency || 'once',
        terms: c.terms || '',
        notes: c.notes || '',
        hold_until: c.hold_until ? dayjs(c.hold_until).format('YYYY-MM-DD[T]HH:mm:ssZ') : null,
      };
      openEnded.value = !c.end_date;
      // Archived items still on this contract must stay selectable.
      c.lines.forEach((l) => {
        if (!itemById(l.rental_item_id)) {
          items.value.push({ id: l.rental_item_id, name: l.item_name, code: l.item_code, status: l.item_status || 'inactive', quantity: l.quantity, rates: {}, deposit_amount: 0 });
        }
      });
      searchCustomers('', { id: c.rental_customer_id });
    } else {
      number.value = (isReservation.value ? meta?.next_reservation_number : meta?.next_number) || '';
      const d = meta?.defaults || {};
      Object.assign(form.value, {
        grace_days: d.grace_days ?? 0,
        late_fee_type: d.late_fee_type || 'none',
        late_fee_value: d.late_fee_value ?? 0,
        late_fee_frequency: d.late_fee_frequency || 'once',
        terms: d.terms || '',
        tax_rate: d.tax_rate || 0,
        hold_until: dayjs().add(d.hold_hours || 48, 'hour').minute(0).second(0).format('YYYY-MM-DD[T]HH:mm:ssZ'),
      });
      if (isReservation.value) form.value.billing_type = 'upfront';
      // Deep links: from a customer or an item page.
      if (route.query.customer) {
        form.value.rental_customer_id = Number(route.query.customer);
        searchCustomers('', { id: route.query.customer });
      }
      if (route.query.item) {
        const item = itemById(Number(route.query.item));
        if (item) {
          form.value.rate_period = item.default_period || 'month';
          form.value.items = [{ ...newLine(), rental_item_id: item.id, rate: rateOf(item) ?? 0 }];
          form.value.deposit_amount = Number(item.deposit_amount || 0);
        }
      }
      // Dates from the availability timeline win; otherwise a sensible
      // starting length for the period (a year of months, one day…).
      if (route.query.start) {
        form.value.start_date = dayjs(route.query.start).format('YYYY-MM-DD[T]HH:mm:ssZ');
      }
      if (route.query.end) {
        form.value.end_date = dayjs(route.query.end).format('YYYY-MM-DD[T]HH:mm:ssZ');
      } else {
        setDuration({ hour: 4, day: 1, week: 1, month: 12, year: 1 }[form.value.rate_period] || 1);
      }
    }
    recalc();
  } catch (e) {
    message.error(t('Rental_Err_Could_Not_Load_The_Contract_Form', 'Could not load the contract form'));
  } finally {
    loading.value = false;
  }
}

async function submit(activate) {
  try {
    await formRef.value.validate();
  } catch (e) {
    message.error(t('Rental_Check_Form', 'Please check the highlighted fields'));
    return;
  }
  const lines = payloadLines();
  if (!lines.length) {
    message.error(t('Rental_Add_One_Item', 'Add at least one item'));
    return;
  }

  const body = {
    ...form.value,
    end_date: openEnded.value && !isReservation.value ? null : form.value.end_date,
    warehouse_id: form.value.warehouse_id || null,
    items: lines,
    activate: activate ? 1 : 0,
  };
  if (!isReservation.value) delete body.hold_until;

  saving.value = activate ? 'active' : 'draft';
  try {
    let id = route.params.id;
    if (isEdit.value) {
      await http.put(`${base.value}/${id}`, body);
      if (!isReservation.value && activate && status.value === 'draft') {
        try {
          await http.post(`rental/contracts/${id}/activate`);
        } catch (e) {
          // The edit is saved; only the activation failed — say so and show the contract.
          message.warning(`${t('Rental_Saved_Not_Activated', 'Saved, but not activated:')} ${e?.data?.message || ''}`, 8);
          router.push(`/${base.value}/${id}`);
          return;
        }
      }
    } else {
      const res = await http.post(base.value, body);
      id = res?.id;
    }
    message.success(isEdit.value
      ? t('Updated_in_successfully', 'Updated successfully')
      : t('Created_in_successfully', 'Created successfully'));
    router.push(id ? `/${base.value}/${id}` : `/${base.value}`);
  } catch (e) {
    const errors = e?.data?.errors;
    if (e?.data?.conflicts) conflicts.value = e.data.conflicts;
    message.error((errors && Object.values(errors)[0]?.[0]) || e?.data?.message || t('Rental_Err_Could_Not_Save_The_Contract', 'Could not save the contract'));
  } finally {
    saving.value = '';
  }
}

function goBack() {
  router.push(isEdit.value ? `/${base.value}/${route.params.id}` : `/${base.value}`);
}

onMounted(load);
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
.no-mb {
  margin-bottom: 0;
}
.wrap {
  display: flex;
  flex-wrap: wrap;
}
.card-title {
  display: inline-flex;
  align-items: center;
  gap: 8px;
}
.card-title :deep(.anticon) {
  color: var(--app-primary, #6d28d9);
}

/* options */
.opt {
  display: flex;
  flex-wrap: wrap;
  align-items: baseline;
  gap: 2px 8px;
}
.opt-main {
  font-weight: 500;
}
.opt-sub {
  font-size: 12px;
  opacity: 0.6;
}
.opt-tag {
  margin-inline-start: auto;
}
.picked {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-top: 12px;
  padding: 10px 12px;
  border-radius: 12px;
  background: rgba(var(--app-primary-rgb, 109, 40, 217), 0.06);
}
.picked-text {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
}
.picked-text span {
  font-size: 12.5px;
  opacity: 0.65;
}

/* quick durations */
.quick {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 6px;
}
.quick-label {
  font-size: 12.5px;
  opacity: 0.6;
  margin-inline-end: 4px;
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
.quick-chip:hover:not(:disabled) {
  border-color: var(--app-primary, #6d28d9);
  color: var(--app-primary, #6d28d9);
}
.quick-chip:disabled {
  opacity: 0.4;
  cursor: not-allowed;
}
.quick-open {
  margin-inline-start: auto;
}

/* lines */
.lines {
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.line {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 90px 140px 120px 36px;
  align-items: center;
  gap: 8px;
}
.line--head {
  font-size: 11.5px;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  opacity: 0.55;
}
.line--bad :deep(.ant-select-selector) {
  border-color: #dc2626 !important;
}
.line-error,
.line-warn {
  grid-column: 1 / -1;
  display: flex;
  align-items: center;
  gap: 6px;
  margin-top: -2px;
  font-size: 12.5px;
}
.line-error {
  color: #dc2626;
}
.line-warn {
  color: #b45309;
}
.right {
  text-align: end;
}
.line-total {
  font-weight: 600;
}
.add-line {
  margin-top: 10px;
}
.hint-link {
  display: inline-block;
  margin-top: 4px;
  font-size: 12.5px;
}
.late :deep(.ant-collapse-header) {
  padding-inline: 0 !important;
  font-weight: 500;
}
.late :deep(.ant-collapse-content-box) {
  padding-inline: 0 !important;
}

/* summary */
.sticky {
  position: sticky;
  top: 80px;
}
.sum-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.sum-number {
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
  font-weight: 600;
  opacity: 0.7;
}
.sum-big {
  display: flex;
  flex-direction: column;
  margin: 14px 0;
  padding: 16px;
  border-radius: 14px;
  background: rgba(var(--app-primary-rgb, 109, 40, 217), 0.08);
}
.sum-label {
  font-size: 12px;
  opacity: 0.7;
}
.sum-amount {
  font-size: 28px;
  font-weight: 700;
  line-height: 1.2;
  color: var(--app-primary, #6d28d9);
}
.sum-per {
  font-size: 13px;
  opacity: 0.6;
}
.sum-rows {
  margin: 0;
}
.sum-rows > div {
  display: flex;
  justify-content: space-between;
  gap: 12px;
  padding: 7px 0;
  border-bottom: 1px dashed rgba(128, 128, 128, 0.22);
  font-size: 13px;
}
.sum-rows dt {
  opacity: 0.7;
}
.sum-rows dd {
  margin: 0;
  font-weight: 500;
}
.sum-strong dd {
  font-weight: 700;
}
.sum-alert {
  margin-top: 12px;
}
.sum-hold {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-top: 12px;
  padding: 8px 12px;
  border-radius: 10px;
  font-size: 13px;
  color: #0369a1;
  background: rgba(2, 132, 199, 0.1);
}
.hold-row {
  margin-top: 14px;
}

@media (max-width: 767px) {
  .line {
    grid-template-columns: 1fr 1fr;
  }
  .line > :first-child {
    grid-column: 1 / -1;
  }
  .line--head {
    display: none;
  }
  .sticky {
    position: static;
  }
}
</style>
