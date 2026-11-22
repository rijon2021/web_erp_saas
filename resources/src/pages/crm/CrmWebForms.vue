<template>
  <div class="page">
    <PageHeader :title="$t('Crm_Web_Forms')" :subtitle="$t('Crm_Web_Forms_Subtitle')" :breadcrumb="[$t('Crm'), $t('Crm_Web_Forms')]">
      <template #actions>
        <a-button type="primary" @click="openEditor()"><template #icon><PlusOutlined /></template>{{ $t('Crm_New_Form') }}</a-button>
      </template>
    </PageHeader>

    <a-spin :spinning="loading">
      <div v-if="forms.length" class="grid">
        <div v-for="f in forms" :key="f.id" class="fcard" :class="{ paused: !f.is_active }">
          <div class="f-bar" :style="{ background: f.accent_color || primary }"></div>
          <div class="f-body">
            <div class="f-head">
              <span class="f-ic" :style="{ color: f.accent_color || primary, background: tint(f.accent_color || primary, 0.12) }"><FileInput :size="18" /></span>
              <div class="f-titles">
                <div class="f-name">{{ f.name }}</div>
                <div class="f-sub">{{ f.title || $t('Crm_No_Title') }}</div>
              </div>
              <a-tooltip :title="f.is_active ? $t('Crm_Form_Live') : $t('Crm_Form_Paused')">
                <a-switch :checked="f.is_active" size="small" @change="(v) => toggle(f, v)" />
              </a-tooltip>
            </div>

            <div class="stats">
              <div class="stat"><span class="s-v">{{ f.submissions }}</span><span class="s-l">{{ $t('Crm_Submissions') }}</span></div>
              <div class="stat"><span class="s-v">{{ f.leads }}</span><span class="s-l">{{ $t('Crm_Leads') }}</span></div>
              <div class="stat"><span class="s-v ok">{{ f.leads ? Math.round((f.converted / f.leads) * 100) : 0 }}%</span><span class="s-l">{{ $t('Crm_Converted') }}</span></div>
            </div>

            <div class="f-meta">
              <span><UserRound :size="12" /> {{ f.owner ? f.owner.name : $t('Crm_By_Assignment_Rules') }}</span>
              <span v-if="f.source"><span class="dot" :style="{ background: f.source.color }"></span>{{ f.source.name }}</span>
              <span><Clock :size="12" /> {{ f.last_submission_at ? ago(f.last_submission_at) : $t('Crm_No_Submissions_Yet') }}</span>
            </div>

            <div class="f-actions">
              <a-button size="small" @click="copy(f.url)"><template #icon><LinkOutlined /></template>{{ $t('Crm_Copy_Link') }}</a-button>
              <a-button size="small" @click="openEmbed(f)"><template #icon><CodeOutlined /></template>{{ $t('Crm_Embed') }}</a-button>
              <a-button size="small" @click="openEditor(f)"><template #icon><EditOutlined /></template></a-button>
              <a-dropdown :trigger="['click']">
                <a-button size="small"><template #icon><MoreOutlined /></template></a-button>
                <template #overlay>
                  <a-menu>
                    <a-menu-item key="open" @click="openUrl(f.url)"><ExportOutlined /> {{ $t('Crm_Open_Form') }}</a-menu-item>
                    <a-menu-item key="leads" @click="$router.push(`/crm/leads?web_form=${f.id}&status=`)"><TeamOutlined /> {{ $t('Crm_See_Leads') }}</a-menu-item>
                    <a-menu-item key="regen" @click="regenerate(f)"><ReloadOutlined /> {{ $t('Crm_New_Link') }}</a-menu-item>
                    <a-menu-divider />
                    <a-menu-item key="del" danger @click="remove(f)"><DeleteOutlined /> {{ $t('Delete') }}</a-menu-item>
                  </a-menu>
                </template>
              </a-dropdown>
            </div>
          </div>
        </div>
      </div>

      <a-card v-else-if="!loading" :bordered="false" class="empty-card">
        <div class="empty">
          <span class="empty-ic"><FileInput :size="30" /></span>
          <div class="empty-title">{{ $t('Crm_No_Forms') }}</div>
          <p>{{ $t('Crm_No_Forms_Hint') }}</p>
          <a-button type="primary" @click="openEditor()"><template #icon><PlusOutlined /></template>{{ $t('Crm_New_Form') }}</a-button>
        </div>
      </a-card>
    </a-spin>

    <!-- Editor -->
    <a-drawer v-model:open="editorOpen" :title="form.id ? $t('Crm_Edit_Form') : $t('Crm_New_Form')" :width="drawerWidth" destroy-on-close>
      <div class="editor">
        <div class="ed-left">
          <a-form layout="vertical">
            <a-form-item :label="$t('Crm_Form_Name')" :extra="$t('Crm_Form_Name_Hint')" required>
              <a-input v-model:value="form.name" :maxlength="160" />
            </a-form-item>
          </a-form>
          <a-tabs v-model:activeKey="edTab" size="small">
            <a-tab-pane key="fields" :tab="$t('Crm_Fields')">
              <div class="fields">
                <div v-for="(fl, i) in form.fields" :key="fl.key" class="fl">
                  <div class="order">
                    <a-button type="text" size="small" :disabled="i === 0" @click="moveField(i, -1)"><template #icon><UpOutlined /></template></a-button>
                    <a-button type="text" size="small" :disabled="i === form.fields.length - 1" @click="moveField(i, 1)"><template #icon><DownOutlined /></template></a-button>
                  </div>
                  <div class="fl-main">
                    <span class="fl-key">{{ paletteLabel(fl.key) }}</span>
                    <a-input v-model:value="fl.label" size="small" :maxlength="120" :placeholder="paletteLabel(fl.key)" />
                  </div>
                  <a-checkbox v-model:checked="fl.required">{{ $t('Crm_Required') }}</a-checkbox>
                  <a-button type="text" size="small" danger @click="form.fields.splice(i, 1)"><template #icon><CloseOutlined /></template></a-button>
                </div>
              </div>
              <a-dropdown v-if="unusedFields.length" :trigger="['click']">
                <a-button type="dashed" block class="add-field"><PlusOutlined /> {{ $t('Crm_Add_Field') }}</a-button>
                <template #overlay>
                  <a-menu @click="({ key }) => addField(key)">
                    <a-menu-item v-for="p in unusedFields" :key="p.key">{{ p.label }}</a-menu-item>
                  </a-menu>
                </template>
              </a-dropdown>
              <div class="hint">{{ $t('Crm_Fields_Hint') }}</div>
            </a-tab-pane>

            <a-tab-pane key="content" :tab="$t('Crm_Content')">
              <a-form layout="vertical">
                <a-form-item :label="$t('Crm_Form_Title')"><a-input v-model:value="form.title" :maxlength="191" :placeholder="$t('Crm_Form_Title_Placeholder')" /></a-form-item>
                <a-form-item :label="$t('Crm_Form_Intro')"><a-textarea v-model:value="form.intro" :rows="3" :maxlength="2000" /></a-form-item>
                <a-row :gutter="12">
                  <a-col :span="12"><a-form-item :label="$t('Crm_Button_Text')"><a-input v-model:value="form.submit_label" :maxlength="80" placeholder="Send" /></a-form-item></a-col>
                  <a-col :span="12">
                    <a-form-item :label="$t('Crm_Colour')">
                      <div class="swatches">
                        <button v-for="c in SWATCHES" :key="c" type="button" class="sw" :class="{ on: form.accent_color === c }" :style="{ background: c }" @click="form.accent_color = c"></button>
                        <input v-model="form.accent_color" type="color" class="sw-input" />
                      </div>
                    </a-form-item>
                  </a-col>
                </a-row>
                <a-form-item :label="$t('Crm_Thank_You_Message')"><a-textarea v-model:value="form.success_message" :rows="2" :maxlength="500" :placeholder="$t('Crm_Thank_You_Placeholder')" /></a-form-item>
                <a-form-item :label="$t('Crm_Redirect_Url')" :extra="$t('Crm_Redirect_Url_Hint')"><a-input v-model:value="form.redirect_url" :maxlength="500" placeholder="https://" /></a-form-item>
                <a-form-item :label="$t('Crm_Consent_Text')" :extra="$t('Crm_Consent_Text_Hint')"><a-input v-model:value="form.consent_text" :maxlength="500" /></a-form-item>
              </a-form>
            </a-tab-pane>

            <a-tab-pane key="routing" :tab="$t('Crm_What_Happens')">
              <a-form layout="vertical">
                <a-form-item :label="$t('Crm_Owner')" :extra="$t('Crm_Form_Owner_Hint')">
                  <a-select v-model:value="form.owner_id" :options="ownerOptions" allow-clear show-search option-filter-prop="label" :placeholder="$t('Crm_By_Assignment_Rules')" />
                </a-form-item>
                <a-row :gutter="12">
                  <a-col :span="12">
                    <a-form-item :label="$t('Crm_Source')"><a-select v-model:value="form.crm_lead_source_id" :options="sourceOptions" allow-clear :placeholder="$t('Crm_Source_Auto')" /></a-form-item>
                  </a-col>
                  <a-col :span="12">
                    <a-form-item :label="$t('Status')"><a-select v-model:value="form.crm_lead_status_id" :options="statusOptions" allow-clear :placeholder="$t('Crm_Starting_Status')" /></a-form-item>
                  </a-col>
                </a-row>
                <a-form-item :label="$t('Crm_Tags')"><a-select v-model:value="form.tag_ids" mode="multiple" :options="tagOptions" option-filter-prop="label" /></a-form-item>
                <a-checkbox v-model:checked="form.is_active">{{ $t('Crm_Form_Accepting') }}</a-checkbox>
              </a-form>
            </a-tab-pane>
          </a-tabs>
        </div>

        <!-- Live preview (same look as the public page) -->
        <div class="ed-right">
          <div class="pv-label"><EyeOutlined /> {{ $t('Crm_Preview') }}</div>
          <div class="pv" :style="{ '--accent': form.accent_color || '#6d28d9' }">
            <div class="pv-card">
              <div class="pv-bar"></div>
              <div class="pv-inner">
                <div v-if="form.title" class="pv-title">{{ form.title }}</div>
                <p v-if="form.intro" class="pv-intro">{{ form.intro }}</p>
                <div class="pv-grid">
                  <div v-for="fl in form.fields" :key="fl.key" class="pv-field" :class="{ half: HALVES.includes(fl.key) }">
                    <label>{{ fl.label || paletteLabel(fl.key) }}<span v-if="fl.required" class="req">*</span></label>
                    <div class="pv-input" :class="{ area: paletteType(fl.key) === 'textarea' }"></div>
                  </div>
                  <label v-if="form.consent_text" class="pv-consent"><span class="pv-box"></span>{{ form.consent_text }}</label>
                </div>
                <div class="pv-btn">{{ form.submit_label || 'Send' }}</div>
              </div>
            </div>
          </div>
        </div>
      </div>
      <template #footer>
        <div class="ed-foot">
          <a-button @click="editorOpen = false">{{ $t('Cancel') }}</a-button>
          <a-button type="primary" :loading="saving" @click="save"><template #icon><SaveOutlined /></template>{{ $t('Save') }}</a-button>
        </div>
      </template>
    </a-drawer>

    <!-- Embed -->
    <a-modal v-model:open="embedOpen" :title="`${$t('Crm_Embed')} — ${embedForm?.name || ''}`" :footer="null" width="640px">
      <template v-if="embedForm">
        <div class="em-label">{{ $t('Crm_Share_Link') }}</div>
        <div class="em-row">
          <a-input :value="embedForm.url" readonly />
          <a-button @click="copy(embedForm.url)"><template #icon><CopyOutlined /></template></a-button>
        </div>
        <div class="em-label">{{ $t('Crm_Embed_Code') }}</div>
        <a-textarea :value="snippet" :rows="7" readonly class="code" />
        <a-button type="primary" class="em-copy" @click="copy(snippet)"><template #icon><CopyOutlined /></template>{{ $t('Crm_Copy_Code') }}</a-button>
        <a-alert type="info" show-icon :message="$t('Crm_Embed_Hint')" />
      </template>
    </a-modal>
  </div>
