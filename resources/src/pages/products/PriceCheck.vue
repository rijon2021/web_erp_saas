<template>
  <div ref="root" class="price-check" @click="focusInput">
    <div class="pc-top">
      <span class="pc-title">{{ $t('Price_Check') }}</span>
      <a-button size="small" @click.stop="toggleFullscreen">
        <template #icon><FullscreenOutlined /></template>
      </a-button>
    </div>

    <div class="pc-stage">
      <template v-if="result && result.found">
        <img v-if="result.image" :src="uploadUrl('products', result.image)" class="pc-image" alt="" />
        <div class="pc-name">{{ result.name }}</div>
        <div class="pc-price">{{ money(result.price) }}</div>
        <div v-if="result.unit" class="pc-unit">/ {{ result.unit }}</div>
      </template>
      <div v-else-if="result && !result.found" class="pc-missing">{{ $t('Price_Check_Not_Found') }}</div>
      <div v-else class="pc-hint">
        <ScanOutlined style="font-size: 64px; opacity: 0.35" />
        <div>{{ $t('Price_Check_Scan_Hint') }}</div>
      </div>
    </div>

    <input
      ref="input" v-model="code" class="pc-input" autocomplete="off" inputmode="text"
      :placeholder="$t('Price_Check_Placeholder')" @keydown.enter.prevent="lookup"
    />
  </div>
</template>

<script setup>
/**
 * Customer price check — a read-only screen for a tablet or kiosk: scan or
 * type a code and see the product name and selling price, nothing else (no
 * cost, no stock). GET price-check?code= needs no permission, so the tablet
 * signs in as a user whose role has none. A result clears itself after a
 * while; the input keeps focus so a scanner works hands-free.
 */
import { ref, onMounted, onBeforeUnmount } from 'vue';
import { useRoute } from 'vue-router';
import { FullscreenOutlined, ScanOutlined } from '@ant-design/icons-vue';
import { useFormat } from '../../composables/useFormat';
import { uploadUrl } from '../../lib/uploadUrl';
import http from '../../lib/http';

const { money } = useFormat();
const route = useRoute();

const root = ref();
const input = ref();
const code = ref('');
const result = ref(null);
let clearTimer = null;

function focusInput() {
  input.value?.focus();
}

async function lookup() {
  const value = code.value.trim();
  code.value = '';
  if (!value) return;
  clearTimeout(clearTimer);
  try {
    // ?warehouse=<id> in the page URL pins the kiosk to a store, so it shows
    // that store's price ("Different prices per store").
    const params = { code: value };
    if (route.query.warehouse) params.warehouse_id = route.query.warehouse;
    result.value = await http.get('price-check', params);
  } catch (e) {
    result.value = { found: false };
  }
  clearTimer = setTimeout(() => { result.value = null; }, 12000);
  focusInput();
}

function toggleFullscreen() {
  if (document.fullscreenElement) document.exitFullscreen?.();
  else root.value?.requestFullscreen?.();
  focusInput();
}

onMounted(focusInput);
onBeforeUnmount(() => clearTimeout(clearTimer));
</script>

<style scoped>
.price-check {
  min-height: calc(100vh - 140px);
  display: flex;
  flex-direction: column;
  background: var(--surface, #fff);
  border-radius: 12px;
  padding: 16px;
}
.price-check:fullscreen {
  min-height: 100vh;
  border-radius: 0;
}
.pc-top {
  display: flex;
  justify-content: space-between;
  align-items: center;
}
.pc-title {
  font-weight: 600;
  opacity: 0.6;
}
.pc-stage {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  text-align: center;
  gap: 12px;
  padding: 24px 8px;
}
.pc-image {
  max-height: 180px;
  max-width: 60%;
  object-fit: contain;
}
.pc-name {
  font-size: clamp(22px, 4vw, 40px);
  font-weight: 600;
  line-height: 1.2;
}
.pc-price {
  font-size: clamp(48px, 10vw, 110px);
  font-weight: 800;
  font-variant-numeric: tabular-nums;
  color: #10b981;
}
.pc-unit {
  font-size: 20px;
  opacity: 0.6;
}
.pc-missing,
.pc-hint {
  font-size: clamp(18px, 3vw, 28px);
  opacity: 0.6;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 16px;
}
.pc-input {
  width: 100%;
  font-size: 18px;
  padding: 10px 14px;
  border: 1px solid rgba(128, 128, 128, 0.3);
  border-radius: 8px;
  background: transparent;
  color: inherit;
  outline: none;
}
</style>
