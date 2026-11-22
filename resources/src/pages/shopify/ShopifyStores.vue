<template>
  <div class="page">
    <PageHeader
      :title="$t('Shopify_Stores')"
      :subtitle="$t('Shp_Stores_Subtitle')"
      :breadcrumb="['Shopify', $t('Stores')]"
    >
      <template #actions>
        <a-button type="primary" @click="openForm(null)">
          <template #icon><PlusOutlined /></template>
          {{ $t('Shp_Connect_Store') }}
        </a-button>
      </template>
    </PageHeader>

    <a-card size="small" style="margin-bottom: 16px">
      <div class="toolbar">
        <a-input-search
          v-model:value="crud.search.value" :placeholder="$t('Shp_Search_Name_Domain')"
          allow-clear class="tb-search" @search="crud.reload"
        />
        <a-select
          v-model:value="filters.status" class="tb-item" allow-clear
          :placeholder="$t('all_statuses')" :options="STORE_STATUSES" @change="crud.reload"
        />
      </div>
    </a-card>

    <DataTable :crud="crud" :columns="columns" :searchable="false">
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'name'">
          <a class="link" @click="$router.push(`/shopify/stores/${record.id}`)">{{ record.name }}</a>
          <div class="sub">{{ record.shop_domain }}</div>
        </template>
        <template v-else-if="column.key === 'status'">
          <a-tooltip :title="record.last_error || ''">
            <a-tag :color="optionOf(STORE_STATUSES, record.status).color">
              {{ labelOf(STORE_STATUSES, record.status) }}
            </a-tag>
          </a-tooltip>
        </template>
        <template v-else-if="column.key === 'target'">
          <div v-if="record.warehouse_name || record.location_id" class="target">
            <span>{{ record.warehouse_name || $t('No_Warehouse') }}</span>
            <span class="sub">{{ $t('Shp_Location_N', { id: record.location_id || '—' }) }}</span>
          </div>
          <a-tag v-else color="warning">{{ $t('Shp_Not_Configured') }}</a-tag>
        </template>
        <template v-else-if="column.key === 'entities'">
          <a-space :size="2" wrap>
            <a-tag v-for="e in enabledEntities(record)" :key="e" class="ent-tag">{{ e }}</a-tag>
            <span v-if="!enabledEntities(record).length" class="muted">{{ $t('None') }}</span>
          </a-space>
        </template>
        <template v-else-if="column.key === 'linked_records'">
          {{ number(record.linked_records || 0, 0) }}
        </template>
        <template v-else-if="column.key === 'recent_errors'">
          <a-tag v-if="record.recent_errors" color="error">{{ record.recent_errors }}</a-tag>
          <span v-else class="muted">—</span>
        </template>
        <template v-else-if="column.key === 'last_sync_at'">
          {{ record.last_sync_at ? dateTime(record.last_sync_at) : '—' }}
          <div v-if="record.auto_sync" class="auto-note">
            {{ $t('Shp_Auto_Every_N_Min', { n: record.sync_interval_minutes }) }}
          </div>
        </template>
        <template v-else-if="column.key === 'actions'">
          <a-space :size="0">
            <a-tooltip :title="$t('Test_Connection')">
              <a-button type="text" size="small" :loading="testingId === record.id" @click="test(record)">
                <template #icon><ApiOutlined /></template>
              </a-button>
            </a-tooltip>
            <a-tooltip :title="$t('Sync')">
              <a-button type="text" size="small" @click="$router.push(`/shopify/sync?store_id=${record.id}`)">
                <template #icon><SyncOutlined style="color: #5f9e3f" /></template>
              </a-button>
            </a-tooltip>
            <a-tooltip :title="$t('Edit')">
              <a-button type="text" size="small" @click="openForm(record)">
                <template #icon><EditOutlined /></template>
              </a-button>
            </a-tooltip>
            <a-tooltip :title="$t('Disconnect')">
              <a-button type="text" size="small" danger @click="confirmRemove(record)">
                <template #icon><DeleteOutlined /></template>
              </a-button>
            </a-tooltip>
          </a-space>
        </template>
      </template>
    </DataTable>

    <ShopifyStoreForm
      v-model:open="formOpen"
      :store="editing"
      :warehouses="meta.warehouses || []"
      @saved="onSaved"
    />
  </div>
