<template>
  <div class="page">
    <PageHeader title="PrestaShop" :breadcrumb="[$t('Int_Ecommerce_Platforms'), 'PrestaShop']">
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
        <a-avatar shape="square" :size="48" :style="{ background: 'rgba(223,0,103,0.12)', color: '#df0067' }">
          <template #icon><GlobalOutlined /></template>
        </a-avatar>
        <div>
          <div class="hero-title">{{ $t('Int_Store_Sync_Title', { name: 'PrestaShop' }) }}</div>
          <div class="muted">{{ $t('Presta_Hero_Desc') }}</div>
        </div>
      </div>

      <a-tabs v-model:activeKey="tab">
        <!-- ============================ Connection ============================ -->
        <a-tab-pane key="connection" :tab="$t('Connection')">
          <a-card size="small" :title="$t('Int_Store_Connection')" style="max-width: 640px">
            <a-form layout="vertical">
              <a-form-item :label="$t('Store_URL')">
                <a-input v-model:value="form.store_url" placeholder="https://store.example.com" />
              </a-form-item>
              <a-form-item :label="$t('Presta_Webservice_Key')">
                <a-input-password
                  v-model:value="form.api_key"
                  :placeholder="state.has_api_key ? $t('Int_Saved_Paste_New_Key') : $t('Presta_Key_Placeholder')"
                  autocomplete="off"
                />
                <div class="muted" style="margin-top: 4px">
                  {{ $t('Presta_Key_Help') }}
                </div>
              </a-form-item>
              <a-form-item :label="$t('Presta_Shop_Language_ID')">
                <a-input-number v-model:value="form.default_language_id" :min="1" style="width: 120px" />
                <div class="muted" style="margin-top: 4px">{{ $t('Presta_Language_Help') }}</div>
              </a-form-item>
              <a-form-item :label="$t('Int_Stock_Warehouse')">
                <a-select
                  v-model:value="form.warehouse_id"
                  :options="[{ value: null, label: $t('Int_All_Warehouses_Aggregate') }, ...state.warehouses.map(w => ({ value: w.id, label: w.name }))]"
                />
              </a-form-item>
              <a-space align="center" style="margin-bottom: 16px">
                <a-switch v-model:checked="form.enabled" />
                <span>{{ $t('Int_Enable_Sync', { name: 'PrestaShop' }) }}</span>
              </a-space>
              <div>
                <a-space wrap>
                  <a-button type="primary" :loading="saving" @click="save">{{ $t('Save') }}</a-button>
                  <a-button :disabled="!state.configured" :loading="testing" @click="testConnection">{{ $t('Test_Connection') }}</a-button>
                </a-space>
              </div>
            </a-form>
          </a-card>
        </a-tab-pane>

        <!-- ============================ Products ============================ -->
        <a-tab-pane key="products" :tab="$t('Products')" :disabled="!state.configured">
          <StatsRow :stats="stats" :items="[['products_total', $t('Total_Products')], ['products_mapped', $t('Int_Linked_To', { name: 'PrestaShop' })]]" />
          <a-card size="small" :title="$t('Int_Push_Products_To', { name: 'PrestaShop' })" style="margin-bottom: 16px">
            <p class="muted">
              {{ $t('Presta_Push_Desc') }}
            </p>
            <a-space>
              <a-button type="primary" :disabled="sync.running.value" @click="runSync('prestashop/sync/products?mode=push', {}, 'push')">
                <template #icon><UploadOutlined /></template>{{ $t('Int_Push_To', { name: 'PrestaShop' }) }}
              </a-button>
              <a-button v-if="sync.running.value" danger @click="sync.cancel()">{{ $t('Cancel') }}</a-button>
            </a-space>
          </a-card>
          <a-card size="small" :title="$t('Int_Pull_Products_From', { name: 'PrestaShop' })" style="margin-bottom: 16px">
            <p class="muted">{{ $t('Presta_Pull_Desc') }}</p>
            <a-space>
              <a-button :disabled="sync.running.value" @click="runSync('prestashop/sync/products?mode=pull', {}, 'pull')">
                <template #icon><DownloadOutlined /></template>{{ $t('Int_Pull_From', { name: 'PrestaShop' }) }}
              </a-button>
              <a-popconfirm :title="$t('Presta_Reset_Confirm')" :ok-text="$t('Yes')" :cancel-text="$t('No')" @confirm="resetMappings('product')">
                <a-button :disabled="sync.running.value">{{ $t('Int_Reset_Product_Links') }}</a-button>
              </a-popconfirm>
            </a-space>
          </a-card>
          <SyncProgress :sync="sync" />
        </a-tab-pane>

        <!-- ============================ Stock ============================ -->
        <a-tab-pane key="stock" :tab="$t('Stock')" :disabled="!state.configured">
          <a-card size="small" :title="$t('Int_Push_Stock_Levels_To', { name: 'PrestaShop' })" style="margin-bottom: 16px">
            <p class="muted">
              {{ $t('Presta_Stock_Desc') }}
              {{ state.warehouse_id ? $t('Int_Counting_Stock_Selected') : $t('Int_Counting_Stock_All') }}
            </p>
            <a-space>
              <a-button type="primary" :disabled="sync.running.value" @click="runSync('prestashop/sync/inventory', {}, 'push')">
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
          <a-card size="small" :title="$t('Int_Pull_Orders_From', { name: 'PrestaShop' })" style="margin-bottom: 16px">
            <i18n-t keypath="Presta_Orders_Desc" tag="p" class="muted">
              <template #ref><code>PS-XKBKZAB…</code></template>
            </i18n-t>
            <a-form layout="inline" style="margin-bottom: 12px">
              <a-form-item :label="$t('Int_Import_Into_Warehouse')">
                <a-select
                  v-model:value="ordersWarehouseId" style="min-width: 220px"
                  :options="[{ value: null, label: $t('Int_Default_Warehouse_Option') }, ...state.warehouses.map(w => ({ value: w.id, label: w.name }))]"
                />
              </a-form-item>
            </a-form>
            <a-space>
              <a-button type="primary" :disabled="sync.running.value" @click="runSync('prestashop/sync/orders', ordersWarehouseId ? { warehouse_id: ordersWarehouseId } : {}, 'pull')">
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
            <a-popconfirm :title="$t('Int_Clear_All_Logs_Confirm', { name: 'PrestaShop' })" :ok-text="$t('Yes')" :cancel-text="$t('No')" @confirm="clearLogs">
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
              <i18n-t keypath="Presta_Guide_1" tag="li">
                <template #path><strong>Advanced Parameters → Webservice</strong></template>
              </i18n-t>
              <i18n-t keypath="Presta_Guide_2" tag="li">
                <template #vae><strong>View/Add/Edit</strong></template>
                <template #products><code>products</code></template>
                <template #stock><code>stock_availables</code></template>
                <template #view><strong>View</strong></template>
                <template #orders><code>orders</code></template>
                <template #customers><code>customers</code></template>
              </i18n-t>
              <i18n-t keypath="Presta_Guide_3" tag="li">
                <template #test><strong>{{ $t('Test_Connection') }}</strong></template>
              </i18n-t>
              <li>{{ $t('Presta_Guide_4') }}</li>
              <li>{{ $t('Presta_Guide_5') }}</li>
            </ol>
            <a-alert
              type="info" show-icon style="margin-top: 8px"
              :message="$t('Presta_Identity_Title')"
              :description="$t('Presta_Identity_Desc')"
            />
          </a-card>
        </a-tab-pane>
      </a-tabs>
    </a-card>
  </div>
