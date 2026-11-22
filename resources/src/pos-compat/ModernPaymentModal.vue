
<template>
  <div class="modern-payment-modal">
    <b-modal
      ref="paymentModal"
      hide-footer
      hide-header
      size="xl"
      id="modern_payment_modal"
      class="payment-modal-wrapper premium-payment-modal-large"
      centered
      :no-close-on-backdrop="hasTerminalActivity"
      @hidden="resetForm"
      body-class="modal-body-custom"
      modal-class="premium-modal"
    >
      <div class="payment-container">
        <!-- Enhanced Header -->
        <div class="payment-header">
          <div class="header-left">
            <div class="icon-wrapper">
              <lucide-icon name="wallet" />
          </div>
            <div class="header-text">
              <h2 class="modal-title">{{ isEditMode ? $t('Edit_Payment') : $t('Payment_Checkout') }}</h2>
            </div>
          </div>
          <button type="button" class="close-button" @click="requestClose">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
              <line x1="18" y1="6" x2="6" y2="18"></line>
              <line x1="6" y1="6" x2="18" y2="18"></line>
            </svg>
          </button>
        </div>

        <!-- Content Area -->
        <div class="payment-content">
          <!-- Left Side: Transaction Info -->
          <div class="transaction-info">
            <!-- Amount Card -->
            <div class="amount-card">
              <div class="amount-card-header">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                  <circle cx="12" cy="12" r="10"></circle>
                  <path d="M12 6v6l4 2"></path>
                </svg>
                <span>{{$t('Transaction_Summary')}}</span>
            </div>
              <div class="amount-display">
                <span class="currency-label">{{$t('Total_Amount')}}</span>
                <span class="amount-large">{{ formatCurrency(paymentForm.amountDue) }}</span>
            </div>
        </div>

            <!-- Payment Status -->
            <div class="payment-status-card">
              <h3 class="card-title">{{$t('Payment_Breakdown')}}</h3>
              <div class="status-grid">
                <div class="status-box">
                  <div class="status-icon paying">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor">
                      <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/>
                    </svg>
                  </div>
                  <div class="status-details">
                    <span class="status-name">{{$t('Paying')}}</span>
                    <span class="status-amount">{{ formatCurrency(totalPaid) }}</span>
                  </div>
                </div>
                <div class="status-box">
                  <div class="status-icon balance">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor">
                      <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/>
                    </svg>
                  </div>
                  <div class="status-details">
                    <span class="status-name">{{$t('Balance')}}</span>
                    <span class="status-amount balance-text">{{ formatCurrency(balance) }}</span>
                  </div>
                </div>
                <div class="status-box">
                  <div class="status-icon change">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor">
                      <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/>
                    </svg>
                  </div>
                  <div class="status-details">
                    <span class="status-name">{{$t('Change')}}</span>
                    <span class="status-amount change-text">{{ formatCurrency(changeReturn) }}</span>
                  </div>
                </div>
              </div>
            </div>

          </div>

          <!-- Right Side: Payment Form -->
          <div class="payment-form-area">
            <form @submit.prevent="submitPayment" class="enhanced-form">
              <!-- Payment Methods header removed as requested -->

              <!-- Multi Payment Lines with Right Vertical Quick Amounts -->
              <div class="form-group">
                <div class="payment-lines-layout">
                  <!-- Left: Lines -->
                  <div class="payment-lines-list">
                    <div v-for="(p, idx) in paymentLines" :key="idx" class="payment-line-card">
                      <div class="payment-line-header">
                        <div class="line-badge">{{ idx + 1 }}</div>
                        <span class="line-title">{{$t('Payment')}} #{{ idx + 1 }}</span>
                        <!-- Show remove (X) only for additional lines so user can return to a single payment method -->
                        <button
                          v-if="paymentLines.length > 1 && idx > 0"
                          type="button"
                          class="line-remove-btn"
                          @click="removePaymentLine(idx)"
                          :aria-label="$t('Remove_Payment_Line')"
                        >
                          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <line x1="18" y1="6" x2="6" y2="18"></line>
                            <line x1="6" y1="6" x2="18" y2="18"></line>
                          </svg>
                        </button>
                      </div>
                      <div class="payment-line-body">
                        <div class="input-field">
                          <label class="field-label">{{$t('Amount')}}</label>
                          <div class="input-with-icon">
                            <input 
                              v-model.number="p.amount"
                              @input="onAmountInput(idx)"
                              type="text"
                              step="0.01"
                              min="0"
                              inputmode="decimal"
                              pattern="\\d*(\\.\\d*)?"
                              :disabled="Number(paymentForm.amountDue) === 0 || isLineTerminalLocked(p)"
                              placeholder="0.00"
                              class="form-input" 
                            />
                          </div>
                        </div>
                        <div class="input-field" v-if="!isLineWallet(p)">
                          <label class="field-label">{{$t('Account')}}</label>
                          <select v-model="p.accountId" class="form-select">
                            <option value="">{{$t('Select_Account')}}</option>
                            <option v-for="a in resolvedAccounts" :key="a.id" :value="a.id">{{ a.account_name || a.name }}</option>
                          </select>
                        </div>
                      <div class="form-group">
                        <div class="payment-methods">
                          <div
                            v-for="m in resolvedPaymentMethods"
                            :key="m.id"
                            class="method-card"
                            :class="{ selected: String(p.paymentMethodId) === String(m.id) }"
                            @click="changePaymentMethod(p, m)"
                          >
                            <div class="method-content">
                              <div class="method-icon-wrapper">
                                <lucide-icon :name="m.icon" />
                              </div>
                              <span class="method-label">{{ m.name }}</span>
                            </div>
                            <div v-if="String(p.paymentMethodId) === String(m.id)" class="selected-indicator">
                              <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/>
                              </svg>
                            </div>
                          </div>
                        </div>
                      </div>
                      <!-- Wallet balance hint -->
                      <div class="form-group" v-if="isLineWallet(p)">
                        <!-- Wallet balances are base-currency; compare/display converted -->
                        <small :class="walletBalance != null && Number(p.amount) > Number(walletBalance) * docRate ? 'text-danger' : 'text-muted'">
                          {{ $t('WalletBalance') }}:
                          {{ walletBalance != null ? fmtAmount(Number(walletBalance) * docRate) : '—' }}
                        </small>
                        <small v-if="walletInfo && walletInfo.status === 'frozen'" class="text-danger d-block">
                          {{ $t('CW_Err_wallet_frozen') }}
                        </small>
                      </div>
                      <!-- Per-line Credit Card Section -->
                      <div class="form-group" v-if="isLineCreditCard(p) && stripeKey">
                        <div class="credit-card-section">
                          <div class="cc-header">
                            <span class="field-label">{{$t('Credit_Card')}}</span>
                          </div>

                          <!-- New Card Form (Stripe Elements) - saved cards disabled -->
                          <div class="new-card-form">
                            <div :id="'card-element-' + idx" class="stripe-card-element"></div>
                            <small class="text-muted">{{$t('Card_Info_Secure_Stripe')}}</small>
                          </div>
                        </div>
                      </div>
                      <!-- Card terminal (Sales > POS Devices): charge this line on the device -->
                      <div class="form-group" v-if="lineTerminals(p).length">
                        <div class="terminal-section" :class="'is-' + terminalState(p)">
                          <div class="terminal-row">
                            <lucide-icon name="credit-card" />
                            <select
                              v-if="lineTerminals(p).length > 1"
                              v-model="p.terminalId"
                              class="form-select terminal-select"
                              :disabled="isLineTerminalLocked(p)"
                            >
                              <option v-for="d in lineTerminals(p)" :key="d.id" :value="d.id">{{ d.name }}</option>
                            </select>
                            <span v-else class="terminal-name">{{ lineTerminals(p)[0].name }}</span>
                            <select
                              v-if="terminalFor(p) && terminalFor(p).max_installments > 1"
                              v-model.number="p.installments"
                              class="form-select terminal-installments"
                              :disabled="isLineTerminalLocked(p)"
                              :title="$t('Pd_Installments')"
                            >
                              <option v-for="n in terminalFor(p).max_installments" :key="n" :value="n">
                                {{ n === 1 ? $t('Pd_Single_Payment') : $t('Pd_Installments_N', { n }) }}
                              </option>
                            </select>
                          </div>
                          <div class="terminal-status">
                            <span v-if="p.terminalBusy" class="loading-spinner terminal-spinner"></span>
                            {{ terminalStatusText(p) }}
                          </div>
                          <div class="terminal-actions">
                            <button
                              v-if="!p.terminalBusy && !isLineCharged(p) && !p.terminalLost"
                              type="button"
                              class="action-btn terminal-charge-btn"
                              :disabled="!(Number(p.amount) > 0) || !isOnline || isSubmitting"
                              @click="chargeLine(p)"
                            >
                              <lucide-icon name="credit-card" /> {{ $t('Pd_Charge_On_Device', { amount: formatCurrency(p.amount) }) }}
                            </button>
                            <button
                              v-if="canRecheck(p)"
                              type="button"
                              class="action-btn cancel-btn"
                              :disabled="!isOnline"
                              @click="recheckLine(p)"
                            >
                              {{ $t('Pd_Check_Again') }}
                            </button>
                            <button
                              v-if="p.terminalBusy && p.terminalTxn && p.terminalTxn.id && !p.terminalVoiding"
                              type="button"
                              class="action-btn cancel-btn"
                              :disabled="p.terminalCancelling"
                              @click="cancelLineCharge(p)"
                            >
                              {{ $t('Cancel') }}
                            </button>
                            <button
                              v-if="isLineCharged(p) && !p.terminalBusy"
                              type="button"
                              class="action-btn cancel-btn"
                              :disabled="isSubmitting"
                              @click="voidLineCharge(p)"
                            >
                              {{ $t('Pd_Void_Charge') }}
                            </button>
                          </div>
                        </div>
                      </div>
                      </div>
                    </div>
                    <button v-if="Number(paymentForm.amountDue) > 0" type="button" class="action-btn cancel-btn add-line-btn" @click="addPaymentLine">
                      <lucide-icon name="plus" /> {{$t('Add_Payment_Method')}}
                    </button>
                  </div>

                  <!-- Right: Vertical Quick Amounts -->
                  <div class="quick-amount-rail">
                    <div class="quick-amount-vertical">
                      <button
                        type="button"
                        class="quick-btn"
                        v-for="amt in quickAmountOptions"
                        :key="amt"
                        @click="setQuickAmount(amt)"
                      >
                        {{ formatCurrency(amt) }}
                      </button>
                    </div>
                  </div>
                </div>
              </div>

          

              <!-- Payment Date Only -->
              <div class="form-group">
                <div class="input-field">
                  <label class="field-label">{{$t('Payment_Date')}} *</label>
                  <input v-model="paymentForm.date" type="date" class="form-select" />
                </div>
              </div>

              <!-- Notes -->
              <div class="form-group">
                <div class="dual-input">
                  <div class="input-field">
                    <label class="field-label">{{$t('Payment_note')}}</label>
                    <textarea v-model="paymentNote" rows="3" class="form-textarea" :placeholder="$t('Add_Payment_Note')"></textarea>
                  </div>
                  <div class="input-field">
                    <label class="field-label">{{$t('sale_note')}}</label>
                    <textarea v-model="saleNote" rows="3" class="form-textarea" :placeholder="$t('Add_Sale_Note')"></textarea>
                  </div>
                </div>
              </div>

              <!-- Kitchen routing (only when the Kitchen Display feature is enabled) -->
              <div class="form-group" v-if="kitchenEnabled">
                <label class="field-label">{{ $t('KitchenRouting') || 'Kitchen' }}</label>
                <div class="kitchen-routing">
                  <label class="kitchen-routing-option" :class="{ active: kitchenAction === 'send' }">
                    <input type="radio" value="send" v-model="kitchenAction" />
                    {{ $t('SendToKitchen') || 'Send to Kitchen' }}
                  </label>
                  <label class="kitchen-routing-option" :class="{ active: kitchenAction === 'none' }">
                    <input type="radio" value="none" v-model="kitchenAction" />
                    {{ $t('SaveWithoutSending') || 'Save Without Sending' }}
                  </label>
                  <label class="kitchen-routing-option" :class="{ active: kitchenAction === 'later' }">
                    <input type="radio" value="later" v-model="kitchenAction" />
                    {{ $t('SendLater') || 'Send Later' }}
                  </label>
                </div>
              </div>

              <!-- Email/SMS (hidden in offline mode) -->
              <div class="form-group" v-if="isOnline">
                <div class="checkboxes-group">
                  <label class="checkbox-item">
                    <input type="checkbox" v-model="sendEmail" />
                    {{$t('Send_Email_Receipt')}}
                  </label>
                  <label class="checkbox-item">
                    <input type="checkbox" v-model="sendSMS" />
                    {{$t('Send_SMS_Receipt')}}
                  </label>
                </div>
              </div>

        </form>
          </div>
        </div>
      </div>
      <!-- Footer Action Bar -->
      <div class="payment-footer">
        <button
          type="button"
          class="footer-btn footer-cancel"
          @click="requestClose"
        >
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <line x1="18" y1="6" x2="6" y2="18"></line>
            <line x1="6" y1="6" x2="18" y2="18"></line>
          </svg>
          {{$t('Cancel')}}
        </button>
        <button
          type="button"
          class="footer-btn footer-submit"
          :disabled="isSubmitting"
          @click="submitPayment"
        >
          <span v-if="!isSubmitting" class="btn-content">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor">
              <path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/>
            </svg>
            {{ isEditMode ? $t('Update_Payment') : $t('Complete_Payment') }}
          </span>
          <span v-else class="btn-content">
            <span class="loading-spinner"></span>
            {{$t('Processing')}}...
          </span>
        </button>
      </div>
    </b-modal>
  </div>
