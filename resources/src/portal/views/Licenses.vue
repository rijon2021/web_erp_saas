<template>
  <!-- Software License Keys: the customer's keys from every purchase. -->
  <div>
    <div v-if="loading" class="text-center text-secondary py-5">
      <div class="spinner-border" role="status"></div>
    </div>

    <div v-else-if="!licenses.length" class="card">
      <div class="card-body text-center py-5">
        <i class="ti ti-key fs-1 text-secondary"></i>
        <h3 class="mt-3 mb-1">{{ tr('lic_none', 'No licenses yet') }}</h3>
        <p class="text-secondary mb-0">{{ tr('lic_none_hint', 'License keys for software you buy will appear here.') }}</p>
      </div>
    </div>

    <div v-else class="row row-cards">
      <div v-for="lic in licenses" :key="lic.id" class="col-12">
        <div class="card">
          <div class="card-body">
            <div class="d-flex align-items-start justify-content-between gap-3 flex-wrap">
              <div class="min-w-0">
                <div class="fw-semibold"><i class="ti ti-key me-1 text-primary"></i>{{ lic.product }}<span v-if="lic.edition"> — {{ lic.edition }}</span></div>
                <div class="text-secondary small mt-1">
                  <span v-if="lic.sale_ref">{{ $t('ref') }} {{ lic.sale_ref }} · </span>{{ lic.purchased_at }}
                </div>
              </div>
              <span :class="badge(tone(lic.state))">{{ stateText(lic.state) }}</span>
            </div>

            <div class="d-flex align-items-center gap-2 flex-wrap mt-3">
              <code class="px-3 py-2 rounded bg-light text-body fw-semibold" style="font-size: 15px; word-break: break-all;">{{ lic.key }}</code>
              <button v-if="lic.state !== 'revoked'" type="button" class="btn btn-outline-secondary btn-sm" @click="copy(lic)">
                <i class="ti ti-copy me-1"></i>{{ copiedId === lic.id ? tr('lic_copied', 'Copied!') : tr('lic_copy', 'Copy') }}
              </button>
            </div>

            <div v-if="lic.generated" class="row g-3 mt-1 small">
              <div class="col-sm-4">
                <div class="text-secondary">{{ tr('lic_valid_until', 'Valid until') }}</div>
                <div class="fw-medium">{{ lic.expires_at || tr('lic_lifetime', 'Lifetime') }}</div>
              </div>
              <div class="col-sm-4">
                <div class="text-secondary">{{ tr('lic_activations', 'Activations') }}</div>
                <div class="fw-medium">{{ lic.activations.length }} / {{ lic.max_activations || '∞' }}</div>
              </div>
              <div class="col-sm-4">
                <div class="text-secondary">{{ tr('lic_locked_to', 'Locked to') }}</div>
                <div class="fw-medium">{{ restrictionText(lic.restriction) }}</div>
              </div>
            </div>

            <div v-if="lic.generated && lic.activations.length" class="border-top mt-3 pt-3">
              <div class="text-secondary small mb-2">{{ tr('lic_activated_on', 'Activated on') }}</div>
              <div v-for="a in lic.activations" :key="a.id" class="d-flex align-items-center justify-content-between gap-3 small py-1">
                <span class="text-truncate">
                  <i :class="`ti ti-${a.kind === 'domain' ? 'world' : 'device-laptop'} me-1 text-secondary`"></i>
                  {{ a.label || a.identifier }}
                  <span v-if="a.label" class="text-secondary">({{ a.identifier }})</span>
                  <span class="text-secondary"> · {{ a.activated_at }}</span>
                </span>
                <button type="button" class="btn btn-ghost-danger btn-sm" :disabled="busyId === a.id" @click="removeActivation(lic, a)">
                  <i class="ti ti-trash me-1"></i>{{ tr('lic_remove', 'Remove') }}
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
/**
 * My software licenses — GET /api/portal/licenses {licenses:[{id, product,
 * edition, key, generated, state, sale_ref, purchased_at, expires_at,
 * max_activations, restriction, activations:[...]}]}; a customer may free one
 * of their activation slots (POST licenses/{id}/activations/{a}/deactivate).
 */
import http from '../lib/http';
import { badge } from '../lib/ui';

const TONES = { active: 'green', delivered: 'green', expired: 'orange', suspended: 'orange', revoked: 'red', inactive: 'secondary' };

export default {
  data() {
    return { licenses: [], loading: true, copiedId: null, busyId: null };
  },
  mounted() { this.fetch(); },
  methods: {
    badge,
    pageMeta() { return { title: this.tr('nav_licenses', 'Licenses'), pretitle: this.tr('nav_services', 'Services'), crumbs: [] }; },
    tone(s) { return TONES[s] || 'secondary'; },
    stateText(s) { return this.tr(`lic_state_${s}`, s); },
    restrictionText(r) {
      if (r === 'domain') return this.tr('lic_restriction_domain', 'Domain');
      if (r === 'device') return this.tr('lic_restriction_device', 'Device');
      return this.tr('lic_restriction_none', 'Not locked');
    },
    async fetch() {
      this.loading = true;
      try {
        const { data } = await http.get('/portal/licenses');
        this.licenses = data.licenses || [];
      } catch (_) {
        this.licenses = [];
      }
      this.loading = false;
    },
    async copy(lic) {
      try {
        if (navigator.clipboard && window.isSecureContext) {
          await navigator.clipboard.writeText(lic.key);
        } else {
          const ta = document.createElement('textarea');
          ta.value = lic.key; document.body.appendChild(ta); ta.select();
          document.execCommand('copy'); document.body.removeChild(ta);
        }
        this.copiedId = lic.id;
        setTimeout(() => { if (this.copiedId === lic.id) this.copiedId = null; }, 1500);
      } catch (_) { /* clipboard blocked */ }
    },
    async removeActivation(lic, a) {
      if (!window.confirm(this.tr('lic_remove_confirm', 'Remove this activation? The license can then be activated somewhere else.'))) return;
      this.busyId = a.id;
      try {
        await http.post(`/portal/licenses/${lic.id}/activations/${a.id}/deactivate`);
        lic.activations = lic.activations.filter((x) => x.id !== a.id);
      } catch (_) { /* keep the row */ }
      this.busyId = null;
    },
  },
};
</script>
