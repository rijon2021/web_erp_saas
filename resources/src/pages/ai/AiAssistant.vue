<template>
  <div class="page">
    <PageHeader :title="$t('AI_Assistant')" :breadcrumb="[$t('AI_Assistant')]">
      <template #actions>
        <a-space wrap>
          <a-button v-if="status.ready" class="history-toggle" @click="historyOpen = true">
            <template #icon><HistoryOutlined /></template>
            {{ tf('Ai_History', 'History') }}
          </a-button>
          <a-button v-if="status.can_configure" @click="openSettings">
            <template #icon><SettingOutlined /></template>
            {{ $t('Settings') }}
          </a-button>
        </a-space>
      </template>
    </PageHeader>

    <div v-if="loadingStatus" class="center-pad"><a-spin size="large" /></div>

    <!-- Not set up yet -->
    <a-card v-else-if="!status.ready" class="setup-card">
      <a-result :title="tf('Ai_Setup_Title', 'Set up the AI assistant')">
        <template #icon><RobotOutlined class="setup-icon" /></template>
        <template #subTitle>
          <span v-if="status.can_configure">{{ tf('Ai_Setup_Admin', 'Add an API key from Anthropic (Claude), OpenAI (ChatGPT) or Google (Gemini) and turn the assistant on. Your key is stored encrypted and only used from this server.') }}</span>
          <span v-else>{{ tf('Ai_Setup_User', 'An administrator needs to set up the AI assistant before it can be used.') }}</span>
        </template>
        <template #extra>
          <a-button v-if="status.can_configure" type="primary" @click="openSettings">
            <template #icon><SettingOutlined /></template>
            {{ tf('Ai_Open_Settings', 'Open settings') }}
          </a-button>
        </template>
      </a-result>
    </a-card>

    <!-- Chat -->
    <div v-else class="ai-shell">
      <aside class="ai-history">
        <ConversationList
          :conversations="conversations"
          :active-id="activeId"
          @new="newChat"
          @open="openConversation"
          @remove="removeConversation"
        />
      </aside>

      <section class="ai-chat">
        <div ref="threadEl" class="ai-thread">
          <!-- Empty state with starter questions -->
          <div v-if="!messages.length && !pending" class="ai-empty">
            <span class="ai-empty-icon"><RobotOutlined /></span>
            <h3>{{ tf('Ai_Empty_Title', 'Ask about your business') }}</h3>
            <p>{{ tf('Ai_Empty_Hint', 'Sales, stock, customers, purchases and expenses — answered from your live data, limited to what you can see.') }}</p>
            <div class="ai-suggestions">
              <a-button v-for="s in suggestions" :key="s" class="ai-suggestion" @click="send(s)">{{ s }}</a-button>
            </div>
          </div>

          <div v-if="loadingConversation" class="center-pad"><a-spin /></div>

          <template v-for="m in messages" :key="m.id">
            <div class="msg msg-user"><div class="bubble">{{ m.question }}</div></div>
            <div class="msg msg-ai">
              <span class="avatar"><RobotOutlined /></span>
              <div class="bubble">
                <a-alert v-if="m.error" type="error" show-icon :message="m.error">
                  <template #action>
                    <a-button size="small" @click="send(m.question)">{{ tf('Ai_Retry', 'Retry') }}</a-button>
                  </template>
                </a-alert>
                <div v-else class="md" v-html="renderMarkdown(m.answer)"></div>
                <div v-if="m.tools_used && m.tools_used.length" class="tools-used">
                  <DatabaseOutlined />
                  {{ tf('Ai_Looked_At', 'Looked at') }}:
                  <a-tag v-for="(name, i) in toolNames(m.tools_used)" :key="i" class="tool-tag">{{ name }}</a-tag>
                </div>
              </div>
            </div>
          </template>

          <!-- In flight -->
          <template v-if="pending">
            <div class="msg msg-user"><div class="bubble">{{ pending }}</div></div>
            <div class="msg msg-ai">
              <span class="avatar"><RobotOutlined /></span>
              <div class="bubble typing">
                <span class="dot"></span><span class="dot"></span><span class="dot"></span>
                <span class="typing-label">{{ tf('Ai_Thinking', 'Analysing your data…') }}</span>
              </div>
            </div>
          </template>
        </div>

        <div class="ai-composer">
          <a-textarea
            ref="inputEl"
            v-model:value="draft"
            :placeholder="tf('Ai_Placeholder', 'Ask a question, e.g. “What were my top products last month?”')"
            :auto-size="{ minRows: 1, maxRows: 6 }"
            :disabled="!!pending"
            :maxlength="4000"
            @keydown="onKeydown"
          />
          <a-button type="primary" :loading="!!pending" :disabled="!draft.trim()" @click="send()">
            <template #icon><SendOutlined /></template>
          </a-button>
        </div>
        <div class="ai-disclaimer">{{ tf('Ai_Disclaimer', 'AI answers can contain mistakes — double-check important figures in the reports.') }}</div>
      </section>
    </div>

    <!-- History on narrow screens -->
    <a-drawer v-model:open="historyOpen" :title="tf('Ai_History', 'History')" placement="left" :width="300">
      <ConversationList
        :conversations="conversations"
        :active-id="activeId"
        @new="newChat(); historyOpen = false"
        @open="id => { openConversation(id); historyOpen = false }"
        @remove="removeConversation"
      />
    </a-drawer>

    <AiSettingsDrawer v-model:open="settingsOpen" @saved="loadStatus" />
  </div>
