<template>
  <div class="page">
    <PageHeader :title="$t('Rst_Menu')" :subtitle="$t('Rst_Menu_Subtitle')" :breadcrumb="[$t('Restaurant_Management'), $t('Rst_Menu')]" />

    <div v-if="first" class="loading"><a-spin size="large" /></div>
    <a-alert v-else-if="!ready" type="warning" show-icon :message="$t('Rst_Not_Ready')" />

    <a-card v-else size="small" :body-style="{ paddingTop: 0 }">
      <a-tabs v-model:activeKey="tab">
        <!-- ===== Dishes ===== -->
        <a-tab-pane key="dishes" :tab="$t('Rst_Dishes')">
          <div class="toolbar">
            <a-input-search v-model:value="dq.search" :placeholder="$t('Search')" allow-clear style="max-width: 240px" @search="loadDishes(1)" @change="debouncedDishes" />
            <a-select v-model:value="dq.category_id" allow-clear :placeholder="$t('Category')" style="width: 200px" show-search option-filter-prop="label"
              :options="categories.map(c => ({ value: c.id, label: c.name }))" @change="loadDishes(1)" />
            <label class="switch-row"><a-switch v-model:checked="dq.configured" size="small" @change="loadDishes(1)" /> {{ $t('Rst_Only_Set_Up') }}</label>
          </div>
          <a-table :columns="dishColumns" :data-source="dishes" :loading="dishLoading" row-key="id" size="middle" :scroll="{ x: 'max-content' }"
            :pagination="{ current: dq.page, pageSize: 20, total: dishTotal, showSizeChanger: false }" @change="p => loadDishes(p.current)">
            <template #bodyCell="{ column, record }">
              <template v-if="column.key === 'name'">
                <div class="dish-name">
                  <img :src="record.image_url" alt="" class="thumb" />
                  <div><strong>{{ record.name }}</strong><div class="muted small">{{ record.category || '—' }} · {{ record.code }}</div></div>
                </div>
              </template>
              <template v-else-if="column.key === 'station'">{{ record.details.station || '—' }}</template>
              <template v-else-if="column.key === 'addons'">
                <a-tag v-for="gid in record.addon_group_ids" :key="gid">{{ groupName(gid) }}</a-tag>
                <span v-if="!record.addon_group_ids.length" class="muted">—</span>
              </template>
              <template v-else-if="column.key === 'tags'">
                <a-tag v-for="d in record.details.dietary" :key="d" :color="dietColor(d)">{{ $t('Rst_Diet_' + d) }}</a-tag>
                <a-tag v-if="record.details.spice_level" color="red">{{ $t('Rst_Spice_' + record.details.spice_level) }}</a-tag>
              </template>
              <template v-else-if="column.key === 'sold_out'">
                <a-switch :checked="record.details.sold_out" :checked-children="$t('Rst_Sold_Out')" @change="v => setSoldOut(record, v)" />
              </template>
              <template v-else-if="column.key === 'actions'">
                <a-button size="small" @click="openDish(record)"><EditOutlined /> {{ $t('Edit') }}</a-button>
              </template>
            </template>
          </a-table>
        </a-tab-pane>

        <!-- ===== Add-on groups ===== -->
        <a-tab-pane key="addons" :tab="$t('Rst_Addon_Groups')">
          <div class="toolbar">
            <span class="muted">{{ $t('Rst_Addon_Groups_Help') }}</span>
            <a-button type="primary" style="margin-inline-start: auto" @click="openGroup()"><PlusOutlined /> {{ $t('Rst_Add_Group') }}</a-button>
          </div>
          <a-empty v-if="!groups.length" :description="$t('Rst_No_Groups')" />
          <a-row :gutter="[16, 16]">
            <a-col v-for="g in groups" :key="g.id" :xs="24" :lg="12">
              <a-card size="small" :class="{ inactive: !g.is_active }">
                <template #title>
                  <span>{{ g.name }}</span>
                  <a-tag :color="g.min_select ? 'orange' : 'default'" style="margin-inline-start: 8px">{{ ruleText(g) }}</a-tag>
                </template>
                <template #extra>
                  <a-space :size="4">
                    <span class="muted small">{{ $t('Rst_Used_By', { n: g.dishes }) }}</span>
                    <a-button size="small" :aria-label="$t('Edit')" @click="openGroup(g)"><EditOutlined /></a-button>
                    <a-popconfirm :title="$t('Rst_Delete_Group_Confirm', { name: g.name })" :ok-text="$t('Yes')" :cancel-text="$t('No')" @confirm="removeGroup(g)">
                      <a-button size="small" danger :aria-label="$t('Delete')"><DeleteOutlined /></a-button>
                    </a-popconfirm>
                  </a-space>
                </template>
                <div v-for="o in g.options" :key="o.id" class="opt-row">
                  <span class="opt-name">{{ o.name }}<a-tag v-if="o.is_default" style="margin-inline-start: 6px">{{ $t('Rst_Default') }}</a-tag></span>
                  <span class="muted small">{{ o.product_name ? $t('Rst_Uses_Stock', { qty: o.stock_qty, product: o.product_name }) : '' }}</span>
                  <span class="num">{{ o.price ? '+' + money(o.price) : $t('Rst_Free_Choice') }}</span>
                  <a-switch size="small" :checked="o.is_available" :aria-label="$t('Rst_Available')" @change="v => setOptionAvailable(o, v)" />
                </div>
              </a-card>
            </a-col>
          </a-row>
        </a-tab-pane>

        <!-- ===== Stations ===== -->
        <a-tab-pane key="stations" :tab="$t('Rst_Stations')">
          <div class="toolbar">
            <span class="muted">{{ $t('Rst_Stations_Help') }}</span>
            <a-space style="margin-inline-start: auto">
              <a-button @click="addDefaultStations">{{ $t('Rst_Add_Usual_Stations') }}</a-button>
              <a-button type="primary" @click="openStation()"><PlusOutlined /> {{ $t('Rst_Add_Station') }}</a-button>
            </a-space>
          </div>
          <a-empty v-if="!stations.length" :description="$t('Rst_No_Stations')" />
          <div v-for="(s, i) in stations" :key="s.id" class="station-row">
            <strong>{{ s.name }}</strong>
            <a-tag v-if="!s.is_active">{{ $t('Rst_Hidden') }}</a-tag>
            <a-space :size="4" style="margin-inline-start: auto">
              <a-button size="small" :disabled="i === 0" :aria-label="$t('Rst_Move_Up')" @click="moveStation(i, -1)">↑</a-button>
              <a-button size="small" :disabled="i === stations.length - 1" :aria-label="$t('Rst_Move_Down')" @click="moveStation(i, 1)">↓</a-button>
              <a-button size="small" :aria-label="$t('Edit')" @click="openStation(s)"><EditOutlined /></a-button>
              <a-popconfirm :title="$t('Rst_Delete_Station_Confirm', { name: s.name })" :ok-text="$t('Yes')" :cancel-text="$t('No')" @confirm="removeStation(s)">
                <a-button size="small" danger :aria-label="$t('Delete')"><DeleteOutlined /></a-button>
              </a-popconfirm>
            </a-space>
          </div>
        </a-tab-pane>

        <!-- ===== Meal times ===== -->
        <a-tab-pane key="times" :tab="$t('Rst_Meal_Times')">
          <p class="muted">{{ $t('Rst_Meal_Times_Help') }}</p>
          <a-form layout="vertical" style="max-width: 520px">
            <a-row v-for="p in ['breakfast', 'lunch', 'dinner']" :key="p" :gutter="12" align="middle">
              <a-col :span="8"><strong>{{ $t('Rst_Meal_' + p) }}</strong></a-col>
              <a-col :span="8"><a-form-item :label="$t('Rst_From')"><a-time-picker v-model:value="times[p].from" format="HH:mm" value-format="HH:mm" :allow-clear="false" style="width: 100%" /></a-form-item></a-col>
              <a-col :span="8"><a-form-item :label="$t('Rst_To')"><a-time-picker v-model:value="times[p].to" format="HH:mm" value-format="HH:mm" :allow-clear="false" style="width: 100%" /></a-form-item></a-col>
            </a-row>
            <a-button type="primary" :loading="saving" @click="saveTimes">{{ $t('Save') }}</a-button>
          </a-form>
        </a-tab-pane>
      </a-tabs>
    </a-card>

    <!-- Dish drawer -->
    <a-drawer v-model:open="dishOpen" :title="dishForm.name" width="520" :footer-style="{ textAlign: 'right' }">
      <a-form layout="vertical">
        <a-row :gutter="12">
          <a-col :span="12"><a-form-item :label="$t('Rst_Kitchen_Station')"><a-select v-model:value="dishForm.station_id" allow-clear :placeholder="$t('Rst_Any_Station')" :options="stations.filter(s => s.is_active).map(s => ({ value: s.id, label: s.name }))" /></a-form-item></a-col>
          <a-col :span="12"><a-form-item :label="$t('Rst_Prep_Time')"><a-input-number v-model:value="dishForm.prep_minutes" :min="0" :max="600" style="width: 100%" :addon-after="$t('Rst_Minutes')" /></a-form-item></a-col>
          <a-col :span="12"><a-form-item :label="$t('Rst_Calories')"><a-input-number v-model:value="dishForm.calories" :min="0" :max="20000" style="width: 100%" addon-after="kcal" /></a-form-item></a-col>
          <a-col :span="12"><a-form-item :label="$t('Rst_Spice_Level')"><a-select v-model:value="dishForm.spice_level" allow-clear :placeholder="$t('Rst_Not_Spicy')" :options="['mild', 'medium', 'hot'].map(v => ({ value: v, label: $t('Rst_Spice_' + v) }))" /></a-form-item></a-col>
        </a-row>
        <a-form-item :label="$t('Rst_Addon_Groups')" :extra="$t('Rst_Addon_Order_Hint')">
          <a-select v-model:value="dishForm.addon_group_ids" mode="multiple" allow-clear :placeholder="$t('Rst_No_Addons')" option-filter-prop="label" :options="groups.filter(g => g.is_active).map(g => ({ value: g.id, label: g.name + ' · ' + ruleText(g) }))" />
        </a-form-item>
        <a-form-item :label="$t('Rst_Dietary')"><a-checkbox-group v-model:value="dishForm.dietary" :options="['vegetarian', 'vegan', 'spicy', 'halal', 'gluten_free'].map(v => ({ value: v, label: $t('Rst_Diet_' + v) }))" /></a-form-item>
        <a-form-item :label="$t('Rst_Served_During')" :extra="$t('Rst_Served_During_Hint')"><a-checkbox-group v-model:value="dishForm.meal_periods" :options="['breakfast', 'lunch', 'dinner'].map(v => ({ value: v, label: $t('Rst_Meal_' + v) }))" /></a-form-item>
        <p class="muted small">{{ $t('Rst_Recipe_Hint') }}</p>
      </a-form>
      <template #footer>
        <a-space><a-button @click="dishOpen = false">{{ $t('Cancel') }}</a-button><a-button type="primary" :loading="saving" @click="saveDish">{{ $t('Save') }}</a-button></a-space>
      </template>
    </a-drawer>

    <!-- Group modal -->
    <a-modal v-model:open="groupOpen" :title="groupForm.id ? $t('Rst_Edit_Group') : $t('Rst_Add_Group')" width="820px" :ok-text="$t('Save')" :confirm-loading="saving" @ok="saveGroup">
      <a-form layout="vertical">
        <a-row :gutter="12">
          <a-col :xs="24" :md="10"><a-form-item :label="$t('Name')" required><a-input v-model:value="groupForm.name" :maxlength="80" :placeholder="$t('Rst_Group_Name_Example')" /></a-form-item></a-col>
          <a-col :xs="12" :md="5"><a-form-item :label="$t('Rst_Min_Choices')" :extra="$t('Rst_Min_Hint')"><a-input-number v-model:value="groupForm.min_select" :min="0" :max="20" style="width: 100%" /></a-form-item></a-col>
          <a-col :xs="12" :md="5"><a-form-item :label="$t('Rst_Max_Choices')"><a-input-number v-model:value="groupForm.max_select" :min="1" :max="20" style="width: 100%" /></a-form-item></a-col>
          <a-col :xs="24" :md="4"><a-form-item :label="$t('Rst_Active')"><a-switch v-model:checked="groupForm.is_active" /></a-form-item></a-col>
        </a-row>
        <div class="muted small" style="margin-bottom: 8px">{{ ruleText(groupForm) }}</div>
        <div class="opt-head"><span>{{ $t('Rst_Option') }}</span><span>{{ $t('Rst_Extra_Price') }}</span><span>{{ $t('Rst_Uses_Stock_Of') }}</span><span>{{ $t('Rst_Qty') }}</span><span /></div>
        <div v-for="(o, i) in groupForm.options" :key="o._key" class="opt-edit">
          <a-input v-model:value="o.name" :maxlength="80" :placeholder="$t('Rst_Option_Example')" />
          <a-input-number v-model:value="o.price" :min="0" style="width: 100%" />
          <a-select v-model:value="o.product_id" allow-clear show-search :filter-option="false" :placeholder="$t('Rst_No_Stock')" :options="productOptions(o)" @search="q => searchProducts(q)" />
          <a-input-number v-model:value="o.stock_qty" :min="0.001" :disabled="!o.product_id" style="width: 100%" />
          <a-space :size="2">
            <a-tooltip :title="$t('Rst_Default')"><a-checkbox v-model:checked="o.is_default" /></a-tooltip>
            <a-button size="small" :disabled="i === 0" :aria-label="$t('Rst_Move_Up')" @click="moveOpt(i, -1)">↑</a-button>
            <a-button size="small" danger :aria-label="$t('Delete')" @click="groupForm.options.splice(i, 1)"><DeleteOutlined /></a-button>
          </a-space>
        </div>
        <a-button type="dashed" block @click="addOpt"><PlusOutlined /> {{ $t('Rst_Add_Option') }}</a-button>
      </a-form>
    </a-modal>

    <!-- Station modal -->
    <a-modal v-model:open="stationOpen" :title="stationForm.id ? $t('Rst_Edit_Station') : $t('Rst_Add_Station')" :ok-text="$t('Save')" :confirm-loading="saving" @ok="saveStation">
      <a-form layout="vertical">
        <a-form-item :label="$t('Name')" required><a-input v-model:value="stationForm.name" :maxlength="60" /></a-form-item>
        <a-checkbox v-model:checked="stationForm.is_active">{{ $t('Rst_Active') }}</a-checkbox>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * Restaurant › Menu (rst_menu) — GET restaurant/menu/setup + restaurant/menu/dishes;
 * PUT restaurant/menu/dishes/{id}, POST .../dishes/{id}/sold-out;
 * stations / addon-groups CRUD, POST .../options/{id}/availability, PUT restaurant/menu/times.
 */
