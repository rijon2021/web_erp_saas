<template>
  <div class="page">
    <PageHeader
      :title="order.reference || $t('Mrp_Production_Order')"
      :subtitle="order.product_name ? `${order.product_name} · ${$t('Mrp_N_Planned', { n: number(order.qty_planned, 0) })}` : ''"
      :breadcrumb="[$t('Manufacturing'), $t('Mrp_Production_Orders'), order.reference || '']"
    >
      <template #actions>
        <a-button @click="$router.push('/mrp/production-orders')">
          <template #icon><ArrowLeftOutlined /></template>
          {{ $t('Back') }}
        </a-button>
        <a-button v-if="canEdit(order)" @click="$router.push(`/mrp/production-orders/${id}/edit`)">
          <template #icon><EditOutlined /></template>
          {{ $t('Edit') }}
        </a-button>
        <a-button v-if="canRelease(order)" type="primary" :loading="busy" @click="release()">
          <template #icon><PlayCircleOutlined /></template>
          {{ $t('Mrp_Release') }}
        </a-button>
        <a-button v-if="canComplete(order)" type="primary" @click="openComplete">
          <template #icon><CheckOutlined /></template>
          {{ $t('Mrp_Complete') }}
        </a-button>
        <a-button v-if="order.status === 'completed'" @click="printLabels">
          <template #icon><BarcodeOutlined /></template>
          {{ $t('Cr_Print_Labels') }}
        </a-button>
        <a-button v-if="canCancel(order)" danger @click="confirmCancel">
          <template #icon><StopOutlined /></template>
          {{ $t('Cancel') }}
        </a-button>
      </template>
    </PageHeader>

    <a-spin :spinning="loading">
      <!-- lifecycle -->
      <a-card size="small" style="margin-bottom: 16px">
        <a-steps
          v-if="order.status !== 'cancelled'"
          :current="currentStep" size="small" :status="stepStatus"
          :items="stepItems"
        />
        <a-alert
          v-else type="error" show-icon banner
          :message="$t('Mrp_Order_Cancelled_Note')"
        />
      </a-card>

      <a-alert
        v-if="shortages.length && canRelease(order)"
        type="warning" show-icon style="margin-bottom: 16px"
        :message="`${shortages.length} ${$t('Mrp_Components_Not_In_Stock')}`"
        :description="$t('Mrp_Release_Blocked_Note')"
      />

      <div class="kpis">
        <div class="kpi">
          <span class="kpi-label">{{ $t('Status') }}</span>
          <span class="kpi-value">
            <a-tag :color="optionOf(ORDER_STATUSES, order.status).color">
              {{ labelOf(ORDER_STATUSES, order.status) }}
            </a-tag>
            <a-tag v-if="order.priority && order.priority !== 'normal'" :color="optionOf(PRIORITIES, order.priority).color">
              {{ labelOf(PRIORITIES, order.priority) }}
            </a-tag>
          </span>
          <span class="kpi-sub">{{ order.warehouse_name || '' }}</span>
        </div>
        <div class="kpi">
          <span class="kpi-label">{{ $t('Mrp_Output') }}</span>
          <span class="kpi-value">{{ number(order.qty_produced, 0) }}<span class="kpi-of">/{{ number(order.qty_planned, 0) }}</span></span>
          <span class="kpi-sub">
            <template v-if="order.qty_scrapped">{{ number(order.qty_scrapped, 0) }} {{ $t('scrapped') }}</template>
            <template v-else-if="order.yield_pct !== null">{{ order.yield_pct }}% {{ $t('Mrp_Yield_Lc') }}</template>
          </span>
        </div>
        <div class="kpi">
          <span class="kpi-label">{{ $t('Batch') }}</span>
          <span class="kpi-value kpi-value--sm">{{ order.batch_no || '—' }}</span>
          <span class="kpi-sub">
            <template v-if="order.expiry_date">{{ $t('Mrp_Expires_On', { date: order.expiry_date }) }}</template>
            <template v-else-if="order.is_batch_tracked">{{ $t('Mrp_Batch_Tracked_Product') }}</template>
            <template v-else>{{ $t('Mrp_No_Batch_Set') }}</template>
          </span>
        </div>
        <div class="kpi kpi--accent">
          <span class="kpi-label">{{ $t('Mrp_Actual_Cost') }}</span>
          <span class="kpi-value">{{ money(order.total_cost || 0) }}</span>
          <span class="kpi-sub">
            <template v-if="order.unit_cost">{{ $t('Mrp_Amount_Per_Unit', { amount: money(order.unit_cost) }) }}</template>
            <template v-else>{{ $t('Mrp_Not_Yet_Costed') }}</template>
          </span>
        </div>
        <div class="kpi">
          <span class="kpi-label">{{ $t('Mrp_Against_Plan') }}</span>
          <span class="kpi-value kpi-value--sm">
            <a-tag v-if="order.cost_variance_pct !== null" :color="varianceColor(order.cost_variance_pct)">
              {{ signedPct(order.cost_variance_pct) }}
            </a-tag>
            <span v-else class="muted">—</span>
          </span>
          <span class="kpi-sub">{{ $t('Mrp_Planned_Amount', { amount: money(order.planned_cost || 0) }) }}</span>
        </div>
        <div class="kpi">
          <span class="kpi-label">{{ $t('Mrp_Shop_Floor_Time') }}</span>
          <span class="kpi-value kpi-value--sm">{{ hours(order.actual_minutes) }}</span>
          <span class="kpi-sub">{{ $t('Mrp_Of') }} {{ hours(order.planned_minutes) }} {{ $t('Mrp_Allowed') }}</span>
        </div>
      </div>

      <a-card size="small">
        <a-tabs v-model:activeKey="tab">
          <!-- ------------------------------------------- materials -->
          <a-tab-pane key="materials">
            <template #tab>
              <span>{{ $t('Mrp_Materials') }} <a-badge :count="materials.length" :number-style="badgeStyle" /></span>
            </template>
            <a-table
              size="small" :columns="materialColumns" :data-source="materials"
              row-key="id" :pagination="false" :scroll="{ x: 900 }"
            >
              <template #bodyCell="{ column, record }">
                <template v-if="column.key === 'product'">
                  {{ record.product_name }}
                  <div class="sub">{{ record.product_code }}</div>
                </template>
                <template v-else-if="column.key === 'on_hand'">
                  <span :class="record.on_hand < record.qty_required - record.qty_consumed ? 'bad' : ''">
                    {{ number(record.on_hand, 2) }}
                  </span>
                </template>
                <template v-else-if="column.key === 'batches'">
                  <template v-if="record.batches?.length">
                    <a-tag v-for="b in record.batches" :key="b.id" style="margin: 1px 4px 1px 0">
                      {{ b.batch_no }} × {{ number(b.qty, 2) }}
                    </a-tag>
                  </template>
                  <span v-else-if="record.is_batch_tracked" class="muted">{{ $t('Mrp_Fefo_On_Release') }}</span>
                  <span v-else class="muted">—</span>
                </template>
                <template v-else-if="column.key === 'shortfall'">
                  <a-tag v-if="record.shortfall > 0" color="warning">{{ number(record.shortfall, 2) }}</a-tag>
                  <span v-else class="muted">—</span>
                </template>
                <template v-else-if="['unit_cost', 'total_cost'].includes(column.key)">
                  {{ money(record[column.key]) }}
                </template>
                <template v-else-if="column.key === 'is_optional'">
                  <a-tag v-if="record.is_optional">{{ $t('Mrp_Optional') }}</a-tag>
                </template>
              </template>
              <template #summary>
                <a-table-summary-row>
                  <a-table-summary-cell :col-span="7" />
                  <a-table-summary-cell align="right"><strong>{{ $t('Mrp_Material_Cost') }}</strong></a-table-summary-cell>
                  <a-table-summary-cell align="right">
                    <strong>{{ money(order.material_cost || 0) }}</strong>
                  </a-table-summary-cell>
                  <a-table-summary-cell />
                </a-table-summary-row>
              </template>
            </a-table>
          </a-tab-pane>

          <!-- ------------------------------------------- routing -->
          <a-tab-pane key="routing">
            <template #tab>
              <span>{{ $t('Mrp_Routing') }} <a-badge :count="workOrders.length" :number-style="badgeStyle" /></span>
            </template>
            <a-table
              size="small" :columns="workOrderColumns" :data-source="workOrders"
              row-key="id" :pagination="false" :scroll="{ x: 1000 }"
            >
              <template #bodyCell="{ column, record }">
                <template v-if="column.key === 'status'">
                  <a-tag :color="optionOf(WORK_ORDER_STATUSES, record.status).color">
                    {{ labelOf(WORK_ORDER_STATUSES, record.status) }}
                  </a-tag>
                </template>
                <template v-else-if="column.key === 'time'">
                  {{ number(record.actual_minutes, 0) }} / {{ number(record.planned_minutes, 0) }} min
                  <a-tag
                    v-if="record.time_variance_pct !== null" class="mini"
                    :color="varianceColor(record.time_variance_pct)"
                  >
                    {{ signedPct(record.time_variance_pct) }}
                  </a-tag>
                </template>
                <template v-else-if="column.key === 'labour_cost'">{{ money(record.labour_cost) }}</template>
                <template v-else-if="column.key === 'actions'">
                  <a-space :size="0">
                    <a-button
                      v-if="record.status === 'pending'" type="link" size="small"
                      :disabled="!canWork" @click="startStep(record)"
                    >
                      {{ $t('Mrp_Start') }}
                    </a-button>
                    <a-button
                      v-else-if="record.status === 'in_progress'" type="link" size="small"
                      @click="openFinish(record)"
                    >
                      {{ $t('Mrp_Finish') }}
                    </a-button>
                  </a-space>
                </template>
              </template>
            </a-table>
          </a-tab-pane>

          <!-- ------------------------------------------- quality -->
          <a-tab-pane key="quality">
            <template #tab>
              <span>{{ $t('Mrp_Quality') }} <a-badge :count="checks.length" :number-style="badgeStyle" /></span>
            </template>
            <div class="tab-actions">
              <a-button size="small" type="primary" @click="openQc">
                <template #icon><PlusOutlined /></template>
                {{ $t('Mrp_Record_Inspection') }}
              </a-button>
              <span v-if="order.qc_required" class="tab-note">
                {{ $t('Mrp_Final_Inspection_Required') }}
              </span>
            </div>
            <a-empty v-if="!checks.length" :image="simpleImage" :description="$t('Mrp_No_Inspections_Yet')" />
            <a-collapse v-else ghost>
              <a-collapse-panel v-for="c in checks" :key="c.id">
                <template #header>
                  <span class="qc-head">
                    <a-tag :color="optionOf(QC_STATUSES, c.status).color">{{ labelOf(QC_STATUSES, c.status) }}</a-tag>
                    <strong>{{ c.reference }}</strong>
                    <span class="sub">{{ labelOf(QC_TYPES, c.type) }}</span>
                    <span class="sub">
                      {{ number(c.qty_passed, 0) }}/{{ number(c.qty_inspected, 0) }} {{ $t('Mrp_Passed_Lc') }}
                      <template v-if="c.pass_rate !== null">({{ c.pass_rate }}%)</template>
                    </span>
                  </span>
                </template>
                <a-table
                  v-if="c.lines?.length" size="small" :columns="qcLineColumns"
                  :data-source="c.lines" row-key="id" :pagination="false"
                >
                  <template #bodyCell="{ column, record }">
                    <template v-if="column.key === 'result'">
                      <a-tag :color="record.result === 'fail' ? 'error' : 'success'">
                        {{ record.result === 'fail' ? $t('Mrp_Fail') : $t('Mrp_Pass') }}
                      </a-tag>
                    </template>
                  </template>
                </a-table>
                <p v-if="c.notes" class="qc-notes">{{ c.notes }}</p>
              </a-collapse-panel>
            </a-collapse>
          </a-tab-pane>

          <!-- ------------------------------------------- costing -->
          <a-tab-pane key="costing" :tab="$t('Mrp_Costing')">
            <a-row :gutter="16">
              <a-col :xs="24" :md="12">
                <a-descriptions bordered size="small" :column="1" :title="$t('Mrp_Cost_Build_Up')">
                  <a-descriptions-item :label="$t('Mrp_Material')">{{ money(order.material_cost || 0) }}</a-descriptions-item>
                  <a-descriptions-item :label="$t('Labour')">{{ money(order.labour_cost || 0) }}</a-descriptions-item>
                  <a-descriptions-item :label="$t('Mrp_Overhead')">{{ money(order.overhead_cost || 0) }}</a-descriptions-item>
                  <a-descriptions-item :label="$t('Mrp_Total')"><strong>{{ money(order.total_cost || 0) }}</strong></a-descriptions-item>
                  <a-descriptions-item :label="$t('Mrp_Planned')">{{ money(order.planned_cost || 0) }}</a-descriptions-item>
                  <a-descriptions-item :label="$t('Mrp_Variance')">
                    <a-tag v-if="order.cost_variance !== null" :color="varianceColor(order.cost_variance_pct)">
                      {{ money(order.cost_variance) }} ({{ signedPct(order.cost_variance_pct) }})
                    </a-tag>
                    <span v-else class="muted">{{ $t('Mrp_Available_Once_Completed') }}</span>
                  </a-descriptions-item>
                </a-descriptions>
              </a-col>
              <a-col :xs="24" :md="12">
                <a-descriptions bordered size="small" :column="1" :title="$t('Mrp_Output')">
                  <a-descriptions-item :label="$t('Mrp_Planned')">{{ number(order.qty_planned, 2) }}</a-descriptions-item>
                  <a-descriptions-item :label="$t('Mrp_Produced')">{{ number(order.qty_produced, 2) }}</a-descriptions-item>
                  <a-descriptions-item :label="$t('Mrp_Scrapped')">{{ number(order.qty_scrapped, 2) }}</a-descriptions-item>
                  <a-descriptions-item :label="$t('Mrp_Batch_No')">{{ order.batch_no || '—' }}</a-descriptions-item>
                  <a-descriptions-item :label="$t('Mrp_Production_Date')">{{ order.production_date || '—' }}</a-descriptions-item>
                  <a-descriptions-item :label="$t('Mrp_Expiry_Date')">{{ order.expiry_date || '—' }}</a-descriptions-item>
                  <a-descriptions-item :label="$t('Mrp_Yield')">
                    {{ order.yield_pct !== null ? order.yield_pct + '%' : '—' }}
                  </a-descriptions-item>
                  <a-descriptions-item :label="$t('Mrp_Scrap_Rate')">
                    {{ order.scrap_pct !== null ? order.scrap_pct + '%' : '—' }}
                  </a-descriptions-item>
                  <a-descriptions-item :label="$t('Mrp_Unit_Cost')">
                    <strong>{{ order.unit_cost ? money(order.unit_cost) : '—' }}</strong>
                  </a-descriptions-item>
                </a-descriptions>
                <p class="cost-note">
                  {{ $t('Mrp_Unit_Cost_Note') }}
                </p>
              </a-col>
            </a-row>
          </a-tab-pane>
        </a-tabs>
      </a-card>
    </a-spin>

    <!-- complete -->
    <a-modal
      :open="completeOpen" :title="$t('Mrp_Complete_This_Order')" :width="520"
      :confirm-loading="busy" :ok-text="$t('Mrp_Complete')" :cancel-text="$t('Cancel')"
      @ok="submitComplete" @cancel="completeOpen = false"
    >
      <a-alert
        type="info" show-icon banner style="margin-bottom: 14px"
        :message="$t('Mrp_Complete_Note')"
      />
      <a-form layout="vertical">
        <a-form-item :label="$t('Mrp_Good_Units_Produced') + ' *'">
          <a-input-number v-model:value="completeForm.qty_produced" :min="0" style="width: 100%" />
        </a-form-item>
        <a-form-item :label="$t('Mrp_Scrapped')">
          <a-input-number v-model:value="completeForm.qty_scrapped" :min="0" style="width: 100%" />
        </a-form-item>
        <a-divider style="margin: 4px 0 14px; font-size: 13px">{{ $t('Mrp_Batch_On_Finished_Goods') }}</a-divider>
        <a-form-item
          :label="order.is_batch_tracked ? $t('Mrp_Batch_No') + ' *' : $t('Mrp_Batch_No')"
          :extra="order.is_batch_tracked
            ? $t('Mrp_Batch_Required_Hint')
            : $t('Mrp_Batch_Optional_Hint')"
        >
          <a-input v-model:value="completeForm.batch_no" allow-clear />
        </a-form-item>
        <a-row :gutter="12">
          <a-col :span="12">
            <a-form-item :label="$t('Mrp_Production_Date')" style="margin-bottom: 0">
              <a-date-picker v-model:value="completeForm.production_date" style="width: 100%" value-format="YYYY-MM-DD" />
            </a-form-item>
          </a-col>
          <a-col :span="12">
            <a-form-item
              :label="$t('Mrp_Expiry_Date')" style="margin-bottom: 0"
              :extra="order.shelf_life_days ? $t('Mrp_Expiry_Blank_Hint', { days: order.shelf_life_days }) : ''"
            >
              <a-date-picker v-model:value="completeForm.expiry_date" style="width: 100%" value-format="YYYY-MM-DD" />
            </a-form-item>
          </a-col>
        </a-row>
      </a-form>
      <p v-if="estimatedUnitCost" class="estimate">
        {{ $t('Mrp_Estimated_Unit_Cost_Note') }} <strong>{{ money(estimatedUnitCost) }}</strong>.
      </p>
    </a-modal>

    <!-- finish a routing step -->
    <a-modal
      :open="finishOpen" :title="$t('Mrp_Finish_Step')" :width="480"
      :confirm-loading="busy" :ok-text="$t('Mrp_Finish')" :cancel-text="$t('Cancel')"
      @ok="submitFinish" @cancel="finishOpen = false"
    >
      <a-form layout="vertical">
        <a-form-item :label="$t('Mrp_Minutes_Taken')" :extra="$t('Mrp_Minutes_Taken_Hint')">
          <a-input-number v-model:value="finishForm.actual_minutes" :min="0" style="width: 100%" />
        </a-form-item>
        <a-form-item :label="$t('Operator')">
          <a-select
            v-model:value="finishForm.employee_id" show-search option-filter-prop="label"
            :options="employeeOptions" allow-clear :placeholder="$t('Mrp_Who_Did_The_Work')"
          />
        </a-form-item>
        <a-row :gutter="12">
          <a-col :span="12">
            <a-form-item :label="$t('Mrp_Good')">
              <a-input-number v-model:value="finishForm.qty_completed" :min="0" style="width: 100%" />
            </a-form-item>
          </a-col>
          <a-col :span="12">
            <a-form-item :label="$t('Mrp_Rejected')">
              <a-input-number v-model:value="finishForm.qty_rejected" :min="0" style="width: 100%" />
            </a-form-item>
          </a-col>
        </a-row>
        <a-form-item :label="$t('Note')" style="margin-bottom: 0">
          <a-textarea v-model:value="finishForm.notes" :rows="2" allow-clear />
        </a-form-item>
      </a-form>
    </a-modal>

    <!-- quality check -->
    <QualityCheckModal
      v-model:open="qcOpen"
      :production-order-id="Number(id)"
      :default-qty="order.qty_planned"
      @saved="onQcSaved"
    />
  </div>
