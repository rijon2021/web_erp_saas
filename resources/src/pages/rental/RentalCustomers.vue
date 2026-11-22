<template>
  <div class="page">
    <PageHeader :title="$t('Rental_Customers')" :breadcrumb="[$t('Rental_Management'), $t('Rental_Customers')]">
      <template #actions>
        <a-button v-if="canAdd" type="primary" @click="$router.push('/rental/customers/create')">
          <template #icon><UserAddOutlined /></template>
          {{ $t('Rental_Add_Customer') }}
        </a-button>
      </template>
    </PageHeader>

    <!-- Summary chips double as quick filters -->
    <div class="chips">
      <button type="button" class="chip" :class="{ active: quick === 'all' }" @click="setQuick('all')">
        <span class="chip-ic chip-ic--brand"><TeamOutlined /></span>
        <span class="chip-text">
          <span class="chip-value">{{ summary.total }}</span>
          <span class="chip-label">{{ $t('Rental_Customers') }}</span>
        </span>
      </button>
      <button type="button" class="chip" :class="{ active: quick === 'attention' }" @click="setQuick('attention')">
        <span class="chip-ic chip-ic--warn"><IdcardOutlined /></span>
        <span class="chip-text">
          <span class="chip-value">{{ summary.id_attention }}</span>
          <span class="chip-label">{{ $t('Rental_ID_Needs_Attention') }}</span>
        </span>
      </button>
      <button type="button" class="chip" :class="{ active: quick === 'blacklisted' }" @click="setQuick('blacklisted')">
        <span class="chip-ic chip-ic--danger"><StopOutlined /></span>
        <span class="chip-text">
          <span class="chip-value">{{ summary.blacklisted }}</span>
          <span class="chip-label">{{ $t('Rental_Blacklisted') }}</span>
        </span>
      </button>
    </div>

    <DataTable :crud="crud" :columns="columns" selectable>
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'name'">
          <button type="button" class="person" @click="open(record)">
            <a-avatar :size="36" :style="{ background: avatarColor(record.name) }">{{ initials(record.name) }}</a-avatar>
            <span class="person-text">
              <span class="person-name">
                {{ record.name }}
                <a-tooltip v-if="record.is_blacklisted" :title="$t('Rental_Blacklisted')">
                  <StopOutlined class="bl-icon" />
                </a-tooltip>
              </span>
              <span class="person-sub">{{ record.phone || record.email || '—' }}</span>
            </span>
          </button>
        </template>

        <template v-else-if="column.key === 'id_doc'">
          <span v-if="record.id_number" class="id-doc">
            <span class="id-type">{{ labelOf(ID_TYPES, record.id_type) }}</span>
            <span class="mono">{{ record.id_number }}</span>
          </span>
          <span v-else class="muted">—</span>
        </template>

        <template v-else-if="column.key === 'id_status'">
          <a-tooltip :title="record.id_expiry ? `${$t('Rental_ID_Expiry')}: ${date(record.id_expiry)}` : ''">
            <a-tag :color="optionOf(ID_STATUSES, record.id_status).color">
              {{ idStatusText(record) }}
            </a-tag>
          </a-tooltip>
        </template>

        <template v-else-if="column.key === 'standing'">
          <a-tag v-if="record.is_blacklisted" color="error">{{ $t('Rental_Blacklisted') }}</a-tag>
          <a-rate v-else-if="record.rating" :value="record.rating" disabled class="mini-rate" />
          <span v-else class="muted">{{ $t('Rental_Not_Rated') }}</span>
        </template>

        <template v-else-if="column.key === 'documents_count'">
          <span v-if="record.documents_count" class="docs"><PaperClipOutlined /> {{ record.documents_count }}</span>
          <span v-else class="muted">—</span>
        </template>

        <template v-else-if="column.key === 'actions'">
          <a-space :size="0">
            <a-tooltip :title="$t('View')">
              <a-button type="text" size="small" @click="open(record)">
                <template #icon><EyeOutlined /></template>
              </a-button>
            </a-tooltip>
            <a-tooltip v-if="canEdit" :title="$t('Edit')">
              <a-button type="text" size="small" @click="$router.push(`/rental/customers/${record.id}/edit`)">
                <template #icon><EditOutlined /></template>
              </a-button>
            </a-tooltip>
            <a-tooltip v-if="canDelete" :title="$t('Rental_Remove_From_Rentals')">
              <a-button type="text" size="small" danger @click="remove(record)">
                <template #icon><DeleteOutlined /></template>
              </a-button>
            </a-tooltip>
          </a-space>
        </template>
      </template>
    </DataTable>
  </div>
</template>

<script setup>
/**
 * Rental customers — GET rental/customers → { customers, totalRows, summary }.
 * Each row is an existing Stocky client with a rental profile; removing one
 * drops the profile only (the client stays in Customers).
 */
