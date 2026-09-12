// 侧边菜单结构（按设计稿分组）。icon 为 @ant-design/icons-vue 组件名。
export interface MenuLeaf {
  key: string;
  label: string;
  icon?: string;
  badge?: 'dead' | 'running';
}

export interface MenuGroup {
  key: string;
  label: string;
  icon: string;
  badge?: 'dead' | 'running';
  children: MenuLeaf[];
}

export type MenuNode = (MenuLeaf & { children?: undefined }) | MenuGroup;

export const MENU: MenuNode[] = [
  { key: '/dashboard', label: '首页', icon: 'HomeOutlined' },
  {
    key: 'g-agent',
    label: 'Agent 代理',
    icon: 'RobotOutlined',
    children: [
      { key: '/agents/intent', label: '对话 / 意图', icon: 'MessageOutlined' },
      { key: '/agents/runs', label: 'Agent 运行', icon: 'ThunderboltOutlined', badge: 'running' },
      { key: '/agents/timeline', label: '思考时间线', icon: 'NodeIndexOutlined' },
    ],
  },
  {
    key: 'g-crawl',
    label: '采集任务',
    icon: 'UnorderedListOutlined',
    children: [
      { key: '/jobs', label: '任务列表', icon: 'ProfileOutlined', badge: 'running' },
      { key: '/jobs/create', label: '任务创建', icon: 'PlusSquareOutlined' },
      { key: '/jobs/schedule', label: '调度计划', icon: 'FieldTimeOutlined' },
    ],
  },
  {
    key: 'g-source',
    label: '数据源管理',
    icon: 'DatabaseOutlined',
    children: [
      { key: '/sources', label: '数据源列表', icon: 'TableOutlined' },
      { key: '/sources/config', label: '数据源配置', icon: 'SettingOutlined' },
    ],
  },
  {
    key: 'g-schema',
    label: 'Schema 管理',
    icon: 'ApartmentOutlined',
    children: [
      { key: '/schemas', label: 'Schema 编辑器', icon: 'CodeOutlined' },
      { key: '/schemas/mapping', label: '字段映射', icon: 'BranchesOutlined' },
    ],
  },
  {
    key: 'g-data',
    label: '数据管理',
    icon: 'HddOutlined',
    children: [
      { key: '/data/datasets', label: '数据集', icon: 'ContainerOutlined' },
      { key: '/records', label: '数据浏览', icon: 'SearchOutlined' },
      { key: '/data/export', label: '数据导出', icon: 'ExportOutlined' },
    ],
  },
  {
    key: 'g-dead',
    label: 'Dead Letter',
    icon: 'WarningOutlined',
    badge: 'dead',
    children: [
      { key: '/dead-letters', label: '死信队列', icon: 'BugOutlined' },
      { key: '/dead-letters/repair', label: '自动修复', icon: 'ToolOutlined' },
    ],
  },
  {
    key: 'g-system',
    label: '系统管理',
    icon: 'SettingOutlined',
    children: [
      { key: '/workers', label: 'Worker 管理', icon: 'ClusterOutlined' },
      { key: '/settings', label: '系统设置', icon: 'ControlOutlined' },
    ],
  },
];
