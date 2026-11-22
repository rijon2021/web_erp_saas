<template>
  <a-card v-if="stores.length" size="small" :title="$t('Shopify')" style="margin-bottom: 16px">
    <p class="hint">{{ $t('Shopify_Link_Card_Hint') }}</p>

    <div v-for="store in stores" :key="store.id" class="store">
      <div class="store-head">
        <div>
          <div class="store-name">{{ store.name }}</div>
          <div class="sub">{{ store.shop_domain }}</div>
        </div>
        <a-tag v-if="store.status !== 'connected'" color="warning">{{ $t('Shopify_Store_Not_Connected') }}</a-tag>
      </div>

      <!-- Already linked (edit form) -->
      <template v-if="store.link && !pending[store.id]">
        <div class="linked">
          <CheckCircleFilled class="ok" />
          <div class="linked-body">
            <div>
              {{ $t('Shopify_Linked_To') }}
              <a :href="store.link.admin_url" target="_blank" rel="noopener">{{ store.link.handle || store.link.shopify_id }}</a>
              <span class="sub"> #{{ store.link.shopify_id }}</span>
            </div>
            <div v-if="store.link.last_synced_at" class="sub">{{ dateTime(store.link.last_synced_at) }}</div>
            <div v-if="store.link.variants.length" class="variant-list">
              <div v-for="v in store.link.variants" :key="v.local_id" class="variant-row">
                <span :class="v.shopify_id ? 'ok-text' : 'warn-text'">
                  <component :is="v.shopify_id ? CheckOutlined : WarningOutlined" />
                </span>
                <span>{{ v.name }}</span>
                <code v-if="v.sku" class="code">{{ v.sku }}</code>
                <span class="sub">{{ v.shopify_id ? '#' + v.shopify_id : $t('Shopify_Unmatched_Local') }}</span>
              </div>
            </div>
          </div>
        </div>
        <a-space size="small" wrap style="margin-top: 8px">
          <a-button size="small" @click="openSearch(store)">{{ $t('Shopify_Change') }}</a-button>
          <a-popconfirm :title="$t('Shopify_Unlink_Confirm')" :ok-text="$t('Shopify_Unlink')" :cancel-text="$t('Cancel')" @confirm="unlink(store)">
            <a-button size="small" danger :loading="busy[store.id]">{{ $t('Shopify_Unlink') }}</a-button>
          </a-popconfirm>
        </a-space>
      </template>

      <!-- A listing has been picked (not yet applied) -->
      <template v-else-if="pending[store.id]">
        <div class="picked">
          <img v-if="pending[store.id].picked.image" :src="pending[store.id].picked.image" class="thumb" alt="">
          <div class="picked-body">
            <div class="picked-title">{{ pending[store.id].picked.title }}</div>
            <div class="sub">{{ pending[store.id].picked.handle }} · #{{ pending[store.id].picked.id }}</div>
          </div>
          <a-button size="small" type="text" @click="clearPick(store)">
            <template #icon><CloseOutlined /></template>
          </a-button>
        </div>

        <div class="preview">
          <div class="preview-title">{{ $t('Shopify_Variant_Matches') }}</div>
          <div v-for="m in previewFor(store)" :key="m.key" class="variant-row">
            <span :class="m.ok ? 'ok-text' : 'warn-text'"><component :is="m.ok ? CheckOutlined : WarningOutlined" /></span>
            <span>{{ m.label }}</span>
            <code v-if="m.sku" class="code">{{ m.sku }}</code>
            <span class="sub">{{ m.note }}</span>
          </div>
        </div>

        <a-radio-group v-model:value="pending[store.id].mode" class="mode">
          <a-radio value="keep" class="mode-item">
            {{ $t('Shopify_Keep_Listing') }}
            <div class="sub">{{ $t('Shopify_Keep_Listing_Hint') }}</div>
          </a-radio>
          <a-radio value="replace" class="mode-item">
            {{ $t('Shopify_Replace_Listing') }}
            <div class="sub">{{ $t('Shopify_Replace_Listing_Hint') }}</div>
          </a-radio>
        </a-radio-group>
        <a-checkbox v-model:checked="pending[store.id].copy" style="margin-top: 8px">{{ $t('Shopify_Copy_Remote') }}</a-checkbox>

        <div style="margin-top: 10px">
          <a-button v-if="productId" type="primary" size="small" :loading="busy[store.id]" @click="applyPick(store, productId)">
            {{ $t('Shopify_Link_Now') }}
          </a-button>
          <a-tag v-else color="processing">{{ $t('Shopify_Will_Link') }}</a-tag>
        </div>
      </template>

      <!-- Not linked -->
      <template v-else>
        <div class="sub" style="margin-bottom: 8px">{{ $t('Shopify_Not_Linked') }}</div>
        <a-button size="small" :disabled="store.status !== 'connected'" @click="openSearch(store)">
          <template #icon><SearchOutlined /></template>
          {{ $t('Shopify_Pick_Listing') }}
        </a-button>
      </template>
    </div>

    <!-- Search modal -->
    <a-modal :open="searchOpen" :title="searchStore ? searchStore.name : ''" :footer="null" :width="640" @cancel="searchOpen = false">
      <a-input-search
        v-model:value="query" :placeholder="$t('Shopify_Search_Placeholder')" allow-clear
        :loading="searching" enter-button autofocus @search="runSearch"
      />
      <div class="results">
        <div v-if="searching" class="results-empty"><a-spin /></div>
        <div v-else-if="searched && !results.length" class="results-empty">{{ $t('Shopify_No_Results') }}</div>
        <div v-for="r in results" :key="r.id" class="result" :class="{ taken: r.linked_to }" @click="pick(r)">
          <img v-if="r.image" :src="r.image" class="thumb" alt="">
          <div v-else class="thumb thumb-empty" />
          <div class="result-body">
            <div class="result-title">
              {{ r.title }}
              <a-tag v-if="r.status && r.status !== 'active'" size="small">{{ r.status }}</a-tag>
            </div>
            <div class="sub">{{ r.handle }} · #{{ r.id }}<template v-if="r.price !== null"> · {{ r.price }}</template></div>
            <div class="sub skus">
              <template v-for="v in r.variants.slice(0, 6)" :key="v.id">
                <code class="code">{{ v.sku || v.title }}</code>
              </template>
              <span v-if="r.variants.length > 6">+{{ r.variants.length - 6 }}</span>
            </div>
            <div v-if="r.linked_to" class="warn-text sub">{{ $t('Shopify_Already_Linked_To') }} {{ r.linked_to.name }}</div>
          </div>
        </div>
      </div>
    </a-modal>
  </a-card>
