<template>
  <!-- Protocol & Requests (SaaS only): complaints, requests, enquiries… the customer sent. -->
  <div>
    <PageActions>
      <router-link to="/requests/new" class="btn btn-primary">
        <i class="ti ti-plus me-1"></i>{{ $t('new_request') }}
      </router-link>
    </PageActions>

    <DataTable
      ref="table"
      :title="$t('nav_requests')"
      :columns="columns"
      :rows="requests"
      :total-rows="totalRows"
      :loading="loading"
      default-sort="last_activity_at"
      default-dir="desc"
      :search-placeholder="$t('search_requests')"
      :empty-text="$t('no_requests_found')"
      empty-icon="inbox"
      :filters="filters"
      @query="fetch"
      @reset="resetFilters"
    >
      <template #filters>
        <select v-model="filters.status" class="form-select w-auto" :aria-label="$t('status')">
          <option value="">{{ $t('all_statuses') }}</option>
          <option v-for="s in statuses" :key="s" :value="s">{{ statusText(s) }}</option>
        </select>
      </template>

      <template #cell-Ref="{ row }"><router-link :to="`/requests/${row.id}`" class="fw-medium font-monospace">{{ row.Ref }}</router-link></template>
      <template #cell-subject="{ row }"><router-link :to="`/requests/${row.id}`" class="text-reset">{{ row.subject }}</router-link></template>
      <template #cell-type="{ value }"><span class="text-secondary">{{ typeText(value) }}</span></template>
      <template #cell-status="{ value }"><span :class="badge(protocolTone(value))">{{ statusText(value) }}</span></template>
      <template #cell-last_activity_at="{ row }"><span class="text-secondary">{{ fmtDateTime(row.last_activity_at || row.created_at) }}</span></template>
      <template #cell-actions="{ row }">
        <div class="rst-row-actions justify-content-end">
          <router-link :to="`/requests/${row.id}`" :title="$t('view')" class="text-secondary"><i class="ti ti-eye"></i></router-link>
        </div>
      </template>
    </DataTable>
  </div>
</template>

<script>
/**
 * My protocol requests — GET /api/portal/requests
 * {requests, totalRows, statuses}; ?page, limit, search, status, sort, dir.
 */
import http from '../lib/http';
import PageActions from '../components/PageActions.vue';
import DataTable from '../components/DataTable.vue';
import { badge, protocolTone } from '../lib/ui';

export const PROTOCOL_STATUSES = ['open', 'received', 'acknowledged', 'in_progress', 'approved', 'rejected', 'closed'];

export default {
  components: { PageActions, DataTable },
  data() {
    return { requests: [], statuses: PROTOCOL_STATUSES, totalRows: 0, loading: false, filters: { status: '' } };
  },
  computed: {
    columns() {
      return [
        { key: 'Ref', label: this.$t('ref'), sortable: true },
        { key: 'subject', label: this.$t('subject'), sortable: true },
        { key: 'type', label: this.$t('rq_type'), sortable: true },
        { key: 'status', label: this.$t('status'), sortable: true },
        { key: 'last_activity_at', label: this.$t('rq_last_activity'), sortable: true },
        { key: 'actions', label: '', class: 'w-1 text-end' },
      ];
    },
  },
  methods: {
    badge, protocolTone,
    pageMeta() { return { title: this.$t('nav_requests'), pretitle: this.$t('nav_services'), crumbs: [] }; },
    statusText(s) { return this.tr(`rq_status_${s}`, s); },
    typeText(t) { return this.tr(`rq_type_${t}`, t); },
    fmtDateTime(d) {
      if (!d) return '—';
      try { return new Date(String(d).replace(' ', 'T')).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }); } catch (_) { return d; }
    },
    async fetch(q) {
      this.loading = true;
      try {
        const { data } = await http.get('/portal/requests', { params: { ...q, status: this.filters.status || undefined } });
        this.requests = data.requests || [];
        this.totalRows = data.totalRows ?? data.total ?? 0;
        if (Array.isArray(data.statuses)) this.statuses = data.statuses;
      } catch (_) {
        this.requests = [];
      }
      this.loading = false;
    },
    resetFilters() {
      this.filters = { status: '' };
      if (this.$refs.table) this.$refs.table.clearSearch();
    },
  },
};
</script>
