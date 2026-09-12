<template>
  <div class="cw-page">
    <div class="cw-page-title">
      <div>
        <h2>Schema 编辑器</h2>
        <div class="sub">Source → Entity → Schema：字段映射、分页定位、版本发布</div>
      </div>
      <a-button @click="load">刷新</a-button>
    </div>

    <a-card :bordered="false" class="cw-card" title="选择数据源 / Entity">
      <a-table :columns="columns" :data-source="rows" row-key="source" size="middle" :pagination="false">
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'source'">
            <b>{{ record.name }}</b>
            <div class="cw-mono cw-sub">{{ record.source }}</div>
          </template>
          <template v-else-if="column.key === 'entity'"><a-tag>{{ record.entity }}</a-tag></template>
          <template v-else-if="column.key === 'schema'">
            <a-tag v-if="record.schema" :color="record.schema.status === 'published' ? 'success' : 'default'">
              v{{ record.schema.version }} · {{ record.schema.status === 'published' ? '已发布' : '草稿' }}（{{ record.schema.fields.length }} 字段）
            </a-tag>
            <a-tag v-else color="warning">未配置</a-tag>
          </template>
          <template v-else-if="column.key === 'actions'">
            <a-space>
              <a @click="$router.push(`/sources/${record.source}/schema`)">编辑 Schema</a>
              <a @click="$router.push('/schemas/mapping')">字段映射</a>
            </a-space>
          </template>
        </template>
      </a-table>
    </a-card>
  </div>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue';
import type { TableColumnsType } from 'ant-design-vue';
import { api } from '@/api';
import type { Schema, SourceEntry } from '@/api/types';

interface Row {
  source: string;
  name: string;
  entity: string;
  schema: Schema | null;
}

const rows = ref<Row[]>([]);
const columns: TableColumnsType = [
  { title: '数据源', key: 'source', width: 200 },
  { title: 'Entity', key: 'entity', width: 120 },
  { title: '当前 Schema', key: 'schema', width: 260 },
  { title: '操作', key: 'actions', width: 200 },
];

async function load() {
  const res = await api.sources();
  const list = res.sources ?? [];
  const out: Row[] = [];
  for (const s of list as SourceEntry[]) {
    let schema: Schema | null = null;
    try {
      const schemas = await api.listSchemas(s.source, s.entity);
      schema = schemas.find((x) => x.status === 'published') ?? schemas[0] ?? null;
    } catch {
      schema = null;
    }
    out.push({ source: s.source, name: s.name || s.source, entity: s.entity, schema });
  }
  rows.value = out;
}
onMounted(load);
</script>

<style scoped>
.cw-sub {
  font-size: 12px;
  color: var(--cw-muted);
}
</style>
