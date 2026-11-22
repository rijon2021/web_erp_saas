<template>
  <div class="page">
    <PageHeader
      :title="$t('Rental_Agreement_Templates')"
      :subtitle="$t('Rental_Templates_Subtitle')"
      :breadcrumb="[$t('Rental_Management'), $t('Rental_Agreement_Templates')]"
    >
      <template #actions>
        <a-button type="primary" @click="startNew">
          <template #icon><PlusOutlined /></template>
          {{ $t('Rental_New_Template') }}
        </a-button>
      </template>
    </PageHeader>

    <a-spin :spinning="loading">
      <a-row :gutter="16">
        <!-- Template list -->
        <a-col :xs="24" :lg="7">
          <a-card size="small" class="block" :body-style="{ padding: '8px' }">
            <div v-if="templates.length" class="tpl-list">
              <button
                v-for="tpl in templates" :key="tpl.id" type="button" class="tpl"
                :class="{ active: form.id === tpl.id }" @click="edit(tpl)"
              >
                <FileTextOutlined class="tpl-ic" />
                <span class="tpl-body">
                  <span class="tpl-name">{{ tpl.name }}</span>
                  <span class="tpl-meta">{{ $t('Rental_Updated') }} {{ date(tpl.updated_at) }}</span>
                </span>
                <a-tag v-if="tpl.is_default" color="purple">{{ $t('Rental_Default') }}</a-tag>
              </button>
            </div>
            <div v-else class="empty">
              <FileTextOutlined class="empty-ic" />
              <p>{{ $t('Rental_No_Templates') }}</p>
            </div>
          </a-card>
        </a-col>

        <!-- Editor -->
        <a-col :xs="24" :lg="17">
          <a-card size="small" class="block">
            <template #title>{{ form.id ? $t('Rental_Edit_Template') : $t('Rental_New_Template') }}</template>
            <template #extra>
              <a-space>
                <a-button v-if="form.id" danger type="text" @click="remove">
                  <template #icon><DeleteOutlined /></template>
                </a-button>
                <a-button type="primary" :loading="saving" @click="save">
                  <template #icon><SaveOutlined /></template>
                  {{ $t('Save') }}
                </a-button>
              </a-space>
            </template>

            <a-row :gutter="16">
              <a-col :xs="24" :md="16">
                <a-form-item :label="$t('Name')" required>
                  <a-input v-model:value="form.name" :placeholder="$t('Rental_Template_Name_Placeholder')" />
                </a-form-item>
              </a-col>
              <a-col :xs="24" :md="8">
                <a-form-item :label="$t('Rental_Default')">
                  <a-switch v-model:checked="form.is_default" />
                  <span class="switch-help">{{ $t('Rental_Default_Template_Help') }}</span>
                </a-form-item>
              </a-col>
            </a-row>

            <div class="editor-wrap">
              <div class="editor">
                <RichTextEditor ref="editorRef" v-model="form.content" />
                <a class="reset-link" @click="useBuiltIn">{{ $t('Rental_Use_Builtin_Wording') }}</a>
              </div>
              <aside class="fields">
                <h4>{{ $t('Rental_Placeholders') }}</h4>
                <p>{{ $t('Rental_Placeholders_Help') }}</p>
                <a-input-search v-model:value="fieldFilter" size="small" allow-clear :placeholder="$t('Search')" class="fields-search" />
                <div class="field-list">
                  <button
                    v-for="p in filteredPlaceholders" :key="p.key" type="button" class="field"
                    :title="p.label" @click="insert(p.key)"
                  >
                    <code>{{ p.key }}</code>
                    <span>{{ p.label }}</span>
                  </button>
                </div>
              </aside>
            </div>
          </a-card>
        </a-col>
      </a-row>
    </a-spin>
  </div>
</template>

<script setup>
/**
 * Agreement templates — HTML wording with {placeholders}, merged into each
 * contract when it is printed. One template is the default; the print menu
 * on a contract lets the user pick any other.
 */
import { ref, computed, createVNode, onMounted } from 'vue';
import { message, Modal } from 'ant-design-vue';
import {
  PlusOutlined, FileTextOutlined, DeleteOutlined, SaveOutlined, ExclamationCircleOutlined,
} from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import RichTextEditor from '../../components/RichTextEditor.vue';
import { useFormat } from '../../composables/useFormat';
import http from '../../lib/http';
import { t } from '../../i18n';

const { date } = useFormat();

const loading = ref(false);
const saving = ref(false);
const templates = ref([]);
const placeholders = ref([]);
const builtIn = ref('');
const editorRef = ref(null);
const fieldFilter = ref('');

const form = ref({ id: null, name: '', content: '', is_default: false });

const filteredPlaceholders = computed(() => {
  const q = fieldFilter.value.trim().toLowerCase();
  if (!q) return placeholders.value;
  return placeholders.value.filter(p => p.key.includes(q) || p.label.toLowerCase().includes(q));
});

function edit(tpl) {
  form.value = { id: tpl.id, name: tpl.name, content: tpl.content || '', is_default: !!tpl.is_default };
}

