<template>
  <div class="page">
    <PageHeader :title="$t('Size_Guides')" :breadcrumb="[$t('Products'), $t('Size_Guides')]">
      <template #actions>
        <a-button type="primary" @click="openCreate">
          <template #icon><PlusOutlined /></template>
          {{ $t('Add') }}
        </a-button>
      </template>
    </PageHeader>

    <a-alert type="info" show-icon style="margin-bottom: 16px" :message="$t('Size_Guides_Hint')" />

    <DataTable :crud="crud" :columns="columns" selectable>
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'image'">
          <a-image
            v-if="record.image_url"
            :src="record.image_url"
            :width="44"
            :height="44"
            style="object-fit: cover; border-radius: 8px"
          />
          <span v-else style="opacity: 0.45">—</span>
        </template>
        <template v-else-if="column.key === 'chart'">
          <span v-if="record.columns && record.columns.length">{{ record.columns.join(' · ') }}</span>
          <span v-else style="opacity: 0.45">—</span>
        </template>
        <template v-else-if="column.key === 'status'">
          <a-tag :color="record.status ? 'success' : 'default'">
            {{ record.status ? $t('Active') : $t('Inactive') }}
          </a-tag>
        </template>
        <template v-else-if="column.key === 'actions'">
          <a-space>
            <a-tooltip :title="$t('Edit')">
              <a-button type="text" size="small" @click="openEdit(record)">
                <template #icon><EditOutlined style="color: #52c41a" /></template>
              </a-button>
            </a-tooltip>
            <a-tooltip :title="$t('Del')">
              <a-button type="text" size="small" danger @click="crud.remove(record)">
                <template #icon><DeleteOutlined /></template>
              </a-button>
            </a-tooltip>
          </a-space>
        </template>
      </template>
    </DataTable>

    <a-modal
      v-model:open="modalOpen"
      :title="editMode ? $t('Edit') : $t('Add')"
      :confirm-loading="submitting"
      :ok-text="$t('submit')"
      :cancel-text="$t('Delete_cancelButtonText')"
      width="860px"
      @ok="submit"
    >
      <a-form ref="formRef" :model="form" :rules="rules" layout="vertical" style="margin-top: 8px">
        <a-row :gutter="16">
          <a-col :xs="24" :md="16">
            <a-form-item :label="$t('Name')" name="name">
              <a-input v-model:value="form.name" :maxlength="100" :placeholder="$t('Size_Guide_Name_Placeholder')" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="8">
            <a-form-item :label="$t('Status')">
              <a-switch v-model:checked="form.status" />
              <span style="margin-left: 8px">{{ form.status ? $t('Active') : $t('Inactive') }}</span>
            </a-form-item>
          </a-col>
        </a-row>

        <a-form-item :label="$t('Image')" :extra="$t('Size_Guide_Image_Hint')">
          <a-upload
            :file-list="fileList"
            :before-upload="beforeUpload"
            :max-count="1"
            list-type="picture"
            accept="image/*"
            @remove="() => (fileList = [])"
          >
            <a-button>
              <template #icon><UploadOutlined /></template>
              {{ $t('Image') }} (max {{ MAX_IMAGE_MB }} MB)
            </a-button>
          </a-upload>
          <div v-if="editMode && currentImageUrl && !removeImage && !fileList.length" class="sg-current">
            <img :src="currentImageUrl" :alt="form.name" class="sg-current-img" />
            <a-button size="small" danger @click="removeImage = true">{{ $t('Remove_Image') }}</a-button>
          </div>
        </a-form-item>

        <a-divider style="margin: 12px 0" />
        <div class="matrix-head">
          <span>{{ $t('Columns') }}</span>
          <a-space wrap>
            <a-dropdown :trigger="['click']">
              <a-button size="small">{{ $t('Size_Guide_Template') }} <DownOutlined /></a-button>
              <template #overlay>
                <a-menu @click="({ key }) => applyTemplate(key)">
                  <a-menu-item v-for="tpl in TEMPLATES" :key="tpl.key">{{ $t(tpl.label) }}</a-menu-item>
                </a-menu>
              </template>
            </a-dropdown>
            <a-button size="small" @click="addColumn">
              <template #icon><PlusOutlined /></template>
              {{ $t('Add_Column') }}
            </a-button>
          </a-space>
        </div>
        <div v-if="form.columns.length" class="matrix-cols">
          <div v-for="(col, ci) in form.columns" :key="'c' + ci" class="matrix-col">
            <a-input v-model:value="form.columns[ci]" size="small" :placeholder="$t('Column_Header')" style="width: 130px" />
            <a-button type="text" size="small" danger :aria-label="$t('Del')" @click="removeColumn(ci)">
              <template #icon><CloseOutlined /></template>
            </a-button>
          </div>
        </div>
        <a-typography-text v-else type="secondary" style="font-size: 12px">
          {{ $t('Size_Guide_Columns_Hint') }}
        </a-typography-text>

        <!-- Rows editor: one cell per column, kept rectangular with the columns above -->
        <template v-if="form.columns.length">
          <div class="matrix-head" style="margin-top: 16px">
            <span>{{ $t('Size_Guide_Rows') }}</span>
            <a-button size="small" @click="addRow">
              <template #icon><PlusOutlined /></template>
              {{ $t('Add_Row') }}
            </a-button>
          </div>
          <div class="matrix-table-wrap">
            <table class="matrix-table">
              <thead>
                <tr>
                  <th v-for="(col, ci) in form.columns" :key="'h' + ci">{{ col || '#' + (ci + 1) }}</th>
                  <th style="width: 40px"></th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(row, ri) in form.rows" :key="'r' + ri">
                  <td v-for="(col, ci) in form.columns" :key="'rc' + ri + '_' + ci">
                    <a-input v-model:value="form.rows[ri][ci]" size="small" />
                  </td>
                  <td style="text-align: center">
                    <a-button type="text" size="small" danger :aria-label="$t('Del')" @click="removeRow(ri)">
                      <template #icon><CloseOutlined /></template>
                    </a-button>
                  </td>
                </tr>
                <tr v-if="!form.rows.length">
                  <td :colspan="form.columns.length + 1" style="text-align: center; opacity: 0.45">
                    {{ $t('Size_Guide_No_Rows') }}
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </template>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * Size guides (size charts) — ported from stocky. A guide is a typed table
 * (columns + rows) and/or a chart image; products pick one on the product
 * form and the storefront product page shows it in a "Size guide" dialog.
 */
