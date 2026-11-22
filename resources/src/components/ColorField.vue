<template>
  <div class="color-field">
    <input
      type="color"
      class="color-field-swatch"
      :value="swatch"
      @input="e => emit('update:modelValue', e.target.value)"
    />
    <a-input
      :value="modelValue"
      :placeholder="placeholder"
      :status="invalid ? 'error' : ''"
      :title="invalid ? $t('Hex_Color_Invalid_Hint') : ''"
      allow-clear
      style="width: 120px"
      @update:value="v => emit('update:modelValue', v || null)"
    />
  </div>
</template>

<script setup>
/**
 * Hex colour picker + text input. Clearing the input sets null, which
 * callers treat as "use the default" (shown as the placeholder).
 */
import { computed } from 'vue';

const props = defineProps({
  modelValue: { type: String, default: null },
  placeholder: { type: String, default: '#4f46e5' },
});
const emit = defineEmits(['update:modelValue']);

// Same rule as the server (LoginPageAppearance::color).
const invalid = computed(() => !!props.modelValue && !/^#([0-9a-fA-F]{3,4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/.test(props.modelValue));

// <input type="color"> only takes #rrggbb: expand #rgb(a), drop alpha.
const swatch = computed(() => {
  const v = !props.modelValue || invalid.value ? props.placeholder : props.modelValue;
  if (/^#[0-9a-fA-F]{3,4}$/.test(v)) return '#' + [...v.slice(1, 4)].map(c => c + c).join('');
  return v.slice(0, 7);
});
</script>

<style scoped>
.color-field {
  display: flex;
  align-items: center;
  gap: 6px;
}
.color-field-swatch {
  width: 40px;
  height: 32px;
  padding: 2px;
  border: 1px solid var(--line);
  border-radius: 6px;
  background: transparent;
  cursor: pointer;
}
</style>
