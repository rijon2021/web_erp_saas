<template>
  <div class="page">
    <PageHeader :title="$t('Portal_Deletion_Log')" :subtitle="$t('Portal_Deletion_Log_Subtitle')" :breadcrumb="[$t('Client_Portal'), $t('Portal_Deletion_Log')]" />

    <DataTable :crud="crud" :columns="columns">
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'client_name'">
          <a v-if="record.client_id" @click="router.push(`/customers/${record.client_id}/details`)">{{ record.client_name || '—' }}</a>
          <span v-else>{{ record.client_name || '—' }}</span>
        </template>
        <template v-else-if="column.key === 'reason'">
          <span v-if="record.reason" class="reason">{{ record.reason }}</span>
          <span v-else class="muted">—</span>
        </template>
        <template v-else-if="column.key === 'created_at'">{{ dateTime(record.created_at) }}</template>
      </template>
    </DataTable>
  </div>
</template>

<script setup>
/**
 * Client-initiated portal account closures (portal_account_deletions, written
 * by PortalProfileController@destroyAccount). Read-only: the customer record
 * and its documents were kept; only the portal login was removed.
 * GET client-portal/deletions → { deletions, totalRows }.
 */
import { computed, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { useI18n } from 'vue-i18n';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';

const { t } = useI18n();
const router = useRouter();
const { dateTime } = useFormat();

const crud = useCrudTable('client-portal/deletions', { rowsKey: 'deletions' });

const columns = computed(() => [
  { title: t('Date'), key: 'created_at', dataIndex: 'created_at', sorter: true },
  { title: t('Customer'), key: 'client_name', dataIndex: 'client_name', sorter: true },
  { title: t('Email'), key: 'email', dataIndex: 'email', sorter: true },
  { title: t('Reason'), key: 'reason', dataIndex: 'reason' },
  { title: t('IP_Address'), key: 'ip', dataIndex: 'ip' },
]);

onMounted(() => crud.fetchRows());
</script>

<style scoped>
.reason { white-space: pre-line; display: inline-block; max-width: 420px; }
.muted { opacity: .5; }
</style>
