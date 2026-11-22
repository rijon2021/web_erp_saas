<template>
  <a-modal
    :open="open"
    :title="$t('LicK_Import_Title')"
    width="760px"
    :mask-closable="false"
    destroy-on-close
    @update:open="v => emit('update:open', v)"
  >
    <!-- ============================== step 1: keys ============================== -->
    <a-form v-if="step === 1" layout="vertical" style="margin-top: 8px">
      <a-row :gutter="12">
        <a-col :xs="24" :md="12">
          <a-form-item :label="$t('Product')" required>
            <a-select
              v-model:value="form.product_id"
              show-search
              option-filter-prop="label"
              :placeholder="$t('Choose_Product')"
              :options="productOptions"
              :not-found-content="$t('LicK_No_Pool_Products')"
              @change="onProductChange"
            />
          </a-form-item>
        </a-col>
        <a-col v-if="variantOptions.length" :xs="24" :md="12">
          <a-form-item :label="$t('LicK_Edition')" required>
            <a-select v-model:value="form.product_variant_id" :placeholder="$t('PleaseSelect')" :options="variantOptions" />
          </a-form-item>
        </a-col>
        <a-col :xs="24" :md="12">
          <a-form-item :label="$t('Warehouse')" required>
            <a-select v-model:value="form.warehouse_id" :placeholder="$t('Choose_Warehouse')" :options="warehouseOptions" />
          </a-form-item>
        </a-col>
        <a-col :xs="12" :md="6">
          <a-form-item :label="$t('LicK_Cost_Each')" :extra="$t('LicK_Cost_Help')">
            <a-input-number v-model:value="form.cost" :min="0" style="width: 100%" />
          </a-form-item>
        </a-col>
        <a-col :xs="12" :md="6">
          <a-form-item :label="$t('Note')">
            <a-input v-model:value="form.note" :maxlength="255" />
          </a-form-item>
        </a-col>
      </a-row>

      <a-form-item :label="$t('LicK_Paste_Keys')">
        <a-textarea
          v-model:value="form.keys"
          :rows="7"
          class="mono"
          :placeholder="'XXXXX-XXXXX-XXXXX-XXXXX\nYYYYY-YYYYY-YYYYY-YYYYY'"
        />
        <div class="muted small" style="margin-top: 4px">{{ $t('LicK_Lines_Count', { n: lineCount }) }}</div>
      </a-form-item>

      <a-form-item :label="$t('LicK_Or_File')" :extra="$t('LicK_File_Help')">
        <div class="file-row">
          <a-upload
            :file-list="file ? [file] : []"
            :max-count="1"
            accept=".csv,.txt,.xlsx,.xls"
            :before-upload="pickFile"
            @remove="file = null"
          >
            <a-button>
              <template #icon><UploadOutlined /></template>
              {{ $t('Choose_file') }}
            </a-button>
          </a-upload>
          <a @click="downloadTemplate"><DownloadOutlined /> {{ $t('LicK_Download_Template') }}</a>
        </div>
      </a-form-item>
      <a-alert type="info" show-icon :message="$t('LicK_Import_Stock_Hint')" />
    </a-form>

    <!-- ============================ step 2: preview ============================ -->
    <div v-else-if="step === 2 && preview" style="margin-top: 8px">
      <div class="prev-tiles">
        <div class="prev-tile" style="--c: #52c41a">
          <div class="prev-num">{{ preview.new }}</div>
          <div class="prev-label">{{ $t('LicK_Prev_New') }}</div>
        </div>
        <div class="prev-tile" style="--c: #faad14">
          <div class="prev-num">{{ preview.duplicates_in_file.length }}</div>
          <div class="prev-label">{{ $t('LicK_Prev_Dupes') }}</div>
        </div>
        <div class="prev-tile" style="--c: #ff4d4f">
          <div class="prev-num">{{ preview.existing.length }}</div>
          <div class="prev-label">{{ $t('LicK_Prev_Existing') }}</div>
        </div>
        <div class="prev-tile" style="--c: #8c8c8c">
          <div class="prev-num">{{ preview.invalid.length }}</div>
          <div class="prev-label">{{ $t('LicK_Prev_Invalid') }}</div>
        </div>
      </div>

      <a-alert
        v-if="!preview.new"
        type="warning" show-icon style="margin-bottom: 12px"
        :message="$t('LicK_Nothing_New')"
      />
      <p v-else class="muted">{{ $t('LicK_Prev_Skip_Hint') }}</p>

      <a-collapse :bordered="false" class="prev-collapse">
        <a-collapse-panel v-if="preview.new_sample.length" key="new" :header="`${$t('LicK_Prev_New')} (${preview.new})`">
          <a-table size="small" :pagination="false" row-key="row" :columns="sampleColumns" :data-source="preview.new_sample" :scroll="{ y: 240 }" />
        </a-collapse-panel>
        <a-collapse-panel v-if="preview.duplicates_in_file.length" key="dupes" :header="`${$t('LicK_Prev_Dupes')} (${preview.duplicates_in_file.length})`">
          <a-table size="small" :pagination="false" row-key="row" :columns="dupeColumns" :data-source="preview.duplicates_in_file" :scroll="{ y: 240 }" />
        </a-collapse-panel>
        <a-collapse-panel v-if="preview.existing.length" key="existing" :header="`${$t('LicK_Prev_Existing')} (${preview.existing.length})`">
          <a-table size="small" :pagination="false" row-key="row" :columns="existingColumns" :data-source="preview.existing" :scroll="{ y: 240 }">
            <template #bodyCell="{ column, record }">
              <template v-if="column.key === 'status'">
                <a-tag :color="licenseStatusColor(record.status)">{{ licenseStatusLabel(record.status) }}</a-tag>
              </template>
            </template>
          </a-table>
        </a-collapse-panel>
        <a-collapse-panel v-if="preview.invalid.length" key="invalid" :header="`${$t('LicK_Prev_Invalid')} (${preview.invalid.length})`">
          <a-table size="small" :pagination="false" row-key="row" :columns="invalidColumns" :data-source="preview.invalid" :scroll="{ y: 240 }" />
        </a-collapse-panel>
      </a-collapse>
    </div>

    <template #footer>
      <a-button @click="emit('update:open', false)">{{ $t('Delete_cancelButtonText') }}</a-button>
      <template v-if="step === 1">
        <a-button type="primary" :loading="busy" @click="runPreview">
          <template #icon><EyeOutlined /></template>
          {{ $t('Preview') }}
        </a-button>
      </template>
      <template v-else>
        <a-button :disabled="busy" @click="step = 1">
          <template #icon><ArrowLeftOutlined /></template>
          {{ $t('back') }}
        </a-button>
        <a-button type="primary" :loading="busy" :disabled="!preview || !preview.new" @click="runImport">
          <template #icon><CloudUploadOutlined /></template>
          {{ $t('LicK_Import_N', { n: preview?.new || 0 }) }}
        </a-button>
      </template>
    </template>
  </a-modal>
