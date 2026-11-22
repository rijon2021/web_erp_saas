<template>
  <a-modal :open="open" :title="$t('Crm_Convert_To_Customer')" :ok-text="$t('Crm_Convert')" :cancel-text="$t('Cancel')"
    :confirm-loading="saving" width="600px" @ok="submit" @cancel="$emit('close')">
    <a-spin :spinning="loading">
      <p class="intro">{{ $t('Crm_Convert_Intro') }}</p>

      <a-radio-group v-model:value="mode" class="modes">
        <a-radio value="new" :disabled="!meta.can_create_customer">
          <span class="m-title">{{ $t('Crm_Create_New_Customer') }}</span>
          <span class="m-sub">{{ lead?.name }}<template v-if="lead?.email"> · {{ lead.email }}</template></span>
        </a-radio>
        <a-radio value="existing">
          <span class="m-title">{{ $t('Crm_Link_Existing_Customer') }}</span>
          <span class="m-sub">{{ $t('Crm_Link_Existing_Hint') }}</span>
        </a-radio>
      </a-radio-group>

      <div v-if="meta.matches?.length" class="matches">
        <div class="m-head">{{ $t('Crm_Possible_Matches') }}</div>
        <button v-for="c in meta.matches" :key="c.id" type="button" class="match" :class="{ on: mode === 'existing' && clientId === c.id }" @click="pickMatch(c)">
          <UserOutlined /> <strong>{{ c.name }}</strong>
          <span class="muted">· {{ c.match === 'email' ? c.email : c.phone }}</span>
        </button>
      </div>

      <a-select
        v-if="mode === 'existing'" v-model:value="clientId" show-search :filter-option="false" :options="clientOptions"
        :placeholder="$t('Crm_Search_Customer')" style="width: 100%" class="gap" :not-found-content="null"
        @search="searchClients" @focus="searchClients('')"
      />

      <a-divider />
      <a-checkbox v-model:checked="withDeal" :disabled="!canAddDeal">{{ $t('Crm_Also_Create_Deal') }}</a-checkbox>
      <div v-if="withDeal" class="deal">
        <a-input v-model:value="deal.title" :maxlength="191" :placeholder="$t('Crm_Deal_Title')" />
        <div class="row">
          <a-select v-model:value="deal.crm_pipeline_stage_id" :options="stageOptions" class="grow" />
          <a-input-number v-model:value="deal.value" :min="0" :precision="2" style="width: 160px" :placeholder="$t('Crm_Value')" />
        </div>
      </div>
      <a-alert v-if="meta.open_deals" type="info" show-icon class="gap" :message="tp('Crm_Convert_Deals_Follow', 'Its {n} open deal(s) will move to the customer too.', { n: meta.open_deals })" />
    </a-spin>
  </a-modal>
</template>

<script setup>
/** Lead → customer: new customer, or link an existing one (matches suggested); optionally start a deal. */
import { ref, computed, watch } from 'vue';
import { message } from 'ant-design-vue';
import { UserOutlined } from '@ant-design/icons-vue';
import { useAuthStore } from '../../stores/auth';
import http from '../../lib/http';
import { t, i18n } from '../../i18n';

const props = defineProps({ open: Boolean, lead: { type: Object, default: null } });
const emit = defineEmits(['close', 'converted']);
const auth = useAuthStore();
const canAddDeal = computed(() => auth.can('crm_deals_add'));

const loading = ref(false);
const saving = ref(false);
const meta = ref({});
const mode = ref('new');
const clientId = ref(undefined);
const clientOptions = ref([]);
const withDeal = ref(false);
const deal = ref({ title: '', crm_pipeline_stage_id: undefined, value: null });
const pipelines = ref([]);
const stageOptions = computed(() => pipelines.value.flatMap(p => p.stages.map(s => ({ value: s.id, label: pipelines.value.length > 1 ? `${p.name} — ${s.name}` : s.name }))));

