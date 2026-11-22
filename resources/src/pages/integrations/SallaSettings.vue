<template>
  <div class="page">
    <PageHeader title="Salla" :breadcrumb="[$t('Int_Ecommerce_Platforms'), 'Salla']">
      <template #actions>
        <a-tag v-if="!isLoading" :color="state.connected ? 'success' : 'default'" style="font-size: 13px; padding: 4px 12px">
          {{ state.connected ? $t('Connected') : $t('Not_connected') }}
        </a-tag>
      </template>
    </PageHeader>

    <div v-if="isLoading" style="display: flex; justify-content: center; padding: 96px 0">
      <a-spin size="large" />
    </div>

    <a-card v-else size="small">
      <div class="hero">
        <a-avatar shape="square" :size="48" :style="{ background: 'rgba(0,165,142,0.14)', color: '#00a58e' }">
          <template #icon><ShopOutlined /></template>
        </a-avatar>
        <div>
          <div class="hero-title">{{ $t('Int_Store_Sync_Title', { name: 'Salla' }) }}</div>
          <div class="muted">{{ $t('Salla_Hero_Desc') }}</div>
        </div>
      </div>

      <a-tabs v-model:activeKey="tab">
        <!-- ============================ Connection ============================ -->
        <a-tab-pane key="connection" :tab="$t('Connection')">
          <a-row :gutter="[16, 16]">
            <a-col :xs="24" :lg="12">
              <a-card size="small" :title="$t('Salla_App_Credentials')" style="height: 100%">
                <a-form layout="vertical">
                  <a-form-item :label="$t('Client_ID')">
                    <a-input v-model:value="form.client_id" :placeholder="$t('Salla_From_Partners_App')" />
                  </a-form-item>
                  <a-form-item :label="$t('Client_Secret')">
                    <a-input-password
                      v-model:value="form.client_secret"
                      :placeholder="state.has_client_secret ? $t('Int_Saved_Paste_New_Value') : $t('Salla_From_Partners_App')"
                      autocomplete="off"
                    />
                  </a-form-item>
                  <a-form-item :label="$t('Salla_Webhook_Secret')">
                    <a-input-password
                      v-model:value="form.webhook_secret"
                      :placeholder="state.has_webhook_secret ? $t('Int_Saved_Paste_New_Value') : $t('Salla_Webhook_Secret_Placeholder')"
                      autocomplete="off"
                    />
                  </a-form-item>
                  <a-form-item :label="$t('Int_Stock_Warehouse')">
                    <a-select
                      v-model:value="form.warehouse_id"
                      :options="[{ value: null, label: $t('Int_All_Warehouses_Aggregate') }, ...state.warehouses.map(w => ({ value: w.id, label: w.name }))]"
                    />
                  </a-form-item>
                  <a-space align="center" style="margin-bottom: 16px">
                    <a-switch v-model:checked="form.enabled" />
                    <span>{{ $t('Int_Enable_Sync', { name: 'Salla' }) }}</span>
                  </a-space>
                  <div>
                    <a-button type="primary" :loading="saving" @click="save">{{ $t('Save') }}</a-button>
                  </div>
                </a-form>
              </a-card>
            </a-col>

            <a-col :xs="24" :lg="12">
              <a-card size="small" :title="$t('Int_Store_Connection')" style="margin-bottom: 16px">
                <a-descriptions :column="1" size="small" bordered>
                  <a-descriptions-item :label="$t('Store')">
                    {{ state.store?.name || '—' }}
                    <span v-if="state.store?.domain" class="muted" style="margin-left: 6px">({{ state.store.domain }})</span>
                  </a-descriptions-item>
                  <a-descriptions-item :label="$t('Salla_Token_Expires')">
                    {{ state.access_token_expires_at ? date(state.access_token_expires_at) : '—' }}
                  </a-descriptions-item>
                  <a-descriptions-item :label="$t('Last_Sync')">
                    {{ state.last_sync_at ? date(state.last_sync_at) : '—' }}
                  </a-descriptions-item>
                </a-descriptions>
                <a-space wrap style="margin-top: 16px">
                  <a-button type="primary" :disabled="!state.client_id || !state.has_client_secret" @click="connect">
                    <template #icon><LinkOutlined /></template>
                    {{ state.connected ? $t('Int_Reconnect_With', { name: 'Salla' }) : $t('Int_Connect_With', { name: 'Salla' }) }}
                  </a-button>
                  <a-button :disabled="!state.connected" :loading="testing" @click="testConnection">{{ $t('Test_Connection') }}</a-button>
                  <a-popconfirm
                    v-if="state.connected"
                    :title="$t('Salla_Disconnect_Confirm')"
                    :ok-text="$t('Yes')" :cancel-text="$t('No')" @confirm="disconnect"
                  >
                    <a-button danger>{{ $t('Disconnect') }}</a-button>
                  </a-popconfirm>
                </a-space>
              </a-card>

              <a-card size="small" :title="$t('Salla_Urls_Title')">
                <div class="url-row">
                  <span class="muted">{{ $t('Salla_OAuth_Callback') }}</span>
                  <a-input-group compact style="display: flex">
                    <a-input :value="state.callback_url" readonly style="flex: 1" />
                    <a-button @click="copy(state.callback_url)"><CopyOutlined /></a-button>
                  </a-input-group>
                </div>
                <div class="url-row">
                  <span class="muted">{{ $t('Salla_Webhook_Url_All') }}</span>
                  <a-input-group compact style="display: flex">
                    <a-input :value="state.webhook_url" readonly style="flex: 1" />
                    <a-button @click="copy(state.webhook_url)"><CopyOutlined /></a-button>
                  </a-input-group>
                </div>
                <i18n-t keypath="Salla_Urls_Help" tag="div" class="muted" style="margin-top: 8px">
                  <template #link><a href="https://salla.partners" target="_blank" rel="noopener">salla.partners</a></template>
                  <template #signature><strong>Signature</strong></template>
                </i18n-t>
              </a-card>
            </a-col>
          </a-row>
        </a-tab-pane>

        <!-- ============================ Products ============================ -->
        <a-tab-pane key="products" :tab="$t('Products')" :disabled="!state.connected">
          <StatsRow :stats="stats" :items="[['products_total', $t('Total_Products')], ['products_mapped', $t('Int_Linked_To', { name: 'Salla' })], ['variants_mapped', $t('Salla_Variants_Linked')]]" />
          <a-card size="small" :title="$t('Int_Push_Products_To', { name: 'Salla' })" style="margin-bottom: 16px">
            <p class="muted">{{ $t('Salla_Push_Desc') }}</p>
            <a-checkbox v-model:checked="onlyUnsynced" :disabled="sync.running.value" style="margin-bottom: 12px">{{ $t('Salla_Only_Unlinked') }}</a-checkbox>
            <div>
              <a-space>
                <a-button type="primary" :disabled="sync.running.value" @click="runSync('salla/sync/products?mode=push', { only_unsynced: onlyUnsynced }, 'push')">
                  <template #icon><UploadOutlined /></template>{{ $t('Int_Push_To', { name: 'Salla' }) }}
                </a-button>
                <a-button v-if="sync.running.value" danger @click="sync.cancel()">{{ $t('Cancel') }}</a-button>
              </a-space>
            </div>
          </a-card>
          <a-card size="small" :title="$t('Int_Pull_Products_From', { name: 'Salla' })" style="margin-bottom: 16px">
            <p class="muted">{{ $t('Salla_Pull_Desc') }}</p>
            <a-space>
              <a-button :disabled="sync.running.value" @click="runSync('salla/sync/products?mode=pull', {}, 'pull')">
                <template #icon><DownloadOutlined /></template>{{ $t('Int_Pull_From', { name: 'Salla' }) }}
              </a-button>
              <a-popconfirm :title="$t('Salla_Reset_Confirm')" :ok-text="$t('Yes')" :cancel-text="$t('No')" @confirm="resetMappings('product')">
                <a-button :disabled="sync.running.value">{{ $t('Int_Reset_Product_Links') }}</a-button>
              </a-popconfirm>
            </a-space>
          </a-card>
          <SyncProgress :sync="sync" />
        </a-tab-pane>

        <!-- ============================ Stock ============================ -->
        <a-tab-pane key="stock" :tab="$t('Stock')" :disabled="!state.connected">
          <a-card size="small" :title="$t('Int_Push_Stock_Levels_To', { name: 'Salla' })" style="margin-bottom: 16px">
            <p class="muted">
              {{ $t('Salla_Stock_Desc') }}
              {{ state.warehouse_id ? $t('Int_Counting_Stock_Selected') : $t('Int_Counting_Stock_All') }}
            </p>
            <a-space>
              <a-button type="primary" :disabled="sync.running.value" @click="runSync('salla/sync/inventory', {}, 'push')">
                <template #icon><UploadOutlined /></template>{{ $t('Int_Push_Stock') }}
              </a-button>
              <a-button v-if="sync.running.value" danger @click="sync.cancel()">{{ $t('Cancel') }}</a-button>
            </a-space>
          </a-card>
          <SyncProgress :sync="sync" />
        </a-tab-pane>

        <!-- ============================ Orders ============================ -->
        <a-tab-pane key="orders" :tab="$t('Orders')" :disabled="!state.connected">
          <StatsRow :stats="stats" :items="[['orders_imported', $t('Int_Orders_Imported')], ['customers_mapped', $t('Int_Customers_Linked')]]" />
          <a-card size="small" :title="$t('Int_Pull_Orders_From', { name: 'Salla' })" style="margin-bottom: 16px">
            <p class="muted">
              {{ $t('Salla_Orders_Desc') }}
            </p>
            <a-form layout="inline" style="margin-bottom: 12px">
              <a-form-item :label="$t('Int_Import_Into_Warehouse')">
                <a-select
                  v-model:value="ordersWarehouseId" style="min-width: 220px"
                  :options="[{ value: null, label: $t('Int_Default_Warehouse_Option') }, ...state.warehouses.map(w => ({ value: w.id, label: w.name }))]"
                />
              </a-form-item>
            </a-form>
            <a-space>
              <a-button type="primary" :disabled="sync.running.value" @click="runSync('salla/sync/orders', ordersWarehouseId ? { warehouse_id: ordersWarehouseId } : {}, 'pull')">
                <template #icon><DownloadOutlined /></template>{{ $t('Int_Pull_Orders') }}
              </a-button>
              <a-button v-if="sync.running.value" danger @click="sync.cancel()">{{ $t('Cancel') }}</a-button>
            </a-space>
          </a-card>
          <SyncProgress :sync="sync" />
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
            <a-popconfirm :title="$t('Int_Clear_All_Logs_Confirm', { name: 'Salla' })" :ok-text="$t('Yes')" :cancel-text="$t('No')" @confirm="clearLogs">
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
              <i18n-t keypath="Salla_Guide_1" tag="li">
                <template #link><a href="https://salla.partners" target="_blank" rel="noopener">salla.partners</a></template>
                <template #mode><strong>Custom Mode</strong></template>
              </i18n-t>
              <i18n-t keypath="Salla_Guide_2" tag="li">
                <template #clientId><strong>{{ $t('Client_ID') }}</strong></template>
                <template #clientSecret><strong>{{ $t('Client_Secret') }}</strong></template>
              </i18n-t>
              <li>{{ $t('Salla_Guide_3') }}</li>
              <i18n-t keypath="Salla_Guide_4" tag="li">
                <template #signature><strong>Signature</strong></template>
                <template #e1><code>order.created</code></template>
                <template #e2><code>order.updated</code></template>
                <template #e3><code>product.updated</code></template>
                <template #e4><code>product.deleted</code></template>
                <template #e5><code>app.store.authorize</code></template>
                <template #e6><code>app.uninstalled</code></template>
              </i18n-t>
              <i18n-t keypath="Salla_Guide_5" tag="li">
                <template #connect><strong>{{ $t('Int_Connect_With', { name: 'Salla' }) }}</strong></template>
                <template #webhook><code>app.store.authorize</code></template>
              </i18n-t>
              <i18n-t keypath="Salla_Guide_6" tag="li">
                <template #ref><code>SLA-12345</code></template>
              </i18n-t>
            </ol>
            <a-alert
              type="info" show-icon style="margin-top: 8px"
              :message="$t('Salla_Identity_Title')"
              :description="$t('Salla_Identity_Desc')"
            />
          </a-card>
        </a-tab-pane>
      </a-tabs>
    </a-card>
  </div>
