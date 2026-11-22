<template>
  <div class="page">
    <PageHeader :title="$t('Cr_Courier_Settings')" :breadcrumb="[$t('Cr_Couriers'), $t('Cr_Courier_Settings')]" />

    <a-spin :spinning="isLoading">
      <a-row :gutter="[16, 16]">
        <a-col :xs="24" :lg="12">
          <a-card size="small" :title="$t('Cr_Returned_Parcels')" class="fill">
            <p class="muted">{{ $t('Cr_Returned_Parcels_Help') }}</p>
            <a-radio-group v-model:value="form.return_action" class="stack">
              <a-radio value="review">
                <strong>{{ $t('Cr_Return_Review') }}</strong>
                <div class="muted option-help">{{ $t('Cr_Return_Review_Help') }}</div>
              </a-radio>
              <a-radio value="restock">
                <strong>{{ $t('Cr_Return_Restock') }}</strong>
                <div class="muted option-help">{{ $t('Cr_Return_Restock_Help') }}</div>
              </a-radio>
            </a-radio-group>
          </a-card>
        </a-col>

        <a-col :xs="24" :lg="12">
          <a-card size="small" :title="$t('Cr_COD_Payments')" class="fill">
            <div class="switch-row">
              <a-switch v-model:checked="form.cod_auto_record" />
              <span>{{ $t('Cr_COD_Auto_Record') }}</span>
            </div>
            <a-form layout="vertical">
              <a-form-item :label="$t('Account')" :extra="$t('Cr_COD_Account_Help')">
                <a-select v-model:value="form.cod_account_id" :options="accountOptions" allow-clear show-search option-filter-prop="label" :disabled="!form.cod_auto_record" />
              </a-form-item>
              <a-form-item :label="$t('Payment_Method')" style="margin-bottom: 0">
                <a-select v-model:value="form.cod_payment_method_id" :options="paymentMethodOptions" allow-clear :disabled="!form.cod_auto_record" />
              </a-form-item>
            </a-form>
            <a-alert
              v-if="form.cod_auto_record && !form.cod_account_id" type="warning" show-icon
              :message="$t('Cr_COD_No_Account_Warning')" style="margin-top: 12px"
            />
          </a-card>
        </a-col>

        <a-col :xs="24" :lg="12">
          <a-card size="small" :title="$t('Cr_Courier_Charges')" class="fill">
            <div class="switch-row">
              <a-switch v-model:checked="form.charges_as_expense" />
              <span>{{ $t('Cr_Charges_As_Expense') }}</span>
            </div>
            <a-form layout="vertical">
              <a-form-item :label="$t('Cr_Expense_Category') + (form.charges_as_expense ? ' *' : '')" style="margin-bottom: 0">
                <a-select v-model:value="form.charges_expense_category_id" :options="categoryOptions" allow-clear show-search option-filter-prop="label" :disabled="!form.charges_as_expense" />
              </a-form-item>
            </a-form>
          </a-card>
        </a-col>

        <a-col :xs="24" :lg="12">
          <a-card size="small" :title="$t('Cr_Status_Updates')" class="fill">
            <p class="muted">{{ $t('Cr_Status_Updates_Help') }}</p>
            <a-form layout="vertical">
              <a-form-item :label="$t('Cr_Check_Every')" style="margin-bottom: 0">
                <a-select v-model:value="form.sync_interval_minutes" :options="intervalOptions" style="max-width: 240px" />
              </a-form-item>
            </a-form>
          </a-card>
        </a-col>

        <a-col v-if="!options.phase7_ready" :xs="24">
          <a-alert type="info" show-icon :message="$t('Cr_Phase7_Migration')" />
        </a-col>

        <a-col :xs="24">
          <a-card size="small" :title="$t('Cr_Customer_Notifications')">
            <p class="muted">{{ $t('Cr_Customer_Notifications_Help') }}</p>
            <a-row :gutter="[16, 8]">
              <a-col :xs="24" :md="8">
                <div class="switch-row tight">
                  <a-switch v-model:checked="form.notify_sms" :disabled="!options.phase7_ready" />
                  <span>{{ $t('Cr_Notify_SMS') }}</span>
                </div>
              </a-col>
              <a-col :xs="24" :md="8">
                <div class="switch-row tight">
                  <a-switch v-model:checked="form.notify_email" :disabled="!options.phase7_ready" />
                  <span>{{ $t('Cr_Notify_Email') }}</span>
                </div>
              </a-col>
              <a-col :xs="24" :md="8">
                <div class="switch-row tight">
                  <a-switch v-model:checked="form.notify_whatsapp" :disabled="!options.phase7_ready" />
                  <span>{{ $t('Cr_Notify_WhatsApp') }}</span>
                </div>
              </a-col>
            </a-row>
            <a-alert v-if="form.notify_whatsapp" type="info" show-icon class="wa-help">
              <template #message>
                {{ $t('Cr_WhatsApp_Template_Help', { key: 'courier_update', example: 'courier_out_for_delivery' }) }}
                <div class="chips">
                  <code v-for="v in WHATSAPP_VARS" :key="v">{{ v }}</code>
                </div>
              </template>
            </a-alert>

            <div class="field-label">{{ $t('Cr_Notify_When') }}</div>
            <a-checkbox-group v-model:value="form.notify_statuses" :disabled="!options.phase7_ready || !anyChannel" class="status-checks">
              <a-checkbox v-for="st in notifyStatusOptions" :key="st.value" :value="st.value">{{ st.label }}</a-checkbox>
            </a-checkbox-group>

            <template v-if="anyChannel && form.notify_statuses.length">
              <div class="field-label">
                {{ $t('Cr_Message_Templates') }}
                <span class="muted placeholders">
                  {{ $t('Cr_Placeholders') }}:
                  <code v-for="p in PLACEHOLDERS" :key="p" class="ph" @click="copyPlaceholder(p)">{{ p }}</code>
                </span>
              </div>
              <div v-for="st in selectedStatusOptions" :key="st.value" class="template-row">
                <div class="template-head">
                  <a-tag :color="st.color">{{ st.label }}</a-tag>
                  <a v-if="form.notify_templates[st.value] !== defaultTemplate(st.value)" class="reset" @click="form.notify_templates[st.value] = defaultTemplate(st.value)">
                    {{ $t('Cr_Reset_Default') }}
                  </a>
                </div>
                <a-textarea v-model:value="form.notify_templates[st.value]" :auto-size="{ minRows: 2, maxRows: 5 }" :maxlength="1000" :disabled="!options.phase7_ready" />
              </div>
            </template>
          </a-card>
        </a-col>

        <a-col :xs="24" :lg="12">
          <a-card size="small" :title="$t('Cr_Tracking_Page')" class="fill">
            <p class="muted">{{ $t('Cr_Tracking_Page_Help') }}</p>
            <div class="switch-row">
              <a-switch v-model:checked="form.tracking_page" :disabled="!options.phase7_ready" />
              <span>{{ $t('Cr_Tracking_Page_Enable') }}</span>
            </div>
            <div v-if="form.tracking_page && options.tracking_page_url" class="page-url">
              <code>{{ options.tracking_page_url }}</code>
              <a :href="options.tracking_page_url" target="_blank" rel="noopener">{{ $t('Cr_Open_Page') }}</a>
            </div>
            <a-alert v-else-if="form.tracking_page" type="warning" show-icon :message="$t('Cr_Tracking_Page_No_Store')" />
          </a-card>
        </a-col>

        <a-col :xs="24" :lg="12">
          <a-card size="small" :title="$t('Cr_Return_Risk')" class="fill">
            <p class="muted">{{ $t('Cr_Return_Risk_Help') }}</p>
            <a-form layout="vertical">
              <a-row :gutter="16">
                <a-col :xs="24" :sm="12">
                  <a-form-item :label="$t('Cr_Risk_High_At')" style="margin-bottom: 0">
                    <a-input-number v-model:value="form.risk_high_percent" :min="5" :max="100" addon-after="%" :disabled="!options.phase7_ready" style="width: 100%" />
                  </a-form-item>
                </a-col>
                <a-col :xs="24" :sm="12">
                  <a-form-item :label="$t('Cr_Risk_Min_Shipments')" style="margin-bottom: 0">
                    <a-input-number v-model:value="form.risk_min_shipments" :min="1" :max="50" :disabled="!options.phase7_ready" style="width: 100%" />
                  </a-form-item>
                </a-col>
              </a-row>
            </a-form>
          </a-card>
        </a-col>
      </a-row>

      <div class="actions">
        <a-button type="primary" :loading="saving" @click="save">{{ $t('Save') }}</a-button>
      </div>
    </a-spin>
  </div>
