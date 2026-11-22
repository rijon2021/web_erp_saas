<template>
  <div class="page">
    <PageHeader :title="tf('Addons_Title', 'Add-ons')" :breadcrumb="[tf('Billing', 'Billing'), tf('Addons_Title', 'Add-ons')]" />

    <a-skeleton v-if="loading" active :paragraph="{ rows: 8 }" />

    <a-alert v-else-if="!ready" type="info" show-icon :message="tf('Addons_Not_Available', 'Add-ons are not available yet.')" />

    <a-card v-else-if="!hasPlan" style="text-align: center; padding: 24px 0">
      <AppstoreOutlined style="font-size: 40px; opacity: 0.4" />
      <h3 style="margin-top: 12px">{{ tf('Addons_Need_Plan', 'You need an active plan before buying add-ons.') }}</h3>
      <a-button type="primary" @click="$router.push('/subscription/change-plan')">{{ tf('Choose_a_Plan', 'Choose a Plan') }}</a-button>
    </a-card>

    <template v-else>
      <a-alert type="info" show-icon style="margin-bottom: 16px" :message="tf('Addons_Intro', 'Add a module or a premium store theme your plan does not include. It is unlocked as soon as the payment is confirmed.')">
        <template v-if="plan && plan.on_trial" #description>{{ tf('Addons_Trial_Note', 'You are on a trial: add-ons are charged in full and run for their own period.') }}</template>
      </a-alert>

      <div class="addon-toolbar">
        <a-segmented v-model:value="filter" :options="filterOptions" />
        <a-input-search v-model:value="search" :placeholder="tf('Search', 'Search')" allow-clear style="max-width: 260px" />
      </div>

      <a-empty v-if="!filtered.length" :description="tf('Addons_None', 'No add-ons are offered for your plan right now.')" style="margin-top: 40px" />

      <a-row :gutter="[16, 16]">
        <a-col v-for="a in filtered" :key="a.key" :xs="24" :md="12" :xl="8">
          <a-card :id="'addon-' + a.key" class="addon-card" :class="{ 'addon-card--focus': focus === a.key }" size="small">
            <div class="addon-head">
              <span class="addon-icon">
                <CrownOutlined v-if="a.type === 'theme'" /><PlusSquareOutlined v-else-if="a.type === 'limit'" /><AppstoreOutlined v-else />
              </span>
              <div class="addon-title">
                <strong>{{ a.name }}</strong>
                <div>
                  <a-tag v-if="a.state === 'included'" color="success">{{ tf('Addons_Included', 'Included in your plan') }}</a-tag>
                  <a-tag v-else-if="a.state === 'active'" color="processing">{{ tf('Active', 'Active') }}</a-tag>
                  <a-tag v-else-if="a.state === 'trial'" color="purple">{{ tf('Addons_On_Trial', 'Free trial') }}</a-tag>
                  <a-tag v-else-if="a.state === 'pending'" color="warning">{{ tf('Addons_Awaiting_Payment', 'Awaiting payment') }}</a-tag>
                  <a-tag v-else-if="a.expired" color="error">{{ tf('Expired', 'Expired') }}</a-tag>
                  <a-tag v-if="a.type === 'theme'" color="gold">{{ tf('Addons_Theme', 'Store theme') }}</a-tag>
                  <a-tag v-if="a.type === 'limit'" color="cyan">{{ tf('Addons_Limit_Pack', 'Limit pack') }}</a-tag>
                </div>
              </div>
            </div>
            <p v-if="a.description" class="addon-desc">{{ a.description }}</p>

            <!-- Requirements -->
            <p v-if="a.requires && a.requires.length" class="addon-requires">
              {{ tf('Addons_Requires', 'Requires') }}:
              <a-tag v-for="r in a.requires" :key="r.key" :color="r.met ? 'success' : 'default'">
                <CheckOutlined v-if="r.met" /> {{ r.name }}
              </a-tag>
            </p>

            <!-- Limit pack: what is owned -->
            <template v-if="a.pack">
              <p class="muted addon-line">
                {{ tf('Addons_Pack_Adds', 'Each pack adds') }} <strong>{{ a.pack.size }}</strong>
                <template v-if="a.pack.base !== null && a.pack.base >= 0"> · {{ tf('Addons_Plan_Limit', 'Plan limit') }}: {{ a.pack.base }}</template>
                <template v-if="a.pack.owned_units"> · {{ tf('Addons_Extra_Now', 'Extra now') }}: <strong>+{{ a.pack.owned_units }}</strong></template>
              </p>
              <div v-for="p in a.pack.owned" :key="p.id" class="addon-pack-row">
                <span>+{{ p.units }} · {{ cycleName(p.billing_cycle) }}<template v-if="p.ends_at"> · {{ tf('Addons_Until', 'until') }} {{ fmtDate(p.ends_at) }}</template></span>
                <a-tag v-if="p.with_plan" color="blue">{{ tf('Addons_With_Plan', 'Renews with your plan') }}</a-tag>
                <a-button v-else-if="p.renewable" size="small" :loading="busy === 'renew-' + p.id" @click="renew(p.id, 'renew-' + p.id)">{{ tf('Addons_Renew', 'Renew now') }}</a-button>
              </div>
            </template>

            <!-- Owned module / theme -->
            <template v-if="a.state === 'active' && a.active">
              <p class="muted addon-line">
                {{ a.active.ends_at ? tf('Addons_Active_Until', 'Active until') + ' ' + fmtDate(a.active.ends_at) : tf('Addons_No_End', 'No end date') }}
                <template v-if="a.active.billing_cycle !== 'manual'"> · {{ cycleName(a.active.billing_cycle) }}</template>
              </p>
              <p v-if="a.active.with_plan" class="addon-with-plan"><SyncOutlined /> {{ tf('Addons_With_Plan_Hint', 'Renews together with your plan, on the same payment.') }}</p>
              <a-button v-else-if="a.active.renewable" :loading="busy === a.key" @click="renew(a.active.id, a.key)">
                {{ tf('Addons_Renew', 'Renew now') }}
              </a-button>
            </template>

            <template v-else-if="a.state === 'included'">
              <p class="muted addon-line">{{ tf('Addons_Included_Hint', 'Nothing to buy — your plan already has it.') }}</p>
            </template>

            <template v-else-if="a.state === 'trial' && a.trial_active">
              <p class="muted addon-line">{{ tf('Addons_Trial_Until', 'Free trial until') }} {{ fmtDate(a.trial_active.ends_at) }} — {{ tf('Addons_Trial_Keep', 'buy it to keep it.') }}</p>
            </template>

            <!-- Waiting for payment -->
            <template v-else-if="a.state === 'pending' && a.pending">
              <p class="muted addon-line">
                {{ cycleName(a.pending.billing_cycle) }}<template v-if="a.pending.quantity > 1"> × {{ a.pending.quantity }}</template> · {{ money(a.pending.amount) }}
              </p>
              <a-space wrap>
                <a-button v-if="a.pending.pay_url" type="primary" :href="a.pending.pay_url">{{ tf('Addons_Complete_Payment', 'Complete payment') }}</a-button>
                <a-button @click="startOver(a)">{{ tf('Addons_Change_Option', 'Choose another option') }}</a-button>
              </a-space>
            </template>

            <!-- Lapsed: renew the same option -->
            <template v-if="a.state === 'available' && a.expired && a.expired.renewable">
              <p class="muted addon-line">{{ tf('Addons_Ended_On', 'Ended on') }} {{ fmtDate(a.expired.ended_at) }}</p>
              <a-button :loading="busy === 'renew-' + a.expired.id" style="margin-bottom: 10px" @click="renew(a.expired.id, 'renew-' + a.expired.id)">
                {{ tf('Addons_Renew', 'Renew now') }}
              </a-button>
            </template>

            <!-- For sale (and buy during a trial) -->
            <template v-if="canBuy(a)">
              <a-radio-group v-model:value="choice[a.key]" class="addon-options">
                <a-radio v-for="p in a.prices" :key="p.cycle" :value="p.cycle" class="addon-option">
                  <strong>{{ money(p.price) }}</strong> <span class="muted">{{ perLabel(p.cycle) }}<template v-if="a.pack"> {{ tf('Addons_Per_Pack', 'per pack') }}</template></span>
                  <div v-if="p.prorated" class="addon-prorated">
                    {{ tf('Addons_First_Payment', 'First payment') }} {{ money(dueFor(a, p)) }} {{ tf('Addons_Until', 'until') }} {{ fmtDate(p.ends_at) }}
                    — {{ tf('Addons_Aligned', 'renews with your plan') }}
                  </div>
                </a-radio>
              </a-radio-group>
              <div v-if="a.pack" class="addon-qty">
                <span class="muted">{{ tf('Addons_Packs', 'Packs') }}</span>
                <a-input-number v-model:value="quantity[a.key]" :min="1" :max="a.pack.max" size="small" />
                <span class="muted">= +{{ qty(a) * a.pack.size }}</span>
              </div>
              <a-space direction="vertical" style="width: 100%">
                <a-button type="primary" block :disabled="!choice[a.key] || a.blocked" :loading="busy === a.key" @click="buy(a)">
                  {{ tf('Addons_Buy', 'Buy') }}<template v-if="selectedPrice(a)"> — {{ money(dueFor(a, selectedPrice(a))) }}</template>
                </a-button>
                <a-button v-if="a.trial && a.state === 'available'" block :disabled="a.blocked" :loading="busy === 'trial-' + a.key" @click="startTrial(a)">
                  {{ tf('Addons_Try_Free', 'Try free for') }} {{ a.trial.days }} {{ tf('days', 'days') }}
                </a-button>
              </a-space>
            </template>
          </a-card>
        </a-col>
      </a-row>
    </template>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, nextTick } from 'vue';