</template>

<script setup>
/**
 * Products › License Keys › Add keys: bulk-load bought keys into stock for a
 * product whose license_mode is `pool`.
 *   1. POST license-keys/preview (keys text and/or file) → new / duplicates /
 *      existing / invalid, nothing stored;
 *   2. POST license-keys/import (same input + product, edition, warehouse,
 *      default cost, note) → { added, duplicates_in_file, existing, invalid, batch }.
 * Each added key is one unit of stock (the server posts the adjustment).
 */
import { ref, computed, watch } from 'vue';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import {
  UploadOutlined, DownloadOutlined, EyeOutlined, ArrowLeftOutlined, CloudUploadOutlined,
} from '@ant-design/icons-vue';
import http from '../../lib/http';
import { licenseError, licenseStatusColor, licenseStatusLabel } from '../../composables/useLicenseKeys';

const props = defineProps({
  open: { type: Boolean, default: false },
  products: { type: Array, default: () => [] },
  warehouses: { type: Array, default: () => [] },
  // { product_id, variant_id } to start from (Overview row action).
  preset: { type: Object, default: null },
});
const emit = defineEmits(['update:open', 'done']);
const { t } = useI18n();

const step = ref(1);
const busy = ref(false);
const preview = ref(null);
const file = ref(null);
const form = ref(blankForm());

function blankForm() {
  return {
    product_id: undefined,
    product_variant_id: undefined,
    warehouse_id: undefined,
    cost: null,
    note: '',
    keys: '',
  };
}

const poolProducts = computed(() => props.products.filter(p => p.mode === 'pool'));
const productOptions = computed(() => poolProducts.value.map(p => ({
  value: p.id,
  label: p.code ? `${p.name} (${p.code})` : p.name,
})));
const currentProduct = computed(() => poolProducts.value.find(p => p.id === form.value.product_id) || null);
const variantOptions = computed(() => (currentProduct.value?.variants || []).map(v => ({ value: v.id, label: v.name })));
const warehouseOptions = computed(() => props.warehouses.map(w => ({ value: w.id, label: w.name })));
const lineCount = computed(() => form.value.keys.split(/\r\n|\r|\n/).filter(l => l.trim() !== '').length);

watch(() => props.open, (o) => {
  if (!o) return;
  step.value = 1;
  preview.value = null;
  file.value = null;
  form.value = blankForm();
  const pre = props.preset;
  if (pre?.product_id && poolProducts.value.some(p => p.id === pre.product_id)) {
    form.value.product_id = pre.product_id;
    form.value.product_variant_id = pre.variant_id || undefined;
  } else if (poolProducts.value.length === 1) {
    form.value.product_id = poolProducts.value[0].id;
  }
  if (props.warehouses.length === 1) form.value.warehouse_id = props.warehouses[0].id;
}, { immediate: true });

function onProductChange() {
  form.value.product_variant_id = undefined;
}

function pickFile(f) {
  file.value = f;
  preview.value = null;
  return false; // keep it local; sent with preview / import
}

