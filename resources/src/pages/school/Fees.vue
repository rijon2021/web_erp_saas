<template>
  <div class="page">
    <PageHeader :title="$t('Sc_Fees')" :subtitle="$t('Sc_Fees_Subtitle')" :breadcrumb="[$t('Sc_School'), $t('Sc_Fees')]">
      <template #actions>
        <a-button v-if="tab === 'invoices'" @click="generateOpen = true">
          <template #icon><ThunderboltOutlined /></template>
          {{ $t('Sc_Bill_A_Class') }}
        </a-button>
        <a-button type="primary" @click="onAdd">
          <template #icon><PlusOutlined /></template>
          {{ tab === 'structures' ? $t('Sc_New_Fee_Item') : $t('Sc_New_Invoice') }}
        </a-button>
      </template>
    </PageHeader>

    <div v-if="tab === 'invoices'" class="summary">
      <div class="sum">
        <span class="sum-label">{{ $t('Sc_Billed') }}</span>
        <span class="sum-value">{{ money(totals.billed || 0) }}</span>
      </div>
      <div class="sum">
        <span class="sum-label">{{ $t('Sc_Collected') }}</span>
        <span class="sum-value ok">{{ money(totals.paid || 0) }}</span>
      </div>
      <div class="sum" :class="{ 'sum--danger': (totals.due || 0) > 0 }">
        <span class="sum-label">{{ $t('Sc_Outstanding') }}</span>
        <span class="sum-value">{{ money(totals.due || 0) }}</span>
      </div>
    </div>

    <a-card size="small" style="margin-bottom: 16px">
      <a-tabs v-model:activeKey="tab" @change="onTabChange">
        <a-tab-pane key="invoices" :tab="$t('Sc_Invoices')" />
        <a-tab-pane key="structures" :tab="$t('Sc_Fee_Structures')" />
        <a-tab-pane key="payments" :tab="$t('Sc_Payments')" />
      </a-tabs>

      <div class="toolbar">
        <a-input-search
          v-model:value="crud.search.value" :placeholder="$t('Sc_Search_Ellipsis')"
          allow-clear class="tb-search" @search="crud.reload"
        />
        <a-select
          v-model:value="filters.academic_year_id" class="tb-item" allow-clear show-search
          option-filter-prop="label" :placeholder="$t('Sc_Year')" :options="yearOptions" @change="crud.reload"
        />
        <a-select
          v-if="tab !== 'payments'"
          v-model:value="filters.class_id" class="tb-item" allow-clear show-search
          option-filter-prop="label" :placeholder="$t('Sc_All_Classes')" :options="classOptions" @change="crud.reload"
        />
        <a-select
          v-if="tab === 'invoices'"
          v-model:value="filters.status" class="tb-item-sm" allow-clear
          :placeholder="$t('Status')" :options="INVOICE_STATUSES" @change="crud.reload"
        />
        <a-range-picker
          v-if="tab !== 'structures'"
          v-model:value="filters.range" class="tb-range" value-format="YYYY-MM-DD" @change="crud.reload"
        />
        <a-checkbox
          v-if="tab === 'invoices'" v-model:checked="filters.overdue"
          class="tb-check" @change="crud.reload"
        >
          {{ $t('Sc_Overdue_Only') }}
        </a-checkbox>
      </div>
    </a-card>

    <DataTable :crud="crud" :columns="columns" :searchable="false">
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'student'">
          <button type="button" class="link-cell" @click="$router.push(`/school/students/${record.student_id}`)">
            <div class="cell-name">{{ record.student_name }}</div>
            <div class="cell-sub">{{ record.admission_number }}{{ record.reference ? ' · ' + record.reference : '' }}</div>
          </button>
        </template>
        <template v-else-if="column.key === 'invoice_date'">
          <div>{{ date(record.invoice_date) }}</div>
          <div v-if="record.due_date" class="cell-sub" :class="{ danger: record.is_overdue }">
            {{ $t('Sc_Due_On_Date', { date: date(record.due_date) }) }}
          </div>
        </template>
        <template v-else-if="column.key === 'paid_on'">{{ date(record.paid_on) }}</template>
        <template v-else-if="column.money">{{ money(record[column.dataIndex] || 0) }}</template>
        <template v-else-if="column.key === 'due'">
          <span :class="{ danger: record.due > 0 }">{{ money(record.due) }}</span>
        </template>
        <template v-else-if="column.key === 'status'">
          <a-tag :color="optionOf(INVOICE_STATUSES, record.status).color">
            {{ labelOf(INVOICE_STATUSES, record.status) }}
          </a-tag>
        </template>
        <template v-else-if="column.key === 'frequency'">{{ labelOf(FEE_FREQUENCIES, record.frequency) }}</template>
        <template v-else-if="column.key === 'method'">{{ labelOf(PAYMENT_METHODS, record.method) }}</template>
        <template v-else-if="column.key === 'is_active'">
          <a-tag :color="record.is_active ? 'success' : 'default'">
            {{ record.is_active ? $t('Sc_Active') : $t('Sc_Inactive') }}
          </a-tag>
        </template>
        <template v-else-if="column.key === 'actions'">
          <a-space :size="0">
            <template v-if="tab === 'invoices'">
              <a-tooltip :title="$t('Sc_Open')">
                <a-button type="text" size="small" @click="openDetail(record)">
                  <template #icon><EyeOutlined /></template>
                </a-button>
              </a-tooltip>
              <a-tooltip v-if="record.due > 0 && record.status !== 'cancelled'" :title="$t('Sc_Take_Payment')">
                <a-button type="text" size="small" @click="openPayment(record)">
                  <template #icon><DollarOutlined /></template>
                </a-button>
              </a-tooltip>
            </template>
            <a-tooltip v-if="tab !== 'payments'" :title="$t('Edit')">
              <a-button type="text" size="small" @click="openForm(record)">
                <template #icon><EditOutlined /></template>
              </a-button>
            </a-tooltip>
            <a-tooltip :title="$t('Delete')">
              <a-button type="text" size="small" danger @click="remove(record)">
                <template #icon><DeleteOutlined /></template>
              </a-button>
            </a-tooltip>
          </a-space>
        </template>
      </template>
    </DataTable>

    <!-- Fee structure form -->
    <a-modal
      :open="structureOpen" :title="editing ? $t('Sc_Edit_Fee_Item') : $t('Sc_New_Fee_Item')" :width="560"
      :confirm-loading="saving" :ok-text="$t('submit')" :cancel-text="$t('Cancel')"
      @ok="submitStructure" @cancel="structureOpen = false"
    >
      <a-form ref="structureRef" :model="structure" :rules="structureRules" layout="vertical">
        <a-row :gutter="16">
          <a-col :xs="24" :md="14">
            <a-form-item :label="$t('Name') + ' *'" name="name">
              <a-input v-model:value="structure.name" :placeholder="$t('Sc_Fee_Name_Placeholder')" allow-clear />
            </a-form-item>
          </a-col>
          <a-col :xs="12" :md="10">
            <a-form-item :label="$t('Amount') + ' *'" name="amount">
              <a-input-number v-model:value="structure.amount" :min="0" style="width: 100%" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Sc_Academic_Year') + ' *'" name="academic_year_id">
              <a-select v-model:value="structure.academic_year_id" :options="yearOptions" show-search option-filter-prop="label" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Sc_Class')" :extra="$t('Sc_Empty_Charges_Every_Class')">
              <a-select
                v-model:value="structure.class_id" allow-clear show-search option-filter-prop="label"
                :options="classOptions" :placeholder="$t('Sc_All_Classes')"
              />
            </a-form-item>
          </a-col>
          <a-col :xs="12" :md="12">
            <a-form-item :label="$t('Sc_Frequency') + ' *'" name="frequency">
              <a-select v-model:value="structure.frequency" :options="FEE_FREQUENCIES" />
            </a-form-item>
          </a-col>
          <a-col :xs="12" :md="12">
            <a-form-item :label="$t('Sc_Due_Date')">
              <a-date-picker v-model:value="structure.due_date" style="width: 100%" value-format="YYYY-MM-DD" />
            </a-form-item>
          </a-col>
          <a-col :span="24">
            <a-form-item :label="$t('Description')">
              <a-textarea v-model:value="structure.description" :rows="2" />
            </a-form-item>
          </a-col>
          <a-col :span="24">
            <a-space>
              <a-checkbox v-model:checked="structure.is_optional">{{ $t('Sc_Optional') }}</a-checkbox>
              <a-checkbox v-model:checked="structure.is_active">{{ $t('Sc_Active') }}</a-checkbox>
            </a-space>
          </a-col>
        </a-row>
      </a-form>
    </a-modal>

    <!-- Invoice form -->
    <a-modal
      :open="invoiceOpen" :title="editing ? $t('Sc_Edit_Invoice') : $t('Sc_New_Invoice')" :width="760"
      :confirm-loading="saving" :ok-text="$t('submit')" :cancel-text="$t('Cancel')"
      @ok="submitInvoice" @cancel="invoiceOpen = false"
    >
      <a-form ref="invoiceRef" :model="invoice" :rules="invoiceRules" layout="vertical">
        <a-row :gutter="16">
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Sc_Student') + ' *'" name="student_id">
              <StudentPicker v-model="invoice.student_id" :initial-option="editingStudent" @select="onStudentSelect" />
            </a-form-item>
          </a-col>
          <a-col :xs="12" :md="6">
            <a-form-item :label="$t('Sc_Invoice_Date')">
              <a-date-picker v-model:value="invoice.invoice_date" style="width: 100%" value-format="YYYY-MM-DD" />
            </a-form-item>
          </a-col>
          <a-col :xs="12" :md="6">
            <a-form-item :label="$t('Sc_Due_Date')">
              <a-date-picker v-model:value="invoice.due_date" style="width: 100%" value-format="YYYY-MM-DD" />
            </a-form-item>
          </a-col>
        </a-row>

        <div class="lines-head">
          <span>{{ $t('Sc_Lines') }}</span>
          <a-space>
            <a-select
              v-model:value="pickFee" style="width: 220px" allow-clear show-search
              option-filter-prop="label" :placeholder="$t('Sc_Add_From_Fee_Structure')"
              :options="feeOptions" @change="addFromStructure"
            />
            <a-button size="small" type="primary" ghost @click="addLine">
              <template #icon><PlusOutlined /></template>
              {{ $t('Sc_Blank_Line') }}
            </a-button>
          </a-space>
        </div>

        <div v-if="invoice.items.length" class="lines">
          <div v-for="(line, i) in invoice.items" :key="i" class="line">
            <a-input v-model:value="line.description" :placeholder="$t('Description')" class="line-desc" />
            <a-input-number v-model:value="line.quantity" :min="0" :placeholder="$t('Sc_Qty')" class="line-qty" />
            <a-input-number v-model:value="line.unit_price" :min="0" :placeholder="$t('Amount')" class="line-price" />
            <span class="line-total">{{ money(lineTotal(line)) }}</span>
            <a-button type="text" danger @click="invoice.items.splice(i, 1)">
              <template #icon><DeleteOutlined /></template>
            </a-button>
          </div>
        </div>
        <a-empty v-else :image="simpleEmptyImage" :description="$t('Sc_No_Lines_Yet')" style="padding: 12px 0" />

        <a-row :gutter="16" style="margin-top: 12px">
          <a-col :xs="12" :md="8">
            <a-form-item :label="$t('Discount')">
              <a-input-number v-model:value="invoice.discount" :min="0" style="width: 100%" />
            </a-form-item>
          </a-col>
          <a-col :xs="12" :md="8">
            <a-form-item :label="$t('Status')">
              <a-select v-model:value="invoice.status" :options="INVOICE_STATUSES" />
            </a-form-item>
          </a-col>
        </a-row>

        <div class="totals-box">
          <div><span>{{ $t('Sc_Subtotal') }}</span><b>{{ money(subtotal) }}</b></div>
          <div><span>{{ $t('Discount') }}</span><b>-{{ money(invoice.discount || 0) }}</b></div>
          <div class="grand"><span>{{ $t('Total') }}</span><b>{{ money(grandTotal) }}</b></div>
        </div>

        <a-form-item :label="$t('Note')" style="margin-bottom: 0">
          <a-textarea v-model:value="invoice.notes" :rows="2" />
        </a-form-item>
      </a-form>
    </a-modal>

    <!-- Bill a class -->
    <a-modal
      :open="generateOpen" :title="$t('Sc_Bill_A_Class')" :width="560"
      :confirm-loading="generating" :ok-text="$t('Sc_Generate')" :cancel-text="$t('Cancel')"
      @ok="submitGenerate" @cancel="generateOpen = false"
    >
      <a-alert
        type="info" show-icon banner
        :message="$t('Sc_Bill_A_Class_Hint')"
        style="margin-bottom: 16px"
      />
      <a-form layout="vertical">
        <a-row :gutter="16">
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Sc_Academic_Year')">
              <a-select v-model:value="generate.academic_year_id" :options="yearOptions" show-search option-filter-prop="label" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Sc_Class')">
              <a-select
                v-model:value="generate.class_id" :options="classOptions" show-search
                option-filter-prop="label" @change="generate.section_id = undefined"
              />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Sc_Section')">
              <a-select
                v-model:value="generate.section_id" allow-clear show-search option-filter-prop="label"
                :options="generateSectionOptions" :disabled="!generate.class_id" :placeholder="$t('Sc_Whole_Class')"
              />
            </a-form-item>
          </a-col>
          <a-col :xs="12" :md="6">
            <a-form-item :label="$t('Sc_Invoice_Date')">
              <a-date-picker v-model:value="generate.invoice_date" style="width: 100%" value-format="YYYY-MM-DD" />
            </a-form-item>
          </a-col>
          <a-col :xs="12" :md="6">
            <a-form-item :label="$t('Sc_Due_Date')">
              <a-date-picker v-model:value="generate.due_date" style="width: 100%" value-format="YYYY-MM-DD" />
            </a-form-item>
          </a-col>
          <a-col :span="24">
            <a-form-item :label="$t('Sc_Fee_Items')" style="margin-bottom: 0">
              <a-select
                v-model:value="generate.fee_structure_ids" mode="multiple" show-search
                option-filter-prop="label" :options="generateFeeOptions" :placeholder="$t('Sc_Pick_What_To_Charge')"
              />
            </a-form-item>
          </a-col>
        </a-row>
      </a-form>
    </a-modal>

    <!-- Invoice detail -->
    <a-drawer :open="detailOpen" :width="640" :title="detail?.reference" @close="detailOpen = false">
      <template #extra>
        <a-button
          v-if="detail && detail.due > 0 && detail.status !== 'cancelled'"
          type="primary" @click="openPayment(detail)"
        >
          {{ $t('Sc_Take_Payment') }}
        </a-button>
      </template>

      <template v-if="detail">
        <a-descriptions :column="2" size="small" bordered style="margin-bottom: 16px">
          <a-descriptions-item :label="$t('Sc_Student')">{{ detail.student_name }}</a-descriptions-item>
          <a-descriptions-item :label="$t('Sc_Adm_No')">{{ detail.admission_number }}</a-descriptions-item>
          <a-descriptions-item :label="$t('Date')">{{ date(detail.invoice_date) }}</a-descriptions-item>
          <a-descriptions-item :label="$t('Status')">
            <a-tag :color="optionOf(INVOICE_STATUSES, detail.status).color">
              {{ labelOf(INVOICE_STATUSES, detail.status) }}
            </a-tag>
          </a-descriptions-item>
        </a-descriptions>

        <a-table
          :columns="detailColumns" :data-source="detail.items" :row-key="r => r.id"
          :pagination="false" size="small"
        >
          <template #bodyCell="{ column, record }">
            <template v-if="column.key === 'unit_price'">{{ money(record.unit_price) }}</template>
            <template v-else-if="column.key === 'total'">{{ money(record.total) }}</template>
          </template>
        </a-table>

        <div class="totals-box" style="margin-top: 16px">
          <div><span>{{ $t('Sc_Subtotal') }}</span><b>{{ money(detail.subtotal) }}</b></div>
          <div><span>{{ $t('Discount') }}</span><b>-{{ money(detail.discount) }}</b></div>
          <div class="grand"><span>{{ $t('Total') }}</span><b>{{ money(detail.total) }}</b></div>
          <div><span>{{ $t('Paid') }}</span><b>{{ money(detail.paid) }}</b></div>
          <div class="grand" :class="{ danger: detail.due > 0 }"><span>{{ $t('Due') }}</span><b>{{ money(detail.due) }}</b></div>
        </div>

        <a-divider orientation="left">{{ $t('Sc_Payments') }}</a-divider>
        <a-list :data-source="detail.payments" size="small">
          <template #renderItem="{ item }">
            <a-list-item>
              <a-list-item-meta
                :title="money(item.amount)"
                :description="`${date(item.paid_on)} · ${labelOf(PAYMENT_METHODS, item.method)}`"
              />
              <template #actions>
                <a-button type="text" size="small" danger @click="removePayment(item)">
                  <template #icon><DeleteOutlined /></template>
                </a-button>
              </template>
            </a-list-item>
          </template>
          <template #empty><a-empty :image="simpleEmptyImage" :description="$t('Sc_Nothing_Paid_Yet')" /></template>
        </a-list>
      </template>
    </a-drawer>

    <!-- Payment -->
    <a-modal
      :open="paymentOpen" :title="$t('Sc_Take_Payment')" :width="440"
      :confirm-loading="paying" :ok-text="$t('submit')" :cancel-text="$t('Cancel')"
      @ok="submitPayment" @cancel="paymentOpen = false"
    >
      <p v-if="payTarget" class="target">
        {{ $t('Sc_Name_Outstanding', { name: payTarget.student_name }) }} <b>{{ money(payTarget.due) }}</b>
      </p>
      <a-form layout="vertical">
        <a-form-item :label="$t('Amount')">
          <a-input-number v-model:value="payment.amount" :min="0.01" :max="roundMoney(payTarget?.due)" style="width: 100%" />
        </a-form-item>
        <a-row :gutter="16">
          <a-col :span="12">
            <a-form-item :label="$t('Date')">
              <a-date-picker v-model:value="payment.paid_on" style="width: 100%" value-format="YYYY-MM-DD" />
            </a-form-item>
          </a-col>
          <a-col :span="12">
            <a-form-item :label="$t('Sc_Method')">
              <a-select v-model:value="payment.method" :options="PAYMENT_METHODS" />
            </a-form-item>
          </a-col>
        </a-row>
        <a-form-item :label="$t('Note')" style="margin-bottom: 0">
          <a-input v-model:value="payment.notes" allow-clear />
        </a-form-item>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * Fees, as three views over one endpoint family: what the school charges
 * (structures), what it has billed (invoices) and what it has received
 * (payments).
 *
 * Totals shown while editing are a PREVIEW — the server recomputes every line
 * and derives the status from the payments that actually exist. Overpayment is
 * refused, so the payment amount is capped at what is outstanding.
 *
 * "Bill a class" is the screen a bursar lives in; it is explicitly re-runnable,
 * because the fear of double-billing is what keeps schools doing it by hand.
 */