import { useRoute } from 'vue-router';
import { message } from 'ant-design-vue';
import { AppstoreOutlined, CrownOutlined, PlusSquareOutlined, CheckOutlined, SyncOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';
import { t as tf } from '../../i18n';
import { useBillingFormat } from './billingFormat';

const route = useRoute();
const { currencySymbol, fmtDate } = useBillingFormat();

const loading = ref(true);
const ready = ref(true);
const hasPlan = ref(false);
const plan = ref(null);
const addons = ref([]);
const filter = ref('all');
const search = ref('');
const choice = ref({});
const quantity = ref({});
const choosing = ref({});
const busy = ref(null);
const focus = ref(String(route.query.feature || ''));

const filterOptions = computed(() => [
  { label: tf('All', 'All'), value: 'all' },
  { label: tf('Addons_Modules', 'Modules'), value: 'feature' },
  { label: tf('Addons_Themes', 'Themes'), value: 'theme' },
  { label: tf('Addons_Limits', 'Limits'), value: 'limit' },
  { label: tf('Addons_Mine', 'Mine'), value: 'mine' },
]);

const isMine = a => ['active', 'pending', 'trial'].includes(a.state) || !!(a.pack && a.pack.owned_units);

const filtered = computed(() => {
  const q = search.value.trim().toLowerCase();
  return addons.value.filter((a) => {
    if (filter.value === 'mine' && !isMine(a)) return false;
    if (['feature', 'theme', 'limit'].includes(filter.value) && a.type !== filter.value) return false;
    return !q || a.name.toLowerCase().includes(q) || (a.description || '').toLowerCase().includes(q);
  });
});

const money = amount => `${currencySymbol.value}${(Number(amount) || 0).toFixed(2)}`;
const cycleName = c => ({
  monthly: tf('Monthly', 'Monthly'),
  yearly: tf('Yearly', 'Yearly'),
  lifetime: tf('Addons_One_Time', 'One-time'),
  manual: tf('Addons_Granted', 'Granted'),
  trial: tf('Addons_On_Trial', 'Free trial'),
}[c] || c);
const perLabel = c => ({
  monthly: tf('Addons_Per_Month', '/ month'),
  yearly: tf('Addons_Per_Year', '/ year'),
  lifetime: tf('Addons_One_Time', 'One-time'),
}[c] || '');
const selectedPrice = a => (a.prices || []).find(p => p.cycle === choice.value[a.key]);
const qty = a => (a.pack ? Math.max(1, Number(quantity.value[a.key]) || 1) : 1);
// What the first payment will be for the chosen number of packs — the same
// rule as AddonService::prorate (prorated, never below 1 unit or the price).
const dueFor = (a, p) => {
  const full = (Number(p.price) || 0) * qty(a);
  if (!p.prorated) return full;
  return Math.max(Math.round(full * (Number(p.ratio) || 1) * 100) / 100, Math.min(full, 1));
};
// Buy options show for sale items, during a free trial, and when changing a pending option.
const canBuy = a => a.state === 'available' || a.state === 'trial' || (a.state === 'pending' && choosing.value[a.key]);

async function load() {
  loading.value = true;
  try {
    const data = await http.get('billing/addons');
    ready.value = !!data.ready;
    hasPlan.value = !!data.has_plan;
    plan.value = data.plan;
    addons.value = data.addons || [];
    if (addons.value.length) currencySymbol.value = addons.value[0].symbol || '$';
    // Pre-select the plan's own cycle when offered, else the first option.
    const planCycle = data.plan && data.plan.billing_cycle;
    const next = {};
    const qtys = {};
    for (const a of addons.value) {
      const cycles = (a.prices || []).map(p => p.cycle);
      next[a.key] = cycles.includes(planCycle) ? planCycle : cycles[0];
      qtys[a.key] = 1;
    }
    choice.value = next;
    quantity.value = qtys;
  } catch (e) {
    message.error(e?.data?.message || tf('Addons_Load_Failed', 'Could not load add-ons.'));
  } finally {
    loading.value = false;
  }
  if (focus.value) {
    await nextTick();
    document.getElementById('addon-' + focus.value)?.scrollIntoView({ behavior: 'smooth', block: 'center' });
  }
}

async function buy(a) {
  busy.value = a.key;
  try {
    const data = await http.post('billing/addons/purchase', { key: a.key, cycle: choice.value[a.key], quantity: qty(a) });
    // The hosted payment page (all gateways + bank transfer) — the add-on
    // switches on as soon as that payment is confirmed.
    window.location.href = data.url;
  } catch (e) {
    message.error(e?.data?.message || tf('Addons_Buy_Failed', 'Could not start the purchase.'));
    busy.value = null;
  }
}

async function startTrial(a) {
  busy.value = 'trial-' + a.key;
  try {
    await http.post('billing/addons/trial', { key: a.key });
    message.success(tf('Addons_Trial_Started', 'Your free trial has started. Reload the page to see it in the menu.'));
    // A full reload refreshes the plan summary, so the sidebar unlocks it.
    window.location.reload();
  } catch (e) {
    message.error(e?.data?.message || tf('Addons_Buy_Failed', 'Could not start the purchase.'));
    busy.value = null;
  }
}

async function renew(id, busyKey) {
  busy.value = busyKey;
  try {
    const data = await http.post(`billing/addons/${id}/renew`, {});
    window.location.href = data.url;
  } catch (e) {
    message.error(e?.data?.message || tf('Addons_Buy_Failed', 'Could not start the purchase.'));
    busy.value = null;
  }
}

function startOver(a) {
  choosing.value = { ...choosing.value, [a.key]: true };
}

onMounted(load);
</script>

<style scoped>
.addon-toolbar { display: flex; flex-wrap: wrap; gap: 12px; justify-content: space-between; margin-bottom: 16px; }
.addon-card { height: 100%; transition: box-shadow 0.2s; }
.addon-card--focus { box-shadow: 0 0 0 2px var(--app-primary, #6d28d9); }
.addon-head { display: flex; gap: 12px; align-items: flex-start; margin-bottom: 8px; }
.addon-icon {
  width: 36px; height: 36px; border-radius: 10px; flex-shrink: 0;
  display: inline-flex; align-items: center; justify-content: center; font-size: 18px;
  color: var(--app-primary, #6d28d9); background: rgba(var(--app-primary-rgb, 109, 40, 217), 0.1);
}
.addon-title { display: flex; flex-direction: column; gap: 4px; }
.addon-desc { color: rgba(var(--ink-rgb), 0.65); font-size: 13px; margin-bottom: 12px; }
.addon-requires { font-size: 12px; margin-bottom: 10px; color: rgba(var(--ink-rgb), 0.65); }
.addon-line { margin-bottom: 10px; }
.addon-with-plan { font-size: 12px; color: rgba(var(--ink-rgb), 0.65); margin-bottom: 10px; }
.addon-pack-row { display: flex; align-items: center; justify-content: space-between; gap: 8px; font-size: 12px; margin-bottom: 6px; }
.addon-qty { display: flex; align-items: center; gap: 8px; margin-bottom: 12px; }
.muted { color: rgba(var(--ink-rgb), 0.45); }
.addon-options { display: flex; flex-direction: column; gap: 8px; margin-bottom: 12px; width: 100%; }
.addon-option { white-space: normal; }
.addon-prorated { font-size: 12px; color: rgba(var(--ink-rgb), 0.55); margin-top: 2px; }
</style>
