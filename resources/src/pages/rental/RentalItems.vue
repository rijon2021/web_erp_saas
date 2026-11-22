<template>
  <div class="page">
    <PageHeader :title="$t('Rental_Items')" :breadcrumb="[$t('Rental_Management'), $t('Rental_Items')]">
      <template #actions>
        <a-button v-if="canDashboard" @click="$router.push('/rental/dashboard')">
          <template #icon><DashboardOutlined /></template>
          {{ $t('Dashboard') }}
        </a-button>
        <a-button v-if="canAdd" type="primary" @click="$router.push('/rental/items/create')">
          <template #icon><PlusOutlined /></template>
          {{ $t('Rental_Add_Item') }}
        </a-button>
      </template>
    </PageHeader>

    <!-- Type switcher: the register mixes flats and drills, so the first cut is by kind -->
    <div v-if="typeOptions.length > 1 || filters.type" class="type-tabs" role="tablist">
      <button
        type="button" class="type-tab" :class="{ active: !filters.type }"
        role="tab" :aria-selected="!filters.type" @click="setType(undefined)"
      >
        {{ $t('All') }}
      </button>
      <button
        v-for="o in typeOptions" :key="o.value" type="button" class="type-tab"
        :class="{ active: filters.type === o.value }" role="tab"
        :aria-selected="filters.type === o.value" @click="setType(o.value)"
      >
        <component :is="o.icon" :size="15" :stroke-width="1.8" />
        {{ o.label }}
      </button>
    </div>

    <a-card size="small" style="margin-bottom: 16px">
      <div class="toolbar">
        <a-input-search
          v-model:value="crud.search.value" :placeholder="$t('Rental_Search_Items')"
          allow-clear class="tb-search" @search="crud.reload"
        />
        <a-select
          v-model:value="filters.status" class="tb-item" allow-clear
          :placeholder="$t('Rental_All_Statuses')" :options="statusOptions" @change="crud.reload"
        />
        <a-select
          v-model:value="filters.category_id" class="tb-item" allow-clear show-search
          option-filter-prop="label" :placeholder="$t('Category')"
          :options="categoryOptions" @change="crud.reload"
        />
        <a-select
          v-model:value="filters.warehouse_id" class="tb-item" allow-clear show-search
          option-filter-prop="label" :placeholder="$t('Warehouse')"
          :options="warehouseOptions" @change="crud.reload"
        />
        <a-segmented v-model:value="view" :options="viewOptions" class="tb-view">
          <template #label="{ value, label }">
            <span class="seg-label">
              <AppstoreOutlined v-if="value === 'grid'" />
              <UnorderedListOutlined v-else />
              {{ label }}
            </span>
          </template>
        </a-segmented>
      </div>
    </a-card>

    <!-- Card view: rentals are recognised by photo -->
    <template v-if="view === 'grid'">
      <a-spin :spinning="crud.loading.value">
        <div v-if="crud.rows.value.length" class="grid">
          <article v-for="item in crud.rows.value" :key="item.id" class="rcard" @click="open(item)">
            <div class="rcard-media">
              <img v-if="item.image_url" :src="item.image_url" :alt="item.name" loading="lazy" />
              <span v-else class="rcard-placeholder">
                <component :is="typeIcon(item.type)" :size="44" :stroke-width="1.4" />
              </span>
              <a-tag class="rcard-status" :color="optionOf(RENTAL_STATUSES, item.status).color">
                {{ labelOf(RENTAL_STATUSES, item.status) }}
              </a-tag>
              <span class="rcard-type">
                <component :is="typeIcon(item.type)" :size="13" :stroke-width="2" />
                {{ labelOf(RENTAL_TYPES, item.type) }}
              </span>
              <span v-if="item.image_count > 1" class="rcard-count">
                <PictureOutlined /> {{ item.image_count }}
              </span>
              <a-tooltip v-if="item.show_on_website === false" :title="$t('Rental_Hidden_From_Website')">
                <span class="rcard-hidden"><EyeInvisibleOutlined /></span>
              </a-tooltip>
            </div>

            <div class="rcard-body">
              <div class="rcard-head">
                <h3 class="rcard-name" :title="item.name">{{ item.name }}</h3>
                <span class="rcard-code">{{ item.code }}</span>
              </div>
              <p class="rcard-sub" :title="item.identifier || ''">
                <EnvironmentOutlined v-if="item.type === 'property' && item.identifier" />
                {{ item.identifier || item.category_name || '—' }}
              </p>

              <div class="rcard-price">
                <template v-if="item.headline_rate !== null">
                  <span class="rcard-amount">{{ money(item.headline_rate) }}</span>
                  <span class="rcard-per">{{ perLabel(item.default_period) }}</span>
                </template>
                <span v-else class="muted">{{ $t('Rental_No_Price') }}</span>
                <span v-if="item.quantity > 1" class="rcard-qty">× {{ item.quantity }}</span>
              </div>
            </div>

            <footer class="rcard-foot" @click.stop>
              <span class="rcard-cat">{{ item.category_name || '' }}</span>
              <span>
                <a-button v-if="canEdit" type="text" size="small" @click="$router.push(`/rental/items/${item.id}/edit`)">
                  <template #icon><EditOutlined /></template>
                </a-button>
                <a-button v-if="canDelete" type="text" size="small" danger @click="crud.remove(item, { label: item.name })">
                  <template #icon><DeleteOutlined /></template>
                </a-button>
              </span>
            </footer>
          </article>
        </div>

        <a-card v-else class="empty-card">
          <div class="empty">
            <span class="empty-ic"><KeyRound :size="30" :stroke-width="1.6" /></span>
            <h3>{{ hasFilters ? $t('Rental_No_Match') : $t('Rental_No_Items_Yet') }}</h3>
            <p>{{ hasFilters ? $t('Rental_No_Match_Help') : $t('Rental_No_Items_Help') }}</p>
            <a-button v-if="canAdd && !hasFilters" type="primary" @click="$router.push('/rental/items/create')">
              <template #icon><PlusOutlined /></template>
              {{ $t('Rental_Add_First_Item') }}
            </a-button>
            <a-button v-else-if="hasFilters" @click="clearFilters">{{ $t('Rental_Clear_Filters') }}</a-button>
          </div>
        </a-card>
      </a-spin>

      <div v-if="crud.total.value > crud.limit.value" class="pager">
        <a-pagination
          :current="crud.page.value" :page-size="crud.limit.value" :total="crud.total.value"
          :page-size-options="['12', '24', '48', '96']" show-size-changer @change="onPageChange"
        />
      </div>
    </template>

    <!-- Table view -->
    <DataTable v-else :crud="crud" :columns="columns" :searchable="false" selectable>
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'name'">
          <button type="button" class="cell-item" @click="open(record)">
            <img v-if="record.image_url" class="cell-thumb" :src="record.image_url" :alt="record.name" loading="lazy" />
            <span v-else class="cell-thumb cell-thumb--empty">
              <component :is="typeIcon(record.type)" :size="18" :stroke-width="1.6" />
            </span>
            <span class="cell-text">
              <span class="cell-name">{{ record.name }}</span>
              <span class="cell-code">{{ record.code }}</span>
            </span>
          </button>
        </template>
        <template v-else-if="column.key === 'type'">
          <span class="cell-type">
            <component :is="typeIcon(record.type)" :size="15" :stroke-width="1.7" />
            {{ labelOf(RENTAL_TYPES, record.type) }}
          </span>
        </template>
        <template v-else-if="column.key === 'status'">
          <a-tag :color="optionOf(RENTAL_STATUSES, record.status).color">
            {{ labelOf(RENTAL_STATUSES, record.status) }}
          </a-tag>
        </template>
        <template v-else-if="column.key === 'condition'">
          <a-tag :color="optionOf(RENTAL_CONDITIONS, record.condition).color" :bordered="false">
            {{ labelOf(RENTAL_CONDITIONS, record.condition) }}
          </a-tag>
        </template>
        <template v-else-if="column.key === 'price'">
          <template v-if="record.headline_rate !== null">
            {{ money(record.headline_rate) }} <span class="muted">{{ perLabel(record.default_period) }}</span>
          </template>
          <span v-else class="muted">—</span>
        </template>
        <template v-else-if="column.key === 'deposit_amount'">
          {{ record.deposit_amount ? money(record.deposit_amount) : '—' }}
        </template>
        <template v-else-if="column.key === 'actions'">
          <a-space :size="0">
            <a-tooltip :title="$t('View')">
              <a-button type="text" size="small" @click="open(record)">
                <template #icon><EyeOutlined /></template>
              </a-button>
            </a-tooltip>
            <a-tooltip v-if="canEdit" :title="$t('Edit')">
              <a-button type="text" size="small" @click="$router.push(`/rental/items/${record.id}/edit`)">
                <template #icon><EditOutlined /></template>
              </a-button>
            </a-tooltip>
            <a-tooltip v-if="canDelete" :title="$t('Delete')">
              <a-button type="text" size="small" danger @click="crud.remove(record, { label: record.name })">
                <template #icon><DeleteOutlined /></template>
              </a-button>
            </a-tooltip>
          </a-space>
        </template>
      </template>
    </DataTable>
  </div>
