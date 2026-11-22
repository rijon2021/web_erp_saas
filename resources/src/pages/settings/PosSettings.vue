<template>
  <div class="page">
    <PageHeader :title="$t('Pos_Settings')" :breadcrumb="[$t('Settings'), $t('Pos_Settings')]" />

    <div v-if="loading" style="display: flex; justify-content: center; padding: 96px 0">
      <a-spin size="large" />
    </div>

    <a-form v-else layout="vertical">
      <a-card size="small" :title="$t('Receipt') || 'Receipt'" style="margin-bottom: 16px">
        <a-row :gutter="16">
          <a-col :xs="24" :md="8">
            <a-form-item
              :label="$t('Receipt_Paper_Size')"
              :extra="$t('PosSet_Paper_Size_Help')"
            >
              <a-select
                v-model:value="s.receipt_paper_size"
                :options="[
                  { value: 58, label: $t('Paper_58mm') },
                  { value: 80, label: $t('Paper_80mm') },
                  { value: 88, label: $t('Paper_88mm') },
                ]"
              />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="8">
            <a-form-item
              :label="$t('PosSet_Receipt_Layout')"
              :extra="$t('PosSet_Receipt_Layout_Help')"
            >
              <a-select
                v-model:value="s.receipt_layout"
                :options="[
                  { value: 1, label: $t('Layout_1_Standard') },
                  { value: 2, label: $t('Layout_2_Compact') },
                  { value: 3, label: $t('Layout_3_Detailed') },
                  { value: 4, label: $t('Layout_4_Bilingual') },
                  { value: 5, label: $t('Layout_5_Minimal') },
                  { value: 6, label: $t('Layout_6_Tabular') },
                ]"
              />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="8">
            <a-form-item :label="$t('PosSet_Logo_Size_Px')">
              <a-input-number v-model:value="s.logo_size" style="width: 100%" :min="0" />
            </a-form-item>
          </a-col>
          <a-col :span="24">
            <a-alert
              type="info" show-icon
              :message="$t('PosSet_Receipt_Content_Info')"
            />
          </a-col>
        </a-row>
      </a-card>

      <a-card size="small" title="POS" style="margin-bottom: 16px">
        <a-row :gutter="16">
          <a-col :xs="24" :md="8">
            <a-form-item :label="$t('PosSet_Products_Per_Page')">
              <a-input-number v-model:value="s.products_per_page" style="width: 100%" :min="1" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="8">
            <a-form-item
              :label="$t('Invoice_Format')"
              :extra="$t('PosSet_Invoice_Format_Help')"
            >
              <a-select
                v-model:value="invoiceFormat"
                :options="[
                  { value: 'thermal', label: $t('PosSet_Thermal_Receipt') },
                  { value: 'a4', label: $t('PosSet_A4_Page') },
                ]"
              />
            </a-form-item>
          </a-col>
          <a-col :span="24">
            <a-row :gutter="[8, 8]">
              <a-col v-for="f in POS_FLAGS" :key="f.key" :xs="12" :md="8">
                <a-checkbox v-model:checked="s[f.key]">{{ $t(f.label) }}</a-checkbox>
              </a-col>
            </a-row>
          </a-col>
        </a-row>
      </a-card>

      <!-- Manager PIN to remove items: cashiers without pos_void_items need a
           manager's PIN to remove a line, lower a quantity or clear the cart. -->
      <a-card size="small" :title="$t('Pv_Item_Removal')" style="margin-bottom: 16px">
        <a-space direction="vertical" size="small">
          <a-space>
            <a-switch v-model:checked="s.require_void_approval" />
            <span>{{ $t('Pv_Require_Approval') }}</span>
          </a-space>
          <a-typography-text type="secondary">{{ $t('Pv_Require_Approval_Help') }}</a-typography-text>
        </a-space>
      </a-card>

      <!-- Cash drawer auto-open (QZ Tray + ESC/POS) — same section as legacy pos_settings.vue -->
      <a-card size="small" :title="$t('Cash_Drawer_Settings')" style="margin-bottom: 16px">
        <a-alert type="info" show-icon :message="$t('Cash_Drawer_Auto_Open_Help')" style="margin-bottom: 16px" />
        <a-row :gutter="16">
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Cash_Drawer_Auto_Open')">
              <a-switch v-model:checked="s.cash_drawer_auto_open" />
            </a-form-item>
          </a-col>
          <a-col :xs="24" :md="12">
            <a-form-item :label="$t('Cash_Drawer_Printer_Name')" :extra="$t('Cash_Drawer_Printer_Name_Help')">
              <a-input
                v-model:value="s.cash_drawer_printer_name"
                :placeholder="$t('Leave_blank_for_default_receipt_printer')"
                :maxlength="192"
              />
            </a-form-item>
          </a-col>
        </a-row>
      </a-card>

      <!-- Keyboard shortcuts: per-device (localStorage), saved on toggle — not
           part of the pos_settings payload, so no Save click is needed. -->
      <a-card size="small" :title="$t('POS_Keyboard_Shortcuts')" style="margin-bottom: 16px">
        <a-space direction="vertical" size="small">
          <a-space>
            <a-switch v-model:checked="keyboardShortcuts" @change="onToggleKeyboardShortcuts" />
            <span>{{ $t('Enable_Keyboard_Shortcuts') }}</span>
          </a-space>
          <a-typography-text type="secondary">
            {{ $t('Enable_Keyboard_Shortcuts_Help') }}
            <a @click.prevent="shortcutsGuideOpen = true">{{ $t('View_Shortcuts') }}</a>
          </a-typography-text>
        </a-space>
      </a-card>

      <a-button type="primary" size="large" :loading="saving" @click="save">{{ $t('submit') }}</a-button>
    </a-form>

    <!-- POS Keyboard Shortcuts Guide (read-only reference) -->
    <a-modal
      v-model:open="shortcutsGuideOpen"
      :title="$t('POS_Keyboard_Shortcuts')"
      :footer="null"
      :width="560"
    >
      <a-typography-paragraph type="secondary">{{ $t('Shortcuts_Guide_Intro') }}</a-typography-paragraph>
      <a-table
        :columns="shortcutColumns"
        :data-source="shortcutRows"
        row-key="id"
        size="small"
        :pagination="false"
      >
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'keys'">
            <a-tag>{{ record.keys }}</a-tag>
          </template>
        </template>
      </a-table>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * GET get_pos_Settings_api → {pos_settings}; PUT pos_settings/{id} with the
 * POS flags only (cash_drawer_auto_open goes as 1/0, the rest as booleans).
 * invoice_format is sent as a separate top-level field like legacy.
 * Receipt content (note + show/hide toggles) is owned by the POS Receipt page
 * and deliberately NOT sent from here, so the two pages can't overwrite each
 * other with stale values. allow_overselling lives in System Settings >
 * Features now.
 */
