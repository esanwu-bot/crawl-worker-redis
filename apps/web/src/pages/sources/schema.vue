<template>
  <div class="cw-page">
    <div class="cw-page-title">
      <div>
        <h2>
          Schema 编辑器
          <span class="cw-mono" style="font-size: 16px; margin-left: 8px">{{ sourceId }}</span>
        </h2>
        <div class="sub">Source → Entity → Schema：外部字段 → Canonical 字段映射，可测试与发布版本</div>
      </div>
      <a-space>
        <a-button @click="$router.push('/sources')"><template #icon><ArrowLeftOutlined /></template>返回</a-button>
        <a-button @click="newVersion"><template #icon><PlusOutlined /></template>新建版本</a-button>
        <a-button type="primary" :loading="saving" @click="save"><template #icon><SaveOutlined /></template>保存</a-button>
      </a-space>
    </div>

    <a-spin :spinning="loading">
      <a-row :gutter="[16, 16]">
        <a-col :xs="24" :lg="15">
          <a-card :bordered="false" title="字段映射">
            <a-space style="margin-bottom: 12px" wrap>
              <span>Entity:</span>
              <a-select
                v-model:value="entity"
                style="width: 140px"
                :options="entityOptions"
                @change="onEntityChange"
              />
              <span>版本:</span>
              <a-select v-model:value="selectedVersionId" style="width: 200px" :options="versionOptions" @change="onVersionChange" />
              <a-tag v-if="current?.status" :color="current.status === 'published' ? 'success' : 'default'">
                {{ current.status === 'published' ? '已发布' : '草稿' }}
              </a-tag>
            </a-space>

            <a-table :columns="fieldColumns" :data-source="fields" :pagination="false" row-key="_rid" size="small">
              <template #bodyCell="{ column, record, index }">
                <template v-if="column.key === 'field'">
                  <a-input v-model:value="record.field" placeholder="Canonical 字段" size="small" />
                </template>
                <template v-else-if="column.key === 'source_path'">
                  <a-input v-model:value="record.source_path" placeholder="$.vod_name" class="cw-mono" size="small" />
                </template>
                <template v-else-if="column.key === 'type'">
                  <a-select
                    v-model:value="record.type"
                    size="small"
                    style="width: 100%"
                    :options="typeOptions"
                  />
                </template>
                <template v-else-if="column.key === 'required'">
                  <a-switch v-model:checked="record.required" size="small" />
                </template>
                <template v-else-if="column.key === 'remark'">
                  <a-input v-model:value="record.remark" size="small" />
                </template>
                <template v-else-if="column.key === 'actions'">
                  <a-button type="text" danger size="small" @click="removeField(index)">
                    <template #icon><DeleteOutlined /></template>
                  </a-button>
                </template>
              </template>
            </a-table>
            <a-button dashed block style="margin-top: 8px" @click="addField">
              <template #icon><PlusOutlined /></template>
              添加字段
            </a-button>

            <h4 style="margin-top: 20px">分页定位 (Pagination)</h4>
            <a-row :gutter="12">
              <a-col :span="6"><a-form-item label="items"><a-input v-model:value="pagination.items" class="cw-mono" size="small" /></a-form-item></a-col>
              <a-col :span="6"><a-form-item label="page"><a-input v-model:value="pagination.page" class="cw-mono" size="small" /></a-form-item></a-col>
              <a-col :span="6"><a-form-item label="page_count"><a-input v-model:value="pagination.page_count" class="cw-mono" size="small" /></a-form-item></a-col>
              <a-col :span="6"><a-form-item label="total"><a-input v-model:value="pagination.total" class="cw-mono" size="small" /></a-form-item></a-col>
            </a-row>

            <a-space style="margin-top: 8px">
              <a-button :disabled="!current?.id" @click="publish" type="primary">
                发布版本
              </a-button>
              <a-popconfirm v-if="current?.id" title="删除该 Schema 版本？" @confirm="removeVersion">
                <a-button danger>删除版本</a-button>
              </a-popconfirm>
            </a-space>
          </a-card>
        </a-col>

        <a-col :xs="24" :lg="9">
          <a-card :bordered="false" title="测试解析">
            <a-alert
              type="info"
              show-icon
              message="粘贴一条原始记录 JSON；留空则尝试从数据源实时抓取一条。"
              style="margin-bottom: 12px"
            />
            <a-textarea
              v-model:value="sampleText"
              :rows="10"
              class="cw-mono"
              placeholder='{"vod_id":151415,"vod_name":"..."}'
            />
            <a-button type="primary" block style="margin-top: 12px" :loading="testing" :disabled="!current?.id" @click="runTest">
              测试解析
            </a-button>

            <template v-if="testResult">
              <h4 style="margin-top: 16px">映射结果</h4>
              <pre class="cw-pre cw-mono">{{ prettyJSON(testResult.mapped) }}</pre>
              <a-alert
                v-if="testResult.missing?.length"
                type="warning"
                show-icon
                :message="`缺失必填字段: ${testResult.missing.join(', ')}`"
                style="margin-top: 8px"
              />
            </template>
          </a-card>

          <a-card :bordered="false" title="Canonical 约定" style="margin-top: 16px">
            <a-typography-paragraph style="font-size: 13px; color: #595959">
              external_id / title 为最小必填；payload 保留领域字段，raw 保留原始行。
            </a-typography-paragraph>
          </a-card>
        </a-col>
      </a-row>
    </a-spin>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { useRoute } from 'vue-router';
