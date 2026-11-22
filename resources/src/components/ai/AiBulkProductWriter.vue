<template>
  <a-drawer
    :open="open"
    :title="tf('Ai_Bulk_Title', 'Write product content with AI')"
    placement="right"
    :width="drawerWidth"
    :mask-closable="!running"
    :closable="!running"
    @update:open="v => $emit('update:open', v)"
  >
    <a-spin :spinning="loadingOptions">
      <!-- Options -->
      <template v-if="!rows.length">
        <a-alert
          type="info" show-icon style="margin-bottom: 16px"
          :message="$t('Ai_Bulk_Count', { n: productIds.length })"
          :description="tf('Ai_Bulk_Hint', 'The AI writes a draft for each product from its saved details and photo. You review the drafts and choose which to save.')"
        />
        <AiWriterOptions v-model="form" :languages="languages" :parts="options?.parts" />
        <a-checkbox v-model:checked="skipDescribed" style="margin-top: 12px">
          {{ tf('Ai_Skip_Described', 'Skip products that already have a description') }}
        </a-checkbox>
      </template>

      <!-- Progress + review -->
      <template v-else>
        <div class="progress">
          <a-progress :percent="percent" :status="running ? 'active' : 'normal'" />
          <div class="progress-text">
            {{ doneCount }} / {{ rows.length }}
            <template v-if="skippedCount"> · {{ tf('Ai_Skipped', 'skipped') }}: {{ skippedCount }}</template>
            <template v-if="failedCount"> · {{ tf('Ai_Failed', 'failed') }}: {{ failedCount }}</template>
          </div>
        </div>

        <div v-for="row in rows" :key="row.id" class="row" :class="row.state">
          <div class="row-head">
            <a-checkbox v-if="row.state === 'done'" v-model:checked="row.selected" />
            <span class="row-name">{{ row.name }}</span>
            <a-tag v-if="row.state === 'pending'">{{ tf('Ai_Waiting', 'Waiting') }}</a-tag>
            <a-tag v-else-if="row.state === 'working'" color="processing"><LoadingOutlined /> {{ tf('Ai_Writing', 'Writing…') }}</a-tag>
            <a-tag v-else-if="row.state === 'skipped'">{{ tf('Ai_Has_Description', 'Has a description') }}</a-tag>
            <a-tag v-else-if="row.state === 'failed'" color="error">{{ row.error }}</a-tag>
            <a-tag v-else-if="row.state === 'saved'" color="success">{{ tf('Ai_Saved', 'Saved') }}</a-tag>
          </div>
          <template v-if="row.draft && (row.state === 'done' || row.state === 'saved')">
            <a-textarea
              v-if="form.parts.includes('description')"
              v-model:value="row.draft.description" :auto-size="{ minRows: 3, maxRows: 10 }"
              :disabled="row.state === 'saved'"
            />
            <div v-if="row.draft.tags.length" class="row-tags">
              <a-tag v-for="tag in row.draft.tags" :key="tag">{{ tag }}</a-tag>
            </div>
            <div v-if="row.draft.faqs.length" class="row-faqs">
              {{ tf('Ai_Part_Faqs', 'FAQs') }}: {{ row.draft.faqs.length }}
            </div>
          </template>
        </div>
      </template>
    </a-spin>

    <template #footer>
      <div class="footer">
        <span class="foot-note">{{ tf('Ai_Bulk_Note', 'Tags and FAQs are added to existing ones; the description replaces the current one.') }}</span>
        <a-space>
          <template v-if="!rows.length">
            <a-button @click="$emit('update:open', false)">{{ $t('Cancel') }}</a-button>
            <a-button type="primary" :disabled="!form.parts.length" @click="start">
              <template #icon><ThunderboltOutlined /></template>
              {{ tf('Ai_Generate', 'Generate') }}
            </a-button>
          </template>
          <template v-else>
            <a-button v-if="running" danger @click="stopRequested = true">{{ tf('Ai_Stop', 'Stop') }}</a-button>
            <a-button v-else @click="reset">{{ tf('Ai_Try_Again', 'Change options') }}</a-button>
            <a-button type="primary" :disabled="running || !selectedCount" :loading="saving" @click="save">
              <template #icon><SaveOutlined /></template>
              {{ tf('Ai_Save_Selected', 'Save selected') }} ({{ selectedCount }})
            </a-button>
          </template>
        </a-space>
      </div>
    </template>
  </a-drawer>
</template>

<script setup>
/**
 * Bulk writer for the products list: one POST ai/product-writer/generate per
 * product (two at a time, so a long batch shows steady progress and can be
 * stopped), then POST ai/product-writer/apply with the drafts the user kept.
 */