import { ref, reactive, computed, onMounted, createVNode } from 'vue';
import { message, Modal, Empty } from 'ant-design-vue';
import {
  PlusOutlined, EditOutlined, DeleteOutlined, EyeOutlined,
  DollarOutlined, ThunderboltOutlined, ExclamationCircleOutlined,
} from '@ant-design/icons-vue';
import { useRoute } from 'vue-router';
import PageHeader from '../../components/PageHeader.vue';
import DataTable from '../../components/DataTable.vue';
import StudentPicker from './StudentPicker.vue';
import { useCrudTable } from '../../composables/useCrudTable';
import { useFormat } from '../../composables/useFormat';
import {
  INVOICE_STATUSES, FEE_FREQUENCIES, PAYMENT_METHODS, labelOf, optionOf,
} from './schoolOptions';
import http from '../../lib/http';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();
const route = useRoute();
const { money, date, roundMoney } = useFormat();
const simpleEmptyImage = Empty.PRESENTED_IMAGE_SIMPLE;

const tab = ref('invoices');
const filters = reactive({
  academic_year_id: undefined, class_id: undefined, status: undefined,
  range: null, overdue: route.query.overdue === '1',
});

const ENDPOINTS = {
  invoices: { url: 'school/fee-invoices', key: 'invoices' },
  structures: { url: 'school/fee-structures', key: 'fee_structures' },
  payments: { url: 'school/fee-payments', key: 'payments' },
};
const endpoint = ref(ENDPOINTS.invoices.url);
const rowsKey = ref(ENDPOINTS.invoices.key);

