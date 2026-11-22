<template>
  <div class="pad" :class="{ empty: isEmpty }">
    <canvas
      ref="canvas" class="pad-canvas"
      @pointerdown="start" @pointermove="move" @pointerup="stop" @pointerleave="stop" @pointercancel="stop"
    ></canvas>
    <span v-if="isEmpty" class="pad-hint">{{ hint }}</span>
    <span class="pad-line"></span>
  </div>
</template>

<script setup>
/**
 * Minimal signature pad: pointer events (mouse, pen and touch alike), drawn
 * at device-pixel resolution so the PNG stays crisp. Exposes clear(),
 * isBlank() and toDataURL() — always a PNG, which is what the API accepts.
 */
import { ref, onMounted, onBeforeUnmount } from 'vue';

defineProps({ hint: { type: String, default: '' } });

const canvas = ref(null);
const isEmpty = ref(true);
let ctx = null;
let drawing = false;
let last = null;

function resize() {
  const el = canvas.value;
  if (!el) return;
  const ratio = Math.max(window.devicePixelRatio || 1, 1);
  const { width, height } = el.getBoundingClientRect();
  const w = Math.round(width * ratio);
  const h = Math.round(height * ratio);
  if (ctx && el.width === w && el.height === h) return; // e.g. a mobile address bar showing/hiding
  // Resizing a canvas wipes it: keep what was drawn and paint it back.
  let snapshot = null;
  if (!isEmpty.value && el.width && el.height) {
    snapshot = document.createElement('canvas');
    snapshot.width = el.width;
    snapshot.height = el.height;
    snapshot.getContext('2d').drawImage(el, 0, 0);
  }
  el.width = w;
  el.height = h;
  ctx = el.getContext('2d');
  if (snapshot) {
    ctx.drawImage(snapshot, 0, 0, w, h);
  }
  ctx.scale(ratio, ratio);
  ctx.lineWidth = 2.2;
  ctx.lineCap = 'round';
  ctx.lineJoin = 'round';
  ctx.strokeStyle = '#111827';
  if (!snapshot) isEmpty.value = true;
}

function point(e) {
  const r = canvas.value.getBoundingClientRect();
  return { x: e.clientX - r.left, y: e.clientY - r.top };
}

function start(e) {
  e.preventDefault();
  // Measured while a modal was still zooming in, the canvas can be the wrong
  // size; re-measure on the first stroke (nothing to lose while empty).
  if (isEmpty.value) resize();
  canvas.value.setPointerCapture?.(e.pointerId);
  drawing = true;
  last = point(e);
  ctx.beginPath();
  ctx.arc(last.x, last.y, 1.1, 0, Math.PI * 2);
  ctx.fillStyle = '#111827';
  ctx.fill();
  isEmpty.value = false;
}

function move(e) {
  if (!drawing) return;
  const p = point(e);
  ctx.beginPath();
  ctx.moveTo(last.x, last.y);
  // Midpoint smoothing keeps fast strokes from looking jagged.
  const mid = { x: (last.x + p.x) / 2, y: (last.y + p.y) / 2 };
  ctx.quadraticCurveTo(last.x, last.y, mid.x, mid.y);
  ctx.lineTo(p.x, p.y);
  ctx.stroke();
  last = p;
}

function stop() {
  drawing = false;
}

function clear() {
  const el = canvas.value;
  if (!el || !ctx) return;
  ctx.save();
  ctx.setTransform(1, 0, 0, 1, 0, 0);
  ctx.clearRect(0, 0, el.width, el.height);
  ctx.restore();
  isEmpty.value = true;
}

function isBlank() {
  return isEmpty.value;
}

function toDataURL() {
  return canvas.value.toDataURL('image/png');
}

onMounted(() => {
  // The pad usually sits in a modal that is still animating in.
  requestAnimationFrame(resize);
  window.addEventListener('resize', resize);
});
onBeforeUnmount(() => window.removeEventListener('resize', resize));

defineExpose({ clear, isBlank, toDataURL, resize });
</script>

<style scoped>
.pad {
  position: relative;
  height: 190px;
  border: 1.5px dashed rgba(128, 128, 128, 0.4);
  border-radius: 12px;
  background: #fff; /* signatures are ink on paper, in both themes */
  overflow: hidden;
}
.pad-canvas {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  touch-action: none;
  cursor: crosshair;
}
.pad-hint {
  position: absolute;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  color: #9ca3af;
  font-size: 14px;
  pointer-events: none;
}
.pad-line {
  position: absolute;
  inset-inline: 24px;
  bottom: 42px;
  border-bottom: 1px solid #d1d5db;
  pointer-events: none;
}
</style>
