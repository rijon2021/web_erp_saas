<template>
  <div class="page store-langs" :class="{ 'is-dark': ui.dark }">
    <PageHeader :title="$t(labels.title)" :breadcrumb="[$t(labels.section), $t(labels.title)]">
      <template #actions>
        <a-space wrap>
          <a-button v-if="visitUrl" :href="visitUrl" target="_blank">
            <template #icon><ExportOutlined /></template>
            {{ $t(labels.visit) }}
          </a-button>
          <a-button v-if="allowCustom" type="primary" @click="openAdd">
            <template #icon><PlusOutlined /></template>
            {{ $t('Lang_Add_Custom') }}
          </a-button>
        </a-space>
      </template>
    </PageHeader>

    <!-- Summary strip -->
    <div class="sl-stats">
      <div class="sl-stat">
        <span class="sl-stat-ic"><TranslationOutlined /></span>
        <div>
          <div class="sl-stat-value">{{ languages.length }}</div>
          <div class="sl-stat-label">{{ $t('StoreLang_Installed') }}</div>
        </div>
      </div>
      <div class="sl-stat">
        <span class="sl-stat-ic sl-stat-ic--green"><EyeOutlined /></span>
        <div>
          <div class="sl-stat-value">{{ activeCount }}</div>
          <div class="sl-stat-label">{{ $t(labels.activeOn) }}</div>
        </div>
      </div>
      <div class="sl-stat">
        <span class="sl-stat-ic sl-stat-ic--amber"><StarFilled /></span>
        <div class="sl-stat-default">
          <img v-if="defaultLang?.flag" :src="`/flags/${defaultLang.flag}`" alt="" class="sl-flag sl-flag--sm" />
          <div>
            <div class="sl-stat-value sl-stat-value--text">{{ defaultLang?.name || defaultLocale }}</div>
            <div class="sl-stat-label">{{ $t('StoreLang_Default_Language') }}</div>
          </div>
        </div>
      </div>
      <div class="sl-stat">
        <span class="sl-stat-ic sl-stat-ic--blue"><GlobalOutlined /></span>
        <div>
          <div class="sl-stat-value">{{ available.length }}</div>
          <div class="sl-stat-label">{{ $t('StoreLang_Available') }}</div>
        </div>
      </div>
    </div>

    <!-- Installed -->
    <div class="sl-section-head">
      <h3>{{ $t('StoreLang_Installed') }}</h3>
    </div>

    <a-skeleton v-if="loading && !languages.length" active :paragraph="{ rows: 4 }" />

    <div v-else class="sl-grid">
      <div
        v-for="l in languages" :key="l.id" class="sl-card"
        :class="{ 'sl-card--default': l.is_default, 'sl-card--inactive': !l.is_active }"
      >
        <div class="sl-card-top">
          <img v-if="l.flag" :src="`/flags/${l.flag}`" alt="" class="sl-flag" />
          <div class="sl-card-id">
            <div class="sl-card-name" :dir="l.rtl ? 'rtl' : null">{{ l.name }}</div>
            <div class="sl-card-meta">
              <span class="sl-code">{{ l.locale }}</span>
              <span v-if="l.rtl" class="sl-chip">RTL</span>
              <span v-if="l.is_custom" class="sl-chip sl-chip--custom">{{ $t('Lang_Custom_Tag') }}</span>
            </div>
          </div>
          <span v-if="l.is_default" class="sl-badge sl-badge--default">
            <StarFilled /> {{ $t('Default') }}
          </span>
          <span v-else-if="!l.is_active" class="sl-badge sl-badge--muted">{{ $t('StoreLang_Inactive') }}</span>
        </div>

        <div class="sl-progress">
          <div class="sl-progress-row">
            <span>{{ $t('StoreLang_Translated') }}</span>
            <span class="sl-progress-num">{{ l.progress }}%</span>
          </div>
          <a-progress
            :percent="l.progress" :show-info="false" size="small"
            :stroke-color="l.progress >= 95 ? '#16a34a' : (l.progress >= 60 ? primary : '#f59e0b')"
          />
          <div class="sl-progress-sub">
            <span>{{ l.translated }} / {{ totalKeys }}</span>
            <span v-if="l.customized">· {{ $t('StoreLang_Edited_Count', { count: l.customized }) }}</span>
          </div>
        </div>

        <div class="sl-card-actions">
          <a-button type="primary" ghost size="small" @click="openTranslations(l)">
            <template #icon><EditOutlined /></template>
            {{ $t('StoreLang_Translate') }}
          </a-button>
          <a-button v-if="!l.is_default" size="small" :loading="busy === `default-${l.id}`" @click="setDefault(l)">
            {{ $t('StoreLang_Set_Default') }}
          </a-button>
          <span class="sl-spacer"></span>
          <a-tooltip :title="$t(labels.showOn)">
            <a-switch
              size="small" :checked="l.is_active" :disabled="l.is_default"
              :loading="busy === `active-${l.id}`" @change="toggleActive(l)"
            />
          </a-tooltip>
          <a-dropdown :trigger="['click']" placement="bottomRight">
            <a-button type="text" size="small" class="sl-more"><template #icon><MoreOutlined /></template></a-button>
            <template #overlay>
              <a-menu>
                <a-menu-item key="rename" @click="openRename(l)">
                  <EditOutlined /> {{ $t(allowCustom ? 'Edit' : 'StoreLang_Rename') }}
                </a-menu-item>
                <a-menu-item key="delete" danger :disabled="l.is_protected" @click="remove(l)">
                  <DeleteOutlined /> {{ $t('Delete') }}
                </a-menu-item>
              </a-menu>
            </template>
          </a-dropdown>
        </div>
      </div>
    </div>

    <!-- Available -->
    <div class="sl-section-head sl-section-head--available">
      <div>
        <h3>{{ $t('StoreLang_Available') }}</h3>
        <p>{{ $t(labels.availableHint) }}</p>
      </div>
      <a-input
        v-if="available.length" v-model:value="query" allow-clear class="sl-search"
        :placeholder="$t('StoreLang_Search_Languages')"
      >
        <template #prefix><SearchOutlined /></template>
      </a-input>
    </div>

    <div v-if="!loadingAvailable && !available.length" class="sl-empty">
      <CheckCircleFilled class="sl-empty-ic" />
      <span>{{ $t('StoreLang_All_Installed') }}</span>
    </div>

    <div v-else class="sl-avail-grid">
      <div v-for="a in filteredAvailable" :key="a.locale" class="sl-avail">
        <img v-if="a.flag" :src="`/flags/${a.flag}`" alt="" class="sl-flag sl-flag--sm" />
        <div class="sl-avail-text">
          <div class="sl-avail-name" :dir="a.rtl ? 'rtl' : null">{{ a.name }}</div>
          <div class="sl-avail-sub">{{ a.english_name }} · <span class="sl-code">{{ a.locale }}</span></div>
        </div>
        <a-button
          type="primary" size="small" class="sl-install"
          :loading="installing === a.locale" :disabled="!!installing && installing !== a.locale"
          @click="install(a)"
        >
          <template #icon><DownloadOutlined /></template>
          {{ $t('Install') }}
        </a-button>
      </div>
      <div v-if="available.length && !filteredAvailable.length" class="sl-empty sl-empty--inline">
        {{ $t('No_results') }}
      </div>
    </div>

    <a-modal
      v-model:open="renameOpen" :title="$t(allowCustom ? 'Edit' : 'StoreLang_Rename')" :ok-text="$t('Save')"
      :confirm-loading="saving" @ok="saveRename"
    >
      <a-form layout="vertical">
        <a-form-item :label="$t('Name')">
          <a-input v-model:value="renameValue" :maxlength="100" @press-enter="saveRename" />
        </a-form-item>
        <a-form-item v-if="allowCustom" :label="$t('Flag')">
          <div class="sl-flag-pick">
            <img v-if="renameFlagPreview" :src="renameFlagPreview" alt="" class="sl-flag" />
            <a-upload
              :file-list="renameFlagList" :max-count="1" accept=".png,.jpg,.jpeg,.svg,.webp" :show-upload-list="false"
              :before-upload="f => { renameFlagList = [f]; renameFlagPreview = previewOf(f); return false; }"
            >
              <a-button><template #icon><UploadOutlined /></template>{{ $t('Choose') }}</a-button>
            </a-upload>
          </div>
        </a-form-item>
      </a-form>
    </a-modal>

    <a-modal
      v-if="allowCustom" v-model:open="addOpen" :title="$t('Lang_Add_Custom')" :ok-text="$t('Save')"
      :confirm-loading="adding" @ok="saveAdd"
    >
      <a-alert type="info" show-icon :message="$t('Lang_Add_Custom_Hint')" style="margin-bottom: 16px" />
      <a-form layout="vertical">
        <a-form-item :label="$t('Name')" required>
          <a-input v-model:value="addForm.name" :maxlength="100" placeholder="Deutsch" />
        </a-form-item>
        <a-form-item :label="$t('Locale')" required :extra="$t('Lang_Locale_Help')">
          <a-input v-model:value="addForm.locale" :maxlength="10" placeholder="de" />
        </a-form-item>
        <a-form-item :label="$t('Flag')">
          <div class="sl-flag-pick">
            <img v-if="addFlagPreview" :src="addFlagPreview" alt="" class="sl-flag" />
            <a-upload
              :file-list="addFlagList" :max-count="1" accept=".png,.jpg,.jpeg,.svg,.webp" :show-upload-list="false"
              :before-upload="f => { addFlagList = [f]; addFlagPreview = previewOf(f); return false; }"
            >
              <a-button><template #icon><UploadOutlined /></template>{{ $t('Choose') }}</a-button>
            </a-upload>
          </div>
        </a-form-item>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * Shared "Languages" page for a database-backed surface (Store → Languages,
 * Client Portal → Portal Languages). Endpoints under `apiBase`:
 *   GET  {apiBase}/languages            → { languages[], default_locale, total_keys, visit_url }
 *   GET  {apiBase}/languages/available  → catalog packs not installed yet
 *   POST {apiBase}/languages/install/{locale}
 *   PUT  {apiBase}/languages/{id}       (rename)
 *   POST {apiBase}/languages/{id}/toggle-active | set-default
 *   DELETE {apiBase}/languages/{id}     (blocked for English and the default)
 * Surface-specific wording comes in through `labels` (i18n keys).
 */
