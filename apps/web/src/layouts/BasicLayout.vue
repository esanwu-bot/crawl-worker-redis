<template>
  <a-layout class="cw-layout">
    <a-layout-sider
      v-model:collapsed="collapsed"
      :trigger="null"
      collapsible
      :width="228"
      :collapsed-width="64"
      theme="dark"
      class="cw-sider"
    >
      <div class="cw-logo">
        <span class="cw-logo-mark">CW</span>
        <div v-show="!collapsed" class="cw-logo-text">
          <div class="t1">crawl-worker-redis</div>
          <div class="t2">Agent Workbench v2</div>
        </div>
      </div>
      <div class="cw-sider-scroll">
        <a-menu
          mode="inline"
          theme="dark"
          :selected-keys="selectedKeys"
          :open-keys="collapsed ? [] : openKeys"
          @click="onMenuClick"
          @openChange="onOpenChange"
        >
          <template v-for="node in MENU" :key="node.key">
            <a-menu-item v-if="!node.children" :key="node.key">
              <template #icon><component :is="iconOf(node.icon)" /></template>
              <span>{{ node.label }}</span>
              <span v-if="badgeOf(node.badge)" class="cw-menu-badge">{{ badgeOf(node.badge) }}</span>
            </a-menu-item>
            <a-sub-menu v-else :key="node.key">
              <template #icon><component :is="iconOf(node.icon)" /></template>
              <template #title>
                <span class="cw-group-title">{{ node.label }}</span>
                <span v-if="badgeOf(node.badge)" class="cw-menu-badge">{{ badgeOf(node.badge) }}</span>
              </template>
              <a-menu-item v-for="c in node.children" :key="c.key">
                <template #icon><component :is="iconOf(c.icon)" /></template>
                <span>{{ c.label }}</span>
                <span v-if="badgeOf(c.badge)" class="cw-menu-badge">{{ badgeOf(c.badge) }}</span>
              </a-menu-item>
            </a-sub-menu>
          </template>
        </a-menu>
      </div>
    </a-layout-sider>

    <a-layout>
      <a-layout-header class="cw-header">
        <a-button type="text" class="cw-icon-btn" @click="collapsed = !collapsed">
          <component :is="collapsed ? MenuUnfoldOutlined : MenuFoldOutlined" />
        </a-button>
        <div class="cw-crumb">{{ pageTitle }}</div>

        <div class="cw-search">
          <a-input
            ref="searchRef"
            v-model:value="keyword"
            placeholder="搜索任务、数据源、记录 ID…"
            allow-clear
            @pressEnter="onSearch"
          >
            <template #prefix><SearchOutlined /></template>
            <template #suffix><span class="cw-kbd">Ctrl + K</span></template>
          </a-input>
        </div>

        <div class="cw-header-right">
          <a-tag :color="system.online ? 'success' : 'warning'" class="cw-live-tag">
            {{ system.online ? '已连接' : '演示数据' }}
          </a-tag>

          <a-dropdown placement="bottomRight">
            <a-badge :count="pendingAlerts" size="small" :offset="[-2, 2]">
              <a-button type="text" shape="circle" class="cw-icon-btn"><BellOutlined /></a-button>
            </a-badge>
            <template #overlay>
              <div class="cw-notify">
                <div class="cw-notify-head">通知</div>
                <a-empty v-if="!pendingAlerts" :image="false" description="暂无新通知" style="padding: 20px" />
                <template v-else>
                  <div class="cw-notify-item" @click="go('/dead-letters')">
                    <a-badge status="error" />
                    <div><b>{{ system.metrics?.dead_letters ?? 0 }} 条死信待处理</b><small>请到死信队列重投或修复</small></div>
                  </div>
                  <div class="cw-notify-item" @click="go('/jobs')">
                    <a-badge status="processing" />
                    <div><b>{{ system.metrics?.pending ?? 0 }} 条消息待确认</b><small>Redis Stream PEL 待 ACK</small></div>
                  </div>
                </template>
              </div>
            </template>
          </a-dropdown>

          <a-tooltip :title="dark ? '切换浅色' : '切换深色'">
            <a-button type="text" shape="circle" class="cw-icon-btn" @click="toggleTheme">
              <component :is="BulbOutlined" />
            </a-button>
          </a-tooltip>

          <a-dropdown placement="bottomRight">
            <div class="cw-user">
              <a-avatar style="background: #2563eb">A</a-avatar>
              <div class="cw-user-meta">
                <b>admin</b>
                <small>管理员</small>
              </div>
            </div>
            <template #overlay>
              <a-menu>
                <a-menu-item key="profile"><UserOutlined /> 个人资料</a-menu-item>
                <a-menu-item key="settings" @click="go('/settings')"><SettingOutlined /> 系统设置</a-menu-item>
                <a-menu-divider />
                <a-menu-item key="logout" disabled><LogoutOutlined /> 退出登录</a-menu-item>
              </a-menu>
            </template>
          </a-dropdown>
        </div>
      </a-layout-header>

      <a-layout-content class="cw-content">
        <router-view v-slot="{ Component }">
          <keep-alive :max="8">
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
  ApartmentOutlined,
  BellOutlined,
  BranchesOutlined,
  BugOutlined,
  BulbOutlined,
  ClusterOutlined,
  CodeOutlined,
  ContainerOutlined,
  ControlOutlined,
  DatabaseOutlined,
  ExportOutlined,
  FieldTimeOutlined,
  HddOutlined,
  HomeOutlined,
  LogoutOutlined,
  MenuFoldOutlined,
  MenuUnfoldOutlined,
  MessageOutlined,
  NodeIndexOutlined,
  PlusSquareOutlined,
  ProfileOutlined,
  RobotOutlined,
  SearchOutlined,
  SettingOutlined,
  TableOutlined,
  ThunderboltOutlined,
  ToolOutlined,
  UnorderedListOutlined,
  UserOutlined,
  WarningOutlined,
} from '@ant-design/icons-vue';
import { MENU, type MenuGroup } from '@/data/menu';
import { dark } from '@/store/theme';
import { refreshSystem, system } from '@/store/system';

