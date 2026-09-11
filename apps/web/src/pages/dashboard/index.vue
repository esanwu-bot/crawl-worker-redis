<template>
  <div class="cw-page">
    <div class="cw-page-title">
      <div>
        <h2>仪表盘</h2>
        <div class="sub">采集系统整体运行概览 · 数据来自 crawler-api</div>
      </div>
      <a-space>
        <a-tag color="blue">Redis Stream</a-tag>
        <a-tag color="green">MySQL</a-tag>
      </a-space>
    </div>

    <a-row :gutter="[16, 16]">
      <a-col :xs="24" :sm="12" :md="6">
        <a-card class="cw-stat-card" :bordered="false">
          <div class="cw-stat-value">{{ fmtNum(jobs.length) }}</div>
          <div class="cw-stat-label"><UnorderedListOutlined /> 采集作业</div>
        </a-card>
      </a-col>
      <a-col :xs="24" :sm="12" :md="6">
        <a-card class="cw-stat-card" :bordered="false">
          <div class="cw-stat-value">{{ fmtNum(recordsTotal) }}</div>
          <div class="cw-stat-label"><ProfileOutlined /> 采集记录</div>
        </a-card>
      </a-col>
      <a-col :xs="24" :sm="12" :md="6">
        <a-card class="cw-stat-card" :bordered="false">
          <div class="cw-stat-value" :style="{ color: doneRate >= 90 ? '#52c41a' : '#faad14' }">
            {{ jobs.length ? doneRate.toFixed(1) + '%' : '-' }}
          </div>
          <div class="cw-stat-label"><CheckCircleOutlined /> 作业完成率</div>
        </a-card>
      </a-col>
      <a-col :xs="24" :sm="12" :md="6">
        <a-card class="cw-stat-card" :bordered="false">
          <div class="cw-stat-value">{{ sources.length }}</div>
          <div class="cw-stat-label"><DatabaseOutlined /> 数据源</div>
        </a-card>
      </a-col>
    </a-row>

    <a-row :gutter="[16, 16]" style="margin-top: 16px">
      <a-col :xs="24" :lg="14">
        <a-card :bordered="false" title="记录分布（按数据源 / Entity）">
          <EChart :option="entityOption" height="300px" />
        </a-card>
      </a-col>
      <a-col :xs="24" :lg="10">
        <a-card :bordered="false" title="作业状态分布">
          <EChart :option="statusOption" height="300px" />
        </a-card>
      </a-col>
    </a-row>

    <a-row :gutter="[16, 16]" style="margin-top: 16px">
      <a-col :xs="24" :lg="14">
        <a-card :bordered="false" title="最近作业">
          <a-table
            :columns="recentColumns"
            :data-source="jobs.slice(0, 8)"
            :pagination="false"
            row-key="id"
            size="middle"
          >
            <template #bodyCell="{ column, record }">
              <template v-if="column.key === 'id'">
                <a class="cw-mono cw-clickable" @click="openJob(record.id)">
                  {{ shortId(record.id) }}
                </a>
              </template>
              <template v-else-if="column.key === 'status'">
                <a-tag :color="jobStatusMeta(record.status).color">
                  {{ jobStatusMeta(record.status).text }}
                </a-tag>
              </template>
              <template v-else-if="column.key === 'progress'">
                <a-progress
                  :percent="progressPercent(record)"
                  size="small"
                  :status="record.status === 'dead' ? 'exception' : undefined"
                />
              </template>
              <template v-else-if="column.key === 'records'">
                {{ fmtNum(record.records) }}
              </template>
            </template>
          </a-table>
          <a-empty v-if="!jobs.length" description="暂无作业" />
        </a-card>
      </a-col>
      <a-col :xs="24" :lg="10">
        <a-card :bordered="false" title="运行时指标">
          <a-descriptions :column="1" size="small" bordered>
            <a-descriptions-item label="任务流长度 (tasks)">{{ fmtNum(metrics?.stream_len) }}</a-descriptions-item>
            <a-descriptions-item label="待确认 PEL">{{ fmtNum(metrics?.pending) }}</a-descriptions-item>
            <a-descriptions-item label="死信 (tasks:dead)">
              <span :style="{ color: (metrics?.dead_letters ?? 0) > 0 ? '#ff4d4f' : undefined }">
                {{ fmtNum(metrics?.dead_letters) }}
              </span>
            </a-descriptions-item>
            <a-descriptions-item label="代理池">
              {{ metrics?.proxy?.enabled ? `启用 (${metrics?.proxy?.size})` : '未启用' }}
            </a-descriptions-item>
            <a-descriptions-item label="成功消息">
              {{ fmtNum(counter('success')) }}
            </a-descriptions-item>
            <a-descriptions-item label="失败消息">
              {{ fmtNum(counter('fail')) }}
            </a-descriptions-item>
          </a-descriptions>
        </a-card>
        <a-card :bordered="false" title="正在运行" style="margin-top: 16px">
          <a-empty v-if="!running.length" description="当前没有运行中的作业" />
          <div v-for="j in running" :key="j.id" class="cw-running-item" @click="openJob(j.id)">
            <a-badge status="processing" />
            <div style="flex: 1; margin: 0 8px">
              <div>{{ j.source }} · {{ unitText(j) }}</div>
              <a-progress :percent="progressPercent(j)" size="small" />
            </div>
          </div>
        </a-card>
      </a-col>
    </a-row>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import type { TableColumnsType } from 'ant-design-vue';
