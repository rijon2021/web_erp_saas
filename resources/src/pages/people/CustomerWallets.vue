<template>
  <div class="page">
    <PageHeader :title="$t('CW_Customer_Wallets')" :breadcrumb="[$t('People'), $t('CW_Customer_Wallets')]">
      <template #actions>
        <a-button @click="$router.push('/customers')">
          <template #icon><TeamOutlined /></template>
          {{ $t('Customers') }}
        </a-button>
        <a-button v-if="canGift" @click="$router.push('/customers/gift-cards')">
          <template #icon><GiftOutlined /></template>
          {{ $t('CW_Gift_Cards') }}
        </a-button>
      </template>
    </PageHeader>

    <a-alert
      v-if="flags.migrated === false"
      type="error" show-icon style="margin-bottom: 16px"
      :message="$t('CW_Not_Migrated')"
    />
    <a-alert
      v-if="flags.plan_allows === false"
      type="warning" show-icon style="margin-bottom: 16px"
      :message="$t('CW_Plan_Hint')"
    />
    <a-alert
      v-else-if="flags.enabled === false"
      type="warning" show-icon style="margin-bottom: 16px"
      :message="$t('CW_Disabled_Hint')"
    >
      <template v-if="canManage" #action>
        <a-button size="small" @click="tab = 'settings'">{{ $t('Settings') }}</a-button>
      </template>
    </a-alert>

    <a-tabs v-model:activeKey="tab">
      <!-- ================================ dashboard ================================ -->
      <a-tab-pane key="dashboard" :tab="$t('Dashboard')">
        <a-spin :spinning="dashLoading && !dash">
          <a-row :gutter="[16, 16]" style="margin-bottom: 16px">
            <a-col v-for="card in dashCards" :key="card.label" :xs="12" :md="6">
              <a-card size="small" class="stat-card">
                <div class="stat-inner">
                  <div class="stat-icon" :style="{ background: card.tint, color: card.color }"><component :is="card.icon" /></div>
                  <div class="stat-meta">
                    <div class="stat-label">{{ $t(card.label) }}</div>
                    <div class="stat-value" :style="{ color: card.valueColor }">{{ card.text }}</div>
                    <div v-if="card.sub" class="stat-sub">{{ card.sub }}</div>
                  </div>
                </div>
              </a-card>
            </a-col>
          </a-row>

          <a-row :gutter="[16, 16]" style="margin-bottom: 16px">
            <a-col :xs="24" :lg="16">
              <a-card size="small" :title="$t('CW_Last_30_Days')" class="panel">
                <template #extra>
                  <span class="sums">
                    {{ $t('Credit') }}: <strong style="color: #52c41a">{{ money(stats.credits_30d || 0) }}</strong>
                    &nbsp;·&nbsp;
                    {{ $t('Debit') }}: <strong style="color: #ff4d4f">{{ money(stats.debits_30d || 0) }}</strong>
                  </span>
                </template>
                <a-empty v-if="!hasDaily" :description="$t('CW_No_Moves_Yet')" style="padding: 40px 0" />
                <apexchart v-else type="bar" height="260" :options="chart.options" :series="chart.series" />
              </a-card>
            </a-col>
            <a-col :xs="24" :lg="8">
              <a-card size="small" :title="$t('CW_Top_Wallets')" class="panel">
                <a-empty v-if="!topWallets.length" :description="$t('CW_No_Funded_Wallets')" style="padding: 40px 0" />
                <div v-for="(w, i) in topWallets" :key="w.client_id" class="top-row" @click="goLedger(w.client_id)">
                  <span class="top-rank">{{ i + 1 }}</span>
                  <div class="top-name">
                    <div class="ellipsis"><a>{{ w.name }}</a></div>
                    <div class="muted small">
                      <span v-if="w.code">#{{ w.code }}</span>
                      <a-tag v-if="w.status === 'frozen'" color="error" style="margin-inline-start: 6px">{{ $t('SW_Frozen') }}</a-tag>
                    </div>
                  </div>
                  <strong class="top-balance">{{ money(w.balance) }}</strong>
                </div>
              </a-card>
            </a-col>
          </a-row>

          <a-row :gutter="[16, 16]">
            <a-col :xs="24" :lg="8">
              <a-card size="small" :title="$t('CW_By_Source_30d')" class="panel">
                <a-empty v-if="!bySource.length" :description="$t('CW_No_Moves_Yet')" style="padding: 24px 0" />
                <div v-for="s in bySource" :key="s.source + s.type" class="src-row">
                  <div>
                    <div>{{ srcLabel(s.source) }}</div>
                    <div class="muted small">{{ s.count }} × · {{ s.type === 'credit' ? $t('Credit') : $t('Debit') }}</div>
                  </div>
                  <strong :style="{ color: s.type === 'credit' ? '#52c41a' : '#ff4d4f' }">
                    {{ s.type === 'credit' ? '+' : '-' }}{{ money(s.total) }}
                  </strong>
                </div>
              </a-card>
            </a-col>
            <a-col :xs="24" :lg="16">
              <a-card size="small" :title="$t('CW_Recent_Moves')" class="panel">
                <template #extra>
                  <a @click="tab = 'transactions'">{{ $t('Transactions') }}</a>
                </template>
                <a-table
                  size="small" :pagination="false" row-key="id" :scroll="{ x: 'max-content' }"
                  :columns="recentColumns" :data-source="dash?.recent || []"
                >
                  <template #bodyCell="{ column, record }">
                    <template v-if="column.key === 'client_name'">
                      <a @click="goLedger(record.client_id)">{{ record.client_name }}</a>
                    </template>
                    <template v-else-if="column.key === 'source'">{{ srcLabel(record.source) }}</template>
                    <template v-else-if="column.key === 'amount'">
                      <strong :style="{ color: record.type === 'credit' ? '#52c41a' : '#ff4d4f' }">
                        {{ record.type === 'credit' ? '+' : '-' }}{{ money(record.amount) }}
                      </strong>
                    </template>
                  </template>
                  <template #emptyText>
                    <a-empty :description="$t('CW_No_Moves_Yet')" style="padding: 16px 0" />
                  </template>
                </a-table>
              </a-card>
            </a-col>
          </a-row>
        </a-spin>
      </a-tab-pane>

      <!-- ================================ balances ================================ -->
      <a-tab-pane key="balances" :tab="$t('SW_Balances')">
        <!-- Summary cards: across every customer wallet, not just this page. -->
        <a-row :gutter="[16, 16]" style="margin-bottom: 16px">
          <a-col v-for="card in balanceCards" :key="card.label" :xs="12" :md="6">
            <a-card size="small" class="stat-card">
              <div class="stat-inner">
                <div class="stat-icon" :style="{ background: card.tint, color: card.color }"><component :is="card.icon" /></div>
                <div class="stat-meta">
                  <div class="stat-label">{{ $t(card.label) }}</div>
                  <div class="stat-value" :style="{ color: card.valueColor }">
                    <a-spin v-if="crud.loading.value && !crud.payload.value" size="small" />
                    <template v-else>{{ card.text }}</template>
                  </div>
                </div>
              </div>
            </a-card>
          </a-col>
        </a-row>

        <div class="filters">
          <a-select v-model:value="balanceFilters.status" style="width: 160px" allow-clear :placeholder="$t('Status')" @change="crud.reload()">
            <a-select-option value="active">{{ $t('Active') }}</a-select-option>
            <a-select-option value="frozen">{{ $t('SW_Frozen') }}</a-select-option>
          </a-select>
          <a-checkbox v-model:checked="balanceFilters.onlyWithBalance" @change="crud.reload()">{{ $t('SW_Only_With_Balance') }}</a-checkbox>
        </div>

        <DataTable :crud="crud" :columns="columns">
          <template #bodyCell="{ column, record }">
            <template v-if="column.key === 'name'">
              <a @click="goLedger(record.id)">{{ record.name }}</a>
            </template>
            <template v-else-if="column.key === 'balance'">
              <strong :style="{ color: Number(record.balance) < 0 ? '#ff4d4f' : Number(record.balance) > 0 ? '#52c41a' : undefined }">
                {{ money(record.balance) }}
              </strong>
            </template>
            <template v-else-if="column.key === 'status'">
              <a-tag :color="record.status === 'frozen' ? 'error' : 'success'">
                {{ record.status === 'frozen' ? $t('SW_Frozen') : $t('Active') }}
              </a-tag>
            </template>
            <template v-else-if="column.key === 'last_activity'">
              {{ record.last_activity ? dateTime(record.last_activity) : '-' }}
            </template>
            <template v-else-if="column.key === 'actions'">
              <a-dropdown :trigger="['click']">
                <a-button type="text" size="small">
                  <template #icon><MoreOutlined style="font-size: 18px" /></template>
                </a-button>
                <template #overlay>
                  <a-menu @click="({ key }) => onAction(key, record)">
                    <a-menu-item key="ledger"><EyeOutlined /> {{ $t('CW_Open_Ledger') }}</a-menu-item>
                    <template v-if="canManage">
                      <a-menu-divider />
                      <a-menu-item key="top_up"><PlusCircleOutlined /> {{ $t('SW_Top_Up') }}</a-menu-item>
                      <a-menu-item key="refund"><MinusCircleOutlined /> {{ $t('CW_Cash_Refund') }}</a-menu-item>
                      <a-menu-item key="adjust"><CalculatorOutlined /> {{ $t('SW_Adjust') }}</a-menu-item>
                      <a-menu-item key="status">
                        <template v-if="record.status === 'frozen'"><UnlockOutlined /> {{ $t('SW_Unfreeze') }}</template>
                        <template v-else><LockOutlined /> {{ $t('SW_Freeze') }}</template>
                      </a-menu-item>
                    </template>
                    <template v-if="canGift">
                      <a-menu-divider />
                      <a-menu-item key="redeem"><GiftOutlined /> {{ $t('CW_Redeem_Gift_Card') }}</a-menu-item>
                    </template>
                  </a-menu>
                </template>
              </a-dropdown>
            </template>
          </template>
        </DataTable>
      </a-tab-pane>

      <!-- ============================== transactions ============================== -->
      <a-tab-pane key="transactions" :tab="$t('Transactions')">
        <div class="filters">
          <a-select v-model:value="txFilters.type" style="width: 140px" allow-clear :placeholder="$t('Type')" @change="txCrud.reload()">
            <a-select-option value="credit">{{ $t('Credit') }}</a-select-option>
            <a-select-option value="debit">{{ $t('Debit') }}</a-select-option>
          </a-select>
          <a-select v-model:value="txFilters.source" style="width: 220px" allow-clear :placeholder="$t('Source')" @change="txCrud.reload()">
            <a-select-option v-for="s in sources" :key="s" :value="s">{{ srcLabel(s) }}</a-select-option>
          </a-select>
          <a-range-picker v-model:value="txFilters.range" value-format="YYYY-MM-DD" @change="txCrud.reload()" />
          <span class="sums">
            {{ $t('Credit') }}: <strong style="color: #52c41a">{{ money(txCrud.payload.value?.sums?.credits || 0) }}</strong>
            &nbsp;·&nbsp;
            {{ $t('Debit') }}: <strong style="color: #ff4d4f">{{ money(txCrud.payload.value?.sums?.debits || 0) }}</strong>
          </span>
        </div>

        <DataTable :crud="txCrud" :columns="txColumns">
          <template #bodyCell="{ column, record }">
            <template v-if="column.key === 'client_name'">
              <a @click="goLedger(record.client_id)">{{ record.client_name }}</a>
            </template>
            <template v-else-if="column.key === 'type'">
              <a-tag :color="record.type === 'credit' ? 'success' : 'error'">{{ record.type === 'credit' ? $t('Credit') : $t('Debit') }}</a-tag>
            </template>
            <template v-else-if="column.key === 'source'">{{ srcLabel(record.source) }}</template>
            <template v-else-if="column.key === 'reference'">
              <template v-if="record.reference">
                <router-link v-if="referenceRoute(record)" :to="referenceRoute(record)">{{ record.reference }}</router-link>
                <span v-else>{{ record.reference }}</span>
                <div v-if="record.reference_kind" class="muted small">{{ kindLabel(record.reference_kind) }}</div>
              </template>
              <span v-else class="muted">-</span>
            </template>
            <template v-else-if="column.key === 'amount'">
              <strong :style="{ color: record.type === 'credit' ? '#52c41a' : '#ff4d4f' }">
                {{ record.type === 'credit' ? '+' : '-' }}{{ money(record.amount) }}
              </strong>
            </template>
            <template v-else-if="column.key === 'balance_after'">{{ money(record.balance_after) }}</template>
            <template v-else-if="column.key === 'via'">{{ record.account_name || record.payment_method || '-' }}</template>
          </template>
        </DataTable>
      </a-tab-pane>

      <!-- ============================== withdrawals ============================== -->
      <a-tab-pane key="withdrawals">
        <template #tab>
          {{ $t('CW_Withdrawals') }}
          <a-badge v-if="stats.pending_withdrawals" :count="stats.pending_withdrawals" :offset="[6, -2]" />
        </template>
        <a-alert
          v-if="wdCrud.payload.value && wdCrud.payload.value.withdrawals_enabled === false"
          type="info" show-icon style="margin-bottom: 12px"
          :message="$t('CW_Withdrawals_Off_Hint')"
        />
        <div class="filters">
          <a-select v-model:value="wdFilters.status" style="width: 180px" allow-clear :placeholder="$t('Status')" @change="wdCrud.reload()">
            <a-select-option v-for="s in WD_STATUSES" :key="s" :value="s">{{ wdStatusLabel(s) }}</a-select-option>
          </a-select>
        </div>

        <DataTable :crud="wdCrud" :columns="wdColumns">
          <template #bodyCell="{ column, record }">
            <template v-if="column.key === 'created_at'">{{ record.created_at ? dateTime(record.created_at) : '-' }}</template>
            <template v-else-if="column.key === 'client_name'">
              <a @click="goLedger(record.client_id)">{{ record.client_name }}</a>
              <div v-if="record.client_email" class="muted small">{{ record.client_email }}</div>
            </template>
            <template v-else-if="column.key === 'amount'"><strong>{{ money(record.amount) }}</strong></template>
            <template v-else-if="column.key === 'wallet_balance'">
              <span :style="{ color: Number(record.wallet_balance) < 0 ? '#ff4d4f' : undefined }">{{ money(record.wallet_balance) }}</span>
            </template>
            <template v-else-if="column.key === 'status'">
              <a-tag :color="WD_COLORS[record.status] || 'default'">{{ wdStatusLabel(record.status) }}</a-tag>
            </template>
            <template v-else-if="column.key === 'processed'">
              <template v-if="record.processed_by_name || record.processed_at">
                <div>{{ record.processed_by_name || '-' }}</div>
                <div class="muted small">{{ record.paid_at ? dateTime(record.paid_at) : (record.processed_at ? dateTime(record.processed_at) : '') }}</div>
              </template>
              <span v-else class="muted">-</span>
            </template>
            <template v-else-if="column.key === 'actions'">
              <a-space v-if="canManage" :size="4" wrap>
                <a-button v-if="record.status === 'pending'" size="small" type="primary" @click="openWd('approve', record)">
                  {{ $t('Approve') }}
                </a-button>
                <a-button v-if="record.status === 'approved'" size="small" type="primary" @click="openWd('paid', record)">
                  {{ $t('CW_Mark_Paid') }}
                </a-button>
                <a-button v-if="record.status === 'pending' || record.status === 'approved'" size="small" danger @click="openWd('reject', record)">
                  {{ $t('Reject') }}
                </a-button>
              </a-space>
            </template>
          </template>
        </DataTable>
      </a-tab-pane>

      <!-- ================================ settings ================================ -->
      <a-tab-pane key="settings" :tab="$t('Settings')">
        <a-spin :spinning="settingsLoading">
          <a-alert
            v-if="settings.migrated === false"
            type="error" show-icon style="margin-bottom: 16px"
            :message="$t('CW_Not_Migrated')"
          />
          <a-alert
            v-if="settings.plan_allows === false"
            type="warning" show-icon style="margin-bottom: 16px"
            :message="$t('CW_Plan_Hint')"
          />
          <a-card size="small" class="settings-card">
            <div class="setting-row">
              <div>
                <div class="setting-label">{{ $t('CW_Customer_Wallet') }}</div>
                <div class="setting-help">{{ $t('Sys_Customer_Wallet_Help') }}</div>
              </div>
              <a-switch v-model:checked="settings.customer_wallet_enabled" :disabled="!canManage" />
            </div>
            <div class="setting-row">
              <div>
                <div class="setting-label">{{ $t('CW_Allow_Negative') }}</div>
                <div class="setting-help">{{ $t('Sys_Customer_Wallet_Negative_Help') }}</div>
              </div>
              <a-switch v-model:checked="settings.customer_wallet_allow_negative" :disabled="!canManage" />
            </div>
            <div class="setting-row">
              <div>
                <div class="setting-label">{{ $t('CW_Checkout_Enabled') }}</div>
                <div class="setting-help">{{ $t('CW_Checkout_Enabled_Help') }}</div>
              </div>
              <a-switch v-model:checked="settings.customer_wallet_checkout_enabled" :disabled="!canManage" />
            </div>
            <div class="setting-row">
              <div>
                <div class="setting-label">{{ $t('CW_Withdrawal_Enabled') }}</div>
                <div class="setting-help">{{ $t('CW_Withdrawal_Enabled_Help') }}</div>
              </div>
              <a-switch v-model:checked="settings.customer_wallet_withdrawal_enabled" :disabled="!canManage" />
            </div>
            <div v-if="settings.customer_wallet_withdrawal_enabled" class="setting-row">
              <div>
                <div class="setting-label">{{ $t('CW_Min_Withdrawal') }}</div>
                <div class="setting-help">{{ $t('CW_Min_Withdrawal_Help') }}</div>
              </div>
              <a-input-number v-model:value="settings.customer_wallet_min_withdrawal" :min="0" style="width: 160px" :disabled="!canManage" />
            </div>
            <div class="setting-row">
              <div>
                <div class="setting-label">{{ $t('CW_Refund_Destination') }}</div>
                <div class="setting-help">{{ $t('CW_Refund_Destination_Help') }}</div>
              </div>
              <a-select v-model:value="settings.customer_wallet_refund_destination" style="width: 260px" :disabled="!canManage">
                <a-select-option value="original">{{ $t('CW_Refund_Original') }}</a-select-option>
                <a-select-option value="wallet">{{ $t('CW_Refund_Wallet') }}</a-select-option>
              </a-select>
            </div>
            <div v-if="settings.payment_method_id" class="setting-row">
              <div>
                <div class="setting-label">{{ $t('CW_Wallet_Tender') }}</div>
                <div class="setting-help">{{ $t('CW_Wallet_Tender_Help') }}</div>
              </div>
              <a-tag color="blue">#{{ settings.payment_method_id }}</a-tag>
            </div>
          </a-card>
          <div v-if="canManage" style="margin-top: 16px">
            <a-button type="primary" :loading="settingsSaving" :disabled="settings.migrated === false" @click="saveSettings">
              <template #icon><SaveOutlined /></template>
              {{ $t('Save') }}
            </a-button>
          </div>
        </a-spin>
      </a-tab-pane>
    </a-tabs>

    <CustomerWalletOpsModal
      v-model:open="opsOpen"
      :mode="opsMode"
      :client="opsTarget"
      :balance="Number(opsTarget?.balance) || 0"
      :status="opsTarget?.status || 'active'"
      :accounts="crud.payload.value?.accounts || []"
      :payment-methods="crud.payload.value?.payment_methods || []"
      @done="refreshAll"
    />
    <GiftCardRedeemModal v-model:open="redeemOpen" :client="opsTarget" @done="refreshAll" />

    <!-- Withdrawal approve / reject / mark paid -->
    <a-modal
      v-model:open="wdOpen"
      :title="wdTitle"
      :confirm-loading="wdSaving"
      :ok-text="$t('submit')"
      :cancel-text="$t('Delete_cancelButtonText')"
      :ok-button-props="wdMode === 'reject' ? { danger: true } : {}"
      @ok="submitWd"
    >
      <a-form layout="vertical" style="margin-top: 8px">
        <a-descriptions :column="1" size="small" bordered style="margin-bottom: 12px">
          <a-descriptions-item :label="$t('Customer')">{{ wdTarget?.client_name }}</a-descriptions-item>
          <a-descriptions-item :label="$t('Amount')"><strong>{{ money(wdTarget?.amount) }}</strong></a-descriptions-item>
          <a-descriptions-item v-if="wdTarget?.method" :label="$t('CW_Method')">{{ wdTarget.method }}</a-descriptions-item>
          <a-descriptions-item v-if="wdTarget?.destination" :label="$t('CW_Destination')">{{ wdTarget.destination }}</a-descriptions-item>
          <a-descriptions-item :label="$t('SW_Wallet_Balance')">{{ money(wdTarget?.wallet_balance) }}</a-descriptions-item>
        </a-descriptions>
        <a-alert type="info" show-icon style="margin-bottom: 12px" :message="$t(wdHint)" />
        <a-row v-if="wdMode === 'paid'" :gutter="12">
          <a-col :span="12">
            <a-form-item :label="$t('Paymentchoice')">
              <a-select v-model:value="wdForm.payment_method_id" allow-clear :placeholder="$t('PleaseSelect')" :options="wdMethodOptions" />
            </a-form-item>
          </a-col>
          <a-col :span="12">
            <a-form-item :label="$t('Account')">
              <a-select v-model:value="wdForm.account_id" allow-clear :placeholder="$t('Choose_Account')" :options="wdAccountOptions" />
            </a-form-item>
          </a-col>
        </a-row>
        <a-form-item :label="$t('Note')">
          <a-textarea v-model:value="wdForm.note" :rows="2" :maxlength="255" />
        </a-form-item>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * People → Customer Wallets: store credit the business holds FOR customers
 * (top-ups, refunds, gift cards), spent on sales / online checkout.
 *   Dashboard     GET customer_wallets/dashboard → { stats, daily, by_source, top_wallets, recent, +flags }
 *   Balances      GET customer_wallets → { wallets, totalRows, totals, +flags, accounts, payment_methods }
 *   Transactions  GET customer_wallets/transactions → { transactions, totalRows, sums, sources }
 *   Withdrawals   GET customer_wallet_withdrawals → { withdrawals, totalRows, +flags, accounts, payment_methods }
 *   Settings      GET/POST customer_wallets/settings
 * Flags = { enabled, plan_allows, allow_negative, withdrawals_enabled,
 * payment_method_id, migrated }. Money moves go through CustomerWalletOpsModal;
 * refusals (422 + code) map to CW_Err_<code>.
 */