</template>

<script setup>
/**
 * GET ai/status → { ready, can_configure, … }
 * GET ai/conversations → { conversations: [{id, title, updated_at}] }
 * GET ai/conversations/{id} → { conversation, messages: [{id, question, answer, tools_used, error}] }
 * POST ai/ask { conversation_id?, question } → { conversation, message }
 * DELETE ai/conversations/{id}
 * Answers are Markdown, rendered by lib/miniMarkdown (escaped + DOMPurify).
 */
import { ref, reactive, nextTick, onMounted, createVNode } from 'vue';
import { useI18n } from 'vue-i18n';
import { message, Modal } from 'ant-design-vue';
import {
  RobotOutlined, SendOutlined, SettingOutlined, HistoryOutlined, DatabaseOutlined, ExclamationCircleOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import ConversationList from './AiConversationList.vue';
import AiSettingsDrawer from './AiSettingsDrawer.vue';
import { renderMarkdown } from '../../lib/miniMarkdown';
import { t as tf } from '../../i18n';
import http from '../../lib/http';

const { t } = useI18n();

const status = reactive({ ready: false, can_configure: false });
const loadingStatus = ref(true);
const conversations = ref([]);
const activeId = ref(null);
const messages = ref([]);
const loadingConversation = ref(false);
const pending = ref('');
const draft = ref('');
const historyOpen = ref(false);
const settingsOpen = ref(false);
const threadEl = ref(null);
const inputEl = ref(null);

const suggestions = [
  tf('Ai_Suggest_1', 'How are sales this week compared to last week?'),
  tf('Ai_Suggest_2', 'What were my 10 best-selling products last month?'),
  tf('Ai_Suggest_3', 'Which products are low on stock?'),
  tf('Ai_Suggest_4', 'Which customers owe me the most?'),
  tf('Ai_Suggest_5', 'Break down this month’s expenses by category'),
];

const TOOL_LABELS = {
  list_warehouses: ['Ai_Tool_Warehouses', 'Warehouses'],
  business_overview: ['Ai_Tool_Overview', 'Business overview'],
  sales_breakdown: ['Ai_Tool_Sales', 'Sales breakdown'],
  top_products: ['Ai_Tool_Top_Products', 'Top products'],
  customer_balances: ['Ai_Tool_Balances', 'Customer balances'],
  top_customers: ['Ai_Tool_Top_Customers', 'Top customers'],
  stock_levels: ['Ai_Tool_Stock', 'Stock levels'],
  purchases_summary: ['Ai_Tool_Purchases', 'Purchases'],
  expenses_summary: ['Ai_Tool_Expenses', 'Expenses'],
  find_records: ['Ai_Tool_Search', 'Search'],
  sale_details: ['Ai_Tool_Sale', 'Sale details'],
};
function toolNames(used) {
  const names = [...new Set(used.map(u => u.name))];
  return names.map(n => (TOOL_LABELS[n] ? tf(...TOOL_LABELS[n]) : n));
}

async function loadStatus() {
  loadingStatus.value = true;
  try {
    Object.assign(status, await http.get('ai/status'));
    if (status.ready) await loadConversations();
  } catch (e) {
    status.ready = false;
  } finally {
    loadingStatus.value = false;
  }
}

async function loadConversations() {
  try {
    const data = await http.get('ai/conversations');
    conversations.value = data?.conversations || [];
  } catch (e) {
    conversations.value = [];
  }
}

async function openConversation(id) {
  if (pending.value) return;
  activeId.value = id;
  messages.value = [];
  loadingConversation.value = true;
  try {
    const data = await http.get(`ai/conversations/${id}`);
    messages.value = data?.messages || [];
    scrollToEnd();
  } catch (e) {
    message.error(t('InvalidData'));
  } finally {
    loadingConversation.value = false;
  }
}

function newChat() {
  if (pending.value) return;
  activeId.value = null;
  messages.value = [];
  draft.value = '';
  nextTick(() => inputEl.value?.focus?.());
}

function removeConversation(id) {
  Modal.confirm({
    title: tf('Ai_Delete_Conversation', 'Delete this conversation?'),
    icon: createVNode(ExclamationCircleOutlined),
    okType: 'danger',
    okText: t('Delete'),
    cancelText: t('Cancel'),
    async onOk() {
      await http.delete(`ai/conversations/${id}`);
      conversations.value = conversations.value.filter(c => c.id !== id);
      if (activeId.value === id) newChat();
    },
  });
}

async function send(text) {
  const question = String(text ?? draft.value).trim();
  if (!question || pending.value) return;
  pending.value = question;
  draft.value = '';
  scrollToEnd();
  try {
    const data = await http.post('ai/ask', { conversation_id: activeId.value, question });
    messages.value.push(data.message);
    if (activeId.value !== data.conversation.id) {
      activeId.value = data.conversation.id;
    }
    // Move the conversation to the top of the history.
    conversations.value = [data.conversation, ...conversations.value.filter(c => c.id !== data.conversation.id)];
  } catch (e) {
    messages.value.push({
      id: `err-${Date.now()}`,
      question,
      answer: '',
      error: e?.data?.message || tf('Ai_Request_Failed', 'Something went wrong. Please try again.'),
    });
  } finally {
    pending.value = '';
    scrollToEnd();
    nextTick(() => inputEl.value?.focus?.());
  }
}

function onKeydown(e) {
  if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) {
    e.preventDefault();
    send();
  }
}

