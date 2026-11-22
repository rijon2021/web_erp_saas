<template>
  <div class="bill-screen">
    <div v-if="loading" class="loading"><a-spin size="large" /></div>
    <a-result v-else-if="loadError" status="warning" :title="loadError">
      <template #extra><a-button @click="$router.push('/restaurant/floor')">{{ $t('Rst_Back_To_Floor') }}</a-button></template>
    </a-result>

    <template v-else-if="order">
      <!-- What is being paid -->
      <section class="panel left">
        <div class="head">
          <a-button type="link" class="back" @click="backToOrder"><LeftOutlined /> {{ $t('Rst_Order') }}</a-button>
          <div>
            <div class="title">{{ order.table ? $t('Rst_Bill_For_Table', { name: order.table.name }) : $t('Rst_Bill_For_Order', { ref: order.ref }) }}</div>
            <div class="muted small">{{ order.ref }}<span v-if="order.guests"> · {{ $t('Rst_Guests_N', { n: order.guests }) }}</span><span v-if="order.customer_name"> · {{ order.customer_name }}</span></div>
          </div>
          <a-tag v-if="order.status !== 'open'" :color="order.status === 'paid' ? 'green' : 'red'">{{ order.status === 'paid' ? $t('Rst_Paid') : $t('Rst_Cancelled') }}</a-tag>
        </div>

        <template v-if="order.status === 'open' && order.unpaid_count">
          <a-segmented v-model:value="split" block :options="splitOptions" class="split" />

          <div v-if="split === 'equal'" class="equal">
            <span>{{ $t('Rst_Split_Between') }}</span>
            <a-input-number v-model:value="shares" :min="2" :max="20" />
            <span class="muted">{{ $t('Rst_People') }}</span>
          </div>
          <div v-if="split === 'items' && seats.length" class="seat-picks">
            <span class="muted small">{{ $t('Rst_Select_Seat') }}</span>
            <a-button v-for="s in seats" :key="s" size="small" @click="selectSeat(s)">{{ $t('Rst_Seat_N', { n: s }) }}</a-button>
            <a-button size="small" type="link" @click="picked = []">{{ $t('Rst_Clear_Selection') }}</a-button>
          </div>
        </template>

        <div class="lines">
          <label v-for="it in order.items" :key="it.id" :class="['line', { paid: it.paid, off: split === 'items' && !it.paid && !picked.includes(it.id) }]">
            <a-checkbox v-if="split === 'items' && !it.paid && order.status === 'open'" :checked="picked.includes(it.id)" @change="togglePick(it.id)" />
            <span class="num qty">{{ fmtQty(it.qty) }}×</span>
            <span class="line-body">
              <span class="line-name">{{ it.name }}</span>
              <span v-if="it.addons_label" class="muted small block">{{ it.addons_label }}</span>
              <span v-if="it.seat" class="muted small block">{{ $t('Rst_Seat_N', { n: it.seat }) }}</span>
            </span>
            <span class="line-right">
              <span class="num">{{ moneyBase(it.line_total) }}</span>
              <span v-if="it.paid" class="st st-paid">{{ $t('Rst_Paid') }}</span>
            </span>
          </label>
        </div>

        <div v-if="history.length" class="history">
          <div class="sub">{{ $t('Rst_Payments_Taken') }}</div>
          <div v-for="p in history" :key="p.id" class="hist-row">
            <span>{{ p.sale_ref || ('#' + p.sale_id) }} <span class="muted small">· {{ splitLabel(p.split) }}{{ p.shares ? ' ×' + p.shares : '' }}</span></span>
            <span class="num">{{ moneyBase(p.amount) }}</span>
          </div>
        </div>
      </section>

      <!-- Totals + payment -->
      <section v-if="order.status === 'open' && order.unpaid_count" class="panel right">
        <div v-if="meta.settings.tips_enabled" class="block">
          <div class="sub">{{ $t('Rst_Tip') }}</div>
          <div class="chips">
            <button type="button" :class="['chip', { on: tip.type === 'amount' && !tip.value }]" @click="tip = { type: 'amount', value: 0 }">{{ $t('Rst_No_Tip') }}</button>
            <button v-for="p in meta.settings.tip_presets" :key="p" type="button" :class="['chip', { on: tip.type === 'percent' && tip.value === p }]" @click="tip = { type: 'percent', value: p }">{{ p }}%</button>
            <a-input-number :value="tip.type === 'amount' && tip.value ? tip.value : null" :min="0" :placeholder="$t('Rst_Tip_Amount')" style="width: 130px" @change="v => tip = { type: 'amount', value: Number(v) || 0 }" />
          </div>
        </div>

        <div v-if="meta.can_discount" class="block">
          <div class="sub">{{ $t('Discount') }} <span class="muted small">· {{ $t('Rst_Discount_Max', { n: meta.settings.max_discount_percent }) }}</span></div>
          <a-input-group compact>
            <a-select v-model:value="discount.type" style="width: 90px" :options="[{ value: 'amount', label: $t('Amount') }, { value: 'percent', label: '%' }]" />
            <a-input-number v-model:value="discount.value" :min="0" style="width: 150px" />
          </a-input-group>
        </div>

        <div class="block">
          <div class="sub">{{ $t('Customer') }}</div>
          <a-select
            v-model:value="clientId" show-search :filter-option="false" :options="clientOptions" style="width: 100%"
            :placeholder="meta.default_client ? meta.default_client.name : $t('Customer')" @search="searchClients" @focus="() => clientOptions.length || searchClients('')"
          />
        </div>

        <div class="totals">
          <a-spin :spinning="quoting">
            <template v-if="quote">
              <div class="row"><span>{{ $t('Rst_Dishes') }}</span><span class="num">{{ moneyBase(quote.items_total) }}</span></div>
              <div v-if="quote.tax_total" class="row muted small"><span>{{ $t('Rst_Including_Tax') }}</span><span class="num">{{ moneyBase(quote.tax_total) }}</span></div>
              <div v-if="quote.service_charge" class="row"><span>{{ $t('Rst_Service_Charge_Pct', { n: quote.service_percent }) }}</span><span class="num">{{ moneyBase(quote.service_charge) }}</span></div>
              <div v-if="quote.discount" class="row"><span>{{ $t('Discount') }}</span><span class="num">−{{ moneyBase(quote.discount) }}</span></div>
              <div v-if="quote.tip" class="row"><span>{{ $t('Rst_Tip') }}</span><span class="num">{{ moneyBase(quote.tip) }}</span></div>
              <div v-if="quote.rounding" class="row muted small"><span>{{ $t('Rst_Rounding') }}</span><span class="num">−{{ moneyBase(quote.rounding) }}</span></div>
              <div class="row grand"><span>{{ $t('Rst_To_Pay') }}</span><span class="num">{{ moneyBase(quote.grand_total) }}</span></div>
              <div v-if="split === 'equal'" class="row muted small"><span>{{ $t('Rst_Each_Pays') }}</span><span class="num">{{ quote.share_amounts.map(a => moneyBase(a)).join(' / ') }}</span></div>
            </template>
            <a-alert v-else-if="quoteError" type="warning" show-icon :message="quoteError" />
          </a-spin>
        </div>

        <div v-if="quote" class="block">
          <div class="sub">{{ $t('Rst_Payment') }}</div>
          <div v-for="(p, i) in payments" :key="i" class="pay-row">
            <span v-if="split === 'equal'" class="muted small share-n">{{ i + 1 }}</span>
            <a-select v-model:value="p.payment_method_id" :options="methodOptions" style="flex: 1; min-width: 120px" />
            <a-select v-if="meta.accounts.length" v-model:value="p.account_id" allow-clear :placeholder="$t('Account')" :options="accountOptions" style="width: 140px" />
            <a-input-number v-model:value="p.amount" :min="0" style="width: 120px" @change="amountsTouched = true" />
            <a-button v-if="payments.length > 1 && split !== 'equal'" type="text" :aria-label="$t('Delete')" @click="payments.splice(i, 1)"><DeleteOutlined /></a-button>
          </div>
          <a-button v-if="split !== 'equal' && payments.length < 6" size="small" type="dashed" @click="addPaymentLine"><PlusOutlined /> {{ $t('Rst_Add_Payment_Line') }}</a-button>
          <div class="row received"><span>{{ $t('Rst_Received') }}</span><span class="num">{{ moneyBase(received) }}</span></div>
          <div v-if="received > quote.grand_total + 0.004" class="row change"><span>{{ $t('Change') }}</span><span class="num">{{ moneyBase(received - quote.grand_total) }}</span></div>
          <div v-else-if="received + 0.004 < quote.grand_total" class="row short"><span>{{ $t('Rst_Still_Due') }}</span><span class="num">{{ moneyBase(quote.grand_total - received) }}</span></div>
          <div v-if="overpayProblem" class="row short" role="alert"><span>{{ overpayProblem }}</span></div>
        </div>

        <div class="actions">
          <a-button size="large" :disabled="!quote" @click="printBill"><PrinterOutlined /> {{ $t('Rst_Print_Bill') }}</a-button>
          <a-button type="primary" size="large" :disabled="!canPay" :loading="paying" @click="pay">
            {{ quote ? $t('Rst_Take_Payment_Amount', { amount: moneyBase(quote.grand_total) }) : $t('Rst_Take_Payment') }}
          </a-button>
        </div>
      </section>

      <section v-else class="panel right done">
        <a-result status="success" :title="order.status === 'paid' ? $t('Rst_Order_Fully_Paid') : $t('Rst_Cancelled')">
          <template #extra>
            <a-button type="primary" @click="$router.push(order.table ? '/restaurant/floor' : '/restaurant/orders')">{{ order.table ? $t('Rst_Back_To_Floor') : $t('Rst_Orders') }}</a-button>
          </template>
        </a-result>
      </section>
    </template>

    <!-- Paid -->
    <a-modal v-model:open="doneOpen" :title="$t('Rst_Payment_Taken')" :closable="false" :mask-closable="false" :footer="null" width="420px">
      <div v-if="done" class="done-box">
        <div class="muted">{{ $t('Rst_Sale_Ref', { ref: done.sale_ref || ('#' + done.sale_id) }) }}</div>
        <div class="done-amount num">{{ moneyBase(done.grand_total) }}</div>
        <div v-if="done.change > 0" class="done-change">{{ $t('Rst_Give_Change', { amount: moneyBase(done.change) }) }}</div>
        <div class="done-actions">
          <a-button block size="large" @click="printReceipt"><PrinterOutlined /> {{ $t('Rst_Print_Receipt') }}</a-button>
          <a-button v-if="!done.closed" block size="large" type="primary" @click="nextPayment">{{ $t('Rst_Next_Payment') }}</a-button>
          <a-button v-else block size="large" type="primary" @click="finish">{{ order && order.table ? $t('Rst_Back_To_Floor') : $t('Rst_Orders') }}</a-button>
        </div>
      </div>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * Restaurant › bill & payment (route /restaurant/order/:id/bill, permission rst_billing).
 * GET restaurant/billing/meta, GET restaurant/orders/{id}/bill, POST .../bill/quote, POST .../bill/pay.
 * Every payment becomes a normal POS sale. Split: whole bill, equally (one sale, one payment
 * line per person) or by item (one sale per guest's dishes).
 */
import { ref, computed, watch, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { LeftOutlined, DeleteOutlined, PlusOutlined, PrinterOutlined } from '@ant-design/icons-vue';
import http from '../../lib/http';
import { useFormat } from '../../composables/useFormat';

const { t } = useI18n();
const route = useRoute();
const router = useRouter();
const { moneyBase } = useFormat();

const loading = ref(true);
const loadError = ref('');
const meta = ref({ payment_methods: [], accounts: [], settings: { tip_presets: [] }, company: {} });
const order = ref(null);
const history = ref([]);
const split = ref('full');
const shares = ref(2);
const picked = ref([]);
const tip = ref({ type: 'amount', value: 0 });
const discount = ref({ type: 'amount', value: 0 });
const clientId = ref(undefined);
const clientOptions = ref([]);
const quote = ref(null);
const quoteError = ref('');
const quoting = ref(false);
const payments = ref([]);
const amountsTouched = ref(false);
const paying = ref(false);
const done = ref(null);
const doneOpen = ref(false);
let saleUuid = null;
let quoteSeq = 0;
let quoteTimer = null;
let clientTimer = null;

const splitOptions = computed(() => [
  { value: 'full', label: t('Rst_Split_Full') },
  { value: 'equal', label: t('Rst_Split_Equal') },
  { value: 'items', label: t('Rst_Split_Items') },
]);
const unpaidItems = computed(() => (order.value?.items || []).filter(i => !i.paid));
const seats = computed(() => [...new Set(unpaidItems.value.map(i => i.seat).filter(Boolean))].sort((a, b) => a - b));
const methodOptions = computed(() => meta.value.payment_methods.map(m => ({ value: m.id, label: m.name })));
const accountOptions = computed(() => meta.value.accounts.map(a => ({ value: a.id, label: a.name })));
const cashMethodId = computed(() => (meta.value.payment_methods.find(m => m.is_cash) || meta.value.payment_methods[0] || {}).id);
const received = computed(() => Math.round(payments.value.reduce((s, p) => s + (Number(p.amount) || 0), 0) * 100) / 100);
// Change can only come back from a cash line big enough (as the POS checks).
const overpayProblem = computed(() => {
  if (!quote.value) return '';
  const over = Math.round((received.value - quote.value.grand_total) * 100) / 100;
  if (over <= 0.004 || payments.value.length === 1) return '';
  const cash = new Set(meta.value.payment_methods.filter(m => m.is_cash).map(m => m.id));
  return payments.value.some(p => cash.has(p.payment_method_id) && (Number(p.amount) || 0) >= over) ? '' : t('Rst_Change_Needs_Cash');
});
const canPay = computed(() => {
  if (!quote.value || paying.value || quoting.value) return false;
  if (payments.value.some(p => !p.payment_method_id)) return false;
  if (meta.value.require_account && payments.value.some(p => !p.account_id)) return false;
  if (overpayProblem.value) return false;
  return received.value + 0.004 >= quote.value.grand_total;
});

function fmtQty(q) { return Number.isInteger(q) ? q : Number(q).toFixed(2); }
function splitLabel(s) { return { full: t('Rst_Split_Full'), equal: t('Rst_Split_Equal'), items: t('Rst_Split_Items') }[s] || s; }
function fail(e) {
  const errors = e?.data?.errors;
  message.error((errors && Object.values(errors)[0]?.[0]) || e?.data?.message || t('Failed'));
}
function uuid() {
  if (window.crypto?.randomUUID) return window.crypto.randomUUID();
  return 'rst-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 12);
}
function backToOrder() { router.push(`/restaurant/order/${route.params.id}`); }
function finish() { doneOpen.value = false; router.push(order.value?.table ? '/restaurant/floor' : '/restaurant/orders'); }

function options() {
  const o = {
    tip_type: tip.value.type, tip_value: tip.value.value || 0,
    discount_type: discount.value.type, discount_value: meta.value.can_discount ? (discount.value.value || 0) : 0,
    shares: split.value === 'equal' ? shares.value : 1,
  };
  if (split.value === 'items') o.item_ids = picked.value;
  return o;
}

async function load() {
  try {
    const [m, b] = await Promise.all([http.get('restaurant/billing/meta'), http.get(`restaurant/orders/${route.params.id}/bill`)]);
    meta.value = m;
    order.value = b.order;
    history.value = b.payments || [];
    loadError.value = '';
  } catch (e) {
    loadError.value = e?.status === 404 ? t('Rst_Order_Not_Found') : (e?.data?.message || t('Failed'));
  }
}
async function reloadBill() {
  const b = await http.get(`restaurant/orders/${route.params.id}/bill`);
  order.value = b.order;
  history.value = b.payments || [];
}

function scheduleQuote() {
  clearTimeout(quoteTimer);
  // "Take payment" waits for the new figures (no paying the old bill).
  quoting.value = true;
  quoteTimer = setTimeout(runQuote, 250);
}
async function runQuote() {
  if (!order.value || order.value.status !== 'open' || !order.value.unpaid_count) { quoting.value = false; return; }
  if (split.value === 'items' && !picked.value.length) {
    quote.value = null;
    quoteError.value = t('Rst_Pick_Dishes_To_Pay');
    quoting.value = false;
    return;
  }
  const seq = ++quoteSeq;
  quoting.value = true;
  try {
    const r = await http.post(`restaurant/orders/${order.value.id}/bill/quote`, options());
    if (seq !== quoteSeq) return;
    quote.value = r.quote;
    quoteError.value = '';
    saleUuid = null;
    resetPayments();
  } catch (e) {
    if (seq !== quoteSeq) return;
    quote.value = null;
    quoteError.value = (e?.data?.errors && Object.values(e.data.errors)[0]?.[0]) || e?.data?.message || t('Failed');
  } finally {
    if (seq === quoteSeq) quoting.value = false;
  }
}
function resetPayments() {
  const q = quote.value;
  if (!q) return;
  if (split.value === 'equal') {
    payments.value = q.share_amounts.map((a, i) => ({ payment_method_id: payments.value[i]?.payment_method_id || cashMethodId.value, account_id: payments.value[i]?.account_id, amount: a }));
    amountsTouched.value = false;
    return;
  }
  if (!payments.value.length || payments.value.length > 1 && !amountsTouched.value) {
    payments.value = [{ payment_method_id: payments.value[0]?.payment_method_id || cashMethodId.value, account_id: payments.value[0]?.account_id, amount: q.grand_total }];
  } else if (payments.value.length === 1 && !amountsTouched.value) {
    payments.value[0].amount = q.grand_total;
  }
}
function addPaymentLine() {
  const left = Math.max(0, Math.round(((quote.value?.grand_total || 0) - received.value) * 100) / 100);
  payments.value.push({ payment_method_id: cashMethodId.value, account_id: undefined, amount: left });
  amountsTouched.value = true;
}
function togglePick(id) { picked.value = picked.value.includes(id) ? picked.value.filter(x => x !== id) : [...picked.value, id]; }
function selectSeat(s) { picked.value = unpaidItems.value.filter(i => i.seat === s).map(i => i.id); }

function searchClients(term) {
  clearTimeout(clientTimer);
  clientTimer = setTimeout(async () => {
    try {
      const r = await http.get('restaurant/billing/clients', { search: term || '' });
      clientOptions.value = (r.data || []).map(c => ({ value: c.id, label: c.phone ? `${c.name} · ${c.phone}` : c.name }));
    } catch (e) { /* the default customer still works */ }
  }, 250);
}

async function pay() {
  if (!canPay.value) return;
  paying.value = true;
  saleUuid = saleUuid || uuid();
  const paidQuote = quote.value;
  const paidLines = payments.value.map(p => ({ ...p }));
  try {
    const r = await http.post(`restaurant/orders/${order.value.id}/bill/pay`, {
      ...options(), split: split.value, client_id: clientId.value || null, sale_uuid: saleUuid,
      expected_total: paidQuote.grand_total, expected_item_ids: paidQuote.lines.map(l => l.item_id),
      payments: paidLines.map(p => ({ payment_method_id: p.payment_method_id, amount: Number(p.amount) || 0, account_id: p.account_id || null })),
    });
    saleUuid = null;
    done.value = { ...r, quote: r.quote || paidQuote, payments: paidLines, at: new Date() };
    order.value = r.order;
    doneOpen.value = true;
    picked.value = [];
    reloadBill().catch(() => {});
  } catch (e) {
    fail(e);
    // A sale that failed (422) is not retried with the same key; a network error keeps it so a retry cannot double-charge.
    if (e?.status) saleUuid = null;
    // The bill changed meanwhile (409): show the new figures.
    if (e?.status === 409) { reloadBill().catch(() => {}); scheduleQuote(); }
  } finally {
    paying.value = false;
  }
}
function nextPayment() {
  doneOpen.value = false;
  done.value = null;
  tip.value = { type: 'amount', value: 0 };
  discount.value = { type: 'amount', value: 0 };
  payments.value = [];
  amountsTouched.value = false;
  scheduleQuote();
}

// ------------------------------------------------------------------ printing (80 mm)
const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
function printHtml(title, body) {
  const html = `<!doctype html><html><head><meta charset="utf-8"><title>${esc(title)}</title><style>
    @page { size: 80mm auto; margin: 3mm; } body { font: 12px/1.4 monospace; margin: 0; width: 74mm; color: #000; }
    h1 { font-size: 16px; margin: 0; text-align: center; } .c { text-align: center; } .m { font-size: 11px; }
    .r { display: flex; justify-content: space-between; gap: 6px; } .l { padding: 3px 0; } .hr { border-top: 1px dashed #000; margin: 6px 0; }
    .b { font-weight: bold; font-size: 14px; } .pre { white-space: pre-line; }
    </style></head><body>${body}</body></html>`;
  const frame = document.createElement('iframe');
  frame.setAttribute('aria-hidden', 'true');
  frame.style.cssText = 'position:fixed;width:0;height:0;border:0;right:0;bottom:0';
  document.body.appendChild(frame);
  frame.contentDocument.open();
  frame.contentDocument.write(html);
  frame.contentDocument.close();
  setTimeout(() => { frame.contentWindow.focus(); frame.contentWindow.print(); setTimeout(() => frame.remove(), 1000); }, 50);
}
function receiptBody(q, extra) {
  const c = meta.value.company || {};
  const s = meta.value.settings || {};
  const o = order.value;
  const lines = q.lines.map(l => `<div class="l"><div class="r"><span>${esc(fmtQty(l.qty))}× ${esc(l.name)}</span><span>${esc(moneyBase(l.subtotal))}</span></div>${l.addons_label ? `<div class="m">${esc(l.addons_label)}</div>` : ''}</div>`).join('');
  const row = (label, v, cls = '') => `<div class="r ${cls}"><span>${esc(label)}</span><span>${esc(v)}</span></div>`;
  return `<h1>${esc(c.name)}</h1>
    ${c.address ? `<div class="c m">${esc(c.address)}</div>` : ''}${c.phone ? `<div class="c m">${esc(c.phone)}</div>` : ''}
    ${s.receipt_header ? `<div class="c m pre">${esc(s.receipt_header)}</div>` : ''}
    <div class="hr"></div>
    <div class="m">${esc(o.table ? t('Rst_Table_N', { name: o.table.name }) : o.ref)}${o.table ? ' · ' + esc(o.ref) : ''}${o.guests ? ' · ' + esc(t('Rst_Guests_N', { n: o.guests })) : ''}</div>
    <div class="m">${esc(new Date().toLocaleString())}</div>
    ${extra.head || ''}
    <div class="hr"></div>${lines}<div class="hr"></div>
    ${row(t('Rst_Dishes'), moneyBase(q.items_total))}
    ${q.tax_total ? row(t('Rst_Including_Tax'), moneyBase(q.tax_total), 'm') : ''}
    ${q.service_charge ? row(t('Rst_Service_Charge_Pct', { n: q.service_percent }), moneyBase(q.service_charge)) : ''}
    ${q.discount ? row(t('Discount'), '−' + moneyBase(q.discount)) : ''}
    ${q.tip ? row(t('Rst_Tip'), moneyBase(q.tip)) : ''}
    ${q.rounding ? row(t('Rst_Rounding'), '−' + moneyBase(q.rounding), 'm') : ''}
    ${row(t('Rst_To_Pay'), moneyBase(q.grand_total), 'b')}
    ${q.shares > 1 ? row(t('Rst_Each_Pays'), q.share_amounts.map(a => moneyBase(a)).join(' / '), 'm') : ''}
    ${extra.foot || ''}
    ${s.receipt_footer ? `<div class="hr"></div><div class="c m pre">${esc(s.receipt_footer)}</div>` : ''}`;
}
function printBill() {
  if (!quote.value) return;
  printHtml(order.value.ref, receiptBody(quote.value, { head: `<div class="c b" style="margin-top:6px">${esc(t('Rst_Bill_Not_Receipt'))}</div>` }));
}
function printReceipt() {
  const d = done.value;
  if (!d) return;
  const methods = Object.fromEntries(meta.value.payment_methods.map(m => [m.id, m.name]));
  const pays = d.payments.map(p => `<div class="r"><span>${esc(methods[p.payment_method_id] || '')}</span><span>${esc(moneyBase(p.amount))}</span></div>`).join('');
  printHtml(d.sale_ref || order.value.ref, receiptBody(d.quote, {
    head: `<div class="m">${esc(t('Rst_Sale_Ref', { ref: d.sale_ref || ('#' + d.sale_id) }))}</div>`,
    foot: `<div class="hr"></div>${pays}${d.change > 0 ? `<div class="r"><span>${esc(t('Change'))}</span><span>${esc(moneyBase(d.change))}</span></div>` : ''}<div class="c" style="margin-top:6px">${esc(t('Rst_Thank_You'))}</div>`,
  }));
}

watch([split, shares, picked, tip, discount], () => { amountsTouched.value = false; scheduleQuote(); }, { deep: true });
watch(split, (v) => {
  if (v === 'items' && !picked.value.length && seats.value.length) selectSeat(seats.value[0]);
  if (v !== 'equal') payments.value = payments.value.slice(0, 1);
});

onMounted(async () => {
  await load();
  loading.value = false;
  if (route.query.split === 'items') split.value = 'items';
  scheduleQuote();
});
</script>

<style scoped>
.bill-screen { display: flex; gap: 16px; align-items: flex-start; flex-wrap: wrap; }
.loading { flex: 1; display: flex; justify-content: center; padding: 96px 0; }
.panel { background: #fff; border: 1px solid rgba(5, 5, 5, 0.06); border-radius: 12px; padding: 16px; }
.left { flex: 1 1 420px; min-width: 0; }
.right { flex: 0 1 440px; width: 440px; display: flex; flex-direction: column; gap: 14px; }
.right.done { justify-content: center; }
.muted { color: rgba(0, 0, 0, 0.55); }
.small { font-size: 12px; }
.block { display: block; }
.num { font-variant-numeric: tabular-nums; }
.head { display: flex; align-items: flex-start; gap: 10px; margin-bottom: 12px; }
.head > div { flex: 1; }
.back { padding-inline: 0; }
.title { font-size: 18px; font-weight: 700; }
.split { margin-bottom: 12px; }
.equal { display: flex; align-items: center; gap: 8px; margin-bottom: 12px; }
.seat-picks { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; margin-bottom: 10px; }
.lines { border-top: 1px solid rgba(5, 5, 5, 0.06); }
.line { display: flex; gap: 10px; align-items: flex-start; padding: 10px 0; border-bottom: 1px dashed rgba(5, 5, 5, 0.08); font-size: 14px; cursor: default; }
.line.paid { opacity: 0.55; }
.line.off { opacity: 0.6; }
.qty { font-weight: 700; min-width: 26px; }
.line-body { flex: 1; min-width: 0; }
.line-name { font-weight: 600; }
.line-right { display: flex; flex-direction: column; align-items: flex-end; gap: 4px; }
.st { font-size: 11px; font-weight: 700; padding: 1px 7px; border-radius: 999px; }
.st-paid { background: #dcfce7; color: #166534; }
.history { margin-top: 14px; }
.hist-row { display: flex; justify-content: space-between; padding: 4px 0; font-size: 13px; }
.sub { font-weight: 700; margin-bottom: 6px; }
.chips { display: flex; gap: 6px; flex-wrap: wrap; align-items: center; }
.chip { min-height: 36px; padding: 0 14px; border-radius: 999px; border: 1px solid rgba(5, 5, 5, 0.15); background: #fff; font: inherit; font-weight: 600; cursor: pointer; color: inherit; }
.chip.on { background: var(--ant-color-primary, #6d28d9); border-color: var(--ant-color-primary, #6d28d9); color: #fff; }
.chip:focus-visible { outline: 2px solid var(--ant-color-primary, #6d28d9); outline-offset: 2px; }
.totals { background: #f7f7fb; border-radius: 10px; padding: 12px 14px; min-height: 60px; }
.row { display: flex; justify-content: space-between; gap: 10px; padding: 3px 0; }
.row.grand { font-size: 20px; font-weight: 700; border-top: 1px solid rgba(5, 5, 5, 0.1); margin-top: 6px; padding-top: 8px; }
.pay-row { display: flex; gap: 6px; align-items: center; margin-bottom: 6px; }
.share-n { min-width: 14px; }
.received { margin-top: 8px; font-weight: 600; }
.change { color: #166534; font-weight: 700; }
.short { color: #b91c1c; font-weight: 700; }
.actions { display: flex; gap: 8px; }
.actions > :last-child { flex: 1; }
.done-box { text-align: center; display: flex; flex-direction: column; gap: 8px; }
.done-amount { font-size: 30px; font-weight: 800; }
.done-change { font-size: 18px; font-weight: 700; color: #166534; background: #dcfce7; border-radius: 10px; padding: 8px; }
.done-actions { display: flex; flex-direction: column; gap: 8px; margin-top: 8px; }
@media (max-width: 900px) {
  .right { width: 100%; flex-basis: 100%; }
}
</style>
