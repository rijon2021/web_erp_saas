<template>
  <div class="page">
    <PageHeader
      :title="$t('Import_Suppliers')"
      :breadcrumb="[$t('People'), $t('Suppliers'), $t('Imp_Import')]"
    >
      <template #extra>
        <a-button @click="$router.push('/suppliers')">{{ $t('Back') }}</a-button>
      </template>
    </PageHeader>

    <!-- Upload -->
    <a-card style="margin-bottom: 16px">
      <div style="max-width: 760px; margin: 0 auto">
          <ImportDropzone
            ref="dz"
            :file="file"
            :accept="accept"
            :title="$t('Imp_Drop_Excel_File')"
            :hint="$t('Imp_Allowed_Excel_20MB')"
            :disabled="uploading"
            @file="loadFile"
            @clear="clearFile()"
          />

          <ImportErrorList :errors="errorMessages" filename="supplier_import_errors" />

          <a-alert v-if="warningMessages.length" type="warning" style="margin-top: 16px" show-icon>
            <template #message>{{ $t('Warnings') }}</template>
            <template #description>
              <ul style="margin: 0; padding-left: 18px">
                <li v-for="(w, idx) in warningMessages" :key="'warn-' + idx">{{ w }}</li>
              </ul>
            </template>
          </a-alert>

          <a-progress v-if="uploading" :percent="progress" size="small" style="margin-top: 16px" />

          <div style="display: flex; justify-content: center; gap: 8px; margin-top: 20px; flex-wrap: wrap">
            <a-button type="primary" :disabled="!canSubmit || uploading" :loading="uploading" @click="submit">
              {{ $t('Imp_Import_Now') }}
            </a-button>
            <a-button href="/import/exemples/suppliers.xlsx" target="_blank" rel="noopener">
              <DownloadOutlined /> {{ $t('Download_exemple') }}
            </a-button>
            <a-button :disabled="!file || uploading" @click="clearFile()">{{ $t('Reset') }}</a-button>
          </div>
      </div>
    </a-card>

    <!-- Expected file format -->
    <a-card size="small" :title="$t('Imp_Expected_File_Format')">
      <template #extra>
        <span style="font-size: 12px; color: rgba(var(--ink-rgb), 0.45)">
          {{ $t('Imp_One_Row_Per_Supplier') }} ·
          <a-tag color="green" style="margin: 0">{{ $t('Imp_Green_Star') }}</a-tag> {{ $t('Imp_Equals_Required') }}
        </span>
      </template>
      <a-space wrap style="margin-bottom: 12px">
        <a-tag v-for="c in columnsGuide" :key="c.key" :color="c.required ? 'green' : 'default'">
          {{ c.label }}{{ c.required ? ' *' : '' }}
        </a-tag>
      </a-space>
      <a-table
        :columns="exampleColumns" :data-source="exampleRows"
        size="small" :pagination="false" :scroll="{ x: 'max-content' }" row-key="code"
      />
      <ul style="padding-left: 18px; margin: 12px 0 0 0; font-size: 12px">
        <li><i18n-t keypath="Imp_Supp_Help_Code" tag="span"><template #code><strong>code</strong></template></i18n-t></li>
        <li><i18n-t keypath="Imp_Supp_Help_Name" tag="span"><template #name><strong>name</strong></template></i18n-t></li>
        <li><i18n-t keypath="Imp_Supp_Help_Address" tag="span"><template #address><strong>Address</strong></template></i18n-t></li>
      </ul>
    </a-card>
  </div>
</template>

<script setup>
/**
 * Import Suppliers wizard — POST suppliers/import (multipart, key `suppliers`).
 * File checks / error rules copied VERBATIM from legacy ImportSuppliers.vue.
 */
