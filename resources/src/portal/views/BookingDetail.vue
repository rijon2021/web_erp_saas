<template>
  <div>
    <PageActions>
      <router-link to="/bookings" class="btn btn-outline-secondary"><i class="ti ti-arrow-left me-1"></i>{{ $t('back') }}</router-link>
    </PageActions>

    <div v-if="loading" class="text-center py-6">
      <div class="spinner-border text-primary" role="status"></div>
      <div class="text-secondary mt-2">{{ $t('loading') }}</div>
    </div>

    <div v-else-if="error" class="card">
      <EmptyState icon="alert-circle" :title="error.title" :subtitle="error.message">
        <button type="button" class="btn btn-primary" @click="fetch">{{ $t('try_again') }}</button>
      </EmptyState>
    </div>

    <template v-else-if="b.id">
      <div v-if="notice" class="alert" :class="notice.ok ? 'alert-success' : 'alert-danger'" role="status">{{ notice.text }}</div>

      <!-- A time staff suggested instead -->
      <div v-if="b.proposal" class="card mb-3 border-primary">
        <div class="card-body">
          <h3 class="card-title">{{ tr('bookings_suggested_title', 'We suggested another time') }}</h3>
          <p class="text-secondary mb-2">{{ tr('bookings_suggested_text', 'The time you asked for is not free. Does this one work for you?') }}</p>
          <div class="fw-medium mb-1">{{ proposalWhen }}</div>
          <div v-if="b.proposal.note" class="text-secondary" style="white-space: pre-wrap">{{ b.proposal.note }}</div>
          <div class="d-flex flex-wrap gap-2 mt-3">
            <button type="button" class="btn btn-primary" :disabled="busy" @click="post('accept-time')">{{ tr('bookings_take_time', 'Take this time') }}</button>
            <button type="button" class="btn btn-outline-secondary" :disabled="busy" @click="post('decline-time')">{{ tr('bookings_another_time', 'Ask for another time') }}</button>
          </div>
        </div>
      </div>

      <!-- Deposit to pay (on the booking's own page) -->
      <div v-if="b.deposit && b.deposit.payable" class="card mb-3 border-warning">
        <div class="card-body d-flex flex-wrap align-items-center gap-3">
          <div class="flex-fill">
            <h3 class="card-title mb-1">{{ tr('bookings_deposit_title', 'Deposit to pay') }}</h3>
            <div class="text-secondary">{{ tr('bookings_deposit_text', 'Pay the deposit to keep your booking.') }}</div>
          </div>
          <span class="h2 mb-0 font-monospace">{{ money(b.deposit.amount) }}</span>
          <a v-if="b.status_url" :href="b.status_url" class="btn btn-warning"><i class="ti ti-credit-card me-1"></i>{{ tr('bookings_pay_deposit', 'Pay the deposit') }}</a>
        </div>
      </div>

      <div class="card">
        <div class="card-header">
          <div>
            <h3 class="card-title mb-0">{{ b.service || b.Ref }}</h3>
            <p class="card-subtitle mb-0">{{ whenText }}</p>
          </div>
          <div class="card-actions">
            <span :class="badge(stageTone(b.stage))">{{ stageLabel(b.stage) }}</span>
          </div>
        </div>
        <div class="card-body">
          <div class="datagrid">
            <div class="datagrid-item"><div class="datagrid-title">{{ $t('reference') }}</div><div class="datagrid-content font-monospace">{{ b.Ref }}</div></div>
            <div class="datagrid-item"><div class="datagrid-title">{{ $t('service') }}</div><div class="datagrid-content">{{ b.service || '—' }}</div></div>
            <div class="datagrid-item"><div class="datagrid-title">{{ $t('when') }}</div><div class="datagrid-content">{{ whenText }}</div></div>
            <div v-if="b.staff" class="datagrid-item"><div class="datagrid-title">{{ tr('bookings_with', 'With') }}</div><div class="datagrid-content">{{ b.staff }}</div></div>
            <div v-if="b.seats > 1" class="datagrid-item"><div class="datagrid-title">{{ tr('bookings_people', 'People') }}</div><div class="datagrid-content">{{ b.seats }}</div></div>
            <div v-if="b.deposit" class="datagrid-item">
              <div class="datagrid-title">{{ tr('bookings_deposit', 'Deposit') }}</div>
              <div class="datagrid-content">{{ depositText }}</div>
            </div>
            <div v-for="(a, i) in b.answers" :key="i" class="datagrid-item"><div class="datagrid-title">{{ a.label }}</div><div class="datagrid-content" style="white-space: pre-wrap">{{ a.value }}</div></div>
            <div v-if="b.created_at" class="datagrid-item"><div class="datagrid-title">{{ $t('booked') }}</div><div class="datagrid-content">{{ b.created_at }}</div></div>
          </div>
          <div v-if="b.reason" class="mt-4">
            <h3 class="card-title mb-2">{{ tr('bookings_reason', 'Reason') }}</h3>
            <div class="p-3 rounded border rst-surface-2" style="white-space: pre-wrap">{{ b.reason }}</div>
          </div>
        </div>
        <div v-if="b.can_cancel || b.calendar_url || b.status_url || b.book_url" class="card-footer d-flex flex-wrap gap-2">
          <a v-if="b.calendar_url" :href="b.calendar_url" class="btn btn-outline-secondary"><i class="ti ti-calendar-plus me-1"></i>{{ tr('bookings_add_calendar', 'Add to calendar') }}</a>
          <a v-if="b.status_url" :href="b.status_url" target="_blank" rel="noopener" class="btn btn-outline-secondary"><i class="ti ti-external-link me-1"></i>{{ tr('bookings_open_page', 'Open booking page') }}</a>
          <a v-if="b.book_url" :href="b.book_url" class="btn btn-outline-secondary"><i class="ti ti-plus me-1"></i>{{ tr('bookings_book_again', 'Book again') }}</a>
          <button v-if="b.can_cancel" type="button" class="btn btn-outline-danger ms-auto" :disabled="busy" @click="cancel">{{ tr('bookings_cancel', 'Cancel booking') }}</button>
        </div>
      </div>
    </template>
  </div>
</template>

<script>
import http from '../lib/http';
import PageActions from '../components/PageActions.vue';
import EmptyState from '../components/EmptyState.vue';
import { money, badge, apiError } from '../lib/ui';
import { bookingWhen, bookingStageLabel, bookingStageTone, trp } from '../lib/bookings';

export default {
  components: { PageActions, EmptyState },
  data() { return { b: {}, loading: true, error: null, busy: false, notice: null }; },
  computed: {
    whenText() { return bookingWhen(this.b, this); },
    proposalWhen() {
      const p = this.b.proposal;
      return p ? `${bookingWhen({ date: p.date, time: p.time, end_time: p.end_time }, this)}${p.staff ? ` · ${p.staff}` : ''}` : '';
    },
    depositText() {
      const d = this.b.deposit;
      if (!d) return '—';
      if (d.status === 'paid') return trp(this, 'bookings_deposit_paid', 'Paid: {amount}', { amount: money(d.paid) });
      if (d.status === 'refunded') return this.tr('bookings_deposit_refunded', 'Refunded');
      if (d.status === 'waived') return this.tr('bookings_deposit_waived', 'Not needed');
      return trp(this, 'bookings_deposit_due_amount', 'To pay: {amount}', { amount: money(d.amount) });
    },
  },
  mounted() { this.fetch(); },
  methods: {
    money, badge,
    stageLabel(s) { return bookingStageLabel(s, this); },
    stageTone: bookingStageTone,
    pageMeta() {
      return { title: this.b.Ref || this.tr('bookings_booking', 'Booking'), pretitle: this.tr('bookings_booking', 'Booking'), crumbs: [{ label: this.tr('bookings_title', 'My bookings'), to: '/bookings' }] };
    },
    async fetch() {
      this.loading = true;
      this.error = null;
      try {
        const { data } = await http.get(`/portal/bookings/${this.$route.params.id}`);
        this.b = data || {};
      } catch (e) {
        this.b = {};
        const status = e && e.response && e.response.status;
        if (status === 404) this.error = { title: this.tr('bookings_not_found', 'Booking not found'), message: this.tr('bookings_not_found_text', 'This booking does not exist or is not on your account.') };
        else if (status === 401) this.error = { title: this.$t('session_expired'), message: this.$t('session_expired_text') };
        else this.error = { title: this.tr('bookings_load_failed', 'Could not load the booking'), message: apiError(e, this.$t('try_again')) };
      }
      this.loading = false;
      this.applyPage();
    },
    async post(action) {
      this.busy = true;
      this.notice = null;
      try {
        const { data } = await http.post(`/portal/bookings/${this.b.id}/${action}`);
        if (data && data.booking) this.b = data.booking;
        window.dispatchEvent(new Event('portal:bookings-changed'));
        this.notice = { ok: true, text: (data && data.message) || this.tr('bookings_saved', 'Saved') };
      } catch (e) {
        this.notice = { ok: false, text: apiError(e, this.$t('try_again')) };
      }
      this.busy = false;
    },
    cancel() {
      if (!window.confirm(this.tr('bookings_cancel_confirm', 'Cancel this booking?'))) return;
      this.post('cancel');
    },
  },
};
</script>
