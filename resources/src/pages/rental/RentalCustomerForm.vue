<template>
  <div class="page">
    <PageHeader
      :title="isEdit ? $t('Rental_Edit_Customer') : $t('Rental_Add_Customer')"
      :breadcrumb="[$t('Rental_Management'), $t('Rental_Customers'), isEdit ? $t('Edit') : $t('Add')]"
    >
      <template #actions>
        <a-button @click="goBack">{{ $t('Cancel') }}</a-button>
        <a-button type="primary" :loading="saving" @click="submit">{{ $t('submit') }}</a-button>
      </template>
    </PageHeader>

    <div v-if="loading" class="loading"><a-spin size="large" /></div>

    <a-form v-else ref="formRef" :model="form" :rules="rules" layout="vertical">
      <a-row :gutter="16">
        <a-col :xs="24" :lg="16">
          <!-- Who: an existing client, or a new one created on the spot -->
          <a-card size="small" :title="$t('Customer')" class="block">
            <template v-if="!isEdit">
              <a-segmented v-model:value="form.client_mode" :options="modeOptions" block class="mode" />

              <template v-if="form.client_mode === 'existing'">
                <a-form-item name="client_id" :label="$t('Rental_Find_Customer')" :extra="$t('Rental_Find_Customer_Help')">
                  <a-select
                    v-model:value="form.client_id" show-search :filter-option="false" :options="clientOptions"
                    :not-found-content="searching ? undefined : $t('Rental_No_Customers_Found')"
                    :placeholder="$t('Rental_Search_Customer_Placeholder')" allow-clear
                    @search="onClientSearch" @focus="onClientSearch('')"
                  >
                    <template #option="{ label, sub, taken }">
                      <div class="opt">
                        <span class="opt-main">{{ label }}</span>
                        <span class="opt-sub">{{ sub }}</span>
                        <a-tag v-if="taken" color="purple" class="opt-tag">{{ $t('Rental_Already_Customer') }}</a-tag>
                      </div>
                    </template>
                  </a-select>
                </a-form-item>

                <div v-if="pickedClient" class="picked">
                  <a-avatar :size="44" :style="{ background: avatarColor(pickedClient.name) }">{{ initials(pickedClient.name) }}</a-avatar>
                  <div class="picked-text">
                    <strong>{{ pickedClient.name }}</strong>
                    <span>{{ [pickedClient.phone, pickedClient.email, pickedClient.city].filter(Boolean).join(' · ') || '—' }}</span>
                  </div>
                </div>
              </template>
            </template>

            <!-- New client, or editing the linked client's contact details -->
            <a-row v-if="isEdit || form.client_mode === 'new'" :gutter="16">
              <a-col :span="24">
                <a-alert v-if="isEdit" type="info" show-icon :message="$t('Rental_Contact_Shared_Note')" class="shared-note" />
              </a-col>
              <a-col :xs="24" :md="12">
                <a-form-item :label="$t('Name')" name="name">
                  <a-input v-model:value="form.name" allow-clear />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="12">
                <a-form-item :label="$t('Phone')" name="phone">
                  <a-input v-model:value="form.phone" allow-clear />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="12">
                <a-form-item :label="$t('Email')" name="email">
                  <a-input v-model:value="form.email" type="email" allow-clear />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="12">
                <a-form-item :label="$t('Address')">
                  <a-input v-model:value="form.adresse" allow-clear />
                </a-form-item>
              </a-col>
              <a-col :xs="12" :md="12">
                <a-form-item :label="$t('City')">
                  <a-input v-model:value="form.city" allow-clear />
                </a-form-item>
              </a-col>
              <a-col :xs="12" :md="12">
                <a-form-item :label="$t('Country')">
                  <a-input v-model:value="form.country" allow-clear />
                </a-form-item>
              </a-col>
            </a-row>
          </a-card>

          <!-- Identity -->
          <a-card size="small" class="block">
            <template #title><span class="card-title"><IdcardOutlined /> {{ $t('Rental_Identity') }}</span></template>
            <a-row :gutter="16">
              <a-col :xs="24" :md="8">
                <a-form-item :label="$t('Rental_ID_Type')">
                  <a-select v-model:value="form.id_type" :options="idTypeOptions" allow-clear />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="8">
                <a-form-item :label="$t('Rental_ID_Number')">
                  <a-input v-model:value="form.id_number" allow-clear />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="8">
                <a-form-item :label="$t('Rental_ID_Expiry')">
                  <a-date-picker v-model:value="form.id_expiry" style="width: 100%" value-format="YYYY-MM-DD" />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="8">
                <a-form-item :label="$t('Rental_Date_Of_Birth')" name="date_of_birth">
                  <a-date-picker v-model:value="form.date_of_birth" style="width: 100%" value-format="YYYY-MM-DD" :disabled-date="futureDate" />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="8">
                <a-form-item :label="$t('Rental_Nationality')">
                  <a-input v-model:value="form.nationality" allow-clear />
                </a-form-item>
              </a-col>
            </a-row>

            <div class="scans">
              <div v-for="side in SIDES" :key="side.field" class="scan">
                <span class="scan-label">{{ $t(side.key) }}</span>
                <div class="scan-box" :class="{ filled: scans[side.field].url }">
                  <template v-if="scans[side.field].url">
                    <img v-if="scans[side.field].isImage" :src="scans[side.field].url" alt="" />
                    <a v-else :href="scans[side.field].url" target="_blank" rel="noopener" class="scan-file">
                      <FilePdfOutlined /> {{ $t('PDF') }}
                    </a>
                    <button type="button" class="scan-remove" :title="$t('Delete')" @click="clearScan(side.field)">
                      <CloseOutlined />
                    </button>
                  </template>
                  <a-upload v-else :show-upload-list="false" accept="image/*,.pdf" :before-upload="f => pickScan(side.field, f)">
                    <button type="button" class="scan-pick">
                      <CameraOutlined />
                      <span>{{ $t('Rental_Upload_Scan') }}</span>
                    </button>
                  </a-upload>
                </div>
              </div>
            </div>
          </a-card>

          <!-- Work -->
          <a-card size="small" class="block">
            <template #title><span class="card-title"><ShopOutlined /> {{ $t('Rental_Work') }}</span></template>
            <a-row :gutter="16">
              <a-col :xs="24" :md="12">
                <a-form-item :label="$t('Rental_Occupation')">
                  <a-input v-model:value="form.occupation" allow-clear />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="12">
                <a-form-item :label="$t('Rental_Employer')">
                  <a-input v-model:value="form.employer" allow-clear />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="12">
                <a-form-item :label="$t('Rental_Employer_Phone')">
                  <a-input v-model:value="form.employer_phone" allow-clear />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="12">
                <a-form-item :label="$t('Rental_Monthly_Income')">
                  <a-input-number v-model:value="form.monthly_income" :min="0" style="width: 100%" />
                </a-form-item>
              </a-col>
            </a-row>
          </a-card>

          <a-row :gutter="16">
            <!-- Emergency contact -->
            <a-col :xs="24" :md="12">
              <a-card size="small" class="block fill">
                <template #title><span class="card-title"><AlertOutlined /> {{ $t('Rental_Emergency_Contact') }}</span></template>
                <a-form-item :label="$t('Name')">
                  <a-input v-model:value="form.emergency_name" allow-clear />
                </a-form-item>
                <a-form-item :label="$t('Phone')">
                  <a-input v-model:value="form.emergency_phone" allow-clear />
                </a-form-item>
                <a-form-item :label="$t('Rental_Relationship')">
                  <a-input v-model:value="form.emergency_relation" :placeholder="$t('Rental_Relationship_Placeholder')" allow-clear />
                </a-form-item>
              </a-card>
            </a-col>

            <!-- Guarantor -->
            <a-col :xs="24" :md="12">
              <a-card size="small" class="block fill">
                <template #title><span class="card-title"><SafetyCertificateOutlined /> {{ $t('Rental_Guarantor') }}</span></template>
                <a-form-item :label="$t('Name')">
                  <a-input v-model:value="form.guarantor_name" allow-clear />
                </a-form-item>
                <a-row :gutter="12">
                  <a-col :span="12">
                    <a-form-item :label="$t('Phone')">
                      <a-input v-model:value="form.guarantor_phone" allow-clear />
                    </a-form-item>
                  </a-col>
                  <a-col :span="12">
                    <a-form-item :label="$t('Rental_ID_Number')">
                      <a-input v-model:value="form.guarantor_id_number" allow-clear />
                    </a-form-item>
                  </a-col>
                </a-row>
                <a-form-item :label="$t('Rental_Relationship')">
                  <a-input v-model:value="form.guarantor_relation" allow-clear />
                </a-form-item>
                <a-form-item :label="$t('Address')">
                  <a-input v-model:value="form.guarantor_address" allow-clear />
                </a-form-item>
              </a-card>
            </a-col>
          </a-row>
        </a-col>

        <!-- Standing + notes -->
        <a-col :xs="24" :lg="8">
          <a-card size="small" :title="$t('Rental_Standing')" class="block">
            <a-form-item :label="$t('Rental_Rating')" :extra="$t('Rental_Rating_Help')">
              <a-rate v-model:value="form.rating" allow-clear />
            </a-form-item>

            <div class="bl" :class="{ on: form.is_blacklisted }">
              <div class="bl-head">
                <span>
                  <strong>{{ $t('Rental_Blacklisted') }}</strong>
                  <span class="bl-help">{{ $t('Rental_Blacklist_Help') }}</span>
                </span>
                <a-switch v-model:checked="form.is_blacklisted" />
              </div>
              <a-form-item v-if="form.is_blacklisted" name="blacklist_reason" :label="$t('Rental_Blacklist_Reason')" class="bl-reason">
                <a-textarea v-model:value="form.blacklist_reason" :rows="3" />
              </a-form-item>
            </div>
          </a-card>

          <a-card size="small" :title="$t('Rental_Internal_Notes')" class="block">
            <a-textarea v-model:value="form.notes" :rows="6" :placeholder="$t('Rental_Notes_Placeholder')" />
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
 * Create / edit a rental customer.
 *
 * Create either links an EXISTING client (type-ahead over clients) or creates
 * a new client on the spot. Edit also edits the linked client's contact
 * details — they're shared with Sales, POS and the portal.
 *
 * Multipart POST (ID scans ride along); edit posts to rental/customers/{id}/update.
 */
