<template>
  <div class="order-screen">
    <div v-if="loading && !order" class="loading"><a-spin size="large" /></div>
    <a-result v-else-if="loadError" status="warning" :title="loadError">
      <template #extra><a-button @click="$router.push('/restaurant/floor')">{{ $t('Rst_Back_To_Floor') }}</a-button></template>
    </a-result>

    <template v-else-if="order">
      <!-- Categories -->
      <nav class="cats" :aria-label="$t('Categories')">
        <a-button type="link" class="back" @click="goBack"><LeftOutlined /> {{ order.table ? $t('Rst_Floor_Plan') : $t('Rst_Orders') }}</a-button>
        <button v-for="c in catTabs" :key="c.id" type="button" :class="['cat', { active: catId === c.id }]" @click="catId = c.id">{{ c.name }}</button>
      </nav>

      <!-- Dishes -->
      <main class="dishes">
        <div class="dish-bar">
          <a-input v-model:value="search" allow-clear :placeholder="$t('Rst_Search_Dishes')" size="large">
            <template #prefix><SearchOutlined /></template>
          </a-input>
        </div>
        <a-empty v-if="!visibleDishes.length" :description="$t('Rst_No_Dishes_Found')" />
        <div class="dish-grid">
          <button
            v-for="d in visibleDishes" :key="d.id" type="button" class="dish"
            :disabled="!canOrder || !d.available || busy" @click="pick(d)"
          >
            <span class="dish-img">
              <img :src="d.image_url" alt="" loading="lazy" />
              <span v-if="d.sold_out" class="dish-off">{{ $t('Rst_Sold_Out_Today') }}</span>
              <span v-else-if="!d.available" class="dish-off">{{ $t('Rst_Not_Served_Now') }}</span>
            </span>
            <span class="dish-name">{{ d.name }}</span>
            <span class="dish-foot">
              <span class="num strong">{{ d.variants.length ? $t('Rst_From_Price', { price: moneyBase(Math.min(...d.variants.map(v => v.price))) }) : moneyBase(d.price) }}</span>
              <span class="diet">
                <span v-for="tag in d.dietary" :key="tag" :class="['diet-tag', 'd-' + tag]" :title="$t('Rst_Diet_' + tag)">{{ dietShort(tag) }}</span>
              </span>
            </span>
          </button>
        </div>
      </main>

      <!-- Ticket -->
      <aside class="ticket">
        <div class="ticket-head">
          <div>
            <div class="ticket-title">{{ order.table ? $t('Rst_Table_N', { name: order.table.name }) : typeLabel(order.type) }}</div>
            <div class="muted small">{{ order.ref }} · {{ order.waiter ? order.waiter.name : '' }} · {{ $t('Rst_Minutes_N', { n: order.minutes || 0 }) }}<a-tag v-if="order.source === 'qr' || order.source === 'online'" color="gold" style="margin-inline-start: 6px">{{ order.source === 'qr' ? $t('Rst_Via_Qr') : $t('Rst_Via_Online') }}</a-tag></div>
          </div>
          <a-tag v-if="!canOrder" :color="order.status === 'cancelled' ? 'red' : 'green'">{{ order.status === 'cancelled' ? $t('Rst_Cancelled') : $t('Rst_Paid') }}</a-tag>
          <a-button v-else-if="order.table" size="small" @click="editGuests">{{ $t('Rst_Guests_N', { n: order.guests || '—' }) }}</a-button>
          <a-button v-else size="small" @click="customerOpen = true">{{ $t('Rst_Customer') }}</a-button>
        </div>
        <div v-if="!order.table && (order.customer_name || order.delivery_address)" class="cust muted small">
          {{ [order.customer_name, order.customer_phone].filter(Boolean).join(' · ') }}<br v-if="order.delivery_address">{{ order.delivery_address }}
        </div>

        <div class="lines">
          <a-empty v-if="!order.items.length" :description="$t('Rst_Tap_A_Dish')" />
          <template v-for="course in courses" :key="course">
            <div v-if="courses.length > 1" class="course-head">{{ $t('Rst_Course_N', { n: course }) }}</div>
            <div v-for="it in order.items.filter(i => i.course === course)" :key="it.id" :class="['line', 'ls-' + it.status]">
              <span class="num qty">{{ fmtQty(it.qty) }}×</span>
              <div class="line-body">
                <div class="line-name">{{ it.name }}</div>
                <div v-if="it.addons_label" class="muted small">{{ it.addons_label }}</div>
                <div v-if="it.note" class="note">{{ it.note }}</div>
                <div class="muted small">
                  <span v-if="it.seat">{{ $t('Rst_Seat_N', { n: it.seat }) }}</span>
                  <span v-if="it.status === 'void'"> · {{ $t('Rst_Voided_Because', { reason: it.void_reason || '—' }) }}</span>
                </div>
                <div v-if="it.status === 'new' && canOrder && !it.paid" class="line-tools">
                  <a-button size="small" :disabled="busy || it.qty <= 1" :aria-label="$t('Rst_Less')" @click="changeQty(it, -1)">−</a-button>
                  <a-button size="small" :disabled="busy" :aria-label="$t('Rst_More')" @click="changeQty(it, 1)">+</a-button>
                  <a-button size="small" :disabled="busy" @click="editLine(it)">{{ $t('Edit') }}</a-button>
                  <a-button size="small" :type="it.hold ? 'primary' : 'default'" :disabled="busy" @click="toggleHold(it)">{{ it.hold ? $t('Rst_On_Hold') : $t('Rst_Hold_Course') }}</a-button>
                  <a-button size="small" danger :disabled="busy" :aria-label="$t('Delete')" @click="remove(it)"><DeleteOutlined /></a-button>
                </div>
                <div v-else-if="canOrder && it.status !== 'void' && !it.paid && canVoid" class="line-tools">
                  <a-button size="small" danger @click="openVoid(it)">{{ $t('Rst_Void') }}</a-button>
                </div>
              </div>
              <div class="line-right">
                <span class="num">{{ moneyBase(it.line_total) }}</span>
                <span :class="['st', 'st-' + it.status]">{{ statusLabel(it) }}</span>
                <span v-if="it.paid" class="st st-paid">{{ $t('Rst_Paid') }}</span>
              </div>
            </div>
          </template>
        </div>

        <div class="ticket-foot">
          <div class="total"><span>{{ $t('Rst_Running_Total') }}</span><span class="num">{{ moneyBase(order.subtotal) }}</span></div>
          <div class="muted small">{{ $t('Rst_Before_Tax_Note') }}</div>
          <template v-if="canOrder">
            <a-button v-if="order.ready_count" block size="large" class="served-btn" :loading="busy" @click="markServed">{{ $t('Rst_Mark_Served_N', { n: order.ready_count }) }}</a-button>
            <a-button type="primary" size="large" block :disabled="!order.new_count || busy" :loading="sending" @click="send()">
              {{ order.new_count ? $t('Rst_Send_N', { n: order.new_count }) : $t('Rst_Nothing_New') }}
            </a-button>
            <a-button v-for="c in order.held_courses" :key="'fire' + c" block :loading="sending" @click="send(c)">{{ $t('Rst_Fire_Course', { n: c }) }}</a-button>
            <a-button v-if="canBill && order.unpaid_count" block size="large" class="bill-btn" @click="$router.push('/restaurant/order/' + order.id + '/bill')">
              <DollarOutlined /> {{ $t('Rst_Bill_And_Pay') }}
            </a-button>
            <div class="foot-row">
              <a-button v-if="order.table" block @click="$router.push('/restaurant/floor')">{{ $t('Rst_Floor_Plan') }}</a-button>
              <a-button v-if="canVoid && !hasPaid" block danger @click="cancelOpen = true">{{ $t('Rst_Cancel_Order') }}</a-button>
            </div>
          </template>
        </div>
      </aside>
    </template>

    <!-- Dish picker -->
    <a-modal v-model:open="pickOpen" :title="picker.dish ? picker.dish.name : ''" :ok-text="$t('Rst_Add_To_Order_Price', { price: moneyBase(pickerTotal) })" :confirm-loading="busy" :ok-button-props="{ disabled: !!pickerError }" width="560px" @ok="addPicked">
      <template v-if="picker.dish">
        <p v-if="picker.dish.description" class="muted">{{ picker.dish.description }}</p>
        <div v-if="picker.dish.variants.length" class="grp">
          <div class="grp-head"><strong>{{ $t('Rst_Choose_Variant') }}</strong><a-tag color="orange">{{ $t('Rst_Rule_Required_One') }}</a-tag></div>
          <a-radio-group v-model:value="picker.variant_id" class="opts">
            <a-radio v-for="v in picker.dish.variants" :key="v.id" :value="v.id" class="opt">{{ v.name }} <span class="num muted">{{ moneyBase(v.price) }}</span></a-radio>
          </a-radio-group>
        </div>
        <div v-for="g in picker.dish.groups" :key="g.id" class="grp">
          <div class="grp-head"><strong>{{ g.name }}</strong><a-tag :color="g.min ? 'orange' : 'default'">{{ ruleText(g) }}</a-tag></div>
          <a-radio-group v-if="g.max === 1 && g.min === 1" v-model:value="picker.single[g.id]" class="opts">
            <a-radio v-for="o in g.options" :key="o.id" :value="o.id" :disabled="!o.available" class="opt">{{ o.name }} <span class="num muted">{{ o.price ? '+' + moneyBase(o.price) : '' }}</span><span v-if="!o.available" class="muted"> · {{ $t('Rst_Sold_Out') }}</span></a-radio>
          </a-radio-group>
          <a-checkbox-group v-else v-model:value="picker.multi[g.id]" class="opts">
            <a-checkbox v-for="o in g.options" :key="o.id" :value="o.id" :disabled="!o.available || (picker.multi[g.id].length >= g.max && !picker.multi[g.id].includes(o.id))" class="opt">{{ o.name }} <span class="num muted">{{ o.price ? '+' + moneyBase(o.price) : '' }}</span><span v-if="!o.available" class="muted"> · {{ $t('Rst_Sold_Out') }}</span></a-checkbox>
          </a-checkbox-group>
        </div>
        <a-row :gutter="12">
          <a-col :span="8"><a-form-item :label="$t('Quantity')"><a-input-number v-model:value="picker.qty" :min="1" :max="999" style="width: 100%" /></a-form-item></a-col>
          <a-col :span="8"><a-form-item :label="$t('Rst_Seat')"><a-select v-model:value="picker.seat" allow-clear :placeholder="$t('Rst_Shared')" :options="seatOptions" /></a-form-item></a-col>
          <a-col :span="8"><a-form-item :label="$t('Rst_Course')"><a-select v-model:value="picker.course" :options="[1, 2, 3, 4, 5].map(n => ({ value: n, label: $t('Rst_Course_N', { n }) }))" /></a-form-item></a-col>
        </a-row>
        <a-form-item :label="$t('Rst_Kitchen_Note')"><a-input v-model:value="picker.note" :maxlength="255" :placeholder="$t('Rst_Kitchen_Note_Example')" /></a-form-item>
        <a-checkbox v-model:checked="picker.hold">{{ $t('Rst_Hold_Until_Fired') }}</a-checkbox>
        <a-alert v-if="pickerError" type="info" show-icon style="margin-top: 12px" :message="pickerError" />
      </template>
    </a-modal>

    <!-- Edit line -->
    <a-modal v-model:open="lineOpen" :title="lineForm.name" :ok-text="$t('Save')" :confirm-loading="busy" @ok="saveLine">
      <a-row :gutter="12">
        <a-col :span="8"><a-form-item :label="$t('Quantity')"><a-input-number v-model:value="lineForm.qty" :min="1" :max="999" style="width: 100%" /></a-form-item></a-col>
        <a-col :span="8"><a-form-item :label="$t('Rst_Seat')"><a-select v-model:value="lineForm.seat" allow-clear :placeholder="$t('Rst_Shared')" :options="seatOptions" /></a-form-item></a-col>
        <a-col :span="8"><a-form-item :label="$t('Rst_Course')"><a-select v-model:value="lineForm.course" :options="[1, 2, 3, 4, 5].map(n => ({ value: n, label: $t('Rst_Course_N', { n }) }))" /></a-form-item></a-col>
      </a-row>
      <a-form-item :label="$t('Rst_Kitchen_Note')"><a-input v-model:value="lineForm.note" :maxlength="255" /></a-form-item>
    </a-modal>

    <!-- Void -->
    <a-modal v-model:open="voidOpen" :title="$t('Rst_Void_Dish', { name: voidLine ? voidLine.name : '' })" :ok-text="$t('Rst_Void')" ok-type="danger" :confirm-loading="busy" :ok-button-props="{ disabled: !voidReason.trim() }" @ok="doVoid">
      <p class="muted">{{ $t('Rst_Void_Help') }}</p>
      <a-input v-model:value="voidReason" :maxlength="255" :placeholder="$t('Rst_Reason')" />
    </a-modal>

    <!-- Cancel order -->
    <a-modal v-model:open="cancelOpen" :title="$t('Rst_Cancel_Order')" :ok-text="$t('Rst_Cancel_Order')" ok-type="danger" :confirm-loading="busy" :ok-button-props="{ disabled: !cancelReason.trim() }" @ok="doCancel">
      <p class="muted">{{ $t('Rst_Cancel_Help') }}</p>
      <a-input v-model:value="cancelReason" :maxlength="255" :placeholder="$t('Rst_Reason')" />
    </a-modal>

    <!-- Guests -->
    <a-modal v-model:open="guestsOpen" :title="$t('Rst_Guests')" :ok-text="$t('Save')" :confirm-loading="busy" @ok="saveGuests">
      <a-input-number v-model:value="guestsValue" :min="1" :max="500" style="width: 100%" />
    </a-modal>

    <!-- Customer (takeaway / delivery) -->
    <a-modal v-model:open="customerOpen" :title="$t('Rst_Customer')" :ok-text="$t('Save')" :confirm-loading="busy" @ok="saveCustomer">
      <a-form layout="vertical">
        <a-row :gutter="12">
          <a-col :span="12"><a-form-item :label="$t('Name')"><a-input v-model:value="customer.customer_name" :maxlength="120" /></a-form-item></a-col>
          <a-col :span="12"><a-form-item :label="$t('Phone')"><a-input v-model:value="customer.customer_phone" :maxlength="40" /></a-form-item></a-col>
        </a-row>
        <a-form-item v-if="order && order.type === 'delivery'" :label="$t('Rst_Delivery_Address')" required><a-textarea v-model:value="customer.delivery_address" :rows="2" :maxlength="1000" /></a-form-item>
        <a-form-item :label="$t('Rst_Order_Note')"><a-input v-model:value="customer.note" :maxlength="500" /></a-form-item>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * Restaurant › waiter order screen (route /restaurant/order/:id).
 * GET restaurant/pos/menu, GET restaurant/orders/{id}; POST .../items, PUT/DELETE .../items/{itemId},
 * POST .../items/{itemId}/void, POST .../send {course?}, POST .../cancel, PUT restaurant/orders/{id}.
 * Every tap is saved straight away as a "new" line; "Send" puts the new lines on a kitchen ticket.
 */
