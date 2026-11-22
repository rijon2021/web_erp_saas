<template>
  <div class="page">
    <PageHeader :title="$t('Rst_Floor_Setup')" :subtitle="$t('Rst_Floor_Setup_Subtitle')" :breadcrumb="[$t('Restaurant_Management'), $t('Rst_Floor_Setup')]">
      <template #actions>
        <a-button @click="openQr"><QrcodeOutlined /> {{ $t('Rst_Table_Qr_Codes') }}</a-button>
        <a-button v-if="auth.can('rst_floor')" @click="$router.push('/restaurant/floor')">{{ $t('Rst_Open_Floor_Plan') }}</a-button>
      </template>
    </PageHeader>

    <div v-if="loading && first" class="loading"><a-spin size="large" /></div>
    <a-alert v-else-if="!ready" type="warning" show-icon :message="$t('Rst_Not_Ready')" />

    <a-row v-else :gutter="16">
      <!-- Areas -->
      <a-col :xs="24" :lg="8">
        <a-card size="small" :title="$t('Rst_Areas')">
          <template #extra><a-button type="primary" size="small" @click="openArea()"><PlusOutlined /> {{ $t('Rst_Add_Area') }}</a-button></template>
          <a-empty v-if="!areas.length" :description="$t('Rst_No_Areas_Yet')" />
          <div v-for="(a, i) in areas" :key="a.id" :class="['area-row', { active: a.id === areaId }]">
            <button type="button" class="area-pick" @click="areaId = a.id">
              <strong>{{ a.name }}</strong>
              <span class="muted small">{{ $t('Rst_Tables_N', { n: a.tables.length }) }} · {{ a.warehouse_name || $t('Rst_All_Branches') }}</span>
              <a-tag v-if="!a.is_active" color="default">{{ $t('Rst_Hidden') }}</a-tag>
            </button>
            <a-space :size="4">
              <a-button size="small" :disabled="i === 0" :aria-label="$t('Rst_Move_Up')" @click="moveArea(i, -1)">↑</a-button>
              <a-button size="small" :disabled="i === areas.length - 1" :aria-label="$t('Rst_Move_Down')" @click="moveArea(i, 1)">↓</a-button>
              <a-button size="small" :aria-label="$t('Edit')" @click="openArea(a)"><EditOutlined /></a-button>
              <a-popconfirm :title="$t('Rst_Delete_Area_Confirm', { name: a.name })" :ok-text="$t('Yes')" :cancel-text="$t('No')" @confirm="removeArea(a)">
                <a-button size="small" danger :aria-label="$t('Delete')"><DeleteOutlined /></a-button>
              </a-popconfirm>
            </a-space>
          </div>
        </a-card>
      </a-col>

      <!-- Tables of the selected area -->
      <a-col :xs="24" :lg="16">
        <a-card size="small" :title="area ? $t('Rst_Tables_In', { area: area.name }) : $t('Rst_Tables')">
          <template v-if="area" #extra>
            <a-space>
              <a-button size="small" @click="openBulk"><AppstoreAddOutlined /> {{ $t('Rst_Add_Several') }}</a-button>
              <a-button type="primary" size="small" @click="openTable()"><PlusOutlined /> {{ $t('Rst_Add_Table') }}</a-button>
            </a-space>
          </template>
          <a-empty v-if="!area" :description="$t('Rst_Add_Area_First')" />
          <a-table
            v-else :columns="columns" :data-source="area.tables" row-key="id" size="middle" :pagination="false"
            :scroll="{ x: 'max-content' }" :locale="{ emptyText: $t('Rst_No_Tables_Yet') }"
          >
            <template #bodyCell="{ column, record, index }">
              <template v-if="column.key === 'name'"><strong>{{ record.name }}</strong></template>
              <template v-else-if="column.key === 'shape'">{{ shapeLabel(record.shape) }}</template>
              <template v-else-if="column.key === 'status'">
                <a-tag v-if="!record.is_active">{{ $t('Rst_Hidden') }}</a-tag>
                <a-tag v-else-if="record.in_use" color="purple">{{ $t('Rst_In_Use') }}</a-tag>
                <a-tag v-else color="green">{{ $t('Rst_Ready') }}</a-tag>
              </template>
              <template v-else-if="column.key === 'actions'">
                <a-space :size="4">
                  <a-button size="small" :disabled="index === 0" :aria-label="$t('Rst_Move_Up')" @click="moveTable(index, -1)">↑</a-button>
                  <a-button size="small" :disabled="index === area.tables.length - 1" :aria-label="$t('Rst_Move_Down')" @click="moveTable(index, 1)">↓</a-button>
                  <a-button size="small" :aria-label="$t('Edit')" @click="openTable(record)"><EditOutlined /></a-button>
                  <a-popconfirm :title="$t('Rst_Delete_Table_Confirm', { name: record.name })" :ok-text="$t('Yes')" :cancel-text="$t('No')" @confirm="removeTable(record)">
                    <a-button size="small" danger :disabled="record.in_use" :aria-label="$t('Delete')"><DeleteOutlined /></a-button>
                  </a-popconfirm>
                </a-space>
              </template>
            </template>
          </a-table>
        </a-card>
      </a-col>
    </a-row>

    <!-- Area form -->
    <a-modal v-model:open="areaOpen" :title="areaForm.id ? $t('Rst_Edit_Area') : $t('Rst_Add_Area')" :ok-text="$t('Save')" :confirm-loading="saving" @ok="saveArea">
      <a-form layout="vertical">
        <a-form-item :label="$t('Name')" required><a-input v-model:value="areaForm.name" :maxlength="80" :placeholder="$t('Rst_Area_Name_Example')" /></a-form-item>
        <a-form-item :label="$t('Rst_Branch')" :extra="$t('Rst_Branch_Help')">
          <a-select v-model:value="areaForm.warehouse_id" allow-clear :placeholder="$t('Rst_All_Branches')" :options="warehouses.map(w => ({ value: w.id, label: w.name }))" />
        </a-form-item>
        <a-checkbox v-model:checked="areaForm.is_active">{{ $t('Rst_Show_On_Floor') }}</a-checkbox>
      </a-form>
    </a-modal>

    <!-- Table form -->
    <a-modal v-model:open="tableOpen" :title="tableForm.id ? $t('Rst_Edit_Table') : $t('Rst_Add_Table')" :ok-text="$t('Save')" :confirm-loading="saving" @ok="saveTable">
      <a-form layout="vertical">
        <a-row :gutter="12">
          <a-col :span="12"><a-form-item :label="$t('Name')" required><a-input v-model:value="tableForm.name" :maxlength="20" placeholder="T1" /></a-form-item></a-col>
          <a-col :span="12"><a-form-item :label="$t('Rst_Seats')" required><a-input-number v-model:value="tableForm.seats" :min="1" :max="50" style="width: 100%" /></a-form-item></a-col>
          <a-col :span="12"><a-form-item :label="$t('Rst_Shape')"><a-select v-model:value="tableForm.shape" :options="shapeOptions" /></a-form-item></a-col>
          <a-col :span="12"><a-form-item :label="$t('Rst_Area')"><a-select v-model:value="tableForm.area_id" :options="areas.map(a => ({ value: a.id, label: a.name }))" /></a-form-item></a-col>
        </a-row>
        <a-checkbox v-model:checked="tableForm.is_active">{{ $t('Rst_Show_On_Floor') }}</a-checkbox>
      </a-form>
    </a-modal>

    <!-- Bulk -->
    <a-modal v-model:open="bulkOpen" :title="$t('Rst_Add_Several')" :ok-text="$t('Rst_Add_N_Tables', { n: bulkCount })" :confirm-loading="saving" :ok-button-props="{ disabled: bulkCount < 1 || bulkCount > 100 }" @ok="saveBulk">
      <a-form layout="vertical">
        <a-row :gutter="12">
          <a-col :span="8"><a-form-item :label="$t('Rst_Prefix')"><a-input v-model:value="bulk.prefix" :maxlength="10" /></a-form-item></a-col>
          <a-col :span="8"><a-form-item :label="$t('Rst_From')"><a-input-number v-model:value="bulk.from" :min="0" :max="9999" style="width: 100%" /></a-form-item></a-col>
          <a-col :span="8"><a-form-item :label="$t('Rst_To')"><a-input-number v-model:value="bulk.to" :min="0" :max="9999" style="width: 100%" /></a-form-item></a-col>
          <a-col :span="12"><a-form-item :label="$t('Rst_Seats')"><a-input-number v-model:value="bulk.seats" :min="1" :max="50" style="width: 100%" /></a-form-item></a-col>
          <a-col :span="12"><a-form-item :label="$t('Rst_Shape')"><a-select v-model:value="bulk.shape" :options="shapeOptions" /></a-form-item></a-col>
        </a-row>
        <div class="muted">{{ bulkCount > 100 ? $t('Rst_Max_100') : $t('Rst_Bulk_Preview', { first: (bulk.prefix || '') + bulk.from, last: (bulk.prefix || '') + bulk.to }) }}</div>
      </a-form>
    </a-modal>

    <!-- Table QR codes (phase 8: guests order from their phone) -->
    <a-modal v-model:open="qrOpen" :title="$t('Rst_Table_Qr_Codes')" width="920px" :footer="null">
      <a-spin :spinning="qrLoading">
        <a-alert v-if="qr && !qr.enabled" type="warning" show-icon style="margin-bottom: 12px" :message="$t('Rst_Qr_Off')" :description="$t('Rst_Qr_Off_Help')" />
        <a-alert v-if="qr && qr.store_live === false" type="warning" show-icon style="margin-bottom: 12px" :message="$t('Rst_Qr_Store_Off')" />
        <p class="muted">{{ $t('Rst_Qr_Help') }}</p>
        <a-empty v-if="qr && !qr.tables.length" :description="$t('Rst_No_Tables_Yet')" />
        <div v-if="qr" class="qr-grid">
          <div v-for="tb in qr.tables" :key="tb.id" class="qr-card">
            <img v-if="tb.qr" :src="tb.qr" :alt="$t('Rst_Table_N', { name: tb.name })" />
            <strong>{{ $t('Rst_Table_N', { name: tb.name }) }}</strong>
            <span class="muted small">{{ tb.area }}</span>
            <a-typography-paragraph :copyable="{ text: tb.url }" class="qr-url">{{ tb.url }}</a-typography-paragraph>
            <a-space wrap>
              <a-button size="small" @click="printQr([tb])"><PrinterOutlined /> {{ $t('Rst_Print') }}</a-button>
              <a-popconfirm :title="$t('Rst_Qr_Renew_Confirm')" :ok-text="$t('Yes')" :cancel-text="$t('No')" @confirm="renewQr(tb)">
                <a-button size="small" danger>{{ $t('Rst_Qr_Renew') }}</a-button>
              </a-popconfirm>
            </a-space>
          </div>
        </div>
        <div v-if="qr && qr.tables.length" style="margin-top: 16px; text-align: end">
          <a-button type="primary" @click="printQr(qr.tables)"><PrinterOutlined /> {{ $t('Rst_Print_All_Qr') }}</a-button>
        </div>
      </a-spin>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * Restaurant › Floor setup (rst_setup) — GET restaurant/setup/floor;
 * areas: POST/PUT/DELETE restaurant/setup/areas[/{id}], POST .../areas/reorder;
 * tables: POST/PUT/DELETE restaurant/setup/tables[/{id}], POST .../tables/bulk, .../tables/reorder.
 */
