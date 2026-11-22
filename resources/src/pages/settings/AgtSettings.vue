<template>
  <div class="page">
    <PageHeader :title="tf('Agt_Page_Title', 'AGT E-Invoicing (Angola)')" :breadcrumb="[$t('Settings'), tf('Agt_Settings', 'AGT E-Invoicing')]" />

    <a-skeleton v-if="loading" active :paragraph="{ rows: 10 }" />
    <a-card v-else size="small">
      <a-alert
        v-if="!platformEnabled"
        type="error" show-icon style="margin-bottom: 12px"
        :message="tf('Einvoice_Platform_Off', 'This integration is temporarily disabled by the platform administrator. Invoices are not transmitted until it is switched back on.')"
      />
      <a-alert
        v-if="!moduleEnabled"
        type="warning" show-icon style="margin-bottom: 12px"
        :message="tf('Agt_Module_Off', 'The module is switched off. Turn it on in System Settings > Features to communicate invoices to AGT.')"
      />
      <a-alert
        v-if="!producer.configured"
        type="error" show-icon style="margin-bottom: 12px"
        :message="tf('Agt_Producer_Missing', 'The platform administrator has not finished the AGT software-producer setup yet.')"
        :description="(producer.missing || []).join(', ')"
      />

      <a-tabs v-model:activeKey="tab" @change="onTab">
        <!-- ======================= Configuration ======================= -->
        <a-tab-pane key="config" :tab="tf('Configuration', 'Configuration')">
          <a-form layout="vertical">
            <a-descriptions size="small" bordered :column="{ xs: 1, md: 3 }" style="margin-bottom: 16px">
              <a-descriptions-item :label="tf('Agt_Software', 'Software')">{{ producer.product_id || '—' }} {{ producer.product_version || '' }}</a-descriptions-item>
              <a-descriptions-item :label="tf('Agt_Validation_Number', 'AGT validation number')">{{ producer.software_validation_number || '—' }}</a-descriptions-item>
              <a-descriptions-item :label="tf('Environment', 'Environment')">
                <a-tag :color="form.environment === 'production' ? 'error' : 'blue'">{{ envLabel }}</a-tag>
              </a-descriptions-item>
            </a-descriptions>

            <a-row :gutter="12">
              <a-col :xs="24" :md="8">
                <a-form-item :label="tf('Environment', 'Environment')" :extra="tf('Agt_Environment_Hint', 'Homologação is the AGT test platform; Produção communicates real invoices.')">
                  <a-select v-model:value="form.environment" :options="environments" />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="8">
                <a-form-item :label="tf('Agt_Auto_Submit', 'Communicate new invoices automatically')">
                  <a-switch v-model:checked="form.auto_submit" />
                  <span style="margin-left: 8px">{{ form.auto_submit ? tf('Automatic', 'Automatic') : tf('Manual', 'Manual') }}</span>
                </a-form-item>
              </a-col>
            </a-row>

            <a-divider orientation="left">{{ tf('Agt_Taxpayer', 'Taxpayer') }}</a-divider>
            <a-row :gutter="12">
              <a-col :xs="24" :md="6"><a-form-item label="NIF"><a-input v-model:value="form.tax_registration_number" :maxlength="15" /></a-form-item></a-col>
              <a-col :xs="24" :md="10"><a-form-item :label="tf('Business_Name', 'Business Name')"><a-input v-model:value="form.company_name" :placeholder="company.name || ''" /></a-form-item></a-col>
              <a-col :xs="24" :md="4">
                <a-form-item :label="tf('Agt_Establishment', 'Establishment')" :extra="tf('Agt_Establishment_Hint', 'As registered on the Portal do Contribuinte (SEDE when single location).')">
                  <a-input v-model:value="form.establishment_number" />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="4"><a-form-item :label="tf('Agt_Eac_Code', 'CAE code')"><a-input v-model:value="form.eac_code" :maxlength="5" /></a-form-item></a-col>
            </a-row>

            <a-divider orientation="left">{{ tf('Agt_Signing_Key', 'AGT signing key') }}</a-divider>
            <a-form-item
              :label="tf('Agt_Private_Key', 'Private key (PEM) issued by AGT')"
              :extra="form.has_private_key
                ? tf('Agt_Key_Saved', 'A key is saved. Paste a new one only to replace it.')
                : tf('Agt_Key_Obtain', 'Download it from your account on the Portal do Contribuinte. It signs every document and request.')"
            >
              <a-textarea v-model:value="privateKey" :rows="4" class="mono" placeholder="-----BEGIN PRIVATE KEY-----" autocomplete="off" />
            </a-form-item>
            <a-collapse v-if="form.public_key" ghost style="margin-bottom: 12px">
              <a-collapse-panel key="pub" :header="tf('Agt_Public_Key', 'Matching public key')">
                <pre class="mono" style="white-space: pre-wrap; margin: 0">{{ form.public_key }}</pre>
              </a-collapse-panel>
            </a-collapse>

            <a-divider orientation="left">{{ tf('Agt_Document_Rules', 'Document rules') }}</a-divider>
            <a-row :gutter="12">
              <a-col :xs="24" :md="8">
                <a-form-item :label="tf('Agt_Paid_Sale_Type', 'Sales paid at creation')" :extra="tf('Agt_Paid_Sale_Type_Hint', 'Unpaid sales are always FT (Factura); returns are NC (Nota de Crédito).')">
                  <a-select v-model:value="form.paid_sale_type" :options="[{ value: 'FR', label: 'FR — Factura/Recibo' }, { value: 'FT', label: 'FT — Factura' }]" />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="8">
                <a-form-item :label="tf('Agt_Exemption_Code', 'Exemption code for 0% lines')" :extra="tf('Agt_Exemption_Code_Hint', '3-character taxExemptionCode from the AGT annex (e.g. M02). Also used for shipping.')">
                  <a-input v-model:value="form.default_exemption_code" :maxlength="3" />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="8">
                <a-form-item :label="tf('Agt_Default_Unit', 'Default unit of measure')"><a-input v-model:value="form.default_unit" :maxlength="20" /></a-form-item>
              </a-col>
            </a-row>
            <a-form-item :label="tf('Agt_Tax_Map', 'IVA rate → AGT tax code')" :extra="tf('Agt_Tax_Map_Hint', 'Rates not listed are sent as OUT (Outra); 0% is always ISE (Isento).')">
              <div v-for="(row, i) in taxRows" :key="i" style="display: flex; gap: 8px; margin-bottom: 6px">
                <a-input-number v-model:value="row.rate" :min="0.01" :max="100" :step="0.5" addon-after="%" style="width: 140px" />
                <a-select v-model:value="row.code" :options="taxCodeOptions" style="width: 220px" />
                <a-button danger @click="taxRows.splice(i, 1)"><template #icon><DeleteOutlined /></template></a-button>
              </div>
              <a-button size="small" @click="taxRows.push({ rate: null, code: 'NOR' })"><template #icon><PlusOutlined /></template>{{ tf('Add', 'Add') }}</a-button>
            </a-form-item>

            <a-space>
              <a-button type="primary" :loading="busy" @click="save">{{ $t('Save') }}</a-button>
              <a-button :loading="busy" @click="testConnection">{{ tf('Test_Connection', 'Test Connection') }}</a-button>
            </a-space>
          </a-form>
        </a-tab-pane>

        <!-- ======================= Series ======================= -->
        <a-tab-pane key="series" :tab="tf('Agt_Series', 'Series')">
          <a-alert type="info" show-icon style="margin-bottom: 12px" :message="tf('Agt_Series_Hint', 'AGT authorises one numbering series per document type, year and establishment. Invoices cannot be issued without an active series for their type and year.')" />
          <a-space style="margin-bottom: 12px" wrap>
            <a-button type="primary" @click="openRequest"><template #icon><CloudDownloadOutlined /></template>{{ tf('Agt_Request_Series', 'Request series from AGT') }}</a-button>
            <a-button @click="openManual"><template #icon><PlusOutlined /></template>{{ tf('Agt_Add_Series', 'Add existing series') }}</a-button>
            <a-button @click="loadSeries"><template #icon><ReloadOutlined /></template>{{ tf('Refresh', 'Refresh') }}</a-button>
          </a-space>
          <a-table size="small" :columns="seriesColumns" :data-source="series" row-key="id" :loading="seriesLoading" :pagination="false" :scroll="{ x: 900 }">
            <template #bodyCell="{ column, record }">
              <template v-if="column.key === 'contingency'">
                <a-tag :color="record.contingency === 'C' ? 'orange' : 'default'">{{ record.contingency === 'C' ? tf('Agt_Contingency', 'Contingency') : tf('Normal', 'Normal') }}</a-tag>
              </template>
              <template v-else-if="column.key === 'next_number'">
                <a-space>
                  <span>{{ record.next_number }}</span>
                  <a-button size="small" type="link" @click="editNext(record)"><template #icon><EditOutlined /></template></a-button>
                </a-space>
              </template>
              <template v-else-if="column.key === 'active'">
                <a-switch :checked="!!record.active" size="small" @change="v => updateSeries(record, { active: v })" />
              </template>
            </template>
          </a-table>
        </a-tab-pane>

        <!-- ======================= Documents ======================= -->
        <a-tab-pane key="documents" :tab="tf('Documents', 'Documents')">
          <a-space style="margin-bottom: 12px" wrap>
            <a-select v-model:value="docStatus" style="width: 180px" :options="docStatusOptions" @change="loadDocuments(1)" />
            <a-input-search v-model:value="docSearch" :placeholder="$t('Search_this_table')" style="width: 220px" @search="loadDocuments(1)" />
            <a-button @click="loadDocuments(docPage)"><template #icon><ReloadOutlined /></template>{{ tf('Refresh', 'Refresh') }}</a-button>
          </a-space>
          <a-table
            size="small" :columns="docColumns" :data-source="documents.data" row-key="id" :loading="docsLoading"
            :pagination="{ current: documents.current_page || 1, pageSize: 20, total: documents.total || 0, showSizeChanger: false }"
            :scroll="{ x: 1100 }"
            @change="p => loadDocuments(p.current)"
          >
            <template #bodyCell="{ column, record }">
              <template v-if="column.key === 'document_no'">
                <div>{{ record.document_no }}</div>
                <div v-if="record.rejected_document_no" style="font-size: 11px; color: #8c8c8c">{{ tf('Agt_Corrects', 'Corrects') }} {{ record.rejected_document_no }}</div>
              </template>
              <template v-else-if="column.key === 'gross_total'">{{ fmtNum(record.gross_total || 0) }}</template>
              <template v-else-if="column.key === 'status'">
                <a-tag :color="docColor(record.status)">{{ statusLabel(record.status) }}</a-tag>
                <a-tag v-if="record.late" color="orange">{{ tf('Agt_Late', '> 24h') }}</a-tag>
              </template>
              <template v-else-if="column.key === 'errors'">
                <a-tooltip v-if="record.errors" :title="record.errors"><span style="color: #ff4d4f">{{ truncate(record.errors, 60) }}</span></a-tooltip>
                <span v-else>—</span>
              </template>
              <template v-else-if="column.key === 'actions'">
                <a-space>
                  <a-button v-if="record.status === 'submitted'" size="small" :loading="busy" @click="checkDoc(record)">{{ tf('Agt_Check', 'Check') }}</a-button>
                  <a-button v-if="['pending', 'error'].includes(record.status)" size="small" :loading="busy" @click="retry(record)">{{ tf('Retry', 'Retry') }}</a-button>
                  <a-popconfirm v-if="record.status === 'invalid'" :title="tf('Agt_Correct_Confirm', 'Issue a correction with a new number for this rejected document?')" @confirm="retry(record)">
                    <a-button size="small" danger :loading="busy">{{ tf('Agt_Correct', 'Correct') }}</a-button>
                  </a-popconfirm>
                  <a-button size="small" type="link" @click="viewDoc(record)">JSON</a-button>
                </a-space>
              </template>
            </template>
          </a-table>
        </a-tab-pane>

        <!-- ======================= Logs ======================= -->
        <a-tab-pane key="logs" :tab="tf('Logs', 'Logs')">
          <a-button style="margin-bottom: 12px" @click="loadLogs"><template #icon><ReloadOutlined /></template>{{ tf('Refresh', 'Refresh') }}</a-button>
          <a-table size="small" :columns="logColumns" :data-source="logs" row-key="id" :loading="logsLoading" :pagination="false" :scroll="{ x: 900 }">
            <template #bodyCell="{ column, record }">
              <template v-if="column.key === 'http_status'">
                <a-tag :color="record.http_status >= 200 && record.http_status < 300 ? 'success' : 'error'">{{ record.http_status || '—' }}</a-tag>
              </template>
              <template v-else-if="column.key === 'response_body'">
                <span class="mono" style="cursor: pointer; word-break: break-all" @click="toggle('log-' + record.id)">
                  {{ expanded['log-' + record.id] ? record.response_body : truncate(record.response_body, 120) }}
                </span>
              </template>
            </template>
          </a-table>
        </a-tab-pane>

        <!-- ======================= Guide ======================= -->
        <a-tab-pane key="guide" :tab="tf('Guide', 'Guide')">
          <a-typography>
            <h3>{{ tf('Agt_Guide_What_Title', 'What this does') }}</h3>
            <p>{{ tf('Agt_Guide_What_Body', 'Every completed sale is issued as an AGT electronic invoice (FR when paid at creation, otherwise FT) and every received return as a credit note (NC). Each document gets the next number of its AGT series, is signed with your AGT key and sent to AGT, which validates it a few minutes later. The receipt and the PDF invoice print the AGT number, the security extract and a QR code.') }}</p>
            <h3>{{ tf('Agt_Guide_Steps_Title', 'Setup') }}</h3>
            <ol>
              <li>{{ tf('Agt_Guide_Step_1', 'Join e-invoicing (adesão) on the Portal do Contribuinte and download your AGT private key.') }}</li>
              <li>{{ tf('Agt_Guide_Step_2', 'Enter your NIF, establishment and the key on the Configuration tab, choose Homologação, save and use Test Connection.') }}</li>
              <li>{{ tf('Agt_Guide_Step_3', 'On the Series tab, request one series per document type you use (FR, FT, NC) for the current year.') }}</li>
              <li>{{ tf('Agt_Guide_Step_4', 'Map your IVA rates to AGT codes and set the exemption code for 0% lines.') }}</li>
              <li>{{ tf('Agt_Guide_Step_5', 'Turn the module on in System Settings > Features, make a test sale and check that it becomes valid on the Documents tab.') }}</li>
              <li>{{ tf('Agt_Guide_Step_6', 'When AGT approves you for production, switch to Produção and request production series.') }}</li>
            </ol>
            <h3>{{ $t('Cr_Guide_Good_To_Know') }}</h3>
            <ul>
              <li>{{ tf('Agt_Guide_Note_1', 'A communicated sale or return can no longer be edited or deleted. Correct it with a sale return (credit note).') }}</li>
              <li>{{ tf('Agt_Guide_Note_2', 'Documents rejected by AGT are corrected with a new number that references the rejected one; the old number is never reused.') }}</li>
              <li>{{ tf('Agt_Guide_Note_3', 'Offline POS sales are communicated when they sync. AGT penalises documents sent more than 24 hours late.') }}</li>
              <li>{{ tf('Agt_Guide_Note_4', 'A line cannot carry both a product tax and the order tax; AGT needs one IVA rate per line. Shipping is sent as an exempt line.') }}</li>
              <li>{{ tf('Agt_Guide_Note_5', 'AGT totals are computed per line and may differ from the system total by a few cents of rounding.') }}</li>
            </ul>
          </a-typography>
        </a-tab-pane>
      </a-tabs>
    </a-card>

    <!-- Request series -->
    <a-modal v-model:open="requestModal.open" :title="tf('Agt_Request_Series', 'Request series from AGT')" :confirm-loading="busy" @ok="requestSeries">
      <a-form layout="vertical">
        <a-form-item :label="tf('Agt_Document_Type', 'Document type')"><a-select v-model:value="requestModal.document_type" :options="documentTypes" /></a-form-item>
        <a-form-item :label="tf('Year', 'Year')"><a-input-number v-model:value="requestModal.series_year" :min="2025" :max="2100" style="width: 100%" /></a-form-item>
        <a-form-item :label="tf('Agt_Establishment', 'Establishment')"><a-input v-model:value="requestModal.establishment_number" :placeholder="form.establishment_number || 'SEDE'" /></a-form-item>
        <a-form-item :label="tf('Agt_Series_Kind', 'Kind')">
          <a-radio-group v-model:value="requestModal.contingency">
            <a-radio value="N">{{ tf('Normal', 'Normal') }}</a-radio>
            <a-radio value="C">{{ tf('Agt_Contingency', 'Contingency') }}</a-radio>
          </a-radio-group>
        </a-form-item>
      </a-form>
    </a-modal>

    <!-- Add an existing series -->
    <a-modal v-model:open="manualModal.open" :title="tf('Agt_Add_Series', 'Add existing series')" :confirm-loading="busy" @ok="storeSeries">
      <a-form layout="vertical">
        <a-row :gutter="12">
          <a-col :span="12"><a-form-item :label="tf('Agt_Document_Type', 'Document type')"><a-select v-model:value="manualModal.document_type" :options="documentTypes" /></a-form-item></a-col>
          <a-col :span="12"><a-form-item :label="tf('Year', 'Year')"><a-input-number v-model:value="manualModal.series_year" :min="2025" :max="2100" style="width: 100%" /></a-form-item></a-col>
          <a-col :span="24"><a-form-item :label="tf('Agt_Series_Code', 'Series code (as issued by AGT)')"><a-input v-model:value="manualModal.series_code" /></a-form-item></a-col>
          <a-col :span="8"><a-form-item :label="tf('Agt_First_Number', 'First')"><a-input-number v-model:value="manualModal.first_number" :min="1" style="width: 100%" /></a-form-item></a-col>
          <a-col :span="8"><a-form-item :label="tf('Agt_Next_Number', 'Next')"><a-input-number v-model:value="manualModal.next_number" :min="1" style="width: 100%" /></a-form-item></a-col>
          <a-col :span="8"><a-form-item :label="tf('Agt_Last_Number', 'Last')"><a-input-number v-model:value="manualModal.last_number" :min="1" style="width: 100%" /></a-form-item></a-col>
        </a-row>
      </a-form>
    </a-modal>

    <!-- Document JSON -->
    <a-modal v-model:open="jsonModal.open" :title="jsonModal.title" width="820px" :footer="null">
      <a-tabs>
        <a-tab-pane key="payload" :tab="tf('Agt_Payload', 'Sent to AGT')"><pre class="mono json">{{ pretty(jsonModal.payload) }}</pre></a-tab-pane>
        <a-tab-pane key="response" :tab="tf('Response', 'Response')"><pre class="mono json">{{ pretty(jsonModal.response) }}</pre></a-tab-pane>
      </a-tabs>
    </a-modal>
  </div>
