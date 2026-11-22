<template>
  <div class="page">
    <PageHeader
      :title="$t('Rental_Availability')"
      :subtitle="$t('Rental_Availability_Subtitle')"
      :breadcrumb="[$t('Rental_Management'), $t('Rental_Availability')]"
    >
      <template #actions>
        <a-button v-if="canCalendar" @click="$router.push('/rental/calendar')">
          <template #icon><CalendarOutlined /></template>
          {{ $t('Rental_Calendar') }}
        </a-button>
        <a-button v-if="canReserve" @click="$router.push('/rental/reservations/create')">
          <template #icon><LockOutlined /></template>
          {{ $t('Rental_New_Reservation') }}
        </a-button>
        <a-button v-if="canContract" type="primary" @click="$router.push('/rental/contracts/create')">
          <template #icon><PlusOutlined /></template>
          {{ $t('Rental_New_Contract') }}
        </a-button>
      </template>
    </PageHeader>

    <!-- Toolbar -->
    <a-card size="small" class="block">
      <div class="toolbar">
        <div class="nav">
          <a-button @click="shift(-1)"><template #icon><LeftOutlined /></template></a-button>
          <a-button @click="goToday">{{ $t('Rental_Today') }}</a-button>
          <a-button @click="shift(1)"><template #icon><RightOutlined /></template></a-button>
          <span class="range-label">{{ rangeLabel }}</span>
        </div>
        <a-segmented v-model:value="zoom" :options="zoomOptions" @change="onZoom" />
      </div>
      <div class="toolbar filters">
        <a-input-search v-model:value="filters.search" :placeholder="$t('Rental_Search_Items')" allow-clear class="f-search" @search="load" />
        <a-select v-model:value="filters.type" :options="typeOptions" allow-clear :placeholder="$t('type')" class="f-item" @change="load" />
        <a-select v-model:value="filters.category_id" :options="categoryOptions" allow-clear show-search option-filter-prop="label" :placeholder="$t('Category')" class="f-item" @change="load" />
        <a-select v-model:value="filters.warehouse_id" :options="warehouseOptions" allow-clear show-search option-filter-prop="label" :placeholder="$t('Warehouse')" class="f-item" @change="load" />
        <a-checkbox v-model:checked="filters.include_drafts" @change="load">{{ $t('Rental_Show_Drafts') }}</a-checkbox>
      </div>
      <!-- "What can I rent for these dates?" -->
      <div class="free">
        <span class="free-label"><SearchOutlined /> {{ $t('Rental_Find_Free') }}</span>
        <a-range-picker v-model:value="freeRange" show-time format="YYYY-MM-DD HH:mm" value-format="YYYY-MM-DD[T]HH:mm:ssZ" class="free-range" />
        <a-input-number v-model:value="freeQty" :min="1" :precision="0" class="free-qty" :addon-before="$t('Quantity')" />
        <a-button type="primary" ghost :disabled="!freeRange || !freeRange[0]" @click="applyFree">{{ $t('Rental_Check') }}</a-button>
        <a-button v-if="freeActive" type="text" @click="clearFree">{{ $t('Rental_Clear') }}</a-button>
        <a-tag v-if="freeActive" color="green" class="free-tag">{{ items.length }} {{ $t('Rental_Free_Items') }}</a-tag>
      </div>
    </a-card>

    <!-- Legend -->
    <div class="legend">
      <span v-for="l in legend" :key="l.cls" class="legend-item"><span class="legend-swatch" :class="l.cls"></span>{{ l.label }}</span>
      <span class="legend-hint"><DragOutlined /> {{ $t('Rental_Drag_Hint') }}</span>
    </div>

    <!-- Board -->
    <a-card size="small" class="board-card" :body-style="{ padding: 0 }">
      <a-spin :spinning="loading">
        <div v-if="!loading && !items.length" class="empty">
          <ScheduleOutlined class="empty-ic" />
          <p>{{ freeActive ? $t('Rental_No_Free_Items') : $t('Rental_No_Items_Yet') }}</p>
        </div>

        <div v-else ref="scroller" class="board" :style="{ '--day-w': `${dayW}px`, '--name-w': `${NAME_W}px`, '--surface': token.colorBgContainer }">
          <!-- Header -->
          <div class="head" :style="{ width: `${NAME_W + days.length * dayW}px` }">
            <div class="head-corner">{{ $t('Rental_Items') }} <span class="muted">({{ items.length }})</span></div>
            <div class="head-days">
              <div class="months">
                <span v-for="m in monthBands" :key="m.key" class="month" :style="{ width: `${m.span * dayW}px` }">{{ m.label }}</span>
              </div>
              <div class="days">
                <span
                  v-for="d in days" :key="d.key" class="day"
                  :class="{ weekend: d.weekend, today: d.today }" :style="{ width: `${dayW}px` }"
                >
                  <template v-if="dayW >= 30"><small>{{ d.dow }}</small>{{ d.num }}</template>
                  <template v-else-if="d.num === 1 || d.dowIndex === 1">{{ d.num }}</template>
                </span>
              </div>
            </div>
          </div>

          <!-- Rows -->
          <div class="body" :style="{ width: `${NAME_W + days.length * dayW}px` }">
            <!-- Weekend shading + today line, drawn once for all rows -->
            <div class="overlay" :style="{ left: `${NAME_W}px` }">
              <span v-for="d in days.filter(x => x.weekend)" :key="`w${d.key}`" class="ov-weekend" :style="{ left: `${d.index * dayW}px`, width: `${dayW}px` }"></span>
              <span v-if="todayX !== null" class="ov-today" :style="{ left: `${todayX}px` }"></span>
            </div>

            <div v-for="row in rows" :key="row.item.id" class="row" :class="{ off: row.item.status === 'maintenance' }">
              <router-link :to="`/rental/items/${row.item.id}`" class="name">
                <span class="thumb">
                  <img v-if="row.item.image_url" :src="row.item.image_url" alt="" loading="lazy" />
                  <component :is="typeIcon(row.item.type)" v-else :size="16" :stroke-width="1.7" />
                </span>
                <span class="name-text">
                  <span class="name-title" :title="row.item.name">{{ row.item.name }}</span>
                  <span class="name-sub">
                    {{ row.item.code }}
                    <template v-if="row.item.quantity > 1"> · ×{{ row.item.quantity }}</template>
                    <a-tag v-if="row.item.status === 'maintenance'" color="warning" class="mini-tag">{{ labelOf(RENTAL_STATUSES, 'maintenance') }}</a-tag>
                    <a-tag v-else-if="row.item.free_qty !== null" color="green" class="mini-tag">{{ row.item.free_qty }} {{ $t('Rental_Free') }}</a-tag>
                  </span>
                </span>
              </router-link>

              <div
                class="lane" :style="{ height: `${row.height}px` }"
                @mousedown="startSelect($event, row.item)"
              >
                <!-- Drag selection -->
                <span
                  v-if="sel && sel.itemId === row.item.id" class="selection"
                  :style="{ left: `${Math.min(sel.from, sel.to) * dayW}px`, width: `${(Math.abs(sel.to - sel.from) + 1) * dayW}px` }"
                ></span>

                <a-tooltip v-for="b in row.bars" :key="b.key" :mouse-enter-delay="0.25">
                  <template #title><div class="tip">{{ barTitle(b) }}</div></template>
                  <router-link
                    :to="b.kind === 'contract' ? `/rental/contracts/${b.id}` : b.kind === 'maintenance' ? `/rental/maintenance?job=${b.id}` : `/rental/reservations/${b.id}`"
                    class="bar" :class="[b.cls, { 'cut-l': b.cutLeft, 'cut-r': b.cutRight }]"
                    :style="{ left: `${b.x}px`, width: `${b.w}px`, top: `${6 + b.lane * LANE_H}px` }"
                    @mousedown.stop
                  >
                    <LockOutlined v-if="b.kind === 'reservation'" class="bar-ic" />
                    <ToolOutlined v-else-if="b.kind === 'maintenance'" class="bar-ic" />
                    <span v-if="b.w > 44" class="bar-text">{{ b.customer_name }}<span class="bar-num"> · {{ b.number }}</span></span>
                    <span v-if="b.quantity > 1 && b.w > 24" class="bar-qty">×{{ b.quantity }}</span>
                  </router-link>
                </a-tooltip>
              </div>
            </div>
          </div>
        </div>
      </a-spin>
    </a-card>

    <!-- What to do with a dragged range -->
    <div v-if="pending" class="pop" :style="{ left: `${pending.x}px`, top: `${pending.y}px`, background: token.colorBgElevated }" @mousedown.stop>
      <div class="pop-title">{{ pending.item.name }}</div>
      <div class="pop-dates">{{ date(pending.start) }} → {{ date(pending.end) }} · {{ pending.nights }} {{ unitLabel('day') }}</div>
      <div class="pop-actions">
        <a-button v-if="canReserve" size="small" @click="goCreate('reservations')">
          <template #icon><LockOutlined /></template>
          {{ $t('Rental_Reserve') }}
        </a-button>
        <a-button v-if="canContract" size="small" type="primary" @click="goCreate('contracts')">
          <template #icon><FileAddOutlined /></template>
          {{ $t('Rental_New_Contract') }}
        </a-button>
        <a-button size="small" type="text" @click="pending = null"><template #icon><CloseOutlined /></template></a-button>
      </div>
    </div>
  </div>
