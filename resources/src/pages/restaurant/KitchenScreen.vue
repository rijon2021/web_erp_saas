<template>
  <div ref="root" class="kds">
    <header class="kds-head">
      <div class="kds-title">
        <h1>{{ $t('Rst_Kitchen') }}</h1>
        <span class="dim">{{ $t('Rst_Kds_Open_N', { n: openTickets.length }) }} · {{ $t('Rst_Kds_Late_After', { n: lateMinutes }) }}</span>
      </div>
      <div class="stations" role="tablist" :aria-label="$t('Rst_Stations')">
        <button v-for="s in stationTabs" :key="s.id" type="button" role="tab" :aria-selected="stationId === s.id" :class="['st-btn', { on: stationId === s.id }]" @click="setStation(s.id)">
          {{ s.name }} <span class="dim">{{ s.count }}</span>
        </button>
      </div>
      <div class="tools">
        <button type="button" class="tool" :aria-pressed="sound" @click="toggleSound">{{ sound ? $t('Rst_Kds_Sound_On') : $t('Rst_Kds_Sound_Off') }}</button>
        <button type="button" class="tool" @click="fullscreen">{{ $t('Rst_Kds_Fullscreen') }}</button>
        <button type="button" class="tool" @click="load(true)">{{ $t('Rst_Refresh') }}</button>
      </div>
    </header>

    <a-alert v-if="!ready" type="warning" show-icon :message="$t('Rst_Not_Ready')" />
    <div v-else-if="!openTickets.length && !readyTickets.length" class="empty">{{ $t('Rst_Kds_All_Clear') }}</div>

    <section v-if="openTickets.length" class="grid">
      <article v-for="tk in openTickets" :key="tk.id" :class="['card', 'u-' + urgency(tk)]">
        <header class="card-head">
          <div>
            <div class="card-title">{{ title(tk) }}</div>
            <div class="card-sub">{{ tk.ref }}<span v-if="tk.order.waiter"> · {{ tk.order.waiter }}</span><span v-if="tk.order.guests"> · {{ $t('Rst_Guests_N', { n: tk.order.guests }) }}</span><span v-if="tk.course"> · {{ $t('Rst_Course_N', { n: tk.course }) }}</span></div>
          </div>
          <div class="timer">
            <div class="num big">{{ clock(tk) }}</div>
            <div class="urg">{{ urgencyLabel(urgency(tk)) }}</div>
          </div>
        </header>
        <div v-if="tk.order.note" class="order-note">{{ tk.order.note }}</div>
        <ul class="items">
          <li v-for="it in tk.items" :key="it.id">
            <button type="button" :class="['item', { done: it.status === 'ready' || it.status === 'served' }]" :aria-pressed="it.status === 'ready' || it.status === 'served'" :disabled="busy[tk.id]" @click="toggle(tk, it)">
              <span class="box" aria-hidden="true" />
              <span class="item-body">
                <span class="item-name"><span class="num">{{ fmtQty(it.qty) }}×</span> {{ it.name }}</span>
                <span v-if="it.addons_label" class="item-mods">{{ it.addons_label }}</span>
                <span v-if="it.note" class="item-note">{{ it.note }}</span>
              </span>
              <span class="item-meta">{{ [it.seat ? $t('Rst_Seat_N', { n: it.seat }) : '', stationId === 'all' ? it.station : ''].filter(Boolean).join(' · ') }}</span>
            </button>
          </li>
        </ul>
        <footer class="card-foot">
          <button v-if="tk.status === 'pending'" type="button" class="btn ghost" :disabled="busy[tk.id]" @click="act(tk, 'start')">{{ $t('Rst_Kds_Start') }}</button>
          <button type="button" class="btn ghost" @click="print(tk)">{{ $t('Rst_Kds_Print') }}</button>
          <button type="button" class="btn go" :disabled="busy[tk.id]" @click="act(tk, 'bump')">{{ stationId === 'all' ? $t('Rst_Kds_Bump') : $t('Rst_Kds_Done_At', { station: stationName }) }}</button>
        </footer>
      </article>
    </section>

    <section v-if="readyTickets.length" class="ready-strip">
      <h2>{{ $t('Rst_Kds_Waiting_Pickup') }}</h2>
      <div class="ready-row">
        <div v-for="tk in readyTickets" :key="'r' + tk.id" :class="['ready-card', { served: tk.status === 'served' }]">
          <div><strong>{{ title(tk) }}</strong> <span class="dim">{{ tk.ref }}</span></div>
          <div class="dim small">{{ tk.status === 'served' ? $t('Rst_Kds_Served_Ago', { n: tk.served_minutes ?? tk.ready_minutes ?? 0 }) : $t('Rst_Kds_Ready_For', { n: tk.ready_minutes || 0 }) }}</div>
          <div class="ready-actions">
            <button v-if="tk.status === 'ready'" type="button" class="btn go sm" :disabled="busy[tk.id]" @click="act(tk, 'served')">{{ $t('Rst_Kds_Served') }}</button>
            <button type="button" class="btn ghost sm" :disabled="busy[tk.id]" @click="act(tk, 'recall')">{{ $t('Rst_Kds_Recall') }}</button>
          </div>
        </div>
      </div>
    </section>
  </div>
