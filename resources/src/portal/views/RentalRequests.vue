<template>
  <div>
    <PageActions>
      <button v-if="!showForm && options.length" type="button" class="btn btn-primary" @click="openForm()">
        <i class="ti ti-plus me-1"></i>{{ tr('rental_report_problem', 'Report a problem') }}
      </button>
    </PageActions>

    <div v-if="done" class="alert alert-success d-flex align-items-center gap-2 mb-3">
      <i class="ti ti-circle-check fs-2"></i>
      <div>{{ tr('rental_request_sent', 'Thank you. Your request {number} was sent; we will be in touch.', { number: done }) }}</div>
    </div>

    <form v-if="showForm" class="card mb-3" @submit.prevent="submit">
      <div class="card-header">
        <div>
          <h3 class="card-title mb-0">{{ tr('rental_report_problem', 'Report a problem') }}</h3>
          <p class="card-subtitle mb-0">{{ tr('rental_report_intro', 'Tell us what is wrong with a rented item and we will arrange a repair.') }}</p>
        </div>
      </div>
      <div class="card-body">
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label required">{{ tr('rental_contract', 'Rental contract') }}</label>
            <select v-model="form.rental_contract_id" class="form-select" required @change="form.rental_item_id = itemsOf(form.rental_contract_id)[0]?.id || ''">
              <option v-for="o in options" :key="o.id" :value="o.id">{{ o.contract_number }}</option>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label required">{{ tr('rental_item', 'Item') }}</label>
            <select v-model="form.rental_item_id" class="form-select" required>
              <option v-for="it in itemsOf(form.rental_contract_id)" :key="it.id" :value="it.id">{{ it.name }}</option>
            </select>
          </div>
          <div class="col-12">
            <label class="form-label required">{{ tr('rental_problem', 'What is the problem?') }}</label>
            <input v-model="form.title" type="text" class="form-control" maxlength="191" required :placeholder="tr('rental_problem_ph', 'e.g. The air conditioner does not cool')" />
          </div>
          <div class="col-12">
            <label class="form-label">{{ $t('description') }} <span class="form-label-description">{{ $t('optional') }}</span></label>
            <textarea v-model="form.description" class="form-control" rows="4" maxlength="3000" :placeholder="tr('rental_details_ph', 'When did it start, what have you noticed…')"></textarea>
          </div>
          <div class="col-md-8">
            <label class="form-label">{{ tr('rental_photos', 'Photos') }} <span class="form-label-description">{{ tr('rental_photos_hint', 'Up to 6') }}</span></label>
            <input ref="photos" type="file" class="form-control" accept="image/*" multiple @change="onPhotos" />
            <div v-if="previews.length" class="d-flex flex-wrap gap-2 mt-2">
              <span v-for="(p, i) in previews" :key="i" class="avatar avatar-lg rounded" :style="{ backgroundImage: `url(${p})` }"></span>
            </div>
          </div>
          <div class="col-md-4 d-flex align-items-end">
            <label class="form-check form-switch mb-2">
              <input v-model="form.urgent" type="checkbox" class="form-check-input" />
              <span class="form-check-label">{{ tr('rental_urgent', 'Urgent (safety risk or item unusable)') }}</span>
            </label>
          </div>
        </div>
        <div v-if="error" class="alert alert-danger mb-0 mt-3">{{ error }}</div>
      </div>
      <div class="card-footer d-flex justify-content-end gap-2">
        <button type="button" class="btn btn-ghost-secondary" @click="showForm = false">{{ $t('cancel') }}</button>
        <button type="submit" class="btn btn-primary" :disabled="saving">
          <span v-if="saving" class="spinner-border spinner-border-sm me-2" role="status"></span>
          <i v-else class="ti ti-send me-1"></i>{{ saving ? $t('submitting') : tr('rental_send_request', 'Send request') }}
        </button>
      </div>
    </form>

    <div class="card">
      <div class="card-header"><h3 class="card-title">{{ tr('rental_requests', 'Maintenance requests') }}</h3></div>
      <div v-if="loading" class="card-body text-center py-5"><div class="spinner-border text-primary" role="status"></div></div>
      <div v-else-if="!requests.length" class="card-body">
        <EmptyState icon="tool" :title="tr('rental_no_requests', 'No requests.')" :subtitle="options.length ? tr('rental_requests_hint', 'If something you rent is broken, report it here.') : tr('rental_requests_none_active', 'You can report problems while a rental is active.')" />
      </div>
      <div v-else class="table-responsive">
        <table class="table table-vcenter card-table">
          <thead><tr><th>{{ $t('number') }}</th><th>{{ tr('rental_problem_short', 'Problem') }}</th><th>{{ tr('rental_item', 'Item') }}</th><th>{{ tr('rental_reported', 'Reported') }}</th><th>{{ $t('status') }}</th></tr></thead>
          <tbody>
            <tr v-for="r in requests" :key="r.id">
              <td class="font-monospace">{{ r.number }}</td>
              <td>{{ r.title }} <span v-if="r.priority === 'urgent'" class="badge bg-red-lt ms-1">{{ tr('rental_urgent_short', 'Urgent') }}</span></td>
              <td class="text-secondary">{{ r.item_name || '—' }}</td>
              <td class="text-secondary">{{ r.created_at }}</td>
              <td>
                <span :class="badge(reqTone(r.status))">{{ reqLabel(r.status) }}</span>
                <div v-if="r.completed_at" class="text-secondary small">{{ r.completed_at }}</div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>

