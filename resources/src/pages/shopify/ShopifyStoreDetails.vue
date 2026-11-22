<template>
  <div class="page">
    <PageHeader
      :title="store.name || $t('Store')"
      :subtitle="store.shop_domain"
      :breadcrumb="['Shopify', $t('Stores'), store.shop_domain || '']"
    >
      <template #actions>
        <a-button @click="$router.push('/shopify/stores')">
          <template #icon><ArrowLeftOutlined /></template>
          {{ $t('Back') }}
        </a-button>
        <a-button :loading="testing" @click="test">
          <template #icon><ApiOutlined /></template>
          {{ $t('Test_Connection') }}
        </a-button>
        <a-button @click="edit()">
          <template #icon><EditOutlined /></template>
          {{ $t('Edit_Store') }}
        </a-button>
        <a-button type="primary" @click="$router.push(`/shopify/sync?store_id=${id}`)">
          <template #icon><SyncOutlined /></template>
          {{ $t('Sync') }}
        </a-button>
      </template>
    </PageHeader>

    <a-spin :spinning="loading">
      <a-alert
        v-if="store.status === 'error'"
        type="error" show-icon style="margin-bottom: 16px"
        :message="$t('Shp_Store_Not_Reachable')"
        :description="store.last_error"
      />

      <div class="kpis">
        <div class="kpi">
          <span class="kpi-label">{{ $t('Status') }}</span>
          <span class="kpi-value">
            <a-tag :color="optionOf(STORE_STATUSES, store.status).color">
              {{ labelOf(STORE_STATUSES, store.status) }}
            </a-tag>
          </span>
          <span class="kpi-sub">{{ store.last_connected_at ? dateTime(store.last_connected_at) : $t('Shp_Never_Verified') }}</span>
        </div>
        <div class="kpi">
          <span class="kpi-label">{{ $t('Shop') }}</span>
          <span class="kpi-value kpi-value--sm">{{ store.shop_name || '—' }}</span>
          <span class="kpi-sub">{{ store.currency || '' }} {{ store.shop_email || '' }}</span>
        </div>
        <div class="kpi">
          <span class="kpi-label">{{ $t('Warehouse') }}</span>
          <span class="kpi-value kpi-value--sm">{{ store.warehouse_name || $t('Shp_Not_Set') }}</span>
          <span class="kpi-sub">{{ $t('Shp_Shopify_Location_N', { id: store.location_id || '—' }) }}</span>
        </div>
        <div class="kpi">
          <span class="kpi-label">{{ $t('Shp_Linked_Records') }}</span>
          <span class="kpi-value">{{ number(totalLinked, 0) }}</span>
          <span class="kpi-sub">{{ $t('Shp_N_Orders_Imported', { n: links.order || 0 }) }}</span>
        </div>
        <div class="kpi">
          <span class="kpi-label">{{ $t('Last_Sync') }}</span>
          <span class="kpi-value kpi-value--sm">{{ store.last_sync_at ? dateTime(store.last_sync_at) : $t('Shp_Never') }}</span>
          <span class="kpi-sub">{{ store.auto_sync ? $t('Shp_Auto_Every_N_Min_Cap', { n: store.sync_interval_minutes }) : $t('Shp_Manual_Only') }}</span>
        </div>
      </div>

      <a-card size="small">
        <a-tabs v-model:activeKey="tab">
          <!-- ------------------------------------------------ overview -->
          <a-tab-pane key="overview" :tab="$t('Shp_Overview')">
            <a-row :gutter="16">
              <a-col :xs="24" :xl="10">
                <a-descriptions bordered size="small" :column="1" :title="$t('Shp_Mapped_Records')">
                  <a-descriptions-item v-for="(count, type) in links" :key="type" :label="linkLabel(type)">
                    <a class="link" @click="$router.push(`/shopify/mappings?store_id=${id}&entity_type=${type}`)">
                      {{ number(count, 0) }}
                    </a>
                  </a-descriptions-item>
                </a-descriptions>
              </a-col>
              <a-col :xs="24" :xl="14">
                <a-card size="small" :title="$t('Shp_What_Store_Syncs')" class="inner">
                  <div class="ent-list">
                    <div v-for="entity in ENTITIES" :key="entity.value" class="ent-row">
                      <component :is="entity.icon" :size="15" class="ent-ic" />
                      <span class="ent-name">{{ entity.label }}</span>
                      <a-tag :color="store[`sync_${entity.value}`] ? 'success' : 'default'">
                        {{ store[`sync_${entity.value}`] ? $t('Shp_On') : $t('Shp_Off') }}
                      </a-tag>
                    </div>
                  </div>
                </a-card>
              </a-col>
            </a-row>

            <!-- what the scheduler will do next, whether or not it is switched on -->
            <a-card size="small" class="inner" style="margin-top: 16px">
              <template #title>
                {{ $t('Shp_Automatic_Sync') }}
                <a-tag :color="auto.enabled ? 'success' : 'default'" style="margin-inline-start: 6px">
                  {{ auto.enabled ? $t('Shp_On') : $t('Shp_Off') }}
                </a-tag>
                <a-tag v-if="auto.using_default_plan" color="default">{{ $t('Shp_Default_Plan') }}</a-tag>
                <a-tag v-if="store.instant_stock_push" color="blue">{{ $t('Shp_Stock_Pushed_On_Change') }}</a-tag>
                <a class="link edit-link" @click="edit('auto')">{{ $t('Change') }}</a>
              </template>

              <a-empty
                v-if="!auto.steps.length" :image="simpleImage"
                :description="$t('Shp_Nothing_Scheduled')"
              />
              <template v-else>
                <a-table
                  size="small" :columns="autoColumns" :data-source="auto.steps" :row-key="stepKey"
                  :pagination="false" :scroll="{ x: 640 }"
                >
                  <template #bodyCell="{ column, record }">
                    <template v-if="column.key === 'step'">
                      {{ entityOf(record.entity).label }}
                      <a-tag :color="optionOf(DIRECTIONS, record.direction).color" class="dir-tag">
                        {{ labelOf(DIRECTIONS, record.direction) }}
                      </a-tag>
                    </template>
                    <template v-else-if="column.key === 'every'">{{ $t('Shp_Every_N_Min', { n: record.every }) }}</template>
                    <template v-else-if="column.key === 'last_run_at'">
                      {{ record.last_run_at ? dateTime(record.last_run_at) : $t('Shp_Never_Lc') }}
                    </template>
                    <template v-else-if="column.key === 'next_due_at'">
                      {{ record.blocked ? '—' : dateTime(record.next_due_at) }}
                    </template>
                    <template v-else-if="column.key === 'state'">
                      <a-tooltip :title="record.blocked || ''">
                        <a-tag :color="stepColor(record)">{{ stepState(record) }}</a-tag>
                      </a-tooltip>
                    </template>
                  </template>
                </a-table>
                <p class="foot-note">
                  <template v-if="!auto.enabled">
                    {{ $t('Shp_Auto_Off_Note') }}
                  </template>
                  <template v-else>
                    {{ $t('Shp_Auto_On_Note') }}
                  </template>
                </p>
              </template>

              <!-- The question people actually arrive with: "I sold it at the
                   till, why is Shopify still offering it?" Answered here
                   rather than left to be discovered in a settings tab. -->
              <a-alert
                v-if="store.sync_inventory && !store.instant_stock_push"
                type="info" show-icon style="margin-top: 14px"
                :message="$t('Shp_Stock_Only_On_Push')"
              >
                <template #description>
                  <i18n-t keypath="Shp_Stock_Lag_Desc" tag="span">
                    <template #n>{{ inventoryEvery }}</template>
                    <template #setting><strong>{{ $t('Shp_Push_Stock_On_Change') }}</strong></template>
                  </i18n-t>
                  <a class="link" @click="edit('auto')">{{ $t('Shp_Turn_It_On') }}</a>
                </template>
              </a-alert>
              <p v-else-if="store.instant_stock_push" class="foot-note">
                {{ $t('Shp_Instant_Push_Note') }}
              </p>
            </a-card>
          </a-tab-pane>

          <!-- ------------------------------------------------ runs -->
          <a-tab-pane key="runs">
            <template #tab>
              <span>{{ $t('Shp_Sync_History') }} <a-badge :count="runs.length" :number-style="badgeStyle" /></span>
            </template>
            <a-empty v-if="!runs.length" :image="simpleImage" :description="$t('Shp_Nothing_Run_For_Store')" />
            <a-table
              v-else size="small" :columns="runColumns" :data-source="runs" row-key="id"
              :pagination="false" :scroll="{ x: 820 }"
            >
              <template #bodyCell="{ column, record }">
                <template v-if="column.key === 'entity'">{{ entityOf(record.entity).label }}</template>
                <template v-else-if="column.key === 'direction'">
                  <a-tag :color="optionOf(DIRECTIONS, record.direction).color">
                    {{ labelOf(DIRECTIONS, record.direction) }}
                  </a-tag>
                </template>
                <template v-else-if="column.key === 'status'">
                  <a-tag :color="optionOf(RUN_STATUSES, record.status).color">
                    {{ labelOf(RUN_STATUSES, record.status) }}
                  </a-tag>
                </template>
                <template v-else-if="column.key === 'result'">
                  {{ $t('Shp_Counts_Short', { created: record.created, updated: record.updated, skipped: record.skipped }) }}
                  <template v-if="record.failed"> · <span class="bad">{{ $t('Shp_N_Fail', { n: record.failed }) }}</span></template>
                </template>
                <template v-else-if="column.key === 'started_at'">
                  {{ record.started_at ? dateTime(record.started_at) : '—' }}
                </template>
              </template>
            </a-table>
          </a-tab-pane>

          <!-- ------------------------------------------------ webhooks -->
          <a-tab-pane key="webhooks" :tab="$t('Webhooks')">
            <a-alert
              type="info" show-icon style="margin-bottom: 14px"
              :message="store.auth_mode === 'client_credentials'
                ? $t('Shp_Hook_Register_Cc')
                : $t('Shp_Hook_Register_Token')"
            >
              <template #description>
                <p class="hook-note">
                  {{ $t('Shp_Hook_Note_Register') }}
                </p>
                <div class="hook-url">
                  <code>{{ webhookUrl }}</code>
                  <a-button size="small" @click="copyUrl">{{ $t('Copy') }}</a-button>
                </div>
                <p class="hook-note">
                  <template v-if="store.auth_mode === 'client_credentials'">
                    {{ $t('Shp_Hook_Verified_Cc') }}
                  </template>
                  <template v-else>
                    {{ $t('Shp_Hook_Verified_Token') }}
                  </template>
                  {{ $t('Shp_Hook_Retries') }}
                </p>
              </template>
            </a-alert>

            <a-alert
              v-if="!store.has_webhook_secret"
              type="warning" show-icon style="margin-bottom: 14px"
              :message="$t('Shp_No_Signing_Secret')"
              :description="$t('Shp_No_Signing_Secret_Desc')"
            />

            <div class="hook-actions">
              <a-button type="primary" :loading="registering" @click="registerHooks(false)">
                <template #icon><ThunderboltOutlined /></template>
                {{ $t('Shp_Register_In_Shopify') }}
              </a-button>
              <a-button :loading="loadingHooks" @click="loadHooks">
                <template #icon><ReloadOutlined /></template>
                {{ $t('Refresh_List') }}
              </a-button>
              <a-popconfirm
                :title="$t('Shp_Tidy_Confirm')"
                :ok-text="$t('Remove')" :cancel-text="$t('Cancel')" @confirm="registerHooks(true)"
              >
                <a-button :disabled="!hookState.stale.length">
                  {{ $t('Shp_Tidy_Up') }}{{ hookState.stale.length ? ` (${hookState.stale.length})` : '' }}
                </a-button>
              </a-popconfirm>
              <span class="hook-count">
                {{ $t('Shp_Topics_Subscribed', { mine: hookState.mine, total: hookState.wanted.length }) }}
              </span>
            </div>

            <a-alert
              v-if="hookState.error" type="error" show-icon style="margin: 12px 0"
              :message="hookState.error"
            />

            <div class="topics">
              <span class="topics-label">{{ $t('Shp_Topics_Handled') }}</span>
              <a-tooltip
                v-for="topic in WEBHOOK_TOPICS" :key="topic"
                :title="topicHint(topic)"
              >
                <a-tag class="topic" :color="topicColor(topic)">{{ topic }}</a-tag>
              </a-tooltip>
            </div>

            <a-table
              v-if="hookState.webhooks.length"
              size="small" :columns="hookColumns" :data-source="hookState.webhooks" row-key="id"
              :pagination="false" :scroll="{ x: 700 }" style="margin-top: 12px"
            >
              <template #bodyCell="{ column, record }">
                <template v-if="column.key === 'address'">
                  <span :class="{ 'muted': !record.mine }">{{ record.address }}</span>
                  <a-tag v-if="!record.mine" color="default" style="margin-inline-start: 6px">{{ $t('Shp_Another_App') }}</a-tag>
                </template>
                <template v-else-if="column.key === 'actions'">
                  <a-popconfirm
                    :title="$t('Shp_Delete_Subscription_Confirm')" :ok-text="$t('Delete')" :cancel-text="$t('Cancel')"
                    @confirm="deleteHook(record)"
                  >
                    <a-button type="link" danger size="small" :loading="deletingHook === record.id">{{ $t('Delete') }}</a-button>
                  </a-popconfirm>
                </template>
              </template>
            </a-table>

            <a-table
              size="small" :columns="eventColumns" :data-source="events" row-key="id"
              :loading="loadingEvents" :pagination="{ pageSize: 10 }" :scroll="{ x: 760 }"
              style="margin-top: 14px"
            >
              <template #bodyCell="{ column, record }">
                <template v-if="column.key === 'status'">
                  <a-tooltip :title="record.error || ''">
                    <a-tag :color="optionOf(WEBHOOK_STATUSES, record.status).color">
                      {{ labelOf(WEBHOOK_STATUSES, record.status) }}
                    </a-tag>
                  </a-tooltip>
                </template>
                <template v-else-if="column.key === 'created_at'">{{ dateTime(record.created_at) }}</template>
                <template v-else-if="column.key === 'actions'">
                  <a-button
                    v-if="record.status === 'failed'" type="link" size="small"
                    :loading="replaying === record.id" @click="replay(record)"
                  >
                    {{ $t('Shp_Replay') }}
                  </a-button>
                </template>
              </template>
            </a-table>
          </a-tab-pane>

          <!-- ------------------------------------------------ setup guide -->
          <a-tab-pane key="guide" :tab="$t('Shp_Setup_Guide')">
            <ShopifyGuide
              :store="store" :cron="cron" :webhook-url="webhookUrl" can-edit
              @goto="tab = $event" @edit="edit"
            />
          </a-tab-pane>

          <!-- ------------------------------------------------ errors -->
          <a-tab-pane key="errors">
            <template #tab>
              <span>{{ $t('Errors') }} <a-badge :count="errors.length" :number-style="badgeStyle" /></span>
            </template>
            <a-empty v-if="!errors.length" :image="simpleImage" :description="$t('Shp_No_Errors_Recorded')" />
            <a-list v-else size="small" :data-source="errors">
              <template #renderItem="{ item }">
                <a-list-item>
                  <a-list-item-meta
                    :title="item.message"
                    :description="`${item.action}${item.entity ? ' · ' + item.entity : ''} · ${item.created_at}`"
                  />
                </a-list-item>
              </template>
            </a-list>
            <div style="margin-top: 12px">
              <a-button size="small" @click="$router.push(`/shopify/logs?store_id=${id}`)">{{ $t('Shp_All_Logs_For_Store') }}</a-button>
            </div>
          </a-tab-pane>
        </a-tabs>
      </a-card>
    </a-spin>

    <!-- Same modal the Stores list uses, so a connection is edited in one
         place however you got here. -->
    <ShopifyStoreForm
      v-model:open="formOpen" :store="store" :warehouses="warehouses" :initial-tab="formTab"
      @saved="onSaved"
    />
  </div>
