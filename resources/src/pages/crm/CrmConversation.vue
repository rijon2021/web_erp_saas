<template>
  <div class="convo">
    <!-- Thread -->
    <div ref="threadEl" class="thread" :class="{ empty: !messages.length }">
      <a-spin v-if="loading && !messages.length" class="center" />
      <a-empty v-else-if="!messages.length" :image="simpleImage" :description="$t('Crm_No_Messages')" />
      <template v-for="(m, i) in messages" :key="m.id">
        <div v-if="dayOf(m) !== dayOf(messages[i - 1])" class="day"><span>{{ dayLabel(m.created_at) }}</span></div>
        <div class="msg" :class="[m.direction, { failed: m.status === 'failed' }]">
          <div class="bubble">
            <div class="b-head">
              <span class="ch" :style="{ color: channelOf(m.channel).color }"><component :is="channelOf(m.channel).icon" :size="13" /></span>
              <span class="ch-name">{{ channelOf(m.channel).label }}</span>
              <span class="to">{{ m.direction === 'out' ? '→' : '←' }} {{ m.to }}</span>
            </div>
            <div v-if="m.subject" class="b-subject">{{ m.subject }}</div>
            <div class="b-body">{{ m.body }}</div>
            <div v-if="m.status === 'failed' && m.error" class="b-error"><AlertCircle :size="12" /> {{ m.error }}</div>
            <div class="b-foot">
              <span v-if="m.direction === 'out' && m.user_name">{{ m.user_name }} ·</span>
              <a-tooltip :title="dateTime(m.created_at)"><span>{{ timeOf(m.created_at) }}</span></a-tooltip>
              <a-tooltip v-if="m.direction === 'out'" :title="statusLabel(m.status)">
                <span class="st" :class="m.status"><component :is="statusIcon(m.status)" :size="13" /></span>
              </a-tooltip>
            </div>
          </div>
        </div>
      </template>
    </div>

    <!-- Composer -->
    <div v-if="canSend" class="composer">
      <div class="c-row">
        <a-radio-group v-model:value="form.channel" button-style="solid" size="small" @change="onChannel">
          <a-tooltip v-for="c in channels" :key="c.value" :title="ready(c.value) ? '' : c.hint">
            <a-radio-button :value="c.value" :disabled="!ready(c.value)">
              <span class="rb"><component :is="c.icon" :size="13" /> {{ c.label }}</span>
            </a-radio-button>
          </a-tooltip>
        </a-radio-group>
        <a-select
          v-model:value="form.template" :options="templateOptions" allow-clear size="small" class="tpl"
          :placeholder="$t('Crm_Use_Template')" :not-found-content="$t('Crm_No_Templates')" @change="applyTemplate"
        />
      </div>
      <template v-if="anyReady">
        <div v-if="form.channel === 'portal'" class="portal-to gap">
          <PanelsTopLeft :size="13" /> {{ $t('Crm_Portal_To') }} <strong>{{ recipients.portal?.email }}</strong>
          <span v-if="recipients.portal && !recipients.portal.visible" class="muted">· {{ $t('Crm_Portal_Will_Share') }}</span>
        </div>
        <a-auto-complete
          v-else
          v-model:value="form.to" :options="toOptions" class="gap full"
          :placeholder="form.channel === 'email' ? $t('Crm_To_Email') : $t('Crm_To_Phone')"
        />
        <a-input v-if="form.channel === 'email'" v-model:value="form.subject" :maxlength="191" :placeholder="$t('Crm_Subject')" class="gap" />
        <a-textarea
          ref="bodyEl" v-model:value="form.body" :auto-size="{ minRows: 3, maxRows: 10 }" :maxlength="5000" class="gap"
          :placeholder="$t('Crm_Write_Message')" @keydown.ctrl.enter.prevent="send" @keydown.meta.enter.prevent="send"
        />
        <div class="c-foot">
          <div class="chips">
            <a-tooltip :title="$t('Crm_Placeholders_Hint')"><span class="lbl"><Braces :size="12" /></span></a-tooltip>
            <span v-for="p in placeholders" :key="p" class="chip" @click="insert(p)">{{ '{' + p + '}' }}</span>
          </div>
          <a-button type="primary" :loading="sending" :disabled="!form.body.trim() || (!form.to && form.channel !== 'portal')" @click="send">
            <template #icon><SendHorizontal :size="14" class="send-ic" /></template>{{ $t('Crm_Send') }}
          </a-button>
        </div>
      </template>
      <a-alert v-else type="info" show-icon class="gap" :message="$t('Crm_No_Channels')" :description="$t('Crm_No_Channels_Hint')" />
    </div>
  </div>
