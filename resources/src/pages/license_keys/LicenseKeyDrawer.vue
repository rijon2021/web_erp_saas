<template>
  <a-drawer
    :open="open"
    :width="drawerWidth"
    :title="$t('LicK_Key_Details')"
    destroy-on-close
    @update:open="v => emit('update:open', v)"
  >
    <a-spin :spinning="loading && !key">
      <a-result v-if="loadError" status="warning" :title="loadError" />

      <template v-else-if="key">
        <!-- ============================== the key ============================== -->
        <div class="key-box">
          <div class="key-main">
            <div class="key-value mono">{{ fullKey || key.masked_key }}</div>
            <a-space :size="6" wrap>
              <a-tag :color="licenseSourceColor(key.source)">{{ licenseSourceLabel(key.source) }}</a-tag>
              <a-tag :color="licenseStatusColor(key.status)">{{ licenseStatusLabel(key.status) }}</a-tag>
              <a-tag v-if="key.license_state && key.license_state !== key.status" :color="licenseStatusColor(key.license_state)">
                {{ licenseStatusLabel(key.license_state) }}
              </a-tag>
              <a-tag v-if="key.returned" color="warning">{{ $t('LicK_From_Return') }}</a-tag>
            </a-space>
          </div>
          <a-space v-if="canReveal" direction="vertical" align="end" :size="4">
            <a-button v-if="!fullKey" :loading="revealing" @click="reveal">
              <template #icon><EyeOutlined /></template>
              {{ $t('LicK_Reveal') }}
            </a-button>
            <a-space v-else :size="4">
              <a-button type="primary" @click="copy(fullKey)">
                <template #icon><CopyOutlined /></template>
                {{ $t('Copy') }}
              </a-button>
              <a-button @click="fullKey = ''">
                <template #icon><EyeInvisibleOutlined /></template>
              </a-button>
            </a-space>
            <span class="muted small">{{ $t('LicK_Reveal_Logged') }}</span>
          </a-space>
        </div>

        <a-alert
          v-if="key.status_reason"
          type="warning" show-icon style="margin-bottom: 16px"
          :message="`${$t('Reason')}: ${key.status_reason}`"
        />

        <!-- ============================== actions ============================== -->
        <div v-if="hasActions" class="actions">
          <template v-if="!isGenerated">
            <a-button v-if="canManage && key.status === 'returned'" type="primary" :loading="acting === 'restock'" @click="restock">
              <template #icon><RollbackOutlined /></template>
              {{ $t('LicK_Restock') }}
            </a-button>
            <a-button v-if="canManage && ['available', 'returned'].includes(key.status)" @click="openReason('void')">
              <template #icon><StopOutlined /></template>
              {{ $t('LicK_Write_Off') }}
            </a-button>
            <a-button v-if="canManage && key.status === 'available'" danger :loading="acting === 'delete'" @click="removeKey">
              <template #icon><DeleteOutlined /></template>
              {{ $t('Delete') }}
            </a-button>
          </template>
          <!-- A generated key taken back by a return / sale edit is not managed here:
               undoing the return (or raising the sale quantity) gives it back. -->
          <template v-else-if="canControl && !key.returned">
            <a-button v-if="!isBlocked" type="primary" @click="openExtend">
              <template #icon><FieldTimeOutlined /></template>
              {{ $t('LicK_Extend') }}
            </a-button>
            <a-button v-if="!isBlocked" @click="openReason('suspend')">
              <template #icon><PauseCircleOutlined /></template>
              {{ $t('LicK_Suspend') }}
            </a-button>
            <a-button v-if="key.status !== 'revoked' && key.license_state !== 'revoked'" danger @click="openReason('revoke')">
              <template #icon><CloseCircleOutlined /></template>
              {{ $t('LicK_Revoke') }}
            </a-button>
            <a-button v-if="isBlocked" type="primary" :loading="acting === 'reactivate'" @click="reactivate">
              <template #icon><CheckCircleOutlined /></template>
              {{ $t('LicK_Reactivate') }}
            </a-button>
          </template>
          <a-button v-if="canManage && key.status === 'sold'" @click="openResend">
            <template #icon><MailOutlined /></template>
            {{ $t('LicK_Resend') }}
          </a-button>
        </div>

        <!-- ============================== details ============================== -->
        <a-descriptions :column="descColumns" size="small" bordered class="block">
          <a-descriptions-item :label="$t('Product')">
            {{ key.product_name || '-' }}
            <span v-if="key.variant_name" class="muted"> · {{ key.variant_name }}</span>
          </a-descriptions-item>
          <a-descriptions-item :label="$t('Customer')">
            <template v-if="key.client_name">
              <div>{{ key.client_name }}</div>
              <div v-if="key.client_email" class="muted small">{{ key.client_email }}</div>
              <div v-if="key.client_phone" class="muted small">{{ key.client_phone }}</div>
            </template>
            <span v-else class="muted">-</span>
          </a-descriptions-item>
          <a-descriptions-item :label="$t('Sale')">
            <template v-if="key.sale_id">
              <router-link v-if="canViewSales" :to="`/sales/${key.sale_id}`">{{ key.sale_ref || ('#' + key.sale_id) }}</router-link>
              <span v-else>{{ key.sale_ref || ('#' + key.sale_id) }}</span>
              <div v-if="key.sale_date" class="muted small">{{ date(key.sale_date) }}</div>
            </template>
            <template v-else-if="key.online_order_id">
              <router-link :to="`/store/orders/${key.online_order_id}`">{{ $t('LicK_Online_Order') }} #{{ key.online_order_id }}</router-link>
            </template>
            <span v-else class="muted">-</span>
          </a-descriptions-item>
          <a-descriptions-item :label="$t('LicK_Sold_At')">{{ key.sold_at ? dateTime(key.sold_at) : '-' }}</a-descriptions-item>
          <a-descriptions-item :label="$t('LicK_Delivered_At')">{{ key.delivered_at ? dateTime(key.delivered_at) : '-' }}</a-descriptions-item>
          <a-descriptions-item :label="$t('Created')">{{ key.created_at ? dateTime(key.created_at) : '-' }}</a-descriptions-item>
          <template v-if="isGenerated">
            <a-descriptions-item :label="$t('LicK_Starts_At')">{{ key.starts_at ? date(key.starts_at) : '-' }}</a-descriptions-item>
            <a-descriptions-item :label="$t('Expiry')">
              <span v-if="!key.expires_at">{{ $t('LicK_Lifetime') }}</span>
              <span v-else :class="{ danger: isPast(key.expires_at) }">{{ date(key.expires_at) }}</span>
            </a-descriptions-item>
            <a-descriptions-item :label="$t('LicK_Activations')">
              {{ key.active_activations || 0 }} / {{ key.max_activations == null ? $t('Unlimited') : key.max_activations }}
            </a-descriptions-item>
            <a-descriptions-item :label="$t('LicK_Restriction')">{{ licenseRestrictionLabel(key.restriction) }}</a-descriptions-item>
          </template>
          <template v-else>
            <a-descriptions-item :label="$t('Cost')">{{ key.cost != null ? money(key.cost) : '-' }}</a-descriptions-item>
          </template>
          <a-descriptions-item v-if="key.batch" :label="$t('Batch')"><span class="mono small">{{ key.batch }}</span></a-descriptions-item>
          <a-descriptions-item v-if="key.note" :label="$t('Note')">{{ key.note }}</a-descriptions-item>
        </a-descriptions>

        <!-- ============================ activations ============================ -->
        <template v-if="isGenerated">
          <div class="section-head">
            <h4>{{ $t('LicK_Activations') }}</h4>
            <a-button
              v-if="canControl && activeActivations.length"
              size="small" danger
              :loading="acting === 'reset_all'"
              @click="resetActivations(null)"
            >
              {{ $t('LicK_Reset_All') }}
            </a-button>
          </div>
          <a-table
            size="small" :pagination="false" row-key="id" :scroll="{ x: 'max-content' }"
            :columns="activationColumns" :data-source="activations" class="block"
          >
            <template #bodyCell="{ column, record }">
              <template v-if="column.key === 'identifier'">
                <div class="mono small">{{ record.identifier }}</div>
                <div v-if="record.label" class="muted small">{{ record.label }}</div>
              </template>
              <template v-else-if="column.key === 'kind'">
                {{ record.kind === 'domain' ? $t('LicK_Kind_Domain') : $t('Device') }}
              </template>
              <template v-else-if="column.key === 'seen'">
                <div class="small">{{ record.activated_at ? dateTime(record.activated_at) : '-' }}</div>
                <div v-if="record.last_seen_at" class="muted small">{{ $t('LicK_Last_Seen') }}: {{ dateTime(record.last_seen_at) }}</div>
              </template>
              <template v-else-if="column.key === 'actions'">
                <a-tag v-if="record.deactivated_at">{{ $t('LicK_Released') }}</a-tag>
                <a-button
                  v-else-if="canControl"
                  size="small"
                  :loading="acting === 'reset_' + record.id"
                  @click="resetActivations(record)"
                >
                  {{ $t('LicK_Release') }}
                </a-button>
              </template>
            </template>
            <template #emptyText>
              <span class="muted">{{ $t('LicK_No_Activations') }}</span>
            </template>
          </a-table>
        </template>

        <!-- =============================== history =============================== -->
        <div class="section-head"><h4>{{ $t('History') }}</h4></div>
        <a-empty v-if="!events.length" :image="simpleImage" />
        <a-timeline v-else class="timeline">
          <a-timeline-item v-for="ev in events" :key="ev.id" :color="licenseEventColor(ev.event)">
            <div class="ev-head">
              <strong>{{ licenseEventLabel(ev.event) }}</strong>
              <span class="muted small">{{ ev.created_at ? dateTime(ev.created_at) : '' }}</span>
            </div>
            <div v-if="ev.note" class="small">{{ ev.note }}</div>
            <div v-if="ev.user" class="muted small">{{ ev.user }}</div>
          </a-timeline-item>
        </a-timeline>
      </template>
    </a-spin>

    <!-- Reason prompt: write off / suspend / revoke -->
    <a-modal
      v-model:open="reasonOpen"
      :title="reasonTitle"
      :confirm-loading="acting === reasonMode"
      :ok-text="$t('submit')"
      :cancel-text="$t('Delete_cancelButtonText')"
      :ok-button-props="reasonMode === 'suspend' ? {} : { danger: true }"
      @ok="submitReason"
    >
      <a-alert type="warning" show-icon style="margin: 8px 0 12px" :message="reasonHelp" />
      <a-form layout="vertical">
        <a-form-item :label="$t('Reason')">
          <a-textarea v-model:value="reasonText" :rows="2" :maxlength="255" />
        </a-form-item>
      </a-form>
    </a-modal>

    <!-- Extend -->
    <a-modal
      v-model:open="extendOpen"
      :title="$t('LicK_Extend')"
      :confirm-loading="acting === 'extend'"
      :ok-text="$t('submit')"
      :cancel-text="$t('Delete_cancelButtonText')"
      @ok="submitExtend"
    >
      <p class="muted" style="margin-top: 8px">
        {{ $t('LicK_Current_Expiry') }}:
        <strong>{{ key?.expires_at ? date(key.expires_at) : $t('LicK_Lifetime') }}</strong>
      </p>
      <a-radio-group v-model:value="extendForm.mode" style="margin-bottom: 12px">
        <a-radio-button value="add">{{ $t('LicK_Ext_Add') }}</a-radio-button>
        <a-radio-button value="date">{{ $t('LicK_Ext_Date') }}</a-radio-button>
        <a-radio-button value="lifetime">{{ $t('LicK_Lifetime') }}</a-radio-button>
      </a-radio-group>
      <div v-if="extendForm.mode === 'add'">
        <a-space>
          <a-input-number v-model:value="extendForm.add_value" :min="1" :max="1000" :precision="0" style="width: 120px" />
          <a-select v-model:value="extendForm.add_unit" style="width: 140px">
            <a-select-option value="days">{{ $t('Days') }}</a-select-option>
            <a-select-option value="months">{{ $t('Months') }}</a-select-option>
            <a-select-option value="years">{{ $t('Years') }}</a-select-option>
          </a-select>
        </a-space>
        <div class="muted small" style="margin-top: 8px">{{ $t('LicK_Ext_Add_Help') }}</div>
      </div>
      <a-date-picker v-else-if="extendForm.mode === 'date'" v-model:value="extendForm.expires_at" value-format="YYYY-MM-DD" style="width: 220px" />
      <div v-else class="muted">{{ $t('LicK_Ext_Lifetime_Help') }}</div>
    </a-modal>

    <!-- Resend -->
    <a-modal
      v-model:open="resendOpen"
      :title="$t('LicK_Resend')"
      :confirm-loading="acting === 'resend'"
      :ok-text="$t('LicK_Send')"
      :cancel-text="$t('Delete_cancelButtonText')"
      @ok="submitResend"
    >
      <a-form layout="vertical" style="margin-top: 8px">
        <a-form-item :label="$t('LicK_Resend_To')" :extra="key?.client_email ? `${$t('LicK_Resend_Default')}: ${key.client_email}` : $t('LicK_Resend_No_Email')">
          <a-input v-model:value="resendEmail" type="email" :placeholder="key?.client_email || 'name@example.com'" />
        </a-form-item>
      </a-form>
    </a-modal>
  </a-drawer>