function scrollToEnd() {
  nextTick(() => {
    const el = threadEl.value;
    if (el) el.scrollTop = el.scrollHeight;
  });
}

function openSettings() {
  settingsOpen.value = true;
}

onMounted(loadStatus);
</script>

<style scoped>
.center-pad { display: flex; justify-content: center; padding: 64px 0; }
.setup-card { border-radius: 12px; }
.setup-icon { font-size: 56px; color: var(--app-primary); }

.ai-shell {
  display: grid;
  grid-template-columns: 260px minmax(0, 1fr);
  gap: 16px;
  height: calc(100vh - 210px);
  min-height: 460px;
}
.ai-history {
  overflow: auto;
  border-radius: 12px;
  border: 1px solid rgba(128, 128, 128, 0.18);
  padding: 12px;
}
.history-toggle { display: none; }
@media (max-width: 991px) {
  .ai-shell { grid-template-columns: minmax(0, 1fr); height: calc(100vh - 190px); }
  .ai-history { display: none; }
  .history-toggle { display: inline-flex; }
}

.ai-chat {
  display: flex;
  flex-direction: column;
  min-width: 0;
  border-radius: 12px;
  border: 1px solid rgba(128, 128, 128, 0.18);
  overflow: hidden;
}
.ai-thread {
  flex: 1;
  overflow-y: auto;
  padding: 20px;
  display: flex;
  flex-direction: column;
  gap: 14px;
}

