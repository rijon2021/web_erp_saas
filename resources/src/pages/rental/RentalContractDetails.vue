<template>
  <div class="page">
    <PageHeader
      :title="c ? c.contract_number : $t('Rental_Contract')"
      :subtitle="c ? c.customer.name : ''"
      :breadcrumb="[$t('Rental_Management'), $t('Rental_Contracts'), c ? c.contract_number : '']"
    >
      <template #actions>
        <a-button @click="$router.push('/rental/contracts')">
          <template #icon><ArrowLeftOutlined /></template>
          {{ $t('Back') }}
        </a-button>
        <template v-if="c">
          <a-dropdown-button @click="printPdf()">
            <PrinterOutlined /> {{ $t('Rental_Print_Agreement') }}
            <template #overlay>
              <a-menu>
                <a-menu-item v-for="tpl in templates" :key="tpl.id" @click="printPdf(tpl.id)">
                  <FileTextOutlined /> {{ tpl.name }}<a-tag v-if="tpl.is_default" class="menu-tag">{{ $t('Rental_Default') }}</a-tag>
                </a-menu-item>
                <a-menu-divider v-if="templates.length" />
                <a-menu-item key="download" @click="downloadPdf">
                  <DownloadOutlined /> {{ $t('Rental_Download_PDF') }}
                </a-menu-item>
              </a-menu>
            </template>
          </a-dropdown-button>
          <a-button v-if="canHandover && canHandOut" type="primary" ghost @click="$router.push(`/rental/contracts/${id}/handover/checkout`)">
            <template #icon><ExportOutlined /></template>
            {{ $t('Rental_Hand_Over') }}
          </a-button>
          <a-button v-if="canHandover && canReturn" type="primary" ghost @click="$router.push(`/rental/contracts/${id}/handover/checkin`)">
            <template #icon><ImportOutlined /></template>
            {{ $t('Rental_Return_Items') }}
          </a-button>
          <a-button v-if="canEdit && editable" @click="$router.push(`/rental/contracts/${id}/edit`)">
            <template #icon><EditOutlined /></template>
            {{ $t('Edit') }}
          </a-button>
          <a-dropdown v-if="moreActions.length">
            <a-button>{{ $t('Rental_More') }} <DownOutlined /></a-button>
            <template #overlay>
              <a-menu @click="({ key }) => runAction(key)">
                <a-menu-item v-for="a in moreActions" :key="a.key" :danger="a.danger">
                  <component :is="a.icon" /> {{ a.label }}
                </a-menu-item>
              </a-menu>
            </template>
          </a-dropdown>
          <a-button v-if="primaryAction" type="primary" :danger="primaryAction.danger" :loading="busy === primaryAction.key" @click="runAction(primaryAction.key)">
            <template #icon><component :is="primaryAction.icon" /></template>
            {{ primaryAction.label }}
          </a-button>
        </template>
      </template>
    </PageHeader>

    <div v-if="loading" class="loading"><a-spin size="large" /></div>

    <a-result v-else-if="!c" status="404" :title="$t('Rental_Contract_Not_Found')">
      <template #extra>
        <a-button type="primary" @click="$router.push('/rental/contracts')">{{ $t('Rental_Contracts') }}</a-button>
      </template>
    </a-result>

    <template v-else>
      <!-- Notices -->
      <a-alert v-if="c.customer.is_blacklisted" type="error" show-icon class="block" :message="$t('Rental_Customer_Blacklisted_Notice')" />
      <a-alert v-if="c.stage === 'overdue'" type="error" show-icon class="block" :message="$t('Rental_Overdue_Notice')" />
      <a-alert v-if="c.renewal" type="info" show-icon class="block">
        <template #message>
          {{ $t('Rental_Renewed_As') }}
          <router-link :to="`/rental/contracts/${c.renewal.id}`">{{ c.renewal.contract_number }}</router-link>
          ({{ labelOf(CONTRACT_STAGES, c.renewal.status) }})
        </template>
      </a-alert>
      <a-alert v-if="c.renewed_from" type="info" show-icon class="block">
        <template #message>
          {{ $t('Rental_Renewal_Of') }}
          <router-link :to="`/rental/contracts/${c.renewed_from.id}`">{{ c.renewed_from.contract_number }}</router-link>
        </template>
      </a-alert>
      <a-alert v-if="c.status === 'terminated'" type="warning" show-icon class="block">
        <template #message>{{ $t('Rental_Terminated_On') }} {{ dateTime(c.terminated_at) }}</template>
        <template #description>{{ c.termination_reason }}</template>
      </a-alert>

      <!-- Timeline hero -->
      <a-card size="small" class="block hero">
        <div class="hero-top">
          <a-tag :color="optionOf(CONTRACT_STAGES, c.stage).color" class="hero-stage">{{ labelOf(CONTRACT_STAGES, c.stage) }}</a-tag>
          <span class="hero-days" :class="{ warn: c.stage === 'expiring', bad: c.stage === 'overdue' }">{{ daysText }}</span>
        </div>
        <div class="track">
          <div class="track-end">
            <span class="track-label">{{ $t('Rental_Start') }}</span>
            <strong>{{ dateTime(c.start_date) }}</strong>
          </div>
          <div class="track-bar">
            <div class="track-fill" :class="c.stage" :style="{ width: `${c.progress ?? (c.end_date ? 0 : 100)}%` }"></div>
          </div>
          <div class="track-end right">
            <span class="track-label">{{ $t('Rental_End') }}</span>
            <strong>{{ c.end_date ? dateTime(c.end_date) : $t('Rental_Open_Ended') }}</strong>
          </div>
        </div>
        <div class="figures">
          <div class="figure">
            <span class="fig-label">{{ c.billing_type === 'upfront' ? $t('Rental_Total_Upfront') : $t('Rental_Each_Bill') }}</span>
            <span class="fig-value accent">{{ money(c.total_amount) }}</span>
            <span class="fig-note">{{ c.billing_type === 'upfront' ? labelOf(BILLING_TYPES, 'upfront') : perLabel(c.rate_period) }}</span>
          </div>
          <div class="figure">
            <span class="fig-label">{{ $t('Rental_Contract_Value') }}</span>
            <span class="fig-value">{{ c.contract_value !== null ? money(c.contract_value) : '—' }}</span>
            <span class="fig-note">{{ c.periods ? `${c.periods} ${unitLabel(c.rate_period)}` : $t('Rental_Open_Ended') }}</span>
          </div>
          <div class="figure">
            <span class="fig-label">{{ $t('Rental_Security_Deposit') }}</span>
            <span class="fig-value">{{ money(c.deposit_amount) }}</span>
          </div>
          <div class="figure">
            <span class="fig-label">{{ $t('Rental_Signature') }}</span>
            <span class="fig-value" :class="c.is_signed ? 'ok' : 'muted'">
              <CheckCircleFilled v-if="c.is_signed" /> {{ c.is_signed ? $t('Rental_Signed') : $t('Rental_Not_Signed') }}
            </span>
            <span v-if="c.signed_at" class="fig-note">{{ dateTime(c.signed_at) }}</span>
          </div>
        </div>
      </a-card>

      <a-row :gutter="16">
        <a-col :xs="24" :lg="16">
          <a-card size="small" class="block">
            <a-tabs v-model:activeKey="tab">
              <!-- Items -->
              <a-tab-pane key="items" :tab="$t('Rental_Items')">
                <div class="lines">
                  <router-link v-for="l in c.lines" :key="l.id" :to="`/rental/items/${l.rental_item_id}`" class="line">
                    <span class="line-media">
                      <img v-if="l.image_url" :src="l.image_url" alt="" loading="lazy" />
                      <component :is="typeIcon(l.item_type)" v-else :size="20" :stroke-width="1.6" />
                    </span>
                    <span class="line-body">
                      <span class="line-name">{{ l.item_name }}</span>
                      <span class="line-sub">
                        {{ l.item_code }}<template v-if="l.quantity > 1"> · × {{ l.quantity }}</template>
                        <a-tag v-if="l.returned_at" color="cyan" class="line-tag">{{ $t('Rental_Returned') }} {{ date(l.returned_at) }}</a-tag>
                        <a-tag v-else-if="l.checked_out_at" color="purple" class="line-tag">{{ $t('Rental_Out_Since') }} {{ date(l.checked_out_at) }}</a-tag>
                      </span>
                    </span>
                    <span class="line-rate">{{ money(l.rate) }} <small>{{ perLabel(c.rate_period) }}</small></span>
                    <strong class="line-amount">{{ money(l.amount) }}</strong>
                  </router-link>
                </div>
                <dl class="totals">
                  <div><dt>{{ $t('Rental_Rent_Per_Period') }}</dt><dd>{{ money(c.rent_amount) }}</dd></div>
                  <div v-if="c.billing_type === 'upfront' && c.periods"><dt>× {{ c.periods }} {{ unitLabel(c.rate_period) }}</dt><dd>{{ money(c.rent_amount * c.periods) }}</dd></div>
                  <div v-if="c.discount"><dt>{{ $t('Discount') }}</dt><dd>− {{ money(c.discount) }}</dd></div>
                  <div v-if="c.tax_amount"><dt>{{ $t('Rental_Tax') }} ({{ c.tax_rate }}%)</dt><dd>{{ money(c.tax_amount) }}</dd></div>
                  <div class="strong"><dt>{{ c.billing_type === 'upfront' ? $t('Rental_Total_Upfront') : $t('Rental_Each_Bill') }}</dt><dd>{{ money(c.total_amount) }}</dd></div>
                </dl>
              </a-tab-pane>

              <!-- Billing -->
              <a-tab-pane v-if="canBilling" key="billing">
                <template #tab>
                  {{ $t('Rental_Billing') }}
                  <a-badge v-if="bill.summary.overdue" dot />
                </template>
                <a-spin :spinning="billLoading">
                  <div class="bill-tiles">
                    <div class="bt"><span>{{ $t('Rental_Billed') }}</span><strong>{{ money(bill.summary.billed || 0) }}</strong></div>
                    <div class="bt ok"><span>{{ $t('Paid') }}</span><strong>{{ money(bill.summary.paid || 0) }}</strong></div>
                    <div class="bt accent"><span>{{ $t('Rental_Outstanding') }}</span><strong>{{ money(bill.summary.outstanding || 0) }}</strong></div>
                    <div class="bt" :class="{ bad: bill.summary.overdue }"><span>{{ $t('Rental_Overdue_Amount') }}</span><strong>{{ money(bill.summary.overdue || 0) }}</strong></div>
                  </div>
                  <div class="bill-actions">
                    <span v-if="bill.summary.next_due" class="muted">
                      {{ $t('Rental_Next_Due') }}: <strong>{{ money(bill.summary.next_due.amount) }}</strong> · {{ date(bill.summary.next_due.date) }}
                    </span>
                    <span class="grow"></span>
                    <a-button v-if="canManageBilling && !['draft', 'cancelled'].includes(c.status)" @click="chargeOpen = true">
                      <template #icon><PlusOutlined /></template>
                      {{ $t('Rental_Add_Charge') }}
                    </a-button>
                    <a-button v-if="canPay && bill.summary.outstanding > 0" type="primary" @click="payOpen = true">
                      <template #icon><DollarOutlined /></template>
                      {{ $t('Rental_Record_Payment') }}
                    </a-button>
                  </div>

                  <div v-if="bill.invoices.length || bill.projected.length" class="sched">
                    <router-link v-for="i in bill.invoices" :key="i.id" :to="`/rental/invoices/${i.id}`" class="srow" :class="{ void: i.status === 'void' }">
                      <span class="s-num">{{ i.invoice_number }}</span>
                      <span class="s-body">
                        <span class="s-title">{{ i.kind === 'charge' ? i.description : `${date(i.period_start)} – ${date(i.period_end)}` }}</span>
                        <span class="s-sub">{{ $t('Rental_Due') }} {{ date(i.due_date) }}<template v-if="i.late_fee && !i.late_fee_waived"> · + {{ money(i.late_fee) }} {{ $t('Rental_Late_Fee_Lower') }}</template></span>
                      </span>
                      <span class="s-amt">
                        <strong>{{ money(i.total) }}</strong>
                        <small v-if="i.paid_amount && i.balance">{{ $t('Rental_Balance') }} {{ money(i.balance) }}</small>
                      </span>
                      <a-tag :color="optionOf(INVOICE_STATUSES, invoiceBadge(i)).color">{{ labelOf(INVOICE_STATUSES, invoiceBadge(i)) }}</a-tag>
                    </router-link>
                    <div v-for="p in bill.projected" :key="`p${p.sequence}`" class="srow projected">
                      <span class="s-num">—</span>
                      <span class="s-body">
                        <span class="s-title">{{ date(p.period_start) }} – {{ p.period_end ? date(p.period_end) : '' }}</span>
                        <span class="s-sub">{{ $t('Rental_Due') }} {{ date(p.due_date) }}</span>
                      </span>
                      <span class="s-amt"><strong>{{ money(p.amount) }}</strong></span>
                      <a-tag>{{ $t('Rental_Scheduled') }}</a-tag>
                    </div>
                  </div>
                  <a-empty v-else :image="simpleEmptyImage" :description="$t('Rental_No_Invoices_Yet')" />
                </a-spin>
              </a-tab-pane>

              <!-- Handover + deposit -->
              <a-tab-pane key="handover" :tab="$t('Rental_Handover_Deposit')">
                <a-spin :spinning="hoLoading">
                  <div class="dep-card">
                    <div class="dep-main">
                      <span class="dep-label">{{ $t('Rental_Deposit_Held') }}</span>
                      <span class="dep-value">{{ money(ho.deposit.held || 0) }}</span>
                      <span class="dep-sub">{{ $t('Rental_Deposit_Agreed') }}: {{ money(ho.deposit.agreed || 0) }}</span>
                    </div>
                    <dl class="dep-rows">
                      <div><dt>{{ $t('Rental_Dep_Collected') }}</dt><dd>{{ money(ho.deposit.collected || 0) }}</dd></div>
                      <div><dt>{{ $t('Rental_Dep_Applied') }}</dt><dd>{{ money(ho.deposit.applied || 0) }}</dd></div>
                      <div><dt>{{ $t('Rental_Dep_Retained') }}</dt><dd>{{ money(ho.deposit.retained || 0) }}</dd></div>
                      <div><dt>{{ $t('Rental_Dep_Refunded') }}</dt><dd>{{ money(ho.deposit.refunded || 0) }}</dd></div>
                    </dl>
                    <div v-if="canDeposits && !['draft', 'cancelled'].includes(c.status)" class="dep-actions">
                      <a-button size="small" @click="openDep('collected')"><template #icon><PlusOutlined /></template>{{ $t('Rental_Collect') }}</a-button>
                      <a-button size="small" :disabled="!(ho.deposit.held > 0)" @click="openDep('applied')">{{ $t('Rental_Use_For_Invoices') }}</a-button>
                      <a-button size="small" :disabled="!(ho.deposit.held > 0)" @click="openDep('retained')">{{ $t('Rental_Keep') }}</a-button>
                      <a-button size="small" :disabled="!(ho.deposit.held > 0)" @click="openDep('refunded')">{{ $t('Rental_Refund') }}</a-button>
                    </div>
                  </div>

                  <div v-if="ho.deposit.transactions && ho.deposit.transactions.length" class="dep-list">
                    <div v-for="tx in ho.deposit.transactions" :key="tx.id" class="dep-tx">
                      <span class="tx-type" :class="tx.type">{{ depLabel(tx.type) }}</span>
                      <span class="tx-body">
                        <strong>{{ money(tx.amount) }}</strong>
                        <span>{{ date(tx.date) }}<template v-if="tx.method"> · {{ tx.method }}</template><template v-if="tx.notes"> · {{ tx.notes }}</template></span>
                      </span>
                      <a-tooltip v-if="canDeposits" :title="$t('Rental_Undo')">
                        <a-button type="text" size="small" danger @click="undoDep(tx)"><template #icon><RollbackOutlined /></template></a-button>
                      </a-tooltip>
                    </div>
                  </div>

                  <h4 class="sub-title">{{ $t('Rental_Handovers') }}</h4>
                  <div v-if="ho.handovers.length" class="hos">
                    <div v-for="h in ho.handovers" :key="h.id" class="ho" :class="h.type">
                      <div class="ho-head">
                        <span class="ho-ic"><ExportOutlined v-if="h.type === 'checkout'" /><ImportOutlined v-else /></span>
                        <span class="ho-title">
                          <strong>{{ h.type === 'checkout' ? $t('Rental_Handed_Over') : $t('Rental_Returned') }}</strong>
                          <span>{{ dateTime(h.happened_at) }}<template v-if="h.user_name"> · {{ h.user_name }}</template></span>
                        </span>
                        <a-tag v-if="h.charges_total" color="error">{{ $t('Rental_Charged') }} {{ money(h.charges_total) }}</a-tag>
                      </div>
                      <ul class="ho-items">
                        <li v-for="(it, i) in h.items" :key="i">
                          <strong>{{ it.item_name }}</strong><template v-if="it.quantity > 1"> × {{ it.quantity }}</template>
                          <a-tag v-if="it.condition" :color="optionOf(RENTAL_CONDITIONS, it.condition).color" :bordered="false" class="mini">{{ labelOf(RENTAL_CONDITIONS, it.condition) }}</a-tag>
                          <span v-if="it.meter_reading !== null" class="muted"> · {{ it.meter_reading }}</span>
                          <span v-if="it.damage_notes" class="bad"> · {{ it.damage_notes }}</span>
                          <span v-if="it.damage_charge" class="bad"> ({{ money(it.damage_charge) }})</span>
                          <a-tag v-if="it.needs_maintenance" color="warning" class="mini">{{ labelOf(RENTAL_STATUSES, 'maintenance') }}</a-tag>
                        </li>
                      </ul>
                      <div v-if="h.checklist.length" class="ho-checks">
                        <span v-for="(ck, i) in h.checklist" :key="i" class="ho-check" :class="{ on: ck.checked }">
                          <CheckOutlined v-if="ck.checked" /><MinusOutlined v-else /> {{ ck.label }}
                        </span>
                      </div>
                      <a-image-preview-group v-if="h.photos.length">
                        <div class="ho-photos">
                          <a-image v-for="url in h.photos" :key="url" :src="url" :width="72" :height="72" class="ho-photo" />
                        </div>
                      </a-image-preview-group>
                      <div v-if="h.signature_url" class="ho-sig">
                        <img :src="h.signature_url" alt="" />
                        <span>{{ h.signed_name }}</span>
                      </div>
                      <p v-if="h.notes" class="ho-notes">{{ h.notes }}</p>
                    </div>
                  </div>
                  <a-empty v-else :image="simpleEmptyImage" :description="$t('Rental_No_Handovers')" />
                </a-spin>
              </a-tab-pane>

              <!-- Agreement + signature -->
              <a-tab-pane key="agreement" :tab="$t('Rental_Agreement')">
                <div class="late-box">
                  <ClockCircleOutlined />
                  <span>{{ lateText }}</span>
                </div>
                <h4 class="sub-title">{{ $t('Rental_Agreement_Terms') }}</h4>
                <p v-if="c.terms" class="prose">{{ c.terms }}</p>
                <p v-else class="muted">{{ $t('Rental_No_Terms') }}</p>

                <h4 class="sub-title">{{ $t('Rental_Signature') }}</h4>
                <div v-if="c.signature_url" class="sig">
                  <img :src="c.signature_url" alt="" class="sig-img" />
                  <div class="sig-meta">
                    <strong>{{ c.signed_name }}</strong>
                    <span>{{ dateTime(c.signed_at) }}</span>
                    <a v-if="canEdit" class="danger-link" @click="removeSignature">{{ $t('Rental_Remove_Signature') }}</a>
                  </div>
                </div>
                <div v-else class="sig-empty">
                  <span class="muted">{{ $t('Rental_Not_Signed_Help') }}</span>
                  <a-button v-if="canEdit" type="primary" ghost @click="openSign">
                    <template #icon><EditOutlined /></template>
                    {{ $t('Rental_Sign_Now') }}
                  </a-button>
                </div>
              </a-tab-pane>

              <!-- Attachments -->
              <a-tab-pane key="attachments">
                <template #tab>
                  {{ $t('Rental_Attachments') }}
                  <a-badge :count="c.attachments.length" :number-style="badgeStyle" />
                </template>
                <div class="docs-head">
                  <span class="muted">{{ $t('Rental_Attachments_Help') }}</span>
                  <a-upload v-if="canEdit" :show-upload-list="false" :before-upload="uploadAttachment"
                    accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx,.xls,.xlsx,.txt">
                    <a-button type="primary" :loading="busy === 'upload'">
                      <template #icon><UploadOutlined /></template>
                      {{ $t('Rental_Upload') }}
                    </a-button>
                  </a-upload>
                </div>
                <div v-if="c.attachments.length" class="docs">
                  <div v-for="a in c.attachments" :key="a.id" class="doc">
                    <PaperClipOutlined class="doc-ic" />
                    <span class="doc-body">
                      <a :href="a.url" target="_blank" rel="noopener" class="doc-title">{{ a.title }}</a>
                      <span class="doc-meta">{{ fileSize(a.size) }} · {{ date(a.created_at) }}</span>
                    </span>
                    <a-button v-if="canEdit" type="text" size="small" danger @click="removeAttachment(a)">
                      <template #icon><DeleteOutlined /></template>
                    </a-button>
                  </div>
                </div>
                <a-empty v-else :image="simpleEmptyImage" :description="$t('Rental_No_Attachments')" />
              </a-tab-pane>

              <!-- History -->
              <a-tab-pane key="history" :tab="$t('Rental_History')">
                <a-timeline class="history">
                  <a-timeline-item v-for="e in c.events" :key="e.id" :color="eventMeta(e).color">
                    <div class="ev-head">
                      <strong>{{ eventMeta(e).text }}</strong>
                      <span class="ev-when">{{ dateTime(e.created_at) }}<template v-if="e.user_name"> · {{ e.user_name }}</template></span>
                    </div>
                    <div v-if="eventDetail(e)" class="ev-detail">{{ eventDetail(e) }}</div>
                    <div v-if="e.note" class="ev-note">{{ e.note }}</div>
                  </a-timeline-item>
                </a-timeline>
              </a-tab-pane>
            </a-tabs>
          </a-card>
        </a-col>

        <!-- Customer + notes -->
        <a-col :xs="24" :lg="8">
          <a-card size="small" :title="$t('Customer')" class="block">
            <router-link :to="`/rental/customers/${c.customer.id}`" class="cust">
              <a-avatar :size="44" :style="{ background: avatarColor(c.customer.name) }">{{ initials(c.customer.name) }}</a-avatar>
              <span class="cust-body">
                <strong>{{ c.customer.name }}</strong>
                <span>{{ c.customer.phone || c.customer.email || '—' }}</span>
              </span>
            </router-link>
            <div class="cust-tags">
              <a-tag :color="optionOf(ID_STATUSES, c.customer.id_status).color">{{ labelOf(ID_STATUSES, c.customer.id_status) }}</a-tag>
              <a-tag v-if="c.customer.is_blacklisted" color="error">{{ $t('Rental_Blacklisted') }}</a-tag>
            </div>
          </a-card>

          <a-card size="small" :title="$t('Rental_Overview')" class="block">
            <a-descriptions :column="1" size="small">
              <a-descriptions-item :label="$t('Rental_Charged_Per')">{{ labelOf(RENTAL_PERIODS, c.rate_period) }}</a-descriptions-item>
              <a-descriptions-item :label="$t('Rental_Billing_Type')">{{ labelOf(BILLING_TYPES, c.billing_type) }}</a-descriptions-item>
              <a-descriptions-item :label="$t('Warehouse')">{{ c.warehouse_name || $t('Rental_All_Branches') }}</a-descriptions-item>
              <a-descriptions-item v-if="c.activated_at" :label="$t('Rental_Activated_On')">{{ dateTime(c.activated_at) }}</a-descriptions-item>
              <a-descriptions-item v-if="c.ended_at" :label="$t('Rental_Closed_On')">{{ dateTime(c.ended_at) }}</a-descriptions-item>
              <a-descriptions-item :label="$t('Rental_Created_On')">{{ dateTime(c.created_at) }}</a-descriptions-item>
            </a-descriptions>
          </a-card>

          <a-card v-if="c.notes" size="small" :title="$t('Rental_Internal_Notes')" class="block">
            <p class="prose">{{ c.notes }}</p>
          </a-card>
        </a-col>
      </a-row>
    </template>

    <a-modal v-model:open="depOpen" :title="depLabel(depForm.type)" :ok-text="$t('Save')" :cancel-text="$t('Cancel')" :confirm-loading="busy === 'deposit'" @ok="doDep">
      <p v-if="depForm.type === 'applied'" class="muted">{{ $t('Rental_Use_For_Invoices_Help') }}</p>
      <a-form layout="vertical">
        <a-row :gutter="12">
          <a-col :span="12">
            <a-form-item :label="$t('Amount')" :required="depForm.type !== 'applied'">
              <a-input-number v-model:value="depForm.amount" :min="0.01" :max="depForm.type === 'collected' ? undefined : ho.deposit.held" style="width: 100%"
                :placeholder="depForm.type === 'applied' ? $t('Rental_As_Much_As_Possible') : ''" />
            </a-form-item>
          </a-col>
          <a-col :span="12">
            <a-form-item :label="$t('date')">
              <a-date-picker v-model:value="depForm.date" value-format="YYYY-MM-DD" style="width: 100%" />
            </a-form-item>
          </a-col>
          <template v-if="['collected', 'refunded'].includes(depForm.type)">
            <a-col :span="12">
              <a-form-item :label="$t('Paymentchoice')">
                <a-select v-model:value="depForm.payment_method_id" :options="payMethods" />
              </a-form-item>
            </a-col>
            <a-col :span="12">
              <a-form-item :label="$t('Account')">
                <a-select v-model:value="depForm.account_id" :options="payAccounts" allow-clear />
              </a-form-item>
            </a-col>
          </template>
          <a-col :span="24">
            <a-form-item :label="depForm.type === 'retained' ? $t('Rental_Keep_Reason') : $t('Rental_Note')" class="no-mb">
              <a-input v-model:value="depForm.notes" />
            </a-form-item>
          </a-col>
        </a-row>
      </a-form>
    </a-modal>

    <RentalReminderModal v-if="c" v-model:open="remindOpen" :contract-id="c.id"
      :types="['contract_expiring', 'return_due', 'rent_overdue']"
      :default-type="c.stage === 'overdue' ? 'return_due' : 'contract_expiring'" />
    <RentalPaymentModal v-if="c" v-model:open="payOpen" :contract-id="c.id" :owed="bill.summary.outstanding || null" @saved="afterMoney" />

    <!-- Add charge -->
    <a-modal v-model:open="chargeOpen" :title="$t('Rental_Add_Charge')" :ok-text="$t('Save')" :cancel-text="$t('Cancel')" :confirm-loading="busy === 'charge'" @ok="doCharge">
      <p class="muted">{{ $t('Rental_Add_Charge_Help') }}</p>
      <a-form layout="vertical">
        <a-form-item :label="$t('Description')" required>
          <a-input v-model:value="chargeForm.description" :placeholder="$t('Rental_Charge_Placeholder')" />
        </a-form-item>
        <a-row :gutter="12">
          <a-col :span="12">
            <a-form-item :label="$t('Amount')" required>
              <a-input-number v-model:value="chargeForm.amount" :min="0.01" :precision="2" style="width: 100%" />
            </a-form-item>
          </a-col>
          <a-col :span="12">
            <a-form-item :label="$t('Rental_Due_Date')">
              <a-date-picker v-model:value="chargeForm.due_date" value-format="YYYY-MM-DD" style="width: 100%" />
            </a-form-item>
          </a-col>
        </a-row>
      </a-form>
    </a-modal>

    <!-- Extend -->
    <a-modal v-model:open="modals.extend" :title="$t('Rental_Extend_Contract')" :ok-text="$t('Rental_Extend')" :cancel-text="$t('Cancel')" :confirm-loading="busy === 'extend'" @ok="doExtend">
      <a-form layout="vertical">
        <a-form-item :label="$t('Rental_New_End')" required>
          <a-date-picker v-model:value="extendForm.end_date" show-time format="YYYY-MM-DD HH:mm" value-format="YYYY-MM-DD[T]HH:mm:ssZ" style="width: 100%" />
        </a-form-item>
        <div class="quick">
          <button v-for="n in [1, 3, 6, 12]" :key="n" type="button" class="quick-chip" @click="extendBy(n)">+{{ n }} {{ unitLabel(c?.rate_period) }}</button>
        </div>
        <a-form-item :label="$t('Rental_Note')" class="mt">
          <a-textarea v-model:value="extendForm.note" :rows="2" />
        </a-form-item>
      </a-form>
    </a-modal>

    <!-- Renew -->
    <a-modal v-model:open="modals.renew" :title="$t('Rental_Renew_Contract')" :ok-text="$t('Rental_Create_Renewal')" :cancel-text="$t('Cancel')" :confirm-loading="busy === 'renew'" @ok="doRenew">
      <p class="muted">{{ $t('Rental_Renew_Help') }}</p>
      <a-form layout="vertical">
        <a-row :gutter="12">
          <a-col :span="14">
            <a-form-item :label="$t('Rental_Start')">
              <a-date-picker v-model:value="renewForm.start_date" show-time format="YYYY-MM-DD HH:mm" value-format="YYYY-MM-DD[T]HH:mm:ssZ" style="width: 100%" />
            </a-form-item>
          </a-col>
          <a-col :span="10">
            <a-form-item :label="$t('Rental_Duration')">
              <a-input-number v-model:value="renewForm.periods" :min="1" :precision="0" style="width: 100%" :addon-after="unitLabel(c?.rate_period)" />
            </a-form-item>
          </a-col>
        </a-row>
        <a-form-item class="no-mb">
          <a-checkbox v-model:checked="renewForm.use_current_rates">{{ $t('Rental_Use_Current_Rates') }}</a-checkbox>
        </a-form-item>
        <a-form-item class="no-mb">
          <a-checkbox v-model:checked="renewForm.activate">{{ $t('Rental_Activate_Now') }}</a-checkbox>
        </a-form-item>
      </a-form>
    </a-modal>

    <!-- Terminate -->
    <a-modal v-model:open="modals.terminate" :title="$t('Rental_Terminate_Contract')" :ok-text="$t('Rental_Terminate')" :cancel-text="$t('Cancel')" :ok-button-props="{ danger: true }" :confirm-loading="busy === 'terminate'" @ok="doTerminate">
      <p class="muted">{{ $t('Rental_Terminate_Help') }}</p>
      <a-form layout="vertical">
        <a-form-item :label="$t('Rental_Termination_Date')">
          <a-date-picker v-model:value="terminateForm.date" show-time format="YYYY-MM-DD HH:mm" value-format="YYYY-MM-DD[T]HH:mm:ssZ" style="width: 100%" />
        </a-form-item>
        <a-form-item :label="$t('Rental_Blacklist_Reason')" required>
          <a-textarea v-model:value="terminateForm.reason" :rows="3" />
        </a-form-item>
      </a-form>
    </a-modal>

    <!-- Sign -->
    <a-modal v-model:open="modals.sign" :title="$t('Rental_Sign_Contract')" :ok-text="$t('Rental_Save_Signature')" :cancel-text="$t('Cancel')" :confirm-loading="busy === 'sign'" width="560px" destroy-on-close @ok="doSign">
      <a-form layout="vertical">
        <a-form-item :label="$t('Rental_Signer_Name')" required>
          <a-input v-model:value="signForm.name" />
        </a-form-item>
        <a-form-item :label="$t('Rental_Signature')" required class="no-mb">
          <SignaturePad ref="padRef" :hint="$t('Rental_Sign_Here')" />
          <a class="clear-link" @click="padRef?.clear()">{{ $t('Rental_Clear') }}</a>
        </a-form-item>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * One rental contract: timeline, money, items, agreement + signature,
 * attachments and history, with every lifecycle action. Which actions show
 * depends on the status (and on whether it has started yet).
 */
