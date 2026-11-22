<template>
  <!-- Online Store > Payment Gateway: per-gateway "auto-confirm paid orders"
       switch. A successful payment through the gateway then confirms the
       order on its own (Sale created, stock deducted). Off by default. -->
  <div class="ac-row" :class="{ 'ac-row--on': checked }">
    <div class="ac-row__text">
      <div class="ac-row__title">
        <CheckSquareOutlined class="ac-row__icon" />
        {{ $t('Pg_Auto_Confirm') }}
      </div>
      <div class="ac-row__help">{{ $t('Pg_Auto_Confirm_Help') }}</div>
    </div>
    <a-switch :checked="checked" :loading="loading" @change="onChange" />
  </div>
</template>

<script setup>
import { CheckSquareOutlined } from '@ant-design/icons-vue';

defineProps({
  checked: { type: Boolean, default: false },
  loading: { type: Boolean, default: false },
});
const emit = defineEmits(['update:checked', 'change']);

function onChange(value) {
  emit('update:checked', value);
  emit('change', value);
}
</script>

<style scoped>
.ac-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  padding: 12px 16px;
  margin-bottom: 20px;
  border: 1px solid var(--line);
  border-radius: 8px;
}
.ac-row--on {
  border-color: rgba(82, 196, 26, 0.45);
  background: rgba(82, 196, 26, 0.06);
}
.ac-row__title {
  font-weight: 600;
  display: flex;
  align-items: center;
  gap: 8px;
}
.ac-row__icon {
  opacity: 0.7;
}
.ac-row__help {
  margin-top: 2px;
  font-size: 12.5px;
  opacity: 0.7;
}
</style>
