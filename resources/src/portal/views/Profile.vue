<template>
  <div>
    <div v-if="profile" class="row row-cards">
      <!-- Account info -->
      <div class="col-lg-6">
        <div class="card">
          <div class="card-header">
            <div class="d-flex align-items-center gap-3">
              <span class="avatar avatar-lg rounded-circle rst-avatar-accent">{{ initials }}</span>
              <div>
                <h3 class="card-title mb-0">{{ profile.client && profile.client.name }}</h3>
                <div class="text-secondary">{{ profile.portal_email }}</div>
              </div>
            </div>
          </div>
          <div class="card-body">
            <div class="datagrid">
              <div class="datagrid-item"><div class="datagrid-title">{{ $t('portal_email') }}</div><div class="datagrid-content pc-wrap">{{ profile.portal_email }}</div></div>
              <div class="datagrid-item"><div class="datagrid-title">{{ $t('client') }}</div><div class="datagrid-content">{{ profile.client && profile.client.name }}</div></div>
              <div class="datagrid-item"><div class="datagrid-title">{{ $t('email') }}</div><div class="datagrid-content pc-wrap">{{ (profile.client && profile.client.email) || '—' }}</div></div>
              <div class="datagrid-item"><div class="datagrid-title">{{ $t('phone') }}</div><div class="datagrid-content">{{ (profile.client && profile.client.phone) || '—' }}</div></div>
              <div class="datagrid-item"><div class="datagrid-title">{{ $t('address') }}</div><div class="datagrid-content">{{ (profile.client && profile.client.adresse) || '—' }}</div></div>
            </div>
          </div>
        </div>
      </div>

      <!-- Password -->
      <div class="col-lg-6">
        <form class="card" @submit.prevent="changePassword">
          <div class="card-header">
            <div>
              <h3 class="card-title mb-0">{{ $t('change_password') }}</h3>
              <div class="text-secondary">{{ $t('use_8_chars') }}</div>
            </div>
          </div>
          <div class="card-body">
            <div class="mb-3">
              <label class="form-label">{{ $t('current_password') }}</label>
              <input v-model="currentPassword" type="password" class="form-control" required placeholder="••••••••" autocomplete="current-password" />
            </div>
            <div class="mb-3">
              <label class="form-label">{{ $t('new_password') }}</label>
              <input v-model="newPassword" type="password" class="form-control" required minlength="8" :placeholder="$t('min_8_chars')" autocomplete="new-password" />
            </div>
            <div class="mb-3">
              <label class="form-label">{{ $t('confirm_new_password') }}</label>
              <input v-model="confirmPassword" type="password" class="form-control" required placeholder="••••••••" autocomplete="new-password" />
            </div>
            <div v-if="pwError" class="alert alert-danger mb-3">{{ pwError }}</div>
            <div v-if="pwSuccess" class="alert alert-success mb-3">{{ $t('password_updated') }}</div>
          </div>
          <div class="card-footer text-end">
            <button type="submit" class="btn btn-primary" :disabled="pwLoading">
              <span v-if="pwLoading" class="spinner-border spinner-border-sm me-2" role="status"></span>
              <i v-else class="ti ti-lock me-1"></i>
              {{ pwLoading ? $t('updating') : $t('update_password') }}
            </button>
          </div>
        </form>
      </div>

      <!-- Two-step verification (PortalTwoFactorController); shown when Portal Settings allows it or it is on -->
      <div v-if="tf && tf.migrated && (tf.allowed || tf.enabled)" class="col-12">
        <div class="card">
          <div class="card-header">
            <div>
              <h3 class="card-title mb-0">
                <i class="ti ti-shield-lock me-1"></i>{{ $t('tf_title') }}
                <span v-if="tf.enabled" class="badge bg-green-lt ms-2">{{ $t('tf_on') }}</span>
              </h3>
              <div class="text-secondary">{{ $t('tf_subtitle') }}</div>
            </div>
          </div>
          <div class="card-body">
            <div v-if="tfError" class="alert alert-danger">{{ tfError }}</div>
            <div v-if="tfInfo" class="alert alert-success">{{ tfInfo }}</div>

            <!-- Recovery codes: shown once -->
            <div v-if="tfCodes.length" class="alert alert-warning">
              <div class="fw-bold mb-1">{{ $t('tf_codes_title') }}</div>
              <div class="mb-2">{{ $t('tf_codes_help') }}</div>
              <div class="row g-2 font-monospace text-center mb-2">
                <div v-for="c in tfCodes" :key="c" class="col-6">{{ c }}</div>
              </div>
              <button type="button" class="btn btn-sm" @click="copyCodes"><i class="ti ti-copy me-1"></i>{{ $t('tf_copy') }}</button>
            </div>

            <!-- On -->
            <template v-if="tf.enabled">
              <p class="text-secondary">
                {{ tf.method === 'email' ? $t('tf_on_email_help') : $t('tf_on_app_help') }}
                <template v-if="tf.method === 'app'"><br>{{ $t('tf_codes_left').replace(':count', tf.recovery_codes_left) }}</template>
              </p>
              <div v-if="!tfAction" class="d-flex gap-2 flex-wrap">
                <button v-if="tf.method === 'app'" type="button" class="btn" @click="tfAction = 'codes'">{{ $t('tf_new_codes') }}</button>
                <button type="button" class="btn btn-outline-danger" @click="tfAction = 'disable'">{{ $t('tf_turn_off') }}</button>
              </div>
              <form v-else class="pc-tf-form" @submit.prevent="tfAction === 'codes' ? regenerateCodes() : disableTf()">
                <label class="form-label">{{ $t('tf_confirm_password') }}</label>
                <input v-model="tfPassword" type="password" class="form-control mb-3" required autocomplete="current-password" placeholder="••••••••" />
                <div class="d-flex gap-2">
                  <button type="submit" class="btn" :class="tfAction === 'disable' ? 'btn-danger' : 'btn-primary'" :disabled="tfBusy || !tfPassword">
                    <span v-if="tfBusy" class="spinner-border spinner-border-sm me-2" role="status"></span>
                    {{ tfAction === 'disable' ? $t('tf_turn_off') : $t('tf_new_codes') }}
                  </button>
                  <button type="button" class="btn btn-ghost-secondary" @click="tfAction = null; tfPassword = ''">{{ $t('cancel') }}</button>
                </div>
              </form>
            </template>

            <!-- Setup in progress -->
            <template v-else-if="tfSetup">
              <template v-if="tfSetup.method === 'app'">
                <ol class="text-secondary ps-3">
                  <li>{{ $t('tf_step_install') }}</li>
                  <li>{{ $t('tf_step_scan') }}</li>
                  <li>{{ $t('tf_step_confirm') }}</li>
                </ol>
                <div class="d-flex flex-wrap align-items-center gap-3 mb-3">
                  <div class="pc-tf-qr"><div ref="qrEl"></div></div>
                  <div>
                    <div class="text-secondary small">{{ $t('tf_manual_key') }}</div>
                    <code class="pc-wrap">{{ formattedSecret }}</code>
                  </div>
                </div>
              </template>
              <p v-else class="text-secondary">{{ $t('tf_email_prompt').replace(':email', tf.email) }}</p>

              <form class="pc-tf-form" @submit.prevent="confirmTf">
                <label class="form-label">{{ $t('tf_code_label') }}</label>
                <input v-model="tfCode" type="text" class="form-control text-center mb-3" inputmode="numeric" autocomplete="one-time-code" maxlength="6" placeholder="000000" style="letter-spacing: .3em" />
                <div class="d-flex gap-2 flex-wrap align-items-center">
                  <button type="submit" class="btn btn-primary" :disabled="tfBusy || tfCode.trim().length !== 6">
                    <span v-if="tfBusy" class="spinner-border spinner-border-sm me-2" role="status"></span>{{ $t('tf_turn_on') }}
                  </button>
                  <button type="button" class="btn btn-ghost-secondary" @click="cancelSetup">{{ $t('cancel') }}</button>
                  <a v-if="tfSetup.method === 'email'" href="#" class="ms-auto" @click.prevent="resendSetup">{{ $t('tf_resend') }}</a>
                </div>
              </form>
            </template>

            <!-- Off -->
            <form v-else class="pc-tf-form" @submit.prevent="startSetup">
              <p class="text-secondary">{{ $t('tf_off_help') }}</p>
              <label class="form-selectgroup-item flex-fill mb-2 d-block">
                <div class="form-check">
                  <input v-model="tfMethod" class="form-check-input" type="radio" value="email" />
                  <span class="form-check-label"><strong>{{ $t('tf_method_email') }}</strong><br><span class="text-secondary">{{ $t('tf_method_email_help') }}</span></span>
                </div>
              </label>
              <label class="d-block mb-3">
                <div class="form-check">
                  <input v-model="tfMethod" class="form-check-input" type="radio" value="app" />
                  <span class="form-check-label"><strong>{{ $t('tf_method_app') }}</strong><br><span class="text-secondary">{{ $t('tf_method_app_help') }}</span></span>
                </div>
              </label>
              <label class="form-label">{{ $t('tf_confirm_password') }}</label>
              <input v-model="tfPassword" type="password" class="form-control mb-3" required autocomplete="current-password" placeholder="••••••••" />
              <button type="submit" class="btn btn-primary" :disabled="tfBusy || !tfPassword">
                <span v-if="tfBusy" class="spinner-border spinner-border-sm me-2" role="status"></span>
                <i v-else class="ti ti-shield-check me-1"></i>{{ $t('tf_continue') }}
              </button>
            </form>
          </div>
        </div>
      </div>

      <!-- Danger zone -->
      <div class="col-12">
        <div class="card border-danger">
          <div class="card-header">
            <div>
              <h3 class="card-title mb-0 text-danger">{{ $t('delete_account') }}</h3>
              <div class="text-secondary">{{ $t('delete_account_subtitle') }}</div>
            </div>
          </div>
          <div class="card-body">
            <p class="text-secondary">{{ $t('delete_copy_1') }}</p>
            <p class="text-secondary">{{ $t('delete_copy_2') }}</p>
            <button type="button" class="btn btn-danger" @click="openDelete"><i class="ti ti-user-off me-1"></i>{{ $t('delete_account') }}</button>
          </div>
        </div>
      </div>
    </div>

    <div v-else class="text-center py-6">
      <div class="spinner-border text-primary" role="status"></div>
      <div class="text-secondary mt-2">{{ $t('loading_profile') }}</div>
    </div>

    <!-- Confirmation dialog (Bootstrap markup, Vue-driven) -->
    <template v-if="delOpen">
      <div class="modal modal-blur fade show d-block" tabindex="-1" role="dialog" aria-modal="true" @click.self="closeDelete">
        <div class="modal-dialog modal-dialog-centered" role="document">
          <form class="modal-content" @submit.prevent="confirmDelete">
            <div class="modal-header">
              <h5 class="modal-title text-danger">{{ $t('delete_account_q') }}</h5>
              <button type="button" class="btn-close" :aria-label="$t('cancel')" @click="closeDelete"></button>
            </div>
            <div class="modal-body">
              <p class="text-secondary">{{ $t('delete_warning') }}</p>
              <div class="mb-3">
                <label class="form-label">{{ $t('type_email_confirm') }}</label>
                <input v-model="delEmail" type="text" class="form-control" autocomplete="off" :placeholder="profile && profile.portal_email" />
              </div>
              <div class="mb-3">
                <label class="form-label">{{ $t('password') }}</label>
                <input v-model="delPassword" type="password" class="form-control" autocomplete="current-password" placeholder="••••••••" />
              </div>
              <div class="mb-2">
                <label class="form-label">{{ $t('reason') }} <span class="form-label-description">{{ $t('optional') }}</span></label>
                <textarea v-model="delReason" class="form-control" rows="2" maxlength="500" :placeholder="$t('reason_placeholder')"></textarea>
              </div>
              <div v-if="delError" class="alert alert-danger mb-0">{{ delError }}</div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-ghost-secondary" @click="closeDelete">{{ $t('cancel') }}</button>
              <button type="submit" class="btn btn-danger" :disabled="delLoading || !canDelete">
                <span v-if="delLoading" class="spinner-border spinner-border-sm me-2" role="status"></span>
                {{ delLoading ? $t('deleting') : $t('delete_my_account') }}
              </button>
            </div>
          </form>
        </div>
      </div>
      <div class="modal-backdrop fade show"></div>
    </template>
  </div>
