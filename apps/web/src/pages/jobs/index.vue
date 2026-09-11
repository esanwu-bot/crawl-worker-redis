<template>
  <div class="cw-page">
    <div class="cw-page-title">
      <div>
        <h2>采集任务</h2>
        <div class="sub">创建采集作业、观察进度、暂停/恢复/取消</div>
      </div>
      <a-button type="primary" @click="openCreate">
        <template #icon><PlusOutlined /></template>
        创建采集任务
      </a-button>
    </div>

    <a-card :bordered="false">
      <a-tabs v-model:activeKey="activeStatus" @change="() => {}">
        <a-tab-pane key="all" tab="全部任务" />
        <a-tab-pane key="active" tab="运行中" />
        <a-tab-pane key="done" tab="已完成" />
        <a-tab-pane key="dead" tab="失败" />
        <a-tab-pane key="paused" tab="已暂停" />
      </a-tabs>

      <a-space style="margin-bottom: 12px">
        <a-select
          v-model:value="filterSource"
          placeholder="全部数据源"
          style="width: 180px"
          allow-clear
          :options="sourceOptions"
        />
        <a-button @click="load"><template #icon><ReloadOutlined /></template>刷新</a-button>
      </a-space>

      <a-table
        :columns="columns"
        :data-source="filtered"
        :loading="loading"
        row-key="id"
        size="middle"
      >
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'id'">
            <a class="cw-mono cw-clickable" @click="openJob(record.id)">{{ shortId(record.id) }}</a>
          </template>
          <template v-else-if="column.key === 'scope'">
            {{ unitText(record) }}
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
          <template v-else-if="column.key === 'created_at'">
            {{ fmtTime(record.created_at) }}
          </template>
          <template v-else-if="column.key === 'actions'">
            <a-space>
              <a @click="openJob(record.id)">查看</a>
              <a v-if="record.status === 'active'" @click="act(record, 'pause')">暂停</a>
              <a v-if="record.status === 'paused'" @click="act(record, 'resume')">继续</a>
              <a-popconfirm
                v-if="['active', 'paused', 'seeding'].includes(record.status)"
                title="确认取消该作业？"
                @confirm="act(record, 'cancel')"
              >
                <a style="color: #ff4d4f">取消</a>
              </a-popconfirm>
            </a-space>
          </template>
        </template>
      </a-table>
    </a-card>

    <a-modal
      v-model:open="createOpen"
      title="创建采集任务"
      :confirm-loading="submitting"
      ok-text="立即执行"
      cancel-text="取消"
      width="620px"
      @ok="submitCreate"
    >
      <a-form layout="vertical" style="margin-top: 8px">
        <a-form-item label="数据源" required>
          <a-select v-model:value="form.source" :options="sourceOptions" placeholder="选择数据源" />
        </a-form-item>
        <a-form-item label="采集单元（留空=自动发现全部）">
          <a-select
            v-model:value="form.units"
            mode="multiple"
            allow-clear
            placeholder="全部采集单元"
            :options="unitOptions"
          />
        </a-form-item>
        <a-row :gutter="16">
          <a-col :span="12">
            <a-form-item label="最大页数">
              <a-input-number v-model:value="form.max_pages" :min="0" style="width: 100%" />
            </a-form-item>
          </a-col>
          <a-col :span="12">
            <a-form-item label="采集模式">
              <a-radio-group v-model:value="form.force">
                <a-radio :value="false">全量</a-radio>
                <a-radio :value="true">强制重采</a-radio>
              </a-radio-group>
            </a-form-item>
          </a-col>
        </a-row>
        <a-alert
          type="info"
          show-icon
          message="并发 / 失败重试由 Worker 与 Runtime 统一控制，暂不在任务级配置。"
        />
      </a-form>
    </a-modal>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { message } from 'ant-design-vue';