<script>
import http from '../lib/http';
import { badge, apiError } from '../lib/ui';
import { requestTone, requestLabel } from '../lib/rental';
import PageActions from '../components/PageActions.vue';
import EmptyState from '../components/EmptyState.vue';

export default {
  components: { PageActions, EmptyState },
  data() {
    return {
      requests: [], options: [], loading: true,
      showForm: false, saving: false, error: '', done: '',
      form: this.blank(), files: [], previews: [],
    };
  },
  async mounted() {
    await this.fetch();
    if (this.$route.query.new && this.options.length) this.openForm(Number(this.$route.query.contract) || null);
  },
  beforeUnmount() { this.previews.forEach((u) => URL.revokeObjectURL(u)); },
  methods: {
    badge,
    reqTone: requestTone,
    reqLabel(s) { return requestLabel(this, s); },
    pageMeta() { return { title: this.tr('rental_requests', 'Maintenance requests'), pretitle: this.tr('nav_rentals', 'Rentals'), crumbs: [] }; },
    blank() { return { rental_contract_id: '', rental_item_id: '', title: '', description: '', urgent: false }; },
    itemsOf(contractId) { return (this.options.find((o) => o.id === contractId) || {}).items || []; },
    async fetch() {
      this.loading = true;
      try {
        const { data } = await http.get('/portal/rentals/requests');
        this.requests = data.requests || [];
        this.options = data.options || [];
      } catch (_) {}
      this.loading = false;
    },
    openForm(contractId = null) {
      const first = this.options.find((o) => o.id === contractId) || this.options[0];
      this.form = { ...this.blank(), rental_contract_id: first ? first.id : '', rental_item_id: first && first.items[0] ? first.items[0].id : '' };
      this.clearPhotos();
      this.error = '';
      this.done = '';
      this.showForm = true;
    },
    onPhotos(e) {
      this.clearPhotos();
      this.files = Array.from(e.target.files || []).slice(0, 6);
      this.previews = this.files.map((f) => URL.createObjectURL(f));
    },
    clearPhotos() {
      this.previews.forEach((u) => URL.revokeObjectURL(u));
      this.files = [];
      this.previews = [];
      if (this.$refs.photos) this.$refs.photos.value = '';
    },
    async submit() {
      if (!this.form.title.trim()) { this.error = this.tr('rental_problem_required', 'Please describe the problem.'); return; }
      this.error = '';
      this.saving = true;
      const fd = new FormData();
      fd.append('rental_contract_id', this.form.rental_contract_id);
      fd.append('rental_item_id', this.form.rental_item_id);
      fd.append('title', this.form.title.trim());
      if (this.form.description) fd.append('description', this.form.description);
      fd.append('urgent', this.form.urgent ? '1' : '0');
      this.files.forEach((f) => fd.append('photos[]', f));
      try {
        const { data } = await http.post('/portal/rentals/requests', fd);
        this.done = data.number || '';
        this.showForm = false;
        this.clearPhotos();
        await this.fetch();
      } catch (e) {
        this.error = apiError(e, this.tr('rental_request_failed', 'The request could not be sent. Please try again.'));
      }
      this.saving = false;
    },
  },
};
</script>
