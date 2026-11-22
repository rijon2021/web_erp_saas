<template>
  <div class="page">
    <PageHeader title="Printful" :breadcrumb="[$t('Int_Ecommerce_Platforms'), 'Printful']">
      <template #actions>
        <a-tag v-if="!isLoading" :color="state.configured && state.enabled ? 'success' : 'default'" style="font-size: 13px; padding: 4px 12px">
          {{ state.configured ? (state.enabled ? $t('Enabled') : $t('Disabled')) : $t('Int_Not_Configured') }}
        </a-tag>
      </template>
    </PageHeader>

    <div v-if="isLoading" style="display: flex; justify-content: center; padding: 96px 0">
      <a-spin size="large" />
    </div>

    <a-card v-else size="small">
      <div class="hero">
        <a-avatar shape="square" :size="48" :style="{ background: 'rgba(0,0,0,0.08)', color: 'inherit' }">
          <template #icon><PrinterOutlined /></template>
        </a-avatar>
        <div>
          <div class="hero-title">{{ $t('Pf_Hero_Title') }}</div>
          <div class="muted">{{ $t('Pf_Hero_Desc') }}</div>
        </div>
      </div>

      <a-tabs v-model:activeKey="tab" @change="onTab">
        <!-- ============================ Connection ============================ -->
        <a-tab-pane key="connection" :tab="$t('Connection')">
          <a-row :gutter="16">
            <a-col :xs="24" :lg="12">
              <a-card size="small" :title="$t('Pf_Api_Access')" style="margin-bottom: 16px">
                <a-form layout="vertical">
                  <a-form-item :label="$t('Pf_Api_Token')" :extra="$t('Pf_Api_Token_Help')">
                    <a-input-password
                      v-model:value="form.api_token"
                      :placeholder="state.has_token ? $t('Int_Saved_Paste_New_Key') + (state.token_hint ? ' (' + state.token_hint + ')' : '') : 'developers.printful.com → Your tokens'"
                      autocomplete="off"
                    />
                  </a-form-item>
                  <a-form-item v-if="stores.length > 1 || form.store_id" :label="$t('Pf_Store')">
                    <a-select
                      v-model:value="form.store_id"
                      :placeholder="$t('Pf_Choose_Store')"
                      :options="storeOptions"
                    />
                  </a-form-item>
                  <a-space align="center" style="margin-bottom: 16px">
                    <a-switch v-model:checked="form.enabled" />
                    <span>{{ $t('Int_Enable_Sync', { name: 'Printful' }) }}</span>
                  </a-space>
                  <div>
                    <a-space wrap>
                      <a-button type="primary" :loading="saving" @click="save()">{{ $t('Save') }}</a-button>
                      <a-button :disabled="!state.configured && !form.api_token" :loading="testing || saving" @click="onTest">{{ $t('Test_Connection') }}</a-button>
                    </a-space>
                  </div>
                  <a-alert
                    v-if="state.store_name && !state.needs_store" type="success" show-icon style="margin-top: 16px"
                    :message="$t('Pf_Connected_Store', { name: state.store_name })"
                  />
                  <a-alert
                    v-else-if="state.needs_store" type="warning" show-icon style="margin-top: 16px"
                    :message="$t('Pf_Needs_Store')"
                  />
                </a-form>
              </a-card>
            </a-col>
            <a-col :xs="24" :lg="12">
              <a-card size="small" :title="$t('Pf_Webhooks')">
                <p class="muted">{{ $t('Pf_Webhooks_Desc') }}</p>
                <a-input v-if="state.webhook_url" :value="state.webhook_url" readonly style="margin-bottom: 12px" />
                <div style="margin-bottom: 12px">
                  <a-tag v-if="state.webhook_registered_at" color="success">{{ $t('Pf_Webhooks_On', { date: date(state.webhook_registered_at) }) }}</a-tag>
                  <a-tag v-else>{{ $t('Pf_Webhooks_Off') }}</a-tag>
                </div>
                <a-button :disabled="!state.configured || !state.store_name" :loading="registering" @click="registerWebhooks">
                  <template #icon><ApiOutlined /></template>{{ $t('Pf_Register_Webhooks') }}
                </a-button>
              </a-card>
            </a-col>
          </a-row>
        </a-tab-pane>

        <!-- ============================ Settings ============================ -->
        <a-tab-pane key="settings" :tab="$t('Settings')" :disabled="!state.configured">
          <a-card size="small" style="max-width: 760px">
            <a-form layout="vertical">
              <a-form-item :label="$t('Pf_Submit_On')">
                <a-radio-group v-model:value="form.submit_on">
                  <a-radio value="confirmed" class="radio-row">{{ $t('Pf_Submit_Confirmed') }}</a-radio>
                  <a-radio value="paid" class="radio-row">{{ $t('Pf_Submit_Paid') }}</a-radio>
                  <a-radio value="manual" class="radio-row">{{ $t('Pf_Submit_Manual') }}</a-radio>
                </a-radio-group>
              </a-form-item>
              <a-form-item>
                <a-space align="start">
                  <a-switch v-model:checked="form.auto_confirm" />
                  <div>
                    <div>{{ $t('Pf_Auto_Confirm') }}</div>
                    <div class="muted">{{ $t('Pf_Auto_Confirm_Help') }}</div>
                  </div>
                </a-space>
              </a-form-item>
              <a-alert v-if="form.auto_confirm" type="warning" show-icon :message="$t('Pf_Auto_Confirm_Warning')" style="margin-bottom: 16px" />
              <a-form-item>
                <a-space align="start">
                  <a-switch v-model:checked="form.mark_shipped" />
                  <div>
                    <div>{{ $t('Pf_Mark_Shipped') }}</div>
                    <div class="muted">{{ $t('Pf_Mark_Shipped_Help') }}</div>
                  </div>
                </a-space>
              </a-form-item>
              <a-form-item>
                <a-space align="start">
                  <a-switch v-model:checked="form.sync_costs" />
                  <div>
                    <div>{{ $t('Pf_Sync_Costs') }}</div>
                    <div class="muted">{{ $t('Pf_Sync_Costs_Help') }}</div>
                  </div>
                </a-space>
              </a-form-item>
              <a-form-item>
                <a-space align="start">
                  <a-switch v-model:checked="form.update_prices" />
                  <div>
                    <div>{{ $t('Pf_Update_Prices') }}</div>
                    <div class="muted">{{ $t('Pf_Update_Prices_Help') }}</div>
                  </div>
                </a-space>
              </a-form-item>
              <a-row :gutter="16">
                <a-col :xs="24" :sm="12">
                  <a-form-item :label="$t('Pf_Import_Category')" :extra="$t('Pf_Import_Category_Help')">
                    <a-select
                      v-model:value="form.category_id" allow-clear show-search option-filter-prop="label"
                      :placeholder="$t('Pf_Auto_Category')"
                      :options="(state.categories || []).map(c => ({ value: c.id, label: c.name }))"
                    />
                  </a-form-item>
                </a-col>
                <a-col :xs="24" :sm="12">
                  <a-form-item
                    :label="$t('Lsr_Exchange_Rate')"
                    :extra="$t('Pf_Exchange_Rate_Help', { from: state.currency, to: state.store_currency })"
                  >
                    <a-input-number
                      v-model:value="form.exchange_rate" :min="0" :step="0.01" style="width: 100%"
                      :disabled="state.currency === state.store_currency"
                      :addon-before="'1 ' + state.currency + ' ='" :addon-after="state.store_currency"
                    />
                  </a-form-item>
                </a-col>
              </a-row>
              <a-button type="primary" :loading="saving" @click="save()">{{ $t('Save') }}</a-button>
            </a-form>
          </a-card>
        </a-tab-pane>

        <!-- ============================ Products ============================ -->
        <a-tab-pane key="products" :tab="$t('Products')" :disabled="!state.configured">
          <StatsRow :stats="stats" :items="[['products_mapped', $t('Pf_Products_Linked')], ['variants_mapped', $t('Pf_Variants_Linked')]]" />
          <a-card size="small" :title="$t('Pf_Import_Title')" style="margin-bottom: 16px">
            <p class="muted">{{ $t('Pf_Import_Desc') }}</p>
            <a-space>
              <a-button type="primary" :disabled="sync.running.value || !state.store_name" @click="runImport">
                <template #icon><DownloadOutlined /></template>{{ $t('Pf_Import_Products') }}
              </a-button>
              <a-button v-if="sync.running.value" danger @click="sync.cancel()">{{ $t('Cancel') }}</a-button>
            </a-space>
          </a-card>
          <SyncProgress :sync="sync" />
        </a-tab-pane>

        <!-- ============================ Orders ============================ -->
        <a-tab-pane key="orders" :tab="$t('Orders')" :disabled="!state.configured">
          <StatsRow
            :stats="stats"
            :items="[['orders_sent', $t('Pf_Orders_Sent')], ['orders_draft', $t('Pf_Orders_Draft')], ['orders_error', $t('Pf_Orders_Attention')], ['orders_shipped', $t('Pf_Orders_Shipped')]]"
          />
          <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; gap: 8px; flex-wrap: wrap">
            <a-segmented v-model:value="orderView" :options="[{ value: 'sent', label: $t('Pf_View_Sent') }, { value: 'ready', label: $t('Pf_View_Ready') }]" @change="loadOrders(1)" />
            <a-button size="small" @click="loadOrders(orders.current_page)"><template #icon><ReloadOutlined /></template></a-button>
          </div>
          <a-table
            size="small" :row-key="r => (orderView === 'sent' ? 'p' + r.id : 'o' + r.online_order_id)"
            :data-source="orders.data" :columns="orderView === 'sent' ? sentColumns : readyColumns" :loading="ordersLoading"
            :pagination="{ current: orders.current_page, pageSize: orders.per_page, total: orders.total, showSizeChanger: false }"
            :scroll="{ x: 1000 }" @change="p => loadOrders(p.current)"
          >
            <template #bodyCell="{ column, record }">
              <template v-if="column.key === 'ref'">
                <strong>{{ record.ref }}</strong>
                <div class="muted">{{ record.customer }}</div>
              </template>
              <template v-else-if="column.key === 'total'">{{ money(record.total) }}</template>
              <template v-else-if="column.key === 'status'">
                <a-tooltip :title="record.error || ''">
                  <a-tag :color="statusColor(record.status)">{{ statusLabel(record.status) }}</a-tag>
                </a-tooltip>
                <div v-if="record.printful_order_id" class="muted">#{{ record.printful_order_id }}</div>
              </template>
              <template v-else-if="column.key === 'cost'">
                <span v-if="record.total_cost != null">{{ Number(record.total_cost).toFixed(2) }} {{ record.currency }}</span>
                <span v-else class="muted">—</span>
              </template>
              <template v-else-if="column.key === 'tracking'">
                <template v-if="record.tracking_number">
                  <div>{{ record.carrier }}</div>
                  <a v-if="record.tracking_url" :href="record.tracking_url" target="_blank" rel="noopener">{{ record.tracking_number }}</a>
                  <span v-else>{{ record.tracking_number }}</span>
                </template>
                <span v-else class="muted">—</span>
              </template>
              <template v-else-if="column.key === 'order_status'">
                <a-tag>{{ record.order_status }}</a-tag>
                <a-tag v-if="record.payment_status" :color="record.payment_status === 'paid' ? 'success' : 'default'">{{ record.payment_status }}</a-tag>
              </template>
              <template v-else-if="column.key === 'actions'">
                <a-space wrap>
                  <a-button v-if="orderView === 'ready' || record.can_retry" size="small" type="primary" :loading="busy === key(record)" @click="sendOrder(record)">
                    {{ orderView === 'ready' ? $t('Pf_Send') : $t('Pf_Retry') }}
                  </a-button>
                  <a-popconfirm v-if="record.can_confirm" :title="$t('Pf_Confirm_Order_Confirm')" :ok-text="$t('Yes')" :cancel-text="$t('No')" @confirm="orderAction(record, 'confirm')">
                    <a-button size="small" type="primary" :loading="busy === key(record)">{{ $t('Confirm') }}</a-button>
                  </a-popconfirm>
                  <a-button v-if="record.printful_order_id" size="small" :loading="busy === key(record)" @click="orderAction(record, 'refresh')">
                    <template #icon><ReloadOutlined /></template>
                  </a-button>
                  <a-popconfirm v-if="record.can_cancel" :title="$t('Pf_Cancel_Order_Confirm')" :ok-text="$t('Yes')" :cancel-text="$t('No')" @confirm="orderAction(record, 'cancel')">
                    <a-button size="small" danger :loading="busy === key(record)">{{ $t('Cancel') }}</a-button>
                  </a-popconfirm>
                </a-space>
              </template>
            </template>
          </a-table>
        </a-tab-pane>

        <!-- ============================ Logs ============================ -->
        <a-tab-pane key="logs" :tab="$t('Logs')">
          <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; gap: 8px; flex-wrap: wrap">
            <a-select
              v-model:value="logLevel" style="min-width: 160px" :options="[
                { value: '', label: $t('Int_All_Levels') },
                { value: 'info', label: $t('Info') },
                { value: 'warning', label: $t('Warning') },
                { value: 'error', label: $t('Cr_Log_Error') },
              ]" @change="loadLogs(1)"
            />
            <a-popconfirm :title="$t('Int_Clear_All_Logs_Confirm', { name: 'Printful' })" :ok-text="$t('Yes')" :cancel-text="$t('No')" @confirm="clearLogs">
              <a-button danger size="small">{{ $t('Clear_Logs') }}</a-button>
            </a-popconfirm>
          </div>
          <a-table
            size="small" row-key="id" :data-source="logs.data" :columns="logColumns"
            :pagination="{ current: logs.current_page, pageSize: logs.per_page, total: logs.total, showSizeChanger: false }"
            :scroll="{ x: 900 }" @change="p => loadLogs(p.current)"
          >
            <template #bodyCell="{ column, record }">
              <template v-if="column.key === 'level'">
                <a-tag :color="record.level === 'error' ? 'error' : record.level === 'warning' ? 'warning' : 'default'">{{ record.level }}</a-tag>
              </template>
              <template v-else-if="column.key === 'created_at'">{{ date(record.created_at) }}</template>
            </template>
          </a-table>
        </a-tab-pane>

        <!-- ============================ Guide ============================ -->
        <a-tab-pane key="guide" :tab="$t('Guide')">
          <a-card size="small" style="max-width: 860px">
            <ol class="guide">
              <i18n-t keypath="Pf_Guide_1" tag="li">
                <template #store><strong>Manual order platform / API</strong></template>
              </i18n-t>
              <i18n-t keypath="Pf_Guide_2" tag="li">
                <template #portal><a href="https://developers.printful.com/" target="_blank" rel="noopener">developers.printful.com</a></template>
                <template #scopes><code>orders</code>, <code>sync_products</code>, <code>webhooks</code></template>
              </i18n-t>
              <li>{{ $t('Pf_Guide_3') }}</li>
              <li>{{ $t('Pf_Guide_4') }}</li>
              <li>{{ $t('Pf_Guide_5') }}</li>
              <li>{{ $t('Pf_Guide_6') }}</li>
              <li>{{ $t('Pf_Guide_7') }}</li>
            </ol>
            <a-alert type="warning" show-icon :message="$t('Pf_Guide_Duplicate')" />
          </a-card>
        </a-tab-pane>
      </a-tabs>
    </a-card>
  </div>
