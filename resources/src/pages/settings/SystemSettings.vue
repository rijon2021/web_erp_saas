<template>
  <div class="page">
    <PageHeader :title="$t('SystemSettings')" :breadcrumb="[$t('Settings'), $t('SystemSettings')]" />

    <!-- Skeleton mirrors the real two-column layout while settings load. -->
    <a-row v-if="isLoading" :gutter="16">
      <a-col :xs="24" :lg="6" style="margin-bottom: 16px">
        <a-card size="small">
          <a-skeleton active :title="false" :paragraph="{ rows: 10, width: '90%' }" />
        </a-card>
      </a-col>
      <a-col :xs="24" :lg="18">
        <a-skeleton active :paragraph="{ rows: 1 }" style="margin-bottom: 12px" />
        <a-card>
          <a-skeleton active :paragraph="{ rows: 8 }" />
        </a-card>
      </a-col>
    </a-row>

    <a-row v-else :gutter="16">
      <!-- ============================ Navigation ============================ -->
      <a-col :xs="24" :lg="6" style="margin-bottom: 16px">
        <a-card size="small" :body-style="{ padding: '8px 0' }">
          <a-menu
            v-model:selectedKeys="selectedKeys"
            mode="inline"
            class="settings-nav"
            style="border-inline-end: none"
            @click="onMenuClick"
          >
            <a-menu-item-group :title="$t('CompanyName')">
              <a-menu-item key="general"><ShopOutlined /> {{ $t('General') }}</a-menu-item>
              <a-menu-item key="localization"><GlobalOutlined /> {{ $t('Localization') }}</a-menu-item>
            </a-menu-item-group>
            <a-menu-item-group :title="$t('Sales')">
              <a-menu-item key="defaults"><ControlOutlined /> {{ $t('Defaults') }}</a-menu-item>
              <a-menu-item key="features"><ThunderboltOutlined /> {{ $t('Features') }}</a-menu-item>
              <a-menu-item key="prefixes"><NumberOutlined /> {{ $t('Prefixes') }}</a-menu-item>
              <a-menu-item key="invoice_pdf"><FilePdfOutlined /> {{ $t('Set_Invoice_PDF') }}</a-menu-item>
              <a-menu-item key="pharmacy"><MedicineBoxOutlined /> {{ $t('Pharmacy') }}</a-menu-item>
            </a-menu-item-group>
            <a-menu-item-group :title="$t('System') || 'Workspace'">
              <a-menu-item key="dashboard"><AppstoreOutlined /> {{ $t('Dashboard') }}</a-menu-item>
              <a-menu-item key="datatable"><TableOutlined /> {{ $t('Sys_DataTable') }}</a-menu-item>
              <a-menu-item key="export"><ExportOutlined /> {{ $t('Export') }}</a-menu-item>
              <a-menu-item key="security"><SafetyOutlined /> {{ $t('Security') }}</a-menu-item>
              <a-menu-item key="backup"><CloudUploadOutlined /> {{ $t('Backup') }}</a-menu-item>
              <a-menu-item key="system"><ToolOutlined /> {{ $t('Maintenance') }}</a-menu-item>
              <a-menu-item key="calendar"><CalendarOutlined /> {{ $t('Calendar') }}</a-menu-item>
            </a-menu-item-group>
            <a-menu-item-group :title="'POS'">
              <a-menu-item key="pos_settings"><ControlOutlined /> {{ $t('Pos_Settings') }}</a-menu-item>
              <a-menu-item key="pos_receipt"><FileTextOutlined /> {{ $t('POS_Receipt') }}</a-menu-item>
              <a-menu-item key="weight_scale"><ScanOutlined /> {{ $t('Scale_Weighing_Scale') }}</a-menu-item>
            </a-menu-item-group>
            <a-menu-item-group :title="$t('Integrations')">
              <a-menu-item key="appearance"><SkinOutlined /> {{ $t('Page_Appearance') }}</a-menu-item>
              <a-menu-item key="login_page"><LoginOutlined /> {{ $t('Sys_Login_Page') }}</a-menu-item>
              <a-menu-item key="pwa"><MobileOutlined /> PWA</a-menu-item>
              <a-menu-item key="mobile_app"><TabletOutlined /> {{ $t('Mobile_App_Settings') }}</a-menu-item>
              <a-menu-item key="mail"><MailOutlined /> {{ $t('Mail') }}</a-menu-item>
              <a-menu-item key="sms"><MessageOutlined /> SMS</a-menu-item>
              <a-menu-item key="payment"><CreditCardOutlined /> {{ $t('Payment_Gateway') }}</a-menu-item>
              <a-menu-item key="zatca"><AuditOutlined /> {{ $t('Zatca_E_Invoicing') }}</a-menu-item>
              <a-menu-item key="custom_fields"><FormOutlined /> {{ $t('CustomFields') }}</a-menu-item>
              <a-menu-item key="backups"><DatabaseOutlined /> {{ $t('Sys_Backup_Archives') }}</a-menu-item>
              <a-menu-item key="devices"><LaptopOutlined /> {{ $t('Page_Login_Devices') }}</a-menu-item>
            </a-menu-item-group>
          </a-menu>
        </a-card>
      </a-col>

      <!-- ============================== Content ============================== -->
      <a-col :xs="24" :lg="18">
        <div class="section-head">
          <h3 style="margin: 0 0 2px">{{ currentSection.title }}</h3>
          <div style="color: rgba(var(--ink-rgb), 0.45); font-size: 13px">{{ currentSection.desc }}</div>
        </div>

        <!-- Embedded settings pages (same components as their standalone
             routes; their own page headers are hidden via CSS below). The
             transition fades sections in/out on tab change. -->
        <transition name="ss-fade" mode="out-in">
        <div v-if="embeddedPages[activeTab]" :key="activeTab" class="embedded">
          <component :is="embeddedPages[activeTab]" @phase1-saved="onZatcaPhase1Saved" @navigate="key => onMenuClick({ key })" />
        </div>

        <a-card v-else :key="'card-' + activeTab">
          <!-- ============================== General ============================== -->
          <template v-if="activeTab === 'general'">
            <a-form layout="vertical" style="max-width: 680px">
              <a-row :gutter="16">
                <a-col :xs="24" :md="12">
                  <a-form-item :label="$t('CompanyName')">
                    <a-input v-model:value="setting.CompanyName" />
                  </a-form-item>
                </a-col>
                <a-col :xs="24" :md="12">
                  <a-form-item :label="$t('Phone')">
                    <a-input v-model:value="setting.CompanyPhone" />
                  </a-form-item>
                </a-col>
                <a-col :xs="24" :md="12">
                  <a-form-item :label="$t('Email')">
                    <a-input v-model:value="setting.email" type="email" />
                  </a-form-item>
                </a-col>
                <a-col :xs="24" :md="12">
                  <a-form-item :label="$t('Adress')">
                    <a-input v-model:value="setting.CompanyAdress" />
                  </a-form-item>
                </a-col>
                <!-- White-labeling is a plan feature (remove_branding). -->
                <a-col v-if="auth.canRemoveBranding" :xs="24" :md="12">
                  <a-form-item :label="$t('footer')" :help="$t('Sys_Footer_Help')">
                    <a-input v-model:value="setting.footer" :placeholder="$t('Sys_Footer_Placeholder', { year: new Date().getFullYear() })" />
                  </a-form-item>
                </a-col>
                <a-col v-if="auth.canRemoveBranding" :xs="24" :md="12">
                  <a-form-item :label="$t('developed_by')">
                    <a-input v-model:value="setting.developed_by" />
                  </a-form-item>
                </a-col>
                <a-col :span="24">
                  <a-form-item :label="$t('ChangeLogo')">
                    <a-upload
                      :file-list="logoFileList"
                      :before-upload="onLogoPicked"
                      :max-count="1"
                      list-type="picture"
                      accept="image/*"
                      @remove="() => { logoFileList = []; logoFile = null; }"
                    >
                      <a-button><UploadOutlined /> {{ $t('ChangeLogo') }}</a-button>
                    </a-upload>
                  </a-form-item>
                </a-col>
                <a-col :xs="24" :md="12">
                  <a-form-item :label="$t('Sys_Sidebar_Logo_Width')">
                    <a-slider v-model:value="setting.sidebar_logo_width" :min="16" :max="200" />
                  </a-form-item>
                </a-col>
                <a-col :xs="24" :md="12">
                  <a-form-item :label="$t('Sys_Sidebar_Logo_Height')">
                    <a-slider v-model:value="setting.sidebar_logo_height" :min="16" :max="120" />
                  </a-form-item>
                </a-col>
              </a-row>
            </a-form>
            <a-divider style="margin: 8px 0 0" />
            <div class="setting-row">
              <div>
                <div class="setting-label">{{ $t('Sys_Show_Logo_In_Sidebar') }}</div>
                <div class="setting-help">{{ $t('Sys_Show_Logo_In_Sidebar_Help') }}</div>
              </div>
              <a-switch :checked="asBool(setting.sidebar_show_logo)" @change="v => (setting.sidebar_show_logo = !!v)" />
            </div>
            <div class="setting-row">
              <div>
                <div class="setting-label">{{ $t('Sys_Show_Company_Name_In_Sidebar') }}</div>
                <div class="setting-help">{{ $t('Sys_Show_Company_Name_In_Sidebar_Help') }}</div>
              </div>
              <a-switch :checked="!asBool(setting.hide_site_name)" @change="v => (setting.hide_site_name = !v)" />
            </div>
          </template>

          <!-- ============================ Localization ============================ -->
          <template v-else-if="activeTab === 'localization'">
            <a-form layout="vertical" style="max-width: 680px">
              <a-row :gutter="16">
                <a-col :xs="24" :md="12">
                  <a-form-item :label="$t('DefaultLanguage')">
                    <a-select
                      v-model:value="setting.default_language" show-search option-filter-prop="label"
                      :options="languages.map(l => ({ label: l.name, value: l.locale }))"
                    />
                  </a-form-item>
                </a-col>
                <a-col :xs="24" :md="12">
                  <a-form-item :label="$t('DefaultCurrency')">
                    <a-select
                      v-model:value="setting.currency_id" show-search option-filter-prop="label"
                      :options="currencies.map(c => ({ label: c.name + ' (' + c.code + ')', value: c.id }))"
                    />
                  </a-form-item>
                </a-col>
                <a-col :xs="24" :md="12">
                  <a-form-item :label="$t('Time_Zone')">
                    <a-select
                      v-model:value="setting.timezone" show-search option-filter-prop="label"
                      :options="zonesArray.map(z => ({ label: z.label, value: z.zone }))"
                    />
                  </a-form-item>
                </a-col>
                <a-col :xs="24" :md="12">
                  <a-form-item :label="$t('Date_Format')">
                    <a-select
                      v-model:value="setting.date_format"
                      :options="[
                        { label: 'DD/MM/YYYY', value: 'DD/MM/YYYY' },
                        { label: 'MM/DD/YYYY', value: 'MM/DD/YYYY' },
                        { label: 'YYYY-MM-DD', value: 'YYYY-MM-DD' },
                      ]"
                    />
                  </a-form-item>
                </a-col>
                <a-col :xs="24" :md="12">
                  <a-form-item :label="$t('Price_Format')">
                    <a-select
                      v-model:value="setting.price_format" allow-clear
                      :options="[
                        { label: $t('Sys_Price_Format_Comma_Dot'), value: 'comma_dot' },
                        { label: $t('Sys_Price_Format_Dot_Comma'), value: 'dot_comma' },
                        { label: $t('Sys_Price_Format_Space_Comma'), value: 'space_comma' },
                      ]"
                    />
                  </a-form-item>
                </a-col>
                <a-col :xs="24" :md="12">
                  <a-form-item :label="$t('Currency_Position')">
                    <a-select v-model:value="setting.currency_position" :options="currencyPositionOptions" />
                  </a-form-item>
                </a-col>
              </a-row>
            </a-form>
            <a-divider style="margin: 8px 0 0" />
            <div class="setting-row">
              <div>
                <div class="setting-label">{{ $t('Show_Languages') }}</div>
                <div class="setting-help">{{ $t('Sys_Show_Languages_Help') }}</div>
              </div>
              <a-switch :checked="asBool(setting.show_language)" @change="v => (setting.show_language = v ? 1 : 0)" />
            </div>
            <div class="setting-row">
              <div>
                <div class="setting-label">{{ $t('Hide_Zero_Decimals') }}</div>
                <div class="setting-help">{{ $t('Sys_Hide_Zero_Decimals_Help') }}</div>
              </div>
              <a-switch :checked="asBool(setting.hide_zero_decimals)" @change="v => (setting.hide_zero_decimals = v ? 1 : 0)" />
            </div>
            <div class="setting-row">
              <div>
                <div class="setting-label">{{ $t('DarkMode') }}</div>
                <div class="setting-help">{{ $t('Sys_Dark_Mode_Help') }}</div>
              </div>
              <a-switch v-model:checked="setting.dark_mode" />
            </div>
            <div class="setting-row">
              <div>
                <div class="setting-label">{{ $t('RTL') }}</div>
                <div class="setting-help">{{ $t('Sys_RTL_Help') }}</div>
              </div>
              <a-switch v-model:checked="setting.rtl" />
            </div>
          </template>

          <!-- ============================== Features ============================== -->
          <template v-else-if="activeTab === 'features'">
            <div class="setting-row">
              <div>
                <div class="setting-label">{{ $t('Enable_3_Decimal_Pricing') }}</div>
                <div class="setting-help">{{ $t('Enable_3_Decimal_Pricing_Help') }}</div>
              </div>
              <a-switch v-model:checked="setting.enable_3_decimal_pricing" />
            </div>
            <div class="setting-row">
              <div>
                <div class="setting-label">{{ $t('EnableKitchenDisplay') }}</div>
                <div class="setting-help">{{ $t('EnableKitchenDisplay_Help') }}</div>
              </div>
              <a-switch v-model:checked="setting.enable_kitchen_display" />
            </div>
            <div class="setting-row">
              <div>
                <div class="setting-label">{{ $t('Show_Product_GTIN') }}</div>
                <div class="setting-help">{{ $t('Show_Product_GTIN_Help') }}</div>
              </div>
              <a-switch v-model:checked="setting.show_product_gtin" />
            </div>
            <div class="setting-row">
              <div>
                <div class="setting-label">{{ $t('Resize_Product_Images') }}</div>
                <div class="setting-help">{{ $t('Resize_Product_Images_Help') }}</div>
              </div>
              <a-switch v-model:checked="setting.product_image_resize" />
            </div>
            <div v-if="setting.product_image_resize" class="setting-row">
              <div>
                <div class="setting-label">{{ $t('Product_Image_Max_Size') }}</div>
                <div class="setting-help">{{ $t('Product_Image_Max_Size_Help') }}</div>
              </div>
              <a-input-number
                v-model:value="setting.product_image_max_size"
                :min="50" :max="5000" :step="100" addon-after="px" style="width: 160px"
              />
            </div>
            <div class="setting-row">
              <div>
                <div class="setting-label">{{ $t('Track_Serial_IMEI') }}</div>
                <div class="setting-help">{{ $t('Track_Serial_IMEI_Hint') }}</div>
              </div>
              <a-switch v-model:checked="setting.show_serial_tracking" />
            </div>
            <div class="setting-row">
              <div>
                <div class="setting-label">{{ $t('Enable_Multi_Pack_Selling') }}</div>
                <div class="setting-help">{{ $t('Enable_Multi_Pack_Selling_Hint') }}</div>
              </div>
              <a-switch v-model:checked="setting.enable_multi_pack_selling" />
            </div>
            <div class="setting-row">
              <div>
                <div class="setting-label">{{ $t('Supplier_Wallet') }}</div>
                <div class="setting-help">{{ $t('Sys_Supplier_Wallet_Help') }}</div>
              </div>
              <a-switch :checked="asBool(setting.supplier_wallet_enabled)" @change="v => (setting.supplier_wallet_enabled = v ? 1 : 0)" />
            </div>
            <div v-if="asBool(setting.supplier_wallet_enabled)" class="setting-row">
              <div>
                <div class="setting-label">{{ $t('SW_Allow_Negative') }}</div>
                <div class="setting-help">{{ $t('Sys_SW_Allow_Negative_Help') }}</div>
              </div>
              <a-switch :checked="asBool(setting.supplier_wallet_allow_negative)" @change="v => (setting.supplier_wallet_allow_negative = v ? 1 : 0)" />
            </div>
            <div class="setting-row">
              <div>
                <div class="setting-label">{{ $t('CW_Customer_Wallet') }}</div>
                <div class="setting-help">{{ $t('Sys_Customer_Wallet_Help') }}</div>
              </div>
              <a-switch :checked="asBool(setting.customer_wallet_enabled)" @change="v => (setting.customer_wallet_enabled = v ? 1 : 0)" />
            </div>
            <div v-if="asBool(setting.customer_wallet_enabled)" class="setting-row">
              <div>
                <div class="setting-label">{{ $t('CW_Allow_Negative') }}</div>
                <div class="setting-help">{{ $t('Sys_Customer_Wallet_Negative_Help') }}</div>
              </div>
              <a-switch :checked="asBool(setting.customer_wallet_allow_negative)" @change="v => (setting.customer_wallet_allow_negative = v ? 1 : 0)" />
            </div>
            <!-- Wholesale Pricing by Quantity (price tiers) is not part of this
                 SaaS build yet: the backend has no price-tier tables or pricing
                 service, so the switch is hidden until the module is ported. -->
            <div class="setting-row">
              <div>
                <div class="setting-label">{{ $t('Enable_Warehouse_Letterhead') }}</div>
                <div class="setting-help">{{ $t('Enable_Warehouse_Letterhead_Hint') }}</div>
              </div>
              <a-switch v-model:checked="setting.enable_warehouse_letterhead" />
            </div>
            <div class="setting-row">
              <div>
                <div class="setting-label">{{ $t('Enable_Multi_Currency') }}</div>
                <div class="setting-help">{{ $t('Enable_Multi_Currency_Hint') }}</div>
              </div>
              <a-switch v-model:checked="setting.enable_multi_currency" />
            </div>
            <div class="setting-row">
              <div>
                <div class="setting-label">{{ $t('Enable_Pos_Salesperson_Switch') }}</div>
                <div class="setting-help">{{ $t('Enable_Pos_Salesperson_Switch_Hint') }}</div>
              </div>
              <a-switch v-model:checked="setting.enable_pos_salesperson_switch" />
            </div>
            <!-- Vehicle Fitment is not part of this SaaS build yet (no
                 VehicleFitmentController / fitment tables; the POS keeps its
                 vehicle UI hidden), so the switch is hidden until ported. -->
            <div class="setting-row">
              <div>
                <div class="setting-label">{{ $t('Auto_Journal_Entries') }}</div>
                <div class="setting-help">{{ $t('Auto_Journal_Entries_Hint') }}</div>
              </div>
              <a-switch v-model:checked="setting.accounting_auto_generate_journals" />
            </div>
            <div class="setting-row">
              <div>
                <div class="setting-label">{{ $t('Allow_overselling') }}</div>
                <div class="setting-help">
                  {{ $t('Sys_Allow_Overselling_Help') }}
                </div>
              </div>
              <a-switch v-model:checked="setting.allow_overselling" />
            </div>
            <div class="setting-row">
              <div>
                <div class="setting-label">{{ $t('Restrict_Master_Data_By_Warehouse') }}</div>
                <div class="setting-help">{{ $t('Restrict_Master_Data_By_Warehouse_Help') }}</div>
              </div>
              <a-switch v-model:checked="setting.restrict_master_data_by_warehouse" />
            </div>
            <div class="setting-row">
              <div>
                <div class="setting-label">{{ $t('Restrict_Products_By_Warehouse') }}</div>
                <div class="setting-help">{{ $t('Restrict_Products_By_Warehouse_Help') }}</div>
              </div>
              <a-switch v-model:checked="setting.restrict_products_by_warehouse" />
            </div>
            <div class="setting-row">
              <div>
                <div class="setting-label">{{ $t('Virtual_Combo_Stock') }}</div>
                <div class="setting-help">{{ $t('Virtual_Combo_Stock_Help') }}</div>
                <a-alert
                  type="warning"
                  show-icon
                  :message="$t('Virtual_Combo_Stock_Warning')"
                  style="margin-top: 8px; max-width: 520px"
                />
              </div>
              <a-switch v-model:checked="setting.virtual_combo_stock" @change="onVirtualComboChange" />
            </div>
            <div class="setting-row">
              <div>
                <div class="setting-label">{{ $t('Customer_Performance') }}</div>
                <div class="setting-help">{{ $t('Customer_Performance_Help') }}</div>
              </div>
              <a-switch v-model:checked="setting.customer_performance_enabled" />
            </div>
            <div class="setting-row">
              <div>
                <div class="setting-label">{{ $t('Product_Supplier') }}</div>
                <div class="setting-help">{{ $t('Product_Supplier_Help') }}</div>
              </div>
              <a-switch v-model:checked="setting.product_supplier_enabled" />
            </div>
            <div class="setting-row">
              <div>
                <div class="setting-label">{{ $t('Purchase_Update_Selling_Price') }}</div>
                <div class="setting-help">{{ $t('Sys_Purchase_Update_Selling_Price_Help') }}</div>
              </div>
              <a-switch :checked="asBool(setting.purchase_update_selling_price)" @change="v => (setting.purchase_update_selling_price = v ? 1 : 0)" />
            </div>
            <div class="setting-row">
              <div>
                <div class="setting-label">{{ $t('Purchase_Update_Product_Cost') }}</div>
                <div class="setting-help">{{ $t('Sys_Purchase_Update_Product_Cost_Help') }}</div>
              </div>
              <a-switch :checked="asBool(setting.purchase_update_product_cost)" @change="v => (setting.purchase_update_product_cost = v ? 1 : 0)" />
            </div>
            <div class="setting-row">
              <div>
                <div class="setting-label">{{ $t('Warehouse_Prices_Enabled') }}</div>
                <div class="setting-help">{{ $t('Warehouse_Prices_Enabled_Help') }}</div>
              </div>
              <a-switch :checked="asBool(setting.warehouse_prices_enabled)" @change="v => (setting.warehouse_prices_enabled = v ? 1 : 0)" />
            </div>
            <div v-if="plan.featureEnabled('agt')" class="setting-row">
              <div>
                <div class="setting-label">{{ $t('Agt_Einvoice_Enabled') }}</div>
                <div class="setting-help">{{ $t('Agt_Einvoice_Enabled_Help') }}</div>
                <router-link v-if="setting.agt_einvoice_enabled && auth.can('agt_settings')" to="/settings/agt" style="font-size: 12px">{{ $t('Agt_Settings') }} →</router-link>
              </div>
              <a-switch v-model:checked="setting.agt_einvoice_enabled" />
            </div>
            <div v-if="plan.featureEnabled('dgi')" class="setting-row">
              <div>
                <div class="setting-label">{{ $t('Dgi_Einvoice_Enabled') }}</div>
                <div class="setting-help">{{ $t('Dgi_Einvoice_Enabled_Help') }}</div>
                <router-link v-if="setting.dgi_einvoice_enabled && auth.can('dgi_settings')" to="/settings/dgi" style="font-size: 12px">{{ $t('Dgi_Settings') }} →</router-link>
              </div>
              <a-switch v-model:checked="setting.dgi_einvoice_enabled" />
            </div>
          </template>

          <!-- ============================== Defaults ============================== -->
          <template v-else-if="activeTab === 'defaults'">
            <a-form layout="vertical" style="max-width: 680px">
              <a-row :gutter="16">
                <a-col :xs="24" :md="12">
                  <a-form-item :label="$t('DefaultCustomer')">
                    <a-select
                      v-model:value="setting.client_id" show-search option-filter-prop="label"
                      :options="clients.map(c => ({ label: c.name, value: c.id }))"
                    />
                  </a-form-item>
                </a-col>
                <a-col :xs="24" :md="12">
                  <a-form-item :label="$t('DefaultWarehouse')">
                    <a-select
                      v-model:value="setting.warehouse_id" show-search option-filter-prop="label"
                      :options="warehouses.map(w => ({ label: w.name, value: w.id }))"
                    />
                  </a-form-item>
                </a-col>
                <a-col :xs="24" :md="12">
                  <a-form-item :label="$t('Default_Account')">
                    <a-select
                      v-model:value="setting.default_account_id" allow-clear show-search option-filter-prop="label"
                      :options="accounts.map(a => ({ label: a.account_name, value: a.id }))"
                    />
                  </a-form-item>
                </a-col>
                <a-col :xs="24" :md="12">
                  <a-form-item :label="$t('Default_Payment_Method')">
                    <a-select
                      v-model:value="setting.default_payment_method_id" allow-clear show-search option-filter-prop="label"
                      :options="paymentMethods.map(p => ({ label: p.name, value: p.id }))"
                    />
                  </a-form-item>
                </a-col>
                <a-col :xs="24" :md="8">
                  <a-form-item :label="$t('Default_Tax')">
                    <a-input-number v-model:value="setting.default_tax" :min="0" :max="100" style="width: 100%" addon-after="%" />
                  </a-form-item>
                </a-col>
                <a-col :xs="24" :md="8">
                  <a-form-item :label="$t('Sys_Point_To_Amount_Rate')">
                    <a-input-number v-model:value="setting.point_to_amount_rate" :min="0" :step="0.01" style="width: 100%" />
                  </a-form-item>
                </a-col>
                <a-col :xs="24" :md="8">
                  <a-form-item :label="$t('Default_SMS_Gateway')">
                    <a-select
                      v-model:value="setting.sms_gateway" allow-clear
                      :options="(smsGateways || []).map(g => ({ label: g.title || g.name || g, value: g.id ?? g.name ?? g }))"
                    />
                  </a-form-item>
                </a-col>
                <a-col :xs="24" :md="8">
                  <a-form-item :label="$t('How_many_items_do_you_want_to_display_in_POS')">
                    <a-input-number v-model:value="pos_settings.products_per_page" :min="1" style="width: 100%" />
                  </a-form-item>
                </a-col>
              </a-row>
            </a-form>
            <a-divider style="margin: 8px 0 0" />
            <div class="setting-row">
              <div>
                <div class="setting-label">{{ $t('Create_Quotation_with_Stock') }}</div>
                <div class="setting-help">{{ $t('Sys_Quotation_With_Stock_Help') }}</div>
              </div>
              <a-switch :checked="asBool(setting.quotation_with_stock)" @change="v => (setting.quotation_with_stock = v ? 1 : 0)" />
            </div>
            <div class="setting-row">
              <div>
                <div class="setting-label">{{ $t('Show_Items_Tax') }}</div>
                <div class="setting-help">{{ $t('Sys_Show_Items_Tax_Help') }}</div>
              </div>
              <a-switch :checked="asBool(pos_settings.show_items_tax)" @change="v => (pos_settings.show_items_tax = v ? 1 : 0)" />
            </div>
          </template>

          <!-- ============================== Dashboard ============================== -->
          <template v-else-if="activeTab === 'dashboard'">
            <a-form layout="vertical" style="max-width: 680px">
              <a-row :gutter="16">
                <a-col :xs="24" :md="8">
                  <a-form-item :label="$t('Default_Dashboard_Date_Range')">
                    <a-select
                      v-model:value="setting.default_dashboard_date_range"
                      :options="[
                        { label: $t('Today'), value: 'today' },
                        { label: $t('ThisWeek'), value: 'week' },
                        { label: $t('ThisMonth'), value: 'month' },
                      ]"
                    />
                  </a-form-item>
                </a-col>
                <a-col :xs="24" :md="8">
                  <a-form-item :label="$t('Dashboard_Font_Size')">
                    <a-select
                      v-model:value="setting.dashboard_font_size"
                      :options="[
                        { label: $t('Default'), value: '' },
                        { label: $t('Sys_Font_Size_12'), value: '12' },
                        { label: $t('Sys_Font_Size_14'), value: '14' },
                        { label: $t('Sys_Font_Size_16'), value: '16' },
                        { label: $t('Sys_Font_Size_18'), value: '18' },
                      ]"
                    />
                  </a-form-item>
                </a-col>
                <a-col :xs="24" :md="8">
                  <a-form-item :label="$t('Dashboard_Font_Family')">
                    <a-select v-model:value="setting.dashboard_font_family" :options="fontFamilyOptions" />
                  </a-form-item>
                </a-col>
              </a-row>

              <a-form-item :label="$t('Default_Dashboard_Widget_Order')">
                <a-list size="small" bordered :data-source="sectionOrderList" style="max-width: 520px">
                  <template #renderItem="{ item, index }">
                    <a-list-item>
                      <span>{{ index + 1 }}. {{ $t(item.labelKey) }}</span>
                      <a-space>
                        <a-button size="small" :disabled="index === 0" @click="moveSection(index, -1)"><UpOutlined /></a-button>
                        <a-button size="small" :disabled="index === sectionOrderList.length - 1" @click="moveSection(index, 1)"><DownOutlined /></a-button>
                      </a-space>
                    </a-list-item>
                  </template>
                </a-list>
                <a-button size="small" style="margin-top: 8px" @click="resetSectionOrder">{{ $t('Reset_to_Default') }}</a-button>
              </a-form-item>
            </a-form>
          </template>

          <!-- ============================== DataTable ============================== -->
          <template v-else-if="activeTab === 'datatable'">
            <a-alert
              type="info" show-icon style="margin-bottom: 16px; max-width: 680px"
              :message="$t('Sys_Saved_On_This_Device')"
              :description="$t('Sys_Datatable_Prefs_Help')"
            />
            <a-form layout="vertical" style="max-width: 680px">
              <a-row :gutter="16">
                <a-col :xs="24" :md="12">
                  <a-form-item :label="$t('Font_Family')">
                    <a-select
                      :value="dtStore.fontFamily"
                      :options="[
                        { label: $t('Sys_Font_Default_Ant'), value: 'default' },
                        { label: 'Monospace', value: 'mono' },
                        { label: 'System UI', value: 'system' },
                        { label: 'Serif', value: 'serif' },
                      ]"
                      @change="v => dtStore.set({ fontFamily: v })"
                    />
                  </a-form-item>
                </a-col>
                <a-col :xs="12" :md="6">
                  <a-form-item :label="$t('Font_Size')">
                    <a-input-number
                      :value="dtStore.fontSize" :min="11" :max="18" style="width: 100%"
                      @change="v => dtStore.set({ fontSize: Number(v) || 13 })"
                    />
                  </a-form-item>
                </a-col>
                <a-col :xs="12" :md="6">
                  <a-form-item :label="$t('Sys_Row_Density')">
                    <a-select
                      :value="dtStore.density"
                      :options="[
                        { label: $t('Compact'), value: 'small' },
                        { label: $t('Medium'), value: 'middle' },
                        { label: $t('Large'), value: 'large' },
                      ]"
                      @change="v => dtStore.set({ density: v })"
                    />
                  </a-form-item>
                </a-col>
              </a-row>

              <a-row :gutter="[16, 8]">
                <a-col :xs="12" :md="8">
                  <a-form-item :label="$t('Sys_Cell_Borders')">
                    <a-switch :checked="dtStore.bordered" @change="v => dtStore.set({ bordered: v })" />
                  </a-form-item>
                </a-col>
                <a-col :xs="12" :md="8">
                  <a-form-item :label="$t('Set_Striped_Rows')">
                    <a-switch :checked="dtStore.striped" @change="v => dtStore.set({ striped: v })" />
                  </a-form-item>
                </a-col>
                <a-col :xs="12" :md="8">
                  <a-form-item :label="$t('Sys_Sortable_Columns')">
                    <a-switch :checked="dtStore.autoSort" @change="v => dtStore.set({ autoSort: v })" />
                  </a-form-item>
                </a-col>
              </a-row>

              <a-divider orientation="left" style="margin: 8px 0 16px">{{ $t('Sys_Header_Thead') }}</a-divider>
              <a-row :gutter="[16, 8]">
                <a-col :xs="12" :md="6">
                  <a-form-item :label="$t('Font_Weight')">
                    <a-select
                      :value="dtStore.headerWeight"
                      :options="[
                        { label: $t('Hm_Normal'), value: '400' },
                        { label: $t('Medium'), value: '500' },
                        { label: $t('Semibold'), value: '600' },
                        { label: $t('Bold'), value: '700' },
                      ]"
                      @change="v => dtStore.set({ headerWeight: v })"
                    />
                  </a-form-item>
                </a-col>
                <a-col :xs="12" :md="6">
                  <a-form-item :label="$t('Font_Size')">
                    <a-input-number
                      :value="dtStore.headerFontSize" :min="11" :max="18" style="width: 100%"
                      @change="v => dtStore.set({ headerFontSize: Number(v) || 14 })"
                    />
                  </a-form-item>
                </a-col>
                <a-col :xs="12" :md="6">
                  <a-form-item :label="$t('Uppercase')">
                    <a-switch :checked="dtStore.headerUppercase" @change="v => dtStore.set({ headerUppercase: v })" />
                  </a-form-item>
                </a-col>
              </a-row>
              <a-row :gutter="[16, 8]">
                <a-col :xs="12" :md="6">
                  <a-form-item :label="$t('Set_Background_Color')">
                    <a-space>
                      <input
                        type="color" class="dt-color-input"
                        :value="dtStore.headerBg || '#fafafa'"
                        @input="e => dtStore.set({ headerBg: e.target.value })"
                      />
                      <a-button v-if="dtStore.headerBg" size="small" @click="dtStore.set({ headerBg: '' })">
                        {{ $t('Reset') }}
                      </a-button>
                    </a-space>
                  </a-form-item>
                </a-col>
                <a-col :xs="12" :md="6">
                  <a-form-item :label="$t('Set_Text_Color')">
                    <a-space>
                      <input
                        type="color" class="dt-color-input"
                        :value="dtStore.headerColor || '#000000'"
                        @input="e => dtStore.set({ headerColor: e.target.value })"
                      />
                      <a-button v-if="dtStore.headerColor" size="small" @click="dtStore.set({ headerColor: '' })">
                        {{ $t('Reset') }}
                      </a-button>
                    </a-space>
                  </a-form-item>
                </a-col>
              </a-row>

              <a-divider orientation="left" style="margin: 8px 0 16px">{{ $t('Sys_Toolbar_Components') }}</a-divider>
              <a-row :gutter="[16, 8]">
                <a-col :xs="12" :md="8">
                  <a-form-item :label="$t('Search')">
                    <a-switch :checked="dtStore.showSearch" @change="v => dtStore.set({ showSearch: v })" />
                  </a-form-item>
                </a-col>
                <a-col :xs="12" :md="8">
                  <a-form-item :label="$t('Sys_Column_Visibility')">
                    <a-switch :checked="dtStore.showColumns" @change="v => dtStore.set({ showColumns: v })" />
                  </a-form-item>
                </a-col>
                <a-col :xs="12" :md="8">
                  <a-form-item :label="$t('Refresh')">
                    <a-switch :checked="dtStore.showRefresh" @change="v => dtStore.set({ showRefresh: v })" />
                  </a-form-item>
                </a-col>
              </a-row>

              <a-button size="small" @click="dtStore.reset()">{{ $t('Reset_to_Default') }}</a-button>

              <!-- Live preview -->
              <a-divider orientation="left" style="margin: 20px 0 12px">{{ $t('Preview') }}</a-divider>
              <a-table
                :columns="dtPreviewColumns"
                :data-source="dtPreviewRows"
                :pagination="false"
                :size="dtStore.density"
                :bordered="dtStore.bordered"
                :row-class-name="(_r, i) => (dtStore.striped && i % 2 === 1 ? 'dt-preview-striped' : '')"
                row-key="ref"
                :style="{
                  '--dtp-font': dtStore.fontStack || 'inherit',
                  '--dtp-size': dtStore.fontSize + 'px',
                  '--dtp-th-weight': dtStore.headerWeight || '600',
                  '--dtp-th-size': (dtStore.headerFontSize || 14) + 'px',
                  '--dtp-th-transform': dtStore.headerUppercase ? 'uppercase' : 'none',
                  ...(dtStore.headerBg ? { '--dtp-th-bg': dtStore.headerBg } : {}),
                  ...(dtStore.headerColor ? { '--dtp-th-color': dtStore.headerColor } : {}),
                }"
                class="dt-preview"
                :class="{ 'dtp-th-bg': !!dtStore.headerBg, 'dtp-th-color': !!dtStore.headerColor }"
              />
            </a-form>
          </template>

          <!-- ============================== Export ============================== -->
          <template v-else-if="activeTab === 'export'">
            <div class="setting-row" style="border-top: none; padding-top: 0">
              <div>
                <div class="setting-label">{{ $t('Sys_Export_Full_Data') }}</div>
                <div class="setting-help">
                  {{ $t('Sys_Export_Full_Data_Help') }}
                </div>
              </div>
              <a-switch :checked="exportForm.scope !== 'page'" @change="v => (exportForm.scope = v ? 'all' : 'page')" />
            </div>
            <div class="setting-row">
              <div>
                <div class="setting-label">{{ $t('Sys_Include_Totals_Row') }}</div>
                <div class="setting-help">{{ $t('Sys_Include_Totals_Row_Help') }}</div>
              </div>
              <a-switch v-model:checked="exportForm.totals" />
            </div>
            <div class="setting-row">
              <div>
                <div class="setting-label">{{ $t('Sys_PDF_Orientation') }}</div>
                <div class="setting-help">{{ $t('Sys_PDF_Orientation_Help') }}</div>
              </div>
              <a-radio-group v-model:value="exportForm.pdf_orientation" size="small">
                <a-radio-button value="landscape">{{ $t('Landscape') }}</a-radio-button>
                <a-radio-button value="portrait">{{ $t('Portrait') }}</a-radio-button>
              </a-radio-group>
            </div>
            <div class="setting-row">
              <div>
                <div class="setting-label">{{ $t('Sys_Filename_Date') }}</div>
                <div class="setting-help">{{ $t('Sys_Filename_Date_Help') }}</div>
              </div>
              <a-switch v-model:checked="exportForm.filename_date" />
            </div>
            <div class="setting-row">
              <div>
                <div class="setting-label">{{ $t('Sys_PDF_Meta') }}</div>
                <div class="setting-help">{{ $t('Sys_PDF_Meta_Help') }}</div>
              </div>
              <a-switch v-model:checked="exportForm.pdf_meta" />
            </div>
          </template>

          <!-- ============================== Prefixes ============================== -->
          <template v-else-if="activeTab === 'prefixes'">
            <a-form layout="vertical" style="max-width: 680px">
              <a-row :gutter="16">
                <a-col v-for="p in prefixFields" :key="p.field" :xs="12" :md="8">
                  <a-form-item :label="$t(p.label)">
                    <a-input v-model:value="setting[p.field]" :placeholder="p.example" />
                  </a-form-item>
                </a-col>
              </a-row>

              <!-- Codes Stocky generates for products: prefix + number, so they
                   never clash with codes from another system. Blank = random. -->
              <a-divider orientation="left" style="font-size: 14px">{{ $t('Product_Code_Numbering') }}</a-divider>
              <a-row :gutter="16">
                <a-col :xs="12" :md="8">
                  <a-form-item :label="$t('Product_Code_Prefix')">
                    <a-input v-model:value="setting.product_code_prefix" :maxlength="20" placeholder="STK-" />
                  </a-form-item>
                </a-col>
                <a-col :xs="12" :md="8">
                  <a-form-item :label="$t('Product_Code_Next')">
                    <a-input-number v-model:value="setting.product_code_next" :min="1" :precision="0" style="width: 100%" placeholder="1" />
                  </a-form-item>
                </a-col>
              </a-row>
              <a-alert type="info" show-icon :message="$t('Product_Code_Numbering_Help')" />
            </a-form>
          </template>

          <!-- The Invoice Format tab was dissolved: the format select moved to
               POS Settings, the footer to Invoice PDF. The on-screen logo size
               fields (invoice_logo_width/height) have no UI anymore but stay
               in the save payload so the stored values are preserved. -->

          <!-- ============================== Pharmacy ============================== -->
          <template v-else-if="activeTab === 'pharmacy'">
            <div class="setting-row" style="border-top: none; padding-top: 0">
              <div>
                <div class="setting-label">{{ $t('Pharmacy_Mode') }}</div>
                <div class="setting-help">{{ $t('Track_Batches_Expiry_Help') }}</div>
              </div>
              <a-switch v-model:checked="setting.pharmacy_mode" />
            </div>
            <div class="setting-row">
              <div>
                <div class="setting-label">{{ $t('Expiry_Warning_Days') }}</div>
                <div class="setting-help">{{ $t('Expiry_Warning_Days_Help') }}</div>
              </div>
              <a-input-number v-model:value="setting.expiry_warning_days" :min="0" style="width: 120px" />
            </div>
            <div class="setting-row">
              <div>
                <div class="setting-label">{{ $t('Block_Expired_Sale') }}</div>
                <div class="setting-help">{{ $t('Block_Expired_Sale_Help') }}</div>
              </div>
              <a-switch v-model:checked="setting.block_expired_sale" />
            </div>
            <div class="setting-row">
              <div>
                <div class="setting-label">{{ $t('Print_Expiry_On_Receipt') }}</div>
                <div class="setting-help">{{ $t('Print_Expiry_On_Receipt_Help') }}</div>
              </div>
              <a-switch v-model:checked="setting.print_expiry_on_receipt" />
            </div>
          </template>

          <!-- ============================== Backup ============================== -->
          <template v-else-if="activeTab === 'backup'">
            <div class="setting-row" style="border-top: none; padding-top: 0">
              <div>
                <div class="setting-label">{{ $t('Sys_Cloud_Backup_Destination') }}</div>
                <div class="setting-help">
                  {{ $t('Sys_Cloud_Backup_Help') }}
                  <router-link to="/settings/backup">{{ $t('Sys_Manage_Backup_Archives') }}</router-link>
                </div>
              </div>
              <a-switch v-model:checked="setting.backup_cloud_enabled" />
            </div>
            <a-form v-if="setting.backup_cloud_enabled" layout="vertical" style="max-width: 680px; margin-top: 12px">
              <a-row :gutter="16">
                <a-col :xs="24" :md="12">
                  <a-form-item :label="$t('Provider')">
                    <a-select
                      v-model:value="setting.backup_cloud_provider" allow-clear
                      :options="[
                        { label: $t('Sys_S3_Compatible'), value: 's3' },
                        { label: 'Google Drive', value: 'gdrive' },
                        { label: 'Dropbox', value: 'dropbox' },
                      ]"
                    />
                  </a-form-item>
                </a-col>
                <a-col :xs="24" :md="12">
                  <a-form-item :label="$t('Path')">
                    <a-input v-model:value="setting.backup_cloud_path" />
                  </a-form-item>
                </a-col>
              </a-row>
              <a-row :gutter="16" v-if="setting.backup_cloud_provider === 's3'">
                <a-col :xs="24" :md="8"><a-form-item :label="$t('Bucket')"><a-input v-model:value="setting.backup_s3_bucket" /></a-form-item></a-col>
                <a-col :xs="24" :md="8"><a-form-item :label="$t('Region')"><a-input v-model:value="setting.backup_s3_region" /></a-form-item></a-col>
                <a-col :xs="24" :md="8"><a-form-item :label="$t('Endpoint')"><a-input v-model:value="setting.backup_s3_endpoint" /></a-form-item></a-col>
                <a-col :xs="24" :md="8"><a-form-item :label="$t('Sys_Access_Key')"><a-input v-model:value="setting.backup_s3_access_key" /></a-form-item></a-col>
                <a-col :xs="24" :md="8"><a-form-item :label="$t('Sys_Secret_Key')"><a-input-password v-model:value="setting.backup_s3_secret_key" /></a-form-item></a-col>
                <a-col :xs="24" :md="8"><a-form-item label=" "><span><a-switch v-model:checked="setting.backup_s3_path_style" size="small" /> {{ $t('Sys_Path_Style_URLs') }}</span></a-form-item></a-col>
              </a-row>
              <a-row :gutter="16" v-if="setting.backup_cloud_provider === 'gdrive'">
                <a-col :xs="24" :md="12"><a-form-item :label="$t('Sys_Folder_ID')"><a-input v-model:value="setting.backup_gdrive_folder_id" /></a-form-item></a-col>
                <a-col :xs="24" :md="12"><a-form-item :label="$t('Client_ID')"><a-input v-model:value="setting.backup_gdrive_client_id" /></a-form-item></a-col>
                <a-col :xs="24" :md="12"><a-form-item :label="$t('Client_Secret')"><a-input-password v-model:value="setting.backup_gdrive_client_secret" /></a-form-item></a-col>
                <a-col :xs="24" :md="12"><a-form-item :label="$t('Access_Token')"><a-input-password v-model:value="setting.backup_gdrive_access_token" /></a-form-item></a-col>
                <a-col :xs="24" :md="12"><a-form-item :label="$t('Sys_Refresh_Token')"><a-input-password v-model:value="setting.backup_gdrive_refresh_token" /></a-form-item></a-col>
              </a-row>
              <a-row :gutter="16" v-if="setting.backup_cloud_provider === 'dropbox'">
                <a-col :xs="24" :md="12"><a-form-item :label="$t('Path')"><a-input v-model:value="setting.backup_dropbox_path" /></a-form-item></a-col>
                <a-col :xs="24" :md="12"><a-form-item :label="$t('Access_Token')"><a-input-password v-model:value="setting.backup_dropbox_access_token" /></a-form-item></a-col>
              </a-row>
            </a-form>
          </template>

          <!-- Security is an embedded page (SecuritySettings.vue). -->

          <!-- ============================== Maintenance ============================== -->
          <template v-else-if="activeTab === 'system'">
            <!-- No Debug Mode switch here: APP_DEBUG is a platform-wide .env
                 value in this SaaS build, not a per-tenant setting. -->
            <div class="setting-row" style="border-top: none; padding-top: 0">
              <div>
                <div class="setting-label">{{ $t('Sys_Offline_Sync_POS') }}</div>
                <div class="setting-help">{{ $t('Sys_Offline_Sync_Help') }}</div>
              </div>
              <a-switch v-model:checked="setting.offline_sync_enabled" />
            </div>
            <div class="setting-row">
              <div>
                <div class="setting-label">{{ $t('Clear_Cache') }}</div>
                <div class="setting-help">{{ $t('Sys_Clear_Cache_Help') }}</div>
              </div>
              <a-button :loading="clearingCache" @click="clearCache"><ClearOutlined /> {{ $t('Clear_Cache') }}</a-button>
            </div>
          </template>

          <!-- ============================== Calendar ============================== -->
          <template v-else-if="activeTab === 'calendar'">
            <a-skeleton v-if="calendarLoading" active :paragraph="{ rows: 5 }" />
            <template v-else>
            <a-alert
              :type="calendarForm.google_calendar_connected ? 'success' : 'info'" show-icon
              :message="calendarForm.google_calendar_connected ? $t('Sys_Google_Calendar_Connected') : $t('Sys_Google_Calendar_Not_Connected')"
              style="margin-bottom: 16px; max-width: 680px"
            >
              <template #description>
                <a v-if="!calendarForm.google_calendar_connected && calendarForm.google_calendar_connect_url" :href="calendarForm.google_calendar_connect_url">{{ $t('Connect') }}</a>
                <a v-else-if="calendarForm.google_calendar_connected && calendarForm.google_calendar_disconnect_url" :href="calendarForm.google_calendar_disconnect_url">{{ $t('Disconnect') }}</a>
              </template>
            </a-alert>
            <a-form layout="vertical" style="max-width: 680px">
              <a-row :gutter="16">
                <a-col :xs="24" :md="12"><a-form-item :label="$t('Client_ID')"><a-input v-model:value="calendarForm.google_calendar_client_id" /></a-form-item></a-col>
                <a-col :xs="24" :md="12"><a-form-item :label="$t('Client_Secret')"><a-input-password v-model:value="calendarForm.google_calendar_client_secret" /></a-form-item></a-col>
                <a-col :xs="24" :md="12"><a-form-item :label="$t('Redirect_URI')"><a-input v-model:value="calendarForm.google_calendar_redirect_uri" /></a-form-item></a-col>
                <a-col :xs="24" :md="12"><a-form-item :label="$t('Calendar_ID')"><a-input v-model:value="calendarForm.google_calendar_calendar_id" /></a-form-item></a-col>
              </a-row>
            </a-form>
            </template>
          </template>

          <!-- Save bar -->
          <a-divider style="margin: 20px 0 16px" />
          <a-button
            v-if="activeTab === 'calendar'"
            type="primary" :loading="calendarSaving" @click="saveCalendar"
          >
            {{ $t('submit') }}
          </a-button>
          <a-button v-else type="primary" :loading="saving" @click="save">
            {{ $t('submit') }}
          </a-button>
        </a-card>
        </transition>
      </a-col>
    </a-row>
  </div>
