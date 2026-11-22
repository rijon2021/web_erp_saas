{{-- Car Dealership theme: Alpine component behind every lead form (vehicle
     page tabs, home trade-in form). Posts JSON to store.vehicle.lead; a
     successful reservation puts the deposit product in the cart and opens
     the mini cart, so payment goes through the normal checkout. --}}
@once
<script>
  window.cdLead = function (cfg) {
    cfg = cfg || {};
    return {
      type: cfg.type || 'enquiry',
      sending: false,
      ok: '',
      err: '',
      errors: {},
      setType(t) { this.type = t; this.ok = ''; this.err = ''; this.errors = {}; },
      async submit(form) {
        if (this.sending) return;
        this.sending = true; this.ok = ''; this.err = ''; this.errors = {};
        const body = {};
        new FormData(form).forEach((v, k) => { body[k] = v; });
        body.type = this.type;
        ['wants_finance', 'has_trade_in'].forEach((k) => { body[k] = form.querySelector('[name=' + k + ']:checked') ? 1 : 0; });
        if (cfg.extra) Object.assign(body, typeof cfg.extra === 'function' ? cfg.extra(this.type) : cfg.extra);
        try {
          const csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
          const res = await fetch(@json(route('store.vehicle.lead')), {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify(body),
          });
          const data = await res.json().catch(() => ({}));
          if (res.ok && data.success !== false) {
            this.ok = data.message || @json(__('messages.CarLeadSent'));
            if (this.type === 'reservation' && cfg.reserve && window.CartLS) {
              window.CartLS.add(cfg.reserve, 1);
              if (window.StoreUI) window.StoreUI.open('miniCart');
            }
            form.reset();
            return;
          }
          this.errors = data.errors || {};
          const first = Object.values(this.errors)[0];
          this.err = (Array.isArray(first) ? first[0] : first) || data.message || @json(__('messages.CarLeadFailed'));
        } catch (e) {
          this.err = @json(__('messages.CarLeadFailed'));
        } finally {
          this.sending = false;
        }
      },
    };
  };
</script>
@endonce
