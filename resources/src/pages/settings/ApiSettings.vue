<template>
  <div class="page">
    <PageHeader :title="$t('Api_Title')" :breadcrumb="[$t('Settings'), $t('Api_Title')]" />

    <a-card>
      <a-tabs>
        <!-- ============================== Keys ============================== -->
        <a-tab-pane key="keys" :tab="$t('Api_Keys')">
          <a-alert v-if="ready === false" type="warning" show-icon style="margin-bottom: 16px" :message="$t('Api_Not_Ready')" />
          <a-alert type="info" show-icon style="margin-bottom: 16px; max-width: 820px" :message="$t('Api_Keys_Help')" />

          <div style="display: flex; justify-content: flex-end; margin-bottom: 12px">
            <a-button type="primary" :disabled="ready === false" @click="openCreate">
              <template #icon><PlusOutlined /></template>
              {{ $t('Api_New_Key') }}
            </a-button>
          </div>

          <a-table :columns="columns" :data-source="keys" :loading="loading" row-key="id" size="middle" :pagination="false">
            <template #bodyCell="{ column, record }">
              <template v-if="column.key === 'prefix'"><code>{{ record.prefix }}…</code></template>
              <template v-else-if="column.key === 'ability'">
                <a-tag :color="record.ability === 'write' ? 'orange' : 'blue'">
                  {{ record.ability === 'write' ? $t('Api_Read_Write') : $t('Api_Read_Only') }}
                </a-tag>
              </template>
              <template v-else-if="column.key === 'last_used_at'">
                {{ record.last_used_at ? dateTime(record.last_used_at) : '—' }}
                <div v-if="record.last_used_ip" class="muted">{{ record.last_used_ip }}</div>
              </template>
              <template v-else-if="column.key === 'status'">
                <a-tag v-if="record.revoked_at" color="default">{{ $t('Api_Revoked') }}</a-tag>
                <a-tag v-else color="success">{{ $t('Active') }}</a-tag>
              </template>
              <template v-else-if="column.key === 'actions'">
                <a-popconfirm v-if="!record.revoked_at" :title="$t('Api_Revoke_Confirm')" @confirm="revoke(record)">
                  <a-button size="small" danger>{{ $t('Api_Revoke') }}</a-button>
                </a-popconfirm>
              </template>
            </template>
          </a-table>
        </a-tab-pane>

        <!-- ============================== Reference ============================== -->
        <a-tab-pane key="docs" :tab="$t('Api_Reference')">
          <div class="docs">
            <p>{{ $t('Api_Doc_Intro') }}</p>
            <a-descriptions :column="1" bordered size="small" style="margin-bottom: 16px">
              <a-descriptions-item :label="$t('Api_Base_Url')"><code>{{ baseUrl }}</code></a-descriptions-item>
              <a-descriptions-item :label="$t('Api_Auth')"><code>Authorization: Bearer stk_…</code></a-descriptions-item>
              <a-descriptions-item :label="$t('Api_Limits')">{{ $t('Api_Limits_Text') }}</a-descriptions-item>
              <a-descriptions-item label="Idempotency-Key">{{ $t('Api_Idempotency_Text') }}</a-descriptions-item>
            </a-descriptions>

            <a-collapse :bordered="false">
              <a-collapse-panel v-for="ep in endpoints" :key="ep.path + ep.method">
                <template #header>
                  <a-tag :color="ep.method === 'GET' ? 'blue' : 'orange'" style="width: 52px; text-align: center">{{ ep.method }}</a-tag>
                  <code>{{ ep.path }}</code>
                  <span class="muted" style="margin-left: 8px">{{ ep.summary }}</span>
                </template>
                <p v-if="ep.notes">{{ ep.notes }}</p>
                <div v-if="ep.params" class="muted" style="margin-bottom: 8px">{{ $t('Api_Params') }}: <code>{{ ep.params }}</code></div>
                <pre class="example">{{ ep.example }}</pre>
              </a-collapse-panel>
            </a-collapse>

            <h4 style="margin-top: 20px">{{ $t('Api_Errors') }}</h4>
            <pre class="example">{{ errorExample }}</pre>
            <p class="muted">{{ $t('Api_Webhooks_Hint') }}</p>
          </div>
        </a-tab-pane>
      </a-tabs>
    </a-card>

    <!-- Create: name + ability; then the key, shown once. -->
    <a-modal v-model:open="createOpen" :title="$t('Api_New_Key')" :footer="null" :mask-closable="!creating">
      <template v-if="!created">
        <a-form layout="vertical">
          <a-form-item :label="$t('Name')" required>
            <a-input v-model:value="form.name" :maxlength="100" :placeholder="$t('Api_Name_Placeholder')" />
          </a-form-item>
          <a-form-item :label="$t('Api_Access')">
            <a-radio-group v-model:value="form.ability">
              <a-radio value="read">{{ $t('Api_Read_Only') }}</a-radio>
              <a-radio value="write">{{ $t('Api_Read_Write') }}</a-radio>
            </a-radio-group>
          </a-form-item>
          <a-alert type="info" show-icon :message="$t('Api_Acts_As_You')" style="margin-bottom: 16px" />
          <div style="display: flex; justify-content: flex-end; gap: 8px">
            <a-button @click="createOpen = false">{{ $t('Cancel') }}</a-button>
            <a-button type="primary" :loading="creating" :disabled="!form.name.trim()" @click="create">{{ $t('Api_Create') }}</a-button>
          </div>
        </a-form>
      </template>
      <template v-else>
        <a-alert type="warning" show-icon :message="$t('Api_Copy_Now')" style="margin-bottom: 12px" />
        <a-input-group compact style="display: flex">
          <a-input :value="created" readonly style="flex: 1; font-family: monospace" />
          <a-button type="primary" @click="copy(created)">
            <template #icon><CopyOutlined /></template>
          </a-button>
        </a-input-group>
        <div style="display: flex; justify-content: flex-end; margin-top: 16px">
          <a-button @click="createOpen = false">{{ $t('Done') }}</a-button>
        </div>
      </template>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * Settings > API: keys for the public REST API (/api/v1) and its reference.
 * GET/POST api-keys, DELETE api-keys/{id} (revoke). The full key comes back
 * only in the create response and is shown once here; the list shows the
 * prefix. A key acts as the user who creates it (ApiKeysController).
 */
