<template>
  <div class="page">
    <PageHeader
      :title="$t('Bkm_Day_View')"
      :subtitle="$t('Bkm_Day_View_Subtitle')"
      :breadcrumb="[$t('Booking Management'), $t('Bkm_Day_View')]"
    >
      <template #actions>
        <a-button type="primary" @click="$router.push('/bookings/create')">
          <template #icon><PlusOutlined /></template>
          {{ $t('Bkm_New_Booking') }}
        </a-button>
      </template>
    </PageHeader>

    <a-alert v-if="!ready" type="warning" show-icon :message="$t('Bkm_Not_Ready')" />

    <template v-else>
      <div class="toolbar">
        <a-button :aria-label="$t('Bkm_Previous_Day')" @click="shift(-1)"><LeftOutlined /></a-button>
        <a-button @click="goToday">{{ $t('Bkm_Today') }}</a-button>
        <a-button :aria-label="$t('Bkm_Next_Day')" @click="shift(1)"><RightOutlined /></a-button>
        <a-date-picker v-model:value="date" value-format="YYYY-MM-DD" :allow-clear="false" @change="load" />
        <h2 class="day-title">{{ dayTitle }}</h2>
        <a-select
          v-if="warehouses.length > 1" v-model:value="warehouseId" allow-clear :placeholder="$t('Bkm_All_Branches')"
          style="width: 180px" :options="warehouses.map((w) => ({ value: w.id, label: w.name }))" @change="load"
        />
      </div>

      <div class="legend" aria-hidden="true">
        <span v-for="s in LEGEND" :key="s.value" class="legend-item">
          <span class="swatch" :style="{ background: s.bg, borderColor: s.border, borderStyle: s.value === 'pending' ? 'dashed' : 'solid' }"></span>
          {{ statusLabel(s.value) }}
        </span>
        <span class="legend-item"><span class="swatch hatch"></span>{{ $t('Bkm_Not_Working') }}</span>
      </div>

      <a-card size="small" :body-style="{ padding: 0 }">
        <a-spin :spinning="loading">
          <a-empty v-if="!columns.length" class="empty" :description="$t('Bkm_No_Staff')">
            <router-link to="/bookings/staff">{{ $t('Bkm_Staff_Hours') }}</router-link>
          </a-empty>
          <div v-else class="grid-wrap">
            <div class="grid" :style="{ gridTemplateColumns: `64px repeat(${columns.length}, minmax(170px, 1fr))` }">
              <div class="corner"></div>
              <div v-for="c in columns" :key="'h' + c.key" class="col-head">
                <img v-if="c.photo_url" :src="c.photo_url" alt="" class="avatar">
                <span v-else class="avatar" :style="{ background: c.color || '#8c8c8c' }">{{ initial(c.name) }}</span>
                <span class="col-text">
                  <span class="col-name">{{ c.name }}</span>
                  <span class="muted small">{{ c.sub }}</span>
                </span>
              </div>

              <div class="gutter" :style="{ height: px(total) }">
                <span v-for="h in hours" :key="h.label" class="hour" :style="{ top: px(h.top) }">{{ h.label }}</span>
              </div>
              <div v-for="c in columns" :key="'c' + c.key" class="col" :style="{ height: px(total) }">
                <div v-for="(w, i) in c.windows" :key="'w' + i" class="window" :style="{ top: px(w.top), height: px(w.height) }"></div>
                <div v-if="c.closedLabel" class="closed">{{ c.closedLabel }}</div>
                <button
                  v-for="e in c.events" :key="e.id" type="button" class="event" :style="e.style"
                  :aria-label="`${e.time} ${e.title} ${e.customer}`" @click="openBooking(e.id)"
                >
                  <span class="ev-time">{{ e.time }}</span>
                  <span class="ev-title">{{ e.title }}</span>
                  <span class="ev-sub">{{ e.customer }}</span>
                </button>
              </div>
            </div>
          </div>
        </a-spin>
      </a-card>
      <p class="muted small hint">{{ $t('Bkm_Day_View_Help') }}</p>
    </template>

    <BookingQuickView v-model:open="viewOpen" :booking-id="viewId" @changed="load" />
  </div>
</template>

