<template>
  <div class="page">
    <PageHeader title="Xero" :breadcrumb="[$t('Integrations'), 'Xero']">
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
        <a-avatar shape="square" :size="48" :style="{ background: 'rgba(19,181,234,0.14)', color: '#13b5ea' }">
          <template #icon><CalculatorOutlined /></template>
        </a-avatar>
        <div>
          <div class="hero-title">{{ $t('Xero_Hero_Title') }}</div>
          <div class="muted">{{ $t('Xero_Hero_Desc') }}</div>
        </div>
      </div>

      <a-tabs v-model:activeKey="tab">
        <!-- ============================ Connection ============================ -->
        <a-tab-pane key="connection" :tab="$t('Connection')">
          <a-row :gutter="[16, 16]">
            <a-col :xs="24" :lg="12">
              <a-card size="small" :title="$t('Xero_App_Credentials_Accounts')" style="height: 100%">
                <a-form layout="vertical">
                  <a-form-item :label="$t('Client_ID')">
                    <a-input v-model:value="form.client_id" :placeholder="$t('Xero_From_Your_App')" />
                  </a-form-item>
                  <a-form-item :label="$t('Client_Secret')">
                    <a-input-password
                      v-model:value="form.client_secret"
                      :placeholder="state.has_client_secret ? $t('Int_Saved_Paste_New_Value') : $t('Xero_From_Your_App')"
                      autocomplete="off"
                    />
                  </a-form-item>
                  <a-form-item :label="$t('Xero_Sales_Account_Code')">
                    <a-input v-model:value="form.sales_account_code" placeholder="200" style="max-width: 160px" />
                    <div class="muted" style="margin-top: 4px">{{ $t('Xero_Sales_Account_Help') }}</div>
                  </a-form-item>
                  <a-form-item :label="$t('Xero_Payment_Account_Code')">
                    <a-input v-model:value="form.payment_account_code" :placeholder="$t('Xero_Payment_Code_Placeholder')" style="max-width: 160px" />
                    <div class="muted" style="margin-top: 4px">{{ $t('Xero_Payment_Account_Help') }}</div>
                  </a-form-item>
                  <a-space direction="vertical" size="small" style="margin-bottom: 16px">
                    <a-space align="center">
                      <a-switch v-model:checked="form.auto_sync" />
                      <span>{{ $t('Xero_Auto_Sync') }} <span class="muted">{{ $t('Xero_Auto_Sync_Hint') }}</span></span>
                    </a-space>
                    <a-space align="center">
                      <a-switch v-model:checked="form.enabled" />
                      <span>{{ $t('Int_Enable_Sync', { name: 'Xero' }) }}</span>
                    </a-space>
                  </a-space>
                  <div>
                    <a-button type="primary" :loading="saving" @click="save">{{ $t('Save') }}</a-button>
                  </div>
                </a-form>
              </a-card>
            </a-col>

            <a-col :xs="24" :lg="12">
              <a-card size="small" :title="$t('Xero_Org_Connection')" style="margin-bottom: 16px">
                <a-descriptions :column="1" size="small" bordered>
                  <a-descriptions-item :label="$t('Xero_Organisation')">{{ state.tenant_name || '—' }}</a-descriptions-item>
                  <a-descriptions-item :label="$t('Last_Sync')">{{ state.last_sync_at ? date(state.last_sync_at) : '—' }}</a-descriptions-item>
                </a-descriptions>
                <a-space wrap style="margin-top: 16px">
                  <a-button type="primary" :disabled="!state.client_id || !state.has_client_secret" @click="connect">
                    <template #icon><LinkOutlined /></template>
                    {{ state.connected ? $t('Int_Reconnect_With', { name: 'Xero' }) : $t('Int_Connect_With', { name: 'Xero' }) }}
                  </a-button>
                  <a-button :disabled="!state.connected" :loading="testing" @click="testConnection">{{ $t('Test_Connection') }}</a-button>
                  <a-popconfirm
                    v-if="state.connected"
                    :title="$t('Xero_Disconnect_Confirm')"
                    :ok-text="$t('Yes')" :cancel-text="$t('No')" @confirm="disconnect"
                  >
                    <a-button danger>{{ $t('Disconnect') }}</a-button>
                  </a-popconfirm>
                </a-space>
              </a-card>

              <a-card size="small" :title="$t('Xero_Redirect_Uri_Title')">
                <a-input-group compact style="display: flex">
                  <a-input :value="state.callback_url" readonly style="flex: 1" />
                  <a-button @click="copy(state.callback_url)"><CopyOutlined /></a-button>
                </a-input-group>
                <i18n-t keypath="Xero_Redirect_Help" tag="div" class="muted" style="margin-top: 8px">
                  <template #link><a href="https://developer.xero.com/app/manage" target="_blank" rel="noopener">developer.xero.com</a></template>
                </i18n-t>
              </a-card>
            </a-col>
          </a-row>
        </a-tab-pane>

        <!-- ============================ Sync ============================ -->
        <a-tab-pane key="sync" :tab="$t('Sync')" :disabled="!state.connected">
          <StatsRow
            :stats="stats"
            :items="[['clients_total', $t('Total_Customers')], ['contacts_mapped', $t('Xero_Contacts_In_Xero')], ['sales_total', $t('Total_Sales')], ['invoices_synced', $t('Xero_Invoices_In_Xero')], ['payments_synced', $t('Xero_Payments_Recorded')]]"
          />

          <a-card size="small" :title="$t('Int_Push_Customers_To', { name: 'Xero' })" style="margin-bottom: 16px">
            <p class="muted">{{ $t('Xero_Push_Customers_Desc') }}</p>
            <a-space>
              <a-button type="primary" :disabled="sync.running.value" @click="runSync('xero/sync/contacts', {}, 'push')">
                <template #icon><UploadOutlined /></template>{{ $t('Int_Push_Customers') }}
              </a-button>
              <a-button v-if="sync.running.value" danger @click="sync.cancel()">{{ $t('Cancel') }}</a-button>
            </a-space>
          </a-card>

          <a-card size="small" :title="$t('Xero_Push_Sales_As_Invoices')" style="margin-bottom: 16px">
            <p class="muted">
              {{ $t('Xero_Push_Invoices_Desc') }}
            </p>
            <a-space>
              <a-button type="primary" :disabled="sync.running.value" @click="runSync('xero/sync/invoices', {}, 'push')">
                <template #icon><UploadOutlined /></template>{{ $t('Xero_Push_Invoices') }}
              </a-button>
              <a-button v-if="sync.running.value" danger @click="sync.cancel()">{{ $t('Cancel') }}</a-button>
              <a-popconfirm :title="$t('Xero_Reset_Contact_Confirm')" :ok-text="$t('Yes')" :cancel-text="$t('No')" @confirm="resetMappings('contact')">
                <a-button :disabled="sync.running.value">{{ $t('Xero_Reset_Contact_Links') }}</a-button>
              </a-popconfirm>
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
            <a-popconfirm :title="$t('Int_Clear_All_Logs_Confirm', { name: 'Xero' })" :ok-text="$t('Yes')" :cancel-text="$t('No')" @confirm="clearLogs">
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
              <i18n-t keypath="Xero_Guide_1" tag="li">
                <template #webApp><strong>{{ $t('Xero_Web_App') }}</strong></template>
                <template #link><a href="https://developer.xero.com/app/manage" target="_blank" rel="noopener">developer.xero.com</a></template>
              </i18n-t>
              <li>{{ $t('Xero_Guide_2') }}</li>
              <i18n-t keypath="Xero_Guide_3" tag="li">
                <template #clientId><strong>{{ $t('Client_ID') }}</strong></template>
                <template #clientSecret><strong>{{ $t('Client_Secret') }}</strong></template>
              </i18n-t>
              <i18n-t keypath="Xero_Guide_4" tag="li">
                <template #connect><strong>{{ $t('Int_Connect_With', { name: 'Xero' }) }}</strong></template>
              </i18n-t>
              <li>{{ $t('Xero_Guide_5') }}</li>
              <li>{{ $t('Xero_Guide_6') }}</li>
            </ol>
            <a-alert
              type="info" show-icon style="margin-top: 8px"
              :message="$t('Xero_Tax_Inclusive_Title')"
              :description="$t('Xero_Tax_Inclusive_Desc')"
            />
          </a-card>
        </a-tab-pane>
      </a-tabs>
    </a-card>
  </div>
