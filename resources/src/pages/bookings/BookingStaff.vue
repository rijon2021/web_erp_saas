<template>
  <div class="page">
    <PageHeader
      :title="$t('Bkm_Staff_Hours')"
      :subtitle="$t('Bkm_Staff_Hours_Subtitle')"
      :breadcrumb="[$t('Booking Management'), $t('Bkm_Staff_Hours')]"
    >
      <template #actions>
        <a-button type="primary" :disabled="!ready" @click="openAdd">
          <template #icon><PlusOutlined /></template>
          {{ $t('Bkm_Add_Staff') }}
        </a-button>
      </template>
    </PageHeader>

    <div v-if="loading && !loaded" class="loading"><a-spin size="large" /></div>
    <a-alert v-else-if="!ready" type="warning" show-icon :message="$t('Bkm_Not_Ready')" />

    <a-row v-else :gutter="16">
      <!-- Staff list -->
      <a-col :xs="24" :lg="8">
        <a-card size="small" class="block" :body-style="{ padding: 0 }">
          <a-empty v-if="!staff.length" :description="$t('Bkm_No_Staff')" class="empty">
            <a-button type="primary" @click="openAdd">{{ $t('Bkm_Add_Staff') }}</a-button>
          </a-empty>
          <ul v-else class="staff-list" role="listbox" :aria-label="$t('Bkm_Staff_Hours')">
            <li
              v-for="s in staff" :key="s.id" role="option" :aria-selected="s.id === selectedId" tabindex="0"
              class="staff-row" :class="{ active: s.id === selectedId, inactive: !s.active }"
              @click="select(s.id)" @keydown.enter.prevent="select(s.id)" @keydown.space.prevent="select(s.id)"
            >
              <img v-if="s.photo_url" :src="s.photo_url" alt="" class="avatar">
              <span v-else class="avatar" :style="{ background: s.color }">{{ initial(s.name) }}</span>
              <span class="staff-text">
                <span class="staff-name">{{ s.name }}</span>
                <span class="muted small">{{ staffLine(s) }}</span>
              </span>
              <a-tag v-if="!s.active">{{ $t('Bkm_Inactive') }}</a-tag>
              <a-tag v-else-if="s.bookable_online" color="green">{{ $t('Bkm_Online') }}</a-tag>
              <a-tag v-else>{{ $t('Bkm_Staff_Only') }}</a-tag>
            </li>
          </ul>
        </a-card>
        <a-card v-if="holidays.length" size="small" class="block" :title="$t('Bkm_Company_Holidays')">
          <p class="muted small">{{ $t('Bkm_Company_Holidays_Help') }}</p>
          <div v-for="h in holidays" :key="h.title + h.start_date" class="off-row">
            <span>{{ rangeLabel(h.start_date, h.end_date) }}</span>
            <span class="muted">{{ h.title }}</span>
          </div>
        </a-card>
      </a-col>

      <!-- Selected staff member -->
      <a-col v-if="current" :xs="24" :lg="16">
        <!-- Profile -->
        <a-card size="small" class="block">
          <template #title><span class="card-title"><UserOutlined /> {{ $t('Bkm_Profile') }}</span></template>
          <template #extra>
            <a-popconfirm :title="removeText" :ok-text="$t('Remove')" ok-type="danger" @confirm="removeStaff">
              <a-button size="small" danger type="text">{{ $t('Remove') }}</a-button>
            </a-popconfirm>
          </template>
          <a-row :gutter="12">
            <a-col :xs="24" :sm="12">
              <a-form-item :label="$t('Name')" required>
                <a-input v-model:value="profile.name" :maxlength="120" />
              </a-form-item>
            </a-col>
            <a-col :xs="24" :sm="12">
              <a-form-item :label="$t('Bkm_Staff_Title')" :extra="$t('Bkm_Staff_Title_Help')">
                <a-input v-model:value="profile.title" :maxlength="120" />
              </a-form-item>
            </a-col>
            <a-col :xs="24" :sm="12">
              <a-form-item :label="$t('Bkm_Calendar_Colour')">
                <div class="swatches" role="radiogroup" :aria-label="$t('Bkm_Calendar_Colour')">
                  <button
                    v-for="c in COLORS" :key="c" type="button" class="swatch" :style="{ background: c }" role="radio"
                    :aria-checked="profile.color === c" :aria-label="c" :class="{ on: profile.color === c }" @click="profile.color = c"
                  />
                </div>
              </a-form-item>
            </a-col>
            <a-col :xs="24" :sm="12">
              <a-form-item :label="$t('Branch')">
                <a-select
                  v-model:value="profile.warehouse_id" allow-clear :placeholder="$t('Bkm_All_Branches')"
                  :options="warehouses.map((w) => ({ value: w.id, label: w.name }))"
                />
              </a-form-item>
            </a-col>
            <a-col :xs="24" :sm="12">
              <a-form-item :label="$t('Photo')" :extra="$t('Bkm_Photo_Help')">
                <div class="photo-row">
                  <img v-if="photoPreview" :src="photoPreview" alt="" class="avatar avatar-lg">
                  <span v-else class="avatar avatar-lg" :style="{ background: profile.color }">{{ initial(profile.name) }}</span>
                  <a-upload :show-upload-list="false" accept="image/*" :before-upload="pickPhoto">
                    <a-button size="small">{{ $t('Bkm_Choose_Photo') }}</a-button>
                  </a-upload>
                  <a-button v-if="photoPreview" size="small" type="link" danger @click="dropPhoto">{{ $t('Remove') }}</a-button>
                </div>
              </a-form-item>
            </a-col>
            <a-col :xs="24" :sm="12">
              <a-form-item :label="$t('Bkm_Max_Per_Day')" :extra="$t('Bkm_Max_Per_Day_Help')">
                <a-input-number v-model:value="profile.max_per_day" :min="1" :max="200" :precision="0" style="width: 140px" />
              </a-form-item>
            </a-col>
          </a-row>
          <div class="switches">
            <label class="switch-row"><a-switch v-model:checked="profile.active" /> {{ $t('Bkm_Staff_Active') }}</label>
            <label class="switch-row"><a-switch v-model:checked="profile.bookable_online" :disabled="!profile.active" /> {{ $t('Bkm_Bookable_Online') }}</label>
            <label class="switch-row"><a-switch v-model:checked="profile.show_on_website" :disabled="!profile.active" /> {{ $t('Bkm_Show_Team_Member') }}</label>
          </div>
          <div class="card-actions">
            <a-button type="primary" :loading="savingProfile" @click="saveProfile">{{ $t('Save') }}</a-button>
          </div>
        </a-card>

        <!-- Weekly hours -->
        <a-card size="small" class="block">
          <template #title><span class="card-title"><ClockCircleOutlined /> {{ $t('Bkm_Weekly_Hours') }}</span></template>
          <template #extra>
            <div class="extra-actions">
              <a-button v-if="current.employee_id" size="small" :loading="loadingShift" @click="copyShift">{{ $t('Bkm_Copy_Shift') }}</a-button>
              <a-button size="small" @click="applyMondayToAll">{{ $t('Bkm_Apply_Monday') }}</a-button>
            </div>
          </template>
          <div class="table-wrap">
            <table class="hours">
              <thead>
                <tr>
                  <th scope="col">{{ $t('Bkm_Day') }}</th>
                  <th scope="col">{{ $t('Bkm_Works') }}</th>
                  <th scope="col">{{ $t('Bkm_From') }}</th>
                  <th scope="col">{{ $t('Bkm_To') }}</th>
                  <th scope="col">{{ $t('Bkm_Break') }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="wd in WEEKDAYS" :key="wd" :class="{ off: !days[wd].enabled }">
                  <th scope="row">{{ dayName(wd) }}</th>
                  <td><a-switch v-model:checked="days[wd].enabled" size="small" :aria-label="dayName(wd)" /></td>
                  <td>
                    <a-time-picker
                      v-model:value="days[wd].start" value-format="HH:mm" format="HH:mm" :minute-step="5" :allow-clear="false"
                      :disabled="!days[wd].enabled" :aria-label="$t('Bkm_From')" style="width: 100px"
                    />
                  </td>
                  <td>
                    <a-time-picker
                      v-model:value="days[wd].end" value-format="HH:mm" format="HH:mm" :minute-step="5" :allow-clear="false"
                      :disabled="!days[wd].enabled" :aria-label="$t('Bkm_To')" style="width: 100px"
                    />
                  </td>
                  <td>
                    <div class="break-cell">
                      <a-time-picker
                        v-model:value="days[wd].break_start" value-format="HH:mm" format="HH:mm" :minute-step="5"
                        :disabled="!days[wd].enabled" :placeholder="$t('Bkm_No_Break')" :aria-label="$t('Bkm_Break_Start')" style="width: 100px"
                      />
                      <span class="muted">–</span>
                      <a-time-picker
                        v-model:value="days[wd].break_end" value-format="HH:mm" format="HH:mm" :minute-step="5"
                        :disabled="!days[wd].enabled || !days[wd].break_start" :aria-label="$t('Bkm_Break_End')" style="width: 100px"
                      />
                    </div>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
          <a-alert v-if="hoursError" type="error" show-icon style="margin-top: 12px" :message="hoursError" />
          <div class="card-actions">
            <a-button type="primary" :loading="savingHours" @click="saveHours">{{ $t('Bkm_Save_Hours') }}</a-button>
          </div>
        </a-card>

        <a-row :gutter="16">
          <!-- Days off -->
          <a-col :xs="24" :xl="12">
            <a-card size="small" class="block">
              <template #title><span class="card-title"><CalendarOutlined /> {{ $t('Bkm_Days_Off') }}</span></template>
              <div v-for="o in current.time_off" :key="'o' + o.id" class="off-row">
                <span>
                  {{ offLabel(o) }}
                  <span v-if="o.reason" class="muted"> · {{ o.reason }}</span>
                </span>
                <a-popconfirm :title="$t('Bkm_Remove_Day_Off')" @confirm="removeOff(o)">
                  <a-button size="small" type="text" danger :aria-label="$t('Remove')"><DeleteOutlined /></a-button>
                </a-popconfirm>
              </div>
              <div v-for="(l, i) in current.hrm_leave" :key="'l' + i" class="off-row">
                <span>{{ rangeLabel(l.start_date, l.end_date) }}</span>
                <span class="muted small">{{ $t('Bkm_Hrm_Leave') }}</span>
              </div>
              <p v-if="!current.time_off.length && !current.hrm_leave.length" class="muted small">{{ $t('Bkm_No_Days_Off') }}</p>

              <div class="off-form">
                <a-range-picker v-model:value="offForm.range" value-format="YYYY-MM-DD" style="width: 100%" :aria-label="$t('Bkm_Days_Off')" />
                <div class="off-times">
                  <a-time-picker v-model:value="offForm.from_time" value-format="HH:mm" format="HH:mm" :minute-step="5" :placeholder="$t('Bkm_Off_From')" style="width: 120px" />
                  <a-time-picker v-model:value="offForm.to_time" value-format="HH:mm" format="HH:mm" :minute-step="5" :placeholder="$t('Bkm_Off_Until')" style="width: 120px" />
                </div>
                <a-input v-model:value="offForm.reason" :maxlength="191" :placeholder="$t('Reason')" />
                <p class="muted small no-mb">{{ $t('Bkm_Off_Times_Help') }}</p>
                <a-button :loading="savingOff" :disabled="!offForm.range || !offForm.range[0]" @click="addOff">
                  <template #icon><PlusOutlined /></template>{{ $t('Bkm_Add_Day_Off') }}
                </a-button>
              </div>
            </a-card>
          </a-col>

          <!-- Services -->
          <a-col :xs="24" :xl="12">
            <a-card size="small" class="block">
              <template #title><span class="card-title"><UnorderedListOutlined /> {{ $t('Bkm_Staff_Services') }}</span></template>
              <p class="muted small">{{ $t('Bkm_Staff_Services_Help') }}</p>
              <a-empty v-if="!services.length" :description="$t('Bkm_No_Bookable_Services')">
                <router-link to="/bookings/services">{{ $t('Bkm_Services') }}</router-link>
              </a-empty>
              <a-checkbox-group v-else v-model:value="serviceIds" class="svc-checks">
                <a-checkbox v-for="p in services" :key="p.id" :value="p.id">{{ p.name }}</a-checkbox>
              </a-checkbox-group>
              <div v-if="services.length" class="card-actions">
                <a-button :loading="savingServices" @click="saveServices">{{ $t('Save') }}</a-button>
              </div>
            </a-card>
          </a-col>
        </a-row>

        <!-- Free times preview -->
        <a-card size="small" class="block">
          <template #title><span class="card-title"><SearchOutlined /> {{ $t('Bkm_Check_Free_Times') }}</span></template>
          <div class="preview-bar">
            <a-select
              v-model:value="preview.product_id" :placeholder="$t('Bkm_Pick_Service')" style="width: 240px"
              :options="services.map((p) => ({ value: p.id, label: p.name }))" @change="loadPreview"
            />
            <a-date-picker v-model:value="preview.date" value-format="YYYY-MM-DD" :allow-clear="false" @change="loadPreview" />
          </div>
          <a-spin :spinning="preview.loading">
            <p v-if="!preview.product_id" class="muted small">{{ $t('Bkm_Check_Free_Times_Help') }}</p>
            <template v-else-if="preview.done">
              <div v-if="preview.slots.length" class="slot-chips">
                <span v-for="s in preview.slots" :key="s.time" class="chip">
                  {{ s.time }}<template v-if="s.seats_left !== null"> · {{ tp('Bkm_Seats_Left', '{n} left', { n: s.seats_left }) }}</template>
                </span>
              </div>
              <p v-else class="muted">{{ previewEmpty }}</p>
            </template>
          </a-spin>
        </a-card>
      </a-col>
    </a-row>

    <!-- Add staff -->
    <a-modal
      v-model:open="addOpen" :title="$t('Bkm_Add_Staff')" :ok-text="$t('Bkm_Add_Staff')" :confirm-loading="adding"
      :ok-button-props="{ disabled: !canAdd }" @ok="addStaff"
    >
      <a-form layout="vertical">
        <a-form-item :label="$t('Bkm_Staff_Source')">
          <a-radio-group v-model:value="addForm.source" button-style="solid">
            <a-radio-button value="employee">{{ $t('Employee') }}</a-radio-button>
            <a-radio-button value="user">{{ $t('User') }}</a-radio-button>
            <a-radio-button value="name">{{ $t('Bkm_Just_A_Name') }}</a-radio-button>
          </a-radio-group>
        </a-form-item>
        <a-form-item v-if="addForm.source === 'employee'" :label="$t('Employee')" :extra="$t('Bkm_Employee_Help')">
          <a-select
            v-model:value="addForm.employee_id" show-search option-filter-prop="label" :placeholder="$t('Bkm_Pick_Employee')"
            :options="employees.map((e) => ({ value: e.id, label: e.name, disabled: !!e.staff_id }))"
            :not-found-content="$t('Bkm_No_Employees')"
          />
        </a-form-item>
        <a-form-item v-if="addForm.source === 'user'" :label="$t('User')">
          <a-select
            v-model:value="addForm.user_id" show-search option-filter-prop="label" :placeholder="$t('Bkm_Pick_User')"
            :options="users.map((u) => ({ value: u.id, label: u.name, disabled: !!u.staff_id }))"
          />
        </a-form-item>
        <a-form-item :label="$t('Name')" :required="addForm.source === 'name'" :extra="addForm.source === 'name' ? '' : $t('Bkm_Name_Override_Help')">
          <a-input v-model:value="addForm.name" :maxlength="120" />
        </a-form-item>
        <a-form-item :label="$t('Bkm_Staff_Title')">
          <a-input v-model:value="addForm.title" :maxlength="120" />
        </a-form-item>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup>
/**
 * Booking Management › Staff & working hours (permission bookings_staff).
 * GET bookings/staff → {ready, staff, employees, users, warehouses, services, holidays};
 * POST bookings/staff, POST bookings/staff/{id}/update (multipart), DELETE bookings/staff/{id},
 * PUT …/hours, GET …/shift-hours, POST/DELETE …/time-off, PUT …/services;
 * free-times preview from GET bookings/availability.
 */
import { ref, computed, onMounted } from 'vue';
import { message } from 'ant-design-vue';
import {
  PlusOutlined, UserOutlined, ClockCircleOutlined, CalendarOutlined, UnorderedListOutlined, SearchOutlined, DeleteOutlined,
} from '@ant-design/icons-vue';
import dayjs from 'dayjs';
import PageHeader from '../../components/PageHeader.vue';
import http from '../../lib/http';
import { t, i18n } from '../../i18n';

const WEEKDAYS = [1, 2, 3, 4, 5, 6, 7];
const COLORS = ['#6d28d9', '#1f5fa6', '#0b6e4f', '#b03a64', '#b45309', '#0f766e', '#4338ca', '#9d174d'];

const loading = ref(false);
const loaded = ref(false);
const ready = ref(true);
const staff = ref([]);
const employees = ref([]);
const users = ref([]);
const warehouses = ref([]);
const services = ref([]);
const holidays = ref([]);
const selectedId = ref(null);
const current = computed(() => staff.value.find((s) => s.id === selectedId.value) || null);

/** t() with named {params}; the English fallback gets the same substitution. */
function tp(key, fallback, params) {
  const out = i18n.global.t(key, params);
  if (out !== key) return out;
  return Object.entries(params).reduce((s, [k, v]) => s.replace(`{${k}}`, v), fallback);
}
function locale() {
  const l = i18n.global.locale;
  return (typeof l === 'string' ? l : l?.value) || 'en';
}
/** ISO weekday name in the user's language (2024-01-01 was a Monday). */
function dayName(wd) {
  try {
    return new Intl.DateTimeFormat(locale(), { weekday: 'long' }).format(new Date(2024, 0, wd));
  } catch (e) {
    return String(wd);
  }
}
function fmtDate(d) {
  try {
    return new Intl.DateTimeFormat(locale(), { weekday: 'short', day: 'numeric', month: 'short' }).format(dayjs(d).toDate());
  } catch (e) {
    return d;
  }
}
function rangeLabel(a, b) {
  return a === b ? fmtDate(a) : `${fmtDate(a)} – ${fmtDate(b)}`;
}
function offLabel(o) {
  let s = rangeLabel(o.start_date, o.end_date);
  if (o.from_time) s += ` · ${tp('Bkm_From_Time', 'from {t}', { t: o.from_time })}`;
  if (o.to_time) s += ` · ${tp('Bkm_Until_Time', 'until {t}', { t: o.to_time })}`;
  return s;
}
function initial(name) {
  return String(name || '?').trim().charAt(0).toUpperCase() || '?';
}
function staffLine(s) {
  const src = s.employee_id ? t('Employee') : s.user_id ? t('User') : t('Bkm_Name_Only', 'Name only');
  const parts = [src];
  if (s.title) parts.push(s.title);
  parts.push(s.service_ids.length ? tp('Bkm_N_Services', '{n} services', { n: s.service_ids.length }) : t('Bkm_Any_Service', 'any service'));
  return parts.join(' · ');
}

async function load(keepSelection = true) {
  loading.value = true;
  try {
    const data = await http.get('bookings/staff');
    ready.value = data?.ready !== false;
    staff.value = data?.staff || [];
    employees.value = data?.employees || [];
    users.value = data?.users || [];
    warehouses.value = data?.warehouses || [];
    services.value = data?.services || [];
    holidays.value = data?.holidays || [];
    if (!keepSelection || !staff.value.some((s) => s.id === selectedId.value)) {
      selectedId.value = staff.value[0]?.id ?? null;
    }
    fillForms();
    loaded.value = true;
  } catch (e) {
    message.error(t('Bkm_Could_Not_Load_Staff'));
  } finally {
    loading.value = false;
  }
}

function select(id) {
  selectedId.value = id;
  fillForms();
}

/** Replace one staff member in the list from an API response. */
function replace(s) {
  if (!s) return;
  const i = staff.value.findIndex((x) => x.id === s.id);
  if (i >= 0) staff.value.splice(i, 1, s);
  else staff.value.push(s);
}

// ----- forms bound to the selected person -----
const profile = ref({});
const days = ref(Object.fromEntries(WEEKDAYS.map((wd) => [wd, { enabled: false, start: '09:00', end: '17:00', break_start: null, break_end: null }])));
const serviceIds = ref([]);
const photoFile = ref(null);
const photoPreview = ref(null);
const removePhoto = ref(false);
const offForm = ref({ range: null, from_time: null, to_time: null, reason: '' });
const preview = ref({ product_id: undefined, date: dayjs().format('YYYY-MM-DD'), slots: [], closed: {}, loading: false, done: false, bookable: true });

function fillForms() {
  const s = current.value;
  if (!s) return;
  profile.value = {
    name: s.name, title: s.title, color: s.color, warehouse_id: s.warehouse_id || undefined, bookable_online: s.bookable_online,
    show_on_website: s.show_on_website, max_per_day: s.max_per_day || null, sort_order: s.sort_order, active: s.active,
  };
  days.value = Object.fromEntries(WEEKDAYS.map((wd) => [wd, { ...s.days[wd] }]));
  serviceIds.value = [...s.service_ids];
  photoFile.value = null;
  removePhoto.value = false;
  photoPreview.value = s.photo_url;
  offForm.value = { range: null, from_time: null, to_time: null, reason: '' };
  preview.value = { ...preview.value, slots: [], done: false };
  if (preview.value.product_id) loadPreview();
}

// ----- profile -----
const savingProfile = ref(false);
function pickPhoto(file) {
  if (!file.type?.startsWith('image/')) {
    message.error(t('Bkm_Photo_Not_Image'));
    return false;
  }
  photoFile.value = file;
  removePhoto.value = false;
  photoPreview.value = URL.createObjectURL(file);
  return false;
}
function dropPhoto() {
  photoFile.value = null;
  photoPreview.value = null;
  removePhoto.value = true;
}
async function saveProfile() {
  if (!String(profile.value.name || '').trim()) {
    message.error(t('Field_is_required'));
    return;
  }
  savingProfile.value = true;
  try {
    const fd = new FormData();
    const p = profile.value;
    fd.append('name', p.name.trim());
    fd.append('title', p.title || '');
    fd.append('color', p.color);
    if (p.warehouse_id) fd.append('warehouse_id', p.warehouse_id);
    fd.append('bookable_online', p.bookable_online ? '1' : '0');
    fd.append('show_on_website', p.show_on_website ? '1' : '0');
    if (p.max_per_day) fd.append('max_per_day', p.max_per_day);
    fd.append('sort_order', p.sort_order ?? 0);
    fd.append('active', p.active ? '1' : '0');
    if (photoFile.value) fd.append('photo_file', photoFile.value);
    if (removePhoto.value) fd.append('remove_photo', '1');
    const data = await http.postForm(`bookings/staff/${selectedId.value}/update`, fd);
    replace(data?.staff);
    fillForms();
    message.success(t('Updated_in_successfully'));
  } catch (e) {
    showError(e);
  } finally {
    savingProfile.value = false;
  }
}
const removeText = computed(() => {
  const n = current.value?.upcoming_bookings || 0;
  return n ? tp('Bkm_Remove_Staff_Upcoming', 'Remove {name}? Their {n} upcoming bookings stay in the calendar.', { name: current.value.name, n })
    : tp('Bkm_Remove_Staff', 'Remove {name} from bookable staff?', { name: current.value?.name || '' });
});
async function removeStaff() {
  try {
    await http.delete(`bookings/staff/${selectedId.value}`);
    message.success(t('Updated_in_successfully'));
    await load(false);
  } catch (e) {
    showError(e);
  }
}

// ----- hours -----
const savingHours = ref(false);
const loadingShift = ref(false);
const hoursError = computed(() => {
  for (const wd of WEEKDAYS) {
    const d = days.value[wd];
    if (!d.enabled) continue;
    if (!d.start || !d.end || d.end <= d.start) return tp('Bkm_Hours_Order', '{day}: the end must be after the start.', { day: dayName(wd) });
    if (d.break_start && (!d.break_end || d.break_end <= d.break_start || d.break_start < d.start || d.break_end > d.end)) {
      return tp('Bkm_Break_Inside', '{day}: the break must fall inside the working hours.', { day: dayName(wd) });
    }
  }
  return '';
});
function applyMondayToAll() {
  const mon = days.value[1];
  WEEKDAYS.forEach((wd) => {
    if (wd !== 1 && days.value[wd].enabled) days.value[wd] = { ...mon };
  });
}
async function copyShift() {
  loadingShift.value = true;
  try {
    const data = await http.get(`bookings/staff/${selectedId.value}/shift-hours`);
    if (data?.days) {
      days.value = Object.fromEntries(WEEKDAYS.map((wd) => [wd, { ...data.days[wd] }]));
      message.info(t('Bkm_Shift_Copied'));
    }
  } catch (e) {
    showError(e);
  } finally {
    loadingShift.value = false;
  }
}
async function saveHours() {
  if (hoursError.value) {
    message.error(hoursError.value);
    return;
  }
  savingHours.value = true;
  try {
    const payload = Object.fromEntries(WEEKDAYS.map((wd) => {
      const d = days.value[wd];
      return [wd, d.enabled ? { start: d.start, end: d.end, break_start: d.break_start || null, break_end: d.break_start ? d.break_end : null } : null];
    }));
    const data = await http.put(`bookings/staff/${selectedId.value}/hours`, { days: payload });
    replace(data?.staff);
    fillForms();
    message.success(t('Updated_in_successfully'));
  } catch (e) {
    showError(e);
  } finally {
    savingHours.value = false;
  }
}

// ----- days off -----
const savingOff = ref(false);
async function addOff() {
  const [start, end] = offForm.value.range || [];
  if (!start) return;
  savingOff.value = true;
  try {
    const data = await http.post(`bookings/staff/${selectedId.value}/time-off`, {
      start_date: start, end_date: end || start, from_time: offForm.value.from_time || null,
      to_time: offForm.value.to_time || null, reason: offForm.value.reason || null,
    });
    replace(data?.staff);
    fillForms();
    message.success(t('Updated_in_successfully'));
  } catch (e) {
    showError(e);
  } finally {
    savingOff.value = false;
  }
}
async function removeOff(o) {
  try {
    const data = await http.delete(`bookings/staff/${selectedId.value}/time-off/${o.id}`);
    replace(data?.staff);
    fillForms();
  } catch (e) {
    showError(e);
  }
}

// ----- services -----
const savingServices = ref(false);
async function saveServices() {
  savingServices.value = true;
  try {
    const data = await http.put(`bookings/staff/${selectedId.value}/services`, { product_ids: serviceIds.value });
    replace(data?.staff);
    fillForms();
    message.success(t('Updated_in_successfully'));
  } catch (e) {
    showError(e);
  } finally {
    savingServices.value = false;
  }
}

// ----- free times preview -----
const CLOSED_KEYS = {
  day_off: ['Bkm_Closed_Day_Off', 'Not working this day.'],
  holiday: ['Bkm_Closed_Holiday', 'Company holiday.'],
  leave: ['Bkm_Closed_Leave', 'On approved leave.'],
  time_off: ['Bkm_Closed_Time_Off', 'Day off.'],
};
const previewEmpty = computed(() => {
  if (!preview.value.bookable) return t('Bkm_Preview_Not_Bookable');
  const reason = preview.value.closed?.[selectedId.value];
  if (reason && CLOSED_KEYS[reason]) return t(...CLOSED_KEYS[reason]);
  return t('Bkm_Preview_None');
});
async function loadPreview() {
  if (!preview.value.product_id || !selectedId.value) return;
  preview.value.loading = true;
  try {
    const data = await http.get('bookings/availability', {
      product_id: preview.value.product_id, date: preview.value.date, staff_id: selectedId.value,
    });
    preview.value = {
      ...preview.value, slots: data?.slots || [], closed: data?.closed || {}, bookable: data?.bookable !== false, done: true,
    };
  } catch (e) {
    showError(e);
  } finally {
    preview.value.loading = false;
  }
}

// ----- add staff -----
const addOpen = ref(false);
const adding = ref(false);
const addForm = ref({ source: 'employee', employee_id: undefined, user_id: undefined, name: '', title: '' });
const canAdd = computed(() => {
  const f = addForm.value;
  if (f.source === 'employee') return !!f.employee_id;
  if (f.source === 'user') return !!f.user_id;
  return !!String(f.name || '').trim();
});
function openAdd() {
  addForm.value = { source: employees.value.length ? 'employee' : 'name', employee_id: undefined, user_id: undefined, name: '', title: '' };
  addOpen.value = true;
}
async function addStaff() {
  const f = addForm.value;
  adding.value = true;
  try {
    const data = await http.post('bookings/staff', {
      employee_id: f.source === 'employee' ? f.employee_id : null,
      user_id: f.source === 'user' ? f.user_id : null,
      name: String(f.name || '').trim() || null,
      title: String(f.title || '').trim() || null,
    });
    addOpen.value = false;
    message.success(t('Successfully_Created'));
    await load();
    if (data?.staff?.id) select(data.staff.id);
  } catch (e) {
    showError(e);
  } finally {
    adding.value = false;
  }
}

function showError(e) {
  const errors = e?.data?.errors;
  const first = errors ? Object.values(errors)[0]?.[0] : null;
  message.error(first || e?.data?.message || t('Bkm_Could_Not_Save_Staff'));
}

onMounted(() => load(false));
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
.empty {
  padding: 32px 16px;
}
.card-title {
  display: inline-flex;
  align-items: center;
  gap: 8px;
}
.muted { color: rgba(0, 0, 0, 0.55); }
.small { font-size: 12px; }
.no-mb { margin-bottom: 0; }
.staff-list {
  list-style: none;
  margin: 0;
  padding: 0;
}
.staff-row {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 12px 16px;
  border-bottom: 1px solid rgba(128, 128, 128, 0.14);
  cursor: pointer;
}
.staff-row:last-child {
  border-bottom: 0;
}
.staff-row.active {
  background: rgba(var(--app-primary-rgb, 109, 40, 217), 0.07);
  box-shadow: inset 3px 0 0 var(--app-primary, #6d28d9);
}
.staff-row.inactive .staff-name {
  opacity: 0.6;
}
.staff-row:focus-visible {
  outline: 2px solid var(--app-primary, #6d28d9);
  outline-offset: -2px;
}
.avatar {
  width: 40px;
  height: 40px;
  flex: none;
  border-radius: 50%;
  object-fit: cover;
  color: #fff;
  font-weight: 700;
  display: inline-flex;
  align-items: center;
  justify-content: center;
}
.avatar-lg {
  width: 56px;
  height: 56px;
  font-size: 20px;
}
.staff-text {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
}
.staff-name {
  font-weight: 600;
}
.swatches {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}
.swatch {
  width: 28px;
  height: 28px;
  border-radius: 50%;
  border: 2px solid transparent;
  cursor: pointer;
  padding: 0;
}
.swatch.on {
  border-color: #fff;
  box-shadow: 0 0 0 2px rgba(0, 0, 0, 0.55);
}
.photo-row {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
}
.switches {
  display: flex;
  flex-direction: column;
  gap: 10px;
}
.switch-row {
  display: flex;
  align-items: center;
  gap: 10px;
  cursor: pointer;
}
.card-actions {
  display: flex;
  justify-content: flex-end;
  margin-top: 12px;
}
.extra-actions {
  display: flex;
  gap: 6px;
  flex-wrap: wrap;
}
.table-wrap {
  overflow-x: auto;
}
.hours {
  width: 100%;
  min-width: 560px;
  border-collapse: collapse;
}
.hours th,
.hours td {
  padding: 6px 8px;
  text-align: start;
  border-top: 1px solid rgba(128, 128, 128, 0.14);
}
.hours thead th {
  border-top: 0;
  font-weight: 600;
  font-size: 13px;
  color: rgba(0, 0, 0, 0.55);
}
.hours tbody th {
  font-weight: 500;
}
.hours tr.off th {
  opacity: 0.55;
}
.break-cell {
  display: flex;
  align-items: center;
  gap: 6px;
}
.off-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 8px;
  padding: 6px 0;
  border-bottom: 1px solid rgba(128, 128, 128, 0.12);
}
.off-form {
  display: flex;
  flex-direction: column;
  gap: 8px;
  margin-top: 12px;
}
.off-times {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
}
.svc-checks {
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.preview-bar {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-bottom: 12px;
}
.slot-chips {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}
.chip {
  padding: 4px 10px;
  border-radius: 8px;
  border: 1px solid rgba(var(--app-primary-rgb, 109, 40, 217), 0.4);
  background: rgba(var(--app-primary-rgb, 109, 40, 217), 0.06);
  font-variant-numeric: tabular-nums;
}
</style>
