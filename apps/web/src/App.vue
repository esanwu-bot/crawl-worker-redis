<template>
  <a-config-provider :locale="zhCN" :theme="themeConfig">
    <router-view />
  </a-config-provider>
</template>

<script setup lang="ts">
import { computed } from 'vue';
import zhCN from 'ant-design-vue/es/locale/zh_CN';
import { theme as antdTheme } from 'ant-design-vue';
import { dark } from '@/store/theme';

const themeConfig = computed(() => ({
  token: {
    colorPrimary: '#2563eb',
    borderRadius: 8,
    fontSize: 14,
  },
  algorithm: dark.value ? antdTheme.darkAlgorithm : antdTheme.defaultAlgorithm,
}));
</script>
