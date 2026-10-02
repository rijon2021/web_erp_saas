<template>
  <div class="page">
    <PageHeader
      title="Mappings"
      subtitle="Which ERP record is paired with which Shopify record, per store."
      :breadcrumb="['Shopify', 'Mappings']"
    >
      <template #actions>
        <a-button @click="linkOpen = true">
          <template #icon><LinkOutlined /></template>
          Link manually
        </a-button>
      </template>
    </PageHeader>

    <a-card size="small" style="margin-bottom: 16px">
      <div class="toolbar">
        <a-input-search
          v-model:value="crud.search.value" placeholder="Search Shopify id or handle…"
          allow-clear class="tb-search" @search="crud.reload"
        />
        <a-select
          v-model:value="filters.store_id" class="tb-item" allow-clear show-search
          option-filter-prop="label" placeholder="All stores"
          :options="storeOptions" @change="crud.reload"
        />
        <a-select
          v-model:value="filters.entity_type" class="tb-item" allow-clear
          placeholder="All types" :options="LINK_TYPES" @change="crud.reload"
        />
      </div>
    </a-card>

    <a-alert
      type="info" show-icon banner style="margin-bottom: 12px"
      message="Unlinking only forgets the pairing. Nothing is deleted in Shopify or in the ERP, and the next sync will re-match or recreate."
    />

    <DataTable :crud="crud" :columns="columns" :searchable="false">
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'entity_type'">
          <a-tag>{{ labelOf(LINK_TYPES, record.entity_type) }}</a-tag>
        </template>
        <template v-else-if="column.key === 'local'">
          <span v-if="record.local_name">{{ record.local_name }}</span>
          <span v-else class="muted">Record #{{ record.local_id }} (missing)</span>
          <div class="sub">#{{ record.local_id }}</div>
        </template>
        <template v-else-if="column.key === 'store_id'">
          {{ storeName(record.store_id) }}
        </template>
        <template v-else-if="column.key === 'shopify_id'">
          <code class="code">{{ record.shopify_id }}</code>
          <div v-if="record.shopify_handle" class="sub">{{ record.shopify_handle }}</div>
        </template>
        <template v-else-if="column.key === 'secondary_id'">
          <code v-if="record.secondary_id" class="code">{{ record.secondary_id }}</code>
          <span v-else class="muted">—</span>
        </template>
        <template v-else-if="column.key === 'last_synced_at'">
          {{ record.last_synced_at ? dateTime(record.last_synced_at) : '—' }}
        </template>
        <template v-else-if="column.key === 'actions'">
          <a-popconfirm
            title="Forget this pairing?"
            ok-text="Unlink" :cancel-text="$t('Cancel')"
            @confirm="unlink(record)"
          >
            <a-button type="text" size="small" danger>
              <template #icon><DisconnectOutlined /></template>
            </a-button>
          </a-popconfirm>
        </template>
      </template>
    </DataTable>

    <a-modal
      :open="linkOpen" title="Link a record by hand" :width="520"
      :confirm-loading="saving" ok-text="Link" :cancel-text="$t('Cancel')"
      @ok="submitLink(false)" @cancel="linkOpen = false"
    >
      <p class="lead">
        Use this when a record exists on both sides but the automatic match failed —
        a product whose SKU differs, for instance.
      </p>
      <a-form layout="vertical">
        <a-form-item label="Store *">
          <a-select
            v-model:value="linkForm.store_id" show-search option-filter-prop="label"
            :options="storeOptions" placeholder="Select a store"
          />
        </a-form-item>
        <a-form-item label="Record type *">
          <a-select v-model:value="linkForm.entity_type" :options="LINK_TYPES" />
        </a-form-item>
        <template v-if="linkForm.entity_type === 'product'">
          <a-form-item :label="$t('Shopify_ERP_Product') + ' *'">
            <a-select
              v-model:value="linkForm.local_id" show-search :filter-option="false" allow-clear
              :placeholder="$t('Shopify_Pick_Product')" :options="productOptions"
              :not-found-content="productSearching ? undefined : null" @search="searchProducts"
            >
              <template v-if="productSearching" #notFoundContent><a-spin size="small" /></template>
            </a-select>
          </a-form-item>
          <a-form-item :label="$t('Shopify_Pick_Listing') + ' *'">
            <a-select
              v-model:value="linkForm.shopify_id" show-search :filter-option="false" allow-clear
              :placeholder="linkForm.store_id ? $t('Shopify_Search_Placeholder') : $t('Shopify_Store_Search_First')"
              :disabled="!linkForm.store_id" :options="remoteOptions"
              @search="searchRemote"
            >
              <template v-if="remoteSearching" #notFoundContent><a-spin size="small" /></template>
              <template #option="{ title, handle, value, skus, taken }">
                <div class="opt-title">{{ title }}</div>
                <div class="sub">{{ handle }} · #{{ value }}<template v-if="skus"> · {{ skus }}</template></div>
                <div v-if="taken" class="sub warn">{{ $t('Shopify_Already_Linked_To') }} {{ taken }}</div>
              </template>
            </a-select>
          </a-form-item>
          <a-radio-group v-model:value="linkForm.mode" class="mode">
            <a-radio value="keep" class="mode-item">
              {{ $t('Shopify_Keep_Listing') }}
              <div class="sub">{{ $t('Shopify_Keep_Listing_Hint') }}</div>
            </a-radio>
            <a-radio value="replace" class="mode-item">
              {{ $t('Shopify_Replace_Listing') }}
              <div class="sub">{{ $t('Shopify_Replace_Listing_Hint') }}</div>
            </a-radio>
          </a-radio-group>
          <a-checkbox v-model:checked="linkForm.copy_remote" style="margin-top: 8px">{{ $t('Shopify_Copy_Remote') }}</a-checkbox>
        </template>
        <template v-else>
          <a-form-item label="ERP record id *" extra="The numeric id of the customer, order or category">
            <a-input-number v-model:value="linkForm.local_id" :min="1" style="width: 100%" />
          </a-form-item>
          <a-form-item label="Shopify id *" style="margin-bottom: 0">
            <a-input v-model:value="linkForm.shopify_id" placeholder="e.g. 8123456789012" allow-clear />
          </a-form-item>
        </template>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * The link table, made readable.
 *
 * This is the page people open when something synced to the wrong place, so it
 * shows the local record's name next to the remote id rather than two opaque
 * numbers, and a row whose local record has since been deleted says so instead
 * of rendering blank.
 */
