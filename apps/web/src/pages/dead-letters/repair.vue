<template>
  <div class="cw-page">
    <div class="cw-page-title">
      <div>
        <h2>自动修复</h2>
        <div class="sub">死信根因分析与批量重投 / 参数修正</div>
      </div>
      <a-space>
        <a-button @click="$router.push('/dead-letters')">死信队列</a-button>
        <a-button type="primary" :loading="repairing" :disabled="!dead.length" @click="repairAll">一键批量重投</a-button>
      </a-space>
    </div>

    <a-row :gutter="[16, 16]">
      <a-col :xs="24" :sm="8">
        <div class="cw-stat">
          <div class="cw-stat-icon" style="background: linear-gradient(135deg, #ef4444, #dc2626)"><BugOutlined /></div>
          <div>
            <div class="cw-stat-label">死信总数</div>
            <div class="cw-stat-value">{{ dead.length }}</div>
          </div>
        </div>
      </a-col>
      <a-col :xs="24" :sm="8">
        <div class="cw-stat">
          <div class="cw-stat-icon" style="background: linear-gradient(135deg, #f59e0b, #d97706)"><ToolOutlined /></div>
          <div>
            <div class="cw-stat-label">可自动修复</div>
            <div class="cw-stat-value">{{ dead.length }}</div>
          </div>
        </div>
      </a-col>
      <a-col :xs="24" :sm="8">
        <div class="cw-stat">
          <div class="cw-stat-icon" style="background: linear-gradient(135deg, #22c55e, #16a34a)"><CheckCircleOutlined /></div>
          <div>
            <div class="cw-stat-label">已处理</div>
            <div class="cw-stat-value">0</div>
          </div>
        </div>
      </a-col>
    </a-row>

    <a-card :bordered="false" class="cw-card" title="死信与建议" style="margin-top: 16px">
      <a-alert v-if="!dead.length" type="success" show-icon message="当前没有死信，链路健康。" style="margin-bottom: 12px" />
      <a-table :columns="columns" :data-source="dead" row-key="id" size="middle" :pagination="{ pageSize: 10 }">
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'id'"><span class="cw-mono">{{ record.id }}</span></template>
          <template v-else-if="column.key === 'reason'"><a-tag color="error">{{ reason(record) }}</a-tag></template>
          <template v-else-if="column.key === 'advice'"><span class="cw-advice">{{ advice(record) }}</span></template>
          <template v-else-if="column.key === 'actions'">
            <a :class="{ 'cw-disabled': repairing }" @click="repairOne(record)">重投</a>
          </template>
        </template>
      </a-table>
    </a-card>
  </div>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { message } from 'ant-design-vue';
import type { TableColumnsType } from 'ant-design-vue';
import { BugOutlined, CheckCircleOutlined, ToolOutlined } from '@ant-design/icons-vue';
import { api } from '@/api';
import type { DeadMessage } from '@/api/types';

const dead = ref<DeadMessage[]>([]);
const repairing = ref(false);

const columns: TableColumnsType = [
  { title: '消息 ID', key: 'id', width: 190 },
  { title: '数据源', key: 'source', width: 100, customRender: ({ record }: any) => record.payload?.source || '-' },
  { title: '失败原因', key: 'reason', width: 160 },
  { title: '修复建议', key: 'advice' },
  { title: '操作', key: 'actions', width: 90 },
];

function reason(m: DeadMessage): string {
  return String(m.payload?._dead_reason || m.payload?.reason || 'unknown');
}
function advice(m: DeadMessage): string {
  const r = reason(m).toLowerCase();
  if (r.includes('502') || r.includes('503')) return '源站临时不可用：建议稍后重投并增加请求间隔。';
  if (r.includes('timeout')) return '超时：建议重投或降低并发。';
  if (r.includes('parse') || r.includes('json')) return '解析失败：建议检查 Schema 字段映射。';
  return '建议重投，若持续失败请检查数据源配置。';
}

async function repairAll() {
  repairing.value = true;
  try {
    const res = await api.requeueDead([], 500);
    message.success(`已重投 ${res.requeued} 条`);
    await load();
  } catch {
    /* 已提示 */
  } finally {
    repairing.value = false;
  }
}
async function repairOne(m: DeadMessage) {
  repairing.value = true;
  try {
    const res = await api.requeueDead([m.id]);
    message.success(`已重投 ${res.requeued} 条`);
    await load();
  } catch {
    /* 已提示 */
  } finally {
    repairing.value = false;
  }
}

async function load() {
  dead.value = await api.deadLetters(200);
}
onMounted(load);
</script>

<style scoped>
.cw-advice {
  color: var(--cw-muted);
  font-size: 12px;
}
.cw-disabled {
  pointer-events: none;
  opacity: 0.5;
}
</style>
