<template>
  <div class="conv-list">
    <a-button type="primary" block @click="$emit('new')">
      <template #icon><PlusOutlined /></template>
      {{ tf('Ai_New_Chat', 'New chat') }}
    </a-button>

    <a-empty
      v-if="!conversations.length"
      :image="simpleImage"
      :description="tf('Ai_No_Conversations', 'No conversations yet')"
      style="margin-top: 32px"
    />

    <div
      v-for="c in conversations"
      :key="c.id"
      class="conv-item"
      :class="{ active: c.id === activeId }"
      role="button"
      tabindex="0"
      @click="$emit('open', c.id)"
      @keydown.enter="$emit('open', c.id)"
    >
      <div class="conv-text">
        <div class="conv-title">{{ c.title || tf('Ai_New_Chat', 'New chat') }}</div>
        <div class="conv-date">{{ dateTime(c.updated_at) }}</div>
      </div>
      <a-button type="text" size="small" class="conv-delete" :aria-label="$t('Delete')" @click.stop="$emit('remove', c.id)">
        <template #icon><DeleteOutlined /></template>
      </a-button>
    </div>
  </div>
</template>

<script setup>
import { Empty } from 'ant-design-vue';
import { PlusOutlined, DeleteOutlined } from '@ant-design/icons-vue';
import { useFormat } from '../../composables/useFormat';
import { t as tf } from '../../i18n';

defineProps({
  conversations: { type: Array, required: true },
  activeId: { type: [Number, String], default: null },
});
defineEmits(['new', 'open', 'remove']);

const { dateTime } = useFormat();
const simpleImage = Empty.PRESENTED_IMAGE_SIMPLE;
</script>

<style scoped>
.conv-list { display: flex; flex-direction: column; gap: 4px; }
.conv-list > :first-child { margin-bottom: 10px; }
.conv-item {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 8px 10px;
  border-radius: 8px;
  cursor: pointer;
}
.conv-item:hover { background: rgba(128, 128, 128, 0.1); }
.conv-item.active { background: rgba(var(--app-primary-rgb), 0.12); }
.conv-text { flex: 1; min-width: 0; }
.conv-title {
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  font-size: 13.5px;
}
.conv-date { font-size: 11px; opacity: 0.55; }
.conv-delete { opacity: 0; }
.conv-item:hover .conv-delete, .conv-item.active .conv-delete, .conv-delete:focus { opacity: 1; }
@media (hover: none) { .conv-delete { opacity: 1; } }
</style>