</template>

<script setup>
/**
 * One production order end to end: materials, routing, quality and costing.
 *
 * The lifecycle bar at the top is the point of the page — someone opening it
 * mid-shift needs to know where the order is before anything else.
 */
import { ref, reactive, computed, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { message, Modal, Empty } from 'ant-design-vue';
import {
  ArrowLeftOutlined, EditOutlined, PlayCircleOutlined, CheckOutlined,
  StopOutlined, PlusOutlined, BarcodeOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import QualityCheckModal from './QualityCheckModal.vue';
import { useFormat } from '../../composables/useFormat';
import {
  ORDER_STATUSES, PRIORITIES, WORK_ORDER_STATUSES, QC_STATUSES, QC_TYPES,
  labelOf, optionOf, canRelease, canComplete, canCancel, canEdit,
  varianceColor, signedPct,
} from './mrpOptions';
import http from '../../lib/http';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const route = useRoute();
const router = useRouter();
const { money, number } = useFormat();
const simpleImage = Empty.PRESENTED_IMAGE_SIMPLE;
const badgeStyle = { backgroundColor: 'rgba(128,128,128,0.25)', color: 'inherit', boxShadow: 'none' };

const id = route.params.id;
const tab = ref('materials');
const loading = ref(false);
const busy = ref(false);

const order = ref({});
const materials = ref([]);
const workOrders = ref([]);
const checks = ref([]);
const shortages = ref([]);
const employees = ref([]);

const employeeOptions = computed(() => employees.value.map(e => ({ value: e.id, label: e.name })));
const canWork = computed(() => ['released', 'in_progress'].includes(order.value.status));

const stepItems = [
  { title: t('Mrp_Status_Draft') }, { title: t('Mrp_Status_Planned') }, { title: t('Mrp_Status_Released') },
  { title: t('Mrp_Status_In_Progress') }, { title: t('Mrp_Status_Completed') },
];
const currentStep = computed(() => {
  const step = optionOf(ORDER_STATUSES, order.value.status).step;
  return step >= 0 ? step : 0;
});
const stepStatus = computed(() => (order.value.status === 'completed' ? 'finish' : 'process'));

/** Rough unit cost preview, so the number is not a surprise after the fact. */
const estimatedUnitCost = computed(() => {
  const qty = Number(completeForm.qty_produced) || 0;
  if (qty <= 0) return 0;
  const total = Number(order.value.total_cost) || 0;
  return total > 0 ? total / qty : 0;
});

function hours(minutes) {
  const m = Number(minutes) || 0;
  if (m === 0) return '0h';
  return m >= 60 ? `${(m / 60).toFixed(1)}h` : `${Math.round(m)}m`;
}

const materialColumns = [
  { title: t('Mrp_Component'), key: 'product', dataIndex: 'product_name' },
  { title: t('Mrp_Required'), dataIndex: 'qty_required', key: 'qty_required', width: 100, align: 'right' },
  { title: t('Mrp_Issued'), dataIndex: 'qty_issued', key: 'qty_issued', width: 100, align: 'right' },
  { title: t('Mrp_Consumed'), dataIndex: 'qty_consumed', key: 'qty_consumed', width: 100, align: 'right' },
  { title: t('Mrp_Batches_Used'), key: 'batches', width: 200 },
  { title: t('Mrp_On_Hand'), key: 'on_hand', dataIndex: 'on_hand', width: 100, align: 'right' },
  { title: t('Mrp_Short'), key: 'shortfall', dataIndex: 'shortfall', width: 90, align: 'right' },
  { title: t('Mrp_Unit_Cost'), key: 'unit_cost', dataIndex: 'unit_cost', width: 110, align: 'right' },
  { title: t('Mrp_Line_Cost'), key: 'total_cost', dataIndex: 'total_cost', width: 120, align: 'right' },
  { title: '', key: 'is_optional', width: 90 },
];

const workOrderColumns = [
  { title: '#', dataIndex: 'sequence', key: 'sequence', width: 55 },
  { title: t('Mrp_Operation'), dataIndex: 'name', key: 'name' },
  { title: t('Mrp_Work_Centre'), dataIndex: 'work_center_name', key: 'work_center_name', width: 160 },
  { title: t('Operator'), dataIndex: 'employee_name', key: 'employee_name', width: 150 },
  { title: t('Status'), key: 'status', dataIndex: 'status', width: 120 },
  { title: t('Mrp_Time'), key: 'time', width: 190 },
  { title: t('Labour'), key: 'labour_cost', dataIndex: 'labour_cost', width: 110, align: 'right' },
  { title: '', key: 'actions', width: 90, align: 'center' },
];

const qcLineColumns = [
  { title: t('Mrp_Parameter'), dataIndex: 'parameter', key: 'parameter' },
  { title: t('Mrp_Expected'), dataIndex: 'expected', key: 'expected', width: 140 },
  { title: t('Mrp_Actual'), dataIndex: 'actual', key: 'actual', width: 140 },
  { title: t('Mrp_Result'), key: 'result', dataIndex: 'result', width: 100 },
];

// ---------------- actions ----------------

async function release(allowShortage = false) {
  busy.value = true;
  try {
    await http.post(`mrp/production-orders/${id}/release`, { allow_shortage: allowShortage });
    message.success(t('Mrp_Released_Materials_Issued'));
    load();
  } catch (e) {
    const list = e?.data?.shortages || [];
    if (list.length) {
      Modal.confirm({
        title: t('Mrp_Not_Enough_Material'),
        content: `${list.length} ${t('Mrp_Components_Short_Release_Text')}`,
        okText: t('Mrp_Release_Anyway'),
        okType: 'danger',
        cancelText: t('Cancel'),
        onOk: () => release(true),
      });
    } else {
      message.error(e?.data?.message || t('Mrp_Could_Not_Release_Order'));
    }
  } finally {
    busy.value = false;
  }
}

const completeOpen = ref(false);
const completeForm = reactive({ qty_produced: 0, qty_scrapped: 0, batch_no: '', production_date: null, expiry_date: null });

function today() {
  const d = new Date();
  const pad = n => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
}

/** Finished-goods labels carry the batch and expiry: hand the order to the barcode page. */
function printLabels() {
  router.push({ path: '/products/barcode', query: { production_order_id: id } });
}

function openComplete() {
  completeForm.qty_produced = Number(order.value.qty_planned) || 0;
  completeForm.qty_scrapped = 0;
  // Whatever was set when the order was raised is the starting point; a
  // tracked product with nothing set falls back to the order reference.
  completeForm.batch_no = order.value.batch_no || (order.value.is_batch_tracked ? order.value.reference : '');
  completeForm.production_date = order.value.production_date || today();
  completeForm.expiry_date = order.value.expiry_date || null;
  completeOpen.value = true;
}

async function submitComplete(allowOverproduction = false) {
  busy.value = true;
  try {
    const res = await http.post(`mrp/production-orders/${id}/complete`, {
      qty_produced: completeForm.qty_produced,
      qty_scrapped: completeForm.qty_scrapped,
      allow_overproduction: allowOverproduction,
      batch_no: completeForm.batch_no,
      production_date: completeForm.production_date,
      expiry_date: completeForm.expiry_date,
    });
    message.success(
      `${t('Mrp_Completed_Unit_Cost')} ${money(res?.unit_cost || 0)}`
      + (res?.product_cost != null ? ` · ${t('Mrp_Cost_Price_Now')} ${money(res.product_cost)}` : '')
      + (res?.batch_no ? ` · ${t('Mrp_Batch_Lc')} ${res.batch_no}` : ''),
    );
    completeOpen.value = false;
    load();
  } catch (e) {
    if (e?.data?.needs_confirmation) {
      Modal.confirm({
        title: t('Mrp_Above_Planned_Quantity'),
        content: e.data.message,
        okText: t('Mrp_Yes_That_Is_Right'),
        cancelText: t('Mrp_Let_Me_Check'),
        onOk: () => submitComplete(true),
      });
    } else {
      message.error(e?.data?.message || t('Mrp_Could_Not_Complete_Order'));
    }
  } finally {
    busy.value = false;
  }
}

function confirmCancel() {
  Modal.confirm({
    title: t('Mrp_Cancel_Ref_Confirm', { ref: order.value.reference }),
    content: order.value.materials_issued
      ? t('Mrp_Cancel_Issued_Text')
      : t('Mrp_Cancel_Clean_Text'),
    okText: t('Mrp_Cancel_Order'),
    okType: 'danger',
    cancelText: t('Mrp_Keep_It'),
    async onOk() {
      try {
        await http.post(`mrp/production-orders/${id}/cancel`, { reason: 'Cancelled from the order page' });
        message.success(t('Mrp_Order_Cancelled'));
        load();
      } catch (e) {
        message.error(e?.data?.message || t('Mrp_Could_Not_Cancel_Order'));
      }
    },
  });
}

async function startStep(record) {
  try {
    await http.post(`mrp/work-orders/${record.id}/start`);
    message.success(t('Mrp_Step_Started'));
    load();
  } catch (e) {
    message.error(e?.data?.message || t('Mrp_Could_Not_Start_Step'));
  }
}

const finishOpen = ref(false);
const finishForm = reactive({ actual_minutes: null, employee_id: undefined, qty_completed: null, qty_rejected: null, notes: '' });
const finishing = ref(null);

function openFinish(record) {
  finishing.value = record;
  finishForm.actual_minutes = null;
  finishForm.employee_id = record.employee_id || undefined;
  finishForm.qty_completed = Number(order.value.qty_planned) || null;
  finishForm.qty_rejected = null;
  finishForm.notes = '';
  finishOpen.value = true;
}

async function submitFinish() {
  busy.value = true;
  try {
    await http.post(`mrp/work-orders/${finishing.value.id}/finish`, { ...finishForm });
    message.success(t('Mrp_Step_Finished'));
    finishOpen.value = false;
    load();
  } catch (e) {
    message.error(e?.data?.message || t('Mrp_Could_Not_Finish_Step'));
  } finally {
    busy.value = false;
  }
}

const qcOpen = ref(false);
function openQc() {
  qcOpen.value = true;
}
function onQcSaved() {
  qcOpen.value = false;
  load();
}

async function load() {
  loading.value = true;
  try {
    const res = await http.get(`mrp/production-orders/${id}`);
    order.value = res?.order || {};
    materials.value = res?.materials || [];
    workOrders.value = res?.work_orders || [];
    checks.value = res?.quality_checks || [];
    shortages.value = (res?.shortages || []).filter(s => !s.is_optional);
  } catch (e) {
    message.error(t('Mrp_Could_Not_Load_Order'));
  } finally {
    loading.value = false;
  }
}

onMounted(async () => {
  load();
  try {
    const meta = await http.get('mrp/meta');
    employees.value = meta?.employees || [];
  } catch (e) { /* the operator select stays empty */ }
});
</script>

<style scoped>
.muted {
  color: rgba(128, 128, 128, 0.7);
}
.bad {
  color: #dc2626;
  font-weight: 600;
}
.sub {
  font-size: 11.5px;
  opacity: 0.55;
}
.mini {
  font-size: 10.5px;
  line-height: 16px;
  margin-inline-start: 4px;
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
  border-color: rgba(109, 40, 217, 0.45);
  background: rgba(109, 40, 217, 0.05);
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
.kpi-of {
  font-size: 14px;
  opacity: 0.5;
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
  flex-wrap: wrap;
}
.tab-note {
  font-size: 12px;
  opacity: 0.65;
}
.qc-head {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}
.qc-notes {
  margin: 8px 0 0;
  font-size: 12.5px;
  opacity: 0.75;
}
.cost-note {
  margin-top: 10px;
  font-size: 12px;
  opacity: 0.65;
}
.estimate {
  margin: 12px 0 0;
  font-size: 12.5px;
  opacity: 0.75;
}
</style>
