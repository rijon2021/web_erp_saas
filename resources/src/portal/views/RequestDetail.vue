<template>
  <div class="portal-page portal-request-detail">
    <header class="pc-page-header">
      <div>
        <h1 class="pc-page-title">{{ request.Ref || $t('rq_request') }}</h1>
        <p class="pc-page-sub" v-if="request.created_at">{{ $t('created_on', { date: request.created_at }) }}</p>
      </div>
      <router-link to="/requests" class="pc-link-back">{{ $t('back_to_requests') }}</router-link>
    </header>

    <div v-if="loading" class="pc-inline-loading">
      <div class="pc-spinner"></div><span>{{ $t('loading') }}</span>
    </div>

    <template v-else>
      <div class="pc-card pc-detail">
        <div class="pc-detail-header">
          <span :class="'pc-badge pc-badge-' + badge(request.status)">{{ $t('rq_status_' + request.status) }}</span>
          <span class="pc-muted">{{ $t('rq_type_' + request.type) }}</span>
        </div>
        <h2 class="rq-subject">{{ request.subject }}</h2>
        <dl class="pc-meta-grid">
          <div><dt>{{ $t('rq_current_desk') }}</dt><dd>{{ request.current_desk || '—' }}</dd></div>
          <div><dt>{{ $t('rq_last_activity') }}</dt><dd>{{ request.last_activity_at || '—' }}</dd></div>
          <div v-if="request.closed_at"><dt>{{ $t('rq_closed_at') }}</dt><dd>{{ request.closed_at }}</dd></div>
        </dl>
        <div class="pc-notes">
          <h3>{{ $t('details') }}</h3>
          <pre>{{ request.body }}</pre>
        </div>
      </div>

      <!-- History (customer-visible events only) -->
      <div class="pc-card">
        <h3 class="rq-h3">{{ $t('rq_history') }}</h3>
        <ol class="rq-timeline">
          <li v-for="e in request.events" :key="e.id" :class="{ 'rq-mine': e.by_customer }">
            <div class="rq-ev-head">
              <strong>{{ $t('rq_ev_' + e.action) }}</strong>
              <span v-if="e.to_desk && ['submitted', 'forwarded', 'returned'].includes(e.action)"> → {{ e.to_desk }}</span>
              <span class="pc-muted"> · {{ e.by_customer ? $t('you') : e.actor }} · {{ e.created_at }}</span>
            </div>
            <pre v-if="e.note" class="rq-note">{{ e.note }}</pre>
            <div v-if="e.attachments && e.attachments.length" class="rq-files">
              <a v-for="a in e.attachments" :key="a.id" :href="a.url" target="_blank" rel="noopener" class="pc-chip">📎 {{ a.file_name }}</a>
            </div>
          </li>
        </ol>
      </div>

      <!-- Reply -->
      <form v-if="request.status !== 'closed'" class="pc-card pc-form" @submit.prevent="reply">
        <div class="pc-form-row">
          <label>{{ $t('your_message') }}</label>
          <textarea v-model="note" rows="4" maxlength="10000" required></textarea>
        </div>
        <div class="pc-form-row">
          <label>{{ $t('attachments') }} <span class="pc-muted">{{ $t('optional') }}</span></label>
          <input type="file" multiple @change="onFiles" />
          <ul v-if="files.length" class="rq-files-list">
            <li v-for="(f, i) in files" :key="i">{{ f.name }} <button type="button" class="pc-icon-btn" @click="files.splice(i, 1)">×</button></li>
          </ul>
        </div>
        <div v-if="error" class="pc-alert pc-alert-error">{{ error }}</div>
        <div class="pc-form-actions">
          <button type="submit" class="pc-btn pc-btn-primary" :disabled="sending">
            <span v-if="sending" class="pc-btn-spinner"></span>{{ $t('send_reply') }}
          </button>
        </div>
      </form>
      <div v-else class="pc-alert pc-alert-success">{{ $t('rq_closed_note') }}</div>
    </template>
  </div>
</template>

