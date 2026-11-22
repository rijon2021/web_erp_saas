<template>
  <a-modal
    :open="open"
    :title="$t('LicK_Issue_Licenses')"
    width="640px"
    :confirm-loading="busy"
    :ok-text="$t('LicK_Issue')"
    :cancel-text="$t('Delete_cancelButtonText')"
    :mask-closable="false"
    destroy-on-close
    @update:open="v => emit('update:open', v)"
    @ok="submit"
  >
    <a-alert type="info" show-icon style="margin: 8px 0 16px" :message="$t('LicK_Issue_Help')" />
    <a-form layout="vertical">
      <a-row :gutter="12">
        <a-col :xs="24" :md="variantOptions.length ? 12 : 16">
          <a-form-item :label="$t('Product')" required>
            <a-select
              v-model:value="form.product_id"
              show-search
              option-filter-prop="label"
              :placeholder="$t('Choose_Product')"
              :options="productOptions"
              :not-found-content="$t('LicK_No_Generated_Products')"
              @change="form.product_variant_id = undefined"
            />
          </a-form-item>
        </a-col>
        <a-col v-if="variantOptions.length" :xs="24" :md="12">
          <a-form-item :label="$t('LicK_Edition')" required>
            <a-select v-model:value="form.product_variant_id" :placeholder="$t('PleaseSelect')" :options="variantOptions" />
          </a-form-item>
        </a-col>
        <a-col :xs="12" :md="8">
          <a-form-item :label="$t('LicK_How_Many')" required>
            <a-input-number v-model:value="form.count" :min="1" :max="500" :precision="0" style="width: 100%" />
          </a-form-item>
        </a-col>
        <a-col :xs="12" :md="variantOptions.length ? 16 : 24">
          <a-form-item :label="$t('LicK_Expiry_Override')" :extra="$t('LicK_Expiry_Override_Help')">
            <a-date-picker v-model:value="form.expires_at" value-format="YYYY-MM-DD" style="width: 100%" />
          </a-form-item>
        </a-col>
      </a-row>

      <a-form-item :label="$t('LicK_Customer_Optional')">
        <a-select
          v-model:value="form.client_id"
          show-search
          allow-clear
          :filter-option="false"
          :placeholder="$t('Choose_Customer')"
          :options="clientOptions"
          :loading="clientsLoading"
          @search="searchClients"
          @focus="() => !clients.length && searchClients('')"
          @change="onClientChange"
        />
      </a-form-item>

      <a-form-item :label="$t('Note')">
        <a-input v-model:value="form.note" :maxlength="255" />
      </a-form-item>

      <div class="switch-row">
        <div>
          <div class="switch-label">{{ $t('LicK_Send_Email') }}</div>
          <div class="muted small">{{ selectedClient && !selectedClient.email ? $t('LicK_Client_No_Email') : $t('LicK_Send_Email_Help') }}</div>
        </div>
        <a-switch v-model:checked="form.send_email" :disabled="!form.client_id" />
      </div>
    </a-form>
  </a-modal>
</template>

<script setup>
/**
 * Products › License Keys › Issue licenses: create generated licenses with no
 * sale behind them (reseller batches, replacements, free licenses).
 *   POST license-keys/issue { product_id, product_variant_id?, count, client_id?,
 *     note?, expires_at? (blank = the product's license rules), send_email }
 *   → { success, count, batch, emailed }
 * Customers come from the paginated GET clients list (search as you type).
 */
import { ref, computed, watch } from 'vue';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import http from '../../lib/http';
import { licenseError } from '../../composables/useLicenseKeys';

const props = defineProps({
  open: { type: Boolean, default: false },
  products: { type: Array, default: () => [] },
  preset: { type: Object, default: null },
});
const emit = defineEmits(['update:open', 'done']);
const { t } = useI18n();

const busy = ref(false);
const form = ref(blankForm());

function blankForm() {
  return {
    product_id: undefined,
    product_variant_id: undefined,
    count: 1,
    client_id: undefined,
    note: '',
    expires_at: null,
    send_email: false,
  };
}

