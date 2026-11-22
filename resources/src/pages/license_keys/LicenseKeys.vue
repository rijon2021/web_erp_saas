<template>
  <div class="page">
    <PageHeader :title="$t('LicK_License_Keys')" :breadcrumb="[$t('Products'), $t('LicK_License_Keys')]">
      <template #actions>
        <template v-if="ready && canManage">
          <a-button v-if="poolProducts.length" @click="openImport()">
            <template #icon><CloudUploadOutlined /></template>
            {{ $t('LicK_Add_Keys') }}
          </a-button>
          <a-button v-if="generatedProducts.length" type="primary" @click="openIssue()">
            <template #icon><PlusOutlined /></template>
            {{ $t('LicK_Issue_Licenses') }}
          </a-button>
        </template>
      </template>
    </PageHeader>

    <a-alert v-if="!planAllows" type="warning" show-icon style="margin-bottom: 16px" :message="$t('LicK_Plan_Hint')" />
    <a-alert v-else-if="notMigrated" type="error" show-icon style="margin-bottom: 16px" :message="notMigrated" />

    <a-tabs v-if="planAllows && !notMigrated" v-model:activeKey="tab">
      <!-- ================================ overview ================================ -->
      <a-tab-pane key="overview" :tab="$t('LicK_Overview')">
        <a-spin :spinning="dashLoading && !dash">
          <div class="kpis">
            <a-card
              v-for="card in kpiCards" :key="card.label" size="small"
              class="stat-card" :class="{ clickable: !!card.go }"
              @click="card.go && card.go()"
            >
              <div class="stat-inner">
                <div class="stat-icon" :style="{ background: card.tint, color: card.color }"><component :is="card.icon" /></div>
                <div class="stat-meta">
                  <div class="stat-label">{{ $t(card.label) }}</div>
                  <div class="stat-value" :style="{ color: card.valueColor }">{{ card.value }}</div>
                </div>
              </div>
            </a-card>
          </div>

          <a-card size="small" class="panel" :title="$t('LicK_License_Products')">
            <a-table
              size="small" row-key="rowKey" :pagination="false" :scroll="{ x: 'max-content' }"
              :columns="productColumns" :data-source="productRows"
              :indent-size="18"
            >
              <template #bodyCell="{ column, record }">
                <template v-if="column.key === 'name'">
                  <template v-if="record.isEdition">
                    <span class="muted">{{ $t('LicK_Edition') }}:</span> {{ record.name }}
                  </template>
                  <template v-else>
                    <strong>{{ record.name }}</strong>
                    <div v-if="record.code" class="muted small">{{ record.code }}</div>
                  </template>
                </template>
                <template v-else-if="column.key === 'mode'">
                  <a-tag v-if="!record.isEdition" :color="licenseSourceColor(record.mode)">{{ licenseSourceLabel(record.mode) }}</a-tag>
                </template>
                <template v-else-if="column.key === 'available'">
                  <template v-if="record.mode === 'pool'">
                    <strong :style="{ color: record.low_stock ? '#ff4d4f' : undefined }">{{ record.counts.available || 0 }}</strong>
                    <a-tag v-if="record.low_stock" color="error" style="margin-inline-start: 6px">
                      <WarningOutlined /> {{ $t('Low_Stock') }}
                    </a-tag>
                    <div v-if="!record.isEdition && record.stock_alert" class="muted small">{{ $t('LicK_Alert_At') }}: {{ record.stock_alert }}</div>
                  </template>
                  <span v-else class="muted">-</span>
                </template>
                <template v-else-if="column.key === 'sold'">{{ record.counts.sold || 0 }}</template>
                <template v-else-if="column.key === 'returned'">
                  <a v-if="record.counts.returned" class="warn" @click="viewKeys(record, { status: 'returned' })">{{ record.counts.returned }}</a>
                  <span v-else>0</span>
                </template>
                <template v-else-if="column.key === 'blocked'">
                  <span v-if="record.mode === 'generated'">
                    {{ (record.counts.suspended || 0) + (record.counts.revoked || 0) }}
                  </span>
                  <span v-else>{{ record.counts.void || 0 }}</span>
                </template>
                <template v-else-if="column.key === 'expiring_30'">
                  <template v-if="!record.isEdition && record.mode === 'generated'">
                    <a v-if="record.expiring_30" class="warn" @click="viewKeys(record, { status: 'expiring' })">{{ record.expiring_30 }}</a>
                    <span v-else>0</span>
                  </template>
                  <span v-else class="muted">-</span>
                </template>
                <template v-else-if="column.key === 'actions'">
                  <a-space :size="4" wrap>
                    <template v-if="canManage">
                      <a-button v-if="record.mode === 'pool'" size="small" @click="openImport(record)">
                        <template #icon><CloudUploadOutlined /></template>
                        {{ $t('LicK_Add_Keys') }}
                      </a-button>
                      <a-button v-else size="small" @click="openIssue(record)">
                        <template #icon><PlusOutlined /></template>
                        {{ $t('LicK_Issue_Licenses') }}
                      </a-button>
                    </template>
                    <a-button size="small" type="link" @click="viewKeys(record)">{{ $t('LicK_View_Keys') }}</a-button>
                  </a-space>
                </template>
              </template>
              <template #emptyText>
                <a-empty :description="$t('LicK_No_Products')" style="padding: 24px 0" />
              </template>
            </a-table>
          </a-card>

          <!-- Completed sales still owed keys (shop imports, pre-orders, subscriptions):
               filled automatically when keys are imported, or here with "Assign now". -->
          <a-card v-if="backorders.length" ref="backorderPanel" size="small" class="panel" style="margin-top: 16px" :title="$t('LicK_Backorders_Title')">
            <div class="muted small" style="margin-bottom: 8px">{{ $t('LicK_Backorders_Help') }}</div>
            <a-table size="small" row-key="id" :pagination="false" :scroll="{ x: 'max-content' }" :columns="backorderColumns" :data-source="backorders">
              <template #bodyCell="{ column, record }">
                <template v-if="column.key === 'sale'">
                  <router-link v-if="canViewSales" :to="`/sales/${record.sale_id}`">{{ record.sale_ref || ('#' + record.sale_id) }}</router-link>
                  <span v-else>{{ record.sale_ref }}</span>
                  <div class="muted small">{{ record.sale_date }}</div>
                </template>
                <template v-else-if="column.key === 'product'">
                  {{ record.product_name }}<span v-if="record.variant_name" class="muted"> · {{ record.variant_name }}</span>
                </template>
                <template v-else-if="column.key === 'missing'">
                  <strong class="warn">{{ record.missing }}</strong>
                </template>
                <template v-else-if="column.key === 'actions'">
                  <a-button v-if="canManage" size="small" :loading="assigningSale === record.sale_id" @click="assignSale(record.sale_id)">
                    {{ $t('LicK_Assign_Now') }}
                  </a-button>
                </template>
              </template>
            </a-table>
          </a-card>
        </a-spin>
      </a-tab-pane>

      <!-- ================================== keys ================================== -->
      <a-tab-pane key="keys" :tab="$t('LicK_Keys_Tab')">
        <div class="filters">
          <a-select
            v-model:value="filters.product_id" style="width: 220px" allow-clear show-search
            option-filter-prop="label" :placeholder="$t('Product')" :options="productOptions"
            @change="onFilterProduct"
          />
          <a-select
            v-if="filterVariantOptions.length"
            v-model:value="filters.variant_id" style="width: 170px" allow-clear
            :placeholder="$t('LicK_Edition')" :options="filterVariantOptions" @change="reloadKeys"
          />
          <a-select v-model:value="filters.source" style="width: 160px" allow-clear :placeholder="$t('Source')" @change="reloadKeys">
            <a-select-option value="pool">{{ licenseSourceLabel('pool') }}</a-select-option>
            <a-select-option value="generated">{{ licenseSourceLabel('generated') }}</a-select-option>
          </a-select>
          <a-select v-model:value="filters.status" style="width: 200px" allow-clear :placeholder="$t('Status')" @change="reloadKeys">
            <a-select-opt-group :label="$t('LicK_Stock_Status')">
              <a-select-option v-for="s in LK_STATUSES" :key="s" :value="s">{{ licenseStatusLabel(s) }}</a-select-option>
            </a-select-opt-group>
            <a-select-opt-group :label="$t('LicK_License_State')">
              <a-select-option v-for="s in LK_STATE_FILTERS" :key="s" :value="s">{{ licenseStatusLabel(s) }}</a-select-option>
            </a-select-opt-group>
          </a-select>
          <a-range-picker v-model:value="filters.range" value-format="YYYY-MM-DD" @change="reloadKeys" />
          <a-tag v-if="filters.batch" closable color="blue" @close="clearBatch">
            {{ $t('Batch') }}: <span class="mono">{{ filters.batch }}</span>
          </a-tag>
          <a-button v-if="hasFilters" type="link" @click="resetFilters">{{ $t('Reset') }}</a-button>
        </div>

        <DataTable :crud="keysCrud" :columns="keyColumns">
          <template #toolbar>
            <a-button v-if="canReveal" :loading="exporting" @click="exportCsv">
              <template #icon><DownloadOutlined /></template>
              {{ $t('LicK_Export_Csv') }}
            </a-button>
          </template>
          <template #bodyCell="{ column, record }">
            <template v-if="column.key === 'masked_key'">
              <a class="mono" @click="openKey(record)">{{ record.masked_key }}</a>
            </template>
            <template v-else-if="column.key === 'product'">
              <div>{{ record.product_name || '-' }}</div>
              <div v-if="record.variant_name" class="muted small">{{ record.variant_name }}</div>
            </template>
            <template v-else-if="column.key === 'source'">
              <a-tag :color="licenseSourceColor(record.source)">{{ licenseSourceLabel(record.source) }}</a-tag>
            </template>
            <template v-else-if="column.key === 'status'">
              <a-tag :color="licenseStatusColor(record.status)">{{ licenseStatusLabel(record.status) }}</a-tag>
              <a-tag
                v-if="record.license_state && record.license_state !== record.status"
                :color="licenseStatusColor(record.license_state)"
              >
                {{ licenseStatusLabel(record.license_state) }}
              </a-tag>
              <a-tooltip v-if="record.status_reason" :title="record.status_reason">
                <InfoCircleOutlined class="muted" />
              </a-tooltip>
            </template>
            <template v-else-if="column.key === 'client_name'">
              <template v-if="record.client_name">
                <div>{{ record.client_name }}</div>
                <div v-if="record.client_email" class="muted small">{{ record.client_email }}</div>
              </template>
              <span v-else class="muted">-</span>
            </template>
            <template v-else-if="column.key === 'sale_ref'">
              <router-link v-if="record.sale_id && canViewSales" :to="`/sales/${record.sale_id}`">{{ record.sale_ref || ('#' + record.sale_id) }}</router-link>
              <span v-else-if="record.sale_id">{{ record.sale_ref }}</span>
              <router-link v-else-if="record.online_order_id" :to="`/store/orders/${record.online_order_id}`">
                {{ $t('LicK_Online_Order') }} #{{ record.online_order_id }}
              </router-link>
              <span v-else class="muted">-</span>
            </template>
            <template v-else-if="column.key === 'sold_at'">{{ record.sold_at ? date(record.sold_at) : '-' }}</template>
            <template v-else-if="column.key === 'expires_at'">
              <template v-if="record.source === 'generated'">
                <span v-if="!record.expires_at" class="muted">{{ $t('LicK_Lifetime') }}</span>
                <span v-else :class="{ danger: isPast(record.expires_at), warn: !isPast(record.expires_at) && isSoon(record.expires_at) }">
                  {{ date(record.expires_at) }}
                </span>
              </template>
              <span v-else class="muted">-</span>
            </template>
            <template v-else-if="column.key === 'activations'">
              <template v-if="record.source === 'generated'">
                {{ record.active_activations || 0 }} / {{ record.max_activations == null ? '∞' : record.max_activations }}
              </template>
              <span v-else class="muted">-</span>
            </template>
            <template v-else-if="column.key === 'created_at'">{{ record.created_at ? dateTime(record.created_at) : '-' }}</template>
            <template v-else-if="column.key === 'actions'">
              <a-button type="text" size="small" @click="openKey(record)">
                <template #icon><EyeOutlined /></template>
              </a-button>
            </template>
          </template>
        </DataTable>
      </a-tab-pane>

      <!-- ================================ settings ================================ -->
      <a-tab-pane key="settings" :tab="$t('Settings')">
        <a-spin :spinning="settingsLoading">
          <a-alert v-if="!canManage" type="info" show-icon style="margin-bottom: 16px; max-width: 860px" :message="$t('LicK_Settings_Readonly')" />
          <a-card size="small" class="settings-card">
            <div class="setting-row">
              <div>
                <div class="setting-label">{{ $t('LicK_Set_Email') }}</div>
                <div class="setting-help">{{ $t('LicK_Set_Email_Help') }}</div>
              </div>
              <a-switch v-model:checked="settings.email_enabled" :disabled="!canManage" />
            </div>
            <div class="setting-row">
              <div>
                <div class="setting-label">{{ $t('LicK_Set_Receipt') }}</div>
                <div class="setting-help">{{ $t('LicK_Set_Receipt_Help') }}</div>
              </div>
              <a-switch v-model:checked="settings.show_on_receipt" :disabled="!canManage" />
            </div>
            <div class="setting-row">
              <div>
                <div class="setting-label">{{ $t('LicK_Set_Api') }}</div>
                <div class="setting-help">{{ $t('LicK_Set_Api_Help') }}</div>
              </div>
              <a-switch v-model:checked="settings.api_enabled" :disabled="!canManage" />
            </div>
            <div class="setting-row">
              <div>
                <div class="setting-label">{{ $t('LicK_Set_Grace') }}</div>
                <div class="setting-help">{{ $t('LicK_Set_Grace_Help') }}</div>
              </div>
              <a-input-number v-model:value="settings.offline_grace_days" :min="0" :max="365" :precision="0" style="width: 140px" :disabled="!canManage" />
            </div>
            <div class="setting-row">
              <div>
                <div class="setting-label">{{ $t('LicK_Set_Reminders') }}</div>
                <div class="setting-help">{{ $t('LicK_Set_Reminders_Help') }}</div>
              </div>
              <a-input v-model:value="settings.reminder_days" placeholder="30,7" style="width: 160px" :maxlength="32" :disabled="!canManage" />
            </div>
          </a-card>
          <div v-if="canManage" style="margin: 16px 0 24px">
            <a-button type="primary" :loading="settingsSaving" @click="saveSettings">
              <template #icon><SaveOutlined /></template>
              {{ $t('Save') }}
            </a-button>
          </div>

          <a-card size="small" class="settings-card" :title="$t('LicK_Signing')" style="margin-top: 16px">
            <div class="field">
              <div class="setting-label">{{ $t('LicK_Api_Base') }}</div>
              <a-input-group compact class="copy-group">
                <a-input :value="apiBase" readonly class="mono" />
                <a-button @click="copy(apiBase)"><template #icon><CopyOutlined /></template></a-button>
              </a-input-group>
            </div>
            <div class="field">
              <div class="setting-label">{{ $t('LicK_Public_Key') }}</div>
              <div class="setting-help" style="margin-bottom: 6px">{{ $t('LicK_Public_Key_Help') }}</div>
              <a-input-group compact class="copy-group">
                <a-input :value="publicKey" readonly class="mono" />
                <a-button @click="copy(publicKey)"><template #icon><CopyOutlined /></template></a-button>
              </a-input-group>
            </div>
            <div v-if="canManage" class="field">
              <a-button danger :loading="rotating" @click="rotateKey">
                <template #icon><SyncOutlined /></template>
                {{ $t('LicK_Rotate_Key') }}
              </a-button>
              <div class="setting-help">{{ $t('LicK_Rotate_Help') }}</div>
            </div>
          </a-card>

          <a-collapse class="settings-card guide" style="margin-top: 16px">
            <a-collapse-panel key="guide" :header="$t('LicK_Api_Guide')">
              <h4>{{ $t('LicK_G_Endpoints') }}</h4>
              <table class="g-table">
                <tbody>
                  <tr v-for="ep in guideEndpoints" :key="ep.path">
                    <td class="mono nowrap">POST {{ ep.path }}</td>
                    <td>{{ $t(ep.help) }}</td>
                  </tr>
                </tbody>
              </table>

              <h4>{{ $t('LicK_G_Request') }}</h4>
              <p>{{ $t('LicK_G_Request_Help') }}</p>
              <pre class="code">{{ curlSnippet }}</pre>

              <h4>{{ $t('LicK_G_Response') }}</h4>
              <p>{{ $t('LicK_G_Response_Help') }}</p>
              <pre class="code">{{ responseSnippet }}</pre>

              <h4>{{ $t('LicK_G_Errors') }}</h4>
              <table class="g-table">
                <tbody>
                  <tr v-for="code in guideErrors" :key="code">
                    <td class="mono nowrap">{{ code }}</td>
                    <td>{{ $t('LicK_GErr_' + code) }}</td>
                  </tr>
                </tbody>
              </table>

              <h4>{{ $t('LicK_G_Verify') }}</h4>
              <p>{{ $t('LicK_G_Verify_Help') }}</p>
              <div class="muted small">PHP (ext-sodium)</div>
              <pre class="code">{{ phpSnippet }}</pre>
              <div class="muted small">Node.js</div>
              <pre class="code">{{ nodeSnippet }}</pre>
              <p>{{ $t('LicK_G_Offline') }}</p>
              <p class="muted">{{ $t('LicK_G_Rate') }}</p>
            </a-collapse-panel>
          </a-collapse>
        </a-spin>
      </a-tab-pane>
    </a-tabs>

    <ImportKeysModal
      v-model:open="importOpen"
      :products="catalog.products"
      :warehouses="catalog.warehouses"
      :preset="preset"
      @done="afterBatch"
    />
    <IssueLicensesModal
      v-model:open="issueOpen"
      :products="catalog.products"
      :preset="preset"
      @done="afterBatch"
    />
    <LicenseKeyDrawer v-model:open="drawerOpen" :key-id="drawerKeyId" @changed="refreshAll" />
  </div>
