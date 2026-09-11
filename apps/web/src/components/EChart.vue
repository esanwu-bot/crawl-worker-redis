<template>
  <div ref="el" :style="{ width: '100%', height }"></div>
</template>

<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import * as echarts from 'echarts';
import type { EChartsOption } from 'echarts';

const props = withDefaults(
  defineProps<{
    option: EChartsOption;
    height?: string;
  }>(),
  { height: '300px' },
);

const el = ref<HTMLDivElement | null>(null);
let chart: echarts.ECharts | null = null;
let ro: ResizeObserver | null = null;

function render() {
  if (!el.value) return;
  if (!chart) chart = echarts.init(el.value);
  chart.setOption(props.option, true);
}

onMounted(() => {
  render();
  ro = new ResizeObserver(() => chart?.resize());
  if (el.value) ro.observe(el.value);
});

watch(() => props.option, render, { deep: true });

onBeforeUnmount(() => {
  ro?.disconnect();
  chart?.dispose();
  chart = null;
});
</script>
