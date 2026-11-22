<template>
  <div class="page">
    <PageHeader :title="$t('Oc_G_Title')" :breadcrumb="[$t('Int_Ecommerce_Platforms'), 'OpenCart', $t('Guide')]">
      <template #actions>
        <router-link to="/integrations/opencart"><a-button type="primary">{{ $t('Oc_G_Open_Settings') }}</a-button></router-link>
      </template>
    </PageHeader>

    <div v-if="loading" style="display: flex; justify-content: center; padding: 96px 0">
      <a-spin size="large" />
    </div>

    <a-row v-else :gutter="[16, 16]">
      <!-- ============================ Your setup ============================ -->
      <a-col :xs="24" :lg="10">
        <a-card size="small" :title="$t('Oc_G_Your_Setup')" class="fill">
          <p class="muted">{{ $t('Oc_G_Your_Setup_Help') }}</p>
          <ul class="checklist">
            <li v-for="step in steps" :key="step.key" :class="'is-' + step.state">
              <span class="dot">
                <CheckOutlined v-if="step.state === 'done'" />
                <ExclamationOutlined v-else-if="step.state === 'warn'" />
                <template v-else>{{ step.n }}</template>
              </span>
              <div>
                <div><strong>{{ step.title }}</strong></div>
                <div class="muted small">{{ step.help }}</div>
              </div>
            </li>
          </ul>
        </a-card>
      </a-col>

      <!-- ============================ Install ============================ -->
      <a-col :xs="24" :lg="14">
        <a-card size="small" :title="$t('Oc_G_Install_Title')" class="fill">
          <p>{{ $t('Oc_G_Install_Intro') }}</p>
          <a-tabs v-model:activeKey="version" size="small">
            <a-tab-pane key="4" :tab="$t('Oc_G_Opencart_4')">
              <ol class="steps">
                <li>
                  {{ $t('Oc_G_I4_1') }}
                  <a-button size="small" style="margin-left: 8px" :loading="downloading === 4" @click="download(4)">
                    <template #icon><DownloadOutlined /></template>{{ $t('Oc_Download_Connector') }}
                  </a-button>
                </li>
                <i18n-t keypath="Oc_G_I4_2" tag="li"><template #path><strong>Extensions → Installer</strong></template></i18n-t>
                <i18n-t keypath="Oc_G_I4_3" tag="li"><template #path><strong>Extensions → Extensions → Modules</strong></template><template #name><strong>{{ $t('Oc_Connector', { company }) }}</strong></template></i18n-t>
                <li>{{ $t('Oc_G_I_4') }}</li>
                <i18n-t keypath="Oc_G_I_5" tag="li"><template #test><strong>{{ $t('Test_Connection') }}</strong></template></i18n-t>
              </ol>
            </a-tab-pane>
            <a-tab-pane key="3" :tab="$t('Oc_G_Opencart_3')">
              <ol class="steps">
                <li>
                  {{ $t('Oc_G_I3_1') }}
                  <a-button size="small" style="margin-left: 8px" :loading="downloading === 3" @click="download(3)">
                    <template #icon><DownloadOutlined /></template>{{ $t('Oc_Download_Connector_3') }}
                  </a-button>
                </li>
                <i18n-t keypath="Oc_G_I3_2" tag="li"><template #path><strong>Extensions → Installer</strong></template></i18n-t>
                <i18n-t keypath="Oc_G_I3_3" tag="li"><template #path><strong>Extensions → Extensions → Modules</strong></template><template #name><strong>{{ $t('Oc_Connector', { company }) }}</strong></template></i18n-t>
                <li>{{ $t('Oc_G_I_4') }}</li>
                <i18n-t keypath="Oc_G_I_5" tag="li"><template #test><strong>{{ $t('Test_Connection') }}</strong></template></i18n-t>
              </ol>
            </a-tab-pane>
          </a-tabs>
          <a-alert type="info" show-icon :message="$t('Oc_G_Requirements')" :description="$t('Oc_G_Requirements_Desc')" />
        </a-card>
      </a-col>

      <!-- ============================ First sync ============================ -->
      <a-col :span="24">
        <a-card size="small" :title="$t('Oc_G_First_Title')">
          <a-row :gutter="[16, 16]">
            <a-col :xs="24" :md="8">
              <h4>{{ $t('Oc_G_First_Shop_Has') }}</h4>
              <p>{{ $t('Oc_G_First_Shop_Has_Text') }}</p>
            </a-col>
            <a-col :xs="24" :md="8">
              <h4>{{ $t('Oc_G_First_Company_Has', { company }) }}</h4>
              <p>{{ $t('Oc_G_First_Company_Has_Text') }}</p>
            </a-col>
            <a-col :xs="24" :md="8">
              <h4>{{ $t('Oc_G_First_Both') }}</h4>
              <p>{{ $t('Oc_G_First_Both_Text') }}</p>
            </a-col>
          </a-row>
          <a-alert type="warning" show-icon :message="$t('Oc_G_Stock_Master', { company })" :description="$t('Oc_G_Stock_Master_Desc', { company })" />
        </a-card>
      </a-col>

      <!-- ============================ How it works ============================ -->
      <a-col :span="24">
        <a-card size="small" :title="$t('Oc_G_How_Title')">
          <a-collapse v-model:active-key="openTopics" ghost>
            <a-collapse-panel key="products" :header="$t('Oc_G_T_Products')">
              <p>{{ $t('Oc_G_Products_1') }}</p>
              <p>{{ $t('Oc_G_Products_2', { company }) }}</p>
              <p>{{ $t('Oc_G_Products_3') }}</p>
            </a-collapse-panel>
            <a-collapse-panel key="variants" :header="$t('Oc_G_T_Variants')">
              <p>{{ $t('Oc_G_Variants_4') }}</p>
              <p>{{ $t('Oc_G_Variants_3') }}</p>
              <p>{{ $t('Oc_G_Variants_Multi') }}</p>
            </a-collapse-panel>
            <a-collapse-panel key="stock" :header="$t('Oc_G_T_Stock')">
              <p>{{ $t('Oc_G_Stock_1') }}</p>
              <p>{{ $t('Oc_G_Stock_2') }}</p>
              <p>{{ $t('Oc_G_Stock_3') }}</p>
            </a-collapse-panel>
            <a-collapse-panel key="orders" :header="$t('Oc_G_T_Orders')">
              <p>{{ $t('Oc_G_Orders_1') }}</p>
              <a-table :data-source="statusRows" :columns="statusColumns" row-key="key" size="small" :pagination="false" style="margin: 8px 0 12px" />
              <p>{{ $t('Oc_G_Orders_2') }}</p>
              <p>{{ $t('Oc_G_Orders_3') }}</p>
            </a-collapse-panel>
            <a-collapse-panel key="cancel" :header="$t('Oc_G_T_Cancel')">
              <p>{{ $t('Oc_G_Cancel_1') }}</p>
              <p>{{ $t('Oc_G_Cancel_2') }}</p>
              <p>{{ $t('Oc_G_Cancel_3') }}</p>
            </a-collapse-panel>
            <a-collapse-panel key="automation" :header="$t('Oc_G_T_Automation')">
              <p>{{ $t('Oc_G_Auto_1') }}</p>
              <p>{{ $t('Oc_G_Auto_2') }}</p>
              <p>{{ $t('Oc_G_Auto_3') }}</p>
            </a-collapse-panel>
            <a-collapse-panel key="faq" :header="$t('Oc_G_T_Faq')">
              <dl class="faq">
                <template v-for="q in FAQ" :key="q">
                  <dt>{{ $t('Oc_G_Q_' + q) }}</dt>
                  <dd>{{ $t('Oc_G_A_' + q, { company }) }}</dd>
                </template>
              </dl>
            </a-collapse-panel>
          </a-collapse>
        </a-card>
      </a-col>
    </a-row>
  </div>
