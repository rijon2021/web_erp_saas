<template>
  <div class="page">
    <PageHeader :title="$t('Portal_Accounts')" :subtitle="$t('Portal_Accounts_Subtitle')" :breadcrumb="[$t('Client_Portal'), $t('Portal_Accounts')]">
      <template #actions>
        <a-button type="primary" @click="openGrant()">
          <template #icon><PlusOutlined /></template>
          {{ $t('Portal_Grant_Access') }}
        </a-button>
      </template>
    </PageHeader>

    <a-card :body-style="{ padding: 0 }">
      <div class="toolbar">
        <a-space wrap>
          <a-input-search
            v-model:value="crud.search.value" :placeholder="$t('Search_this_table')" allow-clear
            style="width: 260px" @search="crud.reload()"
          />
          <a-segmented v-model:value="status" :options="statusOptions" />
        </a-space>
        <a-space wrap>
          <template v-if="selected.length">
            <span class="sel-count">{{ $t('Portal_N_Selected', { n: selected.length }) }}</span>
            <a-button @click="bulkInvite"><template #icon><MailOutlined /></template>{{ $t('Portal_Send_Invitation') }}</a-button>
            <a-button @click="bulkStatus(true)"><template #icon><CheckCircleOutlined /></template>{{ $t('Enable') }}</a-button>
            <a-button danger @click="bulkStatus(false)"><template #icon><StopOutlined /></template>{{ $t('Portal_Disable') }}</a-button>
          </template>
          <a-tooltip :title="$t('Refresh')">
            <a-button @click="crud.fetchRows()"><template #icon><ReloadOutlined :spin="crud.loading.value" /></template></a-button>
          </a-tooltip>
        </a-space>
      </div>

      <a-table
        :columns="columns" :data-source="crud.rows.value" :loading="crud.loading.value"
        :pagination="crud.pagination.value" :row-key="r => r.id" :scroll="{ x: 'max-content' }"
        :row-selection="{ selectedRowKeys: selected, onChange: keys => (selected = keys) }"
        :show-sorter-tooltip="false" size="middle" @change="crud.onChange"
      >
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'client_name'">
            <a class="cust" @click="router.push(`/customers/${record.client_id}/details`)">{{ record.client_name || '—' }}</a>
            <div class="sub">{{ [record.client_code, record.client_phone].filter(Boolean).join(' · ') }}</div>
          </template>
          <template v-else-if="column.key === 'state'">
            <PortalStateTag :account="record" />
            <a-tooltip v-if="!record.has_password && record.state !== 'invited'" :title="$t('Portal_No_Password_Help')">
              <a-tag color="warning" style="margin: 0 0 0 4px">{{ $t('Portal_No_Password') }}</a-tag>
            </a-tooltip>
          </template>
          <template v-else-if="column.key === 'invitation_sent_at'">
            {{ record.invitation_sent_at ? dateTime(record.invitation_sent_at) : '—' }}
          </template>
          <template v-else-if="column.key === 'preferred_locale'">
            {{ record.preferred_locale ? record.preferred_locale.toUpperCase() : '—' }}
          </template>
          <template v-else-if="column.key === 'created_at'">{{ dateTime(record.created_at) }}</template>
          <template v-else-if="column.key === 'two_factor'">
            <a-tag v-if="record.two_factor" color="success" style="margin: 0">{{ record.two_factor === 'email' ? tr('Cust_2fa_Email', 'Email') : tr('Cust_2fa_App', 'App') }}</a-tag>
            <span v-else style="opacity: 0.45">{{ tr('Tfa_Off', 'Off') }}</span>
          </template>
          <template v-else-if="column.key === 'actions'">
            <a-dropdown :trigger="['click']">
              <a-button size="small"><template #icon><MoreOutlined /></template></a-button>
              <template #overlay>
                <a-menu @click="({ key }) => rowAction(key, record)">
                  <a-menu-item key="invite"><MailOutlined /> {{ record.state === 'invited' ? $t('Portal_Resend_Invitation') : $t('Portal_Send_Invitation') }}</a-menu-item>
                  <a-menu-item key="edit"><EditOutlined /> {{ $t('Edit') }}</a-menu-item>
                  <a-menu-item v-if="record.state !== 'active'" key="enable" :disabled="!record.has_password"><CheckCircleOutlined /> {{ $t('Enable') }}</a-menu-item>
                  <a-menu-item v-if="record.state === 'active'" key="disable" danger><StopOutlined /> {{ $t('Portal_Disable') }}</a-menu-item>
                  <a-menu-item v-if="record.two_factor" key="reset_2fa"><SafetyCertificateOutlined /> {{ tr('Cust_2fa_Reset', 'Turn off 2-step') }}</a-menu-item>
                  <a-menu-divider />
                  <a-menu-item key="customer"><UserOutlined /> {{ $t('CustomerDetails') }}</a-menu-item>
                </a-menu>
              </template>
            </a-dropdown>
          </template>
        </template>
        <template #emptyText>
          <a-empty :description="$t('Portal_No_Accounts')" style="padding: 32px 0">
            <a-button type="primary" @click="openGrant()">{{ $t('Portal_Grant_Access') }}</a-button>
          </a-empty>
        </template>
      </a-table>
    </a-card>

    <!-- Grant access -->
    <a-modal
      v-model:open="grantOpen" :title="$t('Portal_Grant_Access')" :confirm-loading="saving"
      :ok-text="grant.mode === 'invite' ? $t('Portal_Send_Invitation') : $t('Save')" :cancel-text="$t('Cancel')"
      @ok="submitGrant"
    >
      <a-form layout="vertical">
        <a-form-item :label="$t('Customer')" required>
          <a-select
            v-model:value="grant.client_id" show-search :filter-option="false" :options="clientOptions"
            :loading="clientsLoading" :placeholder="$t('Portal_Search_Customer')" :not-found-content="$t('Portal_No_Customers_Left')"
            @search="searchClients" @change="onClientPicked"
          />
        </a-form-item>
        <a-form-item :label="$t('Portal_Login_Email')" required>
          <a-input v-model:value="grant.email" type="email" />
        </a-form-item>
        <a-form-item :label="$t('Portal_Access_Method')">
          <a-radio-group v-model:value="grant.mode" class="modes">
            <a-radio value="invite">
              <b>{{ $t('Portal_Mode_Invite') }}</b>
              <div class="sub">{{ $t('Portal_Mode_Invite_Help') }}</div>
            </a-radio>
            <a-radio value="password">
              <b>{{ $t('Portal_Mode_Password') }}</b>
              <div class="sub">{{ $t('Portal_Mode_Password_Help') }}</div>
            </a-radio>
          </a-radio-group>
        </a-form-item>
        <a-form-item v-if="grant.mode === 'password'" :label="$t('Password')" required>
          <a-input-password v-model:value="grant.password" autocomplete="new-password" />
        </a-form-item>
      </a-form>
    </a-modal>

    <!-- Edit -->
    <a-modal
      v-model:open="editOpen" :title="editForm.client_name || $t('Edit')" :confirm-loading="saving"
      :ok-text="$t('Save')" :cancel-text="$t('Cancel')" @ok="submitEdit"
    >
      <a-form layout="vertical">
        <a-form-item :label="$t('Portal_Login_Email')" required>
          <a-input v-model:value="editForm.email" type="email" />
        </a-form-item>
        <a-form-item :label="$t('Portal_New_Password')" :extra="$t('LeaveBlank')">
          <a-input-password v-model:value="editForm.password" autocomplete="new-password" />
        </a-form-item>
      </a-form>
    </a-modal>

    <!-- Invitation link when the email could not be sent -->
    <a-modal v-model:open="linkOpen" :title="$t('Portal_Invite_Link')" :footer="null">
      <a-alert type="warning" show-icon :message="$t('Portal_Invite_Mail_Failed')" style="margin-bottom: 12px" />
      <a-input-group compact style="display: flex">
        <a-input :value="inviteUrl" readonly style="flex: 1" />
        <a-button type="primary" @click="copyLink">{{ $t('Copy') }}</a-button>
      </a-input-group>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * Portal logins (portal_clients) across all customers.
 * GET client-portal/accounts (page, limit, search, SortField, SortType, status)
 * → { accounts, totalRows }. Writes: POST client-portal/accounts (grant),
 * PUT client-portal/accounts/{id}, POST …/status {ids, status}, POST …/invite {ids}.
 */