</template>

<script setup>
/**
 * Products › License Keys (plan feature `license_keys`).
 *   Overview  GET license-keys/dashboard → { products:[…counts, editions], totals }
 *   Keys      GET license-keys?… → { rows, total } (server paging, sort/dir)
 *   Settings  GET/POST license-keys/settings, POST license-keys/settings/rotate-key
 * Catalog for the modals / filters: GET license-keys/products → { products, warehouses }.
 * Permissions: license_keys_view (page), _manage (add/issue/settings),
 * _control (drawer: extend/suspend/revoke/activations), _reveal (full keys, export).
 * A 409 means the tenant migrations have not run (message from the server).
 */
import { ref, computed, watch, onMounted, createVNode } from 'vue';
import { message, Modal } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import dayjs from 'dayjs';
import {
  CloudUploadOutlined, PlusOutlined, WarningOutlined, DownloadOutlined, EyeOutlined, InfoCircleOutlined,
  SaveOutlined, CopyOutlined, SyncOutlined, KeyOutlined, ShoppingOutlined, RollbackOutlined,
  ClockCircleOutlined, ExclamationCircleOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import ImportKeysModal from './ImportKeysModal.vue';
import IssueLicensesModal from './IssueLicensesModal.vue';
import LicenseKeyDrawer from './LicenseKeyDrawer.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import {
  LK_STATUSES, LK_STATE_FILTERS, licenseStatusColor, licenseStatusLabel, licenseSourceColor,
  licenseSourceLabel, licenseError, licenseNotMigrated, copyText,
} from '../../composables/useLicenseKeys';
import { useAuthStore } from '../../stores/auth';
import { usePlanStore } from '../../stores/plan';
import http from '../../lib/http';

