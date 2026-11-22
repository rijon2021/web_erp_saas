<template>
  <div class="page">
    <PageHeader
      :title="asset.name || $t('Asset')"
      :subtitle="asset.tag ? `${asset.tag}${asset.serial_number ? ' · ' + $t('Asset_Serial_Short', { sn: asset.serial_number }) : ''}` : ''"
      :breadcrumb="[$t('Asset_Management'), $t('Assets'), asset.tag || '']"
    >
      <template #actions>
        <a-button @click="$router.push('/assets')">
          <template #icon><ArrowLeftOutlined /></template>
          {{ $t('Back') }}
        </a-button>
        <a-button @click="$router.push(`/assets/${id}/edit`)">
          <template #icon><EditOutlined /></template>
          {{ $t('Edit') }}
        </a-button>
        <a-button v-if="!asset.disposal_date" danger @click="openDispose">
          <template #icon><ExportOutlined /></template>
          {{ $t('Asset_Dispose') }}
        </a-button>
      </template>
    </PageHeader>

    <a-spin :spinning="loading">
      <a-alert
        v-if="asset.disposal_date" type="warning" show-icon banner style="margin-bottom: 16px"
        :message="$t('Asset_Disposed_On_For', { date: asset.disposal_date, amount: money(asset.disposal_amount || 0) })"
        :description="disposalSummary"
      />

      <!-- headline figures -->
      <div class="kpis">
        <div class="kpi">
          <span class="kpi-label">{{ $t('Status') }}</span>
          <span class="kpi-value">
            <a-tag :color="optionOf(ASSET_STATUSES, asset.status).color">
              {{ labelOf(ASSET_STATUSES, asset.status) }}
            </a-tag>
          </span>
        </div>
        <div class="kpi">
          <span class="kpi-label">{{ $t('Purchase_cost') }}</span>
          <span class="kpi-value">{{ money(asset.purchase_cost || 0) }}</span>
          <span class="kpi-sub">{{ asset.purchase_date ? date(asset.purchase_date) : $t('Crm_No_Date') }}</span>
        </div>
        <div class="kpi kpi--accent">
          <span class="kpi-label">{{ $t('Asset_Book_Value_Today') }}</span>
          <span class="kpi-value">{{ money(asset.book_value || 0) }}</span>
          <span class="kpi-sub">{{ $t('Asset_Amount_Depreciated', { amount: money(asset.accumulated_depreciation || 0) }) }}</span>
        </div>
        <div class="kpi">
          <span class="kpi-label">{{ $t('Asset_Cost_Of_Ownership') }}</span>
          <span class="kpi-value">{{ money(asset.total_cost_of_ownership || 0) }}</span>
          <span class="kpi-sub">{{ $t('Asset_Incl_Upkeep', { amount: money(asset.maintenance_cost || 0) }) }}</span>
        </div>
        <div class="kpi">
          <span class="kpi-label">{{ $t('Asset_Held_By') }}</span>
          <span class="kpi-value kpi-value--sm">{{ asset.holder_name || $t('Asset_On_The_Shelf') }}</span>
          <span class="kpi-sub">{{ asset.warehouse_name || $t('No_Warehouse') }}</span>
        </div>
        <div class="kpi">
          <span class="kpi-label">{{ $t('Next_Validation') }}</span>
          <span class="kpi-value kpi-value--sm">
            <a-tag v-if="asset.next_validation" :color="dueColor(asset.days_to_validation)">
              {{ dueLabel(asset.days_to_validation) }}
            </a-tag>
            <span v-else class="muted">{{ $t('Asset_Not_Set') }}</span>
          </span>
          <span class="kpi-sub">{{ asset.next_validation ? date(asset.next_validation) : '—' }}</span>
        </div>
      </div>

      <a-card size="small">
        <a-tabs v-model:activeKey="tab">
          <!-- ---------------------------------------------- overview -->
          <a-tab-pane key="overview" :tab="$t('Overview')">
            <a-descriptions bordered size="small" :column="{ xs: 1, sm: 2, xl: 3 }">
              <a-descriptions-item :label="$t('Tag')">{{ asset.tag || '—' }}</a-descriptions-item>
              <a-descriptions-item :label="$t('Rental_Serial_Number')">{{ asset.serial_number || '—' }}</a-descriptions-item>
              <a-descriptions-item :label="$t('Category')">{{ asset.category_name || '—' }}</a-descriptions-item>
              <a-descriptions-item :label="$t('Warehouse')">{{ asset.warehouse_name || '—' }}</a-descriptions-item>
              <a-descriptions-item :label="$t('Supplier')">{{ asset.supplier || '—' }}</a-descriptions-item>
              <a-descriptions-item :label="$t('Warranty')">
                <a-tag v-if="asset.warranty_expiry" :color="asset.under_warranty ? 'success' : 'default'">
                  {{ asset.under_warranty
                    ? $t('Asset_Warranty_Until', { date: date(asset.warranty_expiry) })
                    : $t('Asset_Warranty_Expired', { date: date(asset.warranty_expiry) }) }}
                </a-tag>
                <span v-else class="muted">—</span>
              </a-descriptions-item>
              <a-descriptions-item :label="$t('Asset_Last_Verified')">
                {{ asset.last_verification ? date(asset.last_verification) : '—' }}
              </a-descriptions-item>
              <a-descriptions-item :label="$t('Depreciation')">
                {{ labelOf(DEPRECIATION_METHODS, asset.depreciation_method || 'none') }}
              </a-descriptions-item>
              <a-descriptions-item :label="$t('Asset_Useful_Life')">
                {{ asset.useful_life_months ? $t('Asset_N_Months', { n: asset.useful_life_months }) : '—' }}
              </a-descriptions-item>
              <a-descriptions-item :label="$t('Description')" :span="3">
                {{ asset.description || '—' }}
              </a-descriptions-item>
            </a-descriptions>
          </a-tab-pane>

          <!-- ---------------------------------------------- custody -->
          <a-tab-pane key="assignments">
            <template #tab>
              <span>{{ $t('Custody') }} <a-badge :count="assignments.length" :number-style="badgeStyle" /></span>
            </template>
            <div class="tab-actions">
              <a-button size="small" type="primary" @click="$router.push(`/assets/assignments?asset_id=${id}`)">
                {{ $t('Asset_Manage_Assignments') }}
              </a-button>
            </div>
            <a-table
              size="small" :columns="assignmentColumns" :data-source="assignments"
              row-key="id" :pagination="false" :scroll="{ x: 800 }"
            >
              <template #bodyCell="{ column, record }">
                <template v-if="column.key === 'status'">
                  <a-tag :color="record.is_overdue ? 'error' : optionOf(ASSIGNMENT_STATUSES, record.status).color">
                    {{ record.is_overdue ? $t('Overdue') : labelOf(ASSIGNMENT_STATUSES, record.status) }}
                  </a-tag>
                </template>
                <template v-else-if="column.key === 'days_held'">{{ $t('Asset_N_Days_Short', { n: record.days_held }) }}</template>
                <template v-else-if="column.dataIndex && column.dataIndex.endsWith('_on')">
                  {{ record[column.dataIndex] ? date(record[column.dataIndex]) : '—' }}
                </template>
              </template>
            </a-table>
          </a-tab-pane>

          <!-- ---------------------------------------------- maintenance -->
          <a-tab-pane key="maintenance">
            <template #tab>
              <span>{{ $t('Maintenance') }} <a-badge :count="maintenance.length" :number-style="badgeStyle" /></span>
            </template>
            <div class="tab-actions">
              <a-button size="small" type="primary" @click="$router.push(`/assets/maintenance?asset_id=${id}`)">
                {{ $t('Asset_Manage_Jobs') }}
              </a-button>
              <span class="tab-total">{{ $t('Asset_Spent_To_Date') }}: <strong>{{ money(asset.maintenance_cost || 0) }}</strong></span>
            </div>
            <a-table
              size="small" :columns="maintenanceColumns" :data-source="maintenance"
              row-key="id" :pagination="false" :scroll="{ x: 900 }"
            >
              <template #bodyCell="{ column, record }">
                <template v-if="column.key === 'type'">
                  <a-tag :color="optionOf(MAINTENANCE_TYPES, record.type).color">
                    {{ labelOf(MAINTENANCE_TYPES, record.type) }}
                  </a-tag>
                </template>
                <template v-else-if="column.key === 'status'">
                  <a-tag :color="optionOf(MAINTENANCE_STATUSES, record.status).color">
                    {{ labelOf(MAINTENANCE_STATUSES, record.status) }}
                  </a-tag>
                </template>
                <template v-else-if="column.key === 'cost'">{{ money(record.cost) }}</template>
                <template v-else-if="column.key === 'scheduled_date'">
                  <a-tag :color="record.is_overdue ? 'error' : 'default'">{{ date(record.scheduled_date) }}</a-tag>
                </template>
                <template v-else-if="column.dataIndex && column.dataIndex.endsWith('_date')">
                  {{ record[column.dataIndex] ? date(record[column.dataIndex]) : '—' }}
                </template>
              </template>
            </a-table>
          </a-tab-pane>

          <!-- ---------------------------------------------- movement -->
          <a-tab-pane key="transfers">
            <template #tab>
              <span>{{ $t('Asset_Movement') }} <a-badge :count="transfers.length" :number-style="badgeStyle" /></span>
            </template>
            <div class="tab-actions">
              <a-button size="small" type="primary" @click="$router.push(`/assets/transfers?asset_id=${id}`)">
                {{ $t('Asset_Manage_Transfers') }}
              </a-button>
            </div>
            <a-empty v-if="!transfers.length" :image="simpleImage" :description="$t('Asset_Not_Moved')" />
            <a-timeline v-else class="timeline">
              <a-timeline-item v-for="t in transfers" :key="t.id" color="purple">
                <div class="tl-head">
                  {{ t.from_warehouse_name || $t('Unassigned') }}
                  <ArrowRightOutlined class="arrow" />
                  <strong>{{ t.to_warehouse_name }}</strong>
                </div>
                <div class="tl-sub">
                  {{ date(t.transfer_date) }}<span v-if="t.reason"> · {{ t.reason }}</span>
                </div>
                <div v-if="t.notes" class="tl-note">{{ t.notes }}</div>
              </a-timeline-item>
            </a-timeline>
          </a-tab-pane>

          <!-- ---------------------------------------------- depreciation -->
          <a-tab-pane key="depreciation" :tab="$t('Depreciation')">
            <a-empty
              v-if="!schedule.length" :image="simpleImage"
              :description="$t('Asset_No_Depreciation_Method')"
            />
            <template v-else>
              <a-table
                size="small" :columns="scheduleColumns" :data-source="schedule"
                row-key="year" :pagination="false"
              >
                <template #bodyCell="{ column, record }">
                  <template v-if="['opening', 'depreciation', 'accumulated', 'closing'].includes(column.key)">
                    <span :class="column.key === 'depreciation' ? 'down' : ''">
                      {{ column.key === 'depreciation' ? '−' : '' }}{{ money(record[column.key]) }}
                    </span>
                  </template>
                </template>
              </a-table>
              <ReportChart
                :data="schedule"
                :fields="[{ key: 'closing', label: $t('Book_Value'), money: true }]"
                :title="$t('Asset_Book_Value_Over_Life')"
                type="area"
                x-key="year"
                :height="240"
                :format="money"
                style="margin-top: 16px"
              />
            </template>
          </a-tab-pane>
        </a-tabs>
      </a-card>
    </a-spin>

    <!-- dispose -->
    <a-modal
      :open="disposeOpen" :title="$t('Asset_Dispose_Title')" :width="500"
      :confirm-loading="disposing" :ok-text="$t('Asset_Dispose')" :cancel-text="$t('Cancel')"
      ok-type="danger" @ok="submitDispose" @cancel="disposeOpen = false"
    >
      <a-alert
        type="warning" show-icon banner style="margin-bottom: 14px"
        :message="$t('Asset_Disposal_Permanent_Info')"
      />
      <a-form layout="vertical">
        <a-form-item :label="$t('Asset_Disposal_Date') + ' *'">
          <a-date-picker v-model:value="disposeForm.disposal_date" style="width: 100%" value-format="YYYY-MM-DD" />
        </a-form-item>
        <a-form-item :label="$t('Asset_Amount_Received')" :extra="$t('Asset_Scrapped_Hint')">
          <a-input-number v-model:value="disposeForm.disposal_amount" :min="0" style="width: 100%" />
        </a-form-item>
        <a-form-item v-if="Number(disposeForm.disposal_amount) > 0" :label="$t('Asset_Received_Into')" :extra="$t('Asset_Received_Into_Hint')">
          <a-select
            v-model:value="disposeForm.disposal_account_id" :options="accountOptions" :placeholder="$t('Choose_Account')"
            allow-clear show-search option-filter-prop="label"
          />
        </a-form-item>
        <a-form-item :label="$t('Note')" style="margin-bottom: 0">
          <a-input v-model:value="disposeForm.disposal_note" :placeholder="$t('Asset_Disposal_Note_Placeholder')" allow-clear />
        </a-form-item>
      </a-form>
      <p class="dispose-hint">
        {{ $t('Asset_Dispose_Hint', { amount: money(asset.book_value || 0) }) }}
      </p>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * Everything about one asset on one page: what it is, what it is worth, who has
 * had it, what has been done to it and where it has been.
 *
 * The tabs each link out to the module page filtered to this asset rather than
 * duplicating the editing UI — one place to change a thing, several places to
 * see it.
 */
