<template>
  <div class="cw-page">
    <div class="cw-page-title">
      <div>
        <h2>Agent 代理</h2>
        <div class="sub">对话 / 意图识别 · Agent 运行 · 思考时间线</div>
      </div>
      <a-button @click="load">刷新</a-button>
    </div>

    <a-card :bordered="false" class="cw-card">
      <a-tabs v-model:activeKey="active" @change="onTab">
        <a-tab-pane key="intent" tab="对话 / 意图">
          <a-alert type="info" show-icon message="自然语言创建采集任务（意图解析）为规划能力，当前展示可直接操作的意图样例。" style="margin-bottom: 16px" />
          <a-row :gutter="16">
            <a-col :span="14">
              <a-textarea v-model:value="intentText" :rows="4" placeholder="例如：采集 MacCMS 韩国剧分类的最新内容，增量更新，最多 100 页" />
              <a-space style="margin-top: 12px">
                <a-button type="primary" @click="parseIntent">解析意图并创建任务</a-button>
                <a-button @click="intentText = ''">清空</a-button>
              </a-space>
              <div style="margin-top: 12px">
                <a-tag v-for="s in intentSamples" :key="s" class="cw-clickable" style="margin-bottom: 6px" @click="intentText = s">{{ s }}</a-tag>
              </div>
              <a-descriptions v-if="parsed" :column="1" bordered size="small" style="margin-top: 16px">
                <a-descriptions-item label="识别数据源">{{ parsed.source }}</a-descriptions-item>
                <a-descriptions-item label="识别范围">{{ parsed.scope }}</a-descriptions-item>
                <a-descriptions-item label="采集模式">{{ parsed.mode }}</a-descriptions-item>
                <a-descriptions-item label="最大页数">{{ parsed.pages }}</a-descriptions-item>
              </a-descriptions>
            </a-col>
            <a-col :span="10">
              <div class="cw-card" style="box-shadow: none; border: 1px solid var(--cw-line)">
                <div class="cw-card-head"><h3>最近意图</h3></div>
                <div class="cw-card-body">
                  <div v-for="j in jobs.slice(0, 5)" :key="j.id" class="cw-intent-row" @click="$router.push(`/jobs/${j.id}`)">
                    <ThunderboltOutlined style="color: #2563eb" />
                    <div><b>{{ jobName(j) }}</b><small>{{ j.id }} · {{ fromNow(j.created_at) }}</small></div>
                  </div>
                  <div v-if="!jobs.length" class="cw-empty">暂无记录</div>
                </div>
              </div>
            </a-col>
          </a-row>
        </a-tab-pane>

        <a-tab-pane key="runs" tab="Agent 运行">
          <a-table :columns="runColumns" :data-source="jobs" row-key="id" size="middle" :pagination="{ pageSize: 10 }">
            <template #bodyCell="{ column, record }">
              <template v-if="column.key === 'id'"><span class="cw-mono">{{ record.id }}</span></template>
              <template v-else-if="column.key === 'name'">{{ jobName(record) }}</template>
              <template v-else-if="column.key === 'status'">
                <a-tag :color="jobStatusMeta(record.status).color">{{ jobStatusMeta(record.status).text }}</a-tag>
              </template>
              <template v-else-if="column.key === 'progress'">
                <a-progress :percent="progressPercent(record)" size="small" />
              </template>
            </template>
          </a-table>
        </a-tab-pane>

        <a-tab-pane key="timeline" tab="思考时间线">
          <div class="cw-timeline">
            <div v-for="(ev, i) in timeline" :key="i" class="cw-timeline-item">
              <span class="cw-timeline-dot" :class="ev.tone"></span>
              <h4>{{ ev.title }}</h4>
              <p>{{ ev.desc }}</p>
            </div>
          </div>
          <div v-if="!timeline.length" class="cw-empty">暂无轨迹</div>
        </a-tab-pane>
      </a-tabs>
    </a-card>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { message } from 'ant-design-vue';
import type { TableColumnsType } from 'ant-design-vue';
import { ThunderboltOutlined } from '@ant-design/icons-vue';
import { api } from '@/api';
import type { Job } from '@/api/types';
import { fromNow, jobStatusMeta, progressPercent } from '@/utils/format';

const route = useRoute();
const router = useRouter();
const jobs = ref<Job[]>([]);
const active = ref((route.meta?.tab as string) || 'intent');
const intentText = ref('');
const parsed = ref<null | { source: string; scope: string; mode: string; pages: number }>(null);

const runColumns: TableColumnsType = [
  { title: 'Run ID', key: 'id', width: 170 },
  { title: '任务', key: 'name' },
  { title: '状态', key: 'status', width: 110 },
  { title: '进度', key: 'progress', width: 200 },
];

const intentSamples = [
  '采集 MacCMS 韩国剧分类最新内容，增量，最多 100 页',
  '全量采集 Shikues 分立元器件产品',
  '重采 TikChip 全部产品并导出',
];

const TAB_PATH: Record<string, string> = {
  intent: '/agents/intent',
  runs: '/agents/runs',
  timeline: '/agents/timeline',
};

function onTab(key: string) {
  router.replace(TAB_PATH[key] || '/agents/intent');
}
watch(
  () => route.meta.tab,
  (t) => {
    if (t && t !== active.value) active.value = t as string;
  },
);

function jobName(j: Job) {
  return `${j.source} · ${j.scope?.[0]?.entity || 'item'}采集`;
}

function parseIntent() {
  const t = intentText.value.toLowerCase();
  const source = t.includes('shikues') ? 'shikues' : t.includes('tikchip') ? 'tikchip' : 'maccms';
  const scope = t.includes('韩国') ? '韩国剧' : t.includes('喜剧') ? '喜剧片' : '全部';
  const mode = t.includes('增量') ? '增量' : '全量';
  const pages = Number((t.match(/(\d+)\s*页/) || [])[1] || 3);
  parsed.value = { source, scope, mode, pages };
  api
    .createJob({ source, max_pages: pages })
    .then((res) => {
      message.success(`已根据意图创建任务 ${res.job_id}`);
      load();
    })
    .catch(() => message.warning('后端未连接，已仅解析意图'));
}

const timeline = computed(() => {
  const j = jobs.value[0];
  if (!j) return [] as { title: string; desc: string; tone: string }[];
  return [
    { title: 'Research · 规划阶段', desc: `分析 ${j.units_total} 个采集单元，准备抓取`, tone: '' },
    { title: `Tool Call · crawler.create_job`, desc: `source=${j.source} scope=${(j.scope ?? []).map((s) => s.unit_name).join('、')}`, tone: 'ok' },
    { title: 'Observe · 记录入库', desc: `已落库 ${j.records} 条记录`, tone: 'ok' },
    { title: 'Next · 继续消费', desc: `运行状态 ${jobStatusMeta(j.status).text}`, tone: j.status === 'dead' ? 'err' : 'warn' },
  ];
});

async function load() {
  jobs.value = await api.listJobs({ limit: 50 });
}
onMounted(load);
</script>

<style scoped>
.cw-intent-row {
  display: flex;
  gap: 10px;
  align-items: center;
  padding: 10px 0;
  border-bottom: 1px solid #f1f5f9;
  cursor: pointer;
}
.cw-intent-row:last-child {
  border-bottom: 0;
}
.cw-intent-row b {
  display: block;
  font-size: 13px;
}
.cw-intent-row small {
  color: var(--cw-muted);
  font-size: 11px;
}
</style>
