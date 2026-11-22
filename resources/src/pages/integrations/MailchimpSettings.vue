<template>
  <div class="page">
    <PageHeader title="Mailchimp" :breadcrumb="[$t('Integrations'), 'Mailchimp']">
      <template #actions>
        <a-tag v-if="!isLoading" :color="state.ready && state.enabled ? 'success' : 'default'" style="font-size: 13px; padding: 4px 12px">
          {{ state.ready ? (state.enabled ? $t('Enabled') : $t('Disabled')) : $t('Int_Not_Configured') }}
        </a-tag>
      </template>
    </PageHeader>

    <div v-if="isLoading" style="display: flex; justify-content: center; padding: 96px 0">
      <a-spin size="large" />
    </div>

    <a-card v-else size="small">
      <div class="hero">
        <a-avatar shape="square" :size="48" :style="{ background: 'rgba(226,164,0,0.14)', color: '#e2a400' }">
          <template #icon><MailOutlined /></template>
        </a-avatar>
        <div>
          <div class="hero-title">{{ $t('Mc_Hero_Title') }}</div>
          <div class="muted">{{ $t('Mc_Hero_Desc') }}</div>
        </div>
      </div>

      <a-tabs v-model:activeKey="tab">
        <!-- ============================ Connection ============================ -->
        <a-tab-pane key="connection" :tab="$t('Connection')">
          <a-card size="small" :title="$t('Mc_Account_Audience')" style="max-width: 640px">
            <a-form layout="vertical">
              <a-form-item :label="$t('API_Key')">
                <a-input-password
                  v-model:value="form.api_key"
                  :placeholder="state.has_api_key ? $t('Int_Saved_Paste_New_Key') : $t('Mc_Api_Key_Placeholder')"
                  autocomplete="off"
                />
              </a-form-item>
              <a-form-item :label="$t('Mc_Audience')">
                <a-input-group compact style="display: flex">
                  <a-select
                    v-model:value="form.list_id" style="flex: 1"
                    :options="lists.map(l => ({ value: l.id, label: l.name + (l.member_count != null ? ' ' + $t('Mc_N_Members', { n: l.member_count }) : '') }))"
                    :placeholder="state.list_name || $t('Mc_Load_Audiences_To_Pick')"
                  />
                  <a-button :disabled="!state.has_api_key && !form.api_key" :loading="loadingLists" @click="loadLists">{{ $t('Mc_Load_Audiences') }}</a-button>
                </a-input-group>
              </a-form-item>
              <a-form-item>
                <a-space direction="vertical" size="small">
                  <a-space align="center">
                    <a-switch v-model:checked="form.double_opt_in" />
                    <span>{{ $t('Mc_Double_Opt_In_Label') }} <span class="muted">{{ $t('Mc_Recommended_GDPR') }}</span></span>
                  </a-space>
                  <a-space align="center">
                    <a-switch v-model:checked="form.auto_sync" />
                    <span>{{ $t('Mc_Auto_Sync_New') }}</span>
                  </a-space>
                  <a-space align="center">
                    <a-switch v-model:checked="form.enabled" />
                    <span>{{ $t('Int_Enable_Sync', { name: 'Mailchimp' }) }}</span>
                  </a-space>
                </a-space>
              </a-form-item>
              <a-space wrap>
                <a-button type="primary" :loading="saving" @click="save">{{ $t('Save') }}</a-button>
                <a-button :disabled="!state.has_api_key" :loading="testing" @click="testConnection">{{ $t('Test_Connection') }}</a-button>
              </a-space>
            </a-form>
          </a-card>
        </a-tab-pane>

        <!-- ============================ Sync ============================ -->
        <a-tab-pane key="sync" :tab="$t('Sync')" :disabled="!state.ready">
          <StatsRow :stats="stats" :items="[['customers_total', $t('Total_Customers')], ['customers_with_email', $t('Mc_With_Email')]]" />
          <a-card size="small" :title="$t('Int_Push_Customers_To', { name: 'Mailchimp' })" style="margin-bottom: 16px">
            <p class="muted">
              {{ $t('Mc_Push_Desc') }}
            </p>
            <a-alert
              v-if="!form.double_opt_in" type="warning" show-icon style="margin-bottom: 12px; max-width: 720px"
              :message="$t('Mc_Double_Opt_In_Off')"
              :description="$t('Mc_Double_Opt_In_Off_Desc')"
            />
            <a-space>
              <a-button type="primary" :disabled="sync.running.value" @click="runSync">
                <template #icon><UploadOutlined /></template>{{ $t('Int_Push_Customers') }}
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
            <a-popconfirm :title="$t('Int_Clear_All_Logs_Confirm', { name: 'Mailchimp' })" :ok-text="$t('Yes')" :cancel-text="$t('No')" @confirm="clearLogs">
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
              <i18n-t keypath="Mc_Guide_1" tag="li">
                <template #path><strong>Account → Extras → API keys</strong></template>
                <template #suffix><code>-usX</code></template>
              </i18n-t>
              <i18n-t keypath="Mc_Guide_2" tag="li">
                <template #load><strong>{{ $t('Mc_Load_Audiences') }}</strong></template>
              </i18n-t>
              <i18n-t keypath="Mc_Guide_3" tag="li">
                <template #doi><strong>{{ $t('Mc_Double_Opt_In_Lower') }}</strong></template>
              </i18n-t>
              <i18n-t keypath="Mc_Guide_4" tag="li">
                <template #push><strong>{{ $t('Int_Push_Customers') }}</strong></template>
                <template #autoSync><strong>{{ $t('Mc_Auto_Sync_Lower') }}</strong></template>
              </i18n-t>
            </ol>
            <a-alert
              type="info" show-icon style="margin-top: 8px"
              :message="$t('Mc_Consent_Matters')"
              :description="$t('Mc_Consent_Desc')"
            />
          </a-card>
        </a-tab-pane>
      </a-tabs>
    </a-card>
  </div>
