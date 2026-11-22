<template>
  <div class="page">
    <PageHeader :title="$t('CountStock')" :breadcrumb="[$t('Products'), $t('CountStock')]">
      <template #actions>
        <a-space wrap>
          <a-button v-if="auth.can('adjustment_add')" @click="openUpload">
            <template #icon><UploadOutlined /></template>
            {{ $t('Count_Upload') }}
          </a-button>
          <a-button type="primary" @click="openCreate">
            <template #icon><PlusOutlined /></template>
            {{ $t('Count') }}
          </a-button>
        </a-space>
      </template>
    </PageHeader>

    <DataTable :crud="crud" :columns="columns">
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'date'">{{ date(record.date) }}</template>
        <template v-else-if="column.key === 'file_stock'">
          <a href="#" @click.prevent="downloadSheet(record)">
            <DownloadOutlined /> {{ $t('Download') }}
          </a>
        </template>
      </template>
    </DataTable>

    <a-modal
      v-model:open="modalOpen"
      :title="$t('Count')"
      :confirm-loading="saving"
      :ok-text="$t('submit')"
      @ok="save"
    >
      <a-form ref="formRef" :model="form" :rules="rules" layout="vertical">
        <a-form-item :label="$t('date')" name="date">
          <a-date-picker v-model:value="form.date" value-format="YYYY-MM-DD" style="width: 100%" />
        </a-form-item>
        <a-form-item :label="$t('warehouse')" name="warehouse_id">
          <a-select
            v-model:value="form.warehouse_id" show-search option-filter-prop="label"
            :placeholder="$t('Choose_Warehouse')" :options="warehouseOptions"
          />
        </a-form-item>
        <a-form-item :label="$t('Categorie')" name="category_id">
          <a-select
            v-model:value="form.category_id" allow-clear show-search option-filter-prop="label"
            :placeholder="$t('Choose_Category')" :options="categoryOptions"
          />
        </a-form-item>
      </a-form>
    </a-modal>

    <!-- Upload a physical count: Code + Counted → one "Stock count" adjustment. -->
    <a-modal
      v-model:open="uploadOpen"
      :title="$t('Count_Upload')"
      :width="760"
      :mask-closable="!uploading"
      :footer="null"
    >
      <a-alert type="info" show-icon style="margin-bottom: 16px" :message="$t('Count_Upload_Help')" />
      <a-form layout="vertical">
        <a-row :gutter="12">
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('warehouse')" required>
              <a-select
                v-model:value="upload.warehouse_id" show-search option-filter-prop="label"
                :placeholder="$t('Choose_Warehouse')" :options="warehouseOptions" :disabled="uploading"
                @change="resetResult"
              />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Reference')">
              <a-input v-model:value="upload.reference" :maxlength="100" :disabled="uploading" />
            </a-form-item>
          </a-col>
        </a-row>
        <ImportDropzone
          :file="upload.file"
          accept=".xlsx,.xls,.csv"
          :title="$t('Imp_Drop_File')"
          :hint="$t('Count_Upload_File_Hint')"
          :disabled="uploading"
          @file="f => { upload.file = f; resetResult(); }"
          @clear="upload.file = null; resetResult();"
        />
      </a-form>

      <a-alert v-if="uploadErrors.length" type="error" show-icon style="margin-top: 16px" :message="$t('Import_Failed_Fix_Below')">
        <template #description>
          <ul style="margin: 0; padding-left: 18px; max-height: 220px; overflow-y: auto">
            <li v-for="(err, i) in uploadErrors" :key="i">{{ err }}</li>
          </ul>
        </template>
      </a-alert>

      <template v-if="result">
        <a-alert
          :type="result.preview ? 'warning' : 'success'" show-icon style="margin-top: 16px"
          :message="result.preview
            ? $t('Count_Upload_Preview_Summary', { counted: result.counted, changed: result.changed, added: result.units_added, removed: result.units_removed })
            : (result.Ref
              ? $t('Count_Upload_Done', { ref: result.Ref, changed: result.changed, added: result.units_added, removed: result.units_removed })
              : $t('Count_Upload_No_Difference', { counted: result.counted }))"
        />
        <a-table
          v-if="result.lines.length"
          :columns="diffColumns" :data-source="result.lines" row-key="code"
          size="small" :pagination="{ pageSize: 10, size: 'small' }" style="margin-top: 12px"
        >
          <template #bodyCell="{ column, record }">
            <template v-if="column.key === 'difference'">
              <span :style="{ color: record.difference > 0 ? '#389e0d' : '#cf1322', fontWeight: 600 }">
                {{ record.difference > 0 ? '+' : '−' }}{{ Math.abs(record.difference) }}
              </span>
            </template>
          </template>
        </a-table>
      </template>

      <div style="display: flex; justify-content: flex-end; gap: 8px; margin-top: 16px">
        <a-button :disabled="uploading" @click="uploadOpen = false">{{ $t('Close') }}</a-button>
        <a-button :disabled="!canUpload" :loading="uploading && step === 'preview'" @click="sendCount(true)">
          {{ $t('Count_Upload_Check') }}
        </a-button>
        <a-button
          type="primary" :disabled="!canUpload || !result || !result.preview || !result.changed"
          :loading="uploading && step === 'apply'" @click="sendCount(false)"
        >
          {{ $t('Count_Upload_Apply') }}
        </a-button>
      </div>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * GET count_stock → {stocks, warehouses, categories, totalRows}. Generate a
 * count sheet: POST store_count_stock {date, warehouse_id, category_id}. The
 * sheet is stored off the public path and fetched over XHR from
 * count_stock/{id}/download, which checks the permission and the warehouse.
 */
