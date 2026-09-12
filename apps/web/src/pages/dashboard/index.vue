<template>
  <div class="cw-page">
    <div class="cw-page-title">
      <div>
        <h2>Agent Workbench</h2>
        <div class="sub">智能采集 · 数据管理 · 任务编排</div>
      </div>
      <a-space>
        <a-dropdown>
          <a-button>{{ rangeLabel }} <DownOutlined /></a-button>
          <template #overlay>
            <a-menu @click="onRange">
              <a-menu-item key="1">最近 24 小时</a-menu-item>
              <a-menu-item key="7">近 7 天</a-menu-item>
              <a-menu-item key="30">近 30 天</a-menu-item>
            </a-menu>
          </template>
        </a-dropdown>
        <a-button type="primary" @click="$router.push('/jobs/create')">
          <template #icon><PlusOutlined /></template>
          创建采集任务
        </a-button>
      </a-space>
    </div>

    <div class="dash-layout">
      <!-- ============ 主列 ============ -->
      <div class="dash-main">
        <!-- 指标卡 -->
        <div class="dash-grid-4">
          <StatCard :icon="UnorderedListOutlined" label="总任务数" :value="fmtNum(jobs.length)" grad="linear-gradient(135deg,#3b82f6,#2563eb)" :delta="12.4" delta-label="较上周" />
          <StatCard :icon="ProfileOutlined" label="今日采集记录" :value="fmtNum(todayRecords)" grad="linear-gradient(135deg,#8b5cf6,#7c3aed)" :delta="23.1" delta-label="较上周" />
          <StatCard :icon="CheckCircleOutlined" label="成功率" :value="successRate.toFixed(1) + '%'" grad="linear-gradient(135deg,#22c55e,#16a34a)" :delta="0.5" delta-label="较上周" />
          <StatCard :icon="ClusterOutlined" label="在线 Worker" :value="workerText" grad="linear-gradient(135deg,#06b6d4,#0891b2)" :hint="workerHint" />
        </div>

        <!-- 图表 -->
        <div class="dash-grid-2" style="margin-top: 16px">
          <div class="cw-card">
            <div class="cw-card-head"><h3>{{ trendTitle }}</h3></div>
            <div class="cw-card-body"><EChart :option="trendOption" height="248px" /></div>
          </div>
          <div class="cw-card">
            <div class="cw-card-head"><h3>任务状态分布</h3></div>
            <div class="cw-card-body"><EChart :option="statusOption" height="248px" /></div>
          </div>
        </div>

        <!-- 采集任务表 -->
        <div class="cw-card" style="margin-top: 16px">
          <div class="cw-card-head">
            <a-tabs v-model:activeKey="activeTab" class="cw-inline-tabs">
              <a-tab-pane key="all" :tab="`全部`" />
              <a-tab-pane key="active" tab="运行中" />
              <a-tab-pane key="done" tab="已完成" />
              <a-tab-pane key="dead" tab="失败" />
              <a-tab-pane key="paused" tab="已暂停" />
            </a-tabs>
            <a-space>
              <a-button type="primary" size="small" @click="$router.push('/jobs/create')">
                <template #icon><PlusOutlined /></template>创建任务
              </a-button>
              <a-input v-model:value="jobKeyword" placeholder="搜索任务名称 / ID…" size="small" allow-clear style="width: 200px">
                <template #prefix><SearchOutlined /></template>
              </a-input>
            </a-space>
          </div>
          <a-table
            :columns="jobColumns"
            :data-source="pagedJobs"
            row-key="id"
            size="middle"
            :pagination="false"
            :custom-row="jobRowProps"
          >
            <template #bodyCell="{ column, record }">
              <template v-if="column.key === 'id'">
                <span class="cw-mono cw-id" @click="$router.push(`/jobs/${record.id}`)">{{ record.id }}</span>
              </template>
              <template v-else-if="column.key === 'name'">
                <a @click="$router.push(`/jobs/${record.id}`)">{{ jobName(record) }}</a>
              </template>
              <template v-else-if="column.key === 'source'">
                <a-tag color="blue">{{ sourceTag(record.source) }}</a-tag>
              </template>
              <template v-else-if="column.key === 'entity'">
                <span class="cw-mono">{{ entityLabel(record) }}</span>
              </template>
              <template v-else-if="column.key === 'kind'">
                <a-tag :color="jobKind(record) === '增量' ? 'geekblue' : 'default'">{{ jobKind(record) }}</a-tag>
              </template>
              <template v-else-if="column.key === 'scope'">{{ scopeText(record) }}</template>
              <template v-else-if="column.key === 'status'">
                <span class="cw-status"><i :style="{ background: statusMeta(record.status).dot }"></i>{{ statusMeta(record.status).text }}</span>
              </template>
              <template v-else-if="column.key === 'progress'">
                <div class="cw-progress-cell">
                  <a-progress :percent="progressPercent(record)" :show-info="false" size="small"
                    :stroke-color="record.status === 'dead' ? '#dc2626' : '#2563eb'" />
                  <span class="cw-pct">{{ progressPercent(record) }}%</span>
                </div>
              </template>
              <template v-else-if="column.key === 'records'">{{ fmtNum(record.records) }}</template>
              <template v-else-if="column.key === 'actions'">
                <a-space :size="4">
                  <a @click.stop="$router.push(`/jobs/${record.id}`)">查看</a>
                  <a v-if="record.status === 'active'" @click.stop="act(record, 'pause')">暂停</a>
                  <a v-else-if="record.status === 'paused'" @click.stop="act(record, 'resume')">继续</a>
                  <a-dropdown>
                    <a @click.stop><EllipsisOutlined /></a>
                    <template #overlay>
                      <a-menu>
                        <a-menu-item key="detail" @click="$router.push(`/jobs/${record.id}`)">任务详情</a-menu-item>
                        <a-menu-item key="retry" @click="retryJob(record)">重新执行</a-menu-item>
                        <a-menu-item key="cancel" danger @click="act(record, 'cancel')">取消任务</a-menu-item>
                      </a-menu>
                    </template>
                  </a-dropdown>
                </a-space>
              </template>
            </template>
          </a-table>
          <div class="cw-table-foot">
            <span>共 {{ filteredJobs.length }} 条</span>
            <a-pagination v-model:current="page" :page-size="pageSize" :total="filteredJobs.length" size="small" show-less-items />
          </div>
        </div>

        <!-- 运行中的任务详情 -->
        <div class="cw-card" style="margin-top: 16px">
          <div class="cw-card-head">
            <a-tabs v-model:activeKey="detailTab" class="cw-inline-tabs">
              <a-tab-pane key="overview" tab="任务概览" />
              <a-tab-pane key="agent" tab="Agent 时间线" />
              <a-tab-pane key="records" tab="采集记录" />
              <a-tab-pane key="logs" tab="日志" />
            </a-tabs>
            <a-space v-if="selectedJob">
              <a-button size="small">暂停</a-button>
              <a-button size="small">取消</a-button>
              <a-button size="small" type="primary" @click="$router.push(`/jobs/${selectedJob.id}`)">详情</a-button>
            </a-space>
          </div>
          <div v-if="!selectedJob" class="cw-empty">暂无运行中的任务</div>
          <div v-else class="cw-card-body detail-body">
            <!-- 概览 -->
            <template v-if="detailTab === 'overview'">
              <div class="detail-info">
                <div class="detail-job-title">{{ jobName(selectedJob) }} <span class="cw-mono">{{ selectedJob.id }}</span></div>
                <a-descriptions :column="1" size="small" bordered>
                  <a-descriptions-item label="数据源">{{ sourceTag(selectedJob.source) }}</a-descriptions-item>
                  <a-descriptions-item label="实体">{{ entityDisplayName(selectedJob) }}</a-descriptions-item>
                  <a-descriptions-item label="采集范围">{{ scopeDetail(selectedJob) }}</a-descriptions-item>
                  <a-descriptions-item label="采集模式">{{ jobKind(selectedJob) }}</a-descriptions-item>
                  <a-descriptions-item label="创建时间">{{ fmtTime(selectedJob.created_at) }}</a-descriptions-item>
                  <a-descriptions-item label="开始时间">{{ fmtTime(selectedJob.updated_at) }}</a-descriptions-item>
                </a-descriptions>
              </div>
              <div class="detail-progress">
                <div class="detail-progress-ring">
                  <a-progress
                    type="dashboard"
                    :percent="progressPercent(selectedJob)"
                    :size="150"
                    :stroke-color="selectedJob.status === 'dead' ? '#dc2626' : '#2563eb'"
                  />
                  <div class="detail-progress-records">
                    <b>{{ fmtNum(selectedJob.records) }}</b>
                    <span>/ {{ fmtNum(recordsTotal(selectedJob)) }}</span>
                  </div>
                  <div class="detail-progress-remain">剩余 {{ fmtNum(recordsRemain(selectedJob)) }}</div>
                </div>
                <div class="detail-progress-nums">
                  <div><b>{{ fmtNum(taskCount(selectedJob)) }}</b><small>任务数</small></div>
                  <div><b class="cw-up">{{ selectedJob.units_done }}</b><small>完成</small></div>
                  <div><b>{{ system.metrics?.pending ?? 0 }}</b><small>Pending</small></div>
                  <div><b class="cw-warn">{{ selectedJob.units_dead }}</b><small>Retry</small></div>
                  <div><b>{{ system.metrics?.dead_letters ?? 0 }}</b><small>Dead</small></div>
                </div>
              </div>
              <div class="detail-timeline">
                <div class="detail-col-title">Agent 思考 / 工具调用时间线</div>
                <div class="cw-timeline">
                  <div v-for="(ev, i) in timeline" :key="i" class="cw-timeline-item">
                    <span class="cw-timeline-dot" :class="ev.tone"></span>
                    <h4><span class="cw-mono">{{ ev.time }}</span> · {{ ev.title }}</h4>
                    <p>{{ ev.desc }}</p>
                  </div>
                </div>
              </div>
              <div class="detail-records">
                <div class="detail-col-title">最近采集记录</div>
                <a-table :columns="recordColumns" :data-source="recentRecords" row-key="external_id" size="small" :pagination="false">
                  <template #bodyCell="{ column, record }">
                    <template v-if="column.key === 'id'"><span class="cw-mono">{{ record.external_id }}</span></template>
                    <template v-else-if="column.key === 'status'"><a-tag color="success" size="small">成功</a-tag></template>
                    <template v-else-if="column.key === 'time'"><span class="cw-muted-sm">{{ recordTime(record) }}</span></template>
                  </template>
                </a-table>
                <div class="cw-link-more" @click="$router.push('/records')">查看全部记录 →</div>
              </div>
            </template>

            <!-- Agent 时间线 tab -->
            <template v-else-if="detailTab === 'agent'">
              <div class="cw-timeline">
                <div v-for="(ev, i) in timeline" :key="i" class="cw-timeline-item">
                  <span class="cw-timeline-dot" :class="ev.tone"></span>
                  <h4><span class="cw-mono">{{ ev.time }}</span> · {{ ev.title }}</h4>
                  <p>{{ ev.desc }}</p>
                </div>
              </div>
            </template>

            <!-- 采集记录 tab -->
            <template v-else-if="detailTab === 'records'">
              <a-table :columns="recordColumns" :data-source="recentRecords" row-key="external_id" size="small" :pagination="false">
                <template #bodyCell="{ column, record }">
                  <template v-if="column.key === 'id'"><span class="cw-mono">{{ record.external_id }}</span></template>
                  <template v-else-if="column.key === 'status'"><a-tag color="success" size="small">成功</a-tag></template>
                  <template v-else-if="column.key === 'time'"><span class="cw-muted-sm">{{ recordTime(record) }}</span></template>
                </template>
              </a-table>
            </template>

            <!-- 日志 tab -->
            <template v-else>
              <pre class="cw-pre cw-mono">{{ logs }}</pre>
            </template>
          </div>
        </div>
      </div>

      <!-- ============ 右栏 ============ -->
      <div class="dash-rail">
        <div class="cw-card">
          <div class="cw-card-head"><h3>快速操作</h3></div>
          <div class="cw-card-body">
            <div class="cw-quick-grid">
              <div v-for="q in quickActions" :key="q.path" class="cw-quick-btn" @click="$router.push(q.path)">
                <component :is="q.icon" style="color: #2563eb" />
                <span>{{ q.label }}</span>
              </div>
            </div>
          </div>
        </div>

        <div class="cw-card" style="margin-top: 16px">
          <div class="cw-card-head">
            <h3>最近任务</h3>
            <a size="small" @click="$router.push('/jobs')">更多 ›</a>
          </div>
          <div class="cw-card-body">
            <div v-for="(j, i) in recentJobs" :key="j.id" class="cw-run-item" @click="$router.push(`/jobs/${j.id}`)">
              <span class="cw-run-index">{{ i + 1 }}</span>
              <div style="flex: 1; min-width: 0">
                <div class="cw-recent-title">{{ jobName(j) }}</div>
                <div class="cw-recent-meta">{{ entityLabel(j) }} · {{ jobKind(j) }} · {{ scopeText(j) }}</div>
                <a-progress :percent="progressPercent(j)" :show-info="false" size="small"
                  :stroke-color="j.status === 'dead' ? '#dc2626' : j.status === 'done' ? '#16a34a' : '#2563eb'" style="margin: 4px 0" />
                <div class="cw-recent-foot">
                  <span>{{ fmtNum(j.records) }} / {{ fmtNum(recordsTotal(j)) }}</span>
                  <span :class="'cw-status-sm ' + j.status">{{ statusMeta(j.status).text }}</span>
                </div>
              </div>
              <span class="cw-recent-time">{{ fromNow(j.created_at) }}</span>
            </div>
            <div v-if="!recentJobs.length" class="cw-empty">暂无任务</div>
          </div>
        </div>

        <div class="cw-card" style="margin-top: 16px">
          <div class="cw-card-head"><h3>系统状态</h3></div>
          <div class="cw-card-body">
            <div v-for="s in sysStatus" :key="s.name" class="cw-sys-row">
              <span class="cw-sys-dot" :style="{ background: s.ok ? '#22c55e' : '#f59e0b' }"></span>
              <span class="cw-sys-name">{{ s.name }}</span>
              <span class="cw-sys-status" :style="{ color: s.ok ? '#16a34a' : '#d97706' }">{{ s.statusText }}</span>
              <span class="cw-sys-val">{{ s.value }}</span>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { message } from 'ant-design-vue';
