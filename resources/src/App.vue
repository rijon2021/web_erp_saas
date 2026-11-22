<template>
  <a-config-provider :theme="theme" :direction="ui.direction" :locale="antLocale">
    <router-view />
    <!-- Global "plan limit reached" modal (SaaS) — opened by lib/http.js. -->
    <LimitReachedModal />
  </a-config-provider>
</template>

<script setup>
import { computed, onMounted, onUnmounted, watchEffect } from 'vue';
import { useRoute } from 'vue-router';
import { theme as antTheme } from 'ant-design-vue';
import enUS from 'ant-design-vue/es/locale/en_US';
import frFR from 'ant-design-vue/es/locale/fr_FR';
import esES from 'ant-design-vue/es/locale/es_ES';
import arEG from 'ant-design-vue/es/locale/ar_EG';
import { useUiStore } from './stores/ui';
import { isRtlLocale } from './i18n';
import { renewSession } from './lib/http';
import LimitReachedModal from './components/LimitReachedModal.vue';

const ui = useUiStore();
ui.initTheme();

// <html dir> mirrors the ConfigProvider direction below, so page CSS
// ([dir="rtl"] rules, logical properties) and Ant components always flip
// together — Arabic → RTL unless the customizer's RTL switch overrides it.
watchEffect(() => {
  document.documentElement.setAttribute('dir', ui.direction);
});

// The active primary colour as page-level CSS variables, so the many
// `var(--app-primary, …)` / `rgba(var(--app-primary-rgb, …), a)` rules follow
// the customizer and the admin default instead of always using their fallback.
watchEffect(() => {
  const hex = /^#[0-9a-f]{6}$/i.test(ui.primaryColor || '') ? ui.primaryColor : null;
  const root = document.documentElement.style;
  if (!hex) {
    root.removeProperty('--app-primary');
    root.removeProperty('--app-primary-rgb');
    return;
  }
  const n = parseInt(hex.slice(1), 16);
  root.setProperty('--app-primary', hex);
  root.setProperty('--app-primary-rgb', `${(n >> 16) & 255}, ${(n >> 8) & 255}, ${n & 255}`);
});

// Session keepalive. Without it an active user is signed out a fixed
// session.lifetime after their last full page load (see lib/http.js). Pings
// follow real input, not API traffic: the topbar polls on every page, so
// counting that would keep an abandoned tab signed in forever. Unattended screens
// get no input at all, so their routes opt out with meta.keepSignedIn.
const route = useRoute();
const KEEPALIVE_EVERY_MS = 10 * 60 * 1000;
let lastActivityAt = Date.now();
let keepaliveTimer = null;
const markActivity = () => { lastActivityAt = Date.now(); };

onMounted(() => {
  window.addEventListener('pointerdown', markActivity, { passive: true });
  window.addEventListener('keydown', markActivity, { passive: true });
  keepaliveTimer = setInterval(() => {
    if (route.meta.keepSignedIn || Date.now() - lastActivityAt <= KEEPALIVE_EVERY_MS) renewSession();
  }, KEEPALIVE_EVERY_MS);
});

onUnmounted(() => {
  window.removeEventListener('pointerdown', markActivity);
  window.removeEventListener('keydown', markActivity);
  clearInterval(keepaliveTimer);
});

// Keep in sync with the [dir="rtl"] body rule in assets/theme.css.
const RTL_FONT_STACK = "'Cairo', -apple-system, BlinkMacSystemFont, 'Segoe UI', Tahoma, Arial, sans-serif";

const ANT_LOCALES = { en: enUS, fr: frFR, es: esES, ar: arEG };
const antLocale = computed(() => ANT_LOCALES[ui.locale] || enUS);

// Ant's own dark algorithm drives every component; no custom dark CSS needed.
const theme = computed(() => ({
  algorithm: ui.dark ? antTheme.darkAlgorithm : antTheme.defaultAlgorithm,
  token: {
    // Driven by the customizer (persisted in the ui store); defaults to brand.
    colorPrimary: ui.primaryColor,
    borderRadius: 10,
    // Light app background behind the content; dark mode keeps its own.
    ...(ui.dark ? {} : { colorBgLayout: '#fafafa' }),
    // No fontFamily override in LTR: `inherit` made every component (tables
    // included) pick up the page font instead of Ant's own stack. The tenant
    // font (partials.tenant-font) still applies to plain elements via
    // --tenant-font. In RTL with no tenant font chosen, Cairo replaces Ant's
    // Latin stack, whose Arabic fallback renders poorly. Keyed on the locale, like
    // the <html dir> attribute the theme.css rule matches.
    ...(isRtlLocale(ui.locale) && !window.__tenantFontStack ? { fontFamily: RTL_FONT_STACK } : {}),
  },
}));
</script>
