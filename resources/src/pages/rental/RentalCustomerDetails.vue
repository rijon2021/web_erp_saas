<template>
  <div class="page">
    <PageHeader
      :title="c ? c.name : $t('Rental_Customer')"
      :breadcrumb="[$t('Rental_Management'), $t('Rental_Customers'), c ? c.name : '']"
    >
      <template #actions>
        <a-button @click="$router.push('/rental/customers')">
          <template #icon><ArrowLeftOutlined /></template>
          {{ $t('Back') }}
        </a-button>
        <template v-if="c">
          <a-button v-if="canReports" @click="$router.push(`/rental/customers/${c.id}/statement`)">
            <template #icon><FileTextOutlined /></template>
            {{ $t('Rental_Statement') }}
          </a-button>
          <a-button v-if="canClient" @click="$router.push(`/customers/${c.client_id}/details`)">
            <template #icon><UserOutlined /></template>
            {{ $t('Rental_Customer_Record') }}
          </a-button>
          <a-button v-if="canEdit && !c.is_blacklisted" danger @click="openBlacklist">
            <template #icon><StopOutlined /></template>
            {{ $t('Rental_Blacklist') }}
          </a-button>
          <a-button v-if="canDelete" danger type="text" @click="remove">
            <template #icon><DeleteOutlined /></template>
          </a-button>
          <a-button v-if="canEdit" type="primary" @click="$router.push(`/rental/customers/${id}/edit`)">
            <template #icon><EditOutlined /></template>
            {{ $t('Edit') }}
          </a-button>
        </template>
      </template>
    </PageHeader>

    <div v-if="loading" class="loading"><a-spin size="large" /></div>

    <a-result v-else-if="!c" status="404" :title="$t('Rental_Customer_Not_Found')">
      <template #extra>
        <a-button type="primary" @click="$router.push('/rental/customers')">{{ $t('Rental_Customers') }}</a-button>
      </template>
    </a-result>

    <template v-else>
      <!-- Blacklist banner -->
      <a-alert v-if="c.is_blacklisted" type="error" show-icon class="block bl-banner">
        <template #message>
          <strong>{{ $t('Rental_Blacklisted_Banner') }}</strong>
        </template>
        <template #description>
          <span class="bl-reason">{{ c.blacklist_reason || '—' }}</span>
          <span v-if="c.blacklisted_at" class="bl-when">{{ $t('Rental_Since') }} {{ date(c.blacklisted_at) }}</span>
        </template>
        <template v-if="canEdit" #action>
          <a-button size="small" :loading="blSaving" @click="clearBlacklist">{{ $t('Rental_Remove_Blacklist') }}</a-button>
        </template>
      </a-alert>

      <a-row :gutter="16">
        <!-- Profile card -->
        <a-col :xs="24" :lg="8">
          <a-card size="small" class="block profile">
            <div class="profile-top">
              <a-avatar :size="72" :style="{ background: avatarColor(c.name) }" class="profile-avatar">{{ initials(c.name) }}</a-avatar>
              <h2 class="profile-name">{{ c.name }}</h2>
              <span class="profile-code">#{{ c.client_code }}</span>
              <div class="profile-tags">
                <a-tag :color="optionOf(ID_STATUSES, c.id_status).color">{{ labelOf(ID_STATUSES, c.id_status) }}</a-tag>
                <a-tag v-if="c.is_blacklisted" color="error">{{ $t('Rental_Blacklisted') }}</a-tag>
              </div>
              <a-rate v-if="c.rating" :value="c.rating" disabled class="profile-rate" />
            </div>

            <ul class="contact">
              <li v-if="c.phone"><PhoneOutlined /><a :href="`tel:${c.phone}`">{{ c.phone }}</a></li>
              <li v-if="c.email"><MailOutlined /><a :href="`mailto:${c.email}`">{{ c.email }}</a></li>
              <li v-if="address"><EnvironmentOutlined /><span>{{ address }}</span></li>
              <li v-if="!c.phone && !c.email && !address" class="muted">{{ $t('Rental_No_Contact') }}</li>
            </ul>
          </a-card>

          <!-- What they owe -->
          <a-card v-if="canBilling && c.billing" size="small" :title="$t('Rental_Account_Balance')" class="block">
            <div class="bal" :class="{ due: c.billing.outstanding > 0, late: c.billing.overdue > 0 }">
              <span class="bal-label">{{ $t('Rental_Outstanding') }}</span>
              <span class="bal-value">{{ money(c.billing.outstanding) }}</span>
              <span v-if="c.billing.overdue" class="bal-late">{{ $t('Rental_Overdue_Amount') }}: {{ money(c.billing.overdue) }}</span>
            </div>
            <dl class="bal-rows">
              <div><dt>{{ $t('Rental_Billed') }}</dt><dd>{{ money(c.billing.billed) }}</dd></div>
              <div><dt>{{ $t('Paid') }}</dt><dd>{{ money(c.billing.paid) }}</dd></div>
              <div v-if="c.billing.late_fees"><dt>{{ $t('Rental_Late_Fees_Open') }}</dt><dd>{{ money(c.billing.late_fees) }}</dd></div>
            </dl>
            <div class="bal-actions">
              <router-link :to="`/rental/invoices?customer=${c.id}&stage=all`">{{ $t('Rental_View_Invoices') }}</router-link>
              <a-button v-if="canPay && c.billing.outstanding > 0" type="primary" size="small" @click="payOpen = true">
                <template #icon><DollarOutlined /></template>
                {{ $t('Rental_Record_Payment') }}
              </a-button>
            </div>
          </a-card>

          <a-card v-if="c.notes" size="small" :title="$t('Rental_Internal_Notes')" class="block">
            <p class="prose">{{ c.notes }}</p>
          </a-card>
        </a-col>

        <!-- Details + documents -->
        <a-col :xs="24" :lg="16">
          <a-card size="small" class="block">
            <a-tabs v-model:activeKey="tab">
              <a-tab-pane key="overview" :tab="$t('Rental_Overview')">
                <!-- Identity -->
                <section class="section">
                  <h3 class="section-title"><IdcardOutlined /> {{ $t('Rental_Identity') }}</h3>
                  <div class="facts">
                    <div v-for="f in identityFacts" :key="f.label" class="fact">
                      <span class="fact-label">{{ f.label }}</span>
                      <span class="fact-value">{{ f.value }}</span>
                    </div>
                  </div>
                  <div v-if="c.id_front_url || c.id_back_url" class="scans">
                    <template v-for="side in scanSides" :key="side.field">
                      <figure v-if="side.url" class="scan">
                        <a-image v-if="side.isImage" :src="side.url" :alt="side.label" />
                        <a v-else :href="side.url" target="_blank" rel="noopener" class="scan-file"><FilePdfOutlined /> {{ $t('PDF') }}</a>
                        <figcaption>{{ side.label }}</figcaption>
                      </figure>
                    </template>
                  </div>
                </section>

                <!-- Work -->
                <section class="section">
                  <h3 class="section-title"><ShopOutlined /> {{ $t('Rental_Work') }}</h3>
                  <div v-if="workFacts.length" class="facts">
                    <div v-for="f in workFacts" :key="f.label" class="fact">
                      <span class="fact-label">{{ f.label }}</span>
                      <span class="fact-value">{{ f.value }}</span>
                    </div>
                  </div>
                  <p v-else class="muted none">{{ $t('Rental_Not_Provided') }}</p>
                </section>

                <a-row :gutter="16">
                  <a-col :xs="24" :md="12">
                    <section class="section person-box">
                      <h3 class="section-title"><AlertOutlined /> {{ $t('Rental_Emergency_Contact') }}</h3>
                      <template v-if="c.emergency_name || c.emergency_phone">
                        <strong>{{ c.emergency_name || '—' }}</strong>
                        <span v-if="c.emergency_relation" class="muted"> · {{ c.emergency_relation }}</span>
                        <a v-if="c.emergency_phone" :href="`tel:${c.emergency_phone}`" class="person-phone"><PhoneOutlined /> {{ c.emergency_phone }}</a>
                      </template>
                      <p v-else class="muted none">{{ $t('Rental_Not_Provided') }}</p>
                    </section>
                  </a-col>
                  <a-col :xs="24" :md="12">
                    <section class="section person-box">
                      <h3 class="section-title"><SafetyCertificateOutlined /> {{ $t('Rental_Guarantor') }}</h3>
                      <template v-if="c.guarantor_name || c.guarantor_phone">
                        <strong>{{ c.guarantor_name || '—' }}</strong>
                        <span v-if="c.guarantor_relation" class="muted"> · {{ c.guarantor_relation }}</span>
                        <a v-if="c.guarantor_phone" :href="`tel:${c.guarantor_phone}`" class="person-phone"><PhoneOutlined /> {{ c.guarantor_phone }}</a>
                        <span v-if="c.guarantor_id_number" class="person-line">{{ $t('Rental_ID_Number') }}: <span class="mono">{{ c.guarantor_id_number }}</span></span>
                        <span v-if="c.guarantor_address" class="person-line">{{ c.guarantor_address }}</span>
                      </template>
                      <p v-else class="muted none">{{ $t('Rental_Not_Provided') }}</p>
                    </section>
                  </a-col>
                </a-row>
              </a-tab-pane>

              <a-tab-pane v-if="canContracts" key="contracts">
                <template #tab>
                  {{ $t('Rental_Contracts') }}
                  <a-badge :count="contracts.length" :number-style="badgeStyle" />
                </template>
                <div class="docs-head">
                  <span class="muted">{{ $t('Rental_Customer_Contracts_Help') }}</span>
                  <a-button v-if="canAddContract && !c.is_blacklisted" type="primary" @click="$router.push(`/rental/contracts/create?customer=${c.id}`)">
                    <template #icon><PlusOutlined /></template>
                    {{ $t('Rental_New_Contract') }}
                  </a-button>
                </div>
                <div v-if="contracts.length" class="docs">
                  <router-link v-for="k in contracts" :key="k.id" :to="`/rental/contracts/${k.id}`" class="doc contract">
                    <span class="k-num">{{ k.contract_number }}</span>
                    <span class="doc-body">
                      <span class="doc-title">{{ k.items_summary.join(', ') }}</span>
                      <span class="doc-meta">{{ date(k.start_date) }} → {{ k.end_date ? date(k.end_date) : $t('Rental_Open_Ended') }}</span>
                    </span>
                    <span class="k-amount">{{ money(k.total_amount) }} <small>{{ perLabel(k.rate_period) }}</small></span>
                    <a-tag :color="optionOf(CONTRACT_STAGES, k.stage).color">{{ labelOf(CONTRACT_STAGES, k.stage) }}</a-tag>
                  </router-link>
                </div>
                <a-empty v-else :image="simpleEmptyImage" :description="$t('Rental_No_Contracts_Yet')" />
              </a-tab-pane>

              <a-tab-pane key="documents">
                <template #tab>
                  {{ $t('Rental_Documents') }}
                  <a-badge :count="documents.length" :number-style="badgeStyle" />
                </template>

                <div class="docs-head">
                  <span class="muted">{{ $t('Rental_Documents_Help') }}</span>
                  <a-button v-if="canEdit" type="primary" @click="docModal = true">
                    <template #icon><UploadOutlined /></template>
                    {{ $t('Rental_Upload_Document') }}
                  </a-button>
                </div>

                <div v-if="documents.length" class="docs">
                  <div v-for="d in documents" :key="d.id" class="doc">
                    <span class="doc-ic" :class="docKind(d)"><component :is="docIcon(d)" /></span>
                    <span class="doc-body">
                      <a :href="d.url" target="_blank" rel="noopener" class="doc-title">{{ d.title }}</a>
                      <span class="doc-meta">{{ fileSize(d.size) }} · {{ date(d.created_at) }}</span>
                    </span>
                    <a-space :size="0">
                      <a-tooltip :title="$t('Rental_Open')">
                        <a-button type="text" size="small" :href="d.url" target="_blank">
                          <template #icon><ExportOutlined /></template>
                        </a-button>
                      </a-tooltip>
                      <a-tooltip v-if="canEdit" :title="$t('Delete')">
                        <a-button type="text" size="small" danger @click="removeDocument(d)">
                          <template #icon><DeleteOutlined /></template>
                        </a-button>
                      </a-tooltip>
                    </a-space>
                  </div>
                </div>
                <a-empty v-else :image="simpleEmptyImage" :description="$t('Rental_No_Documents')" />
              </a-tab-pane>
            </a-tabs>
          </a-card>
        </a-col>
      </a-row>
    </template>

    <RentalPaymentModal v-if="c && c.billing" v-model:open="payOpen" :customer-id="c.id" :owed="c.billing.outstanding || null" @saved="load" />

    <!-- Blacklist modal -->
    <a-modal
      v-model:open="blModal" :title="$t('Rental_Blacklist_Customer')" :ok-text="$t('Rental_Blacklist')"
      :cancel-text="$t('Cancel')" :ok-button-props="{ danger: true }" :confirm-loading="blSaving" @ok="saveBlacklist"
    >
      <p class="muted">{{ $t('Rental_Blacklist_Help') }}</p>
      <a-form layout="vertical">
        <a-form-item :label="$t('Rental_Blacklist_Reason')" required>
          <a-textarea v-model:value="blReason" :rows="4" />
        </a-form-item>
      </a-form>
    </a-modal>

    <!-- Upload document modal -->
    <a-modal
      v-model:open="docModal" :title="$t('Rental_Upload_Document')" :ok-text="$t('Rental_Upload')"
      :cancel-text="$t('Cancel')" :confirm-loading="docSaving" @ok="uploadDocument" @after-close="resetDoc"
    >
      <a-form layout="vertical" style="margin-top: 12px">
        <a-form-item :label="$t('Rental_Document_Title')">
          <a-input v-model:value="docTitle" :placeholder="$t('Rental_Document_Title_Placeholder')" />
        </a-form-item>
        <a-form-item :label="$t('Rental_File')" required>
          <a-upload-dragger :show-upload-list="false" :before-upload="pickDoc" accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx,.xls,.xlsx,.txt">
            <p class="dz-ic"><InboxOutlined /></p>
            <p v-if="docFile" class="dz-title">{{ docFile.name }} <span class="muted">({{ fileSize(docFile.size) }})</span></p>
            <p v-else class="dz-title">{{ $t('Rental_Drop_File') }}</p>
            <p class="dz-hint">{{ $t('Rental_Document_Types') }}</p>
          </a-upload-dragger>
        </a-form-item>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * One rental customer: profile, identity (with ID scans), work, emergency
 * contact, guarantor, standing and documents. Later phases add contracts,
 * payments and balance tabs here.
 */