const { t } = useI18n();
const { date, dateTime } = useFormat();
const auth = useAuthStore();
const plan = usePlanStore();

const canManage = computed(() => auth.can('license_keys_manage'));
const canReveal = computed(() => auth.can('license_keys_reveal'));
const canViewSales = computed(() => auth.can('Sales_view'));
const planAllows = computed(() => plan.featureEnabled('license_keys'));

const tab = ref('overview');
const notMigrated = ref('');
const ready = computed(() => planAllows.value && !notMigrated.value);

function fail(e) {
  if (licenseNotMigrated(e)) notMigrated.value = e.data.message;
  else message.error(licenseError(e));
}

async function copy(text) {
  if (text && await copyText(text)) message.success(t('Copied'));
}

const isPast = v => !!v && dayjs(v).isBefore(dayjs());
const isSoon = v => !!v && dayjs(v).isBefore(dayjs().add(30, 'day'));

/* ----------------------------------------------------------------- catalog */
const catalog = ref({ products: [], warehouses: [] });
const poolProducts = computed(() => catalog.value.products.filter(p => p.mode === 'pool'));
const generatedProducts = computed(() => catalog.value.products.filter(p => p.mode === 'generated'));

async function loadCatalog() {
  try {
    const data = await http.get('license-keys/products');
    catalog.value = { products: data?.products || [], warehouses: data?.warehouses || [] };
  } catch (e) {
    fail(e);
  }
}

