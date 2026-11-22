<template>
  <div class="page">
    <PageHeader :title="tf('Dgi_Page_Title', 'DGI Facture Normalisée (DR Congo)')" :breadcrumb="[$t('Settings'), tf('Dgi_Settings', 'DGI Facture Normalisée')]" />

    <a-skeleton v-if="loading" active :paragraph="{ rows: 8 }" />
    <a-card v-else size="small">
      <a-alert
        v-if="!platformEnabled"
        type="error" show-icon style="margin-bottom: 12px"
        :message="tf('Einvoice_Platform_Off', 'This integration is temporarily disabled by the platform administrator. Invoices are not transmitted until it is switched back on.')"
      />
      <a-alert
        v-if="!moduleEnabled"
        type="warning" show-icon style="margin-bottom: 12px"
        :message="tf('Dgi_Module_Off', 'The module is switched off. Turn it on in System Settings > Features to print the DGI mentions on invoices.')"
      />
      <a-alert
        v-if="!apiAvailable"
        type="info" show-icon style="margin-bottom: 12px"
        :message="tf('Dgi_Api_Pending_Title', 'Certification through the DGI e-MCF is not connected yet')"
        :description="tf('Dgi_Api_Pending_Body', 'The DGI publishes the e-MCF developer API only to registered developers (developper.dgirdc.cd). Until it is connected, invoices carry your NIF / RCCM / Id. Nat. mentions but no DEF number, authentication code or QR.')"
      />

      <a-tabs v-model:activeKey="tab" @change="onTab">
        <a-tab-pane key="config" :tab="tf('Configuration', 'Configuration')">
          <a-form layout="vertical">
            <a-divider orientation="left">{{ tf('Dgi_Seller_Identity', 'Seller identity (mentions obligatoires)') }}</a-divider>
            <a-row :gutter="12">
              <a-col :xs="24" :md="8"><a-form-item label="NIF"><a-input v-model:value="form.nif" :maxlength="30" /></a-form-item></a-col>
              <a-col :xs="24" :md="8"><a-form-item label="RCCM"><a-input v-model:value="form.rccm" :maxlength="60" /></a-form-item></a-col>
              <a-col :xs="24" :md="8"><a-form-item :label="tf('Dgi_Id_Nat', 'Id. Nat.')"><a-input v-model:value="form.id_nat" :maxlength="60" /></a-form-item></a-col>
              <a-col :xs="24" :md="12"><a-form-item :label="tf('Business_Name', 'Business Name')"><a-input v-model:value="form.company_name" :placeholder="company.name || ''" /></a-form-item></a-col>
              <a-col :xs="24" :md="12"><a-form-item :label="$t('Address')"><a-input v-model:value="form.address" :placeholder="company.address || ''" /></a-form-item></a-col>
            </a-row>

            <a-divider orientation="left">{{ tf('Dgi_Emcf_Connection', 'e-MCF connection') }}</a-divider>
            <a-row :gutter="12">
              <a-col :xs="24" :md="6">
                <a-form-item :label="tf('Environment', 'Environment')">
                  <a-select v-model:value="form.environment" :options="[{ value: 'test', label: tf('Dgi_Env_Test', 'Test') }, { value: 'production', label: tf('Dgi_Env_Production', 'Production') }]" />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="6"><a-form-item :label="tf('Dgi_Def_Number', 'DEF / e-MCF number')"><a-input v-model:value="form.def_number" /></a-form-item></a-col>
              <a-col :xs="24" :md="12"><a-form-item :label="tf('Dgi_Api_Url', 'API URL')"><a-input v-model:value="form.api_url" placeholder="https://" /></a-form-item></a-col>
              <a-col :xs="24" :md="12">
                <a-form-item :label="tf('Dgi_Api_Token', 'API token / key')" :extra="form.has_token ? $t('Dgi_Token_Saved', { last4: form.token_last4 || '' }) : ''">
                  <a-input-password v-model:value="apiToken" autocomplete="new-password" />
                </a-form-item>
              </a-col>
            </a-row>
            <a-form-item :label="tf('Dgi_Tax_Groups', 'Tax rate → DGI tax group')" :extra="tf('Dgi_Tax_Groups_Hint', 'Taxation groups as defined by the DGI (updated February 2026). Used once the e-MCF connection is live.')">
              <div v-for="(row, i) in groupRows" :key="i" style="display: flex; gap: 8px; margin-bottom: 6px">
                <a-input-number v-model:value="row.rate" :min="0" :max="100" :step="0.5" addon-after="%" style="width: 140px" />
                <a-input v-model:value="row.group" :maxlength="2" style="width: 90px" :placeholder="tf('Dgi_Group', 'Group')" />
                <a-button danger @click="groupRows.splice(i, 1)"><template #icon><DeleteOutlined /></template></a-button>
              </div>
              <a-button size="small" @click="groupRows.push({ rate: null, group: '' })"><template #icon><PlusOutlined /></template>{{ tf('Add', 'Add') }}</a-button>
            </a-form-item>

            <a-button type="primary" :loading="busy" @click="save">{{ $t('Save') }}</a-button>
          </a-form>
        </a-tab-pane>

        <a-tab-pane key="documents" :tab="tf('Documents', 'Documents')">
          <a-table
            size="small" :columns="docColumns" :data-source="documents.data" row-key="id" :loading="docsLoading"
            :pagination="{ current: documents.current_page || 1, pageSize: 20, total: documents.total || 0, showSizeChanger: false }"
            @change="p => loadDocuments(p.current)"
          >
            <template #emptyText>{{ tf('Dgi_No_Documents', 'No certified invoices yet — certification starts once the e-MCF connection is live.') }}</template>
          </a-table>
        </a-tab-pane>

        <a-tab-pane key="guide" :tab="tf('Guide', 'Guide')">
          <a-typography>
            <h3>{{ tf('Dgi_Guide_What_Title', 'What this does today') }}</h3>
            <p>{{ tf('Dgi_Guide_What_Body', 'With the module on, receipts and PDF invoices print the seller NIF, RCCM and Id. Nat. required on the facture normalisée. Full certification (DEF number, counters, authentication code and QR returned by the DGI) is added once the e-MCF developer API is connected.') }}</p>
            <h3>{{ tf('Dgi_Guide_Steps_Title', 'Getting access to the e-MCF') }}</h3>
            <ol>
              <li>{{ tf('Dgi_Guide_Step_1', 'Create an account with your NIF on developper.dgirdc.cd and request access to the e-MCF test environment.') }}</li>
              <li>{{ tf('Dgi_Guide_Step_2', 'Download the API documentation (routes, DTOs, taxation groups) and the test credentials.') }}</li>
              <li>{{ tf('Dgi_Guide_Step_3', 'Send them to your platform administrator so the connection can be completed and tested.') }}</li>
              <li>{{ tf('Dgi_Guide_Step_4', 'After testing, submit the homologation file to the DGI.') }}</li>
            </ol>
          </a-typography>
        </a-tab-pane>
      </a-tabs>
    </a-card>
  </div>