</template>

<script setup>
/**
 * System settings hub. The legacy page fused ~23 tabs; domains that already
 * have dedicated migrated pages (appearance, PWA, mail, SMS, payment
 * gateway, POS settings/receipt, custom fields, backup archive list, login
 * devices) are LINKED, not duplicated. This page owns the core settings
 * record:
 * - GET get_Settings_data_api?include_secrets=1 → {settings, currencies,
 *   clients, warehouses, accounts, payment_methods, sms_gateway, zones_array,
 *   languages}
 * - POST settings/{id} (FormData + _method=put) — sends the FULL legacy field
 *   list every save, so unrendered fields still round-trip losslessly
 * - sidecar PUT pos_settings/{id} {products_per_page, show_items_tax}
 * - GET clear_cache; GET/PATCH settings/calendar (Google Calendar)
 * Post-save side effects kept: date_format → localStorage app_date_format,
 * price format + decimals caches, ui-store dark/rtl sync, locale switch.
 */
import { ref, computed, onMounted, h } from 'vue';
import { message, Skeleton } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { defineAsyncComponent } from 'vue';
import {
  UploadOutlined, UpOutlined, DownOutlined, ClearOutlined,
  ShopOutlined, GlobalOutlined, AuditOutlined, ControlOutlined, ExportOutlined,
  ThunderboltOutlined, NumberOutlined, FileTextOutlined, FilePdfOutlined, MedicineBoxOutlined,
  AppstoreOutlined, AppstoreAddOutlined, SafetyOutlined, CloudUploadOutlined, ToolOutlined,
  CalendarOutlined, SkinOutlined, LoginOutlined, MobileOutlined, TabletOutlined, MailOutlined, MessageOutlined,
  CreditCardOutlined, FormOutlined, DatabaseOutlined, LaptopOutlined,
  MenuOutlined, ExperimentOutlined, TableOutlined, PrinterOutlined, ScanOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import { useUiStore } from '../../stores/ui';
import { useAuthStore } from '../../stores/auth';
import { usePlanStore } from '../../stores/plan';
import { useDatatableStore } from '../../stores/datatable';
import http from '../../lib/http';
import { uploadForm } from '../../lib/upload';
import { confirmVirtualComboSwitch } from './virtualComboConfirm';
import { buildFontOptions } from '../../lib/fontOptions';
import { CURRENCY_POSITIONS, normalizeCurrencyPosition, withCurrency } from '../../lib/priceFormat';

const { t, locale } = useI18n();
const ui = useUiStore();
const auth = useAuthStore();
const plan = usePlanStore();

/* --------------------------- DataTable preferences (device-local) */
const dtStore = useDatatableStore();
const dtPreviewColumns = computed(() => [
  { title: t('Reference'), dataIndex: 'ref', key: 'ref' },
  { title: t('Name'), dataIndex: 'name', key: 'name' },
  { title: t('Amount'), dataIndex: 'amount', key: 'amount', align: 'right' },
]);
const dtPreviewRows = [
  { ref: 'SL-2941', name: 'Sarah Miller', amount: '1,284.00' },
  { ref: 'SL-2940', name: 'Omar Khalil', amount: '96.20' },
  { ref: 'SL-2939', name: 'Lina Berrada', amount: '452.75' },
];

const isLoading = ref(true);
const saving = ref(false);
const activeTab = ref('general');
const selectedKeys = ref(['general']);

// Domains with dedicated migrated pages, embedded here as sections so the
// whole settings surface lives on ONE page like legacy. Loaded lazily —
// a section's chunk only downloads when it is opened.
// While a section's chunk downloads, show a skeleton instead of a blank hole.
const SectionLoading = {
  name: 'SectionLoading',
  render: () => h('div', { class: 'section-loading' }, [
    h(Skeleton, { active: true, paragraph: { rows: 6 } }),
  ]),
};
const lazySection = loader => defineAsyncComponent({ loader, loadingComponent: SectionLoading, delay: 120 });

const embeddedPages = {
  appearance: lazySection(() => import('./AppearanceSettings.vue')),
  login_page: lazySection(() => import('./LoginPageSettings.vue')),
  pwa: lazySection(() => import('./PwaSettings.vue')),
  mobile_app: lazySection(() => import('./MobileAppSettings.vue')),
  pos_settings: lazySection(() => import('./PosSettings.vue')),
  pos_receipt: lazySection(() => import('./PosReceipt.vue')),
  weight_scale: lazySection(() => import('./ScaleSettings.vue')),
  mail: lazySection(() => import('./MailSettings.vue')),
  sms: lazySection(() => import('./SmsSettings.vue')),
  payment: lazySection(() => import('./PaymentGateway.vue')),
  zatca: lazySection(() => import('./ZatcaSettings.vue')),
  custom_fields: lazySection(() => import('./CustomFields.vue')),
  backups: lazySection(() => import('./Backup.vue')),
  devices: lazySection(() => import('./LoginDevices.vue')),
  invoice_pdf: lazySection(() => import('./InvoicePdfSettings.vue')),
  security: lazySection(() => import('./SecuritySettings.vue')),
  // (module toggles, sidebar manager, demo data and direct network
  //  printing are stocky features without an API on this backend — removed.)
};

// The embedded ZATCA page writes zatca_enabled / vat_number / company_name_ar
// straight to the settings row; mirror them here because save() always posts
// the FULL row and would otherwise push a stale copy back.
function onZatcaPhase1Saved(company) {
  if (!company) return;
  setting.value.zatca_enabled = !!company.zatca_enabled;
  setting.value.vat_number = company.vat_number || '';
  setting.value.company_name_ar = company.name_ar || '';
}

function onMenuClick({ key }) {
  activeTab.value = String(key);
  selectedKeys.value = [String(key)];
  // Tab-scoped data loads on first open only (then cached in memory).
  if (key === 'calendar' && !calendarLoaded.value && !calendarLoading.value) loadCalendar();
}

const currentSection = computed(() => ({
  general: { title: t('General'), desc: t('Sys_Desc_General') },
  localization: { title: t('Localization'), desc: t('Sys_Desc_Localization') },
  zatca: { title: t('Zatca_E_Invoicing'), desc: t('Sys_Desc_Zatca') },
  defaults: { title: t('Defaults'), desc: t('Sys_Desc_Defaults') },
  features: { title: t('Features'), desc: t('Sys_Desc_Features') },
  prefixes: { title: t('Prefixes'), desc: t('Sys_Desc_Prefixes') },
  invoice_pdf: { title: t('Set_Invoice_PDF'), desc: t('Set_Desc_Invoice_PDF') },
  pharmacy: { title: t('Pharmacy'), desc: t('Sys_Desc_Pharmacy') },
  dashboard: { title: t('Dashboard'), desc: t('Sys_Desc_Dashboard') },
  modules: { title: t('Modules'), desc: t('Sys_Desc_Modules') },
  sidebar: { title: t('Sys_Sidebar_Menu'), desc: t('Sys_Desc_Sidebar') },
  datatable: { title: t('Sys_DataTable'), desc: t('Sys_Desc_Datatable') },
  export: { title: t('Export'), desc: t('Sys_Desc_Export') },
  demo: { title: t('Sys_Demo_Data'), desc: t('Sys_Desc_Demo') },
  security: { title: t('Security'), desc: t('Sys_Desc_Security') },
  backup: { title: t('Backup'), desc: t('Sys_Desc_Backup') },
  system: { title: t('Maintenance'), desc: t('Sys_Desc_System') },
  calendar: { title: t('Calendar'), desc: t('Sys_Desc_Calendar') },
  appearance: { title: t('Page_Appearance'), desc: t('Sys_Desc_Appearance') },
  login_page: { title: t('Sys_Login_Page'), desc: t('Sys_Desc_Login_Page') },
  pwa: { title: t('PWA_Settings'), desc: t('PWA_Settings_Help') },
  mobile_app: { title: t('Mobile_App_Settings'), desc: t('Mobile_App_Settings_Help') },
  pos_settings: { title: t('Pos_Settings'), desc: t('Sys_Desc_Pos_Settings') },
  pos_receipt: { title: t('POS_Receipt'), desc: t('Sys_Desc_Pos_Receipt') },
  weight_scale: { title: t('Scale_Weighing_Scale'), desc: t('Scale_Desc') },
  network_printing: { title: t('Direct_Network_Printing'), desc: t('Sys_Desc_Network_Printing') },
  mail: { title: t('Mail'), desc: t('Sys_Desc_Mail') },
  sms: { title: 'SMS', desc: t('Sys_Desc_Sms') },
  payment: { title: t('Payment_Gateway'), desc: t('Sys_Desc_Payment') },
  custom_fields: { title: t('CustomFields'), desc: t('Sys_Desc_Custom_Fields') },
  backups: { title: t('Backup'), desc: t('Sys_Desc_Backups') },
  devices: { title: t('Page_Login_Devices'), desc: t('Sys_Desc_Devices') },
}[activeTab.value] || { title: '', desc: '' }));

const setting = ref({});
// Virtual combo stock: the saved value; the server's warning about leaving it
// travels in setting.virtual_combo_stock_warning.
const savedVirtualCombo = ref(false);

function onVirtualComboChange(checked) {
  confirmVirtualComboSwitch({
    t,
    checked,
    saved: savedVirtualCombo.value,
    warning: setting.value.virtual_combo_stock_warning,
    revert: () => { setting.value.virtual_combo_stock = !checked; },
  });
}
const pos_settings = ref({});
const currencies = ref([]);
const clients = ref([]);
const warehouses = ref([]);
const accounts = ref([]);
const paymentMethods = ref([]);
const smsGateways = ref([]);
const zonesArray = ref([]);
const languages = ref([]);

// Currency Position: each option previews the selected default currency.
const currencyPositionOptions = computed(() => {
  const cur = currencies.value.find(c => Number(c.id) === Number(setting.value.currency_id));
  const symbol = cur?.symbol || auth.currency;
  const labels = {
    before: 'Sys_Currency_Position_Before',
    before_nospace: 'Sys_Currency_Position_Before_NoSpace',
    after: 'Sys_Currency_Position_After',
    after_nospace: 'Sys_Currency_Position_After_NoSpace',
  };
  return CURRENCY_POSITIONS.map(position => ({
    value: position,
    label: `${t(labels[position])} (${withCurrency(symbol, '100', position)})`,
  }));
});

const logoFile = ref(null);
const logoFileList = ref([]);

function asBool(v) {
  return v === 1 || v === '1' || v === true;
}

// Grouped Google + system font options, served as font_options by
// get_Settings_data_api (see Setting::fontOptions). The Google family chosen
// here is loaded by partials/tenant-font.blade.php across the dashboard.
const fontOptionsPayload = ref(null);
const fontFamilyOptions = computed(() => buildFontOptions(fontOptionsPayload.value, t, {
  current: setting.value.dashboard_font_family,
  defaultLabel: t('System_Default'),
}));

/* -------------------------------------------------------------- export */
// Mirrors the defaults in lib/exporters.js exportPrefs(); stored as JSON in
// settings.export_settings and served to every user via get_user_auth.
const EXPORT_DEFAULTS = { scope: 'all', totals: true, pdf_orientation: 'landscape', filename_date: false, pdf_meta: false };
const exportForm = ref({ ...EXPORT_DEFAULTS });

function syncExportForm() {
  let stored = setting.value.export_settings;
  try {
    if (typeof stored === 'string') stored = JSON.parse(stored);
  } catch (e) { stored = null; }
  exportForm.value = { ...EXPORT_DEFAULTS, ...(stored && typeof stored === 'object' ? stored : {}) };
}

/* ------------------------------------------------------------- prefixes */
const prefixFields = [
  { field: 'sale_prefix', label: 'Sales', example: 'SL' },
  { field: 'purchase_prefix', label: 'Purchases', example: 'PR' },
  { field: 'quotation_prefix', label: 'Quotations', example: 'QT' },
  { field: 'adjustment_prefix', label: 'Adjustment', example: 'AD' },
  { field: 'transfer_prefix', label: 'Transfer', example: 'TR' },
  { field: 'sale_return_prefix', label: 'SalesReturn', example: 'RS' },
  { field: 'purchase_return_prefix', label: 'PurchasesReturn', example: 'RP' },
];

/* ----------------------------------------------------- dashboard sections */
const DEFAULT_SECTIONS = [
  { id: 'header', labelKey: 'Dashboard_Header' },
  { id: 'stat_cards_1', labelKey: 'Dashboard_Stat_Cards_1' },
  { id: 'stat_cards_2', labelKey: 'Dashboard_Stat_Cards_2' },
  { id: 'chart_sales_purchases', labelKey: 'Dashboard_Chart_Sales_Purchases' },
  { id: 'chart_top_selling', labelKey: 'Dashboard_Chart_Top_Selling' },
  { id: 'sales_by_payment_stock_value', labelKey: 'Dashboard_Sales_By_Payment_Stock' },
  { id: 'chart_payment_sent_received', labelKey: 'Dashboard_Chart_Payment_Sent_Received' },
  { id: 'chart_top_customers', labelKey: 'Dashboard_Chart_Top_Customers' },
  { id: 'table_stock_alert', labelKey: 'StockAlert' },
  { id: 'table_top_selling_products', labelKey: 'Top_Selling_Products' },
  { id: 'table_recent_sales', labelKey: 'Recent_Sales' },
];
const sectionOrderList = ref([]);

function syncSectionOrder() {
  let order = [];
  try {
    const raw = setting.value.dashboard_section_order;
    if (raw && typeof raw === 'string') order = JSON.parse(raw);
    else if (Array.isArray(raw)) order = raw;
  } catch (e) { /* fall back to defaults */ }
  const byId = {};
  DEFAULT_SECTIONS.forEach(s => { byId[s.id] = s; });
  const ordered = [];
  order.forEach(id => {
    if (byId[id]) { ordered.push(byId[id]); delete byId[id]; }
  });
  DEFAULT_SECTIONS.forEach(s => { if (byId[s.id]) ordered.push(s); });
  sectionOrderList.value = ordered;
}
function commitSectionOrder() {
  setting.value.dashboard_section_order = JSON.stringify(sectionOrderList.value.map(x => x.id));
}
function moveSection(index, dir) {
  const arr = sectionOrderList.value;
  const target = index + dir;
  if (target < 0 || target >= arr.length) return;
  [arr[index], arr[target]] = [arr[target], arr[index]];
  commitSectionOrder();
}
function resetSectionOrder() {
  sectionOrderList.value = [...DEFAULT_SECTIONS];
  commitSectionOrder();
}

/* ------------------------------------------------------------------ logo */
function onLogoPicked(file) {
  logoFile.value = file;
  logoFileList.value = [file];
  return false;
}

/* ------------------------------------------------------------------ save */
async function save() {
  saving.value = true;
  const s = setting.value;
  const fd = new FormData();
  fd.append('client', s.client_id);
  fd.append('warehouse', s.warehouse_id);
  fd.append('default_account', s.default_account_id || '');
  fd.append('default_payment_method', s.default_payment_method_id || '');
  fd.append('currency', s.currency_id);
  fd.append('email', s.email);
  fd.append('logo', logoFile.value || s.logo);
  fd.append('CompanyName', s.CompanyName);
  fd.append('CompanyPhone', s.CompanyPhone);
  fd.append('CompanyAdress', s.CompanyAdress);
  fd.append('company_name_ar', s.company_name_ar || '');
  fd.append('vat_number', s.vat_number || '');
  fd.append('zatca_enabled', s.zatca_enabled ? 1 : 0);
  fd.append('footer', s.footer);
  fd.append('developed_by', s.developed_by);
  fd.append('default_language', s.default_language);
  fd.append('sms_gateway', s.sms_gateway);
  fd.append('is_invoice_footer', s.is_invoice_footer);
  fd.append('invoice_footer', s.invoice_footer);
  fd.append('invoice_format', s.invoice_format || 'thermal');
  fd.append('invoice_logo_width', s.invoice_logo_width || 180);
  fd.append('invoice_logo_height', s.invoice_logo_height || 60);
  fd.append('sidebar_logo_width', s.sidebar_logo_width || 32);
  fd.append('sidebar_logo_height', s.sidebar_logo_height || 32);
  fd.append('sidebar_show_logo', asBool(s.sidebar_show_logo) ? 1 : 0);
  fd.append('hide_site_name', asBool(s.hide_site_name) ? 1 : 0);
  fd.append('quotation_with_stock', s.quotation_with_stock);
  fd.append('show_language', s.show_language);
  fd.append('timezone', s.timezone);
  fd.append('date_format', s.date_format || 'YYYY-MM-DD');
  fd.append('price_format', s.price_format || '');
  fd.append('currency_position', normalizeCurrencyPosition(s.currency_position));
  fd.append('hide_zero_decimals', asBool(s.hide_zero_decimals) ? 1 : 0);
  fd.append('supplier_wallet_enabled', asBool(s.supplier_wallet_enabled) ? 1 : 0);
  fd.append('supplier_wallet_allow_negative', asBool(s.supplier_wallet_allow_negative) ? 1 : 0);
  fd.append('customer_wallet_enabled', asBool(s.customer_wallet_enabled) ? 1 : 0);
  fd.append('customer_wallet_allow_negative', asBool(s.customer_wallet_allow_negative) ? 1 : 0);
  fd.append('purchase_update_selling_price', asBool(s.purchase_update_selling_price) ? 1 : 0);
  fd.append('purchase_update_product_cost', asBool(s.purchase_update_product_cost) ? 1 : 0);
  fd.append('warehouse_prices_enabled', asBool(s.warehouse_prices_enabled) ? 1 : 0);
  fd.append('point_to_amount_rate', s.point_to_amount_rate);
  fd.append('default_tax', s.default_tax || 0);
  fd.append('default_dashboard_date_range', s.default_dashboard_date_range || 'week');
  fd.append('dashboard_section_order', typeof s.dashboard_section_order === 'string' ? s.dashboard_section_order : JSON.stringify(s.dashboard_section_order || []));
  fd.append('dashboard_font_size', s.dashboard_font_size || '');
  fd.append('dashboard_font_family', s.dashboard_font_family || '');
  fd.append('dark_mode', s.dark_mode ? 1 : 0);
  fd.append('rtl', s.rtl ? 1 : 0);
  fd.append('offline_sync_enabled', s.offline_sync_enabled ? 1 : 0);
  fd.append('enable_3_decimal_pricing', s.enable_3_decimal_pricing ? 1 : 0);
  fd.append('enable_kitchen_display', s.enable_kitchen_display ? 1 : 0);
  fd.append('show_product_gtin', s.show_product_gtin ? 1 : 0);
  fd.append('product_image_resize', s.product_image_resize ? 1 : 0);
  fd.append('product_image_max_size', s.product_image_max_size || 800);
  fd.append('show_serial_tracking', s.show_serial_tracking ? 1 : 0);
  fd.append('enable_multi_pack_selling', s.enable_multi_pack_selling ? 1 : 0);
  fd.append('enable_warehouse_letterhead', s.enable_warehouse_letterhead ? 1 : 0);
  fd.append('enable_multi_currency', s.enable_multi_currency ? 1 : 0);
  fd.append('enable_pos_salesperson_switch', s.enable_pos_salesperson_switch ? 1 : 0);
  fd.append('allow_overselling', s.allow_overselling ? 1 : 0);
  fd.append('restrict_master_data_by_warehouse', s.restrict_master_data_by_warehouse ? 1 : 0);
  fd.append('restrict_products_by_warehouse', s.restrict_products_by_warehouse ? 1 : 0);
  fd.append('virtual_combo_stock', s.virtual_combo_stock ? 1 : 0);
  fd.append('customer_performance_enabled', s.customer_performance_enabled ? 1 : 0);
  fd.append('product_supplier_enabled', s.product_supplier_enabled ? 1 : 0);
  fd.append('agt_einvoice_enabled', s.agt_einvoice_enabled ? 1 : 0);
  fd.append('dgi_einvoice_enabled', s.dgi_einvoice_enabled ? 1 : 0);
  // Accounting V2 automatic journal entries (this build's column name).
  fd.append('accounting_auto_generate_journals', s.accounting_auto_generate_journals ? 1 : 0);
  fd.append('sale_prefix', s.sale_prefix || '');
  fd.append('purchase_prefix', s.purchase_prefix || '');
  fd.append('quotation_prefix', s.quotation_prefix || '');
  fd.append('adjustment_prefix', s.adjustment_prefix || '');
  fd.append('transfer_prefix', s.transfer_prefix || '');
  fd.append('sale_return_prefix', s.sale_return_prefix || '');
  fd.append('purchase_return_prefix', s.purchase_return_prefix || '');
  fd.append('product_code_prefix', s.product_code_prefix || '');
  fd.append('product_code_next', s.product_code_next || '');
  fd.append('backup_cloud_enabled', s.backup_cloud_enabled ? 1 : 0);
  fd.append('backup_cloud_provider', s.backup_cloud_provider || '');
  fd.append('backup_cloud_path', s.backup_cloud_path || '');
  fd.append('backup_s3_bucket', s.backup_s3_bucket || '');
  fd.append('backup_s3_region', s.backup_s3_region || '');
  fd.append('backup_s3_access_key', s.backup_s3_access_key || '');
  fd.append('backup_s3_secret_key', s.backup_s3_secret_key || '');
  fd.append('backup_s3_endpoint', s.backup_s3_endpoint || '');
  fd.append('backup_s3_path_style', s.backup_s3_path_style ? 1 : 0);
  fd.append('backup_gdrive_folder_id', s.backup_gdrive_folder_id || '');
  fd.append('backup_gdrive_access_token', s.backup_gdrive_access_token || '');
  fd.append('backup_gdrive_refresh_token', s.backup_gdrive_refresh_token || '');
  fd.append('backup_gdrive_client_id', s.backup_gdrive_client_id || '');
  fd.append('backup_gdrive_client_secret', s.backup_gdrive_client_secret || '');
  fd.append('backup_dropbox_path', s.backup_dropbox_path || '');
  fd.append('backup_dropbox_access_token', s.backup_dropbox_access_token || '');
  fd.append('pharmacy_mode', s.pharmacy_mode ? 1 : 0);
  fd.append('expiry_warning_days', (s.expiry_warning_days != null && s.expiry_warning_days !== '') ? s.expiry_warning_days : 90);
  fd.append('block_expired_sale', s.block_expired_sale ? 1 : 0);
  fd.append('print_expiry_on_receipt', s.print_expiry_on_receipt ? 1 : 0);
  fd.append('export_settings', JSON.stringify(exportForm.value));
  fd.append('_method', 'put');

  try {
    // Defaults-tab fields living on pos_settings save alongside (legacy sidecar).
    if (pos_settings.value.id) {
      http.put(`pos_settings/${pos_settings.value.id}`, {
        products_per_page: pos_settings.value.products_per_page,
        show_items_tax: asBool(pos_settings.value.show_items_tax) ? 1 : 0,
      }).catch(() => {});
    }
    const resp = await uploadForm(`settings/${s.id}`, fd);
    if (resp.status >= 400) {
      const msg = resp.data?.message || resp.data?.error || t('InvalidData');
      throw new Error(msg);
    }
    message.success(t('Successfully_Updated'));
    // Virtual combo stock was switched: it is the new saved value, and the
    // warning about leaving it must be recomputed by the server.
    if (!!s.virtual_combo_stock !== savedVirtualCombo.value) {
      savedVirtualCombo.value = !!s.virtual_combo_stock;
      http.get('get_Settings_data_api')
        .then(d => { setting.value.virtual_combo_stock_warning = d?.settings?.virtual_combo_stock_warning ?? null; })
        .catch(() => {});
    }
    // Refresh the auth payload so the sidebar brand (logo size/visibility,
    // company name) reflects the new settings without a full page reload.
    auth.reload?.().catch?.(() => {});
    // Legacy post-save side effects.
    try {
      if (s.date_format) localStorage.setItem('app_date_format', s.date_format);
      localStorage.setItem('app_price_decimals', s.enable_3_decimal_pricing ? '3' : '2');
      localStorage.setItem('app_hide_zero_decimals', asBool(s.hide_zero_decimals) ? '1' : '0');
      if (s.price_format) localStorage.setItem('app_price_format', s.price_format);
    } catch (e) { /* storage unavailable */ }
    if (typeof s.dark_mode === 'boolean' || s.dark_mode != null) {
      if (!!ui.dark !== !!s.dark_mode) ui.toggleDark?.();
    }
    // RTL applies live through the ConfigProvider (same path as the customizer).
    // On forces RTL; off means "follow the language", NOT "force LTR" — the
    // switch defaults to off, so forcing LTR pinned Arabic users left-to-right
    // the first time they saved this page.
    if (s.rtl) ui.setRtl(true);
    else if (s.rtl != null) ui.clearRtlOverride();
    // Language switches reactively — loadLocale() fetches the bundle, flips the
    // locale and direction in place. (Legacy reloaded the page here, a Vue 2
    // workaround; no reload needed anymore.)
    if (s.default_language && s.default_language !== locale.value) {
      ui.setLocale(s.default_language)?.catch?.(() => {});
    }
  } catch (e) {
    message.error(e?.data?.message || e?.message || t('InvalidData'));
  } finally {
    saving.value = false;
  }
}

/* ----------------------------------------------------------------- system */
const clearingCache = ref(false);
async function clearCache() {
  clearingCache.value = true;
  try {
    await http.get('clear_cache');
    message.success(t('Success'));
  } catch (e) {
    message.error(t('Failed'));
  } finally {
    clearingCache.value = false;
  }
}

/* --------------------------------------------------------------- calendar */
const calendarForm = ref({
  google_calendar_client_id: '',
  google_calendar_client_secret: '',
  google_calendar_redirect_uri: '',
  google_calendar_calendar_id: '',
  google_calendar_connected: false,
  google_calendar_connect_url: '',
  google_calendar_disconnect_url: '',
});
const calendarSaving = ref(false);
const calendarLoaded = ref(false);
const calendarLoading = ref(false);

async function loadCalendar() {
  calendarLoading.value = true;
  try {
    const d = await http.get('settings/calendar');
    if (d) calendarForm.value = { ...calendarForm.value, ...d };
    calendarLoaded.value = true;
  } catch (e) { /* module may be off */ } finally {
    calendarLoading.value = false;
  }
}
async function saveCalendar() {
  calendarSaving.value = true;
  try {
    await http.patch('settings/calendar', {
      google_calendar_client_id: calendarForm.value.google_calendar_client_id || null,
      google_calendar_client_secret: calendarForm.value.google_calendar_client_secret || null,
      google_calendar_redirect_uri: calendarForm.value.google_calendar_redirect_uri || null,
      google_calendar_calendar_id: calendarForm.value.google_calendar_calendar_id || null,
    });
    message.success(t('Successfully_Updated'));
    loadCalendar();
  } catch (e) {
    message.error(t('InvalidData'));
  } finally {
    calendarSaving.value = false;
  }
}

/* ------------------------------------------------------------------- load */
// Progressive load: the page renders as soon as the MAIN settings call lands.
// The POS sidecar fetches in PARALLEL and merges silently (its two fields only
// matter on the Defaults tab), and the calendar tab loads lazily on first
// open — three sequential blocking awaits used to gate the whole page.
onMounted(() => {
  const main = http.get('get_Settings_data_api', { include_secrets: 1 })
    .then(data => {
      setting.value = { ...(data.settings || {}) };
      setting.value.currency_position = normalizeCurrencyPosition(setting.value.currency_position);
      savedVirtualCombo.value = !!setting.value.virtual_combo_stock;
      currencies.value = data.currencies || [];
      clients.value = data.clients || [];
      warehouses.value = data.warehouses || [];
      accounts.value = data.accounts || [];
      paymentMethods.value = data.payment_methods || [];
      smsGateways.value = data.sms_gateway || [];
      zonesArray.value = data.zones_array || [];
      languages.value = data.languages || [];
      fontOptionsPayload.value = data.font_options || null;
      syncSectionOrder();
      syncExportForm();
    })
    .catch(() => message.error(t('InvalidData')));

  http.get('get_pos_Settings')
    .then(pos => { pos_settings.value = pos?.pos_settings || {}; })
    .catch(() => { /* sidecar optional */ });

  main.finally(() => { isLoading.value = false; });
});
</script>

<style scoped>
.section-head {
  margin-bottom: 16px;
}
.setting-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 24px;
  padding: 14px 0;
  border-top: 1px solid rgba(128, 128, 128, 0.12);
}
.setting-row:first-of-type {
  border-top: none;
}
.setting-label {
  font-weight: 500;
}
.setting-help {
  font-size: 12px;
  color: rgba(var(--ink-rgb), 0.45);
  max-width: 520px;
}
/* Embedded settings pages keep their content but drop their own page chrome
   (this hub already provides the header and section title). !important is
   required: several pages set max-width as an INLINE style (e.g. Backup
   1000px), which would otherwise center them inside the column and leave
   large empty gutters. */