const genProducts = computed(() => props.products.filter(p => p.mode === 'generated'));
const productOptions = computed(() => genProducts.value.map(p => ({
  value: p.id,
  label: p.code ? `${p.name} (${p.code})` : p.name,
})));
const currentProduct = computed(() => genProducts.value.find(p => p.id === form.value.product_id) || null);
const variantOptions = computed(() => (currentProduct.value?.variants || []).map(v => ({ value: v.id, label: v.name })));

/* ------------------------------------------------------------- customers */
const clients = ref([]);
const clientsLoading = ref(false);
let searchSeq = 0;
let searchTimer = null;

const clientOptions = computed(() => clients.value.map(c => ({
  value: c.id,
  label: [c.name, c.email || c.phone].filter(Boolean).join(' · '),
})));
const selectedClient = computed(() => clients.value.find(c => c.id === form.value.client_id) || null);

watch(() => props.open, (o) => {
  if (!o) return;
  form.value = blankForm();
  const pre = props.preset;
  if (pre?.product_id && genProducts.value.some(p => p.id === pre.product_id)) {
    form.value.product_id = pre.product_id;
    form.value.product_variant_id = pre.variant_id || undefined;
  } else if (genProducts.value.length === 1) {
    form.value.product_id = genProducts.value[0].id;
  }
  if (pre?.client) {
    clients.value = [pre.client];
    form.value.client_id = pre.client.id;
  }
}, { immediate: true });

function searchClients(term) {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(async () => {
    const mine = ++searchSeq;
    clientsLoading.value = true;
    try {
      const data = await http.get('clients', {
        page: 1, limit: 20, SortField: 'id', SortType: 'desc', search: term || '',
      });
      if (mine !== searchSeq) return;
      const found = data?.clients || [];
      // Keep the chosen customer in the list so its label survives a new search.
      const keep = selectedClient.value;
      clients.value = keep && !found.some(c => c.id === keep.id) ? [keep, ...found] : found;
    } catch (e) {
      if (mine === searchSeq) clients.value = selectedClient.value ? [selectedClient.value] : [];
    } finally {
      if (mine === searchSeq) clientsLoading.value = false;
    }
  }, 300);
}

function onClientChange(id) {
  if (!id) {
    form.value.send_email = false;
    return;
  }
  const c = clients.value.find(x => x.id === id);
  if (c && c.email) form.value.send_email = true;
}

/* ---------------------------------------------------------------- submit */
async function submit() {
  if (!form.value.product_id) {
    message.warning(t('LicK_Choose_Product_First'));
    return;
  }
  if (variantOptions.value.length && !form.value.product_variant_id) {
    message.warning(t('LicK_Edition_Required'));
    return;
  }
  const count = Number(form.value.count) || 0;
  if (count < 1 || count > 500) {
    message.warning(t('LicK_Count_Range'));
    return;
  }
  busy.value = true;
  try {
    const r = await http.post('license-keys/issue', {
      product_id: form.value.product_id,
      product_variant_id: form.value.product_variant_id || null,
      count,
      client_id: form.value.client_id || null,
      note: form.value.note || null,
      expires_at: form.value.expires_at || null,
      send_email: !!(form.value.send_email && form.value.client_id),
    });
    const n = Number(r?.count) || count;
    message.success(r?.emailed ? t('LicK_Issued_Emailed', { n }) : t('LicK_Issued_Msg', { n }));
    if (form.value.send_email && form.value.client_id && !r?.emailed) {
      message.warning(t('LicK_Email_Not_Sent'));
    }
    emit('done', { batch: r?.batch || null, product_id: form.value.product_id });
    emit('update:open', false);
  } catch (e) {
    message.error(licenseError(e));
  } finally {
    busy.value = false;
  }
}
</script>

<style scoped>
.muted { color: rgba(var(--ink-rgb), 0.5); }
.small { font-size: 12px; }
.switch-row {
  display: flex; align-items: center; justify-content: space-between; gap: 16px;
  padding: 10px 12px; border: 1px solid var(--line); border-radius: 8px;
}
.switch-label { font-weight: 600; }
</style>