</template>

<script setup>
/**
 * One license key: GET license-keys/{id} → { key, events, activations }.
 * Actions (each refreshes the drawer and emits `changed`):
 *   uploaded   restock (returned) · void {reason} (available/returned) · DELETE (available)
 *   generated  extend {mode, expires_at | add_value + add_unit} ·
 *              state {action: suspend|revoke|reactivate, reason} ·
 *              reset-activations {activation_id?}
 *   both       resend {email?} (sold) · reveal (logged; perm license_keys_reveal)
 */
import { ref, computed, watch, createVNode } from 'vue';
import { message, Modal, Empty } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import dayjs from 'dayjs';
import {
  EyeOutlined, EyeInvisibleOutlined, CopyOutlined, RollbackOutlined, StopOutlined, DeleteOutlined,
  FieldTimeOutlined, PauseCircleOutlined, CloseCircleOutlined, CheckCircleOutlined, MailOutlined,
  ExclamationCircleOutlined,
} from '@ant-design/icons-vue';
import http from '../../lib/http';
import { useAuthStore } from '../../stores/auth';
import { useFormat } from '../../composables/useFormat';
import {
  licenseError, licenseStatusColor, licenseStatusLabel, licenseSourceColor, licenseSourceLabel,
  licenseEventColor, licenseEventLabel, licenseRestrictionLabel, copyText,
} from '../../composables/useLicenseKeys';

