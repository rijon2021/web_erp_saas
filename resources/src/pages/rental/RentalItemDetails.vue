<template>
  <div class="page">
    <PageHeader
      :title="item ? item.name : $t('Rental_Item')"
      :subtitle="item ? [item.code, item.category_name].filter(Boolean).join(' · ') : ''"
      :breadcrumb="[$t('Rental_Management'), $t('Rental_Items'), item ? item.code : '']"
    >
      <template #actions>
        <a-button @click="$router.push('/rental/items')">
          <template #icon><ArrowLeftOutlined /></template>
          {{ $t('Back') }}
        </a-button>
        <a-button v-if="item && canMaintenance" @click="$router.push(`/rental/maintenance?new=1&item=${id}`)">
          <template #icon><ToolOutlined /></template>
          {{ $t('Rental_Report_Issue') }}
        </a-button>
        <a-button v-if="item && canExpenses" @click="$router.push(`/rental/expenses?new=1&item=${id}`)">
          <template #icon><DollarOutlined /></template>
          {{ $t('Rental_Add_Expense') }}
        </a-button>
        <a-button v-if="item && canDelete" danger @click="remove">
          <template #icon><DeleteOutlined /></template>
          {{ $t('Delete') }}
        </a-button>
        <a-button v-if="canEdit" type="primary" @click="$router.push(`/rental/items/${id}/edit`)">
          <template #icon><EditOutlined /></template>
          {{ $t('Edit') }}
        </a-button>
      </template>
    </PageHeader>

    <div v-if="loading" class="loading"><a-spin size="large" /></div>

    <a-result v-else-if="!item" status="404" :title="$t('Rental_Item_Not_Found')">
      <template #extra>
        <a-button type="primary" @click="$router.push('/rental/items')">{{ $t('Rental_Items') }}</a-button>
      </template>
    </a-result>

    <a-row v-else :gutter="16">
      <!-- Gallery + description -->
      <a-col :xs="24" :lg="15">
        <a-card size="small" class="block" :body-style="{ padding: 0 }">
          <div class="gallery">
            <div class="gallery-main">
              <a-image-preview-group v-if="item.image_urls.length">
                <a-image
                  v-for="(url, i) in item.image_urls" v-show="i === active" :key="url"
                  :src="url" :alt="item.name" class="gallery-img" :width="'100%'" :height="'100%'"
                />
              </a-image-preview-group>
              <span v-else class="gallery-empty">
                <component :is="typeIcon(item.type)" :size="64" :stroke-width="1.2" />
                <span>{{ $t('Rental_No_Photos') }}</span>
              </span>
              <span class="gallery-type">
                <component :is="typeIcon(item.type)" :size="14" :stroke-width="2" />
                {{ labelOf(RENTAL_TYPES, item.type) }}
              </span>
            </div>
            <div v-if="item.image_urls.length > 1" class="thumbs">
              <button
                v-for="(url, i) in item.image_urls" :key="url" type="button"
                class="thumb" :class="{ active: i === active }" @click="active = i"
              >
                <img :src="url" alt="" loading="lazy" />
              </button>
            </div>
          </div>
        </a-card>

        <a-card size="small" :title="detailsTitle" class="block">
          <div class="facts">
            <div v-for="f in facts" :key="f.label" class="fact">
              <span class="fact-label">{{ f.label }}</span>
              <span class="fact-value">{{ f.value }}</span>
            </div>
          </div>
          <a-empty v-if="!facts.length" :image="simpleEmptyImage" :description="$t('Rental_No_Details')" />

          <template v-if="item.type === 'property' && item.amenities.length">
            <a-divider orientation="left" orientation-margin="0" class="sub-divider">{{ $t('Rental_Amenities') }}</a-divider>
            <div class="amenities">
              <span v-for="a in item.amenities" :key="a" class="amenity"><CheckOutlined /> {{ amenityLabel(a) }}</span>
            </div>
          </template>
        </a-card>

        <a-card v-if="item.description" size="small" :title="$t('Description')" class="block">
          <p class="prose">{{ item.description }}</p>
        </a-card>

        <!-- Every contract this item has been on -->
        <a-card v-if="canContracts" size="small" class="block">
          <template #title>{{ $t('Rental_Rental_History') }}</template>
          <template #extra>
            <a-button v-if="canReserve && !['inactive', 'maintenance'].includes(item.status)" size="small" class="mr" @click="$router.push(`/rental/reservations/create?item=${item.id}`)">
              <template #icon><LockOutlined /></template>
              {{ $t('Rental_Reserve') }}
            </a-button>
            <a-button v-if="canAddContract && item.status !== 'inactive'" size="small" type="primary" ghost @click="$router.push(`/rental/contracts/create?item=${item.id}`)">
              <template #icon><PlusOutlined /></template>
              {{ $t('Rental_New_Contract') }}
            </a-button>
          </template>
          <div v-if="item.contracts.length" class="history">
            <router-link v-for="k in item.contracts" :key="k.id" :to="`/rental/contracts/${k.id}`" class="hist">
              <span class="hist-num">{{ k.contract_number }}</span>
              <span class="hist-body">
                <span class="hist-name">{{ k.customer_name }}</span>
                <span class="hist-dates">{{ date(k.start_date) }} → {{ k.end_date ? date(k.end_date) : $t('Rental_Open_Ended') }}</span>
              </span>
              <span class="hist-amount">{{ money(k.total_amount) }} <small v-if="k.billing_type !== 'upfront'">{{ perLabel(k.rate_period) }}</small></span>
              <a-tag :color="optionOf(CONTRACT_STAGES, k.stage).color">{{ labelOf(CONTRACT_STAGES, k.stage) }}</a-tag>
            </router-link>
          </div>
          <a-empty v-else :image="simpleEmptyImage" :description="$t('Rental_Never_Rented')" />
        </a-card>

        <ReportChart
          v-if="fin && fin.series.some(r => r.income || r.expenses)"
          :data="fin.series"
          :fields="[{ key: 'income', label: $t('Rental_Income') }, { key: 'expenses', label: $t('Rental_Expenses') }]"
          :title="$t('Rental_Income_Vs_Expenses')"
          type="bar" x-key="month" :height="240" :format="money"
        />
      </a-col>

      <!-- Price + status -->
      <a-col :xs="24" :lg="9">
        <a-card size="small" class="block price-card">
          <div class="price-head">
            <a-tag :color="optionOf(RENTAL_STATUSES, item.status).color" class="price-status">
              {{ labelOf(RENTAL_STATUSES, item.status) }}
            </a-tag>
            <a-tag :color="optionOf(RENTAL_CONDITIONS, item.condition).color" :bordered="false">
              {{ $t('Rental_Condition') }}: {{ labelOf(RENTAL_CONDITIONS, item.condition) }}
            </a-tag>
          </div>

          <div class="price-main">
            <template v-if="item.headline_rate !== null">
              <span class="price-amount">{{ money(item.headline_rate) }}</span>
              <span class="price-per">{{ perLabel(item.default_period) }}</span>
            </template>
            <span v-else class="muted">{{ $t('Rental_No_Price') }}</span>
          </div>

          <ul class="rate-list">
            <li v-for="p in otherRates" :key="p.value">
              <span>{{ p.label }}</span>
              <strong>{{ money(p.amount) }}</strong>
            </li>
          </ul>

          <div class="price-meta">
            <div>
              <span class="pm-label">{{ $t('Rental_Security_Deposit') }}</span>
              <span class="pm-value">{{ item.deposit_amount ? money(item.deposit_amount) : '—' }}</span>
            </div>
            <div>
              <span class="pm-label">{{ $t('Rental_Minimum_Rental') }}</span>
              <span class="pm-value">{{ item.min_duration ? `${item.min_duration} ${unitLabel(item.min_duration_unit)}` : '—' }}</span>
            </div>
          </div>
        </a-card>

        <a-card size="small" :title="$t('Rental_Overview')" class="block">
          <a-descriptions :column="1" size="small">
            <a-descriptions-item :label="$t('Code')"><span class="mono">{{ item.code }}</span></a-descriptions-item>
            <a-descriptions-item :label="$t('Category')">{{ item.category_name || '—' }}</a-descriptions-item>
            <a-descriptions-item :label="$t('Warehouse')">{{ item.warehouse_name || $t('Rental_All_Branches') }}</a-descriptions-item>
            <a-descriptions-item v-if="item.type !== 'property'" :label="$t('Quantity')">{{ item.quantity }}</a-descriptions-item>
            <a-descriptions-item :label="$t('Rental_Acquired_On')">{{ item.purchase_date ? date(item.purchase_date) : '—' }}</a-descriptions-item>
            <a-descriptions-item :label="$t('Rental_Acquisition_Cost')">{{ item.purchase_cost !== null ? money(item.purchase_cost) : '—' }}</a-descriptions-item>
          </a-descriptions>
        </a-card>

        <!-- Profit + upkeep -->
        <a-card v-if="fin" size="small" :title="$t('Rental_Profitability')" class="block">
          <div class="pf">
            <div class="pf-main" :class="{ neg: fin.profit < 0 }">
              <span>{{ $t('Rental_Profit') }}</span>
              <strong>{{ money(fin.profit) }}</strong>
            </div>
            <dl class="pf-rows">
              <div><dt>{{ $t('Rental_Income') }}</dt><dd class="ok">{{ money(fin.income) }}</dd></div>
              <div><dt>{{ $t('Rental_Expenses') }}</dt><dd class="bad">{{ money(fin.expenses) }}</dd></div>
              <div v-if="fin.payback !== null"><dt>{{ $t('Rental_Payback') }}</dt><dd>{{ fin.payback }}%</dd></div>
              <div><dt>{{ $t('Rental_Utilization') }}</dt><dd>{{ fin.utilization }}%</dd></div>
            </dl>
            <a-progress v-if="fin.payback !== null" :percent="Math.max(0, Math.min(100, fin.payback))" size="small"
              :stroke-color="fin.payback >= 100 ? '#16a34a' : 'var(--app-primary, #6d28d9)'" :format="() => ''" />
            <p class="pf-note">{{ $t('Rental_Profit_Note') }}</p>
          </div>
          <a-divider class="pf-div" />
          <div class="mt-head">
            <strong>{{ $t('Rental_Maintenance') }}</strong>
            <span>
              <a-tag v-if="fin.maintenance.open" color="warning">{{ fin.maintenance.open }} {{ $t('Rental_Open_Lower') }}</a-tag>
              <router-link v-if="canMaintenance" :to="`/rental/maintenance?item=${id}&stage=all`">{{ $t('Rental_View_All') }}</router-link>
            </span>
          </div>
          <div v-if="fin.maintenance.recent.length" class="mt-list">
            <router-link v-for="m in fin.maintenance.recent" :key="m.id" :to="`/rental/maintenance?job=${m.id}`" class="mt-row">
              <span class="mt-num">{{ m.number }}</span>
              <span class="mt-title">{{ m.title }}</span>
              <a-tag :color="optionOf(MAINT_STATUSES, m.status).color" class="mt-tag">{{ labelOf(MAINT_STATUSES, m.status) }}</a-tag>
            </router-link>
          </div>
          <p v-else class="muted mt-none">{{ $t('Rental_No_Maintenance') }}</p>
        </a-card>

        <a-card v-if="item.notes" size="small" :title="$t('Rental_Internal_Notes')" class="block">
          <p class="prose">{{ item.notes }}</p>
        </a-card>
      </a-col>
    </a-row>
  </div>
