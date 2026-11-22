<template>
  <div class="page">
    <div v-if="loading" style="display: flex; justify-content: center; padding: 96px 0">
      <a-spin size="large" />
    </div>

    <template v-else>
      <!-- Document type -->
      <div class="pdfc-top">
        <a-segmented v-model:value="docType" :options="typeOptions" @change="load" />
        <a-space>
          <a-button @click="resetDefaults">{{ $t('Reset_to_Default') }}</a-button>
          <a-button type="primary" :loading="saving" @click="save">
            <template #icon><SaveOutlined /></template>
            {{ $t('submit') }}
          </a-button>
        </a-space>
      </div>

      <a-row :gutter="16">
        <!-- ============================ Controls ============================ -->
        <a-col :xs="24" :lg="10" :xl="9">
          <a-collapse v-model:activeKey="openPanels" :bordered="false" class="pdfc-panels">
            <a-collapse-panel key="colors" :header="$t('Set_Pdf_Colors')">
              <div v-for="c in colorFields" :key="c.key" class="pdfc-row">
                <span>{{ c.label }}</span>
                <input
                  type="color" class="pdfc-color"
                  :value="form[c.key]"
                  @input="e => (form[c.key] = e.target.value)"
                />
              </div>
            </a-collapse-panel>

            <a-collapse-panel key="type" :header="$t('Set_Pdf_Typography')">
              <div class="pdfc-row">
                <span>{{ $t('Font_Family') }}</span>
                <a-select v-model:value="form.font_family" style="width: 190px" :options="fontOptions" />
              </div>
              <div class="pdfc-row">
                <span>{{ $t('Set_Font_Size_Pt') }}</span>
                <a-input-number v-model:value="form.font_size" :min="7" :max="14" />
              </div>
              <a-alert
                type="info" show-icon style="margin-top: 8px"
                :message="$t('Set_Pdf_Fonts_Alert')"
              />
            </a-collapse-panel>

            <a-collapse-panel key="layout" :header="$t('Set_Pdf_Layout_Logo')">
              <div class="pdfc-row">
                <span>{{ $t('Set_Pdf_Margin_V') }}</span>
                <a-input-number v-model:value="form.margin_v" :min="0" :max="40" />
              </div>
              <div class="pdfc-row">
                <span>{{ $t('Set_Pdf_Margin_H') }}</span>
                <a-input-number v-model:value="form.margin_h" :min="0" :max="40" />
              </div>
              <div class="pdfc-row">
                <span>{{ $t('Show_Logo') }}</span>
                <a-switch v-model:checked="form.logo_show" />
              </div>
              <div class="pdfc-row" :class="{ 'pdfc-row--off': !form.logo_show }">
                <span>{{ $t('Set_Logo_Width_Px') }}</span>
                <a-slider v-model:value="form.logo_width" :min="40" :max="400" style="width: 180px" />
              </div>
              <div class="pdfc-row" :class="{ 'pdfc-row--off': !form.logo_show }">
                <span>{{ $t('Set_Logo_Height_Px') }}</span>
                <a-slider v-model:value="form.logo_height" :min="20" :max="300" style="width: 180px" />
              </div>
            </a-collapse-panel>

            <a-collapse-panel key="table" :header="$t('Set_Pdf_Items_Table')">
              <div class="pdfc-row">
                <span>{{ $t('Set_Outer_Border') }}</span>
                <a-switch v-model:checked="form.table_borders" />
              </div>
              <div class="pdfc-row">
                <span>{{ $t('Set_Striped_Rows') }}</span>
                <a-switch v-model:checked="form.table_striped" />
              </div>
            </a-collapse-panel>

            <a-collapse-panel key="sections" :header="$t('Sections')">
              <div v-for="sct in sectionFields" :key="sct.key" class="pdfc-row">
                <span>{{ sct.label }}</span>
                <a-switch v-model:checked="form[sct.key]" />
              </div>
            </a-collapse-panel>

            <a-collapse-panel key="text" :header="$t('Set_Pdf_Text_Labels')">
              <div class="pdfc-field">
                <div class="pdfc-label">{{ $t('Set_Pdf_Doc_Title_Hint') }}</div>
                <a-input v-model:value="form.labels.title" :placeholder="defaultTitle" />
              </div>
              <div class="pdfc-field">
                <div class="pdfc-label">{{ $t('Set_Pdf_Thank_You_Hint') }}</div>
                <a-input v-model:value="form.labels.thank_you" :placeholder="$t('Set_Pdf_Thank_You_Default')" />
              </div>
              <div v-if="docType === 'sale' && form.show_signature" class="pdfc-field">
                <div class="pdfc-label">{{ $t('Set_Signature_Label') }}</div>
                <a-input v-model:value="form.labels.signature" :placeholder="$t('Customer_Signature')" />
              </div>
              <div class="pdfc-field">
                <div class="pdfc-label">{{ $t('Set_Pdf_Footer_Text_Hint') }}</div>
                <a-textarea v-model:value="form.footer_text" :rows="3" />
              </div>
            </a-collapse-panel>
          </a-collapse>
        </a-col>

        <!-- ============================ Live preview ============================ -->
        <a-col :xs="24" :lg="14" :xl="15">
          <div class="pdfc-preview-wrap">
            <div class="pdfc-preview-bar">
              <EyeOutlined /> {{ $t('Set_Live_Preview_Updates') }}
            </div>
            <div class="pdfc-page" :style="pageStyle">
              <!-- Header -->
              <div class="pv-head">
                <div
                  v-if="form.logo_show" class="pv-logo"
                  :style="{ width: form.logo_width / 2 + 'px', height: form.logo_height / 2 + 'px' }"
                >{{ $t('Rcpt_Logo') }}</div>
                <div class="pv-head-right">
                  <div class="pv-title" :style="{ color: form.primary_color }">{{ form.labels.title || defaultTitle }}</div>
                  <div class="pv-ref">INV-2941</div>
                  <div v-if="form.show_status" class="pv-badges">
                    <span class="pv-badge" style="background: #d1fae5; color: #065f46">{{ $t('Completed').toUpperCase() }}</span>
                    <span class="pv-badge" style="background: #dbeafe; color: #1e40af">{{ $t('Partial').toUpperCase() }}</span>
                  </div>
                </div>
              </div>
              <div class="pv-rule" :style="{ background: form.primary_color }"></div>

              <!-- Party boxes -->
              <div class="pv-boxes">
                <div v-if="form.show_customer" class="pv-box">
                  <div class="pv-box-head" :style="{ background: form.primary_color, borderColor: form.secondary_color }">
                    {{ (docType === 'purchase' ? $t('Supplier') : $t('Set_Bill_To')).toUpperCase() }}
                  </div>
                  <div class="pv-box-body" :style="{ color: form.text_color }">
                    <b>Sarah Miller</b>
                    <div class="pv-dim">+212 600-000-000 · sarah@mail.com</div>
                    <div class="pv-dim">12 Market Street, Casablanca</div>
                  </div>
                </div>
                <div v-if="form.show_company" class="pv-box">
                  <div class="pv-box-head" :style="{ background: form.primary_color, borderColor: form.secondary_color }">{{ $t('From').toUpperCase() }}</div>
                  <div class="pv-box-body" :style="{ color: form.text_color }">
                    <b>{{ auth.companyName || $t('Set_Your_Company') }}</b>
                    <div class="pv-dim">+212 522-000-000 · info@company.com</div>
                    <div class="pv-dim">1 Business Avenue</div>
                  </div>
                </div>
              </div>

              <!-- Items table -->
              <table class="pv-table" :style="{ border: form.table_borders ? '1px solid #e5e7eb' : 'none' }">
                <thead>
                  <tr :style="{ background: form.primary_color }">
                    <th>{{ $t('Product').toUpperCase() }}</th><th>{{ $t('Price').toUpperCase() }}</th><th>{{ $t('Qty').toUpperCase() }}</th><th>{{ $t('Total').toUpperCase() }}</th>
                  </tr>
                </thead>
                <tbody :style="{ color: form.text_color }">
                  <tr v-for="(r, i) in previewRows" :key="i" :style="{ background: form.table_striped && i % 2 === 1 ? '#f9fafb' : '#fff' }">
                    <td>{{ r[0] }}</td><td>{{ r[1] }}</td><td>{{ r[2] }}</td><td>{{ r[3] }}</td>
                  </tr>
                </tbody>
              </table>

              <!-- Totals -->
              <div class="pv-totals">
                <table>
                  <tbody>
                    <tr :style="{ color: form.text_color }"><td>{{ $t('Subtotal') }}</td><td>1,760.00</td></tr>
                    <tr :style="{ color: form.text_color }"><td>{{ $t('Rcpt_Tax') }}</td><td>176.00</td></tr>
                    <tr class="pv-grand" :style="{ background: form.primary_color }"><td>{{ $t('Total').toUpperCase() }}</td><td>1,936.00</td></tr>
                  </tbody>
                </table>
              </div>

              <!-- Notes -->
              <div v-if="form.show_notes" class="pv-note">
                <b>{{ $t('Notes') }}</b>
                <div>{{ $t('Set_Pdf_Sample_Note') }}</div>
              </div>

              <!-- Customer signature line (sales invoice) -->
              <div v-if="docType === 'sale' && form.show_signature" class="pv-signature">
                <div class="pv-signature-line" :style="{ borderColor: form.text_color }"></div>
                <div class="pv-signature-label">{{ form.labels.signature || $t('Customer_Signature') }}</div>
              </div>

              <!-- Footer -->
              <div class="pv-footer">
                <div v-if="form.show_footer_text" class="pv-footer-text" :style="{ borderColor: form.primary_color }">
                  {{ form.footer_text || $t('Set_Pdf_Sample_Footer') }}
                </div>
                <div v-if="form.show_thank_you" class="pv-thanks" :style="{ color: form.primary_color }">
                  {{ form.labels.thank_you || $t('Set_Pdf_Thank_You_Default') }}
                </div>
              </div>
            </div>
          </div>
        </a-col>
      </a-row>
    </template>
  </div>
