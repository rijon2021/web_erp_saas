<template>
  <a-card
    ref="cardRef"
    :body-style="{ padding: 0 }" :style="cardVars"
    :class="{ 'dt-th-bg': !!dt.headerBg, 'dt-th-color': !!dt.headerColor, 'dt-windowed': windowed }"
  >
    <div v-if="toolbarVisible" class="datatable-toolbar">
      <a-input-search
        v-if="searchable && dt.showSearch"
        :value="crud.search.value"
        :placeholder="$t('Search_this_table')"
        allow-clear
        style="max-width: 280px"
        @update:value="v => (crud.search.value = v)"
        @search="crud.reload()"
      />
      <span v-else></span>
      <a-space>
        <!-- The header checkbox only knows the rows currently in the DOM; in
             windowed mode it is hidden and this one selects the whole page. -->
        <a-checkbox
          v-if="selectable && windowed"
          :checked="allSelected"
          :indeterminate="!allSelected && crud.selectedIds.value.length > 0"
          @change="e => selectAll(e.target.checked)"
        >
          {{ $t('Select_All') }} ({{ crud.rows.value.length }})
        </a-checkbox>
        <a-button
          v-if="selectable && bulkDelete && crud.selectedIds.value.length"
          danger
          @click="crud.removeSelected()"
        >
          <template #icon><DeleteOutlined /></template>
          {{ $t('Del') }} ({{ crud.selectedIds.value.length }})
        </a-button>
        <slot name="toolbar" />

        <!-- Column visibility -->
        <a-popover v-if="dt.showColumns" trigger="click" placement="bottomRight" overlay-class-name="dt-cols-pop">
          <template #content>
            <div class="col-toggle-list">
              <a-checkbox
                v-for="c in toggleableColumns"
                :key="colKey(c)"
                :checked="!hiddenKeys.includes(colKey(c))"
                @change="toggleColumn(colKey(c))"
              >
                {{ c.title }}
              </a-checkbox>
            </div>
          </template>
          <a-tooltip :title="$t('Columns') || 'Columns'" placement="left">
            <a-button>
              <template #icon><SettingOutlined /></template>
            </a-button>
          </a-tooltip>
        </a-popover>

        <!-- Refresh -->
        <a-tooltip v-if="dt.showRefresh" :title="$t('Refresh')">
          <a-button @click="crud.fetchRows()">
            <template #icon><ReloadOutlined :spin="crud.loading.value" /></template>
          </a-button>
        </a-tooltip>
      </a-space>
    </div>

    <a-table
      :columns="tableColumns"
      :data-source="visibleRows"
      :loading="crud.loading.value"
      :pagination="crud.pagination.value"
      :row-selection="selectable ? rowSelection : undefined"
      :row-key="rowKey"
      :scroll="tableScroll"
      :table-layout="frozenColumns ? 'fixed' : undefined"
      :size="dt.density"
      :bordered="dt.bordered"
      :show-sorter-tooltip="false"
      :row-class-name="dt.striped ? stripedClass : undefined"
      @change="onTableChange"
    >
      <!-- Forward cell rendering to the page. -->
      <template #bodyCell="slotProps">
        <slot name="bodyCell" v-bind="slotProps" />
      </template>
      <template #emptyText>
        <a-empty :description="$t('NodataAvailable')" style="padding: 32px 0" />
      </template>
      <!-- Totals row (legacy rendered this as a bottom "group header").
           Pages either supply a custom #summary slot, or simply flag columns
           with `sum: true` (plain number) / `sum: 'money'` (currency) and get
           this auto-generated page-total footer. -->
      <template v-if="$slots.summary || hasAutoSummary" #summary>
        <a-table-summary :fixed="windowed">
        <slot name="summary">
          <a-table-summary-row>
            <a-table-summary-cell v-if="selectable" :index="0" />
            <a-table-summary-cell
              v-for="(col, i) in visibleColumns"
              :key="colKey(col) || i"
              :index="i + (selectable ? 1 : 0)"
              :align="col.align"
            >
              <b v-if="col.sum">{{ sumFor(col) }}</b>
              <b v-else-if="i === totalLabelIndex">{{ $t('Total') }}</b>
            </a-table-summary-cell>
          </a-table-summary-row>
        </slot>
        </a-table-summary>
      </template>
    </a-table>
  </a-card>
