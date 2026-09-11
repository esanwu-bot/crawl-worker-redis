<template>
  <div class="cw-page">
    <div class="cw-page-title">
      <div>
        <h2>死信队列</h2>
        <div class="sub">超过最大重试次数后进入 tasks:dead 的任务 · 可重新投递</div>
      </div>
      <a-space>
        <a-button :disabled="!selected.length" :loading="requeueing" @click="requeue(selected)">
          <template #icon><RedoOutlined /></template>
          重新投递选中 ({{ selected.length }})
        </a-button>
        <a-popconfirm title="确认重新投递全部死信？" @confirm="requeue([])">
          <a-button type="primary" :loading="requeueing">全部重投</a-button>
        </a-popconfirm>
        <a-button @click="load"><template #icon><ReloadOutlined /></template>刷新</a-button>
      </a-space>
    </div>

    <a-card :bordered="false">
      <a-alert
        v-if="!dead.length && !loading"
        type="success"
        show-icon
        message="当前没有死信，任务链路健康。"
        style="margin-bottom: 12px"
      />
      <a-table
        :columns="columns"
        :data-source="dead"
        :loading="loading"
        row-key="id"
        size="middle"
        :row-selection="{ selectedRowKeys: selected, onChange: onSelect }"
        :pagination="{ pageSize: 20, showSizeChanger: false }"
      >
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'id'">
            <a class="cw-mono cw-clickable" @click="open(record)">{{ record.id }}</a>
          </template>
          <template v-else-if="column.key === 'reason'">
            <a-tag color="error">{{ reason(record) }}</a-tag>
          </template>
          <template v-else-if="column.key === 'page'">
            <span class="cw-mono">{{ record.payload?.cursor?.page ?? record.payload?.page ?? '-' }}</span>
          </template>
        </template>
      </a-table>
    </a-card>

    <a-drawer v-model:open="drawerOpen" title="死信详情" width="680" placement="right">
      <template v-if="current">
        <a-descriptions :column="1" size="small" bordered style="margin-bottom: 16px">
          <a-descriptions-item label="消息 ID">
            <span class="cw-mono">{{ current.id }}</span>
          </a-descriptions-item>
          <a-descriptions-item label="数据源">{{ current.payload?.source }}</a-descriptions-item>
          <a-descriptions-item label="Entity">{{ current.payload?.entity }}</a-descriptions-item>
          <a-descriptions-item label="失败原因">{{ reason(current) }}</a-descriptions-item>
          <a-descriptions-item label="发生时间">{{ current.payload?._dead_at || '-' }}</a-descriptions-item>
        </a-descriptions>
        <h4>任务载荷</h4>
        <pre class="cw-pre cw-mono">{{ prettyJSON(current.payload) }}</pre>
        <a-button type="primary" style="margin-top: 16px" :loading="requeueing" @click="requeue([current.id])">
          重新投递此消息
        </a-button>
      </template>
    </a-drawer>
  </div>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { message } from 'ant-design-vue';
import type { TableColumnsType } from 'ant-design-vue';
import { RedoOutlined, ReloadOutlined } from '@ant-design/icons-vue';
import { api } from '@/api';
import type { DeadMessage } from '@/api/types';
import { prettyJSON } from '@/utils/format';

const loading = ref(false);
const requeueing = ref(false);
const dead = ref<DeadMessage[]>([]);
const selected = ref<string[]>([]);
const drawerOpen = ref(false);
const current = ref<DeadMessage | null>(null);

const columns: TableColumnsType = [
  { title: '消息 ID', key: 'id', width: 180 },
  { title: '数据源', key: 'source', width: 110, customRender: ({ record }: any) => record.payload?.source || '-' },
  { title: 'Entity', key: 'entity', width: 100, customRender: ({ record }: any) => record.payload?.entity || '-' },
  { title: 'Unit', key: 'unit', width: 100, customRender: ({ record }: any) => record.payload?.unit_id ?? record.payload?.params?.t ?? '-' },
  { title: 'Page', key: 'page', width: 80 },
  { title: '失败原因', key: 'reason' },
  { title: '发生时间', key: 'at', width: 170, customRender: ({ record }: any) => record.payload?._dead_at || '-' },
];

function onSelect(keys: (string | number)[]) {
  selected.value = keys.map(String);
}

function reason(m: DeadMessage): string {
  return String(m.payload?._dead_reason || m.payload?.reason || 'unknown');
}

function open(m: DeadMessage) {
  current.value = m;
  drawerOpen.value = true;
}

async function requeue(ids: string[]) {
  requeueing.value = true;
  try {
    const res = await api.requeueDead(ids, ids.length ? 0 : 500);
    message.success(`已重新投递 ${res.requeued} 条`);
    selected.value = [];
    drawerOpen.value = false;
    await load();
  } catch {
    // 已提示
  } finally {
    requeueing.value = false;
  }
}

async function load() {
  loading.value = true;
  try {
    dead.value = await api.deadLetters(200);
  } catch {
    // 已提示
  } finally {
    loading.value = false;
  }
}

onMounted(load);
</script>