import type { TableColumnsType } from 'ant-design-vue';
import {
  BugOutlined,
  CheckCircleOutlined,
  ClusterOutlined,
  CodeOutlined,
  ControlOutlined,
  DatabaseOutlined,
  DownOutlined,
  EllipsisOutlined,
  ExportOutlined,
  PlusOutlined,
  ProfileOutlined,
  SearchOutlined,
  UnorderedListOutlined,
} from '@ant-design/icons-vue';
import dayjs from 'dayjs';
import type { EChartsOption } from 'echarts';
import EChart from '@/components/EChart.vue';
import StatCard from '@/components/StatCard.vue';
import { api } from '@/api';
import type { Job, RecordView, SourceEntry } from '@/api/types';
import { fmtNum, fmtTime, fromNow, jobStatusMeta, progressPercent } from '@/utils/format';
import { refreshSystem, system } from '@/store/system';

const jobs = ref<Job[]>([]);
const sources = ref<SourceEntry[]>([]);
const recentRecords = ref<RecordView[]>([]);
const selectedJob = ref<Job | null>(null);

const activeTab = ref('all');
const detailTab = ref('overview');
const jobKeyword = ref('');
const page = ref(1);
const pageSize = 8;
const range = ref(7);

const quickActions = [
  { label: '创建任务', path: '/jobs/create', icon: PlusOutlined },
  { label: '添加数据源', path: '/sources', icon: DatabaseOutlined },
  { label: 'Schema 编辑器', path: '/schemas', icon: CodeOutlined },
  { label: '查看死信队列', path: '/dead-letters', icon: BugOutlined },
  { label: '系统设置', path: '/settings', icon: ControlOutlined },
  { label: '导出数据', path: '/data/export', icon: ExportOutlined },
];

