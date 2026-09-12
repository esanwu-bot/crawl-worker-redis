<template>
  <div class="cw-page">
    <div class="cw-page-title">
      <div>
        <h2>{{ title }}</h2>
        <div class="sub">数据管理与导出</div>
      </div>
      <a-button @click="load">刷新</a-button>
    </div>

    <a-card :bordered="false" class="cw-card">
      <a-tabs v-model:activeKey="active" @change="onTab">
        <a-tab-pane key="datasets" tab="数据集">
          <a-table :columns="dsColumns" :data-source="datasets" row-key="key" size="middle" :pagination="false">
            <template #bodyCell="{ column, record }">
              <template v-if="column.key === 'name'"><b>{{ record.name }}</b><div class="cw-sub cw-mono">{{ record.key }}</div></template>
              <template v-else-if="column.key === 'count'">{{ fmtNum(record.count) }}</template>
              <template v-else-if="column.key === 'actions'"><a @click="$router.push('/records')">浏览</a></template>
            </template>
          </a-table>
        </a-tab-pane>

        <a-tab-pane key="export" tab="数据导出">
          <a-alert type="info" show-icon message="导出服务为规划能力：将支持 CSV / JSON / XLSX 与增量快照。" style="margin-bottom: 16px" />
          <a-form layout="vertical" style="max-width: 560px">
            <a-form-item label="数据集">
              <a-select v-model:value="exportForm.dataset" :options="datasetOptions" />
            </a-form-item>
            <a-form-item label="导出格式">
              <a-radio-group v-model:value="exportForm.format" button-style="solid">
                <a-radio-button value="csv">CSV</a-radio-button>
                <a-radio-button value="json">JSON</a-radio-button>
                <a-radio-button value="xlsx">XLSX</a-radio-button>
              </a-radio-group>
            </a-form-item>
            <a-form-item label="限制条数">
              <a-input-number v-model:value="exportForm.limit" :min="100" :max="100000" :step="1000" style="width: 100%" />
            </a-form-item>
            <a-button type="primary" @click="doExport">生成导出</a-button>
          </a-form>
        </a-tab-pane>
      </a-tabs>
    </a-card>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { message } from 'ant-design-vue';
import type { TableColumnsType } from 'ant-design-vue';
import { api } from '@/api';
import { fmtNum } from '@/utils/format';

const route = useRoute();
const router = useRouter();
const active = ref((route.meta?.tab as string) || 'datasets');
const datasets = ref<{ key: string; name: string; count: number }[]>([]);
const exportForm = reactive({ dataset: '', format: 'csv', limit: 10000 });

const title = computed(() => (active.value === 'export' ? '数据导出' : '数据集'));
const dsColumns: TableColumnsType = [
  { title: '数据集', key: 'name' },
  { title: '记录数', key: 'count', width: 140, align: 'right' },
  { title: '操作', key: 'actions', width: 90 },
];
const datasetOptions = computed(() => datasets.value.map((d) => ({ label: `${d.name}（${fmtNum(d.count)}）`, value: d.key })));

const TAB_PATH: Record<string, string> = { datasets: '/data/datasets', export: '/data/export' };
function onTab(key: string) {
  router.replace(TAB_PATH[key] || '/data/datasets');
}
watch(
  () => route.meta.tab,
  (t) => {
    if (t && t !== active.value) active.value = t as string;
  },
);

function doExport() {
  message.info(`已提交导出任务：${exportForm.dataset} · ${exportForm.format.toUpperCase()}（演示）`);
}

async function load() {
  const m = await api.metrics();
  datasets.value = (m.records_by_entity ?? []).map((e) => ({
    key: `${e.source}/${e.entity}`,
    name: `${e.source} / ${e.entity}`,
    count: e.count,
  }));
  if (!exportForm.dataset && datasets.value.length) exportForm.dataset = datasets.value[0].key;
}
onMounted(load);
</script>

<style scoped>
.cw-sub {
  font-size: 12px;
  color: var(--cw-muted);
}
</style>
