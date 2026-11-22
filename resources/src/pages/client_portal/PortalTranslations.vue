<template>
  <TranslationsEditorPage
    api-base="admin/portal"
    translations-path="/client-portal/translations"
    languages-path="/client-portal/languages"
    :labels="{ title: 'Portal_Translations', section: 'Client_Portal', languagesTitle: 'Portal_Languages' }"
  />
</template>

<script setup>
/** Client Portal → Portal Translations for one portal language (route param :locale). */
import TranslationsEditorPage from '../../components/languages/TranslationsEditorPage.vue';
</script>