</template>

<script setup>
/**
 * Rental register. Two views over the SAME useCrudTable instance: photo cards
 * and a table. Status/type/category filters can arrive as query params — the
 * dashboard tiles and the categories page link straight into a filtered list.
 */
import { ref, reactive, computed, watch, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import {
  PlusOutlined, EditOutlined, DeleteOutlined, EyeOutlined, DashboardOutlined,
  AppstoreOutlined, UnorderedListOutlined, PictureOutlined, EnvironmentOutlined, EyeInvisibleOutlined,
} from '@ant-design/icons-vue';
import { KeyRound } from 'lucide-vue-next';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import { useAuthStore } from '../../stores/auth';
import http from '../../lib/http';
import { t } from '../../i18n';
import {
  RENTAL_TYPES, RENTAL_STATUSES, RENTAL_CONDITIONS,
  tr, labelOf, optionOf, typeIcon, perLabel,
} from './rentalOptions';
import { useRentalTypes } from './rentalTypes';

const auth = useAuthStore();
const route = useRoute();
const router = useRouter();
const { money } = useFormat();

const canAdd = computed(() => auth.can('rnt_items_add'));
const canEdit = computed(() => auth.can('rnt_items_edit'));
const canDelete = computed(() => auth.can('rnt_items_delete'));
const canDashboard = computed(() => auth.can('rnt_dashboard'));

const VIEW_KEY = 'rental_items_view';
const view = ref(readView());
function readView() {
  try {
    return window.localStorage.getItem(VIEW_KEY) === 'list' ? 'list' : 'grid';
  } catch (e) {
    return 'grid';
  }
}
const viewOptions = computed(() => [
  { value: 'grid', label: t('Rental_Cards', 'Cards') },
  { value: 'list', label: t('Rental_Table', 'Table') },
]);

// Only the tenant's business types (Rental Settings › Business type).
const rentalTypes = useRentalTypes();
const typeOptions = computed(() => rentalTypes.enabledOptions(filters.type));
const statusOptions = computed(() => tr(RENTAL_STATUSES));

const filters = reactive({
  type: route.query.type || undefined,
  status: route.query.status || undefined,
  category_id: route.query.category_id ? Number(route.query.category_id) : undefined,
  warehouse_id: undefined,
});

const crud = useCrudTable('rental/items', {
  rowsKey: 'items',
  limit: 12,
  sortField: 'created_at',
  params: () => ({
    type: filters.type || '',
    status: filters.status || '',
    category_id: filters.category_id || '',
    warehouse_id: filters.warehouse_id || '',
  }),
});

const hasFilters = computed(() =>
  !!filters.type || !!filters.status || !!filters.category_id || !!filters.warehouse_id || !!crud.search.value);

function setType(type) {
  filters.type = type;
  // A category of another type would leave the list empty with a raw id in the select.
  const cat = categories.value.find(c => c.id === Number(filters.category_id));
  if (type && cat && cat.type && cat.type !== type) filters.category_id = undefined;
  crud.reload();
}

function clearFilters() {
  filters.type = undefined;
  filters.status = undefined;
  filters.category_id = undefined;
  filters.warehouse_id = undefined;
  crud.search.value = '';
  crud.reload();
}

function onPageChange(page, pageSize) {
  crud.page.value = page;
  crud.limit.value = pageSize;
  crud.fetchRows();
}

function open(item) {
  router.push(`/rental/items/${item.id}`);
}

const categories = ref([]);
const warehouses = ref([]);
const categoryOptions = computed(() => categories.value
  .filter(c => !filters.type || !c.type || c.type === filters.type)
  .map(c => ({ value: c.id, label: c.name })));
const warehouseOptions = computed(() => warehouses.value.map(w => ({ value: w.id, label: w.name })));

const columns = computed(() => [
  { title: t('Rental_Item'), key: 'name', dataIndex: 'name', sorter: true },
  { title: t('type'), key: 'type', dataIndex: 'type', sorter: true, width: 130 },
  { title: t('Category'), dataIndex: 'category_name', key: 'category', width: 150 },
  { title: t('Status'), key: 'status', dataIndex: 'status', sorter: true, width: 130 },
  { title: t('Rental_Condition'), key: 'condition', dataIndex: 'condition', sorter: true, width: 120 },
  { title: t('Price'), key: 'price', width: 170 },
  { title: t('Rental_Deposit'), key: 'deposit_amount', dataIndex: 'deposit_amount', sorter: true, width: 130 },
  { title: t('Warehouse'), dataIndex: 'warehouse_name', key: 'warehouse', width: 140 },
  { title: '', key: 'actions', width: 120 },
]);

// Remember the chosen view per browser.
watch(view, (v) => {
  try { window.localStorage.setItem(VIEW_KEY, v); } catch (e) { /* storage blocked */ }
});

onMounted(async () => {
  crud.fetchRows();
  try {
    const data = await http.get('rental/items/meta');
    categories.value = data?.categories || [];
    warehouses.value = data?.warehouses || [];
  } catch (e) { /* filters stay empty */ }
});
</script>

<style scoped>
.muted {
  color: rgba(128, 128, 128, 0.75);
}

/* ---------------- type tabs ---------------- */
.type-tabs {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-bottom: 12px;
}
.type-tab {
  display: inline-flex;
  align-items: center;
  gap: 7px;
  padding: 7px 14px;
  border: 1px solid rgba(128, 128, 128, 0.25);
  border-radius: 999px;
  background: transparent;
  color: inherit;
  font: inherit;
  font-size: 13px;
  cursor: pointer;
  transition: border-color 0.15s ease, background 0.15s ease, color 0.15s ease;
}
.type-tab:hover {
  border-color: rgba(var(--app-primary-rgb, 109, 40, 217), 0.5);
}
.type-tab.active {
  border-color: var(--app-primary, #6d28d9);
  background: rgba(var(--app-primary-rgb, 109, 40, 217), 0.1);
  color: var(--app-primary, #6d28d9);
  font-weight: 500;
}

/* ---------------- toolbar ---------------- */
.toolbar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 10px;
}
.tb-search {
  flex: 1 1 240px;
  min-width: 200px;
}
.tb-item {
  width: 170px;
}
.tb-view {
  margin-inline-start: auto;
}
.seg-label {
  display: inline-flex;
  align-items: center;
  gap: 6px;
}

/* ---------------- card grid ---------------- */
.grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
  gap: 16px;
}
.rcard {
  display: flex;
  flex-direction: column;
  border: 1px solid rgba(128, 128, 128, 0.22);
  border-radius: 16px;
  overflow: hidden;
  cursor: pointer;
  transition: border-color 0.15s ease, box-shadow 0.15s ease, transform 0.12s ease;
}
.rcard:hover {
  border-color: rgba(var(--app-primary-rgb, 109, 40, 217), 0.55);
  box-shadow: 0 8px 22px rgba(0, 0, 0, 0.09);
  transform: translateY(-2px);
}
.rcard-media {
  position: relative;
  height: 160px;
  background: rgba(128, 128, 128, 0.1);
  display: flex;
  align-items: center;
  justify-content: center;
}
.rcard-media img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}
.rcard-placeholder {
  opacity: 0.25;
}
.rcard-status {
  position: absolute;
  inset-block-start: 10px;
  inset-inline-end: 10px;
  margin: 0;
}
.rcard-type,
.rcard-count {
  position: absolute;
  inset-block-end: 10px;
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 3px 9px;
  border-radius: 999px;
  font-size: 11.5px;
  font-weight: 500;
  color: #fff;
  background: rgba(15, 23, 42, 0.62);
  backdrop-filter: blur(4px);
}
.rcard-type {
  inset-inline-start: 10px;
}
.rcard-count {
  inset-inline-end: 10px;
}
/* Not published on the Rental store theme. */
.rcard-hidden {
  position: absolute;
  inset-block-start: 10px;
  inset-inline-start: 10px;
  width: 26px;
  height: 26px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 999px;
  color: #fff;
  background: rgba(15, 23, 42, 0.62);
}
.rcard-body {
  padding: 12px 14px 4px;
  flex: 1;
}
.rcard-head {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 8px;
}
.rcard-name {
  margin: 0;
  font-size: 15px;
  font-weight: 600;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.rcard-code {
  flex: none;
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
  font-size: 11.5px;
  padding: 1px 6px;
  border-radius: 5px;
  background: rgba(128, 128, 128, 0.16);
}
.rcard-sub {
  margin: 3px 0 10px;
  font-size: 12.5px;
  opacity: 0.62;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.rcard-price {
  display: flex;
  align-items: baseline;
  gap: 5px;
}
.rcard-amount {
  font-size: 18px;
  font-weight: 700;
  color: var(--app-primary, #6d28d9);
}
.rcard-per {
  font-size: 12.5px;
  opacity: 0.6;
}
.rcard-qty {
  margin-inline-start: auto;
  font-size: 12px;
  font-weight: 500;
  opacity: 0.7;
}
.rcard-foot {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 6px;
  padding: 6px 10px 8px 14px;
}
.rcard-cat {
  font-size: 12px;
  opacity: 0.55;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.pager {
  display: flex;
  justify-content: flex-end;
  margin-top: 16px;
}

/* ---------------- empty state ---------------- */
.empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  text-align: center;
  padding: 40px 16px;
}
.empty-ic {
  width: 64px;
  height: 64px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 20px;
  margin-bottom: 14px;
  color: var(--app-primary, #6d28d9);
  background: rgba(var(--app-primary-rgb, 109, 40, 217), 0.1);
}
.empty h3 {
  margin: 0 0 6px;
  font-size: 16px;
  font-weight: 600;
}
.empty p {
  margin: 0 0 16px;
  max-width: 420px;
  opacity: 0.65;
}

/* ---------------- table cells ---------------- */
.cell-item {
  display: flex;
  align-items: center;
  gap: 10px;
  border: 0;
  background: none;
  padding: 0;
  cursor: pointer;
  color: inherit;
  font: inherit;
  text-align: start;
  min-width: 200px;
}
.cell-thumb {
  width: 44px;
  height: 34px;
  flex: none;
  border-radius: 7px;
  object-fit: cover;
  background: rgba(128, 128, 128, 0.14);
}
.cell-thumb--empty {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  opacity: 0.45;
}
.cell-type {
  display: inline-flex;
  align-items: center;
  gap: 7px;
}
.cell-text {
  min-width: 0;
}
.cell-name {
  display: block;
  font-weight: 500;
}
.cell-code {
  display: block;
  font-size: 11.5px;
  opacity: 0.55;
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
}
.cell-item:hover .cell-name {
  color: var(--app-primary, #6d28d9);
}

@media (max-width: 767px) {
  .tb-item,
  .tb-view {
    width: 100%;
    margin-inline-start: 0;
  }
}
</style>