import { ref, reactive, computed, createVNode, onMounted, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { message, Modal, Empty } from 'ant-design-vue';
import dayjs from 'dayjs';
import {
  ArrowLeftOutlined, EditOutlined, PrinterOutlined, DownloadOutlined, FileTextOutlined, DownOutlined,
  CheckOutlined, StopOutlined, ReloadOutlined, FieldTimeOutlined, CloseCircleOutlined, DeleteOutlined,
  CheckCircleFilled, ClockCircleOutlined, UploadOutlined, PaperClipOutlined, ExclamationCircleOutlined,
  FlagOutlined, PlusOutlined, DollarOutlined, ExportOutlined, ImportOutlined, RollbackOutlined, MinusOutlined, BellOutlined,
} from '@ant-design/icons-vue';
import RentalReminderModal from './RentalReminderModal.vue';
import PageHeader from '../../components/PageHeader.vue';
import SignaturePad from './SignaturePad.vue';
import RentalPaymentModal from './RentalPaymentModal.vue';
import { useFormat } from '../../composables/useFormat';
import { useAuthStore } from '../../stores/auth';
import { uploadForm } from '../../lib/upload';
import http from '../../lib/http';
import { t, i18n } from '../../i18n';
import {
  CONTRACT_STAGES, CONTRACT_EVENTS, BILLING_TYPES, RENTAL_PERIODS, ID_STATUSES, INVOICE_STATUSES, RENTAL_CONDITIONS, RENTAL_STATUSES,
  invoiceBadge, labelOf, optionOf, perLabel, unitLabel, typeIcon, initials, avatarColor,
} from './rentalOptions';

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();
const { money, date, dateTime } = useFormat();
const simpleEmptyImage = Empty.PRESENTED_IMAGE_SIMPLE;
const badgeStyle = { backgroundColor: 'rgba(128,128,128,.25)', color: 'inherit', boxShadow: 'none', marginInlineStart: '6px' };

const canAdd = computed(() => auth.can('rnt_contracts_add'));
const canEdit = computed(() => auth.can('rnt_contracts_edit'));
const canDelete = computed(() => auth.can('rnt_contracts_delete'));
const canBilling = computed(() => auth.can('rnt_billing_view'));
const canPay = computed(() => auth.can('rnt_payments_add'));
const canManageBilling = computed(() => auth.can('rnt_billing_manage'));
const canHandover = computed(() => auth.can('rnt_handover'));
const remindOpen = ref(false);
const canDeposits = computed(() => auth.can('rnt_deposits'));
/** Some line hasn't left yet (active contracts only). */
const canHandOut = computed(() => c.value?.status === 'active' && (c.value.lines || []).some(l => !l.checked_out_at && !l.returned_at));
/** Some line is still out (or was never formally handed over) on a live contract. */
const canReturn = computed(() => ['active', 'ended', 'terminated'].includes(c.value?.status)
  && (c.value.lines || []).some(l => !l.returned_at)
  && (c.value.lines || []).some(l => l.checked_out_at || c.value.status === 'active'));

const id = computed(() => route.params.id);
const c = ref(null);
const loading = ref(false);
const tab = ref('items');
const busy = ref('');
const templates = ref([]);

const editable = computed(() => c.value && ['draft', 'active'].includes(c.value.status));
const notStarted = computed(() => c.value && dayjs(c.value.start_date).isAfter(dayjs()));

function tp(key, fallback, params) {
  const out = i18n.global.t(key, params);
  if (out !== key) return out;
  return Object.entries(params).reduce((s, [k, v]) => s.replace(`{${k}}`, v), fallback);
}

const daysText = computed(() => {
  const x = c.value;
  if (!x) return '';
  if (x.stage === 'upcoming') return tp('Rental_Starts_In', 'Starts in {n} day(s)', { n: Math.max(0, Math.ceil(dayjs(x.start_date).diff(dayjs(), 'hour') / 24)) });
  if (x.stage === 'overdue') return tp('Rental_Overdue_By', 'Overdue by {n} day(s)', { n: Math.abs(x.days_left ?? 0) });
  if (['active', 'expiring'].includes(x.stage) && x.days_left !== null) return tp('Rental_Days_Remaining', '{n} day(s) left', { n: x.days_left });
  return '';
});

const lateText = computed(() => {
  const x = c.value;
  if (!x) return '';
  const grace = tp('Rental_Summary_Grace', 'A payment becomes late {days} day(s) after its due date.', { days: x.grace_days });
  if (x.late_fee_type === 'none' || !x.late_fee_value) return `${grace} ${t('Rental_Summary_No_Fee', 'No late fee is charged.')}`;
  const amount = x.late_fee_type === 'percent'
    ? tp('Rental_Summary_Percent', '{value}% of the overdue amount', { value: x.late_fee_value })
    : money(x.late_fee_value);
  const when = x.late_fee_frequency === 'daily'
    ? t('Rental_Summary_Daily', 'is added for every day it stays unpaid.')
    : t('Rental_Summary_Once', 'is added once.');
  return `${grace} ${amount} ${when}`;
});

// ----- actions -----
const ACTIONS = computed(() => ({
  activate: { key: 'activate', label: t('Rental_Activate', 'Activate'), icon: CheckOutlined },
  end: { key: 'end', label: t('Rental_End_Contract', 'Mark as ended'), icon: FlagOutlined },
  extend: { key: 'extend', label: t('Rental_Extend', 'Extend'), icon: FieldTimeOutlined },
  renew: { key: 'renew', label: t('Rental_Renew', 'Renew'), icon: ReloadOutlined },
  terminate: { key: 'terminate', label: t('Rental_Terminate', 'Terminate'), icon: StopOutlined, danger: true },
  cancel: { key: 'cancel', label: t('Rental_Cancel_Contract', 'Cancel contract'), icon: CloseCircleOutlined, danger: true },
  sign: { key: 'sign', label: t('Rental_Sign_Now', 'Sign now'), icon: EditOutlined },
  remind: { key: 'remind', label: t('Rental_Send_Reminder', 'Send reminder'), icon: BellOutlined },
  delete: { key: 'delete', label: t('Delete', 'Delete'), icon: DeleteOutlined, danger: true },
}));

const primaryAction = computed(() => {
  const x = c.value;
  if (!x || !canEdit.value) return null;
  if (x.status === 'draft') return ACTIONS.value.activate;
  if (x.status === 'active' && x.stage === 'overdue') return ACTIONS.value.end;
  if (x.status === 'active' && x.stage === 'expiring' && !x.renewal && canAdd.value) return ACTIONS.value.renew;
  if (x.status === 'active') return ACTIONS.value.extend;
  if (x.status === 'ended' && !x.renewal && canAdd.value) return ACTIONS.value.renew;
  return null;
});

const moreActions = computed(() => {
  const x = c.value;
  if (!x) return [];
  const A = ACTIONS.value;
  const list = [];
  if (canEdit.value) {
    if (x.status === 'active') {
      list.push(A.extend, A.end);
      if (!x.renewal && canAdd.value) list.push(A.renew);
      if (!x.is_signed) list.push(A.sign);
      list.push(A.terminate);
      if (notStarted.value) list.push(A.cancel);
    }
    if (x.status === 'draft') {
      list.push(A.extend);
      if (!x.is_signed) list.push(A.sign);
      list.push(A.cancel);
    }
    if (x.status === 'ended' && !x.renewal && canAdd.value) list.push(A.renew);
  }
  if (auth.can('rnt_reminders') && x.status === 'active') list.push(A.remind);
  if (canDelete.value && ['draft', 'cancelled'].includes(x.status)) list.push(A.delete);
  const primary = primaryAction.value?.key;
  return list.filter((a, i) => a.key !== primary && list.findIndex(b => b.key === a.key) === i);
});

function runAction(key) {
  switch (key) {
    case 'activate': return confirmPost('activate', t('Rental_Activate_Confirm', 'Activate this contract? Its items will be booked for these dates.'));
    case 'end': return confirmPost('end', t('Rental_End_Confirm', 'Mark this contract as ended? Its items become available again.'));
    case 'cancel': return confirmPost('cancel', t('Rental_Cancel_Confirm', 'Cancel this contract? It will be kept for reference but can no longer be used.'), true);
    case 'extend': return openExtend();
    case 'renew': return openRenew();
    case 'terminate': return openTerminate();
    case 'sign': return openSign();
    case 'delete': return removeContract();
    case 'remind': remindOpen.value = true; return undefined;
  }
}

function confirmPost(action, content, danger = false) {
  Modal.confirm({
    title: ACTIONS.value[action].label,
    icon: createVNode(ExclamationCircleOutlined),
    content,
    okText: ACTIONS.value[action].label,
    okType: danger ? 'danger' : 'primary',
    cancelText: t('Cancel', 'Cancel'),
    async onOk() {
      await post(action, `rental/contracts/${id.value}/${action}`, {});
    },
  });
}

async function post(key, url, body) {
  busy.value = key;
  try {
    const res = await http.post(url, body);
    message.success(t('Updated_in_successfully', 'Updated successfully'));
    return res || true;
  } catch (e) {
    const errors = e?.data?.errors;
    message.error((errors && Object.values(errors)[0]?.[0]) || e?.data?.message || t('InvalidData', 'Something went wrong'), 6);
    return null;
  } finally {
    busy.value = '';
    await load(false);
  }
}

// ----- modals -----
const modals = reactive({ extend: false, renew: false, terminate: false, sign: false });
const extendForm = reactive({ end_date: null, note: '' });
const renewForm = reactive({ start_date: null, periods: 12, use_current_rates: false, activate: false });
const terminateForm = reactive({ date: null, reason: '' });
const signForm = reactive({ name: '' });
const padRef = ref(null);

function openExtend() {
  extendForm.end_date = c.value.end_date ? dayjs(c.value.end_date).format('YYYY-MM-DD[T]HH:mm:ssZ') : null;
  extendForm.note = '';
  extendBy(1);
  modals.extend = true;
}

function extendBy(n) {
  // No end yet: count from the start if it is still ahead, else from now.
  const start = dayjs(c.value.start_date);
  const from = c.value.end_date ? dayjs(c.value.end_date) : (start.isAfter(dayjs()) ? start : dayjs());
  extendForm.end_date = from.add(n, c.value.rate_period).format('YYYY-MM-DD[T]HH:mm:ssZ');
}

async function doExtend() {
  if (!extendForm.end_date) return;
  const ok = await post('extend', `rental/contracts/${id.value}/extend`, { ...extendForm });
  if (ok) modals.extend = false;
}

function openRenew() {
  renewForm.start_date = c.value.end_date ? dayjs(c.value.end_date).format('YYYY-MM-DD[T]HH:mm:ssZ') : dayjs().format('YYYY-MM-DD[T]HH:mm:ssZ');
  renewForm.periods = c.value.periods || 12;
  renewForm.use_current_rates = false;
  renewForm.activate = false;
  modals.renew = true;
}

async function doRenew() {
  const res = await post('renew', `rental/contracts/${id.value}/renew`, { ...renewForm });
  if (res?.id) {
    modals.renew = false;
    router.push(`/rental/contracts/${res.id}`);
  }
}

function openTerminate() {
  terminateForm.date = dayjs().format('YYYY-MM-DD[T]HH:mm:ssZ');
  terminateForm.reason = '';
  modals.terminate = true;
}

async function doTerminate() {
  if (!terminateForm.reason.trim()) {
    message.error(t('Rental_Blacklist_Reason_Required', 'Please give a reason'));
    return;
  }
  const ok = await post('terminate', `rental/contracts/${id.value}/terminate`, { ...terminateForm });
  if (ok) modals.terminate = false;
}

function openSign() {
  signForm.name = c.value.customer.name || '';
  tab.value = 'agreement';
  modals.sign = true;
}

async function doSign() {
  if (!signForm.name.trim() || !padRef.value || padRef.value.isBlank()) {
    message.error(t('Rental_Sign_Required', 'Enter the signer\'s name and sign in the box'));
    return;
  }
  const ok = await post('sign', `rental/contracts/${id.value}/sign`, { signed_name: signForm.name.trim(), signature: padRef.value.toDataURL() });
  if (ok) modals.sign = false;
}

function removeSignature() {
  Modal.confirm({
    title: t('Rental_Remove_Signature', 'Remove signature'),
    icon: createVNode(ExclamationCircleOutlined),
    okType: 'danger',
    okText: t('Delete', 'Delete'),
    cancelText: t('Cancel', 'Cancel'),
    async onOk() {
      try {
        await http.delete(`rental/contracts/${id.value}/sign`);
        await load(false);
      } catch (e) {
        message.error(t('Rental_Err_Could_Not_Remove_The_Signature', 'Could not remove the signature'));
      }
    },
  });
}

// ----- attachments -----
async function uploadAttachment(file) {
  if (file.size > 10 * 1024 * 1024) {
    message.error(t('Rental_Document_Too_Large', 'Files must be 10 MB or smaller'));
    return false;
  }
  const fd = new FormData();
  fd.append('file', file);
  busy.value = 'upload';
  try {
    const res = await uploadForm(`rental/contracts/${id.value}/attachments`, fd);
    if (res.status >= 200 && res.status < 300) {
      message.success(t('Uploaded', 'Uploaded'));
      await load(false);
    } else {
      const errors = res.data?.errors;
      message.error((errors && Object.values(errors)[0]?.[0]) || res.data?.message || t('Upload_failed', 'Upload failed'));
    }
  } catch (e) {
    message.error(t('Upload_failed', 'Upload failed'));
  } finally {
    busy.value = '';
  }
  return false;
}

function removeAttachment(a) {
  Modal.confirm({
    title: t('Delete_Title', 'Are you sure?'),
    icon: createVNode(ExclamationCircleOutlined),
    content: a.title,
    okType: 'danger',
    okText: t('Delete_confirmButtonText', 'Yes, delete it!'),
    cancelText: t('Delete_cancelButtonText', 'Cancel'),
    async onOk() {
      try {
        await http.delete(`rental/contracts/${id.value}/attachments/${a.id}`);
        await load(false);
      } catch (e) {
        message.error(t('Rental_Err_Could_Not_Delete_This_File', 'Could not delete this file'));
      }
    },
  });
}

function fileSize(bytes) {
  const n = Number(bytes) || 0;
  if (n >= 1024 * 1024) return `${(n / 1024 / 1024).toFixed(1)} MB`;
  if (n >= 1024) return `${Math.round(n / 1024)} KB`;
  return `${n} B`;
}

// ----- delete -----
function removeContract() {
  Modal.confirm({
    title: t('Delete_Title', 'Are you sure?'),
    icon: createVNode(ExclamationCircleOutlined),
    content: c.value.contract_number,
    okType: 'danger',
    okText: t('Delete_confirmButtonText', 'Yes, delete it!'),
    cancelText: t('Delete_cancelButtonText', 'Cancel'),
    async onOk() {
      try {
        await http.delete(`rental/contracts/${id.value}`);
        message.success(t('Deleted_in_successfully', 'Deleted successfully'));
        router.push('/rental/contracts');
      } catch (e) {
        message.error(e?.data?.message || t('Rental_Err_Could_Not_Delete_This_Contract', 'Could not delete this contract'));
      }
    },
  });
}

// ----- billing -----
const bill = ref({ summary: {}, invoices: [], projected: [] });
const billLoading = ref(false);
const payOpen = ref(false);
const chargeOpen = ref(false);
const chargeForm = reactive({ description: '', amount: null, due_date: dayjs().format('YYYY-MM-DD') });

async function loadBilling() {
  if (!canBilling.value) return;
  billLoading.value = true;
  try {
    const data = await http.get(`rental/contracts/${id.value}/billing`);
    bill.value = { summary: data?.summary || {}, invoices: data?.invoices || [], projected: data?.projected || [] };
  } catch (e) { /* tab shows empty */ } finally {
    billLoading.value = false;
  }
}

async function afterMoney() {
  await Promise.all([loadBilling(), load(false)]);
}

async function doCharge() {
  if (!chargeForm.description.trim() || !chargeForm.amount) {
    message.error(t('Rental_Charge_Required', 'Enter a description and an amount'));
    return;
  }
  busy.value = 'charge';
  try {
    await http.post('rental/invoices/charge', { rental_contract_id: c.value.id, ...chargeForm });
    message.success(t('Created_in_successfully', 'Created successfully'));
    chargeOpen.value = false;
    chargeForm.description = '';
    chargeForm.amount = null;
    await afterMoney();
  } catch (e) {
    const errors = e?.data?.errors;
    message.error((errors && Object.values(errors)[0]?.[0]) || e?.data?.message || t('Rental_Err_Could_Not_Add_The_Charge', 'Could not add the charge'));
  } finally {
    busy.value = '';
  }
}

// ----- handover + deposit -----
const ho = ref({ deposit: {}, handovers: [], lines: [] });
const hoLoading = ref(false);
const depOpen = ref(false);
const depForm = reactive({ type: 'collected', amount: null, date: dayjs().format('YYYY-MM-DD'), payment_method_id: undefined, account_id: undefined, notes: '' });
const payMethods = ref([]);
const payAccounts = ref([]);

const DEP_LABELS = {
  collected: ['Rental_Dep_Collected', 'Collected'],
  refunded: ['Rental_Dep_Refunded', 'Refunded'],
  applied: ['Rental_Dep_Applied', 'Used for invoices'],
  retained: ['Rental_Dep_Retained', 'Kept'],
};
function depLabel(type) {
  const [key, fb] = DEP_LABELS[type] || [type, type];
  return t(key, fb);
}

async function loadHandovers() {
  hoLoading.value = true;
  try {
    const data = await http.get(`rental/contracts/${id.value}/handovers`);
    ho.value = { deposit: data?.deposit || {}, handovers: data?.handovers || [], lines: data?.lines || [] };
  } catch (e) { /* tab shows empty */ } finally {
    hoLoading.value = false;
  }
}

async function openDep(type) {
  Object.assign(depForm, { type, amount: null, date: dayjs().format('YYYY-MM-DD'), notes: '' });
  if (type === 'collected') depForm.amount = Math.max(0, (ho.value.deposit.agreed || 0) - (ho.value.deposit.held || 0)) || null;
  if (type === 'refunded') depForm.amount = ho.value.deposit.held || null;
  if (!payMethods.value.length) {
    try {
      const m = await http.get('rental/payments/meta');
      payMethods.value = (m?.payment_methods || []).map(x => ({ value: x.id, label: x.name }));
      payAccounts.value = (m?.accounts || []).map(x => ({ value: x.id, label: x.account_name }));
      depForm.payment_method_id = m?.default_payment_method_id || payMethods.value[0]?.value;
      depForm.account_id = m?.default_account_id || undefined;
    } catch (e) { /* selects stay empty */ }
  }
  depOpen.value = true;
}

async function doDep() {
  if (depForm.type !== 'applied' && !depForm.amount) {
    message.error(t('Field_is_required', 'This field is required'));
    return;
  }
  busy.value = 'deposit';
  try {
    await http.post(`rental/contracts/${id.value}/deposits`, { ...depForm, account_id: depForm.account_id || null });
    message.success(t('Updated_in_successfully', 'Updated successfully'));
    depOpen.value = false;
    await Promise.all([loadHandovers(), loadBilling()]);
  } catch (e) {
    const errors = e?.data?.errors;
    message.error((errors && Object.values(errors)[0]?.[0]) || e?.data?.message || t('Gen_Could_Not_Save', 'Could not save'), 6);
  } finally {
    busy.value = '';
  }
}

function undoDep(tx) {
  Modal.confirm({
    title: t('Rental_Undo', 'Undo'),
    icon: createVNode(ExclamationCircleOutlined),
    content: `${depLabel(tx.type)} — ${money(tx.amount)}`,
    okType: 'danger',
    okText: t('Rental_Undo', 'Undo'),
    cancelText: t('Cancel', 'Cancel'),
    async onOk() {
      try {
        await http.delete(`rental/deposits/${tx.id}`);
        await Promise.all([loadHandovers(), loadBilling()]);
      } catch (e) {
        message.error(e?.data?.message || t('Rental_Err_Could_Not_Undo', 'Could not undo'), 6);
      }
    },
  });
}

// ----- PDF -----
async function printPdf(templateId = null) {
  // Open the tab right away (inside the click), or popup blockers stop it
  // once the PDF takes a few seconds to build.
  const win = window.open('', '_blank');
  try {
    const qs = new URLSearchParams({ preview: '1', ...(templateId ? { template_id: templateId } : {}) });
    const blob = await http.blob(`rental/contracts/${id.value}/pdf?${qs}`);
    const url = window.URL.createObjectURL(blob);
    if (win) win.location.href = url;
    else window.open(url, '_blank');
    setTimeout(() => window.URL.revokeObjectURL(url), 60000);
  } catch (e) {
    if (win) win.close();
    message.error(t('Rental_Err_Could_Not_Generate_The_PDF', 'Could not generate the PDF'));
  }
}

async function downloadPdf() {
  try {
    await http.download(`rental/contracts/${id.value}/pdf`, `Rental_Agreement_${c.value.contract_number}.pdf`);
  } catch (e) {
    message.error(t('Rental_Err_Could_Not_Generate_The_PDF', 'Could not generate the PDF'));
  }
}

// ----- history -----
function eventMeta(e) {
  const m = CONTRACT_EVENTS[e.type] || { key: '', label: e.type, color: 'gray' };
  return { text: m.key ? t(m.key, m.label) : m.label, color: m.color };
}

function eventDetail(e) {
  const m = e.meta || {};
  if (e.type === 'extended' && m.to) return `${m.from ? dateTime(m.from) : t('Rental_Open_Ended', 'Open-ended')} → ${dateTime(m.to)}`;
  if (e.type === 'renewal_created' && m.number) return m.number;
  if (e.type === 'created' && m.number) return `${t('Rental_Renewal_Of', 'Renewal of')} ${m.number}`;
  if (e.type === 'signed' && m.name) return m.name;
  if (e.type === 'terminated' && m.date) return dateTime(m.date);
  if (['payment', 'payment_deleted'].includes(e.type) && m.payment) return `${m.payment} · ${money(m.amount)}`;
  if (e.type === 'charge_added' && m.invoice) return `${m.invoice} · ${money(m.amount)}`;
  if (e.type === 'invoice_voided' && m.invoice) return m.invoice;
  if (['deposit_collected', 'deposit_refunded', 'deposit_retained', 'deposit_applied', 'deposit_reversed'].includes(e.type) && m.amount !== undefined) return money(m.amount);
  if (e.type === 'checked_in' && m.charges) return `${t('Rental_Charged', 'Charged')} ${money(m.charges)}`;
  if (e.type === 'created' && m.reservation) return `${t('Rental_From_Reservation', 'From reservation')} ${m.reservation}`;
  return '';
}

// ----- load -----
async function load(showSpinner = true) {
  if (showSpinner) loading.value = true;
  try {
    const data = await http.get(`rental/contracts/${id.value}`);
    c.value = data?.contract || null;
    if (c.value) {
      loadBilling();
      loadHandovers();
    }
  } catch (e) {
    if (showSpinner) c.value = null;
    if (e?.status !== 404) message.error(t('Rental_Could_Not_Load_Contract', 'Could not load this contract'));
  } finally {
    loading.value = false;
  }
}

async function loadTemplates() {
  try {
    const data = await http.get('rental/agreement-templates');
    templates.value = data?.templates || [];
  } catch (e) { /* printing still works with the built-in wording */ }
}

watch(id, (v) => { if (v) load(); });
onMounted(() => {
  load();
  loadTemplates();
});
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
.mt {
  margin-top: 12px;
}
.no-mb {
  margin-bottom: 4px;
}
.menu-tag {
  margin-inline-start: 8px;
}
.prose {
  margin: 0;
  white-space: pre-line;
  line-height: 1.6;
}

/* hero */
.hero-top {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  margin-bottom: 14px;
}
.hero-stage {
  font-size: 13px;
  padding: 2px 10px;
}
.hero-days {
  font-weight: 500;
  opacity: 0.75;
}
.hero-days.warn {
  color: #b45309;
  opacity: 1;
}
.hero-days.bad {
  color: #dc2626;
  opacity: 1;
}
.track {
  display: flex;
  align-items: center;
  gap: 16px;
}
.track-end {
  display: flex;
  flex-direction: column;
  min-width: 140px;
  font-size: 13px;
}
.track-end.right {
  text-align: end;
}
.track-label {
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  opacity: 0.55;
}
.track-bar {
  flex: 1;
  height: 10px;
  border-radius: 999px;
  background: rgba(128, 128, 128, 0.15);
  overflow: hidden;
}
.track-fill {
  height: 100%;
  border-radius: 999px;
  background: var(--app-primary, #6d28d9);
  transition: width 0.4s ease;
}
.track-fill.expiring {
  background: #d97706;
}
.track-fill.overdue {
  background: #dc2626;
}
.track-fill.ended,
.track-fill.renewed {
  background: #0891b2;
}
.track-fill.terminated,
.track-fill.cancelled,
.track-fill.draft {
  background: #94a3b8;
}
.figures {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
  gap: 12px;
  margin-top: 18px;
}
.figure {
  display: flex;
  flex-direction: column;
  padding: 12px 14px;
  border-radius: 12px;
  background: rgba(128, 128, 128, 0.07);
}
.fig-label {
  font-size: 12px;
  opacity: 0.65;
}
.fig-value {
  font-size: 19px;
  font-weight: 700;
}
.fig-value.accent {
  color: var(--app-primary, #6d28d9);
}
.fig-value.ok {
  color: #16a34a;
  font-size: 16px;
}
.fig-value.muted {
  font-size: 16px;
}
.fig-note {
  font-size: 11.5px;
  opacity: 0.55;
}

/* items */
.lines {
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.line {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 8px 10px;
  border: 1px solid rgba(128, 128, 128, 0.18);
  border-radius: 12px;
  color: inherit;
  transition: border-color 0.15s ease;
}
.line:hover {
  border-color: rgba(var(--app-primary-rgb, 109, 40, 217), 0.5);
}
.line-media {
  width: 52px;
  height: 42px;
  flex: none;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 9px;
  overflow: hidden;
  background: rgba(128, 128, 128, 0.12);
}
.line-media img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}
.line-body {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
}
.line-name {
  font-weight: 500;
}
.line-sub {
  font-size: 12px;
  opacity: 0.55;
}
.line-rate {
  font-size: 13px;
  white-space: nowrap;
}
.line-rate small {
  opacity: 0.6;
}
.line-amount {
  min-width: 100px;
  text-align: end;
}
.totals {
  margin: 14px 0 0 auto;
  max-width: 340px;
}
.totals > div {
  display: flex;
  justify-content: space-between;
  padding: 6px 0;
  border-bottom: 1px dashed rgba(128, 128, 128, 0.22);
  font-size: 13px;
}
.totals dd {
  margin: 0;
  font-weight: 500;
}
.totals .strong dd,
.totals .strong dt {
  font-weight: 700;
}

/* agreement */
.late-box {
  display: flex;
  align-items: flex-start;
  gap: 10px;
  padding: 12px 14px;
  border-radius: 12px;
  background: rgba(217, 119, 6, 0.08);
  font-size: 13px;
}
.late-box :deep(.anticon) {
  margin-top: 3px;
  color: #d97706;
}
.sub-title {
  margin: 18px 0 8px;
  font-size: 14px;
  font-weight: 600;
}
.sig {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-end;
  gap: 16px;
}
.sig-img {
  max-width: 280px;
  max-height: 110px;
  padding: 8px 12px;
  border: 1px solid rgba(128, 128, 128, 0.25);
  border-radius: 10px;
  background: #fff;
}
.sig-meta {
  display: flex;
  flex-direction: column;
  gap: 2px;
  font-size: 13px;
}
.danger-link {
  color: #dc2626;
  font-size: 12.5px;
}
.sig-empty {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  padding: 14px;
  border: 1.5px dashed rgba(128, 128, 128, 0.35);
  border-radius: 12px;
}
.clear-link {
  display: inline-block;
  margin-top: 6px;
  font-size: 12.5px;
}

/* billing */
.bill-tiles {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 10px;
}
.bt {
  display: flex;
  flex-direction: column;
  padding: 10px 12px;
  border-radius: 12px;
  background: rgba(128, 128, 128, 0.07);
}
.bt span {
  font-size: 11.5px;
  opacity: 0.65;
}
.bt strong {
  font-size: 17px;
}
.bt.ok strong {
  color: #16a34a;
}
.bt.accent strong {
  color: var(--app-primary, #6d28d9);
}
.bt.bad strong {
  color: #dc2626;
}
.bill-actions {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px;
  margin: 14px 0;
}
.grow {
  flex: 1;
}
.sched {
  display: flex;
  flex-direction: column;
}
.srow {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 9px 6px;
  border-bottom: 1px solid rgba(128, 128, 128, 0.12);
  color: inherit;
}
.srow:hover:not(.projected) {
  background: rgba(var(--app-primary-rgb, 109, 40, 217), 0.04);
}
.srow.projected {
  opacity: 0.55;
}
.srow.void {
  opacity: 0.5;
  text-decoration: line-through;
}
.s-num {
  min-width: 92px;
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
  font-size: 12.5px;
  font-weight: 600;
}
.s-body {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
}
.s-title {
  font-size: 13px;
  font-weight: 500;
}
.s-sub {
  font-size: 11.5px;
  opacity: 0.6;
}
.s-amt {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  min-width: 110px;
}
.s-amt small {
  font-size: 11px;
  color: #b45309;
}

/* items */
.line-tag {
  margin-inline-start: 6px;
  font-size: 10.5px;
  line-height: 16px;
}

/* deposit + handovers */
.dep-card {
  display: grid;
  grid-template-columns: 220px 1fr;
  gap: 14px;
  padding: 14px;
  border-radius: 14px;
  background: rgba(128, 128, 128, 0.06);
}
.dep-main {
  display: flex;
  flex-direction: column;
}
.dep-label,
.dep-sub {
  font-size: 12px;
  opacity: 0.65;
}
.dep-value {
  font-size: 26px;
  font-weight: 700;
  color: var(--app-primary, #6d28d9);
}
.dep-rows {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 4px 16px;
  margin: 0;
}
.dep-rows > div {
  display: flex;
  justify-content: space-between;
  font-size: 13px;
}
.dep-rows dd {
  margin: 0;
  font-weight: 600;
}
.dep-actions {
  grid-column: 1 / -1;
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}
.dep-list {
  margin: 12px 0 4px;
}
.dep-tx {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 7px 4px;
  border-bottom: 1px solid rgba(128, 128, 128, 0.12);
}
.tx-type {
  min-width: 120px;
  padding: 2px 8px;
  border-radius: 999px;
  font-size: 11.5px;
  font-weight: 600;
  text-align: center;
  background: rgba(128, 128, 128, 0.12);
}
.tx-type.collected { color: #15803d; background: rgba(22, 163, 74, 0.12); }
.tx-type.refunded { color: #0369a1; background: rgba(2, 132, 199, 0.12); }
.tx-type.applied { color: #6d28d9; background: rgba(109, 40, 217, 0.1); }
.tx-type.retained { color: #b45309; background: rgba(217, 119, 6, 0.14); }
.tx-body {
  flex: 1;
  display: flex;
  flex-direction: column;
  font-size: 12.5px;
}
.tx-body span {
  opacity: 0.65;
}
.hos {
  display: flex;
  flex-direction: column;
  gap: 12px;
}
.ho {
  padding: 12px 14px;
  border: 1px solid rgba(128, 128, 128, 0.18);
  border-inline-start: 4px solid #7c3aed;
  border-radius: 12px;
}
.ho.checkin {
  border-inline-start-color: #0891b2;
}
.ho-head {
  display: flex;
  align-items: center;
  gap: 10px;
}
.ho-ic {
  width: 32px;
  height: 32px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 9px;
  color: #fff;
  background: #7c3aed;
}
.ho.checkin .ho-ic {
  background: #0891b2;
}
.ho-title {
  flex: 1;
  display: flex;
  flex-direction: column;
}
.ho-title span {
  font-size: 12px;
  opacity: 0.6;
}
.ho-items {
  margin: 10px 0 0;
  padding-inline-start: 18px;
  font-size: 13px;
}
.mini {
  margin-inline-start: 6px;
  font-size: 10.5px;
  line-height: 16px;
}
.ho-checks {
  display: flex;
  flex-wrap: wrap;
  gap: 4px 12px;
  margin-top: 8px;
  font-size: 12px;
}
.ho-check {
  opacity: 0.5;
}
.ho-check.on {
  opacity: 1;
  color: #15803d;
}
.ho-photos {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  margin-top: 10px;
}
.ho-photos :deep(.ant-image-img) {
  object-fit: cover;
  border-radius: 8px;
}
.ho-sig {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-top: 10px;
  font-size: 12px;
}
.ho-sig img {
  max-height: 48px;
  padding: 4px 8px;
  border: 1px solid rgba(128, 128, 128, 0.2);
  border-radius: 8px;
  background: #fff;
}
.ho-notes {
  margin: 8px 0 0;
  font-size: 12.5px;
  opacity: 0.75;
  white-space: pre-line;
}
.bad {
  color: #dc2626;
}

/* attachments */
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
  font-size: 18px;
  color: var(--app-primary, #6d28d9);
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

/* history */
.history {
  margin-top: 8px;
}
.ev-head {
  display: flex;
  flex-wrap: wrap;
  align-items: baseline;
  gap: 4px 10px;
}
.ev-when {
  font-size: 12px;
  opacity: 0.55;
}
.ev-detail {
  font-size: 12.5px;
  opacity: 0.75;
}
.ev-note {
  margin-top: 4px;
  padding: 6px 10px;
  border-radius: 8px;
  font-size: 12.5px;
  background: rgba(128, 128, 128, 0.08);
  white-space: pre-line;
}

/* customer */
.cust {
  display: flex;
  align-items: center;
  gap: 12px;
  color: inherit;
}
.cust-body {
  display: flex;
  flex-direction: column;
  min-width: 0;
}
.cust-body span {
  font-size: 12.5px;
  opacity: 0.6;
}
.cust:hover strong {
  color: var(--app-primary, #6d28d9);
}
.cust-tags {
  display: flex;
  flex-wrap: wrap;
  gap: 4px;
  margin-top: 12px;
}

/* quick chips (extend modal) */
.quick {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}
.quick-chip {
  padding: 3px 11px;
  border: 1px solid rgba(128, 128, 128, 0.28);
  border-radius: 999px;
  background: transparent;
  color: inherit;
  font: inherit;
  font-size: 12.5px;
  cursor: pointer;
}
.quick-chip:hover {
  border-color: var(--app-primary, #6d28d9);
  color: var(--app-primary, #6d28d9);
}

@media (max-width: 767px) {
  .dep-card {
    grid-template-columns: 1fr;
  }
  .bill-tiles {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
  .track {
    flex-direction: column;
    align-items: stretch;
    gap: 8px;
  }
  .track-end,
  .track-end.right {
    text-align: start;
  }
  .line-rate {
    display: none;
  }
}
</style>