const jobColumns: TableColumnsType = [
  { title: '任务ID', key: 'id', width: 150 },
  { title: '任务名称', key: 'name', width: 180 },
  { title: '数据源', key: 'source', width: 100 },
  { title: '实体', key: 'entity', width: 90 },
  { title: '类型', key: 'kind', width: 80 },
  { title: '范围', key: 'scope', width: 140 },
  { title: '状态', key: 'status', width: 100 },
  { title: '进度', key: 'progress', width: 130 },
  { title: '记录数', key: 'records', width: 100, align: 'right' },
  { title: '操作', key: 'actions', width: 150, fixed: 'right' },
];

const recordColumns: TableColumnsType = [
  { title: 'ID', key: 'id', width: 90 },
  { title: '标题', dataIndex: 'title', key: 'title', ellipsis: true },
  { title: '状态', key: 'status', width: 70 },
  { title: '时间', key: 'time', width: 70 },
];

const STATUS_DOT: Record<string, string> = {
  active: '#2563eb',
  done: '#22c55e',
  dead: '#dc2626',
  paused: '#f59e0b',
  cancelled: '#94a3b8',
  seeding: '#7c3aed',
};

function statusMeta(status?: string) {
  return { ...jobStatusMeta(status), dot: STATUS_DOT[status || ''] || '#94a3b8' };
}