</template>

<script>
const loadStripe = () => Promise.resolve(null); // Stripe removed upstream
import axios from "./axios";
import { bvToast } from "./bv";
import { posCompatComponents } from "./components";
import Util from "./util";
import { formatPriceDisplay, getPriceDecimals, resolveHideZeroDecimals, withCurrency } from "./priceFormat";
import { useAuthStore } from "../stores/auth";
import { usePlanStore } from "../stores/plan";
import { receiptFontHeadTags, receiptPageHeadTag, whenPrintReady } from "../lib/receiptFont";
import { chargeOnTerminal, cancelTransaction, voidTransaction, recheckTransaction, fetchTransaction } from "../lib/posTerminal";

// Last device used on this computer (POS Devices), preselected per payment line.
const TERMINAL_LAST_KEY = 'pos_terminal_last';
// Payment method ids bound to a device, from the last list this computer loaded.
const TERMINAL_METHODS_KEY = 'pos_terminal_methods';
function readBoundMethods() {
  try {
    const v = JSON.parse(window.localStorage.getItem(TERMINAL_METHODS_KEY) || '[]');
    return Array.isArray(v) ? v.map(String) : [];
  } catch (e) {
    return [];
  }
}
export default {
  components: { ...posCompatComponents },
  name: 'ModernPaymentModal',
  props: {
    paymentMethods: { type: Array, default: () => [] },
    accounts: { type: Array, default: () => [] },
    savedPaymentMethods: { type: Array, default: () => [] },
    currency: { type: String, default: '$' },
    // Multi-Currency: rate of the POS-selected currency per 1 base unit and
    // its id. The modal operates in that currency (amountDue, typed tender,
    // quick amounts); the payload converts back to base at submit. Rate 1 /
    // null id = base currency, exact legacy behavior.
    currencyRate: { type: Number, default: 1 },
    currencyId: { type: [Number, String], default: null },
    // Data required to submit payment like in old POS logic
    clientId: { type: [Number, String], default: null },
    // Change Salesperson During Checkout: user id the sale is attributed to
    // (null when the feature is off — the backend then uses the cashier).
    sellerId: { type: [Number, String], default: null },
    // Commissions: Sales Agent picked on the POS (sales.sales_agent_id)
    salesAgentId: { type: [Number, String], default: null },
    warehouseId: { type: [Number, String], default: null },
    sale: { type: Object, default: () => ({}) },
    details: { type: Array, default: () => [] },
    grandTotal: { type: Number, default: 0 },
    discountFromPoints: { type: Number, default: 0 },
    usedPoints: { type: Number, default: 0 },
    // Client credit control
    clientCreditLimit: { type: Number, default: 0 },
    clientNetBalance: { type: Number, default: 0 },
    // API endpoints
    createPosUrl: { type: String, default: 'pos/create_pos' },
    // Optional Stripe publishable key (to mirror old POS behavior)
    stripeKey: { type: String, default: '' },
    // Optional: when paying from a loaded draft sale
    draftSaleId: { type: [Number, String], default: null },
    // POS online/offline state (controls some UI like email/SMS options)
    isOnline: { type: Boolean, default: true },
    // Promotion checkout context — forwarded as-is to /pos/create_pos so the
    // backend can re-evaluate promotions and create PromotionUsage rows.
    promotionContext: { type: Object, default: () => ({}) },
    // System defaults from settings (used when opening modal and when adding new payment lines)
    defaultAccountId: { type: [Number, String], default: null },
    defaultPaymentMethodId: { type: [Number, String], default: null }
  },
  data() {
    return {
      isEditMode: false,
      isSubmitting: false,
      paymentProcessing: false,
      // Stable identifier for the current checkout; generated once via crypto.randomUUID()
      // and reused for:
      //  - immediate online API request
      //  - offline save payload
      //  - offline sync retries
      currentSaleUuid: null,
      transactionId: 'TRX-20251017-001',
      transactionStatus: 'Pending',
      stripe: null,
      cardElement: null,
      cardElementsByLine: {},
      activeCardLineIndex: 0,
      paymentLines: [],
      sendEmail: false,
      sendSMS: false,
      paymentNote: '',
      saleNote: '',
      // Kitchen routing choice for this sale: 'send' | 'none' | 'later'. Defaults to 'none'
      // so the existing checkout flow is unchanged when the feature is off or not chosen.
      kitchenAction: 'none',
      isCardInputComplete: false,
      paymentForm: {
        amountDue: 0,
        accountId: '',
        date: new Date().toISOString().split('T')[0], // Will be updated in created() or openModal()
        reference: '',
        notes: ''
      },
      // Selected customer's wallet balance (null until fetched).
      walletBalance: null,
      // GET pos/wallet-balance/{client} → { enabled, balance, status,
      // allow_negative, payment_method_id } (null until fetched / on error).
      walletInfo: null,
      // POS Devices (card terminals) usable from this warehouse; a payment line
      // whose method one of them is bound to is charged on the device.
      terminals: [],
      terminalsState: 'idle', // idle | loading | ready | failed
      terminalsLoadedFor: null,
      terminalsLoadedAt: 0,
      boundMethodIds: readBoundMethods(),
      terminalStop: false
    };
  },
  mounted() {
    window.addEventListener('beforeunload', this.onTerminalBeforeUnload);
  },
  beforeUnmount() {
    this.terminalStop = true;
    window.removeEventListener('beforeunload', this.onTerminalBeforeUnload);
  },
  watch: {
    clientId: {
      immediate: true,
      handler() { this.fetchWalletBalance(); }
    }
  },
  computed: {
    // Monetary precision (2 or 3) driven by the "Enable 3 Decimal Pricing" setting.
    priceDecimals() {
      return getPriceDecimals({ store: this.$store });
    },
    // System Settings -> Hide Zero Decimals. Read from the Pinia auth store:
    // the compat $store facade has no getters (the auth cache covers offline).
    hideZeroDecimals() {
      const user = useAuthStore().user || {};
      return resolveHideZeroDecimals(user.hide_zero_decimals);
    },
    // Multi-Currency: sanitized rate (1 = base currency).
    docRate() {
      const r = Number(this.currencyRate);
      return Number.isFinite(r) && r > 0 ? r : 1;
    },
    // The grand total in the POS-selected currency — the modal's working unit.
    docGrandTotal() {
      return (Number(this.grandTotal) || 0) * this.docRate;
    },
    // Whether the Kitchen Display feature is enabled (system setting, exposed via auth store).
    kitchenEnabled() {
      const user = this.$store && this.$store.getters && this.$store.getters.currentUser;
      return !!(user && user.enable_kitchen_display);
    },
    totalPaid() {
      return (this.paymentLines || []).reduce((sum, line) => sum + (Number(line.amount) || 0), 0);
    },
    balance() {
      const amountDue = this.paymentForm.amountDue || 0;
      const paid = this.totalPaid;
      return Math.max(0, amountDue - paid);
    },
    changeReturn() {
      const over = this.totalPaid - (this.paymentForm.amountDue || 0);
      return over > 0 ? over : 0;
    },
    resolvedPaymentMethods() {
      const methods = (this.paymentMethods || []).map(m => ({
        ...m,
        icon: m.icon || this.getPaymentIcon(m)
      }));
      const isCash = (m) => !!(m && m.name && m.name.toLowerCase().includes('cash'));
      const cash = methods.filter(isCash);
      const others = methods.filter(m => !isCash(m));
      return cash.concat(others);
    },
    resolvedAccounts() {
      return this.accounts || [];
    },
    anyCreditCardUsed() {
      // Stripe card lines only — a line charged on a POS device is already paid.
      return (this.paymentLines || []).some(line => this.isLineCreditCard(line));
    },
    // A device is busy or has charged a line: closing must void it first.
    hasTerminalActivity() {
      return (this.paymentLines || []).some(line => line.terminalBusy || this.isLineCharged(line));
    },
    quickAmountOptions() {
      const base = [this.paymentForm && this.paymentForm.amountDue, 15, 20, 50, 100, 200]
        .map(v => Number(v))
        .filter(v => Number.isFinite(v));
      const seen = new Set();
      const unique = [];
      for (const v of base) {
        const key = v.toFixed(this.priceDecimals);
        if (!seen.has(key)) {
          seen.add(key);
          unique.push(parseFloat(key));
        }
      }
      return unique;
    }
  },
  methods: {
    // Amount text per the Price Format / precision / Hide Zero Decimals settings (no symbol).
    fmtAmount(v) {
      const user = useAuthStore().user || {};
      return formatPriceDisplay(v, this.priceDecimals, user.price_format || null, this.hideZeroDecimals);
    },
    // Get current date in configured timezone (YYYY-MM-DD format)
    getCurrentDateInTimezone() {
      try {
        // Get timezone from Vuex store getter (from get_user_auth API)
        // or default to UTC
        const timezone = (this.$store && this.$store.getters && this.$store.getters.getTimezone) || 'UTC';
        
        // Format current date in the specified timezone
        const now = new Date();
        const formatter = new Intl.DateTimeFormat('en-CA', {
          timeZone: timezone,
          year: 'numeric',
          month: '2-digit',
          day: '2-digit'
        });
        return formatter.format(now);
      } catch (e) {
        // Fallback to UTC if timezone is invalid or formatting fails
        return new Date().toISOString().split('T')[0];
      }
    },
    hasAnyNewCardToTokenize() {
      // Saved cards are disabled; any credit card line requires a fresh token.
      return (this.paymentLines || []).some((line) => this.isLineCreditCard(line));
    },
    isCreditCardMethod(method) {
      if (!method || !method.name) return false;
      return method.name.trim().toLowerCase() === 'credit card';
    },
    isLineCreditCard(line) {
      if (!line) return false;
      // Bound to a POS device: paid on the terminal, not through Stripe.
      if (this.lineTerminals(line).length) return false;
      const pm = this.resolvedPaymentMethods.find(m => String(m.id) === String(line.paymentMethodId));
      return this.isCreditCardMethod(pm);
    },
    isWalletMethod(method) {
      // The Customer Wallet tender is the payment method the server names in
      // pos/wallet-balance (payment_method_id), never a guess by name or id.
      if (!method || !this.walletInfo || !this.walletInfo.enabled) return false;
      if (this.walletInfo.payment_method_id == null) return false;
      return String(method.id) === String(this.walletInfo.payment_method_id);
    },
    isLineWallet(line) {
      if (!line) return false;
      const pm = this.resolvedPaymentMethods.find(m => String(m.id) === String(line.paymentMethodId));
      return this.isWalletMethod(pm);
    },
    async fetchWalletBalance() {
      const clientId = this.clientId;
      if (!clientId) {
        this.walletInfo = null;
        this.walletBalance = null;
        return;
      }
      // Always answers ({ enabled: false } when the Customer Wallet is off);
      // any failure just hides the hint.
      try {
        const r = await axios.get('pos/wallet-balance/' + clientId);
        if (String(clientId) !== String(this.clientId)) return; // customer changed meanwhile
        const info = (r && r.data) || null;
        this.walletInfo = info;
        this.walletBalance = info && info.enabled ? Number(info.balance || 0) : null;
      } catch (e) {
        if (String(clientId) !== String(this.clientId)) return;
        this.walletInfo = null;
        this.walletBalance = null;
      }
    },
    changePaymentMethod(line, method) {
      if (this.isLineTerminalLocked(line)) {
        this.makeToast('warning', this.$t('Pd_Line_Locked'), this.$t('Warning'));
        return;
      }
      if (this.methodTerminals(method && method.id).length) {
        line.paymentMethodId = method.id;
        const idx = (this.paymentLines || []).indexOf(line);
        if (idx >= 0) this.destroyCardElementForLine(idx);
        this.ensureLineTerminal(line);
        return;
      }
      // If attempting to select credit card without Stripe config, block and notify
      if (this.isCreditCardMethod(method) && !this.stripeKey) {
        this.makeToast('warning', this.$t ? this.$t('credit_card_account_not_available') : 'Credit card account not available', this.$t ? this.$t('Warning') : 'Warning');
        // keep existing selection; if none, fallback to cash
        if (!line.paymentMethodId) {
          const cashId = this.getCashMethodId();
          if (cashId) line.paymentMethodId = cashId;
        }
        return;
      }
      line.paymentMethodId = method.id;
      const idx = (this.paymentLines || []).indexOf(line);

      if (this.isCreditCardMethod(method)) {
        // When a credit card method is selected, mount a Stripe Elements card input
        this.activeCardLineIndex = idx >= 0 ? idx : 0;
        this.$nextTick(() => this.loadStripePayment(this.activeCardLineIndex));
      } else if (idx >= 0) {
        // Clean up any existing card element if switching away from credit card
        this.destroyCardElementForLine(idx);
      }
    },
    //------ Toast (mirror old POS behavior)
    makeToast(variant, msg, title) {
      if (bvToast) {
        bvToast.toast(msg, {
          title: title,
          variant: variant,
        });
      } else {
        // Fallback
        console && console.warn && console.warn(`[${variant}] ${title}: ${msg}`);
      }
    },
    getPaymentIcon(method) {
      const name = ((method && method.name) || '').toLowerCase();
      if (name.includes('cash')) return 'wallet';
      if (name.includes('card') || name.includes('credit')) return 'credit-card';
      if (name.includes('bank') || name.includes('transfer')) return 'landmark';
      if (name.includes('cheque') || name.includes('check')) return 'file';
      return 'wallet';
    },
    getCashMethodId() {
      const list = this.resolvedPaymentMethods || [];
      const found = list.find(m => m && m.name && m.name.toLowerCase().includes('cash'));
      return found ? found.id : null;
    },
    getDefaultPaymentMethodId() {
      const id = this.defaultPaymentMethodId;
      if (id !== null && id !== undefined && id !== '') {
        const list = this.resolvedPaymentMethods || [];
        const found = list.find(m => m && String(m.id) === String(id));
        if (found) return found.id;
      }
      return this.getCashMethodId();
    },
    getDefaultAccountId() {
      const id = this.defaultAccountId;
      if (id !== null && id !== undefined && id !== '') {
        const list = this.resolvedAccounts || [];
        const found = list.find(a => a && String(a.id) === String(id));
        if (found) return String(found.id);
      }
      return '';
    },
    selectPaymentMethod(method) {
      this.paymentForm.paymentMethodId = method.id;
    },
    calculateChange() {
      // With multiple payment lines, change is derived from totalPaid vs amountDue
      return;
    },
    formatCurrency(value) {
      const num = Number(value);
      const isValid = !isNaN(num);
      const amount = isValid ? Math.abs(num) : 0;
      const dec = this.priceDecimals;
      const formattedNumber = new Intl.NumberFormat('en-US', {
        minimumFractionDigits: dec,
        maximumFractionDigits: dec
      }).format(amount);
      const sign = isValid && num < 0 ? '-' : '';
      return sign + withCurrency(this.currency, formattedNumber, useAuthStore().user?.currency_position);
    },
    addPaymentLine() {
      if (Number(this.paymentForm.amountDue) === 0) {
        this.makeToast('warning', this.$t ? this.$t('Zero_total_no_payment_required') : 'Zero total sale: no payment needed', this.$t ? this.$t('Warning') : 'Warning');
        return;
      }
      const defaultMethodId = this.getDefaultPaymentMethodId();
      const defaultAccountId = this.getDefaultAccountId();
      const line = this.newLine(0, defaultMethodId, defaultAccountId || this.paymentForm.accountId || '');
      this.paymentLines.push(line);
      this.ensureLineTerminal(this.paymentLines[this.paymentLines.length - 1]);
    },
    // Payment line shape; the terminal* fields are used by POS Devices lines.
    newLine(amount, paymentMethodId, accountId) {
      return {
        amount, paymentMethodId, accountId,
        terminalId: null, terminalTxn: null, terminalBusy: false, terminalVoiding: false,
        terminalCancelling: false, terminalLost: false, installments: 1,
      };
    },
    removePaymentLine(index) {
      // Never allow removing the last remaining line – there must always be at least one payment method
      if (!this.paymentLines || this.paymentLines.length <= 1) {
        return;
      }
      if (this.isLineTerminalLocked(this.paymentLines[index])) {
        this.makeToast('warning', this.$t('Pd_Line_Locked'), this.$t('Warning'));
        return;
      }
      // Remove the line
      this.paymentLines.splice(index, 1);
      // Destroy any Stripe element tied to this line and clear all to avoid index drift
      try { this.destroyCardElementForLine(index); } catch (e) { /* ignore */ }
      try { this.destroyAllCardElements && this.destroyAllCardElements(); } catch (e) { /* ignore */ }
    },
    setQuickAmount(amount) {
      if (Number(this.paymentForm.amountDue) === 0) {
        this.makeToast('warning', this.$t ? this.$t('Zero_total_no_payment_required') : 'Zero total sale: no payment needed', this.$t ? this.$t('Warning') : 'Warning');
        return;
      }
      if (!this.paymentLines.length) this.addPaymentLine();
      if (this.isLineTerminalLocked(this.paymentLines[0])) return;
      this.paymentLines[0].amount = Number(amount) || 0;
    },
    onAmountInput(idx) {
      const line = this.paymentLines && this.paymentLines[idx];
      if (!line) return;
      let val = line.amount;
      // Coerce to finite non-negative number with the configured precision (2 or 3)
      val = Number(val);
      if (!Number.isFinite(val) || val < 0) val = 0;
      // Avoid editing when zero due
      if (Number(this.paymentForm.amountDue) === 0) val = 0;
      const dec = this.priceDecimals;
      this.$set ? this.$set(this.paymentLines[idx], 'amount', Number(val.toFixed ? val.toFixed(dec) : val)) : (this.paymentLines[idx].amount = val);
    },
    async loadStripePayment(lineIndex) {
      try {
        if (!this.stripeKey) return;
        if (!this.stripe) {
          this.stripe = await loadStripe(`${this.stripeKey}`);
        }
        if (!this.stripe) return;
        const idx = typeof lineIndex === 'number' ? lineIndex : (this.activeCardLineIndex || 0);
        const containerId = `card-element-${idx}`;

        // Mount helper with small retry window so that when the modal is
        // re‑opened (or during transitions) the Stripe Element still mounts
        // once the DOM node is actually present.
        const tryMount = (attempt = 0) => {
          const container = document.getElementById(containerId);
          if (!container) {
            // Retry a few times in case the element isn't in the DOM yet
            if (attempt < 5) {
              setTimeout(() => tryMount(attempt + 1), 60);
            }
            return;
          }

          if (!this.cardElementsByLine[idx]) {
            const elements = this.stripe.elements();
            const element = elements.create('card', { hidePostalCode: true });
            element.mount(container);
            try {
              element.on && element.on('change', (event) => {
                this.isCardInputComplete = !!(event && event.complete);
              });
            } catch (e) { /* ignore */ }
            this.cardElementsByLine[idx] = element;
          }
        };

        tryMount();
      } catch (e) {
        // ignore mount errors
      }
    },
    destroyCardElementForLine(lineIndex) {
      try {
        const idx = typeof lineIndex === 'number' ? lineIndex : (this.activeCardLineIndex || 0);
        const el = this.cardElementsByLine && this.cardElementsByLine[idx];
        if (el && el.unmount) {
          el.unmount();
        }
        if (this.cardElementsByLine) {
          delete this.cardElementsByLine[idx];
        }
      } catch (e) { /* ignore */ }
    },
    destroyAllCardElements() {
      try {
        const keys = Object.keys(this.cardElementsByLine || {});
        keys.forEach(k => {
          const el = this.cardElementsByLine[k];
          if (el && el.unmount) {
            try { el.unmount(); } catch (e) { /* ignore */ }
          }
        });
        this.cardElementsByLine = {};
      } catch (e) { /* ignore */ }
    },
    //------------------------------ POS Devices (card terminals) -------------------------\\
    // The plan must include POS Devices: an unconditional request would show the
    // upgrade dialog (or the billing redirect) on every checkout for tenants without it.
    terminalsEnabled() {
      try {
        const summary = usePlanStore().summary;
        if (!summary || !summary.has_plan) return false;
        const f = summary.features && summary.features.pos_terminals;
        return !!(f && f.enabled);
      } catch (e) {
        return false;
      }
    },
    async loadTerminals() {
      if (!this.isOnline || !this.terminalsEnabled()) return;
      const wh = String(this.warehouseId || '');
      if (this.terminalsLoadedFor === wh && this.terminalsState === 'ready' && Date.now() - this.terminalsLoadedAt < 60000) return;
      this.terminalsState = 'loading';
      try {
        const r = await axios.get('pos-terminals/available', { params: { warehouse_id: wh } });
        this.terminals = (r && r.data && r.data.terminals) || [];
        this.terminalsLoadedFor = wh;
        this.terminalsLoadedAt = Date.now();
        this.terminalsState = 'ready';
        // Remember which methods are device-bound, so a line on one of them
        // is never paid "normally" while the list is loading / unavailable.
        this.boundMethodIds = [...new Set(this.terminals.map(d => String(d.payment_method_id)))];
        try { window.localStorage.setItem(TERMINAL_METHODS_KEY, JSON.stringify(this.boundMethodIds)); } catch (e) { /* ignore */ }
      } catch (e) {
        this.terminals = [];
        this.terminalsState = 'failed';
      }
      (this.paymentLines || []).forEach((line, idx) => {
        this.ensureLineTerminal(line);
        // A Stripe element mounted before we knew the method is device-bound.
        if (this.lineTerminals(line).length) this.destroyCardElementForLine(idx);
      });
    },
    methodTerminals(methodId) {
      if (methodId === null || methodId === undefined || methodId === '') return [];
      return (this.terminals || []).filter(d => String(d.payment_method_id) === String(methodId));
    },
    lineTerminals(line) {
      return line ? this.methodTerminals(line.paymentMethodId) : [];
    },
    // Bound to a device per the last list this computer saw (used while the list is not available).
    isBoundMethod(methodId) {
      return methodId !== null && methodId !== undefined && (this.boundMethodIds || []).includes(String(methodId));
    },
    terminalFor(line) {
      const list = this.lineTerminals(line);
      return list.find(d => d.id === line.terminalId) || list[0] || null;
    },
    ensureLineTerminal(line) {
      if (!line) return;
      const list = this.lineTerminals(line);
      if (!list.length) {
        line.terminalId = null;
        return;
      }
      if (list.some(d => d.id === line.terminalId)) return;
      let last = null;
      try { last = Number(window.localStorage.getItem(TERMINAL_LAST_KEY)); } catch (e) { last = null; }
      line.terminalId = (list.find(d => d.id === last) || list[0]).id;
    },
    isLineCharged(line) {
      return !!(line && line.terminalTxn && line.terminalTxn.id && line.terminalTxn.status === 'approved');
    },
    isLineTerminalLocked(line) {
      return !!(line && (line.terminalBusy || this.isLineCharged(line) || line.terminalLost));
    },
    // The device line has a transaction whose outcome we may not know (lost
    // connection, or it ended as "no answer" / cancelled on our side).
    canRecheck(line) {
      const txn = line && line.terminalTxn;
      return !!(txn && txn.id && !line.terminalBusy && (line.terminalLost || ['timeout', 'cancelled', 'failed'].includes(txn.status)));
    },
    terminalState(line) {
      if (line.terminalBusy) return 'busy';
      if (line.terminalLost) return 'timeout';
      return (line.terminalTxn && line.terminalTxn.status) || 'idle';
    },
    terminalStatusText(line) {
      const txn = line.terminalTxn;
      const device = this.terminalFor(line);
      if (line.terminalVoiding) return this.$t('Pd_Voiding');
      if (line.terminalBusy) return this.$t('Pd_Waiting_For_Card', { device: device ? device.name : '' });
      if (line.terminalLost) return this.$t('Pd_Lost_Track');
      if (!txn) return this.$t('Pd_Ready_To_Charge');
      if (txn.status === 'approved') {
        return [this.$t('Pd_Status_Approved'), txn.card_mask, txn.auth_code ? this.$t('Pd_Auth_Code') + ' ' + txn.auth_code : null]
          .filter(Boolean).join(' · ');
      }
      const label = {
        declined: 'Pd_Status_Declined', cancelled: 'Pd_Status_Cancelled', failed: 'Pd_Status_Failed', timeout: 'Pd_Status_Timeout',
      }[txn.status];
      return [label ? this.$t(label) : '', txn.message].filter(Boolean).join(': ');
    },
    // Items for devices that print the fiscal receipt (POS Devices > Full fiscal receipt).
    // Order-level discount / shipping / tax travel as the difference to sale_total.
    terminalReceiptItems() {
      const rate = this.docRate;
      return (this.details || []).map(d => {
        const qty = Number(d.quantity) || 0;
        const total = (Number(d.subtotal) || 0) * rate;
        return {
          name: String(d.name || d.product_name || '').slice(0, 191),
          code: d.code ? String(d.code).slice(0, 191) : null,
          quantity: qty,
          unit_price: qty ? Number((total / qty).toFixed(4)) : 0,
          total: Number(total.toFixed(2)),
          vat_rate: Number(d.tax_percent) || 0,
        };
      });
    },
    terminalError(e) {
      const data = e && (e.data || (e.response && e.response.data));
      if (data && data.errors) {
        const first = Object.values(data.errors)[0];
        if (Array.isArray(first) && first.length) return first[0];
      }
      return (data && data.message) || this.$t('Pd_Device_Error');
    },
    // How long the till follows one device operation before offering "Check again".
    terminalDeadlineMs(device) {
      return ((Number(device && device.timeout_seconds) || 120) + 60) * 1000;
    },
    async chargeLine(line) {
      const device = this.terminalFor(line);
      if (!device || line.terminalBusy || this.isLineCharged(line) || line.terminalLost || this.isSubmitting) return;
      if (!this.isOnline) {
        this.makeToast('warning', this.$t('Pd_Needs_Online'), this.$t('Warning'));
        return;
      }
      // Another line is already using this device.
      if ((this.paymentLines || []).some(l => l !== line && l.terminalBusy && l.terminalId === device.id)) {
        this.makeToast('warning', this.$t('Pd_Wait_For_Device'), this.$t('Warning'));
        return;
      }
      const amount = Number(Number(line.amount || 0).toFixed(this.priceDecimals));
      const due = Number(this.paymentForm.amountDue) || 0;
      if (!(amount > 0)) return;
      // No change can come back from a card: at most what the other lines leave to pay.
      const others = (this.paymentLines || []).filter(l => l !== line).reduce((s, l) => s + (Number(l.amount) || 0), 0);
      if (amount > Math.max(0, due - others) + 0.0001) {
        this.makeToast('warning', this.$t('Pd_Charge_Exceeds_Due'), this.$t('Warning'));
        return;
      }
      try { window.localStorage.setItem(TERMINAL_LAST_KEY, String(device.id)); } catch (e) { /* ignore */ }

      line.terminalBusy = true;
      line.terminalLost = false;
      line.terminalTxn = null;
      try {
        const txn = await chargeOnTerminal(device.id, {
          amount,
          currency_id: this.currencyId || null,
          sale_uuid: this.ensureSaleUuid(),
          warehouse_id: this.warehouseId || null,
          installments: line.installments || 1,
          sale_total: due,
          items: device.fiscal_mode === 'full_receipt' ? this.terminalReceiptItems() : undefined,
        }, this.followOpts(line, device));
        this.afterFollow(line, txn);
      } catch (e) {
        this.followFailed(line, e);
      } finally {
        line.terminalBusy = false;
        line.terminalCancelling = false;
      }
    },
    followOpts(line, device) {
      return {
        onUpdate: (tx) => { line.terminalTxn = tx; },
        stopped: () => this.terminalStop,
        deadlineMs: this.terminalDeadlineMs(device),
      };
    },
    afterFollow(line, txn) {
      line.terminalTxn = txn;
      line.terminalLost = !!(txn && txn.status === 'pending');
      if (txn && txn.status === 'approved') {
        this.makeToast('success', this.$t('Pd_Charge_Approved'), this.$t('Success'));
      } else if (txn && txn.status !== 'pending') {
        this.makeToast('danger', this.terminalStatusText(line), this.$t('Failed'));
      }
    },
    // Once the device has the payment, never forget it: keep the transaction
    // and offer "Check again" instead of a fresh charge (no double charging).
    followFailed(line, e) {
      if (e && e.transaction) line.terminalTxn = e.transaction;
      if (line.terminalTxn && line.terminalTxn.id) {
        line.terminalLost = true;
        this.makeToast('warning', this.$t('Pd_Lost_Track'), this.$t('Warning'));
      } else {
        line.terminalTxn = null;
        this.makeToast('danger', this.terminalError(e), this.$t('Failed'));
      }
    },
    // "Check again": resume a lost / timed-out device payment.
    async recheckLine(line) {
      const txn = line.terminalTxn;
      if (!txn || !txn.id || line.terminalBusy) return;
      const device = this.terminalFor(line);
      line.terminalBusy = true;
      try {
        const result = await recheckTransaction(txn.id, this.followOpts(line, device));
        line.terminalLost = false;
        this.afterFollow(line, result);
      } catch (e) {
        this.followFailed(line, e);
      } finally {
        line.terminalBusy = false;
      }
    },
    async cancelLineCharge(line) {
      const txn = line.terminalTxn;
      if (!txn || !txn.id || line.terminalCancelling) return;
      line.terminalCancelling = true;
      try {
        // The charge loop picks up the outcome (a card that went through
        // before the cancel still ends approved).
        await cancelTransaction(txn.id);
      } catch (e) {
        line.terminalCancelling = false;
        this.makeToast('danger', this.terminalError(e), this.$t('Failed'));
      }
    },
    // Voids the line's charge. True when nothing is left to void (voided, or
    // the charge already belongs to a saved sale / was reversed elsewhere).
    async voidLineCharge(line, silent = false) {
      const txn = line.terminalTxn;
      if (!this.isLineCharged(line)) return true;
      if (line.terminalBusy || this.isSubmitting) return false;
      if (!silent && !window.confirm(this.$t('Pd_Void_Charge_Confirm', { amount: this.formatCurrency(txn.amount) }))) return false;
      line.terminalBusy = true;
      line.terminalVoiding = true;
      try {
        // A sale may already hold this charge (its response got lost): never void that.
        const current = await fetchTransaction(txn.id).catch(() => null);
        if (current && (current.linked || Number(current.reversed) >= Number(current.amount) - 0.0001)) {
          line.terminalTxn = null;
          return true;
        }
        const v = await voidTransaction(txn.id, { stopped: () => this.terminalStop, deadlineMs: this.terminalDeadlineMs(this.terminalFor(line)) });
        if (v && v.status === 'approved') {
          line.terminalTxn = null;
          this.makeToast('success', this.$t('Pd_Void_Done'), this.$t('Success'));
          return true;
        }
        this.makeToast('danger', (v && v.message) || this.$t('Pd_Void_Failed'), this.$t('Failed'));
      } catch (e) {
        this.makeToast('danger', this.terminalError(e), this.$t('Failed'));
      } finally {
        line.terminalBusy = false;
        line.terminalVoiding = false;
      }
      return false;
    },
    // Message key when a device line can't be submitted yet, else null.
    terminalLineIssue() {
      for (const line of this.paymentLines || []) {
        if (!(Number(line.amount) > 0)) continue;
        if (!this.lineTerminals(line).length) {
          // Device-bound method but the device list is not here (yet).
          if (this.terminalsEnabled() && this.isBoundMethod(line.paymentMethodId) && this.terminalsState !== 'ready') {
            return this.terminalsState === 'loading' ? 'Pd_Devices_Loading' : 'Pd_Devices_Unavailable';
          }
          continue;
        }
        if (line.terminalBusy) return 'Pd_Wait_For_Device';
        if (line.terminalLost) return 'Pd_Lost_Track';
        if (!this.isLineCharged(line)) return 'Pd_Charge_First';
        if (Math.abs(Number(line.terminalTxn.amount) - Number(line.amount)) > 0.005) return 'Pd_Amount_Changed';
      }
      return null;
    },
    // Close (×, Cancel): a charged device line is voided first so no card
    // payment is left behind without a sale.
    async requestClose() {
      if (this.isSubmitting) {
        this.makeToast('warning', this.$t('Pd_Saving_Sale'), this.$t('Warning'));
        return;
      }
      if ((this.paymentLines || []).some(l => l.terminalBusy)) {
        this.makeToast('warning', this.$t('Pd_Wait_For_Device'), this.$t('Warning'));
        return;
      }
      const lost = (this.paymentLines || []).some(l => l.terminalLost);
      if ((this.paymentLines || []).some(l => this.isLineCharged(l))) {
        if (!window.confirm(this.$t('Pd_Void_Before_Close'))) return;
        if (!(await this.voidChargedLines())) {
          // Could not void (device offline, batch closed…): the cashier may
          // still close; the charge stays listed as charged without a sale.
          if (!window.confirm(this.$t('Pd_Close_Anyway'))) return;
        }
      } else if (lost && !window.confirm(this.$t('Pd_Close_Anyway'))) {
        return;
      }
      this.$refs.paymentModal && this.$refs.paymentModal.hide && this.$refs.paymentModal.hide();
    },
    // Voids every line charged on a device; false if one could not be voided.
    async voidChargedLines() {
      for (const line of (this.paymentLines || []).filter(l => this.isLineCharged(l))) {
        if (!(await this.voidLineCharge(line, true))) return false;
      }
      return true;
    },
    // Leaving / reloading the page with a card charged or a device busy.
    onTerminalBeforeUnload(e) {
      if (!this.hasTerminalActivity) return undefined;
      e.preventDefault();
      e.returnValue = '';
      return '';
    },

    // Keep template hook but delegate to CreatePOS to mirror old logic
    async submitPayment() {
      await this.CreatePOS();
    },

    //-------------------------------- Invoice POS (mirror old) ------------------------------\\
    async Invoice_POS(id) {
      try {
        if (typeof NProgress !== 'undefined') {
          NProgress.start();
          NProgress.set(0.1);
        }
        const response = await axios.get(`sales_print_invoice/${id}`);
        const posSettings = (response.data && response.data.pos_settings) ? response.data.pos_settings : {};
        const invoicePayload = {
          invoice: response.data,
          payments: response.data && response.data.payments ? response.data.payments : [],
          pos_settings: posSettings
        };
        // Emit upwards so parent can decide how to present invoice
        this.$emit('invoice-ready', invoicePayload);
        if (typeof NProgress !== 'undefined') {
          NProgress.done();
        }
        // Directly trigger print dialog without showing any modal.
        // If Direct Network Printing is enabled, send straight to the
        // configured network printer and skip the browser print popup.
        setTimeout(() => this.print_pos(id, posSettings), 300);
      } catch (e) {
        if (typeof NProgress !== 'undefined') {
          setTimeout(() => NProgress.done(), 300);
        }
      }
    },

    // Try Direct Network Printing (RAW/port 9100) when enabled in settings.
    // Returns Promise resolving to:
    //   { ok: true }                       — receipt dispatched to printer
    //   { ok: false, configured: false }   — DNP off / no IP / no saleId
    //   { ok: false, configured: true, message } — DNP attempt failed
    tryDirectNetworkPrint(saleId, posSettings) {
      try {
        const ps = posSettings || {};
        const enabled = ps.direct_network_printing === true || ps.direct_network_printing === 1 || ps.direct_network_printing === '1';
        const ip = (ps.network_printer_ip || '').toString().trim();
        if (!enabled || !ip || !saleId) return Promise.resolve({ ok: false, configured: false });
        return axios.post('direct_network_print/' + saleId)
          .then((response) => {
            if (response && response.data && response.data.success) return { ok: true };
            const message = (response && response.data && response.data.message) || (this.$t ? this.$t('Network_Printer_Error') : 'Network printer error');
            return { ok: false, configured: true, message };
          })
          .catch((err) => {
            const message = (err && err.response && err.response.data && err.response.data.message) || (err && err.message) || (this.$t ? this.$t('Network_Printer_Unreachable') : 'Network printer unreachable');
            return { ok: false, configured: true, message };
          });
      } catch (e) {
        return Promise.resolve({ ok: false, configured: false });
      }
    },

    //------------------------------ Print -------------------------\\
    // When DNP is enabled, always use the network printer and surface a
    // toast on failure. Never fall back to the browser print popup,
    // since the user explicitly opted into direct printing.
    print_pos(saleId, posSettings) {
      const ps = posSettings || {};
      const dnpEnabled = ps.direct_network_printing === true || ps.direct_network_printing === 1 || ps.direct_network_printing === '1';
      if (dnpEnabled && saleId) {
        this.tryDirectNetworkPrint(saleId, ps).then((result) => {
          if (result && result.ok) {
            this.makeToast && this.makeToast(
              'success',
              this.$t ? this.$t('Sent_to_network_printer') || 'Sent to network printer' : 'Sent to network printer',
              this.$t ? this.$t('Success') : 'Success'
            );
            return;
          }
          if (result && result.configured) {
            this.makeToast && this.makeToast(
              'danger',
              (this.$t ? this.$t('Network_print_failed') || 'Network print failed' : 'Network print failed') + ': ' + (result.message || ''),
              this.$t ? this.$t('Failed') : 'Failed'
            );
            return;
          }
          this.printBrowserFallback(ps);
        });
        return;
      }
      this.printBrowserFallback(ps);
    },

    printBrowserFallback(posSettings) {
      var el = document.getElementById('invoice-POS');
      if (!el) { return; }
      var divContents = el.innerHTML;
      var a = window.open('', '', 'height=600, width=480');
      if (!a) { return; }
      // Custom receipt font (Settings → System → POS Receipt); this popup
      // writes the receipt innerHTML directly into <body>, hence body scope.
      a.document.write('<html><head><link rel="stylesheet" href="/css/pos_print.css">' + receiptPageHeadTag(posSettings) + receiptFontHeadTags(posSettings, 'body') + '</head><body>');
      a.document.write(divContents);
      a.document.write('</body></html>');
      a.document.close();
      const reloadParent = () => {
        try { window.removeEventListener('focus', reloadParent); } catch(e) {}
        try { a.close(); } catch(e) {}
      };
      try { window.addEventListener('focus', reloadParent); } catch(e) {}
      try { a.onafterprint = reloadParent; } catch(e) {}
      // Wait for the receipt webfont, the print stylesheet AND the logo /
      // QR images before snapshotting (see lib/receiptFont whenPrintReady).
      whenPrintReady(a, () => {
        try { a.focus(); } catch(e) {}
        try { a.print(); } catch(e) { reloadParent(); }
      });
    },

    //----------------------------------Process Payment (mirror old) ------------------------------\\
    ensureSaleUuid() {
      if (this.currentSaleUuid) {
        return this.currentSaleUuid;
      }

      let uuid = null;

      try {
        if (typeof window !== 'undefined' && window.crypto && typeof window.crypto.randomUUID === 'function') {
          uuid = window.crypto.randomUUID();
        }
      } catch (e) {
        uuid = null;
      }

      if (!uuid) {
        uuid = 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => {
          const r = Math.random() * 16 | 0;
          const v = c === 'x' ? r : (r & 0x3 | 0x8);
          return v.toString(16);
        });
      }

      this.currentSaleUuid = uuid;
      return uuid;
    },

    async processPayment() {
      const saleUuid = this.ensureSaleUuid();
      this.paymentProcessing = true;
      this.isSubmitting = true;
      try {
        // Validate Stripe configuration and UI readiness
        if (!this.stripeKey) {
          this.paymentProcessing = false;
          this.isSubmitting = false;
          if (typeof NProgress !== 'undefined') NProgress.done();
          this.makeToast('danger', this.$t ? this.$t('credit_card_account_not_available') : 'Credit card not available', this.$t ? this.$t('Failed') : 'Failed');
          return;
        }
        if (!this.stripe) {
          this.paymentProcessing = false;
          this.isSubmitting = false;
          if (typeof NProgress !== 'undefined') NProgress.done();
          this.makeToast('danger', this.$t ? this.$t('Failed_to_load') : 'Failed to load.', this.$t ? this.$t('Failed') : 'Failed');
          return;
        }
        // Determine which lines require a new card (not saved)
        const newCardIndices = (this.paymentLines || []).map((line, i) => {
          const needsNew = this.isLineCreditCard(line);
          return needsNew ? i : null;
        }).filter(i => i !== null);

        // Validate that each such line has a mounted element
        const missing = newCardIndices.find(i => !this.cardElementsByLine || !this.cardElementsByLine[i]);
        if (missing !== undefined) {
          this.paymentProcessing = false;
          this.isSubmitting = false;
          if (typeof NProgress !== 'undefined') NProgress.done();
          this.makeToast('danger', this.$t ? this.$t('Please_enter_credit_card_details') : 'Please enter credit card details', this.$t ? this.$t('Warning') : 'Warning');
          return;
        }

        // Generate tokens for all new-card lines
        // IMPORTANT: Some Stripe integrations don't set event.complete reliably across all browsers/locales
        // We proceed to tokenization and rely on Stripe to validate completeness
        const tokenResults = await Promise.all(newCardIndices.map(async (i) => {
          try {
            return await this.stripe.createToken(this.cardElementsByLine[i]);
          } catch (e) {
            return { error: e };
          }
        }));
        const tokensByLine = {};
        for (let idx = 0; idx < tokenResults.length; idx++) {
          const lineKey = newCardIndices[idx];
          const res = tokenResults[idx] || {};
          if (res.error) {
            this.paymentProcessing = false;
            this.isSubmitting = false;
            if (typeof NProgress !== 'undefined') NProgress.done();
            this.makeToast('danger', this.$t ? this.$t('InvalidData') : 'Invalid data', this.$t ? this.$t('Failed') : 'Failed');
            return;
          }
          if (res.token && res.token.id) {
            tokensByLine[lineKey] = res.token.id;
          }
        }
        // Backward compatibility single token: prefer active line's token if any, else first
        let token = null;
        if (this.activeCardLineIndex != null && tokensByLine[this.activeCardLineIndex]) {
          token = { id: tokensByLine[this.activeCardLineIndex] };
        } else {
          const firstIdx = newCardIndices && newCardIndices.length ? newCardIndices[0] : null;
          if (firstIdx != null && tokensByLine[firstIdx]) {
            token = { id: tokensByLine[firstIdx] };
          }
        }

        const payload = {
          client_id: this.clientId,
          seller_id: this.sellerId || undefined,
          sales_agent_id: this.salesAgentId || undefined,
          warehouse_id: this.warehouseId,
          tax_rate: this.sale && this.sale.tax_rate ? this.sale.tax_rate : 0,
          TaxNet: this.sale && this.sale.TaxNet ? this.sale.TaxNet : 0,
          discount: this.sale && this.sale.discount ? this.sale.discount : 0,
          // Ensure order-level discount method is sent: '1' = percent, '2' = fixed (default)
          discount_Method: this.sale && this.sale.discount_Method
            ? String(this.sale.discount_Method)
            : '2',
          shipping: this.sale && this.sale.shipping ? this.sale.shipping : 0,
          details: this.details,
          GrandTotal: this.grandTotal || (this.paymentForm.amountDue / this.docRate) || (this.totalPaid / this.docRate),
          // Multi-Currency snapshot (nulls when base — server treats as base)
          currency_id: this.currencyId || null,
          exchange_rate: this.currencyId ? this.docRate : null,
          // Multi-payment array with optional per-line account and saved card.
          // Tender is typed in the POS-selected currency; the server stores base.
          payments: (this.paymentLines || []).map((l) => ({
            amount: (Number(l.amount) || 0) / this.docRate,
            payment_method_id: l.paymentMethodId,
            // Customer Wallet lines never land in an account.
            account_id: this.isLineWallet(l) ? null : (l.accountId || this.paymentForm.accountId || null),
            terminal_transaction_id: this.isLineCharged(l) ? l.terminalTxn.id : undefined
          })),
          send_email: this.sendEmail,
          send_sms: this.sendSMS,
          account_id: this.paymentForm.accountId,
          payment_note: this.paymentNote || '',
          token: token && token.id ? token.id : null,
          is_new_credit_card: this.hasAnyNewCardToTokenize(),
          card_tokens_by_line: tokensByLine,
          discount_from_points: this.discountFromPoints || 0,
          used_points: this.usedPoints || 0,
          // Offline-held (local) draft ids never go to the server; the parent
          // deletes the local record on payment-success.
          draft_sale_id: (this.draftSaleId && !(Util && Util.offlinePos && Util.offlinePos.isLocalId && Util.offlinePos.isLocalId(this.draftSaleId)))
            ? this.draftSaleId
            : null,
          sale_uuid: saleUuid || null
        };

        const response = await axios.post(this.createPosUrl, payload);
        this.paymentProcessing = false;
        this.isSubmitting = false;
        if (response && response.data && response.data.success === true) {
          if (typeof NProgress !== 'undefined') NProgress.done();
          // Prefer parent's Invoice_POS if available (ensures existing invoice modal/printing works)
          if (this.$parent && typeof this.$parent.Invoice_POS === 'function') {
            await this.$parent.Invoice_POS(response.data.id);
          } else {
            await this.Invoice_POS(response.data.id);
          }
          this.$emit('payment-success', {
            id: response.data.id,
            payload,
            updated_stock: response.data.updated_stock,
            server_time: response.data.server_time,
            change: this.changeReturn
          });
          this.$refs.paymentModal && this.$refs.paymentModal.hide && this.$refs.paymentModal.hide();
        } else {
          if (typeof NProgress !== 'undefined') NProgress.done();
          this.makeToast('danger', this.$t ? this.$t('InvalidData') : 'Invalid data', this.$t ? this.$t('Failed') : 'Failed');
        }
      } catch (e) {
        this.paymentProcessing = false;
        this.isSubmitting = false;
        if (typeof NProgress !== 'undefined') NProgress.done();
        if (this.handleUnavailableProducts(e)) return;
        this.makeToast('danger', this.$t ? this.$t('InvalidData') : 'Invalid data', this.$t ? this.$t('Failed') : 'Failed');
      }
    },

    // The server refused products deleted / marked "Not for sale" since they
    // were added: say which, close, and let the POS drop those lines (the
    // totals change, so the cashier re-opens payment). True if handled.
    handleUnavailableProducts(error) {
      const data = error && error.response && error.response.data;
      const ids = data && Array.isArray(data.unavailable_product_ids) ? data.unavailable_product_ids : null;
      if (!ids || !ids.length) return false;
      this.makeToast('danger', data.message || (this.$t ? this.$t('InvalidData') : 'Invalid data'), this.$t ? this.$t('Failed') : 'Failed');
      this.$emit('unavailable-products', ids);
      const hide = () => { try { this.$refs.paymentModal && this.$refs.paymentModal.hide && this.$refs.paymentModal.hide(); } catch (e) { /* ignore */ } };
      // The total changes, so a card already charged on a device is voided
      // first; if that fails the modal stays open so the cashier can retry.
      if ((this.paymentLines || []).some(l => this.isLineCharged(l))) {
        this.voidChargedLines().then(ok => { if (ok) hide(); });
      } else {
        hide();
      }
      return true;
    },

    //----------------------------------Create POS (mirror old, with offline queue) ------------------------------\\
    async CreatePOS() {
      if (typeof NProgress !== 'undefined') {
        NProgress.start();
        NProgress.set(0.1);
      }

      const saleUuid = this.ensureSaleUuid();

      // Basic validations: allow 0 total only if exactly one payment line
      const zeroTotal = this.totalPaid <= 0;
      if (!this.paymentLines.length || (zeroTotal && this.paymentLines.length > 1)) {
        if (typeof NProgress !== 'undefined') NProgress.done();
        this.makeToast('danger', this.$t ? this.$t('InvalidData') : 'Invalid data', this.$t ? this.$t('Failed') : 'Failed');
        return;
      }
      // Account selection is optional per line; proceed even if unset

      // POS Devices: every device line must be charged (and still match) first.
      const terminalIssue = this.terminalLineIssue();
      if (terminalIssue) {
        if (typeof NProgress !== 'undefined') NProgress.done();
        this.makeToast('warning', this.$t(terminalIssue), this.$t('Warning'));
        return;
      }

      // Validate multi-payment overpay like old POS
      const total = parseFloat(this.totalPaid);
      const due = parseFloat((this.paymentForm.amountDue || this.grandTotal || 0).toFixed(this.priceDecimals));
      const multi = this.paymentLines.length > 1;
      if (multi && total > due) {
        this.makeToast(
          'warning',
          this.$t ? this.$t('TotalPaidExceedsGrandTotalForMultiPayment') : 'Total paid exceeds grand total for multiple payments',
          this.$t ? this.$t('Warning') : 'Warning'
        );
        if (typeof NProgress !== 'undefined') NProgress.done();
        return;
      }

      // Credit Limit Validation (0 means no limit)
      // Only applies when this sale is adding new credit (paid amount < sale total)
      if (this.clientId && Number(this.clientCreditLimit || 0) > 0 && total < due) {
        // Credit limits live in the base currency — convert the doc-currency
        // shortfall before comparing.
        const currentDue = parseFloat(this.clientNetBalance || 0);
        const newSaleDue = (due - total) / this.docRate; // Remaining due from this sale
        const newTotalDue = currentDue + newSaleDue;

        if (newTotalDue > Number(this.clientCreditLimit)) {
          if (typeof NProgress !== 'undefined') NProgress.done();
          const exceededAmount = newTotalDue - Number(this.clientCreditLimit);
          const t = this.$t ? this.$t.bind(this) : (k => k);
          // Credit limits are base-currency values; convert for display since
          // formatCurrency renders with the POS-selected currency symbol.
          this.makeToast(
            'danger',
            t('Credit_Limit_Exceeded') + ': ' +
              this.formatCurrency(exceededAmount * this.docRate) + ' ' +
              t('exceeds_credit_limit_of') + ' ' +
              this.formatCurrency(Number(this.clientCreditLimit) * this.docRate),
            this.$t ? this.$t('Warning') : 'Warning'
          );
          return;
        }
      }

      const buildPayload = () => {
        // Ensure each detail line carries a sale_unit_id so offline sync can rely on it
        const normalizedDetails = (this.details || []).map(d => ({
          ...d,
          sale_unit_id:
            d && d.sale_unit_id !== undefined && d.sale_unit_id !== null && d.sale_unit_id !== ''
              ? d.sale_unit_id
              : d && d.sale_unit_id
        }));

        return {
          client_id: this.clientId,
          seller_id: this.sellerId || undefined,
          sales_agent_id: this.salesAgentId || undefined,
          warehouse_id: this.warehouseId,
          tax_rate: this.sale && this.sale.tax_rate ? this.sale.tax_rate : 0,
          TaxNet: this.sale && this.sale.TaxNet ? this.sale.TaxNet : 0,
          discount: this.sale && this.sale.discount ? this.sale.discount : 0,
          // Ensure order-level discount method is sent: '1' = percent, '2' = fixed (default)
          discount_Method: this.sale && this.sale.discount_Method
            ? String(this.sale.discount_Method)
            : '2',
          shipping: this.sale && this.sale.shipping ? this.sale.shipping : 0,
          notes: this.saleNote || (this.sale && this.sale.notes) || '',
          details: normalizedDetails,
          GrandTotal: this.grandTotal || (this.paymentForm.amountDue / this.docRate) || (total / this.docRate),
          // Multi-Currency snapshot (nulls when base — server treats as base)
          currency_id: this.currencyId || null,
          exchange_rate: this.currencyId ? this.docRate : null,
          // Multi-payment array including per-line account (no global).
          // Tender is typed in the POS-selected currency; the server stores base.
          payments: (this.paymentLines || []).map((l) => ({
            amount: (Number(l.amount) || 0) / this.docRate,
            payment_method_id: l.paymentMethodId,
            account_id: this.isLineWallet(l) ? null : (l.accountId || null),
            terminal_transaction_id: this.isLineCharged(l) ? l.terminalTxn.id : undefined
          })),
          send_email: this.sendEmail,
          send_sms: this.sendSMS,
          // No global account_id
          payment_note: this.paymentNote || '',
          discount_from_points: this.discountFromPoints || 0,
          used_points: this.usedPoints || 0,
          // Offline-held (local) draft ids never go to the server; the parent
          // deletes the local record on payment-success.
          draft_sale_id: (this.draftSaleId && !(Util && Util.offlinePos && Util.offlinePos.isLocalId && Util.offlinePos.isLocalId(this.draftSaleId)))
            ? this.draftSaleId
            : null,
          sale_uuid: saleUuid || null,
          // Kitchen routing: only 'send' creates a kitchen ticket on the server (and only
          // when the feature is enabled). 'none' / 'later' leave the sales flow untouched.
          kitchen_action: this.kitchenEnabled ? this.kitchenAction : 'none',
          ...(this.promotionContext || {})
        };
      };

      // Prefer explicit POS online state from parent; fall back to navigator.
      let isOnline = this.isOnline !== false
        ? true
        : ((typeof window === 'undefined' || !window.navigator)
            ? true
            : window.navigator.onLine !== false);
      const usingCard = this.anyCreditCardUsed;

      // A customer created offline only exists locally until the sync pushes
      // it; the server would reject its temporary id. Treat such sales as
      // offline (queue them) even when the connection is back — the sync
      // remaps the client id and submits them in order.
      const clientIsLocal = !!(Util && Util.offlinePos && Util.offlinePos.isLocalId && Util.offlinePos.isLocalId(this.clientId));
      if (clientIsLocal) {
        if (usingCard) {
          if (typeof NProgress !== 'undefined') NProgress.done();
          this.paymentProcessing = false;
          this.isSubmitting = false;
          const msg = this.$t
            ? (this.$t('pos.CreditCard_Requires_Online') || 'Credit card payments require an internet connection.')
            : 'Credit card payments require an internet connection.';
          this.makeToast && this.makeToast('warning', msg, this.$t ? this.$t('Warning') : 'Warning');
          return;
        }
        isOnline = false;
      }

      // If we are offline and no credit card is involved, queue sale locally instead of calling API
      if (!isOnline && !usingCard) {
        const payload = buildPayload();
        if (typeof NProgress !== 'undefined') NProgress.done();
        this.paymentProcessing = false;
        this.isSubmitting = false;
        this.queueOfflineSale(payload);
        return;
      }

      // If offline but card is involved, block and warn
      if (!isOnline && usingCard) {
        if (typeof NProgress !== 'undefined') NProgress.done();
        this.paymentProcessing = false;
        this.isSubmitting = false;
        const msg = this.$t
          ? (this.$t('pos.CreditCard_Requires_Online') || 'Credit card payments require an internet connection.')
          : 'Credit card payments require an internet connection.';
        this.makeToast && this.makeToast('warning', msg, this.$t ? this.$t('Warning') : 'Warning');
        return;
      }

      // Determine if any line requires a new card entry (no saved card selected)
      const anyNewCard = this.hasAnyNewCardToTokenize();

      if (anyNewCard) {
        if (!this.stripeKey) {
          this.makeToast('danger', this.$t ? this.$t('credit_card_account_not_available') : 'Credit card not available', this.$t ? this.$t('Failed') : 'Failed');
          if (typeof NProgress !== 'undefined') NProgress.done();
          return;
        }
        if (!this.stripe) {
          this.makeToast('danger', this.$t ? this.$t('Failed_to_load') : 'Failed to load.', this.$t ? this.$t('Failed') : 'Failed');
          if (typeof NProgress !== 'undefined') NProgress.done();
          return;
        }
        // Ensure at least one card element exists for any card lines requiring new card entry
        const needsNewCard = Object.keys(this.cardElementsByLine || {}).some(i => {
          const li = Number(i);
          const line = (this.paymentLines || [])[li];
          return this.isLineCreditCard(line);
        });
        if (!needsNewCard) {
          this.makeToast('danger', this.$t ? this.$t('Please_enter_credit_card_details') : 'Please enter credit card details', this.$t ? this.$t('Warning') : 'Warning');
          if (typeof NProgress !== 'undefined') NProgress.done();
          return;
        }
        await this.processPayment();
        return;
      }

      // Non-card / saved card path
      this.paymentProcessing = true;
      this.isSubmitting = true;
      try {
        const payload = buildPayload();
        const response = await axios.post(this.createPosUrl, payload);
        if (response && response.data && response.data.success === true) {
          if (typeof NProgress !== 'undefined') NProgress.done();
          this.paymentProcessing = false;
          this.isSubmitting = false;
          // Prefer parent's Invoice_POS if available (ensures existing invoice modal/printing works)
          if (this.$parent && typeof this.$parent.Invoice_POS === 'function') {
            await this.$parent.Invoice_POS(response.data.id);
          } else {
            await this.Invoice_POS(response.data.id);
          }
          this.$emit('payment-success', {
            id: response.data.id,
            payload,
            updated_stock: response.data.updated_stock,
            server_time: response.data.server_time,
            change: this.changeReturn
          });
          this.$refs.paymentModal && this.$refs.paymentModal.hide && this.$refs.paymentModal.hide();
        } else {
          if (typeof NProgress !== 'undefined') NProgress.done();
          this.paymentProcessing = false;
          this.isSubmitting = false;
          this.makeToast('danger', this.$t ? this.$t('InvalidData') : 'Invalid data', this.$t ? this.$t('Failed') : 'Failed');
        }
      } catch (error) {
        if (typeof NProgress !== 'undefined') NProgress.done();
        this.paymentProcessing = false;
        this.isSubmitting = false;
        const isNetworkError = !error.response || error.message === 'Network Error';
        // In this non-card branch, true offline handling is already covered by the
        // explicit (!isOnline && !usingCard) path above. Here we are effectively
        // in "online" mode, so do NOT silently queue a new offline sale; instead
        // surface an error toast so the cashier can react.
        if (isNetworkError) {
          const msg = this.$t
            ? (this.$t('pos.NetworkError') || 'Network error, please try again.')
            : 'Network error, please try again.';
          this.makeToast && this.makeToast('danger', msg, this.$t ? this.$t('Failed') : 'Failed');
        } else if (this.handleUnavailableProducts(error)) {
          // handled: message shown, lines dropped by the POS
        } else if (this.hasTerminalActivity && error.response && error.response.status === 422) {
          // A device payment was refused by the server: say why.
          this.makeToast('danger', this.terminalError(error), this.$t ? this.$t('Failed') : 'Failed');
        } else {
          this.makeToast('danger', this.$t ? this.$t('InvalidData') : 'Invalid data', this.$t ? this.$t('Failed') : 'Failed');
        }
      }
    },
    queueOfflineSale(payload) {
      let record = null;
      try {
        if (Util && Util.offlinePos && Util.offlinePos.addOfflineSale) {
          record = Util.offlinePos.addOfflineSale(payload);
        }
      } catch (e) {}
      // Not persisted (browser storage full / blocked): the sale exists nowhere,
      // so keep the modal and cart open and say so instead of "saved offline".
      if (!record) {
        const fallback = 'This sale could not be saved offline because the browser storage is full. It was NOT recorded — please try again.';
        // $t returns the key itself on a tenant not yet re-seeded with it.
        const translated = this.$t ? this.$t('pos.Offline_Sale_Not_Saved') : '';
        const msg = translated && translated !== 'pos.Offline_Sale_Not_Saved' ? translated : fallback;
        this.makeToast && this.makeToast('danger', msg, this.$t ? this.$t('Failed') : 'Failed');
        return;
      }
      // Record shadow stock deductions in IndexedDB for this offline sale (best-effort)
      try {
        const offlineId = record && record.id ? record.id : (payload && payload.offline_id) || null;
        if (offlineId && Util && Util.shadowStock && Util.shadowStock.recordDeductions) {
          Util.shadowStock.recordDeductions(payload.warehouse_id, offlineId, payload.details || []);
        }
      } catch (e) {}
      try {
        const msg = this.$t
          ? (this.$t('pos.Offline_Sale_Saved') || 'Sale saved offline. It will sync when you are back online.')
          : 'Sale saved offline. It will sync when you are back online.';
        this.makeToast && this.makeToast('success', msg, this.$t ? this.$t('Success') : 'Success');
      } catch (e) {}
      this.$emit('payment-success', {
        id: null,
        payload,
        offline: true,
        offlineId: record && record.id ? record.id : null,
        change: this.changeReturn
      });
      this.$refs.paymentModal && this.$refs.paymentModal.hide && this.$refs.paymentModal.hide();
    },
    resetForm() {
      this.paymentForm = {
        amountDue: this.docGrandTotal || 0,
        accountId: '',
        date: this.getCurrentDateInTimezone(),
        reference: '',
        notes: ''
      };
      // Clear lines and any per-line state
      this.paymentLines = [];
      this.destroyAllCardElements && this.destroyAllCardElements();
      this.activeCardLineIndex = 0;
      this.sendEmail = false;
      this.sendSMS = false;
      this.paymentNote = '';
      this.saleNote = '';
      this.kitchenAction = 'none';
      this.isCardInputComplete = false;
      this.isSubmitting = false;
      this.currentSaleUuid = null;
    },
    openModal(data = {}) {
      if (data.id) {
        this.isEditMode = true;
        // Load edit data
        Object.assign(this.paymentForm, data);
      } else {
        this.isEditMode = false;
        // Set payment date to current date in configured timezone
        this.paymentForm.date = this.getCurrentDateInTimezone();
        if (data.reference) {
          this.paymentForm.reference = data.reference;
        }
        if (data.notes) {
          this.paymentForm.notes = data.notes;
        }
      }
      // Derive amountDue (in the POS-selected currency): prefer explicit
      // data, then the converted grandTotal prop, else 0.
      if (Object.prototype.hasOwnProperty.call(data, 'amountDue') && data.amountDue !== undefined && data.amountDue !== null) {
        this.paymentForm.amountDue = Number(data.amountDue) || 0;
      } else {
        this.paymentForm.amountDue = this.docGrandTotal || 0;
      }
      // Initialize one payment line prefilled with amount due; use system defaults when available
      this.paymentLines = [];
      this.destroyAllCardElements && this.destroyAllCardElements();
      this.activeCardLineIndex = 0;
      const defaultMethodId = this.getDefaultPaymentMethodId();
      const defaultAccountId = this.getDefaultAccountId();
      this.paymentForm.accountId = defaultAccountId;
      this.paymentLines.push(this.newLine(Number(this.paymentForm.amountDue || 0), defaultMethodId, defaultAccountId));
      this.ensureLineTerminal(this.paymentLines[0]);
      this.loadTerminals();
      // Fresh wallet balance every time the modal opens (it may have moved since).
      this.fetchWalletBalance();
      this.paymentNote = '';
      this.saleNote = '';
      this.kitchenAction = 'none';
      this.sendEmail = false;
      this.sendSMS = false;
      this.$refs.paymentModal.show();
    }
  }
};
</script>

