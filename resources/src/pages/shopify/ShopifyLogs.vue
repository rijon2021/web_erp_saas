<template>
  <div class="page">
    <PageHeader
      :title="$t('Page_Shopify_Logs')"
      :subtitle="$t('Shp_Logs_Subtitle')"
      :breadcrumb="['Shopify', $t('Logs')]"
    >
      <template #actions>
        <!-- The safe one stays the button; wiping everything is a deliberate
             trip into the menu rather than something adjacent to a stray click. -->
        <a-dropdown-button danger :loading="clearing" @click="confirmClear('old')">
          <DeleteOutlined />
          {{ $t('Shp_Clear_Old_Logs') }}
          <template #overlay>
            <a-menu @click="({ key }) => confirmClear(key)">
              <a-menu-item key="old">
                <DeleteOutlined /> {{ $t('Shp_Older_Than_7_Days') }}
              </a-menu-item>
              <a-menu-item v-if="filters.store_id" key="store">
                <DeleteOutlined /> {{ $t('Shp_Everything_For', { name: currentStoreName }) }}
              </a-menu-item>
              <a-menu-divider />
              <a-menu-item key="all" danger>
                <DeleteOutlined /> {{ $t('Shp_Everything_All_Stores') }}
              </a-menu-item>
            </a-menu>
          </template>
        </a-dropdown-button>
      </template>
    </PageHeader>

    <a-card size="small" style="margin-bottom: 16px">
      <div class="toolbar">
        <a-input-search
          v-model:value="crud.search.value" :placeholder="$t('Shp_Search_Message_Action')"
          allow-clear class="tb-search" @search="crud.reload"
        />
        <a-select
          v-model:value="filters.store_id" class="tb-item" allow-clear show-search
          option-filter-prop="label" :placeholder="$t('Shp_All_Stores')"
          :options="storeOptions" @change="crud.reload"
        />
        <a-select
          v-model:value="filters.level" class="tb-item" allow-clear
          :placeholder="$t('Shp_All_Levels')" :options="LOG_LEVELS" @change="crud.reload"
        />
        <a-select
          v-model:value="filters.entity" class="tb-item" allow-clear
          :placeholder="$t('Shp_All_Entities')" :options="entityOptions" @change="crud.reload"
        />
        <a-range-picker v-model:value="filters.range" class="tb-range" value-format="YYYY-MM-DD" @change="crud.reload" />
        <a-tag v-if="filters.run_id" closable color="purple" class="tb-run" @close="clearRunFilter">
          {{ $t('Shp_Run_N', { id: filters.run_id }) }}
        </a-tag>
      </div>
    </a-card>

    <DataTable :crud="crud" :columns="columns" :searchable="false">
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'level'">
          <a-tag :color="optionOf(LOG_LEVELS, record.level).color">
            {{ labelOf(LOG_LEVELS, record.level) }}
          </a-tag>
        </template>
        <template v-else-if="column.key === 'store_id'">
          {{ storeName(record.store_id) }}
        </template>
        <template v-else-if="column.key === 'action'">
          <code class="code">{{ record.action }}</code>
        </template>
        <template v-else-if="column.key === 'message'">
          <div class="msg" :class="{ 'msg--bad': record.level === 'error' }">{{ record.message }}</div>
        </template>
        <template v-else-if="column.key === 'created_at'">
          {{ dateTime(record.created_at) }}
        </template>
        <template v-else-if="column.key === 'actions'">
          <a-button
            v-if="record.context && Object.keys(record.context).length"
            type="link" size="small" @click="inspect(record)"
          >
            {{ $t('Details') }}
          </a-button>
        </template>
      </template>
    </DataTable>

    <a-drawer
      :open="drawerOpen" :title="$t('Cr_Log_Entry')" placement="right" :width="520"
      @close="drawerOpen = false"
    >
      <template v-if="selected">
        <a-descriptions bordered size="small" :column="1">
          <a-descriptions-item :label="$t('Level')">
            <a-tag :color="optionOf(LOG_LEVELS, selected.level).color">
              {{ labelOf(LOG_LEVELS, selected.level) }}
            </a-tag>
          </a-descriptions-item>
          <a-descriptions-item :label="$t('Action')">{{ selected.action }}</a-descriptions-item>
          <a-descriptions-item :label="$t('Shp_Entity')">{{ selected.entity || '—' }}</a-descriptions-item>
          <a-descriptions-item :label="$t('Store')">{{ storeName(selected.store_id) }}</a-descriptions-item>
          <a-descriptions-item :label="$t('Run')">
            <a v-if="selected.run_id" class="link" @click="filterByRun(selected.run_id)">#{{ selected.run_id }}</a>
            <span v-else>—</span>
          </a-descriptions-item>
          <a-descriptions-item :label="$t('When')">{{ dateTime(selected.created_at) }}</a-descriptions-item>
        </a-descriptions>

        <p class="drawer-msg">{{ selected.message }}</p>

        <a-typography-title :level="5">{{ $t('Context') }}</a-typography-title>
        <pre class="context">{{ JSON.stringify(selected.context, null, 2) }}</pre>
      </template>
    </a-drawer>
  </div>
</template>

<script setup>
/**
 * The audit trail.
 *
 * The context payload lives behind a drawer rather than in the table: it is what
 * you need when diagnosing one failure, and noise on every other row. Clearing
 * defaults to logs older than a week so a stray click cannot wipe the evidence
 * of the failure someone is currently debugging.
 */