</template>

<script setup>
/**
 * One rental item: photo gallery, type-specific details, prices and status.
 * Later phases hang contracts, payments and maintenance off this page.
 */
import { ref, computed, createVNode, onMounted, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { message, Modal, Empty } from 'ant-design-vue';
import {
  ArrowLeftOutlined, EditOutlined, DeleteOutlined, CheckOutlined, ExclamationCircleOutlined, PlusOutlined, LockOutlined,
  ToolOutlined, DollarOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import ReportChart from '../../components/ReportChart.vue';
import { useFormat } from '../../composables/useFormat';
import { useAuthStore } from '../../stores/auth';
import http from '../../lib/http';
import { t } from '../../i18n';
import {
  RENTAL_TYPES, RENTAL_STATUSES, RENTAL_CONDITIONS, RENTAL_PERIODS, CONTRACT_STAGES, MAINT_STATUSES,
  labelOf, optionOf, typeIcon, perLabel, unitLabel, amenityLabel,
} from './rentalOptions';

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();
const { money, number, date } = useFormat();
const simpleEmptyImage = Empty.PRESENTED_IMAGE_SIMPLE;

const canEdit = computed(() => auth.can('rnt_items_edit'));
const canDelete = computed(() => auth.can('rnt_items_delete'));
const canContracts = computed(() => auth.can('rnt_contracts_view'));
const canAddContract = computed(() => auth.can('rnt_contracts_add'));
const canReserve = computed(() => auth.can('rnt_reservations_add'));
const canMaintenance = computed(() => auth.can('rnt_maintenance'));
const canExpenses = computed(() => auth.can('rnt_expenses'));
const fin = ref(null);

async function loadFinancials() {
  try {
    fin.value = await http.get(`rental/items/${id.value}/financials`);
  } catch (e) {
    fin.value = null;
  }
}

const id = computed(() => route.params.id);
const item = ref(null);
const loading = ref(false);
const active = ref(0);

const detailsTitle = computed(() => {
  if (!item.value) return '';
  return item.value.type === 'property'
    ? t('Rental_Property_Details', 'Property details')
    : item.value.type === 'vehicle'
      ? t('Rental_Vehicle_Details', 'Vehicle details')
      : t('Rental_Item_Details', 'Item details');
});

/** Only the facts that are filled in, so a sparse record doesn't read as a wall of dashes. */
const facts = computed(() => {
  const it = item.value;
  if (!it) return [];
  const list = it.type === 'property'
    ? [
      [t('Address', 'Address'), it.address],
      [t('City', 'City'), it.city],
      [t('Rental_Floor', 'Floor'), it.floor],
      [t('Rental_Area', 'Area'), it.area !== null ? `${number(it.area, 0)} ${it.area_unit || ''}`.trim() : null],
      [t('Rental_Bedrooms', 'Bedrooms'), it.bedrooms],
      [t('Rental_Bathrooms', 'Bathrooms'), it.bathrooms],
      [t('Rental_Furnished', 'Furnished'), it.furnished ? t('Yes', 'Yes') : t('No', 'No')],
    ]
    : [
      [t('Brand', 'Brand'), it.brand],
      [t('Rental_Model', 'Model'), it.model],
      [t('Rental_Year', 'Year'), it.year],
      [t('Rental_Serial_Number', 'Serial number'), it.serial_number],
      [t('Rental_Plate_Number', 'Plate number'), it.plate_number],
      [t('Rental_Meter_Reading', 'Meter reading'), it.meter_reading !== null ? `${number(it.meter_reading, 0)} ${it.meter_unit || ''}`.trim() : null],
      [t('Address', 'Address'), it.address],
      [t('City', 'City'), it.city],
    ];
  return list
    .filter(([, value]) => value !== null && value !== undefined && value !== '')
    .map(([label, value]) => ({ label, value }));
});

/** Every offered rate other than the headline one. */
const otherRates = computed(() => {
  const it = item.value;
  if (!it) return [];
  return RENTAL_PERIODS
    .filter(p => p.value !== it.default_period && it.rates?.[p.value] !== null && it.rates?.[p.value] !== undefined)
    .map(p => ({ value: p.value, label: t(p.key, p.label), amount: it.rates[p.value] }));
});

async function load() {
  loading.value = true;
  active.value = 0;
  try {
    const data = await http.get(`rental/items/${id.value}`);
    item.value = data?.item || null;
    if (item.value) loadFinancials();
  } catch (e) {
    item.value = null;
    if (e?.status !== 404) message.error(t('Rental_Err_Could_Not_Load_This_Item', 'Could not load this item'));
  } finally {
    loading.value = false;
  }
}

function remove() {
  Modal.confirm({
    title: t('Delete_Title', 'Are you sure?'),
    icon: createVNode(ExclamationCircleOutlined),
    content: `${t('Delete_Text', "You won't be able to revert this!")} — ${item.value.name}`,
    okText: t('Delete_confirmButtonText', 'Yes, delete it!'),
    okType: 'danger',
    cancelText: t('Delete_cancelButtonText', 'Cancel'),
    async onOk() {
      try {
        await http.delete(`rental/items/${id.value}`);
        message.success(t('Deleted_in_successfully', 'Deleted successfully'));
        router.push('/rental/items');
      } catch (e) {
        message.error(e?.data?.message || t('Rental_Err_Could_Not_Delete_This_Item', 'Could not delete this item'));
      }
    },
  });
}

watch(id, (v) => { if (v) load(); });
onMounted(load);
</script>

<style scoped>
.loading {
  display: flex;
  justify-content: center;
  padding: 96px 0;
}
.block {
  margin-bottom: 16px;
}
.muted {
  opacity: 0.55;
}
.mono {
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
}

/* ---------------- gallery ---------------- */
.gallery-main {
  position: relative;
  height: 380px;
  background: rgba(128, 128, 128, 0.1);
  border-radius: 8px 8px 0 0;
  overflow: hidden;
  display: flex;
  align-items: center;
  justify-content: center;
}
.gallery-main :deep(.ant-image) {
  width: 100%;
  height: 100%;
}
.gallery-main :deep(.ant-image-img) {
  width: 100%;
  height: 100%;
  object-fit: cover;
}
.gallery-empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 10px;
  opacity: 0.35;
}
.gallery-type {
  position: absolute;
  inset-block-start: 12px;
  inset-inline-start: 12px;
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 4px 11px;
  border-radius: 999px;
  font-size: 12px;
  font-weight: 500;
  color: #fff;
  background: rgba(15, 23, 42, 0.62);
  backdrop-filter: blur(4px);
  pointer-events: none;
}
.thumbs {
  display: flex;
  gap: 8px;
  padding: 10px;
  overflow-x: auto;
}
.thumb {
  flex: none;
  width: 72px;
  height: 54px;
  padding: 0;
  border: 2px solid transparent;
  border-radius: 9px;
  overflow: hidden;
  background: none;
  cursor: pointer;
  opacity: 0.65;
  transition: opacity 0.15s ease, border-color 0.15s ease;
}
.thumb img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}
.thumb:hover,
.thumb.active {
  opacity: 1;
}
.thumb.active {
  border-color: var(--app-primary, #6d28d9);
}

/* ---------------- details ---------------- */
.facts {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(170px, 1fr));
  gap: 14px 18px;
}
.fact-label {
  display: block;
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  opacity: 0.55;
}
.fact-value {
  display: block;
  margin-top: 2px;
  font-weight: 500;
  overflow-wrap: anywhere;
}
.sub-divider {
  margin: 18px 0 12px;
  font-size: 13px;
}
.amenities {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}
.amenity {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 4px 11px;
  border-radius: 999px;
  font-size: 12.5px;
  background: rgba(var(--app-primary-rgb, 109, 40, 217), 0.08);
}
.amenity :deep(.anticon) {
  color: var(--app-primary, #6d28d9);
  font-size: 11px;
}
.prose {
  margin: 0;
  white-space: pre-line;
  line-height: 1.6;
}

/* ---------------- price card ---------------- */
.price-head {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}
.price-main {
  display: flex;
  align-items: baseline;
  gap: 6px;
  margin: 16px 0 6px;
}
.price-amount {
  font-size: 30px;
  font-weight: 700;
  line-height: 1.1;
  color: var(--app-primary, #6d28d9);
}
.price-per {
  font-size: 14px;
  opacity: 0.6;
}
.rate-list {
  list-style: none;
  margin: 8px 0 0;
  padding: 0;
}
.rate-list li {
  display: flex;
  justify-content: space-between;
  padding: 7px 0;
  border-bottom: 1px dashed rgba(128, 128, 128, 0.22);
  font-size: 13px;
}
.price-meta {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 10px;
  margin-top: 14px;
}
.price-meta > div {
  padding: 10px 12px;
  border-radius: 10px;
  background: rgba(128, 128, 128, 0.08);
}
.pm-label {
  display: block;
  font-size: 11px;
  opacity: 0.6;
}
.pm-value {
  display: block;
  margin-top: 2px;
  font-weight: 600;
}

.mr {
  margin-inline-end: 6px;
}
/* ---------------- profitability ---------------- */
.pf-main {
  display: flex;
  flex-direction: column;
  padding: 12px 14px;
  border-radius: 12px;
  background: rgba(22, 163, 74, 0.08);
}
.pf-main.neg {
  background: rgba(220, 38, 38, 0.08);
}
.pf-main span {
  font-size: 12px;
  opacity: 0.65;
}
.pf-main strong {
  font-size: 24px;
  color: #16a34a;
}
.pf-main.neg strong {
  color: #dc2626;
}
.pf-rows {
  margin: 10px 0 6px;
}
.pf-rows > div {
  display: flex;
  justify-content: space-between;
  padding: 5px 0;
  font-size: 13px;
  border-bottom: 1px dashed rgba(128, 128, 128, 0.2);
}
.pf-rows dd {
  margin: 0;
  font-weight: 600;
}
.ok {
  color: #16a34a;
}
.bad {
  color: #dc2626;
}
.pf-note {
  margin: 4px 0 0;
  font-size: 11.5px;
  opacity: 0.55;
}
.pf-div {
  margin: 14px 0 10px;
}
.mt-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 6px;
}
.mt-list {
  display: flex;
  flex-direction: column;
}
.mt-row {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 6px 0;
  border-bottom: 1px solid rgba(128, 128, 128, 0.1);
  color: inherit;
  font-size: 12.5px;
}
.mt-num {
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
  font-weight: 600;
}
.mt-title {
  flex: 1;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.mt-tag {
  margin: 0;
}
.mt-none {
  margin: 0;
  font-size: 12.5px;
}

/* ---------------- rental history ---------------- */
.history {
  display: flex;
  flex-direction: column;
}
.hist {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 9px 4px;
  border-bottom: 1px solid rgba(128, 128, 128, 0.12);
  color: inherit;
}
.hist:last-child {
  border-bottom: 0;
}
.hist:hover .hist-name {
  color: var(--app-primary, #6d28d9);
}
.hist-num {
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
  font-size: 12.5px;
  font-weight: 600;
  min-width: 82px;
}
.hist-body {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
}
.hist-name {
  font-weight: 500;
}
.hist-dates {
  font-size: 12px;
  opacity: 0.6;
}
.hist-amount {
  font-size: 13px;
  white-space: nowrap;
}
.hist-amount small {
  opacity: 0.6;
}

@media (max-width: 767px) {
  .hist-amount {
    display: none;
  }
  .gallery-main {
    height: 240px;
  }
}
</style>