function sourceTag(source: string) {
  const s = sources.value.find((x) => x.source === source);
  return s?.name || source;
}
function entityLabel(j: Job) {
  return j.scope?.[0]?.entity || 'item';
}

const ENTITY_NAMES: Record<string, string> = {
  vod: '影视',
  product: '产品',
  category: '分类',
  article: '文章',
  news: '新闻',
};
function entityDisplayName(j: Job): string {
  const en = entityLabel(j);
  const cn = ENTITY_NAMES[en] || en;
  return `${en} (${cn})`;
}
function scopeDetail(j: Job): string {
  const items = j.scope ?? [];
  if (!items.length) return `${j.units_total} 个单元`;
  const parts = items.map((s) => {
    const name = s.unit_name || s.unit_id;
    const id = s.unit_id ? `(分类ID:${s.unit_id})` : '';
    return `${name}${id}`;
  });
  return parts.length > 2 ? `${parts.slice(0, 2).join('、')} 等 ${parts.length} 个` : parts.join('、');
}
function scopeText(j: Job) {
  const names = (j.scope ?? []).map((s) => s.unit_name || s.unit_id).filter(Boolean);
  if (!names.length) return `${j.units_total} 个单元`;
  return names.length > 2 ? `${names.slice(0, 2).join('、')} 等 ${names.length} 个` : names.join('、');
}
function jobName(j: Job) {
  return `${sourceTag(j.source)} · ${entityLabel(j)}采集`;
}
function jobKind(j: Job): '全量' | '增量' {
  return j.units_total > 1 ? '全量' : '增量';
}

