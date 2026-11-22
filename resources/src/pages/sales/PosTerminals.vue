<template>
  <div class="page">
    <PageHeader :title="$t('Pd_Pos_Devices')" :breadcrumb="[$t('Sales'), $t('Pd_Pos_Devices')]">
      <template #extra>
        <a-button v-if="canManage && tab === 'devices'" type="primary" :disabled="!ready" @click="openCreate">
          <template #icon><PlusOutlined /></template>
          {{ $t('Pd_Add_Device') }}
        </a-button>
      </template>
    </PageHeader>

    <a-alert v-if="!ready" type="info" show-icon :message="$t('Pd_Not_Ready')" style="margin-bottom: 16px" />

    <a-tabs v-model:activeKey="tab">
      <a-tab-pane v-if="canManage" key="devices" :tab="$t('Pd_Devices')">
        <a-card size="small" :body-style="{ padding: 0 }">
          <a-table :columns="columns" :data-source="terminals" :loading="isLoading" :pagination="false" size="middle" row-key="id" :scroll="{ x: 900 }">
            <template #emptyText>
              <a-empty :description="$t('Pd_No_Devices')" style="padding: 24px 0">
                <a-button type="primary" :disabled="!ready" @click="openCreate">{{ $t('Pd_Add_Device') }}</a-button>
              </a-empty>
            </template>
            <template #bodyCell="{ column, record }">
              <template v-if="column.key === 'name'">
                <strong>{{ record.name }}</strong>
                <div class="muted small">{{ record.model_label }}<span v-if="record.serial_number"> · {{ record.serial_number }}</span></div>
              </template>
              <template v-else-if="column.key === 'warehouse'">
                <span v-if="record.warehouse_name">{{ record.warehouse_name }}</span>
                <span v-else class="muted">{{ $t('Pd_All_Warehouses') }}</span>
              </template>
              <template v-else-if="column.key === 'payment_method'">{{ record.payment_method_name || '—' }}</template>
              <template v-else-if="column.key === 'connection'">
                <a-tag :color="record.connection_mode === 'cloud' ? 'blue' : 'geekblue'">
                  {{ record.connection_mode === 'cloud' ? $t('Pd_Mode_Cloud') : $t('Pd_Mode_Bridge') }}
                </a-tag>
                <div class="muted small ellipsis">{{ record.connection_mode === 'cloud' ? record.api_base_url : record.bridge_url }}</div>
              </template>
              <template v-else-if="column.key === 'receipt'">
                {{ record.fiscal_mode === 'full_receipt' ? $t('Pd_Fiscal_Full') : $t('Pd_Fiscal_Payment_Only') }}
              </template>
              <template v-else-if="column.key === 'status'">
                <a-space :size="4" wrap>
                  <a-tag :color="record.is_active ? 'success' : 'default'">{{ record.is_active ? $t('Active') : $t('Inactive') }}</a-tag>
                  <a-tag v-if="record.is_test" color="purple">{{ $t('Pd_Test_Mode') }}</a-tag>
                </a-space>
                <div v-if="record.last_seen_at" class="muted small">{{ $t('Pd_Last_Seen') }}: {{ dateTime(record.last_seen_at) }}</div>
              </template>
              <template v-else-if="column.key === 'actions'">
                <a-space>
                  <a-tooltip :title="$t('Pd_Test_Connection')">
                    <a-button size="small" :loading="testingId === record.id" @click="testDevice(record)">
                      <template #icon><ApiOutlined /></template>
                    </a-button>
                  </a-tooltip>
                  <a-button size="small" @click="openEdit(record)"><template #icon><EditOutlined /></template></a-button>
                  <a-button size="small" danger @click="confirmDelete(record)"><template #icon><DeleteOutlined /></template></a-button>
                </a-space>
              </template>
            </template>
          </a-table>
        </a-card>
      </a-tab-pane>

      <a-tab-pane v-if="canViewTransactions" key="transactions" :tab="$t('Pd_Transactions')">
        <PosTerminalTransactions v-if="tab === 'transactions'" />
      </a-tab-pane>

      <a-tab-pane key="guide" :tab="$t('Pd_Setup_Guide')">
        <PosTerminalGuide />
      </a-tab-pane>
    </a-tabs>

    <a-modal
      v-model:open="modalOpen" :title="form.id ? $t('Pd_Edit_Device') : $t('Pd_Add_Device')"
      :confirm-loading="saving" :ok-text="$t('Save')" :cancel-text="$t('Cancel')" width="760px" @ok="save"
    >
      <a-form layout="vertical" style="margin-top: 12px">
        <a-row :gutter="16">
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Pd_Device_Name') + ' *'" :extra="$t('Pd_Device_Name_Help')">
              <a-input v-model:value="form.name" :maxlength="191" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Pd_Model') + ' *'" :extra="selectedModel ? selectedModel.description : undefined">
              <a-select v-model:value="form.driver" :options="modelOptions" @change="applyModelDefaults" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('warehouse')" :extra="$t('Pd_Warehouse_Help')">
              <a-select v-model:value="form.warehouse_id" allow-clear show-search option-filter-prop="label"
                :placeholder="$t('Pd_All_Warehouses')" :options="warehouseOptions" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Pd_Payment_Method') + ' *'" :extra="$t('Pd_Payment_Method_Help')">
              <a-select v-model:value="form.payment_method_id" show-search option-filter-prop="label" :options="paymentMethodOptions" />
            </a-form-item>
          </a-col>
        </a-row>

        <a-divider orientation="left" plain>{{ $t('Pd_Connection') }}</a-divider>
        <a-form-item :label="$t('Pd_Connection_Type')">
          <a-radio-group v-model:value="form.connection_mode">
            <a-radio-button v-if="modeAllowed('bridge')" value="bridge">{{ $t('Pd_Mode_Bridge') }}</a-radio-button>
            <a-radio-button v-if="modeAllowed('cloud')" value="cloud">{{ $t('Pd_Mode_Cloud') }}</a-radio-button>
          </a-radio-group>
          <div class="muted hint">{{ form.connection_mode === 'cloud' ? $t('Pd_Mode_Cloud_Help') : $t('Pd_Mode_Bridge_Help') }}</div>
        </a-form-item>

        <a-row :gutter="16">
          <a-col v-if="form.connection_mode === 'bridge'" :xs="24">
            <a-form-item :label="$t('Pd_Bridge_Url')" :extra="$t('Pd_Bridge_Url_Help')">
              <a-input v-model:value="form.bridge_url" placeholder="http://127.0.0.1:8085" />
            </a-form-item>
          </a-col>
          <template v-else>
            <a-col :xs="24" :md="16">
              <a-form-item :label="$t('Pd_Api_Base_Url') + ' *'" :extra="$t('Pd_Api_Base_Url_Help')">
                <a-input v-model:value="form.api_base_url" placeholder="https://api.example.com/ecr/v1" />
              </a-form-item>
            </a-col>
            <a-col :xs="24" :md="8">
              <a-form-item :label="$t('Pd_Auth_Type')">
                <a-select v-model:value="form.auth_type" :options="authOptions" />
              </a-form-item>
            </a-col>
          </template>
          <a-col v-if="form.connection_mode === 'cloud' && form.auth_type !== 'none'" :xs="24" :md="12">
            <a-form-item :label="$t('Pd_Api_Key')" :extra="stored.has_api_key ? $t('Pd_Secret_Stored', { hint: stored.api_key_hint }) : undefined">
              <a-input-password v-model:value="form.api_key" autocomplete="new-password" :placeholder="stored.has_api_key ? $t('Pd_Keep_Current') : ''" />
              <a-checkbox v-if="stored.has_api_key" v-model:checked="form.remove_api_key" class="mt-6">{{ $t('Pd_Remove_Stored') }}</a-checkbox>
            </a-form-item>
          </a-col>
          <a-col v-if="form.connection_mode === 'bridge' || form.auth_type !== 'none'" :xs="24" :md="12">
            <a-form-item
              :label="(form.connection_mode === 'bridge' ? $t('Pd_Shared_Secret') : $t('Pd_Api_Secret')) + (form.connection_mode === 'bridge' && !stored.has_api_secret ? ' *' : '')"
              :extra="stored.has_api_secret ? $t('Pd_Secret_Stored', { hint: stored.api_secret_hint }) : (form.connection_mode === 'bridge' ? $t('Pd_Shared_Secret_Help') : undefined)"
            >
              <a-input-password v-model:value="form.api_secret" autocomplete="new-password" :placeholder="stored.has_api_secret ? $t('Pd_Keep_Current') : ''" />
              <a-space v-if="form.connection_mode === 'bridge'" class="mt-6">
                <a-button size="small" @click="generateSecret">{{ $t('Pd_Generate_Secret') }}</a-button>
                <a-checkbox v-if="stored.has_api_secret" v-model:checked="form.remove_api_secret">{{ $t('Pd_Remove_Stored') }}</a-checkbox>
              </a-space>
              <a-checkbox v-else-if="stored.has_api_secret" v-model:checked="form.remove_api_secret" class="mt-6">{{ $t('Pd_Remove_Stored') }}</a-checkbox>
            </a-form-item>
          </a-col>
        </a-row>

        <a-divider orientation="left" plain>{{ $t('Pd_Device_Ids') }}</a-divider>
        <a-row :gutter="16">
          <a-col :xs="24" :md="8"><a-form-item :label="$t('Pd_Merchant_Id')"><a-input v-model:value="form.merchant_id" :maxlength="64" /></a-form-item></a-col>
          <a-col :xs="24" :md="8"><a-form-item :label="$t('Pd_Terminal_Id')"><a-input v-model:value="form.terminal_id" :maxlength="64" /></a-form-item></a-col>
          <a-col :xs="24" :md="8"><a-form-item :label="$t('Pd_Serial_Number')" :extra="$t('Pd_Serial_Number_Help')"><a-input v-model:value="form.serial_number" :maxlength="64" /></a-form-item></a-col>
          <a-col :xs="24" :md="16"><a-form-item :label="$t('Pd_Device_Ip')" :extra="$t('Pd_Device_Ip_Help')"><a-input v-model:value="form.ip_address" :maxlength="64" placeholder="192.168.1.50" /></a-form-item></a-col>
          <a-col :xs="24" :md="8"><a-form-item :label="$t('Pd_Device_Port')"><a-input-number v-model:value="form.port" :min="1" :max="65535" style="width: 100%" /></a-form-item></a-col>
        </a-row>

        <a-divider orientation="left" plain>{{ $t('Pd_Receipt') }}</a-divider>
        <a-form-item>
          <a-radio-group v-model:value="form.fiscal_mode">
            <a-radio value="payment_only">{{ $t('Pd_Fiscal_Payment_Only') }}</a-radio>
            <a-radio value="full_receipt" :disabled="!supports('fiscal_receipt')">{{ $t('Pd_Fiscal_Full') }}</a-radio>
          </a-radio-group>
          <div class="muted hint">{{ form.fiscal_mode === 'full_receipt' ? $t('Pd_Fiscal_Full_Help') : $t('Pd_Fiscal_Payment_Only_Help') }}</div>
        </a-form-item>
        <div v-if="form.fiscal_mode === 'full_receipt'" class="dept-box">
          <div class="dept-head">
            <strong>{{ $t('Pd_Departments') }}</strong>
            <span class="muted small">{{ $t('Pd_Departments_Help') }}</span>
          </div>
          <div v-for="(d, i) in form.settings.departments" :key="i" class="dept-row">
            <a-input-number v-model:value="d.vat_rate" :min="0" :max="100" :precision="2" :addon-after="'%'" :placeholder="$t('Pd_Vat_Rate')" />
            <a-input-number v-model:value="d.department" :min="1" :max="99" :addon-before="$t('Pd_Department')" />
            <a-button size="small" danger @click="form.settings.departments.splice(i, 1)"><template #icon><DeleteOutlined /></template></a-button>
          </div>
          <a-button size="small" @click="form.settings.departments.push({ vat_rate: 0, department: 1 })"><PlusOutlined /> {{ $t('Pd_Add_Department') }}</a-button>
        </div>

        <a-collapse ghost class="advanced">
          <a-collapse-panel key="adv" :header="$t('Pd_Advanced')">
            <a-row :gutter="16">
              <a-col :xs="24" :md="8">
                <a-form-item :label="$t('Pd_Timeout_Seconds')" :extra="$t('Pd_Timeout_Seconds_Help')">
                  <a-input-number v-model:value="form.settings.timeout_seconds" :min="15" :max="300" style="width: 100%" />
                </a-form-item>
              </a-col>
              <a-col v-if="supports('installments')" :xs="24" :md="8">
                <a-form-item :label="$t('Pd_Max_Installments')" :extra="$t('Pd_Max_Installments_Help')">
                  <a-input-number v-model:value="form.settings.max_installments" :min="1" :max="36" style="width: 100%" />
                </a-form-item>
              </a-col>
              <a-col v-if="form.connection_mode === 'cloud' && ['header', 'hmac'].includes(form.auth_type)" :xs="24" :md="8">
                <a-form-item :label="$t('Pd_Auth_Header')">
                  <a-input v-model:value="form.settings.auth_header" placeholder="X-Api-Key" />
                </a-form-item>
              </a-col>
            </a-row>
            <div class="muted small" style="margin-bottom: 8px">{{ $t('Pd_Endpoints_Help', { reference: '{reference}' }) }}</div>
            <a-row :gutter="16">
              <a-col v-for="op in ENDPOINT_OPS" :key="op" :xs="24" :md="12">
                <a-form-item :label="opLabel(op)">
                  <a-input v-model:value="form.settings.endpoints[op]" :placeholder="defaultEndpoint(op)" />
                </a-form-item>
              </a-col>
            </a-row>
          </a-collapse-panel>
        </a-collapse>

        <a-space :size="24" wrap>
          <a-checkbox v-model:checked="form.is_test">{{ $t('Pd_Test_Mode') }}</a-checkbox>
          <a-checkbox v-model:checked="form.is_active">{{ $t('Active') }}</a-checkbox>
        </a-space>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * Sales > POS Devices — card terminals / fiscal registers (Ingenico IDE280…).
 *   GET pos-terminals → {ready, terminals, models, warehouses, payment_methods}
 *   POST / PUT pos-terminals[/{id}], DELETE pos-terminals/{id}
 *   POST pos-terminals/{id}/test (+ test-result for bridge devices, see lib/posTerminal)
 * Secrets are write-only: blank on edit keeps the stored value.
 */