import { ref, reactive, computed, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import { message, Modal } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { LinkOutlined, DisconnectOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import { LINK_TYPES, labelOf } from './shopifyOptions';
import http from '../../lib/http';

const route = useRoute();
const { t } = useI18n();
const { dateTime } = useFormat();

const filters = reactive({
  store_id: route.query.store_id ? Number(route.query.store_id) : undefined,
  entity_type: route.query.entity_type || undefined,
});

const crud = useCrudTable('shopify/mappings', {
  rowsKey: 'mappings',
  params: () => ({
    store_id: filters.store_id || '',
    entity_type: filters.entity_type || '',
  }),
});

const columns = computed(() => [
  { title: 'Type', key: 'entity_type', dataIndex: 'entity_type', width: 130 },
  { title: 'ERP record', key: 'local', dataIndex: 'local_name' },
  { title: 'Store', key: 'store_id', dataIndex: 'store_id', width: 170 },
  { title: 'Shopify id', key: 'shopify_id', dataIndex: 'shopify_id', width: 200 },
  { title: 'Secondary id', key: 'secondary_id', dataIndex: 'secondary_id', width: 160 },
  { title: 'Last synced', key: 'last_synced_at', dataIndex: 'last_synced_at', width: 160 },
  { title: '', key: 'actions', width: 70, align: 'center' },
]);

const stores = ref([]);
const storeOptions = computed(() => stores.value.map(s => ({ value: s.id, label: s.label || s.name })));

function storeName(storeId) {
  const store = stores.value.find(s => s.id === storeId);
  return store ? store.name : `#${storeId}`;
}

async function unlink(record) {
  try {
    await http.delete(`shopify/mappings/${record.id}`);
    message.success('Unlinked');
    crud.fetchRows();
  } catch (e) {
    message.error(e?.data?.message || 'Could not unlink that record');
  }
}

// ---------------- manual link ----------------

const linkOpen = ref(false);
const saving = ref(false);
const linkForm = reactive({
  store_id: undefined,
  entity_type: 'product',
  local_id: null,
  shopify_id: '',
  mode: 'keep',
  copy_remote: false,
});

// Products get pickers instead of two ids to type: the ERP side searches the
// catalogue, the Shopify side asks the shop live, and the pairing goes through
// the product-link endpoint so variants and the inventory item are linked too.
const productOptions = ref([]);
const productSearching = ref(false);
let productTimer = null;
function searchProducts(q) {
  clearTimeout(productTimer);
  productTimer = setTimeout(async () => {
    productSearching.value = true;
    try {
      const data = await http.get('products/search-basic', { q: q || '' });
      productOptions.value = (data?.products || []).map(p => ({ value: p.id, label: `${p.name} (${p.code})` }));
    } catch (e) { productOptions.value = []; } finally { productSearching.value = false; }
  }, 250);
}

const remoteOptions = ref([]);
const remoteSearching = ref(false);
let remoteTimer = null;
function searchRemote(q) {
  clearTimeout(remoteTimer);
  if (!q || !linkForm.store_id) { remoteOptions.value = []; return; }
  remoteTimer = setTimeout(async () => {
    remoteSearching.value = true;
    try {
      const data = await http.get('shopify/product-links/search', { store_id: linkForm.store_id, q });
      remoteOptions.value = (data?.products || []).map(r => ({
        value: r.id,
        label: r.title,
        title: r.title,
        handle: r.handle,
        skus: r.variants.map(v => v.sku).filter(Boolean).slice(0, 5).join(', '),
        taken: r.linked_to ? r.linked_to.name : null,
      }));
    } catch (e) {
      remoteOptions.value = [];
      message.error(e?.data?.message || 'Could not search the shop');
    } finally { remoteSearching.value = false; }
  }, 350);
}

async function submitLink(force = false) {
  if (!linkForm.store_id || !linkForm.local_id || !linkForm.shopify_id) {
    message.error('Fill in the store, the ERP record and the Shopify record');
    return;
  }

  saving.value = true;
  try {
    if (linkForm.entity_type === 'product') {
      await http.post('shopify/product-links', {
        store_id: linkForm.store_id,
        product_id: linkForm.local_id,
        shopify_id: String(linkForm.shopify_id),
        mode: linkForm.mode,
        copy_remote: linkForm.copy_remote ? 1 : 0,
        force: force ? 1 : 0,
      });
    } else {
      await http.post('shopify/mappings', {
        store_id: linkForm.store_id,
        entity_type: linkForm.entity_type,
        local_id: linkForm.local_id,
        shopify_id: String(linkForm.shopify_id),
      });
    }
    message.success('Linked');
    linkOpen.value = false;
    linkForm.local_id = null;
    linkForm.shopify_id = '';
    linkForm.copy_remote = false;
    crud.fetchRows();
  } catch (e) {
    if (e?.status === 409 && e?.data?.code === 'claimed') {
      Modal.confirm({
        title: e.data.message,
        okText: 'Move the link',
        cancelText: t('Cancel'),
        onOk: () => submitLink(true),
      });
    } else {
      message.error(e?.data?.message || 'Could not link those records');
    }
  } finally {
    saving.value = false;
  }
}

onMounted(async () => {
  crud.fetchRows();
  try {
    const meta = await http.get('shopify/meta');
    stores.value = meta?.stores || [];
    if (!linkForm.store_id && stores.value.length) linkForm.store_id = stores.value[0].id;
  } catch (e) { /* the selects stay empty */ }
});
</script>

<style scoped>
.muted {
  color: rgba(128, 128, 128, 0.7);
}
.sub {
  font-size: 11.5px;
  opacity: 0.55;
}
.code {
  font-size: 12px;
  padding: 1px 6px;
  border-radius: 5px;
  background: rgba(128, 128, 128, 0.14);
}
.lead {
  margin-bottom: 14px;
  font-size: 13px;
  opacity: 0.7;
}
.opt-title {
  font-weight: 500;
}
.warn {
  color: #faad14;
  opacity: 1;
}
.mode {
  display: flex;
  flex-direction: column;
  gap: 6px;
}
.mode-item {
  align-items: flex-start;
  white-space: normal;
}
.toolbar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 10px;
}
.tb-search {
  flex: 1 1 220px;
  min-width: 180px;
}
.tb-item {
  width: 180px;
}
@media (max-width: 767px) {
  .tb-item {
    width: 100%;
  }
}
</style>