</template>

<script setup>
/**
 * Xero integration page. Endpoints (all under /api, feature-gated by
 * tenant.feature:xero): GET/POST xero/settings, POST xero/disconnect,
 * POST xero/test-connection, GET xero/stats, POST xero/reset-mappings,
 * POST xero/sync/{contacts|invoices}, GET/DELETE xero/logs.
 * OAuth runs over web routes: /xero/connect -> Xero -> /xero/callback,
 * which redirects back here with ?connected=1 or ?xero_error=...
 */
import { ref, h, defineComponent, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import { message, Tag as ATagC, Spin as ASpinC } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import {
  CalculatorOutlined, UploadOutlined, LinkOutlined, CopyOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import { useFormat } from '../../composables/useFormat';
import { useBatchSync, COUNTER_LABELS } from '../../composables/useBatchSync';
import http from '../../lib/http';

const { t } = useI18n();
const { date } = useFormat();
const route = useRoute();

const isLoading = ref(true);
const saving = ref(false);
const testing = ref(false);
const tab = ref('connection');
const state = ref({ connected: false, enabled: false });
const form = ref({ enabled: false, client_id: '', client_secret: '', sales_account_code: '200', payment_account_code: '', auto_sync: false });
const stats = ref({});
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
    state.value = await http.get('xero/settings');
    form.value = {
      enabled: !!state.value.enabled,
      client_id: state.value.client_id || '',
      client_secret: '',
      sales_account_code: state.value.sales_account_code || '200',
      payment_account_code: state.value.payment_account_code || '',
      auto_sync: !!state.value.auto_sync,
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
    stats.value = await http.get('xero/stats');
  } catch (e) { /* tiles show em-dashes */ }
}

async function save() {
  saving.value = true;
  try {
    const payload = {
      enabled: !!form.value.enabled,
      client_id: (form.value.client_id || '').trim() || null,
      sales_account_code: (form.value.sales_account_code || '').trim() || '200',
      payment_account_code: (form.value.payment_account_code || '').trim() || null,
      auto_sync: !!form.value.auto_sync,
    };
    // The secret is write-only: send it only when the admin typed a new value.
    if (form.value.client_secret) payload.client_secret = form.value.client_secret.trim();
    await http.post('xero/settings', payload);
    message.success(t('Successfully_Updated'));
    await load();
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    saving.value = false;
  }
}

function connect() {
  window.location.href = state.value.connect_url || '/xero/connect';
}

async function testConnection() {
  testing.value = true;
  try {
    const res = await http.post('xero/test-connection');
    message.success(t('Int_Connected_To', { name: res?.organisation?.name || 'Xero' }));
  } catch (e) {
    message.error(e?.data?.error || e?.data?.message || t('Connection_Test_Failed'));
  } finally {
    testing.value = false;
  }
}

async function disconnect() {
  try {
    await http.post('xero/disconnect');
    message.success(t('Disconnected'));
    await load();
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  }
}

async function resetMappings(entityType) {
  try {
    const res = await http.post('xero/reset-mappings', { entity_type: entityType });
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
    const query = 'xero/logs?per_page=20&page=' + page + (logLevel.value ? '&level=' + logLevel.value : '');
    logs.value = await http.get(query);
  } catch (e) { /* table stays empty */ }
}

async function clearLogs() {
  try {
    await http.delete('xero/logs');
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
  if (route.query.connected === '1') message.success(t('Xero_Org_Connected'));
  if (route.query.xero_error) message.error(String(route.query.xero_error));
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