const crud = useCrudTable(() => endpoint.value, {
  sortField: 'invoice_date',
  select: p => ({ rows: p?.[rowsKey.value] || [], total: p?.totalRows || 0 }),
  params: () => ({
    academic_year_id: filters.academic_year_id || '',
    class_id: filters.class_id || '',
    status: filters.status || '',
    outstanding: route.query.outstanding === '1' ? 1 : '',
    overdue: filters.overdue ? 1 : '',
    start_date: filters.range?.[0] || '',
    end_date: filters.range?.[1] || '',
  }),
});

const totals = computed(() => crud.payload.value?.totals || {});

function onTabChange() {
  endpoint.value = ENDPOINTS[tab.value].url;
  rowsKey.value = ENDPOINTS[tab.value].key;
  crud.reload();
}

const COLUMNS = {
  invoices: [
    { title: t('Sc_Student'), key: 'student', dataIndex: 'student_name' },
    { title: t('Date'), key: 'invoice_date', dataIndex: 'invoice_date', sorter: true, width: 150 },
    { title: t('Total'), key: 'total', dataIndex: 'total', sorter: true, money: true, width: 120 },
    { title: t('Paid'), key: 'paid', dataIndex: 'paid', sorter: true, money: true, width: 120 },
    { title: t('Due'), key: 'due', dataIndex: 'due', width: 120 },
    { title: t('Status'), key: 'status', dataIndex: 'status', sorter: true, width: 130 },
    { title: '', key: 'actions', width: 140 },
  ],
  structures: [
    { title: t('Sc_Fee_Item'), dataIndex: 'name', key: 'name' },
    { title: t('Sc_Year'), dataIndex: 'year_name', key: 'year_name', width: 140 },
    { title: t('Sc_Class'), dataIndex: 'class_name', key: 'class_name', width: 150 },
    { title: t('Sc_Frequency'), key: 'frequency', dataIndex: 'frequency', width: 130 },
    { title: t('Amount'), key: 'amount', dataIndex: 'amount', money: true, width: 130 },
    { title: t('Status'), key: 'is_active', dataIndex: 'is_active', width: 110 },
    { title: '', key: 'actions', width: 90 },
  ],
  payments: [
    { title: t('Sc_Student'), key: 'student', dataIndex: 'student_name' },
    { title: t('Sc_Paid_On'), key: 'paid_on', dataIndex: 'paid_on', width: 140 },
    { title: t('Amount'), key: 'amount', dataIndex: 'amount', money: true, width: 140 },
    { title: t('Sc_Method'), key: 'method', dataIndex: 'method', width: 150 },
    { title: t('Sc_Invoice'), dataIndex: 'invoice_reference', key: 'invoice_reference', width: 170 },
    { title: '', key: 'actions', width: 70 },
  ],
};