import { ref, reactive, computed, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import { message, Modal } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { DeleteOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import { LOG_LEVELS, ENTITIES, labelOf, optionOf } from './shopifyOptions';
import http from '../../lib/http';

const route = useRoute();
const { t } = useI18n();
const { dateTime } = useFormat();

const filters = reactive({
  store_id: route.query.store_id ? Number(route.query.store_id) : undefined,
  level: route.query.level || undefined,
  entity: undefined,
  run_id: route.query.run_id ? Number(route.query.run_id) : undefined,
  range: null,
});

const crud = useCrudTable('shopify/logs', {
  rowsKey: 'logs',
  params: () => ({
    store_id: filters.store_id || '',
    level: filters.level || '',
    entity: filters.entity || '',
    run_id: filters.run_id || '',
    from: filters.range?.[0] || '',
    to: filters.range?.[1] || '',
  }),
});

const entityOptions = ENTITIES.map(e => ({ value: e.value, label: e.label }))
  .concat([{ value: 'webhooks', label: t('Webhooks') }]);

const columns = computed(() => [
  { title: t('When'), key: 'created_at', dataIndex: 'created_at', width: 160 },
  { title: t('Level'), key: 'level', dataIndex: 'level', width: 100 },
  { title: t('Store'), key: 'store_id', dataIndex: 'store_id', width: 150 },
  { title: t('Action'), key: 'action', dataIndex: 'action', width: 170 },
  { title: t('Message'), key: 'message', dataIndex: 'message' },
  { title: '', key: 'actions', width: 90, align: 'center' },
]);

const stores = ref([]);
const storeOptions = computed(() => stores.value.map(s => ({ value: s.id, label: s.label || s.name })));

function storeName(storeId) {
  if (!storeId) return '—';
  const store = stores.value.find(s => s.id === storeId);
  return store ? store.name : `#${storeId}`;
}

// ---------------- drawer ----------------

const drawerOpen = ref(false);
const selected = ref(null);

function inspect(record) {
  selected.value = record;
  drawerOpen.value = true;
}

function filterByRun(runId) {
  filters.run_id = runId;
  drawerOpen.value = false;
  crud.reload();
}

function clearRunFilter() {
  filters.run_id = undefined;
  crud.reload();
}

// ---------------- clear ----------------

const clearing = ref(false);

const currentStoreName = computed(() => {
  const store = stores.value.find(s => s.id === filters.store_id);

  return store?.name || store?.label || t('Shp_This_Store');
});

/**
 * Three scopes, one endpoint.
 *
 * `all` is what lifts the server's seven-day guard, so the wording of each
 * confirmation has to be honest about how much disappears — the whole point of
 * that guard is the failure somebody is still reading.
 *
 * @param {'old'|'store'|'all'} scope
 */
function confirmClear(scope) {
  const scoped = filters.store_id ? `?store_id=${filters.store_id}` : '';

  const plans = {
    old: {
      title: t('Shp_Clear_Old_Title'),
      content: t('Shp_Clear_Old_Content'),
      // http.delete takes no params argument, so the filter has to travel in
      // the URL. Without this the store filter is silently dropped and the
      // clear applies to every store's logs, not the one on screen.
      query: scoped,
    },
    store: {
      title: t('Shp_Clear_Store_Title', { name: currentStoreName.value }),
      content: t('Shp_Clear_Store_Content'),
      query: `${scoped}${scoped ? '&' : '?'}all=1`,
    },
    all: {
      title: t('Shp_Clear_All_Title'),
      content: t('Shp_Clear_All_Content'),
      query: '?all=1',
    },
  };

  const plan = plans[scope] || plans.old;

  Modal.confirm({
    title: plan.title,
    content: plan.content,
    okText: scope === 'old' ? t('Clear') : t('Shp_Delete_Everything'),
    okType: 'danger',
    cancelText: t('Cancel'),
    async onOk() {
      clearing.value = true;
      try {
        const res = await http.delete(`shopify/logs${plan.query}`);
        message.success(t('Shp_Removed_N_Entries', { n: res?.deleted ?? 0 }));
        crud.fetchRows();
      } catch (e) {
        message.error(t('Shp_Could_Not_Clear_Logs'));
      } finally {
        clearing.value = false;
      }
    },
  });
}

onMounted(async () => {
  crud.fetchRows();
  try {
    const meta = await http.get('shopify/meta');
    stores.value = meta?.stores || [];
  } catch (e) { /* the store select stays empty */ }
});
</script>

<style scoped>
.link {
  color: #5f9e3f;
  cursor: pointer;
}
.code {
  font-size: 11.5px;
  padding: 1px 6px;
  border-radius: 5px;
  background: rgba(128, 128, 128, 0.14);
}
.msg {
  overflow: hidden;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  font-size: 12.5px;
}
.msg--bad {
  color: #dc2626;
}
.toolbar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 10px;
}
.tb-search {
  flex: 1 1 200px;
  min-width: 170px;
}
.tb-item {
  width: 160px;
}
.tb-range {
  width: 230px;
}
.tb-run {
  font-size: 12px;
}
.drawer-msg {
  margin: 16px 0;
  font-size: 13px;
}
.context {
  background: rgba(128, 128, 128, 0.1);
  padding: 12px;
  border-radius: 8px;
  font-size: 11.5px;
  overflow-x: auto;
  max-height: 420px;
}
@media (max-width: 767px) {
  .tb-item,
  .tb-range {
    width: 100%;
  }
}
</style>
