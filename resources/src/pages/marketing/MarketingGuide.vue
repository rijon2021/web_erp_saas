<template>
  <div class="page">
    <PageHeader :title="$t('Mkg_Guide')" :subtitle="$t('Mkg_Guide_Subtitle')" :breadcrumb="[$t('Marketing'), $t('Mkg_Guide')]">
      <template #actions>
        <a-space wrap>
          <a-button v-if="can('marketing_campaigns')" type="primary" @click="$router.push('/marketing/campaigns/create')">{{ $t('Create_Campaign') }}</a-button>
          <a-button v-if="can('marketing_segments')" @click="$router.push('/marketing/segments')">{{ $t('Customer_Segments') }}</a-button>
          <a-button v-if="can('marketing_settings')" @click="$router.push('/marketing/settings')">{{ $t('Marketing_Settings') }}</a-button>
        </a-space>
      </template>
    </PageHeader>

    <a-spin :spinning="loading">
      <a-alert v-if="data && data.counts.stuck" type="warning" show-icon style="margin-bottom: 16px"
        :message="$t('Mkg_Guide_Stuck_Alert', { n: data.counts.stuck })" :description="$t('Mkg_Guide_Stuck_Alert_Help')" />

      <a-row :gutter="[16, 16]">
        <!-- Live checklist -->
        <a-col :xs="24" :xl="10">
          <a-card size="small" :title="$t('Mkg_Guide_Your_Setup')" class="fill">
            <p class="muted">{{ $t('Mkg_Guide_Your_Setup_Help') }}</p>
            <a-progress v-if="steps.length" :percent="donePct" :format="() => t('Mkg_Guide_Done_N', { done: doneCount, total: requiredCount })" size="small" style="margin-bottom: 12px" />
            <ol class="checklist">
              <li v-for="step in steps" :key="step.key" :class="'is-' + step.state">
                <span class="dot" aria-hidden="true">
                  <CheckOutlined v-if="step.state === 'done'" />
                  <ExclamationOutlined v-else-if="step.state === 'warn'" />
                  <span v-else>{{ step.n }}</span>
                </span>
                <div class="step-body">
                  <div class="step-title">
                    {{ step.title }}
                    <a-tag v-if="step.optional" class="opt">{{ $t('Mkg_Guide_Optional') }}</a-tag>
                    <span class="sr-state">{{ stateLabel(step.state) }}</span>
                  </div>
                  <div class="muted step-help">{{ step.help }}</div>
                  <a v-if="step.link" class="step-link" href="#" @click.prevent="$router.push(step.link)">{{ step.linkText }} →</a>
                </div>
              </li>
            </ol>
          </a-card>
        </a-col>

        <!-- How it works -->
        <a-col :xs="24" :xl="14">
          <a-card size="small" :title="$t('Mkg_Guide_How')" class="fill">
            <a-collapse v-model:activeKey="open" ghost>
              <a-collapse-panel key="flow" :header="$t('Mkg_Guide_Flow')">
                <ol class="flow">
                  <li v-for="n in 7" :key="n"><strong>{{ $t('Mkg_Guide_Flow_' + n + '_T') }}</strong> — {{ $t('Mkg_Guide_Flow_' + n) }}</li>
                </ol>
              </a-collapse-panel>

              <a-collapse-panel key="audience" :header="$t('Mkg_Guide_Audience')">
                <ul class="bul">
                  <li v-for="n in 5" :key="n">{{ $t('Mkg_Guide_Audience_' + n) }}</li>
                  <li v-if="data?.crm_leads">{{ $t('Mkg_Guide_Audience_Leads') }}</li>
                </ul>
              </a-collapse-panel>

              <a-collapse-panel key="channels" :header="$t('Mkg_Guide_Channels')">
                <template v-for="ch in CHANNEL_SECTIONS" :key="ch.key">
                  <h4 class="sub">{{ channelLabel(ch.key) }}</h4>
                  <ul class="bul"><li v-for="n in ch.n" :key="n">{{ $t('Mkg_Guide_Ch_' + ch.key + '_' + n) }}</li></ul>
                </template>
              </a-collapse-panel>

              <a-collapse-panel key="variables" :header="$t('Mkg_Guide_Variables')">
                <p class="muted">{{ $t('Mkg_Guide_Variables_Help') }}</p>
                <table class="grid">
                  <tbody>
                    <tr v-for="v in VARIABLES" :key="v.code">
                      <td><code>{{ v.code }}</code></td>
                      <td>{{ $t('Mkg_Guide_Var_' + v.key) }}</td>
                    </tr>
                  </tbody>
                </table>
              </a-collapse-panel>

              <a-collapse-panel key="schedule" :header="$t('Mkg_Guide_Schedule')">
                <ul class="bul"><li v-for="n in 4" :key="n">{{ $t('Mkg_Guide_Schedule_' + n) }}</li></ul>
              </a-collapse-panel>

              <a-collapse-panel key="statuses" :header="$t('Mkg_Guide_Statuses')">
                <h4 class="sub">{{ $t('Mkg_Guide_Campaign_Statuses') }}</h4>
                <table class="grid">
                  <tbody>
                    <tr v-for="s in CAMPAIGN_STATUSES" :key="s">
                      <td><a-tag :color="campaignStatusColor(s)">{{ plainLabel(s) }}</a-tag></td>
                      <td>{{ $t('Mkg_Guide_Status_' + s) }}</td>
                    </tr>
                  </tbody>
                </table>
                <h4 class="sub">{{ $t('Mkg_Guide_Recipient_Statuses') }}</h4>
                <table class="grid">
                  <tbody>
                    <tr v-for="s in RECIPIENT_STATUSES" :key="s">
                      <td><a-tag :color="recipientStatusColor(s)">{{ plainLabel(s) }}</a-tag></td>
                      <td>{{ $t('Mkg_Guide_Rstatus_' + s) }}</td>
                    </tr>
                  </tbody>
                </table>
              </a-collapse-panel>

              <a-collapse-panel key="roles" :header="$t('Mkg_Guide_Roles')">
                <p class="muted">{{ $t('Mkg_Guide_Roles_Help') }}</p>
                <table class="grid">
                  <thead><tr><th>{{ $t('Mkg_Guide_Permission') }}</th><th>{{ $t('Mkg_Guide_Allows') }}</th></tr></thead>
                  <tbody>
                    <tr v-for="p in PERMISSIONS" :key="p.slug">
                      <td><a-tag>{{ $t(p.label) }}</a-tag></td>
                      <td>{{ $t('Mkg_Guide_Perm_' + p.slug) }}</td>
                    </tr>
                  </tbody>
                </table>
              </a-collapse-panel>

              <a-collapse-panel key="faq" :header="$t('Mkg_Guide_Faq')">
                <dl class="faq">
                  <template v-for="n in 8" :key="n">
                    <dt>{{ $t('Mkg_Guide_Faq_' + n + '_Q') }}</dt>
                    <dd>{{ $t('Mkg_Guide_Faq_' + n + '_A') }}</dd>
                  </template>
                </dl>
              </a-collapse-panel>
            </a-collapse>
          </a-card>
        </a-col>
      </a-row>
    </a-spin>
  </div>