const columns = computed(() => COLUMNS[tab.value]);

const meta = ref({});
const yearOptions = computed(() => (meta.value.academic_years || []).map(y => ({
  value: y.id, label: y.is_current ? t('Sc_Year_Name_Current', { name: y.name }) : y.name,
})));
const classOptions = computed(() => (meta.value.classes || []).map(c => ({ value: c.id, label: c.name })));
const feeOptions = computed(() => (meta.value.fee_structures || []).map(f => ({
  value: f.id, label: `${f.name} — ${f.amount}`, fee: f,
})));
const generateFeeOptions = computed(() => (meta.value.fee_structures || [])
  .filter(f => !generate.class_id || !f.class_id || f.class_id === generate.class_id)
  .map(f => ({ value: f.id, label: `${f.name} — ${f.amount}` })));
const generateSectionOptions = computed(() => (meta.value.sections || [])
  .filter(s => s.class_id === generate.class_id)
  .map(s => ({ value: s.id, label: s.name })));

const saving = ref(false);
const editing = ref(null);

function onAdd() {
  if (tab.value === 'structures') openStructure(null);
  else openInvoice(null);
}

function openForm(record) {
  if (tab.value === 'structures') openStructure(record);
  else openInvoice(record);
}

// ---------------- fee structure ----------------

