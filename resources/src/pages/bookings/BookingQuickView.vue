<template>
  <a-modal :open="open" :footer="null" :width="660" destroy-on-close @cancel="close">
    <BookingPanel :booking-id="bookingId" @changed="(b) => $emit('changed', b)" @navigate="close" @failed="close" />
  </a-modal>
</template>

<script setup>
/** BookingPanel in a modal (booking list, day view, calendar). */
import BookingPanel from './BookingPanel.vue';

defineProps({ open: Boolean, bookingId: { type: Number, default: null } });
const emit = defineEmits(['update:open', 'changed']);

function close() {
  emit('update:open', false);
}
</script>
