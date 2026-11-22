<template>
  <div class="page">
    <PageHeader :title="$t('Batches')" :breadcrumb="[$t('Products'), $t('Batches')]">
      <template #actions>
        <a-button v-if="canManage" type="primary" @click="openOpening">
          <template #icon><PlusOutlined /></template>
          {{ $t('Opening_Batches') }}
        </a-button>
      </template>
    </PageHeader>

    <a-card size="small" style="margin-bottom: 16px">
      <a-row :gutter="[16, 8]" align="bottom">
        <a-col :xs="12" :md="7">
          <div class="filter-label">{{ $t('warehouse') }}</div>
          <a-select
            v-model:value="filters.warehouse_id" style="width: 100%" allow-clear show-search
            option-filter-prop="label" :placeholder="$t('Choose_Warehouse')" :options="warehouseOptions"
            @change="crud.reload()"
          />
        </a-col>
        <a-col :xs="12" :md="7">
          <div class="filter-label">{{ $t('Status') }}</div>
          <a-select
            v-model:value="filters.status" style="width: 100%" :options="statusOptions" @change="crud.reload()"
          />
        </a-col>
        <a-col :xs="12" :md="7">
          <div class="filter-label">{{ $t('Expiry_Window') }}</div>
          <a-select
            v-model:value="filters.expiry_window" style="width: 100%" :options="expiryOptions" @change="crud.reload()"
          />
        </a-col>
        <a-col :xs="12" :md="3">
          <div class="muted">{{ $t('Expiry_Warning_Days') }}: {{ expiryWarningDays }}</div>
        </a-col>
      </a-row>
    </a-card>

    <DataTable :crud="crud" :columns="columns">
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'product'">
          <div style="font-weight: 500">{{ record.product_name }}</div>
          <a-tag v-if="record.variant_name" style="margin-top: 2px">{{ record.variant_name }}</a-tag>
        </template>
        <template v-else-if="column.key === 'expiry_date'">
          <span :style="{ color: expiryColor(record) }">{{ record.expiry_date || '—' }}</span>
          <div v-if="Number.isFinite(Number(record.days_to_expiry))" class="muted">
            {{ Number(record.days_to_expiry) < 0 ? $t('Expired') : `${$t('Expires_in')} ${record.days_to_expiry} ${$t('Days')}` }}
          </div>
        </template>
        <template v-else-if="column.key === 'unit_cost'">{{ auth.canSeeCost ? money(record.unit_cost) : '' }}</template>
        <template v-else-if="column.key === 'status'">
          <a-tag :color="batchStatusColor(record.status)">{{ $t(`Batch_Status_${record.status}`) }}</a-tag>
        </template>
        <template v-else-if="column.key === 'actions'">
          <a-space>
            <a-tooltip v-if="canManage" :title="$t('Edit_Batch')">
              <a-button type="text" size="small" @click="openEdit(record)">
                <template #icon><EditOutlined style="color: #52c41a" /></template>
              </a-button>
            </a-tooltip>
            <a-tooltip v-if="canWriteOff && record.status !== 'written_off'" :title="$t('Write_Off')">
              <a-button type="text" size="small" @click="openWriteOff(record)">
                <template #icon><StopOutlined style="color: #faad14" /></template>
              </a-button>
            </a-tooltip>
            <a-tooltip v-if="canManage" :title="$t('Del')">
              <a-button type="text" size="small" danger @click="crud.remove(record, { label: record.batch_no })">
                <template #icon><DeleteOutlined /></template>
              </a-button>
            </a-tooltip>
          </a-space>
        </template>
      </template>
    </DataTable>

    <!-- Edit batch -->
    <a-modal
      v-model:open="editOpen"
      :title="$t('Edit_Batch')"
      :confirm-loading="saving"
      :ok-text="$t('submit')"
      @ok="saveEdit"
    >
      <a-form layout="vertical">
        <a-form-item :label="$t('Product')">
          <a-input :value="editing.product_name" disabled />
        </a-form-item>
        <a-row :gutter="12">
          <a-col :span="12">
            <a-form-item :label="$t('Batch_No')">
              <a-input v-model:value="editing.batch_no" />
            </a-form-item>
          </a-col>
          <a-col :span="12">
            <a-form-item :label="$t('Status')">
              <a-select
                v-model:value="editing.status"
                :options="['active', 'quarantined', 'expired', 'written_off'].map(s => ({ value: s, label: t(`Batch_Status_${s}`) }))"
              />
            </a-form-item>
          </a-col>
          <a-col :span="12">
            <a-form-item :label="$t('Mfg_Date')">
              <a-date-picker v-model:value="editing.mfg_date" value-format="YYYY-MM-DD" style="width: 100%" />
            </a-form-item>
          </a-col>
          <a-col :span="12">
            <a-form-item :label="$t('Expiry_Date')">
              <a-date-picker v-model:value="editing.expiry_date" value-format="YYYY-MM-DD" style="width: 100%" />
            </a-form-item>
          </a-col>
          <a-col :span="12">
            <a-form-item :label="$t('Quantity')" :extra="$t('Batch_Qty_Edit_Hint')">
              <a-input-number v-model:value="editing.qty" style="width: 100%" :min="0" />
            </a-form-item>
          </a-col>
          <a-col v-if="auth.canSeeCost" :span="12">
            <a-form-item :label="$t('UnitCost')">
              <a-input-number v-model:value="editing.unit_cost" style="width: 100%" :min="0" />
            </a-form-item>
          </a-col>
          <a-col :span="24">
            <a-form-item :label="$t('Note')">
              <a-textarea v-model:value="editing.notes" :rows="2" />
            </a-form-item>
          </a-col>
        </a-row>
      </a-form>
    </a-modal>

    <!-- Opening batches: label stock that is already on hand (received before
         batch tracking was switched on, or by an older build) so FEFO, sales
         and transfers can pick it. Stock quantities are not changed. -->
    <a-modal
      v-model:open="openingOpen"
      :title="$t('Opening_Batches')"
      :confirm-loading="saving"
      :ok-text="$t('submit')"
      :ok-button-props="{ disabled: !openingRow }"
      width="800px"
      @ok="saveOpening"
    >
      <a-alert type="info" show-icon style="margin-bottom: 12px" :message="$t('Opening_Batches_Help')" />
      <a-form layout="vertical">
        <a-form-item :label="$t('Product')">
          <a-select
            v-model:value="opening.product_id" show-search :filter-option="false" style="width: 100%"
            :options="productOptions" :placeholder="$t('Choose_Product')"
            @search="searchProducts" @change="loadOpeningMeta"
          />
        </a-form-item>
        <a-form-item v-if="opening.product_id" :label="$t('warehouse')">
          <a-select
            v-model:value="opening.row_key" style="width: 100%" :options="openingRowOptions"
            :placeholder="$t('Choose_Warehouse')" @change="resetOpeningLines"
          />
          <div v-if="openingRow" class="muted" style="margin-top: 4px">
            {{ $t('Stock') }}: {{ openingRow.stock_qty }} ·
            {{ $t('Batched_Qty') }}: {{ openingRow.batched_qty }} ·
            {{ $t('Unbatched_Qty') }}: <strong>{{ openingRow.unbatched_qty }}</strong>
          </div>
        </a-form-item>
      </a-form>
      <template v-if="openingRow">
        <div v-for="(b, i) in opening.batches" :key="i" class="opening-row">
          <a-input v-model:value="b.batch_no" :placeholder="$t('Batch_No')" style="flex: 1.3; min-width: 140px" />
          <a-date-picker v-model:value="b.mfg_date" value-format="YYYY-MM-DD" :placeholder="$t('Mfg_Date')" style="flex: 1" />
          <a-date-picker v-model:value="b.expiry_date" value-format="YYYY-MM-DD" :placeholder="$t('Expiry_Date')" style="flex: 1" />
          <a-input-number v-model:value="b.qty" :min="0" :placeholder="$t('Quantity')" style="width: 100px" />
          <a-input-number v-model:value="b.unit_cost" :min="0" :placeholder="$t('UnitCost')" style="width: 100px" />
          <a-button type="text" size="small" danger @click="opening.batches.splice(i, 1)">
            <template #icon><DeleteOutlined /></template>
          </a-button>
        </div>
        <div style="display: flex; align-items: center; gap: 12px; margin-top: 4px">
          <a-button size="small" @click="addOpeningLine">{{ $t('Add_Batch') }}</a-button>
          <span class="muted">
            {{ $t('Total') }}: <strong>{{ openingTotal }}</strong> / {{ openingRow.unbatched_qty }}
          </span>
        </div>
      </template>
    </a-modal>

    <!-- Write off -->
    <a-modal
      v-model:open="writeOffOpen"
      :title="`${$t('Write_Off')} — ${writingOff.batch_no || ''}`"
      :confirm-loading="saving"
      :ok-text="$t('submit')"
      @ok="saveWriteOff"
    >
      <p class="muted">{{ writingOff.product_name }} — {{ $t('Quantity') }}: {{ writingOff.qty }}</p>
      <a-form layout="vertical">
        <a-form-item :label="$t('Reason')">
          <a-textarea v-model:value="writingOff.reason" :rows="2" />
        </a-form-item>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * GET product_batches → {batches, totalRows, warehouses,
 * expiry_warning_days}; filters status (all/active/quarantined/expired/
 * written_off), expiry_window (all/expired/near/valid), warehouse_id (only
 * sent when set). Edit PUT product_batches/{id} {batch_no, expiry_date,
 * mfg_date, qty, unit_cost, status, notes}; write-off POST
 * product_batches/{id}/writeoff {reason}; DELETE product_batches/{id}.
 * Perms (either name works, legacy checks both): manage_batches|batch_manage,
 * writeoff_batches|batch_writeoff.
 *
 * Opening batches: GET products/{id}/opening_batches → {rows: [{warehouse_id,
 * warehouse_name, product_variant_id, variant_name, stock_qty, batched_qty,
 * unbatched_qty}]}; POST the same URL {warehouse_id, product_variant_id,
 * batches: [{batch_no, mfg_date, expiry_date, qty, unit_cost}]}. Labels
 * existing stock only — product_warehouse.qte is untouched.
 */
