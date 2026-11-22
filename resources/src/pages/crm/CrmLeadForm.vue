<template>
  <div class="page">
    <PageHeader
      :title="isEdit ? $t('Crm_Edit_Lead') : $t('Crm_New_Lead')"
      :breadcrumb="[$t('Crm'), $t('Crm_Leads'), isEdit ? $t('Edit') : $t('Crm_New_Lead')]"
    >
      <template #actions>
        <a-button @click="$router.back()">{{ $t('Cancel') }}</a-button>
        <a-button type="primary" :loading="saving" :disabled="loading" @click="submit">
          <template #icon><SaveOutlined /></template>
          {{ $t('Save') }}
        </a-button>
      </template>
    </PageHeader>

    <a-spin :spinning="loading">
      <a-form ref="formRef" :model="form" layout="vertical" :rules="rules">
        <a-row :gutter="16">
          <a-col :xs="24" :lg="16">
            <a-card :bordered="false" class="card">
              <div class="card-head">
                <span class="card-title">{{ $t('Crm_Who') }}</span>
                <a-segmented v-model:value="form.type" :options="typeOptions" />
              </div>

              <a-row :gutter="12">
                <template v-if="form.type === 'company'">
                  <a-col :xs="24" :md="12">
                    <a-form-item :label="$t('Crm_Company_Name')" name="company_name">
                      <a-input v-model:value="form.company_name" :maxlength="191" />
                    </a-form-item>
                  </a-col>
                  <a-col :xs="24" :md="12">
                    <a-form-item :label="$t('Crm_Industry')">
                      <a-input v-model:value="form.industry" :maxlength="120" />
                    </a-form-item>
                  </a-col>
                  <a-col :span="24"><div class="sub-title">{{ $t('Crm_Contact_Person') }}</div></a-col>
                </template>
                <a-col :xs="24" :md="12">
                  <a-form-item :label="$t('Crm_First_Name')" name="first_name">
                    <a-input v-model:value="form.first_name" :maxlength="100" />
                  </a-form-item>
                </a-col>
                <a-col :xs="24" :md="12">
                  <a-form-item :label="$t('Crm_Last_Name')">
                    <a-input v-model:value="form.last_name" :maxlength="100" />
                  </a-form-item>
                </a-col>
                <a-col v-if="form.type === 'person'" :xs="24" :md="12">
                  <a-form-item :label="$t('Crm_Company_Optional')">
                    <a-input v-model:value="form.company_name" :maxlength="191" />
                  </a-form-item>
                </a-col>
                <a-col :xs="24" :md="12">
                  <a-form-item :label="$t('Crm_Job_Title')">
                    <a-input v-model:value="form.job_title" :maxlength="120" />
                  </a-form-item>
                </a-col>
              </a-row>
            </a-card>

            <a-card :bordered="false" class="card">
              <div class="card-head"><span class="card-title">{{ $t('Crm_Contact_Details') }}</span></div>
              <a-row :gutter="12">
                <a-col :xs="24" :md="12">
                  <a-form-item :label="$t('Email')" name="email">
                    <a-input v-model:value="form.email" type="email" :maxlength="191" @blur="checkDuplicates">
                      <template #prefix><MailOutlined /></template>
                    </a-input>
                  </a-form-item>
                </a-col>
                <a-col :xs="24" :md="12">
                  <a-form-item :label="$t('Phone')">
                    <a-input v-model:value="form.phone" :maxlength="50" @blur="checkDuplicates">
                      <template #prefix><PhoneOutlined /></template>
                    </a-input>
                  </a-form-item>
                </a-col>
                <a-col :span="24">
                  <a-alert v-if="dupCount" type="warning" show-icon class="dups">
                    <template #message>{{ $t('Crm_Possible_Duplicates') }}</template>
                    <template #description>
                      <div v-for="d in duplicates.clients" :key="`c${d.id}`" class="dup">
                        <a-tag color="green">{{ $t('Customer') }}</a-tag>
                        <router-link :to="`/crm/customers/${d.id}`" target="_blank">{{ d.name }}</router-link>
                        <span class="muted">· {{ d.match === 'email' ? d.email : d.phone }}</span>
                      </div>
                      <div v-for="d in duplicates.leads" :key="`l${d.id}`" class="dup">
                        <a-tag color="purple">{{ $t('Crm_Lead') }}</a-tag>
                        <router-link :to="`/crm/leads/${d.id}`" target="_blank">{{ d.name }}</router-link>
                        <span class="muted">· {{ d.lead_number }} · {{ d.status }}<template v-if="d.owner"> · {{ d.owner }}</template></span>
                      </div>
                    </template>
                  </a-alert>
                </a-col>
                <a-col :xs="24" :md="12">
                  <a-form-item :label="$t('Crm_Website')">
                    <a-input v-model:value="form.website" :maxlength="191" placeholder="https://" />
                  </a-form-item>
                </a-col>
                <a-col v-if="form.type === 'person'" :xs="24" :md="12">
                  <a-form-item :label="$t('Crm_Industry')">
                    <a-input v-model:value="form.industry" :maxlength="120" />
                  </a-form-item>
                </a-col>
                <a-col :xs="24" :md="12">
                  <a-form-item :label="$t('City')">
                    <a-input v-model:value="form.city" :maxlength="120" />
                  </a-form-item>
                </a-col>
                <a-col :xs="24" :md="12">
                  <a-form-item :label="$t('Country')">
                    <a-input v-model:value="form.country" :maxlength="120" />
                  </a-form-item>
                </a-col>
                <a-col :span="24">
                  <a-form-item :label="$t('Adress')">
                    <a-input v-model:value="form.address" :maxlength="255" />
                  </a-form-item>
                </a-col>
              </a-row>
            </a-card>

            <a-card :bordered="false" class="card">
              <a-form-item :label="$t('Description')" class="mb0">
                <a-textarea v-model:value="form.description" :rows="4" :maxlength="10000" :placeholder="$t('Crm_Description_Hint')" />
              </a-form-item>
            </a-card>
          </a-col>

          <a-col :xs="24" :lg="8">
            <a-card :bordered="false" class="card">
              <div class="card-head"><span class="card-title">{{ $t('Crm_Qualification') }}</span></div>
              <a-form-item :label="$t('Status')">
                <a-select v-model:value="form.crm_lead_status_id" :options="statusOptions" />
              </a-form-item>
              <a-form-item :label="$t('Crm_Rating')">
                <div class="ratings">
                  <button
                    v-for="r in ratingOptions" :key="r.value" type="button" class="rating"
                    :class="{ active: form.rating === r.value }"
                    :style="form.rating === r.value ? { borderColor: r.color, color: r.color, background: tint(r.color, 0.1) } : {}"
                    @click="form.rating = r.value"
                  >
                    <component :is="r.icon" :size="15" /> {{ r.label }}
                  </button>
                </div>
              </a-form-item>
              <a-form-item :label="$t('Crm_Source')">
                <a-select v-model:value="form.crm_lead_source_id" :options="sourceOptions" allow-clear show-search option-filter-prop="label" />
              </a-form-item>
              <a-form-item :label="$t('Crm_Estimated_Value')">
                <a-input-number v-model:value="form.estimated_value" :min="0" :precision="2" style="width: 100%" />
              </a-form-item>
              <a-form-item :label="$t('Crm_Owner')">
                <a-select v-model:value="form.owner_id" :options="ownerOptions" allow-clear show-search option-filter-prop="label" :placeholder="!isEdit && meta.settings?.default_owner === 'rules' ? $t('Crm_Owner_By_Rules') : $t('Crm_Unassigned_Pool')" />
              </a-form-item>
              <a-form-item :label="$t('Crm_Tags')" class="mb0">
                <a-select v-model:value="form.tags" mode="tags" :options="tagOptions" option-filter-prop="label" :placeholder="$t('Crm_Tag_Pick_Or_Type')" />
              </a-form-item>
            </a-card>
          </a-col>
        </a-row>
      </a-form>
    </a-spin>
  </div>
