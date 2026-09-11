<template>
  <div class="cw-page">
    <div class="cw-page-title">
      <div>
        <h2>
          Job <span class="cw-mono">{{ job?.id || id }}</span>
          <a-tag v-if="job" :color="jobStatusMeta(job.status).color" style="margin-left: 8px">
            {{ jobStatusMeta(job.status).text }}
          </a-tag>
        </h2>
        <div class="sub">
          {{ job?.source }} · {{ entityText }} · 自动刷新中（5s）
        </div>
      </div>
      <a-space>
        <a-button @click="$router.back()"><template #icon><ArrowLeftOutlined /></template>返回</a-button>
        <a-button v-if="job?.status === 'active'" @click="act('pause')">暂停</a-button>
        <a-button v-if="job?.status === 'paused'" type="primary" @click="act('resume')">继续</a-button>
        <a-popconfirm
          v-if="job && ['active', 'paused', 'seeding'].includes(job.status)"
          title="确认取消该作业？"
          @confirm="act('cancel')"
        >
          <a-button danger>取消</a-button>
        </a-popconfirm>
      </a-space>
    </div>

    <a-spin :spinning="loading && !job">
      <a-empty v-if="!job && !loading" description="作业不存在" />

      <template v-if="job">
        <a-row :gutter="[16, 16]">
          <a-col :xs="24" :lg="16">
            <a-card :bordered="false" title="基本信息">
              <a-descriptions :column="{ xs: 1, sm: 2 }" size="small">
                <a-descriptions-item label="数据源">{{ job.source }}</a-descriptions-item>
                <a-descriptions-item label="Entity">{{ entityText }}</a-descriptions-item>
                <a-descriptions-item label="创建时间">{{ fmtTime(job.created_at) }}</a-descriptions-item>
                <a-descriptions-item label="更新时间">{{ fmtTime(job.updated_at) }}</a-descriptions-item>
                <a-descriptions-item label="采集单元">{{ job.units_total }}</a-descriptions-item>
                <a-descriptions-item label="运行时状态">{{ runtimeState || '-' }}</a-descriptions-item>
              </a-descriptions>
            </a-card>

            <a-card :bordered="false" title="采集进度" style="margin-top: 16px">
              <a-progress
                :percent="progressPercent(job)"
                :status="job.status === 'dead' ? 'exception' : undefined"
                :stroke-color="job.status === 'done' ? '#52c41a' : '#1677ff'"
              />
              <a-row :gutter="16" style="margin-top: 16px">
                <a-col :span="6">
                  <a-statistic title="单元完成" :value="job.units_done" />
                </a-col>
                <a-col :span="6">
                  <a-statistic title="单元失败" :value="job.units_dead" />
                </a-col>
                <a-col :span="6">
                  <a-statistic title="采集记录" :value="job.records" />
                </a-col>
                <a-col :span="6">
                  <a-statistic title="暂存任务" :value="parkedTasks" />
                </a-col>
              </a-row>
            </a-card>

            <a-card :bordered="false" title="采集单元 (Scope)" style="margin-top: 16px">
              <a-table
                :columns="scopeColumns"
                :data-source="job.scope ?? []"
                :pagination="false"
                row-key="unit_id"
                size="small"
              />
            </a-card>
          </a-col>

          <a-col :xs="24" :lg="8">
            <a-card :bordered="false" title="执行统计">
              <a-descriptions :column="1" size="small" bordered>
                <a-descriptions-item label="单元总数">{{ job.units_total }}</a-descriptions-item>
                <a-descriptions-item label="已完成">{{ job.units_done }}</a-descriptions-item>
                <a-descriptions-item label="失败">{{ job.units_dead }}</a-descriptions-item>
                <a-descriptions-item label="记录数">{{ fmtNum(job.records) }}</a-descriptions-item>
                <a-descriptions-item label="暂存任务">{{ parkedTasks }}</a-descriptions-item>
                <a-descriptions-item label="Redis 状态">{{ runtimeState || '-' }}</a-descriptions-item>
              </a-descriptions>
            </a-card>

            <a-card :bordered="false" title="操作" style="margin-top: 16px">
              <a-space direction="vertical" style="width: 100%">
                <a-button block :disabled="job.status !== 'active'" @click="act('pause')">暂停</a-button>
                <a-button block :disabled="job.status !== 'paused'" @click="act('resume')">继续</a-button>
                <a-button block danger :disabled="!['active','paused','seeding'].includes(job.status)" @click="act('cancel')">
                  取消
                </a-button>
                <a-button block @click="load">重新加载</a-button>
              </a-space>
            </a-card>
          </a-col>
        </a-row>
      </template>
    </a-spin>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { useRoute } from 'vue-router';
import { message } from 'ant-design-vue';
import type { TableColumnsType } from 'ant-design-vue';
import { ArrowLeftOutlined } from '@ant-design/icons-vue';
import { api } from '@/api';
import type { Job } from '@/api/types';
import { fmtNum, fmtTime, jobStatusMeta, progressPercent } from '@/utils/format';

const route = useRoute();
const id = String(route.params.id);
const job = ref<Job | null>(null);
const runtimeState = ref('');
const parkedTasks = ref(0);
const loading = ref(false);
let timer: number | undefined;

const scopeColumns: TableColumnsType = [
  { title: 'Entity', dataIndex: 'entity', key: 'entity', width: 100 },
  { title: '单元 ID', dataIndex: 'unit_id', key: 'unit_id', width: 100 },
  { title: '单元名称', dataIndex: 'unit_name', key: 'unit_name' },
];

const entityText = computed(() => {
  const first = job.value?.scope?.[0]?.entity;
  return first || '-';
});

async function act(action: 'pause' | 'resume' | 'cancel') {
  try {
    await api.jobAction(id, action);
    message.success('操作成功');
    await load();
  } catch {
    // 已提示
  }
}

async function load() {
  loading.value = true;
  try {
    const res = await api.getJob(id);
    job.value = res.job;
    runtimeState.value = res.runtime_state;
    parkedTasks.value = res.parked_tasks ?? 0;
  } catch {
    // 已提示
  } finally {
    loading.value = false;
  }
}

onMounted(() => {
  load();
  timer = window.setInterval(load, 5000);
});

onUnmounted(() => {
  if (timer) window.clearInterval(timer);
});
</script>