import { ref, computed, onMounted, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { message, Modal } from 'ant-design-vue';
import {
  PlusOutlined, ReloadOutlined, MoreOutlined, MailOutlined, EditOutlined,
  CheckCircleOutlined, StopOutlined, UserOutlined, SafetyCertificateOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import PortalStateTag from './PortalStateTag.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import http from '../../lib/http';

const { t } = useI18n();
const tr = (key, fallback) => {
  const out = t(key);
  return out === key ? fallback : out;
};
const route = useRoute();
const router = useRouter();
const { dateTime } = useFormat();

const STATUSES = ['active', 'invited', 'disabled'];
const status = ref(STATUSES.includes(route.query.status) ? route.query.status : 'all');
const selected = ref([]);

const crud = useCrudTable('client-portal/accounts', {
  rowsKey: 'accounts',
  params: () => (status.value === 'all' ? {} : { status: status.value }),
});
// Clear the selection whenever the page of rows changes.
watch(() => crud.rows.value, () => { selected.value = []; });

const statusOptions = computed(() => [
  { value: 'all', label: t('All') },
  { value: 'active', label: t('Active') },
  { value: 'invited', label: t('Invited') },
  { value: 'disabled', label: t('Disabled') },
]);

const columns = computed(() => [
  { title: t('Customer'), key: 'client_name', dataIndex: 'client_name', sorter: true },
  { title: t('Portal_Login_Email'), key: 'email', dataIndex: 'email', sorter: true },
  { title: t('Status'), key: 'state' },
  { title: t('Portal_Invitation_Sent'), key: 'invitation_sent_at', dataIndex: 'invitation_sent_at', sorter: true },
  { title: t('Language'), key: 'preferred_locale' },
  { title: t('Created_At'), key: 'created_at', dataIndex: 'created_at', sorter: true },
  { title: tr('Tfa_Column', '2FA'), key: 'two_factor', width: 80, align: 'center' },
  { title: t('Actions'), key: 'actions', width: 70, align: 'center' },
]);

watch(status, () => {
  router.replace({ query: { ...route.query, status: status.value === 'all' ? undefined : status.value } });
  crud.reload();
});

const errText = e => e?.data?.message || Object.values(e?.data?.errors || {})[0]?.[0] || t('InvalidData');

/* ------------------------------------------------------------ row actions */
function rowAction(key, record) {
  if (key === 'invite') return invite([record.id]);
  if (key === 'edit') return openEdit(record);
  if (key === 'enable') return setStatus([record.id], true);
  if (key === 'disable') return setStatus([record.id], false);
  if (key === 'customer') return router.push(`/customers/${record.client_id}/details`);
  if (key === 'reset_2fa') return resetTwoFactor(record, `client-portal/accounts/${record.id}/two-factor/reset`);
}

// Lost phone and recovery codes: turn the customer's two-step verification off.
function resetTwoFactor(record, url) {
  Modal.confirm({
    title: tr('Cust_2fa_Reset_Title', 'Turn off two-step verification?'),
    content: tr('Cust_2fa_Reset_Help', 'This customer will sign in with just their password and can turn it on again from their account. Do this only after confirming who is asking.'),
    okText: tr('Cust_2fa_Reset', 'Turn off 2-step'),
    okType: 'danger',
    cancelText: t('Cancel'),
    async onOk() {
      try {
        await http.post(url);
        record.two_factor = null;
        message.success(t('Successfully_Updated'));
      } catch (e) {
        message.error(e?.data?.message || t('InvalidData'));
      }
    },
  });
}


async function setStatus(ids, enable) {
  try {
    const res = await http.post('client-portal/accounts/status', { ids, status: enable });
    if (res.skipped) message.warning(t('Portal_Enable_Skipped', { n: res.skipped }));
    else message.success(t('Success'));
    crud.fetchRows();
  } catch (e) {
    message.error(errText(e));
  }
}

function bulkStatus(enable) {
  const ids = [...selected.value];
  if (enable) return setStatus(ids, true);
  Modal.confirm({
    title: t('Portal_Disable_Selected_Title', { n: ids.length }),
    content: t('Portal_Disable_Help'),
    okType: 'danger',
    okText: t('Portal_Disable'),
    cancelText: t('Cancel'),
    onOk: () => setStatus(ids, false),
  });
}

const linkOpen = ref(false);
const inviteUrl = ref('');

async function invite(ids) {
  try {
    const res = await http.post('client-portal/accounts/invite', { ids });
    if (res.failed && res.invite_url) {
      inviteUrl.value = res.invite_url;
      linkOpen.value = true;
    } else if (res.failed) {
      message.warning(t('Portal_Invites_Partial', { sent: res.sent, failed: res.failed }));
    } else {
      message.success(t('Portal_Invites_Sent', { n: res.sent }));
    }
    crud.fetchRows();
  } catch (e) {
    message.error(errText(e));
  }
}

function bulkInvite() {
  const ids = [...selected.value];
  Modal.confirm({
    title: t('Portal_Invite_Selected_Title', { n: ids.length }),
    content: t('Portal_Invite_Selected_Help'),
    okText: t('Portal_Send_Invitation'),
    cancelText: t('Cancel'),
    onOk: () => invite(ids),
  });
}

async function copyLink() {
  try {
    await navigator.clipboard.writeText(inviteUrl.value);
    message.success(t('Copied'));
  } catch (e) {
    message.info(inviteUrl.value);
  }
}

/* ----------------------------------------------------------- grant access */
const saving = ref(false);
const grantOpen = ref(false);
const grant = ref({ client_id: undefined, email: '', mode: 'invite', password: '' });
const clients = ref([]);
const clientsLoading = ref(false);
const clientOptions = computed(() => clients.value.map(c => ({
  value: c.id,
  label: [c.name, c.code, c.email || c.phone].filter(Boolean).join(' · '),
})));

let searchTimer = null;
function searchClients(q = '') {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(async () => {
    clientsLoading.value = true;
    try {
      const res = await http.get('client-portal/accounts/available-clients', { search: q });
      clients.value = res.clients || [];
    } catch (e) {
      clients.value = [];
    } finally {
      clientsLoading.value = false;
    }
  }, 250);
}

function onClientPicked(id) {
  const c = clients.value.find(x => x.id === id);
  if (c && c.email) grant.value.email = c.email;
}

function openGrant() {
  grant.value = { client_id: undefined, email: '', mode: 'invite', password: '' };
  grantOpen.value = true;
  searchClients('');
}

async function submitGrant() {
  const g = grant.value;
  if (!g.client_id || !(g.email || '').trim() || (g.mode === 'password' && !g.password)) {
    message.warning(t('Field_is_required'));
    return;
  }
  saving.value = true;
  try {
    const res = await http.post('client-portal/accounts', { ...g, email: g.email.trim() });
    grantOpen.value = false;
    if (g.mode === 'invite' && !res.mail_sent && res.invite_url) {
      inviteUrl.value = res.invite_url;
      linkOpen.value = true;
    } else {
      message.success(g.mode === 'invite' ? t('Portal_Invites_Sent', { n: 1 }) : t('Success'));
    }
    crud.fetchRows();
  } catch (e) {
    message.error(errText(e));
  } finally {
    saving.value = false;
  }
}

/* ------------------------------------------------------------------- edit */
const editOpen = ref(false);
const editForm = ref({ id: null, client_name: '', email: '', password: '' });

function openEdit(record) {
  editForm.value = { id: record.id, client_name: record.client_name, email: record.email, password: '' };
  editOpen.value = true;
}

async function submitEdit() {
  const f = editForm.value;
  if (!(f.email || '').trim()) {
    message.warning(t('Field_is_required'));
    return;
  }
  saving.value = true;
  try {
    await http.put(`client-portal/accounts/${f.id}`, { email: f.email.trim(), password: f.password || null });
    editOpen.value = false;
    message.success(t('Success'));
    crud.fetchRows();
  } catch (e) {
    message.error(errText(e));
  } finally {
    saving.value = false;
  }
}

onMounted(() => {
  crud.fetchRows();
  // Dashboard "Customers without access" tile opens the grant dialog directly.
  if (route.query.grant) openGrant();
});
</script>

<style scoped>
.toolbar { display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; padding: 12px 16px; }
.sel-count { font-size: 13px; opacity: .7; }
.cust { font-weight: 500; }
.sub { font-size: 12px; opacity: .6; font-weight: 400; }
.modes { display: flex; flex-direction: column; gap: 10px; }
.modes :deep(.ant-radio-wrapper) { align-items: flex-start; }
</style>