const structureRef = ref();
const structureOpen = ref(false);
const structure = ref(blankStructure());

function blankStructure() {
  return {
    name: '', amount: null, academic_year_id: meta.value.current_year_id,
    class_id: undefined, frequency: 'termly', due_date: null,
    is_optional: false, is_active: true, description: '',
  };
}

const required = () => [{ required: true, message: t('Field_is_required', 'This field is required') }];
const structureRules = computed(() => ({
  name: required(), amount: required(), academic_year_id: required(), frequency: required(),
}));

function openStructure(record) {
  editing.value = record;
  structure.value = record ? { ...blankStructure(), ...record, class_id: record.class_id || undefined } : blankStructure();
  structureOpen.value = true;
  structureRef.value?.clearValidate?.();
}

async function submitStructure() {
  try {
    await structureRef.value.validate();
  } catch (e) {
    return;
  }

  saving.value = true;
  try {
    if (editing.value) await http.put(`school/fee-structures/${editing.value.id}`, structure.value);
    else await http.post('school/fee-structures', structure.value);
    message.success(t('Created_in_successfully', 'Saved successfully'));
    structureOpen.value = false;
    editing.value = null;
    crud.fetchRows();
    loadMeta();
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData', 'Could not save this fee item'));
  } finally {
    saving.value = false;
  }
}

