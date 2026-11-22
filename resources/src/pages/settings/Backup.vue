<template>
  <div class="page">
    <!-- No PageHeader actions: the header is hidden when this page is
         embedded in System Settings — Generate lives on the card instead. -->
    <PageHeader :title="$t('Backup')" :breadcrumb="[$t('Settings'), $t('Backup')]" />

    <!-- Plan policy (read-only): automatic backups + retention, set per plan by the platform. -->
    <a-alert v-if="policy" type="info" show-icon style="margin-bottom: 16px">
      <template #message>{{ tr('Backup_Policy_Title', 'Backup & data retention (set by your plan)') }}</template>
      <template #description>
        <div>{{ autoBackupLine }}</div>
        <ul style="margin: 6px 0 4px; padding-inline-start: 18px">
          <li v-for="row in retentionRows" :key="row.key">{{ row.label }}: {{ row.value }}</li>
        </ul>
        <div style="opacity: .75">{{ tr('Retention_Protected', 'Sales, purchases, payments, accounting, stock, products, customers and suppliers are never deleted automatically.') }}</div>
      </template>
    </a-alert>

    <a-card size="small" :title="$t('Backup')" :body-style="{ padding: 0 }" style="margin-bottom: 16px">
      <template #extra>
        <a-button type="primary" :loading="generating" @click="generate">
          <template #icon><CloudDownloadOutlined /></template>
          {{ $t('GenerateBackup') || 'Generate Backup' }}
        </a-button>
      </template>
      <a-table
        :columns="columns" :data-source="backups" :loading="loading"
        :pagination="false" :row-key="r => r.date" size="middle"
      >
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'type'">
            <a-tag :color="record.type === 'auto' ? 'blue' : 'default'">
              {{ record.type === 'auto' ? tr('Backup_Automatic', 'Automatic') : tr('Backup_Manual', 'Manual') }}
            </a-tag>
          </template>
          <template v-else-if="column.key === 'actions'">
            <a-tooltip :title="$t('Download')">
              <a-button type="text" size="small" :loading="downloading === record.date" @click="downloadBackup(record)">
                <template #icon><DownloadOutlined /></template>
              </a-button>
            </a-tooltip>
            <a-tooltip :title="$t('Del')">
              <a-button type="text" size="small" danger @click="remove(record)">
                <template #icon><DeleteOutlined /></template>
              </a-button>
            </a-tooltip>
          </template>
        </template>
        <template #emptyText>
          <a-empty :description="$t('NodataAvailable')" style="padding: 32px 0" />
        </template>
      </a-table>
    </a-card>

    <!-- Cloud storage destination -->
    <a-card size="small" :title="$t('Bk_Cloud_Backup')" v-if="setting.id">
      <a-form layout="vertical">
        <a-row :gutter="16">
          <a-col :span="24">
            <a-checkbox v-model:checked="setting.backup_cloud_enabled" style="margin-bottom: 12px">
              {{ $t('Bk_Enable_Cloud_Backup') }}
            </a-checkbox>
          </a-col>
          <template v-if="setting.backup_cloud_enabled">
            <a-col :xs="24" :md="8">
              <a-form-item :label="$t('Provider')">
                <a-select
                  v-model:value="setting.backup_cloud_provider"
                  :options="[
                    { value: 's3', label: 'Amazon S3' },
                    { value: 'gdrive', label: 'Google Drive' },
                  ]"
                />
              </a-form-item>
            </a-col>
            <a-col :xs="24" :md="8">
              <a-form-item :label="$t('Path')">
                <a-input v-model:value="setting.backup_cloud_path" />
              </a-form-item>
            </a-col>
            <template v-if="setting.backup_cloud_provider === 's3'">
              <a-col :xs="24" :md="8">
                <a-form-item :label="$t('Bk_S3_Bucket')"><a-input v-model:value="setting.backup_s3_bucket" /></a-form-item>
              </a-col>
              <a-col :xs="24" :md="8">
                <a-form-item :label="$t('Bk_S3_Region')"><a-input v-model:value="setting.backup_s3_region" /></a-form-item>
              </a-col>
              <a-col :xs="24" :md="8">
                <a-form-item :label="$t('Sys_Access_Key')"><a-input v-model:value="setting.backup_s3_access_key" /></a-form-item>
              </a-col>
              <a-col :xs="24" :md="8">
                <a-form-item :label="$t('Sys_Secret_Key')">
                  <a-input-password v-model:value="setting.backup_s3_secret_key" :placeholder="$t('LeaveBlank')" />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="8">
                <a-form-item :label="$t('Endpoint')"><a-input v-model:value="setting.backup_s3_endpoint" /></a-form-item>
              </a-col>
              <a-col :xs="24" :md="8">
                <a-form-item label=" ">
                  <a-checkbox v-model:checked="setting.backup_s3_path_style">{{ $t('Bk_Path_Style_Endpoint') }}</a-checkbox>
                </a-form-item>
              </a-col>
            </template>
            <template v-else-if="setting.backup_cloud_provider === 'gdrive'">
              <a-col :xs="24" :md="8">
                <a-form-item :label="$t('Sys_Folder_ID')"><a-input v-model:value="setting.backup_gdrive_folder_id" /></a-form-item>
              </a-col>
              <a-col :xs="24" :md="8">
                <a-form-item :label="$t('Client_ID')"><a-input v-model:value="setting.backup_gdrive_client_id" /></a-form-item>
              </a-col>
              <a-col :xs="24" :md="8">
                <a-form-item :label="$t('Client_Secret')">
                  <a-input-password v-model:value="setting.backup_gdrive_client_secret" :placeholder="$t('LeaveBlank')" />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="12">
                <a-form-item :label="$t('Access_Token')">
                  <a-input-password v-model:value="setting.backup_gdrive_access_token" :placeholder="$t('LeaveBlank')" />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="12">
                <a-form-item :label="$t('Sys_Refresh_Token')">
                  <a-input-password v-model:value="setting.backup_gdrive_refresh_token" :placeholder="$t('LeaveBlank')" />
                </a-form-item>
              </a-col>
            </template>
          </template>
        </a-row>
        <a-button type="primary" :loading="saving" @click="saveSettings">{{ $t('submit') }}</a-button>
      </a-form>
    </a-card>
  </div>
