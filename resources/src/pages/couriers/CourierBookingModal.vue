<template>
  <a-modal
    :open="open" :title="$t('Cr_Send_To_Courier') + (sale.Ref ? ' · ' + sale.Ref : '')"
    :confirm-loading="saving" :ok-text="$t('Cr_Book')" :ok-button-props="{ disabled: loading || !account || alreadyBooked }"
    width="760px" @ok="submit" @cancel="$emit('update:open', false)"
  >
    <a-spin :spinning="loading">
      <a-alert v-if="!loading && !accounts.length" type="warning" show-icon style="margin-top: 12px" :message="$t('Cr_No_Active_Accounts')">
        <template v-if="auth.can('courier_settings')" #description>
          <a @click="goAccounts">{{ $t('Cr_Add_Account') }}</a>
        </template>
      </a-alert>
      <a-alert v-else-if="alreadyBooked" type="info" show-icon style="margin-top: 12px" :message="$t('Cr_Already_Booked')" />

      <a-form v-if="accounts.length" layout="vertical" style="margin-top: 12px">
        <a-row :gutter="16">
          <a-col :xs="24" :sm="12">
            <a-form-item :label="$t('Cr_Courier_Account') + ' *'">
              <a-select v-model:value="accountId" :options="accountOptions" @change="reload" />
            </a-form-item>
          </a-col>
          <a-col v-if="needsTracking" :xs="24" :sm="12">
            <a-form-item :label="$t('Cr_Tracking_Number') + ' *'" :extra="$t('Cr_Tracking_Number_Help')">
              <a-input v-model:value="form.tracking_number" :maxlength="100" />
            </a-form-item>
          </a-col>
        </a-row>

        <a-alert
          v-if="sale.return_risk && sale.return_risk.level === 'high'" type="error" show-icon style="margin-bottom: 4px"
          :message="$t('Cr_Risk_High_Warning', { returned: sale.return_risk.returned, finished: sale.return_risk.finished })"
        />
        <a-divider orientation="left" class="section">
          {{ $t('Cr_Receiver') }}
          <ReturnRiskBadge v-if="sale.return_risk" :risk="sale.return_risk" style="margin-left: 8px" />
        </a-divider>
        <a-row :gutter="16">
          <a-col :xs="24" :sm="12">
            <a-form-item :label="$t('Name') + ' *'"><a-input v-model:value="form.consignee_name" /></a-form-item>
          </a-col>
          <a-col :xs="24" :sm="12">
            <a-form-item :label="$t('Phone') + ' *'"><a-input v-model:value="form.consignee_phone" /></a-form-item>
          </a-col>
          <a-col :xs="24">
            <a-form-item :label="$t('Address') + ' *'"><a-input v-model:value="form.address" /></a-form-item>
          </a-col>
          <a-col :xs="24" :sm="cityPicker ? 8 : 12">
            <a-form-item :label="$t('City') + ' *'"><a-input v-model:value="form.city" /></a-form-item>
          </a-col>
          <a-col v-if="cityPicker" :xs="24" :sm="16">
            <a-form-item :label="$t('Cr_Courier_City') + ' *'" :extra="$t('Cr_Courier_City_Help')">
              <a-select
                v-model:value="cityValue" show-search :filter-option="false" :options="cityOptions"
                :not-found-content="citySearching ? undefined : (cityError || $t('Cr_No_Match'))" allow-clear
                @search="searchCities" @focus="searchCities(form.city, true)" @change="pickCity"
              />
            </a-form-item>
          </a-col>
          <a-col v-else :xs="24" :sm="12">
            <a-form-item :label="$t('Email')"><a-input v-model:value="form.consignee_email" /></a-form-item>
          </a-col>
        </a-row>

        <a-row v-if="structuredAddress" :gutter="16">
          <a-col :xs="12" :sm="8">
            <a-form-item :label="$t('Cr_State')"><a-input v-model:value="form.state" /></a-form-item>
          </a-col>
          <a-col :xs="12" :sm="8">
            <a-form-item :label="$t('Cr_Postal_Code')"><a-input v-model:value="form.zip" /></a-form-item>
          </a-col>
          <a-col :xs="24" :sm="8">
            <a-form-item :label="$t('Country')"><a-input v-model:value="form.country" /></a-form-item>
          </a-col>
        </a-row>

        <a-row v-if="needsCoordinates" :gutter="16">
          <a-col :xs="12" :sm="8">
            <a-form-item :label="$t('Cr_Latitude') + ' *'"><a-input-number v-model:value="form.latitude" :min="-90" :max="90" :step="0.000001" style="width: 100%" /></a-form-item>
          </a-col>
          <a-col :xs="12" :sm="8">
            <a-form-item :label="$t('Cr_Longitude') + ' *'"><a-input-number v-model:value="form.longitude" :min="-180" :max="180" :step="0.000001" style="width: 100%" /></a-form-item>
          </a-col>
          <a-col :xs="24" :sm="8" class="coord-hint">{{ $t('Cr_Coordinates_Help') }}</a-col>
        </a-row>

        <a-divider orientation="left" class="section">{{ $t('Cr_Parcel') }}</a-divider>
        <a-row :gutter="16">
          <a-col :xs="12" :sm="6">
            <a-form-item :label="$t('Cr_COD_Amount')" :extra="$t('Cr_COD_Amount_Help')">
              <a-input-number v-model:value="form.cod_amount" :min="0" :step="1" style="width: 100%" />
            </a-form-item>
          </a-col>
          <a-col :xs="12" :sm="6">
            <a-form-item :label="$t('Cr_Default_Weight')">
              <a-input-number v-model:value="form.weight" :min="0.001" :step="0.1" style="width: 100%" />
            </a-form-item>
          </a-col>
          <a-col :xs="12" :sm="6">
            <a-form-item :label="selected?.capabilities?.pieces_are_items ? $t('Items') : $t('Cr_Pieces')">
              <a-input-number v-model:value="form.pieces" :min="1" :max="999" style="width: 100%" />
            </a-form-item>
          </a-col>
          <a-col :xs="12" :sm="6">
            <a-form-item :label="serviceLabel">
              <a-input v-model:value="form.service" :maxlength="60" />
            </a-form-item>
          </a-col>
          <a-col :xs="24">
            <a-form-item :label="$t('Cr_Contents')"><a-input v-model:value="form.items_description" :maxlength="250" /></a-form-item>
          </a-col>
          <a-col :xs="24">
            <a-form-item :label="$t('Cr_Instructions')" style="margin-bottom: 8px">
              <a-input v-model:value="form.notes" :maxlength="500" />
            </a-form-item>
          </a-col>
        </a-row>
        <div v-if="selected && selected.capabilities.rates" class="rates">
          <a-button :loading="ratesLoading" @click="loadRates">{{ $t('Cr_Compare_Rates') }}</a-button>
          <span class="rates-hint">{{ $t('Cr_Rates_Hint') }}</span>
          <a-radio-group v-if="rates.length" v-model:value="form.service" class="rate-list">
            <a-radio v-for="r in rates" :key="r.id" :value="r.id">
              <strong>{{ r.carrier }}</strong> · {{ r.service }} — {{ r.currency }} {{ Number(r.amount).toFixed(2) }}
              <span v-if="r.days" class="rates-hint">({{ $t('Cr_Days', { n: r.days }) }})</span>
            </a-radio>
          </a-radio-group>
        </div>
        <span v-if="selected && selected.capabilities.cod && selected.courier !== 'manual' && !needsTracking">
          <a-switch v-model:checked="form.allow_open_parcel" />
          <span style="margin-left: 8px">{{ $t('Cr_Allow_Open_Parcel') }}</span>
        </span>
      </a-form>
    </a-spin>
  </a-modal>
