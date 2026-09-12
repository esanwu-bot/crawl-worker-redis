import dayjs from 'dayjs';
import type {
  DeadMessage,
  HealthResp,
  Job,
  JobStatus,
  MetricsResp,
  RecordView,
  Schema,
  SourceEntry,
  SourceTypeRow,
} from './types';

// 演示数据：后端未启动时用于渲染完整界面（顶栏会标注「演示数据」）。
// 结构完全对齐 goKit /api/v1 契约，接入真实后端后自动切换。

interface JobSeed {
  source: string;
  entity: string;
  status: JobStatus;
  units: number;
  done: number;
  dead: number;
  records: number;
  day: number;
  scope: string[];
  kind: '全量' | '增量';
}

const SEEDS: JobSeed[] = [
  { source: 'maccms', entity: 'vod', status: 'active', units: 8, done: 5, dead: 1, records: 18230, day: 0, scope: ['韩国剧', '喜剧片'], kind: '增量' },
  { source: 'shikues', entity: 'product', status: 'active', units: 12, done: 6, dead: 0, records: 12830, day: 0, scope: ['二极管', 'MOSFET'], kind: '全量' },
  { source: 'maccms', entity: 'category', status: 'dead', units: 10, done: 4, dead: 2, records: 7231, day: 1, scope: ['分类目录'], kind: '全量' },
  { source: 'tikchip', entity: 'product', status: 'done', units: 6, done: 6, dead: 0, records: 56892, day: 1, scope: ['全部'], kind: '全量' },
  { source: 'shikues', entity: 'product', status: 'paused', units: 9, done: 6, dead: 0, records: 14221, day: 2, scope: ['电子元器件'], kind: '增量' },
  { source: 'maccms', entity: 'vod', status: 'done', units: 5, done: 5, dead: 0, records: 31221, day: 2, scope: ['动作片', '喜剧片'], kind: '全量' },
  { source: 'shikues', entity: 'product', status: 'done', units: 7, done: 7, dead: 0, records: 22110, day: 3, scope: ['肖特基', '稳压'], kind: '增量' },
  { source: 'maccms', entity: 'vod', status: 'active', units: 15, done: 9, dead: 1, records: 27640, day: 3, scope: ['日韩动漫'], kind: '增量' },
  { source: 'tikchip', entity: 'product', status: 'done', units: 4, done: 4, dead: 0, records: 19980, day: 4, scope: ['MOSFET'], kind: '全量' },
  { source: 'maccms', entity: 'category', status: 'cancelled', units: 3, done: 1, dead: 0, records: 2210, day: 4, scope: ['分类目录'], kind: '全量' },
  { source: 'shikues', entity: 'product', status: 'done', units: 8, done: 8, dead: 0, records: 24310, day: 5, scope: ['电容', '电阻'], kind: '增量' },
  { source: 'maccms', entity: 'vod', status: 'dead', units: 6, done: 3, dead: 2, records: 9120, day: 5, scope: ['欧美剧'], kind: '增量' },
  { source: 'shikues', entity: 'product', status: 'done', units: 10, done: 10, dead: 0, records: 33540, day: 6, scope: ['全部'], kind: '全量' },
  { source: 'maccms', entity: 'vod', status: 'done', units: 4, done: 4, dead: 0, records: 15420, day: 6, scope: ['喜剧片'], kind: '全量' },
  { source: 'tikchip', entity: 'product', status: 'done', units: 5, done: 5, dead: 0, records: 20330, day: 6, scope: ['电子元器件'], kind: '增量' },
];

function buildJobs(): Job[] {
  const jobs: Job[] = [];
  SEEDS.forEach((s, i) => {
    const created = dayjs().subtract(s.day, 'day').hour(8).minute(20 + i).second(0);
    jobs.push({
      id: `CW-${created.format('YYYYMMDD')}-${String(i + 1).padStart(3, '0')}`,
      source: s.source,
      status: s.status,
      units_total: s.units,
      units_done: s.done,
      units_dead: s.dead,
      records: s.records,
      scope: s.scope.map((name, k) => ({ entity: s.entity, unit_id: String(k + 1), unit_name: name })),
      created_at: created.toISOString(),
      updated_at: created.add(1, 'hour').toISOString(),
    });
  });
  // 补充若干历史作业，让 7 日趋势更丰满
  for (let d = 0; d < 7; d++) {
    for (let k = 0; k < 3; k++) {
      const created = dayjs().subtract(d, 'day').hour(2 + k * 5).minute(10);
      jobs.push({
        id: `CW-${created.format('YYYYMMDD')}-H${k}`,
        source: k % 2 === 0 ? 'shikues' : 'maccms',
        status: d === 0 && k === 2 ? 'active' : 'done',
        units_total: 4,
        units_done: 4,
        units_dead: 0,
        records: 1200 + d * 130 + k * 90,
        scope: [{ entity: k % 2 === 0 ? 'product' : 'vod', unit_id: '1', unit_name: '全部' }],
        created_at: created.toISOString(),
        updated_at: created.add(30, 'minute').toISOString(),
      });
    }
  }
  return jobs.sort((a, b) => (a.created_at < b.created_at ? 1 : -1));
}

export const demoJobs = buildJobs();

