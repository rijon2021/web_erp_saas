<template>
  <div class="guide">
    <p class="lead">
      {{ $t('Tg_Guide_Lead') }}
      <template v-if="state.cron?.send">
        {{ $t('Tg_Guide_Lead_Cron') }}
      </template>
    </p>

    <!-- ---------------------------------------------- setup -->
    <h3 class="heading">{{ $t('Tg_Guide_Set_It_Up') }}</h3>

    <section class="step">
      <h4><span class="num">1</span> {{ $t('Tg_Guide_Step1_Title') }}</h4>
      <i18n-t keypath="Tg_Guide_Step1_Body" tag="p">
        <template #botfather><strong>@BotFather</strong></template>
        <template #newbot><code>/newbot</code></template>
        <template #botToken><strong>{{ $t('Tg_Guide_Bot_Token_Lower') }}</strong></template>
        <template #example><code>123456789:ABCdefGhIJKlmNoPQRsTUVwxyZ</code></template>
      </i18n-t>
    </section>

    <section class="step">
      <h4><span class="num">2</span> {{ $t('Tg_Guide_Step2_Title') }}</h4>
      <p>{{ $t('Tg_Guide_Step2_Intro') }}</p>
      <ul class="plain">
        <i18n-t keypath="Tg_Guide_Own_Chat_Item" tag="li">
          <template #label><strong>{{ $t('Tg_Guide_Own_Chat') }}</strong></template>
          <template #userinfobot><strong>@userinfobot</strong></template>
        </i18n-t>
        <i18n-t keypath="Tg_Guide_Group_Item" tag="li">
          <template #label><strong>{{ $t('Tg_Guide_Group') }}</strong></template>
        </i18n-t>
        <i18n-t keypath="Tg_Guide_Channel_Item" tag="li">
          <template #label><strong>{{ $t('Tg_Guide_Channel') }}</strong></template>
          <template #admin><strong>{{ $t('Tg_Guide_Administrator') }}</strong></template>
        </i18n-t>
      </ul>
      <p>
        {{ $t('Tg_Guide_GetUpdates_Intro') }}
      </p>
      <div class="cmd">
        <code>https://api.telegram.org/bot&lt;YOUR_TOKEN&gt;/getUpdates</code>
      </div>
      <i18n-t keypath="Tg_Guide_GetUpdates_Note" tag="p" class="note">
        <template #chatId><code>"chat":{"id": …}</code></template>
        <template #example><code>-1001234567890</code></template>
      </i18n-t>
    </section>

    <section class="step">
      <h4><span class="num">3</span> {{ $t('Tg_Guide_Step3_Title') }}</h4>
      <i18n-t keypath="Tg_Guide_Step3_Body" tag="p">
        <template #settings><a class="link" @click="$emit('open-settings')">{{ $t('Settings') }}</a></template>
        <template #allEvents><strong>{{ $t('Int_All_Events') }}</strong></template>
        <template #events><code>sale.created</code>, <code>purchase.created</code>, <code>payment.created</code>,
          <code>expense.created</code>, <code>cash_register.opened</code></template>
        <template #enable><strong>{{ $t('Int_Enable_Notifications', { name: 'Telegram' }) }}</strong></template>
        <template #sendTest><strong>{{ $t('Int_Send_Test_Message') }}</strong></template>
      </i18n-t>
      <p class="state">
        <a-tag :color="state.enabled && state.configured ? 'success' : state.configured ? 'warning' : 'default'">
          {{ state.enabled && state.configured ? $t('Tg_Guide_State_Enabled') : state.configured ? $t('Tg_Guide_State_Saved_Off') : $t('Tg_Guide_State_Not_Configured') }}
        </a-tag>
        <span v-if="state.chat_id" class="muted">{{ $t('Tg_Guide_Chat_Id', { id: state.chat_id }) }}</span>
      </p>
    </section>

    <!-- ---------------------------------------------- delivery -->
    <h3 class="heading">{{ $t('Tg_Guide_How_Sent') }}</h3>

    <section class="step">
      <h4><span class="num">A</span> {{ $t('Tg_Guide_A_Title') }}</h4>
      <i18n-t keypath="Tg_Guide_A_Body" tag="p">
        <template #rightAfter><em>{{ $t('Tg_Guide_Right_After') }}</em></template>
      </i18n-t>
    </section>

    <!-- Only where the operator runs the server (not shown to SaaS tenants,
         who get no cron line from the API). -->
    <section v-if="state.cron?.send" class="step">
      <h4><span class="num">B</span> {{ $t('Tg_Guide_B_Title') }}</h4>
      <p>
        {{ $t('Tg_Guide_B_Body') }}
      </p>
      <div class="cmd">
        <code>{{ state.cron.send }}</code>
        <a-button size="small" @click="copy(state.cron?.send)">{{ $t('Copy') }}</a-button>
      </div>
      <i18n-t keypath="Tg_Guide_B_Note" tag="p" class="note">
        <template #cronJobs><em>Cron Jobs</em></template>
        <template #php><code>php</code></template>
        <template #php2><code>php</code></template>
        <template #args><code>artisan telegram:send</code></template>
        <template #path><code>{{ state.cron.base_path }}</code></template>
      </i18n-t>
      <p v-if="state.queue?.waiting != null" class="state">
        <a-tag :color="state.queue.waiting ? 'warning' : 'success'">
          {{ state.queue.waiting ? $t('Tg_Guide_N_Waiting', { n: state.queue.waiting }) : $t('Tg_Guide_Nothing_Waiting') }}
        </a-tag>
        <a-tag v-if="state.queue.failed" color="error">{{ $t('Tg_Guide_N_Gave_Up', { n: state.queue.failed }) }}</a-tag>
        <span v-if="state.queue.waiting" class="muted">{{ $t('Tg_Guide_Growing_Hint') }}</span>
      </p>
      <p class="note">
        {{ $t('Tg_Guide_Without_Cron') }}
      </p>
    </section>

    <section v-else class="step">
      <h4><span class="num">B</span> {{ $t('Tg_Guide_Else_Title') }}</h4>
      <p>
        {{ $t('Tg_Guide_Else_Body') }}
      </p>
    </section>

    <!-- ---------------------------------------------- reference -->
    <h3 class="heading">{{ $t('Tg_Guide_Not_Arrive') }}</h3>

    <section class="step">
      <ul class="plain">
        <i18n-t keypath="Tg_Guide_Err_401" tag="li"><template #code><strong>HTTP 401 Unauthorized</strong></template></i18n-t>
        <i18n-t keypath="Tg_Guide_Err_400" tag="li"><template #code><strong>HTTP 400 chat not found</strong></template></i18n-t>
        <i18n-t keypath="Tg_Guide_Err_403" tag="li"><template #code><strong>HTTP 403 bot is not a member / was kicked</strong></template></i18n-t>
        <i18n-t keypath="Tg_Guide_Test_Works_Item" tag="li">
          <template #label><strong>{{ $t('Tg_Guide_Test_Works') }}</strong></template>
          <template #allEvents><em>{{ $t('Int_All_Events') }}</em></template>
        </i18n-t>
        <i18n-t v-if="state.cron?.send" keypath="Tg_Guide_Imported_Never_Item" tag="li">
          <template #label><strong>{{ $t('Tg_Guide_Imported_Never') }}</strong></template>
        </i18n-t>
      </ul>
    </section>
  </div>
