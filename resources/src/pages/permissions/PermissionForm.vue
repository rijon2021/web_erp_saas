<template>
  <div class="page">
    <PageHeader
      :title="isEdit ? $t('Edit_Permission') : $t('Create_Permission')"
      :breadcrumb="[$t('Users'), $t('GroupPermissions'), isEdit ? $t('Edit') : $t('Add')]"
    >
      <template #extra>
        <a-space>
          <a-button @click="$router.push('/permissions')">{{ $t('Cancel') }}</a-button>
          <a-button type="primary" :loading="submitting" @click="submit">{{ $t('submit') }}</a-button>
        </a-space>
      </template>
    </PageHeader>

    <div v-if="isLoading" style="display: flex; justify-content: center; padding: 96px 0">
      <a-spin size="large" />
    </div>

    <template v-else>
      <a-card size="small" style="margin-bottom: 16px">
        <a-form ref="formRef" :model="role" :rules="rules" layout="vertical">
          <a-row :gutter="16">
            <a-col :xs="24" :md="12">
              <a-form-item :label="$t('RoleName') + ' *'" name="name">
                <a-input v-model:value="role.name" :placeholder="$t('Enter_Role_Name')" />
              </a-form-item>
            </a-col>
            <a-col :xs="24" :md="12">
              <a-form-item :label="$t('RoleDescription')" style="margin-bottom: 0">
                <a-input v-model:value="role.description" :placeholder="$t('Enter_Role_Description')" />
              </a-form-item>
            </a-col>
            <!-- Register guard rail: the most a cashier of this role may take off a sale. -->
            <a-col :xs="24" :md="12">
              <a-form-item :label="$t('Role_Max_Discount')" :extra="$t('Role_Max_Discount_Help')" style="margin: 16px 0 0">
                <a-input-number
                  v-model:value="role.max_discount_percent" :min="0" :max="100" :precision="2"
                  addon-after="%" style="width: 200px" :placeholder="$t('Role_No_Limit')"
                />
              </a-form-item>
            </a-col>
          </a-row>
        </a-form>
      </a-card>

      <!-- Global controls -->
      <a-card size="small" style="margin-bottom: 16px">
        <div class="bulk">
          <a-input-search
            v-model:value="search" :placeholder="$t('Search')"
            allow-clear style="max-width: 280px"
          />
          <a-space wrap>
            <a-tag color="blue">{{ permissions.length }} / {{ totalCount }}</a-tag>
            <a-button size="small" @click="selectAll(true)">{{ tf('Select_All', 'Select all') }}</a-button>
            <a-button size="small" @click="selectAll(false)">{{ tf('Deselect_All', 'Deselect all') }}</a-button>
          </a-space>
        </div>
      </a-card>

      <a-alert
        v-if="lockedPermissions.length"
        type="info" show-icon style="margin-bottom: 16px"
        :message="tf('Plan_Permissions_Hidden_Note', 'Only permissions included in your current plan are shown. Features outside your plan are hidden here and blocked by the system.')"
      />

      <!-- Permission groups -->
      <a-row :gutter="[16, 16]">
        <a-col v-for="group in visibleGroups" :key="group.label" :xs="24" :md="12" :xl="8">
          <a-card size="small" class="perm-card">
            <template #title>
              <span>{{ groupLabel(group) }}</span>
              <a-tag style="margin-left: 8px">{{ groupChecked(group) }}/{{ grantCount(group) }}</a-tag>
            </template>
            <template #extra>
              <a-checkbox
                :checked="groupChecked(group) === grantCount(group)"
                :indeterminate="groupChecked(group) > 0 && groupChecked(group) < grantCount(group)"
                @change="e => toggleGroup(group, e.target.checked)"
              />
            </template>
            <div v-for="item in group.items" :key="item.v" class="perm-row">
              <a-checkbox
                :checked="permissions.includes(item.v)"
                @change="e => togglePermission(item.v, e.target.checked)"
              >
                {{ itemLabel(item) }}
                <a-tag v-if="item.r" color="red" class="restrict-tag">{{ tf('Restriction', 'Restriction') }}</a-tag>
              </a-checkbox>
            </div>
          </a-card>
        </a-col>
      </a-row>

      <a-empty v-if="!visibleGroups.length" :description="$t('NodataAvailable')" style="padding: 48px 0" />

      <div style="display: flex; justify-content: flex-end; margin-top: 16px">
        <a-button type="primary" size="large" :loading="submitting" @click="submit">{{ $t('submit') }}</a-button>
      </div>
    </template>
  </div>
</template>

<script setup>
/**
 * Role create/edit — replaces legacy Create_permission.vue and
 * Edit_permission.vue (~4,100 lines each of hand-written checkboxes) with the
 * same catalogue driven by config/permissions.js, which was EXTRACTED from
 * that template so the original 239 permissions and their 29 groups stayed
 * identical. Modules added since (promotions, document archive...) append their
 * group there — this page needs no edit to show them.
 * Load (edit): GET roles/{id}/edit → {role, permissions[]}.
 * Save: POST roles / PUT roles/{id} with {role, permissions} — legacy payload.
 * After saving, auth permissions are refreshed (legacy dispatched
 * refreshUserPermissions) so the sidebar reflects a self-edit immediately.
 */
