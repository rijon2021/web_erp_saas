<template>
  <!-- Draw-with-finger/mouse signature box. Emits nothing; read it with toDataUrl(). -->
  <div class="rst-sig">
    <canvas
      ref="canvas"
      class="rst-sig-canvas"
      @pointerdown="start"
      @pointermove="move"
      @pointerup="end"
      @pointerleave="end"
      @pointercancel="end"
    ></canvas>
    <div class="rst-sig-line" aria-hidden="true"></div>
    <span v-if="empty" class="rst-sig-hint text-secondary">{{ tr('rental_sign_here', 'Sign here') }}</span>
    <button type="button" class="btn btn-sm btn-ghost-secondary rst-sig-clear" :disabled="empty" @click="clear">
      <i class="ti ti-eraser me-1"></i>{{ tr('rental_clear', 'Clear') }}
    </button>
  </div>
</template>

<script>
export default {
  name: 'SignatureCanvas',
  data() { return { empty: true, drawing: false, last: null }; },
  mounted() {
    this.resize();
    this.onResize = () => this.resize();
    window.addEventListener('resize', this.onResize);
  },
  beforeUnmount() { window.removeEventListener('resize', this.onResize); },
  methods: {
    resize() {
      const c = this.$refs.canvas;
      if (!c) return;
      const ratio = Math.max(window.devicePixelRatio || 1, 1);
      const w = c.offsetWidth;
      const h = c.offsetHeight;
      // Resizing wipes the drawing: skip when the size didn't change, else
      // keep what was drawn and paint it back.
      if (c.width === Math.round(w * ratio) && c.height === Math.round(h * ratio)) return;
      let snapshot = null;
      if (!this.empty && c.width && c.height) {
        snapshot = document.createElement('canvas');
        snapshot.width = c.width;
        snapshot.height = c.height;
        snapshot.getContext('2d').drawImage(c, 0, 0);
      }
      c.width = Math.round(w * ratio);
      c.height = Math.round(h * ratio);
      const ctx = c.getContext('2d');
      if (snapshot) ctx.drawImage(snapshot, 0, 0, c.width, c.height);
      ctx.scale(ratio, ratio);
      ctx.lineWidth = 2.2;
      ctx.lineCap = 'round';
      ctx.lineJoin = 'round';
      ctx.strokeStyle = '#111827';
      if (!snapshot) this.empty = true;
    },
    point(e) {
      const r = this.$refs.canvas.getBoundingClientRect();
      return { x: e.clientX - r.left, y: e.clientY - r.top };
    },
    start(e) {
      e.preventDefault();
      this.$refs.canvas.setPointerCapture?.(e.pointerId);
      this.drawing = true;
      this.last = this.point(e);
      const ctx = this.$refs.canvas.getContext('2d');
      ctx.beginPath();
      ctx.arc(this.last.x, this.last.y, 1, 0, Math.PI * 2);
      ctx.fillStyle = '#111827';
      ctx.fill();
      this.empty = false;
    },
    move(e) {
      if (!this.drawing) return;
      e.preventDefault();
      const p = this.point(e);
      const ctx = this.$refs.canvas.getContext('2d');
      ctx.beginPath();
      ctx.moveTo(this.last.x, this.last.y);
      ctx.lineTo(p.x, p.y);
      ctx.stroke();
      this.last = p;
    },
    end() { this.drawing = false; this.last = null; },
    clear() {
      const c = this.$refs.canvas;
      c.getContext('2d').clearRect(0, 0, c.width, c.height);
      this.empty = true;
    },
    isEmpty() { return this.empty; },
    toDataUrl() { return this.empty ? '' : this.$refs.canvas.toDataURL('image/png'); },
  },
};
</script>

<style scoped>
.rst-sig {
  position: relative;
  border: 1px dashed var(--tblr-border-color, #d0d5dd);
  border-radius: 8px;
  background: #fff;
  height: 180px;
}
.rst-sig-canvas {
  width: 100%;
  height: 100%;
  display: block;
  touch-action: none;
  cursor: crosshair;
}
.rst-sig-line {
  position: absolute;
  left: 24px;
  right: 24px;
  bottom: 42px;
  border-bottom: 1px solid #e5e7eb;
  pointer-events: none;
}
.rst-sig-hint {
  position: absolute;
  left: 24px;
  bottom: 48px;
  font-size: 0.8rem;
  pointer-events: none;
}
.rst-sig-clear {
  position: absolute;
  right: 6px;
  top: 6px;
}
</style>