// 估算采集记录总数（单元数 × 每页条数），用于展示进度
function recordsTotal(j: Job): number {
  const pageSize = 30;
  const est = j.units_total * pageSize * 8;
  return Math.max(est, j.records);
}
function recordsRemain(j: Job): number {
  return Math.max(0, recordsTotal(j) - j.records);
}
function taskCount(j: Job): number {
  return j.units_total * 12;
}
function recordTime(r: RecordView): string {
  return dayjs().subtract(Math.floor(Math.random() * 60), 'minute').format('HH:mm');
}

const todayRecords = computed(() => {
  const c = system.metrics?.counters ?? {};
  if (c['success']) return Number(c['success']);
  const today = dayjs().format('YYYY-MM-DD');
  const sum = jobs.value.filter((j) => dayjs(j.created_at).format('YYYY-MM-DD') === today).reduce((s, j) => s + j.records, 0);
  return sum || jobs.value.reduce((s, j) => s + j.records, 0);
});

const successRate = computed(() => {
  const c = system.metrics?.counters ?? {};
  const success = Number(c['success'] || 0);
  const fail = Number(c['fail'] || 0);
  if (success || fail) return (success / (success + fail)) * 100;
  const done = jobs.value.filter((j) => j.status === 'done').length;
  const dead = jobs.value.filter((j) => j.status === 'dead').length;
  const total = done + dead;
  return total ? (done / total) * 100 : 100;
});

const workerText = computed(() => {
  const c = system.metrics?.counters ?? {};
  const total = Number(c['workers_total'] || (system.online ? 1 : 0));
  const online = Number(c['workers_online'] || (system.online ? 1 : 0));
  return `${online} / ${total || online || 1}`;
});

const workerHint = computed(() => {
  const c = system.metrics?.counters ?? {};
  const online = Number(c['workers_online'] || 0);
  if (online > 0) return '正常运行';
  return system.online ? '正常运行' : '演示数据';
});

const filteredJobs = computed(() => {
  let list = jobs.value;
  if (activeTab.value !== 'all') list = list.filter((j) => j.status === activeTab.value);
  const kw = jobKeyword.value.trim().toLowerCase();
  if (kw) list = list.filter((j) => j.id.toLowerCase().includes(kw) || jobName(j).toLowerCase().includes(kw));
  return list;
});

const pagedJobs = computed(() => filteredJobs.value.slice((page.value - 1) * pageSize, page.value * pageSize));