/* ---------------------------------------------------------------- overview */
const dash = ref(null);
const dashLoading = ref(false);

async function loadDashboard() {
  dashLoading.value = true;
  try {
    dash.value = (await http.get('license-keys/dashboard')) || {};
    backorders.value = Number(dash.value?.totals?.backordered) > 0
      ? ((await http.get('license-keys/backorders'))?.rows || [])
      : [];
  } catch (e) {
    fail(e);
  } finally {
    dashLoading.value = false;
  }
}

// Sales still owed keys.
const backorders = ref([]);
const backorderPanel = ref(null);
const assigningSale = ref(null);
const backorderColumns = computed(() => [
  { title: t('Sale'), key: 'sale' },
  { title: t('Customer'), dataIndex: 'client_name', key: 'client' },
  { title: t('Product'), key: 'product' },
  { title: t('LicK_Missing_Keys'), key: 'missing', align: 'right' },
  { title: '', key: 'actions', align: 'right' },
]);
async function assignSale(saleId) {
  assigningSale.value = saleId;
  try {
    const r = await http.post(`license-keys/sales/${saleId}/sync`, {});
    const n = Number(r?.assigned) || 0;
    if (n) message.success(t('LicK_Assigned_Msg', { n }));
    else message.warning(t('LicK_Assigned_None'));
    await loadDashboard();
  } catch (e) {
    message.error(licenseError(e));
  } finally {
    assigningSale.value = null;
  }
}