import { ref, computed, watch, onMounted, createVNode } from 'vue';
import { useRouter } from 'vue-router';
import { message, Modal } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import dayjs from 'dayjs';
import {
  MoreOutlined, EyeOutlined, PlusCircleOutlined, MinusCircleOutlined, CalculatorOutlined,
  LockOutlined, UnlockOutlined, TeamOutlined, WalletOutlined, WarningOutlined, ExclamationCircleOutlined,
  GiftOutlined, ShoppingOutlined, ArrowUpOutlined, BankOutlined, SaveOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import CustomerWalletOpsModal from '../../components/CustomerWalletOpsModal.vue';
import GiftCardRedeemModal from '../../components/GiftCardRedeemModal.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import {
  customerWalletError, CW_SOURCES, walletSourceLabel, walletKindLabel, walletReferenceRoute,
} from '../../composables/useCustomerWallet';
import { useAuthStore } from '../../stores/auth';
import { useUiStore } from '../../stores/ui';
import http from '../../lib/http';

const { t } = useI18n();
const { money, dateTime } = useFormat();
const auth = useAuthStore();
const ui = useUiStore();
const router = useRouter();

const canManage = computed(() => auth.can('customer_wallet_manage'));
const canGift = computed(() => auth.can('gift_cards_manage'));
const tab = ref('dashboard');

