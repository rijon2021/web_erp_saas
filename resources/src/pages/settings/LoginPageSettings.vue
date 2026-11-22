<template>
  <div class="page">
    <PageHeader :title="$t('Sys_Login_Page')" :breadcrumb="[$t('Settings'), $t('Sys_Login_Page')]" />

    <div v-if="loading" style="display: flex; justify-content: center; padding: 96px 0">
      <a-spin size="large" />
    </div>

    <a-row v-else :gutter="16" class="lp">
      <!-- ============================ Live preview ============================ -->
      <a-col :xs="24" :xxl="14" class="lp-preview-col">
        <a-card size="small" class="lp-preview-card">
          <template #title>
            <span><EyeOutlined /> {{ $t('Live_Preview') }}</span>
          </template>
          <template #extra>
            <a-segmented v-model:value="device" :options="deviceOptions" size="small" />
          </template>
          <div ref="stageEl" class="lp-stage" :style="{ height: frameHeight * scale + 'px' }">
            <iframe
              ref="frameEl"
              :src="previewUrl"
              :title="$t('Login_Page_Preview_Frame')"
              class="lp-frame"
              :style="{
                width: frameWidth + 'px',
                height: frameHeight + 'px',
                transform: `scale(${scale})`,
                left: Math.max(0, (stageWidth - frameWidth * scale) / 2) + 'px',
              }"
            />
          </div>
          <div class="lp-hint">{{ $t('Login_Page_Hint') }}</div>
        </a-card>
      </a-col>

      <!-- ============================== Options ============================== -->
      <a-col :xs="24" :xxl="10">
        <a-card size="small">
          <a-form layout="vertical">
            <a-tabs v-model:activeKey="tab" size="small">
              <!-- Layout -->
              <a-tab-pane key="layout" :tab="$t('Login_Page_Tab_Layout')">
                <a-form-item :label="$t('Login_Page_Layout')">
                  <a-radio-group v-model:value="options.layout" button-style="solid">
                    <a-radio-button value="split">{{ $t('Login_Page_Image_Left') }}</a-radio-button>
                    <a-radio-button value="split_reverse">{{ $t('Login_Page_Image_Right') }}</a-radio-button>
                    <a-radio-button value="centered">{{ $t('Login_Page_Centered') }}</a-radio-button>
                  </a-radio-group>
                </a-form-item>
                <a-row :gutter="16">
                  <a-col :span="12">
                    <a-form-item :label="$t('Login_Page_Hero_Align')">
                      <a-radio-group v-model:value="options.hero_align" :disabled="options.layout === 'centered'">
                        <a-radio-button value="left">{{ $t('Login_Page_Align_Left') }}</a-radio-button>
                        <a-radio-button value="center">{{ $t('Login_Page_Align_Center') }}</a-radio-button>
                      </a-radio-group>
                    </a-form-item>
                  </a-col>
                  <a-col :span="12">
                    <a-form-item :label="$t('Login_Page_Panel_Align')">
                      <a-radio-group v-model:value="options.panel_align">
                        <a-radio-button value="left">{{ $t('Login_Page_Align_Left') }}</a-radio-button>
                        <a-radio-button value="center">{{ $t('Login_Page_Align_Center') }}</a-radio-button>
                      </a-radio-group>
                    </a-form-item>
                  </a-col>
                </a-row>
                <a-form-item :label="$t('Login_Page_Input_Shape')">
                  <a-radio-group v-model:value="options.input_style">
                    <a-radio-button value="pill">{{ $t('Login_Page_Pill') }}</a-radio-button>
                    <a-radio-button value="rounded">{{ $t('Login_Page_Rounded') }}</a-radio-button>
                    <a-radio-button value="square">{{ $t('Login_Page_Square') }}</a-radio-button>
                  </a-radio-group>
                </a-form-item>
                <a-form-item :label="$t('Login_Page_Card_Radius', { n: options.card_radius })">
                  <a-slider v-model:value="options.card_radius" :min="0" :max="40" />
                </a-form-item>
                <a-form-item>
                  <a-switch v-model:checked="options.card_shadow" size="small" /> <span class="lp-switch-label">{{ $t('Login_Page_Card_Shadow') }}</span>
                </a-form-item>
              </a-tab-pane>

              <!-- Hero -->
              <a-tab-pane key="hero" :tab="$t('Hero')">
                <a-alert
                  v-if="options.layout === 'centered'"
                  type="info" show-icon style="margin-bottom: 12px"
                  :message="$t('Login_Page_Centered_Hides_Hero')"
                />
                <a-form-item :label="$t('Badge')">
                  <a-input v-model:value="setting.login_hero_badge" :placeholder="$t('Login_Page_Hidden_When_Empty')" />
                </a-form-item>
                <a-form-item :label="$t('Title')">
                  <a-input v-model:value="setting.login_hero_title" :placeholder="$t('Login_Page_Hero_Title_Ph')" />
                </a-form-item>
                <a-form-item :label="$t('Subtitle')">
                  <a-textarea v-model:value="setting.login_hero_subtitle" :rows="2" :placeholder="$t('Login_Page_Hero_Subtitle_Ph')" />
                </a-form-item>
                <a-form-item v-for="n in 3" :key="n" :label="$t('Login_Page_Feature_N', { n })">
                  <a-input v-model:value="setting[`login_hero_feature_${n}`]" :placeholder="$t('Login_Page_Hidden_When_Empty')" />
                </a-form-item>

                <a-divider style="margin: 8px 0 16px" />
                <a-form-item :label="$t('Login_Page_Background')">
                  <a-radio-group v-model:value="options.hero_bg_type" button-style="solid">
                    <a-radio-button value="gradient">{{ $t('Login_Page_Gradient') }}</a-radio-button>
                    <a-radio-button value="solid">{{ $t('Login_Page_Solid') }}</a-radio-button>
                    <a-radio-button value="image">{{ $t('Image') }}</a-radio-button>
                  </a-radio-group>
                </a-form-item>
                <a-row :gutter="16">
                  <a-col :span="12">
                    <a-form-item :label="options.hero_bg_type === 'gradient' ? $t('Login_Page_Start_Color') : options.hero_bg_type === 'image' ? $t('Login_Page_Color_Behind_Image') : $t('Color')">
                      <ColorField v-model="setting.login_bg_color" :placeholder="options.hero_bg_type === 'gradient' ? '#7a4dff' : '#6237ff'" />
                    </a-form-item>
                  </a-col>
                  <a-col v-if="options.hero_bg_type === 'gradient'" :span="12">
                    <a-form-item :label="$t('Login_Page_End_Color')">
                      <ColorField v-model="options.hero_color_2" placeholder="#4f2bf8" />
                    </a-form-item>
                  </a-col>
                </a-row>
                <a-form-item v-if="options.hero_bg_type === 'gradient'" :label="$t('Login_Page_Gradient_Angle', { n: options.hero_gradient_angle })">
                  <a-slider v-model:value="options.hero_gradient_angle" :min="0" :max="360" />
                </a-form-item>
                <template v-if="options.hero_bg_type === 'image'">
                  <a-form-item :label="$t('Login_Page_Background_Image')" :help="$t('Login_Page_Image_Help')">
                    <div class="lp-image">
                      <div v-if="imageUrl" class="lp-image-thumb" :style="{ backgroundImage: `url('${imageUrl}')` }" />
                      <a-space>
                        <a-upload :show-upload-list="false" accept="image/jpeg,image/png,image/webp" :before-upload="pickImage">
                          <a-button size="small"><UploadOutlined /> {{ imageUrl ? $t('Hm_Replace') : $t('Upload') }}</a-button>
                        </a-upload>
                        <a-button v-if="imageUrl" size="small" danger @click="removeImage">{{ $t('Remove') }}</a-button>
                      </a-space>
                    </div>
                  </a-form-item>
                  <a-form-item :label="$t('Login_Page_Darken_Image', { n: options.hero_overlay })">
                    <a-slider v-model:value="options.hero_overlay" :min="0" :max="90" />
                  </a-form-item>
                </template>
                <a-form-item :label="$t('Set_Text_Color')">
                  <ColorField v-model="options.hero_text_color" placeholder="#ffffff" />
                </a-form-item>
              </a-tab-pane>

              <!-- Sign-in form -->
              <a-tab-pane key="form" :tab="$t('Login_Page_Tab_Form')">
                <a-form-item>
                  <a-switch v-model:checked="options.show_logo" size="small" /> <span class="lp-switch-label">{{ $t('Show_Logo') }}</span>
                  <div v-if="!hasLogo" class="lp-help">{{ $t('Login_Page_No_Logo') }}</div>
                </a-form-item>
                <a-form-item :label="$t('Title')">
                  <a-input v-model:value="setting.login_panel_title" :placeholder="$t('SignIn')" />
                </a-form-item>
                <a-form-item :label="$t('Subtitle')">
                  <a-textarea v-model:value="setting.login_panel_subtitle" :rows="2" :placeholder="$t('Login_Page_Panel_Subtitle_Ph')" />
                </a-form-item>
                <a-form-item :label="$t('Button_Text')">
                  <a-input v-model:value="setting.login_btn_text" :placeholder="$t('SignIn')" />
                </a-form-item>
                <a-form-item :label="$t('Footer_Text')">
                  <a-input v-model:value="setting.login_footer_text" :placeholder="$t('Login_Page_Hidden_When_Empty')" />
                </a-form-item>
                <a-space direction="vertical">
                  <span><a-switch v-model:checked="options.show_forgot_password" size="small" /> <span class="lp-switch-label">{{ $t('Login_Page_Forgot_Link') }}</span></span>
                  <span><a-switch v-model:checked="options.show_password_toggle" size="small" /> <span class="lp-switch-label">{{ $t('Login_Page_Password_Toggle') }}</span></span>
                  <span><a-switch v-model:checked="options.show_remember_me" size="small" /> <span class="lp-switch-label">{{ $t('Login_Page_Remember_Me') }}</span></span>
                </a-space>
              </a-tab-pane>

              <!-- Colors -->
              <a-tab-pane key="colors" :tab="$t('Set_Pdf_Colors')">
                <a-form-item v-for="c in COLOR_FIELDS" :key="c.key" :label="$t(c.label)" :help="c.help ? $t(c.help) : undefined">
                  <ColorField v-model="options[c.key]" :placeholder="c.placeholder" />
                </a-form-item>
              </a-tab-pane>
            </a-tabs>

            <a-space style="margin-top: 8px">
              <a-button type="primary" :loading="saving" @click="save">{{ $t('submit') }}</a-button>
              <a-popconfirm :title="$t('Login_Page_Reset_Confirm')" @confirm="resetDesign">
                <a-button>{{ $t('Login_Page_Reset_Design') }}</a-button>
              </a-popconfirm>
            </a-space>
          </a-form>
        </a-card>
      </a-col>
    </a-row>
  </div>