</template>

<script setup>
/**
 * The connected shops.
 *
 * Disconnecting is confirmed with a spelled-out consequence rather than a bare
 * "are you sure": it drops every mapping for that shop, and someone who expects
 * it to be reversible needs to know before clicking, not after.
 */
import { ref, reactive, computed, onMounted } from 'vue';
import { message, Modal } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import {
  PlusOutlined, EditOutlined, DeleteOutlined, ApiOutlined, SyncOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import ShopifyStoreForm from './ShopifyStoreForm.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import { STORE_STATUSES, ENTITIES, labelOf, optionOf } from './shopifyOptions';
import http from '../../lib/http';

const { t } = useI18n();
const { number, dateTime } = useFormat();

const filters = reactive({ status: undefined });

const crud = useCrudTable('shopify/stores', {
  rowsKey: 'stores',
  params: () => ({ status: filters.status || '' }),
});

const columns = computed(() => [
  { title: t('Store'), key: 'name', dataIndex: 'name', sorter: true },
  { title: t('Status'), key: 'status', dataIndex: 'status', sorter: true, width: 130 },
  { title: t('Shp_Syncs_Into'), key: 'target', width: 180 },
  { title: t('Shp_Entities'), key: 'entities', width: 260 },
  { title: t('Shp_Linked'), key: 'linked_records', dataIndex: 'linked_records', width: 100, align: 'right' },
  { title: t('Shp_Errors_7d'), key: 'recent_errors', dataIndex: 'recent_errors', width: 110, align: 'center' },
  { title: t('Last_Sync'), key: 'last_sync_at', dataIndex: 'last_sync_at', sorter: true, width: 160 },
  { title: '', key: 'actions', width: 150, align: 'center' },
]);

/** Short labels for whichever entity switches are on. */
function enabledEntities(record) {
  return ENTITIES.filter(e => record[`sync_${e.value}`]).map(e => e.label);
}

// ---------------- form ----------------

const meta = ref({ warehouses: [] });
const formOpen = ref(false);
const editing = ref(null);

function openForm(record) {
  editing.value = record;
  formOpen.value = true;
}

function onSaved() {
  formOpen.value = false;
  editing.value = null;
  crud.fetchRows();
}

// ---------------- actions ----------------

const testingId = ref(null);

async function test(record) {
  testingId.value = record.id;
  try {
    const res = await http.post(`shopify/stores/${record.id}/test`);
    message.success(t('Shp_Connected_To', { name: res?.shop?.name || record.shop_domain }));
  } catch (e) {
    message.error(e?.data?.error || e?.data?.message || t('Shp_Could_Not_Reach_Shop'));
  } finally {
    testingId.value = null;
    crud.fetchRows();
  }
}

function confirmRemove(record) {
  Modal.confirm({
    title: t('Shp_Disconnect_Confirm', { name: record.name }),
    content: t('Shp_Disconnect_Content'),
    okText: t('Disconnect'),
    okType: 'danger',
    cancelText: t('Cancel'),
    async onOk() {
      try {
        await http.delete(`shopify/stores/${record.id}`);
        message.success(t('Shp_Store_Disconnected'));
        crud.fetchRows();
      } catch (e) {
        message.error(e?.data?.message || t('Shp_Could_Not_Disconnect_Store'));
      }
    },
  });
}

onMounted(async () => {
  crud.fetchRows();
  try {
    meta.value = await http.get('shopify/meta');
  } catch (e) { /* the warehouse select stays empty */ }
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
.auto-note {
  font-size: 11px;
  opacity: 0.55;
}
.link {
  color: #5f9e3f;
  cursor: pointer;
  font-weight: 500;
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
.target {
  display: flex;
  flex-direction: column;
  font-size: 12.5px;
}
.ent-tag {
  margin-inline-end: 2px;
  font-size: 11px;
  line-height: 18px;
}
@media (max-width: 767px) {
  .tb-item {
    width: 100%;
  }
}
</style>