</template>

<script setup>
/**
 * Thin wrapper binding an a-table to a useCrudTable() instance: server-side
 * pagination, sorting, search and bulk delete wired once. Pages supply columns
 * + a bodyCell slot and nothing else.
 *
 * Display preferences (font, size, density, borders, stripes and which toolbar
 * components show) come from the datatable store — customized under
 * System Settings > DataTable and persisted per device.
 */
import { ref, computed, watch, nextTick, onMounted, onBeforeUnmount } from 'vue';
import { DeleteOutlined, SettingOutlined, ReloadOutlined } from '@ant-design/icons-vue';
import { useDatatableStore } from '../stores/datatable';
import { useFormat } from '../composables/useFormat';

const props = defineProps({
  crud: { type: Object, required: true },
  columns: { type: Array, required: true },
  rowKey: { type: String, default: 'id' },
  searchable: { type: Boolean, default: true },
  // Row checkboxes + bulk delete (endpoint must support /delete/by_selection).
  selectable: { type: Boolean, default: false },
  // Checkboxes can also serve other bulk actions (toolbar slot); this keeps
  // the Delete button to users who may delete.
  bulkDelete: { type: Boolean, default: true },
});

const dt = useDatatableStore();

const toolbarVisible = computed(() =>
  (props.searchable && dt.showSearch) || props.selectable || dt.showColumns || dt.showRefresh
);

// tbody/thead styling via CSS vars, so 'default' cleanly inherits Ant's stack.
// Header colors are only emitted when set — the theme's own header colors
// (light AND dark) stay untouched otherwise.
const cardVars = computed(() => ({
  '--dt-font': dt.fontStack || 'inherit',
  '--dt-font-size': `${dt.fontSize}px`,
  '--dt-th-weight': dt.headerWeight || '600',
  '--dt-th-size': `${dt.headerFontSize || 14}px`,
  '--dt-th-transform': dt.headerUppercase ? 'uppercase' : 'none',
  ...(dt.headerBg ? { '--dt-th-bg': dt.headerBg } : {}),
  ...(dt.headerColor ? { '--dt-th-color': dt.headerColor } : {}),
  ...spacerVars.value,
}));

const stripedClass = (_record, index) => ((index + windowStart.value) % 2 === 1 ? 'dt-row-striped' : '');

/* ------------------------------------------------ column visibility */
const colKey = c => c.key ?? c.dataIndex;

// Columns flagged `defaultHidden: true` start unchecked in the picker; the
// user can turn them on from the columns dropdown for the session.
const hiddenKeys = ref(props.columns.filter(c => c.defaultHidden).map(colKey));

// The actions column stays out of the picker — hiding it strands the row menu.
const toggleableColumns = computed(() => props.columns.filter(c => colKey(c) !== 'actions'));

/* ------------------------------------------------ sorting
 * Global "Sortable columns" pref (System Settings > DataTable):
 * - off: strip every sorter so no column shows sort arrows;
 * - on:  columns that declared `sorter: true` keep server-side sorting, and
 *   every other data column gets a client-side comparator over the loaded
 *   page. Client-side because most of those fields (joined names, computed
 *   quantities…) are not real DB columns — sending them as SortField would
 *   make the backend's orderBy() throw. useCrudTable recognizes function
 *   sorters and keeps them out of the server params. */
function localSorter(key) {
  return (a, b) => {
    const av = a?.[key], bv = b?.[key];
    const an = Number(av), bn = Number(bv);
    if (av !== '' && bv !== '' && Number.isFinite(an) && Number.isFinite(bn)) return an - bn;
    // numeric collation so "12.00 pcs" > "9.00 pcs" and dates stay chronological
    return String(av ?? '').localeCompare(String(bv ?? ''), undefined, { numeric: true, sensitivity: 'base' });
  };
}

