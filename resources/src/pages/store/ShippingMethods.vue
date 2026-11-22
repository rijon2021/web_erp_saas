<template>
  <div class="page">
    <PageHeader :title="$t('Shipping_Methods')" :breadcrumb="[$t('Store'), $t('Shipping_Methods')]">
      <template #extra>
        <a-button type="primary" @click="openCreate">
          <template #icon><PlusOutlined /></template>
          {{ $t('Add') }}
        </a-button>
      </template>
    </PageHeader>

    <a-card size="small" :body-style="{ padding: 0 }">
      <div class="toolbar">
        <a-input-search v-model:value="search" :placeholder="$t('Search')" allow-clear style="max-width: 280px" @search="fetch" @change="fetch" />
      </div>
      <a-table
        :columns="columns" :data-source="methods" :loading="isLoading"
        :pagination="{ pageSize: 10, showSizeChanger: true }" size="middle" row-key="id"
        :locale="{ emptyText: $t('No_Shipping_Methods') }"
      >
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'name'"><strong>{{ record.name }}</strong></template>
          <template v-else-if="column.key === 'price'">
            <a-tag v-if="record.type === 'shippo'" color="blue">{{ $t('Lsr_Live_Badge') }}<template v-if="providerOf(record)"> · {{ providerOf(record) }}</template></a-tag>
            <a-tag v-else-if="record.type === 'printful'" color="purple">{{ $t('Pf_Rates_Badge') }}</a-tag>
            <span v-else>{{ fmtNum(record.price || 0) }}</span>
          </template>
          <template v-else-if="column.key === 'regions'">
            <span v-if="!record.countries.length" style="color: rgba(var(--ink-rgb), 0.45)">{{ $t('All_Regions') }}</span>
            <span v-else>{{ record.countries.join(', ') }}</span>
          </template>
          <template v-else-if="column.key === 'status'">
            <a-tag :color="record.active ? 'success' : 'default'">
              {{ record.active ? $t('Active') : $t('Inactive') }}
            </a-tag>
          </template>
          <template v-else-if="column.key === 'actions'">
            <a-space>
              <a-button size="small" @click="openEdit(record)">
                <template #icon><EditOutlined /></template>
              </a-button>
              <a-button size="small" danger @click="confirmDelete(record)">
                <template #icon><DeleteOutlined /></template>
              </a-button>
            </a-space>
          </template>
        </template>
      </a-table>
    </a-card>

    <a-modal v-model:open="modalOpen" :title="form.id ? $t('Edit') : $t('Add')" :confirm-loading="saving" :width="isLive ? 640 : 520" @ok="submit">
      <a-form layout="vertical" style="margin-top: 12px">
        <a-form-item :label="$t('Name') + ' *'">
          <a-input v-model:value="form.name" />
        </a-form-item>
        <a-form-item v-if="liveSupported" :label="$t('Lsr_Pricing')">
          <a-radio-group v-model:value="form.type" button-style="solid">
            <a-radio-button value="flat">{{ $t('Lsr_Type_Flat') }}</a-radio-button>
            <a-radio-button value="shippo">{{ $t('Lsr_Type_Live') }}</a-radio-button>
            <a-radio-button v-if="printfulAvailable || form.type === 'printful'" value="printful">{{ $t('Pf_Rates_Type') }}</a-radio-button>
          </a-radio-group>
        </a-form-item>

        <a-form-item v-if="!isLive" :label="$t('Price') + ' *'">
          <a-input-number v-model:value="form.price" :min="0" :step="0.01" style="width: 100%" />
        </a-form-item>

        <template v-else>
          <a-alert v-if="!isShippo" type="info" show-icon :message="$t('Pf_Rates_Help')" style="margin-bottom: 16px" />
          <a-alert v-else type="info" show-icon :message="$t('Lsr_Live_Help')" style="margin-bottom: 16px" />
          <a-form-item v-if="isShippo" :label="$t('Lsr_Provider')">
            <a-radio-group v-model:value="provider" @change="onProviderChange">
              <a-radio v-for="c in liveCouriers" :key="c.code" :value="c.code">{{ c.name }}</a-radio>
            </a-radio-group>
          </a-form-item>
          <a-alert v-if="isShippo && !accountOptions.length" type="warning" show-icon style="margin-bottom: 16px">
            <template #message>
              {{ $t('Lsr_No_Account', { courier: providerName }) }}
              <router-link :to="{ name: 'courier-accounts', query: { courier: provider } }">{{ $t('Lsr_Add_Account', { courier: providerName }) }}</router-link>
            </template>
          </a-alert>
          <a-alert v-if="!isShippo && !printfulAvailable" type="warning" show-icon style="margin-bottom: 16px">
            <template #message>
              {{ $t('Pf_Rates_Not_Connected') }}
              <router-link :to="{ name: 'printful-settings' }">Printful</router-link>
            </template>
          </a-alert>
          <a-form-item v-if="isShippo && accountOptions.length" :label="$t('Lsr_Account') + ' *'">
            <a-select v-model:value="form.live.courier_account_id" :options="accountOptions" :placeholder="$t('Lsr_Account')" />
          </a-form-item>
          <a-row :gutter="16">
            <a-col :xs="24" :sm="12">
              <a-form-item :label="$t('Lsr_Handling_Fee')" :extra="$t('Lsr_Handling_Fee_Help')">
                <a-input-number v-model:value="form.live.handling_fee" :min="0" :step="0.5" style="width: 100%" />
              </a-form-item>
            </a-col>
            <a-col :xs="24" :sm="12">
              <a-form-item :label="$t('Lsr_Handling_Percent')" :extra="$t('Lsr_Handling_Percent_Help')">
                <a-input-number v-model:value="form.live.handling_percent" :min="0" :max="500" :step="1" addon-after="%" style="width: 100%" />
              </a-form-item>
            </a-col>
            <a-col :xs="24" :sm="12">
              <a-form-item :label="$t('Lsr_Free_Over')" :extra="$t('Lsr_Free_Over_Help')">
                <a-input-number v-model:value="form.live.free_over" :min="0" :step="1" style="width: 100%" />
              </a-form-item>
            </a-col>
            <a-col :xs="24" :sm="12">
              <a-form-item :label="$t('Lsr_Fallback_Price')" :extra="$t('Lsr_Fallback_Price_Help')">
                <a-input-number v-model:value="form.live.fallback_price" :min="0" :step="0.5" style="width: 100%" />
              </a-form-item>
            </a-col>
            <a-col :xs="24" :sm="12">
              <a-form-item :label="$t('Lsr_Services')" :extra="$t('Lsr_Services_Help')">
                <a-select v-model:value="form.live.services" mode="tags" :token-separators="[',']" :open="false" />
              </a-form-item>
            </a-col>
            <a-col :xs="24" :sm="12">
              <a-form-item :label="$t('Lsr_Max_Options')" :extra="$t('Lsr_Max_Options_Help')">
                <a-input-number v-model:value="form.live.max_options" :min="0" :max="50" style="width: 100%" />
              </a-form-item>
            </a-col>
          </a-row>
          <a-form-item :label="$t('Lsr_Exchange_Rate')" :extra="$t(isShippo ? 'Lsr_Exchange_Rate_Help' : 'Pf_Rates_Exchange_Help', { currency: baseCurrency })">
            <a-input-number v-model:value="form.live.exchange_rate" :min="0" :step="0.01" style="width: 100%" />
          </a-form-item>
        </template>

        <a-form-item :label="$t('Countries')" :extra="$t('Leave_empty_all_regions')">
          <a-select
            v-model:value="form.countries" mode="multiple" allow-clear
            :placeholder="$t('Choose_Countries')"
            :options="countryOptions" show-search option-filter-prop="label" :max-tag-count="6"
          />
        </a-form-item>
        <a-form-item :label="$t('Sort_Order')">
          <a-input-number v-model:value="form.sort_order" :min="0" style="width: 100%" />
        </a-form-item>
        <a-form-item :label="$t('Status')" style="margin-bottom: 0">
          <a-switch v-model:checked="form.active" />
          <span style="margin-left: 8px">{{ form.active ? $t('Active') : $t('Inactive') }}</span>
        </a-form-item>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