</template>

<script setup>
/**
 * "This product already exists in my shop" — the card on the product form.
 *
 * One block per connected store. A store is either linked (shows the pairing
 * and lets it be changed or forgotten), has a listing picked but not yet
 * applied, or is not linked. On the edit form a pick is applied at once; on
 * the create form the product has no id yet, so the pick waits and the form
 * calls commit(newId) after saving.
 */
import { ref, reactive, onMounted, watch } from 'vue';
import { message, Modal } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import {
  CheckCircleFilled, CheckOutlined, WarningOutlined, CloseOutlined, SearchOutlined,
} from '@ant-design/icons-vue';
import http from '../../lib/http';
import { useFormat } from '../../composables/useFormat';

const props = defineProps({
  productId: { type: [Number, String], default: null },
  /** The form's variant rows ({ text | name, code }) for previewing the match before saving. */
  variants: { type: Array, default: () => [] },
  productCode: { type: String, default: '' },
  isVariant: { type: Boolean, default: false },
});

const { t } = useI18n();
const { dateTime } = useFormat();

const stores = ref([]);
const pending = reactive({});
const busy = reactive({});

async function load() {
  try {
    const data = await http.get('shopify/product-links', { product_id: props.productId || 0 });
    stores.value = data?.stores || [];
  } catch (e) {
    // No permission or the module is not set up: the card simply does not show.
    stores.value = [];
  }
}

onMounted(load);
watch(() => props.productId, load);

// ---------------- search ----------------

const searchOpen = ref(false);
const searchStore = ref(null);
const query = ref('');
const results = ref([]);
const searching = ref(false);
const searched = ref(false);

function openSearch(store) {
  searchStore.value = store;
  query.value = props.productCode || '';
  results.value = [];
  searched.value = false;
  searchOpen.value = true;
  if (query.value) runSearch();
}

async function runSearch() {
  if (!searchStore.value || !query.value.trim()) return;
  searching.value = true;
  try {
    const data = await http.get('shopify/product-links/search', { store_id: searchStore.value.id, q: query.value.trim() });
    results.value = data?.products || [];
    searched.value = true;
  } catch (e) {
    message.error(e?.data?.message || t('Shopify_Link_Failed'));
  } finally {
    searching.value = false;
  }
}

function pick(remote) {
  const store = searchStore.value;
  pending[store.id] = { picked: remote, mode: 'keep', copy: false, force: !!remote.linked_to };
  searchOpen.value = false;
}

function clearPick(store) {
  delete pending[store.id];
}

// ---------------- preview ----------------

const norm = v => String(v || '').trim().toLowerCase();

/** Which Shopify variants the local ones will pair with, using the same rule as the server: SKU first, then name. */
function previewFor(store) {
  const p = pending[store.id];
  if (!p) return [];
  const remote = p.picked.variants || [];

  if (!props.isVariant) {
    const first = remote[0];
    if (!first) return [];
    return [{
      key: 'single', ok: true, label: first.title === 'Default Title' ? t('Product') : first.title,
      sku: first.sku, note: '#' + first.id,
    }];
  }

  const rows = [];
  const used = new Set();
  props.variants.forEach((lv, i) => {
    const name = lv.text || lv.name || '';
    let match = remote.find(rv => rv.sku && norm(rv.sku) === norm(lv.code) && !used.has(rv.id));
    if (!match) match = remote.find(rv => norm(rv.title) === norm(name) && !used.has(rv.id));
    if (match) used.add(match.id);
    rows.push({
      key: 'l' + i, ok: !!match, label: name, sku: lv.code,
      note: match ? '→ ' + (match.sku || match.title) + ' #' + match.id : t('Shopify_Unmatched_Local'),
    });
  });
  remote.filter(rv => !used.has(rv.id)).forEach(rv => {
    rows.push({ key: 'r' + rv.id, ok: false, label: rv.title, sku: rv.sku, note: t('Shopify_Unmatched_Remote') });
  });
  return rows;
}

