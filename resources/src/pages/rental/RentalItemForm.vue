<template>
  <div class="page">
    <PageHeader
      :title="isEdit ? $t('Rental_Edit_Item') : $t('Rental_Add_Item')"
      :breadcrumb="[$t('Rental_Management'), $t('Rental_Items'), isEdit ? $t('Edit') : $t('Add')]"
    >
      <template #actions>
        <a-button @click="goBack">{{ $t('Cancel') }}</a-button>
        <a-button type="primary" :loading="saving" @click="submit">{{ $t('submit') }}</a-button>
      </template>
    </PageHeader>

    <div v-if="loading" class="loading"><a-spin size="large" /></div>

    <a-form v-else ref="formRef" :model="form" :rules="rules" layout="vertical">
      <!-- What is being rented decides which fields follow (only the tenant's business types) -->
      <div v-if="typeOptions.length > 1" class="type-picker" :style="{ '--type-cols': Math.min(typeOptions.length, 4) }" role="radiogroup" :aria-label="$t('type')">
        <button
          v-for="o in typeOptions" :key="o.value" type="button" role="radio"
          class="type-card" :class="{ active: form.type === o.value }"
          :aria-checked="form.type === o.value" @click="pickType(o.value)"
        >
          <span class="type-card-ic"><component :is="o.icon" :size="22" :stroke-width="1.7" /></span>
          <span class="type-card-text">
            <span class="type-card-title">{{ o.label }}</span>
            <span class="type-card-hint">{{ t(TYPE_HINTS[o.value].key, TYPE_HINTS[o.value].text) }}</span>
          </span>
          <CheckCircleFilled v-if="form.type === o.value" class="type-card-check" />
        </button>
      </div>

      <a-row :gutter="16">
        <a-col :xs="24" :lg="16">
          <!-- Basics -->
          <a-card size="small" :title="$t('Rental_Basic_Info')" class="block">
            <a-row :gutter="16">
              <a-col :xs="24" :md="16">
                <a-form-item :label="$t('Name')" name="name">
                  <a-input v-model:value="form.name" :placeholder="namePlaceholder" allow-clear />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="8">
                <a-form-item :label="$t('Code')" name="code" :extra="isEdit ? '' : $t('Rental_Code_Auto_Help')">
                  <a-input v-model:value="form.code" :placeholder="nextCode" allow-clear />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="8">
                <a-form-item :label="$t('Category')">
                  <a-select
                    v-model:value="form.rental_category_id" allow-clear show-search
                    option-filter-prop="label" :options="categoryOptions"
                    :placeholder="$t('Rental_No_Category')" @change="onCategoryChange"
                  />
                </a-form-item>
              </a-col>
              <a-col :xs="12" :md="8">
                <a-form-item :label="$t('Status')" name="status">
                  <a-select v-model:value="form.status" :options="statusOptions" />
                </a-form-item>
              </a-col>
              <a-col :xs="12" :md="8">
                <a-form-item :label="$t('Rental_Condition')" name="condition">
                  <a-select v-model:value="form.condition" :options="conditionOptions" />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="isProperty ? 16 : 12">
                <a-form-item :label="$t('Warehouse')">
                  <a-select
                    v-model:value="form.warehouse_id" allow-clear show-search
                    option-filter-prop="label" :options="warehouseOptions"
                    :placeholder="$t('Rental_All_Branches')"
                  />
                </a-form-item>
              </a-col>
              <a-col v-if="!isProperty" :xs="24" :md="12">
                <a-form-item :label="$t('Quantity')" name="quantity" :extra="$t('Rental_Quantity_Help')">
                  <a-input-number v-model:value="form.quantity" :min="1" :precision="0" style="width: 100%" />
                </a-form-item>
              </a-col>
            </a-row>
          </a-card>

          <!-- Pricing -->
          <a-card size="small" :title="$t('Rental_Pricing')" class="block">
            <a-form-item :label="$t('Rental_Main_Period')" :extra="$t('Rental_Main_Period_Help')">
              <a-radio-group v-model:value="form.default_period" button-style="solid" class="period-group">
                <a-radio-button v-for="p in periodOptions" :key="p.value" :value="p.value">{{ p.label }}</a-radio-button>
              </a-radio-group>
            </a-form-item>

            <div class="rates">
              <div
                v-for="p in periodOptions" :key="p.value" class="rate"
                :class="{ main: form.default_period === p.value }"
              >
                <a-form-item :name="p.rate" :label="p.label" class="rate-item">
                  <a-input-number
                    v-model:value="form[p.rate]" :min="0" :step="1" style="width: 100%"
                    :placeholder="form.default_period === p.value ? $t('Required') : $t('Rental_Not_Offered')"
                  />
                </a-form-item>
                <span v-if="form.default_period === p.value" class="rate-badge">{{ $t('Rental_Main') }}</span>
              </div>
            </div>

            <a-row :gutter="16">
              <a-col :xs="24" :md="12">
                <a-form-item :label="$t('Rental_Security_Deposit')" name="deposit_amount" :extra="$t('Rental_Deposit_Help')">
                  <a-input-number v-model:value="form.deposit_amount" :min="0" :step="1" style="width: 100%" />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="12">
                <a-form-item :label="$t('Rental_Minimum_Rental')">
                  <a-input-group compact class="min-group">
                    <a-input-number
                      v-model:value="form.min_duration" :min="1" :precision="0"
                      :placeholder="$t('Rental_No_Minimum')" style="width: 55%"
                    />
                    <a-select v-model:value="form.min_duration_unit" :options="unitOptions" style="width: 45%" />
                  </a-input-group>
                </a-form-item>
              </a-col>
            </a-row>
          </a-card>

          <!-- Property details -->
          <a-card v-if="isProperty" size="small" :title="$t('Rental_Property_Details')" class="block">
            <a-row :gutter="16">
              <a-col :xs="24" :md="16">
                <a-form-item :label="$t('Address')">
                  <a-input v-model:value="form.address" allow-clear />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="8">
                <a-form-item :label="$t('City')">
                  <a-input v-model:value="form.city" allow-clear />
                </a-form-item>
              </a-col>
              <a-col :xs="12" :md="6">
                <a-form-item :label="$t('Rental_Floor')">
                  <a-input v-model:value="form.floor" allow-clear />
                </a-form-item>
              </a-col>
              <a-col :xs="12" :md="6">
                <a-form-item :label="$t('Rental_Area')" name="area">
                  <a-input-group compact>
                    <a-input-number v-model:value="form.area" :min="0" style="width: 62%" />
                    <a-select v-model:value="form.area_unit" :options="AREA_UNITS" style="width: 38%" />
                  </a-input-group>
                </a-form-item>
              </a-col>
              <a-col :xs="12" :md="4">
                <a-form-item :label="$t('Rental_Bedrooms')">
                  <a-input-number v-model:value="form.bedrooms" :min="0" :max="255" :precision="0" style="width: 100%" />
                </a-form-item>
              </a-col>
              <a-col :xs="12" :md="4">
                <a-form-item :label="$t('Rental_Bathrooms')">
                  <a-input-number v-model:value="form.bathrooms" :min="0" :max="255" :precision="0" style="width: 100%" />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="4">
                <a-form-item :label="$t('Rental_Furnished')">
                  <a-switch v-model:checked="form.furnished" />
                </a-form-item>
              </a-col>
              <a-col :span="24">
                <a-form-item :label="$t('Rental_Amenities')" :extra="$t('Rental_Amenities_Help')">
                  <a-select
                    v-model:value="form.amenities" mode="tags" :options="amenityOptions"
                    :placeholder="$t('Rental_Amenities_Placeholder')" :token-separators="[',']"
                  />
                </a-form-item>
              </a-col>
            </a-row>
          </a-card>

          <!-- Equipment / vehicle details -->
          <a-card v-else size="small" :title="form.type === 'vehicle' ? $t('Rental_Vehicle_Details') : $t('Rental_Item_Details')" class="block">
            <a-row :gutter="16">
              <a-col :xs="12" :md="8">
                <a-form-item :label="$t('Brand')">
                  <a-input v-model:value="form.brand" allow-clear />
                </a-form-item>
              </a-col>
              <a-col :xs="12" :md="8">
                <a-form-item :label="$t('Rental_Model')">
                  <a-input v-model:value="form.model" allow-clear />
                </a-form-item>
              </a-col>
              <a-col :xs="12" :md="8">
                <a-form-item :label="$t('Rental_Year')" name="year">
                  <a-input-number v-model:value="form.year" :min="1900" :max="2200" :precision="0" style="width: 100%" />
                </a-form-item>
              </a-col>
              <a-col :xs="12" :md="form.type === 'vehicle' ? 8 : 12">
                <a-form-item :label="$t('Rental_Serial_Number')">
                  <a-input v-model:value="form.serial_number" allow-clear />
                </a-form-item>
              </a-col>
              <a-col v-if="form.type === 'vehicle'" :xs="12" :md="8">
                <a-form-item :label="$t('Rental_Plate_Number')">
                  <a-input v-model:value="form.plate_number" allow-clear />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="form.type === 'vehicle' ? 8 : 12">
                <a-form-item :label="$t('Rental_Meter_Reading')" :extra="$t('Rental_Meter_Help')">
                  <a-input-group compact>
                    <a-input-number v-model:value="form.meter_reading" :min="0" style="width: 60%" />
                    <a-select v-model:value="form.meter_unit" :options="meterUnitOptions" style="width: 40%" />
                  </a-input-group>
                </a-form-item>
              </a-col>
              <template v-if="form.type === 'other'">
                <a-col :xs="24" :md="16">
                  <a-form-item :label="$t('Address')">
                    <a-input v-model:value="form.address" allow-clear />
                  </a-form-item>
                </a-col>
                <a-col :xs="24" :md="8">
                  <a-form-item :label="$t('City')">
                    <a-input v-model:value="form.city" allow-clear />
                  </a-form-item>
                </a-col>
              </template>
            </a-row>
          </a-card>

          <!-- The type's extra properties (App\Support\RentalTypes) — shown on the website too -->
          <a-card v-if="websiteReady && specFields.length" size="small" class="block">
            <template #title>{{ t(`Rental_Spec_Card_${form.type}`, 'Properties') }}</template>
            <template #extra><span class="muted spec-extra">{{ $t('Rental_Specs_Website_Hint') }}</span></template>
            <a-row :gutter="16">
              <a-col v-for="f in specFields" :key="f.id" :xs="24" :md="['multi', 'tags'].includes(f.input) ? 24 : (f.input === 'bool' ? 8 : 12)">
                <a-form-item :label="specLabel(f)" :extra="f.hint ? t(`Rental_Spec_Hint_${f.k}`, f.hint) : ''">
                  <a-select
                    v-if="f.input === 'select'" v-model:value="form.specs[f.id]" allow-clear
                    :options="f.options.map(o => ({ value: o, label: optionLabel(o) }))" :placeholder="$t('Rental_Not_Set')"
                  />
                  <a-input-number
                    v-else-if="f.input === 'number'" v-model:value="form.specs[f.id]" :min="f.min ?? 0" :max="f.max" :precision="0"
                    style="width: 100%" :addon-after="f.unit || undefined"
                  />
                  <a-switch v-else-if="f.input === 'bool'" v-model:checked="form.specs[f.id]" />
                  <a-select
                    v-else-if="f.input === 'multi'" v-model:value="form.specs[f.id]" mode="multiple" allow-clear
                    :options="f.options.map(o => ({ value: o, label: optionLabel(o) }))"
                  />
                  <a-select
                    v-else-if="f.input === 'tags'" v-model:value="form.specs[f.id]" mode="tags" :token-separators="[',']" :open="false"
                    :placeholder="f.placeholder ? t(`Rental_Spec_Ph_${f.k}`, f.placeholder) : ''"
                  />
                  <a-input
                    v-else v-model:value="form.specs[f.id]" allow-clear :maxlength="120"
                    :placeholder="f.placeholder ? t(`Rental_Spec_Ph_${f.k}`, f.placeholder) : ''"
                  />
                </a-form-item>
              </a-col>
            </a-row>
          </a-card>

          <!-- Purchase -->
          <a-card size="small" :title="$t('Rental_Acquisition')" class="block">
            <a-row :gutter="16">
              <a-col :xs="24" :md="12">
                <a-form-item :label="$t('Rental_Acquired_On')" name="purchase_date">
                  <a-date-picker v-model:value="form.purchase_date" style="width: 100%" value-format="YYYY-MM-DD" />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="12">
                <a-form-item :label="$t('Rental_Acquisition_Cost')" name="purchase_cost" :extra="$t('Rental_Acquisition_Cost_Help')">
                  <a-input-number v-model:value="form.purchase_cost" :min="0" :step="1" style="width: 100%" />
                </a-form-item>
              </a-col>
            </a-row>
          </a-card>
        </a-col>

        <!-- Photos + text -->
        <a-col :xs="24" :lg="8">
          <a-card size="small" class="block">
            <template #title>
              {{ $t('Rental_Photos') }}
              <span class="muted photo-count">{{ photos.length }} / {{ MAX_PHOTOS }}</span>
            </template>

            <div v-if="photos.length" class="photos">
              <div v-for="(p, i) in photos" :key="p.key" class="photo" :class="{ cover: i === 0 }">
                <img :src="p.url" alt="" />
                <span v-if="i === 0" class="photo-cover">{{ $t('Rental_Cover') }}</span>
                <div class="photo-actions">
                  <a-tooltip v-if="i !== 0" :title="$t('Rental_Make_Cover')">
                    <button type="button" class="photo-btn" @click="makeCover(i)"><StarOutlined /></button>
                  </a-tooltip>
                  <a-tooltip :title="$t('Delete')">
                    <button type="button" class="photo-btn danger" @click="removePhoto(i)"><DeleteOutlined /></button>
                  </a-tooltip>
                </div>
              </div>
            </div>

            <a-upload-dragger
              v-if="photos.length < MAX_PHOTOS" :show-upload-list="false" accept="image/*"
              multiple :before-upload="pickPhoto" class="dropzone"
            >
              <p class="dz-ic"><CloudUploadOutlined /></p>
              <p class="dz-title">{{ $t('Rental_Drop_Photos') }}</p>
              <p class="dz-hint">{{ $t('Rental_Photos_Hint') }}</p>
            </a-upload-dragger>
          </a-card>

          <!-- Website (Rental store theme) -->
          <a-card v-if="websiteReady" size="small" :title="$t('Rental_Website')" class="block">
            <div class="web-row">
              <span>{{ $t('Rental_Show_On_Website') }}<span class="muted web-hint">{{ $t('Rental_Show_On_Website_Help') }}</span></span>
              <a-switch v-model:checked="form.show_on_website" />
            </div>
            <div class="web-row">
              <span>{{ $t('Rental_Featured') }}<span class="muted web-hint">{{ $t('Rental_Featured_Help') }}</span></span>
              <a-switch v-model:checked="form.featured" :disabled="!form.show_on_website" />
            </div>
            <a-form-item :label="$t('Rental_Url_Slug')" :extra="$t('Rental_Url_Slug_Help')" class="no-mb" style="margin-top: 10px">
              <a-input v-model:value="form.slug" :placeholder="slugPreview" allow-clear :maxlength="191" />
            </a-form-item>
          </a-card>

          <a-card size="small" :title="$t('Description')" class="block">
            <a-textarea v-model:value="form.description" :rows="5" :placeholder="$t('Rental_Description_Placeholder')" />
          </a-card>

          <a-card size="small" :title="$t('Rental_Internal_Notes')" class="block">
            <a-textarea v-model:value="form.notes" :rows="4" :placeholder="$t('Rental_Notes_Placeholder')" />
          </a-card>
        </a-col>
      </a-row>

      <div class="form-foot">
        <a-button @click="goBack">{{ $t('Cancel') }}</a-button>
        <a-button type="primary" size="large" :loading="saving" @click="submit">{{ $t('submit') }}</a-button>
      </div>
    </a-form>
  </div>
</template>

<script setup>
/**
 * Create / edit a rental item.
 *
 * Always posts multipart (photos ride along), so the edit path uses
 * POST rental/items/{id}/update. `image_order` tells the API the final photo
 * order: existing file names and "new:<i>" placeholders for this request's
 * uploads — the first one is the cover.
 */
import { ref, computed, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { message } from 'ant-design-vue';
import {
  CloudUploadOutlined, DeleteOutlined, StarOutlined, CheckCircleFilled,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import { uploadForm } from '../../lib/upload';
import http from '../../lib/http';
import { t } from '../../i18n';
import {
  RENTAL_TYPES, RENTAL_STATUSES, RENTAL_CONDITIONS, RENTAL_PERIODS, AMENITIES, tr,
} from './rentalOptions';
import { specLabel, optionLabel } from './rentalTypes';

const route = useRoute();
const router = useRouter();

const MAX_PHOTOS = 12;
const isEdit = computed(() => !!route.params.id);
const formRef = ref();
const loading = ref(false);
const saving = ref(false);

const TYPE_HINTS = {
  property: { key: 'Rental_Type_Property_Hint', text: 'Apartments, shops, offices, land' },
  equipment: { key: 'Rental_Type_Equipment_Hint', text: 'Tools, machines, cameras, party gear' },
  vehicle: { key: 'Rental_Type_Vehicle_Hint', text: 'Cars, vans, trucks, bikes' },
  other: { key: 'Rental_Type_Other_Hint', text: 'Anything else you rent out' },
};

const AREA_UNITS = [
  { value: 'm²', label: 'm²' },
  { value: 'ft²', label: 'ft²' },
];

// Business types (rental/items/meta → business): which types the tenant runs
// and each one's extra properties. Until it loads, every type is offered.
const business = ref({ types: {}, enabled: RENTAL_TYPES.map(o => o.value), website_ready: false });
const originalType = ref(null);
const websiteReady = computed(() => !!business.value.website_ready);
const typeOptions = computed(() => tr(RENTAL_TYPES)
  .filter(o => (business.value.enabled || []).includes(o.value) || o.value === originalType.value));
const specFields = computed(() => business.value.types?.[form.value.type]?.specs || []);
const statusOptions = computed(() => tr(RENTAL_STATUSES));
const conditionOptions = computed(() => tr(RENTAL_CONDITIONS));
const periodOptions = computed(() => tr(RENTAL_PERIODS));
const unitOptions = computed(() => RENTAL_PERIODS.map(p => ({ value: p.value, label: t(p.unitKey, p.unit) })));
const meterUnitOptions = computed(() => [
  { value: 'km', label: 'km' },
  { value: 'mi', label: 'mi' },
  { value: 'hours', label: t('Rental_Unit_Hours', 'hours') },
]);
const amenityOptions = computed(() => tr(AMENITIES).map(a => ({ value: a.value, label: a.label })));

function blankForm() {
  return {
    type: 'equipment', name: '', code: '', rental_category_id: undefined,
    status: 'available', condition: 'good', warehouse_id: undefined, quantity: 1,
    default_period: 'day',
    hourly_rate: null, daily_rate: null, weekly_rate: null, monthly_rate: null, yearly_rate: null,
    deposit_amount: null, min_duration: null, min_duration_unit: 'day',
    address: '', city: '', floor: '', area: null, area_unit: 'm²',
    bedrooms: null, bathrooms: null, furnished: false, amenities: [],
    brand: '', model: '', serial_number: '', plate_number: '', year: null,
    meter_reading: null, meter_unit: 'km',
    purchase_date: null, purchase_cost: null,
    description: '', notes: '',
    specs: {}, show_on_website: true, featured: false, slug: '',
  };
}

const form = ref(blankForm());
const isProperty = computed(() => form.value.type === 'property');

/** What the website address will use when the slug is left empty. */
const slugPreview = computed(() => (form.value.name || '')
  .toLowerCase().normalize('NFKD').replace(/[̀-ͯ]/g, '')
  .replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, ''));

const namePlaceholder = computed(() => ({
  property: t('Rental_Name_Placeholder_Property', 'e.g. Apartment 3B, Sea View'),
  equipment: t('Rental_Name_Placeholder_Equipment', 'e.g. Generator 5 kVA'),
  vehicle: t('Rental_Name_Placeholder_Vehicle', 'e.g. Toyota Corolla 2023'),
  other: t('Rental_Name_Placeholder_Other', 'e.g. Wedding tent 10×20'),
}[form.value.type]));

const required = () => [{ required: true, message: t('Field_is_required', 'This field is required') }];
const rules = computed(() => {
  const mainRate = RENTAL_PERIODS.find(p => p.value === form.value.default_period)?.rate;
  return {
    name: required(),
    status: required(),
    condition: required(),
    ...(mainRate ? { [mainRate]: [{ required: true, type: 'number', message: t('Rental_Main_Rate_Required', 'Enter a price for the main period') }] } : {}),
  };
});

const categories = ref([]);
const warehouses = ref([]);
const nextCode = ref('');
// A category with a type belongs to that type; one without serves every type.
const categoryOptions = computed(() => categories.value
  .filter(c => !c.type || c.type === form.value.type || c.id === form.value.rental_category_id)
  .map(c => ({ value: c.id, label: c.name })));

// The user switching type drops a category of the other type. Done in the
// click handler, not a watch: loading a record also changes the type, and an
// existing item may legitimately sit in a category whose default type differs.
function pickType(type) {
  if (form.value.type === type) return;
  form.value.type = type;
  const cat = categories.value.find(c => c.id === form.value.rental_category_id);
  if (cat && cat.type && cat.type !== type) form.value.rental_category_id = undefined;
}
const warehouseOptions = computed(() => warehouses.value.map(w => ({ value: w.id, label: w.name })));

/** A category with a default type steers the type picker (new items only). */
function onCategoryChange(id) {
  if (isEdit.value) return;
  const cat = categories.value.find(c => c.id === id);
  if (cat?.type) form.value.type = cat.type;
}

// ----- photos -----
// { key, url, file?: existing file name, upload?: File }
const photos = ref([]);
let photoSeq = 0;

function pickPhoto(file) {
  if (photos.value.length >= MAX_PHOTOS) return false;
  if (file.size > 5 * 1024 * 1024) {
    message.error(t('Rental_Photo_Too_Large', 'Each photo must be 5 MB or smaller'));
    return false;
  }
  photos.value.push({ key: `n${photoSeq++}`, url: window.URL.createObjectURL(file), upload: file });
  return false; // keep antd from uploading it itself
}

function removePhoto(i) {
  photos.value.splice(i, 1);
}

function makeCover(i) {
  const [p] = photos.value.splice(i, 1);
  photos.value.unshift(p);
}

// ----- load / save -----
async function load() {
  loading.value = true;
  try {
    const [meta, record] = await Promise.all([
      http.get('rental/items/meta'),
      isEdit.value ? http.get(`rental/items/${route.params.id}/edit`) : Promise.resolve(null),
    ]);
    categories.value = meta?.categories || [];
    warehouses.value = meta?.warehouses || [];
    nextCode.value = meta?.next_code || '';
    if (meta?.business?.types) business.value = meta.business;
    const enabled = business.value.enabled || [];

    if (!isEdit.value) {
      if (meta?.default_deposit) form.value.deposit_amount = Number(meta.default_deposit);
      // Arriving from a filtered list pre-selects that type; otherwise the business's first type.
      if (route.query.type && enabled.includes(route.query.type)) form.value.type = route.query.type;
      else if (!enabled.includes(form.value.type) && enabled.length) form.value.type = enabled[0];
    }

    if (record?.item) {
      const it = record.item;
      const base = blankForm();
      const merged = { ...base };
      Object.keys(base).forEach((k) => {
        if (it[k] !== undefined && it[k] !== null) merged[k] = it[k];
      });
      merged.rental_category_id = it.rental_category_id || undefined;
      merged.warehouse_id = it.warehouse_id || undefined;
      merged.min_duration_unit = it.min_duration_unit || it.default_period || 'day';
      merged.amenities = Array.isArray(it.amenities) ? it.amenities : [];
      merged.furnished = !!it.furnished;
      merged.specs = it.specs && typeof it.specs === 'object' && !Array.isArray(it.specs) ? { ...it.specs } : {};
      merged.show_on_website = it.show_on_website !== false && it.show_on_website !== 0;
      merged.featured = !!it.featured;
      merged.slug = it.slug || '';
      originalType.value = it.type;
      form.value = merged;
      photos.value = (it.images || []).map(img => ({ key: img.file, url: img.url, file: img.file }));
    }
  } catch (e) {
    message.error(t('Rental_Err_Could_Not_Load_This_Item', 'Could not load this item'));
  } finally {
    loading.value = false;
  }
}

async function submit() {
  try {
    await formRef.value.validate();
  } catch (e) {
    message.error(t('Rental_Check_Form', 'Please check the highlighted fields'));
    return;
  }

  const fd = new FormData();
  const special = ['amenities', 'furnished', 'specs', 'show_on_website', 'featured', 'slug'];
  Object.entries(form.value).forEach(([key, value]) => {
    if (special.includes(key)) return;
    if (value === null || value === undefined || value === '') return;
    fd.append(key, value);
  });
  fd.append('furnished', form.value.furnished ? '1' : '0');
  fd.append('amenities', JSON.stringify(form.value.amenities || []));
  if (websiteReady.value) {
    // Only this type's properties travel; the API drops anything else too.
    const specs = {};
    specFields.value.forEach((f) => {
      const v = form.value.specs[f.id];
      if (v !== undefined && v !== null && v !== '' && !(Array.isArray(v) && !v.length)) specs[f.id] = v;
    });
    fd.append('specs', JSON.stringify(specs));
    fd.append('show_on_website', form.value.show_on_website ? '1' : '0');
    fd.append('featured', form.value.featured ? '1' : '0');
    fd.append('slug', form.value.slug || '');
  }

  // Uploads are numbered in the order they're appended; the order list
  // refers to them by that index.
  const order = [];
  let n = 0;
  photos.value.forEach((p) => {
    if (p.upload) {
      fd.append('new_images[]', p.upload);
      order.push(`new:${n++}`);
    } else {
      order.push(p.file);
    }
  });
  fd.append('image_order', JSON.stringify(order));

  saving.value = true;
  try {
    const url = isEdit.value ? `rental/items/${route.params.id}/update` : 'rental/items';
    const res = await uploadForm(url, fd);
    if (res.status >= 200 && res.status < 300) {
      message.success(isEdit.value
        ? t('Updated_in_successfully', 'Updated successfully')
        : t('Created_in_successfully', 'Created successfully'));
      const id = res.data?.id;
      router.push(id ? `/rental/items/${id}` : '/rental/items');
      return;
    }
    message.error(firstError(res.data) || t('Rental_Err_Could_Not_Save_This_Item', 'Could not save this item'));
  } catch (e) {
    message.error(t('Rental_Err_Could_Not_Save_This_Item', 'Could not save this item'));
  } finally {
    saving.value = false;
  }
}

function firstError(data) {
  const errors = data?.errors;
  if (errors) {
    const first = Object.values(errors)[0];
    if (Array.isArray(first) && first.length) return first[0];
  }
  return data?.message || '';
}

function goBack() {
  router.push(isEdit.value ? `/rental/items/${route.params.id}` : '/rental/items');
}

onMounted(load);
</script>

<style scoped>
.loading {
  display: flex;
  justify-content: center;
  padding: 96px 0;
}
.block {
  margin-bottom: 16px;
}
.muted {
  opacity: 0.55;
}

/* ---------------- type picker ---------------- */
.type-picker {
  display: grid;
  grid-template-columns: repeat(var(--type-cols, 4), minmax(0, 1fr));
  gap: 12px;
  margin-bottom: 16px;
}
.type-card {
  position: relative;
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 14px;
  border: 1.5px solid rgba(128, 128, 128, 0.22);
  border-radius: 14px;
  background: transparent;
  color: inherit;
  font: inherit;
  text-align: start;
  cursor: pointer;
  transition: border-color 0.15s ease, background 0.15s ease;
}
.type-card:hover {
  border-color: rgba(var(--app-primary-rgb, 109, 40, 217), 0.5);
}
.type-card.active {
  border-color: var(--app-primary, #6d28d9);
  background: rgba(var(--app-primary-rgb, 109, 40, 217), 0.07);
}
.type-card-ic {
  width: 42px;
  height: 42px;
  flex: none;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 12px;
  background: rgba(128, 128, 128, 0.12);
}
.type-card.active .type-card-ic {
  color: #fff;
  background: var(--app-primary, #6d28d9);
}
.type-card-text {
  min-width: 0;
}
.type-card-title {
  display: block;
  font-weight: 600;
}
.type-card-hint {
  display: block;
  font-size: 12px;
  opacity: 0.6;
  line-height: 1.35;
}
.type-card-check {
  position: absolute;
  inset-block-start: 8px;
  inset-inline-end: 8px;
  color: var(--app-primary, #6d28d9);
}

/* ---------------- pricing ---------------- */
.period-group {
  display: flex;
  flex-wrap: wrap;
}
.rates {
  display: grid;
  grid-template-columns: repeat(5, minmax(0, 1fr));
  gap: 10px;
  margin-bottom: 8px;
}
.rate {
  position: relative;
  padding: 10px 10px 0;
  border: 1px dashed rgba(128, 128, 128, 0.3);
  border-radius: 12px;
}
.rate.main {
  border-style: solid;
  border-color: var(--app-primary, #6d28d9);
  background: rgba(var(--app-primary-rgb, 109, 40, 217), 0.05);
}
.rate-item {
  margin-bottom: 10px;
}
.rate-badge {
  position: absolute;
  inset-block-start: -9px;
  inset-inline-end: 10px;
  padding: 0 7px;
  border-radius: 999px;
  font-size: 10.5px;
  font-weight: 600;
  line-height: 18px;
  color: #fff;
  background: var(--app-primary, #6d28d9);
}
.min-group {
  display: flex;
}

/* ---------------- photos ---------------- */
.photo-count {
  margin-inline-start: 6px;
  font-size: 12px;
  font-weight: 400;
}
.photos {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 8px;
  margin-bottom: 12px;
}
.photo {
  position: relative;
  aspect-ratio: 1;
  border-radius: 10px;
  overflow: hidden;
  background: rgba(128, 128, 128, 0.1);
}
.photo.cover {
  grid-column: span 3;
  aspect-ratio: 16 / 9;
}
.photo img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}
.photo-cover {
  position: absolute;
  inset-block-start: 8px;
  inset-inline-start: 8px;
  padding: 2px 8px;
  border-radius: 999px;
  font-size: 11px;
  font-weight: 600;
  color: #fff;
  background: var(--app-primary, #6d28d9);
}
.photo-actions {
  position: absolute;
  inset-block-start: 6px;
  inset-inline-end: 6px;
  display: flex;
  gap: 4px;
  opacity: 0;
  transition: opacity 0.15s ease;
}
.photo:hover .photo-actions,
.photo:focus-within .photo-actions {
  opacity: 1;
}
.photo-btn {
  width: 28px;
  height: 28px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border: 0;
  border-radius: 8px;
  color: #fff;
  background: rgba(15, 23, 42, 0.7);
  cursor: pointer;
}
.photo-btn.danger:hover {
  background: #dc2626;
}
.dropzone :deep(.ant-upload-drag) {
  border-radius: 12px;
}
.dz-ic {
  margin: 0;
  font-size: 28px;
  color: var(--app-primary, #6d28d9);
}
.dz-title {
  margin: 4px 0 2px;
  font-weight: 500;
}
.dz-hint {
  margin: 0;
  font-size: 12px;
  opacity: 0.55;
}
.form-foot {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
  margin-top: 8px;
}
.spec-extra {
  font-size: 12px;
  font-weight: 400;
}
.web-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 8px 0;
  border-bottom: 1px dashed rgba(128, 128, 128, 0.2);
}
.web-hint {
  display: block;
  font-size: 12px;
}
.no-mb {
  margin-bottom: 0;
}

@media (max-width: 991px) {
  .type-picker {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
  .rates {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}
@media (max-width: 480px) {
  .type-card-hint {
    display: none;
  }
}
@media (hover: none) {
  .photo-actions {
    opacity: 1;
  }
}
</style>