import { message } from 'ant-design-vue';
import type { TableColumnsType } from 'ant-design-vue';
import {
  ArrowLeftOutlined,
  DeleteOutlined,
  PlusOutlined,
  SaveOutlined,
} from '@ant-design/icons-vue';
import { api } from '@/api';
import type { Schema, SchemaField, SchemaTestResp } from '@/api/types';
import { prettyJSON } from '@/utils/format';

const route = useRoute();
const sourceId = String(route.params.id);

const loading = ref(false);
const saving = ref(false);
const testing = ref(false);

const schemas = ref<Schema[]>([]);
const entity = ref('vod');
const sourceEntity = ref('');
const selectedVersionId = ref<number | undefined>();
const currentId = ref(0);
const currentStatus = ref('draft');
const fields = ref<(SchemaField & { _rid: number })[]>([]);
const pagination = reactive({ items: '', page: '', page_count: '', total: '' });
const sampleText = ref('');
const testResult = ref<SchemaTestResp | null>(null);
let rid = 1;

const typeOptions = [
  { label: 'string', value: 'string' },
  { label: 'integer', value: 'integer' },
  { label: 'number', value: 'number' },
  { label: 'boolean', value: 'boolean' },
  { label: 'text', value: 'text' },
  { label: 'json', value: 'json' },
];

const fieldColumns: TableColumnsType = [
  { title: 'Canonical 字段', key: 'field', width: 150 },
  { title: 'Source Path', key: 'source_path', width: 170 },
  { title: '类型', key: 'type', width: 110 },
  { title: '必填', key: 'required', width: 60 },
  { title: '备注', key: 'remark' },
  { title: '', key: 'actions', width: 50 },
];

const entityOptions = computed(() => {
  const set = new Set<string>();
  if (sourceEntity.value) set.add(sourceEntity.value);
  schemas.value.forEach((s) => set.add(s.entity));
  if (!set.size) set.add('vod');
  return [...set].map((e) => ({ label: e, value: e }));
});

const versionOptions = computed(() =>
  schemas.value
    .filter((s) => s.entity === entity.value)
    .map((s) => ({
      label: `v${s.version} · ${s.status === 'published' ? '已发布' : '草稿'}`,
      value: s.id,
    })),
);

const current = computed<{ id: number; status: string } | null>(() =>
  currentId.value ? { id: currentId.value, status: currentStatus.value } : null,
);

