<template>
  <div class="portal-page portal-requests">
    <header class="pc-page-header">
      <div>
        <h1 class="pc-page-title">{{ $t('requests') }}</h1>
        <p class="pc-page-sub">{{ $t('requests_subtitle') }}</p>
      </div>
      <router-link to="/requests/new" class="pc-btn pc-btn-primary">{{ $t('new_request') }}</router-link>
    </header>

    <div class="pc-card">
      <div class="pc-toolbar">
        <div class="pc-search">
          <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>
          <input v-model="search" type="text" :placeholder="$t('search_requests')" @input="debounceFetch" />
        </div>
        <select v-model="status" class="rq-select" @change="page = 1; fetch()">
          <option value="">{{ $t('all_statuses') }}</option>
          <option v-for="s in STATUSES" :key="s" :value="s">{{ $t('rq_status_' + s) }}</option>
        </select>
      </div>

      <div v-if="loading" class="pc-inline-loading">
        <div class="pc-spinner"></div>
        <span>{{ $t('loading') }}</span>
      </div>

      <template v-else>
        <div v-if="!requests.length" class="pc-empty">
          <div class="pc-empty-icon">📨</div>
          <p>{{ $t('no_requests_found') }}</p>
        </div>

        <!-- Desktop table -->
        <div v-else class="pc-table-wrap pc-hide-mobile">
          <table class="pc-table">
            <thead>
              <tr>
                <th>{{ $t('ref') }}</th>
                <th>{{ $t('subject') }}</th>
                <th>{{ $t('rq_type') }}</th>
                <th>{{ $t('status') }}</th>
                <th>{{ $t('rq_last_activity') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="r in requests" :key="r.id">
                <td><router-link :to="`/requests/${r.id}`" class="pc-link">{{ r.Ref }}</router-link></td>
                <td><router-link :to="`/requests/${r.id}`" class="pc-link">{{ r.subject }}</router-link></td>
                <td>{{ $t('rq_type_' + r.type) }}</td>
                <td><span :class="'pc-badge pc-badge-' + badge(r.status)">{{ $t('rq_status_' + r.status) }}</span></td>
                <td>{{ r.last_activity_at || r.created_at }}</td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Mobile card list -->
        <ul v-if="requests.length" class="pc-mobile-list pc-show-mobile">
          <li v-for="r in requests" :key="'m-' + r.id" class="pc-mobile-row">
            <router-link :to="`/requests/${r.id}`" class="pc-mobile-row-main">
              <div class="pc-mobile-top">
                <strong class="pc-mobile-ref">{{ r.Ref }}</strong>
                <span :class="'pc-badge pc-badge-' + badge(r.status)">{{ $t('rq_status_' + r.status) }}</span>
              </div>
              <div class="pc-mobile-mid">
                <span>{{ r.subject }}</span>
              </div>
              <div class="pc-mobile-foot">
                <span class="pc-kv"><small>{{ $t('rq_type') }}</small>{{ $t('rq_type_' + r.type) }}</span>
                <span class="pc-kv"><small>{{ $t('rq_last_activity') }}</small>{{ r.last_activity_at || r.created_at }}</span>
              </div>
            </router-link>
          </li>
        </ul>

        <div v-if="totalPages > 1" class="pc-pagination">
          <button type="button" class="pc-pg-btn" :disabled="page <= 1" @click="page--; fetch()">{{ $t('previous') }}</button>
          <span class="pc-pg-info">{{ $t('page_of', { page: page, total: totalPages }) }}</span>
          <button type="button" class="pc-pg-btn" :disabled="page >= totalPages" @click="page++; fetch()">{{ $t('next') }}</button>
        </div>
      </template>
    </div>
  </div>
</template>

<script>
/**
 * Customer portal — my protocol requests (complaints, requests, enquiries…).
 * GET /api/portal/requests {requests, total, last_page}.
 */
import http from '../lib/http';

export const STATUSES = ['open', 'received', 'acknowledged', 'in_progress', 'approved', 'rejected', 'closed'];

/** Map request statuses onto the badge palette the portal stylesheet ships. */
export function statusBadge(status) {
  if (status === 'approved' || status === 'closed') return 'paid';
  if (status === 'rejected') return 'unpaid';
  if (status === 'open') return 'pending';
  if (status === 'in_progress') return 'progress';
  return 'partial';
}

export default {
  data() {
    return { requests: [], loading: false, search: '', status: '', page: 1, pageSize: 10, totalPages: 1, STATUSES, timer: null };
  },
  mounted() { this.fetch(); },
  methods: {
    async fetch() {
      this.loading = true;
      try {
        const { data } = await http.get('/portal/requests', { params: { page: this.page, limit: this.pageSize, search: this.search || undefined, status: this.status || undefined } });
        this.requests = data.requests || [];
        this.totalPages = data.last_page || 1;
      } catch (_) {
        this.requests = [];
      }
      this.loading = false;
    },
    debounceFetch() {
      clearTimeout(this.timer);
      this.timer = setTimeout(() => { this.page = 1; this.fetch(); }, 300);
    },
    badge: statusBadge,
  },
};
</script>

<style scoped>
.portal-requests { padding-bottom: 1rem; }
.pc-page-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 1.25rem; gap: 1rem; flex-wrap: wrap; }
.pc-page-title { font-size: 1.5rem; font-weight: 700; color: var(--pc-text); margin: 0 0 0.2rem; }
.pc-page-sub { font-size: 0.9rem; color: var(--pc-text-muted); margin: 0; }
.pc-btn { display: inline-flex; align-items: center; gap: 0.4rem; padding: 0.55rem 1rem; border-radius: 10px; font-size: 0.9rem; font-weight: 600; text-decoration: none; border: 1px solid transparent; cursor: pointer; }
.pc-btn-primary { background: var(--pc-primary); color: #fff; }
.pc-btn-primary:hover { background: var(--pc-primary-600); }

.pc-card { background: var(--pc-surface); border: 1px solid var(--pc-border); border-radius: var(--pc-radius); box-shadow: var(--pc-shadow-sm); overflow: hidden; }
.pc-toolbar { display: flex; align-items: center; gap: 0.6rem; padding: 0.9rem 1.15rem; border-bottom: 1px solid var(--pc-border); background: var(--pc-surface); }
.pc-search { position: relative; display: flex; align-items: center; flex: 1; }
.pc-search svg { position: absolute; left: 0.85rem; color: var(--pc-text-soft); pointer-events: none; }
.pc-search input { width: 100%; padding: 0.6rem 1rem 0.6rem 2.3rem; border: 1px solid var(--pc-border-strong); border-radius: 10px; font-size: 0.92rem; background: var(--pc-surface-alt); transition: border-color 0.15s, background 0.15s, box-shadow 0.15s; box-sizing: border-box; }
.pc-search input:focus { outline: none; background: var(--pc-surface); border-color: var(--pc-primary); box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.15); }

.pc-inline-loading { display: flex; align-items: center; justify-content: center; gap: 0.75rem; padding: 3rem; color: var(--pc-text-muted); }
.pc-spinner { width: 28px; height: 28px; border: 2px solid rgba(79, 70, 229, 0.15); border-top-color: var(--pc-primary); border-radius: 50%; animation: pc-spin 0.7s linear infinite; }
@keyframes pc-spin { to { transform: rotate(360deg); } }

.pc-empty { padding: 3rem 1rem; text-align: center; color: var(--pc-text-soft); }
.pc-empty-icon { font-size: 2.5rem; opacity: 0.7; margin-bottom: 0.5rem; }
.pc-empty p { margin: 0; font-size: 0.95rem; }

.pc-table-wrap { overflow-x: auto; }
.pc-table { width: 100%; border-collapse: collapse; font-size: 0.88rem; }
.pc-table th, .pc-table td { padding: 0.7rem 1.15rem; text-align: left; border-bottom: 1px solid var(--pc-border); }
.pc-table th { background: var(--pc-surface-alt); font-weight: 600; color: var(--pc-text-muted); font-size: 0.73rem; text-transform: uppercase; letter-spacing: 0.04em; }
.pc-table tbody tr:last-child td { border-bottom: none; }
.pc-table tbody tr:hover { background: var(--pc-surface-alt); }

.pc-link { color: var(--pc-primary); text-decoration: none; font-weight: 500; }
.pc-link:hover { color: var(--pc-primary-600); text-decoration: underline; }

.pc-badge { display: inline-block; padding: 0.22rem 0.55rem; border-radius: 999px; font-size: 0.72rem; font-weight: 600; text-transform: capitalize; }
.pc-badge-paid { background: var(--pc-success-bg); color: var(--pc-success); }
.pc-badge-pending { background: var(--pc-warning-bg); color: var(--pc-warning); }
.pc-badge-unpaid { background: var(--pc-danger-bg); color: var(--pc-danger); }
.pc-badge-progress { background: #dbeafe; color: #1d4ed8; }
.pc-badge-partial { background: #e2e8f0; color: #475569; }

/* Mobile list */
.pc-mobile-list { list-style: none; margin: 0; padding: 0; }
.pc-mobile-row { border-bottom: 1px solid var(--pc-border); }
.pc-mobile-row:last-child { border-bottom: none; }
.pc-mobile-row-main { display: block; padding: 0.95rem 1.15rem; text-decoration: none; color: var(--pc-text); }
.pc-mobile-top { display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.35rem; gap: 0.5rem; }
.pc-mobile-ref { font-weight: 600; font-size: 0.95rem; }
.pc-mobile-mid { display: flex; justify-content: space-between; align-items: center; font-size: 0.88rem; margin-bottom: 0.55rem; }
.pc-mobile-foot { display: flex; align-items: center; gap: 0.9rem; flex-wrap: wrap; padding-top: 0.5rem; border-top: 1px dashed var(--pc-border); }
.pc-kv { display: inline-flex; flex-direction: column; gap: 0.1rem; font-size: 0.82rem; }
.pc-kv small { color: var(--pc-text-soft); font-size: 0.65rem; text-transform: uppercase; letter-spacing: 0.06em; font-weight: 600; }

.pc-pagination { display: flex; align-items: center; justify-content: center; gap: 1rem; padding: 0.85rem 1.15rem; border-top: 1px solid var(--pc-border); background: var(--pc-surface-alt); }
.pc-pg-btn { padding: 0.5rem 1rem; background: var(--pc-surface); border: 1px solid var(--pc-border-strong); border-radius: 8px; font-size: 0.88rem; font-weight: 500; color: var(--pc-text); cursor: pointer; transition: background 0.15s, border-color 0.15s; }
.pc-pg-btn:hover:not(:disabled) { background: var(--pc-surface-alt); border-color: var(--pc-primary); color: var(--pc-primary); }
.pc-pg-btn:disabled { opacity: 0.45; cursor: not-allowed; }
.pc-pg-info { font-size: 0.85rem; color: var(--pc-text-muted); }

.pc-show-mobile { display: none; }
.pc-hide-mobile { display: block; }

.rq-select {
  height: 38px;
  padding: 0 10px;
  border: 1px solid var(--pc-border-strong);
  border-radius: 10px;
  background: var(--pc-surface-alt);
  font: inherit;
  color: inherit;
}
.rq-select:focus { outline: none; background: var(--pc-surface); border-color: var(--pc-primary); box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.15); }

@media (max-width: 768px) {
  .pc-page-title { font-size: 1.3rem; }
  .pc-show-mobile { display: block; }
  .pc-hide-mobile { display: none; }
  .pc-pagination { flex-wrap: wrap; gap: 0.5rem; padding: 0.75rem; }
  .pc-pg-info { width: 100%; text-align: center; order: -1; }
}
</style>
