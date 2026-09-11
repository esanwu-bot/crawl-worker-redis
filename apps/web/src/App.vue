<template>
  <a-config-provider :locale="zhCN" :theme="themeConfig">
    <router-view />
  </a-config-provider>
</template>

<script setup lang="ts">
import zhCN from 'ant-design-vue/es/locale/zh_CN';
import { theme } from 'ant-design-vue';

const themeConfig = {
  token: {
    colorPrimary: '#1677ff',
    borderRadius: 6,
  },
  algorithm: theme.defaultAlgorithm,
};
</script>
