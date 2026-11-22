<template>
  <div class="page">
    <PageHeader
      :title="$t('Bkm_Services')"
      :subtitle="$t('Bkm_Services_Subtitle')"
      :breadcrumb="[$t('Booking Management'), $t('Bkm_Services')]"
    >
      <template #actions>
        <a-button :disabled="!ready" @click="openSuggestions">
          <template #icon><AppstoreAddOutlined /></template>
          {{ $t('Bkm_Suggested_Categories') }}
        </a-button>
        <a-button type="primary" @click="addService">
          <template #icon><PlusOutlined /></template>
          {{ $t('Bkm_Add_Service') }}
        </a-button>
      </template>
    </PageHeader>

    <a-alert v-if="!ready" type="warning" show-icon :message="$t('Bkm_Not_Ready')" />

    <template v-else>
      <a-card size="small" class="block">
        <div class="toolbar">
          <a-segmented v-model:value="status" :options="statusOptions" @change="load" />
          <a-input-search
            v-model:value="search" allow-clear :placeholder="$t('Bkm_Search_Services')" style="width: 240px"
            @search="load" @change="onSearchInput"
          />
          <a-select
            v-model:value="categoryId" allow-clear :placeholder="$t('Bkm_All_Categories')" style="width: 200px"
            :options="categories.map((c) => ({ value: c.id, label: c.name }))" @change="load"
          />
        </div>

        <div v-if="selected.length" class="bulk-bar" role="region" :aria-label="$t('Bkm_Bulk_Actions')">
          <span>{{ tp('Bkm_N_Selected', '{n} selected', { n: selected.length }) }}</span>
          <a-button size="small" @click="bulk('setup')">{{ $t('Bkm_Bulk_Setup') }}</a-button>
          <a-button size="small" @click="bulk('show')">{{ $t('Bkm_Bulk_Show') }}</a-button>
          <a-button size="small" @click="bulk('hide')">{{ $t('Bkm_Bulk_Hide') }}</a-button>
          <a-popconfirm :title="$t('Bkm_Bulk_Remove_Confirm')" @confirm="bulk('remove')">
            <a-button size="small" danger>{{ $t('Bkm_Bulk_Remove') }}</a-button>
          </a-popconfirm>
          <a-button size="small" type="link" @click="selected = []">{{ $t('Bkm_Clear_Selection') }}</a-button>
        </div>

        <a-table
          :data-source="rows" :columns="columns" :loading="loading" row-key="id" size="middle"
          :pagination="{ pageSize: 25, hideOnSinglePage: true, showSizeChanger: false }"
          :row-selection="{ selectedRowKeys: selected, onChange: (keys) => (selected = keys) }"
          :scroll="{ x: 900 }"
        >
          <template #emptyText>
            <a-empty :description="emptyText">
              <a-button v-if="!counts.all" type="primary" @click="addService">{{ $t('Bkm_Add_Service') }}</a-button>
            </a-empty>
          </template>
          <template #bodyCell="{ column, record }">
            <template v-if="column.key === 'service'">
              <div class="svc">
                <img v-if="record.image" :src="uploadUrl('products', record.image)" alt="" class="thumb">
                <span v-else class="thumb thumb-empty"><component :is="iconFor(record)" :size="18" :stroke-width="1.7" /></span>
                <span class="svc-text">
                  <span class="svc-name">{{ record.name }}</span>
                  <span class="muted small">{{ [record.category_name, record.code].filter(Boolean).join(' · ') }}</span>
                </span>
              </div>
            </template>
            <template v-else-if="column.key === 'price'">
              <span>{{ money(record.price) }}</span>
              <div v-if="record.detail" class="muted small">{{ priceDisplayLabel(record.detail.price_display) }}</div>
            </template>
            <template v-else-if="column.key === 'duration'">
              <span v-if="record.detail">{{ duration(record.detail.duration_minutes) }}</span>
              <span v-else class="muted">—</span>
            </template>
            <template v-else-if="column.key === 'buffer'">
              <span v-if="record.detail">{{ bufferLabel(record.detail) }}</span>
              <span v-else class="muted">—</span>
            </template>
            <template v-else-if="column.key === 'seats'">
              <span v-if="record.detail">
                {{ record.detail.seats }}
                <a-tag v-if="record.detail.seats > 1" color="blue" class="tag-sm">{{ $t('Bkm_Class') }}</a-tag>
              </span>
              <span v-else class="muted">—</span>
            </template>
            <template v-else-if="column.key === 'staff'">
              <span v-if="!record.detail" class="muted">—</span>
              <span v-else-if="!record.staff_ids.length" class="muted">{{ $t('Bkm_Anyone') }}</span>
              <span v-else>{{ staffNames(record.staff_ids) }}</span>
            </template>
            <template v-else-if="column.key === 'type'">
              <span v-if="record.detail && record.detail.business_type">{{ typeLabel(record.detail.business_type) }}</span>
              <span v-else class="muted">—</span>
            </template>
            <template v-else-if="column.key === 'website'">
              <a-tag v-if="!record.detail">{{ $t('Bkm_Not_Bookable') }}</a-tag>
              <a-tag v-else-if="record.detail.show_on_website" color="green">
                {{ $t('Bkm_Shown') }}<template v-if="record.detail.featured"> · {{ $t('Bkm_Featured') }}</template>
              </a-tag>
              <a-tag v-else color="default">{{ $t('Bkm_Hidden') }}</a-tag>
            </template>
            <template v-else-if="column.key === 'actions'">
              <div class="actions">
                <a-button size="small" :type="record.detail ? 'default' : 'primary'" @click="openEdit(record)">
                  {{ record.detail ? $t('Edit') : $t('Bkm_Set_Up') }}
                </a-button>
                <a-dropdown :trigger="['click']">
                  <a-button size="small" :aria-label="$t('Bkm_More_Actions')"><EllipsisOutlined /></a-button>
                  <template #overlay>
                    <a-menu>
                      <a-menu-item key="product" @click="$router.push(`/products/${record.id}/edit?return=/bookings/services`)">
                        {{ $t('Bkm_Edit_Product') }}
                      </a-menu-item>
                      <a-menu-item v-if="record.detail" key="remove" danger @click="confirmRemove(record)">
                        {{ $t('Bkm_Remove_From_Bookings') }}
                      </a-menu-item>
                    </a-menu>
                  </template>
                </a-dropdown>
              </div>
            </template>
          </template>
        </a-table>
      </a-card>
    </template>

    <!-- Booking details drawer -->
    <a-drawer
      v-model:open="drawerOpen" :width="440" destroy-on-close
      :title="editing ? tp('Bkm_Booking_Details_For', 'Booking details · {name}', { name: editing.name }) : ''"
    >
      <a-form v-if="editing" layout="vertical">
        <a-alert v-if="!editing.detail" type="info" show-icon class="block" :message="$t('Bkm_Set_Up_Help')" />
        <a-form-item v-if="typeChoices.length > 1" :label="$t('Bkm_Service_Type')" :extra="$t('Bkm_Service_Type_Help')">
          <a-select v-model:value="form.business_type" allow-clear :placeholder="$t('Bkm_Common_Fields_Only')" :options="typeChoices" />
        </a-form-item>
        <a-form-item :label="$t('Bkm_Duration')">
          <a-input-number v-model:value="form.duration_minutes" :min="5" :max="1440" :step="5" :precision="0" style="width: 100%" :addon-after="$t('Bkm_Minutes')" />
          <div class="presets">
            <a-button v-for="m in [15, 30, 45, 60, 90, 120]" :key="m" size="small" :type="form.duration_minutes === m ? 'primary' : 'default'" @click="form.duration_minutes = m">
              {{ duration(m) }}
            </a-button>
          </div>
        </a-form-item>
        <a-row :gutter="12">
          <a-col :span="12">
            <a-form-item :label="$t('Bkm_Buffer_Before')">
              <a-input-number v-model:value="form.buffer_before" :min="0" :max="240" :step="5" :precision="0" style="width: 100%" :addon-after="$t('Bkm_Minutes')" />
            </a-form-item>
          </a-col>
          <a-col :span="12">
            <a-form-item :label="$t('Bkm_Buffer_After')">
              <a-input-number v-model:value="form.buffer_after" :min="0" :max="240" :step="5" :precision="0" style="width: 100%" :addon-after="$t('Bkm_Minutes')" />
            </a-form-item>
          </a-col>
        </a-row>
        <p class="muted small help-tight">{{ tp('Bkm_Blocks_Total', 'Each booking blocks {d} in the calendar.', { d: duration(form.duration_minutes + form.buffer_before + form.buffer_after) }) }}</p>
        <a-form-item :label="$t('Bkm_Seats')" :extra="$t('Bkm_Seats_Help')">
          <a-input-number v-model:value="form.seats" :min="1" :max="500" :precision="0" style="width: 160px" />
        </a-form-item>
        <a-row :gutter="12">
          <a-col :span="12">
            <a-form-item :label="$t('Bkm_Price_Display')" :extra="tp('Bkm_Product_Price', 'Product price: {p}', { p: money(editing.price) })">
              <a-select v-model:value="form.price_display" :options="priceDisplayOptions" />
            </a-form-item>
          </a-col>
          <a-col :span="12">
            <a-form-item :label="$t('Bkm_Customers_Can')">
              <a-select v-model:value="form.booking_modes" :options="modeOptions" />
            </a-form-item>
          </a-col>
        </a-row>
        <a-row v-if="deposits.ready" :gutter="12">
          <a-col :span="14">
            <a-form-item :label="$t('Bkm_Deposit')" :extra="form.deposit_choice === 'default' ? defaultDepositText : ''">
              <a-select v-model:value="form.deposit_choice" :options="depositChoiceOptions" />
            </a-form-item>
          </a-col>
          <a-col v-if="form.deposit_choice === 'fixed' || form.deposit_choice === 'percent'" :span="10">
            <a-form-item :label="form.deposit_choice === 'percent' ? $t('Bkm_Deposit_Percent') : $t('Bkm_Deposit_Amount')">
              <a-input-number
                v-model:value="form.deposit_value" :min="0" :max="form.deposit_choice === 'percent' ? 100 : 99999999" :precision="2"
                style="width: 100%" :addon-after="form.deposit_choice === 'percent' ? '%' : deposits.currency"
              />
            </a-form-item>
          </a-col>
        </a-row>
        <a-form-item v-if="staffList.length" :label="$t('Bkm_Staff_Who_Do')" :extra="$t('Bkm_Staff_Who_Do_Help')">
          <a-checkbox-group v-model:value="form.staff_ids" class="staff-checks">
            <a-checkbox v-for="s in staffList" :key="s.id" :value="s.id">
              <span class="dot" :style="{ background: s.color }" aria-hidden="true" /> {{ s.name }}
            </a-checkbox>
          </a-checkbox-group>
        </a-form-item>
        <a-alert v-else type="info" show-icon class="block" :message="$t('Bkm_No_Staff_Yet')">
          <template #description><router-link to="/bookings/staff">{{ $t('Bkm_Staff_Hours') }}</router-link></template>
        </a-alert>
        <div class="web-box">
          <label class="switch-row"><a-switch v-model:checked="form.show_on_website" /> {{ $t('Bkm_Show_On_Website') }}</label>
          <label class="switch-row"><a-switch v-model:checked="form.featured" :disabled="!form.show_on_website" /> {{ $t('Bkm_Featured_Home') }}</label>
          <a-form-item :label="$t('Bkm_Short_Description')" class="no-mb">
            <a-textarea v-model:value="form.short_description" :rows="2" :maxlength="500" show-count />
          </a-form-item>
          <a-form-item :label="$t('Bkm_Website_Order')" :extra="$t('Bkm_Website_Order_Help')" class="no-mb">
            <a-input-number v-model:value="form.sort_order" :min="0" :max="100000" :precision="0" style="width: 160px" />
          </a-form-item>
        </div>
      </a-form>
      <template #footer>
        <div class="drawer-footer">
          <a-button @click="drawerOpen = false">{{ $t('Cancel') }}</a-button>
          <a-button type="primary" :loading="saving" @click="saveDetail">{{ $t('Save') }}</a-button>
        </div>
      </template>
    </a-drawer>

    <!-- Suggested categories -->
    <a-modal
      v-model:open="suggestOpen" :title="$t('Bkm_Suggested_Categories')" :ok-text="$t('Bkm_Create_Categories')"
      :ok-button-props="{ disabled: !suggestPicked.length }" :confirm-loading="suggestSaving" @ok="createSuggested"
    >
      <p class="muted">{{ $t('Bkm_Suggested_Categories_Help') }}</p>
      <a-spin :spinning="suggestLoading">
        <div v-for="g in suggestGroups" :key="g.type" class="suggest-group">
          <div class="suggest-head"><component :is="typeIcon(g.type)" :size="16" :stroke-width="1.8" /> {{ typeLabel(g.type) }}</div>
          <div class="suggest-list">
            <a-checkbox
              v-for="n in g.names" :key="g.type + n.name" :disabled="n.exists"
              :checked="n.exists || suggestPicked.includes(n.name)" @change="toggleSuggested(n.name)"
            >
              {{ n.name }} <span v-if="n.exists" class="muted small">({{ $t('Bkm_Exists') }})</span>
            </a-checkbox>
          </div>
        </div>
        <a-empty v-if="!suggestLoading && !suggestGroups.length" />
      </a-spin>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * Booking Management › Services (permission bookings_services).
 * GET bookings/services → {ready, rows, counts, categories, types};
 * PUT/DELETE bookings/services/{productId}; POST bookings/services/bulk;
 * GET/POST bookings/services/suggested-categories.
 *
 * A service is a service product; "Add service" opens the product form preset
 * to a service and comes back here with `?setup=<id>` to open its details.
 */