import { ref, computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { message } from 'ant-design-vue';
import { ThunderboltOutlined, SaveOutlined, LoadingOutlined } from '@ant-design/icons-vue';
import AiWriterOptions from './AiWriterOptions.vue';
import { useAiWriter } from '../../composables/useAiWriter';
import { t as tf } from '../../i18n';
import http from '../../lib/http';

const props = defineProps({
  open: { type: Boolean, default: false },
  productIds: { type: Array, default: () => [] },
  names: { type: Object, default: () => ({}) }, // id → name, for rows on the current page
});
const emit = defineEmits(['update:open', 'saved']);

const CONCURRENCY = 2;
const { t } = useI18n();
const { options, load } = useAiWriter();
const loadingOptions = ref(false);
const drawerWidth = computed(() => Math.min(640, window.innerWidth));
const form = ref({ language: 'en', tone: 'professional', length: 'medium', parts: ['description', 'tags'], instructions: '', use_image: true });
const skipDescribed = ref(true);
const rows = ref([]);
const running = ref(false);
const stopRequested = ref(false);
const saving = ref(false);

const languages = computed(() => options.value?.languages || []);
const doneCount = computed(() => rows.value.filter(r => !['pending', 'working'].includes(r.state)).length);
const skippedCount = computed(() => rows.value.filter(r => r.state === 'skipped').length);
const failedCount = computed(() => rows.value.filter(r => r.state === 'failed').length);
const percent = computed(() => (rows.value.length ? Math.round((doneCount.value / rows.value.length) * 100) : 0));
const selectedCount = computed(() => rows.value.filter(r => r.state === 'done' && r.selected).length);

watch(() => props.open, async (isOpen) => {
  if (!isOpen || running.value) return;
  reset();
  loadingOptions.value = true;
  await load();
  loadingOptions.value = false;
  if (options.value?.default_language) form.value.language = options.value.default_language;
  // Only the parts this install can store (no tags/FAQs columns in the SaaS yet).
  if (options.value?.parts) form.value.parts = form.value.parts.filter(p => options.value.parts.includes(p));
});

function reset() {
  rows.value = [];
  stopRequested.value = false;
}

async function start() {
  rows.value = props.productIds.map(id => ({
    id, name: props.names[id] || `#${id}`, state: 'pending', draft: null, selected: true, error: '',
  }));
  running.value = true;
  stopRequested.value = false;
  const queue = [...rows.value];

  const worker = async () => {
    while (queue.length && !stopRequested.value) {
      const row = queue.shift();
      row.state = 'working';
      try {
        const data = await http.post('ai/product-writer/generate', {
          ...form.value,
          product_id: row.id,
          skip_described: skipDescribed.value && form.value.parts.includes('description'),
        });
        if (data.skipped) {
          row.state = 'skipped';
        } else {
          row.draft = { description: data.draft.description || '', tags: data.draft.tags || [], faqs: data.draft.faqs || [] };
          row.state = 'done';
        }
      } catch (e) {
        row.state = 'failed';
        row.error = e?.data?.message || tf('Ai_Request_Failed', 'Something went wrong. Please try again.');
        // A configuration problem (bad key, budget) will fail every row — stop early.
        if (e?.status === 422 && rows.value.filter(r => r.state === 'failed').length >= 2) stopRequested.value = true;
      }
    }
  };

  await Promise.all(Array.from({ length: CONCURRENCY }, worker));
  rows.value.filter(r => r.state === 'pending').forEach(r => { r.state = 'failed'; r.error = tf('Ai_Stopped', 'Stopped'); });
  running.value = false;
}

async function save() {
  const picked = rows.value.filter(r => r.state === 'done' && r.selected);
  if (!picked.length) return;
  saving.value = true;
  try {
    const data = await http.post('ai/product-writer/apply', {
      items: picked.map(r => ({
        id: r.id,
        description: form.value.parts.includes('description') ? r.draft.description.trim() : '',
        tags: r.draft.tags,
        faqs: r.draft.faqs,
      })),
    });
    picked.forEach(r => { r.state = 'saved'; });
    message.success(`${tf('Ai_Saved', 'Saved')}: ${data.saved}`);
    emit('saved');
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    saving.value = false;
  }
}
</script>

<style scoped>
.progress { margin-bottom: 16px; }
.progress-text { font-size: 12px; opacity: 0.7; }
.row {
  padding: 10px 12px;
  border-radius: 10px;
  border: 1px solid rgba(128, 128, 128, 0.18);
  margin-bottom: 10px;
}
.row.saved { opacity: 0.7; }
.row-head { display: flex; align-items: center; gap: 8px; margin-bottom: 6px; flex-wrap: wrap; }
.row-name { font-weight: 600; flex: 1; min-width: 0; }
.row-tags { margin-top: 6px; }
.row-faqs { margin-top: 4px; font-size: 12px; opacity: 0.7; }
.footer { display: flex; justify-content: space-between; align-items: center; gap: 8px; flex-wrap: wrap; }
.foot-note { font-size: 12px; opacity: 0.6; flex: 1; min-width: 180px; }
</style>
