<template>
  <div class="page">
    <PageHeader title="OpenCart" :breadcrumb="[$t('Int_Ecommerce_Platforms'), 'OpenCart']">
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
        <a-avatar shape="square" :size="48" :style="{ background: 'rgba(35,161,209,0.12)', color: '#23a1d1' }">
          <template #icon><GlobalOutlined /></template>
        </a-avatar>
        <div>
          <div class="hero-title">{{ $t('Int_Store_Sync_Title', { name: 'OpenCart' }) }}</div>
          <div class="muted">{{ $t('Oc_Hero_Desc', { company }) }}</div>
        </div>
      </div>

      <a-tabs v-model:activeKey="tab">
        <!-- ============================ Connection ============================ -->
        <a-tab-pane key="connection" :tab="$t('Connection')">
          <a-card size="small" :title="$t('Int_Store_Connection')" style="max-width: 640px; margin-bottom: 16px">
            <a-form layout="vertical">
              <a-form-item :label="$t('Oc_Connector', { company })">
                <a-space wrap>
                  <a-button :loading="downloading === 4" @click="downloadConnector(4)">
                    <template #icon><DownloadOutlined /></template>{{ $t('Oc_Download_Connector') }}
                  </a-button>
                  <a-button :loading="downloading === 3" @click="downloadConnector(3)">
                    <template #icon><DownloadOutlined /></template>{{ $t('Oc_Download_Connector_3') }}
                  </a-button>
                </a-space>
                <div class="muted" style="margin-top: 4px">{{ $t('Oc_Connector_Help') }}</div>
              </a-form-item>
              <a-form-item :label="$t('Oc_Connector_Link')" :extra="$t('Oc_Connector_Link_Help', { company })">
                <a-input v-model:value="form.connector_link" placeholder="https://www.example.com" />
              </a-form-item>
              <a-form-item :label="$t('Store_URL')">
                <a-input v-model:value="form.store_url" placeholder="https://store.example.com" />
              </a-form-item>
              <a-form-item :label="$t('Oc_Secret')">
                <a-input-password
                  v-model:value="form.secret"
                  :placeholder="state.has_secret ? $t('Int_Saved_Paste_New_Key') : $t('Oc_Secret_Placeholder', { company })"
                  autocomplete="off"
                />
                <div class="muted" style="margin-top: 4px">{{ $t('Oc_Secret_Help', { company }) }}</div>
              </a-form-item>
              <a-form-item v-if="stores.length > 1" :label="$t('Oc_Store')">
                <a-select
                  v-model:value="form.store_id"
                  :options="stores.map(s => ({ value: s.store_id, label: s.name }))"
                />
              </a-form-item>
              <a-form-item :label="$t('Int_Stock_Warehouse')">
                <a-select
                  v-model:value="form.warehouse_id"
                  :options="[{ value: null, label: $t('Int_All_Warehouses_Aggregate') }, ...state.warehouses.map(w => ({ value: w.id, label: w.name }))]"
                />
              </a-form-item>
              <a-form-item :label="$t('Oc_Code_Source')" :extra="$t('Oc_Code_Source_Help')">
                <a-select v-model:value="form.code_source" :options="codeSources" />
                <a-alert v-if="codeSourceNeedsConnector" type="warning" show-icon :message="$t('Oc_Code_Source_Needs_Update', { version: state.connector_version })" style="margin-top: 8px" />
              </a-form-item>
              <a-space align="center" style="margin-bottom: 16px">
                <a-switch v-model:checked="form.enabled" />
                <span>{{ $t('Int_Enable_Sync', { name: 'OpenCart' }) }}</span>
              </a-space>
              <div>
                <a-space wrap>
                  <a-button type="primary" :loading="saving" @click="save">{{ $t('Save') }}</a-button>
                  <a-button :disabled="!state.configured" :loading="testing" @click="testConnection">{{ $t('Test_Connection') }}</a-button>
                </a-space>
              </div>
              <div v-if="state.connector_version" class="muted" style="margin-top: 12px">
                {{ $t('Oc_Versions', { connector: state.connector_version, opencart: state.opencart_version }) }}
              </div>
            </a-form>
          </a-card>

          <a-card size="small" :title="$t('Oc_Automation')" style="max-width: 640px; margin-bottom: 16px">
            <div v-for="row in switches" :key="row.key" class="switch-row">
              <a-switch v-model:checked="form[row.key]" />
              <div>
                <div>{{ row.title }}</div>
                <div class="muted">{{ row.help }}</div>
              </div>
            </div>
          </a-card>

          <a-card v-if="statusOptions.length" size="small" :title="$t('Oc_Order_Statuses')" style="max-width: 640px">
            <p class="muted">{{ $t('Oc_Order_Statuses_Help') }}</p>
            <a-form layout="vertical">
              <a-form-item v-for="row in statusFields" :key="row.key" :label="row.label" :extra="row.help">
                <a-select v-model:value="form[row.key]" mode="multiple" :options="statusOptions" />
              </a-form-item>
              <a-button type="primary" :loading="saving" @click="save">{{ $t('Save') }}</a-button>
            </a-form>
          </a-card>
        </a-tab-pane>

        <!-- ============================ Products ============================ -->
        <a-tab-pane key="products" :tab="$t('Products')" :disabled="!state.configured">
          <StatsRow :stats="stats" :items="[['products_total', $t('Total_Products')], ['products_mapped', $t('Int_Linked_To', { name: 'OpenCart' })], ['variants_mapped', $t('Oc_Variants_Linked')]]" />
          <a-card size="small" :title="$t('Int_Push_Products_To', { name: 'OpenCart' })" style="margin-bottom: 16px">
            <p class="muted">{{ $t('Oc_Push_Desc') }}</p>
            <a-space>
              <a-button type="primary" :disabled="sync.running.value" @click="runSync('opencart/sync/products?mode=push', {}, 'push')">
                <template #icon><UploadOutlined /></template>{{ $t('Int_Push_To', { name: 'OpenCart' }) }}
              </a-button>
              <a-button v-if="sync.running.value" danger @click="sync.cancel()">{{ $t('Cancel') }}</a-button>
            </a-space>
          </a-card>
          <a-card size="small" :title="$t('Int_Pull_Products_From', { name: 'OpenCart' })" style="margin-bottom: 16px">
            <p class="muted">{{ $t('Oc_Pull_Desc') }}</p>
            <a-space align="center" style="margin-bottom: 12px">
              <a-switch v-model:checked="pullStock" />
              <span>{{ $t('Oc_Pull_Stock') }}</span>
            </a-space>
            <div>
              <a-space>
                <a-button :disabled="sync.running.value" @click="runSync('opencart/sync/products?mode=pull', { import_stock: pullStock }, 'pull')">
                  <template #icon><DownloadOutlined /></template>{{ $t('Int_Pull_From', { name: 'OpenCart' }) }}
                </a-button>
                <a-popconfirm :title="$t('Oc_Reset_Confirm')" :ok-text="$t('Yes')" :cancel-text="$t('No')" @confirm="resetMappings('product')">
                  <a-button :disabled="sync.running.value">{{ $t('Int_Reset_Product_Links') }}</a-button>
                </a-popconfirm>
              </a-space>
            </div>
          </a-card>
          <SyncProgress :sync="sync" />
        </a-tab-pane>

        <!-- ============================ Stock ============================ -->
        <a-tab-pane key="stock" :tab="$t('Stock')" :disabled="!state.configured">
          <a-card size="small" :title="$t('Int_Push_Stock_Levels_To', { name: 'OpenCart' })" style="margin-bottom: 16px">
            <p class="muted">
              {{ $t('Oc_Stock_Desc') }}
              {{ state.warehouse_id ? $t('Int_Counting_Stock_Selected') : $t('Int_Counting_Stock_All') }}
            </p>
            <a-space>
              <a-button type="primary" :disabled="sync.running.value" @click="runSync('opencart/sync/inventory', {}, 'push')">
                <template #icon><UploadOutlined /></template>{{ $t('Int_Push_Stock') }}
              </a-button>
              <a-button v-if="sync.running.value" danger @click="sync.cancel()">{{ $t('Cancel') }}</a-button>
            </a-space>
          </a-card>
          <SyncProgress :sync="sync" />
        </a-tab-pane>

        <!-- ============================ Orders ============================ -->
        <a-tab-pane key="orders" :tab="$t('Orders')" :disabled="!state.configured">
          <StatsRow :stats="stats" :items="[['orders_imported', $t('Int_Orders_Imported')], ['customers_mapped', $t('Int_Customers_Linked')]]" />
          <a-card size="small" :title="$t('Int_Pull_Orders_From', { name: 'OpenCart' })" style="margin-bottom: 16px">
            <i18n-t keypath="Oc_Orders_Desc" tag="p" class="muted">
              <template #ref><code>OC-1042</code></template>
            </i18n-t>
            <a-form layout="inline" style="margin-bottom: 12px">
              <a-form-item :label="$t('Oc_Orders_Since')">
                <a-date-picker v-model:value="ordersSince" value-format="YYYY-MM-DD" />
              </a-form-item>
              <a-form-item :label="$t('Int_Import_Into_Warehouse')">
                <a-select
                  v-model:value="ordersWarehouseId" style="min-width: 220px"
                  :options="[{ value: null, label: $t('Int_Default_Warehouse_Option') }, ...state.warehouses.map(w => ({ value: w.id, label: w.name }))]"
                />
              </a-form-item>
            </a-form>
            <a-space>
              <a-button type="primary" :disabled="sync.running.value || !ordersSince" @click="pullOrders">
                <template #icon><DownloadOutlined /></template>{{ $t('Int_Pull_Orders') }}
              </a-button>
              <a-button v-if="sync.running.value" danger @click="sync.cancel()">{{ $t('Cancel') }}</a-button>
            </a-space>
          </a-card>
          <SyncProgress :sync="sync" />
        </a-tab-pane>

        <!-- ============================ Logs ============================ -->
        <a-tab-pane key="logs" :tab="$t('Logs')">
          <a-alert type="info" show-icon style="margin-bottom: 12px" :message="$t('Oc_L_Tab_Hint')">
            <template #action>
              <router-link to="/integrations/opencart/logs"><a-button size="small" type="primary">{{ $t('Oc_L_Open_Page') }}</a-button></router-link>
            </template>
          </a-alert>
          <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; gap: 8px; flex-wrap: wrap">
            <a-select
              v-model:value="logLevel" style="min-width: 160px" :options="[
                { value: '', label: $t('Int_All_Levels') },
                { value: 'info', label: $t('Info') },
                { value: 'warning', label: $t('Warning') },
                { value: 'error', label: $t('Cr_Log_Error') },
              ]" @change="loadLogs(1)"
            />
            <a-popconfirm :title="$t('Int_Clear_All_Logs_Confirm', { name: 'OpenCart' })" :ok-text="$t('Yes')" :cancel-text="$t('No')" @confirm="clearLogs">
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
              <li>{{ $t('Oc_Guide_1', { company }) }}</li>
              <i18n-t keypath="Oc_Guide_2" tag="li">
                <template #installer><strong>Extensions → Installer</strong></template>
                <template #modules><strong>Extensions → Extensions → Modules</strong></template>
              </i18n-t>
              <i18n-t keypath="Oc_Guide_3" tag="li">
                <template #name><strong>{{ $t('Oc_Connector', { company }) }}</strong></template>
              </i18n-t>
              <i18n-t keypath="Oc_Guide_4" tag="li">
                <template #test><strong>{{ $t('Test_Connection') }}</strong></template>
              </i18n-t>
              <li>{{ $t('Oc_Guide_5', { company }) }}</li>
              <li>{{ $t('Oc_Guide_6') }}</li>
            </ol>
            <router-link to="/integrations/opencart/guide"><a-button type="primary" style="margin-bottom: 12px">{{ $t('Oc_Full_Guide') }}</a-button></router-link>
            <a-alert type="info" show-icon style="margin-top: 8px" :message="$t('Oc_Identity_Title')" :description="$t('Oc_Identity_Desc')" />
            <a-alert type="warning" show-icon style="margin-top: 8px" :message="$t('Oc_Options_Title')" :description="$t('Oc_Options_Desc')" />
            <a-alert type="info" show-icon style="margin-top: 8px" :message="$t('Oc_Cancel_Title')" :description="$t('Oc_Cancel_Desc')" />
            <a-alert type="info" show-icon style="margin-top: 8px" :message="$t('Oc_Opencart3_Title')" :description="$t('Oc_Opencart3_Desc')" />
          </a-card>
        </a-tab-pane>
      </a-tabs>
    </a-card>
  </div>
