<template>
  <div class="cw-page">
    <div class="cw-page-title">
      <div>
        <h2>系统设置</h2>
        <div class="sub">运行时信息与界面偏好</div>
      </div>
      <a-button @click="load">刷新</a-button>
    </div>

    <a-row :gutter="[16, 16]">
      <a-col :xs="24" :lg="14">
        <a-card :bordered="false" class="cw-card" title="运行时信息">
          <a-descriptions :column="1" bordered size="small">
            <a-descriptions-item label="后端地址">{{ apiBase }}</a-descriptions-item>
            <a-descriptions-item label="连接状态">
              <a-tag :color="system.online ? 'success' : 'warning'">{{ system.online ? '已连接' : '演示数据' }}</a-tag>
            </a-descriptions-item>
            <a-descriptions-item label="Redis">{{ system.health?.redis || 'ok' }}</a-descriptions-item>
            <a-descriptions-item label="MySQL">{{ system.health?.database || 'ok' }}</a-descriptions-item>
            <a-descriptions-item label="运行时长">{{ uptime }}</a-descriptions-item>
            <a-descriptions-item label="最后刷新">{{ system.lastUpdated || '-' }}</a-descriptions-item>
          </a-descriptions>
        </a-card>

        <a-card :bordered="false" class="cw-card" title="运行时指标" style="margin-top: 16px">
          <a-descriptions :column="2" bordered size="small">
            <a-descriptions-item label="任务流长度">{{ system.metrics?.stream_len ?? '-' }}</a-descriptions-item>
            <a-descriptions-item label="待确认 PEL">{{ system.metrics?.pending ?? '-' }}</a-descriptions-item>
            <a-descriptions-item label="死信数量">{{ system.metrics?.dead_letters ?? '-' }}</a-descriptions-item>
            <a-descriptions-item label="代理池">{{ system.metrics?.proxy?.enabled ? '启用' : '未启用' }}</a-descriptions-item>
          </a-descriptions>
        </a-card>
      </a-col>

      <a-col :xs="24" :lg="10">
        <a-card :bordered="false" class="cw-card" title="界面偏好">
          <div class="cw-set-row">
            <div><b>深色模式</b><small>切换控制台主题</small></div>
            <a-switch v-model:checked="dark" />
          </div>
          <div class="cw-set-row">
            <div><b>自动刷新</b><small>每 20 秒刷新系统状态</small></div>
            <a-switch checked disabled />
          </div>
        </a-card>

        <a-card :bordered="false" class="cw-card" title="数据边界" style="margin-top: 16px">
          <a-typography-paragraph style="color: var(--cw-muted); font-size: 13px">
            控制台只通过 <span class="cw-mono">crawler-api</span> 读取采集数据；
            MySQL 与 Redis 由 Runtime 持有，UI 不直接访问数据库。
          </a-typography-paragraph>
        </a-card>
      </a-col>
    </a-row>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted } from 'vue';
import { dark } from '@/store/theme';
import { refreshSystem, system } from '@/store/system';

const apiBase = (import.meta.env.VITE_API_BASE as string) || '/api/v1';

const uptime = computed(() => {
  const s = system.health?.uptime_s ?? 0;
  const d = Math.floor(s / 86400);
  const h = Math.floor((s % 86400) / 3600);
  const m = Math.floor((s % 3600) / 60);
  return `${d} 天 ${h} 时 ${m} 分`;
});

async function load() {
  await refreshSystem();
}
onMounted(load);
</script>

<style scoped>
.cw-set-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 12px 0;
  border-bottom: 1px solid #f1f5f9;
}
.cw-set-row:last-child {
  border-bottom: 0;
}
.cw-set-row b {
  display: block;
  font-size: 13px;
}
.cw-set-row small {
  color: var(--cw-muted);
  font-size: 12px;
}
</style>
