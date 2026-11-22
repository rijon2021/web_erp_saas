<template>
  <a-empty v-if="!events.length" :description="$t('Cr_No_Updates_Yet')" />
  <a-timeline v-else class="courier-timeline">
    <a-timeline-item v-for="e in events" :key="e.id" :color="dot(e.status)">
      <div class="tl-head">
        <strong>{{ e.status ? statusLabel(e.status) : (e.raw_status || $t('Cr_Update')) }}</strong>
        <span class="tl-time">{{ dateTime(e.event_at) }}</span>
      </div>
      <div v-if="e.raw_status && e.status" class="tl-raw">{{ e.raw_status }}</div>
      <div v-if="e.description" class="tl-desc">{{ e.description }}</div>
      <div v-if="e.location" class="tl-loc">{{ e.location }}</div>
      <div class="tl-src">{{ $t('Cr_Source_' + (e.source || 'manual')) }}</div>
    </a-timeline-item>
  </a-timeline>
</template>

<script setup>
/** Tracking history of one shipment, newest first (shipment.events). */
import { useI18n } from 'vue-i18n';
import { useFormat } from '../../composables/useFormat';
import { courierStatus } from './courierShared';

defineProps({ events: { type: Array, default: () => [] } });

const { t } = useI18n();
const { dateTime } = useFormat();

const DOTS = { delivered: 'green', returned: 'red', returning: 'orange', failed_attempt: 'orange', cancelled: 'gray' };
const dot = s => DOTS[s] || 'blue';
const statusLabel = s => (courierStatus(s) ? t(courierStatus(s).key) : s);
</script>

<style scoped>
.courier-timeline {
  margin-top: 8px;
}
.tl-head {
  display: flex;
  justify-content: space-between;
  gap: 12px;
}
.tl-time,
.tl-src,
.tl-raw {
  font-size: 12px;
  color: rgba(var(--ink-rgb), 0.45);
}
.tl-desc {
  margin-top: 2px;
}
.tl-loc {
  font-size: 12px;
  color: rgba(var(--ink-rgb), 0.65);
}
</style>
