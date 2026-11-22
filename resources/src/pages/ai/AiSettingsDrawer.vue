<template>
  <a-drawer
    :open="open"
    :title="tf('AI_Settings', 'AI Assistant settings')"
    placement="right"
    :width="drawerWidth"
    @update:open="v => $emit('update:open', v)"
    @after-open-change="v => v && load()"
  >
    <a-spin :spinning="loading">
      <a-form layout="vertical">
        <a-form-item :label="tf('Ai_Enable', 'Enable the AI assistant')">
          <a-switch v-model:checked="form.enabled" />
        </a-form-item>

        <a-form-item :label="tf('Ai_Provider', 'AI provider')" :extra="providerHelp">
          <a-segmented v-model:value="form.provider" :options="providerOptions" block />
        </a-form-item>

        <a-form-item :label="current.keyLabel" :extra="keyHelp">
          <a-input-password
            :key="form.provider"
            v-model:value="form[current.keyField]"
            :placeholder="info[current.maskedField] || current.placeholder"
            autocomplete="off"
          />
          <a-button v-if="info[current.maskedField]" type="link" danger size="small" style="padding: 0" @click="clearKey">
            {{ tf('Ai_Remove_Key', 'Remove the stored key') }}
          </a-button>
        </a-form-item>

        <a-form-item :label="tf('Ai_Model', 'Model')" :extra="current.modelHelp">
          <a-select v-if="form.provider === 'anthropic'" v-model:value="form.model" :options="modelOptions" />
          <a-auto-complete
            v-else
            :key="form.provider"
            v-model:value="form[current.modelField]"
            :options="current.modelOptions"
            style="width: 100%"
          />
        </a-form-item>

        <a-form-item :label="tf('Ai_Effort', 'Thoroughness')" :extra="tf('Ai_Effort_Help', 'Higher takes longer and uses more tokens, but handles harder questions better.')">
          <a-segmented v-model:value="form.effort" :options="effortOptions" block />
        </a-form-item>

        <a-form-item :label="tf('Ai_Insights', 'Daily insights on the dashboard')" :extra="tf('Ai_Insights_Help', 'One short AI summary per day, written when the dashboard is first opened.')">
          <a-switch v-model:checked="form.insights_enabled" />
        </a-form-item>

        <a-form-item :label="tf('Ai_Monthly_Limit', 'Monthly token limit')" :extra="usageText">
          <a-input-number
            v-model:value="form.monthly_token_limit"
            :min="1000"
            :step="100000"
            :placeholder="tf('Ai_No_Limit', 'No limit')"
            style="width: 100%"
          />
        </a-form-item>
      </a-form>

      <a-alert
        v-if="testResult"
        :type="testResult.ok ? 'success' : 'error'"
        show-icon
        :message="testResult.text"
        style="margin-bottom: 16px"
      />
    </a-spin>

    <template #footer>
      <div class="footer">
        <a-button :loading="testing" @click="test">
          <template #icon><ApiOutlined /></template>
          {{ tf('Ai_Test_Connection', 'Test connection') }}
        </a-button>
        <a-space>
          <a-button @click="$emit('update:open', false)">{{ $t('Cancel') }}</a-button>
          <a-button type="primary" :loading="saving" @click="save">{{ $t('Save') }}</a-button>
        </a-space>
      </div>
    </template>
  </a-drawer>
</template>

<script setup>
/**
 * GET/PUT ai/settings, POST ai/settings/test. The provider is Claude
 * (anthropic), ChatGPT (openai) or Gemini (gemini); each keeps its own key
 * and model, so switching back and forth loses nothing. Keys are write-only:
 * the server only ever returns a masked form; leaving a field blank keeps the
 * stored key.
 */
