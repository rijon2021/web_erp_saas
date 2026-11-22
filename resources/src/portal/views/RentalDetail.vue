<template>
  <div>
    <PageActions>
      <router-link to="/rentals" class="btn btn-outline-secondary"><i class="ti ti-arrow-left me-1"></i>{{ $t('back') }}</router-link>
      <a v-if="c.id" :href="`/api/portal/rentals/${c.id}/agreement`" target="_blank" rel="noopener" class="btn btn-outline-primary">
        <i class="ti ti-file-type-pdf me-1"></i>{{ tr('rental_download_agreement', 'Agreement (PDF)') }}
      </a>
      <router-link v-if="c.status === 'active'" :to="`/rental-requests?new=1&contract=${c.id}`" class="btn btn-primary">
        <i class="ti ti-tool me-1"></i>{{ tr('rental_report_problem', 'Report a problem') }}
      </router-link>
    </PageActions>

    <div v-if="loading" class="text-center py-6">
      <div class="spinner-border text-primary" role="status"></div>
      <div class="text-secondary mt-2">{{ $t('loading') }}</div>
    </div>

    <div v-else-if="!c.id" class="card"><div class="card-body"><EmptyState icon="key-off" :title="tr('rental_not_found', 'This rental could not be found.')" /></div></div>

    <template v-else>
      <!-- Signature call-to-action -->
      <div v-if="c.can_sign && !signing" class="alert alert-warning d-flex align-items-center gap-3 mb-3" role="alert">
        <i class="ti ti-signature fs-1"></i>
        <div class="flex-fill">
          <div class="fw-bold">{{ tr('rental_sign_title', 'Please sign your rental agreement') }}</div>
          <div class="text-secondary">{{ tr('rental_sign_intro', 'Read the agreement, then sign it here. It takes less than a minute.') }}</div>
        </div>
        <button type="button" class="btn btn-warning" @click="startSigning"><i class="ti ti-writing-sign me-1"></i>{{ tr('rental_sign_now', 'Sign now') }}</button>
      </div>

      <form v-if="signing" class="card mb-3" @submit.prevent="submitSignature">
        <div class="card-header">
          <div>
            <h3 class="card-title mb-0">{{ tr('rental_sign_title', 'Please sign your rental agreement') }}</h3>
            <p class="card-subtitle mb-0">{{ c.contract_number }}</p>
          </div>
        </div>
        <div class="card-body">
          <p class="mb-3">
            <a :href="`/api/portal/rentals/${c.id}/agreement`" target="_blank" rel="noopener"><i class="ti ti-file-type-pdf me-1"></i>{{ tr('rental_read_agreement', 'Open the agreement to read it') }}</a>
          </p>
          <div class="mb-3">
            <label class="form-label required">{{ tr('rental_full_name', 'Your full name') }}</label>
            <input v-model="sig.name" type="text" class="form-control" maxlength="191" required autocomplete="name" />
          </div>
          <div class="mb-3">
            <label class="form-label required">{{ tr('rental_signature', 'Signature') }}</label>
            <SignatureCanvas ref="pad" />
          </div>
          <label class="form-check">
            <input v-model="sig.agree" type="checkbox" class="form-check-input" />
            <span class="form-check-label">{{ tr('rental_sign_agree', 'I have read the rental agreement and I agree to its terms.') }}</span>
          </label>
          <div v-if="sig.error" class="alert alert-danger mb-0 mt-3">{{ sig.error }}</div>
        </div>
        <div class="card-footer d-flex justify-content-end gap-2">
          <button type="button" class="btn btn-ghost-secondary" @click="signing = false">{{ $t('cancel') }}</button>
          <button type="submit" class="btn btn-primary" :disabled="sig.saving">
            <span v-if="sig.saving" class="spinner-border spinner-border-sm me-2" role="status"></span>
            <i v-else class="ti ti-check me-1"></i>{{ tr('rental_sign_submit', 'Sign the agreement') }}
          </button>
        </div>
      </form>

      <div class="row row-cards mb-3">
        <div class="col-sm-6 col-xl-3"><StatCard :label="tr('rental_rent', 'Rent')" :value="money(c.total_amount)" :sub="`/ ${periodLabel(c.rate_period)}`" icon="receipt" tone="primary" /></div>
        <div class="col-sm-6 col-xl-3"><StatCard :label="tr('rental_paid', 'Paid')" :value="money(c.summary.paid)" icon="circle-check" tone="green" /></div>
        <div class="col-sm-6 col-xl-3"><StatCard :label="tr('rental_outstanding', 'Amount owed')" :value="money(c.summary.outstanding)" :sub="c.summary.overdue > 0 ? `${tr('rental_overdue', 'Overdue')}: ${money(c.summary.overdue)}` : ''" icon="cash" :tone="c.summary.overdue > 0 ? 'red' : 'blue'" /></div>
        <div class="col-sm-6 col-xl-3"><StatCard :label="tr('rental_deposit_held', 'Deposit held')" :value="money(c.deposit_held)" :sub="c.deposit_amount ? `${tr('rental_deposit_required', 'Required')}: ${money(c.deposit_amount)}` : ''" icon="shield-lock" tone="purple" /></div>
      </div>

      <div class="card mb-3">
        <div class="card-header">
          <div>
            <h3 class="card-title mb-0">{{ tr('rental_contract', 'Rental contract') }}</h3>
            <p class="card-subtitle mb-0 font-monospace">{{ c.contract_number }}</p>
          </div>
          <div class="card-actions d-flex align-items-center gap-2">
            <span :class="badge(stageTone(c.stage))">{{ stageLabel(c.stage, c.status) }}</span>
            <span v-if="c.signed" class="badge bg-green-lt"><i class="ti ti-signature me-1"></i>{{ tr('rental_signed', 'Signed') }}</span>
            <span v-else-if="c.status === 'active'" class="badge bg-orange-lt">{{ tr('rental_unsigned', 'Not signed') }}</span>
          </div>
        </div>
        <div class="card-body">
          <div class="datagrid">
            <div class="datagrid-item"><div class="datagrid-title">{{ $t('start_date') }}</div><div class="datagrid-content">{{ dateTime(c.start_date) }}</div></div>
            <div class="datagrid-item"><div class="datagrid-title">{{ $t('end_date') }}</div><div class="datagrid-content">{{ c.end_date ? dateTime(c.end_date) : tr('rental_open_ended', 'Open-ended') }}</div></div>
            <div class="datagrid-item"><div class="datagrid-title">{{ tr('rental_billing', 'Billing') }}</div><div class="datagrid-content">{{ c.billing_type === 'upfront' ? tr('rental_billing_upfront', 'Paid in advance') : tr('rental_billing_recurring', 'Every period') }}</div></div>
            <div v-if="c.contract_value" class="datagrid-item"><div class="datagrid-title">{{ tr('rental_contract_value', 'Total for the whole term') }}</div><div class="datagrid-content">{{ money(c.contract_value) }}</div></div>
            <div class="datagrid-item"><div class="datagrid-title">{{ tr('rental_late_fee', 'Late fee') }}</div><div class="datagrid-content">{{ lateFeeText }}</div></div>
            <div v-if="c.signed" class="datagrid-item"><div class="datagrid-title">{{ tr('rental_signed_by', 'Signed by') }}</div><div class="datagrid-content">{{ c.signed_name }} · {{ dateTime(c.signed_at) }}</div></div>
          </div>
        </div>
        <div class="list-group list-group-flush border-top">
          <div v-for="l in c.items" :key="l.id" class="list-group-item d-flex align-items-center gap-3">
            <span class="avatar avatar-md rounded" :style="l.image_url ? { backgroundImage: `url(${l.image_url})` } : null"><i v-if="!l.image_url" class="ti ti-box"></i></span>
            <div class="flex-fill min-w-0">
              <div class="fw-medium text-truncate">{{ l.item_name }} <span v-if="l.quantity > 1" class="text-secondary">×{{ l.quantity }}</span></div>
              <div class="text-secondary small">{{ money(l.rate) }} / {{ periodLabel(c.rate_period) }}</div>
            </div>
            <span v-if="l.returned" class="badge bg-secondary-lt">{{ tr('rental_returned', 'Returned') }}</span>
            <span class="font-monospace">{{ money(l.amount) }}</span>
          </div>
        </div>
        <div v-if="c.terms" class="card-body border-top">
          <h4 class="mb-2">{{ tr('rental_terms', 'Terms') }}</h4>
          <div class="text-secondary" style="white-space: pre-line">{{ c.terms }}</div>
        </div>
      </div>

      <div class="row row-cards">
        <div class="col-lg-7">
          <div class="card h-100">
            <div class="card-header"><h3 class="card-title">{{ tr('rental_invoices', 'Rental invoices') }}</h3></div>
            <div v-if="!c.invoices.length" class="card-body"><EmptyState icon="file-invoice" :title="tr('rental_no_invoices', 'No invoices yet.')" /></div>
            <div v-else class="table-responsive">
              <table class="table table-vcenter card-table">
                <thead><tr><th>{{ $t('number') }}</th><th>{{ tr('rental_due', 'Due') }}</th><th class="text-end">{{ $t('total') }}</th><th class="text-end">{{ tr('rental_balance', 'Balance') }}</th><th></th><th class="w-1"></th></tr></thead>
                <tbody>
                  <tr v-for="i in c.invoices" :key="i.id">
                    <td>
                      <div class="font-monospace">{{ i.invoice_number }}</div>
                      <div class="text-secondary small">{{ i.description || periodText(i) }}</div>
                    </td>
                    <td class="text-secondary">{{ i.due_date }}</td>
                    <td class="text-end">{{ money(i.total) }}<div v-if="i.late_fee > 0" class="small text-danger">+{{ money(i.late_fee) }} {{ tr('rental_late_fee_short', 'late fee') }}</div></td>
                    <td class="text-end fw-medium">{{ money(i.balance) }}</td>
                    <td><span :class="badge(invTone(i))">{{ invLabel(i) }}</span></td>
                    <td><a :href="`/api/portal/rentals/invoices/${i.id}/pdf`" target="_blank" rel="noopener" class="text-secondary" :title="$t('download')"><i class="ti ti-download"></i></a></td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>
        <div class="col-lg-5">
          <div class="card mb-3">
            <div class="card-header"><h3 class="card-title">{{ tr('rental_payments', 'Payments') }}</h3></div>
            <div v-if="!c.payments.length" class="card-body"><EmptyState icon="credit-card" :title="tr('rental_no_payments', 'No payments yet.')" /></div>
            <div v-else class="list-group list-group-flush">
              <div v-for="p in c.payments" :key="p.id" class="list-group-item d-flex align-items-center gap-2">
                <i class="ti ti-circle-check text-success"></i>
                <div class="flex-fill">
                  <div class="font-monospace small">{{ p.payment_number }}</div>
                  <div class="text-secondary small">{{ p.payment_date }} · {{ p.method === 'deposit' ? tr('rental_from_deposit', 'From the deposit') : (p.method || '—') }}</div>
                </div>
                <span class="fw-medium">{{ money(p.amount) }}</span>
              </div>
            </div>
          </div>
          <div class="card">
            <div class="card-header"><h3 class="card-title">{{ tr('rental_requests', 'Maintenance requests') }}</h3></div>
            <div v-if="!c.requests.length" class="card-body"><EmptyState icon="tool" :title="tr('rental_no_requests', 'No requests.')" /></div>
            <div v-else class="list-group list-group-flush">
              <div v-for="r in c.requests" :key="r.id" class="list-group-item d-flex align-items-center gap-2">
                <div class="flex-fill min-w-0">
                  <div class="text-truncate">{{ r.title }}</div>
                  <div class="text-secondary small">{{ r.number }} · {{ r.created_at }}</div>
                </div>
                <span :class="badge(reqTone(r.status))">{{ reqLabel(r.status) }}</span>
              </div>
            </div>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>

