<template>
  <div class="page">
    <PageHeader :title="$t('Crm_Import_Leads')" :breadcrumb="[$t('Crm'), $t('Crm_Leads'), $t('Crm_Import')]">
      <template #actions>
        <a-button @click="$router.push('/crm/leads')"><template #icon><ArrowLeftOutlined /></template>{{ $t('Back') }}</a-button>
      </template>
    </PageHeader>

    <a-card :bordered="false" class="card">
      <a-steps :current="step" size="small" class="steps" :items="stepItems" />

      <!-- 1. Upload -->
      <div v-if="step === 0" class="pane">
        <a-upload-dragger :before-upload="pick" :show-upload-list="false" accept=".csv,.txt" class="drop">
          <p class="drop-ic"><InboxOutlined /></p>
          <p class="drop-title">{{ $t('Crm_Drop_Csv') }}</p>
          <p class="drop-hint">{{ $t('Crm_Csv_Hint') }}</p>
        </a-upload-dragger>
        <div class="tpl">
          <a-button type="link" @click="downloadTemplate"><template #icon><DownloadOutlined /></template>{{ $t('Crm_Download_Template') }}</a-button>
        </div>
        <a-spin v-if="busy" class="center" />
      </div>

      <!-- 2. Map columns -->
      <div v-else-if="step === 1" class="pane">
        <div class="file-line">
          <FileTextOutlined /> <strong>{{ file?.name }}</strong>
          <span class="muted">· {{ preview.total }} {{ $t('Crm_Rows') }}</span>
          <a-button type="link" size="small" @click="reset">{{ $t('Crm_Choose_Another') }}</a-button>
        </div>
        <a-alert v-if="preview.total > preview.max_rows" type="error" show-icon class="gap"
          :message="tp('Crm_Too_Many_Rows', 'At most {n} rows can be imported at once. Split the file.', { n: preview.max_rows })" />

        <div class="map-title">{{ $t('Crm_Match_Columns') }}</div>
        <div class="map">
          <div v-for="(h, i) in preview.headers" :key="i" class="map-row">
            <div class="map-col">
              <div class="map-head">{{ h || `#${i + 1}` }}</div>
              <div class="map-sample">{{ samples(i) }}</div>
            </div>
            <ArrowRightOutlined class="map-arrow" />
            <a-select :value="fieldFor(i)" :options="fieldOptions" allow-clear :placeholder="$t('Crm_Skip_Column')" class="map-select" @change="v => setField(i, v)" />
          </div>
        </div>

        <div class="map-title">{{ $t('Crm_Options') }}</div>
        <a-row :gutter="16">
          <a-col :xs="24" :md="8">
            <a-form-item :label="$t('Crm_Default_Owner')">
              <a-select v-model:value="opts.default_owner_id" :options="ownerOptions" allow-clear :placeholder="$t('Crm_Owner_From_Settings')" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="8">
            <a-form-item :label="$t('Crm_Default_Source')">
              <a-select v-model:value="opts.default_source_id" :options="sourceOptions" allow-clear />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="8" class="checks">
            <a-checkbox v-model:checked="opts.skip_duplicates">{{ $t('Crm_Skip_Duplicates') }}</a-checkbox>
            <a-checkbox v-model:checked="opts.create_sources">{{ $t('Crm_Create_Missing_Sources') }}</a-checkbox>
          </a-col>
        </a-row>

        <div class="actions">
          <a-button @click="reset">{{ $t('Cancel') }}</a-button>
          <a-button type="primary" :loading="busy" :disabled="!hasName || preview.total > preview.max_rows" @click="run">
            <template #icon><CloudUploadOutlined /></template>
            {{ tp('Crm_Import_N_Rows', 'Import {n} rows', { n: preview.total }) }}
          </a-button>
        </div>
        <div v-if="!hasName" class="warn">{{ $t('Crm_Map_A_Name') }}</div>
      </div>

      <!-- 3. Result -->
      <div v-else class="pane">
        <a-result :status="result.created ? 'success' : 'warning'" :title="tp('Crm_Imported_N', '{n} leads imported', { n: result.created })">
          <template #subTitle>
            <span v-if="result.skipped">{{ tp('Crm_Skipped_N', '{n} skipped as duplicates', { n: result.skipped }) }}</span>
            <span v-if="result.error_count"> · {{ tp('Crm_Errors_N', '{n} rows with problems', { n: result.error_count }) }}</span>
          </template>
          <template #extra>
            <a-button @click="reset">{{ $t('Crm_Import_Another') }}</a-button>
            <a-button type="primary" @click="$router.push('/crm/leads')">{{ $t('Crm_View_Leads') }}</a-button>
          </template>
        </a-result>
        <a-table v-if="result.errors?.length" :data-source="result.errors" :columns="errorColumns" size="small" :pagination="{ pageSize: 10 }" row-key="row" />
      </div>
    </a-card>
  </div>
