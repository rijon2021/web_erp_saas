<template>
  <div class="page">
    <PageHeader :title="$t('Scale_Weighing_Scale')" :breadcrumb="[$t('Settings'), $t('Scale_Weighing_Scale')]" />

    <div v-if="loading" style="display: flex; justify-content: center; padding: 96px 0">
      <a-spin size="large" />
    </div>

    <a-form v-else layout="vertical">
      <!-- Columns from the 2026_10_27_000004 migration are missing: nothing
           here could be saved yet. -->
      <a-alert
        v-if="!ready" type="warning" show-icon
        :message="$t('Scale_Not_Ready')" style="margin-bottom: 16px"
      />

      <!-- On / off -->
      <a-card size="small" style="margin-bottom: 16px">
        <template #title>
          {{ $t('Scale_Integration') }}
          <span class="card-sub">{{ $t('Scale_Integration_Sub') }}</span>
        </template>
        <a-space direction="vertical" size="small">
          <a-space>
            <a-switch v-model:checked="enabled" />
            <span>{{ $t('Scale_Decode_Labels') }}</span>
          </a-space>
          <a-typography-text type="secondary">{{ $t('Scale_Decode_Labels_Help') }}</a-typography-text>
        </a-space>
      </a-card>

      <!-- Formats stay visible (and validated) while decoding is off, so a
           problem that blocks saving is never hidden. -->
        <!-- One card per label format -->
        <a-card
          v-for="(f, i) in formats" :key="f._key"
          size="small" class="format-card" style="margin-bottom: 16px"
        >
          <template #title>
            <span class="format-no">{{ i + 1 }}</span>
            {{ f.name || $t('Scale_Format_N', { n: i + 1 }) }}
            <span class="card-sub">{{ $t('Scale_Digits_Long', { n: formatLength(cleaned[i]) }) }}</span>
          </template>
          <template #extra>
            <a-space :size="4">
              <a-button size="small" type="text" :disabled="i === 0" :title="$t('Scale_Move_Up')" @click="move(i, -1)"><ArrowUpOutlined /></a-button>
              <a-button size="small" type="text" :disabled="i === formats.length - 1" :title="$t('Scale_Move_Down')" @click="move(i, 1)"><ArrowDownOutlined /></a-button>
              <a-popconfirm :title="$t('Scale_Remove_Format_Confirm')" @confirm="remove(i)">
                <a-button size="small" type="text" danger :title="$t('Delete')"><DeleteOutlined /></a-button>
              </a-popconfirm>
            </a-space>
          </template>

          <!-- What a label of this format looks like, digit by digit -->
          <div class="layout-strip" :aria-label="$t('Scale_Layout_Preview')">
            <span
              v-for="(seg, si) in segmentsOf(f, exampleBarcode(f))" :key="si"
              class="seg" :class="'seg-' + seg.kind"
            >
              <span class="seg-digits">{{ seg.text }}</span>
              <span class="seg-label">{{ $t(seg.label) }}</span>
            </span>
          </div>

          <a-alert
            v-if="problemOf(f, i)" type="error" show-icon
            :message="problemOf(f, i)" style="margin-bottom: 12px"
          />

          <a-row :gutter="16">
            <a-col :xs="24" :md="12">
              <a-form-item :label="$t('Scale_Format_Name')">
                <a-input v-model:value="f.name" :maxlength="60" :placeholder="$t('Scale_Format_Name_Placeholder')" />
              </a-form-item>
            </a-col>
            <a-col :xs="24" :md="12">
              <a-form-item :label="$t('Scale_Value_Is')" :extra="$t('Scale_Value_Is_Help')">
                <a-select
                  v-model:value="f.value_type"
                  :options="[
                    { value: 'weight', label: $t('Scale_Value_Weight') },
                    { value: 'price', label: $t('Scale_Value_Price') },
                  ]"
                />
              </a-form-item>
            </a-col>
          </a-row>

          <div class="group-title">{{ $t('Scale_Barcode_Layout') }} <span class="card-sub">{{ $t('Scale_Barcode_Layout_Sub') }}</span></div>
          <a-row :gutter="16">
            <a-col :xs="24" :md="8">
              <a-form-item :label="$t('Scale_Prefix')" :extra="$t('Scale_Prefix_Help')">
                <a-input v-model:value="f.prefix" :maxlength="4" inputmode="numeric" :placeholder="$t('Scale_None')" />
              </a-form-item>
            </a-col>
            <a-col :xs="12" :md="8">
              <a-form-item :label="$t('Scale_Plu_Digits')" :extra="$t('Scale_Plu_Digits_Help')">
                <a-input-number v-model:value="f.plu_digits" :min="1" :max="8" :precision="0" style="width: 100%" />
              </a-form-item>
            </a-col>
            <a-col :xs="12" :md="8">
              <a-form-item :label="$t('Scale_Skip_Digits')" :extra="$t('Scale_Skip_Digits_Help')">
                <a-input-number v-model:value="f.skip_digits" :min="0" :max="3" :precision="0" style="width: 100%" />
              </a-form-item>
            </a-col>
          </a-row>

          <div class="group-title">{{ $t('Scale_Embedded_Value') }} <span class="card-sub">{{ $t('Scale_Embedded_Value_Sub') }}</span></div>
          <a-row :gutter="16">
            <a-col :xs="12" :md="8">
              <a-form-item :label="$t('Scale_Value_Digits')" :extra="$t('Scale_Value_Digits_Help')">
                <a-input-number v-model:value="f.value_digits" :min="1" :max="8" :precision="0" style="width: 100%" />
              </a-form-item>
            </a-col>
            <a-col :xs="12" :md="8">
              <a-form-item
                :label="$t('Scale_Value_Decimals')"
                :extra="f.value_type === 'price' ? $t('Scale_Value_Decimals_Price_Help') : $t('Scale_Value_Decimals_Weight_Help')"
              >
                <a-input-number v-model:value="f.value_decimals" :min="0" :max="6" :precision="0" style="width: 100%" />
              </a-form-item>
            </a-col>
            <a-col :xs="24" :md="8">
              <a-form-item :label="$t('Scale_Check_Digit')" :extra="$t('Scale_Check_Digit_Help')">
                <a-switch v-model:checked="f.check_digit" />
              </a-form-item>
            </a-col>
          </a-row>
        </a-card>

        <a-empty v-if="!formats.length" :description="$t('Scale_No_Formats')" style="margin-bottom: 16px" />

        <a-dropdown :trigger="['click']" :disabled="formats.length >= MAX_FORMATS">
          <a-button style="margin-bottom: 16px"><PlusOutlined /> {{ $t('Scale_Add_Format') }} <DownOutlined /></a-button>
          <template #overlay>
            <a-menu @click="({ key }) => add(key)">
              <a-menu-item key="weight">{{ $t('Scale_Preset_Weight') }}</a-menu-item>
              <a-menu-item key="price">{{ $t('Scale_Preset_Price') }}</a-menu-item>
              <a-menu-item key="legacy">{{ $t('Scale_Preset_Legacy') }}</a-menu-item>
            </a-menu>
          </template>
        </a-dropdown>

        <!-- Test box: decodes with the formats as currently edited (unsaved) -->
        <a-card size="small" style="margin-bottom: 16px">
          <template #title>
            {{ $t('Scale_Test_Barcode') }}
            <span class="card-sub">{{ $t('Scale_Test_Barcode_Sub') }}</span>
          </template>
          <a-form-item :label="$t('Scale_Sample_Barcode')" style="margin-bottom: 12px">
            <a-space wrap>
              <a-input
                v-model:value="sample" :maxlength="20" inputmode="numeric" allow-clear
                :placeholder="placeholderSample" style="width: 240px; font-family: monospace"
              />
              <a-button :disabled="!usableFormats.length" @click="tryExample">{{ $t('Scale_Try_Example') }}</a-button>
            </a-space>
          </a-form-item>

          <template v-if="sample">
            <a-alert v-if="!/^\d+$/.test(sample)" type="warning" show-icon :message="$t('Scale_Test_Digits_Only')" />
            <a-alert v-else-if="!testResults.length" type="info" show-icon :message="$t('Scale_Test_No_Match')" />
            <div v-for="(r, ri) in testResults" :key="ri" class="test-result">
              <div class="layout-strip">
                <span v-for="(seg, si) in segmentsOf(r.format, sample)" :key="si" class="seg" :class="'seg-' + seg.kind">
                  <span class="seg-digits">{{ seg.text }}</span>
                  <span class="seg-label">{{ $t(seg.label) }}</span>
                </span>
              </div>
              <a-descriptions size="small" :column="{ xs: 1, sm: 2, md: 4 }" bordered>
                <a-descriptions-item :label="$t('Scale_Format')">{{ r.format.name || $t('Scale_Format_N', { n: r.n }) }}</a-descriptions-item>
                <a-descriptions-item :label="$t('Scale_Item_Code')">
                  <span style="font-family: monospace">{{ r.plu }}</span>
                </a-descriptions-item>
                <a-descriptions-item :label="r.format.value_type === 'price' ? $t('Scale_Value_Price') : $t('Scale_Value_Weight')">
                  {{ r.format.value_type === 'price' ? r.value.toFixed(r.format.value_decimals) : r.value.toFixed(r.format.value_decimals) + ' kg' }}
                </a-descriptions-item>
                <a-descriptions-item :label="$t('Scale_Check_Digit')">
                  <a-tag v-if="r.checkOk" color="success">{{ $t('Scale_Check_Ok') }}</a-tag>
                  <a-tag v-else :color="r.format.check_digit ? 'error' : 'default'">
                    {{ r.format.check_digit ? $t('Scale_Check_Failed') : $t('Scale_Check_Not_Checked') }}
                  </a-tag>
                </a-descriptions-item>
              </a-descriptions>
              <div class="hint">
                {{ r.format.value_type === 'price' ? $t('Scale_Test_Price_Hint') : $t('Scale_Test_Weight_Hint') }}
              </div>
            </div>
          </template>
        </a-card>

      <a-button type="primary" size="large" :loading="saving" :disabled="!!blockingProblem" @click="save">{{ $t('submit') }}</a-button>
      <div v-if="blockingProblem" class="hint" style="margin-top: 8px; color: var(--ant-color-error, #ff4d4f)">{{ blockingProblem }}</div>
    </a-form>
  </div>