import { ref, computed, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { PlusOutlined, CopyOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import { useFormat } from '../../composables/useFormat';
import http from '../../lib/http';

const { t } = useI18n();
const { dateTime } = useFormat();

const loading = ref(true);
const ready = ref(null);
const keys = ref([]);
const createOpen = ref(false);
const creating = ref(false);
const created = ref('');
const form = ref({ name: '', ability: 'read' });

const baseUrl = `${window.location.origin}/api/v1`;

const columns = computed(() => [
  { title: t('Name'), dataIndex: 'name', key: 'name' },
  { title: t('Api_Key_Prefix'), key: 'prefix' },
  { title: t('Api_Access'), key: 'ability' },
  { title: t('Api_Acts_As'), dataIndex: 'user', key: 'user' },
  { title: t('Last_Used'), key: 'last_used_at' },
  { title: t('Status'), key: 'status' },
  { title: '', key: 'actions', align: 'right' },
]);

async function load() {
  loading.value = true;
  try {
    const data = await http.get('api-keys');
    ready.value = data?.ready !== false;
    keys.value = data?.keys || [];
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    loading.value = false;
  }
}

function openCreate() {
  form.value = { name: '', ability: 'read' };
  created.value = '';
  createOpen.value = true;
}

async function create() {
  creating.value = true;
  try {
    const data = await http.post('api-keys', form.value);
    created.value = data?.key || '';
    load();
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    creating.value = false;
  }
}

async function revoke(record) {
  try {
    await http.delete(`api-keys/${record.id}`);
    message.success(t('Api_Revoked'));
    load();
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  }
}

async function copy(text) {
  try {
    await navigator.clipboard.writeText(text);
    message.success(t('Copied'));
  } catch (e) {
    message.info(text);
  }
}

/* ------------------------------------------------------------ reference */
const H = `-H "Authorization: Bearer $STOCKY_KEY"`;
const J = `-H "Content-Type: application/json"`;
const endpoints = computed(() => [
  { method: 'GET', path: '/me', summary: t('Api_Ep_Me'), example: `curl ${baseUrl}/me ${H}` },
  { method: 'GET', path: '/warehouses', summary: t('Api_Ep_Warehouses'), example: `curl ${baseUrl}/warehouses ${H}` },
  { method: 'GET', path: '/suppliers', summary: t('Api_Ep_Suppliers'), params: 'search, page, per_page', example: `curl "${baseUrl}/suppliers?search=bosch" ${H}` },
  { method: 'GET', path: '/products', summary: t('Api_Ep_Products'), params: 'code, search, updated_since, page, per_page (≤ 500)', example: `curl "${baseUrl}/products?updated_since=2026-11-01&per_page=200" ${H}` },
  { method: 'GET', path: '/products/{code}', summary: t('Api_Ep_Product'), notes: t('Api_Ep_Product_Notes'), example: `curl ${baseUrl}/products/20005812 ${H}` },
  {
    method: 'PUT', path: '/products/{code}', summary: t('Api_Ep_Upsert'), notes: t('Api_Ep_Upsert_Notes'),
    params: 'name, category, sub_category, brand, unit, cost, price, wholesale_price, min_price, tax_percent, tax_method (exclusive|inclusive), is_active, stock_alert, barcodes[]',
    example: `curl -X PUT ${baseUrl}/products/20005812 ${H} ${J} \\\n  -d '{"name":"Brake pad set, front","category":"Brakes","unit":"pc","cost":2450,"price":3900,"barcodes":["4006381333931"]}'`,
  },
  { method: 'GET', path: '/stock', summary: t('Api_Ep_Stock'), params: 'codes (A,B,… ≤ 500), warehouse_id, updated_since, page, per_page (≤ 1000)', example: `curl "${baseUrl}/stock?codes=20005812,20005814&warehouse_id=1" ${H}` },
  {
    method: 'POST', path: '/stock/adjustments', summary: t('Api_Ep_Adjust'), notes: t('Api_Ep_Adjust_Notes'),
    params: 'warehouse_id, reason, reference, date, lines[{code, quantity}]',
    example: `curl -X POST ${baseUrl}/stock/adjustments ${H} ${J} \\\n  -H "Idempotency-Key: count-2026-11-01-shelf-a" \\\n  -d '{"warehouse_id":1,"reason":"Stock count","reference":"Shelf A","lines":[{"code":"20005812","quantity":-2},{"code":"20005814","quantity":5}]}'`,
  },
  {
    method: 'POST', path: '/purchases', summary: t('Api_Ep_Purchase'), notes: t('Api_Ep_Purchase_Notes'),
    params: 'supplier_id | supplier, warehouse_id, reference, status (received|pending|ordered), date, notes, lines[{code, quantity, cost}]',
    example: `curl -X POST ${baseUrl}/purchases ${H} ${J} \\\n  -H "Idempotency-Key: inv-7781" \\\n  -d '{"supplier":"Bosch Lanka","warehouse_id":1,"reference":"INV-7781","lines":[{"code":"20005812","quantity":10,"cost":2400}]}'`,
  },
  { method: 'GET', path: '/sales/lines', summary: t('Api_Ep_Sales'), notes: t('Api_Ep_Sales_Notes'), params: 'since (Y-m-d), until, include_returns, warehouse_id, page, per_page (≤ 1000)', example: `curl "${baseUrl}/sales/lines?since=2026-11-01&include_returns=1" ${H}` },
]);

const errorExample = `HTTP 422
{ "error": { "code": "invalid_lines", "message": "Nothing was posted. 1 line(s) need fixing.",
             "lines": ["lines.0 (X-1): unknown code."] } }

HTTP 401 { "error": { "code": "invalid_key", … } }    HTTP 403 { "error": { "code": "read_only_key", … } }
HTTP 409 { "error": { "code": "duplicate_reference", … } }    HTTP 429 too many requests`;

onMounted(load);
</script>

<style scoped>
.muted {
  color: rgba(var(--ink-rgb), 0.5);
  font-size: 12px;
}
.docs {
  max-width: 920px;
}
.example {
  background: rgba(128, 128, 128, 0.08);
  border-radius: 6px;
  padding: 10px 12px;
  font-size: 12px;
  white-space: pre-wrap;
  word-break: break-all;
  margin: 0;
}
</style>