</template>

<script setup>
/**
 * Tenant login page: texts, layout, colours, visibility, with a live preview.
 * - GET get_appearance_settings → {settings} (shared read with Appearance;
 *   carries login_page_options already sanitized + login_bg_image_url)
 * - save = multipart POST update_login_page_settings/{id} (_method=put):
 *   texts, login_bg_color, login_page_options (JSON), login_bg_image file
 *   or remove_login_bg_image=1. Only login_* columns are written.
 * Preview: iframe of /login/preview (the real login view, form disabled);
 * every change is posted to it as { type: 'login-preview:update', state }
 * and applied by public/assets_super/js/auth-login-preview.js.
 */
import { ref, reactive, computed, watch, onMounted, onBeforeUnmount } from 'vue';
import { message } from 'ant-design-vue';
import { useI18n } from 'vue-i18n';
import { EyeOutlined, UploadOutlined } from '@ant-design/icons-vue';
import PageHeader from '../../components/PageHeader.vue';
import ColorField from '../../components/ColorField.vue';
import http from '../../lib/http';

const { t } = useI18n();

// Mirrors App\Support\LoginPageAppearance::DEFAULTS.
const DEFAULTS = {
  layout: 'split',
  hero_align: 'left',
  panel_align: 'left',
  hero_bg_type: 'gradient',
  hero_color_2: null,
  hero_gradient_angle: 140,
  hero_overlay: 45,
  hero_text_color: null,
  page_bg_color: null,
  card_bg_color: null,
  text_color: null,
  primary_color: null,
  button_text_color: null,
  card_radius: 24,
  card_shadow: true,
  input_style: 'pill',
  show_logo: true,
  show_forgot_password: true,
  show_password_toggle: true,
  show_remember_me: false,
};