</template>

<script setup>
/**
 * Mailchimp integration page. Endpoints (all under /api, feature-gated by
 * tenant.feature:mailchimp): GET/POST mailchimp/settings,
 * POST mailchimp/test-connection, GET mailchimp/lists, GET mailchimp/stats,
 * POST mailchimp/sync/customers, GET/DELETE mailchimp/logs.
 */
import { ref, h, defineComponent, onMounted } from 'vue';
import { message, Tag as ATagC, Spin as ASpinC } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { MailOutlined, UploadOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import { useFormat } from '../../composables/useFormat';
import { useBatchSync, COUNTER_LABELS } from '../../composables/useBatchSync';
import http from '../../lib/http';

const { t } = useI18n();
const { date } = useFormat();

const isLoading = ref(true);
const saving = ref(false);
const testing = ref(false);
const loadingLists = ref(false);
const tab = ref('connection');
const state = ref({ configured: false, ready: false, enabled: false });
const form = ref({ enabled: false, api_key: '', list_id: null, double_opt_in: true, auto_sync: false });
const lists = ref([]);
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
    state.value = await http.get('mailchimp/settings');
    form.value = {
      enabled: !!state.value.enabled,
      api_key: '',
      list_id: state.value.list_id || null,
      double_opt_in: state.value.double_opt_in !== false,
      auto_sync: !!state.value.auto_sync,
    };
    if (state.value.ready) loadStats();
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    isLoading.value = false;
  }
}

async function loadStats() {
  try {
    stats.value = await http.get('mailchimp/stats');
  } catch (e) { /* tiles show em-dashes */ }
}

async function save() {
  saving.value = true;
  try {
    const chosen = lists.value.find(l => l.id === form.value.list_id);
    const payload = {
      enabled: !!form.value.enabled,
      list_id: form.value.list_id || null,
      list_name: chosen ? chosen.name : state.value.list_name || null,
      double_opt_in: !!form.value.double_opt_in,
      auto_sync: !!form.value.auto_sync,
    };
    // The key is write-only: send it only when the admin typed a new one.
    if (form.value.api_key) payload.api_key = form.value.api_key.trim();
    await http.post('mailchimp/settings', payload);
    message.success(t('Successfully_Updated'));
    await load();
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    saving.value = false;
  }
}

async function testConnection() {
  testing.value = true;
  try {
    await http.post('mailchimp/test-connection');
    message.success(t('Int_Connected_To_Dot', { name: 'Mailchimp' }));
  } catch (e) {
    message.error(e?.data?.error || e?.data?.message || t('Connection_Test_Failed'));
  } finally {
    testing.value = false;
  }
}

async function loadLists() {
  // Persist a freshly pasted key first so the lookup uses it.
  if (form.value.api_key) await save();
  loadingLists.value = true;
  try {
    const res = await http.get('mailchimp/lists');
    lists.value = res.lists || [];
    if (!lists.value.length) message.warning(t('Mc_No_Audiences'));
  } catch (e) {
    message.error(e?.data?.error || e?.data?.message || t('InvalidData'));
  } finally {
    loadingLists.value = false;
  }
}

async function runSync() {
  await sync.run('mailchimp/sync/customers', {}, 'push');
  loadStats();
}

async function loadLogs(page = 1) {
  try {
    const query = 'mailchimp/logs?per_page=20&page=' + page + (logLevel.value ? '&level=' + logLevel.value : '');
    logs.value = await http.get(query);
  } catch (e) { /* table stays empty */ }
}

async function clearLogs() {
  try {
    await http.delete('mailchimp/logs');
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
