<template>
  <a-modal
    :open="open"
    :title="tf('Ai_Write_Product', 'Write with AI')"
    :width="720"
    :footer="null"
    destroy-on-close
    @update:open="v => $emit('update:open', v)"
  >
    <a-spin :spinning="loadingOptions">
      <!-- Step 1: options -->
      <template v-if="!draft">
        <AiWriterOptions v-model="form" :languages="languages" :parts="options?.parts" :show-image-option="!!productId" />
        <a-alert v-if="error" type="error" show-icon :message="error" style="margin-top: 12px" />
        <div class="actions">
          <a-button @click="$emit('update:open', false)">{{ $t('Cancel') }}</a-button>
          <a-button type="primary" :loading="generating" :disabled="!form.parts.length" @click="generate">
            <template #icon><ThunderboltOutlined /></template>
            {{ generating ? tf('Ai_Writing', 'Writing…') : tf('Ai_Generate', 'Generate') }}
          </a-button>
        </div>
      </template>

      <!-- Step 2: review and apply -->
      <template v-else>
        <a-alert type="info" show-icon :message="tf('Ai_Review_Hint', 'Review and edit the draft, then apply it to the form. Nothing is saved until you save the product.')" style="margin-bottom: 14px" />

        <div v-if="draft.description" class="block">
          <div class="block-head">
            <strong>{{ tf('Ai_Part_Description', 'Description') }}</strong>
            <a-checkbox v-model:checked="use.description">{{ tf('Ai_Use_This', 'Use') }}</a-checkbox>
          </div>
          <a-textarea v-model:value="draft.description" :auto-size="{ minRows: 4, maxRows: 14 }" />
        </div>

        <div v-if="draft.tags.length" class="block">
          <div class="block-head">
            <strong>{{ tf('Ai_Part_Tags', 'Search tags') }}</strong>
            <a-checkbox v-model:checked="use.tags">{{ tf('Ai_Add_To_Existing', 'Add to existing') }}</a-checkbox>
          </div>
          <a-tag v-for="(tag, i) in draft.tags" :key="tag" closable class="tag" @close="draft.tags.splice(i, 1)">{{ tag }}</a-tag>
        </div>

        <div v-if="draft.faqs.length" class="block">
          <div class="block-head">
            <strong>{{ tf('Ai_Part_Faqs', 'FAQs') }}</strong>
            <a-checkbox v-model:checked="use.faqs">{{ tf('Ai_Add_To_Existing', 'Add to existing') }}</a-checkbox>
          </div>
          <div v-for="(f, i) in draft.faqs" :key="i" class="faq">
            <a-input v-model:value="f.question" />
            <a-textarea v-model:value="f.answer" :auto-size="{ minRows: 1, maxRows: 4 }" />
            <a-button type="text" danger size="small" @click="draft.faqs.splice(i, 1)">
              <template #icon><DeleteOutlined /></template>
            </a-button>
          </div>
        </div>

        <a-empty v-if="!draft.description && !draft.tags.length && !draft.faqs.length" :description="tf('Ai_Nothing_Written', 'The AI had nothing to write from these details.')" />

        <div class="foot-note">{{ tf('Ai_Check_Facts', 'Check the facts before publishing — the AI only uses the details you gave it, but can still make mistakes.') }}</div>

        <div class="actions">
          <a-button @click="draft = null">
            <template #icon><ReloadOutlined /></template>
            {{ tf('Ai_Try_Again', 'Change options') }}
          </a-button>
          <a-button type="primary" :disabled="!canApply" @click="apply">
            <template #icon><CheckOutlined /></template>
            {{ tf('Ai_Apply_To_Form', 'Apply to form') }}
          </a-button>
        </div>
      </template>
    </a-spin>
  </a-modal>
</template>

<script setup>
/**
 * Single-product writer for the product form. Sends the form's current
 * values as facts (unsaved edits included) plus the product id for its photo;
 * emits `apply` with the parts the user kept — the form decides how to merge
 * them, and nothing is saved until the product is saved.
 */
import { ref, reactive, computed, watch } from 'vue';
import { ThunderboltOutlined, ReloadOutlined, CheckOutlined, DeleteOutlined } from '@ant-design/icons-vue';
import AiWriterOptions from './AiWriterOptions.vue';
import { useAiWriter } from '../../composables/useAiWriter';
import { t as tf } from '../../i18n';
import http from '../../lib/http';

const props = defineProps({
  open: { type: Boolean, default: false },
  facts: { type: Function, required: true }, // () => facts object, read at generate time
  productId: { type: [Number, String], default: null },
});
const emit = defineEmits(['update:open', 'apply']);

const { options, load } = useAiWriter();
const loadingOptions = ref(false);
const generating = ref(false);
const error = ref('');
const draft = ref(null);
const use = reactive({ description: true, tags: true, faqs: true });
const form = ref({ language: 'en', tone: 'professional', length: 'medium', parts: ['description', 'tags', 'faqs'], instructions: '', use_image: true });

const languages = computed(() => options.value?.languages || []);
const canApply = computed(() => draft.value && (
  (use.description && draft.value.description)
  || (use.tags && draft.value.tags.length)
  || (use.faqs && draft.value.faqs.length)
));

watch(() => props.open, async (isOpen) => {
  if (!isOpen) return;
  draft.value = null;
  error.value = '';
  loadingOptions.value = true;
  await load();
  loadingOptions.value = false;
  if (options.value?.default_language) form.value.language = options.value.default_language;
  // Only the parts this install can store (no tags/FAQs columns in the SaaS yet).
  if (options.value?.parts) form.value.parts = form.value.parts.filter(p => options.value.parts.includes(p));
});

async function generate() {
  generating.value = true;
  error.value = '';
  try {
    const data = await http.post('ai/product-writer/generate', {
      ...form.value,
      product_id: props.productId || null,
      facts: props.facts(),
    });
    draft.value = {
      description: data.draft.description || '',
      tags: [...(data.draft.tags || [])],
      faqs: (data.draft.faqs || []).map(f => ({ ...f })),
    };
    Object.assign(use, { description: true, tags: true, faqs: true });
  } catch (e) {
    error.value = e?.data?.message || tf('Ai_Request_Failed', 'Something went wrong. Please try again.');
  } finally {
    generating.value = false;
  }
}

function apply() {
  emit('apply', {
    description: use.description ? draft.value.description.trim() : '',
    tags: use.tags ? draft.value.tags : [],
    faqs: use.faqs ? draft.value.faqs.filter(f => f.question.trim() && f.answer.trim()) : [],
  });
  emit('update:open', false);
}
</script>

<style scoped>
.actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 16px; flex-wrap: wrap; }
.block { margin-bottom: 16px; }
.block-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px; }
.tag { margin-bottom: 6px; }
.faq { display: grid; grid-template-columns: 1fr 1.6fr auto; gap: 8px; margin-bottom: 8px; align-items: start; }
@media (max-width: 575px) { .faq { grid-template-columns: 1fr auto; } .faq > :nth-child(2) { grid-column: 1 / 2; } }
.foot-note { font-size: 12px; opacity: 0.6; }
</style>
