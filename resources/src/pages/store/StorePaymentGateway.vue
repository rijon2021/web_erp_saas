<template>
  <div class="page">
    <PageHeader
      :title="$t('Payment_Gateway')"
      :breadcrumb="context === 'settings' ? [$t('Settings'), $t('Payment_Gateway')] : [$t('Store'), $t('Payment_Gateway')]"
    />

    <a-alert
      v-if="!storeFeature"
      type="info" show-icon style="margin-bottom: 16px"
      :message="$t('Pg_Stripe_Shared_Notice')"
      :description="$t('Pg_Store_Feature_Not_In_Plan')"
    />
    <a-alert
      v-else
      type="info" show-icon style="margin-bottom: 16px"
      :message="$t('Pg_Intro_Title')"
      :description="$t('Pg_Intro_Desc')"
    />

    <div v-if="loading" style="display: flex; justify-content: center; padding: 96px 0">
      <a-spin size="large" />
    </div>

    <template v-else>
    <!-- Gateways — one card, one tab per provider -->
    <a-card :bordered="false" class="pg-card" style="margin-bottom: 16px">
      <a-tabs v-model:active-key="gatewayTab" tab-position="left" class="pg-tabs">
        <!-- ============================== Stripe ============================== -->
        <a-tab-pane key="stripe">
          <template #tab>
            <span class="pg-tab">
              <span class="pg-tab__dot" :class="{ 'pg-tab__dot--on': stripeOffered }"></span>
              <CreditCardOutlined /> Stripe
            </span>
          </template>

          <div class="pg-head">
            <div class="pg-head__left">
              <a-avatar :size="52" style="background: #635bff; flex: 0 0 auto">
                <template #icon><CreditCardOutlined /></template>
              </a-avatar>
              <div>
                <div class="pg-head__title">Stripe</div>
                <div class="pg-head__sub">{{ $t('Store') }} — {{ $t('Payment_Gateway') }}</div>
              </div>
            </div>
            <div class="pg-head__right">
              <a-tag :color="stripeOffered ? 'success' : 'default'">
                <template #icon><component :is="stripeOffered ? CheckCircleFilled : StopOutlined" /></template>
                {{ stripeOffered ? $t('Enabled') : $t('Disabled') }}
              </a-tag>
              <a-switch v-if="storeFeature" v-model:checked="stripeEnabled" :loading="!!flagSaving.stripe" @change="saveStripeFlag" />
            </div>
          </div>

          <a-divider style="margin: 16px 0 20px" />

          <AutoConfirmSwitch v-if="storeFeature" v-model:checked="autoConfirm.credit_card" :loading="!!acSaving.credit_card" @change="saveAutoConfirm('credit_card')" />

          <!-- Keys stay editable even when cards are switched off, so turning
               them back on does not mean re-entering credentials. -->
          <a-form layout="vertical" class="pg-form">
            <!-- Field labels literal like legacy's .env-style naming. -->
            <a-form-item :label="$t('Pg_Label_Stripe_Key')">
              <a-input v-model:value="gateway.stripe_key" :placeholder="$t('LeaveBlank')">
                <template #prefix><KeyOutlined style="color: rgba(var(--ink-rgb), 0.25)" /></template>
              </a-input>
            </a-form-item>
            <a-form-item :label="$t('Pg_Label_Stripe_Secret')" :extra="$t('LeaveBlank')">
              <a-input-password v-model:value="gateway.stripe_secret" :placeholder="$t('LeaveBlank')" autocomplete="new-password">
                <template #prefix><LockOutlined style="color: rgba(var(--ink-rgb), 0.25)" /></template>
              </a-input-password>
            </a-form-item>
          </a-form>
          <a-alert
            v-if="!stripeEnabled" type="info" show-icon style="margin-bottom: 20px"
            :message="$t('Pg_Card_Payments_Off')"
            :description="$t('Pg_Card_Keys_Kept')"
          />
          <a-alert
            v-else-if="!gateway.stripe_key" type="warning" show-icon style="margin-bottom: 20px"
            :message="$t('Pg_No_Stripe_Key')"
            :description="$t('Pg_Enter_Stripe_Key')"
          />

          <a-space>
            <a-button type="primary" :loading="saving" @click="save">
              <template #icon><SaveOutlined /></template>
              {{ $t('submit') }}
            </a-button>
            <a-popconfirm
              v-if="gateway.stripe_key"
              :title="$t('RemoveStripeKeysConfirm')"
              @confirm="clearStripeKeys"
            >
              <a-button danger :loading="saving">{{ $t('RemoveKeys') }}</a-button>
            </a-popconfirm>
          </a-space>
        </a-tab-pane>

        <!-- Everything below is online-store-only (online_orders plan feature). -->
        <template v-if="storeFeature">
        <!-- ============================== Razorpay ============================== -->
        <a-tab-pane key="razorpay">
          <template #tab>
            <span class="pg-tab">
              <span class="pg-tab__dot" :class="{ 'pg-tab__dot--on': offered.razorpay }"></span>
              <ThunderboltOutlined /> Razorpay
            </span>
          </template>

          <div class="pg-head">
            <div class="pg-head__left">
              <a-avatar :size="52" style="background: #3395ff; flex: 0 0 auto">
                <template #icon><ThunderboltOutlined /></template>
              </a-avatar>
              <div>
                <div class="pg-head__title">Razorpay <a-tag color="blue" style="margin-left: 4px">{{ $t('Online_Store') }}</a-tag></div>
                <div class="pg-head__sub">{{ $t('Pg_Online_Store_Only_Sub') }}</div>
              </div>
            </div>
            <div class="pg-head__right">
              <a-tag :color="offered.razorpay ? 'success' : 'default'">
                <template #icon><component :is="offered.razorpay ? CheckCircleFilled : StopOutlined" /></template>
                {{ offered.razorpay ? $t('Enabled') : $t('Disabled') }}
              </a-tag>
              <a-switch v-model:checked="methodFlags.razorpay" :loading="!!flagSaving.razorpay" @change="saveMethodFlag('razorpay')" />
            </div>
          </div>

          <a-divider style="margin: 16px 0 20px" />

          <AutoConfirmSwitch v-model:checked="autoConfirm.razorpay" :loading="!!acSaving.razorpay" @change="saveAutoConfirm('razorpay')" />

          <!-- Switched off keeps the credentials; only the checkout stops
               offering the gateway. -->
          <a-alert
            v-if="!methodFlags.razorpay" type="info" show-icon style="margin-bottom: 20px"
            :message="$t('Pg_Gateway_Switched_Off', { name: 'Razorpay' })"
            :description="$t('Pg_Credentials_Kept', { name: 'Razorpay' })"
          />

          <a-form layout="vertical" class="pg-form">
            <a-form-item :label="$t('Pg_Label_Razorpay_Key_Id')">
              <a-input v-model:value="razorpay.key_id" placeholder="rzp_test_xxxxxxxx">
                <template #prefix><KeyOutlined style="color: rgba(var(--ink-rgb), 0.25)" /></template>
              </a-input>
            </a-form-item>
            <a-form-item :label="$t('Pg_Label_Razorpay_Key_Secret')" :extra="$t('LeaveBlank')">
              <a-input-password v-model:value="razorpay.key_secret" :placeholder="$t('LeaveBlank')" autocomplete="new-password">
                <template #prefix><LockOutlined style="color: rgba(var(--ink-rgb), 0.25)" /></template>
              </a-input-password>
            </a-form-item>
            <a-alert
              type="info" show-icon style="margin-bottom: 20px"
              :message="$t('Pg_Razorpay_Help')"
            />
          </a-form>

          <a-space>
            <a-button type="primary" :loading="razorpaySaving" @click="saveRazorpay">
              <template #icon><SaveOutlined /></template>
              {{ $t('submit') }}
            </a-button>
            <a-popconfirm
              v-if="razorpay.key_id"
              :title="$t('Pg_Remove_Keys_Confirm', { name: 'Razorpay', short: 'Razorpay' })"
              @confirm="clearRazorpay"
            >
              <a-button danger :loading="razorpaySaving">{{ $t('RemoveKeys') }}</a-button>
            </a-popconfirm>
          </a-space>
        </a-tab-pane>

        <!-- ============================== bKash ============================== -->
        <a-tab-pane key="bkash">
          <template #tab>
            <span class="pg-tab">
              <span class="pg-tab__dot" :class="{ 'pg-tab__dot--on': offered.bkash }"></span>
              <MobileOutlined /> bKash
            </span>
          </template>

          <div class="pg-head">
            <div class="pg-head__left">
              <a-avatar :size="52" style="background: #e2136e; flex: 0 0 auto">
                <template #icon><MobileOutlined /></template>
              </a-avatar>
              <div>
                <div class="pg-head__title">bKash <a-tag color="blue" style="margin-left: 4px">{{ $t('Online_Store') }}</a-tag></div>
                <div class="pg-head__sub">{{ $t('Pg_Online_Store_Only_Sub') }}</div>
              </div>
            </div>
            <div class="pg-head__right">
              <a-tag :color="offered.bkash ? 'success' : 'default'">
                <template #icon><component :is="offered.bkash ? CheckCircleFilled : StopOutlined" /></template>
                {{ offered.bkash ? $t('Enabled') : $t('Disabled') }}
              </a-tag>
              <a-switch v-model:checked="methodFlags.bkash" :loading="!!flagSaving.bkash" @change="saveMethodFlag('bkash')" />
            </div>
          </div>

          <a-divider style="margin: 16px 0 20px" />

          <AutoConfirmSwitch v-model:checked="autoConfirm.bkash" :loading="!!acSaving.bkash" @change="saveAutoConfirm('bkash')" />

          <!-- Switched off keeps the credentials; only the checkout stops
               offering the gateway. -->
          <a-alert
            v-if="!methodFlags.bkash" type="info" show-icon style="margin-bottom: 20px"
            :message="$t('Pg_Gateway_Switched_Off', { name: 'bKash' })"
            :description="$t('Pg_Credentials_Kept', { name: 'bKash' })"
          />

          <a-form layout="vertical" class="pg-form">
            <a-form-item :label="$t('Pg_Label_Bkash_App_Key')">
              <a-input v-model:value="bkash.app_key">
                <template #prefix><KeyOutlined style="color: rgba(var(--ink-rgb), 0.25)" /></template>
              </a-input>
            </a-form-item>
            <a-form-item :label="$t('Pg_Label_Bkash_App_Secret')" :extra="$t('LeaveBlank')">
              <a-input-password v-model:value="bkash.app_secret" :placeholder="$t('LeaveBlank')" autocomplete="new-password">
                <template #prefix><LockOutlined style="color: rgba(var(--ink-rgb), 0.25)" /></template>
              </a-input-password>
            </a-form-item>
            <a-form-item :label="$t('Pg_Label_Bkash_Username')">
              <a-input v-model:value="bkash.username" placeholder="01XXXXXXXXX">
                <template #prefix><KeyOutlined style="color: rgba(var(--ink-rgb), 0.25)" /></template>
              </a-input>
            </a-form-item>
            <a-form-item :label="$t('Pg_Label_Bkash_Password')" :extra="$t('LeaveBlank')">
              <a-input-password v-model:value="bkash.password" :placeholder="$t('LeaveBlank')" autocomplete="new-password">
                <template #prefix><LockOutlined style="color: rgba(var(--ink-rgb), 0.25)" /></template>
              </a-input-password>
            </a-form-item>
            <a-form-item
              :label="$t('Pg_Sandbox_Test_Mode')"
              :extra="$t('Pg_Bkash_Sandbox_Help')"
            >
              <a-switch v-model:checked="bkash.test_mode" />
            </a-form-item>
            <a-alert
              type="info" show-icon style="margin-bottom: 20px"
              :message="$t('Pg_Bkash_Help')"
            />
          </a-form>

          <a-space>
            <a-button type="primary" :loading="bkashSaving" @click="saveBkash">
              <template #icon><SaveOutlined /></template>
              {{ $t('submit') }}
            </a-button>
            <a-popconfirm
              v-if="bkash.app_key"
              :title="$t('Pg_Remove_Credentials_Confirm', { name: 'bKash', short: 'bKash' })"
              @confirm="clearBkash"
            >
              <a-button danger :loading="bkashSaving">{{ $t('RemoveKeys') }}</a-button>
            </a-popconfirm>
          </a-space>
        </a-tab-pane>

        <!-- ============================== SSLCommerz ============================== -->
        <a-tab-pane key="sslcommerz">
          <template #tab>
            <span class="pg-tab">
              <span class="pg-tab__dot" :class="{ 'pg-tab__dot--on': offered.sslcommerz }"></span>
              <SafetyCertificateOutlined /> SSLCommerz
            </span>
          </template>

          <div class="pg-head">
            <div class="pg-head__left">
              <a-avatar :size="52" style="background: #2e3192; flex: 0 0 auto">
                <template #icon><SafetyCertificateOutlined /></template>
              </a-avatar>
              <div>
                <div class="pg-head__title">SSLCommerz <a-tag color="blue" style="margin-left: 4px">{{ $t('Online_Store') }}</a-tag></div>
                <div class="pg-head__sub">{{ $t('Pg_Online_Store_Only_Sub') }}</div>
              </div>
            </div>
            <div class="pg-head__right">
              <a-tag :color="offered.sslcommerz ? 'success' : 'default'">
                <template #icon><component :is="offered.sslcommerz ? CheckCircleFilled : StopOutlined" /></template>
                {{ offered.sslcommerz ? $t('Enabled') : $t('Disabled') }}
              </a-tag>
              <a-switch v-model:checked="methodFlags.sslcommerz" :loading="!!flagSaving.sslcommerz" @change="saveMethodFlag('sslcommerz')" />
            </div>
          </div>

          <a-divider style="margin: 16px 0 20px" />

          <AutoConfirmSwitch v-model:checked="autoConfirm.sslcommerz" :loading="!!acSaving.sslcommerz" @change="saveAutoConfirm('sslcommerz')" />

          <!-- Switched off keeps the credentials; only the checkout stops
               offering the gateway. -->
          <a-alert
            v-if="!methodFlags.sslcommerz" type="info" show-icon style="margin-bottom: 20px"
            :message="$t('Pg_Gateway_Switched_Off', { name: 'SSLCommerz' })"
            :description="$t('Pg_Credentials_Kept', { name: 'SSLCommerz' })"
          />

          <a-form layout="vertical" class="pg-form">
            <a-form-item :label="$t('Pg_Label_Sslcommerz_Store_Id')">
              <a-input v-model:value="sslcommerz.store_id" placeholder="teststore00live">
                <template #prefix><KeyOutlined style="color: rgba(var(--ink-rgb), 0.25)" /></template>
              </a-input>
            </a-form-item>
            <a-form-item :label="$t('Pg_Label_Sslcommerz_Store_Password')" :extra="$t('LeaveBlank')">
              <a-input-password v-model:value="sslcommerz.store_password" :placeholder="$t('LeaveBlank')" autocomplete="new-password">
                <template #prefix><LockOutlined style="color: rgba(var(--ink-rgb), 0.25)" /></template>
              </a-input-password>
            </a-form-item>
            <a-form-item
              :label="$t('Pg_Sandbox_Test_Mode')"
              :extra="$t('Pg_Sslcommerz_Sandbox_Help')"
            >
              <a-switch v-model:checked="sslcommerz.test_mode" />
            </a-form-item>
            <a-alert
              type="info" show-icon style="margin-bottom: 12px"
              :message="$t('Pg_Sslcommerz_Help')"
            />
            <a-alert type="info" show-icon style="margin-bottom: 20px">
              <template #message>
                {{ $t('Pg_Sslcommerz_Ipn_Help') }}
                <a-typography-text code copyable>{{ sslcommerzIpnUrl }}</a-typography-text>
              </template>
            </a-alert>
          </a-form>

          <a-space>
            <a-button type="primary" :loading="sslcommerzSaving" @click="saveSslcommerz">
              <template #icon><SaveOutlined /></template>
              {{ $t('submit') }}
            </a-button>
            <a-popconfirm
              v-if="sslcommerz.store_id"
              :title="$t('Pg_Remove_Credentials_Confirm', { name: 'SSLCommerz', short: 'SSLCommerz' })"
              @confirm="clearSslcommerz"
            >
              <a-button danger :loading="sslcommerzSaving">{{ $t('RemoveKeys') }}</a-button>
            </a-popconfirm>
          </a-space>
        </a-tab-pane>

        <!-- ============================== Paymob ============================== -->
        <a-tab-pane key="paymob">
          <template #tab>
            <span class="pg-tab">
              <span class="pg-tab__dot" :class="{ 'pg-tab__dot--on': offered.paymob }"></span>
              <GlobalOutlined /> Paymob
            </span>
          </template>

          <div class="pg-head">
            <div class="pg-head__left">
              <a-avatar :size="52" style="background: #0d3b66; flex: 0 0 auto">
                <template #icon><GlobalOutlined /></template>
              </a-avatar>
              <div>
                <div class="pg-head__title">Paymob <a-tag color="blue" style="margin-left: 4px">{{ $t('Online_Store') }}</a-tag></div>
                <div class="pg-head__sub">{{ $t('Pg_Online_Store_Only_Sub') }}</div>
              </div>
            </div>
            <div class="pg-head__right">
              <a-tag :color="offered.paymob ? 'success' : 'default'">
                <template #icon><component :is="offered.paymob ? CheckCircleFilled : StopOutlined" /></template>
                {{ offered.paymob ? $t('Enabled') : $t('Disabled') }}
              </a-tag>
              <a-switch v-model:checked="methodFlags.paymob" :loading="!!flagSaving.paymob" @change="saveMethodFlag('paymob')" />
            </div>
          </div>

          <a-divider style="margin: 16px 0 20px" />

          <AutoConfirmSwitch v-model:checked="autoConfirm.paymob" :loading="!!acSaving.paymob" @change="saveAutoConfirm('paymob')" />

          <!-- Switched off keeps the credentials; only the checkout stops
               offering the gateway. -->
          <a-alert
            v-if="!methodFlags.paymob" type="info" show-icon style="margin-bottom: 20px"
            :message="$t('Pg_Gateway_Switched_Off', { name: 'Paymob' })"
            :description="$t('Pg_Credentials_Kept', { name: 'Paymob' })"
          />

          <a-form layout="vertical" class="pg-form">
            <a-form-item :label="$t('Pg_Label_Region')" :extra="$t('Pg_Paymob_Region_Help')">
              <a-input v-model:value="paymob.region" placeholder="egypt">
                <template #prefix><GlobalOutlined style="color: rgba(var(--ink-rgb), 0.25)" /></template>
              </a-input>
            </a-form-item>
            <a-form-item :label="$t('Pg_Label_Public_Key')">
              <a-input v-model:value="paymob.public_key" placeholder="egy_pk_test_...">
                <template #prefix><KeyOutlined style="color: rgba(var(--ink-rgb), 0.25)" /></template>
              </a-input>
            </a-form-item>
            <a-form-item :label="$t('Pg_Label_Secret_Key')" :extra="$t('LeaveBlank')">
              <a-input-password v-model:value="paymob.secret_key" :placeholder="$t('LeaveBlank')" autocomplete="new-password">
                <template #prefix><LockOutlined style="color: rgba(var(--ink-rgb), 0.25)" /></template>
              </a-input-password>
            </a-form-item>
            <a-form-item :label="$t('Pg_Label_Api_Key')" :extra="$t('LeaveBlank')">
              <a-input-password v-model:value="paymob.api_key" :placeholder="$t('LeaveBlank')" autocomplete="new-password">
                <template #prefix><LockOutlined style="color: rgba(var(--ink-rgb), 0.25)" /></template>
              </a-input-password>
            </a-form-item>
            <a-form-item :label="$t('Pg_Label_Integration_Ids')" :extra="$t('Pg_Paymob_Integration_Help')">
              <a-input v-model:value="paymob.integration_id" :placeholder="$t('Pg_Paymob_Integration_Placeholder')">
                <template #prefix><KeyOutlined style="color: rgba(var(--ink-rgb), 0.25)" /></template>
              </a-input>
            </a-form-item>
            <a-form-item :label="$t('Pg_Label_Hmac_Secret')" :extra="$t('LeaveBlank')">
              <a-input-password v-model:value="paymob.hmac_secret" :placeholder="$t('LeaveBlank')" autocomplete="new-password">
                <template #prefix><LockOutlined style="color: rgba(var(--ink-rgb), 0.25)" /></template>
              </a-input-password>
            </a-form-item>
            <a-form-item :label="$t('Pg_Label_Charge_Currency')" :extra="$t('Pg_Paymob_Currency_Help')">
              <a-input v-model:value="paymob.currency" placeholder="EGP" style="max-width: 120px">
                <template #prefix><DollarOutlined style="color: rgba(var(--ink-rgb), 0.25)" /></template>
              </a-input>
            </a-form-item>
            <a-alert
              type="info" show-icon style="margin-bottom: 12px"
              :message="$t('Pg_Paymob_Help')"
            />
            <a-alert type="info" show-icon style="margin-bottom: 20px">
              <template #message>
                {{ $t('Pg_Paymob_Webhook_Help') }}
                <a-typography-text code copyable>{{ paymobWebhookUrl }}</a-typography-text>
              </template>
            </a-alert>
          </a-form>

          <a-space>
            <a-button type="primary" :loading="paymobSaving" @click="savePaymob">
              <template #icon><SaveOutlined /></template>
              {{ $t('submit') }}
            </a-button>
            <a-popconfirm
              v-if="paymob.public_key"
              :title="$t('Pg_Remove_Credentials_Confirm', { name: 'Paymob', short: 'Paymob' })"
              @confirm="clearPaymob"
            >
              <a-button danger :loading="paymobSaving">{{ $t('RemoveKeys') }}</a-button>
            </a-popconfirm>
          </a-space>
        </a-tab-pane>

        <!-- ============================== MyFatoorah ============================== -->
        <a-tab-pane key="myfatoorah">
          <template #tab>
            <span class="pg-tab">
              <span class="pg-tab__dot" :class="{ 'pg-tab__dot--on': offered.myfatoorah }"></span>
              <WalletOutlined /> MyFatoorah
            </span>
          </template>

          <div class="pg-head">
            <div class="pg-head__left">
              <a-avatar :size="52" style="background: #3fb5a3; flex: 0 0 auto">
                <template #icon><WalletOutlined /></template>
              </a-avatar>
              <div>
                <div class="pg-head__title">MyFatoorah <a-tag color="blue" style="margin-left: 4px">{{ $t('Online_Store') }}</a-tag></div>
                <div class="pg-head__sub">{{ $t('Pg_Online_Store_Only_Sub') }}</div>
              </div>
            </div>
            <div class="pg-head__right">
              <a-tag :color="offered.myfatoorah ? 'success' : 'default'">
                <template #icon><component :is="offered.myfatoorah ? CheckCircleFilled : StopOutlined" /></template>
                {{ offered.myfatoorah ? $t('Enabled') : $t('Disabled') }}
              </a-tag>
              <a-switch v-model:checked="methodFlags.myfatoorah" :loading="!!flagSaving.myfatoorah" @change="saveMethodFlag('myfatoorah')" />
            </div>
          </div>

          <a-divider style="margin: 16px 0 20px" />

          <AutoConfirmSwitch v-model:checked="autoConfirm.myfatoorah" :loading="!!acSaving.myfatoorah" @change="saveAutoConfirm('myfatoorah')" />

          <!-- Switched off keeps the credentials; only the checkout stops
               offering the gateway. -->
          <a-alert
            v-if="!methodFlags.myfatoorah" type="info" show-icon style="margin-bottom: 20px"
            :message="$t('Pg_Gateway_Switched_Off', { name: 'MyFatoorah' })"
            :description="$t('Pg_Credentials_Kept', { name: 'MyFatoorah' })"
          />

          <a-form layout="vertical" class="pg-form">
            <a-form-item :label="$t('Pg_Label_Api_Token')" :extra="$t('LeaveBlank')">
              <a-input-password v-model:value="myfatoorah.api_token" :placeholder="$t('LeaveBlank')" autocomplete="new-password">
                <template #prefix><LockOutlined style="color: rgba(var(--ink-rgb), 0.25)" /></template>
              </a-input-password>
            </a-form-item>
            <a-form-item :label="$t('Pg_Label_Account_Country_Live')" :extra="$t('Pg_Myfatoorah_Country_Help')">
              <a-input v-model:value="myfatoorah.country" placeholder="KWT" style="max-width: 120px">
                <template #prefix><GlobalOutlined style="color: rgba(var(--ink-rgb), 0.25)" /></template>
              </a-input>
            </a-form-item>
            <a-form-item :label="$t('Pg_Label_Charge_Currency')" :extra="$t('Pg_Myfatoorah_Currency_Help')">
              <a-input v-model:value="myfatoorah.currency" placeholder="KWD" style="max-width: 120px">
                <template #prefix><DollarOutlined style="color: rgba(var(--ink-rgb), 0.25)" /></template>
              </a-input>
            </a-form-item>
            <a-form-item
              :label="$t('Pg_Sandbox_Test_Mode')"
              :extra="$t('Pg_Myfatoorah_Sandbox_Help')"
            >
              <a-switch v-model:checked="myfatoorah.test_mode" />
            </a-form-item>
            <a-alert
              type="info" show-icon style="margin-bottom: 20px"
              :message="$t('Pg_Myfatoorah_Help')"
            />
          </a-form>

          <a-space>
            <a-button type="primary" :loading="myfatoorahSaving" @click="saveMyfatoorah">
              <template #icon><SaveOutlined /></template>
              {{ $t('submit') }}
            </a-button>
            <a-popconfirm
              v-if="myfatoorah.configured"
              :title="$t('Pg_Remove_Credentials_Confirm', { name: 'MyFatoorah', short: 'MyFatoorah' })"
              @confirm="clearMyfatoorah"
            >
              <a-button danger :loading="myfatoorahSaving">{{ $t('RemoveKeys') }}</a-button>
            </a-popconfirm>
          </a-space>
        </a-tab-pane>

        <!-- ============================== Moyasar ============================== -->
        <a-tab-pane key="moyasar">
          <template #tab>
            <span class="pg-tab">
              <span class="pg-tab__dot" :class="{ 'pg-tab__dot--on': offered.moyasar }"></span>
              <CreditCardOutlined /> Moyasar
            </span>
          </template>

          <div class="pg-head">
            <div class="pg-head__left">
              <a-avatar :size="52" style="background: #20b2aa; flex: 0 0 auto">
                <template #icon><CreditCardOutlined /></template>
              </a-avatar>
              <div>
                <div class="pg-head__title">Moyasar <a-tag color="blue" style="margin-left: 4px">{{ $t('Online_Store') }}</a-tag></div>
                <div class="pg-head__sub">{{ $t('Pg_Online_Store_Only_Sub') }}</div>
              </div>
            </div>
            <div class="pg-head__right">
              <a-tag :color="offered.moyasar ? 'success' : 'default'">
                <template #icon><component :is="offered.moyasar ? CheckCircleFilled : StopOutlined" /></template>
                {{ offered.moyasar ? $t('Enabled') : $t('Disabled') }}
              </a-tag>
              <a-switch v-model:checked="methodFlags.moyasar" :loading="!!flagSaving.moyasar" @change="saveMethodFlag('moyasar')" />
            </div>
          </div>

          <a-divider style="margin: 16px 0 20px" />

          <AutoConfirmSwitch v-model:checked="autoConfirm.moyasar" :loading="!!acSaving.moyasar" @change="saveAutoConfirm('moyasar')" />

          <!-- Switched off keeps the credentials; only the checkout stops
               offering the gateway. -->
          <a-alert
            v-if="!methodFlags.moyasar" type="info" show-icon style="margin-bottom: 20px"
            :message="$t('Pg_Gateway_Switched_Off', { name: 'Moyasar' })"
            :description="$t('Pg_Credentials_Kept', { name: 'Moyasar' })"
          />

          <a-form layout="vertical" class="pg-form">
            <a-form-item :label="$t('Pg_Label_Secret_Key')" :extra="$t('LeaveBlank')">
              <a-input-password v-model:value="moyasar.secret_key" placeholder="sk_test_... or sk_live_..." autocomplete="new-password">
                <template #prefix><LockOutlined style="color: rgba(var(--ink-rgb), 0.25)" /></template>
              </a-input-password>
            </a-form-item>
            <a-form-item :label="$t('Pg_Label_Charge_Currency')" :extra="$t('Pg_Moyasar_Currency_Help')">
              <a-input v-model:value="moyasar.currency" placeholder="SAR" style="max-width: 120px">
                <template #prefix><DollarOutlined style="color: rgba(var(--ink-rgb), 0.25)" /></template>
              </a-input>
            </a-form-item>
            <a-alert
              type="info" show-icon style="margin-bottom: 20px"
              :message="$t('Pg_Moyasar_Help')"
            />
          </a-form>

          <a-space>
            <a-button type="primary" :loading="moyasarSaving" @click="saveMoyasar">
              <template #icon><SaveOutlined /></template>
              {{ $t('submit') }}
            </a-button>
            <a-popconfirm
              v-if="moyasar.configured"
              :title="$t('Pg_Remove_Credentials_Confirm', { name: 'Moyasar', short: 'Moyasar' })"
              @confirm="clearMoyasar"
            >
              <a-button danger :loading="moyasarSaving">{{ $t('RemoveKeys') }}</a-button>
            </a-popconfirm>
          </a-space>
        </a-tab-pane>
        <!-- ============================== XPay (PostEx) ============================== -->
        <a-tab-pane key="xpay">
          <template #tab>
            <span class="pg-tab">
              <span class="pg-tab__dot" :class="{ 'pg-tab__dot--on': offered.xpay }"></span>
              <CreditCardOutlined /> XPay
            </span>
          </template>

          <div class="pg-head">
            <div class="pg-head__left">
              <a-avatar :size="52" style="background: #0f766e; flex: 0 0 auto">
                <template #icon><CreditCardOutlined /></template>
              </a-avatar>
              <div>
                <div class="pg-head__title">XPay by PostEx <a-tag color="blue" style="margin-left: 4px">{{ $t('Online_Store') }}</a-tag></div>
                <div class="pg-head__sub">{{ $t('Pg_Online_Store_Only_Sub') }}</div>
              </div>
            </div>
            <div class="pg-head__right">
              <a-tag :color="offered.xpay ? 'success' : 'default'">
                <template #icon><component :is="offered.xpay ? CheckCircleFilled : StopOutlined" /></template>
                {{ offered.xpay ? $t('Enabled') : $t('Disabled') }}
              </a-tag>
              <a-switch v-model:checked="methodFlags.xpay" :loading="!!flagSaving.xpay" @change="saveMethodFlag('xpay')" />
            </div>
          </div>

          <a-divider style="margin: 16px 0 20px" />

          <AutoConfirmSwitch v-model:checked="autoConfirm.xpay" :loading="!!acSaving.xpay" @change="saveAutoConfirm('xpay')" />

          <!-- Switched off keeps the credentials; only the checkout stops
               offering the gateway. -->
          <a-alert
            v-if="!methodFlags.xpay" type="info" show-icon style="margin-bottom: 20px"
            :message="$t('Pg_Gateway_Switched_Off', { name: 'XPay' })"
            :description="$t('Pg_Credentials_Kept', { name: 'XPay' })"
          />

          <a-form layout="vertical" class="pg-form">
            <a-form-item :label="$t('Pg_Label_Account_Id')" :extra="$t('Pg_Xpay_Account_Help')">
              <a-input v-model:value="xpay.account_id" :placeholder="$t('Pg_Xpay_Account_Placeholder')">
                <template #prefix><IdcardOutlined style="color: rgba(var(--ink-rgb), 0.25)" /></template>
              </a-input>
            </a-form-item>
            <a-form-item :label="$t('Pg_Label_Publishable_Key')" :extra="$t('Pg_Xpay_Publishable_Help')">
              <a-input v-model:value="xpay.publishable_key" placeholder="xpay_pk_test_... or xpay_pk_live_...">
                <template #prefix><KeyOutlined style="color: rgba(var(--ink-rgb), 0.25)" /></template>
              </a-input>
            </a-form-item>
            <a-form-item :label="$t('Pg_Label_Secret_Key')" :extra="$t('LeaveBlank')">
              <a-input-password v-model:value="xpay.secret_key" placeholder="xpay_sk_..." autocomplete="new-password">
                <template #prefix><LockOutlined style="color: rgba(var(--ink-rgb), 0.25)" /></template>
              </a-input-password>
            </a-form-item>
            <a-form-item :label="$t('Pg_Label_Api_Hmac_Secret')" :extra="$t('LeaveBlank')">
              <a-input-password v-model:value="xpay.hmac_secret" :placeholder="$t('Pg_Xpay_Hmac_Placeholder')" autocomplete="new-password">
                <template #prefix><LockOutlined style="color: rgba(var(--ink-rgb), 0.25)" /></template>
              </a-input-password>
            </a-form-item>
            <a-form-item :label="$t('Pg_Label_Webhook_Hmac_Secret')" :extra="xpay.webhook_secret_set ? $t('LeaveBlank') : $t('Pg_Xpay_Webhook_Secret_Help')">
              <a-input-password v-model:value="xpay.webhook_hmac_secret" :placeholder="$t('Pg_Xpay_Hmac_Placeholder')" autocomplete="new-password">
                <template #prefix><LockOutlined style="color: rgba(var(--ink-rgb), 0.25)" /></template>
              </a-input-password>
            </a-form-item>
            <a-form-item :label="$t('Pg_Label_Webhook_Url')" :extra="$t('Pg_Xpay_Webhook_Help')">
              <a-typography-text code copyable>{{ xpayWebhookUrl }}</a-typography-text>
            </a-form-item>
            <a-form-item :label="$t('Pg_Label_Charge_Currency')" :extra="$t('Pg_Xpay_Currency_Help')">
              <a-input v-model:value="xpay.currency" placeholder="PKR" style="max-width: 120px">
                <template #prefix><DollarOutlined style="color: rgba(var(--ink-rgb), 0.25)" /></template>
              </a-input>
            </a-form-item>
            <a-form-item
              :label="$t('Pg_Sandbox_Test_Mode')"
              :extra="$t('Pg_Xpay_Sandbox_Help')"
            >
              <a-switch v-model:checked="xpay.test_mode" />
            </a-form-item>
            <a-alert
              type="info" show-icon style="margin-bottom: 20px"
              :message="$t('Pg_Xpay_Help')"
            />
          </a-form>

          <a-space>
            <a-button type="primary" :loading="xpaySaving" @click="saveXpay">
              <template #icon><SaveOutlined /></template>
              {{ $t('submit') }}
            </a-button>
            <a-popconfirm
              v-if="xpay.configured"
              :title="$t('Pg_Remove_Credentials_Confirm', { name: 'XPay', short: 'XPay' })"
              @confirm="clearXpay"
            >
              <a-button danger :loading="xpaySaving">{{ $t('RemoveKeys') }}</a-button>
            </a-popconfirm>
          </a-space>
        </a-tab-pane>
        <!-- ============================== PayFast ============================== -->
        <a-tab-pane key="payfast">
          <template #tab>
            <span class="pg-tab">
              <span class="pg-tab__dot" :class="{ 'pg-tab__dot--on': offered.payfast }"></span>
              <BankOutlined /> PayFast
            </span>
          </template>

          <div class="pg-head">
            <div class="pg-head__left">
              <a-avatar :size="52" style="background: #e2183d; flex: 0 0 auto">
                <template #icon><BankOutlined /></template>
              </a-avatar>
              <div>
                <div class="pg-head__title">PayFast <a-tag color="blue" style="margin-left: 4px">{{ $t('Online_Store') }}</a-tag></div>
                <div class="pg-head__sub">{{ $t('Pg_Online_Store_Only_Sub') }}</div>
              </div>
            </div>
            <div class="pg-head__right">
              <a-tag :color="offered.payfast ? 'success' : 'default'">
                <template #icon><component :is="offered.payfast ? CheckCircleFilled : StopOutlined" /></template>
                {{ offered.payfast ? $t('Enabled') : $t('Disabled') }}
              </a-tag>
              <a-switch v-model:checked="methodFlags.payfast" :loading="!!flagSaving.payfast" @change="saveMethodFlag('payfast')" />
            </div>
          </div>

          <a-divider style="margin: 16px 0 20px" />

          <AutoConfirmSwitch v-model:checked="autoConfirm.payfast" :loading="!!acSaving.payfast" @change="saveAutoConfirm('payfast')" />

          <!-- Switched off keeps the credentials; only the checkout stops
               offering the gateway. -->
          <a-alert
            v-if="!methodFlags.payfast" type="info" show-icon style="margin-bottom: 20px"
            :message="$t('Pg_Gateway_Switched_Off', { name: 'PayFast' })"
            :description="$t('Pg_Credentials_Kept', { name: 'PayFast' })"
          />

          <a-form layout="vertical" class="pg-form">
            <a-form-item :label="$t('Pg_Label_Payfast_Merchant_Id')">
              <a-input v-model:value="payfast.merchant_id">
                <template #prefix><KeyOutlined style="color: rgba(var(--ink-rgb), 0.25)" /></template>
              </a-input>
            </a-form-item>
            <a-form-item :label="$t('Pg_Label_Payfast_Merchant_Key')" :extra="$t('LeaveBlank')">
              <a-input-password v-model:value="payfast.merchant_key" :placeholder="$t('LeaveBlank')" autocomplete="new-password">
                <template #prefix><LockOutlined style="color: rgba(var(--ink-rgb), 0.25)" /></template>
              </a-input-password>
            </a-form-item>
            <a-form-item :label="$t('Pg_Label_Payfast_Passphrase')" :extra="$t('LeaveBlank')">
              <a-input-password v-model:value="payfast.passphrase" :placeholder="$t('LeaveBlank')" autocomplete="new-password">
                <template #prefix><LockOutlined style="color: rgba(var(--ink-rgb), 0.25)" /></template>
              </a-input-password>
            </a-form-item>
            <a-form-item :label="$t('Pg_Sandbox_Test_Mode')" :extra="$t('Pg_Payfast_Sandbox_Help')">
              <a-switch v-model:checked="payfast.sandbox" />
            </a-form-item>
            <a-alert type="info" show-icon style="margin-bottom: 20px">
              <template #message>
                {{ $t('Pg_Payfast_Help') }}
                <a-typography-text code copyable>{{ storeBase }}/payfast/itn</a-typography-text>
              </template>
            </a-alert>
          </a-form>

          <a-space>
            <a-button type="primary" :loading="payfastSaving" @click="savePayfast">
              <template #icon><SaveOutlined /></template>
              {{ $t('submit') }}
            </a-button>
            <a-popconfirm
              v-if="payfast.merchant_id"
              :title="$t('Pg_Remove_Credentials_Confirm', { name: 'PayFast', short: 'PayFast' })"
              @confirm="clearPayfast"
            >
              <a-button danger :loading="payfastSaving">{{ $t('RemoveKeys') }}</a-button>
            </a-popconfirm>
          </a-space>
        </a-tab-pane>

        <!-- ============================== LigdiCash ============================== -->
        <a-tab-pane key="ligdicash">
          <template #tab>
            <span class="pg-tab">
              <span class="pg-tab__dot" :class="{ 'pg-tab__dot--on': offered.ligdicash }"></span>
              <WalletOutlined /> LigdiCash
            </span>
          </template>

          <div class="pg-head">
            <div class="pg-head__left">
              <a-avatar :size="52" style="background: #f39c12; flex: 0 0 auto">
                <template #icon><WalletOutlined /></template>
              </a-avatar>
              <div>
                <div class="pg-head__title">LigdiCash <a-tag color="blue" style="margin-left: 4px">{{ $t('Online_Store') }}</a-tag></div>
                <div class="pg-head__sub">{{ $t('Pg_Online_Store_Only_Sub') }}</div>
              </div>
            </div>
            <div class="pg-head__right">
              <a-tag :color="offered.ligdicash ? 'success' : 'default'">
                <template #icon><component :is="offered.ligdicash ? CheckCircleFilled : StopOutlined" /></template>
                {{ offered.ligdicash ? $t('Enabled') : $t('Disabled') }}
              </a-tag>
              <a-switch v-model:checked="methodFlags.ligdicash" :loading="!!flagSaving.ligdicash" @change="saveMethodFlag('ligdicash')" />
            </div>
          </div>

          <a-divider style="margin: 16px 0 20px" />

          <AutoConfirmSwitch v-model:checked="autoConfirm.ligdicash" :loading="!!acSaving.ligdicash" @change="saveAutoConfirm('ligdicash')" />

          <!-- Switched off keeps the credentials; only the checkout stops
               offering the gateway. -->
          <a-alert
            v-if="!methodFlags.ligdicash" type="info" show-icon style="margin-bottom: 20px"
            :message="$t('Pg_Gateway_Switched_Off', { name: 'LigdiCash' })"
            :description="$t('Pg_Credentials_Kept', { name: 'LigdiCash' })"
          />

          <a-form layout="vertical" class="pg-form">
            <a-form-item :label="$t('Pg_Label_Ligdicash_Api_Key')">
              <a-input v-model:value="ligdicash.api_key">
                <template #prefix><KeyOutlined style="color: rgba(var(--ink-rgb), 0.25)" /></template>
              </a-input>
            </a-form-item>
            <a-form-item :label="$t('Pg_Label_Ligdicash_Auth_Token')" :extra="$t('LeaveBlank')">
              <a-input-password v-model:value="ligdicash.auth_token" :placeholder="$t('LeaveBlank')" autocomplete="new-password">
                <template #prefix><LockOutlined style="color: rgba(var(--ink-rgb), 0.25)" /></template>
              </a-input-password>
            </a-form-item>
            <a-alert type="info" show-icon style="margin-bottom: 20px">
              <template #message>
                {{ $t('Pg_Ligdicash_Help') }}
                <a-typography-text code copyable>{{ storeBase }}/ligdicash/callback</a-typography-text>
              </template>
            </a-alert>
          </a-form>

          <a-space>
            <a-button type="primary" :loading="ligdicashSaving" @click="saveLigdicash">
              <template #icon><SaveOutlined /></template>
              {{ $t('submit') }}
            </a-button>
            <a-popconfirm
              v-if="ligdicash.api_key"
              :title="$t('Pg_Remove_Credentials_Confirm', { name: 'LigdiCash', short: 'LigdiCash' })"
              @confirm="clearLigdicash"
            >
              <a-button danger :loading="ligdicashSaving">{{ $t('RemoveKeys') }}</a-button>
            </a-popconfirm>
          </a-space>
        </a-tab-pane>

        <!-- ============================== Dodo Payments ============================== -->
        <a-tab-pane key="dodo">
          <template #tab>
            <span class="pg-tab">
              <span class="pg-tab__dot" :class="{ 'pg-tab__dot--on': offered.dodo }"></span>
              <GlobalOutlined /> Dodo Payments
            </span>
          </template>

          <div class="pg-head">
            <div class="pg-head__left">
              <a-avatar :size="52" style="background: #111827; flex: 0 0 auto">
                <template #icon><GlobalOutlined /></template>
              </a-avatar>
              <div>
                <div class="pg-head__title">Dodo Payments <a-tag color="blue" style="margin-left: 4px">{{ $t('Online_Store') }}</a-tag></div>
                <div class="pg-head__sub">{{ $t('Pg_Online_Store_Only_Sub') }}</div>
              </div>
            </div>
            <div class="pg-head__right">
              <a-tag :color="offered.dodo ? 'success' : 'default'">
                <template #icon><component :is="offered.dodo ? CheckCircleFilled : StopOutlined" /></template>
                {{ offered.dodo ? $t('Enabled') : $t('Disabled') }}
              </a-tag>
              <a-switch v-model:checked="methodFlags.dodo" :loading="!!flagSaving.dodo" @change="saveMethodFlag('dodo')" />
            </div>
          </div>

          <a-divider style="margin: 16px 0 20px" />

          <AutoConfirmSwitch v-model:checked="autoConfirm.dodo" :loading="!!acSaving.dodo" @change="saveAutoConfirm('dodo')" />

          <!-- Switched off keeps the credentials; only the checkout stops
               offering the gateway. -->
          <a-alert
            v-if="!methodFlags.dodo" type="info" show-icon style="margin-bottom: 20px"
            :message="$t('Pg_Gateway_Switched_Off', { name: 'Dodo Payments' })"
            :description="$t('Pg_Credentials_Kept', { name: 'Dodo Payments' })"
          />

          <a-form layout="vertical" class="pg-form">
            <a-form-item :label="$t('Pg_Label_Dodo_Product_Id')" :extra="$t('Pg_Dodo_Product_Help')">
              <a-input v-model:value="dodo.product_id" placeholder="pdt_xxxxxxxx">
                <template #prefix><KeyOutlined style="color: rgba(var(--ink-rgb), 0.25)" /></template>
              </a-input>
            </a-form-item>
            <a-form-item :label="$t('Pg_Label_Dodo_Api_Key')" :extra="$t('LeaveBlank')">
              <a-input-password v-model:value="dodo.api_key" :placeholder="$t('LeaveBlank')" autocomplete="new-password">
                <template #prefix><LockOutlined style="color: rgba(var(--ink-rgb), 0.25)" /></template>
              </a-input-password>
            </a-form-item>
            <a-form-item :label="$t('Pg_Label_Dodo_Webhook_Secret')" :extra="$t('LeaveBlank')">
              <a-input-password v-model:value="dodo.webhook_secret" :placeholder="$t('LeaveBlank')" autocomplete="new-password">
                <template #prefix><ApiOutlined style="color: rgba(var(--ink-rgb), 0.25)" /></template>
              </a-input-password>
            </a-form-item>
            <a-form-item :label="$t('Pg_Test_Mode')" :extra="$t('Pg_Dodo_Test_Help')">
              <a-switch v-model:checked="dodo.test_mode" />
            </a-form-item>
            <a-alert type="info" show-icon style="margin-bottom: 20px">
              <template #message>
                {{ $t('Pg_Dodo_Help') }}
                <a-typography-text code copyable>{{ storeBase }}/dodo/webhook</a-typography-text>
              </template>
            </a-alert>
          </a-form>

          <a-space>
            <a-button type="primary" :loading="dodoSaving" @click="saveDodo">
              <template #icon><SaveOutlined /></template>
              {{ $t('submit') }}
            </a-button>
            <a-popconfirm
              v-if="dodo.product_id"
              :title="$t('Pg_Remove_Credentials_Confirm', { name: 'Dodo Payments', short: 'Dodo' })"
              @confirm="clearDodo"
            >
              <a-button danger :loading="dodoSaving">{{ $t('RemoveKeys') }}</a-button>
            </a-popconfirm>
          </a-space>
        </a-tab-pane>

        <!-- ============================== WaafiPay ============================== -->
        <a-tab-pane key="waafipay">
          <template #tab>
            <span class="pg-tab">
              <span class="pg-tab__dot" :class="{ 'pg-tab__dot--on': offered.waafipay }"></span>
              <MobileOutlined /> WaafiPay
            </span>
          </template>

          <div class="pg-head">
            <div class="pg-head__left">
              <a-avatar :size="52" style="background: #0ea5e9; flex: 0 0 auto">
                <template #icon><MobileOutlined /></template>
              </a-avatar>
              <div>
                <div class="pg-head__title">WaafiPay <a-tag color="blue" style="margin-left: 4px">{{ $t('Online_Store') }}</a-tag></div>
                <div class="pg-head__sub">{{ $t('Pg_Online_Store_Only_Sub') }}</div>
              </div>
            </div>
            <div class="pg-head__right">
              <a-tag :color="offered.waafipay ? 'success' : 'default'">
                <template #icon><component :is="offered.waafipay ? CheckCircleFilled : StopOutlined" /></template>
                {{ offered.waafipay ? $t('Enabled') : $t('Disabled') }}
              </a-tag>
              <a-switch v-model:checked="methodFlags.waafipay" :loading="!!flagSaving.waafipay" @change="saveMethodFlag('waafipay')" />
            </div>
          </div>

          <a-divider style="margin: 16px 0 20px" />

          <AutoConfirmSwitch v-model:checked="autoConfirm.waafipay" :loading="!!acSaving.waafipay" @change="saveAutoConfirm('waafipay')" />

          <!-- Switched off keeps the credentials; only the checkout stops
               offering the gateway. -->
          <a-alert
            v-if="!methodFlags.waafipay" type="info" show-icon style="margin-bottom: 20px"
            :message="$t('Pg_Gateway_Switched_Off', { name: 'WaafiPay' })"
            :description="$t('Pg_Credentials_Kept', { name: 'WaafiPay' })"
          />

          <a-form layout="vertical" class="pg-form">
            <a-form-item :label="$t('Pg_Label_Waafipay_Merchant_Uid')">
              <a-input v-model:value="waafipay.merchant_uid">
                <template #prefix><KeyOutlined style="color: rgba(var(--ink-rgb), 0.25)" /></template>
              </a-input>
            </a-form-item>
            <a-form-item :label="$t('Pg_Label_Waafipay_Api_User_Id')" :extra="$t('Pg_Waafipay_Api_User_Help')">
              <a-input v-model:value="waafipay.api_user_id">
                <template #prefix><KeyOutlined style="color: rgba(var(--ink-rgb), 0.25)" /></template>
              </a-input>
            </a-form-item>
            <a-form-item :label="$t('Pg_Label_Waafipay_Api_Key')" :extra="$t('LeaveBlank')">
              <a-input-password v-model:value="waafipay.api_key" :placeholder="$t('LeaveBlank')" autocomplete="new-password">
                <template #prefix><LockOutlined style="color: rgba(var(--ink-rgb), 0.25)" /></template>
              </a-input-password>
            </a-form-item>
            <a-form-item :label="$t('Pg_Label_Waafipay_Store_Id')" :extra="$t('Pg_Waafipay_Store_Help')">
              <a-input v-model:value="waafipay.store_id">
                <template #prefix><KeyOutlined style="color: rgba(var(--ink-rgb), 0.25)" /></template>
              </a-input>
            </a-form-item>
            <a-form-item :label="$t('Pg_Label_Waafipay_Hpp_Key')" :extra="$t('LeaveBlank')">
              <a-input-password v-model:value="waafipay.hpp_key" :placeholder="$t('LeaveBlank')" autocomplete="new-password">
                <template #prefix><LockOutlined style="color: rgba(var(--ink-rgb), 0.25)" /></template>
              </a-input-password>
            </a-form-item>
            <a-form-item :label="$t('Pg_Label_Waafipay_Webhook_Secret')" :extra="$t('LeaveBlank')">
              <a-input-password v-model:value="waafipay.webhook_secret" :placeholder="$t('LeaveBlank')" autocomplete="new-password">
                <template #prefix><ApiOutlined style="color: rgba(var(--ink-rgb), 0.25)" /></template>
              </a-input-password>
            </a-form-item>
            <a-form-item :label="$t('Pg_Sandbox_Test_Mode')" :extra="$t('Pg_Waafipay_Sandbox_Help')">
              <a-switch v-model:checked="waafipay.test_mode" />
            </a-form-item>
            <a-alert type="info" show-icon style="margin-bottom: 20px">
              <template #message>
                {{ $t('Pg_Waafipay_Webhook_Help') }}
                <a-typography-text code copyable>{{ storeBase }}/waafipay/webhook</a-typography-text>
              </template>
            </a-alert>
          </a-form>

          <a-space>
            <a-button type="primary" :loading="waafipaySaving" @click="saveWaafipay">
              <template #icon><SaveOutlined /></template>
              {{ $t('submit') }}
            </a-button>
            <a-popconfirm
              v-if="waafipay.merchant_uid"
              :title="$t('Pg_Remove_Credentials_Confirm', { name: 'WaafiPay', short: 'WaafiPay' })"
              @confirm="clearWaafipay"
            >
              <a-button danger :loading="waafipaySaving">{{ $t('RemoveKeys') }}</a-button>
            </a-popconfirm>
          </a-space>
        </a-tab-pane>


        <a-tab-pane key="paypal">
          <template #tab>
            <span class="pg-tab">
              <span class="pg-tab__dot" :class="{ 'pg-tab__dot--on': offered.paypal }"></span>
              <GlobalOutlined /> PayPal
            </span>
          </template>

          <div class="pg-head">
            <div class="pg-head__left">
              <a-avatar :size="52" style="background: #003087; flex: 0 0 auto">
                <template #icon><GlobalOutlined /></template>
              </a-avatar>
              <div>
                <div class="pg-head__title">PayPal <a-tag color="blue" style="margin-left: 4px">{{ $t('Online_Store') }}</a-tag></div>
                <div class="pg-head__sub">{{ $t('Pg_Online_Store_Only_Sub') }}</div>
              </div>
            </div>
            <div class="pg-head__right">
              <a-tag :color="offered.paypal ? 'success' : 'default'">
                <template #icon><component :is="offered.paypal ? CheckCircleFilled : StopOutlined" /></template>
                {{ offered.paypal ? $t('Enabled') : $t('Disabled') }}
              </a-tag>
              <a-switch v-model:checked="methodFlags.paypal" :loading="!!flagSaving.paypal" @change="saveMethodFlag('paypal')" />
            </div>
          </div>

          <a-divider style="margin: 16px 0 20px" />

          <AutoConfirmSwitch v-model:checked="autoConfirm.paypal" :loading="!!acSaving.paypal" @change="saveAutoConfirm('paypal')" />

          <!-- Switched off keeps the credentials; only the checkout stops
               offering the gateway. -->
          <a-alert
            v-if="!methodFlags.paypal" type="info" show-icon style="margin-bottom: 20px"
            :message="$t('Pg_Gateway_Switched_Off', { name: 'PayPal' })"
            :description="$t('Pg_Credentials_Kept', { name: 'PayPal' })"
          />

          <a-form layout="vertical" class="pg-form">
            <a-form-item :label="$t('Pg_Label_Client_Id')">
              <a-input v-model:value="paypal.client_id" placeholder="AY...">
                <template #prefix><KeyOutlined style="color: rgba(var(--ink-rgb), 0.25)" /></template>
              </a-input>
            </a-form-item>
            <a-form-item :label="$t('Pg_Label_Client_Secret')" :extra="$t('LeaveBlank')">
              <a-input-password v-model:value="paypal.client_secret" placeholder="EC..." autocomplete="new-password">
                <template #prefix><LockOutlined style="color: rgba(var(--ink-rgb), 0.25)" /></template>
              </a-input-password>
            </a-form-item>
            <a-form-item :label="$t('Pg_Label_Webhook_Id')" :extra="$t('Pg_Paypal_Webhook_Id_Help')">
              <a-input v-model:value="paypal.webhook_id" placeholder="8SF...">
                <template #prefix><ApiOutlined style="color: rgba(var(--ink-rgb), 0.25)" /></template>
              </a-input>
            </a-form-item>
            <a-form-item :label="$t('Pg_Label_Sandbox_Mode')" :extra="$t('Pg_Paypal_Sandbox_Help')">
              <a-switch v-model:checked="paypal.test_mode" />
            </a-form-item>
            <a-form-item :label="$t('Pg_Label_Webhook_Url')" :extra="$t('Pg_Paypal_Webhook_Url_Help')">
              <a-typography-text code copyable>{{ storeBase }}/api/store/webhooks/paypal</a-typography-text>
            </a-form-item>

            <a-alert
              type="info" show-icon style="margin-bottom: 20px"
              :message="$t('Pg_Paypal_Help')"
            />

          </a-form>

          <a-space>
            <a-button type="primary" :loading="paypalSaving" @click="savePaypal">
              <template #icon><SaveOutlined /></template>
              {{ $t('submit') }}
            </a-button>
            <a-popconfirm
              v-if="paypal.configured"
              :title="$t('Pg_Remove_Credentials_Confirm', { name: 'PayPal', short: 'PayPal' })"
              @confirm="clearPaypal"
            >
              <a-button danger :loading="paypalSaving">{{ $t('RemoveKeys') }}</a-button>
            </a-popconfirm>
          </a-space>
        </a-tab-pane>

        <a-tab-pane key="paystack">
          <template #tab>
            <span class="pg-tab">
              <span class="pg-tab__dot" :class="{ 'pg-tab__dot--on': offered.paystack }"></span>
              <WalletOutlined /> Paystack
            </span>
          </template>

          <div class="pg-head">
            <div class="pg-head__left">
              <a-avatar :size="52" style="background: #011b33; flex: 0 0 auto">
                <template #icon><WalletOutlined /></template>
              </a-avatar>
              <div>
                <div class="pg-head__title">Paystack <a-tag color="blue" style="margin-left: 4px">{{ $t('Online_Store') }}</a-tag></div>
                <div class="pg-head__sub">{{ $t('Pg_Online_Store_Only_Sub') }}</div>
              </div>
            </div>
            <div class="pg-head__right">
              <a-tag :color="offered.paystack ? 'success' : 'default'">
                <template #icon><component :is="offered.paystack ? CheckCircleFilled : StopOutlined" /></template>
                {{ offered.paystack ? $t('Enabled') : $t('Disabled') }}
              </a-tag>
              <a-switch v-model:checked="methodFlags.paystack" :loading="!!flagSaving.paystack" @change="saveMethodFlag('paystack')" />
            </div>
          </div>

          <a-divider style="margin: 16px 0 20px" />

          <AutoConfirmSwitch v-model:checked="autoConfirm.paystack" :loading="!!acSaving.paystack" @change="saveAutoConfirm('paystack')" />

          <!-- Switched off keeps the credentials; only the checkout stops
               offering the gateway. -->
          <a-alert
            v-if="!methodFlags.paystack" type="info" show-icon style="margin-bottom: 20px"
            :message="$t('Pg_Gateway_Switched_Off', { name: 'Paystack' })"
            :description="$t('Pg_Credentials_Kept', { name: 'Paystack' })"
          />

          <a-form layout="vertical" class="pg-form">
            <a-form-item :label="$t('Pg_Label_Public_Key')">
              <a-input v-model:value="paystack.public_key" placeholder="pk_test_... or pk_live_...">
                <template #prefix><KeyOutlined style="color: rgba(var(--ink-rgb), 0.25)" /></template>
              </a-input>
            </a-form-item>
            <a-form-item :label="$t('Pg_Label_Secret_Key')" :extra="$t('LeaveBlank')">
              <a-input-password v-model:value="paystack.secret_key" placeholder="sk_test_... or sk_live_..." autocomplete="new-password">
                <template #prefix><LockOutlined style="color: rgba(var(--ink-rgb), 0.25)" /></template>
              </a-input-password>
            </a-form-item>
            <a-form-item :label="$t('Pg_Label_Webhook_Url')" :extra="$t('Pg_Paystack_Webhook_Url_Help')">
              <a-typography-text code copyable>{{ storeBase }}/api/store/webhooks/paystack</a-typography-text>
            </a-form-item>

            <a-alert
              type="info" show-icon style="margin-bottom: 20px"
              :message="$t('Pg_Paystack_Help')"
            />

          </a-form>

          <a-space>
            <a-button type="primary" :loading="paystackSaving" @click="savePaystack">
              <template #icon><SaveOutlined /></template>
              {{ $t('submit') }}
            </a-button>
            <a-popconfirm
              v-if="paystack.configured"
              :title="$t('Pg_Remove_Credentials_Confirm', { name: 'Paystack', short: 'Paystack' })"
              @confirm="clearPaystack"
            >
              <a-button danger :loading="paystackSaving">{{ $t('RemoveKeys') }}</a-button>
            </a-popconfirm>
          </a-space>
        </a-tab-pane>

        <a-tab-pane key="flutterwave">
          <template #tab>
            <span class="pg-tab">
              <span class="pg-tab__dot" :class="{ 'pg-tab__dot--on': offered.flutterwave }"></span>
              <GlobalOutlined /> Flutterwave
            </span>
          </template>

          <div class="pg-head">
            <div class="pg-head__left">
              <a-avatar :size="52" style="background: #f5a623; flex: 0 0 auto">
                <template #icon><GlobalOutlined /></template>
              </a-avatar>
              <div>
                <div class="pg-head__title">Flutterwave <a-tag color="blue" style="margin-left: 4px">{{ $t('Online_Store') }}</a-tag></div>
                <div class="pg-head__sub">{{ $t('Pg_Online_Store_Only_Sub') }}</div>
              </div>
            </div>
            <div class="pg-head__right">
              <a-tag :color="offered.flutterwave ? 'success' : 'default'">
                <template #icon><component :is="offered.flutterwave ? CheckCircleFilled : StopOutlined" /></template>
                {{ offered.flutterwave ? $t('Enabled') : $t('Disabled') }}
              </a-tag>
              <a-switch v-model:checked="methodFlags.flutterwave" :loading="!!flagSaving.flutterwave" @change="saveMethodFlag('flutterwave')" />
            </div>
          </div>

          <a-divider style="margin: 16px 0 20px" />

          <AutoConfirmSwitch v-model:checked="autoConfirm.flutterwave" :loading="!!acSaving.flutterwave" @change="saveAutoConfirm('flutterwave')" />

          <!-- Switched off keeps the credentials; only the checkout stops
               offering the gateway. -->
          <a-alert
            v-if="!methodFlags.flutterwave" type="info" show-icon style="margin-bottom: 20px"
            :message="$t('Pg_Gateway_Switched_Off', { name: 'Flutterwave' })"
            :description="$t('Pg_Credentials_Kept', { name: 'Flutterwave' })"
          />

          <a-form layout="vertical" class="pg-form">
            <a-form-item :label="$t('Pg_Label_Public_Key')">
              <a-input v-model:value="flutterwave.public_key" placeholder="FLWPUBK_TEST-... or FLWPUBK-...">
                <template #prefix><KeyOutlined style="color: rgba(var(--ink-rgb), 0.25)" /></template>
              </a-input>
            </a-form-item>
            <a-form-item :label="$t('Pg_Label_Secret_Key')" :extra="$t('LeaveBlank')">
              <a-input-password v-model:value="flutterwave.secret_key" placeholder="FLWSECK_TEST-... or FLWSECK-..." autocomplete="new-password">
                <template #prefix><LockOutlined style="color: rgba(var(--ink-rgb), 0.25)" /></template>
              </a-input-password>
            </a-form-item>
            <a-form-item :label="$t('Pg_Label_Secret_Hash')" :extra="$t('LeaveBlank')">
              <a-input-password v-model:value="flutterwave.secret_hash" :placeholder="$t('Pg_Flutterwave_Hash_Placeholder')" autocomplete="new-password">
                <template #prefix><SafetyCertificateOutlined style="color: rgba(var(--ink-rgb), 0.25)" /></template>
              </a-input-password>
            </a-form-item>
            <a-form-item :label="$t('Pg_Label_Webhook_Url')" :extra="$t('Pg_Flutterwave_Webhook_Url_Help')">
              <a-typography-text code copyable>{{ storeBase }}/api/store/webhooks/flutterwave</a-typography-text>
            </a-form-item>

            <a-alert
              type="info" show-icon style="margin-bottom: 20px"
              :message="$t('Pg_Flutterwave_Help')"
            />
            <a-alert
              type="warning" show-icon style="margin-bottom: 20px"
              :message="$t('Pg_Flutterwave_Hash_Help')"
            />

          </a-form>

          <a-space>
            <a-button type="primary" :loading="flutterwaveSaving" @click="saveFlutterwave">
              <template #icon><SaveOutlined /></template>
              {{ $t('submit') }}
            </a-button>
            <a-popconfirm
              v-if="flutterwave.configured"
              :title="$t('Pg_Remove_Credentials_Confirm', { name: 'Flutterwave', short: 'Flutterwave' })"
              @confirm="clearFlutterwave"
            >
              <a-button danger :loading="flutterwaveSaving">{{ $t('RemoveKeys') }}</a-button>
            </a-popconfirm>
          </a-space>
        </a-tab-pane>

        <!-- ============================== Manual / offline ============================== -->
        <a-tab-pane key="manual">
          <template #tab>
            <span class="pg-tab">
              <span class="pg-tab__dot" :class="{ 'pg-tab__dot--on': anyOfflineEnabled }"></span>
              <DollarOutlined /> {{ $t('OfflinePayments') }}
            </span>
          </template>

          <a-alert
            type="info" show-icon style="margin-bottom: 20px"
            :message="$t('OfflinePaymentsIntro')"
          />

          <!-- Cash on delivery -->
          <div class="pg-head">
            <div class="pg-head__left">
              <a-avatar :size="52" style="background: #52c41a; flex: 0 0 auto">
                <template #icon><DollarOutlined /></template>
              </a-avatar>
              <div>
                <div class="pg-head__title">{{ $t('CashOnDelivery') }}</div>
                <div class="pg-head__sub">{{ $t('CashOnDeliveryDesc') }}</div>
              </div>
            </div>
            <div class="pg-head__right">
              <a-switch v-model:checked="flags.cod" />
            </div>
          </div>

          <a-divider />

          <!-- Mobile money -->
          <div class="pg-head">
            <div class="pg-head__left">
              <a-avatar :size="52" style="background: #1677ff; flex: 0 0 auto">
                <template #icon><MobileOutlined /></template>
              </a-avatar>
              <div>
                <div class="pg-head__title">{{ $t('MobileMoney') }}</div>
                <div class="pg-head__sub">{{ $t('MobileMoneyDesc') }}</div>
              </div>
            </div>
            <div class="pg-head__right">
              <a-switch v-model:checked="flags.mobile_money" />
            </div>
          </div>

          <a-divider />

          <!-- GCash -->
          <div class="pg-head">
            <div class="pg-head__left">
              <a-avatar :size="52" style="background: #0075c9; flex: 0 0 auto">
                <template #icon><MobileOutlined /></template>
              </a-avatar>
              <div>
                <div class="pg-head__title">{{ $t('GCash') }}</div>
                <div class="pg-head__sub">{{ $t('GCashDesc') }}</div>
              </div>
            </div>
            <div class="pg-head__right">
              <a-switch v-model:checked="manual.gcash_enabled" />
            </div>
          </div>

          <a-form v-if="manual.gcash_enabled" layout="vertical" class="pg-form" style="margin-top: 16px">
            <a-row :gutter="16">
              <a-col :xs="24" :md="12">
                <a-form-item :label="$t('AccountName')">
                  <a-input v-model:value="manual.gcash_account_name" :maxlength="150" />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="12">
                <a-form-item :label="$t('MobileNumber')">
                  <a-input v-model:value="manual.gcash_account_number" :maxlength="60" placeholder="09XX XXX XXXX" />
                </a-form-item>
              </a-col>
            </a-row>
            <a-form-item :label="$t('Instructions')" :extra="$t('OfflineInstructionsHint')">
              <a-textarea v-model:value="manual.gcash_instructions" :rows="3" :maxlength="1000" show-count />
            </a-form-item>
            <a-form-item :label="$t('QrCode')">
              <a-upload
                :file-list="gcashQrList"
                :before-upload="beforeQrUpload"
                accept="image/png,image/jpeg,image/webp"
                list-type="picture"
                @remove="removeQr"
              >
                <a-button v-if="!gcashQrList.length">
                  <template #icon><UploadOutlined /></template>
                  {{ $t('Upload') }}
                </a-button>
              </a-upload>
              <img v-if="!gcashQrList.length && manual.gcash_qr_url" :src="manual.gcash_qr_url" class="pg-qr" alt="GCash QR" />
            </a-form-item>
          </a-form>

          <a-divider />

          <!-- Bank transfer -->
          <div class="pg-head">
            <div class="pg-head__left">
              <a-avatar :size="52" style="background: #4c5fd7; flex: 0 0 auto">
                <template #icon><BankOutlined /></template>
              </a-avatar>
              <div>
                <div class="pg-head__title">{{ $t('BankTransfer') }}</div>
                <div class="pg-head__sub">{{ $t('BankTransferDesc') }}</div>
              </div>
            </div>
            <div class="pg-head__right">
              <a-switch v-model:checked="manual.bank_enabled" />
            </div>
          </div>

          <a-form v-if="manual.bank_enabled" layout="vertical" class="pg-form" style="margin-top: 16px">
            <a-row :gutter="16">
              <a-col :xs="24" :md="12">
                <a-form-item :label="$t('BankName')">
                  <a-input v-model:value="manual.bank_name" :maxlength="150" />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="12">
                <a-form-item :label="$t('Branch')">
                  <a-input v-model:value="manual.bank_branch" :maxlength="150" />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="12">
                <a-form-item :label="$t('AccountName')">
                  <a-input v-model:value="manual.bank_account_name" :maxlength="150" />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="12">
                <a-form-item :label="$t('AccountNumber')">
                  <a-input v-model:value="manual.bank_account_number" :maxlength="60" />
                </a-form-item>
              </a-col>
            </a-row>
            <a-form-item :label="$t('Instructions')" :extra="$t('OfflineInstructionsHint')">
              <a-textarea v-model:value="manual.bank_instructions" :rows="3" :maxlength="1000" show-count />
            </a-form-item>
          </a-form>

          <a-divider />

          <!-- Cash on pickup -->
          <div class="pg-head">
            <div class="pg-head__left">
              <a-avatar :size="52" style="background: #f7971e; flex: 0 0 auto">
                <template #icon><ShopOutlined /></template>
              </a-avatar>
              <div>
                <div class="pg-head__title">{{ $t('CashOnPickup') }}</div>
                <div class="pg-head__sub">{{ $t('CashOnPickupDesc') }}</div>
              </div>
            </div>
            <div class="pg-head__right">
              <a-switch v-model:checked="manual.pickup_enabled" />
            </div>
          </div>

          <div v-if="manual.pickup_enabled" style="margin-top: 16px">
            <a-form layout="vertical" class="pg-form">
              <a-form-item :label="$t('Instructions')" :extra="$t('OfflineInstructionsHint')">
                <a-textarea v-model:value="manual.pickup_instructions" :rows="2" :maxlength="1000" show-count />
              </a-form-item>
            </a-form>

            <div class="pg-head__title" style="margin-top: 8px">{{ $t('PickupBranches') }}</div>
            <div class="pg-head__sub" style="margin-bottom: 12px">{{ $t('PickupBranchesHint') }}</div>

            <a-alert
              v-if="!branches.length"
              type="warning" show-icon style="margin-bottom: 12px"
              :message="$t('NoStoreWarehouses')"
            />

            <a-table
              v-else
              :columns="branchColumns"
              :data-source="branches"
              :pagination="false"
              size="small"
              row-key="warehouse_id"
              :scroll="{ x: 'max-content' }"
            >
              <template #bodyCell="{ column, record }">
                <template v-if="column.key === 'active'">
                  <a-switch v-model:checked="record.active" size="small" />
                </template>
                <template v-else-if="column.key === 'address'">
                  <a-input v-model:value="record.address" :maxlength="255" :placeholder="record.city || ''" />
                </template>
                <template v-else-if="column.key === 'hours'">
                  <a-input v-model:value="record.hours" :maxlength="191" placeholder="Mon-Sat 9:00-18:00" />
                </template>
                <template v-else-if="column.key === 'contact'">
                  <a-input v-model:value="record.contact" :maxlength="100" />
                </template>
              </template>
            </a-table>
          </div>

          <a-alert
            v-if="!anyOfflineEnabled" type="warning" show-icon style="margin-top: 20px"
            :message="$t('Pg_No_Offline_Method')"
            :description="$t('Pg_No_Offline_Method_Desc')"
          />

          <a-button type="primary" :loading="manualSaving" style="margin-top: 20px" @click="saveManual">
            <template #icon><SaveOutlined /></template>
            {{ $t('submit') }}
          </a-button>
        </a-tab-pane>
        </template>
      </a-tabs>

    </a-card>

    </template>
  </div>