</template>

<script setup>
/**
 * Printful integration page. Endpoints (all under /api, feature-gated by
 * tenant.feature:printful): GET/POST printful/settings, POST
 * printful/test-connection, GET/POST printful/webhooks, GET printful/stats,
 * POST printful/sync/products (cursor = offset in page_info), GET
 * printful/orders?view=sent|ready, POST printful/orders/send/{onlineOrderId},
 * POST printful/orders/{id}/{confirm|cancel|refresh}, GET/DELETE printful/logs.
 * Auth is a private Printful API token — no OAuth.
 */
import { ref, computed, h, defineComponent, onMounted } from 'vue';
import { message, Tag as ATagC, Spin as ASpinC } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { PrinterOutlined, DownloadOutlined, ReloadOutlined, ApiOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import { useFormat } from '../../composables/useFormat';
import { useBatchSync, COUNTER_LABELS } from '../../composables/useBatchSync';
import http from '../../lib/http';

const { t } = useI18n();
const { date, money } = useFormat();

const isLoading = ref(true);
const saving = ref(false);
const testing = ref(false);
const registering = ref(false);
const tab = ref('connection');
const state = ref({ configured: false, enabled: false, categories: [] });
const form = ref({});
const stores = ref([]);
const stats = ref({});
const orderView = ref('sent');
const orders = ref({ data: [], current_page: 1, per_page: 20, total: 0 });
const ordersLoading = ref(false);
const busy = ref(null);
const logs = ref({ data: [], current_page: 1, per_page: 20, total: 0 });
const logLevel = ref('');

const sync = useBatchSync();

const storeOptions = computed(() => {
  const list = stores.value.map(s => ({ value: s.id, label: s.name + (s.type ? ' (' + s.type + ')' : '') }));
  if (form.value.store_id && !list.some(o => o.value === form.value.store_id)) {
    list.unshift({ value: form.value.store_id, label: state.value.store_name || '#' + form.value.store_id });
  }
  return list;
});

const logColumns = [
  { title: 'ID', dataIndex: 'id', width: 70 },
  { title: t('Action'), dataIndex: 'action', width: 180 },
  { title: t('Level'), key: 'level', width: 100 },
  { title: t('Message'), dataIndex: 'message', ellipsis: true },
  { title: t('Date'), key: 'created_at', width: 170 },
];
const sentColumns = [
  { title: t('Order'), key: 'ref' },
  { title: t('Total'), key: 'total', width: 120 },
  { title: 'Printful', key: 'status', width: 150 },
  { title: t('Pf_Printful_Cost'), key: 'cost', width: 130 },
  { title: t('Pf_Tracking'), key: 'tracking', width: 170 },
  { title: t('Actions'), key: 'actions', width: 220 },
];
const readyColumns = [
  { title: t('Order'), key: 'ref' },
  { title: t('Date'), dataIndex: 'date', width: 120 },
  { title: t('Country'), dataIndex: 'country', width: 140 },
  { title: t('Total'), key: 'total', width: 120 },
  { title: t('Status'), key: 'order_status', width: 190 },
  { title: t('Actions'), key: 'actions', width: 140 },
];

const STATUS_COLORS = {
  queued: 'processing', error: 'error', draft: 'gold', inreview: 'blue', pending: 'blue', inprocess: 'blue',
  onhold: 'warning', failed: 'error', partial: 'cyan', fulfilled: 'success', canceled: 'default', archived: 'default',
};
const statusColor = s => STATUS_COLORS[s] || 'default';
const statusLabel = s => t('Pf_Status_' + s, s);
const key = r => (r.id ? 'p' + r.id : 'o' + r.online_order_id);

function fillForm() {
  const s = state.value;
  form.value = {
    enabled: !!s.enabled,
    api_token: '',
    store_id: s.store_id || undefined,
    submit_on: s.submit_on || 'confirmed',
    auto_confirm: !!s.auto_confirm,
    sync_costs: !!s.sync_costs,
    update_prices: !!s.update_prices,
    mark_shipped: !!s.mark_shipped,
    category_id: s.category_id || undefined,
    exchange_rate: s.exchange_rate || null,
  };
}

async function load() {
  try {
    state.value = await http.get('printful/settings');
    fillForm();
    if (state.value.configured) loadStats();
    // Token saved but no store chosen yet: fetch the store list for the picker.
    if (state.value.needs_store) testConnection();
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    isLoading.value = false;
  }
}

async function loadStats() {
  try {
    stats.value = await http.get('printful/stats');
  } catch (e) { /* tiles show em-dashes */ }
}

async function save(quiet = false) {
  saving.value = true;
  try {
    const f = form.value;
    const payload = {
      enabled: !!f.enabled,
      submit_on: f.submit_on,
      auto_confirm: !!f.auto_confirm,
      sync_costs: !!f.sync_costs,
      update_prices: !!f.update_prices,
      mark_shipped: !!f.mark_shipped,
      category_id: f.category_id || null,
      exchange_rate: f.exchange_rate || null,
    };
    // The token is write-only: send it only when the admin typed a new one.
    // A new token gets its store picked again, so the old store isn't sent.
    if (f.api_token) payload.api_token = f.api_token.trim();
    else if (f.store_id) {
      payload.store_id = f.store_id;
      payload.store_name = (stores.value.find(s => s.id === f.store_id) || {}).name || state.value.store_name || null;
    }
    const hadToken = state.value.has_token;
    const storeChanged = !!payload.store_id && payload.store_id !== state.value.store_id;
    await http.post('printful/settings', payload);
    if (!quiet) message.success(t('Successfully_Updated'));
    state.value = await http.get('printful/settings');
    fillForm();
    // New token or store: check it straight away (this also registers the webhooks).
    if (payload.api_token || storeChanged || (!hadToken && state.value.configured)) {
      await testConnection();
    }
  } catch (e) {
    message.error(e?.data?.errors ? Object.values(e.data.errors)[0][0] : (e?.data?.message || t('InvalidData')));
  } finally {
    saving.value = false;
  }
}

/** Test what is on screen: unsaved token / store edits are saved first (save() then tests). */
function onTest() {
  const f = form.value;
  if (f.api_token || (f.store_id && f.store_id !== state.value.store_id)) save();
  else testConnection();
}

async function testConnection() {
  testing.value = true;
  try {
    const r = await http.post('printful/test-connection');
    stores.value = r.stores || [];
    message.success(t('Int_Connected_To_Dot', { name: r.store?.name || 'Printful' }));
    if (r.webhooks && !r.webhooks.ok) message.warning(r.webhooks.error || t('Pf_Webhooks_Off'));
    state.value = await http.get('printful/settings');
    fillForm();
    loadStats();
  } catch (e) {
    stores.value = e?.data?.stores || stores.value;
    if (e?.data?.needs_store) {
      message.info(t('Pf_Choose_Store'));
      // The backend dropped a store this token can't reach: show that.
      try {
        state.value = await http.get('printful/settings');
        fillForm();
      } catch (ignored) { /* keep what is shown */ }
    } else message.error(e?.data?.error || e?.data?.message || t('Connection_Test_Failed'));
  } finally {
    testing.value = false;
  }
}

async function registerWebhooks() {
  registering.value = true;
  try {
    await http.post('printful/webhooks');
    message.success(t('Successfully_Updated'));
    state.value = await http.get('printful/settings');
  } catch (e) {
    message.error(e?.data?.error || e?.data?.message || t('InvalidData'));
  } finally {
    registering.value = false;
  }
}

async function runImport() {
  await sync.run('printful/sync/products', {}, 'pull');
  loadStats();
}

async function loadOrders(page = 1) {
  ordersLoading.value = true;
  try {
    orders.value = await http.get('printful/orders?per_page=20&view=' + orderView.value + '&page=' + page);
  } catch (e) {
    orders.value = { data: [], current_page: 1, per_page: 20, total: 0 };
  } finally {
    ordersLoading.value = false;
  }
}

async function sendOrder(record) {
  busy.value = key(record);
  try {
    const r = await http.post('printful/orders/send/' + record.online_order_id);
    message.success(t('Pf_Sent_As', { status: statusLabel(r.status) }));
    loadOrders(orders.value.current_page);
    loadStats();
  } catch (e) {
    message.error(e?.data?.error || e?.data?.message || t('InvalidData'));
    loadOrders(orders.value.current_page);
  } finally {
    busy.value = null;
  }
}

async function orderAction(record, action) {
  busy.value = key(record);
  try {
    await http.post('printful/orders/' + record.id + '/' + action);
    message.success(t('Successfully_Updated'));
    loadOrders(orders.value.current_page);
    loadStats();
  } catch (e) {
    message.error(e?.data?.error || e?.data?.message || t('InvalidData'));
  } finally {
    busy.value = null;
  }
}

async function loadLogs(page = 1) {
  try {
    const query = 'printful/logs?per_page=20&page=' + page + (logLevel.value ? '&level=' + logLevel.value : '');
    logs.value = await http.get(query);
  } catch (e) { /* table stays empty */ }
}

async function clearLogs() {
  try {
    await http.delete('printful/logs');
    loadLogs(1);
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  }
}

function onTab(k) {
  if (k === 'orders') loadOrders(1);
  if (k === 'logs') loadLogs(1);
  if (k === 'products' || k === 'orders') loadStats();
}

onMounted(load);

// ---- small local render components (same pattern as JumiaSettings.vue) ----

const tileStyle = {
  border: '1px solid rgba(128,128,128,0.2)', borderRadius: '10px',
  padding: '10px 18px', minWidth: '130px', textAlign: 'center',
};

const StatsRow = defineComponent({
  props: { stats: Object, items: Array },
  setup(p) {
    return () => h(
      'div',
      { style: { display: 'flex', gap: '12px', flexWrap: 'wrap', marginBottom: '16px' } },
      (p.items || []).map(([k, label]) => h('div', { style: tileStyle, key: k }, [
        h('div', { style: { fontSize: '20px', fontWeight: 600 } }, p.stats?.[k] ?? '—'),
        h('div', { style: { fontSize: '12px', opacity: 0.6 } }, label),
      ]))
    );
  },
});

const SyncProgress = defineComponent({
  props: { sync: Object },
  setup(p) {
    return () => {
      const s = p.sync;
      if (!s || (!s.hasCounters.value && !s.running.value)) return null;
      const pills = Object.entries(s.counters.value).map(([k, v]) =>
        h(ATagC, { key: k, color: k === 'failed' && v ? 'error' : 'default' }, () => `${COUNTER_LABELS[k] || k}: ${v}`));
      const rows = [h('div', { style: { display: 'flex', gap: '6px', flexWrap: 'wrap', alignItems: 'center' } }, [
        ...(s.running.value ? [h(ASpinC, { size: 'small', style: { marginRight: '6px' } })] : []),
        ...pills,
      ])];
      if (s.batchErrors.value.length) {
        rows.push(h('div', { style: { marginTop: '10px', fontSize: '12px', maxHeight: '160px', overflow: 'auto' } },
          s.batchErrors.value.map((err, i) => h('div', { key: i, style: { opacity: 0.75 } }, JSON.stringify(err)))));
      }
      return h('div', {
        style: { border: '1px dashed rgba(128,128,128,0.35)', borderRadius: '10px', padding: '12px 14px' },
      }, rows);
    };
  },
});
</script>

<style scoped>
.hero {
  display: flex;
  align-items: center;
  gap: 14px;
  margin-bottom: 16px;
  padding-bottom: 14px;
  border-bottom: 1px solid rgba(128, 128, 128, 0.15);
}
.hero-title {
  font-weight: 600;
  font-size: 16px;
}
.muted {
  opacity: 0.65;
  font-size: 13px;
}
.radio-row {
  display: flex;
  margin-bottom: 6px;
}
.guide {
  padding-left: 20px;
  line-height: 2;
  margin: 0 0 12px;
}
</style>