const props = defineProps({
  open: { type: Boolean, default: false },
  keyId: { type: [Number, String], default: null },
});
const emit = defineEmits(['update:open', 'changed']);

const { t } = useI18n();
const { money, date, dateTime } = useFormat();
const auth = useAuthStore();
const simpleImage = Empty.PRESENTED_IMAGE_SIMPLE;

const canManage = computed(() => auth.can('license_keys_manage'));
const canControl = computed(() => auth.can('license_keys_control'));
const canReveal = computed(() => auth.can('license_keys_reveal'));
const canViewSales = computed(() => auth.can('Sales_view'));

const drawerWidth = computed(() => (typeof window !== 'undefined' && window.innerWidth < 760 ? '100%' : 720));
const descColumns = computed(() => (typeof window !== 'undefined' && window.innerWidth < 760 ? 1 : 2));

const loading = ref(false);
const loadError = ref('');
const key = ref(null);
const events = ref([]);
const activations = ref([]);
const fullKey = ref('');
const revealing = ref(false);
const acting = ref('');

const isGenerated = computed(() => key.value?.source === 'generated');
const isBlocked = computed(() => ['suspended', 'revoked'].includes(key.value?.status)
  || ['suspended', 'revoked'].includes(key.value?.license_state));
const activeActivations = computed(() => activations.value.filter(a => !a.deactivated_at));
const hasActions = computed(() => {
  const k = key.value;
  if (!k) return false;
  if (canManage.value && k.status === 'sold') return true;
  if (!isGenerated.value) return canManage.value && ['available', 'returned'].includes(k.status);
  return canControl.value;
});