</template>

<script setup>
/**
 * Online-store payment gateway. Credentials live on the tenant
 * payment_settings row: GET get_payment_gateway → {gateway:{…}} (secrets come
 * back blank), POST payment_gateway with one gateway's fields (blank secret =
 * keep, <gateway>_deleted:true = wipe). The per-method switches (cards, cash
 * on delivery, mobile money) live on the store settings row
 * (admin/store/settings → payment_*_enabled).
 */
import { ref, reactive, computed, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import {
  CreditCardOutlined, CheckCircleFilled, StopOutlined, KeyOutlined, LockOutlined, SaveOutlined, IdcardOutlined,
  DollarOutlined, MobileOutlined, WalletOutlined, DollarCircleOutlined, BankOutlined, ApiOutlined,
  GlobalOutlined, ThunderboltOutlined, ShopOutlined, UploadOutlined, SafetyCertificateOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import AutoConfirmSwitch from '../../components/AutoConfirmSwitch.vue';
import http from '../../lib/http';
import { usePlanStore } from '../../stores/plan';

const { t } = useI18n();

// Settings → Payment Gateway is reachable on plans without the online store.
// The store endpoints answer 403 feature_unavailable there (which pops the
// upgrade modal), so only Stripe's shared keys are shown and loaded.
const planStore = usePlanStore();
const storeFeature = computed(() => planStore.featureEnabled('online_orders'));

// Rendered from two routes: /store/payment-gateway ('store', default) and
// /settings/payment-gateway ('settings' — only the breadcrumb differs).
defineProps({ context: { type: String, default: 'store' } });

const loading = ref(true);
const gatewayTab = ref('stripe');
const saving = ref(false);
// `enabled` = credentials are stored; `stripeEnabled` = the admin offers cards
// at checkout. Keeping them apart means switching cards off never wipes keys.
const enabled = ref(false);
const stripeEnabled = ref(true);
const gateway = ref({ stripe_key: '', stripe_secret: '' });
const stripeOffered = computed(() => stripeEnabled.value && !!gateway.value.stripe_key);

// Method on/off flags. The store-settings endpoint takes partial payloads, so
// these switches are saved on their own.
const flags = reactive({ cod: true, mobile_money: true });
const manualSaving = ref(false);

/**
 * Manual (offline) methods: GCash, bank transfer and cash on pickup. These
 * carry the account details the shopper pays to; GCash and bank transfer also
 * ask the shopper for a screenshot + reference number, verified per order.
 */
const manual = reactive({
  gcash_enabled: false,
  gcash_account_name: '',
  gcash_account_number: '',
  gcash_instructions: '',
  gcash_qr_url: '',
  bank_enabled: false,
  bank_name: '',
  bank_account_name: '',
  bank_account_number: '',
  bank_branch: '',
  bank_instructions: '',
  pickup_enabled: false,
  pickup_instructions: '',
});
const gcashQrList = ref([]);
const gcashQrFile = ref(null);
const branches = ref([]);

/** Lights the Offline Payments tab dot when any offline method is offered. */
const anyOfflineEnabled = computed(() =>
  flags.cod || flags.mobile_money
  || manual.gcash_enabled || manual.bank_enabled || manual.pickup_enabled);

const branchColumns = computed(() => [
  { title: t('Offer'), key: 'active', width: 80, align: 'center' },
  { title: t('warehouse'), dataIndex: 'name', key: 'name', width: 180 },
  { title: t('Address'), key: 'address', width: 260 },
  { title: t('PickupHours'), key: 'hours', width: 200 },
  { title: t('Phone'), key: 'contact', width: 160 },
]);

/** Hold the picked QR locally; it uploads with the rest of the tab. */
function beforeQrUpload(file) {
  gcashQrFile.value = file;
  gcashQrList.value = [{ uid: '-1', name: file.name, status: 'done', url: URL.createObjectURL(file) }];
  return false;
}
function removeQr() {
  gcashQrFile.value = null;
  gcashQrList.value = [];
  return true;
}

/** The offline switches live on the store settings row (payment_*_enabled). */
async function saveManual() {
  manualSaving.value = true;
  try {
    // Only this tab's own fields — echoing unrelated store settings back made
    // this save fail with a 422 on any tenant whose default currency is unset.
    const body = {
      payment_cod_enabled: flags.cod ? 1 : 0,
      payment_mobile_money_enabled: flags.mobile_money ? 1 : 0,
      payment_gcash_enabled: manual.gcash_enabled ? 1 : 0,
      gcash_account_name: manual.gcash_account_name || '',
      gcash_account_number: manual.gcash_account_number || '',
      gcash_instructions: manual.gcash_instructions || '',
      payment_bank_transfer_enabled: manual.bank_enabled ? 1 : 0,
      bank_name: manual.bank_name || '',
      bank_account_name: manual.bank_account_name || '',
      bank_account_number: manual.bank_account_number || '',
      bank_branch: manual.bank_branch || '',
      bank_instructions: manual.bank_instructions || '',
      payment_cash_on_pickup_enabled: manual.pickup_enabled ? 1 : 0,
      pickup_instructions: manual.pickup_instructions || '',
    };

    if (gcashQrFile.value) {
      // A file forces multipart; nulls would arrive as the string "null".
      const fd = new FormData();
      Object.entries(body).forEach(([k, v]) => {
        if (v !== null && v !== undefined) fd.append(k, v);
      });
      fd.append('gcash_qr', gcashQrFile.value);
      await http.postForm('admin/store/settings', fd);
      gcashQrFile.value = null;
    } else {
      await http.post('admin/store/settings', body);
    }

    // Branch details ride on their own endpoint (one row per warehouse).
    if (branches.value.length) {
      await http.post('store/pickup-branches', {
        branches: branches.value.map(b => ({
          warehouse_id: b.warehouse_id,
          active: !!b.active,
          address: b.address || null,
          hours: b.hours || null,
          contact: b.contact || null,
          notes: b.notes || null,
          sort_order: b.sort_order || 0,
        })),
      });
    }

    await loadManual();
    message.success(t('Successfully_Updated'));
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    manualSaving.value = false;
  }
}

/** Re-read the offline config + branch rows after a save. */
async function loadManual() {
  const [store, br] = await Promise.all([
    http.get('admin/store/settings').catch(() => null),
    http.get('store/pickup-branches').catch(() => null),
  ]);
  applyManualSettings(store?.settings || {});
  branches.value = (br?.branches || []).map(b => ({ ...b }));
}

function applyManualSettings(s) {
  flags.cod = s.payment_cod_enabled == null ? true : !!Number(s.payment_cod_enabled);
  flags.mobile_money = s.payment_mobile_money_enabled == null ? true : !!Number(s.payment_mobile_money_enabled);

  manual.gcash_enabled = !!Number(s.payment_gcash_enabled);
  manual.gcash_account_name = s.gcash_account_name || '';
  manual.gcash_account_number = s.gcash_account_number || '';
  manual.gcash_instructions = s.gcash_instructions || '';
  manual.gcash_qr_url = s.gcash_qr_path ? `/${String(s.gcash_qr_path).replace(/^\/+/, '')}` : '';
  manual.bank_enabled = !!Number(s.payment_bank_transfer_enabled);
  manual.bank_name = s.bank_name || '';
  manual.bank_account_name = s.bank_account_name || '';
  manual.bank_account_number = s.bank_account_number || '';
  manual.bank_branch = s.bank_branch || '';
  manual.bank_instructions = s.bank_instructions || '';
  manual.pickup_enabled = !!Number(s.payment_cash_on_pickup_enabled);
  manual.pickup_instructions = s.pickup_instructions || '';
  gcashQrList.value = [];
}

// Razorpay — stored via the payment_gateway endpoint (tenant payment_settings
// row), like Stripe: offered at checkout as soon as both keys are stored.
// The secret is write-only: blank means "keep the stored value".
const razorpay = reactive({ key_id: '', key_secret: '' });
const razorpaySaving = ref(false);

// PayFast / LigdiCash / Dodo Payments / WaafiPay — same storage + rules.
const payfast = reactive({ merchant_id: '', merchant_key: '', passphrase: '', sandbox: true });
const payfastSaving = ref(false);
const ligdicash = reactive({ api_key: '', auth_token: '' });
const ligdicashSaving = ref(false);
const dodo = reactive({ product_id: '', api_key: '', webhook_secret: '', test_mode: true });
const dodoSaving = ref(false);
const waafipay = reactive({ merchant_uid: '', store_id: '', api_user_id: '', hpp_key: '', api_key: '', webhook_secret: '', test_mode: true });
const waafipaySaving = ref(false);

// Storefront base URL (default /online_store or the tenant's custom path) —
// the gateway callback URLs shown in the tabs live under it.
const storeBase = ref(`${window.location.origin}/online_store`);

// bKash / SSLCommerz — stored via the payment_gateway endpoint (tenant
// payment_settings row), like Stripe: a gateway is offered at checkout as
// soon as its credentials are stored, and "remove keys" turns it off.
// Secrets are write-only: blank means "keep the stored value".
const bkash = reactive({ app_key: '', app_secret: '', username: '', password: '', test_mode: true });
const bkashSaving = ref(false);
const sslcommerz = reactive({ store_id: '', store_password: '', test_mode: true });
const sslcommerzSaving = ref(false);
const sslcommerzIpnUrl = `${window.location.origin}/api/store/sslcommerz/ipn`;
const paymob = reactive({ region: '', public_key: '', secret_key: '', api_key: '', integration_id: '', hmac_secret: '', currency: 'EGP' });
const paymobSaving = ref(false);
const paymobWebhookUrl = `${window.location.origin}/api/store/paymob/webhook`;
const myfatoorah = reactive({ api_token: '', country: '', currency: 'KWD', test_mode: true, configured: false });
const myfatoorahSaving = ref(false);
const moyasar = reactive({ secret_key: '', currency: 'SAR', configured: false });
const moyasarSaving = ref(false);
const xpay = reactive({
  account_id: '', publishable_key: '', secret_key: '', hmac_secret: '', webhook_hmac_secret: '',
  currency: 'PKR', test_mode: true, configured: false, webhook_secret_set: false,
});
const xpaySaving = ref(false);
const xpayWebhookUrl = `${window.location.origin}/api/store/xpay/webhook`;

// Per-gateway "offer at checkout" switches, the same idea as the card switch:
// they live on the store settings row (payment_<gateway>_enabled), so turning a
// gateway off never touches its stored credentials. Unlike the card switch they
// save the moment they are flipped — the tab's Save button is for credentials.
const methodFlags = reactive({
  razorpay: true,
  bkash: true,
  sslcommerz: true,
  paymob: true,
  myfatoorah: true,
  moyasar: true,
  xpay: true,
  payfast: true,
  ligdicash: true,
  dodo: true,
  waafipay: true,
  paypal: true,
  paystack: true,
  flutterwave: true,
});
const flagSaving = reactive({});

/** Credentials stored for each gateway (what the tabs below fill in). */
const configured = computed(() => ({
  razorpay: !!razorpay.key_id,
  bkash: !!bkash.app_key,
  sslcommerz: !!sslcommerz.store_id,
  paymob: !!paymob.public_key,
  myfatoorah: !!myfatoorah.configured,
  moyasar: !!moyasar.configured,
  xpay: !!xpay.configured,
  payfast: !!payfast.merchant_id,
  ligdicash: !!ligdicash.api_key,
  dodo: !!dodo.product_id,
  waafipay: !!waafipay.merchant_uid,
  paypal: !!paypal.configured,
  paystack: !!paystack.configured,
  flutterwave: !!flutterwave.configured,
}));

/** A gateway is offered at checkout when it is switched on AND configured. */
const offered = computed(() => {
  const c = configured.value;
  return Object.fromEntries(Object.keys(c).map((k) => [k, c[k] && methodFlags[k]]));
});

/**
 * Save one gateway switch on its own. The store-settings endpoint takes partial
 * payloads; a role that may edit gateways but not store settings gets the same
 * warning the card switch uses, and the switch snaps back.
 */
async function saveMethodFlag(key) {
  flagSaving[key] = true;
  try {
    await http.post('admin/store/settings', { [`payment_${key}_enabled`]: methodFlags[key] ? 1 : 0 });
    message.success(t('Successfully_Updated'));
  } catch (e) {
    methodFlags[key] = !methodFlags[key];
    message.warning(t('CardSwitchNotSaved'));
  } finally {
    flagSaving[key] = false;
  }
}

// Per-gateway "Auto-confirm paid orders" switches (store_settings.
// auto_confirm_methods, off by default). Each saves on its own as a one-key
// map the server merges into the stored list. credit_card = Stripe.
const AUTO_CONFIRM_KEYS = [
  'credit_card', 'razorpay', 'bkash', 'sslcommerz', 'paymob', 'myfatoorah', 'moyasar', 'xpay',
  'payfast', 'ligdicash', 'dodo', 'waafipay', 'paypal', 'paystack', 'flutterwave',
];
const autoConfirm = reactive(Object.fromEntries(AUTO_CONFIRM_KEYS.map((k) => [k, false])));
const acSaving = reactive({});

async function saveAutoConfirm(key) {
  acSaving[key] = true;
  try {
    const data = await http.post('admin/store/settings', { auto_confirm_methods: { [key]: autoConfirm[key] ? 1 : 0 } });
    // Not migrated yet: the server drops the value, so show what was stored.
    const stored = Array.isArray(data?.auto_confirm_methods) ? data.auto_confirm_methods : [];
    if (stored.includes(key) !== !!autoConfirm[key]) {
      autoConfirm[key] = stored.includes(key);
      message.warning(t('Pg_Auto_Confirm_Not_Saved'));
      return;
    }
    message.success(t('Successfully_Updated'));
  } catch (e) {
    autoConfirm[key] = !autoConfirm[key];
    message.warning(t('Pg_Auto_Confirm_Not_Saved'));
  } finally {
    acSaving[key] = false;
  }
}

/** Same for the card switch; save() keeps sending the flag along with the keys. */
async function saveStripeFlag() {
  flagSaving.stripe = true;
  try {
    await http.post('admin/store/settings', { payment_stripe_enabled: stripeEnabled.value ? 1 : 0 });
    message.success(t('Successfully_Updated'));
  } catch (e) {
    stripeEnabled.value = !stripeEnabled.value;
    message.warning(t('CardSwitchNotSaved'));
  } finally {
    flagSaving.stripe = false;
  }
}

async function saveBkash() {
  if (!bkash.app_key.trim() || !bkash.username.trim()) {
    message.warning(t('Pg_Req_bKash_App_Key_and_Username'));
    return;
  }
  bkashSaving.value = true;
  try {
    await http.post('payment_gateway', {
      bkash_deleted: false,
      bkash_app_key: bkash.app_key.trim(),
      bkash_username: bkash.username.trim(),
      // Blank secrets → backend keeps the stored ones.
      bkash_app_secret: bkash.app_secret.trim() || '',
      bkash_password: bkash.password.trim() || '',
      bkash_test_mode: !!bkash.test_mode,
    });
    bkash.app_secret = '';
    bkash.password = '';
    message.success(t('Successfully_Updated'));
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    bkashSaving.value = false;
  }
}

async function clearBkash() {
  bkashSaving.value = true;
  try {
    await http.post('payment_gateway', { bkash_deleted: true });
    bkash.app_key = '';
    bkash.app_secret = '';
    bkash.username = '';
    bkash.password = '';
    bkash.test_mode = true;
    message.success(t('Successfully_Updated'));
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    bkashSaving.value = false;
  }
}

async function saveSslcommerz() {
  if (!sslcommerz.store_id.trim()) {
    message.warning(t('Pg_Req_SSLCommerz_Store_ID'));
    return;
  }
  sslcommerzSaving.value = true;
  try {
    await http.post('payment_gateway', {
      sslcommerz_deleted: false,
      sslcommerz_store_id: sslcommerz.store_id.trim(),
      // Blank password → backend keeps the stored one.
      sslcommerz_store_password: sslcommerz.store_password.trim() || '',
      sslcommerz_test_mode: !!sslcommerz.test_mode,
    });
    sslcommerz.store_password = '';
    message.success(t('Successfully_Updated'));
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    sslcommerzSaving.value = false;
  }
}

async function clearSslcommerz() {
  sslcommerzSaving.value = true;
  try {
    await http.post('payment_gateway', { sslcommerz_deleted: true });
    sslcommerz.store_id = '';
    sslcommerz.store_password = '';
    sslcommerz.test_mode = true;
    message.success(t('Successfully_Updated'));
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    sslcommerzSaving.value = false;
  }
}

async function savePaymob() {
  if (!paymob.public_key.trim() || !paymob.integration_id.trim()) {
    message.warning(t('Pg_Req_Paymob_Public_Key_and_Integration_ID'));
    return;
  }
  paymobSaving.value = true;
  try {
    await http.post('payment_gateway', {
      paymob_deleted: false,
      paymob_region: paymob.region.trim().toLowerCase(),
      paymob_public_key: paymob.public_key.trim(),
      paymob_integration_id: paymob.integration_id.trim(),
      paymob_currency: paymob.currency.trim().toUpperCase() || 'EGP',
      // Blank secrets → backend keeps the stored ones.
      paymob_secret_key: paymob.secret_key.trim() || '',
      paymob_api_key: paymob.api_key.trim() || '',
      paymob_hmac_secret: paymob.hmac_secret.trim() || '',
    });
    paymob.secret_key = '';
    paymob.api_key = '';
    paymob.hmac_secret = '';
    message.success(t('Successfully_Updated'));
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    paymobSaving.value = false;
  }
}

async function clearPaymob() {
  paymobSaving.value = true;
  try {
    await http.post('payment_gateway', { paymob_deleted: true });
    paymob.region = '';
    paymob.public_key = '';
    paymob.secret_key = '';
    paymob.api_key = '';
    paymob.integration_id = '';
    paymob.hmac_secret = '';
    paymob.currency = 'EGP';
    message.success(t('Successfully_Updated'));
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    paymobSaving.value = false;
  }
}

async function saveMyfatoorah() {
  if (!myfatoorah.configured && !myfatoorah.api_token.trim()) {
    message.warning(t('Pg_Req_MyFatoorah_API_Token'));
    return;
  }
  myfatoorahSaving.value = true;
  try {
    await http.post('payment_gateway', {
      myfatoorah_deleted: false,
      myfatoorah_country: myfatoorah.country.trim().toUpperCase(),
      myfatoorah_currency: myfatoorah.currency.trim().toUpperCase() || 'KWD',
      // Blank token → backend keeps the stored one.
      myfatoorah_api_token: myfatoorah.api_token.trim() || '',
      myfatoorah_test_mode: !!myfatoorah.test_mode,
    });
    if (myfatoorah.api_token.trim()) myfatoorah.configured = true;
    myfatoorah.api_token = '';
    message.success(t('Successfully_Updated'));
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    myfatoorahSaving.value = false;
  }
}

async function clearMyfatoorah() {
  myfatoorahSaving.value = true;
  try {
    await http.post('payment_gateway', { myfatoorah_deleted: true });
    myfatoorah.api_token = '';
    myfatoorah.country = '';
    myfatoorah.currency = 'KWD';
    myfatoorah.test_mode = true;
    myfatoorah.configured = false;
    message.success(t('Successfully_Updated'));
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    myfatoorahSaving.value = false;
  }
}

async function saveMoyasar() {
  if (!moyasar.configured && !moyasar.secret_key.trim()) {
    message.warning(t('Pg_Req_Moyasar_Secret_Key'));
    return;
  }
  moyasarSaving.value = true;
  try {
    await http.post('payment_gateway', {
      moyasar_deleted: false,
      moyasar_currency: moyasar.currency.trim().toUpperCase() || 'SAR',
      // Blank key → backend keeps the stored one.
      moyasar_secret_key: moyasar.secret_key.trim() || '',
    });
    if (moyasar.secret_key.trim()) moyasar.configured = true;
    moyasar.secret_key = '';
    message.success(t('Successfully_Updated'));
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    moyasarSaving.value = false;
  }
}

// PayPal / Paystack / Flutterwave — hosted redirect gateways stored on the
// tenant payment_settings row. Secrets are write-only: the API never echoes
// them back, and `configured` says whether one is stored.
const paypal = reactive({ client_id: '', client_secret: '', webhook_id: '', test_mode: true, configured: false });
const paypalSaving = ref(false);
const paystack = reactive({ public_key: '', secret_key: '', configured: false });
const paystackSaving = ref(false);
const flutterwave = reactive({ public_key: '', secret_key: '', secret_hash: '', configured: false, hash_set: false });
const flutterwaveSaving = ref(false);

async function savePaypal() {
  if (!paypal.client_id.trim()) {
    message.warning(t('Pg_Req_PayPal_Client_ID'));
    return;
  }
  if (!paypal.configured && !paypal.client_secret.trim()) {
    message.warning(t('Pg_Req_PayPal_Client_Secret'));
    return;
  }
  paypalSaving.value = true;
  try {
    await http.post('payment_gateway', {
      paypal_deleted: false,
      paypal_client_id: paypal.client_id.trim(),
      paypal_webhook_id: paypal.webhook_id.trim(),
      paypal_test_mode: paypal.test_mode,
      // Blank secret → backend keeps the stored one.
      paypal_client_secret: paypal.client_secret.trim() || '',
    });
    if (paypal.client_secret.trim()) paypal.configured = true;
    paypal.client_secret = '';
    message.success(t('Successfully_Updated'));
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    paypalSaving.value = false;
  }
}
async function clearPaypal() {
  paypalSaving.value = true;
  try {
    await http.post('payment_gateway', { paypal_deleted: true });
    paypal.client_id = '';
    paypal.client_secret = '';
    paypal.webhook_id = '';
    paypal.test_mode = true;
    paypal.configured = false;
    message.success(t('Successfully_Updated'));
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    paypalSaving.value = false;
  }
}

async function savePaystack() {
  if (!paystack.public_key.trim()) {
    message.warning(t('Pg_Req_Paystack_Public_Key'));
    return;
  }
  if (!paystack.configured && !paystack.secret_key.trim()) {
    message.warning(t('Pg_Req_Paystack_Secret_Key'));
    return;
  }
  paystackSaving.value = true;
  try {
    await http.post('payment_gateway', {
      paystack_deleted: false,
      paystack_public_key: paystack.public_key.trim(),
      paystack_secret_key: paystack.secret_key.trim() || '',
    });
    if (paystack.secret_key.trim()) paystack.configured = true;
    paystack.secret_key = '';
    message.success(t('Successfully_Updated'));
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    paystackSaving.value = false;
  }
}
async function clearPaystack() {
  paystackSaving.value = true;
  try {
    await http.post('payment_gateway', { paystack_deleted: true });
    paystack.public_key = '';
    paystack.secret_key = '';
    paystack.configured = false;
    message.success(t('Successfully_Updated'));
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    paystackSaving.value = false;
  }
}

async function saveFlutterwave() {
  if (!flutterwave.public_key.trim()) {
    message.warning(t('Pg_Req_Flutterwave_Public_Key'));
    return;
  }
  if (!flutterwave.configured && !flutterwave.secret_key.trim()) {
    message.warning(t('Pg_Req_Flutterwave_Secret_Key'));
    return;
  }
  flutterwaveSaving.value = true;
  try {
    await http.post('payment_gateway', {
      flutterwave_deleted: false,
      flutterwave_public_key: flutterwave.public_key.trim(),
      flutterwave_secret_key: flutterwave.secret_key.trim() || '',
      flutterwave_secret_hash: flutterwave.secret_hash.trim() || '',
    });
    if (flutterwave.secret_key.trim()) flutterwave.configured = true;
    if (flutterwave.secret_hash.trim()) flutterwave.hash_set = true;
    flutterwave.secret_key = '';
    flutterwave.secret_hash = '';
    message.success(t('Successfully_Updated'));
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    flutterwaveSaving.value = false;
  }
}
async function clearFlutterwave() {
  flutterwaveSaving.value = true;
  try {
    await http.post('payment_gateway', { flutterwave_deleted: true });
    flutterwave.public_key = '';
    flutterwave.secret_key = '';
    flutterwave.secret_hash = '';
    flutterwave.configured = false;
    flutterwave.hash_set = false;
    message.success(t('Successfully_Updated'));
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    flutterwaveSaving.value = false;
  }
}

async function clearMoyasar() {
  moyasarSaving.value = true;
  try {
    await http.post('payment_gateway', { moyasar_deleted: true });
    moyasar.secret_key = '';
    moyasar.currency = 'SAR';
    moyasar.configured = false;
    message.success(t('Successfully_Updated'));
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    moyasarSaving.value = false;
  }
}

async function saveXpay() {
  if (!xpay.account_id.trim() || !xpay.publishable_key.trim()) {
    message.warning(t('Pg_Req_XPay_Account_ID_and_Publishable_Key'));
    return;
  }
  if (!xpay.configured && (!xpay.secret_key.trim() || !xpay.hmac_secret.trim())) {
    message.warning(t('Pg_Req_XPay_Secret_Key_and_API_HMAC_Secret'));
    return;
  }
  xpaySaving.value = true;
  try {
    await http.post('payment_gateway', {
      xpay_deleted: false,
      xpay_account_id: xpay.account_id.trim(),
      xpay_publishable_key: xpay.publishable_key.trim(),
      xpay_currency: xpay.currency.trim().toUpperCase() || 'PKR',
      xpay_test_mode: xpay.test_mode,
      // Blank secrets → backend keeps the stored ones.
      xpay_secret_key: xpay.secret_key.trim() || '',
      xpay_hmac_secret: xpay.hmac_secret.trim() || '',
      xpay_webhook_hmac_secret: xpay.webhook_hmac_secret.trim() || '',
    });
    if (xpay.secret_key.trim() && xpay.hmac_secret.trim()) xpay.configured = true;
    if (xpay.webhook_hmac_secret.trim()) xpay.webhook_secret_set = true;
    xpay.secret_key = '';
    xpay.hmac_secret = '';
    xpay.webhook_hmac_secret = '';
    message.success(t('Successfully_Updated'));
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    xpaySaving.value = false;
  }
}

async function clearXpay() {
  xpaySaving.value = true;
  try {
    await http.post('payment_gateway', { xpay_deleted: true });
    xpay.account_id = '';
    xpay.publishable_key = '';
    xpay.secret_key = '';
    xpay.hmac_secret = '';
    xpay.webhook_hmac_secret = '';
    xpay.currency = 'PKR';
    xpay.test_mode = true;
    xpay.configured = false;
    xpay.webhook_secret_set = false;
    message.success(t('Successfully_Updated'));
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    xpaySaving.value = false;
  }
}

/**
 * One gateway at a time: POST payment_gateway with that gateway's fields.
 * Blank secrets are sent as '' so the backend keeps the stored ones.
 */
async function postGateway(savingRef, body, after) {
  savingRef.value = true;
  try {
    await http.post('payment_gateway', body);
    if (after) after();
    message.success(t('Successfully_Updated'));
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    savingRef.value = false;
  }
}

function saveRazorpay() {
  if (!razorpay.key_id.trim()) {
    message.warning(t('Pg_Req_Razorpay_Key_ID'));
    return;
  }
  return postGateway(razorpaySaving, {
    razorpay_deleted: false,
    razorpay_key_id: razorpay.key_id.trim(),
    razorpay_key_secret: razorpay.key_secret.trim() || '',
  }, () => { razorpay.key_secret = ''; });
}
function clearRazorpay() {
  return postGateway(razorpaySaving, { razorpay_deleted: true }, () => {
    razorpay.key_id = '';
    razorpay.key_secret = '';
  });
}

function savePayfast() {
  if (!payfast.merchant_id.trim()) {
    message.warning(t('Pg_Req_PayFast_Merchant_ID'));
    return;
  }
  return postGateway(payfastSaving, {
    payfast_deleted: false,
    payfast_merchant_id: payfast.merchant_id.trim(),
    payfast_merchant_key: payfast.merchant_key.trim() || '',
    payfast_passphrase: payfast.passphrase.trim() || '',
    payfast_sandbox: !!payfast.sandbox,
  }, () => { payfast.merchant_key = ''; payfast.passphrase = ''; });
}
function clearPayfast() {
  return postGateway(payfastSaving, { payfast_deleted: true }, () => {
    payfast.merchant_id = '';
    payfast.merchant_key = '';
    payfast.passphrase = '';
    payfast.sandbox = true;
  });
}

function saveLigdicash() {
  if (!ligdicash.api_key.trim()) {
    message.warning(t('Pg_Req_LigdiCash_API_Key'));
    return;
  }
  return postGateway(ligdicashSaving, {
    ligdicash_deleted: false,
    ligdicash_api_key: ligdicash.api_key.trim(),
    ligdicash_auth_token: ligdicash.auth_token.trim() || '',
  }, () => { ligdicash.auth_token = ''; });
}
function clearLigdicash() {
  return postGateway(ligdicashSaving, { ligdicash_deleted: true }, () => {
    ligdicash.api_key = '';
    ligdicash.auth_token = '';
  });
}

function saveDodo() {
  if (!dodo.product_id.trim()) {
    message.warning(t('Pg_Req_Dodo_Product_ID'));
    return;
  }
  return postGateway(dodoSaving, {
    dodo_deleted: false,
    dodo_product_id: dodo.product_id.trim(),
    dodo_api_key: dodo.api_key.trim() || '',
    dodo_webhook_secret: dodo.webhook_secret.trim() || '',
    dodo_test_mode: !!dodo.test_mode,
  }, () => { dodo.api_key = ''; dodo.webhook_secret = ''; });
}
function clearDodo() {
  return postGateway(dodoSaving, { dodo_deleted: true }, () => {
    dodo.product_id = '';
    dodo.api_key = '';
    dodo.webhook_secret = '';
    dodo.test_mode = true;
  });
}

function saveWaafipay() {
  if (!waafipay.merchant_uid.trim()) {
    message.warning(t('Pg_Req_WaafiPay_Merchant_UID'));
    return;
  }
  return postGateway(waafipaySaving, {
    waafipay_deleted: false,
    waafipay_merchant_uid: waafipay.merchant_uid.trim(),
    waafipay_store_id: waafipay.store_id.trim(),
    waafipay_api_user_id: waafipay.api_user_id.trim(),
    waafipay_hpp_key: waafipay.hpp_key.trim() || '',
    waafipay_api_key: waafipay.api_key.trim() || '',
    waafipay_webhook_secret: waafipay.webhook_secret.trim() || '',
    waafipay_test_mode: !!waafipay.test_mode,
  }, () => { waafipay.hpp_key = ''; waafipay.api_key = ''; waafipay.webhook_secret = ''; });
}
function clearWaafipay() {
  return postGateway(waafipaySaving, { waafipay_deleted: true }, () => {
    Object.assign(waafipay, { merchant_uid: '', store_id: '', api_user_id: '', hpp_key: '', api_key: '', webhook_secret: '', test_mode: true });
  });
}

async function save() {
  saving.value = true;
  try {
    await http.post('payment_gateway', {
      stripe_key: gateway.value.stripe_key,
      // blank secret → null keeps the stored one (leave-blank behaviour).
      stripe_secret: gateway.value.stripe_secret ? gateway.value.stripe_secret : null,
      deleted: false,
    });
    // The offer-cards-at-checkout switch lives on the store settings, which a
    // gateway-only role may not be allowed to write — the keys still save.
    let flagSaved = true;
    if (storeFeature.value) {
      try {
        await http.post('admin/store/settings', {
          payment_stripe_enabled: stripeEnabled.value ? 1 : 0,
        });
      } catch (e) {
        flagSaved = false;
      }
    }
    enabled.value = !!gateway.value.stripe_key;
    gateway.value.stripe_secret = '';
    if (flagSaved) {
      message.success(t('Successfully_Updated'));
    } else {
      message.warning(t('CardSwitchNotSaved'));
    }
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    saving.value = false;
  }
}

/** Explicitly wipe the stored Stripe credentials (the old off-switch path). */
async function clearStripeKeys() {
  saving.value = true;
  try {
    await http.post('payment_gateway', { deleted: true });
    gateway.value.stripe_key = '';
    gateway.value.stripe_secret = '';
    enabled.value = false;
    message.success(t('Successfully_Updated'));
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    saving.value = false;
  }
}

onMounted(async () => {
  try {
    const [gw, store, br] = await Promise.all([
      http.get('get_payment_gateway'),
      storeFeature.value ? http.get('admin/store/settings').catch(() => null) : null,
      // Cash-on-pickup branch rows; only this tab uses them, so a failure
      // here must not take the whole page down.
      storeFeature.value ? http.get('store/pickup-branches').catch(() => null) : null,
    ]);
    const g = gw?.gateway || {};
    gateway.value = { stripe_key: g.stripe_key || '', stripe_secret: '' };
    enabled.value = !!gateway.value.stripe_key;
    bkash.app_key = g.bkash_app_key || '';
    bkash.username = g.bkash_username || '';
    bkash.test_mode = g.bkash_test_mode == null ? true : !!g.bkash_test_mode;
    sslcommerz.store_id = g.sslcommerz_store_id || '';
    sslcommerz.test_mode = g.sslcommerz_test_mode == null ? true : !!g.sslcommerz_test_mode;
    paymob.region = g.paymob_region || '';
    paymob.public_key = g.paymob_public_key || '';
    paymob.integration_id = g.paymob_integration_id || '';
    paymob.currency = g.paymob_currency || 'EGP';
    myfatoorah.country = g.myfatoorah_country || '';
    myfatoorah.currency = g.myfatoorah_currency || 'KWD';
    myfatoorah.test_mode = g.myfatoorah_test_mode == null ? true : !!g.myfatoorah_test_mode;
    myfatoorah.configured = !!g.myfatoorah_configured;
    moyasar.currency = g.moyasar_currency || 'SAR';
    moyasar.configured = !!g.moyasar_configured;
    xpay.account_id = g.xpay_account_id || '';
    xpay.publishable_key = g.xpay_publishable_key || '';
    xpay.currency = g.xpay_currency || 'PKR';
    xpay.test_mode = g.xpay_test_mode == null ? true : !!g.xpay_test_mode;
    xpay.configured = !!g.xpay_configured;
    xpay.webhook_secret_set = !!g.xpay_webhook_secret_set;
    razorpay.key_id = g.razorpay_key_id || '';
    payfast.merchant_id = g.payfast_merchant_id || '';
    payfast.sandbox = g.payfast_sandbox == null ? true : !!g.payfast_sandbox;
    ligdicash.api_key = g.ligdicash_api_key || '';
    dodo.product_id = g.dodo_product_id || '';
    dodo.test_mode = g.dodo_test_mode == null ? true : !!g.dodo_test_mode;
    waafipay.merchant_uid = g.waafipay_merchant_uid || '';
    waafipay.store_id = g.waafipay_store_id || '';
    waafipay.api_user_id = g.waafipay_api_user_id || '';
    waafipay.test_mode = g.waafipay_test_mode == null ? true : !!g.waafipay_test_mode;
    paypal.client_id = g.paypal_client_id || '';
    paypal.webhook_id = g.paypal_webhook_id || '';
    paypal.test_mode = g.paypal_test_mode == null ? true : !!g.paypal_test_mode;
    paypal.configured = !!g.paypal_configured;
    paystack.public_key = g.paystack_public_key || '';
    paystack.configured = !!g.paystack_configured;
    flutterwave.public_key = g.flutterwave_public_key || '';
    flutterwave.configured = !!g.flutterwave_configured;
    flutterwave.hash_set = !!g.flutterwave_hash_set;

    const s = store?.settings || {};
    stripeEnabled.value = s.payment_stripe_enabled == null ? true : !!Number(s.payment_stripe_enabled);
    // Missing column / fresh tenant reads as ON, like the card switch.
    Object.keys(methodFlags).forEach((k) => {
      const v = s[`payment_${k}_enabled`];
      methodFlags[k] = v == null ? true : !!Number(v);
    });
    const acOn = Array.isArray(s.auto_confirm_methods) ? s.auto_confirm_methods : [];
    AUTO_CONFIRM_KEYS.forEach((k) => { autoConfirm[k] = acOn.includes(k); });
    // Callback URLs shown in the tabs follow the configured store base path.
    if (s.store_effective_url) storeBase.value = String(s.store_effective_url).replace(/\/+$/, '');
    applyManualSettings(s);
    branches.value = (br?.branches || []).map(b => ({ ...b }));
  } catch (e) {
    message.error(t('InvalidData'));
  } finally {
    loading.value = false;
  }
});
</script>

<style scoped>
.pg-card {
  border: 1px solid var(--line);
  box-shadow: 0 1px 4px rgba(0, 0, 0, 0.04);
}
.pg-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  flex-wrap: wrap;
}
.pg-head__left {
  display: flex;
  align-items: center;
  gap: 14px;
}
.pg-head__title {
  font-size: 18px;
  font-weight: 700;
  line-height: 1.2;
}
.pg-head__sub {
  font-size: 12px;
  color: rgba(var(--ink-rgb), 0.45);
}
.pg-head__right {
  display: flex;
  align-items: center;
  gap: 10px;
}
.pg-form {
  max-width: 520px;
  margin-bottom: 4px;
}

/* Gateway tabs: vertical nav rail on the left (matches Store Settings) with
   provider icon + name + a small status dot that shows at a glance which
   gateways are live without opening their tab. */
.pg-tabs :deep(.ant-tabs-nav) {
  min-width: 190px;
}
.pg-tabs :deep(.ant-tabs-tab) {
  padding: 10px 14px !important;
  margin: 2px 0 !important;
  border-radius: 8px;
  text-align: left;
}
.pg-tabs :deep(.ant-tabs-tab-active) {
  background: rgba(109, 40, 217, 0.08);
}
.pg-tabs :deep(.ant-tabs-content-holder) {
  padding-left: 8px;
}
.pg-tab {
  display: inline-flex;
  align-items: center;
  gap: 7px;
  font-weight: 600;
}
/* Keep the rail from squeezing the content on small screens. */
@media (max-width: 767px) {
  .pg-tabs :deep(.ant-tabs-nav) {
    min-width: 0;
  }
  .pg-tabs :deep(.ant-tabs-content-holder) {
    padding-left: 0;
  }
}
.pg-tab__dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: #d9d9d9;
  flex: 0 0 auto;
}
.pg-tab__dot--on {
  background: #52c41a;
  box-shadow: 0 0 0 3px rgba(82, 196, 26, 0.18);
}

/* Offline payments tab */
.pg-qr {
  display: block;
  max-width: 160px;
  margin-top: 8px;
  border-radius: 8px;
  border: 1px solid var(--line);
}
.pm-title {
  font-size: 16px;
  font-weight: 600;
}
.pm-sub {
  font-size: 12px;
  color: rgba(var(--ink-rgb), 0.45);
  margin-bottom: 16px;
}
</style>
