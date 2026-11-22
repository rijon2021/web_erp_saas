<template>
  <div class="page">
    <PageHeader :title="$t('Vehicle_Leads')" :breadcrumb="[$t('Store'), $t('Vehicle_Leads')]" />

    <a-alert v-if="!ready" type="warning" show-icon style="margin-bottom: 16px" :message="$t('Vehicle_Leads_Not_Ready')" />

    <a-card size="small" :body-style="{ padding: 0 }">
      <div class="tabs">
        <a-segmented v-model:value="typeFilter" :options="typeOptions" @change="refetch" />
      </div>
      <div class="toolbar">
        <a-input-search v-model:value="search" :placeholder="$t('Search')" allow-clear style="max-width: 240px" @search="refetch" @change="onSearchChange" />
        <a-select v-model:value="statusFilter" style="width: 190px" @change="refetch">
          <a-select-option value="">{{ $t('All') }}</a-select-option>
          <a-select-option v-for="st in STATUSES" :key="st" :value="st">{{ statusLabel(st) }}{{ counts.statuses[st] ? ` (${counts.statuses[st]})` : '' }}</a-select-option>
        </a-select>
      </div>
      <a-table
        :columns="columns" :data-source="rows" :loading="isLoading" row-key="id" size="middle"
        :pagination="{ current: page, pageSize: perPage, total, showSizeChanger: true }"
        :scroll="{ x: 'max-content' }" :locale="{ emptyText: $t('No_Vehicle_Leads') }"
        @change="onTableChange"
      >
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'created_at'">{{ formatDate(record.created_at) }}</template>
          <template v-else-if="column.key === 'type'"><a-tag :color="typeColor(record.type)">{{ typeLabel(record.type) }}</a-tag></template>
          <template v-else-if="column.key === 'customer'">
            <div style="font-weight: 600">{{ record.name }}</div>
            <div class="sub">
              <a v-if="record.phone" :href="'tel:' + record.phone">{{ record.phone }}</a>
              <span v-if="record.phone && record.email"> · </span>
              <a v-if="record.email" :href="'mailto:' + record.email">{{ record.email }}</a>
            </div>
          </template>
          <template v-else-if="column.key === 'vehicle'">
            <router-link v-if="record.product_id" :to="`/products/${record.product_id}/edit`">{{ record.product_name || '#' + record.product_id }}</router-link>
            <span v-else>—</span>
          </template>
          <template v-else-if="column.key === 'details'">
            <div class="details">{{ details(record) || '—' }}</div>
          </template>
          <template v-else-if="column.key === 'assignee'">
            <a-select
              :value="record.assigned_to || undefined" allow-clear size="small" style="width: 160px" :placeholder="$t('Unassigned')"
              :options="users.map(u => ({ value: u.id, label: u.name }))" @change="v => update(record, { assigned_to: v || null })"
            />
          </template>
          <template v-else-if="column.key === 'status'">
            <a-select :value="record.status" size="small" style="width: 130px" @change="v => update(record, { status: v })">
              <a-select-option v-for="st in STATUSES" :key="st" :value="st"><a-badge :status="statusBadge(st)" :text="statusLabel(st)" /></a-select-option>
            </a-select>
          </template>
          <template v-else-if="column.key === 'actions'">
            <a-space>
              <a-tooltip :title="$t('Staff_Note')"><a-button size="small" @click="openNote(record)"><template #icon><FormOutlined /></template></a-button></a-tooltip>
              <a-tooltip v-if="crm" :title="$t('Send_To_CRM')"><a-button size="small" @click="toCrm(record)"><template #icon><UserAddOutlined /></template></a-button></a-tooltip>
              <a-tooltip v-if="record.phone" title="WhatsApp"><a-button size="small" :href="'https://wa.me/' + digits(record.phone)" target="_blank"><template #icon><WhatsAppOutlined /></template></a-button></a-tooltip>
              <a-button size="small" danger @click="confirmDelete(record)"><template #icon><DeleteOutlined /></template></a-button>
            </a-space>
          </template>
        </template>
      </a-table>
    </a-card>

    <a-modal v-model:open="noteOpen" :title="$t('Staff_Note')" :ok-text="$t('Save')" :cancel-text="$t('Cancel')" @ok="saveNote">
      <p v-if="noteRow && noteRow.message" class="sub" style="white-space: pre-line">{{ noteRow.message }}</p>
      <a-textarea v-model:value="noteText" :rows="5" :maxlength="5000" />
    </a-modal>
  </div>
</template>

<script setup>
/**
 * Car Dealership theme leads — GET store/vehicle-leads?type&status&search&page&per_page
 * → {data, meta, counts{types,statuses}, users, crm, ready}; POST store/vehicle-leads/{id}
 * {status|assigned_to|staff_note}; POST .../{id}/crm; DELETE .../{id}.
 */
