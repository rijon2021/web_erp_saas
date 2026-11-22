<template>
  <div>
    <a-alert
      :type="connectionOk === true ? 'success' : connectionOk === false ? 'error' : 'info'"
      show-icon style="margin-bottom: 16px"
      :message="connectionOk === true ? $t('Connected') : connectionOk === false ? $t('Disconnected') : $t('Unknown')"
      :description="lastSyncText || undefined"
    />

    <a-form ref="formRef" :model="form" :rules="rules" layout="vertical" style="max-width: 720px">
      <a-form-item :label="$t('Store_URL') || 'Store URL'" name="store_url">
        <a-input v-model:value="form.store_url" placeholder="https://shop.example.com" />
      </a-form-item>
      <a-row :gutter="16">
        <a-col :xs="24" :md="12">
          <a-form-item :label="$t('Consumer_Key')" name="consumer_key">
            <a-input v-model:value="form.consumer_key" />
          </a-form-item>
        </a-col>
        <a-col :xs="24" :md="12">
          <a-form-item :label="$t('Consumer_Secret')" name="consumer_secret">
            <a-input-password v-model:value="form.consumer_secret" />
          </a-form-item>
        </a-col>
        <a-col :xs="24" :md="12">
          <a-form-item :label="$t('Woo_WordPress_Username')">
            <a-input v-model:value="form.wp_username" />
          </a-form-item>
        </a-col>
        <a-col :xs="24" :md="12">
          <a-form-item :label="$t('Woo_WordPress_App_Password')">
            <a-input-password v-model:value="form.wp_app_password" />
          </a-form-item>
        </a-col>
      </a-row>
      <a-space>
        <a-button type="primary" @click="onSubmit">{{ $t('Save') }}</a-button>
        <a-button :loading="connecting" @click="testConnection">{{ $t('Connection') }}</a-button>
      </a-space>
    </a-form>

    <a-card class="guide-card" :bordered="true" style="margin-top: 24px">
      <template #title>
        <span class="guide-card-title">
          <ThunderboltOutlined :style="{ color: token.colorInfo }" />
          {{ $t('Woo_Automatic_Sync') }}
        </span>
      </template>

      <p class="guide-intro">{{ $t('Woo_Auto_Sync_Intro') }}</p>

      <div class="auto-sync-row">
        <a-switch v-model:checked="form.auto_sync_products" />
        <div>
          <div class="auto-sync-label">{{ $t('Products') }}</div>
          <div class="guide-intro">{{ $t('Woo_Auto_Sync_Products_Help') }}</div>
        </div>
      </div>
      <div class="auto-sync-row">
        <a-switch v-model:checked="form.auto_sync_stock" />
        <div>
          <div class="auto-sync-label">{{ $t('Stock') }}</div>
          <div class="guide-intro">{{ $t('Woo_Auto_Sync_Stock_Help') }}</div>
        </div>
      </div>

      <a-button type="primary" style="margin-top: 8px" @click="onSubmit">{{ $t('Save') }}</a-button>
    </a-card>

    <!-- Only when the server describes tunable options (sync_options_meta). -->
    <a-card v-if="tuningFields.length" class="guide-card" :bordered="true" style="margin-top: 24px">
      <template #title>
        <span class="guide-card-title">
          <ControlOutlined :style="{ color: token.colorInfo }" />
          {{ $t('Woo_Sync_Tuning') }}
        </span>
      </template>
      <template #extra>
        <a-button size="small" :disabled="!tuningFields.length" @click="resetTuning">{{ $t('Reset') }}</a-button>
      </template>

      <p class="guide-intro">{{ $t('Woo_Sync_Tuning_Intro') }}</p>

      <a-collapse v-model:activeKey="tuningOpen" ghost>
        <a-collapse-panel v-for="g in tuningGroups" :key="g.key" :header="g.title">
          <a-row :gutter="16">
            <a-col v-for="f in fieldsIn(g.key)" :key="f.key" :xs="24" :md="12">
              <a-form-item :label="f.label" :help="f.hint">
                <a-input-number
                  v-if="f.type === 'integer'"
                  v-model:value="syncOptions[f.key]"
                  :min="f.min" :max="f.max" :placeholder="String(f.default)"
                  style="width: 100%"
                />
                <a-switch v-else-if="f.type === 'boolean'" v-model:checked="syncOptions[f.key]" />
                <a-input v-else v-model:value="syncOptions[f.key]" allow-clear placeholder="—" />
              </a-form-item>
            </a-col>
          </a-row>
        </a-collapse-panel>
      </a-collapse>

      <a-button type="primary" style="margin-top: 8px" @click="onSubmit">{{ $t('Save') }}</a-button>
    </a-card>

    <a-card class="guide-card" :bordered="true" style="margin-top: 24px">
      <template #title>
        <span class="guide-card-title">
          <BookOutlined :style="{ color: token.colorInfo }" />
          {{ $t('Woo_Sync_Guide') }}
        </span>
      </template>

      <div class="guide-section">
        <div class="guide-title">
          <KeyOutlined :style="{ color: token.colorInfo }" />
          {{ $t('Woo_Guide_Getting_Keys') }}
        </div>
        <ul class="guide-list">
          <li><SelectOutlined :style="{ color: token.colorPrimary }" /><span>{{ $t('Woo_Guide_Keys_1') }}</span></li>
          <li><PlusOutlined :style="{ color: token.colorPrimary }" /><span>{{ $t('Woo_Guide_Keys_2') }}</span></li>
          <li><GlobalOutlined :style="{ color: token.colorPrimary }" /><i18n-t keypath="Woo_Guide_Keys_3" tag="span"><template #example><a-typography-text code>https://yoursite.com</a-typography-text></template></i18n-t></li>
        </ul>
      </div>

      <a-divider class="guide-divider" />

      <div class="guide-section">
        <div class="guide-title">
          <UserOutlined :style="{ color: token.colorInfo }" />
          {{ $t('Woo_Guide_WP_Title') }}
        </div>
        <p class="guide-intro">{{ $t('Woo_Guide_WP_Intro') }}</p>
        <ul class="guide-list">
          <li><PictureOutlined :style="{ color: token.colorPrimary }" /><span>{{ $t('Woo_Guide_WP_1') }}</span></li>
          <li><KeyOutlined :style="{ color: token.colorPrimary }" /><span>{{ $t('Woo_Guide_WP_2') }}</span></li>
          <li><InfoCircleOutlined :style="{ color: token.colorPrimary }" /><span>{{ $t('Woo_Guide_WP_3') }}</span></li>
        </ul>
      </div>

      <a-divider class="guide-divider" />

      <div class="guide-section">
        <div class="guide-title">
          <SettingOutlined :style="{ color: token.colorInfo }" />
          {{ $t('Woo_Guide_How_To_Enable') }}
        </div>
        <ul class="guide-list">
          <li><CheckCircleOutlined :style="{ color: token.colorSuccess }" /><span>{{ $t('Woo_Guide_Enable_1') }}</span></li>
          <li><CloudSyncOutlined :style="{ color: token.colorSuccess }" /><span>{{ $t('Woo_Guide_Enable_2') }}</span></li>
          <li><ClockCircleOutlined :style="{ color: token.colorSuccess }" /><span>{{ $t('Woo_Guide_Enable_3') }}</span></li>
          <li><ThunderboltOutlined :style="{ color: token.colorSuccess }" /><span>{{ $t('Woo_Guide_Enable_4') }}</span></li>
        </ul>
      </div>

      <a-divider class="guide-divider" />

      <div class="guide-section">
        <div class="guide-title">
          <SyncOutlined :style="{ color: token.colorPrimary }" />
          {{ $t('Woo_Guide_Manual_Sync') }}
        </div>
        <ul class="guide-list">
          <li><SwapOutlined :style="{ color: token.colorPrimary }" /><span>{{ $t('Woo_Guide_Manual_1') }}</span></li>
          <li><MenuOutlined :style="{ color: token.colorPrimary }" /><span>{{ $t('Woo_Guide_Manual_2') }}</span></li>
        </ul>
      </div>

      <a-divider class="guide-divider" />

      <div class="guide-section">
        <div class="guide-title">
          <ThunderboltOutlined :style="{ color: token.colorInfo }" />
          {{ $t('Woo_Automatic_Sync') }}
        </div>
        <ul class="guide-list">
          <li><CheckCircleOutlined :style="{ color: token.colorSuccess }" /><span>{{ $t('Woo_Guide_Auto_1') }}</span></li>
          <li><CheckCircleOutlined :style="{ color: token.colorSuccess }" /><span>{{ $t('Woo_Guide_Auto_2') }}</span></li>
          <li><ExclamationCircleOutlined :style="{ color: token.colorWarning }" /><span>{{ $t('Woo_Guide_Auto_3') }}</span></li>
          <li><ExclamationCircleOutlined :style="{ color: token.colorWarning }" /><span>{{ $t('Woo_Guide_Auto_4') }}</span></li>
          <li><FileTextOutlined :style="{ color: token.colorPrimary }" /><span>{{ $t('Woo_Guide_Auto_5') }}</span></li>
        </ul>
      </div>

      <a-divider class="guide-divider" />

      <div class="guide-section">
        <div class="guide-title">
          <FieldTimeOutlined :style="{ color: token.colorInfo }" />
          {{ $t('Woo_Guide_First_Setup') }}
        </div>
        <ul class="guide-list">
          <li><CloudSyncOutlined :style="{ color: token.colorPrimary }" /><span>{{ $t('Woo_Guide_First_1') }}</span></li>
          <li><ClockCircleOutlined :style="{ color: token.colorPrimary }" /><span>{{ $t('Woo_Guide_First_2') }}</span></li>
          <li><InfoCircleOutlined :style="{ color: token.colorPrimary }" /><span>{{ $t('Woo_Guide_First_3') }}</span></li>
        </ul>
      </div>

      <a-divider class="guide-divider" />

      <div class="guide-section">
        <div class="guide-title">
          <InfoCircleOutlined :style="{ color: token.colorInfo }" />
          {{ $t('Notes') }}
        </div>
        <ul class="guide-list">
          <li><ExclamationCircleOutlined :style="{ color: token.colorWarning }" /><span>{{ $t('Woo_Guide_Notes_1') }}</span></li>
          <li><ExclamationCircleOutlined :style="{ color: token.colorWarning }" /><span>{{ $t('Woo_Guide_Notes_2') }}</span></li>
        </ul>
      </div>
    </a-card>
  </div>
