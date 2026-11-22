<template>
  <div class="page">
    <PageHeader
      :title="$t('Rental_Settings')"
      :subtitle="$t('Rental_Settings_Subtitle')"
      :breadcrumb="[$t('Rental_Management'), $t('Rental_Settings')]"
    >
      <template #actions>
        <a-button type="primary" :loading="saving" :disabled="loading || !loaded" @click="save">
          <template #icon><SaveOutlined /></template>
          {{ $t('Save') }}
        </a-button>
      </template>
    </PageHeader>

    <div v-if="loading" class="loading"><a-spin size="large" /></div>

    <a-form v-else ref="formRef" :model="form" :rules="rules" layout="vertical">
      <a-alert v-if="!form.website_ready" type="warning" show-icon class="block" :message="$t('Rental_Website_Not_Ready')" />
      <a-row v-else :gutter="16">
        <!-- Business type -->
        <a-col :xs="24" :lg="12">
          <a-card size="small" class="block">
            <template #title>
              <span class="card-title"><AppstoreOutlined /> {{ $t('Rental_Business_Type') }}</span>
            </template>
            <p class="card-help">{{ $t('Rental_Business_Type_Help') }}</p>
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
                  <span class="biz-hint">{{ t(TYPE_HINTS[o.value].key, TYPE_HINTS[o.value].text) }}</span>
                </span>
              </div>
            </div>
            <a-alert v-if="!form.business_types.length" type="info" show-icon style="margin-top: 12px" :message="$t('Rental_Business_Type_All')" />
          </a-card>
        </a-col>

        <!-- Website booking (Rental store theme) -->
        <a-col :xs="24" :lg="12">
          <a-card size="small" class="block">
            <template #title>
              <span class="card-title"><GlobalOutlined /> {{ $t('Rental_Website_Booking') }}</span>
            </template>
            <template #extra><a-switch v-model:checked="form.website_booking" /></template>
            <a-alert
              v-if="form.store_theme !== 'rental'" type="info" show-icon style="margin-bottom: 12px"
              :message="$t('Rental_Website_Theme_Off')"
            >
              <template #description>
                <router-link to="/store/settings">{{ $t('Rental_Open_Store_Settings') }}</router-link>
              </template>
            </a-alert>
            <p class="card-help">{{ $t('Rental_Website_Booking_Help') }}</p>
            <div :class="{ dim: !form.website_booking }">
              <a-row :gutter="16">
                <a-col :xs="24" :sm="12">
                  <a-form-item :label="$t('Rental_Website_Hold')" :extra="$t('Rental_Website_Hold_Help')">
                    <a-input-number v-model:value="form.website_hold_hours" :min="1" :max="720" :precision="0" style="width: 100%" :addon-after="$t('Rental_Unit_Hours')" />
                  </a-form-item>
                </a-col>
                <a-col :xs="24" :sm="12">
                  <a-form-item :label="$t('Rental_Website_Notice')" :extra="$t('Rental_Website_Notice_Help')">
                    <a-input-number v-model:value="form.website_min_notice_hours" :min="0" :max="720" :precision="0" style="width: 100%" :addon-after="$t('Rental_Unit_Hours')" />
                  </a-form-item>
                </a-col>
                <a-col :xs="24" :sm="12">
                  <a-form-item :label="$t('Rental_Website_Ahead')" :extra="$t('Rental_Website_Ahead_Help')">
                    <a-input-number v-model:value="form.website_max_days_ahead" :min="1" :max="730" :precision="0" style="width: 100%" :addon-after="$t('Rental_Unit_Days')" />
                  </a-form-item>
                </a-col>
                <a-col :xs="24" :sm="12">
                  <a-form-item :label="$t('Rental_Website_Login')" :extra="$t('Rental_Website_Login_Help')">
                    <a-switch v-model:checked="form.website_require_login" />
                  </a-form-item>
                </a-col>
              </a-row>
              <a-form-item v-if="showsType('vehicle')" :label="$t('Rental_Pickup_Locations')" :extra="$t('Rental_Pickup_Locations_Help')">
                <a-select v-model:value="form.pickup_locations" mode="tags" :token-separators="[';']" :open="false" :placeholder="$t('Rental_Pickup_Locations_Placeholder')" />
              </a-form-item>
            </div>
          </a-card>
        </a-col>

        <!-- Booking form fields per business type -->
        <a-col :span="24">
          <a-card size="small" class="block">
            <template #title>
              <span class="card-title"><FormOutlined /> {{ $t('Rental_Booking_Form_Fields') }}</span>
            </template>
            <p class="card-help">{{ $t('Rental_Booking_Form_Fields_Help') }}</p>
            <a-row :gutter="[16, 12]">
              <a-col v-for="grp in fieldGroups" :key="grp.type" :xs="24" :md="12" :xl="8">
                <div class="fields-group">
                  <div class="fields-head"><component :is="grp.icon" :size="16" :stroke-width="1.8" /> {{ grp.label }}</div>
                  <div v-for="f in grp.fields" :key="f.id" class="field-row">
                    <span class="field-name">
                      {{ fieldLabel(f) }}
                      <a-tooltip v-if="f.input === 'location'" :title="$t('Rental_Field_Location_Hint')"><InfoCircleOutlined class="muted" /></a-tooltip>
                    </span>
                    <a-segmented v-model:value="form.website_fields[f.id]" :options="modeOptions" size="small" />
                  </div>
                </div>
              </a-col>
            </a-row>
            <a-form-item :label="$t('Rental_Website_Terms')" :extra="$t('Rental_Website_Terms_Help')" class="no-mb" style="margin-top: 16px">
              <a-textarea v-model:value="form.website_terms" :rows="4" />
            </a-form-item>
          </a-card>
        </a-col>
      </a-row>

      <a-row :gutter="16">
        <a-col :xs="24" :lg="12">
          <!-- Numbering -->
          <a-card size="small" class="block">
            <template #title>
              <span class="card-title"><NumberOutlined /> {{ $t('Rental_Numbering') }}</span>
            </template>
            <p class="card-help">{{ $t('Rental_Numbering_Help') }}</p>
            <a-row :gutter="16">
              <a-col v-for="p in prefixes" :key="p.field" :xs="24" :sm="12">
                <a-form-item :label="p.label" :name="p.field">
                  <a-input v-model:value="form[p.field]" :maxlength="16" @blur="upper(p.field)">
                    <template #suffix>
                      <span class="preview">{{ (form[p.field] || '').toUpperCase() }}-0001</span>
                    </template>
                  </a-input>
                </a-form-item>
              </a-col>
            </a-row>
          </a-card>

          <!-- Deposits & scheduling -->
          <a-card size="small" class="block">
            <template #title>
              <span class="card-title"><SafetyCertificateOutlined /> {{ $t('Rental_Deposits_Scheduling') }}</span>
            </template>
            <a-row :gutter="16">
              <a-col :xs="24" :sm="12">
                <a-form-item :label="$t('Rental_Default_Deposit')" name="default_deposit" :extra="$t('Rental_Default_Deposit_Help')">
                  <a-input-number v-model:value="form.default_deposit" :min="0" style="width: 100%" />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :sm="12">
                <a-form-item :label="$t('Rental_Buffer_Time')" name="buffer_hours" :extra="$t('Rental_Buffer_Time_Help')">
                  <a-input-number v-model:value="form.buffer_hours" :min="0" :max="720" :precision="0" style="width: 100%" :addon-after="$t('Rental_Unit_Hours')" />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :sm="12">
                <a-form-item :label="$t('Rental_Default_Hold')" name="default_hold_hours" :extra="$t('Rental_Default_Hold_Help')">
                  <a-input-number v-model:value="form.default_hold_hours" :min="1" :max="2160" :precision="0" style="width: 100%" :addon-after="$t('Rental_Unit_Hours')" />
                </a-form-item>
              </a-col>
            </a-row>
          </a-card>

          <!-- Alerts -->
          <a-card size="small" class="block">
            <template #title>
              <span class="card-title"><BellOutlined /> {{ $t('Rental_Alerts_Reminders') }}</span>
            </template>
            <a-row :gutter="16">
              <a-col :xs="24" :sm="12">
                <a-form-item :label="$t('Rental_Expiring_Soon')" name="expiring_soon_days" :extra="$t('Rental_Expiring_Soon_Help')">
                  <a-input-number v-model:value="form.expiring_soon_days" :min="1" :max="365" :precision="0" style="width: 100%" :addon-after="$t('Rental_Unit_Days')" />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :sm="12">
                <a-form-item :label="$t('Rental_Reminder_Before')" name="reminder_days_before" :extra="$t('Rental_Reminder_Before_Help')">
                  <a-input-number v-model:value="form.reminder_days_before" :min="0" :max="90" :precision="0" style="width: 100%" :addon-after="$t('Rental_Unit_Days')" />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :sm="12">
                <a-form-item :label="$t('Rental_Invoice_Lead')" name="invoice_lead_days" :extra="$t('Rental_Invoice_Lead_Help')">
                  <a-input-number v-model:value="form.invoice_lead_days" :min="0" :max="90" :precision="0" style="width: 100%" :addon-after="$t('Rental_Unit_Days')" />
                </a-form-item>
              </a-col>
            </a-row>
          </a-card>
        </a-col>

        <a-col :xs="24" :lg="12">
          <!-- Late payments -->
          <a-card size="small" class="block">
            <template #title>
              <span class="card-title"><FieldTimeOutlined /> {{ $t('Rental_Late_Payments') }}</span>
            </template>
            <a-form-item :label="$t('Rental_Grace_Period')" name="grace_days" :extra="$t('Rental_Grace_Period_Help')">
              <a-input-number v-model:value="form.grace_days" :min="0" :max="365" :precision="0" style="width: 220px" :addon-after="$t('Rental_Unit_Days')" />
            </a-form-item>
            <a-form-item :label="$t('Rental_Late_Fee')">
              <a-segmented v-model:value="form.late_fee_type" :options="feeTypeOptions" block />
            </a-form-item>
            <a-row v-if="form.late_fee_type !== 'none'" :gutter="16">
              <a-col :xs="24" :sm="12">
                <a-form-item :label="form.late_fee_type === 'percent' ? $t('Rental_Late_Fee_Percent') : $t('Rental_Late_Fee_Amount')" name="late_fee_value">
                  <a-input-number
                    v-model:value="form.late_fee_value" :min="0" :max="form.late_fee_type === 'percent' ? 100 : undefined"
                    style="width: 100%" :addon-after="form.late_fee_type === 'percent' ? '%' : undefined"
                  />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :sm="12">
                <a-form-item :label="$t('Rental_Late_Fee_Charged')">
                  <a-select v-model:value="form.late_fee_frequency" :options="frequencyOptions" />
                </a-form-item>
              </a-col>
            </a-row>
            <a-alert type="info" show-icon :message="lateFeeSummary" class="summary" />
          </a-card>

          <!-- Reminders -->
          <a-card size="small" class="block">
            <template #title>
              <span class="card-title"><NotificationOutlined /> {{ $t('Rental_Reminders') }}</span>
            </template>
            <template #extra><a-switch v-model:checked="form.reminders_enabled" /></template>
            <p class="card-help">{{ $t('Rental_Reminders_Help') }}</p>
            <div :class="{ dim: !form.reminders_enabled }">
              <a-form-item :label="$t('Rental_Channels')">
                <a-checkbox-group v-model:value="form.reminder_channels">
                  <a-checkbox value="email">{{ $t('Email') }}</a-checkbox>
                  <a-checkbox value="sms">{{ $t('SMS') }}</a-checkbox>
                  <a-checkbox value="whatsapp" :disabled="!form.whatsapp_active">WhatsApp<span v-if="!form.whatsapp_active" class="muted"> ({{ $t('Rental_Not_Connected') }})</span></a-checkbox>
                </a-checkbox-group>
              </a-form-item>
              <a-row :gutter="16">
                <a-col :xs="24" :sm="12">
                  <a-form-item :label="$t('Rental_Overdue_Every')">
                    <a-input-number v-model:value="form.overdue_reminder_every_days" :min="1" :max="90" :precision="0" style="width: 100%" :addon-after="$t('Rental_Unit_Days')" />
                  </a-form-item>
                </a-col>
                <a-col :xs="24" :sm="12">
                  <a-form-item :label="$t('Rental_Expiry_Reminder')">
                    <a-input-number v-model:value="form.expiry_reminder_days" :min="1" :max="365" :precision="0" style="width: 100%" :addon-after="$t('Rental_Unit_Days')" />
                  </a-form-item>
                </a-col>
              </a-row>
              <a-tabs v-model:activeKey="tplTab" size="small">
                <a-tab-pane v-for="tt in TEMPLATE_TYPES" :key="tt.value" :tab="tt.label">
                  <a-form-item :label="$t('Rental_Email_Subject')">
                    <a-input v-model:value="form.reminder_templates[tt.value].subject" />
                  </a-form-item>
                  <a-form-item :label="$t('Rental_Message')" :extra="`${$t('Rental_Reminder_Placeholders')} ${PLACEHOLDERS}`" class="no-mb">
                    <a-textarea v-model:value="form.reminder_templates[tt.value].body" :rows="5" />
                  </a-form-item>
                </a-tab-pane>
              </a-tabs>
              <p class="cron-note"><InfoCircleOutlined /> {{ $t('Rental_Reminders_Cron') }} <code>php artisan rental:send-reminders</code></p>
            </div>
          </a-card>

          <!-- Handover checklists -->
          <a-card size="small" class="block">
            <template #title>
              <span class="card-title"><CheckSquareOutlined /> {{ $t('Rental_Handover_Checklists') }}</span>
            </template>
            <p class="card-help">{{ $t('Rental_Handover_Checklists_Help') }}</p>
            <a-form-item :label="$t('Rental_Checkout_Checklist')">
              <a-select v-model:value="form.checkout_checklist" mode="tags" :token-separators="[';']" :open="false" />
            </a-form-item>
            <a-form-item :label="$t('Rental_Checkin_Checklist')" class="no-mb">
              <a-select v-model:value="form.checkin_checklist" mode="tags" :token-separators="[';']" :open="false" />
            </a-form-item>
          </a-card>

          <!-- Agreement terms -->
          <a-card size="small" class="block">
            <template #title>
              <span class="card-title"><FileTextOutlined /> {{ $t('Rental_Agreement_Terms') }}</span>
            </template>
            <p class="card-help">{{ $t('Rental_Agreement_Terms_Help') }}</p>
            <a-textarea v-model:value="form.agreement_terms" :rows="10" :placeholder="$t('Rental_Agreement_Terms_Placeholder')" />
          </a-card>
        </a-col>
      </a-row>
    </a-form>
  </div>