import { ref, computed, onMounted, onBeforeUnmount, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { LeftOutlined, SearchOutlined, DeleteOutlined, DollarOutlined } from '@ant-design/icons-vue';
import http from '../../lib/http';
import { useAuthStore } from '../../stores/auth';
import { useFormat } from '../../composables/useFormat';

const { t } = useI18n();
const route = useRoute();
const router = useRouter();
const auth = useAuthStore();
const { moneyBase } = useFormat();

const loading = ref(true);
const loadError = ref('');
const busy = ref(false);
const sending = ref(false);
const order = ref(null);
const dishes = ref([]);
const categories = ref([]);
const catId = ref('all');
const search = ref('');
const pickOpen = ref(false);
const picker = ref({ dish: null, variant_id: undefined, single: {}, multi: {}, qty: 1, seat: undefined, course: 1, note: '', hold: false });
const lineOpen = ref(false);
const lineForm = ref({});
const voidOpen = ref(false);
const voidLine = ref(null);
const voidReason = ref('');
const cancelOpen = ref(false);
const cancelReason = ref('');
const guestsOpen = ref(false);
const guestsValue = ref(1);
const customerOpen = ref(false);
const customer = ref({});
let timer = null;

const canOrder = computed(() => order.value && order.value.status === 'open');
const canVoid = computed(() => auth.can('rst_orders_void'));
const canBill = computed(() => auth.can('rst_billing'));
const hasPaid = computed(() => (order.value?.items || []).some(i => i.paid));
const catTabs = computed(() => [{ id: 'all', name: t('All') }].concat(categories.value));
const visibleDishes = computed(() => {
  const q = search.value.trim().toLowerCase();
  return dishes.value.filter(d => (catId.value === 'all' || d.category_id === catId.value) && (!q || d.name.toLowerCase().includes(q) || (d.code || '').toLowerCase().includes(q)));
});
const courses = computed(() => [...new Set((order.value?.items || []).map(i => i.course))].sort((a, b) => a - b));
const seatOptions = computed(() => Array.from({ length: Math.max(order.value?.guests || 4, 1) }, (_, i) => ({ value: i + 1, label: t('Rst_Seat_N', { n: i + 1 }) })));

const pickerOptionIds = computed(() => {
  const p = picker.value;
  if (!p.dish) return [];
  return p.dish.groups.flatMap(g => (g.max === 1 && g.min === 1 ? (p.single[g.id] ? [p.single[g.id]] : []) : (p.multi[g.id] || [])));
});
const pickerTotal = computed(() => {
  const p = picker.value;
  if (!p.dish) return 0;
  const v = p.dish.variants.find(x => x.id === p.variant_id);
  const base = v ? v.price : p.dish.price;
  const extra = p.dish.groups.flatMap(g => g.options).filter(o => pickerOptionIds.value.includes(o.id)).reduce((s, o) => s + o.price, 0);
  return (base + extra) * (p.qty || 1);
});
const pickerError = computed(() => {
  const p = picker.value;
  if (!p.dish) return '';
  if (p.dish.variants.length && !p.variant_id) return t('Rst_Choose_Variant');
  for (const g of p.dish.groups) {
    const n = (g.max === 1 && g.min === 1) ? (p.single[g.id] ? 1 : 0) : (p.multi[g.id] || []).length;
    if (n < g.min) return t('Rst_Choose_For', { group: g.name });
  }
  return '';
});

function typeLabel(type) { return { dine_in: t('Rst_Dine_In'), takeaway: t('Rst_Takeaway'), delivery: t('Rst_Delivery') }[type] || type; }
function statusLabel(it) {
  if (it.status === 'new') return it.hold ? t('Rst_On_Hold') : t('Rst_Line_New');
  return { sent: t('Rst_Line_Sent'), preparing: t('Rst_Line_Preparing'), ready: t('Rst_Line_Ready'), served: t('Rst_Line_Served'), void: t('Rst_Line_Void') }[it.status] || it.status;
}
function dietShort(tag) { return { vegetarian: 'V', vegan: 'VG', spicy: 'S', halal: 'H', gluten_free: 'GF' }[tag] || tag; }
function fmtQty(q) { return Number.isInteger(q) ? q : Number(q).toFixed(2); }
function ruleText(g) {
  if (g.min === 0) return g.max === 1 ? t('Rst_Rule_Optional_One') : t('Rst_Rule_Optional_Up_To', { n: g.max });
  if (g.min === g.max) return g.min === 1 ? t('Rst_Rule_Required_One') : t('Rst_Rule_Required_Exactly', { n: g.min });
  return t('Rst_Rule_Between', { min: g.min, max: g.max });
}
function fail(e) {
  const errors = e?.data?.errors;
  message.error((errors && Object.values(errors)[0]?.[0]) || e?.data?.message || t('Failed'));
}
function goBack() { router.push(order.value?.table ? '/restaurant/floor' : '/restaurant/orders'); }

async function loadOrder() {
  try {
    const r = await http.get(`restaurant/orders/${route.params.id}`);
    order.value = r.order;
    loadError.value = '';
  } catch (e) {
    loadError.value = e?.status === 404 ? t('Rst_Order_Not_Found') : (e?.data?.message || t('Failed'));
  }
}
async function loadMenu() {
  try {
    const r = await http.get('restaurant/pos/menu');
    dishes.value = r.dishes || [];
    categories.value = r.categories || [];
  } catch (e) { fail(e); }
}
async function apply(promise, okMsg) {
  busy.value = true;
  try {
    const r = await promise;
    if (r?.order) order.value = r.order;
    if (okMsg) message.success(okMsg);
    return true;
  } catch (e) {
    fail(e);
    await loadOrder();
    return false;
  } finally {
    busy.value = false;
  }
}

function pick(d) {
  if (!d.groups.length && !d.variants.length) {
    apply(http.post(`restaurant/orders/${order.value.id}/items`, { items: [{ product_id: d.id, qty: 1 }] }));
    return;
  }
  const single = {}; const multi = {};
  d.groups.forEach(g => {
    const defaults = g.options.filter(o => o.is_default && o.available).map(o => o.id);
    if (g.max === 1 && g.min === 1) single[g.id] = defaults[0] || (g.options.filter(o => o.available).length === 1 ? g.options.find(o => o.available).id : undefined);
    else multi[g.id] = defaults.slice(0, g.max);
  });
  picker.value = { dish: d, variant_id: d.variants.length === 1 ? d.variants[0].id : undefined, single, multi, qty: 1, seat: undefined, course: 1, note: '', hold: false };
  pickOpen.value = true;
}
async function addPicked() {
  const p = picker.value;
  const ok = await apply(http.post(`restaurant/orders/${order.value.id}/items`, { items: [{
    product_id: p.dish.id, variant_id: p.variant_id || null, qty: p.qty || 1, option_ids: pickerOptionIds.value,
    note: p.note || null, seat: p.seat || null, course: p.course || 1, hold: !!p.hold,
  }] }));
  if (ok) pickOpen.value = false;
}
function changeQty(it, d) { apply(http.put(`restaurant/orders/${order.value.id}/items/${it.id}`, { qty: Math.max(1, it.qty + d) })); }
function toggleHold(it) { apply(http.put(`restaurant/orders/${order.value.id}/items/${it.id}`, { hold: !it.hold })); }
function remove(it) { apply(http.delete(`restaurant/orders/${order.value.id}/items/${it.id}`)); }
function editLine(it) { lineForm.value = { id: it.id, name: it.name, qty: it.qty, seat: it.seat || undefined, course: it.course, note: it.note || '' }; lineOpen.value = true; }
async function saveLine() {
  const f = lineForm.value;
  if (await apply(http.put(`restaurant/orders/${order.value.id}/items/${f.id}`, { qty: f.qty, seat: f.seat || null, course: f.course, note: f.note || null }))) lineOpen.value = false;
}
function openVoid(it) { voidLine.value = it; voidReason.value = ''; voidOpen.value = true; }
async function doVoid() {
  if (await apply(http.post(`restaurant/orders/${order.value.id}/items/${voidLine.value.id}/void`, { reason: voidReason.value }), t('Rst_Dish_Voided'))) voidOpen.value = false;
}
async function doCancel() {
  if (await apply(http.post(`restaurant/orders/${order.value.id}/cancel`, { reason: cancelReason.value }), t('Rst_Order_Cancelled'))) cancelOpen.value = false;
}
async function send(course = null) {
  sending.value = true;
  try {
    const r = await http.post(`restaurant/orders/${order.value.id}/send`, course ? { course } : {});
    order.value = r.order;
    message.success(t('Rst_Sent_Ticket', { ref: r.ticket.ref }));
  } catch (e) { fail(e); await loadOrder(); } finally { sending.value = false; }
}
function markServed() { apply(http.post(`restaurant/orders/${order.value.id}/served`), t('Rst_Marked_Served')); }
function editGuests() { guestsValue.value = order.value.guests || 1; guestsOpen.value = true; }
async function saveGuests() { if (await apply(http.put(`restaurant/orders/${order.value.id}`, { guests: guestsValue.value }))) guestsOpen.value = false; }
async function saveCustomer() {
  const c = customer.value;
  if (await apply(http.put(`restaurant/orders/${order.value.id}`, { customer_name: c.customer_name || null, customer_phone: c.customer_phone || null, delivery_address: c.delivery_address || null, note: c.note || null }))) customerOpen.value = false;
}
watch(customerOpen, (open) => {
  if (open && order.value) customer.value = { customer_name: order.value.customer_name || '', customer_phone: order.value.customer_phone || '', delivery_address: order.value.delivery_address || '', note: order.value.note || '' };
});

// Kitchen status changes (phase 5) show up without a reload.
function tick() { if (!document.hidden && !busy.value && !pickOpen.value && !lineOpen.value) loadOrder(); }
watch(() => route.params.id, async (id) => { if (id) { loading.value = true; await loadOrder(); loading.value = false; } });
onMounted(async () => {
  await Promise.all([loadOrder(), loadMenu()]);
  loading.value = false;
  timer = setInterval(tick, 20000);
});
onBeforeUnmount(() => clearInterval(timer));
</script>

<style scoped>
.order-screen { display: flex; gap: 0; min-height: calc(100vh - 120px); background: #f5f5f8; border-radius: 12px; overflow: hidden; border: 1px solid rgba(5, 5, 5, 0.06); }
.loading { flex: 1; display: flex; justify-content: center; padding: 96px 0; }
.muted { color: rgba(0, 0, 0, 0.55); }
.small { font-size: 12px; }
.num { font-variant-numeric: tabular-nums; }
.strong { font-weight: 700; }

.cats { width: 180px; flex: none; background: #fff; border-inline-end: 1px solid rgba(5, 5, 5, 0.06); padding: 10px; display: flex; flex-direction: column; gap: 4px; overflow-y: auto; }
.cats .back { text-align: start; padding-inline: 4px; }
.cat { min-height: 44px; padding: 0 12px; border-radius: 10px; border: 0; background: transparent; text-align: start; font: 600 14px inherit; font-family: inherit; cursor: pointer; color: inherit; }
.cat:hover { background: #f5f3ff; }
.cat.active { background: var(--ant-color-primary, #6d28d9); color: #fff; }

.dishes { flex: 1; min-width: 0; padding: 14px; display: flex; flex-direction: column; gap: 12px; overflow-y: auto; }
.dish-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 12px; }
.dish { background: #fff; border: 1px solid rgba(5, 5, 5, 0.08); border-radius: 12px; padding: 8px; display: flex; flex-direction: column; gap: 6px; text-align: start; cursor: pointer; font: inherit; color: inherit; min-height: 170px; }
.dish:hover:not(:disabled) { border-color: var(--ant-color-primary, #6d28d9); }
.dish:disabled { cursor: not-allowed; opacity: 0.75; }
.dish:focus-visible { outline: 2px solid var(--ant-color-primary, #6d28d9); }
.dish-img { position: relative; height: 88px; border-radius: 8px; overflow: hidden; background: #efeff4; display: block; }
.dish-img img { width: 100%; height: 100%; object-fit: cover; }
.dish-off { position: absolute; inset: 0; background: rgba(31, 31, 44, 0.62); color: #fff; font-weight: 700; font-size: 12px; display: flex; align-items: center; justify-content: center; text-align: center; padding: 4px; }
.dish-name { font-weight: 600; font-size: 14px; line-height: 1.3; }
.dish-foot { margin-top: auto; display: flex; justify-content: space-between; align-items: center; gap: 4px; }
.diet { display: flex; gap: 3px; }
.diet-tag { font-size: 10px; font-weight: 700; min-width: 18px; height: 18px; padding: 0 3px; border-radius: 5px; display: inline-flex; align-items: center; justify-content: center; }
.d-vegetarian, .d-vegan { background: #dcfce7; color: #166534; }
.d-spicy { background: #fee2e2; color: #991b1b; }
.d-halal { background: #e0e7ff; color: #3730a3; }
.d-gluten_free { background: #fef3c7; color: #92400e; }

.ticket { width: 360px; flex: none; background: #fff; border-inline-start: 1px solid rgba(5, 5, 5, 0.06); display: flex; flex-direction: column; }
.ticket-head { padding: 14px 16px; border-bottom: 1px solid rgba(5, 5, 5, 0.06); display: flex; justify-content: space-between; align-items: flex-start; gap: 8px; }
.ticket-title { font-size: 18px; font-weight: 700; }
.cust { padding: 8px 16px 0; }
.lines { flex: 1; overflow-y: auto; padding: 6px 16px; }
.course-head { margin-top: 10px; font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; color: rgba(0, 0, 0, 0.55); }
.line { display: flex; gap: 10px; padding: 10px 0; border-bottom: 1px dashed rgba(5, 5, 5, 0.08); font-size: 14px; }
.line.ls-void { opacity: 0.55; }
.line.ls-void .line-name { text-decoration: line-through; }
.qty { font-weight: 700; min-width: 26px; }
.line-body { flex: 1; min-width: 0; }
.line-name { font-weight: 600; }
.note { display: inline-block; margin-top: 3px; font-size: 12px; color: #92400e; background: #fffbeb; border-radius: 6px; padding: 1px 6px; }
.line-tools { display: flex; gap: 4px; flex-wrap: wrap; margin-top: 6px; }
.line-right { display: flex; flex-direction: column; align-items: flex-end; gap: 4px; }
.st { font-size: 11px; font-weight: 700; padding: 1px 7px; border-radius: 999px; white-space: nowrap; background: #f1f1f6; color: #54546a; }
.st-new { background: #ede9fe; color: #5b21b6; }
.st-preparing { background: #fef3c7; color: #92400e; }
.st-ready { background: #dcfce7; color: #166534; }
.st-void { background: #fee2e2; color: #991b1b; }
.ticket-foot { padding: 14px 16px; border-top: 1px solid rgba(5, 5, 5, 0.06); display: flex; flex-direction: column; gap: 8px; }
.total { display: flex; justify-content: space-between; font-size: 18px; font-weight: 700; }
.foot-row { display: flex; gap: 8px; }
.served-btn { background: #dcfce7; border-color: #16a34a; color: #166534; font-weight: 700; }
.bill-btn { font-weight: 700; }
.st-paid { background: #dcfce7; color: #166534; }

.grp { margin-bottom: 14px; }
.grp-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; }
.opts { display: flex; flex-direction: column; gap: 6px; width: 100%; }
.opt { margin: 0; min-height: 36px; display: flex; align-items: center; }

@media (max-width: 1100px) {
  .order-screen { flex-wrap: wrap; }
  .cats { width: 100%; flex-direction: row; overflow-x: auto; border-inline-end: 0; border-bottom: 1px solid rgba(5, 5, 5, 0.06); }
  .cat { white-space: nowrap; }
  .ticket { width: 100%; border-inline-start: 0; border-top: 1px solid rgba(5, 5, 5, 0.06); }
}
</style>
