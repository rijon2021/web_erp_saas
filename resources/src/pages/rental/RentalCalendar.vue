<template>
  <div class="page">
    <PageHeader
      :title="$t('Rental_Calendar')"
      :subtitle="$t('Rental_Calendar_Subtitle')"
      :breadcrumb="[$t('Rental_Management'), $t('Rental_Calendar')]"
    >
      <template #actions>
        <a-segmented v-model:value="kind" :options="kindOptions" />
        <a-button @click="$router.push('/rental/availability')">
          <template #icon><ScheduleOutlined /></template>
          {{ $t('Rental_Availability') }}
        </a-button>
      </template>
    </PageHeader>

    <a-row :gutter="16">
      <a-col :xs="24" :xl="16">
        <a-card size="small" class="block">
          <a-spin :spinning="loading">
            <a-calendar v-model:value="selected" class="cal" @panel-change="onPanelChange">
              <template #dateCellRender="{ current }">
                <ul class="cell">
                  <li v-for="e in eventsOn(current).slice(0, 3)" :key="`${e.kind}${e.id}${e.type}`" class="cell-ev" :class="e.type">
                    <span class="dot"></span>{{ e.customer_name }}
                  </li>
                  <li v-if="eventsOn(current).length > 3" class="cell-more">+{{ eventsOn(current).length - 3 }}</li>
                </ul>
              </template>
            </a-calendar>
          </a-spin>
        </a-card>
      </a-col>

      <!-- Day agenda -->
      <a-col :xs="24" :xl="8">
        <a-card size="small" class="block agenda">
          <template #title>
            <span class="agenda-title">
              <span class="agenda-day">{{ selected.format('D') }}</span>
              <span>
                <strong>{{ selected.format('dddd') }}</strong>
                <span class="agenda-sub">{{ selected.format('MMMM YYYY') }}</span>
              </span>
            </span>
          </template>
          <template #extra>
            <a-button v-if="!isToday" size="small" @click="selected = dayjs()">{{ $t('Rental_Today') }}</a-button>
          </template>

          <div class="counts">
            <div class="count pickup"><strong>{{ dayEvents.filter(e => e.type === 'pickup').length }}</strong><span>{{ $t('Rental_Pickups') }}</span></div>
            <div class="count return"><strong>{{ dayEvents.filter(e => e.type === 'return').length }}</strong><span>{{ $t('Rental_Returns') }}</span></div>
            <div class="count reservation"><strong>{{ dayEvents.filter(e => e.type === 'reservation').length }}</strong><span>{{ $t('Rental_Reservations') }}</span></div>
          </div>

          <div v-if="dayEvents.length" class="list">
            <router-link
              v-for="e in dayEvents" :key="`${e.kind}${e.id}${e.type}`"
              :to="e.kind === 'contract' ? `/rental/contracts/${e.id}` : `/rental/reservations/${e.id}`"
              class="ev" :class="e.type"
            >
              <span class="ev-ic">
                <ExportOutlined v-if="e.type === 'pickup'" />
                <ImportOutlined v-else-if="e.type === 'return'" />
                <LockOutlined v-else />
              </span>
              <span class="ev-body">
                <span class="ev-top">
                  <strong>{{ e.customer_name }}</strong>
                  <span class="ev-time">{{ dayjs(e.date).format('HH:mm') }}</span>
                </span>
                <span class="ev-items">{{ e.items }}</span>
                <span class="ev-meta">
                  {{ typeLabel(e.type) }} · {{ e.number }}
                  <a-tag v-if="e.done" color="success" class="ev-tag">{{ $t('Rental_Done') }}</a-tag>
                </span>
              </span>
            </router-link>
          </div>
          <a-empty v-else :image="simpleEmptyImage" :description="$t('Rental_Nothing_This_Day')" />
        </a-card>
      </a-col>
    </a-row>
  </div>
</template>

<script setup>
/**
 * Pickups / returns calendar — GET rental/availability/calendar for the
 * visible month grid (± a week, since the grid shows neighbouring days).
 * Pickup = a contract starting, return = a contract's agreed end,
 * reservation = a hold whose dates begin that day.
 */