<style lang="scss">
/* ========================================
   PREMIUM PAYMENT MODAL DESIGN
   ======================================== */

.kitchen-routing {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-top: 6px;
}
.kitchen-routing-option {
  flex: 1 1 30%;
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 8px 10px;
  border: 1px solid #d9dee3;
  border-radius: 6px;
  cursor: pointer;
  font-size: 0.85rem;
  margin-bottom: 0;
  transition: all 0.15s ease;

  &.active {
    border-color: #007bff;
    background: rgba(0, 123, 255, 0.08);
    font-weight: 600;
  }
}

.modern-payment-modal {
  .modal-dialog {
    max-width: 1100px !important;
    width: 90vw !important;
  }

  .modal-content {
    border: none !important;
    border-radius: 18px !important;
    box-shadow: 0 15px 50px rgba(0, 0, 0, 0.15) !important;
    overflow: hidden !important;
  }

  .modal-body-custom {
    padding: 0 !important;
  }

  &.premium-payment-modal-large {
    .modal-dialog {
      max-width: 1100px !important;
    }
  }
}

/* Global modal override for this specific modal */
#modern_payment_modal {
  .modal-dialog {
    max-width: 1100px !important;
    width: 90vw !important;
  }

  .modal-content {
    border: none !important;
    border-radius: 18px !important;
    box-shadow: 0 15px 50px rgba(0, 0, 0, 0.15) !important;
    overflow: hidden !important;
  }

  .modal-body {
    padding: 0 !important;
  }
}

