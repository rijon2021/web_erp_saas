<template>
  <a-alert v-if="errors.length" :type="type" style="margin-top: 16px" show-icon>
    <template #message>
      <div style="display: flex; justify-content: space-between; align-items: center; gap: 8px; flex-wrap: wrap">
        <span>{{ title || $t('Import_Failed_Fix_Below') }}</span>
        <a-button size="small" @click="download">
          <template #icon><DownloadOutlined /></template>
          {{ $t('Imp_Download_Errors') }} ({{ errors.length }})
        </a-button>
      </div>
    </template>
    <template #description>
      <ul style="margin: 0; padding-left: 18px; max-height: 260px; overflow-y: auto">
        <li v-for="(err, idx) in errors" :key="'err-' + idx">{{ err }}</li>
      </ul>
    </template>
  </a-alert>
</template>

<script setup>
/**
 * The import pages' error panel: the full list stays on screen (scrolls when
 * long) and can be saved as a CSV, so rejected rows and their reasons are not
 * lost once the page is left.
 */
import { DownloadOutlined } from '@ant-design/icons-vue';
import dayjs from 'dayjs';

const props = defineProps({
  errors: { type: Array, required: true },
  title: { type: String, default: '' },
  type: { type: String, default: 'error' },
  filename: { type: String, default: 'import_errors' },
});

function download() {
  // One message per row; quotes doubled so commas and quotes survive.
  const csv = ['Error', ...props.errors.map(e => `"${String(e).replace(/"/g, '""')}"`)].join('\r\n');
  const blob = new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8' });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = `${props.filename}_${dayjs().format('YYYY-MM-DD_HHmm')}.csv`;
  document.body.appendChild(a);
  a.click();
  a.remove();
  setTimeout(() => URL.revokeObjectURL(url), 10000);
}
</script>