const TEXT_FIELDS = [
  'login_hero_badge', 'login_hero_title', 'login_hero_subtitle',
  'login_hero_feature_1', 'login_hero_feature_2', 'login_hero_feature_3',
  'login_panel_title', 'login_panel_subtitle', 'login_btn_text', 'login_footer_text',
];

const COLOR_FIELDS = [
  { key: 'page_bg_color', label: 'Login_Page_Page_Bg', placeholder: '#f3f3ff', help: 'Login_Page_Page_Bg_Help' },
  { key: 'card_bg_color', label: 'Login_Page_Card_Bg', placeholder: '#ffffff' },
  { key: 'text_color', label: 'Login_Page_Card_Text', placeholder: '#1f2937' },
  { key: 'primary_color', label: 'Login_Page_Button_Links', placeholder: '#4c44ec' },
  { key: 'button_text_color', label: 'Button_Text', placeholder: '#ffffff' },
];

const DEVICES = {
  desktop: { width: 1280, height: 800 },
  mobile: { width: 390, height: 760 },
};
const deviceOptions = computed(() => [
  { value: 'desktop', label: t('Login_Page_Desktop') },
  { value: 'mobile', label: t('Login_Page_Mobile') },
]);

const loading = ref(true);
const saving = ref(false);
const tab = ref('layout');
const setting = ref({});
const options = reactive({ ...DEFAULTS });

// Hero image: a picked file previews from a blob URL until saved.
const savedImageUrl = ref(null);
const imageFile = ref(null);
const imageBlobUrl = ref(null);
const imageRemoved = ref(false);
const imageUrl = computed(() => imageBlobUrl.value || (imageRemoved.value ? null : savedImageUrl.value));
const hasLogo = computed(() => !!setting.value.logo);