</template>

<script setup>
/** CRM web forms: public lead-capture forms (GET/POST crm/web-forms); the public page is /lead-form/{token}. */
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import { message, Modal } from 'ant-design-vue';
import {
  PlusOutlined, EditOutlined, DeleteOutlined, LinkOutlined, CodeOutlined, MoreOutlined, ExportOutlined, TeamOutlined,
  ReloadOutlined, UpOutlined, DownOutlined, CloseOutlined, SaveOutlined, EyeOutlined, CopyOutlined,
} from '@ant-design/icons-vue';
import { FileInput, UserRound, Clock } from 'lucide-vue-next';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';
import { t } from '../../i18n';
import { SWATCHES, tint, ago } from './crmOptions';

const HALVES = ['first_name', 'last_name', 'email', 'phone', 'company_name', 'job_title', 'city', 'country', 'website', 'estimated_value'];
const primary = getComputedStyle(document.documentElement).getPropertyValue('--app-primary').trim() || '#6d28d9';

const loading = ref(false);
const saving = ref(false);
const forms = ref([]);
const lookups = ref({ palette: [], default_fields: [], sources: [], statuses: [], owners: [], tags: [] });

const ownerOptions = computed(() => lookups.value.owners.map(o => ({ value: o.id, label: o.name })));
const sourceOptions = computed(() => lookups.value.sources.map(s => ({ value: s.id, label: s.name })));
const statusOptions = computed(() => lookups.value.statuses.map(s => ({ value: s.id, label: s.name })));
const tagOptions = computed(() => lookups.value.tags.map(x => ({ value: x.id, label: x.name })));
function paletteLabel(key) { return lookups.value.palette.find(p => p.key === key)?.label || key; }
function paletteType(key) { return lookups.value.palette.find(p => p.key === key)?.type || 'text'; }