</template>

<script setup>
/**
 * Invoice PDF customization — GET/POST pdf_templates/{type} for
 * sale | quotation | purchase. Every option here is consumed by the real
 * DomPDF blades (colors, fonts, margins, logo, section toggles, labels); the
 * right-hand preview is an instant HTML approximation of the rendered PDF.
 */
import { ref, computed, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { SaveOutlined, EyeOutlined } from '@ant-design/icons-vue';
import { useAuthStore } from '../../stores/auth';
import http from '../../lib/http';

const { t } = useI18n();
const auth = useAuthStore();

const loading = ref(true);
const saving = ref(false);
const docType = ref('sale');
const openPanels = ref(['colors', 'type', 'layout', 'table', 'sections', 'text']);
const defaults = ref(null);
const form = ref(null);

const typeOptions = computed(() => [
  { value: 'sale', label: t('Set_Sales_Invoice') },
  { value: 'quotation', label: t('Quotation') },
  { value: 'purchase', label: t('Purchase') },
]);

const defaultTitle = computed(() => ({
  sale: t('Set_Sales_Invoice'), quotation: t('Quotation'), purchase: t('Set_Purchase_Order'),
}[docType.value] || '').toUpperCase());

const colorFields = computed(() => [
  { key: 'primary_color', label: t('Primary_Color') },
  { key: 'secondary_color', label: t('Secondary_Color') },
  { key: 'text_color', label: t('Set_Text_Color') },
  { key: 'background_color', label: t('Set_Background_Color') },
]);
const fontOptions = ['DejaVu Sans', 'DejaVu Serif', 'DejaVu Sans Mono', 'Helvetica', 'Times', 'Courier']
  .map(f => ({ value: f, label: f }));
const sectionFields = computed(() => [
  { key: 'show_status', label: t('Set_Status_Badges') },
  { key: 'show_customer', label: docType.value === 'purchase' ? t('Set_Supplier_Details') : t('Set_Customer_Details') },
  { key: 'show_company', label: t('Set_Company_Details') },
  { key: 'show_notes', label: t('Notes') },
  { key: 'show_footer_text', label: t('Footer_Text') },
  { key: 'show_thank_you', label: t('Set_Thank_You_Line') },
  // Blank customer signature line above the footer; the sales invoice only.
  ...(docType.value === 'sale' ? [{ key: 'show_signature', label: t('Show_Signature_Line') }] : []),
]);

const previewRows = [
  ['Wireless Earbuds Pro', '240.00', '2', '480.00'],
  ['Smart Watch S9', '640.00', '1', '640.00'],
  ['Organic Coffee 1kg', '80.00', '8', '640.00'],
];

const FONT_MAP = {
  'DejaVu Sans': 'Verdana, "DejaVu Sans", sans-serif',
  'DejaVu Serif': 'Georgia, "DejaVu Serif", serif',
  'DejaVu Sans Mono': 'Consolas, "DejaVu Sans Mono", monospace',
  Helvetica: 'Helvetica, Arial, sans-serif',
  Times: '"Times New Roman", Times, serif',
  Courier: '"Courier New", Courier, monospace',
};

const pageStyle = computed(() => ({
  background: form.value.background_color,
  color: form.value.text_color,
  fontFamily: FONT_MAP[form.value.font_family] || 'sans-serif',
  fontSize: `${form.value.font_size + 2}px`,
  padding: `${form.value.margin_v * 2.2}px ${form.value.margin_h * 2.2}px`,
}));

async function load() {
  loading.value = true;
  try {
    const data = await http.get(`pdf_templates/${docType.value}`);
    form.value = data.settings;
    defaults.value = data.defaults;
  } catch (e) {
    message.error(t('InvalidData'));
  } finally {
    loading.value = false;
  }
}

function resetDefaults() {
  if (defaults.value) form.value = JSON.parse(JSON.stringify(defaults.value));
}

async function save() {
  saving.value = true;
  try {
    await http.post(`pdf_templates/${docType.value}`, form.value);
    message.success(t('Successfully_Updated'));
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    saving.value = false;
  }
}

onMounted(load);
</script>

<style scoped>
.pdfc-top {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  flex-wrap: wrap;
  margin-bottom: 16px;
}
.pdfc-panels {
  background: transparent;
}
.pdfc-panels :deep(.ant-collapse-item) {
  background: #fff;
  border: 1px solid #ececee !important;
  border-radius: 10px !important;
  margin-bottom: 10px;
  overflow: hidden;
}
.pdfc-panels :deep(.ant-collapse-header) {
  font-weight: 600;
}
.pdfc-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 7px 0;
  font-size: 13px;
}
.pdfc-row--off {
  opacity: 0.45;
  pointer-events: none;
}
.pdfc-color {
  width: 44px;
  height: 30px;
  padding: 2px;
  border: 1px solid #d9d9d9;
  border-radius: 6px;
  background: #fff;
  cursor: pointer;
}
.pdfc-field {
  margin-bottom: 12px;
}
.pdfc-label {
  font-size: 12px;
  color: rgba(0, 0, 0, 0.55);
  margin-bottom: 4px;
}

