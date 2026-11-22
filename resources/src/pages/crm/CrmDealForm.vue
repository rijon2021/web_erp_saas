<template>
  <div class="page">
    <PageHeader :title="isEdit ? $t('Crm_Edit_Deal') : $t('Crm_New_Deal')" :breadcrumb="[$t('Crm'), $t('Crm_Deals'), isEdit ? $t('Edit') : $t('Crm_New_Deal')]">
      <template #actions>
        <a-button @click="$router.back()">{{ $t('Cancel') }}</a-button>
        <a-button type="primary" :loading="saving" :disabled="loading" @click="submit"><template #icon><SaveOutlined /></template>{{ $t('Save') }}</a-button>
      </template>
    </PageHeader>

    <a-spin :spinning="loading">
      <a-form layout="vertical">
        <a-row :gutter="16">
          <a-col :xs="24" :lg="16">
            <a-card :bordered="false" class="card">
              <a-form-item :label="$t('Crm_Deal_Title')" required>
                <a-input v-model:value="form.title" :maxlength="191" :placeholder="$t('Crm_Deal_Title_Hint')" size="large" />
              </a-form-item>

              <div class="who-head">
                <span class="lbl">{{ $t('Crm_Deal_With') }}</span>
                <a-segmented v-model:value="whoType" :options="whoOptions" size="small" />
              </div>
              <a-select
                v-if="whoType === 'lead'" v-model:value="form.crm_lead_id" show-search :filter-option="false" :options="leadOptions"
                :placeholder="$t('Crm_Search_Lead')" style="width: 100%" allow-clear :not-found-content="null" @search="searchLeads" @focus="searchLeads('')"
              />
              <a-select
                v-else v-model:value="form.client_id" show-search :filter-option="false" :options="clientOptions"
                :placeholder="$t('Crm_Search_Customer')" style="width: 100%" allow-clear :not-found-content="null" @search="searchClients" @focus="searchClients('')"
              />
              <a-select
                v-if="contactOptions.length" v-model:value="form.crm_contact_id" :options="contactOptions" allow-clear
                :placeholder="$t('Crm_Contact_Person')" style="width: 100%; margin-top: 8px"
              />
            </a-card>

            <a-card :bordered="false" class="card">
              <div class="card-head">
                <span class="card-title">{{ $t('Crm_Products') }}</span>
                <a-select
                  v-model:value="pick" show-search :filter-option="false" :options="productOptions" :placeholder="$t('Crm_Add_Product')"
                  style="width: 320px" :not-found-content="null" @search="searchProducts" @focus="searchProducts('')" @select="addProduct"
                />
              </div>
              <div v-if="form.products.length" class="lines">
                <div class="line line-head">
                  <span>{{ $t('Crm_Item') }}</span><span>{{ $t('Quantity') }}</span><span>{{ $t('Crm_Unit_Price') }}</span>
                  <span>{{ $t('Crm_Discount_Pct') }}</span><span>{{ $t('Crm_Tax_Pct') }}</span><span class="r">{{ $t('Total') }}</span><span></span>
                </div>
                <div v-for="(l, i) in form.products" :key="l.key" class="line">
                  <span class="item">
                    <a-input v-model:value="l.name" size="small" :maxlength="191" />
                    <small v-if="l.code" class="code">{{ l.code }}</small>
                  </span>
                  <a-input-number v-model:value="l.quantity" :min="0.001" size="small" style="width: 100%" />
                  <a-input-number v-model:value="l.unit_price" :min="0" :precision="2" size="small" style="width: 100%" />
                  <a-input-number v-model:value="l.discount_percent" :min="0" :max="100" size="small" style="width: 100%" />
                  <span class="tax">
                    <a-input-number v-model:value="l.tax_percent" :min="0" :max="100" size="small" style="width: 64px" />
                    <a-tooltip :title="l.tax_method === 2 ? $t('Crm_Tax_Included') : $t('Crm_Tax_Added')">
                      <a-button size="small" type="text" class="tm" @click="l.tax_method = l.tax_method === 2 ? 1 : 2">{{ l.tax_method === 2 ? $t('Crm_Incl') : '+' }}</a-button>
                    </a-tooltip>
                  </span>
                  <span class="r strong">{{ money(lineTotal(l)) }}</span>
                  <a-button type="text" danger size="small" @click="form.products.splice(i, 1)"><template #icon><DeleteOutlined /></template></a-button>
                </div>
                <div class="lines-total">
                  <span>{{ $t('Crm_Deal_Value') }}</span>
                  <strong>{{ money(productsTotal) }}</strong>
                </div>
              </div>
              <div v-else class="empty-lines">
                <span>{{ $t('Crm_No_Products_Hint') }}</span>
                <a-button type="link" size="small" @click="addBlank">{{ $t('Crm_Add_Custom_Line') }}</a-button>
              </div>
              <a-button v-if="form.products.length" type="link" size="small" class="add-blank" @click="addBlank"><PlusOutlined /> {{ $t('Crm_Add_Custom_Line') }}</a-button>
            </a-card>

            <a-card :bordered="false" class="card">
              <a-form-item :label="$t('Description')" class="mb0">
                <a-textarea v-model:value="form.description" :rows="4" :maxlength="10000" />
              </a-form-item>
            </a-card>
          </a-col>

          <a-col :xs="24" :lg="8">
            <a-card :bordered="false" class="card">
              <a-form-item v-if="meta.pipelines.length > 1" :label="$t('Crm_Pipeline')">
                <a-select v-model:value="pipelineId" :options="pipelineOptions" @change="onPipeline" />
              </a-form-item>
              <a-form-item :label="$t('Crm_Stage')" required>
                <div class="stages">
                  <button
                    v-for="s in stages" :key="s.id" type="button" class="stage" :class="{ on: form.crm_pipeline_stage_id === s.id }"
                    :style="form.crm_pipeline_stage_id === s.id ? { borderColor: s.color, background: tint(s.color, 0.12), color: s.color } : {}"
                    @click="form.crm_pipeline_stage_id = s.id"
                  >
                    <span>{{ s.name }}</span><small>{{ s.probability }}%</small>
                  </button>
                </div>
              </a-form-item>
              <a-form-item :label="$t('Crm_Value')" :extra="form.products.length ? $t('Crm_Value_From_Products') : ''">
                <a-input-number
                  :value="form.products.length ? productsTotal : form.value" :disabled="!!form.products.length" :min="0" :precision="2"
                  style="width: 100%" @update:value="v => (form.value = v)"
                />
              </a-form-item>
              <a-form-item :label="$t('Crm_Expected_Close')">
                <a-date-picker v-model:value="form.expected_close_date" value-format="YYYY-MM-DD" style="width: 100%" />
              </a-form-item>
              <a-form-item :label="$t('Crm_Owner')">
                <a-select v-model:value="form.owner_id" :options="ownerOptions" allow-clear show-search option-filter-prop="label" :placeholder="$t('Crm_Unassigned_Pool')" />
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
/** Create / edit a deal: who it is with, pipeline stage, products (or a plain value). */
import { ref, computed, onMounted, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { message } from 'ant-design-vue';
import { SaveOutlined, DeleteOutlined, PlusOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import { useFormat } from '../../composables/useFormat';
import http from '../../lib/http';
import { useAuthStore } from '../../stores/auth';
import { t } from '../../i18n';
import { tint, lineTotal } from './crmOptions';

const route = useRoute();
const router = useRouter();
const { money } = useFormat();
const auth = useAuthStore();
const isEdit = computed(() => !!route.params.id);

const loading = ref(false);
const saving = ref(false);
const meta = ref({ pipelines: [], owners: [], tags: [], can: {} });
const pipelineId = ref(undefined);
const whoType = ref('lead');
let lineKey = 0;

const form = ref({
  title: '', crm_pipeline_stage_id: undefined, crm_lead_id: undefined, client_id: undefined, crm_contact_id: undefined, owner_id: undefined,
  value: null, expected_close_date: null, tags: [], description: '', products: [],
});

const whoOptions = computed(() => [{ value: 'lead', label: t('Crm_Lead', 'Lead') }, { value: 'client', label: t('Customer', 'Customer') }]);
const pipelineOptions = computed(() => meta.value.pipelines.map(p => ({ value: p.id, label: p.name })));
const stages = computed(() => meta.value.pipelines.find(p => p.id === pipelineId.value)?.stages || []);
const ownerOptions = computed(() => (meta.value.can?.view_all ? meta.value.owners : meta.value.owners.filter(o => o.id === meta.value.me))
  .map(o => ({ value: o.id, label: o.name })));
const tagOptions = computed(() => meta.value.tags.map(tg => ({ value: tg.id, label: tg.name })));
const productsTotal = computed(() => Math.round(form.value.products.reduce((s, l) => s + lineTotal(l), 0) * 100) / 100);

function onPipeline() { form.value.crm_pipeline_stage_id = stages.value[0]?.id; }

// Contact people of the chosen customer.
const contacts = ref([]);
const contactOptions = computed(() => contacts.value.map(c => ({ value: c.id, label: `${c.name}${c.job_title ? ` · ${c.job_title}` : ''}` })));
async function loadContacts(clientId) {
  contacts.value = [];
  if (!clientId || !auth.can('crm_customers_view')) return;
  try { contacts.value = (await http.get(`crm/customers/${clientId}`)).customer?.contacts || []; } catch (e) { /* none */ }
  if (form.value.crm_contact_id && !contacts.value.some(c => c.id === form.value.crm_contact_id)) form.value.crm_contact_id = undefined;
}
watch(() => form.value.client_id, (v) => loadContacts(v));

// ----- lead / client / product search -----
const leadOptions = ref([]);
const clientOptions = ref([]);
const productOptions = ref([]);
const productRows = ref([]);
const pick = ref(undefined);
let timers = {};
function debounce(key, fn) { clearTimeout(timers[key]); timers[key] = setTimeout(fn, 300); }

function searchLeads(q) {
  debounce('lead', async () => {
    try {
      const res = await http.get('crm/leads', { search: q || '', limit: 15, status: '', SortField: 'created_at', SortType: 'desc' });
      const keep = leadOptions.value.find(o => o.value === form.value.crm_lead_id);
      leadOptions.value = (res.leads || []).map(l => ({ value: l.id, label: `${l.name}${l.company_name && l.type === 'person' ? ` · ${l.company_name}` : ''} (${l.lead_number})` }));
      if (keep && !leadOptions.value.some(o => o.value === keep.value)) leadOptions.value.unshift(keep);
    } catch (e) { /* keep the list */ }
  });
}
function searchClients(q) {
  debounce('client', async () => {
    try {
      const res = await http.get('crm/deals/clients', { search: q || '' });
      const keep = clientOptions.value.find(o => o.value === form.value.client_id);
      clientOptions.value = (res.clients || []).map(c => ({ value: c.id, label: `${c.name}${c.phone ? ` · ${c.phone}` : ''}` }));
      if (keep && !clientOptions.value.some(o => o.value === keep.value)) clientOptions.value.unshift(keep);
    } catch (e) { /* keep the list */ }
  });
}
function searchProducts(q) {
  debounce('product', async () => {
    try {
      const res = await http.get('crm/deals/products', { search: q || '' });
      productRows.value = res.products || [];
      productOptions.value = productRows.value.map((p, i) => ({ value: i, label: `${p.name}${p.code ? ` (${p.code})` : ''} — ${money(p.unit_price)}` }));
    } catch (e) { /* keep the list */ }
  });
}
function addProduct(i) {
  const p = productRows.value[i];
  if (p) {
    form.value.products.push({ key: ++lineKey, ...p, quantity: 1, discount_percent: 0 });
  }
  pick.value = undefined;
}
function addBlank() {
  form.value.products.push({ key: ++lineKey, product_id: null, product_variant_id: null, name: '', code: null, quantity: 1, unit_price: 0, discount_percent: 0, tax_percent: 0, tax_method: 1 });
}

async function load() {
  loading.value = true;
  try {
    meta.value = await http.get('crm/deals/meta');
    if (isEdit.value) {
      const { deal } = await http.get(`crm/deals/${route.params.id}`);
      pipelineId.value = deal.pipeline?.id;
      whoType.value = deal.crm_lead_id ? 'lead' : 'client';
      if (deal.lead) leadOptions.value = [{ value: deal.lead.id, label: `${deal.lead.name} (${deal.lead.lead_number})` }];
      if (deal.client) clientOptions.value = [{ value: deal.client.id, label: deal.client.name }];
      Object.assign(form.value, {
        title: deal.title, crm_pipeline_stage_id: deal.stage?.id, crm_lead_id: deal.crm_lead_id || undefined,
        client_id: deal.client_id || undefined, crm_contact_id: deal.crm_contact_id || undefined, owner_id: deal.owner?.id, value: deal.value,
        expected_close_date: deal.expected_close_date, tags: deal.tags.map(tg => tg.id), description: deal.description || '',
        products: deal.products.map(p => ({ key: ++lineKey, ...p })),
      });
    } else {
      const q = route.query;
      const stageId = q.stage ? Number(q.stage) : null;
      const byStage = stageId ? meta.value.pipelines.find(p => p.stages.some(s => s.id === stageId)) : null;
      pipelineId.value = byStage?.id || (q.pipeline ? Number(q.pipeline) : null) || meta.value.default_pipeline_id;
      form.value.crm_pipeline_stage_id = stageId && byStage ? stageId : stages.value[0]?.id;
      form.value.owner_id = meta.value.me;
      if (q.lead) {
        whoType.value = 'lead';
        try {
          const { lead } = await http.get(`crm/leads/${q.lead}`);
          form.value.crm_lead_id = lead.id;
          leadOptions.value = [{ value: lead.id, label: `${lead.name} (${lead.lead_number})` }];
          form.value.title = lead.company_name || lead.name;
          if (lead.estimated_value) form.value.value = lead.estimated_value;
        } catch (e) { /* the user picks it */ }
      } else if (q.client) {
        whoType.value = 'client';
        form.value.client_id = Number(q.client);
        searchClients('');
      }
    }
  } catch (e) {
    message.error(e?.data?.message || t('Crm_Err_Could_Not_Load_The_Deal', 'Could not load the deal'));
  } finally {
    loading.value = false;
  }
}

async function submit() {
  if (saving.value) return;
  const f = form.value;
  if (!f.title.trim()) { message.error(t('Crm_Title_Required', 'Give the deal a title')); return; }
  if (!f.crm_pipeline_stage_id) { message.error(t('Crm_Choose_Stage', 'Choose a stage')); return; }
  const leadId = whoType.value === 'lead' ? f.crm_lead_id : null;
  const clientId = whoType.value === 'client' ? f.client_id : null;
  if (!leadId && !clientId) { message.error(t('Crm_Link_Lead_Or_Customer', 'Link the deal to a lead or a customer')); return; }
  if (f.products.some(l => !String(l.name || '').trim())) { message.error(t('Crm_Line_Name_Required', 'Every product line needs a name')); return; }

  saving.value = true;
  try {
    const body = {
      title: f.title, crm_pipeline_stage_id: f.crm_pipeline_stage_id, crm_lead_id: leadId || '', client_id: clientId || '',
      crm_contact_id: clientId ? (f.crm_contact_id || '') : '',
      owner_id: f.owner_id || '', value: f.value || 0, expected_close_date: f.expected_close_date || '', tags: f.tags,
      description: f.description,
      products: f.products.map(({ key, total, id, ...l }) => l),
    };
    let id = route.params.id;
    if (isEdit.value) await http.put(`crm/deals/${id}`, body);
    else id = (await http.post('crm/deals', body))?.id;
    message.success(isEdit.value ? t('Updated_in_successfully', 'Updated successfully') : t('Created_in_successfully', 'Created successfully'));
    router.push(id ? `/crm/deals/${id}` : '/crm/deals');
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
.card-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 12px; flex-wrap: wrap; }
.card-title { font-weight: 600; font-size: 15px; }
.mb0 { margin-bottom: 0; }
.who-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px; }
.lbl { font-weight: 500; }
.stages { display: flex; flex-direction: column; gap: 6px; }
.stage {
  display: flex; align-items: center; justify-content: space-between; padding: 7px 12px; border: 1px solid rgba(128, 128, 128, 0.25);
  border-radius: 10px; background: transparent; color: inherit; font: inherit; cursor: pointer; text-align: start;
}
.stage small { opacity: 0.6; }
.stage.on { font-weight: 600; }
.lines { display: flex; flex-direction: column; gap: 6px; }
.line { display: grid; grid-template-columns: minmax(160px, 2.2fr) 0.8fr 1fr 0.7fr 1.1fr 1fr 32px; gap: 8px; align-items: center; }
.line-head { font-size: 12px; opacity: 0.6; font-weight: 600; }
.item { display: flex; flex-direction: column; }
.code { opacity: 0.55; font-size: 11px; }
.tax { display: flex; align-items: center; gap: 2px; }
.tm { padding: 0 6px; font-size: 11px; font-weight: 700; }
.r { text-align: end; }
.strong { font-weight: 600; }
.lines-total { display: flex; justify-content: flex-end; gap: 16px; padding-top: 8px; margin-top: 4px; border-top: 1px solid rgba(128, 128, 128, 0.15); font-size: 15px; }
.lines-total strong { color: var(--app-primary, #6d28d9); }
.empty-lines { padding: 16px; text-align: center; border: 1px dashed rgba(128, 128, 128, 0.3); border-radius: 10px; opacity: 0.75; }
.add-blank { margin-top: 6px; padding-inline-start: 0; }
@media (max-width: 900px) {
  .line { grid-template-columns: 1fr 1fr; }
  .line-head { display: none; }
}
</style>