import { ref, computed } from 'vue';
import { useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { notification } from 'ant-design-vue';
import { DownloadOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import ImportErrorList from '../../components/ImportErrorList.vue';
import ImportDropzone from '../../components/ImportDropzone.vue';
import { uploadForm } from '../../lib/upload';
import { start as startProgress, done as doneProgress } from '../../lib/progress';

const router = useRouter();
const { t } = useI18n();

const endpoint = 'suppliers/import';
const file = ref(null);
const uploading = ref(false);
const progress = ref(0);
const errorMessages = ref([]);
const warningMessages = ref([]);
const dz = ref();

const maxSize = 20 * 1024 * 1024; // 20MB
const accept = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel,.xlsx,.xls';

const columnsGuide = [
  { key: 'name', label: 'name', required: true },
  { key: 'code', label: 'code (integer)', required: true },
  { key: 'email', label: 'email', required: false },
  { key: 'phone', label: 'phone', required: false },
  { key: 'tax_number', label: 'tax_number', required: false },
  { key: 'country', label: 'country', required: false },
  { key: 'city', label: 'city', required: false },
  { key: 'adresse', label: 'adresse', required: false },
];

const canSubmit = computed(() => !!file.value && errorMessages.value.length === 0);

const exampleColumns = [
  { title: 'name *', dataIndex: 'name' }, { title: 'code (integer) *', dataIndex: 'code' },
  { title: 'email', dataIndex: 'email' }, { title: 'phone', dataIndex: 'phone' },
  { title: 'tax_number', dataIndex: 'tax_number' }, { title: 'country', dataIndex: 'country' },
  { title: 'city', dataIndex: 'city' }, { title: 'Address', dataIndex: 'adresse' },
];
const exampleRows = [
  { name: 'ACME Supplies', code: '2001', email: 'contact@acmesupplies.com', phone: '+1 555 0100', tax_number: 'VAT-4455', country: 'USA', city: 'New York', adresse: '5th Ave' },
  { name: 'Global Vendor', code: '2002', email: '', phone: '+44 20 7946 0000', tax_number: '', country: 'UK', city: 'London', adresse: '221B Baker Street' },
];

function toast(msg, title, type) {
  notification[type === 'danger' ? 'error' : type]({ message: title, description: msg });
}

function clearErrors() {
  errorMessages.value = [];
  warningMessages.value = [];
}

function loadFile(f) {
  clearErrors();
  const msgs = [];
  if (f.size > maxSize) msgs.push(t('Imp_File_Too_Large_20MB'));
  const name = f.name || '';
  const ext = name.split('.').pop().toLowerCase();
  if (['xlsx', 'xls'].indexOf(ext) === -1) msgs.push(t('Imp_Unsupported_Excel'));
  if (msgs.length) {
    errorMessages.value = msgs;
    clearFile(false);
    return;
  }
  file.value = f;
}

function clearFile(resetInput) {
  if (typeof resetInput === 'undefined') resetInput = true;
  file.value = null;
  if (resetInput && dz.value) dz.value.resetInput();
}

function onlyErrorsArray(data) {
  if (!data || !data.errors) return [];
  const e = data.errors;
  const out = [];
  if (Array.isArray(e)) {
    for (let i = 0; i < e.length; i++) if (e[i]) out.push(String(e[i]));
  } else if (typeof e === 'object') {
    Object.keys(e).forEach((k) => {
      const v = e[k];
      if (Array.isArray(v)) v.forEach((m) => { if (m) out.push(String(m)); });
      else if (v) out.push(String(v));
    });
  } else if (typeof e === 'string') {
    out.push(e);
  }
  const seen = {};
  return out.map((s) => String(s).trim()).filter((s) => s && !seen[s] && (seen[s] = 1));
}

async function submit() {
  if (!file.value) {
    errorMessages.value = [t('Imp_Choose_File')];
    return;
  }
  clearErrors();
  uploading.value = true;
  progress.value = 0;
  startProgress();

  try {
    const fd = new FormData();
    fd.append('suppliers', file.value);

    const resp = await uploadForm(endpoint, fd, (p) => { progress.value = p; });
    const data = resp && resp.data && typeof resp.data === 'object' ? resp.data : {};
    const http = resp ? resp.status : 0;

    if (http === 422 || data.status === false) {
      let errs = onlyErrorsArray(data);
      if (!errs.length && data && typeof data.message === 'string'
        && data.message.trim().toLowerCase() !== 'validation failed') {
        errs = [data.message];
      }
      if (!errs.length) {
        errs = [t('Imp_Fix_Highlighted_Errors')];
      }
      errorMessages.value = errs;
      toast(t('Imp_Check_Error_List'), t('Imp_Import_Failed'), 'danger');
      return;
    }

    if (Array.isArray(data.warnings) && data.warnings.length) {
      warningMessages.value = data.warnings;
    }
    const count = data.imported || 0;
    toast(t('Imp_Suppliers_Imported', { count }), t('Success'), 'success');
    router.push('/suppliers');
  } catch (e) {
    const msg = e && e.message ? String(e.message) : t('Imp_Network_Error_Retry');
    errorMessages.value = [msg];
    toast(t('Imp_Upload_Network_Failed'), t('Imp_Error'), 'danger');
  } finally {
    doneProgress();
    uploading.value = false;
    progress.value = 0;
  }
}
</script>