const srcLabel = walletSourceLabel;
const kindLabel = walletKindLabel;
const referenceRoute = walletReferenceRoute;

function goLedger(clientId) {
  if (clientId) router.push(`/customers/${clientId}/wallet`);
}

/* ------------------------------------------------------------------- flags */
// Every endpoint ships the same flags; the latest answer wins.
const flags = ref({});
function takeFlags(p) {
  if (!p || typeof p !== 'object') return;
  const next = { ...flags.value };
  for (const k of ['enabled', 'plan_allows', 'allow_negative', 'withdrawals_enabled', 'payment_method_id', 'migrated']) {
    if (k in p) next[k] = p[k];
  }
  flags.value = next;
}

/* --------------------------------------------------------------- dashboard */
const dash = ref(null);
const dashLoading = ref(false);
const stats = computed(() => dash.value?.stats || {});
const topWallets = computed(() => dash.value?.top_wallets || []);
const bySource = computed(() => [...(dash.value?.by_source || [])].sort((a, b) => Number(b.total) - Number(a.total)));

async function loadDashboard() {
  dashLoading.value = true;
  try {
    const data = await http.get('customer_wallets/dashboard');
    dash.value = data || {};
    takeFlags(data);
  } catch (e) {
    message.error(customerWalletError(e));
  } finally {
    dashLoading.value = false;
  }
}

