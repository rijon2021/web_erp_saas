<template>
  <!-- Protocol & Requests (SaaS only): one request, its customer-visible history and a reply box. -->
  <div>
    <PageActions>
      <router-link to="/requests" class="btn btn-outline-secondary"><i class="ti ti-arrow-left me-1"></i>{{ $t('back_to_requests') }}</router-link>
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
        <!-- The request itself -->
        <div class="card mb-3">
          <div class="card-header">
            <div class="min-w-0">
              <h3 class="card-title mb-0 text-truncate">{{ req.subject }}</h3>
              <p class="card-subtitle mb-0 font-monospace">{{ req.Ref }}</p>
            </div>
            <div class="card-actions">
              <span :class="badge(protocolTone(req.status))">{{ statusText(req.status) }}</span>
            </div>
          </div>
          <div class="card-body">
            <div class="rq-body">{{ req.body }}</div>
          </div>
        </div>

        <!-- History (customer-visible events only) -->
        <div class="card mb-3">
          <div class="card-header"><h3 class="card-title"><i class="ti ti-history me-2"></i>{{ $t('rq_history') }}</h3></div>
          <div class="card-body">
            <ul v-if="req.events.length" class="timeline">
              <li v-for="e in req.events" :key="e.id" class="timeline-event">
                <div class="timeline-event-icon" :class="eventIconClass(e)"><i :class="`ti ti-${eventIcon(e)}`"></i></div>
                <div class="card timeline-event-card">
                  <div class="card-body">
                    <div class="text-secondary float-end small">{{ fmtDateTime(e.created_at) }}</div>
                    <h4 class="mb-1">
                      {{ tr(`rq_ev_${e.action}`, e.action) }}
                      <span v-if="e.to_desk && ['submitted', 'forwarded', 'returned'].includes(e.action)" class="text-secondary fw-normal"> → {{ e.to_desk }}</span>
                    </h4>
                    <div class="text-secondary small mb-1">{{ e.by_customer ? $t('you') : e.actor }}</div>
                    <div v-if="e.note" class="rq-note">{{ e.note }}</div>
                    <div v-if="e.attachments && e.attachments.length" class="d-flex flex-wrap gap-2 mt-2">
                      <a v-for="a in e.attachments" :key="a.id" :href="a.url" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary">
                        <i class="ti ti-paperclip me-1"></i>{{ a.file_name }}
                      </a>
                    </div>
                  </div>
                </div>
              </li>
            </ul>
            <EmptyState v-else icon="history" :title="tr('no_activity_yet', 'No activity yet.')" />
          </div>
        </div>

        <!-- Reply -->
        <form v-if="req.status !== 'closed'" class="card" @submit.prevent="reply">
          <div class="card-header"><h3 class="card-title"><i class="ti ti-message-circle me-2"></i>{{ $t('your_message') }}</h3></div>
          <div class="card-body">
            <textarea v-model="note" class="form-control mb-3" rows="4" maxlength="10000" required></textarea>
            <label class="form-label">{{ $t('attachments') }} <span class="form-label-description">{{ $t('optional') }}</span></label>
            <input type="file" class="form-control" multiple @change="onFiles" />
            <div class="form-hint">{{ $t('rq_attachments_hint') }}</div>
            <div v-if="files.length" class="d-flex flex-wrap gap-2 mt-2">
              <span v-for="(f, i) in files" :key="i" class="badge bg-secondary-lt d-inline-flex align-items-center gap-1 py-2">
                <i class="ti ti-paperclip"></i>{{ f.name }}
                <button type="button" class="btn-close btn-close-sm ms-1" :aria-label="$t('remove')" @click="files.splice(i, 1)"></button>
              </span>
            </div>
            <div v-if="error" class="alert alert-danger mb-0 mt-3">{{ error }}</div>
          </div>
          <div class="card-footer d-flex justify-content-end">
            <button type="submit" class="btn btn-primary" :disabled="sending || !note.trim()">
              <span v-if="sending" class="spinner-border spinner-border-sm me-2" role="status"></span>
              <i v-else class="ti ti-send me-1"></i>{{ $t('send_reply') }}
            </button>
          </div>
        </form>
        <div v-else class="alert alert-secondary mb-0">{{ $t('rq_closed_note') }}</div>
      </div>

      <div class="col-lg-4">
        <div class="card">
          <div class="card-body">
            <div class="text-secondary small text-uppercase fw-bold mb-3">{{ $t('details') }}</div>
            <dl class="row mb-0 small">
              <dt class="col-5 text-secondary fw-normal">{{ $t('status') }}</dt>
              <dd class="col-7"><span :class="badge(protocolTone(req.status))">{{ statusText(req.status) }}</span></dd>
              <dt class="col-5 text-secondary fw-normal">{{ $t('rq_type') }}</dt>
              <dd class="col-7">{{ tr(`rq_type_${req.type}`, req.type) }}</dd>
              <dt class="col-5 text-secondary fw-normal">{{ $t('rq_current_desk') }}</dt>
              <dd class="col-7">{{ req.current_desk || '—' }}</dd>
              <dt class="col-5 text-secondary fw-normal">{{ $t('rq_last_activity') }}</dt>
              <dd class="col-7">{{ fmtDateTime(req.last_activity_at) }}</dd>
              <template v-if="req.closed_at">
                <dt class="col-5 text-secondary fw-normal">{{ $t('rq_closed_at') }}</dt>
                <dd class="col-7">{{ fmtDateTime(req.closed_at) }}</dd>
              </template>
            </dl>
            <div v-if="req.created_at" class="text-secondary small mt-3">{{ $t('created_on', { date: fmtDateTime(req.created_at) }) }}</div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
