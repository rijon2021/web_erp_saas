<template>
  <div class="page">
    <PageHeader :title="$t('Cr_Courier_Logs')" :breadcrumb="[$t('Cr_Couriers'), $t('Cr_Courier_Logs')]">
      <template #actions>
        <a-dropdown-button danger :loading="clearing" :disabled="!ready" @click="confirmClear('old')">
          <DeleteOutlined /> {{ $t('Cr_Clear_Old_Logs') }}
          <template #overlay>
            <a-menu @click="({ key }) => confirmClear(key)">
              <a-menu-item key="old"><DeleteOutlined /> {{ $t('Cr_Clear_Old_Logs') }}</a-menu-item>
              <a-menu-divider />
              <a-menu-item key="all" danger><DeleteOutlined /> {{ $t('Cr_Clear_All_Logs') }}</a-menu-item>
            </a-menu>
          </template>
        </a-dropdown-button>
      </template>
    </PageHeader>

    <a-alert v-if="!ready" type="info" show-icon :message="$t('Cr_Logs_Migration')" style="margin-bottom: 16px" />
    <a-alert v-else-if="errors24h" type="warning" show-icon style="margin-bottom: 16px"
      :message="$t('Cr_Logs_Errors_24h', { n: errors24h })">
      <template #action>
        <a-button size="small" @click="showErrors">{{ $t('Cr_Show_Errors') }}</a-button>
      </template>
    </a-alert>

    <a-card size="small" style="margin-bottom: 16px">
      <div class="toolbar">
        <a-input-search v-model:value="crud.search.value" :placeholder="$t('Cr_Search_Logs')" allow-clear class="tb-search" @search="crud.reload" />
        <a-select v-model:value="filters.account_id" class="tb-item" allow-clear show-search option-filter-prop="label"
          :placeholder="$t('Cr_Courier_Account')" :options="accountOptions" @change="crud.reload" />
        <a-select v-model:value="filters.action" class="tb-item" allow-clear :placeholder="$t('Cr_Log_Action')" :options="actionOptions" @change="crud.reload" />
        <a-select v-model:value="filters.level" class="tb-small" allow-clear :placeholder="$t('Cr_Log_Level')" :options="levelOptions" @change="crud.reload" />
        <a-select v-model:value="filters.direction" class="tb-small" allow-clear :placeholder="$t('Cr_Log_Direction')" :options="directionOptions" @change="crud.reload" />
        <a-range-picker v-model:value="filters.range" class="tb-range" value-format="YYYY-MM-DD" @change="crud.reload" />
        <a-tag v-if="filters.shipment_id" closable color="purple" @close="clearShipment">{{ $t('Cr_Shipment_N', { n: filters.shipment_id }) }}</a-tag>
      </div>
      <div class="muted hint">{{ $t('Cr_Logs_Help', { days: keepDays }) }}</div>
    </a-card>

    <DataTable :crud="crud" :columns="columns" :searchable="false" row-key="id">
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'created_at'">{{ dateTime(record.created_at) }}</template>
        <template v-else-if="column.key === 'level'">
          <a-tag :color="record.level === 'error' ? 'error' : 'success'">{{ record.level === 'error' ? $t('Cr_Log_Error') : $t('Cr_Log_OK') }}</a-tag>
        </template>
        <template v-else-if="column.key === 'account'">
          <div>{{ record.account_name || '—' }}</div>
          <div class="muted small">{{ record.courier_name }}</div>
        </template>
        <template v-else-if="column.key === 'action'">
          <a-tag :color="record.direction === 'in' ? 'purple' : 'blue'">
            {{ record.direction === 'in' ? '⇠' : '⇢' }} {{ actionLabel(record.action) }}
          </a-tag>
        </template>
        <template v-else-if="column.key === 'message'">
          <div class="msg" :class="{ 'msg--bad': record.level === 'error' }">{{ record.message }}</div>
          <div v-if="record.url" class="muted small url">{{ record.method }} {{ record.url }}</div>
        </template>
        <template v-else-if="column.key === 'http'">
          <span v-if="record.http_status" :class="{ 'msg--bad': record.http_status >= 400 }">{{ record.http_status }}</span>
          <span v-else class="muted">—</span>
          <div v-if="record.duration_ms !== null && record.duration_ms !== undefined" class="muted small">{{ record.duration_ms }} ms</div>
        </template>
        <template v-else-if="column.key === 'actions'">
          <a-button type="link" size="small" @click="inspect(record)">{{ $t('Cr_Details') }}</a-button>
        </template>
      </template>
    </DataTable>

    <a-drawer :open="drawerOpen" :title="$t('Cr_Log_Entry')" placement="right" :width="drawerWidth" @close="drawerOpen = false">
      <a-spin :spinning="loadingEntry">
        <template v-if="entry">
          <a-descriptions bordered size="small" :column="1">
            <a-descriptions-item :label="$t('Cr_Log_Level')">
              <a-tag :color="entry.level === 'error' ? 'error' : 'success'">{{ entry.level === 'error' ? $t('Cr_Log_Error') : $t('Cr_Log_OK') }}</a-tag>
            </a-descriptions-item>
            <a-descriptions-item :label="$t('Cr_Log_Action')">
              {{ entry.direction === 'in' ? $t('Cr_Log_In') : $t('Cr_Log_Out') }} · {{ actionLabel(entry.action) }}
            </a-descriptions-item>
            <a-descriptions-item :label="$t('Cr_Courier_Account')">{{ entry.account_name || '—' }} <span class="muted">{{ entry.courier_name }}</span></a-descriptions-item>
            <a-descriptions-item v-if="entry.shipment_id" :label="$t('Cr_Courier_Shipment')">
              <a @click="filterShipment(entry.shipment_id)">#{{ entry.shipment_id }}</a>
              <router-link v-if="entry.sale_id" :to="`/sales/${entry.sale_id}`" style="margin-left: 12px">{{ $t('Cr_View_Sale') }}</router-link>
            </a-descriptions-item>
            <a-descriptions-item v-if="entry.url" label="URL"><code class="wrap">{{ entry.method }} {{ entry.url }}</code></a-descriptions-item>
            <a-descriptions-item v-if="entry.http_status" label="HTTP">{{ entry.http_status }}<span v-if="entry.duration_ms !== null" class="muted"> · {{ entry.duration_ms }} ms</span></a-descriptions-item>
            <a-descriptions-item :label="$t('date')">{{ dateTime(entry.created_at) }}</a-descriptions-item>
          </a-descriptions>

          <p class="drawer-msg" :class="{ 'msg--bad': entry.level === 'error' }">{{ entry.message }}</p>

          <template v-if="entry.request">
            <div class="block-head">
              <a-typography-title :level="5" style="margin: 0">{{ entry.direction === 'in' ? $t('Cr_Log_Received') : $t('Cr_Log_Sent') }}</a-typography-title>
              <a @click="copy(entry.request)"><CopyOutlined /> {{ $t('Cr_Copy') }}</a>
            </div>
            <pre class="payload">{{ entry.request }}</pre>
          </template>
          <template v-if="entry.response">
            <div class="block-head">
              <a-typography-title :level="5" style="margin: 0">{{ $t('Cr_Log_Answer') }}</a-typography-title>
              <a @click="copy(entry.response)"><CopyOutlined /> {{ $t('Cr_Copy') }}</a>
            </div>
            <pre class="payload">{{ entry.response }}</pre>
          </template>
          <p class="muted small">{{ $t('Cr_Logs_Masked') }}</p>
        </template>
      </a-spin>
    </a-drawer>
  </div>
