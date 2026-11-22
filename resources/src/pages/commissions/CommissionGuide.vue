<template>
  <div class="page">
    <PageHeader :title="$t('Commission_Guide')" :breadcrumb="[$t('Commissions'), $t('Commission_Guide')]">
      <template #actions>
        <a-button @click="$router.push('/commissions/programs')">{{ $t('Commission_Programs') }}</a-button>
        <a-button @click="$router.push('/commissions/rules')">{{ $t('Commission_Rules') }}</a-button>
        <a-button @click="$router.push('/commissions/report')">{{ $t('Commission_Report') }}</a-button>
      </template>
    </PageHeader>

    <a-alert type="info" show-icon class="intro" :message="$t('Comm_G_Intro_Title')" :description="$t('Comm_G_Intro')" />

    <a-spin :spinning="loading">
      <a-row :gutter="[16, 16]">
        <!-- Live checklist -->
        <a-col :xs="24" :xl="10">
          <a-card size="small" :title="$t('Comm_G_Your_Setup')" class="fill">
            <p class="muted">{{ $t('Comm_G_Your_Setup_Help') }}</p>
            <ol class="checklist">
              <li v-for="step in steps" :key="step.key" :class="'is-' + step.state">
                <span class="dot">
                  <CheckOutlined v-if="step.state === 'done'" />
                  <ExclamationOutlined v-else-if="step.state === 'warn'" />
                  <span v-else>{{ step.n }}</span>
                </span>
                <div class="step-body">
                  <div class="step-title">{{ step.title }}<a-tag v-if="step.optional" class="opt">{{ $t('Comm_G_Optional') }}</a-tag></div>
                  <div class="muted step-help">{{ step.help }}</div>
                  <a v-if="step.link" class="step-link" @click="$router.push(step.link)">{{ step.linkText }} →</a>
                </div>
              </li>
            </ol>
          </a-card>
        </a-col>

        <!-- Rule calculator -->
        <a-col :xs="24" :xl="14">
          <a-card size="small" :title="$t('Comm_G_Try_Rule')" class="fill">
            <p class="muted">{{ $t('Comm_G_Try_Rule_Help') }}</p>
            <a-row :gutter="12">
              <a-col :xs="12" :md="8">
                <div class="field-label">{{ $t('Comm_G_Sale_Total') }}</div>
                <a-input-number v-model:value="calc.total" :min="0" :step="10" class="full" />
              </a-col>
              <a-col :xs="12" :md="8">
                <div class="field-label">{{ $t('Paid_Amount') }}</div>
                <a-input-number v-model:value="calc.paid" :min="0" :step="10" class="full" />
              </a-col>
              <a-col :xs="12" :md="8">
                <div class="field-label">{{ $t('Source') }}</div>
                <a-select v-model:value="calc.source" class="full">
                  <a-select-option value="sale_total">{{ $t('Sale_Total') }}</a-select-option>
                  <a-select-option value="paid_amount">{{ $t('Paid_Amount') }}</a-select-option>
                </a-select>
              </a-col>
              <a-col :xs="12" :md="8">
                <div class="field-label">{{ $t('Type') }}</div>
                <a-select v-model:value="calc.type" class="full">
                  <a-select-option value="percentage">{{ $t('Percentage') }}</a-select-option>
                  <a-select-option value="fixed">{{ $t('Fixed') }}</a-select-option>
                </a-select>
              </a-col>
              <a-col :xs="12" :md="8">
                <div class="field-label">{{ $t('Value') }}<template v-if="calc.type === 'percentage'"> (%)</template></div>
                <a-input-number v-model:value="calc.value" :min="0" :step="0.5" class="full" />
              </a-col>
              <a-col :xs="12" :md="8">
                <div class="field-label">{{ $t('Min_Threshold') }}</div>
                <a-input-number v-model:value="calc.min" :min="0" :step="10" class="full" :placeholder="$t('Comm_G_None')" />
              </a-col>
              <a-col :xs="12" :md="8">
                <div class="field-label">{{ $t('Max_Cap') }}</div>
                <a-input-number v-model:value="calc.cap" :min="0" :step="10" class="full" :placeholder="$t('Comm_G_None')" />
              </a-col>
            </a-row>

            <div class="result" :class="{ 'is-none': !result.amount }">
              <div class="result-amount">
                <span class="muted">{{ $t('Comm_G_Commission') }}</span>
                <strong>{{ result.amount ? formatMoney(result.amount) : $t('Comm_G_No_Commission') }}</strong>
              </div>
              <ul class="result-lines">
                <li v-for="(line, i) in result.lines" :key="i">{{ line }}</li>
              </ul>
            </div>
          </a-card>
        </a-col>

        <!-- The flow -->
        <a-col :xs="24">
          <a-card size="small" :title="$t('Comm_G_The_Flow')">
            <div class="flow">
              <div v-for="(f, i) in FLOW" :key="f" class="flow-step">
                <span class="flow-num">{{ i + 1 }}</span>
                <div>
                  <div class="step-title">{{ $t('Comm_G_Flow_' + f) }}</div>
                  <div class="muted step-help">{{ $t('Comm_G_Flow_' + f + '_Help') }}</div>
                </div>
              </div>
            </div>
          </a-card>
        </a-col>

        <!-- How it works -->
        <a-col :xs="24">
          <a-card size="small" :title="$t('Comm_G_How_It_Works')">
            <a-collapse v-model:active-key="openTopics" ghost>
              <a-collapse-panel key="credit" :header="$t('Comm_G_T_Credit')">
                <p class="topic">{{ $t('Comm_G_Credit_Intro') }}</p>
                <ol class="numbered">
                  <li v-for="k in CREDIT" :key="k">{{ $t('Comm_G_Credit_' + k) }}</li>
                </ol>
                <p class="muted topic-note">{{ $t('Comm_G_Credit_Note') }}</p>
              </a-collapse-panel>

              <a-collapse-panel key="when" :header="$t('Comm_G_T_When')">
                <p class="topic">{{ $t('Comm_G_When_Intro') }}</p>
                <ul class="plain">
                  <li v-for="k in WHEN" :key="k">{{ $t('Comm_G_When_' + k) }}</li>
                </ul>
                <p class="topic">{{ $t('Comm_G_When_Not_Intro') }}</p>
                <ul class="plain">
                  <li v-for="k in WHEN_NOT" :key="k">{{ $t('Comm_G_When_Not_' + k) }}</li>
                </ul>
              </a-collapse-panel>

              <a-collapse-panel key="statuses" :header="$t('Comm_G_T_Statuses')">
                <a-table :data-source="statusRows" :columns="statusColumns" row-key="value" size="small" :pagination="false">
                  <template #bodyCell="{ column, record }">
                    <template v-if="column.key === 'status'"><a-tag :color="record.color">{{ $t(record.label) }}</a-tag></template>
                  </template>
                </a-table>
              </a-collapse-panel>

              <a-collapse-panel key="programs" :header="$t('Comm_G_T_Programs')">
                <p class="topic">{{ $t('Comm_G_Programs_Body') }}</p>
                <a-table :data-source="programFields" :columns="fieldColumns" row-key="key" size="small" :pagination="false" class="topic-table" />
              </a-collapse-panel>

              <a-collapse-panel key="agents" :header="$t('Comm_G_T_Agents')">
                <p class="topic">{{ $t('Comm_G_Agents_Body') }}</p>
                <a-table :data-source="agentFields" :columns="fieldColumns" row-key="key" size="small" :pagination="false" class="topic-table" />
              </a-collapse-panel>

              <a-collapse-panel key="rules" :header="$t('Comm_G_T_Rules')">
                <p class="topic">{{ $t('Comm_G_Rules_Body') }}</p>
                <a-table :data-source="ruleFields" :columns="fieldColumns" row-key="key" size="small" :pagination="false" class="topic-table" />
              </a-collapse-panel>

              <a-collapse-panel key="stacking" :header="$t('Comm_G_T_Stacking')">
                <p class="topic">{{ $t('Comm_G_Stacking_Body') }}</p>
              </a-collapse-panel>

              <a-collapse-panel key="paying" :header="$t('Comm_G_T_Paying')">
                <p class="topic">{{ $t('Comm_G_Paying_Body') }}</p>
              </a-collapse-panel>

              <a-collapse-panel key="snapshot" :header="$t('Comm_G_T_Snapshot')">
                <p class="topic">{{ $t('Comm_G_Snapshot_Body') }}</p>
              </a-collapse-panel>

              <a-collapse-panel key="permissions" :header="$t('Comm_G_T_Permissions')">
                <a-table :data-source="permissionRows" :columns="permissionColumns" row-key="key" size="small" :pagination="false">
                  <template #bodyCell="{ column, record }">
                    <template v-if="column.key === 'perm'"><code>{{ record.key }}</code></template>
                  </template>
                </a-table>
              </a-collapse-panel>

              <a-collapse-panel key="faq" :header="$t('Comm_G_T_Faq')">
                <dl class="faq">
                  <template v-for="q in FAQ" :key="q">
                    <dt>{{ $t('Comm_G_Q_' + q) }}</dt>
                    <dd>{{ $t('Comm_G_A_' + q) }}</dd>
                  </template>
                </dl>
              </a-collapse-panel>
            </a-collapse>
          </a-card>
        </a-col>
      </a-row>
    </a-spin>
  </div>