const C = {
  green: ['#52c41a', 'rgba(82,196,26,0.12)'],
  blue: ['#1677ff', 'rgba(22,119,255,0.12)'],
  purple: ['#722ed1', 'rgba(114,46,209,0.12)'],
  teal: ['#13c2c2', 'rgba(19,194,194,0.12)'],
  amber: ['#faad14', 'rgba(250,173,20,0.14)'],
  magenta: ['#eb2f96', 'rgba(235,47,150,0.12)'],
  red: ['#ff4d4f', 'rgba(255,77,79,0.12)'],
};
const dashCards = computed(() => {
  const s = stats.value;
  const n = k => Number(s[k]) || 0;
  const card = (label, text, color, icon, extra = {}) => ({ label, text, icon, color: color[0], tint: color[1], ...extra });
  return [
    card('CW_In_Circulation', money(n('in_circulation')), C.green, WalletOutlined, { valueColor: '#52c41a' }),
    card('CW_Funded_Wallets', `${n('funded')} / ${n('wallets')}`, C.blue, TeamOutlined),
    card('CW_Spent_30d', money(n('spent_30d')), C.purple, ShoppingOutlined),
    card('CW_Top_Ups_30d', money(n('top_ups_30d')), C.teal, PlusCircleOutlined),
    card('CW_Pending_Withdrawals', String(n('pending_withdrawals')), C.amber, BankOutlined, {
      valueColor: n('pending_withdrawals') > 0 ? '#faad14' : undefined,
      sub: money(n('pending_withdrawal_amount')),
    }),
    card('CW_Gift_Cards_Outstanding', money(n('gift_cards_outstanding')), C.magenta, GiftOutlined, {
      sub: `${n('gift_cards_active')} ${t('Active')} · ${n('gift_cards_redeemed')} ${t('CW_Redeemed')}`,
    }),
    card('SW_Overdrawn', money(n('overdrawn')), C.red, WarningOutlined, { valueColor: n('overdrawn') < 0 ? '#ff4d4f' : undefined }),
    card('CW_Withdrawn_Total', money(n('withdrawn_total')), C.blue, ArrowUpOutlined, {
      sub: n('approved_withdrawals') ? `${n('approved_withdrawals')} ${t('Approved')} · ${money(n('approved_withdrawal_amount'))}` : '',
    }),
  ];
});