import { ref, computed, createVNode, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { message, Modal } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import {
  ExportOutlined, TranslationOutlined, EyeOutlined, StarFilled, GlobalOutlined,
  EditOutlined, MoreOutlined, DeleteOutlined, SearchOutlined, DownloadOutlined,
  CheckCircleFilled, ExclamationCircleOutlined, PlusOutlined, UploadOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';
import { useUiStore } from '../../stores/ui';

const props = defineProps({
  /** e.g. 'admin/store' or 'admin/portal' */
  apiBase: { type: String, required: true },
  /** SPA path of the translations editor, e.g. '/store/translations' */
  translationsPath: { type: String, required: true },
  /** i18n keys: title, section, visit, activeOn, showOn, availableHint, installed, deleteConfirm */
  labels: { type: Object, required: true },
  /**
   * Back office only: languages outside the catalog can be added by hand
   * (POST {apiBase}/languages, multipart name/locale/flag) and the edit dialog
   * takes a flag upload (POST + _method=PUT).
   */
  allowCustom: { type: Boolean, default: false },
});

/** Emitted after any change to the installed set (install / delete / toggle / default). */
const emit = defineEmits(['changed']);

const { t } = useI18n();
const router = useRouter();
const ui = useUiStore();

const primary = computed(() =>
  getComputedStyle(document.documentElement).getPropertyValue('--app-primary').trim() || '#6d28d9'
);

const loading = ref(true);
const languages = ref([]);
const defaultLocale = ref('en');
const totalKeys = ref(0);
const visitUrl = ref('');

const activeCount = computed(() => languages.value.filter(l => l.is_active).length);
const defaultLang = computed(() => languages.value.find(l => l.is_default) || null);

async function load() {
  loading.value = true;
  try {
    const data = await http.get(`${props.apiBase}/languages`);
    languages.value = data?.languages || [];
    defaultLocale.value = data?.default_locale || 'en';
    totalKeys.value = data?.total_keys || 0;
    visitUrl.value = data?.visit_url || '';
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    loading.value = false;
  }
}

// ---------------- available ----------------

const loadingAvailable = ref(true);
const available = ref([]);
const query = ref('');
const installing = ref(null);

const filteredAvailable = computed(() => {
  const q = query.value.trim().toLowerCase();
  if (!q) return available.value;
  return available.value.filter(a =>
    [a.name, a.english_name, a.locale].some(v => String(v || '').toLowerCase().includes(q))
  );
});

async function loadAvailable() {
  loadingAvailable.value = true;
  try {
    const data = await http.get(`${props.apiBase}/languages/available`);
    available.value = Array.isArray(data) ? data : [];
  } catch (e) {
    available.value = [];
  } finally {
    loadingAvailable.value = false;
  }
}

async function install(a) {
  installing.value = a.locale;
  try {
    await http.post(`${props.apiBase}/languages/install/${encodeURIComponent(a.locale)}`);
    message.success(t(props.labels.installed));
    await Promise.all([load(), loadAvailable()]);
    emit('changed');
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    installing.value = null;
  }
}

// ---------------- row actions ----------------

const busy = ref(null);

function openTranslations(l) {
  router.push(`${props.translationsPath}/${encodeURIComponent(l.locale)}`);
}

async function setDefault(l) {
  busy.value = `default-${l.id}`;
  try {
    await http.post(`${props.apiBase}/languages/${l.id}/set-default`);
    message.success(t('Successfully_Updated'));
    await load();
    emit('changed');
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    busy.value = null;
  }
}

async function toggleActive(l) {
  busy.value = `active-${l.id}`;
  try {
    await http.post(`${props.apiBase}/languages/${l.id}/toggle-active`);
    l.is_active = !l.is_active;
    emit('changed');
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    busy.value = null;
  }
}

function remove(l) {
  if (l.is_protected) return;
  Modal.confirm({
    title: t('Delete_Title'),
    icon: createVNode(ExclamationCircleOutlined),
    content: t(props.labels.deleteConfirm, { name: l.name }),
    okType: 'danger',
    okText: t('Delete_confirmButtonText'),
    cancelText: t('Delete_cancelButtonText'),
    async onOk() {
      try {
        await http.delete(`${props.apiBase}/languages/${l.id}`);
        message.success(t('Deleted_in_successfully'));
        await Promise.all([load(), loadAvailable()]);
        emit('changed');
      } catch (e) {
        message.error(e?.data?.message || t('InvalidData'));
      }
    },
  });
}

const renameOpen = ref(false);
const renameValue = ref('');
const renameTarget = ref(null);
const renameFlagList = ref([]);
const renameFlagPreview = ref('');
const saving = ref(false);

function previewOf(file) {
  try { return URL.createObjectURL(file.originFileObj || file); } catch (e) { return ''; }
}

function openRename(l) {
  renameTarget.value = l;
  renameValue.value = l.name;
  renameFlagList.value = [];
  renameFlagPreview.value = l.flag ? `/flags/${l.flag}` : '';
  renameOpen.value = true;
}

async function saveRename() {
  if (!renameTarget.value || !renameValue.value.trim()) return;
  saving.value = true;
  try {
    const url = `${props.apiBase}/languages/${renameTarget.value.id}`;
    if (props.allowCustom && renameFlagList.value.length) {
      const fd = new FormData();
      fd.append('name', renameValue.value.trim());
      fd.append('flag', renameFlagList.value[0].originFileObj || renameFlagList.value[0]);
      const res = await http.postForm(`${url}?_method=PUT`, fd);
      if (res?.flag) renameTarget.value.flag = res.flag;
    } else {
      await http.put(url, { name: renameValue.value.trim() });
    }
    renameTarget.value.name = renameValue.value.trim();
    renameOpen.value = false;
    message.success(t('Successfully_Updated'));
    emit('changed');
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    saving.value = false;
  }
}

// ---------------- add custom language (back office) ----------------

const addOpen = ref(false);
const adding = ref(false);
const addForm = ref({ name: '', locale: '' });
const addFlagList = ref([]);
const addFlagPreview = ref('');

function openAdd() {
  addForm.value = { name: '', locale: '' };
  addFlagList.value = [];
  addFlagPreview.value = '';
  addOpen.value = true;
}

async function saveAdd() {
  const name = addForm.value.name.trim();
  const locale = addForm.value.locale.trim();
  if (!name || !locale) {
    message.error(t('Field_is_required'));
    return;
  }
  adding.value = true;
  try {
    const fd = new FormData();
    fd.append('name', name);
    fd.append('locale', locale);
    if (addFlagList.value.length) fd.append('flag', addFlagList.value[0].originFileObj || addFlagList.value[0]);
    await http.postForm(`${props.apiBase}/languages`, fd);
    addOpen.value = false;
    message.success(t('Successfully_Created'));
    await Promise.all([load(), loadAvailable()]);
    emit('changed');
  } catch (e) {
    const errs = e?.data?.errors;
    message.error((errs && Object.values(errs)[0]?.[0]) || e?.data?.message || t('InvalidData'));
  } finally {
    adding.value = false;
  }
}

onMounted(() => {
  load();
  loadAvailable();
});
</script>

<style scoped>
.store-langs {
  --sl-surface: #fff;
  --sl-border: rgba(0, 0, 0, 0.07);
  --sl-muted: rgba(0, 0, 0, 0.5);
  --sl-soft: rgba(0, 0, 0, 0.035);
  --sl-tint: rgba(var(--app-primary-rgb, 109, 40, 217), 0.08);
}
.store-langs.is-dark {
  --sl-surface: #1f1f1f;
  --sl-border: rgba(255, 255, 255, 0.09);
  --sl-muted: rgba(255, 255, 255, 0.5);
  --sl-soft: rgba(255, 255, 255, 0.05);
  --sl-tint: rgba(var(--app-primary-light-rgb, 139, 92, 246), 0.16);
}

/* ---------- summary strip ---------- */
.sl-stats {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 12px;
  margin-bottom: 22px;
}
.sl-stat {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 14px 16px;
  border-radius: 14px;
  background: var(--sl-surface);
  border: 1px solid var(--sl-border);
}
.sl-stat-ic {
  flex: none;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 40px;
  height: 40px;
  border-radius: 11px;
  font-size: 18px;
  color: var(--app-primary, #6d28d9);
  background: var(--sl-tint);
}
.sl-stat-ic--green { color: #16a34a; background: rgba(22, 163, 74, 0.12); }
.sl-stat-ic--amber { color: #d97706; background: rgba(217, 119, 6, 0.12); }
.sl-stat-ic--blue { color: #2563eb; background: rgba(37, 99, 235, 0.12); }
.sl-stat-value {
  font-size: 22px;
  font-weight: 700;
  line-height: 1.1;
}
.sl-stat-value--text {
  font-size: 15px;
  font-weight: 600;
}
.sl-stat-label {
  font-size: 12px;
  color: var(--sl-muted);
  margin-top: 2px;
}
.sl-stat-default {
  display: flex;
  align-items: center;
  gap: 10px;
  min-width: 0;
}

/* ---------- section heads ---------- */
.sl-section-head {
  display: flex;
  align-items: flex-end;
  justify-content: space-between;
  gap: 12px;
  margin: 4px 0 12px;
}
.sl-section-head h3 {
  margin: 0;
  font-size: 15px;
  font-weight: 600;
}
.sl-section-head p {
  margin: 3px 0 0;
  font-size: 12.5px;
  color: var(--sl-muted);
}
.sl-section-head--available {
  margin-top: 30px;
}
.sl-search {
  max-width: 260px;
  border-radius: 10px;
}

/* ---------- installed cards ---------- */
.sl-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(290px, 1fr));
  gap: 14px;
}
.sl-card {
  display: flex;
  flex-direction: column;
  gap: 14px;
  padding: 16px;
  border-radius: 16px;
  background: var(--sl-surface);
  border: 1px solid var(--sl-border);
  transition: box-shadow 0.2s ease, transform 0.2s ease, border-color 0.2s ease;
}
.sl-card:hover {
  box-shadow: 0 8px 24px rgba(0, 0, 0, 0.07);
  transform: translateY(-1px);
}
.sl-card--default {
  border-color: rgba(var(--app-primary-rgb, 109, 40, 217), 0.45);
  box-shadow: 0 0 0 3px rgba(var(--app-primary-rgb, 109, 40, 217), 0.08);
}
.sl-card--inactive .sl-card-top,
.sl-card--inactive .sl-progress {
  opacity: 0.55;
}
.sl-card-top {
  display: flex;
  align-items: center;
  gap: 12px;
}
.sl-flag {
  flex: none;
  width: 44px;
  height: 32px;
  object-fit: cover;
  border-radius: 8px;
  box-shadow: 0 0 0 1px var(--sl-border), 0 2px 6px rgba(0, 0, 0, 0.08);
}
.sl-flag--sm {
  width: 32px;
  height: 24px;
  border-radius: 6px;
}
.sl-card-id {
  min-width: 0;
  flex: 1;
}
.sl-card-name {
  font-size: 16px;
  font-weight: 600;
  line-height: 1.25;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.sl-card-meta {
  display: flex;
  align-items: center;
  gap: 6px;
  margin-top: 3px;
}
.sl-code {
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
  font-size: 11px;
  padding: 1px 6px;
  border-radius: 5px;
  background: var(--sl-soft);
  color: var(--sl-muted);
}
.sl-chip--custom {
  color: #7c3aed !important;
  background: rgba(124, 58, 237, 0.12) !important;
}
.sl-flag-pick {
  display: flex;
  align-items: center;
  gap: 12px;
}
.sl-chip {
  font-size: 10px;
  font-weight: 600;
  letter-spacing: 0.04em;
  padding: 1px 6px;
  border-radius: 5px;
  color: #0e7490;
  background: rgba(14, 116, 144, 0.12);
}
.sl-badge {
  flex: none;
  display: inline-flex;
  align-items: center;
  gap: 4px;
  font-size: 11px;
  font-weight: 600;
  padding: 3px 9px;
  border-radius: 999px;
}
.sl-badge--default {
  color: var(--app-primary, #6d28d9);
  background: var(--sl-tint);
}
.sl-badge--muted {
  color: var(--sl-muted);
  background: var(--sl-soft);
}
.sl-progress-row {
  display: flex;
  justify-content: space-between;
  font-size: 12px;
  color: var(--sl-muted);
}
.sl-progress-num {
  font-weight: 600;
  color: inherit;
}
.sl-progress :deep(.ant-progress) {
  margin: 4px 0 2px;
}
.sl-progress-sub {
  display: flex;
  gap: 6px;
  font-size: 11.5px;
  color: var(--sl-muted);
}
.sl-card-actions {
  display: flex;
  align-items: center;
  gap: 8px;
  padding-top: 12px;
  border-top: 1px solid var(--sl-border);
}
.sl-spacer {
  flex: 1;
}
.sl-more {
  border-radius: 8px;
}

/* ---------- available grid ---------- */
.sl-avail-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
  gap: 10px;
}
.sl-avail {
  display: flex;
  align-items: center;
  gap: 11px;
  padding: 11px 12px;
  border-radius: 13px;
  background: var(--sl-surface);
  border: 1px dashed var(--sl-border);
  transition: border-color 0.2s ease, background 0.2s ease;
}
.sl-avail:hover {
  border-style: solid;
  border-color: rgba(var(--app-primary-rgb, 109, 40, 217), 0.4);
  background: var(--sl-tint);
}
.sl-avail-text {
  min-width: 0;
  flex: 1;
}
.sl-avail-name {
  font-weight: 600;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.sl-avail-sub {
  font-size: 11.5px;
  color: var(--sl-muted);
  margin-top: 1px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.sl-install {
  flex: none;
  border-radius: 999px;
}
.sl-empty {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  padding: 22px;
  border-radius: 14px;
  border: 1px dashed var(--sl-border);
  color: var(--sl-muted);
}
.sl-empty--inline {
  grid-column: 1 / -1;
}
.sl-empty-ic {
  color: #16a34a;
  font-size: 18px;
}

@media (max-width: 991px) {
  .sl-stats { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
@media (max-width: 575px) {
  .sl-stats { grid-template-columns: 1fr; }
  .sl-section-head { flex-direction: column; align-items: stretch; }
  .sl-search { max-width: none; }
}
</style>
