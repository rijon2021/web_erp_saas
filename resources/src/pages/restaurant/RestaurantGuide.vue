<template>
  <div class="page">
    <PageHeader :title="$t('Rst_Guide')" :subtitle="$t('Rst_Guide_Subtitle')" :breadcrumb="[$t('Restaurant_Management'), $t('Rst_Guide')]">
      <template #actions>
        <a-space wrap>
          <a-button v-if="auth.can('rst_setup')" @click="$router.push('/restaurant/setup/floor')">{{ $t('Rst_Floor_Setup') }}</a-button>
          <a-button v-if="auth.can('rst_menu')" @click="$router.push('/restaurant/menu')">{{ $t('Rst_Menu') }}</a-button>
          <a-button v-if="auth.can('rst_settings')" @click="$router.push('/restaurant/settings')">{{ $t('Rst_Settings') }}</a-button>
        </a-space>
      </template>
    </PageHeader>

    <a-spin :spinning="loading">
      <a-alert v-if="data && !data.module_on" type="warning" show-icon style="margin-bottom: 16px" :message="$t('Rst_Guide_Module_Off')" :description="$t('Rst_Guide_Module_Off_Help')" />

      <a-row :gutter="[16, 16]">
        <!-- Live checklist -->
        <a-col :xs="24" :xl="10">
          <a-card size="small" :title="$t('Rst_Guide_Your_Setup')" class="fill">
            <p class="muted">{{ $t('Rst_Guide_Your_Setup_Help') }}</p>
            <a-progress v-if="steps.length" :percent="donePct" :format="() => $t('Rst_Guide_Done_N', { done: doneCount, total: requiredCount })" size="small" style="margin-bottom: 12px" />
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
                    <a-tag v-if="step.optional" class="opt">{{ $t('Rst_Guide_Optional') }}</a-tag>
                    <span class="sr-state">{{ stateLabel(step.state) }}</span>
                  </div>
                  <div class="muted step-help">{{ step.help }}</div>
                  <a v-if="step.link" class="step-link" :href="'#'" @click.prevent="$router.push(step.link)">{{ step.linkText }} →</a>
                </div>
              </li>
            </ol>
          </a-card>
        </a-col>

        <!-- How it works -->
        <a-col :xs="24" :xl="14">
          <a-card size="small" :title="$t('Rst_Guide_How')" class="fill">
            <a-collapse v-model:activeKey="open" ghost>
              <a-collapse-panel key="service" :header="$t('Rst_Guide_Service')">
                <ol class="flow">
                  <li v-for="n in 7" :key="n"><strong>{{ $t('Rst_Guide_Service_' + n + '_T') }}</strong> — {{ $t('Rst_Guide_Service_' + n) }}</li>
                </ol>
              </a-collapse-panel>

              <a-collapse-panel key="roles" :header="$t('Rst_Guide_Roles')">
                <p class="muted">{{ $t('Rst_Guide_Roles_Help') }}</p>
                <table class="roles">
                  <thead><tr><th>{{ $t('Rst_Guide_Job') }}</th><th>{{ $t('Rst_Guide_Give') }}</th></tr></thead>
                  <tbody>
                    <tr v-for="r in ROLES" :key="r.job">
                      <td>{{ $t('Rst_Guide_Job_' + r.job) }}</td>
                      <td><a-tag v-for="p in r.perms" :key="p">{{ $t(PERM_LABELS[p]) }}</a-tag></td>
                    </tr>
                  </tbody>
                </table>
              </a-collapse-panel>

              <a-collapse-panel key="menu" :header="$t('Rst_Guide_Menu')">
                <ul class="bul"><li v-for="n in 5" :key="n">{{ $t('Rst_Guide_Menu_' + n) }}</li></ul>
              </a-collapse-panel>

              <a-collapse-panel key="kitchen" :header="$t('Rst_Guide_Kitchen')">
                <ul class="bul"><li v-for="n in 5" :key="n">{{ $t('Rst_Guide_Kitchen_' + n) }}</li></ul>
              </a-collapse-panel>

              <a-collapse-panel key="bills" :header="$t('Rst_Guide_Bills')">
                <ul class="bul"><li v-for="n in 7" :key="n">{{ $t('Rst_Guide_Bills_' + n) }}</li></ul>
              </a-collapse-panel>

              <a-collapse-panel key="bookings" :header="$t('Rst_Guide_Bookings')">
                <ul class="bul"><li v-for="n in 5" :key="n">{{ $t('Rst_Guide_Bookings_' + n) }}</li></ul>
              </a-collapse-panel>

              <a-collapse-panel key="online" :header="$t('Rst_Guide_Online')">
                <ul class="bul"><li v-for="n in 6" :key="n">{{ $t('Rst_Guide_Online_' + n) }}</li></ul>
              </a-collapse-panel>

              <a-collapse-panel key="reports" :header="$t('Rst_Guide_Reports')">
                <ul class="bul"><li v-for="n in 4" :key="n">{{ $t('Rst_Guide_Reports_' + n) }}</li></ul>
              </a-collapse-panel>

              <a-collapse-panel key="faq" :header="$t('Rst_Guide_Faq')">
                <dl class="faq">
                  <template v-for="n in 7" :key="n">
                    <dt>{{ $t('Rst_Guide_Faq_' + n + '_Q') }}</dt>
                    <dd>{{ $t('Rst_Guide_Faq_' + n + '_A') }}</dd>
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
 * Restaurant › Guide — GET restaurant/guide (any restaurant permission):
 * a live set-up checklist built from the tenant's own data, plus how the
 * module works, step by step.
 */