.embedded :deep(.page-header) {
  display: none;
}
.embedded :deep(.page) {
  max-width: none !important;
  margin: 0 !important;
}

/* DataTable preferences live preview — mirrors DataTable.vue's cell styling. */
.dt-preview :deep(.ant-table-tbody > tr > td) {
  font-family: var(--dtp-font, inherit);
  font-size: var(--dtp-size, 13px);
}
.dt-preview :deep(.dt-preview-striped > td) {
  background: rgba(128, 128, 128, 0.045);
}
.dt-preview :deep(.ant-table-thead > tr > th) {
  font-weight: var(--dtp-th-weight, 600);
  font-size: var(--dtp-th-size, 14px);
  text-transform: var(--dtp-th-transform, none);
}
.dtp-th-bg :deep(.ant-table-thead > tr > th) {
  background: var(--dtp-th-bg);
}
.dtp-th-color :deep(.ant-table-thead > tr > th) {
  color: var(--dtp-th-color);
}
.dt-color-input {
  width: 48px;
  height: 32px;
  padding: 2px;
  border: 1px solid var(--line);
  border-radius: 6px;
  background: var(--surface);
  cursor: pointer;
}

/* Smooth cross-fade when switching sections. */
.ss-fade-enter-active,
.ss-fade-leave-active {
  transition: opacity 0.16s ease, transform 0.16s ease;
}
.ss-fade-enter-from,
.ss-fade-leave-to {
  opacity: 0;
  transform: translateY(4px);
}
@media (prefers-reduced-motion: reduce) {
  .ss-fade-enter-active,
  .ss-fade-leave-active {
    transition: none;
  }
}

/* Skeleton shown while a section's lazy chunk downloads (rendered by a child
   component, so it needs :deep to receive these scoped styles). */
.embedded :deep(.section-loading) {
  background: var(--surface);
  border: 1px solid #ececee;
  border-radius: 10px;
  padding: 20px;
}

/* Settings navigation: scrolls on its own so every section stays reachable
   without scrolling the whole page. */
.settings-nav {
  max-height: calc(100vh - 220px);
  overflow-y: auto;
  overflow-x: hidden;
}
.settings-nav::-webkit-scrollbar {
  width: 6px;
}
.settings-nav::-webkit-scrollbar-thumb {
  background: rgba(128, 128, 128, 0.35);
  border-radius: 3px;
}
.settings-nav::-webkit-scrollbar-track {
  background: transparent;
}
</style>
