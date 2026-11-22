<template>
  <div class="guide">
    <p class="lead">
      <template v-if="store?.id">
        {{ cron.managed ? $t('Shp_G_Lead_Store') : $t('Shp_G_Lead_Store_Cmd') }}
      </template>
      <template v-else>
        {{ cron.managed ? $t('Shp_G_Lead_Generic') : $t('Shp_G_Lead_Generic_Cron') }}
      </template>
    </p>

    <!-- The choice, before either tab is opened: a tab nobody clicks is a
         feature nobody finds. -->
    <a-table
      size="small" :columns="wayColumns" :data-source="wayRows" row-key="what"
      :pagination="false" :scroll="{ x: 640 }" class="ways"
    >
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'what'"><strong>{{ record.what }}</strong></template>
      </template>
    </a-table>
    <p class="note ways-note">
      {{ $t('Shp_G_Ways_Note') }}
    </p>

    <!-- ---------------------------------------------- common ground -->
    <h3 class="heading">{{ $t('Shp_G_Both_Need') }}</h3>

    <section class="step">
      <h4><span class="num">1</span> {{ $t('Shp_G_Credentials') }}</h4>
      <p>
        {{ $t('Shp_G_Cred_Intro') }}
      </p>
      <ul class="plain">
        <li>
          <i18n-t keypath="Shp_G_Cred_Dev" tag="span">
            <template #app><strong>{{ $t('Shp_G_Dev_App') }}</strong></template>
            <template #path><em>dev.shopify.com → Apps → Create app</em></template>
            <template #clientId><strong>{{ $t('Client_ID') }}</strong></template>
            <template #clientSecret><strong>{{ $t('Client_Secret') }}</strong></template>
            <template #edit><strong>{{ $t('Edit_Store') }} → {{ $t('Connection') }}</strong></template>
          </i18n-t>
        </li>
        <li>
          <i18n-t keypath="Shp_G_Cred_Legacy" tag="span">
            <template #app><strong>{{ $t('Shp_G_Legacy_App') }}</strong></template>
            <template #token><strong>{{ $t('Admin_API_Access_Token') }}</strong></template>
            <template #prefix><code>shpat_…</code></template>
            <template #secret><strong>{{ $t('Shp_G_Api_Secret_Key') }}</strong></template>
          </i18n-t>
        </li>
        <li v-if="store?.id">
          {{ $t('Shp_Webhook_Signing_Secret') }}:
          <a-tag v-if="store.has_webhook_secret" color="success">{{ $t('Shp_G_Stored') }}</a-tag>
          <a-tag v-else color="warning">{{ $t('Shp_G_Missing_Refused') }}</a-tag>
        </li>
      </ul>
      <a-table
        size="small" :columns="scopeColumns" :data-source="scopeRows" row-key="entity"
        :pagination="false" :scroll="{ x: 520 }" class="scope-table"
      >
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'entity'">
            {{ record.label }}
            <a-tag v-if="store?.id" :color="record.on ? 'success' : 'default'">
              {{ record.on ? $t('Shp_On') : $t('Shp_Off') }}
            </a-tag>
          </template>
          <template v-else-if="column.key === 'scopes'">
            <code class="scopes">{{ record.scopes }}</code>
          </template>
        </template>
      </a-table>
    </section>

    <section class="step">
      <h4><span class="num">2</span> {{ $t('Shp_Where_Data_Lands') }}</h4>
      <p>
        <i18n-t keypath="Shp_G_Targets" tag="span">
          <template #path><em>{{ $t('Edit_Store') }} → {{ $t('Shp_Where_Data_Lands') }}.</em></template>
        </i18n-t>
      </p>
      <p v-if="store?.id">
        <i18n-t keypath="Shp_G_Currently" tag="span">
          <template #warehouse><strong>{{ store.warehouse_name || $t('Shp_G_Not_Set_Lc') }}</strong></template>
          <template #location><strong>{{ store.location_id || $t('Shp_G_Not_Set_Lc') }}</strong></template>
        </i18n-t>
        <a-tag v-if="store.warehouse_id && store.location_id" color="success">{{ $t('Shp_G_Ready') }}</a-tag>
        <a-tag v-else color="error">{{ $t('Shp_G_Incomplete') }}</a-tag>
      </p>
    </section>

    <section class="step">
      <h4><span class="num">3</span> {{ $t('Shp_G_Manual_Syncs') }}</h4>
      <p>
        <i18n-t keypath="Shp_G_Manual_Body" tag="span">
          <template #centre><strong>{{ $t('Page_Sync_Centre') }}</strong></template>
        </i18n-t>
        <template v-if="cron.managed">
          {{ $t('Shp_G_Worker_Managed') }}
        </template>
        <template v-else>
          {{ $t('Shp_G_Worker_Cron') }}
        </template>
      </p>
      <div v-if="!cron.managed && cron.manual" class="cmd">
        <code>{{ cron.manual }}</code>
        <a-button size="small" @click="copy(cron.manual, $t('Shp_G_Cron_Copied'))">{{ $t('Copy') }}</a-button>
      </div>
      <p class="note">
        {{ $t('Shp_G_Worker_Busy') }}
      </p>
    </section>

    <!-- ---------------------------------------------- the two ways -->
    <h3 class="heading">{{ $t('Shp_G_Pick_How') }}</h3>

    <a-tabs v-model:activeKey="way" type="card" class="way-tabs">
      <!-- ------------------------------------------- real time -->
      <a-tab-pane key="realtime">
        <template #tab>
          <span>
            {{ $t('Shp_G_Right_Away') }}
            <a-tag v-if="store?.id" :color="store.instant_stock_push ? 'success' : 'default'" class="tab-tag">
              {{ store.instant_stock_push ? $t('Shp_G_Stock_Live') : $t('Shp_G_Stock_Not_Live') }}
            </a-tag>
          </span>
        </template>

        <p class="pane-lead">
          <i18n-t keypath="Shp_G_Realtime_Lead" tag="span">
            <template #webhooks><strong>{{ $t('Shp_G_Webhooks_Lc') }}</strong></template>
            <template #push><strong>{{ $t('Shp_G_Instant_Push_Lc') }}</strong></template>
          </i18n-t>
        </p>

        <section class="step">
          <h4><span class="num">A</span> {{ $t('Shp_G_Webhooks_Heading') }}</h4>
          <p>
            <i18n-t keypath="Shp_G_Webhooks_Body" tag="span">
              <template #button><strong>{{ $t('Shp_Register_In_Shopify') }}</strong></template>
              <template #tab>
                <a v-if="store?.id" class="link" @click="$emit('goto', 'webhooks')">{{ $t('Shp_G_Webhooks_Tab') }}</a>
                <span v-else>{{ $t('Shp_G_Webhooks_Tab') }}</span>
              </template>
            </i18n-t>
          </p>
          <div class="cmd">
            <code>{{ webhookUrl }}</code>
            <a-button size="small" @click="copy(webhookUrl, $t('Shp_URL_Copied'))">{{ $t('Copy') }}</a-button>
          </div>
          <p class="note">
            <i18n-t keypath="Shp_G_Webhooks_Note" tag="span">
              <template #localhost><code>localhost</code></template>
              <template #test><code>.test</code></template>
            </i18n-t>
          </p>
        </section>

        <section class="step">
          <h4><span class="num">B</span> {{ $t('Shp_G_Push_Heading') }}</h4>
          <a-alert type="warning" show-icon class="callout" :message="$t('Shp_G_People_Miss')">
            <template #description>
              <p>
                <i18n-t keypath="Shp_G_Push_P1" tag="span">
                  <template #not><strong>{{ $t('Shp_G_Not') }}</strong></template>
                </i18n-t>
              </p>
              <p>
                <i18n-t keypath="Shp_G_Push_P2" tag="span">
                  <template #setting><strong>{{ $t('Shp_Push_Stock_On_Change') }}</strong></template>
                  <template #path><em>{{ $t('Edit_Store') }} → {{ $t('Shp_Automatic_Sync') }}</em></template>
                </i18n-t>
              </p>
              <p v-if="store?.id" class="callout-state">
                {{ $t('Shp_G_This_Store') }}
                <a-tag :color="store.instant_stock_push ? 'success' : 'warning'">
                  {{ store.instant_stock_push ? $t('Shp_G_Push_On') : $t('Shp_G_Push_Off') }}
                </a-tag>
                <a v-if="canEdit && !store.instant_stock_push" class="link" @click="$emit('edit', 'auto')">{{ $t('Shp_Turn_It_On') }}</a>
              </p>
            </template>
          </a-alert>
          <p class="note">
            {{ $t('Shp_G_Push_Note') }}
          </p>
        </section>

        <section class="step">
          <h4><span class="num">C</span> {{ $t('Shp_G_Nothing_Else') }}</h4>
          <p class="note">
            <i18n-t keypath="Shp_G_Nothing_Else_Body" tag="span">
              <template #after><em>{{ $t('Shp_G_After') }}</em></template>
            </i18n-t>
          </p>
          <p class="note">
            <i18n-t keypath="Shp_G_Exception" tag="span">
              <template #queue><code>webhooks</code></template>
            </i18n-t>
            <template v-if="cron.managed">
              {{ $t('Shp_G_Queue_Managed') }}
            </template>
            <template v-else>
              {{ $t('Shp_G_Queue_Cron') }}
            </template>
          </p>
          <div v-if="!cron.managed" class="cmd">
            <code>{{ cron.queue }}</code>
            <a-button size="small" @click="copy(cron.queue, $t('Shp_G_Cron_Copied'))">{{ $t('Copy') }}</a-button>
          </div>
        </section>
      </a-tab-pane>

      <!-- ------------------------------------------- scheduled -->
      <a-tab-pane key="scheduled">
        <template #tab>
          <span>
            {{ $t('Shp_G_On_Schedule') }}
            <a-tag v-if="store?.id" :color="store.auto_sync ? 'success' : 'default'" class="tab-tag">
              {{ store.auto_sync ? $t('Shp_G_On_Lc') : $t('Shp_G_Off_Lc') }}
            </a-tag>
          </span>
        </template>

        <p class="pane-lead">
          {{ $t('Shp_G_Scheduled_Lead') }}
        </p>

        <section v-if="cron.managed" class="step">
          <h4><span class="num">A</span> {{ $t('Shp_G_Nothing_To_Install') }}</h4>
          <p>
            {{ $t('Shp_G_Managed_Body') }}
          </p>
          <p class="note">
            {{ $t('Shp_G_Managed_Note') }}
          </p>
        </section>
        <section v-else class="step">
          <h4><span class="num">A</span> {{ $t('Shp_G_Cron_Entry') }}</h4>
          <p>
            {{ $t('Shp_G_Cron_Body') }}
          </p>
          <div class="cmd">
            <code>{{ cron.sync }}</code>
            <a-button size="small" @click="copy(cron.sync, $t('Shp_G_Cron_Copied'))">{{ $t('Copy') }}</a-button>
          </div>
          <p class="note">
            <i18n-t keypath="Shp_G_Cron_Note" tag="span">
              <template #every15><code>*/15</code></template>
              <template #php><code>php</code></template>
              <template #args><code>artisan shopify:sync</code></template>
              <template #path><code>{{ cron.base_path }}</code></template>
            </i18n-t>
          </p>
        </section>

        <section class="step">
          <h4><span class="num">B</span> {{ $t('Shp_G_Switch_On') }}</h4>
          <p>
            <i18n-t keypath="Shp_G_Plan_Body" tag="span">
              <template #path><em>{{ $t('Edit_Store') }} → {{ $t('Shp_Automatic_Sync') }}.</em></template>
              <template #step><strong>{{ $t('Shp_G_Step_Lc') }}</strong></template>
              <template #ex1><em>{{ $t('Shp_G_Ex1') }}</em></template>
              <template #ex2><em>{{ $t('Shp_G_Ex2') }}</em></template>
            </i18n-t>
          </p>
          <p class="note">
            {{ $t('Shp_G_Plan_Note') }}
          </p>
          <p v-if="store?.id">
            {{ $t('Shp_G_This_Store') }}
            <a-tag :color="store.auto_sync ? 'success' : 'default'">
              {{ store.auto_sync ? $t('Shp_G_Schedule_On') : $t('Shp_G_Schedule_Off') }}
            </a-tag>
            <a v-if="canEdit" class="link" @click="$emit('edit', 'auto')">{{ $t('Change') }}</a>
          </p>
        </section>

        <section class="step">
          <h4><span class="num">C</span> {{ $t('Shp_G_When_Runs') }}</h4>
          <p class="note">
            <i18n-t keypath="Shp_G_When_Body" tag="span">
              <template #started><em>{{ $t('Shp_G_Started_Lc') }}</em></template>
            </i18n-t>
          </p>
        </section>
      </a-tab-pane>
    </a-tabs>

    <!-- ---------------------------------------------- reference -->
    <h3 class="heading">{{ $t('Reference') }}</h3>

    <section class="step">
      <h4><span class="num">⚙</span> {{ $t('Shp_G_Where_Setting') }}</h4>
      <p class="note">
        <i18n-t keypath="Shp_G_Where_Note" tag="span">
          <template #path><strong>Shopify → {{ $t('Stores') }}</strong></template>
          <template #edit><strong>{{ $t('Edit_Store') }}</strong></template>
        </i18n-t>
      </p>
      <a-table
        size="small" :columns="settingColumns" :data-source="settingRows" row-key="setting"
        :pagination="false" :scroll="{ x: 560 }"
      >
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'where'"><strong>{{ record.where }}</strong></template>
        </template>
      </a-table>
    </section>

    <section class="step">
      <h4><span class="num">!</span> {{ $t('Shp_G_Product_Images') }}</h4>
      <ul class="plain">
        <li>
          <i18n-t keypath="Shp_G_Img1" tag="span">
            <template #push><strong>{{ $t('Shp_G_Products_Push') }}</strong></template>
          </i18n-t>
        </li>
        <li>
          <i18n-t keypath="Shp_G_Img2" tag="span">
            <template #assigned><strong>{{ $t('Shp_G_Assigned') }}</strong></template>
          </i18n-t>
        </li>
        <li>
          <i18n-t keypath="Shp_G_Img3" tag="span">
            <template #uploaded><strong>{{ $t('Shp_G_Uploaded') }}</strong></template>
          </i18n-t>
        </li>
        <li>
          {{ $t('Shp_G_Img4') }}
        </li>
        <li>
          <i18n-t keypath="Shp_G_Img5" tag="span">
            <template #not><em>{{ $t('Shp_G_Not') }}</em></template>
          </i18n-t>
        </li>
      </ul>
    </section>

    <section v-if="!cron.managed" class="step">
      <h4><span class="num">⌘</span> {{ $t('Shp_G_Commands') }}</h4>
      <div v-for="item in commands" :key="item.cmd" class="cmd cmd--row">
        <div class="cmd-body">
          <code>{{ item.cmd }}</code>
          <span class="cmd-what">{{ item.what }}</span>
        </div>
        <a-button size="small" @click="copy(item.cmd, $t('Shp_G_Command_Copied'))">{{ $t('Copy') }}</a-button>
      </div>
    </section>

    <p class="note foot">
      <i18n-t keypath="Shp_G_Foot" tag="span">
        <template #logs><strong>Shopify → {{ $t('Logs') }}</strong></template>
      </i18n-t>
    </p>
  </div>
