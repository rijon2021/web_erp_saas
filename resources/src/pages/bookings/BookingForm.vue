<template>
  <div class="page">
    <PageHeader
      :title="isEdit ? $t('Edit_Booking') : $t('Create_Booking')"
      :breadcrumb="[$t('Bookings'), isEdit ? $t('Edit_Booking') : $t('Create_Booking')]"
    />

    <div v-if="loading" style="display: flex; justify-content: center; padding: 96px 0">
      <a-spin size="large" />
    </div>

    <a-card v-else size="small">
      <a-form ref="formRef" :model="booking" :rules="rules" layout="vertical">
        <a-row :gutter="16">
          <a-col :xs="24" :md="12" :lg="8">
            <a-form-item name="customer_id">
              <template #label>
                <div class="label-row">
                  <span>{{ $t('Customer') }} *</span>
                  <a-button type="link" size="small" style="padding: 0" @click="openQuickAdd">
                    {{ $t('New_Customer') }}
                  </a-button>
                </div>
              </template>
              <a-select
                v-model:value="booking.customer_id" show-search option-filter-prop="label"
                :placeholder="$t('Choose_Customer')" allow-clear
                :options="customers.map(c => ({ value: c.id, label: c.name }))"
              />
            </a-form-item>
          </a-col>

          <a-col :xs="24" :md="12" :lg="8">
            <a-form-item :label="$t('Sales_Person')">
              <a-select
                v-model:value="booking.sales_person_id" show-search option-filter-prop="label"
                :placeholder="$t('Choose_Sales_Person')" allow-clear
                :options="salesPersons.map(p => ({ value: p.id, label: p.name }))"
              />
            </a-form-item>
          </a-col>

          <a-col :xs="24" :md="12" :lg="8">
            <a-form-item :label="$t('Tray')">
              <a-select
                v-model:value="booking.tray_id" show-search option-filter-prop="label"
                :placeholder="$t('Choose_Tray')" allow-clear
                :options="trays.map(t2 => ({ value: t2.id, label: t2.name }))"
              />
            </a-form-item>
          </a-col>

          <a-col :xs="24" :md="12" :lg="8">
            <a-form-item :label="$t('Product')" :help="isEdit ? undefined : $t('Bk_Only_Service_Products')">
              <a-select
                v-model:value="booking.product_id" show-search option-filter-prop="label"
                :placeholder="$t('Choose_Product')" allow-clear
                :options="products.map(p => ({ value: p.id, label: p.name }))"
                @change="onProductChange"
              />
            </a-form-item>
          </a-col>

          <a-col :xs="24" :md="12" :lg="8">
            <a-form-item :label="$t('Product_Name')" name="product_name">
              <a-input v-model:value="booking.product_name" :placeholder="$t('Product_Name')" />
            </a-form-item>
          </a-col>

          <a-col :xs="24" :md="12" :lg="4">
            <a-form-item :label="$t('Price')" name="price">
              <a-input-number
                v-model:value="booking.price" :min="0" :step="0.01"
                style="width: 100%" :placeholder="tf('Enter_Price', 'Enter price')"
              />
            </a-form-item>
          </a-col>

          <a-col :xs="24" :md="12" :lg="4">
            <a-form-item :label="$t('Amount')" name="amount">
              <a-input-number
                v-model:value="booking.amount" :min="0" :step="0.01"
                style="width: 100%" :placeholder="$t('Amount')"
              />
            </a-form-item>
          </a-col>

          <a-col :span="24">
            <a-form-item :label="$t('Product_Description')">
              <a-textarea
                v-model:value="booking.product_description" :rows="2"
                :placeholder="$t('Product_Description')"
              />
            </a-form-item>
          </a-col>

          <a-col :xs="24" :md="12" :lg="8">
            <a-form-item :label="$t('Date') + ' *'" name="booking_date">
              <a-date-picker v-model:value="booking.booking_date" value-format="YYYY-MM-DD" style="width: 100%" />
            </a-form-item>
          </a-col>

          <a-col :xs="24" :md="12" :lg="8">
            <a-form-item :label="$t('Start_Time') + ' *'" name="booking_time">
              <a-time-picker
                v-model:value="booking.booking_time" value-format="HH:mm" format="HH:mm" style="width: 100%"
                @change="autoEndTime"
              />
            </a-form-item>
          </a-col>

          <a-col :xs="24" :md="12" :lg="8">
            <a-form-item :label="$t('End_Time')" :extra="endTimeAuto ? $t('Bkm_End_From_Duration') : ''">
              <a-time-picker
                v-model:value="booking.booking_end_time" value-format="HH:mm" format="HH:mm" style="width: 100%"
                @change="endTimeAuto = false"
              />
            </a-form-item>
          </a-col>

          <a-col :xs="24" :md="12" :lg="8">
            <a-form-item :label="$t('Delivery_Date')">
              <a-date-picker v-model:value="booking.delivery_date" value-format="YYYY-MM-DD" style="width: 100%" />
            </a-form-item>
          </a-col>

          <a-col :xs="24" :md="12" :lg="8">
            <a-form-item :label="$t('Delivery_Time')">
              <a-time-picker
                v-model:value="booking.delivery_time" value-format="HH:mm" format="HH:mm" style="width: 100%"
              />
            </a-form-item>
          </a-col>

          <a-col :xs="24" :md="12" :lg="8">
            <a-form-item :label="$t('Status') + ' *'" name="status">
              <a-select
                v-model:value="booking.status" :placeholder="$t('Choose_Status')" :options="statusOptions"
              />
            </a-form-item>
          </a-col>

          <!-- Booking Management: who does it, checked against their free time -->
          <a-col v-if="staffList.length" :xs="24" :md="12" :lg="8">
            <a-form-item :label="$t('Bkm_Staff')">
              <a-select
                v-model:value="booking.staff_id" allow-clear :placeholder="$t('Bkm_Not_Assigned')"
                :options="staffList.map(s => ({ value: s.id, label: s.removed ? `${s.name} (${t('Bkm_Staff_Removed', 'removed')})` : s.name }))" @change="runCheck"
              />
              <div v-if="check" :class="['staff-check', check.ok ? 'ok' : 'bad']" role="status">
                {{ check.ok ? $t('Bkm_Time_Free') : reasonText(check.reason, check) }}
              </div>
            </a-form-item>
          </a-col>

          <a-col :xs="24" :lg="12">
            <a-form-item :label="$t('Delivery_Address')">
              <a-textarea
                v-model:value="booking.delivery_address" :rows="2"
                :placeholder="$t('Delivery_Address')"
              />
            </a-form-item>
          </a-col>

          <a-col :xs="24" :lg="12">
            <a-form-item :label="$t('Details')">
              <a-textarea v-model:value="booking.notes" :rows="4" :placeholder="$t('Afewwords')" />
            </a-form-item>
          </a-col>
        </a-row>

        <a-space>
          <a-button type="primary" :loading="submitting" @click="submit">{{ $t('submit') }}</a-button>
          <a-button @click="$router.push('/bookings')">{{ $t('Cancel') }}</a-button>
        </a-space>
      </a-form>
    </a-card>

    <!-- Quick add customer -->
    <a-modal
      v-model:open="quickAddOpen" :title="$t('New_Customer')"
      :confirm-loading="clientSaving" :ok-text="$t('submit')" :cancel-text="$t('Cancel')"
      @ok="submitQuickAdd"
    >
      <a-form ref="clientRef" :model="client" :rules="clientRules" layout="vertical">
        <a-form-item :label="$t('CustomerName') + ' *'" name="name">
          <a-input v-model:value="client.name" :placeholder="$t('CustomerName')" />
        </a-form-item>
        <a-form-item :label="$t('Email')">
          <a-input v-model:value="client.email" />
        </a-form-item>
        <a-form-item :label="$t('Phone')">
          <a-input v-model:value="client.phone" />
        </a-form-item>
        <a-form-item :label="$t('Address')">
          <a-textarea v-model:value="client.adresse" :rows="2" />
        </a-form-item>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * Booking create/edit — legacy store_booking.vue + Edit_booking.vue, which
 * were byte-identical apart from the five branches handled below.
 *
 * - create: GET bookings/create → {customers, products, trays, sales_persons}
 *           → POST bookings
 * - edit:   GET bookings/{id}/edit → the same four lists + booking
 *           → PUT bookings/{id} (same body, id only in the URL)
 *
 * The product list is services only — that is the server's filter, not ours.
 *
 * Legacy quirks kept: `price` and `amount` are independent free-entry numbers
 * with no arithmetic between them, and the product-price prefill differs by
 * mode (create overwrites whatever is typed and clears on deselect; edit only
 * fills an empty price and ignores deselect).
 *
 * Fixed: the default booking date used toISOString(), i.e. UTC, so anyone east
 * of UTC got yesterday's date late in the evening — it is local now. Server
 * validation errors were swallowed behind a generic toast; field messages are
 * surfaced. Times still round-trip as HH:mm, truncating the HH:mm:ss the API
 * returns, or the pickers render blank and a save would wipe existing times.
 */
