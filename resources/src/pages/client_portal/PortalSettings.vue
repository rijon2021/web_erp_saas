<template>
  <div class="page">
    <PageHeader :title="$t('Portal_Settings')" :breadcrumb="[$t('Client_Portal'), $t('Portal_Settings')]">
      <template #actions>
        <a-button :href="portalUrl" target="_blank" rel="noopener">
          <template #icon><ExternalLink :size="14" class="btn-ic" /></template>
          {{ $t('Portal_Open_Portal') }}
        </a-button>
        <a-button type="primary" :loading="saving" :disabled="loading" @click="save">
          <template #icon><SaveOutlined /></template>
          {{ $t('Save') }}
        </a-button>
      </template>
    </PageHeader>

    <a-spin :spinning="loading">
      <a-row :gutter="[16, 16]">
        <a-col :xs="24" :lg="14">
          <!-- Availability -->
          <a-card size="small" :title="$t('Portal_Availability')" style="margin-bottom: 16px">
            <div class="switch-row">
              <div>
                <div class="sw-title">{{ $t('Portal_Enabled_Label') }}</div>
                <div class="sw-help">{{ $t('Portal_Enabled_Help') }}</div>
              </div>
              <a-switch v-model:checked="form.enabled" />
            </div>
            <a-alert v-if="!form.enabled" type="warning" show-icon :message="$t('Portal_Is_Off_Help')" style="margin-top: 12px" />
          </a-card>

          <!-- Modules -->
          <a-card size="small" :title="$t('Portal_Modules')" style="margin-bottom: 16px">
            <p class="card-help">{{ $t('Portal_Modules_Help') }}</p>
            <div class="mod-grid">
              <label v-for="m in moduleList" :key="m.key" class="mod" :class="{ off: !form.modules[m.key] || !inPlan(m.key), locked: !inPlan(m.key) }">
                <span class="mod-ic"><component :is="m.icon" :size="16" /></span>
                <span class="mod-body">
                  <span class="mod-name">{{ m.label }}</span>
                  <span class="mod-help">{{ m.help }}</span>
                  <!-- SaaS: the plan lacks the feature this module is built on. -->
                  <a-tag v-if="!inPlan(m.key)" color="default" class="mod-lock"><LockOutlined /> {{ tr('Portal_Not_In_Plan', 'Not in your plan') }}</a-tag>
                </span>
                <a-switch v-model:checked="form.modules[m.key]" size="small" :disabled="!inPlan(m.key)" />
              </label>
            </div>
          </a-card>

          <!-- Actions -->
          <a-card size="small" :title="$t('Portal_Client_Actions')">
            <div class="switch-row">
              <div>
                <div class="sw-title">{{ $t('Portal_Allow_Quote_Requests') }}</div>
                <div class="sw-help">{{ $t('Portal_Allow_Quote_Requests_Help') }}</div>
              </div>
              <a-switch v-model:checked="form.actions.quotation_requests" :disabled="!form.modules.quotations || !inPlan('quotations')" />
            </div>
            <a-divider style="margin: 12px 0" />
            <div class="switch-row">
              <div>
                <div class="sw-title">{{ $t('Portal_Allow_Booking') }}</div>
                <div class="sw-help">{{ $t('Portal_Allow_Booking_Help') }}</div>
              </div>
              <a-switch v-model:checked="form.actions.appointment_booking" :disabled="!form.modules.appointments || !inPlan('appointments')" />
            </div>
          </a-card>
        </a-col>

        <a-col :xs="24" :lg="10">
          <!-- Welcome -->
          <a-card size="small" :title="$t('Portal_Welcome_Message')" style="margin-bottom: 16px">
            <p class="card-help">{{ $t('Portal_Welcome_Message_Help') }}</p>
            <a-textarea v-model:value="form.welcome_message" :rows="5" :maxlength="2000" show-count />
          </a-card>

          <!-- Language & invitations -->
          <a-card size="small" :title="$t('Portal_Access_Defaults')">
            <a-form layout="vertical">
              <a-form-item :label="$t('Portal_Default_Language')" :extra="$t('Portal_Default_Language_Help')">
                <a-select v-model:value="form.default_locale" :options="localeOptions" allow-clear :placeholder="$t('Portal_Same_As_App')" />
              </a-form-item>
              <a-form-item :label="$t('Portal_Invite_Expiry')" :extra="$t('Portal_Invite_Expiry_Help')">
                <a-input-number v-model:value="form.invitation_expiry_hours" :min="1" :max="720" style="width: 160px" :addon-after="$t('Portal_Hours')" />
              </a-form-item>
              <a-form-item
                :label="tr('Portal_2fa', 'Two-step verification')" style="margin-bottom: 0"
                :extra="tr('Portal_2fa_Help', 'Lets clients protect their portal login with a code sent by email or from an authenticator app, turned on from their profile. Optional for each client.')"
              >
                <a-switch v-model:checked="form.two_factor_enabled" />
              </a-form-item>
            </a-form>
          </a-card>
        </a-col>
      </a-row>
    </a-spin>
  </div>
</template>

<script setup>
/**
 * GET/PUT client-portal/settings — stored in settings.portal_settings and
 * enforced on the portal API by the portal.feature middleware.
 * SaaS: `plan_modules` says which modules the tenant's plan offers; the others
 * are shown locked (the API refuses them whatever the switch says).
 */