</template>

<script setup>
/**
 * The setup guide, shared by the Shopify → Guide page and the Setup guide tab
 * on a store. One copy, so the two cannot drift; what changes between them is
 * only whether a store is in hand — with one, the guide reports that store's
 * actual state next to each step.
 *
 * Laid out as the module actually works: what both ways need, then a tab per
 * way, because their setup differs (a queue worker versus a cron entry) and
 * reading the steps for the path you are not taking is how people end up
 * configuring neither. The comparison table stays outside the tabs so the
 * choice is visible without opening either one.
 */
import { ref, computed } from 'vue';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { ENTITIES } from './shopifyOptions';

const props = defineProps({
  /** The store this guide is about, or null for the generic version. */
  store: { type: Object, default: null },
  /**
   * Scheduling facts from the server. `managed` means the platform runs the
   * scheduler and the queue itself, which is the hosted case — the guide then
   * says what happens instead of what to install, because a tenant has no
   * crontab to add a line to.
   */
  cron: { type: Object, default: () => ({ managed: true, base_path: '', sync: '', queue: '', manual: '' }) },
  webhookUrl: { type: String, default: '' },
  /** Whether to offer the "Change these" shortcut into the edit form. */
  canEdit: { type: Boolean, default: false },
});

defineEmits(['goto', 'edit']);