const isPast = v => !!v && dayjs(v).isBefore(dayjs());

async function load() {
  if (!props.keyId) return;
  loading.value = true;
  loadError.value = '';
  try {
    const data = await http.get(`license-keys/${props.keyId}`);
    key.value = data?.key || null;
    events.value = data?.events || [];
    activations.value = data?.activations || [];
  } catch (e) {
    loadError.value = licenseError(e);
  } finally {
    loading.value = false;
  }
}

watch(() => [props.open, props.keyId], ([o]) => {
  if (!o) return;
  key.value = null;
  events.value = [];
  activations.value = [];
  fullKey.value = '';
  load();
}, { immediate: true });

function changed() {
  emit('changed');
  return load();
}

async function copy(text) {
  if (await copyText(text)) message.success(t('Copied'));
}

async function reveal() {
  revealing.value = true;
  try {
    const data = await http.post(`license-keys/${key.value.id}/reveal`, {});
    fullKey.value = data?.key || '';
    load(); // the reveal is now in the history
  } catch (e) {
    message.error(licenseError(e));
  } finally {
    revealing.value = false;
  }
}

/** Run one action; `what` drives the loading state of its button. */
async function act(what, call, successText = null) {
  acting.value = what;
  try {
    await call();
    message.success(successText || t('Successfully_Updated'));
    await changed();
    return true;
  } catch (e) {
    message.error(licenseError(e));
    return false;
  } finally {
    acting.value = '';
  }
}