import { ref, computed, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import PageHeader from '../../components/PageHeader.vue';
import { PERMISSION_GROUPS } from '../../config/permissions';
import { useAuthStore } from '../../stores/auth';
import { t as tf } from '../../i18n';
import http from '../../lib/http';

const { t } = useI18n();
const route = useRoute();
const router = useRouter();
const auth = useAuthStore();

const id = route.params.id || null;
const isEdit = !!id;

const isLoading = ref(true);
const submitting = ref(false);
const formRef = ref();
const search = ref('');
const role = ref({ name: '', description: '', max_discount_percent: null });
const permissions = ref([]);

const rules = computed(() => ({
  name: [{ required: true, message: t('Field_is_required') }],
}));

// Plan gating (legacy loadAvailablePermissions): slugs the tenant's plan does
// not include are hidden and never resubmitted — the API rejects a role that
// carries one with a 422.
const lockedPermissions = ref([]);
const lockedSet = computed(() => new Set(lockedPermissions.value));
const availableGroups = computed(() =>
  PERMISSION_GROUPS
    .map(g => ({ ...g, items: g.items.filter(i => !lockedSet.value.has(i.v)) }))
    .filter(g => g.items.length)
);

async function loadLockedPermissions() {
  try {
    const data = await http.get('roles/available_permissions');
    lockedPermissions.value = Array.isArray(data?.locked) ? data.locked : [];
  } catch (e) {
    lockedPermissions.value = []; // fail open: the API still enforces the plan on save
  }
}

const totalCount = computed(() => availableGroups.value.reduce((s, g) => s + g.items.length, 0));

/** Label helpers — `l` is an i18n key, `f` the legacy fallback text. */
function itemLabel(item) {
  const first = tf(item.l, item.f);
  if (!item.l2) return first;
  return `${first}${item.sep || ' '}${tf(item.l2, item.f2)}`;
}
function groupLabel(group) {
  return group.raw ? group.label : tf(group.label, group.label);
}

const visibleGroups = computed(() => {
  const q = search.value.trim().toLowerCase();
  if (!q) return availableGroups.value;
  return availableGroups.value
    .map(g => ({
      ...g,
      items: g.items.filter(i =>
        i.v.toLowerCase().includes(q) || itemLabel(i).toLowerCase().includes(q)),
    }))
    .filter(g => g.items.length || groupLabel(g).toLowerCase().includes(q));
});

// Group counters + the header checkbox track grant items only (restrictions excluded).
function groupChecked(group) {
  return group.items.filter(i => !i.r && permissions.value.includes(i.v)).length;
}
const restrictionSlugs = new Set(PERMISSION_GROUPS.flatMap(g => g.items.filter(i => i.r).map(i => i.v)));
function grantCount(group) {
  return group.items.filter(i => !i.r).length;
}
function togglePermission(v, on) {
  const i = permissions.value.indexOf(v);
  if (on && i === -1) permissions.value.push(v);
  else if (!on && i !== -1) permissions.value.splice(i, 1);
}
// Restriction items (r: true) remove access, so bulk toggles never tick them;
// deselecting still clears them.
function toggleGroup(group, on) {
  group.items.forEach(i => { if (!(on && i.r)) togglePermission(i.v, on); });
}
function selectAll(on) {
  if (!on) {
    permissions.value = [];
    return;
  }
  // Grant everything, but keep whichever restrictions were already ticked.
  const kept = permissions.value.filter(v => restrictionSlugs.has(v));
  permissions.value = [
    ...availableGroups.value.flatMap(g => g.items.filter(i => !i.r).map(i => i.v)),
    ...kept,
  ];
}

async function submit() {
  try {
    await formRef.value.validate();
  } catch (e) {
    message.error(t('Please_fill_the_form_correctly'));
    return;
  }
  submitting.value = true;
  const payload = { role: role.value, permissions: permissions.value };
  try {
    if (isEdit) {
      await http.put(`roles/${id}`, payload);
      message.success(t('Successfully_Updated'));
    } else {
      await http.post('roles', payload);
      message.success(t('Successfully_Created'));
    }
    // legacy dispatched refreshUserPermissions after saving — the store's
    // load() short-circuits once loaded, so clear the flag to force a refetch.
    try {
      auth.loaded = false;
      await auth.load();
    } catch (e) { /* non-fatal */ }
    router.push('/permissions');
  } catch (e) {
    // A 422 (e.g. the plan gate) names the problem; show it rather than a
    // generic error.
    const errors = e?.data?.errors;
    const first = errors ? Object.values(errors).flat()[0] : null;
    message.error(first ? String(first) : t('InvalidData'));
  } finally {
    submitting.value = false;
  }
}

onMounted(async () => {
  // Role #1 (Owner) is not editable: the API answers roles/1/edit with HTTP
  // 401, which lib/http.js treats as an expired session and logs the user
  // out. The list hides the button; guard the direct URL too.
  if (isEdit && String(id) === '1') {
    message.warning(t('InvalidData'));
    router.replace('/permissions');
    return;
  }
  await loadLockedPermissions();
  if (!isEdit) {
    isLoading.value = false;
    return;
  }
  try {
    const data = await http.get(`roles/${id}/edit`);
    role.value = { max_discount_percent: null, ...(data.role || { name: '', description: '' }) };
    if (role.value.max_discount_percent !== null) role.value.max_discount_percent = Number(role.value.max_discount_percent);
    // Never resubmit a slug the plan no longer covers.
    permissions.value = (Array.isArray(data.permissions) ? data.permissions : [])
      .filter(p => !lockedSet.value.has(p));
  } catch (e) {
    message.error(t('InvalidData'));
  } finally {
    isLoading.value = false;
  }
});
</script>

<style scoped>
.restrict-tag {
  margin-left: 6px;
  font-size: 11px;
  line-height: 16px;
}
.bulk {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  flex-wrap: wrap;
}
.perm-card {
  height: 100%;
}
.perm-row {
  padding: 3px 0;
}
</style>
