<template>
  <a-modal
    :open="open"
    :title="tf('Ai_Scan_Invoice', 'Scan supplier invoice')"
    :width="result ? 1000 : 560"
    :footer="null"
    :mask-closable="!scanning"
    destroy-on-close
    @update:open="v => $emit('update:open', v)"
  >
    <!-- Step 1: upload -->
    <template v-if="!result">
      <a-upload-dragger
        :file-list="file ? [file] : []"
        :before-upload="pick"
        :max-count="1"
        accept="image/jpeg,image/png,image/webp,image/gif,application/pdf"
        :disabled="scanning"
        @remove="file = null"
      >
        <p class="drop-icon"><FileSearchOutlined /></p>
        <p class="drop-title">{{ tf('Ai_Scan_Drop', 'Drop a photo or PDF of the invoice here, or click to choose') }}</p>
        <p class="drop-hint">{{ tf('Ai_Scan_Types', 'JPG, PNG, WEBP or PDF · up to 10 MB — large photos are resized automatically') }}</p>
      </a-upload-dragger>

      <a-alert v-if="error" type="error" show-icon :message="error" style="margin-top: 12px" />

      <div class="actions">
        <a-button :disabled="scanning" @click="$emit('update:open', false)">{{ $t('Cancel') }}</a-button>
        <a-button type="primary" :disabled="!file" :loading="scanning" @click="scan">
          <template #icon><ScanOutlined /></template>
          {{ scanning ? tf('Ai_Scan_Reading', 'Reading the invoice…') : tf('Ai_Scan_Read', 'Read invoice') }}
        </a-button>
      </div>
    </template>

    <!-- Step 2: review -->
    <template v-else>
      <a-alert
        v-if="!result.is_invoice" type="warning" show-icon style="margin-bottom: 12px"
        :message="tf('Ai_Scan_Not_Invoice', 'This doesn’t look like an invoice. Check the details carefully.')"
      />
      <a-alert v-if="result.notes" type="info" show-icon :message="result.notes" style="margin-bottom: 12px" />

      <a-row :gutter="16" class="head">
        <a-col :xs="24" :md="9">
          <div class="label">{{ $t('Supplier') }}</div>
          <a-select
            v-model:value="supplierId" show-search option-filter-prop="label" allow-clear
            :options="supplierOptions" :placeholder="$t('Choose_Supplier')" style="width: 100%"
          />
          <div class="detected">
            {{ tf('Ai_Scan_On_Invoice', 'On the invoice') }}: <strong>{{ result.supplier.name || '—' }}</strong>
            <template v-if="result.supplier.tax_number"> · {{ result.supplier.tax_number }}</template>
            <a-tag v-if="result.supplier_match" :color="result.supplier_match.confidence === 'exact' ? 'success' : 'warning'" class="conf">
              {{ result.supplier_match.confidence === 'exact' ? tf('Ai_Match_Exact', 'Matched') : tf('Ai_Match_Suggested', 'Check') }}
            </a-tag>
            <a-tag v-else color="default" class="conf">{{ tf('Ai_Scan_Supplier_New', 'Not found — pick or add one') }}</a-tag>
          </div>
        </a-col>
        <a-col :xs="12" :md="5">
          <div class="label">{{ $t('date') }}</div>
          <a-date-picker v-model:value="date" value-format="YYYY-MM-DD" style="width: 100%" />
        </a-col>
        <a-col :xs="12" :md="5">
          <div class="label">{{ tf('Ai_Scan_Invoice_No', 'Invoice no.') }}</div>
          <a-input v-model:value="invoiceNumber" />
        </a-col>
        <a-col :xs="24" :md="5">
          <div class="label">{{ tf('Ai_Scan_Invoice_Total', 'Invoice total') }}</div>
          <div class="total">
            {{ fmt(result.total) }} <span class="muted">{{ result.currency }}</span>
          </div>
        </a-col>
      </a-row>

      <a-table
        :columns="columns" :data-source="rows" :pagination="false" size="small"
        :row-key="r => r.key" :scroll="{ x: 'max-content', y: 360 }" class="lines"
      >
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'use'">
            <a-checkbox v-model:checked="record.use" :disabled="record.productIndex === undefined" />
          </template>
          <template v-else-if="column.key === 'line'">
            <div class="desc">{{ record.description || '—' }}</div>
            <div v-if="record.code" class="muted">{{ record.code }}</div>
          </template>
          <template v-else-if="column.key === 'quantity'">
            <a-input-number v-model:value="record.quantity" :min="0" size="small" style="width: 90px" />
          </template>
          <template v-else-if="column.key === 'unit_cost'">
            <a-input-number v-model:value="record.unit_cost" :min="0" size="small" style="width: 110px" />
          </template>
          <template v-else-if="column.key === 'product'">
            <div class="product-cell">
              <a-select
                :value="record.productIndex" show-search allow-clear size="small"
                :filter-option="filterProduct" :options="productOptions"
                :placeholder="tf('Ai_Scan_Pick_Product', 'Pick the product')"
                style="width: 280px"
                @update:value="v => setProduct(record, v)"
              />
              <a-tag v-if="record.confidence === 'exact'" color="success">{{ tf('Ai_Match_Exact', 'Matched') }}</a-tag>
              <a-tooltip v-else-if="record.confidence === 'suggested'" :title="tf('Ai_Match_Suggested_Help', 'Matched by name — check it is the right product')">
                <a-tag color="warning">{{ tf('Ai_Match_Suggested', 'Check') }}</a-tag>
              </a-tooltip>
              <a-tag v-else-if="record.productIndex === undefined" color="error">{{ tf('Ai_Match_None', 'Not matched') }}</a-tag>
            </div>
          </template>
          <template v-else-if="column.key === 'line_total'">{{ fmt(record.quantity * record.unit_cost) }}</template>
        </template>
      </a-table>

      <div class="summary">
        <div>
          <a-checkbox v-if="result.shipping" v-model:checked="useShipping">{{ tf('Ai_Scan_Add_Shipping', 'Add shipping') }} ({{ fmt(result.shipping) }})</a-checkbox>
          <a-checkbox v-if="result.discount" v-model:checked="useDiscount">{{ tf('Ai_Scan_Add_Discount', 'Add discount') }} ({{ fmt(result.discount) }})</a-checkbox>
          <a-checkbox v-model:checked="attachFile">{{ tf('Ai_Scan_Attach', 'Attach the invoice file to the purchase') }}</a-checkbox>
          <div v-if="result.tax" class="muted tax-note">
            {{ tf('Ai_Scan_Tax_Note', 'Invoice tax') }}: {{ fmt(result.tax) }} — {{ tf('Ai_Scan_Tax_Help', 'line taxes come from each product’s tax settings; check them in the form.') }}
          </div>
        </div>
        <div class="sum-box">
          <div>{{ tf('Ai_Scan_Selected_Lines', 'Selected lines') }}: <strong>{{ fmt(selectedTotal) }}</strong></div>
          <div v-if="result.subtotal !== null" class="muted">{{ tf('Ai_Scan_Invoice_Subtotal', 'Invoice subtotal') }}: {{ fmt(result.subtotal) }}</div>
          <a-tag v-if="subtotalMismatch" color="warning" style="margin-top: 4px">{{ tf('Ai_Scan_Mismatch', 'Doesn’t match the invoice — check quantities and prices') }}</a-tag>
        </div>
      </div>

      <a-alert
        v-if="unmatchedCount" type="warning" show-icon style="margin-top: 12px"
        :message="$t('Ai_Scan_Unmatched', { n: unmatchedCount })"
      />

      <div class="actions">
        <a-button @click="result = null">{{ tf('Ai_Scan_Another', 'Scan another file') }}</a-button>
        <a-button type="primary" :disabled="!selectedCount" @click="apply">
          <template #icon><CheckOutlined /></template>
          {{ tf('Ai_Scan_Fill', 'Fill the purchase') }} ({{ selectedCount }})
        </a-button>
      </div>
    </template>
  </a-modal>
