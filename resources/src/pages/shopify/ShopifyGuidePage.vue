<template>
  <div class="page">
    <PageHeader
      :title="$t('Page_Shopify_Guide')"
      :subtitle="$t('Shp_Guide_Subtitle')"
      :breadcrumb="['Shopify', $t('Guide')]"
    >
      <template #actions>
        <a-select
          v-model:value="storeId" :options="storeOptions" style="min-width: 260px"
          :placeholder="$t('Shp_Show_Store_State')" allow-clear
          :loading="loading" @change="loadStore"
        />
        <a-button v-if="storeId" @click="$router.push(`/shopify/stores/${storeId}`)">
          {{ $t('Shp_Open_Store') }}
        </a-button>
        <a-button type="primary" @click="$router.push('/shopify/stores')">
          <template #icon><ShopOutlined /></template>
          {{ $t('Stores') }}
        </a-button>
      </template>
    </PageHeader>

    <a-card size="small">
      <a-spin :spinning="loading">
        <a-alert
          v-if="!loading && !stores.length"
          type="info" show-icon style="margin-bottom: 16px"
          :message="$t('Shp_No_Shop_Connected_Yet')"
          :description="$t('Shp_Guide_No_Shop_Desc')"
        />
        <ShopifyGuide :store="store" :cron="cron" :webhook-url="webhookUrl" />
      </a-spin>
    </a-card>
  </div>
</template>

<script setup>
/**
 * The guide as a page of its own, so it can be read before any store exists —
 * the same component the store's own Setup guide tab renders, with the store
 * optional. Picking one fills in its real state without leaving the page.
 */
import { ref, computed, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import { ShopOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import ShopifyGuide from './ShopifyGuide.vue';
import http from '../../lib/http';

const route = useRoute();

const loading = ref(false);
const stores = ref([]);
const store = ref(null);
const storeId = ref(route.query.store_id ? Number(route.query.store_id) : undefined);
// The platform always runs the scheduler (ShopifyStoreController::cronLines
// returns managed: true), so a failed request must not fall back to cron steps.
const cron = ref({ managed: true, base_path: '', sync: '', queue: '' });
const webhookUrl = ref('');

const storeOptions = computed(() => stores.value.map(s => ({ value: s.id, label: s.label })));

/**
 * The picked store's full settings. The meta list carries only enough to
 * choose from, and the guide reports on things it does not include (the
 * signing secret, the automatic plan), so the store is fetched in full.
 */
async function loadStore(id) {
  if (!id) {
    store.value = null;

    return;
  }

  try {
    const res = await http.get(`shopify/stores/${id}`);
    store.value = res?.store || null;
  } catch (e) {
    store.value = null;
  }
}

onMounted(async () => {
  loading.value = true;
  try {
    const meta = await http.get('shopify/meta');
    stores.value = meta?.stores || [];
    cron.value = meta?.cron || cron.value;
    webhookUrl.value = meta?.webhook_url || '';

    // Deep-linked with ?store_id=, or the only shop there is.
    if (!storeId.value && stores.value.length === 1) storeId.value = stores.value[0].id;
    if (storeId.value) await loadStore(storeId.value);
  } finally {
    loading.value = false;
  }
});
</script>

<style scoped>
.page {
  padding-bottom: 20px;
}
</style>
