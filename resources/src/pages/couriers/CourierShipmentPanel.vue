<template>
  <a-card v-if="visible" size="small" class="courier-panel" :loading="loading">
    <template #title>
      <CarOutlined /> {{ $t('Cr_Courier_Shipment') }}
    </template>
    <template #extra>
      <a-space>
        <a-button v-if="canBook && (!shipment || data.can_book)" type="primary" size="small" @click="bookingOpen = true">
          {{ $t('Cr_Send_To_Courier') }}
        </a-button>
        <CourierShipmentActions
          v-if="shipment" :shipment="shipment" :show-timeline="false" :show-sale-link="false"
          :icon-only="false" @updated="onUpdated"
        />
      </a-space>
    </template>

    <a-empty v-if="!shipment" :description="$t('Cr_Not_Sent_Yet')" />
    <template v-else>
      <a-descriptions :column="{ xs: 1, sm: 2, lg: 4 }" size="small">
        <a-descriptions-item :label="$t('Cr_Courier')">{{ shipment.account_name }}</a-descriptions-item>
        <a-descriptions-item :label="$t('Cr_Tracking_Number')">
          <a v-if="shipment.tracking_link" :href="shipment.tracking_link" target="_blank" rel="noopener">{{ shipment.tracking_number }}</a>
          <span v-else>{{ shipment.tracking_number }}</span>
        </a-descriptions-item>
        <a-descriptions-item :label="$t('Status')">
          <a-tag :color="statusMeta?.color">{{ statusMeta ? $t(statusMeta.key) : shipment.courier_status }}</a-tag>
        </a-descriptions-item>
        <a-descriptions-item :label="$t('Cr_COD')">
          <span v-if="shipment.cod_amount > 0">{{ money(shipment.cod_amount) }} · <a-tag>{{ $t('Cr_COD_' + (shipment.cod_status || 'none')) }}</a-tag></span>
          <span v-else>{{ $t('Cr_Prepaid') }}</span>
        </a-descriptions-item>
        <a-descriptions-item v-if="shipment.return_risk" :label="$t('Cr_Return_Risk')">
          <ReturnRiskBadge :risk="shipment.return_risk" />
        </a-descriptions-item>
        <a-descriptions-item v-if="shipment.customer_tracking_url" :label="$t('Cr_Customer_Link')">
          <a :href="shipment.customer_tracking_url" target="_blank" rel="noopener">{{ $t('Cr_Open_Page') }}</a>
        </a-descriptions-item>
      </a-descriptions>
      <a-alert v-if="shipment.return_status === 'pending'" type="warning" show-icon :message="$t('Cr_Return_Waiting')" style="margin-bottom: 12px" />
      <a-alert v-if="shipment.sync_error" type="error" show-icon :message="shipment.sync_error" style="margin-bottom: 12px" />
      <CourierTimeline :events="shipment.events || []" />
    </template>

    <CourierBookingModal v-model:open="bookingOpen" :sale-id="saleId" @booked="load" />
  </a-card>
</template>

<script setup>
/**
 * Courier block on the sale page: booking button, current status, COD,
 * actions and the tracking timeline. GET couriers/sales/{id} → {shipment, can_book}.
 * Hidden unless the plan has the courier feature and the user may see shipments.
 */
import { ref, computed, watch } from 'vue';
import { CarOutlined } from '@ant-design/icons-vue';
import { useAuthStore } from '../../stores/auth';
import { usePlanStore } from '../../stores/plan';
import { useFormat } from '../../composables/useFormat';
import http from '../../lib/http';
import { courierStatus } from './courierShared';
import CourierTimeline from './CourierTimeline.vue';
import CourierShipmentActions from './CourierShipmentActions.vue';
import CourierBookingModal from './CourierBookingModal.vue';
import ReturnRiskBadge from './ReturnRiskBadge.vue';

const props = defineProps({ saleId: { type: [Number, String], required: true } });

const auth = useAuthStore();
const plan = usePlanStore();
const { money } = useFormat();

const visible = computed(() => plan.featureEnabled('couriers') && auth.can('courier_shipments'));
const canBook = computed(() => auth.can('courier_book'));
const loading = ref(false);
const data = ref({ shipment: null, can_book: true });
const bookingOpen = ref(false);

const shipment = computed(() => data.value.shipment);
const statusMeta = computed(() => courierStatus(shipment.value?.courier_status));

async function load() {
  if (!visible.value || !props.saleId) return;
  loading.value = true;
  try {
    data.value = await http.get(`couriers/sales/${props.saleId}`);
  } catch (e) {
    data.value = { shipment: null, can_book: false };
  } finally {
    loading.value = false;
  }
}

function onUpdated(s) {
  data.value = { ...data.value, shipment: s, can_book: s.courier_status === 'cancelled' };
}

/** Lets the page header button open the booking modal. */
function openBooking() {
  bookingOpen.value = true;
}
defineExpose({ openBooking, reload: load });

watch(() => props.saleId, load, { immediate: true });
</script>

<style scoped>
.courier-panel {
  margin-top: 16px;
}
@media print {
  .courier-panel {
    display: none !important;
  }
}
</style>