const route = useRoute();
const router = useRouter();

const collapsed = ref(false);
const selectedKeys = ref<string[]>([]);
const openKeys = ref<string[]>(['g-crawl']);
const keyword = ref('');
const searchRef = ref();
let timer: number | undefined;

const ICONS: Record<string, unknown> = {
  HomeOutlined,
  RobotOutlined,
  MessageOutlined,
  ThunderboltOutlined,
  NodeIndexOutlined,
  UnorderedListOutlined,
  ProfileOutlined,
  PlusSquareOutlined,
  FieldTimeOutlined,
  DatabaseOutlined,
  TableOutlined,
  SettingOutlined,
  ApartmentOutlined,
  CodeOutlined,
  BranchesOutlined,
  HddOutlined,
  ContainerOutlined,
  SearchOutlined,
  ExportOutlined,
  WarningOutlined,
  BugOutlined,
  ToolOutlined,
  ClusterOutlined,
  ControlOutlined,
};

function iconOf(name?: string) {
  return (name && ICONS[name]) || HomeOutlined;
}

const pageTitle = computed(() => (route.meta?.title as string) || '首页');

const pendingAlerts = computed(
  () => (system.metrics?.dead_letters ?? 0) + (system.metrics?.pending ?? 0),
);

function badgeOf(kind?: 'dead' | 'running') {
  if (!kind) return 0;
  if (kind === 'dead') return system.metrics?.dead_letters ?? 0;
  return system.metrics?.pending ?? 0;
}

function syncMenu() {
  const active = (route.meta?.activeMenu as string) || route.path;
  selectedKeys.value = [active];
  for (const node of MENU as MenuGroup[]) {
    if (node.children?.some((c) => active === c.key || active.startsWith(c.key + '/'))) {
      if (!openKeys.value.includes(node.key)) openKeys.value = [...openKeys.value, node.key];
    }
  }
}

function onOpenChange(keys: (string | number)[]) {
  openKeys.value = keys.map(String);
}

function onMenuClick({ key }: { key: string | number }) {
  router.push(String(key));
}

function go(path: string) {
  router.push(path);
}

function onSearch() {
  const kw = keyword.value.trim();
  if (!kw) return;
  router.push({ path: '/records', query: { keyword: kw } });
}

function toggleTheme() {
  dark.value = !dark.value;
}

function onKeydown(e: KeyboardEvent) {
  if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
    e.preventDefault();
    searchRef.value?.focus?.();
  }
}

watch(() => route.path, syncMenu, { immediate: true });

onMounted(() => {
  refreshSystem();
  timer = window.setInterval(refreshSystem, 20000);
  window.addEventListener('keydown', onKeydown);
});

onUnmounted(() => {
  if (timer) window.clearInterval(timer);
  window.removeEventListener('keydown', onKeydown);
});
</script>

<style scoped>
.cw-layout {
  min-height: 100vh;
}

.cw-sider {
  position: sticky;
  top: 0;
  height: 100vh;
  background: var(--cw-sidebar) !important;
}

.cw-logo {
  display: flex;
  align-items: center;
  gap: 10px;
  height: 60px;
  padding: 0 16px;
  border-bottom: 1px solid rgba(255, 255, 255, 0.06);
  overflow: hidden;
  flex-shrink: 0;
}

