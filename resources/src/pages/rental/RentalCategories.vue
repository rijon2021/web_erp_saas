<template>
  <div class="page">
    <PageHeader :title="$t('Rental_Categories')" :breadcrumb="[$t('Rental_Management'), $t('Rental_Categories')]">
      <template #actions>
        <a-dropdown v-if="suggestTypes.length > 1" :trigger="['click']">
          <a-button :loading="suggesting"><template #icon><BulbOutlined /></template>{{ $t('Rental_Add_Suggested') }}</a-button>
          <template #overlay>
            <a-menu @click="({ key }) => addSuggested(key)">
              <a-menu-item v-for="o in suggestTypes" :key="o.value">{{ o.label }}</a-menu-item>
            </a-menu>
          </template>
        </a-dropdown>
        <a-button v-else-if="suggestTypes.length === 1" :loading="suggesting" @click="addSuggested(suggestTypes[0].value)">
          <template #icon><BulbOutlined /></template>{{ $t('Rental_Add_Suggested') }}
        </a-button>
        <a-button type="primary" @click="openModal()">
          <template #icon><PlusOutlined /></template>
          {{ $t('Add_Category') }}
        </a-button>
      </template>
    </PageHeader>

    <!-- One tab per business type the tenant runs (a category without a type shows under each). -->
    <div v-if="typeOptions.length > 1" class="type-tabs" role="tablist">
      <button type="button" class="type-tab" :class="{ active: !typeFilter }" role="tab" :aria-selected="!typeFilter" @click="setType(undefined)">
        {{ $t('All') }}
      </button>
      <button
        v-for="o in typeOptions" :key="o.value" type="button" class="type-tab"
        :class="{ active: typeFilter === o.value }" role="tab" :aria-selected="typeFilter === o.value" @click="setType(o.value)"
      >
        <component :is="o.icon" :size="15" :stroke-width="1.8" />
        {{ o.label }}
      </button>
    </div>

    <DataTable :crud="crud" :columns="columns" selectable>
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'name'">
          <span class="cat-name">
            <img v-if="record.image_url" :src="record.image_url" alt="" class="cat-photo" loading="lazy" />
            <span v-else class="cat-ic">
              <component :is="typeIcon(record.type)" :size="16" :stroke-width="1.8" />
            </span>
            <span>
              <span class="cat-title">{{ record.name }}</span>
              <span v-if="record.description" class="cat-desc">{{ record.description }}</span>
            </span>
          </span>
        </template>
        <template v-else-if="column.key === 'type'">
          <span v-if="record.type">
            {{ labelOf(RENTAL_TYPES, record.type) }}
            <a-tooltip v-if="!rentalTypes.isEnabled(record.type)" :title="$t('Rental_Type_Turned_Off')"><WarningOutlined class="warn" /></a-tooltip>
          </span>
          <span v-else class="muted">{{ $t('Rental_Any_Type') }}</span>
        </template>
        <template v-else-if="column.key === 'website'">
          <a-tag v-if="record.show_on_website !== false" color="green">{{ $t('Rental_Shown') }}</a-tag>
          <a-tag v-else>{{ $t('Rental_Hidden') }}</a-tag>
        </template>
        <template v-else-if="column.key === 'items_count'">
          <router-link v-if="record.items_count" :to="`/rental/items?category_id=${record.id}`">
            <a-tag color="cyan">{{ record.items_count }}</a-tag>
          </router-link>
          <a-tag v-else>0</a-tag>
        </template>
        <template v-else-if="column.key === 'actions'">
          <a-space :size="0">
            <a-tooltip :title="$t('Edit')">
              <a-button type="text" size="small" @click="openModal(record)">
                <template #icon><EditOutlined /></template>
              </a-button>
            </a-tooltip>
            <a-tooltip :title="$t('Delete')">
              <a-button type="text" size="small" danger @click="crud.remove(record)">
                <template #icon><DeleteOutlined /></template>
              </a-button>
            </a-tooltip>
          </a-space>
        </template>
      </template>
    </DataTable>

    <a-modal
      v-model:open="modalOpen" :title="form.id ? $t('Edit_Category') : $t('Add_Category')"
      :confirm-loading="saving" :ok-text="$t('submit')" :cancel-text="$t('Cancel')" @ok="save"
    >
      <a-form ref="formRef" :model="form" :rules="rules" layout="vertical" style="margin-top: 12px">
        <a-form-item :label="$t('Name')" name="name">
          <a-input v-model:value="form.name" :placeholder="$t('Rental_Category_Name_Placeholder')" allow-clear />
        </a-form-item>
        <a-form-item :label="$t('Rental_Default_Type')" :extra="$t('Rental_Default_Type_Help')">
          <a-select v-model:value="form.type" :options="rentalTypes.enabledOptions(form.originalType)" allow-clear :placeholder="$t('Rental_Any_Type')" />
        </a-form-item>
        <a-form-item :label="$t('Description')">
          <a-textarea v-model:value="form.description" :rows="3" />
        </a-form-item>
        <template v-if="websiteReady">
          <a-row :gutter="16">
            <a-col :span="12">
              <a-form-item :label="$t('Rental_Show_On_Website')">
                <a-switch v-model:checked="form.show_on_website" />
              </a-form-item>
            </a-col>
            <a-col :span="12">
              <a-form-item :label="$t('Rental_Sort_Order')" :extra="$t('Rental_Sort_Order_Help')">
                <a-input-number v-model:value="form.sort_order" :precision="0" style="width: 100%" />
              </a-form-item>
            </a-col>
          </a-row>
          <a-form-item :label="$t('Rental_Category_Photo')" :extra="$t('Rental_Category_Photo_Help')" class="no-mb">
            <div class="photo-pick">
              <img v-if="photoPreview" :src="photoPreview" alt="" class="photo-preview" />
              <a-space>
                <a-upload :file-list="[]" :before-upload="pickPhoto" accept="image/*" :max-count="1">
                  <a-button><UploadOutlined /> {{ $t('Upload') }}</a-button>
                </a-upload>
                <a-button v-if="photoPreview" danger @click="clearPhoto"><DeleteOutlined /></a-button>
              </a-space>
            </div>
          </a-form-item>
        </template>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * Rental categories — GET rental/categories → { categories, totalRows }.
 * Saves are multipart POSTs (the website photo rides along): create POSTs to
 * rental/categories, edit to rental/categories/{id}/update. Deleting a
 * category that still holds items answers 422 with the reason.
 *
 * The type list is the tenant's business types (Rental Settings), and
 * "Add suggested categories" creates that type's starter set.
 */
