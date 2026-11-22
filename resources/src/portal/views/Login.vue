<template>
  <!-- Portal gate: sign-in (password, then the two-step code when the account has it) -->
  <div>
    <div class="position-absolute top-0 p-3 rst-gate-lang"><LanguageMenu /></div>
    <div class="rst-login-screen w-100">
      <div class="container py-4 rst-gate-frame">
        <div class="text-center mb-4"><BrandMark /></div>

        <div class="card rst-login-panel">
          <div v-if="!tf" class="card-body p-4 p-md-5">
            <h2 class="h2 text-center mb-1">{{ $t('welcome_back') }}</h2>
            <p class="text-secondary text-center mb-4">{{ $t('login_subtitle') }}</p>

            <form autocomplete="off" novalidate @submit.prevent="login">
              <div class="mb-3">
                <label class="form-label" for="portal-email">{{ $t('email') }}</label>
                <input id="portal-email" v-model="email" type="email" class="form-control" :class="{ 'is-invalid': !!error }" placeholder="you@company.com" autocomplete="username" required autofocus />
              </div>
              <div class="mb-3">
                <label class="form-label" for="portal-password">{{ $t('password') }}</label>
                <input id="portal-password" v-model="password" type="password" class="form-control" :class="{ 'is-invalid': !!error }" placeholder="••••••••" autocomplete="current-password" required />
                <div v-if="error" class="invalid-feedback d-block">{{ error }}</div>
              </div>
              <div class="form-footer">
                <button type="submit" class="btn btn-primary w-100" :disabled="loading">
                  <span v-if="loading" class="spinner-border spinner-border-sm me-2" role="status"></span>
                  <i v-else class="ti ti-login-2 me-2"></i>
                  {{ loading ? $t('signing_in') : $t('sign_in') }}
                </button>
              </div>
            </form>
          </div>

          <!-- Two-step code (PortalTwoFactorController::verify) -->
          <div v-else class="card-body p-4 p-md-5">
            <div class="text-center mb-3"><i class="ti ti-shield-lock" style="font-size: 2.5rem; color: var(--tblr-primary)"></i></div>
            <h2 class="h2 text-center mb-1">{{ $t('tf_title') }}</h2>
            <p class="text-secondary text-center mb-4">
              <template v-if="tf.method === 'email'">{{ $t('tf_email_prompt').replace(':email', tf.email) }}</template>
              <template v-else-if="recovery">{{ $t('tf_recovery_prompt') }}</template>
              <template v-else>{{ $t('tf_app_prompt') }}</template>
            </p>

            <div v-if="info" class="alert alert-success py-2">{{ info }}</div>

            <form autocomplete="off" novalidate @submit.prevent="verify">
              <div class="mb-3">
                <label class="form-label" for="portal-code">{{ recovery ? $t('tf_recovery_label') : $t('tf_code_label') }}</label>
                <input
                  id="portal-code" ref="codeInput" v-model="code" type="text" class="form-control text-center"
                  :class="{ 'is-invalid': !!error }" :inputmode="recovery ? 'text' : 'numeric'" autocomplete="one-time-code"
                  :placeholder="recovery ? 'xxxxx-xxxxx' : '000000'" maxlength="32" style="font-size: 1.25rem; letter-spacing: .3em"
                />
                <div v-if="error" class="invalid-feedback d-block">{{ error }}</div>
              </div>
              <label class="form-check mb-3">
                <input v-model="trust" class="form-check-input" type="checkbox" />
                <span class="form-check-label">{{ $t('tf_trust_device').replace(':days', tf.trust_days) }}</span>
              </label>
              <div class="form-footer">
                <button type="submit" class="btn btn-primary w-100" :disabled="loading || !code.trim()">
                  <span v-if="loading" class="spinner-border spinner-border-sm me-2" role="status"></span>
                  <i v-else class="ti ti-check me-2"></i>
                  {{ $t('tf_verify') }}
                </button>
              </div>
            </form>

            <div class="d-flex justify-content-between flex-wrap gap-2 mt-3 small">
              <a v-if="tf.method === 'email'" href="#" @click.prevent="resend">{{ $t('tf_resend') }}</a>
              <a v-else href="#" @click.prevent="toggleRecovery">{{ recovery ? $t('tf_use_app') : $t('tf_use_recovery') }}</a>
              <a href="#" class="text-secondary" @click.prevent="back">{{ $t('tf_back') }}</a>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
import http from '../lib/http';
import { apiError } from '../lib/ui';
import LanguageMenu from '../components/LanguageMenu.vue';
import BrandMark from '../components/BrandMark.vue';

export default {
  components: { LanguageMenu, BrandMark },
  data() {
    return {
      email: '', password: '', error: '', loading: false,
      // Two-step state: { method, email, trust_days } once the password passed.
      tf: null, code: '', trust: false, recovery: false, info: '',
    };
  },
  methods: {
    async login() {
      this.error = '';
      this.loading = true;
      try {
        const { data } = await http.post('/portal/login', { email: this.email, password: this.password });
        if (data && data.two_factor) {
          this.tf = { method: data.method, email: data.email, trust_days: data.trust_days };
          this.password = '';
          this.info = data.method === 'email' && !data.email_failed ? this.$t('tf_code_sent') : '';
          // The code email could not be sent: say so, the "resend" link stays available.
          if (data.email_failed) this.error = this.$t('tf_email_failed');
          this.$nextTick(() => this.$refs.codeInput && this.$refs.codeInput.focus());
          return;
        }
        window.location.href = '/portal/dashboard';
      } catch (e) {
        this.error = apiError(e, this.$t('login_failed'));
      } finally {
        this.loading = false;
      }
    },
    async verify() {
      this.error = '';
      this.loading = true;
      try {
        await http.post('/portal/login/two-factor', { code: this.code.trim(), trust_device: this.trust });
        window.location.href = '/portal/dashboard';
      } catch (e) {
        this.error = apiError(e, this.$t('login_failed'));
        // The step timed out (10 minutes): start over with the password.
        if (e && e.response && e.response.data && e.response.data.expired) this.back();
      } finally {
        this.loading = false;
      }
    },
    async resend() {
      this.error = '';
      this.info = '';
      try {
        await http.post('/portal/login/two-factor/resend');
        this.info = this.$t('tf_code_sent');
      } catch (e) {
        this.error = apiError(e, this.$t('login_failed'));
      }
    },
    toggleRecovery() {
      this.recovery = !this.recovery;
      this.code = '';
      this.error = '';
      this.$nextTick(() => this.$refs.codeInput && this.$refs.codeInput.focus());
    },
    back() {
      this.tf = null;
      this.code = '';
      this.recovery = false;
      this.info = '';
    },
  },
};
</script>