</template>

<script setup>
/**
 * DR Congo DGI facture normalisée settings (module switch: System Settings > Features).
 *   GET/POST dgi/settings   GET dgi/documents
 */
import { ref, computed, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { PlusOutlined, DeleteOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';
import { t as tf } from '../../i18n';

const { t } = useI18n();
const loading = ref(true);
const busy = ref(false);
const tab = ref('config');
const apiToken = ref('');
const moduleEnabled = ref(false);
const platformEnabled = ref(true);
const apiAvailable = ref(false);
const company = ref({ name: '', address: '' });
const groupRows = ref([]);
const form = ref({
  environment: 'test', nif: '', rccm: '', id_nat: '', company_name: '', address: '',
  def_number: '', api_url: '', has_token: false, token_last4: null, tax_group_map: {},
});

const documents = ref({ data: [], total: 0, current_page: 1 });
const docsLoading = ref(false);
const docColumns = computed(() => [
  { title: t('Reference'), dataIndex: 'invoice_ref', key: 'invoice_ref' },
  { title: tf('Dgi_Def_Number', 'DEF'), dataIndex: 'def_number', key: 'def_number' },
  { title: tf('Dgi_Counters', 'Counters'), dataIndex: 'counters', key: 'counters' },
  { title: t('Status'), dataIndex: 'status', key: 'status', width: 110 },
  { title: tf('Dgi_Certified_At', 'Certified'), dataIndex: 'certified_at', key: 'certified_at', width: 170 },
]);

function errMsg(e, fallback) { return e?.data?.message || (e?.data?.errors && Object.values(e.data.errors)[0]?.[0]) || fallback; }

function applySettings(s) {
  Object.assign(form.value, s || {});
  groupRows.value = Object.entries(form.value.tax_group_map || {}).map(([rate, group]) => ({ rate: Number(rate), group }));
}

async function load() {
  try {
    const data = await http.get('dgi/settings');
    applySettings(data.settings);
    moduleEnabled.value = !!data.module_enabled;
    platformEnabled.value = data.platform_enabled !== false;
    apiAvailable.value = !!data.api_available;
    company.value = data.company || company.value;
  } catch (e) {
    message.error(errMsg(e, tf('Dgi_Load_Failed', 'Could not load the DGI settings.')));
  } finally {
    loading.value = false;
  }
}

async function save() {
  busy.value = true;
  try {
    const map = {};
    groupRows.value.forEach(r => { if (r.rate !== null && r.rate !== '' && r.group) map[String(r.rate)] = r.group; });
    const payload = { ...form.value, tax_group_map: map };
    delete payload.has_token;
    delete payload.token_last4;
    if (!payload.api_url) payload.api_url = null;
    if (apiToken.value) payload.api_token = apiToken.value;
    const data = await http.post('dgi/settings', payload);
    applySettings(data.settings);
    apiToken.value = '';
    message.success(t('Successfully_Updated'));
  } catch (e) {
    message.error(errMsg(e, tf('Dgi_Save_Failed', 'Could not save the DGI settings.')));
  } finally {
    busy.value = false;
  }
}

async function loadDocuments(page) {
  docsLoading.value = true;
  try {
    documents.value = await http.get('dgi/documents', { limit: 20, page: Math.max(1, page || 1) });
  } catch (e) {
    message.error(errMsg(e, tf('Dgi_Docs_Load_Failed', 'Could not load the documents.')));
  } finally {
    docsLoading.value = false;
  }
}

function onTab(key) {
  if (key === 'documents' && !documents.value.data.length) loadDocuments(1);
}

onMounted(load);
</script>