import { useFormat } from '../../composables/useFormat';
// Shared money formatter (Price Format separators, precision, Hide Zero Decimals).
const { number: fmtNum } = useFormat();
/**
 * Shipping methods — GET store/shipping-methods?search → {methods,
 * live_supported, live_accounts, base_currency}; save POST/PUT
 * store/shipping-methods[/{id}] {name, price, countries[], sort_order,
 * active 1|0, type flat|shippo|printful, live_settings{…}}. Countries are picked from
 * the canonical list the region matcher understands (empty = all regions) —
 * free text used to let a spelling drift from the customer's address and read
 * as "no shipping". A "shippo" method (the type name predates Shipbubble) has
 * no price: checkout asks the chosen courier account — Shippo or Shipbubble,
 * from live_couriers — for real carrier rates (live_supported is false until
 * the tenant migration has run).
 */
import { ref, computed, onMounted } from 'vue';
import { message, Modal } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { PlusOutlined, EditOutlined, DeleteOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';

const { t } = useI18n();

const isLoading = ref(true);
const saving = ref(false);
const search = ref('');
const methods = ref([]);
// Canonical country list from the API: [{code, name, canonical}].
const countries = ref([]);
const countryOptions = computed(() =>
  countries.value.map(x => ({ label: x.name, value: x.canonical })));
const liveSupported = ref(false);
const liveAccounts = ref([]);
// Couriers that can quote live rates: [{code, name}].
const liveCouriers = ref([{ code: 'shippo', name: 'Shippo' }]);
const provider = ref('shippo');
const providerName = computed(() => liveCouriers.value.find(c => c.code === provider.value)?.name || provider.value);
const baseCurrency = ref('USD');
const accountOptions = computed(() => liveAccounts.value.filter(a => (a.courier || 'shippo') === provider.value).map(a => ({
  value: a.id, label: a.mode === 'test' ? `${a.name} (${t('Lsr_Test_Mode')})` : a.name,
})));
const providerOf = (m) => liveAccounts.value.find(a => a.id === m.live_settings?.courier_account_id)?.courier_name || '';
// The provider of an account, else the first one that has an account.
function pickProvider(accountId) {
  const own = liveAccounts.value.find(a => a.id === accountId);
  provider.value = own?.courier || liveAccounts.value[0]?.courier || liveCouriers.value[0]?.code || 'shippo';
  if (!own) onProviderChange();
}
// Switching provider: keep a matching account, else take its only one.
function onProviderChange() {
  const options = accountOptions.value;
  if (!options.some(o => o.value === form.value.live.courier_account_id)) {
    form.value.live.courier_account_id = options.length === 1 ? options[0].value : undefined;
  }
}
const modalOpen = ref(false);

const emptyLive = () => ({
  courier_account_id: undefined, handling_fee: null, handling_percent: null, free_over: null,
  fallback_price: null, exchange_rate: null, max_options: 0, services: [],
});
const emptyForm = () => ({ id: null, name: '', price: 0, countries: [], sort_order: 0, active: true, type: 'flat', live: emptyLive() });
const form = ref(emptyForm());
const printfulAvailable = ref(false);
const isLive = computed(() => liveSupported.value && ['shippo', 'printful'].includes(form.value.type));
const isShippo = computed(() => isLive.value && form.value.type === 'shippo');

const columns = computed(() => [
  { title: t('Name'), key: 'name' },
  { title: t('Price'), key: 'price', align: 'right' },
  { title: t('Regions'), key: 'regions' },
  { title: t('Status'), key: 'status', width: 100 },
  { title: t('Actions'), key: 'actions', width: 100 },
]);

async function fetch() {
  try {
    const resp = await http.get('store/shipping-methods', { search: search.value });
    methods.value = resp.methods || [];
    countries.value = resp.country_options || [];
    liveSupported.value = !!resp.live_supported;
    liveAccounts.value = resp.live_accounts || [];
    if (resp.live_couriers?.length) liveCouriers.value = resp.live_couriers;
    printfulAvailable.value = !!resp.printful_available;
    baseCurrency.value = resp.base_currency || 'USD';
  } catch (e) {
    message.error(t('Failed'));
  } finally {
    isLoading.value = false;
  }
}
function openCreate() {
  form.value = emptyForm();
  pickProvider(undefined);
  modalOpen.value = true;
}
function openEdit(m) {
  const s = m.live_settings || {};
  form.value = {
    id: m.id, name: m.name, price: m.price,
    countries: (m.countries || []).slice(), sort_order: m.sort_order, active: !!m.active,
    type: m.type || 'flat',
    live: { ...emptyLive(), ...s, courier_account_id: s.courier_account_id || undefined, services: (s.services || []).slice() },
  };
  pickProvider(form.value.live.courier_account_id);
  modalOpen.value = true;
}
async function submit() {
  if (isShippo.value && !form.value.live.courier_account_id) {
    message.error(t('Lsr_Account_Required'));
    return;
  }
  saving.value = true;
  const payload = {
    name: form.value.name,
    price: isLive.value ? 0 : form.value.price,
    countries: form.value.countries,
    sort_order: form.value.sort_order,
    active: form.value.active ? 1 : 0,
  };
  if (liveSupported.value) {
    payload.type = form.value.type;
    if (isLive.value) {
      // Only courier methods carry an account (a Printful one quotes with the Printful connection).
      const { courier_account_id, ...rest } = form.value.live;
      payload.live_settings = isShippo.value ? form.value.live : rest;
    }
  }
  try {
    if (form.value.id) await http.put(`store/shipping-methods/${form.value.id}`, payload);
    else await http.post('store/shipping-methods', payload);
    message.success(t('Successfully_Updated'));
    modalOpen.value = false;
    fetch();
  } catch (e) {
    const errors = e?.data?.errors;
    message.error((errors && Object.values(errors)[0]?.[0]) || e?.data?.message || t('InvalidData'));
  } finally {
    saving.value = false;
  }
}
function confirmDelete(m) {
  Modal.confirm({
    title: t('AreYouSure'),
    content: m.name,
    okText: t('Yes'),
    okType: 'danger',
    cancelText: t('No'),
    async onOk() {
      try {
        await http.delete(`store/shipping-methods/${m.id}`);
        message.success(t('Deleted_in_successfully'));
        fetch();
      } catch (e) {
        message.error(t('Failed'));
      }
    },
  });
}

onMounted(fetch);
</script>

<style scoped>
.toolbar {
  padding: 16px;
  border-bottom: 1px solid rgba(5, 5, 5, 0.06);
}
</style>