</template>

<script setup>
/**
 * Commissions → Guide — read-only explainer built from the module's own
 * list endpoints (commission_programs / sales_agents / commission_rules with
 * limit=-1, commission_report/summary): a live setup checklist, a rule
 * calculator that mirrors CommissionService::commissionFor(), the
 * pending → approved → paid flow and the rules behind it (rule modes,
 * recalculation when a sale changes, adjustments).
 */
import { ref, computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { CheckOutlined, ExclamationOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import { useAuthStore } from '../../stores/auth';
import http from '../../lib/http';

const { t } = useI18n();
const auth = useAuthStore();

const FLOW = ['Program', 'Agent', 'Rule', 'Sale', 'Approve', 'Pay'];
const CREDIT = ['Picked', 'Seller', 'Cashier', 'Nobody'];
const WHEN = ['Create', 'Edit', 'Pos', 'Payment', 'Cod', 'Changes'];
const WHEN_NOT = ['Status', 'Before', 'Import', 'Online'];
const FAQ = ['Empty', 'Mode_Old', 'Twice', 'Rate', 'Return', 'Negative', 'Wrong_Agent', 'Inactive', 'Partial', 'Undo', 'Dates', 'Ledger'];

const loading = ref(true);
const openTopics = ref(['credit']);
const data = ref({ programs: [], agents: [], rules: [], summary: null });

// ---------------------------------------------------------------- checklist
function day(v) {
  return v ? String(v).slice(0, 10) : null;
}
const today = new Date().toISOString().slice(0, 10);

const liveProgramIds = computed(() => new Set(
  data.value.programs
    .filter(p => p.is_active && (!day(p.valid_from) || day(p.valid_from) <= today) && (!day(p.valid_to) || day(p.valid_to) >= today))
    .map(p => p.id),
));

const steps = computed(() => {
  const { programs, agents, rules, summary } = data.value;
  const activeAgents = agents.filter(a => a.is_active);
  const linkedAgents = activeAgents.filter(a => a.user_id);
  const liveRules = rules.filter(r => r.is_active && liveProgramIds.value.has(r.commission_program_id));
  const byStatus = (summary && summary.by_status) || {};
  const count = s => Number(byStatus[s]?.count || 0);
  const totalCount = Number(summary?.totals?.total_count || 0);
  const canAdd = auth.can('commissions_add');
  const canEdit = auth.can('commissions_edit');

  const list = [
    {
      key: 'program',
      state: liveProgramIds.value.size ? 'done' : (programs.length ? 'warn' : 'todo'),
      title: t('Comm_G_Step_Program'),
      help: liveProgramIds.value.size || !programs.length ? t('Comm_G_Step_Program_Help') : t('Comm_G_Step_Program_Warn'),
      link: liveProgramIds.value.size ? null : '/commissions/programs', linkText: t('Commission_Programs'),
    },
    {
      key: 'agent',
      state: activeAgents.length ? 'done' : 'todo',
      title: t('Comm_G_Step_Agent'),
      help: t('Comm_G_Step_Agent_Help'),
      link: activeAgents.length ? null : '/commissions/agents', linkText: t('Sales_Agents'),
    },
    {
      key: 'link',
      optional: true,
      state: !activeAgents.length ? 'todo' : (linkedAgents.length === activeAgents.length ? 'done' : 'warn'),
      title: t('Comm_G_Step_Link'),
      help: activeAgents.length && linkedAgents.length < activeAgents.length
        ? t('Comm_G_Step_Link_Warn', { n: activeAgents.length - linkedAgents.length })
        : t('Comm_G_Step_Link_Help'),
      link: activeAgents.length && linkedAgents.length < activeAgents.length ? '/commissions/agents' : null, linkText: t('Sales_Agents'),
    },
    {
      key: 'rule',
      state: liveRules.length ? 'done' : (rules.length ? 'warn' : 'todo'),
      title: t('Comm_G_Step_Rule'),
      help: liveRules.length || !rules.length ? t('Comm_G_Step_Rule_Help') : t('Comm_G_Step_Rule_Warn'),
      link: liveRules.length ? null : '/commissions/rules', linkText: t('Commission_Rules'),
    },
    {
      key: 'sale',
      state: totalCount ? 'done' : 'todo',
      title: t('Comm_G_Step_Sale'),
      help: totalCount ? t('Comm_G_Step_Sale_Done', { n: totalCount }) : t('Comm_G_Step_Sale_Help'),
      link: totalCount || !auth.can('Pos_view') ? null : '/pos', linkText: t('POS'),
    },
    {
      key: 'approve',
      state: count('pending') ? 'warn' : (totalCount ? 'done' : 'todo'),
      title: t('Comm_G_Step_Approve'),
      help: count('pending') ? t('Comm_G_Step_Approve_Warn', { n: count('pending') }) : t('Comm_G_Step_Approve_Help'),
      link: count('pending') && canEdit ? '/commissions/report' : null, linkText: t('Commission_Report'),
    },
    {
      key: 'pay',
      state: count('approved') ? 'warn' : (count('paid') ? 'done' : 'todo'),
      title: t('Comm_G_Step_Pay'),
      help: count('approved') ? t('Comm_G_Step_Pay_Warn', { n: count('approved') }) : t('Comm_G_Step_Pay_Help'),
      link: count('approved') && canAdd ? '/commissions/receipts' : null, linkText: t('Commission_Receipts'),
    },
  ];
  // The setup pages are only useful to users who can add there.
  const SETUP = ['program', 'agent', 'link', 'rule'];
  return list.map((s, i) => ({ ...s, n: i + 1, link: SETUP.includes(s.key) && !canAdd ? null : s.link }));
});

// ---------------------------------------------------------------- calculator
const calc = ref({ total: 1000, paid: 1000, source: 'sale_total', type: 'percentage', value: 5, min: null, cap: null });

// Same order of checks as CommissionService::commissionFor().
const result = computed(() => {
  const c = calc.value;
  const base = Number((c.source === 'paid_amount' ? c.paid : c.total) || 0);
  const value = Number(c.value || 0);
  const lines = [t('Comm_G_Calc_Base', { source: t(c.source === 'paid_amount' ? 'Paid_Amount' : 'Sale_Total'), base: formatMoney(base) })];

  if (base <= 0) return { amount: 0, lines: [...lines, t('Comm_G_Calc_Zero_Base')] };
  if (c.min != null && c.min !== '' && Number(c.min) > base) {
    return { amount: 0, lines: [...lines, t('Comm_G_Calc_Below_Min', { min: formatMoney(c.min) })] };
  }

  let amount;
  if (c.type === 'percentage') {
    amount = Math.round(base * (value / 100) * 10000) / 10000;
    lines.push(t('Comm_G_Calc_Pct', { value, base: formatMoney(base), amount: formatMoney(amount) }));
  } else {
    amount = value;
    lines.push(t('Comm_G_Calc_Fixed', { amount: formatMoney(amount) }));
  }
  if (c.cap != null && c.cap !== '' && amount > Number(c.cap)) {
    amount = Number(c.cap);
    lines.push(t('Comm_G_Calc_Capped', { cap: formatMoney(amount) }));
  }
  if (amount <= 0) return { amount: 0, lines: [...lines, t('Comm_G_Calc_Zero')] };
  return { amount, lines };
});

// ---------------------------------------------------------------- reference tables
const statusRows = computed(() => [
  { value: 'pending', label: 'Pending', color: 'warning', meaning: t('Comm_G_S_Pending') },
  { value: 'approved', label: 'Approved', color: 'processing', meaning: t('Comm_G_S_Approved') },
  { value: 'paid', label: 'Paid', color: 'success', meaning: t('Comm_G_S_Paid') },
  { value: 'cancelled', label: 'Cancelled', color: 'default', meaning: t('Comm_G_S_Cancelled') },
]);
const statusColumns = computed(() => [
  { title: t('Status'), key: 'status', width: 140 },
  { title: t('Comm_G_Meaning'), dataIndex: 'meaning', key: 'meaning' },
]);

const field = (label, help) => ({ key: label, label: t(label), help: t(help) });
const programFields = computed(() => [
  field('Name', 'Comm_G_F_Program_Name'),
  field('Active', 'Comm_G_F_Program_Active'),
  field('Comm_Rule_Mode', 'Comm_G_F_Program_Mode'),
  field('Valid_From', 'Comm_G_F_Program_From'),
  field('Valid_To', 'Comm_G_F_Program_To'),
]);
const agentFields = computed(() => [
  field('Name', 'Comm_G_F_Agent_Name'),
  field('Code', 'Comm_G_F_Agent_Code'),
  field('Link_User', 'Comm_G_F_Agent_User'),
  field('Active', 'Comm_G_F_Agent_Active'),
]);
const ruleFields = computed(() => [
  field('Commission_Program', 'Comm_G_F_Rule_Program'),
  field('Type', 'Comm_G_F_Rule_Type'),
  field('Source', 'Comm_G_F_Rule_Source'),
  field('Value', 'Comm_G_F_Rule_Value'),
  field('Min_Threshold', 'Comm_G_F_Rule_Min'),
  field('Max_Cap', 'Comm_G_F_Rule_Cap'),
  field('Applies_To', 'Comm_G_F_Rule_Applies'),
  field('Priority', 'Comm_G_F_Rule_Priority'),
  field('Active', 'Comm_G_F_Rule_Active'),
]);
const fieldColumns = computed(() => [
  { title: t('Comm_G_Field'), dataIndex: 'label', key: 'label', width: 180 },
  { title: t('Comm_G_Meaning'), dataIndex: 'help', key: 'help' },
]);

const permissionRows = computed(() => [
  { key: 'commissions_view', help: t('Comm_G_P_View') },
  { key: 'commissions_add', help: t('Comm_G_P_Add') },
  { key: 'commissions_edit', help: t('Comm_G_P_Edit') },
  { key: 'commissions_delete', help: t('Comm_G_P_Delete') },
]);
const permissionColumns = computed(() => [
  { title: t('Comm_G_Permission'), key: 'perm', width: 200 },
  { title: t('Comm_G_Lets_You'), dataIndex: 'help', key: 'help' },
]);

function formatMoney(v) {
  return Number(v || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function rows(res, key) {
  const d = res.data || res;
  return Array.isArray(d) ? d : (d[key] || []);
}

onMounted(async () => {
  // Each source is independent: one failing leaves its checklist step "to do".
  const [programs, agents, rules, summary] = await Promise.allSettled([
    http.get('commission_programs', { limit: '-1' }),
    http.get('sales_agents', { limit: '-1' }),
    http.get('commission_rules', { limit: '-1' }),
    http.get('commission_report/summary'),
  ]);
  data.value = {
    programs: programs.status === 'fulfilled' ? rows(programs.value, 'programs') : [],
    agents: agents.status === 'fulfilled' ? rows(agents.value, 'agents') : [],
    rules: rules.status === 'fulfilled' ? rows(rules.value, 'rules') : [],
    summary: summary.status === 'fulfilled' ? (summary.value.data || summary.value) : null,
  };
  loading.value = false;
});
</script>

<style scoped>
.intro {
  margin-bottom: 16px;
}
.muted {
  opacity: 0.62;
}
.fill {
  height: 100%;
}
.full {
  width: 100%;
}
.checklist {
  list-style: none;
  margin: 0;
  padding: 0;
}
.checklist li {
  display: flex;
  gap: 12px;
  padding: 10px 0;
  border-bottom: 1px solid rgba(128, 128, 128, 0.12);
}
.checklist li:last-child {
  border-bottom: 0;
}
.dot,
.flow-num {
  flex: 0 0 26px;
  height: 26px;
  border-radius: 50%;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-size: 12px;
  font-weight: 600;
  background: rgba(128, 128, 128, 0.16);
}
.flow-num {
  background: rgba(var(--app-primary-rgb, 109, 40, 217), 0.14);
}
.is-done .dot {
  background: rgba(82, 196, 26, 0.18);
  color: #389e0d;
}
.is-warn .dot {
  background: rgba(250, 173, 20, 0.2);
  color: #d48806;
}
.step-title {
  font-weight: 600;
}
.step-help {
  font-size: 12.5px;
  margin-top: 2px;
}
.step-link {
  font-size: 12.5px;
  display: inline-block;
  margin-top: 4px;
}
.opt {
  margin-left: 8px;
  font-weight: normal;
}
.field-label {
  font-size: 12.5px;
  margin: 8px 0 4px;
}
.result {
  margin-top: 16px;
  padding: 12px 14px;
  border-radius: 10px;
  border: 1px solid rgba(82, 196, 26, 0.35);
  background: rgba(82, 196, 26, 0.06);
}
.result.is-none {
  border-color: rgba(128, 128, 128, 0.25);
  background: rgba(128, 128, 128, 0.06);
}
.result-amount {
  display: flex;
  justify-content: space-between;
  align-items: baseline;
  gap: 12px;
  flex-wrap: wrap;
}
.result-amount strong {
  font-size: 20px;
}
.result-lines {
  margin: 8px 0 0;
  padding-left: 18px;
  font-size: 12.5px;
}
.flow {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
  gap: 14px 20px;
}
.flow-step {
  display: flex;
  gap: 12px;
}
.topic,
.topic-note {
  white-space: pre-line;
  margin: 0 0 8px;
}
.topic-note {
  font-size: 12.5px;
}
.topic-table {
  margin-top: 4px;
}
.numbered,
.plain {
  margin: 0 0 10px;
  padding-left: 20px;
}
.numbered li,
.plain li {
  margin-bottom: 6px;
}
.faq dt {
  font-weight: 600;
  margin-top: 10px;
}
.faq dd {
  margin: 2px 0 0;
}
</style>