import { ref, computed, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import {
  PlusOutlined, EditOutlined, DeleteOutlined, UploadOutlined, CloseOutlined, DownOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import http from '../../lib/http';

const { t } = useI18n();

/** Kept in step with the `image` rule in SizeGuideController (max:2048). */
const MAX_IMAGE_MB = 2;

const crud = useCrudTable('size_guides', { rowsKey: 'size_guides' });

const columns = computed(() => [
  { title: t('Image'), key: 'image', width: 80 },
  { title: t('Name'), dataIndex: 'name', key: 'name', sorter: true },
  { title: t('Columns'), key: 'chart' },
  { title: t('Size_Guide_Rows'), dataIndex: 'rows_count', key: 'rows_count', width: 90 },
  { title: t('Products'), dataIndex: 'products_count', key: 'products_count', width: 100 },
  { title: t('Status'), key: 'status', width: 110 },
  { title: t('Action'), key: 'actions', width: 110, align: 'center' },
]);

/* Starting points for the commonest charts (body measurements in cm). */
const TEMPLATES = [
  {
    key: 'women', label: 'Size_Guide_Tpl_Women',
    columns: ['Size', 'UK', 'EU', 'US', 'Bust (cm)', 'Waist (cm)', 'Hips (cm)'],
    rows: [
      ['XS', '6', '34', '2', '80', '62', '88'],
      ['S', '8', '36', '4', '84', '66', '92'],
      ['M', '10', '38', '6', '88', '70', '96'],
      ['L', '12', '40', '8', '94', '76', '102'],
      ['XL', '14', '42', '10', '100', '82', '108'],
    ],
  },
  {
    key: 'men', label: 'Size_Guide_Tpl_Men',
    columns: ['Size', 'Chest (cm)', 'Waist (cm)', 'Neck (cm)', 'Sleeve (cm)'],
    rows: [
      ['S', '88-96', '74-82', '37-38', '84'],
      ['M', '96-104', '82-90', '39-40', '86'],
      ['L', '104-112', '90-98', '41-42', '88'],
      ['XL', '112-120', '98-106', '43-44', '90'],
      ['XXL', '120-128', '106-114', '45-46', '92'],
    ],
  },
  {
    key: 'shoes', label: 'Size_Guide_Tpl_Shoes',
    columns: ['EU', 'UK', 'US women', 'US men', 'Foot length (cm)'],
    rows: [
      ['37', '4', '6.5', '5', '23.5'],
      ['38', '5', '7.5', '6', '24.1'],
      ['39', '6', '8.5', '7', '24.8'],
      ['40', '6.5', '9', '7.5', '25.4'],
      ['41', '7.5', '10', '8.5', '26.0'],
      ['42', '8', '10.5', '9', '26.7'],
      ['43', '9', '11.5', '10', '27.3'],
      ['44', '10', '12.5', '11', '27.9'],
    ],
  },
];

/* ---------------------------------------------------------- create / edit */
const modalOpen = ref(false);
const editMode = ref(false);
const submitting = ref(false);
const formRef = ref();
const fileList = ref([]);
const currentImageUrl = ref('');
const removeImage = ref(false);
const emptyForm = () => ({ id: null, name: '', status: true, columns: [], rows: [] });
const form = ref(emptyForm());

const rules = computed(() => ({
  name: [
    { required: true, message: t('Field_is_required') },
    { min: 2, max: 100, message: '2 – 100' },
  ],
}));

/* ------------------------------------------------------- matrix editing */
// Columns and rows stay rectangular: every row carries one cell per column.
function addColumn() {
  form.value.columns.push('');
  form.value.rows.forEach(r => r.push(''));
}
function removeColumn(ci) {
  form.value.columns.splice(ci, 1);
  form.value.rows.forEach(r => r.splice(ci, 1));
}
function addRow() {
  form.value.rows.push(form.value.columns.map(() => ''));
}
function removeRow(ri) {
  form.value.rows.splice(ri, 1);
}
function applyTemplate(key) {
  const tpl = TEMPLATES.find(x => x.key === key);
  if (!tpl) return;
  form.value.columns = [...tpl.columns];
  form.value.rows = tpl.rows.map(r => [...r]);
  if (!form.value.name) form.value.name = t(tpl.label);
}

function beforeUpload(file) {
  if (file.size / 1024 / 1024 > MAX_IMAGE_MB) {
    message.error(t('InvalidData'));
    return false;
  }
  fileList.value = [file];
  return false; // uploaded with the form, not immediately
}

function openCreate() {
  editMode.value = false;
  form.value = emptyForm();
  fileList.value = [];
  currentImageUrl.value = '';
  removeImage.value = false;
  modalOpen.value = true;
}

function openEdit(record) {
  editMode.value = true;
  // The list response already carries columns/rows, so no refetch is needed.
  form.value = {
    id: record.id,
    name: record.name || '',
    status: !!record.status,
    columns: Array.isArray(record.columns) ? [...record.columns] : [],
    rows: Array.isArray(record.rows) ? record.rows.map(r => (Array.isArray(r) ? [...r] : [])) : [],
  };
  fileList.value = [];
  currentImageUrl.value = record.image_url || '';
  removeImage.value = false;
  modalOpen.value = true;
}

async function submit() {
  try {
    await formRef.value.validate();
  } catch (e) {
    return;
  }
  submitting.value = true;
  try {
    const fd = new FormData();
    fd.append('name', form.value.name);
    fd.append('status', form.value.status ? 1 : 0);
    fd.append('columns', JSON.stringify(form.value.columns || []));
    fd.append('rows', JSON.stringify(form.value.rows || []));
    // Only send a real File — omitting it keeps the current image server-side.
    if (fileList.value.length) fd.append('image', fileList.value[0]);

    if (editMode.value) {
      if (removeImage.value) fd.append('remove_image', 1);
      fd.append('_method', 'put'); // legacy API expects POST + _method for updates
      await http.postForm(`size_guides/${form.value.id}`, fd);
      message.success(t('Successfully_Updated'));
    } else {
      await http.postForm('size_guides', fd);
      message.success(t('Successfully_Created'));
    }
    modalOpen.value = false;
    crud.fetchRows();
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    submitting.value = false;
  }
}

onMounted(crud.fetchRows);
</script>

<style scoped>
.sg-current {
  display: flex;
  align-items: center;
  gap: 10px;
  margin: 8px 0 4px;
}
.sg-current-img {
  max-width: 96px;
  max-height: 96px;
  object-fit: contain;
  border: 1px solid rgba(5, 5, 5, 0.12);
  border-radius: 6px;
  background: #fafafa;
}
.matrix-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  margin-bottom: 8px;
  font-size: 13px;
}
.matrix-cols {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}
.matrix-col {
  display: flex;
  align-items: center;
  gap: 2px;
}
.matrix-table-wrap {
  overflow-x: auto;
}
.matrix-table {
  width: 100%;
  border-collapse: collapse;
}
.matrix-table th,
.matrix-table td {
  border: 1px solid rgba(128, 128, 128, 0.25);
  padding: 4px;
  font-size: 12px;
  font-weight: 500;
}
</style>