const { t } = useI18n();

// Real time first: it is the half people do not know is there.
const way = ref('realtime');

const wayColumns = [
  { title: '', key: 'what', dataIndex: 'what', width: 130 },
  { title: t('Shp_G_Right_Away'), dataIndex: 'realtime', key: 'realtime' },
  { title: t('Shp_G_On_Schedule'), dataIndex: 'scheduled', key: 'scheduled' },
];

const wayRows = [
  {
    what: t('Shp_G_Covers'),
    realtime: t('Shp_G_Way_Covers_Rt'),
    scheduled: t('Shp_G_Way_Covers_Sc'),
  },
  { what: t('Shp_G_Speed'), realtime: t('Shp_G_Seconds'), scheduled: t('Shp_G_Way_Speed_Sc') },
  {
    what: t('Shp_G_Needs'),
    realtime: t('Shp_G_Way_Needs_Rt'),
    scheduled: t('Shp_G_Way_Needs_Sc'),
  },
  {
    what: t('Shp_G_Switched_On_At'),
    realtime: `${t('Shp_G_Webhooks_Tab')} → ${t('Shp_Register_In_Shopify')} · ${t('Shp_Automatic_Sync')} → ${t('Shp_Push_Stock_On_Change')}`,
    scheduled: t('Shp_G_Way_Switch_Sc', { path: `${t('Shp_Automatic_Sync')} → ${t('Shp_Sync_On_Schedule')}` }),
  },
];

