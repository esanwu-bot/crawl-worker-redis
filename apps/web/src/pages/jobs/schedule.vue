<template>
  <div class="cw-page">
    <div class="cw-page-title">
      <div>
        <h2>调度计划</h2>
        <div class="sub">按周期自动触发采集任务（一次性 / 每小时 / 每天 / 每周 / Cron）</div>
      </div>
      <a-button @click="$router.push('/jobs/create')">创建任务</a-button>
    </div>

    <a-alert type="info" show-icon message="调度器（Scheduler）为规划能力：后端将提供 Cron/时区/幂等/死信；当前展示按数据源建议的调度配置与最近触发。" style="margin-bottom: 16px" />

    <a-card :bordered="false" class="cw-card" title="建议调度（按数据源）">
      <a-table :columns="planColumns" :data-source="plans" row-key="source" size="middle" :pagination="false">
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'source'"><a-tag color="blue">{{ record.source }}</a-tag></template>
          <template v-else-if="column.key === 'cron'"><span class="cw-mono">{{ record.cron }}</span></template>
          <template v-else-if="column.key === 'enabled'">
            <a-switch v-model:checked="record.enabled" size="small" />
          </template>
          <template v-else-if="column.key === 'actions'">
            <a @click="runNow(record.source)">立即触发</a>
          </template>
        </template>
      </a-table>
    </a-card>

    <a-card :bordered="false" class="cw-card" title="最近触发记录" style="margin-top: 16px">
      <a-table :columns="recentColumns" :data-source="jobs" row-key="id" size="small" :pagination="{ pageSize: 8 }">
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'id'"><span class="cw-mono">{{ record.id }}</span></template>
          <template v-else-if="column.key === 'status'"><a-tag :color="jobStatusMeta(record.status).color">{{ jobStatusMeta(record.status).text }}</a-tag></template>
          <template v-else-if="column.key === 'created_at'">{{ fmtTime(record.created_at) }}</template>
        </template>
      </a-table>
    </a-card>
  </div>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { message } from 'ant-design-vue';
import type { TableColumnsType } from 'ant-design-vue';
import { api } from '@/api';
import type { Job, SourceEntry } from '@/api/types';
import { fmtTime, jobStatusMeta } from '@/utils/format';

const jobs = ref<Job[]>([]);
const plans = ref<{ source: string; cron: string; enabled: boolean }[]>([]);

const planColumns: TableColumnsType = [
  { title: '数据源', key: 'source', width: 140 },
  { title: '调度', dataIndex: 'name', key: 'name' },
  { title: 'Cron', key: 'cron', width: 160 },
  { title: '启用', key: 'enabled', width: 90 },
  { title: '操作', key: 'actions', width: 100 },
];
const recentColumns: TableColumnsType = [
  { title: '任务 ID', key: 'id', width: 180 },
  { title: '数据源', dataIndex: 'source', key: 'source', width: 110 },
  { title: '状态', key: 'status', width: 100 },
  { title: '创建时间', key: 'created_at' },
];

async function runNow(source: string) {
  try {
    const res = await api.createJob({ source, max_pages: 3 });
    message.success(`已触发 ${source}：${res.job_id}`);
    await load();
  } catch {
    /* 已提示 */
  }
}

async function load() {
  jobs.value = await api.listJobs({ limit: 50 });
  const res = await api.sources();
  plans.value = (res.sources ?? []).map((s: SourceEntry) => ({
    source: s.source,
    name: `${s.name || s.source} 每日全量`,
    cron: s.source === 'shikues' ? '0 2 * * *' : s.source === 'maccms' ? '0 3 * * *' : '0 4 * * *',
    enabled: s.status !== 'disabled',
  }));
}

onMounted(load);
</script>
