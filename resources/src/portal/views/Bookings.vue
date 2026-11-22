<template>
  <div>
    <PageActions v-if="bookUrl">
      <a :href="bookUrl" class="btn btn-primary"><i class="ti ti-plus me-1"></i>{{ tr('bookings_book_new', 'Book an appointment') }}</a>
    </PageActions>

    <DataTable
      ref="table"
      :title="tr('bookings_title', 'My bookings')"
      :columns="columns"
      :rows="bookings"
      :total-rows="totalRows"
      :loading="loading"
      :search-placeholder="tr('bookings_search', 'Search bookings')"
      :empty-text="filters.when === 'upcoming' ? tr('bookings_none_upcoming', 'No upcoming bookings.') : tr('bookings_none', 'No bookings yet.')"
      empty-icon="calendar-event"
      :filters="filters"
      @query="fetch"
      @reset="resetFilters"
    >
      <template #filters>
        <select v-model="filters.when" class="form-select w-auto" :aria-label="tr('bookings_show', 'Show')">
          <option value="upcoming">{{ tr('bookings_upcoming', 'Upcoming') }}</option>
          <option value="past">{{ tr('bookings_past', 'Past') }}</option>
          <option value="all">{{ tr('bookings_all', 'All') }}</option>
        </select>
      </template>

      <template #cell-Ref="{ row }"><router-link :to="`/bookings/${row.id}`" class="fw-medium font-monospace">{{ row.Ref }}</router-link></template>
      <template #cell-when="{ row }">
        <span>{{ whenText(row) }}</span>
        <div v-if="row.staff" class="text-secondary small">{{ row.staff }}</div>
      </template>
      <template #cell-stage="{ row }">
        <span :class="badge(stageTone(row.stage))">{{ stageLabel(row.stage) }}</span>
        <span v-if="row.needs_answer" class="badge bg-azure-lt ms-1">{{ tr('bookings_needs_answer', 'Answer needed') }}</span>
        <span v-if="row.deposit_due" class="badge bg-orange-lt ms-1">{{ tr('bookings_deposit_due', 'Deposit to pay') }}</span>
      </template>
      <template #cell-actions="{ row }">
        <div class="rst-row-actions justify-content-end">
          <router-link :to="`/bookings/${row.id}`" :title="$t('view')" class="text-secondary"><i class="ti ti-eye"></i></router-link>
        </div>
      </template>
    </DataTable>
  </div>
</template>

<script>
import http from '../lib/http';
import PageActions from '../components/PageActions.vue';
import DataTable from '../components/DataTable.vue';
import { badge } from '../lib/ui';
import { bookingWhen, bookingStageLabel, bookingStageTone } from '../lib/bookings';

export default {
  components: { PageActions, DataTable },
  data() {
    return { bookings: [], totalRows: 0, loading: false, bookUrl: null, filters: { when: 'upcoming' } };
  },
  computed: {
    columns() {
      return [
        { key: 'Ref', label: this.$t('ref') },
        { key: 'service', label: this.$t('service') },
        { key: 'when', label: this.$t('when') },
        { key: 'stage', label: this.$t('status') },
        { key: 'actions', label: '', class: 'w-1 text-end' },
      ];
    },
  },
  methods: {
    badge,
    whenText(row) { return bookingWhen(row, this); },
    stageLabel(s) { return bookingStageLabel(s, this); },
    stageTone: bookingStageTone,
    pageMeta() { return { title: this.tr('bookings_title', 'My bookings'), pretitle: this.$t('nav_services'), crumbs: [] }; },
    async fetch(q) {
      this.loading = true;
      try {
        const { data } = await http.get('/portal/bookings', { params: { ...q, when: this.filters.when } });
        this.bookings = data.bookings || [];
        this.totalRows = data.totalRows || 0;
        // The website's booking page (only while the store uses the Booking theme).
        this.bookUrl = data.book_url || null;
      } catch (_) {}
      this.loading = false;
    },
    resetFilters() {
      this.filters = { when: 'upcoming' };
      if (this.$refs.table) this.$refs.table.clearSearch();
    },
  },
};
</script>
