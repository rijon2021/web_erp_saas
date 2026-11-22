<template>
  <div>
    <PageActions>
      <router-link to="/rental-requests?new=1" class="btn btn-primary"><i class="ti ti-tool me-1"></i>{{ tr('rental_report_problem', 'Report a problem') }}</router-link>
    </PageActions>

    <div class="row row-cards mb-3">
      <div class="col-sm-6 col-xl-3"><StatCard :label="tr('rental_active_rentals', 'Active rentals')" :value="summary.active_contracts || 0" icon="key" tone="green" /></div>
      <div class="col-sm-6 col-xl-3"><StatCard :label="tr('rental_outstanding', 'Amount owed')" :value="money(summary.outstanding || 0)" icon="cash" tone="blue" /></div>
      <div class="col-sm-6 col-xl-3"><StatCard :label="tr('rental_overdue', 'Overdue')" :value="money(summary.overdue || 0)" icon="alert-triangle" :tone="summary.overdue > 0 ? 'red' : 'secondary'" /></div>
      <div class="col-sm-6 col-xl-3"><StatCard :label="tr('rental_to_sign', 'Waiting for your signature')" :value="summary.unsigned || 0" icon="signature" :tone="summary.unsigned > 0 ? 'orange' : 'secondary'" /></div>
    </div>

    <DataTable
      ref="table"
      :title="tr('rental_my_rentals', 'My rentals')"
      :columns="columns"
      :rows="contracts"
      :total-rows="totalRows"
      :loading="loading"
      default-sort="start_date"
      default-dir="desc"
      :search-placeholder="tr('rental_search', 'Search by number or item…')"
      :empty-text="tr('rental_none', 'You have no rentals yet.')"
      empty-icon="key"
      :filters="filters"
      @query="fetch"
      @reset="resetFilters"
    >
      <template #filters>
        <select v-model="filters.status" class="form-select w-auto" :aria-label="$t('status')">
          <option value="">{{ $t('all_statuses') }}</option>
          <option v-for="s in statuses" :key="s" :value="s">{{ statusLabel(s) }}</option>
        </select>
      </template>

      <template #cell-contract_number="{ row }"><router-link :to="`/rentals/${row.id}`" class="fw-medium font-monospace">{{ row.contract_number }}</router-link></template>
      <template #cell-items="{ value }"><span class="text-truncate d-inline-block" style="max-width: 280px" :title="value">{{ value || '—' }}</span></template>
      <template #cell-period="{ row }"><span class="text-secondary">{{ row.start_date || '—' }} → {{ row.end_date || tr('rental_open_ended', 'Open-ended') }}</span></template>
      <template #cell-total_amount="{ row }">{{ money(row.total_amount) }} <span class="text-secondary small">/ {{ periodLabel(row.rate_period) }}</span></template>
      <template #cell-status="{ row }">
        <span :class="badge(stageTone(row.stage))">{{ stageLabel(row.stage, row.status) }}</span>
        <span v-if="row.status === 'active' && !row.signed" class="badge bg-orange-lt ms-1">{{ tr('rental_unsigned', 'Not signed') }}</span>
      </template>
      <template #cell-actions="{ row }">
        <div class="rst-row-actions justify-content-end">
          <a :href="`/api/portal/rentals/${row.id}/agreement`" target="_blank" rel="noopener" :title="tr('rental_agreement', 'Agreement')" class="text-secondary"><i class="ti ti-file-type-pdf"></i></a>
          <router-link :to="`/rentals/${row.id}`" :title="$t('view')" class="text-secondary"><i class="ti ti-eye"></i></router-link>
        </div>
      </template>
    </DataTable>
  </div>
</template>

<script>
import http from '../lib/http';
import { money, badge } from '../lib/ui';
import { rentalStageTone, rentalStageLabel, rentalPeriodLabel } from '../lib/rental';
import DataTable from '../components/DataTable.vue';
import PageActions from '../components/PageActions.vue';
import StatCard from '../components/StatCard.vue';

export default {
  components: { DataTable, PageActions, StatCard },
  data() {
    return { contracts: [], statuses: [], totalRows: 0, loading: false, summary: {}, filters: { status: '' } };
  },
  computed: {
    columns() {
      return [
        { key: 'contract_number', label: this.$t('number'), sortable: true },
        { key: 'items', label: this.tr('rental_items', 'Rented items') },
        { key: 'period', label: this.$t('period') },
        { key: 'total_amount', label: this.tr('rental_rent', 'Rent'), sortable: true, numeric: true },
        { key: 'status', label: this.$t('status') },
        { key: 'actions', label: '', class: 'w-1 text-end' },
      ];
    },
  },
  mounted() { this.loadSummary(); },
  methods: {
    money, badge,
    stageTone: rentalStageTone,
    stageLabel(stage, status) { return rentalStageLabel(this, stage, status); },
    periodLabel(p) { return rentalPeriodLabel(this, p); },
    pageMeta() { return { title: this.tr('rental_my_rentals', 'My rentals'), pretitle: this.tr('nav_rentals', 'Rentals'), crumbs: [] }; },
    async loadSummary() {
      try { this.summary = (await http.get('/portal/rentals/summary')).data || {}; } catch (_) {}
    },
    async fetch(q) {
      this.loading = true;
      try {
        const { data } = await http.get('/portal/rentals', { params: { ...q, status: this.filters.status || undefined } });
        this.contracts = data.contracts || [];
        this.totalRows = data.totalRows || 0;
        if (Array.isArray(data.statuses)) this.statuses = data.statuses;
      } catch (_) {}
      this.loading = false;
    },
    resetFilters() {
      this.filters = { status: '' };
      if (this.$refs.table) this.$refs.table.clearSearch();
    },
  },
};
</script>