import { ref, computed } from 'vue';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { EditOutlined, DeleteOutlined, StopOutlined, PlusOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import { useAuthStore } from '../../stores/auth';
import http from '../../lib/http';

const { t } = useI18n();
const { money } = useFormat();
const auth = useAuthStore();

const canManage = computed(() => auth.can('manage_batches') || auth.can('batch_manage'));
const canWriteOff = computed(() => auth.can('writeoff_batches') || auth.can('batch_writeoff'));

const filters = ref({ warehouse_id: undefined, status: 'all', expiry_window: 'all' });

const crud = useCrudTable('product_batches', {
  rowsKey: 'batches',
  params: () => ({
    status: filters.value.status,
    expiry_window: filters.value.expiry_window,
    ...(filters.value.warehouse_id ? { warehouse_id: filters.value.warehouse_id } : {}),
  }),
});
crud.fetchRows();

const warehouseOptions = computed(() =>
  (crud.payload.value?.warehouses || []).map(w => ({ value: w.id, label: w.name }))
);
const expiryWarningDays = computed(() => crud.payload.value?.expiry_warning_days || 30);

const statusOptions = computed(() => [
  { value: 'all', label: t('All') },
  { value: 'active', label: t('Batch_Status_active') },
  { value: 'quarantined', label: t('Batch_Status_quarantined') },
  { value: 'expired', label: t('Batch_Status_expired') },
  { value: 'written_off', label: t('Batch_Status_written_off') },
]);
const expiryOptions = computed(() => [
  { value: 'all', label: t('All') },
  { value: 'expired', label: t('Expired') },
  { value: 'near', label: t('Near_Expiry') },
  { value: 'valid', label: t('Valid') },
]);

const columns = computed(() => [
  { title: t('Product'), key: 'product' },
  { title: t('Batch_No'), dataIndex: 'batch_no', key: 'batch_no', sorter: true },
  { title: t('Warehouse'), dataIndex: 'warehouse_name', key: 'warehouse_name' },
  { title: t('Expiry_Date'), dataIndex: 'expiry_date', key: 'expiry_date', sorter: true },
  { title: t('Quantity'), dataIndex: 'qty', key: 'qty', align: 'right', sorter: true },
  ...(auth.canSeeCost ? [{ title: t('UnitCost'), dataIndex: 'unit_cost', key: 'unit_cost', align: 'right' }] : []),
  { title: t('Status'), dataIndex: 'status', key: 'status' },
  { title: t('Action'), key: 'actions', width: 140, align: 'center' },
]);

function batchStatusColor(s) {
  return { active: 'success', quarantined: 'warning', expired: 'error', written_off: 'default' }[s] || 'default';
}

function expiryColor(b) {
  const d = Number(b.days_to_expiry);
  if (!Number.isFinite(d)) return undefined;
  if (d < 0) return '#ff4d4f';
  if (d <= expiryWarningDays.value) return '#faad14';
  return undefined;
}

const editOpen = ref(false);
const writeOffOpen = ref(false);
const saving = ref(false);
const editing = ref({});
const writingOff = ref({});

function openEdit(record) {
  editing.value = {
    id: record.id,
    product_name: record.product_name,
    batch_no: record.batch_no,
    expiry_date: record.expiry_date || null,
    mfg_date: record.mfg_date || null,
    qty: Number(record.qty) || 0,
    unit_cost: Number(record.unit_cost) || 0,
    status: record.status,
    notes: record.notes || '',
  };
  editOpen.value = true;
}

async function saveEdit() {
  saving.value = true;
  try {
    await http.put(`product_batches/${editing.value.id}`, {
      batch_no: editing.value.batch_no,
      expiry_date: editing.value.expiry_date || null,
      mfg_date: editing.value.mfg_date || null,
      qty: editing.value.qty,
      // The API only touches unit_cost when the key is sent — omit it while the
      // field is hidden so the stored cost (even null) is left exactly as is.
      ...(auth.canSeeCost ? { unit_cost: editing.value.unit_cost } : {}),
      status: editing.value.status,
      notes: editing.value.notes,
    });
    message.success(t('Successfully_Updated'));
    editOpen.value = false;
    crud.fetchRows();
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    saving.value = false;
  }
}

function openWriteOff(record) {
  writingOff.value = {
    id: record.id,
    batch_no: record.batch_no,
    product_name: record.product_name,
    qty: record.qty,
    reason: '',
  };
  writeOffOpen.value = true;
}

/* ---------------------------------------------------------- opening batches */
const openingOpen = ref(false);
const opening = ref({ product_id: undefined, row_key: undefined, rows: [], batches: [] });
const productHits = ref([]);
let productTimer = null;

const productOptions = computed(() =>
  productHits.value.map(p => ({ value: p.id, label: `${p.code} — ${p.name}` }))
);
const openingRowOptions = computed(() => opening.value.rows.map(r => ({
  value: rowKey(r),
  label: `${r.warehouse_name}${r.variant_name ? ` · ${r.variant_name}` : ''} — ${t('Unbatched_Qty')}: ${r.unbatched_qty}`,
})));
const openingRow = computed(
  () => opening.value.rows.find(r => rowKey(r) === opening.value.row_key) || null
);
const openingTotal = computed(
  () => opening.value.batches.reduce((s, b) => s + (Number(b.qty) || 0), 0)
);

function rowKey(r) {
  return `${r.warehouse_id}|${r.product_variant_id || 0}`;
}

function openOpening() {
  opening.value = { product_id: undefined, row_key: undefined, rows: [], batches: [] };
  productHits.value = [];
  openingOpen.value = true;
  searchProducts('');
}

function searchProducts(term) {
  clearTimeout(productTimer);
  productTimer = setTimeout(async () => {
    try {
      const data = await http.get('products', { search: term || '', limit: 20, page: 1 });
      productHits.value = data?.products || [];
    } catch (e) {
      productHits.value = [];
    }
  }, 250);
}

async function loadOpeningMeta() {
  opening.value.rows = [];
  opening.value.row_key = undefined;
  opening.value.batches = [];
  if (!opening.value.product_id) return;
  try {
    const data = await http.get(`products/${opening.value.product_id}/opening_batches`);
    opening.value.rows = (data?.rows || []).filter(r => Number(r.unbatched_qty) > 0);
    if (opening.value.rows.length === 1) {
      opening.value.row_key = rowKey(opening.value.rows[0]);
      resetOpeningLines();
    }
    if (!opening.value.rows.length) message.info(t('No_Unbatched_Stock'));
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  }
}

function resetOpeningLines() {
  opening.value.batches = [];
  addOpeningLine();
}

function addOpeningLine() {
  const row = openingRow.value;
  const remaining = row ? Math.max(0, Number(row.unbatched_qty) - openingTotal.value) : 0;
  opening.value.batches.push({ batch_no: '', mfg_date: null, expiry_date: null, qty: remaining, unit_cost: null });
}

async function saveOpening() {
  const row = openingRow.value;
  if (!row) return;
  const rows = opening.value.batches.filter(b => (b.batch_no || '').trim() && Number(b.qty) > 0);
  if (!rows.length) {
    message.warning(t('Batch_No_Required'));
    return;
  }
  if (openingTotal.value > Number(row.unbatched_qty) + 0.0001) {
    message.warning(t('Batch_Qty_Exceeds_Available'));
    return;
  }
  saving.value = true;
  try {
    await http.post(`products/${opening.value.product_id}/opening_batches`, {
      warehouse_id: row.warehouse_id,
      product_variant_id: row.product_variant_id,
      batches: rows.map(b => ({
        batch_no: b.batch_no.trim(), mfg_date: b.mfg_date || null, expiry_date: b.expiry_date || null,
        qty: b.qty, unit_cost: b.unit_cost ?? null,
      })),
    });
    message.success(t('Successfully_Created'));
    openingOpen.value = false;
    crud.fetchRows();
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    saving.value = false;
  }
}

async function saveWriteOff() {
  saving.value = true;
  try {
    await http.post(`product_batches/${writingOff.value.id}/writeoff`, {
      reason: writingOff.value.reason,
    });
    message.success(t('Successfully_Updated'));
    writeOffOpen.value = false;
    crud.fetchRows();
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    saving.value = false;
  }
}
</script>

<style scoped>
.filter-label {
  margin-bottom: 4px;
  color: rgba(var(--ink-rgb), 0.55);
  font-size: 13px;
}
.muted {
  color: rgba(var(--ink-rgb), 0.45);
  font-size: 12px;
}
.opening-row {
  display: flex;
  gap: 8px;
  align-items: center;
  margin-bottom: 8px;
}
</style>
