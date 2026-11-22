@extends('layouts.store')

@section('content')
@php
  // Wallet balances are kept in the BASE currency (like refunds), so they
  // are shown with the base symbol whatever display currency is active.
  $currency = \App\Services\StoreCurrencyService::base()['symbol'];
@endphp

<section class="border-b border-line-subtle"
         style="background: linear-gradient(135deg, rgb(var(--color-accent-500) / .04), rgb(var(--color-bg-surface)));">
  <div class="container py-6 flex items-center justify-between flex-wrap gap-3">
    <div>
      <span class="section-kicker">{{ __('messages.Account') }}</span>
      <h1 class="section-title mt-1">{{ __('messages.MyWallet') }}</h1>
      <div class="text-fg-muted text-sm mt-1">{{ __('messages.WalletPageHint') }}</div>
    </div>
    <a href="{{ store_base_url('account') }}" class="btn btn-outline">
      <x-store.icon name="user" class="w-4 h-4" />{{ __('messages.MyAccount') }}
    </a>
  </div>
</section>

<div class="container py-8" id="wallet-app">
  <div class="max-w-5xl mx-auto">

    <div id="wl-loading" class="text-center text-fg-muted py-10">
      <span class="spinner spinner-lg"></span>
    </div>

    <div id="wl-load-error" class="alert alert-danger hidden flex items-start gap-2">
      <x-store.icon name="alert-circle" class="w-5 h-5 mt-0.5 shrink-0" />
      <span>{{ __('messages.WalletLoadFailed') }}</span>
    </div>

    <div id="wl-content" class="hidden grid lg:grid-cols-[minmax(0,1fr)_400px] gap-6 items-start">

      {{-- ===== Left: balance + activity ===== --}}
      <div class="space-y-6 min-w-0">

        <div class="card">
          <div class="card-body">
            <div class="flex items-start justify-between gap-4 flex-wrap">
              <div class="min-w-0">
                <div class="text-fg-muted text-sm flex items-center gap-2">
                  <x-store.icon name="wallet" class="w-4 h-4 text-accent-500" />{{ __('messages.WalletCurrentBalance') }}
                </div>
                <div id="wl-balance" class="price text-3xl font-bold mt-1 text-fg-primary"></div>
                <div id="wl-available-row" class="text-sm text-fg-muted mt-1 hidden">
                  {{ __('messages.WalletAvailableToWithdraw') }}: <strong id="wl-available" class="text-fg-primary"></strong>
                </div>
              </div>
              <span class="trust-icon shrink-0" style="width:3rem;height:3rem;border-radius:.85rem"><x-store.icon name="wallet" class="w-6 h-6" /></span>
            </div>

            <div id="wl-frozen" class="alert alert-warning mt-4 mb-0 hidden flex items-start gap-2">
              <x-store.icon name="lock" class="w-4 h-4 mt-0.5 shrink-0" />
              <span>{{ __('messages.WalletFrozenNotice') }}</span>
            </div>
            <div id="wl-owed" class="alert alert-warning mt-4 mb-0 hidden flex items-start gap-2">
              <x-store.icon name="info" class="w-4 h-4 mt-0.5 shrink-0" />
              <span>{{ __('messages.WalletOwedNotice') }}</span>
            </div>
            <div id="wl-checkout-hint" class="text-xs text-fg-muted mt-3 hidden flex items-center gap-1.5">
              <x-store.icon name="cart" class="w-3.5 h-3.5" />{{ __('messages.WalletUseAtCheckout') }}
            </div>
          </div>
        </div>

        <div class="card">
          <div class="card-body">
            <h5 class="font-semibold mb-3 flex items-center gap-2">
              <x-store.icon name="clock-history" class="w-5 h-5 text-accent-500" />{{ __('messages.WalletActivity') }}
            </h5>

            <div id="wl-tx-empty" class="empty-state py-10 hidden">
              <div class="empty-icon"><x-store.icon name="wallet" class="w-10 h-10" /></div>
              <h3>{{ __('messages.WalletNoActivity') }}</h3>
              <p>{{ __('messages.WalletNoActivityHint') }}</p>
            </div>

            <ul id="wl-tx-list" class="divide-y divide-line-subtle"></ul>

            <div class="text-center mt-4">
              <button type="button" id="wl-tx-more" class="btn btn-outline btn-sm hidden">
                <span class="js-label">{{ __('messages.WalletLoadMore') }}</span>
                <span class="js-spin hidden spinner"></span>
              </button>
            </div>
          </div>
        </div>
      </div>

      {{-- ===== Right: gift card + withdrawals ===== --}}
      <div class="space-y-6 min-w-0">

        <div class="card hidden" id="wl-redeem-card">
          <div class="card-body">
            <h5 class="font-semibold mb-1 flex items-center gap-2">
              <x-store.icon name="gift" class="w-5 h-5 text-accent-500" />{{ __('messages.WalletRedeemGiftCard') }}
            </h5>
            <p class="text-xs text-fg-muted mb-3">{{ __('messages.WalletRedeemHint') }}</p>
            <form id="wl-redeem-form" class="space-y-3" novalidate>
              <div>
                <label class="form-label text-xs" for="wl-code">{{ __('messages.WalletGiftCardCode') }}</label>
                <input type="text" id="wl-code" class="input" maxlength="60" autocomplete="off" spellcheck="false" required>
              </div>
              <div id="wl-redeem-msg" class="text-xs hidden"></div>
              <button type="submit" class="btn btn-primary btn-block" id="wl-redeem-btn">
                <x-store.icon name="check" class="w-4 h-4" />{{ __('messages.WalletRedeem') }}
              </button>
            </form>
          </div>
        </div>

        <div class="card hidden" id="wl-withdraw-card">
          <div class="card-body">
            <h5 class="font-semibold mb-1 flex items-center gap-2">
              <x-store.icon name="cash" class="w-5 h-5 text-accent-500" />{{ __('messages.WalletRequestWithdrawal') }}
            </h5>
            <p class="text-xs text-fg-muted mb-3">{{ __('messages.WalletRequestWithdrawalHint') }}</p>
            <form id="wl-withdraw-form" class="space-y-3" novalidate>
              <div>
                <label class="form-label text-xs" for="wl-amount">{{ __('messages.Amount') }} *</label>
                <input type="number" id="wl-amount" class="input" min="0.01" step="0.01" inputmode="decimal" required>
                <div id="wl-amount-hint" class="text-[11px] text-fg-muted mt-1"></div>
              </div>
              <div>
                <label class="form-label text-xs" for="wl-method">{{ __('messages.WalletPayoutMethod') }}</label>
                {{-- Values stay in plain English: the store staff read them in the admin. --}}
                <select id="wl-method" class="input">
                  <option value="Bank transfer">{{ __('messages.BankTransfer') }}</option>
                  <option value="Mobile money">{{ __('messages.MobileMoney') }}</option>
                  <option value="Cash at the store">{{ __('messages.WalletMethodCashPickup') }}</option>
                </select>
              </div>
              <div>
                <label class="form-label text-xs" for="wl-destination">{{ __('messages.WalletPayoutDetails') }} *</label>
                <textarea id="wl-destination" class="input" rows="2" maxlength="190" required></textarea>
                <div class="text-[11px] text-fg-muted mt-1">{{ __('messages.WalletPayoutDetailsHint') }}</div>
              </div>
              <div id="wl-withdraw-msg" class="text-xs hidden"></div>
              <button type="submit" class="btn btn-primary btn-block" id="wl-withdraw-btn">
                <x-store.icon name="send" class="w-4 h-4" />{{ __('messages.WalletSendRequest') }}
              </button>
            </form>
          </div>
        </div>

        <div class="card hidden" id="wl-wd-card">
          <div class="card-body">
            <h6 class="font-semibold mb-3 flex items-center gap-2">
              <x-store.icon name="receipt" class="w-4 h-4 text-accent-500" />{{ __('messages.WalletWithdrawals') }}
            </h6>
            <div id="wl-wd-empty" class="text-sm text-fg-muted hidden">{{ __('messages.WalletNoWithdrawals') }}</div>
            <ul id="wl-wd-list" class="divide-y divide-line-subtle"></ul>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
