<template>
  <a-tooltip v-if="risk" :title="detail">
    <a-tag :color="color" class="risk-tag">
      <span class="dot" />{{ $t('Cr_Risk_' + risk.level) }}<template v-if="showRate && risk.finished"> · {{ risk.rate }}%</template>
    </a-tag>
  </a-tooltip>
</template>

<script setup>
/**
 * Customer return-risk tag (App\Services\Couriers\ReturnRisk):
 * risk = {level: new|low|medium|high, rate, returned, delivered, finished, total}.
 * Renders nothing without data (walk-in customer, feature off).
 */
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const props = defineProps({
  risk: { type: Object, default: null },
  showRate: { type: Boolean, default: true },
});

const { t } = useI18n();

const COLORS = { high: 'red', medium: 'orange', low: 'green', new: 'default' };
const color = computed(() => COLORS[props.risk?.level] || 'default');
const detail = computed(() => {
  const r = props.risk;
  if (!r) return '';
  if (!r.total) return t('Cr_Risk_None');
  return t('Cr_Risk_Detail', { returned: r.returned, finished: r.finished, rate: r.rate });
});
</script>

<style scoped>
.risk-tag {
  margin-inline-end: 0;
}
.dot {
  display: inline-block;
  width: 6px;
  height: 6px;
  border-radius: 50%;
  background: currentColor;
  margin-inline-end: 5px;
  vertical-align: middle;
}
</style>