import type { TableColumnsType } from 'ant-design-vue';
import { PlusOutlined, ReloadOutlined } from '@ant-design/icons-vue';
import { api } from '@/api';
import type { Job, SourceEntry, SourceTypeRow } from '@/api/types';
import { fmtNum, fmtTime, jobStatusMeta, progressPercent } from '@/utils/format';

const router = useRouter();
const loading = ref(false);
const submitting = ref(false);
const createOpen = ref(false);
const activeStatus = ref('all');
const filterSource = ref<string | undefined>();

const jobs = ref<Job[]>([]);
const sources = ref<SourceEntry[]>([]);
const units = ref<SourceTypeRow[]>([]);

const form = reactive<{
  source?: string;
  units: string[];
  max_pages: number;
  force: boolean;
}>({ source: undefined, units: [], max_pages: 3, force: false });

const columns: TableColumnsType = [
  { title: 'Job ID', key: 'id', width: 160 },
  { title: '数据源', dataIndex: 'source', key: 'source', width: 120 },
  { title: '采集范围', key: 'scope' },
  { title: '状态', key: 'status', width: 100 },
  { title: '进度', key: 'progress', width: 170 },
  { title: '记录', key: 'records', width: 100, align: 'right' },
  { title: '创建时间', key: 'created_at', width: 170 },
  { title: '操作', key: 'actions', width: 160 },
];

const sourceOptions = computed(() =>
  (sources.value ?? []).map((s) => ({
    label: `${s.source} · ${s.entity}`,
    value: s.source,
  })),
);

const unitOptions = computed(() =>
  units.value
    .filter((u) => !form.source || u.source === form.source)
    .map((u) => ({ label: `${u.cn_name || u.en_name} (${u.type_id})`, value: String(u.type_id) })),
);

const filtered = computed(() => {
  let list = jobs.value;
  if (activeStatus.value !== 'all') {
    list = list.filter((j) => j.status === activeStatus.value);
  }
  if (filterSource.value) {
    list = list.filter((j) => j.source === filterSource.value);
  }
  return list;
});

watch(filterSource, load);

function shortId(id: string) {
  return id?.length > 16 ? id.slice(0, 8) + '…' + id.slice(-4) : id;
}

function unitText(j: Job) {
  const names = (j.scope ?? []).map((s) => s.unit_name || s.unit_id);
  if (!names.length) return `${j.units_total} 个单元`;
  return names.length > 3 ? `${names.slice(0, 3).join('、')} 等 ${names.length} 个` : names.join('、');
}

function openJob(id: string) {
  router.push(`/jobs/${id}`);
}

function openCreate() {
  form.source = form.source || sources.value[0]?.source;
  form.units = [];
  form.max_pages = 3;
  form.force = false;
  createOpen.value = true;
}

async function submitCreate() {
  if (!form.source) {
    message.warning('请选择数据源');
    return;
  }
  submitting.value = true;
  try {
    const res = await api.createJob({
      source: form.source,
      units: form.units,
      max_pages: form.max_pages,
      force: form.force,
    });
    message.success(`作业已创建: ${res.job_id}`);
    createOpen.value = false;
    await load();
  } catch {
    // 已提示
  } finally {
    submitting.value = false;
  }
}

async function act(job: Job, action: 'pause' | 'resume' | 'cancel') {
  try {
    await api.jobAction(job.id, action);
    message.success('操作成功');
    await load();
  } catch {
    // 已提示
  }
}

async function load() {
  loading.value = true;
  try {
    const [j, s] = await Promise.all([
      api.listJobs({ source: filterSource.value, limit: 100 }),
      sources.value.length ? Promise.resolve({ sources: sources.value, units: units.value }) : api.sources(),
    ]);
    jobs.value = j;
    sources.value = s.sources ?? [];
    units.value = (s.units as SourceTypeRow[]) ?? [];
  } catch {
    // 已提示
  } finally {
    loading.value = false;
  }
}

onMounted(load);
</script>