import { ref, computed, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import { message, Empty } from 'ant-design-vue';
import {
  ArrowLeftOutlined, EditOutlined, ExportOutlined, ArrowRightOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import ReportChart from '../../components/ReportChart.vue';
import { useFormat } from '../../composables/useFormat';
import {
  ASSET_STATUSES, ASSIGNMENT_STATUSES, MAINTENANCE_TYPES, MAINTENANCE_STATUSES,
  DEPRECIATION_METHODS, labelOf, optionOf, dueColor, dueLabel, tp,
} from './assetOptions';
import http from '../../lib/http';
import { t } from '../../i18n';

const route = useRoute();
const { money, date } = useFormat();
const simpleImage = Empty.PRESENTED_IMAGE_SIMPLE;
const badgeStyle = { backgroundColor: 'rgba(128,128,128,0.25)', color: 'inherit', boxShadow: 'none' };

const id = route.params.id;
const loading = ref(false);
const tab = ref('overview');

const asset = ref({});
const schedule = ref([]);
const maintenance = ref([]);
const assignments = ref([]);
const transfers = ref([]);

const disposalSummary = computed(() => {
  const gain = asset.value.disposal_gain;
  if (gain === null || gain === undefined) return '';
  return gain >= 0
    ? tp('Asset_Sold_Above_Book', 'Sold {amount} above its book value.', { amount: money(gain) })
    : tp('Asset_Sold_Below_Book', 'Sold {amount} below its book value.', { amount: money(Math.abs(gain)) });
});

const assignmentColumns = computed(() => [
  { title: t('Asset_Holder', 'Holder'), dataIndex: 'user_name', key: 'user_name' },
  { title: t('Asset_Assigned', 'Assigned'), dataIndex: 'assigned_on', key: 'assigned_on', width: 120 },
  { title: t('Asset_Due_Back', 'Due back'), dataIndex: 'due_back_on', key: 'due_back_on', width: 120 },
  { title: t('Returned', 'Returned'), dataIndex: 'returned_on', key: 'returned_on', width: 120 },
  { title: t('Asset_Days_Held', 'Days held'), key: 'days_held', dataIndex: 'days_held', width: 80, align: 'right' },
  { title: t('Out', 'Out'), dataIndex: 'condition_out', key: 'condition_out' },
  { title: t('In', 'In'), dataIndex: 'condition_in', key: 'condition_in' },
  { title: t('Status', 'Status'), key: 'status', dataIndex: 'status', width: 110 },
]);

const maintenanceColumns = computed(() => [
  { title: t('Scheduled', 'Scheduled'), key: 'scheduled_date', dataIndex: 'scheduled_date', width: 125 },
  { title: t('Rental_Work', 'Work'), dataIndex: 'title', key: 'title' },
  { title: t('Type', 'Type'), key: 'type', dataIndex: 'type', width: 115 },
  { title: t('Rental_Vendor', 'Vendor'), dataIndex: 'vendor', key: 'vendor', width: 130 },
  { title: t('Completed', 'Completed'), key: 'completed_date', dataIndex: 'completed_date', width: 115 },
  { title: t('Rental_Next_Due', 'Next due'), key: 'next_due_date', dataIndex: 'next_due_date', width: 115 },
  { title: t('Cost', 'Cost'), key: 'cost', dataIndex: 'cost', width: 110, align: 'right' },
  { title: t('Status', 'Status'), key: 'status', dataIndex: 'status', width: 120 },
]);

const scheduleColumns = computed(() => [
  { title: t('Year', 'Year'), dataIndex: 'year', key: 'year', width: 70 },
  { title: t('Period', 'Period'), dataIndex: 'period', key: 'period' },
  { title: t('Opening', 'Opening'), key: 'opening', dataIndex: 'opening', width: 130, align: 'right' },
  { title: t('Rental_Charge', 'Charge'), key: 'depreciation', dataIndex: 'depreciation', width: 130, align: 'right' },
  { title: t('Asset_Accumulated', 'Accumulated'), key: 'accumulated', dataIndex: 'accumulated', width: 140, align: 'right' },
  { title: t('Closing', 'Closing'), key: 'closing', dataIndex: 'closing', width: 130, align: 'right' },
]);

// ---------------- dispose ----------------

const disposeOpen = ref(false);
const disposing = ref(false);
const disposeForm = ref({
  disposal_date: new Date().toISOString().slice(0, 10),
  disposal_amount: 0,
  disposal_note: '',
  disposal_account_id: undefined,
});

const accounts = ref([]);
const accountOptions = computed(() => accounts.value.map(a => ({ value: a.id, label: a.account_name })));

async function openDispose() {
  disposeOpen.value = true;
  if (accounts.value.length) return;
  try {
    const meta = await http.get('assets/workspace/meta');
    accounts.value = meta.accounts || [];
  } catch (e) { /* the picker just stays empty: proceeds then go to Cash */ }
}

async function submitDispose() {
  if (!disposeForm.value.disposal_date) {
    message.error(t('Asset_Pick_Disposal_Date', 'Pick a disposal date'));
    return;
  }
  disposing.value = true;
  try {
    const res = await http.post(`assets/workspace/dispose/${id}`, disposeForm.value);
    message.success(
      res?.gain >= 0
        ? tp('Asset_Disposed_Gain', 'Disposed — {amount} gain', { amount: money(res.gain) })
        : tp('Asset_Disposed_Loss', 'Disposed — {amount} loss', { amount: money(Math.abs(res?.gain || 0)) }),
    );
    disposeOpen.value = false;
    load();
  } catch (e) {
    message.error(e?.data?.message || t('Asset_Could_Not_Dispose', 'Could not dispose of this asset'));
  } finally {
    disposing.value = false;
  }
}

async function load() {
  loading.value = true;
  try {
    const data = await http.get(`assets/workspace/details/${id}`);
    asset.value = data?.asset || {};
    schedule.value = data?.schedule || [];
    maintenance.value = data?.maintenance || [];
    assignments.value = data?.assignments || [];
    transfers.value = data?.transfers || [];
  } catch (e) {
    message.error(t('Asset_Could_Not_Load', 'Could not load this asset'));
  } finally {
    loading.value = false;
  }
}

onMounted(load);
</script>

<style scoped>
.muted {
  color: rgba(128, 128, 128, 0.7);
}
.down {
  color: #dc2626;
}
.kpis {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  gap: 12px;
  margin-bottom: 16px;
}
.kpi {
  display: flex;
  flex-direction: column;
  gap: 2px;
  padding: 13px 16px;
  border: 1px solid rgba(128, 128, 128, 0.2);
  border-radius: 14px;
}
.kpi--accent {
  border-color: rgba(var(--app-primary-rgb, 109, 40, 217), 0.45);
  background: rgba(var(--app-primary-rgb, 109, 40, 217), 0.05);
}
.kpi-label {
  font-size: 12.5px;
  opacity: 0.65;
}
.kpi-value {
  font-size: 19px;
  font-weight: 600;
  line-height: 1.35;
}
.kpi-value--sm {
  font-size: 15px;
}
.kpi-sub {
  font-size: 11.5px;
  opacity: 0.55;
}
.tab-actions {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 12px;
}
.tab-total {
  margin-inline-start: auto;
  font-size: 12.5px;
  opacity: 0.75;
}
.timeline {
  padding-top: 6px;
}
.tl-head {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}
.arrow {
  font-size: 11px;
  opacity: 0.45;
}
.tl-sub {
  font-size: 12px;
  opacity: 0.6;
}
.tl-note {
  font-size: 12px;
  opacity: 0.75;
  margin-top: 2px;
}
.dispose-hint {
  margin: 14px 0 0;
  font-size: 12.5px;
  opacity: 0.7;
}
</style>