function fill(sch: Schema) {
  currentId.value = sch.id;
  currentStatus.value = sch.status;
  selectedVersionId.value = sch.id;
  fields.value = (sch.fields ?? []).map((f) => ({ ...f, _rid: rid++ }));
  Object.assign(pagination, {
    items: sch.pagination?.items ?? '',
    page: sch.pagination?.page ?? '',
    page_count: sch.pagination?.page_count ?? '',
    total: sch.pagination?.total ?? '',
  });
  testResult.value = null;
}

function emptyFields(): SchemaField[] {
  return [
    { field: 'external_id', source_path: '', type: 'string', required: true, remark: '', sort: 0 },
    { field: 'title', source_path: '', type: 'string', required: true, remark: '', sort: 1 },
  ];
}

function newVersion() {
  currentId.value = 0;
  currentStatus.value = 'draft';
  selectedVersionId.value = undefined;
  fields.value = emptyFields().map((f) => ({ ...f, _rid: rid++ }));
  Object.assign(pagination, { items: '$.list', page: '$.page', page_count: '$.pagecount', total: '$.total' });
  testResult.value = null;
  message.info('已创建草稿，保存后生成新版本');
}

function addField() {
  fields.value.push({
    _rid: rid++,
    field: '',
    source_path: '',
    type: 'string',
    required: false,
    remark: '',
    sort: fields.value.length,
  });
}

function removeField(index: number) {
  fields.value.splice(index, 1);
}

function onVersionChange(id: number) {
  const sch = schemas.value.find((s) => s.id === id);
  if (sch) fill(sch);
}

async function onEntityChange() {
  const sch = schemas.value.find((s) => s.entity === entity.value);
  if (sch) fill(sch);
  else newVersion();
}

function currentPayload(): Partial<Schema> {
  return {
    source: sourceId,
    entity: entity.value,
    status: currentStatus.value,
    pagination: { ...pagination },
    fields: fields.value.map((f, i) => ({
      field: f.field,
      source_path: f.source_path,
      type: f.type,
      required: !!f.required,
      remark: f.remark,
      sort: i,
    })),
  };
}

async function save() {
  saving.value = true;
  try {
    let saved: Schema;
    if (currentId.value) saved = await api.updateSchema(currentId.value, currentPayload());
    else saved = await api.createSchema(sourceId, currentPayload());
    message.success(`已保存 v${saved.version}`);
    await loadSchemas(saved.id);
  } catch {
    // 已提示
  } finally {
    saving.value = false;
  }
}

async function publish() {
  if (!currentId.value) return;
  try {
    await api.publishSchema(currentId.value);
    message.success('已发布');
    await loadSchemas(currentId.value);
  } catch {
    // 已提示
  }
}

async function removeVersion() {
  if (!currentId.value) return;
  try {
    await api.deleteSchema(currentId.value);
    message.success('已删除');
    currentId.value = 0;
    await loadSchemas();
  } catch {
    // 已提示
  }
}

async function runTest() {
  if (!currentId.value) return;
  testing.value = true;
  try {
    let sample: Record<string, unknown> | undefined;
    if (sampleText.value.trim()) {
      try {
        sample = JSON.parse(sampleText.value);
      } catch {
        message.error('样例 JSON 解析失败');
        return;
      }
    }
    testResult.value = await api.testSchema(currentId.value, sample);
    if (!testResult.value.fetched && !sample) {
      message.info('未提供样例且未能实时抓取，已返回空结果');
    }
  } catch {
    // 已提示
  } finally {
    testing.value = false;
  }
}

async function loadSchemas(selectId?: number) {
  loading.value = true;
  try {
    schemas.value = await api.listSchemas(sourceId);
    if (schemas.value.length) {
      const target =
        schemas.value.find((s) => s.id === selectId) ??
        schemas.value.find((s) => s.entity === entity.value && s.status === 'published') ??
        schemas.value[0];
      entity.value = target.entity;
      fill(target);
    } else {
      newVersion();
    }
  } catch {
    // 已提示
  } finally {
    loading.value = false;
  }
}

onMounted(async () => {
  try {
    const def = await api.getSource(sourceId);
    if (def?.entity) {
      entity.value = def.entity;
      sourceEntity.value = def.entity;
    }
  } catch {
    // 已提示
  }
  await loadSchemas();
});
</script>