import { ref, computed, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';
import {
  POS_SHORTCUTS,
  posShortcutsEnabled,
  setPosShortcutsEnabled,
} from '../../pos-compat/posKeyboardShortcuts';

const { t } = useI18n();

const POS_FLAGS = [
  { key: 'quick_add_customer', label: 'Quick_Add_Customer' },
  { key: 'barcode_scanning_sound', label: 'Barcode_Scanning_Sound' },
  { key: 'show_product_images', label: 'Show_Product_Images_in_POS' },
  { key: 'show_stock_quantity', label: 'Show_Stock_Quantity_in_POS' },
  // Available quantity next to each hit in the product search dropdown.
  { key: 'show_search_stock', label: 'Show_Stock_In_Search_Results' },
  { key: 'enable_hold_sales', label: 'Enable_Hold_Sales' },
  { key: 'enable_customer_points', label: 'Enable_Customer_Points_in_POS' },
  { key: 'show_categories', label: 'Show_Categories_in_POS' },
  { key: 'show_brands', label: 'Show_Brands_in_POS' },
  // Ask before selling an item priced at 0 (catches unpriced products).
  { key: 'confirm_zero_price', label: 'Pos_Confirm_Zero_Price' },
  // allow_overselling moved to System Settings > Features (global switch).
  // cash_drawer_auto_open has its own card below (legacy "Cash Drawer" section).
];

const ALL_FLAGS = [...POS_FLAGS.map(f => f.key), 'cash_drawer_auto_open', 'require_void_approval'];

const loading = ref(true);
const saving = ref(false);
const s = ref({});
const invoiceFormat = ref('');

// Per-device keyboard-shortcuts preference (localStorage, no backend field).
const keyboardShortcuts = ref(posShortcutsEnabled());
const shortcutsGuideOpen = ref(false);

const shortcutColumns = computed(() => [
  { title: t('Shortcut'), key: 'keys', dataIndex: 'keys', width: '45%' },
  { title: t('Action'), dataIndex: 'description' },
]);

// vue-i18n returns the key itself when a translation is missing, so fall back
// to the English label shipped with the shortcut definition.
const shortcutRows = computed(() =>
  POS_SHORTCUTS.map(sc => ({
    id: sc.id,
    keys: sc.keys,
    description: t(sc.descriptionKey) === sc.descriptionKey
      ? sc.descriptionFallback
      : t(sc.descriptionKey),
  })),
);

function onToggleKeyboardShortcuts(checked) {
  setPosShortcutsEnabled(checked);
  message.success(t('Successfully_Updated'));
}

async function save() {
  saving.value = true;
  const v = s.value;
  const body = {
    logo_size: v.logo_size,
    receipt_paper_size: v.receipt_paper_size,
    receipt_layout: v.receipt_layout,
    products_per_page: v.products_per_page,
    cash_drawer_printer_name: v.cash_drawer_printer_name || null,
    invoice_format: invoiceFormat.value,
  };
  ALL_FLAGS.forEach(k => {
    body[k] = (k === 'cash_drawer_auto_open' || k === 'require_void_approval') ? (v[k] ? 1 : 0) : v[k];
  });
  try {
    await http.put(`pos_settings/${v.id}`, body);
    message.success(t('Successfully_Updated'));
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    saving.value = false;
  }
}

onMounted(async () => {
  try {
    const data = await http.get('get_pos_Settings_api');
    const ps = data.pos_settings || {};
    ALL_FLAGS.forEach(k => { ps[k] = !!Number(ps[k] ?? 0) || ps[k] === true; });
    // Stored as an integer (58/80/88); coerce so the select matches an option.
    ps.receipt_paper_size = [58, 80, 88].includes(Number(ps.receipt_paper_size))
      ? Number(ps.receipt_paper_size)
      : 80;
    s.value = ps;
    // Global invoice format comes as a top-level field on the response
    // (it lives on the `settings` row, not `pos_settings`).
    invoiceFormat.value = data.invoice_format || ps.invoice_format || 'thermal';
  } catch (e) {
    message.error(t('InvalidData'));
  } finally {
    loading.value = false;
  }
});
</script>