const C = {
  green: ['#52c41a', 'rgba(82,196,26,0.12)'],
  blue: ['#1677ff', 'rgba(22,119,255,0.12)'],
  amber: ['#faad14', 'rgba(250,173,20,0.14)'],
  red: ['#ff4d4f', 'rgba(255,77,79,0.12)'],
  purple: ['#722ed1', 'rgba(114,46,209,0.12)'],
};
const kpiCards = computed(() => {
  const tot = dash.value?.totals || {};
  const n = k => Number(tot[k]) || 0;
  const card = (label, value, color, icon, extra = {}) => ({ label, value, icon, color: color[0], tint: color[1], ...extra });
  return [
    card('LicK_Kpi_Available', n('available'), C.green, KeyOutlined, {
      go: () => viewKeys(null, { status: 'available', source: 'pool' }),
    }),
    card('LicK_Kpi_Sold', n('sold'), C.blue, ShoppingOutlined, { go: () => viewKeys(null, { status: 'sold' }) }),
    card('LicK_Kpi_Returned', n('returned'), C.amber, RollbackOutlined, {
      valueColor: n('returned') ? '#faad14' : undefined,
      go: () => viewKeys(null, { status: 'returned' }),
    }),
    card('LicK_Kpi_Low', n('low_stock'), C.red, WarningOutlined, { valueColor: n('low_stock') ? '#ff4d4f' : undefined }),
    card('LicK_Kpi_Expiring', n('expiring_30'), C.purple, ClockCircleOutlined, {
      go: () => viewKeys(null, { status: 'expiring' }),
    }),
    card('LicK_Kpi_Backordered', n('backordered'), C.red, ExclamationCircleOutlined, {
      valueColor: n('backordered') ? '#ff4d4f' : undefined,
      go: n('backordered') ? () => backorderPanel.value?.$el?.scrollIntoView?.({ behavior: 'smooth' }) : null,
    }),
  ];
});

// Products with their editions as tree children (a-table expands them).
const productRows = computed(() => (dash.value?.products || []).map(p => ({
  ...p,
  rowKey: 'p' + p.id,
  counts: p.counts || {},
  children: (p.editions || []).length
    ? p.editions.map(e => ({
      rowKey: `p${p.id}-e${e.id}`,
      isEdition: true,
      id: p.id,
      variant_id: e.id,
      name: e.name,
      mode: p.mode,
      counts: e.counts || {},
      low_stock: false,
    }))
    : undefined,
})));