const sortedColumns = computed(() => {
  if (!dt.autoSort) return props.columns.map(c => (c.sorter ? { ...c, sorter: undefined } : c));
  return props.columns.map(c => {
    if (c.sorter || c.sorter === false || !c.dataIndex || colKey(c) === 'actions') return c;
    return { ...c, sorter: localSorter(c.dataIndex) };
  });
});

const visibleColumns = computed(() => sortedColumns.value.filter(c => !hiddenKeys.value.includes(colKey(c))));

// The auto-summary "Total" label goes on the first data column, in case a page
// leads with its actions column.
const totalLabelIndex = computed(() => visibleColumns.value.findIndex(c => colKey(c) !== 'actions'));

function toggleColumn(key) {
  const i = hiddenKeys.value.indexOf(key);
  if (i >= 0) hiddenKeys.value.splice(i, 1);
  else hiddenKeys.value.push(key);
}

/* ------------------------------------------------ row windowing
 * a-table (antd-vue 4) renders a DOM row for every record it is given, so the
 * "All" page size on a large catalogue (thousands of rows x a dozen cells,
 * each with an <img>) used to exhaust the tab. Above WINDOW_MIN_ROWS the
 * body becomes its own scroller (scroll.y) and only the rows near the
 * viewport are handed to a-table; transparent borders on the inner <table>
 * stand in for the rows above and below so the scrollbar still spans the
 * whole page. Row heights are measured as they render (variant products are
 * taller); unmeasured rows use the average of the first rows shown. */
const WINDOW_MIN_ROWS = 150;
const OVERSCAN = 8;
const DEFAULT_ROW_H = 47;

const cardRef = ref(null);
const windowed = computed(() => props.crud.rows.value.length > WINDOW_MIN_ROWS);
const frozenColumns = ref(null); // { widths: px per visible column, selection: px, total: px }
const sizingRows = ref(null);
const tableScroll = computed(() => {
  if (!windowed.value) return { x: 'max-content' };
  return { x: frozenColumns.value?.total || 'max-content', y: 'calc(100vh - 290px)' };
});

// Client-side (function) sorters are applied by a-table to its data-source -
// only the slice, in windowed mode. Track the active one and order the full
// page ourselves before slicing; a-table re-sorting the slice is a no-op then.
const localSort = ref(null);
function onTableChange(pag, filters, sorter) {
  const fn = sorter?.column?.sorter;
  localSort.value = typeof fn === 'function' && sorter.order ? { fn, order: sorter.order } : null;
  return props.crud.onChange(pag, filters, sorter);
}
const orderedRows = computed(() => {
  const rows = props.crud.rows.value;
  if (!windowed.value || !localSort.value) return rows;
  const { fn, order } = localSort.value;
  return [...rows].sort((a, b) => (order === 'ascend' ? fn(a, b) : -fn(a, b)));
});

const scrollTop = ref(0);
const viewportH = ref(600);
const heights = ref(new Map()); // String(row key) -> measured px
const avgRowH = ref(DEFAULT_ROW_H);
// One estimate per data set: re-averaging while scrolling would move every
// unmeasured row above the viewport at once.
let avgLocked = false;

const rowHeight = key => heights.value.get(String(key)) ?? avgRowH.value;

// Prefix offsets over the ordered page: offsets[i] = top of row i.
const offsets = computed(() => {
  const rows = orderedRows.value;
  const out = new Array(rows.length + 1);
  let acc = 0;
  for (let i = 0; i < rows.length; i++) {
    out[i] = acc;
    acc += rowHeight(rows[i]?.[props.rowKey]);
  }
  out[rows.length] = acc;
  return out;
});

function indexAt(y) {
  const o = offsets.value;
  let lo = 0, hi = Math.max(o.length - 2, 0);
  while (lo < hi) {
    const mid = (lo + hi + 1) >> 1;
    if (o[mid] <= y) lo = mid; else hi = mid - 1;
  }
  return lo;
}

