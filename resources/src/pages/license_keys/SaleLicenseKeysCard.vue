<template>
  <a-card v-if="visible && (rows.length || missingTotal)" size="small" class="lic-card" :title="$t('LicK_License_Keys')">
    <template #extra>
      <router-link :to="'/license-keys'">{{ $t('LicK_Manage_Keys') }}</router-link>
    </template>
    <!-- Keys this sale is still owed (shop order, pre-order, subscription…). -->
    <a-alert v-if="missingTotal" type="warning" show-icon style="margin-bottom: 12px">
      <template #message>
        {{ t('LicK_Backorder_Sale_Msg', { n: missingTotal }) }}
        <span class="muted"> — {{ missing.map(m => m.product_name + (m.variant_name ? ' · ' + m.variant_name : '') + ' ×' + m.missing).join(', ') }}</span>
      </template>
      <template v-if="canManage" #action>
        <a-button size="small" type="primary" :loading="assigning" @click="assignNow">{{ $t('LicK_Assign_Now') }}</a-button>
      </template>
    </a-alert>
    <a-table v-if="rows.length" size="small" row-key="id" :pagination="false" :scroll="{ x: 'max-content' }" :columns="columns" :data-source="rows">
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'key'">
          <span class="mono">{{ revealed[record.id] || record.masked_key }}</span>
        </template>
        <template v-else-if="column.key === 'product'">
          {{ record.product_name || '-' }}
          <span v-if="record.variant_name" class="muted"> · {{ record.variant_name }}</span>
        </template>
        <template v-else-if="column.key === 'status'">
          <a-tag :color="licenseStatusColor(record.status)">{{ licenseStatusLabel(record.status) }}</a-tag>
          <a-tag v-if="record.license_state && record.license_state !== record.status" :color="licenseStatusColor(record.license_state)">
            {{ licenseStatusLabel(record.license_state) }}
          </a-tag>
        </template>
        <template v-else-if="column.key === 'expires_at'">
          <template v-if="record.source === 'generated'">{{ record.expires_at ? date(record.expires_at) : $t('LicK_Lifetime') }}</template>
          <span v-else class="muted">-</span>
        </template>
        <template v-else-if="column.key === 'actions'">
          <template v-if="canReveal">
            <a-button v-if="!revealed[record.id]" size="small" :loading="busy === record.id" @click="reveal(record)">
              <template #icon><EyeOutlined /></template>
              {{ $t('LicK_Reveal') }}
            </a-button>
            <a-button v-else size="small" @click="copy(revealed[record.id])">
              <template #icon><CopyOutlined /></template>
              {{ $t('Copy') }}
            </a-button>
          </template>
        </template>
      </template>
    </a-table>
  </a-card>
</template>

<script setup>
/**
 * Sale details → the license keys delivered with this sale
 * (GET license-keys?sale_id=…). Hidden unless the plan has `license_keys`,
 * the user may view keys and the sale carries at least one. Reveal is logged.
 */
import { ref, computed, watch } from 'vue';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { EyeOutlined, CopyOutlined } from '@ant-design/icons-vue';
import http from '../../lib/http';
import { useAuthStore } from '../../stores/auth';
import { usePlanStore } from '../../stores/plan';
import { useFormat } from '../../composables/useFormat';
import {
  licenseStatusColor, licenseStatusLabel, licenseError, copyText,
} from '../../composables/useLicenseKeys';

const props = defineProps({
  saleId: { type: [Number, String], required: true },
});

const { t } = useI18n();
const { date } = useFormat();
const auth = useAuthStore();
const plan = usePlanStore();

const visible = computed(() => plan.featureEnabled('license_keys') && auth.can('license_keys_view'));
const canReveal = computed(() => auth.can('license_keys_reveal'));

const canManage = computed(() => auth.can('license_keys_manage'));

const rows = ref([]);
const revealed = ref({});
const busy = ref(null);
const missing = ref([]);
const missingTotal = computed(() => missing.value.reduce((s, m) => s + (Number(m.missing) || 0), 0));
const assigning = ref(false);

async function load() {
  rows.value = [];
  revealed.value = {};
  missing.value = [];
  if (!visible.value || !props.saleId) return;
  try {
    const [data, bo] = await Promise.all([
      http.get('license-keys', { sale_id: props.saleId, limit: 200, sort: 'id', dir: 'asc' }),
      http.get('license-keys/backorders', { sale_id: props.saleId }),
    ]);
    rows.value = data?.rows || [];
    missing.value = bo?.rows || [];
  } catch (e) {
    // Migrations not run / feature off: the card simply stays hidden.
    rows.value = [];
    missing.value = [];
  }
}
watch(() => props.saleId, load, { immediate: true });

async function assignNow() {
  assigning.value = true;
  try {
    const r = await http.post(`license-keys/sales/${props.saleId}/sync`, {});
    const n = Number(r?.assigned) || 0;
    if (n) message.success(t('LicK_Assigned_Msg', { n }));
    else message.warning(t('LicK_Assigned_None'));
    await load();
  } catch (e) {
    message.error(licenseError(e));
  } finally {
    assigning.value = false;
  }
}

async function reveal(record) {
  busy.value = record.id;
  try {
    const data = await http.post(`license-keys/${record.id}/reveal`, {});
    revealed.value = { ...revealed.value, [record.id]: data?.key || '' };
  } catch (e) {
    message.error(licenseError(e));
  } finally {
    busy.value = null;
  }
}

async function copy(text) {
  if (text && await copyText(text)) message.success(t('Copied'));
}

const columns = computed(() => [
  { title: t('LicK_Key'), key: 'key' },
  { title: t('Product'), key: 'product' },
  { title: t('Status'), key: 'status' },
  { title: t('Expiry'), key: 'expires_at' },
  { title: '', key: 'actions', align: 'right' },
]);
</script>

<style scoped>
.lic-card { margin-top: 16px; border-radius: 10px; }
.mono { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; }
.muted { color: rgba(var(--ink-rgb), 0.45); }
</style>