// 30 calendar days ending today; days without moves read 0.
const dailySeries = computed(() => {
  const byDay = {};
  for (const d of dash.value?.daily || []) byDay[String(d.day).slice(0, 10)] = d;
  const out = [];
  for (let i = 29; i >= 0; i--) {
    const day = dayjs().subtract(i, 'day').format('YYYY-MM-DD');
    const row = byDay[day] || {};
    out.push({ day, credits: Number(row.credits) || 0, debits: Number(row.debits) || 0 });
  }
  return out;
});
const hasDaily = computed(() => dailySeries.value.some(d => d.credits || d.debits));
const chart = computed(() => {
  const dark = ui.dark;
  const ink = dark ? '#c9c9d6' : '#54546a';
  return {
    series: [
      { name: t('Credit'), data: dailySeries.value.map(d => d.credits) },
      { name: t('Debit'), data: dailySeries.value.map(d => d.debits) },
    ],
    options: {
      chart: { type: 'bar', background: 'transparent', foreColor: ink, fontFamily: 'inherit', toolbar: { show: false } },
      colors: dark ? ['#73d13d', '#ff7875'] : ['#52c41a', '#ff4d4f'],
      plotOptions: { bar: { columnWidth: '60%', borderRadius: 3, borderRadiusApplication: 'end' } },
      dataLabels: { enabled: false },
      legend: { position: 'top', horizontalAlign: 'left', labels: { colors: ink }, markers: { size: 6 } },
      xaxis: {
        categories: dailySeries.value.map(d => dayjs(d.day).format('DD/MM')),
        axisBorder: { show: false }, axisTicks: { show: false },
        labels: { rotate: -45, hideOverlappingLabels: true },
      },
      yaxis: { forceNiceScale: true, labels: { formatter: v => money(v) } },
      grid: { borderColor: dark ? 'rgba(255,255,255,0.08)' : 'rgba(0,0,0,0.06)', strokeDashArray: 4 },
      tooltip: { theme: dark ? 'dark' : 'light', shared: true, intersect: false, y: { formatter: v => money(v) } },
    },
  };
});

