<template>
  <div>
    <PageActions>
      <router-link to="/deals" class="btn btn-outline-secondary"><i class="ti ti-arrow-left me-1"></i>{{ tr('req_back', 'Back to my requests') }}</router-link>
    </PageActions>

    <div v-if="loading" class="text-center py-6">
      <div class="spinner-border text-primary" role="status"></div>
      <div class="text-secondary mt-2">{{ $t('loading') }}</div>
    </div>

    <div v-else-if="notFound" class="card"><div class="card-body">
      <EmptyState icon="search" :title="tr('req_not_found', 'This request was not found.')" />
    </div></div>

    <div v-else class="row row-cards">
      <div class="col-lg-8">
        <!-- Progress -->
        <div class="card mb-3">
          <div class="card-header">
            <div>
              <h3 class="card-title mb-0">{{ req.title }}</h3>
              <p class="card-subtitle mb-0 font-monospace">{{ req.number }}</p>
            </div>
            <div class="card-actions">
              <span :class="`badge bg-${req.closed ? 'secondary' : 'primary'}-lt`">{{ stepLabel }}</span>
            </div>
          </div>
          <div class="card-body">
            <div v-if="req.closed" class="alert alert-secondary mb-0">{{ tr('req_closed_hint', 'This request is closed. You can still send us a message below.') }}</div>
            <ul v-else class="steps steps-counter steps-primary my-2">
              <li v-for="(s, i) in steps" :key="s" class="step-item" :class="{ active: i === req.step_index }">{{ tr(`req_step_${s}`, stepFallback[s]) }}</li>
            </ul>
            <div v-if="req.expected_date && !req.closed" class="text-secondary small mt-3"><i class="ti ti-calendar me-1"></i>{{ tr('req_expected', 'Expected by {date}', { date: fmtDate(req.expected_date) }) }}</div>
          </div>
        </div>

        <!-- Messages -->
        <div class="card">
          <div class="card-header"><h3 class="card-title"><i class="ti ti-messages me-2"></i>{{ tr('req_conversation', 'Messages') }}</h3></div>
          <div ref="thread" class="card-body req-thread">
            <div v-if="!req.messages.length" class="text-center text-secondary py-4">
              <i class="ti ti-message-circle fs-1 d-block mb-2"></i>{{ tr('req_no_messages', 'No messages yet. Ask us anything about this request.') }}
            </div>
            <div v-for="m in req.messages" :key="m.id" class="req-msg" :class="m.mine ? 'mine' : 'theirs'">
              <div class="req-bubble">
                <div class="req-author">{{ m.mine ? tr('req_you', 'You') : (m.author || tr('req_our_team', 'Our team')) }}</div>
                <div class="req-body">{{ m.body }}</div>
                <div class="req-time">{{ fmtDateTime(m.created_at) }}</div>
              </div>
            </div>
          </div>
          <div class="card-footer">
            <form class="d-flex gap-2 align-items-end" @submit.prevent="send">
              <textarea v-model="draft" class="form-control" rows="2" maxlength="3000" :placeholder="tr('req_write', 'Write a message…')" @keydown.ctrl.enter.prevent="send" @keydown.meta.enter.prevent="send"></textarea>
              <button type="submit" class="btn btn-primary" :disabled="sending || !draft.trim()">
                <span v-if="sending" class="spinner-border spinner-border-sm me-1"></span><i v-else class="ti ti-send me-1"></i>{{ tr('req_send', 'Send') }}
              </button>
            </form>
            <div v-if="error" class="text-danger small mt-2">{{ error }}</div>
          </div>
        </div>
      </div>

      <div class="col-lg-4">
        <div class="card mb-3">
          <div class="card-body">
            <div class="text-secondary small text-uppercase fw-bold mb-2">{{ tr('req_your_rep', 'Your contact') }}</div>
            <div class="d-flex align-items-center gap-3">
              <span class="avatar avatar-md rounded-circle bg-primary-lt">{{ initials(req.rep ? req.rep.name : '?') }}</span>
              <div>
                <div class="fw-bold">{{ req.rep ? req.rep.name : tr('req_no_rep', 'Our team will assign someone shortly') }}</div>
                <div class="text-secondary small">{{ tr('req_rep_hint', 'Replies here in the portal') }}</div>
              </div>
            </div>
          </div>
        </div>
        <div v-if="req.quotation" class="card mb-3">
          <div class="card-body">
            <div class="text-secondary small text-uppercase fw-bold mb-2">{{ $t('quotation') }}</div>
            <div class="d-flex align-items-center justify-content-between mb-3">
              <span class="font-monospace">{{ req.quotation.ref }}</span>
              <span class="h3 mb-0 font-monospace">{{ money(req.quotation.total) }}</span>
            </div>
            <router-link v-if="moduleOn('quotations')" :to="`/quotations/${req.quotation.id}`" class="btn btn-outline-primary w-100"><i class="ti ti-file-description me-1"></i>{{ tr('req_view_quotation', 'View quotation') }}</router-link>
          </div>
        </div>
        <div v-if="req.details" class="card">
          <div class="card-body">
            <div class="text-secondary small text-uppercase fw-bold mb-2">{{ tr('req_details', 'Your request') }}</div>
            <div class="req-details">{{ req.details }}</div>
            <div class="text-secondary small mt-2">{{ $t('created_on', { date: fmtDate(req.created_at) }) }}</div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
