<template>
  <div class="guide">
    <a-card size="small" :title="$t('Pd_Guide_How_Title')">
      <p>{{ $t('Pd_Guide_How_Intro') }}</p>
      <ol>
        <li>{{ $t('Pd_Guide_Step_1') }}</li>
        <li>{{ $t('Pd_Guide_Step_2') }}</li>
        <li>{{ $t('Pd_Guide_Step_3') }}</li>
        <li>{{ $t('Pd_Guide_Step_4') }}</li>
        <li>{{ $t('Pd_Guide_Step_5') }}</li>
      </ol>
    </a-card>

    <a-row :gutter="[16, 16]">
      <a-col :xs="24" :lg="12">
        <a-card size="small" :title="$t('Pd_Mode_Bridge')" class="full">
          <p>{{ $t('Pd_Guide_Bridge_Text') }}</p>
          <ul>
            <li>{{ $t('Pd_Guide_Bridge_Point_1') }}</li>
            <li>{{ $t('Pd_Guide_Bridge_Point_2') }}</li>
            <li>{{ $t('Pd_Guide_Bridge_Point_3') }}</li>
          </ul>
        </a-card>
      </a-col>
      <a-col :xs="24" :lg="12">
        <a-card size="small" :title="$t('Pd_Mode_Cloud')" class="full">
          <p>{{ $t('Pd_Guide_Cloud_Text') }}</p>
          <ul>
            <li>{{ $t('Pd_Guide_Cloud_Point_1') }}</li>
            <li>{{ $t('Pd_Guide_Cloud_Point_2') }}</li>
          </ul>
        </a-card>
      </a-col>
    </a-row>

    <a-card size="small" :title="$t('Pd_Guide_Ide280_Title')">
      <p>{{ $t('Pd_Guide_Ide280_Text') }}</p>
      <ul>
        <li>{{ $t('Pd_Guide_Ide280_Point_1') }}</li>
        <li>{{ $t('Pd_Guide_Ide280_Point_2') }}</li>
        <li>{{ $t('Pd_Guide_Ide280_Point_3') }}</li>
      </ul>
    </a-card>

    <a-card size="small" :title="$t('Pd_Guide_Protocol_Title')">
      <p>{{ $t('Pd_Guide_Protocol_Intro') }}</p>
      <a-table :columns="endpointColumns" :data-source="endpoints" :pagination="false" size="small" row-key="op" />

      <h4>{{ $t('Pd_Guide_Request_Title') }}</h4>
      <p class="muted">{{ $t('Pd_Guide_Request_Text') }}</p>
      <pre>{{ requestSample }}</pre>

      <h4>{{ $t('Pd_Guide_Response_Title') }}</h4>
      <p class="muted">{{ $t('Pd_Guide_Response_Text') }}</p>
      <pre>{{ responseSample }}</pre>

      <h4>{{ $t('Pd_Guide_Signing_Title') }}</h4>
      <p class="muted">{{ $t('Pd_Guide_Signing_Text') }}</p>
      <pre>{{ signingSample }}</pre>

      <h4>{{ $t('Pd_Guide_Browser_Title') }}</h4>
      <p class="muted">{{ $t('Pd_Guide_Browser_Text') }}</p>
      <pre>{{ corsSample }}</pre>
    </a-card>

    <a-card size="small" :title="$t('Pd_Guide_Safety_Title')">
      <ul>
        <li>{{ $t('Pd_Guide_Safety_1') }}</li>
        <li>{{ $t('Pd_Guide_Safety_2') }}</li>
        <li>{{ $t('Pd_Guide_Safety_3') }}</li>
        <li>{{ $t('Pd_Guide_Safety_4') }}</li>
      </ul>
    </a-card>
  </div>
</template>

<script setup>
/** Sales > POS Devices > Setup guide: what to configure and the bridge / API contract. */
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const endpointColumns = computed(() => [
  { title: t('Pd_Guide_Operation'), dataIndex: 'label', key: 'label', width: 180 },
  { title: t('Pd_Guide_Default_Endpoint'), dataIndex: 'endpoint', key: 'endpoint', width: 260 },
  { title: t('Pd_Guide_When'), dataIndex: 'when', key: 'when' },
]);

