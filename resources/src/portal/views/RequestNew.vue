<template>
  <div class="portal-page portal-request-new">
    <header class="pc-page-header">
      <div>
        <h1 class="pc-page-title">{{ $t('new_request') }}</h1>
        <p class="pc-page-sub">{{ $t('new_request_subtitle') }}</p>
      </div>
      <router-link to="/requests" class="pc-link-back">{{ $t('back_to_requests') }}</router-link>
    </header>

    <form class="pc-card pc-form" @submit.prevent="submit">
      <div class="pc-form-row">
        <label>{{ $t('rq_type') }} <span class="pc-required">*</span></label>
        <select v-model="form.type" class="rq-select" required>
          <option v-for="t in TYPES" :key="t" :value="t">{{ $t('rq_type_' + t) }}</option>
        </select>
      </div>
      <div class="pc-form-row">
        <label>{{ $t('subject') }} <span class="pc-required">*</span></label>
        <input v-model="form.subject" type="text" maxlength="255" required />
      </div>
      <div class="pc-form-row">
        <label>{{ $t('details') }} <span class="pc-required">*</span></label>
        <textarea v-model="form.body" rows="6" maxlength="10000" required></textarea>
      </div>
      <div class="pc-form-row">
        <label>{{ $t('attachments') }} <span class="pc-muted">{{ $t('optional') }}</span></label>
        <input type="file" multiple @change="onFiles" />
        <ul v-if="files.length" class="rq-files">
          <li v-for="(f, i) in files" :key="i">{{ f.name }} <button type="button" class="pc-icon-btn" @click="files.splice(i, 1)">×</button></li>
        </ul>
        <small class="pc-muted">{{ $t('rq_attachments_hint') }}</small>
      </div>

      <div v-if="error" class="pc-alert pc-alert-error">{{ error }}</div>

      <div class="pc-form-actions">
        <router-link to="/requests" class="pc-btn pc-btn-ghost">{{ $t('cancel') }}</router-link>
        <button type="submit" class="pc-btn pc-btn-primary" :disabled="submitting">
          <span v-if="submitting" class="pc-btn-spinner"></span>{{ $t('submit_request') }}
        </button>
      </div>
    </form>
  </div>
</template>

<script>
/**
 * Customer portal — submit a protocol request (multipart with attachments[]).
 * POST /api/portal/requests → {id, Ref}.
 */
import http from '../lib/http';

const TYPES = ['complaint', 'request', 'memo', 'letter', 'document', 'enquiry', 'other'];

export default {
  data() {
    return { form: { type: 'request', subject: '', body: '' }, files: [], submitting: false, error: '', TYPES };
  },
  methods: {
    onFiles(e) {
      const picked = Array.from(e.target.files || []);
      this.files = this.files.concat(picked).slice(0, 5);
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
        this.files.forEach(f => fd.append('attachments[]', f));
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

<style scoped>
.portal-request-new { padding-bottom: 1rem; }
.pc-page-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 1.25rem; gap: 1rem; flex-wrap: wrap; }
.pc-page-title { font-size: 1.5rem; font-weight: 700; color: var(--pc-text); margin: 0 0 0.2rem; }
.pc-page-sub { font-size: 0.9rem; color: var(--pc-text-muted); margin: 0; }
.pc-link-back { color: var(--pc-text-muted); text-decoration: none; font-size: 0.88rem; }
.pc-link-back:hover { color: var(--pc-primary); }
.pc-card { background: var(--pc-surface); border: 1px solid var(--pc-border); border-radius: var(--pc-radius); box-shadow: var(--pc-shadow-sm); }
.pc-form { padding: 1.5rem; display: flex; flex-direction: column; gap: 1.15rem; }
.pc-form-row { display: flex; flex-direction: column; gap: 0.45rem; }
.pc-form-row label { font-size: 0.85rem; font-weight: 600; color: var(--pc-text); }
.pc-muted { color: var(--pc-text-soft); font-weight: 400; font-size: 0.78rem; }
.pc-required { color: var(--pc-danger); }
.pc-form-row input, .pc-form-row textarea { width: 100%; padding: 0.6rem 0.85rem; border: 1px solid var(--pc-border-strong); border-radius: 10px; font-size: 0.92rem; background: var(--pc-surface-alt); box-sizing: border-box; font-family: inherit; }
.pc-form-row input:focus, .pc-form-row textarea:focus { outline: none; background: var(--pc-surface); border-color: var(--pc-primary); box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.15); }
.pc-icon-btn { background: transparent; border: 1px solid var(--pc-border-strong); width: 26px; height: 26px; border-radius: 8px; cursor: pointer; color: var(--pc-text-soft); display: inline-flex; align-items: center; justify-content: center; }
.pc-icon-btn:hover { border-color: var(--pc-danger); color: var(--pc-danger); }
.pc-btn { display: inline-flex; align-items: center; gap: 0.4rem; padding: 0.55rem 1rem; border-radius: 10px; font-size: 0.9rem; font-weight: 600; text-decoration: none; border: 1px solid transparent; cursor: pointer; }
.pc-btn-primary { background: var(--pc-primary); color: #fff; }
.pc-btn-primary:hover:not(:disabled) { background: var(--pc-primary-600); }
.pc-btn-primary:disabled { opacity: 0.6; cursor: not-allowed; }
.pc-btn-ghost { background: var(--pc-surface); border-color: var(--pc-border-strong); color: var(--pc-text); }
.pc-btn-ghost:hover { border-color: var(--pc-primary); color: var(--pc-primary); }
.pc-btn-spinner { display: inline-block; width: 0.95rem; height: 0.95rem; border: 2px solid rgba(255, 255, 255, 0.35); border-top-color: #fff; border-radius: 50%; animation: pc-spin 0.7s linear infinite; }
@keyframes pc-spin { to { transform: rotate(360deg); } }
.pc-form-actions { display: flex; justify-content: flex-end; gap: 0.6rem; margin-top: 0.5rem; }
.pc-alert { padding: 0.7rem 0.95rem; border-radius: 10px; font-size: 0.88rem; }
.pc-alert-error { background: var(--pc-danger-bg); color: #b91c1c; border: 1px solid #fecaca; }
.rq-select {
  width: 100%;
  height: 40px;
  padding: 0 10px;
  border: 1px solid var(--pc-border-strong);
  border-radius: 10px;
  background: var(--pc-surface-alt);
  font: inherit;
  color: inherit;
}
.rq-select:focus { outline: none; background: var(--pc-surface); border-color: var(--pc-primary); box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.15); }
.rq-files { list-style: none; padding: 0; margin: 8px 0 0; font-size: 13px; }
.rq-files li { display: flex; align-items: center; gap: 8px; padding: 2px 0; }
</style>