function downloadTemplate() {
  const csv = 'license_key,cost,note\r\nAAAAA-BBBBB-CCCCC-DDDDD,12.50,Reseller batch 1\r\nEEEEE-FFFFF-GGGGG-HHHHH,12.50,\r\n';
  const blob = new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8' });
  const href = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = href;
  a.download = 'license-keys-template.csv';
  document.body.appendChild(a);
  a.click();
  document.body.removeChild(a);
  URL.revokeObjectURL(href);
}

function hasInput() {
  return lineCount.value > 0 || !!file.value;
}

function payload(withMeta) {
  const fd = new FormData();
  if (form.value.keys.trim()) fd.append('keys', form.value.keys);
  if (file.value) fd.append('file', file.value);
  if (withMeta) {
    fd.append('product_id', form.value.product_id);
    if (form.value.product_variant_id) fd.append('product_variant_id', form.value.product_variant_id);
    fd.append('warehouse_id', form.value.warehouse_id);
    if (form.value.cost !== null && form.value.cost !== undefined && form.value.cost !== '') fd.append('cost', form.value.cost);
    if (form.value.note) fd.append('note', form.value.note);
  }
  return fd;
}

function validateStep1() {
  if (!form.value.product_id) return t('LicK_Choose_Product_First');
  if (variantOptions.value.length && !form.value.product_variant_id) return t('LicK_Edition_Required');
  if (!form.value.warehouse_id) return t('Choose_Warehouse');
  if (!hasInput()) return t('LicK_Nothing_To_Import');
  return null;
}

async function runPreview() {
  const err = validateStep1();
  if (err) {
    message.warning(err);
    return;
  }
  busy.value = true;
  try {
    const data = await http.postForm('license-keys/preview', payload(false));
    preview.value = {
      new: Number(data?.new) || 0,
      new_sample: data?.new_sample || [],
      duplicates_in_file: data?.duplicates_in_file || [],
      existing: data?.existing || [],
      invalid: data?.invalid || [],
    };
    step.value = 2;
  } catch (e) {
    message.error(licenseError(e));
  } finally {
    busy.value = false;
  }
}

async function runImport() {
  busy.value = true;
  try {
    const r = await http.postForm('license-keys/import', payload(true));
    message.success({
      content: t('LicK_Imported_Msg', {
        added: Number(r?.added) || 0,
        dupes: Number(r?.duplicates_in_file) || 0,
        existing: Number(r?.existing) || 0,
        invalid: Number(r?.invalid) || 0,
      }),
      duration: 6,
    });
    // Sales that were waiting for this product's keys got them straight away.
    if (Number(r?.backorders_filled) > 0) {
      message.info({ content: t('LicK_Backorders_Filled_Msg', { n: Number(r.backorders_filled) }), duration: 6 });
    }
    emit('done', { batch: r?.batch || null, product_id: form.value.product_id });
    emit('update:open', false);
  } catch (e) {
    message.error(licenseError(e));
    // Another import may have stored some keys meanwhile: go back to re-check.
    if (e?.status === 422) step.value = 1;
  } finally {
    busy.value = false;
  }
}

const sampleColumns = computed(() => [
  { title: t('LicK_Row'), dataIndex: 'row', key: 'row', width: 70 },
  { title: t('LicK_Key'), dataIndex: 'key', key: 'key' },
  { title: t('Cost'), dataIndex: 'cost', key: 'cost' },
  { title: t('Note'), dataIndex: 'note', key: 'note' },
]);
const dupeColumns = computed(() => [
  { title: t('LicK_Row'), dataIndex: 'row', key: 'row', width: 70 },
  { title: t('LicK_Key'), dataIndex: 'key', key: 'key' },
  { title: t('LicK_First_Row'), dataIndex: 'first_row', key: 'first_row' },
]);
const existingColumns = computed(() => [
  { title: t('LicK_Row'), dataIndex: 'row', key: 'row', width: 70 },
  { title: t('LicK_Key'), dataIndex: 'key', key: 'key' },
  { title: t('Product'), dataIndex: 'product', key: 'product' },
  { title: t('Status'), dataIndex: 'status', key: 'status' },
]);
const invalidColumns = computed(() => [
  { title: t('LicK_Row'), dataIndex: 'row', key: 'row', width: 70 },
  { title: t('LicK_Key'), dataIndex: 'key', key: 'key' },
]);
</script>

<style scoped>
.mono { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; }
.muted { color: rgba(var(--ink-rgb), 0.5); }
.small { font-size: 12px; }
.file-row { display: flex; align-items: center; gap: 16px; flex-wrap: wrap; }
.prev-tiles {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 12px;
  margin-bottom: 12px;
}
.prev-tile {
  border: 1px solid var(--line);
  border-inline-start: 4px solid var(--c);
  border-radius: 8px;
  padding: 10px 12px;
}
.prev-num { font-size: 22px; font-weight: 700; color: var(--c); }
.prev-label { font-size: 12px; opacity: 0.7; }
.prev-collapse { background: transparent; }
@media (max-width: 575px) {
  .prev-tiles { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
</style>
