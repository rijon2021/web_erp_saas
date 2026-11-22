<template>
  <div class="page">
    <PageHeader :title="$t('Customer_Segments')" :breadcrumb="[$t('Marketing'), $t('Customer_Segments')]">
      <template #actions>
        <a-button type="primary" @click="openCreate()">
          <template #icon><PlusOutlined /></template>
          {{ $t('New_Segment') }}
        </a-button>
      </template>
    </PageHeader>

    <DataTable :crud="crud" :columns="columns">
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'audience'">
          <a-tag v-if="record.audience === 'crm_leads'" color="purple">{{ $t('Crm_Leads') }}</a-tag>
          <a-tag v-else color="blue">{{ $t('Customers') }}</a-tag>
        </template>
        <template v-else-if="column.key === 'all_customers'">
          <a-tag :color="record.all_customers ? 'green' : 'default'">
            {{ record.all_customers ? (record.audience === 'crm_leads' ? $t('Seg_All_Leads') : $t('All_Customers')) : $t('Specific_Segment') }}
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
      :title="editing ? $t('Edit_Segment') : $t('New_Segment')"
      :confirm-loading="saving"
      :ok-text="$t('Save_Segment')"
      width="760px"
      @ok="save"
    >
      <a-form ref="formRef" :model="form" :rules="formRules" layout="vertical">
        <a-form-item v-if="leadMeta.available" :label="$t('Seg_Audience')">
          <a-segmented v-model:value="form.audience" :options="audienceOptions" @change="resetPreview" />
          <div v-if="form.audience === 'crm_leads'" class="hint">{{ $t('Seg_Leads_Hint') }}</div>
        </a-form-item>
        <a-form-item :label="$t('Segment_Name')" name="name">
          <a-input v-model:value="form.name" />
        </a-form-item>
        <a-form-item :label="$t('Description')" name="description">
          <a-input v-model:value="form.description" />
        </a-form-item>
        <a-form-item>
          <a-checkbox v-model:checked="form.all_customers" @change="resetPreview">
            {{ form.audience === 'crm_leads' ? $t('Seg_All_Leads') : $t('All_Customers') }}
          </a-checkbox>
        </a-form-item>

        <!-- Customers -->
        <template v-if="!form.all_customers && form.audience !== 'crm_leads'">
          <a-divider style="margin: 8px 0 16px" />
          <a-row :gutter="12">
            <a-col :xs="24" :md="12">
              <a-form-item :label="$t('City')">
                <a-input v-model:value="form.filters.city" />
              </a-form-item>
            </a-col>
            <a-col :xs="12" :md="6">
              <a-form-item :label="$t('Last_Purchase_From')">
                <a-date-picker v-model:value="form.filters.last_purchase_from" value-format="YYYY-MM-DD" style="width: 100%" />
              </a-form-item>
            </a-col>
            <a-col :xs="12" :md="6">
              <a-form-item :label="$t('Last_Purchase_To')">
                <a-date-picker v-model:value="form.filters.last_purchase_to" value-format="YYYY-MM-DD" style="width: 100%" />
              </a-form-item>
            </a-col>
            <a-col :xs="12" :md="6">
              <a-form-item :label="$t('Total_Purchases_Min')">
                <a-input-number v-model:value="form.filters.total_purchases_min" :min="0" style="width: 100%" />
              </a-form-item>
            </a-col>
            <a-col :xs="12" :md="6">
              <a-form-item :label="$t('Total_Purchases_Max')">
                <a-input-number v-model:value="form.filters.total_purchases_max" :min="0" style="width: 100%" />
              </a-form-item>
            </a-col>
            <a-col :xs="12" :md="6">
              <a-form-item :label="$t('Total_Spent_Min')">
                <a-input-number v-model:value="form.filters.total_spent_min" :min="0" style="width: 100%" />
              </a-form-item>
            </a-col>
            <a-col :xs="12" :md="6">
              <a-form-item :label="$t('Total_Spent_Max')">
                <a-input-number v-model:value="form.filters.total_spent_max" :min="0" style="width: 100%" />
              </a-form-item>
            </a-col>
          </a-row>
        </template>

        <!-- CRM leads -->
        <template v-if="!form.all_customers && form.audience === 'crm_leads'">
          <a-divider style="margin: 8px 0 16px" />
          <a-row :gutter="12">
            <a-col :xs="24" :md="12">
              <a-form-item :label="$t('Crm_Tags')">
                <a-select v-model:value="form.lead.tag_ids" mode="multiple" :options="opt(leadMeta.tags)" option-filter-prop="label" :placeholder="$t('Crm_Any')" />
              </a-form-item>
            </a-col>
            <a-col :xs="24" :md="12">
              <a-form-item :label="$t('Crm_Source')">
                <a-select v-model:value="form.lead.source_ids" mode="multiple" :options="opt(leadMeta.sources)" option-filter-prop="label" :placeholder="$t('Crm_Any')" />
              </a-form-item>
            </a-col>
            <a-col :xs="24" :md="12">
              <a-form-item :label="$t('Status')">
                <a-select v-model:value="form.lead.status_ids" mode="multiple" :options="opt(leadMeta.statuses)" option-filter-prop="label" :placeholder="$t('Seg_Open_Leads')" />
              </a-form-item>
            </a-col>
            <a-col :xs="24" :md="12">
              <a-form-item :label="$t('Crm_Score')">
                <a-slider v-model:value="form.lead.score" range :min="0" :max="100" :marks="{ 0: '0', 40: '40', 70: '70', 100: '100' }" />
              </a-form-item>
            </a-col>
            <a-col :xs="24" :md="8">
              <a-form-item :label="$t('Crm_Rating')">
                <a-select v-model:value="form.lead.ratings" mode="multiple" :options="ratingOptions" :placeholder="$t('Crm_Any')" />
              </a-form-item>
            </a-col>
            <a-col :xs="24" :md="8">
              <a-form-item :label="$t('Crm_Owner')">
                <a-select v-model:value="form.lead.owner_ids" mode="multiple" :options="ownerOptions" option-filter-prop="label" :placeholder="$t('Crm_Any')" />
              </a-form-item>
            </a-col>
            <a-col :xs="24" :md="8">
              <a-form-item :label="$t('Crm_Came_In_Through')">
                <a-select v-model:value="form.lead.channels" mode="multiple" :options="channelOptions" :placeholder="$t('Crm_Any')" />
              </a-form-item>
            </a-col>
            <a-col :xs="24" :md="8">
              <a-form-item :label="$t('Country')">
                <a-input v-model:value="form.lead.country" />
              </a-form-item>
            </a-col>
            <a-col :xs="12" :md="8">
              <a-form-item :label="$t('Crm_Created_From')">
                <a-date-picker v-model:value="form.lead.created_from" value-format="YYYY-MM-DD" style="width: 100%" />
              </a-form-item>
            </a-col>
            <a-col :xs="12" :md="8">
              <a-form-item :label="$t('Crm_Created_To')">
                <a-date-picker v-model:value="form.lead.created_to" value-format="YYYY-MM-DD" style="width: 100%" />
              </a-form-item>
            </a-col>
          </a-row>
        </template>

        <div class="preview-row">
          <a-button :loading="previewing" @click="preview">
            <template #icon><SearchOutlined /></template>
            {{ $t('Preview_Segment') }}
          </a-button>
          <template v-if="previewCount !== null">
            <strong class="pv-count">{{ previewCount }} {{ form.audience === 'crm_leads' ? $t('Seg_Matching_Leads') : $t('Matching_Customers') }}</strong>
            <span class="pv-sub"><MailOutlined /> {{ previewEmail }} · <PhoneOutlined /> {{ previewPhone }}</span>
          </template>
        </div>
        <div v-if="previewSample.length" class="sample">
          <span v-for="s in previewSample" :key="s.id" class="sample-chip">{{ s.name }}</span>
          <span v-if="previewCount > previewSample.length" class="sample-more">+{{ previewCount - previewSample.length }}</span>
        </div>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * GET marketing/segments → {segments, totalRows}. Save = POST/PUT with
 * {name, description, audience (customers | crm_leads), all_customers (1/0), filters};
 * the backend recomputes customers_count on every save. Preview = POST
 * marketing/segments/preview → {count, sample, with_email, with_phone}.
 * CRM lead segments need "view all leads" (GET marketing/segments/lead-meta).
 */
