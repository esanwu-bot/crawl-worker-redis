<template>
  <div class="cw-page">
    <div class="cw-page-title">
      <div>
        <h2>任务创建</h2>
        <div class="sub">选择数据源与采集范围，后台转换为 Redis Stream 任务投递</div>
      </div>
      <a-button @click="$router.push('/jobs')">返回任务列表</a-button>
    </div>

    <a-row :gutter="16">
      <a-col :xs="24" :lg="15">
        <a-card :bordered="false" class="cw-card" title="采集配置">
          <a-form layout="vertical">
            <a-form-item label="数据源" required>
              <a-select v-model:value="form.source" :options="sourceOptions" placeholder="选择数据源" @change="onSourceChange" />
            </a-form-item>
            <a-form-item label="采集范围">
              <a-radio-group v-model:value="form.scopeType">
                <a-radio value="all">全部</a-radio>
                <a-radio value="units">指定分类 / 单元</a-radio>
              </a-radio-group>
            </a-form-item>
            <a-form-item v-if="form.scopeType === 'units'" label="分类 / 单元">
              <a-select v-model:value="form.units" mode="multiple" allow-clear placeholder="选择采集单元" :options="unitOptions" />
            </a-form-item>
            <a-row :gutter="16">
              <a-col :span="12">
                <a-form-item label="采集模式">
                  <a-radio-group v-model:value="form.mode">
                    <a-radio value="full">全量</a-radio>
                    <a-radio value="incremental">增量</a-radio>
                  </a-radio-group>
                </a-form-item>
              </a-col>
              <a-col :span="12">
                <a-form-item label="最大页数">
                  <a-input-number v-model:value="form.maxPages" :min="1" :max="1000" style="width: 100%" />
                </a-form-item>
              </a-col>
            </a-row>
            <a-form-item label="并发 / 重试">
              <a-input-group compact>
                <a-input-number v-model:value="form.concurrency" :min="1" :max="32" addon-before="并发" style="width: 50%" />
                <a-input-number v-model:value="form.retry" :min="0" :max="10" addon-before="重试" style="width: 50%" />
              </a-input-group>
              <div class="cw-hint">并发与重试由 Worker / Runtime 统一控制，此处仅作任务侧建议。</div>
            </a-form-item>
            <a-space>
              <a-button type="primary" :loading="submitting" @click="submit">立即执行</a-button>
              <a-button @click="reset">重置</a-button>
            </a-space>
          </a-form>
        </a-card>
      </a-col>

      <a-col :xs="24" :lg="9">
        <a-card :bordered="false" class="cw-card" title="预览">
          <pre class="cw-pre cw-mono">{{ preview }}</pre>
        </a-card>
      </a-col>
    </a-row>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { useRouter } from 'vue-router';
import { message } from 'ant-design-vue';
import { api } from '@/api';
import type { SourceEntry, SourceTypeRow } from '@/api/types';

const router = useRouter();
const sources = ref<SourceEntry[]>([]);
const units = ref<SourceTypeRow[]>([]);
const submitting = ref(false);

const form = reactive({
  source: undefined as string | undefined,
  scopeType: 'all',
  units: [] as string[],
  mode: 'incremental',
  maxPages: 3,
  concurrency: 4,
  retry: 3,
});

const sourceOptions = computed(() => sources.value.map((s) => ({ label: `${s.name || s.source}（${s.source}）`, value: s.source })));
const unitOptions = computed(() =>
  units.value
    .filter((u) => !form.source || u.source === form.source)
    .map((u) => ({ label: `${u.cn_name || u.en_name} (${u.type_id})`, value: String(u.type_id) })),
);

const preview = computed(() =>
  JSON.stringify(
    {
      source: form.source || 'maccms',
      scope: form.scopeType === 'units' ? { unit_ids: form.units } : { all: true },
      mode: form.mode,
      max_pages: form.maxPages,
      concurrency: form.concurrency,
      retry: form.retry,
    },
    null,
    2,
  ),
);

function onSourceChange() {
  form.units = [];
}

async function submit() {
  if (!form.source) {
    message.warning('请选择数据源');
    return;
  }
  submitting.value = true;
  try {
    const res = await api.createJob({
      source: form.source,
      units: form.scopeType === 'units' ? form.units.map(String) : undefined,
      max_pages: form.maxPages,
      force: form.mode === 'full',
    });
    message.success(`任务已创建：${res.job_id}`);
    router.push('/jobs');
  } catch {
    /* 已提示 */
  } finally {
    submitting.value = false;
  }
}

function reset() {
  form.scopeType = 'all';
  form.units = [];
  form.mode = 'incremental';
  form.maxPages = 3;
}

onMounted(async () => {
  const res = await api.sources();
  sources.value = res.sources ?? [];
  units.value = (res.units as SourceTypeRow[]) ?? [];
  form.source = sources.value[0]?.source;
});
</script>

<style scoped>
.cw-hint {
  margin-top: 6px;
  color: var(--cw-muted);
  font-size: 12px;
}
</style>