/* ---------------- Preview ---------------- */
.pdfc-preview-wrap {
  position: sticky;
  top: 12px;
}
.pdfc-preview-bar {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 12px;
  color: rgba(0, 0, 0, 0.5);
  margin-bottom: 8px;
}
.pdfc-page {
  border: 1px solid #ececee;
  border-radius: 8px;
  box-shadow: 0 4px 18px rgba(0, 0, 0, 0.08);
  min-height: 620px;
  overflow: hidden;
}
.pv-head {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 12px;
}
.pv-logo {
  height: 40px;
  border: 1px dashed #c4c4cc;
  border-radius: 6px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 10px;
  letter-spacing: 2px;
  color: #9a9aa5;
  flex: none;
}
.pv-head-right { text-align: right; }
.pv-title {
  font-size: 1.7em;
  font-weight: 800;
  letter-spacing: 0.02em;
}
.pv-ref {
  display: inline-block;
  background: #f3f4f6;
  color: #4b5563;
  font-weight: 700;
  border-radius: 4px;
  padding: 2px 10px;
  margin-top: 4px;
  font-size: 0.9em;
}
.pv-badges { margin-top: 6px; display: flex; gap: 6px; justify-content: flex-end; }
.pv-badge {
  font-size: 0.65em;
  font-weight: 700;
  padding: 2px 8px;
  border-radius: 3px;
}
.pv-rule { height: 2px; margin: 10px 0 12px; }
.pv-boxes { display: flex; gap: 10px; margin-bottom: 12px; }
.pv-box {
  flex: 1;
  border: 1px solid #e5e7eb;
  border-radius: 4px;
  overflow: hidden;
}
.pv-box-head {
  color: #fff;
  font-size: 0.75em;
  font-weight: 700;
  letter-spacing: 0.05em;
  padding: 4px 10px;
  border-bottom: 1px solid;
}
.pv-box-body { padding: 8px 10px; background: #fbfbfc; font-size: 0.85em; }
.pv-dim { opacity: 0.6; }
.pv-table {
  width: 100%;
  border-collapse: collapse;
  margin-bottom: 10px;
}
.pv-table th {
  color: #fff;
  text-align: left;
  font-size: 0.72em;
  letter-spacing: 0.04em;
  padding: 6px 8px;
}
.pv-table td {
  padding: 6px 8px;
  font-size: 0.85em;
  border-bottom: 1px solid #eef0f2;
}
.pv-totals { display: flex; justify-content: flex-end; margin-bottom: 12px; }
.pv-totals table { min-width: 45%; border-collapse: collapse; font-size: 0.85em; }
.pv-totals td { padding: 4px 8px; }
.pv-totals td:last-child { text-align: right; font-weight: 600; }
.pv-grand td { color: #fff; font-weight: 800; }
.pv-note {
  background: #f0f9ff;
  border-left: 3px solid #0284c7;
  border-radius: 3px;
  color: #0c4a6e;
  padding: 8px 10px;
  font-size: 0.8em;
  margin-bottom: 12px;
}
.pv-footer { border-top: 2px solid #e5e7eb; padding-top: 10px; }
.pv-footer-text {
  background: #f9fafb;
  border-left: 3px solid;
  border-radius: 3px;
  padding: 7px 10px;
  font-size: 0.8em;
  opacity: 0.85;
  margin-bottom: 8px;
}
.pv-thanks {
  text-align: center;
  font-weight: 800;
  padding: 4px 0;
}
.pv-signature {
  width: 45%;
  margin: 14px 0 0 auto;
}
.pv-signature-line {
  border-bottom: 1px solid;
  height: 26px;
}
.pv-signature-label {
  text-align: center;
  font-size: 0.75em;
  opacity: 0.7;
  margin-top: 3px;
}
</style>