const windowStart = computed(() =>
  windowed.value ? Math.max(indexAt(scrollTop.value) - OVERSCAN, 0) : 0
);
const windowEnd = computed(() => {
  const n = orderedRows.value.length;
  if (!windowed.value) return n;
  return Math.min(indexAt(scrollTop.value + viewportH.value) + 1 + OVERSCAN, n);
});
const visibleRows = computed(() => {
  if (sizingRows.value) return sizingRows.value;
  return windowed.value ? orderedRows.value.slice(windowStart.value, windowEnd.value) : orderedRows.value;
});
const spacerVars = computed(() => {
  if (!windowed.value) return {};
  const o = offsets.value;
  return {
    '--dt-top': `${o[windowStart.value] || 0}px`,
    '--dt-bottom': `${Math.max((o[o.length - 1] || 0) - (o[windowEnd.value] || 0), 0)}px`,
  };
});

const bodyEl = () => cardRef.value?.$el?.querySelector?.('.ant-table-body') || null;

// Scroll events don't bubble, but they can be captured from the card root -
// that survives antd recreating the body element when scroll.y toggles.
function onAnyScroll(e) {
  if (!windowed.value || !e.target?.classList?.contains('ant-table-body')) return;
  scrollTop.value = e.target.scrollTop;
}

// Measure rendered rows. A row above the viewport that turns out taller or
// shorter than its estimate would push the visible rows, so the scroll position
// absorbs the difference.
function measureRows() {
  const body = bodyEl();
  if (!body || !windowed.value || sizingRows.value) return;
  if (body.clientHeight) viewportH.value = body.clientHeight;
  const viewTop = body.getBoundingClientRect().top;
  let changed = false, shift = 0, sum = 0, n = 0;
  body.querySelectorAll('tbody > tr[data-row-key]').forEach(tr => {
    const rect = tr.getBoundingClientRect();
    if (!rect.height) return;
    const key = tr.getAttribute('data-row-key');
    const known = heights.value.get(key);
    if (known === undefined || Math.abs(known - rect.height) > 0.01) {
      if (rect.bottom <= viewTop) shift += rect.height - (known ?? avgRowH.value);
      heights.value.set(key, rect.height);
      changed = true;
    }
    sum += rect.height; n++;
  });
  if (n && !avgLocked) { avgRowH.value = sum / n; avgLocked = true; changed = true; }
  if (changed) heights.value = new Map(heights.value); // trigger offsets
  if (Math.abs(shift) >= 0.5) {
    body.scrollTop += shift;
    scrollTop.value = body.scrollTop;
  }
}

/* Column widths: with auto layout a-table sizes columns from the rows in the
 * DOM, so they would jump as the window moves. On entering windowed mode one
 * render holds the row with the longest value of each column, those widths are
 * measured, and the table stays on fixed layout at them. Both renders land in
 * the same task, so the sizing rows are never painted. */
const colSizingKey = computed(() => [
  visibleColumns.value.map(colKey).join('|'), dt.fontStack, dt.fontSize, dt.density, dt.bordered,
  dt.headerWeight, dt.headerFontSize, dt.headerUppercase,
].join('~'));

function longestLine(value) {
  if (value === null || value === undefined) return 0;
  const text = String(value);
  return text.includes('\n') ? Math.max(...text.split('\n').map(l => l.length)) : text.length;
}

function widestRows() {
  const rows = props.crud.rows.value;
  const picked = new Set();
  visibleColumns.value.forEach(col => {
    if (!col.dataIndex) return;
    let best = null, bestLen = -1;
    for (const row of rows) {
      const len = longestLine(row?.[col.dataIndex]);
      if (len > bestLen) { best = row; bestLen = len; }
    }
    if (best) picked.add(best);
  });
  return picked.size ? [...picked] : rows.slice(0, 1);
}