.cw-logo-mark {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 34px;
  height: 34px;
  border-radius: 10px;
  background: linear-gradient(135deg, #3b82f6, #7c3aed);
  color: #fff;
  font-weight: 800;
  font-size: 13px;
  flex-shrink: 0;
}

.cw-logo-text .t1 {
  color: #fff;
  font-size: 13px;
  font-weight: 650;
  line-height: 1.2;
  white-space: nowrap;
}
.cw-logo-text .t2 {
  color: rgba(255, 255, 255, 0.45);
  font-size: 11px;
  white-space: nowrap;
}

.cw-sider-scroll {
  flex: 1;
  overflow-y: auto;
  overflow-x: hidden;
  padding: 6px 0 16px;
}
.cw-sider-scroll::-webkit-scrollbar {
  width: 6px;
}
.cw-sider-scroll::-webkit-scrollbar-thumb {
  background: rgba(255, 255, 255, 0.12);
}

.cw-sider :deep(.ant-menu) {
  background: transparent !important;
  border-inline-end: none !important;
}
.cw-sider :deep(.ant-menu-item),
.cw-sider :deep(.ant-menu-submenu-title) {
  width: auto;
  margin: 2px 8px;
  border-radius: 8px;
  color: var(--cw-sidebar-text);
  height: 40px;
  line-height: 40px;
}
.cw-sider :deep(.ant-menu-item-selected) {
  background: linear-gradient(135deg, #2563eb, #1d4ed8) !important;
  color: #fff !important;
}
.cw-sider :deep(.ant-menu-item-selected .ant-menu-item-icon),
.cw-sider :deep(.ant-menu-item-selected a) {
  color: #fff !important;
}
.cw-sider :deep(.ant-menu-item:hover),
.cw-sider :deep(.ant-menu-submenu-title:hover) {
  background: var(--cw-sidebar-2) !important;
  color: #fff !important;
}
.cw-sider :deep(.ant-menu-sub.ant-menu-inline) {
  background: transparent !important;
}
.cw-sider :deep(.ant-menu-inline .ant-menu-item) {
  padding-left: 44px !important;
}

.cw-group-title {
  font-size: 13px;
}

.cw-menu-badge {
  float: right;
  margin-left: auto;
  background: rgba(148, 163, 184, 0.18);
  color: #cbd5e1;
  font-size: 11px;
  padding: 0 7px;
  border-radius: 9px;
  line-height: 18px;
  align-self: center;
}

.cw-header {
  display: flex;
  align-items: center;
  gap: 12px;
  height: 60px;
  padding: 0 18px;
  background: var(--cw-panel);
  border-bottom: 1px solid var(--cw-line);
  position: sticky;
  top: 0;
  z-index: 10;
}

.cw-icon-btn {
  color: var(--cw-muted);
  font-size: 16px;
}

.cw-crumb {
  font-size: 15px;
  font-weight: 650;
  white-space: nowrap;
}

.cw-search {
  margin-left: 12px;
  max-width: 380px;
  flex: 1;
}
.cw-search :deep(.ant-input-affix-wrapper) {
  border-radius: 20px;
  background: var(--cw-bg);
  border-color: transparent;
}
.cw-kbd {
  font-size: 11px;
  color: var(--cw-muted);
  border: 1px solid var(--cw-line);
  border-radius: 5px;
  padding: 1px 6px;
  white-space: nowrap;
}

.cw-header-right {
  margin-left: auto;
  display: flex;
  align-items: center;
  gap: 6px;
}

.cw-live-tag {
  margin-right: 4px;
}

.cw-user {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 4px 8px;
  border-radius: 8px;
  cursor: pointer;
}
.cw-user:hover {
  background: var(--cw-bg);
}
.cw-user-meta {
  line-height: 1.15;
}
.cw-user-meta b {
  display: block;
  font-size: 13px;
}
.cw-user-meta small {
  color: var(--cw-muted);
  font-size: 11px;
}

.cw-content {
  background: var(--cw-bg);
  min-height: calc(100vh - 60px);
}

.cw-notify {
  width: 300px;
  background: var(--cw-panel);
  border-radius: 10px;
  box-shadow: 0 8px 28px rgba(15, 23, 42, 0.16);
  overflow: hidden;
}
.cw-notify-head {
  padding: 10px 14px;
  font-weight: 650;
  border-bottom: 1px solid var(--cw-line);
}
.cw-notify-item {
  display: flex;
  gap: 10px;
  padding: 12px 14px;
  cursor: pointer;
  border-bottom: 1px solid var(--cw-line);
}
.cw-notify-item:hover {
  background: var(--cw-bg);
}
.cw-notify-item b {
  display: block;
  font-size: 13px;
}
.cw-notify-item small {
  color: var(--cw-muted);
  font-size: 12px;
}
</style>
