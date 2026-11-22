<template>
  <a-tag :color="meta.color" style="margin: 0">{{ meta.label }}</a-tag>
</template>

<script setup>
/** Portal account state from ClientPortalController::presentAccount(). */
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const props = defineProps({ account: { type: Object, required: true } });
const { t } = useI18n();

const meta = computed(() => {
  const a = props.account;
  if (a.state === 'active') return { color: 'success', label: t('Active') };
  if (a.state === 'invited') {
    return a.invite_expired
      ? { color: 'orange', label: t('Portal_Invite_Expired') }
      : { color: 'processing', label: t('Invited') };
  }
  return { color: 'default', label: t('Disabled') };
});
</script>