</template>

<script setup>
/**
 * Restaurant › Kitchen display (rst_kitchen) — GET restaurant/kitchen?station_id (polled every 8 s);
 * POST restaurant/kitchen/tickets/{id}/{start|bump|served|recall} {station_id?};
 * POST restaurant/kitchen/items/{id}/toggle {done}. Timers run locally between polls.
 */
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import http from '../../lib/http';

const { t } = useI18n();
const POLL_MS = 8000;
const STATION_KEY = 'rst.kds.station';
const SOUND_KEY = 'rst.kds.sound';

const root = ref(null);
const ready = ref(true);
const tickets = ref([]);
const stations = ref([]);
const counts = ref({});
const lateMinutes = ref(20);
const stationId = ref(readStore(STATION_KEY, 'all'));
const sound = ref(readStore(SOUND_KEY, '1') === '1');
const busy = ref({});
const now = ref(Date.now());
let offset = 0; // server clock − browser clock
let seen = null;
let pollTimer = null;
let clockTimer = null;
let audio = null;

function readStore(key, fallback) { try { return localStorage.getItem(key) ?? fallback; } catch (e) { return fallback; } }
function writeStore(key, v) { try { localStorage.setItem(key, v); } catch (e) { /* private mode */ } }

const stationTabs = computed(() => [{ id: 'all', name: t('Rst_Kds_All_Stations'), count: counts.value.all || 0 }]
  .concat(stations.value.map(s => ({ id: s.id, name: s.name, count: counts.value[String(s.id)] || 0 }))));
const stationName = computed(() => (stations.value.find(s => s.id === stationId.value) || {}).name || '');
const openTickets = computed(() => tickets.value.filter(tk => tk.status === 'pending' || tk.status === 'preparing'));
const readyTickets = computed(() => tickets.value.filter(tk => tk.status === 'ready' || tk.status === 'served'));

function title(tk) {
  // Orders from the online store / a table's QR code say so (phase 8).
  const via = { online: ' · ' + t('Rst_Via_Online'), qr: ' · ' + t('Rst_Via_Qr') }[tk.order.source] || '';
  if (tk.order.table) return t('Rst_Table_N', { name: tk.order.table }) + via;
  const kind = { takeaway: t('Rst_Takeaway'), delivery: t('Rst_Delivery'), dine_in: t('Rst_Dine_In') }[tk.order.type] || '';
  return (tk.order.customer_name ? `${kind} · ${tk.order.customer_name}` : kind) + via;
}
function ageMs(tk) { return tk.sent_at ? Math.max(0, now.value + offset - Date.parse(tk.sent_at)) : 0; }
function clock(tk) { const s = Math.floor(ageMs(tk) / 1000); return `${String(Math.floor(s / 60)).padStart(2, '0')}:${String(s % 60).padStart(2, '0')}`; }
function urgency(tk) {
  const min = ageMs(tk) / 60000;
  return min >= lateMinutes.value ? 'late' : (min >= Math.floor(lateMinutes.value / 2) ? 'soon' : 'ok');
}
function urgencyLabel(u) { return { ok: t('Rst_Kds_On_Time'), soon: t('Rst_Kds_Getting_Late'), late: t('Rst_Kds_Late') }[u]; }
function fmtQty(q) { return Number.isInteger(q) ? q : Number(q).toFixed(2); }

async function load(manual = false) {
  try {
    const r = await http.get('restaurant/kitchen', stationId.value === 'all' ? {} : { station_id: stationId.value });
    ready.value = true;
    if (r.server_time) offset = Date.parse(r.server_time) - Date.now();
    lateMinutes.value = r.late_minutes || 20;
    stations.value = r.stations || [];
    counts.value = r.counts || {};
    if (stationId.value !== 'all' && !stations.value.some(s => s.id === stationId.value)) setStation('all');
    const fresh = (r.tickets || []).filter(tk => tk.status === 'pending').map(tk => tk.id);
    if (seen && sound.value && fresh.some(id => !seen.has(id))) beep();
    seen = new Set((r.tickets || []).map(tk => tk.id));
    tickets.value = r.tickets || [];
  } catch (e) {
    if (e?.status === 503) ready.value = false;
    else if (manual) message.error(e?.data?.message || t('Failed'));
  }
}
function setStation(id) { stationId.value = id; writeStore(STATION_KEY, String(id)); seen = null; load(); }