import { ref, computed, onMounted, nextTick } from 'vue';
import { message } from 'ant-design-vue';
import {
  PlusOutlined, EditOutlined, DeleteOutlined, BulbOutlined, UploadOutlined, WarningOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import http from '../../lib/http';
import { uploadForm } from '../../lib/upload';
import { t, i18n } from '../../i18n';
import { RENTAL_TYPES, labelOf, typeIcon } from './rentalOptions';
import { useRentalTypes } from './rentalTypes';

const rentalTypes = useRentalTypes();
const typeFilter = ref(undefined);
const crud = useCrudTable('rental/categories', { rowsKey: 'categories', params: () => ({ type: typeFilter.value || undefined }) });

const typeOptions = computed(() => rentalTypes.enabledOptions());
const websiteReady = computed(() => !!rentalTypes.state.value.website_ready);
// Types with a starter set of categories.
const suggestTypes = computed(() => typeOptions.value.filter((o) => (rentalTypes.state.value.types?.[o.value]?.categories || []).length));

function setType(type) {
  typeFilter.value = type;
  crud.reload ? crud.reload() : crud.fetchRows();
}

const columns = computed(() => [
  { title: t('Name'), dataIndex: 'name', key: 'name', sorter: true },
  { title: t('Rental_Default_Type'), dataIndex: 'type', key: 'type', sorter: true, width: 170 },
  ...(websiteReady.value ? [{ title: t('Rental_Website'), key: 'website', width: 120 }] : []),
  { title: t('Rental_Items'), key: 'items_count', width: 120, align: 'center' },
  { title: t('Action'), key: 'actions', width: 100 },
]);

const modalOpen = ref(false);
const saving = ref(false);
const suggesting = ref(false);
const formRef = ref();
const blank = () => ({ id: null, name: '', type: undefined, originalType: null, description: '', show_on_website: true, sort_order: 0 });
const form = ref(blank());
// Photo: the stored URL, a newly picked File (+ preview) or a removal.
const photo = ref({ url: null, file: null, preview: null, remove: false });
const photoPreview = computed(() => photo.value.preview || (!photo.value.remove && photo.value.url) || null);

const rules = computed(() => ({
  name: [{ required: true, message: t('Field_is_required', 'This field is required') }],
}));

function openModal(row = null) {
  form.value = row
    ? {
      id: row.id, name: row.name, type: row.type || undefined, originalType: row.type || null, description: row.description || '',
      show_on_website: row.show_on_website !== false, sort_order: Number(row.sort_order || 0),
    }
    : { ...blank(), type: typeFilter.value || (typeOptions.value.length === 1 ? typeOptions.value[0].value : undefined) };
  photo.value = { url: row?.image_url || null, file: null, preview: null, remove: false };
  modalOpen.value = true;
  nextTick(() => formRef.value?.clearValidate());
}

function pickPhoto(file) {
  if (file.size > 5 * 1024 * 1024) {
    message.error(t('Rental_Photo_Too_Large', 'Each photo must be 5 MB or smaller'));
    return false;
  }
  photo.value = { ...photo.value, file, preview: window.URL.createObjectURL(file), remove: false };
  return false;
}

function clearPhoto() {
  photo.value = { ...photo.value, file: null, preview: null, remove: true };
}

async function save() {
  try {
    await formRef.value.validate();
  } catch (e) {
    return;
  }
  saving.value = true;
  const fd = new FormData();
  fd.append('name', form.value.name);
  fd.append('type', form.value.type || '');
  fd.append('description', form.value.description || '');
  if (websiteReady.value) {
    fd.append('show_on_website', form.value.show_on_website ? '1' : '0');
    fd.append('sort_order', String(form.value.sort_order || 0));
    if (photo.value.file) fd.append('image_file', photo.value.file);
    if (photo.value.remove) fd.append('remove_image', '1');
  }
  try {
    const url = form.value.id ? `rental/categories/${form.value.id}/update` : 'rental/categories';
    const res = await uploadForm(url, fd);
    if (res.status >= 200 && res.status < 300) {
      message.success(form.value.id
        ? t('Updated_in_successfully', 'Updated successfully')
        : t('Created_in_successfully', 'Created successfully'));
      modalOpen.value = false;
      await crud.fetchRows();
      return;
    }
    const errors = res.data?.errors;
    const first = errors ? Object.values(errors)[0]?.[0] : null;
    message.error(first || res.data?.message || t('Gen_Could_Not_Save', 'Could not save'));
  } catch (e) {
    message.error(t('Gen_Could_Not_Save', 'Could not save'));
  } finally {
    saving.value = false;
  }
}

async function addSuggested(type) {
  suggesting.value = true;
  try {
    const data = await http.post('rental/categories/suggested', { type });
    const n = (data?.added || []).length;
    if (n) message.success(i18n.global.t('Rental_Suggested_Added', { n }));
    else message.info(t('Rental_Suggested_None', 'These categories already exist.'));
    await crud.fetchRows();
  } catch (e) {
    message.error(e?.data?.message || t('Gen_Could_Not_Save', 'Could not save'));
  } finally {
    suggesting.value = false;
  }
}

onMounted(crud.fetchRows);
</script>

<style scoped>
.muted {
  color: rgba(128, 128, 128, 0.7);
}
.warn {
  color: #d97706;
  margin-inline-start: 4px;
}
.type-tabs {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  margin-bottom: 12px;
}
.type-tab {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 6px 14px;
  border: 1px solid rgba(128, 128, 128, 0.25);
  border-radius: 999px;
  background: transparent;
  color: inherit;
  font: inherit;
  cursor: pointer;
}
.type-tab.active {
  border-color: var(--app-primary, #6d28d9);
  color: var(--app-primary, #6d28d9);
  background: rgba(var(--app-primary-rgb, 109, 40, 217), 0.08);
}
.cat-name {
  display: inline-flex;
  align-items: center;
  gap: 10px;
}
.cat-ic {
  width: 32px;
  height: 32px;
  flex: none;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 9px;
  color: var(--app-primary, #6d28d9);
  background: rgba(var(--app-primary-rgb, 109, 40, 217), 0.1);
}
.cat-photo {
  width: 32px;
  height: 32px;
  flex: none;
  border-radius: 9px;
  object-fit: cover;
}
.cat-title {
  display: block;
  font-weight: 500;
}
.cat-desc {
  display: block;
  font-size: 12px;
  opacity: 0.6;
  max-width: 420px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.photo-pick {
  display: flex;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
}
.photo-preview {
  width: 96px;
  height: 64px;
  border-radius: 10px;
  object-fit: cover;
}
.no-mb {
  margin-bottom: 0;
}
</style>
