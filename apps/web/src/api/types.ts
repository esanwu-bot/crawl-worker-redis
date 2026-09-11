// 与 goKit crawler-api (REST /api/v1) 对应的 TypeScript 契约。

export interface HealthResp {
  status: string;
  uptime_s: number;
  redis: string;
  database: string;
  time: string;
}

export interface SourceEntry {
  source: string;
  id?: string;
  name?: string;
  adapter: string;
  entity: string;
  site: string;
  api_base: string;
  page_size?: number;
  detail_url?: string;
  status?: string;
  managed?: boolean;
  registered?: boolean;
  units?: number;
  healthy?: boolean;
}

export interface SourceTypeRow {
  source: string;
  type_id: number;
  cn_name: string;
  en_name: string;
}

export interface SourceEntityRow {
  source: string;
  entity: string;
  name: string;
  status: string;
  updated_at: string;
}

export interface SourcesResp {
  sources: SourceEntry[];
  units: SourceTypeRow[] | null;
  entities?: SourceEntityRow[] | null;
}

export interface UnitDefinition {
  unit_id: string;
  unit_name: string;
  params?: Record<string, unknown>;
}

export interface SourceDefinition {
  id: string;
  name: string;
  adapter: string;
  entity: string;
  site: string;
  api_base: string;
  type?: number;
  page_size: number;
  detail_url: string;
  status: string;
  units?: UnitDefinition[];
  created_at?: string;
  updated_at?: string;
}

export type SchemaFieldType = 'string' | 'integer' | 'number' | 'boolean' | 'text' | 'json';

export interface SchemaField {
  field: string;
  source_path: string;
  type: SchemaFieldType;
  required: boolean;
  remark: string;
  sort: number;
}

export interface SchemaPagination {
  items: string;
  page: string;
  page_count: string;
  total: string;
}

export interface Schema {
  id: number;
  source: string;
  entity: string;
  version: number;
  status: string;
  fields: SchemaField[];
  pagination: SchemaPagination;
  created_at?: string;
  updated_at?: string;
}

export interface SchemaTestResp {
  mapped: Record<string, unknown>;
  missing: string[];
  fetched: boolean;
  raw: Record<string, unknown>;
}

export interface DiscoverResp {
  units: { entity: string; unit_id: string; unit_name: string }[];
  count: number;
}


export interface MetricsResp {
  stream_len: number;
  pending: number;
  dead_letters: number;
  counters: Record<string, string>;
  records_by_entity: EntityStat[] | null;
  proxy: {
    enabled: boolean;
    size: number;
    proxies?: unknown;
  };
}

export interface EntityStat {
  source: string;
  entity: string;
  count: number;
}

export type JobStatus = 'seeding' | 'active' | 'paused' | 'done' | 'dead' | 'cancelled';

export interface ScopeItem {
  entity: string;
  unit_id: string;
  unit_name: string;
}

export interface Job {
  id: string;
  source: string;
  status: JobStatus;
  units_total: number;
  units_done: number;
  units_dead: number;
  records: number;
  scope: ScopeItem[] | null;
  created_at: string;
  updated_at: string;
}

export interface JobDetailResp {
  job: Job;
  runtime_state: string;
  parked_tasks: number;
}

export interface CreateJobReq {
  source: string;
  units?: string[];
  max_pages?: number;
  force?: boolean;
}

export interface CreateJobResp {
  job_id: string;
  [k: string]: unknown;
}

export interface RecordView {
  job_id: string;
  source: string;
  entity: string;
  unit_id: string;
  unit_name: string;
  external_id: string;
  title: string;
  url: string;
  image: string;
  payload_json: string;
  raw_json: string;
}

export interface DeadMessage {
  id: string;
  payload: Record<string, unknown>;
}

export interface Paged<T> {
  [k: string]: unknown;
}

export interface ResultsResp {
  records: RecordView[];
}

export interface DeadResp {
  dead_letters: DeadMessage[];
}