export const demoMetrics: MetricsResp = {
  stream_len: 182,
  pending: 12,
  dead_letters: 3,
  counters: { success: '1284930', fail: '17234', seed_tasks: '312', workers_total: '8', workers_online: '8' },
  records_by_entity: [
    { source: 'maccms', entity: 'vod', count: 642310 },
    { source: 'shikues', entity: 'product', count: 512040 },
    { source: 'tikchip', entity: 'product', count: 130580 },
  ],
  proxy: { enabled: false, size: 0 },
};

export const demoHealth: HealthResp = {
  status: 'ok',
  uptime_s: 86400,
  redis: '',
  database: '',
  time: dayjs().toISOString(),
};

export const demoSources: SourceEntry[] = [
  { source: 'maccms', name: 'MacCMS 影视源', adapter: 'maccms', entity: 'vod', site: 'https://cj.lziapi.com', api_base: 'https://cj.lziapi.com/api.php/provide/vod/', status: 'enabled', registered: true, healthy: true, units: 3 },
  { source: 'shikues', name: 'Shikues 元器件源', adapter: 'shikues', entity: 'product', site: 'https://www.shikues.com', api_base: 'https://api.shikues.com/api/product', status: 'enabled', registered: true, healthy: true, units: 12 },
  { source: 'tikchip', name: 'TikChip 元器件源', adapter: 'maccms', entity: 'product', site: 'https://tikchip.com', api_base: 'https://tikchip.com/api', status: 'enabled', registered: true, healthy: true, units: 8 },
  { source: 'abc', name: 'ABC 站', adapter: 'json', entity: 'product', site: 'https://abc.example.com', api_base: 'https://abc.example.com/api', status: 'disabled', registered: false, healthy: false, units: 0 },
];

export const demoUnits: SourceTypeRow[] = [
  { source: 'maccms', type_id: 7, cn_name: '喜剧片', en_name: '' },
  { source: 'maccms', type_id: 15, cn_name: '韩国剧', en_name: '' },
  { source: 'maccms', type_id: 30, cn_name: '日韩动漫', en_name: '' },
  { source: 'shikues', type_id: 1, cn_name: '分立元器件', en_name: 'Discrete' },
  { source: 'shikues', type_id: 2, cn_name: '二极管', en_name: 'Diode' },
  { source: 'shikues', type_id: 3, cn_name: 'MOSFET', en_name: 'MOSFET' },
  { source: 'tikchip', type_id: 1, cn_name: '全部', en_name: 'All' },
];

export const demoRecords: RecordView[] = [
  { job_id: 'CW-1', source: 'shikues', entity: 'product', unit_id: '1', unit_name: '二极管', external_id: '151415', title: '欲望的陷阱', url: '', image: '', payload_json: '{}', raw_json: '{}' },
  { job_id: 'CW-1', source: 'shikues', entity: 'product', unit_id: '2', unit_name: 'MOSFET', external_id: '156829', title: '中头奖还是要', url: '', image: '', payload_json: '{}', raw_json: '{}' },
  { job_id: 'CW-2', source: 'maccms', entity: 'vod', unit_id: '15', unit_name: '韩国剧', external_id: '132528', title: '我们愉快的', url: '', image: '', payload_json: '{}', raw_json: '{}' },
  { job_id: 'CW-3', source: 'shikues', entity: 'product', unit_id: '1', unit_name: '二极管', external_id: '118736', title: '爱的迫降', url: '', image: '', payload_json: '{}', raw_json: '{}' },
  { job_id: 'CW-4', source: 'tikchip', entity: 'product', unit_id: '1', unit_name: '全部', external_id: '105821', title: '机智的医生', url: '', image: '', payload_json: '{}', raw_json: '{}' },
];

export const demoDead: DeadMessage[] = [
  { id: '1721234567890-0', payload: { source: 'maccms', entity: 'vod', unit_id: '7', cursor: { page: 5 }, _dead_reason: 'HTTP 502', _dead_at: dayjs().subtract(2, 'hour').format('YYYY-MM-DD HH:mm:ss') } },
  { id: '1721234567891-0', payload: { source: 'shikues', entity: 'product', unit_id: '3', cursor: { page: 2 }, _dead_reason: 'JSON parse error', _dead_at: dayjs().subtract(3, 'hour').format('YYYY-MM-DD HH:mm:ss') } },
  { id: '1721234567892-0', payload: { source: 'maccms', entity: 'vod', unit_id: '15', cursor: { page: 9 }, _dead_reason: 'timeout', _dead_at: dayjs().subtract(5, 'hour').format('YYYY-MM-DD HH:mm:ss') } },
];

export const demoSchemas: Schema[] = [
  {
    id: 1,
    source: 'maccms',
    entity: 'vod',
    version: 1,
    status: 'published',
    pagination: { items: '$.list', page: '$.page', page_count: '$.pagecount', total: '$.total' },
    fields: [
      { field: 'external_id', source_path: '$.vod_id', type: 'string', required: true, remark: '', sort: 0 },
      { field: 'title', source_path: '$.vod_name', type: 'string', required: true, remark: '', sort: 1 },
      { field: 'category_id', source_path: '$.type_id', type: 'integer', required: false, remark: '', sort: 2 },
      { field: 'image', source_path: '$.vod_pic', type: 'string', required: false, remark: '', sort: 3 },
    ],
  },
];
