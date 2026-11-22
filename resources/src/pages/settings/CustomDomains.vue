<template>
  <div class="page">
    <PageHeader :title="$t('Cd_Title')" :breadcrumb="[$t('Settings'), $t('Cd_Title')]" />

    <a-row :gutter="[16, 16]">
      <!-- ============================== Domains ============================== -->
      <a-col :xs="24" :xl="16">
        <a-card :title="$t('Cd_Your_Domains')">
          <template #extra>
            <span v-if="limit !== null" class="muted">{{ t('Cd_Limit_Info', { used, max: limit }) }}</span>
            <span v-else class="muted">{{ t('Cd_Limit_Unlimited', { used }) }}</span>
          </template>

          <a-alert type="info" show-icon style="margin-bottom: 16px" :message="$t('Cd_Intro')" />

          <a-descriptions v-if="subdomain" :column="1" size="small" bordered style="margin-bottom: 16px">
            <a-descriptions-item :label="$t('Cd_Current_Address')">
              <code>{{ subdomain }}</code>
            </a-descriptions-item>
          </a-descriptions>

          <!-- Add -->
          <a-form layout="vertical" @submit.prevent="add">
            <a-form-item :label="$t('Cd_Add_Domain')" :help="$t('Cd_Domain_Help')" :validate-status="addError ? 'error' : ''" :extra="addError">
              <a-input-group compact style="display: flex">
                <a-input
                  v-model:value="newDomain"
                  :placeholder="$t('Cd_Domain_Placeholder')"
                  :maxlength="253"
                  :disabled="limitReached || adding"
                  style="flex: 1"
                  @press-enter="add"
                />
                <a-button type="primary" :loading="adding" :disabled="limitReached || !newDomain.trim()" @click="add">
                  <template #icon><PlusOutlined /></template>
                  {{ $t('Cd_Add_Domain') }}
                </a-button>
              </a-input-group>
            </a-form-item>
          </a-form>
          <a-alert v-if="limitReached" type="warning" show-icon style="margin-bottom: 16px" :message="$t('Cd_Limit_Reached')" />

          <!-- List -->
          <a-spin :spinning="loading">
            <a-empty v-if="!loading && !domains.length" :description="$t('Cd_No_Domains')" />

            <div v-for="d in domains" :key="d.id" class="domain">
              <div class="domain-head">
                <div>
                  <div class="domain-name">
                    <GlobalOutlined />
                    <strong>{{ d.domain }}</strong>
                    <a-tag v-if="d.is_primary" color="purple">{{ $t('Cd_Status_Primary') }}</a-tag>
                    <a-tag v-if="d.is_verified" color="success">{{ $t('Cd_Status_Verified') }}</a-tag>
                    <a-tag v-else color="warning">{{ $t('Cd_Status_Pending') }}</a-tag>
                    <a-tag v-if="d.is_verified" :color="sslColor(d.ssl_status)">{{ $t('Cd_Ssl_' + (d.ssl_status || 'unknown')) }}</a-tag>
                  </div>
                  <div class="muted">
                    {{ $t('Cd_Last_Check') }}: {{ d.last_check_at ? dateTime(d.last_check_at) : $t('Cd_Never_Checked') }}
                    <template v-if="!d.is_verified && d.last_check_error"> · {{ d.last_check_error }}</template>
                  </div>
                </div>
                <a-space wrap>
                  <a-button size="small" :loading="verifying === d.id" @click="verify(d)">
                    <template #icon><ReloadOutlined /></template>
                    {{ $t('Cd_Verify') }}
                  </a-button>
                  <a-button v-if="d.is_verified && !d.is_primary" size="small" @click="makePrimary(d)">
                    <template #icon><StarOutlined /></template>
                    {{ $t('Cd_Make_Primary') }}
                  </a-button>
                  <a-popconfirm :title="$t('Cd_Remove_Confirm')" @confirm="remove(d)">
                    <a-button size="small" danger>
                      <template #icon><DeleteOutlined /></template>
                      {{ $t('Delete') }}
                    </a-button>
                  </a-popconfirm>
                </a-space>
              </div>

              <!-- DNS instructions stay visible until the domain verifies -->
              <div v-if="!d.is_verified" class="dns">
                <div class="dns-title">{{ $t('Cd_Dns_Title') }}</div>
                <p class="muted" style="margin: 0 0 8px">{{ $t('Cd_Dns_Intro') }}</p>
                <a-table :columns="dnsColumns" :data-source="dnsRows(d)" row-key="type" size="small" :pagination="false">
                  <template #bodyCell="{ column, record }">
                    <template v-if="column.key === 'type'">
                      <a-tag>{{ record.type }}</a-tag>
                      <div class="muted">{{ record.note }}</div>
                    </template>
                    <template v-else-if="column.key === 'host'">
                      <span class="copy" @click="copy(record.host)"><code>{{ record.host }}</code> <CopyOutlined /></span>
                    </template>
                    <template v-else-if="column.key === 'value'">
                      <template v-if="record.value">
                        <span class="copy" @click="copy(record.value)"><code>{{ record.value }}</code> <CopyOutlined /></span>
                      </template>
                      <span v-else class="muted">{{ $t('Cd_Dns_Ip_Unknown') }}</span>
                    </template>
                  </template>
                </a-table>
                <div v-if="foundRecords[d.id]" class="muted" style="margin-top: 8px">
                  {{ $t('Cd_Found_Records') }}: <code>{{ foundRecords[d.id] }}</code>
                </div>
                <p class="muted" style="margin: 8px 0 0">{{ $t('Cd_Cloudflare_Note') }}</p>
              </div>
            </div>
          </a-spin>

          <p class="muted" style="margin: 16px 0 0"><LockOutlined /> {{ $t('Cd_Ssl_Hint') }}</p>
        </a-card>
      </a-col>

      <!-- ============================== Guide ============================== -->
      <a-col :xs="24" :xl="8">
        <a-card :title="$t('Cd_Guide_Title')">
          <a-steps direction="vertical" size="small" :current="-1">
            <a-step :title="$t('Cd_Guide_1')" />
            <a-step :title="$t('Cd_Guide_2')" />
            <a-step :title="$t('Cd_Guide_3')" />
            <a-step :title="$t('Cd_Guide_4')" />
          </a-steps>
        </a-card>
      </a-col>
    </a-row>
  </div>
