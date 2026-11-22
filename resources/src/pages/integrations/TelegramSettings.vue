<template>
  <div class="page">
    <PageHeader title="Telegram" :breadcrumb="[$t('Integrations'), 'Telegram']">
      <template #actions>
        <a-tag v-if="!isLoading" :color="state.enabled && state.configured ? 'success' : 'default'" style="font-size: 13px; padding: 4px 12px">
          {{ state.enabled && state.configured ? $t('Enabled') : state.configured ? $t('Disabled') : $t('Int_Not_Configured') }}
        </a-tag>
      </template>
    </PageHeader>

    <div v-if="isLoading" style="display: flex; justify-content: center; padding: 96px 0">
      <a-spin size="large" />
    </div>

    <a-card v-else size="small">
      <div class="hero">
        <a-avatar shape="square" :size="48" :style="{ background: 'rgba(34,158,217,0.14)', color: '#229ed9' }">
          <template #icon><SendOutlined /></template>
        </a-avatar>
        <div>
          <div class="hero-title">{{ $t('Int_Notifications_Title', { name: 'Telegram' }) }}</div>
          <div class="muted">
            {{ $t('Tg_Hero_Desc') }}
          </div>
        </div>
      </div>

      <a-tabs v-model:activeKey="tab">
        <!-- ============================ Settings ============================ -->
        <a-tab-pane key="settings" :tab="$t('Settings')">
          <a-form layout="vertical" style="max-width: 640px">
            <a-form-item :label="$t('Tg_Bot_Token')">
              <a-input-password
                v-model:value="form.bot_token"
                :placeholder="state.configured ? $t('Tg_Saved_Paste_New_Token', { hint: state.bot_token_hint }) : '123456789:ABCdefGhIJKlmNoPQRsTUVwxyZ'"
                autocomplete="off"
              />
              <i18n-t keypath="Tg_Bot_Token_Help" tag="div" class="muted" style="margin-top: 4px">
                <template #botfather><strong>@BotFather</strong></template>
              </i18n-t>
            </a-form-item>

            <a-form-item :label="$t('Tg_Chat_ID')">
              <a-input v-model:value="form.chat_id" :placeholder="$t('Tg_Chat_ID_Placeholder')" />
              <i18n-t keypath="Tg_Chat_ID_Help" tag="div" class="muted" style="margin-top: 4px">
                <template #userinfobot><strong>@userinfobot</strong></template>
                <template #getUpdates><code>getUpdates</code></template>
              </i18n-t>
            </a-form-item>

            <a-form-item :label="$t('Events')">
              <a-radio-group v-model:value="eventMode">
                <a-radio-button value="all">{{ $t('Int_All_Events') }}</a-radio-button>
                <a-radio-button value="selected">{{ $t('Int_Selected_Events') }}</a-radio-button>
              </a-radio-group>
              <a-select
                v-if="eventMode === 'selected'"
                v-model:value="form.events"
                mode="multiple"
                style="width: 100%; margin-top: 8px"
                :placeholder="$t('Tg_Pick_Events')"
                :options="state.available_events.map(ev => ({ value: ev, label: ev }))"
              />
            </a-form-item>

            <a-form-item>
              <a-space align="center">
                <a-switch v-model:checked="form.enabled" />
                <span>{{ $t('Int_Enable_Notifications', { name: 'Telegram' }) }}</span>
              </a-space>
            </a-form-item>

            <a-space wrap>
              <a-button type="primary" :loading="saving" @click="save">{{ $t('Save') }}</a-button>
              <a-button :loading="testing" :disabled="!state.configured && !(form.bot_token && form.chat_id)" @click="sendTest">
                {{ $t('Int_Send_Test_Message') }}
              </a-button>
            </a-space>
          </a-form>
        </a-tab-pane>

        <!-- ============================ Guide ============================ -->
        <a-tab-pane key="guide" :tab="$t('Guide')">
          <TelegramGuide :state="state" @open-settings="tab = 'settings'" />
        </a-tab-pane>
      </a-tabs>
    </a-card>
  </div>
</template>

<script setup>
/**
 * Telegram integration settings: write-only bot token, destination chat id,
 * event subscription (empty list = all events, mirrored server-side), enable
 * switch, and a synchronous test send.
 */
import { ref, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { SendOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import TelegramGuide from './TelegramGuide.vue';
import http from '../../lib/http';

const { t } = useI18n();

const isLoading = ref(true);
const saving = ref(false);
const testing = ref(false);
const tab = ref('settings');
const state = ref({ enabled: false, configured: false, bot_token_hint: null, chat_id: '', available_events: [], queue: {}, cron: {} });
const form = ref({ enabled: false, bot_token: '', chat_id: '', events: [] });
const eventMode = ref('all');

async function load() {
  isLoading.value = true;
  try {
    state.value = await http.get('telegram/settings');
    form.value.enabled = !!state.value.enabled;
    form.value.chat_id = state.value.chat_id || '';
    form.value.events = state.value.events || [];
    form.value.bot_token = '';
    eventMode.value = form.value.events.length ? 'selected' : 'all';
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    isLoading.value = false;
  }
}

async function save() {
  saving.value = true;
  try {
    const payload = {
      enabled: !!form.value.enabled,
      chat_id: (form.value.chat_id || '').trim() || null,
      events: eventMode.value === 'all' ? [] : form.value.events,
    };
    // The token is write-only: send it only when the admin pasted a new one.
    if (form.value.bot_token) payload.bot_token = form.value.bot_token.trim();
    await http.put('telegram/settings', payload);
    message.success(t('Successfully_Updated'));
    await load();
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    saving.value = false;
  }
}

async function sendTest() {
  testing.value = true;
  try {
    // Persist freshly entered credentials first so the test uses them.
    if (form.value.bot_token || form.value.chat_id !== (state.value.chat_id || '')) await save();
    await http.post('telegram/settings/test');
    message.success(t('Tg_Test_Sent'));
  } catch (e) {
    message.error(e?.data?.error || e?.data?.message || t('Int_Test_Failed'));
  } finally {
    testing.value = false;
  }
}

onMounted(load);
</script>

<style scoped>
.hero {
  display: flex;
  align-items: center;
  gap: 14px;
  margin-bottom: 20px;
  padding-bottom: 16px;
  border-bottom: 1px solid rgba(128, 128, 128, 0.15);
}
.hero-title {
  font-weight: 600;
  font-size: 16px;
}
.muted {
  opacity: 0.65;
  font-size: 13px;
}
</style>
