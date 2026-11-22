<template>
  <div class="page">
    <PageHeader
      :title="$t('Rst_Settings')"
      :subtitle="$t('Rst_Settings_Subtitle')"
      :breadcrumb="[$t('Restaurant_Management'), $t('Rst_Settings')]"
    >
      <template #actions>
        <a-button type="primary" :loading="saving" :disabled="loading || !loaded" @click="save">
          <template #icon><SaveOutlined /></template>
          {{ $t('Save') }}
        </a-button>
      </template>
    </PageHeader>

    <div v-if="loading" class="loading"><a-spin size="large" /></div>

    <a-alert v-else-if="!ready" type="warning" show-icon :message="$t('Rst_Not_Ready')" />

    <a-form v-else ref="formRef" :model="form" :rules="rules" layout="vertical">
      <a-row :gutter="16">
        <a-col :xs="24" :lg="12">
          <!-- Order types -->
          <a-card size="small" class="block">
            <template #title><span class="card-title"><ShopOutlined /> {{ $t('Rst_Order_Types') }}</span></template>
            <p class="card-help">{{ $t('Rst_Order_Types_Help') }}</p>
            <div class="switches">
              <label class="switch-row"><a-switch v-model:checked="form.dine_in_enabled" /> {{ $t('Rst_Dine_In') }}</label>
              <label class="switch-row"><a-switch v-model:checked="form.takeaway_enabled" /> {{ $t('Rst_Takeaway') }}</label>
              <label class="switch-row"><a-switch v-model:checked="form.delivery_enabled" /> {{ $t('Rst_Delivery') }}</label>
              <label class="switch-row"><a-switch v-model:checked="form.require_guest_count" /> {{ $t('Rst_Require_Guest_Count') }}</label>
            </div>
            <a-alert v-if="noOrderType" type="error" show-icon style="margin-top: 12px" :message="$t('Rst_At_Least_One_Type')" />
            <a-row :gutter="16" style="margin-top: 16px">
              <a-col :xs="24" :sm="12">
                <a-form-item :label="$t('Rst_Order_Prefix')" name="order_prefix" :extra="$t('Rst_Order_Prefix_Help')">
                  <a-input v-model:value="form.order_prefix" :maxlength="16" @blur="form.order_prefix = (form.order_prefix || '').toUpperCase().trim()">
                    <template #suffix><span class="preview">{{ (form.order_prefix || '').toUpperCase() }}-0001</span></template>
                  </a-input>
                </a-form-item>
              </a-col>
              <a-col :xs="24" :sm="12">
                <a-form-item :label="$t('Rst_Default_Warehouse')" :extra="$t('Rst_Default_Warehouse_Help')">
                  <a-select
                    v-model:value="form.default_warehouse_id" allow-clear :placeholder="$t('Rst_Ask_Each_Time')"
                    :options="warehouses.map(w => ({ value: w.id, label: w.name }))"
                  />
                </a-form-item>
              </a-col>
            </a-row>
          </a-card>

          <!-- Kitchen -->
          <a-card size="small" class="block">
            <template #title><span class="card-title"><FireOutlined /> {{ $t('Rst_Kitchen') }}</span></template>
            <a-row :gutter="16">
              <a-col :xs="24" :sm="12">
                <a-form-item :label="$t('Rst_Late_Ticket_After')" name="late_ticket_minutes" :extra="$t('Rst_Late_Ticket_Help')">
                  <a-input-number v-model:value="form.late_ticket_minutes" :min="1" :max="240" :precision="0" style="width: 100%" :addon-after="$t('Rst_Minutes')" />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :sm="12">
                <a-form-item :label="$t('Rst_Online_Orders_Kitchen')" :extra="$t('Rst_Online_Orders_Kitchen_Help')">
                  <a-select v-model:value="form.kitchen_online_orders" :options="kitchenOnlineOptions" />
                </a-form-item>
              </a-col>
            </a-row>
          </a-card>
        </a-col>

        <a-col :xs="24" :lg="12">
          <!-- Bills -->
          <a-card size="small" class="block">
            <template #title><span class="card-title"><ReceiptIcon /> {{ $t('Rst_Bills') }}</span></template>
            <a-row :gutter="16">
              <a-col :xs="24" :sm="12">
                <a-form-item :label="$t('Rst_Service_Charge')" name="service_charge_percent" :extra="$t('Rst_Service_Charge_Help')">
                  <a-input-number v-model:value="form.service_charge_percent" :min="0" :max="100" :step="0.5" style="width: 100%" addon-after="%" />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :sm="12">
                <a-form-item :label="$t('Rst_Service_Charge_On')">
                  <a-select v-model:value="form.service_charge_scope" :options="scopeOptions" :disabled="!form.service_charge_percent" />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :sm="12">
                <a-form-item :label="$t('Rst_Bill_Rounding')" :extra="$t('Rst_Bill_Rounding_Help')">
                  <a-select v-model:value="form.bill_rounding" :options="roundingOptions" />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :sm="12">
                <a-form-item :label="$t('Rst_Max_Discount')" name="max_discount_percent" :extra="$t('Rst_Max_Discount_Help')">
                  <a-input-number v-model:value="form.max_discount_percent" :min="0" :max="100" style="width: 100%" addon-after="%" />
                </a-form-item>
              </a-col>
            </a-row>
            <label class="switch-row"><a-switch v-model:checked="form.tips_enabled" /> {{ $t('Rst_Tips_Enabled') }}</label>
            <a-form-item v-if="form.tips_enabled" :label="$t('Rst_Tip_Presets')" :extra="$t('Rst_Tip_Presets_Help')" style="margin-top: 12px">
              <a-select
                v-model:value="form.tip_presets" mode="tags" :token-separators="[',', ' ']" :open="false"
                :max-tag-count="6" style="width: 100%" @change="cleanTips"
              />
            </a-form-item>
            <a-form-item :label="$t('Rst_After_Payment')" :extra="$t('Rst_After_Payment_Help')">
              <a-radio-group v-model:value="form.after_payment_table_status">
                <a-radio value="cleaning">{{ $t('Rst_Status_Cleaning') }}</a-radio>
                <a-radio value="free">{{ $t('Rst_Status_Free') }}</a-radio>
              </a-radio-group>
            </a-form-item>
          </a-card>

          <!-- Reservations -->
          <a-card size="small" class="block">
            <template #title><span class="card-title"><CalendarOutlined /> {{ $t('Rst_Reservations') }}</span></template>
            <a-row :gutter="12">
              <a-col :xs="24" :sm="12">
                <a-form-item :label="$t('Rst_Booking_Length')" :extra="$t('Rst_Booking_Length_Help')">
                  <a-input-number v-model:value="form.reservation_minutes" :min="15" :max="720" :step="15" :precision="0" style="width: 100%" :addon-after="$t('Rst_Minutes')" />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :sm="12">
                <a-form-item :label="$t('Rst_Max_Party')">
                  <a-input-number v-model:value="form.max_party_size" :min="1" :max="500" :precision="0" style="width: 100%" />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :sm="12">
                <a-form-item :label="$t('Rst_Hold_Before')" :extra="$t('Rst_Hold_Before_Help')">
                  <a-input-number v-model:value="form.reservation_hold_minutes" :min="0" :max="240" :precision="0" style="width: 100%" :addon-after="$t('Rst_Minutes')" />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :sm="12">
                <a-form-item :label="$t('Rst_No_Show_After')" :extra="$t('Rst_No_Show_After_Help')">
                  <a-input-number v-model:value="form.no_show_minutes" :min="0" :max="240" :precision="0" style="width: 100%" :addon-after="$t('Rst_Minutes')" />
                </a-form-item>
              </a-col>
            </a-row>
          </a-card>

          <!-- QR table ordering + online booking (phase 8) -->
          <a-card size="small" class="block">
            <template #title><span class="card-title"><QrcodeOutlined /> {{ $t('Rst_Qr_Ordering') }}</span></template>
            <a-form-item :extra="$t('Rst_Qr_Ordering_Help')">
              <a-switch v-model:checked="form.qr_ordering_enabled" /> <span style="margin-inline-start: 8px">{{ $t('Rst_Qr_Ordering_On') }}</span>
            </a-form-item>
            <a-form-item v-if="form.qr_ordering_enabled" :extra="$t('Rst_Qr_Auto_Send_Help')">
              <a-checkbox v-model:checked="form.qr_auto_send">{{ $t('Rst_Qr_Auto_Send') }}</a-checkbox>
            </a-form-item>
          </a-card>

          <a-card size="small" class="block">
            <template #title><span class="card-title"><GlobalOutlined /> {{ $t('Rst_Online_Booking') }}</span></template>
            <a-form-item :extra="$t('Rst_Online_Booking_Help')">
              <a-switch v-model:checked="form.online_booking_enabled" /> <span style="margin-inline-start: 8px">{{ $t('Rst_Online_Booking_On') }}</span>
            </a-form-item>
            <a-row v-if="form.online_booking_enabled" :gutter="12">
              <a-col :xs="12" :sm="8"><a-form-item :label="$t('Rst_First_Booking')"><a-time-picker v-model:value="form.booking_from" value-format="HH:mm" format="HH:mm" :minute-step="15" :allow-clear="false" style="width: 100%" /></a-form-item></a-col>
              <a-col :xs="12" :sm="8"><a-form-item :label="$t('Rst_Last_Booking')"><a-time-picker v-model:value="form.booking_to" value-format="HH:mm" format="HH:mm" :minute-step="15" :allow-clear="false" style="width: 100%" /></a-form-item></a-col>
              <a-col :xs="12" :sm="8"><a-form-item :label="$t('Rst_Every')"><a-select v-model:value="form.booking_slot_minutes" :options="[15, 30, 60].map(v => ({ value: v, label: $t('Rst_Minutes_N', { n: v }) }))" /></a-form-item></a-col>
              <a-col :xs="12" :sm="8"><a-form-item :label="$t('Rst_Online_Max_Party')"><a-input-number v-model:value="form.online_max_party" :min="1" :max="500" :precision="0" style="width: 100%" /></a-form-item></a-col>
              <a-col :xs="12" :sm="8"><a-form-item :label="$t('Rst_Notice')" :extra="$t('Rst_Notice_Help')"><a-input-number v-model:value="form.booking_notice_minutes" :min="0" :max="10080" :precision="0" style="width: 100%" :addon-after="$t('Rst_Minutes')" /></a-form-item></a-col>
              <a-col :xs="12" :sm="8"><a-form-item :label="$t('Rst_Days_Ahead')"><a-input-number v-model:value="form.booking_days_ahead" :min="1" :max="365" :precision="0" style="width: 100%" /></a-form-item></a-col>
            </a-row>
            <div v-if="form.online_booking_enabled" class="muted small">{{ $t('Rst_Online_Booking_Tables_Note') }}</div>
          </a-card>

          <!-- Receipt -->
          <a-card size="small" class="block">
            <template #title><span class="card-title"><PrinterOutlined /> {{ $t('Rst_Receipt') }}</span></template>
            <a-form-item :label="$t('Rst_Receipt_Header')" :extra="$t('Rst_Receipt_Header_Help')">
              <a-textarea v-model:value="form.receipt_header" :rows="2" :maxlength="1000" />
            </a-form-item>
            <a-form-item :label="$t('Rst_Receipt_Footer')">
              <a-textarea v-model:value="form.receipt_footer" :rows="2" :maxlength="1000" :placeholder="$t('Rst_Receipt_Footer_Placeholder')" />
            </a-form-item>
          </a-card>
        </a-col>
      </a-row>
    </a-form>
  </div>