</template>

<script setup>
/**
 * List GET get_backup → {backups (rows keyed by date = file name, with type
 * manual|auto, size, created_at), totalRows, policy (the plan's backup and
 * retention settings, null without a plan)}; generate GET generate_new_backup
 * (answers {success:false, error|message} on failure — surfaced verbatim);
 * download GET download_backup/{date} (authenticated; backups are private);
 * delete DELETE delete_backup/{date}. Cloud destination
 * lives in the main settings row: GET get_Settings_data?include_secrets=1,
 * saved via POST settings/{id} as FormData with backup_* fields (booleans
 * as 1/0). Field labels literal English like legacy.
 */
import { ref, computed, createVNode, onMounted } from 'vue';
import { message, Modal } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { CloudDownloadOutlined, DeleteOutlined, DownloadOutlined, ExclamationCircleOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';

const { t } = useI18n();

// Keys added with this page may not have been synced into a tenant's
// translation table yet — fall back to the English text rather than the key.
function tr(key, fallback, params = {}) {
  const out = t(key, params);
  return out === key ? fallback.replace(/\{(\w+)\}/g, (_, k) => params[k] ?? '') : out;
}

const loading = ref(true);
const generating = ref(false);
const saving = ref(false);
const downloading = ref(null);
const backups = ref([]);
const policy = ref(null);
const setting = ref({});

const columns = computed(() => [
  { title: t('date'), dataIndex: 'date', key: 'date' },
  { title: t('Type'), key: 'type', width: 120 },
  { title: t('Size'), dataIndex: 'size', key: 'size', width: 120 },
  { title: t('Created_at'), dataIndex: 'created_at', key: 'created_at', width: 180 },
  { title: t('Action'), key: 'actions', width: 110, align: 'center' },
]);

const autoBackupLine = computed(() => {
  const p = policy.value;
  if (!p || p.backup_frequency === 'off') {
    return tr('Backup_Policy_Off', 'Your plan does not include automatic backups. Use Generate Backup to create one.');
  }
  const frequency = tr(`Backup_Freq_${p.backup_frequency}`, p.backup_frequency);
  return tr('Backup_Policy_Auto', 'Automatic backups: {frequency}, each kept for {days} days (the newest is always kept).',
    { frequency, days: p.backup_retention_days });
});

const retentionRows = computed(() => {
  const p = policy.value || {};
  const value = (days) => (days > 0
    ? tr('Retention_Days', 'deleted after {days} days', { days })
    : tr('Keep_Forever', 'kept forever'));
  return [
    { key: 'log', label: tr('Retention_Logs', 'Logs & sync history'), value: value(p.log_retention_days) },
    { key: 'audit', label: tr('Retention_Audit', 'Audit log'), value: value(p.audit_retention_days) },
    { key: 'msg', label: tr('Retention_Messages', 'Notifications & message history'), value: value(p.message_retention_days) },
    { key: 'tmp', label: tr('Retention_Temp', 'Temporary data (POS drafts, expired logins)'), value: value(p.temp_retention_days) },
  ];
});

async function loadBackups() {
  loading.value = true;
  try {
    const data = await http.get('get_backup');
    backups.value = data.backups || [];
    policy.value = data.policy || null;
  } catch (e) {
    message.error(t('InvalidData'));
  } finally {
    loading.value = false;
  }
}

async function loadSettings() {
  try {
    const data = await http.get('get_Settings_data', { include_secrets: 1 });
    const s = data.settings || {};
    setting.value = {
      ...s,
      backup_cloud_enabled: !!Number(s.backup_cloud_enabled),
      backup_s3_path_style: !!Number(s.backup_s3_path_style),
    };
  } catch (e) { /* cloud card stays hidden */ }
}

async function generate() {
  generating.value = true;
  try {
    const data = await http.get('generate_new_backup');
    if (data && data.success === false) {
      message.error(data.error || data.message || t('Failed'));
    } else {
      message.success(t('Success'));
    }
    loadBackups();
  } catch (e) {
    message.error(e?.data?.error || e?.data?.message || t('InvalidData'));
  } finally {
    generating.value = false;
  }
}

async function downloadBackup(record) {
  downloading.value = record.date;
  try {
    await http.download(`download_backup/${encodeURIComponent(record.date)}`, record.date);
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    downloading.value = null;
  }
}

function remove(record) {
  Modal.confirm({
    title: t('Delete_Title'),
    icon: createVNode(ExclamationCircleOutlined),
    content: record.date,
    okType: 'danger',
    okText: t('Delete_confirmButtonText'),
    cancelText: t('Delete_cancelButtonText'),
    async onOk() {
      try {
        await http.delete(`delete_backup/${encodeURIComponent(record.date)}`);
        message.success(t('Deleted_in_successfully'));
        loadBackups();
      } catch (e) {
        message.error(t('InvalidData'));
      }
    },
  });
}

async function saveSettings() {
  saving.value = true;
  const s = setting.value;
  const fd = new FormData();
  fd.append('backup_cloud_enabled', s.backup_cloud_enabled ? 1 : 0);
  fd.append('backup_cloud_provider', s.backup_cloud_provider || '');
  fd.append('backup_cloud_path', s.backup_cloud_path || '');
  fd.append('backup_s3_bucket', s.backup_s3_bucket || '');
  fd.append('backup_s3_region', s.backup_s3_region || '');
  fd.append('backup_s3_access_key', s.backup_s3_access_key || '');
  fd.append('backup_s3_secret_key', s.backup_s3_secret_key || '');
  fd.append('backup_s3_endpoint', s.backup_s3_endpoint || '');
  fd.append('backup_s3_path_style', s.backup_s3_path_style ? 1 : 0);
  fd.append('backup_gdrive_folder_id', s.backup_gdrive_folder_id || '');
  fd.append('backup_gdrive_access_token', s.backup_gdrive_access_token || '');
  fd.append('backup_gdrive_refresh_token', s.backup_gdrive_refresh_token || '');
  fd.append('backup_gdrive_client_id', s.backup_gdrive_client_id || '');
  fd.append('backup_gdrive_client_secret', s.backup_gdrive_client_secret || '');
  try {
    fd.append('_method', 'put'); // Route::resource('settings') — legacy spoofs PUT
    await http.postForm(`settings/${s.id}`, fd);
    message.success(t('Successfully_Updated'));
  } catch (e) {
    message.error(e?.data?.message || e?.data?.error || t('InvalidData'));
  } finally {
    saving.value = false;
  }
}

onMounted(() => {
  loadBackups();
  loadSettings();
});
</script>
