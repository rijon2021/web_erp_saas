<template>
  <div class="page store-tr" :class="{ 'is-dark': ui.dark }">
    <PageHeader
      :title="$t(labels.title)"
      :breadcrumb="[$t(labels.section), $t(labels.languagesTitle), language?.name || locale]"
    >
      <template #actions>
        <a-space wrap>
          <a-button @click="$router.push(languagesPath)">
            <template #icon><ArrowLeftOutlined /></template>
            {{ $t(labels.languagesTitle) }}
          </a-button>
          <a-button type="primary" @click="openAdd">
            <template #icon><PlusOutlined /></template>
            {{ $t('StoreTr_Add_Key') }}
          </a-button>
        </a-space>
      </template>
    </PageHeader>

    <!-- Language hero: which language is being edited + quick switcher -->
    <div class="st-hero">
      <img v-if="language?.flag" :src="`/flags/${language.flag}`" alt="" class="st-hero-flag" />
      <div class="st-hero-text">
        <div class="st-hero-name" :dir="language?.rtl ? 'rtl' : null">{{ language?.name || locale }}</div>
        <div class="st-hero-sub">
          <span class="st-code">{{ locale }}</span>
          <span>{{ $t('StoreTr_Live_Hint') }}</span>
        </div>
      </div>
      <a-select
        v-if="installed.length > 1" :value="locale" class="st-switch" :dropdown-match-select-width="false"
        @change="switchLanguage"
      >
        <a-select-option v-for="l in installed" :key="l.locale" :value="l.locale">
          <span class="st-opt">
            <img v-if="l.flag" :src="`/flags/${l.flag}`" alt="" />
            {{ l.name }}
          </span>
        </a-select-option>
      </a-select>
    </div>

    <div class="st-panel">
      <!-- Toolbar: filter pills + search -->
      <div class="st-toolbar">
        <div class="st-pills" role="tablist">
          <button
            v-for="f in filters" :key="f.key" type="button" role="tab" class="st-pill"
            :class="{ active: filter === f.key, [`st-pill--${f.key}`]: true }" :aria-selected="filter === f.key"
            @click="setFilter(f.key)"
          >
            {{ f.label }}
            <span class="st-pill-count">{{ counts[f.key] ?? 0 }}</span>
          </button>
        </div>
        <a-input v-model:value="search" allow-clear class="st-search" :placeholder="$t('Search')">
          <template #prefix><SearchOutlined /></template>
        </a-input>
      </div>

      <div class="st-head-row">
        <span>{{ $t('StoreTr_English') }}</span>
        <span>{{ $t('StoreTr_Translation') }}</span>
      </div>

      <a-spin :spinning="loading">
        <div class="st-list">
          <div
            v-for="row in rows" :key="row.key" class="st-row"
            :class="{ 'st-row--missing': !String(row.draft || '').trim(), 'st-row--saved': row.justSaved }"
          >
            <div class="st-src">
              <div class="st-key-line">
                <code class="st-key">{{ row.key }}</code>
                <span v-if="row.is_custom_key" class="st-tag st-tag--custom">{{ $t('StoreTr_Custom_Tag') }}</span>
                <span v-else-if="row.is_customized" class="st-tag st-tag--edited">{{ $t('StoreTr_Edited_Tag') }}</span>
              </div>
              <div v-if="row.english !== null" class="st-en">{{ row.english }}</div>
              <div v-else class="st-en st-en--none">—</div>
            </div>

            <div class="st-edit">
              <a-textarea
                v-model:value="row.draft" :auto-size="{ minRows: 1, maxRows: 6 }"
                :dir="language?.rtl ? 'rtl' : null"
                :placeholder="$t('StoreTr_Empty_Fallback')"
                class="st-input"
                @blur="save(row)"
                @keydown.ctrl.enter.prevent="save(row)"
                @keydown.meta.enter.prevent="save(row)"
              />
              <div class="st-row-actions">
                <span v-if="row.saving" class="st-state"><LoadingOutlined /></span>
                <span v-else-if="row.justSaved" class="st-state st-state--ok"><CheckCircleFilled /> {{ $t('StoreTr_Saved') }}</span>
                <a-tooltip v-if="row.is_customized && !row.is_custom_key" :title="$t('StoreTr_Reset_Default')">
                  <a-button type="text" size="small" class="st-icon-btn" @click="reset(row)">
                    <template #icon><UndoOutlined /></template>
                  </a-button>
                </a-tooltip>
                <a-tooltip v-if="row.is_custom_key" :title="$t('Delete')">
                  <a-button type="text" size="small" danger class="st-icon-btn" @click="remove(row)">
                    <template #icon><DeleteOutlined /></template>
                  </a-button>
                </a-tooltip>
              </div>
            </div>
          </div>

          <div v-if="!loading && !rows.length" class="st-empty">
            <InboxOutlined class="st-empty-ic" />
            <span>{{ $t('No_results') }}</span>
          </div>
        </div>
      </a-spin>

      <div class="st-foot">
        <span class="st-foot-count">{{ rangeLabel }}</span>
        <a-pagination
          v-model:current="page" :page-size="perPage" :total="total" size="small"
          :show-size-changer="false" @change="load"
        />
      </div>
    </div>

    <!-- Add translation -->
    <a-modal
      v-model:open="addOpen" :title="$t('StoreTr_Add_Key')" :ok-text="$t('Save')"
      :confirm-loading="adding" @ok="addKey"
    >
      <a-form layout="vertical">
        <a-form-item :label="$t('StoreTr_Key')" :extra="$t('StoreTr_Key_Help')">
          <a-input v-model:value="newKey.key" placeholder="My_Custom_Text" />
        </a-form-item>
        <a-form-item :label="$t('StoreTr_Translation')">
          <a-textarea v-model:value="newKey.value" :auto-size="{ minRows: 2, maxRows: 6 }" :dir="language?.rtl ? 'rtl' : null" />
        </a-form-item>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * Shared "Translations" editor for one language of a database-backed surface
 * (route param :locale). Endpoints under `apiBase`:
 *   GET  {apiBase}/translations/{locale}?page&per_page&search&filter
 *        → { language, data[{key, english, value, is_customized, is_custom_key}], total, counts }
 *   PUT  {apiBase}/translations/{locale}         {key, value}  (marks the row edited)
 *   POST {apiBase}/translations/{locale}/reset   {key}         (back to the shipped value)
 *   POST {apiBase}/translations/{locale}/delete  {key}         (custom keys only)
 * Edits bust the server cache, so the surface shows them right away.
 */
