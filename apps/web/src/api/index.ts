import http from './client';
import { usingDemo } from './mode';
import {
  demoDead,
  demoHealth,
  demoJobs,
  demoMetrics,
  demoRecords,
  demoSchemas,
  demoSources,
  demoUnits,
} from './demo';
import type {
  CreateJobReq,
  CreateJobResp,
  DeadMessage,
  DeadResp,
  DiscoverResp,
  HealthResp,
  Job,
  JobDetailResp,
  MetricsResp,
  RecordView,
  ResultsResp,
  Schema,
  SchemaTestResp,
  SourceDefinition,
  SourcesResp,
} from './types';

// GET 请求失败时回落到演示数据（并标记 usingDemo）。
async function fb<T>(fn: () => Promise<T>, fallbackData: T): Promise<T> {
  try {
    return await fn();
  } catch {
    usingDemo.value = true;
    return fallbackData;
  }
}

function filterDemoJobs(params: { source?: string; limit?: number } = {}): Job[] {
  let list = demoJobs;
  if (params.source) list = list.filter((j) => j.source === params.source);
  if (params.limit) list = list.slice(0, params.limit);
  return list;
}

function filterDemoDead(limit: number): DeadMessage[] {
  return demoDead.slice(0, limit);
}

function filterDemoRecords(params: {
  source?: string;
  entity?: string;
  unit_id?: string;
  keyword?: string;
  limit?: number;
}): RecordView[] {
  let list = demoRecords;
  if (params.source) list = list.filter((r) => r.source === params.source);
  if (params.entity) list = list.filter((r) => r.entity === params.entity);
  if (params.keyword) list = list.filter((r) => r.title.includes(params.keyword as string));
  if (params.limit) list = list.slice(0, params.limit);
  return list;
}

export const api = {
  health: () => fb(() => http.get<HealthResp>('/health').then((r) => r.data), demoHealth),

  metrics: () => fb(() => http.get<MetricsResp>('/metrics').then((r) => r.data), demoMetrics),

  sources: (probe = false) =>
    fb(
      () => http.get<SourcesResp>('/sources', { params: probe ? { probe: 1 } : {} }).then((r) => r.data),
      { sources: demoSources, units: demoUnits },
    ),

  listJobs: (params: { source?: string; limit?: number } = {}) =>
    fb(
      () => http.get<{ jobs: Job[] | null }>('/jobs', { params }).then((r) => r.data.jobs ?? []),
      filterDemoJobs(params),
    ),

  createJob: (body: CreateJobReq) =>
    http.post<CreateJobResp>('/jobs', body).then((r) => r.data),

  getJob: (id: string) =>
    fb(
      () => http.get<JobDetailResp>(`/jobs/${id}`).then((r) => r.data),
      {
        job: demoJobs.find((j) => j.id === id) ?? demoJobs[0],
        runtime_state: 'running',
        parked_tasks: 0,
      } as JobDetailResp,
    ),

  jobAction: (id: string, action: 'pause' | 'resume' | 'cancel') =>
    http.post(`/jobs/${id}/${action}`).then((r) => r.data),

  deadLetters: (limit = 50) =>
    fb(
      () => http.get<DeadResp>('/dead', { params: { limit } }).then((r) => r.data.dead_letters ?? []),
      filterDemoDead(limit),
    ),

  requeueDead: (ids: string[] = [], limit = 0) =>
    http.post<{ requeued: number }>('/dead/requeue', { ids, limit }).then((r) => r.data),

  searchRecords: (params: {
    source?: string;
    entity?: string;
    unit_id?: string;
    keyword?: string;
    limit?: number;
  }) =>
    fb(
      () => http.get<ResultsResp>('/results', { params }).then((r) => r.data.records ?? []),
      filterDemoRecords(params),
    ),

  // ---- Sources CRUD / 动作 ----
  getSource: (id: string) =>
    fb(
      () => http.get<{ source: SourceDefinition }>(`/sources/${id}`).then((r) => r.data.source),
      (demoSources.find((s) => s.source === id) as unknown as SourceDefinition) ?? ({} as SourceDefinition),
    ),

  createSource: (body: SourceDefinition) =>
    http.post<{ source: SourceDefinition }>('/sources', body).then((r) => r.data.source),

  updateSource: (id: string, body: SourceDefinition) =>
    http.put<{ source: SourceDefinition }>(`/sources/${id}`, body).then((r) => r.data.source),

  deleteSource: (id: string) => http.delete(`/sources/${id}`).then((r) => r.data),

  testSource: (id: string) =>
    http.post<{ healthy: boolean; error?: string }>(`/sources/${id}/test`).then((r) => r.data),

  discoverSource: (id: string) =>
    http.post<DiscoverResp>(`/sources/${id}/discover`).then((r) => r.data),

  enableSource: (id: string) => http.post(`/sources/${id}/enable`).then((r) => r.data),

  disableSource: (id: string) => http.post(`/sources/${id}/disable`).then((r) => r.data),

  // ---- Schema ----
  listSchemas: (sourceId: string, entity?: string) =>
    fb(
      () =>
        http
          .get<{ schemas: Schema[] | null }>(`/sources/${sourceId}/schema`, {
            params: entity ? { entity } : {},
          })
          .then((r) => r.data.schemas ?? []),
      demoSchemas.filter((s) => s.source === sourceId && (!entity || s.entity === entity)),
    ),

  createSchema: (sourceId: string, body: Partial<Schema>) =>
    http.post<{ schema: Schema }>(`/sources/${sourceId}/schema`, body).then((r) => r.data.schema),

  getSchema: (id: number) =>
    fb(
      () => http.get<{ schema: Schema }>(`/schemas/${id}`).then((r) => r.data.schema),
      demoSchemas.find((s) => s.id === id) ?? demoSchemas[0],
    ),

  updateSchema: (id: number, body: Partial<Schema>) =>
    http.put<{ schema: Schema }>(`/schemas/${id}`, body).then((r) => r.data.schema),

  deleteSchema: (id: number) => http.delete(`/schemas/${id}`).then((r) => r.data),

  testSchema: (id: number, sample?: Record<string, unknown>) =>
    http.post<SchemaTestResp>(`/schemas/${id}/test`, sample ? { sample } : {}).then((r) => r.data),

  publishSchema: (id: number) =>
    http.post<{ published: number }>(`/schemas/${id}/publish`).then((r) => r.data),
};

export type { RecordView };