function pickImage(file) {
  if (file.size > 4 * 1024 * 1024) {
    message.error(t('Login_Page_Image_Too_Big'));
    return false;
  }
  clearBlob();
  imageFile.value = file;
  imageBlobUrl.value = URL.createObjectURL(file);
  imageRemoved.value = false;
  return false;
}

function removeImage() {
  clearBlob();
  imageFile.value = null;
  imageRemoved.value = true;
}

function clearBlob() {
  if (imageBlobUrl.value) URL.revokeObjectURL(imageBlobUrl.value);
  imageBlobUrl.value = null;
}

function resetDesign() {
  Object.assign(options, DEFAULTS);
  setting.value.login_bg_color = null;
  removeImage();
}

/* --------------------------------------------------------------- Preview */
const previewUrl = `${window.location.origin}/login/preview`;
const frameEl = ref(null);
const stageEl = ref(null);
const stageWidth = ref(0);
const device = ref('desktop');
const frameWidth = computed(() => DEVICES[device.value].width);
const frameHeight = computed(() => DEVICES[device.value].height);
const scale = computed(() => (stageWidth.value ? Math.min(1, stageWidth.value / frameWidth.value) : 0.5));

function previewState() {
  const texts = {};
  TEXT_FIELDS.forEach(key => { texts[key] = setting.value[key] || ''; });
  return {
    texts,
    login_bg_color: setting.value.login_bg_color || null,
    image_url: imageUrl.value,
    options: { ...options },
  };
}

function pushPreview() {
  const win = frameEl.value?.contentWindow;
  if (win) win.postMessage({ type: 'login-preview:update', state: previewState() }, window.location.origin);
}

function onMessage(event) {
  if (event.origin !== window.location.origin) return;
  if (event.data?.type === 'login-preview:ready') pushPreview();
}

watch([setting, () => ({ ...options }), imageUrl], pushPreview, { deep: true });

let resizeObserver = null;
watch(stageEl, el => {
  resizeObserver?.disconnect();
  if (!el) return;
  resizeObserver = new ResizeObserver(([entry]) => { stageWidth.value = entry.contentRect.width; });
  resizeObserver.observe(el);
});

/* ------------------------------------------------------------------ Data */
async function load() {
  const data = await http.get('get_appearance_settings');
  const s = data.settings || {};
  setting.value = s;
  Object.assign(options, DEFAULTS, s.login_page_options || {});
  savedImageUrl.value = s.login_bg_image_url || null;
  clearBlob();
  imageFile.value = null;
  imageRemoved.value = false;
}

async function save() {
  saving.value = true;
  const s = setting.value;
  const fd = new FormData();
  TEXT_FIELDS.forEach(key => fd.append(key, s[key] || ''));
  fd.append('login_bg_color', s.login_bg_color || '');
  fd.append('login_page_options', JSON.stringify(options));
  if (imageFile.value) fd.append('login_bg_image', imageFile.value);
  else if (imageRemoved.value) fd.append('remove_login_bg_image', '1');
  fd.append('_method', 'put');
  try {
    await http.postForm(`update_login_page_settings/${s.id}`, fd);
    message.success(t('Successfully_Updated'));
    await load();
  } catch (e) {
    message.error(e?.data?.message || t('InvalidData'));
  } finally {
    saving.value = false;
  }
}

onMounted(async () => {
  window.addEventListener('message', onMessage);
  try {
    await load();
  } catch (e) {
    message.error(t('InvalidData'));
  } finally {
    loading.value = false;
  }
});

onBeforeUnmount(() => {
  window.removeEventListener('message', onMessage);
  resizeObserver?.disconnect();
  clearBlob();
});
</script>

<style scoped>
.lp-stage {
  position: relative;
  overflow: hidden;
  border-radius: 8px;
  background: rgba(var(--ink-rgb), 0.04);
}
.lp-frame {
  position: absolute;
  top: 0;
  border: 0;
  transform-origin: top left;
  background: #fff;
}
.lp-hint,
.lp-help {
  margin-top: 8px;
  color: rgba(var(--ink-rgb), 0.45);
  font-size: 12px;
}
.lp-switch-label {
  margin-left: 8px;
}
.lp-image {
  display: flex;
  align-items: center;
  gap: 12px;
}
.lp-image-thumb {
  width: 96px;
  height: 60px;
  border-radius: 6px;
  background: center / cover no-repeat;
  border: 1px solid var(--line);
}
/* Wide screens: options left, sticky preview right. */
@media (min-width: 1600px) {
  .lp-preview-col {
    order: 2;
  }
  .lp-preview-card {
    position: sticky;
    top: 16px;
  }
}
</style>
