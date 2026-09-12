<template>
  <div class="cw-page">
    <div class="cw-page-title">
      <div>
        <h2>数据浏览</h2>
        <div class="sub">Canonical + Domain Payload + Raw 三层保留</div>
      </div>
    </div>

    <a-card :bordered="false">
      <a-space wrap style="margin-bottom: 12px">
        <a-select
          v-model:value="query.source"
          placeholder="数据源"
          style="width: 150px"
          allow-clear
          :options="sourceOptions"
        />
        <a-input v-model:value="query.entity" placeholder="Entity" style="width: 120px" allow-clear />
        <a-input v-model:value="query.unit_id" placeholder="Unit ID" style="width: 120px" allow-clear />
        <a-input-search
          v-model:value="query.keyword"
          placeholder="按标题搜索"
          style="width: 220px"
          @search="load"
        />
        <a-button type="primary" @click="load"><template #icon><SearchOutlined /></template>查询</a-button>
      </a-space>

      <a-table
        :columns="columns"
        :data-source="records"
        :loading="loading"
        row-key="external_id"
        size="middle"
        :pagination="{ pageSize: 15, showSizeChanger: false }"
      >
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'external_id'">
            <a class="cw-mono cw-clickable" @click="open(record)">{{ record.external_id }}</a>
          </template>
          <template v-else-if="column.key === 'image'">
            <a-avatar shape="square" :src="record.image" size="small">
              <template #icon><PictureOutlined /></template>
            </a-avatar>
          </template>
          <template v-else-if="column.key === 'title'">
            <a class="cw-clickable" @click="open(record)">{{ record.title || '(无标题)' }}</a>
          </template>
        </template>
      </a-table>
    </a-card>

    <a-drawer v-model:open="drawerOpen" :title="current?.title || '记录详情'" width="720" placement="right">
      <template v-if="current">
        <a-descriptions :column="1" size="small" bordered style="margin-bottom: 16px">
          <a-descriptions-item label="source">{{ current.source }}</a-descriptions-item>
          <a-descriptions-item label="entity">{{ current.entity }}</a-descriptions-item>
          <a-descriptions-item label="unit">{{ current.unit_name }} ({{ current.unit_id }})</a-descriptions-item>
          <a-descriptions-item label="external_id">{{ current.external_id }}</a-descriptions-item>
          <a-descriptions-item label="job_id">
            <a class="cw-mono cw-clickable" @click="goJob(current.job_id)">{{ current.job_id || '-' }}</a>
          </a-descriptions-item>
          <a-descriptions-item v-if="current.url" label="URL">
            <a :href="current.url" target="_blank">{{ current.url }}</a>
          </a-descriptions-item>
        </a-descriptions>

        <a-tabs>
          <a-tab-pane key="payload" tab="Payload">
            <pre class="cw-pre cw-mono">{{ prettyJSON(current.payload_json) || '{}' }}</pre>
          </a-tab-pane>
          <a-tab-pane key="raw" tab="Raw">
            <pre class="cw-pre cw-mono">{{ prettyJSON(current.raw_json) || '{}' }}</pre>
          </a-tab-pane>
        </a-tabs>
      </template>
    </a-drawer>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import type { TableColumnsType } from 'ant-design-vue';
import { PictureOutlined, SearchOutlined } from '@ant-design/icons-vue';
import { api } from '@/api';
import type { RecordView, SourceEntry } from '@/api/types';
import { prettyJSON } from '@/utils/format';

const route = useRoute();
const router = useRouter();
const loading = ref(false);
const records = ref<RecordView[]>([]);
const sources = ref<SourceEntry[]>([]);
const drawerOpen = ref(false);
const current = ref<RecordView | null>(null);

const query = reactive<{ source?: string; entity?: string; unit_id?: string; keyword?: string }>({
  source: undefined,
  entity: '',
  unit_id: '',
  keyword: (route.query.keyword as string) || '',
});

const columns: TableColumnsType = [
  { title: '', key: 'image', width: 56 },
  { title: 'ID', key: 'external_id', width: 120 },
  { title: '标题', key: 'title' },
  { title: '分类', dataIndex: 'unit_name', key: 'unit_name', width: 140 },
  { title: '数据源', dataIndex: 'source', key: 'source', width: 110 },
];

const sourceOptions = computed(() => sources.value.map((s) => ({ label: s.source, value: s.source })));

function open(record: RecordView) {
  current.value = record;
  drawerOpen.value = true;
}

function goJob(id: string) {
  if (id) router.push(`/jobs/${id}`);
}

async function load() {
  loading.value = true;
  try {
    records.value = await api.searchRecords({
      source: query.source,
      entity: query.entity || undefined,
      unit_id: query.unit_id || undefined,
      keyword: query.keyword || undefined,
      limit: 200,
    });
  } catch {
    // 已提示
  } finally {
    loading.value = false;
  }
}

onMounted(async () => {
  await load();
  try {
    const s = await api.sources();
    sources.value = s.sources ?? [];
  } catch {
    // 已提示
  }
});
</script>
