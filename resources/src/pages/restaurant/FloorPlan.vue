<template>
  <div class="page">
    <PageHeader :title="$t('Rst_Floor_Plan')" :breadcrumb="[$t('Restaurant_Management'), $t('Rst_Floor_Plan')]">
      <template #actions>
        <a-space wrap>
          <span class="muted small">{{ $t('Rst_Updated_At', { time: lastUpdated }) }}</span>
          <a-button :loading="loading" @click="load(true)"><template #icon><ReloadOutlined /></template>{{ $t('Rst_Refresh') }}</a-button>
          <a-badge v-if="canReserve" :count="waiting" :number-style="{ backgroundColor: '#6d28d9' }" :title="$t('Rst_Waiting_N', { n: waiting })">
            <a-button @click="$router.push('/restaurant/reservations')"><template #icon><CalendarOutlined /></template>{{ $t('Rst_Reservations') }}</a-button>
          </a-badge>
          <a-button v-if="canSetup" @click="$router.push('/restaurant/setup/floor')"><template #icon><SettingOutlined /></template>{{ $t('Rst_Floor_Setup') }}</a-button>
        </a-space>
      </template>
    </PageHeader>

    <div v-if="firstLoad" class="loading"><a-spin size="large" /></div>
    <a-alert v-else-if="!ready" type="warning" show-icon :message="$t('Rst_Not_Ready')" />
    <a-empty v-else-if="!areas.length" :description="$t('Rst_No_Areas')">
      <a-button v-if="canSetup" type="primary" @click="$router.push('/restaurant/setup/floor')">{{ $t('Rst_Set_Up_Floor') }}</a-button>
    </a-empty>

    <template v-else>
      <!-- Dishes the kitchen marked ready, waiting for a waiter -->
      <a-alert v-if="readyTables.length" type="success" show-icon class="ready-alert">
        <template #message>
          <span class="ready-list">
            <strong>{{ $t('Rst_Ready_To_Serve') }}</strong>
            <a-button v-for="tb in readyTables" :key="'ready' + tb.id" size="small" @click="selectedId = tb.id">
              {{ tb.name }} · {{ $t('Rst_Dishes_N', { n: tb.order.ready_items }) }}
            </a-button>
          </span>
        </template>
      </a-alert>
      <a-card size="small" class="toolbar-card">
        <div class="toolbar">
          <a-segmented v-model:value="areaId" :options="areaOptions" />
          <div class="legend">
            <span v-for="st in STATUSES" :key="st" class="legend-item">
              <span :class="['swatch', 'st-' + st]" />{{ statusLabel(st) }} <strong>{{ visibleCounts[st] || 0 }}</strong>
            </span>
          </div>
        </div>
      </a-card>

      <a-row :gutter="16">
        <a-col :xs="24" :xl="16">
          <a-card size="small" :body-style="{ padding: '16px' }">
            <a-empty v-if="!visibleTables.length" :description="$t('Rst_No_Tables_In_Area')" />
            <div v-else class="floor">
              <button
                v-for="tb in visibleTables" :key="tb.id" type="button"
                :class="['tbl', 'st-' + tb.status, 'shape-' + tb.shape, { selected: tb.id === selectedId, child: tb.merged_into }]"
                :aria-pressed="tb.id === selectedId" @click="selectedId = tb.id"
              >
                <span class="tbl-head"><strong class="tbl-name">{{ tb.name }}</strong><span class="tbl-seats">{{ $t('Rst_Seats_N', { n: tb.seats }) }}</span></span>
                <span class="tags">
                  <span :class="['tag', 'st-' + tb.status]">{{ tb.merged_into ? $t('Rst_Joined_To', { table: tb.merged_into.name }) : statusLabel(tb.status) }}</span>
                  <span v-if="tb.order && tb.order.ready_items" class="tag ready">{{ $t('Rst_Ready_N', { n: tb.order.ready_items }) }}</span>
                  <span v-if="tb.booking" :class="['tag', 'booking', { soon: tb.booking.soon }]">{{ $t('Rst_Booked_At_Party', { time: tb.booking.time, n: tb.booking.party_size }) }}</span>
                </span>
                <span class="tbl-line">{{ line(tb) }}</span>
                <span class="tbl-line strong">{{ tb.order && !tb.merged_into ? moneyBase(tb.order.subtotal) : (tb.waiter && !tb.merged_into ? tb.waiter.name : '') }}</span>
              </button>
            </div>
          </a-card>
        </a-col>

        <a-col :xs="24" :xl="8">
          <a-card v-if="selected" size="small" class="panel">
            <div class="panel-head">
              <div>
                <h2 class="panel-title">{{ $t('Rst_Table_N', { name: selected.name }) }}</h2>
                <div class="muted small">{{ areaName(selected) }} · {{ $t('Rst_Seats_N', { n: selected.seats }) }}</div>
              </div>
              <span :class="['tag', 'big', 'st-' + selected.status]">{{ statusLabel(selected.status) }}</span>
            </div>

            <!-- Joined to another table -->
            <template v-if="selected.merged_into">
              <p>{{ $t('Rst_Joined_Help', { table: selected.merged_into.name }) }}</p>
              <a-space wrap>
                <a-button @click="selectedId = selected.merged_into.id">{{ $t('Rst_Open_Table', { table: selected.merged_into.name }) }}</a-button>
                <a-button :loading="busy" @click="act('unmerge')">{{ $t('Rst_Unjoin') }}</a-button>
              </a-space>
            </template>

            <!-- Free / reserved: seat guests -->
            <template v-else-if="selected.status === 'free' || selected.status === 'reserved'">
              <a-alert v-if="selected.status === 'reserved'" type="info" show-icon class="mb" :message="selected.note ? $t('Rst_Held_For', { note: selected.note }) : $t('Rst_Held')" />
              <a-alert
                v-if="selected.booking" :type="selected.booking.soon ? 'warning' : 'info'" show-icon class="mb"
                :message="$t(selected.booking.late ? 'Rst_Booking_Late' : 'Rst_Booking_Next', { name: selected.booking.name, time: selected.booking.time, n: selected.booking.party_size })"
                :description="selected.booking.soon ? $t('Rst_Booking_Soon_Help') : ''"
              >
                <template v-if="canReserve" #action>
                  <a-button size="small" type="primary" :loading="busy" @click="seatBooking">{{ $t('Rst_Seat_Booking') }}</a-button>
                </template>
              </a-alert>
              <a-form layout="vertical">
                <a-row :gutter="12">
                  <a-col :span="10">
                    <a-form-item :label="$t('Rst_Guests')" :required="settings.require_guest_count">
                      <a-input-number v-model:value="seatForm.guests" :min="1" :max="500" style="width: 100%" />
                    </a-form-item>
                  </a-col>
                  <a-col :span="14">
                    <a-form-item :label="$t('Rst_Waiter')">
                      <a-select v-model:value="seatForm.waiter_id" show-search option-filter-prop="label" :options="waiterOptions" />
                    </a-form-item>
                  </a-col>
                </a-row>
              </a-form>
              <a-button v-if="canTakeOrders" type="primary" block size="large" :loading="busy" @click="seatAndOrder">{{ $t('Rst_Seat_And_Order') }}</a-button>
              <a-button :type="canTakeOrders ? 'default' : 'primary'" block :size="canTakeOrders ? 'middle' : 'large'" :loading="busy" style="margin-top: 8px" @click="seat">{{ $t('Rst_Seat_Guests') }}</a-button>
              <div class="actions">
                <a-button v-if="selected.status === 'free'" :loading="busy" @click="openHold">{{ $t('Rst_Hold_Table') }}</a-button>
                <a-button v-else :loading="busy" @click="act('unreserve')">{{ $t('Rst_Release_Hold') }}</a-button>
                <a-button v-if="canReserve" @click="$router.push({ path: '/restaurant/reservations', query: { new: '1', table: selected.id } })">{{ $t('Rst_Book_This_Table') }}</a-button>
              </div>
            </template>

            <!-- Guests seated -->
            <template v-else-if="selected.status === 'occupied' || selected.status === 'bill'">
              <div class="facts">
                <div class="fact"><span>{{ $t('Rst_Guests') }}</span><strong>{{ selected.guests || '—' }}</strong></div>
                <div class="fact"><span>{{ $t('Rst_Seated') }}</span><strong>{{ duration(selected.minutes) }}</strong></div>
                <div class="fact"><span>{{ $t('Rst_Waiter') }}</span><strong>{{ selected.waiter ? selected.waiter.name : '—' }}</strong></div>
              </div>
              <p v-if="selected.merged.length" class="muted small">{{ $t('Rst_Joined_Tables', { tables: selected.merged.map(m => m.name).join(', ') }) }}</p>
              <div v-if="selected.order" class="order-box">
                <div><strong>{{ selected.order.ref }}</strong> · {{ $t('Rst_Dishes_N', { n: selected.order.items }) }}<a-tag v-if="selected.order.new_items" color="purple" style="margin-inline-start: 6px">{{ $t('Rst_Not_Sent_N', { n: selected.order.new_items }) }}</a-tag></div>
                <strong class="num">{{ moneyBase(selected.order.subtotal) }}</strong>
              </div>
              <a-button v-if="canTakeOrders && selected.order && selected.order.ready_items" block size="large" class="served-btn" :loading="busy" @click="markServed">{{ $t('Rst_Mark_Served_N', { n: selected.order.ready_items }) }}</a-button>
              <a-button v-if="canTakeOrders" type="primary" block size="large" :loading="busy" style="margin-top: 8px" @click="openOrder()">{{ selected.order ? $t('Rst_Open_Order') : $t('Rst_Take_Order') }}</a-button>
              <a-button v-if="canBill && selected.order && selected.order.items" block size="large" style="margin-top: 8px" @click="$router.push('/restaurant/order/' + selected.order.id + '/bill')"><DollarOutlined /> {{ $t('Rst_Bill_And_Pay') }}</a-button>
              <div class="actions">
                <a-button v-if="selected.status === 'occupied'" :loading="busy" @click="act('bill')">{{ $t('Rst_Bill_Requested') }}</a-button>
                <a-button v-else :loading="busy" @click="act('resume')">{{ $t('Rst_Ordering_Again') }}</a-button>
                <a-button @click="openMove">{{ $t('Rst_Move_Table') }}</a-button>
                <a-button @click="openMerge">{{ $t('Rst_Join_Tables') }}</a-button>
                <a-popconfirm :title="$t('Rst_Guests_Left_Confirm')" :ok-text="$t('Yes')" :cancel-text="$t('No')" @confirm="act('clear')">
                  <a-button danger>{{ $t('Rst_Guests_Left') }}</a-button>
                </a-popconfirm>
              </div>
            </template>

            <!-- Needs cleaning -->
            <template v-else-if="selected.status === 'cleaning'">
              <p>{{ $t('Rst_Left_Ago', { time: duration(selected.since_minutes) }) }}</p>
              <a-button type="primary" block size="large" :loading="busy" @click="act('clean')">{{ $t('Rst_Mark_Clean') }}</a-button>
            </template>
          </a-card>
          <a-card v-else size="small"><a-empty :description="$t('Rst_Pick_A_Table')" /></a-card>
        </a-col>
      </a-row>
    </template>

    <!-- Move -->
    <a-modal v-model:open="moveOpen" :title="$t('Rst_Move_Guests_From', { table: selected ? selected.name : '' })" :ok-text="$t('Rst_Move')" :confirm-loading="busy" :ok-button-props="{ disabled: !moveTo }" @ok="move">
      <p class="muted">{{ $t('Rst_Move_Help') }}</p>
      <a-select v-model:value="moveTo" style="width: 100%" show-search option-filter-prop="label" :placeholder="$t('Rst_Choose_Free_Table')" :options="freeTableOptions" />
    </a-modal>

    <!-- Join -->
    <a-modal v-model:open="mergeOpen" :title="$t('Rst_Join_To', { table: selected ? selected.name : '' })" :ok-text="$t('Rst_Join')" :confirm-loading="busy" :ok-button-props="{ disabled: !mergeIds.length }" @ok="merge">
      <p class="muted">{{ $t('Rst_Join_Help') }}</p>
      <a-checkbox-group v-model:value="mergeIds" class="merge-list">
        <a-checkbox v-for="o in mergeOptions" :key="o.value" :value="o.value">{{ o.label }}</a-checkbox>
      </a-checkbox-group>
      <a-empty v-if="!mergeOptions.length" :description="$t('Rst_Nothing_To_Join')" />
    </a-modal>

    <!-- Hold -->
    <a-modal v-model:open="holdOpen" :title="$t('Rst_Hold_Table')" :ok-text="$t('Rst_Hold')" :confirm-loading="busy" @ok="hold">
      <a-form layout="vertical">
        <a-form-item :label="$t('Rst_Hold_For')" :extra="$t('Rst_Hold_For_Help')">
          <a-input v-model:value="holdNote" :maxlength="191" />
        </a-form-item>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * Restaurant › Floor plan — GET restaurant/floor (polled every 15 s) and
 * POST restaurant/floor/tables/{id}/{action}
 * (seat | bill | resume | clear | clean | reserve | unreserve | move | merge | unmerge).
 */