// Header titles wrap inside the body's column width, so they are measured
// separately or a short-valued column would squeeze its own title.
const titleCanvas = typeof document !== 'undefined' ? document.createElement('canvas').getContext('2d') : null;
function titleWidth(col, th) {
  if (!th || !titleCanvas || col.title === undefined) return 0;
  const cs = getComputedStyle(th);
  titleCanvas.font = `${cs.fontWeight} ${cs.fontSize} ${cs.fontFamily}`;
  const title = String(col.title);
  const text = cs.textTransform === 'uppercase' ? title.toUpperCase() : title;
  const sorter = th.querySelector('.ant-table-column-sorter') ? 26 : 0;
  return Math.ceil(titleCanvas.measureText(text).width) + parseFloat(cs.paddingLeft) + parseFloat(cs.paddingRight) + sorter + 2;
}

let sizingRun = 0;
async function sizeColumns() {
  const run = ++sizingRun;
  frozenColumns.value = null;
  sizingRows.value = windowed.value ? widestRows() : null;
  if (!windowed.value) return;
  await nextTick();
  if (run !== sizingRun) return;
  const offset = props.selectable ? 1 : 0;
  const cells = bodyEl()?.querySelectorAll('tbody > tr[data-row-key]') || [];
  const ths = [...(cardRef.value?.$el?.querySelectorAll('.ant-table-header thead th') || [])];
  const count = visibleColumns.value.length + offset;
  const widths = new Array(count).fill(0);
  let complete = cells.length > 0;
  cells.forEach(tr => {
    if (tr.children.length !== count) { complete = false; return; }
    [...tr.children].forEach((td, i) => { widths[i] = Math.max(widths[i], Math.ceil(td.getBoundingClientRect().width)); });
  });
  sizingRows.value = null;
  if (!complete) return; // unusual markup (spanned cells): keep auto layout
  visibleColumns.value.forEach((col, i) => {
    widths[i + offset] = Math.max(widths[i + offset], titleWidth(col, ths[i + offset]));
  });
  frozenColumns.value = {
    selection: offset ? widths[0] : 0,
    widths: widths.slice(offset),
    total: widths.reduce((a, b) => a + b, 0),
  };
  await nextTick();
  if (run === sizingRun) measureRows();
}

const tableColumns = computed(() => {
  const frozen = frozenColumns.value;
  if (!frozen) return visibleColumns.value;
  return visibleColumns.value.map((col, i) => ({ ...col, width: frozen.widths[i] }));
});

let ro = null;
function observeBody() {
  ro?.disconnect();
  ro = null;
  const body = bodyEl();
  if (!body || typeof ResizeObserver === 'undefined') return;
  ro = new ResizeObserver(() => measureRows());
  ro.observe(body);
  const inner = body.querySelector('table');
  if (inner) ro.observe(inner);
}

onMounted(() => {
  cardRef.value?.$el?.addEventListener('scroll', onAnyScroll, true);
  if (windowed.value) nextTick(observeBody);
});
onBeforeUnmount(() => {
  cardRef.value?.$el?.removeEventListener('scroll', onAnyScroll, true);
  ro?.disconnect();
});

// New rows (page/sort/search) start from the top; a fresh data set drops the
// per-row measurements but keeps the learned average.
watch(() => props.crud.rows.value, () => {
  heights.value = new Map();
  avgLocked = false;
  scrollTop.value = 0;
  const body = bodyEl();
  if (body) body.scrollTop = 0;
  nextTick(() => { observeBody(); measureRows(); });
});
watch([() => props.crud.rows.value, colSizingKey], sizeColumns);
watch(windowed, on => { if (!on) { ro?.disconnect(); ro = null; } else nextTick(observeBody); });
watch(visibleRows, () => nextTick(measureRows), { flush: 'post' });

/* ------------------------------------------------ selection
 * The header "select all" checkbox only covers a-table's data-source - the
 * rendered slice in windowed mode - so it is hidden there in favour of the
 * toolbar checkbox, which covers every loaded row. */