// ---------------- invoice ----------------

const invoiceRef = ref();
const invoiceOpen = ref(false);
const editingStudent = ref(null);
const pickFee = ref(undefined);
const invoice = ref(blankInvoice());

function blankInvoice() {
  return {
    student_id: undefined, academic_year_id: meta.value.current_year_id, class_id: undefined,
    invoice_date: new Date().toISOString().slice(0, 10), due_date: null,
    items: [], discount: null, status: 'unpaid', notes: '',
  };
}

const invoiceRules = computed(() => ({ student_id: required() }));

function lineTotal(line) {
  return (Number(line.quantity) || 0) * (Number(line.unit_price) || 0);
}
const subtotal = computed(() => invoice.value.items.reduce((sum, l) => sum + lineTotal(l), 0));
const grandTotal = computed(() => Math.max(0, subtotal.value - (Number(invoice.value.discount) || 0)));

function addLine() {
  invoice.value.items.push({ description: '', quantity: 1, unit_price: 0, fee_structure_id: null });
}

function addFromStructure(id) {
  const option = feeOptions.value.find(o => o.value === id);
  if (!option) return;
  invoice.value.items.push({
    description: option.fee.name,
    quantity: 1,
    unit_price: option.fee.amount,
    fee_structure_id: option.fee.id,
  });
  pickFee.value = undefined;
}

