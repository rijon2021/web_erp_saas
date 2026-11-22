<template>
  <div class="page">
    <PageHeader
      :title="$t('Opening_Stock')"
      :breadcrumb="[$t('Products'), $t('Opening_Stock')]"
    >
      <template #extra>
        <a-button @click="$router.push('/products')">{{ $t('Back') }}</a-button>
      </template>
    </PageHeader>

    <!-- Upload -->
    <a-card style="margin-bottom: 16px">
      <div style="display: flex; justify-content: center; margin-bottom: 24px">
        <a-segmented v-model:value="importType" :options="typeOptions" @change="clearErrors" />
      </div>

      <div style="max-width: 760px; margin: 0 auto">
          <a-alert
            type="info" show-icon style="margin-bottom: 16px"
            :message="$t('Imp_Opening_Stock_Title')"
            :description="$t('Imp_Opening_Stock_Desc')"
          />

          <a-form-item
            :label="$t('warehouse') + ' *'" layout="vertical"
            :validate-status="warehouseTouched && !warehouseId ? 'error' : undefined"
            :help="warehouseTouched && !warehouseId ? $t('Imp_Choose_Warehouse') : $t('Imp_Stock_Added_To_Warehouse')"
          >
            <a-select
              v-model:value="warehouseId"
              style="width: 100%"
              :options="warehouseOptions"
              :placeholder="$t('Imp_Choose_Warehouse_Ph')"
              show-search
              option-filter-prop="label"
              allow-clear
            />
          </a-form-item>

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

          <ImportErrorList :errors="errorMessages" filename="opening_stock_errors" />

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
            <a-button type="primary" :disabled="uploading" :loading="uploading" @click="submit">
              {{ $t('Imp_Import_Now') }}
            </a-button>
            <a-button :href="exampleHref" target="_blank" rel="noopener">
              <DownloadOutlined /> {{ $t('Download_exemple') }}
            </a-button>
            <a-button :disabled="uploading" @click="resetAll">{{ $t('Reset') }}</a-button>
          </div>
      </div>
    </a-card>

    <!-- Expected file format -->
    <a-card size="small" :title="$t('Imp_Expected_File_Format')">
      <template #extra>
        <span style="font-size: 12px; color: rgba(var(--ink-rgb), 0.45)">{{ $t('Imp_All_Columns_Required') }}</span>
      </template>
      <a-table
        :columns="activeExample.columns" :data-source="activeExample.rows"
        size="small" :pagination="false" row-key="__k"
      />
    </a-card>
  </div>
</template>

<script setup>
/**
 * Opening Stock import — GET opening-stock/import/meta for warehouses,
 * POST opening-stock/import/{single|variants} with warehouse_id + products.
 * File checks / error collectors / submit order (warehouse checked BEFORE
 * file) copied VERBATIM from legacy opening_stock_import.vue.
 */