<script setup>
/**
 * Booking Management › Day view: one column per staff member (working hours
 * light, the rest hatched) with that day's bookings; bookings without a staff
 * member get their own column. GET bookings/day?date&warehouse_id.
 * Clicking a booking opens BookingQuickView.
 */
import { ref, computed, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import { message } from 'ant-design-vue';
import { PlusOutlined, LeftOutlined, RightOutlined } from '@ant-design/icons-vue';
import dayjs from 'dayjs';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';
import { t, i18n } from '../../i18n';
import BookingQuickView from './BookingQuickView.vue';
import { STATUSES, statusLabel, statusColors } from './bookingStatus';

const HOUR = 64; // px per hour
const LEGEND = STATUSES.filter((s) => ['pending', 'confirmed', 'arrived', 'completed', 'no_show'].includes(s.value));
const CLOSED = {
  day_off: ['Bkm_Closed_Day_Off', 'Not working this day.'],
  holiday: ['Bkm_Closed_Holiday', 'Company holiday.'],
  leave: ['Bkm_Closed_Leave', 'On approved leave.'],
  time_off: ['Bkm_Closed_Time_Off', 'Day off.'],
};

const route = useRoute();
const ready = ref(true);
const loading = ref(false);
const date = ref(/^\d{4}-\d{2}-\d{2}$/.test(String(route.query.date || '')) ? String(route.query.date) : dayjs().format('YYYY-MM-DD'));
const warehouseId = ref(undefined);
const warehouses = ref([]);
const data = ref({ staff: [], unassigned: [], range: { from: '08:00', to: '20:00' } });
const viewOpen = ref(false);
const viewId = ref(null);

function locale() {
  const l = i18n.global.locale;
  return (typeof l === 'string' ? l : l?.value) || 'en';
}
const dayTitle = computed(() => {
  try {
    return new Intl.DateTimeFormat(locale(), { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }).format(dayjs(date.value).toDate());
  } catch (e) {
    return date.value;
  }
});
function mins(hhmm) {
  const [h, m] = String(hhmm || '0:0').split(':').map(Number);
  return h * 60 + (m || 0);
}
const start = computed(() => mins(data.value.range?.from || '08:00'));
const total = computed(() => Math.max(60, mins(data.value.range?.to || '20:00') - start.value) * HOUR / 60);
function px(v) {
  return `${Math.round(v)}px`;
}
function initial(name) {
  return String(name || '?').trim().charAt(0).toUpperCase() || '?';
}
const hours = computed(() => {
  const out = [];
  for (let m = start.value; m < start.value + total.value * 60 / HOUR; m += 60) {
    out.push({ label: `${String(Math.floor(m / 60)).padStart(2, '0')}:00`, top: (m - start.value) * HOUR / 60 });
  }
  return out;
});

function events(list) {
  return (list || []).map((b) => {
    const s = mins(b.time);
    const e = b.end_time ? mins(b.end_time) : s + (b.duration_minutes || 60);
    const c = statusColors(b.status);
    const top = (s - start.value) * HOUR / 60;
    const height = Math.max(22, (e - s) * HOUR / 60 - 3);
    return {
      id: b.id,
      time: b.end_time ? `${b.time} – ${b.end_time}` : b.time,
      title: b.product?.name || b.Ref,
      customer: [b.customer?.name, b.seats > 1 ? `× ${b.seats}` : ''].filter(Boolean).join(' '),
      style: {
        top: px(top + 1), height: px(height), background: c.bg, color: c.text,
        border: `${b.status === 'pending' ? 2 : 1}px ${b.status === 'pending' ? 'dashed' : 'solid'} ${c.border}`,
      },
    };
  });
}
const columns = computed(() => {
  const cols = (data.value.staff || []).map((s) => ({
    key: s.id,
    name: s.name,
    color: s.color,
    photo_url: s.photo_url,
    sub: s.removed ? t('Bkm_Staff_Not_Bookable', 'No longer bookable') : (s.windows.length ? s.windows.map((w) => `${w.from}–${w.to}`).join(', ') : t(...(CLOSED[s.closed] || CLOSED.day_off))),
    closedLabel: s.windows.length || s.removed ? '' : t(...(CLOSED[s.closed] || CLOSED.day_off)),
    windows: s.windows.map((w) => ({ top: (mins(w.from) - start.value) * HOUR / 60, height: (mins(w.to) - mins(w.from)) * HOUR / 60 })),
    events: events(s.bookings),
  }));
  if ((data.value.unassigned || []).length) {
    cols.push({
      key: 'none', name: t('Bkm_Not_Assigned', 'Not assigned'), color: '#8c8c8c', photo_url: null,
      sub: t('Bkm_No_Staff_Set', 'No staff member set'), closedLabel: '', windows: [{ top: 0, height: total.value }],
      events: events(data.value.unassigned),
    });
  }
  return cols;
});

async function load() {
  loading.value = true;
  try {
    const res = await http.get('bookings/day', { date: date.value, warehouse_id: warehouseId.value || undefined });
    ready.value = res?.ready !== false;
    if (res?.ready !== false) {
      data.value = res;
      warehouses.value = res.warehouses || [];
    }
  } catch (e) {
    message.error(t('Bkm_Could_Not_Load_Day', 'Could not load the day.'));
  } finally {
    loading.value = false;
  }
}
function shift(n) {
  date.value = dayjs(date.value).add(n, 'day').format('YYYY-MM-DD');
  load();
}
function goToday() {
  date.value = dayjs().format('YYYY-MM-DD');
  load();
}
function openBooking(id) {
  viewId.value = id;
  viewOpen.value = true;
}

onMounted(load);
</script>

<style scoped>
.toolbar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px;
  margin-bottom: 10px;
}
.day-title {
  margin: 0 8px;
  font-size: 18px;
  flex: 1 1 auto;
}
.legend {
  display: flex;
  flex-wrap: wrap;
  gap: 14px;
  margin-bottom: 10px;
  font-size: 13px;
}
.legend-item {
  display: inline-flex;
  align-items: center;
  gap: 6px;
}
.swatch {
  width: 14px;
  height: 14px;
  border-radius: 4px;
  border: 2px solid transparent;
  box-sizing: border-box;
}
.hatch,
.col {
  background: repeating-linear-gradient(45deg, rgba(128, 128, 128, 0.12) 0 6px, rgba(128, 128, 128, 0.04) 6px 12px);
}
.empty {
  padding: 40px 16px;
}
.muted { color: rgba(0, 0, 0, 0.55); }
.small { font-size: 12px; }
.hint {
  margin-top: 8px;
}
.grid-wrap {
  overflow-x: auto;
}
.grid {
  display: grid;
  min-width: 640px;
}
.corner,
.col-head {
  border-bottom: 1px solid rgba(128, 128, 128, 0.2);
}
.col-head {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px 12px;
  border-inline-start: 1px solid rgba(128, 128, 128, 0.2);
  min-width: 0;
}
.avatar {
  width: 32px;
  height: 32px;
  flex: none;
  border-radius: 50%;
  object-fit: cover;
  color: #fff;
  font-weight: 700;
  display: inline-flex;
  align-items: center;
  justify-content: center;
}
.col-text {
  display: flex;
  flex-direction: column;
  min-width: 0;
}
.col-name {
  font-weight: 600;
}
.gutter {
  position: relative;
}
.hour {
  position: absolute;
  inset-inline-end: 8px;
  font-size: 12px;
  color: rgba(0, 0, 0, 0.55);
  transform: translateY(2px);
}
.col {
  position: relative;
  border-inline-start: 1px solid rgba(128, 128, 128, 0.2);
}
.window {
  position: absolute;
  inset-inline: 0;
  background-color: #fff;
  background-image: repeating-linear-gradient(to bottom, rgba(128, 128, 128, 0.16) 0 1px, transparent 1px 64px);
}
.closed {
  position: absolute;
  top: 12px;
  inset-inline: 8px;
  text-align: center;
  font-size: 13px;
  color: rgba(0, 0, 0, 0.6);
}
.event {
  position: absolute;
  inset-inline: 6px;
  border-radius: 8px;
  padding: 4px 8px;
  text-align: start;
  overflow: hidden;
  cursor: pointer;
  font: inherit;
  display: flex;
  flex-direction: column;
  box-sizing: border-box;
}
.event:focus-visible {
  outline: 2px solid var(--app-primary, #6d28d9);
  outline-offset: 2px;
}
.ev-time {
  font-size: 12px;
  font-weight: 600;
}
.ev-title {
  font-size: 13px;
  font-weight: 600;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.ev-sub {
  font-size: 12px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
</style>