/**
 * The Admin API scopes each entity needs, next to whether this store actually
 * syncs it — so the list to grant in Shopify is the one on screen, not a
 * generic table to filter mentally.
 */
const SCOPES = {
  products: 'read_products, write_products',
  inventory: 'read_inventory, write_inventory, read_locations',
  customers: 'read_customers, write_customers',
  get orders() { return t('Shp_G_Scope_Orders', { a: 'read_orders', b: 'read_all_orders' }); },
  collections: 'read_products, write_products',
  fulfillments: 'read/write_merchant_managed_fulfillment_orders, read/write_assigned_fulfillment_orders',
};

const scopeColumns = [
  { title: t('Shp_Entity'), key: 'entity', width: 150 },
  { title: t('Shp_G_Admin_Scopes'), key: 'scopes' },
];

const scopeRows = computed(() => ENTITIES.map(e => ({
  entity: e.value,
  label: e.label,
  on: !!props.store?.[`sync_${e.value}`],
  scopes: SCOPES[e.value] || '',
})));

const settingColumns = [
  { title: t('Shp_G_Setting'), dataIndex: 'setting', key: 'setting', width: 230 },
  { title: t('Shp_G_Tab_In_Edit'), key: 'where', width: 170 },
  { title: '', dataIndex: 'what', key: 'what' },
];