</template>

<script>
import http from '../lib/http';
import { apiError, initials } from '../lib/ui';
import { renderQr } from '../../lib/qrcode';

export default {
  data() {
    return {
      profile: null,
      currentPassword: '', newPassword: '', confirmPassword: '',
      pwError: '', pwSuccess: false, pwLoading: false,
      delOpen: false, delEmail: '', delPassword: '', delReason: '', delError: '', delLoading: false,
      // Two-step verification
      tf: null, tfSetup: null, tfMethod: 'email', tfPassword: '', tfCode: '', tfCodes: [],
      tfAction: null, tfBusy: false, tfError: '', tfInfo: '',
    };
  },
  computed: {
    canDelete() { return !!this.delEmail.trim() && !!this.delPassword; },
    initials() { return initials(this.profile && this.profile.client && this.profile.client.name); },
    formattedSecret() { return ((this.tfSetup && this.tfSetup.secret) || '').match(/.{1,4}/g)?.join(' ') || ''; },
  },
  watch: {
    delOpen(v) { document.body.classList.toggle('pc-modal-open', v); },
  },
  mounted() { this.fetch(); this.fetchTf(); },
  beforeUnmount() { document.body.classList.remove('pc-modal-open'); },
  methods: {
    pageMeta() { return { title: this.$t('profile'), pretitle: this.$t('account'), crumbs: [] }; },
    async fetch() {
      try {
        const { data } = await http.get('/portal/profile');
        this.profile = data;
      } catch (_) {}
    },
    async fetchTf() {
      try {
        const { data } = await http.get('/portal/profile/two-factor');
        this.tf = data;
      } catch (_) {}
    },
    tfReset(keepMessages = false) {
      this.tfPassword = '';
      this.tfCode = '';
      this.tfAction = null;
      if (!keepMessages) { this.tfError = ''; this.tfInfo = ''; }
    },
    async startSetup() {
      this.tfError = ''; this.tfInfo = ''; this.tfCodes = [];
      this.tfBusy = true;
      try {
        const { data } = await http.post('/portal/profile/two-factor/setup', { method: this.tfMethod, password: this.tfPassword });
        this.tfSetup = data;
        this.tfReset(true);
        if (data.method === 'email') {
          this.tfInfo = this.$t('tf_code_sent');
        } else {
          await this.$nextTick();
          await renderQr(this.$refs.qrEl, data.uri, 160);
        }
      } catch (e) {
        this.tfError = apiError(e, this.$t('tf_failed'));
      } finally {
        this.tfBusy = false;
      }
    },
    async confirmTf() {
      this.tfError = ''; this.tfInfo = '';
      this.tfBusy = true;
      try {
        const { data } = await http.post('/portal/profile/two-factor/confirm', { code: this.tfCode.trim() });
        this.tfCodes = data.recovery_codes || [];
        this.tf = { ...this.tf, ...data };
        this.tfSetup = null;
        this.tfReset(true);
        this.tfInfo = this.$t('tf_turned_on');
      } catch (e) {
        this.tfError = apiError(e, this.$t('tf_failed'));
      } finally {
        this.tfBusy = false;
      }
    },
    async resendSetup() {
      this.tfError = ''; this.tfInfo = '';
      try {
        await http.post('/portal/profile/two-factor/resend');
        this.tfInfo = this.$t('tf_code_sent');
      } catch (e) {
        this.tfError = apiError(e, this.$t('tf_failed'));
      }
    },
    cancelSetup() {
      this.tfSetup = null;
      this.tfReset();
    },
    async regenerateCodes() {
      this.tfError = ''; this.tfInfo = '';
      this.tfBusy = true;
      try {
        const { data } = await http.post('/portal/profile/two-factor/recovery-codes', { password: this.tfPassword });
        this.tfCodes = data.recovery_codes || [];
        this.tf = { ...this.tf, ...data };
        this.tfReset(true);
      } catch (e) {
        this.tfError = apiError(e, this.$t('tf_failed'));
      } finally {
        this.tfBusy = false;
      }
    },
    async disableTf() {
      this.tfError = ''; this.tfInfo = '';
      this.tfBusy = true;
      try {
        const { data } = await http.post('/portal/profile/two-factor/disable', { password: this.tfPassword });
        this.tf = { ...this.tf, ...data };
        this.tfCodes = [];
        this.tfReset(true);
        this.tfInfo = this.$t('tf_turned_off');
      } catch (e) {
        this.tfError = apiError(e, this.$t('tf_failed'));
      } finally {
        this.tfBusy = false;
      }
    },
    copyCodes() {
      const text = this.tfCodes.join('\n');
      if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(text).then(() => { this.tfInfo = this.$t('tf_copied'); });
    },
    openDelete() { this.delOpen = true; this.delError = ''; this.delEmail = ''; this.delPassword = ''; this.delReason = ''; },
    closeDelete() { if (!this.delLoading) this.delOpen = false; },
    async confirmDelete() {
      if (!this.canDelete) return;
      this.delError = '';
      this.delLoading = true;
      try {
        await http.delete('/portal/profile', { data: { current_password: this.delPassword, confirm_email: this.delEmail.trim(), reason: this.delReason || null } });
        window.location.href = '/portal/login';
      } catch (e) {
        this.delError = apiError(e, this.$t('delete_failed'));
        this.delLoading = false;
      }
    },
    async changePassword() {
      if (this.newPassword !== this.confirmPassword) { this.pwError = this.$t('passwords_no_match'); return; }
      this.pwError = '';
      this.pwSuccess = false;
      this.pwLoading = true;
      try {
        await http.put('/portal/profile/password', { current_password: this.currentPassword, password: this.newPassword, password_confirmation: this.confirmPassword });
        this.pwSuccess = true;
        this.currentPassword = this.newPassword = this.confirmPassword = '';
      } catch (e) {
        this.pwError = apiError(e, this.$t('update_password_failed'));
      }
      this.pwLoading = false;
    },
  },
};
</script>

<style scoped>
.pc-tf-form { max-width: 460px; }
.pc-tf-qr { width: 176px; height: 176px; padding: 8px; background: #fff; border-radius: 8px; display: flex; align-items: center; justify-content: center; }
.pc-tf-qr :deep(img), .pc-tf-qr :deep(canvas) { width: 160px !important; height: 160px !important; }
</style>