function errText(e, fallback) {
  const errors = e?.data?.errors;
  return (errors && Object.values(errors)[0]?.[0]) || e?.data?.message || fallback;
}

async function load() {
  loading.value = true;
  try {
    const res = await http.get('crm/web-forms');
    forms.value = res.forms;
    lookups.value = res;
  } catch (e) {
    message.error(errText(e, t('Crm_Could_Not_Load_Forms', 'Could not load the forms')));
  } finally {
    loading.value = false;
  }
}

// ----- editor -----
const editorOpen = ref(false);
const edTab = ref('fields');
const form = ref({});
const winW = ref(window.innerWidth);
const onResize = () => { winW.value = window.innerWidth; };
const drawerWidth = computed(() => (winW.value < 900 ? '100%' : Math.min(1120, winW.value - 120)));
const unusedFields = computed(() => lookups.value.palette.filter(p => !(form.value.fields || []).some(f => f.key === p.key)));

function openEditor(f = null) {
  form.value = f
    ? JSON.parse(JSON.stringify({
      id: f.id, name: f.name, title: f.title, intro: f.intro, fields: f.fields.map(x => ({ key: x.key, label: x.label, required: x.required })),
      submit_label: f.submit_label, success_message: f.success_message, redirect_url: f.redirect_url, consent_text: f.consent_text,
      accent_color: f.accent_color || '#6d28d9', crm_lead_source_id: f.crm_lead_source_id || undefined, crm_lead_status_id: f.crm_lead_status_id || undefined,
      owner_id: f.owner_id || undefined, tag_ids: f.tag_ids, is_active: f.is_active,
    }))
    : {
      id: null, name: '', title: t('Crm_Form_Default_Title', 'Get in touch'), intro: '', fields: JSON.parse(JSON.stringify(lookups.value.default_fields)),
      submit_label: '', success_message: '', redirect_url: '', consent_text: '', accent_color: '#6d28d9',
      crm_lead_source_id: undefined, crm_lead_status_id: undefined, owner_id: undefined, tag_ids: [], is_active: true,
    };
  edTab.value = 'fields';
  editorOpen.value = true;
}
function addField(key) {
  form.value.fields.push({ key, label: paletteLabel(key), required: false });
}
function moveField(i, dir) {
  const list = form.value.fields;
  const [x] = list.splice(i, 1);
  list.splice(i + dir, 0, x);
}
async function save() {
  const f = form.value;
  if (!f.name?.trim()) { message.error(t('Crm_Form_Name_Required', 'Give the form a name')); return; }
  saving.value = true;
  const body = {
    ...f, crm_lead_source_id: f.crm_lead_source_id || '', crm_lead_status_id: f.crm_lead_status_id || '', owner_id: f.owner_id || '',
    fields: f.fields.map(x => ({ key: x.key, label: x.label, required: !!x.required })),
  };
  try {
    const res = f.id ? await http.put(`crm/web-forms/${f.id}`, body) : await http.post('crm/web-forms', body);
    editorOpen.value = false;
    await load();
    message.success(t('Saved', 'Saved'));
    if (!f.id && res?.form) openEmbed(forms.value.find(x => x.id === res.form.id) || res.form);
  } catch (e) {
    message.error(errText(e, t('Gen_Could_Not_Save', 'Could not save')));
  } finally {
    saving.value = false;
  }
}

