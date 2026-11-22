<template>
  <div class="page">
    <PageHeader :title="$t('Bkm_Guide')" :subtitle="$t('Bkm_Guide_Subtitle')" :breadcrumb="[$t('Booking Management'), $t('Bkm_Guide')]">
      <template #actions>
        <a-space wrap>
          <a-button v-if="can('bookings_services')" @click="$router.push('/bookings/services')">{{ $t('Bkm_Services') }}</a-button>
          <a-button v-if="can('bookings_staff')" @click="$router.push('/bookings/staff')">{{ $t('Bkm_Staff_Hours') }}</a-button>
          <a-button v-if="can('bookings_settings')" @click="$router.push('/bookings/settings')">{{ $t('Bkm_Settings') }}</a-button>
        </a-space>
      </template>
    </PageHeader>

    <a-spin :spinning="loading">
      <a-alert v-if="data && !data.module_on" type="warning" show-icon style="margin-bottom: 16px" :message="$t('Bkm_Guide_Module_Off')" :description="$t('Bkm_Guide_Module_Off_Help')" />

      <a-row :gutter="[16, 16]">
        <!-- Live checklist -->
        <a-col :xs="24" :xl="10">
          <a-card size="small" :title="$t('Bkm_Guide_Your_Setup')" class="fill">
            <p class="muted">{{ $t('Bkm_Guide_Your_Setup_Help') }}</p>
            <a-progress v-if="steps.length" :percent="donePct" :format="() => t('Bkm_Guide_Done_N', { done: doneCount, total: requiredCount })" size="small" style="margin-bottom: 12px" />
            <ol class="checklist">
              <li v-for="step in steps" :key="step.key" :class="'is-' + step.state">
                <span class="dot" aria-hidden="true">
                  <CheckOutlined v-if="step.state === 'done'" />
                  <ExclamationOutlined v-else-if="step.state === 'warn'" />
                  <span v-else>{{ step.n }}</span>
                </span>
                <div class="step-body">
                  <div class="step-title">
                    {{ step.title }}
                    <a-tag v-if="step.optional" class="opt">{{ $t('Bkm_Guide_Optional') }}</a-tag>
                    <span class="sr-state">{{ stateLabel(step.state) }}</span>
                  </div>
                  <div class="muted step-help">{{ step.help }}</div>
                  <a v-if="step.link" class="step-link" href="#" @click.prevent="$router.push(step.link)">{{ step.linkText }} →</a>
                </div>
              </li>
            </ol>
          </a-card>
        </a-col>

        <!-- How it works -->
        <a-col :xs="24" :xl="14">
          <a-card size="small" :title="$t('Bkm_Guide_How')" class="fill">
            <a-collapse v-model:activeKey="open" ghost>
              <a-collapse-panel key="website" :header="$t('Bkm_Guide_Website')">
                <ol class="flow">
                  <li v-for="n in 6" :key="n"><strong>{{ $t('Bkm_Guide_Website_' + n + '_T') }}</strong> — {{ $t('Bkm_Guide_Website_' + n) }}</li>
                </ol>
              </a-collapse-panel>

              <a-collapse-panel key="requests" :header="$t('Bkm_Guide_Requests')">
                <ul class="bul"><li v-for="n in 5" :key="n">{{ $t('Bkm_Guide_Requests_' + n) }}</li></ul>
              </a-collapse-panel>

              <a-collapse-panel key="day" :header="$t('Bkm_Guide_Day')">
                <ul class="bul"><li v-for="n in 6" :key="n">{{ $t('Bkm_Guide_Day_' + n) }}</li></ul>
              </a-collapse-panel>

              <a-collapse-panel key="deposits" :header="$t('Bkm_Guide_Deposits')">
                <ul class="bul"><li v-for="n in 6" :key="n">{{ $t('Bkm_Guide_Deposits_' + n) }}</li></ul>
              </a-collapse-panel>

              <a-collapse-panel key="messages" :header="$t('Bkm_Guide_Messages')">
                <ul class="bul"><li v-for="n in 5" :key="n">{{ $t('Bkm_Guide_Messages_' + n) }}</li></ul>
              </a-collapse-panel>

              <a-collapse-panel key="customers" :header="$t('Bkm_Guide_Customers')">
                <ul class="bul"><li v-for="n in 4" :key="n">{{ $t('Bkm_Guide_Customers_' + n) }}</li></ul>
              </a-collapse-panel>

              <a-collapse-panel key="roles" :header="$t('Bkm_Guide_Roles')">
                <p class="muted">{{ $t('Bkm_Guide_Roles_Help') }}</p>
                <table class="roles">
                  <thead><tr><th>{{ $t('Bkm_Guide_Job') }}</th><th>{{ $t('Bkm_Guide_Give') }}</th></tr></thead>
                  <tbody>
                    <tr v-for="r in ROLES" :key="r.job">
                      <td>{{ $t('Bkm_Guide_Job_' + r.job) }}</td>
                      <td><a-tag v-for="p in r.perms" :key="p">{{ $t(PERM_LABELS[p][0], PERM_LABELS[p][1]) }}</a-tag></td>
                    </tr>
                  </tbody>
                </table>
              </a-collapse-panel>

              <a-collapse-panel key="faq" :header="$t('Bkm_Guide_Faq')">
                <dl class="faq">
                  <template v-for="n in 7" :key="n">
                    <dt>{{ $t('Bkm_Guide_Faq_' + n + '_Q') }}</dt>
                    <dd>{{ $t('Bkm_Guide_Faq_' + n + '_A') }}</dd>
                  </template>
                </dl>
              </a-collapse-panel>
            </a-collapse>
          </a-card>
        </a-col>
      </a-row>
    </a-spin>
  </div>