</template>

<script setup>
/**
 * Courier settings — GET couriers/settings → {settings, options{accounts,
 * payment_methods, expense_categories, sync_intervals}}; PUT couriers/settings.
 */
import { ref, computed, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';
import { errorText, courierStatus } from './courierShared';

const { t } = useI18n();

// Message placeholders (CourierNotifier) and WhatsApp template variables — shown as code, never put in i18n strings.
const PLACEHOLDERS = ['{contact_name}', '{business_name}', '{invoice_number}', '{courier}', '{tracking_number}', '{tracking_url}', '{cod_amount}', '{cod_note}'];
const WHATSAPP_VARS = ['{{customer_name}}', '{{invoice_number}}', '{{courier}}', '{{tracking_number}}', '{{tracking_url}}', '{{cod_amount}}', '{{status}}'];

const isLoading = ref(true);
const saving = ref(false);
const options = ref({ accounts: [], payment_methods: [], expense_categories: [], sync_intervals: [], notify_statuses: [], default_templates: {}, phase7_ready: false, tracking_page_url: null });
const form = ref({
  return_action: 'review',
  cod_auto_record: true,
  cod_account_id: undefined,
  cod_payment_method_id: undefined,
  charges_as_expense: false,
  charges_expense_category_id: undefined,
  sync_interval_minutes: 60,
  notify_sms: false,
  notify_email: false,
  notify_whatsapp: false,
  notify_statuses: [],
  notify_templates: {},
  tracking_page: true,
  risk_high_percent: 30,
  risk_min_shipments: 3,
});

const anyChannel = computed(() => form.value.notify_sms || form.value.notify_email || form.value.notify_whatsapp);
const notifyStatusOptions = computed(() => (options.value.notify_statuses || []).map(v => {
  const s = courierStatus(v);
  return { value: v, label: s ? t(s.key) : v, color: s ? s.color : 'default' };
}));
const selectedStatusOptions = computed(() => notifyStatusOptions.value.filter(o => form.value.notify_statuses.includes(o.value)));
const defaultTemplate = st => (options.value.default_templates || {})[st] || '';

function copyPlaceholder(p) {
  try {
    navigator.clipboard && navigator.clipboard.writeText(p);
    message.success(p);
  } catch (e) {
    /* clipboard unavailable: the code chip stays selectable */
  }
}

const toOptions = list => (list || []).map(x => ({ label: x.name, value: x.id }));
const accountOptions = computed(() => toOptions(options.value.accounts));
const paymentMethodOptions = computed(() => toOptions(options.value.payment_methods));
const categoryOptions = computed(() => toOptions(options.value.expense_categories));
const intervalOptions = computed(() => (options.value.sync_intervals || []).map(m => ({
  value: m,
  label: m < 60 ? t('Cr_Minutes', { n: m }) : t('Cr_Hours', { n: m / 60 }),
})));

async function load() {
  try {
    const resp = await http.get('couriers/settings');
    options.value = resp.options || options.value;
    const s = resp.settings || {};
    form.value = {
      return_action: s.return_action || 'review',
      cod_auto_record: !!s.cod_auto_record,
      cod_account_id: s.cod_account_id || undefined,
      cod_payment_method_id: s.cod_payment_method_id || undefined,
      charges_as_expense: !!s.charges_as_expense,
      charges_expense_category_id: s.charges_expense_category_id || undefined,
      sync_interval_minutes: s.sync_interval_minutes || 60,
      notify_sms: !!s.notify_sms,
      notify_email: !!s.notify_email,
      notify_whatsapp: !!s.notify_whatsapp,
      notify_statuses: Array.isArray(s.notify_statuses) ? [...s.notify_statuses] : [],
      notify_templates: { ...(options.value.default_templates || {}), ...(s.notify_templates || {}) },
      tracking_page: s.tracking_page !== false,
      risk_high_percent: s.risk_high_percent || 30,
      risk_min_shipments: s.risk_min_shipments || 3,
    };
  } catch (e) {
    message.error(errorText(e, t('Failed')));
  } finally {
    isLoading.value = false;
  }
}

async function save() {
  saving.value = true;
  const f = form.value;
  try {
    await http.put('couriers/settings', {
      return_action: f.return_action,
      cod_auto_record: f.cod_auto_record ? 1 : 0,
      cod_account_id: f.cod_account_id || null,
      cod_payment_method_id: f.cod_payment_method_id || null,
      charges_as_expense: f.charges_as_expense ? 1 : 0,
      charges_expense_category_id: f.charges_expense_category_id || null,
      sync_interval_minutes: f.sync_interval_minutes,
      ...(options.value.phase7_ready ? {
        notify_sms: f.notify_sms ? 1 : 0,
        notify_email: f.notify_email ? 1 : 0,
        notify_whatsapp: f.notify_whatsapp ? 1 : 0,
        notify_statuses: f.notify_statuses,
        notify_templates: f.notify_templates,
        tracking_page: f.tracking_page ? 1 : 0,
        risk_high_percent: f.risk_high_percent,
        risk_min_shipments: f.risk_min_shipments,
      } : {}),
    });
    message.success(t('Successfully_Updated'));
  } catch (e) {
    message.error(errorText(e, t('InvalidData')));
  } finally {
    saving.value = false;
  }
}

onMounted(load);
</script>

<style scoped>
.muted {
  color: rgba(var(--ink-rgb), 0.45);
}
.fill {
  height: 100%;
}
.stack {
  display: flex;
  flex-direction: column;
  gap: 14px;
}
.option-help {
  font-size: 12px;
  font-weight: normal;
  white-space: normal;
}
.switch-row {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 14px;
}
.actions {
  margin-top: 16px;
  display: flex;
  justify-content: flex-end;
}
.switch-row.tight {
  margin-bottom: 4px;
}
.wa-help {
  margin: 10px 0 4px;
}
.chips {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  margin-top: 6px;
}
.field-label {
  font-weight: 600;
  margin: 16px 0 8px;
}
.status-checks {
  display: flex;
  flex-wrap: wrap;
  gap: 8px 4px;
}
.placeholders {
  font-weight: normal;
  font-size: 12px;
  margin-left: 8px;
}
.ph {
  cursor: pointer;
  margin: 0 3px;
}
.template-row {
  margin-bottom: 12px;
}
.template-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 4px;
}
.reset {
  font-size: 12px;
}
.page-url {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 10px;
  word-break: break-all;
}
</style>