import { ref, computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { message } from 'ant-design-vue';
import { SaveOutlined, LockOutlined } from '@ant-design/icons-vue';
import {
  ExternalLink, FileText, CreditCard, ReceiptText, MessagesSquare, Quote,
  CalendarCheck, CalendarClock, FileSignature, KeyRound, Book, Inbox,
} from 'lucide-vue-next';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';

const { t } = useI18n();
// English fallback for keys not seeded yet.
const tr = (key, fallback) => {
  const out = t(key);
  return out === key ? fallback : out;
};

const loading = ref(true);
const saving = ref(false);
const portalUrl = ref('/portal');
const locales = ref({});
const planModules = ref({});
// Until the settings load, nothing shows as locked.
const inPlan = (key) => planModules.value[key] !== false;
const form = ref({
  enabled: true,
  modules: {},
  actions: { quotation_requests: true, appointment_booking: true },
  welcome_message: '',
  default_locale: null,
  invitation_expiry_hours: 48,
  two_factor_enabled: false,
});

const MODULES = [
  { key: 'invoices', icon: FileText, label: 'Portal_Mod_Invoices', help: 'Portal_Mod_Invoices_Help' },
  { key: 'payments', icon: CreditCard, label: 'Portal_Mod_Payments', help: 'Portal_Mod_Payments_Help' },
  { key: 'statement', icon: ReceiptText, label: 'Portal_Mod_Statement', help: 'Portal_Mod_Statement_Help' },
  // SaaS: 'requests' is Protocol & Requests; the CRM (stocky's "requests") is 'deals'.
  { key: 'requests', icon: Inbox, label: 'Portal_Mod_Protocol', help: 'Portal_Mod_Protocol_Help' },
  { key: 'deals', icon: MessagesSquare, label: 'Portal_Mod_Deals', help: 'Portal_Mod_Deals_Help' },
  { key: 'quotations', icon: Quote, label: 'Portal_Mod_Quotations', help: 'Portal_Mod_Quotations_Help' },
  { key: 'appointments', icon: CalendarCheck, label: 'Portal_Mod_Appointments', help: 'Portal_Mod_Appointments_Help' },
  { key: 'bookings', icon: CalendarClock, label: 'Portal_Mod_Bookings', help: 'Portal_Mod_Bookings_Help' },
  { key: 'contracts', icon: FileSignature, label: 'Portal_Mod_Contracts', help: 'Portal_Mod_Contracts_Help' },
  { key: 'rentals', icon: KeyRound, label: 'Portal_Mod_Rentals', help: 'Portal_Mod_Rentals_Help' },
  { key: 'help', icon: Book, label: 'Portal_Mod_Help', help: 'Portal_Mod_Help_Help' },
];
const moduleList = computed(() => MODULES.map(m => ({ ...m, label: t(m.label), help: t(m.help) })));
const localeOptions = computed(() => Object.entries(locales.value).map(([value, label]) => ({ value, label })));

async function load() {
  loading.value = true;
  try {
    const res = await http.get('client-portal/settings');
    form.value = { ...form.value, ...res.settings };
    locales.value = res.locales || {};
    portalUrl.value = res.portal_url || '/portal';
    planModules.value = res.plan_modules || {};
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    loading.value = false;
  }
}

async function save() {
  saving.value = true;
  try {
    const res = await http.put('client-portal/settings', {
      ...form.value,
      default_locale: form.value.default_locale || null,
      invitation_expiry_hours: form.value.invitation_expiry_hours || 48,
    });
    form.value = { ...form.value, ...res.settings };
    message.success(t('Success'));
  } catch (e) {
    message.error(e?.data?.message || Object.values(e?.data?.errors || {})[0]?.[0] || t('InvalidData'));
  } finally {
    saving.value = false;
  }
}

onMounted(load);
</script>

<style scoped>
.btn-ic { margin-right: 6px; vertical-align: -2px; }
.card-help { margin: 0 0 12px; font-size: 13px; opacity: .7; }
.switch-row { display: flex; align-items: center; justify-content: space-between; gap: 16px; }
.sw-title { font-weight: 500; }
.sw-help { font-size: 12px; opacity: .65; }
.mod-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 10px; }
.mod { display: flex; align-items: center; gap: 10px; padding: 10px 12px; border: 1px solid rgba(128, 128, 128, .2); border-radius: 10px; cursor: pointer; transition: opacity .15s, border-color .15s; }
.mod:not(.off) { border-color: rgba(var(--app-primary-rgb, 109, 40, 217), .35); }
.mod.off { opacity: .55; }
.mod.locked { cursor: not-allowed; }
.mod-lock { align-self: flex-start; margin-top: 4px; font-size: 11px; }
.mod-ic { display: inline-flex; align-items: center; justify-content: center; width: 32px; height: 32px; border-radius: 8px; color: var(--app-primary, #6d28d9); background: rgba(var(--app-primary-rgb, 109, 40, 217), .1); flex-shrink: 0; }
.mod-body { flex: 1; display: flex; flex-direction: column; min-width: 0; }
.mod-name { font-weight: 500; }
.mod-help { font-size: 12px; opacity: .65; }
</style>