const endpoints = computed(() => [
  { op: 'ping', label: t('Pd_Op_Ping'), endpoint: 'GET /ping', when: t('Pd_Guide_When_Ping') },
  { op: 'sale', label: t('Pd_Op_Sale'), endpoint: 'POST /sale', when: t('Pd_Guide_When_Sale') },
  { op: 'status', label: t('Pd_Op_Status'), endpoint: 'GET /transactions/{reference}', when: t('Pd_Guide_When_Status') },
  { op: 'cancel', label: t('Pd_Op_Cancel'), endpoint: 'POST /cancel', when: t('Pd_Guide_When_Cancel') },
  { op: 'void', label: t('Pd_Op_Void'), endpoint: 'POST /void', when: t('Pd_Guide_When_Void') },
  { op: 'refund', label: t('Pd_Op_Refund'), endpoint: 'POST /refund', when: t('Pd_Guide_When_Refund') },
]);

const requestSample = `POST /sale
{
  "operation": "sale",
  "protocol": "gmp3",
  "reference": "PT261028143215004217",
  "terminal_id": "T1234567",
  "merchant_id": "M7654321",
  "serial_number": "JI20012345",
  "device": { "brand": "Ingenico", "model": "IDE280", "ip": "192.168.1.50", "port": 7500 },
  "test_mode": false,
  "amount": 125.5,
  "amount_minor": 12550,
  "currency": "TRY",
  "currency_numeric": "949",
  "installments": 1,
  "receipt": {                      // only with "Full fiscal receipt"
    "mode": "full_receipt",
    "items": [
      { "name": "Ayran 300 ml", "code": "869001", "quantity": 2, "unit_price": 25.0,
        "total": 50.0, "vat_rate": 10, "department": 2 }
    ],
    "items_total": 50.0,
    "adjustment": 75.5,             // order discount (negative) / shipping / order tax
    "sale_total": 125.5,
    "paid_on_device": 125.5
  }
}

POST /void  and  POST /refund add:
  "original": { "reference": "...", "provider_reference": "RRN", "auth_code": "123456",
                "amount": 125.5, "date": "2026-10-28T14:32:15+03:00" }`;

const responseSample = `{
  "reference": "PT261028143215004217", // REQUIRED for bridge answers: echo our reference
  "operation": "sale",                 // REQUIRED for bridge answers: sale | status | cancel | void | refund | ping
  "status": "approved",                // approved | declined | cancelled | pending | timeout | failed
  "message": "ONAY KODU 123456",
  "auth_code": "123456",
  "rrn": "830214567890",
  "card_mask": "454360******1234",
  "card_brand": "VISA",
  "response_code": "00"
}`;

const signingSample = `POS server -> bridge (request headers)
  X-Stocky-Timestamp: 1761651135
  X-Stocky-Reference: PT261028143215004217
  X-Stocky-Operation: sale
  X-Stocky-Signature: hex( HMAC-SHA256( "req." + timestamp + "." + rawBody, sharedSecret ) )

bridge -> POS server (response header; body must echo "reference" and "operation")
  X-Bridge-Signature: hex( HMAC-SHA256( rawResponseBody, sharedSecret ) )`;

const corsSample = `Access-Control-Allow-Origin: https://<your-shop-domain>
Access-Control-Allow-Methods: GET, POST, OPTIONS
Access-Control-Allow-Headers: Content-Type, Accept, X-Stocky-Timestamp, X-Stocky-Reference, X-Stocky-Operation, X-Stocky-Signature
Access-Control-Expose-Headers: X-Bridge-Signature
Access-Control-Allow-Private-Network: true      (answer to the OPTIONS preflight)`;
</script>

<style scoped>
.guide {
  display: flex;
  flex-direction: column;
  gap: 16px;
}
.full {
  height: 100%;
}
.muted {
  color: rgba(var(--ink-rgb), 0.55);
}
h4 {
  margin: 18px 0 6px;
}
pre {
  background: rgba(var(--ink-rgb), 0.04);
  border: 1px solid var(--line);
  border-radius: 8px;
  padding: 12px;
  font-size: 12px;
  line-height: 1.5;
  overflow-x: auto;
  white-space: pre;
  margin: 0;
}
ol,
ul {
  padding-left: 20px;
  margin-bottom: 0;
}
li {
  margin-bottom: 4px;
}
</style>
