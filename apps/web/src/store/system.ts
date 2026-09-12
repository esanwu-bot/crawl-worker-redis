import { reactive } from 'vue';
import dayjs from 'dayjs';
import { api } from '@/api';
import { usingDemo } from '@/api/mode';
import type { HealthResp, MetricsResp } from '@/api/types';

// 全局系统状态：顶栏、侧边菜单徽标与首页共用。
export const system = reactive({
  health: null as HealthResp | null,
  metrics: null as MetricsResp | null,
  online: true,
  lastUpdated: '',
  loading: false,
});

export async function refreshSystem() {
  system.loading = true;
  usingDemo.value = false;
  try {
    const [h, m] = await Promise.all([api.health(), api.metrics()]);
    system.health = h;
    system.metrics = m;
  } finally {
    system.online = !usingDemo.value;
    system.lastUpdated = dayjs().format('HH:mm:ss');
    system.loading = false;
  }
}
