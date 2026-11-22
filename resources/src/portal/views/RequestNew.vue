<template>
  <!-- Protocol & Requests (SaaS only): send a complaint / request / enquiry with attachments. -->
  <div>
    <PageActions>
      <router-link to="/requests" class="btn btn-outline-secondary"><i class="ti ti-arrow-left me-1"></i>{{ $t('back_to_requests') }}</router-link>
    </PageActions>

    <form class="card" @submit.prevent="submit">
      <div class="card-header">
        <div>
          <h3 class="card-title mb-0">{{ $t('new_request') }}</h3>
          <p class="card-subtitle mb-0">{{ $t('new_request_subtitle') }}</p>
        </div>
      </div>
      <div class="card-body">
        <div class="row g-3">
          <div class="col-md-4">
            <label class="form-label required">{{ $t('rq_type') }}</label>
            <select v-model="form.type" class="form-select" required>
              <option v-for="t in types" :key="t" :value="t">{{ tr(`rq_type_${t}`, t) }}</option>
            </select>
          </div>
          <div class="col-md-8">
            <label class="form-label required">{{ $t('subject') }}</label>
            <input v-model="form.subject" type="text" class="form-control" maxlength="255" required />
          </div>
          <div class="col-12">
            <label class="form-label required">{{ $t('details') }}</label>
            <textarea v-model="form.body" class="form-control" rows="6" maxlength="10000" required></textarea>
          </div>
          <div class="col-12">
            <label class="form-label">{{ $t('attachments') }} <span class="form-label-description">{{ $t('optional') }}</span></label>
            <input ref="file" type="file" class="form-control" multiple @change="onFiles" />
            <div class="form-hint">{{ $t('rq_attachments_hint') }}</div>
            <div v-if="files.length" class="d-flex flex-wrap gap-2 mt-2">
              <span v-for="(f, i) in files" :key="i" class="badge bg-secondary-lt d-inline-flex align-items-center gap-1 py-2">
                <i class="ti ti-paperclip"></i>{{ f.name }}
                <button type="button" class="btn-close btn-close-sm ms-1" :aria-label="$t('remove')" @click="files.splice(i, 1)"></button>
              </span>
            </div>
          </div>
        </div>
        <div v-if="error" class="alert alert-danger mb-0 mt-3">{{ error }}</div>
      </div>
      <div class="card-footer d-flex justify-content-end gap-2">
        <router-link to="/requests" class="btn btn-ghost-secondary">{{ $t('cancel') }}</router-link>
        <button type="submit" class="btn btn-primary" :disabled="submitting">
          <span v-if="submitting" class="spinner-border spinner-border-sm me-2" role="status"></span>
          <i v-else class="ti ti-send me-1"></i>
          {{ submitting ? $t('submitting') : $t('submit_request') }}
        </button>
      </div>
    </form>
  </div>
</template>

<script>
/**
 * Submit a protocol request — POST /api/portal/requests (multipart:
 * type, subject, body, attachments[]) → {id, Ref}.
 */
import http from '../lib/http';
import PageActions from '../components/PageActions.vue';

const TYPES = ['complaint', 'request', 'memo', 'letter', 'document', 'enquiry', 'other'];
const MAX_FILES = 5;

export default {
  components: { PageActions },
  data() {
    return { form: { type: 'request', subject: '', body: '' }, files: [], types: TYPES, submitting: false, error: '' };
  },
  methods: {
    pageMeta() { return { title: this.$t('new_request'), pretitle: this.$t('nav_requests'), crumbs: [{ label: this.$t('nav_requests'), to: '/requests' }] }; },
    onFiles(e) {
      this.files = this.files.concat(Array.from(e.target.files || [])).slice(0, MAX_FILES);
      e.target.value = '';
    },
    async submit() {
      this.error = '';
      this.submitting = true;
      try {
        const fd = new FormData();
        fd.append('type', this.form.type);
        fd.append('subject', this.form.subject);
        fd.append('body', this.form.body);
        this.files.forEach((f) => fd.append('attachments[]', f));
        const { data } = await http.post('/portal/requests', fd);
        this.$router.push(`/requests/${data.id}`);
      } catch (e) {
        const d = e && e.response && e.response.data;
        this.error = (d && (d.message || (d.errors && Object.values(d.errors).flat().join(' ')))) || this.$t('request_submit_failed');
      }
      this.submitting = false;
    },
  },
};
</script>
