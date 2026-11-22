<template>
  <div class="page">
    <PageHeader :title="$t('Rst_Orders')" :breadcrumb="[$t('Restaurant_Management'), $t('Rst_Orders')]">
      <template #actions>
        <a-space wrap>
          <a-button v-if="types.includes('takeaway')" type="primary" @click="openNew('takeaway')"><PlusOutlined /> {{ $t('Rst_New_Takeaway') }}</a-button>
          <a-button v-if="types.includes('delivery')" @click="openNew('delivery')"><PlusOutlined /> {{ $t('Rst_New_Delivery') }}</a-button>
        </a-space>
      </template>
    </PageHeader>

    <a-card size="small" class="toolbar-card">
      <div class="toolbar">
        <a-segmented v-model:value="type" :options="typeOptions" @change="load" />
        <a-segmented v-model:value="status" :options="statusOptions" @change="load" />
        <a-input-search v-model:value="search" allow-clear :placeholder="$t('Rst_Search_Orders')" style="max-width: 240px" @search="load" />
      </div>
    </a-card>

    <a-spin :spinning="loading">
      <a-empty v-if="!orders.length && !loading" :description="$t('Rst_No_Open_Orders')" />
      <div class="grid">
        <button v-for="o in orders" :key="o.id" type="button" class="card" @click="$router.push(`/restaurant/order/${o.id}`)">
          <span class="row">
            <strong class="title">{{ o.table ? $t('Rst_Table_N', { name: o.table.name }) : (o.customer_name || typeLabel(o.type)) }}</strong>
            <span><a-tag v-if="o.source === 'qr' || o.source === 'online'" color="gold">{{ o.source === 'qr' ? $t('Rst_Via_Qr') : $t('Rst_Via_Online') }}</a-tag><a-tag :color="typeColor(o.type)">{{ typeLabel(o.type) }}</a-tag></span>
          </span>
          <span class="muted small">{{ o.ref }} · {{ o.waiter || '—' }} · {{ $t('Rst_Minutes_N', { n: o.minutes || 0 }) }}</span>
          <span v-if="o.customer_phone" class="muted small">{{ o.customer_phone }}</span>
          <span class="row foot">
            <span>{{ $t('Rst_Dishes_N', { n: o.items }) }}<a-tag v-if="o.new_items" color="purple" style="margin-inline-start: 6px">{{ $t('Rst_Not_Sent_N', { n: o.new_items }) }}</a-tag></span>
            <strong class="num">{{ moneyBase(o.subtotal) }}</strong>
          </span>
        </button>
      </div>
    </a-spin>

    <a-modal v-model:open="newOpen" :title="newType === 'delivery' ? $t('Rst_New_Delivery') : $t('Rst_New_Takeaway')" :ok-text="$t('Rst_Start_Order')" :confirm-loading="saving" @ok="create">
      <a-form layout="vertical">
        <a-row :gutter="12">
          <a-col :span="12"><a-form-item :label="$t('Name')"><a-input v-model:value="form.customer_name" :maxlength="120" /></a-form-item></a-col>
          <a-col :span="12"><a-form-item :label="$t('Phone')"><a-input v-model:value="form.customer_phone" :maxlength="40" /></a-form-item></a-col>
        </a-row>
        <a-form-item v-if="newType === 'delivery'" :label="$t('Rst_Delivery_Address')" required><a-textarea v-model:value="form.delivery_address" :rows="2" :maxlength="1000" /></a-form-item>
        <a-form-item :label="$t('Rst_Order_Note')"><a-input v-model:value="form.note" :maxlength="500" /></a-form-item>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
/** Restaurant › Orders — GET restaurant/orders?type&status&search; POST restaurant/orders (takeaway / delivery). */
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import { useRouter } from 'vue-router';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { PlusOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';
import { useFormat } from '../../composables/useFormat';

const { t } = useI18n();
const router = useRouter();
const { moneyBase } = useFormat();

const loading = ref(true);
const saving = ref(false);
const orders = ref([]);
const types = ref([]);
const type = ref('');
const status = ref('open');
const search = ref('');
const newOpen = ref(false);
const newType = ref('takeaway');
const form = ref({});
let timer = null;

const typeOptions = computed(() => [{ value: '', label: t('All') }, { value: 'dine_in', label: t('Rst_Dine_In') }, { value: 'takeaway', label: t('Rst_Takeaway') }, { value: 'delivery', label: t('Rst_Delivery') }]);
const statusOptions = computed(() => [{ value: 'open', label: t('Rst_Open') }, { value: 'paid', label: t('Rst_Paid') }, { value: 'cancelled', label: t('Rst_Cancelled') }]);

function typeLabel(v) { return { dine_in: t('Rst_Dine_In'), takeaway: t('Rst_Takeaway'), delivery: t('Rst_Delivery') }[v] || v; }
function typeColor(v) { return { dine_in: 'purple', takeaway: 'blue', delivery: 'orange' }[v] || 'default'; }
function fail(e) {
  const errors = e?.data?.errors;
  message.error((errors && Object.values(errors)[0]?.[0]) || e?.data?.message || t('Failed'));
}

async function load() {
  loading.value = true;
  try {
    const r = await http.get('restaurant/orders', { type: type.value, status: status.value, search: search.value });
    orders.value = r.data || [];
  } catch (e) { fail(e); } finally { loading.value = false; }
}
async function loadTypes() {
  try { const r = await http.get('restaurant/pos/menu'); types.value = r.settings?.order_types || []; } catch (e) { /* buttons stay hidden */ }
}
function openNew(kind) { newType.value = kind; form.value = { customer_name: '', customer_phone: '', delivery_address: '', note: '' }; newOpen.value = true; }
async function create() {
  saving.value = true;
  try {
    const r = await http.post('restaurant/orders', { type: newType.value, ...form.value });
    newOpen.value = false;
    router.push(`/restaurant/order/${r.order.id}`);
  } catch (e) { fail(e); } finally { saving.value = false; }
}

onMounted(() => { load(); loadTypes(); timer = setInterval(() => { if (!document.hidden && status.value === 'open') load(); }, 20000); });
onBeforeUnmount(() => clearInterval(timer));
</script>

<style scoped>
.toolbar-card { margin-bottom: 16px; }
.toolbar { display: flex; gap: 12px; flex-wrap: wrap; align-items: center; }
.muted { color: rgba(0, 0, 0, 0.55); }
.small { font-size: 12px; }
.num { font-variant-numeric: tabular-nums; }
.grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 12px; }
.card { background: #fff; border: 1px solid rgba(5, 5, 5, 0.08); border-radius: 12px; padding: 14px; display: flex; flex-direction: column; gap: 4px; text-align: start; cursor: pointer; font: inherit; color: inherit; }
.card:hover { border-color: var(--ant-color-primary, #6d28d9); }
.card:focus-visible { outline: 2px solid var(--ant-color-primary, #6d28d9); }
.row { display: flex; justify-content: space-between; align-items: center; gap: 8px; }
.title { font-size: 16px; }
.foot { margin-top: 8px; padding-top: 8px; border-top: 1px solid rgba(5, 5, 5, 0.06); }
</style>