import { ref, computed, createVNode, onMounted, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { message, Modal, Empty } from 'ant-design-vue';
import {
  ArrowLeftOutlined, EditOutlined, DeleteOutlined, StopOutlined, UserOutlined,
  PhoneOutlined, MailOutlined, EnvironmentOutlined, IdcardOutlined, ShopOutlined,
  AlertOutlined, SafetyCertificateOutlined, UploadOutlined, ExportOutlined,
  FilePdfOutlined, FileImageOutlined, FileWordOutlined, FileExcelOutlined, FileTextOutlined,
  InboxOutlined, ExclamationCircleOutlined, PlusOutlined, DollarOutlined,
} from '@ant-design/icons-vue';
import RentalPaymentModal from './RentalPaymentModal.vue';
import PageHeader from '../../components/PageHeader.vue';
import { useFormat } from '../../composables/useFormat';
import { useAuthStore } from '../../stores/auth';
import { uploadForm } from '../../lib/upload';
import http from '../../lib/http';
import { t } from '../../i18n';
import {
  ID_TYPES, ID_STATUSES, CONTRACT_STAGES, labelOf, optionOf, initials, avatarColor, perLabel,
} from './rentalOptions';

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();
const { money, date } = useFormat();
const simpleEmptyImage = Empty.PRESENTED_IMAGE_SIMPLE;
const badgeStyle = { backgroundColor: 'rgba(128,128,128,.25)', color: 'inherit', boxShadow: 'none', marginInlineStart: '6px' };

const canEdit = computed(() => auth.can('rnt_customers_edit'));
const canDelete = computed(() => auth.can('rnt_customers_delete'));
const canClient = computed(() => auth.can('Customers_view'));
const canContracts = computed(() => auth.can('rnt_contracts_view'));
const canAddContract = computed(() => auth.can('rnt_contracts_add'));
const canBilling = computed(() => auth.can('rnt_billing_view'));
const canReports = computed(() => auth.can('rnt_reports'));
const canPay = computed(() => auth.can('rnt_payments_add'));
const payOpen = ref(false);

const id = computed(() => route.params.id);
const c = ref(null);
const loading = ref(false);
const tab = ref('overview');
const documents = computed(() => c.value?.documents || []);
const contracts = computed(() => c.value?.contracts || []);

const address = computed(() => [c.value?.adresse, c.value?.city, c.value?.country].filter(Boolean).join(', '));

function keep(list) {
  return list
    .filter(([, value]) => value !== null && value !== undefined && value !== '')
    .map(([label, value]) => ({ label, value }));
}

const identityFacts = computed(() => {
  const x = c.value;
  if (!x) return [];
  const facts = keep([
    [t('Rental_ID_Type', 'ID type'), x.id_type ? labelOf(ID_TYPES, x.id_type) : null],
    [t('Rental_ID_Number', 'ID number'), x.id_number],
    [t('Rental_ID_Expiry', 'ID expiry'), x.id_expiry ? date(x.id_expiry) : null],
    [t('Rental_Date_Of_Birth', 'Date of birth'), x.date_of_birth ? date(x.date_of_birth) : null],
    [t('Rental_Nationality', 'Nationality'), x.nationality],
  ]);
  return facts.length ? facts : [{ label: t('Rental_ID_Document', 'ID document'), value: t('Rental_Not_Provided', 'Not provided') }];
});

const workFacts = computed(() => {
  const x = c.value;
  if (!x) return [];
  return keep([
    [t('Rental_Occupation', 'Occupation'), x.occupation],
    [t('Rental_Employer', 'Employer'), x.employer],
    [t('Rental_Employer_Phone', 'Employer phone'), x.employer_phone],
    [t('Rental_Monthly_Income', 'Monthly income'), x.monthly_income !== null ? money(x.monthly_income) : null],
  ]);
});

const scanSides = computed(() => [
  { field: 'front', label: t('Rental_ID_Front', 'Front'), url: c.value?.id_front_url, isImage: !/\.pdf$/i.test(c.value?.id_front_url || '') },
  { field: 'back', label: t('Rental_ID_Back', 'Back'), url: c.value?.id_back_url, isImage: !/\.pdf$/i.test(c.value?.id_back_url || '') },
]);

async function load() {
  loading.value = true;
  try {
    const data = await http.get(`rental/customers/${id.value}`);
    c.value = data?.customer || null;
  } catch (e) {
    c.value = null;
    if (e?.status !== 404) message.error(t('Gen_Could_Not_Load_Customer', 'Could not load this customer'));
  } finally {
    loading.value = false;
  }
}

// ----- blacklist -----
const blModal = ref(false);
const blReason = ref('');
const blSaving = ref(false);

function openBlacklist() {
  blReason.value = '';
  blModal.value = true;
}

async function saveBlacklist() {
  if (!blReason.value.trim()) {
    message.error(t('Rental_Blacklist_Reason_Required', 'Please give a reason'));
    return;
  }
  // Close only when it saved, so a typed reason isn't lost on an error.
  if (await setBlacklist(true, blReason.value.trim())) blModal.value = false;
}

function clearBlacklist() {
  setBlacklist(false, null);
}

async function setBlacklist(blacklisted, reason) {
  blSaving.value = true;
  try {
    await http.post(`rental/customers/${id.value}/blacklist`, { blacklisted, reason });
    message.success(t('Updated_in_successfully', 'Updated successfully'));
    await load();
    return true;
  } catch (e) {
    message.error(e?.data?.message || t('Gen_Could_Not_Update', 'Could not update'));
    return false;
  } finally {
    blSaving.value = false;
  }
}

// ----- documents -----
const docModal = ref(false);
const docTitle = ref('');
const docFile = ref(null);
const docSaving = ref(false);

function pickDoc(file) {
  if (file.size > 10 * 1024 * 1024) {
    message.error(t('Rental_Document_Too_Large', 'Files must be 10 MB or smaller'));
    return false;
  }
  docFile.value = file;
  return false;
}

function resetDoc() {
  docTitle.value = '';
  docFile.value = null;
}

async function uploadDocument() {
  if (!docFile.value) {
    message.error(t('Rental_Choose_File', 'Choose a file first'));
    return;
  }
  const fd = new FormData();
  fd.append('file', docFile.value);
  if (docTitle.value.trim()) fd.append('title', docTitle.value.trim());
  docSaving.value = true;
  try {
    const res = await uploadForm(`rental/customers/${id.value}/documents`, fd);
    if (res.status >= 200 && res.status < 300) {
      message.success(t('Uploaded', 'Uploaded'));
      docModal.value = false;
      await load();
      tab.value = 'documents';
    } else {
      const errors = res.data?.errors;
      message.error((errors && Object.values(errors)[0]?.[0]) || res.data?.message || t('Upload_failed', 'Upload failed'));
    }
  } catch (e) {
    message.error(t('Upload_failed', 'Upload failed'));
  } finally {
    docSaving.value = false;
  }
}

function removeDocument(doc) {
  Modal.confirm({
    title: t('Delete_Title', 'Are you sure?'),
    icon: createVNode(ExclamationCircleOutlined),
    content: doc.title,
    okText: t('Delete_confirmButtonText', 'Yes, delete it!'),
    okType: 'danger',
    cancelText: t('Delete_cancelButtonText', 'Cancel'),
    async onOk() {
      try {
        await http.delete(`rental/customers/${id.value}/documents/${doc.id}`);
        message.success(t('Deleted_Successfully', 'Deleted successfully'));
        await load();
      } catch (e) {
        message.error(t('DocArc_Could_Not_Delete_Document', 'Could not delete this document'));
      }
    },
  });
}

function docKind(d) {
  const m = `${d.mime || ''} ${d.original_name || ''}`.toLowerCase();
  if (m.includes('pdf')) return 'pdf';
  if (m.includes('image')) return 'img';
  if (m.includes('word') || /\.docx?\b/.test(m)) return 'word';
  if (m.includes('sheet') || m.includes('excel') || /\.xlsx?\b/.test(m)) return 'excel';
  return 'text';
}

function docIcon(d) {
  return { pdf: FilePdfOutlined, img: FileImageOutlined, word: FileWordOutlined, excel: FileExcelOutlined }[docKind(d)] || FileTextOutlined;
}

function fileSize(bytes) {
  const n = Number(bytes) || 0;
  if (n >= 1024 * 1024) return `${(n / 1024 / 1024).toFixed(1)} MB`;
  if (n >= 1024) return `${Math.round(n / 1024)} KB`;
  return `${n} B`;
}

// ----- delete -----
function remove() {
  Modal.confirm({
    title: t('Rental_Remove_From_Rentals', 'Remove from rental customers?'),
    icon: createVNode(ExclamationCircleOutlined),
    content: t('Rental_Remove_Note', 'the customer record itself is kept'),
    okText: t('Delete_confirmButtonText', 'Yes, delete it!'),
    okType: 'danger',
    cancelText: t('Delete_cancelButtonText', 'Cancel'),
    async onOk() {
      try {
        await http.delete(`rental/customers/${id.value}`);
        message.success(t('Deleted_Successfully', 'Deleted successfully'));
        router.push('/rental/customers');
      } catch (e) {
        message.error(e?.data?.message || t('Rental_Could_Not_Remove_Customer', 'Could not remove this customer'));
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
  opacity: 0.6;
}
.mono {
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
}
.prose {
  margin: 0;
  white-space: pre-line;
  line-height: 1.6;
}

/* blacklist banner */
.bl-reason {
  display: block;
  white-space: pre-line;
}
.bl-when {
  display: block;
  margin-top: 4px;
  font-size: 12px;
  opacity: 0.7;
}

/* profile */
.profile-top {
  display: flex;
  flex-direction: column;
  align-items: center;
  text-align: center;
  padding: 10px 0 14px;
  border-bottom: 1px solid rgba(128, 128, 128, 0.15);
}
.profile-avatar {
  font-size: 26px;
  font-weight: 600;
}
.profile-name {
  margin: 12px 0 0;
  font-size: 19px;
  font-weight: 600;
}
.profile-code {
  font-size: 12px;
  opacity: 0.55;
}
.profile-tags {
  display: flex;
  flex-wrap: wrap;
  justify-content: center;
  gap: 4px;
  margin-top: 10px;
}
.profile-rate {
  margin-top: 6px;
  font-size: 16px;
}
.contact {
  list-style: none;
  margin: 0;
  padding: 12px 4px 4px;
  display: flex;
  flex-direction: column;
  gap: 10px;
}
.contact li {
  display: flex;
  align-items: flex-start;
  gap: 10px;
  overflow-wrap: anywhere;
}
.contact :deep(.anticon) {
  margin-top: 3px;
  color: var(--app-primary, #6d28d9);
}

/* balance */
.bal {
  display: flex;
  flex-direction: column;
  padding: 14px;
  border-radius: 12px;
  background: rgba(22, 163, 74, 0.08);
}
.bal.due {
  background: rgba(var(--app-primary-rgb, 109, 40, 217), 0.08);
}
.bal.late {
  background: rgba(220, 38, 38, 0.08);
}
.bal-label {
  font-size: 12px;
  opacity: 0.65;
}
.bal-value {
  font-size: 24px;
  font-weight: 700;
}
.bal.late .bal-value,
.bal-late {
  color: #dc2626;
}
.bal-late {
  font-size: 12.5px;
  font-weight: 500;
}
.bal-rows {
  margin: 10px 0 0;
}
.bal-rows > div {
  display: flex;
  justify-content: space-between;
  padding: 5px 0;
  font-size: 13px;
  border-bottom: 1px dashed rgba(128, 128, 128, 0.2);
}
.bal-rows dd {
  margin: 0;
  font-weight: 500;
}
.bal-actions {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-top: 12px;
}

/* overview sections */
.section {
  margin-bottom: 20px;
}
.section-title {
  display: flex;
  align-items: center;
  gap: 8px;
  margin: 0 0 12px;
  font-size: 14px;
  font-weight: 600;
}
.section-title :deep(.anticon) {
  color: var(--app-primary, #6d28d9);
}
.facts {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
  gap: 12px 18px;
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
.none {
  margin: 0;
}
.scans {
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  margin-top: 14px;
}
.scan {
  margin: 0;
  width: 220px;
}
.scan :deep(.ant-image),
.scan :deep(.ant-image-img) {
  width: 220px;
  height: 139px;
  object-fit: cover;
  border-radius: 10px;
}
.scan figcaption {
  margin-top: 4px;
  font-size: 12px;
  opacity: 0.6;
}
.scan-file {
  width: 220px;
  height: 139px;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  border: 1px solid rgba(128, 128, 128, 0.25);
  border-radius: 10px;
  font-weight: 600;
}
.person-box {
  padding: 14px;
  border-radius: 12px;
  background: rgba(128, 128, 128, 0.06);
}
.person-phone,
.person-line {
  display: flex;
  align-items: center;
  gap: 6px;
  margin-top: 6px;
  font-size: 13px;
}

/* documents */
.docs-head {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  margin-bottom: 14px;
}
.docs {
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.doc {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 10px 12px;
  border: 1px solid rgba(128, 128, 128, 0.18);
  border-radius: 12px;
}
.doc-ic {
  width: 38px;
  height: 38px;
  flex: none;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 10px;
  font-size: 18px;
  color: #475569;
  background: rgba(71, 85, 105, 0.12);
}
.doc-ic.pdf {
  color: #dc2626;
  background: rgba(220, 38, 38, 0.1);
}
.doc-ic.img {
  color: #0891b2;
  background: rgba(8, 145, 178, 0.1);
}
.doc-ic.word {
  color: #2563eb;
  background: rgba(37, 99, 235, 0.1);
}
.doc-ic.excel {
  color: #16a34a;
  background: rgba(22, 163, 74, 0.1);
}
.doc-body {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
}
.doc-title {
  font-weight: 500;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.doc-meta {
  font-size: 12px;
  opacity: 0.55;
}
.doc.contract {
  color: inherit;
}
.doc.contract:hover {
  border-color: rgba(var(--app-primary-rgb, 109, 40, 217), 0.5);
}
.k-num {
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
  font-size: 12.5px;
  font-weight: 600;
  min-width: 82px;
}
.k-amount {
  font-size: 13px;
  white-space: nowrap;
}
.k-amount small {
  opacity: 0.6;
}
.dz-ic {
  margin: 0;
  font-size: 28px;
  color: var(--app-primary, #6d28d9);
}
.dz-title {
  margin: 4px 0 2px;
  font-weight: 500;
}
.dz-hint {
  margin: 0;
  font-size: 12px;
  opacity: 0.55;
}
</style>
