<template>
  <div class="cw-page">
    <div class="cw-page-title">
      <div>
        <h2>数据源</h2>
        <div class="sub">配置式数据源（Source Definition）：连接 + 端点 + 采集单元，后台创建即生效</div>
      </div>
      <a-space>
        <a-button :loading="probing" @click="probe">
          <template #icon><ThunderboltOutlined /></template>
          探测可用性
        </a-button>
        <a-button type="primary" @click="openCreate">
          <template #icon><PlusOutlined /></template>
          新建数据源
        </a-button>
      </a-space>
    </div>

    <a-card :bordered="false" title="数据源列表">
      <a-table :columns="columns" :data-source="sources" :loading="loading" row-key="source" size="middle">
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'source'">
            <div>
              <a class="cw-clickable" @click="edit(record)">{{ record.name || record.source }}</a>
              <div class="cw-mono" style="font-size: 12px; color: #8c8c8c">{{ record.source }}</div>
            </div>
          </template>
          <template v-else-if="column.key === 'api_base'">
            <a v-if="record.api_base" :href="record.api_base" target="_blank" class="cw-mono" style="font-size: 12px">
              {{ record.api_base }}
            </a>
            <span v-else>-</span>
          </template>
          <template v-else-if="column.key === 'units'">
            {{ unitCount(record.source) }}
          </template>
          <template v-else-if="column.key === 'status'">
            <a-tag :color="record.status === 'disabled' ? 'default' : 'success'">
              {{ record.status === 'disabled' ? '停用' : '启用' }}
            </a-tag>
            <a-tag v-if="record.registered === false" color="warning">未注册</a-tag>
          </template>
          <template v-else-if="column.key === 'healthy'">
            <a-tag v-if="record.healthy === undefined" color="default">未探测</a-tag>
            <a-tag v-else :color="record.healthy ? 'success' : 'error'">
              {{ record.healthy ? '正常' : '异常' }}
            </a-tag>
          </template>
          <template v-else-if="column.key === 'actions'">
            <a-space>
              <a @click="edit(record)">编辑</a>
              <a @click="$router.push(`/sources/${record.source}/schema`)">Schema</a>
              <a-popconfirm title="发现并刷新采集单元？" @confirm="discover(record)">
                <a>发现</a>
              </a-popconfirm>
              <a v-if="record.status !== 'disabled'" @click="toggle(record, false)">停用</a>
              <a v-else @click="toggle(record, true)">启用</a>
              <a-popconfirm title="删除该数据源及其 Schema？" @confirm="remove(record)">
                <a style="color: #ff4d4f">删除</a>
              </a-popconfirm>
            </a-space>
          </template>
        </template>
      </a-table>
    </a-card>

    <a-card :bordered="false" :title="`采集单元目录${activeSource ? ' · ' + activeSource : ''}`" style="margin-top: 16px">
      <a-space style="margin-bottom: 12px">
        <a-select
          v-model:value="activeSource"
          placeholder="全部数据源"
          style="width: 200px"
          allow-clear
          :options="sourceOptions"
        />
      </a-space>
      <a-table :columns="unitColumns" :data-source="filteredUnits" :pagination="{ pageSize: 10 }" row-key="type_id" size="small">
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'type_id'">
            <span class="cw-mono">{{ record.type_id }}</span>
          </template>
          <template v-else-if="column.key === 'actions'">
            <a @click="seedUnit(record)">立即采集</a>
          </template>
        </template>
      </a-table>
    </a-card>

    <a-modal
      v-model:open="modalOpen"
      :title="editing ? `编辑数据源 · ${form.id}` : '新建数据源'"
      :confirm-loading="saving"
      width="720px"
      @ok="save"
    >
      <a-form layout="vertical" style="margin-top: 8px">
        <a-row :gutter="16">
          <a-col :span="12">
            <a-form-item label="数据源 ID" required>
              <a-input v-model:value="form.id" :disabled="editing" placeholder="如 maccms-demo" />
            </a-form-item>
          </a-col>
          <a-col :span="12">
            <a-form-item label="展示名">
              <a-input v-model:value="form.name" placeholder="如 MacCMS 影视源" />
            </a-form-item>
          </a-col>
        </a-row>
        <a-row :gutter="16">
          <a-col :span="12">
            <a-form-item label="Adapter / Connector" required>
              <a-select
                v-model:value="form.adapter"
                :options="[
                  { label: 'MacCMS', value: 'maccms' },
                  { label: 'Shikues', value: 'shikues' },
                ]"
              />
            </a-form-item>
          </a-col>
          <a-col :span="12">
            <a-form-item label="Entity" required>
              <a-input v-model:value="form.entity" placeholder="如 vod / model" />
            </a-form-item>
          </a-col>
        </a-row>
        <a-row :gutter="16">
          <a-col :span="12">
            <a-form-item label="Site">
              <a-input v-model:value="form.site" placeholder="https://example.com" />
            </a-form-item>
          </a-col>
          <a-col :span="12">
            <a-form-item label="API Base" required>
              <a-input v-model:value="form.api_base" placeholder="https://example.com/api.php/provide/vod/" />
            </a-form-item>
          </a-col>
        </a-row>
        <a-row :gutter="16">
          <a-col :span="8">
            <a-form-item label="Type">
              <a-input-number v-model:value="form.type" :min="0" style="width: 100%" />
            </a-form-item>
          </a-col>
          <a-col :span="8">
            <a-form-item label="Page Size">
              <a-input-number v-model:value="form.page_size" :min="0" style="width: 100%" />
            </a-form-item>
          </a-col>
          <a-col :span="8">
            <a-form-item label="状态">
              <a-select
                v-model:value="form.status"
                :options="[
                  { label: '启用', value: 'enabled' },
                  { label: '停用', value: 'disabled' },
                ]"
              />
            </a-form-item>
          </a-col>
        </a-row>
        <a-form-item label="Detail URL">
          <a-input v-model:value="form.detail_url" placeholder="/api.php/provide/vod/?ac=detail&ids={id}" />
        </a-form-item>

        <a-form-item label="采集单元（可选，留空则运行时自动发现）">
          <div v-for="(u, i) in form.units" :key="i" class="cw-unit-row">
            <a-input v-model:value="u.unit_id" placeholder="Unit ID" style="width: 140px" />
            <a-input v-model:value="u.unit_name" placeholder="名称" style="flex: 1" />
            <a-button danger type="text" @click="form.units.splice(i, 1)">
              <template #icon><MinusCircleOutlined /></template>
            </a-button>
          </div>
          <a-button dashed block @click="form.units.push({ unit_id: '', unit_name: '' })">
            <template #icon><PlusOutlined /></template>
            添加采集单元
          </a-button>
        </a-form-item>
      </a-form>
    </a-modal>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { useRouter } from 'vue-router';