</template>

<script setup>
/**
 * Book one sale with a courier. GET couriers/sales/{id}/booking[?account_id]
 * → {sale, accounts, account_id, form, already_booked};
 * POST couriers/sales/{id}/book {account_id, ...form} → {shipment}.
 * Couriers that need a typed tracking number (manual, AfterShip, 17TRACK)
 * show the field; couriers with their own city list get a city picker.
 */
import { ref, computed, watch } from 'vue';
import { useRouter } from 'vue-router';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import http from '../../lib/http';
import { useAuthStore } from '../../stores/auth';
import { errorText } from './courierShared';
import ReturnRiskBadge from './ReturnRiskBadge.vue';

const props = defineProps({
  open: { type: Boolean, default: false },
  saleId: { type: [Number, String], default: null },
});
const emit = defineEmits(['update:open', 'booked']);

const { t } = useI18n();
const router = useRouter();
const auth = useAuthStore();

const loading = ref(false);
const saving = ref(false);
const sale = ref({});
const accounts = ref([]);
const accountId = ref(null);
const alreadyBooked = ref(false);
const form = ref({});
const cityOptions = ref([]);
const cityValue = ref(undefined);
const citySearching = ref(false);
// The courier's city lookup failure (bad token, courier down) — shown in the dropdown, not hidden as "no match".
const cityError = ref('');

const accountOptions = computed(() => accounts.value.map(a => ({ value: a.id, label: `${a.name} · ${a.courier_name}` })));
const selected = computed(() => accounts.value.find(a => a.id === accountId.value) || null);
const account = computed(() => selected.value);
const needsTracking = computed(() => !!selected.value?.capabilities?.manual_tracking);
const cityPicker = computed(() => !!selected.value?.capabilities?.cities);
const serviceLabel = computed(() => {
  if (selected.value?.capabilities?.rates) return t('Cr_Service_Preference');
  return needsTracking.value && selected.value?.courier !== 'manual' ? t('Cr_Carrier_Code') : t('Cr_Service_Type');
});
// Map-routed couriers (e.g. Lalamove) need the drop-off point.
const needsCoordinates = computed(() => !!selected.value?.capabilities?.coordinates);
// International aggregators need the full postal address.
const structuredAddress = computed(() => !!selected.value?.capabilities?.rates);
const rates = ref([]);
const ratesLoading = ref(false);

