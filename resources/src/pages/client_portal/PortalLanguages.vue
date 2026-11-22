<template>
  <LanguagePacksPage
    api-base="admin/portal"
    translations-path="/client-portal/translations"
    :labels="{
      title: 'Portal_Languages',
      section: 'Client_Portal',
      visit: 'PortalLang_Open_Portal',
      activeOn: 'PortalLang_Active_On_Portal',
      showOn: 'PortalLang_Show_On_Portal',
      availableHint: 'PortalLang_Available_Hint',
      installed: 'PortalLang_Installed_Success',
      deleteConfirm: 'PortalLang_Delete_Confirm',
    }"
  />
</template>

<script setup>
/** Client Portal → Portal Languages (portal_languages / portal_translations). */
import LanguagePacksPage from '../../components/languages/LanguagePacksPage.vue';
</script>
