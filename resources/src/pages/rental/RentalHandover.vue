<template>
  <div class="page">
    <PageHeader
      :title="isCheckin ? $t('Rental_Return_Items') : $t('Rental_Hand_Over')"
      :subtitle="meta ? `${meta.contract.contract_number} · ${meta.contract.customer_name}` : ''"
      :breadcrumb="[$t('Rental_Management'), $t('Rental_Contracts'), meta ? meta.contract.contract_number : '', isCheckin ? $t('Rental_Return_Items') : $t('Rental_Hand_Over')]"
    >
      <template #actions>
        <a-button @click="$router.push(`/rental/contracts/${id}`)">{{ $t('Cancel') }}</a-button>
      </template>
    </PageHeader>

    <div v-if="loading" class="loading"><a-spin size="large" /></div>

    <a-result v-else-if="meta && !meta.lines.length" status="info"
      :title="isCheckin ? $t('Rental_Nothing_To_Return') : $t('Rental_Nothing_To_Hand_Over')">
      <template #extra>
        <a-button type="primary" @click="$router.push(`/rental/contracts/${id}`)">{{ $t('Rental_Back_To_Contract') }}</a-button>
      </template>
    </a-result>

    <template v-else-if="meta">
      <a-card size="small" class="block">
        <a-steps :current="step" size="small" :items="stepItems" class="steps" @change="goStep" />
      </a-card>

      <a-row :gutter="16">
        <a-col :xs="24" :lg="16">
          <!-- 1. Items -->
          <a-card v-show="step === 0" size="small" class="block" :title="isCheckin ? $t('Rental_What_Comes_Back') : $t('Rental_What_Goes_Out')">
            <div class="lines">
              <div v-for="l in lines" :key="l.id" class="line" :class="{ off: !l.include }">
                <div class="line-head">
                  <a-checkbox v-model:checked="l.include" />
                  <span class="thumb">
                    <img v-if="l.image_url" :src="l.image_url" alt="" />
                    <component :is="typeIcon(l.type)" v-else :size="18" :stroke-width="1.6" />
                  </span>
                  <span class="line-title">
                    <strong>{{ l.item_name }}</strong>
                    <span>{{ l.item_code }}<template v-if="l.quantity > 1"> · × {{ l.quantity }}</template></span>
                  </span>
                </div>
                <a-row v-if="l.include" :gutter="12" class="line-fields">
                  <a-col :xs="12" :md="8">
                    <label class="lbl">{{ $t('Rental_Condition') }}</label>
                    <a-select v-model:value="l.condition" :options="conditionOptions" style="width: 100%" />
                  </a-col>
                  <a-col :xs="12" :md="8">
                    <label class="lbl">{{ $t('Rental_Meter_Reading') }}<template v-if="l.meter_unit"> ({{ l.meter_unit }})</template></label>
                    <a-input-number v-model:value="l.meter_reading" :min="0" style="width: 100%" :placeholder="l.prev_meter !== null ? String(l.prev_meter) : ''" />
                  </a-col>
                  <template v-if="isCheckin">
                    <a-col :xs="24" :md="8">
                      <label class="lbl">{{ $t('Rental_Damage_Charge') }}</label>
                      <a-input-number v-model:value="l.damage_charge" :min="0" style="width: 100%" />
                    </a-col>
                    <a-col :xs="24" :md="16">
                      <label class="lbl">{{ $t('Rental_Damage_Notes') }}</label>
                      <a-input v-model:value="l.damage_notes" :placeholder="$t('Rental_Damage_Notes_Placeholder')" allow-clear />
                    </a-col>
                    <a-col :xs="24" :md="8" class="maint">
                      <a-checkbox v-model:checked="l.needs_maintenance">{{ $t('Rental_Send_To_Maintenance') }}</a-checkbox>
                    </a-col>
                  </template>
                </a-row>
              </div>
            </div>
          </a-card>

          <!-- 2. Checklist + photos -->
          <a-card v-show="step === 1" size="small" class="block" :title="$t('Rental_Checklist_Photos')">
            <div class="checklist">
              <label v-for="(c, i) in checklist" :key="i" class="check" :class="{ done: c.checked }">
                <a-checkbox v-model:checked="c.checked" />
                <span>{{ c.label }}</span>
              </label>
              <div class="check-add">
                <a-input v-model:value="newCheck" :placeholder="$t('Rental_Add_Check_Placeholder')" @press-enter="addCheck" />
                <a-button @click="addCheck"><template #icon><PlusOutlined /></template></a-button>
              </div>
            </div>
            <a-divider />
            <h4 class="sub-title">{{ $t('Rental_Photos') }}</h4>
            <div class="photos">
              <div v-for="(p, i) in photos" :key="p.key" class="photo">
                <img :src="p.url" alt="" />
                <button type="button" class="photo-x" @click="photos.splice(i, 1)"><CloseOutlined /></button>
              </div>
              <a-upload v-if="photos.length < 20" :show-upload-list="false" accept="image/*" multiple :before-upload="pickPhoto">
                <button type="button" class="photo-add"><CameraOutlined /><span>{{ $t('Rental_Add_Photos') }}</span></button>
              </a-upload>
            </div>
            <a-divider />
            <h4 class="sub-title">{{ $t('Rental_Note') }}</h4>
            <a-textarea v-model:value="notes" :rows="3" :placeholder="$t('Rental_Handover_Notes_Placeholder')" />
          </a-card>

          <!-- 3. Money -->
          <a-card v-show="step === 2" size="small" class="block" :title="isCheckin ? $t('Rental_Charges_Deposit') : $t('Rental_Security_Deposit')">
            <template v-if="!isCheckin">
              <div class="dep-head">
                <div><span>{{ $t('Rental_Deposit_Agreed') }}</span><strong>{{ money(meta.deposit.agreed) }}</strong></div>
                <div><span>{{ $t('Rental_Deposit_Held') }}</span><strong>{{ money(meta.deposit.held) }}</strong></div>
              </div>
              <a-row v-if="canDeposits" :gutter="12">
                <a-col :xs="24" :md="8">
                  <label class="lbl">{{ $t('Rental_Collect_Now') }}</label>
                  <a-input-number v-model:value="deposit.amount" :min="0" style="width: 100%" />
                </a-col>
                <a-col :xs="12" :md="8">
                  <label class="lbl">{{ $t('Paymentchoice') }}</label>
                  <a-select v-model:value="deposit.payment_method_id" :options="methodOptions" style="width: 100%" />
                </a-col>
                <a-col :xs="12" :md="8">
                  <label class="lbl">{{ $t('Account') }}</label>
                  <a-select v-model:value="deposit.account_id" :options="accountOptions" allow-clear style="width: 100%" />
                </a-col>
              </a-row>
              <a-alert v-else type="info" show-icon :message="$t('Rental_No_Deposit_Rights')" />
            </template>

            <template v-else>
              <!-- Late return -->
              <div v-if="meta.late.periods" class="late">
                <div class="late-text">
                  <ClockCircleOutlined />
                  <span>{{ tp('Rental_Late_By', 'Returned {n} {unit} after the agreed end.', { n: meta.late.periods, unit: unitLabel(meta.contract.rate_period) }) }}</span>
                </div>
                <a-checkbox v-model:checked="lateOn">{{ $t('Rental_Charge_Late_Return') }}</a-checkbox>
                <a-input-number v-if="lateOn" v-model:value="lateCharge" :min="0" style="width: 160px" @change="lateTouched = true" />
              </div>

              <h4 class="sub-title">{{ $t('Rental_Extra_Charges') }}</h4>
              <div v-for="(ch, i) in charges" :key="i" class="charge">
                <a-input v-model:value="ch.description" :placeholder="$t('Rental_Charge_Placeholder')" />
                <a-input-number v-model:value="ch.amount" :min="0" style="width: 150px" />
                <a-button type="text" danger @click="charges.splice(i, 1)"><template #icon><DeleteOutlined /></template></a-button>
              </div>
              <a-button type="dashed" size="small" @click="charges.push({ description: '', amount: null })">
                <template #icon><PlusOutlined /></template>{{ $t('Rental_Add_Charge') }}
              </a-button>

              <a-divider />
              <div class="settle">
                <div class="settle-row"><span>{{ $t('Rental_Owed_Before') }}</span><strong>{{ money(meta.outstanding) }}</strong></div>
                <div class="settle-row"><span>{{ $t('Rental_New_Charges') }}</span><strong>{{ money(newCharges) }}</strong></div>
                <div class="settle-row total"><span>{{ $t('Rental_Total_Owed') }}</span><strong>{{ money(totalOwed) }}</strong></div>
                <div class="settle-row"><span>{{ $t('Rental_Deposit_Held') }}</span><strong>{{ money(meta.deposit.held) }}</strong></div>
              </div>

              <template v-if="canDeposits && meta.deposit.held > 0">
                <a-checkbox v-model:checked="deposit.apply" class="apply">
                  {{ $t('Rental_Use_Deposit_For_Debt') }} <strong>({{ money(applied) }})</strong>
                </a-checkbox>
                <a-row :gutter="12" class="dep-fields">
                  <a-col :xs="12" :md="6">
                    <label class="lbl">{{ $t('Rental_Keep') }}</label>
                    <a-input-number v-model:value="deposit.retain_amount" :min="0" :max="remaining" style="width: 100%" @change="autoRefund" />
                  </a-col>
                  <a-col :xs="12" :md="6">
                    <label class="lbl">{{ $t('Rental_Refund') }}</label>
                    <a-input-number v-model:value="deposit.refund_amount" :min="0" :max="refundMax" style="width: 100%" />
                  </a-col>
                  <a-col :xs="12" :md="6">
                    <label class="lbl">{{ $t('Paymentchoice') }}</label>
                    <a-select v-model:value="deposit.payment_method_id" :options="methodOptions" style="width: 100%" />
                  </a-col>
                  <a-col :xs="12" :md="6">
                    <label class="lbl">{{ $t('Account') }}</label>
                    <a-select v-model:value="deposit.account_id" :options="accountOptions" allow-clear style="width: 100%" />
                  </a-col>
                  <a-col v-if="deposit.retain_amount" :span="24" class="mt">
                    <a-input v-model:value="deposit.retain_reason" :placeholder="$t('Rental_Keep_Reason')" />
                  </a-col>
                </a-row>
                <p v-if="unsettled > 0.004" class="warn"><WarningOutlined /> {{ tp('Rental_Deposit_Left', '{amount} stays held.', { amount: money(unsettled) }) }}</p>
              </template>
            </template>
          </a-card>

          <!-- 4. Sign -->
          <a-card v-show="step === 3" size="small" class="block" :title="$t('Rental_Sign_Confirm')">
            <a-row :gutter="12">
              <a-col :xs="24" :md="12">
                <label class="lbl">{{ $t('Rental_Signer_Name') }}</label>
                <a-input v-model:value="signedName" />
              </a-col>
              <a-col :xs="24" :md="12">
                <label class="lbl">{{ $t('Rental_Handover_Time') }}</label>
                <a-date-picker v-model:value="happenedAt" show-time format="YYYY-MM-DD HH:mm" value-format="YYYY-MM-DD[T]HH:mm:ssZ" style="width: 100%" :allow-clear="false" />
              </a-col>
            </a-row>
            <label class="lbl mt">{{ $t('Rental_Signature') }} <span class="muted">({{ $t('Rental_Optional') }})</span></label>
            <SignaturePad ref="padRef" :hint="$t('Rental_Sign_Here')" />
            <a class="clear-link" @click="padRef?.clear()">{{ $t('Rental_Clear') }}</a>
            <a-checkbox v-if="isCheckin && allComingBack && meta.contract.status === 'active'" v-model:checked="closeContract" class="close">
              {{ $t('Rental_Close_After_Return') }}
            </a-checkbox>
          </a-card>

          <div class="nav">
            <a-button v-if="step > 0" @click="step--"><template #icon><LeftOutlined /></template>{{ $t('Rental_Previous') }}</a-button>
            <span class="grow"></span>
            <a-button v-if="step < 3" type="primary" :disabled="!included.length" @click="step++">{{ $t('Rental_Next') }}<RightOutlined /></a-button>
            <a-button v-else type="primary" size="large" :loading="saving" :disabled="!included.length" @click="submit">
              <template #icon><CheckOutlined /></template>
              {{ isCheckin ? $t('Rental_Confirm_Return') : $t('Rental_Confirm_Handover') }}
            </a-button>
          </div>
        </a-col>

        <!-- Summary -->
        <a-col :xs="24" :lg="8">
          <a-card size="small" class="sticky" :title="$t('Rental_Summary')">
            <dl class="sum">
              <div><dt>{{ $t('Rental_Items') }}</dt><dd>{{ included.length }} / {{ lines.length }}</dd></div>
              <div><dt>{{ $t('Rental_Checklist') }}</dt><dd>{{ checklist.filter(c => c.checked).length }} / {{ checklist.length }}</dd></div>
              <div><dt>{{ $t('Rental_Photos') }}</dt><dd>{{ photos.length }}</dd></div>
              <template v-if="!isCheckin">
                <div v-if="deposit.amount"><dt>{{ $t('Rental_Deposit_To_Collect') }}</dt><dd>{{ money(deposit.amount) }}</dd></div>
              </template>
              <template v-else>
                <div v-if="newCharges"><dt>{{ $t('Rental_New_Charges') }}</dt><dd class="bad">{{ money(newCharges) }}</dd></div>
                <div v-if="deposit.apply && applied"><dt>{{ $t('Rental_From_Deposit') }}</dt><dd>− {{ money(applied) }}</dd></div>
                <div v-if="deposit.retain_amount"><dt>{{ $t('Rental_Keep') }}</dt><dd>{{ money(deposit.retain_amount) }}</dd></div>
                <div v-if="deposit.refund_amount"><dt>{{ $t('Rental_Refund') }}</dt><dd class="ok">{{ money(deposit.refund_amount) }}</dd></div>
                <div class="strong"><dt>{{ $t('Rental_Still_Owed') }}</dt><dd>{{ money(Math.max(0, totalOwed - (deposit.apply ? applied : 0))) }}</dd></div>
              </template>
            </dl>
            <p v-if="!isCheckin && contractNotStarted" class="note"><InfoCircleOutlined /> {{ $t('Rental_Early_Handover_Note') }}</p>
          </a-card>
        </a-col>
      </a-row>
    </template>
  </div>
