<template>
  <div class="page">
    <PageHeader
      :title="$t('Bkm_Settings')"
      :subtitle="$t('Bkm_Settings_Subtitle')"
      :breadcrumb="[$t('Booking Management'), $t('Bkm_Settings')]"
    >
      <template #actions>
        <a-button type="primary" :loading="saving" :disabled="loading || !ready" @click="save">
          <template #icon><SaveOutlined /></template>
          {{ $t('Save') }}
        </a-button>
      </template>
    </PageHeader>

    <div v-if="loading" class="loading"><a-spin size="large" /></div>

    <a-alert v-else-if="!ready" type="warning" show-icon :message="$t('Bkm_Not_Ready')" />

    <template v-else>
      <a-row :gutter="16">
        <!-- Business type -->
        <a-col :xs="24" :lg="12">
          <a-card size="small" class="block">
            <template #title><span class="card-title"><AppstoreOutlined /> {{ $t('Bkm_Business_Type') }}</span></template>
            <p class="card-help">{{ $t('Bkm_Business_Type_Help') }}</p>
            <div class="biz-grid">
              <div
                v-for="o in typeOptions" :key="o.value" class="biz-card" :class="{ active: form.business_types.includes(o.value) }"
                role="checkbox" tabindex="0" :aria-checked="form.business_types.includes(o.value)"
                @click="toggleType(o.value)" @keydown.space.prevent="toggleType(o.value)" @keydown.enter.prevent="toggleType(o.value)"
              >
                <a-checkbox :checked="form.business_types.includes(o.value)" class="biz-check" tabindex="-1" />
                <span class="biz-ic"><component :is="o.icon" :size="20" :stroke-width="1.7" /></span>
                <span class="biz-text">
                  <span class="biz-title">{{ o.label }}</span>
                  <span class="biz-hint">{{ o.hint }}</span>
                </span>
              </div>
            </div>
            <a-alert v-if="!form.business_types.length" type="info" show-icon style="margin-top: 12px" :message="$t('Bkm_Business_Type_All')" />
          </a-card>
        </a-col>

        <!-- How customers book -->
        <a-col :xs="24" :lg="12">
          <a-card size="small" class="block">
            <template #title><span class="card-title"><CalendarOutlined /> {{ $t('Bkm_How_Customers_Book') }}</span></template>
            <div class="mode-list">
              <label class="mode-row">
                <a-checkbox v-model:checked="form.slot_booking" />
                <span>
                  <span class="mode-title">{{ $t('Bkm_Mode_Slot') }}</span>
                  <span class="mode-hint">{{ $t('Bkm_Mode_Slot_Help') }}</span>
                </span>
              </label>
              <label class="mode-row">
                <a-checkbox v-model:checked="form.request_booking" />
                <span>
                  <span class="mode-title">{{ $t('Bkm_Mode_Request') }}</span>
                  <span class="mode-hint">{{ $t('Bkm_Mode_Request_Help') }}</span>
                </span>
              </label>
            </div>
            <a-alert v-if="noMode" type="error" show-icon style="margin: 12px 0 0" :message="$t('Bkm_At_Least_One_Mode')" />

            <a-row :gutter="12" style="margin-top: 16px">
              <a-col :xs="24" :sm="12">
                <a-form-item :label="$t('Bkm_Slot_Step')" :extra="$t('Bkm_Slot_Step_Help')">
                  <a-select v-model:value="form.slot_step_minutes" :options="stepOptions" :disabled="!form.slot_booking" />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :sm="12">
                <a-form-item :label="$t('Bkm_Min_Notice')" :extra="$t('Bkm_Min_Notice_Help')">
                  <a-select v-model:value="form.min_notice_minutes" :options="noticeOptions" />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :sm="12">
                <a-form-item :label="$t('Bkm_Days_Ahead')">
                  <a-input-number v-model:value="form.max_days_ahead" :min="1" :max="730" :precision="0" style="width: 100%" :addon-after="$t('Bkm_Days')" />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :sm="12">
                <a-form-item :label="$t('Bkm_Hold')" :extra="$t('Bkm_Hold_Help')">
                  <a-select v-model:value="form.hold_minutes" :options="holdOptions" />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :sm="12">
                <a-form-item :label="$t('Bkm_Max_Open')" :extra="$t('Bkm_Max_Open_Help')">
                  <a-input-number v-model:value="form.max_open_per_customer" :min="1" :max="20" :precision="0" style="width: 100%" />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :sm="12">
                <a-form-item :label="$t('Bkm_Customer_Cancel')" :extra="form.customer_cancel ? $t('Bkm_Customer_Cancel_Help') : ''">
                  <div class="inline">
                    <a-switch v-model:checked="form.customer_cancel" />
                    <a-input-number
                      v-if="form.customer_cancel" v-model:value="form.cancel_notice_hours" :min="0" :max="720" :precision="0"
                      style="width: 160px" :addon-after="$t('Bkm_Hours_Before')"
                    />
                  </div>
                </a-form-item>
              </a-col>
            </a-row>

            <label class="auto-row">
              <span>
                <span class="mode-title">{{ $t('Bkm_Auto_Confirm') }}</span>
                <span class="mode-hint">{{ form.auto_confirm ? $t('Bkm_Auto_Confirm_On_Help') : $t('Bkm_Auto_Confirm_Off_Help') }}</span>
              </span>
              <a-switch v-model:checked="form.auto_confirm" :disabled="!form.slot_booking" />
            </label>
          </a-card>
        </a-col>

        <!-- Booking form fields -->
        <a-col :span="24">
          <a-card size="small" class="block">
            <template #title><span class="card-title"><FormOutlined /> {{ $t('Bkm_Form_Fields') }}</span></template>
            <p class="card-help">{{ $t('Bkm_Form_Fields_Help') }}</p>
            <a-row :gutter="[16, 12]">
              <a-col v-for="grp in fieldGroups" :key="grp.key" :xs="24" :md="12" :xl="8">
                <div class="fields-group">
                  <div class="fields-head"><component :is="grp.icon" :size="16" :stroke-width="1.8" /> {{ grp.label }}</div>
                  <div v-for="f in grp.fields" :key="grp.key + f.id" class="field-row">
                    <span class="field-name">
                      {{ fieldLabel(f) }}
                      <a-tooltip v-if="f.input === 'area'" :title="$t('Bkm_Field_Area_Hint')"><InfoCircleOutlined class="muted" /></a-tooltip>
                      <a-tooltip v-if="shared(f.id)" :title="$t('Bkm_Field_Shared_Hint')"><LinkOutlined class="muted" /></a-tooltip>
                    </span>
                    <a-segmented v-model:value="form.form_fields[f.id]" :options="modeOptions" size="small" />
                  </div>
                </div>
              </a-col>
            </a-row>
            <a-form-item v-if="showsType('home_services')" :label="$t('Bkm_Service_Areas')" :extra="$t('Bkm_Service_Areas_Help')" style="margin-top: 16px">
              <a-select v-model:value="form.service_areas" mode="tags" :token-separators="[';']" :open="false" :placeholder="$t('Bkm_Service_Areas_Placeholder')" />
            </a-form-item>
            <a-form-item :label="$t('Bkm_Terms')" :extra="$t('Bkm_Terms_Help')" class="no-mb" style="margin-top: 16px">
              <a-textarea v-model:value="form.terms" :rows="3" :maxlength="5000" />
            </a-form-item>
          </a-card>
        </a-col>

        <!-- Request time windows -->
        <a-col :xs="24" :lg="12">
          <a-card size="small" class="block">
            <template #title><span class="card-title"><ClockCircleOutlined /> {{ $t('Bkm_Time_Windows') }}</span></template>
            <p class="card-help">{{ $t('Bkm_Time_Windows_Help') }}</p>
            <div :class="{ dim: !form.request_booking }">
              <div v-for="w in windowKeys" :key="w.key" class="window-row">
                <label class="window-name"><a-switch v-model:checked="form.time_windows[w.key].enabled" size="small" /> {{ w.label }}</label>
                <a-time-picker
                  v-model:value="form.time_windows[w.key].from" value-format="HH:mm" format="HH:mm" :minute-step="15"
                  :allow-clear="false" :disabled="!form.time_windows[w.key].enabled" :aria-label="$t('Bkm_From')" style="width: 110px"
                />
                <span class="muted">–</span>
                <a-time-picker
                  v-model:value="form.time_windows[w.key].to" value-format="HH:mm" format="HH:mm" :minute-step="15"
                  :allow-clear="false" :disabled="!form.time_windows[w.key].enabled" :aria-label="$t('Bkm_To')" style="width: 110px"
                />
              </div>
            </div>
            <a-alert v-if="windowError" type="error" show-icon style="margin-top: 12px" :message="windowError" />
          </a-card>

          <!-- Website -->
          <a-card size="small" class="block">
            <template #title><span class="card-title"><GlobalOutlined /> {{ $t('Bkm_Website') }}</span></template>
            <a-alert v-if="storeTheme !== 'booking'" type="info" show-icon style="margin-bottom: 12px" :message="$t('Bkm_Website_Theme_Note')">
              <template #description><router-link to="/store/settings">{{ $t('Bkm_Open_Store_Settings') }}</router-link></template>
            </a-alert>
            <div class="switches">
              <label class="switch-row"><a-switch v-model:checked="form.hero_form" /> {{ $t('Bkm_Hero_Form') }}</label>
              <label class="switch-row"><a-switch v-model:checked="form.show_team" /> {{ $t('Bkm_Show_Team') }}</label>
            </div>
            <a-form-item :label="$t('Bkm_Default_Branch')" :extra="$t('Bkm_Default_Branch_Help')" class="no-mb" style="margin-top: 16px">
              <a-select
                v-model:value="form.default_warehouse_id" allow-clear :placeholder="$t('Bkm_No_Branch')"
                :options="warehouses.map((w) => ({ value: w.id, label: w.name }))"
              />
            </a-form-item>
          </a-card>
        </a-col>

        <!-- Notifications -->
        <a-col :xs="24" :lg="12">
          <a-card size="small" class="block">
            <template #title><span class="card-title"><BellOutlined /> {{ $t('Bkm_Notifications') }}</span></template>
            <div class="switches">
              <label class="switch-row"><a-switch v-model:checked="form.notify_owner" /> {{ $t('Bkm_Notify_Owner') }}</label>
              <a-form-item v-if="form.notify_owner" :label="$t('Bkm_Notify_Owner_Email')" :extra="$t('Bkm_Notify_Owner_Email_Help')" class="no-mb">
                <a-input v-model:value="form.notify_owner_email" type="email" :maxlength="191" :placeholder="$t('Bkm_Company_Email')" />
              </a-form-item>
              <label class="switch-row"><a-switch v-model:checked="form.notify_customer" /> {{ $t('Bkm_Notify_Customer') }}</label>
              <a-form-item :label="$t('Bkm_Reminder')" :extra="$t('Bkm_Reminder_Help')" class="no-mb">
                <a-select v-model:value="form.reminder_hours" :options="reminderOptions" style="max-width: 260px" />
              </a-form-item>
              <label class="switch-row"><a-switch v-model:checked="form.whatsapp_notify" /> {{ $t('Bkm_Whatsapp_Notify') }}</label>
              <div class="muted small">{{ $t('Bkm_Whatsapp_Notify_Help') }}</div>
            </div>
          </a-card>

          <!-- CRM -->
          <a-card v-if="crm.ready" size="small" class="block">
            <template #title><span class="card-title"><TeamOutlined /> {{ $t('Bkm_Crm') }}</span></template>
            <a-alert v-if="!crm.available" type="info" show-icon style="margin-bottom: 12px" :message="$t('Bkm_Crm_Unavailable')" />
            <div class="switches" :class="{ dim: !crm.available }">
              <label class="switch-row"><a-switch v-model:checked="form.crm_capture" :disabled="!crm.available" /> {{ $t('Bkm_Crm_Capture') }}</label>
              <div class="muted small">{{ $t('Bkm_Crm_Capture_Help') }}</div>
              <label class="switch-row"><a-switch v-model:checked="form.crm_request_deals" :disabled="!crm.available || !form.crm_capture || !form.request_booking" /> {{ $t('Bkm_Crm_Request_Deals') }}</label>
              <div class="muted small">{{ $t('Bkm_Crm_Request_Deals_Help') }}</div>
            </div>
          </a-card>
        </a-col>

        <!-- Deposit -->
        <a-col v-if="deposits.ready" :span="24">
          <a-card size="small" class="block">
            <template #title><span class="card-title"><CreditCardOutlined /> {{ $t('Bkm_Deposit') }}</span></template>
            <p class="card-help">{{ $t('Bkm_Deposit_Help') }}</p>
            <a-row :gutter="16">
              <a-col :xs="24" :lg="12">
                <a-form-item :label="$t('Bkm_Deposit_Ask')">
                  <a-radio-group v-model:value="form.deposit_type" class="dep-types">
                    <a-radio v-for="o in depositTypeOptions" :key="o.value" :value="o.value">{{ o.label }}</a-radio>
                  </a-radio-group>
                </a-form-item>
                <a-form-item v-if="form.deposit_type === 'fixed' || form.deposit_type === 'percent'" :label="form.deposit_type === 'percent' ? $t('Bkm_Deposit_Percent') : $t('Bkm_Deposit_Amount')">
                  <a-input-number
                    v-model:value="form.deposit_value" :min="0" :max="form.deposit_type === 'percent' ? 100 : 99999999" :precision="2"
                    style="width: 220px" :addon-after="form.deposit_type === 'percent' ? '%' : deposits.currency"
                  />
                </a-form-item>
                <template v-if="form.deposit_type !== 'none'">
                  <a-form-item :label="$t('Bkm_Deposit_Minutes')" :extra="$t('Bkm_Deposit_Minutes_Help')">
                    <a-select v-model:value="form.deposit_minutes" :options="depositMinuteOptions" style="max-width: 260px" />
                  </a-form-item>
                  <div class="switches">
                    <label class="switch-row"><a-switch v-model:checked="form.deposit_auto_cancel" /> {{ $t('Bkm_Deposit_Auto_Cancel') }}</label>
                    <label class="switch-row"><a-switch v-model:checked="form.deposit_on_requests" :disabled="!form.request_booking" /> {{ $t('Bkm_Deposit_On_Requests') }}</label>
                    <div class="muted small">{{ $t('Bkm_Deposit_Services_Note') }}</div>
                  </div>
                </template>
              </a-col>
              <a-col v-if="form.deposit_type !== 'none'" :xs="24" :lg="12">
                <a-form-item :label="$t('Bkm_Deposit_Gateways')" :extra="$t('Bkm_Deposit_Gateways_Help')">
                  <div class="gw-list">
                    <label v-for="g in deposits.gateways" :key="g.key" class="gw-row" :class="{ dim: !g.configured || !g.enabled }">
                      <a-checkbox :checked="gatewayPicked(g.key)" :disabled="!g.configured || !g.enabled" @change="toggleGateway(g.key)" />
                      <span class="gw-name">{{ g.label }}</span>
                      <a-tag v-if="!g.configured">{{ $t('Bkm_Gateway_Not_Set_Up') }}</a-tag>
                      <a-tag v-else-if="!g.enabled">{{ $t('Bkm_Gateway_Off') }}</a-tag>
                    </label>
                  </div>
                </a-form-item>
                <a-alert v-if="!usableGateways.length" type="warning" show-icon style="margin-bottom: 12px" :message="$t('Bkm_Deposit_No_Gateway')">
                  <template #description><router-link to="/store/payment-gateway">{{ $t('Bkm_Open_Payment_Gateways') }}</router-link></template>
                </a-alert>
                <a-form-item :label="$t('Bkm_Deposit_Note')" :extra="$t('Bkm_Deposit_Note_Help')" class="no-mb">
                  <a-textarea v-model:value="form.deposit_note" :rows="2" :maxlength="500" :placeholder="$t('Bkm_Deposit_Note_Placeholder')" />
                </a-form-item>
              </a-col>
            </a-row>
          </a-card>
        </a-col>
      </a-row>
    </template>
  </div>