const recentColumns = computed(() => [
  { title: t('date'), dataIndex: 'date', key: 'date' },
  { title: t('Customer'), dataIndex: 'client_name', key: 'client_name' },
  { title: t('Source'), dataIndex: 'source', key: 'source' },
  { title: t('Amount'), dataIndex: 'amount', key: 'amount', align: 'right' },
]);

/* ---------------------------------------------------------------- balances */
const balanceFilters = ref({ status: undefined, onlyWithBalance: false });
const crud = useCrudTable('customer_wallets', {
  rowsKey: 'wallets',
  sortField: 'balance',
  params: () => ({
    status: balanceFilters.value.status || '',
    only_with_balance: balanceFilters.value.onlyWithBalance ? 1 : 0,
  }),
});
watch(() => crud.payload.value, takeFlags);

const columns = computed(() => [
  { title: t('Action'), key: 'actions', width: 70, align: 'center', fixed: 'left' },
  { title: t('CustomerCode'), dataIndex: 'code', key: 'code', sorter: true },
  { title: t('CustomerName'), dataIndex: 'name', key: 'name', sorter: true },
  { title: t('Phone'), dataIndex: 'phone', key: 'phone' },
  { title: t('Email'), dataIndex: 'email', key: 'email', defaultHidden: true },
  { title: t('SW_Wallet_Balance'), dataIndex: 'balance', key: 'balance', sorter: true, align: 'right', exportValue: r => money(r.balance) },
  { title: t('Status'), dataIndex: 'status', key: 'status', sorter: true, exportValue: r => (r.status === 'frozen' ? t('SW_Frozen') : t('Active')) },
  { title: t('Last_Activity'), dataIndex: 'last_activity', key: 'last_activity' },
]);

const balanceCards = computed(() => {
  const tot = crud.payload.value?.totals || {};
  return [
    { label: 'CW_In_Circulation', text: money(tot.positive || 0), icon: WalletOutlined, color: '#52c41a', tint: 'rgba(82,196,26,0.12)', valueColor: '#52c41a' },
    { label: 'SW_Overdrawn', text: money(tot.negative || 0), icon: WarningOutlined, color: '#ff4d4f', tint: 'rgba(255,77,79,0.12)', valueColor: Number(tot.negative) < 0 ? '#ff4d4f' : undefined },
    { label: 'SW_Wallets_Count', text: String(tot.wallets || 0), icon: TeamOutlined, color: '#1677ff', tint: 'rgba(22,119,255,0.12)' },
    { label: 'SW_Frozen_Count', text: String(tot.frozen || 0), icon: LockOutlined, color: '#faad14', tint: 'rgba(250,173,20,0.14)' },
  ];
});

/* ------------------------------------------------------------ transactions */
const txFilters = ref({ type: undefined, source: undefined, range: null });
const txCrud = useCrudTable('customer_wallets/transactions', {
  rowsKey: 'transactions',
  params: () => ({
    type: txFilters.value.type || '',
    source: txFilters.value.source || '',
    from: txFilters.value.range?.[0] || '',
    to: txFilters.value.range?.[1] || '',
  }),
});
const sources = computed(() => {
  const fromApi = txCrud.payload.value?.sources;
  return Array.isArray(fromApi) && fromApi.length ? fromApi : CW_SOURCES;
});

