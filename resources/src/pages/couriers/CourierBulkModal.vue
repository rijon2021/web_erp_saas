<template>
  <a-modal
    :open="open" :title="$t('Cr_Send_To_Courier') + ` (${rows.length})`" width="900px"
    :footer="null" @cancel="close"
  >
    <a-spin :spinning="loading">
      <template v-if="!results">
        <a-row :gutter="16" style="margin: 12px 0" align="bottom">
          <a-col :xs="24" :sm="12">
            <div class="lbl">{{ $t('Cr_Courier_Account') }}</div>
            <a-select v-model:value="accountId" :options="accountOptions" style="width: 100%" @change="prepare" />
          </a-col>
          <a-col :xs="24" :sm="6">
            <div class="lbl">{{ $t('Cr_Weight_Override') }}</div>
            <a-input-number v-model:value="weight" :min="0.001" :step="0.1" style="width: 100%" :placeholder="$t('Cr_From_Products')" />
          </a-col>
        </a-row>
        <a-alert v-if="!accounts.length && !loading" type="warning" show-icon :message="$t('Cr_No_Active_Accounts')" />
        <a-alert v-else-if="needsTracking" type="info" show-icon :message="$t('Cr_Bulk_Tracking_Help')" style="margin-bottom: 12px" />

        <a-table :columns="columns" :data-source="rows" :pagination="false" size="small" :row-key="r => r.sale.id" :scroll="{ y: 380 }">
          <template #bodyCell="{ column, record }">
            <template v-if="column.key === 'sale'">
              <strong>{{ record.sale.Ref }}</strong>
              <div class="muted">{{ record.sale.client_name }}</div>
            </template>
            <template v-else-if="column.key === 'to'">
              <div>{{ record.form.consignee_phone }}</div>
              <div class="muted">{{ record.form.city }}</div>
            </template>
            <template v-else-if="column.key === 'cod'">{{ money(record.form.cod_amount) }}</template>
            <template v-else-if="column.key === 'state'">
              <a-tag v-if="record.booked" color="blue">{{ $t('Cr_Already_Booked_Short') }}</a-tag>
              <a-tooltip v-else-if="record.problems.length" :title="$t('Cr_Missing') + ': ' + record.problems.map(problemLabel).join(', ')">
                <a-tag color="error">{{ $t('Cr_Incomplete') }}</a-tag>
              </a-tooltip>
              <a-tag v-else color="success">{{ $t('Cr_Ready') }}</a-tag>
            </template>
            <template v-else-if="column.key === 'tracking'">
              <a-input v-model:value="tracking[record.sale.id]" size="small" :disabled="!!record.booked || !!record.problems.length" />
            </template>
          </template>
        </a-table>

        <div class="footer">
          <span class="muted">{{ $t('Cr_Ready_Count', { n: readyRows.length }) }}</span>
          <a-space>
            <a-button @click="close">{{ $t('Cancel') }}</a-button>
            <a-button type="primary" :loading="saving" :disabled="!readyRows.length || !accountId" @click="book">
              {{ $t('Cr_Book_N', { n: readyRows.length }) }}
            </a-button>
          </a-space>
        </div>
      </template>

      <template v-else>
        <a-result
          :status="results.failed ? (results.booked ? 'warning' : 'error') : 'success'"
          :title="$t('Cr_Bulk_Result', { ok: results.booked, failed: results.failed })"
        />
        <a-table :columns="resultColumns" :data-source="results.results" :pagination="false" size="small" row-key="sale_id" :scroll="{ y: 320 }">
          <template #bodyCell="{ column, record }">
            <template v-if="column.key === 'ok'">
              <a-tag :color="record.ok ? 'success' : 'error'">{{ record.ok ? $t('Cr_Booked') : $t('Cr_Failed') }}</a-tag>
            </template>
            <template v-else-if="column.key === 'info'">{{ record.ok ? record.tracking_number : record.error }}</template>
          </template>
        </a-table>
        <div class="footer">
          <span />
          <a-space>
            <a-button v-if="bookedShipmentIds.length" @click="printMany(bookedShipmentIds)">
              <template #icon><PrinterOutlined /></template>
              {{ $t('Cr_Print_Labels') }}
            </a-button>
            <a-button type="primary" @click="close">{{ $t('Close') }}</a-button>
          </a-space>
        </div>
      </template>
    </a-spin>
  </a-modal>