import { ref, reactive, computed, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { message } from 'ant-design-vue';
import dayjs from 'dayjs';
import {
  IdcardOutlined, ShopOutlined, AlertOutlined, SafetyCertificateOutlined,
  CameraOutlined, CloseOutlined, FilePdfOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import { uploadForm } from '../../lib/upload';
import http from '../../lib/http';
import { t } from '../../i18n';
import { ID_TYPES, tr, initials, avatarColor } from './rentalOptions';

const route = useRoute();
const router = useRouter();

const isEdit = computed(() => !!route.params.id);
const formRef = ref();
const loading = ref(false);
const saving = ref(false);

const SIDES = [
  { field: 'id_front', key: 'Rental_ID_Front' },
  { field: 'id_back', key: 'Rental_ID_Back' },
];

const form = ref({
  client_mode: 'existing', client_id: undefined,
  name: '', phone: '', email: '', adresse: '', city: '', country: '',
  id_type: 'national_id', id_number: '', id_expiry: null, date_of_birth: null, nationality: '',
  occupation: '', employer: '', employer_phone: '', monthly_income: null,
  emergency_name: '', emergency_phone: '', emergency_relation: '',
  guarantor_name: '', guarantor_phone: '', guarantor_id_number: '', guarantor_relation: '', guarantor_address: '',
  rating: 0, is_blacklisted: false, blacklist_reason: '', notes: '',
});

const modeOptions = computed(() => [
  { value: 'existing', label: t('Rental_Existing_Customer', 'Existing customer') },
  { value: 'new', label: t('Rental_New_Customer', 'New customer') },
]);
const idTypeOptions = computed(() => tr(ID_TYPES));

const required = msg => [{ required: true, message: msg || t('Field_is_required', 'This field is required') }];
const rules = computed(() => {
  const needsClientFields = isEdit.value || form.value.client_mode === 'new';
  return {
    ...(needsClientFields ? { name: required() } : { client_id: required(t('Rental_Pick_Customer', 'Pick a customer')) }),
    email: [{ type: 'email', message: t('Rental_Invalid_Email', 'Enter a valid email address') }],
    ...(form.value.is_blacklisted ? { blacklist_reason: required() } : {}),
  };
});

const futureDate = d => d && d.isAfter(dayjs(), 'day');

// ----- client type-ahead -----
const clients = ref([]);
const searching = ref(false);
let searchTimer = null;
let searchSeq = 0;

const clientOptions = computed(() => clients.value.map(c => ({
  value: c.id,
  label: c.name,
  sub: [c.phone, c.email, c.city].filter(Boolean).join(' · '),
  taken: !!c.rental_customer_id,
  disabled: !!c.rental_customer_id,
})));
const pickedClient = computed(() => clients.value.find(c => c.id === form.value.client_id) || null);

function onClientSearch(value) {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(async () => {
    const seq = ++searchSeq;
    searching.value = true;
    try {
      const data = await http.get('rental/customers/client-search', { search: value || '' });
      // Ignore answers to searches the user has already typed past.
      if (seq === searchSeq) clients.value = data?.clients || [];
    } catch (e) { /* keep the previous list */ } finally {
      if (seq === searchSeq) searching.value = false;
    }
  }, 250);
}

// ----- ID scans -----
// { url, isImage, file?: File, remove: bool }
const scans = reactive({
  id_front: { url: '', isImage: true, file: null, remove: false },
  id_back: { url: '', isImage: true, file: null, remove: false },
});

function pickScan(field, file) {
  if (file.size > 5 * 1024 * 1024) {
    message.error(t('Rental_File_Too_Large', 'Each file must be 5 MB or smaller'));
    return false;
  }
  scans[field] = {
    url: window.URL.createObjectURL(file),
    isImage: file.type.startsWith('image/'),
    file,
    remove: false,
  };
  return false;
}

function clearScan(field) {
  scans[field] = { url: '', isImage: true, file: null, remove: true };
}

// ----- load / save -----
async function load() {
  if (!isEdit.value) return;
  loading.value = true;
  try {
    const data = await http.get(`rental/customers/${route.params.id}`);
    const c = data?.customer;
    if (c) {
      Object.keys(form.value).forEach((k) => {
        if (c[k] !== undefined && c[k] !== null) form.value[k] = c[k];
      });
      form.value.rating = c.rating || 0;
      form.value.is_blacklisted = !!c.is_blacklisted;
      // Keep "no ID type" as it is instead of the new-customer default.
      form.value.id_type = c.id_type || undefined;
      for (const side of SIDES) {
        const url = c[`${side.field}_url`];
        if (url) scans[side.field] = { url, isImage: !/\.pdf$/i.test(url), file: null, remove: false };
      }
    }
  } catch (e) {
    message.error(t('Gen_Could_Not_Load_Customer', 'Could not load this customer'));
  } finally {
    loading.value = false;
  }
}

async function submit() {
  if (saving.value) return; // a double click must not create the client twice
  saving.value = true;
  try {
    await formRef.value.validate();
  } catch (e) {
    saving.value = false;
    message.error(t('Rental_Check_Form', 'Please check the highlighted fields'));
    return;
  }

  const fd = new FormData();
  Object.entries(form.value).forEach(([key, value]) => {
    if (key === 'is_blacklisted' || key === 'rating') return;
    if (value === null || value === undefined || value === '') return;
    fd.append(key, value);
  });
  fd.append('is_blacklisted', form.value.is_blacklisted ? '1' : '0');
  if (form.value.rating) fd.append('rating', form.value.rating);
  for (const side of SIDES) {
    const s = scans[side.field];
    if (s.file) fd.append(`${side.field}_file`, s.file);
    else if (s.remove) fd.append(`remove_${side.field}`, '1');
  }

  saving.value = true;
  try {
    const url = isEdit.value ? `rental/customers/${route.params.id}/update` : 'rental/customers';
    const res = await uploadForm(url, fd);
    if (res.status >= 200 && res.status < 300) {
      message.success(isEdit.value
        ? t('Updated_in_successfully', 'Updated successfully')
        : t('Created_successfully', 'Created successfully'));
      router.push(res.data?.id ? `/rental/customers/${res.data.id}` : '/rental/customers');
      return;
    }
    message.error(firstError(res.data) || t('Rental_Could_Not_Save_Customer', 'Could not save this customer'));
  } catch (e) {
    message.error(t('Rental_Could_Not_Save_Customer', 'Could not save this customer'));
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
  router.push(isEdit.value ? `/rental/customers/${route.params.id}` : '/rental/customers');
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
.fill {
  height: calc(100% - 16px);
}
.card-title {
  display: inline-flex;
  align-items: center;
  gap: 8px;
}
.card-title :deep(.anticon) {
  color: var(--app-primary, #6d28d9);
}
.mode {
  margin-bottom: 16px;
}
.shared-note {
  margin-bottom: 16px;
}

/* client options + picked client */
.opt {
  display: flex;
  flex-wrap: wrap;
  align-items: baseline;
  gap: 2px 8px;
}
.opt-main {
  font-weight: 500;
}
.opt-sub {
  font-size: 12px;
  opacity: 0.6;
}
.opt-tag {
  margin-inline-start: auto;
}
.picked {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 12px 14px;
  border-radius: 12px;
  background: rgba(var(--app-primary-rgb, 109, 40, 217), 0.06);
}
.picked-text {
  display: flex;
  flex-direction: column;
  min-width: 0;
}
.picked-text span {
  font-size: 12.5px;
  opacity: 0.65;
}

/* ID scans */
.scans {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 12px;
}
.scan-label {
  display: block;
  margin-bottom: 6px;
  font-size: 13px;
}
.scan-box {
  position: relative;
  aspect-ratio: 1.586; /* ID-1 card proportions */
  border: 1.5px dashed rgba(128, 128, 128, 0.35);
  border-radius: 12px;
  overflow: hidden;
  display: flex;
  align-items: center;
  justify-content: center;
}
.scan-box.filled {
  border-style: solid;
}
.scan-box :deep(.ant-upload-wrapper),
.scan-box :deep(.ant-upload) {
  width: 100%;
  height: 100%;
}
.scan-box img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}
.scan-pick {
  width: 100%;
  height: 100%;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 6px;
  border: 0;
  background: none;
  color: inherit;
  font: inherit;
  font-size: 13px;
  opacity: 0.6;
  cursor: pointer;
}
.scan-pick :deep(.anticon) {
  font-size: 24px;
  color: var(--app-primary, #6d28d9);
}
.scan-pick:hover {
  opacity: 1;
}
.scan-file {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  font-size: 16px;
  font-weight: 600;
}
.scan-remove {
  position: absolute;
  inset-block-start: 6px;
  inset-inline-end: 6px;
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
.scan-remove:hover {
  background: #dc2626;
}

/* blacklist block */
.bl {
  padding: 12px 14px;
  border: 1px solid rgba(128, 128, 128, 0.22);
  border-radius: 12px;
  transition: border-color 0.15s ease, background 0.15s ease;
}
.bl.on {
  border-color: rgba(220, 38, 38, 0.45);
  background: rgba(220, 38, 38, 0.05);
}
.bl-head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
}
.bl-help {
  display: block;
  font-size: 12px;
  opacity: 0.6;
}
.bl-reason {
  margin: 12px 0 0;
}
.form-foot {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
  margin-top: 8px;
}

@media (max-width: 575px) {
  .scans {
    grid-template-columns: 1fr;
  }
}
</style>