const txColumns = computed(() => [
  { title: t('date'), dataIndex: 'date', key: 'date', sorter: true },
  { title: t('Customer'), dataIndex: 'client_name', key: 'client_name' },
  { title: t('Type'), dataIndex: 'type', key: 'type', sorter: true, exportValue: r => (r.type === 'credit' ? t('Credit') : t('Debit')) },
  { title: t('Source'), dataIndex: 'source', key: 'source', sorter: true, exportValue: r => srcLabel(r.source) },
  { title: t('Reference'), dataIndex: 'reference', key: 'reference', exportValue: r => r.reference || '' },
  { title: t('Amount'), dataIndex: 'amount', key: 'amount', sorter: true, align: 'right', exportValue: r => (r.type === 'credit' ? '+' : '-') + money(r.amount) },
  { title: t('SW_Balance_After'), dataIndex: 'balance_after', key: 'balance_after', align: 'right', exportValue: r => money(r.balance_after) },
  { title: t('Account'), key: 'via', exportValue: r => r.account_name || r.payment_method || '' },
  { title: t('User'), dataIndex: 'user_name', key: 'user_name' },
  { title: t('Note'), dataIndex: 'note', key: 'note' },
]);

/* ------------------------------------------------------------- withdrawals */
const WD_STATUSES = ['pending', 'approved', 'paid', 'rejected'];
const WD_COLORS = { pending: 'warning', approved: 'processing', paid: 'success', rejected: 'error' };
const wdStatusLabel = s => ({ pending: t('Pending'), approved: t('Approved'), paid: t('Paid'), rejected: t('Rejected') }[s] || s);

const wdFilters = ref({ status: undefined });
const wdCrud = useCrudTable('customer_wallet_withdrawals', {
  rowsKey: 'withdrawals',
  params: () => ({ status: wdFilters.value.status || '' }),
});
watch(() => wdCrud.payload.value, takeFlags);

const wdColumns = computed(() => [
  { title: t('date'), dataIndex: 'created_at', key: 'created_at', exportValue: r => r.created_at || '' },
  { title: t('Customer'), dataIndex: 'client_name', key: 'client_name' },
  { title: t('Amount'), dataIndex: 'amount', key: 'amount', align: 'right', exportValue: r => money(r.amount) },
  { title: t('CW_Method'), dataIndex: 'method', key: 'method' },
  { title: t('CW_Destination'), dataIndex: 'destination', key: 'destination' },
  { title: t('Status'), dataIndex: 'status', key: 'status', exportValue: r => wdStatusLabel(r.status) },
  { title: t('SW_Wallet_Balance'), dataIndex: 'wallet_balance', key: 'wallet_balance', align: 'right', exportValue: r => money(r.wallet_balance) },
  { title: t('CW_Processed_By'), key: 'processed', exportValue: r => r.processed_by_name || '' },
  { title: t('Account'), dataIndex: 'account_name', key: 'account_name' },
  { title: t('Note'), dataIndex: 'note', key: 'note' },
  { title: t('Action'), key: 'actions', align: 'center' },
]);

const wdOpen = ref(false);
const wdSaving = ref(false);
const wdMode = ref('approve'); // approve | reject | paid
const wdTarget = ref(null);
const wdForm = ref({ note: '', account_id: undefined, payment_method_id: undefined });
const wdTitle = computed(() => ({ approve: t('CW_Approve_Withdrawal'), reject: t('CW_Reject_Withdrawal'), paid: t('CW_Mark_Paid') }[wdMode.value]));
const wdHint = computed(() => ({ approve: 'CW_Approve_Help', reject: 'CW_Reject_Help', paid: 'CW_Mark_Paid_Help' }[wdMode.value]));
const wdMethodOptions = computed(() => (wdCrud.payload.value?.payment_methods || []).map(m => ({ value: m.id, label: m.name })));
const wdAccountOptions = computed(() => (wdCrud.payload.value?.accounts || []).map(a => ({ value: a.id, label: a.account_name })));

function openWd(mode, record) {
  wdMode.value = mode;
  wdTarget.value = record;
  wdForm.value = { note: '', account_id: undefined, payment_method_id: undefined };
  wdOpen.value = true;
}

async function submitWd() {
  const endpoint = { approve: 'approve', reject: 'reject', paid: 'paid' }[wdMode.value];
  const body = { note: wdForm.value.note || null };
  if (wdMode.value === 'paid') {
    body.account_id = wdForm.value.account_id || null;
    body.payment_method_id = wdForm.value.payment_method_id || null;
  }
  wdSaving.value = true;
  try {
    await http.post(`customer_wallet_withdrawals/${wdTarget.value.id}/${endpoint}`, body);
    message.success(t('Successfully_Updated'));
    wdOpen.value = false;
    refreshAll();
  } catch (e) {
    message.error(customerWalletError(e));
  } finally {
    wdSaving.value = false;
  }
}

/* ---------------------------------------------------------------- settings */
const settings = ref({});
const settingsLoading = ref(false);
const settingsSaving = ref(false);

function takeSettings(data) {
  const d = data || {};
  settings.value = {
    customer_wallet_enabled: !!d.customer_wallet_enabled,
    customer_wallet_allow_negative: !!d.customer_wallet_allow_negative,
    customer_wallet_checkout_enabled: !!d.customer_wallet_checkout_enabled,
    customer_wallet_withdrawal_enabled: !!d.customer_wallet_withdrawal_enabled,
    customer_wallet_min_withdrawal: Number(d.customer_wallet_min_withdrawal) || 0,
    customer_wallet_refund_destination: d.customer_wallet_refund_destination || 'original',
    payment_method_id: d.payment_method_id ?? null,
    migrated: d.migrated,
    plan_allows: d.plan_allows,
  };
  takeFlags(d);
}

async function loadSettings() {
  settingsLoading.value = true;
  try {
    takeSettings(await http.get('customer_wallets/settings'));
  } catch (e) {
    message.error(customerWalletError(e));
  } finally {
    settingsLoading.value = false;
  }
}