<script>
/**
 * Customer portal — one request with its customer-visible history and a reply
 * box. GET /api/portal/requests/{id}; POST /api/portal/requests/{id}/reply.
 */
import http from '../lib/http';
import { statusBadge } from './Requests.vue';

export default {
  data() {
    return { request: { events: [] }, loading: false, note: '', files: [], sending: false, error: '' };
  },
  mounted() { this.fetch(); },
  watch: { '$route.params.id': 'fetch' },
  methods: {
    async fetch() {
      this.loading = true;
      try {
        const { data } = await http.get(`/portal/requests/${this.$route.params.id}`);
        this.request = data.request || { events: [] };
      } catch (_) {}
      this.loading = false;
    },
    onFiles(e) {
      this.files = this.files.concat(Array.from(e.target.files || [])).slice(0, 5);
      e.target.value = '';
    },
    async reply() {
      this.error = '';
      this.sending = true;
      try {
        const fd = new FormData();
        fd.append('note', this.note);
        this.files.forEach(f => fd.append('attachments[]', f));
        await http.post(`/portal/requests/${this.$route.params.id}/reply`, fd);
        this.note = '';
        this.files = [];
        await this.fetch();
      } catch (e) {
        const d = e && e.response && e.response.data;
        this.error = (d && d.message) || this.$t('request_submit_failed');
      }
      this.sending = false;
    },
    badge: statusBadge,
  },
};
</script>

<style scoped>
.portal-request-detail { padding-bottom: 1rem; }
.pc-page-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 1.25rem; gap: 1rem; flex-wrap: wrap; }
.pc-page-title { font-size: 1.5rem; font-weight: 700; color: var(--pc-text); margin: 0 0 0.2rem; }
.pc-page-sub { font-size: 0.9rem; color: var(--pc-text-muted); margin: 0; }
.pc-link-back { color: var(--pc-text-muted); text-decoration: none; font-size: 0.88rem; }
.pc-link-back:hover { color: var(--pc-primary); }
.pc-inline-loading { display: flex; align-items: center; justify-content: center; gap: 0.75rem; padding: 3rem; color: var(--pc-text-muted); }
.pc-spinner { width: 28px; height: 28px; border: 2px solid rgba(79, 70, 229, 0.15); border-top-color: var(--pc-primary); border-radius: 50%; animation: pc-spin 0.7s linear infinite; }
@keyframes pc-spin { to { transform: rotate(360deg); } }