(function(){
  // Base symbol, NOT the session display currency — wallet amounts are base.
  const cur  = @json($currency);
  const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
  const URLS = {
    summary:  @json(route('my_wallet.summary')),
    tx:       @json(route('my_wallet.transactions')),
    redeem:   @json(route('my_wallet.redeem')),
    withdraw: @json(route('my_wallet.withdraw')),
  };
  const T = {
    failed:      @json(__('messages.SomethingWentWrong')),
    balAfter:    @json(__('messages.WalletBalanceAfter')),
    redeemed:    @json(__('messages.WalletRedeemed')),
    minW:        @json(__('messages.WalletMinWithdrawal')),
    available:   @json(__('messages.WalletAvailableToWithdraw')),
    sent:        @json(__('messages.WalletWithdrawalSent')),
    codeReq:     @json(__('messages.Wallet_Err_gift_card_code_required')),
    amountBad:   @json(__('messages.Wallet_Err_wallet_invalid_amount')),
    belowMin:    @json(__('messages.Wallet_Err_withdrawal_below_minimum')),
    overAvail:   @json(__('messages.Wallet_Err_withdrawal_exceeds_available')),
    destReq:     @json(__('messages.WalletPayoutDetailsHint')),
  };
  const WD_STATUS = {
    pending:  { text: @json(__('messages.WalletWdStatus_pending')),  cls: 'chip-warning' },
    approved: { text: @json(__('messages.WalletWdStatus_approved')), cls: 'chip-info' },
    paid:     { text: @json(__('messages.WalletWdStatus_paid')),     cls: 'chip-success' },
    rejected: { text: @json(__('messages.WalletWdStatus_rejected')), cls: 'chip-danger' },
  };
  const PER_PAGE = 10;

  const $ = id => document.getElementById(id);
  const el = {
    loading: $('wl-loading'), loadErr: $('wl-load-error'), content: $('wl-content'),
    balance: $('wl-balance'), availRow: $('wl-available-row'), avail: $('wl-available'),
    frozen: $('wl-frozen'), owed: $('wl-owed'), coHint: $('wl-checkout-hint'),
    txList: $('wl-tx-list'), txEmpty: $('wl-tx-empty'), txMore: $('wl-tx-more'),
    redeemCard: $('wl-redeem-card'), redeemForm: $('wl-redeem-form'), code: $('wl-code'),
    redeemMsg: $('wl-redeem-msg'), redeemBtn: $('wl-redeem-btn'),
    wdCard: $('wl-withdraw-card'), wdForm: $('wl-withdraw-form'), amount: $('wl-amount'),
    amountHint: $('wl-amount-hint'), method: $('wl-method'), dest: $('wl-destination'),
    wdMsg: $('wl-withdraw-msg'), wdBtn: $('wl-withdraw-btn'),
    wdListCard: $('wl-wd-card'), wdList: $('wl-wd-list'), wdEmpty: $('wl-wd-empty'),
  };

  let state = { available: 0, min: 0, status: 'active' };
  let txPage = 0, txPages = 1;

  function money(n){
    const v = Number(n || 0);
    const s = storeWithCurrency(cur, storeFmtNumber(Math.abs(v)));
    return v < 0 ? '-' + s : s;
  }
  function esc(s){ return String(s==null?'':s).replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m])); }
  function fmtDate(s){ return s ? String(s).substring(0,10) : '—'; }
  function errorText(d){
    if (!d) return T.failed;
    if (d.error) return d.error;
    if (d.errors) { const first = Object.values(d.errors)[0]; if (Array.isArray(first) && first[0]) return first[0]; }
    return d.message || T.failed;
  }
  function showMsg(node, text, ok){
    node.textContent = text;
    node.className = 'text-xs ' + (ok ? 'text-success' : 'text-danger');
  }
  function hideMsg(node){ node.className = 'text-xs hidden'; node.textContent = ''; }
  function busy(button, on){ button.disabled = on; button.classList.toggle('opacity-60', on); }

  async function getJson(url){
    const res = await fetch(url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' });
    const d = await res.json().catch(() => null);
    if (!res.ok) throw d || {};
    return d;
  }
  async function postJson(url, body){
    const res = await fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
      body: JSON.stringify(body),
    });
    const d = await res.json().catch(() => null);
    if (!res.ok) throw d || {};
    return d;
  }

  // ---- Summary: balance card, forms, withdrawal requests ----
  function renderSummary(d){
    const bal = Number(d.balance || 0);
    state = { available: Number(d.available || 0), min: Number(d.min_withdrawal || 0), status: d.status || 'active' };
    const frozen = state.status === 'frozen';

    el.balance.textContent = money(bal);
    el.balance.classList.toggle('text-danger', bal < 0);
    el.balance.classList.toggle('text-fg-primary', bal >= 0);
    el.frozen.classList.toggle('hidden', !frozen);
    el.owed.classList.toggle('hidden', !(bal < 0));
    el.coHint.classList.toggle('hidden', !(d.checkout_enabled && !frozen && bal > 0));

    el.availRow.classList.toggle('hidden', !d.withdrawal_enabled);
    el.avail.textContent = money(state.available);

    el.redeemCard.classList.toggle('hidden', !d.gift_cards_enabled);

    el.wdCard.classList.toggle('hidden', !d.withdrawal_enabled);
    if (d.withdrawal_enabled) {
      const hints = [T.available + ': ' + money(state.available)];
      if (state.min > 0) hints.unshift(T.minW.replace(':amount', money(state.min)));
      el.amountHint.textContent = hints.join(' · ');
      if (state.min > 0) el.amount.min = state.min;
      el.amount.max = state.available > 0 ? state.available : '';
      // A frozen wallet can't pay out; keep the form visible but locked.
      [el.amount, el.method, el.dest, el.wdBtn].forEach(n => { n.disabled = frozen; });
    }

    const wds = Array.isArray(d.withdrawals) ? d.withdrawals : [];
    el.wdListCard.classList.toggle('hidden', !d.withdrawal_enabled && !wds.length);
    el.wdEmpty.classList.toggle('hidden', wds.length > 0);
    el.wdList.innerHTML = wds.map(w => {
      const st = WD_STATUS[w.status] || { text: w.status, cls: '' };
      return `<li class="py-3 flex items-start justify-between gap-3">
        <div class="min-w-0">
          <div class="font-semibold price">${esc(money(w.amount))}</div>
          <div class="text-xs text-fg-muted">${esc(w.method || '')}${w.method ? ' · ' : ''}${esc(fmtDate(w.created_at))}</div>
          ${w.note ? `<div class="text-xs text-fg-secondary mt-1" style="overflow-wrap:anywhere">${esc(w.note)}</div>` : ''}
        </div>
        <span class="chip ${st.cls} shrink-0">${esc(st.text)}</span>
      </li>`;
    }).join('');
  }

  async function loadSummary(){
    const d = await getJson(URLS.summary);
    renderSummary(d);
    return d;
  }

  // ---- Activity list with "Load more" ----
  function txRow(t){
    const credit = t.type === 'credit';
    const sign = credit ? '+' : '−';
    return `<li class="py-3 flex items-start gap-3">
      <span class="rounded-full inline-flex items-center justify-center shrink-0 font-bold ${credit ? 'text-success' : 'bg-bg-muted text-fg-secondary'}" style="width:2.25rem;height:2.25rem;${credit ? 'background:rgb(var(--color-success) / .12)' : ''}" aria-hidden="true">${sign}</span>
      <div class="min-w-0 flex-1">
        <div class="font-medium">${esc(t.source_label || t.source)}</div>
        <div class="text-xs text-fg-muted">${esc(fmtDate(t.date || t.created_at))}</div>
        ${t.note ? `<div class="text-xs text-fg-secondary mt-0.5" style="overflow-wrap:anywhere">${esc(t.note)}</div>` : ''}
      </div>
      <div class="text-end shrink-0">
        <div class="font-semibold price ${credit ? 'text-success' : 'text-fg-primary'}">${sign}${esc(money(Math.abs(Number(t.amount || 0))))}</div>
        <div class="text-[11px] text-fg-muted">${esc(T.balAfter.replace(':amount', money(t.balance_after)))}</div>
      </div>
    </li>`;
  }

  async function loadTx(reset){
    if (reset) { txPage = 0; txPages = 1; }
    const next = txPage + 1;
    const d = await getJson(URLS.tx + '?page=' + next + '&per_page=' + PER_PAGE);
    const rows = Array.isArray(d.data) ? d.data : [];
    if (reset) el.txList.innerHTML = '';
    el.txList.insertAdjacentHTML('beforeend', rows.map(txRow).join(''));
    txPage = (d.meta && d.meta.page) || next;
    txPages = (d.meta && d.meta.pages) || 1;
    el.txEmpty.classList.toggle('hidden', el.txList.children.length > 0);
    el.txMore.classList.toggle('hidden', txPage >= txPages);
  }

  el.txMore.addEventListener('click', async () => {
    const label = el.txMore.querySelector('.js-label');
    const spin = el.txMore.querySelector('.js-spin');
    busy(el.txMore, true); label.classList.add('hidden'); spin.classList.remove('hidden');
    try { await loadTx(false); } catch (e) { window.StoreDialog ? StoreDialog.alert(errorText(e), { type: 'danger' }) : alert(errorText(e)); }
    busy(el.txMore, false); label.classList.remove('hidden'); spin.classList.add('hidden');
  });

  async function refreshAll(){
    await Promise.all([loadSummary(), loadTx(true)]);
  }

  // ---- Redeem a gift card ----
  el.redeemForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    const code = el.code.value.trim();
    if (!code) { showMsg(el.redeemMsg, T.codeReq, false); el.code.focus(); return; }
    hideMsg(el.redeemMsg);
    busy(el.redeemBtn, true);
    try {
      const d = await postJson(URLS.redeem, { code });
      el.code.value = '';
      showMsg(el.redeemMsg, T.redeemed.replace(':amount', money(d.credited)), true);
      await refreshAll().catch(() => {});
    } catch (err) {
      showMsg(el.redeemMsg, errorText(err), false);
    }
    busy(el.redeemBtn, false);
  });

  // ---- Request a withdrawal ----
  el.wdForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    const amount = Math.round(Number(el.amount.value || 0) * 100) / 100;
    const destination = el.dest.value.trim();
    if (!(amount > 0)) { showMsg(el.wdMsg, T.amountBad, false); el.amount.focus(); return; }
    if (state.min > 0 && amount < state.min) { showMsg(el.wdMsg, T.belowMin.replace(':min', money(state.min)), false); el.amount.focus(); return; }
    if (amount > state.available) { showMsg(el.wdMsg, T.overAvail, false); el.amount.focus(); return; }
    if (!destination) { showMsg(el.wdMsg, T.destReq, false); el.dest.focus(); return; }
    hideMsg(el.wdMsg);
    busy(el.wdBtn, true);
    try {
      await postJson(URLS.withdraw, { amount, method: el.method.value || null, destination });
      el.amount.value = '';
      el.dest.value = '';
      await loadSummary().catch(() => {});
      showMsg(el.wdMsg, T.sent, true);
    } catch (err) {
      showMsg(el.wdMsg, errorText(err), false);
    }
    // loadSummary() re-applies the frozen lock; only release when not frozen.
    if (state.status !== 'frozen') busy(el.wdBtn, false);
  });

  // ---- Boot ----
  refreshAll()
    .then(() => { el.loading.classList.add('hidden'); el.content.classList.remove('hidden'); })
    .catch(() => { el.loading.classList.add('hidden'); el.loadErr.classList.remove('hidden'); });
})();
</script>
@endsection