import { ref, computed, onMounted, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { message, Modal } from 'ant-design-vue';
import { PlusOutlined, AppstoreAddOutlined, EllipsisOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';
import { uploadUrl } from '../../lib/uploadUrl';
import { t, i18n } from '../../i18n';
import { useFormat } from '../../composables/useFormat';
import { useBookingTypes, typeLabel, typeIcon } from './bookingTypes';

const route = useRoute();
const router = useRouter();
const { money } = useFormat();
const bookingTypes = useBookingTypes();

const ready = ref(true);
const loading = ref(false);
const rows = ref([]);
const counts = ref({ all: 0, set_up: 0, website: 0, not_set_up: 0 });
const categories = ref([]);
const enabledTypes = ref([]);
const staffList = ref([]);
const status = ref('all');
const search = ref('');
const categoryId = ref(undefined);
const selected = ref([]);

/** t() with named {params}; the English fallback gets the same substitution. */
function tp(key, fallback, params) {
  const out = i18n.global.t(key, params);
  if (out !== key) return out;
  return Object.entries(params).reduce((s, [k, v]) => s.replace(`{${k}}`, v), fallback);
}
function duration(min) {
  const m = Number(min) || 0;
  const h = Math.floor(m / 60);
  const r = m % 60;
  if (!h) return tp('Bkm_N_Minutes', '{n} min', { n: r });
  if (!r) return tp('Bkm_N_Hours', '{n} h', { n: h });
  return tp('Bkm_N_Hours_Minutes', '{h} h {m} min', { h, m: r });
}
function bufferLabel(d) {
  if (!d.buffer_before && !d.buffer_after) return t('Bkm_No_Buffer', 'None');
  const parts = [];
  if (d.buffer_before) parts.push(tp('Bkm_Before_N', '{d} before', { d: duration(d.buffer_before) }));
  if (d.buffer_after) parts.push(tp('Bkm_After_N', '{d} after', { d: duration(d.buffer_after) }));
  return parts.join(' · ');
}

const statusOptions = computed(() => [
  { value: 'all', label: `${t('Bkm_All', 'All')} (${counts.value.all})` },
  { value: 'set_up', label: `${t('Bkm_Bookable', 'Bookable')} (${counts.value.set_up})` },
  { value: 'website', label: `${t('Bkm_On_Website', 'On website')} (${counts.value.website})` },
  { value: 'not_set_up', label: `${t('Bkm_Not_Set_Up', 'Not set up')} (${counts.value.not_set_up})` },
]);
const priceDisplayOptions = computed(() => [
  { value: 'from', label: t('Bkm_Price_From', 'From price') },
  { value: 'fixed', label: t('Bkm_Price_Fixed', 'Fixed price') },
  { value: 'hidden', label: t('Bkm_Price_Hidden', 'Hide price') },
]);
function priceDisplayLabel(v) {
  return priceDisplayOptions.value.find((o) => o.value === v)?.label || '';
}
const modeOptions = computed(() => [
  { value: 'both', label: t('Bkm_Modes_Both', 'Pick a time or send a request') },
  { value: 'slot', label: t('Bkm_Modes_Slot', 'Pick a time only') },
  { value: 'request', label: t('Bkm_Modes_Request', 'Send a request only') },
]);
const multiType = computed(() => enabledTypes.value.length > 1);
const columns = computed(() => [
  { title: t('Bkm_Service', 'Service'), key: 'service' },
  { title: t('Price'), key: 'price', width: 130 },
  { title: t('Bkm_Duration'), key: 'duration', width: 120 },
  { title: t('Bkm_Buffer'), key: 'buffer', width: 150 },
  { title: t('Bkm_Seats'), key: 'seats', width: 100 },
  ...(staffList.value.length ? [{ title: t('Bkm_Staff', 'Staff'), key: 'staff', width: 170 }] : []),
  ...(multiType.value ? [{ title: t('Bkm_Business_Type'), key: 'type', width: 150 }] : []),
  { title: t('Bkm_Website'), key: 'website', width: 150 },
  { title: '', key: 'actions', width: 130, align: 'right' },
]);
const emptyText = computed(() => (counts.value.all ? t('Bkm_No_Matching_Services') : t('Bkm_No_Services_Yet')));

function staffNames(ids) {
  const by = new Map(staffList.value.map((s) => [s.id, s.name]));
  return ids.map((id) => by.get(id)).filter(Boolean).join(', ') || t('Bkm_Anyone', 'Anyone');
}

function iconFor(record) {
  return typeIcon(record.detail?.business_type || enabledTypes.value[0]);
}

let searchTimer = null;
function onSearchInput() {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(load, 350);
}

async function load() {
  loading.value = true;
  try {
    const data = await http.get('bookings/services', {
      status: status.value, search: search.value || undefined, category_id: categoryId.value || undefined,
    });
    ready.value = data?.ready !== false;
    rows.value = data?.rows || [];
    if (data?.counts) counts.value = data.counts;
    categories.value = data?.categories || [];
    enabledTypes.value = data?.types || [];
    staffList.value = data?.staff || [];
    deposits.value = data?.deposits || { ready: false };
    const keep = new Set(rows.value.map((r) => r.id));
    selected.value = selected.value.filter((id) => keep.has(id));
  } catch (e) {
    message.error(t('Bkm_Could_Not_Load_Services'));
  } finally {
    loading.value = false;
  }
}

function addService() {
  router.push({ path: '/products/create', query: { type: 'is_service', return: '/bookings/services' } });
}

// ----- deposit (phase 8) -----
const deposits = ref({ ready: false });
const depositKinds = () => ({
  none: t('Bkm_Deposit_None', 'No deposit'),
  fixed: t('Bkm_Deposit_Fixed', 'A fixed amount'),
  percent: t('Bkm_Deposit_Percent_Of', 'A percentage of the price'),
  full: t('Bkm_Deposit_Full', 'The full price'),
});
const depositChoiceOptions = computed(() => {
  const kinds = depositKinds();
  return [{ value: 'default', label: t('Bkm_Deposit_Use_Default', 'Same as Booking Settings') }]
    .concat(Object.entries(kinds).map(([value, label]) => ({ value, label })));
});
const defaultDepositText = computed(() => {
  const d = deposits.value.default;
  if (!d) return '';
  const kinds = depositKinds();
  const what = d.type === 'fixed' ? `${kinds.fixed}: ${money(d.value)}` : d.type === 'percent' ? `${d.value}%` : kinds[d.type] || kinds.none;
  return tp('Bkm_Deposit_Default_Is', 'Booking Settings: {what}', { what });
});

// ----- booking details drawer -----
const drawerOpen = ref(false);
const saving = ref(false);
const editing = ref(null);
const form = ref({});
const typeChoices = computed(() => {
  const list = [...enabledTypes.value];
  const current = form.value.business_type;
  if (current && !list.includes(current)) list.push(current);
  return list.map((v) => ({ value: v, label: typeLabel(v) }));
});

function openEdit(record) {
  editing.value = record;
  const d = record.detail;
  form.value = d ? { ...d } : {
    business_type: enabledTypes.value.length === 1 ? enabledTypes.value[0] : undefined,
    duration_minutes: 60, buffer_before: 0, buffer_after: 0, seats: 1, price_display: 'from',
    booking_modes: 'both', show_on_website: true, featured: false, sort_order: 0, short_description: '',
  };
  if (!form.value.business_type) form.value.business_type = undefined;
  form.value.deposit_choice = d && d.deposit_type ? d.deposit_type : 'default';
  form.value.deposit_value = d && d.deposit_value !== null && d.deposit_value !== undefined ? d.deposit_value : null;
  // Only staff still on the list; a removed person drops out of the links.
  const live = new Set(staffList.value.map((s) => s.id));
  form.value.staff_ids = (record.staff_ids || []).filter((id) => live.has(id));
  drawerOpen.value = true;
}

async function saveDetail() {
  if (!form.value.duration_minutes) {
    message.error(t('Bkm_Duration_Required'));
    return;
  }
  saving.value = true;
  try {
    const { staff_ids: staffIds, deposit_choice: depositChoice, deposit_type: _type, deposit_value: depositValue, ...detail } = form.value;
    const data = await http.put(`bookings/services/${editing.value.id}`, {
      ...detail,
      ...(staffList.value.length ? { staff_ids: staffIds || [] } : {}),
      ...(deposits.value.ready ? {
        deposit_type: depositChoice === 'default' ? null : depositChoice,
        deposit_value: depositChoice === 'fixed' || depositChoice === 'percent' ? (depositValue || 0) : null,
      } : {}),
      business_type: form.value.business_type || null,
      featured: !!(form.value.featured && form.value.show_on_website),
      short_description: form.value.short_description || null,
    });
    message.success(t('Updated_in_successfully'));
    drawerOpen.value = false;
    if (data?.row) {
      const i = rows.value.findIndex((r) => r.id === data.row.id);
      if (i >= 0) rows.value.splice(i, 1, data.row);
    }
    load();
  } catch (e) {
    const errors = e?.data?.errors;
    const first = errors ? Object.values(errors)[0]?.[0] : null;
    message.error(first || e?.data?.message || t('Bkm_Could_Not_Save_Service'));
  } finally {
    saving.value = false;
  }
}

function confirmRemove(record) {
  Modal.confirm({
    title: tp('Bkm_Remove_Confirm_Title', 'Stop taking bookings for {name}?', { name: record.name }),
    content: t('Bkm_Remove_Confirm_Body'),
    okText: t('Bkm_Remove_From_Bookings'),
    okType: 'danger',
    async onOk() {
      try {
        await http.delete(`bookings/services/${record.id}`);
        message.success(t('Updated_in_successfully'));
        load();
      } catch (e) {
        message.error(e?.data?.message || t('Bkm_Could_Not_Save_Service'));
      }
    },
  });
}

async function bulk(action) {
  try {
    const data = await http.post('bookings/services/bulk', { product_ids: selected.value, action });
    message.success(tp('Bkm_Bulk_Done', '{n} services updated', { n: data?.changed ?? 0 }));
    selected.value = [];
    load();
  } catch (e) {
    message.error(e?.data?.message || t('Bkm_Could_Not_Save_Service'));
  }
}

// ----- suggested categories -----
const suggestOpen = ref(false);
const suggestLoading = ref(false);
const suggestSaving = ref(false);
const suggestGroups = ref([]);
const suggestPicked = ref([]);

async function openSuggestions() {
  suggestOpen.value = true;
  suggestPicked.value = [];
  suggestLoading.value = true;
  try {
    const data = await http.get('bookings/services/suggested-categories');
    suggestGroups.value = data?.groups || [];
  } catch (e) {
    message.error(t('Bkm_Could_Not_Load_Services'));
  } finally {
    suggestLoading.value = false;
  }
}
function toggleSuggested(name) {
  suggestPicked.value = suggestPicked.value.includes(name)
    ? suggestPicked.value.filter((n) => n !== name)
    : [...suggestPicked.value, name];
}
async function createSuggested() {
  suggestSaving.value = true;
  try {
    const data = await http.post('bookings/services/suggested-categories', { names: suggestPicked.value });
    message.success(tp('Bkm_Categories_Created', '{n} categories created', { n: (data?.created || []).length }));
    suggestOpen.value = false;
  } catch (e) {
    message.error(e?.status === 403 ? t('Bkm_Need_Category_Permission') : (e?.data?.message || t('Bkm_Could_Not_Save_Service')));
  } finally {
    suggestSaving.value = false;
  }
}

/** ?setup=<productId> (back from Add service): open that service's details once it is listed. */
function openFromQuery() {
  const id = Number(route.query.setup);
  if (!id) return;
  const record = rows.value.find((r) => r.id === id);
  if (record) openEdit(record);
  router.replace({ query: { ...route.query, setup: undefined } });
}

watch(() => route.query.setup, (v) => { if (v) load().then(openFromQuery); });

onMounted(async () => {
  await load();
  openFromQuery();
  bookingTypes.ready();
});
</script>

<style scoped>
.block {
  margin-bottom: 16px;
}
.toolbar {
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  align-items: center;
  margin-bottom: 12px;
}
.bulk-bar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px;
  padding: 8px 12px;
  margin-bottom: 12px;
  border-radius: 10px;
  background: rgba(var(--app-primary-rgb, 109, 40, 217), 0.07);
}
.muted { color: rgba(0, 0, 0, 0.55); }
.small { font-size: 12px; }
.no-mb { margin-bottom: 0; }
.svc {
  display: flex;
  align-items: center;
  gap: 10px;
  min-width: 0;
}
.thumb {
  width: 40px;
  height: 40px;
  flex: none;
  border-radius: 8px;
  object-fit: cover;
}
.thumb-empty {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  background: rgba(128, 128, 128, 0.12);
}
.svc-text {
  display: flex;
  flex-direction: column;
  min-width: 0;
}
.svc-name {
  font-weight: 600;
}
.tag-sm {
  margin-inline-start: 4px;
}
.actions {
  display: inline-flex;
  gap: 6px;
}
.presets {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  margin-top: 8px;
}
.help-tight {
  margin: -8px 0 16px;
}
.web-box {
  display: flex;
  flex-direction: column;
  gap: 12px;
  padding: 12px;
  border: 1px solid rgba(128, 128, 128, 0.2);
  border-radius: 10px;
}
.switch-row {
  display: flex;
  align-items: center;
  gap: 10px;
  cursor: pointer;
}
.staff-checks {
  display: flex;
  flex-wrap: wrap;
  gap: 8px 16px;
}
.dot {
  display: inline-block;
  width: 10px;
  height: 10px;
  border-radius: 50%;
  margin-inline-end: 4px;
  vertical-align: middle;
}
.drawer-footer {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
}
.suggest-group {
  margin-bottom: 14px;
}
.suggest-head {
  display: flex;
  align-items: center;
  gap: 6px;
  font-weight: 600;
  margin-bottom: 6px;
}
.suggest-list {
  display: flex;
  flex-wrap: wrap;
  gap: 8px 16px;
}
</style>