</template>

<script setup>
/**
 * Integrations → OpenCart → Guide. A live setup checklist read from
 * GET opencart/settings and opencart/stats, the install steps for OpenCart 4
 * and 3 (with the connector downloads), and the module explained: what each
 * side owns, how stock and orders move, and what to do when something is off.
 */
import { ref, computed, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { CheckOutlined, ExclamationOutlined, DownloadOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';

const { t } = useI18n();

const FAQ = ['Secret', 'Clock', 'Redirect', 'Not_Answering', 'No_Orders', 'Stock_Wrong', 'Out_Of_Stock', 'Held', 'Deleted_Sale', 'Uninstall'];

const loading = ref(true);
const downloading = ref(0);
const version = ref('4');
const openTopics = ref(['products']);
const settings = ref({});
const company = computed(() => settings.value.company_name || '');
const stats = ref({});

const statusColumns = [
  { title: t('Oc_G_Col_List'), dataIndex: 'list', width: 180 },
  { title: t('Oc_G_Col_Meaning'), dataIndex: 'meaning' },
  { title: t('Oc_G_Col_Default'), dataIndex: 'defaults', width: 260 },
];
const statusRows = computed(() => [
  { key: 'commit', list: t('Oc_Commit_Statuses'), meaning: t('Oc_Commit_Statuses_Help'), defaults: t('Oc_G_Default_Commit') },
  { key: 'paid', list: t('Oc_Paid_Statuses'), meaning: t('Oc_Paid_Statuses_Help'), defaults: t('Oc_G_Default_Paid') },
  { key: 'cancel', list: t('Oc_Cancel_Statuses'), meaning: t('Oc_Cancel_Statuses_Help'), defaults: t('Oc_G_Default_Cancel') },
]);

const steps = computed(() => {
  const s = settings.value || {};
  const st = stats.value || {};
  const connected = !!s.connector_version;
  const list = [
    { key: 'install', state: connected ? 'done' : 'todo', title: t('Oc_G_Step_Install', { company: company.value }), help: t('Oc_G_Step_Install_Help', { company: company.value }) },
    { key: 'connect', state: connected ? 'done' : (s.configured ? 'warn' : 'todo'), title: t('Oc_G_Step_Connect'), help: connected ? t('Oc_G_Step_Connect_Done', { connector: s.connector_version, opencart: s.opencart_version }) : t('Oc_G_Step_Connect_Help') },
    { key: 'enable', state: s.enabled ? 'done' : (connected ? 'warn' : 'todo'), title: t('Oc_G_Step_Enable'), help: t('Oc_G_Step_Enable_Help') },
    { key: 'products', state: st.products_mapped > 0 ? 'done' : 'todo', title: t('Oc_G_Step_Products'), help: st.products_mapped > 0 ? t('Oc_G_Step_Products_Done', { n: st.products_mapped, v: st.variants_mapped || 0 }) : t('Oc_G_Step_Products_Help') },
    { key: 'orders', state: s.last_webhook_at ? 'done' : (st.orders_imported > 0 ? 'warn' : 'todo'), title: t('Oc_G_Step_Orders'), help: s.last_webhook_at ? t('Oc_G_Step_Orders_Done') : (st.orders_imported > 0 ? t('Oc_G_Step_Orders_No_Webhook') : t('Oc_G_Step_Orders_Help')) },
  ];
  return list.map((item, i) => ({ ...item, n: i + 1 }));
});

async function load() {
  loading.value = true;
  try {
    settings.value = await http.get('opencart/settings');
    if (settings.value.configured) {
      try { stats.value = await http.get('opencart/stats'); } catch (e) { /* tiles stay empty */ }
    }
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    loading.value = false;
  }
}

async function download(v) {
  downloading.value = v;
  try {
    await http.download('opencart/connector?version=' + v, 'pos_connector.ocmod.zip');
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    downloading.value = 0;
  }
}

onMounted(load);
</script>

<style scoped>
.muted {
  opacity: 0.65;
}
.small {
  font-size: 12px;
}
.fill {
  height: 100%;
}
.checklist {
  list-style: none;
  margin: 0;
  padding: 0;
}
.checklist li {
  display: flex;
  gap: 12px;
  padding: 10px 0;
  border-bottom: 1px solid rgba(128, 128, 128, 0.12);
}
.checklist li:last-child {
  border-bottom: 0;
}
.dot {
  flex: 0 0 26px;
  height: 26px;
  border-radius: 50%;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-size: 12px;
  font-weight: 600;
  background: rgba(128, 128, 128, 0.16);
}
.is-done .dot {
  background: rgba(82, 196, 26, 0.18);
  color: #389e0d;
}
.is-warn .dot {
  background: rgba(250, 173, 20, 0.2);
  color: #d48806;
}
.steps {
  padding-left: 20px;
  line-height: 2;
  margin: 0 0 12px;
}
.faq dt {
  font-weight: 600;
  margin-top: 10px;
}
.faq dd {
  margin: 4px 0 0;
  opacity: 0.8;
}
</style>