</template>

<script setup>
/**
 * OpenCart integration page. Endpoints (all under /api, feature-gated by
 * tenant.feature:opencart): GET/POST opencart/settings,
 * POST opencart/test-connection (also registers the webhook),
 * GET opencart/connector (the extension zip), GET opencart/stats,
 * POST opencart/reset-mappings, POST opencart/sync/{products|inventory|orders},
 * GET/DELETE opencart/logs.
 *
 * The store list and order statuses live in the store, so a connected page
 * asks for them when it opens; if the store is down those cards just stay away.
 */
import { ref, computed, h, defineComponent, onMounted } from 'vue';
import { message, Tag as ATagC, Spin as ASpinC } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import {
  GlobalOutlined, UploadOutlined, DownloadOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import { useFormat } from '../../composables/useFormat';
import { useBatchSync, COUNTER_LABELS } from '../../composables/useBatchSync';
import http from '../../lib/http';

const { t } = useI18n();
const { date } = useFormat();

const isLoading = ref(true);
const saving = ref(false);
const testing = ref(false);
const downloading = ref(0);
const tab = ref('connection');
const state = ref({ configured: false, enabled: false, warehouses: [] });
const form = ref({
  enabled: false, store_url: '', secret: '', store_id: 0, warehouse_id: null, code_source: 'model',
  connector_link: '',
  auto_sync_products: false, auto_sync_stock: true, auto_import_orders: true,
  commit_status_ids: [], paid_status_ids: [], cancel_status_ids: [],
});
const remote = ref({ stores: [], order_statuses: [] });
const stats = ref({});
const pullStock = ref(true);
const ordersWarehouseId = ref(null);
// Thirty days back, so a first pull does not bring in the whole history.
const ordersSince = ref(new Date(Date.now() - 30 * 86400000).toISOString().slice(0, 10));
const logs = ref({ data: [], current_page: 1, per_page: 20, total: 0 });
const logLevel = ref('');

const sync = useBatchSync();

const company = computed(() => state.value.company_name || '');
const stores = computed(() => remote.value.stores || []);
const statusOptions = computed(() => (remote.value.order_statuses || []).map(s => ({ value: s.id, label: s.name })));

// Model, then OpenCart's identifier fields. Connector 1.1.0 reports them all; 1.0.0 the SKU only.
const codeSources = computed(() => [
  { value: 'model', label: t('Oc_Code_Source_Model') },
  { value: 'sku', label: t('Oc_Code_Source_Sku') },
  ...['upc', 'ean', 'jan', 'isbn', 'mpn'].map(v => ({ value: v, label: v.toUpperCase() })),
]);
const codeSourceNeedsConnector = computed(() => {
  if (['model', 'sku'].includes(form.value.code_source) || !state.value.connector_version) return false;
  const v = String(state.value.connector_version).split('.').map(n => parseInt(n, 10) || 0);
  return v[0] < 1 || (v[0] === 1 && (v[1] || 0) < 1);
});

const switches = computed(() => [
  { key: 'auto_import_orders', title: t('Oc_Auto_Orders'), help: t('Oc_Auto_Orders_Help') },
  { key: 'auto_sync_stock', title: t('Oc_Auto_Stock'), help: t('Oc_Auto_Stock_Help') },
  { key: 'auto_sync_products', title: t('Oc_Auto_Products'), help: t('Oc_Auto_Products_Help') },
]);

const statusFields = computed(() => [
  { key: 'commit_status_ids', label: t('Oc_Commit_Statuses'), help: t('Oc_Commit_Statuses_Help') },
  { key: 'paid_status_ids', label: t('Oc_Paid_Statuses'), help: t('Oc_Paid_Statuses_Help') },
  { key: 'cancel_status_ids', label: t('Oc_Cancel_Statuses'), help: t('Oc_Cancel_Statuses_Help') },
]);

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
    state.value = await http.get('opencart/settings');
    form.value = {
      enabled: !!state.value.enabled,
      store_url: state.value.store_url || '',
      secret: '',
      store_id: state.value.store_id || 0,
      warehouse_id: state.value.warehouse_id || null,
      code_source: state.value.code_source || 'model',
      connector_link: state.value.connector_link || '',
      auto_sync_products: !!state.value.auto_sync_products,
      auto_sync_stock: !!state.value.auto_sync_stock,
      auto_import_orders: !!state.value.auto_import_orders,
      commit_status_ids: state.value.commit_status_ids || [],
      paid_status_ids: state.value.paid_status_ids || [],
      cancel_status_ids: state.value.cancel_status_ids || [],
    };
    if (state.value.configured) loadStats();
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    isLoading.value = false;
  }
}