</template>

<script setup>
/** Create / edit a lead. Duplicates (same email or phone) are flagged as you type. */
import { ref, computed, onMounted, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { message } from 'ant-design-vue';
import { SaveOutlined, MailOutlined, PhoneOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';
import { t, i18n } from '../../i18n';

function tp(key, fallback, params) {
  const out = i18n.global.t(key, params);
  return out !== key ? out : Object.entries(params).reduce((x, [k, v]) => x.replace(`{${k}}`, v ?? ''), fallback);
}
import { LEAD_TYPES, RATINGS, tr, tint } from './crmOptions';

const route = useRoute();
const router = useRouter();
const isEdit = computed(() => !!route.params.id);

const formRef = ref();
const loading = ref(false);
const saving = ref(false);
const meta = ref({ statuses: [], sources: [], tags: [], owners: [], can: {}, settings: {} });

const form = ref({
  type: 'person', first_name: '', last_name: '', company_name: '', job_title: '',
  email: '', phone: '', website: '', industry: '', address: '', city: '', country: '',
  crm_lead_status_id: undefined, crm_lead_source_id: undefined, owner_id: undefined,
  rating: 'warm', estimated_value: null, tags: [], description: '',
});

const rules = computed(() => ({
  company_name: form.value.type === 'company' ? [{ required: true, message: t('Field_is_required', 'This field is required') }] : [],
  email: [{ type: 'email', message: t('Crm_Invalid_Email', 'Enter a valid email') }],
}));

const typeOptions = computed(() => tr(LEAD_TYPES));
const ratingOptions = computed(() => tr(RATINGS));
const statusOptions = computed(() => meta.value.statuses
  .filter(s => s.kind === 'open' || s.id === original.value?.crm_lead_status_id)
  .map(s => ({ value: s.id, label: s.name, disabled: s.kind !== 'open' })));
const sourceOptions = computed(() => meta.value.sources
  .filter(s => s.is_active || s.id === form.value.crm_lead_source_id)
  .map(s => ({ value: s.id, label: s.name })));
const ownerOptions = computed(() => (meta.value.can?.view_all
  ? meta.value.owners
  : meta.value.owners.filter(o => o.id === meta.value.me))
  .map(o => ({ value: o.id, label: o.name })));
const tagOptions = computed(() => meta.value.tags.map(tg => ({ value: tg.id, label: tg.name })));
const original = ref(null);

// ----- duplicates -----
const duplicates = ref({ leads: [], clients: [] });
const dupCount = computed(() => duplicates.value.leads.length + duplicates.value.clients.length);
let dupTimer = null;
async function checkDuplicates() {
  if (!meta.value.settings?.duplicate_check) return;
  const email = (form.value.email || '').trim();
  const phone = (form.value.phone || '').trim();
  if (!email && phone.replace(/\D/g, '').length < 6) { duplicates.value = { leads: [], clients: [] }; return; }
  try {
    duplicates.value = await http.get('crm/leads/duplicates', { email, phone, except: route.params.id || '' });
  } catch (e) { /* the warning is a convenience */ }
}
watch(() => [form.value.email, form.value.phone], () => {
  clearTimeout(dupTimer);
  dupTimer = setTimeout(checkDuplicates, 600);
});

async function load() {
  loading.value = true;
  try {
    meta.value = await http.get('crm/leads/meta');
    if (isEdit.value) {
      const { lead } = await http.get(`crm/leads/${route.params.id}`);
      original.value = { crm_lead_status_id: lead.status?.id };
      Object.assign(form.value, {
        type: lead.type, first_name: lead.first_name || '', last_name: lead.last_name || '',
        company_name: lead.company_name || '', job_title: lead.job_title || '', email: lead.email || '',
        phone: lead.phone || '', website: lead.website || '', industry: lead.industry || '',
        address: lead.address || '', city: lead.city || '', country: lead.country || '',
        crm_lead_status_id: lead.status?.id, crm_lead_source_id: lead.source?.id,
        owner_id: lead.owner?.id, rating: lead.rating || 'warm',
        estimated_value: lead.estimated_value || null, tags: lead.tags.map(tg => tg.id),
        description: lead.description || '',
      });
    } else {
      form.value.crm_lead_status_id = meta.value.default_status_id;
      if (meta.value.settings?.default_owner === 'creator') form.value.owner_id = meta.value.me;
      if (route.query.source) form.value.crm_lead_source_id = Number(route.query.source);
    }
  } catch (e) {
    message.error(e?.data?.message || t('Crm_Could_Not_Load_Lead', 'Could not load the lead'));
  } finally {
    loading.value = false;
  }
}

async function submit() {
  if (saving.value) return;
  try { await formRef.value.validate(); } catch (e) { return; }
  const f = form.value;
  if (!(f.first_name || f.last_name || f.company_name).trim()) {
    message.error(t('Crm_Name_Required', 'Give the lead a name (first or last name, or a company).'));
    return;
  }
  saving.value = true;
  try {
    const body = { ...f, owner_id: f.owner_id || '', crm_lead_source_id: f.crm_lead_source_id || '' };
    let id = route.params.id;
    let res = null;
    if (isEdit.value) await http.put(`crm/leads/${id}`, body);
    else { res = await http.post('crm/leads', body); id = res?.id; }
    // The assignment rules gave the new lead to another rep: it is no longer visible here.
    if (res?.assigned_to && !res.can_view) {
      message.success(tp('Crm_Lead_Created_Assigned', 'Lead created and assigned to {name}', { name: res.assigned_to }));
      router.push('/crm/leads');
      return;
    }
    message.success(isEdit.value ? t('Updated_in_successfully', 'Updated successfully') : t('Created_successfully', 'Created successfully'));
    router.push(id ? `/crm/leads/${id}` : '/crm/leads');
  } catch (e) {
    const errors = e?.data?.errors;
    message.error((errors && Object.values(errors)[0]?.[0]) || e?.data?.message || t('Gen_Could_Not_Save', 'Could not save'));
  } finally {
    saving.value = false;
  }
}

onMounted(load);
</script>

<style scoped>
.card { margin-bottom: 16px; border-radius: 14px; }
.card-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 14px; flex-wrap: wrap; }
.card-title { font-weight: 600; font-size: 15px; }
.sub-title { font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; opacity: 0.55; margin: 2px 0 10px; }
.mb0 { margin-bottom: 0; }
.muted { color: rgba(128, 128, 128, 0.8); }
.dups { margin-bottom: 16px; }
.dup { display: flex; align-items: center; gap: 6px; margin-top: 4px; flex-wrap: wrap; }
.ratings { display: flex; gap: 8px; }
.rating {
  flex: 1; display: inline-flex; align-items: center; justify-content: center; gap: 6px; padding: 7px 8px;
  border: 1px solid rgba(128, 128, 128, 0.25); border-radius: 10px; background: transparent; color: inherit; font: inherit; cursor: pointer;
}
.rating.active { font-weight: 600; }
</style>