import { ref, computed, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { PlusOutlined, EditOutlined, DeleteOutlined, SearchOutlined, MailOutlined, PhoneOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import http from '../../lib/http';
import { RATINGS, CAPTURE_CHANNELS, tr } from '../crm/crmOptions';

const { t } = useI18n();
const route = useRoute();

const crud = useCrudTable('marketing/segments', { rowsKey: 'segments' });
crud.fetchRows();

const leadMeta = ref({ available: false, sources: [], statuses: [], tags: [], owners: [] });

const columns = computed(() => [
  { title: t('Segment_Name'), dataIndex: 'name', key: 'name', sorter: true },
  ...(leadMeta.value.available ? [{ title: t('Seg_Audience'), dataIndex: 'audience', key: 'audience', width: 130 }] : []),
  { title: t('Type'), dataIndex: 'all_customers', key: 'all_customers' },
  { title: t('Matching_Customers'), dataIndex: 'customers_count', key: 'customers_count', sorter: true, align: 'right' },
  { title: t('Action'), key: 'actions', width: 110, align: 'center' },
]);

const modalOpen = ref(false);
const saving = ref(false);
const previewing = ref(false);
const previewCount = ref(null);
const previewEmail = ref(0);
const previewPhone = ref(0);
const previewSample = ref([]);
const editing = ref(null);
const formRef = ref();

function emptyFilters() {
  return {
    city: '', last_purchase_from: null, last_purchase_to: null,
    total_purchases_min: null, total_purchases_max: null,
    total_spent_min: null, total_spent_max: null,
  };
}
function emptyLead() {
  return {
    tag_ids: [], source_ids: [], status_ids: [], ratings: [], owner_ids: [], channels: [],
    score: [0, 100], country: '', created_from: null, created_to: null,
  };
}

const form = ref({ name: '', description: '', audience: 'customers', all_customers: false, filters: emptyFilters(), lead: emptyLead() });

const formRules = computed(() => ({
  name: [{ required: true, message: t('Field_is_required') }],
}));
const audienceOptions = computed(() => [
  { value: 'customers', label: t('Customers') },
  { value: 'crm_leads', label: t('Crm_Leads') },
]);
const ratingOptions = computed(() => tr(RATINGS).map(r => ({ value: r.value, label: r.label })));
const channelOptions = computed(() => tr(CAPTURE_CHANNELS).map(c => ({ value: c.value, label: c.label })));
const ownerOptions = computed(() => [{ value: 'none', label: t('Crm_Unassigned') }, ...leadMeta.value.owners.map(o => ({ value: String(o.id), label: o.name }))]);
function opt(rows) { return (rows || []).map(r => ({ value: r.id, label: r.name })); }

function resetPreview() { previewCount.value = null; previewSample.value = []; }

function openCreate(prefill = null) {
  editing.value = null;
  resetPreview();
  form.value = { name: '', description: '', audience: 'customers', all_customers: false, filters: emptyFilters(), lead: emptyLead() };
  if (prefill) {
    form.value.audience = 'crm_leads';
    form.value.lead = { ...form.value.lead, ...prefill };
  }
  modalOpen.value = true;
}

function openEdit(record) {
  editing.value = record;
  resetPreview();
  const f = record.filters || {};
  const isLead = record.audience === 'crm_leads';
  form.value = {
    name: record.name,
    description: record.description || '',
    audience: isLead ? 'crm_leads' : 'customers',
    all_customers: !!record.all_customers,
    filters: isLead ? emptyFilters() : { ...emptyFilters(), ...f },
    lead: isLead ? {
      ...emptyLead(), ...f,
      owner_ids: (f.owner_ids || []).map(String),
      score: [f.score_min ?? 0, f.score_max ?? 100],
    } : emptyLead(),
  };
  modalOpen.value = true;
}

function filtersPayload() {
  if (form.value.audience !== 'crm_leads') return form.value.filters;
  const { score, ...rest } = form.value.lead;
  return { ...rest, score_min: score[0] > 0 ? score[0] : null, score_max: score[1] < 100 ? score[1] : null };
}
function payload() {
  return {
    name: form.value.name,
    description: form.value.description,
    audience: form.value.audience,
    all_customers: form.value.all_customers ? 1 : 0,
    filters: filtersPayload(),
  };
}

async function preview() {
  previewing.value = true;
  try {
    const data = await http.post('marketing/segments/preview', {
      audience: form.value.audience,
      all_customers: form.value.all_customers ? 1 : 0,
      filters: filtersPayload(),
    });
    previewCount.value = data.count;
    previewEmail.value = data.with_email ?? 0;
    previewPhone.value = data.with_phone ?? 0;
    previewSample.value = data.sample || [];
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    previewing.value = false;
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
    if (editing.value) {
      await http.put(`marketing/segments/${editing.value.id}`, payload());
      message.success(t('Successfully_Updated'));
    } else {
      await http.post('marketing/segments', payload());
      message.success(t('Successfully_Created'));
    }
    modalOpen.value = false;
    crud.fetchRows();
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    saving.value = false;
  }
}

/** /marketing/segments?audience=crm_leads&tag=1&source=2… (from the CRM leads list) opens a prefilled lead segment. */
function prefillFromQuery() {
  const q = route.query;
  if (q.audience !== 'crm_leads' || !leadMeta.value.available) return;
  const list = (v) => (v === undefined || v === '' ? [] : String(v).split(',').filter(Boolean));
  openCreate({
    tag_ids: list(q.tag).map(Number),
    source_ids: list(q.source).map(Number),
    status_ids: list(q.status).filter(s => /^\d+$/.test(s)).map(Number),
    ratings: list(q.rating),
    owner_ids: list(q.owner).map(String),
    channels: list(q.captured_via),
  });
  form.value.name = q.name ? String(q.name) : '';
}

onMounted(async () => {
  try { leadMeta.value = await http.get('marketing/segments/lead-meta'); } catch (e) { /* customers only */ }
  prefillFromQuery();
});
</script>

<style scoped>
.hint { font-size: 12.5px; opacity: 0.65; margin-top: 6px; }
.preview-row { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; margin-top: 4px; }
.pv-count { color: var(--app-primary, #1677ff); }
.pv-sub { font-size: 12.5px; opacity: 0.7; }
.sample { display: flex; flex-wrap: wrap; gap: 4px; margin-top: 10px; }
.sample-chip { font-size: 12px; padding: 1px 8px; border-radius: 999px; background: rgba(128, 128, 128, 0.12); }
.sample-more { font-size: 12px; opacity: 0.6; padding: 1px 4px; }
</style>