const productColumns = computed(() => [
  { title: t('Product'), key: 'name' },
  { title: t('Source'), key: 'mode' },
  { title: t('Status_available'), key: 'available', align: 'right' },
  { title: t('Status_sold'), key: 'sold', align: 'right' },
  { title: t('Returned'), key: 'returned', align: 'right' },
  { title: t('LicK_Blocked_Col'), key: 'blocked', align: 'right' },
  { title: t('LicK_Expiring_30'), key: 'expiring_30', align: 'right' },
  { title: t('Action'), key: 'actions' },
]);

/* -------------------------------------------------------------------- keys */
const blankFilters = () => ({
  product_id: undefined, variant_id: undefined, source: undefined, status: undefined, range: null, batch: '',
});
const filters = ref(blankFilters());
const hasFilters = computed(() => {
  const f = filters.value;
  return !!(f.product_id || f.variant_id || f.source || f.status || f.range || f.batch);
});

const keysCrud = useCrudTable('license-keys', {
  limit: 25,
  pageSizeOptions: ['10', '25', '50', '100', '200'],
  select: p => ({ rows: p?.rows || [], total: p?.total || 0 }),
  onError: fail,
  params: () => ({
    sort: keysCrud.sortField.value,
    dir: keysCrud.sortType.value,
    product_id: filters.value.product_id || '',
    variant_id: filters.value.variant_id || '',
    source: filters.value.source || '',
    status: filters.value.status || '',
    batch: filters.value.batch || '',
    from: filters.value.range?.[0] || '',
    to: filters.value.range?.[1] || '',
  }),
});

const productOptions = computed(() => catalog.value.products.map(p => ({
  value: p.id,
  label: p.code ? `${p.name} (${p.code})` : p.name,
})));
const filterVariantOptions = computed(() => {
  const p = catalog.value.products.find(x => x.id === filters.value.product_id);
  return (p?.variants || []).map(v => ({ value: v.id, label: v.name }));
});

function reloadKeys() {
  keysLoaded = true;
  keysCrud.reload();
}
function onFilterProduct() {
  filters.value.variant_id = undefined;
  reloadKeys();
}
function clearBatch() {
  filters.value.batch = '';
  reloadKeys();
}
function resetFilters() {
  filters.value = blankFilters();
  keysCrud.search.value = '';
  reloadKeys();
}

/** Jump to the Keys tab with a product (or edition) and/or status filter. */
function viewKeys(record, extra = {}) {
  const f = blankFilters();
  if (record) {
    f.product_id = record.id;
    if (record.isEdition) f.variant_id = record.variant_id;
  }
  Object.assign(f, extra);
  filters.value = f;
  keysCrud.search.value = '';
  tab.value = 'keys';
  reloadKeys();
}

const keyColumns = computed(() => [
  { title: t('LicK_Key'), dataIndex: 'masked_key', key: 'masked_key' },
  { title: t('Product'), key: 'product' },
  { title: t('Source'), dataIndex: 'source', key: 'source', defaultHidden: true },
  { title: t('Status'), dataIndex: 'status', key: 'status', sorter: true },
  { title: t('Customer'), dataIndex: 'client_name', key: 'client_name' },
  { title: t('Sale'), dataIndex: 'sale_ref', key: 'sale_ref' },
  { title: t('LicK_Sold_At'), dataIndex: 'sold_at', key: 'sold_at', sorter: true },
  { title: t('Expiry'), dataIndex: 'expires_at', key: 'expires_at', sorter: true },
  { title: t('LicK_Activations'), key: 'activations', align: 'center' },
  { title: t('Created'), dataIndex: 'created_at', key: 'created_at', sorter: true, defaultHidden: true },
  { title: t('Note'), dataIndex: 'note', key: 'note', defaultHidden: true },
  { title: t('Action'), key: 'actions', align: 'center', width: 70 },
]);

const exporting = ref(false);
function exportCsv() {
  Modal.confirm({
    title: t('LicK_Export_Csv'),
    icon: createVNode(ExclamationCircleOutlined),
    content: t('LicK_Export_Confirm'),
    okText: t('Export'),
    cancelText: t('Delete_cancelButtonText'),
    async onOk() {
      const f = filters.value;
      const qs = new URLSearchParams();
      if (f.product_id) qs.append('product_id', f.product_id);
      // The export knows stock statuses only (not active / expired / expiring).
      if (f.status && LK_STATUSES.includes(f.status)) qs.append('status', f.status);
      if (f.source) qs.append('source', f.source);
      if (f.batch) qs.append('batch', f.batch);
      const s = qs.toString();
      exporting.value = true;
      try {
        await http.download('license-keys/export' + (s ? '?' + s : ''), `license-keys-${dayjs().format('YYYYMMDD-HHmmss')}.csv`);
      } catch (e) {
        fail(e);
      } finally {
        exporting.value = false;
      }
    },
  });
}

/* ---------------------------------------------------------------- settings */
const settings = ref({ email_enabled: false, show_on_receipt: false, api_enabled: false, offline_grace_days: 0, reminder_days: '' });
const publicKey = ref('');
const apiBase = ref('');
const settingsLoading = ref(false);
const settingsSaving = ref(false);
const rotating = ref(false);

