<template>
  <div class="page">
    <PageHeader :title="$t('Crm_Pipeline')" :breadcrumb="[$t('Crm'), $t('Crm_Pipeline')]">
      <template #actions>
        <a-button @click="$router.push('/crm/deals')"><template #icon><UnorderedListOutlined /></template>{{ $t('Crm_List_View') }}</a-button>
        <a-button v-if="canAdd" type="primary" @click="$router.push(`/crm/deals/create?pipeline=${pipelineId || ''}`)">
          <template #icon><PlusOutlined /></template>{{ $t('Crm_New_Deal') }}
        </a-button>
      </template>
    </PageHeader>

    <!-- Toolbar -->
    <div class="toolbar">
      <a-select v-if="meta.pipelines.length > 1" v-model:value="pipelineId" :options="pipelineOptions" style="width: 200px" @change="load" />
      <a-input-search v-model:value="search" :placeholder="$t('Crm_Search_Deals')" allow-clear style="width: 230px" @search="load" />
      <a-select v-model:value="owner" :options="ownerOptions" :placeholder="$t('Crm_Owner')" allow-clear style="width: 180px" @change="load" />
      <a-select v-model:value="tag" :options="tagOptions" :placeholder="$t('Crm_Tag')" allow-clear show-search option-filter-prop="label" style="width: 150px" @change="load" />
      <div class="month">
        <span class="m-item won"><Trophy :size="14" /> {{ board.month?.won || 0 }} · {{ money(board.month?.won_value || 0) }}</span>
        <span class="m-item lost"><XCircle :size="14" /> {{ board.month?.lost || 0 }}</span>
        <span class="m-label">{{ $t('Crm_This_Month') }}</span>
      </div>
    </div>

    <a-spin :spinning="loading">
      <div v-if="board.stages.length" class="board" :class="{ dragging: !!drag }">
        <section
          v-for="col in board.stages" :key="col.id" class="col"
          :class="{ over: drag && overCol === col.id }"
          @dragover.prevent="onDragOver($event, col)" @drop.prevent="onDrop(col)"
        >
          <header class="col-head" :style="{ borderTopColor: col.color }">
            <div class="col-title">
              <span class="col-name">{{ col.name }}</span>
              <span class="col-prob">{{ col.probability }}%</span>
            </div>
            <div class="col-meta">
              <span>{{ money(col.value) }}</span>
              <span class="dotsep">·</span>
              <span>{{ col.count }} {{ col.count === 1 ? $t('Crm_Deal_Lower') : $t('Crm_Deals_Lower') }}</span>
            </div>
          </header>

          <div class="cards">
            <template v-for="(d, i) in col.deals" :key="d.id">
              <div v-if="drag && overCol === col.id && dropIndex === i" class="placeholder"></div>
              <article
                class="card" :class="{ ghost: drag && drag.deal.id === d.id, rot: d.is_rotting }"
                :draggable="canEdit" @dragstart="onDragStart($event, d, col)" @dragend="onDragEnd"
                @click="open(d)"
              >
                <div class="card-top">
                  <span class="card-title">{{ d.title }}</span>
                  <a-dropdown v-if="canEdit" :trigger="['click']" @click.stop>
                    <a-button type="text" size="small" class="card-menu" @click.stop><template #icon><MoreOutlined /></template></a-button>
                    <template #overlay>
                      <a-menu @click="({ key }) => cardAction(key, d, col)">
                        <a-sub-menu key="move" :title="$t('Crm_Move_To')">
                          <a-menu-item v-for="s in board.stages" :key="`stage:${s.id}`" :disabled="s.id === col.id">{{ s.name }}</a-menu-item>
                        </a-sub-menu>
                        <a-menu-item key="won"><Trophy :size="13" class="mi won" /> {{ $t('Crm_Mark_Won') }}</a-menu-item>
                        <a-menu-item key="lost"><XCircle :size="13" class="mi lost" /> {{ $t('Crm_Mark_Lost') }}</a-menu-item>
                      </a-menu>
                    </template>
                  </a-dropdown>
                </div>
                <div class="card-who">{{ d.contact || '—' }}<template v-if="d.company && d.company !== d.contact"> · {{ d.company }}</template></div>
                <div class="card-tags" v-if="d.tags.length">
                  <span v-for="tg in d.tags.slice(0, 2)" :key="tg.id" class="tag" :style="{ color: tg.color, background: tint(tg.color) }">{{ tg.name }}</span>
                </div>
                <div class="card-next" :class="{ none: !d.next_activity, late: d.next_activity?.is_overdue }">
                  <template v-if="d.next_activity"><component :is="actIcon(d.next_activity.type)" :size="12" /> {{ dueLabel(d.next_activity.due_at, date) }} · {{ d.next_activity.subject }}</template>
                  <template v-else><TriangleAlert :size="12" /> {{ $t('Crm_No_Next_Step_Short') }}</template>
                </div>
                <div class="card-foot">
                  <span class="card-value">{{ money(d.value) }}</span>
                  <a-tooltip v-if="d.is_rotting" :title="$t('Crm_Rotting_Hint')"><Hourglass :size="13" class="rot-ic" /></a-tooltip>
                  <span v-if="d.expected_close_date" class="card-date" :class="{ late: d.is_overdue }">
                    <CalendarClock :size="12" /> {{ date(d.expected_close_date) }}
                  </span>
                  <a-tooltip v-if="d.owner" :title="d.owner.name">
                    <a-avatar :size="22" :style="{ background: avatarColor(d.owner.name), fontSize: '10px' }">{{ initials(d.owner.name) }}</a-avatar>
                  </a-tooltip>
                  <a-tooltip v-else :title="$t('Crm_Unassigned')"><a-avatar :size="22" class="nobody">?</a-avatar></a-tooltip>
                </div>
              </article>
            </template>
            <div v-if="drag && overCol === col.id && dropIndex >= col.deals.length" class="placeholder"></div>
            <button v-if="canAdd && !drag" type="button" class="add" @click="$router.push(`/crm/deals/create?stage=${col.id}`)">
              <PlusOutlined /> {{ $t('Crm_Add_Deal') }}
            </button>
          </div>
        </section>
      </div>
      <a-empty v-else-if="!loading" :description="$t('Crm_No_Pipeline')" />

      <!-- Won / lost drop zones while dragging -->
      <div v-if="drag" class="dropzones">
        <div class="zone won" :class="{ hot: overZone === 'won' }" @dragover.prevent="overZone = 'won'" @dragleave="overZone = ''" @drop.prevent="dropZone('won')">
          <Trophy :size="18" /> {{ $t('Crm_Drop_Won') }}
        </div>
        <div class="zone lost" :class="{ hot: overZone === 'lost' }" @dragover.prevent="overZone = 'lost'" @dragleave="overZone = ''" @drop.prevent="dropZone('lost')">
          <XCircle :size="18" /> {{ $t('Crm_Drop_Lost') }}
        </div>
      </div>
    </a-spin>

    <a-modal v-model:open="lostOpen" :title="$t('Crm_Mark_Lost')" :ok-text="$t('Crm_Mark_Lost')" :cancel-text="$t('Cancel')"
      :ok-button-props="{ danger: true }" :confirm-loading="busy" @ok="doLost">
      <p class="lost-deal">{{ lostDeal?.title }}</p>
      <a-form layout="vertical">
        <a-form-item :label="$t('Crm_Lost_Reason')" required>
          <a-select v-model:value="lostForm.crm_lost_reason_id" :options="reasonOptions" :placeholder="$t('Crm_Choose_Reason')" />
        </a-form-item>
        <a-form-item :label="$t('Crm_Note')" class="mb0">
          <a-textarea v-model:value="lostForm.lost_note" :rows="3" :maxlength="1000" />
        </a-form-item>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * Kanban board of a pipeline — GET crm/deals/board. Cards are dragged with the
 * browser's own drag-and-drop (POST crm/deals/{id}/move); while dragging, two
 * zones appear to drop a deal as won or lost. Each card also has a menu, which
 * is how phones (no HTML5 drag) move deals.
 */
