import http from './client';
import type {
  CreateJobReq,
  CreateJobResp,
  DeadResp,
  DiscoverResp,
  HealthResp,
  JobDetailResp,
  Job,
  MetricsResp,
  RecordView,
  ResultsResp,
  Schema,
  SchemaTestResp,
  SourceDefinition,
  SourcesResp,
} from './types';

export const api = {
  health: () => http.get<HealthResp>('/health').then((r) => r.data),

  metrics: () => http.get<MetricsResp>('/metrics').then((r) => r.data),

  sources: (probe = false) =>
    http.get<SourcesResp>('/sources', { params: probe ? { probe: 1 } : {} }).then((r) => r.data),

  listJobs: (params: { source?: string; limit?: number } = {}) =>
    http.get<{ jobs: Job[] | null }>('/jobs', { params }).then((r) => r.data.jobs ?? []),

  createJob: (body: CreateJobReq) =>
    http.post<CreateJobResp>('/jobs', body).then((r) => r.data),

  getJob: (id: string) =>
    http.get<JobDetailResp>(`/jobs/${id}`).then((r) => r.data),

  jobAction: (id: string, action: 'pause' | 'resume' | 'cancel') =>
    http.post(`/jobs/${id}/${action}`).then((r) => r.data),

  deadLetters: (limit = 50) =>
    http.get<DeadResp>('/dead', { params: { limit } }).then((r) => r.data.dead_letters ?? []),

  requeueDead: (ids: string[] = [], limit = 0) =>
    http.post<{ requeued: number }>('/dead/requeue', { ids, limit }).then((r) => r.data),

  searchRecords: (params: {
    source?: string;
    entity?: string;
    unit_id?: string;
    keyword?: string;
    limit?: number;
  }) => http.get<ResultsResp>('/results', { params }).then((r) => r.data.records ?? []),

  // ---- Sources CRUD / 动作 ----
  getSource: (id: string) =>
    http.get<{ source: SourceDefinition }>(`/sources/${id}`).then((r) => r.data.source),

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
    http
      .get<{ schemas: Schema[] | null }>(`/sources/${sourceId}/schema`, { params: entity ? { entity } : {} })
      .then((r) => r.data.schemas ?? []),

  createSchema: (sourceId: string, body: Partial<Schema>) =>
    http.post<{ schema: Schema }>(`/sources/${sourceId}/schema`, body).then((r) => r.data.schema),

  getSchema: (id: number) =>
    http.get<{ schema: Schema }>(`/schemas/${id}`).then((r) => r.data.schema),

  updateSchema: (id: number, body: Partial<Schema>) =>
    http.put<{ schema: Schema }>(`/schemas/${id}`, body).then((r) => r.data.schema),

  deleteSchema: (id: number) => http.delete(`/schemas/${id}`).then((r) => r.data),

  testSchema: (id: number, sample?: Record<string, unknown>) =>
    http.post<SchemaTestResp>(`/schemas/${id}/test`, sample ? { sample } : {}).then((r) => r.data),

  publishSchema: (id: number) =>
    http.post<{ published: number }>(`/schemas/${id}/publish`).then((r) => r.data),
};

export type { RecordView };
