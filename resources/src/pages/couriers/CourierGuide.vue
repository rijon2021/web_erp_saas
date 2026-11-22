<template>
  <div class="page">
    <PageHeader :title="$t('Cr_Courier_Guide')" :breadcrumb="[$t('Cr_Couriers'), $t('Cr_Courier_Guide')]">
      <template #actions>
        <a-button v-if="data.can_settings" @click="$router.push('/couriers/accounts')">{{ $t('Cr_Courier_Accounts') }}</a-button>
        <a-button v-if="data.can_settings" @click="$router.push('/couriers/settings')">{{ $t('Cr_Courier_Settings') }}</a-button>
        <a-button v-if="data.can_settings" @click="$router.push('/couriers/logs')">{{ $t('Cr_Courier_Logs') }}</a-button>
      </template>
    </PageHeader>

    <a-spin :spinning="loading">
      <a-row :gutter="[16, 16]">
        <!-- Live checklist -->
        <a-col :xs="24" :xl="10">
          <a-card size="small" :title="$t('Cr_Guide_Your_Setup')" class="fill">
            <p class="muted">{{ $t('Cr_Guide_Your_Setup_Help') }}</p>
            <ol class="checklist">
              <li v-for="step in steps" :key="step.key" :class="'is-' + step.state">
                <span class="dot">
                  <CheckOutlined v-if="step.state === 'done'" />
                  <ExclamationOutlined v-else-if="step.state === 'warn'" />
                  <span v-else>{{ step.n }}</span>
                </span>
                <div class="step-body">
                  <div class="step-title">{{ step.title }}<a-tag v-if="step.optional" class="opt">{{ $t('Cr_Guide_Optional') }}</a-tag></div>
                  <div class="muted step-help">{{ step.help }}</div>
                  <a v-if="step.link" class="step-link" @click="$router.push(step.link)">{{ step.linkText }} →</a>
                </div>
              </li>
            </ol>
          </a-card>
        </a-col>

        <!-- Courier explorer -->
        <a-col :xs="24" :xl="14">
          <a-card size="small" :title="$t('Cr_Guide_Courier_Setup')" class="fill">
            <a-select
              v-model:value="selected" show-search option-filter-prop="label" style="width: 100%"
              :placeholder="$t('Cr_Guide_Pick_Courier')" :options="courierOptions"
            />
            <div v-if="courier" class="courier">
              <div class="courier-head">
                <div>
                  <h3>{{ courier.name }}</h3>
                  <div class="muted">{{ courier.region }}<template v-if="courier.website"> · <a :href="courier.website" target="_blank" rel="noopener">{{ courier.website.replace(/^https?:\/\//, '') }}</a></template></div>
                </div>
                <a-button v-if="data.can_settings" type="primary" @click="$router.push({ path: '/couriers/accounts', query: { courier: courier.code } })">
                  <PlusOutlined /> {{ $t('Cr_Guide_Add_This') }}
                </a-button>
              </div>
              <p>{{ courier.description }}</p>
              <div class="caps">
                <a-tag v-for="c in courier.capabilities" :key="c" color="blue">{{ $t('Cr_Cap_' + c) }}</a-tag>
              </div>

              <div class="section-label">{{ $t('Cr_Guide_You_Need') }}</div>
              <ul v-if="courier.credentials.length" class="fields">
                <li v-for="f in courier.credentials" :key="f.label">
                  <strong>{{ f.label }}</strong><span v-if="f.required" class="req">*</span>
                  <div v-if="f.help" class="muted">{{ f.help }}</div>
                </li>
              </ul>
              <p v-else class="muted">{{ $t('Cr_Guide_No_Keys') }}</p>

              <template v-if="courier.settings.length">
                <div class="section-label">{{ $t('Cr_Courier_Options') }}</div>
                <ul class="fields">
                  <li v-for="f in courier.settings" :key="f.label">
                    <strong>{{ f.label }}</strong><span v-if="f.required" class="req">*</span>
                    <div v-if="f.help" class="muted">{{ f.help }}</div>
                  </li>
                </ul>
              </template>

              <div class="section-label">{{ $t('Cr_Status_Updates') }}</div>
              <div class="hook">
                <a-tag :color="HOOK_COLORS[courier.webhook.mode]">{{ $t('Cr_Hook_' + courier.webhook.mode) }}</a-tag>
                <span>{{ courier.webhook.text }}</span>
              </div>

              <template v-if="courier.tips.length">
                <div class="section-label">{{ $t('Cr_Guide_Good_To_Know') }}</div>
                <ul class="tips">
                  <li v-for="tip in courier.tips" :key="tip">{{ tip }}</li>
                </ul>
              </template>
            </div>
          </a-card>
        </a-col>

        <!-- Webhook addresses -->
        <a-col v-if="data.can_settings && webhookAccounts.length" :xs="24">
          <a-card size="small" :title="$t('Cr_Guide_Webhooks')">
            <p class="muted">{{ $t('Cr_Webhook_URL_Help') }}</p>
            <a-table :data-source="webhookAccounts" :columns="hookColumns" row-key="id" size="small" :pagination="false" :scroll="{ x: 800 }">
              <template #bodyCell="{ column, record }">
                <template v-if="column.key === 'account'">
                  <strong>{{ record.name }}</strong>
                  <div class="muted">{{ record.courier_name }}</div>
                </template>
                <template v-else-if="column.key === 'how'">
                  <a-tag :color="HOOK_COLORS[record.webhook.mode]">{{ $t('Cr_Hook_' + record.webhook.mode) }}</a-tag>
                  <div class="muted small">{{ record.webhook.text }}</div>
                </template>
                <template v-else-if="column.key === 'url'">
                  <div class="url-cell">
                    <code>{{ record.webhook_url }}</code>
                    <a @click="copy(record.webhook_url)"><CopyOutlined /></a>
                  </div>
                </template>
              </template>
            </a-table>
          </a-card>
        </a-col>

        <!-- How it works -->
        <a-col :xs="24">
          <a-card size="small" :title="$t('Cr_Guide_How_It_Works')">
            <a-collapse v-model:active-key="openTopics" ghost>
              <a-collapse-panel key="statuses" :header="$t('Cr_Guide_T_Statuses')">
                <a-table :data-source="statusRows" :columns="statusColumns" row-key="value" size="small" :pagination="false">
                  <template #bodyCell="{ column, record }">
                    <template v-if="column.key === 'status'"><a-tag :color="record.color">{{ $t(record.key) }}</a-tag></template>
                  </template>
                </a-table>
                <p class="muted topic-note">{{ $t('Cr_Guide_Statuses_Note') }}</p>
              </a-collapse-panel>
              <a-collapse-panel v-for="topic in TOPICS" :key="topic" :header="$t('Cr_Guide_T_' + topic)">
                <p class="topic">{{ $t('Cr_Guide_B_' + topic) }}</p>
              </a-collapse-panel>
              <a-collapse-panel key="faq" :header="$t('Cr_Guide_T_Faq')">
                <dl class="faq">
                  <template v-for="q in FAQ" :key="q">
                    <dt>{{ $t('Cr_Guide_Q_' + q) }}</dt>
                    <dd>{{ $t('Cr_Guide_A_' + q) }}</dd>
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
 * Couriers → Guide — GET couriers/guide → {checklist, accounts[], couriers[],
 * can_settings}. A live setup checklist, a per-courier setup explorer (keys,
 * options, how status updates arrive, gotchas), this workspace's webhook
 * addresses and the module explained.
 */
import { ref, computed, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { CheckOutlined, ExclamationOutlined, PlusOutlined, CopyOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';
import { COURIER_STATUSES, errorText } from './courierShared';

const { t } = useI18n();

const HOOK_COLORS = { panel: 'blue', request: 'purple', auto: 'green', paid: 'orange', none: 'default' };
const TOPICS = ['Booking', 'Cod', 'Returns', 'Customers', 'Labels'];
const FAQ = ['Test', 'City', 'Webhook', 'Stuck', 'Label', 'Cod'];
const STATUS_MEANING = {
  booked: 'Cr_Guide_S_booked', picked_up: 'Cr_Guide_S_picked_up', in_transit: 'Cr_Guide_S_in_transit',
  out_for_delivery: 'Cr_Guide_S_out_for_delivery', failed_attempt: 'Cr_Guide_S_failed_attempt', delivered: 'Cr_Guide_S_delivered',
  returning: 'Cr_Guide_S_returning', returned: 'Cr_Guide_S_returned', cancelled: 'Cr_Guide_S_cancelled',
};

const loading = ref(true);
const data = ref({ checklist: {}, accounts: [], couriers: [], can_settings: false });
const selected = ref(undefined);
const openTopics = ref(['statuses']);

const courierOptions = computed(() => data.value.couriers.map(c => ({ value: c.code, label: `${c.name} — ${c.region}` })));
const courier = computed(() => data.value.couriers.find(c => c.code === selected.value) || null);
const webhookAccounts = computed(() => data.value.accounts.filter(a => a.webhook_url));

const steps = computed(() => {
  const c = data.value.checklist || {};
  const list = [
    { key: 'account', state: c.accounts ? 'done' : 'todo', title: t('Cr_Guide_Step_Account'), help: t('Cr_Guide_Step_Account_Help'),
      link: c.accounts ? null : '/couriers/accounts?new=1', linkText: t('Cr_Add_Account') },
    { key: 'test', state: c.connected ? 'done' : (c.accounts ? 'warn' : 'todo'), title: t('Cr_Guide_Step_Test'), help: t('Cr_Guide_Step_Test_Help'),
      link: c.accounts && !c.connected ? '/couriers/accounts' : null, linkText: t('Cr_Courier_Accounts') },
    { key: 'webhook', state: 'todo', title: t('Cr_Guide_Step_Webhook'), help: t('Cr_Guide_Step_Webhook_Help') },
    { key: 'cod', state: c.cod_account ? 'done' : 'todo', title: t('Cr_Guide_Step_Cod'), help: t('Cr_Guide_Step_Cod_Help'),
      link: c.cod_account ? null : '/couriers/settings', linkText: t('Cr_Courier_Settings') },
    { key: 'notify', state: c.notifications ? 'done' : 'todo', optional: true, title: t('Cr_Guide_Step_Notify'), help: t('Cr_Guide_Step_Notify_Help'),
      link: c.notifications ? null : '/couriers/settings', linkText: t('Cr_Courier_Settings') },
    { key: 'book', state: c.shipments ? 'done' : 'todo', title: t('Cr_Guide_Step_Book'), help: t('Cr_Guide_Step_Book_Help'),
      link: c.shipments ? '/couriers/shipments' : '/sales', linkText: c.shipments ? t('Cr_Courier_Shipments') : t('Sales') },
    { key: 'errors', state: c.errors_24h ? 'warn' : 'done', title: t('Cr_Guide_Step_Errors'),
      help: c.errors_24h ? t('Cr_Logs_Errors_24h', { n: c.errors_24h }) : t('Cr_Guide_Step_Errors_Help'),
      link: data.value.can_settings ? '/couriers/logs' + (c.errors_24h ? '?level=error' : '') : null, linkText: t('Cr_Courier_Logs') },
  ];
  // Users without settings access cannot follow the setup links.
  return list.map((s, i) => ({ ...s, n: i + 1, link: data.value.can_settings || s.key === 'book' ? s.link : null }));
});

const statusRows = computed(() => COURIER_STATUSES.map(s => ({ ...s, meaning: t(STATUS_MEANING[s.value]) })));
const statusColumns = computed(() => [
  { title: t('Status'), key: 'status', width: 200 },
  { title: t('Cr_Guide_Meaning'), dataIndex: 'meaning', key: 'meaning' },
]);
const hookColumns = computed(() => [
  { title: t('Cr_Courier_Account'), key: 'account', width: 220 },
  { title: t('Cr_Guide_How'), key: 'how', width: 340 },
  { title: t('Cr_Webhook_URL'), key: 'url' },
]);

function copy(text) {
  try {
    navigator.clipboard.writeText(text);
    message.success(t('Cr_Copied'));
  } catch (e) {
    /* the address stays selectable */
  }
}

onMounted(async () => {
  try {
    data.value = await http.get('couriers/guide');
    // Start on the courier this workspace uses, else nothing picked.
    const first = data.value.accounts.find(a => a.courier !== 'manual') || data.value.accounts[0];
    if (first) selected.value = first.courier;
  } catch (e) {
    message.error(errorText(e, t('Failed')));
  } finally {
    loading.value = false;
  }
});
</script>

<style scoped>
.muted {
  color: rgba(var(--ink-rgb), 0.5);
}
.small {
  font-size: 12px;
}
.fill {
  height: 100%;
}
.checklist {
  list-style: none;
  margin: 0;
  padding: 0;
}
.checklist li {
  display: flex;
  gap: 12px;
  padding: 10px 0;
  border-bottom: 1px solid rgba(128, 128, 128, 0.12);
}
.checklist li:last-child {
  border-bottom: 0;
}
.dot {
  flex: 0 0 26px;
  height: 26px;
  border-radius: 50%;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-size: 12px;
  font-weight: 600;
  background: rgba(128, 128, 128, 0.16);
}
.is-done .dot {
  background: rgba(82, 196, 26, 0.18);
  color: #389e0d;
}
.is-warn .dot {
  background: rgba(250, 173, 20, 0.2);
  color: #d48806;
}
.step-title {
  font-weight: 600;
}
.step-help {
  font-size: 12.5px;
  margin-top: 2px;
}
.step-link {
  font-size: 12.5px;
  display: inline-block;
  margin-top: 4px;
}
.opt {
  margin-left: 8px;
  font-weight: normal;
}
.courier {
  margin-top: 16px;
}
.courier-head {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 12px;
  flex-wrap: wrap;
  margin-bottom: 8px;
}
.courier-head h3 {
  margin: 0;
}
.caps {
  display: flex;
  flex-wrap: wrap;
  gap: 6px 0;
}
.section-label {
  font-weight: 600;
  margin: 16px 0 6px;
}
.fields,
.tips {
  margin: 0;
  padding-left: 18px;
}
.fields li,
.tips li {
  margin-bottom: 6px;
}
.fields .muted {
  font-size: 12.5px;
}
.req {
  color: #cf1322;
  margin-left: 2px;
}
.hook {
  display: flex;
  gap: 8px;
  align-items: flex-start;
}
.url-cell {
  display: flex;
  gap: 8px;
  align-items: center;
  word-break: break-all;
}
.topic,
.topic-note {
  white-space: pre-line;
  margin: 0;
}
.topic-note {
  margin-top: 8px;
  font-size: 12.5px;
}
.faq dt {
  font-weight: 600;
  margin-top: 10px;
}
.faq dd {
  margin: 2px 0 0;
}
</style>
