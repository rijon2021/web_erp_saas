<template>
  <div class="page">
    <PageHeader :title="$t('Security')" :breadcrumb="[$t('Settings'), $t('Security')]" />

    <a-skeleton v-if="loading" active :paragraph="{ rows: 10 }" />

    <template v-else>
      <a-alert
        v-if="!migrated"
        type="warning" show-icon style="margin-bottom: 16px"
        :message="$t('Sec_Db_Update_Required')"
        :description="$t('Sec_Db_Update_Help')"
      />

      <!-- ============================== CAPTCHA ============================== -->
      <a-card size="small" class="sec-card">
        <template #title><RobotOutlined /> {{ $t('LoginCaptcha') }}</template>
        <div class="setting-row first">
          <div>
            <div class="setting-label">{{ $t('Sec_Require_Captcha') }}</div>
            <div class="setting-help">{{ $t('Sec_Require_Captcha_Help') }}</div>
          </div>
          <a-switch v-model:checked="form.captcha_enabled" />
        </div>

        <template v-if="form.captcha_enabled">
          <div class="setting-row">
            <div>
              <div class="setting-label">{{ $t('Provider') }}</div>
              <div class="setting-help">
                <template v-if="form.captcha_provider === 'builtin'">
                  {{ imageCaptcha
                    ? $t('Sec_Builtin_Image_Help')
                    : $t('Sec_Builtin_Sum_Help') }}
                </template>
                <template v-else>{{ $t('Sec_Provider_Keys_Help') }} <code>{{ host }}</code></template>
              </div>
            </div>
            <a-select v-model:value="form.captcha_provider" :options="providerOptions" style="width: 240px" @change="resetTest" />
          </div>

          <div v-if="form.captcha_provider !== 'builtin'" class="sub-block">
            <a-form layout="vertical">
              <a-row :gutter="16">
                <a-col :xs="24" :md="12">
                  <a-form-item :label="$t('Sec_Site_Key')" class="tight">
                    <a-input v-model:value="form.captcha_site_key" autocomplete="off" @change="resetTest" />
                  </a-form-item>
                </a-col>
                <a-col :xs="24" :md="12">
                  <a-form-item :label="$t('Sys_Secret_Key')" class="tight" :help="secretSet ? $t('Sec_Secret_Saved_Help') : $t('Sec_Secret_Stored_Help')">
                    <a-input-password v-model:value="form.captcha_secret_key" autocomplete="new-password" @change="resetTest" />
                  </a-form-item>
                </a-col>
              </a-row>
            </a-form>

            <div v-if="keysChanged" class="test-box">
              <div class="setting-label">{{ $t('Sec_Test_Keys') }}</div>
              <div class="setting-help" style="max-width: none">
                {{ $t('Sec_Test_Keys_Help') }}
              </div>
              <a-space style="margin-top: 8px" wrap>
                <a-button size="small" :loading="testLoading" @click="loadTest"><PlayCircleOutlined /> {{ $t('Sec_Load_Test') }}</a-button>
                <a-tag v-if="testToken" color="success"><CheckCircleOutlined /> {{ $t('Sec_Test_Passed') }}</a-tag>
              </a-space>
              <div ref="testHost" style="margin-top: 10px" />
              <div v-if="testError" class="test-error">{{ testError }}</div>
            </div>
          </div>

          <div class="setting-row">
            <div>
              <div class="setting-label">{{ $t('Sec_When_To_Show') }}</div>
              <div class="setting-help">{{ $t('Sec_When_To_Show_Help') }}</div>
            </div>
            <a-space wrap>
              <a-radio-group v-model:value="form.captcha_mode" size="small">
                <a-radio-button value="always">{{ $t('Sec_Every_Sign_In') }}</a-radio-button>
                <a-radio-button value="after_failures">{{ $t('Sec_After_Failed') }}</a-radio-button>
              </a-radio-group>
              <a-input-number
                v-if="form.captcha_mode === 'after_failures'"
                v-model:value="form.captcha_after_failures" :min="1" :max="20" size="small" style="width: 120px"
                :addon-after="$t('Sec_Fails')"
              />
            </a-space>
          </div>

          <div class="setting-row">
            <div>
              <div class="setting-label">{{ $t('Sec_Captcha_Forgot') }}</div>
              <div class="setting-help">{{ $t('Sec_Captcha_Forgot_Help') }}</div>
            </div>
            <a-switch v-model:checked="form.captcha_on_forgot_password" />
          </div>
        </template>
      </a-card>

      <!-- ============================== Lockout ============================== -->
      <a-card size="small" class="sec-card">
        <template #title><LockOutlined /> {{ $t('Sec_Lockout') }}</template>
        <div class="setting-row first">
          <div>
            <div class="setting-label">{{ $t('Sec_Lockout_Enable') }}</div>
            <div class="setting-help">{{ $t('Sec_Lockout_Enable_Help') }}</div>
          </div>
          <a-switch v-model:checked="form.lockout_enabled" />
        </div>

        <template v-if="form.lockout_enabled">
          <div class="setting-row">
            <div>
              <div class="setting-label">{{ $t('Sec_Max_Attempts') }}</div>
              <div class="setting-help">{{ $t('Sec_Max_Attempts_Help') }}</div>
            </div>
            <a-input-number v-model:value="form.lockout_max_attempts" :min="2" :max="50" style="width: 140px" :addon-after="$t('Sec_Tries')" />
          </div>

          <div class="setting-row">
            <div>
              <div class="setting-label">{{ $t('Sec_Block_For') }}</div>
              <div class="setting-help">{{ $t('Sec_Block_For_Help') }}</div>
            </div>
            <a-space wrap>
              <a-radio-group v-model:value="lockoutPreset" size="small">
                <a-radio-button v-for="m in LOCKOUT_PRESETS" :key="m" :value="String(m)">{{ minutesLabel(m) }}</a-radio-button>
                <a-radio-button value="custom">{{ $t('Custom') }}</a-radio-button>
              </a-radio-group>
              <a-input-number
                v-if="lockoutPreset === 'custom'"
                v-model:value="form.lockout_minutes" :min="1" :max="1440" size="small" style="width: 120px"
                :addon-after="$t('Sec_Min')"
              />
            </a-space>
          </div>

          <div class="setting-row">
            <div>
              <div class="setting-label">{{ $t('Sec_What_To_Block') }}</div>
              <div class="setting-help">
                {{ $t('Sec_What_To_Block_Help') }}
              </div>
            </div>
            <a-select v-model:value="form.lockout_scope" :options="scopeOptions" style="width: 280px" />
          </div>

          <div class="setting-row">
            <div>
              <div class="setting-label">{{ $t('Sec_Show_Remaining') }}</div>
              <div class="setting-help">{{ $t('Sec_Show_Remaining_Help') }}</div>
            </div>
            <a-switch v-model:checked="form.lockout_show_remaining" />
          </div>
        </template>
      </a-card>

      <!-- ============================== Sessions ============================== -->
      <a-card size="small" class="sec-card">
        <template #title><ClockCircleOutlined /> {{ $t('Sec_Sessions') }}</template>
        <div class="setting-row first">
          <div>
            <div class="setting-label">{{ $t('Sec_Idle_Logout') }}</div>
            <div class="setting-help">
              {{ $t('Sec_Idle_Logout_Help') }}
            </div>
          </div>
          <a-space wrap>
            <a-radio-group v-model:value="timeoutPreset" size="small">
              <a-radio-button value="0">{{ $t('Sec_Never') }}</a-radio-button>
              <a-radio-button v-for="m in TIMEOUT_PRESETS" :key="m" :value="String(m)">{{ minutesLabel(m) }}</a-radio-button>
              <a-radio-button value="custom">{{ $t('Custom') }}</a-radio-button>
            </a-radio-group>
            <a-input-number
              v-if="timeoutPreset === 'custom'"
              v-model:value="form.session_timeout_minutes" :min="5" :max="43200" size="small" style="width: 120px"
              :addon-after="$t('Sec_Min')"
            />
          </a-space>
        </div>
        <div class="setting-row">
          <div>
            <div class="setting-label">{{ $t('Sec_Single_Session') }}</div>
            <div class="setting-help">{{ $t('Sec_Single_Session_Help') }}</div>
          </div>
          <a-switch v-model:checked="form.single_session" />
        </div>
        <div class="setting-row">
          <div>
            <div class="setting-label">{{ $t('Sec_Log_Attempts') }}</div>
            <div class="setting-help">{{ $t('Sec_Log_Attempts_Help') }}</div>
          </div>
          <a-switch v-model:checked="form.log_login_attempts" />
        </div>
        <div class="setting-row">
          <div>
            <div class="setting-label">{{ $t('Page_Login_Devices') }}</div>
            <div class="setting-help">{{ $t('Sec_Login_Devices_Help') }}</div>
          </div>
          <a-button size="small" @click="emit('navigate', 'devices')">{{ $t('View') }}</a-button>
        </div>
      </a-card>

      <div class="save-bar">
        <a-button type="primary" :loading="saving" :disabled="!migrated" @click="save">{{ $t('submit') }}</a-button>
      </div>

      <!-- ============================== Activity ============================== -->
      <a-card size="small" class="sec-card">
        <template #title><HistoryOutlined /> {{ $t('Sec_Sign_In_Activity') }}</template>

        <template v-if="locked.length">
          <div class="setting-label" style="margin-bottom: 8px"><StopOutlined style="color: #cf1322" /> {{ $t('Sec_Blocked_Now') }}</div>
          <a-table
            :columns="lockedColumns" :data-source="locked" :pagination="false" size="small"
            :row-key="r => (r.email || '') + '|' + (r.ip_address || '')" :scroll="{ x: 'max-content' }"
            style="margin-bottom: 20px"
          >
            <template #bodyCell="{ column, record }">
              <template v-if="column.key === 'left'">{{ secondsLabel(record.seconds_left) }}</template>
              <template v-else-if="column.key === 'actions'">
                <a-button size="small" :loading="unlocking === record.email + '|' + record.ip_address" @click="unlock(record)">
                  <UnlockOutlined /> {{ $t('Sec_Unblock') }}
                </a-button>
              </template>
            </template>
          </a-table>
        </template>

        <div class="activity-toolbar">
          <a-space wrap>
            <a-select v-model:value="filters.event" :options="eventOptions" style="width: 180px" allow-clear :placeholder="$t('Sec_All_Events')" @change="reload" />
            <a-input-search v-model:value="filters.search" :placeholder="$t('Sec_Email_Or_IP')" style="width: 220px" allow-clear @search="reload" />
            <a-button @click="loadActivity"><ReloadOutlined /></a-button>
          </a-space>
          <a-popconfirm :title="$t('Sec_Clear_Log_Confirm')" :ok-text="$t('Delete')" :cancel-text="$t('Cancel')" @confirm="clearLog">
            <a-button danger size="small" :disabled="!attempts.length"><DeleteOutlined /> {{ $t('Sec_Clear_Log') }}</a-button>
          </a-popconfirm>
        </div>

        <a-table
          :columns="attemptColumns" :data-source="attempts" :loading="activityLoading" size="small"
          :row-key="r => r.id" :scroll="{ x: 'max-content' }"
          :pagination="{ current: filters.page, pageSize: filters.limit, total: totalRows, showSizeChanger: false, size: 'small' }"
          @change="p => { filters.page = p.current; loadActivity(); }"
        >
          <template #bodyCell="{ column, record }">
            <template v-if="column.key === 'event'">
              <a-tag :color="EVENTS[record.event]?.color">{{ EVENTS[record.event]?.label || record.event }}</a-tag>
            </template>
          </template>
          <template #emptyText>
            <a-empty :description="saved.log_login_attempts ? $t('Sec_No_Attempts') : $t('Sec_Recording_Off')" style="padding: 24px 0" />
          </template>
        </a-table>
        <div class="setting-help" style="max-width: none; margin-top: 8px">{{ $t('Sec_Retention_Note') }}</div>
      </a-card>
    </template>
  </div>
