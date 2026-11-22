<template>
  <div class="page">
    <PageHeader
      :title="$t('Shp_Mappings')"
      :subtitle="$t('Shp_Mappings_Subtitle')"
      :breadcrumb="['Shopify', $t('Shp_Mappings')]"
    >
      <template #actions>
        <a-button @click="linkOpen = true">
          <template #icon><LinkOutlined /></template>
          {{ $t('Shp_Link_Manually') }}
        </a-button>
      </template>
    </PageHeader>

    <a-card size="small" style="margin-bottom: 16px">
      <div class="toolbar">
        <a-input-search
          v-model:value="crud.search.value" :placeholder="$t('Shp_Search_Id_Handle')"
          allow-clear class="tb-search" @search="crud.reload"
        />
        <a-select
          v-model:value="filters.store_id" class="tb-item" allow-clear show-search
          option-filter-prop="label" :placeholder="$t('Shp_All_Stores')"
          :options="storeOptions" @change="crud.reload"
        />
        <a-select
          v-model:value="filters.entity_type" class="tb-item" allow-clear
          :placeholder="$t('All_Types')" :options="LINK_TYPES" @change="crud.reload"
        />
      </div>
    </a-card>

    <a-alert
      type="info" show-icon banner style="margin-bottom: 12px"
      :message="$t('Shp_Unlink_Info')"
    />

    <DataTable :crud="crud" :columns="columns" :searchable="false">
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'entity_type'">
          <a-tag>{{ labelOf(LINK_TYPES, record.entity_type) }}</a-tag>
        </template>
        <template v-else-if="column.key === 'local'">
          <span v-if="record.local_name">{{ record.local_name }}</span>
          <span v-else class="muted">{{ $t('Shp_Record_Missing', { id: record.local_id }) }}</span>
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
            :title="$t('Shp_Forget_Pairing')"
            :ok-text="$t('Shopify_Unlink')" :cancel-text="$t('Cancel')"
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
      :open="linkOpen" :title="$t('Shp_Link_By_Hand')" :width="520"
      :confirm-loading="saving" :ok-text="$t('Link')" :cancel-text="$t('Cancel')"
      @ok="submitLink(false)" @cancel="linkOpen = false"
    >
      <p class="lead">
        {{ $t('Shp_Link_By_Hand_Lead') }}
      </p>
      <a-form layout="vertical">
        <a-form-item :label="$t('Store') + ' *'">
          <a-select
            v-model:value="linkForm.store_id" show-search option-filter-prop="label"
            :options="storeOptions" :placeholder="$t('Shp_Select_A_Store')"
          />
        </a-form-item>
        <a-form-item :label="$t('Shp_Record_Type') + ' *'">
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
          <a-form-item :label="$t('Shp_ERP_Record_Id') + ' *'" :extra="$t('Shp_ERP_Record_Id_Hint')">
            <a-input-number v-model:value="linkForm.local_id" :min="1" style="width: 100%" />
          </a-form-item>
          <a-form-item :label="$t('Shp_Shopify_Id') + ' *'" style="margin-bottom: 0">
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
  { title: t('Type'), key: 'entity_type', dataIndex: 'entity_type', width: 130 },
  { title: t('Shp_ERP_Record'), key: 'local', dataIndex: 'local_name' },
  { title: t('Store'), key: 'store_id', dataIndex: 'store_id', width: 170 },
  { title: t('Shp_Shopify_Id'), key: 'shopify_id', dataIndex: 'shopify_id', width: 200 },
  { title: t('Shp_Secondary_Id'), key: 'secondary_id', dataIndex: 'secondary_id', width: 160 },
  { title: t('Shp_Last_Synced'), key: 'last_synced_at', dataIndex: 'last_synced_at', width: 160 },
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
    message.success(t('Shp_Unlinked'));
    crud.fetchRows();
  } catch (e) {
    message.error(e?.data?.message || t('Shp_Could_Not_Unlink'));
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
      message.error(e?.data?.message || t('Shp_Could_Not_Search_Shop'));
    } finally { remoteSearching.value = false; }
  }, 350);
}

async function submitLink(force = false) {
  if (!linkForm.store_id || !linkForm.local_id || !linkForm.shopify_id) {
    message.error(t('Shp_Fill_Link_Fields'));
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
    message.success(t('Shp_Linked'));
    linkOpen.value = false;
    linkForm.local_id = null;
    linkForm.shopify_id = '';
    linkForm.copy_remote = false;
    crud.fetchRows();
  } catch (e) {
    if (e?.status === 409 && e?.data?.code === 'claimed') {
      Modal.confirm({
        title: e.data.message,
        okText: t('Shp_Move_Link'),
        cancelText: t('Cancel'),
        onOk: () => submitLink(true),
      });
    } else {
      message.error(e?.data?.message || t('Shp_Could_Not_Link_Records'));
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
