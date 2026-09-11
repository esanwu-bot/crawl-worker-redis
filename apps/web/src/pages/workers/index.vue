<template>
  <div class="cw-page">
    <div class="cw-page-title">
      <div>
        <h2>Worker / Redis</h2>
        <div class="sub">Redis Stream 任务层与运行时指标</div>
      </div>
      <a-button @click="load"><template #icon><ReloadOutlined /></template>刷新</a-button>
    </div>

    <a-alert
      type="info"
      show-icon
      message="Worker 实例级心跳/CPU/内存需 Runtime 上报（Phase 3），当前展示 Redis Stream 与统计计数。"
      style="margin-bottom: 16px"
    />

    <a-row :gutter="[16, 16]">
      <a-col :xs="24" :sm="8">
        <a-card :bordered="false">
          <a-statistic title="任务流 tasks" :value="metrics?.stream_len ?? 0" />
        </a-card>
      </a-col>
      <a-col :xs="24" :sm="8">
        <a-card :bordered="false">
          <a-statistic title="待确认 PEL" :value="metrics?.pending ?? 0" />
        </a-card>
      </a-col>
      <a-col :xs="24" :sm="8">
        <a-card :bordered="false">
          <a-statistic
            title="死信 tasks:dead"
            :value="metrics?.dead_letters ?? 0"
            :value-style="{ color: (metrics?.dead_letters ?? 0) > 0 ? '#ff4d4f' : undefined }"
          />
        </a-card>
      </a-col>
    </a-row>

    <a-row :gutter="[16, 16]" style="margin-top: 16px">
      <a-col :xs="24" :lg="14">
        <a-card :bordered="false" title="统计计数器">
          <a-table
            :columns="counterColumns"
            :data-source="counterRows"
            :pagination="false"
            row-key="key"
            size="small"
          />
        </a-card>
      </a-col>
      <a-col :xs="24" :lg="10">
        <a-card :bordered="false" title="代理池">
          <a-descriptions :column="1" size="small" bordered>
            <a-descriptions-item label="启用">
              <a-tag :color="metrics?.proxy?.enabled ? 'success' : 'default'">
                {{ metrics?.proxy?.enabled ? '是' : '否' }}
              </a-tag>
            </a-descriptions-item>
            <a-descriptions-item label="可用数量">{{ metrics?.proxy?.size ?? 0 }}</a-descriptions-item>
          </a-descriptions>
        </a-card>
      </a-col>
    </a-row>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import type { TableColumnsType } from 'ant-design-vue';
import { ReloadOutlined } from '@ant-design/icons-vue';
import { api } from '@/api';
import type { MetricsResp } from '@/api/types';

const metrics = ref<MetricsResp | null>(null);

const counterColumns: TableColumnsType = [
  { title: '指标', dataIndex: 'key', key: 'key' },
  { title: '值', dataIndex: 'value', key: 'value', align: 'right', width: 140 },
];

const counterRows = computed(() =>
  Object.entries(metrics.value?.counters ?? {}).map(([key, value]) => ({ key, value })),
);

async function load() {
  try {
    metrics.value = await api.metrics();
  } catch {
    // 已提示
  }
}

onMounted(load);
</script>