function onStudentSelect(student) {
  if (student?.class_id && !invoice.value.class_id) invoice.value.class_id = student.class_id;
}

async function openInvoice(record) {
  editing.value = record;
  editingStudent.value = record
    ? { id: record.student_id, name: record.student_name, admission_number: record.admission_number }
    : null;

  if (record) {
    try {
      const data = await http.get(`school/fee-invoices/${record.id}`);
      const i = data?.invoice;
      invoice.value = {
        student_id: i.student_id,
        academic_year_id: i.academic_year_id,
        class_id: i.class_id,
        invoice_date: i.invoice_date,
        due_date: i.due_date,
        items: (i.items || []).map(x => ({ ...x })),
        discount: i.discount,
        status: i.status,
        notes: i.notes || '',
      };
    } catch (e) {
      message.error(t('InvalidData', 'Could not load this invoice'));
      return;
    }
  } else {
    invoice.value = blankInvoice();
  }

  invoiceOpen.value = true;
  invoiceRef.value?.clearValidate?.();
}

async function submitInvoice() {
  try {
    await invoiceRef.value.validate();
  } catch (e) {
    return;
  }
  if (!invoice.value.items.length) {
    message.warning(t('Sc_Add_At_Least_One_Line'));
    return;
  }

  saving.value = true;
  try {
    if (editing.value) await http.put(`school/fee-invoices/${editing.value.id}`, invoice.value);
    else await http.post('school/fee-invoices', invoice.value);
    message.success(t('Created_in_successfully', 'Saved successfully'));
    invoiceOpen.value = false;
    editing.value = null;
    crud.fetchRows();
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData', 'Could not save this invoice'));
  } finally {
    saving.value = false;
  }
}

// ---------------- generate ----------------

const generateOpen = ref(false);
const generating = ref(false);
const generate = reactive({
  academic_year_id: undefined, class_id: undefined, section_id: undefined,
  invoice_date: new Date().toISOString().slice(0, 10), due_date: null, fee_structure_ids: [],
});

async function submitGenerate() {
  if (!generate.class_id || !generate.fee_structure_ids.length) {
    message.warning(t('Sc_Pick_Class_And_Fee_Item'));
    return;
  }

  generating.value = true;
  try {
    const data = await http.post('school/fee-invoices/generate', { ...generate });
    message.success(t('Sc_Invoices_Created_Skipped', { created: data.created, skipped: data.skipped }));
    generateOpen.value = false;
    crud.fetchRows();
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData', 'Could not generate the invoices'));
  } finally {
    generating.value = false;
  }
}

// ---------------- detail & payments ----------------

const detailOpen = ref(false);
const detail = ref(null);

const detailColumns = [
  { title: t('Description'), dataIndex: 'description', key: 'description' },
  { title: t('Sc_Qty'), dataIndex: 'quantity', key: 'quantity', width: 70 },
  { title: t('Amount'), key: 'unit_price', dataIndex: 'unit_price', width: 120 },
  { title: t('Total'), key: 'total', dataIndex: 'total', width: 120 },
];

async function openDetail(record) {
  try {
    const data = await http.get(`school/fee-invoices/${record.id}`);
    detail.value = data?.invoice || null;
    detailOpen.value = true;
  } catch (e) {
    message.error(t('InvalidData', 'Could not load this invoice'));
  }
}

const paymentOpen = ref(false);
const paying = ref(false);
const payTarget = ref(null);
const payment = reactive({ amount: null, paid_on: null, method: 'cash', notes: '' });

function openPayment(record) {
  payTarget.value = record;
  Object.assign(payment, {
    amount: roundMoney(record.due),
    paid_on: new Date().toISOString().slice(0, 10),
    method: 'cash',
    notes: '',
  });
  paymentOpen.value = true;
}