import { ref, computed, onMounted, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { message, Modal } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import PageHeader from '../../components/PageHeader.vue';
import { t as tf } from '../../i18n';
import http from '../../lib/http';
import { statusLabel as bookingStatusLabel, reasonText } from './bookingStatus';

const { t } = useI18n();
const route = useRoute();
const router = useRouter();

const id = route.params.id;
const isEdit = !!id;

const loading = ref(true);
const submitting = ref(false);
const formRef = ref();

const customers = ref([]);
const products = ref([]);
const trays = ref([]);
const salesPersons = ref([]);

/** Local date, not UTC — see the note above. */
function todayLocal() {
  const d = new Date();
  const pad = n => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
}

const booking = ref({
  customer_id: null, sales_person_id: null, tray_id: null, product_id: null,
  product_name: '', product_description: '', price: null, amount: null,
  booking_date: isEdit ? '' : todayLocal(),
  booking_time: '', booking_end_time: '',
  delivery_date: '', delivery_time: '', delivery_address: '',
  status: 'pending', notes: '', staff_id: undefined,
});

// Statuses come from the API (Booking Management adds arrived / declined / no-show).
const apiStatuses = ref(['pending', 'confirmed', 'cancelled', 'completed']);
const statusOptions = computed(() => apiStatuses.value.map(s => ({ value: s, label: bookingStatusLabel(s) })));

// ---------------- Booking Management: staff + free-time check ----------------
const staffList = ref([]);
const check = ref(null);
let checkSeq = 0;
async function runCheck() {
  check.value = null;
  const b = booking.value;
  if (!staffList.value.length || !b.staff_id || !b.booking_date || !b.booking_time) return;
  if (!['pending', 'confirmed', 'arrived'].includes(b.status)) return;
  const seq = ++checkSeq;
  try {
    const data = await http.get('bookings/availability/check', {
      product_id: b.product_id || undefined, staff_id: b.staff_id, date: b.booking_date, time: b.booking_time,
      end_time: b.booking_end_time || undefined, ignore_id: isEdit ? Number(id) : undefined,
    });
    if (seq === checkSeq && data?.ready !== false) check.value = data;
  } catch (e) {
    /* the save runs the same check */
  }
}
watch(() => [booking.value.booking_date, booking.value.booking_time, booking.value.booking_end_time, booking.value.product_id, booking.value.status], runCheck);

const rules = computed(() => ({
  customer_id: [{ required: true, message: t('Field_is_required') }],
  booking_date: [{ required: true, message: t('Field_is_required') }],
  booking_time: [{ required: true, message: t('Field_is_required') }],
  status: [{ required: true, message: t('Field_is_required') }],
  product_name: [{ max: 255, message: t('Please_fill_the_form_correctly') }],
  price: [{ type: 'number', min: 0, message: t('Please_fill_the_form_correctly') }],
  amount: [{ type: 'number', min: 0, message: t('Please_fill_the_form_correctly') }],
}));

/** The API stores HH:mm:ss; the pickers only accept HH:mm. */
function normalizeTime(value) {
  if (!value) return '';
  return String(value).length > 5 ? String(value).substring(0, 5) : String(value);
}

/**
 * Booking Management › Services: a service with a booking length fills the
 * end time from the start time — only while the end time is empty or was
 * filled this way (a typed end time is never replaced).
 */
const endTimeAuto = ref(false);
function autoEndTime() {
  const product = products.value.find(p => p.id === booking.value.product_id);
  const minutes = product?.duration_minutes;
  const start = booking.value.booking_time;
  if (!minutes || !start || (booking.value.booking_end_time && !endTimeAuto.value)) return;
  const [h, m] = String(start).split(':').map(Number);
  const total = h * 60 + m + minutes;
  if (total >= 24 * 60) return; // runs past midnight: leave it to the user
  booking.value.booking_end_time = `${String(Math.floor(total / 60)).padStart(2, '0')}:${String(total % 60).padStart(2, '0')}`;
  endTimeAuto.value = true;
}

function onProductChange(productId) {
  const product = products.value.find(p => p.id === productId);
  autoEndTime();
  if (isEdit) {
    // Edit never clobbers a price already on the booking.
    if (product?.price && !booking.value.price) booking.value.price = product.price;
    return;
  }
  if (productId) {
    if (product?.price) booking.value.price = product.price;
  } else {
    booking.value.price = null;
  }
}

function payload() {
  const b = booking.value;
  return {
    customer_id: b.customer_id,
    sales_person_id: b.sales_person_id,
    tray_id: b.tray_id,
    product_id: b.product_id,
    product_name: b.product_name,
    product_description: b.product_description,
    price: b.price ? parseFloat(b.price) : null,
    amount: b.amount ? parseFloat(b.amount) : null,
    booking_date: b.booking_date,
    booking_time: b.booking_time,
    // These three must be null rather than "" — the server validates them
    // with date_format:H:i and an empty string fails.
    booking_end_time: b.booking_end_time || null,
    delivery_date: b.delivery_date || null,
    delivery_time: b.delivery_time || null,
    delivery_address: b.delivery_address,
    status: b.status,
    notes: b.notes,
    // Only once Booking Management is set up; absent = the server keeps the current staff.
    ...(staffList.value.length ? { staff_id: b.staff_id || null } : {}),
  };
}

async function save(force = false) {
  const body = { ...payload(), ...(force ? { force: 1 } : {}) };
  if (isEdit) {
    await http.put(`bookings/${id}`, body);
    message.success(t('Successfully_Updated'));
  } else {
    await http.post('bookings', body);
    message.success(t('Successfully_Created'));
  }
  router.push('/bookings');
}

async function submit() {
  try {
    await formRef.value.validate();
  } catch (e) {
    message.error(t('Please_fill_the_form_correctly'));
    return;
  }
  submitting.value = true;
  try {
    await save();
  } catch (e) {
    const d = e?.data || {};
    if (e?.status === 422 && d.can_force) {
      // The staff member is not free: say why and let the user book it anyway.
      Modal.confirm({
        title: t('Bkm_Clash_Title', 'This time is not free'),
        content: reasonText(d.reason, d),
        okText: t('Bkm_Save_Anyway', 'Save anyway'),
        cancelText: t('Cancel'),
        async onOk() {
          try {
            await save(true);
          } catch (e2) {
            message.error(e2?.data?.message || t('InvalidData'));
          }
        },
      });
      return;
    }
    const errors = d.errors;
    message.error(d.message || (errors && Object.values(errors).flat()[0]) || t('InvalidData'));
  } finally {
    submitting.value = false;
  }
}

// ---------------- quick add customer ----------------

const quickAddOpen = ref(false);
const clientSaving = ref(false);
const clientRef = ref();
const client = ref({ name: '', email: '', phone: '', adresse: '' });
const clientRules = computed(() => ({
  name: [{ required: true, message: t('Field_is_required') }],
}));

function openQuickAdd() {
  client.value = { name: '', email: '', phone: '', adresse: '' };
  clientRef.value?.clearValidate?.();
  quickAddOpen.value = true;
}

async function submitQuickAdd() {
  try {
    await clientRef.value.validate();
  } catch (e) {
    message.error(t('Please_fill_the_form_correctly'));
    return;
  }
  clientSaving.value = true;
  try {
    const data = await http.post('clients', {
      name: client.value.name,
      email: client.value.email || '',
      phone: client.value.phone || '',
      adresse: client.value.adresse || '',
    });
    const created = data?.provider || data;
    if (created?.id) {
      customers.value.push({ id: created.id, name: created.name });
      booking.value.customer_id = created.id;
    }
    message.success(t('Successfully_Created'));
    quickAddOpen.value = false;
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    clientSaving.value = false;
  }
}

onMounted(async () => {
  try {
    const data = await http.get(isEdit ? `bookings/${id}/edit` : 'bookings/create');
    customers.value = data?.customers || [];
    products.value = data?.products || [];
    trays.value = data?.trays || [];
    salesPersons.value = data?.sales_persons || [];
    staffList.value = data?.staff || [];
    if (Array.isArray(data?.statuses) && data.statuses.length) apiStatuses.value = data.statuses;

    if (isEdit) {
      const b = data?.booking || {};
      booking.value = {
        customer_id: b.customer_id,
        sales_person_id: b.sales_person_id,
        tray_id: b.tray_id,
        product_id: b.product_id,
        product_name: b.product_name || '',
        product_description: b.product_description || '',
        // decimal:2 casts arrive as strings ("150.00"); the number rules reject them.
        price: b.price === null || b.price === undefined || b.price === '' ? null : Number(b.price),
        amount: b.amount === null || b.amount === undefined || b.amount === '' ? null : Number(b.amount),
        // Defensive slice: the pickers need a bare date even if the API ever
        // starts returning a full timestamp.
        booking_date: String(b.booking_date || '').slice(0, 10),
        booking_time: normalizeTime(b.booking_time),
        booking_end_time: normalizeTime(b.booking_end_time),
        delivery_date: String(b.delivery_date || '').slice(0, 10),
        delivery_time: normalizeTime(b.delivery_time),
        delivery_address: b.delivery_address || '',
        status: b.status,
        notes: b.notes || '',
        staff_id: b.staff_id || undefined,
      };
      runCheck();
    }
  } catch (e) {
    // Legacy failed silently here and left an empty form with empty selects.
    message.error(t('InvalidData'));
  } finally {
    loading.value = false;
  }
});
</script>

<style scoped>
.label-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  width: 100%;
}
.staff-check {
  margin-top: 4px;
  font-size: 13px;
}
.staff-check.ok { color: #135200; }
.staff-check.bad { color: #a8071a; }
</style>
