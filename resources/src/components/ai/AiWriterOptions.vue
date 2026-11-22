<template>
  <a-form layout="vertical" class="ai-writer-options">
    <a-row :gutter="12">
      <a-col :xs="24" :sm="12">
        <a-form-item :label="tf('Ai_Language', 'Language')">
          <a-select
            :value="modelValue.language"
            show-search option-filter-prop="label"
            :options="languageOptions"
            @update:value="v => update('language', v)"
          />
        </a-form-item>
      </a-col>
      <a-col :xs="24" :sm="12">
        <a-form-item :label="tf('Ai_Tone', 'Tone')">
          <a-select :value="modelValue.tone" :options="toneOptions" @update:value="v => update('tone', v)" />
        </a-form-item>
      </a-col>
    </a-row>

    <a-form-item :label="tf('Ai_Length', 'Description length')">
      <a-segmented :value="modelValue.length" :options="lengthOptions" block @update:value="v => update('length', v)" />
    </a-form-item>

    <a-form-item :label="tf('Ai_Write', 'Write')">
      <a-checkbox-group :value="modelValue.parts" :options="partOptions" @update:value="v => update('parts', v)" />
    </a-form-item>

    <a-form-item
      :label="tf('Ai_Instructions', 'Extra instructions (optional)')"
      :extra="tf('Ai_Instructions_Help', 'Facts the AI should mention, e.g. “handmade in Morocco, 2-year warranty”. It never invents details.')"
    >
      <a-textarea
        :value="modelValue.instructions" :rows="2" :maxlength="500" show-count
        @update:value="v => update('instructions', v)"
      />
    </a-form-item>

    <a-form-item v-if="showImageOption" style="margin-bottom: 0">
      <a-checkbox :checked="modelValue.use_image" @update:checked="v => update('use_image', v)">
        {{ tf('Ai_Use_Image', 'Let the AI look at the product photo') }}
      </a-checkbox>
    </a-form-item>
  </a-form>
</template>

<script setup>
/** Shared option fields of the single and bulk product writers (v-model object). */
import { computed } from 'vue';
import { t as tf } from '../../i18n';

const props = defineProps({
  modelValue: { type: Object, required: true },
  languages: { type: Array, default: () => [] },
  showImageOption: { type: Boolean, default: true },
  parts: { type: Array, default: null }, // parts the server can store; null = all
});
const emit = defineEmits(['update:modelValue']);

const update = (key, value) => emit('update:modelValue', { ...props.modelValue, [key]: value });

const languageOptions = computed(() => props.languages.map(l => ({ value: l.locale, label: l.name })));
const toneOptions = computed(() => [
  { value: 'professional', label: tf('Ai_Tone_Professional', 'Professional') },
  { value: 'friendly', label: tf('Ai_Tone_Friendly', 'Friendly') },
  { value: 'luxury', label: tf('Ai_Tone_Luxury', 'Premium') },
  { value: 'technical', label: tf('Ai_Tone_Technical', 'Technical') },
  { value: 'playful', label: tf('Ai_Tone_Playful', 'Playful') },
]);
const lengthOptions = computed(() => [
  { value: 'short', label: tf('Ai_Length_Short', 'Short') },
  { value: 'medium', label: tf('Ai_Length_Medium', 'Medium') },
  { value: 'long', label: tf('Ai_Length_Long', 'Long') },
]);
const partOptions = computed(() => [
  { value: 'description', label: tf('Ai_Part_Description', 'Description') },
  { value: 'tags', label: tf('Ai_Part_Tags', 'Search tags') },
  { value: 'faqs', label: tf('Ai_Part_Faqs', 'FAQs') },
].filter(o => !props.parts || props.parts.includes(o.value)));
</script>

<style scoped>
.ai-writer-options :deep(.ant-form-item) { margin-bottom: 14px; }
</style>