</template>

<script setup>
/**
 * System Settings > Security (tenant staff login).
 * - GET security/login-security → {options, providers[{value,label,script,class}],
 *   image_captcha, migrated}; options mirror App\Support\Security\SecurityOptions
 *   (captcha_secret_key always '', captcha_secret_set flag instead)
 * - PUT security/login-security {…options, captcha_test_token}: an external
 *   CAPTCHA with new keys is refused unless the token from the test widget
 *   verifies with them (protects against locking everyone out)
 * - GET security/login-attempts {event, search, page, limit} → {attempts,
 *   totalRows, locked[{email, ip_address, seconds_left}]}
 * - POST security/login-attempts/unlock {email, ip_address}; DELETE security/login-attempts
 */
import { ref, reactive, computed, onMounted, onBeforeUnmount } from 'vue';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import dayjs from 'dayjs';
import {
  RobotOutlined, LockOutlined, ClockCircleOutlined, HistoryOutlined, StopOutlined, UnlockOutlined,
  ReloadOutlined, DeleteOutlined, PlayCircleOutlined, CheckCircleOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';

const emit = defineEmits(['navigate']);
const { t } = useI18n();

const LOCKOUT_PRESETS = [1, 2, 5, 10, 15, 30, 60];
const TIMEOUT_PRESETS = [15, 30, 60, 240, 480, 720];
const EVENTS = {
  success: { get label() { return t('Sec_Ev_Success'); }, color: 'success' },
  failed: { get label() { return t('Sec_Ev_Failed'); }, color: 'warning' },
  locked_out: { get label() { return t('Sec_Ev_Locked_Out'); }, color: 'error' },
  blocked: { get label() { return t('Sec_Ev_Blocked'); }, color: 'volcano' },
  captcha_failed: { get label() { return t('Sec_Ev_Captcha_Failed'); }, color: 'purple' },
};

const host = window.location.host;
const loading = ref(true);
const saving = ref(false);
const migrated = ref(true);
const imageCaptcha = ref(true);
const providers = ref([]);
const secretSet = ref(false);
const saved = ref({}); // last saved options, to detect key changes
const form = reactive({});

/* ------------------------------------------------------------- labels */
function minutesLabel(m) {
  if (m < 60) return t('Sec_N_Min', { n: m });
  const h = m / 60;
  return Number.isInteger(h) ? t('Sec_N_Hours', { n: h }) : t('Sec_N_Min', { n: m });
}
function secondsLabel(s) {
  if (s < 60) return t('Sec_N_Sec', { n: s });
  return t('Sec_N_Min', { n: Math.ceil(s / 60) });
}

const providerOptions = computed(() => [
  { value: 'builtin', label: t('Sec_Builtin_Provider') },
  ...providers.value.map(p => ({ value: p.value, label: p.label })),
]);
const scopeOptions = computed(() => [
  { value: 'email_ip', label: t('Sec_Scope_Email_Ip') },
  { value: 'email', label: t('Sec_Scope_Email') },
  { value: 'ip', label: t('Sec_Scope_Ip') },
]);
const eventOptions = computed(() => Object.entries(EVENTS).map(([value, e]) => ({ value, label: e.label })));

/* ------------------------------------------------------------- presets */
function presetModel(field, presets, zeroIsPreset) {
  const custom = ref(false);
  return computed({
    get() {
      const v = Number(form[field]) || 0;
      if (custom.value) return 'custom';
      if (zeroIsPreset && v <= 0) return '0';
      return presets.includes(v) ? String(v) : 'custom';
    },
    set(val) {
      custom.value = val === 'custom';
      if (val !== 'custom') form[field] = Number(val);
      else if (!(Number(form[field]) > 0)) form[field] = presets[presets.length - 1];
    },
  });
}
const lockoutPreset = presetModel('lockout_minutes', LOCKOUT_PRESETS, false);
const timeoutPreset = presetModel('session_timeout_minutes', TIMEOUT_PRESETS, true);

/* ------------------------------------------------------------- key test */
const testHost = ref(null);
const testToken = ref('');
const testError = ref('');
const testLoading = ref(false);
const GLOBALS = { recaptcha: 'grecaptcha', hcaptcha: 'hcaptcha', turnstile: 'turnstile' };
const scriptPromises = {};

const keysChanged = computed(() => form.captcha_enabled
  && form.captcha_provider !== 'builtin'
  && (!saved.value.captcha_enabled
    || saved.value.captcha_provider !== form.captcha_provider
    || saved.value.captcha_site_key !== (form.captcha_site_key || '').trim()
    || !!form.captcha_secret_key));

function resetTest() {
  testToken.value = '';
  testError.value = '';
  if (testHost.value) testHost.value.innerHTML = '';
}

// reCAPTCHA, hCaptcha and Turnstile share the explicit-render API:
// <global>.render(element, { sitekey, callback }).
function loadProviderScript(name) {
  const g = GLOBALS[name];
  if (window[g]?.render) return Promise.resolve();
  if (scriptPromises[name]) return scriptPromises[name];
  const provider = providers.value.find(p => p.value === name);
  scriptPromises[name] = new Promise((resolve, reject) => {
    const cb = `__secCaptchaReady_${name}`;
    window[cb] = () => resolve();
    const s = document.createElement('script');
    s.src = `${provider.script}?render=explicit&onload=${cb}`;
    s.async = true;
    s.onerror = () => { delete scriptPromises[name]; reject(new Error('load')); };
    document.head.appendChild(s);
  });
  return scriptPromises[name];
}

async function loadTest() {
  resetTest();
  const siteKey = (form.captcha_site_key || '').trim();
  if (!siteKey) { testError.value = t('Sec_Enter_Site_Key'); return; }
  testLoading.value = true;
  try {
    await loadProviderScript(form.captcha_provider);
    const el = document.createElement('div');
    testHost.value.appendChild(el);
    window[GLOBALS[form.captcha_provider]].render(el, {
      sitekey: siteKey,
      callback: token => { testToken.value = token; testError.value = ''; },
    });
  } catch (e) {
    testError.value = t('Sec_Challenge_Load_Failed');
  } finally {
    testLoading.value = false;
  }
}

/* ------------------------------------------------------------- load / save */
function applyOptions(options) {
  const { captcha_secret_set: set, ...rest } = options || {};
  Object.assign(form, rest, { captcha_secret_key: '' });
  secretSet.value = !!set;
  saved.value = { ...rest };
}

async function load() {
  try {
    const d = await http.get('security/login-security');
    providers.value = d.providers || [];
    imageCaptcha.value = d.image_captcha !== false;
    migrated.value = !!d.migrated;
    applyOptions(d.options);
  } catch (e) {
    message.error(t('InvalidData'));
  } finally {
    loading.value = false;
  }
}

async function save() {
  if (keysChanged.value && !testToken.value) {
    message.warning(t('Sec_Pass_Test_First'));
    return;
  }
  saving.value = true;
  try {
    const payload = { ...form };
    if (testToken.value) payload.captcha_test_token = testToken.value;
    const d = await http.put('security/login-security', payload);
    applyOptions(d.options);
    resetTest();
    message.success(t('Successfully_Updated'));
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    saving.value = false;
  }
}

/* ------------------------------------------------------------- activity */
const DATE_FMT = (() => {
  try { return localStorage.getItem('app_date_format') || 'YYYY-MM-DD'; } catch (e) { return 'YYYY-MM-DD'; }
})();
const fmtDateTime = v => (v ? dayjs(v).format(`${DATE_FMT} HH:mm:ss`) : '');

const attempts = ref([]);
const locked = ref([]);
const totalRows = ref(0);
const activityLoading = ref(false);
const unlocking = ref('');
const filters = reactive({ event: undefined, search: '', page: 1, limit: 15 });

const attemptColumns = computed(() => [
  { title: t('Time'), dataIndex: 'created_at', key: 'created_at', customRender: ({ text }) => fmtDateTime(text) },
  { title: t('Event'), dataIndex: 'event', key: 'event' },
  { title: t('Email'), dataIndex: 'email', key: 'email' },
  { title: t('IP_Address'), dataIndex: 'ip_address', key: 'ip_address' },
  { title: t('Device_Browser'), dataIndex: 'device', key: 'device' },
]);
const lockedColumns = computed(() => [
  { title: t('Email'), dataIndex: 'email', key: 'email' },
  { title: t('IP_Address'), dataIndex: 'ip_address', key: 'ip_address' },
  { title: t('Sec_Blocked_At'), dataIndex: 'locked_at', key: 'locked_at', customRender: ({ text }) => fmtDateTime(text) },
  { title: t('Sec_Time_Left'), key: 'left' },
  { title: '', key: 'actions', align: 'right' },
]);

function reload() {
  filters.page = 1;
  loadActivity();
}

async function loadActivity() {
  activityLoading.value = true;
  try {
    const d = await http.get('security/login-attempts', {
      event: filters.event || '',
      search: filters.search || '',
      page: filters.page,
      limit: filters.limit,
    });
    attempts.value = d.attempts || [];
    locked.value = d.locked || [];
    totalRows.value = d.totalRows || 0;
  } catch (e) {
    /* tab still usable without the log */
  } finally {
    activityLoading.value = false;
  }
}

async function unlock(record) {
  unlocking.value = record.email + '|' + record.ip_address;
  try {
    await http.post('security/login-attempts/unlock', { email: record.email, ip_address: record.ip_address });
    message.success(t('Sec_Unblocked'));
    loadActivity();
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    unlocking.value = '';
  }
}

async function clearLog() {
  try {
    await http.delete('security/login-attempts');
    reload();
  } catch (e) {
    message.error(t('InvalidData'));
  }
}

// "Time left" counts down without refetching.
let ticker = null;
onMounted(() => {
  load();
  loadActivity();
  ticker = setInterval(() => {
    if (!locked.value.length) return;
    locked.value = locked.value
      .map(r => ({ ...r, seconds_left: r.seconds_left - 1 }))
      .filter(r => r.seconds_left > 0);
  }, 1000);
});
onBeforeUnmount(() => clearInterval(ticker));
</script>

<style scoped>
.sec-card {
  margin-bottom: 16px;
}
.setting-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 24px;
  padding: 14px 0;
  border-top: 1px solid rgba(128, 128, 128, 0.12);
  flex-wrap: wrap;
}
.setting-row.first {
  border-top: none;
  padding-top: 4px;
}
.setting-label {
  font-weight: 500;
}
.setting-help {
  font-size: 12px;
  color: rgba(var(--ink-rgb), 0.45);
  max-width: 520px;
}
.sub-block {
  padding: 4px 0 12px;
}
.tight {
  margin-bottom: 8px;
}
.test-box {
  border: 1px dashed rgba(128, 128, 128, 0.35);
  border-radius: 8px;
  padding: 12px;
}
.test-error {
  color: #cf1322;
  font-size: 12px;
  margin-top: 6px;
}
.save-bar {
  margin: 4px 0 20px;
}
.activity-toolbar {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
  margin-bottom: 12px;
}
</style>