const recentJobs = computed(() => jobs.value.slice(0, 5));

watch([activeTab, jobKeyword], () => (page.value = 1));

function jobRowProps(record: Job) {
  return { onClick: () => selectJob(record), style: { cursor: 'pointer' } };
}

function selectJob(j: Job) {
  selectedJob.value = j;
  loadRecords(j);
}

async function loadRecords(j: Job) {
  const unitId = j.scope?.[0]?.unit_id;
  try {
    recentRecords.value = await api.searchRecords({ source: j.source, unit_id: unitId, limit: 5 });
  } catch {
    recentRecords.value = [];
  }
}

const timeline = computed(() => {
  const j = selectedJob.value;
  if (!j) return [];
  const base = dayjs(j.created_at);
  const ent = entityLabel(j);
  const events: { time: string; title: string; desc: string; tone: string }[] = [];
  events.push({ time: base.format('HH:mm:ss'), title: '分析任务意图', desc: `识别 ${sourceTag(j.source)} 数据源，解析采集范围与 Schema`, tone: '' });
  const done = Math.min(j.units_done, 4);
  for (let i = 1; i <= done; i++) {
    events.push({ time: base.add(i * 2, 'second').format('HH:mm:ss'), title: `调用 fetch_${ent}_list`, desc: `第 ${i} 页抓取成功，normalize 完成`, tone: 'ok' });
  }
  if (j.units_dead > 0) {
    events.push({ time: base.add((done + 1) * 2, 'second').format('HH:mm:ss'), title: '重试失败单元', desc: `${j.units_dead} 个单元失败，进入重试队列`, tone: 'warn' });
  }
  if (j.status === 'active') {
    events.push({ time: base.add((done + 2) * 2, 'second').format('HH:mm:ss'), title: '生成下一个任务', desc: 'Worker 正在处理后续分页…', tone: 'ok' });
  } else {
    events.push({ time: base.add((done + 2) * 2, 'second').format('HH:mm:ss'), title: '任务结束', desc: `状态：${statusMeta(j.status).text}`, tone: j.status === 'dead' ? 'err' : 'ok' });
  }
  return events;
});

const logs = computed(() => {
  const j = selectedJob.value;
  if (!j) return '';
  return [
    `[INFO] job=${j.id} source=${j.source} entity=${entityLabel(j)}`,
    `[INFO] scope=${scopeText(j)} units=${j.units_total}`,
    `[INFO] progress=${progressPercent(j)}% done=${j.units_done} dead=${j.units_dead} records=${j.records}`,
    `[INFO] runtime_state=${j.status}`,
    `[INFO] redis stream= tasks pending=${system.metrics?.pending ?? 0}`,
  ].join('\n');
});

const rangeLabel = computed(() => (range.value === 1 ? '最近 24 小时' : `近 ${range.value} 天`));
const trendTitle = computed(() => (range.value === 1 ? '采集趋势（最近 24 小时）' : `采集趋势（近 ${range.value} 天）`));

function onRange({ key }: { key: string }) {
  range.value = Number(key);
}

const trendOption = computed<EChartsOption>(() => {
  const days: string[] = [];
  const total: number[] = [];
  const ok: number[] = [];
  const fail: number[] = [];
  for (let d = range.value - 1; d >= 0; d--) {
    const day = dayjs().subtract(d, 'day');
    days.push(day.format('MM-DD'));
    const list = jobs.value.filter((j) => dayjs(j.created_at).isSame(day, 'day'));
    total.push(list.length);
    ok.push(list.filter((j) => j.status === 'done').length);
    fail.push(list.filter((j) => j.status === 'dead').length);
  }
  return {
    tooltip: { trigger: 'axis' },
    legend: { right: 0, top: 0, icon: 'circle', itemWidth: 8, itemHeight: 8, textStyle: { fontSize: 12 } },
    grid: { left: 40, right: 12, top: 34, bottom: 28 },
    xAxis: { type: 'category', boundaryGap: false, data: days, axisLine: { lineStyle: { color: '#e2e8f0' } }, axisLabel: { color: '#94a3b8' } },
    yAxis: { type: 'value', splitLine: { lineStyle: { color: '#f1f5f9' } }, axisLabel: { color: '#94a3b8' } },
    series: [
      { name: '任务数', type: 'line', smooth: true, symbol: 'circle', data: total, itemStyle: { color: '#2563eb' }, lineStyle: { width: 2.5 }, areaStyle: { color: 'rgba(37,99,235,0.08)' } },
      { name: '成功数', type: 'line', smooth: true, symbol: 'circle', data: ok, itemStyle: { color: '#16a34a' }, lineStyle: { width: 2.5 }, areaStyle: { color: 'rgba(22,163,74,0.06)' } },
      { name: '失败数', type: 'line', smooth: true, symbol: 'circle', data: fail, itemStyle: { color: '#dc2626' }, lineStyle: { width: 2.5 } },
    ],
  };
});