import {
  CheckCircleOutlined,
  DatabaseOutlined,
  ProfileOutlined,
  UnorderedListOutlined,
} from '@ant-design/icons-vue';
import type { EChartsOption } from 'echarts';
import EChart from '@/components/EChart.vue';
import { api } from '@/api';
import type { EntityStat, Job, MetricsResp, SourceEntry } from '@/api/types';
import { fmtNum, jobStatusMeta, progressPercent } from '@/utils/format';

const router = useRouter();
const metrics = ref<MetricsResp | null>(null);
const jobs = ref<Job[]>([]);
const sources = ref<SourceEntry[]>([]);

const recordsTotal = computed(() =>
  (metrics.value?.records_by_entity ?? []).reduce((s, e) => s + e.count, 0),
);

const doneRate = computed(() => {
  if (!jobs.value.length) return 0;
  const done = jobs.value.filter((j) => j.status === 'done').length;
  return (done / jobs.value.length) * 100;
});

const running = computed(() => jobs.value.filter((j) => j.status === 'active'));

const recentColumns: TableColumnsType = [
  { title: 'Job ID', key: 'id', width: 150 },
  { title: '数据源', dataIndex: 'source', key: 'source', width: 110 },
  { title: '状态', key: 'status', width: 100 },
  { title: '进度', key: 'progress', width: 160 },
  { title: '记录', key: 'records', width: 100, align: 'right' },
];

const entityOption = computed<EChartsOption>(() => {
  const list: EntityStat[] = metrics.value?.records_by_entity ?? [];
  return {
    tooltip: { trigger: 'axis' },
    grid: { left: 40, right: 20, top: 30, bottom: 40 },
    xAxis: {
      type: 'category',
      data: list.map((e) => `${e.source}/${e.entity}`),
      axisLabel: { rotate: list.length > 4 ? 30 : 0 },
    },
    yAxis: { type: 'value' },
    series: [
      {
        type: 'bar',
        data: list.map((e) => e.count),
        itemStyle: { color: '#1677ff', borderRadius: [4, 4, 0, 0] },
        barMaxWidth: 48,
      },
    ],
  };
});

const statusOption = computed<EChartsOption>(() => {
  const map = new Map<string, number>();
  for (const j of jobs.value) {
    const label = jobStatusMeta(j.status).text;
    map.set(label, (map.get(label) || 0) + 1);
  }
  const data = [...map.entries()].map(([name, value]) => ({ name, value }));
  return {
    tooltip: { trigger: 'item' },
    legend: { bottom: 0 },
    series: [
      {
        type: 'pie',
        radius: ['45%', '70%'],
        avoidLabelOverlap: true,
        itemStyle: { borderRadius: 6, borderColor: '#fff', borderWidth: 2 },
        label: { formatter: '{b}: {c}' },
        data,
      },
    ],
  };
});

function openJob(id: string) {
  router.push(`/jobs/${id}`);
}

function shortId(id: string) {
  return id?.length > 14 ? id.slice(0, 6) + '…' + id.slice(-4) : id;
}

function unitText(j: Job) {
  const names = (j.scope ?? []).map((s) => s.unit_name || s.unit_id).slice(0, 3);
  return names.length ? names.join('、') : `${j.units_total} 个单元`;
}

function counter(key: string): number {
  const v = metrics.value?.counters?.[key];
  return v ? Number(v) : 0;
}

async function load() {
  try {
    const [m, j, s] = await Promise.all([api.metrics(), api.listJobs({ limit: 50 }), api.sources()]);
    metrics.value = m;
    jobs.value = j;
    sources.value = s.sources ?? [];
  } catch {
    // 错误已由拦截器提示
  }
}

onMounted(load);
</script>

<style scoped>
.cw-running-item {
  display: flex;
  align-items: center;
  padding: 8px 0;
  cursor: pointer;
}
.cw-running-item:hover {
  background: #fafafa;
}
</style>
