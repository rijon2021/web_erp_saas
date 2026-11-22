<template>
  <div>
    <div v-for="c in components" :key="c.product_id" class="combo-serial-row">
      <div class="combo-serial-label">{{ c.name }}<span v-if="c.code" class="muted"> ({{ c.code }})</span></div>
      <a-spin :spinning="!!loading[c.product_id]">
        <a-select
          :value="groupOf(c.product_id)"
          mode="multiple"
          style="width: 100%"
          :placeholder="$t('Select_Serials')"
          :options="(available[c.product_id] || []).map(s => ({ value: s.serial_number, label: s.serial_number }))"
          :max-tag-count="6"
          show-search
          @change="values => onChange(c.product_id, values)"
        />
        <div class="muted" style="margin-top: 4px">
          {{ groupOf(c.product_id).length }} / {{ requiredFor(c) }}
          <span v-if="!loading[c.product_id] && !(available[c.product_id] || []).length" style="color: #faad14; margin-left: 8px">
            {{ $t('No_Serials_Available') }}
          </span>
        </div>
      </a-spin>
    </div>
  </div>
</template>

<script setup>
/**
 * Serial picker for a combo line: one picker per serial-tracked component
 * (line.serial_components = [{product_id, name, code, per}]), each needing
 * per × line quantity serials. Selections live per component in
 * line.serial_groups and are flattened into line.serial_numbers /
 * line.imei_number — the backend resolves each serial to its component.
 *
 * Candidates come from fetchUrl with baseParams + the component's product_id
 * (sale: in-stock at the warehouse; return: sold on that combo line).
 */
import { reactive, computed, watch, onMounted } from 'vue';
import http from '../lib/http';
import { comboSerialRequired, setComboSerialGroup } from '../lib/comboSerials';
import { lineSerialUnits, loadUnitFactors } from '../lib/serialUnits';

const props = defineProps({
  line: { type: Object, required: true },
  warehouseId: { type: [Number, String], default: null },
  fetchUrl: { type: String, default: 'serial_numbers/available' },
  // Explicit params shared by every component; default is { warehouse_id }.
  baseParams: { type: Object, default: null },
  // Units assembled into kits (serial_numbers/available only): 'include' when
  // selling a combo, 'exclude' for kit assembly, 'only' for disassembly.
  kitUnits: { type: String, default: 'include' },
});

const available = reactive({});
const loading = reactive({});

const components = computed(() => props.line.serial_components || []);

const params = computed(() => {
  if (props.baseParams) return props.baseParams;
  return props.warehouseId ? { warehouse_id: props.warehouseId } : null;
});

const groupOf = productId => (props.line.serial_groups || {})[productId] || [];
const requiredFor = c => comboSerialRequired(c, lineSerialUnits(props.line));

function onChange(productId, values) {
  setComboSerialGroup(props.line, productId, values);
}

async function load() {
  for (const c of components.value) {
    if (!params.value) {
      available[c.product_id] = [];
      continue;
    }
    loading[c.product_id] = true;
    const query = { ...params.value, product_id: c.product_id };
    if (props.fetchUrl === 'serial_numbers/available') {
      if (props.kitUnits === 'include') query.for_combo = 1;
      if (props.kitUnits === 'only') query.in_kit = 1;
    }
    http.get(props.fetchUrl, query)
      .then(data => { available[c.product_id] = data?.serials || []; })
      .catch(() => { available[c.product_id] = []; })
      .finally(() => { loading[c.product_id] = false; });
  }
}

// Keyed on the serialized params, like SerialPicker.
watch(() => JSON.stringify([params.value, props.kitUnits, components.value.map(c => c.product_id)]), load);
onMounted(() => {
  loadUnitFactors();
  load();
});
</script>

<style scoped>
.combo-serial-row + .combo-serial-row {
  margin-top: 10px;
}
.combo-serial-label {
  font-size: 12px;
  font-weight: 600;
  margin-bottom: 4px;
}
.muted {
  color: rgba(var(--ink-rgb), 0.45);
  font-size: 12px;
}
</style>