// ---------------- apply ----------------

async function applyPick(store, productId) {
  const p = pending[store.id];
  if (!p) return true;
  busy[store.id] = true;
  try {
    const res = await http.post('shopify/product-links', {
      store_id: store.id,
      product_id: productId,
      shopify_id: p.picked.id,
      mode: p.mode,
      copy_remote: p.copy ? 1 : 0,
      force: p.force ? 1 : 0,
    });
    delete pending[store.id];
    const missing = (res?.unmatched_remote?.length || 0) + (res?.unmatched_local?.length || 0);
    message.success(t('Shopify_Linked_Success') + (missing ? ` (${missing} ${t('Shopify_Unmatched_Local').toLowerCase()})` : ''));
    return true;
  } catch (e) {
    if (e?.status === 409 || e?.data?.code === 'claimed') {
      return new Promise(resolve => {
        Modal.confirm({
          title: e.data.message,
          okText: t('Shopify_Link_Now'),
          cancelText: t('Cancel'),
          onOk: async () => { p.force = true; resolve(await applyPick(store, productId)); },
          onCancel: () => resolve(false),
        });
      });
    }
    message.error(e?.data?.message || t('Shopify_Link_Failed'));
    return false;
  } finally {
    busy[store.id] = false;
    await load();
  }
}

async function unlink(store) {
  busy[store.id] = true;
  try {
    await http.delete(`shopify/product-links?store_id=${store.id}&product_id=${props.productId}`);
    await load();
  } catch (e) {
    message.error(e?.data?.message || t('Shopify_Link_Failed'));
  } finally {
    busy[store.id] = false;
  }
}

/** Apply every waiting pick to a product that now exists. Returns false if any failed. */
async function commit(productId) {
  let ok = true;
  for (const store of stores.value) {
    if (pending[store.id]) {
      const done = await applyPick(store, productId);
      ok = ok && done;
    }
  }
  return ok;
}

function hasPending() {
  return Object.keys(pending).length > 0;
}

defineExpose({ commit, hasPending });
</script>

<style scoped>
.hint {
  margin: 0 0 12px;
  font-size: 12.5px;
  opacity: 0.7;
}
.store + .store {
  margin-top: 14px;
  padding-top: 14px;
  border-top: 1px solid rgba(128, 128, 128, 0.18);
}
.store-head {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 8px;
  margin-bottom: 8px;
}
.store-name {
  font-weight: 600;
}
.sub {
  font-size: 11.5px;
  opacity: 0.6;
}
.linked {
  display: flex;
  gap: 8px;
  align-items: flex-start;
}
.linked-body {
  flex: 1;
  min-width: 0;
}
.ok {
  color: #52c41a;
  margin-top: 3px;
}
.ok-text {
  color: #52c41a;
}
.warn-text {
  color: #faad14;
}
.variant-list,
.preview {
  margin-top: 8px;
  padding: 8px 10px;
  border-radius: 8px;
  background: rgba(128, 128, 128, 0.08);
}
.preview-title {
  font-size: 12px;
  font-weight: 600;
  margin-bottom: 4px;
}
.variant-row {
  display: flex;
  gap: 8px;
  align-items: center;
  font-size: 12.5px;
  padding: 2px 0;
  flex-wrap: wrap;
}
.code {
  font-size: 11.5px;
  padding: 0 5px;
  border-radius: 4px;
  background: rgba(128, 128, 128, 0.16);
}
.picked {
  display: flex;
  gap: 10px;
  align-items: center;
}
.picked-body {
  flex: 1;
  min-width: 0;
}
.picked-title {
  font-weight: 600;
}
.thumb {
  width: 44px;
  height: 44px;
  border-radius: 6px;
  object-fit: cover;
  flex: none;
  background: rgba(128, 128, 128, 0.12);
}
.thumb-empty {
  border: 1px dashed rgba(128, 128, 128, 0.35);
}
.mode {
  display: flex;
  flex-direction: column;
  gap: 6px;
  margin-top: 10px;
}
.mode-item {
  align-items: flex-start;
  white-space: normal;
}
.results {
  margin-top: 12px;
  max-height: 60vh;
  overflow: auto;
}
.results-empty {
  text-align: center;
  padding: 24px 0;
  opacity: 0.6;
}
.result {
  display: flex;
  gap: 12px;
  align-items: center;
  padding: 8px;
  border-radius: 8px;
  cursor: pointer;
}
.result:hover {
  background: rgba(128, 128, 128, 0.1);
}
.result.taken {
  opacity: 0.75;
}
.result-body {
  flex: 1;
  min-width: 0;
}
.result-title {
  font-weight: 600;
}
.skus {
  display: flex;
  gap: 4px;
  flex-wrap: wrap;
  margin-top: 2px;
}
</style>