</template>

<script setup>
/**
 * Booking Management › Guide — GET bookings/guide (any booking permission):
 * a live set-up checklist built from the tenant's own data, plus how the
 * module works, step by step.
 */
import { ref, computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { CheckOutlined, ExclamationOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';

const { t } = useI18n();
const loading = ref(true);
const data = ref(null);
const open = ref(['website']);

const PERM_LABELS = {
  bookings: ['bookings', 'Bookings'],
  bookings_settings: ['Bkm_Perm_Settings', 'Booking settings'],
  bookings_services: ['Bkm_Perm_Services', 'Booking services: durations, staff, website'],
  bookings_staff: ['Bkm_Perm_Staff', 'Bookable staff and working hours'],
  bookings_reports: ['Bkm_Perm_Reports', 'Booking dashboard and reports'],
};
const ROLES = [
  { job: 'Front_Desk', perms: ['bookings'] },
  { job: 'Staff_Member', perms: ['bookings'] },
  { job: 'Manager', perms: ['bookings', 'bookings_services', 'bookings_staff', 'bookings_settings'] },
  { job: 'Owner', perms: ['bookings_reports'] },
];

function can(p) { return !!data.value?.can?.[p]; }
function stateLabel(s) { return s === 'done' ? t('Bkm_Guide_State_Done') : s === 'warn' ? t('Bkm_Guide_State_Warn') : t('Bkm_Guide_State_Todo'); }

const steps = computed(() => {
  const d = data.value;
  if (!d) return [];
  const m = d.migrations || {};
  const c = d.counts || {};
  const s = d.settings || {};
  const allMig = Object.values(m).every(Boolean);
  const list = [
    { key: 'db', state: allMig ? 'done' : 'warn', title: t('Bkm_Guide_Step_Db'), help: allMig ? t('Bkm_Guide_Step_Db_Ok') : t('Bkm_Guide_Step_Db_Help') },
  ];
  if (m.foundation) {
    const types = s.business_types || [];
    const theme = d.store?.theme === 'booking';
    const slotNeedsStaff = s.slot_booking && !c.staff_online;
    list.push(
      { key: 'type', state: types.length ? 'done' : 'warn', title: t('Bkm_Guide_Step_Type'), help: types.length ? t('Bkm_Guide_Step_Type_Ok', { n: types.length }) : t('Bkm_Guide_Step_Type_Help'), link: '/bookings/settings', linkText: t('Bkm_Settings') },
      { key: 'services', state: c.services ? (c.services_website ? 'done' : 'warn') : 'todo', title: t('Bkm_Guide_Step_Services'), help: c.services ? t('Bkm_Guide_Step_Services_Ok', { n: c.services, web: c.services_website }) : t('Bkm_Guide_Step_Services_Help', { n: c.service_products }), link: '/bookings/services', linkText: t('Bkm_Services') },
      { key: 'staff', state: c.staff && c.staff_with_hours ? 'done' : (c.staff ? 'warn' : 'todo'), title: t('Bkm_Guide_Step_Staff'), help: c.staff ? t('Bkm_Guide_Step_Staff_Ok', { n: c.staff, hours: c.staff_with_hours }) : t('Bkm_Guide_Step_Staff_Help'), link: '/bookings/staff', linkText: t('Bkm_Staff_Hours') },
      { key: 'online', state: slotNeedsStaff ? 'warn' : 'done', title: t('Bkm_Guide_Step_Online'), help: !s.slot_booking ? t('Bkm_Guide_Step_Online_Requests') : (c.staff_online ? t('Bkm_Guide_Step_Online_Ok', { n: c.staff_online }) : t('Bkm_Guide_Step_Online_Help')), link: '/bookings/staff', linkText: t('Bkm_Staff_Hours') },
      { key: 'store', state: theme && d.store?.enabled ? 'done' : (theme ? 'warn' : 'todo'), title: t('Bkm_Guide_Step_Store'), help: theme ? (d.store?.enabled ? t('Bkm_Guide_Step_Store_Ok') : t('Bkm_Guide_Step_Store_Off')) : t('Bkm_Guide_Step_Store_Help'), link: '/store/settings', linkText: t('Bkm_Open_Store_Settings') },
      { key: 'mail', state: d.mail_server ? 'done' : 'warn', title: t('Bkm_Guide_Step_Mail'), help: d.mail_server ? t('Bkm_Guide_Step_Mail_Ok') : t('Bkm_Guide_Step_Mail_Help'), link: '/settings/mail', linkText: t('Bkm_Guide_Mail_Settings') },
      { key: 'first', state: c.bookings ? 'done' : 'todo', title: t('Bkm_Guide_Step_First'), help: c.bookings ? t('Bkm_Guide_Step_First_Ok', { n: c.bookings, web: c.website_bookings }) : t('Bkm_Guide_Step_First_Help'), link: '/bookings/requests', linkText: t('Bkm_Requests') },
    );
    if (m.deposits && d.deposits) {
      const on = d.deposits.type !== 'none' || d.deposits.services_own > 0;
      list.push({ key: 'deposit', optional: true, state: on ? (d.deposits.gateways ? 'done' : 'warn') : 'todo', title: t('Bkm_Guide_Step_Deposit'), help: on ? (d.deposits.gateways ? t('Bkm_Guide_Step_Deposit_Ok', { n: d.deposits.gateways }) : t('Bkm_Guide_Step_Deposit_No_Gateway')) : t('Bkm_Guide_Step_Deposit_Help'), link: on && !d.deposits.gateways ? '/store/payment-gateway' : '/bookings/settings', linkText: on && !d.deposits.gateways ? t('Bkm_Open_Payment_Gateways') : t('Bkm_Settings') });
    }
    if (s.whatsapp_notify) {
      list.push({ key: 'whatsapp', optional: true, state: d.whatsapp ? 'done' : 'warn', title: t('Bkm_Guide_Step_Whatsapp'), help: d.whatsapp ? t('Bkm_Guide_Step_Whatsapp_Ok') : t('Bkm_Guide_Step_Whatsapp_Help') });
    }
    if (m.deposits) {
      list.push({ key: 'crm', optional: true, state: d.crm_on ? 'done' : 'todo', title: t('Bkm_Guide_Step_Crm'), help: d.crm_on ? t('Bkm_Guide_Step_Crm_Ok') : (d.crm_available ? t('Bkm_Guide_Step_Crm_Help') : t('Bkm_Guide_Step_Crm_Unavailable')), link: d.crm_available ? '/bookings/settings' : null, linkText: t('Bkm_Settings') });
    }
    list.push({ key: 'portal', optional: true, state: d.portal?.enabled && d.portal?.bookings ? 'done' : 'todo', title: t('Bkm_Guide_Step_Portal'), help: d.portal?.enabled && d.portal?.bookings ? t('Bkm_Guide_Step_Portal_Ok') : t('Bkm_Guide_Step_Portal_Help'), link: '/client-portal/settings', linkText: t('Portal_Settings') });
  }
  let n = 0;
  return list.map((st) => ({ ...st, n: ++n }));
});
const requiredCount = computed(() => steps.value.filter((s) => !s.optional).length);
const doneCount = computed(() => steps.value.filter((s) => !s.optional && s.state === 'done').length);
const donePct = computed(() => (requiredCount.value ? Math.round((doneCount.value / requiredCount.value) * 100) : 0));

onMounted(async () => {
  try { data.value = await http.get('bookings/guide'); } catch (e) { data.value = null; } finally { loading.value = false; }
});
</script>

<style scoped>
.muted { color: rgba(0, 0, 0, 0.55); }
.fill { height: 100%; }
.checklist { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 14px; }
.checklist li { display: flex; gap: 12px; align-items: flex-start; }
.dot { flex: none; width: 28px; height: 28px; border-radius: 999px; display: inline-flex; align-items: center; justify-content: center; font-weight: 700; font-size: 13px; background: #f1f1f6; color: #54546a; }
.is-done .dot { background: #dcfce7; color: #166534; }
.is-warn .dot { background: #fef3c7; color: #92400e; }
.step-body { flex: 1; min-width: 0; }
.step-title { font-weight: 600; display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
.step-help { font-size: 13px; margin-top: 2px; }
.step-link { font-size: 13px; font-weight: 600; display: inline-block; margin-top: 4px; }
.opt { font-weight: 500; }
.sr-state { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }
.flow { margin: 0; padding-inline-start: 20px; display: flex; flex-direction: column; gap: 8px; }
.bul { margin: 0; padding-inline-start: 20px; display: flex; flex-direction: column; gap: 6px; }
.roles { width: 100%; border-collapse: collapse; font-size: 14px; }
.roles th { text-align: start; font-size: 12px; color: rgba(0, 0, 0, 0.55); font-weight: 600; padding: 6px 4px; }
.roles td { padding: 8px 4px; border-top: 1px solid rgba(5, 5, 5, 0.06); vertical-align: top; }
.roles td:first-child { font-weight: 600; white-space: nowrap; padding-inline-end: 12px; }
.faq { margin: 0; }
.faq dt { font-weight: 600; margin-top: 10px; }
.faq dd { margin: 2px 0 0; color: rgba(0, 0, 0, 0.7); }
:global(html.dark) .page .muted, :global(html.dark) .page .roles th { color: rgba(255, 255, 255, 0.6); }
:global(html.dark) .page .faq dd { color: rgba(255, 255, 255, 0.75); }
:global(html.dark) .page .dot { background: rgba(255, 255, 255, 0.1); color: rgba(255, 255, 255, 0.75); }
:global(html.dark) .page .roles td { border-color: rgba(255, 255, 255, 0.08); }
</style>