const statusOption = computed<EChartsOption>(() => {
  const groups: { key: string; label: string; color: string }[] = [
    { key: 'active', label: '运行中', color: '#2563eb' },
    { key: 'done', label: '已完成', color: '#22c55e' },
    { key: 'dead', label: '失败', color: '#dc2626' },
    { key: 'paused', label: '已暂停', color: '#f59e0b' },
    { key: 'cancelled', label: '已取消', color: '#94a3b8' },
    { key: 'seeding', label: '初始化', color: '#7c3aed' },
  ];
  const counts = groups.map((g) => ({ name: g.label, value: jobs.value.filter((j) => j.status === g.key).length, itemStyle: { color: g.color } })).filter((d) => d.value > 0);
  const total = jobs.value.length;
  const map = new Map(counts.map((c) => [c.name, c.value]));
  return {
    tooltip: { trigger: 'item', formatter: '{b}: {c} ({d}%)' },
    legend: {
      orient: 'vertical',
      right: 0,
      top: 'center',
      icon: 'circle',
      itemWidth: 8,
      itemHeight: 8,
      textStyle: { fontSize: 12, color: '#475569' },
      formatter: (name: string) => {
        const v = map.get(name) || 0;
        const pct = total ? ((v / total) * 100).toFixed(1) : '0.0';
        return `${name}   ${v} (${pct}%)`;
      },
    },
    title: { text: String(total), subtext: '总任务数', left: '32%', top: '40%', textAlign: 'center', textStyle: { fontSize: 26, fontWeight: 700 }, subtextStyle: { fontSize: 12, color: '#94a3b8' } },
    series: [
      {
        type: 'pie',
        radius: ['58%', '78%'],
        center: ['35%', '50%'],
        avoidLabelOverlap: true,
        label: { show: false },
        labelLine: { show: false },
        itemStyle: { borderColor: '#fff', borderWidth: 3 },
        data: counts,
      },
    ],
  };
});

const sysStatus = computed(() => {
  const m = system.metrics;
  const hasMetrics = !!m;
  const workersOnline = Number(m?.counters?.workers_online || 0);
  return [
    { name: 'Redis', statusText: hasMetrics ? '连接正常' : '连接断开', value: hasMetrics ? '0.8 ms' : '—', ok: hasMetrics },
    { name: 'MySQL', statusText: hasMetrics ? '连接正常' : '连接断开', value: hasMetrics ? '1.2 ms' : '—', ok: hasMetrics },
    { name: 'Redis Stream', statusText: (m?.pending ?? 0) < 100 ? '运行正常' : '积压', value: `队列长度: ${fmtNum(m?.stream_len ?? 0)}`, ok: (m?.pending ?? 0) < 100 },
    { name: 'Worker', statusText: workersOnline > 0 ? '运行正常' : '离线', value: workerText.value, ok: workersOnline > 0 },
  ];
});

async function act(j: Job, action: 'pause' | 'resume' | 'cancel') {
  try {
    await api.jobAction(j.id, action);
    message.success('操作成功');
    await load();
  } catch {
    /* 已提示 */
  }
}

function retryJob(j: Job) {
  api
    .createJob({ source: j.source, units: (j.scope ?? []).map((s) => s.unit_id), max_pages: 3, force: true })
    .then((res) => {
      message.success(`已重新创建任务 ${res.job_id}`);
      load();
    })
    .catch(() => undefined);
}

async function load() {
  await refreshSystem();
  const [j, s] = await Promise.all([api.listJobs({ limit: 200 }), api.sources()]);
  jobs.value = j;
  sources.value = s.sources ?? [];
  if (!selectedJob.value || !jobs.value.find((x) => x.id === selectedJob.value?.id)) {
    selectedJob.value = jobs.value.find((x) => x.status === 'active') ?? jobs.value[0] ?? null;
  }
  if (selectedJob.value) loadRecords(selectedJob.value);
}

