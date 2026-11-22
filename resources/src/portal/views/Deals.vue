<template>
  <!-- CRM deals shared with the customer (stocky's "My requests"; /requests is Protocol here). -->
  <div>
    <PageActions v-if="actionOn('quotation_requests') && moduleOn('quotations')">
      <router-link to="/quotations/new" class="btn btn-primary"><i class="ti ti-plus me-1"></i>{{ $t('request_quotation') }}</router-link>
    </PageActions>

    <div v-if="loading" class="text-center py-6">
      <div class="spinner-border text-primary" role="status"></div>
      <div class="text-secondary mt-2">{{ $t('loading') }}</div>
    </div>

    <div v-else-if="!requests.length" class="card">
      <div class="card-body">
        <EmptyState icon="message-circle" :title="tr('req_empty', 'You have no requests yet')" :subtitle="tr('req_empty_hint', 'Ask for a quotation and follow its progress here.')">
          <router-link v-if="actionOn('quotation_requests') && moduleOn('quotations')" to="/quotations/new" class="btn btn-primary"><i class="ti ti-plus me-1"></i>{{ $t('request_quotation') }}</router-link>
        </EmptyState>
      </div>
    </div>

    <div v-else class="row row-cards">
      <div v-for="r in requests" :key="r.id" class="col-md-6 col-xl-4">
        <router-link :to="`/deals/${r.id}`" class="card card-link card-link-pop h-100 text-reset text-decoration-none req-card" :class="{ 'req-closed': r.closed }">
          <div class="card-body d-flex flex-column gap-3">
            <div class="d-flex align-items-start gap-2">
              <div class="flex-fill min-w-0">
                <div class="text-secondary small font-monospace">{{ r.number }}</div>
                <div class="fw-bold text-truncate">{{ r.title }}</div>
              </div>
              <span v-if="r.unread" class="badge bg-red text-red-fg" :title="tr('req_new_messages', 'New messages')"><i class="ti ti-message-circle me-1"></i>{{ r.unread }}</span>
              <span v-else :class="`badge bg-${tone(r)}-lt`">{{ stepLabel(r) }}</span>
            </div>

            <!-- progress -->
            <div v-if="!r.closed" class="req-steps">
              <div v-for="(s, i) in steps" :key="s" class="req-step" :class="{ done: i <= r.step_index }">
                <span class="req-dot"></span>
              </div>
            </div>
            <div class="small text-secondary">{{ stepLabel(r) }}<template v-if="r.expected_date"> · {{ tr('req_expected', 'Expected by {date}', { date: fmtDate(r.expected_date) }) }}</template></div>

            <div class="mt-auto d-flex align-items-center gap-2 small">
              <span class="avatar avatar-xs rounded-circle">{{ initials(r.rep ? r.rep.name : '?') }}</span>
              <span class="text-truncate">{{ r.rep ? r.rep.name : tr('req_no_rep', 'Our team will assign someone shortly') }}</span>
              <span v-if="r.quotation" class="ms-auto font-monospace fw-bold">{{ money(r.quotation.total) }}</span>
            </div>
          </div>
        </router-link>
      </div>
    </div>
  </div>
</template>

<script>
import http from '../lib/http';
import PageActions from '../components/PageActions.vue';
import EmptyState from '../components/EmptyState.vue';
import { money, initials } from '../lib/ui';
import { REQUEST_STEPS, STEP_FALLBACK } from '../lib/requests';
import { actionOn, moduleOn } from '../lib/settings';

export default {
  components: { PageActions, EmptyState },
  data() { return { requests: [], loading: true, steps: REQUEST_STEPS }; },
  mounted() { this.fetch(); },
  methods: {
    money, initials, actionOn, moduleOn,
    pageMeta() { return { title: this.tr('nav_deals', 'My deals'), pretitle: this.$t('nav_services'), crumbs: [] }; },
    stepLabel(r) { return r.closed ? this.tr('req_closed', 'Closed') : this.tr(`req_step_${r.step}`, STEP_FALLBACK[r.step] || r.step); },
    tone(r) { return r.closed ? 'secondary' : ({ accepted: 'green', quoted: 'blue', in_progress: 'yellow' }[r.step] || 'azure'); },
    fmtDate(d) { try { return new Date(`${d}T00:00:00`).toLocaleDateString(undefined, { dateStyle: 'medium' }); } catch (_) { return d; } },
    async fetch() {
      this.loading = true;
      try {
        const { data } = await http.get('/portal/deals');
        this.requests = data.requests || [];
      } catch (_) { this.requests = []; }
      this.loading = false;
    },
  },
};
</script>

<style scoped>
.req-card { transition: transform .15s, box-shadow .15s; }
.req-closed { opacity: .7; }
.req-steps { display: grid; grid-template-columns: repeat(4, 1fr); gap: 4px; }
.req-step { height: 6px; border-radius: 999px; background: var(--tblr-border-color, rgba(128, 128, 128, .2)); position: relative; }
.req-step.done { background: var(--tblr-primary, #f76707); }
.req-dot { display: none; }
.min-w-0 { min-width: 0; }
</style>
