<template>
  <div class="page">
    <PageHeader :title="$t('Cr_Courier_Accounts')" :breadcrumb="[$t('Cr_Couriers'), $t('Cr_Courier_Accounts')]">
      <template #extra>
        <a-button type="primary" @click="openCreate">
          <template #icon><PlusOutlined /></template>
          {{ $t('Cr_Add_Account') }}
        </a-button>
      </template>
    </PageHeader>

    <a-card size="small" :body-style="{ padding: 0 }">
      <a-table
        :columns="columns" :data-source="accounts" :loading="isLoading"
        :pagination="false" size="middle" row-key="id"
      >
        <template #emptyText>
          <a-empty :description="$t('Cr_No_Accounts')" style="padding: 24px 0">
            <a-button type="primary" @click="openCreate">{{ $t('Cr_Add_Account') }}</a-button>
          </a-empty>
        </template>
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'name'">
            <strong>{{ record.name }}</strong>
            <a-tag v-if="record.is_default" color="gold" style="margin-left: 8px">{{ $t('Cr_Default') }}</a-tag>
          </template>
          <template v-else-if="column.key === 'courier'">
            {{ record.courier_name }}
            <a-tag v-if="record.mode === 'test'" color="purple" style="margin-left: 6px">{{ $t('Cr_Test_Mode') }}</a-tag>
            <a-tag v-if="!record.available" color="error" style="margin-left: 6px">{{ $t('Cr_Unavailable') }}</a-tag>
          </template>
          <template v-else-if="column.key === 'warehouse'">
            <span v-if="record.warehouse_name">{{ record.warehouse_name }}</span>
            <span v-else class="muted">{{ $t('Cr_All_Warehouses') }}</span>
          </template>
          <template v-else-if="column.key === 'connection'">
            <a-tooltip :title="record.last_test_message || ''">
              <a-tag v-if="record.last_test_ok === true" color="success">{{ $t('Cr_Connected') }}</a-tag>
              <a-tag v-else-if="record.last_test_ok === false" color="error">{{ $t('Cr_Failed') }}</a-tag>
              <a-tag v-else>{{ $t('Cr_Not_Tested') }}</a-tag>
            </a-tooltip>
          </template>
          <template v-else-if="column.key === 'status'">
            <a-tag :color="record.active ? 'success' : 'default'">
              {{ record.active ? $t('Active') : $t('Inactive') }}
            </a-tag>
          </template>
          <template v-else-if="column.key === 'actions'">
            <a-space>
              <a-tooltip :title="$t('Cr_Test_Connection')">
                <a-button size="small" :loading="testingId === record.id" @click="testAccount(record)">
                  <template #icon><ApiOutlined /></template>
                </a-button>
              </a-tooltip>
              <a-tooltip v-if="!record.is_default && record.active" :title="$t('Cr_Make_Default')">
                <a-button size="small" @click="makeDefault(record)">
                  <template #icon><StarOutlined /></template>
                </a-button>
              </a-tooltip>
              <a-button size="small" :disabled="!record.available" @click="openEdit(record)">
                <template #icon><EditOutlined /></template>
              </a-button>
              <a-button size="small" danger @click="confirmDelete(record)">
                <template #icon><DeleteOutlined /></template>
              </a-button>
            </a-space>
          </template>
        </template>
      </a-table>
    </a-card>

    <a-modal
      v-model:open="modalOpen" :title="form.id ? $t('Cr_Edit_Account') : $t('Cr_Add_Account')"
      :confirm-loading="saving" :ok-button-props="{ disabled: !selectedMeta }" :ok-text="$t('Save')"
      width="760px" @ok="submit"
    >
      <!-- Step 1 (create only): pick the courier -->
      <template v-if="!form.id && !selectedMeta">
        <p class="muted" style="margin-top: 8px">{{ $t('Cr_Choose_Courier') }}</p>
        <a-row :gutter="[12, 12]">
          <a-col v-for="c in catalog.available" :key="c.code" :xs="24" :sm="12">
            <div class="courier-card" @click="pickCourier(c)">
              <div class="courier-card__name">{{ c.name }}</div>
              <div class="courier-card__region">{{ c.region }}</div>
              <div v-if="c.description" class="courier-card__desc">{{ c.description }}</div>
            </div>
          </a-col>
        </a-row>
        <template v-if="catalog.planned.length">
          <a-divider orientation="left" style="font-size: 13px">{{ $t('Cr_Coming_Soon') }}</a-divider>
          <a-input v-model:value="plannedSearch" :placeholder="$t('Search')" allow-clear size="small" style="max-width: 240px; margin-bottom: 10px" />
          <div class="planned">
            <a-tooltip v-for="p in plannedFiltered" :key="p.code" :title="p.region">
              <a-tag class="planned__tag">{{ p.name }}</a-tag>
            </a-tooltip>
          </div>
        </template>
      </template>

      <!-- Step 2: account form -->
      <a-form v-else layout="vertical" style="margin-top: 12px">
        <a-alert type="info" show-icon style="margin-bottom: 16px">
          <template #message>
            <strong>{{ selectedMeta.name }}</strong>
            <span v-if="selectedMeta.region" class="muted"> · {{ selectedMeta.region }}</span>
            <a v-if="!form.id" style="margin-left: 12px" @click="form.courier = null">{{ $t('Cr_Change') }}</a>
          </template>
          <template v-if="selectedMeta.description" #description>{{ selectedMeta.description }}</template>
        </a-alert>

        <a-alert v-if="form.id && webhookUrl" type="success" style="margin-bottom: 16px">
          <template #message>{{ $t('Cr_Webhook_URL') }}</template>
          <template #description>
            <div class="muted" style="margin-bottom: 4px">{{ $t('Cr_Webhook_URL_Help') }}</div>
            <a-typography-text :copyable="{ text: webhookUrl }" code>{{ webhookUrl }}</a-typography-text>
          </template>
        </a-alert>

        <a-row :gutter="16">
          <a-col :xs="24" :sm="selectedMeta.capabilities.test_mode ? 16 : 24">
            <a-form-item :label="$t('Cr_Account_Name') + ' *'" :extra="$t('Cr_Account_Name_Help')">
              <a-input v-model:value="form.name" :maxlength="120" />
            </a-form-item>
          </a-col>
          <a-col v-if="selectedMeta.capabilities.test_mode" :xs="24" :sm="8">
            <a-form-item :label="$t('Cr_Mode')">
              <a-radio-group v-model:value="form.mode" button-style="solid">
                <a-radio-button value="test">{{ $t('Cr_Test') }}</a-radio-button>
                <a-radio-button value="live">{{ $t('Cr_Live') }}</a-radio-button>
              </a-radio-group>
            </a-form-item>
          </a-col>
        </a-row>

        <template v-if="selectedMeta.credentials.length">
          <a-divider orientation="left" class="section">{{ $t('Cr_API_Credentials') }}</a-divider>
          <a-row :gutter="16">
            <a-col v-for="f in selectedMeta.credentials" :key="f.key" :xs="24" :sm="12">
              <a-form-item :label="f.label + (f.required && !credentialSet(f.key) ? ' *' : '')" :extra="f.help || undefined">
                <a-input-password
                  v-if="f.type === 'password'" v-model:value="form.credentials[f.key]"
                  :placeholder="credentialSet(f.key) ? $t('Cr_Saved_Keep', { hint: credentialHint(f.key) }) : ''"
                  autocomplete="new-password"
                />
                <a-input
                  v-else v-model:value="form.credentials[f.key]"
                  :placeholder="credentialSet(f.key) ? credentialHint(f.key) : ''"
                />
              </a-form-item>
            </a-col>
          </a-row>
        </template>

        <template v-if="selectedMeta.settings.length">
          <a-divider orientation="left" class="section">{{ $t('Cr_Courier_Options') }}</a-divider>
          <a-row :gutter="16">
            <a-col v-for="f in selectedMeta.settings" :key="f.key" :xs="24" :sm="12">
              <a-form-item :label="f.label + (f.required ? ' *' : '')" :extra="f.help || undefined">
                <a-select v-if="f.type === 'select'" v-model:value="form.settings[f.key]" :options="f.options" allow-clear />
                <a-input-number v-else-if="f.type === 'number'" v-model:value="form.settings[f.key]" style="width: 100%" />
                <a-switch v-else-if="f.type === 'switch'" v-model:checked="form.settings[f.key]" />
                <a-input v-else v-model:value="form.settings[f.key]" />
              </a-form-item>
            </a-col>
          </a-row>
        </template>

        <a-form-item :label="$t('Cr_Tracking_Link')" :extra="$t('Cr_Tracking_Link_Help', { ph: '{tracking}' })">
          <a-input v-model:value="form.tracking_url" placeholder="https://courier.example/track?id={tracking}" />
        </a-form-item>

        <a-divider orientation="left" class="section">{{ $t('Cr_Pickup_Address') }}</a-divider>
        <a-row :gutter="16">
          <a-col :xs="24" :sm="12">
            <a-form-item :label="$t('Cr_Contact_Name')"><a-input v-model:value="form.pickup_contact" /></a-form-item>
          </a-col>
          <a-col :xs="24" :sm="12">
            <a-form-item :label="$t('Phone')"><a-input v-model:value="form.pickup_phone" /></a-form-item>
          </a-col>
          <a-col :xs="24" :sm="16">
            <a-form-item :label="$t('Address')"><a-input v-model:value="form.pickup_address" /></a-form-item>
          </a-col>
          <a-col :xs="24" :sm="8">
            <a-form-item :label="$t('City')"><a-input v-model:value="form.pickup_city" /></a-form-item>
          </a-col>
        </a-row>

        <a-divider orientation="left" class="section">{{ $t('Cr_Booking_Defaults') }}</a-divider>
        <a-row :gutter="16">
          <a-col :xs="24" :sm="12">
            <a-form-item :label="$t('warehouse')" :extra="$t('Cr_Warehouse_Help')">
              <a-select v-model:value="form.warehouse_id" :options="warehouseOptions" allow-clear :placeholder="$t('Cr_All_Warehouses')" />
            </a-form-item>
          </a-col>
          <a-col :xs="12" :sm="6">
            <a-form-item :label="$t('Cr_Default_Weight')">
              <a-input-number v-model:value="form.default_weight" :min="0.001" :step="0.1" style="width: 100%" />
            </a-form-item>
          </a-col>
          <a-col :xs="12" :sm="6">
            <a-form-item :label="$t('Cr_Service_Type')">
              <a-input v-model:value="form.default_service" :maxlength="60" />
            </a-form-item>
          </a-col>
        </a-row>
        <a-space :size="32" wrap>
          <span v-if="selectedMeta.capabilities.cod && selectedMeta.code !== 'manual'">
            <a-switch v-model:checked="form.allow_open_parcel" />
            <span class="switch-label">{{ $t('Cr_Allow_Open_Parcel') }}</span>
          </span>
          <span>
            <a-switch v-model:checked="form.active" />
            <span class="switch-label">{{ form.active ? $t('Active') : $t('Inactive') }}</span>
          </span>
          <span>
            <a-switch v-model:checked="form.is_default" :disabled="!form.active" />
            <span class="switch-label">{{ $t('Cr_Default_Account') }}</span>
          </span>
        </a-space>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * Courier accounts — GET couriers/accounts → {accounts, warehouses, catalog};
 * POST/PUT couriers/accounts[/{id}], DELETE couriers/accounts/{id},
 * POST couriers/accounts/{id}/test | /default. The form is built from the
 * driver's meta (credential + option fields, capabilities). Credentials are
 * write-only: blank fields on edit keep the stored value.
 */