async function act(tk, action) {
  busy.value = { ...busy.value, [tk.id]: true };
  try {
    await http.post(`restaurant/kitchen/tickets/${tk.id}/${action}`, action === 'bump' && stationId.value !== 'all' ? { station_id: stationId.value } : {});
    await load();
  } catch (e) {
    message.error(e?.data?.message || t('Failed'));
    await load();
  } finally {
    busy.value = { ...busy.value, [tk.id]: false };
  }
}
async function toggle(tk, it) {
  const done = !(it.status === 'ready' || it.status === 'served');
  it.status = done ? 'ready' : 'preparing'; // optimistic
  busy.value = { ...busy.value, [tk.id]: true };
  try {
    await http.post(`restaurant/kitchen/items/${it.id}/toggle`, { done });
    await load();
  } catch (e) {
    message.error(e?.data?.message || t('Failed'));
    await load();
  } finally {
    busy.value = { ...busy.value, [tk.id]: false };
  }
}

/** 80 mm kitchen ticket through a hidden iframe (no pop-up blocker). */
function print(tk) {
  const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  const lines = tk.items.map(it => `<div class="l"><b>${esc(fmtQty(it.qty))}× ${esc(it.name)}</b>${it.addons_label ? `<div>${esc(it.addons_label)}</div>` : ''}${it.note ? `<div class="n">** ${esc(it.note)} **</div>` : ''}${it.seat ? `<div>${esc(t('Rst_Seat_N', { n: it.seat }))}</div>` : ''}</div>`).join('');
  const html = `<!doctype html><html><head><meta charset="utf-8"><title>${esc(tk.ref)}</title><style>
    @page { size: 80mm auto; margin: 3mm; } body { font: 13px/1.35 monospace; margin: 0; width: 74mm; }
    h1 { font-size: 20px; margin: 0 0 4px; } .l { padding: 6px 0; border-bottom: 1px dashed #000; } .n { font-weight: bold; } .m { font-size: 11px; }
    </style></head><body><h1>${esc(title(tk))}</h1><div class="m">${esc(tk.ref)} · ${esc(new Date(tk.sent_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }))}${tk.order.waiter ? ' · ' + esc(tk.order.waiter) : ''}${tk.course ? ' · ' + esc(t('Rst_Course_N', { n: tk.course })) : ''}</div>
    ${tk.order.note ? `<div class="n">${esc(tk.order.note)}</div>` : ''}${lines}</body></html>`;
  const frame = document.createElement('iframe');
  frame.setAttribute('aria-hidden', 'true');
  frame.style.cssText = 'position:fixed;width:0;height:0;border:0;right:0;bottom:0';
  document.body.appendChild(frame);
  frame.contentDocument.open();
  frame.contentDocument.write(html);
  frame.contentDocument.close();
  setTimeout(() => { frame.contentWindow.focus(); frame.contentWindow.print(); setTimeout(() => frame.remove(), 1000); }, 50);
}

function beep() {
  try {
    audio = audio || new (window.AudioContext || window.webkitAudioContext)();
    const o = audio.createOscillator();
    const g = audio.createGain();
    o.frequency.value = 880;
    g.gain.setValueAtTime(0.2, audio.currentTime);
    g.gain.exponentialRampToValueAtTime(0.001, audio.currentTime + 0.4);
    o.connect(g).connect(audio.destination);
    o.start();
    o.stop(audio.currentTime + 0.4);
  } catch (e) { /* audio blocked until the first click */ }
}
function toggleSound() { sound.value = !sound.value; writeStore(SOUND_KEY, sound.value ? '1' : '0'); if (sound.value) beep(); }
function fullscreen() {
  const el = root.value;
  if (document.fullscreenElement) document.exitFullscreen?.();
  else el?.requestFullscreen?.();
}

onMounted(() => {
  if (stationId.value !== 'all') stationId.value = Number(stationId.value) || 'all';
  load();
  pollTimer = setInterval(() => { if (!document.hidden) load(); }, POLL_MS);
  clockTimer = setInterval(() => { now.value = Date.now(); }, 1000);
});
onBeforeUnmount(() => { clearInterval(pollTimer); clearInterval(clockTimer); });
</script>

