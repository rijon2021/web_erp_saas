<template>
  <span>
    <a-dropdown :trigger="['click']">
      <a-button :size="size" :loading="busy">
        <template v-if="iconOnly" #icon><MoreOutlined /></template>
        <span v-if="!iconOnly">{{ $t('Actions') }} <DownOutlined /></span>
      </a-button>
      <template #overlay>
        <a-menu @click="({ key }) => run(key)">
          <a-menu-item v-if="showTimeline" key="timeline"><HistoryOutlined /> {{ $t('Cr_Timeline') }}</a-menu-item>
          <a-menu-item v-if="shipment.can.refresh" key="refresh"><SyncOutlined /> {{ $t('Cr_Track_Now') }}</a-menu-item>
          <a-menu-item v-if="shipment.tracking_link" key="link"><LinkOutlined /> {{ $t('Cr_Open_Tracking_Page') }}</a-menu-item>
          <a-menu-item key="label"><PrinterOutlined /> {{ $t('Cr_Print_Label') }}</a-menu-item>
          <template v-if="canBook">
            <a-menu-item v-if="shipment.can.manual_status" key="status"><EditOutlined /> {{ $t('Cr_Update_Status') }}</a-menu-item>
            <a-menu-item v-if="shipment.can.cod" key="cod"><DollarOutlined /> {{ $t('Cr_Update_COD') }}</a-menu-item>
            <template v-if="shipment.return_status === 'pending'">
              <a-menu-item key="restock"><RollbackOutlined /> {{ $t('Cr_Restock') }}</a-menu-item>
              <a-menu-item key="dismiss"><StopOutlined /> {{ $t('Cr_Dismiss_Return') }}</a-menu-item>
            </template>
            <a-menu-item v-if="shipment.can.cancel" key="cancel" danger><CloseCircleOutlined /> {{ $t('Cr_Cancel_Booking') }}</a-menu-item>
          </template>
          <a-menu-item v-if="showSaleLink" key="sale"><FileTextOutlined /> {{ $t('Cr_View_Sale') }}</a-menu-item>
        </a-menu>
      </template>
    </a-dropdown>

    <!-- Status / COD / return / cancel: one small form modal -->
    <a-modal v-model:open="dialog.open" :title="dialog.title" :confirm-loading="busy" :ok-text="$t('Save')" @ok="submitDialog">
      <a-form layout="vertical" style="margin-top: 12px">
        <a-form-item v-if="dialog.kind === 'status'" :label="$t('Status')">
          <a-select v-model:value="dialog.status" :options="statusOptions" />
        </a-form-item>
        <template v-if="dialog.kind === 'cod'">
          <a-form-item :label="$t('Cr_COD_Status')">
            <a-radio-group v-model:value="dialog.codStatus">
              <a-radio value="collected">{{ $t('Cr_COD_collected') }}</a-radio>
              <a-radio value="remitted">{{ $t('Cr_COD_remitted') }}</a-radio>
            </a-radio-group>
          </a-form-item>
          <a-alert v-if="dialog.codStatus === 'remitted'" type="info" show-icon :message="$t('Cr_COD_Remit_Help')" style="margin-bottom: 12px" />
          <a-form-item :label="$t('Cr_Payout_Reference')">
            <a-input v-model:value="dialog.reference" :maxlength="120" />
          </a-form-item>
        </template>
        <a-alert v-if="dialog.kind === 'restock'" type="info" show-icon :message="$t('Cr_Restock_Help')" style="margin-bottom: 12px" />
        <a-alert v-if="dialog.kind === 'dismiss'" type="warning" show-icon :message="$t('Cr_Dismiss_Help')" style="margin-bottom: 12px" />
        <a-form-item v-if="dialog.kind !== 'cod'" :label="$t('Note')" style="margin-bottom: 0">
          <a-textarea v-model:value="dialog.note" :rows="2" :maxlength="500" />
        </a-form-item>
      </a-form>
    </a-modal>
  </span>
</template>

<script setup>
/**
 * Actions menu for one courier shipment (row of the shipments page, or the
 * sale's courier panel). Emits `updated` with the refreshed shipment from
 * the API and `timeline` when the user asks for the history.
 */
import { ref, reactive, computed } from 'vue';
import { useRouter } from 'vue-router';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import {
  MoreOutlined, DownOutlined, HistoryOutlined, SyncOutlined, LinkOutlined, PrinterOutlined, EditOutlined,
  DollarOutlined, RollbackOutlined, StopOutlined, CloseCircleOutlined, FileTextOutlined,
} from '@ant-design/icons-vue';
import http from '../../lib/http';
import { useAuthStore } from '../../stores/auth';
import { COURIER_STATUSES, errorText } from './courierShared';
import { useShipmentLabels } from './useShipmentLabels';

const props = defineProps({
  shipment: { type: Object, required: true },
  size: { type: String, default: 'small' },
  iconOnly: { type: Boolean, default: true },
  showTimeline: { type: Boolean, default: true },
  showSaleLink: { type: Boolean, default: true },
});
const emit = defineEmits(['updated', 'timeline']);

const { t } = useI18n();
const router = useRouter();
const auth = useAuthStore();
const { printOne } = useShipmentLabels();

const busy = ref(false);
const canBook = computed(() => auth.can('courier_book'));
const statusOptions = computed(() => COURIER_STATUSES
  .filter(s => s.value !== 'cancelled')
  .map(s => ({ value: s.value, label: t(s.key) })));

const dialog = reactive({ open: false, kind: '', title: '', status: null, codStatus: 'collected', reference: '', note: '' });

function openDialog(kind, title, extra = {}) {
  Object.assign(dialog, { open: true, kind, title, status: props.shipment.courier_status, codStatus: 'collected', reference: '', note: '' }, extra);
}

async function post(path, body, okKey = 'Successfully_Updated') {
  busy.value = true;
  try {
    const resp = await http.post(`couriers/shipments/${props.shipment.id}/${path}`, body);
    message.success(t(okKey));
    emit('updated', resp.shipment);
    return true;
  } catch (e) {
    message.error(errorText(e, t('Failed')));
    return false;
  } finally {
    busy.value = false;
  }
}

function run(key) {
  const s = props.shipment;
  const actions = {
    timeline: () => emit('timeline', s),
    refresh: () => post('refresh', {}, 'Cr_Status_Checked'),
    link: () => window.open(s.tracking_link, '_blank', 'noopener'),
    label: () => printOne(s.id),
    status: () => openDialog('status', t('Cr_Update_Status')),
    cod: () => openDialog('cod', t('Cr_Update_COD'), { codStatus: s.cod_status === 'collected' ? 'remitted' : 'collected' }),
    restock: () => openDialog('restock', t('Cr_Restock')),
    dismiss: () => openDialog('dismiss', t('Cr_Dismiss_Return')),
    cancel: () => openDialog('cancel', t('Cr_Cancel_Booking')),
    sale: () => router.push(`/sales/${s.sale_id}`),
  };
  actions[key]?.();
}

async function submitDialog() {
  const d = dialog;
  const calls = {
    status: () => post('status', { status: d.status, note: d.note || null }),
    cod: () => post('cod', { cod_status: d.codStatus, reference: d.reference || null }),
    restock: () => post('return/restock', { note: d.note || null }),
    dismiss: () => post('return/dismiss', { note: d.note || null }),
    cancel: () => post('cancel', { note: d.note || null }),
  };
  if (await calls[d.kind]?.()) dialog.open = false;
}
</script>