</template>

<script setup>
/**
 * PrestaShop integration page. Endpoints (all under /api, feature-gated by
 * tenant.feature:prestashop): GET/POST prestashop/settings,
 * POST prestashop/test-connection, GET prestashop/stats,
 * POST prestashop/reset-mappings, POST prestashop/sync/{products|inventory|orders},
 * GET/DELETE prestashop/logs. No OAuth: HTTP Basic with the webservice key.
 */
import { ref, h, defineComponent, onMounted } from 'vue';
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
const tab = ref('connection');
const state = ref({ configured: false, enabled: false, warehouses: [] });
const form = ref({ enabled: false, store_url: '', api_key: '', default_language_id: 1, warehouse_id: null });
const stats = ref({});
const ordersWarehouseId = ref(null);
const logs = ref({ data: [], current_page: 1, per_page: 20, total: 0 });
const logLevel = ref('');

const sync = useBatchSync();

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
    state.value = await http.get('prestashop/settings');
    form.value = {
      enabled: !!state.value.enabled,
      store_url: state.value.store_url || '',
      api_key: '',
      default_language_id: state.value.default_language_id || 1,
      warehouse_id: state.value.warehouse_id || null,
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
    stats.value = await http.get('prestashop/stats');
  } catch (e) { /* tiles show em-dashes */ }
}

async function save() {
  saving.value = true;
  try {
    const payload = {
      enabled: !!form.value.enabled,
      store_url: (form.value.store_url || '').trim() || null,
      default_language_id: form.value.default_language_id || 1,
      warehouse_id: form.value.warehouse_id,
    };
    // The key is write-only: send it only when the admin typed a new one.
    if (form.value.api_key) payload.api_key = form.value.api_key.trim();
    await http.post('prestashop/settings', payload);
    message.success(t('Successfully_Updated'));
    await load();
  } catch (e) {
    message.error(e?.data?.errors?.store_url?.[0] || e?.data?.message || t('InvalidData'));
  } finally {
    saving.value = false;
  }
}

async function testConnection() {
  testing.value = true;
  try {
    await http.post('prestashop/test-connection');
    message.success(t('Int_Connected_To_Dot', { name: 'PrestaShop' }));
  } catch (e) {
    message.error(e?.data?.error || e?.data?.message || t('Connection_Test_Failed'));
  } finally {
    testing.value = false;
  }
}

async function resetMappings(entityType) {
  try {
    const res = await http.post('prestashop/reset-mappings', { entity_type: entityType });
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
    const query = 'prestashop/logs?per_page=20&page=' + page + (logLevel.value ? '&level=' + logLevel.value : '');
    logs.value = await http.get(query);
  } catch (e) { /* table stays empty */ }
}

async function clearLogs() {
  try {
    await http.delete('prestashop/logs');
    loadLogs(1);
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  }
}

onMounted(() => {
  load();
  loadLogs(1);
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
.guide {
  padding-left: 20px;
  line-height: 2;
  margin: 0 0 12px;
}
</style>