</template>

<script setup>
/**
 * The conversation of a lead / deal / customer: email, SMS and WhatsApp
 * sent from the CRM plus WhatsApp replies, with a composer that uses the
 * marketing templates. GET/POST crm/messages.
 */
import { ref, computed, onMounted, onBeforeUnmount, nextTick, watch } from 'vue';
import dayjs from 'dayjs';
import { message, Empty } from 'ant-design-vue';
import { Mail, MessageSquareText, MessageCircle, Check, CheckCheck, Clock, AlertCircle, Inbox, Braces, SendHorizontal, PanelsTopLeft } from 'lucide-vue-next';
import { useFormat } from '../../composables/useFormat';
import { usePendingWorkStore } from '../../stores/pendingWork';
import http from '../../lib/http';
import { t } from '../../i18n';

const props = defineProps({
  leadId: { type: [Number, String], default: null },
  dealId: { type: [Number, String], default: null },
  clientId: { type: [Number, String], default: null },
  canSend: { type: Boolean, default: true },
});
const emit = defineEmits(['sent']);
const { dateTime, date } = useFormat();
const pending = usePendingWorkStore();
const simpleImage = Empty.PRESENTED_IMAGE_SIMPLE;

const CHANNELS = [
  { value: 'email', key: 'Email', label: 'Email', icon: Mail, color: '#2563eb', hintKey: 'Crm_Email_Not_Ready', hint: 'Set up a mail server in Settings → Mail first' },
  { value: 'sms', key: 'Crm_SMS', label: 'SMS', icon: MessageSquareText, color: '#7c3aed', hintKey: 'Crm_Sms_Not_Ready', hint: 'Set up an SMS gateway first (Marketing or SMS settings)' },
  { value: 'whatsapp', key: 'Crm_WhatsApp', label: 'WhatsApp', icon: MessageCircle, color: '#16a34a', hintKey: 'Crm_WhatsApp_Not_Ready', hint: 'Connect WhatsApp in Integrations → WhatsApp first' },
  { value: 'portal', key: 'Crm_Portal', label: 'Client portal', icon: PanelsTopLeft, color: '#f97316', hintKey: 'Crm_Portal_Not_Ready', hint: 'The customer needs an active client portal account', dealOnly: true },
];
const channels = computed(() => CHANNELS.filter(c => !c.dealOnly || props.dealId).map(c => ({ ...c, label: t(c.key, c.label), hint: t(c.hintKey, c.hint) })));
// Display lookup covers every channel (a lead page shows portal messages of its deals too).
function channelOf(v) { const c = CHANNELS.find(x => x.value === v) || CHANNELS[0]; return { ...c, label: t(c.key, c.label) }; }

// Meta (channels ready, templates, placeholders) is the same everywhere: fetch once per page load.
let metaPromise = null;
const meta = ref({ channels: {}, templates: [], placeholders: [] });
function loadMeta() {
  if (!metaPromise) metaPromise = http.get('crm/messages/meta').catch((e) => { metaPromise = null; throw e; });
  return metaPromise;
}
const placeholders = computed(() => meta.value.placeholders || []);
// Portal messages need a deal whose customer has an active portal account.
function ready(ch) { return !!meta.value.channels?.[ch] && (ch !== 'portal' || !!recipients.value.portal); }
const anyReady = computed(() => channels.value.some(c => ready(c.value)));

const messages = ref([]);
const recipients = ref({ emails: [], phones: [] });
const loading = ref(false);
const sending = ref(false);
const threadEl = ref(null);
const bodyEl = ref(null);
const form = ref({ channel: 'email', to: '', subject: '', body: '', template: undefined });

const params = computed(() => {
  const p = {};
  if (props.dealId) p.deal = props.dealId;
  if (props.leadId) p.lead = props.leadId;
  if (props.clientId) p.client = props.clientId;
  return p;
});
const ids = computed(() => ({
  crm_deal_id: props.dealId || undefined,
  crm_lead_id: props.leadId || undefined,
  client_id: props.clientId || undefined,
}));

const toOptions = computed(() => (form.value.channel === 'email' ? recipients.value.emails : recipients.value.phones)
  .map(r => ({ value: r.value, label: r.label })));
const templateOptions = computed(() => (meta.value.templates || [])
  .filter(tp => tp.type === form.value.channel)
  .map(tp => ({ value: tp.id, label: tp.name })));