import { ref, computed, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { PlusOutlined, EditOutlined, DeleteOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';
import { useFormat } from '../../composables/useFormat';

const { t } = useI18n();
const { moneyBase } = useFormat();

const first = ref(true);
const ready = ref(true);
const saving = ref(false);
const tab = ref('dishes');
const stations = ref([]);
const groups = ref([]);
const categories = ref([]);
const times = ref({ breakfast: { from: '06:00', to: '11:30' }, lunch: { from: '11:30', to: '16:00' }, dinner: { from: '16:00', to: '23:59' } });

const dishes = ref([]);
const dishTotal = ref(0);
const dishLoading = ref(false);
const dq = ref({ search: '', category_id: undefined, configured: false, page: 1 });
const dishOpen = ref(false);
const dishForm = ref({});

const groupOpen = ref(false);
const groupForm = ref({ options: [] });
const productHits = ref([]);
const stationOpen = ref(false);
const stationForm = ref({});
let searchTimer = null;
let keySeq = 0;

const dishColumns = computed(() => [
  { title: t('Rst_Dish'), key: 'name' },
  { title: t('Price'), dataIndex: 'price', key: 'price', customRender: ({ text }) => money(text) },
  { title: t('Rst_Kitchen_Station'), key: 'station' },
  { title: t('Rst_Addon_Groups'), key: 'addons' },
  { title: t('Rst_Dietary'), key: 'tags' },
  { title: t('Rst_Sold_Out_Today'), key: 'sold_out' },
  { title: t('Actions'), key: 'actions' },
]);

function money(v) { return moneyBase(Number(v || 0)); }
function fail(e) {
  const errors = e?.data?.errors;
  message.error((errors && Object.values(errors)[0]?.[0]) || e?.data?.message || t('Failed'));
}
function groupName(id) { const g = groups.value.find(x => x.id === id); return g ? g.name : '#' + id; }
function dietColor(d) { return { vegetarian: 'green', vegan: 'green', spicy: 'red', halal: 'blue', gluten_free: 'gold' }[d] || 'default'; }
function ruleText(g) {
  const min = Number(g.min_select || 0); const max = Math.max(min, Number(g.max_select || 1));
  if (min === 0) return max === 1 ? t('Rst_Rule_Optional_One') : t('Rst_Rule_Optional_Up_To', { n: max });
  if (min === max) return min === 1 ? t('Rst_Rule_Required_One') : t('Rst_Rule_Required_Exactly', { n: min });
  return t('Rst_Rule_Between', { min, max });
}

async function loadSetup() {
  try {
    const r = await http.get('restaurant/menu/setup');
    ready.value = r.ready !== false;
    stations.value = r.stations || [];
    groups.value = r.groups || [];
    categories.value = r.categories || [];
    if (r.menu_times) times.value = JSON.parse(JSON.stringify(r.menu_times));
  } catch (e) { fail(e); } finally { first.value = false; }
}
async function loadDishes(page = dq.value.page) {
  dq.value.page = page;
  dishLoading.value = true;
  try {
    const r = await http.get('restaurant/menu/dishes', { search: dq.value.search, category_id: dq.value.category_id, only: dq.value.configured ? 'configured' : '', page, per_page: 20 });
    dishes.value = r.data || [];
    dishTotal.value = r.meta?.total || 0;
  } catch (e) { fail(e); } finally { dishLoading.value = false; }
}
function debouncedDishes() { clearTimeout(searchTimer); searchTimer = setTimeout(() => loadDishes(1), 350); }

function openDish(d) {
  dishForm.value = { id: d.id, name: d.name, ...JSON.parse(JSON.stringify(d.details)), station_id: d.details.station_id || undefined, spice_level: d.details.spice_level || undefined, addon_group_ids: [...d.addon_group_ids] };
  dishOpen.value = true;
}
async function saveDish() {
  saving.value = true;
  try {
    const f = dishForm.value;
    await http.put(`restaurant/menu/dishes/${f.id}`, { station_id: f.station_id || null, prep_minutes: f.prep_minutes ?? null, calories: f.calories ?? null, spice_level: f.spice_level || null, dietary: f.dietary, meal_periods: f.meal_periods, addon_group_ids: f.addon_group_ids });
    dishOpen.value = false;
    message.success(t('Successfully_Updated'));
    await Promise.all([loadDishes(), loadSetup()]);
  } catch (e) { fail(e); } finally { saving.value = false; }
}
async function setSoldOut(d, v) {
  try { await http.post(`restaurant/menu/dishes/${d.id}/sold-out`, { sold_out: v }); d.details.sold_out = v; } catch (e) { fail(e); }
}

// ----- add-on groups -----
function blankOpt() { return { _key: ++keySeq, id: null, name: '', price: 0, product_id: undefined, product_name: null, stock_qty: 1, is_default: false, is_available: true }; }
function openGroup(g = null) {
  groupForm.value = g
    ? { id: g.id, name: g.name, min_select: g.min_select, max_select: g.max_select, is_active: g.is_active, options: g.options.map(o => ({ ...o, _key: ++keySeq, product_id: o.product_id || undefined })) }
    : { name: '', min_select: 0, max_select: 1, is_active: true, options: [blankOpt(), blankOpt()] };
  productHits.value = [];
  groupOpen.value = true;
}
function addOpt() { groupForm.value.options.push(blankOpt()); }
function moveOpt(i, dir) { const o = groupForm.value.options; const [x] = o.splice(i, 1); o.splice(i + dir, 0, x); }
function productOptions(o) {
  const list = productHits.value.map(p => ({ value: p.id, label: p.name }));
  if (o.product_id && !list.some(x => x.value === o.product_id)) list.unshift({ value: o.product_id, label: o.product_name || '#' + o.product_id });
  return list;
}
async function searchProducts(q) {
  clearTimeout(searchTimer);
  if (!q) return;
  searchTimer = setTimeout(async () => {
    try { const r = await http.get('restaurant/menu/dishes', { search: q, per_page: 15, only: 'ingredients' }); productHits.value = (r.data || []).map(p => ({ id: p.id, name: p.name })); } catch (e) { /* ignore */ }
  }, 300);
}
async function saveGroup() {
  const f = groupForm.value;
  const options = f.options.filter(o => o.name?.trim());
  if (!f.name?.trim() || !options.length) { message.error(t('Rst_Group_Needs_Name_Options')); return; }
  saving.value = true;
  try {
    const body = { name: f.name, min_select: f.min_select ?? 0, max_select: f.max_select ?? 1, is_active: f.is_active, options: options.map(o => ({ id: o.id, name: o.name, price: o.price || 0, product_id: o.product_id || null, stock_qty: o.stock_qty || 1, is_default: !!o.is_default, is_available: o.is_available !== false })) };
    if (f.id) await http.put(`restaurant/menu/addon-groups/${f.id}`, body); else await http.post('restaurant/menu/addon-groups', body);
    groupOpen.value = false;
    message.success(t('Successfully_Updated'));
    await loadSetup();
  } catch (e) { fail(e); } finally { saving.value = false; }
}
async function removeGroup(g) {
  try { await http.delete(`restaurant/menu/addon-groups/${g.id}`); message.success(t('Deleted_in_successfully')); await Promise.all([loadSetup(), loadDishes()]); } catch (e) { fail(e); }
}
async function setOptionAvailable(o, v) {
  try { await http.post(`restaurant/menu/options/${o.id}/availability`, { available: v }); o.is_available = v; } catch (e) { fail(e); }
}

// ----- stations -----
function openStation(s = null) { stationForm.value = s ? { id: s.id, name: s.name, is_active: s.is_active } : { name: '', is_active: true }; stationOpen.value = true; }
async function saveStation() {
  if (!stationForm.value.name?.trim()) { message.error(t('Rst_Name_Required')); return; }
  saving.value = true;
  try {
    if (stationForm.value.id) await http.put(`restaurant/menu/stations/${stationForm.value.id}`, stationForm.value); else await http.post('restaurant/menu/stations', stationForm.value);
    stationOpen.value = false;
    await loadSetup();
  } catch (e) { fail(e); } finally { saving.value = false; }
}
async function removeStation(s) {
  try { await http.delete(`restaurant/menu/stations/${s.id}`); await Promise.all([loadSetup(), loadDishes()]); } catch (e) { fail(e); }
}
async function addDefaultStations() {
  try { const r = await http.post('restaurant/menu/stations/defaults'); message.success(t('Rst_Stations_Added', { n: (r.added || []).length })); await loadSetup(); } catch (e) { fail(e); }
}
async function moveStation(i, dir) {
  const list = [...stations.value]; const [x] = list.splice(i, 1); list.splice(i + dir, 0, x); stations.value = list;
  try { await http.post('restaurant/menu/stations/reorder', { ids: list.map(s => s.id) }); } catch (e) { fail(e); await loadSetup(); }
}

async function saveTimes() {
  saving.value = true;
  try { const r = await http.put('restaurant/menu/times', times.value); times.value = r.menu_times; message.success(t('Successfully_Updated')); } catch (e) { fail(e); } finally { saving.value = false; }
}

onMounted(async () => { await loadSetup(); if (ready.value) loadDishes(1); });
</script>

<style scoped>
.loading { display: flex; justify-content: center; padding: 96px 0; }
.muted { color: rgba(0, 0, 0, 0.55); }
.small { font-size: 12px; }
.num { font-variant-numeric: tabular-nums; }
.toolbar { display: flex; gap: 12px; flex-wrap: wrap; align-items: center; margin-bottom: 16px; }
.switch-row { display: inline-flex; align-items: center; gap: 8px; cursor: pointer; }
.dish-name { display: flex; align-items: center; gap: 10px; }
.thumb { width: 40px; height: 40px; border-radius: 8px; object-fit: cover; background: #f3f3f7; }
.inactive { opacity: 0.6; }
.opt-row { display: grid; grid-template-columns: 1fr auto auto auto; gap: 12px; align-items: center; padding: 6px 0; border-bottom: 1px dashed rgba(5, 5, 5, 0.06); }
.opt-name { font-weight: 500; }
.opt-head, .opt-edit { display: grid; grid-template-columns: 2fr 1fr 2fr 0.8fr auto; gap: 8px; align-items: center; }
.opt-head { font-size: 12px; color: rgba(0, 0, 0, 0.55); margin-bottom: 6px; }
.opt-edit { margin-bottom: 8px; }
.station-row { display: flex; align-items: center; gap: 10px; padding: 8px 4px; border-bottom: 1px solid rgba(5, 5, 5, 0.06); }
</style>