async function loadRates() {
  ratesLoading.value = true;
  rates.value = [];
  try {
    const resp = await http.post(`couriers/sales/${props.saleId}/rates`, { ...form.value, account_id: accountId.value, allow_open_parcel: form.value.allow_open_parcel ? 1 : 0 });
    rates.value = resp.rates || [];
    if (!rates.value.length) message.info(t('Cr_No_Rates'));
    else form.value.service = rates.value[0].id;
  } catch (e) {
    message.error(errorText(e, t('Failed')));
  } finally {
    ratesLoading.value = false;
  }
}

async function load(withAccount) {
  if (!props.saleId) return;
  loading.value = true;
  try {
    const resp = await http.get(`couriers/sales/${props.saleId}/booking`, withAccount ? { account_id: accountId.value } : undefined);
    sale.value = resp.sale || {};
    accounts.value = resp.accounts || [];
    accountId.value = resp.account_id || accounts.value[0]?.id || null;
    alreadyBooked.value = !!resp.already_booked;
    form.value = { ...resp.form };
    cityValue.value = form.value.courier_city_name ? `${form.value.courier_city_code ?? ''}${CITY_SEP}${form.value.courier_city_name}` : undefined;
    cityOptions.value = cityValue.value ? [{ value: cityValue.value, label: form.value.courier_city_name }] : [];
  } catch (e) {
    message.error(errorText(e, t('Failed')));
    emit('update:open', false);
  } finally {
    loading.value = false;
  }
}

function reload() {
  rates.value = [];
  // Keep what the user typed; take the account-specific defaults.
  const typed = { ...form.value };
  load(true).then(() => {
    form.value = {
      ...form.value,
      consignee_name: typed.consignee_name, consignee_phone: typed.consignee_phone,
      address: typed.address, city: typed.city, cod_amount: typed.cod_amount,
      items_description: typed.items_description, notes: typed.notes,
    };
  });
}

let searchTimer = null;
// Option value = code + separator + name. Courier codes may contain '|' (eCourier "City|Thana"),
// so the separator is a control character no code or name contains.
const CITY_SEP = '\u001f';

// fromFocus: q is the sale's own City (may be misspelt) — when it matches nothing, list all cities instead.
function searchCities(q, fromFocus = false) {
  if (!accountId.value) return;
  clearTimeout(searchTimer);
  searchTimer = setTimeout(async () => {
    citySearching.value = true;
    cityError.value = '';
    try {
      const url = `couriers/accounts/${accountId.value}/cities`;
      let resp = await http.get(url, { q: q || '' });
      if (fromFocus && q && !(resp.cities || []).length) {
        resp = await http.get(url, { q: '' });
      }
      cityOptions.value = (resp.cities || []).map(c => ({ value: `${c.code ?? ''}${CITY_SEP}${c.name}`, label: c.name }));
    } catch (e) {
      cityOptions.value = [];
      cityError.value = errorText(e, t('Failed'));
    } finally {
      citySearching.value = false;
    }
  }, 250);
}

function pickCity(v) {
  if (!v) {
    form.value.courier_city_code = null;
    form.value.courier_city_name = null;
    return;
  }
  const i = v.indexOf(CITY_SEP);
  form.value.courier_city_code = v.slice(0, i) || null;
  form.value.courier_city_name = v.slice(i + CITY_SEP.length);
}

async function submit() {
  saving.value = true;
  try {
    const resp = await http.post(`couriers/sales/${props.saleId}/book`, {
      ...form.value,
      account_id: accountId.value,
      allow_open_parcel: form.value.allow_open_parcel ? 1 : 0,
    });
    message.success(t('Cr_Booked_Tracking', { n: resp.shipment.tracking_number }));
    emit('booked', resp.shipment);
    emit('update:open', false);
  } catch (e) {
    message.error(errorText(e, t('InvalidData')));
  } finally {
    saving.value = false;
  }
}

function goAccounts() {
  emit('update:open', false);
  router.push('/couriers/accounts');
}

watch(() => [props.open, props.saleId], ([open]) => {
  if (open) load(false);
}, { immediate: true });
</script>

<style scoped>
.section {
  font-size: 13px;
  margin: 4px 0 12px;
}
.rates {
  margin-bottom: 12px;
}
.coord-hint {
  font-size: 12px;
  color: rgba(var(--ink-rgb), 0.45);
  padding-top: 30px;
}
.rates-hint {
  margin-left: 8px;
  font-size: 12px;
  color: rgba(var(--ink-rgb), 0.45);
}
.rate-list {
  display: flex;
  flex-direction: column;
  gap: 6px;
  margin-top: 10px;
}
</style>