</template>

<script setup>
/**
 * One store: how it is configured, what it has mapped, what it has run and what
 * webhooks it has received.
 *
 * Editing reuses the list page's modal rather than a second form — one place to
 * change a connection, reachable from either screen, so a setting can never be
 * offered in two shapes that disagree.
 */
import { ref, computed, watch, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import { message, Empty } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import {
  ArrowLeftOutlined, ApiOutlined, SyncOutlined, ThunderboltOutlined, ReloadOutlined, EditOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import ShopifyGuide from './ShopifyGuide.vue';
import ShopifyStoreForm from './ShopifyStoreForm.vue';
import { useFormat } from '../../composables/useFormat';
import {
  STORE_STATUSES, RUN_STATUSES, DIRECTIONS, WEBHOOK_STATUSES, WEBHOOK_TOPICS,
  ENTITIES, LINK_TYPES, entityOf, labelOf, optionOf, stepKey,
} from './shopifyOptions';
import http from '../../lib/http';

const route = useRoute();
const { t } = useI18n();
const { number, dateTime } = useFormat();
const simpleImage = Empty.PRESENTED_IMAGE_SIMPLE;
const badgeStyle = { backgroundColor: 'rgba(128,128,128,0.25)', color: 'inherit', boxShadow: 'none' };

const id = route.params.id;
const tab = ref('overview');
const loading = ref(false);
const testing = ref(false);

const store = ref({});
const links = ref({});
const runs = ref([]);
const errors = ref([]);
const webhookUrl = ref('');

const totalLinked = computed(() => Object.values(links.value).reduce((sum, n) => sum + (n || 0), 0));

const runColumns = [
  { title: t('Shp_Entity'), key: 'entity', dataIndex: 'entity', width: 130 },
  { title: t('Direction'), key: 'direction', dataIndex: 'direction', width: 150 },
  { title: t('Status'), key: 'status', dataIndex: 'status', width: 130 },
  { title: t('Shp_Result'), key: 'result', width: 250 },
  { title: t('Shp_Started'), key: 'started_at', dataIndex: 'started_at', width: 160 },
];

const eventColumns = [
  { title: t('Shp_Topic'), dataIndex: 'topic', key: 'topic', width: 180 },
  { title: t('Event'), dataIndex: 'event_id', key: 'event_id' },
  { title: t('Status'), key: 'status', dataIndex: 'status', width: 120 },
  { title: t('Received'), key: 'created_at', dataIndex: 'created_at', width: 160 },
  { title: '', key: 'actions', width: 90, align: 'center' },
];

function linkLabel(type) {
  return labelOf(LINK_TYPES, type);
}

// ---------------- automatic sync ----------------

const auto = ref({ enabled: false, using_default_plan: true, steps: [] });

const autoColumns = [
  { title: t('Shp_Step'), key: 'step', width: 230 },
  { title: t('Shp_Interval'), key: 'every', width: 110 },
  { title: t('Shp_Last_Run'), key: 'last_run_at', width: 160 },
  { title: t('Shp_Next_Due'), key: 'next_due_at', width: 160 },
  { title: '', key: 'state', width: 120 },
];

/**
 * Running beats blocked beats due. A step that is working right now is not
 * "blocked" whatever else is true of it, and a step that cannot run is worth
 * saying something about even when it is also overdue.
 */
function stepState(row) {
  if (row.running) return t('Running');
  if (row.blocked) return t('Blocked');
  if (!auto.value.enabled) return t('Shp_Manual');
  return row.due ? t('Shp_Due_Now') : t('Shp_Waiting');
}

function stepColor(row) {
  if (row.running) return 'processing';
  if (row.blocked) return 'error';
  if (!auto.value.enabled) return 'default';
  return row.due ? 'success' : 'default';
}

/** How long stock can lag without instant push: the inventory step's interval. */
const inventoryEvery = computed(() => {
  const step = auto.value.steps.find(s => s.entity === 'inventory' && s.direction === 'push');

  return step?.every || store.value.sync_interval_minutes || 60;
});

// ---------------- webhooks ----------------

const events = ref([]);
const loadingEvents = ref(false);
const replaying = ref(null);

async function loadEvents() {
  loadingEvents.value = true;
  try {
    const res = await http.get('shopify/webhook-events', { store_id: id, limit: 50 });
    events.value = res?.events || [];
  } catch (e) {
    events.value = [];
  } finally {
    loadingEvents.value = false;
  }
}

async function replay(record) {
  replaying.value = record.id;
  try {
    await http.post(`shopify/webhook-events/${record.id}/replay`);
    message.success(t('Shp_Replayed'));
    loadEvents();
    load();
  } catch (e) {
    message.error(e?.data?.message || t('Shp_Replay_Failed'));
  } finally {
    replaying.value = null;
  }
}

function copy(text, label = t('Cr_Copied')) {
  if (!text) return;
  navigator.clipboard?.writeText(text)
    .then(() => message.success(label))
    // No clipboard (an insecure origin, a locked-down browser): show the text
    // so it can still be selected by hand rather than failing silently.
    .catch(() => message.info(text));
}

function copyUrl() {
  copy(webhookUrl.value, t('Shp_URL_Copied'));
}

// ---------------- setup guide ----------------

// The platform always runs the scheduler (ShopifyStoreController::cronLines
// returns managed: true), so a failed request must not fall back to cron steps.
const cron = ref({ managed: true, base_path: '', sync: '', queue: '' });

// ---------------- editing ----------------

const warehouses = ref([]);
const formOpen = ref(false);
const formTab = ref('connection');

/** Open the edit modal, optionally straight onto one of its tabs. */
function edit(which = 'connection') {
  formTab.value = which;
  formOpen.value = true;
}

function onSaved() {
  formOpen.value = false;
  load();
}

// ---------------- webhook subscriptions ----------------
// What the shop is subscribed to, which is a different question from what it
// has delivered. Fetched from Shopify, so it is loaded when the tab is opened
// rather than on every visit to the page.

const hookState = ref({ webhooks: [], missing: [], stale: [], wanted: [], error: null, mine: 0 });
const loadingHooks = ref(false);
const registering = ref(false);
const deletingHook = ref(null);
const hooksLoaded = ref(false);

const hookColumns = [
  { title: t('Shp_Topic'), dataIndex: 'topic', key: 'topic', width: 190 },
  { title: t('Shp_Delivers_To'), key: 'address' },
  { title: 'API', dataIndex: 'api_version', key: 'api_version', width: 100 },
  { title: '', key: 'actions', width: 90, align: 'center' },
];

function topicColor(topic) {
  if (hookState.value.error || !hooksLoaded.value) return 'default';
  if (!hookState.value.wanted.includes(topic)) return 'default';

  return hookState.value.missing.includes(topic) ? 'warning' : 'success';
}

function topicHint(topic) {
  if (!hooksLoaded.value) return t('Shp_Hint_Press_Refresh');
  if (!hookState.value.wanted.includes(topic)) return t('Shp_Hint_Not_Needed');

  return hookState.value.missing.includes(topic) ? t('Shp_Not_Subscribed_Yet') : t('Shp_Subscribed');
}

async function loadHooks() {
  loadingHooks.value = true;
  try {
    const res = await http.get(`shopify/stores/${id}/webhooks`);
    const wanted = res?.wanted || [];
    hookState.value = {
      webhooks: res?.webhooks || [],
      missing: res?.missing || [],
      stale: res?.stale || [],
      wanted,
      error: null,
      mine: (res?.webhooks || []).filter(h => h.mine && wanted.includes(h.topic)).length,
    };
    hooksLoaded.value = true;
  } catch (e) {
    hookState.value = {
      ...hookState.value,
      error: e?.data?.error || t('Shp_Could_Not_Read_Webhooks'),
    };
  } finally {
    loadingHooks.value = false;
  }
}

async function registerHooks(prune) {
  registering.value = true;
  try {
    const res = await http.post(`shopify/stores/${id}/webhooks`, { prune: !!prune });
    const created = res?.created?.length || 0;
    const deleted = res?.deleted?.length || 0;

    if (res?.failed?.length) {
      message.warning(t('Shp_Hooks_Created_Failed', { created, failed: res.failed.length }));
    } else if (created || deleted) {
      message.success(t(created === 1 ? 'Shp_Hooks_Created_One' : 'Shp_Hooks_Created_Many', { n: created })
        + (deleted ? t('Shp_Hooks_Removed_Suffix', { n: deleted }) : ''));
    } else {
      message.info(t('Shp_Already_Subscribed'));
    }

    if (res?.warning) message.warning(res.warning, 8);
  } catch (e) {
    message.error(e?.data?.error || t('Shp_Could_Not_Register_Webhooks'));
  } finally {
    registering.value = false;
    loadHooks();
  }
}

async function deleteHook(record) {
  deletingHook.value = record.id;
  try {
    await http.delete(`shopify/stores/${id}/webhooks/${record.id}`);
    message.success(t('Shp_Subscription_Removed'));
  } catch (e) {
    message.error(e?.data?.error || t('Shp_Could_Not_Remove_Subscription'));
  } finally {
    deletingHook.value = null;
    loadHooks();
  }
}

// Opening the tab is the signal to go and ask Shopify.
watch(tab, (value) => {
  if (value === 'webhooks' && !hooksLoaded.value && !loadingHooks.value) loadHooks();
});

// ---------------- load ----------------

async function test() {
  testing.value = true;
  try {
    const res = await http.post(`shopify/stores/${id}/test`);
    message.success(t('Shp_Connected_To', { name: res?.shop?.name || store.value.shop_domain }));
  } catch (e) {
    message.error(e?.data?.error || t('Shp_Could_Not_Reach_Shop'));
  } finally {
    testing.value = false;
    load();
  }
}

async function load() {
  loading.value = true;
  try {
    const res = await http.get(`shopify/stores/${id}/overview`);
    store.value = res?.store || {};
    links.value = res?.links || {};
    runs.value = res?.runs || [];
    errors.value = res?.errors || [];
    auto.value = res?.auto_sync || { enabled: false, using_default_plan: true, steps: [] };
    cron.value = res?.cron || { managed: true, base_path: '', sync: '', queue: '' };
  } catch (e) {
    message.error(t('Shp_Could_Not_Load_Store'));
  } finally {
    loading.value = false;
  }
}

onMounted(async () => {
  load();
  loadEvents();
  try {
    const meta = await http.get('shopify/meta');
    webhookUrl.value = meta?.webhook_url || '';
    warehouses.value = meta?.warehouses || [];
  } catch (e) { /* the URL box stays empty */ }
});
</script>

<style scoped>
.bad {
  color: #dc2626;
}
.link {
  color: #5f9e3f;
  cursor: pointer;
}
.kpis {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
  gap: 12px;
  margin-bottom: 16px;
}
.kpi {
  display: flex;
  flex-direction: column;
  gap: 2px;
  padding: 13px 16px;
  border: 1px solid rgba(128, 128, 128, 0.2);
  border-radius: 14px;
}
.kpi-label {
  font-size: 12.5px;
  opacity: 0.65;
}
.kpi-value {
  font-size: 19px;
  font-weight: 600;
  line-height: 1.35;
}
.kpi-value--sm {
  font-size: 14.5px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.kpi-sub {
  font-size: 11.5px;
  opacity: 0.55;
}
.inner {
  height: 100%;
}
.ent-list {
  display: flex;
  flex-direction: column;
  gap: 9px;
}
.ent-row {
  display: flex;
  align-items: center;
  gap: 9px;
}
.ent-ic {
  opacity: 0.7;
  flex: none;
}
.ent-name {
  flex: 1;
  font-size: 13px;
}
.hook-url {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
  margin-top: 6px;
}
.hook-url code {
  padding: 3px 8px;
  border-radius: 6px;
  background: rgba(128, 128, 128, 0.14);
  font-size: 12px;
  word-break: break-all;
}
.hook-note {
  margin: 8px 0 0;
  font-size: 12px;
  opacity: 0.75;
}
.topics {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 5px;
}
.topics-label {
  font-size: 12.5px;
  opacity: 0.65;
  margin-inline-end: 4px;
}
.topic {
  font-family: monospace;
  font-size: 11px;
}
.hook-actions {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
  margin-bottom: 12px;
}
.hook-count {
  font-size: 12.5px;
  opacity: 0.65;
}
.muted {
  opacity: 0.55;
}
.dir-tag {
  font-size: 10.5px;
  line-height: 17px;
  margin-inline-start: 6px;
}
.foot-note {
  margin: 10px 0 0;
  font-size: 12px;
  opacity: 0.7;
}
.foot-note code {
  padding: 2px 6px;
  border-radius: 5px;
  background: rgba(128, 128, 128, 0.14);
  font-size: 11.5px;
}

/* the Change shortcut in the Automatic sync card title */
.edit-link {
  margin-inline-start: 8px;
  font-size: 12px;
  font-weight: 400;
}
</style>
