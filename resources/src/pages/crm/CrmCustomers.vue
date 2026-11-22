<template>
  <div class="page">
    <PageHeader :title="$t('Crm_Customers')" :subtitle="$t('Crm_Customers_Subtitle')" :breadcrumb="[$t('Crm'), $t('Crm_Customers')]" />

    <div class="filters">
      <a-segmented v-model:value="scope" :options="scopeOptions" @change="crud.reload()" />
    </div>

    <DataTable :crud="crud" :columns="columns">
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'name'">
          <button type="button" class="person" @click="$router.push(`/crm/customers/${record.id}`)">
            <a-avatar :size="36" :style="{ background: avatarColor(record.name) }">{{ initials(record.name) }}</a-avatar>
            <span class="person-text">
              <span class="person-name">{{ record.name }}</span>
              <span class="person-sub">{{ record.phone || record.email || `#${record.code}` }}<template v-if="record.city"> · {{ record.city }}</template></span>
            </span>
          </button>
        </template>
        <template v-else-if="column.key === 'open_value'">
          <span v-if="record.open_deals" class="money">{{ money(record.open_value) }}<small> · {{ record.open_deals }}</small></span>
          <span v-else class="muted">—</span>
        </template>
        <template v-else-if="column.key === 'won_value'">
          <span :class="record.won_value ? 'won' : 'muted'">{{ record.won_value ? money(record.won_value) : '—' }}</span>
        </template>
        <template v-else-if="column.key === 'contacts_count'">
          <span v-if="record.contacts_count" class="contacts"><TeamOutlined /> {{ record.contacts_count }}</span>
          <span v-else class="muted">—</span>
        </template>
        <template v-else-if="column.key === 'last_activity'">
          <a-tooltip v-if="record.last_activity" :title="dateTime(record.last_activity)"><span>{{ ago(record.last_activity) }}</span></a-tooltip>
          <span v-else class="muted">—</span>
        </template>
        <template v-else-if="column.key === 'actions'">
          <a-button type="text" size="small" @click="$router.push(`/crm/customers/${record.id}`)"><template #icon><EyeOutlined /></template></a-button>
        </template>
      </template>
    </DataTable>
  </div>
</template>

<script setup>
/** CRM customers — GET crm/customers (existing clients with their CRM figures). */
import { ref, computed, onMounted } from 'vue';
import { EyeOutlined, TeamOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import { t } from '../../i18n';
import { initials, avatarColor, ago } from './crmOptions';

const { money, dateTime } = useFormat();
const scope = ref('crm');
const scopeOptions = computed(() => [
  { value: 'crm', label: t('Crm_In_The_Crm', 'In the CRM') },
  { value: 'all', label: t('Crm_All_Customers', 'All customers') },
]);
const crud = useCrudTable('crm/customers', { rowsKey: 'customers', sortField: 'last_activity', params: () => ({ scope: scope.value }) });

const columns = computed(() => [
  { title: t('Customer'), key: 'name', dataIndex: 'name', sorter: true },
  { title: t('Crm_Open_Deals'), key: 'open_value', dataIndex: 'open_value', sorter: true, width: 170, align: 'right' },
  { title: t('Crm_Won'), key: 'won_value', dataIndex: 'won_value', sorter: true, width: 150, align: 'right' },
  { title: t('Crm_Contacts'), key: 'contacts_count', width: 110, align: 'center' },
  { title: t('Crm_Last_Activity'), key: 'last_activity', dataIndex: 'last_activity', sorter: true, width: 150 },
  { title: '', key: 'actions', width: 60 },
]);

onMounted(crud.fetchRows);
</script>

<style scoped>
.muted { color: rgba(128, 128, 128, 0.75); }
.filters { margin-bottom: 12px; }
.person { display: flex; align-items: center; gap: 10px; padding: 0; border: 0; background: none; color: inherit; font: inherit; text-align: start; cursor: pointer; min-width: 220px; }
.person-name { display: block; font-weight: 500; }
.person:hover .person-name { color: var(--app-primary, #6d28d9); }
.person-sub { display: block; font-size: 12px; opacity: 0.6; }
.money { font-weight: 600; }
.money small { font-weight: 400; opacity: 0.6; }
.won { color: #16a34a; font-weight: 600; }
.contacts { display: inline-flex; align-items: center; gap: 4px; }
</style>