async function load(spinner = true) {
  if (spinner) loading.value = true;
  const atBottom = isAtBottom();
  try {
    const res = await http.get('crm/messages', params.value);
    const grew = res.messages.length !== messages.value.length;
    messages.value = res.messages;
    recipients.value = res.recipients || { emails: [], phones: [] };
    if (!form.value.to) pickRecipient();
    // Opening the thread marked the replies read: drop the bell badge now.
    if (res.messages.some(m => m.unread)) pending.fetch(true);
    if (spinner || (grew && atBottom)) scrollDown();
  } catch (e) {
    if (spinner) message.error(e?.data?.message || t('Crm_Err_Could_Not_Load_The_Conversation', 'Could not load the conversation'));
  } finally {
    loading.value = false;
  }
}

function pickRecipient() {
  form.value.to = toOptions.value[0]?.value || '';
}
function onChannel() {
  form.value.template = undefined;
  pickRecipient();
}

async function applyTemplate(id) {
  const tpl = (meta.value.templates || []).find(x => x.id === id);
  if (!tpl) return;
  try {
    const res = await http.post('crm/messages/render', { ...ids.value, subject: tpl.subject || '', body: tpl.content || '' });
    form.value.subject = res.subject || form.value.subject;
    form.value.body = stripHtml(res.body || '');
  } catch (e) {
    message.error(e?.data?.message || t('Crm_Err_Could_Not_Load_The_Template', 'Could not load the template'));
  }
}
// Email templates are often HTML; the CRM sends plain text with line breaks.
function stripHtml(s) {
  if (!/<[a-z][\s\S]*>/i.test(s)) return s;
  const withBreaks = s.replace(/<br\s*\/?>/gi, '\n').replace(/<\/(p|div|li|h[1-6])>/gi, '\n');
  const doc = new DOMParser().parseFromString(withBreaks, 'text/html');
  return (doc.body.textContent || '').replace(/\n{3,}/g, '\n\n').trim();
}

function insert(p) {
  const token = `{${p}}`;
  const el = bodyEl.value?.resizableTextArea?.textArea || bodyEl.value?.$el?.querySelector?.('textarea');
  const b = form.value.body || '';
  if (el && typeof el.selectionStart === 'number') {
    const s = el.selectionStart;
    const e = el.selectionEnd;
    form.value.body = b.slice(0, s) + token + b.slice(e);
    nextTick(() => { el.focus(); el.setSelectionRange(s + token.length, s + token.length); });
  } else {
    form.value.body = b + token;
  }
}

async function send() {
  const f = form.value;
  if (!f.body.trim() || (!f.to && f.channel !== 'portal') || sending.value) return;
  if (f.channel === 'email' && !f.subject.trim()) { message.error(t('Crm_Subject_Required', 'An email needs a subject')); return; }
  sending.value = true;
  try {
    const res = await http.post('crm/messages', {
      ...ids.value, channel: f.channel, to: f.to, subject: f.channel === 'email' ? f.subject : '', body: f.body,
      marketing_template_id: f.template || undefined,
    });
    messages.value.push(res.message_row);
    form.value = { ...f, subject: '', body: '', template: undefined };
    message.success(t('Crm_Message_Sent', 'Message sent'));
    scrollDown();
    emit('sent', res.message_row);
  } catch (e) {
    // A failed send is still recorded: show it in the thread with its error.
    if (e?.data?.message_row) { messages.value.push(e.data.message_row); scrollDown(); }
    const errors = e?.data?.errors;
    message.error((errors && Object.values(errors)[0]?.[0]) || e?.data?.message || t('Crm_Err_Could_Not_Send', 'Could not send'));
  } finally {
    sending.value = false;
  }
}

function isAtBottom() {
  const el = threadEl.value;
  return !el || el.scrollHeight - el.scrollTop - el.clientHeight < 40;
}
function scrollDown() {
  nextTick(() => { const el = threadEl.value; if (el) el.scrollTop = el.scrollHeight; });
}

function dayOf(m) { return m ? dayjs(m.created_at).format('YYYY-MM-DD') : null; }
function dayLabel(iso) {
  const d = dayjs(iso);
  if (d.isSame(dayjs(), 'day')) return t('Crm_Today', 'Today');
  if (d.isSame(dayjs().subtract(1, 'day'), 'day')) return t('Crm_Yesterday', 'Yesterday');
  return date(iso);
}
function timeOf(iso) { return dayjs(iso).format('HH:mm'); }