import { message } from 'ant-design-vue';
import type { TableColumnsType } from 'ant-design-vue';
import { MinusCircleOutlined, PlusOutlined, ThunderboltOutlined } from '@ant-design/icons-vue';
import { api } from '@/api';
import type { SourceDefinition, SourceEntry, SourceTypeRow, UnitDefinition } from '@/api/types';

const router = useRouter();
const loading = ref(false);
const probing = ref(false);
const saving = ref(false);
const modalOpen = ref(false);
const editing = ref(false);
const sources = ref<SourceEntry[]>([]);
const units = ref<SourceTypeRow[]>([]);
const activeSource = ref<string | undefined>();

type SourceForm = SourceDefinition & { units: UnitDefinition[] };

const emptyForm = (): SourceForm => ({
  id: '',
  name: '',
  adapter: 'maccms',
  entity: 'vod',
  site: '',
  api_base: '',
  type: 0,
  page_size: 20,
  detail_url: '',
  status: 'enabled',
  units: [],
});
const form = reactive<SourceForm>(emptyForm());

const columns: TableColumnsType = [
  { title: '数据源', key: 'source', width: 180 },
  { title: 'Adapter', dataIndex: 'adapter', key: 'adapter', width: 100 },
  { title: 'Entity', dataIndex: 'entity', key: 'entity', width: 90 },
  { title: 'Base URL', key: 'api_base' },
  { title: '采集单元', key: 'units', width: 90, align: 'right' },
  { title: '状态', key: 'status', width: 130 },
  { title: '可用性', key: 'healthy', width: 90 },
  { title: '操作', key: 'actions', width: 260 },
];