function takeSettings(data) {
  const s = data?.settings || {};
  settings.value = {
    email_enabled: !!s.email_enabled,
    show_on_receipt: !!s.show_on_receipt,
    api_enabled: !!s.api_enabled,
    offline_grace_days: Number(s.offline_grace_days) || 0,
    reminder_days: s.reminder_days || '',
  };
  publicKey.value = data?.public_key || '';
  apiBase.value = data?.api_base || '';
}

async function loadSettings() {
  settingsLoading.value = true;
  try {
    takeSettings(await http.get('license-keys/settings'));
  } catch (e) {
    fail(e);
  } finally {
    settingsLoading.value = false;
  }
}

async function saveSettings() {
  const s = settings.value;
  settingsSaving.value = true;
  try {
    takeSettings(await http.post('license-keys/settings', {
      email_enabled: s.email_enabled ? 1 : 0,
      show_on_receipt: s.show_on_receipt ? 1 : 0,
      api_enabled: s.api_enabled ? 1 : 0,
      offline_grace_days: Number(s.offline_grace_days) || 0,
      reminder_days: (s.reminder_days || '').replace(/\s+/g, ''),
    }));
    message.success(t('Successfully_Updated'));
  } catch (e) {
    fail(e);
  } finally {
    settingsSaving.value = false;
  }
}

function rotateKey() {
  Modal.confirm({
    title: t('LicK_Rotate_Key'),
    icon: createVNode(ExclamationCircleOutlined),
    content: t('LicK_Rotate_Confirm'),
    okText: t('LicK_Rotate_Key'),
    okType: 'danger',
    cancelText: t('Delete_cancelButtonText'),
    async onOk() {
      rotating.value = true;
      try {
        takeSettings(await http.post('license-keys/settings/rotate-key', {}));
        message.success(t('LicK_Rotated'));
      } catch (e) {
        fail(e);
      } finally {
        rotating.value = false;
      }
    },
  });
}

/* ------------------------------------------------------------- API guide */
const guideEndpoints = [
  { path: '/activate', help: 'LicK_G_Activate' },
  { path: '/validate', help: 'LicK_G_Validate' },
  { path: '/deactivate', help: 'LicK_G_Deactivate' },
  { path: '/status', help: 'LicK_G_Status' },
];
const guideErrors = [
  'invalid_key', 'product_mismatch', 'invalid_request', 'not_activated', 'activation_limit_reached',
  'license_expired', 'license_suspended', 'license_revoked', 'too_many_attempts', 'unavailable',
];
const baseOrPlaceholder = computed(() => apiBase.value || 'https://your-store.example.com/api/licensing/v1');
const keyOrPlaceholder = computed(() => publicKey.value || 'PUBLIC_KEY_BASE64');

const curlSnippet = computed(() => `curl -X POST ${baseOrPlaceholder.value}/activate \\
  -H "Content-Type: application/json" \\
  -d '{"license_key":"XXXXX-XXXXX-XXXXX-XXXXX","product":"PRODUCT-CODE","domain":"example.com","nonce":"5f1c9a…random…"}'

# device-locked licenses send instead:
#   "device_id":"9f2c…","device_name":"Office PC"`);

const responseSnippet = `{
  "action": "activate",
  "key_fingerprint": "sha256 of the upper-cased, trimmed key",
  "nonce": "5f1c9a…random…",
  "host": "your-store.example.com",
  "identifier": "example.com",
  "valid": true,
  "status": "active",
  "product": "PRODUCT-CODE",
  "product_name": "My App Pro",
  "edition": "Business",
  "key_last4": "AB12",
  "expires_at": "2027-11-13T00:00:00+00:00",
  "restriction": "domain",
  "activations": { "used": 1, "max": 3 },
  "offline_until": "2026-11-20T10:00:00+00:00",
  "issued_at": "2026-11-13T10:00:00+00:00",
  "token": "eyJ2YWxpZCI6dHJ1ZSwi…",
  "signature": "k3V0…"
}
// refused: "valid": false, "error": "license_expired", "message": "This license has expired."`;

const phpSnippet = computed(() => `$publicKey = '${keyOrPlaceholder.value}';
$res = json_decode($body, true);   // answer of /activate, /validate, /deactivate or /status

$ok = isset($res['token'], $res['signature']) && sodium_crypto_sign_verify_detached(
    base64_decode($res['signature']),
    $res['token'],
    base64_decode($publicKey)
);
$license = $ok ? json_decode(base64_decode($res['token']), true) : null;   // trust only this

// Use is allowed only when the signed answer is for THIS key, device and request:
$allowed = $license
    && in_array($license['action'], ['activate', 'validate'], true)
    && $license['valid'] === true
    && hash_equals(hash('sha256', strtoupper(trim($licenseKey))), $license['key_fingerprint'])
    && $license['identifier'] === $myDomainOrDeviceId      // domain: lower-case, no www.
    && $license['nonce'] === $nonceSent;`);

