<template>
  <div class="page">
    <PageHeader :title="$t('Crm_Inbox')" :subtitle="$t('Crm_Inbox_Subtitle')" :breadcrumb="[$t('Crm'), $t('Crm_Inbox')]">
      <template #actions>
        <a-segmented v-model:value="filter" :options="filterOptions" @change="load" />
        <a-button :disabled="!unreadIds.length" @click="markRead(unreadIds)"><template #icon><CheckCheck :size="14" class="btn-ic" /></template>{{ $t('Crm_Mark_All_Read') }}</a-button>
        <a-button @click="load"><template #icon><ReloadOutlined /></template></a-button>
      </template>
    </PageHeader>

    <!-- Client Portal → Customer Requests lands here with ?channel=portal. -->
    <a-tag v-if="channel" color="orange" closable style="margin-bottom: 12px" @close="clearChannel">
      {{ $t('Portal_Messages_Only') }}
    </a-tag>

    <a-spin :spinning="loading">
      <a-card :bordered="false" class="card">
        <div v-if="rows.length" class="list">
          <div v-for="m in rows" :key="m.id" class="row" :class="{ unread: m.unread }" @click="open(m)">
            <a-avatar :size="38" :style="{ background: avatarColor(nameOf(m)), fontSize: '13px' }">{{ initials(nameOf(m)) }}</a-avatar>
            <div class="r-body">
              <div class="r-top">
                <span class="r-name">{{ nameOf(m) }}</span>
                <span v-if="m.deal" class="r-deal"><BriefcaseBusiness :size="12" /> {{ m.deal.title }}</span>
                <span class="r-time"><a-tooltip :title="dateTime(m.created_at)">{{ ago(m.created_at) }}</a-tooltip></span>
              </div>
              <div class="r-text">
                <span class="wa" :class="m.channel"><component :is="m.channel === 'portal' ? PanelsTopLeft : MessageCircle" :size="13" /></span>
                <span class="snippet">{{ m.body }}</span>
              </div>
              <div class="r-sub">{{ m.to }}</div>
            </div>
            <span v-if="m.unread" class="dot" />
            <a-tooltip v-if="m.unread" :title="$t('Crm_Mark_Read')">
              <a-button type="text" size="small" class="tool" @click.stop="markRead([m.id])"><template #icon><Check :size="14" /></template></a-button>
            </a-tooltip>
          </div>
        </div>
        <a-empty v-else :image="simpleImage" :description="filter === 'unread' ? $t('Crm_Inbox_All_Caught_Up') : $t('Crm_Inbox_Empty')" />
      </a-card>
    </a-spin>
  </div>
</template>

<script setup>
/** WhatsApp replies from leads and customers (GET crm/messages/inbox). Opening one goes to its conversation. */
import { ref, computed, onMounted, watch } from 'vue';
import { useRouter, useRoute } from 'vue-router';
import { message, Empty } from 'ant-design-vue';
import { ReloadOutlined } from '@ant-design/icons-vue';
import { MessageCircle, BriefcaseBusiness, Check, CheckCheck, PanelsTopLeft } from 'lucide-vue-next';
import PageHeader from '../../components/PageHeader.vue';
import { useFormat } from '../../composables/useFormat';
import { usePendingWorkStore } from '../../stores/pendingWork';
import http from '../../lib/http';
import { t } from '../../i18n';
import { initials, avatarColor, ago } from './crmOptions';

const router = useRouter();
const pending = usePendingWorkStore();
const { dateTime } = useFormat();
const simpleImage = Empty.PRESENTED_IMAGE_SIMPLE;

const route = useRoute();
const channel = computed(() => (route.query.channel === 'portal' ? 'portal' : ''));
watch(channel, () => load());
function clearChannel() {
  const { channel: _c, ...rest } = route.query;
  router.replace({ path: route.path, query: rest });
}

const filter = ref('unread');
const rows = ref([]);
const unread = ref(0);
const loading = ref(false);
const filterOptions = computed(() => [
  { value: 'unread', label: `${t('Crm_Unread', 'Unread')}${unread.value ? ` (${unread.value})` : ''}` },
  { value: 'all', label: t('All', 'All') },
]);
const unreadIds = computed(() => rows.value.filter(m => m.unread).map(m => m.id));

function nameOf(m) {
  return m.client?.name || m.lead?.name || m.to;
}

async function load() {
  loading.value = true;
  try {
    const res = await http.get('crm/messages/inbox', { filter: filter.value, ...(channel.value ? { channel: channel.value } : {}) });
    rows.value = res.messages;
    unread.value = res.unread;
    pending.fetch(true);
  } catch (e) {
    message.error(e?.data?.message || t('Crm_Err_Could_Not_Load_The_Inbox', 'Could not load the inbox'));
  } finally {
    loading.value = false;
  }
}

async function markRead(ids) {
  if (!ids.length) return;
  try {
    await http.post('crm/messages/read', { ids });
    rows.value.forEach((m) => { if (ids.includes(m.id)) m.unread = false; });
    unread.value = Math.max(0, unread.value - ids.length);
    if (filter.value === 'unread') rows.value = rows.value.filter(m => m.unread);
    pending.fetch(true);
  } catch (e) {
    message.error(e?.data?.message || t('Gen_Could_Not_Update', 'Could not update'));
  }
}

// The conversation lives on the deal, else the lead, else the customer (opening it marks it read).
function open(m) {
  if (m.deal) router.push(`/crm/deals/${m.deal.id}`);
  else if (m.lead) router.push(`/crm/leads/${m.lead.id}`);
  else if (m.client) router.push(`/crm/customers/${m.client.id}`);
}

onMounted(load);
</script>

<style scoped>
.card { border-radius: 14px; }
.btn-ic { margin-inline-end: 6px; vertical-align: -2px; }
.list { display: flex; flex-direction: column; }
.row {
  display: flex; align-items: center; gap: 12px; padding: 12px 8px; cursor: pointer;
  border-bottom: 1px solid rgba(128, 128, 128, 0.12); border-radius: 10px; transition: background 0.15s;
}
.row:last-child { border-bottom: 0; }
.row:hover { background: rgba(128, 128, 128, 0.06); }
.row.unread { background: rgba(var(--app-primary-rgb, 109, 40, 217), 0.04); }
.row.unread .r-name, .row.unread .snippet { font-weight: 600; }
.r-body { flex: 1; min-width: 0; }
.r-top { display: flex; align-items: center; gap: 8px; }
.r-name { font-weight: 500; }
.r-deal { display: inline-flex; align-items: center; gap: 4px; font-size: 12px; opacity: 0.65; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.r-time { margin-inline-start: auto; font-size: 12px; opacity: 0.6; flex: none; }
.r-text { display: flex; align-items: center; gap: 6px; margin-top: 2px; }
.wa { color: #16a34a; display: inline-flex; flex: none; }
.wa.portal { color: #f97316; }
.snippet { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; opacity: 0.85; }
.r-sub { font-size: 12px; opacity: 0.5; margin-top: 1px; }
.dot { width: 9px; height: 9px; border-radius: 50%; background: var(--app-primary, #6d28d9); flex: none; }
.tool { opacity: 0.6; }
.row:hover .tool { opacity: 1; }
</style>