import { ref, computed, onMounted } from 'vue';
import { message, Modal } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { DeleteOutlined, FormOutlined, UserAddOutlined, WhatsAppOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';

const { t } = useI18n();

const TYPES = ['enquiry', 'test_drive', 'trade_in', 'finance', 'reservation'];
const STATUSES = ['new', 'contacted', 'booked', 'won', 'lost'];

const isLoading = ref(true);
const ready = ref(true);
const search = ref('');
const typeFilter = ref('');
const statusFilter = ref('');
const rows = ref([]);
const users = ref([]);
const crm = ref(false);
const counts = ref({ types: {}, statuses: {} });
const page = ref(1);
const perPage = ref(15);
const total = ref(0);
const noteOpen = ref(false);
const noteRow = ref(null);
const noteText = ref('');
let searchTimer = null;

const typeOptions = computed(() => {
  const all = Object.values(counts.value.types || {}).reduce((a, b) => a + Number(b || 0), 0);
  return [{ value: '', label: `${t('All')} ${all}` }].concat(TYPES.map(k => ({ value: k, label: `${typeLabel(k)} ${counts.value.types[k] || 0}` })));
});

const columns = computed(() => [
  { title: t('Received'), key: 'created_at' },
  { title: t('type'), key: 'type' },
  { title: t('Customer'), key: 'customer' },
  { title: t('Vehicle'), key: 'vehicle' },
  { title: t('Details'), key: 'details' },
  { title: t('Assigned_To'), key: 'assignee' },
  { title: t('Status'), key: 'status' },
  { title: t('Actions'), key: 'actions', fixed: 'right' },
]);

function typeLabel(k) { return t('Vehicle_Lead_' + k); }
function typeColor(k) { return { enquiry: 'blue', test_drive: 'green', trade_in: 'orange', finance: 'purple', reservation: 'magenta' }[k] || 'default'; }
function statusLabel(k) { return t('Vehicle_Lead_Status_' + k); }
function statusBadge(k) { return { new: 'warning', contacted: 'processing', booked: 'success', won: 'success', lost: 'default' }[k] || 'default'; }
function formatDate(d) { return d ? new Date(d.replace(' ', 'T')).toLocaleString() : ''; }
function digits(v) { return String(v || '').replace(/\D+/g, ''); }

function details(r) {
  const parts = [];
  if (r.preferred_date) parts.push(`${t('Preferred_Date')}: ${r.preferred_date}${r.preferred_time ? ' (' + t('Vehicle_Time_' + r.preferred_time) + ')' : ''}`);
  if (r.trade_plate) parts.push(`${t('Trade_In')}: ${r.trade_plate}${r.trade_mileage != null ? ' · ' + Number(r.trade_mileage).toLocaleString() : ''}${r.trade_vehicle ? ' · ' + r.trade_vehicle : ''}`);
  else if (r.has_trade_in) parts.push(t('Has_Trade_In'));
  if (r.wants_finance) parts.push(`${t('Wants_Finance')}${r.finance_term ? ' · ' + r.finance_term + ' ' + t('months') : ''}${r.finance_deposit != null ? ' · ' + t('Deposit') + ' ' + Number(r.finance_deposit).toLocaleString() : ''}`);
  if (r.message) parts.push(r.message.length > 80 ? r.message.slice(0, 80) + '…' : r.message);
  if (r.staff_note) parts.push(t('Staff_Note') + ': ' + (r.staff_note.length > 60 ? r.staff_note.slice(0, 60) + '…' : r.staff_note));
  return parts.join('\n');
}

async function fetch() {
  isLoading.value = true;
  try {
    const r = await http.get('store/vehicle-leads', {
      search: search.value, type: typeFilter.value, status: statusFilter.value, page: page.value, per_page: perPage.value,
    });
    rows.value = r.data || [];
    total.value = (r.meta && r.meta.total) || 0;
    counts.value = { types: (r.counts && r.counts.types) || {}, statuses: (r.counts && r.counts.statuses) || {} };
    users.value = r.users || [];
    crm.value = r.crm === true;
    ready.value = r.ready !== false;
  } catch (e) {
    message.error(t('Failed'));
  } finally {
    isLoading.value = false;
  }
}
/** A filter changed: start again from the first page. */
function refetch() {
  page.value = 1;
  fetch();
}
function onSearchChange() {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(() => { page.value = 1; fetch(); }, 350);
}
function onTableChange(pag) {
  page.value = pag.current;
  perPage.value = pag.pageSize;
  fetch();
}
async function update(r, patch) {
  try {
    await http.post(`store/vehicle-leads/${r.id}`, patch);
    Object.assign(r, patch);
    message.success(t('Successfully_Updated'));
    if ('status' in patch) fetch();
  } catch (e) {
    message.error(t('Failed'));
  }
}
function openNote(r) {
  noteRow.value = r;
  noteText.value = r.staff_note || '';
  noteOpen.value = true;
}
async function saveNote() {
  if (!noteRow.value) return;
  await update(noteRow.value, { staff_note: noteText.value });
  noteOpen.value = false;
}
async function toCrm(r) {
  try {
    const res = await http.post(`store/vehicle-leads/${r.id}/crm`);
    if (res && res.success) message.success(t('Sent_To_CRM'));
    else message.error((res && res.message) || t('Failed'));
  } catch (e) {
    message.error(t('Failed'));
  }
}
function confirmDelete(r) {
  Modal.confirm({
    title: t('AreYouSure'),
    okText: t('Yes'),
    okType: 'danger',
    cancelText: t('No'),
    async onOk() {
      try {
        await http.delete(`store/vehicle-leads/${r.id}`);
        message.success(t('Deleted_in_successfully'));
        fetch();
      } catch (e) {
        message.error(t('Failed'));
      }
    },
  });
}

onMounted(fetch);
</script>

<style scoped>
.tabs {
  padding: 16px 16px 0;
  overflow-x: auto;
}
.toolbar {
  display: flex;
  gap: 12px;
  flex-wrap: wrap;
  padding: 16px;
  border-bottom: 1px solid rgba(5, 5, 5, 0.06);
}
.sub {
  font-size: 12px;
  color: rgba(0, 0, 0, 0.55);
}
.details {
  white-space: pre-line;
  max-width: 320px;
  font-size: 12px;
  line-height: 1.5;
}
</style>