</template>

<script setup>
/**
 * Availability timeline: one row per rental item, one column per day, every
 * active contract and live reservation drawn as a bar (overlapping bookings
 * of pooled items stack into lanes). Dragging across empty days in a row
 * offers to reserve those dates or start a contract for them.
 *
 * Data: GET rental/availability/timeline (items + flat bookings). The optional
 * "find free" range is filtered server-side with the same double-booking rule
 * the contracts use.
 */
import { ref, reactive, computed, onMounted, onBeforeUnmount } from 'vue';
import { useRouter } from 'vue-router';
import { message, theme } from 'ant-design-vue';
import dayjs from 'dayjs';
import {
  CalendarOutlined, LockOutlined, PlusOutlined, LeftOutlined, RightOutlined, SearchOutlined,
  ScheduleOutlined, DragOutlined, FileAddOutlined, CloseOutlined, ToolOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import { useFormat } from '../../composables/useFormat';
import { useAuthStore } from '../../stores/auth';
import http from '../../lib/http';
import { t } from '../../i18n';
import {
  RENTAL_TYPES, RENTAL_STATUSES, CONTRACT_STAGES, RESERVATION_STAGES,
  tr, labelOf, typeIcon, unitLabel,
} from './rentalOptions';
import { useRentalTypes } from './rentalTypes';

const router = useRouter();
const auth = useAuthStore();
const { date, dateTime } = useFormat();
// Sticky header/name cells need an opaque surface that follows light/dark.
const { token } = theme.useToken();

const canReserve = computed(() => auth.can('rnt_reservations_add'));
const canContract = computed(() => auth.can('rnt_contracts_add'));
const canCalendar = computed(() => auth.can('rnt_availability'));

const NAME_W = 250;
const LANE_H = 26;
const ZOOMS = {
  week: { days: 7, w: 140 },
  fortnight: { days: 14, w: 76 },
  month: { days: 0, w: 44 },   // 0 = the calendar month
  quarter: { days: 0, w: 18 }, // 3 calendar months
};

const ZOOM_KEY = 'rental_timeline_zoom';
function readZoom() {
  try {
    const z = window.localStorage.getItem(ZOOM_KEY);
    return ZOOMS[z] ? z : 'fortnight';
  } catch (e) {
    return 'fortnight';
  }
}

const zoom = ref(readZoom());
const anchor = ref(dayjs().startOf('day'));
const loading = ref(false);
const items = ref([]);
const bookings = ref([]);
const scroller = ref(null);

const filters = reactive({ search: '', type: undefined, category_id: undefined, warehouse_id: undefined, include_drafts: false });
const freeRange = ref(null);
const freeQty = ref(1);
const freeActive = ref(false);

const zoomOptions = computed(() => [
  { value: 'week', label: t('Rental_Zoom_Week', 'Week') },
  { value: 'fortnight', label: t('Rental_Zoom_Two_Weeks', '2 weeks') },
  { value: 'month', label: t('Rental_Zoom_Month', 'Month') },
  { value: 'quarter', label: t('Rental_Zoom_Quarter', 'Quarter') },
]);
// Only the tenant's business types (Rental Settings › Business type).
const rentalTypes = useRentalTypes();
const typeOptions = computed(() => rentalTypes.enabledOptions(filters.type));

const legend = computed(() => [
  { cls: 'b-running', label: labelOf(CONTRACT_STAGES, 'active') },
  { cls: 'b-upcoming', label: labelOf(CONTRACT_STAGES, 'upcoming') },
  { cls: 'b-expiring', label: labelOf(CONTRACT_STAGES, 'expiring') },
  { cls: 'b-overdue', label: labelOf(CONTRACT_STAGES, 'overdue') },
  { cls: 'b-hold', label: labelOf(RESERVATION_STAGES, 'held') },
  { cls: 'b-draft', label: labelOf(CONTRACT_STAGES, 'draft') },
  { cls: 'b-closed', label: t('Rental_Closed', 'Closed') },
  { cls: 'b-maint', label: t('Rental_Maintenance', 'Maintenance') },
]);

// ----- range -----
const range = computed(() => {
  const a = anchor.value;
  let start;
  let count;
  if (zoom.value === 'month') {
    start = a.startOf('month');
    count = start.daysInMonth();
  } else if (zoom.value === 'quarter') {
    start = a.startOf('month');
    count = start.add(3, 'month').diff(start, 'day');
  } else {
    // Week views start yesterday so "today" is never hidden at the edge.
    start = a.subtract(1, 'day');
    count = ZOOMS[zoom.value].days;
  }
  return { start, end: start.add(count, 'day'), count };
});

const dayW = computed(() => ZOOMS[zoom.value].w);

const days = computed(() => {
  const out = [];
  const today = dayjs().startOf('day');
  for (let i = 0; i < range.value.count; i++) {
    const d = range.value.start.add(i, 'day');
    out.push({
      key: d.format('YYYY-MM-DD'),
      index: i,
      num: d.date(),
      dow: d.format('dd'),
      dowIndex: d.day(),
      weekend: d.day() === 0 || d.day() === 6,
      today: d.isSame(today, 'day'),
    });
  }
  return out;
});

const monthBands = computed(() => {
  const bands = [];
  days.value.forEach((d, i) => {
    const m = range.value.start.add(i, 'day');
    const key = m.format('YYYY-MM');
    const last = bands[bands.length - 1];
    if (last && last.key === key) last.span++;
    else bands.push({ key, label: m.format('MMMM YYYY'), span: 1 });
  });
  return bands;
});

const rangeLabel = computed(() => `${range.value.start.format('D MMM')} – ${range.value.end.subtract(1, 'day').format('D MMM YYYY')}`);

/** Pixel offset of "now" inside the timeline, or null when off-screen. */
const todayX = computed(() => {
  const ms = dayjs().diff(range.value.start);
  const x = (ms / 86400000) * dayW.value;
  return x >= 0 && x <= range.value.count * dayW.value ? x : null;
});

function shift(dir) {
  const unit = zoom.value === 'month' ? 'month' : zoom.value === 'quarter' ? 'month' : 'day';
  const step = zoom.value === 'quarter' ? 3 : zoom.value === 'month' ? 1 : ZOOMS[zoom.value].days;
  anchor.value = anchor.value.add(dir * step, unit);
  load();
}

function goToday() {
  anchor.value = dayjs().startOf('day');
  load();
}

function onZoom(value) {
  try { window.localStorage.setItem(ZOOM_KEY, value); } catch (e) { /* storage blocked */ }
  load();
}

// ----- bars -----
function barClass(b) {
  if (b.kind === 'maintenance') return 'b-maint';
  if (b.kind === 'reservation') return 'b-hold';
  if (b.status === 'draft') return 'b-draft';
  if (['ended', 'terminated', 'renewed'].includes(b.status)) return 'b-closed';
  return { upcoming: 'b-upcoming', expiring: 'b-expiring', overdue: 'b-overdue' }[b.stage] || 'b-running';
}

/** Bookings grouped per item, positioned and packed into non-overlapping lanes. */
const rows = computed(() => {
  const start = range.value.start;
  const totalW = range.value.count * dayW.value;
  const pxPerMs = dayW.value / 86400000;
  const byItem = new Map();
  bookings.value.forEach((b) => {
    if (!byItem.has(b.item_id)) byItem.set(b.item_id, []);
    byItem.get(b.item_id).push(b);
  });

  return items.value.map((item) => {
    const list = (byItem.get(item.id) || [])
      .map((b) => {
        const s = dayjs(b.start);
        const e = b.end ? dayjs(b.end) : range.value.end.add(1, 'day');
        let x = s.diff(start) * pxPerMs;
        let x2 = e.diff(start) * pxPerMs;
        const cutLeft = x < 0;
        const cutRight = x2 > totalW;
        x = Math.max(0, x);
        x2 = Math.min(totalW, x2);
        return { ...b, x, w: Math.max(6, x2 - x), cutLeft, cutRight, sortStart: s.valueOf(), endPx: x2, cls: barClass(b) };
      })
      .filter(b => b.endPx > 0 && b.x < totalW)
      .sort((a, b) => a.sortStart - b.sortStart);

    const laneEnds = [];
    list.forEach((b) => {
      let lane = laneEnds.findIndex(end => end <= b.x + 0.5);
      if (lane === -1) {
        lane = laneEnds.length;
        laneEnds.push(0);
      }
      laneEnds[lane] = b.x + b.w;
      b.lane = lane;
    });

    return { item, bars: list, height: Math.max(1, laneEnds.length) * LANE_H + 12 };
  });
});

function barTitle(b) {
  if (b.kind === 'maintenance') {
    const end = b.open_ended ? t('Rental_Until_Done', 'until done') : dateTime(b.end);
    return `${t('Rental_Maintenance', 'Maintenance')} ${b.number} — ${b.customer_name}\n${dateTime(b.start)} → ${end}`;
  }
  const kind = b.kind === 'reservation' ? t('Rental_Reservation', 'Reservation') : t('Rental_Contract', 'Contract');
  const end = b.open_ended ? t('Rental_Open_Ended', 'Open-ended') : dateTime(b.agreed_end || b.end);
  const status = b.kind === 'reservation'
    ? `${labelOf(RESERVATION_STAGES, b.stage)} · ${t('Rental_Held_Until', 'Held until')} ${dateTime(b.hold_until)}`
    : labelOf(CONTRACT_STAGES, b.status === 'active' ? b.stage : b.status);
  const qty = b.quantity > 1 ? ` · ×${b.quantity}` : '';
  return `${kind} ${b.number} — ${b.customer_name}${qty}\n${dateTime(b.start)} → ${end}\n${status}`;
}

// ----- drag to select -----
const sel = ref(null);
const pending = ref(null);

function dayAt(event, laneEl) {
  const rect = laneEl.getBoundingClientRect();
  const x = event.clientX - rect.left;
  return Math.min(range.value.count - 1, Math.max(0, Math.floor(x / dayW.value)));
}

function startSelect(event, item) {
  if (event.button !== 0 || item.status === 'maintenance' || (!canReserve.value && !canContract.value)) return;
  const lane = event.currentTarget;
  pending.value = null;
  const from = dayAt(event, lane);
  sel.value = { itemId: item.id, item, from, to: from, lane };
  event.preventDefault();
  window.addEventListener('mousemove', moveSelect);
  window.addEventListener('mouseup', endSelect, { once: true });
}

function moveSelect(event) {
  if (!sel.value) return;
  sel.value.to = dayAt(event, sel.value.lane);
}

function endSelect(event) {
  window.removeEventListener('mousemove', moveSelect);
  if (!sel.value) return;
  const a = Math.min(sel.value.from, sel.value.to);
  const b = Math.max(sel.value.from, sel.value.to);
  // Pickup and return at 09:00 — a typical counter opening time; the form lets
  // the user change both.
  const start = range.value.start.add(a, 'day').hour(9);
  const end = range.value.start.add(b + 1, 'day').hour(9);
  pending.value = {
    item: sel.value.item,
    start,
    end,
    nights: b - a + 1,
    x: Math.min(event.clientX + 8, window.innerWidth - 300),
    y: Math.min(event.clientY + 12, window.innerHeight - 140),
  };
}

function goCreate(kind) {
  const p = pending.value;
  if (!p) return;
  router.push({
    path: `/rental/${kind}/create`,
    query: {
      item: p.item.id,
      start: p.start.format('YYYY-MM-DD[T]HH:mm:ssZ'),
      end: p.end.format('YYYY-MM-DD[T]HH:mm:ssZ'),
    },
  });
}

function dismissPending() {
  if (pending.value) {
    pending.value = null;
    sel.value = null;
  }
}

// ----- free search -----
function applyFree() {
  if (!freeRange.value?.[0] || !freeRange.value?.[1]) return;
  freeActive.value = true;
  // Jump the board to the searched dates.
  anchor.value = dayjs(freeRange.value[0]).startOf('day');
  load();
}

function clearFree() {
  freeActive.value = false;
  freeRange.value = null;
  load();
}

// ----- data -----
const categories = ref([]);
const warehouses = ref([]);
const categoryOptions = computed(() => categories.value.map(c => ({ value: c.id, label: c.name })));
const warehouseOptions = computed(() => warehouses.value.map(w => ({ value: w.id, label: w.name })));

let loadSeq = 0;
async function load() {
  const seq = ++loadSeq;
  loading.value = true;
  pending.value = null;
  sel.value = null;
  try {
    const params = {
      start: range.value.start.format('YYYY-MM-DD'),
      end: range.value.end.format('YYYY-MM-DD'),
      search: filters.search || '',
      type: filters.type || '',
      category_id: filters.category_id || '',
      warehouse_id: filters.warehouse_id || '',
      include_drafts: filters.include_drafts ? 1 : '',
    };
    if (freeActive.value && freeRange.value?.[0]) {
      params.free_start = freeRange.value[0];
      params.free_end = freeRange.value[1];
      params.free_qty = freeQty.value || 1;
    }
    const data = await http.get('rental/availability/timeline', params);
    if (seq !== loadSeq) return;
    items.value = data?.items || [];
    bookings.value = data?.bookings || [];
  } catch (e) {
    if (seq === loadSeq) message.error(e?.data?.message || t('Rental_Err_Could_Not_Load_Availability', 'Could not load availability'));
  } finally {
    if (seq === loadSeq) loading.value = false;
  }
}

onMounted(async () => {
  load();
  window.addEventListener('mousedown', dismissPending);
  try {
    const meta = await http.get('rental/items/meta');
    categories.value = meta?.categories || [];
    warehouses.value = meta?.warehouses || [];
  } catch (e) { /* filters stay empty without item rights */ }
});

onBeforeUnmount(() => {
  window.removeEventListener('mousedown', dismissPending);
  window.removeEventListener('mousemove', moveSelect);
});
</script>

<style scoped>
.block {
  margin-bottom: 12px;
}
.muted {
  opacity: 0.55;
}

/* toolbar */
.toolbar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
}
.toolbar + .toolbar,
.free {
  margin-top: 10px;
}
.nav {
  display: flex;
  align-items: center;
  gap: 6px;
}
.range-label {
  margin-inline-start: 8px;
  font-size: 15px;
  font-weight: 600;
}
.filters {
  justify-content: flex-start;
}
.f-search {
  flex: 1 1 220px;
  max-width: 320px;
}
.f-item {
  width: 160px;
}
.free {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px;
  padding-top: 10px;
  border-top: 1px dashed rgba(128, 128, 128, 0.22);
}
.free-label {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-weight: 500;
}
.free-range {
  width: 340px;
}
.free-qty {
  width: 170px;
}
.free-tag {
  margin: 0;
}