</template>

<script setup>
/**
 * WooCommerce connection settings — GET/POST woocommerce/settings
 * {store_url, consumer_key, consumer_secret, wp_username, wp_app_password};
 * POST woocommerce/test-connection → {ok}. Legacy validation: store_url
 * required + must start with http(s)://; keys required.
 *
 * The same endpoint also carries `sync_options` — this tenant's store-specific
 * tuning (batch sizes, lookup page caps, alternate-SKU rule; formerly global
 * WOO_* env vars) — plus `sync_options_meta`, which describes every field so the
 * tuning form below renders itself. Sending a key as null/blank restores its
 * default. Server limits (timeouts, retries) are platform-wide, in
 * config/woocommerce.php, and not editable here.
 */
import { ref, computed, onMounted } from 'vue';
import { message, theme } from 'ant-design-vue';
import {
  BookOutlined, KeyOutlined, UserOutlined, SettingOutlined, SyncOutlined,
  InfoCircleOutlined, GlobalOutlined, PlusOutlined, SelectOutlined,
  PictureOutlined, CheckCircleOutlined, CloudSyncOutlined, ClockCircleOutlined,
  SwapOutlined, MenuOutlined, ExclamationCircleOutlined, FieldTimeOutlined,
  FileTextOutlined, ControlOutlined, ThunderboltOutlined,
} from '@ant-design/icons-vue';
import { useI18n } from 'vue-i18n';
import dayjs from 'dayjs';
import relativeTime from 'dayjs/plugin/relativeTime';
import http from '../../../lib/http';