async function submitPayment() {
  paying.value = true;
  try {
    await http.post(`school/fee-invoices/${payTarget.value.id}/payments`, { ...payment });
    message.success(t('Sc_Payment_Recorded'));
    paymentOpen.value = false;
    crud.fetchRows();
    if (detailOpen.value) openDetail(payTarget.value);
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData', 'Could not record this payment'));
  } finally {
    paying.value = false;
  }
}

async function removePayment(item) {
  try {
    await http.delete(`school/fee-invoices/${detail.value.id}/payments/${item.id}`);
    message.success(t('Deleted_in_successfully', 'Deleted successfully'));
    openDetail(detail.value);
    crud.fetchRows();
  } catch (e) {
    message.error(t('InvalidData', 'Could not remove this payment'));
  }
}

function remove(record) {
  const url = tab.value === 'structures'
    ? `school/fee-structures/${record.id}`
    : tab.value === 'payments'
      ? `school/fee-invoices/${record.invoice_id}/payments/${record.id}`
      : `school/fee-invoices/${record.id}`;

  Modal.confirm({
    title: t('Delete_Title', 'Are you sure?'),
    icon: createVNode(ExclamationCircleOutlined),
    content: t('Delete_Text', "You won't be able to revert this!"),
    okText: t('Delete_confirmButtonText', 'Yes, delete it!'),
    okType: 'danger',
    cancelText: t('Delete_cancelButtonText', 'Cancel'),
    async onOk() {
      try {
        await http.delete(url);
        message.success(t('Deleted_in_successfully', 'Deleted successfully'));
        crud.fetchRows();
      } catch (e) {
        message.error(e?.data?.message || t('InvalidData', 'Could not delete this record'));
      }
    },
  });
}

async function loadMeta() {
  try {
    meta.value = await http.get('school/meta');
    if (!filters.academic_year_id) filters.academic_year_id = meta.value.current_year_id;
    if (!generate.academic_year_id) generate.academic_year_id = meta.value.current_year_id;
  } catch (e) { /* selects stay empty */ }
}

onMounted(async () => {
  await loadMeta();
  crud.fetchRows();
});
</script>

<style scoped>
.danger {
  color: #ff4d4f;
}
.ok {
  color: #16a34a;
}
.summary {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
  gap: 12px;
  margin-bottom: 16px;
}
.sum {
  display: flex;
  flex-direction: column;
  padding: 12px 16px;
  border: 1px solid rgba(128, 128, 128, 0.2);
  border-radius: 12px;
}
.sum--danger {
  border-color: rgba(255, 77, 79, 0.4);
  background: rgba(255, 77, 79, 0.05);
}
.sum-label {
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  opacity: 0.55;
}
.sum-value {
  font-size: 19px;
  font-weight: 600;
}
.toolbar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 10px;
}
.tb-search {
  flex: 1 1 220px;
  min-width: 180px;
}
.tb-item {
  width: 170px;
}
.tb-item-sm {
  width: 130px;
}
.tb-range {
  width: 230px;
}
.tb-check {
  white-space: nowrap;
}
.link-cell {
  border: 0;
  background: none;
  padding: 0;
  cursor: pointer;
  color: inherit;
  font: inherit;
  text-align: left;
}
.cell-name {
  font-weight: 500;
}
.link-cell:hover .cell-name {
  color: #6d28d9;
}
.cell-sub {
  font-size: 11.5px;
  opacity: 0.55;
}
.lines-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  flex-wrap: wrap;
  margin-bottom: 8px;
  font-weight: 500;
}
.lines {
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.line {
  display: flex;
  align-items: center;
  gap: 6px;
  flex-wrap: wrap;
}
.line-desc {
  flex: 2 1 200px;
  min-width: 160px;
}
.line-qty {
  width: 80px;
  flex: none;
}
.line-price {
  width: 120px;
  flex: none;
}
.line-total {
  width: 110px;
  flex: none;
  text-align: right;
  font-weight: 600;
  font-size: 13px;
}
.totals-box {
  margin-top: 12px;
  padding: 12px 14px;
  border-radius: 12px;
  background: rgba(128, 128, 128, 0.08);
  font-size: 13px;
}
.totals-box > div {
  display: flex;
  justify-content: space-between;
  padding: 2px 0;
}
.totals-box .grand {
  margin-top: 6px;
  padding-top: 6px;
  border-top: 1px solid rgba(128, 128, 128, 0.2);
  font-size: 15px;
}
.target {
  margin: 0 0 14px;
  padding: 10px 12px;
  border-radius: 10px;
  background: rgba(128, 128, 128, 0.1);
  font-size: 13px;
}
</style>