import { ref, computed, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { PlusOutlined, EditOutlined, DeleteOutlined, AppstoreAddOutlined, QrcodeOutlined, PrinterOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';
import { useAuthStore } from '../../stores/auth';

const { t } = useI18n();
const auth = useAuthStore();

const loading = ref(true);
const first = ref(true);
const qrOpen = ref(false);
const qrLoading = ref(false);
const qr = ref(null);
const saving = ref(false);
const ready = ref(true);
const areas = ref([]);
const warehouses = ref([]);
const areaId = ref(null);
const areaOpen = ref(false);
const areaForm = ref({});
const tableOpen = ref(false);
const tableForm = ref({});
const bulkOpen = ref(false);
const bulk = ref({ prefix: 'T', from: 1, to: 10, seats: 4, shape: 'square' });

const area = computed(() => areas.value.find(a => a.id === areaId.value) || null);
const bulkCount = computed(() => (bulk.value.to ?? 0) - (bulk.value.from ?? 0) + 1);
const shapeOptions = computed(() => ['square', 'round', 'long'].map(v => ({ value: v, label: shapeLabel(v) })));
const columns = computed(() => [
  { title: t('Name'), key: 'name' },
  { title: t('Rst_Seats'), dataIndex: 'seats', key: 'seats', align: 'center' },
  { title: t('Rst_Shape'), key: 'shape' },
  { title: t('Status'), key: 'status' },
  { title: t('Actions'), key: 'actions', width: 170 },
]);

function shapeLabel(v) {
  return { square: t('Rst_Shape_Square'), round: t('Rst_Shape_Round'), long: t('Rst_Shape_Long') }[v] || v;
}
function fail(e) {
  const errors = e?.data?.errors;
  message.error((errors && Object.values(errors)[0]?.[0]) || e?.data?.message || t('Failed'));
}

async function load() {
  loading.value = true;
  try {
    const r = await http.get('restaurant/setup/floor');
    ready.value = r.ready !== false;
    areas.value = r.areas || [];
    warehouses.value = r.warehouses || [];
    if (!areas.value.some(a => a.id === areaId.value)) areaId.value = areas.value.length ? areas.value[0].id : null;
  } catch (e) {
    fail(e);
  } finally {
    loading.value = false;
    first.value = false;
  }
}

function openArea(a = null) {
  areaForm.value = a ? { id: a.id, name: a.name, warehouse_id: a.warehouse_id || undefined, is_active: a.is_active } : { name: '', warehouse_id: undefined, is_active: true };
  areaOpen.value = true;
}
async function saveArea() {
  if (!areaForm.value.name?.trim()) { message.error(t('Rst_Name_Required')); return; }
  saving.value = true;
  try {
    const body = { ...areaForm.value, warehouse_id: areaForm.value.warehouse_id || null };
    if (areaForm.value.id) await http.put(`restaurant/setup/areas/${areaForm.value.id}`, body);
    else { const r = await http.post('restaurant/setup/areas', body); areaId.value = r.id; }
    areaOpen.value = false;
    message.success(t('Successfully_Updated'));
    await load();
  } catch (e) { fail(e); } finally { saving.value = false; }
}
async function removeArea(a) {
  try { await http.delete(`restaurant/setup/areas/${a.id}`); message.success(t('Deleted_in_successfully')); await load(); } catch (e) { fail(e); }
}
async function moveArea(i, dir) {
  const list = [...areas.value];
  const [x] = list.splice(i, 1);
  list.splice(i + dir, 0, x);
  areas.value = list;
  try { await http.post('restaurant/setup/areas/reorder', { ids: list.map(a => a.id) }); } catch (e) { fail(e); await load(); }
}

function openTable(tb = null) {
  tableForm.value = tb
    ? { id: tb.id, name: tb.name, seats: tb.seats, shape: tb.shape, is_active: tb.is_active, area_id: areaId.value }
    : { name: '', seats: 4, shape: 'square', is_active: true, area_id: areaId.value };
  tableOpen.value = true;
}
async function saveTable() {
  if (!tableForm.value.name?.trim()) { message.error(t('Rst_Name_Required')); return; }
  saving.value = true;
  try {
    if (tableForm.value.id) await http.put(`restaurant/setup/tables/${tableForm.value.id}`, tableForm.value);
    else await http.post('restaurant/setup/tables', tableForm.value);
    tableOpen.value = false;
    message.success(t('Successfully_Updated'));
    await load();
  } catch (e) { fail(e); } finally { saving.value = false; }
}
async function removeTable(tb) {
  try { await http.delete(`restaurant/setup/tables/${tb.id}`); message.success(t('Deleted_in_successfully')); await load(); } catch (e) { fail(e); }
}
async function moveTable(i, dir) {
  const list = [...area.value.tables];
  const [x] = list.splice(i, 1);
  list.splice(i + dir, 0, x);
  area.value.tables = list;
  try { await http.post('restaurant/setup/tables/reorder', { area_id: area.value.id, ids: list.map(tb => tb.id) }); } catch (e) { fail(e); await load(); }
}

function openBulk() { bulkOpen.value = true; }
async function saveBulk() {
  saving.value = true;
  try {
    const r = await http.post('restaurant/setup/tables/bulk', { ...bulk.value, area_id: areaId.value });
    bulkOpen.value = false;
    message.success(t('Rst_Bulk_Result', { created: (r.created || []).length, skipped: (r.skipped || []).length }));
    await load();
  } catch (e) { fail(e); } finally { saving.value = false; }
}

onMounted(load);

// ------------------------------------------------------------------ table QR codes
async function openQr() {
  qrOpen.value = true;
  qrLoading.value = true;
  try { qr.value = await http.get('restaurant/setup/qr-codes'); } catch (e) { message.error(e?.data?.message || t('Failed')); } finally { qrLoading.value = false; }
}
async function renewQr(tb) {
  try {
    const r = await http.post(`restaurant/setup/tables/${tb.id}/qr/renew`);
    Object.assign(tb, { url: r.url, qr: r.qr });
    message.success(t('Rst_Qr_Renewed'));
  } catch (e) { message.error(e?.data?.message || t('Failed')); }
}
/** Table cards on A4 (two per row, cut lines) through a hidden iframe. */
function printQr(list) {
  const esc = v => String(v ?? '').replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  const store = esc(qr.value?.store_name || '');
  const cards = list.map(tb => `<div class="c"><div class="s">${store}</div><div class="t">${esc(t('Rst_Table_N', { name: tb.name }))}</div>${tb.qr ? `<img src="${esc(tb.qr)}" alt="">` : ''}<div class="h">${esc(t('Rst_Qr_Scan_Text'))}</div></div>`).join('');
  const html = `<!doctype html><html><head><meta charset="utf-8"><title>${esc(t('Rst_Table_Qr_Codes'))}</title><style>
    @page { size: A4; margin: 10mm; } body { margin: 0; font-family: system-ui, sans-serif; }
    .g { display: grid; grid-template-columns: 1fr 1fr; } .c { border: 1px dashed #999; padding: 10mm 6mm; text-align: center; page-break-inside: avoid; }
    .s { font-size: 14px; color: #555; } .t { font-size: 30px; font-weight: 800; margin: 2mm 0 4mm; } img { width: 62mm; height: 62mm; } .h { font-size: 15px; margin-top: 3mm; }
    </style></head><body><div class="g">${cards}</div></body></html>`;
  const frame = document.createElement('iframe');
  frame.setAttribute('aria-hidden', 'true');
  frame.style.cssText = 'position:fixed;width:0;height:0;border:0;right:0;bottom:0';
  document.body.appendChild(frame);
  frame.contentDocument.open();
  frame.contentDocument.write(html);
  frame.contentDocument.close();
  setTimeout(() => { frame.contentWindow.focus(); frame.contentWindow.print(); setTimeout(() => frame.remove(), 1500); }, 300);
}
</script>

<style scoped>
.loading { display: flex; justify-content: center; padding: 96px 0; }
.muted { color: rgba(0, 0, 0, 0.55); }
.small { font-size: 12px; }
.area-row { display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 8px; border-radius: 10px; }
.area-row.active { background: #f5f3ff; }
.area-pick { flex: 1; min-width: 0; display: flex; flex-direction: column; align-items: flex-start; gap: 2px; background: none; border: 0; padding: 4px; text-align: left; cursor: pointer; font: inherit; color: inherit; }
.area-pick:focus-visible { outline: 2px solid #6d28d9; border-radius: 6px; }
.qr-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 12px; }
.qr-card { border: 1px solid rgba(5, 5, 5, 0.08); border-radius: 12px; padding: 12px; display: flex; flex-direction: column; align-items: center; gap: 4px; text-align: center; }
.qr-card img { width: 150px; height: 150px; }
.qr-url { font-size: 11px; word-break: break-all; margin: 4px 0 6px !important; }
</style>