</template>

<script setup>
/**
 * Check-out (hand over) and check-in (return) wizard:
 *   1 items (condition, meter, damage) → 2 checklist + photos →
 *   3 deposit / charges → 4 signature + confirm.
 * Posts multipart: `data` (JSON) + `photos[]`.
 */
import { ref, reactive, computed, onMounted, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { message } from 'ant-design-vue';
import dayjs from 'dayjs';
import {
  PlusOutlined, CloseOutlined, CameraOutlined, DeleteOutlined, ClockCircleOutlined, WarningOutlined,
  LeftOutlined, RightOutlined, CheckOutlined, InfoCircleOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import SignaturePad from './SignaturePad.vue';
import { useFormat } from '../../composables/useFormat';
import { useAuthStore } from '../../stores/auth';
import { uploadForm } from '../../lib/upload';
import http from '../../lib/http';
import { t, i18n } from '../../i18n';
import { RENTAL_CONDITIONS, tr, typeIcon, unitLabel } from './rentalOptions';

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();
const { money } = useFormat();
const canDeposits = computed(() => auth.can('rnt_deposits'));

const id = computed(() => route.params.id);
const isCheckin = computed(() => route.params.type === 'checkin');
const meta = ref(null);
const loading = ref(true);
const saving = ref(false);
const step = ref(0);

const lines = ref([]);
const checklist = ref([]);
const newCheck = ref('');
const photos = ref([]);
const notes = ref('');
const signedName = ref('');
const happenedAt = ref(dayjs().format('YYYY-MM-DD[T]HH:mm:ssZ'));
const padRef = ref(null);
const closeContract = ref(true);
const lateOn = ref(true);
const lateCharge = ref(0);
const lateTouched = ref(false);
const charges = ref([]);
const deposit = reactive({ amount: 0, payment_method_id: undefined, account_id: undefined, apply: true, retain_amount: 0, refund_amount: 0, retain_reason: '' });

const conditionOptions = computed(() => tr(RENTAL_CONDITIONS));
const methodOptions = computed(() => (meta.value?.payment_methods || []).map(m => ({ value: m.id, label: m.name })));
const accountOptions = computed(() => (meta.value?.accounts || []).map(a => ({ value: a.id, label: a.account_name })));
const stepItems = computed(() => [
  { title: t('Items', 'Items') },
  { title: t('Rental_Checklist', 'Checklist') },
  { title: isCheckin.value ? t('Rental_Charges_Deposit', 'Charges & deposit') : t('Rental_Security_Deposit', 'Deposit') },
  { title: t('Rental_Sign_Confirm', 'Sign & confirm') },
]);

const included = computed(() => lines.value.filter(l => l.include));
const allComingBack = computed(() => included.value.length === lines.value.length);
const contractNotStarted = computed(() => meta.value && dayjs(meta.value.contract.start_date).isAfter(dayjs()));

// ----- money (check-in) -----
const newCharges = computed(() => {
  const damage = included.value.reduce((s, l) => s + Number(l.damage_charge || 0), 0);
  const extra = charges.value.reduce((s, c) => s + (c.description?.trim() ? Number(c.amount || 0) : 0), 0);
  return Math.round((damage + extra + (lateOn.value ? Number(lateCharge.value || 0) : 0)) * 100) / 100;
});
const totalOwed = computed(() => Math.round(((meta.value?.outstanding || 0) + newCharges.value) * 100) / 100);
const applied = computed(() => (deposit.apply ? Math.min(meta.value?.deposit.held || 0, totalOwed.value) : 0));
const remaining = computed(() => Math.max(0, Math.round(((meta.value?.deposit.held || 0) - applied.value) * 100) / 100));
const unsettled = computed(() => Math.max(0, remaining.value - Number(deposit.retain_amount || 0) - Number(deposit.refund_amount || 0)));
// Keep + refund can never be more than what is left of the deposit.
const refundMax = computed(() => Math.max(0, Math.round((remaining.value - Number(deposit.retain_amount || 0)) * 100) / 100));

// Late return is charged only for the lines coming back now (the others are
// charged when they return), so the suggestion follows the ticked lines.
const lateSuggested = computed(() => {
  const periods = Number(meta.value?.late?.periods || 0);
  const perPeriod = included.value.reduce((sum, l) => sum + Number(l.amount || 0), 0);
  return Math.round(periods * perPeriod * 100) / 100;
});
watch(lateSuggested, (v) => { if (!lateTouched.value) lateCharge.value = v; });

function autoRefund() {
  deposit.refund_amount = Math.max(0, Math.round((remaining.value - Number(deposit.retain_amount || 0)) * 100) / 100);
}
// Whatever isn't used or kept is offered back by default.
watch([applied, () => deposit.apply], () => autoRefund());

function tp(key, fallback, params) {
  const out = i18n.global.t(key, params);
  if (out !== key) return out;
  return Object.entries(params).reduce((s, [k, v]) => s.replace(`{${k}}`, v), fallback);
}

function goStep(n) {
  step.value = n;
}

function addCheck() {
  const label = newCheck.value.trim();
  if (!label) return;
  checklist.value.push({ label, checked: true });
  newCheck.value = '';
}

let photoSeq = 0;
function pickPhoto(file) {
  if (file.size > 8 * 1024 * 1024) {
    message.error(t('Rental_Photo_Too_Large_8', 'Each photo must be 8 MB or smaller'));
    return false;
  }
  if (photos.value.length < 20) photos.value.push({ key: photoSeq++, file, url: window.URL.createObjectURL(file) });
  return false;
}

async function load() {
  loading.value = true;
  try {
    const m = await http.get(`rental/contracts/${id.value}/handover-meta`, { type: isCheckin.value ? 'checkin' : 'checkout' });
    meta.value = m;
    lines.value = (m.lines || []).map(l => ({
      ...l,
      include: true,
      prev_meter: l.meter_reading,
      meter_reading: null,
      damage_charge: null,
      damage_notes: '',
      needs_maintenance: false,
    }));
    checklist.value = (m.checklist || []).map(label => ({ label, checked: false }));
    signedName.value = m.contract.customer_name || '';
    deposit.payment_method_id = m.default_payment_method_id || m.payment_methods?.[0]?.id;
    deposit.account_id = m.default_account_id || undefined;
    deposit.amount = Math.max(0, Math.round((m.deposit.agreed - m.deposit.held) * 100) / 100);
    lateTouched.value = false;
    lateCharge.value = lateSuggested.value;
    lateOn.value = !!m.late?.periods;
    autoRefund();
  } catch (e) {
    message.error(e?.data?.message || t('Rental_Could_Not_Load_Contract', 'Could not load this contract'));
  } finally {
    loading.value = false;
  }
}

async function submit() {
  const data = {
    happened_at: happenedAt.value,
    lines: included.value.map(l => ({
      id: l.id,
      condition: l.condition,
      meter_reading: l.meter_reading,
      ...(isCheckin.value ? {
        damage_charge: l.damage_charge || 0,
        damage_notes: l.damage_notes || null,
        needs_maintenance: l.needs_maintenance,
      } : {}),
    })),
    checklist: checklist.value,
    notes: notes.value || null,
    signed_name: signedName.value || null,
    signature: padRef.value && !padRef.value.isBlank() ? padRef.value.toDataURL() : null,
  };
  if (isCheckin.value) {
    Object.assign(data, {
      late_charge: lateOn.value ? lateCharge.value || 0 : 0,
      charges: charges.value.filter(c => c.description?.trim() && Number(c.amount) > 0),
      close_contract: allComingBack.value && closeContract.value,
      deposit: canDeposits.value ? {
        apply: deposit.apply && applied.value > 0,
        retain_amount: deposit.retain_amount || 0,
        retain_reason: deposit.retain_reason || null,
        refund_amount: deposit.refund_amount || 0,
        // "Give back the rest": the server refunds whatever is still held
        // after the deposit covered the debt (the figure here may be stale).
        refund_rest: Number(deposit.refund_amount || 0) > 0 && unsettled.value < 0.005,
        payment_method_id: deposit.payment_method_id || null,
        account_id: deposit.account_id || null,
      } : {},
    });
  } else if (canDeposits.value && deposit.amount > 0) {
    data.deposit = { amount: deposit.amount, payment_method_id: deposit.payment_method_id || null, account_id: deposit.account_id || null };
  }

  const fd = new FormData();
  fd.append('data', JSON.stringify(data));
  photos.value.forEach(p => fd.append('photos[]', p.file));

  saving.value = true;
  try {
    const res = await uploadForm(`rental/contracts/${id.value}/${isCheckin.value ? 'checkin' : 'checkout'}`, fd);
    if (res.status >= 200 && res.status < 300) {
      message.success(isCheckin.value ? t('Rental_Return_Done', 'Return recorded') : t('Rental_Handover_Done', 'Handover recorded'));
      router.push(`/rental/contracts/${id.value}`);
      return;
    }
    const errors = res.data?.errors;
    message.error((errors && Object.values(errors)[0]?.[0]) || res.data?.message || t('Gen_Could_Not_Save', 'Could not save'), 6);
  } catch (e) {
    message.error(t('Gen_Could_Not_Save', 'Could not save'));
  } finally {
    saving.value = false;
  }
}

onMounted(load);
</script>

<style scoped>
.loading {
  display: flex;
  justify-content: center;
  padding: 96px 0;
}
.block {
  margin-bottom: 16px;
}
.muted {
  opacity: 0.6;
}
.mt {
  margin-top: 12px;
}
.steps :deep(.ant-steps-item) {
  cursor: pointer;
}
.lbl {
  display: block;
  margin-bottom: 4px;
  font-size: 12.5px;
  opacity: 0.75;
}
.sub-title {
  margin: 0 0 10px;
  font-size: 14px;
  font-weight: 600;
}

/* lines */
.lines {
  display: flex;
  flex-direction: column;
  gap: 10px;
}
.line {
  padding: 12px;
  border: 1px solid rgba(128, 128, 128, 0.2);
  border-radius: 12px;
  transition: opacity 0.15s ease, border-color 0.15s ease;
}
.line:not(.off) {
  border-color: rgba(var(--app-primary-rgb, 109, 40, 217), 0.35);
}
.line.off {
  opacity: 0.55;
}
.line-head {
  display: flex;
  align-items: center;
  gap: 12px;
}
.thumb {
  width: 48px;
  height: 40px;
  flex: none;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 9px;
  overflow: hidden;
  background: rgba(128, 128, 128, 0.12);
}
.thumb img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}
.line-title {
  display: flex;
  flex-direction: column;
}
.line-title span {
  font-size: 12px;
  opacity: 0.6;
}
.line-fields {
  margin-top: 12px;
}
.line-fields > .ant-col {
  margin-bottom: 8px;
}
.maint {
  display: flex;
  align-items: flex-end;
  padding-bottom: 6px;
}

/* checklist */
.checklist {
  display: flex;
  flex-direction: column;
  gap: 6px;
}
.check {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 9px 12px;
  border: 1px solid rgba(128, 128, 128, 0.18);
  border-radius: 10px;
  cursor: pointer;
}
.check.done {
  border-color: rgba(22, 163, 74, 0.4);
  background: rgba(22, 163, 74, 0.06);
}
.check-add {
  display: flex;
  gap: 6px;
  margin-top: 4px;
}

/* photos */
.photos {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}
.photo,
.photo-add {
  position: relative;
  width: 96px;
  height: 96px;
  border-radius: 10px;
  overflow: hidden;
}
.photo img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}
.photo-x {
  position: absolute;
  top: 4px;
  inset-inline-end: 4px;
  width: 24px;
  height: 24px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border: 0;
  border-radius: 6px;
  color: #fff;
  background: rgba(15, 23, 42, 0.7);
  cursor: pointer;
}
.photo-add {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 4px;
  border: 1.5px dashed rgba(128, 128, 128, 0.4);
  background: none;
  color: inherit;
  font: inherit;
  font-size: 12px;
  cursor: pointer;
}
.photo-add :deep(.anticon) {
  font-size: 22px;
  color: var(--app-primary, #6d28d9);
}

/* deposit / charges */
.dep-head {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 10px;
  margin-bottom: 14px;
}
.dep-head > div {
  display: flex;
  flex-direction: column;
  padding: 10px 12px;
  border-radius: 10px;
  background: rgba(128, 128, 128, 0.07);
}
.dep-head span {
  font-size: 12px;
  opacity: 0.65;
}
.dep-head strong {
  font-size: 18px;
}
.late {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 12px;
  margin-bottom: 16px;
  padding: 12px 14px;
  border-radius: 12px;
  background: rgba(217, 119, 6, 0.1);
}
.late-text {
  flex: 1 1 100%;
  display: flex;
  align-items: center;
  gap: 8px;
  color: #b45309;
  font-weight: 500;
}
.charge {
  display: flex;
  gap: 8px;
  margin-bottom: 8px;
}
.settle {
  max-width: 420px;
}
.settle-row {
  display: flex;
  justify-content: space-between;
  padding: 6px 0;
  font-size: 13.5px;
  border-bottom: 1px dashed rgba(128, 128, 128, 0.2);
}
.settle-row.total {
  font-size: 15px;
}
.apply {
  margin: 14px 0 10px;
}
.dep-fields > .ant-col {
  margin-bottom: 8px;
}
.warn {
  margin: 8px 0 0;
  color: #b45309;
  font-size: 12.5px;
}

/* sign */
.clear-link {
  display: inline-block;
  margin-top: 6px;
  font-size: 12.5px;
}
.close {
  display: flex;
  margin-top: 14px;
}

/* nav + summary */
.nav {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 24px;
}
.grow {
  flex: 1;
}
.sticky {
  position: sticky;
  top: 80px;
}
.sum {
  margin: 0;
}
.sum > div {
  display: flex;
  justify-content: space-between;
  padding: 7px 0;
  font-size: 13px;
  border-bottom: 1px dashed rgba(128, 128, 128, 0.2);
}
.sum dd {
  margin: 0;
  font-weight: 600;
}
.sum .strong {
  font-size: 14.5px;
}
.bad {
  color: #dc2626;
}
.ok {
  color: #16a34a;
}
.note {
  margin: 12px 0 0;
  font-size: 12.5px;
  opacity: 0.7;
}
@media (max-width: 991px) {
  .sticky {
    position: static;
  }
}
</style>