function confirm(title, content, onOk, danger = false) {
  Modal.confirm({
    title,
    icon: createVNode(ExclamationCircleOutlined),
    content,
    okText: t('submit'),
    okType: danger ? 'danger' : 'primary',
    cancelText: t('Delete_cancelButtonText'),
    onOk,
  });
}

/* ---------------------------------------------------------- uploaded keys */
function restock() {
  confirm(t('LicK_Restock'), t('LicK_Restock_Confirm'),
    () => act('restock', () => http.post(`license-keys/${key.value.id}/restock`, {})));
}

function removeKey() {
  confirm(t('Delete_Title'), t('LicK_Delete_Confirm'), async () => {
    acting.value = 'delete';
    try {
      await http.delete(`license-keys/${key.value.id}`);
      message.success(t('Successfully_Deleted'));
      emit('changed');
      emit('update:open', false);
    } catch (e) {
      message.error(licenseError(e));
    } finally {
      acting.value = '';
    }
  }, true);
}

/* ------------------------------------------------- reason: void / suspend / revoke */
const reasonOpen = ref(false);
const reasonMode = ref('void');
const reasonText = ref('');
const reasonTitle = computed(() => ({
  void: t('LicK_Write_Off'), suspend: t('LicK_Suspend'), revoke: t('LicK_Revoke'),
}[reasonMode.value]));
const reasonHelp = computed(() => ({
  void: t('LicK_Write_Off_Help'), suspend: t('LicK_Suspend_Help'), revoke: t('LicK_Revoke_Help'),
}[reasonMode.value]));

function openReason(mode) {
  reasonMode.value = mode;
  reasonText.value = '';
  reasonOpen.value = true;
}