import { ref, computed, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { message } from 'ant-design-vue';
import { PlusOutlined, UnorderedListOutlined, MoreOutlined } from '@ant-design/icons-vue';
import { Trophy, XCircle, Hourglass, CalendarClock, TriangleAlert } from 'lucide-vue-next';
import PageHeader from '../../components/PageHeader.vue';
import { useFormat } from '../../composables/useFormat';
import { useAuthStore } from '../../stores/auth';
import http from '../../lib/http';
import { t } from '../../i18n';
import { initials, avatarColor, tint, ACTIVITY_TYPES, optionOf, dueLabel } from './crmOptions';

function actIcon(type) { return optionOf(ACTIVITY_TYPES, type).icon; }

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();
const { money, date } = useFormat();
const canAdd = computed(() => auth.can('crm_deals_add'));
const canEdit = computed(() => auth.can('crm_deals_edit'));

const meta = ref({ pipelines: [], owners: [], tags: [], lost_reasons: [], can: {} });
const board = ref({ stages: [], month: {} });
const loading = ref(false);
const busy = ref(false);
const pipelineId = ref(route.query.pipeline ? Number(route.query.pipeline) : undefined);
const search = ref('');
const owner = ref(route.query.owner || undefined);
const tag = ref(undefined);

const pipelineOptions = computed(() => meta.value.pipelines.map(p => ({ value: p.id, label: p.name })));
const ownerOptions = computed(() => [
  { value: 'me', label: t('Crm_My_Deals', 'My deals') },
  { value: 'none', label: t('Crm_Unassigned', 'Unassigned') },
  ...(meta.value.can?.view_all ? meta.value.owners.map(o => ({ value: String(o.id), label: o.name })) : []),
]);
const tagOptions = computed(() => meta.value.tags.map(tg => ({ value: tg.id, label: tg.name })));
const reasonOptions = computed(() => meta.value.lost_reasons.map(r => ({ value: r.id, label: r.name })));

async function load() {
  loading.value = true;
  try {
    board.value = await http.get('crm/deals/board', {
      pipeline: pipelineId.value || '', search: search.value || '', owner: owner.value || '', tag: tag.value || '',
    });
    if (board.value.pipeline && !pipelineId.value) pipelineId.value = board.value.pipeline.id;
    board.value.stages = board.value.stages || [];
  } catch (e) {
    message.error(t('Crm_Err_Could_Not_Load_The_Pipeline', 'Could not load the pipeline'));
  } finally {
    loading.value = false;
  }
}

function open(d) { if (!drag.value) router.push(`/crm/deals/${d.id}`); }

// ----- drag & drop -----
const drag = ref(null);        // { deal, fromCol }
const overCol = ref(null);
const dropIndex = ref(0);
const overZone = ref('');

function onDragStart(e, deal, col) {
  drag.value = { deal, fromCol: col.id };
  e.dataTransfer.effectAllowed = 'move';
  e.dataTransfer.setData('text/plain', String(deal.id));
}
function onDragEnd() {
  setTimeout(() => { drag.value = null; overCol.value = null; overZone.value = ''; }, 0);
}
function onDragOver(e, col) {
  if (!drag.value) return;
  overCol.value = col.id;
  overZone.value = '';
  // Drop index from the cursor's position against the cards' middles.
  const cards = [...e.currentTarget.querySelectorAll('.card:not(.ghost)')];
  let idx = cards.length;
  for (let i = 0; i < cards.length; i++) {
    const r = cards[i].getBoundingClientRect();
    if (e.clientY < r.top + r.height / 2) { idx = i; break; }
  }
  // The dragged card (ghost) is left out, so this is the index the server
  // expects: the position among the column's other cards.
  dropIndex.value = idx;
}

async function onDrop(col) {
  if (!drag.value) return;
  const { deal } = drag.value;
  const position = dropIndex.value;
  // Optimistic: move the card locally, then save.
  moveLocal(deal, col.id, position);
  drag.value = null;
  overCol.value = null;
  try {
    await http.post(`crm/deals/${deal.id}/move`, { crm_pipeline_stage_id: col.id, position });
  } catch (e) {
    message.error(e?.data?.message || t('Crm_Err_Could_Not_Move_The_Deal', 'Could not move the deal'));
  }
  load();
}

function moveLocal(deal, toColId, position) {
  for (const c of board.value.stages) {
    const i = c.deals.findIndex(d => d.id === deal.id);
    if (i >= 0) { c.deals.splice(i, 1); c.count--; c.value -= deal.value; }
  }
  const target = board.value.stages.find(c => c.id === toColId);
  if (target) { target.deals.splice(Math.min(position, target.deals.length), 0, deal); target.count++; target.value += deal.value; }
}

async function dropZone(which) {
  if (!drag.value) return;
  const { deal } = drag.value;
  drag.value = null;
  overZone.value = '';
  if (which === 'won') await markWon(deal);
  else openLost(deal);
}

async function cardAction(key, d) {
  if (key.startsWith('stage:')) {
    const stageId = Number(key.split(':')[1]);
    try {
      await http.post(`crm/deals/${d.id}/move`, { crm_pipeline_stage_id: stageId });
    } catch (e) {
      message.error(e?.data?.message || t('Crm_Err_Could_Not_Move_The_Deal', 'Could not move the deal'));
    }
    load();
  } else if (key === 'won') {
    await markWon(d);
  } else if (key === 'lost') {
    openLost(d);
  }
}

async function markWon(d) {
  try {
    await http.post(`crm/deals/${d.id}/won`);
    message.success(`${t('Crm_Deal_Won_Msg', 'Deal won')} — ${money(d.value)}`);
  } catch (e) {
    message.error(e?.data?.message || t('Gen_Could_Not_Update', 'Could not update'));
  }
  load();
}

const lostOpen = ref(false);
const lostDeal = ref(null);
const lostForm = ref({ crm_lost_reason_id: undefined, lost_note: '' });
function openLost(d) {
  lostDeal.value = d;
  lostForm.value = { crm_lost_reason_id: undefined, lost_note: '' };
  lostOpen.value = true;
}
async function doLost() {
  if (!lostForm.value.crm_lost_reason_id) { message.error(t('Crm_Choose_Reason', 'Choose a reason')); return; }
  busy.value = true;
  try {
    await http.post(`crm/deals/${lostDeal.value.id}/lost`, lostForm.value);
    lostOpen.value = false;
    load();
  } catch (e) {
    message.error(e?.data?.message || t('Gen_Could_Not_Update', 'Could not update'));
  } finally {
    busy.value = false;
  }
}

onMounted(async () => {
  try { meta.value = await http.get('crm/deals/meta'); } catch (e) { /* filters stay empty */ }
  if (!pipelineId.value) pipelineId.value = meta.value.default_pipeline_id;
  load();
});
</script>

<style scoped>
.toolbar { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin-bottom: 14px; }
.month { margin-inline-start: auto; display: flex; align-items: center; gap: 10px; font-size: 13px; }
.m-item { display: inline-flex; align-items: center; gap: 4px; font-weight: 600; }
.m-item.won { color: #16a34a; }
.m-item.lost { color: #dc2626; }
.m-label { opacity: 0.55; font-size: 12px; }

.board { display: flex; gap: 12px; overflow-x: auto; padding-bottom: 12px; min-height: 60vh; align-items: flex-start; }
.col {
  flex: 0 0 280px; display: flex; flex-direction: column; max-height: calc(100vh - 230px);
  background: rgba(128, 128, 128, 0.06); border-radius: 14px; transition: background 0.15s, box-shadow 0.15s;
}
.col.over { background: rgba(var(--app-primary-rgb, 109, 40, 217), 0.08); box-shadow: inset 0 0 0 2px rgba(var(--app-primary-rgb, 109, 40, 217), 0.35); }
.col-head { padding: 12px 14px 10px; border-top: 4px solid transparent; border-radius: 14px 14px 0 0; }
.col-title { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
.col-name { font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.col-prob { font-size: 11px; font-weight: 600; padding: 1px 7px; border-radius: 999px; background: rgba(128, 128, 128, 0.15); }
.col-meta { font-size: 12px; opacity: 0.65; margin-top: 2px; }
.dotsep { margin: 0 4px; }
.cards { flex: 1; overflow-y: auto; padding: 4px 10px 10px; display: flex; flex-direction: column; gap: 8px; min-height: 80px; }

.card {
  background: var(--ant-color-bg-container, #fff); border: 1px solid rgba(128, 128, 128, 0.18); border-radius: 12px;
  padding: 10px 12px; cursor: pointer; box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04); transition: box-shadow 0.15s, transform 0.15s;
}
.card:hover { box-shadow: 0 4px 14px rgba(15, 23, 42, 0.08); }
.card[draggable='true'] { cursor: grab; }
.card.ghost { opacity: 0.35; }
.card.rot { border-inline-start: 3px solid #f59e0b; }
.card-top { display: flex; align-items: flex-start; justify-content: space-between; gap: 6px; }
.card-title { font-weight: 600; line-height: 1.35; word-break: break-word; }
.card-menu { margin: -4px -6px 0 0; opacity: 0.55; }
.card-who { font-size: 12.5px; opacity: 0.65; margin-top: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.card-tags { display: flex; gap: 4px; flex-wrap: wrap; margin-top: 6px; }
.tag { padding: 0 7px; border-radius: 6px; font-size: 11px; font-weight: 500; }
.card-foot { display: flex; align-items: center; gap: 8px; margin-top: 8px; }
.card-value { font-weight: 700; color: var(--app-primary, #6d28d9); margin-inline-end: auto; }
.card-date { display: inline-flex; align-items: center; gap: 3px; font-size: 11.5px; opacity: 0.7; }
.card-date.late { color: #dc2626; opacity: 1; font-weight: 600; }
.rot-ic { color: #f59e0b; }
.nobody { background: #cbd5e1; font-size: 11px; }
.placeholder { height: 64px; border: 2px dashed rgba(var(--app-primary-rgb, 109, 40, 217), 0.4); border-radius: 12px; background: rgba(var(--app-primary-rgb, 109, 40, 217), 0.04); }
.add {
  border: 1px dashed rgba(128, 128, 128, 0.35); background: transparent; border-radius: 10px; padding: 8px; color: inherit;
  font: inherit; font-size: 13px; opacity: 0.65; cursor: pointer;
}
.add:hover { opacity: 1; border-color: var(--app-primary, #6d28d9); color: var(--app-primary, #6d28d9); }

.dropzones { position: fixed; inset-inline: 0; bottom: 18px; display: flex; justify-content: center; gap: 16px; z-index: 1000; pointer-events: none; }
.zone {
  pointer-events: auto; display: inline-flex; align-items: center; gap: 8px; padding: 18px 40px; border-radius: 16px;
  font-weight: 700; color: #fff; box-shadow: 0 10px 30px rgba(15, 23, 42, 0.25); transition: transform 0.15s;
}
.zone.won { background: #16a34a; }
.zone.lost { background: #dc2626; }
.zone.hot { transform: scale(1.08); }
.mi { vertical-align: -2px; margin-inline-end: 4px; }
.mi.won { color: #16a34a; }
.mi.lost { color: #dc2626; }
.lost-deal { font-weight: 600; }
.mb0 { margin-bottom: 0; }
.card-next { margin-top: 6px; font-size: 11.5px; display: flex; align-items: center; gap: 4px; opacity: 0.75; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.card-next.late { color: #dc2626; opacity: 1; font-weight: 600; }
.card-next.none { color: #d97706; opacity: 1; }
</style>
