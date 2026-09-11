<template>
  <a-layout style="min-height: 100vh">
    <a-layout-sider :width="220" theme="dark" class="cw-sider">
      <div class="cw-logo">
        <span class="cw-logo-mark">CW</span>
        <div>
          <div class="cw-logo-title">Crawler Console</div>
          <div class="cw-logo-sub">数据采集管理平台</div>
        </div>
      </div>
      <a-menu
        v-model:selectedKeys="selectedKeys"
        theme="dark"
        mode="inline"
        @click="onMenuClick"
      >
        <a-menu-item key="/dashboard">
          <template #icon><DashboardOutlined /></template>
          仪表盘
        </a-menu-item>
        <a-menu-item key="/jobs">
          <template #icon><UnorderedListOutlined /></template>
          采集任务
        </a-menu-item>
        <a-menu-item key="/sources">
          <template #icon><DatabaseOutlined /></template>
          数据源
        </a-menu-item>
        <a-menu-item key="/records">
          <template #icon><ProfileOutlined /></template>
          采集结果
        </a-menu-item>
        <a-menu-item key="/dead-letters">
          <template #icon><WarningOutlined /></template>
          死信队列
        </a-menu-item>
        <a-menu-item key="/workers">
          <template #icon><ClusterOutlined /></template>
          Worker / Redis
        </a-menu-item>
      </a-menu>
    </a-layout-sider>

    <a-layout>
      <a-layout-header class="cw-header">
        <div class="cw-header-left">
          <span class="cw-header-title">{{ pageTitle }}</span>
        </div>
        <div class="cw-header-right">
          <a-tooltip :title="healthTooltip">
            <a-tag :color="healthColor" style="margin-right: 8px">
              <template #icon><ApiOutlined /></template>
              {{ healthText }}
            </a-tag>
          </a-tooltip>
          <a-button size="small" :loading="refreshing" @click="refresh" style="margin-right: 8px">
            <template #icon><ReloadOutlined /></template>
            刷新
          </a-button>
          <a-avatar style="background: #1677ff">A</a-avatar>
          <span style="margin-left: 8px">Admin</span>
        </div>
      </a-layout-header>

      <a-layout-content class="cw-content">
        <router-view v-slot="{ Component }">
          <keep-alive :max="5">
            <component :is="Component" :key="route.fullPath" />
          </keep-alive>
        </router-view>
      </a-layout-content>
    </a-layout>
  </a-layout>
</template>

<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import {
  ApiOutlined,
  ClusterOutlined,
  DashboardOutlined,
  DatabaseOutlined,
  ProfileOutlined,
  ReloadOutlined,
  UnorderedListOutlined,
  WarningOutlined,
} from '@ant-design/icons-vue';
import { api } from '@/api';
import type { HealthResp } from '@/api/types';

const route = useRoute();
const router = useRouter();

const selectedKeys = ref<string[]>(['/dashboard']);
const health = ref<HealthResp | null>(null);
const healthErr = ref(false);
const refreshing = ref(false);
let timer: number | undefined;

const pageTitle = computed(() => (route.meta?.title as string) || 'Crawler Console');

function syncMenu() {
  const p = route.path;
  const top = '/' + (p.split('/')[1] || 'dashboard');
  selectedKeys.value = [top];
}

watch(() => route.path, syncMenu, { immediate: true });

function onMenuClick({ key }: { key: string | number }) {
  router.push(String(key));
}

const healthText = computed(() => {
  if (healthErr.value) return 'API 离线';
  if (!health.value) return '检测中';
  return health.value.status === 'ok' ? '系统正常' : '降级运行';
});

const healthColor = computed(() => {
  if (healthErr.value) return 'error';
  if (!health.value) return 'default';
  return health.value.status === 'ok' ? 'success' : 'warning';
});

const healthTooltip = computed(() => {
  if (healthErr.value) return '无法连接采集引擎 API，请确认 crawler-api 已启动';
  if (!health.value) return '正在检测后端状态';
  const h = health.value;
  return `Redis: ${h.redis || 'ok'} | MySQL: ${h.database || 'ok'} | 运行 ${h.uptime_s}s`;
});

async function loadHealth() {
  try {
    health.value = await api.health();
    healthErr.value = false;
  } catch {
    healthErr.value = true;
  }
}

async function refresh() {
  refreshing.value = true;
  await loadHealth();
  refreshing.value = false;
}

onMounted(() => {
  loadHealth();
  timer = window.setInterval(loadHealth, 15000);
});

onUnmounted(() => {
  if (timer) window.clearInterval(timer);
});
</script>

<style scoped>
.cw-sider {
  position: sticky;
  top: 0;
  height: 100vh;
}

.cw-logo {
  display: flex;
  align-items: center;
  gap: 10px;
  height: 56px;
  padding: 0 16px;
  border-bottom: 1px solid rgba(255, 255, 255, 0.08);
}

.cw-logo-mark {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 34px;
  height: 34px;
  border-radius: 8px;
  background: linear-gradient(135deg, #1677ff, #52c41a);
  color: #fff;
  font-weight: 700;
  font-size: 13px;
}

.cw-logo-title {
  color: #fff;
  font-size: 14px;
  font-weight: 600;
  line-height: 1.2;
}

.cw-logo-sub {
  color: rgba(255, 255, 255, 0.45);
  font-size: 11px;
}

.cw-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  height: 56px;
  padding: 0 20px;
  background: #fff;
  box-shadow: 0 1px 4px rgba(0, 21, 41, 0.08);
}

.cw-header-title {
  font-size: 16px;
  font-weight: 600;
}

.cw-header-right {
  display: flex;
  align-items: center;
}

.cw-content {
  background: #f5f7fa;
}
</style>
