<template>
  <div class="cw-page">
    <div class="cw-page-title">
      <div>
        <h2>字段映射</h2>
        <div class="sub">外部字段 → Canonical 字段的映射关系（只读视图）</div>
      </div>
      <a-button @click="load">刷新</a-button>
    </div>

    <a-card :bordered="false" class="cw-card">
      <a-space style="margin-bottom: 14px">
        <a-select v-model:value="source" style="width: 200px" :options="sourceOptions" @change="load" />
        <a-tag v-if="schema" :color="schema.status === 'published' ? 'success' : 'default'">
          v{{ schema.version }} · {{ schema.status }}
        </a-tag>
        <a-button v-if="source" type="primary" ghost @click="$router.push(`/sources/${source}/schema`)">编辑 Schema</a-button>
      </a-space>

      <a-table v-if="schema" :columns="columns" :data-source="schema.fields" row-key="field" size="middle" :pagination="false">
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'field'"><span class="cw-mono">{{ record.field }}</span></template>
          <template v-else-if="column.key === 'source_path'"><span class="cw-mono">{{ record.source_path }}</span></template>
          <template v-else-if="column.key === 'type'"><a-tag>{{ record.type }}</a-tag></template>
          <template v-else-if="column.key === 'required'">
            <a-tag :color="record.required ? 'red' : 'default'">{{ record.required ? '必填' : '可选' }}</a-tag>
          </template>
        </template>
      </a-table>
      <a-empty v-else description="该数据源尚未配置 Schema" />

      <template v-if="schema">
        <h3 style="margin: 20px 0 12px">分页定位</h3>
        <a-descriptions :column="4" size="small" bordered>
          <a-descriptions-item label="items">{{ schema.pagination.items || '-' }}</a-descriptions-item>
          <a-descriptions-item label="page">{{ schema.pagination.page || '-' }}</a-descriptions-item>
          <a-descriptions-item label="page_count">{{ schema.pagination.page_count || '-' }}</a-descriptions-item>
          <a-descriptions-item label="total">{{ schema.pagination.total || '-' }}</a-descriptions-item>
        </a-descriptions>
      </template>
    </a-card>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import type { TableColumnsType } from 'ant-design-vue';
import { api } from '@/api';
import type { Schema, SourceEntry } from '@/api/types';

const sources = ref<SourceEntry[]>([]);
const source = ref('');
const schema = ref<Schema | null>(null);

const columns: TableColumnsType = [
  { title: 'Canonical 字段', key: 'field', width: 200 },
  { title: '外部字段路径', key: 'source_path', width: 260 },
  { title: '类型', key: 'type', width: 110 },
  { title: '必填', key: 'required', width: 90 },
  { title: '备注', dataIndex: 'remark', key: 'remark' },
];

const sourceOptions = computed(() => sources.value.map((s) => ({ label: s.source, value: s.source })));

async function load() {
  if (!source.value) {
    schema.value = null;
    return;
  }
  const list = await api.listSchemas(source.value);
  schema.value = list.find((s) => s.status === 'published') ?? list[0] ?? null;
}

onMounted(async () => {
  const res = await api.sources();
  sources.value = res.sources ?? [];
  source.value = sources.value[0]?.source || '';
  await load();
});
</script>