import { ref, computed, watch, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { message, Modal } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import {
  ArrowLeftOutlined, PlusOutlined, SearchOutlined, UndoOutlined, DeleteOutlined,
  LoadingOutlined, CheckCircleFilled, InboxOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';
import { useUiStore } from '../../stores/ui';

const props = defineProps({
  /** e.g. 'admin/store' or 'admin/portal' */
  apiBase: { type: String, required: true },
  /** SPA path of this editor without the locale, e.g. '/store/translations' */
  translationsPath: { type: String, required: true },
  /** SPA path of the matching Languages page, e.g. '/store/languages' */
  languagesPath: { type: String, required: true },
  /** i18n keys: title, section, languagesTitle */
  labels: { type: Object, required: true },
});

/**
 * {locale, key, value} after every successful save / reset / add, so a host
 * page can apply the change live (the back office merges it into vue-i18n).
 */
const emit = defineEmits(['saved']);

const { t } = useI18n();
const route = useRoute();
const router = useRouter();
const ui = useUiStore();

const locale = computed(() => String(route.params.locale || 'en'));

const loading = ref(false);
const language = ref(null);
const rows = ref([]);
const total = ref(0);
const counts = ref({});
const page = ref(1);
const perPage = 40;
const search = ref('');
const filter = ref('all');

const filters = computed(() => [
  { key: 'all', label: t('All') },
  { key: 'missing', label: t('StoreTr_Filter_Missing') },
  { key: 'customized', label: t('StoreTr_Filter_Edited') },
  { key: 'custom', label: t('StoreTr_Filter_Custom') },
]);

const rangeLabel = computed(() => {
  if (!total.value) return '';
  const from = (page.value - 1) * perPage + 1;
  const to = Math.min(total.value, page.value * perPage);
  return `${from}–${to} / ${total.value}`;
});

async function load() {
  loading.value = true;
  try {
    const data = await http.get(`${props.apiBase}/translations/${encodeURIComponent(locale.value)}`, {
      page: page.value, per_page: perPage, search: search.value || undefined, filter: filter.value,
    });
    language.value = data?.language || null;
    total.value = data?.total || 0;
    counts.value = data?.counts || {};
    rows.value = (data?.data || []).map(r => ({ ...r, draft: r.value, saving: false, justSaved: false }));
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    loading.value = false;
  }
}

function setFilter(key) {
  filter.value = key;
  page.value = 1;
  load();
}

let searchTimer = null;
watch(search, () => {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(() => { page.value = 1; load(); }, 300);
});

// ---------------- language switcher ----------------

const installed = ref([]);
async function loadInstalled() {
  try {
    const data = await http.get(`${props.apiBase}/languages`);
    installed.value = data?.languages || [];
  } catch (e) { installed.value = []; }
}
function switchLanguage(value) {
  router.push(`${props.translationsPath}/${encodeURIComponent(value)}`);
}
watch(locale, () => { page.value = 1; load(); });

// ---------------- editing ----------------

function flashSaved(row) {
  row.justSaved = true;
  setTimeout(() => { row.justSaved = false; }, 1600);
}

async function save(row) {
  if (row.saving || (row.draft ?? '') === (row.value ?? '')) return;
  row.saving = true;
  try {
    await http.put(`${props.apiBase}/translations/${encodeURIComponent(locale.value)}`, { key: row.key, value: row.draft ?? '' });
    if (!String(row.value || '').trim() && String(row.draft || '').trim()) {
      counts.value.missing = Math.max(0, (counts.value.missing || 0) - 1);
    }
    if (!row.is_customized) counts.value.customized = (counts.value.customized || 0) + 1;
    row.value = row.draft;
    row.is_customized = true;
    flashSaved(row);
    emit('saved', { locale: locale.value, key: row.key, value: row.value });
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    row.saving = false;
  }
}

async function reset(row) {
  row.saving = true;
  try {
    const res = await http.post(`${props.apiBase}/translations/${encodeURIComponent(locale.value)}/reset`, { key: row.key });
    row.value = res?.value ?? row.value;
    row.draft = row.value;
    row.is_customized = false;
    emit('saved', { locale: locale.value, key: row.key, value: row.value });
    counts.value.customized = Math.max(0, (counts.value.customized || 0) - 1);
    flashSaved(row);
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    row.saving = false;
  }
}

function remove(row) {
  Modal.confirm({
    title: t('Delete_Title'),
    content: row.key,
    okType: 'danger',
    okText: t('Delete_confirmButtonText'),
    cancelText: t('Delete_cancelButtonText'),
    async onOk() {
      try {
        await http.post(`${props.apiBase}/translations/${encodeURIComponent(locale.value)}/delete`, { key: row.key });
        message.success(t('Deleted_in_successfully'));
        load();
      } catch (e) {
        message.error(e?.data?.message || t('InvalidData'));
      }
    },
  });
}

// ---------------- add key ----------------

const addOpen = ref(false);
const adding = ref(false);
const newKey = ref({ key: '', value: '' });

function openAdd() {
  newKey.value = { key: '', value: '' };
  addOpen.value = true;
}

async function addKey() {
  const key = newKey.value.key.trim();
  if (!/^[A-Za-z0-9_.-]+$/.test(key)) {
    message.error(t('StoreTr_Key_Help'));
    return;
  }
  adding.value = true;
  try {
    await http.put(`${props.apiBase}/translations/${encodeURIComponent(locale.value)}`, { key, value: newKey.value.value });
    addOpen.value = false;
    message.success(t('Successfully_Created'));
    emit('saved', { locale: locale.value, key, value: newKey.value.value });
    search.value = key;
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    adding.value = false;
  }
}

onMounted(() => {
  load();
  loadInstalled();
});
</script>

<style scoped>
.store-tr {
  --st-surface: #fff;
  --st-border: rgba(0, 0, 0, 0.07);
  --st-muted: rgba(0, 0, 0, 0.5);
  --st-soft: rgba(0, 0, 0, 0.035);
  --st-tint: rgba(var(--app-primary-rgb, 109, 40, 217), 0.08);
}
.store-tr.is-dark {
  --st-surface: #1f1f1f;
  --st-border: rgba(255, 255, 255, 0.09);
  --st-muted: rgba(255, 255, 255, 0.5);
  --st-soft: rgba(255, 255, 255, 0.05);
  --st-tint: rgba(var(--app-primary-light-rgb, 139, 92, 246), 0.16);
}

/* ---------- hero ---------- */
.st-hero {
  display: flex;
  align-items: center;
  gap: 14px;
  padding: 16px 18px;
  margin-bottom: 16px;
  border-radius: 16px;
  border: 1px solid var(--st-border);
  background:
    linear-gradient(120deg, rgba(var(--app-primary-rgb, 109, 40, 217), 0.10), transparent 60%),
    var(--st-surface);
}
.st-hero-flag {
  flex: none;
  width: 52px;
  height: 38px;
  object-fit: cover;
  border-radius: 9px;
  box-shadow: 0 0 0 1px var(--st-border), 0 3px 10px rgba(0, 0, 0, 0.1);
}
.st-hero-text {
  min-width: 0;
  flex: 1;
}
.st-hero-name {
  font-size: 19px;
  font-weight: 700;
  line-height: 1.2;
}
.st-hero-sub {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
  margin-top: 4px;
  font-size: 12.5px;
  color: var(--st-muted);
}
.st-code {
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
  font-size: 11px;
  padding: 1px 6px;
  border-radius: 5px;
  background: var(--st-soft);
}
.st-switch {
  min-width: 190px;
}
.st-opt {
  display: inline-flex;
  align-items: center;
  gap: 8px;
}
.st-opt img {
  width: 20px;
  height: 14px;
  object-fit: cover;
  border-radius: 3px;
}

/* ---------- panel ---------- */
.st-panel {
  border-radius: 16px;
  border: 1px solid var(--st-border);
  background: var(--st-surface);
  overflow: hidden;
}
.st-toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  flex-wrap: wrap;
  padding: 14px 16px;
  border-bottom: 1px solid var(--st-border);
}
.st-pills {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}
.st-pill {
  display: inline-flex;
  align-items: center;
  gap: 7px;
  padding: 6px 12px;
  border: 1px solid var(--st-border);
  border-radius: 999px;
  background: transparent;
  color: inherit;
  font-size: 13px;
  cursor: pointer;
  transition: background 0.15s ease, border-color 0.15s ease, color 0.15s ease;
}
.st-pill:hover {
  background: var(--st-soft);
}
.st-pill.active {
  border-color: transparent;
  background: var(--app-primary, #6d28d9);
  color: #fff;
}
.st-pill-count {
  font-size: 11px;
  font-weight: 600;
  padding: 0 7px;
  border-radius: 999px;
  line-height: 18px;
  background: var(--st-soft);
}
.st-pill.active .st-pill-count {
  background: rgba(255, 255, 255, 0.22);
}
.st-pill--missing:not(.active) .st-pill-count {
  color: #d97706;
  background: rgba(217, 119, 6, 0.12);
}
.st-search {
  max-width: 280px;
  border-radius: 10px;
}

.st-head-row,
.st-row {
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(0, 1.25fr);
  gap: 18px;
}
.st-head-row {
  padding: 9px 18px;
  font-size: 11px;
  font-weight: 600;
  letter-spacing: 0.06em;
  text-transform: uppercase;
  color: var(--st-muted);
  background: var(--st-soft);
  border-bottom: 1px solid var(--st-border);
}
.st-list {
  min-height: 120px;
}
.st-row {
  padding: 12px 18px;
  border-bottom: 1px solid var(--st-border);
  border-inline-start: 3px solid transparent;
  transition: background 0.2s ease, border-color 0.2s ease;
}
.st-row:hover {
  background: var(--st-soft);
}
.st-row--missing {
  border-inline-start-color: #f59e0b;
}
.st-row--saved {
  background: rgba(22, 163, 74, 0.06);
}
.st-key-line {
  display: flex;
  align-items: center;
  gap: 6px;
  flex-wrap: wrap;
}
.st-key {
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
  font-size: 11.5px;
  padding: 1px 7px;
  border-radius: 6px;
  background: var(--st-tint);
  color: var(--app-primary, #6d28d9);
  word-break: break-all;
}
.is-dark .st-key {
  color: var(--app-primary-3, #c4b5fd);
}
.st-tag {
  font-size: 10.5px;
  font-weight: 600;
  padding: 1px 7px;
  border-radius: 999px;
}
.st-tag--edited {
  color: #2563eb;
  background: rgba(37, 99, 235, 0.12);
}
.st-tag--custom {
  color: #0e7490;
  background: rgba(14, 116, 144, 0.12);
}
.st-en {
  margin-top: 6px;
  font-size: 13.5px;
  line-height: 1.5;
  overflow-wrap: anywhere;
}
.st-en--none {
  color: var(--st-muted);
}
.st-edit {
  display: flex;
  align-items: flex-start;
  gap: 8px;
}
.st-input {
  flex: 1;
  border-radius: 10px;
}
.st-row-actions {
  flex: none;
  display: flex;
  align-items: center;
  gap: 2px;
  min-width: 66px;
  min-height: 32px;
  justify-content: flex-end;
}
.st-state {
  font-size: 12px;
  color: var(--st-muted);
  white-space: nowrap;
}
.st-state--ok {
  color: #16a34a;
}
.st-icon-btn {
  border-radius: 8px;
}
.st-empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 8px;
  padding: 40px 12px;
  color: var(--st-muted);
}
.st-empty-ic {
  font-size: 28px;
  opacity: 0.6;
}
.st-foot {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  flex-wrap: wrap;
  padding: 12px 16px;
}
.st-foot-count {
  font-size: 12px;
  color: var(--st-muted);
}

@media (max-width: 767px) {
  .st-head-row { display: none; }
  .st-row { grid-template-columns: 1fr; gap: 8px; }
  .st-hero { flex-wrap: wrap; }
  .st-switch { width: 100%; }
  .st-search { max-width: none; width: 100%; }
}
</style>