</template>

<script setup>
/**
 * Salla integration page. Endpoints (all under /api, feature-gated by
 * tenant.feature:salla): GET/POST salla/settings, POST salla/disconnect,
 * POST salla/test-connection, GET salla/stats, POST salla/reset-mappings,
 * POST salla/sync/{products|inventory|orders}, GET/DELETE salla/logs.
 * OAuth runs over web routes: /salla/connect -> Salla -> /salla/callback,
 * which redirects back here with ?connected=1 or ?salla_error=...
 */
import { ref, h, defineComponent, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import { message, Tag as ATagC, Spin as ASpinC } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import {
  ShopOutlined, UploadOutlined, DownloadOutlined, LinkOutlined, CopyOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import { useFormat } from '../../composables/useFormat';
import { useSallaSync, COUNTER_LABELS } from '../../composables/useSallaSync';
import http from '../../lib/http';

const { t } = useI18n();
const { date } = useFormat();
const route = useRoute();

const isLoading = ref(true);
const saving = ref(false);
const testing = ref(false);
const tab = ref('connection');
const state = ref({ connected: false, enabled: false, warehouses: [], store: {} });
const form = ref({ enabled: false, client_id: '', client_secret: '', webhook_secret: '', warehouse_id: null });
const stats = ref({});
const onlyUnsynced = ref(true);
const ordersWarehouseId = ref(null);
const logs = ref({ data: [], current_page: 1, per_page: 20, total: 0 });
const logLevel = ref('');

const sync = useSallaSync();

const logColumns = [
  { title: 'ID', dataIndex: 'id', width: 70 },
  { title: t('Action'), dataIndex: 'action', width: 180 },
  { title: t('Level'), key: 'level', width: 100 },
  { title: t('Message'), dataIndex: 'message', ellipsis: true },
  { title: t('Date'), key: 'created_at', width: 170 },
];

async function load() {
  isLoading.value = true;
  try {
    state.value = await http.get('salla/settings');
    form.value = {
      enabled: !!state.value.enabled,
      client_id: state.value.client_id || '',
      client_secret: '',
      webhook_secret: '',
      warehouse_id: state.value.warehouse_id || null,
    };
    if (state.value.connected) loadStats();
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    isLoading.value = false;
  }
}

async function loadStats() {
  try {
    stats.value = await http.get('salla/stats');
  } catch (e) { /* tiles show em-dashes */ }
}

async function save() {
  saving.value = true;
  try {
    const payload = {
      enabled: !!form.value.enabled,
      client_id: (form.value.client_id || '').trim() || null,
      warehouse_id: form.value.warehouse_id,
    };
    // Secrets are write-only: send them only when the admin typed a new value.
    if (form.value.client_secret) payload.client_secret = form.value.client_secret.trim();
    if (form.value.webhook_secret) payload.webhook_secret = form.value.webhook_secret.trim();
    await http.post('salla/settings', payload);
    message.success(t('Successfully_Updated'));
    await load();
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    saving.value = false;
  }
}

function connect() {
  window.location.href = state.value.connect_url || '/salla/connect';
}

async function testConnection() {
  testing.value = true;
  try {
    const res = await http.post('salla/test-connection');
    message.success(t('Int_Connected_To', { name: res?.store?.name || 'Salla' }));
  } catch (e) {
    message.error(e?.data?.error || e?.data?.message || t('Connection_Test_Failed'));
  } finally {
    testing.value = false;
  }
}

async function disconnect() {
  try {
    await http.post('salla/disconnect');
    message.success(t('Disconnected'));
    await load();
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  }
}

async function resetMappings(entityType) {
  try {
    const res = await http.post('salla/reset-mappings', { entity_type: entityType });
    message.success(t('Int_Removed_N_Links', { n: res.deleted }));
    loadStats();
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  }
}

async function runSync(url, params, kind) {
  await sync.run(url, params, kind);
  loadStats();
}

async function loadLogs(page = 1) {
  try {
    const query = 'salla/logs?per_page=20&page=' + page + (logLevel.value ? '&level=' + logLevel.value : '');
    logs.value = await http.get(query);
  } catch (e) { /* table stays empty */ }
}

async function clearLogs() {
  try {
    await http.delete('salla/logs');
    loadLogs(1);
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  }
}

async function copy(text) {
  try {
    await navigator.clipboard.writeText(text || '');
    message.success(t('Copied'));
  } catch (e) {
    message.error(t('InvalidData'));
  }
}

onMounted(() => {
  load();
  loadLogs(1);
  if (route.query.connected === '1') message.success(t('Salla_Store_Connected'));
  if (route.query.salla_error) message.error(String(route.query.salla_error));
});

// ---- small local render components (same pattern as ShopifySettings.vue) ----

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
      (p.items || []).map(([key, label]) => h('div', { style: tileStyle, key }, [
        h('div', { style: { fontSize: '20px', fontWeight: 600 } }, p.stats?.[key] ?? '—'),
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
.url-row {
  margin-bottom: 12px;
}
.url-row > .muted {
  display: block;
  margin-bottom: 4px;
}
.guide {
  padding-left: 20px;
  line-height: 2;
  margin: 0 0 12px;
}
</style>
