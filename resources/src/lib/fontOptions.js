/**
 * Grouped <a-select> options for the two font pickers:
 *   - System Settings → Dashboard font family (partials/tenant-font.blade.php)
 *   - Store Settings  → Font family           (store/partials/store-font.blade.php)
 *
 * `payload` is the `font_options` object served by get_Settings_data_api and
 * admin/store/settings (Setting::fontOptions):
 *   { google: [{ value, label, arabic }], system: [{ value, label }] }
 * Falls back to the system stacks alone when the API did not send it.
 *
 * A stored value missing from every list (a legacy free-text stack) is kept as
 * an extra "Custom" option so the select still shows what is saved.
 */
import { t as tf } from '../i18n';

export const SYSTEM_FONT_FALLBACK = [
  { get label() { return tf('Font_Inherit', 'Inherit'); }, value: 'inherit' },
  { label: 'Arial', value: 'Arial, sans-serif' },
  { label: 'Georgia', value: 'Georgia, serif' },
  { label: 'Times New Roman', value: '"Times New Roman", Times, serif' },
  { label: 'Verdana', value: 'Verdana, Geneva, sans-serif' },
  { label: 'Tahoma', value: 'Tahoma, Geneva, sans-serif' },
  { label: 'Segoe UI', value: '"Segoe UI", Tahoma, sans-serif' },
  { label: 'System UI', value: 'system-ui, -apple-system, sans-serif' },
];

export function buildFontOptions(payload, t, { current = '', defaultLabel = null } = {}) {
  const google = Array.isArray(payload && payload.google) ? payload.google : [];
  const system = Array.isArray(payload && payload.system) && payload.system.length
    ? payload.system
    : SYSTEM_FONT_FALLBACK;
  const toOpt = f => ({ label: f.label, value: f.value });

  const groups = [];
  if (defaultLabel !== null) groups.push({ label: defaultLabel, value: '' });

  const arabic = google.filter(f => f.arabic).map(toOpt);
  const latin = google.filter(f => !f.arabic).map(toOpt);
  if (arabic.length) groups.push({ label: t('Google_Fonts_Arabic'), options: arabic });
  if (latin.length) groups.push({ label: t('Google_Fonts_Latin'), options: latin });
  groups.push({ label: t('System_Fonts'), options: system.map(toOpt) });

  const cur = String(current || '').trim();
  const known = new Set([...google, ...system].map(f => f.value));
  if (cur && !known.has(cur)) groups.push({ label: t('Custom'), options: [{ label: cur, value: cur }] });

  return groups;
}
