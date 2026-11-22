<template>
  <div class="page">
    <PageHeader
      :title="$t('Import_Customers')"
      :breadcrumb="[$t('People'), $t('Customers'), $t('Imp_Import')]"
    >
      <template #extra>
        <a-button @click="$router.push('/customers')">{{ $t('Back') }}</a-button>
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

          <ImportErrorList :errors="errorMessages" filename="customer_import_errors" />

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
            <a-button href="/import/exemples/customers.xlsx" target="_blank" rel="noopener">
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
          {{ $t('Imp_One_Row_Per_Customer') }} ·
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
        <li><i18n-t keypath="Imp_Cust_Help_Code" tag="span"><template #code><strong>code</strong></template></i18n-t></li>
        <li><i18n-t keypath="Imp_Cust_Help_Username" tag="span"><template #username><strong>Username</strong></template><template #name><strong>name</strong></template></i18n-t></li>
        <li><i18n-t keypath="Imp_Cust_Help_Names" tag="span"><template #firstname><strong>firstname</strong></template><template #lastname><strong>lastname</strong></template></i18n-t></li>
        <li><i18n-t keypath="Imp_Cust_Help_Address" tag="span"><template #address><strong>Address</strong></template></i18n-t></li>
        <li><i18n-t keypath="Imp_Cust_Help_Opening" tag="span"><template #opening_balance><strong>opening_balance</strong></template></i18n-t></li>
        <li><i18n-t keypath="Imp_Cust_Help_Email" tag="span"><template #email><strong>email</strong></template><template #phone><strong>phone</strong></template></i18n-t></li>
      </ul>
    </a-card>
  </div>
</template>

<script setup>
/**
 * Import Customers wizard — POST customers/import (multipart, key `customers`).
 * All file checks, error-collection rules and messages are copied VERBATIM
 * from legacy ImportCustomers.vue (validation-parity rule): 20MB clamp,
 * xlsx/xls whitelist, 422/status:false handling that prefers errors[] and
 * never surfaces a bare "Validation failed".
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

const endpoint = 'customers/import';
const file = ref(null);
const uploading = ref(false);
const progress = ref(0);
const errorMessages = ref([]);
const warningMessages = ref([]);
const dz = ref();

const maxSize = 20 * 1024 * 1024; // 20MB
const accept = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel,.xlsx,.xls';

const columnsGuide = [
  { key: 'name', label: 'Username', required: true },
  { key: 'firstname', label: 'firstname', required: false },
  { key: 'lastname', label: 'lastname', required: false },
  { key: 'code', label: 'code (integer)', required: true },
  { key: 'email', label: 'email', required: false },
  { key: 'phone', label: 'phone', required: false },
  { key: 'tax_number', label: 'tax_number', required: false },
  { key: 'country', label: 'country', required: false },
  { key: 'city', label: 'city', required: false },
  { key: 'adresse', label: 'adresse', required: false },
  { key: 'opening_balance', label: 'opening_balance', required: false },
];

const canSubmit = computed(() => !!file.value && errorMessages.value.length === 0);

const exampleColumns = [
  { title: 'Username *', dataIndex: 'name' }, { title: 'firstname', dataIndex: 'firstname' },
  { title: 'lastname', dataIndex: 'lastname' }, { title: 'code (integer) *', dataIndex: 'code' },
  { title: 'email', dataIndex: 'email' }, { title: 'phone', dataIndex: 'phone' },
  { title: 'tax_number', dataIndex: 'tax_number' }, { title: 'country', dataIndex: 'country' },
  { title: 'city', dataIndex: 'city' }, { title: 'Address', dataIndex: 'adresse' },
  { title: 'opening_balance', dataIndex: 'opening_balance' },
];
const exampleRows = [
  { name: 'Acme Trading', firstname: 'Acme', lastname: 'Trading', code: '10001', email: 'info@acme.com', phone: '+1 555 0123', tax_number: 'TAX-9988', country: 'USA', city: 'New York', adresse: '5th Ave, Suite 2', opening_balance: '150.50' },
  { name: 'Jane Smith', firstname: 'Jane', lastname: 'Smith', code: '10002', email: 'jane@example.com', phone: '+44 20 7946 0958', tax_number: '', country: 'UK', city: 'London', adresse: '221B Baker Street', opening_balance: '0' },
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
    fd.append('customers', file.value);

    const resp = await uploadForm(endpoint, fd, (p) => { progress.value = p; });
    const data = resp && resp.data && typeof resp.data === 'object' ? resp.data : {};
    const http = resp ? resp.status : 0;

    if (http === 422 || data.status === false) {
      const errs = onlyErrorsArray(data);
      errorMessages.value = errs.length
        ? errs
        : (data.message && data.message.trim().toLowerCase() !== 'validation failed'
          ? [data.message]
          : [t('Imp_Fix_Highlighted_Errors')]);
      toast(t('Imp_Check_Error_List'), t('Imp_Import_Failed'), 'danger');
      return;
    }

    if (Array.isArray(data.warnings) && data.warnings.length) {
      warningMessages.value = data.warnings;
    }
    const count = data.imported || 0;
    toast(t('Imp_Customers_Imported', { count }), t('Success'), 'success');
    router.push('/customers');
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