function tp(key, fallback, params) {
  const out = i18n.global.t(key, params);
  return out !== key ? out : Object.entries(params).reduce((s, [k, v]) => s.replace(`{${k}}`, v), fallback);
}
function pickMatch(c) {
  mode.value = 'existing';
  clientId.value = c.id;
  if (!clientOptions.value.some(o => o.value === c.id)) clientOptions.value.unshift({ value: c.id, label: c.name });
}
let timer = null;
function searchClients(q) {
  clearTimeout(timer);
  timer = setTimeout(async () => {
    try {
      const res = await http.get('crm/deals/clients', { search: q || '' });
      const keep = clientOptions.value.find(o => o.value === clientId.value);
      clientOptions.value = (res.clients || []).map(c => ({ value: c.id, label: `${c.name}${c.phone ? ` · ${c.phone}` : ''}` }));
      if (keep && !clientOptions.value.some(o => o.value === keep.value)) clientOptions.value.unshift(keep);
    } catch (e) { /* keep the list */ }
  }, 300);
}

watch(() => props.open, async (v) => {
  if (!v || !props.lead) return;
  loading.value = true;
  mode.value = 'new';
  clientId.value = undefined;
  withDeal.value = false;
  try {
    meta.value = await http.get(`crm/leads/${props.lead.id}/convert`);
    if (!meta.value.can_create_customer) mode.value = 'existing';
    if (meta.value.matches?.length) pickMatch(meta.value.matches[0]);
    if (canAddDeal.value) {
      const dm = await http.get('crm/deals/meta');
      pipelines.value = (dm.pipelines || []).filter(p => p.stages.length);
      const def = pipelines.value.find(p => p.id === dm.default_pipeline_id) || pipelines.value[0];
      deal.value = { title: props.lead.company_name || props.lead.name, crm_pipeline_stage_id: def?.stages[0]?.id, value: props.lead.estimated_value || null };
      withDeal.value = !meta.value.open_deals;
    }
  } catch (e) {
    message.error(e?.data?.message || t('Gen_Could_Not_Load', 'Could not load'));
  } finally {
    loading.value = false;
  }
});

async function submit() {
  if (mode.value === 'existing' && !clientId.value) { message.error(t('Crm_Choose_Customer', 'Choose the customer')); return; }
  saving.value = true;
  try {
    const res = await http.post(`crm/leads/${props.lead.id}/convert`, {
      mode: mode.value,
      client_id: mode.value === 'existing' ? clientId.value : '',
      deal: withDeal.value && deal.value.title.trim() ? deal.value : {},
    });
    message.success(t('Crm_Converted_Msg', 'Converted to a customer'));
    emit('converted', res);
  } catch (e) {
    if (e?.data?.existing_client) pickMatch(e.data.existing_client);
    message.error(e?.data?.message || t('Crm_Err_Could_Not_Convert', 'Could not convert'), 6);
  } finally {
    saving.value = false;
  }
}
</script>

<style scoped>
.intro { opacity: 0.75; }
.modes { display: flex; flex-direction: column; gap: 10px; }
.m-title { display: block; font-weight: 600; }
.m-sub { display: block; font-size: 12px; opacity: 0.6; }
.matches { margin-top: 14px; display: flex; flex-direction: column; gap: 6px; }
.m-head { font-size: 12px; font-weight: 700; text-transform: uppercase; opacity: 0.55; }
.match {
  display: flex; align-items: center; gap: 6px; padding: 8px 12px; border: 1px solid rgba(128, 128, 128, 0.22); border-radius: 10px;
  background: transparent; color: inherit; font: inherit; text-align: start; cursor: pointer;
}
.match.on { border-color: var(--app-primary, #6d28d9); background: rgba(var(--app-primary-rgb, 109, 40, 217), 0.06); }
.muted { opacity: 0.6; }
.gap { margin-top: 12px; }
.deal { display: flex; flex-direction: column; gap: 8px; margin-top: 10px; }
.row { display: flex; gap: 8px; }
.grow { flex: 1; }
</style>