</template>

<script setup>
/**
 * Booking Management › Settings — GET bookings/settings → {ready, settings,
 * registry, warehouses, store_theme}; PUT bookings/settings (permission
 * bookings_settings). The registry (App\Support\BookingTypes) is shared
 * with the other booking pages through bookingTypes.js.
 */
import { ref, computed, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import {
  SaveOutlined, AppstoreOutlined, CalendarOutlined, FormOutlined, ClockCircleOutlined, GlobalOutlined,
  BellOutlined, InfoCircleOutlined, LinkOutlined, CreditCardOutlined, TeamOutlined,
} from '@ant-design/icons-vue';
import { ListChecks } from 'lucide-vue-next';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';
import { t, i18n } from '../../i18n';
import { setBookingTypes, typeLabel, typeHint, typeIcon, fieldLabel } from './bookingTypes';

const loading = ref(true);
const saving = ref(false);
const ready = ref(true);
const registry = ref({ types: {}, common: [] });
const warehouses = ref([]);
const storeTheme = ref(null);
const deposits = ref({ ready: false, gateways: [], currency: null });
const crm = ref({ ready: false, available: false });

const defaultWindows = () => ({
  morning: { enabled: true, from: '08:00', to: '12:00' },
  afternoon: { enabled: true, from: '12:00', to: '16:00' },
  evening: { enabled: true, from: '16:00', to: '20:00' },
});
const form = ref({
  business_types: [], slot_booking: true, request_booking: true, slot_step_minutes: 15, min_notice_minutes: 120,
  max_days_ahead: 60, hold_minutes: 120, auto_confirm: false, max_open_per_customer: 3, customer_cancel: true,
  cancel_notice_hours: 24, form_fields: {}, time_windows: defaultWindows(), service_areas: [], notify_owner: true,
  notify_owner_email: '', notify_customer: true, reminder_hours: 24, whatsapp_notify: false, hero_form: true,
  show_team: true, terms: '', default_warehouse_id: undefined,
});

/** t() with named {params}; the English fallback gets the same substitution. */
function tp(key, fallback, params) {
  const out = i18n.global.t(key, params);
  if (out !== key) return out;
  return Object.entries(params).reduce((s, [k, v]) => s.replace(`{${k}}`, v), fallback);
}
function duration(min) {
  if (min % 1440 === 0) return tp('Bkm_N_Days', '{n} days', { n: min / 1440 });
  if (min % 60 === 0) return tp('Bkm_N_Hours', '{n} h', { n: min / 60 });
  return tp('Bkm_N_Minutes', '{n} min', { n: min });
}

// ----- business type -----
const typeOptions = computed(() => Object.keys(registry.value.types || {}).map((value) => ({
  value, label: typeLabel(value), hint: typeHint(value), icon: typeIcon(value),
})));
function toggleType(type) {
  const list = form.value.business_types;
  form.value.business_types = list.includes(type) ? list.filter((x) => x !== type) : [...list, type];
}
/** Nothing ticked = every type is on. */
function showsType(type) {
  return !form.value.business_types.length || form.value.business_types.includes(type);
}

// ----- how customers book -----
const noMode = computed(() => !form.value.slot_booking && !form.value.request_booking);
const stepOptions = computed(() => [10, 15, 20, 30, 60].map((v) => ({ value: v, label: duration(v) }))
  .concat([{ value: 0, label: t('Bkm_Step_Service_Length', 'The service length') }]));
const noticeOptions = computed(() => [0, 30, 60, 120, 240, 720, 1440, 2880].map((v) => ({
  value: v, label: v === 0 ? t('Bkm_No_Notice', 'Any time') : tp('Bkm_From_Now', '{d} from now', { d: duration(v) }),
})));
const holdOptions = computed(() => [30, 60, 120, 360, 720, 1440, 2880].map((v) => ({ value: v, label: duration(v) }))
  .concat([{ value: 0, label: t('Bkm_Until_Answered', 'Until answered') }]));
const reminderOptions = computed(() => [24, 48, 12, 6, 3, 2, 1].map((v) => ({ value: v, label: tp('Bkm_Hours_Before_N', '{n} h before', { n: v }) }))
  .concat([{ value: 0, label: t('Bkm_No_Reminder', 'No reminder') }]));

// ----- deposit -----
const depositTypeOptions = computed(() => [
  { value: 'none', label: t('Bkm_Deposit_None', 'No deposit') },
  { value: 'fixed', label: t('Bkm_Deposit_Fixed', 'A fixed amount') },
  { value: 'percent', label: t('Bkm_Deposit_Percent_Of', 'A percentage of the price') },
  { value: 'full', label: t('Bkm_Deposit_Full', 'The full price') },
]);
const depositMinuteOptions = computed(() => [15, 30, 60, 120, 360, 1440].map((v) => ({ value: v, label: duration(v) })));
/** None ticked = every gateway that is set up. */
function gatewayPicked(key) {
  const list = form.value.deposit_gateways || [];
  return !list.length || list.includes(key);
}
function toggleGateway(key) {
  const usable = deposits.value.gateways.filter((g) => g.configured && g.enabled).map((g) => g.key);
  let list = (form.value.deposit_gateways || []).length ? [...form.value.deposit_gateways] : [...usable];
  list = list.includes(key) ? list.filter((k) => k !== key) : [...list, key];
  // Nothing ticked would mean "all" again, so the last one stays ticked.
  if (!list.length) return;
  form.value.deposit_gateways = usable.every((k) => list.includes(k)) ? [] : list;
}
const usableGateways = computed(() => deposits.value.gateways.filter((g) => g.configured && g.enabled && gatewayPicked(g.key)));

// ----- form fields -----
const modeOptions = computed(() => [
  { value: 'required', label: t('Bkm_Mode_Required', 'Required') },
  { value: 'optional', label: t('Bkm_Mode_Optional', 'Optional') },
  { value: 'off', label: t('Bkm_Mode_Off', 'Hidden') },
]);
const fieldGroups = computed(() => [
  { key: 'common', label: t('Bkm_All_Types', 'Every business type'), icon: ListChecks, fields: registry.value.common || [] },
  ...typeOptions.value
    .filter((o) => showsType(o.value))
    .map((o) => ({ key: o.value, label: o.label, icon: o.icon, fields: registry.value.types[o.value]?.fields || [] })),
].filter((g) => g.fields.length));
/** A field id used by more than one type shares one mode. */
function shared(id) {
  return Object.values(registry.value.types || {}).filter((d) => (d.fields || []).some((f) => f.id === id)).length > 1;
}

// ----- time windows -----
const windowKeys = computed(() => [
  { key: 'morning', label: t('Bkm_Morning', 'Morning') },
  { key: 'afternoon', label: t('Bkm_Afternoon', 'Afternoon') },
  { key: 'evening', label: t('Bkm_Evening', 'Evening') },
]);
const windowError = computed(() => {
  const w = form.value.time_windows;
  if (Object.values(w).some((x) => x.to <= x.from)) return t('Bkm_Window_Order');
  if (form.value.request_booking && !Object.values(w).some((x) => x.enabled)) return t('Bkm_Window_Needed');
  return '';
});

function apply(settings) {
  const windows = defaultWindows();
  Object.keys(windows).forEach((k) => { windows[k] = { ...windows[k], ...(settings.time_windows?.[k] || {}) }; });
  form.value = {
    ...form.value,
    ...settings,
    business_types: Array.isArray(settings.business_types) ? settings.business_types : [],
    form_fields: { ...(settings.form_fields || {}) },
    time_windows: windows,
    service_areas: Array.isArray(settings.service_areas) ? settings.service_areas : [],
    notify_owner_email: settings.notify_owner_email || '',
    terms: settings.terms || '',
    default_warehouse_id: settings.default_warehouse_id || undefined,
  };
  if ('deposit_type' in settings) {
    form.value.deposit_note = settings.deposit_note || '';
    form.value.deposit_gateways = Array.isArray(settings.deposit_gateways) ? settings.deposit_gateways : [];
  }
}

function useRegistry(reg) {
  if (reg && reg.types) {
    registry.value = reg;
    setBookingTypes(reg);
  }
}

async function load() {
  loading.value = true;
  try {
    const data = await http.get('bookings/settings');
    ready.value = data?.ready !== false;
    warehouses.value = data?.warehouses || [];
    storeTheme.value = data?.store_theme || null;
    deposits.value = data?.deposits || { ready: false, gateways: [], currency: null };
    crm.value = data?.crm || { ready: false, available: false };
    useRegistry(data?.registry);
    if (data?.settings) apply(data.settings);
  } catch (e) {
    message.error(t('Bkm_Could_Not_Load_Settings'));
  } finally {
    loading.value = false;
  }
}

async function save() {
  if (noMode.value) {
    message.error(t('Bkm_At_Least_One_Mode'));
    return;
  }
  if (windowError.value) {
    message.error(windowError.value);
    return;
  }
  saving.value = true;
  try {
    const data = await http.put('bookings/settings', {
      ...form.value,
      default_warehouse_id: form.value.default_warehouse_id || null,
      notify_owner_email: (form.value.notify_owner_email || '').trim() || null,
    });
    if (data?.settings) apply(data.settings);
    useRegistry(data?.registry);
    message.success(t('Updated_in_successfully'));
  } catch (e) {
    const errors = e?.data?.errors;
    const first = errors ? Object.values(errors)[0]?.[0] : null;
    message.error(first || e?.data?.message || t('Bkm_Could_Not_Save_Settings'));
  } finally {
    saving.value = false;
  }
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
.card-title {
  display: inline-flex;
  align-items: center;
  gap: 8px;
}
.card-help {
  margin: 0 0 12px;
  color: rgba(0, 0, 0, 0.55);
  font-size: 13px;
}
.muted { color: rgba(0, 0, 0, 0.55); }
.small { font-size: 12px; }
.dim { opacity: 0.55; }
.no-mb { margin-bottom: 0; }
.inline {
  display: flex;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
}
.dep-types {
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.gw-list {
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.gw-row {
  display: flex;
  align-items: center;
  gap: 10px;
}
.gw-name { min-width: 120px; }
.switches {
  display: flex;
  flex-direction: column;
  gap: 12px;
}
.switch-row {
  display: flex;
  align-items: center;
  gap: 10px;
  cursor: pointer;
}

/* business type */
.biz-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 10px;
}
@media (max-width: 575px) {
  .biz-grid { grid-template-columns: minmax(0, 1fr); }
}
.biz-card {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 12px;
  border: 1.5px solid rgba(128, 128, 128, 0.22);
  border-radius: 12px;
  cursor: pointer;
  transition: border-color 0.15s ease, background 0.15s ease;
}
.biz-card:hover {
  border-color: rgba(var(--app-primary-rgb, 109, 40, 217), 0.5);
}
.biz-card.active {
  border-color: var(--app-primary, #6d28d9);
  background: rgba(var(--app-primary-rgb, 109, 40, 217), 0.07);
}
.biz-card:focus-visible {
  outline: 2px solid var(--app-primary, #6d28d9);
  outline-offset: 2px;
}
/* The card is the control; the checkbox only shows its state. */
.biz-check {
  pointer-events: none;
}
.biz-ic {
  width: 36px;
  height: 36px;
  flex: none;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 10px;
  background: rgba(128, 128, 128, 0.12);
}
.biz-card.active .biz-ic {
  color: #fff;
  background: var(--app-primary, #6d28d9);
}
.biz-text {
  min-width: 0;
}
.biz-title {
  display: block;
  font-weight: 600;
}
.biz-hint {
  display: block;
  font-size: 12px;
  opacity: 0.6;
  line-height: 1.35;
}

/* booking modes */
.mode-list {
  display: flex;
  flex-direction: column;
  gap: 12px;
}
.mode-row {
  display: flex;
  align-items: flex-start;
  gap: 10px;
  cursor: pointer;
}
.mode-title {
  display: block;
  font-weight: 600;
}
.mode-hint {
  display: block;
  font-size: 12px;
  opacity: 0.65;
  line-height: 1.4;
}
.auto-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 12px;
  border: 1px solid rgba(128, 128, 128, 0.2);
  border-radius: 10px;
  cursor: pointer;
}

/* form fields */
.fields-group {
  border: 1px solid rgba(128, 128, 128, 0.2);
  border-radius: 12px;
  padding: 10px 12px;
  height: 100%;
}
.fields-head {
  display: flex;
  align-items: center;
  gap: 6px;
  font-weight: 600;
  margin-bottom: 6px;
}
.field-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  padding: 6px 0;
  border-top: 1px solid rgba(128, 128, 128, 0.12);
}
.field-name {
  min-width: 0;
  display: inline-flex;
  align-items: center;
  gap: 6px;
  flex-wrap: wrap;
}

/* time windows */
.window-row {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
  padding: 6px 0;
}
.window-name {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  min-width: 130px;
  cursor: pointer;
}
</style>
