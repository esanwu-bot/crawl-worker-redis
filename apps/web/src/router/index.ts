import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router';
import BasicLayout from '@/layouts/BasicLayout.vue';

const routes: RouteRecordRaw[] = [
  {
    path: '/',
    component: BasicLayout,
    redirect: '/dashboard',
    children: [
      { path: 'dashboard', name: 'dashboard', component: () => import('@/pages/dashboard/index.vue'), meta: { title: '首页' } },

      { path: 'agents/intent', name: 'agent-intent', component: () => import('@/pages/agents/index.vue'), meta: { title: '对话 / 意图', tab: 'intent', activeMenu: '/agents/intent' } },
      { path: 'agents/runs', name: 'agent-runs', component: () => import('@/pages/agents/index.vue'), meta: { title: 'Agent 运行', tab: 'runs', activeMenu: '/agents/runs' } },
      { path: 'agents/timeline', name: 'agent-timeline', component: () => import('@/pages/agents/index.vue'), meta: { title: '思考时间线', tab: 'timeline', activeMenu: '/agents/timeline' } },

      { path: 'jobs', name: 'jobs', component: () => import('@/pages/jobs/index.vue'), meta: { title: '任务列表', activeMenu: '/jobs' } },
      { path: 'jobs/create', name: 'job-create', component: () => import('@/pages/jobs/create.vue'), meta: { title: '任务创建', activeMenu: '/jobs/create' } },
      { path: 'jobs/schedule', name: 'job-schedule', component: () => import('@/pages/jobs/schedule.vue'), meta: { title: '调度计划', activeMenu: '/jobs/schedule' } },
      { path: 'jobs/:id', name: 'job-detail', component: () => import('@/pages/jobs/detail.vue'), meta: { title: '任务详情', activeMenu: '/jobs' } },

      { path: 'sources', name: 'sources', component: () => import('@/pages/sources/index.vue'), meta: { title: '数据源列表', activeMenu: '/sources' } },
      { path: 'sources/config', name: 'source-config', component: () => import('@/pages/sources/config.vue'), meta: { title: '数据源配置', activeMenu: '/sources/config' } },
      { path: 'sources/:id/schema', name: 'source-schema', component: () => import('@/pages/sources/schema.vue'), meta: { title: 'Schema 编辑器', activeMenu: '/schemas' } },

      { path: 'schemas', name: 'schemas', component: () => import('@/pages/schemas/index.vue'), meta: { title: 'Schema 编辑器', activeMenu: '/schemas' } },
      { path: 'schemas/mapping', name: 'schema-mapping', component: () => import('@/pages/schemas/mapping.vue'), meta: { title: '字段映射', activeMenu: '/schemas/mapping' } },

      { path: 'data/datasets', name: 'data-datasets', component: () => import('@/pages/data/index.vue'), meta: { title: '数据集', tab: 'datasets', activeMenu: '/data/datasets' } },
      { path: 'records', name: 'records', component: () => import('@/pages/records/index.vue'), meta: { title: '数据浏览', activeMenu: '/records' } },
      { path: 'data/export', name: 'data-export', component: () => import('@/pages/data/index.vue'), meta: { title: '数据导出', tab: 'export', activeMenu: '/data/export' } },

      { path: 'dead-letters', name: 'dead-letters', component: () => import('@/pages/dead-letters/index.vue'), meta: { title: '死信队列', activeMenu: '/dead-letters' } },
      { path: 'dead-letters/repair', name: 'dead-repair', component: () => import('@/pages/dead-letters/repair.vue'), meta: { title: '自动修复', activeMenu: '/dead-letters/repair' } },

      { path: 'workers', name: 'workers', component: () => import('@/pages/workers/index.vue'), meta: { title: 'Worker 管理', activeMenu: '/workers' } },
      { path: 'settings', name: 'settings', component: () => import('@/pages/settings/index.vue'), meta: { title: '系统设置', activeMenu: '/settings' } },
    ],
  },
  { path: '/:pathMatch(.*)*', redirect: '/dashboard' },
];

const router = createRouter({ history: createWebHistory(), routes });

router.afterEach((to) => {
  const title = (to.meta?.title as string) || 'Agent Workbench';
  document.title = `${title} · crawl-worker-redis`;
});

export default router;