import { ref, computed, watch, onMounted, createVNode } from 'vue';
import { message, Modal } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { PlusOutlined, EditOutlined, DeleteOutlined, ApiOutlined, ExclamationCircleOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';
import { testTerminal } from '../../lib/posTerminal';
import { useAuthStore } from '../../stores/auth';
import { useFormat } from '../../composables/useFormat';
import { errorText } from '../couriers/courierShared';
import PosTerminalTransactions from './PosTerminalTransactions.vue';
import PosTerminalGuide from './PosTerminalGuide.vue';

const { t } = useI18n();
const { dateTime } = useFormat();
const auth = useAuthStore();

const canManage = computed(() => auth.can('pos_terminals_manage'));
const canViewTransactions = computed(() => auth.can('pos_terminals_transactions'));
const tab = ref(canManage.value ? 'devices' : (canViewTransactions.value ? 'transactions' : 'guide'));

const ENDPOINT_OPS = ['ping', 'sale', 'refund', 'void', 'status', 'cancel'];

const ready = ref(true);
const isLoading = ref(false);
const saving = ref(false);
const testingId = ref(null);
const terminals = ref([]);
const models = ref([]);
const warehouses = ref([]);
const paymentMethods = ref([]);
const modalOpen = ref(false);
const stored = ref({});

const emptySettings = (defaults = {}) => ({
  timeout_seconds: defaults.timeout_seconds ?? 120,
  max_installments: defaults.max_installments ?? 1,
  auth_header: '',
  endpoints: {},
  departments: (defaults.departments || []).map(d => ({ ...d })),
});
const emptyForm = () => ({
  id: null, name: '', driver: models.value[0]?.code, warehouse_id: undefined, payment_method_id: undefined,
  connection_mode: 'bridge', api_base_url: '', bridge_url: models.value[0]?.defaults?.bridge_url || 'http://127.0.0.1:8085',
  auth_type: 'bearer', api_key: '', api_secret: '', remove_api_key: false, remove_api_secret: false,
  merchant_id: '', terminal_id: '', serial_number: '', ip_address: '', port: null,
  fiscal_mode: 'payment_only', is_test: true, is_active: true,
  settings: emptySettings(models.value[0]?.defaults),
});
const form = ref(emptyForm());
// Typing a new key / secret means "replace", not "remove".
watch(() => form.value.api_key, v => { if (v) form.value.remove_api_key = false; });
watch(() => form.value.api_secret, v => { if (v) form.value.remove_api_secret = false; });

const selectedModel = computed(() => models.value.find(m => m.code === form.value.driver) || null);
const modelOptions = computed(() => models.value.map(m => ({ value: m.code, label: m.label })));
const warehouseOptions = computed(() => warehouses.value.map(w => ({ value: w.id, label: w.name })));
const paymentMethodOptions = computed(() => paymentMethods.value.map(p => ({ value: p.id, label: p.name })));
const authOptions = computed(() => [
  { value: 'bearer', label: t('Pd_Auth_Bearer') },
  { value: 'basic', label: t('Pd_Auth_Basic') },
  { value: 'header', label: t('Pd_Auth_Header_Key') },
  { value: 'hmac', label: t('Pd_Auth_Hmac') },
  { value: 'none', label: t('Pd_Auth_None') },
]);

const supports = cap => (selectedModel.value?.capabilities || []).includes(cap);
const modeAllowed = mode => (selectedModel.value?.modes || ['bridge', 'cloud']).includes(mode);
const defaultEndpoint = op => selectedModel.value?.defaults?.endpoints?.[op] || '';
const opLabel = op => t({
  ping: 'Pd_Op_Ping', sale: 'Pd_Op_Sale', refund: 'Pd_Op_Refund', void: 'Pd_Op_Void', status: 'Pd_Op_Status', cancel: 'Pd_Op_Cancel',
}[op]);

const columns = computed(() => [
  { title: t('Name'), key: 'name' },
  { title: t('warehouse'), key: 'warehouse', width: 150 },
  { title: t('Pd_Payment_Method'), key: 'payment_method', width: 140 },
  { title: t('Pd_Connection'), key: 'connection', width: 200 },
  { title: t('Pd_Receipt'), key: 'receipt', width: 140 },
  { title: t('Status'), key: 'status', width: 170 },
  { title: t('Actions'), key: 'actions', width: 130 },
]);

async function load() {
  if (!canManage.value) return;
  isLoading.value = true;
  try {
    const resp = await http.get('pos-terminals');
    ready.value = resp.ready !== false;
    terminals.value = resp.terminals || [];
    models.value = resp.models || [];
    warehouses.value = resp.warehouses || [];
    paymentMethods.value = resp.payment_methods || [];
  } catch (e) {
    message.error(errorText(e, t('Failed')));
  } finally {
    isLoading.value = false;
  }
}

function applyModelDefaults() {
  const d = selectedModel.value?.defaults || {};
  if (!form.value.id) {
    form.value.settings = emptySettings(d);
    form.value.bridge_url = d.bridge_url || form.value.bridge_url;
  }
  if (!modeAllowed(form.value.connection_mode)) form.value.connection_mode = (selectedModel.value?.modes || ['bridge'])[0];
  if (form.value.fiscal_mode === 'full_receipt' && !supports('fiscal_receipt')) form.value.fiscal_mode = 'payment_only';
}

function openCreate() {
  stored.value = {};
  form.value = emptyForm();
  modalOpen.value = true;
}

function openEdit(record) {
  stored.value = {
    has_api_key: record.has_api_key, api_key_hint: record.api_key_hint,
    has_api_secret: record.has_api_secret, api_secret_hint: record.api_secret_hint,
  };
  const model = models.value.find(m => m.code === record.driver);
  const s = record.settings || {};
  const defaultEndpoints = model?.defaults?.endpoints || {};
  // Only endpoints that differ from the model default are shown as edited.
  const endpoints = {};
  Object.entries(s.endpoints || {}).forEach(([op, v]) => { if (v && v !== defaultEndpoints[op]) endpoints[op] = v; });
  form.value = {
    ...emptyForm(),
    id: record.id, name: record.name, driver: record.driver,
    warehouse_id: record.warehouse_id || undefined, payment_method_id: record.payment_method_id || undefined,
    connection_mode: record.connection_mode, api_base_url: record.api_base_url || '', bridge_url: record.bridge_url || '',
    auth_type: record.auth_type || 'bearer', merchant_id: record.merchant_id || '', terminal_id: record.terminal_id || '',
    serial_number: record.serial_number || '', ip_address: record.ip_address || '', port: record.port || null,
    fiscal_mode: record.fiscal_mode, is_test: !!record.is_test, is_active: !!record.is_active,
    settings: {
      timeout_seconds: s.timeout_seconds ?? 120,
      max_installments: s.max_installments ?? 1,
      auth_header: s.auth_header || '',
      endpoints,
      departments: (s.departments || []).map(d => ({ ...d })),
    },
  };
  modalOpen.value = true;
}

function generateSecret() {
  const bytes = new Uint8Array(32);
  window.crypto.getRandomValues(bytes);
  form.value.api_secret = Array.from(bytes, b => b.toString(16).padStart(2, '0')).join('');
  form.value.remove_api_secret = false;
  Modal.info({
    title: t('Pd_Secret_Generated'),
    content: createVNode('div', null, [
      createVNode('p', null, t('Pd_Secret_Generated_Help')),
      createVNode('code', { style: 'word-break: break-all; user-select: all' }, form.value.api_secret),
    ]),
  });
}

async function save() {
  const f = form.value;
  if (!f.name.trim() || !f.driver || !f.payment_method_id) {
    message.warning(t('Pd_Fill_Required'));
    return;
  }
  const endpoints = {};
  Object.entries(f.settings.endpoints || {}).forEach(([op, v]) => { if (v && v.trim()) endpoints[op] = v.trim(); });
  const payload = {
    name: f.name, driver: f.driver, warehouse_id: f.warehouse_id || null, payment_method_id: f.payment_method_id,
    connection_mode: f.connection_mode, api_base_url: f.api_base_url || null, bridge_url: f.bridge_url || null,
    auth_type: f.auth_type, api_key: f.api_key || null, api_secret: f.api_secret || null,
    remove_api_key: !!f.remove_api_key, remove_api_secret: !!f.remove_api_secret,
    merchant_id: f.merchant_id || null, terminal_id: f.terminal_id || null, serial_number: f.serial_number || null,
    ip_address: f.ip_address || null, port: f.port || null, fiscal_mode: f.fiscal_mode,
    is_test: !!f.is_test, is_active: !!f.is_active,
    settings: {
      timeout_seconds: f.settings.timeout_seconds || 120,
      max_installments: f.settings.max_installments || 1,
      auth_header: f.settings.auth_header || null,
      endpoints,
      departments: f.fiscal_mode === 'full_receipt' ? f.settings.departments : (f.settings.departments || []),
    },
  };
  saving.value = true;
  try {
    if (f.id) await http.put(`pos-terminals/${f.id}`, payload);
    else await http.post('pos-terminals', payload);
    message.success(t('Successfully_Updated'));
    modalOpen.value = false;
    await load();
  } catch (e) {
    message.error(errorText(e, t('Failed')));
  } finally {
    saving.value = false;
  }
}

async function testDevice(record) {
  testingId.value = record.id;
  try {
    const r = await testTerminal(record.id);
    if (r && r.ok) {
      message.success(t('Pd_Test_Ok'));
    } else if (r && (r.message === 'bridge_unreachable' || r.message === 'bridge_timeout')) {
      message.error(t('Pd_Bridge_Unreachable', { url: r.url || record.bridge_url }), 6);
    } else {
      message.error((r && r.message) || t('Pd_Test_Failed'), 6);
    }
    await load();
  } catch (e) {
    message.error(errorText(e, t('Pd_Test_Failed')), 6);
  } finally {
    testingId.value = null;
  }
}

function confirmDelete(record) {
  Modal.confirm({
    title: t('Pd_Delete_Device'),
    icon: createVNode(ExclamationCircleOutlined),
    content: t('Pd_Delete_Device_Help', { name: record.name }),
    okText: t('Delete'),
    okType: 'danger',
    cancelText: t('Cancel'),
    async onOk() {
      try {
        await http.delete(`pos-terminals/${record.id}`);
        message.success(t('Deleted_in_successfully'));
        await load();
      } catch (e) {
        message.error(errorText(e, t('Failed')));
      }
    },
  });
}

onMounted(load);
</script>

<style scoped>
.muted {
  color: rgba(var(--ink-rgb), 0.45);
}
.small {
  font-size: 11.5px;
}
.hint {
  margin-top: 6px;
  font-size: 12px;
}
.ellipsis {
  max-width: 190px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.mt-6 {
  margin-top: 6px;
}
.dept-box {
  border: 1px solid var(--line);
  border-radius: 8px;
  padding: 12px;
  margin-bottom: 16px;
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.dept-head {
  display: flex;
  flex-direction: column;
  gap: 2px;
}
.dept-row {
  display: flex;
  gap: 8px;
  align-items: center;
  flex-wrap: wrap;
}
.advanced {
  margin-bottom: 12px;
}
.advanced :deep(.ant-collapse-header) {
  padding-left: 0 !important;
}
</style>