import { ref, computed } from 'vue';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { PlusOutlined, DownloadOutlined, UploadOutlined } from '@ant-design/icons-vue';
import dayjs from 'dayjs';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import ImportDropzone from '../../components/ImportDropzone.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import { useAuthStore } from '../../stores/auth';
import { uploadForm } from '../../lib/upload';
import http from '../../lib/http';

const { t } = useI18n();
const { date } = useFormat();
const auth = useAuthStore();

const crud = useCrudTable('count_stock', { rowsKey: 'stocks' });
crud.fetchRows();

const warehouseOptions = computed(() =>
  (crud.payload.value?.warehouses || []).map(w => ({ value: w.id, label: w.name }))
);
const categoryOptions = computed(() =>
  (crud.payload.value?.categories || []).map(c => ({ value: c.id, label: c.name }))
);

const columns = computed(() => [
  { title: t('date'), dataIndex: 'date', key: 'date', sorter: true },
  { title: t('warehouse'), dataIndex: 'warehouse_name', key: 'warehouse_name' },
  { title: t('Categorie'), dataIndex: 'category_name', key: 'category_name' },
  { title: t('file'), dataIndex: 'file_stock', key: 'file_stock' },
]);

const modalOpen = ref(false);
const saving = ref(false);
const formRef = ref();
const form = ref({ date: dayjs().format('YYYY-MM-DD'), warehouse_id: undefined, category_id: undefined });

const rules = computed(() => ({
  date: [{ required: true, message: t('Field_is_required') }],
  warehouse_id: [{ required: true, message: t('Field_is_required') }],
}));

function openCreate() {
  form.value = { date: dayjs().format('YYYY-MM-DD'), warehouse_id: undefined, category_id: undefined };
  modalOpen.value = true;
}

async function downloadSheet(record) {
  try {
    await http.download(`count_stock/${record.id}/download`, record.file_stock);
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  }
}

/* ------------------------------------------------ upload a count
 * POST count_stock/upload {warehouse_id, reference, file, preview}. "Check"
 * previews the differences; "Apply" posts them, recomputed against stock at
 * that moment server-side, as one "Stock count" adjustment. */
const uploadOpen = ref(false);
const uploading = ref(false);
const step = ref(null);
const upload = ref({ warehouse_id: undefined, reference: '', file: null });
const uploadErrors = ref([]);
const result = ref(null);

const canUpload = computed(() => !!upload.value.warehouse_id && !!upload.value.file && !uploading.value);

const diffColumns = computed(() => [
  { title: t('Code'), dataIndex: 'code', key: 'code' },
  { title: t('ProductName'), dataIndex: 'name', key: 'name' },
  { title: t('Count_Upload_Current'), dataIndex: 'current', key: 'current', align: 'right' },
  { title: t('Count_Upload_Counted'), dataIndex: 'counted', key: 'counted', align: 'right' },
  { title: t('Count_Upload_Difference'), dataIndex: 'difference', key: 'difference', align: 'right' },
]);

function openUpload() {
  upload.value = { warehouse_id: undefined, reference: '', file: null };
  resetResult();
  uploadOpen.value = true;
}

function resetResult() {
  result.value = null;
  uploadErrors.value = [];
}

function errorList(data) {
  if (Array.isArray(data?.errors)) return data.errors;
  if (data?.errors && typeof data.errors === 'object') return Object.values(data.errors).flat();
  return [data?.message || t('InvalidData')];
}

async function sendCount(preview) {
  uploading.value = true;
  step.value = preview ? 'preview' : 'apply';
  uploadErrors.value = [];
  try {
    const fd = new FormData();
    fd.append('warehouse_id', upload.value.warehouse_id);
    fd.append('reference', upload.value.reference || '');
    fd.append('file', upload.value.file);
    fd.append('preview', preview ? '1' : '0');
    const { status, data } = await uploadForm('count_stock/upload', fd);
    if (status !== 200 || !data?.status) {
      result.value = null;
      uploadErrors.value = errorList(data);
      return;
    }
    result.value = { ...data, lines: data.lines || [] };
    if (!preview) message.success(t('Successfully_Created'));
  } catch (e) {
    uploadErrors.value = [e?.message || t('InvalidData')];
  } finally {
    uploading.value = false;
    step.value = null;
  }
}

async function save() {
  try {
    await formRef.value.validate();
  } catch (e) {
    return;
  }
  saving.value = true;
  try {
    await http.post('store_count_stock', {
      date: form.value.date,
      warehouse_id: form.value.warehouse_id,
      category_id: form.value.category_id || null,
    });
    message.success(t('Successfully_Generated_Count'));
    modalOpen.value = false;
    crud.fetchRows();
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    saving.value = false;
  }
}
</script>