import http from '../lib/http';
import PageActions from '../components/PageActions.vue';
import EmptyState from '../components/EmptyState.vue';
import { money, initials, apiError } from '../lib/ui';
import { REQUEST_STEPS, STEP_FALLBACK } from '../lib/requests';
import { moduleOn } from '../lib/settings';

export default {
  components: { PageActions, EmptyState },
  data() {
    return {
      req: { messages: [] }, loading: true, notFound: false, draft: '', sending: false, error: '',
      steps: REQUEST_STEPS,
      stepFallback: STEP_FALLBACK,
    };
  },
  computed: {
    stepLabel() { return this.req.closed ? this.tr('req_closed', 'Closed') : this.tr(`req_step_${this.req.step}`, this.stepFallback[this.req.step] || ''); },
  },
  mounted() {
    this.fetch();
    // New replies show up without a reload.
    this.timer = setInterval(() => { if (document.visibilityState === 'visible' && !this.sending) this.fetch(true); }, 30000);
  },
  beforeUnmount() { clearInterval(this.timer); },
  methods: {
    money, initials, moduleOn,
    pageMeta() {
      return { title: this.req.title || this.tr('nav_deals', 'My deals'), pretitle: this.tr('nav_deals', 'My deals'), crumbs: [{ label: this.tr('nav_deals', 'My deals'), to: '/deals' }] };
    },
    fmtDate(d) { try { return new Date(String(d).length === 10 ? `${d}T00:00:00` : d).toLocaleDateString(undefined, { dateStyle: 'medium' }); } catch (_) { return d; } },
    fmtDateTime(d) { try { return new Date(d).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }); } catch (_) { return d; } },
    async fetch(quiet = false) {
      if (!quiet) this.loading = true;
      try {
        const { data } = await http.get(`/portal/deals/${this.$route.params.id}`);
        const grew = (data.request.messages || []).length !== this.req.messages.length;
        this.req = data.request;
        this.notFound = false;
        if (!quiet || grew) this.scrollDown();
        // The bell / nav badge may have changed (replies are now read).
        window.dispatchEvent(new CustomEvent('portal:deals-changed'));
      } catch (e) {
        if (!quiet) this.notFound = true;
      }
      this.loading = false;
      this.applyPage();
    },
    scrollDown() {
      this.$nextTick(() => { const el = this.$refs.thread; if (el) el.scrollTop = el.scrollHeight; });
    },
    async send() {
      const body = this.draft.trim();
      if (!body || this.sending) return;
      this.sending = true;
      this.error = '';
      try {
        const { data } = await http.post(`/portal/deals/${this.req.id}/messages`, { body });
        this.req.messages.push(data.message);
        this.draft = '';
        this.scrollDown();
      } catch (e) {
        this.error = apiError(e, this.tr('req_send_failed', 'The message could not be sent.'));
      }
      this.sending = false;
    },
  },
};
</script>

<style scoped>
.req-thread { max-height: 460px; overflow-y: auto; display: flex; flex-direction: column; gap: 10px; background: var(--tblr-bg-surface-secondary, rgba(128, 128, 128, .04)); }
.req-msg { display: flex; }
.req-msg.mine { justify-content: flex-end; }
.req-bubble { max-width: 80%; padding: 8px 12px; border-radius: 14px; border: 1px solid var(--tblr-border-color, rgba(128, 128, 128, .2)); background: var(--tblr-bg-surface, #fff); }
.req-msg.mine .req-bubble { background: var(--tblr-primary-lt, rgba(247, 103, 7, .1)); border-color: transparent; border-bottom-right-radius: 4px; }
.req-msg.theirs .req-bubble { border-bottom-left-radius: 4px; }
.req-author { font-size: 11.5px; font-weight: 700; opacity: .7; margin-bottom: 2px; }
.req-body { white-space: pre-wrap; word-break: break-word; }
.req-time { font-size: 11px; opacity: .55; text-align: end; margin-top: 2px; }
.req-details { white-space: pre-wrap; word-break: break-word; }
</style>