import { ref, computed, watch, onMounted } from 'vue';
import { message, Empty } from 'ant-design-vue';
import dayjs from 'dayjs';
import { ScheduleOutlined, ExportOutlined, ImportOutlined, LockOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';
import { t } from '../../i18n';

const simpleEmptyImage = Empty.PRESENTED_IMAGE_SIMPLE;

const selected = ref(dayjs());
const events = ref([]);
const loading = ref(false);
const kind = ref('all');
let loadedKey = '';

const kindOptions = computed(() => [
  { value: 'all', label: t('All', 'All') },
  { value: 'pickup', label: t('Rental_Pickups', 'Pickups') },
  { value: 'return', label: t('Rental_Returns', 'Returns') },
  { value: 'reservation', label: t('Rental_Reservations', 'Reservations') },
]);

const isToday = computed(() => selected.value.isSame(dayjs(), 'day'));

/** Events grouped by YYYY-MM-DD, filtered by the segmented control. */
const byDay = computed(() => {
  const map = new Map();
  events.value
    .filter(e => kind.value === 'all' || e.type === kind.value)
    .forEach((e) => {
      const key = dayjs(e.date).format('YYYY-MM-DD');
      if (!map.has(key)) map.set(key, []);
      map.get(key).push(e);
    });
  return map;
});

function eventsOn(d) {
  return byDay.value.get(d.format('YYYY-MM-DD')) || [];
}

const dayEvents = computed(() => eventsOn(selected.value));

function typeLabel(type) {
  return {
    pickup: t('Rental_Pickup', 'Pickup'),
    return: t('Rental_Return', 'Return'),
    reservation: t('Rental_Reservation', 'Reservation'),
  }[type] || type;
}

let loadSeq = 0;

async function loadMonth(d) {
  // The month grid is 6 weeks, so up to 14 days of the next month show.
  const start = d.startOf('month').subtract(7, 'day');
  const end = d.endOf('month').add(14, 'day');
  const key = start.format('YYYY-MM-DD');
  if (key === loadedKey) return;
  const seq = ++loadSeq;
  loading.value = true;
  try {
    const data = await http.get('rental/availability/calendar', {
      start: start.format('YYYY-MM-DD'),
      end: end.format('YYYY-MM-DD'),
    });
    if (seq !== loadSeq) return; // a newer month was asked for meanwhile
    events.value = data?.events || [];
    loadedKey = key;
  } catch (e) {
    message.error(t('Rental_Could_Not_Load_Calendar', 'Could not load the calendar'));
  } finally {
    loading.value = false;
  }
}

function onPanelChange(value) {
  loadMonth(value);
}

// Clicking a greyed day of the next/previous month moves the panel too.
watch(selected, v => loadMonth(v));
onMounted(() => loadMonth(selected.value));
</script>

<style scoped>
.block {
  margin-bottom: 16px;
}
.cal :deep(.ant-picker-calendar-date-content) {
  height: 74px;
}
.cell {
  list-style: none;
  margin: 0;
  padding: 0;
}
.cell-ev {
  display: flex;
  align-items: center;
  gap: 5px;
  overflow: hidden;
  font-size: 11.5px;
  line-height: 18px;
  white-space: nowrap;
  text-overflow: ellipsis;
}
.dot {
  width: 7px;
  height: 7px;
  flex: none;
  border-radius: 50%;
}
.cell-ev.pickup .dot,
.count.pickup strong,
.ev.pickup .ev-ic {
  background-color: #16a34a;
}
.cell-ev.return .dot,
.count.return strong,
.ev.return .ev-ic {
  background-color: #0284c7;
}
.cell-ev.reservation .dot,
.count.reservation strong,
.ev.reservation .ev-ic {
  background-color: #7c3aed;
}
.cell-more {
  font-size: 11px;
  opacity: 0.6;
}

/* agenda */
.agenda-title {
  display: inline-flex;
  align-items: center;
  gap: 12px;
}
.agenda-day {
  width: 42px;
  height: 42px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 12px;
  font-size: 18px;
  font-weight: 700;
  color: #fff;
  background: var(--app-primary, #6d28d9);
}
.agenda-sub {
  display: block;
  font-size: 12px;
  font-weight: 400;
  opacity: 0.6;
}
.counts {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 8px;
  margin-bottom: 14px;
}
.count {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 8px 10px;
  border-radius: 10px;
  background: rgba(128, 128, 128, 0.07);
  font-size: 12px;
}
.count strong {
  min-width: 26px;
  padding: 0 6px;
  border-radius: 999px;
  color: #fff;
  font-size: 13px;
  line-height: 22px;
  text-align: center;
}
.list {
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.ev {
  display: flex;
  gap: 10px;
  padding: 10px;
  border: 1px solid rgba(128, 128, 128, 0.18);
  border-radius: 12px;
  color: inherit;
}
.ev:hover {
  border-color: rgba(var(--app-primary-rgb, 109, 40, 217), 0.5);
}
.ev-ic {
  width: 32px;
  height: 32px;
  flex: none;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 9px;
  color: #fff;
}
.ev-body {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
}
.ev-top {
  display: flex;
  justify-content: space-between;
  gap: 8px;
}
.ev-time {
  font-size: 12px;
  font-weight: 600;
  opacity: 0.7;
}
.ev-items {
  font-size: 12.5px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.ev-meta {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 11.5px;
  opacity: 0.6;
}
.ev-tag {
  margin: 0;
}

@media (max-width: 767px) {
  .cal :deep(.ant-picker-calendar-date-content) {
    height: 44px;
  }
  .cell-ev {
    font-size: 0;
  }
}
</style>