import { ref, computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { CheckOutlined, ExclamationOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';
import { useAuthStore } from '../../stores/auth';

const { t } = useI18n();
const auth = useAuthStore();
const loading = ref(true);
const data = ref(null);
const open = ref(['service']);

const PERM_LABELS = {
  rst_dashboard: 'Rst_Perm_Dashboard', rst_floor: 'Rst_Perm_Floor', rst_orders_take: 'Rst_Perm_Orders_Take', rst_orders_void: 'Rst_Perm_Orders_Void',
  rst_discounts: 'Rst_Perm_Discounts', rst_billing: 'Rst_Perm_Billing', rst_menu: 'Rst_Perm_Menu', rst_reservations: 'Rst_Perm_Reservations',
  rst_reports: 'Rst_Perm_Reports', rst_setup: 'Rst_Perm_Setup', rst_settings: 'Rst_Perm_Settings', rst_kitchen: 'Rst_Perm_Kitchen',
};
const ROLES = [
  { job: 'Waiter', perms: ['rst_floor', 'rst_orders_take'] },
  { job: 'Cook', perms: ['rst_kitchen'] },
  { job: 'Cashier', perms: ['rst_floor', 'rst_billing'] },
  { job: 'Host', perms: ['rst_floor', 'rst_reservations'] },
  { job: 'Supervisor', perms: ['rst_orders_void', 'rst_discounts', 'rst_dashboard'] },
  { job: 'Manager', perms: ['rst_menu', 'rst_setup', 'rst_settings', 'rst_reports'] },
];

function stateLabel(s) { return s === 'done' ? t('Rst_Guide_State_Done') : s === 'warn' ? t('Rst_Guide_State_Warn') : t('Rst_Guide_State_Todo'); }

const steps = computed(() => {
  const d = data.value;
  if (!d) return [];
  const m = d.migrations || {};
  const c = d.counts || {};
  const s = d.settings || {};
  const r = d.roles || {};
  const allMig = Object.values(m).every(Boolean);
  const list = [
    { key: 'db', state: allMig ? 'done' : 'warn', title: t('Rst_Guide_Step_Db'), help: allMig ? t('Rst_Guide_Step_Db_Ok') : t('Rst_Guide_Step_Db_Help') },
  ];
  if (m.settings) {
    list.push(
      { key: 'settings', state: s.default_warehouse ? 'done' : 'warn', title: t('Rst_Guide_Step_Settings'), help: s.default_warehouse ? t('Rst_Guide_Step_Settings_Ok', { types: (s.order_types || []).map(typeLabel).join(', ') }) : t('Rst_Guide_Step_Settings_Help'), link: '/restaurant/settings', linkText: t('Rst_Settings') },
      { key: 'floor', state: c.tables ? 'done' : 'todo', title: t('Rst_Guide_Step_Floor'), help: c.tables ? t('Rst_Guide_Step_Floor_Ok', { areas: c.areas, tables: c.tables }) : t('Rst_Guide_Step_Floor_Help'), link: '/restaurant/setup/floor', linkText: t('Rst_Floor_Setup') },
      { key: 'stations', state: c.stations ? 'done' : 'todo', title: t('Rst_Guide_Step_Stations'), help: c.stations ? t('Rst_Guide_Step_Stations_Ok', { n: c.stations }) : t('Rst_Guide_Step_Stations_Help'), link: '/restaurant/menu', linkText: t('Rst_Menu') },
      { key: 'dishes', state: c.dishes ? 'done' : 'todo', title: t('Rst_Guide_Step_Dishes'), help: c.dishes ? t('Rst_Guide_Step_Dishes_Ok', { n: c.dishes, groups: c.addon_groups }) : t('Rst_Guide_Step_Dishes_Help'), link: '/restaurant/menu', linkText: t('Rst_Menu') },
      { key: 'payments', state: c.payment_methods && c.cash_methods && d.default_client ? 'done' : 'warn', title: t('Rst_Guide_Step_Payments'), help: c.payment_methods && c.cash_methods && d.default_client ? t('Rst_Guide_Step_Payments_Ok', { n: c.payment_methods }) : t('Rst_Guide_Step_Payments_Help'), link: '/settings/payment-methods', linkText: t('Payment_Methods') },
      { key: 'staff', state: r.waiters && r.kitchen && r.cashiers ? 'done' : 'warn', title: t('Rst_Guide_Step_Staff'), help: t('Rst_Guide_Step_Staff_Help', { waiters: r.waiters || 0, kitchen: r.kitchen || 0, cashiers: r.cashiers || 0 }), link: '/permissions', linkText: t('GroupPermissions') },
      { key: 'first', state: c.bills ? 'done' : 'todo', title: t('Rst_Guide_Step_First'), help: c.bills ? t('Rst_Guide_Step_First_Ok', { n: c.bills }) : t('Rst_Guide_Step_First_Help'), link: '/restaurant/floor', linkText: t('Rst_Floor_Plan') },
      { key: 'qr', optional: true, state: s.qr_ordering_enabled && c.tables_with_qr ? 'done' : 'todo', title: t('Rst_Guide_Step_Qr'), help: s.qr_ordering_enabled ? t('Rst_Guide_Step_Qr_On', { n: c.tables_with_qr }) : t('Rst_Guide_Step_Qr_Help'), link: s.qr_ordering_enabled ? '/restaurant/setup/floor' : '/restaurant/settings', linkText: s.qr_ordering_enabled ? t('Rst_Table_Qr_Codes') : t('Rst_Settings') },
      { key: 'booking', optional: true, state: s.online_booking_enabled ? 'done' : 'todo', title: t('Rst_Guide_Step_Booking'), help: s.online_booking_enabled ? t('Rst_Guide_Step_Booking_On') : t('Rst_Guide_Step_Booking_Help'), link: '/restaurant/settings', linkText: t('Rst_Settings') },
      { key: 'store', optional: true, state: d.store && d.store.theme === 'restaurant' && d.store.enabled ? 'done' : 'todo', title: t('Rst_Guide_Step_Store'), help: d.store && d.store.theme === 'restaurant' ? t('Rst_Guide_Step_Store_On', { mode: kitchenMode(s.kitchen_online_orders) }) : t('Rst_Guide_Step_Store_Help') },
    );
  }
  let n = 0;
  return list.map(st => ({ ...st, n: ++n }));
});
const requiredCount = computed(() => steps.value.filter(s => !s.optional).length);
const doneCount = computed(() => steps.value.filter(s => !s.optional && s.state === 'done').length);
const donePct = computed(() => (requiredCount.value ? Math.round((doneCount.value / requiredCount.value) * 100) : 0));

function typeLabel(x) { return { dine_in: t('Rst_Dine_In'), takeaway: t('Rst_Takeaway'), delivery: t('Rst_Delivery') }[x] || x; }
function kitchenMode(x) { return { on_place: t('Rst_Kitchen_On_Place'), on_accept: t('Rst_Kitchen_On_Accept'), off: t('Rst_Kitchen_Off') }[x] || x; }

onMounted(async () => {
  try { data.value = await http.get('restaurant/guide'); } catch (e) { data.value = null; } finally { loading.value = false; }
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