/**
 * GET /api/portal/requests/{id} {request: {…, events[]}};
 * POST /api/portal/requests/{id}/reply (multipart: note, attachments[]).
 */
import http from '../lib/http';
import PageActions from '../components/PageActions.vue';
import EmptyState from '../components/EmptyState.vue';
import { badge, protocolTone } from '../lib/ui';

const EVENT_ICONS = {
  submitted: 'send', received: 'inbox', acknowledged: 'eye-check', response: 'message-reply', reply: 'message-circle',
  forwarded: 'arrow-forward-up', returned: 'arrow-back-up', approved: 'circle-check', rejected: 'circle-x',
  closed: 'lock', reopened: 'lock-open', attachment: 'paperclip', reassigned: 'user-share', note: 'note', updated: 'pencil',
};

export default {
  components: { PageActions, EmptyState },
  data() {
    return { req: { events: [] }, loading: true, notFound: false, note: '', files: [], sending: false, error: '' };
  },
  watch: { '$route.params.id'() { this.fetch(); } },
  mounted() { this.fetch(); },
  methods: {
    badge, protocolTone,
    pageMeta() {
      return { title: this.req.Ref || this.$t('rq_request'), pretitle: this.$t('nav_requests'), crumbs: [{ label: this.$t('nav_requests'), to: '/requests' }] };
    },
    statusText(s) { return this.tr(`rq_status_${s}`, s || ''); },
    eventIcon(e) { return EVENT_ICONS[e.action] || 'point'; },
    eventIconClass(e) {
      if (e.by_customer) return 'bg-primary-lt';
      if (e.action === 'approved') return 'bg-green-lt';
      if (e.action === 'rejected') return 'bg-red-lt';
      return '';
    },
    fmtDateTime(d) {
      if (!d) return '—';
      try { return new Date(String(d).replace(' ', 'T')).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }); } catch (_) { return d; }
    },
    async fetch() {
      this.loading = true;
      try {
        const { data } = await http.get(`/portal/requests/${this.$route.params.id}`);
        this.req = data.request || { events: [] };
        if (!Array.isArray(this.req.events)) this.req.events = [];
        this.notFound = false;
      } catch (_) {
        this.notFound = true;
      }
      this.loading = false;
      this.applyPage();
    },
    onFiles(e) {
      this.files = this.files.concat(Array.from(e.target.files || [])).slice(0, 5);
      e.target.value = '';
    },
    async reply() {
      if (!this.note.trim() || this.sending) return;
      this.error = '';
      this.sending = true;
      try {
        const fd = new FormData();
        fd.append('note', this.note);
        this.files.forEach((f) => fd.append('attachments[]', f));
        await http.post(`/portal/requests/${this.req.id}/reply`, fd);
        this.note = '';
        this.files = [];
        await this.fetch();
      } catch (e) {
        const d = e && e.response && e.response.data;
        this.error = (d && (d.message || (d.errors && Object.values(d.errors).flat().join(' ')))) || this.$t('request_submit_failed');
      }
      this.sending = false;
    },
  },
};
</script>

<style scoped>
.rq-body, .rq-note { white-space: pre-wrap; word-break: break-word; }
.min-w-0 { min-width: 0; }
</style>