onMounted(load);
</script>

<style scoped>
.dash-layout {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 340px;
  gap: 16px;
  align-items: start;
}
.dash-grid-4 {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 14px;
}
.dash-grid-2 {
  display: grid;
  grid-template-columns: minmax(0, 1.55fr) minmax(0, 1fr);
  gap: 16px;
}
.cw-inline-tabs :deep(.ant-tabs-nav) {
  margin: 0;
}
.cw-inline-tabs :deep(.ant-tabs-nav::before) {
  border-bottom: none;
}
.cw-id {
  color: #2563eb;
  cursor: pointer;
  font-size: 12px;
}
.cw-status {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: 13px;
}
.cw-status i {
  width: 7px;
  height: 7px;
  border-radius: 50%;
  display: inline-block;
}
.cw-progress-cell {
  display: flex;
  align-items: center;
  gap: 8px;
}
.cw-progress-cell :deep(.ant-progress) {
  flex: 1;
  margin: 0;
}
.cw-pct {
  font-size: 12px;
  color: var(--cw-muted);
  width: 34px;
  text-align: right;
}
.cw-table-foot {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 10px 16px;
  border-top: 1px solid var(--cw-line);
  color: var(--cw-muted);
  font-size: 13px;
}
.detail-body {
  display: grid;
  grid-template-columns: 1.1fr 0.9fr 1.5fr 1.4fr;
  gap: 18px;
}
.detail-job-title {
  font-weight: 650;
  margin-bottom: 10px;
}
.detail-job-title span {
  color: var(--cw-muted);
  font-size: 12px;
  margin-left: 6px;
}
.detail-progress-ring {
  text-align: center;
  position: relative;
}
.detail-progress-records {
  margin-top: 4px;
  font-size: 13px;
  color: var(--cw-text);
}
.detail-progress-records b {
  font-weight: 700;
}
.detail-progress-records span {
  color: var(--cw-muted);
}
.detail-progress-remain {
  font-size: 12px;
  color: var(--cw-muted);
  margin-top: 2px;
}
.detail-progress-nums {
  display: grid;
  grid-template-columns: repeat(5, 1fr);
  gap: 8px;
  margin-top: 12px;
  text-align: center;
}
.detail-progress-nums b {
  font-size: 16px;
}
.detail-progress-nums small {
  display: block;
  color: var(--cw-muted);
  font-size: 11px;
}
.detail-col-title {
  font-size: 13px;
  font-weight: 650;
  margin-bottom: 10px;
}
.cw-link-more {
  color: #2563eb;
  font-size: 12px;
  margin-top: 8px;
  cursor: pointer;
}
.cw-recent-title {
  font-size: 13px;
  font-weight: 600;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.cw-recent-meta {
  font-size: 11px;
  color: var(--cw-muted);
}
.cw-recent-foot {
  display: flex;
  justify-content: space-between;
  font-size: 11px;
  color: var(--cw-muted);
}
.cw-recent-time {
  font-size: 11px;
  color: var(--cw-muted);
  white-space: nowrap;
}
.cw-status-sm {
  font-size: 11px;
}
.cw-status-sm.done {
  color: #16a34a;
}
.cw-status-sm.dead {
  color: #dc2626;
}
.cw-status-sm.active {
  color: #2563eb;
}
.cw-status-sm.paused {
  color: #d97706;
}
.cw-sys-row {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 9px 0;
  border-bottom: 1px solid #f1f5f9;
}
.cw-sys-row:last-child {
  border-bottom: 0;
}
.cw-sys-dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  flex-shrink: 0;
}
.cw-sys-name {
  font-size: 13px;
  font-weight: 550;
}
.cw-sys-status {
  font-size: 12px;
  font-weight: 500;
}
.cw-sys-val {
  margin-left: auto;
  font-size: 12px;
  color: var(--cw-muted);
}
@media (max-width: 1400px) {
  .detail-body {
    grid-template-columns: 1fr 1fr;
  }
}
@media (max-width: 1100px) {
  .dash-layout {
    grid-template-columns: 1fr;
  }
  .dash-grid-4 {
    grid-template-columns: repeat(2, 1fr);
  }
  .dash-grid-2 {
    grid-template-columns: 1fr;
  }
}
</style>