<script>
import http from '../lib/http';
import { money, badge, apiError } from '../lib/ui';
import {
  rentalStageTone, rentalStageLabel, rentalPeriodLabel, rentalInvoiceTone, rentalInvoiceLabel, requestTone, requestLabel,
} from '../lib/rental';
import PageActions from '../components/PageActions.vue';
import StatCard from '../components/StatCard.vue';
import EmptyState from '../components/EmptyState.vue';
import SignatureCanvas from '../components/SignatureCanvas.vue';

export default {
  components: { PageActions, StatCard, EmptyState, SignatureCanvas },
  data() {
    return {
      c: {},
      loading: true,
      signing: false,
      sig: { name: '', agree: false, saving: false, error: '' },
    };
  },
  computed: {
    lateFeeText() {
      const c = this.c;
      if (!c.late_fee_value || c.late_fee_type === 'none') return this.tr('rental_no_late_fee', 'None');
      const amount = c.late_fee_type === 'percent' ? `${c.late_fee_value}%` : money(c.late_fee_value);
      const freq = c.late_fee_frequency === 'daily' ? this.tr('rental_per_day_late', 'per day late') : this.tr('rental_once', 'once');
      const grace = c.grace_days ? ` · ${this.tr('rental_grace_days', '{days} day(s) grace', { days: c.grace_days })}` : '';
      return `${amount} ${freq}${grace}`;
    },
  },
  mounted() { this.fetch(); },
  methods: {
    money, badge,
    stageTone: rentalStageTone,
    invTone: rentalInvoiceTone,
    reqTone: requestTone,
    stageLabel(stage, status) { return rentalStageLabel(this, stage, status); },
    periodLabel(p) { return rentalPeriodLabel(this, p); },
    invLabel(i) { return rentalInvoiceLabel(this, i); },
    reqLabel(s) { return requestLabel(this, s); },
    pageMeta() {
      return {
        title: this.c.contract_number || this.tr('rental_contract', 'Rental contract'),
        pretitle: this.tr('nav_rentals', 'Rentals'),
        crumbs: [{ label: this.tr('rental_my_rentals', 'My rentals'), to: '/rentals' }],
      };
    },
    async fetch() {
      this.loading = true;
      try {
        this.c = (await http.get(`/portal/rentals/${this.$route.params.id}`)).data || {};
      } catch (_) {
        this.c = {};
      }
      this.loading = false;
      this.applyPage();
    },
    dateTime(iso) {
      if (!iso) return '—';
      try { return new Date(iso).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }); } catch (_) { return iso; }
    },
    periodText(i) { return i.period_start ? `${i.period_start} → ${i.period_end || ''}` : ''; },
    startSigning() {
      this.sig = { name: this.sig.name, agree: false, saving: false, error: '' };
      this.signing = true;
    },
    async submitSignature() {
      const pad = this.$refs.pad;
      if (!this.sig.name.trim()) { this.sig.error = this.tr('rental_enter_name', 'Please type your full name.'); return; }
      if (!pad || pad.isEmpty()) { this.sig.error = this.tr('rental_draw_signature', 'Please draw your signature in the box.'); return; }
      if (!this.sig.agree) { this.sig.error = this.tr('rental_must_agree', 'Please confirm that you agree to the terms.'); return; }
      this.sig.error = '';
      this.sig.saving = true;
      try {
        await http.post(`/portal/rentals/${this.c.id}/sign`, { signed_name: this.sig.name.trim(), signature: pad.toDataUrl(), agree: 1 });
        this.signing = false;
        await this.fetch();
        window.dispatchEvent(new CustomEvent('portal:rentals-changed'));
      } catch (e) {
        this.sig.error = apiError(e, this.tr('rental_sign_failed', 'The signature could not be saved. Please try again.'));
      }
      this.sig.saving = false;
    },
  },
};
</script>