async function submitReason() {
  const reason = reasonText.value.trim() || null;
  const id = key.value.id;
  const ok = reasonMode.value === 'void'
    ? await act('void', () => http.post(`license-keys/${id}/void`, { reason }))
    : await act(reasonMode.value, () => http.post(`license-keys/${id}/state`, { action: reasonMode.value, reason }));
  if (ok) reasonOpen.value = false;
}

function reactivate() {
  confirm(t('LicK_Reactivate'), t('LicK_Reactivate_Confirm'),
    () => act('reactivate', () => http.post(`license-keys/${key.value.id}/state`, { action: 'reactivate' })));
}

/* ------------------------------------------------------------------ extend */
const extendOpen = ref(false);
const extendForm = ref({ mode: 'add', add_value: 1, add_unit: 'years', expires_at: null });

function openExtend() {
  extendForm.value = { mode: 'add', add_value: 1, add_unit: 'years', expires_at: null };
  extendOpen.value = true;
}

async function submitExtend() {
  const f = extendForm.value;
  const body = { mode: f.mode };
  if (f.mode === 'date') {
    if (!f.expires_at) {
      message.warning(t('LicK_Pick_Date'));
      return;
    }
    body.expires_at = f.expires_at;
  } else if (f.mode === 'add') {
    body.add_value = Number(f.add_value) || 1;
    body.add_unit = f.add_unit;
  }
  if (await act('extend', () => http.post(`license-keys/${key.value.id}/extend`, body))) extendOpen.value = false;
}

/* ------------------------------------------------------------- activations */
function resetActivations(activation) {
  const what = activation ? 'reset_' + activation.id : 'reset_all';
  const body = activation ? { activation_id: activation.id } : {};
  confirm(
    activation ? t('LicK_Release') : t('LicK_Reset_All'),
    activation ? `${t('LicK_Release_Confirm')} (${activation.identifier})` : t('LicK_Reset_All_Confirm'),
    () => act(what, () => http.post(`license-keys/${key.value.id}/reset-activations`, body)),
    !activation,
  );
}

const activationColumns = computed(() => [
  { title: t('LicK_Identifier'), key: 'identifier' },
  { title: t('Kind'), key: 'kind' },
  { title: t('LicK_IP'), dataIndex: 'ip', key: 'ip' },
  { title: t('LicK_Activated_At'), key: 'seen' },
  { title: '', key: 'actions', align: 'right' },
]);

/* ------------------------------------------------------------------ resend */
const resendOpen = ref(false);
const resendEmail = ref('');

function openResend() {
  resendEmail.value = '';
  resendOpen.value = true;
}

async function submitResend() {
  const email = resendEmail.value.trim();
  if (!email && !key.value.client_email) {
    message.warning(t('LicK_Resend_No_Email'));
    return;
  }
  const ok = await act('resend', () => http.post(`license-keys/${key.value.id}/resend`, email ? { email } : {}), t('LicK_Email_Sent'));
  if (ok) resendOpen.value = false;
}
</script>

<style scoped>
.mono { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; }
.muted { color: rgba(var(--ink-rgb), 0.5); }
.small { font-size: 12px; }
.danger { color: #ff4d4f; }
.block { margin-bottom: 20px; }
.key-box {
  display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; flex-wrap: wrap;
  padding: 14px 16px; margin-bottom: 16px;
  border: 1px solid var(--line); border-radius: 10px;
  background: rgba(var(--ink-rgb), 0.02);
}
.key-main { min-width: 0; flex: 1 1 260px; }
.key-value {
  font-size: 18px; font-weight: 600; letter-spacing: 0.5px;
  word-break: break-all; margin-bottom: 8px;
}
.actions { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 16px; }
.section-head {
  display: flex; align-items: center; justify-content: space-between; gap: 12px;
  margin: 4px 0 10px;
}
.section-head h4 { margin: 0; font-size: 14px; font-weight: 600; }
.timeline { margin-top: 8px; }
.ev-head { display: flex; align-items: baseline; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
</style>