</template>

<script setup>
/**
 * Telegram setup guide, shown on the Guide tab of the Telegram page. Takes
 * the page's settings payload (state, queue counts, this server's cron line)
 * so what it shows matches the install it is on.
 */
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

defineProps({
  state: { type: Object, default: () => ({}) },
});

defineEmits(['open-settings']);

function copy(text) {
  if (!text) return;
  navigator.clipboard?.writeText(text)
    .then(() => message.success(t('Tg_Cron_Line_Copied')))
    // No clipboard (insecure origin, locked-down browser): show the text so it
    // can still be selected by hand rather than failing silently.
    .catch(() => message.info(text));
}
</script>

<style scoped>
.guide {
  max-width: 860px;
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
  background: rgba(var(--app-primary-rgb, 109, 40, 217), 0.14);
  font-size: 12px;
  flex: none;
}
.step p {
  margin: 0 0 8px;
  font-size: 13px;
  line-height: 1.6;
}
.plain {
  margin: 0 0 8px;
  padding-inline-start: 18px;
  font-size: 13px;
  line-height: 1.75;
}
.state .muted {
  margin-inline-start: 6px;
  opacity: 0.6;
  font-size: 12px;
}
.note {
  font-size: 12px;
  opacity: 0.68;
  line-height: 1.6;
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
.link {
  color: var(--app-primary, #6d28d9);
  cursor: pointer;
}
</style>