const unitColumns: TableColumnsType = [
  { title: '数据源', dataIndex: 'source', key: 'source', width: 120 },
  { title: 'Type ID', key: 'type_id', width: 100 },
  { title: '中文名', dataIndex: 'cn_name', key: 'cn_name' },
  { title: '英文名', dataIndex: 'en_name', key: 'en_name' },
  { title: '操作', key: 'actions', width: 100 },
];

const sourceOptions = computed(() => sources.value.map((s) => ({ label: s.source, value: s.source })));
const filteredUnits = computed(() =>
  activeSource.value ? units.value.filter((u) => u.source === activeSource.value) : units.value,
);

function unitCount(source: string) {
  return units.value.filter((u) => u.source === source).length;
}

function resetForm(d: SourceDefinition) {
  Object.assign(form, emptyForm(), d, {
    units: (d.units ?? []).map((u) => ({ unit_id: u.unit_id, unit_name: u.unit_name })),
  });
}

function openCreate() {
  editing.value = false;
  resetForm(emptyForm());
  modalOpen.value = true;
}

async function edit(record: SourceEntry) {
  try {
    const full = await api.getSource(record.source);
    editing.value = true;
    resetForm(full);
    modalOpen.value = true;
  } catch {
    // 已提示
  }
}

async function save() {
  if (!form.id || !form.api_base) {
    message.warning('数据源 ID 与 API Base 为必填');
    return;
  }
  saving.value = true;
  try {
    const payload: SourceDefinition = {
      ...form,
      page_size: Number(form.page_size) || 0,
      type: Number(form.type) || 0,
      units: (form.units ?? []).filter((u) => u.unit_id),
    };
    if (editing.value) await api.updateSource(form.id, payload);
    else await api.createSource(payload);
    message.success('已保存');
    modalOpen.value = false;
    await load();
  } catch {
    // 已提示
  } finally {
    saving.value = false;
  }
}

async function toggle(record: SourceEntry, enable: boolean) {
  try {
    if (enable) await api.enableSource(record.source);
    else await api.disableSource(record.source);
    message.success(enable ? '已启用' : '已停用');
    await load();
  } catch {
    // 已提示
  }
}

async function remove(record: SourceEntry) {
  try {
    await api.deleteSource(record.source);
    message.success('已删除');
    await load();
  } catch {
    // 已提示
  }
}

async function discover(record: SourceEntry) {
  try {
    const res = await api.discoverSource(record.source);
    message.success(`发现 ${res.count} 个采集单元`);
    await load();
  } catch {
    // 已提示
  }
}

async function probe() {
  probing.value = true;
  try {
    const res = await api.sources(true);
    sources.value = res.sources ?? [];
    units.value = (res.units as SourceTypeRow[]) ?? [];
    message.success('探测完成');
  } catch {
    // 已提示
  } finally {
    probing.value = false;
  }
}

async function seedUnit(u: SourceTypeRow) {
  try {
    const res = await api.createJob({ source: u.source, units: [String(u.type_id)], max_pages: 3 });
    message.success(`已创建采集作业: ${res.job_id}`);
    router.push(`/jobs/${res.job_id}`);
  } catch {
    // 已提示
  }
}

async function load() {
  loading.value = true;
  try {
    const res = await api.sources();
    sources.value = res.sources ?? [];
    units.value = (res.units as SourceTypeRow[]) ?? [];
  } catch {
    // 已提示
  } finally {
    loading.value = false;
  }
}

onMounted(load);
</script>

<style scoped>
.cw-unit-row {
  display: flex;
  gap: 8px;
  margin-bottom: 8px;
}
</style>
