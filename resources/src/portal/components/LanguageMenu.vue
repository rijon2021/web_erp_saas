<template>
  <!-- Portal topbar language menu. Languages = the ACTIVE portal languages
       (Client Portal → Portal Languages), injected by portal.blade.php. -->
  <div class="dropdown">
    <button type="button" class="btn btn-icon rst-tool rst-lang-trigger" data-bs-toggle="dropdown" data-bs-auto-close="outside"
            :title="$t('language')" :aria-label="$t('language')">
      <img v-if="flag(current)" :src="flag(current)" alt="" class="rst-lang-trigger-flag">
      <i v-else class="ti ti-world"></i>
    </button>
    <div class="dropdown-menu dropdown-menu-end rst-menu rst-lang-menu">
      <div class="rst-menu-head">
        <span class="rst-menu-title">{{ $t('language') }}</span>
        <span class="rst-menu-count">{{ locales[current] || current }}</span>
      </div>

      <div v-if="codes.length > 6" class="rst-lang-search">
        <i class="ti ti-search"></i>
        <input v-model="query" type="text" class="form-control form-control-sm" :aria-label="$t('language')" @keydown.enter.prevent="chooseFirst">
      </div>

      <div class="rst-lang-list">
        <button
          v-for="code in filtered"
          :key="code"
          type="button"
          class="dropdown-item rst-lang"
          :class="{ active: current === code }"
          :disabled="busy"
          @click="choose(code)"
        >
          <img v-if="flag(code)" :src="flag(code)" alt="" class="rst-lang-flag">
          <span v-else class="rst-lang-code">{{ code }}</span>
          <span class="rst-lang-name">{{ locales[code] }}</span>
          <i v-if="current === code" class="ti ti-check rst-lang-check"></i>
        </button>
        <div v-if="!filtered.length" class="rst-lang-empty">—</div>
      </div>
    </div>
  </div>
</template>

<script>
import { SUPPORTED_LOCALES, LOCALE_FLAGS, setLocale, i18n } from '../i18n';

export default {
  name: 'LanguageMenu',
  data() {
    return { locales: SUPPORTED_LOCALES, busy: false, query: '' };
  },
  computed: {
    current() { return i18n.global.locale.value; },
    codes() { return Object.keys(this.locales); },
    filtered() {
      const q = this.query.trim().toLowerCase();
      if (!q) return this.codes;
      return this.codes.filter(c => c.toLowerCase().includes(q) || String(this.locales[c]).toLowerCase().includes(q));
    },
  },
  methods: {
    flag(code) {
      const f = LOCALE_FLAGS[code];
      return f ? `/flags/${f}` : null;
    },
    chooseFirst() {
      if (this.filtered.length) this.choose(this.filtered[0]);
    },
    async choose(code) {
      if (!code || code === this.current || this.busy) return;
      this.busy = true;
      try {
        await setLocale(code);
        this.query = '';
      } finally {
        this.busy = false;
      }
    },
  },
};
</script>