</template>

<script setup>
/**
 * System Settings > Weighing scale. GET get_pos_Settings_api → pos_settings
 * (scale_barcode_enabled, scale_barcode_formats); PUT pos_settings/{id} with
 * those two fields only. The server cleans the formats
 * (App\Services\Pos\ScaleBarcode); the POS, sale form and transfer form read
 * them through lib/scaleBarcode.js.
 */
import { ref, computed, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { ArrowUpOutlined, ArrowDownOutlined, DeleteOutlined, PlusOutlined, DownOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';
import {
  LEGACY_FORMAT, DEFAULT_FORMAT, MAX_FORMATS, MIN_LENGTH, MAX_LENGTH,
  cleanFormat, formatLength, formatProblem, decodeScaleBarcode, exampleBarcode,
} from '../../lib/scaleBarcode';

const { t } = useI18n();

const PRESETS = {
  weight: DEFAULT_FORMAT,
  // Price label: 2 + 5-digit item code + price check digit + 5-digit price + check (13).
  price: { ...DEFAULT_FORMAT, plu_digits: 5, skip_digits: 1, value_digits: 5, value_decimals: 2, value_type: 'price' },
  legacy: LEGACY_FORMAT,
};

const loading = ref(true);
const saving = ref(false);
const settingsId = ref(null);
// False until the tenant migration adds the scale columns (the GET payload
// has no scale keys before that, and the server would skip the save).
const ready = ref(false);
const enabled = ref(false);
const formats = ref([]);
const sample = ref('');

let keySeq = 0;
const withKey = f => ({ ...cleanFormat(f), _key: ++keySeq });

function add(preset) {
  if (formats.value.length >= MAX_FORMATS) return;
  formats.value.push(withKey({ ...(PRESETS[preset] || DEFAULT_FORMAT), name: '' }));
}

function remove(i) {
  formats.value.splice(i, 1);
}

// Order matters only between formats whose prefixes overlap — the longer
// prefix is always tried first — but the list order is what the user sees.
function move(i, dir) {
  const j = i + dir;
  if (j < 0 || j >= formats.value.length) return;
  const list = formats.value;
  [list[i], list[j]] = [list[j], list[i]];
}

/** Formats as the server will store them (inputs coerced, helper keys dropped). */
const cleaned = computed(() => formats.value.map(f => cleanFormat(f)));

const NUMBER_FIELDS = ['plu_digits', 'skip_digits', 'value_digits', 'value_decimals'];

function problemOf(f, i) {
  // A cleared number box would otherwise be saved as a default the user never saw.
  if (NUMBER_FIELDS.some(k => f[k] === null || f[k] === '' || f[k] === undefined)) return t('Scale_Err_Empty');
  const c = cleaned.value[i];
  const key = formatProblem(c);
  if (key) return t(key, { n: formatLength(c), min: MIN_LENGTH, max: MAX_LENGTH });
  const twin = cleaned.value.findIndex((o, oi) => oi < i && o.prefix === c.prefix && formatLength(o) === formatLength(c));
  if (twin !== -1) return t('Scale_Err_Duplicate', { n: twin + 1 });
  return '';
}

// The server validates every format, switched on or not, so problems block
// the save either way (the cards stay visible to fix them).
const blockingProblem = computed(() => {
  if (!ready.value || !settingsId.value) return t('Scale_Not_Ready');
  if (enabled.value && !formats.value.length) return t('Scale_Need_One_Format');
  return formats.value.some((f, i) => problemOf(f, i)) ? t('Scale_Fix_Formats') : '';
});

/** Formats the test box can use, each with its card number. */
const usable = computed(() => cleaned.value
  .map((f, i) => ({ f, n: i + 1 }))
  .filter(({ n }) => !problemOf(formats.value[n - 1], n - 1)));
const usableFormats = computed(() => usable.value.map(u => u.f));

/** Split a label into coloured parts for the layout strip. */
function segmentsOf(fmt, code) {
  const f = cleanFormat(fmt);
  const parts = [
    { kind: 'prefix', len: f.prefix.length, label: 'Scale_Seg_Prefix' },
    { kind: 'plu', len: f.plu_digits, label: 'Scale_Seg_Item' },
    { kind: 'skip', len: f.skip_digits, label: 'Scale_Seg_Skip' },
    { kind: 'value', len: f.value_digits, label: f.value_type === 'price' ? 'Scale_Seg_Price' : 'Scale_Seg_Weight' },
    { kind: 'check', len: 1, label: 'Scale_Seg_Check' },
  ];
  const out = [];
  let pos = 0;
  parts.forEach(p => {
    if (p.len <= 0) return;
    out.push({ kind: p.kind, label: p.label, text: String(code).substr(pos, p.len).padEnd(p.len, '·') });
    pos += p.len;
  });
  return out;
}

const testResults = computed(() => decodeScaleBarcode(sample.value, usableFormats.value)
  .map(r => ({ ...r, n: usable.value[r.index].n })));
const placeholderSample = computed(() => (usableFormats.value[0] ? exampleBarcode(usableFormats.value[0]) : exampleBarcode(DEFAULT_FORMAT)));

// Cycles through the formats so each one's example can be tried.
let exampleTurn = 0;
function tryExample() {
  const list = usableFormats.value;
  if (!list.length) return;
  sample.value = exampleBarcode(list[exampleTurn % list.length]);
  exampleTurn += 1;
}

async function save() {
  if (blockingProblem.value) return;
  saving.value = true;
  try {
    await http.put(`pos_settings/${settingsId.value}`, {
      scale_barcode_enabled: enabled.value ? 1 : 0,
      scale_barcode_formats: cleaned.value,
    });
    message.success(t('Successfully_Updated'));
  } catch (e) {
    const errors = e?.data?.errors;
    message.error(errors ? String(Object.values(errors).flat()[0]) : (e?.data?.message || t('InvalidData')));
  } finally {
    saving.value = false;
  }
}

onMounted(async () => {
  try {
    const data = await http.get('get_pos_Settings_api');
    const ps = data.pos_settings || {};
    settingsId.value = ps.id || null;
    ready.value = 'scale_barcode_enabled' in ps;
    enabled.value = ps.scale_barcode_enabled === true || Number(ps.scale_barcode_enabled) === 1;
    let stored = ps.scale_barcode_formats;
    if (typeof stored === 'string') {
      try { stored = JSON.parse(stored); } catch (e) { stored = []; }
    }
    formats.value = (Array.isArray(stored) ? stored : []).map(withKey);
    // First visit: start from the usual EAN-13 weight label.
    if (!formats.value.length) add('weight');
  } catch (e) {
    message.error(t('InvalidData'));
  } finally {
    loading.value = false;
  }
});
</script>

<style scoped>
.card-sub {
  margin-inline-start: 8px;
  font-weight: 400;
  font-size: 12px;
  color: rgba(var(--ink-rgb), 0.45);
}
.format-no {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 20px;
  height: 20px;
  margin-inline-end: 6px;
  border-radius: 50%;
  font-size: 12px;
  background: rgba(22, 119, 255, 0.12);
  color: #1677ff;
}
.group-title {
  margin: 4px 0 8px;
  font-weight: 600;
  font-size: 13px;
}
.hint {
  margin-top: 6px;
  font-size: 12px;
  color: rgba(var(--ink-rgb), 0.55);
}
.test-result + .test-result {
  margin-top: 16px;
}
.layout-strip {
  display: flex;
  flex-wrap: wrap;
  gap: 4px;
  margin-bottom: 12px;
}
.seg {
  display: inline-flex;
  flex-direction: column;
  align-items: center;
  padding: 4px 8px;
  border-radius: 6px;
}
.seg-digits {
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
  font-size: 15px;
  letter-spacing: 2px;
}
.seg-label {
  font-size: 10px;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  opacity: 0.75;
}
.seg-prefix { background: rgba(22, 119, 255, 0.12); }
.seg-plu { background: rgba(82, 196, 26, 0.14); }
.seg-skip { background: rgba(140, 140, 140, 0.16); }
.seg-value { background: rgba(250, 140, 22, 0.16); }
.seg-check { background: rgba(114, 46, 209, 0.13); }
</style>