const nodeSnippet = computed(() => `const crypto = require('crypto');
const PUBLIC_KEY = '${keyOrPlaceholder.value}';
// raw 32-byte Ed25519 key -> SPKI DER
const publicKey = crypto.createPublicKey({
  key: Buffer.concat([Buffer.from('302a300506032b6570032100', 'hex'), Buffer.from(PUBLIC_KEY, 'base64')]),
  format: 'der', type: 'spki',
});
const ok = !!res.token && !!res.signature &&
  crypto.verify(null, Buffer.from(res.token), publicKey, Buffer.from(res.signature, 'base64'));
// or with tweetnacl:
// nacl.sign.detached.verify(Buffer.from(res.token), Buffer.from(res.signature, 'base64'), Buffer.from(PUBLIC_KEY, 'base64'))
const license = ok ? JSON.parse(Buffer.from(res.token, 'base64').toString('utf8')) : null;

// Use is allowed only when the signed answer is for THIS key, device and request:
const fingerprint = crypto.createHash('sha256').update(licenseKey.trim().toUpperCase()).digest('hex');
const allowed = !!license && ['activate', 'validate'].includes(license.action) && license.valid === true
  && license.key_fingerprint === fingerprint
  && license.identifier === myDomainOrDeviceId   // domain: lower-case, no www.
  && license.nonce === nonceSent;`);

/* ------------------------------------------------------------ modals / drawer */
const importOpen = ref(false);
const issueOpen = ref(false);
const preset = ref(null);
const drawerOpen = ref(false);
const drawerKeyId = ref(null);

function presetFrom(record) {
  return record ? { product_id: record.id, variant_id: record.isEdition ? record.variant_id : null } : null;
}
function openImport(record = null) {
  preset.value = presetFrom(record);
  importOpen.value = true;
}
function openIssue(record = null) {
  preset.value = presetFrom(record);
  issueOpen.value = true;
}
function openKey(record) {
  drawerKeyId.value = record.id;
  drawerOpen.value = true;
}

/** After an import / issue: show exactly the keys that batch created. */
function afterBatch(result) {
  loadDashboard();
  if (result?.batch) {
    viewKeys({ id: result.product_id }, { batch: result.batch });
  } else if (keysLoaded) {
    keysCrud.fetchRows();
  }
}

function refreshAll() {
  loadDashboard();
  if (keysLoaded) keysCrud.fetchRows();
}

/* ------------------------------------------------------------ tab loading */
let keysLoaded = false;
let settingsLoaded = false;
watch(tab, (k) => {
  if (k === 'keys' && !keysLoaded) reloadKeys();
  else if (k === 'settings' && !settingsLoaded) {
    settingsLoaded = true;
    loadSettings();
  }
});

onMounted(() => {
  if (!planAllows.value) return;
  loadDashboard();
  loadCatalog();
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
.muted { color: rgba(var(--ink-rgb), 0.45); }
.small { font-size: 12px; }
.mono { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; }
.nowrap { white-space: nowrap; }
.danger { color: #ff4d4f; }
.warn { color: #d48806; }
a.warn { font-weight: 600; }

.kpis {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(210px, 1fr));
  gap: 16px;
  margin-bottom: 16px;
}
.stat-card { border-radius: 10px; height: 100%; }
.stat-card.clickable { cursor: pointer; }
.stat-card.clickable:hover { border-color: var(--ant-color-primary, #1677ff); }
.stat-inner { display: flex; align-items: center; gap: 12px; }
.stat-icon {
  width: 44px; height: 44px; border-radius: 10px;
  display: flex; align-items: center; justify-content: center;
  font-size: 20px; flex: none;
}
.stat-meta { min-width: 0; }
.stat-label { opacity: 0.65; font-size: 13px; margin-bottom: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.stat-value { font-size: 20px; font-weight: 700; white-space: nowrap; }
.panel { border-radius: 10px; }

.settings-card { max-width: 860px; border-radius: 10px; }
.setting-row {
  display: flex; align-items: center; justify-content: space-between; gap: 16px;
  padding: 14px 4px; border-bottom: 1px solid var(--line);
}
.setting-row:last-child { border-bottom: none; }
.setting-label { font-weight: 600; }
.setting-help { font-size: 12px; color: rgba(var(--ink-rgb), 0.5); margin-top: 2px; max-width: 600px; }
.field { padding: 10px 4px; }
.field + .field { border-top: 1px solid var(--line); }
.copy-group { display: flex !important; margin-top: 4px; }
.copy-group :deep(.ant-input) { flex: 1 1 auto; }

.guide h4 { margin: 16px 0 6px; font-size: 14px; font-weight: 600; }
.guide h4:first-child { margin-top: 0; }
.guide p { margin: 0 0 8px; font-size: 13px; }
.g-table { width: 100%; border-collapse: collapse; font-size: 13px; margin-bottom: 8px; }
.g-table td { padding: 5px 8px; border-bottom: 1px solid var(--line); vertical-align: top; }
.g-table td:first-child { width: 1%; padding-inline-start: 0; }
.code {
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
  font-size: 12px; line-height: 1.5;
  background: rgba(var(--ink-rgb), 0.04);
  border: 1px solid var(--line); border-radius: 8px;
  padding: 10px 12px; margin: 0 0 10px;
  white-space: pre; overflow-x: auto;
}

@media (max-width: 575px) {
  .stat-icon { width: 36px; height: 36px; font-size: 16px; }
  .stat-value { font-size: 16px; }
  .setting-row { flex-direction: column; align-items: flex-start; }
  .kpis { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
}
</style>