async function toggle(f, v) {
  try {
    await http.put(`crm/web-forms/${f.id}`, {
      ...f, is_active: v, owner_id: f.owner_id || '', crm_lead_source_id: f.crm_lead_source_id || '', crm_lead_status_id: f.crm_lead_status_id || '',
    });
    f.is_active = v;
  } catch (e) { message.error(errText(e, t('Gen_Could_Not_Save', 'Could not save'))); }
}
function regenerate(f) {
  Modal.confirm({
    title: t('Crm_New_Link_Title', 'Create a new link?'),
    content: t('Crm_New_Link_Hint', 'The current link and embed code stop working; you will need to update your website.'),
    okText: t('Crm_New_Link', 'New link'), cancelText: t('Cancel', 'Cancel'),
    async onOk() {
      try { await http.post(`crm/web-forms/${f.id}/regenerate`); await load(); message.success(t('Crm_New_Link_Done', 'New link ready')); } catch (e) { message.error(errText(e, t('Gen_Could_Not_Update', 'Could not update'))); }
    },
  });
}
function remove(f) {
  Modal.confirm({
    title: t('Delete_Title', 'Are you sure?'), content: `${f.name} — ${t('Crm_Delete_Form_Hint', 'leads already captured are kept')}`, okType: 'danger',
    okText: t('Delete_confirmButtonText', 'Yes, delete it!'), cancelText: t('Delete_cancelButtonText', 'Cancel'),
    async onOk() { try { await http.delete(`crm/web-forms/${f.id}`); await load(); } catch (e) { message.error(errText(e, t('Gen_Could_Not_Delete', 'Could not delete'))); } },
  });
}