/* ========================================
   PAYMENT CONTAINER
   ======================================== */

.payment-container {
  background: #ffffff;
  min-height: 450px;
  max-height: 85vh;
  display: flex;
  flex-direction: column;
}

/* ========================================
   HEADER SECTION
   ======================================== */

.payment-header {
  background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
  padding: 14px 24px;
  display: flex;
  justify-content: space-between;
  align-items: center;
  position: relative;
  overflow: hidden;

  &::before {
    content: '';
    position: absolute;
    top: -50%;
    right: -10%;
    width: 400px;
    height: 400px;
    background: radial-gradient(circle, rgba(255, 255, 255, 0.1) 0%, transparent 70%);
    border-radius: 50%;
  }

  .header-left {
        display: flex;
        align-items: center;
    gap: 14px;
    z-index: 1;
  }

  .icon-wrapper {
    width: 40px;
    height: 40px;
    background: rgba(255, 255, 255, 0.2);
    backdrop-filter: blur(10px);
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    color: white;
    box-shadow: 0 6px 18px rgba(0, 0, 0, 0.15);
  }

  .header-text {
    .modal-title {
      margin: 0;
      font-size: 18px;
      font-weight: 700;
      color: white;
      letter-spacing: -0.5px;
    }
  }

  .close-button {
    width: 36px;
    height: 36px;
    background: rgba(255, 255, 255, 0.15);
    backdrop-filter: blur(10px);
    border: 2px solid rgba(255, 255, 255, 0.2);
    border-radius: 12px;
      display: flex;
      align-items: center;
      justify-content: center;
    cursor: pointer;
      transition: all 0.3s ease;
    z-index: 1;
    color: white;

      &:hover {
      background: rgba(255, 255, 255, 0.25);
        transform: rotate(90deg);
      }
    }
  }