import { ref, computed, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { message, Modal } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { PlusOutlined, EditOutlined, DeleteOutlined, ApiOutlined, StarOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';
import { errorText } from './courierShared';

const { t } = useI18n();
const route = useRoute();
const router = useRouter();

const isLoading = ref(true);
const saving = ref(false);
const testingId = ref(null);
const accounts = ref([]);
const warehouses = ref([]);
const catalog = ref({ available: [], planned: [] });
const plannedSearch = ref('');
const modalOpen = ref(false);
// Credential state of the account being edited: {key: {set, hint}}.
const storedCredentials = ref({});
const webhookUrl = ref(null);

const emptyForm = () => ({
  id: null, courier: null, name: '', mode: 'live',
  credentials: {}, settings: {}, tracking_url: '',
  pickup_contact: '', pickup_phone: '', pickup_address: '', pickup_city: '',
  warehouse_id: undefined, default_weight: 0.5, default_service: '',
  allow_open_parcel: false, active: true, is_default: false,
});
const form = ref(emptyForm());

const selectedMeta = computed(() => catalog.value.available.find(c => c.code === form.value.courier) || null);
const warehouseOptions = computed(() => warehouses.value.map(w => ({ label: w.name, value: w.id })));
const plannedFiltered = computed(() => {
  const q = plannedSearch.value.trim().toLowerCase();
  return q
    ? catalog.value.planned.filter(p => (p.name + ' ' + p.region).toLowerCase().includes(q))
    : catalog.value.planned;
});

const columns = computed(() => [
  { title: t('Name'), key: 'name' },
  { title: t('Cr_Courier'), key: 'courier' },
  { title: t('warehouse'), key: 'warehouse' },
  { title: t('Cr_Connection'), key: 'connection', width: 130 },
  { title: t('Cr_Shipments'), dataIndex: 'shipments_count', key: 'shipments', align: 'right', width: 110 },
  { title: t('Status'), key: 'status', width: 100 },
  { title: t('Actions'), key: 'actions', width: 170 },
]);

function credentialSet(key) {
  return !!(storedCredentials.value[key] && storedCredentials.value[key].set);
}
function credentialHint(key) {
  return (storedCredentials.value[key] && storedCredentials.value[key].hint) || '';
}

async function fetch() {
  try {
    const resp = await http.get('couriers/accounts');
    accounts.value = resp.accounts || [];
    warehouses.value = resp.warehouses || [];
    catalog.value = resp.catalog || { available: [], planned: [] };
  } catch (e) {
    message.error(errorText(e, t('Failed')));
  } finally {
    isLoading.value = false;
  }
}

function openCreate() {
  form.value = emptyForm();
  storedCredentials.value = {};
  webhookUrl.value = null;
  plannedSearch.value = '';
  // With a single available courier there is nothing to choose.
  if (catalog.value.available.length === 1) pickCourier(catalog.value.available[0]);
  modalOpen.value = true;
}

function pickCourier(meta) {
  // Switching courier ("Change") must not carry the previous courier's defaults along.
  const previous = form.value.courier ? catalog.value.available.find(c => c.code === form.value.courier) : null;
  if (previous && previous.code !== meta.code) {
    if (form.value.name === previous.name) form.value.name = '';
    if (form.value.tracking_url === (previous.tracking_url || '')) form.value.tracking_url = '';
    form.value.credentials = {};
    form.value.settings = {};
  }
  form.value.courier = meta.code;
  if (!form.value.name) form.value.name = meta.name;
  if (!form.value.tracking_url && meta.tracking_url) form.value.tracking_url = meta.tracking_url;
  form.value.mode = meta.capabilities.test_mode ? 'test' : 'live';
}

function openEdit(a) {
  storedCredentials.value = a.credentials || {};
  webhookUrl.value = a.webhook_url || null;
  form.value = {
    id: a.id, courier: a.courier, name: a.name, mode: a.mode,
    // Secrets never come back: start blank, blank = keep.
    credentials: {},
    settings: { ...(a.settings || {}) },
    tracking_url: a.tracking_url || '',
    pickup_contact: a.pickup_contact || '', pickup_phone: a.pickup_phone || '',
    pickup_address: a.pickup_address || '', pickup_city: a.pickup_city || '',
    warehouse_id: a.warehouse_id || undefined,
    default_weight: a.default_weight, default_service: a.default_service || '',
    allow_open_parcel: !!a.allow_open_parcel, active: !!a.active, is_default: !!a.is_default,
  };
  modalOpen.value = true;
}

async function submit() {
  if (!selectedMeta.value) return;
  saving.value = true;
  const f = form.value;
  const credentials = {};
  Object.entries(f.credentials).forEach(([k, v]) => {
    if (v !== undefined && v !== null && String(v).trim() !== '') credentials[k] = String(v).trim();
  });
  const payload = {
    courier: f.courier,
    name: f.name,
    mode: f.mode,
    credentials,
    settings: f.settings,
    tracking_url: f.tracking_url || null,
    pickup_contact: f.pickup_contact || null,
    pickup_phone: f.pickup_phone || null,
    pickup_address: f.pickup_address || null,
    pickup_city: f.pickup_city || null,
    warehouse_id: f.warehouse_id || null,
    default_weight: f.default_weight || 0.5,
    default_service: f.default_service || null,
    allow_open_parcel: f.allow_open_parcel ? 1 : 0,
    active: f.active ? 1 : 0,
    is_default: f.active && f.is_default ? 1 : 0,
  };
  try {
    if (f.id) await http.put(`couriers/accounts/${f.id}`, payload);
    else await http.post('couriers/accounts', payload);
    message.success(t('Successfully_Updated'));
    modalOpen.value = false;
    fetch();
  } catch (e) {
    // Plan limit: http.js already opened the upgrade modal.
    if (!e.limitReached) message.error(errorText(e, t('InvalidData')));
  } finally {
    saving.value = false;
  }
}

async function testAccount(a) {
  testingId.value = a.id;
  try {
    const resp = await http.post(`couriers/accounts/${a.id}/test`);
    message.success(resp.message || t('Cr_Connected'));
  } catch (e) {
    message.error(errorText(e, t('Cr_Failed')));
  } finally {
    testingId.value = null;
    fetch();
  }
}

async function makeDefault(a) {
  try {
    await http.post(`couriers/accounts/${a.id}/default`);
    fetch();
  } catch (e) {
    message.error(errorText(e, t('Failed')));
  }
}

function confirmDelete(a) {
  Modal.confirm({
    title: t('AreYouSure'),
    content: a.name,
    okText: t('Yes'),
    okType: 'danger',
    cancelText: t('No'),
    async onOk() {
      try {
        await http.delete(`couriers/accounts/${a.id}`);
        message.success(t('Deleted_in_successfully'));
        fetch();
      } catch (e) {
        message.error(errorText(e, t('Failed')));
      }
    },
  });
}

// Couriers → Guide links here with ?courier=<code> ("Add this courier") or ?new=1.
onMounted(async () => {
  await fetch();
  const code = route.query.courier;
  if (code || route.query.new) {
    openCreate();
    const meta = code && catalog.value.available.find(c => c.code === code);
    if (meta) pickCourier(meta);
    router.replace({ query: {} });
  }
});
</script>

<style scoped>
.muted {
  color: rgba(var(--ink-rgb), 0.45);
}
.courier-card {
  border: 1px solid rgba(5, 5, 5, 0.1);
  border-radius: 8px;
  padding: 14px 16px;
  cursor: pointer;
  height: 100%;
  transition: border-color 0.15s, box-shadow 0.15s;
}
.courier-card:hover {
  border-color: var(--ant-color-primary, #1677ff);
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
}
.courier-card__name {
  font-weight: 600;
}
.courier-card__region {
  font-size: 12px;
  color: rgba(var(--ink-rgb), 0.45);
}
.courier-card__desc {
  font-size: 12px;
  margin-top: 6px;
  color: rgba(var(--ink-rgb), 0.65);
}
.planned {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}
.planned__tag {
  margin: 0;
  opacity: 0.75;
}
.section {
  font-size: 13px;
  margin: 8px 0 12px;
}
.switch-label {
  margin-left: 8px;
}
</style>