import { ref, reactive, computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { message } from 'ant-design-vue';
import { ApiOutlined } from '@ant-design/icons-vue';
import { t as tf } from '../../i18n';
import http from '../../lib/http';

defineProps({ open: { type: Boolean, default: false } });
const emit = defineEmits(['update:open', 'saved']);

const { t } = useI18n();
const drawerWidth = computed(() => Math.min(440, window.innerWidth));

const loading = ref(false);
const saving = ref(false);
const testing = ref(false);
const testResult = ref(null);
const info = reactive({
  masked_key: null,
  openai_masked_key: null,
  gemini_masked_key: null,
  providers: ['anthropic'],
  openai_models: [],
  gemini_models: [],
  month_tokens: 0,
});
const form = reactive({
  enabled: false,
  provider: 'anthropic',
  api_key: '',
  clear_key: false,
  openai_api_key: '',
  clear_openai_key: false,
  gemini_api_key: '',
  clear_gemini_key: false,
  model: 'claude-opus-5-5',
  openai_model: 'gpt-6.1-sol',
  gemini_model: 'gemini-3.8-flash',
  effort: 'medium',
  insights_enabled: true,
  monthly_token_limit: null,
});

const modelOptions = [
  { value: 'claude-opus-5-5', label: 'Claude Opus 5.5' },
  { value: 'claude-sonnet-5-5', label: 'Claude Sonnet 5.5' },
  { value: 'claude-haiku-5-5', label: 'Claude Haiku 5.5' },
];

/** Form fields and texts per provider (Claude keeps its original field names). */
const providers = computed(() => ({
  anthropic: {
    label: 'Claude',
    keyField: 'api_key',
    clearField: 'clear_key',
    maskedField: 'masked_key',
    placeholder: 'sk-ant-…',
    keyLabel: tf('Ai_Api_Key', 'Claude API key'),
    keyHelp: tf('Ai_Key_Help', 'Create one at console.anthropic.com. It is stored encrypted and never sent to the browser.'),
    modelHelp: tf('Ai_Model_Help', 'Opus is the most capable; Sonnet and Haiku answer faster at a lower cost.'),
  },
  openai: {
    label: 'ChatGPT',
    keyField: 'openai_api_key',
    clearField: 'clear_openai_key',
    maskedField: 'openai_masked_key',
    modelField: 'openai_model',
    modelOptions: info.openai_models.map(value => ({ value })),
    placeholder: 'sk-…',
    keyLabel: tf('Ai_OpenAI_Api_Key', 'ChatGPT (OpenAI) API key'),
    keyHelp: tf('Ai_OpenAI_Key_Help', 'Create one at platform.openai.com/api-keys. It is stored encrypted and never sent to the browser.'),
    modelHelp: tf('Ai_OpenAI_Model_Help', 'Pick a suggested model or type any OpenAI model id your key can use.'),
  },
  gemini: {
    label: 'Gemini',
    keyField: 'gemini_api_key',
    clearField: 'clear_gemini_key',
    maskedField: 'gemini_masked_key',
    modelField: 'gemini_model',
    modelOptions: info.gemini_models.map(value => ({ value })),
    placeholder: 'AIza…',
    keyLabel: tf('Ai_Gemini_Api_Key', 'Gemini (Google) API key'),
    keyHelp: tf('Ai_Gemini_Key_Help', 'Create one at aistudio.google.com/apikey. It is stored encrypted and never sent to the browser.'),
    modelHelp: tf('Ai_Gemini_Model_Help', 'Pick a suggested model or type any Gemini model id your key can use.'),
  },
}));
const current = computed(() => providers.value[form.provider] || providers.value.anthropic);

const providerOptions = computed(() => Object.entries(providers.value).map(([value, p]) => ({
  value,
  label: p.label,
  disabled: !info.providers.includes(value),
})));
const providerHelp = computed(() => info.providers.length === Object.keys(providers.value).length
  ? tf('Ai_Provider_Help', 'Used for the chat, dashboard insights, product texts and invoice scanning. Each provider keeps its own key.')
  : tf('Ai_Provider_Not_Ready', 'Run the latest system update to enable more AI providers.'));
const effortOptions = computed(() => [
  { value: 'low', label: tf('Ai_Effort_Low', 'Fast') },
  { value: 'medium', label: tf('Ai_Effort_Medium', 'Balanced') },
  { value: 'high', label: tf('Ai_Effort_High', 'Thorough') },
]);

const keyHelp = computed(() => info[current.value.maskedField]
  ? tf('Ai_Key_Stored', 'A key is stored (encrypted). Leave blank to keep it.')
  : current.value.keyHelp);
const usageText = computed(() =>
  `${tf('Ai_Used_This_Month', 'Used this month')}: ${Number(info.month_tokens || 0).toLocaleString()} ${tf('Ai_Tokens', 'tokens')}`
);

async function load() {
  loading.value = true;
  testResult.value = null;
  try {
    const data = await http.get('ai/settings');
    Object.assign(info, {
      masked_key: data.masked_key,
      openai_masked_key: data.openai_masked_key || null,
      gemini_masked_key: data.gemini_masked_key || null,
      providers: data.providers || ['anthropic'],
      openai_models: data.openai_models || [],
      gemini_models: data.gemini_models || [],
      month_tokens: data.month_tokens,
    });
    Object.assign(form, {
      enabled: !!data.enabled,
      provider: data.provider || 'anthropic',
      api_key: '',
      clear_key: false,
      openai_api_key: '',
      clear_openai_key: false,
      gemini_api_key: '',
      clear_gemini_key: false,
      model: data.model || 'claude-opus-5-5',
      openai_model: data.openai_model || 'gpt-6.1-sol',
      gemini_model: data.gemini_model || 'gemini-3.8-flash',
      effort: data.effort || 'medium',
      insights_enabled: !!data.insights_enabled,
      monthly_token_limit: data.monthly_token_limit ?? null,
    });
  } catch (e) {
    message.error(t('InvalidData'));
  } finally {
    loading.value = false;
  }
}

/** Removes the key of the provider on screen (applied on save). */
function clearKey() {
  const p = current.value;
  form[p.clearField] = true;
  form[p.keyField] = '';
  info[p.maskedField] = null;
}

async function persist() {
  await http.put('ai/settings', {
    ...form,
    openai_model: (form.openai_model || '').trim() || null,
    gemini_model: (form.gemini_model || '').trim() || null,
    monthly_token_limit: form.monthly_token_limit || null,
  });
  Object.assign(form, {
    api_key: '', clear_key: false,
    openai_api_key: '', clear_openai_key: false,
    gemini_api_key: '', clear_gemini_key: false,
  });
}

async function save() {
  saving.value = true;
  try {
    await persist();
    message.success(t('Successfully_Updated'));
    emit('saved');
    emit('update:open', false);
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    saving.value = false;
  }
}

/** Saves first, so the test uses exactly what is on screen. */
async function test() {
  testing.value = true;
  testResult.value = null;
  try {
    await persist();
    const data = await http.post('ai/settings/test', {});
    await load(); // refresh the masked key / usage (clears testResult)
    testResult.value = { ok: true, text: `${tf('Ai_Test_Ok', 'Connected')} — ${data.model}` };
    emit('saved');
  } catch (e) {
    testResult.value = { ok: false, text: e?.data?.message || tf('Ai_Request_Failed', 'Something went wrong. Please try again.') };
  } finally {
    testing.value = false;
  }
}
</script>

<style scoped>
.footer { display: flex; justify-content: space-between; align-items: center; gap: 8px; flex-wrap: wrap; }
</style>