/* ========================================
   CONTENT AREA
   ======================================== */

.payment-content {
  display: grid;
  grid-template-columns: 300px 1fr;
  min-height: 400px;
  flex: 1;
  overflow: hidden;

  @media (max-width: 992px) {
    display: block;
    grid-template-columns: none;
    overflow-y: auto;
  }
}

/* ========================================
   TRANSACTION INFO SIDEBAR
   ======================================== */

.transaction-info {
  background: linear-gradient(180deg, #f8f9fc 0%, #eef2f7 100%);
  padding: 14px 12px;
  display: flex;
  flex-direction: column;
  gap: 12px;
  border-right: 1px solid rgba(0, 0, 0, 0.06);
  overflow-y: auto;
  overflow-x: hidden;

  /* Custom scrollbar */
  &::-webkit-scrollbar {
    width: 6px;
  }

  &::-webkit-scrollbar-track {
    background: transparent;
  }

  &::-webkit-scrollbar-thumb {
    background: #d1d5db;
    border-radius: 3px;

    &:hover {
      background: #9ca3af;
    }
  }
}

.amount-card {
  background: white;
  border-radius: 12px;
  padding: 12px;
  box-shadow: 0 3px 15px rgba(0, 0, 0, 0.08);
  border: 1px solid rgba(0, 0, 0, 0.04);

  .amount-card-header {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 10px;
    color: #667eea;
    font-weight: 600;
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: 0.5px;

    svg {
      flex-shrink: 0;
      width: 16px;
      height: 16px;
    }
  }

  .amount-display {
    text-align: center;
    padding: 12px 0;
    border-radius: 10px;
    background: linear-gradient(135deg, rgba(102, 126, 234, 0.05) 0%, rgba(118, 75, 162, 0.05) 100%);

    .currency-label {
      display: block;
      font-size: 10px;
      color: #6b7280;
      font-weight: 500;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      margin-bottom: 4px;
    }

    .amount-large {
      display: block;
      font-size: 26px;
      font-weight: 800;
      background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      background-clip: text;
    }
  }

  .transaction-meta {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-top: 14px;
    padding-top: 14px;
    border-top: 1px solid rgba(0, 0, 0, 0.06);

    .meta-item {
      display: flex;
      flex-direction: column;
      gap: 4px;

      .meta-label {
        font-size: 9px;
        color: #9ca3af;
        font-weight: 500;
        text-transform: uppercase;
        letter-spacing: 0.5px;
      }

      .meta-value {
        font-size: 12px;
        font-weight: 700;
        color: #1f2937;
      }
    }

    .meta-divider {
      width: 1px;
      height: 24px;
      background: rgba(0, 0, 0, 0.06);
    }

    .status-badge {
      display: flex;
      align-items: center;
      gap: 4px;
      background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
      color: white;
      padding: 4px 10px;
      border-radius: 16px;
      font-size: 10px;
      font-weight: 600;

      .status-dot {
        width: 5px;
        height: 5px;
        background: white;
        border-radius: 50%;
        animation: pulse 2s ease infinite;
      }
    }
  }
}

.payment-status-card {
  background: white;
  border-radius: 12px;
  padding: 12px;
  box-shadow: 0 3px 15px rgba(0, 0, 0, 0.08);
  border: 1px solid rgba(0, 0, 0, 0.04);

  .card-title {
    font-size: 11px;
    font-weight: 700;
    color: #1f2937;
    margin: 0 0 10px 0;
    text-transform: uppercase;
    letter-spacing: 0.5px;
  }

  .status-grid {
    display: flex;
    flex-direction: column;
    gap: 8px;
  }

  .status-box {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px;
    background: #f9fafb;
    border-radius: 10px;
    transition: all 0.3s ease;

    &:hover {
      background: #f3f4f6;
      transform: translateX(4px);
    }

    .status-icon {
      width: 30px;
      height: 30px;
      border-radius: 8px;
      display: flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;

      &.paying {
        background: linear-gradient(135deg, #10b981 0%, #059669 100%);
        color: white;
      }

      &.balance {
        background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
        color: white;
      }

      &.change {
        background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
        color: white;
      }

      svg {
        width: 14px;
        height: 14px;
      }
    }

    .status-details {
      flex: 1;
      display: flex;
      flex-direction: column;
      gap: 2px;

      .status-name {
        font-size: 9px;
        color: #6b7280;
        font-weight: 500;
        text-transform: uppercase;
        letter-spacing: 0.5px;
      }

      .status-amount {
        font-size: 14px;
        font-weight: 700;
        color: #1f2937;

        &.balance-text {
          color: #f59e0b;
        }

        &.change-text {
          color: #3b82f6;
        }
      }
    }
  }
}

/* ========================================
   PAYMENT FORM AREA
   ======================================== */

.payment-form-area {
  padding: 16px 20px;
  overflow-y: auto;
  overflow-x: hidden;
  max-height: calc(85vh - 120px);
  position: relative;

  /* Custom scrollbar */
  &::-webkit-scrollbar {
    width: 6px;
  }

  &::-webkit-scrollbar-track {
    background: #f3f4f6;
    border-radius: 4px;
  }

  &::-webkit-scrollbar-thumb {
    background: #d1d5db;
    border-radius: 4px;

    &:hover {
      background: #9ca3af;
    }
  }

  @media (max-width: 992px) {
    max-height: none;
    padding: 16px 16px;
  }
}

.enhanced-form {
  display: flex;
  flex-direction: column;
  gap: 14px;
}

.form-group {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.form-label {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 11px;
  font-weight: 700;
  color: #1f2937;
  text-transform: uppercase;
  letter-spacing: 0.5px;

  svg {
    color: #667eea;
    flex-shrink: 0;
    width: 13px;
    height: 13px;
  }
}

.field-label {
  font-size: 11px;
  font-weight: 600;
  color: #4b5563;
  margin-bottom: 4px;
}

/* Payment Methods */
.payment-methods {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 8px;

  @media (max-width: 1024px) {
    grid-template-columns: repeat(2, 1fr);
  }
}

.method-card {
  position: relative;
  padding: 10px 8px;
  background: white;
  border: 2px solid #e5e7eb;
  border-radius: 10px;
  cursor: pointer;
  transition: all 0.3s ease;
  display: flex;
  flex-direction: column;
  align-items: center;
  text-align: center;
  min-height: 70px;

  &:hover {
    border-color: #667eea;
    background: linear-gradient(135deg, rgba(102, 126, 234, 0.03) 0%, rgba(118, 75, 162, 0.03) 100%);
    transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(102, 126, 234, 0.12);
  }

  &.selected {
    border-color: #667eea;
    background: linear-gradient(135deg, rgba(102, 126, 234, 0.08) 0%, rgba(118, 75, 162, 0.08) 100%);
    box-shadow: 0 6px 16px rgba(102, 126, 234, 0.15);
  }

  .method-content {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 6px;
    width: 100%;
  }

  .method-icon-wrapper {
    width: 32px;
    height: 32px;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 16px;
    color: white;
    box-shadow: 0 2px 8px rgba(102, 126, 234, 0.25);
  }

  .method-label {
    font-size: 11px;
    font-weight: 600;
    color: #1f2937;
  }

  .selected-indicator {
    position: absolute;
    top: 4px;
    right: 4px;
    width: 18px;
    height: 18px;
    background: #10b981;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    box-shadow: 0 2px 6px rgba(16, 185, 129, 0.3);

    svg {
      width: 10px;
      height: 10px;
    }
  }
}

/* Amount Inputs */
.amount-row {
      display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 12px;

  @media (max-width: 1024px) {
    grid-template-columns: 1fr;
  }
}

.input-field {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.input-with-icon {
      position: relative;
      display: flex;
      align-items: center;

  .input-icon {
        position: absolute;
    left: 16px;
    font-size: 16px;
    font-weight: 700;
        color: #667eea;
    pointer-events: none;
  }

  .form-input {
    width: 100%;
    padding: 10px 12px 10px 10px;
    border: 2px solid #e5e7eb;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 600;
    color: #1f2937;
    transition: all 0.3s ease;
    background: white;

    &:focus {
      outline: none;
      border-color: #667eea;
      box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
    }

    &::placeholder {
      color: #9ca3af;
      font-weight: 400;
    }
  }
}

/* Change Notification */
.change-notification {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px 14px;
  background: linear-gradient(135deg, #10b98110 0%, #05966910 100%);
  border: 2px solid #10b981;
  border-radius: 8px;
  margin-top: 8px;
  animation: slideIn 0.4s ease;

  .change-icon {
    width: 30px;
    height: 30px;
    background: #10b981;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    flex-shrink: 0;

    svg {
      width: 16px;
      height: 16px;
    }
  }

  .change-content {
    flex: 1;
    display: flex;
    justify-content: space-between;
    align-items: center;

    .change-title {
      font-size: 10px;
      font-weight: 600;
      color: #047857;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }

    .change-amount {
      font-size: 16px;
      font-weight: 800;
      color: #10b981;
    }
  }
}

/* Dual Input */
.dual-input {
      display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 12px;

  @media (max-width: 1024px) {
    grid-template-columns: 1fr;
  }
}

.form-select {
  width: 100%;
  padding: 10px 12px;
  border: 2px solid #e5e7eb;
  border-radius: 8px;
  font-size: 12px;
  font-weight: 500;
  color: #1f2937;
  transition: all 0.3s ease;
  background: white;
  cursor: pointer;

  &:focus {
    outline: none;
    border-color: #667eea;
    box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
  }
}

.form-textarea {
  width: 100%;
  padding: 10px 12px;
  border: 2px solid #e5e7eb;
  border-radius: 8px;
  font-size: 12px;
  font-weight: 500;
  color: #1f2937;
  transition: all 0.3s ease;
  background: white;
  resize: vertical;
  min-height: 60px;
  font-family: inherit;

  &:focus {
    outline: none;
    border-color: #667eea;
    box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
  }

  &::placeholder {
    color: #9ca3af;
  }
}

/* Payment lines layout: left list + right vertical quick amounts */
.payment-lines-layout {
  display: grid;
  grid-template-columns: 1fr 180px;
  gap: 12px;

  @media (max-width: 992px) {
    grid-template-columns: 1fr;
  }
}

.payment-lines-list {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.payment-line-card {
  background: #f9fafb;
  border: 1px solid #e5e7eb;
  border-radius: 8px;
  overflow: hidden;
}

.payment-line-header {
  background: white;
  border-bottom: 1px solid #e5e7eb;
  padding: 10px 12px;
  display: flex;
  align-items: center;
  gap: 8px;
}

.line-badge {
  background: #667eea;
  color: white;
  width: 22px;
  height: 22px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 11px;
  font-weight: 700;
}

.line-title {
  flex: 1;
  font-size: 12px;
  font-weight: 700;
  color: #1f2937;
}

.line-remove-btn {
  color: #ef4444;
  padding: 0;
  background: none;
  border: none;
  cursor: pointer;
  transition: all .2s ease;
  font-size: 18px;

  &:hover {
    color: #dc2626;
    transform: scale(1.1);
  }
}

.payment-line-body {
  padding: 12px;
  display: grid;
  grid-template-columns: 1fr;
  gap: 10px;

  @media (max-width: 1024px) {
    grid-template-columns: 1fr;
  }
}

.method-pills {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}

.method-pill {
  padding: 8px 10px;
  border: 2px solid #e5e7eb;
  background: #fff;
  border-radius: 999px;
  font-size: 12px;
  font-weight: 600;
  cursor: pointer;
  transition: all .2s ease;

  &:hover {
    border-color: #667eea;
    background: rgba(102,126,234,.06);
  }

  &.selected {
    border-color: #667eea;
    background: rgba(102,126,234,.12);
    color: #2b2e83;
  }
}

.add-line-btn {
  margin-top: 8px;
  outline: none !important;
  box-shadow: none !important;
  &:focus,
  &:active,
  &:focus-visible {
    outline: none !important;
    box-shadow: none !important;
  }
}

.quick-amount-rail {
  display: flex;
  flex-direction: column;
  align-items: stretch;
  gap: 8px;
}

.quick-amount-title {
  font-size: 11px;
  font-weight: 700;
  color: #1f2937;
  text-transform: uppercase;
  letter-spacing: .5px;
}

.quick-amount-vertical {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.quick-btn {
  outline: none !important;
  box-shadow: none !important;
  &:focus,
  &:active,
  &:focus-visible {
    outline: none !important;
    box-shadow: none !important;
  }
}

/* Action Buttons */
.form-actions-new {
  position: sticky;
  bottom: 0;
  display: flex;
  gap: 10px;
  padding: 12px 20px;
  margin: 16px -20px -16px -20px;
  background: white;
  border-top: 2px solid #e5e7eb;
  box-shadow: 0 -4px 12px rgba(0, 0, 0, 0.08);
  z-index: 10;

  @media (max-width: 1024px) {
    flex-direction: row;
    gap: 8px;
    padding: 10px 16px;
    margin: 16px -16px -16px -16px;
  }
}

.action-btn {
  flex: 1;
  padding: 12px 20px;
  border: none;
  border-radius: 8px;
  font-size: 12px;
  font-weight: 700;
  cursor: pointer;
  transition: all 0.3s ease;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  min-height: 42px;

  &:disabled {
    opacity: 0.6;
    cursor: not-allowed;
  }

  .btn-content {
    display: flex;
    align-items: center;
    gap: 8px;
  }

  svg {
    width: 16px;
    height: 16px;
  }
}

.cancel-btn {
  background: #f3f4f6;
  color: #4b5563;
  border: 2px solid #e5e7eb;

  &:hover:not(:disabled) {
    background: #e5e7eb;
    border-color: #d1d5db;
    transform: none;
  }
}

.submit-btn {
  background: linear-gradient(135deg, #10b981 0%, #059669 100%);
  color: white;
  box-shadow: 0 4px 12px rgba(16, 185, 129, 0.25);

  &:hover:not(:disabled) {
    box-shadow: 0 6px 16px rgba(16, 185, 129, 0.35);
    transform: none;
  }

  &:active:not(:disabled) {
    transform: scale(0.98);
  }
}

/* Fixed footer buttons */
.payment-footer {
  position: sticky;
  bottom: 0;
  display: flex;
  gap: 10px;
  padding: 12px 20px;
  background: white;
  border-top: 2px solid #e5e7eb;
  box-shadow: 0 -4px 12px rgba(0, 0, 0, 0.08);
  z-index: 20;
}

/* Saved Cards minimal styling */
.saved-cards {
  background: #ffffff;
  border: 1px solid #e5e7eb;
  border-radius: 10px;
  overflow: hidden;
}

.saved-cards-header {
  padding: 10px 12px;
  font-size: 11px;
  font-weight: 700;
  color: #1f2937;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  background: #f9fafb;
  border-bottom: 1px solid #e5e7eb;
}

.saved-cards-table {
  margin: 0;
  width: 100%;
}

.saved-cards-table thead th {
  font-size: 11px;
  font-weight: 700;
  color: #6b7280;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  background: #f9fafb;
  border-bottom: 1px solid #e5e7eb;
}

.saved-cards-table tbody td {
  vertical-align: middle;
  font-size: 12px;
}

.bg-selected-card {
  background: linear-gradient(135deg, rgba(102, 126, 234, 0.06) 0%, rgba(118, 75, 162, 0.06) 100%);
}

.default-badge {
  display: inline-block;
  margin-left: 8px;
  padding: 2px 6px;
  border-radius: 999px;
  background: #ecfeff;
  color: #0369a1;
  border: 1px solid #67e8f9;
  font-size: 10px;
  font-weight: 700;
  vertical-align: middle;
}

.selected-badge {
  display: inline-block;
  margin-left: 6px;
  padding: 2px 6px;
  border-radius: 999px;
  background: #e8fff3;
  color: #065f46;
  border: 1px solid #6ee7b7;
  font-size: 10px;
  font-weight: 700;
  vertical-align: middle;
}

.footer-btn {
  flex: 1;
  padding: 12px 20px;
  border: none;
  border-radius: 8px;
  font-size: 12px;
  font-weight: 700;
  cursor: pointer;
  transition: all 0.3s ease;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  min-height: 42px;
}

.footer-cancel {
  background: #f3f4f6;
  color: #4b5563;
  border: 2px solid #e5e7eb;
}

.footer-submit {
  background: linear-gradient(135deg, #10b981 0%, #059669 100%);
  color: white;
  box-shadow: 0 4px 12px rgba(16, 185, 129, 0.25);
}

/* Loading Spinner */
.loading-spinner {
    display: inline-block;
  width: 16px;
  height: 16px;
    border: 2px solid rgba(255, 255, 255, 0.3);
    border-top-color: white;
    border-radius: 50%;
    animation: spin 0.8s linear infinite;
  }

/* Animations */
  @keyframes spin {
    to {
      transform: rotate(360deg);
    }
}

@keyframes pulse {
  0%, 100% {
    opacity: 1;
  }
  50% {
    opacity: 0.5;
  }
}

@keyframes slideIn {
  from {
    opacity: 0;
    transform: translateY(-10px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}

/* Responsive Design */
@media (max-width: 992px) {
  .payment-header {
    padding: 14px 20px;

    .icon-wrapper {
      width: 40px;
      height: 40px;
      font-size: 20px;
    }

    .header-text {
      .modal-title {
        font-size: 18px;
      }
    }
  }

  .transaction-info {
    padding: 14px 12px;
  }

  .payment-form-area {
    padding: 16px 20px;
  }
}

@media (max-width: 1024px) {
  .payment-header {
    padding: 12px 16px;

    .header-left {
      gap: 10px;
    }

    .icon-wrapper {
      width: 36px;
      height: 36px;
      font-size: 18px;
    }

    .header-text {
      .modal-title {
        font-size: 16px;
      }

      .modal-subtitle {
        font-size: 10px;
      }
    }

    .close-button {
      width: 32px;
      height: 32px;
    }
  }

  .payment-form-area {
    padding: 14px 16px;
  }

  /* Ensure transaction info doesn't scroll vertically on small screens */
  .transaction-info {
    overflow-y: visible;
    max-height: none;
  }
}

/* POS Devices: card terminal panel on a payment line */
.terminal-section {
  border: 2px solid #e5e7eb;
  border-radius: 10px;
  padding: 10px 12px;
  display: flex;
  flex-direction: column;
  gap: 8px;
  background: #f9fafb;

  &.is-busy,
  &.is-pending {
    border-color: #93c5fd;
    background: #eff6ff;
  }
  &.is-approved {
    border-color: #6ee7b7;
    background: #ecfdf5;
  }
  &.is-declined,
  &.is-failed,
  &.is-timeout {
    border-color: #fca5a5;
    background: #fef2f2;
  }

  .terminal-row {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;

    svg {
      width: 18px;
      height: 18px;
      flex: 0 0 auto;
    }
  }

  .terminal-name {
    font-weight: 700;
    font-size: 13px;
  }

  .terminal-select {
    flex: 1 1 160px;
    min-width: 0;
  }

  .terminal-installments {
    flex: 0 1 140px;
  }

  .terminal-status {
    font-size: 12px;
    color: #374151;
    display: flex;
    align-items: center;
    gap: 6px;
    word-break: break-word;
  }

  .terminal-spinner {
    border-color: rgba(37, 99, 235, 0.25);
    border-top-color: #2563eb;
    flex: 0 0 auto;
  }

  .terminal-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
  }

  .terminal-charge-btn {
    background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
    color: #fff;
  }
}
</style>

