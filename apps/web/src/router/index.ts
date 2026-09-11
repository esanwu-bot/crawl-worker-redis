import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router';
import BasicLayout from '@/layouts/BasicLayout.vue';

const routes: RouteRecordRaw[] = [
  {
    path: '/',
    component: BasicLayout,
    redirect: '/dashboard',
    children: [
      {
        path: 'dashboard',
        name: 'dashboard',
        component: () => import('@/pages/dashboard/index.vue'),
        meta: { title: '仪表盘', icon: 'DashboardOutlined' },
      },
      {
        path: 'jobs',
        name: 'jobs',
        component: () => import('@/pages/jobs/index.vue'),
        meta: { title: '采集任务', icon: 'UnorderedListOutlined' },
      },
      {
        path: 'jobs/:id',
        name: 'job-detail',
        component: () => import('@/pages/jobs/detail.vue'),
        meta: { title: '任务详情', hidden: true },
      },
      {
        path: 'sources',
        name: 'sources',
        component: () => import('@/pages/sources/index.vue'),
        meta: { title: '数据源', icon: 'DatabaseOutlined' },
      },
      {
        path: 'sources/:id/schema',
        name: 'source-schema',
        component: () => import('@/pages/sources/schema.vue'),
        meta: { title: 'Schema 编辑器', hidden: true },
      },
      {
        path: 'records',
        name: 'records',
        component: () => import('@/pages/records/index.vue'),
        meta: { title: '采集结果', icon: 'ProfileOutlined' },
      },
      {
        path: 'dead-letters',
        name: 'dead-letters',
        component: () => import('@/pages/dead-letters/index.vue'),
        meta: { title: '死信队列', icon: 'WarningOutlined' },
      },
      {
        path: 'workers',
        name: 'workers',
        component: () => import('@/pages/workers/index.vue'),
        meta: { title: 'Worker / Redis', icon: 'ClusterOutlined' },
      },
    ],
  },
  {
    path: '/:pathMatch(.*)*',
    redirect: '/dashboard',
  },
];

const router = createRouter({
  history: createWebHistory(),
  routes,
});

router.afterEach((to) => {
  const title = (to.meta?.title as string) || 'Crawler Console';
  document.title = `${title} · Crawler Console`;
});

export default router;