/* legend */
.legend {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 6px 16px;
  margin: 0 2px 10px;
  font-size: 12.5px;
}
.legend-item {
  display: inline-flex;
  align-items: center;
  gap: 6px;
}
.legend-swatch {
  width: 22px;
  height: 10px;
  border-radius: 4px;
}
.legend-hint {
  margin-inline-start: auto;
  opacity: 0.6;
}

/* board */
.board {
  position: relative;
  overflow: auto;
  max-height: calc(100vh - 330px);
  min-height: 260px;
  user-select: none;
}
.head {
  position: sticky;
  top: 0;
  z-index: 4;
  display: flex;
  background: var(--surface, #fff);
  border-bottom: 1px solid rgba(128, 128, 128, 0.2);
}
.head-corner {
  position: sticky;
  left: 0;
  z-index: 5;
  width: var(--name-w);
  flex: none;
  display: flex;
  align-items: flex-end;
  gap: 4px;
  padding: 8px 12px;
  font-size: 12px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  background: var(--surface, #fff);
  border-inline-end: 1px solid rgba(128, 128, 128, 0.2);
}
.months {
  display: flex;
  border-bottom: 1px solid rgba(128, 128, 128, 0.12);
}
.month {
  flex: none;
  padding: 4px 8px;
  overflow: hidden;
  white-space: nowrap;
  text-overflow: ellipsis;
  font-size: 12px;
  font-weight: 600;
  border-inline-start: 1px solid rgba(128, 128, 128, 0.15);
}
.days {
  display: flex;
}
.day {
  flex: none;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  height: 38px;
  font-size: 12px;
  font-weight: 600;
  border-inline-start: 1px solid rgba(128, 128, 128, 0.1);
}
.day small {
  font-size: 10px;
  font-weight: 400;
  opacity: 0.55;
  text-transform: uppercase;
}
.day.weekend {
  background: rgba(128, 128, 128, 0.06);
}
.day.today {
  color: #fff;
  background: var(--app-primary, #6d28d9);
  border-radius: 6px 6px 0 0;
}
.day.today small {
  opacity: 0.85;
}

.body {
  position: relative;
}
.overlay {
  position: absolute;
  top: 0;
  bottom: 0;
  right: 0;
  pointer-events: none;
}
.ov-weekend {
  position: absolute;
  top: 0;
  bottom: 0;
  background: rgba(128, 128, 128, 0.06);
}
.ov-today {
  position: absolute;
  top: 0;
  bottom: 0;
  width: 2px;
  margin-inline-start: -1px;
  z-index: 3;
  background: var(--app-primary, #6d28d9);
  box-shadow: 0 0 0 3px rgba(var(--app-primary-rgb, 109, 40, 217), 0.15);
}

.row {
  position: relative;
  display: flex;
  border-bottom: 1px solid rgba(128, 128, 128, 0.12);
}
.row:hover .lane {
  background-color: rgba(var(--app-primary-rgb, 109, 40, 217), 0.025);
}
.row.off .lane {
  background-image: repeating-linear-gradient(-45deg, rgba(217, 119, 6, 0.08) 0 6px, transparent 6px 12px);
  cursor: not-allowed;
}
.name {
  position: sticky;
  left: 0;
  z-index: 2;
  width: var(--name-w);
  flex: none;
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 6px 12px;
  color: inherit;
  background: var(--surface, #fff);
  border-inline-end: 1px solid rgba(128, 128, 128, 0.2);
}
.name:hover .name-title {
  color: var(--app-primary, #6d28d9);
}
.thumb {
  width: 34px;
  height: 28px;
  flex: none;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 7px;
  overflow: hidden;
  background: rgba(128, 128, 128, 0.12);
}
.thumb img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}
.name-text {
  min-width: 0;
  display: flex;
  flex-direction: column;
}
.name-title {
  font-weight: 500;
  font-size: 13px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.name-sub {
  display: flex;
  align-items: center;
  gap: 4px;
  font-size: 11px;
  opacity: 0.7;
}
.mini-tag {
  margin: 0;
  padding: 0 5px;
  font-size: 10.5px;
  line-height: 16px;
}
.lane {
  position: relative;
  flex: none;
  width: calc(100% - var(--name-w));
  background-image: repeating-linear-gradient(to right, transparent 0 calc(var(--day-w) - 1px), rgba(128, 128, 128, 0.1) calc(var(--day-w) - 1px) var(--day-w));
  cursor: crosshair;
}
.selection {
  position: absolute;
  top: 3px;
  bottom: 3px;
  z-index: 1;
  border: 1.5px dashed var(--app-primary, #6d28d9);
  border-radius: 8px;
  background: rgba(var(--app-primary-rgb, 109, 40, 217), 0.12);
}

/* bars */
.bar {
  position: absolute;
  z-index: 2;
  height: 22px;
  display: flex;
  align-items: center;
  gap: 4px;
  padding: 0 7px;
  overflow: hidden;
  border-radius: 7px;
  font-size: 11.5px;
  font-weight: 500;
  white-space: nowrap;
  color: #fff;
  cursor: pointer;
  box-shadow: 0 1px 2px rgba(0, 0, 0, 0.12);
  transition: transform 0.1s ease, box-shadow 0.1s ease;
}
.bar:hover {
  transform: translateY(-1px);
  box-shadow: 0 3px 8px rgba(0, 0, 0, 0.18);
  z-index: 3;
}
.bar.cut-l {
  border-start-start-radius: 0;
  border-end-start-radius: 0;
}
.bar.cut-r {
  border-start-end-radius: 0;
  border-end-end-radius: 0;
}
.bar-text {
  overflow: hidden;
  text-overflow: ellipsis;
}
.bar-num {
  opacity: 0.8;
  font-weight: 400;
}
.bar-qty {
  margin-inline-start: auto;
  padding: 0 5px;
  border-radius: 999px;
  font-size: 10.5px;
  background: rgba(0, 0, 0, 0.18);
}
.bar-ic {
  font-size: 10px;
}

/* bar colours (also used by the legend swatches) */
.b-running {
  background: var(--app-primary, #6d28d9);
}
.b-upcoming {
  background: #0284c7;
}
.b-expiring {
  background: #d97706;
}
.b-overdue {
  background: #dc2626;
}
.b-hold {
  color: #075985;
  background: repeating-linear-gradient(-45deg, #bae6fd 0 6px, #e0f2fe 6px 12px);
  border: 1px solid #38bdf8;
}
.b-draft {
  color: #475569;
  background: rgba(148, 163, 184, 0.18);
  border: 1.5px dashed #94a3b8;
}
.b-closed {
  color: #475569;
  background: #cbd5e1;
}
.b-maint {
  color: #9a3412;
  background: repeating-linear-gradient(45deg, #fed7aa 0 6px, #ffedd5 6px 12px);
  border: 1px solid #fb923c;
}

/* drag action popup */
.pop {
  position: fixed;
  z-index: 1050;
  width: 290px;
  padding: 12px;
  border-radius: 12px;
  box-shadow: 0 12px 32px rgba(0, 0, 0, 0.2);
}
.pop-title {
  font-weight: 600;
}
.pop-dates {
  margin: 2px 0 10px;
  font-size: 12.5px;
  opacity: 0.7;
}
.pop-actions {
  display: flex;
  gap: 6px;
}

.empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  padding: 48px 16px;
  text-align: center;
  opacity: 0.7;
}
.empty-ic {
  font-size: 32px;
  margin-bottom: 8px;
  color: var(--app-primary, #6d28d9);
}

.tip {
  white-space: pre-line;
}

@media (max-width: 767px) {
  .free-range,
  .free-qty,
  .f-item {
    width: 100%;
  }
  .legend-hint {
    display: none;
  }
}
</style>
