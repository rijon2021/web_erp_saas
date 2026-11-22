<template>
  <div>
    <div v-if="outstanding > 0" class="alert alert-info d-flex align-items-center gap-2 mb-3">
      <i class="ti ti-info-circle fs-2"></i>
      <div>{{ tr('rental_owed_note', 'You owe {amount} on your rentals. Please contact us to pay.', { amount: money(outstanding) }) }}</div>
    </div>

    <DataTable
      ref="table"
      :title="tr('rental_invoices', 'Rental invoices')"
      :columns="columns"
      :rows="invoices"
      :total-rows="totalRows"
      :loading="loading"
      default-sort="due_date"
      default-dir="desc"
      :search-placeholder="tr('rental_search_invoices', 'Search by invoice number…')"
      :empty-text="tr('rental_no_invoices', 'No invoices yet.')"
      empty-icon="file-invoice"
      :filters="filters"
      @query="fetch"
      @reset="resetFilters"
    >
      <template #filters>
        <select v-model="filters.status" class="form-select w-auto" :aria-label="$t('status')">
          <option value="">{{ $t('all_statuses') }}</option>
          <option value="open">{{ tr('rental_to_pay', 'To pay') }}</option>
          <option value="paid">{{ statusLabel('paid') }}</option>
        </select>
      </template>

      <template #cell-invoice_number="{ row }">
        <div class="font-monospace fw-medium">{{ row.invoice_number }}</div>
        <div class="text-secondary small">{{ row.description || periodText(row) }}</div>
      </template>
      <template #cell-contract_number="{ row }">
        <router-link :to="`/rentals/${row.rental_contract_id}`" class="font-monospace">{{ row.contract_number }}</router-link>
      </template>
      <template #cell-amount="{ row }">
        {{ money(row.total) }}
        <div v-if="row.late_fee > 0" class="small text-danger">+{{ money(row.late_fee) }} {{ tr('rental_late_fee_short', 'late fee') }}</div>
      </template>
      <template #cell-balance="{ value }"><span class="fw-medium">{{ money(value) }}</span></template>
      <template #cell-status="{ row }"><span :class="badge(invTone(row))">{{ invLabel(row) }}</span></template>
      <template #cell-actions="{ row }">
        <div class="rst-row-actions justify-content-end">
          <a :href="`/api/portal/rentals/invoices/${row.id}/pdf`" target="_blank" rel="noopener" :title="$t('download')" class="text-secondary"><i class="ti ti-download"></i></a>
        </div>
      </template>
    </DataTable>
  </div>
</template>

<script>
import http from '../lib/http';
import { money, badge } from '../lib/ui';
import { rentalInvoiceTone, rentalInvoiceLabel } from '../lib/rental';
import DataTable from '../components/DataTable.vue';

export default {
  components: { DataTable },
  data() {
    return { invoices: [], totalRows: 0, outstanding: 0, loading: false, filters: { status: this.$route.query.status === 'open' ? 'open' : '' } };
  },
  computed: {
    columns() {
      return [
        { key: 'invoice_number', label: this.$t('number'), sortable: true },
        { key: 'contract_number', label: this.tr('rental_contract', 'Rental contract') },
        { key: 'due_date', label: this.tr('rental_due', 'Due'), sortable: true },
        { key: 'amount', label: this.$t('total'), sortable: true, numeric: true },
        { key: 'balance', label: this.tr('rental_balance', 'Balance'), numeric: true },
        { key: 'status', label: this.$t('status') },
        { key: 'actions', label: '', class: 'w-1 text-end' },
      ];
    },
  },
  methods: {
    money, badge,
    invTone: rentalInvoiceTone,
    invLabel(i) { return rentalInvoiceLabel(this, i); },
    periodText(i) { return i.period_start ? `${i.period_start} → ${i.period_end || ''}` : ''; },
    pageMeta() { return { title: this.tr('rental_invoices', 'Rental invoices'), pretitle: this.tr('nav_rentals', 'Rentals'), crumbs: [] }; },
    async fetch(q) {
      this.loading = true;
      try {
        const { data } = await http.get('/portal/rentals/invoices', { params: { ...q, status: this.filters.status || undefined } });
        this.invoices = data.invoices || [];
        this.totalRows = data.totalRows || 0;
        this.outstanding = Number(data.outstanding) || 0;
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