function startNew() {
  form.value = { id: null, name: '', content: builtIn.value, is_default: !templates.value.length };
}

function useBuiltIn() {
  form.value.content = builtIn.value;
}

function insert(key) {
  editorRef.value?.insertText(key);
}

async function load(selectId = null) {
  loading.value = true;
  try {
    const data = await http.get('rental/agreement-templates');
    templates.value = data?.templates || [];
    placeholders.value = data?.placeholders || [];
    builtIn.value = data?.default_content || '';
    const pick = templates.value.find(x => x.id === selectId) || templates.value.find(x => x.is_default) || templates.value[0];
    if (pick) edit(pick);
    else startNew();
  } catch (e) {
    message.error(t('Rental_Err_Could_Not_Load_The_Templates', 'Could not load the templates'));
  } finally {
    loading.value = false;
  }
}

async function save() {
  if (!form.value.name.trim() || !form.value.content.trim()) {
    message.error(t('Rental_Template_Required', 'Give the template a name and some content'));
    return;
  }
  saving.value = true;
  const body = { name: form.value.name.trim(), content: form.value.content, is_default: form.value.is_default };
  try {
    let id = form.value.id;
    if (id) {
      await http.put(`rental/agreement-templates/${id}`, body);
    } else {
      id = (await http.post('rental/agreement-templates', body))?.id;
    }
    message.success(t('Saved', 'Saved'));
    await load(id);
  } catch (e) {
    const errors = e?.data?.errors;
    message.error((errors && Object.values(errors)[0]?.[0]) || e?.data?.message || t('Gen_Could_Not_Save', 'Could not save'));
  } finally {
    saving.value = false;
  }
}

function remove() {
  Modal.confirm({
    title: t('Delete_Title', 'Are you sure?'),
    icon: createVNode(ExclamationCircleOutlined),
    content: form.value.name,
    okType: 'danger',
    okText: t('Delete_confirmButtonText', 'Yes, delete it!'),
    cancelText: t('Delete_cancelButtonText', 'Cancel'),
    async onOk() {
      try {
        await http.delete(`rental/agreement-templates/${form.value.id}`);
        message.success(t('Deleted_in_successfully', 'Deleted successfully'));
        await load();
      } catch (e) {
        message.error(t('Rental_Err_Could_Not_Delete_This_Template', 'Could not delete this template'));
      }
    },
  });
}

onMounted(() => load());
</script>

<style scoped>
.block {
  margin-bottom: 16px;
}
.tpl-list {
  display: flex;
  flex-direction: column;
  gap: 4px;
}
.tpl {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px;
  border: 1px solid transparent;
  border-radius: 10px;
  background: none;
  color: inherit;
  font: inherit;
  text-align: start;
  cursor: pointer;
}
.tpl:hover {
  background: rgba(128, 128, 128, 0.07);
}
.tpl.active {
  border-color: rgba(var(--app-primary-rgb, 109, 40, 217), 0.4);
  background: rgba(var(--app-primary-rgb, 109, 40, 217), 0.07);
}
.tpl-ic {
  font-size: 18px;
  color: var(--app-primary, #6d28d9);
}
.tpl-body {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
}
.tpl-name {
  font-weight: 500;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.tpl-meta {
  font-size: 11.5px;
  opacity: 0.55;
}
.empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  padding: 24px 8px;
  text-align: center;
  opacity: 0.7;
}
.empty-ic {
  font-size: 28px;
  margin-bottom: 8px;
  color: var(--app-primary, #6d28d9);
}
.switch-help {
  margin-inline-start: 10px;
  font-size: 12px;
  opacity: 0.6;
}
.editor-wrap {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 250px;
  gap: 16px;
}
.editor :deep(.ql-container) {
  min-height: 420px;
}
.reset-link {
  display: inline-block;
  margin-top: 8px;
  font-size: 12.5px;
}
.fields h4 {
  margin: 0 0 4px;
  font-size: 13px;
  font-weight: 600;
}
.fields p {
  margin: 0 0 8px;
  font-size: 12px;
  opacity: 0.6;
}
.fields-search {
  margin-bottom: 8px;
}
.field-list {
  display: flex;
  flex-direction: column;
  gap: 4px;
  max-height: 460px;
  overflow-y: auto;
}
.field {
  display: flex;
  flex-direction: column;
  padding: 6px 8px;
  border: 1px solid rgba(128, 128, 128, 0.18);
  border-radius: 8px;
  background: none;
  color: inherit;
  font: inherit;
  text-align: start;
  cursor: pointer;
}
.field:hover {
  border-color: var(--app-primary, #6d28d9);
}
.field code {
  font-size: 12px;
  color: var(--app-primary, #6d28d9);
  background: none;
}
.field span {
  font-size: 11.5px;
  opacity: 0.6;
}

@media (max-width: 991px) {
  .editor-wrap {
    grid-template-columns: 1fr;
  }
  .field-list {
    max-height: 220px;
  }
}
</style>