</template>

<script setup>
/**
 * Book many sales at once with their pre-filled forms.
 * POST couriers/bulk/prepare {sale_ids, account_id?} → {rows, accounts, account_id};
 * POST couriers/bulk/book {account_id, items[{sale_id, tracking_number?}], weight?}
 * → {results, booked, failed}. Rows missing a phone / address / city are
 * skipped — those sales are booked one by one from their page.
 */
import { ref, computed, watch } from 'vue';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { PrinterOutlined } from '@ant-design/icons-vue';
import http from '../../lib/http';
import { useFormat } from '../../composables/useFormat';
import { errorText } from './courierShared';
import { useShipmentLabels } from './useShipmentLabels';

const props = defineProps({
  open: { type: Boolean, default: false },
  saleIds: { type: Array, default: () => [] },
});
const emit = defineEmits(['update:open', 'done']);

const { t } = useI18n();
const { money } = useFormat();
const { printMany } = useShipmentLabels();

const loading = ref(false);
const saving = ref(false);
const rows = ref([]);
const accounts = ref([]);
const accountId = ref(null);
const weight = ref(null);
const tracking = ref({});
const results = ref(null);

const accountOptions = computed(() => accounts.value.map(a => ({ value: a.id, label: `${a.name} · ${a.courier_name}` })));
const selected = computed(() => accounts.value.find(a => a.id === accountId.value) || null);
const needsTracking = computed(() => !!selected.value?.capabilities?.manual_tracking);
const readyRows = computed(() => rows.value.filter(r => !r.booked && !r.problems.length
  && (!needsTracking.value || String(tracking.value[r.sale.id] || '').trim() !== '')));
const bookedShipmentIds = computed(() => (results.value?.results || []).filter(r => r.ok && r.shipment_id).map(r => r.shipment_id));

const columns = computed(() => [
  { title: t('Cr_Sale'), key: 'sale' },
  { title: t('Cr_Receiver'), key: 'to' },
  { title: t('Cr_COD_Amount'), key: 'cod', align: 'right', width: 120 },
  { title: t('Status'), key: 'state', width: 120 },
  ...(needsTracking.value ? [{ title: t('Cr_Tracking_Number'), key: 'tracking', width: 200 }] : []),
]);
const resultColumns = computed(() => [
  { title: t('Cr_Sale'), dataIndex: 'Ref', key: 'ref', width: 160 },
  { title: t('Status'), key: 'ok', width: 110 },
  { title: t('Cr_Details'), key: 'info' },
]);

// Backend reports missing fields as tokens (name/phone/address/city).
function problemLabel(p) {
  const key = { name: 'Name', phone: 'Phone', address: 'Address', city: 'City' }[p];
  return key ? t(key) : p;
}

async function prepare() {
  loading.value = true;
  try {
    const resp = await http.post('couriers/bulk/prepare', { sale_ids: props.saleIds, account_id: accountId.value });
    rows.value = resp.rows || [];
    accounts.value = resp.accounts || [];
    if (!accountId.value) accountId.value = resp.account_id || accounts.value.find(a => a.is_default)?.id || accounts.value[0]?.id || null;
  } catch (e) {
    message.error(errorText(e, t('Failed')));
  } finally {
    loading.value = false;
  }
}

async function book() {
  saving.value = true;
  try {
    results.value = await http.post('couriers/bulk/book', {
      account_id: accountId.value,
      weight: weight.value || null,
      items: readyRows.value.map(r => ({ sale_id: r.sale.id, tracking_number: (tracking.value[r.sale.id] || '').trim() || null })),
    });
    emit('done');
  } catch (e) {
    message.error(errorText(e, t('Failed')));
  } finally {
    saving.value = false;
  }
}

function close() {
  emit('update:open', false);
}

watch(() => props.open, open => {
  if (!open) return;
  rows.value = [];
  results.value = null;
  tracking.value = {};
  weight.value = null;
  accountId.value = null;
  prepare();
}, { immediate: true });
</script>

<style scoped>
.lbl {
  font-size: 12px;
  margin-bottom: 4px;
}
.muted {
  color: rgba(var(--ink-rgb), 0.45);
  font-size: 12px;
}
.footer {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-top: 16px;
}
</style>