// ----- embed / copy -----
const embedOpen = ref(false);
const embedForm = ref(null);
function openEmbed(f) { embedForm.value = f; embedOpen.value = true; }
const snippet = computed(() => {
  const f = embedForm.value;
  if (!f) return '';
  const id = `crm-form-${f.token}`;
  return `<iframe id="${id}" src="${f.url}?embed=1" title="${(f.title || f.name).replace(/"/g, '&quot;')}" style="width:100%;border:0;min-height:480px" loading="lazy"></iframe>\n`
    + `<script>window.addEventListener('message',function(e){var d=e.data||{};if(d.token==='${f.token}'&&d.crmFormHeight){document.getElementById('${id}').style.height=d.crmFormHeight+'px';}});<\/script>`;
});
async function copy(text) {
  try {
    await navigator.clipboard.writeText(text);
  } catch (e) {
    const ta = document.createElement('textarea');
    ta.value = text; document.body.appendChild(ta); ta.select();
    try { document.execCommand('copy'); } catch (x) { /* ignore */ }
    ta.remove();
  }
  message.success(t('Crm_Copied', 'Copied'));
}
function openUrl(url) { window.open(url, '_blank', 'noopener'); }

onMounted(() => { load(); window.addEventListener('resize', onResize); });
onBeforeUnmount(() => window.removeEventListener('resize', onResize));
</script>