const STATUS = {
  queued: { icon: Clock, key: 'Crm_Msg_Queued', label: 'Queued' },
  sent: { icon: Check, key: 'Crm_Msg_Sent', label: 'Sent' },
  delivered: { icon: CheckCheck, key: 'Crm_Msg_Delivered', label: 'Delivered' },
  read: { icon: CheckCheck, key: 'Crm_Msg_Read', label: 'Read' },
  failed: { icon: AlertCircle, key: 'Crm_Msg_Failed', label: 'Not sent' },
  received: { icon: Inbox, key: 'Crm_Msg_Received', label: 'Received' },
};
function statusIcon(s) { return (STATUS[s] || STATUS.sent).icon; }
function statusLabel(s) { const o = STATUS[s] || STATUS.sent; return t(o.key, o.label); }

// New WhatsApp replies show up without reloading the page.
let timer = null;
function poll() { if (document.visibilityState === 'visible' && !sending.value) load(false); }

watch(params, () => { form.value.to = ''; load(); });
onMounted(async () => {
  try {
    meta.value = await loadMeta();
    if (!ready(form.value.channel)) form.value.channel = CHANNELS.find(c => ready(c.value))?.value || 'email';
  } catch (e) { /* the thread still loads */ }
  await load();
  timer = setInterval(poll, 30000);
});
onBeforeUnmount(() => clearInterval(timer));

defineExpose({ reload: () => load(false) });
</script>

<style scoped>
.convo { display: flex; flex-direction: column; gap: 12px; }
.thread {
  max-height: 480px; min-height: 140px; overflow-y: auto; padding: 8px 6px;
  border-radius: 12px; background: rgba(128, 128, 128, 0.05); display: flex; flex-direction: column; gap: 6px;
}
.thread.empty { justify-content: center; }
.center { display: block; margin: 40px auto; }
.day { text-align: center; margin: 8px 0 4px; }
.day span { font-size: 11px; font-weight: 600; padding: 2px 10px; border-radius: 999px; background: rgba(128, 128, 128, 0.14); opacity: 0.8; }
.msg { display: flex; }
.msg.out { justify-content: flex-end; }
.bubble { max-width: 78%; padding: 8px 12px; border-radius: 14px; font-size: 13.5px; }
.msg.in .bubble { background: var(--ant-color-bg-container, #fff); border: 1px solid rgba(128, 128, 128, 0.18); border-bottom-left-radius: 4px; }
.msg.out .bubble { background: rgba(var(--app-primary-rgb, 109, 40, 217), 0.1); border: 1px solid rgba(var(--app-primary-rgb, 109, 40, 217), 0.18); border-bottom-right-radius: 4px; }
.msg.failed .bubble { background: rgba(220, 38, 38, 0.06); border-color: rgba(220, 38, 38, 0.35); }
.b-head { display: flex; align-items: center; gap: 5px; font-size: 11.5px; opacity: 0.75; margin-bottom: 3px; }
.ch { display: inline-flex; }
.ch-name { font-weight: 600; }
.to { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.b-subject { font-weight: 600; margin-bottom: 2px; }
.b-body { white-space: pre-wrap; word-break: break-word; }
.b-error { color: #dc2626; font-size: 12px; margin-top: 4px; display: flex; gap: 4px; align-items: flex-start; }
.b-error :deep(svg) { flex: none; margin-top: 2px; }
.b-foot { display: flex; justify-content: flex-end; align-items: center; gap: 4px; font-size: 11px; opacity: 0.6; margin-top: 3px; }
.st { display: inline-flex; }
.st.read { color: #0ea5e9; opacity: 1; }
.st.failed { color: #dc2626; opacity: 1; }
.composer { border: 1px solid rgba(128, 128, 128, 0.18); border-radius: 12px; padding: 10px; }
.c-row { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; justify-content: space-between; }
.rb { display: inline-flex; align-items: center; gap: 5px; }
.tpl { min-width: 200px; flex: 0 1 260px; }
.gap { margin-top: 8px; }
.full { width: 100%; }
.portal-to { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; font-size: 13px; padding: 6px 10px; border-radius: 8px; background: rgba(249, 115, 22, 0.08); }
.muted { opacity: 0.6; }
.c-foot { display: flex; align-items: flex-end; gap: 10px; justify-content: space-between; margin-top: 8px; }
.chips { display: flex; flex-wrap: wrap; gap: 4px; align-items: center; flex: 1; min-width: 0; }
.lbl { display: inline-flex; opacity: 0.55; margin-inline-end: 2px; }
.chip {
  font-size: 11px; font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; cursor: pointer; user-select: none;
  padding: 1px 6px; border-radius: 6px; background: rgba(128, 128, 128, 0.12);
}
.chip:hover { background: rgba(var(--app-primary-rgb, 109, 40, 217), 0.15); color: var(--app-primary, #6d28d9); }
.send-ic { margin-inline-end: 6px; vertical-align: -2px; }
</style>