import { ref, computed, onMounted, onBeforeUnmount, watch } from 'vue';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { ReloadOutlined, SettingOutlined, DollarOutlined, CalendarOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';
import { useAuthStore } from '../../stores/auth';
import { useFormat } from '../../composables/useFormat';
import { useRouter } from 'vue-router';

const { t } = useI18n();
const auth = useAuthStore();
const { moneyBase } = useFormat();
const router = useRouter();

const STATUSES = ['free', 'occupied', 'bill', 'reserved', 'cleaning'];
const POLL_MS = 15000;

const firstLoad = ref(true);
const loading = ref(false);
const busy = ref(false);
const ready = ref(true);
const areas = ref([]);
const waiters = ref([]);
const settings = ref({ require_guest_count: true });
const me = ref(null);
const areaId = ref('all');
const selectedId = ref(null);
const lastUpdated = ref('');
const seatForm = ref({ guests: null, waiter_id: undefined });
const moveOpen = ref(false);
const moveTo = ref(undefined);
const mergeOpen = ref(false);
const mergeIds = ref([]);
const holdOpen = ref(false);
const holdNote = ref('');
let timer = null;

const canSetup = computed(() => auth.can('rst_setup'));
const canTakeOrders = computed(() => auth.can('rst_orders_take'));
const canBill = computed(() => auth.can('rst_billing'));
const canReserve = computed(() => auth.can('rst_reservations'));
const waiting = ref(0);
const readyTables = computed(() => allTables.value.filter(tb => tb.order && tb.order.ready_items > 0 && !tb.merged_into));

const allTables = computed(() => areas.value.flatMap(a => a.tables.map(tb => ({ ...tb, area_id: a.id }))));
const visibleTables = computed(() => (areaId.value === 'all' ? allTables.value : allTables.value.filter(tb => tb.area_id === areaId.value)));
const selected = computed(() => allTables.value.find(tb => tb.id === selectedId.value) || null);
const visibleCounts = computed(() => visibleTables.value.reduce((c, tb) => { c[tb.status] = (c[tb.status] || 0) + 1; return c; }, {}));
const areaOptions = computed(() => [{ value: 'all', label: `${t('All')} (${allTables.value.length})` }]
  .concat(areas.value.map(a => ({ value: a.id, label: `${a.name} (${a.tables.length})` }))));
const waiterOptions = computed(() => waiters.value.map(w => ({ value: w.id, label: w.name })));
const freeTableOptions = computed(() => allTables.value
  .filter(tb => tb.status === 'free' && !tb.merged_into && selected.value && tb.id !== selected.value.id)
  .map(tb => ({ value: tb.id, label: `${tb.name} · ${areaName(tb)} · ${t('Rst_Seats_N', { n: tb.seats })}` })));
const mergeOptions = computed(() => {
  if (!selected.value) return [];
  return allTables.value
    .filter(tb => tb.area_id === selected.value.area_id && tb.id !== selected.value.id && !tb.merged_into && ['free', 'occupied', 'bill'].includes(tb.status))
    .map(tb => ({ value: tb.id, label: `${tb.name} · ${statusLabel(tb.status)}${tb.guests ? ' · ' + t('Rst_Guests_N', { n: tb.guests }) : ''}` }));
});

function statusLabel(st) {
  return { free: t('Rst_Status_Free'), occupied: t('Rst_Status_Occupied'), bill: t('Rst_Status_Bill'), reserved: t('Rst_Status_Reserved'), cleaning: t('Rst_Status_Cleaning') }[st] || st;
}
function areaName(tb) {
  const a = areas.value.find(x => x.id === tb.area_id);
  return a ? a.name : '';
}
function duration(min) {
  if (min == null) return '—';
  if (min < 60) return t('Rst_Minutes_N', { n: min });
  return t('Rst_Hours_Minutes', { h: Math.floor(min / 60), m: min % 60 });
}
function line(tb) {
  if (tb.merged_into) return '';
  if (tb.status === 'occupied' || tb.status === 'bill') return `${tb.guests ? t('Rst_Guests_N', { n: tb.guests }) + ' · ' : ''}${duration(tb.minutes)}`;
  if (tb.status === 'reserved') return tb.note || t('Rst_Held');
  if (tb.status === 'cleaning') return t('Rst_Left_Ago', { time: duration(tb.since_minutes) });
  return '';
}

async function load(manual = false) {
  if (loading.value) return;
  loading.value = true;
  try {
    const r = await http.get('restaurant/floor');
    ready.value = r.ready !== false;
    areas.value = r.areas || [];
    waiters.value = r.waiters || [];
    settings.value = r.settings || settings.value;
    me.value = r.me || null;
    waiting.value = r.waiting || 0;
    lastUpdated.value = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' });
    if (areaId.value !== 'all' && !areas.value.some(a => a.id === areaId.value)) areaId.value = 'all';
    if (!selectedId.value || !allTables.value.some(tb => tb.id === selectedId.value)) {
      selectedId.value = visibleTables.value.length ? visibleTables.value[0].id : null;
    }
  } catch (e) {
    if (manual) message.error(t('Failed'));
  } finally {
    loading.value = false;
    firstLoad.value = false;
  }
}

/** The booked party arrived: seat them here (phase 7). */
async function seatBooking() {
  const b = selected.value?.booking;
  if (!b) return;
  busy.value = true;
  try {
    await http.post(`restaurant/reservations/${b.id}/seat`, { table_id: selected.value.id });
    message.success(t('Rst_Party_Seated'));
  } catch (e) {
    message.error(e?.data?.message || t('Failed'));
  } finally {
    busy.value = false;
    await load();
  }
}

async function act(action, payload = {}) {
  if (!selected.value) return false;
  busy.value = true;
  try {
    await http.post(`restaurant/floor/tables/${selected.value.id}/${action}`, payload);
    await load();
    return true;
  } catch (e) {
    message.error(e?.data?.message || t('Failed'));
    await load();
    return false;
  } finally {
    busy.value = false;
  }
}

async function seat() {
  if (settings.value.require_guest_count && !seatForm.value.guests) {
    message.error(t('Rst_Enter_Guests'));
    return;
  }
  if (await act('seat', { guests: seatForm.value.guests || null, waiter_id: seatForm.value.waiter_id || null })) {
    message.success(t('Rst_Guests_Seated'));
  }
}
/** The table's open order (POST creates it, seating a free table with the form's guests). */
async function openOrder(guests = null, waiterId = null) {
  if (!selected.value) return;
  busy.value = true;
  try {
    const body = {};
    if (guests) body.guests = guests;
    if (waiterId) body.waiter_id = waiterId; // the waiter picked in "Seat guests"
    const r = await http.post(`restaurant/tables/${selected.value.id}/order`, body);
    router.push(`/restaurant/order/${r.order.id}`);
  } catch (e) {
    message.error(e?.data?.message || t('Failed'));
    await load();
  } finally {
    busy.value = false;
  }
}
async function markServed() {
  if (!selected.value?.order) return;
  busy.value = true;
  try {
    await http.post(`restaurant/orders/${selected.value.order.id}/served`);
    message.success(t('Rst_Marked_Served'));
  } catch (e) {
    message.error(e?.data?.message || t('Failed'));
  } finally {
    busy.value = false;
    await load();
  }
}
function seatAndOrder() {
  if (settings.value.require_guest_count && !seatForm.value.guests) {
    message.error(t('Rst_Enter_Guests'));
    return;
  }
  openOrder(seatForm.value.guests || null, seatForm.value.waiter_id || null);
}
function openMove() { moveTo.value = undefined; moveOpen.value = true; }
async function move() {
  const to = moveTo.value;
  if (await act('move', { to_table_id: to })) { moveOpen.value = false; selectedId.value = to; }
}
function openMerge() { mergeIds.value = []; mergeOpen.value = true; }
async function merge() {
  if (await act('merge', { table_ids: mergeIds.value })) mergeOpen.value = false;
}
function openHold() { holdNote.value = ''; holdOpen.value = true; }
async function hold() {
  if (await act('reserve', { note: holdNote.value || null })) holdOpen.value = false;
}

// New table selected → fresh seat form (guests empty, waiter = me).
watch(selectedId, () => { seatForm.value = { guests: null, waiter_id: me.value || undefined }; });

function tick() {
  if (document.hidden || moveOpen.value || mergeOpen.value || holdOpen.value) return;
  load();
}
onMounted(() => { load(); timer = setInterval(tick, POLL_MS); });
onBeforeUnmount(() => clearInterval(timer));
</script>

<style scoped>
.loading { display: flex; justify-content: center; padding: 96px 0; }
.muted { color: rgba(0, 0, 0, 0.55); }
.small { font-size: 12px; }
.mb { margin-bottom: 12px; }
.toolbar-card { margin-bottom: 16px; }
.toolbar { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
.legend { display: flex; gap: 14px; flex-wrap: wrap; font-size: 13px; }
.legend-item { display: inline-flex; align-items: center; gap: 6px; }
.swatch { width: 14px; height: 14px; border-radius: 4px; border: 2px solid; display: inline-block; }

.floor { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 14px; }
.tbl { min-height: 124px; border-radius: 14px; padding: 12px; display: flex; flex-direction: column; align-items: flex-start; gap: 6px; text-align: left; cursor: pointer; border: 2px solid; font: inherit; color: #1f1f2c; transition: box-shadow 0.12s; }
.tbl:hover { box-shadow: 0 6px 18px rgba(31, 31, 44, 0.12); }
.tbl:focus-visible { outline: 3px solid #6d28d9; outline-offset: 2px; }
.tbl.selected { box-shadow: 0 0 0 3px #6d28d9; }
.tbl.shape-round { border-radius: 40px; padding-left: 18px; }
.tbl.shape-long { grid-column: span 2; }
.tbl.child { border-style: dashed; }
.tbl-head { display: flex; justify-content: space-between; align-items: center; width: 100%; gap: 6px; }
.tbl-name { font-size: 18px; }
.tbl-seats { font-size: 12px; color: #54546a; }
.tbl-line { font-size: 13px; color: #3b3b4f; min-height: 18px; }
.tbl-line.strong { font-weight: 600; }
.tag { font-size: 12px; font-weight: 600; padding: 2px 8px; border-radius: 999px; }
.tag.big { padding: 4px 10px; }

/* Status colours: background + border + text differ in lightness, and every tile also says its status in words. */
.st-free { background: #ffffff; border-color: #16a34a; }
.st-occupied { background: #f5f3ff; border-color: #6d28d9; }
.st-bill { background: #fffbeb; border-color: #b45309; }
.st-reserved { background: #eff6ff; border-color: #1d4ed8; }
.st-cleaning { background: #f8fafc; border-color: #94a3b8; }
.tag.st-free { background: #dcfce7; color: #166534; }
.tag.st-occupied { background: #ede9fe; color: #5b21b6; }
.tag.st-bill { background: #fef3c7; color: #92400e; }
.tag.st-reserved { background: #dbeafe; color: #1e40af; }
.tag.st-cleaning { background: #e2e8f0; color: #475569; }

.panel-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 8px; margin-bottom: 14px; }
.panel-title { margin: 0; font-size: 20px; }
.facts { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 8px; margin-bottom: 12px; }
.fact { background: #f7f7fb; border-radius: 10px; padding: 10px; display: flex; flex-direction: column; }
.fact span { font-size: 12px; color: rgba(0, 0, 0, 0.55); }
.actions { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 12px; }
.merge-list { display: flex; flex-direction: column; gap: 8px; }
.ready-alert { margin-bottom: 12px; }
.ready-list { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.tags { display: flex; gap: 4px; flex-wrap: wrap; }
.tag.ready { background: #16a34a; color: #fff; }
.tag.booking { background: #dbeafe; color: #1e40af; }
.tag.booking.soon { background: #f59e0b; color: #fff; }
.served-btn { background: #dcfce7; border-color: #16a34a; color: #166534; font-weight: 700; }
.order-box { display: flex; justify-content: space-between; align-items: center; gap: 8px; background: #f5f3ff; border-radius: 10px; padding: 10px 12px; margin-bottom: 10px; }
.num { font-variant-numeric: tabular-nums; }
</style>