</template>

<script setup>
/**
 * POST ai/invoice-scan (multipart `file`) → { invoice: { supplier, supplier_match,
 * invoice_number, invoice_date, currency, currency_id, subtotal, discount, tax,
 * shipping, total, notes, is_invoice, lines: [{description, code, quantity,
 * unit_cost, line_total, match: {product_id, product_variant_id, confidence}}] } }
 *
 * Lines are matched to the form's warehouse product list (props.products, by
 * index — what the form's own picker uses). Emits `apply` with what the user
 * kept; the form adds the lines and, after saving, attaches the file.
 * Amounts are as printed on the invoice (the form treats them as document
 * currency).
 */
import { ref, computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { FileSearchOutlined, ScanOutlined, CheckOutlined } from '@ant-design/icons-vue';
import { t as tf } from '../../i18n';
import http from '../../lib/http';

const props = defineProps({
  open: { type: Boolean, default: false },
  suppliers: { type: Array, default: () => [] },
  products: { type: Array, default: () => [] },
});
const emit = defineEmits(['update:open', 'apply']);

const { t } = useI18n();
const file = ref(null);
const scanning = ref(false);
const error = ref('');
const result = ref(null);
const rows = ref([]);
const supplierId = ref(undefined);
const date = ref(null);
const invoiceNumber = ref('');
const useShipping = ref(true);
const useDiscount = ref(true);
const attachFile = ref(true);

const supplierOptions = computed(() => props.suppliers.map(s => ({ value: s.id, label: s.name })));
const productOptions = computed(() => props.products.map((p, i) => ({ value: i, label: `${p.code} — ${p.name}` })));
const filterProduct = (input, option) => String(option.label).toLowerCase().includes(String(input).toLowerCase());

const columns = computed(() => [
  { key: 'use', width: 40 },
  { title: tf('Ai_Scan_Invoice_Line', 'Invoice line'), key: 'line' },
  { title: t('Quantity'), key: 'quantity', width: 110 },
  { title: tf('Ai_Scan_Unit_Cost', 'Unit cost'), key: 'unit_cost', width: 130 },
  { title: t('Product'), key: 'product' },
  { title: t('SubTotal'), key: 'line_total', align: 'right', width: 110 },
]);

const selected = computed(() => rows.value.filter(r => r.use && r.productIndex !== undefined));
const selectedCount = computed(() => selected.value.length);
const unmatchedCount = computed(() => rows.value.filter(r => r.productIndex === undefined).length);
const selectedTotal = computed(() => selected.value.reduce((s, r) => s + (Number(r.quantity) || 0) * (Number(r.unit_cost) || 0), 0));
const subtotalMismatch = computed(() => {
  const sub = result.value?.subtotal;
  if (sub === null || sub === undefined || unmatchedCount.value) return false;
  return Math.abs(selectedTotal.value - sub) > Math.max(0.05, sub * 0.005);
});

const fmt = v => (v === null || v === undefined ? '—' : Number(v).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }));

