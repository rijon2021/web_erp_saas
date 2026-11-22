<template>
  <div class="page">
    <PageHeader
      :title="$t('Page_Import_Products_Update_Only')"
      :breadcrumb="[$t('Products'), $t('Imp_Import_Update_Only')]"
    >
      <template #extra>
        <a-button @click="$router.push('/products')">{{ $t('Back') }}</a-button>
      </template>
    </PageHeader>

    <!-- Upload -->
    <a-card style="margin-bottom: 16px">
      <div style="max-width: 760px; margin: 0 auto">
          <a-alert
            type="info" show-icon style="margin-bottom: 16px"
            :message="$t('Imp_Update_Only')"
            :description="$t('Imp_Update_Only_Desc_Blank')"
          />

          <ImportDropzone
            ref="dz"
            :file="file"
            :accept="accept"
            :title="$t('Imp_Drop_File')"
            :hint="$t('Imp_Allowed_Csv_Excel_20MB')"
            :disabled="uploading"
            @file="loadFile"
            @clear="clearFile()"
          />

          <a-alert v-if="successMessage" type="success" style="margin-top: 16px" show-icon>
            <template #message>{{ successMessage }}</template>
            <template #description>
              <div v-if="importResults" style="font-size: 12px">
                <div>{{ $t('Imp_Updated_N_Products', { n: importResults.updated }) }}</div>
                <div v-if="importResults.not_found > 0" style="color: #d48806">
                  {{ $t('Imp_Not_Found_N_Codes', { n: importResults.not_found }) }}
                </div>
                <div v-if="importResults.errors > 0" style="color: #cf1322">
                  {{ $t('Imp_Errors_N', { n: importResults.errors }) }}
                </div>
                <div v-if="importResults.ignored_columns.length" style="color: #d48806">
                  {{ $t('Imp_Ignored_Columns', { columns: importResults.ignored_columns.join(', ') }) }}
                </div>
              </div>
            </template>
          </a-alert>

          <ImportErrorList :errors="errorMessages" filename="update_only_errors" />
          <!-- Partial success: the rows that were skipped, listed in full. -->
          <ImportErrorList
            v-if="importResults && importResults.error_list.length"
            :errors="importResults.error_list" type="warning"
            :title="$t('Imp_Rows_Not_Updated')" filename="update_only_skipped"
          />

          <a-progress v-if="uploading" :percent="progress" size="small" style="margin-top: 16px" />

          <div style="display: flex; justify-content: center; gap: 8px; margin-top: 20px; flex-wrap: wrap">
            <a-button type="primary" :disabled="!canSubmit || uploading" :loading="uploading" @click="submit">
              {{ $t('Imp_Import_Now') }}
            </a-button>
            <a-button href="/import/exemples/update_products.csv" target="_blank" rel="noopener">
              <DownloadOutlined /> {{ $t('Download_exemple') }}
            </a-button>
            <a-button :disabled="!file || uploading" @click="clearFile()">{{ $t('Reset') }}</a-button>
          </div>
      </div>
    </a-card>

    <!-- Expected file format -->
    <a-card size="small" :title="$t('Imp_Expected_File_Format')">
      <template #extra>
        <span style="font-size: 12px; color: rgba(var(--ink-rgb), 0.45)">{{ $t('Imp_One_Row_Per_Product_Blank_Unchanged') }}</span>
      </template>
      <a-table
        :columns="exampleColumns" :data-source="exampleRows"
        size="small" :pagination="false" row-key="code"
      />
      <ul style="padding-left: 18px; margin: 12px 0 0 0; font-size: 12px">
        <li>{{ $t('Imp_Update_Only_Matching_Help') }}</li>
      </ul>
    </a-card>

    <!-- Additional barcodes: Code + Barcode rows (manufacturer EANs …). -->
    <a-card size="small" :title="$t('Imp_Barcodes_Title')" style="margin-top: 16px">
      <div style="max-width: 760px; margin: 0 auto">
        <a-alert type="info" show-icon style="margin-bottom: 16px" :message="$t('Imp_Barcodes_Help')" />
        <a-radio-group v-model:value="bc.mode" style="margin-bottom: 12px" :disabled="bc.uploading">
          <a-radio value="append">{{ $t('Imp_Barcodes_Append') }}</a-radio>
          <a-radio value="replace">{{ $t('Imp_Barcodes_Replace') }}</a-radio>
        </a-radio-group>
        <ImportDropzone
          :file="bc.file" :accept="accept" :title="$t('Imp_Drop_File')" :hint="$t('Imp_Barcodes_File_Hint')"
          :disabled="bc.uploading" @file="f => { bc.file = f; bc.errors = []; bc.result = ''; }" @clear="bc.file = null"
        />
        <a-alert v-if="bc.result" type="success" show-icon style="margin-top: 16px" :message="bc.result" />
        <ImportErrorList :errors="bc.errors" filename="barcode_import_errors" />
        <div style="display: flex; justify-content: center; gap: 8px; margin-top: 16px; flex-wrap: wrap">
          <a-button type="primary" :disabled="!bc.file" :loading="bc.uploading" @click="importBarcodes">
            {{ $t('Imp_Import_Now') }}
          </a-button>
          <a-button href="/import/exemples/product_barcodes.csv" target="_blank" rel="noopener">
            <DownloadOutlined /> {{ $t('Download_exemple') }}
          </a-button>
        </div>
      </div>
    </a-card>

    <!-- Different prices per store: export the sheet, edit it, import it back. -->
    <a-card v-if="sp.meta && sp.meta.available" size="small" :title="$t('Store_Prices')" style="margin-top: 16px">
      <div style="max-width: 760px; margin: 0 auto">
        <a-alert
          v-if="!sp.meta.enabled" type="warning" show-icon style="margin-bottom: 16px"
          :message="$t('Store_Prices_Switch_Off')"
        />
        <a-alert type="info" show-icon style="margin-bottom: 16px" :message="$t('Store_Prices_Import_Help')" />

        <div style="display: flex; gap: 8px; flex-wrap: wrap; align-items: center; margin-bottom: 16px">
          <a-select v-model:value="sp.warehouse" :options="spWarehouseOptions" style="min-width: 220px" />
          <a-checkbox v-model:checked="sp.onlySet">{{ $t('Store_Prices_Only_Set') }}</a-checkbox>
          <a-button :loading="sp.exporting" @click="exportStorePrices">
            <DownloadOutlined /> {{ $t('Store_Prices_Export') }}
          </a-button>
        </div>

        <ImportDropzone
          :file="sp.file" :accept="accept" :title="$t('Imp_Drop_File')" :hint="$t('Imp_Allowed_Csv_Excel_20MB')"
          :disabled="sp.uploading || !sp.meta.enabled"
          @file="f => { sp.file = f; sp.errors = []; sp.result = ''; sp.skipped = []; }" @clear="sp.file = null"
        />
        <a-alert v-if="sp.result" type="success" show-icon style="margin-top: 16px" :message="sp.result" />
        <ImportErrorList :errors="sp.errors" filename="store_price_import_errors" />
        <ImportErrorList
          v-if="sp.skipped.length" :errors="sp.skipped" type="warning"
          :title="$t('Imp_Rows_Not_Updated')" filename="store_price_import_skipped"
        />
        <div style="display: flex; justify-content: center; gap: 8px; margin-top: 16px; flex-wrap: wrap">
          <a-button type="primary" :disabled="!sp.file || !sp.meta.enabled" :loading="sp.uploading" @click="importStorePrices">
            {{ $t('Imp_Import_Now') }}
          </a-button>
        </div>
      </div>
    </a-card>
  </div>