<style scoped>
.grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 16px; }
.fcard { border-radius: 14px; background: var(--ant-color-bg-container, #fff); border: 1px solid rgba(128, 128, 128, 0.16); overflow: hidden; display: flex; flex-direction: column; transition: box-shadow 0.15s; }
.fcard:hover { box-shadow: 0 6px 22px -10px rgba(15, 23, 42, 0.25); }
.fcard.paused { opacity: 0.72; }
.f-bar { height: 4px; }
.f-body { padding: 14px 16px 16px; display: flex; flex-direction: column; gap: 12px; }
.f-head { display: flex; align-items: center; gap: 10px; }
.f-ic { width: 38px; height: 38px; border-radius: 10px; display: inline-flex; align-items: center; justify-content: center; flex: none; }
.f-titles { flex: 1; min-width: 0; }
.f-name { font-weight: 600; font-size: 15px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.f-sub { font-size: 12.5px; opacity: 0.6; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; padding: 10px; border-radius: 10px; background: rgba(128, 128, 128, 0.06); }
.stat { display: flex; flex-direction: column; align-items: center; }
.s-v { font-size: 18px; font-weight: 700; line-height: 1.2; }
.s-v.ok { color: #16a34a; }
.s-l { font-size: 11.5px; opacity: 0.6; }
.f-meta { display: flex; flex-wrap: wrap; gap: 4px 14px; font-size: 12px; opacity: 0.75; }
.f-meta span { display: inline-flex; align-items: center; gap: 5px; }
.dot { width: 8px; height: 8px; border-radius: 50%; display: inline-block; }
.f-actions { display: flex; gap: 6px; flex-wrap: wrap; }
.empty-card { border-radius: 14px; }
.empty { text-align: center; padding: 40px 16px; }
.empty-ic { width: 64px; height: 64px; border-radius: 18px; margin: 0 auto 14px; display: flex; align-items: center; justify-content: center; color: var(--app-primary, #6d28d9); background: rgba(var(--app-primary-rgb, 109, 40, 217), 0.1); }
.empty-title { font-size: 17px; font-weight: 600; }
.empty p { opacity: 0.65; max-width: 460px; margin: 6px auto 16px; }

.editor { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 24px; }
@media (max-width: 900px) { .editor { grid-template-columns: 1fr; } }
.fields { display: flex; flex-direction: column; gap: 6px; }
.fl { display: flex; align-items: center; gap: 8px; padding: 6px 8px; border: 1px solid rgba(128, 128, 128, 0.18); border-radius: 10px; }
.order { display: flex; flex-direction: column; margin: -4px 0; }
.order :deep(.ant-btn) { height: 16px; }
.fl-main { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 2px; }
.fl-key { font-size: 11px; text-transform: uppercase; letter-spacing: 0.04em; opacity: 0.5; font-weight: 600; }
.add-field { margin-top: 8px; }
.hint { font-size: 12px; opacity: 0.6; margin-top: 8px; }
.swatches { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; }
.sw { width: 22px; height: 22px; border-radius: 6px; border: 2px solid transparent; cursor: pointer; }
.sw.on { border-color: rgba(0, 0, 0, 0.55); box-shadow: 0 0 0 2px #fff inset; }
.sw-input { width: 30px; height: 24px; padding: 0; border: 0; background: none; cursor: pointer; }
.ed-foot { display: flex; justify-content: flex-end; gap: 8px; }

.ed-right { position: sticky; top: 0; align-self: start; }
.pv-label { font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; opacity: 0.55; margin-bottom: 8px; }
.pv { background: #f1f5f9; border-radius: 14px; padding: 22px; color: #0f172a; }
.pv-card { background: #fff; border-radius: 14px; overflow: hidden; box-shadow: 0 10px 28px -14px rgba(15, 23, 42, 0.3); }
.pv-bar { height: 5px; background: var(--accent); }
.pv-inner { padding: 20px; }
.pv-title { font-size: 18px; font-weight: 700; margin-bottom: 4px; }
.pv-intro { color: #64748b; font-size: 13px; white-space: pre-line; margin: 0 0 14px; }
.pv-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.pv-field { grid-column: span 2; display: flex; flex-direction: column; gap: 4px; }
.pv-field.half { grid-column: span 1; }
.pv-field label { font-size: 12px; font-weight: 600; }
.req { color: #dc2626; margin-inline-start: 2px; }
.pv-input { height: 34px; border: 1px solid #cbd5e1; border-radius: 8px; background: #fff; }
.pv-input.area { height: 74px; }
.pv-consent { grid-column: span 2; display: flex; gap: 8px; align-items: center; font-size: 12px; color: #64748b; }
.pv-box { width: 15px; height: 15px; border: 1px solid #94a3b8; border-radius: 4px; flex: none; }
.pv-btn { display: inline-block; margin-top: 16px; padding: 9px 18px; border-radius: 8px; color: #fff; background: var(--accent); font-weight: 600; font-size: 13px; }

.em-label { font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; opacity: 0.6; margin: 4px 0 6px; }
.em-row { display: flex; gap: 6px; margin-bottom: 16px; }
.code { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 12px; }
.em-copy { margin: 8px 0 14px; }
</style>