</template>

<script setup>
/**
 * Marketing › Guide — GET marketing/guide (any marketing permission): a live
 * set-up checklist built from the tenant's own data (channels, customers,
 * delivery health), plus how campaigns work, step by step.
 */
import { ref, computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { CheckOutlined, ExclamationOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import {
  CHANNELS, CAMPAIGN_STATUSES, channelLabel, plainLabel,
  campaignStatusColor, recipientStatusColor,
} from './marketingVocab';
import http from '../../lib/http';

const { t } = useI18n();
const loading = ref(true);
const data = ref(null);
const open = ref(['flow']);

const RECIPIENT_STATUSES = ['pending', 'sent', 'failed', 'skipped'];
const CHANNEL_SECTIONS = [
  { key: 'email', n: 4 },
  { key: 'sms', n: 3 },
  { key: 'whatsapp', n: 4 },
];
const VARIABLES = [
  { code: '{customer_name}', key: 'Name' },
  { code: '{first_name}', key: 'First_Name' },
  { code: '{company}', key: 'Company' },
  { code: '{phone}', key: 'Phone' },
  { code: '{email}', key: 'Email' },
  { code: '{last_purchase}', key: 'Last_Purchase' },
  { code: '{total_spent}', key: 'Total_Spent' },
  { code: '{unsubscribe_url}', key: 'Unsubscribe' },
];
const PERMISSIONS = [
  { slug: 'marketing_dashboard', label: 'Marketing_Dashboard' },
  { slug: 'marketing_campaigns', label: 'Campaigns' },
  { slug: 'marketing_segments', label: 'Customer_Segments' },
  { slug: 'marketing_templates', label: 'Marketing_Templates' },
  { slug: 'marketing_reports', label: 'Marketing_Reports' },
  { slug: 'marketing_settings', label: 'Marketing_Settings' },
];

function can(p) { return !!data.value?.can?.[p]; }
function stateLabel(s) { return s === 'done' ? t('Mkg_Guide_State_Done') : s === 'warn' ? t('Mkg_Guide_State_Warn') : t('Mkg_Guide_State_Todo'); }
/** A link only for people who can open the page behind it. */
function linkIf(perm, link, linkText) { return can(perm) ? { link, linkText } : {}; }

const steps = computed(() => {
  const d = data.value;
  if (!d) return [];
  const c = d.counts;
  const ch = d.channels;
  const reachable = c.with_email + c.with_phone > 0;
  const enabled = CHANNELS.filter((k) => ch[k].enabled);
  const settingsLink = linkIf('marketing_settings', '/marketing/settings', t('Marketing_Settings'));

  const list = [
    {
      key: 'customers',
      state: reachable ? 'done' : (c.customers ? 'warn' : 'todo'),
      title: t('Mkg_Guide_Step_Customers'),
      help: c.customers ? t('Mkg_Guide_Step_Customers_Ok', { n: c.customers, email: c.with_email, phone: c.with_phone }) : t('Mkg_Guide_Step_Customers_Help'),
      link: '/customers', linkText: t('Customers'),
    },
    {
      key: 'channel',
      state: enabled.length ? 'done' : 'todo',
      title: t('Mkg_Guide_Step_Channel'),
      help: enabled.length ? t('Mkg_Guide_Step_Channel_Ok', { list: enabled.map(channelLabel).join(', ') }) : t('Mkg_Guide_Step_Channel_Help'),
      ...settingsLink,
    },
  ];
  if (ch.email.enabled) {
    list.push({
      key: 'mail', state: ch.email.ready ? 'done' : 'warn', title: t('Mkg_Guide_Step_Mail'),
      help: ch.email.ready ? t('Mkg_Guide_Step_Mail_Ok') : t('Mkg_Guide_Step_Mail_Help'),
      link: '/settings/mail', linkText: t('mail_settings'),
    });
  }
  if (ch.sms.enabled) {
    list.push({
      key: 'sms', state: ch.sms.ready ? 'done' : 'warn', title: t('Mkg_Guide_Step_Sms'),
      help: ch.sms.ready ? t('Mkg_Guide_Step_Sms_Ok') : t('Mkg_Guide_Step_Sms_Help'), ...settingsLink,
    });
  }
  if (ch.whatsapp.enabled) {
    list.push({
      key: 'whatsapp', state: ch.whatsapp.ready ? 'done' : 'warn', title: t('Mkg_Guide_Step_Whatsapp'),
      help: ch.whatsapp.ready ? t('Mkg_Guide_Step_Whatsapp_Ok') : t('Mkg_Guide_Step_Whatsapp_Help'), ...settingsLink,
    });
  }
  list.push(
    {
      key: 'segments', optional: true, state: c.segments ? 'done' : 'todo', title: t('Mkg_Guide_Step_Segments'),
      help: c.segments ? t('Mkg_Guide_Step_Segments_Ok', { n: c.segments }) : t('Mkg_Guide_Step_Segments_Help'),
      ...linkIf('marketing_segments', '/marketing/segments', t('Customer_Segments')),
    },
    {
      key: 'templates', optional: true, state: c.templates ? 'done' : 'todo', title: t('Mkg_Guide_Step_Templates'),
      help: c.templates ? t('Mkg_Guide_Step_Templates_Ok', { n: c.templates }) : t('Mkg_Guide_Step_Templates_Help'),
      ...linkIf('marketing_templates', '/marketing/templates/email', t('Marketing_Templates')),
    },
    {
      key: 'schedule', optional: true, state: d.scheduling ? 'done' : 'todo', title: t('Mkg_Guide_Step_Schedule'),
      help: d.scheduling ? t('Mkg_Guide_Step_Schedule_Ok') : t('Mkg_Guide_Step_Schedule_Help'), ...settingsLink,
    },
    {
      key: 'first', state: c.sent ? 'done' : 'todo', title: t('Mkg_Guide_Step_First'),
      help: c.sent ? t('Mkg_Guide_Step_First_Ok', { n: c.sent }) : t('Mkg_Guide_Step_First_Help'),
      ...linkIf('marketing_campaigns', c.sent ? '/marketing/campaigns' : '/marketing/campaigns/create', c.sent ? t('Campaigns') : t('Create_Campaign')),
    },
  );
  if (c.campaigns) {
    const problems = c.stuck || c.failed_30d;
    list.push({
      key: 'health', state: problems ? 'warn' : 'done', title: t('Mkg_Guide_Step_Health'),
      help: c.stuck ? t('Mkg_Guide_Step_Health_Stuck', { n: c.stuck })
        : (c.failed_30d ? t('Mkg_Guide_Step_Health_Failed', { n: c.failed_30d, skipped: c.skipped_30d })
          : (c.skipped_30d ? t('Mkg_Guide_Step_Health_Skipped', { n: c.skipped_30d }) : t('Mkg_Guide_Step_Health_Ok'))),
      ...linkIf('marketing_campaigns', '/marketing/campaigns', t('Campaigns')),
    });
  }
  let n = 0;
  return list.map((st) => ({ ...st, n: ++n }));
});
const requiredCount = computed(() => steps.value.filter((s) => !s.optional).length);
const doneCount = computed(() => steps.value.filter((s) => !s.optional && s.state === 'done').length);
const donePct = computed(() => (requiredCount.value ? Math.round((doneCount.value / requiredCount.value) * 100) : 0));

onMounted(async () => {
  try { data.value = await http.get('marketing/guide'); } catch (e) { data.value = null; } finally { loading.value = false; }
});
</script>

<style scoped>
.muted { color: rgba(0, 0, 0, 0.55); }
.fill { height: 100%; }
.checklist { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 14px; }
.checklist li { display: flex; gap: 12px; align-items: flex-start; }
.dot { flex: none; width: 28px; height: 28px; border-radius: 999px; display: inline-flex; align-items: center; justify-content: center; font-weight: 700; font-size: 13px; background: #f1f1f6; color: #54546a; }
.is-done .dot { background: #dcfce7; color: #166534; }
.is-warn .dot { background: #fef3c7; color: #92400e; }
.step-body { flex: 1; min-width: 0; }
.step-title { font-weight: 600; display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
.step-help { font-size: 13px; margin-top: 2px; }
.step-link { font-size: 13px; font-weight: 600; display: inline-block; margin-top: 4px; }
.opt { font-weight: 500; }
.sr-state { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }
.flow { margin: 0; padding-inline-start: 20px; display: flex; flex-direction: column; gap: 8px; }
.bul { margin: 0; padding-inline-start: 20px; display: flex; flex-direction: column; gap: 6px; }
.sub { font-size: 13px; font-weight: 600; margin: 14px 0 6px; }
.sub:first-child { margin-top: 0; }
.grid { width: 100%; border-collapse: collapse; font-size: 14px; }
.grid th { text-align: start; font-size: 12px; color: rgba(0, 0, 0, 0.55); font-weight: 600; padding: 6px 4px; }
.grid td { padding: 8px 4px; border-top: 1px solid rgba(5, 5, 5, 0.06); vertical-align: top; }
.grid td:first-child { white-space: nowrap; padding-inline-end: 12px; }
.faq { margin: 0; }
.faq dt { font-weight: 600; margin-top: 10px; }
.faq dd { margin: 2px 0 0; color: rgba(0, 0, 0, 0.7); }
:global(html.dark) .page .muted, :global(html.dark) .page .grid th { color: rgba(255, 255, 255, 0.6); }
:global(html.dark) .page .faq dd { color: rgba(255, 255, 255, 0.75); }
:global(html.dark) .page .dot { background: rgba(255, 255, 255, 0.1); color: rgba(255, 255, 255, 0.75); }
:global(html.dark) .page .grid td { border-color: rgba(255, 255, 255, 0.08); }
</style>
