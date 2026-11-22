<template>
  <div class="page">
    <PageHeader
      :title="$t('Integrations')"
      :subtitle="$t('Int_Hub_Subtitle')"
    >
      <template #actions>
        <a-segmented
          v-model:value="filter"
          :options="[
            { value: 'all', label: $t('All') },
            { value: 'connected', label: $t('Connected') },
            { value: 'not', label: $t('Not_connected') },
          ]"
        />
      </template>
    </PageHeader>

    <section v-for="cat in categories" :key="cat.title">
      <div class="cat-head">
        <h4 class="cat-title">{{ catLabel(cat.title) }}</h4>
        <span class="cat-count">{{ cat.items.length === 1 ? $t('Int_N_Integration', { n: cat.items.length }) : $t('Int_N_Integrations', { n: cat.items.length }) }}</span>
      </div>
      <a-row :gutter="[16, 16]">
        <a-col v-for="c in cat.items" :key="c.key" :xs="24" :sm="12" :xl="8">
          <a-card size="small" :class="['int-card', { locked: c.locked }]">
            <div class="int-top">
              <a-avatar shape="square" :size="44" :style="avatarStyle(c)">
                <template #icon><component :is="c.icon" /></template>
              </a-avatar>
              <a-tag v-if="c.locked" color="warning"><LockOutlined /> {{ $t('Int_Not_In_Your_Plan') }}</a-tag>
              <a-tag v-else-if="c.status === 'loading'">{{ $t('Int_Checking') }}</a-tag>
              <a-tag v-else-if="c.status === 'on'" color="success">{{ $t('Connected') }}</a-tag>
              <a-tag v-else-if="c.status === 'unknown'">{{ $t('Int_Status_Unavailable') }}</a-tag>
              <a-tag v-else>{{ $t('Not_connected') }}</a-tag>
            </div>
            <div class="int-name">{{ c.name }}</div>
            <div class="int-desc">{{ c.desc }}</div>
            <div class="int-foot">
              <span class="int-detail">{{ c.detail }}</span>
              <a-button v-if="c.locked" size="small" type="primary" @click="upgrade">
                {{ $t('Upgrade_Plan') }}
              </a-button>
              <a-button v-else size="small" type="primary" ghost @click="configure(c)">
                {{ c.status === 'on' ? $t('Manage') : $t('Int_Configure') }} <RightOutlined />
              </a-button>
            </div>
          </a-card>
        </a-col>
      </a-row>
    </section>

    <template v-if="filter === 'all'">
      <div class="cat-head" style="margin-top: 28px">
        <h4 class="cat-title">{{ $t('Cr_Coming_Soon') }}</h4>
        <span class="cat-count">{{ $t('Int_On_The_Roadmap') }}</span>
      </div>
      <a-row :gutter="[12, 12]">
        <a-col v-for="s in comingSoon" :key="s.name" :xs="12" :sm="8" :lg="6">
          <a-card size="small" class="int-card soon">
            <div class="soon-body">
              <a-avatar shape="square" :size="36" :style="avatarStyle(s)">
                <template #icon><component :is="s.icon" /></template>
              </a-avatar>
              <div class="soon-text">
                <div class="soon-name">{{ s.name }}</div>
                <div class="soon-desc">{{ s.desc }}</div>
              </div>
            </div>
          </a-card>
        </a-col>
      </a-row>
    </template>
  </div>
</template>

<script setup>
/**
 * Integrations Hub — one card per connector, grouped by category. Each card
 * probes its own settings endpoint for a live "connected" state (in parallel,
 * failures degrade to "Status unavailable"). Cards the plan excludes render
 * locked with an upgrade CTA instead of probing (the server would 403 them
 * via tenant.feature middleware anyway); cards the user has no permission
 * for are hidden entirely, mirroring the sidebar gates.
 */