</template>

<script setup>
/**
 * Rental module settings — GET/PUT rental/settings. One row of numbering
 * prefixes, late-fee policy, deposit default, booking buffer and the alert
 * windows the later phases (contracts, payments, reminders) read.
 */
import { ref, computed, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import {
  SaveOutlined, NumberOutlined, SafetyCertificateOutlined, BellOutlined,
  FieldTimeOutlined, FileTextOutlined, CheckSquareOutlined, NotificationOutlined, InfoCircleOutlined,
  AppstoreOutlined, GlobalOutlined, FormOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import { useFormat } from '../../composables/useFormat';
import http from '../../lib/http';
import { t, i18n } from '../../i18n';
import { RENTAL_TYPES, tr } from './rentalOptions';
import { useRentalTypes, reloadRentalTypes, fieldLabel } from './rentalTypes';

const { money } = useFormat();
const rentalTypes = useRentalTypes();

// ----- business type + website booking -----
const TYPE_HINTS = {
  property: { key: 'Rental_Type_Property_Hint', text: 'Apartments, shops, offices, land' },
  equipment: { key: 'Rental_Type_Equipment_Hint', text: 'Tools, machines, cameras, party gear' },
  vehicle: { key: 'Rental_Type_Vehicle_Hint', text: 'Cars, vans, trucks, bikes' },
  other: { key: 'Rental_Type_Other_Hint', text: 'Anything else you rent out' },
};
// Business-first order: the two the theme is built around come first.
const TYPE_ORDER = ['vehicle', 'equipment', 'property', 'other'];
const typeOptions = computed(() => tr(RENTAL_TYPES).sort((a, b) => TYPE_ORDER.indexOf(a.value) - TYPE_ORDER.indexOf(b.value)));
const modeOptions = computed(() => [
  { value: 'required', label: t('Rental_Mode_Required', 'Required') },
  { value: 'optional', label: t('Rental_Mode_Optional', 'Optional') },
  { value: 'off', label: t('Rental_Mode_Off', 'Hidden') },
]);

function toggleType(type) {
  const list = form.value.business_types;
  form.value.business_types = list.includes(type) ? list.filter((x) => x !== type) : [...list, type];
}
/** Nothing ticked = every type is on. */
function showsType(type) {
  return !form.value.business_types.length || form.value.business_types.includes(type);
}
/** Booking-form fields grouped by the types this business runs (from the registry). */
const fieldGroups = computed(() => typeOptions.value
  .filter((o) => showsType(o.value))
  .map((o) => ({ type: o.value, label: o.label, icon: o.icon, fields: rentalTypes.bookingFields(o.value) }))
  .filter((g) => g.fields.length));

/** t() with named {params}; the English fallback gets the same substitution. */
function tp(key, fallback, params) {
  const out = i18n.global.t(key, params);
  if (out !== key) return out;
  return Object.entries(params).reduce((s, [k, v]) => s.replace(`{${k}}`, v), fallback);
}

const formRef = ref();
const tplTab = ref('rent_due');
// Shown literally (never through i18n, which would treat {x} as a parameter).
const PLACEHOLDERS = '{customer_name} {contract_number} {invoice_number} {amount} {due_date} {days_late} {end_date} {items} {company_name} {company_phone}';
const TEMPLATE_TYPES = computed(() => [
  { value: 'rent_due', label: t('Rental_Rem_Rent_Due', 'Rent due') },
  { value: 'rent_overdue', label: t('Rental_Rem_Rent_Overdue', 'Rent overdue') },
  { value: 'contract_expiring', label: t('Rental_Rem_Expiring', 'Contract ending') },
  { value: 'return_due', label: t('Rental_Rem_Return_Due', 'Return due') },
]);
const loading = ref(false);
const saving = ref(false);
const form = ref({
  item_code_prefix: 'RI', contract_prefix: 'RNT', reservation_prefix: 'RSV', invoice_prefix: 'RINV',
  grace_days: 3, late_fee_type: 'none', late_fee_value: 0, late_fee_frequency: 'once',
  default_deposit: 0, buffer_hours: 0, default_hold_hours: 48, expiring_soon_days: 30, reminder_days_before: 3, invoice_lead_days: 7, checkout_checklist: [], checkin_checklist: [],
  reminders_enabled: false, reminder_channels: ['email'], overdue_reminder_every_days: 3, expiry_reminder_days: 14, whatsapp_active: false,
  reminder_templates: { rent_due: { subject: '', body: '' }, rent_overdue: { subject: '', body: '' }, contract_expiring: { subject: '', body: '' }, return_due: { subject: '', body: '' } },
  agreement_terms: '',
  website_ready: true, business_types: [], website_booking: true, website_hold_hours: 24, website_min_notice_hours: 2,
  website_max_days_ahead: 180, website_require_login: false, website_fields: {}, pickup_locations: [], website_terms: '',
  store_theme: null,
});

const prefixes = computed(() => [
  { field: 'item_code_prefix', label: t('Rental_Item_Code_Prefix', 'Item code prefix') },
  { field: 'contract_prefix', label: t('Rental_Contract_Prefix', 'Contract prefix') },
  { field: 'reservation_prefix', label: t('Rental_Reservation_Prefix', 'Reservation prefix') },
  { field: 'invoice_prefix', label: t('Rental_Invoice_Prefix', 'Invoice prefix') },
]);

const feeTypeOptions = computed(() => [
  { value: 'none', label: t('Rental_Late_Fee_None', 'No late fee') },
  { value: 'fixed', label: t('Rental_Late_Fee_Fixed', 'Fixed amount') },
  { value: 'percent', label: t('Rental_Late_Fee_Percentage', 'Percentage') },
]);

const frequencyOptions = computed(() => [
  { value: 'once', label: t('Rental_Late_Fee_Once', 'Once per late payment') },
  { value: 'daily', label: t('Rental_Late_Fee_Daily', 'Every day it stays late') },
]);

/** Plain-language read-back of the policy, so the numbers can't be misread. */
const lateFeeSummary = computed(() => {
  const f = form.value;
  const grace = tp('Rental_Summary_Grace', 'A payment becomes late {days} day(s) after its due date.', { days: f.grace_days ?? 0 });
  if (f.late_fee_type === 'none') {
    return `${grace} ${t('Rental_Summary_No_Fee', 'No late fee is charged.')}`;
  }
  const amount = f.late_fee_type === 'percent'
    ? tp('Rental_Summary_Percent', '{value}% of the overdue amount', { value: f.late_fee_value || 0 })
    : money(f.late_fee_value || 0);
  const when = f.late_fee_frequency === 'daily'
    ? t('Rental_Summary_Daily', 'is added for every day it stays unpaid.')
    : t('Rental_Summary_Once', 'is added once.');
  return `${grace} ${amount} ${when}`;
});

const PREFIX_RULE = () => [
  { required: true, message: t('Field_is_required', 'This field is required') },
  { pattern: /^[A-Za-z0-9_]+$/, message: t('Rental_Prefix_Rule', 'Letters, digits and underscores only') },
];
const rules = computed(() => ({
  item_code_prefix: PREFIX_RULE(),
  contract_prefix: PREFIX_RULE(),
  reservation_prefix: PREFIX_RULE(),
  invoice_prefix: PREFIX_RULE(),
}));

function upper(field) {
  form.value[field] = (form.value[field] || '').toUpperCase().trim();
}

// Save stays off until the real settings arrived, so defaults never overwrite them.
const loaded = ref(false);

/** API → form: arrays/objects never null, so the pickers can bind to them. */
function normalize(s) {
  return {
    ...s,
    agreement_terms: s.agreement_terms || '',
    website_terms: s.website_terms || '',
    business_types: Array.isArray(s.business_types) ? s.business_types : [],
    pickup_locations: Array.isArray(s.pickup_locations) ? s.pickup_locations : [],
    website_fields: s.website_fields && typeof s.website_fields === 'object' ? { ...s.website_fields } : {},
  };
}

async function load() {
  loading.value = true;
  try {
    const data = await http.get('rental/settings');
    if (data?.settings) {
      form.value = normalize({ ...form.value, ...data.settings });
      loaded.value = true;
    }
  } catch (e) {
    message.error(t('Rental_Could_Not_Load_Settings', 'Could not load the rental settings'));
  } finally {
    loading.value = false;
  }
}

async function save() {
  try {
    await formRef.value.validate();
  } catch (e) {
    return;
  }
  saving.value = true;
  try {
    const data = await http.put('rental/settings', form.value);
    if (data?.settings) form.value = normalize({ ...form.value, ...data.settings });
    // Item form, categories and filters follow the new business types.
    reloadRentalTypes();
    message.success(t('Updated_in_successfully', 'Updated successfully'));
  } catch (e) {
    const errors = e?.data?.errors;
    const first = errors ? Object.values(errors)[0]?.[0] : null;
    message.error(first || e?.data?.message || t('Rental_Could_Not_Save_Settings', 'Could not save the settings'));
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
.card-title :deep(.anticon) {
  color: var(--app-primary, #6d28d9);
}
.card-help {
  margin: 0 0 14px;
  font-size: 12.5px;
  opacity: 0.65;
}
.preview {
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
  font-size: 11.5px;
  opacity: 0.5;
}
.summary {
  margin-top: 4px;
}
.no-mb {
  margin-bottom: 0;
}
.dim {
  opacity: 0.55;
}
.muted {
  opacity: 0.6;
}
.cron-note {
  margin: 12px 0 0;
  font-size: 12px;
  opacity: 0.7;
}
.cron-note code {
  font-size: 11.5px;
}

/* business type */
.biz-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 10px;
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
  border-top: 1px dashed rgba(128, 128, 128, 0.2);
}
.field-name {
  min-width: 0;
  font-size: 13px;
}
@media (max-width: 575px) {
  .biz-grid {
    grid-template-columns: 1fr;
  }
  .field-row {
    flex-wrap: wrap;
  }
}
</style>
