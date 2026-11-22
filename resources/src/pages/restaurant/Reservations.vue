<template>
  <div class="rsv-page">
    <PageHeader :title="$t('Rst_Reservations')" :subtitle="$t('Rst_Reservations_Subtitle')" :breadcrumb="[$t('Restaurant_Management'), $t('Rst_Reservations')]">
      <template #actions>
        <a-input-search v-model:value="searchTerm" allow-clear :placeholder="$t('Rst_Search_Bookings')" style="width: 220px" @search="runSearch" />
        <a-button @click="openWait()"><UserAddOutlined /> {{ $t('Rst_Add_To_Waitlist') }}</a-button>
        <a-button type="primary" @click="openBooking()"><PlusOutlined /> {{ $t('Rst_New_Booking') }}</a-button>
      </template>
    </PageHeader>

    <div v-if="loading && first" class="loading"><a-spin size="large" /></div>
    <a-alert v-else-if="!ready" type="warning" show-icon :message="$t('Rst_Not_Ready')" />

    <template v-else>
      <!-- Day navigation -->
      <div class="daybar">
        <a-button :aria-label="$t('Rst_Previous_Day')" @click="shiftDay(-1)"><LeftOutlined /></a-button>
        <a-date-picker v-model:value="date" value-format="YYYY-MM-DD" :allow-clear="false" @change="load" />
        <a-button :aria-label="$t('Rst_Next_Day')" @click="shiftDay(1)"><RightOutlined /></a-button>
        <a-button :disabled="date === today" @click="date = today; load()">{{ $t('Rst_Today') }}</a-button>
        <div class="week">
          <button v-for="d in week" :key="d.date" type="button" :class="['wd', { on: d.date === date }]" @click="date = d.date; load()">
            <span class="wd-name">{{ dayName(d.date) }}</span>
            <span class="wd-num">{{ d.date.slice(8) }}</span>
            <span class="wd-count">{{ d.bookings ? $t('Rst_Bookings_Short', { n: d.bookings, covers: d.covers }) : '—' }}</span>
          </button>
        </div>
      </div>

      <!-- Search results -->
      <a-card v-if="searchResults" size="small" class="block">
        <template #title>{{ $t('Rst_Search_Results', { q: searchedFor }) }}</template>
        <template #extra><a-button size="small" type="link" @click="searchResults = null">{{ $t('Close') }}</a-button></template>
        <a-empty v-if="!searchResults.length" :description="$t('Rst_No_Bookings_Found')" />
        <div v-for="r in searchResults" :key="r.id" class="sr" role="button" tabindex="0" @click="goTo(r)" @keydown.enter="goTo(r)">
          <strong>{{ r.date }} {{ r.time }}</strong> · {{ r.customer_name }} <span class="muted">· {{ $t('Rst_Party_N', { n: r.party_size }) }}{{ r.table ? ' · ' + r.table.name : '' }}{{ r.customer_phone ? ' · ' + r.customer_phone : '' }}</span>
          <a-tag :color="statusColor(r.status)" style="margin-inline-start: 6px">{{ statusLabel(r.status) }}</a-tag>
        </div>
      </a-card>

      <div class="stats">
        <div class="stat"><span>{{ $t('Rst_Bookings') }}</span><strong>{{ counts.bookings }}</strong></div>
        <div class="stat"><span>{{ $t('Rst_Covers') }}</span><strong>{{ counts.covers }}</strong></div>
        <div class="stat"><span>{{ $t('Rst_To_Arrive') }}</span><strong>{{ counts.to_arrive }}</strong></div>
        <div class="stat"><span>{{ $t('Rst_Seated') }}</span><strong>{{ counts.seated }}</strong></div>
        <div class="stat"><span>{{ $t('Rst_No_Shows') }}</span><strong>{{ counts.no_show }}</strong></div>
        <div v-if="isToday" class="stat"><span>{{ $t('Rst_Waiting') }}</span><strong>{{ counts.waiting }}</strong></div>
      </div>

      <div class="cols">
        <!-- Bookings -->
        <a-card size="small" class="main">
          <template #title>
            <a-segmented v-model:value="view" :options="[{ value: 'list', label: $t('Rst_View_List') }, { value: 'timeline', label: $t('Rst_View_Timeline') }]" />
          </template>
          <template #extra>
            <a-checkbox v-if="view === 'list'" v-model:checked="showClosed">{{ $t('Rst_Show_Closed') }}</a-checkbox>
          </template>

          <template v-if="view === 'list'">
            <a-empty v-if="!listRows.length" :description="$t('Rst_No_Bookings_Day')">
              <a-button type="primary" @click="openBooking()">{{ $t('Rst_New_Booking') }}</a-button>
            </a-empty>
            <div v-for="r in listRows" :key="r.id" :class="['bk', 'bs-' + r.status, { late: r.late, soon: r.soon && !r.late, hl: r.id === highlight }]">
              <div class="bk-time">
                <strong class="num">{{ r.time }}</strong>
                <span class="muted small num">{{ r.end_time }}</span>
              </div>
              <div class="bk-body">
                <div class="bk-name">{{ r.customer_name }} <span class="muted">· {{ $t('Rst_Party_N', { n: r.party_size }) }}</span></div>
                <div class="muted small">
                  <span>{{ r.table ? $t('Rst_Table_N', { name: r.table.name }) : (areaName(r.area_id) || $t('Rst_No_Table_Yet')) }}</span>
                  <span v-if="r.customer_phone"> · <a :href="'tel:' + r.customer_phone" @click.stop>{{ r.customer_phone }}</a></span>
                  <span> · {{ sourceLabel(r.source) }}</span>
                </div>
                <div v-if="r.note" class="note">{{ r.note }}</div>
                <div v-if="r.cancel_reason" class="muted small">{{ r.cancel_reason }}</div>
              </div>
              <div class="bk-side">
                <a-tag :color="statusColor(r.status)">{{ statusLabel(r.status) }}</a-tag>
                <span v-if="r.late && upcoming(r)" class="flag flag-late">{{ $t('Rst_Late_By', { n: -r.minutes_to }) }}</span>
                <span v-else-if="r.soon && upcoming(r)" class="flag flag-soon">{{ $t('Rst_Due_In', { n: r.minutes_to }) }}</span>
                <div class="bk-actions">
                  <a-button v-if="upcoming(r) && isToday" size="small" type="primary" @click="openSeat(r)">{{ $t('Rst_Seat') }}</a-button>
                  <a-button v-if="r.status === 'booked'" size="small" @click="act(r, 'confirm')">{{ $t('Rst_Confirm') }}</a-button>
                  <a-button v-if="r.status !== 'completed' && r.status !== 'cancelled' && r.status !== 'no_show'" size="small" @click="openBooking(r)">{{ $t('Edit') }}</a-button>
                  <a-dropdown v-if="upcoming(r) || r.status === 'cancelled' || r.status === 'no_show'" :trigger="['click']">
                    <a-button size="small" :aria-label="$t('Rst_More')"><MoreOutlined /></a-button>
                    <template #overlay>
                      <a-menu>
                        <a-menu-item v-if="upcoming(r) && r.late" key="ns" @click="act(r, 'no_show')">{{ $t('Rst_Mark_No_Show') }}</a-menu-item>
                        <a-menu-item v-if="upcoming(r)" key="cx" danger @click="openCancel(r)">{{ $t('Rst_Cancel_Booking') }}</a-menu-item>
                        <a-menu-item v-if="r.status === 'cancelled' || r.status === 'no_show'" key="rs" @click="act(r, 'restore')">{{ $t('Rst_Restore_Booking') }}</a-menu-item>
                      </a-menu>
                    </template>
                  </a-dropdown>
                </div>
              </div>
            </div>
          </template>

          <!-- Timeline: one row per table, bookings as blocks -->
          <div v-else class="tl" :style="{ '--hours': hours.length }">
            <div class="tl-head">
              <div class="tl-label"></div>
              <div class="tl-track">
                <span v-for="h in hours" :key="h" class="tl-hour" :style="{ insetInlineStart: pct(h * 60) + '%' }">{{ String(h % 24).padStart(2, '0') }}:00</span>
              </div>
            </div>
            <div v-for="t in tables" :key="t.id" class="tl-row">
              <div class="tl-label"><strong>{{ t.name }}</strong> <span class="muted small">{{ $t('Rst_Seats_N', { n: t.seats }) }}</span></div>
              <div class="tl-track" role="button" tabindex="0" :aria-label="$t('Rst_Book_Table', { name: t.name })" @click="clickTrack($event, t)" @keydown.enter="openBooking(null, { table_id: t.id })">
                <span v-if="nowPct !== null" class="tl-now" :style="{ insetInlineStart: nowPct + '%' }"></span>
                <button
                  v-for="r in tableBookings(t.id)" :key="r.id" type="button" :class="['tl-block', 'bs-' + r.status, { late: r.late && upcoming(r) }]"
                  :style="blockStyle(r)" :title="`${r.time}–${r.end_time} · ${r.customer_name} (${r.party_size})`" @click.stop="openBooking(r)"
                >
                  <span class="tl-b-name">{{ r.customer_name }}</span><span class="tl-b-meta">{{ r.time }} · {{ r.party_size }}</span>
                </button>
              </div>
            </div>
            <div v-if="unassigned.length" class="tl-row">
              <div class="tl-label"><strong>{{ $t('Rst_No_Table_Yet') }}</strong></div>
              <div class="tl-track">
                <button v-for="r in unassigned" :key="r.id" type="button" :class="['tl-block', 'bs-' + r.status]" :style="blockStyle(r)" @click.stop="openBooking(r)">
                  <span class="tl-b-name">{{ r.customer_name }}</span><span class="tl-b-meta">{{ r.time }} · {{ r.party_size }}</span>
                </button>
              </div>
            </div>
            <p class="muted small">{{ $t('Rst_Timeline_Hint') }}</p>
          </div>
        </a-card>

        <!-- Waitlist (today) -->
        <a-card v-if="isToday" size="small" class="side">
          <template #title>{{ $t('Rst_Waitlist') }} <a-badge :count="counts.waiting" :number-style="{ backgroundColor: '#6d28d9' }" /></template>
          <template #extra><a-button size="small" @click="openWait()"><PlusOutlined /></a-button></template>
          <a-empty v-if="!waitlist.length" :description="$t('Rst_Nobody_Waiting')" />
          <div v-for="(w, i) in waitlist" :key="w.id" :class="['wl', 'ws-' + w.status]">
            <div class="wl-top">
              <span class="wl-pos num">{{ isWaiting(w) ? i + 1 : '' }}</span>
              <div class="wl-body">
                <div><strong>{{ w.customer_name }}</strong> <span class="muted">· {{ $t('Rst_Party_N', { n: w.party_size }) }}</span></div>
                <div class="muted small">
                  {{ $t('Rst_Waiting_For', { n: w.waited_minutes }) }}<span v-if="w.quoted_minutes !== null"> / {{ $t('Rst_Quoted_N', { n: w.quoted_minutes }) }}</span><span v-if="w.area"> · {{ w.area }}</span>
                </div>
                <div v-if="w.customer_phone" class="small">
                  <a :href="'tel:' + w.customer_phone">{{ w.customer_phone }}</a>
                  · <a :href="whatsAppLink(w)" target="_blank" rel="noopener noreferrer">WhatsApp</a>
                </div>
                <div v-if="w.note" class="note">{{ w.note }}</div>
              </div>
              <a-tag v-if="w.status === 'notified'" color="blue">{{ $t('Rst_Notified_At', { time: w.notified_at }) }}</a-tag>
              <a-tag v-else-if="w.status === 'seated'" color="green">{{ $t('Rst_Seated') }}</a-tag>
              <a-tag v-else-if="w.status === 'left'">{{ $t('Rst_Left') }}</a-tag>
              <span v-else-if="w.over_quote" class="flag flag-late">{{ $t('Rst_Over_Quote') }}</span>
            </div>
            <div v-if="isWaiting(w)" class="wl-actions">
              <a-button size="small" type="primary" @click="openSeatWait(w)">{{ $t('Rst_Seat') }}</a-button>
              <a-button v-if="w.status === 'waiting'" size="small" @click="waitAct(w, 'notify')">{{ $t('Rst_Table_Ready_Notify') }}</a-button>
              <a-button size="small" @click="openWait(w)">{{ $t('Edit') }}</a-button>
              <a-button size="small" danger @click="waitAct(w, 'left')">{{ $t('Rst_Left') }}</a-button>
            </div>
            <div v-else-if="w.status === 'left'" class="wl-actions">
              <a-button size="small" type="link" @click="waitAct(w, 'back')">{{ $t('Rst_Back_In_Queue') }}</a-button>
            </div>
          </div>
        </a-card>
      </div>
    </template>

    <!-- Booking form -->
    <a-modal v-model:open="bookingOpen" :title="booking.id ? $t('Rst_Edit_Booking') : $t('Rst_New_Booking')" :ok-text="$t('Save')" :confirm-loading="busy" width="640px" @ok="saveBooking">
      <a-form layout="vertical">
        <a-row :gutter="12">
          <a-col :xs="24" :sm="12"><a-form-item :label="$t('Name')" required><a-input v-model:value="booking.customer_name" :maxlength="120" /></a-form-item></a-col>
          <a-col :xs="24" :sm="12"><a-form-item :label="$t('Phone')"><a-input v-model:value="booking.customer_phone" :maxlength="40" /></a-form-item></a-col>
          <a-col :xs="12" :sm="6"><a-form-item :label="$t('Rst_Party')" required><a-input-number v-model:value="booking.party_size" :min="1" :max="settings.max_party_size || 20" style="width: 100%" /></a-form-item></a-col>
          <a-col :xs="12" :sm="6"><a-form-item :label="$t('Date')" required><a-date-picker v-model:value="booking.date" value-format="YYYY-MM-DD" :allow-clear="false" :disabled="booking.status === 'seated'" style="width: 100%" /></a-form-item></a-col>
          <a-col :xs="12" :sm="6"><a-form-item :label="$t('Rst_Time')" required><a-time-picker v-model:value="booking.time" value-format="HH:mm" format="HH:mm" :minute-step="15" :allow-clear="false" :disabled="booking.status === 'seated'" style="width: 100%" /></a-form-item></a-col>
          <a-col :xs="12" :sm="6"><a-form-item :label="$t('Rst_Length')"><a-select v-model:value="booking.minutes" :options="lengthOptions" :disabled="booking.status === 'seated'" /></a-form-item></a-col>
        </a-row>
        <a-form-item :label="$t('Rst_Table')" :extra="tableHint">
          <a-select v-model:value="booking.table_id" allow-clear :placeholder="$t('Rst_Decide_Later')" :loading="availLoading" :disabled="booking.status === 'seated'" :options="tableOptions" />
        </a-form-item>
        <a-row :gutter="12">
          <a-col v-if="!booking.table_id" :xs="24" :sm="12"><a-form-item :label="$t('Rst_Area_Preference')"><a-select v-model:value="booking.area_id" allow-clear :placeholder="$t('Rst_Any_Area')" :options="areas.map(a => ({ value: a.id, label: a.name }))" /></a-form-item></a-col>
          <a-col :xs="24" :sm="12"><a-form-item :label="$t('Email')"><a-input v-model:value="booking.customer_email" :maxlength="191" /></a-form-item></a-col>
          <a-col v-if="!booking.id" :xs="24" :sm="12"><a-form-item :label="$t('Rst_Booked_Via')"><a-select v-model:value="booking.source" :options="sourceOptions" /></a-form-item></a-col>
        </a-row>
        <a-form-item :label="$t('Rst_Booking_Note')"><a-textarea v-model:value="booking.note" :rows="2" :maxlength="500" :placeholder="$t('Rst_Booking_Note_Example')" /></a-form-item>
      </a-form>
    </a-modal>

    <!-- Seat a booking / a waiting party -->
    <a-modal v-model:open="seatOpen" :title="seatTarget ? $t('Rst_Seat_Party', { name: seatTarget.customer_name, n: seatTarget.party_size }) : ''" :ok-text="$t('Rst_Seat')" :confirm-loading="busy" :ok-button-props="{ disabled: !seatTable }" @ok="doSeat">
      <a-spin :spinning="availLoading">
        <p class="muted">{{ $t('Rst_Choose_Free_Table_Now') }}</p>
        <a-radio-group v-model:value="seatTable" class="seat-opts">
          <a-radio v-for="t in seatTables" :key="t.id" :value="t.id" :disabled="!t.free" class="seat-opt">
            <strong>{{ t.name }}</strong> <span class="muted">· {{ $t('Rst_Seats_N', { n: t.seats }) }}{{ t.area ? ' · ' + t.area : '' }}</span>
            <span v-if="t.in_use" class="muted"> · {{ $t('Rst_In_Use') }}</span>
            <span v-else-if="t.clash" class="muted"> · {{ $t('Rst_Booked_For_At', { name: t.clash.name, time: t.clash.time }) }}</span>
            <span v-else-if="!t.fits" class="flag flag-soon"> {{ $t('Rst_Too_Small') }}</span>
          </a-radio>
        </a-radio-group>
      </a-spin>
    </a-modal>

    <!-- Cancel -->
    <a-modal v-model:open="cancelOpen" :title="$t('Rst_Cancel_Booking')" :ok-text="$t('Rst_Cancel_Booking')" ok-type="danger" :confirm-loading="busy" @ok="doCancel">
      <a-input v-model:value="cancelReason" :maxlength="255" :placeholder="$t('Rst_Reason_Optional')" />
    </a-modal>

    <!-- Waitlist form -->
    <a-modal v-model:open="waitOpen" :title="wait.id ? $t('Rst_Edit_Waiting') : $t('Rst_Add_To_Waitlist')" :ok-text="$t('Save')" :confirm-loading="busy" @ok="saveWait">
      <a-form layout="vertical">
        <a-row :gutter="12">
          <a-col :span="16"><a-form-item :label="$t('Name')" required><a-input v-model:value="wait.customer_name" :maxlength="120" /></a-form-item></a-col>
          <a-col :span="8"><a-form-item :label="$t('Rst_Party')" required><a-input-number v-model:value="wait.party_size" :min="1" :max="settings.max_party_size || 20" style="width: 100%" /></a-form-item></a-col>
          <a-col :span="12"><a-form-item :label="$t('Phone')"><a-input v-model:value="wait.customer_phone" :maxlength="40" /></a-form-item></a-col>
          <a-col :span="12"><a-form-item :label="$t('Rst_Quoted_Wait')"><a-input-number v-model:value="wait.quoted_minutes" :min="0" :max="600" :addon-after="$t('Rst_Minutes')" style="width: 100%" /></a-form-item></a-col>
          <a-col :span="12"><a-form-item :label="$t('Rst_Area_Preference')"><a-select v-model:value="wait.area_id" allow-clear :placeholder="$t('Rst_Any_Area')" :options="areas.map(a => ({ value: a.id, label: a.name }))" /></a-form-item></a-col>
          <a-col :span="12"><a-form-item :label="$t('Rst_Booking_Note')"><a-input v-model:value="wait.note" :maxlength="255" /></a-form-item></a-col>
        </a-row>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * Restaurant › Reservations & waitlist (permission rst_reservations).
 * GET restaurant/reservations?date, GET .../search?q, GET .../availability; POST/PUT restaurant/reservations,
 * POST restaurant/reservations/{id}/{confirm|cancel|no_show|restore|seat};
 * POST/PUT restaurant/waitlist, POST restaurant/waitlist/{id}/{notify|seat|left|back}.
 * Times are the restaurant's wall clock ("YYYY-MM-DD" + "HH:mm"), never converted in the browser.
 */
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue';
import { useRoute } from 'vue-router';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import dayjs from 'dayjs';
import { PlusOutlined, UserAddOutlined, LeftOutlined, RightOutlined, MoreOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';

const { t, locale } = useI18n();
const route = useRoute();

const loading = ref(true);
const first = ref(true);
const ready = ref(true);
const busy = ref(false);
const date = ref(dayjs().format('YYYY-MM-DD'));
const today = ref(dayjs().format('YYYY-MM-DD'));
const serverTime = ref('');
const reservations = ref([]);
const waitlist = ref([]);
const areas = ref([]);
const tables = ref([]);
const week = ref([]);
const counts = ref({});
const settings = ref({});
const view = ref('list');
const showClosed = ref(true);
const highlight = ref(null);
const searchTerm = ref('');
const searchedFor = ref('');
const searchResults = ref(null);

const bookingOpen = ref(false);
const booking = ref({});
const avail = ref([]);
const availLoading = ref(false);
const seatOpen = ref(false);
const seatTarget = ref(null);
const seatKind = ref('booking');
const seatTables = ref([]);
const seatTable = ref(null);
const cancelOpen = ref(false);
const cancelTarget = ref(null);
const cancelReason = ref('');
const waitOpen = ref(false);
const wait = ref({});
let timer = null;
let availSeq = 0;
let dateChosen = false;

const isToday = computed(() => date.value === today.value);
const listRows = computed(() => reservations.value.filter(r => showClosed.value || ['booked', 'confirmed', 'seated'].includes(r.status)));
const unassigned = computed(() => reservations.value.filter(r => !r.table && ['booked', 'confirmed'].includes(r.status)));
const lengthOptions = computed(() => [60, 90, 120, 150, 180, 240].concat(settings.value.reservation_minutes ? [settings.value.reservation_minutes] : [])
  .filter((v, i, a) => a.indexOf(v) === i).sort((a, b) => a - b)
  .map(v => ({ value: v, label: v % 60 ? t('Rst_Hours_Minutes', { h: Math.floor(v / 60), m: v % 60 }) : t('Rst_Hours_N', { n: v / 60 }) })));
const sourceOptions = computed(() => ['phone', 'walk_in', 'online', 'other'].map(v => ({ value: v, label: sourceLabel(v) })));
const tableOptions = computed(() => avail.value.map(a => ({
  value: a.id,
  disabled: !a.free && a.id !== booking.value.table_id,
  label: `${a.name} · ${t('Rst_Seats_N', { n: a.seats })}${a.area ? ' · ' + a.area : ''}${a.clash ? ' · ' + t('Rst_Booked_For_At', { name: a.clash.name, time: a.clash.time }) : (a.in_use ? ' · ' + t('Rst_In_Use') : (!a.fits ? ' · ' + t('Rst_Too_Small') : ''))}`,
})));
const tableHint = computed(() => {
  const a = avail.value.find(x => x.id === booking.value.table_id);
  return a && !a.fits ? t('Rst_Table_Too_Small_Hint', { n: a.seats }) : '';
});

// Timeline spans the opening hours seen that day (at least 10:00–24:00).
const hours = computed(() => {
  let from = 10; let to = 24;
  reservations.value.forEach((r) => {
    from = Math.min(from, Number(r.time.slice(0, 2)));
    const end = Number(r.end_time.slice(0, 2)) + (r.end_time.slice(3) !== '00' ? 1 : 0);
    to = Math.max(to, end < Number(r.time.slice(0, 2)) ? 24 : end);
  });
  return Array.from({ length: Math.min(to, 24) - from }, (_, i) => from + i);
});
const nowPct = computed(() => {
  if (!isToday.value || !serverTime.value) return null;
  const m = toMin(serverTime.value.slice(11));
  const p = pct(m);
  return p >= 0 && p <= 100 ? p : null;
});

function toMin(hhmm) { return Number(hhmm.slice(0, 2)) * 60 + Number(hhmm.slice(3, 5)); }
function pct(min) { const start = hours.value[0] * 60; return ((min - start) / (hours.value.length * 60)) * 100; }
function blockStyle(r) {
  const s = toMin(r.time);
  let e = s + r.minutes;
  e = Math.min(e, (hours.value[0] + hours.value.length) * 60);
  return { insetInlineStart: pct(s) + '%', width: Math.max(pct(e) - pct(s), 2) + '%' };
}
function tableBookings(id) { return reservations.value.filter(r => r.table && r.table.id === id && r.status !== 'cancelled'); }
function upcoming(r) { return r.status === 'booked' || r.status === 'confirmed'; }
function isWaiting(w) { return w.status === 'waiting' || w.status === 'notified'; }
function areaName(id) { return id ? (areas.value.find(a => a.id === id) || {}).name : ''; }
function dayName(d) { return new Date(d + 'T12:00:00').toLocaleDateString(locale.value || undefined, { weekday: 'short' }); }
function statusLabel(s) { return { booked: t('Rst_Booked'), confirmed: t('Rst_Confirmed'), seated: t('Rst_Seated'), completed: t('Rst_Completed'), cancelled: t('Rst_Cancelled'), no_show: t('Rst_No_Show') }[s] || s; }
function statusColor(s) { return { booked: 'purple', confirmed: 'blue', seated: 'green', completed: 'default', cancelled: 'red', no_show: 'orange' }[s] || 'default'; }
function sourceLabel(s) { return { phone: t('Rst_Source_Phone'), walk_in: t('Rst_Source_Walk_In'), online: t('Rst_Source_Online'), other: t('Rst_Source_Other') }[s] || s; }
function whatsAppLink(w) { return `https://wa.me/${String(w.customer_phone || '').replace(/\D/g, '')}?text=${encodeURIComponent(t('Rst_Table_Ready_Message', { name: w.customer_name }))}`; }
function fail(e) {
  const errors = e?.data?.errors;
  message.error((errors && Object.values(errors)[0]?.[0]) || e?.data?.message || t('Failed'));
}

async function load() {
  loading.value = true;
  try {
    // First visit: no date, so the server's "today" (restaurant clock) is used, not the browser's.
    const r = await http.get('restaurant/reservations', dateChosen ? { date: date.value } : {});
    dateChosen = true;
    ready.value = r.ready !== false;
    if (r.ready !== false) {
      reservations.value = r.reservations;
      waitlist.value = r.waitlist;
      areas.value = r.areas;
      tables.value = r.tables.filter(x => !x.joined);
      week.value = r.week;
      counts.value = r.counts;
      settings.value = r.settings;
      today.value = r.today;
      serverTime.value = r.server_time;
      date.value = r.date;
    }
  } catch (e) { fail(e); } finally {
    loading.value = false;
    first.value = false;
  }
}
function shiftDay(n) { date.value = dayjs(date.value).add(n, 'day').format('YYYY-MM-DD'); load(); }

async function runSearch() {
  const q = searchTerm.value.trim();
  if (q.length < 2) { searchResults.value = null; return; }
  try {
    const r = await http.get('restaurant/reservations/search', { q });
    searchResults.value = r.data;
    searchedFor.value = q;
  } catch (e) { fail(e); }
}
async function goTo(r) {
  searchResults.value = null;
  date.value = r.date;
  view.value = 'list';
  await load();
  highlight.value = r.id;
  setTimeout(() => { highlight.value = null; }, 4000);
}

// ------------------------------------------------------------------ booking form
function nextSlot() {
  // The restaurant's clock (server time), not the browser's.
  const now = serverTime.value ? dayjs(serverTime.value.replace(' ', 'T')) : dayjs();
  const m = Math.ceil((now.hour() * 60 + now.minute() + 1) / 15) * 15;
  return `${String(Math.floor(m / 60) % 24).padStart(2, '0')}:${String(m % 60).padStart(2, '0')}`;
}
function openBooking(r = null, preset = {}) {
  booking.value = r
    ? { id: r.id, status: r.status, customer_name: r.customer_name, customer_phone: r.customer_phone || '', customer_email: r.customer_email || '', party_size: r.party_size, date: r.date, time: r.time, minutes: r.minutes, table_id: r.table ? r.table.id : undefined, area_id: r.area_id || undefined, note: r.note || '', source: r.source }
    : { id: null, status: 'booked', customer_name: '', customer_phone: '', customer_email: '', party_size: 2, date: date.value, time: isToday.value ? nextSlot() : '19:00', minutes: settings.value.reservation_minutes || 90, table_id: undefined, area_id: undefined, note: '', source: 'phone', ...preset };
  bookingOpen.value = true;
  loadAvail();
}
async function loadAvail() {
  const b = booking.value;
  if (!b.date || !b.time || !b.party_size) return;
  const seq = ++availSeq;
  availLoading.value = true;
  try {
    const r = await http.get('restaurant/reservations/availability', { date: b.date, time: b.time, minutes: b.minutes, party_size: b.party_size, exclude_id: b.id || '' });
    if (seq === availSeq) avail.value = r.tables;
  } catch (e) { /* the table list stays as it was */ } finally {
    if (seq === availSeq) availLoading.value = false;
  }
}
watch(() => bookingOpen.value && [booking.value.date, booking.value.time, booking.value.minutes, booking.value.party_size].join('|'), (v) => { if (v) loadAvail(); });
function clickTrack(ev, table) {
  const rect = ev.currentTarget.getBoundingClientRect();
  const rtl = getComputedStyle(ev.currentTarget).direction === 'rtl';
  const frac = rtl ? (rect.right - ev.clientX) / rect.width : (ev.clientX - rect.left) / rect.width;
  const min = hours.value[0] * 60 + Math.round((frac * hours.value.length * 60) / 15) * 15;
  const hh = String(Math.floor(min / 60) % 24).padStart(2, '0');
  const mm = String(min % 60).padStart(2, '0');
  openBooking(null, { table_id: table.id, time: `${hh}:${mm}`, party_size: Math.min(table.seats, 2) || 2 });
}
async function saveBooking() {
  const b = booking.value;
  if (!b.customer_name.trim()) { message.warning(t('Rst_Name_Required')); return; }
  busy.value = true;
  const body = { customer_name: b.customer_name, customer_phone: b.customer_phone || null, customer_email: b.customer_email || null, party_size: b.party_size, date: b.date, time: b.time, minutes: b.minutes, table_id: b.table_id || null, area_id: b.table_id ? null : (b.area_id || null), note: b.note || null, source: b.source };
  try {
    if (b.id) await http.put(`restaurant/reservations/${b.id}`, body);
    else await http.post('restaurant/reservations', body);
    message.success(t('Rst_Booking_Saved'));
    bookingOpen.value = false;
    if (b.date !== date.value) date.value = b.date;
    await load();
  } catch (e) { fail(e); } finally { busy.value = false; }
}

// ------------------------------------------------------------------ actions
async function act(r, action, data = {}) {
  busy.value = true;
  try {
    await http.post(`restaurant/reservations/${r.id}/${action}`, data);
    await load();
    return true;
  } catch (e) { fail(e); await load(); return false; } finally { busy.value = false; }
}
function openCancel(r) { cancelTarget.value = r; cancelReason.value = ''; cancelOpen.value = true; }
async function doCancel() { if (await act(cancelTarget.value, 'cancel', { reason: cancelReason.value || null })) cancelOpen.value = false; }

async function openSeat(target, kind = 'booking') {
  seatTarget.value = target;
  seatKind.value = kind;
  seatTables.value = [];
  seatTable.value = null;
  seatOpen.value = true;
  availLoading.value = true;
  try {
    const now = serverTime.value ? serverTime.value.slice(11, 16) : dayjs().format('HH:mm');
    const minutes = kind === 'booking' ? Math.max(15, target.minutes - Math.max(0, -target.minutes_to)) : (settings.value.reservation_minutes || 90);
    const r = await http.get('restaurant/reservations/availability', { date: today.value, time: now, minutes, party_size: target.party_size, exclude_id: kind === 'booking' ? target.id : '' });
    seatTables.value = r.tables;
    const own = kind === 'booking' && target.table ? r.tables.find(x => x.id === target.table.id && x.free) : null;
    seatTable.value = own ? own.id : ((r.tables.find(x => x.free && x.fits) || {}).id || null);
  } catch (e) { fail(e); } finally { availLoading.value = false; }
}
function openSeatWait(w) { openSeat(w, 'wait'); }
async function doSeat() {
  busy.value = true;
  try {
    if (seatKind.value === 'booking') await http.post(`restaurant/reservations/${seatTarget.value.id}/seat`, { table_id: seatTable.value });
    else await http.post(`restaurant/waitlist/${seatTarget.value.id}/seat`, { table_id: seatTable.value });
    message.success(t('Rst_Party_Seated'));
    seatOpen.value = false;
    await load();
  } catch (e) { fail(e); } finally { busy.value = false; }
}

// ------------------------------------------------------------------ waitlist
function openWait(w = null) {
  wait.value = w
    ? { id: w.id, customer_name: w.customer_name, customer_phone: w.customer_phone || '', party_size: w.party_size, quoted_minutes: w.quoted_minutes, area_id: w.area_id || undefined, note: w.note || '' }
    : { id: null, customer_name: '', customer_phone: '', party_size: 2, quoted_minutes: 15, area_id: undefined, note: '' };
  waitOpen.value = true;
}
async function saveWait() {
  const w = wait.value;
  if (!w.customer_name.trim()) { message.warning(t('Rst_Name_Required')); return; }
  busy.value = true;
  const body = { customer_name: w.customer_name, customer_phone: w.customer_phone || null, party_size: w.party_size, quoted_minutes: w.quoted_minutes ?? null, area_id: w.area_id || null, note: w.note || null };
  try {
    if (w.id) await http.put(`restaurant/waitlist/${w.id}`, body);
    else await http.post('restaurant/waitlist', body);
    waitOpen.value = false;
    if (!isToday.value) date.value = today.value;
    await load();
  } catch (e) { fail(e); } finally { busy.value = false; }
}
async function waitAct(w, action) {
  busy.value = true;
  try { await http.post(`restaurant/waitlist/${w.id}/${action}`); await load(); } catch (e) { fail(e); } finally { busy.value = false; }
}

function tick() { if (!document.hidden && isToday.value && !busy.value && !bookingOpen.value && !seatOpen.value && !waitOpen.value) load(); }
onMounted(async () => {
  if (typeof route.query.date === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(route.query.date)) { date.value = route.query.date; dateChosen = true; }
  await load();
  if (route.query.new === '1') openBooking(null, route.query.table ? { table_id: Number(route.query.table) } : {});
  if (route.query.wait === '1') openWait();
  timer = setInterval(tick, 30000);
});
onBeforeUnmount(() => clearInterval(timer));
</script>

<style scoped>
.rsv-page { display: flex; flex-direction: column; gap: 12px; }
.loading { display: flex; justify-content: center; padding: 96px 0; }
.muted { color: rgba(0, 0, 0, 0.55); }
.small { font-size: 12px; }
.num { font-variant-numeric: tabular-nums; }
.block { margin-bottom: 0; }
.daybar { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.week { display: flex; gap: 6px; overflow-x: auto; margin-inline-start: 8px; }
.wd { min-width: 74px; padding: 6px 8px; border-radius: 10px; border: 1px solid rgba(5, 5, 5, 0.1); background: #fff; display: flex; flex-direction: column; align-items: center; cursor: pointer; font: inherit; color: inherit; }
.wd.on { border-color: var(--ant-color-primary, #6d28d9); background: #f5f3ff; }
.wd:focus-visible { outline: 2px solid var(--ant-color-primary, #6d28d9); }
.wd-name { font-size: 11px; text-transform: uppercase; color: rgba(0, 0, 0, 0.55); }
.wd-num { font-size: 18px; font-weight: 700; line-height: 1.2; }
.wd-count { font-size: 11px; color: rgba(0, 0, 0, 0.55); white-space: nowrap; }
.sr { padding: 8px 4px; border-bottom: 1px dashed rgba(5, 5, 5, 0.08); cursor: pointer; }
.sr:hover { background: #f5f3ff; }
.stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 8px; }
.stat { background: #fff; border: 1px solid rgba(5, 5, 5, 0.06); border-radius: 10px; padding: 10px 12px; display: flex; flex-direction: column; }
.stat span { font-size: 12px; color: rgba(0, 0, 0, 0.55); }
.stat strong { font-size: 22px; font-variant-numeric: tabular-nums; }
.cols { display: flex; gap: 12px; align-items: flex-start; flex-wrap: wrap; }
.main { flex: 1 1 560px; min-width: 0; }
.side { flex: 0 1 340px; width: 340px; }
.bk { display: flex; gap: 12px; padding: 10px 6px; border-bottom: 1px dashed rgba(5, 5, 5, 0.08); border-radius: 8px; transition: background 0.3s; }
.bk.hl { background: #fef9c3; }
.bk.bs-cancelled, .bk.bs-no_show, .bk.bs-completed { opacity: 0.6; }
.bk.late { box-shadow: inset 3px 0 0 #dc2626; }
.bk.soon { box-shadow: inset 3px 0 0 #f59e0b; }
.bk-time { min-width: 52px; display: flex; flex-direction: column; }
.bk-time strong { font-size: 16px; }
.bk-body { flex: 1; min-width: 0; }
.bk-name { font-weight: 600; }
.bk-side { display: flex; flex-direction: column; align-items: flex-end; gap: 6px; }
.bk-actions { display: flex; gap: 4px; flex-wrap: wrap; justify-content: flex-end; }
.note { display: inline-block; margin-top: 3px; font-size: 12px; color: #92400e; background: #fffbeb; border-radius: 6px; padding: 1px 6px; }
.flag { font-size: 11px; font-weight: 700; padding: 1px 7px; border-radius: 999px; white-space: nowrap; }
.flag-late { background: #fee2e2; color: #991b1b; }
.flag-soon { background: #fef3c7; color: #92400e; }

.tl { overflow-x: auto; }
.tl-head, .tl-row { display: flex; min-width: calc(110px + var(--hours) * 64px); }
.tl-label { width: 110px; flex: none; padding: 6px 8px 6px 0; }
.tl-track { position: relative; flex: 1; min-height: 40px; border-bottom: 1px solid rgba(5, 5, 5, 0.06); background-image: linear-gradient(to right, rgba(5, 5, 5, 0.06) 1px, transparent 1px); background-size: calc(100% / var(--hours)) 100%; cursor: copy; }
.tl-head .tl-track { min-height: 22px; background: none; border: 0; cursor: default; }
.tl-hour { position: absolute; top: 2px; font-size: 11px; color: rgba(0, 0, 0, 0.55); transform: translateX(-50%); }
.tl-now { position: absolute; top: 0; bottom: 0; width: 2px; background: #dc2626; z-index: 2; pointer-events: none; }
.tl-block { position: absolute; top: 4px; bottom: 4px; border-radius: 6px; border: 0; padding: 0 6px; font: inherit; font-size: 12px; text-align: start; overflow: hidden; white-space: nowrap; cursor: pointer; display: flex; flex-direction: column; justify-content: center; background: #ede9fe; color: #4c1d95; z-index: 1; }
.tl-block:focus-visible { outline: 2px solid var(--ant-color-primary, #6d28d9); }
.tl-block.bs-confirmed { background: #dbeafe; color: #1e3a8a; }
.tl-block.bs-seated { background: #dcfce7; color: #14532d; }
.tl-block.bs-completed, .tl-block.bs-no_show { background: #f1f1f6; color: #54546a; }
.tl-block.late { outline: 2px solid #dc2626; }
.tl-b-name { font-weight: 700; overflow: hidden; text-overflow: ellipsis; }
.tl-b-meta { font-size: 11px; opacity: 0.8; }

.wl { padding: 10px 0; border-bottom: 1px dashed rgba(5, 5, 5, 0.08); }
.wl.ws-seated, .wl.ws-left { opacity: 0.6; }
.wl-top { display: flex; gap: 8px; align-items: flex-start; }
.wl-pos { min-width: 18px; font-weight: 700; color: var(--ant-color-primary, #6d28d9); }
.wl-body { flex: 1; min-width: 0; }
.wl-actions { display: flex; gap: 4px; flex-wrap: wrap; margin-top: 6px; padding-inline-start: 26px; }
.seat-opts { display: flex; flex-direction: column; gap: 6px; width: 100%; }
.seat-opt { margin: 0; min-height: 34px; display: flex; align-items: center; }
@media (max-width: 900px) {
  .side { width: 100%; flex-basis: 100%; }
}
</style>