import { ref, computed, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import {
  UserAddOutlined, TeamOutlined, IdcardOutlined, StopOutlined, EyeOutlined,
  EditOutlined, DeleteOutlined, PaperClipOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import { useAuthStore } from '../../stores/auth';
import { t, i18n } from '../../i18n';
import { ID_TYPES, ID_STATUSES, labelOf, optionOf, initials, avatarColor } from './rentalOptions';

const auth = useAuthStore();
const route = useRoute();
const router = useRouter();
const { date } = useFormat();

const canAdd = computed(() => auth.can('rnt_customers_add'));
const canEdit = computed(() => auth.can('rnt_customers_edit'));
const canDelete = computed(() => auth.can('rnt_customers_delete'));

const quick = ref(['attention', 'blacklisted'].includes(route.query.filter) ? route.query.filter : 'all');

const crud = useCrudTable('rental/customers', {
  rowsKey: 'customers',
  sortField: 'created_at',
  params: () => ({
    standing: quick.value === 'blacklisted' ? 'blacklisted' : '',
    id_status: quick.value === 'attention' ? 'attention' : '',
  }),
});

const summary = computed(() => crud.payload.value?.summary || { total: 0, blacklisted: 0, id_attention: 0 });

function setQuick(value) {
  quick.value = value;
  crud.reload();
}

function open(row) {
  router.push(`/rental/customers/${row.id}`);
}

/** "ID expiring · 12d" style text with the day count where it helps. */
function idStatusText(row) {
  const base = labelOf(ID_STATUSES, row.id_status);
  if (row.id_status === 'expiring' && row.id_days_left !== null) {
    return `${base} · ${tp('Rental_Days_Left', '{n}d left', { n: row.id_days_left })}`;
  }
  return base;
}

function tp(key, fallback, params) {
  const out = i18n.global.t(key, params);
  if (out !== key) return out;
  return Object.entries(params).reduce((s, [k, v]) => s.replace(`{${k}}`, v), fallback);
}

function remove(row) {
  crud.remove(row, { label: `${row.name} — ${t('Rental_Remove_Note', 'the customer record itself is kept')}` });
}

const columns = computed(() => [
  { title: t('Customer'), key: 'name', dataIndex: 'name', sorter: true },
  { title: t('Rental_ID_Document'), key: 'id_doc', width: 210 },
  { title: t('Rental_ID_Status'), key: 'id_status', dataIndex: 'id_expiry', sorter: true, width: 170 },
  { title: t('City'), dataIndex: 'city', key: 'city', width: 130 },
  { title: t('Rental_Guarantor'), dataIndex: 'guarantor_name', key: 'guarantor', width: 160 },
  { title: t('Rental_Standing'), key: 'standing', dataIndex: 'rating', sorter: true, width: 150 },
  { title: t('Rental_Docs'), key: 'documents_count', width: 80, align: 'center' },
  { title: '', key: 'actions', width: 120 },
]);

onMounted(crud.fetchRows);
</script>

<style scoped>
.muted {
  color: rgba(128, 128, 128, 0.75);
}
.mono {
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
  font-size: 12.5px;
}

/* ---------------- summary chips ---------------- */
.chips {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 12px;
  margin-bottom: 16px;
}
.chip {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 12px 16px;
  border: 1px solid rgba(128, 128, 128, 0.2);
  border-radius: 14px;
  background: transparent;
  color: inherit;
  font: inherit;
  text-align: start;
  cursor: pointer;
  transition: border-color 0.15s ease, background 0.15s ease;
}
.chip:hover {
  border-color: rgba(var(--app-primary-rgb, 109, 40, 217), 0.5);
}
.chip.active {
  border-color: var(--app-primary, #6d28d9);
  background: rgba(var(--app-primary-rgb, 109, 40, 217), 0.06);
}
.chip-ic {
  width: 38px;
  height: 38px;
  flex: none;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 11px;
  font-size: 17px;
}
.chip-ic--brand {
  color: var(--app-primary, #6d28d9);
  background: rgba(var(--app-primary-rgb, 109, 40, 217), 0.12);
}
.chip-ic--warn {
  color: #d97706;
  background: rgba(217, 119, 6, 0.14);
}
.chip-ic--danger {
  color: #dc2626;
  background: rgba(220, 38, 38, 0.12);
}
.chip-text {
  display: flex;
  flex-direction: column;
}
.chip-value {
  font-size: 19px;
  font-weight: 600;
  line-height: 1.2;
}
.chip-label {
  font-size: 12px;
  opacity: 0.6;
}

/* ---------------- cells ---------------- */
.person {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 0;
  border: 0;
  background: none;
  color: inherit;
  font: inherit;
  text-align: start;
  cursor: pointer;
  min-width: 200px;
}
.person-text {
  min-width: 0;
}
.person-name {
  display: flex;
  align-items: center;
  gap: 6px;
  font-weight: 500;
}
.person:hover .person-name {
  color: var(--app-primary, #6d28d9);
}
.person-sub {
  display: block;
  font-size: 12px;
  opacity: 0.6;
}
.bl-icon {
  color: #dc2626;
}
.id-doc {
  display: flex;
  flex-direction: column;
}
.id-type {
  font-size: 11.5px;
  opacity: 0.6;
}
.mini-rate {
  font-size: 13px;
}
.mini-rate :deep(.ant-rate-star:not(:last-child)) {
  margin-inline-end: 2px;
}
.docs {
  display: inline-flex;
  align-items: center;
  gap: 4px;
}
</style>