const settingRows = [
  { setting: t('Shp_G_Set1'), where: t('Connection'), what: t('Shp_G_What1') },
  { setting: t('Shp_G_Set2'), where: t('Shp_Where_Data_Lands'), what: t('Shp_G_What2') },
  { setting: t('Shp_Price_Sent'), where: t('Shp_Where_Data_Lands'), what: t('Shp_G_What3') },
  { setting: t('Shp_G_Set4'), where: t('Shp_Where_Data_Lands'), what: t('Shp_G_What4') },
  { setting: t('Shp_G_Set5'), where: t('Shp_What_To_Sync'), what: t('Shp_G_What5') },
  { setting: t('Shp_Sync_On_Schedule'), where: t('Shp_Automatic_Sync'), what: t('Shp_G_What6') },
  { setting: t('Shp_Push_Stock_On_Change'), where: t('Shp_Automatic_Sync'), what: t('Shp_G_What7') },
  { setting: t('Shp_G_Set8'), where: t('Shp_Automatic_Sync'), what: t('Shp_G_What8') },
];

const commands = computed(() => {
  const id = props.store?.id;

  return [
    { cmd: 'php artisan shopify:sync --list', what: t('Shp_G_Cmd1') },
    { cmd: `php artisan shopify:sync --store=${id || '<id>'} --force`, what: t('Shp_G_Cmd2') },
    { cmd: `php artisan shopify:sync --store=${id || '<id>'} --entity=products --direction=push --only-unsynced`, what: t('Shp_G_Cmd3') },
    { cmd: `php artisan shopify:sync --store=${id || '<id>'} --entity=orders --direction=pull --since=2026-01-01`, what: t('Shp_G_Cmd4') },
    { cmd: `php artisan shopify:sync --store=${id || '<id>'} --entity=inventory --direction=push --dry-run`, what: t('Shp_G_Cmd5') },
  ];
});