async function saveSettings() {
  const s = settings.value;
  settingsSaving.value = true;
  try {
    const data = await http.post('customer_wallets/settings', {
      customer_wallet_enabled: s.customer_wallet_enabled ? 1 : 0,
      customer_wallet_allow_negative: s.customer_wallet_allow_negative ? 1 : 0,
      customer_wallet_checkout_enabled: s.customer_wallet_checkout_enabled ? 1 : 0,
      customer_wallet_withdrawal_enabled: s.customer_wallet_withdrawal_enabled ? 1 : 0,
      customer_wallet_min_withdrawal: Number(s.customer_wallet_min_withdrawal) || 0,
      customer_wallet_refund_destination: s.customer_wallet_refund_destination || 'original',
    });
    takeSettings(data);
    message.success(t('Successfully_Updated'));
    // The switch decides whether balances / tender exist: refresh the rest.
    loaded.clear();
    loaded.add('settings');
    loadTab('dashboard');
    loadTab('balances');
  } catch (e) {
    message.error(customerWalletError(e));
  } finally {
    settingsSaving.value = false;
  }
}

/* ------------------------------------------------------------------ actions */
const opsOpen = ref(false);
const opsMode = ref('top_up');
const opsTarget = ref(null);
const redeemOpen = ref(false);

function onAction(key, record) {
  if (key === 'ledger') return goLedger(record.id);
  if (key === 'status') return toggleStatus(record);
  opsTarget.value = record;
  if (key === 'redeem') {
    redeemOpen.value = true;
    return;
  }
  opsMode.value = key;
  opsOpen.value = true;
}

function toggleStatus(record) {
  const next = record.status === 'frozen' ? 'active' : 'frozen';
  Modal.confirm({
    title: next === 'frozen' ? t('SW_Freeze') : t('SW_Unfreeze'),
    icon: createVNode(ExclamationCircleOutlined),
    content: next === 'frozen' ? t('CW_Freeze_Confirm') : t('CW_Unfreeze_Confirm'),
    okText: t('submit'),
    cancelText: t('Delete_cancelButtonText'),
    async onOk() {
      try {
        await http.post(`customer_wallets/${record.id}/status`, { status: next });
        message.success(t('Successfully_Updated'));
        refreshAll();
      } catch (e) {
        message.error(customerWalletError(e));
      }
    },
  });
}

/* ------------------------------------------------------------ tab loading */
// Each tab loads the first time it is opened; money moves refresh them all.
const loaded = new Set();
function loadTab(key) {
  if (loaded.has(key)) return;
  loaded.add(key);
  if (key === 'dashboard') loadDashboard();
  else if (key === 'balances') crud.fetchRows();
  else if (key === 'transactions') txCrud.fetchRows();
  else if (key === 'withdrawals') wdCrud.fetchRows();
  else if (key === 'settings') loadSettings();
}
watch(tab, loadTab);

function refreshAll() {
  // Dashboard (stats + pending badge) and balances (ops modal lists) always
  // reload; the other tabs reload now if open, else when next opened.
  const keepSettings = loaded.has('settings');
  loaded.clear();
  if (keepSettings) loaded.add('settings');
  loadTab('dashboard');
  loadTab('balances');
  loadTab(tab.value);
}

onMounted(() => {
  loadTab('dashboard');
  // Balances carry the accounts / payment methods the ops modal needs.
  loadTab('balances');
});
</script>

<style scoped>
.filters {
  display: flex;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
  margin-bottom: 12px;
}
.sums {
  margin-inline-start: auto;
  font-size: 13px;
}
.muted {
  color: rgba(var(--ink-rgb), 0.45);
}
.small { font-size: 12px; }
.ellipsis { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.stat-card { border-radius: 10px; height: 100%; }
.stat-inner { display: flex; align-items: center; gap: 12px; }
.stat-icon {
  width: 44px; height: 44px; border-radius: 10px;
  display: flex; align-items: center; justify-content: center;
  font-size: 20px; flex: none;
}
.stat-meta { min-width: 0; }
.stat-label { opacity: 0.65; font-size: 13px; margin-bottom: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.stat-value { font-size: 20px; font-weight: 700; white-space: nowrap; }
.stat-sub { font-size: 12px; opacity: 0.6; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.panel { height: 100%; border-radius: 10px; }

.top-row {
  display: flex; align-items: center; gap: 10px;
  padding: 8px 4px; border-bottom: 1px solid var(--line); cursor: pointer;
}
.top-row:last-child { border-bottom: none; }
.top-rank {
  width: 24px; height: 24px; border-radius: 50%; flex: none;
  display: flex; align-items: center; justify-content: center;
  font-size: 12px; font-weight: 600; background: rgba(82, 196, 26, 0.12); color: #52c41a;
}
.top-name { min-width: 0; flex: 1 1 auto; }
.top-balance { color: #52c41a; white-space: nowrap; }

.src-row {
  display: flex; align-items: center; justify-content: space-between; gap: 12px;
  padding: 8px 0; border-bottom: 1px solid var(--line);
}
.src-row:last-child { border-bottom: none; }

.settings-card { max-width: 860px; border-radius: 10px; }
.setting-row {
  display: flex; align-items: center; justify-content: space-between; gap: 16px;
  padding: 14px 4px; border-bottom: 1px solid var(--line);
}
.setting-row:last-child { border-bottom: none; }
.setting-label { font-weight: 600; }
.setting-help { font-size: 12px; color: rgba(var(--ink-rgb), 0.5); margin-top: 2px; max-width: 560px; }

@media (max-width: 575px) {
  .stat-icon { width: 36px; height: 36px; font-size: 16px; }
  .stat-value { font-size: 16px; }
  .setting-row { flex-direction: column; align-items: flex-start; }
}
</style>