dayjs.extend(relativeTime);

const { t } = useI18n();
const { token } = theme.useToken();
const emit = defineEmits(['ready', 'connection', 'updated']);

const connecting = ref(false);
const connectionOk = ref(null);
const lastSyncAt = ref(null);
const formRef = ref();
const form = ref({
  store_url: '',
  consumer_key: '',
  consumer_secret: '',
  wp_username: '',
  wp_app_password: '',
  auto_sync_products: false,
  auto_sync_stock: false,
});

const syncOptions = ref({});
const tuningMeta = ref({ groups: [], fields: [] });
const tuningOpen = ref([]);

const tuningGroups = computed(() => tuningMeta.value.groups || []);
const tuningFields = computed(() => tuningMeta.value.fields || []);
function fieldsIn(group) {
  return tuningFields.value.filter(f => f.group === group);
}
function resetTuning() {
  const next = {};
  for (const f of tuningFields.value) {
    next[f.key] = f.default ?? (f.type === 'boolean' ? false : null);
  }
  syncOptions.value = next;
}

const rules = computed(() => ({
  store_url: [
    { required: true, message: t('Field_is_required') },
    { pattern: /^https?:\/\//, message: 'https://…' },
  ],
  consumer_key: [{ required: true, message: t('Field_is_required') }],
  consumer_secret: [{ required: true, message: t('Field_is_required') }],
}));

const lastSyncText = computed(() => (lastSyncAt.value ? dayjs(lastSyncAt.value).fromNow() : null));

async function loadSettings() {
  try {
    const data = await http.get('woocommerce/settings');
    if (data.settings) {
      // sync_options is edited through its own form, not the credentials one.
      const { sync_options: _stored, ...credentials } = data.settings;
      form.value = { ...form.value, ...credentials };
      lastSyncAt.value = data.settings.last_sync_at;
    }
    if (data.sync_options_meta) tuningMeta.value = data.sync_options_meta;
    if (data.sync_options) syncOptions.value = { ...data.sync_options };
  } catch (e) { /* first-run: nothing saved yet */ }
}

async function onSubmit() {
  try {
    await formRef.value.validate();
  } catch (e) {
    message.error(t('Please_fill_the_form_correctly'));
    return;
  }
  try {
    await http.post('woocommerce/settings', { ...form.value, sync_options: syncOptions.value });
    message.success(t('Successfully_Updated'));
    emit('updated');
    testConnection();
  } catch (e) {
    message.error(t('InvalidData'));
  }
}

async function testConnection() {
  connecting.value = true;
  try {
    const data = await http.post('woocommerce/test-connection');
    connectionOk.value = !!data.ok;
    emit('connection', connectionOk.value);
    if (data.ok) message.success(t('Connection_successful'));
    else message.error(t('Connection_failed'));
  } catch (e) {
    connectionOk.value = false;
    emit('connection', false);
    message.error(t('Connection_failed'));
  } finally {
    connecting.value = false;
  }
}

onMounted(async () => {
  await loadSettings();
  // Silent probe on load (no toast) — mirror legacy's created() order but
  // avoid the noisy toast: probe directly.
  try {
    const data = await http.post('woocommerce/test-connection');
    connectionOk.value = !!data.ok;
    emit('connection', connectionOk.value);
  } catch (e) {
    connectionOk.value = false;
    emit('connection', false);
  }
  emit('ready');
});
</script>

<style scoped>
.guide-card {
  max-width: 920px;
}

.guide-card-title,
.guide-title {
  display: inline-flex;
  align-items: center;
  gap: 8px;
}

.guide-title {
  display: flex;
  font-weight: 600;
  font-size: 15px;
  margin-bottom: 10px;
}

.guide-intro {
  font-size: 13px;
  line-height: 1.6;
  margin: 0 0 8px;
  opacity: 0.85;
}

.guide-divider {
  margin: 14px 0;
}

.auto-sync-row {
  display: flex;
  align-items: flex-start;
  gap: 12px;
  padding: 8px 0;
}

.auto-sync-row .ant-switch {
  margin-top: 2px;
  flex-shrink: 0;
}

.auto-sync-label {
  font-weight: 600;
  font-size: 14px;
  margin-bottom: 2px;
}

.guide-list {
  list-style: none;
  padding: 0;
  margin: 0;
}

.guide-list li {
  display: flex;
  align-items: flex-start;
  gap: 10px;
  padding: 5px 0;
  line-height: 1.6;
  font-size: 13px;
}

.guide-list li .anticon {
  margin-top: 4px;
  flex-shrink: 0;
}

.guide-code {
  border: 1px solid;
  border-radius: 8px;
  padding: 10px 14px;
  margin: 8px 0;
  font-size: 12px;
  line-height: 1.6;
  white-space: pre-wrap;
  word-break: break-word;
  overflow-x: auto;
}
</style>
