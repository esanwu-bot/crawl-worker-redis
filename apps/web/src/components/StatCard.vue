<template>
  <div class="cw-stat">
    <div class="cw-stat-icon" :style="{ background: grad }">
      <component :is="icon" />
    </div>
    <div class="cw-stat-main">
      <div class="cw-stat-label">{{ label }}</div>
      <div class="cw-stat-value" :style="valueColor ? { color: valueColor } : {}">{{ value }}</div>
      <div v-if="delta !== undefined" class="cw-stat-delta">
        <span :class="delta >= 0 ? 'cw-up' : 'cw-down'">
          {{ delta >= 0 ? '↑' : '↓' }} {{ Math.abs(delta).toFixed(1) }}%
        </span>
        <span class="cw-stat-sub"> {{ deltaLabel }}</span>
      </div>
      <div v-else-if="hint" class="cw-stat-delta cw-stat-sub">{{ hint }}</div>
    </div>
  </div>
</template>

<script setup lang="ts">
defineProps<{
  icon: unknown;
  label: string;
  value: string | number;
  grad: string;
  delta?: number;
  deltaLabel?: string;
  hint?: string;
  valueColor?: string;
}>();
</script>

<style scoped>
.cw-stat-main {
  min-width: 0;
}
.cw-stat-sub {
  color: var(--cw-muted);
}
</style>
