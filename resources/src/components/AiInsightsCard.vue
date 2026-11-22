<template>
  <a-card v-if="visible" class="ai-insights" :body-style="{ padding: '16px 20px' }">
    <div class="head">
      <div class="title">
        <span class="badge"><BulbOutlined /></span>
        <div>
          <div class="label">{{ tf('Ai_Insights_Title', 'AI insights') }}</div>
          <div v-if="insight && insight.headline" class="headline">{{ insight.headline }}</div>
        </div>
      </div>
      <a-space>
        <a-tooltip v-if="canRefresh && insight" :title="tf('Ai_Refresh_Insights', 'Write new insights')">
          <a-button type="text" size="small" :loading="generating" @click="generate(true)">
            <template #icon><ReloadOutlined /></template>
          </a-button>
        </a-tooltip>
        <a-button size="small" @click="$router.push('/ai-assistant')">
          <template #icon><MessageOutlined /></template>
          {{ tf('Ai_Ask', 'Ask the assistant') }}
        </a-button>
      </a-space>
    </div>

    <a-skeleton v-if="generating && !insight" active :paragraph="{ rows: 3 }" :title="false" style="margin-top: 12px" />

    <a-alert v-else-if="error" type="warning" show-icon :message="error" style="margin-top: 12px">
      <template #action>
        <a-button size="small" @click="generate(false)">{{ tf('Ai_Retry', 'Retry') }}</a-button>
      </template>
    </a-alert>

    <a-row v-else-if="insight" :gutter="[12, 12]" class="items">
      <a-col v-for="(item, i) in insight.insights" :key="i" :xs="24" :md="12" :xl="8">
        <div class="item" :class="item.tone">
          <span class="tone-icon">
            <RiseOutlined v-if="item.tone === 'positive'" />
            <WarningOutlined v-else-if="item.tone === 'warning'" />
            <InfoCircleOutlined v-else />
          </span>
          <div>
            <div class="item-title">{{ item.title }}</div>
            <div class="item-detail">{{ item.detail }}</div>
          </div>
        </div>
      </a-col>
    </a-row>

    <div v-if="insight && insight.generated_at" class="foot">
      {{ tf('Ai_Generated_At', 'Written') }} {{ dateTime(insight.generated_at) }} · {{ tf('Ai_Insights_Note', 'AI-generated from your data — double-check before acting.') }}
    </div>
  </a-card>
</template>

<script setup>
/**
 * Dashboard card: today's AI insights for this user's visibility scope.
 * GET ai/insights → { enabled, insight|null }; when today's are missing, the
 * first viewer triggers POST ai/insights (one Claude call per scope per day).
 * Hidden entirely when the assistant isn't set up or the user lacks access.
 */
import { ref, computed, onMounted } from 'vue';
import {
  BulbOutlined, ReloadOutlined, MessageOutlined, RiseOutlined, WarningOutlined, InfoCircleOutlined,
} from '@ant-design/icons-vue';
import { useAuthStore } from '../stores/auth';
import { usePlanStore } from '../stores/plan';
import { useFormat } from '../composables/useFormat';
import { t as tf } from '../i18n';
import http from '../lib/http';

const auth = useAuthStore();
const plan = usePlanStore();
const { dateTime } = useFormat();

const enabled = ref(false);
const insight = ref(null);
const generating = ref(false);
const error = ref('');

const visible = computed(() => enabled.value && auth.can('ai_assistant'));
// Regenerating costs a Claude call — keep it to people who manage the AI settings.
const canRefresh = computed(() => auth.can('ai_settings'));

async function load() {
  // The ai/* routes are behind the `ai_assistant` plan feature.
  if (!plan.featureEnabled('ai_assistant') || !auth.can('ai_assistant')) return;
  try {
    const data = await http.get('ai/insights');
    enabled.value = !!data?.enabled;
    insight.value = data?.insight || null;
    // A recent failure is shown (with Retry) instead of auto-retrying a paid call.
    if (data?.error) error.value = data.error;
    else if (enabled.value && !insight.value) generate(false);
  } catch (e) {
    enabled.value = false;
  }
}

async function generate(refresh) {
  generating.value = true;
  error.value = '';
  try {
    const data = await http.post('ai/insights', { refresh: refresh ? 1 : 0 });
    insight.value = data?.insight || null;
  } catch (e) {
    error.value = e?.data?.message || tf('Ai_Request_Failed', 'Something went wrong. Please try again.');
  } finally {
    generating.value = false;
  }
}

onMounted(load);
</script>

<style scoped>
.ai-insights {
  margin-top: 16px;
  border-radius: 12px;
  border-inline-start: 4px solid var(--app-primary);
}
.head {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 12px;
  flex-wrap: wrap;
}
.title { display: flex; gap: 12px; align-items: flex-start; min-width: 0; }
.badge {
  flex: none;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 36px;
  height: 36px;
  border-radius: 10px;
  font-size: 18px;
  color: var(--app-primary);
  background: rgba(var(--app-primary-rgb), 0.12);
}
.label { font-size: 12px; text-transform: uppercase; letter-spacing: 0.04em; opacity: 0.65; font-weight: 600; }
.headline { font-size: 15px; font-weight: 600; margin-top: 2px; }
.items { margin-top: 14px; }
.item {
  display: flex;
  gap: 10px;
  height: 100%;
  padding: 10px 12px;
  border-radius: 10px;
  background: rgba(128, 128, 128, 0.07);
}
.tone-icon { flex: none; font-size: 16px; margin-top: 2px; }
.item.positive .tone-icon { color: #10b981; }
.item.warning .tone-icon { color: #f59e0b; }
.item.neutral .tone-icon { color: var(--app-primary); }
.item-title { font-weight: 600; font-size: 13.5px; }
.item-detail { font-size: 13px; opacity: 0.8; margin-top: 2px; }
.foot { margin-top: 12px; font-size: 11.5px; opacity: 0.55; }
</style>