async function loadStats() {
  try {
    stats.value = await http.get('opencart/stats');
  } catch (e) { /* tiles show em-dashes */ }
}

async function save() {
  saving.value = true;
  try {
    const { secret, ...payload } = form.value;
    payload.store_url = (payload.store_url || '').trim() || null;
    payload.connector_link = (payload.connector_link || '').trim() || null;
    // The status lists only mean something once the store's statuses are
    // known; before that the connection test fills them in.
    if (!statusOptions.value.length) {
      delete payload.commit_status_ids;
      delete payload.paid_status_ids;
      delete payload.cancel_status_ids;
    }
    // The secret is write-only: send it only when the admin typed a new one.
    if (secret) payload.secret = secret.trim();
    await http.post('opencart/settings', payload);
    message.success(t('Successfully_Updated'));
    await load();
  } catch (e) {
    message.error(e?.data?.errors?.store_url?.[0] || e?.data?.errors?.connector_link?.[0] || e?.data?.message || t('InvalidData'));
  } finally {
    saving.value = false;
  }
}

async function testConnection() {
  testing.value = true;
  try {
    remote.value = await http.post('opencart/test-connection');
    message.success(t('Int_Connected_To_Dot', { name: 'OpenCart' }));
    await load();
  } catch (e) {
    message.error(e?.data?.error || e?.data?.message || t('Connection_Test_Failed'));
  } finally {
    testing.value = false;
  }
}