function pick(f) {
  file.value = f;
  error.value = '';
  return false; // keep it local; uploaded on "Read invoice"
}

function indexOfProduct(match) {
  if (!match) return undefined;
  const i = props.products.findIndex(p =>
    Number(p.id) === Number(match.product_id)
    && Number(p.product_variant_id ?? 0) === Number(match.product_variant_id ?? 0));
  return i >= 0 ? i : undefined;
}

function setProduct(row, value) {
  row.productIndex = value ?? undefined;
  row.confidence = value === undefined || value === null ? null : 'manual';
  row.use = row.productIndex !== undefined;
}

async function scan() {
  if (!file.value) return;
  scanning.value = true;
  error.value = '';
  try {
    const fd = new FormData();
    fd.append('file', file.value);
    const data = await http.postForm('ai/invoice-scan', fd);
    const inv = data.invoice;
    result.value = inv;
    supplierId.value = inv.supplier_match?.id ?? undefined;
    date.value = inv.invoice_date || null;
    invoiceNumber.value = inv.invoice_number || '';
    useShipping.value = !!inv.shipping;
    useDiscount.value = !!inv.discount;
    rows.value = inv.lines.map((l, i) => {
      const productIndex = indexOfProduct(l.match);
      return {
        key: i,
        description: l.description,
        code: l.code,
        quantity: Number(l.quantity) || 0,
        unit_cost: Number(l.unit_cost) || 0,
        productIndex,
        confidence: productIndex !== undefined ? l.match.confidence : null,
        use: productIndex !== undefined,
      };
    });
  } catch (e) {
    error.value = e?.data?.message || e?.data?.errors?.file?.[0] || tf('Ai_Request_Failed', 'Something went wrong. Please try again.');
  } finally {
    scanning.value = false;
  }
}

function apply() {
  emit('apply', {
    supplier_id: supplierId.value ?? null,
    date: date.value,
    invoice_number: invoiceNumber.value.trim(),
    currency_id: result.value.currency_id ?? null,
    shipping: useShipping.value ? result.value.shipping : null,
    discount: useDiscount.value ? result.value.discount : null,
    file: attachFile.value ? file.value : null,
    lines: selected.value.map(r => ({ index: r.productIndex, quantity: Number(r.quantity) || 0, unit_cost: Number(r.unit_cost) || 0 })),
  });
  emit('update:open', false);
  result.value = null;
  file.value = null;
}
</script>

<style scoped>
.drop-icon { font-size: 40px; color: var(--app-primary); margin-bottom: 4px; }
.drop-title { font-size: 15px; margin-bottom: 4px; }
.drop-hint { font-size: 12px; opacity: 0.6; }
.actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 16px; flex-wrap: wrap; }
.head { margin-bottom: 14px; row-gap: 10px; }
.label { font-size: 12px; opacity: 0.7; margin-bottom: 4px; }
.detected { font-size: 12px; margin-top: 4px; opacity: 0.85; }
.conf { margin-inline-start: 6px; }
.total { font-size: 18px; font-weight: 600; line-height: 32px; }
.muted { font-size: 12px; opacity: 0.6; }
.desc { max-width: 260px; white-space: normal; }
.product-cell { display: flex; align-items: center; gap: 6px; }
.summary { display: flex; justify-content: space-between; gap: 16px; margin-top: 12px; flex-wrap: wrap; }
.summary :deep(.ant-checkbox-wrapper) { display: flex; margin: 0 0 6px; }
.tax-note { max-width: 520px; }
.sum-box { text-align: end; }
</style>