import { ref, computed, onMounted, markRaw } from 'vue';
import { useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import {
  ShoppingCartOutlined, ShopOutlined, BankOutlined, ThunderboltOutlined,
  WhatsAppOutlined, ApiOutlined, LockOutlined, RightOutlined, SlackOutlined,
  SendOutlined, TableOutlined, MailOutlined, CalculatorOutlined,
  ShoppingOutlined, GlobalOutlined, CarOutlined, PrinterOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';
import { useAuthStore } from '../../stores/auth';
import { usePlanStore } from '../../stores/plan';

const { t } = useI18n();
const auth = useAuthStore();
const plan = usePlanStore();
const router = useRouter();
const filter = ref('all');

const CAT_ECOMMERCE = 'Ecommerce Platforms';
const CAT_AUTOMATION = 'Automation & Messaging';
const CAT_ACCOUNTING = 'Accounting & Data';
const CAT_SHIPPING = 'Shipping & Couriers';

// Category ids stay English (used as keys/for matching); only the heading is translated.
function catLabel(cat) {
  if (cat === CAT_ECOMMERCE) return t('Int_Ecommerce_Platforms');
  if (cat === CAT_AUTOMATION) return t('Int_Automation_Messaging');
  if (cat === CAT_ACCOUNTING) return t('Int_Accounting_Data');
  if (cat === CAT_SHIPPING) return t('Int_Shipping_Couriers');
  return cat;
}

// targets: [permission, vue3 path] pairs — Configure goes to the first one
// the user holds, so e.g. a WhatsApp user with only template rights still
// lands somewhere the router guard lets them in.
const defs = [
  {
    key: 'woocommerce', name: 'WooCommerce', category: CAT_ECOMMERCE,
    color: '#96588a', icon: markRaw(ShoppingCartOutlined),
    desc: t('Int_Desc_Woocommerce'),
    perms: ['woocommerce_settings'], feature: 'woocommerce',
    targets: [['woocommerce_settings', '/woocommerce']],
    check: async () => {
      const d = await http.get('woocommerce/settings');
      const s = d && d.settings;
      return { on: !!(s && s.store_url && s.consumer_key && s.consumer_secret) };
    },
  },
  {
    key: 'shopify', name: 'Shopify', category: CAT_ECOMMERCE,
    color: '#5e8e3e', icon: markRaw(ShopOutlined),
    desc: t('Int_Desc_Shopify'),
    perms: ['shopify_stores', 'shopify_sync', 'shopify_logs'], feature: 'shopify',
    targets: [['shopify_stores', '/shopify/stores'], ['shopify_sync', '/shopify/sync'], ['shopify_logs', '/shopify/logs']],
    check: async () => {
      const d = await http.get('shopify/stores');
      const list = (d && (d.stores || d.data)) || (Array.isArray(d) ? d : []);
      const n = Array.isArray(list) ? list.length : 0;
      return { on: n > 0, detail: n ? (n > 1 ? t('Int_N_Stores_Connected', { n }) : t('Int_N_Store_Connected', { n })) : '' };
    },
  },
  {
    key: 'jumia', name: 'Jumia', category: CAT_ECOMMERCE,
    color: '#f68b1e', icon: markRaw(ShoppingOutlined),
    desc: t('Int_Desc_Jumia'),
    perms: ['jumia_settings'], feature: 'jumia',
    targets: [['jumia_settings', '/integrations/jumia']],
    check: async () => {
      const d = await http.get('jumia/settings');
      return { on: !!(d && d.configured && d.enabled) };
    },
  },
  {
    key: 'printful', name: 'Printful', category: CAT_ECOMMERCE,
    color: '#2b2b2b', icon: markRaw(PrinterOutlined),
    desc: t('Int_Desc_Printful'),
    perms: ['printful_settings'], feature: 'printful',
    targets: [['printful_settings', '/integrations/printful']],
    check: async () => {
      const d = await http.get('printful/settings');
      return { on: !!(d && d.configured && d.enabled && d.store_name), detail: (d && d.store_name) || '' };
    },
  },
  {
    key: 'zapier', name: 'Zapier', category: CAT_AUTOMATION,
    color: '#ff4a00', icon: markRaw(ThunderboltOutlined),
    desc: t('Int_Desc_Zapier'),
    perms: ['zapier_settings'], feature: 'zapier',
    targets: [['zapier_settings', '/settings/zapier']],
    check: async () => {
      const d = await http.get('zapier/settings');
      return {
        on: !!(d && d.enabled && d.has_key),
        detail: d && d.api_key_last4 ? t('Int_Api_Key_Last4', { last4: d.api_key_last4 }) : '',
      };
    },
  },
  {
    key: 'api', name: 'REST API', category: CAT_AUTOMATION,
    color: '#2f54eb', icon: markRaw(ApiOutlined),
    desc: t('Int_Desc_Api'),
    perms: ['api_keys'], feature: 'api',
    targets: [['api_keys', '/settings/api']],
    check: async () => {
      const d = await http.get('api-keys');
      const active = (d?.keys || []).filter(k => !k.revoked_at).length;
      return { on: active > 0, detail: active ? t('Int_Api_Active_Keys', { n: active }) : '' };
    },
  },
  {
    key: 'salla', name: 'Salla', category: CAT_ECOMMERCE,
    color: '#00a58e', icon: markRaw(ShoppingOutlined),
    desc: t('Int_Desc_Salla'),
    perms: ['salla_settings'], feature: 'salla',
    targets: [['salla_settings', '/integrations/salla']],
    check: async () => {
      const d = await http.get('salla/settings');
      return {
        on: !!(d && d.connected && d.enabled),
        detail: d && d.store && d.store.name ? d.store.name : '',
      };
    },
  },
  {
    key: 'prestashop', name: 'PrestaShop', category: CAT_ECOMMERCE,
    color: '#df0067', icon: markRaw(GlobalOutlined),
    desc: t('Int_Desc_Prestashop'),
    perms: ['prestashop_settings'], feature: 'prestashop',
    targets: [['prestashop_settings', '/integrations/prestashop']],
    check: async () => {
      const d = await http.get('prestashop/settings');
      return { on: !!(d && d.configured && d.enabled) };
    },
  },
  {
    key: 'opencart', name: 'OpenCart', category: CAT_ECOMMERCE,
    color: '#23a1d1', icon: markRaw(GlobalOutlined),
    desc: t('Int_Desc_Opencart'),
    perms: ['opencart_settings'], feature: 'opencart',
    targets: [['opencart_settings', '/integrations/opencart']],
    check: async () => {
      const d = await http.get('opencart/settings');
      return { on: !!(d && d.configured && d.enabled) };
    },
  },
  {
    key: 'mailchimp', name: 'Mailchimp', category: CAT_AUTOMATION,
    color: '#e2a400', icon: markRaw(MailOutlined),
    desc: t('Int_Desc_Mailchimp'),
    perms: ['mailchimp_settings'], feature: 'mailchimp',
    targets: [['mailchimp_settings', '/integrations/mailchimp']],
    check: async () => {
      const d = await http.get('mailchimp/settings');
      return {
        on: !!(d && d.ready && d.enabled),
        detail: d && d.list_name ? d.list_name : '',
      };
    },
  },
  {
    key: 'slack', name: 'Slack', category: CAT_AUTOMATION,
    color: '#611f69', icon: markRaw(SlackOutlined),
    desc: t('Int_Desc_Slack'),
    perms: ['slack_settings'], feature: 'slack',
    targets: [['slack_settings', '/integrations/slack']],
    check: async () => {
      const d = await http.get('slack/settings');
      return { on: !!(d && d.enabled && d.configured) };
    },
  },
  {
    key: 'telegram', name: 'Telegram', category: CAT_AUTOMATION,
    color: '#229ed9', icon: markRaw(SendOutlined),
    desc: t('Int_Desc_Telegram'),
    perms: ['telegram_settings'], feature: 'telegram',
    targets: [['telegram_settings', '/integrations/telegram']],
    check: async () => {
      const d = await http.get('telegram/settings');
      return { on: !!(d && d.enabled && d.configured) };
    },
  },
  {
    key: 'whatsapp', name: 'WhatsApp Business', category: CAT_AUTOMATION,
    color: '#25d366', icon: markRaw(WhatsAppOutlined),
    desc: t('Int_Desc_Whatsapp'),
    perms: ['whatsapp_settings', 'whatsapp_templates', 'whatsapp_logs'],
    targets: [
      ['whatsapp_settings', '/whatsapp/settings'],
      ['whatsapp_templates', '/whatsapp/templates'],
      ['whatsapp_logs', '/whatsapp/logs'],
    ],
    check: async () => {
      const d = await http.get('whatsapp/settings');
      return { on: !!(d && d.settings && d.settings.is_connected) };
    },
  },
  {
    key: 'webhooks', name: t('Webhooks'), category: CAT_AUTOMATION,
    color: '#6d28d9', icon: markRaw(ApiOutlined),
    desc: t('Int_Desc_Webhooks'),
    perms: ['webhooks_view'], feature: 'webhooks',
    targets: [['webhooks_view', '/settings/webhooks']],
    check: async () => {
      const d = await http.get('webhooks?limit=-1');
      const all = (d && d.webhooks) || [];
      const active = all.filter(w => w.is_active).length;
      return {
        on: active > 0,
        detail: all.length ? t('Int_N_Active_N_Total', { active, total: all.length }) : '',
      };
    },
  },
  {
    key: 'google_sheets', name: 'Google Sheets', category: CAT_ACCOUNTING,
    color: '#188038', icon: markRaw(TableOutlined),
    desc: t('Int_Desc_Google_Sheets'),
    perms: ['google_sheets_settings'], feature: 'google_sheets',
    targets: [['google_sheets_settings', '/integrations/google-sheets']],
    check: async () => {
      const d = await http.get('google-sheets/settings');
      return {
        on: !!(d && d.connected && d.enabled),
        detail: d && d.spreadsheet_id ? t('Int_Spreadsheet_Linked') : '',
      };
    },
  },
  {
    key: 'xero', name: 'Xero', category: CAT_ACCOUNTING,
    color: '#13b5ea', icon: markRaw(CalculatorOutlined),
    desc: t('Int_Desc_Xero'),
    perms: ['xero_settings'], feature: 'xero',
    targets: [['xero_settings', '/integrations/xero']],
    check: async () => {
      const d = await http.get('xero/settings');
      return {
        on: !!(d && d.connected && d.enabled),
        detail: d && d.tenant_name ? d.tenant_name : '',
      };
    },
  },
  {
    key: 'couriers', name: t('Int_Courier_Integration'), category: CAT_SHIPPING,
    color: '#e47911', icon: markRaw(CarOutlined),
    desc: t('Int_Desc_Couriers'),
    perms: ['courier_settings'], feature: 'couriers',
    targets: [['courier_settings', '/couriers/accounts']],
    check: async () => {
      const d = await http.get('couriers/accounts');
      const active = ((d && d.accounts) || []).filter(a => a.active).length;
      return { on: active > 0, detail: active ? (active === 1 ? t('Int_N_Active_Account', { n: active }) : t('Int_N_Active_Accounts', { n: active })) : '' };
    },
  },
  {
    key: 'quickbooks', name: 'QuickBooks', category: CAT_ACCOUNTING,
    color: '#2ca01c', icon: markRaw(BankOutlined),
    desc: t('Int_Desc_Quickbooks'),
    perms: ['quickbooks_settings'], feature: 'quickbooks',
    targets: [['quickbooks_settings', '/settings/quickbooks']],
    check: async () => {
      const d = await http.get('quickbooks/status');
      return { on: !!(d && d.has_token) };
    },
  },
];

const cards = ref(
  defs
    .filter(d => d.perms.some(p => auth.can(p)))
    .map(d => ({
      ...d,
      locked: d.feature ? !plan.featureEnabled(d.feature) : false,
      status: 'loading',
      detail: '',
    }))
);

const comingSoon = [
  { name: 'Meta Commerce', desc: t('Int_Soon_Meta_Desc'), color: '#0866ff', icon: markRaw(ShopOutlined) },
  { name: 'TikTok Shop', desc: t('Int_Soon_Social_Commerce'), color: '#fe2c55', icon: markRaw(ShoppingCartOutlined) },
];

function matches(c) {
  if (filter.value === 'connected') return c.status === 'on';
  if (filter.value === 'not') return c.locked || c.status === 'off' || c.status === 'unknown';
  return true;
}

const categories = computed(() =>
  [CAT_ECOMMERCE, CAT_SHIPPING, CAT_AUTOMATION, CAT_ACCOUNTING]
    .map(title => ({ title, items: cards.value.filter(c => c.category === title && matches(c)) }))
    .filter(cat => cat.items.length)
);

function tint(hex, alpha = 0.14) {
  const n = parseInt(hex.replace('#', ''), 16);
  return `rgba(${(n >> 16) & 255}, ${(n >> 8) & 255}, ${n & 255}, ${alpha})`;
}
function avatarStyle(c) {
  return { background: tint(c.color), color: c.color };
}

function configure(c) {
  const target = c.targets.find(([p]) => auth.can(p));
  if (target) router.push(target[1]);
}
function upgrade() {
  router.push('/subscription/change-plan');
}

onMounted(() => {
  for (const c of cards.value) {
    if (c.locked) continue;
    c.check()
      .then(r => { c.status = r.on ? 'on' : 'off'; c.detail = r.detail || ''; })
      .catch(() => { c.status = 'unknown'; });
  }
});
</script>

<style scoped>
.cat-head {
  display: flex;
  align-items: baseline;
  gap: 10px;
  margin: 20px 0 12px;
}
.cat-title {
  margin: 0;
  font-size: 15px;
  font-weight: 600;
}
.cat-count {
  font-size: 12px;
  opacity: 0.55;
}
.int-card {
  height: 100%;
  transition: box-shadow 0.18s ease, transform 0.18s ease;
}
.int-card:hover {
  transform: translateY(-2px);
  box-shadow: 0 8px 20px rgba(0, 0, 0, 0.1);
}
.int-card.locked {
  opacity: 0.8;
}
.int-top {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  margin-bottom: 12px;
}
.int-name {
  font-weight: 600;
  font-size: 15px;
  margin-bottom: 4px;
}
.int-desc {
  font-size: 12.5px;
  line-height: 1.5;
  opacity: 0.65;
  min-height: 38px;
}
.int-foot {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  margin-top: 14px;
  padding-top: 12px;
  border-top: 1px solid rgba(128, 128, 128, 0.15);
}
.int-detail {
  font-size: 12px;
  opacity: 0.6;
}
.int-card.soon :deep(.ant-card-body) {
  padding: 10px 12px;
}
.soon {
  border-style: dashed;
}
.soon-body {
  display: flex;
  align-items: center;
  gap: 10px;
  min-width: 0;
}
.soon-text {
  min-width: 0;
}
.soon-name {
  font-weight: 600;
  font-size: 13px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.soon-desc {
  font-size: 11px;
  opacity: 0.55;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
</style>
