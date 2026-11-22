<template>
  <div class="wa-tpl">
    <div class="wa-tpl-title">{{ $t('Mk_WA_Template') }}</div>
    <a-alert type="info" show-icon class="wa-tpl-help" :message="$t('Mk_WA_Template_Help')" />

    <a-row :gutter="12">
      <a-col :xs="24" :md="16">
        <a-form-item
          :label="$t('Mk_WA_Template_Name')"
          :validate-status="nameInvalid ? 'error' : ''"
          :help="nameInvalid ? $t('Mk_WA_Template_Name_Invalid') : ''"
        >
          <a-input v-model:value="model.wa_template_name" placeholder="spring_offer" allow-clear @blur="tidyName" />
        </a-form-item>
      </a-col>
      <a-col :xs="24" :md="8">
        <a-form-item :label="$t('Mk_WA_Template_Language')">
          <a-input v-model:value="model.wa_template_language" placeholder="en_US" :disabled="!hasName" />
        </a-form-item>
      </a-col>
    </a-row>

    <template v-if="hasName">
      <div class="wa-tpl-label">{{ $t('Mk_WA_Template_Params') }}</div>
      <div class="wa-tpl-sub">{{ paramsHelp }}</div>

      <div v-for="(_, i) in params" :key="i" class="wa-tpl-param">
        <a-input
          v-model:value="params[i]"
          :addon-before="placeholderLabel(i)"
          @focus="focused = i"
        />
        <a-button type="text" danger :title="$t('Remove')" @click="removeParam(i)">
          <template #icon><DeleteOutlined /></template>
        </a-button>
      </div>

      <div class="wa-tpl-actions">
        <a-button size="small" @click="addParam">
          <template #icon><PlusOutlined /></template>
          {{ $t('Mk_WA_Add_Param') }}
        </a-button>
        <template v-if="params.length">
          <span class="wa-tpl-sub">{{ $t('Mk_WA_Insert_Variable') }}:</span>
          <a-button v-for="v in variables" :key="v" size="small" @click="insertVariable(v)">{{ v }}</a-button>
        </template>
      </div>
    </template>
  </div>
</template>

<script setup>
/**
 * Meta-approved WhatsApp template for a marketing campaign or saved template.
 * Mutates the parent's form object in place (wa_template_name,
 * wa_template_language, wa_template_params[]), like SerialPicker does with its
 * line. Empty name = free-text message; the backend stores nulls then.
 *
 * Meta delivers free text only inside a customer's 24-hour window, so
 * campaigns to everyone else need one of these.
 */
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { PlusOutlined, DeleteOutlined } from '@ant-design/icons-vue';
import { PERSONALIZATION_VARIABLES } from './marketingVocab';

const props = defineProps({
  model: { type: Object, required: true },
});

const { t } = useI18n();
const variables = PERSONALIZATION_VARIABLES;

// Built here, not in the template: a literal "{{1}}" inside a mustache would
// end the interpolation early. Braces go in as params, never in the message.
const placeholderLabel = i => `{{${i + 1}}}`;
const paramsHelp = computed(() => t('Mk_WA_Template_Params_Help', { placeholder: '{{1}}', variable: '{customer_name}' }));
const focused = ref(null);

if (!Array.isArray(props.model.wa_template_params)) props.model.wa_template_params = [];
const params = computed(() => props.model.wa_template_params);

const hasName = computed(() => !!String(props.model.wa_template_name || '').trim());
// Same rule as the API: lowercase letters, digits and underscores.
const nameInvalid = computed(() => hasName.value && !/^[a-z0-9_]+$/.test(String(props.model.wa_template_name).trim()));

/** WhatsApp Manager shows names in lowercase; fix casing/spaces people paste. */
function tidyName() {
  const v = String(props.model.wa_template_name || '').trim();
  props.model.wa_template_name = v ? v.toLowerCase().replace(/\s+/g, '_') : '';
}

function addParam() {
  params.value.push('');
  focused.value = params.value.length - 1;
}

function removeParam(i) {
  params.value.splice(i, 1);
  focused.value = null;
}

function insertVariable(v) {
  const i = focused.value ?? params.value.length - 1;
  if (i < 0 || i >= params.value.length) return;
  params.value[i] = (params.value[i] || '') + v;
}
</script>

<style scoped>
.wa-tpl {
  border: 1px dashed rgba(var(--ink-rgb), 0.15);
  border-radius: 8px;
  padding: 12px;
  margin-bottom: 16px;
}
.wa-tpl-title {
  font-weight: 600;
  margin-bottom: 8px;
}
.wa-tpl-help {
  margin-bottom: 12px;
}
.wa-tpl-label {
  margin-bottom: 2px;
}
.wa-tpl-sub {
  color: rgba(var(--ink-rgb), 0.45);
  font-size: 12px;
  margin-bottom: 8px;
}
.wa-tpl-param {
  display: flex;
  gap: 6px;
  margin-bottom: 8px;
}
.wa-tpl-actions {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 6px;
}
.wa-tpl-actions .wa-tpl-sub {
  margin: 0;
  margin-inline-start: 6px;
}
</style>
