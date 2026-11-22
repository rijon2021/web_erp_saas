import { ref, computed } from 'vue';
import http from '../../lib/http';
import { t } from '../../i18n';
import { RENTAL_TYPES, tr } from './rentalOptions';

/**
 * The tenant's rental business types (Rental Settings › Business type) and
 * each type's extra properties, from GET rental/types (App\Support\RentalTypes).
 *
 * Every rental page that offers a type — item form, item list, categories,
 * availability, reports — reads `enabledOptions` here, so a car hire tenant
 * never sees property or equipment choices. Fetched once per page load and
 * shared; call `reloadRentalTypes()` after the settings change.
 *
 * Labels: `specLabel(f)` → Rental_Spec_<K>, `optionLabel(v)` →
 * Rental_Opt_<Value>, `fieldLabel(f)` → Rental_Field_<K>, each falling back to
 * the English text the API sends.
 */

const state = ref({ types: {}, enabled: RENTAL_TYPES.map((o) => o.value), chosen: [], field_modes: {}, website_ready: false });
const loaded = ref(false);
let pending = null;

export function reloadRentalTypes() {
  pending = http.get('rental/types')
    .then((data) => {
      if (data && data.types) {
        state.value = { ...state.value, ...data };
      }
      loaded.value = true;
      return state.value;
    })
    .catch(() => {
      loaded.value = true;
      return state.value;
    });
  return pending;
}

/** Studly-case like Laravel's Str::studly: "four_wheel_drive" → "FourWheelDrive". */
export function studly(value) {
  return String(value || '')
    .split(/[_\-\s]+/)
    .filter(Boolean)
    .map((w) => w.charAt(0).toUpperCase() + w.slice(1))
    .join('');
}

export function specLabel(f) {
  return t(`Rental_Spec_${f.k || studly(f.id)}`, f.label);
}

export function fieldLabel(f) {
  return t(`Rental_Field_${f.k || studly(f.id)}`, f.label);
}

export function optionLabel(value) {
  const fallback = String(value || '').replace(/_/g, ' ');
  return t(`Rental_Opt_${studly(value)}`, fallback.charAt(0).toUpperCase() + fallback.slice(1));
}

export function useRentalTypes() {
  if (!pending) reloadRentalTypes();

  const enabled = computed(() => state.value.enabled || []);
  /** Type options limited to the tenant's business types (keeps `keep` if given — an item's current type). */
  const enabledOptions = (keep = null) => tr(RENTAL_TYPES).filter((o) => enabled.value.includes(o.value) || o.value === keep);
  const specFields = (type) => (state.value.types?.[type]?.specs) || [];
  const bookingFields = (type) => (state.value.types?.[type]?.booking) || [];
  const isEnabled = (type) => enabled.value.includes(type);

  return {
    state, loaded, enabled, enabledOptions, specFields, bookingFields, isEnabled,
    ready: () => pending,
  };
}
