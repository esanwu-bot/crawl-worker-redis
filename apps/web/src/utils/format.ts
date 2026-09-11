import dayjs from 'dayjs';
import relativeTime from 'dayjs/plugin/relativeTime';
import 'dayjs/locale/zh-cn';

dayjs.extend(relativeTime);
dayjs.locale('zh-cn');

export function fmtTime(v?: string | null): string {
  if (!v) return '-';
  const d = dayjs(v);
  return d.isValid() ? d.format('YYYY-MM-DD HH:mm:ss') : String(v);
}

export function fromNow(v?: string | null): string {
  if (!v) return '-';
  const d = dayjs(v);
  return d.isValid() ? d.fromNow() : String(v);
}

export function fmtNum(v?: number | null): string {
  if (v === null || v === undefined) return '-';
  return new Intl.NumberFormat('en-US').format(v);
}

export function safeParse<T = unknown>(json: string | null | undefined, fallback: T): T {
  if (!json) return fallback;
  try {
    return JSON.parse(json) as T;
  } catch {
    return fallback;
  }
}

export function prettyJSON(v: unknown): string {
  if (v === null || v === undefined) return '';
  if (typeof v === 'string') {
    try {
      return JSON.stringify(JSON.parse(v), null, 2);
    } catch {
      return v;
    }
  }
  return JSON.stringify(v, null, 2);
}

export const JOB_STATUS_META: Record<string, { color: string; text: string }> = {
  seeding: { color: 'processing', text: '初始化' },
  active: { color: 'blue', text: '运行中' },
  paused: { color: 'orange', text: '已暂停' },
  done: { color: 'success', text: '已完成' },
  dead: { color: 'error', text: '失败' },
  cancelled: { color: 'default', text: '已取消' },
};

export function jobStatusMeta(status?: string) {
  return JOB_STATUS_META[status || ''] || { color: 'default', text: status || '未知' };
}

export function progressPercent(job: { units_total: number; units_done: number; units_dead: number }): number {
  if (!job.units_total) return 0;
  return Math.min(100, Math.round(((job.units_done + job.units_dead) / job.units_total) * 100));
}