.pc-card { background: var(--pc-surface); border: 1px solid var(--pc-border); border-radius: var(--pc-radius); box-shadow: var(--pc-shadow-sm); padding: 1.5rem; margin-bottom: 1rem; }
.pc-detail-header { display: flex; align-items: center; gap: 0.75rem; padding-bottom: 1rem; border-bottom: 1px solid var(--pc-border); margin-bottom: 1.1rem; }
.pc-badge { display: inline-block; padding: 0.3rem 0.7rem; border-radius: 999px; font-size: 0.78rem; font-weight: 600; text-transform: capitalize; }
.pc-badge-paid { background: var(--pc-success-bg); color: var(--pc-success); }
.pc-badge-pending { background: var(--pc-warning-bg); color: var(--pc-warning); }
.pc-badge-unpaid { background: var(--pc-danger-bg); color: var(--pc-danger); }
.pc-badge-progress { background: #dbeafe; color: #1d4ed8; }
.pc-badge-partial { background: #e2e8f0; color: #475569; }
.pc-muted { color: var(--pc-text-soft); font-weight: 400; font-size: 0.82rem; }
.pc-meta-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 0.85rem 1.5rem; margin: 0 0 1.2rem; }
.pc-meta-grid div { display: flex; flex-direction: column; }
.pc-meta-grid dt { font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.04em; color: var(--pc-text-soft); font-weight: 600; }
.pc-meta-grid dd { margin: 0.15rem 0 0; font-size: 0.95rem; color: var(--pc-text); font-weight: 500; }
.pc-notes h3 { font-size: 0.95rem; margin: 0 0 0.55rem; color: var(--pc-text); }
.pc-notes pre { background: var(--pc-surface-alt); padding: 0.85rem 1rem; border-radius: 10px; font-family: inherit; white-space: pre-wrap; font-size: 0.9rem; color: var(--pc-text); margin: 0; }

.pc-form { display: flex; flex-direction: column; gap: 1.15rem; }
.pc-form-row { display: flex; flex-direction: column; gap: 0.45rem; }
.pc-form-row label { font-size: 0.85rem; font-weight: 600; color: var(--pc-text); }
.pc-form-row input, .pc-form-row textarea { width: 100%; padding: 0.6rem 0.85rem; border: 1px solid var(--pc-border-strong); border-radius: 10px; font-size: 0.92rem; background: var(--pc-surface-alt); box-sizing: border-box; font-family: inherit; }
.pc-form-row input:focus, .pc-form-row textarea:focus { outline: none; background: var(--pc-surface); border-color: var(--pc-primary); box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.15); }
.pc-icon-btn { background: transparent; border: 1px solid var(--pc-border-strong); width: 26px; height: 26px; border-radius: 8px; cursor: pointer; color: var(--pc-text-soft); display: inline-flex; align-items: center; justify-content: center; }
.pc-icon-btn:hover { border-color: var(--pc-danger); color: var(--pc-danger); }
.pc-form-actions { display: flex; justify-content: flex-end; gap: 0.6rem; margin-top: 0.5rem; }
.pc-btn { display: inline-flex; align-items: center; gap: 0.4rem; padding: 0.55rem 1rem; border-radius: 10px; font-size: 0.9rem; font-weight: 600; text-decoration: none; border: 1px solid transparent; cursor: pointer; }
.pc-btn-primary { background: var(--pc-primary); color: #fff; }
.pc-btn-primary:hover:not(:disabled) { background: var(--pc-primary-600); }
.pc-btn-primary:disabled { opacity: 0.6; cursor: not-allowed; }
.pc-btn-spinner { display: inline-block; width: 0.95rem; height: 0.95rem; border: 2px solid rgba(255, 255, 255, 0.35); border-top-color: #fff; border-radius: 50%; animation: pc-spin 0.7s linear infinite; }
.pc-alert { padding: 0.7rem 0.95rem; border-radius: 10px; font-size: 0.88rem; }
.pc-alert-error { background: var(--pc-danger-bg); color: #b91c1c; border: 1px solid #fecaca; }
.pc-alert-success { background: var(--pc-success-bg); color: var(--pc-success); border: 1px solid #a7f3d0; }
.pc-chip { display: inline-flex; align-items: center; padding: 0.25rem 0.6rem; border: 1px solid var(--pc-border-strong); border-radius: 6px; font-size: 0.75rem; font-weight: 500; color: var(--pc-text-muted); text-decoration: none; background: #fff; }
.pc-chip:hover { border-color: var(--pc-primary); color: var(--pc-primary); }

.rq-subject { font-size: 18px; margin: 10px 0 12px; }
.rq-h3 { margin: 0 0 12px; font-size: 15px; }
.rq-timeline { list-style: none; padding: 0; margin: 0; border-left: 2px solid rgba(0, 0, 0, 0.08); }
.rq-timeline li { position: relative; padding: 0 0 16px 18px; }
.rq-timeline li::before { content: ''; position: absolute; left: -7px; top: 4px; width: 12px; height: 12px; border-radius: 50%; background: #94a3b8; }
.rq-timeline li.rq-mine::before { background: #6c5ce7; }
.rq-ev-head { font-size: 14px; }
.rq-note { white-space: pre-wrap; font: inherit; margin: 6px 0 0; padding: 8px 10px; border-radius: 8px; background: rgba(0, 0, 0, 0.04); }
.rq-files { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 6px; }
.rq-files-list { list-style: none; padding: 0; margin: 8px 0 0; font-size: 13px; }
.rq-files-list li { display: flex; align-items: center; gap: 8px; padding: 2px 0; }
</style>