</template>

<script setup>
import { useFormat } from '../../composables/useFormat';
// Shared money formatter (Price Format separators, precision, Hide Zero Decimals).
const { number: fmtNum } = useFormat();
/**
 * Angola AGT e-invoicing settings (module switch: System Settings > Features).
 *   GET/POST agt/settings   POST agt/test-connection
 *   GET agt/series   POST agt/series   POST agt/series/request   PUT agt/series/{id}
 *   GET agt/documents   GET agt/documents/{id}   POST agt/documents/{id}/retry|check
 *   GET agt/logs
 */
import { ref, reactive, computed, onMounted, h } from 'vue';
import { message, Modal, InputNumber } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { ReloadOutlined, PlusOutlined, DeleteOutlined, EditOutlined, CloudDownloadOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';
import { t as tf } from '../../i18n';

const { t } = useI18n();
const loading = ref(true);
const busy = ref(false);
const tab = ref('config');
const privateKey = ref('');
const moduleEnabled = ref(false);
const platformEnabled = ref(true);
const company = ref({ name: '' });
const producer = ref({ configured: false, missing: [] });
const environments = ref([]);
const documentTypes = ref([]);
const taxCodeOptions = ref([]);
const taxRows = ref([]);
const form = ref({
  auto_submit: true, environment: 'test', tax_registration_number: '', company_name: '',
  establishment_number: 'SEDE', eac_code: '', has_private_key: false, public_key: null,
  paid_sale_type: 'FR', default_exemption_code: '', default_unit: 'UN', tax_code_map: {},
});

const envLabel = computed(() => (environments.value.find(e => e.value === form.value.environment) || {}).label || form.value.environment);

// series
const series = ref([]);
const seriesLoading = ref(false);
const requestModal = reactive({ open: false, document_type: 'FR', series_year: new Date().getFullYear(), establishment_number: '', contingency: 'N' });
const manualModal = reactive({ open: false, document_type: 'FR', series_year: new Date().getFullYear(), series_code: '', first_number: 1, next_number: 1, last_number: 999999999999 });
const seriesColumns = computed(() => [
  { title: tf('Agt_Document_Type', 'Document type'), dataIndex: 'document_type', key: 'document_type', width: 90 },
  { title: tf('Agt_Series_Code', 'Series code'), dataIndex: 'series_code', key: 'series_code' },
  { title: tf('Year', 'Year'), dataIndex: 'series_year', key: 'series_year', width: 80 },
  { title: tf('Agt_Establishment', 'Establishment'), dataIndex: 'establishment_number', key: 'establishment_number' },
  { title: tf('Agt_Series_Kind', 'Kind'), key: 'contingency', width: 110 },
  { title: tf('Agt_Next_Number', 'Next'), key: 'next_number', width: 140 },
  { title: tf('Agt_Remaining', 'Remaining'), dataIndex: 'remaining', key: 'remaining', width: 130 },
  { title: tf('Active', 'Active'), key: 'active', width: 80 },
]);

// documents
const documents = ref({ data: [], total: 0, current_page: 1 });
const docPage = ref(1);
const docStatus = ref('');
const docSearch = ref('');
const docsLoading = ref(false);
const STATUS = {
  pending: ['default', 'Agt_Status_Pending', 'Pending'],
  submitted: ['processing', 'Agt_Status_Submitted', 'Submitted'],
  valid: ['success', 'Agt_Status_Valid', 'Valid'],
  invalid: ['error', 'Agt_Status_Invalid', 'Rejected'],
  error: ['warning', 'Agt_Status_Error', 'Not sent'],
  superseded: ['default', 'Agt_Status_Superseded', 'Corrected'],
};
const statusLabel = s => (STATUS[s] ? tf(STATUS[s][1], STATUS[s][2]) : s);
const docColor = s => (STATUS[s] ? STATUS[s][0] : 'default');
const docStatusOptions = computed(() => [
  { value: '', label: tf('All_Statuses', 'All statuses') },
  ...Object.keys(STATUS).map(k => ({ value: k, label: statusLabel(k) })),
]);
const docColumns = computed(() => [
  { title: tf('Agt_Document_Type', 'Type'), dataIndex: 'document_type', key: 'document_type', width: 70 },
  { title: tf('Agt_Document_No', 'AGT number'), key: 'document_no' },
  { title: t('Reference'), dataIndex: 'invoice_ref', key: 'invoice_ref' },
  { title: t('date'), dataIndex: 'document_date', key: 'document_date', width: 110 },
  { title: t('Total'), key: 'gross_total', width: 110 },
  { title: t('Status'), key: 'status', width: 150 },
  { title: tf('Error', 'Error'), key: 'errors' },
  { title: '', key: 'actions', width: 220 },
]);
const jsonModal = reactive({ open: false, title: '', payload: null, response: null });

// logs
const logs = ref([]);
const logsLoading = ref(false);
const expanded = reactive({});
const logColumns = computed(() => [
  { title: t('date'), dataIndex: 'created_at', key: 'created_at', width: 170 },
  { title: t('Action'), dataIndex: 'action', key: 'action', width: 140 },
  { title: 'HTTP', key: 'http_status', width: 80 },
  { title: t('Endpoint'), dataIndex: 'endpoint', key: 'endpoint' },
  { title: tf('Response', 'Response'), key: 'response_body' },
]);

function toggle(k) { expanded[k] = !expanded[k]; }
function truncate(text, len) { const s = String(text || ''); return s.length > len ? s.slice(0, len) + '…' : s; }
function pretty(v) { return v ? JSON.stringify(v, null, 2) : '—'; }
function errMsg(e, fallback) { return e?.data?.message || (e?.data?.errors && Object.values(e.data.errors)[0]?.[0]) || fallback; }

function applySettings(s) {
  Object.assign(form.value, s || {});
  taxRows.value = Object.entries(form.value.tax_code_map || {}).map(([rate, code]) => ({ rate: Number(rate), code }));
}

async function load() {
  try {
    const data = await http.get('agt/settings');
    applySettings(data.settings);
    moduleEnabled.value = !!data.module_enabled;
    platformEnabled.value = data.platform_enabled !== false;
    company.value = data.company || company.value;
    producer.value = data.producer || producer.value;
    environments.value = data.environments || [];
    documentTypes.value = data.document_types || [];
    taxCodeOptions.value = (data.tax_codes || []).filter(o => o.value !== 'ISE');
  } catch (e) {
    message.error(errMsg(e, tf('Agt_Load_Failed', 'Could not load the AGT settings.')));
  } finally {
    loading.value = false;
  }
}

async function save() {
  busy.value = true;
  try {
    const map = {};
    taxRows.value.forEach(r => { if (r.rate > 0 && r.code) map[String(r.rate)] = r.code; });
    const payload = { ...form.value, tax_code_map: map };
    delete payload.has_private_key;
    delete payload.public_key;
    if (privateKey.value.trim()) payload.private_key = privateKey.value.trim();
    const data = await http.post('agt/settings', payload);
    applySettings(data.settings);
    privateKey.value = '';
    message.success(t('Successfully_Updated'));
    if (tab.value === 'series') loadSeries();
  } catch (e) {
    message.error(errMsg(e, tf('Agt_Save_Failed', 'Could not save the AGT settings.')));
  } finally {
    busy.value = false;
  }
}

async function testConnection() {
  busy.value = true;
  try {
    const data = await http.post('agt/test-connection', {});
    (data.success ? message.success : message.warning)(data.message);
  } catch (e) {
    message.error(errMsg(e, tf('Connection_failed', 'Connection failed')));
  } finally {
    busy.value = false;
  }
}

async function loadSeries() {
  seriesLoading.value = true;
  try {
    series.value = (await http.get('agt/series')) || [];
  } catch (e) {
    message.error(errMsg(e, tf('Agt_Series_Load_Failed', 'Could not load the series.')));
  } finally {
    seriesLoading.value = false;
  }
}
function openRequest() {
  Object.assign(requestModal, { open: true, establishment_number: form.value.establishment_number || 'SEDE' });
}
function openManual() {
  Object.assign(manualModal, { open: true, series_code: '', first_number: 1, next_number: 1, last_number: 999999999999 });
}
async function requestSeries() {
  busy.value = true;
  try {
    const { open, ...payload } = requestModal;
    await http.post('agt/series/request', payload);
    requestModal.open = false;
    message.success(tf('Agt_Series_Created', 'Series created by AGT.'));
    loadSeries();
  } catch (e) {
    message.error(errMsg(e, tf('Agt_Series_Request_Failed', 'AGT did not create the series.')));
  } finally {
    busy.value = false;
  }
}
async function storeSeries() {
  busy.value = true;
  try {
    const { open, ...payload } = manualModal;
    await http.post('agt/series', payload);
    manualModal.open = false;
    message.success(t('Successfully_Created'));
    loadSeries();
  } catch (e) {
    message.error(errMsg(e, tf('Agt_Save_Failed', 'Could not save.')));
  } finally {
    busy.value = false;
  }
}
async function updateSeries(record, patch) {
  try {
    await http.put(`agt/series/${record.id}`, patch);
    loadSeries();
  } catch (e) {
    message.error(errMsg(e, tf('Agt_Save_Failed', 'Could not save.')));
  }
}
function editNext(record) {
  let value = record.next_number;
  Modal.confirm({
    title: tf('Agt_Next_Number_Edit', 'Next number (can only move forward)'),
    content: () => h(InputNumber, { defaultValue: value, min: record.next_number, style: 'width: 100%', onChange: v => { value = v; } }),
    onOk: () => updateSeries(record, { next_number: value }),
  });
}

async function loadDocuments(page) {
  docPage.value = Math.max(1, page || 1);
  docsLoading.value = true;
  try {
    documents.value = await http.get('agt/documents', {
      limit: 20, page: docPage.value, status: docStatus.value || undefined, search: docSearch.value || undefined,
    });
  } catch (e) {
    message.error(errMsg(e, tf('Agt_Docs_Load_Failed', 'Could not load the documents.')));
  } finally {
    docsLoading.value = false;
  }
}
async function docAction(record, action) {
  busy.value = true;
  try {
    const data = await http.post(`agt/documents/${record.id}/${action}`, {});
    const text = `${data.document_no || ''} — ${statusLabel(data.status)}`;
    if (data.success) message.success(text);
    else message.warning(data.error || text);
    loadDocuments(docPage.value);
  } catch (e) {
    message.error(errMsg(e, tf('Agt_Action_Failed', 'The request to AGT failed.')));
  } finally {
    busy.value = false;
  }
}
const retry = record => docAction(record, 'retry');
const checkDoc = record => docAction(record, 'check');
async function viewDoc(record) {
  try {
    const data = await http.get(`agt/documents/${record.id}`);
    Object.assign(jsonModal, { open: true, title: record.document_no, payload: data.payload, response: data.response });
  } catch (e) {
    message.error(errMsg(e, tf('Agt_Docs_Load_Failed', 'Could not load the document.')));
  }
}

async function loadLogs() {
  logsLoading.value = true;
  try {
    logs.value = (await http.get('agt/logs', { limit: 50 })) || [];
  } catch (e) {
    message.error(errMsg(e, tf('Agt_Logs_Load_Failed', 'Could not load the logs.')));
  } finally {
    logsLoading.value = false;
  }
}

function onTab(key) {
  if (key === 'series' && !series.value.length) loadSeries();
  if (key === 'documents' && !documents.value.data.length) loadDocuments(1);
  if (key === 'logs' && !logs.value.length) loadLogs();
}

onMounted(load);
</script>

<style scoped>
.mono { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 12px; }
.json { max-height: 60vh; overflow: auto; background: #fafafa; padding: 8px; border-radius: 4px; }
</style>