import { ref, computed, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { notification } from 'ant-design-vue';
import { DownloadOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import ImportErrorList from '../../components/ImportErrorList.vue';
import ImportDropzone from '../../components/ImportDropzone.vue';
import http from '../../lib/http';
import { uploadForm } from '../../lib/upload';
import { isDuplicateImport, confirmDuplicateImport } from '../../lib/importDuplicate';
import { start as startProgress, done as doneProgress } from '../../lib/progress';

const router = useRouter();
const { t } = useI18n();

const metaEndpoint = 'opening-stock/import/meta';
const singleEndpoint = 'opening-stock/import/single';
const variantEndpoint = 'opening-stock/import/variants';

const importType = ref('single');
const typeOptions = computed(() => [
  { label: t('Imp_Single_Products'), value: 'single' },
  { label: t('Imp_Variant_Products'), value: 'variant' },
]);
const warehouseId = ref(null);
const warehouseTouched = ref(false);
const warehouses = ref([]);
const file = ref(null);
const uploading = ref(false);
const progress = ref(0);
const errorMessages = ref([]);
const warningMessages = ref([]);
const dz = ref();

const maxSize = 20 * 1024 * 1024; // 20MB
const accept = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel,.xlsx,.xls';

const warehouseOptions = computed(() => warehouses.value.map((w) => ({ label: w.name, value: w.id })));

const EXAMPLES = {
  single: {
    columns: [
      { title: 'product_code *', dataIndex: 'product_code' },
      { title: 'qty *', dataIndex: 'qty' },
    ],
    rows: [
      { __k: 1, product_code: 'TSHIRT-BLUE', qty: '10' },
      { __k: 2, product_code: 'MUG-COF-01', qty: '25' },
    ],
  },
  variant: {
    columns: [
      { title: 'product_code *', dataIndex: 'product_code' },
      { title: 'variant_code *', dataIndex: 'variant_code' },
      { title: 'qty *', dataIndex: 'qty' },
    ],
    rows: [
      { __k: 1, product_code: 'TSHIRT-100', variant_code: 'TSHIRT-100-S', qty: '5' },
      { __k: 2, product_code: 'TSHIRT-100', variant_code: 'TSHIRT-100-M', qty: '8' },
      { __k: 3, product_code: 'TSHIRT-100', variant_code: 'TSHIRT-100-L', qty: '7' },
    ],
  },
};
const activeExample = computed(() => EXAMPLES[importType.value] || EXAMPLES.single);
const exampleHref = computed(() => (importType.value === 'single'
  ? '/import/exemples/opening_stock_single.xlsx'
  : '/import/exemples/opening_stock_variants.xlsx'));

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

function resetAll() {
  clearFile(true);
  warehouseId.value = null;
  warehouseTouched.value = false;
  clearErrors();
}

function flattenLaravelErrorsObject(errorsObj) {
  const out = [];
  if (!errorsObj || typeof errorsObj !== 'object') return out;
  Object.keys(errorsObj).forEach((k) => {
    const v = errorsObj[k];
    if (Array.isArray(v)) v.forEach((m) => { if (m) out.push(String(m)); });
    else if (v) out.push(String(v));
  });
  return out;
}

function collectErrorsFromResponse(data) {
  let out = [];
  if (!data || typeof data !== 'object') return out;
  if (data.message && typeof data.message === 'string') out.push(data.message);
  if (Array.isArray(data.errors)) {
    data.errors.forEach((e) => { if (e) out.push(String(e)); });
  } else if (data.errors && typeof data.errors === 'object') {
    out = out.concat(flattenLaravelErrorsObject(data.errors));
  }
  if (Array.isArray(data.details)) {
    data.details.forEach((d) => { if (d) out.push(String(d)); });
  } else if (data.details && typeof data.details === 'string') {
    out.push(data.details);
  }
  if (data.error && typeof data.error === 'string') out.push(data.error);
  const seen = {}; const unique = [];
  out.forEach((u) => { if (!seen[u]) { seen[u] = true; unique.push(u); } });
  return unique;
}

async function submit(confirmDuplicate = false) {
  warehouseTouched.value = true;
  if (!warehouseId.value) {
    errorMessages.value = [t('Imp_Choose_Warehouse')];
    return;
  }
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
    fd.append('warehouse_id', warehouseId.value);
    fd.append('products', file.value);
    if (confirmDuplicate === true) fd.append('confirm_duplicate', '1');

    const endpoint = importType.value === 'single' ? singleEndpoint : variantEndpoint;
    const resp = await uploadForm(endpoint, fd, (p) => { progress.value = p; });
    const data = resp ? resp.data : null;
    const ok = data && typeof data === 'object' && (data.status === true || data.success === true);

    // Same rows already posted: ask, and resend only on an explicit yes.
    if (!ok && isDuplicateImport(data)) {
      doneProgress();
      uploading.value = false;
      if (await confirmDuplicateImport(data)) await submit(true);
      return;
    }

    if (!ok) {
      const msgs = collectErrorsFromResponse(data && typeof data === 'object' ? data : null);
      errorMessages.value = msgs.length ? msgs : [t('Imp_Failed_Review_File')];
      toast(t('Imp_Check_Error_List'), t('Imp_Import_Failed'), 'danger');
      return;
    }

    toast(t('Imp_Opening_Stock_Imported'), t('Success'), 'success');
    router.push('/products');
  } catch (err) {
    errorMessages.value = err && err.message ? [String(err.message)] : [t('Imp_Upload_Went_Wrong')];
    toast(t('Imp_Check_Error_List'), t('Imp_Import_Failed'), 'danger');
  } finally {
    doneProgress();
    uploading.value = false;
    progress.value = 0;
  }
}

onMounted(async () => {
  try {
    const data = await http.get(metaEndpoint);
    warehouses.value = data && data.warehouses ? data.warehouses : [];
  } catch (e) {
    warehouses.value = [];
  }
});
</script>