</template>

<script setup>
/**
 * Import Products (Update Only) — POST products/import/update-only
 * (multipart, key `products`). Accepts csv/xlsx/xls (unlike the other
 * wizards). Success shows an updated/not_found/errors panel then redirects
 * after 2s — all copied from legacy Import_products_update.vue.
 */
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import { useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { notification } from 'ant-design-vue';
import { DownloadOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import ImportErrorList from '../../components/ImportErrorList.vue';
import ImportDropzone from '../../components/ImportDropzone.vue';
import { uploadForm } from '../../lib/upload';
import http from '../../lib/http';
import { start as startProgress, done as doneProgress } from '../../lib/progress';

const router = useRouter();
const { t } = useI18n();

/* Different prices per store — GET products/warehouse-prices/meta|export,
 * POST products/import/warehouse-prices {products}. The card stays hidden
 * until the database update has been run. */
const sp = ref({
  meta: null, warehouse: 0, onlySet: false, exporting: false,
  file: null, uploading: false, errors: [], skipped: [], result: '',
});
const spWarehouseOptions = computed(() => [
  { value: 0, label: t('All_Warehouses') },
  ...((sp.value.meta && sp.value.meta.warehouses) || []).map(w => ({ value: w.id, label: w.name })),
]);
onMounted(async () => {
  try {
    sp.value.meta = await http.get('products/warehouse-prices/meta');
  } catch (e) {
    sp.value.meta = null; // no permission / not migrated: no card
  }
});
async function exportStorePrices() {
  sp.value.exporting = true;
  try {
    const qs = new URLSearchParams();
    if (sp.value.warehouse) qs.set('warehouse_id', sp.value.warehouse);
    if (sp.value.onlySet) qs.set('only_set', '1');
    await http.download(`products/warehouse-prices/export?${qs.toString()}`, 'store_prices.csv');
  } catch (e) {
    notification.error({ message: t('Error'), description: e?.message || t('Imp_Upload_Went_Wrong') });
  } finally {
    sp.value.exporting = false;
  }
}
async function importStorePrices() {
  sp.value.uploading = true;
  sp.value.errors = [];
  sp.value.skipped = [];
  sp.value.result = '';
  try {
    const fd = new FormData();
    fd.append('products', sp.value.file);
    const { status, data } = await uploadForm('products/import/warehouse-prices', fd);
    if (status !== 200 || !data?.status) {
      sp.value.errors = Array.isArray(data?.errors) && data.errors.length
        ? data.errors
        : (data?.errors && typeof data.errors === 'object' ? Object.values(data.errors).flat() : [data?.message || t('Imp_Failed_Review_File')]);
      return;
    }
    sp.value.result = t('Store_Prices_Import_Done', {
      created: data.created || 0, updated: data.updated || 0, cleared: data.cleared || 0,
    });
    sp.value.skipped = Array.isArray(data.error_list) ? data.error_list : [];
    sp.value.file = null;
  } catch (e) {
    sp.value.errors = [e?.message || t('Imp_Upload_Went_Wrong')];
  } finally {
    sp.value.uploading = false;
  }
}

const endpoint = 'products/import/update-only';

/* Additional barcodes — POST products/import/barcodes {products, mode}. */
const bc = ref({ mode: 'append', file: null, uploading: false, errors: [], result: '' });
async function importBarcodes() {
  bc.value.uploading = true;
  bc.value.errors = [];
  bc.value.result = '';
  try {
    const fd = new FormData();
    fd.append('products', bc.value.file);
    fd.append('mode', bc.value.mode);
    const { status, data } = await uploadForm('products/import/barcodes', fd);
    if (status !== 200 || !data?.status) {
      bc.value.errors = Array.isArray(data?.errors) && data.errors.length
        ? data.errors
        : (data?.errors && typeof data.errors === 'object' ? Object.values(data.errors).flat() : [data?.message || t('Imp_Failed_Review_File')]);
      return;
    }
    bc.value.result = t('Imp_Barcodes_Done', { products: data.products, added: data.added });
    bc.value.file = null;
  } catch (e) {
    bc.value.errors = [e?.message || t('Imp_Upload_Went_Wrong')];
  } finally {
    bc.value.uploading = false;
  }
}

const file = ref(null);
const uploading = ref(false);
const progress = ref(0);
const successMessage = ref('');
const importResults = ref(null);
const errorMessages = ref([]);
const warningMessages = ref([]);
const dz = ref();
let redirectTimer = null;

const maxSize = 20 * 1024 * 1024; // 20MB
const accept = '.csv,.xlsx,.xls,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel,text/csv';

const canSubmit = computed(() => !!file.value && errorMessages.value.length === 0);

const exampleColumns = [
  { title: 'code *', dataIndex: 'code' },
  { title: 'cost', dataIndex: 'cost' },
  { title: 'retail_price', dataIndex: 'retail_price' },
  { title: 'name', dataIndex: 'name' },
  { title: 'category', dataIndex: 'category' },
  { title: 'sub_category', dataIndex: 'sub_category' },
  { title: 'brand', dataIndex: 'brand' },
];
const exampleRows = [
  { code: 'PROD-001', cost: '10.50', retail_price: '19.99', name: '', category: '', sub_category: '', brand: '' },
  { code: 'PROD-002', cost: '', retail_price: '12.50', name: '', category: 'Brakes', sub_category: 'Brake Pads', brand: '' },
  { code: 'PROD-003', cost: '8.00', retail_price: '', name: 'Oil Filter 5W-30', category: '', sub_category: '', brand: 'Bosch' },
];

onBeforeUnmount(() => { if (redirectTimer) clearTimeout(redirectTimer); });

function toast(msg, title, type) {
  notification[type === 'danger' ? 'error' : type]({ message: title, description: msg });
}

function clearErrors() {
  errorMessages.value = [];
  warningMessages.value = [];
}

function loadFile(f) {
  clearErrors();
  successMessage.value = '';
  importResults.value = null;
  const msgs = [];
  if (f.size > maxSize) msgs.push(t('Imp_File_Too_Large_20MB'));
  const name = f.name || '';
  const ext = name.split('.').pop().toLowerCase();
  if (['xlsx', 'xls', 'csv'].indexOf(ext) === -1) msgs.push(t('Imp_Unsupported_Csv_Excel'));
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
  successMessage.value = '';
  importResults.value = null;
  if (resetInput && dz.value) dz.value.resetInput();
}

function flattenLaravelErrors(errors) {
  const out = [];
  if (!errors) return out;
  if (Array.isArray(errors)) {
    errors.forEach((v) => { if (v != null && v !== '') out.push(String(v)); });
    return out;
  }
  if (typeof errors === 'object') {
    Object.keys(errors).forEach((k) => {
      const val = errors[k];
      if (Array.isArray(val)) val.forEach((vv) => { if (vv != null && vv !== '') out.push(String(vv)); });
      else if (val != null && val !== '') out.push(String(val));
    });
    return out;
  }
  if (typeof errors === 'string') return [errors];
  return out;
}

function collectErrorsFromResponse(data) {
  const out = [];
  if (!data) return out;
  if (typeof data === 'string') {
    const txt = data.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
    if (txt && txt.toLowerCase() !== 'validation failed') out.push(txt);
    return out;
  }
  if (data.errors) {
    const errs = flattenLaravelErrors(data.errors);
    if (errs.length) return errs;
  }
  if (Array.isArray(data.messages)) out.push(...data.messages);
  if (data.details) {
    if (Array.isArray(data.details)) out.push(...data.details);
    else if (typeof data.details === 'string') out.push(data.details);
  }
  if (typeof data.error === 'string') out.push(data.error);
  if (!out.length && typeof data.message === 'string') {
    const msg = data.message.trim();
    if (msg.toLowerCase() !== 'validation failed') out.push(msg);
  }
  const seen = {}; const filtered = [];
  out.forEach((s) => {
    const t = String(s).trim();
    if (t && !seen[t]) { seen[t] = true; filtered.push(t); }
  });
  return filtered;
}

async function submit() {
  if (!file.value) {
    errorMessages.value = [t('Imp_Choose_File')];
    return;
  }
  clearErrors();
  successMessage.value = '';
  importResults.value = null;
  uploading.value = true;
  progress.value = 0;
  startProgress();

  try {
    const fd = new FormData();
    fd.append('products', file.value);

    const resp = await uploadForm(endpoint, fd, (p) => { progress.value = p; });
    const data = resp ? resp.data : null;
    const ok = data && typeof data === 'object' && (data.status === true || data.success === true);

    if (!ok) {
      // Every rejected row when the server listed them, else its summary.
      const msgs = Array.isArray(data?.error_list) && data.error_list.length
        ? data.error_list
        : collectErrorsFromResponse(data);
      errorMessages.value = msgs.length ? msgs : [t('Imp_Failed_Review_File')];
      toast(t('Imp_Check_Error_List'), t('Imp_Import_Failed'), 'danger');
      return;
    }

    importResults.value = {
      updated: data.updated || 0,
      not_found: data.not_found || 0,
      errors: data.errors || 0,
      ignored_columns: Array.isArray(data.ignored_columns) ? data.ignored_columns : [],
      error_list: Array.isArray(data.error_list) ? data.error_list : [],
    };
    successMessage.value = data.message || t('Imp_Products_Updated');
    toast(successMessage.value, t('Success'), 'success');
    // Leave only a clean import; anything to report stays on screen to read.
    const r = importResults.value;
    if (!r.not_found && !r.errors && !r.ignored_columns.length) {
      redirectTimer = setTimeout(() => { router.push('/products'); }, 2000);
    }
  } catch (err) {
    errorMessages.value = err && err.message ? [String(err.message)] : [t('Imp_Upload_Went_Wrong')];
    toast(t('Imp_Check_Error_List'), t('Imp_Import_Failed'), 'danger');
  } finally {
    doneProgress();
    uploading.value = false;
    progress.value = 0;
  }
}
</script>