<style scoped>
.kds { background: #0f1115; color: #e7e9ee; min-height: calc(100vh - 120px); border-radius: 12px; padding: 16px; display: flex; flex-direction: column; gap: 14px; }
.kds:fullscreen { border-radius: 0; min-height: 100vh; overflow-y: auto; }
.dim { color: #9aa1ad; }
.small { font-size: 12px; }
.num { font-variant-numeric: tabular-nums; }
.kds-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
.kds-title { display: flex; align-items: baseline; gap: 12px; flex-wrap: wrap; }
.kds-title h1 { margin: 0; font-size: 22px; font-weight: 800; color: #e7e9ee; }
.stations, .tools { display: flex; gap: 6px; flex-wrap: wrap; }
.st-btn, .tool { min-height: 40px; padding: 0 14px; border-radius: 10px; border: 1px solid #2b2f38; background: #171a21; color: #e7e9ee; font: 700 14px inherit; font-family: inherit; cursor: pointer; }
.st-btn.on { background: #e7e9ee; color: #0f1115; border-color: #e7e9ee; }
.st-btn.on .dim { color: #3b3f48; }
.tool { font-weight: 600; }
.empty { padding: 80px 0; text-align: center; font-size: 20px; color: #9aa1ad; }
.grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 14px; align-items: start; }
.card { background: #171a21; border: 1px solid #2b2f38; border-radius: 14px; overflow: hidden; display: flex; flex-direction: column; }
.card-head { padding: 12px 14px; display: flex; justify-content: space-between; align-items: center; gap: 10px; }
.u-ok .card-head { background: #14532d; color: #dcfce7; }
.u-soon .card-head { background: #78350f; color: #fef3c7; }
.u-late .card-head { background: #7f1d1d; color: #fee2e2; }
.card-title { font-size: 18px; font-weight: 800; }
.card-sub { font-size: 12px; opacity: 0.9; }
.timer { text-align: end; }
.big { font-size: 22px; font-weight: 800; }
.urg { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; }
.order-note { margin: 10px 14px 0; padding: 6px 8px; border-radius: 8px; background: #fcd34d; color: #1a1a1a; font-weight: 700; font-size: 13px; }
.items { list-style: none; margin: 0; padding: 4px 14px; }
.item { width: 100%; display: flex; gap: 12px; align-items: flex-start; padding: 10px 0; border: 0; border-bottom: 1px solid #2b2f38; background: transparent; color: #e7e9ee; font: inherit; cursor: pointer; text-align: start; }
.item:focus-visible { outline: 2px solid #e7e9ee; outline-offset: 2px; }
.item.done { opacity: 0.45; }
.item.done .item-name { text-decoration: line-through; }
.box { width: 22px; height: 22px; flex: none; margin-top: 2px; border-radius: 6px; border: 2px solid #5b6170; }
.item.done .box { background: #22c55e; border-color: #22c55e; }
.item-body { flex: 1; display: flex; flex-direction: column; gap: 2px; }
.item-name { font-size: 16px; font-weight: 700; }
.item-mods { font-size: 13px; color: #b8bec9; }
.item-note { align-self: flex-start; font-size: 13px; font-weight: 700; color: #1a1a1a; background: #fcd34d; border-radius: 6px; padding: 1px 6px; }
.item-meta { font-size: 11px; color: #9aa1ad; white-space: nowrap; }
.card-foot { padding: 10px 14px 14px; display: flex; gap: 8px; }
.btn { flex: 1; min-height: 44px; border-radius: 10px; font: 700 14px inherit; font-family: inherit; cursor: pointer; }
.btn.sm { min-height: 36px; font-size: 13px; }
.btn.ghost { border: 1px solid #2b2f38; background: #1f232c; color: #e7e9ee; }
.btn.go { flex: 2; border: 0; background: #22c55e; color: #052e16; font-weight: 800; }
.btn:disabled { opacity: 0.6; cursor: wait; }
.ready-strip h2 { margin: 0 0 8px; font-size: 15px; color: #9aa1ad; text-transform: uppercase; letter-spacing: 0.06em; }
.ready-row { display: flex; gap: 10px; flex-wrap: wrap; }
.ready-card { background: #13261b; border: 1px solid #1f5131; border-radius: 12px; padding: 10px 12px; min-width: 220px; display: flex; flex-direction: column; gap: 6px; }
.ready-card.served { background: #171a21; border-color: #2b2f38; opacity: 0.75; }
.ready-actions { display: flex; gap: 6px; }
</style>