</template>

<script setup>
/**
 * Restaurant Management › Settings — GET restaurant/settings → {ready, settings, warehouses};
 * PUT restaurant/settings (permission rst_settings).
 */
import { ref, computed, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { SaveOutlined, ShopOutlined, FireOutlined, PrinterOutlined, CalendarOutlined, QrcodeOutlined, GlobalOutlined, FileTextOutlined as ReceiptIcon } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';

const { t } = useI18n();

const loading = ref(true);
const saving = ref(false);
const ready = ref(true);
const loaded = ref(false);
const formRef = ref();
const warehouses = ref([]);
const form = ref({
  order_prefix: 'RO', dine_in_enabled: true, takeaway_enabled: true, delivery_enabled: false, require_guest_count: true,
  service_charge_percent: 0, service_charge_scope: 'dine_in', tips_enabled: true, tip_presets: [5, 10, 15],
  bill_rounding: 0, max_discount_percent: 100, default_warehouse_id: undefined, late_ticket_minutes: 20,
  after_payment_table_status: 'cleaning', kitchen_online_orders: 'on_accept', receipt_header: '', receipt_footer: '',
  reservation_minutes: 90, reservation_hold_minutes: 30, no_show_minutes: 15, max_party_size: 20,
  qr_ordering_enabled: false, qr_auto_send: false, online_booking_enabled: false, booking_from: '12:00', booking_to: '22:00',
  booking_slot_minutes: 30, online_max_party: 8, booking_notice_minutes: 60, booking_days_ahead: 30,
});

const noOrderType = computed(() => !form.value.dine_in_enabled && !form.value.takeaway_enabled && !form.value.delivery_enabled);

const scopeOptions = computed(() => [
  { value: 'dine_in', label: t('Rst_Scope_Dine_In') },
  { value: 'all', label: t('Rst_Scope_All') },
]);
const kitchenOnlineOptions = computed(() => [
  { value: 'on_accept', label: t('Rst_Kitchen_On_Accept') },
  { value: 'on_place', label: t('Rst_Kitchen_On_Place') },
  { value: 'off', label: t('Rst_Kitchen_Off') },
]);
const roundingOptions = computed(() => [0, 0.05, 0.1, 0.25, 0.5, 1].map(v => ({
  value: v, label: v === 0 ? t('Rst_No_Rounding') : t('Rst_Round_To', { step: v }),
})));

const rules = computed(() => ({
  order_prefix: [
    { required: true, message: t('Field_is_required') },
    { pattern: /^[A-Za-z0-9_]+$/, message: t('Rst_Prefix_Rule') },
  ],
}));

/** Tags input: keep positive numbers up to 100, sorted, no duplicates. */
function cleanTips(values) {
  form.value.tip_presets = [...new Set((values || []).map(v => Number(String(v).replace('%', ''))).filter(v => v > 0 && v <= 100))].sort((a, b) => a - b).slice(0, 6);
}

function apply(settings) {
  form.value = {
    ...form.value,
    ...settings,
    default_warehouse_id: settings.default_warehouse_id || undefined,
    receipt_header: settings.receipt_header || '',
    receipt_footer: settings.receipt_footer || '',
  };
}

async function load() {
  loading.value = true;
  try {
    const data = await http.get('restaurant/settings');
    ready.value = data?.ready !== false;
    warehouses.value = data?.warehouses || [];
    if (data?.settings) {
      apply(data.settings);
      loaded.value = true;
    }
  } catch (e) {
    message.error(t('Rst_Could_Not_Load_Settings'));
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
  if (noOrderType.value) {
    message.error(t('Rst_At_Least_One_Type'));
    return;
  }
  saving.value = true;
  try {
    const data = await http.put('restaurant/settings', { ...form.value, default_warehouse_id: form.value.default_warehouse_id || null });
    if (data?.settings) apply(data.settings);
    message.success(t('Updated_in_successfully'));
  } catch (e) {
    const errors = e?.data?.errors;
    const first = errors ? Object.values(errors)[0]?.[0] : null;
    message.error(first || e?.data?.message || t('Rst_Could_Not_Save_Settings'));
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
.switches {
  display: flex;
  flex-direction: column;
  gap: 10px;
}
.switch-row {
  display: flex;
  align-items: center;
  gap: 10px;
  cursor: pointer;
}
.preview {
  color: rgba(0, 0, 0, 0.45);
  font-size: 12px;
}
.muted { color: rgba(0, 0, 0, 0.55); }
.small { font-size: 12px; }
</style>
