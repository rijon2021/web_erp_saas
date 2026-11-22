<template>
  <a-modal :open="open" :title="$t('Crm_Create_Quotation')" :ok-text="$t('Crm_Create_Quotation')" :cancel-text="$t('Cancel')"
    :confirm-loading="saving" :ok-button-props="{ disabled: !canSubmit }" width="560px" @ok="submit" @cancel="$emit('close')">
    <a-spin :spinning="loading">
      <a-alert v-if="meta.has_customer === false" type="warning" show-icon class="gap" :message="$t('Crm_Quote_Needs_Customer')" />
      <a-alert v-else-if="meta.can_create_quotation === false" type="error" show-icon class="gap" :message="$t('Crm_No_Quote_Permission')" />
      <a-alert v-else-if="meta.catalogue_lines === 0" type="warning" show-icon class="gap" :message="$t('Crm_Quote_Needs_Products')" />
      <template v-else>
        <div class="sum">
          <span>{{ tp('Crm_Quote_Lines', '{n} catalogue line(s)', { n: meta.catalogue_lines || 0 }) }}</span>
          <strong>{{ money(meta.lines_total || 0) }}</strong>
        </div>
        <a-alert v-if="meta.custom_lines" type="info" show-icon class="gap" :message="tp('Crm_Quote_Custom_Lines', '{n} custom line(s) will be listed in the quotation notes (quotations only hold catalogue products).', { n: meta.custom_lines })" />
        <a-form layout="vertical">
          <a-form-item :label="$t('warehouse')" required>
            <a-select v-model:value="form.warehouse_id" :options="warehouseOptions" />
          </a-form-item>
          <a-row :gutter="12">
            <a-col :span="8"><a-form-item :label="$t('Crm_Order_Tax')"><a-input-number v-model:value="form.tax_rate" :min="0" :max="100" style="width: 100%" addon-after="%" /></a-form-item></a-col>
            <a-col :span="8"><a-form-item :label="$t('Discount')"><a-input-number v-model:value="form.discount" :min="0" :precision="2" style="width: 100%" /></a-form-item></a-col>
            <a-col :span="8"><a-form-item :label="$t('Shipping')"><a-input-number v-model:value="form.shipping" :min="0" :precision="2" style="width: 100%" /></a-form-item></a-col>
          </a-row>
          <div class="sum total"><span>{{ $t('Total') }}</span><strong>{{ money(grand) }}</strong></div>
          <a-form-item :label="$t('Note')" class="mb0">
            <a-textarea v-model:value="form.notes" :rows="2" :maxlength="5000" />
          </a-form-item>
        </a-form>
      </template>
    </a-spin>
  </a-modal>
</template>

<script setup>
/** Deal → Stocky quotation (catalogue lines, order tax / discount / shipping). */
import { ref, computed, watch } from 'vue';
import { message } from 'ant-design-vue';
import { useFormat } from '../../composables/useFormat';
import http from '../../lib/http';
import { t, i18n } from '../../i18n';

const props = defineProps({ open: Boolean, dealId: { type: [Number, String], default: null } });
const emit = defineEmits(['close', 'created']);
const { money } = useFormat();

const loading = ref(false);
const saving = ref(false);
const meta = ref({});
const form = ref({ warehouse_id: undefined, tax_rate: 0, discount: 0, shipping: 0, notes: '' });
const warehouseOptions = computed(() => (meta.value.warehouses || []).map(w => ({ value: w.id, label: w.name })));
const canSubmit = computed(() => meta.value.has_customer && meta.value.can_create_quotation && meta.value.catalogue_lines > 0 && form.value.warehouse_id);
// Same order maths as the quotation form: (lines − discount) × tax % + shipping.
const grand = computed(() => {
  const after = (Number(meta.value.lines_total) || 0) - (Number(form.value.discount) || 0);
  return Math.round((after + after * (Number(form.value.tax_rate) || 0) / 100 + (Number(form.value.shipping) || 0)) * 100) / 100;
});

function tp(key, fallback, params) {
  const out = i18n.global.t(key, params);
  return out !== key ? out : Object.entries(params).reduce((s, [k, v]) => s.replace(`{${k}}`, v), fallback);
}

watch(() => props.open, async (v) => {
  if (!v) return;
  loading.value = true;
  try {
    meta.value = await http.get(`crm/deals/${props.dealId}/quotation`);
    form.value = { warehouse_id: meta.value.default_warehouse_id, tax_rate: 0, discount: 0, shipping: 0, notes: '' };
  } catch (e) {
    message.error(e?.data?.message || t('Gen_Could_Not_Load', 'Could not load'));
  } finally {
    loading.value = false;
  }
});

async function submit() {
  saving.value = true;
  try {
    const res = await http.post(`crm/deals/${props.dealId}/quotation`, form.value);
    message.success(`${t('Crm_Quotation_Created', 'Quotation created')} — ${res.ref}`);
    emit('created', res);
  } catch (e) {
    message.error(e?.data?.message || t('Crm_Err_Could_Not_Create_The_Quotation', 'Could not create the quotation'), 6);
  } finally {
    saving.value = false;
  }
}
</script>

<style scoped>
.gap { margin-bottom: 12px; }
.mb0 { margin-bottom: 0; }
.sum { display: flex; justify-content: space-between; align-items: center; padding: 10px 12px; border-radius: 10px; background: rgba(128, 128, 128, 0.07); margin-bottom: 12px; }
.sum.total strong { color: var(--app-primary, #6d28d9); font-size: 16px; }
</style>