function copy(text, label = t('Cr_Copied')) {
  if (!text) return;
  navigator.clipboard?.writeText(text)
    .then(() => message.success(label))
    // No clipboard (insecure origin, locked-down browser): show the text so it
    // can still be selected by hand rather than failing silently.
    .catch(() => message.info(text));
}
</script>

<style scoped>
.guide {
  max-width: 900px;
}
.lead {
  font-size: 13px;
  opacity: 0.7;
  margin-bottom: 14px;
}
.heading {
  margin: 22px 0 12px;
  font-size: 13px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  opacity: 0.55;
}
.ways {
  margin-bottom: 8px;
}
.ways-note {
  margin-bottom: 4px;
}
.way-tabs {
  margin-top: -4px;
}
.tab-tag {
  margin-inline-start: 6px;
  font-size: 10.5px;
  line-height: 16px;
}
.pane-lead {
  margin: 4px 0 16px;
  font-size: 13px;
  line-height: 1.6;
  opacity: 0.8;
}
.step {
  padding: 0 0 18px;
  margin-bottom: 18px;
  border-bottom: 1px solid rgba(128, 128, 128, 0.16);
}
.step:last-of-type {
  border-bottom: 0;
  margin-bottom: 0;
}
.step h4 {
  display: flex;
  align-items: center;
  gap: 9px;
  margin: 0 0 8px;
  font-size: 14px;
  font-weight: 600;
}
.num {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 22px;
  height: 22px;
  border-radius: 50%;
  background: rgba(109, 40, 217, 0.14);
  font-size: 12px;
  flex: none;
}
.step p {
  margin: 0 0 8px;
  font-size: 13px;
  line-height: 1.6;
}
.plain {
  margin: 0 0 10px;
  padding-inline-start: 18px;
  font-size: 13px;
  line-height: 1.75;
}
.note {
  font-size: 12px;
  opacity: 0.68;
  line-height: 1.6;
}
.note.foot {
  margin-top: 4px;
}
.step code,
.note code {
  padding: 2px 6px;
  border-radius: 5px;
  background: rgba(128, 128, 128, 0.14);
  font-size: 11.5px;
  word-break: break-all;
}
.cmd {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
  margin: 8px 0 10px;
  padding: 8px 10px;
  border: 1px solid rgba(128, 128, 128, 0.2);
  border-radius: 10px;
}
.cmd code {
  flex: 1 1 320px;
  background: none;
  padding: 0;
  font-size: 12px;
}
.cmd--row {
  align-items: flex-start;
}
.cmd-body {
  display: flex;
  flex-direction: column;
  gap: 3px;
  flex: 1 1 320px;
  min-width: 0;
}
.cmd-what {
  font-size: 11.5px;
  opacity: 0.6;
}
.scope-table {
  margin-top: 10px;
}
.scopes {
  font-size: 11px;
  background: none;
  padding: 0;
}
.link {
  color: #5f9e3f;
  cursor: pointer;
}
.callout {
  margin: 4px 0 12px;
}
.callout :deep(p) {
  margin: 0 0 6px;
  font-size: 12.5px;
  line-height: 1.6;
}
.callout :deep(p:last-child) {
  margin-bottom: 0;
}
.callout-state {
  margin-top: 8px;
}
</style>
