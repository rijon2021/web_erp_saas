<template>
  <div class="page">
    <PageHeader
      :title="$t('Bkm_Requests')"
      :subtitle="$t('Bkm_Requests_Subtitle')"
      :breadcrumb="[$t('Booking Management'), $t('Bkm_Requests')]"
    >
      <template #actions>
        <a-button :loading="loading" @click="load">
          <template #icon><ReloadOutlined /></template>
          {{ $t('Bkm_Refresh') }}
        </a-button>
      </template>
    </PageHeader>

    <a-alert v-if="!ready" type="warning" show-icon :message="$t('Bkm_Not_Ready')" />

    <template v-else>
      <div class="toolbar">
        <a-segmented v-model:value="tab" :options="tabOptions" @change="load" />
        <a-input-search v-model:value="search" allow-clear :placeholder="$t('Bkm_Search_Requests')" style="width: 260px" @search="load" />
      </div>

      <a-row :gutter="16">
        <a-col :xs="24" :lg="9">
          <a-card size="small" :body-style="{ padding: 0 }" class="block">
            <a-spin :spinning="loading">
              <a-empty v-if="!rows.length" class="empty" :description="$t('Bkm_No_Requests')" />
              <ul v-else class="req-list" role="listbox" :aria-label="$t('Bkm_Requests')">
                <li
                  v-for="r in rows" :key="r.id" role="option" tabindex="0" :aria-selected="r.id === selectedId"
                  class="req-row" :class="{ active: r.id === selectedId }"
                  @click="selectedId = r.id" @keydown.enter.prevent="selectedId = r.id" @keydown.space.prevent="selectedId = r.id"
                >
                  <span class="req-top">
                    <strong>{{ r.customer?.name || '—' }}</strong>
                    <span class="muted small">{{ ago(r.created_at) }}</span>
                  </span>
                  <span class="req-service">{{ r.product?.name || '—' }}</span>
                  <span class="req-meta">
                    <a-tag v-if="r.booking_mode === 'request'" color="cyan" class="tag">{{ $t('Bkm_Needs_A_Time') }}</a-tag>
                    <a-tag v-else color="purple" class="tag">{{ $t('Bkm_Picked_A_Time') }}</a-tag>
                    <a-tag v-if="r.hold_expired" color="orange" class="tag">{{ $t('Bkm_Hold_Ran_Out') }}</a-tag>
                    <a-tag v-if="r.deposit && r.deposit.status === 'due'" color="gold" class="tag">{{ $t('Bkm_Deposit_Not_Paid') }}</a-tag>
                    <a-tag v-else-if="r.deposit && r.deposit.status === 'paid'" color="green" class="tag">{{ $t('Bkm_Deposit_Is_Paid') }}</a-tag>
                    <span class="muted small">{{ whenText(r) }}</span>
                  </span>
                </li>
              </ul>
              <p v-if="limit && rows.length >= limit" class="muted small cut-note">{{ i18n.global.t('Bkm_Requests_Cut', { n: limit }) }}</p>
            </a-spin>
          </a-card>
        </a-col>
        <a-col :xs="24" :lg="15">
          <a-card size="small" class="block">
            <BookingPanel v-if="selectedId" :key="selectedId" :booking-id="selectedId" @changed="onChanged" />
            <a-empty v-else :description="$t('Bkm_Pick_A_Request')" />
          </a-card>
        </a-col>
      </a-row>
    </template>
  </div>
</template>

<script setup>
/**
 * Booking Management › Requests: pending bookings to confirm or decline.
 * GET bookings/requests?tab=website|request|all&search → {rows, counts};
 * the right-hand side is BookingPanel (details + actions).
 */
import { ref, computed, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import { message } from 'ant-design-vue';
import { ReloadOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';
import { t, i18n } from '../../i18n';
import BookingPanel from './BookingPanel.vue';
import { windowLabel } from './bookingStatus';

const route = useRoute();
const ready = ref(true);
const loading = ref(false);
const rows = ref([]);
const counts = ref({ website: 0, request: 0, all: 0 });
const limit = ref(0);
const tab = ref(['website', 'request', 'all'].includes(route.query.tab) ? route.query.tab : 'website');
const search = ref('');
const selectedId = ref(Number(route.query.id) || null);

const tabOptions = computed(() => [
  { value: 'website', label: `${t('Bkm_Tab_Website', 'From the website')} (${counts.value.website})` },
  { value: 'request', label: `${t('Bkm_Needs_A_Time', 'Needs a time')} (${counts.value.request})` },
  { value: 'all', label: `${t('Bkm_Tab_All_Pending', 'All pending')} (${counts.value.all})` },
]);

function locale() {
  const l = i18n.global.locale;
  return (typeof l === 'string' ? l : l?.value) || 'en';
}
function ago(iso) {
  if (!iso) return '';
  const mins = Math.round((Date.now() - new Date(iso).getTime()) / 60000);
  try {
    const rtf = new Intl.RelativeTimeFormat(locale(), { numeric: 'auto' });
    if (Math.abs(mins) < 60) return rtf.format(-mins, 'minute');
    if (Math.abs(mins) < 1440) return rtf.format(-Math.round(mins / 60), 'hour');
    return rtf.format(-Math.round(mins / 1440), 'day');
  } catch (e) {
    return '';
  }
}
function whenText(r) {
  if (r.booking_mode === 'request' && r.status === 'pending' && !r.confirmed_at) {
    return `${r.preferred_date || r.date} · ${windowLabel(r.preferred_window)}`;
  }
  return `${r.date} · ${r.time}${r.staff ? ' · ' + r.staff.name : ''}`;
}

async function load() {
  loading.value = true;
  try {
    const data = await http.get('bookings/requests', { tab: tab.value, search: search.value || undefined });
    ready.value = data?.ready !== false;
    rows.value = data?.rows || [];
    if (data?.counts) counts.value = data.counts;
    limit.value = data?.limit || 0;
    if (!rows.value.some((r) => r.id === selectedId.value)) selectedId.value = rows.value[0]?.id ?? null;
  } catch (e) {
    message.error(t('Bkm_Could_Not_Load_Requests', 'Could not load the requests.'));
  } finally {
    loading.value = false;
  }
}

/** An answered request leaves the inbox; load() then moves the panel to the next one. */
async function onChanged() {
  await load();
}

onMounted(load);
</script>

<style scoped>
.toolbar {
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  align-items: center;
  margin-bottom: 12px;
}
.block {
  margin-bottom: 16px;
}
.empty {
  padding: 32px 16px;
}
.muted { color: rgba(0, 0, 0, 0.55); }
.small { font-size: 12px; }
.req-list {
  list-style: none;
  margin: 0;
  padding: 0;
  max-height: 70vh;
  overflow-y: auto;
}
.req-row {
  display: flex;
  flex-direction: column;
  gap: 4px;
  padding: 12px 16px;
  border-bottom: 1px solid rgba(128, 128, 128, 0.14);
  cursor: pointer;
}
.req-row.active {
  background: rgba(var(--app-primary-rgb, 109, 40, 217), 0.07);
  box-shadow: inset 3px 0 0 var(--app-primary, #6d28d9);
}
.req-row:focus-visible {
  outline: 2px solid var(--app-primary, #6d28d9);
  outline-offset: -2px;
}
.req-top {
  display: flex;
  justify-content: space-between;
  gap: 8px;
}
.req-meta {
  display: flex;
  flex-wrap: wrap;
  gap: 4px;
  align-items: center;
}
.tag {
  margin: 0;
}
</style>