async function downloadConnector(version) {
  downloading.value = version;
  try {
    await http.download('opencart/connector?version=' + version, 'pos_connector.ocmod.zip');
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    downloading.value = 0;
  }
}

async function resetMappings(entityType) {
  try {
    const res = await http.post('opencart/reset-mappings', { entity_type: entityType });
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

function pullOrders() {
  const params = { since: ordersSince.value };
  if (ordersWarehouseId.value) params.warehouse_id = ordersWarehouseId.value;
  return runSync('opencart/sync/orders', params, 'pull');
}

async function loadLogs(page = 1) {
  try {
    const query = 'opencart/logs?per_page=20&page=' + page + (logLevel.value ? '&level=' + logLevel.value : '');
    logs.value = await http.get(query);
  } catch (e) { /* table stays empty */ }
}

async function clearLogs() {
  try {
    await http.delete('opencart/logs');
    loadLogs(1);
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  }
}

async function loadStoreDetails() {
  try {
    remote.value = await http.post('opencart/test-connection');
  } catch (e) { /* the Test Connection button reports the reason */ }
}

onMounted(async () => {
  loadLogs(1);
  await load();
  if (state.value.configured) loadStoreDetails();
});

// ---- small local render components (same pattern as SallaSettings.vue) ----

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
.switch-row {
  display: flex;
  gap: 12px;
  align-items: flex-start;
  margin-bottom: 14px;
}
.guide {
  padding-left: 20px;
  line-height: 2;
  margin: 0 0 12px;
}
</style>