// preserveSelectedRowKeys: antd otherwise prunes keys missing from its
// data-source on every change, i.e. anything scrolled out of the slice.
const rowSelection = computed(() => {
  const base = props.crud.rowSelection.value;
  if (!windowed.value) return base;
  return {
    ...base,
    hideSelectAll: true,
    preserveSelectedRowKeys: true,
    ...(frozenColumns.value ? { columnWidth: frozenColumns.value.selection } : {}),
  };
});
const allSelected = computed(() => {
  const n = props.crud.rows.value.length;
  return n > 0 && props.crud.selectedIds.value.length >= n;
});
function selectAll(on) {
  props.crud.selectedIds.value = on ? props.crud.rows.value.map(r => r[props.rowKey]) : [];
}

/* ------------------------------------------------ auto totals footer */
const { money, number } = useFormat();

// Only for columns still visible — totals follow the column picker.
const hasAutoSummary = computed(
  () => visibleColumns.value.some(c => c.sum) && props.crud.rows.value.length > 0
);

// Page-scoped totals (the loaded rows), like the legacy bottom group-header.
function sumFor(col) {
  const key = col.dataIndex ?? col.key;
  const total = props.crud.rows.value.reduce((acc, r) => acc + (Number(r?.[key]) || 0), 0);
  return col.sum === 'money' ? money(total) : number(total, Number.isInteger(total) ? 0 : 2);
}
</script>

<style scoped>
.datatable-toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  flex-wrap: wrap;
  padding: 16px;
  border-bottom: 1px solid rgba(5, 5, 5, 0.06);
}
.col-toggle-list {
  display: flex;
  flex-direction: column;
  gap: 6px;
  /* Viewport-aware: a fixed cap silently clipped the tail of longer column sets
     (products has 12 toggleable). The reserved gutter keeps the width stable so
     the popover doesn't re-measure and flash while it animates open — that was
     the reason the bar used to be hidden outright. */
  max-height: min(60vh, 460px);
  overflow-y: auto;
  scrollbar-gutter: stable;
  scrollbar-width: thin;
  min-width: 180px;
}
.col-toggle-list::-webkit-scrollbar {
  width: 6px;
}
.col-toggle-list::-webkit-scrollbar-thumb {
  background: rgba(128, 128, 128, 0.35);
  border-radius: 3px;
}
.col-toggle-list :deep(.ant-checkbox-wrapper) {
  margin-inline-start: 0;
}

/* Windowed mode: the rows above/below the rendered slice are stood in for by
   transparent borders on the inner table (borders always count towards the
   scroll height, unlike margins). */
.dt-windowed :deep(.ant-table-body > table) {
  border-top: var(--dt-top, 0px) solid transparent;
  border-bottom: var(--dt-bottom, 0px) solid transparent;
}
/* The browser's scroll anchoring reacts to the spacer changing by moving the
   scroll position, which moves the window, which changes the spacer again: the
   list races away from a single wheel tick. The window owns scrolling here. */
.dt-windowed :deep(.ant-table-body) {
  overflow-anchor: none;
}

/* Body cells follow the user's DataTable preferences (font + size). */
:deep(.ant-table-tbody > tr > td) {
  font-family: var(--dt-font, inherit);
  font-size: var(--dt-font-size, 13px);
}
:deep(.dt-row-striped > td) {
  background: rgba(128, 128, 128, 0.045);
}

/* Header cells: weight/size/case always follow the prefs (defaults match
   Ant's); colors apply only when the user picked one (classes above). */
:deep(.ant-table-thead > tr > th) {
  font-weight: var(--dt-th-weight, 600);
  font-size: var(--dt-th-size, 14px);
  text-transform: var(--dt-th-transform, none);
}
.dt-th-bg :deep(.ant-table-thead > tr > th) {
  background: var(--dt-th-bg);
}
.dt-th-color :deep(.ant-table-thead > tr > th) {
  color: var(--dt-th-color);
}
</style>

<!-- The column-visibility popover is teleported to <body>, outside this
     component's scoped styles — its chrome must be clamped globally so no
     transient scrollbar flashes on .ant-popover-content while it animates.
     Scrolling happens ONLY inside .col-toggle-list. -->
<style>
.dt-cols-pop .ant-popover-content,
.dt-cols-pop .ant-popover-inner,
.dt-cols-pop .ant-popover-inner-content {
  overflow: hidden !important;
}
</style>
