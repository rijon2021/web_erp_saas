<template>
  <div class="page">
    <a-result
      status="404"
      :title="$t('Page_Not_Available')"
      :sub-title="$t('Page_Not_Available_Desc')"
    >
      <template #extra>
        <a-button type="primary" @click="$router.push('/dashboard')">{{ $t('Go_to_Dashboard') }}</a-button>
      </template>
    </a-result>
  </div>
</template>
