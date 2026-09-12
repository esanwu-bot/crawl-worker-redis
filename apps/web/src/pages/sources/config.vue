<template>
  <div class="cw-page">
    <div class="cw-page-title">
      <div>
        <h2>数据源配置</h2>
        <div class="sub">Source Definition：连接信息、端点与分页参数（配置式数据源）</div>
      </div>
      <a-space>
        <a-button @click="$router.push('/sources')">数据源列表</a-button>
        <a-button @click="load">刷新</a-button>
      </a-space>
    </div>

    <a-card :bordered="false" class="cw-card">
      <a-table :columns="columns" :data-source="sources" row-key="source" size="middle" :pagination="false">
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'source'">
            <b>{{ record.name || record.source }}</b>
            <div class="cw-mono cw-sub">{{ record.source }}</div>
          </template>
          <template v-else-if="column.key === 'api_base'">
            <span class="cw-mono cw-sub">{{ record.api_base || '-' }}</span>
          </template>
          <template v-else-if="column.key === 'detail_url'">
            <span class="cw-mono cw-sub">{{ record.detail_url || '-' }}</span>
          </template>
          <template v-else-if="column.key === 'status'">
            <a-tag :color="record.status === 'disabled' ? 'default' : 'success'">{{ record.status === 'disabled' ? '停用' : '启用' }}</a-tag>
          </template>
          <template v-else-if="column.key === 'actions'">
            <a @click="$router.push('/sources')">管理</a>
          </template>
        </template>
      </a-table>
    </a-card>

    <a-card :bordered="false" class="cw-card" title="采集单元目录" style="margin-top: 16px">
      <a-table :columns="unitColumns" :data-source="units" row-key="type_id" size="small" :pagination="{ pageSize: 10 }">
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'type_id'"><span class="cw-mono">{{ record.type_id }}</span></template>
        </template>
      </a-table>
    </a-card>
  </div>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue';
import type { TableColumnsType } from 'ant-design-vue';
import { api } from '@/api';
import type { SourceEntry, SourceTypeRow } from '@/api/types';

const sources = ref<SourceEntry[]>([]);
const units = ref<SourceTypeRow[]>([]);

const columns: TableColumnsType = [
  { title: '数据源', key: 'source', width: 180 },
  { title: 'Adapter', dataIndex: 'adapter', key: 'adapter', width: 100 },
  { title: 'Entity', dataIndex: 'entity', key: 'entity', width: 90 },
  { title: 'API Base', key: 'api_base' },
  { title: 'Detail URL', key: 'detail_url' },
  { title: 'Page Size', dataIndex: 'page_size', key: 'page_size', width: 90 },
  { title: '状态', key: 'status', width: 90 },
  { title: '操作', key: 'actions', width: 80 },
];
const unitColumns: TableColumnsType = [
  { title: '数据源', dataIndex: 'source', key: 'source', width: 120 },
  { title: 'Type ID', key: 'type_id', width: 100 },
  { title: '中文名', dataIndex: 'cn_name', key: 'cn_name' },
  { title: '英文名', dataIndex: 'en_name', key: 'en_name' },
];

async function load() {
  const res = await api.sources();
  sources.value = res.sources ?? [];
  units.value = (res.units as SourceTypeRow[]) ?? [];
}
onMounted(load);
</script>

<style scoped>
.cw-sub {
  font-size: 12px;
  color: var(--cw-muted);
}
</style>
