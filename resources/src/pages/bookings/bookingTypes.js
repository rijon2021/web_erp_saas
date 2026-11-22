import { ref, computed } from 'vue';
import { Scissors, Stethoscope, Briefcase, House, Car, Dumbbell, Camera, CalendarCheck } from 'lucide-vue-next';
import http from '../../lib/http';
import { t } from '../../i18n';

/**
 * The tenant's booking business types (Booking Settings › Business type) and
 * each type's booking-form fields, from GET bookings/types
 * (App\Support\BookingTypes).
 *
 * Booking pages that offer a type — settings, services, the website form
 * preview — read it here. Fetched once per page load and shared; call
 * `reloadBookingTypes()` (or `setBookingTypes()` with a fresh registry)
 * after the settings change.
 *
 * Labels: `typeLabel(type)` → Bkm_Type_<K>, `fieldLabel(f)` → Bkm_Field_<K>,
 * `optionLabel(v)` → Bkm_Opt_<Value>, each falling back to the English text
 * the API sends.
 */

export const TYPE_ICONS = {
  scissors: Scissors, stethoscope: Stethoscope, briefcase: Briefcase, house: House,
  car: Car, dumbbell: Dumbbell, camera: Camera,
};

const state = ref({ types: {}, common: [], enabled: [], chosen: [], field_modes: {}, ready: false });
const loaded = ref(false);
let pending = null;

export function setBookingTypes(registry) {
  if (registry && registry.types) {
    state.value = { ...state.value, ...registry };
  }
  loaded.value = true;
}

export function reloadBookingTypes() {
  pending = http.get('bookings/types')
    .then((data) => {
      setBookingTypes(data);
      return state.value;
    })
    .catch(() => {
      loaded.value = true;
      return state.value;
    });
  return pending;
}

/** Studly-case like Laravel's Str::studly: "home_services" → "HomeServices". */
export function studly(value) {
  return String(value || '')
    .split(/[_\-\s]+/)
    .filter(Boolean)
    .map((w) => w.charAt(0).toUpperCase() + w.slice(1))
    .join('');
}

export function typeLabel(type) {
  const def = state.value.types?.[type];
  return t(`Bkm_Type_${studly(type)}`, def?.label || type);
}

export function typeHint(type) {
  const def = state.value.types?.[type];
  return t(`Bkm_Type_${studly(type)}_Hint`, def?.hint || '');
}

export function typeIcon(type) {
  return TYPE_ICONS[state.value.types?.[type]?.icon] || CalendarCheck;
}

export function fieldLabel(f) {
  return t(`Bkm_Field_${f.k || studly(f.id)}`, f.label);
}

export function optionLabel(value) {
  const fallback = String(value || '').replace(/_/g, ' ');
  return t(`Bkm_Opt_${studly(value)}`, fallback.charAt(0).toUpperCase() + fallback.slice(1));
}

export function useBookingTypes() {
  if (!pending) reloadBookingTypes();

  const typeList = computed(() => Object.values(state.value.types || {}));
  const enabled = computed(() => state.value.enabled || []);
  const isEnabled = (type) => enabled.value.includes(type);
  const fields = (type) => state.value.types?.[type]?.fields || [];
  const common = computed(() => state.value.common || []);

  return {
    state, loaded, typeList, enabled, isEnabled, fields, common,
    ready: () => pending,
  };
}