</template>

<script setup>
/**
 * Couriers → Logs — GET couriers/logs (filters: account_id, action, level,
 * direction, from, to, shipment_id, search) → {logs, totalRows, ready,
 * errors_24h, accounts, keep_days}; GET couriers/logs/{id} → masked request /
 * response bodies; DELETE couriers/logs[?all=1] (default: older than 7 days).
 */
import { ref, reactive, computed, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import { message, Modal } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { DeleteOutlined, CopyOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import http from '../../lib/http';
import { errorText } from './courierShared';

const { t } = useI18n();
const route = useRoute();
const { dateTime } = useFormat();

const ACTIONS = ['test', 'book', 'rates', 'cities', 'label', 'cancel', 'track', 'webhook', 'other'];
const actionLabel = a => t('Cr_Log_Action_' + (ACTIONS.includes(a) ? a : 'other'));

const filters = reactive({
  account_id: route.query.account_id ? Number(route.query.account_id) : undefined,
  shipment_id: route.query.shipment_id ? Number(route.query.shipment_id) : undefined,
  action: route.query.action || undefined,
  level: route.query.level || undefined,
  direction: undefined,
  range: null,
});

const ready = ref(true);
const errors24h = ref(0);
const keepDays = ref(30);
const accounts = ref([]);

const crud = useCrudTable('couriers/logs', {
  rowsKey: 'logs',
  limit: 25,
  pageSizeOptions: ['10', '25', '50', '100', '200'], // the server pages logs at 200 max

  params: () => ({
    account_id: filters.account_id || '',
    shipment_id: filters.shipment_id || '',
    action: filters.action || '',
    level: filters.level || '',
    direction: filters.direction || '',
    from: filters.range?.[0] || '',
    to: filters.range?.[1] || '',
  }),
  select: p => {
    ready.value = p.ready !== false;
    errors24h.value = p.errors_24h || 0;
    keepDays.value = p.keep_days || 30;
    accounts.value = p.accounts || [];
    return { rows: p.logs || [], total: p.totalRows || 0 };
  },
});

const accountOptions = computed(() => accounts.value.map(a => ({ value: a.id, label: a.name })));
const actionOptions = computed(() => ACTIONS.map(a => ({ value: a, label: actionLabel(a) })));
const levelOptions = computed(() => [{ value: 'error', label: t('Cr_Log_Error') }, { value: 'info', label: t('Cr_Log_OK') }]);
const directionOptions = computed(() => [{ value: 'out', label: t('Cr_Log_Out') }, { value: 'in', label: t('Cr_Log_In') }]);

const columns = computed(() => [
  { title: t('date'), key: 'created_at', width: 165 },
  { title: t('Cr_Log_Level'), key: 'level', width: 90 },
  { title: t('Cr_Courier_Account'), key: 'account', width: 190 },
  { title: t('Cr_Log_Action'), key: 'action', width: 150 },
  { title: t('Cr_Log_Message'), key: 'message' },
  { title: 'HTTP', key: 'http', width: 90, align: 'right' },
  { title: '', key: 'actions', width: 90, align: 'center' },
]);

function showErrors() {
  filters.level = 'error';
  crud.reload();
}

function clearShipment() {
  filters.shipment_id = undefined;
  crud.reload();
}

function filterShipment(id) {
  filters.shipment_id = id;
  drawerOpen.value = false;
  crud.reload();
}

// ---------------- drawer ----------------

const drawerOpen = ref(false);
const loadingEntry = ref(false);
const entry = ref(null);
const drawerWidth = computed(() => (window.innerWidth < 760 ? '100%' : 640));

async function inspect(record) {
  entry.value = record;
  drawerOpen.value = true;
  loadingEntry.value = true;
  try {
    const res = await http.get(`couriers/logs/${record.id}`);
    entry.value = res.log || record;
  } catch (e) {
    message.error(errorText(e, t('Failed')));
  } finally {
    loadingEntry.value = false;
  }
}

function copy(text) {
  try {
    navigator.clipboard.writeText(text);
    message.success(t('Cr_Copied'));
  } catch (e) {
    /* the block stays selectable */
  }
}

// ---------------- clear ----------------

const clearing = ref(false);

function confirmClear(scope) {
  const all = scope === 'all';
  Modal.confirm({
    title: all ? t('Cr_Clear_All_Logs') + '?' : t('Cr_Clear_Old_Logs') + '?',
    content: all ? t('Cr_Clear_All_Help') : t('Cr_Clear_Old_Help'),
    okType: 'danger',
    okText: all ? t('Cr_Clear_All_Logs') : t('Cr_Clear_Old_Logs'),
    cancelText: t('Cancel'),
    async onOk() {
      clearing.value = true;
      try {
        const res = await http.delete(`couriers/logs${all ? '?all=1' : ''}`);
        message.success(t('Cr_Logs_Removed', { n: res?.deleted ?? 0 }));
        crud.fetchRows();
      } catch (e) {
        message.error(errorText(e, t('Failed')));
      } finally {
        clearing.value = false;
      }
    },
  });
}

onMounted(() => crud.fetchRows());
</script>

<style scoped>
.muted {
  color: rgba(var(--ink-rgb), 0.45);
}
.small {
  font-size: 11.5px;
}
.hint {
  margin-top: 8px;
  font-size: 12px;
}
.toolbar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 10px;
}
.tb-search {
  flex: 1 1 200px;
  min-width: 170px;
}
.tb-item {
  width: 180px;
}
.tb-small {
  width: 130px;
}
.tb-range {
  width: 230px;
}
.msg {
  overflow: hidden;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  font-size: 12.5px;
}
.msg--bad {
  color: #dc2626;
}
.url {
  word-break: break-all;
}
.drawer-msg {
  margin: 16px 0;
  font-size: 13px;
}
.block-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin: 14px 0 6px;
}
.payload {
  background: rgba(128, 128, 128, 0.1);
  padding: 12px;
  border-radius: 8px;
  font-size: 11.5px;
  overflow: auto;
  max-height: 360px;
  white-space: pre-wrap;
  word-break: break-all;
}
.wrap {
  word-break: break-all;
}
@media (max-width: 767px) {
  .tb-item,
  .tb-small,
  .tb-range {
    width: 100%;
  }
}
</style>