/* Empty state */
.ai-empty {
  margin: auto;
  max-width: 560px;
  text-align: center;
  padding: 24px 8px;
}
.ai-empty h3 { margin: 12px 0 6px; font-size: 20px; }
.ai-empty p { opacity: 0.7; margin-bottom: 18px; }
.ai-empty-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 56px;
  height: 56px;
  border-radius: 16px;
  font-size: 28px;
  color: var(--app-primary);
  background: rgba(var(--app-primary-rgb), 0.12);
}
.ai-suggestions { display: flex; flex-wrap: wrap; gap: 8px; justify-content: center; }
.ai-suggestion { white-space: normal; height: auto; padding: 6px 12px; text-align: start; }

/* Messages */
.msg { display: flex; gap: 10px; align-items: flex-start; }
.msg-user { justify-content: flex-end; }
.msg-user .bubble {
  max-width: 78%;
  white-space: pre-wrap;
  word-break: break-word;
  color: #fff;
  background: var(--app-primary);
  border-radius: 14px 14px 4px 14px;
  padding: 9px 14px;
}
.msg-ai .bubble {
  max-width: min(860px, 88%);
  min-width: 0;
  border-radius: 4px 14px 14px 14px;
  padding: 10px 14px;
  background: rgba(128, 128, 128, 0.08);
}
.avatar {
  flex: none;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 30px;
  height: 30px;
  border-radius: 10px;
  color: var(--app-primary);
  background: rgba(var(--app-primary-rgb), 0.12);
}
.tools-used {
  margin-top: 8px;
  padding-top: 8px;
  border-top: 1px dashed rgba(128, 128, 128, 0.25);
  font-size: 12px;
  opacity: 0.75;
}
.tool-tag { margin: 2px 4px 2px 0; font-size: 11px; }

/* Typing indicator */
.typing { display: inline-flex; align-items: center; gap: 4px; }
.typing .dot {
  width: 6px;
  height: 6px;
  border-radius: 50%;
  background: var(--app-primary);
  opacity: 0.4;
  animation: blink 1.2s infinite ease-in-out;
}
.typing .dot:nth-child(2) { animation-delay: 0.2s; }
.typing .dot:nth-child(3) { animation-delay: 0.4s; }
.typing-label { margin-inline-start: 8px; font-size: 13px; opacity: 0.7; }
@keyframes blink { 0%, 80%, 100% { opacity: 0.25; } 40% { opacity: 1; } }

/* Composer */
.ai-composer {
  display: flex;
  gap: 8px;
  align-items: flex-end;
  padding: 12px;
  border-top: 1px solid rgba(128, 128, 128, 0.18);
}
.ai-composer :deep(textarea) { resize: none; }
.ai-disclaimer {
  padding: 0 12px 10px;
  font-size: 11px;
  opacity: 0.55;
  text-align: center;
}

/* Rendered Markdown */
.md { line-height: 1.6; word-break: break-word; }
.md :deep(p) { margin: 0 0 8px; }
.md :deep(p:last-child) { margin-bottom: 0; }
.md :deep(ul), .md :deep(ol) { margin: 0 0 8px; padding-inline-start: 20px; }
.md :deep(h3), .md :deep(h4), .md :deep(h5), .md :deep(h6) { margin: 10px 0 6px; font-size: 15px; font-weight: 600; }
.md :deep(code) { padding: 1px 5px; border-radius: 4px; background: rgba(128, 128, 128, 0.15); font-size: 12.5px; }
.md :deep(pre) { padding: 10px; border-radius: 8px; background: rgba(128, 128, 128, 0.12); overflow: auto; }
.md :deep(pre code) { padding: 0; background: none; }
.md :deep(.md-table) { overflow-x: auto; margin: 4px 0 10px; }
.md :deep(table) { border-collapse: collapse; font-size: 13px; min-width: 60%; }
.md :deep(th), .md :deep(td) { padding: 6px 10px; border: 1px solid rgba(128, 128, 128, 0.25); text-align: start; white-space: nowrap; }
.md :deep(th) { background: rgba(128, 128, 128, 0.1); font-weight: 600; }
</style>