</template>

<script setup>
/** Import leads from CSV: upload → match columns (auto-suggested) → result. */
import { ref, computed, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import {
  ArrowLeftOutlined, InboxOutlined, DownloadOutlined, FileTextOutlined, ArrowRightOutlined, CloudUploadOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';
import { uploadForm } from '../../lib/upload';
import { t, i18n } from '../../i18n';

const FIELD_LABELS = {
  full_name: ['Crm_Field_Full_Name', 'Full name'],
  first_name: ['Crm_First_Name', 'First name'],
  last_name: ['Crm_Last_Name', 'Last name'],
  company_name: ['Crm_Company_Name', 'Company name'],
  job_title: ['Crm_Job_Title', 'Job title'],
  email: ['Email', 'Email'],
  phone: ['Phone', 'Phone'],
  website: ['Crm_Website', 'Website'],
  industry: ['Crm_Industry', 'Industry'],
  address: ['Adress', 'Address'],
  city: ['City', 'City'],
  country: ['Country', 'Country'],
  source: ['Crm_Source', 'Source'],
  status: ['Status', 'Status'],
  owner: ['Crm_Owner', 'Owner'],
  rating: ['Crm_Rating', 'Rating'],
  estimated_value: ['Crm_Estimated_Value', 'Estimated value'],
  tags: ['Crm_Tags', 'Tags'],
  description: ['Description', 'Description'],
};

const step = ref(0);
const busy = ref(false);
const file = ref(null);
const preview = ref({ headers: [], sample: [], total: 0, mapping: {}, fields: [], max_rows: 5000 });
const mapping = ref({});
const opts = ref({ default_owner_id: undefined, default_source_id: undefined, skip_duplicates: true, create_sources: true });
const result = ref({});
const meta = ref({ owners: [], sources: [], can: {} });

const stepItems = computed(() => [
  { title: t('Crm_Upload_File', 'Upload file') },
  { title: t('Crm_Match_Columns', 'Match columns') },
  { title: t('Crm_Done', 'Done') },
]);
const fieldOptions = computed(() => (preview.value.fields || []).map(f => ({
  value: f, label: t(FIELD_LABELS[f]?.[0] || f, FIELD_LABELS[f]?.[1] || f),
  disabled: mapping.value[f] !== undefined,
})));
const ownerOptions = computed(() => (meta.value.can?.view_all ? meta.value.owners : meta.value.owners.filter(o => o.id === meta.value.me))
  .map(o => ({ value: o.id, label: o.name })));
const sourceOptions = computed(() => meta.value.sources.map(s => ({ value: s.id, label: s.name })));
const hasName = computed(() => ['full_name', 'first_name', 'last_name', 'company_name'].some(f => mapping.value[f] !== undefined));
const errorColumns = computed(() => [
  { title: t('Crm_Row', 'Row'), dataIndex: 'row', width: 90 },
  { title: t('Crm_Problem', 'Problem'), dataIndex: 'message' },
]);

function tp(key, fallback, params) {
  const out = i18n.global.t(key, params);
  return out !== key ? out : Object.entries(params).reduce((s, [k, v]) => s.replace(`{${k}}`, v), fallback);
}
function samples(i) {
  return (preview.value.sample || []).map(r => r[i]).filter(v => v !== undefined && v !== '').slice(0, 3).join(' · ') || '—';
}
function fieldFor(i) { return Object.keys(mapping.value).find(f => mapping.value[f] === i); }
function setField(i, field) {
  const next = { ...mapping.value };
  Object.keys(next).forEach(f => { if (next[f] === i) delete next[f]; });
  if (field) next[field] = i;
  mapping.value = next;
}

async function pick(f) {
  if (f.size > 5 * 1024 * 1024) { message.error(t('Crm_File_Too_Large', 'The file must be 5 MB or smaller')); return false; }
  file.value = f;
  busy.value = true;
  const fd = new FormData();
  fd.append('file', f);
  try {
    const res = await uploadForm('crm/leads/import/preview', fd);
    if (res.status >= 200 && res.status < 300) {
      preview.value = res.data;
      mapping.value = { ...(res.data.mapping || {}) };
      step.value = 1;
    } else {
      message.error(res.data?.errors ? Object.values(res.data.errors)[0][0] : (res.data?.message || t('Crm_Err_Could_Not_Read_The_File', 'Could not read the file')));
    }
  } catch (e) {
    message.error(t('Crm_Err_Could_Not_Read_The_File', 'Could not read the file'));
  } finally {
    busy.value = false;
  }
  return false;
}

async function run() {
  busy.value = true;
  const fd = new FormData();
  fd.append('file', file.value);
  fd.append('mapping', JSON.stringify(mapping.value));
  fd.append('skip_duplicates', opts.value.skip_duplicates ? '1' : '0');
  fd.append('create_sources', opts.value.create_sources ? '1' : '0');
  if (opts.value.default_owner_id) fd.append('default_owner_id', opts.value.default_owner_id);
  if (opts.value.default_source_id) fd.append('default_source_id', opts.value.default_source_id);
  try {
    const res = await uploadForm('crm/leads/import', fd);
    if (res.status >= 200 && res.status < 300) {
      result.value = res.data;
      step.value = 2;
    } else {
      message.error(res.data?.message || t('Crm_Err_Could_Not_Import', 'Could not import'));
    }
  } catch (e) {
    message.error(t('Crm_Err_Could_Not_Import', 'Could not import'));
  } finally {
    busy.value = false;
  }
}

function reset() {
  step.value = 0; file.value = null; mapping.value = {}; result.value = {};
}

async function downloadTemplate() {
  try { await http.download('crm/leads/import/template', 'leads_import_template.csv'); } catch (e) { message.error(t('Crm_Err_Could_Not_Download', 'Could not download')); }
}

onMounted(async () => {
  try { meta.value = await http.get('crm/leads/meta'); } catch (e) { /* options stay empty */ }
});
</script>

<style scoped>
.card { border-radius: 14px; }
.steps { max-width: 640px; margin: 4px auto 24px; }
.pane { max-width: 920px; margin: 0 auto; }
.drop { padding: 18px 0; }
.drop-ic { font-size: 40px; color: var(--app-primary, #6d28d9); margin-bottom: 8px; }
.drop-title { font-size: 16px; font-weight: 600; }
.drop-hint { opacity: 0.6; }
.tpl { text-align: center; margin-top: 10px; }
.center { display: block; margin: 16px auto; }
.muted { opacity: 0.6; }
.gap { margin-bottom: 12px; }
.file-line { display: flex; align-items: center; gap: 8px; margin-bottom: 14px; flex-wrap: wrap; }
.map-title { font-weight: 600; margin: 18px 0 10px; }
.map { display: flex; flex-direction: column; gap: 8px; }
.map-row { display: grid; grid-template-columns: 1fr 24px 260px; align-items: center; gap: 10px; padding: 8px 12px; border: 1px solid rgba(128, 128, 128, 0.18); border-radius: 10px; }
.map-head { font-weight: 600; }
.map-sample { font-size: 12px; opacity: 0.6; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 460px; }
.map-arrow { opacity: 0.4; }
.map-select { width: 100%; }
.checks { display: flex; flex-direction: column; gap: 8px; padding-top: 30px; }
.actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 12px; }
.warn { text-align: end; color: #d97706; font-size: 12.5px; margin-top: 6px; }
@media (max-width: 700px) {
  .map-row { grid-template-columns: 1fr; }
  .map-arrow { display: none; }
  .checks { padding-top: 0; }
}
</style>