</template>

<script setup>
/**
 * Settings > Custom Domain: the tenant's own domains for this workspace.
 * GET/POST custom-domains, POST custom-domains/{id}/verify|primary,
 * DELETE custom-domains/{id} (CustomDomainsController). Error responses
 * carry a stable `code` (invalid, duplicate, no_records, mismatch…) that is
 * translated here as Cd_Err_<code>; the plan quota comes back as a 403
 * limit_reached which the http lib already turns into the upgrade prompt.
 */
import { ref, computed, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { PlusOutlined, CopyOutlined, ReloadOutlined, StarOutlined, DeleteOutlined, GlobalOutlined, LockOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import { useFormat } from '../../composables/useFormat';
import http from '../../lib/http';

const { t } = useI18n();
const { dateTime } = useFormat();

const loading = ref(true);
const domains = ref([]);
const subdomain = ref(null);
const centralHost = ref('');
const limit = ref(null);
const used = ref(0);
const newDomain = ref('');
const adding = ref(false);
const addError = ref('');
const verifying = ref(null);
const foundRecords = ref({});

const limitReached = computed(() => limit.value !== null && used.value >= limit.value);

const dnsColumns = computed(() => [
  { title: t('Cd_Dns_Type'), key: 'type', width: 160 },
  { title: t('Cd_Dns_Host'), key: 'host' },
  { title: t('Cd_Dns_Value'), key: 'value' },
]);

function dnsRows(d) {
  const dns = d.dns || {};
  const ips = Array.isArray(dns.a?.value) ? dns.a.value.join(', ') : dns.a?.value || '';
  return [
    { type: 'CNAME', host: dns.cname?.host || d.domain, value: dns.cname?.value || centralHost.value, note: t('Cd_Dns_Cname_Note') },
    { type: 'A', host: dns.a?.host || d.domain, value: ips, note: t('Cd_Dns_A_Note') },
    { type: 'TXT', host: dns.txt?.host || `_saas-verify.${d.domain}`, value: dns.txt?.value || '', note: t('Cd_Dns_Txt_Note') },
  ];
}

function sslColor(status) {
  return { active: 'success', failed: 'error', pending: 'processing' }[status] || 'default';
}

function errorText(e) {
  const code = e?.data?.code;
  if (code) {
    const key = `Cd_Err_${code}`;
    const txt = t(key);
    return txt !== key ? txt : e.data.message || t('InvalidData');
  }
  return e?.data?.message || t('InvalidData');
}

async function load() {
  loading.value = true;
  try {
    const data = await http.get('custom-domains');
    domains.value = data?.domains || [];
    subdomain.value = data?.subdomain || null;
    centralHost.value = data?.central_host || '';
    limit.value = data?.limit ?? null;
    used.value = data?.used ?? domains.value.length;
  } catch (e) {
    message.error(errorText(e));
  } finally {
    loading.value = false;
  }
}

async function add() {
  const domain = newDomain.value.trim();
  if (!domain || adding.value || limitReached.value) return;
  adding.value = true;
  addError.value = '';
  try {
    await http.post('custom-domains', { domain });
    newDomain.value = '';
    message.success(t('Cd_Added'));
    await load();
  } catch (e) {
    // 403 limit_reached already opened the global upgrade prompt
    if (e?.data?.status !== 'limit_reached') addError.value = errorText(e);
  } finally {
    adding.value = false;
  }
}

async function verify(d) {
  verifying.value = d.id;
  try {
    await http.post(`custom-domains/${d.id}/verify`, {});
    message.success(t('Cd_Verified_Msg'));
    delete foundRecords.value[d.id];
    await load();
  } catch (e) {
    const recs = e?.data?.records;
    foundRecords.value[d.id] = recs && Object.keys(recs).length ? JSON.stringify(recs) : '';
    message.warning(errorText(e));
    await load();
  } finally {
    verifying.value = null;
  }
}

async function makePrimary(d) {
  try {
    await http.post(`custom-domains/${d.id}/primary`, {});
    message.success(t('Cd_Primary_Set'));
    await load();
  } catch (e) {
    message.error(errorText(e));
  }
}

async function remove(d) {
  try {
    await http.delete(`custom-domains/${d.id}`);
    message.success(t('Cd_Removed'));
    await load();
  } catch (e) {
    message.error(errorText(e));
  }
}

async function copy(text) {
  try {
    await navigator.clipboard.writeText(text);
    message.success(t('Copied'));
  } catch (e) {
    message.info(text);
  }
}

onMounted(load);
</script>

<style scoped>
.muted {
  color: rgba(var(--ink-rgb), 0.5);
  font-size: 12px;
}
.domain {
  border: 1px solid rgba(128, 128, 128, 0.2);
  border-radius: 8px;
  padding: 12px 14px;
  margin-bottom: 12px;
}
.domain-head {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 12px;
  flex-wrap: wrap;
}
.domain-name {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
  margin-bottom: 4px;
}
.dns {
  margin-top: 12px;
  padding-top: 12px;
  border-top: 1px dashed rgba(128, 128, 128, 0.25);
}
.dns-title {
  font-weight: 600;
  margin-bottom: 4px;
}
.copy {
  cursor: pointer;
}
.copy code {
  word-break: break-all;
}
</style>
